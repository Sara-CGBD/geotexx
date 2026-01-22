<?php
session_start();
require_once 'security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();
$reporter_id = $_SESSION['user_id'];
$reporter_name = $_SESSION['username'];

// Fetch full name from database
$reporter_full_name = $reporter_name;
try {
    $stmt = $conn->prepare("SELECT full_name FROM users WHERE username = ?");
    $stmt->bind_param("s", $reporter_name);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $reporter_full_name = $row['full_name'] ?: $reporter_name;
    }
    $stmt->close();
} catch (Exception $e) {
    $reporter_full_name = $reporter_name;
}

// Check user role - only testers can access this page
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$is_checker = ($user_role === 'checker');
$can_approve = in_array($user_role, ['admin', 'agm ops', 'agm operations'], true);

if ($is_checker || $can_approve) {
    die('Access denied. Only testers can edit rejected tests.');
}

$report_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($report_id <= 0) {
    die('Invalid report ID');
}

$message = '';
$error = '';

// Fetch the rejected report (must belong to this user and be rejected)
$stmt = $conn->prepare("SELECT * FROM water_permeability_tests WHERE id = ? AND reporter_id = ? AND status = 'rejected'");
$stmt->bind_param("ii", $report_id, $reporter_id);
$stmt->execute();
$result = $stmt->get_result();
$report = $result->fetch_assoc();
$stmt->close();

if (!$report) {
    die('Report not found or you do not have permission to edit it.');
}

// Decode test data JSON
$test_data = json_decode($report['test_results'], true);

// Filter out empty experimental data rows - only include rows with actual data
if (isset($test_data['experimental_data']) && is_array($test_data['experimental_data'])) {
    $filtered_experimental_data = [];
    foreach ($test_data['experimental_data'] as $row) {
        // Only include rows that have at least one meaningful value (not empty, not zero)
        $has_h0 = isset($row['h0']) && trim($row['h0']) !== '' && floatval($row['h0']) != 0;
        $has_t1 = isset($row['t1']) && trim($row['t1']) !== '' && floatval($row['t1']) != 0;
        $has_h1 = isset($row['h1']) && trim($row['h1']) !== '' && floatval($row['h1']) != 0;
        $has_t2 = isset($row['t2']) && trim($row['t2']) !== '' && floatval($row['t2']) != 0;
        $has_thickness = isset($row['thickness']) && trim($row['thickness']) !== '' && floatval($row['thickness']) != 0;
        $has_water_level = isset($row['water_level']) && trim($row['water_level']) !== '' && floatval($row['water_level']) != 0;
        $has_temp = isset($row['temp']) && trim($row['temp']) !== '' && floatval($row['temp']) != 0;
        $has_correction = isset($row['correction']) && trim($row['correction']) !== '' && floatval($row['correction']) != 0;
        $has_time = isset($row['time']) && trim($row['time']) !== '' && floatval($row['time']) != 0;
        
        if ($has_h0 || $has_t1 || $has_h1 || $has_t2 || $has_thickness || $has_water_level || $has_temp || $has_correction || $has_time) {
            $filtered_experimental_data[] = $row;
        }
    }
    $test_data['experimental_data'] = $filtered_experimental_data;
}

// Get reference number from the rejected report
$original_reference_number = $report['reference_number'] ?? '';
$original_bundle_reference = $report['bundle_reference'] ?? '';

