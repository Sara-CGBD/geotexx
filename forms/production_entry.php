<?php


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

date_default_timezone_set('Asia/Dhaka');

// DB connection
$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

// Fetch projects
$projects = [];
$res = $conn->query("SELECT id, project_name FROM projects ORDER BY project_name ASC");
if ($res) while ($r = $res->fetch_assoc()) $projects[] = $r;

// Auto-generate next roll and batch numbers from DB schema
$next_roll = 1; 
$next_batch = 1;

// Determine DB name
$dbRow = $conn->query("SELECT DATABASE() AS d")->fetch_assoc();
$dbName = $dbRow ? $dbRow['d'] : 'geobagg';

// Find roll source: prefer roll_entry.roll_number, else production_entry.roll_number/roll_no
$rollCol = null; $rollTable = null;
$colRes = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='" . $conn->real_escape_string($dbName) . "' AND TABLE_NAME='roll_entry'");
if ($colRes) {
  $cols = [];
  while ($cr = $colRes->fetch_assoc()) { $cols[] = $cr['COLUMN_NAME']; }
  if (in_array('roll_number', $cols)) { $rollTable = 'roll_entry'; $rollCol = 'roll_number'; }
}
if (!$rollCol) {
  $colRes2 = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='" . $conn->real_escape_string($dbName) . "' AND TABLE_NAME='production_entry'");
  if ($colRes2) {
    $cols2 = [];
    while ($cr = $colRes2->fetch_assoc()) { $cols2[] = $cr['COLUMN_NAME']; }
    if (in_array('roll_number', $cols2)) { $rollTable = 'production_entry'; $rollCol = 'roll_number'; }
    elseif (in_array('roll_no', $cols2)) { $rollTable = 'production_entry'; $rollCol = 'roll_no'; }
  }
}
if ($rollCol && $rollTable) {
  $q = $conn->query("SELECT MAX(`$rollCol`) AS m FROM `$rollTable`");
  if ($q && ($row = $q->fetch_assoc())) { $next_roll = ((int)($row['m'] ?? 0)) + 1; }
}

// Batch number: try roll_entry.batch_number then production_entry.batch_number
$batchCol = null; $batchTable = null;
$bColRes = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='" . $conn->real_escape_string($dbName) . "' AND TABLE_NAME='roll_entry'");
if ($bColRes) {
  $bcols = [];
  while ($cr = $bColRes->fetch_assoc()) { $bcols[] = $cr['COLUMN_NAME']; }
  if (in_array('batch_number', $bcols)) { $batchTable = 'roll_entry'; $batchCol = 'batch_number'; }
}
if (!$batchCol) {
  $bColRes2 = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='" . $conn->real_escape_string($dbName) . "' AND TABLE_NAME='production_entry'");
  if ($bColRes2) {
    $bcols2 = [];
    while ($cr = $bColRes2->fetch_assoc()) { $bcols2[] = $cr['COLUMN_NAME']; }
    if (in_array('batch_number', $bcols2)) { $batchTable = 'production_entry'; $batchCol = 'batch_number'; }
  }
}
if ($batchCol && $batchTable) {
  $qb = $conn->query("SELECT MAX(`$batchCol`) AS mb FROM `$batchTable`");
  if ($qb && ($row = $qb->fetch_assoc())) { $next_batch = ((int)($row['mb'] ?? 0)) + 1; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Production Entry</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
  .container { max-width:900px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);}
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
</style>
</head>
<body>
<div class="container">
  
  <h1>Production Entry</h1>

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
      â† Back to Dashboard
    </a>
  </div>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <form id="productionForm" method="post" action="../handlers/submit_production_entry.php" onsubmit="return validateForm();">

    <!-- Production ID -->
    <div class="form-group">
      <label>Production ID </label>
      <input type="text" value="Auto (DB generated)" readonly class="readonly">
    </div>

    <!-- Hidden datetime + shift -->
    <input type="hidden" id="dateTime" name="date_time">
    <input type="hidden" id="shift" name="shift">

    <!-- Operator -->
    <div class="form-group">
      <label>Operator (Auto from session)</label>
      <input type="text" value="<?php echo htmlspecialchars($_SESSION['username']); ?>" readonly class="readonly">
      <input type="hidden" name="operator_id" value="<?php echo $_SESSION['user_id']; ?>">
    </div>

    <!-- Project -->
    <div class="form-group">
      <label>Project Name </label>
      <select id="project_id" name="project_id" required>
        <option value="">-- Select Project --</option>
        <?php foreach($projects as $p): ?>
          <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['project_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- GSM -->
    <div class="form-group">
      <label>GSM </label>
      <input type="number" id="gsm" name="gsm" required min="1">
    </div>

    <!-- Line no -->
    <div class="form-group">
      <label>Line Number </label>
      <input type="text" id="line_no" name="line_no" required>
    </div>

    <!-- Fiber type -->
    <div class="form-group">
      <label>Fiber Type </label>
      <input type="text" id="fiber_type" name="fiber_type" required>
    </div>

    <!-- Roll no -->
    <div class="form-group">
      <label>Roll Number </label>
      <input type="text" value="<?php echo $next_roll; ?>" readonly class="readonly">
      <input type="hidden" name="roll_no" value="<?php echo $next_roll; ?>">
    </div>

    <!-- Total weight -->
    <div class="form-group">
      <label>Total Weight </label>
      <input type="number" step="0.01" id="total_weight" name="total_weight" required min="0.01">
    </div>

    <!-- Batch no -->
    <div class="form-group">
      <label>Batch Number </label>
      <input type="text" value="<?php echo $next_batch; ?>" readonly class="readonly">
      <input type="hidden" name="batch_number" value="<?php echo $next_batch; ?>">
    </div>

    <div class="actions">
      <button type="submit" class="submit-btn">Submit</button>
      <button type="reset" class="clear-btn">Clear</button>
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
  const m = dhaka.getMinutes();
  const timeInMinutes = h * 60 + m;
  // Day shift: 8am-7:59pm (8-19), Night shift: 8pm-7:59am (20-7)
  const shift = (h >= 8 && h <= 19) ? "Day" : "Night";
  document.getElementById("shiftBanner").innerText = "Shift: " + shift;
  document.getElementById("shift").value = shift;
}
setInterval(updateTimeAndShift,1000); updateTimeAndShift();

function validateForm(){
  if(!document.getElementById("project_id").value){
    alert("Select a project.");
    return false;
  }
  return true;
}
</script>
</body>
</html>


