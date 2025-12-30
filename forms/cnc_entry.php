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

// DB connection
$conn = SecurityConfig::getConnection();

// Performance: Defer project loading - will load asynchronously after page render
$projects = [];

// Performance: Optimize reference query with LIMIT and defer loading
// Fetch reference numbers from roll_received that:
// 1. Haven't been used in CNC entry yet
// 2. Have all QC tests (excluding UV/Weathering Exposure Test) approved by AGM/Admin
//    - QC Test Orders (excluding UV/Weathering Exposure Test)
//    - Water Permeability Tests
//    - Characteristics Tests
//    - Sun Test Reports
// 3. At least one approved test exists for this reference
$referenceNumbers = [];
$refQuery = "SELECT DISTINCT r.reference_number 
             FROM roll_received r 
             WHERE r.reference_number IS NOT NULL 
             AND NOT EXISTS (
                 SELECT 1 FROM cnc_entries c 
                 WHERE c.reference_number = r.reference_number
             )
             AND (
                 -- Check if all QC test orders (excluding Weathering Exposure Test) for this reference are approved
                 NOT EXISTS (
                     SELECT 1 
                     FROM qc_test_orders qto
                     LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
                     WHERE qto.sample_reference_id = r.reference_number
                     AND ts.test_name != 'Weathering Exposure Test'
                     AND (qto.status != 'approved' OR qto.approved_by IS NULL)
                 )
                 -- Check if all Water Permeability Tests for this reference are approved
                 AND NOT EXISTS (
                     SELECT 1 
                     FROM water_permeability_tests wpt
                     WHERE wpt.reference_number = r.reference_number
                     AND (wpt.status != 'approved' OR wpt.approved_by IS NULL)
                 )
                 -- Check if all Characteristics Tests for this reference are approved
                 AND NOT EXISTS (
                     SELECT 1 
                     FROM characteristics_tests ct
                     WHERE ct.reference_number = r.reference_number
                     AND (ct.status != 'approved' OR ct.approver_name IS NULL)
                 )
                 -- Check if all Sun Test Reports for this reference are approved
                 AND NOT EXISTS (
                     SELECT 1 
                     FROM sun_test_reports str
                     WHERE str.reference_number = r.reference_number
                     AND (str.status != 'approved' OR str.approved_by IS NULL)
                 )
             )
             AND (
                 -- Ensure at least one approved test exists for this reference
                 EXISTS (
                     SELECT 1 
                     FROM qc_test_orders qto
                     LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
                     WHERE qto.sample_reference_id = r.reference_number
                     AND ts.test_name != 'Weathering Exposure Test'
                     AND qto.status = 'approved'
                     AND qto.approved_by IS NOT NULL
                 )
                 OR EXISTS (
                     SELECT 1 
                     FROM water_permeability_tests wpt
                     WHERE wpt.reference_number = r.reference_number
                     AND wpt.status = 'approved'
                     AND wpt.approved_by IS NOT NULL
                 )
                 OR EXISTS (
                     SELECT 1 
                     FROM characteristics_tests ct
                     WHERE ct.reference_number = r.reference_number
                     AND ct.status = 'approved'
                     AND ct.approver_name IS NOT NULL
                 )
                 OR EXISTS (
                     SELECT 1 
                     FROM sun_test_reports str
                     WHERE str.reference_number = r.reference_number
                     AND str.status = 'approved'
                     AND str.approved_by IS NOT NULL
                 )
             )
             ORDER BY r.created_at DESC
             LIMIT 100";
// Performance: Defer reference loading - will load asynchronously after page render
$referenceNumbers = [];

// Generate Entry ID (auto-increment based on date and sequence)
$current_date = date('Y-m-d');
$next_cnc_number = 1;

