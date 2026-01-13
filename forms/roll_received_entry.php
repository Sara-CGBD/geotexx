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

// Ensure trip column exists in roll_received table
$checkTripCol = $conn->query("SHOW COLUMNS FROM roll_received LIKE 'trip'");
if (!$checkTripCol || $checkTripCol->num_rows == 0) {
    $conn->query("ALTER TABLE roll_received ADD COLUMN trip INT DEFAULT NULL");
}

// Fetch default project only
$projects = getDefaultProjectList($conn);

// Fetch distinct trips from roll_transfer for dropdown
$trips = [];
$hasTripCol = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'trip'");
if ($hasTripCol && $hasTripCol->num_rows > 0) {
    $tripQuery = "SELECT DISTINCT trip, MAX(date_time) as last_date 
                  FROM roll_transfer 
                  WHERE trip IS NOT NULL 
                  GROUP BY trip 
                  ORDER BY trip DESC";
    $tripResult = $conn->query($tripQuery);
    if ($tripResult) {
        while ($row = $tripResult->fetch_assoc()) {
            $trips[] = [
                'trip' => (int)$row['trip'],
                'last_date' => $row['last_date']
            ];
        }
    }
}

// Fetch reference numbers with their trip, excluding those already received for the same trip
$refsByTrip = [];
// Check if trip column exists in roll_received
$hasReceivedTrip = false;
$checkReceivedTrip = $conn->query("SHOW COLUMNS FROM roll_received LIKE 'trip'");
if ($checkReceivedTrip && $checkReceivedTrip->num_rows > 0) {
    $hasReceivedTrip = true;
}

// Check if is_deleted column exists in roll_received
$hasReceivedIsDeleted = false;
$checkReceivedIsDeleted = $conn->query("SHOW COLUMNS FROM roll_received LIKE 'is_deleted'");
if ($checkReceivedIsDeleted && $checkReceivedIsDeleted->num_rows > 0) {
    $hasReceivedIsDeleted = true;
}

// Check if is_deleted column exists in roll_transfer  
$hasTransferIsDeleted = false;
$checkTransferIsDeleted = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'is_deleted'");
if ($checkTransferIsDeleted && $checkTransferIsDeleted->num_rows > 0) {
    $hasTransferIsDeleted = true;
}

$transferDeletedCondition = $hasTransferIsDeleted ? "AND (rt.is_deleted = 0 OR rt.is_deleted IS NULL)" : "";
$receivedDeletedCondition = $hasReceivedIsDeleted ? "AND (rr.is_deleted = 0 OR rr.is_deleted IS NULL)" : "";

// Build query based on whether trip column exists
if ($hasReceivedTrip) {
    // If trip column exists, exclude references received for the same trip only
    $refTripQuery = "SELECT DISTINCT rt.reference_number, rt.trip 
                     FROM roll_transfer rt
                     WHERE rt.reference_number IS NOT NULL 
                       AND rt.reference_number != ''
                       AND rt.trip IS NOT NULL
                       {$transferDeletedCondition}
                       AND NOT EXISTS (
                         SELECT 1 FROM roll_received rr 
                         WHERE rr.reference_number = rt.reference_number
                         AND rr.trip IS NOT NULL
                         AND rr.trip = rt.trip
                         {$receivedDeletedCondition}
                       )
                     ORDER BY rt.trip DESC, rt.date_time DESC";
} else {
    // If trip column doesn't exist, exclude all received references (backward compatibility)
    $refTripQuery = "SELECT DISTINCT rt.reference_number, rt.trip 
                     FROM roll_transfer rt
                     WHERE rt.reference_number IS NOT NULL 
                       AND rt.reference_number != ''
                       AND rt.trip IS NOT NULL
                       {$transferDeletedCondition}
                       AND NOT EXISTS (
                         SELECT 1 FROM roll_received rr 
                         WHERE rr.reference_number = rt.reference_number
                         {$receivedDeletedCondition}
                       )
                     ORDER BY rt.trip DESC, rt.date_time DESC";
}
$refTripResult = $conn->query($refTripQuery);
if (!$refTripResult) {
    error_log("Query failed in roll_received_entry.php: " . $conn->error);
    error_log("Query: " . $refTripQuery);
} else {
    $totalRows = 0;
    while ($row = $refTripResult->fetch_assoc()) {
        $totalRows++;
        $ref = trim($row['reference_number']);
        $trip = (int)$row['trip'];
        
        // Initialize trip array if not exists
        if (!isset($refsByTrip[$trip])) {
            $refsByTrip[$trip] = [];
        }
        
        // Add reference if not already present
        if (!in_array($ref, $refsByTrip[$trip], true)) {
            $refsByTrip[$trip][] = $ref;
        }
    }
    // Debug: log how many references were found
    error_log("roll_received_entry.php: Found $totalRows reference rows, grouped into " . count($refsByTrip) . " trips");
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
  .alert {
    padding: 12px;
    border-radius: 6px;
    margin-bottom: 20px;
  }
  .alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
  }
  .alert-danger {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
  }
  small.help-text {
    color: #666;
    display: block;
    margin-top: 5px;
    font-size: 13px;
  }
