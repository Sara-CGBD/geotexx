<?php
// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();

// Include security configuration
require_once 'security_config.php';

// Auth/session checks
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

// Role-based access control for Sheet Production module
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_ROLL_PRODUCTION, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>Access Denied</h2>
        <p>You do not have permission to access the Sheet Production module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

// Timezone
date_default_timezone_set('Asia/Dhaka');

// DB connection (shared config)
$conn = SecurityConfig::getConnection();

// Fetch projects
$projects = [];
$res = $conn->query("SELECT id, project_name FROM projects");
if ($res) {
    while ($r = $res->fetch_assoc()) $projects[] = $r;
}

// Material type is always "PP Stable Fiber"

// Operator
$operator_id = $_SESSION['user_id'];
$operator_name = $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Fiber To Roll Entry</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:0; color:#2c3e50; }
    .container { max-width:100%; margin:0; background:#fff; border-radius:0; padding:25px 80px; box-shadow:none; }
    h1 { text-align:center; font-size:28px; margin-bottom:30px; }
    .form-group { margin-bottom:20px; }
    label { font-weight:600; display:block; margin-bottom:8px; }
    input[type="text"], input[type="number"], textarea, select {
      padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px); box-sizing:border-box; font-family:'Inter',sans-serif;
    }
    textarea { resize:vertical; min-height:60px; }
    .btn-group { display:flex; flex-wrap:wrap; gap:10px; }
    .btn { padding:10px 16px; font-size:14px; border:none; border-radius:6px; cursor:pointer; background:#e0e0e0; }
    .btn:hover { background:#ccc; }
    .btn.selected { background:#3498db; color:white; }
    .btn.disabled { background:#f5f5f5; color:#ccc; opacity:0.6; cursor:not-allowed !important; }
    .btn.disabled:hover { background:#f5f5f5; }
    .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; color:#333; }
    .actions { margin-top:30px; text-align:center; }
    .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px; }
    .submit-btn { background:#2ecc71; color:white; }
    .clear-btn { background:#e74c3c; color:white; }
    .readonly { background:#ecf0f1; }
    
    /* Quantity Limit Popup Styles */
    .qty-limit-popup-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(15, 23, 42, 0.75);
      backdrop-filter: blur(8px);
      z-index: 9999;
      display: flex;
      align-items: center;
      justify-content: center;
      opacity: 0;
      visibility: hidden;
      transition: all 0.3s ease;
    }
    
    .qty-limit-popup-overlay.show {
      opacity: 1;
      visibility: visible;
    }
    
    .qty-limit-popup {
      background: white;
      border-radius: 16px;
      max-width: 400px;
      width: 90%;
      box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
      position: relative;
      transform: scale(0.9) translateY(20px);
      transition: all 0.3s ease;
      overflow: hidden;
    }
    
    .qty-limit-popup.show {
      transform: scale(1) translateY(0);
    }
    
    .qty-limit-popup-header {
      background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
      padding: 16px 20px;
      display: flex;
      align-items: center;
      gap: 12px;
      color: white;
    }
    
    .qty-limit-popup-icon {
      width: 56px;
      height: 56px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.2);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 28px;
      flex-shrink: 0;
    }
    
    .qty-limit-popup-title {
      font-size: 18px;
      font-weight: 700;
      margin: 0;
      text-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
    }
    
    .qty-limit-popup-body {
      padding: 20px 24px 24px;
    }
    
    .qty-limit-popup-message {
      font-size: 14px;
      color: #64748b;
      margin-bottom: 16px;
      line-height: 1.5;
    }
    
    .qty-limit-popup-details {
      background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
      border: 1px solid #fbbf24;
      border-radius: 12px;
      padding: 14px 16px;
      margin-bottom: 20px;
      font-size: 13px;
      color: #78350f;
    }
    
    .qty-limit-popup-details strong {
      color: #92400e;
      font-weight: 600;
      display: inline-block;
      min-width: 70px;
    }
    
    .qty-limit-popup-details-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 6px 0;
    }
    
    .qty-limit-popup-details-row:last-child {
      padding-bottom: 0;
    }
    
    .qty-limit-popup-details-row:first-child {
      padding-top: 0;
    }
    
    .qty-limit-popup-button {
      background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
      color: white;
      border: none;
      padding: 12px 32px;
      border-radius: 10px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
      box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
      width: 100%;
    }
    
    .qty-limit-popup-button:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(239, 68, 68, 0.4);
    }
    
    .qty-limit-popup-button:active {
      transform: translateY(0);
      box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
    }
    
    .qty-limit-popup-close {
      position: absolute;
      top: 12px;
      right: 12px;
      width: 28px;
      height: 28px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.2);
      border: none;
      color: #ffffff;
      font-size: 18px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s ease;
      z-index: 10;
      backdrop-filter: blur(10px);
      line-height: 1;
    }
    
    .qty-limit-popup-close:hover {
      background: rgba(255, 255, 255, 0.3);
      transform: scale(1.1);
    }
    
    .qty-limit-popup-close:active {
      transform: scale(0.95);
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>Fiber To Roll Entry</h1>

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

    <form id="rollEntryForm" method="post" action="../handlers/submit_fiber_to_roll_entry.php" onsubmit="return validateAndSubmit();">
      <input type="hidden" id="dateTime" name="date_time" />

      <!-- Operator -->
      <div class="form-group">
        <label>Operator Name:</label>
        <input type="text" name="operator_display" value="<?php echo htmlspecialchars($operator_name); ?>" readonly class="readonly">
        <input type="hidden" name="operator_id" value="<?php echo htmlspecialchars($operator_id); ?>">
      </div>

      <div class="form-group">
        <label>Reference Number:</label>
        <input type="text" id="reference_number" name="reference_number" placeholder="Will auto-generate after filling fields below" readonly class="readonly">
      </div>

      <div class="form-group">
        <label>GSM:</label>
        <input type="number" step="0.01" id="gsm" name="gsm" placeholder="e.g., 400" required>
      </div>

      <div class="form-group">
        <label>Line Number:</label>
        <div class="btn-group" id="lineNumberGroup">
          <button type="button" class="btn" data-value="1" onclick="selectBtn(this,'lineNumberGroup')">Line 1</button>
          <button type="button" class="btn" data-value="2" onclick="selectBtn(this,'lineNumberGroup')">Line 2</button>
        </div>
        <input type="hidden" id="line_no" name="line_no" value="">
        
        <!-- Bale Opener Number (shown after Line Number is selected) -->
        <div id="baleOpenerSection" style="display:none; margin-top:15px;">
          <label style="font-size:14px; color:#555;">Bale Opener Number:</label>
          <div class="btn-group" id="baleOpenerGroup">
            <button type="button" class="btn" data-value="1" onclick="selectBtn(this,'baleOpenerGroup')">1</button>
            <button type="button" class="btn" data-value="2" onclick="selectBtn(this,'baleOpenerGroup')">2</button>
            <button type="button" class="btn" data-value="3" onclick="selectBtn(this,'baleOpenerGroup')">3</button>
          </div>
          <input type="hidden" id="bale_opener_number" name="bale_opener_number" value="">
        </div>
      </div>

      <div class="form-group">
        <label>Roll Number:</label>
        <input type="number" step="1" min="1" id="roll_no" name="roll_no" placeholder="e.g., 8" required>
      </div>

      <div class="form-group">
        <label>Batch Information:</label>
        <input type="text" id="batch_info" name="batch_info" placeholder="e.g., GT9.H1" required>
      </div>

      <div class="form-group">
        <label>Bale Number:</label>
        <input type="text" id="bale_number" name="bale_number" required>
      </div>

      <div class="form-group">
        <label>Bale Weight (KG):</label>
        <input type="number" step="1" min="1" id="bale_weight" name="bale_weight" required>
      </div>

      <div class="form-group">
        <label>Project:</label>
        <div class="btn-group" id="projectGroup">
          <?php foreach($projects as $p): ?>
            <button type="button" class="btn" data-id="<?php echo $p['id']; ?>" onclick="selectBtn(this,'projectGroup')">
              <?php echo htmlspecialchars($p['project_name']); ?>
            </button>
          <?php endforeach; ?>
        </div>
        <input type="hidden" id="project_id" name="project_id" value="">
      </div>

      <div class="form-group">
        <label>Material Type:</label>
        <div class="btn-group" id="materialTypeGroup">
          <button type="button" class="btn" data-value="PP Stable Fiber" onclick="selectMaterialType(this)">PP Stable Fiber</button>
        </div>
        <input type="hidden" id="material_type" name="material_type" value="">
      </div>

      <div class="form-group">
        <label>Origin:</label>
        <div class="btn-group" id="originGroup">
          <button type="button" class="btn" data-value="Vietnam" onclick="selectBtn(this,'originGroup')">Vietnam</button>
          <button type="button" class="btn" data-value="Saudi Arabia" onclick="selectBtn(this,'originGroup')">Saudi Arabia</button>
          <button type="button" class="btn" data-value="China" onclick="selectBtn(this,'originGroup')">China</button>
          <button type="button" class="btn" data-value="BD" onclick="selectBtn(this,'originGroup')">BD</button>
        </div>
        <input type="hidden" id="origin" name="origin" value="" required>
      </div>

      <div class="form-group">
        <label>Total Weight (kg):</label>
        <input type="number" step="1" min="1" id="total_weight" name="total_weight" required oninput="validateTotalWeight()">
        <small id="available_material_text" style="display:block; margin-top:5px; font-weight:600; color:#999;">Select a material type to see available quantity</small>
        <small id="weight_warning" style="color:#e74c3c; font-weight:600; display:none; margin-top:5px;"></small>
      </div>

      <!-- Summary -->
      <div class="form-group">
        <label></label>
        <div id="summaryBox" class="summary-info">Please fill in the details above to generate a summary.</div>
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
    document.getElementById("shiftBanner").innerText = "Shift: " + ((h>=8&&h<=19)?"Day":"Night");
    updateSummary();
  }
  setInterval(updateTimeAndShift,1000);
  updateTimeAndShift();

  function selectBtn(button, groupId) {
    document.querySelectorAll(`#${groupId} .btn`).forEach(b=>b.classList.remove('selected'));
    button.classList.add('selected');
    if(groupId==="projectGroup"){
      document.getElementById("project_id").value = button.dataset.id;
    }
    if(groupId==="materialTypeGroup"){
      document.getElementById("material_type").value = button.dataset.value;
    }
    if(groupId==="originGroup"){
      document.getElementById("origin").value = button.dataset.value;
    }
    if(groupId==="lineNumberGroup"){
      document.getElementById("line_no").value = button.dataset.value;
      // Show Bale Opener section after Line Number is selected
      document.getElementById("baleOpenerSection").style.display = "block";
    }
    if(groupId==="baleOpenerGroup"){
      document.getElementById("bale_opener_number").value = button.dataset.value;
    }
    updateSummary();
    generateReferenceNumber();
  }
  
  // Function to handle material type selection
  function selectMaterialType(button) {
    // First, select the material type button
    const materialGroup = document.getElementById('materialTypeGroup');
    materialGroup.querySelectorAll('.btn').forEach(btn => btn.classList.remove('selected'));
    button.classList.add('selected');
    
    // Set the material type value - always "PP Stable Fiber"
    document.getElementById('material_type').value = 'PP Stable Fiber';
    
    // Fetch available material quantity
    fetchAvailableMaterial('PP Stable Fiber');
    
    updateSummary();
    generateReferenceNumber();
  }

  // Fetch available material quantity from fiber_entries
  async function fetchAvailableMaterial(materialType) {
    const availableText = document.getElementById('available_material_text');
    const warning = document.getElementById('weight_warning');
    
    // Show loading indicator
    availableText.textContent = ' Loading available quantity...';
    availableText.style.color = '#3498db';
    availableText.style.display = 'block';
    warning.style.display = 'none';
    
    console.log('Fetching available material for:', materialType);
    console.log('API URL:', `api/get_fiber_available_material.php?material_type=${encodeURIComponent(materialType)}`);
    
    try {
      const response = await fetch(`api/get_fiber_available_material.php?material_type=${encodeURIComponent(materialType)}`);
      console.log('Response status:', response.status);
      
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      
      const data = await response.json();
      console.log('API Response:', data);
      
      if (data.success) {
        const availableQty = parseFloat(data.available_quantity) || 0;
        console.log('Available quantity:', availableQty, 'kg');
        
        if (availableQty > 0) {
          availableText.textContent = `Available: ${availableQty.toFixed(2)} kg`;
          availableText.style.color = '#27ae60'; // Green for available stock
          availableText.style.display = 'block';
          availableText.style.fontWeight = '600';
          
          // Store for validation
          document.getElementById('total_weight').setAttribute('data-max-weight', availableQty);
        } else {
          availableText.textContent = `âš ï¸ No stock available for this material`;
          availableText.style.color = '#e74c3c'; // Red for no stock
          availableText.style.display = 'block';
          availableText.style.fontWeight = '600';
          document.getElementById('total_weight').setAttribute('data-max-weight', 0);
        }
      } else {
        throw new Error(data.error || 'Unknown error');
      }
    } catch (error) {
      console.error(' Error fetching available material:', error);
      availableText.textContent = `Error: ${error.message} (Check console)`;
      availableText.style.color = '#e74c3c';
      availableText.style.display = 'block';
      availableText.style.fontWeight = '600';
    }
  }

  let lastPopupWeight = null; // Track last weight that triggered popup to avoid repeated popups

  // Validate total weight against available quantity
  function validateTotalWeight() {
    const totalWeightInput = document.getElementById('total_weight');
    const totalWeight = parseFloat(totalWeightInput.value) || 0;
    const maxWeight = parseFloat(totalWeightInput.getAttribute('data-max-weight')) || 0;
    const warning = document.getElementById('weight_warning');
    const availableText = document.getElementById('available_material_text');
    
    console.log('âš–ï¸ Validating weight:', totalWeight, 'kg vs available:', maxWeight, 'kg');
    
    if (maxWeight > 0 && totalWeight > maxWeight) {
      // Exceeds available - show red warning
      console.log(' Weight exceeds available!');
      warning.textContent = `Total weight (${totalWeight} kg) exceeds available quantity (${maxWeight.toFixed(2)} kg)`;
      warning.style.display = 'block';
      warning.style.fontWeight = '600';
      totalWeightInput.style.borderColor = '#e74c3c';
      totalWeightInput.style.border = '2px solid #e74c3c';
      
      // Show popup notification (only once per weight value to avoid spam)
      if (lastPopupWeight !== totalWeight) {
        showQtyLimitPopup(totalWeight, maxWeight);
        lastPopupWeight = totalWeight;
      }
    } else {
      // Reset popup tracking when weight is valid
      if (totalWeight <= maxWeight) {
        lastPopupWeight = null;
      }
      
      if (maxWeight > 0) {
        // Within limit - keep available text green, hide warning
        console.log('Weight is within available quantity');
        warning.style.display = 'none';
        totalWeightInput.style.borderColor = '#ccc';
        totalWeightInput.style.border = '1px solid #ccc';
        
        // Make sure available text is green
        if (availableText) {
          availableText.style.color = '#27ae60';
          availableText.style.display = 'block';
        }
      } else {
        // No max weight set yet
        console.log('No max weight set yet');
        warning.style.display = 'none';
        totalWeightInput.style.borderColor = '#ccc';
        totalWeightInput.style.border = '1px solid #ccc';
      }
    }
  }

  function showQtyLimitPopup(enteredWeight, maxWeight) {
    const popup = document.getElementById('qtyLimitPopup');
    const overlay = document.getElementById('qtyLimitPopupOverlay');
    const message = document.getElementById('qtyLimitPopupMessage');
    const details = document.getElementById('qtyLimitPopupDetails');
    
    // Shorter, more user-friendly message
    message.textContent = `Only ${maxWeight.toFixed(2)} kg available. You entered ${enteredWeight.toFixed(2)} kg.`;
    
    // Simplified details structure
    const excess = (enteredWeight - maxWeight).toFixed(2);
    details.innerHTML = `
      <div class="qty-limit-popup-details-row">
        <strong>Available:</strong>
        <span>${maxWeight.toFixed(2)} kg</span>
      </div>
      <div class="qty-limit-popup-details-row">
        <strong>Excess:</strong>
        <span style="color: #dc2626; font-weight: 700;">${excess} kg</span>
      </div>
    `;
    
    overlay.classList.add('show');
    // Small delay to ensure overlay is rendered first
    setTimeout(() => {
        popup.classList.add('show');
    }, 10);
  }

  function closeQtyLimitPopup() {
    try {
        const popup = document.getElementById('qtyLimitPopup');
        const overlay = document.getElementById('qtyLimitPopupOverlay');
        
        if (popup && overlay) {
            popup.classList.remove('show');
            overlay.classList.remove('show');
            
            // Focus back on total weight input field
            setTimeout(() => {
                const totalWeightInput = document.getElementById('total_weight');
                if (totalWeightInput) {
                    totalWeightInput.focus();
                    totalWeightInput.select();
                }
            }, 100);
        }
    } catch (error) {
        console.error('Error closing popup:', error);
    }
  }

  // Close popup on ESC key
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const popup = document.getElementById('qtyLimitPopup');
        if (popup && popup.classList.contains('show')) {
            closeQtyLimitPopup();
        }
    }
  });

  function clearForm(){
    document.querySelectorAll('#projectGroup .btn,#materialTypeGroup .btn,#lineNumberGroup .btn,#baleOpenerGroup .btn').forEach(b=>b.classList.remove('selected'));
    document.getElementById("project_id").value='';
    document.getElementById("material_type").value='';
    document.getElementById("origin").value='';
    document.getElementById("line_no").value='';
    document.getElementById("bale_opener_number").value='';
    document.getElementById("reference_number").value='';
    // Hide Bale Opener section
    document.getElementById("baleOpenerSection").style.display = "none";
    updateTimeAndShift();
    updateSummary();
  }

  // Generate Reference Number: 4.0L225OCT21-R08-GT0.9H0.1
  function generateReferenceNumber() {
    const gsm = document.getElementById("gsm").value;
    const lineNo = document.getElementById("line_no").value;
    const rollNo = document.getElementById("roll_no").value;
    const batchInfo = document.getElementById("batch_info").value;

    console.log('Generating ref:', {gsm, lineNo, rollNo, batchInfo}); // Debug

    if (!gsm || !lineNo || !rollNo || !batchInfo) {
      document.getElementById("reference_number").value = '';
      return;
    }

    // Get current date
    const now = new Date();
    const utc = now.getTime() + (now.getTimezoneOffset()*60000);
    const dhaka = new Date(utc + (6*3600000));
    
    const year = dhaka.getFullYear().toString().slice(-2); // Last 2 digits of year (2025 -> 25)
    const monthNames = ["JAN","FEB","MAR","APR","MAY","JUN","JUL","AUG","SEP","OCT","NOV","DEC"];
    const month = monthNames[dhaka.getMonth()];
    const day = String(dhaka.getDate()).padStart(2, '0');

    // Format GSM: 300 -> 3.0, 400 -> 4.0
    const gsmFormatted = (parseFloat(gsm) / 100).toFixed(1);
    
    // Format Roll No: 3 -> R03, 8 -> R08
    const rollFormatted = 'R' + String(rollNo).padStart(2, '0');
    
    // Format Batch Info: GT9.H1 -> GT0.9H0.1 or gt9.h1 -> GT0.9H0.1
    // Convert to uppercase first
    let batchFormatted = batchInfo.toUpperCase();
    
    // Replace each number with 0.number format
    // GT9.H1 -> GT[9].H[1] -> GT0.9H0.1
    batchFormatted = batchFormatted.replace(/([A-Z]+)(\d+)/g, function(match, letters, number) {
      return letters + '0.' + number;
    });

    // Final format: 3.0L125OCT21-R03-GT0.9H0.1 (single entry, no roll suffix in fiber_to_roll)
    const refNumber = `${gsmFormatted}L${lineNo}${year}${month}${day}-${rollFormatted}-${batchFormatted}`;
    
    console.log('Generated reference:', refNumber); // Debug
    
    const refField = document.getElementById("reference_number");
    if (refField) {
      refField.value = refNumber;
      console.log('Set reference field to:', refField.value); // Debug
    } else {
      console.error('Reference number field not found!');
    }
    updateSummary();
  }

  function updateSummary() {
    const dateTime = document.getElementById("dateTime").value;
    const shift = document.getElementById("shiftBanner").innerText.replace("Shift: ","");
    const operator = "<?php echo htmlspecialchars($operator_name); ?>";
    const gsm = document.getElementById("gsm").value;
    const lineNo = document.getElementById("line_no").value;
    const baleOpener = document.getElementById("bale_opener_number").value;
    const rollNo = document.getElementById("roll_no").value;
    const batchInfo = document.getElementById("batch_info").value;
    const refNumber = document.getElementById("reference_number").value;
    const baleNumber = document.getElementById("bale_number").value;
    const baleWeight = document.getElementById("bale_weight").value;
    const projectBtn = document.querySelector("#projectGroup .btn.selected");
    const project = projectBtn ? projectBtn.innerText : "";
    const materialType = document.getElementById("material_type").value;
    const origin = document.getElementById("origin").value;
    const totalWeight = document.getElementById("total_weight").value;

    let summary = `${dateTime} | Shift: ${shift} | Operator: ${operator}`;
    if (gsm) summary += ` | GSM: ${gsm}`;
    if (lineNo) summary += ` | Line: ${lineNo}`;
    if (baleOpener) summary += ` | Bale Opener: ${baleOpener}`;
    if (rollNo) summary += ` | Roll: ${rollNo}`;
    if (batchInfo) summary += ` | Batch: ${batchInfo}`;
    if (refNumber) summary += ` | Ref: ${refNumber}`;
    if (baleNumber) summary += ` | Bale: ${baleNumber}`;
    if (baleWeight) summary += ` | Bale Wt: ${baleWeight}kg`;
    if (project) summary += ` | Project: ${project}`;
    if (materialType) summary += ` | Material: ${materialType}`;
    if (origin) summary += ` | Origin: ${origin}`;
    if (totalWeight) summary += ` | Total Wt: ${totalWeight}kg`;

    document.getElementById("summaryBox").innerText = summary;
    document.getElementById("summary").value = summary;
  }

  ["bale_number","bale_weight","gsm","roll_no","batch_info","origin","total_weight"].forEach(id=>{
    document.getElementById(id).addEventListener("input", function() {
      updateSummary();
      generateReferenceNumber();
    });
  });

  function validateAndSubmit(){
    if(!document.getElementById("gsm").value.trim()){ alert("Please fill GSM."); return false; }
    if(!document.getElementById("line_no").value){ alert("Please select Line Number."); return false; }
    if(!document.getElementById("bale_opener_number").value){ alert("Please select Bale Opener Number."); return false; }
    if(!document.getElementById("roll_no").value.trim()){ alert("Please fill Roll Number."); return false; }
    if(!document.getElementById("batch_info").value.trim()){ alert("Please fill Batch Information."); return false; }
    if(!document.getElementById("reference_number").value.trim()){ alert("Reference Number not generated."); return false; }
    if(!document.getElementById("bale_number").value.trim()){ alert("Please fill Bale Number."); return false; }
    if(!document.getElementById("bale_weight").value.trim()){ alert("Please fill Bale Weight."); return false; }
    if(!document.getElementById("project_id").value){ alert("Please select a Project."); return false; }
    if(!document.getElementById("material_type").value){ alert("Please select Material Type."); return false; }
    if(!document.getElementById("origin").value.trim()){ alert("Please fill Origin."); return false; }
    if(!document.getElementById("total_weight").value.trim()){ alert("Please fill Total Weight."); return false; }
    
    // Validate total weight against available quantity
    const totalWeightInput = document.getElementById('total_weight');
    const totalWeight = parseFloat(totalWeightInput.value) || 0;
    const maxWeight = parseFloat(totalWeightInput.getAttribute('data-max-weight')) || 0;
    
    if (maxWeight > 0 && totalWeight > maxWeight) {
      alert(`âŒ Total weight (${totalWeight} kg) exceeds available quantity (${maxWeight.toFixed(2)} kg). Please reduce the weight.`);
      return false;
    }
    
    return true;
  }
</script>

<!-- Quantity Limit Popup -->
<div id="qtyLimitPopupOverlay" class="qty-limit-popup-overlay" onclick="closeQtyLimitPopup()">
  <div id="qtyLimitPopup" class="qty-limit-popup" onclick="event.stopPropagation()">
    <button type="button" class="qty-limit-popup-close" onclick="closeQtyLimitPopup()" aria-label="Close">×</button>
    <div class="qty-limit-popup-header">
      <div class="qty-limit-popup-icon">⚠️</div>
      <h3 class="qty-limit-popup-title">Weight Limit Exceeded</h3>
    </div>
    <div class="qty-limit-popup-body">
      <p class="qty-limit-popup-message" id="qtyLimitPopupMessage"></p>
      <div class="qty-limit-popup-details" id="qtyLimitPopupDetails"></div>
      <button type="button" class="qty-limit-popup-button" onclick="closeQtyLimitPopup()">Got It</button>
    </div>
  </div>
</div>
</body>
</html>