// Check if cnc_entries table exists and has cnc_id column
$table_check = $conn->query("SHOW TABLES LIKE 'cnc_entries'");
if ($table_check && $table_check->num_rows > 0) {
    // Check if cnc_id column exists
    $column_check = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'cnc_id'");
    if ($column_check && $column_check->num_rows > 0) {
        $last_cnc = $conn->query("SELECT MAX(CAST(SUBSTRING(cnc_id, -3) AS UNSIGNED)) as last_num FROM cnc_entries WHERE DATE(date_time) = '$current_date'");
        if ($last_cnc && $last_cnc->num_rows > 0) {
            $row = $last_cnc->fetch_assoc();
            if ($row['last_num']) {
                $next_cnc_number = $row['last_num'] + 1;
            }
        }
    }
}

$entry_id = "CNC" . date('Ymd') . str_pad($next_cnc_number, 3, '0', STR_PAD_LEFT);

// Calculate next cutting number (resets at 8 AM daily)
// Get current time in Dhaka timezone
$dhaka_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
$current_hour = (int)$dhaka_time->format('H');
$today_8am = clone $dhaka_time;
$today_8am->setTime(8, 0, 0);

// If current time is before 8 AM, use yesterday's 8 AM as the start
if ($current_hour < 8) {
    $shift_start = clone $today_8am;
    $shift_start->modify('-1 day');
} else {
    $shift_start = $today_8am;
}

$shift_start_str = $shift_start->format('Y-m-d H:i:s');