</style>
</head>
<body>
<div class="container">
  
  <h1>Roll Received Entry</h1>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">
      <?php echo htmlspecialchars($_GET['success']); ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger">
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

    <!-- Trip -->
    <div class="form-group">
      <label>Trip:</label>
      <select id="trip" name="trip" required onchange="handleTripChange()">
        <option value="">-- Select Trip --</option>
        <?php foreach($trips as $t): 
          $tripDate = '';
          if (!empty($t['last_date'])) {
            try {
              $dateObj = new DateTime($t['last_date']);
              $tripDate = ' (' . $dateObj->format('Y-m-d') . ')';
            } catch (Exception $e) {
              $tripDate = '';
            }
          }
        ?>
        <option value="<?php echo $t['trip']; ?>">Trip <?php echo $t['trip']; ?><?php echo $tripDate; ?></option>
        <?php endforeach; ?>
      </select>
      <small class="help-text">Select a trip to see reference numbers transferred in that trip</small>
    </div>

    <!-- Reference Number (filtered by project and trip) -->
    <div class="form-group">
      <label>Reference Number:</label>
      <select id="reference_number" name="reference_number" required onchange="handleReferenceChange()">
        <option value="">-- Select a trip first --</option>
      </select>
      <input type="hidden" id="bundle_refs" name="bundle_refs" value="">
      <small class="help-text" id="refHelp">Select a trip to see available reference numbers</small>
    </div>

    <!-- Summary Section -->
    <div class="form-group">
      <div id="summaryBox" class="summary-info">Please fill all fields to see summary</div>
      <input type="hidden" id="summary" name="summary">
    </div>

    <div class="actions">
      <button type="submit" class="submit-btn">Submit</button>
      <button type="button" class="clear-btn" onclick="clearForm()">Clear</button>
    </div>
  </form>
</div>

<script>
// PHP data for JavaScript - pass as simple object
const refsByTrip = <?php echo json_encode($refsByTrip); ?>;
const receivedCombinations = <?php echo json_encode($receivedCombinations); ?>;

// Debug: Log the structure
console.log('=== REFS BY TRIP DEBUG ===');
console.log('refsByTrip:', refsByTrip);
console.log('Type:', typeof refsByTrip);
console.log('Keys:', Object.keys(refsByTrip));
console.log('refsByTrip[1]:', refsByTrip[1]);
console.log('refsByTrip["1"]:', refsByTrip["1"]);
console.log('refsByTrip[2]:', refsByTrip[2]);
console.log('refsByTrip["2"]:', refsByTrip["2"]);

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
}
setInterval(updateTimeAndShift,1000); 
updateTimeAndShift();

function selectBtn(btn, groupId){
  document.querySelectorAll(`#${groupId} .btn`).forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  if(groupId==="projectGroup"){
    const projectId = btn.dataset.id;
    document.getElementById("project_id").value = projectId;
    
    // Update reference number dropdown based on selected project and trip
    const selectedTrip = document.getElementById('trip').value;
    if (selectedTrip) {
      handleTripChange();
    }
  }
  updateSummary();
}

