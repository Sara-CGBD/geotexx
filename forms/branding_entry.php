<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'php_error.log');
error_reporting(E_ALL);
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();
require_once '../config/security_config.php';

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

// Role-based access control for Production module
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_PRODUCTION, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access the Production module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// Performance: Defer project loading - will load asynchronously after page render
$projects = [];
$conn = SecurityConfig::getConnection();

// Performance: Defer sewing data loading - will load asynchronously after page render
$sewingData = [];

// Generate next Branding ID
$nextBrandingNumber = 1;
$table_check = $conn->query("SHOW TABLES LIKE 'branding_entries'");
if ($table_check && $table_check->num_rows > 0) {
    // Check if branding_id column exists
    $column_check = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'branding_id'");
    if ($column_check && $column_check->num_rows > 0) {
        // Get current time in Bangladesh timezone
        $dhaka_tz = new DateTimeZone('Asia/Dhaka');
        $now = new DateTime('now', $dhaka_tz);
        
        // Determine the reset date (8 AM today or yesterday if before 8 AM)
        $reset_date = clone $now;
        if ($now->format('H') < 8) {
            $reset_date->modify('-1 day');
        }
        $reset_date->setTime(8, 0, 0);
        $reset_timestamp = $reset_date->format('Y-m-d H:i:s');
        
        // Get the max number for entries created since 8 AM
        $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(branding_id, -3) AS UNSIGNED)) as last_num 
                                FROM branding_entries 
                                WHERE date_time >= ?");
        if ($stmt) {
            $stmt->bind_param('s', $reset_timestamp);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                if ($row['last_num']) {
                    $nextBrandingNumber = $row['last_num'] + 1;
                }
            }
            $stmt->close();
        }
    }
}

