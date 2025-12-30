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

// Fetch Scrap entries with remaining quantities after recycling (from scrap table)
// Only show lab testing scrap if send_to_recycle = 1 (or if it's not a lab testing entry)
$scraps = [];
$hasScrapType = $conn->query("SHOW COLUMNS FROM scrap_recycle LIKE 'scrap_type'");
$srTypeCondition = ($hasScrapType && $hasScrapType->num_rows > 0) ? "sr.scrap_type = 'scrap'" : "1=1";
$srTypeCase = ($hasScrapType && $hasScrapType->num_rows > 0) ? "CASE WHEN sr.scrap_type = 'scrap' THEN sr.recycled_qty ELSE 0 END" : "sr.recycled_qty";

$hasSendToRecycle = $conn->query("SHOW COLUMNS FROM scrap LIKE 'send_to_recycle'");
$sendToRecycleCond = ($hasSendToRecycle && $hasSendToRecycle->num_rows > 0)
    ? "AND (s.scrap_product != 'Lab Testing' OR s.scrap_product IS NULL OR COALESCE(s.send_to_recycle, 1) = 1)"
    : "";
$res = $conn->query("SELECT 
    s.id,
    s.scrap_id as entry_id,
    s.date_time as date,
    s.shift,
    s.scrap_category as category,
    s.qty as quantity,
    'scrap' as scrap_type,
    COALESCE(SUM({$srTypeCase}), 0) as total_recycled,
    (s.qty - COALESCE(SUM({$srTypeCase}), 0)) as remaining_qty
FROM scrap s
LEFT JOIN scrap_recycle sr ON s.id = sr.scrap_id AND {$srTypeCondition}
WHERE s.is_deleted = 0 
  {$sendToRecycleCond}
GROUP BY s.id, s.scrap_id, s.date_time, s.shift, s.scrap_category, s.qty
HAVING remaining_qty > 0
ORDER BY s.id DESC");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $scraps[] = $row;
    }
}

// Fetch Side Cut Scrap entries with remaining quantities after recycling
$srSideCond = ($hasScrapType && $hasScrapType->num_rows > 0) ? "sr.scrap_type = 'side_cut'" : "1=1";
$srSideCase = ($hasScrapType && $hasScrapType->num_rows > 0) ? "CASE WHEN sr.scrap_type = 'side_cut' THEN sr.recycled_qty ELSE 0 END" : "sr.recycled_qty";
$sideCutRes = $conn->query("SELECT 
    scs.id,
    scs.entry_id,
    scs.entry_date as date,
    scs.shift,
    scs.category,
    scs.quantity_kg as quantity,
    'side_cut' as scrap_type,
    COALESCE(SUM({$srSideCase}), 0) as total_recycled,
    (scs.quantity_kg - COALESCE(SUM({$srSideCase}), 0)) as remaining_qty
FROM side_cut_scrap scs
LEFT JOIN scrap_recycle sr ON scs.id = sr.scrap_id AND {$srSideCond}
GROUP BY scs.id, scs.entry_id, scs.entry_date, scs.shift, scs.category, scs.quantity_kg
HAVING remaining_qty > 0
ORDER BY scs.id DESC");
if ($sideCutRes) {
    while ($row = $sideCutRes->fetch_assoc()) {
        $scraps[] = $row;
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

    <!-- Scrap ID -->
    <div class="form-group">
      <label>Scrap ID: </label>
      <select id="scrap_id" name="scrap_id" required onchange="updateRemainingQty()">
        <option value="">-- Select Scrap --</option>
        <?php foreach($scraps as $s): ?>
          <?php 
            $id = $s['id'] ?? '';
            $scrapType = $s['scrap_type'] ?? 'scrap';
            $entryId = $s['entry_id'] ?? '';
            $category = $s['category'] ?? '';
            $original_qty = $s['quantity'] ?? 0;
            $total_recycled = $s['total_recycled'] ?? 0;
            $remaining_qty = $s['remaining_qty'] ?? $original_qty;
            $typeLabel = ($scrapType === 'side_cut') ? 'Side Cut' : 'Scrap';
          ?>
          <option value="<?php echo htmlspecialchars($id); ?>" 
                  data-scrap-type="<?php echo htmlspecialchars($scrapType); ?>"
                  data-original="<?php echo $original_qty; ?>"
                  data-recycled="<?php echo $total_recycled; ?>"
                  data-remaining="<?php echo $remaining_qty; ?>">
            <?php echo $typeLabel; ?>#<?php echo htmlspecialchars($id); ?> (<?php echo htmlspecialchars($entryId); ?>) - <?php echo htmlspecialchars($category); ?> | Available: <?php echo number_format($remaining_qty, 2); ?> kg
          </option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" id="scrap_type" name="scrap_type" value="scrap">
    </div>
    
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
      <div class="btn-group">
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
function validateForm(){
  if(!document.getElementById("scrap_id").value){
    alert("Please select a scrap entry."); return false;
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
  const scrapSelect = document.getElementById("scrap_id");
  const selectedOption = scrapSelect.options[scrapSelect.selectedIndex];
  const availableQtyField = document.getElementById("available_qty");
  const recycledQtyField = document.getElementById("recycled_qty");
  const scrapTypeField = document.getElementById("scrap_type");
  
  if (selectedOption && selectedOption.value) {
    const remainingQty = parseFloat(selectedOption.getAttribute("data-remaining")) || 0;
    const scrapType = selectedOption.getAttribute("data-scrap-type") || 'scrap';
    
    availableQtyField.value = remainingQty.toFixed(2);
    recycledQtyField.max = remainingQty;
    recycledQtyField.value = Math.min(1, remainingQty).toFixed(2);
    scrapTypeField.value = scrapType;
  } else {
    availableQtyField.value = "";
    recycledQtyField.max = "";
    recycledQtyField.value = "1";
    scrapTypeField.value = "scrap";
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
  
  // Remove selected class from all buttons
  const buttons = document.querySelectorAll('.btn-group .btn');
  buttons.forEach(btn => btn.classList.remove('selected'));
  
  // Add selected class to clicked button
  event.target.classList.add('selected');
  
  updateSummary();
}

function updateSummary(){
  const dateTime = document.getElementById('dateTime').value;
  const recycleId = document.getElementById('recycle_id_display').value;
  const scrapSelect = document.getElementById("scrap_id");
  const scrapText = scrapSelect.options[scrapSelect.selectedIndex].text;
  const qtyInput = document.getElementById("recycled_qty");
  const machineIdInput = document.getElementById("machine_id");
  const remarksInput = document.getElementById("remarks");
  const remarksText = remarksInput.value.trim();

  if (dateTime) {
    let s = `${dateTime}`;
    if (recycleId) s += ` | Recycle ID: ${recycleId}`;
    if (scrapText && scrapSelect.value) s += ` | Scrap: ${scrapText}`;
    if (qtyInput.value) s += ` | Qty: ${qtyInput.value}`;
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
  document.getElementById("scrap_id").addEventListener("change", updateSummary);
  document.getElementById("recycled_qty").addEventListener("input", updateSummary);
  document.getElementById("remarks").addEventListener("input", updateSummary);
});
</script>
</body>
</html>