// Get the last cutting number for current shift (from 8 AM to 7:59 AM next day)
$next_cutting_number = 1;
if ($table_check && $table_check->num_rows > 0) {
    // Check if cnc_cutting_batch column exists
    $batch_column_check = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'cnc_cutting_batch'");
    if ($batch_column_check && $batch_column_check->num_rows > 0) {
        $cutting_query = "SELECT MAX(CAST(SUBSTRING_INDEX(cnc_cutting_batch, '-', -1) AS UNSIGNED)) as last_cutting 
                          FROM cnc_entries 
                          WHERE date_time >= '$shift_start_str' AND cnc_cutting_batch IS NOT NULL";
        $cutting_result = $conn->query($cutting_query);
        if ($cutting_result && $cutting_result->num_rows > 0) {
            $cutting_row = $cutting_result->fetch_assoc();
            if ($cutting_row['last_cutting']) {
                $next_cutting_number = $cutting_row['last_cutting'] + 1;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CNC Entry</title>
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
    padding: 10px 16px;
    font-size: 14px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    background-color: #f8f9fa;
  }
  .btn-group .btn:hover { background-color: #ccc; }
  .btn-group .btn.selected { background-color: #3498db; color: white; }
  .btn-bag-size {
    padding: 8px 12px;
    font-size: 13px;
    border: 1px solid #ddd;
    border-radius: 5px;
    cursor: pointer;
    background-color: #f8f9fa;
    transition: all 0.2s;
    white-space: nowrap;
    flex: 0 0 32%;
    box-sizing: border-box;
    min-width: 0;
  }
  .btn-bag-size.custom-bag-size-btn {
    flex: 0 0 100%;
    margin-top: 5px;
  }
  .btn-bag-size:hover {
    background-color: #007bff;
    color: white;
    border-color: #007bff;
  }
  .btn-bag-size.selected {
    background-color: #28a745;
    color: white;
    border-color: #28a745;
    font-weight: bold;
  }
</style>
</head>
<body>
<div class="container">
  
  <h1>CNC Machine Entry</h1>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
      âœ… CNC Entry saved successfully!
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger" style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
      âŒ Error: <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <form id="cncForm" method="post" action="../handlers/submit_cnc_entry.php" onsubmit="return validateForm();">

    <!-- Entry ID (renamed from CNC ID) -->
    <div class="form-group">
      <label>Entry ID: </label>
      <input type="text" value="<?php echo htmlspecialchars($entry_id); ?>" readonly class="readonly">
      <input type="hidden" name="cnc_id" value="<?php echo htmlspecialchars($entry_id); ?>">
    </div>

    <!-- Reference Number (fetched from roll_received) -->
    <div class="form-group">
      <label>Reference Number: </label>
      <select id="reference_number" name="reference_number" required onchange="generateCuttingBatch()">
        <option value="">-- Loading Reference Numbers... --</option>
      </select>
      <small id="ref_loading" style="display: block; color: #7f8c8d; font-size: 0.75em; margin-top: 2px;">Loading options...</small>
    </div>

    <!-- CNC Cutting Batch (Auto-generated from reference + date + cutting number) -->
    <div class="form-group">
      <label>CNC Cutting Batch Number: </label>
      <input type="text" id="cnc_cutting_batch_display" readonly class="readonly" placeholder="Select reference number first">
      <input type="hidden" id="cnc_cutting_batch" name="cnc_cutting_batch">
      <input type="hidden" id="cutting_number" value="<?php echo $next_cutting_number; ?>">
    </div>

    <!-- CNC Machine ID -->
    <div class="form-group">
      <label>CNC Machine ID:</label>
      <div class="btn-group" id="cncMachineGroup">
        <button type="button" class="btn" data-value="CNC-01" onclick="selectCNCMachine(this,'cncMachineGroup')">CNC-01</button>
        <button type="button" class="btn" data-value="CNC-02" onclick="selectCNCMachine(this,'cncMachineGroup')">CNC-02</button>
        <button type="button" class="btn" data-value="custom" onclick="selectCNCMachine(this,'cncMachineGroup')">Custom</button>
      </div>
      <input type="text" id="cnc_machine_custom" placeholder="Enter CNC Machine ID manually" style="margin-top: 8px; display: none;">
      <input type="hidden" id="cnc_machine_id" name="cnc_machine_id">
    </div>

    <!-- Hidden datetime + shift -->
    <input type="hidden" id="dateTime" name="date_time">
    <input type="hidden" id="shift" name="shift">

    <!-- Reporter -->
    <div class="form-group">
      <label>Reporter:</label>
      <input type="text" value="<?php echo htmlspecialchars($_SESSION['username']); ?>" readonly class="readonly">
      <input type="hidden" name="reporter_id" value="<?php echo $_SESSION['user_id']; ?>">
    </div>

    <!-- Project -->
<div class="form-group">
  <label>Project:</label>
  <div class="btn-group" id="projectGroup">
    <!-- Projects will be loaded asynchronously -->
  </div>
  <small id="project_loading" style="display: block; color: #7f8c8d; font-size: 0.75em; margin-top: 2px;">Loading projects...</small>
  <input type="hidden" id="project_id" name="project_id">
</div>

    <!-- Cutting Roll Quantity -->
    <div class="form-group">
      <label>Cutting Roll Quantity:</label>
      <input type="number" step="1" id="cutting_roll_quantity" name="cutting_roll_quantity" required min="1" placeholder="Enter number of rolls">
    </div>

    <!-- Bag Size with button system and custom input -->
    <div class="form-group">
      <label>Bag Size:</label>
      <div style="margin-bottom: 10px; max-height: 500px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 5px;">
        <div class="btn-group" style="display: flex; flex-wrap: wrap; gap: 5px; width: 100%;">
          <button type="button" class="btn-bag-size" onclick="selectBagSize('2000mmX1500mm')">2000mmX1500mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1200mmX950mm')">1200mmX950mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1250mmX1000mm')">1250mmX1000mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1225mmX1000mm')">1225mmX1000mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1300mmX1050mm')">1300mmX1050mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1600mmX850mm')">1600mmX850mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1100mmX850mm')">1100mmX850mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1200mmX600mm')">1200mmX600mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1100mmX800mm')">1100mmX800mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1125mmX900mm')">1125mmX900mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1150mmX800mm')">1150mmX800mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1150mmX850mm')">1150mmX850mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1150mmX900mm')">1150mmX900mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1700mmX1250mm')">1700mmX1250mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1050mmX800mm')">1050mmX800mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1075mmX850mm')">1075mmX850mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1030mmX700mm')">1030mmX700mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1000mmX800mm')">1000mmX800mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('950mmX750mm')">950mmX750mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('950mmX500mm')">950mmX500mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('830mmX600mm')">830mmX600mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('300mmX299mm')">300mmX299mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('500mmX499mm')">500mmX499mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('700mmX700mm')">700mmX700mm</button>
          
          <button type="button" class="btn-bag-size" onclick="selectBagSize('850mmX700mm')">850mmX700mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1030mmX750mm')">1030mmX750mm</button>
          <button type="button" class="btn-bag-size" onclick="selectBagSize('1000mmX700mm')">1000mmX700mm</button>
          <button type="button" class="btn-bag-size custom-bag-size-btn" onclick="selectBagSize('custom')" style="background: #6c757d; color: white;">Custom (Enter manually)</button>
        </div>
      </div>
      <input type="text" id="bag_size_custom" placeholder="Enter custom bag size" style="margin-top: 8px; display: none; width: 100%; padding: 8px;">
      <input type="hidden" id="bag_size" name="bag_size">
    </div>

    <!-- GSM Selection (shown dynamically if multiple GSM options) -->
    <div class="form-group" id="gsmSection" style="display:none;">
      <label>GSM:</label>
      <div class="btn-group" id="gsmGroup"></div>
      <input type="hidden" id="gsm" name="gsm">
    </div>

    <!-- Thickness Selection (shown dynamically if multiple thickness options) -->
    <div class="form-group" id="thicknessSection" style="display:none;">
      <label>Thickness (mm):</label>
      <div class="btn-group" id="thicknessGroup"></div>
      <input type="hidden" id="thickness" name="thickness">
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
  const shift = (h >= 8 && h <= 19) ? "Day" : "Night";
  document.getElementById("shiftBanner").innerText = "Shift: " + shift;
  document.getElementById("shift").value = shift;
}
setInterval(updateTimeAndShift,1000); updateTimeAndShift();

