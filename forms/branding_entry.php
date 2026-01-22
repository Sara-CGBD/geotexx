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

    <!-- CNC Cutting Batch (dropdown from sewing machine entries) -->
    <div class="form-group">
      <label>CNC Cutting Batch:</label>
      <select id="cncCuttingBatch" name="cncCuttingBatch" required onchange="updateReferenceFromBatch()">
        <option value="">-- Loading CNC Cutting Batches... --</option>
      </select>
      <div id="batch_loading" style="display: none; margin-top: 10px;"></div>
      <input type="hidden" id="referenceNumber" name="referenceNumber" value="">
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
      <div id="available_print_text" style="display:none; margin-top:10px;"></div>
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
// Auto-select bag size based on value from database
function autoSelectBagSize(bagSizeValue) {
    if (!bagSizeValue) return;
    
    // Normalize the bag size value (handle case differences and spacing)
    const normalizedValue = bagSizeValue.trim().toLowerCase();
    
    // Find the matching button in bagSizeGroup
    const bagSizeButtons = document.querySelectorAll('#bagSizeGroup .btn-bag-size');
    let found = false;
    
    bagSizeButtons.forEach(btn => {
        const btnValue = btn.textContent.trim().toLowerCase();
        if (btnValue === normalizedValue || btnValue.replace(/\s+/g, '') === normalizedValue.replace(/\s+/g, '')) {
            // Found matching button, select it
            selectBagSize(btn.textContent.trim(), btn);
            found = true;
        }
    });
    
    // If no button match found, check if it's a custom size
    if (!found) {
        // Try to find custom button and activate custom input
        const customBtn = document.querySelector('#bagSizeGroup .custom-bag-size-btn');
        if (customBtn) {
            selectBagSize('custom', customBtn);
            const customInput = document.getElementById('bagSizeCustom');
            if (customInput) {
                customInput.value = bagSizeValue;
                customInput.style.display = 'block';
            }
        }
    }
}

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

// Removed updateCNCBatch function - CNC batch is now selected directly from dropdown

// Track if popup is already shown to avoid multiple popups
let popupShown = false;

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
        
        // Show popup immediately if not already shown
        if (!popupShown) {
            showPrintQtyPopup(printQty, maxQty);
            popupShown = true;
        }
    } else {
        warningText.style.display = 'none';
        printQtyInput.style.border = '1px solid #ccc';
        // Reset flag when quantity is valid (allows popup to show again if user exceeds limit again)
        if (printQty <= maxQty) {
            popupShown = false;
        }
        
        // Keep available text visible (already styled in updateReferenceFromBatch)
        if (availableText && maxQty > 0) {
            availableText.style.display = 'block';
        }
    }
}

// Show popup when print quantity exceeds limit
function showPrintQtyPopup(enteredQty, maxQty) {
    // Remove existing popup if any
    const existingOverlay = document.getElementById('printQtyPopupOverlay');
    if (existingOverlay) {
        existingOverlay.remove();
    }
    
    // Create popup overlay with modern backdrop blur
    const overlay = document.createElement('div');
    overlay.id = 'printQtyPopupOverlay';
    overlay.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        z-index: 10000;
        display: flex;
        justify-content: center;
        align-items: center;
        animation: fadeIn 0.3s ease-out;
    `;
    
    // Add fade-in animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes slideUp {
            from { 
                opacity: 0;
                transform: translateY(30px) scale(0.95);
            }
            to { 
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }
    `;
    document.head.appendChild(style);
    
    // Create popup content with modern design
    const popup = document.createElement('div');
    popup.style.cssText = `
        background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
        padding: 0;
        border-radius: 16px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.1);
        max-width: 380px;
        width: 85%;
        overflow: hidden;
        animation: slideUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        position: relative;
    `;
    
    popup.innerHTML = `
        <!-- Header with gradient -->
        <div style="
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
            padding: 20px 20px 18px 20px;
            text-align: center;
            position: relative;
        ">
            <div style="
                width: 60px;
                height: 60px;
                margin: 0 auto 12px;
                background: rgba(255, 255, 255, 0.2);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                backdrop-filter: blur(10px);
                animation: shake 0.5s ease-in-out;
            ">
                <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line>
                    <line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
            </div>
            <h2 style="
                color: white;
                margin: 0;
                font-size: 20px;
                font-weight: 700;
                letter-spacing: -0.3px;
                text-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            ">Quantity Limit Exceeded</h2>
        </div>
        
        <!-- Content -->
        <div style="padding: 22px 20px 20px 20px;">
            <div style="
                background: linear-gradient(135deg, #fff5f5 0%, #ffe5e5 100%);
                border-left: 4px solid #ff6b6b;
                padding: 14px 16px;
                border-radius: 10px;
                margin-bottom: 16px;
            ">
                <p style="
                    color: #2d3748;
                    margin: 0 0 10px 0;
                    font-size: 14px;
                    line-height: 1.5;
                    font-weight: 500;
                ">
                    You entered <strong style="color: #ff6b6b; font-size: 15px;">${formatNumber(enteredQty)} pieces</strong>
                </p>
                <p style="
                    color: #4a5568;
                    margin: 0;
                    font-size: 13px;
                    line-height: 1.5;
                ">
                    Available quantity: <strong style="color: #27ae60; font-size: 15px;">${formatNumber(maxQty)} pieces</strong>
                </p>
            </div>
            
            <div style="
                background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
                border-left: 4px solid #f39c12;
                padding: 12px 14px;
                border-radius: 10px;
                margin-bottom: 18px;
            ">
                <p style="
                    color: #2d3748;
                    margin: 0;
                    font-size: 12px;
                    line-height: 1.5;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                ">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#f39c12" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span>Please adjust the print quantity to <strong style="color: #27ae60;">${formatNumber(maxQty)} pieces</strong> or less to proceed.</span>
                </p>
            </div>
            
            <button onclick="closePrintQtyPopup()" style="
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                padding: 12px 30px;
                border-radius: 10px;
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                width: 100%;
                letter-spacing: 0.3px;
                text-transform: uppercase;
            " onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 10px 24px rgba(102, 126, 234, 0.5)'" 
               onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 6px 16px rgba(102, 126, 234, 0.4)'"
               onmousedown="this.style.transform='translateY(0)'"
               onmouseup="this.style.transform='translateY(-2px)'">
                Got It
            </button>
        </div>
    `;
    
    overlay.appendChild(popup);
    document.body.appendChild(overlay);
    
    // Close on overlay click
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            closePrintQtyPopup();
        }
    });
    
    // Close on Escape key
    const escapeHandler = function(e) {
        if (e.key === 'Escape') {
            closePrintQtyPopup();
            document.removeEventListener('keydown', escapeHandler);
        }
    };
    document.addEventListener('keydown', escapeHandler);
}