// Format the Branding ID
$brandingId = 'BR-' . date('Ymd') . '-' . str_pad($nextBrandingNumber, 3, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Branding Machine Entry</title>
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
  .btn-group { display: flex; flex-wrap: wrap; gap: 5px; width: 100%; }
  .btn {
    padding: 10px 16px;
    font-size: 14px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    background-color: #f8f9fa;
  }
  .btn:hover { background-color: #ccc; }
  .btn.selected { background-color: #3498db; color: white; }
  .btn-bag-size {
    padding: 8px 12px;
    font-size: 13px;
    background: #f8f9fa;
    border: 1px solid #ddd;
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
  }
</style>
</head>
<body>
<div class="container">
  
  <h1>Branding Machine Entry</h1>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
      ✅ Branding Machine Entry saved successfully!<br>
      <?php if (isset($_GET['branding_id'])): ?>
        <strong>Branding ID: <?php echo htmlspecialchars($_GET['branding_id']); ?></strong>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error" style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
      ❌ Error: <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <form id="brandingEntryForm" method="post" action="../handlers/submit_branding_entry.php" onsubmit="return validateForm()">

    <!-- Hidden datetime + shift -->
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">

    <!-- Branding ID (Auto-generated, readonly) -->
    <div class="form-group">
      <label>Branding ID:</label>
      <input type="text" id="brandingId" name="brandingId" value="<?php echo htmlspecialchars($brandingId); ?>" readonly class="readonly" required>
    </div>

    <!-- Shift Incharge -->
    <div class="form-group">
      <label>Shift Incharge:</label>
      <div class="btn-group" id="shiftInchargeGroup">
        <button type="button" class="btn" data-value="Monir Hossen" onclick="selectBtn(this, 'shiftInchargeGroup')">Monir Hossen</button>
        <button type="button" class="btn" data-value="Abul Hashem" onclick="selectBtn(this, 'shiftInchargeGroup')">Abul Hashem</button>
        <button type="button" class="btn" data-value="Karim Uddin" onclick="selectBtn(this, 'shiftInchargeGroup')">Karim Uddin</button>
      </div>
      <input type="hidden" id="shiftIncharge" name="shiftIncharge">
    </div>

    <!-- Reference Number -->
    <div class="form-group">
      <label>Reference Number:</label>
      <select id="referenceNumber" name="referenceNumber" onchange="updateCNCBatch()" required>
        <option value="">-- Loading Reference Numbers... --</option>
      </select>
      <small id="ref_loading" style="display: block; color: #7f8c8d; font-size: 0.75em; margin-top: 2px;">Loading options...</small>
    </div>

    <!-- CNC Cutting Batch (Auto-filled) -->
    <div class="form-group">
      <label>CNC Cutting Batch:</label>
      <input type="text" id="cncCuttingBatch" name="cncCuttingBatch" placeholder="Auto-filled from reference" readonly class="readonly" required>
    </div>

    <!-- Project Selection -->
    <div class="form-group">
      <label>Project:</label>
      <div class="btn-group" id="projectGroup">
        <!-- Projects will be loaded asynchronously -->
      </div>
      <small id="project_loading" style="display: block; color: #7f8c8d; font-size: 0.75em; margin-top: 2px;">Loading projects...</small>
      <input type="hidden" id="project" name="project">
    </div>

    <!-- Print Machine -->
    <div class="form-group">
      <label>Print Machine:</label>
      <input type="text" id="printMachine" name="printMachine" required>
    </div>

    <!-- Bag Size (Buttons + Manual Input) -->
    <div class="form-group">
      <label>Bag Size:</label>
      <div class="btn-group" id="bagSizeGroup">
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('2000mmX1500mm', this)">2000mmX1500mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1200mmX950mm', this)">1200mmX950mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1250mmX1000mm', this)">1250mmX1000mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1225mmX1000mm', this)">1225mmX1000mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1300mmX1050mm', this)">1300mmX1050mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1600mmX850mm', this)">1600mmX850mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1100mmX850mm', this)">1100mmX850mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1200mmX600mm', this)">1200mmX600mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1100mmX800mm', this)">1100mmX800mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1125mmX900mm', this)">1125mmX900mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1150mmX800mm', this)">1150mmX800mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1150mmX850mm', this)">1150mmX850mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1150mmX900mm', this)">1150mmX900mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1700mmX1250mm', this)">1700mmX1250mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1050mmX800mm', this)">1050mmX800mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1075mmX850mm', this)">1075mmX850mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1000mmX800mm', this)">1000mmX800mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('950mmX750mm', this)">950mmX750mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('950mmX500mm', this)">950mmX500mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('830mmX600mm', this)">830mmX600mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1030mmX700mm', this)">1030mmX700mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('300mmX299mm', this)">300mmX299mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('500mmX499mm', this)">500mmX499mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('700mmX700mm', this)">700mmX700mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('850mmX700mm', this)">850mmX700mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1030mmX750mm', this)">1030mmX750mm</button>
        <button type="button" class="btn btn-bag-size" onclick="selectBagSize('1000mmX700mm', this)">1000mmX700mm</button>
        <button type="button" class="btn btn-bag-size custom-bag-size-btn" onclick="selectBagSize('custom', this)" style="background:#6c757d;color:#fff;">Custom (Enter manually)</button>
      </div>
      <input type="text" id="bagSizeCustom" placeholder="Enter custom bag size" style="margin-top: 10px; display:none;">
      <input type="hidden" id="bagSize" name="bagSize" value="">
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

    <!-- Print Quantity -->
    <div class="form-group">
      <label>Print Quantity:</label>
      <input type="number" id="printQty" name="printQty" min="1" required oninput="validatePrintQty()">
      <small id="available_print_text" style="color:#27ae60; font-weight:600; display:none; margin-top:5px;"></small>
      <small id="print_warning" style="color:#e74c3c; font-weight:600; display:none; margin-top:5px;"></small>
    </div>

    <!-- Summary Section -->
    <div class="form-group">
      <div id="summaryBox" class="summary-info"></div>
      <input type="hidden" id="summary" name="summary">
    </div>


    <div class="actions">
      <button type="submit" class="submit-btn">Submit</button>
      <button type="button" class="clear-btn" onclick="clearForm()">Clear </button>
    </div>
  </form>
</div>

