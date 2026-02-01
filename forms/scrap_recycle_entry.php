<?php
// scrap_recycle_entry.php

session_start();
require_once 'security_config.php';

// Security/session checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}
if (SecurityConfig::checkSessionTimeout()) {
    session_destroy();
    header("Location: ../login.html?error=timeout");
    exit();
}
SecurityConfig::updateSessionActivity();
if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    session_destroy();
    header("Location: ../login.html?error=disabled");
    exit();
}

// Role-based access control for Recycle module
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_RECYCLE, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>Access Denied</h2>
        <p>You do not have permission to access the Recycle module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// DB connection
$conn = SecurityConfig::getConnection();

// Fetch Side Cut Scrap entries for recycling - separate by category
$hasScrapType = $conn->query("SHOW COLUMNS FROM scrap_recycle LIKE 'scrap_type'");
$srSideCond = ($hasScrapType && $hasScrapType->num_rows > 0) ? "sr.scrap_type = 'side_cut'" : "1=1";
$srSideCase = ($hasScrapType && $hasScrapType->num_rows > 0) ? "CASE WHEN sr.scrap_type = 'side_cut' THEN sr.recycled_qty ELSE 0 END" : "sr.recycled_qty";

// Fetch Sheet Production side cuts (with reference_number)
$sheetProductionScraps = [];
$sheetRes = $conn->query("SELECT 
    scs.id,
    scs.entry_id,
    scs.entry_date as date,
    scs.shift,
    scs.category,
    scs.reference_number,
    scs.cutting_batch_no,
    scs.quantity_kg as quantity,
    'side_cut' as scrap_type,
    COALESCE(SUM({$srSideCase}), 0) as total_recycled,
    (scs.quantity_kg - COALESCE(SUM({$srSideCase}), 0)) as remaining_qty
FROM side_cut_scrap scs
LEFT JOIN scrap_recycle sr ON scs.id = sr.scrap_id AND {$srSideCond}
WHERE scs.category = 'Sheet Production'
  AND scs.reference_number IS NOT NULL 
  AND scs.reference_number != ''
GROUP BY scs.id, scs.entry_id, scs.entry_date, scs.shift, scs.category, scs.reference_number, scs.cutting_batch_no, scs.quantity_kg
HAVING remaining_qty > 0
ORDER BY scs.id DESC");
if ($sheetRes) {
    while ($row = $sheetRes->fetch_assoc()) {
        $sheetProductionScraps[] = $row;
    }
}

// Fetch Sewing Production side cuts (with cutting_batch_no)
$sewingProductionScraps = [];
$sewingRes = $conn->query("SELECT 
    scs.id,
    scs.entry_id,
    scs.entry_date as date,
    scs.shift,
    scs.category,
    scs.reference_number,
    scs.cutting_batch_no,
    scs.quantity_kg as quantity,
    'side_cut' as scrap_type,
    COALESCE(SUM({$srSideCase}), 0) as total_recycled,
    (scs.quantity_kg - COALESCE(SUM({$srSideCase}), 0)) as remaining_qty
FROM side_cut_scrap scs
LEFT JOIN scrap_recycle sr ON scs.id = sr.scrap_id AND {$srSideCond}
WHERE (scs.category = 'Sewing Production' OR scs.category = 'Swing Production')
  AND scs.cutting_batch_no IS NOT NULL 
  AND scs.cutting_batch_no != ''
GROUP BY scs.id, scs.entry_id, scs.entry_date, scs.shift, scs.category, scs.reference_number, scs.cutting_batch_no, scs.quantity_kg
HAVING remaining_qty > 0
ORDER BY scs.id DESC");
if ($sewingRes) {
    while ($row = $sewingRes->fetch_assoc()) {
        $sewingProductionScraps[] = $row;
    }
}

// Generate Recycle ID function
function generateRecycleId() {
    global $conn;
    
    try {
        // Ensure scrap_recycle table exists
        $conn->query("CREATE TABLE IF NOT EXISTS scrap_recycle (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recycle_id VARCHAR(20) UNIQUE NOT NULL,
            scrap_id INT NOT NULL,
            scrap_type VARCHAR(20) DEFAULT 'scrap',
            recycled_qty DECIMAL(10,2) NOT NULL,
            user_id INT NOT NULL,
            recycled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            remarks TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Add scrap_type column if it doesn't exist
        $conn->query("ALTER TABLE scrap_recycle ADD COLUMN IF NOT EXISTS scrap_type VARCHAR(20) DEFAULT 'scrap' AFTER scrap_id");
        
        // Generate unique recycle ID based on shift logic
        // Day shift: 8am to 7:59pm, Night shift: 8pm to 7:59am
        $current_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $current_hour = (int)$current_time->format('H');
        
        // Determine shift (8am-7:59pm = day, 8pm-7:59am = night)
        $shift = ($current_hour >= 8 && $current_hour < 20) ? 'day' : 'night';
        
        // Get shift start time for the current day
        if ($shift === 'day') {
            $shift_start = clone $current_time;
            $shift_start->setTime(8, 0, 0); // 8:00 AM
        } else {
            // For night shift, if it's before 8am, it's still the previous day's night shift
            if ($current_hour < 8) {
                $shift_start = clone $current_time;
                $shift_start->modify('-1 day')->setTime(20, 0, 0); // 8:00 PM previous day
            } else {
                $shift_start = clone $current_time;
                $shift_start->setTime(20, 0, 0); // 8:00 PM current day
            }
        }
        
        $date_str = $shift_start->format('Ymd');
        $shift_code = ($shift === 'day') ? 'D' : 'N';
        
        // Get the next recycle ID for this shift - simplified query
        $pattern = 'RC' . $date_str . $shift_code . '%';
        $stmt = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(recycle_id, -3) AS UNSIGNED)), 0) + 1 as next_id 
                                FROM scrap_recycle 
                                WHERE recycle_id LIKE ?");
        $stmt->bind_param("s", $pattern);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $row = $result->fetch_assoc()) {
            $next_id = $row['next_id'];
        } else {
            $next_id = 1; // Default to 1 if query fails
        }
        $stmt->close();
        
        // Format recycle ID: RC + YYYYMMDD + Shift + 3-digit number
        $recycle_id = 'RC' . $date_str . $shift_code . str_pad($next_id, 3, '0', STR_PAD_LEFT);
        
        return $recycle_id;
        
    } catch (Exception $e) {
        // Fallback ID if anything fails
        $date_str = date('Ymd');
        $current_hour = (int)date('H');
        $shift_code = ($current_hour >= 8 && $current_hour < 20) ? 'D' : 'N';
        return 'RC' . $date_str . $shift_code . '001';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Recycle Entry</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:0; color:#2c3e50; }
  .container { max-width:100%; margin:0; background:#fff; border-radius:0; padding:25px 80px; box-shadow:none;}
  h1 { text-align:center; font-size:28px; margin-bottom:30px; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  input[type="text"], input[type="number"], select {
    padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px);
  }
  .readonly { background:#ecf0f1; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .alert { padding:12px; border-radius:6px; margin-bottom:20px; }
  .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
  .alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
  .btn { padding:8px 16px; border:1px solid #ccc; background:#f0f0f0; cursor:pointer; border-radius:6px; transition:all 0.2s; font-weight:500; }
  .btn:hover { background:#e0e0e0; }
  .btn.selected { background:#007bff; color:#fff; border-color:#007bff; }
  .btn-group { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px; }
</style>
</head>
<body>
<div class="container">
  
  <h1>Recycle Entry</h1>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">
      <strong>Success!</strong> <?php echo htmlspecialchars($_GET['success']); ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error">
      <strong>Error!</strong> <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <form id="recycleForm" method="post" action="../handlers/submit_scrap_recycle_entry.php" onsubmit="return validateForm();">

    <!-- Hidden datetime + shift -->
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">

    <!-- Recycle ID -->
    <div class="form-group">
      <label>Recycle ID:</label>
      <input type="text" id="recycle_id_display" value="<?php echo generateRecycleId(); ?>" readonly class="readonly">
      <input type="hidden" id="recycle_id" name="recycle_id" value="<?php echo generateRecycleId(); ?>">
    </div>

    <!-- Production Category Selection -->
    <div class="form-group">
      <label>Production Category: </label>
      <div class="btn-group" id="categoryGroup">
        <button type="button" class="btn" data-value="Sheet Production" onclick="selectCategory(this)">Sheet Production</button>
        <button type="button" class="btn" data-value="Sewing Production" onclick="selectCategory(this)">Sewing Production</button>
      </div>
      <input type="hidden" id="category" name="category" value="">
    </div>

    <!-- Scrap ID - Sheet Production (Reference Number) -->
    <div class="form-group" id="sheetProductionSection" style="display:none;">
      <label>Scrap ID: </label>
      <select id="scrap_id_sheet" onchange="updateScrapId()">
        <option value="">-- Select Reference Number --</option>
        <?php foreach($sheetProductionScraps as $s): ?>
          <?php 
            $id = $s['id'] ?? '';
            $scrapType = $s['scrap_type'] ?? 'side_cut';
            $entryId = $s['entry_id'] ?? '';
            $category = $s['category'] ?? '';
            $referenceNumber = $s['reference_number'] ?? '';
            $original_qty = $s['quantity'] ?? 0;
            $total_recycled = $s['total_recycled'] ?? 0;
            $remaining_qty = $s['remaining_qty'] ?? $original_qty;
          ?>
          <option value="<?php echo htmlspecialchars($id); ?>" 
                  data-scrap-type="<?php echo htmlspecialchars($scrapType); ?>"
                  data-original="<?php echo $original_qty; ?>"
                  data-recycled="<?php echo $total_recycled; ?>"
                  data-remaining="<?php echo $remaining_qty; ?>"
                  data-reference="<?php echo htmlspecialchars($referenceNumber); ?>">
            <?php echo htmlspecialchars($referenceNumber); ?> - Entry: <?php echo htmlspecialchars($entryId); ?> | Available: <?php echo number_format($remaining_qty, 2); ?> kg
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Scrap ID - Sewing Production (CNC Cutting Batch) -->
    <div class="form-group" id="sewingProductionSection" style="display:none;">
      <label>Scrap ID: </label>
      <select id="scrap_id_sewing" onchange="updateScrapId()">
        <option value="">-- Select CNC Cutting Batch --</option>
        <?php foreach($sewingProductionScraps as $s): ?>
          <?php 
            $id = $s['id'] ?? '';
            $scrapType = $s['scrap_type'] ?? 'side_cut';
            $entryId = $s['entry_id'] ?? '';
            $category = $s['category'] ?? '';
            $cuttingBatchNo = $s['cutting_batch_no'] ?? '';
            $original_qty = $s['quantity'] ?? 0;
            $total_recycled = $s['total_recycled'] ?? 0;
            $remaining_qty = $s['remaining_qty'] ?? $original_qty;
          ?>
          <option value="<?php echo htmlspecialchars($id); ?>" 
                  data-scrap-type="<?php echo htmlspecialchars($scrapType); ?>"
                  data-original="<?php echo $original_qty; ?>"
                  data-recycled="<?php echo $total_recycled; ?>"
                  data-remaining="<?php echo $remaining_qty; ?>"
                  data-batch="<?php echo htmlspecialchars($cuttingBatchNo); ?>">
            <?php echo htmlspecialchars($cuttingBatchNo); ?> - Entry: <?php echo htmlspecialchars($entryId); ?> | Available: <?php echo number_format($remaining_qty, 2); ?> kg
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <input type="hidden" id="scrap_id" name="scrap_id" value="">
    <input type="hidden" id="scrap_type" name="scrap_type" value="side_cut">
    
    <!-- Available Quantity Display -->
    <div class="form-group">
      <label>Available Quantity:</label>
      <input type="number" id="available_qty" readonly style="background:#ecf0f1; padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px); font-weight:bold;" placeholder="Select scrap to see available quantity">
    </div>

    <!-- Recycled quantity -->
    <div class="form-group">
      <label>Recycled Quantity (kg):</label>
      <div style="display: flex; align-items: center; gap: 10px;">
        <input type="number" id="recycled_qty" name="recycled_qty" value="1" min="0.01" step="0.01" required oninput="validateRecycledQty()" style="flex: 1; padding: 10px; border: 1px solid #ccc; border-radius: 6px;">
        <button type="button" onclick="incrementQty()" style="padding: 8px 12px; background: #2ecc71; color: white; border: none; border-radius: 4px; cursor: pointer;">+</button>
      </div>
      <small id="qty_warning" style="color: #e74c3c; font-weight: 600; display: none;"></small>
    </div>

    <!-- Machine ID -->
    <div class="form-group">
      <label>Machine ID:</label>
      <div class="btn-group" id="machineIdGroup">
        <button type="button" class="btn" onclick="setMachineId('1')">1</button>
        <button type="button" class="btn" onclick="setMachineId('2')">2</button>
        <button type="button" class="btn" onclick="setMachineId('3')">3</button>
        <button type="button" class="btn" onclick="setMachineId('4')">4</button>
        <button type="button" class="btn" onclick="setMachineId('5')">5</button>
      </div>
      <input type="hidden" id="machine_id" name="machine_id" value="">
    </div>

    <!-- Remarks -->
    <div class="form-group">
      <label>Remarks:</label>
      <textarea name="remarks" id="remarks" rows="3" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px; resize:vertical;" placeholder="Enter any additional notes about this recycling process..." oninput="updateSummary()"></textarea>
    </div>

    <!-- Summary Section -->
    <div class="form-group">
      <div id="summaryBox" class="summary-info"></div>
      <input type="hidden" id="summary" name="summary">
    </div>

    <div class="actions">
      <button type="submit" class="submit-btn">Submit</button>
      <button type="reset" class="clear-btn">Clear</button>
    </div>
  </form>
</div>

<script>
function selectCategory(btn) {
  // Remove selected class from all buttons
  const buttons = document.querySelectorAll('#categoryGroup .btn');
  buttons.forEach(b => b.classList.remove('selected'));
  
  // Add selected class to clicked button
  btn.classList.add('selected');
  
  const category = btn.dataset.value;
  document.getElementById('category').value = category;
  
  // Show/hide appropriate section
  if (category === 'Sheet Production') {
    document.getElementById('sheetProductionSection').style.display = 'block';
    document.getElementById('sewingProductionSection').style.display = 'none';
    document.getElementById('scrap_id_sewing').value = '';
  } else if (category === 'Sewing Production') {
    document.getElementById('sewingProductionSection').style.display = 'block';
    document.getElementById('sheetProductionSection').style.display = 'none';
    document.getElementById('scrap_id_sheet').value = '';
  }
  
  // Clear scrap_id and available quantity
  document.getElementById('scrap_id').value = '';
  document.getElementById('available_qty').value = '';
  document.getElementById('recycled_qty').value = '1';
  updateSummary();
}

function updateScrapId() {
  const category = document.getElementById('category').value;
  const scrapSelect = category === 'Sheet Production' 
    ? document.getElementById("scrap_id_sheet")
    : document.getElementById("scrap_id_sewing");
  
  // Update the hidden scrap_id input
  document.getElementById('scrap_id').value = scrapSelect.value;
  
  // Update remaining quantity
  updateRemainingQty();
}

function validateForm(){
  const category = document.getElementById("category").value;
  if (!category) {
    alert("Please select a Production Category."); return false;
  }
  
  const scrapId = document.getElementById("scrap_id").value;
  
  if(!scrapId || scrapId === ''){
    alert("Please select a Scrap ID."); return false;
  }
  if(!document.getElementById("machine_id").value){
    alert("Please select a Machine ID."); return false;
  }
  
  const qty = parseFloat(document.getElementById("recycled_qty").value);
  const availableQty = parseFloat(document.getElementById("available_qty").value) || 0;
  
  if(isNaN(qty) || qty <= 0){
    alert("Recycled quantity must be greater than 0."); return false;
  }
  
  if(qty > availableQty){
    alert("Recycled quantity (" + qty + " kg) cannot exceed available quantity (" + availableQty + " kg).\nPlease adjust the quantity."); 
    return false;
  }
  
  return true;
}

function updateRemainingQty() {
  const category = document.getElementById("category").value;
  const scrapSelect = category === 'Sheet Production' 
    ? document.getElementById("scrap_id_sheet")
    : document.getElementById("scrap_id_sewing");
  
  const selectedOption = scrapSelect.options[scrapSelect.selectedIndex];
  const availableQtyField = document.getElementById("available_qty");
  const recycledQtyField = document.getElementById("recycled_qty");
  const scrapTypeField = document.getElementById("scrap_type");
  
  if (selectedOption && selectedOption.value) {
    const remainingQty = parseFloat(selectedOption.getAttribute("data-remaining")) || 0;
    const scrapType = selectedOption.getAttribute("data-scrap-type") || 'side_cut';
    
    availableQtyField.value = remainingQty.toFixed(2);
    recycledQtyField.max = remainingQty;
    recycledQtyField.value = Math.min(1, remainingQty).toFixed(2);
    scrapTypeField.value = scrapType;
  } else {
    availableQtyField.value = "";
    recycledQtyField.max = "";
    recycledQtyField.value = "1";
    scrapTypeField.value = "side_cut";
  }
  updateSummary();
}

function validateRecycledQty() {
  const qty = parseFloat(document.getElementById("recycled_qty").value);
  const availableQty = parseFloat(document.getElementById("available_qty").value) || 0;
  const warning = document.getElementById("qty_warning");
  const qtyInput = document.getElementById("recycled_qty");
  
  if (qty > availableQty) {
    warning.textContent = "Cannot exceed available quantity (" + availableQty + " kg)";
    warning.style.display = "block";
    qtyInput.style.borderColor = "#e74c3c";
  } else {
    warning.style.display = "none";
    qtyInput.style.borderColor = "#ccc";
  }
  updateSummary();
}

function incrementQty(){
  const qtyInput = document.getElementById("recycled_qty");
  const maxQty = parseFloat(document.getElementById("available_qty").value) || Infinity;
  const currentValue = parseFloat(qtyInput.value) || 0;
  const newValue = Math.min(currentValue + 1, maxQty);
  qtyInput.value = newValue.toFixed(2);
  validateRecycledQty();
  updateSummary();
}

function setMachineId(machineId){
  document.getElementById("machine_id").value = machineId;
  
  // Remove selected class from only machine ID buttons (not category buttons)
  const machineIdGroup = document.getElementById("machineIdGroup");
  if (machineIdGroup) {
    const machineButtons = machineIdGroup.querySelectorAll('.btn');
    machineButtons.forEach(btn => btn.classList.remove('selected'));
  }
  
  // Add selected class to clicked button
  event.target.classList.add('selected');
  
  updateSummary();
}

function updateSummary(){
  const dateTime = document.getElementById('dateTime').value;
  const recycleId = document.getElementById('recycle_id_display').value;
  const category = document.getElementById('category').value;
  const scrapSelect = category === 'Sheet Production' 
    ? document.getElementById("scrap_id_sheet")
    : document.getElementById("scrap_id_sewing");
  const scrapText = scrapSelect && scrapSelect.selectedIndex >= 0 ? scrapSelect.options[scrapSelect.selectedIndex].text : '';
  const scrapId = scrapSelect ? scrapSelect.value : '';
  const qtyInput = document.getElementById("recycled_qty");
  const machineIdInput = document.getElementById("machine_id");
  const remarksInput = document.getElementById("remarks");
  const remarksText = remarksInput.value.trim();

  if (dateTime) {
    let s = `${dateTime}`;
    if (recycleId) s += ` | Recycle ID: ${recycleId}`;
    if (category) s += ` | Category: ${category}`;
    if (scrapText && scrapId) s += ` | Scrap: ${scrapText}`;
    if (qtyInput.value) s += ` | Qty: ${qtyInput.value} kg`;
    if (machineIdInput.value) s += ` | Machine: ${machineIdInput.value}`;
    if (remarksText) s += ` | Remarks: ${remarksText}`;
    document.getElementById('summaryBox').innerText = s;
    document.getElementById('summary').value = s;
  } else {
    document.getElementById('summaryBox').innerText = '';
    document.getElementById('summary').value = '';
  }
}

function updateTimeAndShift() {
    const now = new Date();
    const utc = now.getTime() + (now.getTimezoneOffset()*60000);
    const dhaka = new Date(utc + (6*3600000));
    document.getElementById("dateTimeDisplay").innerHTML =
        "Date & Time: " + dhaka.toDateString() + " " + dhaka.toLocaleTimeString();

    const yyyy = dhaka.getFullYear();
    const mm = String(dhaka.getMonth()+1).padStart(2,'0');
    const dd = String(dhaka.getDate()).padStart(2,'0');
    const hh = String(dhaka.getHours()).padStart(2,'0');
    const min = String(dhaka.getMinutes()).padStart(2,'0');
    const ss = String(dhaka.getSeconds()).padStart(2,'0');

    document.getElementById("dateTime").value = `${yyyy}-${mm}-${dd} ${hh}:${min}:${ss}`;
    
    const h = dhaka.getHours();
    const shift = (h >= 8 && h <= 19) ? "Day" : "Night";
    document.getElementById("shiftBanner").innerText = "Shift: " + shift;
    document.getElementById("shift").value = shift;
    updateSummary();
}

// Initialize summary on page load
document.addEventListener('DOMContentLoaded', function(){
  updateTimeAndShift();
  setInterval(updateTimeAndShift, 1000);
  
  // Add event listeners for dynamic updates
  document.getElementById("scrap_id_sheet").addEventListener("change", updateSummary);
  document.getElementById("scrap_id_sewing").addEventListener("change", updateSummary);
  document.getElementById("recycled_qty").addEventListener("input", updateSummary);
  document.getElementById("remarks").addEventListener("input", updateSummary);
});
</script>
</body>
</html>


