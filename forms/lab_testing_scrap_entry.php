<?php
session_start();
// Optional auto-reload for development; ignore if not present
if (file_exists(__DIR__ . '/../dev/auto_reload.php')) {
    include_once(__DIR__ . '/../dev/auto_reload.php');
}
require_once 'security_config.php';

// Prevent browser caching to ensure fresh data after form submission
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

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

// Role-based access control for QC module
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_QC, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access the QC module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// DB connection
$conn = SecurityConfig::getConnection();

// Generate Lab Scrap ID with 8 AM daily reset
$dhaka_tz = new DateTimeZone('Asia/Dhaka');
$now = new DateTime('now', $dhaka_tz);
$current_hour = (int)$now->format('H');

// Determine reset date (8 AM cutoff)
$reset_date = clone $now;
if ($current_hour < 8) {
    $reset_date->modify('-1 day');
}
$reset_date->setTime(8, 0, 0);
$reset_timestamp = $reset_date->format('Y-m-d H:i:s');
$date_part = $reset_date->format('Ymd');

$scrap_prefix = 'LS-' . $date_part . '-';
$next_scrap_number = 1;

// Ensure created_at column exists
$conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

// Get max number for lab scrap entries created since 8 AM reset
$checkCol = $conn->query("SHOW COLUMNS FROM scrap LIKE 'created_at'");
if ($checkCol && $checkCol->num_rows > 0) {
    $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(scrap_id, -3) AS UNSIGNED)) as last_num 
                            FROM scrap 
                            WHERE scrap_id LIKE ? 
                            AND created_at >= ?");
    if ($stmt) {
        $scrap_pattern = $scrap_prefix . '%';
        $stmt->bind_param('ss', $scrap_pattern, $reset_timestamp);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if ($row['last_num']) {
                $next_scrap_number = $row['last_num'] + 1;
            }
        }
        $stmt->close();
    }
} else {
    // Fallback if created_at doesn't exist yet
    $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(scrap_id, -3) AS UNSIGNED)) as last_num 
                            FROM scrap 
                            WHERE scrap_id LIKE ?");
    if ($stmt) {
        $scrap_pattern = $scrap_prefix . '%';
        $stmt->bind_param('s', $scrap_pattern);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            if ($row['last_num']) {
                $next_scrap_number = $row['last_num'] + 1;
            }
        }
        $stmt->close();
    }
}

$pre_scrap_id = $scrap_prefix . str_pad((string)$next_scrap_number, 3, '0', STR_PAD_LEFT);

