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

// Fetch all reference numbers with roll numbers and available quantities from fiber_to_roll_entry
$references = [];
$refQuery = $conn->query("
    SELECT 
        ftr.reference_number, 
        ftr.roll_no, 
        ftr.line_no,
        ftr.total_weight as original_weight,
        COALESCE(SUM(rqc.product_amount), 0) as used_amount,
        (ftr.total_weight - COALESCE(SUM(rqc.product_amount), 0)) as available_amount
    FROM fiber_to_roll_entry ftr
    LEFT JOIN roll_qc_reports rqc 
        ON ftr.reference_number COLLATE {$collation} = rqc.reference_number COLLATE {$collation}
    WHERE ftr.reference_number IS NOT NULL
    GROUP BY ftr.reference_number, ftr.roll_no, ftr.line_no, ftr.total_weight
    HAVING available_amount > 0
    ORDER BY ftr.created_at DESC
");
if ($refQuery) {
    while ($row = $refQuery->fetch_assoc()) {
        $references[] = $row;
    }
}

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
        <?php foreach($references as $ref): ?>
        <option value="<?php echo htmlspecialchars($ref['reference_number']); ?>" 
                data-roll="<?php echo htmlspecialchars($ref['roll_no']); ?>"
                data-line="<?php echo htmlspecialchars($ref['line_no']); ?>"
                data-available="<?php echo $ref['available_amount']; ?>"
                data-original="<?php echo $ref['original_weight']; ?>">
          <?php echo htmlspecialchars($ref['reference_number']); ?> (Roll <?php echo htmlspecialchars($ref['roll_no']); ?>) - Available: <?php echo number_format($ref['available_amount'], 2); ?> kg
        </option>
        <?php endforeach; ?>
      </select>
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

<script>
let availableAmount = 0;

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
    return false;
  } else {
    warningElement.style.display = 'none';
    amountInput.setCustomValidity('');
    return true;
  }
}

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


