<?php

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();

// Include security configuration (same pattern as your other pages)
require_once 'security_config.php';
require_once '../config/project_helper.php';

// Auth/session checks
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
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_ROLL_PRODUCTION, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access the Sheet Production module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

// Timezone
date_default_timezone_set('Asia/Dhaka');

// DB connection
$conn = SecurityConfig::getConnection();

$colExists = function(mysqli $conn, string $table, string $column): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    $col = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    return $res && $res->num_rows > 0;
};

// Align collation for reference joins if mismatched
$ftrCollation = null;
$reCollation = null;
$dgcCollation = null;
$lcCollation = null;
$colRes = $conn->query("SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME IN ('fiber_to_roll_entry', 'roll_entry', 'daily_gsm_checks', 'length_calibrations') 
    AND COLUMN_NAME = 'reference_number'");
if ($colRes) {
    while ($row = $colRes->fetch_assoc()) {
        if ($row['TABLE_NAME'] === 'fiber_to_roll_entry') $ftrCollation = $row['COLLATION_NAME'];
        if ($row['TABLE_NAME'] === 'roll_entry') $reCollation = $row['COLLATION_NAME'];
        if ($row['TABLE_NAME'] === 'daily_gsm_checks') $dgcCollation = $row['COLLATION_NAME'];
        if ($row['TABLE_NAME'] === 'length_calibrations') $lcCollation = $row['COLLATION_NAME'];
    }
}
$collation = $ftrCollation ?: $reCollation ?: 'utf8mb4_unicode_ci';
if (!preg_match('/^[0-9A-Za-z_]+$/', $collation)) {
    $collation = 'utf8mb4_unicode_ci';
}
if ($ftrCollation && $reCollation && $ftrCollation !== $reCollation) {
    $conn->query("ALTER TABLE roll_entry MODIFY reference_number VARCHAR(100) CHARACTER SET utf8mb4 COLLATE {$collation}");
}
if ($dgcCollation && $dgcCollation !== $collation) {
    $conn->query("ALTER TABLE daily_gsm_checks MODIFY reference_number VARCHAR(100) CHARACTER SET utf8mb4 COLLATE {$collation}");
}
if ($lcCollation && $lcCollation !== $collation) {
    $conn->query("ALTER TABLE length_calibrations MODIFY reference_number VARCHAR(100) CHARACTER SET utf8mb4 COLLATE {$collation}");
}

// Build EXISTS clauses only if required columns exist to avoid missing-column errors
$gsmExistsClause = '';
$lcExistsClause = '';
$rqcExistsClause = '';

$hasGsmRef = $colExists($conn, 'daily_gsm_checks', 'reference_number');
$hasGsmRoll = $colExists($conn, 'daily_gsm_checks', 'roll_no');
$hasGsmLine = $colExists($conn, 'daily_gsm_checks', 'line_number');
$hasGsmStatus = $colExists($conn, 'daily_gsm_checks', 'status');
if ($hasGsmRoll && $hasGsmStatus) {
    // Match primarily by roll_no (required) and status = approved
    // Optionally match by reference_number if available, otherwise just roll_no is enough
    // This handles cases where reference_number might be NULL or line_number might not match
    $gsmRefMatch = '';
    if ($hasGsmRef) {
        $gsmRefMatch = "AND (dgc.reference_number COLLATE {$collation} = ftr.reference_number COLLATE {$collation} OR dgc.reference_number IS NULL)";
    }
    
    $gsmExistsClause = "
    AND EXISTS (
        SELECT 1 FROM daily_gsm_checks dgc 
        WHERE dgc.roll_no = ftr.roll_no 
        {$gsmRefMatch}
        AND LOWER(TRIM(dgc.status)) = 'approved'
    )";
}

$hasLcRef = $colExists($conn, 'length_calibrations', 'reference_number');
$hasLcRoll = $colExists($conn, 'length_calibrations', 'roll_no');
$hasLcLine = $colExists($conn, 'length_calibrations', 'line_number');
$hasLcStatus = $colExists($conn, 'length_calibrations', 'status');
if ($hasLcRoll && $hasLcStatus) {
    // Match primarily by roll_no (required) and status = approved
    // Optionally match by reference_number if available, otherwise just roll_no is enough
    // This handles cases where reference_number is NULL (as shown in debug output)
    $lcRefMatch = '';
    if ($hasLcRef) {
        $lcRefMatch = "AND (lc.reference_number COLLATE {$collation} = ftr.reference_number COLLATE {$collation} OR lc.reference_number IS NULL)";
    }
    
    $lcExistsClause = "
    AND EXISTS (
        SELECT 1 FROM length_calibrations lc 
        WHERE lc.roll_no = ftr.roll_no 
        {$lcRefMatch}
        AND LOWER(TRIM(lc.status)) = 'approved'
    )";
}

// Check for approved Roll QC Reports
$hasRqcRef = $colExists($conn, 'roll_qc_reports', 'reference_number');
$hasRqcApproved = $colExists($conn, 'roll_qc_reports', 'approved');
$hasRqcOverallStatus = $colExists($conn, 'roll_qc_reports', 'overall_status');

// Build Roll QC Report check clause
$rqcExistsClause = '';
if ($hasRqcRef) {
    if ($hasRqcApproved && $hasRqcOverallStatus) {
        // If both approved column and overall_status exist, check for approved = 1 OR overall_status IN ('approved', 'Done')
        $rqcExistsClause = "
        AND EXISTS (
            SELECT 1 FROM roll_qc_reports rqc 
            WHERE rqc.reference_number COLLATE {$collation} = ftr.reference_number COLLATE {$collation}
            AND (rqc.approved = 1 OR rqc.overall_status IN ('approved', 'Done'))
        )";
    } elseif ($hasRqcApproved) {
        // If only approved column exists, check for approved = 1
        $rqcExistsClause = "
        AND EXISTS (
            SELECT 1 FROM roll_qc_reports rqc 
            WHERE rqc.reference_number COLLATE {$collation} = ftr.reference_number COLLATE {$collation}
            AND rqc.approved = 1
        )";
    } elseif ($hasRqcOverallStatus) {
        // If only overall_status exists, check for 'approved' or 'Done' status
        $rqcExistsClause = "
        AND EXISTS (
            SELECT 1 FROM roll_qc_reports rqc 
            WHERE rqc.reference_number COLLATE {$collation} = ftr.reference_number COLLATE {$collation}
            AND rqc.overall_status IN ('approved', 'Done')
        )";
    } else {
        // If neither column exists, don't add the check (backward compatibility)
        // This allows the query to work even if roll_qc_reports table doesn't have approval columns yet
    }
}

$defaultProject = getDefaultProject($conn);
$projects = $defaultProject ? [$defaultProject] : [];

// Build the WHERE clause for roll_qc_reports subquery
$rqcWhereClause = '';
if ($hasRqcRef) {
    if ($hasRqcApproved && $hasRqcOverallStatus) {
        $rqcWhereClause = "AND (rqc.approved = 1 OR rqc.overall_status IN ('approved', 'Done'))";
    } elseif ($hasRqcApproved) {
        $rqcWhereClause = "AND rqc.approved = 1";
    } elseif ($hasRqcOverallStatus) {
        $rqcWhereClause = "AND rqc.overall_status IN ('approved', 'Done')";
    }
}

// Performance: Defer reference loading - will load asynchronously after page render
// Fetch reference numbers from fiber_to_roll_entry with material type and available weight
// ONLY if BOTH Daily GSM Check AND Length Calibration tests are APPROVED
// AND Roll QC Report is APPROVED
// Available weight = product_amount from approved roll_qc_reports - used_in_roll_entry
$referenceNumbers = [];
$refRes = $conn->query("
    SELECT 
        ftr.id, 
        ftr.reference_number, 
        ftr.material_type,
        ftr.roll_no,
        ftr.line_no,
        ftr.total_weight as original_weight,
        COALESCE((
            SELECT SUM(re2.total_weight)
            FROM roll_entry re2
            WHERE ftr.reference_number COLLATE {$collation} = re2.reference_number COLLATE {$collation}
               OR re2.reference_number COLLATE {$collation} LIKE CONCAT(ftr.reference_number COLLATE {$collation}, '-%')
        ), 0) as used_in_roll_entry,
        COALESCE((
            SELECT rqc.product_amount
            FROM roll_qc_reports rqc
            WHERE rqc.reference_number COLLATE {$collation} = ftr.reference_number COLLATE {$collation}
              AND (rqc.roll_no = ftr.roll_no OR (rqc.roll_no IS NULL AND ftr.roll_no IS NULL))
              {$rqcWhereClause}
            ORDER BY rqc.created_at DESC
            LIMIT 1
        ), 0) as qc_approved_amount,
        (COALESCE((
            SELECT rqc.product_amount
            FROM roll_qc_reports rqc
            WHERE rqc.reference_number COLLATE {$collation} = ftr.reference_number COLLATE {$collation}
              AND (rqc.roll_no = ftr.roll_no OR (rqc.roll_no IS NULL AND ftr.roll_no IS NULL))
              {$rqcWhereClause}
            ORDER BY rqc.created_at DESC
            LIMIT 1
        ), 0) - 
         COALESCE((
            SELECT SUM(re2.total_weight)
            FROM roll_entry re2
            WHERE ftr.reference_number COLLATE {$collation} = re2.reference_number COLLATE {$collation}
               OR re2.reference_number COLLATE {$collation} LIKE CONCAT(ftr.reference_number COLLATE {$collation}, '-%')
        ), 0)) as available_weight
    FROM fiber_to_roll_entry ftr
    WHERE ftr.reference_number IS NOT NULL
    {$gsmExistsClause}
    {$lcExistsClause}
    {$rqcExistsClause}
    HAVING available_weight > 0.01
    ORDER BY ftr.created_at DESC
");
if ($refRes) {
    while ($r = $refRes->fetch_assoc()) $referenceNumbers[] = $r;
}

// Material type options include "PP Stable Fiber" and "PSF Fiber"

// Generate next Entry ID
$current_date = date('Y-m-d');
$last_entry = $conn->query("SELECT MAX(CAST(SUBSTRING(entry_id, 13) AS UNSIGNED)) as last_num FROM roll_entry 
                            WHERE DATE(date_time) = '$current_date' AND entry_id LIKE 'RE-" . date('Ymd') . "-%'");
$next_entry_number = 1;
if ($last_entry && $last_entry->num_rows > 0) {
    $l = $last_entry->fetch_assoc();
    $next_entry_number = ($l['last_num'] ?? 0) + 1;
}
$next_entry_id = 'RE-' . date('Ymd') . '-' . str_pad($next_entry_number, 4, '0', STR_PAD_LEFT);

// Operator
$operator_id = $_SESSION['user_id'];
$operator_name = $_SESSION['username'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Roll Entry</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Inter', sans-serif;
      background-color: #f4f6f9;
      margin: 0;
      padding: 30px 20px;
      color: #2c3e50;
    }
    small {
      display: block;
      margin-top: 5px;
      font-size: 13px;
    }
    .container {
      max-width: 1000px;
      margin: auto;
      background: #fff;
      border-radius: 12px;
      padding: 30px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    }
    h1 {
      text-align: center;
      font-size: 28px;
      margin-bottom: 30px;
    }
    .form-group { margin-bottom: 20px; }
    label { font-weight: 600; display: block; margin-bottom: 8px; }
    input[type="text"],
    input[type="number"],
    textarea,
    select {
      width: 100%;
      padding: 12px;
      border: 2px solid #e1e5e9;
      border-radius: 8px;
      font-size: 14px;
      transition: border-color 0.3s;
    }
    input:focus, textarea:focus, select:focus {
      outline: none;
      border-color: #007bff;
    }
    .readonly {
      background-color: #f8f9fa;
      color: #6c757d;
    }
    .btn-group {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 8px;
    }
    .btn-group .btn {
      padding: 10px 16px;
      font-size: 14px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      background-color: #f8f9fa;
    }
    .btn-group .btn:hover {
      background-color: #ccc;
    }
    .btn-group .btn.selected {
      background-color: #3498db;
      color: white;
    }
    .actions {
      margin-top: 30px;
      text-align: center;
    }
    .actions button {
      padding: 10px 20px;
      font-size: 15px;
      border: none;
      border-radius: 6px;
      cursor: pointer;
      margin: 0 10px;
    }
    .submit-btn {
      background-color: #2ecc71;
      color: white;
    }
    .clear-btn {
      background-color: #e74c3c;
      color: white;
    }
    
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
    .summary-info {
      font-size: 16px;
      font-weight: bold;
      padding: 10px;
      border-radius: 8px;
      text-align: center;
      margin-bottom: 20px;
      background: #f0f0f0;
    }
    .alert {
      padding: 12px;
      border-radius: 8px;
      margin-bottom: 20px;
    }
    .alert-success {
      background: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
    }
    .alert-danger {
      background: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
    }
  </style>
</head>
<body>
  <div class="container">
    <h1>Roll Entry</h1>

    <?php if (isset($_GET['success'])): ?>
      <div class="alert alert-success"><?= htmlspecialchars($_GET['success']) ?></div>
    <?php endif; ?>
    
    <?php if (isset($_GET['qc_prompt']) && isset($_SESSION['last_roll_entry_reference'])): ?>
      <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 12px; margin-bottom: 20px; box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);">
        <div>
          <h3 style="margin: 0 0 8px 0; font-size: 20px;">
            <i class="fas fa-clipboard-check"></i> Roll Entry Submitted Successfully
          </h3>
          <p style="margin: 0; font-size: 15px; opacity: 0.95;">
            Roll Entry <strong><?= htmlspecialchars($_SESSION['last_roll_entry_reference']) ?></strong> has been submitted successfully. 
            <br>The roll will be tested for quality control.
          </p>
        </div>
      </div>
      <?php 
        // Clear the prompt after showing it once
        unset($_SESSION['show_qc_test_prompt']); 
      ?>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div>
    <?php endif; ?>

    <!-- Back to Dashboard Link -->
    <div style="margin-bottom: 15px;">
      <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
        ← Back to Dashboard
      </a>
    </div>

    <!-- Important Notice -->
    <div style="background:#fff3cd; border:2px solid #ffc107; border-radius:8px; padding:15px; margin-bottom:20px;">
      <div style="display:flex; align-items:center; gap:10px;">
        <span style="font-size:24px;">⚠️</span>
        <div>
          <strong style="color:#856404; font-size:16px;">Quality Control Requirement</strong>
          <p style="margin:5px 0 0 0; color:#856404; font-size:14px;">
            Only reference numbers with <strong>BOTH</strong> approved <strong>Daily GSM Check</strong> AND <strong>Length Calibration</strong> tests are available for Roll Entry.
            If you don't see a reference number, ensure both QC tests have been completed and approved.
          </p>
        </div>
      </div>
    </div>

    <div id="dateTimeDisplay" class="summary-info"></div>
    <div id="shiftBanner" class="summary-info"></div>

    <form id="rollForm" method="post" action="../handlers/submit_roll_entry.php" onsubmit="return validateAndSubmit();">

      <!-- Entry ID auto -->
      <div class="form-group">
        <label>Entry ID</label>
        <input type="text" id="entryIdDisplay" value="<?php echo htmlspecialchars($next_entry_id); ?>" readonly class="readonly">
        <input type="hidden" id="entryId" name="entry_id" value="<?php echo htmlspecialchars($next_entry_id); ?>">
      </div>

      <!-- Multiple Rolls Question -->
      <div class="form-group">
        <label>Do you want to add more than 1 roll?</label>
        <div class="btn-group" id="multipleRollsGroup">
          <button type="button" class="btn" onclick="setMultipleRolls(false, this)">No (Single Roll)</button>
          <button type="button" class="btn" onclick="setMultipleRolls(true, this)">Yes (Multiple Rolls)</button>
        </div>
      </div>

      <!-- Single Roll Mode -->
      <div id="singleRollMode" style="display:none;">
        <div class="form-group">
          <label>Reference Number:</label>
          <select id="reference_number_single" onchange="updateSingleReference()" required>
            <option value="">Select Reference Number</option>
            <?php foreach($referenceNumbers as $ref): ?>
            <option value="<?php echo htmlspecialchars($ref['reference_number']); ?>"
                    data-material-type="<?php echo htmlspecialchars($ref['material_type'] ?? ''); ?>"
                    data-available-weight="<?php echo $ref['available_weight']; ?>"
                    data-original-weight="<?php echo $ref['original_weight']; ?>">
              <?php echo htmlspecialchars($ref['reference_number']); ?> - Available: <?php echo number_format($ref['available_weight'], 2); ?> kg
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Multiple Rolls Mode -->
      <div id="multipleRollsMode" style="display:none;">
        <div class="form-group">
          <label>Base Reference Number:</label>
          <select id="reference_number_base" onchange="updateReferenceDisplay()">
            <option value="">Select Reference Number</option>
            <?php foreach($referenceNumbers as $ref): ?>
            <option value="<?php echo htmlspecialchars($ref['reference_number']); ?>"
                    data-material-type="<?php echo htmlspecialchars($ref['material_type'] ?? ''); ?>"
                    data-available-weight="<?php echo $ref['available_weight']; ?>"
                    data-original-weight="<?php echo $ref['original_weight']; ?>">
              <?php echo htmlspecialchars($ref['reference_number']); ?> - Available: <?php echo number_format($ref['available_weight'], 2); ?> kg
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Number of Rolls:</label>
          <input type="number" step="1" id="number_of_rolls_input" min="2" max="10" value="2" onchange="updateReferenceDisplay()" oninput="updateReferenceDisplay()" placeholder="Enter number of rolls (2-10)">
          <small style="color: #666; display: block; margin-top: 5px;">How many rolls to create? (e.g., 3 will create -1, -2, -3)</small>
        </div>

        <div class="form-group">
          <label>Final Reference Number:</label>
          <input type="text" id="reference_number_display" placeholder="Will show final reference" readonly class="readonly">
        </div>
      </div>

      <!-- Hidden fields -->
      <input type="hidden" id="reference_number" name="reference_number">
      <input type="hidden" id="number_of_rolls" name="number_of_rolls" value="1">

      <!-- Hidden datetime -->
      <input type="hidden" id="dateTime" name="date_time">

      <!-- Operator -->
      <div class="form-group">
        <label>Operator</label>
        <input type="text" value="<?php echo htmlspecialchars($operator_name); ?>" readonly class="readonly">
        <input type="hidden" name="operator_id" value="<?php echo $operator_id; ?>">
      </div>

      <!-- Project -->
      <div class="form-group">
        <label>Project</label>
        <div class="btn-group" id="projectGroup">
          <?php foreach($projects as $p): ?>
          <button type="button" class="btn selected" data-id="<?php echo $p['id']; ?>" onclick="selectBtn(this,'projectGroup')">
            <?php echo htmlspecialchars($p['project_name']); ?>
          </button>
          <?php endforeach; ?>
        </div>
        <input type="hidden" id="project_id" name="project_id" value="<?php echo isset($projects[0]['id']) ? (int)$projects[0]['id'] : 0; ?>">
      </div>

      <!-- Material Type -->
      <div class="form-group">
        <label>Material Type:</label>
      <div class="btn-group" id="materialTypeGroup">
        <button type="button" class="btn" data-value="PP Stable Fiber" onclick="selectBtn(this, 'materialTypeGroup')">PP Stable Fiber</button>
        <button type="button" class="btn" data-value="PSF Fiber" onclick="selectBtn(this, 'materialTypeGroup')">PSF Fiber</button>
      </div>
        <input type="hidden" id="material_type" name="material_type" value="">
      </div>

      <!-- Roll Size (Buttons + Manual Input) -->
      <div class="form-group">
        <label>Roll Size:</label>
        <div class="btn-group" id="rollSizeGroup">
          <button type="button" class="btn" onclick="selectRollSize('GEOCIL-50 (4X100MTR)', this)">GEOCIL-50 (4X100MTR)</button>
          <button type="button" class="btn" onclick="selectRollSize('GEOCIL-60 (4X100MTR)', this)">GEOCIL-60 (4X100MTR)</button>
          <button type="button" class="btn" onclick="selectRollSize('GEOCIL-70 (4X90 MTR)', this)">GEOCIL-70 (4X90 MTR)</button>
          <button type="button" class="btn" onclick="selectRollSize('GEOCIL-80 (4X75 MTR)', this)">GEOCIL-80 (4X75 MTR)</button>
          <button type="button" class="btn" onclick="selectRollSize('GEOCIL-90 (4X70 MTR)', this)">GEOCIL-90 (4X70 MTR)</button>
          <button type="button" class="btn" onclick="selectRollSize('GEOCIL-100 (4X60 MTR)', this)">GEOCIL-100 (4X60 MTR)</button>
          <button type="button" class="btn" onclick="selectRollSize('GEOCIL-110 (4X60 MTR)', this)">GEOCIL-110 (4X60 MTR)</button>
          <button type="button" class="btn" onclick="selectRollSize('GEOCIL-70 (4.06X35.5 MTR)', this)">GEOCIL-70 (4.06X35.5 MTR)</button>
          <button type="button" class="btn" onclick="selectRollSize('GEOCIL 70 (2X2 MTR)', this)">GEOCIL 70 (2X2 MTR)</button>
          <button type="button" class="btn" onclick="selectRollSize('GEOCIL-70 (4.5X35.5 MTR)', this)">GEOCIL-70 (4.5X35.5 MTR)</button>
          <button type="button" class="btn" onclick="selectRollSize('GEOCIL 100 (3.93X24 MTR)', this)">GEOCIL 100 (3.93X24 MTR)</button>
          <button type="button" class="btn" onclick="selectRollSize('GEOCIL 20 (100X4 MTR)', this)">GEOCIL 20 (100X4 MTR)</button>
          <button type="button" class="btn" onclick="selectRollSize('GEOCIL-70 (4.08X50.8 MTR)', this)">GEOCIL-70 (4.08X50.8 MTR)</button>
          <button type="button" class="btn" onclick="selectRollSize('GEOCIL-40  (4X100 MTR)', this)">GEOCIL-40  (4X100 MTR)</button>
          <button type="button" class="btn" onclick="selectRollSize('GEOCIL 70 (4.06X35.5 MTR)(White)', this)">GEOCIL 70 (4.06X35.5 MTR)(White)</button>
          <button type="button" class="btn" onclick="selectRollSize('Geocil-70 | 4.06x51 Mtr)', this)">Geocil-70 | 4.06x51 Mtr)</button>
          <button type="button" class="btn" onclick="selectRollSize('custom', this)" style="background:#6c757d;color:#fff;">Custom (Enter manually)</button>
        </div>
        <input type="text" id="roll_size_custom" placeholder="Enter custom roll size" style="margin-top: 10px; display:none;">
        <input type="hidden" id="roll_size" name="roll_size" value="">
      </div>

      <!-- Total weight -->
      <div class="form-group">
        <label>Total Weight (kg):</label>
        <input type="number" step="0.01" id="total_weight" name="total_weight" required min="0.01" oninput="validateTotalWeight(); calculateActualGSM();">
        <small id="available_weight_text" style="color: #27ae60; font-weight: 600; display: none; margin-top: 5px;">
          Available from roll: <span id="available_weight_value">0</span> kg
        </small>
        <small id="weight_warning" style="color: #e74c3c; font-weight: 600; display: none; margin-top: 5px;"></small>
      </div>

      <!-- Total Area (sqm) -->
      <div class="form-group">
        <label>Total Area (sqm):</label>
        <input type="number" step="0.01" id="total_area" name="total_area" required min="0.01" oninput="calculateActualGSM();">
      </div>

      <!-- Actual GSM (Auto-calculated) -->
      <div class="form-group">
        <label>Actual GSM:</label>
        <input type="number" step="0.01" id="actual_gsm" name="actual_gsm" readonly class="readonly" style="background:#e8f5e9; font-weight:bold;">
        <small style="color: #666; font-style: italic; display: block; margin-top: 5px;">
          Formula: (Total Weight / Total Area) × 1000
        </small>
      </div>

       <!-- Summary Section -->
       <div class="form-group">
         <div id="summaryBox" class="summary-info"></div>
         <input type="hidden" id="summary" name="summary">
       </div>

      <div class="actions">
        <button type="submit" class="submit-btn">Submit</button>
        <button type="button" class="clear-btn" onclick="clearForm()">Clear</button>
      </div>
    </form>
  </div>

  <script>
    let availableWeight = 0;
    let lastPopupWeight = null;
    
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
      updateEntryIdDisplay();
    }

    function updateEntryIdDisplay() {
      const rollNumber = document.querySelector('input[name="roll_number"]').value || '0001';
      const today = new Date();
      const dateStr = today.getFullYear() + 
                     String(today.getMonth() + 1).padStart(2, '0') + 
                     String(today.getDate()).padStart(2, '0');
      const entryId = 'RE-' + dateStr + '-' + String(rollNumber).padStart(4, '0');
      document.getElementById("entryIdDisplay").value = entryId;
      document.getElementById("entryId").value = entryId;
    }

    function updateBatchNumberDisplay() {
      const gsm = document.getElementById('gsm').value || '';
      const lineNo = document.getElementById('line_no').value || '';
      const materialType = document.getElementById('material_type').value || '';
      if (gsm && lineNo && materialType) {
        const now = new Date();
        const utc = now.getTime() + now.getTimezoneOffset()*60000;
        const dhaka = new Date(utc + 6*3600000);
        const hour = dhaka.getHours();
        let shiftDate = new Date(dhaka);
        if (hour < 8) { shiftDate.setDate(shiftDate.getDate() - 1); }
        const year = String(shiftDate.getFullYear()).slice(-2);
        const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const month = monthNames[shiftDate.getMonth()];
        const day = String(shiftDate.getDate()).padStart(2, '0');
        const dateStr = year + month + day;
        const rollNumber = document.querySelector('input[name="roll_number"]').value || '1';
        const batchPreview = gsm + 'L' + lineNo + materialType + dateStr + rollNumber;
        document.getElementById("batchNumberDisplay").value = batchPreview;
      } else {
        document.getElementById("batchNumberDisplay").value = "Auto-generated on submit";
      }
    }

     function updateSummary() {
       const dateTime = document.getElementById("dateTime").value;
       const shift = document.getElementById("shiftBanner").innerText.replace("Shift: ", "");
       const operator = "<?php echo htmlspecialchars($operator_name); ?>";
       const entryId = document.getElementById("entryIdDisplay").value;
       
       // Get reference from either single or multiple mode
       let refDisplay = '';
       const singleMode = document.getElementById('singleRollMode').style.display !== 'none';
       if (singleMode) {
         refDisplay = document.getElementById('reference_number_single').value;
       } else {
         refDisplay = document.getElementById('reference_number_display').value;
       }
       
      const numberOfRolls = parseInt(document.getElementById('number_of_rolls').value) || parseInt(document.getElementById('number_of_rolls_input').value) || 1;
      const projectBtn = document.querySelector("#projectGroup .btn.selected");
      const project = projectBtn ? projectBtn.innerText : "";
      const materialType = document.getElementById("material_type").value;
      const rollSize = document.getElementById("roll_size").value;
      const totalWeight = document.getElementById("total_weight").value;
      const totalArea = document.getElementById("total_area").value;
      const actualGSM = document.getElementById("actual_gsm").value;

      let summary = `${dateTime} | Shift: ${shift} | Operator: ${operator}`;
      if (entryId) summary += ` | Entry ID: ${entryId}`;
      if (refDisplay) summary += ` | Ref: ${refDisplay}`;
      if (project) summary += ` | Project: ${project}`;
      if (materialType) summary += ` | Material: ${materialType}`;
      if (rollSize) summary += ` | Size: ${rollSize}`;
      if (numberOfRolls && numberOfRolls > 1) summary += ` | Rolls: ${numberOfRolls}`;
      if (totalWeight) summary += ` | Weight: ${totalWeight} kg`;
      if (totalArea) summary += ` | Area: ${totalArea} sqm`;
      if (actualGSM) summary += ` | Actual GSM: ${actualGSM}`;

       document.getElementById("summaryBox").innerText = summary;
       document.getElementById("summary").value = summary;
     }

    setInterval(updateTimeAndShift,1000); 
    updateTimeAndShift();

    document.getElementById('total_weight').addEventListener('input', function() {
      validateTotalWeight();
      calculateActualGSM();
      updateSummary();
    });
    document.getElementById('total_area').addEventListener('input', function() {
      calculateActualGSM();
      updateSummary();
    });
    
    // Add event listeners for reference changes
    document.getElementById('reference_number_single').addEventListener('change', updateSummary);
    document.getElementById('reference_number_display').addEventListener('input', updateSummary);
    document.getElementById('number_of_rolls').addEventListener('change', updateSummary);
    document.getElementById('number_of_rolls_input').addEventListener('input', updateSummary);
    
    // Update summary on page load
    updateSummary();
    
    // Calculate Actual GSM function
    function calculateActualGSM() {
        const totalWeight = parseFloat(document.getElementById('total_weight').value) || 0;
        const totalArea = parseFloat(document.getElementById('total_area').value) || 0;
        const actualGSMInput = document.getElementById('actual_gsm');
        
        if (totalWeight > 0 && totalArea > 0) {
            const actualGSM = (totalWeight / totalArea) * 1000;
            actualGSMInput.value = actualGSM.toFixed(2);
        } else {
            actualGSMInput.value = '';
        }
    }

    // Toggle between single and multiple roll modes
    function setMultipleRolls(isMultiple, button) {
      // Highlight selected button
      const group = document.getElementById('multipleRollsGroup');
      group.querySelectorAll('.btn').forEach(btn => btn.classList.remove('selected'));
      button.classList.add('selected');
      
      if (isMultiple) {
        document.getElementById('singleRollMode').style.display = 'none';
        document.getElementById('multipleRollsMode').style.display = 'block';
        document.getElementById('reference_number_single').removeAttribute('required');
        document.getElementById('reference_number_base').setAttribute('required', 'required');
        // Reset single mode
        document.getElementById('reference_number_single').value = '';
        // Set number_of_rolls to 2 for multiple mode
        document.getElementById('number_of_rolls').value = '2';
      } else {
        document.getElementById('singleRollMode').style.display = 'block';
        document.getElementById('multipleRollsMode').style.display = 'none';
        document.getElementById('reference_number_base').removeAttribute('required');
        document.getElementById('reference_number_single').setAttribute('required', 'required');
        // Reset multiple mode
        document.getElementById('reference_number_base').value = '';
        document.getElementById('number_of_rolls_input').value = '2';
        document.getElementById('reference_number_display').value = '';
        // Set number_of_rolls to 1 for single mode
        document.getElementById('number_of_rolls').value = '1';
      }
      document.getElementById('reference_number').value = '';
      updateSummary();
    }

    // Update reference for single roll mode
    function updateSingleReference() {
      const refSelect = document.getElementById('reference_number_single');
      const refNumber = refSelect.value;
      document.getElementById('reference_number').value = refNumber;
      
      if (!refNumber) {
        // Clear material type selection
        const materialTypeGroup = document.querySelectorAll('#materialTypeGroup .btn');
        materialTypeGroup.forEach(btn => btn.classList.remove('selected'));
        document.getElementById('material_type').value = '';
        // Reset weight tracking
        availableWeight = 0;
        document.getElementById('available_weight_text').style.display = 'none';
        document.getElementById('weight_warning').style.display = 'none';
        document.getElementById('total_weight').value = '';
        document.getElementById('total_weight').max = '';
        document.getElementById('total_area').value = '';
        document.getElementById('actual_gsm').value = '';
        updateSummary();
        return;
      }
      
      // Get material type and available weight from selected option
      const selectedOption = refSelect.options[refSelect.selectedIndex];
      const materialType = selectedOption.getAttribute('data-material-type');
      const availWeight = parseFloat(selectedOption.getAttribute('data-available-weight')) || 0;
      
      // Store available weight
      availableWeight = availWeight;
      
      // Show available weight
      document.getElementById('available_weight_value').textContent = availWeight.toFixed(2);
      document.getElementById('available_weight_text').style.display = 'block';
      
      // Set max weight
      document.getElementById('total_weight').max = availWeight;
      
      setMaterialTypeSelection(materialType);
      
      updateSummary();
    }

    // Update reference for multiple rolls mode
    function updateReferenceDisplay() {
      const baseRefSelect = document.getElementById('reference_number_base');
      const baseRef = baseRefSelect.value;
      const numberOfRolls = parseInt(document.getElementById('number_of_rolls_input').value) || 2;
      
      if (!baseRef) {
        document.getElementById('reference_number_display').value = '';
        document.getElementById('reference_number').value = '';
        // Clear material type selection
        const materialTypeGroup = document.querySelectorAll('#materialTypeGroup .btn');
        materialTypeGroup.forEach(btn => btn.classList.remove('selected'));
        document.getElementById('material_type').value = '';
        // Reset weight tracking
        availableWeight = 0;
        document.getElementById('available_weight_text').style.display = 'none';
        document.getElementById('weight_warning').style.display = 'none';
        document.getElementById('total_weight').value = '';
        document.getElementById('total_weight').max = '';
        document.getElementById('total_area').value = '';
        document.getElementById('actual_gsm').value = '';
        updateSummary();
        return;
      }
      
      // Get material type and available weight from selected option
      const selectedOption = baseRefSelect.options[baseRefSelect.selectedIndex];
      const materialType = selectedOption.getAttribute('data-material-type');
      const availWeight = parseFloat(selectedOption.getAttribute('data-available-weight')) || 0;
      
      // Store available weight
      availableWeight = availWeight;
      
      // Show available weight
      document.getElementById('available_weight_value').textContent = availWeight.toFixed(2);
      document.getElementById('available_weight_text').style.display = 'block';
      
      // Set max weight
      document.getElementById('total_weight').max = availWeight;
      
      setMaterialTypeSelection(materialType);
      
      // Store base reference in hidden field
      document.getElementById('reference_number').value = baseRef;
      
      // Update the hidden number_of_rolls field
      document.getElementById('number_of_rolls').value = numberOfRolls;
      
      // Show display with the LAST roll number
      // If 3 rolls selected, show reference ending with -3
      const refWithRollNumber = `${baseRef}-${numberOfRolls}`;
      document.getElementById('reference_number_display').value = refWithRollNumber;
      
      updateSummary();
    }
    
    // Validate total weight doesn't exceed available
    function validateTotalWeight() {
      const totalWeightInput = document.getElementById('total_weight');
      const weight = parseFloat(totalWeightInput.value) || 0;
      const warningElement = document.getElementById('weight_warning');
      
      if (availableWeight > 0 && weight > availableWeight) {
        warningElement.textContent = `⚠️ Weight exceeds available quantity (${availableWeight.toFixed(2)} kg)`;
        warningElement.style.display = 'block';
        totalWeightInput.setCustomValidity('Weight exceeds available quantity');
        totalWeightInput.style.borderColor = '#e74c3c';
        totalWeightInput.style.border = '2px solid #e74c3c';
        
        // Show popup notification (only once per weight value to avoid spam)
        if (lastPopupWeight !== weight) {
          showQtyLimitPopup(weight, availableWeight);
          lastPopupWeight = weight;
        }
      } else {
        // Reset popup tracking when weight is valid
        if (weight <= availableWeight) {
          lastPopupWeight = null;
        }
        
        warningElement.style.display = 'none';
        totalWeightInput.setCustomValidity('');
        totalWeightInput.style.borderColor = '#e1e5e9';
        totalWeightInput.style.border = '2px solid #e1e5e9';
      }
      
      updateSummary();
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
          
          // Focus back on total_weight input field
          setTimeout(() => {
            const weightInput = document.getElementById('total_weight');
            if (weightInput) {
              weightInput.focus();
              weightInput.select();
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

    function selectRollSize(value, btn){
      document.querySelectorAll('#rollSizeGroup .btn').forEach(b=>b.classList.remove('selected'));
      btn.classList.add('selected');
      const hidden = document.getElementById('roll_size');
      const custom = document.getElementById('roll_size_custom');
      if(value==='custom'){
        hidden.value = '';
        custom.style.display = 'block';
        custom.focus();
      } else {
        custom.style.display = 'none';
        custom.value = '';
        hidden.value = value;
      }
      updateSummary();
    }

    // Update hidden when typing custom (already added above, keeping this for compatibility)
    const rollSizeCustomEl = document.getElementById('roll_size_custom');
    if (rollSizeCustomEl && !rollSizeCustomEl.hasAttribute('data-listener-added')) {
      rollSizeCustomEl.addEventListener('input', function(){
        document.getElementById('roll_size').value = this.value;
        updateSummary();
      });
      rollSizeCustomEl.setAttribute('data-listener-added', 'true');
    }

    function selectBtn(btn, groupId){
      document.querySelectorAll(`#${groupId} .btn`).forEach(b=>b.classList.remove('selected'));
      btn.classList.add('selected');
      if(groupId==="projectGroup"){
        document.getElementById("project_id").value = btn.dataset.id;
      }
      if(groupId==="materialTypeGroup"){
        document.getElementById("material_type").value = btn.dataset.value || btn.innerText.trim();
        updateBatchNumberDisplay();
      }
      updateSummary();
    }

    function setMaterialTypeSelection(preferredType) {
      const buttons = Array.from(document.querySelectorAll('#materialTypeGroup .btn'));
      buttons.forEach(btn => btn.classList.remove('selected'));
      const targetType = (preferredType || '').trim();
      let targetBtn = buttons.find(btn => ((btn.dataset.value || btn.innerText).trim()) === targetType);
      if (!targetBtn) {
        targetBtn = buttons.find(btn => ((btn.dataset.value || btn.innerText).trim()) === 'PP Stable Fiber');
      }
      if (!targetBtn && buttons.length > 0) {
        targetBtn = buttons[0];
      }
      if (targetBtn) {
        targetBtn.classList.add('selected');
        document.getElementById("material_type").value = (targetBtn.dataset.value || targetBtn.innerText).trim();
      } else {
        document.getElementById("material_type").value = '';
      }
      updateBatchNumberDisplay();
    }

    function clearForm(){
      document.getElementById("gsm").value = "";
      document.getElementById("line_no").value = "";
      document.getElementById("material_type").value = "";
      document.getElementById("total_weight").value = "";
      document.getElementById("total_area").value = "";
      document.getElementById("actual_gsm").value = "";
      document.querySelectorAll('#projectGroup .btn').forEach(b=>b.classList.remove('selected'));
      const defaultProjectBtn = document.querySelector('#projectGroup .btn');
      if (defaultProjectBtn) {
        defaultProjectBtn.classList.add('selected');
        document.getElementById("project_id").value = defaultProjectBtn.dataset.id || '';
      } else {
        document.getElementById("project_id").value="";
      }
      document.querySelectorAll('#materialTypeGroup .btn').forEach(b=>b.classList.remove('selected'));
      document.getElementById("material_type").value="";
      updateEntryIdDisplay();
      updateBatchNumberDisplay();
      updateSummary();
    }

    function validateAndSubmit(){
      if(!document.getElementById("project_id").value){ alert("Please select a project."); return false; }
      if(!document.getElementById("gsm").value){ alert("Please enter GSM."); return false; }
      if(!document.getElementById("line_no").value){ alert("Please enter Line Number."); return false; }
      if(!document.getElementById("material_type").value){ alert("Please select Material Type."); return false; }
      if(!document.getElementById("total_weight").value){ alert("Please enter Total Weight."); return false; }
      if(!document.getElementById("total_area").value){ alert("Please enter Total Area (sqm)."); return false; }
      
      // Validate weight doesn't exceed available
      const totalWeight = parseFloat(document.getElementById('total_weight').value) || 0;
      if (availableWeight > 0 && totalWeight > availableWeight) {
        // Show popup instead of alert
        showQtyLimitPopup(totalWeight, availableWeight);
        return false;
      }
      
      return true;
    }
    
    // Default material type selection on page load
    window.addEventListener('DOMContentLoaded', function() {
      setMaterialTypeSelection('PP Stable Fiber');
    });
  </script>

  <!-- Quantity Limit Exceeded Popup -->
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

