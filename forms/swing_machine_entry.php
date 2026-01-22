<?php
// sewing_machine_entry.php

session_start();
require_once 'security_config.php';
require_once '../config/project_helper.php';

// Session & security check
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

// Role-based access control for Production module
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_PRODUCTION, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access the Production module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// Connect DB
$conn = SecurityConfig::getConnection();
if (!$conn) die("DB connection failed");

$defaultProject = getDefaultProject($conn);
$projects = $defaultProject ? [$defaultProject] : [];

// CNC cutting batches will be loaded via API

// Reporter from session
$reporter_id = $_SESSION['user_id'];
$reporter_name = $_SESSION['username'];

// Generate Sewing Machine ID (auto-increment based on date and sequence)
$current_date = date('Y-m-d');
$next_sewing_number = 1;

// Check if sewing_machine_entry table exists and has sewing_id column
// Also check for old swing_machine_entry table for backward compatibility
$table_check = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
$table_name = 'sewing_machine_entry';
$id_column = 'sewing_id';

// If new table doesn't exist, check for old table name
if (!$table_check || $table_check->num_rows == 0) {
    $old_table_check = $conn->query("SHOW TABLES LIKE 'swing_machine_entry'");
    if ($old_table_check && $old_table_check->num_rows > 0) {
        $table_name = 'swing_machine_entry';
        $id_column = 'swing_id';
    }
}

if ($table_check && $table_check->num_rows > 0 || ($table_name == 'swing_machine_entry' && isset($old_table_check) && $old_table_check->num_rows > 0)) {
    // Check if id column exists
    $column_check = $conn->query("SHOW COLUMNS FROM $table_name LIKE '$id_column'");
    if ($column_check && $column_check->num_rows > 0) {
        $last_sewing = $conn->query("SELECT MAX(CAST(SUBSTRING($id_column, -3) AS UNSIGNED)) as last_num FROM $table_name WHERE DATE(date_time) = '$current_date'");
        if ($last_sewing && $last_sewing->num_rows > 0) {
            $row = $last_sewing->fetch_assoc();
            if ($row['last_num']) {
                $next_sewing_number = $row['last_num'] + 1;
            }
        }
    }
}