// If reference_number is truncated or too short, try to get full reference from roll_entry
$full_reference_number = $original_reference_number;
if (!empty($original_reference_number) && (strlen(trim($original_reference_number)) <= 3 || !preg_match('/[A-Za-z]/', $original_reference_number))) {
    $lookupStmt = $conn->prepare("SELECT reference_number FROM roll_entry 
        WHERE reference_number LIKE ? 
           OR reference_number LIKE ? 
           OR reference_number LIKE ?
        ORDER BY date_time DESC 
        LIMIT 1");
    $likePattern1 = '%' . trim($original_reference_number) . '%';
    $likePattern2 = trim($original_reference_number) . '-%';
    $likePattern3 = trim($original_reference_number) . '%';
    $lookupStmt->bind_param("sss", $likePattern1, $likePattern2, $likePattern3);
    $lookupStmt->execute();
    $lookupResult = $lookupStmt->get_result();
    if ($lookupRow = $lookupResult->fetch_assoc()) {
        $full_reference_number = $lookupRow['reference_number'];
    }
    $lookupStmt->close();
}

// Clean rejection remarks
$rejection_reason = $report['remarks'] ?? 'No comments';
$rejection_reason = preg_replace('/\[Checker Rejection\]:\s*/i', '', $rejection_reason);
$rejection_reason = preg_replace('/\[Admin Rejection\]:\s*/i', '', $rejection_reason);
$rejection_reason = trim($rejection_reason);

$rejected_by = $report['checked_by'] ?? $report['approved_by'] ?? 'Unknown';

// Handle form submission - create NEW test with corrected data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Collect ALL form data (matching the original form exactly)
        $data = [
            'test_name' => trim($_POST['test_name']),
            'test_date' => $_POST['test_date'],
            'specimen_area' => floatval($_POST['specimen_area']),
            'water_temperature' => floatval($_POST['water_temperature']),
            'correction_factor' => floatval($_POST['correction_factor']),
            'water_type' => trim($_POST['water_type']),
            'number_of_specimens' => intval($_POST['number_of_specimens']),
            'relative_humidity' => trim($_POST['relative_humidity']),
            'dissolved_oxygen' => trim($_POST['dissolved_oxygen']),
            'area_specimen' => floatval($_POST['area_specimen']),
            'area_pipe' => floatval($_POST['area_pipe']),
            'lab_temp' => floatval($_POST['lab_temp']),
            'avg_water_temp' => floatval($_POST['avg_water_temp']),
            'avg_correction_factor' => floatval($_POST['avg_correction_factor']),
            'avg_permeability' => floatval($_POST['avg_permeability']),
            'avg_velocity' => floatval($_POST['avg_velocity']),
            'remarks' => trim($_POST['remarks'] ?? ''),
            'reporter_id' => $reporter_id,
            'reporter_name' => $reporter_name
        ];
        
        // Collect experimental data (dynamic rows)
        $experimental_data = [];
        $i = 1;
        while (isset($_POST["exp_h0_$i"])) {
            $experimental_data[] = [
                'no' => $i,
                'h0' => floatval($_POST["exp_h0_$i"] ?? 0),
                't1' => floatval($_POST["exp_t1_$i"] ?? 0),
                'h1' => floatval($_POST["exp_h1_$i"] ?? 0),
                't2' => floatval($_POST["exp_t2_$i"] ?? 0),
                'thickness' => floatval($_POST["exp_thickness_$i"] ?? 0),
                'water_level' => floatval($_POST["exp_water_level_$i"] ?? 0),
                'temp' => floatval($_POST["exp_temp_$i"] ?? 0),
                'correction' => floatval($_POST["exp_correction_$i"] ?? 0),
                'head_diff' => floatval($_POST["exp_head_diff_$i"] ?? 0),
                'time' => floatval($_POST["exp_time_$i"] ?? 0),
                'velocity' => floatval($_POST["exp_velocity_$i"] ?? 0),
                'permeability' => floatval($_POST["exp_permeability_$i"] ?? 0)
            ];
            $i++;
        }
        
        $data['experimental_data'] = $experimental_data;
        
        // Get reference number from form - use original_reference_number as primary source since it's auto-fetched
        // Use auto-fetched reference number from rejected report (priority)
        $reference_number = trim($_POST['original_reference_number'] ?? $full_reference_number ?? $_POST['reference_number'] ?? '');
        $bundle_reference = trim($_POST['original_bundle_reference'] ?? $original_bundle_reference ?? $_POST['bundle_reference'] ?? '');
        
        // Validate reference
        if (empty($reference_number)) {
            throw new Exception("Reference number is required. Please ensure the reference number is set.");
        }
        
        // If reference_number is too short, try to find full reference from roll_entry
        if (strlen(trim($reference_number)) <= 3 || !preg_match('/[A-Za-z]/', $reference_number)) {
            $lookupStmt = $conn->prepare("SELECT reference_number FROM roll_entry 
                WHERE reference_number LIKE ? 
                   OR reference_number LIKE ? 
                   OR reference_number LIKE ?
                ORDER BY date_time DESC 
                LIMIT 1");
            $likePattern1 = '%' . trim($reference_number) . '%';
            $likePattern2 = trim($reference_number) . '-%';
            $likePattern3 = trim($reference_number) . '%';
            $lookupStmt->bind_param("sss", $likePattern1, $likePattern2, $likePattern3);
            $lookupStmt->execute();
            $lookupResult = $lookupStmt->get_result();
            if ($lookupRow = $lookupResult->fetch_assoc()) {
                $reference_number = $lookupRow['reference_number'];
            }
            $lookupStmt->close();
        }
        
        $data['reference_number'] = $reference_number;
        $data['bundle_reference'] = $bundle_reference;
        
        // Define generateLabTestNumber function directly here to avoid session conflict
        function generateLabTestNumber($conn) {
            $today = date('Y-m-d');
            $stmt = $conn->prepare("SELECT MAX(CAST(lab_test_number AS UNSIGNED)) as max_num 
                                    FROM water_permeability_tests 
                                    WHERE DATE(created_at) = ?");
            $stmt->bind_param("s", $today);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $max_num = (int)($row['max_num'] ?? 0);
            $stmt->close();
            
            $next_num = $max_num + 1;
            return str_pad($next_num, 2, '0', STR_PAD_LEFT);
        }
        
        // Define createWaterPermeabilityTable function
        function createWaterPermeabilityTable($conn) {
            // First check if table exists and add 'resubmitted' to enum if needed
            $check_table = "SHOW TABLES LIKE 'water_permeability_tests'";
            $result = $conn->query($check_table);
            
            if ($result && $result->num_rows > 0) {
                // Table exists, alter enum to add 'resubmitted' if not present
                $conn->query("ALTER TABLE water_permeability_tests MODIFY COLUMN status ENUM('pending', 'checked', 'approved', 'rejected', 'resubmitted') DEFAULT 'pending'");
            } else {
                // Create new table with 'resubmitted' status
                $create_table = "CREATE TABLE IF NOT EXISTS water_permeability_tests (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    report_number VARCHAR(100) UNIQUE NOT NULL,
                    lab_test_number VARCHAR(50) NOT NULL,
                    test_date DATE NOT NULL,
                    test_results JSON NOT NULL,
                    test_performed_by VARCHAR(255) NOT NULL,
                    checked_by VARCHAR(255) NULL,
                    approved_by VARCHAR(255) NULL,
                    checked_at DATETIME NULL,
                    approved_at DATETIME NULL,
                    reporter_id INT NOT NULL,
                    reporter_name VARCHAR(255) NOT NULL,
                    status ENUM('pending', 'checked', 'approved', 'rejected', 'resubmitted') DEFAULT 'pending',
                    remarks TEXT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_report_number (report_number),
                    INDEX idx_status (status),
                    INDEX idx_created_at (created_at),
                    INDEX idx_reporter_id (reporter_id)
                )";
                
                if (!$conn->query($create_table)) {
                    throw new Exception("Error creating table: " . $conn->error);
                }
            }
        }
        
        // Create table if not exists
        createWaterPermeabilityTable($conn);
        
        // Function to determine current shift
        function getCurrentShift() {
            $hour = intval(date('H'));
            if ($hour >= 8 && $hour < 20) {
                return 'Day';
            } else {
                return 'Night';
            }
        }
        
        // Generate new lab test number (synchronized)
        $generated_lab_test_no = generateLabTestNumber($conn);
        $data['lab_test_no'] = $generated_lab_test_no;
        
        // Determine current shift
        $current_shift = getCurrentShift();
        
        // Sample ID is not generated (GSM and Roll Number removed)
        $data['sample_id'] = null;
        
        // Generate NEW Report Number in format: WPT-YYYYMMDD-XXXXX (resets at 8 AM daily)
        // Use MAX to get the highest number for today, then increment
        $hour = intval(date('H'));
        if ($hour < 8) {
            $report_date = date('Ymd', strtotime('-1 day'));
        } else {
            $report_date = date('Ymd');
        }
        
        // Find the maximum report number for today's business day
        $stmt_max = $conn->prepare("SELECT MAX(CAST(SUBSTRING(report_number, -5) AS UNSIGNED)) as max_num 
                                     FROM water_permeability_tests 
                                     WHERE report_number LIKE ? FOR UPDATE");
        $report_pattern = 'WPT-' . $report_date . '-%';
        $stmt_max->bind_param("s", $report_pattern);
        $stmt_max->execute();
        $max_result = $stmt_max->get_result()->fetch_assoc();
        $max_num = $max_result['max_num'] ?? 0;
        $daily_count = $max_num + 1;
        $stmt_max->close();
        $data['report_no'] = 'WPT-' . $report_date . '-' . str_pad($daily_count, 5, '0', STR_PAD_LEFT);
        
        // Store as JSON
        $test_results_json = json_encode($data);
        
        // Check if this report has a bundle_reference with pipe separator (range format: from|to)
        $bundle_ref = trim($original_bundle_reference ?? '');
        $reports_to_update = [$report_id];
        
        // If bundle_reference exists and contains a pipe (|), find all reports with the same bundle_reference
        if (!empty($bundle_ref) && strpos($bundle_ref, '|') !== false) {
            // Find all reports with the same bundle_reference that are rejected
            $findBulkStmt = $conn->prepare("SELECT id FROM water_permeability_tests WHERE bundle_reference = ? AND status IN ('rejected', 'rejected_by_checker') AND id != ?");
            $findBulkStmt->bind_param("si", $bundle_ref, $report_id);
            $findBulkStmt->execute();
            $bulkResult = $findBulkStmt->get_result();
            while ($bulkRow = $bulkResult->fetch_assoc()) {
                $reports_to_update[] = $bulkRow['id'];
            }
            $findBulkStmt->close();
            
            error_log("Water Permeability: Resubmitting bulk reference range: $bundle_ref. Found " . count($reports_to_update) . " reports to update.");
        }
        
        // Update all reports in the range to 'pending' status
        $placeholders = str_repeat('?,', count($reports_to_update) - 1) . '?';
        $updateStmt = $conn->prepare("UPDATE water_permeability_tests SET status = 'pending', remarks = NULL, checked_by = NULL, checked_at = NULL, approved_by = NULL, approved_at = NULL, updated_at = NOW() WHERE id IN ($placeholders)");
        $types = str_repeat('i', count($reports_to_update));
        $updateStmt->bind_param($types, ...$reports_to_update);
        $updateStmt->execute();
        $updateStmt->close();
        
        // Add shift column if it doesn't exist
        $conn->query("ALTER TABLE water_permeability_tests ADD COLUMN IF NOT EXISTS shift VARCHAR(10) AFTER test_date");
        // Add reference_number and bundle_reference columns if they don't exist
        $conn->query("ALTER TABLE water_permeability_tests ADD COLUMN IF NOT EXISTS reference_number VARCHAR(255) AFTER lab_test_number");
        $conn->query("ALTER TABLE water_permeability_tests ADD COLUMN IF NOT EXISTS bundle_reference VARCHAR(255) AFTER reference_number");
        
        // Update the existing report with new test data (instead of creating a new one)
        $update_stmt = $conn->prepare(
            "UPDATE water_permeability_tests 
            SET test_results = ?, 
                test_performed_by = ?,
                updated_at = NOW()
            WHERE id = ?"
        );
        
        $update_stmt->bind_param(
            "ssi",
            $test_results_json,
            $reporter_full_name,
            $report_id
        );
        
        if ($update_stmt->execute()) {
            $update_stmt->close();
            // Redirect to main form with success message
            $count = count($reports_to_update);
            $message = $count > 1 
                ? "Test resubmitted successfully! {$count} reports in the reference range have been resubmitted. Status: Pending"
                : "Test resubmitted successfully! Report Number: " . $report['report_number'] . " | Status: Pending";
            $_SESSION['success_message'] = $message;
            header("Location: ../tester_rejected_reports.php");
            exit();
        } else {
            $update_stmt->close();
            throw new Exception("Failed to update test: " . $update_stmt->error);
        }
        
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Edit & Resubmit Water Permeability Test</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:20px; color:#2c3e50; }
    .container { max-width:1400px; margin:auto; background:#fff; border-radius:12px; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.08);} 
    h1 { margin:0 0 20px 0; color:#2c3e50; border-bottom:2px solid#f39c12; padding-bottom:10px; }
    h3 { margin:20px 0 10px 0; color:#2c3e50; border-bottom:1px solid #ddd; padding-bottom:5px; }
    .alert { padding:12px; border-radius:6px; margin-bottom:15px; }
    .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
    .alert-warning { background:#fff3cd; color:#856404; border:1px solid #ffc107; }
    .form-row { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin-bottom:15px; }
    .form-group { display:flex; flex-direction:column; }
    .form-group label { font-weight:600; margin-bottom:5px; font-size:13px; color:#555; }
    .form-group input, .form-group textarea { padding:8px; border:1px solid #ccc; border-radius:4px; font-size:14px; }
    .form-group input:focus { outline:none; border-color:#3498db; }
    table { width:100%; border-collapse:collapse; margin-top:15px; font-size:11px; }
    th, td { border:1px solid #ddd; padding:6px; text-align:center; }
    th { background:#3498db; color:#fff; font-weight:600; font-size:10px; }
    td input { width:90%; padding:4px; border:1px solid #ddd; border-radius:3px; text-align:center; font-size:11px; }
    .btn { padding:10px 20px; border:none; border-radius:6px; cursor:pointer; text-decoration:none; display:inline-block; margin:10px 5px 0 0; font-size:14px; }
    .btn-back { background:#6c757d; color:#fff; }
    .btn-submit { background:#28a745; color:#fff; }
    .readonly { background:#e9ecef; cursor:not-allowed; }
  </style>
</head>
<body>
<div class="container">
  <h1><i class="fas fa-edit"></i> Edit & Resubmit Water Permeability Test</h1>
  
  <?php if ($message): ?>
    <div class="alert alert-success"><?php echo $message; ?></div>
  <?php endif; ?>
  
  <?php if ($error): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
  <?php endif; ?>
  
  <div class="alert alert-warning">
    <h3 style="margin-top:0; color:#856404;">⚠️ Original Test Was Rejected</h3>
    <p style="margin:5px 0;"><strong>Report Number:</strong> <?php echo htmlspecialchars($report['report_number']); ?></p>
    <p style="margin:5px 0;"><strong>Rejected By:</strong> <?php echo htmlspecialchars($rejected_by); ?></p>
    <p style="margin:5px 0;"><strong>Rejection Reason:</strong> <span style="color:#dc3545; font-weight:600;"><?php echo htmlspecialchars($rejection_reason); ?></span></p>
    <p style="margin:5px 0 0 0; font-style:italic;">Please make the necessary corrections and resubmit. A new test will be created.</p>
  </div>

  <form method="POST" action="">
    
    <!-- Date/Time and Shift Display -->
    <div id="dateTimeDisplay" style="background:#e3f2fd; padding:10px; border-radius:6px; margin-bottom:15px; text-align:center; font-weight:600; color:#1976d2;"></div>
    <div id="shiftBanner" style="background:#fff3cd; padding:8px; border-radius:6px; margin-bottom:15px; text-align:center; font-weight:600; color:#856404;"></div>
    
    <h3>Basic Information</h3>
    <div class="form-row">
      <div class="form-group">
        <label>Test Name:</label>
        <input type="text" name="test_name" value="<?php echo htmlspecialchars($test_data['test_name'] ?? 'Water Permeability Test'); ?>" required>
      </div>
      <div class="form-group">
        <label>Test Date:</label>
        <input type="date" name="test_date" id="test_date" value="<?php echo htmlspecialchars($report['test_date']); ?>" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Reference Number (Auto-fetched):</label>
        <input type="text" name="reference_number" id="reference_number" value="<?php echo htmlspecialchars($full_reference_number); ?>" readonly style="background:#f0f0f0; cursor:not-allowed;" required>
        <input type="hidden" name="original_reference_number" value="<?php echo htmlspecialchars($full_reference_number); ?>">
        <small style="color:#666; font-size:11px;">This reference was automatically fetched from the rejected report.</small>
      </div>
      <?php if (!empty($original_bundle_reference)): ?>
      <div class="form-group">
        <label>Bundle Reference (Auto-fetched):</label>
        <input type="text" name="bundle_reference" id="bundle_reference" value="<?php echo htmlspecialchars($original_bundle_reference); ?>" readonly style="background:#f0f0f0; cursor:not-allowed;">
        <input type="hidden" name="original_bundle_reference" value="<?php echo htmlspecialchars($original_bundle_reference); ?>">
        <small style="color:#666; font-size:11px;">Bundle reference from the rejected report.</small>
      </div>
      <?php endif; ?>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Specimen Area, A (mm²):</label>
        <input type="number" name="specimen_area" step="any" value="<?php echo htmlspecialchars($test_data['specimen_area'] ?? ''); ?>" required>
      </div>
      <div class="form-group">
        <label>Water Temperature (°C):</label>
        <input type="number" name="water_temperature" step="any" value="<?php echo htmlspecialchars($test_data['water_temperature'] ?? ''); ?>" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Correction Factor:</label>
        <input type="number" name="correction_factor" step="any" value="<?php echo htmlspecialchars($test_data['correction_factor'] ?? ''); ?>" required>
      </div>
      <div class="form-group">
        <label>Water Type:</label>
        <input type="text" name="water_type" value="<?php echo htmlspecialchars($test_data['water_type'] ?? ''); ?>" required>
      </div>
      <div class="form-group">
        <label>Number of Specimens:</label>
        <input type="number" name="number_of_specimens" value="<?php echo htmlspecialchars($test_data['number_of_specimens'] ?? 5); ?>" min="1" max="10" step="1" required>
      </div>
    </div>

    <h3>Test Parameters</h3>
    <div class="form-row">
      <div class="form-group">
        <label>Relative Humidity:</label>
        <input type="text" name="relative_humidity" value="<?php echo htmlspecialchars($test_data['relative_humidity'] ?? ''); ?>" placeholder="50~60%" required>
      </div>
      <div class="form-group">
        <label>Dissolved Oxygen (ppm):</label>
        <input type="text" name="dissolved_oxygen" value="<?php echo htmlspecialchars($test_data['dissolved_oxygen'] ?? ''); ?>" placeholder="<5" required>
      </div>
    </div>

    <h3>Calculation Inputs</h3>
    <div class="form-row">
      <div class="form-group">
        <label>Exposed area of the test specimen (a) m²:</label>
        <input type="number" step="any" name="area_specimen" id="area_specimen" value="<?php echo htmlspecialchars($test_data['area_specimen'] ?? ''); ?>" oninput="recalculateAll()" required>
      </div>
      <div class="form-group">
        <label>Exposed area of stand pipe (A) m²:</label>
        <input type="number" step="any" name="area_pipe" id="area_pipe" value="<?php echo htmlspecialchars($test_data['area_pipe'] ?? ''); ?>" oninput="recalculateAll()" required>
      </div>
      <div class="form-group">
        <label>Laboratory Temperature °C:</label>
        <input type="number" step="any" name="lab_temp" value="<?php echo htmlspecialchars($test_data['lab_temp'] ?? ''); ?>" required>
      </div>
      <div class="form-group">
        <label>Avg. Water Temperature °C:</label>
        <input type="number" step="any" name="avg_water_temp" value="<?php echo htmlspecialchars($test_data['avg_water_temp'] ?? ''); ?>" required>
      </div>
      <div class="form-group">
        <label>Avg. Correction Factor (RT):</label>
        <input type="number" step="any" name="avg_correction_factor" id="avg_correction_factor" value="<?php echo htmlspecialchars($test_data['avg_correction_factor'] ?? ''); ?>" oninput="recalculateAll()" required>
      </div>
    </div>

    <h3>Experimental Data Table</h3>
    <div style="overflow-x:auto;">
      <table>
        <thead>
          <tr>
            <th rowspan="2">No</th>
            <th colspan="4">Chosen Water Level Interval</th>
            <th rowspan="2">Thickness<br>(mm)</th>
            <th rowspan="2">Water Level<br>at v=0<br>h₀(m)</th>
            <th rowspan="2">Temp<br>(°C)</th>
            <th rowspan="2">Correction<br>Factor</th>
            <th rowspan="2">Δh (m)</th>
            <th rowspan="2">Time<br>(s)</th>
            <th rowspan="2">Velocity<br>(m/s⁻¹)</th>
            <th rowspan="2">Permeability<br>10⁻³(m/s)</th>
            <th rowspan="2">Actions</th>
          </tr>
          <tr>
            <th>h₀(m)</th>
            <th>t₁ (s)</th>
            <th>h₁(m)</th>
            <th>t₂ (s)</th>
          </tr>
        </thead>
        <tbody id="experimental_data_table">
          <?php 
          $existing_rows = $test_data['experimental_data'] ?? [];
          $row_count = count($existing_rows);
          if ($row_count === 0) {
              $row_count = 1; // At least 1 row if no data
          }
          for ($i = 0; $i < $row_count; $i++): 
            $row_data = $existing_rows[$i] ?? [];
            $row_num = $i + 1;
          ?>
          <tr data-row="<?php echo $row_num; ?>">
            <td><?php echo $row_num; ?></td>
            <td><input type="number" id="exp_h0_<?php echo $row_num; ?>" name="exp_h0_<?php echo $row_num; ?>" step="any" value="<?php echo $row_data['h0'] ?? ''; ?>" oninput="calculateRow(<?php echo $row_num; ?>)" required></td>
            <td><input type="number" id="exp_t1_<?php echo $row_num; ?>" name="exp_t1_<?php echo $row_num; ?>" step="any" value="<?php echo $row_data['t1'] ?? ''; ?>" oninput="calculateRow(<?php echo $row_num; ?>)" required></td>
            <td><input type="number" id="exp_h1_<?php echo $row_num; ?>" name="exp_h1_<?php echo $row_num; ?>" step="any" value="<?php echo $row_data['h1'] ?? ''; ?>" oninput="calculateRow(<?php echo $row_num; ?>)" required></td>
            <td><input type="number" id="exp_t2_<?php echo $row_num; ?>" name="exp_t2_<?php echo $row_num; ?>" step="any" value="<?php echo $row_data['t2'] ?? ''; ?>" oninput="calculateRow(<?php echo $row_num; ?>)" required></td>
            <td><input type="number" id="exp_thickness_<?php echo $row_num; ?>" name="exp_thickness_<?php echo $row_num; ?>" step="any" value="<?php echo $row_data['thickness'] ?? ''; ?>" oninput="calculateRow(<?php echo $row_num; ?>)" required></td>
            <td><input type="number" id="exp_water_level_<?php echo $row_num; ?>" name="exp_water_level_<?php echo $row_num; ?>" step="any" value="<?php echo $row_data['water_level'] ?? ''; ?>" required></td>
            <td><input type="number" id="exp_temp_<?php echo $row_num; ?>" name="exp_temp_<?php echo $row_num; ?>" step="any" value="<?php echo $row_data['temp'] ?? ''; ?>" oninput="calculateRow(<?php echo $row_num; ?>)" required></td>
            <td><input type="number" id="exp_correction_<?php echo $row_num; ?>" name="exp_correction_<?php echo $row_num; ?>" step="any" value="<?php echo $row_data['correction'] ?? ''; ?>" oninput="calculateRow(<?php echo $row_num; ?>)" required></td>
            <td><input type="number" id="exp_head_diff_<?php echo $row_num; ?>" name="exp_head_diff_<?php echo $row_num; ?>" step="any" value="<?php echo $row_data['head_diff'] ?? ''; ?>" readonly class="readonly"></td>
            <td><input type="number" id="exp_time_<?php echo $row_num; ?>" name="exp_time_<?php echo $row_num; ?>" step="any" value="<?php echo $row_data['time'] ?? ''; ?>" oninput="calculateRow(<?php echo $row_num; ?>)" required></td>
            <td><input type="number" id="exp_velocity_<?php echo $row_num; ?>" name="exp_velocity_<?php echo $row_num; ?>" step="any" value="<?php echo $row_data['velocity'] ?? ''; ?>" readonly class="readonly"></td>
            <td><input type="number" id="exp_permeability_<?php echo $row_num; ?>" name="exp_permeability_<?php echo $row_num; ?>" step="any" value="<?php echo $row_data['permeability'] ?? ''; ?>" readonly class="readonly"></td>
            <td>
              <?php if ($row_count > 1 || $row_num > 1): ?>
              <button type="button" onclick="removeWPTRow(this)" style="padding:4px 8px; background:#dc3545; color:#fff; border:none; cursor:pointer; border-radius:4px;">Delete</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endfor; ?>
        </tbody>
      </table>
      <button type="button" onclick="addWPTRow()" style="margin-top:10px; padding:8px 16px; background:#28a745; color:#fff; border:none; cursor:pointer; border-radius:4px;"><i class="fas fa-plus"></i> Add 1 More Row</button>
    </div>

    <h3>Summary Results</h3>
    <div class="form-row">
      <div class="form-group">
        <label>Average Permeability (×10⁻³ m/s):</label>
        <input type="number" name="avg_permeability" id="avg_permeability" step="any" value="<?php echo htmlspecialchars($test_data['avg_permeability'] ?? ''); ?>" readonly class="readonly">
      </div>
      <div class="form-group">
        <label>Average Velocity (m/s):</label>
        <input type="number" name="avg_velocity" id="avg_velocity" step="any" value="<?php echo htmlspecialchars($test_data['avg_velocity'] ?? ''); ?>" readonly class="readonly">
      </div>
    </div>

    <div class="form-group">
      <label>Remarks (Optional):</label>
      <textarea name="remarks" rows="3"><?php echo htmlspecialchars($test_data['remarks'] ?? ''); ?></textarea>
    </div>

    <div style="margin-top:20px;">
      <button type="submit" class="btn btn-submit"><i class="fas fa-paper-plane"></i> Resubmit Test</button>
      <a href="water_permeability_test.php" class="btn btn-back"><i class="fas fa-arrow-left"></i> Cancel</a>
    </div>
  </form>
</div>

<script>
// Calculation functions (same as main form)
function calculateRow(rowNum) {
  const h0 = parseFloat(document.getElementById('exp_h0_' + rowNum).value) || 0;
  const h1 = parseFloat(document.getElementById('exp_h1_' + rowNum).value) || 0;
  const t1 = parseFloat(document.getElementById('exp_t1_' + rowNum).value) || 0;
  const t2 = parseFloat(document.getElementById('exp_t2_' + rowNum).value) || 0;
  const thickness = parseFloat(document.getElementById('exp_thickness_' + rowNum).value) || 0;
  const correction = parseFloat(document.getElementById('exp_correction_' + rowNum).value) || 1;

  const areaSpecimen = parseFloat(document.getElementById('area_specimen').value) || 0;
  const areaPipe = parseFloat(document.getElementById('area_pipe').value) || 0;

  const headDiff = h0 - h1;
  document.getElementById('exp_head_diff_' + rowNum).value = headDiff.toFixed(3);

  // Time (s) - User inputs this manually, just read it
  const time = parseFloat(document.getElementById('exp_time_' + rowNum).value) || 0;

  if (h0 > 0 && h1 > 0 && time > 0 && areaSpecimen > 0 && thickness > 0) {
    const L = thickness / 1000;
    const logRatio = Math.log10(h0 / h1);
    const K = ((areaPipe * L) / (areaSpecimen * time)) * 2.303 * logRatio * correction;
    const K_display = K * 1000;
    document.getElementById('exp_permeability_' + rowNum).value = K_display.toFixed(3);

    const i = headDiff / L;
    const velocity = K * i;
    document.getElementById('exp_velocity_' + rowNum).value = velocity.toFixed(3);
  }

  calculateAverages();
}

function calculateAverages() {
  let totalK = 0, totalV = 0, nK = 0, nV = 0;

  // Get all rows dynamically
  const rows = document.querySelectorAll('#experimental_data_table tr');
  rows.forEach((row, index) => {
    const rowNum = index + 1;
    const K = parseFloat(document.getElementById('exp_permeability_' + rowNum)?.value) || 0;
    const V = parseFloat(document.getElementById('exp_velocity_' + rowNum)?.value) || 0;
    if (K > 0) { totalK += K; nK++; }
    if (V > 0) { totalV += V; nV++; }
  });

  if (nK > 0) document.getElementById('avg_permeability').value = (totalK / nK).toFixed(3);
  if (nV > 0) document.getElementById('avg_velocity').value = (totalV / nV).toFixed(3);
}

// Recalculate all rows when common inputs change
function recalculateAll() {
  const rows = document.querySelectorAll('#experimental_data_table tr');
  rows.forEach((row, index) => {
    const rowNum = index + 1;
    calculateRow(rowNum);
  });
}

// Initialize row count
let rowCount = <?php echo $row_count; ?>;

// Add a new row to experimental data table
function addWPTRow() {
  rowCount++;
  const tbody = document.getElementById('experimental_data_table');
  const newRow = document.createElement('tr');
  newRow.setAttribute('data-row', rowCount);
  
  newRow.innerHTML = `
    <td>${rowCount}</td>
    <td><input type="number" step="any" name="exp_h0_${rowCount}" id="exp_h0_${rowCount}" oninput="calculateRow(${rowCount})" required></td>
    <td><input type="number" step="any" name="exp_t1_${rowCount}" id="exp_t1_${rowCount}" oninput="calculateRow(${rowCount})" required></td>
    <td><input type="number" step="any" name="exp_h1_${rowCount}" id="exp_h1_${rowCount}" oninput="calculateRow(${rowCount})" required></td>
    <td><input type="number" step="any" name="exp_t2_${rowCount}" id="exp_t2_${rowCount}" oninput="calculateRow(${rowCount})" required></td>
    <td><input type="number" step="any" name="exp_thickness_${rowCount}" id="exp_thickness_${rowCount}" oninput="calculateRow(${rowCount})" required></td>
    <td><input type="number" step="any" name="exp_water_level_${rowCount}" id="exp_water_level_${rowCount}" required></td>
    <td><input type="number" step="any" name="exp_temp_${rowCount}" id="exp_temp_${rowCount}" oninput="calculateRow(${rowCount})" required></td>
    <td><input type="number" step="any" name="exp_correction_${rowCount}" id="exp_correction_${rowCount}" oninput="calculateRow(${rowCount})" required></td>
    <td><input type="number" step="any" name="exp_head_diff_${rowCount}" id="exp_head_diff_${rowCount}" readonly class="readonly"></td>
    <td><input type="number" step="any" name="exp_time_${rowCount}" id="exp_time_${rowCount}" oninput="calculateRow(${rowCount})"></td>
    <td><input type="number" step="any" name="exp_velocity_${rowCount}" id="exp_velocity_${rowCount}" readonly class="readonly"></td>
    <td><input type="number" step="any" name="exp_permeability_${rowCount}" id="exp_permeability_${rowCount}" readonly class="readonly"></td>
    <td><button type="button" onclick="removeWPTRow(this)" style="padding:4px 8px; background:#dc3545; color:#fff; border:none; cursor:pointer; border-radius:4px;">Delete</button></td>
  `;
  
  tbody.appendChild(newRow);
  updateRowNumbers();
}

// Remove a row from experimental data table
function removeWPTRow(button) {
  const row = button.closest('tr');
  const rows = document.querySelectorAll('#experimental_data_table tr');
  if (rows.length <= 1) {
    alert('At least one row is required!');
    return;
  }
  row.remove();
  updateRowNumbers();
  calculateAverages();
}

// Update row numbers after add/remove
function updateRowNumbers() {
  const rows = document.querySelectorAll('#experimental_data_table tr');
  rows.forEach((row, index) => {
    const rowNum = index + 1;
    row.querySelector('td:first-child').textContent = rowNum;
    row.setAttribute('data-row', rowNum);
  });
  rowCount = rows.length;
}

// Update date/time and shift display
function updateTimeBD() {
  const now = new Date();
  const utc = now.getTime() + now.getTimezoneOffset() * 60000;
  const dhaka = new Date(utc + 6 * 3600000);
  
  // Display date and time
  document.getElementById("dateTimeDisplay").innerHTML = 
    "📅 Date & Time: " + dhaka.toDateString() + " " + dhaka.toLocaleTimeString();
  
  // Calculate and display shift (8 AM to 7:59 PM = Day, 8 PM to 7:59 AM = Night)
  const h = dhaka.getHours();
  const shift = (h >= 8 && h < 20) ? "Day" : "Night";
  document.getElementById("shiftBanner").innerText = "🔄 Shift: " + shift;
}

// Update time every second
setInterval(updateTimeBD, 1000);
updateTimeBD();
</script>
</body>
</html>


