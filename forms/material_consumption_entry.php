<?php
session_start();
require_once '../config/security_config.php';
require_once '../config/project_helper.php';

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

// Role-based access control for Material Consumption
// Allowed roles: Admin, Production User, Planning User, AGM Ops
$allowed_roles = ['admin', 'production_user', 'planning_user', 'agm ops', 'agm operations'];
$user_role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>ðŸš« Access Denied</h2>
        <p>You do not have permission to access Material Consumption Entry.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <p>Allowed roles: Admin, Production User, Planning User, AGM Operations</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

// Check if user is view-only (AGM Ops can only view)
$is_view_only = ($user_role === 'agm ops' || $user_role === 'agm operations');

date_default_timezone_set('Asia/Dhaka');

// DB connection
$conn = SecurityConfig::getConnection();

// Get next consumption ID
$next_id = 1;
$res = $conn->query("SELECT MAX(id) AS cid FROM material_consumption");
if ($res && ($row = $res->fetch_assoc())) {
    $next_id = ($row['cid'] ?? 0) + 1;
}

// Only PP Stable Fiber material
$material_name = 'PP Stable Fiber';

// Manufacturer Names (same as store received entry)
$manufacturerNames = [
    'Natpet',
    'APT',
    'Texofib',
    'Hubei Botao',
    'Jiangsu Botao',
    'Taizhu Hailun',
    'PSF',
    'Other'
];

$defaultProject = getDefaultProject($conn);
$projects = $defaultProject ? [$defaultProject] : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Material Consumption Entry</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:0; color:#2c3e50; }
  .container { max-width:100%; margin:0; background:#fff; border-radius:0; padding:25px 80px; box-shadow:none;}
  h1 { text-align:center; font-size:28px; margin-bottom:30px; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  input[type="text"], input[type="number"], select, textarea {
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
</style>
</head>
<body>
<div class="container">
  
  <h1>Material Consumption Entry</h1>

  <?php if ($is_view_only): ?>
    <div style="background:#fff3cd; color:#856404; padding:15px; border-radius:6px; margin-bottom:20px; border:1px solid #ffc107;">
      <strong>â„¹ï¸ View Only Mode:</strong> You can view this form but cannot submit new entries. Your role: <?php echo htmlspecialchars($_SESSION['role']); ?>
    </div>
  <?php endif; ?>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <?php if (isset($_GET['error'])): ?>
    <div style="background:#fee; color:#c33; padding:10px; border-radius:6px; margin-bottom:20px; border:1px solid #fcc;">
      <strong>Error:</strong> <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['success'])): ?>
    <div style="background:#efe; color:#3c3; padding:10px; border-radius:6px; margin-bottom:20px; border:1px solid #cfc;">
      <strong>Success:</strong> <?php echo htmlspecialchars($_GET['success']); ?>
    </div>
  <?php endif; ?>

  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      Back to Dashboard
    </a>
  </div>

  <form id="consumptionForm" method="post" action="../handlers/submit_material_consumption.php" onsubmit="return validateForm();">

    <!-- Hidden datetime + shift -->
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">

    <!-- Consumption ID -->
    <div class="form-group">
      <label>Consumption ID: </label>
      <input type="text" value="MC-<?php echo str_pad($next_id, 4, '0', STR_PAD_LEFT); ?>" readonly class="readonly">
      <input type="hidden" name="consumption_id" value="<?php echo $next_id; ?>">
    </div>

    <!-- Material Name (Fixed: PP Stable Fiber) -->
    <div class="form-group">
      <label>Material Name: </label>
      <input type="text" value="<?php echo htmlspecialchars($material_name); ?>" readonly class="readonly">
      <input type="hidden" name="material_name" id="material_name" value="<?php echo htmlspecialchars($material_name); ?>">
      <input type="hidden" name="material_id" id="material_id" value="0">
    </div>

    <!-- Manufacturer Name -->
    <div class="form-group">
      <label>Manufacturer Name: <span style="color: red;">*</span></label>
      <select name="manufacturer_name" id="manufacturer_name" required onchange="updateSummary()">
        <option value="">-- Select Manufacturer --</option>
        <?php foreach ($manufacturerNames as $mfr): ?>
        <option value="<?php echo htmlspecialchars($mfr); ?>"><?php echo htmlspecialchars($mfr); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Consumption Type -->
    <div class="form-group">
      <label>Consumption Type: </label>
      <div class="btn-group" id="typeGroup">
        <button type="button" class="btn" data-value="Production" onclick="selectBtn(this,'typeGroup')">Production</button>
        <button type="button" class="btn" data-value="CNC Cutting" onclick="selectBtn(this,'typeGroup')">CNC Cutting</button>
        <button type="button" class="btn" data-value="Sewing" onclick="selectBtn(this,'typeGroup')">Sewing</button>
        <button type="button" class="btn" data-value="Branding" onclick="selectBtn(this,'typeGroup')">Branding</button>
        <button type="button" class="btn" data-value="Finishing" onclick="selectBtn(this,'typeGroup')">Finishing</button>
        <button type="button" class="btn" data-value="Quality Control" onclick="selectBtn(this,'typeGroup')">Quality Control</button>
        <button type="button" class="btn" data-value="Maintenance" onclick="selectBtn(this,'typeGroup')">Maintenance</button>
        <button type="button" class="btn" data-value="Other" onclick="selectBtn(this,'typeGroup')">Other</button>
      </div>
      <input type="hidden" name="consumption_type" id="consumption_type">
    </div>

    <!-- Project -->
    <div class="form-group">
      <label>Project (Optional): </label>
      <div class="btn-group" id="projectGroup">
        <?php foreach($projects as $index => $proj): ?>
        <button type="button" class="btn <?php echo $index === 0 ? 'selected' : ''; ?>" data-id="<?php echo $proj['id']; ?>" onclick="selectBtn(this,'projectGroup')">
          <?php echo htmlspecialchars($proj['project_name']); ?>
        </button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="project_id" id="project_id" value="<?php echo isset($projects[0]['id']) ? (int)$projects[0]['id'] : ''; ?>">
    </div>

    <!-- Quantity Consumed -->
    <div class="form-group">
      <label>Quantity Consumed: </label>
      <input type="number" name="quantity" id="quantity" min="0.01" step="0.01" required oninput="updateSummary()">
    </div>

    <!-- Unit -->
    <div class="form-group">
      <label>Unit: </label>
      <div class="btn-group" id="unitGroup">
        <button type="button" class="btn" data-value="kg" onclick="selectBtn(this,'unitGroup')">kg</button>
        <button type="button" class="btn" data-value="pcs" onclick="selectBtn(this,'unitGroup')">pcs</button>
        <button type="button" class="btn" data-value="meters" onclick="selectBtn(this,'unitGroup')">meters</button>
        <button type="button" class="btn" data-value="liters" onclick="selectBtn(this,'unitGroup')">liters</button>
      </div>
      <input type="hidden" name="unit" id="unit">
    </div>

    <!-- Operator/User -->
    <div class="form-group">
      <label>Operator/User: </label>
      <input type="text" name="operator" id="operator" value="<?php echo htmlspecialchars($_SESSION['username']); ?>" readonly class="readonly">
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
      <?php if (!$is_view_only): ?>
        <button type="submit" class="submit-btn">Submit</button>
        <button type="reset" class="clear-btn" onclick="clearForm()">Clear</button>
      <?php else: ?>
        <button type="button" class="submit-btn" disabled style="opacity: 0.5; cursor: not-allowed;">Submit (View Only)</button>
      Back to Dashboard
      <?php endif; ?>
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
    updateSummary();
}
setInterval(updateTimeAndShift, 1000);
updateTimeAndShift();

