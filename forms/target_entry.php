<?php
session_start();
require_once '../config/security_config.php';

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

date_default_timezone_set('Asia/Dhaka');

// DB connection
$conn = SecurityConfig::getConnection();

// Get next target ID
$next_target_id = 1;
$res = $conn->query("SELECT MAX(target_id) AS tid FROM production_targets");
if ($res && ($row = $res->fetch_assoc())) {
    $next_target_id = ($row['tid'] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Target Setting Entry</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
  .container { max-width:900px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);}
  h1 { text-align:center; font-size:28px; margin-bottom:30px; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  input[type="text"], input[type="number"], input[type="date"], select, textarea {
    padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px);
  }
  .readonly { background:#ecf0f1; }
  .btn-group { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:10px; }
  .btn { padding:10px 16px; font-size:14px; border:none; border-radius:6px; cursor:pointer; background:#e0e0e0; }
  .btn.selected { background:#3498db; color:#fff; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
  .alert {
    padding: 10px;
    border-radius: 6px;
    margin-bottom: 20px;
  }
  .alert-success {
    background: #efe;
    color: #3c3;
    border: 1px solid #cfc;
  }
  .alert-error {
    background: #fee;
    color: #c33;
    border: 1px solid #fcc;
  }
</style>
</head>
<body>
<div class="container">
  
  <h1>Target Setting Entry</h1>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error">
      <strong>Error:</strong> <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">
      <strong>Success:</strong> Target entry created successfully!
    </div>
  <?php endif; ?>

  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      Back to Dashboard
    </a>
  </div>

  <form id="targetForm" method="post" action="../handlers/submit_target_entry.php" onsubmit="return validateForm();">

    <!-- Hidden datetime + shift -->
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">

    <!-- Target ID -->
    <div class="form-group">
      <label>Target ID: </label>
      <input type="text" value="TARGET-<?php echo str_pad($next_target_id, 4, '0', STR_PAD_LEFT); ?>" readonly class="readonly">
      <input type="hidden" name="target_id" value="<?php echo $next_target_id; ?>">
    </div>

    <!-- Module Selection -->
    <div class="form-group">
      <label>Module / Department: </label>
      <div class="btn-group" id="moduleGroup">
        <button type="button" class="btn" data-id="1" onclick="selectBtn(this,'moduleGroup')">Production</button>
        <button type="button" class="btn" data-id="2" onclick="selectBtn(this,'moduleGroup')">Roll Production</button>
        <button type="button" class="btn" data-id="3" onclick="selectBtn(this,'moduleGroup')">CNC Cutting</button>
        <button type="button" class="btn" data-id="4" onclick="selectBtn(this,'moduleGroup')">Sewing</button>
        <button type="button" class="btn" data-id="5" onclick="selectBtn(this,'moduleGroup')">Branding</button>
        <button type="button" class="btn" data-id="6" onclick="selectBtn(this,'moduleGroup')">Quality Control</button>
        <button type="button" class="btn" data-id="7" onclick="selectBtn(this,'moduleGroup')">Finished Goods</button>
        <button type="button" class="btn" data-id="8" onclick="selectBtn(this,'moduleGroup')">Recycle</button>
        <button type="button" class="btn" data-id="9" onclick="selectBtn(this,'moduleGroup')">Scrap/Waste</button>
      </div>
      <input type="hidden" name="module_id" id="module_id" required>
    </div>

    <!-- Target Period -->
    <div class="form-group">
      <label>Target Period: </label>
      <div class="btn-group" id="periodGroup">
        <button type="button" class="btn" data-value="Daily" onclick="selectPeriod(this)">Daily</button>
        <button type="button" class="btn" data-value="Weekly" onclick="selectPeriod(this)">Weekly</button>
        <button type="button" class="btn" data-value="Monthly" onclick="selectPeriod(this)">Monthly</button>
      </div>
      <input type="hidden" name="target_period" id="target_period" required>
    </div>

    <!-- Target Date -->
    <div class="form-group">
      <label>Target Date: </label>
      <input type="date" name="target_date" id="target_date" required onchange="updateSummary()">
    </div>

    <!-- Target Quantity -->
    <div class="form-group">
      <label>Target Quantity: </label>
      <input type="number" name="target_qty" id="target_qty" min="1" step="1" required oninput="updateSummary()">
    </div>

    <!-- Current Production Quantity -->
    <div class="form-group">
      <label>Current Production Quantity: </label>
      <input type="number" name="production_qty" id="production_qty" min="0" step="1" value="0" required oninput="updateSummary()">
    </div>

    <!-- Remarks -->
    <div class="form-group">
      <label>Remarks (Optional): </label>
      <textarea name="remarks" id="remarks" rows="3" oninput="updateSummary()" style="width: calc(100% - 22px); padding: 10px; border: 1px solid #ccc; border-radius: 6px; resize: vertical;"></textarea>
    </div>

    <!-- Summary Section -->
    <div class="form-group">
      <div id="summaryBox" class="summary-info"></div>
      <input type="hidden" id="summary" name="summary">
    </div>

    <div class="actions">
      <button type="submit" class="submit-btn">Submit</button>
      <button type="reset" class="clear-btn" onclick="clearForm()">Clear</button>
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
    const shift = (h >= 8 && h <= 19) ? "Day" : "Night";
    document.getElementById("shiftBanner").innerText = "Shift: " + shift;
    document.getElementById("shift").value = shift;
    
    // Set default target date to today if not set
    if (!document.getElementById("target_date").value) {
        document.getElementById("target_date").value = `${yyyy}-${mm}-${dd}`;
    }
    
    updateSummary();
}
setInterval(updateTimeAndShift, 1000);
updateTimeAndShift();

// Module names mapping
const moduleNames = {
    '1': 'Production',
    '2': 'Roll Production',
    '3': 'CNC Cutting',
    '4': 'Sewing',
    '5': 'Branding',
    '6': 'Quality Control',
    '7': 'Finished Goods',
    '8': 'Recycle',
    '9': 'Scrap/Waste'
};

// Button selection for modules
function selectBtn(btn, groupId){
  document.querySelectorAll(`#${groupId} .btn`).forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById("module_id").value = btn.dataset.id;
  updateSummary();
}

// Period selection
function selectPeriod(btn){
  document.querySelectorAll('#periodGroup .btn').forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById("target_period").value = btn.dataset.value;
  updateSummary();
}

// Update summary
function updateSummary(){
  const dateTime = document.getElementById('dateTime').value;
  const targetId = document.querySelector('input[name="target_id"]').value;
  const moduleId = document.getElementById('module_id').value;
  const moduleName = moduleNames[moduleId] || '';
  const period = document.getElementById('target_period').value;
  const targetDate = document.getElementById('target_date').value;
  const targetQty = document.getElementById('target_qty').value;
  const productionQty = document.getElementById('production_qty').value;
  const remarks = document.getElementById('remarks').value.trim();

  if (dateTime) {
    let s = `${dateTime}`;
    if (targetId) s += ` | Target ID: TARGET-${String(targetId).padStart(4, '0')}`;
    if (moduleName) s += ` | Module: ${moduleName}`;
    if (period) s += ` | Period: ${period}`;
    if (targetDate) s += ` | Date: ${targetDate}`;
    if (targetQty) s += ` | Target: ${targetQty}`;
    if (productionQty) s += ` | Current: ${productionQty}`;
    if (targetQty && productionQty) {
      const achievement = ((parseFloat(productionQty) / parseFloat(targetQty)) * 100).toFixed(1);
      s += ` | Achievement: ${achievement}%`;
    }
    if (remarks) s += ` | Remarks: ${remarks}`;
    document.getElementById('summaryBox').innerText = s;
    document.getElementById('summary').value = s;
  } else {
    document.getElementById('summaryBox').innerText = '';
    document.getElementById('summary').value = '';
  }
}

// Clear form
function clearForm(){
  document.querySelectorAll('#moduleGroup .btn, #periodGroup .btn').forEach(b=>b.classList.remove('selected'));
  document.getElementById("module_id").value="";
  document.getElementById("target_period").value="";
  document.getElementById('summaryBox').innerText = '';
  document.getElementById('summary').value = '';
  setTimeout(updateSummary, 100);
}

// Validate form
function validateForm(){
  if(!document.getElementById("module_id").value){
    alert("Please select a module/department."); 
    return false;
  }
  if(!document.getElementById("target_period").value){
    alert("Please select a target period."); 
    return false;
  }
  if(!document.getElementById("target_date").value){
    alert("Please select a target date."); 
    return false;
  }
  if(!document.getElementById("target_qty").value || parseFloat(document.getElementById("target_qty").value) <= 0){
    alert("Please enter a valid target quantity."); 
    return false;
  }
  return true;
}

// Add event listeners
document.addEventListener('DOMContentLoaded', function(){
  setTimeout(updateSummary, 100);
});
</script>
</body>
</html>



