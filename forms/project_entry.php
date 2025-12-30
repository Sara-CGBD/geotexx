<?php


session_start();
require_once 'security_config.php';

// Security/session checks
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

// Role-based access control for Project Entry
// Only Admin and AGM Ops can create/edit projects
$allowed_roles = ['admin', 'agm ops', 'agm operations'];
$user_role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>Access Denied</h2>
        <p>You do not have permission to access Project Entry.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <p>Allowed roles: Admin, AGM Operations</p>
        <p><strong>Reason:</strong> Only AGM Operations and Admin can create, edit, and approve projects.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// DB connection (shared config; root/no password)
$conn = SecurityConfig::getConnection();

// Next project id
$next_project_id = 1;
$res = $conn->query("SELECT MAX(id) AS pid FROM projects");
if ($res && ($row = $res->fetch_assoc())) {
    $next_project_id = ($row['pid'] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Project Entry</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:0; color:#2c3e50; }
  .container { max-width:100%; margin:0; background:#fff; border-radius:0; padding:25px 80px; box-shadow:none;}
  h1 { text-align:center; font-size:28px; margin-bottom:30px; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  input[type="text"], input[type="number"] {
    padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px);
  }
  .readonly { background:#ecf0f1; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
</style>
</head>
<body>
<div class="container">
  
  <h1>Project Entry</h1>

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

  <!-- Back to Dashboard Link -->
  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      <- Back to Dashboard
    </a>
  </div>

  <form id="projectForm" method="post" action="../handlers/submit_project_entry.php" onsubmit="return validateForm();">

    <!-- Hidden datetime + shift -->
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">

    <!-- Project ID -->
    <div class="form-group">
      <label>Project ID: </label>
      <input type="text" value="<?php echo $next_project_id; ?>" readonly class="readonly">
      <input type="hidden" name="project_id" value="<?php echo $next_project_id; ?>">
    </div>

    <!-- Project Name -->
    <div class="form-group">
      <label>Project Name: </label>
      <input type="text" name="project_name" id="project_name" required oninput="updateSummary()">
    </div>

    <!-- Project Value -->
    <div class="form-group">
      <label>Project Value (BDT):</label>
      <input type="number" name="project_value" id="project_value" required min="0" step="0.01" oninput="updateSummary()">
    </div>

    <!-- Produced Quantity -->
    <div class="form-group">
      <label>Produced Quantity: </label>
      <input type="number" name="produced_qty" id="produced_qty" required min="1" oninput="updateSummary()">
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
    updateSummary();
}
setInterval(updateTimeAndShift, 1000);
updateTimeAndShift();

function updateSummary(){
  const dateTime = document.getElementById('dateTime').value;
  const projectId = document.querySelector('input[name="project_id"]').value;
  const projectName = document.getElementById('project_name').value;
  const projectValue = document.getElementById('project_value').value;
  const producedQty = document.getElementById('produced_qty').value;

  if (dateTime) {
    let s = `${dateTime}`;
    if (projectId) s += ` | Project ID: ${projectId}`;
    if (projectName) s += ` | Name: ${projectName}`;
    if (projectValue) s += ` | Value: ${projectValue} BDT`;
    if (producedQty) s += ` | Quantity: ${producedQty}`;
    document.getElementById('summaryBox').innerText = s;
    document.getElementById('summary').value = s;
  } else {
    document.getElementById('summaryBox').innerText = '';
    document.getElementById('summary').value = '';
  }
}

function clearForm(){
  document.getElementById('summaryBox').innerText = '';
  document.getElementById('summary').value = '';
  setTimeout(updateSummary, 100);
}

function validateForm(){
  if(document.getElementById("project_name").value.trim() === ""){
    alert("Project name is required."); return false;
  }
  if(document.getElementById("project_value").value <= 0){
    alert("Project value must be greater than 0."); return false;
  }
  if(document.getElementById("produced_qty").value <= 0){
    alert("Produced quantity must be greater than 0."); return false;
  }
  return true;
}
</script>
</body>
</html>


