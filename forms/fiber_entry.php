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

// Function to generate entry number (date-based sequential format)
function generateFiberEntryNumber($conn) {
    $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
    $hour = (int)$now->format('H');
    
    // If before 8 AM, use previous day's date
    if ($hour < 8) {
        $now->modify('-1 day');
    }
    
    $dateKey = $now->format('Ymd');
    
    // Count actual entries for this day key (real-time)
    $pattern = 'FE-' . $dateKey . '-%';
    $countStmt = $conn->prepare("SELECT COUNT(*) as entry_count FROM fiber_entries WHERE entry_code LIKE ? AND (is_deleted = 0 OR is_deleted IS NULL)");
    $countStmt->bind_param("s", $pattern);
    $countStmt->execute();
    $result = $countStmt->get_result();
    
    $counter = 1;
    if ($result && $row = $result->fetch_assoc()) {
        $counter = (int)$row['entry_count'] + 1;
    }
    $countStmt->close();
    
    return sprintf("FE-%s-%03d", $dateKey, $counter);
}

// Generate next entry number for display
$display_entry_code = generateFiberEntryNumber($conn);

// Only show material issue entry references in the dropdown
// Exclude references that have already been used in fiber_entries
$approvedMaterials = [];

// Get material issue entries that haven't been fully used in fiber_entries
// Show references if the full amount hasn't been consumed yet
// Check which amount column exists in fiber_entries
$feAmountCol = 'amount_kg';
$feColCheck = $conn->query("SHOW COLUMNS FROM fiber_entries LIKE 'amount_kg'");
if (!$feColCheck || $feColCheck->num_rows == 0) {
    $feColCheck = $conn->query("SHOW COLUMNS FROM fiber_entries LIKE 'amount'");
    if ($feColCheck && $feColCheck->num_rows > 0) {
        $feAmountCol = 'amount';
    } else {
        $feColCheck = $conn->query("SHOW COLUMNS FROM fiber_entries LIKE 'total_amount'");
        if ($feColCheck && $feColCheck->num_rows > 0) {
            $feAmountCol = 'total_amount';
        }
    }
}