// Material is fixed, no selection needed

function selectBtn(btn, groupId){
  document.querySelectorAll(`#${groupId} .btn`).forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  
  if(groupId === "typeGroup"){
    document.getElementById("consumption_type").value = btn.dataset.value;
  } else if(groupId === "unitGroup"){
    document.getElementById("unit").value = btn.dataset.value;
  } else if(groupId === "projectGroup"){
    document.getElementById("project_id").value = btn.dataset.id;
  }
  updateSummary();
}

function updateSummary(){
  const dateTime = document.getElementById('dateTime').value;
  const consumptionId = document.querySelector('input[name="consumption_id"]').value;
  const materialText = document.getElementById('material_name').value || '';
  const manufacturerName = document.getElementById('manufacturer_name').value.trim();
  const typeText = document.getElementById('consumption_type').value;
  const projectText = document.querySelector('#projectGroup .btn.selected')?.textContent?.trim() || '';
  const quantity = document.getElementById('quantity').value;
  const unit = document.getElementById('unit').value;
  const operator = document.getElementById('operator').value;
  const remarks = document.getElementById('remarks').value.trim();

  if (dateTime) {
    let s = `${dateTime}`;
    if (consumptionId) s += ` | ID: MC-${String(consumptionId).padStart(4, '0')}`;
    if (materialText) s += ` | Material: ${materialText}`;
    if (manufacturerName) s += ` | Manufacturer: ${manufacturerName}`;
    if (typeText) s += ` | Type: ${typeText}`;
    if (projectText) s += ` | Project: ${projectText}`;
    if (quantity) s += ` | Qty: ${quantity}`;
    if (unit) s += ` ${unit}`;
    if (operator) s += ` | By: ${operator}`;
    if (remarks) s += ` | Remarks: ${remarks}`;
    document.getElementById('summaryBox').innerText = s;
    document.getElementById('summary').value = s;
  } else {
    document.getElementById('summaryBox').innerText = '';
    document.getElementById('summary').value = '';
  }
}

function clearForm(){
  document.querySelectorAll('#typeGroup .btn, #unitGroup .btn, #projectGroup .btn').forEach(b=>b.classList.remove('selected'));
  document.getElementById("consumption_type").value="";
  document.getElementById("unit").value="";
  document.getElementById("project_id").value="";
  document.getElementById("manufacturer_name").value="";
  document.getElementById('summaryBox').innerText = '';
  document.getElementById('summary').value = '';
  setTimeout(updateSummary, 100);
}

function validateForm(){
  if(!document.getElementById("manufacturer_name").value || !document.getElementById("manufacturer_name").value.trim()){
    alert("Please select manufacturer name."); return false;
  }
  if(!document.getElementById("consumption_type").value){
    alert("Please select a consumption type."); return false;
  }
  if(!document.getElementById("quantity").value || parseFloat(document.getElementById("quantity").value) <= 0){
    alert("Please enter a valid quantity."); return false;
  }
  if(!document.getElementById("unit").value){
    alert("Please select a unit."); return false;
  }
  return true;
}
</script>
</body>
</html>



