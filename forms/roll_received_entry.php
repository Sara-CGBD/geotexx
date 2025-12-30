<?php


session_start();
require_once 'security_config.php';
require_once '../config/project_helper.php';

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

// Role-based access control for Production module
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_PRODUCTION, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>Access Denied</h2>
        <p>You do not have permission to access the Production module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// Connect DB
$conn = SecurityConfig::getConnection();

// Fetch default project only
$projects = getDefaultProjectList($conn);

// Fetch all reference numbers from transferred rolls
$allReferenceNumbers = [];
$refQuery = "SELECT DISTINCT reference_number FROM roll_transfer WHERE reference_number IS NOT NULL ORDER BY created_at DESC";
$refResult = $conn->query($refQuery);
if ($refResult) {
    while ($row = $refResult->fetch_assoc()) {
        $allReferenceNumbers[] = $row['reference_number'];
    }
}

// Include routed roll references (bag production) from qc_test_orders
$routedQuery = "SELECT DISTINCT sample_reference_id AS reference_number 
                FROM qc_test_orders 
                WHERE roll_destination = 'bag_production' 
                  AND sample_reference_id IS NOT NULL 
                  AND sample_reference_id != ''";
$routedResult = $conn->query($routedQuery);
if ($routedResult) {
    while ($row = $routedResult->fetch_assoc()) {
        if (!in_array($row['reference_number'], $allReferenceNumbers, true)) {
            $allReferenceNumbers[] = $row['reference_number'];
        }
    }
}

// Fetch project-reference combinations that have already been received
$receivedCombinations = [];
$receivedQuery = "SELECT DISTINCT project_id, reference_number FROM roll_received WHERE reference_number IS NOT NULL";
$receivedResult = $conn->query($receivedQuery);
if ($receivedResult) {
    while ($row = $receivedResult->fetch_assoc()) {
        $key = $row['project_id'] . '_' . $row['reference_number'];
        $receivedCombinations[$key] = true;
    }
}

// Reporter (session user)
$reporter_id = $_SESSION['user_id'];
$reporter_name = $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Roll Received Entry</title>
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
  .btn-group {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 5px;
  }
  .btn-group .btn {
    padding: 8px 16px;
    border: 2px solid #e1e5e9;
    background: #fff;
    color: #333;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
  }
  .btn-group .btn:hover {
    border-color: #007bff;
    background: #f8f9ff;
  }
  .btn-group .btn.selected {
    background: #007bff;
    color: white;
    border-color: #007bff;
  }
</style>
</head>
<body>
<div class="container">
  
  <h1>Roll Received Entry</h1>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 10px; border: 1px solid #c3e6cb; border-radius: 4px; margin: 10px 0;">
      <?php echo htmlspecialchars($_GET['success']); ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger" style="background: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb; border-radius: 4px; margin: 10px 0;">
      <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <form id="receivedForm" method="post" action="../handlers/submit_roll_received_entry.php" onsubmit="return validateForm();">

    <!-- Hidden reporting datetime -->
    <input type="hidden" id="reporting_time" name="reporting_time">

    <!-- Receiver Name (Auto-filled from session) -->
    <div class="form-group">
      <label>Receiver Name:</label>
      <input type="text" value="<?php echo htmlspecialchars($reporter_name); ?>" readonly class="readonly">
      <input type="hidden" id="receiver_name" name="receiver_name" value="<?php echo htmlspecialchars($reporter_name); ?>">
      <input type="hidden" name="reporter_id" value="<?php echo $reporter_id; ?>">
    </div>

    <!-- Project -->
    <div class="form-group">
      <label>Project:</label>
      <div class="btn-group" id="projectGroup">
        <?php foreach($projects as $index => $p): ?>
        <button type="button" class="btn <?php echo $index === 0 ? 'selected' : ''; ?>" data-id="<?php echo $p['id']; ?>" onclick="selectBtn(this,'projectGroup')">
          <?php echo htmlspecialchars($p['project_name']); ?>
        </button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" id="project_id" name="project_id" value="<?php echo isset($projects[0]['id']) ? (int)$projects[0]['id'] : ''; ?>">
    </div>

    <!-- Reference Number (filtered by project) -->
    <div class="form-group">
      <label>Reference Number: </label>
      <select id="reference_number" name="reference_number" required>
        <option value="">-- Select a project first --</option>
      </select>
      <small style="color: #666; display: block; margin-top: 5px;">Select a project to see available reference numbers</small>
    </div>

    <!-- Summary Section (CNC-style) -->
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
// PHP data for JavaScript
const allReferenceNumbers = <?php echo json_encode($allReferenceNumbers); ?>;
const receivedCombinations = <?php echo json_encode($receivedCombinations); ?>;