function selectBtn(btn, groupId){
  document.querySelectorAll(`#${groupId} .btn`).forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  if(groupId==="projectGroup"){
    document.getElementById("project_id").value = btn.dataset.id;
  }
  updateSummary();
}

function selectCNCMachine(btn, groupId){
  document.querySelectorAll(`#${groupId} .btn`).forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  
  const customInput = document.getElementById('cnc_machine_custom');
  const hiddenInput = document.getElementById('cnc_machine_id');
  
  if (btn.dataset.value === 'custom') {
    customInput.style.display = 'block';
    customInput.required = true;
    hiddenInput.value = '';
  } else {
    customInput.style.display = 'none';
    customInput.required = false;
    customInput.value = '';
    hiddenInput.value = btn.dataset.value;
  }
  updateSummary();
}

function generateCuttingBatch() {
  const refNumber = document.getElementById('reference_number').value;
  const cuttingNumber = parseInt(document.getElementById('cutting_number').value);
  
  if (!refNumber) {
    document.getElementById('cnc_cutting_batch_display').value = '';
    document.getElementById('cnc_cutting_batch').value = '';
    updateSummary();
    return;
  }
  
  // Extract prefix without the date part
  // e.g., "1.8L125OCT21-R02-GT0.9H0.1" â†’ "1.8L125OCT"
  // Pattern: everything up to the last 2 digits before first hyphen
  const refParts = refNumber.split('-');
  let refPrefix = refParts[0]; // e.g., "1.8L125OCT21"
  
  // Remove last 2 digits (the year) from the prefix
  // Match pattern: ends with 2 digits
  refPrefix = refPrefix.replace(/\d{2}$/, ''); // "1.8L125OCT21" â†’ "1.8L125OCT"
  
  // Get today's date (just the day, e.g., "21")
  const now = new Date();
  const utc = now.getTime() + now.getTimezoneOffset()*60000;
  const dhaka = new Date(utc + 6*3600000);
  
  const day = String(dhaka.getDate()).padStart(2, '0');
  
  // Format cutting number with leading zero (01, 02, etc.)
  const cuttingNumFormatted = String(cuttingNumber).padStart(2, '0');
  
  // Generate batch: refPrefix-day-CW-cuttingNumber
  // e.g., "1.8L125OCT-21-CW-01"
  const cuttingBatch = `${refPrefix}-${day}-CW-${cuttingNumFormatted}`;
  
  document.getElementById('cnc_cutting_batch_display').value = cuttingBatch;
  document.getElementById('cnc_cutting_batch').value = cuttingBatch;
  
  console.log('Generated cutting batch:', cuttingBatch);
  updateSummary();
}

