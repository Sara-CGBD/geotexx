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

// Use full_name from session (set during login) - this ensures correct user from auto login
$reporter_full_name = $_SESSION['full_name'] ?? $_SESSION['username'];

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
                
                $_SESSION['success_message'] = "Test approved successfully!";
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
            // Get reference number - validate and ensure full reference is stored
            'reference_number' => (function() use ($conn) {
                $ref = '';
                
                // Debug: Log all POST data related to references
                error_log("WPT Reference Debug - individual_roll_reference: " . ($_POST['individual_roll_reference'] ?? 'NOT SET'));
                error_log("WPT Reference Debug - from_reference: " . ($_POST['from_reference'] ?? 'NOT SET'));
                error_log("WPT Reference Debug - to_reference: " . ($_POST['to_reference'] ?? 'NOT SET'));
                error_log("WPT Reference Debug - reference_number: " . ($_POST['reference_number'] ?? 'NOT SET'));
                
                // Priority 1: Individual roll reference (from bundle selection)
                if (isset($_POST['individual_roll_reference']) && !empty($_POST['individual_roll_reference'])) {
                    $ref = trim($_POST['individual_roll_reference']);
                    error_log("WPT Reference: Using individual_roll_reference: " . $ref);
                } 
                // Priority 2: From/To reference (line-based selection) - only use if both are set and not empty
                elseif (isset($_POST['from_reference']) && isset($_POST['to_reference']) && 
                          !empty(trim($_POST['from_reference'])) && !empty(trim($_POST['to_reference']))) {
                    $fromRef = trim($_POST['from_reference']);
                    $toRef = trim($_POST['to_reference']);
                    error_log("WPT Reference: from_reference = " . $fromRef . ", to_reference = " . $toRef);
                    
                    // Validate that from_reference is a valid full reference
                    if (strlen($fromRef) > 3 && preg_match('/[A-Za-z]/', $fromRef)) {
                        $ref = $fromRef;
                        error_log("WPT Reference: Using valid from_reference: " . $ref);
                    } else {
                        // Invalid from_reference - will be handled by validation below
                        $ref = $fromRef;
                        error_log("WPT Reference: Invalid from_reference detected: " . $ref);
                    }
                } 
                // Priority 3: Regular reference_number field (All Lines selection)
                elseif (isset($_POST['reference_number']) && !empty($_POST['reference_number'])) {
                    $ref = trim($_POST['reference_number']);
                    error_log("WPT Reference: Using reference_number: " . $ref);
                    // Check if this is a range format (from|to)
                    if (strpos($ref, '|') !== false) {
                        $parts = explode('|', $ref);
                        if (count($parts) === 2) {
                            $ref = trim($parts[0]); // Extract from reference if range format
                            error_log("WPT Reference: Extracted from range: " . $ref);
                        }
                    }
                }
                
                error_log("WPT Reference: Final reference before validation: " . $ref);
                
                // Validate reference format - must contain letters/numbers and be more than 3 chars (typical format like "2.0L126JAN16-R06")
                if (!empty($ref)) {
                    $refTrimmed = trim($ref);
                    // If reference is too short or doesn't look like a valid reference, try to find full reference from roll_entry
                    if (strlen($refTrimmed) <= 3 || !preg_match('/[A-Za-z]/', $refTrimmed)) {
                        // Try to find a matching reference in roll_entry
                        // For single digit like "2", look for references starting with "2." 
                        $lookupStmt = null;
                        if (strlen($refTrimmed) <= 3 && preg_match('/^\d+$/', $refTrimmed)) {
                            // Single number - look for references starting with this number followed by a dot (e.g., "2.0L...")
                            $lookupStmt = $conn->prepare("SELECT reference_number FROM roll_entry 
                                WHERE reference_number LIKE ?
                                ORDER BY date_time DESC 
                                LIMIT 1");
                            $pattern1 = $refTrimmed . '.%';
                            $lookupStmt->bind_param("s", $pattern1);
                        } else {
                            // Other patterns - look for references containing the partial reference
                            $lookupStmt = $conn->prepare("SELECT reference_number FROM roll_entry 
                                WHERE reference_number LIKE ? 
                                   OR reference_number LIKE ? 
                                   OR reference_number LIKE ?
                                ORDER BY date_time DESC 
                                LIMIT 1");
                            $likePattern1 = '%' . $refTrimmed . '%';
                            $likePattern2 = $refTrimmed . '-%';
                            $likePattern3 = $refTrimmed . '%';
                            $lookupStmt->bind_param("sss", $likePattern1, $likePattern2, $likePattern3);
                        }
                        
                        if ($lookupStmt) {
                            $lookupStmt->execute();
                            $lookupResult = $lookupStmt->get_result();
                            if ($lookupRow = $lookupResult->fetch_assoc()) {
                                $ref = $lookupRow['reference_number'];
                            } else {
                                // If lookup fails and reference is too short, throw an error instead of saving "2"
                                if (strlen($refTrimmed) <= 3) {
                                    throw new Exception("Invalid reference: '{$refTrimmed}'. Could not find matching reference. Please select a valid full reference number from the dropdown.");
                                }
                            }
                            $lookupStmt->close();
                        }
                    }
                }
                
                // Final validation - ensure reference is not empty and has minimum length and contains letters
                $finalRef = trim($ref);
                if (empty($finalRef)) {
                    throw new Exception("Reference number is required. Please select a reference from the dropdown.");
                }
                if (strlen($finalRef) <= 3) {
                    throw new Exception("Reference number '{$finalRef}' is too short. Please select a valid full reference from the dropdown.");
                }
                if (!preg_match('/[A-Za-z]/', $finalRef)) {
                    throw new Exception("Reference number '{$finalRef}' is invalid (must contain letters). Please select a valid full reference from the dropdown.");
                }
                
                return $finalRef;
            })(),
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
        
        // Add shift and reference_number columns if they don't exist
        $columns_to_add = [
            ['name' => 'shift', 'definition' => 'VARCHAR(10) AFTER test_date'],
            ['name' => 'reference_number', 'definition' => 'VARCHAR(255)'], // Increased from 100 to 255 to ensure full reference fits
            ['name' => 'bundle_reference', 'definition' => 'VARCHAR(255) NULL AFTER reference_number'] // Increased from 100 to 255
        ];
        
        foreach ($columns_to_add as $col) {
            $check = $conn->query("SHOW COLUMNS FROM water_permeability_tests LIKE '{$col['name']}'");
            if ($check->num_rows == 0) {
                $conn->query("ALTER TABLE water_permeability_tests ADD COLUMN {$col['name']} {$col['definition']}");
                error_log("WPT: Added column {$col['name']} with definition: {$col['definition']}");
            } else {
                // Check if column exists but might be too small - alter it to ensure it's large enough
                if ($col['name'] === 'reference_number' || $col['name'] === 'bundle_reference') {
                    $colInfo = $conn->query("SHOW COLUMNS FROM water_permeability_tests WHERE Field = '{$col['name']}'");
                    if ($colInfo && $row = $colInfo->fetch_assoc()) {
                        // Check if it's VARCHAR and smaller than 255
                        if (preg_match('/varchar\((\d+)\)/i', $row['Type'], $matches)) {
                            $currentSize = (int)$matches[1];
                            if ($currentSize < 255) {
                                $conn->query("ALTER TABLE water_permeability_tests MODIFY COLUMN {$col['name']} VARCHAR(255)");
                                error_log("WPT: Updated column {$col['name']} size from {$currentSize} to 255");
                            }
                        }
                    }
                }
            }
        }
        
        // Get bundle reference if individual roll is selected
        // Or handle from/to reference selection
        // Generate individual roll references from from-to range (same as other tests)
        $bulk_rolls = [];
        if (isset($_POST['from_reference']) && isset($_POST['to_reference']) && 
            !empty(trim($_POST['from_reference'])) && !empty(trim($_POST['to_reference']))) {
            
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
                error_log("Water Test: Generated " . count($bulk_rolls) . " individual references from range: " . $fromRef . " to " . $toRef);
            } else {
                // Different bases - add both endpoints
                $bulk_rolls[] = $fromRef;
                if ($toRef !== $fromRef) {
                    $bulk_rolls[] = $toRef;
                }
                error_log("Water Test: WARNING - Different base references in range. Generated " . count($bulk_rolls) . " references.");
            }
        }
        
        $bundle_reference = null;
        if (isset($_POST['individual_roll_reference']) && !empty($_POST['individual_roll_reference']) 
            && isset($_POST['reference_number']) && !empty($_POST['reference_number'])) {
            $bundle_reference = trim($_POST['reference_number']); // Original bundle reference
        } elseif (!empty($bulk_rolls)) {
            // Range selected - bundle_reference will be set for all rolls
            $bundle_reference = trim($_POST['from_reference']) . '|' . trim($_POST['to_reference']);
        } elseif (isset($_POST['from_reference']) && isset($_POST['to_reference']) && 
                  !empty($_POST['from_reference']) && !empty($_POST['to_reference'])) {
            // Line-based selection: can store range info if needed in the future
            // For now, we use from_reference as the main reference
        }
        
        // Final safety check - absolutely prevent saving "2" or other invalid short references
        $finalReference = trim($data['reference_number']);
        
        // Log the reference before validation
        error_log("WPT Final Check - Reference to save: '" . $finalReference . "' (length: " . strlen($finalReference) . ")");
        
        if (empty($finalReference)) {
            throw new Exception("Cannot save test: Reference number is required. Please select a reference from the dropdown.");
        }
        if (strlen($finalReference) <= 3) {
            throw new Exception("Cannot save test: Reference number '" . $finalReference . "' is too short (length: " . strlen($finalReference) . "). Please select a valid full reference from the dropdown.");
        }
        if (!preg_match('/[A-Za-z]/', $finalReference)) {
            throw new Exception("Cannot save test: Reference number '{$finalReference}' is invalid (must contain letters). Please select a valid full reference from the dropdown.");
        }
        
        // Process each roll in the range (or single reference)
        $rolls_to_process = !empty($bulk_rolls) ? $bulk_rolls : [];
        if (empty($rolls_to_process) && !empty($finalReference)) {
            $rolls_to_process = [$finalReference];
        }
        
        if (empty($rolls_to_process)) {
            throw new Exception("No reference selected. Please select a reference or range.");
        }
        
        $stmt = $conn->prepare("INSERT INTO water_permeability_tests 
            (report_number, lab_test_number, reference_number, bundle_reference, gsm, roll_number, test_date, shift, test_results, 
             test_performed_by, checked_by, approved_by, checked_at, approved_at, 
             reporter_id, reporter_name, status, remarks) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $inserted_count = 0;
        $report_numbers = [];
        $base_report_no = $data['report_no'];
        $base_lab_test_no = $data['lab_test_no'];
        
        foreach ($rolls_to_process as $roll_ref) {
            // For bulk rolls, use the individual roll as reference_number
            $current_reference = !empty($bulk_rolls) ? $roll_ref : $finalReference;
            
            // Validate the current reference
            if (strlen($current_reference) <= 3 || !preg_match('/[A-Za-z]/', $current_reference)) {
                error_log("Water Test: Skipping invalid reference: " . $current_reference);
                continue;
            }
            
            // Generate unique report number for each roll
            if ($inserted_count > 0) {
                // Generate new report number for subsequent rolls
                $date_formatted = date('Ymd');
                $countStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM water_permeability_tests WHERE DATE(created_at) = CURDATE()");
                $countStmt->execute();
                $countResult = $countStmt->get_result();
                $countRow = $countResult->fetch_assoc();
                $seq = (int)$countRow['cnt'] + 1;
                $data['report_no'] = "WPT-{$date_formatted}-" . str_pad($seq, 3, '0', STR_PAD_LEFT);
                $data['lab_test_no'] = (string)$seq;
            }
            $report_numbers[] = $data['report_no'];
        
        // Log the reference right before binding to prepared statement
            error_log("WPT Database Insert - reference_number value: '" . $current_reference . "' (length: " . strlen($current_reference) . ")");
        error_log("WPT Database Insert - bundle_reference value: " . ($bundle_reference ? "'" . $bundle_reference . "'" : 'NULL'));
        
        // Fix bind_param types: reference_number should be 's' (string), not 'i' (integer)
        // Types: report_no(s), lab_test_no(s), reference_number(s), bundle_reference(s), gsm(i), roll_number(s), test_date(s), shift(s), test_results(s), test_performed_by(s), checked_by(s), approved_by(s), checked_at(s), approved_at(s), reporter_id(i), reporter_name(s), status(s), remarks(s)
        $stmt->bind_param('ssssissssssssssiss', 
            $data['report_no'],
            $data['lab_test_no'],
                $current_reference,  // Use current roll reference
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
        
        // Log successful insertion
            error_log("WPT Database Insert - About to execute with reference_number: '" . $current_reference . "'");
        
        if (!$stmt->execute()) {
            $error_msg = $stmt->error;
                error_log("Water Test: Failed to insert roll " . $roll_ref . ": " . $error_msg);
                continue;
        }
        
        // Check if row was actually inserted
        $inserted_id = $conn->insert_id;
        if (!$inserted_id) {
            error_log("No insert ID returned. Affected rows: " . $conn->affected_rows);
                error_log("WPT Database Insert - Failed to insert, reference_number was: '" . $current_reference . "'");
                continue;
            }
            
            $inserted_count++;
        }
        
        $stmt->close();
        
        if ($inserted_count === 0) {
            throw new Exception("Failed to save any tests. Please check your data and try again.");
        }
        
        // Check bundle completion if this is from a bundle and was approved
        if ($bundle_reference && $status === 'approved') {
            checkAndMarkBundleComplete($conn, 'water_permeability_tests', $bundle_reference);
        }
        
        $conn->commit();
        
        // Generate success message based on count
        if ($inserted_count > 1) {
            $report_list = implode(', ', array_slice($report_numbers, 0, 3)) . (count($report_numbers) > 3 ? '...' : '');
        if ($status === 'pending') {
                $message = "{$inserted_count} Water Permeability Tests submitted for checking! Reports: " . htmlspecialchars($report_list);
        } elseif ($status === 'checked') {
                $message = "{$inserted_count} Water Permeability Tests forwarded to admin for approval! Reports: " . htmlspecialchars($report_list);
        } else {
                $message = "{$inserted_count} Water Permeability Tests approved and submitted successfully! Reports: " . htmlspecialchars($report_list);
            }
        } else {
            if ($status === 'pending') {
                $message = "Water Permeability Test submitted for checking! Report: " . htmlspecialchars($report_numbers[0]);
            } elseif ($status === 'checked') {
                $message = "Water Permeability Test forwarded to admin for approval! Report: " . htmlspecialchars($report_numbers[0]);
            } else {
                $message = "Water Permeability Test approved and submitted successfully! Report: " . htmlspecialchars($report_numbers[0]);
            }
        }
        
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
  
  <form method="POST" action="" onsubmit="return validateWPTForm();">

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
        <label>Lab Test No.:</label>
        <input type="text" name="lab_test_no" id="lab_test_no" value="<?php echo htmlspecialchars($generated_lab_test_no); ?>" readonly class="readonly">
      </div>
      <div class="form-group">
        <label>Test Date:</label>
        <input type="date" name="test_date" id="test_date" value="<?php echo date('Y-m-d'); ?>" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group" style="grid-column: 1 / -1;">
        <label>Reference Number:</label>
        
        <!-- Line Selection Buttons -->
        <div id="wpt_line_selection_buttons" style="display:flex; gap:10px; margin-bottom:10px;">
          <button type="button" id="wpt_line1_btn" class="line-btn" onclick="filterWPTByLine('L1')" style="padding:8px 16px; border:2px solid #3498db; border-radius:6px; background:#e3f2fd; color:#1565C0; font-weight:600; cursor:pointer;">
            Line 1
          </button>
          <button type="button" id="wpt_line2_btn" class="line-btn" onclick="filterWPTByLine('L2')" style="padding:8px 16px; border:2px solid #3498db; border-radius:6px; background:#e3f2fd; color:#1565C0; font-weight:600; cursor:pointer;">
            Line 2
          </button>
          <button type="button" id="wpt_line_all_btn" class="line-btn active" onclick="filterWPTByLine('all')" style="padding:8px 16px; border:2px solid #3498db; border-radius:6px; background:#2196F3; color:#ffffff; font-weight:600; cursor:pointer;">
            All Lines
          </button>
        </div>
        
        <!-- From/To Reference Selection (shown when line is selected) -->
        <div id="wpt_bulk_reference_selection" style="display:none; margin-bottom:10px; padding:10px; background:#f8f9fa; border:1px solid #ddd; border-radius:6px;">
          <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <label style="font-weight:600; margin:0;">From Reference:</label>
            <select id="wpt_from_reference" name="from_reference" style="min-width:250px; padding:5px; border:1px solid #ccc; border-radius:4px;" onchange="updateWPTReferenceRange(true); handleWPTFromToReferenceChange();">
              <option value="">-- Select From Reference --</option>
            </select>
            <label style="font-weight:600; margin:0;">To Reference:</label>
            <select id="wpt_to_reference" name="to_reference" style="min-width:250px; padding:5px; border:1px solid #ccc; border-radius:4px;" onchange="updateWPTReferenceRange(false); handleWPTFromToReferenceChange();">
              <option value="">-- Select To Reference --</option>
            </select>
            <button type="button" onclick="applyWPTBulkReferenceSelection()" style="padding:6px 12px; background:#3498db; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:600;">
              Apply
            </button>
            <button type="button" onclick="clearWPTBulkReferenceSelection()" style="padding:6px 12px; background:#6c757d; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:600;">
              Clear
            </button>
          </div>
          
          <!-- Reference Test Status Display (shown when Apply is clicked) -->
          <div id="wpt_reference_test_status_container" style="display:none; margin-top:20px; padding:0; background:#ffffff; border:2px solid #e0e0e0; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.1); max-width:600px; margin-left:auto; margin-right:auto;">
            <div style="padding:20px; background:linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius:12px 12px 0 0; color:white;">
              <div style="display:flex; align-items:center; gap:12px;">
                <i class="fas fa-list-check" style="font-size:24px;"></i>
                <div>
                  <h3 style="margin:0; font-size:18px; font-weight:600;" id="wpt_reference_status_title">Reference Test Status</h3>
                  <div style="font-size:13px; opacity:0.9; margin-top:4px;" id="wpt_reference_status_summary"></div>
                </div>
              </div>
            </div>
            <div id="wpt_reference_test_status_list" style="padding:20px; max-height:500px; overflow-y:auto;">
              <!-- Status will be populated here -->
            </div>
          </div>
          
          <!-- Hidden input to store the selected product_reference when line-based selection is used -->
          <input type="hidden" id="wpt_line_based_product_reference" name="product_reference" value="">
        </div>
        
        <!-- Production Product Reference Dropdown (shown when "All Lines" is selected) -->
        <select id="reference_number" name="reference_number" onchange="handleWPTReferenceSelection(this.value)" required style="padding:10px; border:1px solid #ccc; border-radius:6px; width:100%;">
          <option value="">Select Reference Number</option>
          <!-- References will be loaded dynamically via JavaScript from API -->
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

// Track if references are currently being loaded to prevent duplicate calls
let wptReferencesLoading = false;

// Load references list on page load (same as sun test)
function loadWPTReferencesList() {
    // Prevent multiple simultaneous calls
    if (wptReferencesLoading) {
        console.log('WPT References already loading, skipping...');
        return;
    }
    
    const select = document.getElementById('reference_number');
    if (!select) {
        console.error('Reference dropdown not found');
        return;
    }
    
    wptReferencesLoading = true;
    
    // Clear ALL existing options completely
    select.innerHTML = '<option value="">Select Reference Number</option>';
    
    // Track added references to prevent duplicates
    const addedReferences = new Set();
    
    // Add cache-busting parameter to ensure fresh data after submission
    const cacheBuster = '?t=' + new Date().getTime();
    fetch('api/get_references_list_wpt.php' + cacheBuster)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                
                // Add bundle references first
                if (data.bundleReferences && data.bundleReferences.length > 0) {
                    data.bundleReferences.forEach(bundle => {
                        // Skip if already added (deduplication)
                        if (addedReferences.has(bundle.reference)) {
                            return;
                        }
                        addedReferences.add(bundle.reference);
                        
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
                
                // Add single roll references (skip if already added as bundle)
                if (data.references && data.references.length > 0) {
                    data.references.forEach(ref => {
                        const refValue = typeof ref === 'string' ? ref : ref.reference;
                        
                        // Skip if already added (deduplication)
                        if (addedReferences.has(refValue)) {
                            return;
                        }
                        addedReferences.add(refValue);
                        
                        const lineIndicator = typeof ref === 'object' ? (ref.line || '') : '';
                        const option = document.createElement('option');
                        option.value = refValue;
                        option.setAttribute('data-is-bundle', 'false');
                        option.setAttribute('data-line', lineIndicator);
                        option.textContent = refValue;
                        select.appendChild(option);
                    });
                }
                
                console.log('WPT References loaded: ' + (data.references ? data.references.length : 0) + ' single, ' + (data.bundleReferences ? data.bundleReferences.length : 0) + ' bundles, ' + addedReferences.size + ' unique total');
            } else {
                console.error('Failed to load references:', data.error);
            }
        })
        .catch(err => console.error('Error loading references:', err))
        .finally(() => {
            wptReferencesLoading = false;
        });
}

// Load references when page loads
let wptReferencesLoaded = false;
document.addEventListener('DOMContentLoaded', function() {
    if (!wptReferencesLoaded) {
        loadWPTReferencesList();
        wptReferencesLoaded = true;
    }
});

// Also reload references when the page is shown (after redirect)
window.addEventListener('pageshow', function(event) {
    // Only reload if not already loaded, or if page was restored from cache
    if (event.persisted || !wptReferencesLoaded) {
        wptReferencesLoaded = true;
        // Small delay to ensure DOM is ready
        setTimeout(function() {
            loadWPTReferencesList();
        }, 100);
    }
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

// Filter references by Line (L1 or L2)
function filterWPTByLine(line) {
    const productRefSelect = document.getElementById('reference_number');
    const bulkRefSelection = document.getElementById('wpt_bulk_reference_selection');
    
    // Update button styles
    const line1Btn = document.getElementById('wpt_line1_btn');
    const line2Btn = document.getElementById('wpt_line2_btn');
    const lineAllBtn = document.getElementById('wpt_line_all_btn');
    
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
        productRefSelect.removeAttribute('name');
        
        // Show From/To reference selection
        if (bulkRefSelection) {
            bulkRefSelection.style.display = 'block';
            populateWPTLineReferences(line);
        }
        
        // Make From/To required (name attributes already in HTML)
        const fromRefSelect = document.getElementById('wpt_from_reference');
        const toRefSelect = document.getElementById('wpt_to_reference');
        if (fromRefSelect) {
            fromRefSelect.setAttribute('required', 'required');
        }
        if (toRefSelect) {
            toRefSelect.setAttribute('required', 'required');
        }
    } else {
        // Show single reference dropdown for "All Lines"
        productRefSelect.style.display = 'block';
        productRefSelect.setAttribute('required', 'required');
        productRefSelect.setAttribute('name', 'reference_number');
        
        // Hide From/To reference selection
        if (bulkRefSelection) {
            bulkRefSelection.style.display = 'none';
            clearWPTBulkReferenceSelection();
        }
        
        // Remove required from From/To and remove names
        // Remove required from From/To, clear values, and remove names to prevent submission
        const fromRefSelect = document.getElementById('wpt_from_reference');
        const toRefSelect = document.getElementById('wpt_to_reference');
        if (fromRefSelect) {
            fromRefSelect.removeAttribute('required');
            fromRefSelect.removeAttribute('name'); // Remove name so it won't be submitted
            fromRefSelect.value = '';
        }
        if (toRefSelect) {
            toRefSelect.removeAttribute('required');
            toRefSelect.removeAttribute('name'); // Remove name so it won't be submitted
            toRefSelect.value = '';
        }
        
        // Clear hidden input
        const lineBasedProductRef = document.getElementById('wpt_line_based_product_reference');
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
                handleWPTReferenceSelection('');
            }
        }
    }
}

// Populate From/To reference dropdowns with references for selected line
function populateWPTLineReferences(line) {
    const productRefSelect = document.getElementById('reference_number');
    const fromRefSelect = document.getElementById('wpt_from_reference');
    const toRefSelect = document.getElementById('wpt_to_reference');
    
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
    
    // Populate both dropdowns - ensure values are valid full references
    lineReferences.forEach(ref => {
        // Skip if value is too short or invalid
        if (!ref.value || ref.value.length <= 3 || !ref.value.match(/[A-Za-z]/)) {
            console.warn('Skipping invalid reference:', ref.value);
            return;
        }
        
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
function updateWPTReferenceRange(autoSelect = true) {
    const fromRefSelect = document.getElementById('wpt_from_reference');
    const toRefSelect = document.getElementById('wpt_to_reference');
    
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
function applyWPTBulkReferenceSelection() {
    const fromRef = document.getElementById('wpt_from_reference').value;
    const toRef = document.getElementById('wpt_to_reference').value;
    
    if (!fromRef || !toRef) {
        alert('Please select both From and To references');
        return;
    }
    
    // Store the range in hidden input
    const lineBasedProductRef = document.getElementById('wpt_line_based_product_reference');
    if (lineBasedProductRef) {
        lineBasedProductRef.value = fromRef + ' to ' + toRef;
    }
    
    // Update the main reference dropdown to show the range
    const productRefSelect = document.getElementById('reference_number');
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
    checkWPTReferenceTestStatus(fromRef, toRef);
}

// Check which references in range have been submitted for Water Permeability Test
function checkWPTReferenceTestStatus(fromRef, toRef) {
    const container = document.getElementById('wpt_reference_test_status_container');
    const statusList = document.getElementById('wpt_reference_test_status_list');
    const statusTitle = document.getElementById('wpt_reference_status_title');
    const statusSummary = document.getElementById('wpt_reference_status_summary');
    
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
    fetch(`api/check_submitted_tests_range_wpt.php?from_reference=${encodeURIComponent(fromRef)}&to_reference=${encodeURIComponent(toRef)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayWPTReferenceStatus(data, fromRef, toRef);
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
function displayWPTReferenceStatus(data, fromRef, toRef) {
    const statusList = document.getElementById('wpt_reference_test_status_list');
    const statusSummary = document.getElementById('wpt_reference_status_summary');
    
    if (!statusList) return;
    
    const submittedRefs = data.submitted_references || [];
    const submittedRefSet = new Set(submittedRefs.map(r => r.reference));
    
    // Get all references in range
    const fromSelect = document.getElementById('wpt_from_reference');
    const toSelect = document.getElementById('wpt_to_reference');
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
function clearWPTBulkReferenceSelection() {
    const fromRefSelect = document.getElementById('wpt_from_reference');
    const toRefSelect = document.getElementById('wpt_to_reference');
    const lineBasedProductRef = document.getElementById('wpt_line_based_product_reference');
    
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
function handleWPTFromToReferenceChange() {
    // This function can be extended to perform validation or other actions
    // when From/To references change
}

// Validate form before submission
function validateWPTForm() {
    const productRefSelect = document.getElementById('reference_number');
    const fromRefSelect = document.getElementById('wpt_from_reference');
    const toRefSelect = document.getElementById('wpt_to_reference');
    const individualRollSelect = document.getElementById('wpt_individual_roll_reference');
    
    // Check if line-based selection is active (From/To visible)
    const bulkRefSelection = document.getElementById('wpt_bulk_reference_selection');
    const isLineBased = bulkRefSelection && bulkRefSelection.style.display !== 'none';
    
    if (isLineBased) {
        // Validate From/To references
        const fromValue = fromRefSelect ? fromRefSelect.value : '';
        const toValue = toRefSelect ? toRefSelect.value : '';
        
        if (!fromValue || !toValue) {
            alert('Please select both From Reference and To Reference');
            if (!fromValue && fromRefSelect) fromRefSelect.focus();
            else if (!toValue && toRefSelect) toRefSelect.focus();
            return false;
        }
        
        // Validate that references are valid full references (not too short)
        if (fromValue && fromValue.length <= 3) {
            alert('Invalid From Reference selected. Please select a valid full reference.');
            if (fromRefSelect) fromRefSelect.focus();
            return false;
        }
        
        if (toValue && toValue.length <= 3) {
            alert('Invalid To Reference selected. Please select a valid full reference.');
            if (toRefSelect) toRefSelect.focus();
            return false;
        }
        
        // Ensure the reference field name is removed so it doesn't interfere
        if (productRefSelect) {
            productRefSelect.removeAttribute('name');
        }
        
        // Ensure from/to have their names set (already in HTML, but ensure it's there)
        if (fromRefSelect) fromRefSelect.setAttribute('name', 'from_reference');
        if (toRefSelect) toRefSelect.setAttribute('name', 'to_reference');
    } else {
        // Validate single reference or individual roll
        const refValue = productRefSelect ? productRefSelect.value : '';
        const individualValue = individualRollSelect && individualRollSelect.style.display !== 'none' 
            ? individualRollSelect.value : '';
        
        if (!refValue && !individualValue) {
            alert('Please select a reference');
            if (productRefSelect) productRefSelect.focus();
            return false;
        }
        
        // Ensure the reference field has its name set
        if (productRefSelect) {
            productRefSelect.setAttribute('name', 'reference_number');
            
            // Debug: Log the selected reference value
            const selectedRef = productRefSelect.value;
            console.log('WPT Form Submit - Selected reference_number:', selectedRef);
            
            // Validate that the selected reference is valid (full reference)
            if (selectedRef && (selectedRef.length <= 3 || !selectedRef.match(/[A-Za-z]/))) {
                alert('Invalid reference selected: "' + selectedRef + '". Please select a valid full reference.');
                productRefSelect.focus();
                return false;
            }
        }
        
        // Remove names from from/to so they don't interfere
        if (fromRefSelect) fromRefSelect.removeAttribute('name');
        if (toRefSelect) toRefSelect.removeAttribute('name');
    }
    
    // Final check before submission - ensure we have a valid reference
    const form = document.querySelector('form[onsubmit*="validateWPTForm"]') || document.querySelector('form');
    if (form) {
        const formData = new FormData(form);
        const refValue = formData.get('reference_number') || formData.get('from_reference') || formData.get('individual_roll_reference');
        console.log('WPT Form Submit - Final reference to submit:', refValue);
        
        if (!refValue) {
            alert('No reference selected. Please select a reference.');
            return false;
        }
        
        if (refValue.length <= 3 || !refValue.match(/[A-Za-z]/)) {
            alert('Invalid reference detected: "' + refValue + '". Please select a valid full reference.');
            return false;
        }
    }
    
    return true;
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
