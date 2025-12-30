<?php
// bom_entry.php

session_start();
require_once '../config/security_config.php';

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

// Role-based access control for Planning module (BOM is part of planning)
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_PLANNING, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>ðŸš« Access Denied</h2>
        <p>You do not have permission to access the Planning module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// DB connection
$conn = SecurityConfig::getConnection();

// Next BOM id
$next_bom_id = 1;
$res = $conn->query("SELECT MAX(id) AS bid FROM bom");
if ($res && ($row = $res->fetch_assoc())) {
    $next_bom_id = ($row['bid'] ?? 0) + 1;
}

// Fetch products (FG)
$products = [];
$r = $conn->query("SELECT id, product_name FROM fg WHERE product_name NOT IN ('Finished Product - 70x110','Finished Product - 1125mmX900mm')");
if ($r) {
    while ($row = $r->fetch_assoc()) $products[] = $row;
}

// Fetch materials (only active ones) for name->id mapping
$materials = [];
$m = $conn->query("SELECT id, material_name FROM materials WHERE is_deleted = 0 ORDER BY material_name ASC");
if ($m) {
    while ($row = $m->fetch_assoc()) $materials[] = $row;
}

// Fetch material types dynamically from materials table for dropdown
$materialTypeOptions = [];
foreach ($materials as $mat) {
    $materialTypeOptions[] = $mat['material_name'];
}

// Ensure unique values
$materialTypeOptions = array_values(array_unique($materialTypeOptions));

// Handle quick bag size addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_add_bag'])) {
    $bag_size = trim($_POST['quick_bag_size']);
    $gsm = floatval($_POST['quick_gsm']);
    $thickness = floatval($_POST['quick_thickness']);
    $bag_capacity = trim($_POST['quick_capacity']);
    
    // Check if exists
    $check = $conn->prepare("SELECT id FROM bag_size_master WHERE bag_size = ? AND gsm = ? AND thickness = ?");
    $check->bind_param('sdd', $bag_size, $gsm, $thickness);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows == 0) {
        $stmt = $conn->prepare("INSERT INTO bag_size_master (bag_size, gsm, thickness, bag_capacity, unit_price) VALUES (?, ?, ?, ?, 0)");
        $stmt->bind_param('sdds', $bag_size, $gsm, $thickness, $bag_capacity);
        $stmt->execute();
        $stmt->close();
        
        // Notify Product Pricing to refresh
        echo "<script>localStorage.setItem('bagSizeAddedFromBOM', 'true');</script>";
    }
    $check->close();
}

// Fetch distinct bag sizes from bag_size_master table
$bagSizes = [];
$q2 = $conn->query("SELECT DISTINCT bag_size FROM bag_size_master WHERE bag_size IS NOT NULL AND bag_size <> ''");
if ($q2) { while ($r = $q2->fetch_assoc()) { $bagSizes[] = $r['bag_size']; } }

$bagSizeOptions = array_values(array_unique(array_filter($bagSizes)));
sort($bagSizeOptions);

// Predefined roll sizes (same as roll_entry.php)
$predefinedRollSizes = [
    'GEOCIL-50 (4X100MTR)',
    'GEOCIL-60 (4X100MTR)',
    'GEOCIL-70 (4X90 MTR)',
    'GEOCIL-80 (4X75 MTR)',
    'GEOCIL-90 (4X70 MTR)',
    'GEOCIL-100 (4X60 MTR)',
    'GEOCIL-110 (4X60 MTR)',
    'GEOCIL-70 (4.06X35.5 MTR)',
    'GEOCIL 70 (2X2 MTR)',
    'GEOCIL-70 (4.5X35.5 MTR)',
    'GEOCIL 100 (3.93X24 MTR)',
    'GEOCIL 20 (100X4 MTR)',
    'GEOCIL-70 (4.08X50.8 MTR)',
    'GEOCIL-40 (4X100 MTR)',
    'GEOCIL 70 (4.06X35.5 MTR)(White)',
    'Geocil-70 | 4.06x51 Mtr)'
];

// Fetch additional roll sizes from roll_entry table (in case new ones were added)
$dbRollSizes = [];
$rollQuery = $conn->query("SELECT DISTINCT roll_size FROM roll_entry WHERE roll_size IS NOT NULL AND roll_size <> '' AND is_deleted = 0 ORDER BY roll_size");
if ($rollQuery) { 
    while ($r = $rollQuery->fetch_assoc()) { 
        $dbRollSizes[] = $r['roll_size']; 
    } 
}