function selectBagSize(size) {
  const customInput = document.getElementById('bag_size_custom');
  const hiddenInput = document.getElementById('bag_size');
  
  if (size === 'custom') {
    customInput.style.display = 'block';
    customInput.required = true;
    hiddenInput.value = '';
    customInput.focus();
    // Hide GSM/thickness sections for custom
    document.getElementById('gsmSection').style.display = 'none';
    document.getElementById('thicknessSection').style.display = 'none';
  } else {
    customInput.style.display = 'none';
    customInput.required = false;
    customInput.value = '';
    hiddenInput.value = size;
    
    // Highlight selected button
    document.querySelectorAll('.btn-bag-size').forEach(btn => {
      btn.classList.remove('selected');
      if (btn.textContent.trim() === size) {
        btn.classList.add('selected');
      }
    });
    
    // Fetch GSM and thickness options for this bag size
    fetchBagOptions(size);
  }
  updateSummary();
}

function fetchBagOptions(bagSize) {
  fetch(`api/fetch_bag_options.php?bag_size=${encodeURIComponent(bagSize)}`)
    .then(response => response.json())
    .then(data => {
      const gsmSection = document.getElementById('gsmSection');
      const gsmGroup = document.getElementById('gsmGroup');
      const gsmHidden = document.getElementById('gsm');
      
      // Clear previous selections
      gsmGroup.innerHTML = '';
      gsmHidden.value = '';
      
      if (data.gsmList && data.gsmList.length > 1) {
        // Multiple GSM options - show selection
        gsmSection.style.display = 'block';
        data.gsmList.forEach(gsm => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn';
          btn.textContent = gsm;
          btn.onclick = function() { selectGSM(gsm, bagSize); };
          gsmGroup.appendChild(btn);
        });
      } else if (data.gsmList && data.gsmList.length === 1) {
        // Only one GSM - auto-select and check thickness
        gsmSection.style.display = 'none';
        gsmHidden.value = data.gsmList[0];
        fetchThicknessOptions(bagSize, data.gsmList[0]);
      } else {
        // No GSM data - hide both sections
        gsmSection.style.display = 'none';
        document.getElementById('thicknessSection').style.display = 'none';
      }
    })
    .catch(error => console.error('Error fetching bag options:', error));
}

function selectGSM(gsm, bagSize) {
  document.getElementById('gsm').value = gsm;
  
  // Highlight selected GSM button
  document.querySelectorAll('#gsmGroup .btn').forEach(btn => {
    btn.classList.remove('selected');
    if (btn.textContent == gsm) {
      btn.classList.add('selected');
    }
  });
  
  // Fetch thickness options for this bag size + GSM
  fetchThicknessOptions(bagSize, gsm);
  updateSummary();
}

function fetchThicknessOptions(bagSize, gsm) {
  fetch(`api/fetch_thickness_options.php?bag_size=${encodeURIComponent(bagSize)}&gsm=${gsm}`)
    .then(response => response.json())
    .then(data => {
      const thicknessSection = document.getElementById('thicknessSection');
      const thicknessGroup = document.getElementById('thicknessGroup');
      const thicknessHidden = document.getElementById('thickness');
      
      // Clear previous selections
      thicknessGroup.innerHTML = '';
      thicknessHidden.value = '';
      
      if (data.thicknessList && data.thicknessList.length > 1) {
        // Multiple thickness options - show selection
        thicknessSection.style.display = 'block';
        data.thicknessList.forEach(thickness => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn';
          btn.textContent = thickness;
          btn.onclick = function() { selectThickness(thickness); };
          thicknessGroup.appendChild(btn);
        });
      } else if (data.thicknessList && data.thicknessList.length === 1) {
        // Only one thickness - auto-select
        thicknessSection.style.display = 'none';
        thicknessHidden.value = data.thicknessList[0];
      } else {
        // No thickness data
        thicknessSection.style.display = 'none';
      }
    })
    .catch(error => console.error('Error fetching thickness options:', error));
}

