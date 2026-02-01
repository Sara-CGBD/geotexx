<?php
// scrap_entry.php

session_start();
require_once 'security_config.php';

// Session & security checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}
if (SecurityConfig::checkSessionTimeout()) {
    session_destroy();
    header("Location: login.html?error=timeout");
    exit();
}
SecurityConfig::updateSessionActivity();
if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    session_destroy();
    header("Location: login.html?error=disabled");
    exit();
}

// Role-based access control for Scrap/Waste module
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_SCRAP, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access the Scrap/Waste module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// DB connection (shared)
$conn = SecurityConfig::getConnection();

// Check if editing an existing entry (MUST BE BEFORE QUERIES)
$editMode = false;
$editEntry = null;
if (isset($_GET['id'])) {
    $editId = (int)$_GET['id'];
    $editStmt = $conn->prepare("SELECT * FROM scrap WHERE id = ?");
    if ($editStmt) {
        $editStmt->bind_param('i', $editId);
        $editStmt->execute();
        $result = $editStmt->get_result();
        if ($result->num_rows > 0) {
            $editEntry = $result->fetch_assoc();
            $editMode = true;
        }
        $editStmt->close();
    }
}

// Get current shift info for filtering
$currentDate = date('Y-m-d');
$currentHour = (int)date('H');
$currentShift = ($currentHour >= 8 && $currentHour <= 19) ? 'Day' : 'Night';

// Fetch reference numbers from gsm_roll_entry (generated in GSM and Roll Entry form) excluding already recorded ones
// BUT include the current entry's reference if in edit mode
$sheetReferences = [];
$excludeId = ($editMode && $editEntry) ? $editEntry['id'] : 0;
$gsmTableExists = $conn->query("SHOW TABLES LIKE 'gsm_roll_entry'")->num_rows > 0;
if ($gsmTableExists) {
    $sheetQuery = "SELECT DISTINCT g.reference AS reference_number 
                   FROM gsm_roll_entry g
                   LEFT JOIN scrap s ON s.reference_number = g.reference 
                       AND DATE(s.date_time) = ? 
                       AND s.scrap_category = 'Sheet Production Scrap'
                       AND s.id != ?
                   WHERE g.reference IS NOT NULL 
                       AND g.reference != ''
                       AND s.id IS NULL
                   ORDER BY COALESCE(g.date_time, g.created_at) DESC 
                   LIMIT 50";
    $sheetStmt = $conn->prepare($sheetQuery);
    if ($sheetStmt) {
        $sheetStmt->bind_param('si', $currentDate, $excludeId);
        $sheetStmt->execute();
        $sheetResult = $sheetStmt->get_result();
        while ($row = $sheetResult->fetch_assoc()) {
            $sheetReferences[] = $row['reference_number'];
        }
        $sheetStmt->close();
    }
}

// In edit mode, ensure the current reference is in the list
if ($editMode && $editEntry && !empty($editEntry['reference_number']) && !in_array($editEntry['reference_number'], $sheetReferences)) {
    array_unshift($sheetReferences, $editEntry['reference_number']);
}

// Fetch CNC cutting batches with date and bag size from sewing_machine_entry (Sewing Machine Entry form) excluding already recorded ones
// Same logic as Side Cut Entry. Fallback to swing_machine_entry for backward compatibility.
$cncBatches = [];
$sewingTableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
$sewingTableExists = $sewingTableCheck && $sewingTableCheck->num_rows > 0;
$swingTableCheck = $conn->query("SHOW TABLES LIKE 'swing_machine_entry'");
$swingTableExists = $swingTableCheck && $swingTableCheck->num_rows > 0;
$sewingTable = $sewingTableExists ? 'sewing_machine_entry' : ($swingTableExists ? 'swing_machine_entry' : null);

