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
      <label>Sewing Quantity (Pieces)</label>
      <input type="number" id="sewing_qty" name="sewing_qty" required min="1">
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
        option.value = batch.batch;
        
        // Build display text with batch number and references
        let displayText = batch.batch;
        
        // Add references if available
        if (batch.references && batch.references.length > 0) {
          const refCount = batch.references.length;
          
          // For small batches (<= 5 refs), show all references
          if (refCount <= 5) {
            displayText += ` - Ref: ${batch.references.join(', ')}`;
          } 
          // For medium batches (6-15 refs), show first 2 and count
          else if (refCount <= 15) {
            const refsToShow = batch.references.slice(0, 2).join(', ');
            displayText += ` - Ref: ${refsToShow} +${refCount - 2} more`;
          }
          // For large batches (>15 refs), just show count
          else {
            displayText += ` - ${refCount} references`;
          }
        }
        
        option.textContent = displayText;
        option.setAttribute('data-entry-count', batch.entry_count);
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
  if(!document.getElementById("sewing_qty").value.trim()){
    alert("Please enter sewing quantity."); return false;
  }
  if(!document.getElementById("ncp_piece").value.trim()){
    alert("Please enter NCP piece."); return false;
  }
  return true;
}

// Add event listeners for form fields to update summary
document.addEventListener('DOMContentLoaded', function() {
  // Load CNC cutting batches on page load
  loadCNCCuttingBatches();
  
  document.getElementById('project_id').addEventListener('change', updateSummary);
  document.getElementById('line_no').addEventListener('input', updateSummary);
  document.getElementById('sewing_qty').addEventListener('input', updateSummary);
  document.getElementById('ncp_piece').addEventListener('input', updateSummary);
  document.getElementById('cnc_cutting_batch').addEventListener('change', updateSummary);
  
  // Update summary on page load
  updateSummary();
});
</script>
</body>
</html>