function selectThickness(thickness) {
  document.getElementById('thickness').value = thickness;
  
  // Highlight selected thickness button
  document.querySelectorAll('#thicknessGroup .btn').forEach(btn => {
    btn.classList.remove('selected');
    if (btn.textContent == thickness) {
      btn.classList.add('selected');
    }
  });
  
  updateSummary();
}

function handleBagSizeChange() {
  const dropdown = document.getElementById('bag_size_dropdown');
  const customInput = document.getElementById('bag_size_custom');
  const hiddenInput = document.getElementById('bag_size');
  
  if (dropdown.value === 'custom') {
    customInput.style.display = 'block';
    customInput.required = true;
    hiddenInput.value = '';
  } else {
    customInput.style.display = 'none';
    customInput.required = false;
    customInput.value = '';
    hiddenInput.value = dropdown.value;
  }
  updateSummary();
}

// Listen to custom input changes
document.addEventListener('DOMContentLoaded', function() {
  const bagSizeCustomInput = document.getElementById('bag_size_custom');
  const bagSizeHiddenInput = document.getElementById('bag_size');
  const cncMachineCustomInput = document.getElementById('cnc_machine_custom');
  const cncMachineHiddenInput = document.getElementById('cnc_machine_id');
  const refNumber = document.getElementById('reference_number');
  
  bagSizeCustomInput.addEventListener('input', function() {
    bagSizeHiddenInput.value = this.value;
    updateSummary();
  });
  
  cncMachineCustomInput.addEventListener('input', function() {
    cncMachineHiddenInput.value = this.value;
    updateSummary();
  });
  
  refNumber.addEventListener('change', updateSummary);
});

function updateSummary() {
  const dateTime = document.getElementById("dateTime").value;
  const shift = document.getElementById("shift").value;
  const entryId = document.querySelector('input[name="cnc_id"]').value;
  
  // Get project name from selected button
  const selectedProject = document.querySelector('#projectGroup .btn.selected');
  const projectName = selectedProject ? selectedProject.textContent.trim() : '';
  
  const refNumber = document.getElementById("reference_number").value;
  const cuttingBatch = document.getElementById("cnc_cutting_batch").value;
  const bagSize = document.getElementById("bag_size").value;
  const cuttingQty = document.getElementById("cutting_roll_quantity").value;
  
  // Get CNC Machine ID (either from hidden field or custom input)
  let cncMachineId = document.getElementById("cnc_machine_id").value;
  if (!cncMachineId) {
    const customMachine = document.getElementById("cnc_machine_custom").value.trim();
    if (customMachine) {
      cncMachineId = customMachine;
    }
  }
  
  // Only show summary if at least some basic info is available
  if (dateTime && shift && entryId) {
    let summary = `${dateTime} | Shift: ${shift} | Entry ID: ${entryId}`;
    if (refNumber) summary += ` | Reference: ${refNumber}`;
    if (cuttingBatch) summary += ` | Cutting Batch: ${cuttingBatch}`;
    if (cncMachineId) summary += ` | Machine: ${cncMachineId}`;
    if (projectName) summary += ` | Project: ${projectName}`;
    if (cuttingQty) summary += ` | Rolls: ${cuttingQty}`;
    if (bagSize) summary += ` | Bag Size: ${bagSize}`;
    
    document.getElementById("summaryBox").innerText = summary;
    document.getElementById("summary").value = summary;
  } else {
    document.getElementById("summaryBox").innerText = "";
    document.getElementById("summary").value = "";
  }
}

