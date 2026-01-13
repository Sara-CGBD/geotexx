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

// Role-based access control for Sheet Production module
require_once '../config/AccessControl.php';
require_once '../config/project_helper.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_ROLL_PRODUCTION, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access the Sheet Production module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// Performance: Defer project and material loading - will load asynchronously after page render
$projects = [];
$materials = [];
$conn = SecurityConfig::getConnection();

$defaultProject = getDefaultProject($conn);
$projects = $defaultProject ? [$defaultProject] : [];

// Performance: Defer approved materials loading - will load asynchronously after page render
// Only show store entries where ALL 6 raw material tests are approved
$approvedMaterials = [];
$refQuery = $conn->query("
    SELECT 
        sre.entry_number,
        sre.material_type,
        sre.amount_kg as remaining_amount,
        COALESCE(sre.original_amount_kg, sre.amount_kg) as original_amount,
        sre.date_time as received_date,
        'store' as source,
        'approved' as approval_status
    FROM store_received_entries sre
    LEFT JOIN fineness_fiber_reports ff ON sre.entry_number COLLATE utf8mb4_unicode_ci = ff.store_entry_reference AND ff.status = 'approved'
    LEFT JOIN cut_length_fiber_reports cl ON sre.entry_number COLLATE utf8mb4_unicode_ci = cl.store_entry_reference AND cl.status = 'approved'
    LEFT JOIN tenacity_fiber_reports tf ON sre.entry_number COLLATE utf8mb4_unicode_ci = tf.store_entry_reference AND tf.status = 'approved'
    LEFT JOIN tenacity_yarn_reports ty ON sre.entry_number COLLATE utf8mb4_unicode_ci = ty.store_entry_reference AND ty.status = 'approved'
    LEFT JOIN fiber_test_reports ft ON sre.entry_number COLLATE utf8mb4_unicode_ci = ft.store_entry_reference AND ft.status = 'approved'
    LEFT JOIN sewing_thread_reports st ON sre.entry_number COLLATE utf8mb4_unicode_ci = st.store_entry_reference AND st.status = 'approved'
    WHERE ff.id IS NOT NULL 
      AND cl.id IS NOT NULL 
      AND tf.id IS NOT NULL 
      AND ty.id IS NOT NULL 
      AND ft.id IS NOT NULL 
      AND st.id IS NOT NULL
      AND sre.amount_kg > 0
    GROUP BY sre.entry_number, sre.material_type, sre.amount_kg, sre.original_amount_kg, sre.date_time
    ORDER BY sre.date_time DESC
    LIMIT 100
");
if ($refQuery) {
    while ($row = $refQuery->fetch_assoc()) {
        $approvedMaterials[] = $row;
    }
}

// Fetch available recycled materials from scrap_recycle table
// Subtract amounts already used in fiber_entries
$recycledMaterials = [
    'sheet_production' => 0,
    'swing' => 0
];

// Get total recycled quantities from scrap_recycle
// Sum all recycled_qty where scrap_category matches
// Get ALL recycled quantities and their categories
// Also get scrap_type to help determine category if needed
$recycledQuery = $conn->query("
    SELECT 
        s.id as scrap_entry_id,
        s.scrap_category,
        s.scrap_type,
        s.scrap_product,
        sr.recycled_qty,
        s.is_deleted,
        sr.id as recycle_entry_id
    FROM scrap_recycle sr
    INNER JOIN scrap s ON sr.scrap_id = s.id
    ORDER BY s.id, sr.id
");

// Calculate recycled totals
$recycledTotals = [];

if ($recycledQuery) {
    while ($row = $recycledQuery->fetch_assoc()) {
        $category = trim($row['scrap_category'] ?? '');
        $qty = (float)$row['recycled_qty'];
        $isDeleted = (int)$row['is_deleted'];
        
        // Only count if not deleted
        if ($isDeleted == 0 && !empty($category)) {
            // Case-insensitive matching for scrap categories
            if (stripos($category, 'Sheet Production') !== false) {
                $recycledTotals['sheet_production'] = ($recycledTotals['sheet_production'] ?? 0) + $qty;
            } elseif (stripos($category, 'Swing') !== false && stripos($category, 'Sheet Production') === false) {
                $recycledTotals['swing'] = ($recycledTotals['swing'] ?? 0) + $qty;
            }
        }
    }
}

// Get amounts already used in fiber_entries (not deleted) with schema guard
$usedQuerySql = "SELECT 
        recycled_type,
        COALESCE(SUM(recycled_amount), 0) as total_used
    FROM fiber_entries
    WHERE is_deleted = 0
    AND recycled_amount > 0
    GROUP BY recycled_type";

// If recycled_type column is missing, skip used amounts computation
$usedQuery = $conn->query("SHOW COLUMNS FROM fiber_entries LIKE 'recycled_type'");
$usedAmounts = [];
if ($usedQuery && $usedQuery->num_rows > 0) {
    $usedQuery = $conn->query($usedQuerySql);
if ($usedQuery) {
    while ($row = $usedQuery->fetch_assoc()) {
        $usedAmounts[$row['recycled_type']] = (float)$row['total_used'];
        }
    }
}

// Calculate available amounts (total recycled - already used)
$recycledMaterials['sheet_production'] = max(0, ($recycledTotals['sheet_production'] ?? 0) - ($usedAmounts['sheet_production'] ?? 0));
$recycledMaterials['swing'] = max(0, ($recycledTotals['swing'] ?? 0) - ($usedAmounts['swing'] ?? 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fiber Received Entry</title>
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
  small {
    display: block;
    margin-top: 5px;
    font-size: 13px;
  }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .readonly { background:#ecf0f1; }
  .btn-group { display: flex; flex-wrap: wrap; gap: 10px; }
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
  <h1>Fiber Received Entry</h1>

  <!-- Back to Dashboard Link -->
  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
  </div>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <form id="fiberEntryForm">

    <!-- Auto-generated Entry ID (hidden) -->

    <!-- Hidden datetime + shift -->
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">

    <!-- Shift Incharge -->
    <div class="form-group">
      <label>Shift Incharge:</label>
      <div class="btn-group" id="shiftInchargeGroup">
        <button type="button" class="btn" data-value="Monir Hossen" onclick="selectBtn(this, 'shiftInchargeGroup')">Monir Hossen</button>
        <button type="button" class="btn" data-value="Abul Hashem" onclick="selectBtn(this, 'shiftInchargeGroup')">Abul Hashem</button>
        <button type="button" class="btn" data-value="MD.Faysal Ahmed" onclick="selectBtn(this, 'shiftInchargeGroup')">MD.Faysal Ahmed</button>
        <button type="button" class="btn" data-value="Test Entry" onclick="selectBtn(this, 'shiftInchargeGroup')">Test Entry</button>
      </div>
      <input type="hidden" id="shiftIncharge" name="shiftIncharge" value="">
    </div>

    <!-- Reference (From Approved QC Tests or Roll Transfer) -->
    <div class="form-group">
      <label>Reference (QC Approved / Roll Transfer):</label>
      <select id="reference" name="reference" required onchange="loadApprovedMaterial()">
        <option value="">-- Select Reference --</option>
        <?php foreach ($approvedMaterials as $mat): 
          $sourceLabel = ($mat['source'] === 'roll_transfer') ? '🔄 Roll Transfer' : '📦 Store';
        ?>
        <option value="<?php echo htmlspecialchars($mat['entry_number']); ?>" 
                data-material-type="<?php echo htmlspecialchars($mat['material_type']); ?>"
                data-available-amount="<?php echo $mat['remaining_amount']; ?>"
                data-source="<?php echo $mat['source']; ?>">
          [<?php echo $sourceLabel; ?>] <?php echo htmlspecialchars($mat['entry_number']); ?> - <?php echo htmlspecialchars($mat['material_type']); ?> (<?php echo number_format($mat['remaining_amount'], 2); ?> kg)
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Project -->
    <div class="form-group">
      <label>Project:</label>
      <div class="btn-group" id="projectGroup">
        <?php foreach ($projects as $index => $p): ?>
        <button type="button" class="btn <?php echo $index === 0 ? 'selected' : ''; ?>" data-value="<?php echo (int)$p['id']; ?>" onclick="selectBtn(this, 'projectGroup')">
          <?php echo htmlspecialchars($p['project_name']); ?>
        </button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" id="project" name="project" value="<?php echo isset($projects[0]['id']) ? (int)$projects[0]['id'] : ''; ?>">
    </div>

    <!-- Amount -->
    <div class="form-group">
      <label>Amount(KG):</label>
      <input type="number" id="amount" name="amount" step="0.01" min="0.01" placeholder="Enter amount" oninput="validateAmount()" required autocomplete="off">
      <small id="available_amount_text" style="color: #27ae60; font-weight: 600; display: none; margin-top: 5px;">Available in inventory: <span id="available_amount_value">0</span> kg</small>
      <small id="amount_warning" style="color: #e74c3c; font-weight: 600; display: none; margin-top: 5px;"></small>
    </div>

    <!-- Recycled Amount Type -->
    <div class="form-group">
      <label>Recycled Material From:</label>
      <div class="btn-group" id="recycledTypeGroup">
        <button type="button" class="btn" data-value="none" onclick="selectRecycledType(this, 'none')">No Recycled</button>
        <button type="button" class="btn" data-value="sheet_production" onclick="selectRecycledType(this, 'sheet_production')">Sheet Production</button>
        <button type="button" class="btn" data-value="swing" onclick="selectRecycledType(this, 'swing')">Swing</button>
      </div>
      <input type="hidden" id="recycledType" name="recycledType" value="none">
    </div>

    <!-- Recycled Amount (shown only when Production/Sewing selected) -->
    <div class="form-group" id="recycledAmountField" style="display:none;">
      <label>Available Recycled Material:</label>
      <input type="number" id="availableRecycled" readonly class="readonly" style="background:#e8f5e9; font-weight:bold; margin-bottom:10px;">
      <label>Recycled Amount to Use (KG):</label>
      <input type="number" id="recycledAmount" name="recycledAmount" step="0.01" placeholder="Enter recycled amount" value="0" min="0" oninput="validateRecycledAmount()">
      <small id="recycled_warning" style="color: #e74c3c; font-weight: 600; display: none;"></small>
    </div>

    <!-- Total Amount (Auto-calculated) -->
    <div class="form-group">
      <label>Total Amount(KG):</label>
      <input type="number" id="totalAmount" name="totalAmount" step="0.01" readonly class="readonly" style="background:#e8f5e9; font-weight:bold; font-size:16px;">
    </div>

    <!-- Material Type -->
    <div class="form-group">
      <label>Material Type:</label>
      <div class="btn-group" id="materialTypeGroup">
        <button type="button" class="btn" data-value="PP Stable Fiber" onclick="selectBtn(this, 'materialTypeGroup')">PP Stable Fiber</button>
      </div>
      <input type="hidden" id="materialType" name="materialType" value="">
    </div>

    <!-- Origin -->
    <div class="form-group">
      <label>Origin:</label>
      <div class="btn-group" id="originGroup">
        <button type="button" class="btn" data-value="Vietnam" onclick="selectBtn(this, 'originGroup')">Vietnam</button>
        <button type="button" class="btn" data-value="Saudi Arabia" onclick="selectBtn(this, 'originGroup')">Saudi Arabia</button>
        <button type="button" class="btn" data-value="China" onclick="selectBtn(this, 'originGroup')">China</button>
        <button type="button" class="btn" data-value="BD" onclick="selectBtn(this, 'originGroup')">BD</button>
      </div>
      <input type="hidden" id="origin" name="origin" value="" required>
    </div>

    <!-- Bale Weight -->
    <div class="form-group">
      <label>Bale Weight (KG):</label>
      <input type="number" id="beltWeight" name="beltWeight" step="1" min="1" placeholder="Enter bale weight" required>
    </div>

    <!-- Bale Number -->
    <div class="form-group">
      <label>Bale Number:</label>
      <input type="text" id="beltNumber" name="beltNumber" placeholder="Enter bale number" required>
    </div>

    <!-- Summary -->
    <div class="form-group">
      <label>Summary:</label>
      <div id="summaryBox" class="summary-info">Please fill in the details above to generate a summary.</div>
      <input type="hidden" id="summary" name="summary">
    </div>

    <div class="actions">
      <button type="button" onclick="submitFiberEntry()" class="submit-btn">Submit</button>
      <button type="button" onclick="clearForm()" class="clear-btn">Clear</button>
    </div>
  </form>
  
  <!-- Quantity Limit Popup -->
  <div id="qtyLimitPopupOverlay" class="qty-limit-popup-overlay" onclick="closeQtyLimitPopup()">
    <div id="qtyLimitPopup" class="qty-limit-popup" onclick="event.stopPropagation()">
      <button type="button" class="qty-limit-popup-close" onclick="closeQtyLimitPopup()" aria-label="Close">×</button>
      <div class="qty-limit-popup-header">
        <div class="qty-limit-popup-icon">⚠️</div>
        <h3 class="qty-limit-popup-title">Quantity Limit Exceeded</h3>
      </div>
      <div class="qty-limit-popup-body">
        <p class="qty-limit-popup-message" id="qtyLimitPopupMessage"></p>
        <div class="qty-limit-popup-details" id="qtyLimitPopupDetails"></div>
        <button type="button" class="qty-limit-popup-button" onclick="closeQtyLimitPopup()">Got It</button>
      </div>
    </div>
  </div>
</div>

<script>
// Store recycled materials data from PHP
const recycledMaterialsData = {
    sheet_production: <?php echo $recycledMaterials['sheet_production']; ?>,
    swing: <?php echo $recycledMaterials['swing']; ?>
};


// Store available amount for validation
let availableInventoryAmount = 0;

// Load approved material details when reference is selected
function loadApprovedMaterial() {
    const referenceSelect = document.getElementById('reference');
    const selectedOption = referenceSelect.options[referenceSelect.selectedIndex];
    
    if (referenceSelect.value === '') {
        // Reset if nothing selected
        availableInventoryAmount = 0;
        document.getElementById('available_amount_text').style.display = 'none';
        document.getElementById('amount_warning').style.display = 'none';
        document.getElementById('amount').value = '';
        document.getElementById('amount').removeAttribute('max');
        document.getElementById('amount').disabled = false;
        
        // Clear material type selection
        const materialTypeGroup = document.getElementById('materialTypeGroup');
        materialTypeGroup.querySelectorAll('.btn').forEach(btn => btn.classList.remove('selected'));
        document.getElementById('materialType').value = '';
        
        updateSummary();
        return;
    }
    
    const materialType = selectedOption.getAttribute('data-material-type');
    const availableAmount = parseFloat(selectedOption.getAttribute('data-available-amount'));
    
    // Store available amount
    availableInventoryAmount = availableAmount;
    
    // Show available amount
    document.getElementById('available_amount_value').textContent = availableAmount.toFixed(2);
    document.getElementById('available_amount_text').style.display = 'block';
    
    // Set max amount (only if availableAmount > 0, otherwise allow any input)
    if (availableAmount > 0) {
    document.getElementById('amount').max = availableAmount;
    } else {
        document.getElementById('amount').removeAttribute('max');
    }
    
    // Auto-select material type - always set to "PP Stable Fiber"
    const materialTypeGroup = document.getElementById('materialTypeGroup');
    const materialTypeBtn = materialTypeGroup.querySelector('.btn[data-value="PP Stable Fiber"]');
    if (materialTypeBtn) {
        materialTypeBtn.classList.add('selected');
        document.getElementById('materialType').value = 'PP Stable Fiber';
    }
    
    updateSummary();
}

let lastPopupAmount = null; // Track last amount that triggered popup to avoid repeated popups

// Validate amount doesn't exceed available inventory
function validateAmount() {
    const amountInput = document.getElementById('amount');
    const amount = parseFloat(amountInput.value) || 0;
    const warningElement = document.getElementById('amount_warning');
    
    // Ensure input is always enabled
    amountInput.disabled = false;
    
    if (availableInventoryAmount > 0 && amount > availableInventoryAmount) {
        warningElement.textContent = `⚠️ Amount exceeds available inventory (${availableInventoryAmount.toFixed(2)} kg)`;
        warningElement.style.display = 'block';
        amountInput.setCustomValidity('Amount exceeds available inventory');
        amountInput.style.borderColor = "#e74c3c";
        
        // Show popup notification (only once per amount value to avoid spam)
        if (lastPopupAmount !== amount) {
            showQtyLimitPopup(amount, availableInventoryAmount);
            lastPopupAmount = amount;
        }
    } else {
        // Reset popup tracking when amount is valid
        if (amount <= availableInventoryAmount) {
            lastPopupAmount = null;
        }
        
        warningElement.style.display = 'none';
        amountInput.setCustomValidity('');
        amountInput.style.borderColor = "#ccc";
    }
    
    calculateTotalAmount();
}

function showQtyLimitPopup(enteredAmount, maxAmount) {
    const popup = document.getElementById('qtyLimitPopup');
    const overlay = document.getElementById('qtyLimitPopupOverlay');
    const message = document.getElementById('qtyLimitPopupMessage');
    const details = document.getElementById('qtyLimitPopupDetails');
    
    // Shorter, more user-friendly message
    message.textContent = `Only ${maxAmount.toFixed(2)} kg available. You entered ${enteredAmount.toFixed(2)} kg.`;
    
    // Simplified details structure
    const excess = (enteredAmount - maxAmount).toFixed(2);
    details.innerHTML = `
      <div class="qty-limit-popup-details-row">
        <strong>Available:</strong>
        <span>${maxAmount.toFixed(2)} kg</span>
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
            
            // Focus back on amount input field
            setTimeout(() => {
                const amountInput = document.getElementById('amount');
                if (amountInput) {
                    amountInput.focus();
                    amountInput.select();
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

// Calculate total amount (amount + recycled amount)
function calculateTotalAmount() {
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    const recycledAmount = parseFloat(document.getElementById('recycledAmount').value) || 0;
    const total = amount + recycledAmount;
    
    document.getElementById('totalAmount').value = total.toFixed(2);
    updateSummary();
}

// Initialize total amount on page load
calculateTotalAmount();

// Ensure amount field is always enabled on page load
document.addEventListener('DOMContentLoaded', function() {
    const amountInput = document.getElementById('amount');
    if (amountInput) {
        amountInput.disabled = false;
        amountInput.removeAttribute('readonly');
    }
});

// Handle recycled type selection
function selectRecycledType(button, type) {
    const group = document.getElementById('recycledTypeGroup');
    group.querySelectorAll('.btn').forEach(btn => btn.classList.remove('selected'));
    button.classList.add('selected');
    
    document.getElementById('recycledType').value = type;
    
    const recycledAmountField = document.getElementById('recycledAmountField');
    const recycledAmountInput = document.getElementById('recycledAmount');
    const availableRecycledInput = document.getElementById('availableRecycled');
    
    if (type === 'sheet_production' || type === 'swing') {
        // Show recycled amount field with available quantity
        recycledAmountField.style.display = 'block';
        
        const availableQty = recycledMaterialsData[type] || 0;
        availableRecycledInput.value = availableQty.toFixed(2);
        recycledAmountInput.value = '0';
        recycledAmountInput.max = availableQty;
        
        // Show warning if no recycled material available
        if (availableQty === 0 || availableQty === null || availableQty === undefined) {
            alert('No recycled material available for ' + (type === 'sheet_production' ? 'Sheet Production' : 'Swing') + '.\nPlease check the Recycle Entry module.');
        }
    } else {
        // Hide recycled amount field and reset to 0
        recycledAmountField.style.display = 'none';
        recycledAmountInput.value = '0';
        availableRecycledInput.value = '0';
    }
    
    calculateTotalAmount();
    updateSummary();
}

// Validate recycled amount doesn't exceed available
function validateRecycledAmount() {
    const recycledAmount = parseFloat(document.getElementById('recycledAmount').value) || 0;
    const availableRecycled = parseFloat(document.getElementById('availableRecycled').value) || 0;
    const warning = document.getElementById('recycled_warning');
    const recycledInput = document.getElementById('recycledAmount');
    
    if (recycledAmount > availableRecycled) {
        warning.textContent = 'Cannot exceed available quantity (' + availableRecycled.toFixed(2) + ' kg)';
        warning.style.display = 'block';
        recycledInput.style.borderColor = '#e74c3c';
    } else {
        warning.style.display = 'none';
        recycledInput.style.borderColor = '#ccc';
    }
    
    calculateTotalAmount();
}

function selectBtn(button, groupId) {
    const group = document.getElementById(groupId);
    group.querySelectorAll('.btn').forEach(btn => btn.classList.remove('selected'));
    button.classList.add('selected');
    const hiddenInput = document.getElementById(groupId.replace('Group', ''));
    hiddenInput.value = button.getAttribute('data-value');
    updateSummary();
}

function updateSummary() {
    const dateTime = document.getElementById("dateTime").value;
    const shift = document.getElementById("shift").value;
    const shiftIncharge = document.getElementById("shiftIncharge").value;
    const reference = document.getElementById("reference").value;
    const projectBtn = document.querySelector("#projectGroup .btn.selected");
    const project = projectBtn ? projectBtn.innerText : "";
    const amount = document.getElementById("amount").value;
    const recycledType = document.getElementById("recycledType").value;
    const recycledAmount = document.getElementById("recycledAmount").value;
    const totalAmount = document.getElementById("totalAmount").value;
    const origin = document.getElementById("origin").value;
    const beltWeight = document.getElementById("beltWeight").value;
    const beltNumber = document.getElementById("beltNumber").value;

    const materialType = document.getElementById('materialType').value;
    
    let summary = `${dateTime} | Shift: ${shift}`;
    if (reference) summary += ` | Ref: ${reference}`;
    if (shiftIncharge) summary += ` | Incharge: ${shiftIncharge}`;
    if (project) summary += ` | Project: ${project}`;
    if (amount) summary += ` | Amount: ${amount}kg`;
    if (recycledType !== 'none' && recycledAmount && parseFloat(recycledAmount) > 0) {
        const typeLabel = recycledType === 'sheet_production' ? 'Sheet Production' : 'Swing';
        summary += ` | Recycled (${typeLabel}): ${recycledAmount}kg`;
    }
    if (totalAmount) summary += ` | Total: ${totalAmount}kg`;
    if (materialType) summary += ` | Material: ${materialType}`;
    if (origin) summary += ` | Origin: ${origin}`;
    if (beltWeight) summary += ` | Bale Wt: ${beltWeight}kg`;
    if (beltNumber) summary += ` | Bale No: ${beltNumber}`;

    document.getElementById("summaryBox").innerText = summary;
    document.getElementById("summary").value = summary;
}

["amount","beltWeight","beltNumber","origin","reference"].forEach(id=>{
  document.getElementById(id).addEventListener("input",updateSummary);
  document.getElementById(id).addEventListener("change",updateSummary);
});

function submitFiberEntry() {
    const form = document.getElementById('fiberEntryForm');
    const data = {
        dateTime: form.dateTime.value,
        shift: form.shift.value,
        shiftIncharge: form.shiftIncharge.value,
        reference: form.reference.value,
        project: form.project.value,
        amount: form.amount.value,
        recycledType: form.recycledType.value,
        recycledAmount: form.recycledAmount.value,
        totalAmount: form.totalAmount.value,
        materialType: form.materialType.value,
        origin: form.origin.value,
        beltWeight: form.beltWeight.value,
        beltNumber: form.beltNumber.value,
        summary: form.summary.value
    };

    // Debug: Check which fields are missing
    const missing = [];
    if (!data.reference) missing.push("Reference");
    if (!data.shiftIncharge) missing.push("Shift Incharge");
    if (!data.project) missing.push("Project");
    if (!data.amount) missing.push("Amount");
    if (!data.materialType) missing.push("Material Type");
    if (!data.origin) missing.push("Origin");
    if (!data.beltWeight) missing.push("Bale Weight");
    if (!data.beltNumber) missing.push("Bale Number");
    
    if (missing.length > 0) {
        alert("Please fill all required fields. Missing: " + missing.join(", "));
        return;
    }
    
    // Validate recycled amount doesn't exceed available
    const recycledAmount = parseFloat(data.recycledAmount) || 0;
    const availableRecycled = parseFloat(document.getElementById('availableRecycled').value) || 0;
    
    if (data.recycledType !== 'none' && recycledAmount > availableRecycled) {
        alert("Recycled amount (" + recycledAmount + " kg) cannot exceed available quantity (" + availableRecycled + " kg).\nPlease adjust the quantity.");
        return;
    }

    fetch('../handlers/submit_fiber_entry.php', {
        method:'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(data)
    })
    .then(res => res.json())
    .then(resp => {
        if (resp.success === true || resp.status === 'success') {
            alert("Fiber entry submitted successfully!");
            // Reload the page to get fresh data and update available quantities
            window.location.reload();
        } else {
            alert("Submission failed: " + (resp.message || "Unknown error"));
        }
    })
    .catch(err => {
        console.error(err);
        alert("Error submitting entry.");
    });
}

function clearForm() {
    const form = document.getElementById('fiberEntryForm');
    form.reset();

    document.querySelectorAll('.btn').forEach(btn => btn.classList.remove('selected'));
    
    // Reset available amount tracking
    availableInventoryAmount = 0;
    document.getElementById('available_amount_text').style.display = 'none';
    document.getElementById('amount_warning').style.display = 'none';
    document.getElementById('amount').max = '';
    document.getElementById('amount').setCustomValidity('');
    
    // Reset recycled amount field
    document.getElementById('recycledAmountField').style.display = 'none';
    document.getElementById('recycled_warning').style.display = 'none';
    
    updateTimeAndShift();
    updateSummary();
    alert("Form cleared successfully!");
}
</script>
</body>
</html>