function handleTripChange() {
  const tripSelect = document.getElementById('trip');
  const projectId = document.getElementById('project_id').value;
  const selectedTrip = tripSelect.value;
  
  const refSelect = document.getElementById('reference_number');
  const refHelp = document.getElementById('refHelp');
  
  // Clear dropdown
  refSelect.innerHTML = '<option value="">-- Select Reference Number --</option>';
  
  if (!selectedTrip || selectedTrip === '') {
    refSelect.innerHTML = '<option value="">-- Select a trip first --</option>';
    refHelp.textContent = 'Select a trip to see available reference numbers';
    updateSummary();
    return;
  }
  
  if (!projectId) {
    refSelect.innerHTML = '<option value="">-- Select a project first --</option>';
    refHelp.textContent = 'Select a project first';
    updateSummary();
    return;
  }
  
  // Get references for the selected trip
  // JSON encoding converts PHP integer keys to string keys in JavaScript objects
  const tripKeyNum = parseInt(selectedTrip);
  const tripKeyStr = String(selectedTrip);
  let tripRefs = [];
  
  // Try string key first (JSON converts integer keys to strings), then numeric
  if (refsByTrip) {
  tripRefs = refsByTrip[tripKeyStr] || refsByTrip[tripKeyNum] || [];
  }
  
  if (tripRefs.length === 0) {
    refSelect.innerHTML = '<option value="">-- No references found for this trip --</option>';
    refHelp.textContent = 'No reference numbers transferred in this trip';
    updateSummary();
    return;
  }
  
  // Group references into bundles and individual refs
  const bundleMap = new Map();
  const individualRefs = [];
  
  tripRefs.forEach(ref => {
    const match = ref.match(/^(.+)-(\d+)$/);
    if (match) {
      const base = match[1];
      if (!bundleMap.has(base)) {
        bundleMap.set(base, []);
      }
      bundleMap.get(base).push(ref);
    } else {
      individualRefs.push(ref);
    }
  });
  
  const displayRefs = [];
  const allActualRefs = [];
  
  // Process bundles - add with range in brackets like "REF-1-4 (Bundle)"
  bundleMap.forEach((refs, base) => {
    if (refs.length >= 2) {
      // Sort by roll number
      refs.sort((a, b) => {
        const numA = parseInt(a.match(/-(\d+)$/)[1]);
        const numB = parseInt(b.match(/-(\d+)$/)[1]);
        return numA - numB;
      });
      
      // Get first and last roll numbers
      const firstNum = refs[0].match(/-(\d+)$/)[1];
      const lastNum = refs[refs.length - 1].match(/-(\d+)$/)[1];
      
      // Create bundle display format: "REF-1-4 (Bundle)"
      const bundleDisplay = firstNum === lastNum 
        ? base + '-' + firstNum + ' (Bundle)'
        : base + '-' + firstNum + '-' + lastNum + ' (Bundle)';
      
      displayRefs.push(bundleDisplay);
      allActualRefs.push(...refs);
    } else {
      // Single ref that matched pattern but isn't a bundle
      individualRefs.push(refs[0]);
    }
  });
  
  // Add individual references
  individualRefs.forEach(ref => {
    displayRefs.push(ref);
    allActualRefs.push(ref);
  });
  
  // Sort display refs (bundles first, then individual refs)
  displayRefs.sort((a, b) => {
    const aIsBundle = a.includes('(Bundle)');
    const bIsBundle = b.includes('(Bundle)');
    if (aIsBundle && !bIsBundle) return -1;
    if (!aIsBundle && bIsBundle) return 1;
    return a.localeCompare(b);
  });
    
  // Create a single option with all references separated by commas
  const displayString = displayRefs.join(', ');
  const actualRefsString = allActualRefs.join(', ');
  
      const option = document.createElement('option');
  option.value = actualRefsString; // Store actual refs in value for submission
  option.setAttribute('data-display', displayString); // Store display string
        option.setAttribute('data-is-bundle', 'false');
  option.textContent = displayString; // Show display string
  refSelect.appendChild(option);
  
  refHelp.textContent = tripRefs.length + ' reference' + (tripRefs.length > 1 ? 's' : '') + ' from this trip';
  
  updateSummary();
}

function handleReferenceChange() {
  const refSelect = document.getElementById('reference_number');
  const bundleRefsInput = document.getElementById('bundle_refs');
  const selectedOption = refSelect.options[refSelect.selectedIndex];
  
  if (selectedOption && selectedOption.getAttribute('data-is-bundle') === 'true') {
    const bundleRefs = selectedOption.getAttribute('data-bundle-refs');
    bundleRefsInput.value = bundleRefs || '';
  } else {
    bundleRefsInput.value = '';
  }
  updateSummary();
}

function updateSummary() {
  const dateTime = document.getElementById('reporting_time').value;
  const shiftBanner = document.getElementById('shiftBanner').innerText;
  const receiverName = document.getElementById('receiver_name').value;
  const projectName = document.querySelector('#projectGroup .btn.selected')?.innerText || '';
  const trip = document.getElementById('trip').value;
  const referenceNumber = document.getElementById('reference_number').value;
  
  if (dateTime && receiverName && projectName && trip && referenceNumber) {
    let summary = `${dateTime} | ${shiftBanner} | Receiver: ${receiverName} | Project: ${projectName} | Trip: ${trip} | Reference: ${referenceNumber}`;
    document.getElementById('summaryBox').innerText = summary;
    document.getElementById('summary').value = summary;
  } else {
    document.getElementById('summaryBox').innerText = 'Please fill all fields to see summary';
    document.getElementById('summary').value = '';
  }
}

function validateForm(){
  if(!document.getElementById("project_id").value){
    alert("Select a project."); 
    return false;
  }
  if(!document.getElementById("trip").value){
    alert("Select a trip."); 
    return false;
  }
  if(!document.getElementById("reference_number").value){
    alert("Select a reference number."); 
    return false;
  }
  return true;
}

function clearForm(){
  document.getElementById("receivedForm").reset();
  
  const projectButtons = document.querySelectorAll('#projectGroup .btn');
  projectButtons.forEach(b=>b.classList.remove('selected'));
  if (projectButtons.length > 0) {
    projectButtons[0].classList.add('selected');
    const defaultProjectId = projectButtons[0].dataset.id || '';
    document.getElementById("project_id").value = defaultProjectId;
  } else {
    document.getElementById("project_id").value = "";
  }
  
  const refSelect = document.getElementById('reference_number');
  refSelect.innerHTML = '<option value="">-- Select a trip first --</option>';
  document.getElementById('refHelp').textContent = 'Select a trip to see available reference numbers';
  document.getElementById("bundle_refs").value = "";
  document.getElementById('summaryBox').innerText = 'Please fill all fields to see summary';
  document.getElementById('summary').value = '';
}
</script>
</body>
</html>