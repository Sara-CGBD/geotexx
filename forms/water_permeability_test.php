<?php
session_start();
require_once 'security_config.php';

// Prevent browser caching to ensure fresh dropdown data after submission
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

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

// Allow tester, checker, admin, AGM Ops, and management access
$__role = strtolower(trim($_SESSION['role'] ?? ''));
$__allowed = ['tester', 'checker', 'admin', 'agm ops', 'agm operations', 'management'];

if (!in_array($__role, $__allowed, true)) {
    die('Access denied. Only testers, checkers, admin, AGM Ops, and management can access this page.');
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();
$reporter_id = $_SESSION['user_id'];
$reporter_name = $_SESSION['username'];

// Fetch full name from database
$reporter_full_name = $reporter_name;
try {
    $stmt = $conn->prepare("SELECT full_name FROM new_user WHERE id = ?");
    $stmt->bind_param("i", $reporter_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $reporter_full_name = $row['full_name'] ?: $reporter_name;
    }
    $stmt->close();
} catch (Exception $e) {
    $reporter_full_name = $reporter_name;
}

$message = '';
$error = '';

// Check for success message from session (after redirect)
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Determine user role capabilities
$is_tester = ($__role === 'tester');
$is_checker = ($__role === 'checker');
$can_approve = in_array($__role, ['admin', 'agm ops', 'agm operations'], true);

// Function to get pending reports for checker
function getPendingReportsForChecker($conn, $limit = 20) {
    // Check if reference_number column exists
    $refColCheck = $conn->query("SHOW COLUMNS FROM water_permeability_tests LIKE 'reference_number'");
    $hasRefCol = ($refColCheck && $refColCheck->num_rows > 0);
    $refSelect = $hasRefCol ? ", COALESCE(reference_number, '') as reference_number" : ", '' as reference_number";
    
    $stmt = $conn->prepare("
        SELECT id, report_number, lab_test_number, test_date, 
               test_performed_by, status, created_at $refSelect
        FROM water_permeability_tests 
        WHERE status = 'pending'
        ORDER BY created_at DESC 
        LIMIT ?
    ");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $reports = [];
    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }
    $stmt->close();
    return $reports;
}

// Function to get checked reports pending admin approval
function getPendingReportsForApproval($conn, $limit = 20) {
    // Check if reference_number column exists
    $refColCheck = $conn->query("SHOW COLUMNS FROM water_permeability_tests LIKE 'reference_number'");
    $hasRefCol = ($refColCheck && $refColCheck->num_rows > 0);
    $refSelect = $hasRefCol ? ", COALESCE(reference_number, '') as reference_number" : ", '' as reference_number";
    
    $stmt = $conn->prepare("
        SELECT id, report_number, lab_test_number, test_date, 
               test_performed_by, checked_by, status, created_at $refSelect
        FROM water_permeability_tests 
        WHERE status = 'checked'
        ORDER BY created_at DESC 
        LIMIT ?
    ");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $reports = [];
    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }
    $stmt->close();
    return $reports;
}

// No inline approval handling - this is done in separate checker/admin pages

// Function to get rejected reports for a user (tester)
function getRejectedReportsForUser($conn, $user_id) {
    $stmt = $conn->prepare(
        "SELECT id, report_number, lab_test_number, gsm, roll_number, test_date, status, remarks, 
                checked_by, approved_by, created_at, test_performed_by
         FROM water_permeability_tests 
         WHERE reporter_id = ? AND status = 'rejected'
         ORDER BY created_at DESC 
         LIMIT 10"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $reports = [];
    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }
    $stmt->close();
    return $reports;
}

// Fetch pending reports based on role
$pending_reports = [];
if ($is_checker) {
    $pending_reports = getPendingReportsForChecker($conn, 20);
} else if ($can_approve) {
    $pending_reports = getPendingReportsForApproval($conn, 20);
}

// Fetch QC Test Orders for Water Permeability
$test_orders = [];
try {
    $query = "SELECT 
        qto.id,
        qto.sample_reference_id,
        qto.chosen_method,
        qto.test_data,
        qto.created_at,
        ts.test_name,
        ts.standard_code,
        ts.iso_code,
        ts.product,
        ts.description
    FROM qc_test_orders qto
    LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
    WHERE (ts.test_name LIKE '%Water Permeability%' 
        OR ts.test_name LIKE '%Characteristics%'
        OR ts.iso_code = 'ISO 11058' 
        OR ts.iso_code = 'EN ISO 12956'
        OR ts.standard_code LIKE '%11058%'
        OR ts.standard_code LIKE '%12956%')
    ORDER BY qto.created_at DESC
    LIMIT 50";
    
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $test_orders[] = $row;
    }
} catch (Exception $e) {
    $error = "Error fetching test orders: " . $e->getMessage();
}

