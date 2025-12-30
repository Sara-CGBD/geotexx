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

date_default_timezone_set('Asia/Dhaka');

// Connect DB
$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

// Fetch projects
$projects = [];
$res = $conn->query("SELECT id, project_name FROM projects");
if ($res) {
    while ($r = $res->fetch_assoc()) $projects[] = $r;
} else {
    die("Failed to fetch projects: " . $conn->error);
}

// Next roll number
$next_roll = 1;
$r = $conn->query("SELECT MAX(roll_number) AS r FROM roll_entry");
if ($r && ($row = $r->fetch_assoc())) {
    $next_roll = ($row['r'] ?? 0) + 1;
} else if (!$r) {
    die("Failed to fetch roll number: " . $conn->error);
}
// Next batch number
$next_batch = 1;
$b = $conn->query("SELECT MAX(batch_number) AS b FROM roll_entry");
if ($b && ($row = $b->fetch_assoc())) {
    $next_batch = ($row['b'] ?? 0) + 1;
} else if (!$b) {
    die("Failed to fetch batch number: " . $conn->error);
}

// Operator from session
$operator_id = $_SESSION['user_id'];
$operator_name = $_SESSION['username'];

$pageTitle = "Roll Entry";
include '../includes/iframe_form_header.php';
?>

<h1><i class="fas fa-dolly-flatbed"></i> Roll Entry</h1>

<div id="dateTimeDisplay" class="summary-info"></div>
<div id="shiftBanner" class="summary-info"></div>

<form id="rollForm" method="post" action="../handlers/submit_roll_entry.php" onsubmit="return validateForm();">

<!-- Roll Number (Auto) -->
<div class="form-group">
<label>Roll Number:</label>
<input type="number" id="rollNumber" name="rollNumber" value="<?php echo $next_roll; ?>" readonly>
</div>

<!-- Batch Number (Auto) -->
<div class="form-group">
<label>Batch Number:</label>
<input type="number" id="batchNumber" name="batchNumber" value="<?php echo $next_batch; ?>" readonly>
</div>

<!-- Project Selection -->
<div class="form-group">
<label>Project:</label>
<div id="projectGroup" class="btn-group">
<?php foreach ($projects as $p): ?>
<button type="button" class="btn" data-id="<?php echo $p['id']; ?>" onclick="selectProject(this, 'projectGroup')">
<?php echo htmlspecialchars($p['project_name']); ?>
</button>
<?php endforeach; ?>
</div>
<input type="hidden" id="project_id" name="project_id" required>
</div>

<!-- Weight -->
<div class="form-group">
<label>Weight (kg):</label>
<input type="number" id="weight" name="weight" step="0.01" required>
</div>

<!-- Length -->
<div class="form-group">
<label>Length (m):</label>
<input type="number" id="length" name="length" step="0.01" required>
</div>

<!-- Width -->
<div class="form-group">
<label>Width (m):</label>
<input type="number" id="width" name="width" step="0.01" required>
</div>

<!-- Quality -->
<div class="form-group">
<label>Quality:</label>
<select id="quality" name="quality" required>
<option value="">Select Quality</option>
<option value="A">A Grade</option>
<option value="B">B Grade</option>
<option value="C">C Grade</option>
</select>
</div>

<!-- Operator (Auto from session) -->
<div class="form-group">
<label>Operator:</label>
<input type="text" id="operator" name="operator" value="<?php echo htmlspecialchars($operator_name); ?>" readonly>
<input type="hidden" id="operator_id" name="operator_id" value="<?php echo $operator_id; ?>">
</div>

<!-- Date & Time (Auto) -->
<div class="form-group">
<label>Date & Time:</label>
<input type="text" id="dateTime" name="dateTime" readonly>
</div>

<div class="actions">
<button type="submit" class="btn btn-success">
    <i class="fas fa-save"></i> Submit Roll Entry
</button>
<button type="button" class="btn btn-primary" onclick="clearForm()">
    <i class="fas fa-eraser"></i> Clear Form
</button>
</div>

</form>

<script>
function updateTime() {
    const now = new Date();
    const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
    const bangladeshTime = new Date(utc + (6 * 3600000));
    
    const options = {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    };
    
    const formattedTime = bangladeshTime.toLocaleString('en-US', options);
    document.getElementById('dateTimeDisplay').textContent = `Current Time: ${formattedTime}`;
    document.getElementById('dateTime').value = bangladeshTime.toISOString();
    
    // Determine shift
    const hour = bangladeshTime.getHours();
    let shift = '';
    if (hour >= 6 && hour < 14) shift = 'Morning Shift (6 AM - 2 PM)';
    else if (hour >= 14 && hour < 22) shift = 'Evening Shift (2 PM - 10 PM)';
    else shift = 'Night Shift (10 PM - 6 AM)';
    
    document.getElementById('shiftBanner').textContent = `Current Shift: ${shift}`;
}

function selectProject(btn, groupId) {
  document.querySelectorAll(`#${groupId} .btn`).forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  if(groupId==="projectGroup"){
    document.getElementById("project_id").value = btn.dataset.id;
  }
}
function clearForm(){
  document.querySelectorAll('#projectGroup .btn').forEach(b=>b.classList.remove('selected'));
  document.getElementById("project_id").value="";
}
function validateForm(){
  if(!document.getElementById("project_id").value){
    alert("Select a project."); return false;
  }
  return true;
}

// Initialize time on page load
updateTime();
</script>

<?php include '../includes/iframe_form_footer.php'; ?>