if ($sewingTable) {
    $hasDate = $conn->query("SHOW COLUMNS FROM {$sewingTable} LIKE 'date_time'")->num_rows > 0;
    $hasBagSize = $conn->query("SHOW COLUMNS FROM {$sewingTable} LIKE 'bag_size'")->num_rows > 0;
    $bagExpr = $hasBagSize ? "TRIM(COALESCE(sme.bag_size,''))" : "''";
    $cncQuery = "SELECT sme.cnc_cutting_batch, MAX(sme.date_time) AS date_time, {$bagExpr} AS bag_size
                 FROM {$sewingTable} sme
                 LEFT JOIN scrap s ON s.cutting_batch = sme.cnc_cutting_batch
                     AND DATE(s.date_time) = ?
                     AND s.scrap_category = 'Sewing Scrap'
                     AND s.id != ?
                 WHERE sme.cnc_cutting_batch IS NOT NULL
                     AND sme.cnc_cutting_batch != ''
                     AND s.id IS NULL
                 GROUP BY sme.cnc_cutting_batch, DATE(sme.date_time), {$bagExpr}
                 ORDER BY MAX(sme.date_time) DESC
                 LIMIT 50";
    $cncStmt = $conn->prepare($cncQuery);
    if ($cncStmt) {
        $cncStmt->bind_param('si', $currentDate, $excludeId);
        $cncStmt->execute();
        $cncResult = $cncStmt->get_result();
        while ($row = $cncResult->fetch_assoc()) {
            $cncBatches[] = [
                'batch' => $row['cnc_cutting_batch'],
                'date_time' => $row['date_time'] ?? null,
                'bag_size' => isset($row['bag_size']) ? trim($row['bag_size']) : '',
            ];
        }
        $cncStmt->close();
    }
}

// In edit mode, ensure the current batch is in the list
$editBatch = $editMode && $editEntry && !empty($editEntry['cutting_batch']) ? $editEntry['cutting_batch'] : '';
if ($editBatch) {
    $found = false;
    foreach ($cncBatches as $item) {
        if (isset($item['batch']) && $item['batch'] === $editBatch) { $found = true; break; }
    }
    if (!$found) {
        array_unshift($cncBatches, ['batch' => $editBatch, 'date_time' => null, 'bag_size' => '']);
    }
}

// Generate Scrap ID (server-side, daily reset like CNC: SC-YYYYMMDD-XXX)
$scrap_id = 'SC-' . date('Ymd') . '-001';
$today = date('Y-m-d');

if ($editMode && $editEntry) {
    $scrap_id = $editEntry['scrap_id'];
} else {
if ($conn) {
  // Ensure table and column exist before querying sequence
  $tbl = $conn->query("SHOW TABLES LIKE 'scrap'");
  if ($tbl && $tbl->num_rows > 0) {
    $col = $conn->query("SHOW COLUMNS FROM scrap LIKE 'scrap_id'");
    if ($col && $col->num_rows > 0) {
      // Use prepared statement for date query
      $seqStmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(scrap_id, -3) AS UNSIGNED)) AS last_num FROM scrap WHERE DATE(date_time) = ?");
      if ($seqStmt) {
          $seqStmt->bind_param("s", $today);
          $seqStmt->execute();
          $seq = $seqStmt->get_result();
          if ($seq && $row = $seq->fetch_assoc()) {
              $next = (!empty($row['last_num']) ? ((int)$row['last_num']) + 1 : 1);
              $scrap_id = 'SC-' . date('Ymd') . '-' . str_pad($next, 3, '0', STR_PAD_LEFT);
          }
          $seqStmt->close();
      }
      }
    }
  }
}