// Handle admin approve/reject from dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wpt_action'], $_POST['wpt_report_id']) && $can_approve) {
    try {
        $action = $_POST['wpt_action'];
        $test_id = intval($_POST['wpt_report_id']);
        $admin_name = $reporter_full_name;
        
        if ($action === 'approve') {
            $stmt = $conn->prepare("UPDATE water_permeability_tests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'checked'");
            $stmt->bind_param("si", $admin_name, $test_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                // Check bundle completion if this is from a bundle
                $bundleCheck = $conn->prepare("SELECT bundle_reference FROM water_permeability_tests WHERE id = ? AND bundle_reference IS NOT NULL");
                $bundleCheck->bind_param("i", $test_id);
                $bundleCheck->execute();
                $bundleResult = $bundleCheck->get_result();
                if ($bundleRow = $bundleResult->fetch_assoc()) {
                    checkAndMarkBundleComplete($conn, 'water_permeability_tests', $bundleRow['bundle_reference']);
                }
                $bundleCheck->close();
                
                $_SESSION['success_message'] = "✅ Test approved successfully!";
                $stmt->close();
                // Prevent caching
                header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
                header("Cache-Control: post-check=0, pre-check=0", false);
                header("Pragma: no-cache");
                header("Location: water_permeability_test.php?t=" . time());
                exit();
            }
            $stmt->close();
        } elseif ($action === 'reject') {
            // Reject only 'checked' reports (from admin dashboard)
            // This sends it back to tester to resubmit
            $reject_reason = "Rejected by Admin: " . $admin_name;
            $stmt = $conn->prepare("UPDATE water_permeability_tests SET status = 'rejected', remarks = ?, approved_by = ? WHERE id = ? AND status = 'checked'");
            $stmt->bind_param("ssi", $reject_reason, $admin_name, $test_id);
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "❌ Test rejected and returned to tester!";
            }
            $stmt->close();
        }
        
        // Refresh the page to update the dashboard
        // Add timestamp to force fresh load and prevent caching
        header("Location: water_permeability_test.php?t=" . time());
        exit();
        
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_test'])) {
    try {
        $conn->begin_transaction();
        
        // Collect all form data
        $data = [
            'test_name' => trim($_POST['test_name']),
            'report_no' => trim($_POST['report_no']),
            'lab_test_no' => trim($_POST['lab_test_no']),
            'test_date' => trim($_POST['test_date']),
            // Get reference number - use individual roll reference if bundle is selected
            'reference_number' => (isset($_POST['individual_roll_reference']) && !empty($_POST['individual_roll_reference'])) 
                ? trim($_POST['individual_roll_reference']) 
                : trim($_POST['reference_number']),
            'gsm' => intval($_POST['gsm']),
            'roll_number' => trim($_POST['roll_number']),
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
        $test_results_json = json_encode($data);
        
        // Create table if not exists
        createWaterPermeabilityTable($conn);
        
        // Generate lab test number at submission time (synchronized across all users)
        $generated_lab_test_no = generateLabTestNumber($conn);
        $data['lab_test_no'] = $generated_lab_test_no;
        
        // Determine current shift
        $current_shift = getCurrentShift();
        
        // Generate Sample ID using: GSM.XL[YY][MONTH][DD]-LT[XX]-R[XX]
        // Example: 4.0L25OCT14-LT01-R27
        $test_date_obj = new DateTime($data['test_date']);
        $year = $test_date_obj->format('y'); // Last 2 digits
        $month = strtoupper($test_date_obj->format('M')); // 3-letter month
        $day = $test_date_obj->format('d'); // 2-digit day
        $gsm_formatted = number_format($data['gsm'] / 100, 1); // e.g., 400 -> 4.0
        $lab_test_formatted = 'LT' . $generated_lab_test_no;
        $roll_formatted = 'R' . $data['roll_number'];
        $data['sample_id'] = "{$gsm_formatted}L{$year}{$month}{$day}-{$lab_test_formatted}-{$roll_formatted}";
        
        // Generate Report Number in format: WPT-YYYYMMDD-XXXXX (resets at 8 AM daily)
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
        
        // Determine status and approval fields based on user role
        // Water Permeability test must go through checker first, then admin/AGM
        // All new submissions start as 'pending' for checker review
        // Checker approval (separate action) will change status from 'pending' to 'checked'
        // Admin approval (separate action) will change status from 'checked' to 'approved'
        $user_role = strtolower(trim($_POST['user_role'] ?? $__role));
        $test_performed_by = trim($_POST['test_performed_by']);
        $checked_by = null;
        $approved_by = null;
        $checked_at = null;
        $approved_at = null;
        $status = 'pending';
        
        // Even admin submissions go through checker workflow
        // All submissions start as 'pending' for checker review
        
        // Add sample_id, shift, and reference_number columns if they don't exist
        $columns_to_add = [
            ['name' => 'sample_id', 'definition' => 'VARCHAR(100) AFTER report_number'],
            ['name' => 'shift', 'definition' => 'VARCHAR(10) AFTER test_date'],
            ['name' => 'reference_number', 'definition' => 'VARCHAR(100)'],
            ['name' => 'bundle_reference', 'definition' => 'VARCHAR(100) NULL AFTER reference_number']
        ];
        
        foreach ($columns_to_add as $col) {
            $check = $conn->query("SHOW COLUMNS FROM water_permeability_tests LIKE '{$col['name']}'");
            if ($check->num_rows == 0) {
                $conn->query("ALTER TABLE water_permeability_tests ADD COLUMN {$col['name']} {$col['definition']}");
            }
        }
        
        // Get bundle reference if individual roll is selected
        $bundle_reference = null;
        if (isset($_POST['individual_roll_reference']) && !empty($_POST['individual_roll_reference']) 
            && isset($_POST['reference_number']) && !empty($_POST['reference_number'])) {
            $bundle_reference = trim($_POST['reference_number']); // Original bundle reference
        }
        
        $stmt = $conn->prepare("INSERT INTO water_permeability_tests 
            (report_number, sample_id, lab_test_number, reference_number, bundle_reference, gsm, roll_number, test_date, shift, test_results, 
             test_performed_by, checked_by, approved_by, checked_at, approved_at, 
             reporter_id, reporter_name, status, remarks) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->bind_param('ssisssisssssssssiss', 
            $data['report_no'],
            $data['sample_id'], 
            $data['lab_test_no'],
            $data['reference_number'],
            $bundle_reference, 
            $data['gsm'], 
            $data['roll_number'], 
            $data['test_date'],
            $current_shift,
            $test_results_json, 
            $test_performed_by,
            $checked_by,
            $approved_by,
            $checked_at,
            $approved_at,
            $reporter_id, 
            $reporter_full_name,
            $status,
            $data['remarks']
        );
        
        if (!$stmt->execute()) {
            $error_msg = $stmt->error;
            error_log("Insert failed: " . $error_msg);
            throw new Exception("Failed to submit test: " . $error_msg);
        }
        
        // Check if row was actually inserted
        $inserted_id = $conn->insert_id;
        if (!$inserted_id) {
            error_log("No insert ID returned. Affected rows: " . $conn->affected_rows);
            throw new Exception("Failed to insert record - no ID returned");
        }
        
        // Check bundle completion if this is from a bundle and was approved
        if ($bundle_reference && $status === 'approved') {
            checkAndMarkBundleComplete($conn, 'water_permeability_tests', $bundle_reference);
        }
        
        $conn->commit();
        if ($status === 'pending') {
            $message = "✅ Water Permeability Test submitted for checking! Report: " . htmlspecialchars($data['report_no']);
        } elseif ($status === 'checked') {
            $message = "✅ Water Permeability Test forwarded to admin for approval! Report: " . htmlspecialchars($data['report_no']);
        } else {
            $message = "✅ Water Permeability Test approved and submitted successfully! Report: " . htmlspecialchars($data['report_no']);
        }
        $stmt->close();
        
        // Store success message in session and redirect to refresh dropdown
        $_SESSION['success_message'] = $message;
        $success_msg = urlencode($message);
        header("Location: water_permeability_test.php?success=1&msg={$success_msg}&t=" . time());
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "❌ Error: " . $e->getMessage();
        error_log("Water Permeability Test Error: " . $e->getMessage());
    }
}

// Function to check if all rolls from a bundle are tested and approved, then mark bundle as complete
function checkAndMarkBundleComplete($conn, $table_name, $bundle_reference) {
    try {
        // Parse bundle reference to get base reference and roll count (e.g., "REF-4" -> base="REF", count=4)
        if (!preg_match('/-(\d+)$/', $bundle_reference, $matches)) {
            return false; // Not a valid bundle reference
        }
        
        $rollCount = (int)$matches[1];
        $baseRef = preg_replace('/-\d+$/', '', $bundle_reference);
        
        // Generate all individual roll references (REF-1, REF-2, etc.)
        $expectedRolls = [];
        for ($i = 1; $i <= $rollCount; $i++) {
            $expectedRolls[] = $baseRef . '-' . $i;
        }
        
        // Check if all individual rolls are tested and approved
        $placeholders = str_repeat('?,', count($expectedRolls) - 1) . '?';
        $query = "SELECT reference_number, status 
                  FROM {$table_name} 
                  WHERE bundle_reference = ? 
                  AND reference_number IN ({$placeholders})
                  AND status = 'approved'";
        
        $stmt = $conn->prepare($query);
        $params = array_merge([$bundle_reference], $expectedRolls);
        $types = str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $approvedRolls = [];
        while ($row = $result->fetch_assoc()) {
            $approvedRolls[] = $row['reference_number'];
        }
        $stmt->close();
        
        // If all rolls are approved, mark bundle as complete
        if (count($approvedRolls) >= $rollCount) {
            // Create bundle_test_completion table if it doesn't exist
            $createTable = "CREATE TABLE IF NOT EXISTS bundle_test_completion (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bundle_reference VARCHAR(100) NOT NULL,
                test_type VARCHAR(50) NOT NULL,
                status ENUM('pending', 'completed', 'passed') DEFAULT 'pending',
                completed_at DATETIME NULL,
                approved_by VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY (bundle_reference, test_type),
                INDEX idx_bundle_status (bundle_reference, status),
                INDEX idx_test_type (test_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            $conn->query($createTable);
            
            // Determine test type from table name
            $testType = '';
            switch ($table_name) {
                case 'water_permeability_tests':
                    $testType = 'water_permeability';
                    break;
                case 'characteristics_tests':
                    $testType = 'characteristics';
                    break;
                case 'sun_test_reports':
                    $testType = 'sun_test';
                    break;
                case 'weathering_exposure_reports':
                    $testType = 'weathering_exposure';
                    break;
                default:
                    return false;
            }
            
            // Get the approver name from the last approved test
            $approverQuery = "SELECT approved_by FROM {$table_name} 
                             WHERE bundle_reference = ? AND status = 'approved' 
                             ORDER BY approved_at DESC LIMIT 1";
            $approverStmt = $conn->prepare($approverQuery);
            $approverStmt->bind_param("s", $bundle_reference);
            $approverStmt->execute();
            $approverResult = $approverStmt->get_result();
            $approverRow = $approverResult->fetch_assoc();
            $approverName = $approverRow['approved_by'] ?? null;
            $approverStmt->close();
            
            // Insert or update bundle completion record
            $insertQuery = "INSERT INTO bundle_test_completion 
                           (bundle_reference, test_type, status, completed_at, approved_by) 
                           VALUES (?, ?, 'passed', NOW(), ?)
                           ON DUPLICATE KEY UPDATE 
                           status = 'passed', 
                           completed_at = NOW(), 
                           approved_by = ?";
            $insertStmt = $conn->prepare($insertQuery);
            $insertStmt->bind_param("ssss", $bundle_reference, $testType, $approverName, $approverName);
            $insertStmt->execute();
            $insertStmt->close();
            
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error checking bundle completion: " . $e->getMessage());
        return false;
    }
}

// Function to create table
function createWaterPermeabilityTable($conn) {
    $create_table = "CREATE TABLE IF NOT EXISTS water_permeability_tests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_number VARCHAR(100) UNIQUE NOT NULL,
        sample_id VARCHAR(100),
        lab_test_number VARCHAR(50) NOT NULL,
        reference_number VARCHAR(100),
        gsm INT NOT NULL,
        roll_number VARCHAR(100) NOT NULL,
        test_date DATE NOT NULL,
        shift VARCHAR(10),
        test_results JSON NOT NULL,
        test_performed_by VARCHAR(255) NOT NULL,
        checked_by VARCHAR(255) NULL,
        approved_by VARCHAR(255) NULL,
        checked_at DATETIME NULL,
        approved_at DATETIME NULL,
        reporter_id INT NOT NULL,
        reporter_name VARCHAR(255) NOT NULL,
        status ENUM('pending', 'checked', 'approved', 'rejected') DEFAULT 'pending',
        remarks TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_report_number (report_number),
        INDEX idx_sample_id (sample_id),
        INDEX idx_reference_number (reference_number),
        INDEX idx_shift (shift),
        INDEX idx_status (status),
        INDEX idx_created_at (created_at),
        INDEX idx_reporter_id (reporter_id)
    )";
    
    if (!$conn->query($create_table)) {
        throw new Exception("Error creating table: " . $conn->error);
    }
}

// Generate Lab Test Number (auto-incremented for the day)
function generateLabTestNumber($conn) {
    createWaterPermeabilityTable($conn);
    
    $now = new DateTime();
    $today = $now->format('Y-m-d');
    
    // Find the maximum lab test number for today
    $stmt = $conn->prepare("SELECT MAX(CAST(lab_test_number AS UNSIGNED)) AS max_num 
                            FROM water_permeability_tests 
                            WHERE DATE(created_at) = ?");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $max_num = (int)($row['max_num'] ?? 0);
    $stmt->close();
    
    $nextSeq = $max_num + 1;
    return str_pad($nextSeq, 2, '0', STR_PAD_LEFT);
}

// Generate preview of next lab test number (will be regenerated at submission for accuracy)
$generated_lab_test_no = generateLabTestNumber($conn);

// Function to determine current shift
function getCurrentShift() {
    $hour = intval(date('H'));
    // Day shift: 8:00 AM (08:00) to 7:59 PM (19:59)
    // Night shift: 8:00 PM (20:00) to 7:59 AM (07:59)
    if ($hour >= 8 && $hour < 20) {
        return 'Day';
    } else {
        return 'Night';
    }
}

// Generate preview of next report number (resets daily at 8 AM)
function generateNextReportNumber($conn) {
    createWaterPermeabilityTable($conn);
    
    $hour = intval(date('H'));
    if ($hour < 8) {
        $report_date = date('Ymd', strtotime('-1 day'));
    } else {
        $report_date = date('Ymd');
    }
    
    // Find the maximum report number for today's business day
    $stmt_max = $conn->prepare("SELECT MAX(CAST(SUBSTRING(report_number, -5) AS UNSIGNED)) as max_num 
                                 FROM water_permeability_tests 
                                 WHERE report_number LIKE ?");
    $report_pattern = 'WPT-' . $report_date . '-%';
    $stmt_max->bind_param("s", $report_pattern);
    $stmt_max->execute();
    $max_result = $stmt_max->get_result()->fetch_assoc();
    $max_num = $max_result['max_num'] ?? 0;
    $daily_count = $max_num + 1;
    $stmt_max->close();
    
    return 'WPT-' . $report_date . '-' . str_pad($daily_count, 5, '0', STR_PAD_LEFT);
}

$generated_report_no = generateNextReportNumber($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Water Permeability & Characteristics Test</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
  .container { max-width:1400px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);} 
  h1 { text-align:center; font-size:28px; margin-bottom:30px; color:#2c3e50; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; font-size:12px; }
  input[type="text"], input[type="number"], select, textarea { 
    padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px); 
  }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .readonly { background:#ecf0f1; }
  .test-table { width:100%; border-collapse:collapse; margin-top:15px; font-size:12px; }
  .test-table th, .test-table td { border:1px solid #ddd; padding:6px; text-align:center; }
  .test-table th { background:#3498db; color:#fff; font-weight:600; }
  .test-table input { width:70px; border:none; background:transparent; text-align:center; padding:4px; }
  .form-row { display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:12px; margin-bottom:20px; }
  .alert { padding:12px; border-radius:6px; margin-bottom:15px; }
  .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
  .alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
  .section-title { font-size:16px; font-weight:700; margin:25px 0 15px 0; padding:10px; background:#e9ecef; border-left:4px solid #3498db; }
</style>
</head>
<body>
<div class="container">
  <h1> Water Permeability Test (ISO 11058)</h1>

  <?php if ($message): ?>
    <div class="alert alert-success">✅ <?php echo $message; ?></div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-error">❌ <?php echo $error; ?></div>
  <?php endif; ?>

  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
  </div>

  <?php if ($is_checker && !empty($pending_reports)): ?>
  <!-- Checker Dashboard: Pending for Checking -->
  <div style="margin-top:16px; padding:12px; border:1px solid #ddd; border-radius:8px; background:#fff;">
    <h3 style="margin:0 0 12px 0;">📋 Pending Reports</h3>
    <table class="test-table">
      <thead>
        <tr>
          <th>Report No</th>
          <th>Lab Test No</th>
          <th>Reference Number</th>
          <th>Test Date</th>
          <th>Tested By</th>
          <th>Submitted</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pending_reports as $pr): ?>
        <tr>
          <td><?php echo htmlspecialchars($pr['report_number']); ?></td>
          <td><?php echo htmlspecialchars($pr['lab_test_number']); ?></td>
          <td><?php echo htmlspecialchars($pr['reference_number'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($pr['test_date']); ?></td>
          <td><?php echo htmlspecialchars($pr['test_performed_by']); ?></td>
          <td><?php echo date('M d, Y H:i', strtotime($pr['created_at'])); ?></td>
          <td>
            <a href="../admin/check_water_permeability.php?id=<?php echo $pr['id']; ?>" 
               target="_blank"
               style="background:#2ecc71; color:#fff; padding:4px 8px; border-radius:4px; text-decoration:none; font-size:12px;">
              Check
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php elseif ($is_checker && empty($pending_reports)): ?>
  <div style="background:#e3f2fd; border:1px solid #2196f3; padding:20px; border-radius:8px; text-align:center; margin-top:20px;">
    <h3 style="color:#1976d2; margin-top:0;">✅ No Tests Pending for Checking</h3>
    <p style="color:#555;">All tests have been checked. New tests will appear here when testers submit them.</p>
  </div>
  <?php elseif ($can_approve && !empty($pending_reports)): ?>
  <!-- Admin Dashboard: Pending for Approval -->
  <div style="margin-top:16px; padding:12px; border:1px solid #ddd; border-radius:8px; background:#fff;">
    <h3 style="margin:0 0 12px 0;">✅ Pending Reports:</h3>
    <table class="test-table">
      <thead>
        <tr>
          <th>Report No</th>
          <th>Lab Test No</th>
          <th>Reference Number</th>
          <th>Test Date</th>
          <th>Tested By</th>
          <th>Checked By</th>
          <th>Submitted</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pending_reports as $pr): ?>
        <tr>
          <td><?php echo htmlspecialchars($pr['report_number']); ?></td>
          <td><?php echo htmlspecialchars($pr['lab_test_number']); ?></td>
          <td><?php echo htmlspecialchars($pr['reference_number'] ?? ''); ?></td>
          <td><?php echo htmlspecialchars($pr['test_date']); ?></td>
          <td><?php echo htmlspecialchars($pr['test_performed_by']); ?></td>
          <td><?php echo htmlspecialchars($pr['checked_by'] ?? 'N/A'); ?></td>
          <td><?php echo date('M d, Y H:i', strtotime($pr['created_at'])); ?></td>
          <td>
            <a href="../admin/view_water_permeability.php?id=<?php echo $pr['id']; ?>" 
               target="_blank"
               style="background:#3498db; color:#fff; padding:4px 8px; border-radius:4px; text-decoration:none; font-size:12px; margin-right:4px;">
              View
            </a>
            <form method="POST" action="" style="display:inline; margin-right:4px;">
              <input type="hidden" name="wpt_report_id" value="<?php echo $pr['id']; ?>">
              <input type="hidden" name="wpt_action" value="approve">
              <button type="submit" style="background:#28a745; color:#fff; padding:4px 8px; border:none; border-radius:4px; cursor:pointer; font-size:12px;">Approve</button>
            </form>
            <form method="POST" action="" style="display:inline;">
              <input type="hidden" name="wpt_report_id" value="<?php echo $pr['id']; ?>">
              <input type="hidden" name="wpt_action" value="reject">
              <button type="submit" style="background:#dc3545; color:#fff; padding:4px 8px; border:none; border-radius:4px; cursor:pointer; font-size:12px;">Reject</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php elseif ($can_approve && empty($pending_reports)): ?>
  <div style="background:#e8f5e9; border:1px solid #4caf50; padding:20px; border-radius:8px; text-align:center; margin-top:20px;">
    <h3 style="color:#2e7d32; margin-top:0;">✅ No Tests Pending Approval</h3>
    <p style="color:#555;">All checked tests have been approved.</p>
  </div>
  <?php endif; ?>

  <?php if (!$is_checker): ?>
  <!-- Entry Form (for testers and admins) -->
  
  <?php 
  // Display success message from redirect (e.g., after resubmission)
  if (isset($_SESSION['success_message'])): ?>
    <div style="background:#d4edda; color:#155724; border:1px solid #c3e6cb; padding:15px; border-radius:6px; margin:15px 0; text-align:center; font-weight:600;">
      <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
    </div>
  <?php endif; ?>
  
  <form method="POST" action="">

    <!-- Date/Time and Shift Display -->
    <div id="dateTimeDisplay" class="summary-info"></div>
    <div id="shiftBanner" class="summary-info"></div>

    <?php if (!$can_approve): ?>
    <!-- Rejected Reports for Tester to Review -->
    <?php $rejected = getRejectedReportsForUser($conn, $reporter_id); if (!empty($rejected)): ?>
    <div style="margin-top:16px; padding:12px; border:1px solid #f8d7da; border-radius:8px; background:#fff3cd;">
      <h3 style="margin:0 0 12px 0; color:#721c24;">❌ Rejected Water Permeability Tests - Action Required</h3>
      <p style="margin:0 0 12px 0; color:#856404;">The following tests were rejected. Please review the comments and make corrections.</p>
      <table class="test-table">
        <thead>
          <tr>
            <th>Report No</th>
            <th>Lab Test No</th>
            <th>Test Date</th>
            <th>Rejected By</th>
            <th>Rejection Comments</th>
            <th>Submitted At</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rejected as $rj): ?>
          <tr>
            <td><?php echo htmlspecialchars($rj['report_number']); ?></td>
            <td><?php echo htmlspecialchars($rj['lab_test_number']); ?></td>
            <td><?php echo htmlspecialchars($rj['test_date']); ?></td>
            <td><?php echo htmlspecialchars($rj['checked_by'] ?? $rj['approved_by'] ?? 'N/A'); ?></td>
            <td style="text-align:left; max-width:300px; color:#721c24; font-weight:600;">
              <?php 
              $remarks = $rj['remarks'] ?? 'No comments';
              // Remove rejection prefixes for cleaner display
              $remarks = preg_replace('/\[Checker Rejection\]:\s*/i', '', $remarks);
              $remarks = preg_replace('/\[Admin Rejection\]:\s*/i', '', $remarks);
              echo nl2br(htmlspecialchars(trim($remarks))); 
              ?>
            </td>
            <td><?php echo htmlspecialchars($rj['created_at']); ?></td>
            <td>
              <a href="edit_water_permeability.php?id=<?php echo $rj['id']; ?>" target="_blank" class="submit-btn" style="padding:6px 10px; text-decoration:none; display:inline-block; background:#f39c12; color:#fff;">Edit & Resubmit</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p style="margin:12px 0 0 0; color:#856404; font-style:italic;">💡 Note: Please review the rejection comments and submit a new corrected test below.</p>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Hidden fields -->
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">

    <!-- Basic Information -->
    <div class="form-row">
      <div class="form-group">
        <label>Test Name:</label>
        <input type="text" name="test_name" value="Water Permeability Characteristics Normal to the Plane, Without Load" readonly class="readonly">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Report Number:</label>
        <input type="text" name="report_no" id="report_no" value="<?php echo htmlspecialchars($generated_report_no); ?>" readonly class="readonly">
      </div>
      <div class="form-group">
        <label>Sample ID:</label>
        <input type="text" name="sample_id" id="sample_id" readonly class="readonly">
      </div>
      <div class="form-group">
        <label>Lab Test No.:</label>
        <input type="text" name="lab_test_no" id="lab_test_no" value="<?php echo htmlspecialchars($generated_lab_test_no); ?>" readonly class="readonly">
      </div>
      <div class="form-group">
        <label>Test Date:</label>
        <input type="date" name="test_date" id="test_date" value="<?php echo date('Y-m-d'); ?>" required onchange="generateSampleId()">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Reference Number:</label>
        <select id="reference_number" name="reference_number" onchange="handleWPTReferenceSelection(this.value)" required>
          <option value="">Select Reference Number</option>
          <?php
          // Fetch reference numbers from roll_received (roll received entry) - support bundles
          $bundleReferences = [];
          $singleReferences = [];
          
          $refQuery = "SELECT DISTINCT r.reference_number, MAX(r.created_at) as created_at
                       FROM roll_received r
                       LEFT JOIN water_permeability_tests wpt ON r.reference_number = wpt.reference_number
                         AND wpt.status IN ('pending', 'checked', 'approved')
                       WHERE r.reference_number IS NOT NULL 
                         AND r.reference_number != ''
                         AND r.is_deleted = 0
                         AND wpt.reference_number IS NULL
                       GROUP BY r.reference_number
                       ORDER BY created_at DESC 
                       LIMIT 100";
          $refResult = $conn->query($refQuery);
          if ($refResult && $refResult->num_rows > 0) {
              while ($refRow = $refResult->fetch_assoc()) {
                  $ref = $refRow['reference_number'];
                  
                  // Check if this is a bundle reference (ends with -N pattern)
                  if (preg_match('/-(\d+)$/', $ref, $matches)) {
                      $rollCount = (int)$matches[1];
                      $baseRef = preg_replace('/-\d+$/', '', $ref);
                      
                      $bundleReferences[] = [
                          'reference' => $ref,
                          'base_reference' => $baseRef,
                          'roll_count' => $rollCount
                      ];
                  } else {
                      $singleReferences[] = $ref;
                  }
              }
          }
          
          // Show bundle references
          foreach($bundleReferences as $bundle): ?>
            <option value="<?php echo htmlspecialchars($bundle['reference']); ?>" data-is-bundle="true" data-base-ref="<?php echo htmlspecialchars($bundle['base_reference']); ?>" data-roll-count="<?php echo $bundle['roll_count']; ?>">
              <?php echo htmlspecialchars($bundle['reference']); ?> (Bundle - <?php echo $bundle['roll_count']; ?> rolls)
            </option>
          <?php endforeach; ?>
          
          <!-- Show single roll references -->
          <?php foreach($singleReferences as $ref): ?>
            <option value="<?php echo htmlspecialchars($ref); ?>" data-is-bundle="false">
              <?php echo htmlspecialchars($ref); ?>
            </option>
          <?php endforeach; ?>
          ?>
        </select>
        
        <!-- Individual Roll Selector (shown when bundle is selected) -->
        <select name="individual_roll_reference" id="wpt_individual_roll_reference" onchange="handleWPTIndividualRollSelection(this.value)" style="display:none; margin-top:10px; padding:10px; border:2px solid #3498db; border-radius:6px; background:#f8f9fa;">
          <option value="">-- Select Individual Roll for Testing --</option>
        </select>
        <div id="wpt_bundle_info" style="display:none; margin-top:8px; padding:10px; background:#e3f2fd; border-left:4px solid #2196F3; border-radius:4px; font-size:13px; color:#1565C0;">
          <i class="fas fa-info-circle"></i> <strong>Bundle Detected:</strong> This reference contains multiple rolls. <strong>Please select the specific roll number</strong> you want to test individually.
        </div>
      </div>
      <!-- GSM and Roll Number removed from visible fields (not required in this test) -->
      <input type="hidden" name="gsm" id="gsm" value="">
      <input type="hidden" name="roll_number" id="roll_number" value="">
      <div class="form-group">
        <label>Specimen Area, A (mm²):</label>
        <input type="number" name="specimen_area" step="any" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Water Temperature (°C):</label>
        <input type="number" name="water_temperature" step="any" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Correction Factor:</label>
        <input type="number" name="correction_factor" step="any" required>
      </div>
      <div class="form-group">
        <label>Water Type:</label>
        <input type="text" name="water_type" id="water_type" pattern="[A-Za-z\s]+" title="Only letters and spaces are allowed" oninput="this.value = this.value.replace(/[^A-Za-z\s]/g, '')" required>
      </div>
      <div class="form-group">
        <label>Number of Specimens:</label>
        <input type="number" name="number_of_specimens" value="1" min="1" max="20" step="1" required>
      </div>
    </div>

    <!-- Test Parameters -->
    <div class="section-title">Test Parameters</div>
    <div class="form-row">
      <div class="form-group">
        <label>Relative Humidity:</label>
        <input type="text" name="relative_humidity" placeholder="50~60%" required>
      </div>
      <div class="form-group">
        <label>Dissolved Oxygen (ppm):</label>
        <input type="text" name="dissolved_oxygen" placeholder="<5" required>
      </div>
    </div>

    <!-- Calculation Inputs -->
    <div class="section-title">Calculation Inputs</div>
    <div class="form-row">
      <div class="form-group">
        <label>Exposed area of the test specimen (a) m²:</label>
        <input type="number" step="any" name="area_specimen" id="area_specimen" oninput="recalculateAll()" required>
      </div>
      <div class="form-group">
        <label>Exposed area of stand pipe (A) m²:</label>
        <input type="number" step="any" name="area_pipe" id="area_pipe" oninput="recalculateAll()" required>
      </div>
      <div class="form-group">
        <label>Laboratory Temperature °C:</label>
        <input type="number" step="any" name="lab_temp" id="lab_temp" required>
      </div>
      <div class="form-group">
        <label>Avg. Water Temperature °C:</label>
        <input type="number" step="any" name="avg_water_temp" id="avg_water_temp" required>
      </div>
      <div class="form-group">
        <label>Avg. Correction Factor (RT):</label>
        <input type="number" step="any" name="avg_correction_factor" id="avg_correction_factor" oninput="recalculateAll()" required>
      </div>
    </div>

    <!-- Experimental Data Table -->
    <div class="section-title">Experimental Data (Falling Head Method)</div>
    <div style="overflow-x:auto;">
      <table class="test-table">
        <thead>
          <tr>
            <th rowspan="3">No</th>
            <th colspan="4">Chosen Water Level Interval</th>
            <th rowspan="3">Thickness<br>(mm)</th>
            <th rowspan="3">Water Level<br>at v=0<br>h₀(m)</th>
            <th rowspan="3">Temp.<br>(°C)</th>
            <th rowspan="3">Correction<br>Factor</th>
            <th rowspan="3">Δh<br>(m)</th>
            <th rowspan="3">Time<br>(s)</th>
            <th rowspan="3">Velocity<br>(m/s⁻¹)</th>
            <th rowspan="3">Permeability<br>10⁻³(m/s)</th>
            <th rowspan="3">Actions</th>
          </tr>
          <tr>
            <th colspan="2">Upper Limit</th>
            <th colspan="2">Lower Limit</th>
          </tr>
          <tr>
            <th>h₀ (m)</th>
            <th>t₁ (s)</th>
            <th>h₁ (m)</th>
            <th>t₂ (s)</th>
          </tr>
        </thead>
        <tbody id="experimental_data_table">
          <tr data-row="1">
            <td>1</td>
            <td><input type="number" step="any" name="exp_h0_1" id="exp_h0_1" oninput="calculateRow(1)" required></td>
            <td><input type="number" step="any" name="exp_t1_1" id="exp_t1_1" oninput="calculateRow(1)" required></td>
            <td><input type="number" step="any" name="exp_h1_1" id="exp_h1_1" oninput="calculateRow(1)" required></td>
            <td><input type="number" step="any" name="exp_t2_1" id="exp_t2_1" oninput="calculateRow(1)" required></td>
            <td><input type="number" step="any" name="exp_thickness_1" id="exp_thickness_1" oninput="calculateRow(1)" required></td>
            <td><input type="number" step="any" name="exp_water_level_1" id="exp_water_level_1" required></td>
            <td><input type="number" step="any" name="exp_temp_1" id="exp_temp_1" oninput="calculateRow(1)" required></td>
            <td><input type="number" step="any" name="exp_correction_1" id="exp_correction_1" oninput="calculateRow(1)" required></td>
            <td><input type="number" step="any" name="exp_head_diff_1" id="exp_head_diff_1" readonly class="readonly"></td>
            <td><input type="number" step="any" name="exp_time_1" id="exp_time_1" oninput="calculateRow(1)"></td>
            <td><input type="number" step="any" name="exp_velocity_1" id="exp_velocity_1" readonly class="readonly"></td>
            <td><input type="number" step="any" name="exp_permeability_1" id="exp_permeability_1" readonly class="readonly"></td>
            <td><button type="button" onclick="removeWPTRow(this)" style="padding:4px 8px; background:#dc3545; color:#fff; border:none; cursor:pointer;">Delete</button></td>
          </tr>
        </tbody>
      </table>
      <button type="button" onclick="addWPTRow()" style="margin-top:10px; padding:8px 16px; background:#28a745; color:#fff; border:none; cursor:pointer;">Add 1 More Row</button>
    </div>

    <!-- Summary Results -->
    <div class="section-title">Summary Results</div>
    <div class="form-row">
      <div class="form-group">
        <label>Average Permeability, k (m/s×10⁻³):</label>
        <input type="number" step="0.0001" name="avg_permeability" id="avg_permeability" readonly class="readonly">
      </div>
      <div class="form-group">
        <label>Average Flow Velocity, V₂₀ (m/s×10⁻³):</label>
        <input type="number" step="0.0001" name="avg_velocity" id="avg_velocity" readonly class="readonly">
      </div>
    </div>

    <div class="form-group">
      <label>Remarks:</label>
      <textarea name="remarks" rows="3" placeholder="Any observations or notes..."></textarea>
    </div>

    <!-- Approval Section -->
    <?php if ($__role === 'checker'): ?>
    <div class="section-title">Quality Check</div>
    <div class="form-row">
      <div class="form-group">
        <label>Checked By:</label>
        <input type="text" name="checked_by" value="<?php echo htmlspecialchars($reporter_full_name); ?>" readonly class="readonly">
      </div>
    </div>
    <?php elseif ($__role === 'admin' || $__role === 'agm ops' || $__role === 'agm operations'): ?>
    <div class="section-title">Approval</div>
    <div class="form-row">
      <div class="form-group">
        <label>Approved By:</label>
        <input type="text" name="approved_by" value="<?php echo htmlspecialchars($reporter_full_name); ?>" readonly class="readonly">
      </div>
    </div>
    <?php else: // testers and other roles ?>
    <div class="section-title">Test Information</div>
    <div class="form-row">
      <div class="form-group">
        <label>Test Performed By:</label>
        <input type="text" name="test_performed_by" value="<?php echo htmlspecialchars($reporter_full_name); ?>" readonly class="readonly">
      </div>
    </div>
    <?php endif; ?>

    <!-- Hidden fields for workflow -->
    <input type="hidden" name="test_performed_by" value="<?php echo htmlspecialchars($reporter_full_name); ?>">
    <input type="hidden" name="reporter_id" value="<?php echo $reporter_id; ?>">
    <input type="hidden" name="reporter_name" value="<?php echo htmlspecialchars($reporter_name); ?>">
    <input type="hidden" name="user_role" value="<?php echo htmlspecialchars($__role); ?>">

    <!-- Submit Buttons -->
    <div class="actions">
      <button type="submit" name="submit_test" class="submit-btn">Submit</button>
      <button type="reset" class="clear-btn">Clear</button>
    </div>
  </form>
  <?php endif; ?>
</div>

<script>
// Update date/time and shift
function updateTimeBD() {
  const now = new Date();
  const utc = now.getTime() + now.getTimezoneOffset()*60000;
  const dhaka = new Date(utc + 6*3600000);
  
  document.getElementById("dateTimeDisplay").innerHTML = "Date & Time: " + dhaka.toDateString() + " " + dhaka.toLocaleTimeString();
  
  const yyyy = dhaka.getFullYear();
  const mm = String(dhaka.getMonth()+1).padStart(2,'0');
  const dd = String(dhaka.getDate()).padStart(2,'0');
  const hh = String(dhaka.getHours()).padStart(2,'0');
  const min = String(dhaka.getMinutes()).padStart(2,'0');
  const ss = String(dhaka.getSeconds()).padStart(2,'0');
  document.getElementById('dateTime').value = `${yyyy}-${mm}-${dd} ${hh}:${min}:${ss}`;
  
  const h = dhaka.getHours();
  const shift = (h >= 8 && h < 20) ? "Day" : "Night";
  document.getElementById("shiftBanner").innerText = "Shift: " + shift;
  document.getElementById("shift").value = shift;
}
setInterval(updateTimeBD, 1000);
updateTimeBD();

function generateSampleId() {
  const refSelect = document.getElementById('reference_number');
  const labTestNo = document.getElementById('lab_test_no')?.value || '';
  const refValue = refSelect ? refSelect.value.trim() : '';

  if (!refValue || !labTestNo) return;

  // Base is everything up to first dash (e.g., 4.0L125DEC16 from 4.0L125DEC16-R02-GT0.9.H0.1)
  const parts = refValue.split('-');
  const base = parts[0] || refValue;

  // Roll part: first segment starting with R (e.g., R02)
  let rollPart = '';
  for (const p of parts) {
    if (p.toUpperCase().startsWith('R')) {
      rollPart = p.toUpperCase();
      break;
    }
  }
  if (!rollPart) {
    const m = refValue.match(/(R\d+)/i);
    if (m) rollPart = m[1].toUpperCase();
  }
  if (!rollPart) rollPart = 'R01';

  // Lab test number -> LTXX
  const labTestFormatted = 'LT' + String(labTestNo).padStart(2, '0');

  // Final sample ID: BASE-LTXX-RYY (e.g., 4.0L125DEC16-LT01-R02)
  const sampleId = `${base}-${labTestFormatted}-${rollPart}`;

  // Set hidden roll number for backend (strip leading R)
  const rollNumeric = rollPart.replace(/^R/i, '');
  const rollNumberInput = document.getElementById('roll_number');
  if (rollNumberInput) rollNumberInput.value = rollNumeric;

  document.getElementById('sample_id').value = sampleId;
}

// Call generateSampleId on page load
document.addEventListener('DOMContentLoaded', function() {
  generateSampleId();
});

// Calculate values for a single row in the experimental data table
function calculateRow(rowNum) {
  // Input values
  const h0 = parseFloat(document.getElementById('exp_h0_' + rowNum).value) || 0;
  const h1 = parseFloat(document.getElementById('exp_h1_' + rowNum).value) || 0;
  const t1 = parseFloat(document.getElementById('exp_t1_' + rowNum).value) || 0;
  const t2 = parseFloat(document.getElementById('exp_t2_' + rowNum).value) || 0;
  const thickness = parseFloat(document.getElementById('exp_thickness_' + rowNum).value) || 0;
  const correction = parseFloat(document.getElementById('exp_correction_' + rowNum).value) || 1;

  // Setup calculation inputs
  const areaSpecimen = parseFloat(document.getElementById('area_specimen').value) || 0; // specimen area A
  const areaPipe = parseFloat(document.getElementById('area_pipe').value) || 0; // stand pipe area a

  // Head difference
  const headDiff = h0 - h1;
  document.getElementById('exp_head_diff_' + rowNum).value = headDiff.toFixed(3);

  // Time (s) - User inputs this manually, just read it
  const time = parseFloat(document.getElementById('exp_time_' + rowNum).value) || 0;

  if (h0 > 0 && h1 > 0 && time > 0 && areaSpecimen > 0 && thickness > 0) {
    const L = thickness / 1000; // mm → m
    const logRatio = Math.log10(h0 / h1);

    // ---- Permeability K (m/s) ----
    const K = ((areaPipe * L) / (areaSpecimen * time)) * 2.303 * logRatio * correction;

    // display in 10^-3 m/s
    const K_display = K * 1000;
    document.getElementById('exp_permeability_' + rowNum).value = K_display.toFixed(3);

    // ---- Velocity (m/s) ----
    const i = headDiff / L; // hydraulic gradient
    const velocity = K * i; // directly from Darcy's law
    document.getElementById('exp_velocity_' + rowNum).value = velocity.toFixed(3);
  }

  calculateAverages();
}

// Recalculate all rows when calculation inputs change
function recalculateAll() {
  const rows = document.querySelectorAll('#experimental_data_table tr');
  rows.forEach((row, index) => {
    const rowNum = index + 1;
    calculateRow(rowNum);
  });
}

// Calculate average permeability and velocity
function calculateAverages() {
  let totalK = 0, totalV = 0, nK = 0, nV = 0;

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

// Handle reference selection - check if bundle and show individual roll selector
function handleWPTReferenceSelection(selectedValue) {
    const productRefSelect = document.getElementById('reference_number');
    const individualRollSelect = document.getElementById('wpt_individual_roll_reference');
    const bundleInfo = document.getElementById('wpt_bundle_info');
    
    if (!selectedValue || selectedValue === '') {
        // Hide individual roll selector
        if (individualRollSelect) {
            individualRollSelect.style.display = 'none';
            individualRollSelect.value = '';
            individualRollSelect.removeAttribute('required');
        }
        if (bundleInfo) bundleInfo.style.display = 'none';
        loadWPTReferenceData('');
        return;
    }
    
    // Get the selected option
    const selectedOption = productRefSelect.options[productRefSelect.selectedIndex];
    const isBundle = selectedOption?.getAttribute('data-is-bundle') === 'true';
    
    if (isBundle) {
        // Show individual roll selector
        const baseRef = selectedOption.getAttribute('data-base-ref');
        const rollCount = parseInt(selectedOption.getAttribute('data-roll-count')) || 1;
        const bundleRef = selectedValue;
        
        // Fetch already tested rolls from this bundle
        fetch(`api/get_tested_rolls_wpt.php?bundle_ref=${encodeURIComponent(bundleRef)}`)
            .then(response => response.json())
            .then(data => {
                const testedRolls = data.success ? data.tested_rolls : [];
                
                // Populate individual roll dropdown, excluding already tested rolls
                if (individualRollSelect) {
                    individualRollSelect.innerHTML = '<option value="">-- Select Individual Roll for Testing --</option>';
                    for (let i = 1; i <= rollCount; i++) {
                        const individualRef = baseRef + '-' + i;
                        
                        // Skip if this roll has already been tested
                        if (testedRolls.includes(individualRef)) {
                            continue;
                        }
                        
                        const option = document.createElement('option');
                        option.value = individualRef;
                        option.textContent = `Roll ${i} - ${individualRef}`;
                        individualRollSelect.appendChild(option);
                    }
                    individualRollSelect.style.display = 'block';
                    individualRollSelect.setAttribute('required', 'required');
                }
                if (bundleInfo) bundleInfo.style.display = 'block';
            })
            .catch(error => {
                console.error('Error fetching tested rolls:', error);
                // Fallback: show all rolls if API fails
                if (individualRollSelect) {
                    individualRollSelect.innerHTML = '<option value="">-- Select Individual Roll for Testing --</option>';
                    for (let i = 1; i <= rollCount; i++) {
                        const option = document.createElement('option');
                        const individualRef = baseRef + '-' + i;
                        option.value = individualRef;
                        option.textContent = `Roll ${i} - ${individualRef}`;
                        individualRollSelect.appendChild(option);
                    }
                    individualRollSelect.style.display = 'block';
                    individualRollSelect.setAttribute('required', 'required');
                }
                if (bundleInfo) bundleInfo.style.display = 'block';
            });
        
        // Don't load reference data yet - wait for individual roll selection
        loadWPTReferenceData('');
    } else {
        // Hide individual roll selector
        if (individualRollSelect) {
            individualRollSelect.style.display = 'none';
            individualRollSelect.value = '';
            individualRollSelect.removeAttribute('required');
        }
        if (bundleInfo) bundleInfo.style.display = 'none';
        
        // Load reference data for single roll
        loadWPTReferenceData(selectedValue);
    }
}

// Handle individual roll selection from bundle
function handleWPTIndividualRollSelection(selectedValue) {
    if (selectedValue && selectedValue !== '') {
        // Check if this is from a bundle and get first test data to pre-fill
        const productRefSelect = document.getElementById('reference_number');
        const selectedOption = productRefSelect.options[productRefSelect.selectedIndex];
        const isBundle = selectedOption?.getAttribute('data-is-bundle') === 'true';
        const bundleRef = selectedOption?.value || '';
        
        if (isBundle && bundleRef) {
            // Fetch first test data from bundle to pre-fill form
            fetch(`api/get_first_bundle_test_data_wpt.php?bundle_ref=${encodeURIComponent(bundleRef)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        // Pre-fill form with first test data
                        if (data.data.gsm) document.getElementById('gsm').value = data.data.gsm;
                        if (data.data.roll_number) document.getElementById('roll_number').value = data.data.roll_number;
                        if (data.data.specimen_area) document.getElementById('specimen_area').value = data.data.specimen_area;
                        generateSampleId();
                    }
                    // Still try to load reference data (may have roll-specific info)
                    loadWPTReferenceData(selectedValue);
                })
                .catch(error => {
                    console.error('Error fetching bundle test data:', error);
                    // Still try to load reference data
                    loadWPTReferenceData(selectedValue);
                });
        } else {
            loadWPTReferenceData(selectedValue);
        }
    }
}

// Load reference data to auto-fill GSM and Roll Number
function loadWPTReferenceData(refNumber) {
  const refValue = refNumber || document.getElementById('reference_number')?.value || '';
  
  // If individual roll is selected, use that instead
  const individualRoll = document.getElementById('wpt_individual_roll_reference')?.value;
  const finalRef = individualRoll || refValue;
  
  if (!finalRef) {
    document.getElementById('gsm').value = '';
    document.getElementById('roll_number').value = '';
    generateSampleId();
    return;
  }
  
  fetch('api/get_reference_data.php?reference=' + encodeURIComponent(finalRef))
    .then(response => response.json())
    .then(data => {
      if (data.success && data.data) {
        // Only fill if not already filled from bundle data
        if (!document.getElementById('gsm').value && data.data.gsm) {
            document.getElementById('gsm').value = data.data.gsm || '';
        }
        if (!document.getElementById('roll_number').value && data.data.roll_number) {
            document.getElementById('roll_number').value = data.data.roll_number || '';
        }
        generateSampleId();
      } else {
        // Don't show alert if bundle data was loaded successfully
        if (!document.getElementById('gsm').value && !document.getElementById('roll_number').value) {
            console.warn('Could not load reference data:', data.error || 'Unknown error');
        }
      }
    })
    .catch(error => {
      console.error('Error:', error);
      // Don't alert if bundle data was already loaded
      if (!document.getElementById('gsm').value && !document.getElementById('roll_number').value) {
          console.error('Error loading reference data');
      }
    });
}

// Add a new row to experimental data table
let rowCount = 1;

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
    <td><button type="button" onclick="removeWPTRow(this)" style="padding:4px 8px; background:#dc3545; color:#fff; border:none; cursor:pointer;">Delete</button></td>
  `;
  
  tbody.appendChild(newRow);
  updateRowNumbers();
}

// Remove a row from experimental data table
function removeWPTRow(button) {
  const row = button.closest('tr');
  row.remove();
  updateRowNumbers();
  calculateAverages();
}

// Update row numbers after add/remove
function updateRowNumbers() {
  const rows = document.querySelectorAll('#experimental_data_table tr');
  rows.forEach((row, index) => {
    row.querySelector('td:first-child').textContent = index + 1;
    row.setAttribute('data-row', index + 1);
  });
  rowCount = rows.length;
}
</script>
</body>
</html
