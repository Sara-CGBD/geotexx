<?php
session_start();
// Optional auto-reload for development
if (file_exists(__DIR__ . '/../dev/auto_reload.php')) {
    include_once(__DIR__ . '/../dev/auto_reload.php');
}
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
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_QC, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access the QC module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Initialize rejected entries array early
$rejectedEntries = [];

// Schema helpers
$colExists = function(mysqli $conn, string $table, string $column): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    $col = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    return $res && $res->num_rows > 0;
};

// Collation alignment for reference_number and line_number
$ftrCollation = null;
$dgcCollation = null;
$dgcLineCollation = null;
$colRes = $conn->query("SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME IN ('fiber_to_roll_entry','daily_gsm_checks')
    AND COLUMN_NAME IN ('reference_number','line_number')");
if ($colRes) {
    while ($row = $colRes->fetch_assoc()) {
        if ($row['TABLE_NAME'] === 'fiber_to_roll_entry' && $row['COLUMN_NAME'] === 'reference_number') $ftrCollation = $row['COLLATION_NAME'];
        if ($row['TABLE_NAME'] === 'daily_gsm_checks' && $row['COLUMN_NAME'] === 'reference_number') $dgcCollation = $row['COLLATION_NAME'];
        if ($row['TABLE_NAME'] === 'daily_gsm_checks' && $row['COLUMN_NAME'] === 'line_number') $dgcLineCollation = $row['COLLATION_NAME'];
    }
}
$collation = $ftrCollation ?: $dgcCollation ?: 'utf8mb4_unicode_ci';
if (!preg_match('/^[0-9A-Za-z_]+$/', $collation)) {
    $collation = 'utf8mb4_unicode_ci';
}
if ($dgcCollation && $dgcCollation !== $collation) {
    $conn->query("ALTER TABLE daily_gsm_checks MODIFY reference_number VARCHAR(100) CHARACTER SET utf8mb4 COLLATE {$collation}");
}
if ($dgcLineCollation && $dgcLineCollation !== $collation) {
    $conn->query("ALTER TABLE daily_gsm_checks MODIFY line_number VARCHAR(100) CHARACTER SET utf8mb4 COLLATE {$collation}");
}

// Build EXISTS filters only if columns exist
$hasDgcRef = $colExists($conn, 'daily_gsm_checks', 'reference_number');
$hasDgcRoll = $colExists($conn, 'daily_gsm_checks', 'roll_no');
$hasDgcLine = $colExists($conn, 'daily_gsm_checks', 'line_number');
$hasDgcStatus = $colExists($conn, 'daily_gsm_checks', 'status');
// Exclude all submitted entries (any status except rejected, as rejected entries can be resubmitted)
$dgcStatusFilter = $hasDgcStatus ? "AND d.status NOT IN ('rejected')" : "";
$dgcLineFilter = $hasDgcLine ? "AND d.line_number COLLATE {$collation} = CONCAT('Line ', f.line_no) COLLATE {$collation}" : "";
$dgcExistsClause = "";
if ($hasDgcRoll) {
    // Exclude if roll_no already exists in daily_gsm_checks (regardless of reference_number or line_number)
    // This ensures a roll number can only be submitted once
    if ($hasDgcStatus) {
        $dgcExistsClause = "
        AND NOT EXISTS (
            SELECT 1 FROM daily_gsm_checks d 
            WHERE d.roll_no = f.roll_no
            AND d.status NOT IN ('rejected')
        )";
    } else {
        // If status column doesn't exist, exclude all entries for this roll_no
        $dgcExistsClause = "
        AND NOT EXISTS (
            SELECT 1 FROM daily_gsm_checks d 
            WHERE d.roll_no = f.roll_no
        )";
    }
}

// Fetch roll numbers from gsm_roll_entry (GSM and roll input data table)
$rollNumbers = [];

// First, try to fetch from gsm_roll_entry table
$tableExists = false;
$tableCheck = $conn->query("SHOW TABLES LIKE 'gsm_roll_entry'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $tableExists = true;
}

if ($tableExists) {
    // Check if daily_gsm_checks table exists before using it in NOT EXISTS clause
    $dgcTableCheck = $conn->query("SHOW TABLES LIKE 'daily_gsm_checks'");
    $dgcTableExists = ($dgcTableCheck && $dgcTableCheck->num_rows > 0);
    
    // Build the exclusion clause only if daily_gsm_checks table exists
    // Exclude rolls that have already been submitted (except rejected ones which can be resubmitted)
    $exclusionClause = "";
    if ($dgcTableExists && $hasDgcRoll) {
        // Re-check status column existence since table might not have existed when checked earlier
        $statusColCheck = $conn->query("SHOW COLUMNS FROM daily_gsm_checks LIKE 'status'");
        $statusColExists = ($statusColCheck && $statusColCheck->num_rows > 0);
        
        // Build exclusion clause with proper status filter
        // IMPORTANT: Always match by BOTH roll_no AND reference_number (entire reference)
        // This allows the same roll number with different reference/GSM to appear in dropdown
        // Only exclude if status is NOT 'rejected' (rejected entries can be resubmitted)
        if ($statusColExists) {
            // Exclude only if BOTH roll_no AND reference_number match exactly
            // This ensures Roll 3 with reference "REF-A" doesn't exclude Roll 3 with reference "REF-B"
            $exclusionClause = "AND NOT EXISTS (
                SELECT 1 FROM daily_gsm_checks d 
                WHERE CAST(d.roll_no AS CHAR) = CAST(g.roll_no AS CHAR)
                AND (
                    -- Both have reference numbers and they match
                    (d.reference_number IS NOT NULL AND d.reference_number != '' 
                     AND g.reference IS NOT NULL AND g.reference != ''
                     AND TRIM(d.reference_number) COLLATE {$collation} = TRIM(g.reference) COLLATE {$collation})
                    OR
                    -- Both are NULL or empty (treat as match)
                    ((d.reference_number IS NULL OR TRIM(d.reference_number) = '') 
                     AND (g.reference IS NULL OR TRIM(g.reference) = ''))
                )
                AND d.status != 'rejected'
            )";
        } else {
            // If status column doesn't exist, exclude all submitted rolls matching both roll_no and reference
            $exclusionClause = "AND NOT EXISTS (
                SELECT 1 FROM daily_gsm_checks d 
                WHERE CAST(d.roll_no AS CHAR) = CAST(g.roll_no AS CHAR)
                AND (
                    -- Both have reference numbers and they match
                    (d.reference_number IS NOT NULL AND d.reference_number != '' 
                     AND g.reference IS NOT NULL AND g.reference != ''
                     AND TRIM(d.reference_number) COLLATE {$collation} = TRIM(g.reference) COLLATE {$collation})
                    OR
                    -- Both are NULL or empty (treat as match)
                    ((d.reference_number IS NULL OR TRIM(d.reference_number) = '') 
                     AND (g.reference IS NULL OR TRIM(g.reference) = ''))
                )
            )";
        }
    }
    
    // First, check total rolls without exclusions
    $totalQuery = $conn->query("SELECT COUNT(*) as cnt FROM gsm_roll_entry WHERE roll_no IS NOT NULL AND roll_no != 0");
    $totalResult = $totalQuery ? $totalQuery->fetch_assoc() : null;
    $totalRolls = $totalResult ? (int)$totalResult['cnt'] : 0;
    
    // Check how many are already submitted
    $submittedCount = 0;
    if ($dgcTableExists && $hasDgcRoll) {
        $statusColCheck = $conn->query("SHOW COLUMNS FROM daily_gsm_checks LIKE 'status'");
        $statusColExists = ($statusColCheck && $statusColCheck->num_rows > 0);
        $submittedStatusFilter = $statusColExists ? "AND status NOT IN ('rejected')" : "";
        $submittedQuery = $conn->query("SELECT COUNT(DISTINCT roll_no) as cnt FROM daily_gsm_checks WHERE roll_no IS NOT NULL AND roll_no != '' {$submittedStatusFilter}");
        if ($submittedQuery) {
            $submittedResult = $submittedQuery->fetch_assoc();
            $submittedCount = (int)$submittedResult['cnt'];
        }
    }
    
    // Debug: Get all rolls from gsm_roll_entry first
    $allRollsQuery = $conn->query("SELECT roll_no, reference, gsm FROM gsm_roll_entry WHERE roll_no IS NOT NULL AND roll_no != 0 ORDER BY created_at DESC LIMIT 10");
    $allRollsList = [];
    if ($allRollsQuery) {
        while ($r = $allRollsQuery->fetch_assoc()) {
            $allRollsList[] = "Roll " . $r['roll_no'];
        }
    }
    error_log("Daily GSM Check - All rolls in gsm_roll_entry: " . implode(", ", $allRollsList));
    
    // Debug: Get submitted rolls
    $submittedRollsList = [];
    if ($dgcTableExists && $hasDgcRoll) {
        $submittedQuery = $conn->query("SELECT DISTINCT roll_no FROM daily_gsm_checks WHERE roll_no IS NOT NULL AND roll_no != '' LIMIT 10");
        if ($submittedQuery) {
            while ($s = $submittedQuery->fetch_assoc()) {
                $submittedRollsList[] = "Roll " . $s['roll_no'];
            }
        }
    }
    error_log("Daily GSM Check - Submitted rolls: " . implode(", ", $submittedRollsList));
    
    // Fetch from gsm_roll_entry
    // Include GSM in the query for display purposes (but won't be saved)
    // Exclude rolls that have already been submitted in daily_gsm_checks (except rejected ones)
    // Cast roll_no to CHAR for proper comparison with VARCHAR roll_no in daily_gsm_checks
    $rollQuery = $conn->query("
        SELECT g.reference, g.roll_no, g.line_number as line_no, g.gsm
        FROM gsm_roll_entry g
        WHERE g.roll_no IS NOT NULL
        AND g.roll_no != 0
        {$exclusionClause}
        ORDER BY g.created_at DESC 
        LIMIT 100
    ");
    
    if ($rollQuery === false) {
        // Log query error for debugging
        error_log("Daily GSM Check - Roll Query Error: " . $conn->error);
    } else {
        $rowCount = 0;
        while ($row = $rollQuery->fetch_assoc()) {
            $rowCount++;
            $rollNo = (string)$row['roll_no']; // Convert to string for consistency
            $reference = $row['reference'] ?? '';
            $lineNo = $row['line_no'] ?? '';
            $gsm = $row['gsm'] ?? '';
            // Convert line_no to "Line X" format if it's numeric
            if (!empty($lineNo) && is_numeric($lineNo)) {
                $lineNo = "Line " . $lineNo;
            }
            // Display format: "Roll X - GSM: Y" if GSM exists, otherwise just "Roll X"
            $displayText = !empty($gsm) ? "Roll $rollNo - GSM: $gsm" : "Roll $rollNo";
            $rollNumbers[] = [
                'roll_no' => $rollNo,
                'reference' => $reference,
                'line_no' => $lineNo,
                'gsm' => $gsm,
                'display' => $displayText
            ];
        }
        
    }
}