// (Deprecated) Previous FG products fetch no longer used for scrap entry product selection
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Scrap Entry</title>
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
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .readonly { background:#ecf0f1; }
  .btn-group { display:flex; flex-wrap:wrap; gap:10px; }
  .btn { padding:10px 16px; font-size:14px; border:none; border-radius:6px; cursor:pointer; background-color:#f8f9fa; }
  .btn:hover { background-color:#ccc; }
  .btn.selected { background-color:#3498db; color:white; }
</style>
</head>
<body>
<div class="container">
  
  <h1>Scrap Entry</h1>

  <?php if ($editMode): ?>
    <div style="background:#fff3cd; color:#856404; padding:15px; border-radius:6px; margin-bottom:20px; border:2px solid #ffc107;">
      <strong>📝 EDIT MODE</strong> - You are editing an existing scrap entry.<br>
      <small>Entry ID: <?php echo htmlspecialchars($editEntry['scrap_id']); ?></small>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success" style="background:#d4edda;color:#155724;padding:12px;border-radius:6px;border:1px solid #c3e6cb;margin-bottom:15px;">
      ✅ <?php echo htmlspecialchars($_GET['success']); ?>
      <?php if (isset($_GET['scrap_id'])): ?>
        <br><strong>Scrap ID: <?php echo htmlspecialchars($_GET['scrap_id']); ?></strong>
      <?php endif; ?>
      <?php if (isset($_GET['last_id'])): ?>
        <br><br>
        <a href="scrap_entry.php?id=<?php echo (int)$_GET['last_id']; ?>" 
           style="display:inline-block; margin-top:10px; background:#28a745; color:white; padding:8px 16px; border-radius:5px; text-decoration:none; font-weight:600;">
          📝 Edit This Entry
        </a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error" style="background:#f8d7da;color:#721c24;padding:12px;border-radius:6px;border:1px solid #f5c6cb;margin-bottom:15px;">
      ❌ <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <!-- Edit Mode Banner -->
  <?php if ($editMode): ?>
    <div style="background:#ff9800; color:white; padding:15px; border-radius:8px; margin-bottom:20px; text-align:center; font-weight:600; font-size:16px;">
      ✏️ EDIT MODE - Updating Scrap Entry: <?php echo htmlspecialchars($editEntry['scrap_id']); ?>
    </div>
  <?php endif; ?>

  <!-- Back to Dashboard & View List Links -->
  <div style="margin-bottom: 15px; display: flex; gap: 10px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
    <a href="scrap_entries_list.php" style="background:#6c757d; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      📋 View All Entries (Current Shift)
    </a>
  </div>

  <!-- Date/Time and Shift Display -->
  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <form id="scrapForm" method="post" action="../handlers/submit_scrap_entry.php" onsubmit="return validateForm();">

    <?php if ($editMode): ?>
      <input type="hidden" name="edit_id" value="<?php echo $editEntry['id']; ?>">
    <?php endif; ?>

    <!-- Scrap ID (server-generated, daily reset) -->
    <div class="form-group">
      <label>Scrap ID: </label>
      <input type="text" id="scrap_id_preview" value="<?php echo htmlspecialchars($scrap_id); ?>" readonly class="readonly">
      <input type="hidden" id="scrap_id" name="scrap_id" value="<?php echo htmlspecialchars($scrap_id); ?>">
    </div>
   

    <!-- Scrap Category -->
    <div class="form-group">
      <label>Scrap Category: </label>
      <div class="btn-group" id="scrapCategoryGroup">
        <button type="button" class="btn" data-value="Sheet Production Scrap" onclick="selectSource(this)">Sheet Production Scrap</button>
        <button type="button" class="btn" data-value="Sewing Scrap" onclick="selectSource(this)">Sewing Scrap</button>
      </div>
      <input type="hidden" id="scrap_category" name="scrap_category" value="">
    </div>

    <!-- SHEET PRODUCTION SCRAP FIELDS (Hidden by default) -->
    <div id="sheetScrapFields" style="display:none;">
      <!-- Reference Number (from Fiber Received Entry) -->
      <div class="form-group">
        <label>Reference Number: </label>
        <select id="sheet_reference" name="sheet_reference">
          <option value="">-- Select Reference Number --</option>
          <?php foreach($sheetReferences as $ref): ?>
            <option value="<?php echo htmlspecialchars($ref); ?>"><?php echo htmlspecialchars($ref); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Scrap Product -->
      <div class="form-group">
        <label>Scrap Product: </label>
        <div class="btn-group" id="sheetProductGroup">
          <button type="button" class="btn" data-value="Raw Material" onclick="selectBtn(this, 'sheetProductGroup')">Raw Material</button>
        </div>
        <input type="hidden" id="sheet_product" name="sheet_product" value="">
      </div>

      <!-- Scrap Type -->
      <div class="form-group">
        <label>Scrap Type: </label>
        <div class="btn-group" id="sheetTypeGroup">
          <button type="button" class="btn" data-value="Process" onclick="selectBtn(this, 'sheetTypeGroup')">Process</button>
          <button type="button" class="btn" data-value="Unseen" onclick="selectBtn(this, 'sheetTypeGroup')">Unseen</button>
        </div>
        <input type="hidden" id="sheet_type" name="sheet_type" value="">
      </div>

      <!-- Scrap Quantity (kg) -->
    <div class="form-group">
        <label>Scrap Quantity (kg): </label>
        <input type="number" id="sheet_qty" name="sheet_qty" min="0.01" step="0.01">
      </div>
    </div>

    <!-- SWING SCRAP FIELDS (Hidden by default) -->
    <div id="swingScrapFields" style="display:none;">
      <!-- Cutting Batch Number (from CNC Entry) -->
      <div class="form-group">
        <label>CNC Cutting Batch: </label>
        <select id="swing_cutting_batch" name="swing_cutting_batch">
          <option value="">-- Select CNC Cutting Batch --</option>
          <?php foreach ($cncBatches as $item): ?>
            <?php
              $batchVal = is_array($item) ? $item['batch'] : $item;
              $dateStr = !empty($item['date_time']) ? date('Y-m-d', strtotime($item['date_time'])) : '';
              $bagStr = !empty($item['bag_size']) ? ' [' . $item['bag_size'] . ']' : '';
              $label = $batchVal . ($dateStr ? ' - ' . $dateStr : '') . $bagStr;
            ?>
            <option value="<?php echo htmlspecialchars($batchVal); ?>"><?php echo htmlspecialchars($label); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Scrap Product -->
      <div class="form-group">
        <label>Scrap Product: </label>
        <div class="btn-group" id="swingProductGroup">
          <button type="button" class="btn" data-value="Raw Material" onclick="selectBtn(this, 'swingProductGroup')">Raw Material</button>
        </div>
        <input type="hidden" id="swing_product" name="swing_product" value="">
      </div>

      <!-- Scrap Type (Yarn/Sheet for Swing) -->
      <div class="form-group">
        <label>Scrap Type: </label>
        <div class="btn-group" id="swingTypeGroup">
          <button type="button" class="btn" data-value="Yarn" onclick="selectBtn(this, 'swingTypeGroup')">Yarn</button>
          <button type="button" class="btn" data-value="Sheet" onclick="selectBtn(this, 'swingTypeGroup')">Sheet</button>
        </div>
        <input type="hidden" id="swing_type" name="swing_type" value="">
      </div>

      <!-- Scrap Quantity (kg) -->
    <div class="form-group">
        <label>Scrap Quantity (kg): </label>
        <input type="number" id="swing_qty" name="swing_qty" min="0.01" step="0.01">
      </div>
    </div>

    <!-- Hidden Bangladesh DateTime and Shift for record -->
     <input type="hidden" id="dateTime" name="dateTime" value="">
    <input type="hidden" id="shift" name="shift" value="">

<!-- Summary Section (kept as the last section before submit) -->
<div class="form-group">
  <div id="summaryBox" class="summary-info"></div>
  <input type="hidden" id="summary" name="summary">
</div>

    <div class="actions">
      <button type="submit" class="submit-btn">Submit</button>
      <button type="button" class="clear-btn" onclick="clearForm()">Clear</button>
    </div>
  </form>
</div>

<script>
function updateTimeBD() {
  const now = new Date();
  const utc = now.getTime() + now.getTimezoneOffset()*60000;
  const dhaka = new Date(utc + 6*3600000);
  
  // Display date and time
  document.getElementById("dateTimeDisplay").innerHTML =
    "Date & Time: " + dhaka.toDateString() + " " + dhaka.toLocaleTimeString();
  
  // Set hidden datetime field
  const yyyy = dhaka.getFullYear();
  const mm = String(dhaka.getMonth()+1).padStart(2,'0');
  const dd = String(dhaka.getDate()).padStart(2,'0');
  const hh = String(dhaka.getHours()).padStart(2,'0');
  const min = String(dhaka.getMinutes()).padStart(2,'0');
  const ss = String(dhaka.getSeconds()).padStart(2,'0');
  document.getElementById('dateTime').value = `${yyyy}-${mm}-${dd} ${hh}:${min}:${ss}`;
  
  // Calculate and display shift (8 AM to 7:59 PM = Day, 8 PM to 7:59 AM = Night)
  const h = dhaka.getHours();
  const shift = (h >= 8 && h < 20) ? "Day" : "Night";
  document.getElementById("shiftBanner").innerText = "Shift: " + shift;
  document.getElementById("shift").value = shift;
  
  updateSummary();
}
setInterval(updateTimeBD,1000); updateTimeBD();

function updateSummary(){
  const dateTime = document.getElementById('dateTime').value;
  const shift = document.getElementById('shift').value;
  const scrapId = document.getElementById('scrap_id_preview').value;
  const source = document.getElementById('scrap_category').value;

  if (dateTime && shift && scrapId) {
    let s = `${dateTime} | Shift: ${shift} | Scrap ID: ${scrapId}`;
    if (source) s += ` | Source: ${source}`;
    
    // Sheet Production Scrap fields
    if (source === 'Sheet Production Scrap') {
      const ref = document.getElementById('sheet_reference').value;
      const product = document.getElementById('sheet_product').value;
      const type = document.getElementById('sheet_type').value;
      const qty = document.getElementById('sheet_qty').value;
      
      if (ref) s += ` | Ref: ${ref}`;
      if (product) s += ` | Product: ${product}`;
      if (type) s += ` | Type: ${type}`;
      if (qty) s += ` | Qty: ${qty} kg`;
    }
    
    // Sewing Scrap fields
    if (source === 'Sewing Scrap') {
      const batch = document.getElementById('swing_cutting_batch').value;
      const product = document.getElementById('swing_product').value;
      const type = document.getElementById('swing_type').value;
      const qty = document.getElementById('swing_qty').value;
      
      if (batch) s += ` | Batch: ${batch}`;
      if (product) s += ` | Product: ${product}`;
      if (type) s += ` | Type: ${type}`;
      if (qty) s += ` | Qty: ${qty} kg`;
    }
    
    document.getElementById('summaryBox').innerText = s;
    document.getElementById('summary').value = s;
  } else {
    document.getElementById('summaryBox').innerText = '';
    document.getElementById('summary').value = '';
  }
}

function validateForm(){
  const source = document.getElementById("scrap_category").value;
  
  if (!source) {
    alert("Please select a scrap category."); 
    return false;
  }
  
  if (source === 'Sheet Production Scrap') {
    if (!document.getElementById("sheet_reference").value) {
      alert("Please select a reference number.");
      return false;
    }
    if (!document.getElementById("sheet_product").value) {
      alert("Please select scrap product.");
      return false;
    }
    if (!document.getElementById("sheet_type").value) {
      alert("Please select scrap type.");
      return false;
    }
    if (!document.getElementById("sheet_qty").value) {
      alert("Please enter scrap quantity.");
      return false;
    }
  }
  
  if (source === 'Sewing Scrap') {
    if (!document.getElementById("swing_cutting_batch").value) {
      alert("Please select CNC cutting batch.");
      return false;
    }
    if (!document.getElementById("swing_product").value) {
      alert("Please select scrap product.");
      return false;
    }
    if (!document.getElementById("swing_type").value) {
      alert("Please select scrap type.");
      return false;
    }
    if (!document.getElementById("swing_qty").value) {
      alert("Please enter scrap quantity.");
      return false;
    }
  }
  
  return true;
}


// Select button function
function selectBtn(btn, groupId) {
  const group = document.getElementById(groupId);
  const buttons = group.querySelectorAll('button');
  buttons.forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  
  // Map group to the correct hidden input id
  let hiddenId = groupId.replace('Group','');
  
  // Handle special cases for sheet and swing prefixes
  if (groupId === 'sheetProductGroup') hiddenId = 'sheet_product';
  if (groupId === 'sheetTypeGroup') hiddenId = 'sheet_type';
  if (groupId === 'swingProductGroup') hiddenId = 'swing_product';
  if (groupId === 'swingTypeGroup') hiddenId = 'swing_type';
  
  const hiddenEl = document.getElementById(hiddenId);
  if (hiddenEl) {
    hiddenEl.value = btn.dataset.value;
  }
  updateSummary();
}

function selectSource(btn) {
  // Select category button
  const group = document.getElementById('scrapCategoryGroup');
  const buttons = group.querySelectorAll('button');
  buttons.forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  
  document.getElementById('scrap_category').value = btn.dataset.value;
  
  // Show/hide appropriate fields based on source
  if (btn.dataset.value === 'Sheet Production Scrap') {
    document.getElementById('sheetScrapFields').style.display = 'block';
    document.getElementById('swingScrapFields').style.display = 'none';
    // Clear swing fields
    document.getElementById('swing_cutting_batch').value = '';
    document.getElementById('swing_product').value = '';
    document.getElementById('swing_type').value = '';
    document.getElementById('swing_qty').value = '';
    document.querySelectorAll('#swingProductGroup .btn, #swingTypeGroup .btn').forEach(b => b.classList.remove('selected'));
    
    // Auto-select Raw Material for sheet scrap
    const sheetProductBtn = document.querySelector('#sheetProductGroup .btn[data-value="Raw Material"]');
    if (sheetProductBtn) {
      sheetProductBtn.classList.add('selected');
      document.getElementById('sheet_product').value = 'Raw Material';
    }
  } else if (btn.dataset.value === 'Sewing Scrap') {
    document.getElementById('swingScrapFields').style.display = 'block';
    document.getElementById('sheetScrapFields').style.display = 'none';
    // Clear sheet fields
    document.getElementById('sheet_reference').value = '';
    document.getElementById('sheet_product').value = '';
    document.getElementById('sheet_type').value = '';
    document.getElementById('sheet_qty').value = '';
    document.querySelectorAll('#sheetProductGroup .btn, #sheetTypeGroup .btn').forEach(b => b.classList.remove('selected'));
    
    // Auto-select Raw Material for sewing scrap
    const swingProductBtn = document.querySelector('#swingProductGroup .btn[data-value="Raw Material"]');
    if (swingProductBtn) {
      swingProductBtn.classList.add('selected');
      document.getElementById('swing_product').value = 'Raw Material';
    }
  }
  updateSummary();
}

// Event listeners for quantity fields
document.addEventListener('DOMContentLoaded', function() {
  const sheetQty = document.getElementById('sheet_qty');
  const swingQty = document.getElementById('swing_qty');
  const sheetRef = document.getElementById('sheet_reference');
  const swingBatch = document.getElementById('swing_cutting_batch');
  
  if (sheetQty) sheetQty.addEventListener('input', updateSummary);
  if (swingQty) swingQty.addEventListener('input', updateSummary);
  if (sheetRef) sheetRef.addEventListener('change', updateSummary);
  if (swingBatch) swingBatch.addEventListener('change', updateSummary);
  
  // Pre-fill form in edit mode
  <?php if ($editMode && $editEntry): ?>
    // Select scrap category
    const sourceBtn = document.querySelector('#scrapCategoryGroup .btn[data-value="<?php echo htmlspecialchars($editEntry['scrap_category']); ?>"]');
    if (sourceBtn) {
      sourceBtn.click();
      
      setTimeout(function() {
        // Fill in category-specific fields
        <?php if ($editEntry['scrap_category'] === 'Sheet Production Scrap'): ?>
          document.getElementById('sheet_reference').value = '<?php echo htmlspecialchars($editEntry['reference_number']); ?>';
          const sheetProductBtn = document.querySelector('#sheetProductGroup .btn[data-value="<?php echo htmlspecialchars($editEntry['scrap_product']); ?>"]');
          if (sheetProductBtn) sheetProductBtn.click();
          const sheetTypeBtn = document.querySelector('#sheetTypeGroup .btn[data-value="<?php echo htmlspecialchars($editEntry['scrap_type']); ?>"]');
          if (sheetTypeBtn) sheetTypeBtn.click();
          document.getElementById('sheet_qty').value = '<?php echo $editEntry['qty']; ?>';
        <?php elseif ($editEntry['scrap_category'] === 'Sewing Scrap'): ?>
          document.getElementById('swing_cutting_batch').value = '<?php echo htmlspecialchars($editEntry['cutting_batch']); ?>';
          const swingProductBtn = document.querySelector('#swingProductGroup .btn[data-value="<?php echo htmlspecialchars($editEntry['scrap_product']); ?>"]');
          if (swingProductBtn) swingProductBtn.click();
          const swingTypeBtn = document.querySelector('#swingTypeGroup .btn[data-value="<?php echo htmlspecialchars($editEntry['scrap_type']); ?>"]');
          if (swingTypeBtn) swingTypeBtn.click();
          document.getElementById('swing_qty').value = '<?php echo $editEntry['qty']; ?>';
        <?php endif; ?>
        
        updateSummary();
      }, 100);
    }
  <?php endif; ?>
});

// Clear all fields and selections
function clearForm(){
  const form = document.getElementById('scrapForm');
  form.reset();
  
  // Clear button selections
  document.querySelectorAll('.btn-group .btn').forEach(b => b.classList.remove('selected'));
  
  // Clear hidden fields
  document.getElementById('scrap_category').value = '';
  document.getElementById('sheet_product').value = '';
  document.getElementById('sheet_type').value = '';
  document.getElementById('swing_product').value = '';
  document.getElementById('swing_type').value = '';
  
  // Hide conditional sections
  document.getElementById('sheetScrapFields').style.display = 'none';
  document.getElementById('swingScrapFields').style.display = 'none';
  
  // Clear summary
  document.getElementById('summaryBox').innerText = '';
  document.getElementById('summary').value = '';
}
</script>
</body>
</html>

