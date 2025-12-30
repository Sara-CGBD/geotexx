<?php
// swing_machine_entry_with_dedicated_tables.php
// This version uses dedicated operators and helpers tables

session_start();
require_once 'security_config.php';

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

date_default_timezone_set('Asia/Dhaka');

// Connect DB
$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

// Fetch projects
$projects = [];
$res = $conn->query("SELECT id, project_name FROM projects ORDER BY project_name ASC");
if ($res) while ($r = $res->fetch_assoc()) $projects[] = $r;

// Fetch machines
$machines = [];
$m = $conn->query("SELECT id, machine_name FROM machines ORDER BY machine_name ASC");
if ($m) while ($row = $m->fetch_assoc()) $machines[] = $row;

// Fetch operators from dedicated table
$operators = [];
$op_query = $conn->query("SELECT id, operator_code, operator_name FROM operators WHERE is_active = 1 ORDER BY operator_name ASC");
if ($op_query) while ($op = $op_query->fetch_assoc()) $operators[] = $op;

// Fetch helpers from dedicated table
$helpers = [];
$hl_query = $conn->query("SELECT id, helper_code, helper_name FROM helpers WHERE is_active = 1 ORDER BY helper_name ASC");
if ($hl_query) while ($hl = $hl_query->fetch_assoc()) $helpers[] = $hl;

// Reporter from session
$reporter_id = $_SESSION['user_id'];
$reporter_name = $_SESSION['username'];

// Generate Swing Machine ID (auto-increment based on date and sequence)
$current_date = date('Y-m-d');
$next_swing_number = 1;

// Check if swing_machine_entry table exists and has swing_id column
$table_check = $conn->query("SHOW TABLES LIKE 'swing_machine_entry'");
if ($table_check && $table_check->num_rows > 0) {
    // Check if swing_id column exists
    $column_check = $conn->query("SHOW COLUMNS FROM swing_machine_entry LIKE 'swing_id'");
    if ($column_check && $column_check->num_rows > 0) {
        $last_swing = $conn->query("SELECT MAX(CAST(SUBSTRING(swing_id, -3) AS UNSIGNED)) as last_num FROM swing_machine_entry WHERE DATE(date_time) = '$current_date'");
        if ($last_swing && $last_swing->num_rows > 0) {
            $row = $last_swing->fetch_assoc();
            if ($row['last_num']) {
                $next_swing_number = $row['last_num'] + 1;
            }
        }
    }
}