$sewing_id = "SEW-" . date('Ymd') . "-" . str_pad($next_sewing_number, 3, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sewing Machine Entry</title>
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
  .btn-group { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
  .btn-group .btn {
    padding: 8px 16px; border: 1px solid #ddd; background: #fff; cursor: pointer;
    border-radius: 4px; transition: all 0.2s; font-size: 14px;
  }
  .btn-group .btn:hover { background: #f8f9fa; border-color: #007bff; }
  .btn-group .btn.selected { background: #007bff; color: #fff; border-color: #007bff; }
  
  /* Modern Warning Popup Styles */
  .popup-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    z-index: 10000;
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.2s ease-out;
  }
  .popup-overlay.show {
    display: flex;
  }
  @keyframes fadeIn {
    from {
      opacity: 0;
    }
    to {
      opacity: 1;
    }
  }
  .warning-popup {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(220, 53, 69, 0.3), 0 0 0 1px rgba(220, 53, 69, 0.1);
    max-width: 480px;
    width: 90%;
    overflow: hidden;
    animation: slideUp 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    transform-origin: center;
  }
  @keyframes slideUp {
    from {
      opacity: 0;
      transform: translateY(30px) scale(0.95);
    }
    to {
      opacity: 1;
      transform: translateY(0) scale(1);
    }
  }
  .warning-popup-content {
    display: flex;
    align-items: flex-start;
    padding: 28px 24px;
    position: relative;
  }
  .warning-bar {
    width: 5px;
    background: linear-gradient(180deg, #dc3545 0%, #c82333 100%);
    border-radius: 3px 0 0 3px;
    margin-right: 20px;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);
  }
  .warning-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 18px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4);
  }
  .warning-icon span {
    color: #fff;
    font-size: 28px;
    font-weight: 700;
    line-height: 1;
  }
  .warning-text {
    flex: 1;
    padding-top: 2px;
  }
  .warning-title {
    font-size: 20px;
    font-weight: 700;
    color: #1a1a1a;
    margin: 0 0 8px 0;
    letter-spacing: -0.3px;
  }
  .warning-message {
    font-size: 15px;
    color: #4a4a4a;
    margin: 0;
    line-height: 1.6;
    font-weight: 500;
  }
  .popup-actions {
    padding: 20px 24px;
    background: linear-gradient(to bottom, #fafafa 0%, #f5f5f5 100%);
    display: flex;
    justify-content: center;
    border-top: 1px solid #e8e8e8;
  }
  .popup-btn {
    padding: 12px 36px;
    border: none;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    min-width: 100px;
  }
  .popup-btn-ok {
    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
  }
  .popup-btn-ok:hover {
    background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
    box-shadow: 0 6px 16px rgba(220, 53, 69, 0.4);
    transform: translateY(-1px);
  }
</style>
</head>
<body>
<div class="container">
  
  <h1>Sewing Machine Entry</h1>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
      Sewing Machine Entry saved successfully! Entry ID: <strong><?php echo isset($_GET['entry_id']) ? htmlspecialchars($_GET['entry_id']) : 'N/A'; ?></strong>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger" style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
      ❌ Error: <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <form id="sewingForm" method="post" action="../handlers/submit_sewing_machine_entry.php" onsubmit="return validateForm();">

    <!-- Entry ID auto -->
    <div class="form-group">
      <label>Sewing Machine ID </label>
      <input type="text" id="sewingIdDisplay" value="<?php echo $sewing_id; ?>" readonly class="readonly">
      <input type="hidden" id="sewing_id" name="sewing_id" value="<?php echo $sewing_id; ?>">
    </div>

    <!-- Hidden datetime -->
    <input type="hidden" id="dateTime" name="date_time">
    <input type="hidden" id="shift" name="shift">

    <!-- Reporter -->
    <div class="form-group">
      <label>Reporter </label>
      <input type="text" value="<?php echo htmlspecialchars($reporter_name); ?>" readonly class="readonly">
      <input type="hidden" name="reporter_id" value="<?php echo $reporter_id; ?>">
    </div>

    <!-- CNC Cutting Batch (dropdown from cnc_entries) -->
    <div class="form-group">
      <label>CNC Cutting Batch:</label>
      <select id="cnc_cutting_batch" name="cnc_cutting_batch" required onchange="updateSummary()">
        <option value="">-- Loading CNC Cutting Batches... --</option>
      </select>
      <div id="batch_loading" style="display: none; margin-top: 10px;"></div>
    </div>

    <!-- Project -->
    <div class="form-group">
      <label>Project:</label>
      <div class="btn-group" id="projectGroup">
        <?php foreach ($projects as $index => $p): ?>
        <button type="button" class="btn <?php echo $index === 0 ? 'selected' : ''; ?>" data-value="<?php echo (int)$p['id']; ?>" onclick="selectBtn(this, 'projectGroup')">
          <?php echo htmlspecialchars($p['project_name']); ?>
        </button>
        <?php endforeach; ?>
      </div>
       <input type="hidden" id="project_id" name="project_id" value="<?php echo isset($projects[0]['id']) ? (int)$projects[0]['id'] : ''; ?>">
    </div>  

    <!-- Line Number (10 buttons: 1-10) -->
    <div class="form-group">
      <label>Line Number:</label>
      <div class="btn-group" id="lineNumberGroup">
        <button type="button" class="btn" data-value="1" onclick="selectBtn(this, 'lineNumberGroup')">1</button>
        <button type="button" class="btn" data-value="2" onclick="selectBtn(this, 'lineNumberGroup')">2</button>
        <button type="button" class="btn" data-value="3" onclick="selectBtn(this, 'lineNumberGroup')">3</button>
        <button type="button" class="btn" data-value="4" onclick="selectBtn(this, 'lineNumberGroup')">4</button>
        <button type="button" class="btn" data-value="5" onclick="selectBtn(this, 'lineNumberGroup')">5</button>
        <button type="button" class="btn" data-value="6" onclick="selectBtn(this, 'lineNumberGroup')">6</button>
        <button type="button" class="btn" data-value="7" onclick="selectBtn(this, 'lineNumberGroup')">7</button>
        <button type="button" class="btn" data-value="8" onclick="selectBtn(this, 'lineNumberGroup')">8</button>
        <button type="button" class="btn" data-value="9" onclick="selectBtn(this, 'lineNumberGroup')">9</button>
        <button type="button" class="btn" data-value="10" onclick="selectBtn(this, 'lineNumberGroup')">10</button>
      </div>
      <input type="hidden" id="line_no" name="line_no" value="">
    </div>

    <!-- Operator/Helper removed -->

    <!-- Sewing quantity -->
    <div class="form-group">
      <label>Sewing Quantity (Pieces) <span id="cutting_qty_hint" style="color:#27ae60; font-weight:normal; font-size:13px;"></span></label>
      <input type="number" id="sewing_qty" name="sewing_qty" required min="1" onchange="validateSewingQuantity()" oninput="validateSewingQuantity()">
      <small id="sewing_qty_error" style="color:#e74c3c; display:none; margin-top:5px;"></small>
    </div>

    <!-- NCP piece -->
    <div class="form-group">
      <label>NCP Piece</label>
      <input type="number" id="ncp_piece" name="ncp_piece" required min="0">
    </div>

    <!-- Summary Section -->
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

<!-- Modern Warning Popup -->
<div id="quantityExceedPopup" class="popup-overlay">
  <div class="warning-popup">
    <div class="warning-popup-content">
      <div class="warning-bar"></div>
      <div class="warning-icon">
        <span>!</span>
      </div>
      <div class="warning-text">
        <div class="warning-title">Quantity Exceeded</div>
        <div class="warning-message" id="quantityExceedMessage"></div>
      </div>
    </div>
    <div class="popup-actions">
      <button class="popup-btn popup-btn-ok" onclick="closeQuantityExceedPopup()">OK</button>
    </div>
  </div>
</div>

<script>
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
  const shift = (h>=8&&h<=19)?"Day":"Night";
  document.getElementById("shiftBanner").innerText = "Shift: " + shift;
  document.getElementById("shift").value = shift;
}
setInterval(updateTimeAndShift,1000); updateTimeAndShift();

// Load CNC cutting batches from API
function loadCNCCuttingBatches() {
  const batchSelect = document.getElementById('cnc_cutting_batch');
  const loadingText = document.getElementById('batch_loading');
  
  if (!batchSelect) return;
  
  fetch('api/get_cnc_cutting_batches.php')
    .then(response => response.json())
    .then(data => {
      if (loadingText) {
        loadingText.innerHTML = '';
        loadingText.style.display = 'none';
      }
      
      if (!data.success) {
        batchSelect.innerHTML = '<option value="">-- Failed to load batches --</option>';
        if (loadingText) {
          loadingText.innerHTML = `
            <div style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border: 1px solid #fca5a5; border-radius: 10px; color: #991b1b; font-size: 0.9rem; box-shadow: 0 2px 8px rgba(239, 68, 68, 0.1);">
              <i class="fas fa-exclamation-circle" style="font-size: 1.1rem; color: #dc2626;"></i>
              <span style="font-weight: 500;">Error loading batches. Please try again.</span>
            </div>
          `;
          loadingText.style.display = 'block';
        }
        return;
      }
      
      const batches = data.batches || [];
      batchSelect.innerHTML = '<option value="">-- Select CNC Cutting Batch --</option>';
      
      if (batches.length === 0) {
        batchSelect.innerHTML = '<option value="">-- No batches available --</option>';
        if (loadingText) {
          loadingText.innerHTML = `
            <div style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 1px solid #fbbf24; border-radius: 10px; color: #92400e; font-size: 0.9rem; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.1);">
              <i class="fas fa-info-circle" style="font-size: 1.1rem; color: #d97706;"></i>
              <div style="flex: 1;">
                <div style="font-weight: 600; margin-bottom: 4px;">No CNC cutting batches found</div>
                <div style="font-size: 0.85rem; opacity: 0.9;">Please create CNC entries first to proceed with sewing machine entry.</div>
              </div>
            </div>
          `;
          loadingText.style.display = 'block';
        }
        return;
      }
      
      batches.forEach(batch => {
        const option = document.createElement('option');
        option.value = batch.cnc_cutting_batch;
        
        // Build display text with batch number and date
        let displayText = batch.cnc_cutting_batch;
        
        // Add date if available
        if (batch.batch_date) {
          displayText += ` (${batch.batch_date})`;
        }
        
        option.textContent = displayText;
        // Store cutting quantity as data attribute for validation
        option.setAttribute('data-cutting-quantity', batch.total_cutting_quantity || 0);
        option.setAttribute('data-first-entry', batch.first_entry_date);
        option.setAttribute('data-last-entry', batch.last_entry_date);
        if (batch.references && batch.references.length > 0) {
          option.setAttribute('data-references', batch.references.join(','));
        }
        batchSelect.appendChild(option);
      });
    })
    .catch(err => {
      console.error('Error loading CNC cutting batches:', err);
      batchSelect.innerHTML = '<option value="">-- Error loading batches --</option>';
      if (loadingText) {
        loadingText.textContent = 'Error loading batches: ' + err.message;
        loadingText.style.color = '#e74c3c';
        loadingText.style.display = 'block';
      }
    });
}

function selectBtn(btn, groupId){
  document.querySelectorAll(`#${groupId} .btn`).forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  if(groupId==="projectGroup"){
    document.getElementById("project_id").value = btn.dataset.value;
  }
  if(groupId==="lineNumberGroup"){
    document.getElementById("line_no").value = btn.dataset.value;
  }
  updateSummary();
}

function updateSummary() {
  const dateTime = document.getElementById("dateTime").value;
  const shift = document.getElementById("shiftBanner").innerText.replace("Shift: ", "");
  const sewingId = document.getElementById("sewing_id").value;
  
  // Get selected values
  const cncBatch = document.getElementById("cnc_cutting_batch").value;
  
  const selectedProject = document.querySelector('#projectGroup .btn.selected');
  const projectName = selectedProject ? selectedProject.textContent.trim() : '';
  
  const selectedLineNumber = document.querySelector('#lineNumberGroup .btn.selected');
  const lineNo = selectedLineNumber ? selectedLineNumber.textContent.trim() : '';
  
  const sewingQty = document.getElementById("sewing_qty").value;
  const ncpPiece = document.getElementById("ncp_piece").value;
  
  // Only show summary if at least some basic info is available
  if (dateTime && shift && sewingId) {
    let summary = `${dateTime} | Shift: ${shift} | Sewing ID: ${sewingId}`;
    if (cncBatch) summary += ` | CNC Batch: ${cncBatch}`;
    if (projectName) summary += ` | Project: ${projectName}`;
    if (lineNo) summary += ` | Line: ${lineNo}`;
    if (sewingQty) summary += ` | Sewing Qty: ${sewingQty}`;
    if (ncpPiece) summary += ` | NCP: ${ncpPiece}`;
    
    document.getElementById("summaryBox").innerText = summary;
    document.getElementById("summary").value = summary;
  } else {
    document.getElementById("summaryBox").innerText = "";
    document.getElementById("summary").value = "";
  }
}

function clearForm(){
  // Clear all form fields
  document.getElementById("sewingForm").reset();
  
  // Clear button selections
  document.querySelectorAll('#projectGroup .btn').forEach(b=>b.classList.remove('selected'));
  const defaultProjectBtn = document.querySelector('#projectGroup .btn');
  if (defaultProjectBtn) {
    defaultProjectBtn.classList.add('selected');
    document.getElementById("project_id").value = defaultProjectBtn.dataset.value || '';
  } else {
    document.getElementById("project_id").value="";
  }
  // Clear summary
  document.getElementById("summaryBox").innerText = "";
  document.getElementById("summary").value = "";
  
  // Reload the page to get a new Sewing Machine ID
  window.location.reload();
}

function validateForm(){
  if(!document.getElementById("project_id").value){
    alert("Please select a project."); return false;
  }
  if(!document.getElementById("line_no").value.trim()){
    alert("Please enter line number."); return false;
  }
  if(!document.getElementById("cnc_cutting_batch").value){
    alert("Please select a CNC cutting batch."); return false;
  }
  if(!document.getElementById("sewing_qty").value.trim()){
    alert("Please enter sewing quantity."); return false;
  }
  
  // Validate sewing quantity doesn't exceed cutting quantity
  const batchSelect = document.getElementById("cnc_cutting_batch");
  const selectedOption = batchSelect.options[batchSelect.selectedIndex];
  const cuttingQuantity = parseInt(selectedOption.getAttribute('data-cutting-quantity')) || 0;
  const sewingQty = parseInt(document.getElementById("sewing_qty").value) || 0;
  
  if (cuttingQuantity > 0 && sewingQty > cuttingQuantity) {
    showQuantityExceedPopup(sewingQty, cuttingQuantity);
    document.getElementById("sewing_qty").focus();
    return false;
  }
  
  if(!document.getElementById("ncp_piece").value.trim()){
    alert("Please enter NCP piece."); return false;
  }
  return true;
}

// Update cutting quantity hint when batch is selected
function updateCuttingQuantityHint() {
  const batchSelect = document.getElementById('cnc_cutting_batch');
  const hintElement = document.getElementById('cutting_qty_hint');
  const errorElement = document.getElementById('sewing_qty_error');
  
  if (!batchSelect || !hintElement) return;
  
  const selectedOption = batchSelect.options[batchSelect.selectedIndex];
  if (selectedOption && selectedOption.value) {
    const cuttingQuantity = parseInt(selectedOption.getAttribute('data-cutting-quantity')) || 0;
    if (cuttingQuantity > 0) {
      hintElement.textContent = `(Max: ${cuttingQuantity} pieces)`;
      hintElement.style.display = 'inline';
    } else {
      hintElement.textContent = '';
      hintElement.style.display = 'none';
    }
  } else {
    hintElement.textContent = '';
    hintElement.style.display = 'none';
  }
  
  // Clear error when batch changes
  if (errorElement) {
    errorElement.style.display = 'none';
    errorElement.textContent = '';
  }
}

// Validate sewing quantity against cutting quantity
function validateSewingQuantity() {
  const batchSelect = document.getElementById('cnc_cutting_batch');
  const sewingQtyInput = document.getElementById('sewing_qty');
  const errorElement = document.getElementById('sewing_qty_error');
  
  if (!batchSelect || !sewingQtyInput || !errorElement) return;
  
  const selectedOption = batchSelect.options[batchSelect.selectedIndex];
  if (!selectedOption || !selectedOption.value) {
    errorElement.style.display = 'none';
    errorElement.textContent = '';
    return;
  }
  
  const cuttingQuantity = parseInt(selectedOption.getAttribute('data-cutting-quantity')) || 0;
  const sewingQty = parseInt(sewingQtyInput.value) || 0;
  
  if (cuttingQuantity > 0 && sewingQty > cuttingQuantity) {
    errorElement.textContent = `Sewing quantity (${sewingQty}) cannot exceed cutting quantity (${cuttingQuantity}) for this CNC cutting batch.`;
    errorElement.style.display = 'block';
    sewingQtyInput.style.borderColor = '#e74c3c';
    // Show modern popup
    showQuantityExceedPopup(sewingQty, cuttingQuantity);
  } else {
    errorElement.style.display = 'none';
    errorElement.textContent = '';
    sewingQtyInput.style.borderColor = '';
  }
}

// Show modern popup for quantity exceeded
function showQuantityExceedPopup(sewingQty, cuttingQuantity) {
  const popup = document.getElementById('quantityExceedPopup');
  const messageElement = document.getElementById('quantityExceedMessage');
  
  if (popup && messageElement) {
    messageElement.textContent = `Sewing quantity (${sewingQty} pieces) cannot exceed cutting quantity (${cuttingQuantity} pieces) for this CNC cutting batch.`;
    popup.classList.add('show');
  }
}

// Close quantity exceed popup
function closeQuantityExceedPopup() {
  const popup = document.getElementById('quantityExceedPopup');
  if (popup) {
    popup.classList.remove('show');
  }
}

// Add event listeners for form fields to update summary
document.addEventListener('DOMContentLoaded', function() {
  // Load CNC cutting batches on page load
  loadCNCCuttingBatches();
  
  document.getElementById('project_id').addEventListener('change', updateSummary);
  document.getElementById('line_no').addEventListener('input', updateSummary);
  document.getElementById('sewing_qty').addEventListener('input', updateSummary);
  document.getElementById('ncp_piece').addEventListener('input', updateSummary);
  document.getElementById('cnc_cutting_batch').addEventListener('change', function() {
    updateCuttingQuantityHint();
    validateSewingQuantity();
    updateSummary();
  });
  
  // Update cutting quantity hint when batch is selected
  updateCuttingQuantityHint();
  
  // Update summary on page load
  updateSummary();
});
</script>
</body>
</html>

