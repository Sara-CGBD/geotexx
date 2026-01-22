<?php
session_start();
require_once '../config/security_config.php';

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit;
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Manufacturer Names
$manufacturerNames = [
    'Natpet',
    'APT',
    'Texofib',
    'Hubei Botao',
    'Jiangsu Botao',
    'Taizhu Hailun',
    'PSF',
    'Other'
];

$message = '';
$error = '';

// Create table if not exists
$createTableSQL = "CREATE TABLE IF NOT EXISTS store_issue_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    issue_number VARCHAR(100) UNIQUE NOT NULL,
    date_time DATETIME NOT NULL,
    shift VARCHAR(50) NOT NULL,
    manufacturer_name VARCHAR(255) NOT NULL,
    material_type VARCHAR(255) NOT NULL,
    amount_kg DECIMAL(10,2) NOT NULL,
    store_entry_reference VARCHAR(100),
    deduction_details TEXT,
    reported_by VARCHAR(255) NOT NULL,
    reporter_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_issue_number (issue_number),
    INDEX idx_date_time (date_time),
    INDEX idx_store_entry (store_entry_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$conn->query($createTableSQL);

// Add deduction_details column if it doesn't exist (for existing tables)
$checkColumn = $conn->query("SHOW COLUMNS FROM store_issue_entries LIKE 'deduction_details'");
if ($checkColumn && $checkColumn->num_rows == 0) {
    $conn->query("ALTER TABLE store_issue_entries ADD COLUMN deduction_details TEXT AFTER store_entry_reference");
}

// Add material_request_number column if it doesn't exist
$checkRequestColumn = $conn->query("SHOW COLUMNS FROM store_issue_entries LIKE 'material_request_number'");
if ($checkRequestColumn && $checkRequestColumn->num_rows == 0) {
    $conn->query("ALTER TABLE store_issue_entries ADD COLUMN material_request_number VARCHAR(30) DEFAULT NULL AFTER deduction_details");
}

// Get URL parameters for prefilling
$prefillManufacturer = isset($_GET['manufacturer']) ? htmlspecialchars($_GET['manufacturer']) : '';
$prefillMaterialType = isset($_GET['material_type']) ? htmlspecialchars($_GET['material_type']) : '';
$prefillAmount = isset($_GET['amount']) ? htmlspecialchars($_GET['amount']) : '';
$prefillRequestNumber = isset($_GET['request_number']) ? htmlspecialchars($_GET['request_number']) : '';

// Function to generate issue number
function generateIssueNumber($conn) {
    $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
    $hour = (int)$now->format('H');
    
    if ($hour < 8) {
        $now->modify('-1 day');
    }
    
    $dateKey = $now->format('Ymd');
    $pattern = 'MIE-' . $dateKey . '-%';
    $countStmt = $conn->prepare("SELECT COUNT(*) as entry_count FROM store_issue_entries WHERE issue_number LIKE ?");
    $countStmt->bind_param("s", $pattern);
    $countStmt->execute();
    $result = $countStmt->get_result();
    
    $counter = 1;
    if ($result && $row = $result->fetch_assoc()) {
        $counter = (int)$row['entry_count'] + 1;
    }
    $countStmt->close();
    
    return sprintf("MIE-%s-%03d", $dateKey, $counter);
}

$display_issue_number = generateIssueNumber($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Material Issue Entry</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
    .container { max-width:1200px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08); }
    h1 { text-align:center; font-size:28px; margin-bottom:30px; color:#2c3e50; }
    .form-group { margin-bottom:20px; }
    label { font-weight:600; display:block; margin-bottom:8px; }
    input[type="text"], input[type="number"], select, textarea { padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px); }
    .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
    .actions { margin-top:30px; text-align:center; }
    .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px; }
    .submit-btn { background:#2ecc71; color:#fff; }
    .clear-btn { background:#e74c3c; color:#fff; }
    .readonly { background:#ecf0f1; }
    .btn-group { display:flex; flex-wrap:wrap; gap:10px; margin-top:8px; }
    .btn { padding:10px 16px; border:2px solid #ddd; background:#fff; border-radius:6px; cursor:pointer; font-size:14px; font-weight:500; transition:all 0.3s; color:#555; }
    .btn:hover { border-color:#3498db; color:#3498db; }
    .btn.selected { background:#3498db; color:#fff; border-color:#3498db; }
    .btn:disabled, .btn.disabled { background:#e0e0e0; color:#9e9e9e; cursor:not-allowed; opacity:0.6; }
    .available-amount { background:#e8f5e9; padding:10px; border-radius:6px; margin-top:8px; color:#2e7d32; font-weight:600; }
    .error-amount { background:#ffebee; padding:10px; border-radius:6px; margin-top:8px; color:#c62828; font-weight:600; }
    select:disabled { background:#f5f5f5; color:#666; cursor:not-allowed; opacity:0.7; }
    
    /* Modern Popup Notification Styles */
    .qty-limit-popup-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(15, 23, 42, 0.75);
      backdrop-filter: blur(8px);
      z-index: 9999;
      display: none;
      animation: fadeInOverlay 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    
    .qty-limit-popup-overlay.show {
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    @keyframes fadeInOverlay {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    
    .qty-limit-popup {
      position: relative;
      background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
      border-radius: 20px;
      padding: 0;
      box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.25), 
                  0 0 0 1px rgba(231, 76, 60, 0.1);
      z-index: 10000;
      max-width: 400px;
      width: 90%;
      text-align: center;
      animation: popupSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
      display: none;
      overflow: hidden;
    }
    
    .qty-limit-popup.show {
      display: block;
    }
    
    @keyframes popupSlideIn {
      from {
        opacity: 0;
        transform: scale(0.9) translateY(-20px);
      }
      to {
        opacity: 1;
        transform: scale(1) translateY(0);
      }
    }
    
    .qty-limit-popup-header {
      background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
      padding: 20px 24px 16px;
      position: relative;
      overflow: hidden;
    }
    
    .qty-limit-popup-icon-wrapper {
      position: relative;
      z-index: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 56px;
      height: 56px;
      background: rgba(255, 255, 255, 0.2);
      border-radius: 50%;
      margin-bottom: 12px;
      backdrop-filter: blur(10px);
    }
    
    .qty-limit-popup-icon {
      font-size: 32px;
      color: #ffffff;
      filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
    }
    
    .qty-limit-popup-title {
      font-size: 18px;
      font-weight: 700;
      color: #ffffff;
      margin: 0;
      position: relative;
      z-index: 1;
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
  <h1>Material Issue Entry</h1>

  <?php if (isset($_GET['success'])): ?>
    <div style="background:#d4edda;color:#155724;padding:12px;border-radius:6px;border:1px solid #c3e6cb;margin-bottom:15px;">
      <?php echo htmlspecialchars($_GET['success']); ?>
    </div>
  <?php endif; ?>
  
  <?php if (isset($_GET['error'])): ?>
    <div style="background:#f8d7da;color:#721c24;padding:12px;border-radius:6px;border:1px solid #f5c6cb;margin-bottom:15px;">
      ❌ <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
  </div>
  
  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>
  
  <form method="POST" id="materialIssueForm" action="../handlers/submit_material_issue_entry.php">
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">
    
    <div class="form-group">
      <label>Issue Number:</label>
      <input type="text" value="<?php echo htmlspecialchars($display_issue_number); ?>" class="readonly" readonly>
    </div>
    
    <div class="form-group">
      <label>Manufacturer Name:<?php if ($prefillRequestNumber): ?> <span style="color:#666; font-size:12px;">(From Material Request - Cannot be changed)</span><?php endif; ?></label>
      <select name="manufacturerName" id="manufacturerName" <?php echo $prefillRequestNumber ? 'disabled' : ''; ?> required>
        <option value="">-- Select Manufacturer --</option>
        <?php foreach ($manufacturerNames as $mfr): ?>
        <option value="<?php echo htmlspecialchars($mfr); ?>" <?php echo ($prefillManufacturer === $mfr) ? 'selected' : ''; ?>><?php echo htmlspecialchars($mfr); ?></option>
        <?php endforeach; ?>
      </select>
      <?php if ($prefillRequestNumber): ?>
      <input type="hidden" name="manufacturerName" value="<?php echo htmlspecialchars($prefillManufacturer); ?>">
      <?php endif; ?>
    </div>
    
    <div class="form-group">
      <label>Material Type:<?php if ($prefillRequestNumber): ?> <span style="color:#666; font-size:12px;">(From Material Request - Cannot be changed)</span><?php endif; ?></label>
    <div class="btn-group" id="materialTypeGroup">
        <button type="button" class="btn <?php echo ($prefillRequestNumber) ? 'disabled' : ''; ?>" data-value="PP Stable Fiber" <?php echo ($prefillRequestNumber) ? 'disabled' : 'onclick="selectBtn(this, \'materialTypeGroup\')"'; ?>>PP Stable Fiber</button>
        <button type="button" class="btn <?php echo ($prefillRequestNumber) ? 'disabled' : ''; ?>" data-value="PSF Fiber" <?php echo ($prefillRequestNumber) ? 'disabled' : 'onclick="selectBtn(this, \'materialTypeGroup\')"'; ?>>PSF Fiber</button>
      </div>
      <input type="hidden" id="materialType" name="materialType" value="<?php echo htmlspecialchars($prefillMaterialType); ?>" required>
      <?php if ($prefillRequestNumber): ?>
      <input type="hidden" name="materialRequestNumber" value="<?php echo htmlspecialchars($prefillRequestNumber); ?>">
      <?php endif; ?>
    </div>
    
    <div class="form-group">
      <label>Issue Quantity (kg):</label>
      <input type="number" name="amountKg" id="amountKg" step="0.01" min="0.01" placeholder="Enter issue quantity in kg" value="<?php echo htmlspecialchars($prefillAmount); ?>" required>
      <div id="availableAmountDisplay"></div>
    </div>
    
    <div id="summarySection" style="background:#e8f5e9; border:1px solid #4caf50; padding:15px; border-radius:8px; margin-bottom:20px; display:none;">
      <h3 style="margin:0 0 10px 0; color:#2e7d32; font-size:18px;">Summary - Please Review Before Submitting</h3>
      <div id="summaryContent" style="font-size:14px; line-height:1.8; color:#333;"></div>
    </div>
    
    <div class="actions">
      <button type="submit" name="submit_entry" class="submit-btn">Submit</button>
      <button type="button" class="clear-btn" onclick="clearForm()">Clear</button>
    </div>
  </form>
</div>

<!-- Quantity Limit Exceeded Popup -->
<div id="qtyLimitPopupOverlay" class="qty-limit-popup-overlay" onclick="closeQtyLimitPopup()">
  <div id="qtyLimitPopup" class="qty-limit-popup" onclick="event.stopPropagation()">
    <button class="qty-limit-popup-close" onclick="closeQtyLimitPopup()" title="Close">×</button>
    <div class="qty-limit-popup-header">
      <div class="qty-limit-popup-icon-wrapper">
        <div class="qty-limit-popup-icon">⚠️</div>
      </div>
      <div class="qty-limit-popup-title">Quantity Limit Exceeded</div>
    </div>
    <div class="qty-limit-popup-body">
      <div class="qty-limit-popup-message" id="qtyLimitPopupMessage">
        The issue quantity exceeds the available stock.
      </div>
      <div class="qty-limit-popup-details" id="qtyLimitPopupDetails"></div>
      <button class="qty-limit-popup-button" onclick="closeQtyLimitPopup()">
        <span>Got It</span>
      </button>
    </div>
  </div>
</div>

<script>
let availableAmount = 0;

function updateTimeAndShift() {
    const now = new Date();
    const dhaka = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Dhaka' }));
    
    const year = dhaka.getFullYear();
    const month = String(dhaka.getMonth() + 1).padStart(2, '0');
    const day = String(dhaka.getDate()).padStart(2, '0');
    const hours = String(dhaka.getHours()).padStart(2, '0');
    const minutes = String(dhaka.getMinutes()).padStart(2, '0');
    const seconds = String(dhaka.getSeconds()).padStart(2, '0');
    
    const dateTimeStr = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
    document.getElementById("dateTime").value = dateTimeStr;
    
    const displayStr = dhaka.toLocaleString('en-US', {
        weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
        hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
    });
    document.getElementById("dateTimeDisplay").innerText = displayStr;
    
    const h = dhaka.getHours();
    const shift = (h >= 8 && h <= 19) ? "Day" : "Night";
    document.getElementById("shiftBanner").innerText = "Shift: " + shift;
    document.getElementById("shift").value = shift;
    
    if (document.getElementById('manufacturerName').value || document.getElementById('materialType').value) {
        updateSummary();
    }
}
setInterval(updateTimeAndShift, 1000);
updateTimeAndShift();

function selectBtn(btn, groupId) {
    // Prevent selection if button is disabled
    if (btn.disabled || btn.classList.contains('disabled')) {
        return;
    }
    
    const group = document.getElementById(groupId);
    const buttons = group.querySelectorAll('.btn');
    buttons.forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    
    const hiddenInput = document.getElementById(groupId.replace('Group', ''));
    if (hiddenInput) {
        hiddenInput.value = btn.getAttribute('data-value');
    }
    
    checkAvailableAmount();
    updateSummary();
}

function setMaterialTypeSelection(preferredType = 'PP Stable Fiber') {
    const normalizedTarget = (preferredType || '').trim();
    const buttons = Array.from(document.querySelectorAll('#materialTypeGroup .btn'));
    let targetBtn = buttons.find(btn => ((btn.dataset.value || btn.innerText).trim()) === normalizedTarget);
    if (!targetBtn) {
        targetBtn = buttons.find(btn => ((btn.dataset.value || btn.innerText).trim()) === 'PP Stable Fiber');
    }
    if (!targetBtn && buttons.length > 0) {
        targetBtn = buttons[0];
    }
    if (targetBtn) {
        selectBtn(targetBtn, 'materialTypeGroup');
    }
}

async function checkAvailableAmount() {
    const manufacturer = document.getElementById('manufacturerName').value;
    const materialType = document.getElementById('materialType').value;
    const displayDiv = document.getElementById('availableAmountDisplay');
    
    if (!manufacturer || !materialType) {
        displayDiv.innerHTML = '';
        availableAmount = 0;
        return;
    }
    
    try {
        const response = await fetch(`api/get_approved_available_material.php?manufacturer=${encodeURIComponent(manufacturer)}&material_type=${encodeURIComponent(materialType)}`);
        const data = await response.json();
        
        if (data.success) {
            availableAmount = parseFloat(data.available_amount || 0);
            if (availableAmount > 0) {
                displayDiv.innerHTML = `<div class="available-amount">Available Amount (Approved): ${availableAmount.toFixed(2)} kg</div>`;
            } else {
                displayDiv.innerHTML = '<div class="error-amount">No approved inventory available for this manufacturer and material type</div>';
            }
            validateAmount();
        } else {
            availableAmount = 0;
            displayDiv.innerHTML = `<div class="error-amount">Error: ${data.message || 'Could not fetch available amount'}</div>`;
        }
    } catch (error) {
        availableAmount = 0;
        displayDiv.innerHTML = '<div class="error-amount">Error fetching available amount</div>';
    }
}

let lastPopupAmount = null; // Track last amount that triggered popup to avoid repeated popups

function validateAmount() {
    const amountInput = document.getElementById('amountKg');
    const amount = parseFloat(amountInput.value) || 0;
    const displayDiv = document.getElementById('availableAmountDisplay');
    
    if (amount > availableAmount && availableAmount > 0) {
        displayDiv.innerHTML = `<div class="error-amount">Available Amount: ${availableAmount.toFixed(2)} kg - Cannot exceed available amount!</div>`;
        amountInput.setCustomValidity('Amount cannot exceed available amount');
        amountInput.style.borderColor = "#e74c3c";
        
        // Show popup notification (only once per amount value to avoid spam)
        if (lastPopupAmount !== amount) {
            showQtyLimitPopup(amount, availableAmount);
            lastPopupAmount = amount;
        }
    } else {
        // Reset popup tracking when amount is valid
        if (amount <= availableAmount) {
            lastPopupAmount = null;
        }
        
        if (amount > 0) {
            displayDiv.innerHTML = `<div class="available-amount">Available Amount: ${availableAmount.toFixed(2)} kg</div>`;
            amountInput.style.borderColor = "#27ae60";
        } else {
            displayDiv.innerHTML = '';
            amountInput.style.borderColor = "#ccc";
        }
        amountInput.setCustomValidity('');
    }
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
    const popup = document.getElementById('qtyLimitPopup');
    const overlay = document.getElementById('qtyLimitPopupOverlay');
    
    popup.classList.remove('show');
    overlay.classList.remove('show');
    
    // Focus back on amount input field
    const amountInput = document.getElementById('amountKg');
    if (amountInput) {
        amountInput.focus();
        amountInput.select();
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

function updateSummary() {
    const dateTime = document.getElementById('dateTime').value;
    const shift = document.getElementById('shift').value;
    const manufacturer = document.getElementById('manufacturerName').value;
    const materialType = document.getElementById('materialType').value;
    const amount = document.getElementById('amountKg').value;
    
    const summarySection = document.getElementById('summarySection');
    const summaryContent = document.getElementById('summaryContent');
    
    if (manufacturer && materialType && amount && parseFloat(amount) > 0) {
        summarySection.style.display = 'block';
        
        let summary = '<strong>Date & Time:</strong> ' + (dateTime ? new Date(dateTime).toLocaleString('en-US', {
            weekday: 'short', year: 'numeric', month: 'short', day: 'numeric',
            hour: '2-digit', minute: '2-digit', hour12: true
        }) : 'N/A') + '<br>';
        summary += '<strong>Shift:</strong> ' + (shift || 'N/A') + '<br>';
        summary += '<strong>Manufacturer:</strong> ' + manufacturer + '<br>';
        summary += '<strong>Material Type:</strong> ' + materialType + '<br>';
        summary += '<strong>Amount:</strong> ' + parseFloat(amount).toFixed(2) + ' kg<br>';
        summary += '<strong>Available:</strong> ' + availableAmount.toFixed(2) + ' kg';
        
        summaryContent.innerHTML = summary;
    } else {
        summarySection.style.display = 'none';
    }
}

function clearForm() {
    document.getElementById('materialIssueForm').reset();
    document.querySelectorAll('.btn.selected').forEach(btn => btn.classList.remove('selected'));
    document.getElementById('materialType').value = '';
    document.getElementById('availableAmountDisplay').innerHTML = '';
    availableAmount = 0;
    updateTimeAndShift();
    updateSummary();
}

document.getElementById('manufacturerName').addEventListener('change', function() {
    checkAvailableAmount();
    updateSummary();
});

document.getElementById('materialType').addEventListener('change', function() {
    checkAvailableAmount();
    updateSummary();
});

document.getElementById('amountKg').addEventListener('input', function() {
    validateAmount();
    updateSummary();
});

document.getElementById('materialIssueForm').addEventListener('submit', function(e) {
    const materialType = document.getElementById('materialType').value;
    const amountKg = parseFloat(document.getElementById('amountKg').value);
    
    if (!materialType) {
        e.preventDefault();
        alert('Please select a material type.');
        return false;
    }
    
    if (amountKg <= 0) {
        e.preventDefault();
        alert('Amount must be greater than 0 kg.');
        return false;
    }
    
    if (amountKg > availableAmount) {
        e.preventDefault();
        alert('Amount cannot exceed available approved amount of ' + availableAmount.toFixed(2) + ' kg.');
        return false;
    }
});

document.addEventListener('DOMContentLoaded', function() {
    // Check if form is prefilled from material request
    const isFromRequest = <?php echo $prefillRequestNumber ? 'true' : 'false'; ?>;
    
    // Prefill form if URL parameters are present
    <?php if ($prefillMaterialType): ?>
    const prefillMaterialType = '<?php echo htmlspecialchars($prefillMaterialType, ENT_QUOTES); ?>';
    if (prefillMaterialType) {
        setMaterialTypeSelection(prefillMaterialType);
        // If from request, disable all material type buttons after selection
        if (isFromRequest) {
            document.querySelectorAll('#materialTypeGroup .btn').forEach(btn => {
                btn.disabled = true;
                btn.classList.add('disabled');
            });
        }
    }
    <?php endif; ?>
    
    <?php if ($prefillManufacturer): ?>
    const prefillManufacturer = '<?php echo htmlspecialchars($prefillManufacturer, ENT_QUOTES); ?>';
    if (prefillManufacturer) {
        const manufacturerSelect = document.getElementById('manufacturerName');
        manufacturerSelect.value = prefillManufacturer;
        if (isFromRequest) {
            manufacturerSelect.disabled = true;
        }
        checkAvailableAmount();
    }
    <?php endif; ?>
    
    <?php if ($prefillAmount): ?>
    const prefillAmount = parseFloat('<?php echo htmlspecialchars($prefillAmount, ENT_QUOTES); ?>');
    if (prefillAmount > 0) {
        document.getElementById('amountKg').value = prefillAmount;
        validateAmount();
    }
    <?php endif; ?>
    
    // Update summary if prefilled
    <?php if ($prefillManufacturer && $prefillMaterialType && $prefillAmount): ?>
    updateSummary();
    <?php endif; ?>
    
    // Only set default if not from request
    if (!isFromRequest) {
        setMaterialTypeSelection('PP Stable Fiber');
    }
});
</script>
</body>
</html>