$swing_id = "SW-" . date('Ymd') . "-" . str_pad($next_swing_number, 3, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Swing Machine Entry</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
  .container { max-width:1000px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);}
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
  .info-box { background: #e7f3ff; border: 1px solid #b3d9ff; padding: 15px; border-radius: 6px; margin-bottom: 20px; }
  .info-box h3 { margin: 0 0 10px 0; color: #0066cc; }
  .info-box p { margin: 5px 0; color: #333; }
</style>
</head>
<body>
<div class="container">
  
  <h1>Swing Machine Entry</h1>

  <div class="info-box">
    <h3>ðŸ“‹ Data Source Information</h3>
    <p><strong>Operators:</strong> <?php echo count($operators); ?> active operators from dedicated operators table</p>
    <p><strong>Helpers:</strong> <?php echo count($helpers); ?> active helpers from dedicated helpers table</p>
    <p><strong>Projects:</strong> <?php echo count($projects); ?> projects available</p>
  </div>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
      âœ… Swing Machine Entry saved successfully!
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger" style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
      âŒ Error: <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <form id="swingForm" method="post" action="../handlers/submit_swing_machine_entry.php" onsubmit="return validateForm();">

    <!-- Entry ID auto -->
    <div class="form-group">
      <label>Swing Machine ID </label>
      <input type="text" id="swingIdDisplay" value="<?php echo $swing_id; ?>" readonly class="readonly">
      <input type="hidden" id="swing_id" name="swing_id" value="<?php echo $swing_id; ?>">
    </div>

    <!-- Hidden datetime -->
    <input type="hidden" id="dateTime" name="date_time">

    <!-- Reporter -->
    <div class="form-group">
      <label>Reporter </label>
      <input type="text" value="<?php echo htmlspecialchars($reporter_name); ?>" readonly class="readonly">
      <input type="hidden" name="reporter_id" value="<?php echo $reporter_id; ?>">
    </div>

    <!-- Project -->
    <div class="form-group">
      <label>Project:</label>
      <div class="btn-group" id="projectGroup">
        <?php foreach ($projects as $p): ?>
        <button type="button" class="btn" data-value="<?php echo (int)$p['id']; ?>" onclick="selectBtn(this, 'projectGroup')">
          <?php echo htmlspecialchars($p['project_name']); ?>
        </button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" id="project_id" name="project_id" value="">
    </div>  

    <!-- Operator -->
    <div class="form-group">
      <label>Operator</label>
      <select id="operator_id" name="operator_id" required>
        <option value="">-- Select Operator --</option>
        <?php foreach($operators as $op): ?>
          <option value="<?php echo $op['id']; ?>">
            <?php echo htmlspecialchars($op['operator_code'] . ' - ' . $op['operator_name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Helper -->
    <div class="form-group">
      <label>Helper</label>
      <select id="helper_id" name="helper_id" required>
        <option value="">-- Select Helper --</option>
        <?php foreach($helpers as $hl): ?>
          <option value="<?php echo $hl['id']; ?>">
            <?php echo htmlspecialchars($hl['helper_code'] . ' - ' . $hl['helper_name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Line number -->
    <div class="form-group">
      <label>Line Number </label>
      <input type="text" id="line_no" name="line_no" required>
    </div>

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
  const utc = now.getTime() + now.getTimezoneOffset()*60000;
  const dhaka = new Date(utc + 6*3600000);
  document.getElementById("dateTimeDisplay").innerHTML =
    "Date & Time: " + dhaka.toDateString() + " " + dhaka.toLocaleTimeString();
  document.getElementById("dateTime").value = dhaka.toISOString().slice(0,19).replace("T"," ");
  const h = dhaka.getHours();
  document.getElementById("shiftBanner").innerText = "Shift: " + ((h>=8&&h<20)?"Day":"Night");
}
setInterval(updateTimeAndShift,1000); updateTimeAndShift();

function selectBtn(btn, groupId){
  document.querySelectorAll(`#${groupId} .btn`).forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  if(groupId==="projectGroup"){
    document.getElementById("project_id").value = btn.dataset.value;
  }
  updateSummary();
}

function updateSummary() {
  const dateTime = document.getElementById("dateTime").value;
  const shift = document.getElementById("shiftBanner").innerText.replace("Shift: ", "");
  const swingId = document.getElementById("swing_id").value;
  
  // Get selected values
  const selectedProject = document.querySelector('#projectGroup .btn.selected');
  const projectName = selectedProject ? selectedProject.textContent.trim() : '';
  
  const operatorSelect = document.getElementById("operator_id");
  const operatorName = operatorSelect.options[operatorSelect.selectedIndex].text;
  
  const helperSelect = document.getElementById("helper_id");
  const helperName = helperSelect.options[helperSelect.selectedIndex].text;
  
  const lineNo = document.getElementById("line_no").value;
  const sewingQty = document.getElementById("sewing_qty").value;
  const ncpPiece = document.getElementById("ncp_piece").value;
  
  // Only show summary if at least some basic info is available
  if (dateTime && shift && swingId) {
    let summary = `${dateTime} | Shift: ${shift} | Swing ID: ${swingId}`;
    if (projectName) summary += ` | Project: ${projectName}`;
    if (operatorName !== "-- Select Operator --") summary += ` | Operator: ${operatorName}`;
    if (helperName !== "-- Select Helper --") summary += ` | Helper: ${helperName}`;
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
  document.getElementById("swingForm").reset();
  
  // Clear button selections
  document.querySelectorAll('#projectGroup .btn').forEach(b=>b.classList.remove('selected'));
  document.getElementById("project_id").value="";
  
  // Clear summary
  document.getElementById("summaryBox").innerText = "";
  document.getElementById("summary").value = "";
  
  // Reload the page to get a new Swing Machine ID
  window.location.reload();
}

function validateForm(){
  if(!document.getElementById("project_id").value){
    alert("Please select a project."); return false;
  }
  if(!document.getElementById("operator_id").value){
    alert("Please select an operator."); return false;
  }
  if(!document.getElementById("helper_id").value){
    alert("Please select a helper."); return false;
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
  document.getElementById('project_id').addEventListener('change', updateSummary);
  document.getElementById('operator_id').addEventListener('change', updateSummary);
  document.getElementById('helper_id').addEventListener('change', updateSummary);
  document.getElementById('line_no').addEventListener('input', updateSummary);
  document.getElementById('sewing_qty').addEventListener('input', updateSummary);
  document.getElementById('ncp_piece').addEventListener('input', updateSummary);
  
  // Update summary on page load
  updateSummary();
});
</script>
</body>
</html>


