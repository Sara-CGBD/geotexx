<?php
session_start();
require_once 'security_config.php';

// Prevent browser caching to ensure fresh dropdown data after submission
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();
$reporter_id = $_SESSION['user_id'];
$reporter_name = $_SESSION['username'];

// Fetch full name
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
    
    // Fetch reference numbers from roll_entry - support bundles and line detection
    // Tests are done AFTER roll entry submission
    // Exclude references that have been routed by AGM
    $references = [];
    $bundleReferences = [];
    
    // Helper function to check if a reference has been routed
    $isReferenceRouted = function($conn, $reference) {
        // Check if routed table exists
        $routedTableExists = $conn->query("SHOW TABLES LIKE 'routed'")->num_rows > 0;
        if (!$routedTableExists) {
            return false;
        }
        
        // Check exact match first
        $stmt = $conn->prepare("SELECT 1 FROM routed WHERE reference_number = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $reference);
            $stmt->execute();
            $result = $stmt->get_result();
            $routed = ($result && $result->num_rows > 0);
            $stmt->close();
            if ($routed) return true;
        }
        
        // Check prefix match (stored ref is prefix of query ref)
        $stmt = $conn->prepare("SELECT 1 FROM routed WHERE ? LIKE CONCAT(reference_number, '%') LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $reference);
            $stmt->execute();
            $result = $stmt->get_result();
            $routed = ($result && $result->num_rows > 0);
            $stmt->close();
            if ($routed) return true;
        }
        
        // Check prefix match (query ref is prefix of stored ref)
        $stmt = $conn->prepare("SELECT 1 FROM routed WHERE reference_number LIKE CONCAT(?, '%') LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('s', $reference);
            $stmt->execute();
            $result = $stmt->get_result();
            $routed = ($result && $result->num_rows > 0);
            $stmt->close();
            return $routed;
        }
        
        return false;
    };
    
    try {
        $refQuery = $conn->query("SELECT DISTINCT re.reference_number, MAX(re.date_time) as created_at
            FROM roll_entry re 
            LEFT JOIN characteristics_tests ct ON re.reference_number = ct.reference_number 
                AND ct.status IN ('pending', 'checked', 'approved')
            WHERE re.reference_number IS NOT NULL 
                AND re.reference_number != ''
                AND ct.reference_number IS NULL
            GROUP BY re.reference_number
            ORDER BY created_at DESC 
            LIMIT 100");
        if ($refQuery) {
            while ($row = $refQuery->fetch_assoc()) {
                $ref = $row['reference_number'];
                
                // Check if this reference has been routed by AGM - skip if routed
                if ($isReferenceRouted($conn, $ref)) {
                    continue;
                }
                
                // Detect line number (L1 or L2) from reference
                $lineIndicator = '';
                if (strpos($ref, 'L1') !== false) {
                    $lineIndicator = 'L1';
                } elseif (strpos($ref, 'L2') !== false) {
                    $lineIndicator = 'L2';
                }
                
                // Check if this is a bundle reference (ends with -N pattern)
                if (preg_match('/-(\d+)$/', $ref, $matches)) {
                    $rollCount = (int)$matches[1];
                    $baseRef = preg_replace('/-\d+$/', '', $ref);
                    
                    $bundleReferences[] = [
                        'reference' => $ref,
                        'base_reference' => $baseRef,
                        'roll_count' => $rollCount,
                        'date' => $row['created_at'],
                        'line' => $lineIndicator
                    ];
                } else {
                    $references[] = [
                        'reference' => $ref,
                        'line' => $lineIndicator,
                        'date' => $row['created_at']
                    ];
                }
            }
        }
    } catch (Exception $e) {
        $references = [];
    }

$__role = strtolower(trim($_SESSION['role'] ?? 'user'));
$is_tester = ($__role === 'tester');
$is_checker = ($__role === 'checker');
$can_approve = in_array($__role, ['admin', 'agm ops', 'agm operations'], true);

$message = '';
$error = '';

// Check for success message from session (after redirect)
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Create table function
function createCharacteristicsTable($conn) {
    $create_table = "CREATE TABLE IF NOT EXISTS characteristics_tests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_number VARCHAR(100) UNIQUE NOT NULL,
        lab_test_number VARCHAR(50) NOT NULL,
        test_standard VARCHAR(100) NULL,
        test_materials VARCHAR(255) NOT NULL,
        reference_number VARCHAR(200) NULL,
        gsm DECIMAL(10,4) NULL,
        roll_number VARCHAR(100) NULL,
        sample_id VARCHAR(100) NULL,
        specimen_size DECIMAL(10,4) NOT NULL,
        specimen_size_unit VARCHAR(10) NOT NULL,
        sand_type VARCHAR(100) NOT NULL,
        sand_weight DECIMAL(10,4) NOT NULL,
        sand_weight_unit VARCHAR(10) NOT NULL,
        sieving_time DECIMAL(10,4) NOT NULL,
        sieving_time_unit VARCHAR(10) NOT NULL,
        sample_received DATE NOT NULL,
        sample_tested DATETIME NOT NULL,
        test_results JSON NOT NULL,
        test_performed_by VARCHAR(255) NOT NULL,
        checker_name VARCHAR(255) NULL,
        approver_name VARCHAR(255) NULL,
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
    
    // Add new columns if missing (for existing installations)
    @$conn->query("ALTER TABLE characteristics_tests ADD COLUMN IF NOT EXISTS test_standard VARCHAR(100) NULL AFTER lab_test_number");
    @$conn->query("ALTER TABLE characteristics_tests ADD COLUMN IF NOT EXISTS reference_number VARCHAR(200) NULL AFTER test_materials");
    @$conn->query("ALTER TABLE characteristics_tests ADD COLUMN IF NOT EXISTS bundle_reference VARCHAR(200) NULL AFTER reference_number");
    
    // Modify existing columns to allow NULL (for existing installations)
    // Check if column exists and modify it
    $check_gsm = $conn->query("SHOW COLUMNS FROM characteristics_tests LIKE 'gsm'");
    if ($check_gsm && $check_gsm->num_rows > 0) {
        @$conn->query("ALTER TABLE characteristics_tests MODIFY COLUMN gsm DECIMAL(10,4) NULL");
    }
    
    $check_roll = $conn->query("SHOW COLUMNS FROM characteristics_tests LIKE 'roll_number'");
    if ($check_roll && $check_roll->num_rows > 0) {
        @$conn->query("ALTER TABLE characteristics_tests MODIFY COLUMN roll_number VARCHAR(100) NULL");
    }
    
    $check_sample_id = $conn->query("SHOW COLUMNS FROM characteristics_tests LIKE 'sample_id'");
    if ($check_sample_id && $check_sample_id->num_rows > 0) {
        @$conn->query("ALTER TABLE characteristics_tests MODIFY COLUMN sample_id VARCHAR(100) NULL");
    }
}

// Generate lab test number
function generateLabTestNumber($conn) {
    try {
        // Get current time
        $now = new DateTime();
        $current_hour = (int)$now->format('H');
        
        // Adjust date based on 8 AM shift
        // If before 8 AM, it belongs to previous day's batch
        $shift_date = $now;
        if ($current_hour < 8) {
            $shift_date->modify('-1 day');
        }
        
        $date_str = $shift_date->format('Y-m-d');
        
        // Query to find max report number for this shift period
        $stmt = $conn->prepare("
            SELECT report_number 
            FROM characteristics_tests 
            WHERE created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $start_datetime = $date_str . ' 08:00:00';
        $stmt->bind_param("ss", $start_datetime, $start_datetime);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row && $row['report_number']) {
            // Extract the sequential number from report number (format: CT-X.XLXxxXxx-XX-XX-X)
            // The number after the last dash is the sequence
            preg_match('/(\d+)$/', $row['report_number'], $matches);
            if (!empty($matches[1])) {
                $next_num = intval($matches[1]) + 1;
            } else {
                $next_num = 1;
            }
        } else {
            $next_num = 1;
        }
        
        return $next_num;
    } catch (Exception $e) {
        // Table doesn't exist yet or is empty
        return 1;
    }
}

createCharacteristicsTable($conn);

// Function to get pending reports for checker
function getPendingReportsForChecker($conn, $limit = 20) {
    // Check if reference_number column exists
    $refColCheck = $conn->query("SHOW COLUMNS FROM characteristics_tests LIKE 'reference_number'");
    $hasRefCol = ($refColCheck && $refColCheck->num_rows > 0);
    $refSelect = $hasRefCol ? ", COALESCE(reference_number, '') as reference_number" : ", '' as reference_number";
    
    $stmt = $conn->prepare(
        "SELECT id, report_number, lab_test_number, status, created_at, test_performed_by $refSelect
         FROM characteristics_tests 
         WHERE status = 'pending'
         ORDER BY created_at ASC 
         LIMIT ?"
    );
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

// Function to get pending reports for admin approval
function getPendingReportsForApproval($conn, $limit = 20) {
    // Check if reference_number column exists
    $refColCheck = $conn->query("SHOW COLUMNS FROM characteristics_tests LIKE 'reference_number'");
    $hasRefCol = ($refColCheck && $refColCheck->num_rows > 0);
    $refSelect = $hasRefCol ? ", COALESCE(reference_number, '') as reference_number" : ", '' as reference_number";
    
    $stmt = $conn->prepare(
        "SELECT id, report_number, lab_test_number, status, created_at, test_performed_by, checker_name $refSelect
         FROM characteristics_tests 
         WHERE status = 'checked'
         ORDER BY checked_at ASC 
         LIMIT ?"
    );
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

// Function to get rejected reports for a user (tester)
function getRejectedReportsForUser($conn, $user_id) {
    $stmt = $conn->prepare(
        "SELECT id, report_number, lab_test_number, gsm, roll_number, sample_id, status, remarks, 
                checker_name, approver_name, created_at, test_performed_by
         FROM characteristics_tests 
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

// Fetch rejected reports for testers
$rejected_reports = [];
if ($is_tester) {
    $rejected_reports = getRejectedReportsForUser($conn, $reporter_id);
}

// Handle admin approve/reject from dashboard
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ct_action'], $_POST['ct_report_id']) && $can_approve) {
    try {
        $action = $_POST['ct_action'];
        $test_id = intval($_POST['ct_report_id']);
        $admin_name = $reporter_full_name;
        
        if ($action === 'approve') {
            $stmt = $conn->prepare("UPDATE characteristics_tests SET status = 'approved', approver_name = ?, approved_at = NOW() WHERE id = ? AND status = 'checked'");
            $stmt->bind_param("si", $admin_name, $test_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                // Check bundle completion if this is from a bundle
                $bundleCheck = $conn->prepare("SELECT bundle_reference FROM characteristics_tests WHERE id = ? AND bundle_reference IS NOT NULL");
                $bundleCheck->bind_param("i", $test_id);
                $bundleCheck->execute();
                $bundleResult = $bundleCheck->get_result();
                if ($bundleRow = $bundleResult->fetch_assoc()) {
                    checkAndMarkBundleComplete($conn, 'characteristics_tests', $bundleRow['bundle_reference']);
                }
                $bundleCheck->close();
                
                $_SESSION['success_message'] = "Test approved successfully!";
                $stmt->close();
                // Prevent caching and redirect to refresh the page
                header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
                header("Cache-Control: post-check=0, pre-check=0", false);
                header("Pragma: no-cache");
                header("Location: characteristics_test.php?t=" . time());
                exit();
            }
            $stmt->close();
        } elseif ($action === 'reject') {
            // Reject only 'checked' reports (from admin dashboard)
            // This sends it back to tester to resubmit
            $reject_comment = trim($_POST['ct_reject_comment'] ?? '');
            
            // Handle rejection reasons checkboxes
            if (isset($_POST['ct_rejection_reasons']) && is_array($_POST['ct_rejection_reasons'])) {
                $rejection_reasons = array_map('trim', $_POST['ct_rejection_reasons']);
                $reasons_text = "Rejection Reasons: " . implode(', ', $rejection_reasons);
                // Append additional comments if provided
                if ($reject_comment) {
                    $reject_reason = $reasons_text . "\n\nAdditional Comments: " . $reject_comment;
                } else {
                    $reject_reason = $reasons_text;
                }
            } else {
                $reject_reason = "Rejected by Admin: " . $admin_name;
            }
            
            $stmt = $conn->prepare("UPDATE characteristics_tests SET status = 'rejected', remarks = ?, approver_name = ? WHERE id = ? AND status = 'checked'");
            $stmt->bind_param("ssi", $reject_reason, $admin_name, $test_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $_SESSION['success_message'] = "❌ Test rejected and returned to tester!";
                $stmt->close();
                // Prevent caching and redirect to refresh the page
                header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
                header("Cache-Control: post-check=0, pre-check=0", false);
                header("Pragma: no-cache");
                header("Location: characteristics_test.php?t=" . time());
                exit();
            }
            $stmt->close();
        }
        
        // Refresh the page to update the dashboard with success message
        // Add timestamp to force fresh load and prevent caching
        $success_msg = urlencode("Test submitted successfully! Report Number: {$report_number}");
        header("Location: characteristics_test.php?success=1&msg={$success_msg}&t=" . time());
        exit();
        
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_test'])) {
    try {
        $conn->begin_transaction();
        
        // Generate lab test number and report number
        $generated_lab_test_no = generateLabTestNumber($conn);
        $test_date = date('Y-m-d');
        $gsm = null; // No longer required
        $roll_number = null; // No longer required
        
        // Generate individual roll references from from-to range (same as QC test order)
        $bulk_rolls = [];
        if (isset($_POST['from_reference']) && isset($_POST['to_reference']) && 
            !empty($_POST['from_reference']) && !empty($_POST['to_reference'])) {
            
            $fromRef = trim($_POST['from_reference']);
            $toRef = trim($_POST['to_reference']);
            
            // Extract base reference and roll numbers
            $fromBaseRef = '';
            $fromRollNum = 0;
            $toBaseRef = '';
            $toRollNum = 0;
            
            if (preg_match('/^(.+)-(\d+)$/', $fromRef, $fromMatches)) {
                $fromBaseRef = $fromMatches[1];
                $fromRollNum = (int)$fromMatches[2];
            } else {
                $fromBaseRef = $fromRef;
                $fromRollNum = 1;
            }
            
            if (preg_match('/^(.+)-(\d+)$/', $toRef, $toMatches)) {
                $toBaseRef = $toMatches[1];
                $toRollNum = (int)$toMatches[2];
            } else {
                $toBaseRef = $toRef;
                $toRollNum = 1;
            }
            
            // If same base, generate all references from fromRollNum to toRollNum
            if ($fromBaseRef === $toBaseRef && $fromRollNum > 0 && $toRollNum > 0) {
                for ($roll = $fromRollNum; $roll <= $toRollNum; $roll++) {
                    $bulk_rolls[] = $fromBaseRef . '-' . $roll;
                }
                error_log("Characteristics Test: Generated " . count($bulk_rolls) . " individual references from range: " . $fromRef . " to " . $toRef);
            } else {
                // Different bases - add both endpoints
                $bulk_rolls[] = $fromRef;
                if ($toRef !== $fromRef) {
                    $bulk_rolls[] = $toRef;
                }
                error_log("Characteristics Test: WARNING - Different base references in range. Generated " . count($bulk_rolls) . " references.");
            }
        }
        
        // Get reference number - use individual roll reference if bundle is selected
        // Or use from_reference/to_reference if line-based selection is used
        $reference_number = '';
        $bundle_reference = null;
        
        if (isset($_POST['individual_roll_reference']) && !empty($_POST['individual_roll_reference'])) {
            $reference_number = trim($_POST['individual_roll_reference']);
            // Get bundle reference if individual roll is selected
            if (isset($_POST['reference_number']) && !empty($_POST['reference_number'])) {
                $bundle_reference = trim($_POST['reference_number']); // Original bundle reference
            }
        } elseif (!empty($bulk_rolls)) {
            // Range selected - bundle_reference will be set for all rolls
            $bundle_reference = trim($_POST['from_reference']) . '|' . trim($_POST['to_reference']);
            // reference_number will be set per roll in the loop
        } elseif (isset($_POST['reference_number']) && !empty($_POST['reference_number'])) {
            $reference_number = trim($_POST['reference_number']);
            // Check if this is a range format (from|to)
            if (strpos($reference_number, '|') !== false) {
                $parts = explode('|', $reference_number);
                if (count($parts) === 2) {
                    $reference_number = trim($parts[0]); // Use from reference
                    $bundle_reference = $reference_number; // Store the range format
                }
            }
        }
        
        // Simple report number format: CT-YYYYMMDD-XXXXX
        $date_formatted = date('Ymd');
        
        // Collect sieve analysis data (dynamic rows up to 20)
        $sieve_data = [];
        for ($i = 1; $i <= 20; $i++) {
            if (isset($_POST["sieve_size_$i"])) {
                $sieve_data[] = [
                    'sieve_size' => floatval($_POST["sieve_size_$i"] ?? 0),
                    'retained' => floatval($_POST["retained_$i"] ?? 0),
                    'cumulative' => floatval($_POST["cumulative_$i"] ?? 0),
                    'passing' => floatval($_POST["passing_$i"] ?? 0)
                ];
            }
        }
        
        $test_results = [
            'sieve_data' => $sieve_data,
            'o_value' => floatval($_POST['o_value'] ?? 0),
            'opening_size' => floatval($_POST['opening_size'] ?? 0),
            'remarks' => trim($_POST['remarks'] ?? '')
        ];
        
        $test_results_json = json_encode($test_results);
        
        // Determine status based on user role
        // Characteristics test must go through checker first, then admin/AGM
        // All new submissions start as 'pending' for checker review
        $submit_status = 'pending';
        $checker_name_val = null;
        $approved_at_val = null;
        $checked_at_val = null;
        
        // Even admin submissions go through checker workflow
        // Checker approval (separate action) will change status from 'pending' to 'checked'
        // Admin approval (separate action) will change status from 'checked' to 'approved'
        
        // Bundle reference is now set above when processing from_reference/to_reference
        
        // Prepare the insert statement
        $stmt = $conn->prepare(
            "INSERT INTO characteristics_tests 
            (report_number, lab_test_number, test_standard, test_materials, reference_number, bundle_reference, gsm, roll_number, sample_id,
             specimen_size, specimen_size_unit, sand_type, sand_weight, sand_weight_unit,
             sieving_time, sieving_time_unit, sample_received, sample_tested,
             test_results, test_performed_by, approver_name, checker_name, checked_at, approved_at, 
             reporter_id, reporter_name, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        
        $sample_tested = date('Y-m-d H:i:s');
        $test_standard = $_POST['test_standard'] ?? 'ISO 12956';
        
        // Ensure numeric values are safe
        $specimen_size = isset($_POST['specimen_size']) && is_numeric($_POST['specimen_size']) ? floatval($_POST['specimen_size']) : 0.0;
        $sieving_time = isset($_POST['sieving_time']) && is_numeric($_POST['sieving_time']) ? floatval($_POST['sieving_time']) : 0.0;
        $sand_weight = isset($_POST['sand_weight']) && is_numeric($_POST['sand_weight']) ? floatval($_POST['sand_weight']) : 0.0;
        
        $sample_id = $_POST['sample_id'] ?? null;
        
        // Set approver_name based on user role
        $approver_name = '';
        if ($can_approve) {
            $approver_name = $_POST['approved_by'] ?? $reporter_full_name;
        }
        
        // Process each roll in the range (or single reference)
        $rolls_to_process = !empty($bulk_rolls) ? $bulk_rolls : [];
        if (empty($rolls_to_process) && !empty($reference_number)) {
            $rolls_to_process = [$reference_number];
        }
        
        if (empty($rolls_to_process)) {
            throw new Exception("No reference selected. Please select a reference or range.");
        }
        
        $inserted_count = 0;
        $report_numbers = [];
        
        foreach ($rolls_to_process as $roll_ref) {
            // For bulk rolls, use the individual roll as reference_number
            $current_reference_number = !empty($bulk_rolls) ? $roll_ref : $reference_number;
            
            // Generate unique report number for each roll
            $current_lab_test_no = $generated_lab_test_no + $inserted_count;
            $report_number = "CT-{$date_formatted}-" . str_pad($current_lab_test_no, 3, '0', STR_PAD_LEFT);
            $report_numbers[] = $report_number;
        
        $stmt->bind_param(
            "sssssssdssdssdsdssssssssiss",
            $report_number,                  // s
                $current_lab_test_no,            // s
            $test_standard,                  // s
            $_POST['test_materials'],        // s
                $current_reference_number,       // s
            $bundle_reference,               // s (NULL if not bundle)
            $gsm,                            // d (NULL)
            $roll_number,                    // s (NULL)
            $sample_id,                      // s
            $specimen_size,                  // d
            $_POST['specimen_size_unit'],    // s
            $_POST['sand_type'],             // s
            $sand_weight,                    // d
            $_POST['sand_weight_unit'],      // s
            $sieving_time,                   // d
            $_POST['sieving_time_unit'],     // s
            $_POST['sample_received'],       // s
            $sample_tested,                  // s
            $test_results_json,              // s
            $reporter_full_name,             // s (test_performed_by)
            $approver_name,                  // s (approver_name - only for admin/AGM)
            $checker_name_val,               // s
            $checked_at_val,                 // s
            $approved_at_val,                // s
            $reporter_id,                    // i
            $reporter_name,                  // s
            $submit_status                   // s
        );
        
        if ($stmt->execute()) {
                $inserted_count++;
            } else {
                error_log("Characteristics Test: Failed to insert roll " . $roll_ref . ": " . $stmt->error);
            }
        }
        
        if ($inserted_count > 0) {
            $conn->commit();
            if ($submit_status === 'approved') {
                $message = $inserted_count > 1 
                    ? "{$inserted_count} tests submitted and auto-approved! Report Numbers: " . implode(', ', array_slice($report_numbers, 0, 3)) . (count($report_numbers) > 3 ? '...' : '') . " | Status: Approved"
                    : "Test submitted and auto-approved! Report Number: " . $report_numbers[0] . " | Status: Approved";
            } else {
                $message = $inserted_count > 1 
                    ? "{$inserted_count} tests submitted successfully! Report Numbers: " . implode(', ', array_slice($report_numbers, 0, 3)) . (count($report_numbers) > 3 ? '...' : '') . " | Status: Pending"
                    : "Test submitted successfully! Report Number: " . $report_numbers[0] . " | Status: Pending";
            }
            
            // Check bundle completion if this is from a bundle and was approved
            if ($bundle_reference && $submit_status === 'approved') {
                checkAndMarkBundleComplete($conn, 'characteristics_tests', $bundle_reference);
            }
            
            // Store success message in session and redirect to refresh dropdown
            $_SESSION['success_message'] = $message;
            $success_msg = urlencode($message);
            header("Location: characteristics_test.php?success=1&msg={$success_msg}&t=" . time());
            exit();
        } else {
            $stmt->close();
            throw new Exception("Failed to save test: " . $stmt->error);
        }
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error: " . $e->getMessage();
    }
}

// Generate preview lab test number
$generated_lab_test_no = generateLabTestNumber($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Determination of the Characteristics Test (ISO 12956)</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:5px 10px 5px 5px; color:#2c3e50; }
  .container { max-width:100%; margin:0; margin-left:0; background:#fff; border-radius:8px; padding:15px 25px 15px 10px; box-shadow:0 2px 10px rgba(0,0,0,0.08);} 
  h1 { text-align:center; font-size:28px; margin-bottom:30px; color:#2c3e50; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; font-size:12px; }
  input[type="text"], input[type="number"], input[type="date"], input[type="datetime-local"], select, textarea { 
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
  .calc-section { margin-top:20px; padding:15px; border:2px solid #27ae60; border-radius:8px; background:#d5f4e6; }
  .calc-btn { padding:8px 16px; background:#27ae60; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:bold; }
  .result-box { background:#e8f5e9; padding:15px; border-radius:6px; margin-top:10px; border:2px solid #4caf50; }
</style>
</head>
<body>
<div class="container">
  <h1>Determination of the Characteristics Test (ISO 12956)</h1>

  <?php if ($message): ?>
    <div class="alert alert-success"><?php echo $message; ?></div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-error">❌ <?php echo $error; ?></div>
  <?php endif; ?>

  <div style="margin-bottom: 15px;">
    <a href="../admin/lab_testing_dashboard.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
  </div>

  <?php if ($is_checker && !empty($pending_reports)): ?>
  <!-- Checker Dashboard: Pending for Checking -->
  <div style="margin-top:16px; padding:12px; border:1px solid #ddd; border-radius:8px; background:#fff;">
    <h3 style="margin:0 0 12px 0;"> Pending Reports</h3>
    <table class="test-table">
      <thead>
        <tr>
          <th>Report No</th>
          <th>Lab Test No</th>
          <th>Reference Number</th>
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
          <td><?php echo htmlspecialchars($pr['test_performed_by']); ?></td>
          <td><?php echo date('M d, Y H:i', strtotime($pr['created_at'])); ?></td>
          <td>
            <a href="../admin/check_characteristics.php?id=<?php echo $pr['id']; ?>" 
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
    <h3 style="margin:0 0 12px 0;">✅ Pending Reports for Approval (Checked)</h3>
    <table class="test-table">
      <thead>
        <tr>
          <th>Report No</th>
          <th>Lab Test No</th>
          <th>Reference Number</th>
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
          <td><?php echo htmlspecialchars($pr['test_performed_by']); ?></td>
          <td><?php echo htmlspecialchars($pr['checker_name'] ?? 'N/A'); ?></td>
          <td><?php echo date('M d, Y H:i', strtotime($pr['created_at'])); ?></td>
          <td>
            <a href="../admin/view_characteristics.php?id=<?php echo $pr['id']; ?>" 
               target="_blank"
               style="background:#3498db; color:#fff; padding:4px 8px; border-radius:4px; text-decoration:none; font-size:12px; margin-right:4px;">
              View
            </a>
            <form method="POST" action="" style="display:inline; margin-right:4px;">
              <input type="hidden" name="ct_report_id" value="<?php echo $pr['id']; ?>">
              <input type="hidden" name="ct_action" value="approve">
              <button type="submit" style="background:#28a745; color:#fff; padding:4px 8px; border:none; border-radius:4px; cursor:pointer; font-size:12px;">Approve</button>
            </form>
            <button type="button" onclick="openCTRejectModal(<?php echo $pr['id']; ?>)" style="background:#dc3545; color:#fff; padding:4px 8px; border:none; border-radius:4px; cursor:pointer; font-size:12px;">Reject</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (!$is_tester && !$can_approve && !empty($rejected_reports)): ?>
  <!-- Tester View: Rejected Reports -->
  <div style="margin-top:20px; padding:15px; border:2px solid #e74c3c; border-radius:8px; background:#fff5f5;">
    <h3 style="margin:0 0 12px 0; color:#c0392b;">Pending Reports:</h3>
    <p style="color:#555; margin-bottom:12px;">The following tests were rejected. Please review the comments and make corrections.</p>
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
        <?php foreach ($rejected_reports as $rj): ?>
        <tr>
          <td><?php echo htmlspecialchars($rj['report_number']); ?></td>
          <td><?php echo htmlspecialchars($rj['lab_test_number']); ?></td>
          <td><?php echo date('Y-m-d', strtotime($rj['created_at'])); ?></td>
          <td><?php echo htmlspecialchars($rj['checker_name'] ?? $rj['approver_name'] ?? 'N/A'); ?></td>
          <td style="white-space:normal; text-align:left; max-width:200px;">
            <?php 
              $clean_remarks = preg_replace('/^\[?(Checker|Admin) Rejection\]?:\s*/i', '', $rj['remarks']);
              echo htmlspecialchars($clean_remarks); 
            ?>
          </td>
          <td><?php echo date('Y-m-d H:i:s', strtotime($rj['created_at'])); ?></td>
          <td>
            <a href="edit_characteristics.php?id=<?php echo $rj['id']; ?>" 
               style="background:#f39c12; color:#fff; padding:4px 8px; border-radius:4px; text-decoration:none; font-size:12px;">
              Edit & Resubmit
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin-top:12px; color:#666; font-size:13px;">💡 <strong>Note:</strong> Please review the rejection comments and submit a new corrected test below.</p>
  </div>
  <?php endif; ?>
  
  <?php if (!$is_checker): ?>
  <!-- Form is only visible to testers and admins, not checkers -->
  <form method="POST" action="">
    
    <!-- Date/Time and Shift Display -->
    <div id="dateTimeDisplay" class="summary-info"></div>
    <div id="shiftBanner" class="summary-info"></div>

    <div class="form-row">
      <div class="form-group">
        <label>Lab Test No.:</label>
        <input type="text" name="lab_test_no" value="<?php echo htmlspecialchars($generated_lab_test_no); ?>" readonly class="readonly">
      </div>
      <div class="form-group">
        <label>Test Standard:</label>
        <input type="text" name="test_standard" value="ISO 12956" readonly class="readonly">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group" style="grid-column: 1 / -1;">
        <label>Test Materials:</label>
        <input type="text" name="test_materials" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group" style="grid-column: 1 / -1;">
        <label>Reference: <span style="color:red;">*</span></label>
        
        <!-- Line Selection Buttons -->
        <div id="char_line_selection_buttons" style="display:flex; gap:10px; margin-bottom:10px;">
          <button type="button" id="char_line1_btn" class="line-btn" onclick="filterCharByLine('L1')" style="padding:8px 16px; border:2px solid #3498db; border-radius:6px; background:#e3f2fd; color:#1565C0; font-weight:600; cursor:pointer;">
            Line 1
          </button>
          <button type="button" id="char_line2_btn" class="line-btn" onclick="filterCharByLine('L2')" style="padding:8px 16px; border:2px solid #3498db; border-radius:6px; background:#e3f2fd; color:#1565C0; font-weight:600; cursor:pointer;">
            Line 2
          </button>
          <button type="button" id="char_line_all_btn" class="line-btn active" onclick="filterCharByLine('all')" style="padding:8px 16px; border:2px solid #3498db; border-radius:6px; background:#2196F3; color:#ffffff; font-weight:600; cursor:pointer;">
            All Lines
          </button>
        </div>
        
        <!-- From/To Reference Selection (shown when line is selected) -->
        <div id="char_bulk_reference_selection" style="display:none; margin-bottom:10px; padding:10px; background:#f8f9fa; border:1px solid #ddd; border-radius:6px;">
          <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <label style="font-weight:600; margin:0;">From Reference:</label>
            <select id="char_from_reference" name="from_reference" style="min-width:250px; padding:5px; border:1px solid #ccc; border-radius:4px;" onchange="updateCharReferenceRange(true); handleCharFromToReferenceChange();">
              <option value="">-- Select From Reference --</option>
            </select>
            <label style="font-weight:600; margin:0;">To Reference:</label>
            <select id="char_to_reference" name="to_reference" style="min-width:250px; padding:5px; border:1px solid #ccc; border-radius:4px;" onchange="updateCharReferenceRange(false); handleCharFromToReferenceChange();">
              <option value="">-- Select To Reference --</option>
            </select>
            <button type="button" onclick="applyCharBulkReferenceSelection()" style="padding:6px 12px; background:#3498db; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:600;">
              Apply
            </button>
            <button type="button" onclick="clearCharBulkReferenceSelection()" style="padding:6px 12px; background:#6c757d; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:600;">
              Clear
            </button>
          </div>
          
          <!-- Reference Test Status Display (shown when Apply is clicked) -->
          <div id="char_reference_test_status_container" style="display:none; margin-top:15px; padding:0; background:#ffffff; border:1px solid #e0e0e0; border-radius:8px; box-shadow:0 1px 4px rgba(0,0,0,0.08); max-width:550px; margin-left:auto; margin-right:auto;">
            <div style="padding:12px 16px; background:linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius:8px 8px 0 0; color:white;">
              <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
                <div style="display:flex; align-items:center; gap:8px;">
                  <i class="fas fa-list-check" style="font-size:16px;"></i>
                  <h3 style="margin:0; font-size:14px; font-weight:600;" id="char_reference_status_title">Test Status</h3>
                </div>
                <div style="font-size:11px; opacity:0.95; font-weight:500;" id="char_reference_status_summary"></div>
              </div>
            </div>
            <div id="char_reference_test_status_list" style="padding:12px; max-height:300px; overflow-y:auto;">
              <!-- Status will be populated here -->
            </div>
          </div>
          
          <!-- Hidden input to store the selected product_reference when line-based selection is used -->
          <input type="hidden" id="char_line_based_product_reference" name="product_reference" value="">
        </div>
        
        <!-- Production Product Reference Dropdown (shown when "All Lines" is selected) -->
        <select name="reference_number" id="char_reference_number" onchange="handleCharReferenceSelection(this.value)" required style="padding:10px; border:1px solid #ccc; border-radius:6px; width:100%;">
          <option value="">Select Reference</option>
          <?php 
          // Show bundle references
          foreach($bundleReferences as $bundle): ?>
            <option value="<?php echo htmlspecialchars($bundle['reference']); ?>" data-is-bundle="true" data-base-ref="<?php echo htmlspecialchars($bundle['base_reference']); ?>" data-roll-count="<?php echo $bundle['roll_count']; ?>" data-line="<?php echo htmlspecialchars($bundle['line'] ?? ''); ?>">
              <?php echo htmlspecialchars($bundle['reference']); ?> (Bundle - <?php echo $bundle['roll_count']; ?> rolls)
            </option>
          <?php endforeach; ?>
          <?php 
          // Show single roll references
          foreach($references as $ref): 
            $refValue = is_array($ref) ? $ref['reference'] : $ref;
            $lineIndicator = is_array($ref) ? ($ref['line'] ?? '') : '';
          ?>
            <option value="<?php echo htmlspecialchars($refValue); ?>" data-is-bundle="false" data-line="<?php echo htmlspecialchars($lineIndicator); ?>">
              <?php echo htmlspecialchars($refValue); ?>
            </option>
          <?php endforeach; ?>
        </select>
        
        <!-- Individual Roll Selector (shown when bundle is selected) -->
        <select name="individual_roll_reference" id="char_individual_roll_reference" onchange="handleCharIndividualRollSelection(this.value)" style="display:none; margin-top:10px; padding:10px; border:2px solid #3498db; border-radius:6px; background:#f8f9fa;">
          <option value="">-- Select Individual Roll for Testing --</option>
        </select>
        <div id="char_bundle_info" style="display:none; margin-top:8px; padding:10px; background:#e3f2fd; border-left:4px solid #2196F3; border-radius:4px; font-size:13px; color:#1565C0;">
          <i class="fas fa-info-circle"></i> <strong>Bundle Detected:</strong> This reference contains multiple rolls. <strong>Please select the specific roll number</strong> you want to test individually.
        </div>
        <div id="char_reference_error" style="color:red; margin-top:5px; font-size:12px;"></div>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Report ID:</label>
        <input type="text" name="sample_id" id="report_id" readonly class="readonly">
      </div>
      <div class="form-group">
        <label>Specimens Size:</label>
        <div style="display:flex; gap:5px;">
          <input type="number" name="specimen_size" step="0.0001" min="0" required style="flex:1;">
          <select name="specimen_size_unit" style="width:70px;">
            <option value="mm">mm</option>
            <option value="mm2">mm²</option>
            <option value="cm">cm</option>
            <option value="m">m</option>
            <option value="in">in</option>
            <option value="ft">ft</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Sand Type:</label>
        <input type="text" name="sand_type" required>
      </div>
      <div class="form-group">
        <label>Total Sand Weight:</label>
        <div style="display:flex; gap:5px;">
          <input type="number" name="sand_weight" step="0.0001" min="0" required style="flex:1;" id="sand_weight">
          <select name="sand_weight_unit" style="width:70px;">
            <option value="g">g</option>
            <option value="kg">kg</option>
            <option value="mg">mg</option>
            <option value="lb">lb</option>
            <option value="oz">oz</option>
          </select>
        </div>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Sieving Time:</label>
        <div style="display:flex; gap:5px;">
          <input type="number" name="sieving_time" step="0.0001" min="0" required style="flex:1;">
          <select name="sieving_time_unit" style="width:70px;">
            <option value="min">min</option>
            <option value="s">s</option>
            <option value="h">h</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Sample Received:</label>
        <input type="date" name="sample_received" value="<?php echo date('Y-m-d'); ?>" required>
      </div>
      <div class="form-group">
        <label>Sample Tested:</label>
        <input type="datetime-local" name="sample_tested" value="<?php echo date('Y-m-d\TH:i'); ?>" readonly class="readonly">
      </div>
    </div>

    <div class="section-title">📊 Sieve Analysis Data</div>
    <div style="overflow-x:auto;">
      <table class="test-table">
        <thead>
          <tr>
            <th>Sieve Size (mm)</th>
            <th>Retained (g)</th>
            <th>Cumulative (g)</th>
            <th>Cumulative Passing (%)</th>
            <th style="width:80px;">Action</th>
          </tr>
        </thead>
        <tbody id="sieve_tbody">
          <?php for ($i = 1; $i <= 4; $i++): ?>
          <tr>
            <td><input type="number" name="sieve_size_<?php echo $i; ?>" id="sieve_size_<?php echo $i; ?>" step="0.0001" min="0"></td>
            <td><input type="number" name="retained_<?php echo $i; ?>" id="retained_<?php echo $i; ?>" step="0.0001" min="0" oninput="calculateSieve()"></td>
            <td><input type="number" name="cumulative_<?php echo $i; ?>" id="cumulative_<?php echo $i; ?>" readonly class="readonly"></td>
            <td><input type="number" name="passing_<?php echo $i; ?>" id="passing_<?php echo $i; ?>" readonly class="readonly"></td>
            <td><?php if($i > 1): ?><button type="button" onclick="removeSieveRow(this)" style="padding:4px 8px; background:#dc3545; color:#fff; border:none; cursor:pointer;">Delete</button><?php endif; ?></td>
          </tr>
          <?php endfor; ?>
        </tbody>
      </table>
      <button type="button" onclick="addSieveRow()" style="margin-top:10px; padding:8px 16px; background:#28a745; color:#fff; border:none; cursor:pointer;">Add 1 More Row</button>
    </div>

    <div class="section-title">Opening Size Calculation (AOS)</div>
    <div class="calc-section">
      <div style="margin-bottom:15px;">
        <label style="font-size:14px; font-weight:bold; margin-bottom:8px; display:block;">Enter O-Value (%):</label>
        <div style="display:flex; gap:10px; align-items:center;">
          <input type="number" id="o_value_input" name="o_value" placeholder="e.g., 90" min="0" max="100" step="0.1" style="width:100px; padding:8px; border:1px solid #ddd; border-radius:4px;">
          <button type="button" onclick="calculateOpening()" class="calc-btn">Calculate</button>
        </div>
      </div>
      <div id="calculation_display" style="display:none; margin-top:15px;">
        <h4 style="margin:10px 0; color:#2c3e50; font-size:15px;">Calculation:</h4>
        <div id="calculation_text" style="background:#fff; padding:12px; border-radius:6px; border:1px solid #27ae60; line-height:1.8; font-size:14px;"></div>
      </div>
      <div id="result_display" style="display:none; margin-top:15px;">
        <h4 style="margin:10px 0; color:#2c3e50; font-size:15px;">Results:</h4>
        <div class="result-box">
          <div id="final_result" style="font-size:16px; font-weight:bold; color:#27ae60;"></div>
        </div>
        <input type="hidden" name="opening_size" id="opening_size">
      </div>
    </div>

    <div class="section-title">Remarks</div>
    <div class="form-group">
      <label>Remarks (Optional):</label>
      <textarea name="remarks" rows="3"></textarea>
    </div>

    <?php if ($can_approve): ?>
    <!-- Approved By field - only for AGM/Admin -->
    <div class="form-row">
      <div class="form-group">
        <label>Approved By:</label>
        <input type="text" name="approved_by" value="<?php echo htmlspecialchars($reporter_full_name); ?>" readonly class="readonly">
      </div>
    </div>
    <?php else: ?>
    <!-- Test Conducted By field - for testers -->
    <div class="form-row">
      <div class="form-group">
        <label>Test Conducted By:</label>
        <input type="text" name="test_conducted_by" value="<?php echo htmlspecialchars($reporter_full_name); ?>" readonly class="readonly">
        <input type="hidden" name="approved_by" value="">
      </div>
    </div>
    <?php endif; ?>

    <div class="actions">
      <button type="submit" name="submit_test" class="submit-btn">Submit Test</button>
      <button type="button" onclick="document.querySelector('form').reset();" class="clear-btn">Clear</button>
    </div>
  </form>
  <?php endif; ?>
</div>

<script>
// Update date/time and shift
function updateTimeBD() {
  const now = new Date();
  const utc = now.getTime() + now.getTimezoneOffset() * 60000;
  const dhaka = new Date(utc + 6 * 3600000);
  
  document.getElementById("dateTimeDisplay").innerHTML = 
    "Date & Time: " + dhaka.toDateString() + " " + dhaka.toLocaleTimeString();
  
  const h = dhaka.getHours();
  const shift = (h >= 8 && h < 20) ? "Day" : "Night";
  document.getElementById("shiftBanner").innerText = "Shift: " + shift;
}

setInterval(updateTimeBD, 1000);
updateTimeBD();

// Generate Report ID (simplified - no longer depends on GSM and Roll Number)
function generateReportID() {
  const labTestNo = document.querySelector('input[name="lab_test_no"]').value;
  
  if (!labTestNo) {
    document.getElementById('report_id').value = '';
    return;
  }
  
  const now = new Date();
  const utc = now.getTime() + now.getTimezoneOffset() * 60000;
  const dhaka = new Date(utc + 6 * 3600000);
  
  const year = dhaka.getFullYear();
  const month = String(dhaka.getMonth() + 1).padStart(2, '0');
  const day = String(dhaka.getDate()).padStart(2, '0');
  
  const labTestFormatted = 'LT' + labTestNo;
  const dateFormatted = `${year}${month}${day}`;
  
  const reportID = `${dateFormatted}-${labTestFormatted}`;
  
  document.getElementById('report_id').value = reportID;
}

// Generate on page load
generateReportID();

// Add event listener to sand_weight to trigger recalculation
document.getElementById('sand_weight').addEventListener('input', calculateSieve);

// Load references list on page load
document.addEventListener('DOMContentLoaded', function() {
    loadCharReferencesList();
});

// Load references list from API
function loadCharReferencesList() {
    fetch('api/get_characteristics_references_list.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const select = document.getElementById('char_reference_number');
                if (!select) return;
                
                // Clear existing options except the first placeholder
                select.innerHTML = '<option value="">Select Reference</option>';
                
                // Add bundle references
                if (data.bundleReferences && data.bundleReferences.length > 0) {
                    data.bundleReferences.forEach(bundle => {
                        const option = document.createElement('option');
                        option.value = bundle.reference;
                        option.setAttribute('data-is-bundle', 'true');
                        option.setAttribute('data-base-ref', bundle.base_reference);
                        option.setAttribute('data-roll-count', bundle.roll_count);
                        option.setAttribute('data-line', bundle.line || '');
                        option.textContent = bundle.reference + ' (Bundle - ' + bundle.roll_count + ' rolls)';
                        select.appendChild(option);
                    });
                }
                
                // Add single roll references
                if (data.references && data.references.length > 0) {
                    data.references.forEach(ref => {
                        const refValue = typeof ref === 'string' ? ref : ref.reference;
                        const lineIndicator = typeof ref === 'object' ? (ref.line || '') : '';
                        const option = document.createElement('option');
                        option.value = refValue;
                        option.setAttribute('data-is-bundle', 'false');
                        option.setAttribute('data-line', lineIndicator);
                        option.textContent = refValue;
                        select.appendChild(option);
                    });
                }
            }
        })
        .catch(err => console.error('Error loading references:', err));
}

// Handle reference selection - check if bundle and show individual roll selector
function handleCharReferenceSelection(selectedValue) {
    const productRefSelect = document.getElementById('char_reference_number');
    const individualRollSelect = document.getElementById('char_individual_roll_reference');
    const bundleInfo = document.getElementById('char_bundle_info');
    
    if (!selectedValue || selectedValue === '') {
        // Hide individual roll selector
        if (individualRollSelect) {
            individualRollSelect.style.display = 'none';
            individualRollSelect.value = '';
            individualRollSelect.removeAttribute('required');
        }
        if (bundleInfo) bundleInfo.style.display = 'none';
        generateReportID();
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
        fetch(`api/get_tested_rolls_char.php?bundle_ref=${encodeURIComponent(bundleRef)}`)
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
                generateReportID();
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
                generateReportID();
            });
    } else {
        // Hide individual roll selector
        if (individualRollSelect) {
            individualRollSelect.style.display = 'none';
            individualRollSelect.value = '';
            individualRollSelect.removeAttribute('required');
        }
        if (bundleInfo) bundleInfo.style.display = 'none';
        
        // Generate Report ID for single roll
        generateReportID();
    }
}

// Filter references by Line (L1 or L2)
function filterCharByLine(line) {
    const productRefSelect = document.getElementById('char_reference_number');
    const bulkRefSelection = document.getElementById('char_bulk_reference_selection');
    
    // Update button styles
    const line1Btn = document.getElementById('char_line1_btn');
    const line2Btn = document.getElementById('char_line2_btn');
    const lineAllBtn = document.getElementById('char_line_all_btn');
    
    if (line1Btn) line1Btn.classList.remove('active');
    if (line2Btn) line2Btn.classList.remove('active');
    if (lineAllBtn) lineAllBtn.classList.remove('active');
    
    if (line === 'L1' && line1Btn) {
        line1Btn.classList.add('active');
        line1Btn.style.background = '#2196F3';
        line1Btn.style.color = '#ffffff';
        line2Btn.style.background = '#e3f2fd';
        line2Btn.style.color = '#1565C0';
        lineAllBtn.style.background = '#e3f2fd';
        lineAllBtn.style.color = '#1565C0';
    } else if (line === 'L2' && line2Btn) {
        line2Btn.classList.add('active');
        line2Btn.style.background = '#2196F3';
        line2Btn.style.color = '#ffffff';
        line1Btn.style.background = '#e3f2fd';
        line1Btn.style.color = '#1565C0';
        lineAllBtn.style.background = '#e3f2fd';
        lineAllBtn.style.color = '#1565C0';
    } else if (line === 'all' && lineAllBtn) {
        lineAllBtn.classList.add('active');
        lineAllBtn.style.background = '#2196F3';
        lineAllBtn.style.color = '#ffffff';
        line1Btn.style.background = '#e3f2fd';
        line1Btn.style.color = '#1565C0';
        line2Btn.style.background = '#e3f2fd';
        line2Btn.style.color = '#1565C0';
    }
    
    if (line === 'L1' || line === 'L2') {
        // Hide single reference dropdown
        productRefSelect.style.display = 'none';
        productRefSelect.value = '';
        productRefSelect.removeAttribute('required');
        
        // Show From/To reference selection
        if (bulkRefSelection) {
            bulkRefSelection.style.display = 'block';
            populateCharLineReferences(line);
        }
        
        // Make From/To required
        const fromRefSelect = document.getElementById('char_from_reference');
        const toRefSelect = document.getElementById('char_to_reference');
        if (fromRefSelect) fromRefSelect.setAttribute('required', 'required');
        if (toRefSelect) toRefSelect.setAttribute('required', 'required');
    } else {
        // Show single reference dropdown for "All Lines"
        productRefSelect.style.display = 'block';
        productRefSelect.setAttribute('required', 'required');
        
        // Remove required from From/To
        const fromRefSelect = document.getElementById('char_from_reference');
        const toRefSelect = document.getElementById('char_to_reference');
        if (fromRefSelect) {
            fromRefSelect.removeAttribute('required');
            fromRefSelect.value = '';
        }
        if (toRefSelect) {
            toRefSelect.removeAttribute('required');
            toRefSelect.value = '';
        }
        
        // Hide From/To reference selection
        if (bulkRefSelection) {
            bulkRefSelection.style.display = 'none';
            clearCharBulkReferenceSelection();
        }
        
        // Clear hidden input
        const lineBasedProductRef = document.getElementById('char_line_based_product_reference');
        if (lineBasedProductRef) {
            lineBasedProductRef.value = '';
        }
        
        // Filter options for "All Lines"
        const currentValue = productRefSelect.value;
        Array.from(productRefSelect.options).forEach(option => {
            if (option.value === '') {
                option.style.display = '';
                return;
            }
            option.style.display = '';
        });
        
        // Clear selection if current value doesn't match filter
        if (currentValue) {
            const selectedOption = productRefSelect.querySelector(`option[value="${currentValue}"]`);
            if (selectedOption && selectedOption.style.display === 'none') {
                productRefSelect.value = '';
                handleCharReferenceSelection('');
            }
        }
    }
}

// Populate From/To reference dropdowns with references for selected line
function populateCharLineReferences(line) {
    const productRefSelect = document.getElementById('char_reference_number');
    const fromRefSelect = document.getElementById('char_from_reference');
    const toRefSelect = document.getElementById('char_to_reference');
    
    if (!productRefSelect || !fromRefSelect || !toRefSelect) return;
    
    // Clear existing options
    fromRefSelect.innerHTML = '<option value="">-- Select From Reference --</option>';
    toRefSelect.innerHTML = '<option value="">-- Select To Reference --</option>';
    
    // Collect all references for the selected line
    const lineReferences = [];
    Array.from(productRefSelect.options).forEach(option => {
        if (option.value && option.value !== '') {
            const optionLine = option.getAttribute('data-line') || '';
            if (optionLine === line) {
                const isBundle = option.getAttribute('data-is-bundle') === 'true';
                lineReferences.push({
                    value: option.value,
                    text: option.textContent,
                    isBundle: isBundle,
                    baseRef: option.getAttribute('data-base-ref') || '',
                    rollCount: parseInt(option.getAttribute('data-roll-count')) || 1
                });
            }
        }
    });
    
    // Sort references by date (extract date from reference number)
    // Reference format: GSM + L + Line# + YY + MMMDD + -R + Roll# + - + Batch
    function extractDateFromReference(ref) {
        const monthAbbr = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
        for (let i = 0; i < monthAbbr.length; i++) {
            const month = monthAbbr[i];
            const pattern = new RegExp(month + '(\\d{2})');
            const match = ref.match(pattern);
            if (match) {
                const day = parseInt(match[1]);
                return ((i + 1) * 100) + day;
            }
        }
        return 9999;
    }
    
    // Sort by date first, then alphabetically for same date
    lineReferences.sort((a, b) => {
        const dateA = extractDateFromReference(a.value);
        const dateB = extractDateFromReference(b.value);
        if (dateA !== dateB) {
            return dateA - dateB;
        }
        return a.value.localeCompare(b.value);
    });
    
    // Populate both dropdowns
    lineReferences.forEach(ref => {
        // Check if this reference is part of a bundle (ends with -N pattern)
        const isPartOfBundle = /-\d+$/.test(ref.value);
        const displayText = isPartOfBundle ? ref.text + ' (Bundle)' : ref.text;
        
        const fromOption = document.createElement('option');
        fromOption.value = ref.value;
        fromOption.textContent = displayText;
        fromOption.setAttribute('data-is-bundle', ref.isBundle);
        fromOption.setAttribute('data-base-ref', ref.baseRef);
        fromOption.setAttribute('data-roll-count', ref.rollCount);
        fromRefSelect.appendChild(fromOption);
        
        const toOption = document.createElement('option');
        toOption.value = ref.value;
        toOption.textContent = displayText;
        toOption.setAttribute('data-is-bundle', ref.isBundle);
        toOption.setAttribute('data-base-ref', ref.baseRef);
        toOption.setAttribute('data-roll-count', ref.rollCount);
        toRefSelect.appendChild(toOption);
    });
}

// Update To Reference dropdown based on From Reference selection
function updateCharReferenceRange(autoSelect = true) {
    const fromRefSelect = document.getElementById('char_from_reference');
    const toRefSelect = document.getElementById('char_to_reference');
    
    if (!fromRefSelect || !toRefSelect) return;
    
    const fromValue = fromRefSelect.value;
    if (!fromValue) {
        const allOptions = Array.from(toRefSelect.options);
        allOptions.forEach(option => {
            option.style.display = '';
        });
        return;
    }
    
    // Get the selected From reference option
    const fromOption = fromRefSelect.options[fromRefSelect.selectedIndex];
    const isBundle = fromOption?.getAttribute('data-is-bundle') === 'true';
    let baseRef = fromOption?.getAttribute('data-base-ref') || '';
    let rollCount = parseInt(fromOption?.getAttribute('data-roll-count')) || 1;
    
    // Extract base reference from the selected value if not provided
    if (!baseRef) {
        const rollMatch = fromValue.match(/^(.+)-(\d+)$/);
        if (rollMatch) {
            baseRef = rollMatch[1];
        } else {
            baseRef = fromValue;
        }
    }
    
    // Find the bundle reference to get the actual roll count
    if (baseRef) {
        Array.from(fromRefSelect.options).forEach(option => {
            if (option.value && option.value !== '') {
                const optionBaseRef = option.getAttribute('data-base-ref') || option.value.replace(/-\d+$/, '');
                const optionIsBundle = option.getAttribute('data-is-bundle') === 'true';
                if (optionIsBundle && optionBaseRef === baseRef) {
                    rollCount = parseInt(option.getAttribute('data-roll-count')) || rollCount;
                }
            }
        });
    }
    
    // Find the index of the selected From reference in the To dropdown
    let fromIndex = -1;
    Array.from(toRefSelect.options).forEach((option, index) => {
        if (option.value === fromValue) {
            fromIndex = index;
        }
    });
    
    // If From reference is a bundle, we need to find the last roll of that bundle
    let targetFromIndex = fromIndex;
    if (isBundle && baseRef && rollCount > 1) {
        const lastRollRef = baseRef + '-' + rollCount;
        
        let foundLastRoll = false;
        Array.from(toRefSelect.options).forEach((option, index) => {
            if (option.value === lastRollRef) {
                targetFromIndex = index;
                foundLastRoll = true;
            }
        });
        
        if (!foundLastRoll) {
            Array.from(toRefSelect.options).forEach((option, index) => {
                if (index > fromIndex && option.value) {
                    const optionBaseRef = option.getAttribute('data-base-ref') || option.value.replace(/-\d+$/, '');
                    if (optionBaseRef !== baseRef) {
                        if (targetFromIndex === fromIndex) {
                            targetFromIndex = index;
                        }
                    }
                }
            });
            
            if (targetFromIndex === fromIndex) {
                targetFromIndex = fromIndex + 1;
            }
        }
    }
    
    // Show only references from the target From reference onwards
    Array.from(toRefSelect.options).forEach((option, index) => {
        if (index === 0) {
            option.style.display = '';
        } else if (index >= targetFromIndex) {
            option.style.display = '';
        } else {
            option.style.display = 'none';
        }
    });
    
    // Auto-select the last roll of the bundle in To dropdown
    if (autoSelect) {
        let actualBaseRef = baseRef;
        let actualRollCount = rollCount;
        
        // Extract base reference from the selected value
        const rollMatch = fromValue.match(/^(.+)-(\d+)$/);
        if (rollMatch) {
            actualBaseRef = rollMatch[1];
            const selectedRollNum = parseInt(rollMatch[2]);
            
            // If first roll (-1) is selected, find the highest roll number for this base reference
            if (selectedRollNum === 1) {
                // Find all rolls for this base reference in the To dropdown
                let highestRoll = 0;
                let highestRollRef = '';
                
                Array.from(toRefSelect.options).forEach(option => {
                    if (option.value && option.value !== '') {
                        const optionRollMatch = option.value.match(/^(.+)-(\d+)$/);
                        if (optionRollMatch) {
                            const optionBaseRef = optionRollMatch[1];
                            const optionRollNum = parseInt(optionRollMatch[2]);
                            
                            // If it's from the same base reference
                            if (optionBaseRef === actualBaseRef && optionRollNum > highestRoll) {
                                highestRoll = optionRollNum;
                                highestRollRef = option.value;
                            }
                        }
                    }
                });
                
                // Auto-select the highest roll found
                if (highestRollRef && highestRoll > 1) {
                    toRefSelect.value = highestRollRef;
                    return; // Exit early since we've found and selected the last roll
                }
            }
        }
        
        // Fallback to original logic for other cases
        if (!actualBaseRef) {
            if (rollMatch) {
                actualBaseRef = rollMatch[1];
            } else {
                actualBaseRef = fromValue;
            }
        }
        
        if (actualBaseRef) {
            Array.from(fromRefSelect.options).forEach(option => {
                if (option.value && option.value !== '') {
                    const optionBaseRef = option.getAttribute('data-base-ref') || option.value.replace(/-\d+$/, '');
                    const optionIsBundle = option.getAttribute('data-is-bundle') === 'true';
                    if (optionIsBundle && optionBaseRef === actualBaseRef) {
                        actualRollCount = parseInt(option.getAttribute('data-roll-count')) || actualRollCount;
                    }
                }
            });
        }
        
        if (actualBaseRef && actualRollCount > 1) {
            const lastRollRef = actualBaseRef + '-' + actualRollCount;
            let found = false;
            Array.from(toRefSelect.options).forEach(option => {
                if (option.value === lastRollRef && option.style.display !== 'none') {
                    toRefSelect.value = lastRollRef;
                    found = true;
                    return;
                }
            });
            
            // If exact match not found, find the highest roll number from this bundle that's visible
            if (!found) {
                let highestRoll = 0;
                let highestRollRef = '';
                Array.from(toRefSelect.options).forEach(option => {
                    if (option.style.display !== 'none' && option.value) {
                        const optionBaseRef = option.getAttribute('data-base-ref') || option.value.replace(/-\d+$/, '');
                        if (optionBaseRef === actualBaseRef) {
                            const rollMatch = option.value.match(/-(\d+)$/);
                            if (rollMatch) {
                                const rollNum = parseInt(rollMatch[1]);
                                if (rollNum > highestRoll && rollNum <= actualRollCount) {
                                    highestRoll = rollNum;
                                    highestRollRef = option.value;
                                }
                            }
                        }
                    }
                });
                if (highestRollRef) {
                    toRefSelect.value = highestRollRef;
                }
            }
        } else if (fromIndex >= 0) {
            // Not a bundle, just select the same reference
            toRefSelect.value = fromValue;
        }
    }
}

// Apply bulk reference selection
function applyCharBulkReferenceSelection() {
    const fromRef = document.getElementById('char_from_reference').value;
    const toRef = document.getElementById('char_to_reference').value;
    
    if (!fromRef || !toRef) {
        alert('Please select both From and To references');
        return;
    }
    
    // Store the range in hidden input
    const lineBasedProductRef = document.getElementById('char_line_based_product_reference');
    if (lineBasedProductRef) {
        lineBasedProductRef.value = fromRef + ' to ' + toRef;
    }
    
    // Update the main reference dropdown to show the range
    const productRefSelect = document.getElementById('char_reference_number');
    if (productRefSelect) {
        // Find or create an option for the range
        let rangeOption = Array.from(productRefSelect.options).find(opt => opt.value === fromRef + '|' + toRef);
        if (!rangeOption) {
            rangeOption = document.createElement('option');
            rangeOption.value = fromRef + '|' + toRef;
            rangeOption.textContent = fromRef + ' to ' + toRef;
            productRefSelect.appendChild(rangeOption);
        }
        productRefSelect.value = rangeOption.value;
    }
    
    // Check which references in the range have been submitted
    checkCharReferenceTestStatus(fromRef, toRef);
}

// Check which references in range have been submitted for Characteristics Test
function checkCharReferenceTestStatus(fromRef, toRef) {
    const container = document.getElementById('char_reference_test_status_container');
    const statusList = document.getElementById('char_reference_test_status_list');
    const statusTitle = document.getElementById('char_reference_status_title');
    const statusSummary = document.getElementById('char_reference_status_summary');
    
    if (!container || !statusList) {
        console.error('Reference status container elements not found!');
        return;
    }
    
    // Show container with loading state
    container.style.display = 'block';
    if (statusTitle) statusTitle.textContent = 'Test Status';
    statusList.innerHTML = '<div style="padding:20px; text-align:center; color:#666;"><i class="fas fa-spinner fa-spin" style="font-size:18px;"></i><div style="margin-top:8px; font-size:12px;">Loading...</div></div>';
    if (statusSummary) statusSummary.innerHTML = '';
    
    // Fetch submitted references
    fetch(`api/check_submitted_tests_range_char.php?from_reference=${encodeURIComponent(fromRef)}&to_reference=${encodeURIComponent(toRef)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayCharReferenceStatus(data, fromRef, toRef);
            } else {
                statusList.innerHTML = `<div style="padding:12px; text-align:center; color:#dc3545; font-size:12px;"><i class="fas fa-exclamation-triangle"></i> ${data.error || 'Unknown error'}</div>`;
            }
        })
        .catch(error => {
            console.error('Error checking reference status:', error);
            statusList.innerHTML = `<div style="padding:12px; text-align:center; color:#dc3545; font-size:12px;"><i class="fas fa-exclamation-triangle"></i> Error loading status</div>`;
        });
}

// Display reference status with modern UI
function displayCharReferenceStatus(data, fromRef, toRef) {
    const statusList = document.getElementById('char_reference_test_status_list');
    const statusSummary = document.getElementById('char_reference_status_summary');
    
    if (!statusList) return;
    
    const submittedRefs = data.submitted_references || [];
    const submittedRefSet = new Set(submittedRefs.map(r => r.reference));
    
    // Get all references in range
    const fromSelect = document.getElementById('char_from_reference');
    const toSelect = document.getElementById('char_to_reference');
    const allRefs = [];
    
    if (fromSelect && toSelect) {
        const fromIndex = Array.from(fromSelect.options).findIndex(opt => opt.value === fromRef);
        const toIndex = Array.from(toSelect.options).findIndex(opt => opt.value === toRef);
        
        if (fromIndex !== -1 && toIndex !== -1) {
            for (let i = fromIndex; i <= toIndex; i++) {
                const opt = fromSelect.options[i];
                if (opt && opt.value) {
                    allRefs.push(opt.value);
                }
            }
        }
    }
    
    // If we couldn't get refs from dropdown, use submitted refs to infer
    if (allRefs.length === 0) {
        // Extract base and generate range
        const fromMatch = fromRef.match(/^(.+?)-(\d+)$/);
        const toMatch = toRef.match(/^(.+?)-(\d+)$/);
        if (fromMatch && toMatch && fromMatch[1] === toMatch[1]) {
            const base = fromMatch[1];
            const fromNum = parseInt(fromMatch[2]);
            const toNum = parseInt(toMatch[2]);
            for (let i = fromNum; i <= toNum; i++) {
                allRefs.push(base + '-' + i);
            }
        } else {
            allRefs.push(fromRef, toRef);
        }
    }
    
    const pendingRefs = allRefs.filter(ref => !submittedRefSet.has(ref));
    const submittedCount = submittedRefs.length;
    const pendingCount = pendingRefs.length;
    
    // Update summary
    if (statusSummary) {
        statusSummary.innerHTML = `${allRefs.length} refs | ${submittedCount} done | ${pendingCount} pending`;
    }
    
    // Build HTML - compact modern design
    let html = '';
    
    if (submittedCount > 0) {
        html += `
            <div style="margin-bottom:12px;">
                <div style="display:flex; align-items:center; gap:6px; margin-bottom:8px; padding:6px 10px; background:#e8f5e9; border-radius:6px;">
                    <i class="fas fa-check-circle" style="color:#4caf50; font-size:14px;"></i>
                    <span style="color:#2e7d32; font-size:12px; font-weight:600;">Submitted (${submittedCount})</span>
                </div>
                <div style="display:flex; flex-wrap:wrap; gap:6px;">
        `;
        
        submittedRefs.forEach(ref => {
            const statusBadge = ref.status === 'approved' ? '<span style="background:#4caf50; color:white; padding:1px 6px; border-radius:3px; font-size:10px; font-weight:500;">✓</span>' :
                           ref.status === 'checked' ? '<span style="background:#2196f3; color:white; padding:1px 6px; border-radius:3px; font-size:10px; font-weight:500;">✓</span>' :
                           '<span style="background:#ff9800; color:white; padding:1px 6px; border-radius:3px; font-size:10px; font-weight:500;">⏳</span>';
            html += `
                <div style="padding:6px 10px; background:#f8f9fa; border:1px solid #e0e0e0; border-radius:5px; display:flex; align-items:center; gap:6px; font-size:11px;">
                    <span style="color:#333; font-weight:500;">${ref.reference}</span>
                    ${statusBadge}
                </div>
            `;
        });
        
        html += `</div></div>`;
    }
    
    if (pendingCount > 0) {
        html += `
            <div>
                <div style="display:flex; align-items:center; gap:6px; margin-bottom:8px; padding:6px 10px; background:#fff3e0; border-radius:6px;">
                    <i class="fas fa-clock" style="color:#ff9800; font-size:14px;"></i>
                    <span style="color:#e65100; font-size:12px; font-weight:600;">Pending (${pendingCount})</span>
                </div>
                <div style="display:flex; flex-wrap:wrap; gap:6px;">
        `;
        
        pendingRefs.forEach(ref => {
            html += `
                <div style="padding:6px 10px; background:#fff8e1; border:1px solid #ffcc80; border-radius:5px; display:flex; align-items:center; gap:6px; font-size:11px;">
                    <span style="color:#333; font-weight:500;">${ref}</span>
                    <span style="background:#ff9800; color:white; padding:1px 6px; border-radius:3px; font-size:10px; font-weight:500;">→</span>
                </div>
            `;
        });
        
        html += `</div></div>`;
    }
    
    if (submittedCount === 0 && pendingCount === 0) {
        html = `
            <div style="padding:20px; text-align:center; color:#999;">
                <i class="fas fa-info-circle" style="font-size:20px; color:#2196F3; margin-bottom:8px;"></i>
                <div style="font-size:12px; color:#666;">No references found in range</div>
            </div>
        `;
    }
    
    statusList.innerHTML = html;
}

// Clear bulk reference selection
function clearCharBulkReferenceSelection() {
    const fromRefSelect = document.getElementById('char_from_reference');
    const toRefSelect = document.getElementById('char_to_reference');
    const lineBasedProductRef = document.getElementById('char_line_based_product_reference');
    
    if (fromRefSelect) fromRefSelect.value = '';
    if (toRefSelect) toRefSelect.value = '';
    if (lineBasedProductRef) lineBasedProductRef.value = '';
    
    // Reset To dropdown to show all options
    if (toRefSelect) {
        Array.from(toRefSelect.options).forEach(option => {
            option.style.display = '';
        });
    }
}

// Handle From/To reference change
function handleCharFromToReferenceChange() {
    // This function can be extended to perform validation or other actions
    // when From/To references change
}

// Handle individual roll selection from bundle
function handleCharIndividualRollSelection(selectedValue) {
    if (selectedValue && selectedValue !== '') {
        // Check if this is from a bundle and get first test data to pre-fill
        const productRefSelect = document.getElementById('char_reference_number');
        const selectedOption = productRefSelect.options[productRefSelect.selectedIndex];
        const isBundle = selectedOption?.getAttribute('data-is-bundle') === 'true';
        const bundleRef = selectedOption?.value || '';
        
        if (isBundle && bundleRef) {
            // Fetch first test data from bundle to pre-fill form
            fetch(`api/get_first_bundle_test_data_char.php?bundle_ref=${encodeURIComponent(bundleRef)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        // Pre-fill form with first test data
                        const testMaterialsField = document.querySelector('input[name="test_materials"]');
                        if (testMaterialsField && data.data.test_materials !== undefined && data.data.test_materials !== null) {
                            testMaterialsField.value = data.data.test_materials;
                        }
                        
                        const gsmField = document.querySelector('input[name="gsm"]');
                        if (gsmField && data.data.gsm !== undefined && data.data.gsm !== null) {
                            gsmField.value = data.data.gsm;
                        }
                        
                        const rollNumberField = document.querySelector('input[name="roll_number"]');
                        if (rollNumberField && data.data.roll_number !== undefined && data.data.roll_number !== null) {
                            rollNumberField.value = data.data.roll_number;
                        }
                        
                        const specimenSizeField = document.querySelector('input[name="specimen_size"]');
                        if (specimenSizeField && data.data.specimen_size !== undefined && data.data.specimen_size !== null) {
                            specimenSizeField.value = data.data.specimen_size;
                        }
                        
                        const specimenSizeUnitField = document.querySelector('select[name="specimen_size_unit"]');
                        if (specimenSizeUnitField && data.data.specimen_size_unit !== undefined && data.data.specimen_size_unit !== null && data.data.specimen_size_unit !== '') {
                            specimenSizeUnitField.value = data.data.specimen_size_unit;
                        }
                        
                        const sandTypeField = document.querySelector('input[name="sand_type"]');
                        if (sandTypeField && data.data.sand_type !== undefined && data.data.sand_type !== null) {
                            sandTypeField.value = data.data.sand_type;
                        }
                        
                        const sandWeightField = document.querySelector('input[name="sand_weight"]');
                        if (sandWeightField && data.data.sand_weight !== undefined && data.data.sand_weight !== null) {
                            sandWeightField.value = data.data.sand_weight;
                        }
                        
                        const sandWeightUnitField = document.querySelector('select[name="sand_weight_unit"]');
                        if (sandWeightUnitField && data.data.sand_weight_unit !== undefined && data.data.sand_weight_unit !== null && data.data.sand_weight_unit !== '') {
                            sandWeightUnitField.value = data.data.sand_weight_unit;
                        }
                        
                        const sievingTimeField = document.querySelector('input[name="sieving_time"]');
                        if (sievingTimeField && data.data.sieving_time !== undefined && data.data.sieving_time !== null) {
                            sievingTimeField.value = data.data.sieving_time;
                        }
                        
                        const sievingTimeUnitField = document.querySelector('select[name="sieving_time_unit"]');
                        if (sievingTimeUnitField && data.data.sieving_time_unit !== undefined && data.data.sieving_time_unit !== null && data.data.sieving_time_unit !== '') {
                            sievingTimeUnitField.value = data.data.sieving_time_unit;
                        }
                        
                        // Pre-fill sieve table data if available
                        if (data.data.sieve_data && Array.isArray(data.data.sieve_data) && data.data.sieve_data.length > 0) {
                            const tbody = document.getElementById('sieve_tbody');
                            if (tbody) {
                                // Clear existing rows
                                tbody.innerHTML = '';
                                
                                // Update sieveRowCounter to match the number of rows we'll add
                                sieveRowCounter = data.data.sieve_data.length;
                                
                                // Add rows for each sieve data entry
                                data.data.sieve_data.forEach((sieveItem, index) => {
                                    const rowNum = index + 1;
                                    const row = document.createElement('tr');
                                    row.innerHTML = `
                                        <td><input type="number" name="sieve_size_${rowNum}" id="sieve_size_${rowNum}" step="0.0001" min="0" value="${sieveItem.sieve_size || ''}"></td>
                                        <td><input type="number" name="retained_${rowNum}" id="retained_${rowNum}" step="0.0001" min="0" oninput="calculateSieve()" value="${sieveItem.retained || ''}"></td>
                                        <td><input type="number" name="cumulative_${rowNum}" id="cumulative_${rowNum}" readonly class="readonly" value="${sieveItem.cumulative || ''}"></td>
                                        <td><input type="number" name="passing_${rowNum}" id="passing_${rowNum}" readonly class="readonly" value="${sieveItem.passing || ''}"></td>
                                        <td>${rowNum > 1 ? '<button type="button" onclick="removeSieveRow(this)" style="padding:4px 8px; background:#dc3545; color:#fff; border:none; cursor:pointer;">Delete</button>' : ''}</td>
                                    `;
                                    tbody.appendChild(row);
                                });
                                
                                // Recalculate sieve after filling data (this will update cumulative and passing based on retained values)
                                // Use setTimeout to ensure all fields are set before calculation
                                setTimeout(function() {
                                    calculateSieve();
                                }, 100);
                            }
                        }
                    }
                    generateReportID();
                })
                .catch(error => {
                    console.error('Error fetching bundle test data:', error);
                    generateReportID();
                });
        } else {
            generateReportID();
        }
    }
}

// Add sieve row
let sieveRowCounter = 4;
function addSieveRow() {
    const tbody = document.getElementById('sieve_tbody');
    sieveRowCounter++;
    const row = document.createElement('tr');
    row.innerHTML = `
        <td><input type="number" name="sieve_size_${sieveRowCounter}" id="sieve_size_${sieveRowCounter}" step="0.0001" min="0"></td>
        <td><input type="number" name="retained_${sieveRowCounter}" id="retained_${sieveRowCounter}" step="0.0001" min="0" oninput="calculateSieve()"></td>
        <td><input type="number" name="cumulative_${sieveRowCounter}" id="cumulative_${sieveRowCounter}" readonly class="readonly"></td>
        <td><input type="number" name="passing_${sieveRowCounter}" id="passing_${sieveRowCounter}" readonly class="readonly"></td>
        <td><button type="button" onclick="removeSieveRow(this)" style="padding:4px 8px; background:#dc3545; color:#fff; border:none; cursor:pointer;">Delete</button></td>
    `;
    tbody.appendChild(row);
}

// Remove sieve row
function removeSieveRow(button) {
    if (confirm('Are you sure you want to delete this row?')) {
        button.closest('tr').remove();
        calculateSieve();
    }
}

// Calculate sieve analysis
function calculateSieve() {
  const totalSand = parseFloat(document.getElementById('sand_weight').value) || 0;
  let cumulative = 0;
  
  // Get all sieve rows dynamically
  const tbody = document.getElementById('sieve_tbody');
  const rows = tbody.querySelectorAll('tr');
  
  rows.forEach((row, index) => {
    const rowNum = index + 1;
    const retainedEl = document.getElementById(`retained_${rowNum}`);
    const cumulativeEl = document.getElementById(`cumulative_${rowNum}`);
    const passingEl = document.getElementById(`passing_${rowNum}`);
    
    if (retainedEl && cumulativeEl && passingEl) {
      const retained = parseFloat(retainedEl.value) || 0;
      cumulative += retained;
      
      cumulativeEl.value = cumulative.toFixed(1);
      
      if (totalSand > 0) {
        // Cumulative Passing = 100 - (Cumulative / Total) * 100
        const passing = 100 - ((cumulative / totalSand) * 100);
        passingEl.value = passing.toFixed(1);
      } else {
        passingEl.value = '';
      }
    }
  });
}

// Calculate opening size based on O-value
function calculateOpening() {
  const oValueInput = document.getElementById('o_value_input');
  if (!oValueInput) {
    alert('O-value input field not found');
    return;
  }
  
  const oValue = parseFloat(oValueInput.value);
  
  if (!oValue || oValue < 0 || oValue > 100) {
    alert('Please enter a valid O-value between 0 and 100');
    return;
  }
  
  // Find interpolated sieve size for the given O-value
  // Passing decreases as sieve size decreases, so we need upper (higher %) and lower (lower %)
  let upperSize = 0, lowerSize = 0, upperPass = 0, lowerPass = 0;
  let found = false;
  
  // First, find how many sieve rows exist
  const tbody = document.getElementById('sieve_tbody');
  if (!tbody) {
    alert('Sieve table not found');
    return;
  }
  
  const rows = tbody.querySelectorAll('tr');
  const maxRows = rows.length;
  
  for (let i = 1; i <= maxRows; i++) {
    const passingEl = document.getElementById('passing_' + i);
    const sieveSizeEl = document.getElementById('sieve_size_' + i);
    
    if (!passingEl || !sieveSizeEl) continue;
    
    const passing = parseFloat(passingEl.value) || 0;
    const sieveSize = parseFloat(sieveSizeEl.value) || 0;
    
    if (sieveSize <= 0) continue; // Skip empty rows
    
    if (passing <= oValue) {
      // Found the lower bound (passing just below O-value)
      lowerSize = sieveSize;
      lowerPass = passing;
      
      // Get upper bound from previous row (passing just above O-value)
      if (i > 1) {
        const prevSieveSizeEl = document.getElementById('sieve_size_' + (i-1));
        const prevPassingEl = document.getElementById('passing_' + (i-1));
        if (prevSieveSizeEl && prevPassingEl) {
          upperSize = parseFloat(prevSieveSizeEl.value) || 0;
          upperPass = parseFloat(prevPassingEl.value) || 0;
          found = true;
        }
      } else {
        // O-value is higher than the first sieve's passing %
        // Use the first sieve size as the result
        upperSize = sieveSize;
        upperPass = passing;
        found = true;
      }
      break;
    }
  }
  
  // If O-value is lower than all sieves, use the last sieve
  if (!found && lowerSize === 0) {
    for (let i = maxRows; i >= 1; i--) {
      const sieveSizeEl = document.getElementById('sieve_size_' + i);
      const passingEl = document.getElementById('passing_' + i);
      if (sieveSizeEl && passingEl) {
        const sieveSize = parseFloat(sieveSizeEl.value) || 0;
        const passing = parseFloat(passingEl.value) || 0;
        if (sieveSize > 0) {
          lowerSize = sieveSize;
          lowerPass = passing;
          found = true;
          break;
        }
      }
    }
  }
  
  let openingSize = 0;
  let calculationHTML = '';
  
  if (upperPass > 0 && lowerPass >= 0 && upperSize > 0 && lowerSize > 0 && upperPass > lowerPass) {
    // Linear interpolation: O = lowerSize + (oValue - lowerPass)/(upperPass - lowerPass) * (upperSize - lowerSize)
    openingSize = lowerSize + ((oValue - lowerPass) / (upperPass - lowerPass)) * (upperSize - lowerSize);
    
    // Build calculation text
    calculationHTML = `
      O${oValue} lies between sieve size ${upperSize} mm (${upperPass.toFixed(1)}% passing) and ${lowerSize} mm (${lowerPass.toFixed(1)}% passing)<br><br>
      <strong>Interpolation formula:</strong><br>
      O${oValue} = ${lowerSize} + ((${oValue} - ${lowerPass.toFixed(1)}) / (${upperPass.toFixed(1)} - ${lowerPass.toFixed(1)})) × (${upperSize} - ${lowerSize})<br><br>
      O${oValue} = ${openingSize.toFixed(3)} mm (${(openingSize * 1000).toFixed(0)} μm)
    `;
  } else if (upperSize > 0 && lowerSize === 0) {
    // O-value is higher than all sieves
    openingSize = upperSize;
    calculationHTML = `O${oValue} is higher than all sieve passing percentages.<br>Using largest sieve size: ${openingSize.toFixed(3)} mm (${(openingSize * 1000).toFixed(0)} μm)`;
  } else if (lowerSize > 0 && upperSize === 0) {
    // O-value is lower than all sieves
    openingSize = lowerSize;
    calculationHTML = `O${oValue} is lower than all sieve passing percentages.<br>Using smallest sieve size: ${openingSize.toFixed(3)} mm (${(openingSize * 1000).toFixed(0)} μm)`;
  } else if (upperSize > 0) {
    openingSize = upperSize;
    calculationHTML = `O${oValue} = ${openingSize.toFixed(3)} mm (${(openingSize * 1000).toFixed(0)} μm)`;
  } else if (lowerSize > 0) {
    openingSize = lowerSize;
    calculationHTML = `O${oValue} = ${openingSize.toFixed(3)} mm (${(openingSize * 1000).toFixed(0)} μm)`;
  }
  
  const openingSizeEl = document.getElementById('opening_size');
  const calculationTextEl = document.getElementById('calculation_text');
  const calculationDisplayEl = document.getElementById('calculation_display');
  const finalResultEl = document.getElementById('final_result');
  const resultDisplayEl = document.getElementById('result_display');
  
  if (!openingSizeEl || !calculationTextEl || !calculationDisplayEl || !finalResultEl || !resultDisplayEl) {
    alert('Required display elements not found. Please refresh the page.');
    return;
  }
  
  openingSizeEl.value = openingSize.toFixed(4);
  calculationTextEl.innerHTML = calculationHTML;
  calculationDisplayEl.style.display = 'block';
  
  finalResultEl.innerHTML = 
    `Apparent Opening Size (O${oValue}): ${openingSize.toFixed(3)} mm (${(openingSize * 1000).toFixed(0)} μm)`;
  resultDisplayEl.style.display = 'block';
}

// Admin rejection modal functions
function openCTRejectModal(reportId) {
    document.getElementById('ctRejectReportId').value = reportId;
    document.getElementById('ctRejectionModal').style.display = 'block';
}

function closeCTRejectModal() {
    document.getElementById('ctRejectionModal').style.display = 'none';
    document.getElementById('ctAdminRejectForm').reset();
}

function submitCTAdminRejection() {
    const checkboxes = document.querySelectorAll('input[name="ct_rejection_reasons[]"]');
    const checked = Array.from(checkboxes).filter(cb => cb.checked);
    
    if (checked.length === 0) {
        alert('❌ Please select at least one reason for rejection!');
        return false;
    }
    
    if (confirm('Are you sure you want to reject this report?')) {
        const form = document.getElementById('ctAdminRejectForm');
        form.onsubmit = null; // Remove the return false
        form.submit();
    }
}
</script>

<!-- Rejection Modal for Admin (Characteristics Test) -->
<div id="ctRejectionModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow-y:auto;">
  <div style="max-width:600px; margin:50px auto; background:#fff; border-radius:8px; padding:25px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
    <h3 style="margin-top:0; color:#dc3545; border-bottom:2px solid #dc3545; padding-bottom:10px;">
      ❌ Reject Characteristics Test Report
    </h3>
    
    <form id="ctAdminRejectForm" method="POST" action="" onsubmit="return false;">
      <input type="hidden" id="ctRejectReportId" name="ct_report_id" value="">
      <input type="hidden" name="ct_action" value="reject">
      
      <label style="font-weight:600; display:block; margin-bottom:10px;">Reason for Rejection (Select at least one):</label>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="ct_rejection_reasons[]" value="Incorrect Roll Identification" style="margin-right:8px;">
          Incorrect Roll Identification
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="ct_rejection_reasons[]" value="Incorrect Fiber Specification Entry" style="margin-right:8px;">
          Incorrect Fiber Specification Entry
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="ct_rejection_reasons[]" value="Excessive Sampling" style="margin-right:8px;">
          Excessive Sampling
        </label>
      </div>
      
      <label style="font-weight:bold; display:block; margin:15px 0 8px 0;">
        Additional Comments (Optional):
      </label>
      <textarea name="ct_reject_comment" id="ctAdminRejectComment" rows="4" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-family:inherit;" placeholder="Provide additional details..."></textarea>
      
      <div style="margin-top:20px; text-align:right;">
        <button type="button" onclick="closeCTRejectModal()" style="padding:10px 20px; margin-right:10px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer;">Cancel</button>
        <button type="button" onclick="submitCTAdminRejection()" style="padding:10px 20px; background:#dc3545; color:#fff; border:none; border-radius:6px; cursor:pointer;">Submit Rejection</button>
      </div>
    </form>
  </div>
</div>

</body>
</html>
<?php $conn->close(); ?>