// Note: Only using gsm_roll_entry as the source (no fallback to fiber_to_roll_entry)
// If gsm_roll_entry doesn't exist or has no data, $rollNumbers will remain empty

// Fetch bag sizes with GSM and thickness from bag_size_master table
$bagSizes = [];
$bagQuery = $conn->query("SELECT bag_size, gsm, thickness FROM bag_size_master WHERE bag_size IS NOT NULL AND bag_size != '' ORDER BY bag_size ASC, gsm ASC, thickness ASC");
if ($bagQuery) {
    while ($row = $bagQuery->fetch_assoc()) {
        $bagSizes[] = [
            'bag_size' => $row['bag_size'],
            'gsm' => $row['gsm'],
            'thickness' => $row['thickness'],
            'display' => $row['bag_size'] . ' - GSM: ' . $row['gsm'] . ' - Thickness: ' . $row['thickness']
        ];
    }
}

// Fetch rejected entries for this user to allow resubmission
$userId = $_SESSION['user_id'];
$hasRejectionReason = $colExists($conn, 'daily_gsm_checks', 'rejection_reason');
$rejCol = $hasRejectionReason ? "rejection_reason" : "NULL AS rejection_reason";
$userFilter = $colExists($conn, 'daily_gsm_checks', 'user_id') ? "AND user_id = {$userId}" : "";
$rejectedQuery = $conn->query("
    SELECT entry_id, date_time, shift, line_number, inspector, {$rejCol}, created_at
    FROM daily_gsm_checks
    WHERE status = 'rejected' {$userFilter}
    GROUP BY entry_id
    ORDER BY created_at DESC
    LIMIT 10
");
if ($rejectedQuery) {
    while ($row = $rejectedQuery->fetch_assoc()) {
        $rejectedEntries[] = $row;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Daily GSM Check (Floor)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:20px; color:#2c3e50; }
  .container { max-width:1600px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);}
  h1 { text-align:center; font-size:28px; margin-bottom:30px; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  input[type="text"], input[type="number"], select {
    padding:10px; border:1px solid #ccc; border-radius:6px; width:100%;
  }
  .readonly { background:#ecf0f1; }
  .btn-group { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px; }
  .btn { padding:6px 12px; border:1px solid #ccc; background:#f0f0f0; cursor:pointer; border-radius:4px; transition:all 0.2s; font-weight:500; font-size:14px; max-width:85px; min-width:65px; }
  .btn:hover { background:#e0e0e0; }
  .btn.selected { background:#007bff; color:#fff; border-color:#007bff; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .add-row-btn { background:#3498db; color:#fff; padding:6px 14px; border:none; border-radius:4px; cursor:pointer; font-weight:500; margin-bottom:20px; font-size:13px; }
  .alert { padding:12px; border-radius:6px; margin-bottom:20px; }
  .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
  .alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
  
  .gsm-table { width:100%; margin-top:20px; margin-bottom:20px; font-size:13px; }
  .gsm-table th { background:#34495e; color:white; padding:10px 8px; text-align:center; font-size:11px; font-weight:600; vertical-align:middle; }
  .gsm-table td { padding:8px 6px; vertical-align:middle; }
  .gsm-table .form-control-sm { text-align:center; padding:6px 10px; font-size:12px; width:100%; min-width:90px; }
  .gsm-table select.form-control-sm { max-width:200px; min-width:180px; }
  .gsm-table .avg-cell { background:#e8f5e9; }
  .gsm-table .avg-cell input { background:#e8f5e9; font-weight:bold; }
  .gsm-table th .btn-group .btn { background:#6c757d; color:white; border-color:#6c757d; font-size:10px; padding:4px 10px; }
  .gsm-table th .btn-group .btn:hover { background:#5a6268; border-color:#545b62; }
  .gsm-table th .btn-group .btn.active { background:#0d6efd; border-color:#0d6efd; }
  .gsm-table .btn-danger { background:#dc3545; color:#fff; border:2px solid #dc3545; padding:6px 14px; font-size:12px; font-weight:600; transition:all 0.3s; box-shadow:0 2px 4px rgba(0,0,0,0.1); }
  .gsm-table .btn-danger:hover { background:#c82333; border-color:#bd2130; transform:translateY(-1px); box-shadow:0 4px 6px rgba(0,0,0,0.15); }
</style>
</head>
<body>
<div class="container">
  
  <h1>Daily GSM Check (Floor)</h1>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>
  <div id="entryIdDisplay" class="summary-info" style="background:#e8f5e9; border-left:4px solid #27ae60;"></div>

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

  <?php if (count($rejectedEntries) > 0): ?>
  <div style="background:#f8d7da; border:1px solid #f5c6cb; padding:15px; border-radius:8px; margin-bottom:20px;">
    <h3 style="color:#721c24; margin-bottom:12px; font-size:16px;"><i class="fas fa-exclamation-triangle"></i> Your Rejected Entries</h3>
    <table style="width:100%; background:white; font-size:13px;">
      <thead style="background:#e74c3c; color:white;">
        <tr>
          <th style="padding:8px; font-size:13px;">Entry ID</th>
          <th style="padding:8px; font-size:13px;">Date & Time</th>
          <th style="padding:8px; font-size:13px;">Line</th>
          <th style="padding:8px; font-size:13px;">Rejection Reason</th>
          <th style="padding:8px; font-size:13px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rejectedEntries as $rejected): ?>
        <tr>
          <td style="padding:8px; font-size:12px;"><strong><?php echo htmlspecialchars($rejected['entry_id']); ?></strong></td>
          <td style="padding:8px; font-size:12px;"><?php echo date('d M Y, h:i A', strtotime($rejected['date_time'])); ?></td>
          <td style="padding:8px; font-size:12px;"><?php echo htmlspecialchars($rejected['line_number']); ?></td>
          <td style="padding:8px; font-size:12px;"><?php echo htmlspecialchars($rejected['rejection_reason']); ?></td>
          <td style="padding:8px;">
            <a href="edit_gsm_check.php?id=<?php echo urlencode($rejected['entry_id']); ?>" 
               style="background:#3498db; color:white; border:none; padding:6px 12px; border-radius:6px; cursor:pointer; font-weight:600; font-size:12px; text-decoration:none; display:inline-block;">
              <i class="fas fa-edit"></i> Edit & Resubmit
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <form id="gsmCheckForm" method="post" action="../handlers/submit_daily_gsm_check.php" onsubmit="return validateForm();">

    <!-- Hidden datetime + shift -->
    <input type="hidden" id="dateTime" name="date_time">
    <input type="hidden" id="shift" name="shift">

    <!-- Line Number -->
    <div class="form-group">
      <label>Line Number:</label>
      <div class="btn-group">
        <button type="button" class="btn" onclick="selectLine('Line 1')">Line 1</button>
        <button type="button" class="btn" onclick="selectLine('Line 2')">Line 2</button>
      </div>
      <input type="hidden" id="line_number" name="line_number" value="" required>
    </div>

    <!-- Inspector Name -->
    <div class="form-group">
      <label>Inspector Name:</label>
      <input type="text" id="inspector" name="inspector" value="<?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?>" readonly class="readonly">
    </div>

    <!-- GSM Check Table -->
    <div style="overflow-x:auto;">
      <table class="gsm-table table table-bordered text-center align-middle" id="gsmTable">
        <thead>
          <tr>
          <tr>
            <th rowspan="2">Roll No.</th>
            <th rowspan="2">
              Size <br>
              <div class="btn-group mt-1" role="group" aria-label="Size Options">
                <button type="button" class="btn btn-sm btn-secondary" onclick="selectAllSize('Test Size')">Test Size</button>
                <button type="button" class="btn btn-sm btn-secondary" onclick="selectAllSize('Bag Size')">Bag Size</button>
              </div>
            </th>
            <th colspan="4">Weight (g)</th>
            <th colspan="4">GSM</th>
            <th rowspan="2">Avg GSM</th>
            <th rowspan="2">Remarks</th>
            <th rowspan="2">Action</th>
          </tr>
          <tr>
            <th>Left</th>
            <th>Left Middle</th>
            <th>Right Middle</th>
            <th>Right</th>
            <th>Left</th>
            <th>Left Middle</th>
            <th>Right Middle</th>
            <th>Right</th>
          </tr>
        </thead>
        <tbody id="gsmTableBody">
          <!-- Rows will be added dynamically -->
        </tbody>
      </table>
    </div>

    <!-- Add Row Button -->
    <button type="button" class="add-row-btn" onclick="addRow()">
      + Add 1 More Row
    </button>

    <div class="actions">
      <button type="submit" class="submit-btn">Submit</button>
      <button type="reset" class="clear-btn" onclick="clearForm()">Clear</button>
    </div>
  </form>
</div>

<script>
// Load rejected entry data for editing and resubmission
function loadRejectedEntry(entryId) {
  if (!confirm('Load this rejected entry for editing?')) return;
  
  fetch('api/get_rejected_gsm.php?entry_id=' + encodeURIComponent(entryId))
    .then(res => {
      if (!res.ok) {
        throw new Error('HTTP error! status: ' + res.status);
      }
      return res.json();
    })
    .then(response => {
      if (response.success && response.data.length > 0) {
        const data = response.data;
        const firstRow = data[0];
        
        // Set resubmit entry ID
        document.getElementById('resubmit_entry_id').value = entryId;
        
        // Set line number
        selectLine(firstRow.line_number);
        
        // Set inspector
        document.getElementById('inspector').value = firstRow.inspector;
        
        // Clear existing rows
        document.getElementById('gsmTableBody').innerHTML = '';
        rowCount = 0;
        
        // Add rows with rejected data
        data.forEach((row, index) => {
          addRow();
          const currentRowId = rowCount; // Capture the current row count
          
          // Wait for row to be added to DOM
          setTimeout(() => {
            const rowElement = document.getElementById('row_' + currentRowId);
            
            if (rowElement) {
              // Set roll number (select dropdown)
              const rollSelect = rowElement.querySelector('[name="roll_no[]"]');
              if (rollSelect) rollSelect.value = row.roll_no;
              
              // Set size type and value - need to handle the size cell properly
              const sizeTypeInput = document.getElementById('size_type_' + currentRowId);
              if (sizeTypeInput) sizeTypeInput.value = row.size_type;
              
              const sizeValueInput = rowElement.querySelector('[name="size_value[]"]');
              if (sizeValueInput && row.size_value) sizeValueInput.value = row.size_value;
              
              // Set weight fields using querySelector (no IDs)
              const inputs = rowElement.querySelectorAll('input[type="text"]');
              const weightFields = Array.from(inputs).filter(inp => inp.name.startsWith('weight_'));
              if (weightFields[0]) weightFields[0].value = row.weight_left;
              if (weightFields[1]) weightFields[1].value = row.weight_left_middle;
              if (weightFields[2]) weightFields[2].value = row.weight_right_middle;
              if (weightFields[3]) weightFields[3].value = row.weight_right;
              
              // Set GSM fields using IDs
              const gsmLeft = document.getElementById('gsm_left_' + currentRowId);
              if (gsmLeft) gsmLeft.value = row.gsm_left;
              
              const gsmLeftMiddle = document.getElementById('gsm_left_middle_' + currentRowId);
              if (gsmLeftMiddle) gsmLeftMiddle.value = row.gsm_left_middle;
              
              const gsmRightMiddle = document.getElementById('gsm_right_middle_' + currentRowId);
              if (gsmRightMiddle) gsmRightMiddle.value = row.gsm_right_middle;
              
              const gsmRight = document.getElementById('gsm_right_' + currentRowId);
              if (gsmRight) gsmRight.value = row.gsm_right;
              
              const avgGsm = document.getElementById('avg_gsm_' + currentRowId);
              if (avgGsm) avgGsm.value = row.avg_gsm;
              
              const remarks = rowElement.querySelector('[name="remarks[]"]');
              if (remarks && row.remarks) remarks.value = row.remarks;
            }
          }, 100 * index); // Stagger the updates
        });
        
        // Show alert after all rows are loaded
        setTimeout(() => {
          alert('Rejected entry loaded! Please review and edit the values, then submit again.');
          window.scrollTo(0, document.getElementById('gsmCheckForm').offsetTop);
        }, 100 * data.length + 100);
      } else {
        alert('Error: ' + (response.message || 'Could not load rejected entry'));
      }
    })
    .catch(err => {
      console.error('Fetch error:', err);
      alert('Error loading rejected entry: ' + err.message);
    });
}

let rowCount = 0;

function selectLine(line) {
  document.getElementById("line_number").value = line;
  
  // Remove selected class from all line buttons
  const lineButtons = event.target.parentElement.querySelectorAll('.btn');
  lineButtons.forEach(btn => btn.classList.remove('selected'));
  
  // Add selected class to clicked button
  event.target.classList.add('selected');
}

function autoSelectLineFromRoll(rowId) {
  // Get the selected roll's line number and reference
  const selectElement = document.getElementById(`roll_select_${rowId}`);
  const selectedOption = selectElement.options[selectElement.selectedIndex];
  const lineNo = selectedOption.dataset.line;
  const reference = selectedOption.dataset.reference;
  
  // Populate the hidden reference_number field
  const refField = document.getElementById(`reference_number_${rowId}`);
  if (refField) {
    refField.value = reference || '';
  }
  
  // Always auto-select the line number if available
  if (lineNo) {
    // Extract numeric part if lineNo is in "Line X" format, otherwise use as-is
    let lineText = lineNo;
    if (lineNo.startsWith('Line ')) {
      lineText = lineNo; // Already in correct format
    } else {
      // Extract number from lineNo (could be "1", "2", "Line 1", etc.)
      const match = lineNo.match(/\d+/);
      if (match) {
        lineText = `Line ${match[0]}`;
      }
    }
    
    // Set the hidden field value
    const lineNumberField = document.getElementById("line_number");
    if (lineNumberField) {
      lineNumberField.value = lineText;
    }
    
    // Highlight the corresponding button
    const lineButtons = document.querySelectorAll('.btn-group .btn');
    lineButtons.forEach(btn => {
      btn.classList.remove('selected');
      // Match by button text (e.g., "Line 1" or "Line 2")
      if (btn.textContent.trim() === lineText) {
        btn.classList.add('selected');
      }
    });
  }
}

function selectAllSize(sizeType) {
  // Bag sizes from PHP
  const bagSizes = <?php echo json_encode($bagSizes); ?>;
  
  // Set the size type for all existing rows and add appropriate input field
  for (let i = 1; i <= rowCount; i++) {
    const sizeField = document.getElementById(`size_type_${i}`);
    const sizeCell = document.getElementById(`size_cell_${i}`);
    if (sizeField && sizeCell && document.getElementById(`row_${i}`)) {
      sizeField.value = sizeType;
      
      if (sizeType === 'Bag Size') {
        // Show dropdown for Bag Size
        let options = '<option value="">-- Select Bag Size --</option>';
        bagSizes.forEach(size => {
          const value = size.bag_size + ' - GSM: ' + size.gsm + ' - Thickness: ' + size.thickness;
          options += `<option value="${value}">${size.display}</option>`;
        });
        
        sizeCell.innerHTML = `
          <input type="hidden" name="size_type[]" id="size_type_${i}" value="${sizeType}">
          <select name="size_value[]" class="form-control form-control-sm" required>${options}</select>
        `;
      } else {
        // Show text input for Test Size
        sizeCell.innerHTML = `
          <input type="hidden" name="size_type[]" id="size_type_${i}" value="${sizeType}">
          <input type="text" name="size_value[]" class="form-control form-control-sm" placeholder="Test Size" required>
        `;
      }
    }
  }
  
  // Visual feedback on button
  const buttons = event.target.parentElement.querySelectorAll('.btn');
  buttons.forEach(btn => btn.classList.remove('active'));
  event.target.classList.add('active');
}

function addRow() {
  rowCount++;
  const tbody = document.getElementById('gsmTableBody');
  const row = document.createElement('tr');
  row.id = `row_${rowCount}`;
  
  // Build roll number options from PHP data (includes GSM in display)
  const rollOptions = <?php echo json_encode($rollNumbers); ?>;
  
  let rollOptionsHTML = '<option value="">-- Select Roll --</option>';
  if (rollOptions && Array.isArray(rollOptions) && rollOptions.length > 0) {
    rollOptions.forEach(roll => {
      // Use the display text which includes GSM if available (e.g., "Roll 1 - GSM: 120")
      const displayText = roll.display || `Roll ${roll.roll_no}`;
      rollOptionsHTML += `<option value="${roll.roll_no}" data-line="${roll.line_no || ''}" data-reference="${roll.reference || ''}" data-gsm="${roll.gsm || ''}">${displayText}</option>`;
    });
  }
  
  row.innerHTML = `
    <td>
      <select name="roll_no[]" id="roll_select_${rowCount}" class="form-control form-control-sm" onchange="autoSelectLineFromRoll(${rowCount})" required>
        ${rollOptionsHTML}
      </select>
      <input type="hidden" name="reference_number[]" id="reference_number_${rowCount}" value="">
    </td>
    <td id="size_cell_${rowCount}">
      <input type="hidden" name="size_type[]" id="size_type_${rowCount}" value="">
      <span style="color:#999; font-size:11px;">Select size above</span>
    </td>
    <td><input type="number" step="0.01" name="weight_left[]" class="form-control form-control-sm" oninput="calculateAvgGSM(${rowCount})" onkeypress="return isNumeric(event)" required></td>
    <td><input type="number" step="0.01" name="weight_left_middle[]" class="form-control form-control-sm" oninput="calculateAvgGSM(${rowCount})" onkeypress="return isNumeric(event)" required></td>
    <td><input type="number" step="0.01" name="weight_right_middle[]" class="form-control form-control-sm" oninput="calculateAvgGSM(${rowCount})" onkeypress="return isNumeric(event)" required></td>
    <td><input type="number" step="0.01" name="weight_right[]" class="form-control form-control-sm" oninput="calculateAvgGSM(${rowCount})" onkeypress="return isNumeric(event)" required></td>
    <td><input type="number" step="0.01" name="gsm_left[]" id="gsm_left_${rowCount}" class="form-control form-control-sm" oninput="calculateAvgGSM(${rowCount})" onkeypress="return isNumeric(event)" required></td>
    <td><input type="number" step="0.01" name="gsm_left_middle[]" id="gsm_left_middle_${rowCount}" class="form-control form-control-sm" oninput="calculateAvgGSM(${rowCount})" onkeypress="return isNumeric(event)" required></td>
    <td><input type="number" step="0.01" name="gsm_right_middle[]" id="gsm_right_middle_${rowCount}" class="form-control form-control-sm" oninput="calculateAvgGSM(${rowCount})" onkeypress="return isNumeric(event)" required></td>
    <td><input type="number" step="0.01" name="gsm_right[]" id="gsm_right_${rowCount}" class="form-control form-control-sm" oninput="calculateAvgGSM(${rowCount})" onkeypress="return isNumeric(event)" required></td>
    <td class="avg-cell"><input type="text" name="avg_gsm[]" id="avg_gsm_${rowCount}" class="form-control form-control-sm" readonly></td>
    <td><input type="text" name="remarks[]" class="form-control form-control-sm"></td>
    <td>${rowCount > 1 ? '<button type="button" onclick="removeRow(' + rowCount + ')" class="btn btn-danger btn-sm">Remove</button>' : ''}</td>
  `;
  
  tbody.appendChild(row);
}

// Function to allow only numeric input (numbers, decimal point, backspace, delete, arrow keys)
function isNumeric(event) {
  // Allow: backspace, delete, tab, escape, enter, and arrow keys
  if (event.key === 'Backspace' || event.key === 'Delete' || event.key === 'Tab' || 
      event.key === 'Escape' || event.key === 'Enter' ||
      event.key === 'ArrowLeft' || event.key === 'ArrowRight' || 
      event.key === 'ArrowUp' || event.key === 'ArrowDown') {
    return true;
  }
  // Allow numbers 0-9
  if (event.key >= '0' && event.key <= '9') {
    return true;
  }
  // Allow decimal point only if it doesn't already exist in the input
  if (event.key === '.' || event.key === ',') {
    const decimalChar = event.key === ',' ? '.' : event.key;
    if (event.target.value.indexOf('.') === -1) {
      // Replace comma with dot if user typed comma
      if (event.key === ',') {
        event.preventDefault();
        event.target.value += '.';
        return false;
      }
      return true;
    } else {
      event.preventDefault();
      return false;
    }
  }
  // Block all other characters
  event.preventDefault();
  return false;
}

function calculateAvgGSM(rowId) {
  const gsmLeft = parseFloat(document.getElementById(`gsm_left_${rowId}`).value) || 0;
  const gsmLeftMiddle = parseFloat(document.getElementById(`gsm_left_middle_${rowId}`).value) || 0;
  const gsmRightMiddle = parseFloat(document.getElementById(`gsm_right_middle_${rowId}`).value) || 0;
  const gsmRight = parseFloat(document.getElementById(`gsm_right_${rowId}`).value) || 0;
  
  const avgGsm = (gsmLeft + gsmLeftMiddle + gsmRightMiddle + gsmRight) / 4;
  document.getElementById(`avg_gsm_${rowId}`).value = avgGsm.toFixed(2);
}

function removeRow(rowId) {
  const row = document.getElementById(`row_${rowId}`);
  if (row) {
    row.remove();
  }
}

function validateForm(){
  if(!document.getElementById("line_number").value){
    alert("Please select a line number."); 
    return false;
  }
  
  const rows = document.querySelectorAll('#gsmTableBody tr');
  if(rows.length === 0){
    alert("Please add at least one row."); 
    return false;
  }
  
  // Check that a size type has been selected using the header buttons
  let hasSizeType = false;
  for(let i = 1; i <= rowCount; i++) {
    const row = document.getElementById(`row_${i}`);
    if (row) {
      const sizeTypeField = document.getElementById(`size_type_${i}`);
      if (sizeTypeField && sizeTypeField.value) {
        hasSizeType = true;
        break;
      }
    }
  }
  
  if (!hasSizeType) {
    alert("Please select Test Size or Bag Size from the header buttons.");
    return false;
  }
  
  return true;
}

function clearForm() {
  document.getElementById("gsmCheckForm").reset();
  document.getElementById("gsmTableBody").innerHTML = '';
  rowCount = 0;
  
  // Remove selected class from line buttons
  const buttons = document.querySelectorAll('.btn-group .btn');
  buttons.forEach(btn => btn.classList.remove('selected'));
  
  updateTimeAndShift();
}

function updateTimeAndShift() {
  const now = new Date();
  const utc = now.getTime() + (now.getTimezoneOffset()*60000);
  const dhaka = new Date(utc + (6*3600000));
  document.getElementById("dateTimeDisplay").innerHTML =
    "Date & Time: " + dhaka.toDateString() + " " + dhaka.toLocaleTimeString();
  
  // Fetch Entry ID from server
  fetch('../handlers/get_entry_id.php?module=daily_gsm_check')
    .then(response => response.json())
    .then(data => {
      const entryId = data.entry_id || 'GSM-00000000-000';
      document.getElementById("entryIdDisplay").innerText = `Entry ID: ${entryId}`;
    })
    .catch(error => {
      document.getElementById("entryIdDisplay").innerText = "Entry ID: Loading...";
    });

  const yyyy = dhaka.getFullYear();
  const mm = String(dhaka.getMonth()+1).padStart(2,'0');
  const dd = String(dhaka.getDate()).padStart(2,'0');
  const hh = String(dhaka.getHours()).padStart(2,'0');
  const min = String(dhaka.getMinutes()).padStart(2,'0');
  const ss = String(dhaka.getSeconds()).padStart(2,'0');
  document.getElementById("dateTime").value = `${yyyy}-${mm}-${dd} ${hh}:${min}:${ss}`;

  const h = dhaka.getHours();
  const shift = (h >= 8 && h < 20) ? "Day" : "Night";
  document.getElementById("shiftBanner").innerText = "Shift: " + shift;
  document.getElementById("shift").value = shift;
}

// Initialize
updateTimeAndShift();
setInterval(updateTimeAndShift, 1000);

// Add initial row on page load
window.addEventListener('DOMContentLoaded', function() {
  addRow();
});
</script>
</body>
</html>