function clearForm(){
  // Clear all form fields
  document.getElementById("cncForm").reset();
  
  // Clear button selections
  document.querySelectorAll('#projectGroup .btn').forEach(b=>b.classList.remove('selected'));
  document.querySelectorAll('#cncMachineGroup .btn').forEach(b=>b.classList.remove('selected'));
  document.getElementById("project_id").value="";
  document.getElementById("cnc_machine_id").value="";
  document.getElementById("bag_size").value="";
  document.getElementById("cnc_cutting_batch").value="";
  document.getElementById("cnc_cutting_batch_display").value="";
  document.getElementById("bag_size_custom").style.display = 'none';
  document.getElementById("cnc_machine_custom").style.display = 'none';
  
  // Clear summary
  document.getElementById("summaryBox").innerText = "";
  document.getElementById("summary").value = "";
}

function validateForm(){
  // Ensure hidden fields are populated
  const dateTime = document.getElementById("dateTime").value;
  const shift = document.getElementById("shift").value;
  
  if(!document.getElementById("reference_number").value){
    alert("Please select a Reference Number."); return false;
  }
  if(!document.getElementById("cnc_cutting_batch").value){
    alert("CNC Cutting Batch is required. Please select a reference number."); return false;
  }
  
  // Handle CNC Machine ID (either from buttons or custom input)
  const cncMachineId = document.getElementById("cnc_machine_id");
  const cncMachineCustom = document.getElementById("cnc_machine_custom");
  
  if(!cncMachineId.value && cncMachineCustom.value.trim()){
    // If custom input has value, use it
    cncMachineId.value = cncMachineCustom.value.trim();
  }
  
  if(!cncMachineId.value){
    alert("Please select or enter a CNC Machine ID."); return false;
  }
  
  if(!document.getElementById("project_id").value){
    alert("Please select a project."); return false;
  }
  if(!document.getElementById("cutting_roll_quantity").value.trim()){
    alert("Please enter Cutting Roll Quantity."); return false;
  }
  if(!document.getElementById("bag_size").value.trim()){
    alert("Please select or enter bag size."); return false;
  }
  
  return true;
}

// Add event listeners for form fields to update summary (additional to the ones in handleBagSizeChange)
document.addEventListener('DOMContentLoaded', function() {
  document.getElementById('cutting_roll_quantity').addEventListener('input', updateSummary);
  document.getElementById('cnc_machine_custom').addEventListener('input', updateSummary);
  
  // Load form data asynchronously after page renders for instant page load
  loadFormData();
  
  // Update summary on page load
  updateSummary();
});

// Load form dropdowns asynchronously to avoid blocking page render
function loadFormData() {
  // Load projects
  fetch('api/get_projects.php')
    .then(response => response.json())
    .then(data => {
      if (data.success && data.projects) {
        const projectGroup = document.getElementById('projectGroup');
        const loadingText = document.getElementById('project_loading');
        if (loadingText) loadingText.style.display = 'none';
        
        data.projects.forEach(project => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn';
          btn.setAttribute('data-id', project.id);
          btn.textContent = project.project_name;
          btn.onclick = function() { selectBtn(this, 'projectGroup'); };
          projectGroup.appendChild(btn);
        });
      }
    })
    .catch(err => {
      console.error('Error loading projects:', err);
      const loadingText = document.getElementById('project_loading');
      if (loadingText) loadingText.textContent = 'Failed to load projects';
    });
  
  // Load reference numbers
  fetch('api/get_cnc_references.php')
    .then(response => response.json())
    .then(data => {
      if (data.success && data.references) {
        const refSelect = document.getElementById('reference_number');
        const loadingText = document.getElementById('ref_loading');
        if (loadingText) loadingText.style.display = 'none';
        
        refSelect.innerHTML = '<option value="">-- Select Reference Number --</option>';
        data.references.forEach(ref => {
          const option = document.createElement('option');
          option.value = ref;
          option.textContent = ref;
          refSelect.appendChild(option);
        });
      }
    })
    .catch(err => {
      console.error('Error loading references:', err);
      const loadingText = document.getElementById('ref_loading');
      if (loadingText) loadingText.textContent = 'Failed to load references';
    });
}
</script>
</body>
</html>