// Close popup
function closePrintQtyPopup() {
    const overlay = document.getElementById('printQtyPopupOverlay');
    if (overlay) {
        overlay.style.animation = 'fadeIn 0.2s ease-out reverse';
        setTimeout(() => {
            overlay.remove();
            popupShown = false;
        }, 200);
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
["cncCuttingBatch", "printMachine", "bagSizeCustom", "printQty"].forEach(id => {
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

// Load CNC cutting batches from sewing machine entries
function loadCNCCuttingBatches() {
    const batchSelect = document.getElementById('cncCuttingBatch');
    const loadingText = document.getElementById('batch_loading');
    
    if (!batchSelect) return;
    
    // Show loading state
    if (loadingText) {
        loadingText.innerHTML = `
            <div style="display: flex; align-items: center; gap: 10px; padding: 10px 16px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 10px; color: #0369a1; font-size: 0.9rem;">
                <i class="fas fa-spinner fa-spin" style="font-size: 1rem;"></i>
                <span style="font-weight: 500;">Loading batches from sewing entries...</span>
            </div>
        `;
        loadingText.style.display = 'block';
    }
    
    fetch('api/get_sewing_cnc_batches.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            console.log('API Response:', data); // Debug log
            
            if (loadingText) {
                loadingText.innerHTML = '';
                loadingText.style.display = 'none';
            }
            
            if (!data.success) {
                batchSelect.innerHTML = '<option value="">-- Failed to load batches --</option>';
                if (loadingText) {
                    loadingText.innerHTML = `
                        <div style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border: 1px solid #fca5a5; border-radius: 10px; color: #991b1b; font-size: 0.9rem; box-shadow: 0 2px 8px rgba(239, 68, 68, 0.1);">
                            <i class="fas fa-exclamation-circle" style="font-size: 1.1rem; color: #dc2626;"></i>
                            <span style="font-weight: 500;">Error loading batches. Please try again.</span>
                        </div>
                    `;
                    loadingText.style.display = 'block';
                }
                return;
            }
            
            const batches = data.batches || [];
            console.log('Batches found:', batches.length, data.debug_info); // Debug log
            batchSelect.innerHTML = '<option value="">-- Select CNC Cutting Batch --</option>';
            
            if (batches.length === 0) {
                batchSelect.innerHTML = '<option value="">-- No batches available --</option>';
                if (loadingText) {
                    loadingText.innerHTML = `
                        <div style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 1px solid #fbbf24; border-radius: 10px; color: #92400e; font-size: 0.9rem; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.1);">
                            <i class="fas fa-info-circle" style="font-size: 1.1rem; color: #d97706;"></i>
                            <div style="flex: 1;">
                                <div style="font-weight: 600; margin-bottom: 4px;">No CNC cutting batches found</div>
                                <div style="font-size: 0.85rem; opacity: 0.9;">Please create sewing machine entries first to proceed with branding entry.</div>
                            </div>
                        </div>
                    `;
                    loadingText.style.display = 'block';
                }
                return;
            }
            
            // Store batch data globally for reference number lookup
            window.batchDataMap = window.batchDataMap || {};
            
            batches.forEach(batch => {
                const option = document.createElement('option');
                option.value = batch.batch;
                
                // Store batch data for reference lookup
                window.batchDataMap[batch.batch] = batch;
                
                // Build display text: batch - references - quantity
                let displayText = batch.batch;
                
                // Add reference numbers if available
                if (batch.references && batch.references.length > 0) {
                    const refCount = batch.references.length;
                    if (refCount <= 5) {
                        // Show all references if 5 or fewer
                        displayText += ` - ${batch.references.join(', ')}`;
                    } else {
                        // Show first 2 + count if more than 5
                        displayText += ` - ${batch.references.slice(0, 2).join(', ')} +${refCount - 2} more`;
                    }
                }
                
                // Add remaining quantity (use exact cutting quantity from cnc_entries)
                const qty = batch.remaining_qty || batch.total_cutting_qty || batch.total_sewing_qty || 0;
                if (qty > 0) {
                    displayText += ` - ${formatNumber(qty)}`;
                }
                
                option.textContent = displayText;
                batchSelect.appendChild(option);
            });
        })
        .catch(error => {
            console.error('Error fetching batches:', error);
            if (loadingText) {
                loadingText.innerHTML = '';
                loadingText.style.display = 'none';
            }
            batchSelect.innerHTML = '<option value="">-- Error loading batches --</option>';
            if (loadingText) {
                loadingText.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border: 1px solid #fca5a5; border-radius: 10px; color: #991b1b; font-size: 0.9rem; box-shadow: 0 2px 8px rgba(239, 68, 68, 0.1);">
                        <i class="fas fa-exclamation-circle" style="font-size: 1.1rem; color: #dc2626;"></i>
                        <span style="font-weight: 500;">Network error. Please check your connection and try again.</span>
                    </div>
                `;
                loadingText.style.display = 'block';
            }
        });
}

// Format number helper
function formatNumber(num) {
    return new Intl.NumberFormat().format(num);
}

// Update reference number from selected batch
function updateReferenceFromBatch() {
    const batchSelect = document.getElementById('cncCuttingBatch');
    const refInput = document.getElementById('referenceNumber');
    const printQtyInput = document.getElementById('printQty');
    const availableText = document.getElementById('available_print_text');
    
    if (!batchSelect || !refInput) return;
    
    const selectedBatch = batchSelect.value;
    
    if (!selectedBatch) {
        refInput.value = '';
        if (printQtyInput) {
            printQtyInput.removeAttribute('data-max-qty');
            printQtyInput.removeAttribute('max');
        }
        if (availableText) {
            availableText.style.display = 'none';
        }
        return;
    }
    
    // Get batch data from stored map
    if (window.batchDataMap && window.batchDataMap[selectedBatch]) {
        const batch = window.batchDataMap[selectedBatch];
        
        // Update reference number
        if (batch.references && batch.references.length > 0) {
            refInput.value = batch.references.join(', ');
        } else {
            refInput.value = '';
        }
        
        // Update print quantity limit using remaining quantity (use exact cutting quantity from cnc_entries)
        const maxQty = batch.remaining_qty || batch.total_cutting_qty || batch.total_sewing_qty || 0;
        if (printQtyInput && maxQty > 0) {
            printQtyInput.setAttribute('data-max-qty', maxQty);
            printQtyInput.setAttribute('max', maxQty);
            
            // Show available quantity with modern UI
            if (availableText) {
                availableText.innerHTML = `
                    <div style="
                        background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
                        border: 1px solid #27ae60;
                        border-radius: 6px;
                        padding: 6px 10px;
                        display: inline-flex;
                        align-items: center;
                        gap: 8px;
                        box-shadow: 0 1px 4px rgba(39, 174, 96, 0.1);
                    ">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#27ae60" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0;">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        <span style="
                            color: #1e8449;
                            font-size: 11px;
                            font-weight: 600;
                        ">Available Printing Quantity:</span>
                        <span style="
                            color: #27ae60;
                            font-size: 13px;
                            font-weight: 700;
                        ">${formatNumber(maxQty)} pieces</span>
                    </div>
                `;
                availableText.style.display = 'block';
            }
            
            // Check if current quantity exceeds the new limit (but don't auto-adjust)
            const currentQty = parseInt(printQtyInput.value) || 0;
            if (currentQty > maxQty) {
                // Just validate to show warning, but don't change the value
                validatePrintQty();
            }
        } else {
            if (printQtyInput) {
                printQtyInput.removeAttribute('data-max-qty');
                printQtyInput.removeAttribute('max');
            }
            if (availableText) {
                availableText.style.display = 'none';
            }
        }
        
        // Auto-select bag size from batch data
        if (batch.bag_size) {
            autoSelectBagSize(batch.bag_size);
        }
    } else {
        refInput.value = '';
        if (printQtyInput) {
            printQtyInput.removeAttribute('data-max-qty');
            printQtyInput.removeAttribute('max');
        }
        if (availableText) {
            availableText.style.display = 'none';
        }
    }
}

// Load form dropdowns asynchronously to avoid blocking page render
function loadFormData() {
    // Load CNC cutting batches
    loadCNCCuttingBatches();
    
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
    
}
</script>
</body>
</html>