console.log('All references:', allReferenceNumbers);
console.log('Received combinations:', receivedCombinations);

function updateTimeAndShift() {
  const now = new Date();
  const utc = now.getTime() + now.getTimezoneOffset()*60000;
  const dhaka = new Date(utc + 6*3600000);
  document.getElementById("dateTimeDisplay").innerHTML =
    "Reporting Time: " + dhaka.toDateString() + " " + dhaka.toLocaleTimeString();
  const yyyy = dhaka.getFullYear();
  const mm = String(dhaka.getMonth()+1).padStart(2,'0');
  const dd = String(dhaka.getDate()).padStart(2,'0');
  const hh = String(dhaka.getHours()).padStart(2,'0');
  const min = String(dhaka.getMinutes()).padStart(2,'0');
  const ss = String(dhaka.getSeconds()).padStart(2,'0');
  document.getElementById("reporting_time").value = `${yyyy}-${mm}-${dd} ${hh}:${min}:${ss}`;
  const h = dhaka.getHours();
  document.getElementById("shiftBanner").innerText = "Shift: " + ((h>=8&&h<20)?"Day":"Night");
  updateSummary();
}
setInterval(updateTimeAndShift,1000); updateTimeAndShift();

function selectBtn(btn, groupId){
  document.querySelectorAll(`#${groupId} .btn`).forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  if(groupId==="projectGroup"){
    const projectId = btn.dataset.id;
    document.getElementById("project_id").value = projectId;
    
    // Update reference number dropdown based on selected project
    updateReferenceDropdown(projectId);
  }
  updateSummary();
}

function updateReferenceDropdown(projectId) {
  const refSelect = document.getElementById('reference_number');
  refSelect.innerHTML = '<option value="">-- Select Reference Number --</option>';
  
  // Filter references that haven't been received for this project
  allReferenceNumbers.forEach(ref => {
    const key = projectId + '_' + ref;
    const alreadyReceived = receivedCombinations.hasOwnProperty(key);
    
    if (!alreadyReceived) {
      const option = document.createElement('option');
      option.value = ref;
      option.textContent = ref;
      refSelect.appendChild(option);
    }
  });
  
  // Reset selection
  refSelect.value = '';
  updateSummary();
  
  console.log('Updated reference dropdown for project:', projectId);
}

function updateSummary() {
  const dateTime = document.getElementById('reporting_time')?.value || '';
  const shiftBanner = document.getElementById('shiftBanner')?.innerText || '';
  const receiverName = document.getElementById('receiver_name')?.value || '';
  const projectName = document.querySelector('#projectGroup .btn.selected')?.innerText || '';
  const referenceNumber = document.getElementById('reference_number')?.value || '';
  
  console.log('updateSummary called:', { dateTime, shiftBanner, receiverName, projectName, referenceNumber });
  
  if (dateTime && receiverName && projectName && referenceNumber) {
    let summary = `${dateTime} | ${shiftBanner} | Receiver: ${receiverName} | Project: ${projectName} | Reference: ${referenceNumber}`;
    const summaryBox = document.getElementById('summaryBox');
    if (summaryBox) {
      summaryBox.innerText = summary;
      summaryBox.style.display = 'block';
    }
    const summaryInput = document.getElementById('summary');
    if (summaryInput) {
      summaryInput.value = summary;
    }
    console.log('Summary set:', summary);
  } else {
    const summaryBox = document.getElementById('summaryBox');
    if (summaryBox) {
      summaryBox.innerText = 'Please fill all fields to see summary';
      summaryBox.style.display = 'block';
    }
    const summaryInput = document.getElementById('summary');
    if (summaryInput) {
      summaryInput.value = '';
    }
  }
}

function oldUpdateBatchNumberDisplay() {
  const gsm = document.getElementById('gsm')?.value || '';
  const lineNo = document.getElementById('line_no')?.value || '';
  const fiberType = document.getElementById('fiber_type')?.value || '';
  const rollNumber = document.getElementById('roll_number')?.value || '';
  
  if (gsm && lineNo && fiberType && rollNumber) {
    const now = new Date();
    const utc = now.getTime() + now.getTimezoneOffset()*60000;
    const dhaka = new Date(utc + 6*3600000);
    
    // Determine ShiftDate based on time
    const hour = dhaka.getHours();
    let shiftDate = new Date(dhaka);
    
    if (hour < 8) {
      // Night shift belongs to previous day
      shiftDate.setDate(shiftDate.getDate() - 1);
    }
    
    // Format date as YYMonDD
    const year = String(shiftDate.getFullYear()).slice(-2);
    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = monthNames[shiftDate.getMonth()];
    const day = String(shiftDate.getDate()).padStart(2, '0');
    const dateStr = year + month + day;
    
    // Generate batch number preview with selected roll number
    const batchPreview = gsm + 'L' + lineNo + fiberType + dateStr + rollNumber;
    document.getElementById("batchNumberDisplay").value = batchPreview;
  } else {
    document.getElementById("batchNumberDisplay").value = "Auto-generated on submit";
  }
  updateSummary();
}