<script>
function selectBagSize(value, btn){
  // toggle selected class
  document.querySelectorAll('#bagSizeGroup .btn-bag-size').forEach(b=>b.classList.remove('selected'));
  if(btn){ btn.classList.add('selected'); }
  const hidden = document.getElementById('bagSize');
  const custom = document.getElementById('bagSizeCustom');
  if(value === 'custom'){
    hidden.value = '';
    custom.style.display = 'block';
    custom.focus();
    // Hide GSM/thickness sections for custom
    document.getElementById('gsmSection').style.display = 'none';
    document.getElementById('thicknessSection').style.display = 'none';
  } else {
    custom.style.display = 'none';
    custom.value = '';
    hidden.value = value;
    
    // Fetch GSM and thickness options for this bag size
    fetchBagOptions(value);
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
function updateTimeAndShift() {
  const now = new Date();
    const utc = now.getTime() + now.getTimezoneOffset() * 60000;
    const dhaka = new Date(utc + 6 * 3600000);
    
    document.getElementById("dateTimeDisplay").innerHTML = "Date & Time: " + dhaka.toDateString() + " " + dhaka.toLocaleTimeString();
    
    const yyyy = dhaka.getFullYear();
    const mm = String(dhaka.getMonth() + 1).padStart(2, '0');
    const dd = String(dhaka.getDate()).padStart(2, '0');
    const hh = String(dhaka.getHours()).padStart(2, '0');
    const min = String(dhaka.getMinutes()).padStart(2, '0');
    const ss = String(dhaka.getSeconds()).padStart(2, '0');
    document.getElementById("dateTime").value = `${yyyy}-${mm}-${dd} ${hh}:${min}:${ss}`;
    
  const h = dhaka.getHours();
    const shift = (h >= 8 && h <= 19) ? "Day" : "Night";
    document.getElementById("shiftBanner").innerText = "Shift: " + shift;
    document.getElementById("shift").value = shift;
    updateSummary();
}
setInterval(updateTimeAndShift, 1000);
updateTimeAndShift();

function selectBtn(btn, groupId) {
    const group = document.getElementById(groupId);
    const buttons = group.querySelectorAll('button');
    buttons.forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    
    const hiddenInput = groupId.replace('Group', '');
    document.getElementById(hiddenInput).value = btn.dataset.value;
    updateSummary();
}

function updateCNCBatch() {
    const refSelect = document.getElementById("referenceNumber");
    const selectedOption = refSelect.options[refSelect.selectedIndex];
    const cncBatch = selectedOption.getAttribute("data-cnc");
    const availableText = document.getElementById("available_print_text");
    const warningText = document.getElementById("print_warning");
    const printQtyInput = document.getElementById("printQty");
    
    document.getElementById("cncCuttingBatch").value = cncBatch || '';
    
    // Load available print quantity
    if (selectedOption && selectedOption.value) {
        const sewingQty = parseInt(selectedOption.getAttribute("data-sewing-qty")) || 0;
        const printedQty = parseInt(selectedOption.getAttribute("data-printed-qty")) || 0;
        const availablePrint = parseInt(selectedOption.getAttribute("data-available-print")) || 0;
        
        // Display available quantity
        availableText.textContent = `✓ Available: ${availablePrint} pcs (Sewing: ${sewingQty} pcs, Already Printed: ${printedQty} pcs)`;
        availableText.style.color = '#27ae60';
        availableText.style.display = 'block';
        warningText.style.display = 'none';
        
        // Store for validation
        printQtyInput.setAttribute('data-max-qty', availablePrint);
        printQtyInput.max = availablePrint;
    } else {
        availableText.style.display = 'none';
        warningText.style.display = 'none';
        printQtyInput.removeAttribute('data-max-qty');
        printQtyInput.removeAttribute('max');
    }
    
    updateSummary();
}

function validatePrintQty() {
    const printQtyInput = document.getElementById("printQty");
    const printQty = parseInt(printQtyInput.value) || 0;
    const maxQty = parseInt(printQtyInput.getAttribute('data-max-qty')) || 0;
    const warningText = document.getElementById("print_warning");
    const availableText = document.getElementById("available_print_text");
    
    if (maxQty > 0 && printQty > maxQty) {
        warningText.textContent = `⚠️ Print quantity (${printQty} pcs) exceeds available quantity (${maxQty} pcs)`;
        warningText.style.display = 'block';
        printQtyInput.style.border = '2px solid #e74c3c';
    } else {
        warningText.style.display = 'none';
        printQtyInput.style.border = '1px solid #ccc';
        
        // Keep available text visible and green
        if (availableText && maxQty > 0) {
            availableText.style.color = '#27ae60';
            availableText.style.display = 'block';
        }
    }
}

function handleBagSizeChange() {
    // deprecated with button system
    updateSummary();
}

function updateSummary() {
    const brandingId = document.getElementById("brandingId").value;
    const dateTime = document.getElementById("dateTime").value;
    const shift = document.getElementById("shift").value;
    
    // Get selected values
    const selectedShiftIncharge = document.querySelector('#shiftInchargeGroup .btn.selected');
    const shiftInchargeName = selectedShiftIncharge ? selectedShiftIncharge.textContent.trim() : '';
    
    const referenceNumber = document.getElementById("referenceNumber").value;
    const cncCuttingBatch = document.getElementById("cncCuttingBatch").value;
    
    const selectedProject = document.querySelector('#projectGroup .btn.selected');
    const projectName = selectedProject ? selectedProject.textContent.trim() : '';
    
    const printMachine = document.getElementById("printMachine").value;
    
    // Get bag size (from hidden or custom input)
    let bagSize = document.getElementById("bagSize").value || document.getElementById("bagSizeCustom").value;
    
    const printQty = document.getElementById("printQty").value;
    
    // Build summary
    if (dateTime && shift) {
        let summary = `${brandingId} | ${dateTime} | Shift: ${shift}`;
        if (shiftInchargeName) summary += ` | Shift Incharge: ${shiftInchargeName}`;
        if (referenceNumber) summary += ` | Reference: ${referenceNumber}`;
        if (cncCuttingBatch) summary += ` | CNC Batch: ${cncCuttingBatch}`;
        if (projectName) summary += ` | Project: ${projectName}`;
        if (printMachine) summary += ` | Print Machine: ${printMachine}`;
        if (bagSize) summary += ` | Bag Size: ${bagSize}`;
        if (printQty) summary += ` | Print Qty: ${printQty}`;
        
        document.getElementById("summaryBox").innerText = summary;
        document.getElementById("summary").value = summary;
    } else {
        document.getElementById("summaryBox").innerText = "";
        document.getElementById("summary").value = "";
    }
}

function clearForm() {
    document.getElementById('brandingEntryForm').reset();
    document.querySelectorAll('.btn-group button').forEach(btn => btn.classList.remove('selected'));
    document.querySelectorAll('input[type="hidden"]').forEach(input => input.value = '');
    document.getElementById("bagSizeCustom").style.display = "none";
    document.getElementById("cncCuttingBatch").value = '';
    
    // Clear validation messages
    const availableText = document.getElementById('available_print_text');
    const warningText = document.getElementById('print_warning');
    if (availableText) availableText.style.display = 'none';
    if (warningText) warningText.style.display = 'none';
    
    const printQtyInput = document.getElementById('printQty');
    if (printQtyInput) {
        printQtyInput.removeAttribute('data-max-qty');
        printQtyInput.removeAttribute('max');
        printQtyInput.style.border = '1px solid #ccc';
    }
    
    updateSummary();
}

function validateForm() {
    // Validate print quantity against available
    const printQtyInput = document.getElementById('printQty');
    const printQty = parseInt(printQtyInput.value) || 0;
    const maxQty = parseInt(printQtyInput.getAttribute('data-max-qty')) || 0;
    
    if (maxQty > 0 && printQty > maxQty) {
        alert(`❌ Print quantity (${printQty} pcs) exceeds available quantity (${maxQty} pcs).\n\nPlease reduce the print quantity.`);
        return false;
    }
    
    if (printQty <= 0) {
        alert('❌ Please enter a valid print quantity greater than 0.');
        return false;
    }
    
    return true;
}

// Add event listeners for input fields
["referenceNumber", "printMachine", "bagSizeCustom", "printQty"].forEach(id => {
    const elem = document.getElementById(id);
    if (elem) {
        elem.addEventListener("input", updateSummary);
        elem.addEventListener("change", updateSummary);
    }
});

// Load form data asynchronously after page renders for instant page load
document.addEventListener('DOMContentLoaded', function() {
    loadFormData();
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
                
                data.projects.forEach((project, index) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn' + (index === 0 ? ' selected' : '');
                    btn.setAttribute('data-value', project.id);
                    btn.textContent = project.project_name;
                    btn.onclick = function() { selectBtn(this, 'projectGroup'); };
                    projectGroup.appendChild(btn);
                });
                if (projectGroup && data.projects.length > 0) {
                    document.getElementById('project').value = data.projects[0].id || '';
                }
            }
        })
        .catch(err => {
            console.error('Error loading projects:', err);
            const loadingText = document.getElementById('project_loading');
            if (loadingText) loadingText.textContent = 'Failed to load projects';
        });
    
    // Load reference numbers (sewing data)
    fetch('api/get_branding_references.php')
        .then(response => response.json())
        .then(data => {
            const refSelect = document.getElementById('referenceNumber');
            const loadingText = document.getElementById('ref_loading');
            if (loadingText) loadingText.style.display = 'none';

            if (!data.success) {
                refSelect.innerHTML = '<option value="">-- Failed to load references --</option>';
                return;
            }

            const refs = data.references || [];
            refSelect.innerHTML = '<option value="">-- Select Reference Number --</option>';
            if (refs.length === 0) {
                refSelect.innerHTML = '<option value="">-- No references available --</option>';
                return;
            }

            refs.forEach(ref => {
                const option = document.createElement('option');
                option.value = ref.reference_number;
                option.setAttribute('data-cnc', ref.cnc_cutting_batch || '');
                option.setAttribute('data-sewing-qty', ref.total_sewing_qty || 0);
                option.setAttribute('data-printed-qty', ref.total_printed || 0);
                option.setAttribute('data-available-print', ref.available_for_print || 0);
                option.textContent = ref.reference_number + ' - Available: ' + (ref.available_for_print || 0) + ' pcs';
                refSelect.appendChild(option);
            });
        })
        .catch(err => {
            console.error('Error loading references:', err);
            const refSelect = document.getElementById('referenceNumber');
            const loadingText = document.getElementById('ref_loading');
            if (loadingText) {
                loadingText.style.display = 'none';
                loadingText.textContent = 'Failed to load references';
            }
            if (refSelect) {
                refSelect.innerHTML = '<option value="">-- Failed to load references --</option>';
            }
        });
}
</script>
</body>
</html>

