<?php
session_start();
require_once 'security_config.php';

// Security/session checks
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

// Role-based access control for QC module
require_once '../config/AccessControl.php';
$userRole = strtolower(trim($_SESSION['role'] ?? ''));
$hasAccess = AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_QC, AccessControl::PERMISSION_ENTRY);
             
if (!$hasAccess) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access the Roll QC Report.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Helper to fetch column collation safely
function getColumnCollation(mysqli $conn, string $table, string $column): ?string {
    $stmt = $conn->prepare("
        SELECT COLLATION_NAME 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
          AND TABLE_NAME = ? 
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("ss", $table, $column);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $result = $stmt->get_result();
    $collation = null;
    if ($result && $row = $result->fetch_assoc()) {
        $collation = $row['COLLATION_NAME'] ?? null;
    }
    $stmt->close();
    return $collation;
}

// Determine a common collation for reference_number comparison
$ftrCollation = getColumnCollation($conn, 'fiber_to_roll_entry', 'reference_number');
$rqcCollation = getColumnCollation($conn, 'roll_qc_reports', 'reference_number');
$collation = $ftrCollation ?: $rqcCollation ?: 'utf8mb4_unicode_ci';
// Basic safety: allow only expected collation patterns
if (!preg_match('/^[0-9A-Za-z_]+$/', $collation)) {
    $collation = 'utf8mb4_unicode_ci';
}

// If collations differ, align roll_qc_reports.reference_number to fiber_to_roll_entry's collation when possible
if ($ftrCollation && $rqcCollation && $ftrCollation !== $rqcCollation) {
    $conn->query("ALTER TABLE roll_qc_reports MODIFY reference_number VARCHAR(100) CHARACTER SET utf8mb4 COLLATE {$collation}");
}

// Ensure roll_qc_reports table exists (some schemas may miss this table)
$conn->query("CREATE TABLE IF NOT EXISTS roll_qc_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_number VARCHAR(100) NOT NULL,
    roll_no VARCHAR(50) NULL,
    line_no VARCHAR(50) NULL,
    product_amount DECIMAL(12,2) DEFAULT 0,
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reference_number (reference_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Fetch all reference numbers with roll numbers from gsm_roll_entry only
// Only amounts that have passed through GSM and Roll Entry are shown (not raw fiber input entries)
$references = [];

// Check if gsm_roll_entry table exists
$tableExists = false;
$tableCheck = $conn->query("SHOW TABLES LIKE 'gsm_roll_entry'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $tableExists = true;
}

// IMPORTANT: Only show amounts that have passed through GSM and Roll Entry
// This ensures we track the exact amount that was processed, not the full amount from fiber input entries
// The gsm_roll_entry.total_weight represents the sum of weights from selected fiber input entries
// that were actually used in GSM and Roll Entry

// First, get all gsm_roll_entry records (primary source - these are the references generated in GSM and Roll Input)
if ($tableExists) {
    $gsmQuery = $conn->query("
        SELECT 
            g.reference as reference_number, 
            g.roll_no, 
            g.line_number as line_no,
            COALESCE(g.total_weight, 0) as original_weight
        FROM gsm_roll_entry g
        WHERE g.reference IS NOT NULL 
            AND g.reference != ''
            AND g.roll_no IS NOT NULL
        ORDER BY g.created_at DESC
    ");
    
    if ($gsmQuery) {
        while ($row = $gsmQuery->fetch_assoc()) {
            $referenceNumber = $row['reference_number'] ?? '';
            $rollNo = $row['roll_no'] ?? null;
            
            if (empty($referenceNumber)) {
                continue;
            }
            
            // Get original weight from gsm_roll_entry (this is the amount that passed through GSM and Roll Entry)
            // This weight is calculated from the selected fiber input entries when the GSM Roll Entry was created
            $originalWeight = isset($row['original_weight']) ? (float)$row['original_weight'] : 0;
            
            // If total_weight is 0 or null, calculate from fiber_input_entries JSON
            // This ensures we get the exact amount that was selected and passed through GSM and Roll Entry
            if ($originalWeight <= 0) {
                // Fetch the fiber_input_entries JSON to get entry IDs - using prepared statement
                $fiberStmt = $conn->prepare("
                    SELECT fiber_input_entries 
                    FROM gsm_roll_entry 
                    WHERE reference = ? 
                    AND roll_no = ?
                    LIMIT 1
                ");
                if ($fiberStmt) {
                    $fiberStmt->bind_param("si", $referenceNumber, $rollNo);
                    $fiberStmt->execute();
                    $fiberResult = $fiberStmt->get_result();
                    if ($fiberResult && $fiberRow = $fiberResult->fetch_assoc()) {
                        $fiberEntries = json_decode($fiberRow['fiber_input_entries'] ?? '[]', true);
                        if (is_array($fiberEntries) && !empty($fiberEntries)) {
                            // Build list of entry IDs for prepared statement
                            $entryIds = [];
                            foreach ($fiberEntries as $entry) {
                                if (isset($entry['entry_id']) && !empty($entry['entry_id'])) {
                                    $entryIds[] = $entry['entry_id'];
                                }
                            }
                            
                            // Fetch total weights from fiber_to_roll_entry table using prepared statement
                            if (!empty($entryIds)) {
                                $placeholders = str_repeat('?,', count($entryIds) - 1) . '?';
                                $weightStmt = $conn->prepare("
                                    SELECT COALESCE(SUM(total_weight), 0) as total_weight_sum
                                    FROM fiber_to_roll_entry
                                    WHERE entry_id IN ($placeholders)
                                ");
                                if ($weightStmt) {
                                    $types = str_repeat('s', count($entryIds));
                                    $weightStmt->bind_param($types, ...$entryIds);
                                    $weightStmt->execute();
                                    $weightResult = $weightStmt->get_result();
                                    if ($weightResult && $weightRow = $weightResult->fetch_assoc()) {
                                        $originalWeight = (float)$weightRow['total_weight_sum'];
                                        
                                        // Update the gsm_roll_entry record with calculated weight using prepared statement
                                        if ($originalWeight > 0) {
                                            $updateStmt = $conn->prepare("
                                                UPDATE gsm_roll_entry 
                                                SET total_weight = ?
                                                WHERE reference = ? 
                                                AND roll_no = ?
                                            ");
                                            if ($updateStmt) {
                                                $updateStmt->bind_param("dsi", $originalWeight, $referenceNumber, $rollNo);
                                                $updateStmt->execute();
                                                $updateStmt->close();
                                            }
                                        }
                                    }
                                    $weightStmt->close();
                                }
                            }
                        }
                    }
                    $fiberStmt->close();
                }
            }
            
            // Calculate used amount from roll_qc_reports - using prepared statement
            if ($rollNo === null || $rollNo === '') {
                $usedStmt = $conn->prepare("
                    SELECT COALESCE(SUM(product_amount), 0) as used_amount
                    FROM roll_qc_reports 
                    WHERE reference_number = ?
                    AND (roll_no IS NULL OR roll_no = '')
                ");
                if ($usedStmt) {
                    $usedStmt->bind_param("s", $referenceNumber);
                    $usedStmt->execute();
                    $usedResult = $usedStmt->get_result();
                    $usedAmount = 0;
                    if ($usedResult && $usedRow = $usedResult->fetch_assoc()) {
                        $usedAmount = (float)$usedRow['used_amount'];
                    }
                    $usedStmt->close();
                } else {
                    $usedAmount = 0;
                }
            } else {
                $usedStmt = $conn->prepare("
                    SELECT COALESCE(SUM(product_amount), 0) as used_amount
                    FROM roll_qc_reports 
                    WHERE reference_number = ?
                    AND roll_no = ?
                ");
                if ($usedStmt) {
                    $usedStmt->bind_param("ss", $referenceNumber, $rollNo);
                    $usedStmt->execute();
                    $usedResult = $usedStmt->get_result();
                    $usedAmount = 0;
                    if ($usedResult && $usedRow = $usedResult->fetch_assoc()) {
                        $usedAmount = (float)$usedRow['used_amount'];
                    }
                    $usedStmt->close();
                } else {
                    $usedAmount = 0;
                }
            }
            
            // Calculate available amount
            $availableAmount = $originalWeight - $usedAmount;
            
            // Only include if there's remaining amount (or if original weight is 0, show it anyway)
            if ($availableAmount > 0.01 || $originalWeight <= 0) {
                $row['original_weight'] = $originalWeight;
                $row['used_amount'] = $usedAmount;
                $row['available_amount'] = max(0, $availableAmount);
                $references[] = $row;
            }
        }
    }
}


// Only show amounts that have passed through GSM and Roll Entry (gsm_roll_entry table)
// This ensures we only show the exact amount that was actually processed through the system

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Roll QC Report</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:20px; color:#2c3e50; }
  .container { max-width:1200px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);}
  h1 { text-align:center; font-size:28px; margin-bottom:30px; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  select, input[type="text"], input[type="number"] {
    padding:10px; border:1px solid #ccc; border-radius:6px; width:100%;
  }
  small {
    display: block;
    margin-top: 5px;
    font-size: 13px;
  }
  .status-table { width:100%; border-collapse:collapse; margin-top:20px; }
  .status-table th { background:#34495e; color:white; padding:12px; text-align:center; font-size:13px; font-weight:600; }
  .status-table td { padding:10px; border:1px solid #ddd; text-align:center; }
  .status-done { background:#d4edda; color:#155724; font-weight:600; padding:6px 12px; border-radius:4px; }
  .status-pending { background:#fff3cd; color:#856404; font-weight:600; padding:6px 12px; border-radius:4px; }
  .alert { padding:12px; border-radius:6px; margin-bottom:20px; }
  .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
  .alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px; font-weight:600;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .submit-btn:hover { background:#27ae60; }
  
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
  
  <h1>Roll QC Report</h1>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">
      <strong>✓ Success:</strong> <?php echo htmlspecialchars($_GET['success']); ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error">
      <strong>✗ Error:</strong> <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <!-- Back to Dashboard Link -->
  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
  </div>

  <form id="qcReportForm" method="post" action="../handlers/submit_roll_qc_report.php" onsubmit="return validateForm();">

    <!-- Reference Number Selection -->
    <div class="form-group">
      <label>Reference Number:</label>
      <select id="ref_number" name="ref_number" onchange="loadRollData()" required>
        <option value="">-- Select Reference Number --</option>
        <?php if (empty($references)): ?>
          <option value="" disabled>-- No references with remaining amount found --</option>
        <?php else: ?>
          <?php foreach($references as $ref): ?>
          <option value="<?php echo htmlspecialchars($ref['reference_number']); ?>" 
                  data-roll="<?php echo htmlspecialchars($ref['roll_no'] ?? ''); ?>"
                  data-line="<?php echo htmlspecialchars($ref['line_no'] ?? ''); ?>"
                  data-available="<?php echo number_format($ref['available_amount'], 2); ?>"
                  data-original="<?php echo number_format($ref['original_weight'], 2); ?>">
            <?php echo htmlspecialchars($ref['reference_number']); ?> 
            <?php if (!empty($ref['roll_no'])): ?>
              (Roll <?php echo htmlspecialchars($ref['roll_no']); ?>) 
            <?php endif; ?>
            - Available: <?php echo number_format($ref['available_amount'], 2); ?> kg
          </option>
          <?php endforeach; ?>
        <?php endif; ?>
      </select>
      <?php if (empty($references)): ?>
        <small style="color: #e74c3c; font-weight: 600; display: block; margin-top: 5px;">
          No references found with remaining amount. Please create roll entries first or check if all amounts have been used.
        </small>
      <?php endif; ?>
    </div>

    <!-- Product Amount -->
    <div class="form-group">
      <label>Product Amount (kg):</label>
      <input type="number" id="product_amount" name="product_amount" step="0.01" oninput="validateAmount()" required>
      <small id="available_text" style="color: #27ae60; font-weight: 600; display: none; margin-top: 5px; font-size: 13px;">
        Available in roll: <span id="available_value">0</span> kg (Original: <span id="original_value">0</span> kg)
      </small>
      <small id="amount_warning" style="color: #e74c3c; font-weight: 600; display: none; margin-top: 5px; font-size: 13px;"></small>
    </div>

    <!-- QC Status Table -->
    <div id="qcStatusSection" style="display:none;">
      <h3 style="margin-top:30px; margin-bottom:15px;">QC Test Status</h3>
      <table class="status-table">
        <thead>
          <tr>
            <th>Test Name</th>
            <th>Status</th>
            <th>Last Updated</th>
          </tr>
        </thead>
        <tbody id="qcStatusBody">
          <tr>
            <td>Daily GSM Check</td>
            <td id="gsmStatus">-</td>
            <td id="gsmDate">-</td>
          </tr>
          <tr>
            <td>Length Calibration</td>
            <td id="lengthStatus">-</td>
            <td id="lengthDate">-</td>
          </tr>
          <tr style="background:#f8f9fa; font-weight:600;">
            <td>Overall Status</td>
            <td id="overallStatus" colspan="2">-</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="actions">
      <button type="submit" class="submit-btn">Submit Report</button>
    </div>
  </form>
</div>

<!-- Quantity Limit Exceeded Popup -->
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

<script>
let availableAmount = 0;
let lastPopupAmount = null;

async function loadRollData() {
  const refSelect = document.getElementById('ref_number');
  const selectedOption = refSelect.options[refSelect.selectedIndex];
  
  if (!selectedOption.value) {
    // Reset if nothing selected
    availableAmount = 0;
    document.getElementById('available_text').style.display = 'none';
    document.getElementById('amount_warning').style.display = 'none';
    document.getElementById('product_amount').value = '';
    document.getElementById('product_amount').max = '';
    document.getElementById('qcStatusSection').style.display = 'none';
    document.getElementById('qcStatusSection').style.display = 'none';
    return;
  }
  
  const refNumber = selectedOption.value;
  const rollNo = selectedOption.dataset.roll;
  const lineNumber = selectedOption.dataset.line;
  const available = parseFloat(selectedOption.dataset.available) || 0;
  const original = parseFloat(selectedOption.dataset.original) || 0;
  
  // Store available amount
  availableAmount = available;
  
  // Show available amount
  document.getElementById('available_value').textContent = available.toFixed(2);
  document.getElementById('original_value').textContent = original.toFixed(2);
  document.getElementById('available_text').style.display = 'block';
  
  // Set max amount
  document.getElementById('product_amount').max = available;
  
  try {
    // Add timestamp to prevent caching
    const timestamp = new Date().getTime();
    const response = await fetch(`../handlers/check_qc_status.php?ref_number=${encodeURIComponent(refNumber)}&roll_no=${encodeURIComponent(rollNo)}&line_number=${encodeURIComponent(lineNumber)}&_t=${timestamp}`);
    
    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }
    
    const text = await response.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (parseError) {
      console.error('JSON parse error. Response text:', text);
      throw new Error('Invalid JSON response: ' + text.substring(0, 100));
    }
    
    if (data.error) {
      throw new Error(data.error + (data.message ? ': ' + data.message : ''));
    }
    
    // Debug logging
    console.log('QC Status Response:', data);
    console.log('GSM Done:', data.gsm_done, 'Length Done:', data.length_done);
    
    // Update GSM Status
    if (data.gsm_done) {
      document.getElementById('gsmStatus').innerHTML = '<span class="status-done">Approved</span>';
      document.getElementById('gsmDate').textContent = data.gsm_date || 'Approved';
    } else {
      document.getElementById('gsmStatus').innerHTML = '<span class="status-pending">Pending</span>';
      document.getElementById('gsmDate').textContent = '-';
    }
    
    // Update Length Calibration Status
    if (data.length_done) {
      document.getElementById('lengthStatus').innerHTML = '<span class="status-done">Approved</span>';
      document.getElementById('lengthDate').textContent = data.length_date || 'Approved';
    } else {
      document.getElementById('lengthStatus').innerHTML = '<span class="status-pending">Pending</span>';
      document.getElementById('lengthDate').textContent = '-';
    }
    
    // Update Overall Status
    if (data.gsm_done && data.length_done) {
      document.getElementById('overallStatus').innerHTML = '<span class="status-done">All Tests Approved</span>';
    } else {
      document.getElementById('overallStatus').innerHTML = '<span class="status-pending">Tests Pending</span>';
    }
    
    document.getElementById('qcStatusSection').style.display = 'block';
    
  } catch (error) {
    console.error('Error loading QC status:', error);
    // Try to get more details about the error
    const errorMsg = error.message || 'Unknown error';
    console.error('Full error:', error);
    alert('Error loading QC status: ' + errorMsg);
  }
}

function validateAmount() {
  const amountInput = document.getElementById('product_amount');
  const amount = parseFloat(amountInput.value) || 0;
  const warningElement = document.getElementById('amount_warning');
  
  if (availableAmount > 0 && amount > availableAmount) {
    warningElement.textContent = `⚠️ Amount exceeds available quantity (${availableAmount.toFixed(2)} kg)`;
    warningElement.style.display = 'block';
    amountInput.setCustomValidity('Amount exceeds available quantity');
    amountInput.style.borderColor = "#e74c3c";
    
    // Show popup notification (only once per amount value to avoid spam)
    if (lastPopupAmount !== amount) {
      showQtyLimitPopup(amount, availableAmount);
      lastPopupAmount = amount;
    }
    return false;
  } else {
    // Reset popup tracking when amount is valid
    if (amount <= availableAmount) {
      lastPopupAmount = null;
    }
    
    warningElement.style.display = 'none';
    amountInput.setCustomValidity('');
    amountInput.style.borderColor = "#ccc";
    return true;
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
  try {
    const popup = document.getElementById('qtyLimitPopup');
    const overlay = document.getElementById('qtyLimitPopupOverlay');
    
    if (popup && overlay) {
      popup.classList.remove('show');
      overlay.classList.remove('show');
      
      // Focus back on product_amount input field
      setTimeout(() => {
        const amountInput = document.getElementById('product_amount');
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

function validateForm() {
  if (!document.getElementById('ref_number').value) {
    alert('Please select a reference number');
    return false;
  }
  if (!document.getElementById('product_amount').value) {
    alert('Please enter product amount');
    return false;
  }
  if (!validateAmount()) {
    alert('Please enter a valid amount that does not exceed the available quantity');
    return false;
  }
  return true;
}
</script>
</body>
</html>