// Merge predefined and database roll sizes, remove duplicates
$rollSizes = array_values(array_unique(array_merge($predefinedRollSizes, $dbRollSizes)));

// Weight is now fetched from bag_size_master table via API
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>BOM Entry</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:0; color:#2c3e50; }
  .container { max-width:100%; margin:0; background:#fff; border-radius:0; padding:25px 80px; box-shadow:none;}
  h1 { text-align:center; font-size:28px; margin-bottom:30px; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  input[type="text"], input[type="number"], select {
    padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px);
  }
  .readonly { background:#ecf0f1; }
  .btn-group { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:10px; }
  .btn { padding:10px 16px; font-size:14px; border:none; border-radius:6px; cursor:pointer; background:#e0e0e0; }
  .btn.selected { background:#3498db; color:#fff; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
</style>
</head>
<body>
<div class="container">
  
  <h1>BOM Entry</h1>

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
      â† Back to Dashboard
    </a>
  </div>

  <form id="bomForm" method="post" action="../handlers/submit_bom_entry.php" onsubmit="return validateForm();">

    <!-- Hidden datetime + shift -->
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">

    <!-- BOM ID -->
    <div class="form-group">
      <label>BOM ID: </label>
      <input type="text" value="<?php echo $next_bom_id; ?>" readonly class="readonly">
      <input type="hidden" name="bom_id" value="<?php echo $next_bom_id; ?>">
    </div>

    

    <!-- Product Type (Roll or Bag) -->
    <div class="form-group">
      <label>Product Type: </label>
      <div class="btn-group" id="productTypeGroup">
        <button type="button" class="btn" onclick="selectProductType('bag', this)" style="padding: 12px 30px; font-size: 16px;">Bag</button>
        <button type="button" class="btn" onclick="selectProductType('roll', this)" style="padding: 12px 30px; font-size: 16px;">Roll</button>
      </div>
      <input type="hidden" name="product_type" id="product_type" value="">
    </div>

    <!-- Material Type -->
    <div class="form-group">
      <label>Material Type: </label>
      <div class="btn-group" id="materialGroup">
        <?php foreach($materialTypeOptions as $mt): ?>
        <button type="button" class="btn" data-name="<?php echo htmlspecialchars($mt); ?>" onclick="selectMaterialByName(this)">
          <?php echo htmlspecialchars($mt); ?>
        </button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="material_id" id="material_id">
      <input type="hidden" name="material_name" id="material_name">
    </div>

    <!-- Unit Price removed - now handled in Product Pricing page -->
    <input type="hidden" name="unit_price" value="0">


    <!-- Bag Size Section (shown when Bag is selected) -->
    <div class="form-group" id="bagSizeSection" style="display: none;">
      <label style="display: flex; justify-content: space-between; align-items: center;">
        <span>Bag Size:</span>
        <div style="display: flex; gap: 10px;">
          <button type="button" onclick="toggleQuickAdd()" style="font-size: 12px; color: #27ae60; background: none; border: none; cursor: pointer; padding: 0;">
            <i class="fas fa-plus-circle"></i> Quick Add
          </button>
          <a href="../admin/product_pricing.php" target="_blank" id="addBagSizeLink" style="font-size: 12px; color: #3498db; text-decoration: none;">
            <i class="fas fa-external-link-alt"></i> Manage All
          </a>
        </div>
      </label>
      
      <!-- Quick Add Form -->
      <div id="quickAddForm" style="display: none; background: #e8f5e9; padding: 15px; border-radius: 6px; margin-bottom: 10px;">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 10px; margin-bottom: 10px;">
          <input type="text" id="quick_bag_size" placeholder="Bag Size*" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-size: 12px;">
          <input type="number" id="quick_gsm" placeholder="GSM*" step="0.01" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-size: 12px;">
          <input type="number" id="quick_thickness" placeholder="Thickness*" step="0.01" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-size: 12px;">
          <input type="text" id="quick_capacity" placeholder="Capacity*" style="padding: 6px; border: 1px solid #ccc; border-radius: 4px; font-size: 12px;">
        </div>
        <div style="display: flex; gap: 8px;">
          <button type="button" onclick="submitQuickAdd()" style="padding: 6px 15px; background: #27ae60; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
            <i class="fas fa-check"></i> Add
          </button>
          <button type="button" onclick="toggleQuickAdd()" style="padding: 6px 15px; background: #95a5a6; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
            Cancel
          </button>
        </div>
      </div>
      
      <div class="btn-group" id="bagSizeGroup">
        <?php foreach($bagSizeOptions as $sz): ?>
        <button type="button" class="btn" data-size="<?php echo htmlspecialchars($sz); ?>" onclick="selectBagSizeBtn(this)">
          <?php echo htmlspecialchars($sz); ?>
        </button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="bag_size" id="bag_size">
    </div>

    <!-- Roll Size Section (shown when Roll is selected) -->
    <div class="form-group" id="rollSizeSection" style="display: none;">
      <label>Roll Size:</label>
      <div class="btn-group" id="rollSizeGroup">
        <?php foreach($rollSizes as $rs): ?>
        <button type="button" class="btn" data-size="<?php echo htmlspecialchars($rs); ?>" onclick="selectRollSizeBtn(this)">
          <?php echo htmlspecialchars($rs); ?>
        </button>
        <?php endforeach; ?>
      </div>
      <input type="text" id="roll_size_custom" name="roll_size_custom" placeholder="Or enter custom roll size..." style="margin-top: 10px; padding: 10px; border: 1px solid #ccc; border-radius: 6px; width: calc(100% - 22px);">
      <input type="hidden" name="roll_size" id="roll_size">
    </div>

    <!-- GSM Options (dynamically populated) - Only for Bag -->
    <div class="form-group" id="gsmSection" style="display: none;">
      <div id="gsmOptions"></div>
      <input type="hidden" name="gsm" id="gsm">
    </div>

    <!-- Thickness Options (dynamically populated) - Only for Bag -->
    <div class="form-group" id="thicknessSection" style="display: none;">
      <div id="thicknessOptions"></div>
      <input type="hidden" name="thickness_mm" id="thickness_mm">
    </div>

    <!-- Roll GSM (manual entry for Roll) -->
    <div class="form-group" id="rollGsmSection" style="display: none;">
      <label>GSM:</label>
      <input type="number" step="0.01" min="0" name="roll_gsm" id="roll_gsm" placeholder="Enter GSM" oninput="updateSummary()" style="padding: 10px; border: 1px solid #ccc; border-radius: 6px; width: calc(100% - 22px);">
    </div>

    <!-- Weight (kg) -->
    <div class="form-group" id="weightSection">
      <label>Weight (kg): </label>
      <input type="number" step="0.01" min="0" name="weight" id="weight" required oninput="updateSummary()" readonly class="readonly">
      <small id="weightHint" style="color:#666;">Auto-filled based on bag size, GSM and thickness</small>
    </div>

    <!-- Roll Weight (manual entry for Roll) -->
    <div class="form-group" id="rollWeightSection" style="display: none;">
      <label>Weight (kg): </label>
      <input type="number" step="0.01" min="0" name="roll_weight" id="roll_weight" placeholder="Enter weight" oninput="updateSummary()" style="padding: 10px; border: 1px solid #ccc; border-radius: 6px; width: calc(100% - 22px);">
    </div>

    <!-- Cost -->
    <div class="form-group">
      <label id="costLabel">Material Cost (tk): </label>
      <input type="number" step="0.01" min="0" name="cost" id="cost" required oninput="updateSummary()">
      <small id="costHint" style="color:#666;">Enter the material cost for this configuration. Selling price is set in Product Pricing page.</small>
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

function selectBtn(btn, groupId){
  document.querySelectorAll(`#${groupId} .btn`).forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  updateSummary();
}

function selectProductType(type, btn) {
  document.querySelectorAll('#productTypeGroup .btn').forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('product_type').value = type;
  
  // Toggle sections based on product type
  const bagSection = document.getElementById('bagSizeSection');
  const rollSection = document.getElementById('rollSizeSection');
  const gsmSection = document.getElementById('gsmSection');
  const thicknessSection = document.getElementById('thicknessSection');
  const rollGsmSection = document.getElementById('rollGsmSection');
  const weightSection = document.getElementById('weightSection');
  const rollWeightSection = document.getElementById('rollWeightSection');
  const costLabel = document.getElementById('costLabel');
  const costHint = document.getElementById('costHint');
  
  if (type === 'bag') {
    bagSection.style.display = 'block';
    rollSection.style.display = 'none';
    rollGsmSection.style.display = 'none';
    weightSection.style.display = 'block';
    rollWeightSection.style.display = 'none';
    costLabel.textContent = 'Material Cost per Bag (tk):';
    costHint.textContent = 'Enter the material cost for this bag configuration.';
    
    // Clear roll values
    document.getElementById('roll_size').value = '';
    document.getElementById('roll_size_custom').value = '';
    document.getElementById('roll_gsm').value = '';
    document.getElementById('roll_weight').value = '';
  } else if (type === 'roll') {
    bagSection.style.display = 'none';
    rollSection.style.display = 'block';
    gsmSection.style.display = 'none';
    thicknessSection.style.display = 'none';
    rollGsmSection.style.display = 'block';
    weightSection.style.display = 'none';
    rollWeightSection.style.display = 'block';
    costLabel.textContent = 'Material Cost per Roll (tk):';
    costHint.textContent = 'Enter the material cost for this roll configuration.';
    
    // Clear bag values
    document.getElementById('bag_size').value = '';
    document.getElementById('gsm').value = '';
    document.getElementById('thickness_mm').value = '';
    document.getElementById('weight').value = '';
    document.querySelectorAll('#bagSizeGroup .btn').forEach(b=>b.classList.remove('selected'));
  }
  
  updateSummary();
}

function selectRollSizeBtn(btn) {
  document.querySelectorAll('#rollSizeGroup .btn').forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  const rollSize = btn.dataset.size || '';
  document.getElementById('roll_size').value = rollSize;
  document.getElementById('roll_size_custom').value = '';
  updateSummary();
}

function selectMaterialByName(btn){
  document.querySelectorAll('#materialGroup .btn').forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('material_name').value = btn.dataset.name || '';
  document.getElementById('material_id').value = '';
  updateSummary();
}

async function selectBagSizeBtn(btn){
  document.querySelectorAll('#bagSizeGroup .btn').forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  const bagSize = btn.dataset.size || '';
  document.getElementById('bag_size').value = bagSize;
  
  // Hide thickness by default
  document.getElementById('thicknessSection').style.display = 'none';
  document.getElementById('thickness_mm').value = '';
  
  // Clear GSM and weight
  document.getElementById('gsm').value = '';
  document.getElementById('weight').value = '';
  
  // Fetch available GSM and thickness options for this bag size
  try {
    const response = await fetch(`api/fetch_bag_options.php?bag_size=${encodeURIComponent(bagSize)}`);
    const data = await response.json();
    
    if (data.gsmList && data.gsmList.length > 0) {
      // There are multiple GSM options, show as buttons
      const gsmContainer = document.getElementById('gsmOptions');
      gsmContainer.innerHTML = '<label>GSM:</label><div class="btn-group" id="gsmGroup"></div>';
      data.gsmList.forEach(gsm => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn';
        btn.textContent = gsm;
        btn.onclick = () => selectGSM(gsm, btn);
        document.getElementById('gsmGroup').appendChild(btn);
      });
      
      // Show GSM options
      document.getElementById('gsmSection').style.display = 'block';
      
      // Auto-select first GSM if only one option
      if (data.gsmList.length === 1) {
        document.getElementById('gsm').value = data.gsmList[0];
        const firstGSMBtn = document.getElementById('gsmGroup').querySelector('.btn');
        if (firstGSMBtn) {
          firstGSMBtn.classList.add('selected');
          // Trigger GSM selection to fetch thickness and weight
          selectGSM(data.gsmList[0], firstGSMBtn);
        }
      }
    }
    
    if (data.hasThickness) {
      document.getElementById('thicknessSection').style.display = 'block';
    }
    
    // Fetch weight immediately after bag size selection (even without GSM/thickness)
    await calculateWeight();
    
  } catch(e) {
    console.error('Failed to fetch bag options:', e);
    // Still try to fetch weight even if options fetch fails
    await calculateWeight();
  }
  
  updateSummary();
}

async function selectGSM(gsm, btn){
  document.querySelectorAll('#gsmGroup .btn').forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('gsm').value = gsm;
  
  // Fetch thickness options for this GSM
  const bagSize = document.getElementById('bag_size').value;
  if (!bagSize) {
    console.error('Bag size not selected');
    return;
  }
  
  try {
    const response = await fetch(`api/fetch_thickness_options.php?bag_size=${encodeURIComponent(bagSize)}&gsm=${gsm}`);
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }
    const data = await response.json();
    
    if (data.thicknessList && data.thicknessList.length > 0) {
      // Show thickness options as buttons
      const thicknessContainer = document.getElementById('thicknessOptions');
      if (!thicknessContainer) {
        console.error('thicknessOptions container not found');
        return;
      }
      thicknessContainer.innerHTML = '<label>Thickness:</label><div class="btn-group" id="thicknessGroup"></div>';
      data.thicknessList.forEach(thickness => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn';
        btn.textContent = thickness + 'mm';
        btn.onclick = () => selectThickness(thickness, btn);
        document.getElementById('thicknessGroup').appendChild(btn);
      });
      document.getElementById('thicknessSection').style.display = 'block';
      
      // Auto-select if only one thickness option
      if (data.thicknessList.length === 1) {
        document.getElementById('thickness_mm').value = data.thicknessList[0];
        const firstBtn = document.getElementById('thicknessGroup').querySelector('.btn');
        if (firstBtn) {
          firstBtn.classList.add('selected');
        }
        // Trigger weight calculation after auto-selecting thickness
        await calculateWeight();
      }
    } else {
      // No thickness options, hide the section
      document.getElementById('thicknessSection').style.display = 'none';
      document.getElementById('thickness_mm').value = '';
    }
  } catch(e) {
    console.error('Failed to fetch thickness options:', e);
    document.getElementById('thicknessSection').style.display = 'none';
  }
  
  await calculateWeight();
  updateSummary();
}

async function selectThickness(thickness, btn){
  document.querySelectorAll('#thicknessGroup .btn').forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('thickness_mm').value = thickness;
  await calculateWeight();
  updateSummary();
}

async function calculateWeight(){
  const bagSize = document.getElementById('bag_size').value;
  const weightInput = document.getElementById('weight');
  const gsm = parseFloat(document.getElementById('gsm').value) || 0;
  const thickness = parseFloat(document.getElementById('thickness_mm').value) || 0;
  
  if (!bagSize) {
    weightInput.value = '';
    return;
  }
  
  // Fetch weight from bag_size_master table
  try {
    if (gsm > 0 && thickness > 0) {
      // Both GSM and thickness provided
      const response = await fetch(`api/fetch_bag_weight.php?bag_size=${encodeURIComponent(bagSize)}&gsm=${gsm}&thickness=${thickness}`);
      const data = await response.json();
      if (data.weight) {
        weightInput.value = data.weight;
        return;
      }
    }
    
    if (gsm > 0) {
      // Only GSM provided
      const response = await fetch(`api/fetch_bag_weight.php?bag_size=${encodeURIComponent(bagSize)}&gsm=${gsm}`);
      const data = await response.json();
      if (data.weight) {
        weightInput.value = data.weight;
        return;
      }
    }
    
    // Get default weight for this bag size (first entry)
    const response = await fetch(`api/fetch_bag_weight.php?bag_size=${encodeURIComponent(bagSize)}`);
    const data = await response.json();
    if (data.weight) {
      weightInput.value = data.weight;
    } else {
      weightInput.value = '';
    }
  } catch(e) {
    console.error('Failed to fetch weight:', e);
    weightInput.value = '';
  }
}

function updateSummary(){
  const dateTime = document.getElementById('dateTime').value;
  const bomId = document.querySelector('input[name="bom_id"]').value;
  const productType = document.getElementById('product_type').value;
  const materialText = document.querySelector('#materialGroup .btn.selected')?.textContent?.trim() || '';
  const unitPrice = document.querySelector('input[name="unit_price"]').value;
  const cost = document.querySelector('input[name="cost"]').value;
  
  // Get size and weight based on product type
  let sizeValue = '';
  let weightValue = '';
  let gsmValue = '';
  
  if (productType === 'roll') {
    sizeValue = document.getElementById('roll_size').value || document.getElementById('roll_size_custom').value;
    weightValue = document.getElementById('roll_weight').value;
    gsmValue = document.getElementById('roll_gsm').value;
  } else {
    sizeValue = document.getElementById('bag_size').value;
    weightValue = document.getElementById('weight').value;
    gsmValue = document.getElementById('gsm').value;
  }

  if (dateTime) {
    let s = `${dateTime}`;
    if (bomId) s += ` | BOM ID: ${bomId}`;
    if (productType) s += ` | Type: ${productType.charAt(0).toUpperCase() + productType.slice(1)}`;
    if (materialText) s += ` | Material: ${materialText}`;
    if (sizeValue) s += ` | Size: ${sizeValue}`;
    if (gsmValue) s += ` | GSM: ${gsmValue}`;
    if (weightValue) s += ` | Weight: ${weightValue} kg`;
    if (cost) s += ` | Cost: tk${cost}`;
    document.getElementById('summaryBox').innerText = s;
    document.getElementById('summary').value = s;
  } else {
    document.getElementById('summaryBox').innerText = '';
    document.getElementById('summary').value = '';
  }
}

function clearForm(){
  document.querySelectorAll('#productTypeGroup .btn, #materialGroup .btn, #bagSizeGroup .btn, #rollSizeGroup .btn').forEach(b=>b.classList.remove('selected'));
  document.getElementById("product_type").value="";
  document.getElementById("material_id").value="";
  document.getElementById("material_name").value="";
  document.getElementById('bag_size').value = '';
  document.getElementById('roll_size').value = '';
  document.getElementById('roll_size_custom').value = '';
  document.getElementById('gsm').value = '';
  document.getElementById('roll_gsm').value = '';
  document.getElementById('thickness_mm').value = '';
  document.getElementById('thicknessSection').style.display = 'none';
  document.getElementById('gsmSection').style.display = 'none';
  document.getElementById('bagSizeSection').style.display = 'none';
  document.getElementById('rollSizeSection').style.display = 'none';
  document.getElementById('rollGsmSection').style.display = 'none';
  document.getElementById('weightSection').style.display = 'block';
  document.getElementById('rollWeightSection').style.display = 'none';
  document.getElementById('weight').value = '';
  document.getElementById('roll_weight').value = '';
  document.getElementById('cost').value = '';
  document.getElementById('summaryBox').innerText = '';
  document.getElementById('summary').value = '';
}

// Add event listeners for dynamic updates
document.addEventListener('DOMContentLoaded', function(){
  document.getElementById('cost').addEventListener('input', updateSummary);
  document.getElementById('gsm').addEventListener('input', calculateWeight);
  document.getElementById('thickness_mm').addEventListener('input', calculateWeight);
  
  // Roll size custom input listener
  const rollCustomInput = document.getElementById('roll_size_custom');
  if (rollCustomInput) {
    rollCustomInput.addEventListener('input', function() {
      if (this.value.trim()) {
        document.getElementById('roll_size').value = this.value.trim();
        document.querySelectorAll('#rollSizeGroup .btn').forEach(b=>b.classList.remove('selected'));
      }
      updateSummary();
    });
  }
  
  // Roll weight and GSM listeners
  const rollWeightInput = document.getElementById('roll_weight');
  if (rollWeightInput) {
    rollWeightInput.addEventListener('input', updateSummary);
  }
  
  const rollGsmInput = document.getElementById('roll_gsm');
  if (rollGsmInput) {
    rollGsmInput.addEventListener('input', updateSummary);
  }
  
  // Initial summary update
  setTimeout(updateSummary, 100);
});

function validateForm(){
  const productType = document.getElementById('product_type').value;
  
  if (!productType) {
    alert("Please select a product type (Bag or Roll)."); 
    return false;
  }
  
  if(!(document.getElementById("material_id").value || document.getElementById('material_name').value)){
    alert("Select a material type."); 
    return false;
  }
  
  if (productType === 'bag') {
    const bagSize = document.getElementById('bag_size').value;
    const gsm = document.getElementById('gsm').value;
    const thickness = document.getElementById('thickness_mm').value;
    const weight = document.getElementById('weight').value;
    
    if (!bagSize) {
      alert("Please select a bag size.");
      return false;
    }
    
    if (!gsm || parseFloat(gsm) <= 0) {
      alert("Please select GSM from the options");
      return false;
    }
    
    if (!thickness || parseFloat(thickness) <= 0) {
      alert("Please select Thickness from the options");
      return false;
    }
    
    if (!weight || parseFloat(weight) <= 0) {
      alert("Weight is required for bag");
      return false;
    }
  } else if (productType === 'roll') {
    const rollSize = document.getElementById('roll_size').value || document.getElementById('roll_size_custom').value;
    const rollWeight = document.getElementById('roll_weight').value;
    
    if (!rollSize) {
      alert("Please select or enter a roll size.");
      return false;
    }
    
    if (!rollWeight || parseFloat(rollWeight) <= 0) {
      alert("Please enter weight for the roll.");
      return false;
    }
  }
  
  const cost = document.getElementById('cost').value;
  if (!cost || parseFloat(cost) <= 0) {
    alert("Please enter material cost.");
    return false;
  }
  
  return true;
}

// Quick Add Toggle
function toggleQuickAdd() {
  const form = document.getElementById('quickAddForm');
  form.style.display = form.style.display === 'none' ? 'block' : 'none';
}

// Submit Quick Add
function submitQuickAdd() {
  const bag_size = document.getElementById('quick_bag_size').value.trim();
  const gsm = document.getElementById('quick_gsm').value;
  const thickness = document.getElementById('quick_thickness').value;
  const capacity = document.getElementById('quick_capacity').value.trim();
  
  if (!bag_size || !gsm || !thickness || !capacity) {
    alert('Please fill all fields');
    return;
  }
  
  // Create form and submit
  const form = document.createElement('form');
  form.method = 'POST';
  form.innerHTML = `
    <input type="hidden" name="quick_add_bag" value="1">
    <input type="hidden" name="quick_bag_size" value="${bag_size}">
    <input type="hidden" name="quick_gsm" value="${gsm}">
    <input type="hidden" name="quick_thickness" value="${thickness}">
    <input type="hidden" name="quick_capacity" value="${capacity}">
  `;
  document.body.appendChild(form);
  
  // Set flag for Product Pricing to refresh
  localStorage.setItem('bagSizeAddedFromBOM', 'true');
  
  form.submit();
}

// Auto-refresh bag sizes when returning to tab
(function() {
  // When user clicks "Manage All", set a flag
  document.getElementById('addBagSizeLink').addEventListener('click', function() {
    localStorage.setItem('bagSizeAdded', 'pending');
  });
  
  // Check when page becomes visible again
  document.addEventListener('visibilitychange', function() {
    if (!document.hidden && localStorage.getItem('bagSizeAdded') === 'pending') {
      refreshBagSizes();
    }
  });
  
  window.addEventListener('focus', function() {
    if (localStorage.getItem('bagSizeAdded') === 'pending') {
      refreshBagSizes();
    }
  });
  
  function refreshBagSizes() {
    fetch('api/get_bag_sizes.php')
      .then(response => response.json())
      .then(data => {
        if (data.success && data.bagSizes) {
          updateBagSizeButtons(data.bagSizes);
          localStorage.removeItem('bagSizeAdded');
          showNotification('âœ“ Bag sizes updated!');
        }
      })
      .catch(error => console.error('Error:', error));
  }
  
  function updateBagSizeButtons(bagSizes) {
    const container = document.getElementById('bagSizeGroup');
    const currentSelection = document.getElementById('bag_size').value;
    
    container.innerHTML = '';
    bagSizes.forEach(size => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn';
      if (size === currentSelection) btn.classList.add('selected');
      btn.setAttribute('data-size', size);
      btn.textContent = size;
      btn.onclick = function() { selectBagSizeBtn(this); };
      container.appendChild(btn);
    });
  }
  
  function showNotification(message) {
    const notification = document.createElement('div');
    notification.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #27ae60; color: white; padding: 15px 20px; border-radius: 5px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 9999; font-size: 14px;';
    notification.innerHTML = '<i class="fas fa-check-circle"></i> ' + message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
      notification.style.transition = 'opacity 0.3s';
      notification.style.opacity = '0';
      setTimeout(() => notification.remove(), 300);
    }, 3000);
  }
})();
</script>
</body>
</html>