// Build the query to show material issue entries with remaining stock
// Remaining stock = original issued amount - what's been used in fiber_entries
$issueQuery = $conn->query("
    SELECT 
        sie.issue_number as entry_number,
        sie.material_type,
        sie.manufacturer_name,
        sie.amount_kg as original_amount,
        sie.date_time as received_date,
        'material_issue' as source,
        'issued' as approval_status,
        COALESCE((
            SELECT SUM(COALESCE(fe.$feAmountCol, 0))
            FROM fiber_entries fe
            WHERE fe.reference IS NOT NULL
              AND fe.reference != ''
              AND BINARY TRIM(fe.reference) = BINARY TRIM(sie.issue_number)
              AND (fe.is_deleted = 0 OR fe.is_deleted IS NULL)
        ), 0) as used_amount,
        -- Calculate remaining stock: original issued amount - used in fiber entries
        (sie.amount_kg - COALESCE((
            SELECT SUM(COALESCE(fe.$feAmountCol, 0))
            FROM fiber_entries fe
            WHERE fe.reference IS NOT NULL
              AND fe.reference != ''
              AND BINARY TRIM(fe.reference) = BINARY TRIM(sie.issue_number)
              AND (fe.is_deleted = 0 OR fe.is_deleted IS NULL)
        ), 0)) as remaining_amount
    FROM store_issue_entries sie
    WHERE sie.amount_kg > 0
      -- Only show entries that still have remaining stock
      AND (sie.amount_kg - COALESCE((
            SELECT SUM(COALESCE(fe.$feAmountCol, 0))
            FROM fiber_entries fe
            WHERE fe.reference IS NOT NULL
              AND fe.reference != ''
              AND BINARY TRIM(fe.reference) = BINARY TRIM(sie.issue_number)
              AND (fe.is_deleted = 0 OR fe.is_deleted IS NULL)
        ), 0)) > 0
    ORDER BY sie.date_time DESC
    LIMIT 100
");
if ($issueQuery) {
    while ($row = $issueQuery->fetch_assoc()) {
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
// Use production_category column if available, otherwise fallback to side_cut_scrap.category
$recycledTotals = [];

// Check if production_category column exists in scrap_recycle
$colCheck = $conn->query("SHOW COLUMNS FROM scrap_recycle LIKE 'production_category'");
$hasProductionCategory = $colCheck && $colCheck->num_rows > 0;

if ($hasProductionCategory) {
    // Use production_category column directly
    $recycledQuery = $conn->query("
        SELECT 
            production_category,
            COALESCE(SUM(recycled_qty), 0) as total_qty
        FROM scrap_recycle
        WHERE scrap_type = 'side_cut'
          AND production_category IS NOT NULL
          AND production_category != ''
        GROUP BY production_category
    ");
    
    if ($recycledQuery) {
        while ($row = $recycledQuery->fetch_assoc()) {
            $category = trim($row['production_category'] ?? '');
            $qty = (float)$row['total_qty'];
            
            if (stripos($category, 'Sheet Production') !== false) {
                $recycledTotals['sheet_production'] = ($recycledTotals['sheet_production'] ?? 0) + $qty;
            } elseif (stripos($category, 'Swing') !== false || stripos($category, 'Sewing Production') !== false) {
                $recycledTotals['swing'] = ($recycledTotals['swing'] ?? 0) + $qty;
            }
        }
    }
} else {
    // Fallback: query from scrap_recycle joined with side_cut_scrap
    $recycledQuery = $conn->query("
        SELECT 
            scs.category,
            COALESCE(SUM(sr.recycled_qty), 0) as total_qty
        FROM scrap_recycle sr
        INNER JOIN side_cut_scrap scs ON sr.scrap_id = scs.id AND sr.scrap_type = 'side_cut'
        WHERE sr.scrap_type = 'side_cut'
        GROUP BY scs.category
    ");
    
    if ($recycledQuery) {
        while ($row = $recycledQuery->fetch_assoc()) {
            $category = trim($row['category'] ?? '');
            $qty = (float)$row['total_qty'];
            
            if (stripos($category, 'Sheet Production') !== false) {
                $recycledTotals['sheet_production'] = ($recycledTotals['sheet_production'] ?? 0) + $qty;
            } elseif (stripos($category, 'Swing') !== false || stripos($category, 'Sewing Production') !== false) {
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
  
  /* Toast Notification Styles */
  #toastContainer {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 10000;
    display: flex;
    flex-direction: column;
    gap: 12px;
    pointer-events: none;
  }
  
  .toast {
    background: white;
    border-radius: 12px;
    padding: 18px 22px;
    min-width: 320px;
    max-width: 500px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
    display: flex;
    align-items: center;
    gap: 16px;
    pointer-events: auto;
    animation: slideInRight 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    border-left: 5px solid;
    position: relative;
    overflow: hidden;
  }
  
  .toast::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 3px;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.5), transparent);
    animation: shimmer 2s infinite;
  }
  
  .toast.success {
    background: linear-gradient(135deg, #ffffff 0%, #f0fdf4 100%);
    border-left-color: #10b981;
    box-shadow: 0 8px 24px rgba(16, 185, 129, 0.3);
  }
  
  .toast.success::before {
    background: linear-gradient(90deg, transparent, rgba(16, 185, 129, 0.3), transparent);
  }
  
  .toast.error {
    background: linear-gradient(135deg, #ffffff 0%, #fef2f2 100%);
    border-left-color: #ef4444;
    box-shadow: 0 8px 24px rgba(239, 68, 68, 0.3);
  }
  
  .toast.error::before {
    background: linear-gradient(90deg, transparent, rgba(239, 68, 68, 0.3), transparent);
  }
  
  .toast.warning {
    background: linear-gradient(135deg, #ffffff 0%, #fffbeb 100%);
    border-left-color: #f59e0b;
    box-shadow: 0 8px 24px rgba(245, 158, 11, 0.3);
  }
  
  .toast.warning::before {
    background: linear-gradient(90deg, transparent, rgba(245, 158, 11, 0.3), transparent);
  }
  
  .toast-icon {
    font-size: 24px;
    flex-shrink: 0;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
  }
  
  .toast.success .toast-icon {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
  }
  
  .toast.error .toast-icon {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
  }
  
  .toast.warning .toast-icon {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
  }
  
  .toast-message {
    flex: 1;
    font-size: 15px;
    font-weight: 500;
    line-height: 1.5;
  }
  
  .toast.success .toast-message {
    color: #065f46;
  }
  
  .toast.error .toast-message {
    color: #991b1b;
  }
  
  .toast.warning .toast-message {
    color: #92400e;
  }
  
  .toast-close {
    background: rgba(0, 0, 0, 0.05);
    border: none;
    font-size: 20px;
    font-weight: bold;
    color: #6b7280;
    cursor: pointer;
    padding: 4px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: all 0.2s ease;
  }
  
  .toast-close:hover {
    background: rgba(0, 0, 0, 0.1);
    color: #374151;
    transform: scale(1.1);
  }
  
  .toast.success .toast-close:hover {
    background: rgba(16, 185, 129, 0.1);
    color: #10b981;
  }
  
  .toast.error .toast-close:hover {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
  }
  
  .toast.warning .toast-close:hover {
    background: rgba(245, 158, 11, 0.1);
    color: #f59e0b;
  }
  
  @keyframes shimmer {
    0% {
      transform: translateX(-100%);
    }
    100% {
      transform: translateX(100%);
    }
  }
  
  @keyframes slideInRight {
    from {
      transform: translateX(100%);
      opacity: 0;
    }
    to {
      transform: translateX(0);
      opacity: 1;
    }
  }
  
  @keyframes slideOutRight {
    from {
      transform: translateX(0);
      opacity: 1;
    }
    to {
      transform: translateX(100%);
      opacity: 0;
    }
  }
  
  .toast.hiding {
    animation: slideOutRight 0.3s ease-in forwards;
  }
</style>
</head>
<body>
<div id="toastContainer" aria-live="polite" aria-atomic="true"></div>
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

    <!-- Entry Code (display only) -->
    <div class="form-group">
      <label>Entry Code:</label>
      <input type="text" id="entry_code" value="<?php echo htmlspecialchars($display_entry_code); ?>" readonly class="readonly" style="background:#e8f5e9; font-weight:bold;">
      <small style="color: #7f8c8d; font-size: 0.85em;">Auto-generated entry code</small>
    </div>

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

    <!-- Reference (From Approved QC Tests, Material Issue, or Roll Transfer) -->
    <div class="form-group">
      <label>Reference (Material Issue ):</label>
      <select id="reference" name="reference" required onchange="loadApprovedMaterial()">
        <option value="">-- Select Reference --</option>
        <?php foreach ($approvedMaterials as $mat): ?>
        <option value="<?php echo htmlspecialchars($mat['entry_number']); ?>" 
                data-material-type="<?php echo htmlspecialchars($mat['material_type']); ?>"
                data-available-amount="<?php echo $mat['remaining_amount']; ?>"
                data-source="<?php echo $mat['source']; ?>"
                data-manufacturer="<?php echo htmlspecialchars($mat['manufacturer_name'] ?? ''); ?>">
          <?php echo htmlspecialchars($mat['entry_number']); ?> - <?php echo htmlspecialchars($mat['material_type']); ?><?php if (!empty($mat['manufacturer_name'])): ?> (<?php echo htmlspecialchars($mat['manufacturer_name']); ?>)<?php endif; ?> (<?php echo number_format($mat['remaining_amount'], 2); ?> kg)
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
        <button type="button" class="btn" data-value="swing" onclick="selectRecycledType(this, 'swing')">Sewing</button>
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
      <label for="materialType">Material Type:</label>
      <div class="btn-group" id="materialTypeGroup">
        <button type="button" class="btn" data-value="PP Stable Fiber" onclick="selectBtn(this, 'materialTypeGroup')">PP Stable Fiber</button>
        <button type="button" class="btn" data-value="PSF Fiber" onclick="selectBtn(this, 'materialTypeGroup')">PSF Fiber</button>
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
        
        // Clear material type selection and re-enable buttons
        const materialTypeGroup = document.getElementById('materialTypeGroup');
        materialTypeGroup.querySelectorAll('.btn').forEach(btn => {
            btn.classList.remove('selected');
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
        });
        document.getElementById('materialType').value = '';
        
        // Remove note if exists
        const note = document.querySelector('label[for="materialType"] .note-text');
        if (note) note.remove();
        
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
    
    const materialTypeGroup = document.getElementById('materialTypeGroup');
    const materialButtons = Array.from(materialTypeGroup.querySelectorAll('.btn'));
    materialButtons.forEach(btn => btn.classList.remove('selected'));

    const normalizedMaterialType = (materialType || '').trim();
    let materialTypeBtn = materialButtons.find(btn => {
        const btnValue = (btn.dataset.value || btn.textContent).trim();
        return normalizedMaterialType && btnValue === normalizedMaterialType;
    });

    if (!materialTypeBtn) {
        materialTypeBtn = materialButtons.find(btn => (btn.dataset.value || btn.textContent).trim() === 'PP Stable Fiber');
    }
    if (!materialTypeBtn && materialButtons.length > 0) {
        materialTypeBtn = materialButtons[0];
    }

    if (materialTypeBtn) {
        materialTypeBtn.classList.add('selected');
        document.getElementById('materialType').value = (materialTypeBtn.dataset.value || materialTypeBtn.textContent).trim();
        
        // If reference is from material issue entry, disable material type buttons
        const source = selectedOption.getAttribute('data-source');
        if (source === 'material_issue') {
            materialButtons.forEach(btn => {
                btn.disabled = true;
                btn.style.opacity = '0.6';
                btn.style.cursor = 'not-allowed';
            });
            // Add a note that material type cannot be changed
            const materialTypeLabel = document.querySelector('label[for="materialType"]');
            if (materialTypeLabel && !materialTypeLabel.querySelector('.note-text')) {
                const note = document.createElement('span');
                note.className = 'note-text';
                note.style.cssText = 'color: #666; font-size: 12px; font-weight: normal; margin-left: 8px;';
                note.textContent = '(From Material Issue - Cannot be changed)';
                materialTypeLabel.appendChild(note);
            }
        } else {
            // Enable buttons if not from material issue
            materialButtons.forEach(btn => {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.style.cursor = 'pointer';
            });
            // Remove note if exists
            const note = document.querySelector('label[for="materialType"] .note-text');
            if (note) note.remove();
        }
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

// Toast Notification Function
function showToast(message, type = 'success', duration = 5000) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    
    const icon = document.createElement('span');
    icon.className = 'toast-icon';
    if (type === 'success') {
        icon.textContent = '✓';
    } else if (type === 'error') {
        icon.textContent = '✕';
    } else if (type === 'warning') {
        icon.textContent = '⚠';
    }
    
    const messageEl = document.createElement('div');
    messageEl.className = 'toast-message';
    messageEl.textContent = message;
    
    const closeBtn = document.createElement('button');
    closeBtn.className = 'toast-close';
    closeBtn.textContent = '×';
    closeBtn.setAttribute('aria-label', 'Close notification');
    closeBtn.onclick = () => removeToast(toast);
    
    toast.appendChild(icon);
    toast.appendChild(messageEl);
    toast.appendChild(closeBtn);
    container.appendChild(toast);
    
    // Auto-remove after duration
    let autoHide;
    if (duration > 0) {
        autoHide = setTimeout(() => {
            removeToast(toast);
        }, duration);
    }
    
    function removeToast(toastElement) {
        if (autoHide) {
            clearTimeout(autoHide);
        }
        toastElement.classList.add('hiding');
        setTimeout(() => {
            if (toastElement.parentNode) {
                toastElement.parentNode.removeChild(toastElement);
            }
        }, 300);
    }
}

// Fetch latest recycled availability per type
async function fetchRecycledAvailableAmount(type) {
    if (!type) return null;
    try {
        const response = await fetch(`api/get_recycled_available_material.php?type=${encodeURIComponent(type)}`);
        if (!response.ok) {
            return null;
        }
        const data = await response.json();
        if (data.success) {
            return parseFloat(data.available_amount) || 0;
        }
    } catch (error) {
        console.error('Failed to fetch recycled availability', error);
    }
    return null;
}

// Handle recycled type selection
async function selectRecycledType(button, type) {
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
        let availableQty = recycledMaterialsData[type] || 0;
        const fetchedQty = await fetchRecycledAvailableAmount(type);
        if (fetchedQty !== null) {
            availableQty = fetchedQty;
        }
        availableRecycledInput.value = availableQty.toFixed(2);
        recycledAmountInput.value = '0';
        recycledAmountInput.max = availableQty;
        
        // Show warning if no recycled material available
        if (availableQty === 0 || availableQty === null || availableQty === undefined) {
            showToast('No recycled material available for ' + (type === 'sheet_production' ? 'Sheet Production' : 'Sewing') + '. Please check the Recycle Entry module.', 'warning', 6000);
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
    // Prevent selection if button is disabled (e.g., when material type is locked from material issue entry)
    if (button.disabled || button.classList.contains('disabled')) {
        return;
    }
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
        const typeLabel = recycledType === 'sheet_production' ? 'Sheet Production' : 'Sewing';
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
    const referenceSelect = document.getElementById('reference');
    const selectedOption = referenceSelect.options[referenceSelect.selectedIndex];
    const manufacturerName = selectedOption ? (selectedOption.getAttribute('data-manufacturer') || '') : '';
    
    const data = {
        dateTime: form.dateTime.value,
        shift: form.shift.value,
        shiftIncharge: form.shiftIncharge.value,
        reference: form.reference.value,
        manufacturerName: manufacturerName,
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
        showToast("Please fill all required fields. Missing: " + missing.join(", "), 'error', 6000);
        return;
    }
    
    // Validate recycled amount doesn't exceed available
    const recycledAmount = parseFloat(data.recycledAmount) || 0;
    const availableRecycled = parseFloat(document.getElementById('availableRecycled').value) || 0;
    
    if (data.recycledType !== 'none' && recycledAmount > availableRecycled) {
        showToast("Recycled amount (" + recycledAmount + " kg) cannot exceed available quantity (" + availableRecycled + " kg). Please adjust the quantity.", 'error', 6000);
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
            console.log('Submission successful. Reference:', data.reference, 'Amount:', data.amount);
            showToast("Fiber entry submitted successfully! Entry Code: " + (resp.entry_code || 'N/A'), 'success', 5000);
            // Force a hard reload to clear cache and get fresh data with updated available quantities
            setTimeout(function() {
                window.location.href = window.location.href.split('?')[0] + '?t=' + new Date().getTime();
            }, 500);
        } else {
            showToast("Submission failed: " + (resp.message || "Unknown error"), 'error', 6000);
        }
    })
    .catch(err => {
        console.error(err);
        showToast("Error submitting entry.", 'error', 6000);
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
    showToast("Form cleared successfully!", 'success', 3000);
}
</script>
</body>
</html>