function validateForm(){
  if(!document.getElementById("project_id").value){
    alert("Select a project."); return false;
  }
  if(!document.getElementById("roll_number").value){
    alert("Select a roll number."); return false;
  }
  
  // Generate batch number before submission
  updateBatchNumberDisplay();
  const batchNumber = document.getElementById("batchNumberDisplay").value;
  if (batchNumber === "Auto-generated on submit") {
    alert("Please fill all required fields to generate batch number."); return false;
  }
  
  // Set the hidden batch number field
  document.getElementById("batchNumber").value = batchNumber;
  
  return true;
}

// Old summary updater - replaced
function oldUpdateSummaryBackup(){
  const dateTime = document.getElementById('reporting_time').value;
  const shiftText = document.getElementById('shiftBanner').innerText.replace('Shift: ','').trim();

  // Project name from selected button
  const selectedProjectBtn = document.querySelector('#projectGroup .btn.selected');
  const projectName = selectedProjectBtn ? selectedProjectBtn.textContent.trim() : '';

  const receiverName = document.getElementById('receiver_name').value;
  const bagRollType = document.getElementById('bag_roll_type').value;
  const rollSize = document.getElementById('roll_size').value;
  const rollQty = document.getElementById('roll_quantity').value;
  const gsm = document.getElementById('gsm').value;
  const lineNo = document.getElementById('line_no').value;
  const fiberType = document.getElementById('fiber_type').value;
  const rollNumber = document.getElementById('roll_number').value;
  const batchPreview = document.getElementById('batchNumberDisplay').value;

  if (dateTime && shiftText) {
    let summary = `${dateTime} | Shift: ${shiftText}`;
    if (receiverName) summary += ` | Receiver: ${receiverName}`;
    if (projectName) summary += ` | Project: ${projectName}`;
    if (rollNumber) summary += ` | Roll No: ${rollNumber}`;
    if (gsm) summary += ` | GSM: ${gsm}`;
    if (lineNo) summary += ` | Line: ${lineNo}`;
    if (fiberType) summary += ` | Fiber: ${fiberType}`;
    if (bagRollType) summary += ` | Bag Roll: ${bagRollType}`;
    if (rollSize) summary += ` | Size: ${rollSize}`;
    if (rollQty) summary += ` | Qty: ${rollQty}`;
    if (batchPreview && batchPreview !== 'Auto-generated on submit') summary += ` | Batch: ${batchPreview}`;

    document.getElementById('summaryBox').innerText = summary;
    document.getElementById('summary').value = summary;
  } else {
    document.getElementById('summaryBox').innerText = '';
    document.getElementById('summary').value = '';
  }
}

function clearForm(){
  // Clear all form fields
  document.getElementById("receivedForm").reset();
  
  // Clear button selections
  const projectButtons = document.querySelectorAll('#projectGroup .btn');
  projectButtons.forEach(b=>b.classList.remove('selected'));
  if (projectButtons.length > 0) {
    projectButtons[0].classList.add('selected');
    const defaultProjectId = projectButtons[0].dataset.id || '';
    document.getElementById("project_id").value = defaultProjectId;
    updateReferenceDropdown(defaultProjectId);
  } else {
    document.getElementById("project_id").value="";
  }
  
  // Reset batch number display
  document.getElementById("batchNumberDisplay").value = "Auto-generated on submit";
  document.getElementById("batchNumber").value = "";
  // Clear summary
  document.getElementById('summaryBox').innerText = '';
  document.getElementById('summary').value = '';
}

// Add event listeners for summary updates
const receiverNameInput = document.getElementById('receiver_name');
const referenceNumberSelect = document.getElementById('reference_number');

if (receiverNameInput) {
  receiverNameInput.addEventListener('input', updateSummary);
}
if (referenceNumberSelect) {
  referenceNumberSelect.addEventListener('change', updateSummary);
}

// Initial summary
updateSummary();

// Initialize references for default project on load
document.addEventListener('DOMContentLoaded', () => {
  const defaultProjectBtn = document.querySelector('#projectGroup .btn.selected');
  if (defaultProjectBtn) {
    const projectId = defaultProjectBtn.dataset.id || '';
    document.getElementById("project_id").value = projectId;
    updateReferenceDropdown(projectId);
  }
});

console.log('Roll Received Entry form initialized');
</script>
</body>
</html>