// Fetch reference numbers from fiber_to_roll_entry (exclude already submitted lab scrap)
$references = [];
$refQuery = $conn->query("SELECT DISTINCT f.reference_number 
    FROM fiber_to_roll_entry f
    WHERE f.reference_number IS NOT NULL 
    AND f.reference_number NOT IN (
        SELECT s.reference_number FROM scrap s 
        WHERE s.scrap_product = 'Lab Testing' 
        AND s.is_deleted = 0 
        AND s.reference_number IS NOT NULL
    )
    ORDER BY f.date_time DESC LIMIT 50");
if ($refQuery) {
    while ($row = $refQuery->fetch_assoc()) {
        $references[] = $row;
    }
}

// Fetch CNC batches
$cncBatches = [];
$cncQuery = $conn->query("SELECT DISTINCT cnc_cutting_batch FROM cnc_entries WHERE cnc_cutting_batch IS NOT NULL ORDER BY date_time DESC LIMIT 50");
if ($cncQuery) {
    while ($row = $cncQuery->fetch_assoc()) {
        $cncBatches[] = $row;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Lab Testing Scrap Entry</title>
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
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .alert { padding:12px; border-radius:6px; margin-bottom:20px; }
  .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
  .alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
</style>
</head>
<body>
<div class="container">
  
  <h1>Lab Testing Scrap Entry</h1>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">
      <strong>✓ Success:</strong> <?php echo htmlspecialchars($_GET['success']); ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error">
      <strong>✗ Error:</strong> <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <!-- Back to Dashboard Link -->
  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#3498db; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
  </div>

  <form id="labScrapForm" method="post" action="../handlers/submit_lab_scrap_entry.php" onsubmit="return validateForm();">

    <!-- Hidden datetime + shift -->
    <input type="hidden" id="dateTime" name="date_time">
    <input type="hidden" id="shift" name="shift">

    <!-- Lab Scrap ID -->
    <div class="form-group">
      <label>Lab Scrap ID:</label>
      <input type="text" value="<?php echo htmlspecialchars($pre_scrap_id); ?>" readonly class="readonly">
      <input type="hidden" name="scrap_id" value="<?php echo htmlspecialchars($pre_scrap_id); ?>">
    </div>

    <!-- Reference Number -->
    <div class="form-group">
      <label>Reference Number:</label>
      <select id="reference_number" name="reference_number" required>
        <option value="">-- Select Reference --</option>
        <?php foreach($references as $ref): ?>
          <option value="<?php echo htmlspecialchars($ref['reference_number']); ?>">
            <?php echo htmlspecialchars($ref['reference_number']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- CNC Cutting Batch (optional) -->
    <div class="form-group">
      <label>CNC Cutting Batch:</label>
      <select id="cutting_batch" name="cutting_batch">
        <option value="">-- Select CNC Batch (Optional) --</option>
        <?php foreach($cncBatches as $batch): ?>
          <option value="<?php echo htmlspecialchars($batch['cnc_cutting_batch']); ?>">
            <?php echo htmlspecialchars($batch['cnc_cutting_batch']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Weight (kg) - REQUIRED -->
    <div class="form-group">
      <label>Weight (kg): <span style="color: #e74c3c;">*</span></label>
      <input type="number" id="weight" name="weight" step="0.01" min="0.01" required placeholder="Enter weight in kg" oninput="updateSummary()">
    </div>

    <!-- Scrap Type -->
    <div class="form-group">
      <label>Scrap Type:</label>
      <select id="scrap_type" name="scrap_type" required onchange="updateSummary()">
        <option value="">-- Select Scrap Type --</option>
        <option value="Testing Sample">Testing Sample</option>
        <option value="Failed Test">Failed Test</option>
        <option value="Trimming">Trimming</option>
        <option value="Edge Waste">Edge Waste</option>
        <option value="Damaged Material">Damaged Material</option>
      </select>
    </div>

    <!-- Test Inspector -->
    <div class="form-group">
      <label>Test Inspector:</label>
      <input type="text" id="inspector" name="inspector" value="<?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?>" readonly class="readonly">
    </div>

    <!-- Send to Recycle -->
    <div class="form-group">
      <label>Send to Recycle:</label>
      <div style="display: flex; align-items: center; gap: 15px;">
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 10px 20px; border: 2px solid #2ecc71; border-radius: 6px; background: #d4edda;" id="recycleYesLabel">
          <input type="radio" name="send_to_recycle" value="1" id="recycleYes" checked onchange="updateRecycleStyle()">
          <span style="font-weight: 600; color: #155724;">✓ Yes, Send to Recycle</span>
        </label>
        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 10px 20px; border: 2px solid #ccc; border-radius: 6px; background: #f8f9fa;" id="recycleNoLabel">
          <input type="radio" name="send_to_recycle" value="0" id="recycleNo" onchange="updateRecycleStyle()">
          <span style="font-weight: 600; color: #6c757d;">✗ No, Do Not Recycle</span>
        </label>
      </div>
      <small style="color: #6c757d; margin-top: 5px; display: block;">Choose whether this lab scrap should appear in the Recycle Entry list</small>
    </div>

    <!-- Remarks -->
    <div class="form-group">
      <label>Remarks:</label>
      <textarea id="remarks" name="remarks" rows="3" placeholder="Any additional notes about the lab testing scrap..." oninput="updateSummary()"></textarea>
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
  const shift = (h >= 8 && h < 20) ? "Day" : "Night";
  document.getElementById("shiftBanner").innerText = "Shift: " + shift;
  document.getElementById("shift").value = shift;
}

function validateForm(){
  if(!document.getElementById("reference_number").value){
    alert("Please select a reference number."); 
    return false;
  }
  
  const weight = parseFloat(document.getElementById("weight").value);
  if(!weight || weight <= 0){
    alert("Please enter a valid weight (must be greater than 0)."); 
    return false;
  }
  
  if(!document.getElementById("scrap_type").value){
    alert("Please select a scrap type."); 
    return false;
  }
  
  return true;
}

function updateSummary() {
  const dateTime = document.getElementById("dateTime").value;
  const shift = document.getElementById("shift").value;
  const referenceNumber = document.getElementById("reference_number").value;
  const weight = document.getElementById("weight").value;
  const scrapType = document.getElementById("scrap_type").value;
  const inspector = document.getElementById("inspector").value;
  const sendToRecycle = document.getElementById("recycleYes").checked ? "Yes" : "No";
  
  if (dateTime && shift && referenceNumber && weight && scrapType) {
    let summaryText = `${dateTime} | Shift: ${shift} | Reference: ${referenceNumber} | Weight: ${weight} kg | Type: ${scrapType} | Recycle: ${sendToRecycle} | Inspector: ${inspector}`;
    
    document.getElementById("summaryBox").textContent = summaryText;
    document.getElementById("summary").value = summaryText;
  } else {
    document.getElementById("summaryBox").textContent = "Please fill all required fields to see summary";
    document.getElementById("summary").value = "";
  }
}

function clearForm() {
  document.getElementById("labScrapForm").reset();
  document.getElementById("summaryBox").textContent = "Please fill all required fields to see summary";
  document.getElementById("summary").value = "";
  updateTimeAndShift();
  updateRecycleStyle();
}

function updateRecycleStyle() {
  const yesLabel = document.getElementById("recycleYesLabel");
  const noLabel = document.getElementById("recycleNoLabel");
  const yesRadio = document.getElementById("recycleYes");
  
  if (yesRadio.checked) {
    yesLabel.style.borderColor = "#2ecc71";
    yesLabel.style.background = "#d4edda";
    yesLabel.querySelector("span").style.color = "#155724";
    noLabel.style.borderColor = "#ccc";
    noLabel.style.background = "#f8f9fa";
    noLabel.querySelector("span").style.color = "#6c757d";
  } else {
    noLabel.style.borderColor = "#e74c3c";
    noLabel.style.background = "#f8d7da";
    noLabel.querySelector("span").style.color = "#721c24";
    yesLabel.style.borderColor = "#ccc";
    yesLabel.style.background = "#f8f9fa";
    yesLabel.querySelector("span").style.color = "#6c757d";
  }
  updateSummary();
}

// Initialize
updateTimeAndShift();
setInterval(updateTimeAndShift, 1000);

// Add event listeners for real-time summary updates
document.addEventListener('DOMContentLoaded', function() {
  const refSelect = document.getElementById("reference_number");
  const weightInput = document.getElementById("weight");
  const scrapTypeSelect = document.getElementById("scrap_type");
  
  if (refSelect) refSelect.addEventListener('change', updateSummary);
  if (weightInput) weightInput.addEventListener('input', updateSummary);
  if (scrapTypeSelect) scrapTypeSelect.addEventListener('change', updateSummary);
  
  updateSummary();
  
  // Clean URL after showing success/error message (removes query params)
  if (window.location.search.includes('success') || window.location.search.includes('error')) {
    setTimeout(function() {
      window.history.replaceState({}, document.title, window.location.pathname);
    }, 3000);
  }
});
</script>
</body>
</html>


