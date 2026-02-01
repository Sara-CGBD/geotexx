<?php
session_start();

// AJAX endpoint to generate external reference - MUST be before anything that outputs HTML
if (isset($_GET['action']) && $_GET['action'] === 'generate_external_ref') {
    require_once 'security_config.php';
    
    // Verify user is logged in for AJAX call
    if (!isset($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Not authenticated'
        ]);
        exit();
    }
    
    $conn = SecurityConfig::getConnection();
    
    // Generate external reference
    $today = date('Ymd');
    $pattern = "EXT-{$today}-%";
    
    $count = 0;
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c 
        FROM qc_test_orders 
        WHERE sample_reference_id LIKE ? 
        AND DATE(created_at) = CURDATE()
    ");
    
    if ($stmt) {
        $stmt->bind_param('s', $pattern);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            $row = $res->fetch_assoc();
            $count = (int)($row['c'] ?? 0);
        }
        $stmt->close();
    }
    
    $seq = $count + 1;
    $reference = 'EXT-' . $today . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'reference' => $reference
    ]);
    exit();
}

// AJAX endpoint to check reference-level test status for a range
if (isset($_GET['action']) && $_GET['action'] === 'check_reference_test_status') {
    // Suppress any output and errors that might interfere with JSON
    ob_start();
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    
    try {
        require_once 'security_config.php';
        
        // Verify user is logged in for AJAX call
        if (!isset($_SESSION['user_id'])) {
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Not authenticated'
            ]);
            exit();
        }
        
        $fromRef = $_GET['from_reference'] ?? '';
        $toRef = $_GET['to_reference'] ?? '';
        $testName = $_GET['test_name'] ?? '';
        $method = $_GET['method'] ?? '';
        
        if (empty($fromRef) || empty($toRef) || empty($testName) || empty($method)) {
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'error' => 'Missing required parameters',
                'debug' => [
                    'from_ref' => $fromRef,
                    'to_ref' => $toRef,
                    'test_name' => $testName,
                    'method' => $method
                ]
            ]);
            exit();
        }
        
        $conn = SecurityConfig::getConnection();
        if (!$conn) {
            throw new Exception('Database connection failed');
        }
        
        // Generate all references in the range
        $references = [];
        $fromBaseRef = '';
        $fromRollNum = 0;
        $fromSuffix = '';
        $toBaseRef = '';
        $toRollNum = 0;
        $toSuffix = '';
        
        // Parse from reference: extract base, roll number, and suffix (e.g., -GT0.9.H0.1)
        // Pattern: BASE-R##-SUFFIX or BASE-##-SUFFIX or BASE-R## or BASE-##
        // Try to match -R## or -## followed by optional suffix
        if (preg_match('/^(.+?)-R(\d{1,2})(.*)$/', $fromRef, $fromMatches)) {
            // Format: BASE-R##-SUFFIX or BASE-R##
            $fromBaseRef = $fromMatches[1];
            $fromRollNum = (int)$fromMatches[2];
            $fromSuffix = $fromMatches[3]; // This captures the suffix like -GT0.9.H0.1
        } elseif (preg_match('/^(.+?)-(\d{1,2})(.*)$/', $fromRef, $fromMatches)) {
            // Format: BASE-##-SUFFIX or BASE-##
            $fromBaseRef = $fromMatches[1];
            $fromRollNum = (int)$fromMatches[2];
            $fromSuffix = $fromMatches[3]; // This captures the suffix like -GT0.9.H0.1
        } else {
            // No roll number pattern found - use reference as-is
            $fromBaseRef = $fromRef;
            $fromRollNum = 1;
            $fromSuffix = '';
        }
        
        // Parse to reference
        if (preg_match('/^(.+?)-R(\d{1,2})(.*)$/', $toRef, $toMatches)) {
            // Format: BASE-R##-SUFFIX or BASE-R##
            $toBaseRef = $toMatches[1];
            $toRollNum = (int)$toMatches[2];
            $toSuffix = $toMatches[3]; // This captures the suffix like -GT0.9.H0.1
        } elseif (preg_match('/^(.+?)-(\d{1,2})(.*)$/', $toRef, $toMatches)) {
            // Format: BASE-##-SUFFIX or BASE-##
            $toBaseRef = $toMatches[1];
            $toRollNum = (int)$toMatches[2];
            $toSuffix = $toMatches[3]; // This captures the suffix like -GT0.9.H0.1
        } else {
            // No roll number pattern found - use reference as-is
            $toBaseRef = $toRef;
            $toRollNum = 1;
            $toSuffix = '';
        }
        
        // Generate references
        // If same base and same suffix, generate range; otherwise use endpoints as-is
        if ($fromBaseRef === $toBaseRef && $fromSuffix === $toSuffix && $fromRollNum > 0 && $toRollNum > 0 && $fromRollNum <= $toRollNum) {
            // Same base reference and suffix - generate all in range
            for ($roll = $fromRollNum; $roll <= $toRollNum; $roll++) {
                // Preserve original format (R## or just ##) and suffix
                if (strpos($fromRef, '-R') !== false) {
                    $references[] = $fromBaseRef . '-R' . str_pad($roll, 2, '0', STR_PAD_LEFT) . $fromSuffix;
                } else {
                    $references[] = $fromBaseRef . '-' . $roll . $fromSuffix;
                }
            }
        } else {
            // Different base references, different suffixes, or invalid range - use endpoints as-is
            $references[] = $fromRef;
            if ($toRef !== $fromRef) {
                $references[] = $toRef;
            }
        }
        
        // Log for debugging
        error_log("QC Reference Status: From='$fromRef', To='$toRef'");
        error_log("QC Reference Status: Parsed - FromBase='$fromBaseRef', FromRoll=$fromRollNum, FromSuffix='$fromSuffix'");
        error_log("QC Reference Status: Parsed - ToBase='$toBaseRef', ToRoll=$toRollNum, ToSuffix='$toSuffix'");
        error_log("QC Reference Status: Generated " . count($references) . " references: " . implode(', ', $references));
        
        // Get test standard ID
        // Note: test_standards table uses 'standard_code' not 'method'
        $testStdStmt = $conn->prepare("SELECT id FROM test_standards WHERE test_name = ? AND standard_code = ? LIMIT 1");
        $testStdStmt->bind_param('ss', $testName, $method);
        $testStdStmt->execute();
        $testStdResult = $testStdStmt->get_result();
        $testStandardId = null;
        if ($testStdRow = $testStdResult->fetch_assoc()) {
            $testStandardId = $testStdRow['id'];
        }
        $testStdStmt->close();
        
        // Log if test standard not found
        if (!$testStandardId) {
            error_log("QC Reference Status: Test standard not found for test_name='$testName', method='$method'");
        }
    
        // Check status for each reference
        $results = [];
        foreach ($references as $ref) {
            $status = 'pending';
            $submittedDate = null;
            $reportNumber = null;
            $reportId = null;
            
            if ($testStandardId) {
                // Check if test is submitted for this reference
                // Use multiple matching strategies to handle variations:
                // 1. Exact match
                // 2. LIKE pattern (handles partial matches)
                // 3. Case-insensitive match
                
                $found = false;
                $checkRow = null;
                
                // Strategy 1: Exact match (most reliable)
                $checkStmt = $conn->prepare("
                    SELECT id, report_number, created_at, status, sample_reference_id
                    FROM qc_test_orders
                    WHERE sample_reference_id = ?
                    AND test_standard_id = ?
                    AND chosen_method = ?
                    AND status NOT IN ('rejected_by_checker', 'rejected_by_approver')
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $checkStmt->bind_param('sis', $ref, $testStandardId, $method);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                
                if ($checkRow = $checkResult->fetch_assoc()) {
                    $found = true;
                    error_log("QC Reference Status: Found (exact match) for ref='$ref', report='{$checkRow['report_number']}', stored_ref='{$checkRow['sample_reference_id']}'");
                }
                $checkStmt->close();
                
                // Strategy 2: If exact match failed, try LIKE pattern (handles suffix variations)
                if (!$found) {
                    // Try matching with LIKE - this handles cases where stored ref might have extra parts
                    $refPattern = $ref . '%';
                    $checkStmt = $conn->prepare("
                        SELECT id, report_number, created_at, status, sample_reference_id
                        FROM qc_test_orders
                        WHERE sample_reference_id LIKE ?
                        AND test_standard_id = ?
                        AND chosen_method = ?
                        AND status NOT IN ('rejected_by_checker', 'rejected_by_approver')
                        ORDER BY created_at DESC
                        LIMIT 1
                    ");
                    $checkStmt->bind_param('sis', $refPattern, $testStandardId, $method);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    
                    if ($checkRow = $checkResult->fetch_assoc()) {
                        $found = true;
                        error_log("QC Reference Status: Found (LIKE match) for ref='$ref', report='{$checkRow['report_number']}', stored_ref='{$checkRow['sample_reference_id']}'");
                    }
                    $checkStmt->close();
                }
                
                // Strategy 3: If still not found, try reverse LIKE (stored ref is shorter than query ref)
                if (!$found) {
                    // Extract base reference (without suffix) and try matching
                    $baseRef = $ref;
                    if (preg_match('/^(.+?)-R?(\d{1,2})/', $ref, $baseMatches)) {
                        $baseRef = $baseMatches[1] . (strpos($ref, '-R') !== false ? '-R' . str_pad($baseMatches[2], 2, '0', STR_PAD_LEFT) : '-' . $baseMatches[2]);
                    }
                    
                    $basePattern = $baseRef . '%';
                    $checkStmt = $conn->prepare("
                        SELECT id, report_number, created_at, status, sample_reference_id
                        FROM qc_test_orders
                        WHERE sample_reference_id LIKE ?
                        AND test_standard_id = ?
                        AND chosen_method = ?
                        AND status NOT IN ('rejected_by_checker', 'rejected_by_approver')
                        ORDER BY created_at DESC
                        LIMIT 1
                    ");
                    $checkStmt->bind_param('sis', $basePattern, $testStandardId, $method);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    
                    if ($checkRow = $checkResult->fetch_assoc()) {
                        $storedRef = $checkRow['sample_reference_id'];
                        // Verify the stored reference matches our query reference (handle suffix variations)
                        if ($storedRef === $ref || 
                            strpos($storedRef, $baseRef) === 0 || 
                            strpos($ref, $baseRef) === 0) {
                            $found = true;
                            error_log("QC Reference Status: Found (base pattern match) for ref='$ref', report='{$checkRow['report_number']}', stored_ref='$storedRef'");
                        }
                    }
                    $checkStmt->close();
                }
                
                // Strategy 4: Try case-insensitive exact match (MySQL default, but be explicit)
                if (!$found) {
                    $checkStmt = $conn->prepare("
                        SELECT id, report_number, created_at, status, sample_reference_id
                        FROM qc_test_orders
                        WHERE LOWER(TRIM(sample_reference_id)) = LOWER(TRIM(?))
                        AND test_standard_id = ?
                        AND chosen_method = ?
                        AND status NOT IN ('rejected_by_checker', 'rejected_by_approver')
                        ORDER BY created_at DESC
                        LIMIT 1
                    ");
                    $checkStmt->bind_param('sis', $ref, $testStandardId, $method);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    
                    if ($checkRow = $checkResult->fetch_assoc()) {
                        $found = true;
                        error_log("QC Reference Status: Found (case-insensitive match) for ref='$ref', report='{$checkRow['report_number']}', stored_ref='{$checkRow['sample_reference_id']}'");
                    }
                    $checkStmt->close();
                }
                
                if ($found && $checkRow) {
                    $status = 'submitted';
                    $submittedDate = $checkRow['created_at'];
                    $reportNumber = $checkRow['report_number'];
                    $reportId = $checkRow['id'];
                } else {
                    // Log when not found for debugging
                    error_log("QC Reference Status: No submitted test found for ref='$ref', test_standard_id=$testStandardId, method='$method' (tried exact, LIKE, base pattern, and case-insensitive)");
                }
            } else {
                error_log("QC Reference Status: testStandardId is null for ref='$ref', test_name='$testName', method='$method'");
            }
            
            $results[] = [
                'reference' => $ref,
                'status' => $status,
                'submitted_date' => $submittedDate,
                'report_number' => $reportNumber,
                'report_id' => $reportId,
                'can_submit' => ($status === 'pending')
            ];
        }
        
        // Log for debugging
        error_log("QC Reference Status API: Returning " . count($results) . " reference results for test: " . $testName . " (" . $method . ")");
        
        // Clean any output buffer and send JSON
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'test_name' => $testName,
            'method' => $method,
            'references' => $results,
            'debug' => [
                'from_ref' => $fromRef,
                'to_ref' => $toRef,
                'generated_refs' => $references,
                'test_standard_id' => $testStandardId
            ]
        ]);
        exit();
        
    } catch (Exception $e) {
        // Handle any errors gracefully
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Server error: ' . $e->getMessage(),
            'debug' => [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ]);
        error_log("QC Reference Status API Error: " . $e->getMessage());
        exit();
    } catch (Error $e) {
        // Handle fatal errors
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Fatal error: ' . $e->getMessage(),
            'debug' => [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ]);
        error_log("QC Reference Status API Fatal Error: " . $e->getMessage());
        exit();
    }
}

// Include auto-reload AFTER AJAX endpoint to prevent it from contaminating JSON response
$devReload = __DIR__ . '/../dev/auto_reload.php';
if (file_exists($devReload)) {
    include_once $devReload;
}

// Prevent browser caching to ensure fresh data loads
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'security_config.php';

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
        <p>You do not have permission to access the QC Test Order module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();
$reporter_id = $_SESSION['user_id'];
$reporter_name = $_SESSION['full_name'] ?? $_SESSION['username'];
$user_role = strtolower(trim($_SESSION['role'] ?? ''));

// Check user role permissions
$is_tester = in_array($user_role, ['tester', 'qc_inspector', 'admin']);
$is_checker = ($user_role === 'checker' || $user_role === 'admin');
$is_admin = ($user_role === 'admin' || $user_role === 'agm ops' || $user_role === 'agm operations');

// Helper function to get readable status labels
function getStatusLabel($status) {
    $labels = [
        'pending_checker' => 'Pending Checker Review',
        'pending_approval' => 'Forwarded to AGM/Admin',
        'approved' => 'Approved',
        'rejected_by_checker' => 'Rejected by Checker',
        'rejected_by_approver' => 'Rejected by Admin'
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

$message = '';
$error = '';

// Check for error message from session
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']); // Clear it after displaying
}

// Check if coming from Roll Entry and pre-select reference
$preselected_reference = '';
if (isset($_SESSION['last_roll_entry_reference'])) {
    $preselected_reference = $_SESSION['last_roll_entry_reference'];
    // Don't unset here - let it stay for multiple visits
}

// Check for success message from session (after redirect)
if (isset($_SESSION['qc_success_message'])) {
    $message = $_SESSION['qc_success_message'];
    unset($_SESSION['qc_success_message']); // Clear it after displaying
}

// Check for success message from redirect (legacy)
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Check if we're in edit mode
$edit_mode = false;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$existing_report = null;
$existing_test_data = [];
$edit_external_forward = false;

if ($edit_id > 0) {
    // Fetch the report for editing - allow editing for:
    // 1. Rejected reports (original behavior)
    // 2. External products in pending status (for testers to fill test data)
    $stmt = $conn->prepare("
        SELECT qto.*, ts.test_name, ts.standard_code,
               LOWER(TRIM(u.role)) as creator_role
        FROM qc_test_orders qto
        LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
        LEFT JOIN new_user u ON qto.inspector_id = u.id
        WHERE qto.id = ?
    ");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing_report = $result->fetch_assoc();
    $stmt->close();
    
    if ($existing_report) {
        // Check if it's an external product
        $test_data_check = json_decode($existing_report['test_data'] ?? '{}', true);
        $is_external = ($existing_report['sample_reference_id'] ?? '') !== '' && 
                       (strpos($existing_report['sample_reference_id'], 'EXT-') === 0 || 
                        strpos($existing_report['sample_reference_id'], 'TOKEN-') === 0 ||
                        (isset($test_data_check['is_external_product']) && $test_data_check['is_external_product'] == '1'));
        
        // Check if original creator was AGM/admin
        $creator_role = strtolower(trim($existing_report['creator_role'] ?? ''));
        $was_created_by_agm = in_array($creator_role, ['agm ops', 'agm operations', 'admin']);
        
        // Determine if this is an external product forwarded to testers
        $edit_external_forward = $is_external && in_array($existing_report['status'], ['pending_tester', 'pending_checker', 'pending_approval']);
        
        // Allow editing if:
        // 1. Report is rejected (original behavior) - for external tests, allow any tester to edit
        // 2. OR it's an external product in pending status (for testers to perform tests)
        $can_edit = false;
        if (in_array($existing_report['status'], ['rejected_by_checker', 'rejected_by_approver'])) {
            // For external tests: allow any tester to edit rejected reports
            // For regular tests: only owner can edit rejected reports
            if ($is_external) {
                // External test: any tester can edit rejected tests
                $can_edit = true;
            } else {
                // Regular test: only owner can edit rejected reports
                if ($existing_report['inspector_id'] == $reporter_id) {
                    $can_edit = true;
                }
            }
        } elseif ($edit_external_forward) {
            // New behavior: testers can edit external products forwarded by AGM
            // Allow any tester to edit external products in pending status
            $can_edit = true;
        }
        
        if ($can_edit) {
            $edit_mode = true;
            $existing_test_data = json_decode($existing_report['test_data'], true) ?? [];
            
            // Find all related reports in the same bulk group to determine which test methods were submitted
            $submitted_test_methods = []; // Array to store test_name => [methods] that were submitted
            $bulk_from_ref = '';
            $bulk_to_ref = '';
            
            // Always get the current report's test method (for both bulk and single reports)
            $current_test_name = $existing_report['test_name'] ?? '';
            $current_method = $existing_report['chosen_method'] ?? '';
            if (!empty($current_test_name) && !empty($current_method)) {
                if (!isset($submitted_test_methods[$current_test_name])) {
                    $submitted_test_methods[$current_test_name] = [];
                }
                if (!in_array($current_method, $submitted_test_methods[$current_test_name])) {
                    $submitted_test_methods[$current_test_name][] = $current_method;
                }
            }
            
            if (isset($existing_test_data['is_bulk_reference']) && $existing_test_data['is_bulk_reference'] && 
                isset($existing_test_data['bulk_from_reference']) && isset($existing_test_data['bulk_to_reference'])) {
                // This is a bulk reference submission - find all related reports
                $bulk_from_ref = $existing_test_data['bulk_from_reference'];
                $bulk_to_ref = $existing_test_data['bulk_to_reference'];
                $current_status = $existing_report['status'];
                $current_report_id = $existing_report['id'];
                
                // Query to find all reports with the same status - filter in PHP for better compatibility
                $related_query = $conn->prepare("
                    SELECT ts.test_name, qto.chosen_method, qto.test_data
                    FROM qc_test_orders qto
                    LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
                    WHERE qto.status = ?
                    AND qto.id != ?
                ");
                
                if ($related_query) {
                    $related_query->bind_param("si", $current_status, $current_report_id);
                    $related_query->execute();
                    $related_result = $related_query->get_result();
                    
                    while ($row = $related_result->fetch_assoc()) {
                        $row_test_data = json_decode($row['test_data'] ?? '{}', true) ?? [];
                        // Check if this report has the same bulk reference range
                        if (isset($row_test_data['is_bulk_reference']) && $row_test_data['is_bulk_reference'] &&
                            isset($row_test_data['bulk_from_reference']) && isset($row_test_data['bulk_to_reference']) &&
                            $row_test_data['bulk_from_reference'] === $bulk_from_ref &&
                            $row_test_data['bulk_to_reference'] === $bulk_to_ref) {
                            $test_name = $row['test_name'] ?? '';
                            $method = $row['chosen_method'] ?? '';
                            if (!empty($test_name) && !empty($method)) {
                                if (!isset($submitted_test_methods[$test_name])) {
                                    $submitted_test_methods[$test_name] = [];
                                }
                                if (!in_array($method, $submitted_test_methods[$test_name])) {
                                    $submitted_test_methods[$test_name][] = $method;
                                }
                            }
                        }
                    }
                    $related_query->close();
                }
            }
            
            // Store the existing_report in a way that persists for $lock_general_fields check
            // This ensures we have all the data needed to determine if fields should be locked
        } else {
            // Report not editable, redirect to clean URL
            header("Location: qc_test_order.php");
            exit();
        }
    } else {
        // Report not found, redirect to clean URL
        header("Location: qc_test_order.php");
        exit();
    }
} else {
    // Not in edit mode, ensure existing_report is not set
    $existing_report = null;
}

// Lock general fields if editing an external test that was originally created by AGM
// This includes both forwarded tests (pending_tester/pending_checker/pending_approval) 
// and rejected tests (rejected_by_checker/rejected_by_approver) that were created by AGM
$lock_general_fields = false;
$creator_role = '';
$was_created_by_agm = false;
$fetched_role_debug = 'not_checked';
if ($edit_mode && isset($existing_report) && !empty($existing_report)) {
    $test_data_check = json_decode($existing_report['test_data'] ?? '{}', true);
    $is_external = ($existing_report['sample_reference_id'] ?? '') !== '' && 
                   (strpos($existing_report['sample_reference_id'], 'EXT-') === 0 || 
                    strpos($existing_report['sample_reference_id'], 'TOKEN-') === 0 ||
                    (isset($test_data_check['is_external_product']) && ($test_data_check['is_external_product'] == '1' || $test_data_check['is_external_product'] === true)));
    $creator_role = strtolower(trim($existing_report['creator_role'] ?? ''));
    
    // Always fetch creator role directly from database if inspector_id is available
    // This ensures we get the role even if the JOIN didn't work
    // Check both new_user and users tables (system might use either)
    $fetched_role_debug = 'not_fetched';
    if (isset($existing_report['inspector_id']) && $existing_report['inspector_id'] > 0) {
        try {
            // First try new_user table
            $creator_check_stmt = $conn->prepare("SELECT LOWER(TRIM(role)) as role FROM new_user WHERE id = ?");
            if ($creator_check_stmt) {
                $creator_check_stmt->bind_param("i", $existing_report['inspector_id']);
                $creator_check_stmt->execute();
                $creator_result = $creator_check_stmt->get_result();
                if ($creator_row = $creator_result->fetch_assoc()) {
                    $fetched_role = strtolower(trim($creator_row['role'] ?? ''));
                    $fetched_role_debug = $fetched_role ?: 'empty';
                    if (!empty($fetched_role)) {
                        $creator_role = $fetched_role;
                    }
                    $creator_check_stmt->close();
                } else {
                    // User not found in new_user, try users table
                    $fetched_role_debug = 'not_in_new_user';
                    $creator_check_stmt->close();
                    
                    // Try users table
                    $creator_check_stmt2 = $conn->prepare("SELECT LOWER(TRIM(role)) as role FROM users WHERE id = ?");
                    if ($creator_check_stmt2) {
                        $creator_check_stmt2->bind_param("i", $existing_report['inspector_id']);
                        $creator_check_stmt2->execute();
                        $creator_result2 = $creator_check_stmt2->get_result();
                        if ($creator_row2 = $creator_result2->fetch_assoc()) {
                            $fetched_role = strtolower(trim($creator_row2['role'] ?? ''));
                            $fetched_role_debug = $fetched_role ?: 'empty_from_users';
                            if (!empty($fetched_role)) {
                                $creator_role = $fetched_role;
                            }
                        } else {
                            $fetched_role_debug = 'user_not_found_in_both_tables';
                        }
                        $creator_check_stmt2->close();
                    }
                }
            } else {
                $fetched_role_debug = 'prepare_failed: ' . $conn->error;
            }
        } catch (Exception $e) {
            $fetched_role_debug = 'error: ' . $e->getMessage();
        }
    }
    
    $was_created_by_agm = in_array($creator_role, ['agm ops', 'agm operations', 'admin']);
    
    // For external tests: Always assume created by AGM if it has EXT- or TOKEN- prefix
    // This is because external tests are ALWAYS created by AGM in the workflow
    // Even if inspector_id was updated to a tester after submission, the original creator was AGM
    if ($is_external && (strpos($existing_report['sample_reference_id'], 'TOKEN-') === 0 || 
                         strpos($existing_report['sample_reference_id'], 'EXT-') === 0)) {
        $was_created_by_agm = true;
        if (empty($creator_role) || !in_array($creator_role, ['agm ops', 'agm operations', 'admin'])) {
            $fetched_role_debug .= ' (assumed_agm_for_external_prefix)';
        }
    }
    
    // Fallback: If it's an external test and we can't find the user role,
    // assume it was created by AGM (external tests are ALWAYS created by AGM in the workflow)
    // This handles cases where the user might have been deleted or the ID doesn't match
    if ($is_external && empty($creator_role) && $fetched_role_debug !== 'not_checked' && !$was_created_by_agm) {
        // For external tests, if we can't determine the creator, assume AGM
        // This is safe because external tests are only created by AGM in the system workflow
        $was_created_by_agm = true;
        $fetched_role_debug .= ' (assumed_agm_for_external)';
    }
    
    $lock_general_fields = $is_external && $was_created_by_agm;
    
    // Debug logging
    error_log("QC Test Order: lock_general_fields check - edit_mode: " . ($edit_mode ? 'true' : 'false') . 
              ", is_external: " . ($is_external ? 'true' : 'false') . 
              ", sample_ref: " . ($existing_report['sample_reference_id'] ?? 'N/A') . 
              ", creator_role: '" . $creator_role . "'" . 
              ", inspector_id: " . ($existing_report['inspector_id'] ?? 'N/A') . 
              ", was_created_by_agm: " . ($was_created_by_agm ? 'true' : 'false') . 
              ", lock_general_fields: " . ($lock_general_fields ? 'true' : 'false') . 
              ", status: " . ($existing_report['status'] ?? 'N/A'));
}

$external_reference_value = '';
if ($edit_mode) {
    if (!empty($existing_test_data['external_reference'])) {
        $external_reference_value = $existing_test_data['external_reference'];
    } elseif (!empty($existing_report['sample_reference_id']) && strpos($existing_report['sample_reference_id'], 'EXT-') === 0) {
        $external_reference_value = $existing_report['sample_reference_id'];
    }
}

// Determine the currently selected reference (for dropdown display)
$current_reference_selection = $preselected_reference;
if ($edit_mode) {
    if (!empty($existing_test_data['product_reference'])) {
        $current_reference_selection = $existing_test_data['product_reference'];
    } elseif (!empty($existing_test_data['external_reference'])) {
        $current_reference_selection = $existing_test_data['external_reference'];
    } elseif (!empty($existing_report['sample_reference_id'])) {
        $current_reference_selection = $existing_report['sample_reference_id'];
    }
}

// Value to show for read-only external reference fields
$external_reference_display = $external_reference_value;
if (empty($external_reference_display) && !empty($existing_report['sample_reference_id'])) {
    $external_reference_display = $existing_report['sample_reference_id'];
}

$sample_received_datetime_value = '';
if ($edit_mode && !empty($existing_test_data['sample_received_datetime'])) {
    try {
        $dtTmp = new DateTime($existing_test_data['sample_received_datetime']);
        $sample_received_datetime_value = htmlspecialchars($dtTmp->format('Y-m-d\TH:i'));
    } catch (Exception $e) {
        $sample_received_datetime_value = htmlspecialchars($existing_test_data['sample_received_datetime']);
    }
}

$product_type_default = 'production';
if (isset($_POST['is_external_product'])) {
    $product_type_default = ($_POST['is_external_product'] == '1') ? 'external' : 'production';
} elseif ($lock_general_fields || ($edit_mode && (($existing_test_data['is_external_product'] ?? false) || $edit_external_forward || (isset($existing_report['sample_reference_id']) && strpos($existing_report['sample_reference_id'], 'EXT-') === 0)))) {
    $product_type_default = 'external';
}

// Fetch reference numbers from roll_entry (each roll individually for QC testing)
$references = [];
$bundleReferences = []; // Store bundle references separately

// Check if number_of_rolls column exists
$checkCol = $conn->query("SHOW COLUMNS FROM roll_entry LIKE 'number_of_rolls'");
$hasNumberOfRolls = ($checkCol && $checkCol->num_rows > 0);

// Check if roll_destination column exists in qc_test_orders table
$checkRollDestCol = $conn->query("SHOW COLUMNS FROM qc_test_orders LIKE 'roll_destination'");
$hasRollDestination = ($checkRollDestCol && $checkRollDestCol->num_rows > 0);

// Helper function to check if a reference has been routed
$isReferenceRouted = function($conn, $reference, $hasRollDestination) {
    if (!$hasRollDestination) {
        return false;
    }
    
    // Check exact match first (most reliable)
    $stmt = $conn->prepare("
        SELECT 1 
        FROM qc_test_orders 
        WHERE sample_reference_id = ? 
        AND status = 'approved' 
        AND roll_destination IS NOT NULL 
        AND roll_destination != ''
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $result = $stmt->get_result();
        $routed = ($result && $result->num_rows > 0);
        $stmt->close();
        if ($routed) {
            return true;
        }
    }
    
    // Also check if the stored reference is a prefix of the query reference
    // e.g., stored: "2.0L126JAN16-R06" matches query: "2.0L126JAN16-R06-GT0.9.H0.1"
    $stmt = $conn->prepare("
        SELECT 1 
        FROM qc_test_orders 
        WHERE ? LIKE CONCAT(sample_reference_id, '%')
        AND status = 'approved' 
        AND roll_destination IS NOT NULL 
        AND roll_destination != ''
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $result = $stmt->get_result();
        $routed = ($result && $result->num_rows > 0);
        $stmt->close();
        if ($routed) {
            return true;
        }
    }
    
    // Also check if the query reference is a prefix of stored reference
    // e.g., query: "2.0L126JAN16-R06" matches stored: "2.0L126JAN16-R06-GT0.9.H0.1"
    $stmt = $conn->prepare("
        SELECT 1 
        FROM qc_test_orders 
        WHERE sample_reference_id LIKE CONCAT(?, '%')
        AND status = 'approved' 
        AND roll_destination IS NOT NULL 
        AND roll_destination != ''
        LIMIT 1
    ");
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

$refQuery = $conn->query("
    SELECT reference_number, 
           MAX(material_type) as material_type, 
           MAX(total_weight) as total_weight, 
           MAX(created_at) as created_at" . 
           ($hasNumberOfRolls ? ", MAX(number_of_rolls) as number_of_rolls" : "") . "
    FROM roll_entry 
    WHERE reference_number IS NOT NULL 
    GROUP BY reference_number
    ORDER BY created_at DESC 
    LIMIT 200
");
if ($refQuery) {
    while ($row = $refQuery->fetch_assoc()) {
        $ref = $row['reference_number'];
        
        // Check if this is a bundle reference (ends with -N pattern where N is a number)
        // Pattern: e.g., "3.0L225NOV09-R03-GT0.9.H0.1-4" where -4 indicates 4 rolls
        if (preg_match('/-(\d+)$/', $ref, $matches)) {
            $rollCount = (int)$matches[1];
            $baseRef = preg_replace('/-\d+$/', '', $ref);
            
            // Check if the bundle reference itself has been routed
            $bundleRouted = $isReferenceRouted($conn, $ref, $hasRollDestination);
            
            // Check how many individual rolls have been routed
            $routedRollCount = 0;
            $routedRolls = [];
            for ($i = 1; $i <= $rollCount; $i++) {
                $individualRef = $baseRef . '-' . $i;
                if ($isReferenceRouted($conn, $individualRef, $hasRollDestination)) {
                    $routedRollCount++;
                    $routedRolls[] = $i;
                }
            }
            
            // Only add bundle if not all rolls are routed and bundle itself is not routed
            if (!$bundleRouted && $routedRollCount < $rollCount) {
                // Store as bundle (only if it has at least one non-routed roll)
                $bundleReferences[] = [
                    'reference' => $ref,
                    'base_reference' => $baseRef,
                    'roll_count' => $rollCount,
                    'material_type' => $row['material_type'],
                    'weight' => $row['total_weight'],
                    'date' => $row['created_at']
                ];
                
                // Add individual roll references for selection (only if not routed)
                for ($i = 1; $i <= $rollCount; $i++) {
                    $individualRef = $baseRef . '-' . $i;
                    
                    // Check if this individual roll reference has been routed
                    $isRouted = $isReferenceRouted($conn, $individualRef, $hasRollDestination);
                    
                    // Only add if not routed
                    if (!$isRouted) {
                        $references[] = [
                            'reference' => $individualRef,
                            'bundle_reference' => $ref,
                            'roll_number' => $i,
                            'is_individual' => true,
                            'material_type' => $row['material_type'],
                            'weight' => $row['total_weight'] / $rollCount, // Approximate weight per roll
                            'date' => $row['created_at']
                        ];
                    }
                }
            }
        } else {
            // Single roll reference (not a bundle)
            // Check if this reference has been routed
            $isRouted = $isReferenceRouted($conn, $ref, $hasRollDestination);
            
            // Only add if not routed
            if (!$isRouted) {
                $references[] = [
                    'reference' => $ref,
                    'material_type' => $row['material_type'],
                    'weight' => $row['total_weight'],
                    'date' => $row['created_at'],
                    'is_individual' => false
                ];
            }
        }
    }
}

// Define the specific test methods as requested
$test_methods = [
    'Thickness (Under 2kPa Pressure)' => ['ASTM D5199', 'ISO 9863-1'],
    'Mass Per Unit Area (GSM)' => ['ASTM D5261', 'ISO 9864'],
    'Strip Tensile Test' => ['ASTM D4595', 'ISO 10319'],
    'CBR Puncture Resistance' => ['ASTM D6241', 'ISO 12236'],
    'Grab Tensile Test' => ['ASTM D4632'],
    'Weathering Exposure Test' => ['ASTM D4533'],
    'Seam/Joint Test' => ['ISO 10321']
];

// Determine which tests to display
// Default: show all tests for both production and external products
$display_test_methods = $test_methods;

$locked_test_name = '';
$locked_test_method = '';

// Restrict test display when:
// 1. Editing an external forwarded test (pending_tester/pending_checker/pending_approval)
// 2. OR editing a rejected external report (rejected_by_checker/rejected_by_approver)
if ($edit_mode && isset($existing_report) && !empty($existing_report)) {
    // Check if it's an external product
    $test_data_check = json_decode($existing_report['test_data'] ?? '{}', true);
    $is_external_edit = ($existing_report['sample_reference_id'] ?? '') !== '' && 
                       (strpos($existing_report['sample_reference_id'], 'EXT-') === 0 || 
                        strpos($existing_report['sample_reference_id'], 'TOKEN-') === 0 ||
                        (isset($test_data_check['is_external_product']) && ($test_data_check['is_external_product'] == '1' || $test_data_check['is_external_product'] === true)));
    
    // Check if it's a forwarded test or rejected test
    $is_forwarded = $edit_external_forward;
    $is_rejected = in_array($existing_report['status'], ['rejected_by_checker', 'rejected_by_approver']);
    
    // If it's an external test and either forwarded or rejected, show only the original test
    if ($is_external_edit && ($is_forwarded || $is_rejected)) {
        $locked_test_name = $existing_report['test_name'] ?? ($existing_test_data['test_name'] ?? '');
        $locked_test_method = $existing_report['chosen_method'] ?? ($existing_test_data['chosen_method'] ?? '');
        if ($locked_test_name && isset($test_methods[$locked_test_name])) {
            $methods = $test_methods[$locked_test_name];
            if ($locked_test_method && in_array($locked_test_method, $methods, true)) {
                $methods = [$locked_test_method];
            }
            $display_test_methods = [
                $locked_test_name => $methods
            ];
        }
    }
}
$total_available_tests = array_sum(array_map('count', $display_test_methods));

// Define which tests require checker approval (first 5 tests only)
$tests_requiring_checker = [
    'Thickness (Under 2kPa Pressure)',
    'Mass Per Unit Area (GSM)',
    'Strip Tensile Test',
    'CBR Puncture Resistance',
    'Grab Tensile Test'
];
// Tests that bypass checker: None (all tests now require checker approval)

// Load user's last used values from database for auto-fill
$user_qc_prefs = [];
try {
    // First check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'user_qc_preferences'");
    if ($table_check && $table_check->num_rows > 0) {
        $pref_query = $conn->prepare("SELECT field_name, field_value FROM user_qc_preferences WHERE user_id = ?");
        $pref_query->bind_param("i", $reporter_id);
        $pref_query->execute();
        $pref_result = $pref_query->get_result();
        while ($pref_row = $pref_result->fetch_assoc()) {
            $user_qc_prefs[$pref_row['field_name']] = $pref_row['field_value'];
        }
        $pref_query->close();
        
        error_log("QC Test Order: Loaded " . count($user_qc_prefs) . " preferences for user {$reporter_id}");
    } else {
        error_log("QC Test Order: user_qc_preferences table does not exist");
    }
} catch (Exception $e) {
    error_log("QC Test Order: Error loading preferences - " . $e->getMessage());
}

// Merge with session data (session takes precedence)
$session_data = $_SESSION['qc_last_general'] ?? [];
$user_qc_prefs = array_merge($user_qc_prefs, $session_data);

error_log("QC Test Order: Final prefs count = " . count($user_qc_prefs) . " (DB: " . (count($user_qc_prefs) - count($session_data)) . " + Session: " . count($session_data) . ")");

// Fetch existing QC test orders to prevent duplicates
// Build a map of reference_number => [test_methods]
$existing_tests = [];
$qctoColsExist = [];
$colResExist = $conn->query("SHOW COLUMNS FROM qc_test_orders");
if ($colResExist) {
    while ($r = $colResExist->fetch_assoc()) {
        $qctoColsExist[] = strtolower($r['Field']);
    }
}
$hasStatusExist = in_array('status', $qctoColsExist, true);
$statusFilterExist = $hasStatusExist ? "WHERE status NOT IN ('rejected_by_checker', 'rejected_by_approver')" : "";
$existingQuery = $conn->query("
    SELECT sample_reference_id, chosen_method, test_data 
    FROM qc_test_orders 
    $statusFilterExist
");
if ($existingQuery) {
    while ($row = $existingQuery->fetch_assoc()) {
        $ref = $row['sample_reference_id'];
        $method = $row['chosen_method'];
        
        // Also check test_data JSON for product_reference, fiber_reference_no, yarn_reference_no
        $test_data = json_decode($row['test_data'], true);
        $additional_refs = [];
        if (isset($test_data['product_reference'])) {
            $additional_refs[] = $test_data['product_reference'];
        }
        if (isset($test_data['fiber_reference_no'])) {
            $additional_refs[] = $test_data['fiber_reference_no'];
        }
        if (isset($test_data['yarn_reference_no'])) {
            $additional_refs[] = $test_data['yarn_reference_no'];
        }
        
        // Add all references with this method
        $all_refs = array_merge([$ref], $additional_refs);
        foreach ($all_refs as $reference) {
            if (!empty($reference)) {
                if (!isset($existing_tests[$reference])) {
                    $existing_tests[$reference] = [];
                }
                if (!in_array($method, $existing_tests[$reference])) {
                    $existing_tests[$reference][] = $method;
                }
            }
        }
    }
}

// Handle form submission (both new and edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_order'])) {
    try {
        $is_editing = isset($_POST['edit_id']) && (int)$_POST['edit_id'] > 0;
        $edit_id_post = $is_editing ? (int)$_POST['edit_id'] : 0;
        
        // Check if this is a forwarded external test (for message and redirect)
        // A forwarded external test is one that:
        // 1. Is an external product (EXT- prefix or is_external_product flag)
        // 2. Has status pending_checker or pending_approval (not rejected)
        // 3. Was created by AGM/admin (not by the current tester)
        $is_forwarded_external_submit = false;
        $original_status = null; // Store original status for message determination
        if ($is_editing && $edit_id_post > 0) {
            $check_stmt = $conn->prepare("
                SELECT qto.test_data, qto.sample_reference_id, qto.status, qto.inspector_id,
                       LOWER(TRIM(u.role)) as creator_role
                FROM qc_test_orders qto
                LEFT JOIN new_user u ON qto.inspector_id = u.id
                WHERE qto.id = ?
            ");
            $check_stmt->bind_param("i", $edit_id_post);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_row = $check_result->fetch_assoc()) {
                $original_status = $check_row['status']; // Store original status
                $check_test_data = json_decode($check_row['test_data'] ?? '{}', true);
                $is_external = (strpos($check_row['sample_reference_id'], 'EXT-') === 0 || 
                               strpos($check_row['sample_reference_id'], 'TOKEN-') === 0 ||
                               (isset($check_test_data['is_external_product']) && $check_test_data['is_external_product'] == '1'));
                
                // Check if original creator was AGM/admin
                $creator_role = strtolower(trim($check_row['creator_role'] ?? ''));
                $was_created_by_agm = in_array($creator_role, ['agm ops', 'agm operations', 'admin']);
                
                // Check if current user is different from creator (tester submitting test created by AGM)
                $is_different_user = ($check_row['inspector_id'] != $reporter_id);
                
                // SIMPLE: If status is pending_tester and current user is different from creator, it's a forwarded test
                // This is the most reliable indicator - pending_tester status means AGM forwarded it
                $is_forwarded_external_submit = ($check_row['status'] === 'pending_tester') && $is_different_user;
                
                // Also detect rejected external tests - these are forwarded tests that were rejected
                // Any tester should be able to resubmit rejected external tests
                if (!$is_forwarded_external_submit && $is_external && 
                    in_array($check_row['status'], ['rejected_by_checker', 'rejected_by_approver'])) {
                    $is_forwarded_external_submit = true;
                }
                
                // Fallback: Also check the old logic for other pending statuses
                if (!$is_forwarded_external_submit) {
                    $is_forwarded_external_submit = $is_external && 
                                                    in_array($check_row['status'], ['pending_checker', 'pending_approval']) &&
                                                    $was_created_by_agm &&
                                                    $is_different_user;
                }
                
                // Debug logging
                error_log("QC Test Order: Edit mode check - is_external: " . ($is_external ? 'true' : 'false') . 
                         ", status: " . $check_row['status'] . 
                         ", was_created_by_agm: " . ($was_created_by_agm ? 'true' : 'false') . 
                         ", is_different_user: " . ($is_different_user ? 'true' : 'false') . 
                         ", is_forwarded_external_submit: " . ($is_forwarded_external_submit ? 'true' : 'false'));
            }
            $check_stmt->close();
        }
        
        $sample_reference_id = trim($_POST['sample_reference_id']);
        
        if (empty($sample_reference_id)) {
            throw new Exception("Sample Reference ID is required.");
        }
        
        // Check if at least one test method is selected
        // For external products: allow multiple tests
        // For production products: enforce exactly one
        $selected_methods = [];
        foreach ($test_methods as $test_name => $methods) {
            foreach ($methods as $method) {
                $field_name = 'test_' . md5($test_name . '_' . $method);
                if (isset($_POST[$field_name])) {
                    $selected_methods[] = [
                        'test_name' => $test_name,
                        'method' => $method
                    ];
                }
            }
        }
        
        
        if (empty($selected_methods)) {
            throw new Exception("Please select at least one test method.");
        }
        
        // Check if this is an external product
        $is_external_product = isset($_POST['is_external_product']) && $_POST['is_external_product'] == '1';
        
        // For production products, enforce exactly one test
        // For external products (AGM), allow multiple tests
        if (!$is_external_product && count($selected_methods) !== 1) {
            throw new Exception("Select exactly one test method before submitting.");
        }
        
        // Generate proper sample reference ID
        if ($is_external_product && isset($_POST['external_reference']) && !empty($_POST['external_reference'])) {
            // For external products, use the external reference as sample_reference_id
            $generated_sample_ref = $_POST['external_reference'];
            error_log("QC Test Order: Using external reference as sample_reference_id: " . $generated_sample_ref);
        } else {
            // For production products, generate with sequence
            $generated_sample_ref = generateSampleReferenceId();
        }
        
        // Get the user's selected reference number (for fetching in summary report)
        $user_reference = '';
        
        if ($is_external_product && isset($_POST['external_reference']) && !empty($_POST['external_reference'])) {
            // External product - use auto-generated external reference
            $user_reference = $_POST['external_reference'];
            error_log("QC Test Order: Using external reference: " . $user_reference);
        } elseif (isset($_POST['individual_roll_reference']) && !empty($_POST['individual_roll_reference'])) {
            // Individual roll from bundle - use this for individual testing
            $user_reference = $_POST['individual_roll_reference'];
            error_log("QC Test Order: Using individual roll reference from bundle: " . $user_reference);
        } elseif (isset($_POST['product_reference']) && !empty($_POST['product_reference'])) {
            $user_reference = $_POST['product_reference'];
        } elseif (isset($_POST['fiber_reference_no']) && !empty($_POST['fiber_reference_no'])) {
            $user_reference = $_POST['fiber_reference_no'];
        } elseif (isset($_POST['yarn_reference_no']) && !empty($_POST['yarn_reference_no'])) {
            $user_reference = $_POST['yarn_reference_no'];
        }
        
        // Update the sample reference ID in the form for display
        $_POST['sample_reference_id'] = $generated_sample_ref;
        
        // Ensure test_standards table exists (minimal schema) and fetch/create IDs on demand
        $conn->query("CREATE TABLE IF NOT EXISTS test_standards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            test_name VARCHAR(255) NOT NULL,
            standard_code VARCHAR(100) NOT NULL,
            UNIQUE KEY uniq_test_standard (test_name, standard_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $ensureTestStandard = function($conn, $testName, $method) {
            $stmt = $conn->prepare("SELECT id FROM test_standards WHERE test_name = ? AND standard_code = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("ss", $testName, $method);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $row = $res->fetch_assoc()) {
                    $stmt->close();
                    return (int)$row['id'];
                }
                $stmt->close();
            }
            // Insert if not found
            $stmt = $conn->prepare("INSERT INTO test_standards (test_name, standard_code) VALUES (?, ?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)");
            if ($stmt) {
                $stmt->bind_param("ss", $testName, $method);
                if ($stmt->execute()) {
                    $newId = $conn->insert_id;
                    $stmt->close();
                    return (int)$newId;
                }
                $stmt->close();
            }
            return null;
        };

        // Ensure qc_test_orders table exists and has required columns (self-heal legacy schemas)
        $conn->query("CREATE TABLE IF NOT EXISTS qc_test_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sample_reference_id VARCHAR(255),
            report_number VARCHAR(255) UNIQUE,
            test_standard_id INT,
            chosen_method VARCHAR(255),
            test_data JSON,
            inspector_id INT,
            inspector_name VARCHAR(255),
            status VARCHAR(50),
            checked_by VARCHAR(255),
            checked_at DATETIME,
            checker_remarks TEXT,
            approved_by VARCHAR(255),
            approved_at DATETIME,
            admin_remarks TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Helper to add missing columns without failing if they already exist
        $ensureColumn = function($name, $definition) use ($conn) {
            @$conn->query("ALTER TABLE qc_test_orders ADD COLUMN IF NOT EXISTS {$name} {$definition}");
        };
        $ensureColumn('sample_reference_id', "VARCHAR(255) NULL");
        $ensureColumn('report_number', "VARCHAR(255) NULL");
        $ensureColumn('test_standard_id', "INT NULL");
        $ensureColumn('chosen_method', "VARCHAR(255) NULL");
        $ensureColumn('test_data', "JSON NULL");
        $ensureColumn('inspector_id', "INT NULL");
        $ensureColumn('inspector_name', "VARCHAR(255) NULL");
        $ensureColumn('status', "VARCHAR(50) NULL");
        $ensureColumn('checked_by', "VARCHAR(255) NULL");
        $ensureColumn('checked_at', "DATETIME NULL");
        $ensureColumn('checker_remarks', "TEXT NULL");
        $ensureColumn('approved_by', "VARCHAR(255) NULL");
        $ensureColumn('approved_at', "DATETIME NULL");
        $ensureColumn('admin_remarks', "TEXT NULL");
        $ensureColumn('updated_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
        $ensureColumn('created_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

        $conn->begin_transaction();
        
        // Check for bulk reference selection (from From/To reference dropdowns when line is selected)
        $bulk_rolls = [];
        if (isset($_POST['from_reference']) && isset($_POST['to_reference']) && 
            !empty($_POST['from_reference']) && !empty($_POST['to_reference'])) {
            
            $fromRef = $_POST['from_reference'];
            $toRef = $_POST['to_reference'];
            
            // Determine line filter from the reference
            $lineFilter = '';
            if (strpos($fromRef, 'L1') !== false) {
                $lineFilter = 'L1';
            } elseif (strpos($fromRef, 'L2') !== false) {
                $lineFilter = 'L2';
            }
            
            // Fetch all references for this line, ordered alphabetically
            if ($lineFilter) {
                $linePattern = '%' . $lineFilter . '%';
                $stmt_line = $conn->prepare("
                    SELECT DISTINCT reference_number 
                    FROM roll_entry 
                    WHERE reference_number IS NOT NULL 
                    AND reference_number LIKE ?
                    ORDER BY reference_number ASC
                ");
                $stmt_line->bind_param("s", $linePattern);
                $stmt_line->execute();
                $result_line = $stmt_line->get_result();
                
                $allRefs = [];
                while ($row = $result_line->fetch_assoc()) {
                    $allRefs[] = $row['reference_number'];
                }
                $stmt_line->close();
                
                // Extract base references and roll numbers from From and To
                $fromBaseRef = '';
                $fromRollNum = 0;
                $toBaseRef = '';
                $toRollNum = 0;
                
                // Extract base reference and roll number from From value
                if (preg_match('/^(.+)-(\d+)$/', $fromRef, $fromMatches)) {
                    $fromBaseRef = $fromMatches[1];
                    $fromRollNum = (int)$fromMatches[2];
                } else {
                    $fromBaseRef = $fromRef;
                    $fromRollNum = 1;
                }
                
                // Extract base reference and roll number from To value
                if (preg_match('/^(.+)-(\d+)$/', $toRef, $toMatches)) {
                    $toBaseRef = $toMatches[1];
                    $toRollNum = (int)$toMatches[2];
                } else {
                    $toBaseRef = $toRef;
                    $toRollNum = 1;
                }
                
                // Check if From and To are from the same bundle
                if ($fromBaseRef === $toBaseRef && $fromRollNum > 0 && $toRollNum > 0) {
                    // Same bundle - include ALL rolls from the bundle (from roll 1 to the last roll)
                    // First, find the bundle to get the total roll count
                    $stmt_bundle = $conn->prepare("
                        SELECT reference_number 
                        FROM roll_entry 
                        WHERE reference_number LIKE ? 
                        AND reference_number REGEXP '-[0-9]+$'
                        ORDER BY LENGTH(reference_number) DESC, reference_number DESC
                        LIMIT 1
                    ");
                    $bundlePattern = $fromBaseRef . '-%';
                    $stmt_bundle->bind_param("s", $bundlePattern);
                    $stmt_bundle->execute();
                    $result_bundle = $stmt_bundle->get_result();
                    
                    $bundleRollCount = max($fromRollNum, $toRollNum);
                    if ($result_bundle && $row_bundle = $result_bundle->fetch_assoc()) {
                        $bundleRef = $row_bundle['reference_number'];
                        if (preg_match('/-(\d+)$/', $bundleRef, $bundleMatches)) {
                            $potentialCount = (int)$bundleMatches[1];
                            // If the bundle reference ends with a number > 1, it might be the roll count
                            // Check if there are individual roll entries
                            $stmt_check = $conn->prepare("
                                SELECT COUNT(DISTINCT reference_number) as roll_count
                                FROM roll_entry 
                                WHERE reference_number LIKE ?
                                AND reference_number REGEXP '-[0-9]+$'
                            ");
                            $checkPattern = $fromBaseRef . '-%';
                            $stmt_check->bind_param("s", $checkPattern);
                            $stmt_check->execute();
                            $result_check = $stmt_check->get_result();
                            if ($result_check && $row_check = $result_check->fetch_assoc()) {
                                $actualCount = (int)$row_check['roll_count'];
                                if ($actualCount > $bundleRollCount) {
                                    $bundleRollCount = $actualCount;
                                } else {
                                    $bundleRollCount = max($bundleRollCount, $potentialCount);
                                }
                            }
                            $stmt_check->close();
                        }
                    }
                    $stmt_bundle->close();
                    
                    // Include all rolls from 1 to bundleRollCount
                    for ($roll = 1; $roll <= $bundleRollCount; $roll++) {
                        $bulk_rolls[] = $fromBaseRef . '-' . $roll;
                    }
                } else {
                    // Different bundles or references - process serially
                    $fromIndex = array_search($fromRef, $allRefs);
                    $toIndex = array_search($toRef, $allRefs);
                    
                    if ($fromIndex !== false && $toIndex !== false && $fromIndex <= $toIndex) {
                        // Process each reference in the range
                        for ($i = $fromIndex; $i <= $toIndex; $i++) {
                            $ref = $allRefs[$i];
                            
                            // Extract base reference and roll number
                            $refBaseRef = '';
                            $refRollNum = 0;
                            if (preg_match('/^(.+)-(\d+)$/', $ref, $refMatches)) {
                                $refBaseRef = $refMatches[1];
                                $refRollNum = (int)$refMatches[2];
                            } else {
                                $refBaseRef = $ref;
                                $refRollNum = 1;
                            }
                            
                            // Check if we need to generate serial references for this base
                            // If this is the first reference and there are more in the range with the same base
                            $needsSerial = false;
                            $endRollNum = $refRollNum;
                            
                            if ($i === $fromIndex) {
                                // Check if To reference has the same base
                                if ($refBaseRef === $toBaseRef) {
                                    $needsSerial = true;
                                    $endRollNum = $toRollNum;
                                } else {
                                    // Check if any reference in the range has the same base
                                    for ($j = $i + 1; $j <= $toIndex; $j++) {
                                        $checkRef = $allRefs[$j];
                                        $checkBaseRef = '';
                                        $checkRollNum = 0;
                                        if (preg_match('/^(.+)-(\d+)$/', $checkRef, $checkMatches)) {
                                            $checkBaseRef = $checkMatches[1];
                                            $checkRollNum = (int)$checkMatches[2];
                                        } else {
                                            $checkBaseRef = $checkRef;
                                            $checkRollNum = 1;
                                        }
                                        
                                        if ($checkBaseRef === $refBaseRef) {
                                            $needsSerial = true;
                                            if ($checkRollNum > $endRollNum) {
                                                $endRollNum = $checkRollNum;
                                            }
                                        } else if ($needsSerial) {
                                            // Found a different base, stop
                                            break;
                                        }
                                    }
                                }
                            }
                            
                            if ($needsSerial && $endRollNum > $refRollNum) {
                                // Generate all serial references from refRollNum to endRollNum
                                for ($roll = $refRollNum; $roll <= $endRollNum; $roll++) {
                                    $bulk_rolls[] = $refBaseRef . '-' . $roll;
                                }
                            } else {
                                // Single reference - check if it's part of a bundle
                                $stmt_bundle = $conn->prepare("
                                    SELECT reference_number 
                                    FROM roll_entry 
                                    WHERE reference_number LIKE ? 
                                    AND reference_number REGEXP '-[0-9]+$'
                                    ORDER BY LENGTH(reference_number) DESC, reference_number DESC
                                    LIMIT 1
                                ");
                                $bundlePattern = $refBaseRef . '-%';
                                $stmt_bundle->bind_param("s", $bundlePattern);
                                $stmt_bundle->execute();
                                $result_bundle = $stmt_bundle->get_result();
                                
                                $bundleRollCount = 1;
                                if ($result_bundle && $row_bundle = $result_bundle->fetch_assoc()) {
                                    $bundleRef = $row_bundle['reference_number'];
                                    if (preg_match('/-(\d+)$/', $bundleRef, $bundleMatches)) {
                                        $potentialCount = (int)$bundleMatches[1];
                                        // Check actual count of individual rolls
                                        $stmt_check = $conn->prepare("
                                            SELECT COUNT(DISTINCT reference_number) as roll_count
                                            FROM roll_entry 
                                            WHERE reference_number LIKE ?
                                            AND reference_number REGEXP '-[0-9]+$'
                                        ");
                                        $checkPattern = $refBaseRef . '-%';
                                        $stmt_check->bind_param("s", $checkPattern);
                                        $stmt_check->execute();
                                        $result_check = $stmt_check->get_result();
                                        if ($result_check && $row_check = $result_check->fetch_assoc()) {
                                            $actualCount = (int)$row_check['roll_count'];
                                            $bundleRollCount = max($actualCount, $potentialCount);
                                        } else {
                                            $bundleRollCount = $potentialCount;
                                        }
                                        $stmt_check->close();
                                    }
                                }
                                $stmt_bundle->close();
                                
                                // If it's a bundle (rollCount > 1), add all individual rolls
                                if ($bundleRollCount > 1) {
                                    for ($roll = 1; $roll <= $bundleRollCount; $roll++) {
                                        $bulk_rolls[] = $refBaseRef . '-' . $roll;
                                    }
                                } else {
                                    // Single roll reference
                                    $bulk_rolls[] = $ref;
                                }
                            }
                        }
                    }
                }
            }
        } elseif (isset($_POST['from_roll']) && isset($_POST['to_roll']) && 
            !empty($_POST['from_roll']) && !empty($_POST['to_roll']) &&
            isset($_POST['product_reference']) && !empty($_POST['product_reference'])) {
            
            // Legacy support for roll-based bulk selection (when "All Lines" is selected)
            $productRefSelect = $_POST['product_reference'];
            $stmt_check = $conn->prepare("SELECT reference_number FROM roll_entry WHERE reference_number = ? LIMIT 1");
            $stmt_check->bind_param("s", $productRefSelect);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            if ($result_check && $result_check->num_rows > 0) {
                $baseRef = preg_replace('/-\d+$/', '', $productRefSelect);
                $fromRoll = (int)$_POST['from_roll'];
                $toRoll = (int)$_POST['to_roll'];
                
                for ($i = $fromRoll; $i <= $toRoll; $i++) {
                    $bulk_rolls[] = $baseRef . '-' . $i;
                }
            }
            $stmt_check->close();
        }
        
        $inserted_count = 0;
        
        // If bulk reference range is selected but $bulk_rolls is empty, generate individual references from the range
        if (empty($bulk_rolls) && isset($_POST['from_reference']) && isset($_POST['to_reference']) && 
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
                error_log("QC Test Order: Generated " . count($bulk_rolls) . " individual references from range: " . $fromRef . " to " . $toRef);
            } else {
                // Different bases - add both endpoints and log warning
                $bulk_rolls[] = $fromRef;
                if ($toRef !== $fromRef) {
                    $bulk_rolls[] = $toRef;
                }
                error_log("QC Test Order: WARNING - Different base references in range. Generated " . count($bulk_rolls) . " references.");
            }
        }
        
        // If bulk rolls are selected, process each roll separately
        $rolls_to_process = !empty($bulk_rolls) ? $bulk_rolls : [null]; // null means process single reference
        $original_user_ref = $user_reference;
        
        // Track submission results for bulk references
        $bulk_submission_results = [
            'submitted' => [],
            'skipped' => [],
            'errors' => []
        ];
        
        foreach ($rolls_to_process as $bulk_roll_ref) {
            // If processing bulk, temporarily set the individual roll reference
            $original_individual_ref = $_POST['individual_roll_reference'] ?? '';
            if ($bulk_roll_ref) {
                $_POST['individual_roll_reference'] = $bulk_roll_ref;
                $user_reference = $bulk_roll_ref; // Update user_reference for this roll
            }
            
            foreach ($selected_methods as $selected) {
            // Find or create the test_standard_id
            $test_standard_id = $ensureTestStandard($conn, $selected['test_name'], $selected['method']);
            if (!$test_standard_id) {
                // Could not resolve standard, log and skip
                $debug_msg = "=== QC Test Order ERROR ===\n";
                $debug_msg .= "Test standard NOT FOUND/CREATED for: " . $selected['test_name'] . " - " . $selected['method'] . "\n";
                file_put_contents('qc_debug.txt', $debug_msg, FILE_APPEND);
                error_log("QC Test Order: Test standard not found/created for " . $selected['test_name'] . " - " . $selected['method']);
                continue; // Skip this test
            }
            
            // Prepare test data JSON
            // If editing, start with existing test_data to preserve all fields
            $test_data = [];
            if ($is_editing && $edit_id_post > 0 && isset($existing_test_data) && is_array($existing_test_data)) {
                $test_data = $existing_test_data; // Start with existing data
            }
            
            // IMPORTANT: Save bulk reference information BEFORE processing individual test data
            // This ensures it's saved for ALL tests in the range, not just when bulk_rolls is populated
            if (isset($_POST['from_reference']) && isset($_POST['to_reference']) && 
                !empty($_POST['from_reference']) && !empty($_POST['to_reference'])) {
                $test_data['is_bulk_reference'] = true;
                $test_data['bulk_from_reference'] = trim($_POST['from_reference']);
                $test_data['bulk_to_reference'] = trim($_POST['to_reference']);
                $test_data['bulk_reference_count'] = !empty($bulk_rolls) ? count($bulk_rolls) : 0;
                error_log("QC Test Order: [EARLY] Saving bulk reference range - From: " . $_POST['from_reference'] . ", To: " . $_POST['to_reference'] . ", Count: " . (!empty($bulk_rolls) ? count($bulk_rolls) : '0'));
            }
            
            // Debug logging to file
            $debug_msg = "=== QC Test Order Debug ===\n";
            $debug_msg .= "Test: " . $selected['test_name'] . " - " . $selected['method'] . "\n";
            $debug_msg .= "Test Standard ID: " . $test_standard_id . "\n";
            $debug_msg .= "product_reference: " . ($_POST['product_reference'] ?? 'NOT SET') . "\n";
            $debug_msg .= "fiber_reference_no: " . ($_POST['fiber_reference_no'] ?? 'NOT SET') . "\n";
            $debug_msg .= "user_reference: " . $user_reference . "\n";
            file_put_contents('qc_debug.txt', $debug_msg, FILE_APPEND);
            
            // Save general form fields to test_data for editing
            $general_fields = [
                'sample_details', 'batch_information', 'sample_collected_from', 
                'sample_received_datetime', 'sample_production_date', 
                'temperature', 'rh_percentage', 'test_period_from', 'test_period_to',
                'roll_number', 'gsm', 'customer_reference', 'sample_received_from',
                'lighthouse_reference', 'other_info'
            ];
            foreach ($general_fields as $field) {
                if (isset($_POST[$field]) && $_POST[$field] !== '') {
                    $test_data[$field] = $_POST[$field];
                }
            }
            
            // Save reference numbers to test_data for filtering
            $test_data['is_external_product'] = $is_external_product;
            
            if ($is_external_product && isset($_POST['external_reference']) && !empty($_POST['external_reference'])) {
                $test_data['external_reference'] = $_POST['external_reference'];
                file_put_contents('qc_debug.txt', "Saved external_reference: " . $_POST['external_reference'] . "\n", FILE_APPEND);
            } elseif (isset($_POST['individual_roll_reference']) && !empty($_POST['individual_roll_reference'])) {
                // Save individual roll reference for bundle testing
                $test_data['product_reference'] = $_POST['individual_roll_reference'];
                $test_data['individual_roll_reference'] = $_POST['individual_roll_reference']; // Store separately for easier retrieval
                if (isset($_POST['product_reference']) && !empty($_POST['product_reference'])) {
                    $test_data['bundle_reference'] = $_POST['product_reference']; // Store bundle reference too
                }
                file_put_contents('qc_debug.txt', "Saved individual_roll_reference: " . $_POST['individual_roll_reference'] . "\n", FILE_APPEND);
            } elseif (isset($_POST['product_reference']) && !empty($_POST['product_reference'])) {
                $test_data['product_reference'] = $_POST['product_reference'];
                file_put_contents('qc_debug.txt', "Saved product_reference: " . $_POST['product_reference'] . "\n", FILE_APPEND);
            }
            
            if (isset($_POST['fiber_reference_no']) && !empty($_POST['fiber_reference_no'])) {
                $test_data['fiber_reference_no'] = $_POST['fiber_reference_no'];
                file_put_contents('qc_debug.txt', "Saved fiber_reference_no: " . $_POST['fiber_reference_no'] . "\n", FILE_APPEND);
            }
            if (isset($_POST['yarn_reference_no']) && !empty($_POST['yarn_reference_no'])) {
                $test_data['yarn_reference_no'] = $_POST['yarn_reference_no'];
                file_put_contents('qc_debug.txt', "Saved yarn_reference_no: " . $_POST['yarn_reference_no'] . "\n", FILE_APPEND);
            }
                
            // Store bulk reference information if this is a bulk submission
            // Save bulk reference information if From/To references are provided
            // This should be saved regardless of whether bulk_rolls is populated
            if (isset($_POST['from_reference']) && isset($_POST['to_reference']) && 
                !empty($_POST['from_reference']) && !empty($_POST['to_reference'])) {
                $test_data['is_bulk_reference'] = true;
                $test_data['bulk_from_reference'] = trim($_POST['from_reference']);
                $test_data['bulk_to_reference'] = trim($_POST['to_reference']);
                $test_data['bulk_reference_count'] = !empty($bulk_rolls) ? count($bulk_rolls) : 0;
                file_put_contents('qc_debug.txt', "Saved bulk reference: " . $_POST['from_reference'] . " to " . $_POST['to_reference'] . " (" . (!empty($bulk_rolls) ? count($bulk_rolls) : '0') . " references)\n", FILE_APPEND);
                error_log("QC Test Order: Saving bulk reference range - From: " . $_POST['from_reference'] . ", To: " . $_POST['to_reference']);
            }
                
                // Collect thickness test data if this is a thickness test
                if ($selected['test_name'] === 'Thickness (Under 2kPa Pressure)') {
                    // Only reset positions if we have new data to add
                    // Preserve existing positions if no new data is submitted
                    $has_new_thickness_data = false;
                    for ($i = 1; $i <= 16; $i++) {
                        $position_key = 'astmd5199_position_' . $i;
                        $value_key = 'astmd5199_under2kpa_' . $i;
                        if (isset($_POST[$position_key]) && isset($_POST[$value_key]) && $_POST[$value_key] !== '') {
                            $has_new_thickness_data = true;
                            break;
                        }
                    }
                    
                    // If we have new data, collect it (this will overwrite existing positions)
                    if ($has_new_thickness_data) {
                        $test_data['positions'] = [];
                        for ($i = 1; $i <= 16; $i++) {
                            $position_key = 'astmd5199_position_' . $i;
                            $value_key = 'astmd5199_under2kpa_' . $i;
                            if (isset($_POST[$position_key]) && isset($_POST[$value_key]) && $_POST[$value_key] !== '') {
                                $test_data['positions'][] = [
                                    'position' => $_POST[$position_key],
                                    'value' => floatval($_POST[$value_key]), // Use 'value' field for consistency
                                    'thickness' => floatval($_POST[$value_key]) // Also store as 'thickness' for compatibility
                                ];
                            }
                        }
                    } else {
                        // No new data - ensure positions array exists (use existing or empty)
                        if (!isset($test_data['positions']) || !is_array($test_data['positions'])) {
                            $test_data['positions'] = [];
                        }
                    }
                    
                    // Collect statistics (always update if provided)
                    if (isset($_POST['astmd5199_avg']) && $_POST['astmd5199_avg'] !== '') {
                        $test_data['average'] = floatval($_POST['astmd5199_avg']);
                        $test_data['sd'] = floatval($_POST['astmd5199_sd'] ?? 0);
                        $test_data['cv'] = floatval($_POST['astmd5199_cv'] ?? 0);
                        $test_data['max'] = floatval($_POST['astmd5199_max'] ?? 0);
                        $test_data['min'] = floatval($_POST['astmd5199_min'] ?? 0);
                    }
                }
                
                // Collect Mass Per Unit Area (GSM) test data
                if ($selected['test_name'] === 'Mass Per Unit Area (GSM)') {
                    $test_data['positions'] = [];
                    for ($i = 1; $i <= 16; $i++) {
                        $position_key = 'gsm_position_' . $i;
                        $value_key = 'gsm_weight_' . $i;
                        $calc_key = 'gsm_calculated_' . $i;
                        
                        // Check if position exists
                        if (isset($_POST[$position_key]) && !empty($_POST[$position_key])) {
                            $position_data = [
                                'position' => $_POST[$position_key]
                            ];
                            
                            // Add weight if provided
                            if (isset($_POST[$value_key]) && $_POST[$value_key] !== '') {
                                $position_data['weight'] = floatval($_POST[$value_key]);
                            }
                            
                            // Add calculated GSM if provided
                            if (isset($_POST[$calc_key]) && $_POST[$calc_key] !== '') {
                                $position_data['gsm'] = floatval($_POST[$calc_key]);
                            }
                            
                            $test_data['positions'][] = $position_data;
                        }
                    }
                    // Collect statistics
                    if (isset($_POST['gsm_avg'])) {
                        $test_data['average'] = floatval($_POST['gsm_avg']);
                        $test_data['sd'] = floatval($_POST['gsm_sd'] ?? 0);
                        $test_data['cv'] = floatval($_POST['gsm_cv'] ?? 0);
                        $test_data['max'] = floatval($_POST['gsm_max'] ?? 0);
                        $test_data['min'] = floatval($_POST['gsm_min'] ?? 0);
                    }
                }
                
                // Collect Strip Tensile Test data
                if ($selected['test_name'] === 'Strip Tensile Test') {
                    $test_data['strip_data'] = [];
                    $i = 1;
                    while (isset($_POST["strip_position_$i"])) {
                        $test_data['strip_data'][] = [
                            'position' => $_POST["strip_position_$i"] ?? '',
                            'direction' => $_POST["strip_direction_$i"] ?? '',
                            'strength' => floatval($_POST["strip_strength_$i"] ?? 0),
                            'elongation' => floatval($_POST["strip_elongation_$i"] ?? 0)
                        ];
                        $i++;
                    }
                    // Collect summary statistics
                    $test_data['summary'] = [
                        'md' => [
                            'strength_avg' => floatval($_POST['strip_md_strength_avg'] ?? 0),
                            'strength_sd' => floatval($_POST['strip_md_strength_sd'] ?? 0),
                            'strength_cv' => floatval($_POST['strip_md_strength_cv'] ?? 0),
                            'strength_max' => floatval($_POST['strip_md_strength_max'] ?? 0),
                            'strength_min' => floatval($_POST['strip_md_strength_min'] ?? 0),
                            'elongation_avg' => floatval($_POST['strip_md_elongation_avg'] ?? 0),
                            'elongation_sd' => floatval($_POST['strip_md_elongation_sd'] ?? 0),
                            'elongation_cv' => floatval($_POST['strip_md_elongation_cv'] ?? 0),
                            'elongation_max' => floatval($_POST['strip_md_elongation_max'] ?? 0),
                            'elongation_min' => floatval($_POST['strip_md_elongation_min'] ?? 0)
                        ],
                        'cd' => [
                            'strength_avg' => floatval($_POST['strip_cd_strength_avg'] ?? 0),
                            'strength_sd' => floatval($_POST['strip_cd_strength_sd'] ?? 0),
                            'strength_cv' => floatval($_POST['strip_cd_strength_cv'] ?? 0),
                            'strength_max' => floatval($_POST['strip_cd_strength_max'] ?? 0),
                            'strength_min' => floatval($_POST['strip_cd_strength_min'] ?? 0),
                            'elongation_avg' => floatval($_POST['strip_cd_elongation_avg'] ?? 0),
                            'elongation_sd' => floatval($_POST['strip_cd_elongation_sd'] ?? 0),
                            'elongation_cv' => floatval($_POST['strip_cd_elongation_cv'] ?? 0),
                            'elongation_max' => floatval($_POST['strip_cd_elongation_max'] ?? 0),
                            'elongation_min' => floatval($_POST['strip_cd_elongation_min'] ?? 0)
                        ]
                    ];
                }
                
                // Collect CBR Puncture Resistance test data
                if ($selected['test_name'] === 'CBR Puncture Resistance') {
                    $test_data['cbr_data'] = [];
                    $i = 1;
                    while (isset($_POST["cbr_position_$i"])) {
                        $test_data['cbr_data'][] = [
                            'position' => $_POST["cbr_position_$i"] ?? '',
                            'force' => floatval($_POST["cbr_force_$i"] ?? 0),
                            'displacement' => floatval($_POST["cbr_displacement_$i"] ?? 0)
                        ];
                        $i++;
                    }
                    // Collect summary statistics
                    $test_data['summary'] = [
                        'force' => [
                            'avg' => floatval($_POST['cbr_force_avg'] ?? 0),
                            'sd' => floatval($_POST['cbr_force_sd'] ?? 0),
                            'cv' => floatval($_POST['cbr_force_cv'] ?? 0),
                            'max' => floatval($_POST['cbr_force_max'] ?? 0),
                            'min' => floatval($_POST['cbr_force_min'] ?? 0)
                        ],
                        'displacement' => [
                            'avg' => floatval($_POST['cbr_displacement_avg'] ?? 0),
                            'sd' => floatval($_POST['cbr_displacement_sd'] ?? 0),
                            'cv' => floatval($_POST['cbr_displacement_cv'] ?? 0),
                            'max' => floatval($_POST['cbr_displacement_max'] ?? 0),
                            'min' => floatval($_POST['cbr_displacement_min'] ?? 0)
                        ]
                    ];
                }
                
                // Collect Grab Tensile Test data
                if ($selected['test_name'] === 'Grab Tensile Test') {
                    $test_data['grab_data'] = [];
                    $i = 1;
                    while (isset($_POST["grab_position_$i"])) {
                        $test_data['grab_data'][] = [
                            'position' => $_POST["grab_position_$i"] ?? '',
                            'direction' => $_POST["grab_direction_$i"] ?? '',
                            'breaking_force' => floatval($_POST["grab_force_$i"] ?? $_POST["grab_breaking_force_$i"] ?? 0),
                            'elongation' => floatval($_POST["grab_elongation_$i"] ?? 0)
                        ];
                        $i++;
                    }
                    // Collect summary statistics
                    $test_data['summary'] = [
                        'md' => [
                            'force_avg' => floatval($_POST['grab_md_force_avg'] ?? 0),
                            'force_sd' => floatval($_POST['grab_md_force_sd'] ?? 0),
                            'force_cv' => floatval($_POST['grab_md_force_cv'] ?? 0),
                            'force_max' => floatval($_POST['grab_md_force_max'] ?? 0),
                            'force_min' => floatval($_POST['grab_md_force_min'] ?? 0),
                            'elongation_avg' => floatval($_POST['grab_md_elongation_avg'] ?? 0),
                            'elongation_sd' => floatval($_POST['grab_md_elongation_sd'] ?? 0),
                            'elongation_cv' => floatval($_POST['grab_md_elongation_cv'] ?? 0),
                            'elongation_max' => floatval($_POST['grab_md_elongation_max'] ?? 0),
                            'elongation_min' => floatval($_POST['grab_md_elongation_min'] ?? 0)
                        ],
                        'cd' => [
                            'force_avg' => floatval($_POST['grab_cd_force_avg'] ?? 0),
                            'force_sd' => floatval($_POST['grab_cd_force_sd'] ?? 0),
                            'force_cv' => floatval($_POST['grab_cd_force_cv'] ?? 0),
                            'force_max' => floatval($_POST['grab_cd_force_max'] ?? 0),
                            'force_min' => floatval($_POST['grab_cd_force_min'] ?? 0),
                            'elongation_avg' => floatval($_POST['grab_cd_elongation_avg'] ?? 0),
                            'elongation_sd' => floatval($_POST['grab_cd_elongation_sd'] ?? 0),
                            'elongation_cv' => floatval($_POST['grab_cd_elongation_cv'] ?? 0),
                            'elongation_max' => floatval($_POST['grab_cd_elongation_max'] ?? 0),
                            'elongation_min' => floatval($_POST['grab_cd_elongation_min'] ?? 0)
                        ]
                    ];
                }
                
            // FINAL CHECK: Ensure bulk reference information is saved before encoding
            // This is critical - the bulk reference info must be in test_data JSON
            if (isset($_POST['from_reference']) && isset($_POST['to_reference']) && 
                !empty($_POST['from_reference']) && !empty($_POST['to_reference'])) {
                $test_data['is_bulk_reference'] = true;
                $test_data['bulk_from_reference'] = trim($_POST['from_reference']);
                $test_data['bulk_to_reference'] = trim($_POST['to_reference']);
                if (!isset($test_data['bulk_reference_count']) || $test_data['bulk_reference_count'] == 0) {
                    $test_data['bulk_reference_count'] = !empty($bulk_rolls) ? count($bulk_rolls) : 0;
                }
                error_log("QC Test Order: [FINAL CHECK] Ensuring bulk reference in test_data - From: " . $_POST['from_reference'] . ", To: " . $_POST['to_reference'] . ", Test: " . ($selected['test_name'] ?? 'N/A'));
            }
                
            $test_data_json = json_encode($test_data);
            
            // Debug: Verify bulk reference is in the JSON
            $test_data_check = json_decode($test_data_json, true);
            if (isset($_POST['from_reference']) && isset($_POST['to_reference']) && 
                !empty($_POST['from_reference']) && !empty($_POST['to_reference'])) {
                if (isset($test_data_check['is_bulk_reference']) && $test_data_check['is_bulk_reference']) {
                    error_log("QC Test Order: [VERIFIED] Bulk reference saved in JSON - From: " . ($test_data_check['bulk_from_reference'] ?? 'MISSING') . ", To: " . ($test_data_check['bulk_to_reference'] ?? 'MISSING'));
                } else {
                    error_log("QC Test Order: [ERROR] Bulk reference NOT in JSON! From: " . $_POST['from_reference'] . ", To: " . $_POST['to_reference']);
                }
            }
            
            // Use user's reference if available, otherwise use generated reference
            // For bulk rolls, ALWAYS use the individual bulk roll reference (each reference gets its own row)
            if (isset($bulk_roll_ref) && $bulk_roll_ref) {
                // This is an individual reference from the bulk range - use it as sample_reference_id
                $final_reference = $bulk_roll_ref;
                error_log("QC Test Order: Using individual bulk roll reference: " . $final_reference);
            } else {
                // Not processing bulk - use user reference or generated reference
                $final_reference = !empty($user_reference) ? $user_reference : $generated_sample_ref;
            }
            
            // Generate report number for this test order
            $report_number = generateReportNumber();
            
            // Determine status based on test type and user role
            global $tests_requiring_checker;
            
            // Initialize status variable
            $status = null;
            
            // Check if this is an edit/resubmission
            if ($is_editing && $edit_id_post > 0) {
                // UPDATING existing report (resubmission after rejection OR forwarded external test)
                // Determine status based on original status and test type
                error_log("QC Test Order: Edit mode - original_status: " . ($original_status ?? 'NULL') . ", is_forwarded_external_submit: " . ($is_forwarded_external_submit ? 'true' : 'false'));
                if ($original_status === 'pending_tester') {
                    // Forwarded external test: tester submits → change to pending_checker
                    $status = in_array($selected['test_name'], $tests_requiring_checker) ? 'pending_checker' : 'pending_approval';
                    error_log("QC Test Order: Status changed from pending_tester to: " . $status);
                } else {
                    // Resubmission after rejection: reset to pending_checker or pending_approval based on test type
                    $status = in_array($selected['test_name'], $tests_requiring_checker) ? 'pending_checker' : 'pending_approval';
                    error_log("QC Test Order: Resubmission - status set to: " . $status);
                    
                    // If this is a resubmission of a rejected test with bulk reference range, also resubmit all other reports in the range
                    if (in_array($original_status, ['rejected_by_checker', 'rejected_by_approver']) && 
                        isset($test_data['is_bulk_reference']) && $test_data['is_bulk_reference'] &&
                        isset($test_data['bulk_from_reference']) && isset($test_data['bulk_to_reference'])) {
                        
                        $bulk_from = $test_data['bulk_from_reference'];
                        $bulk_to = $test_data['bulk_to_reference'];
                        
                        // Find all other reports with the same bulk reference range, test, and method that are also rejected
                        $findRelatedStmt = $conn->prepare("
                            SELECT qto.id, qto.report_number, qto.test_data
                            FROM qc_test_orders qto
                            WHERE qto.test_standard_id = ?
                            AND qto.chosen_method = ?
                            AND qto.id != ?
                            AND qto.status IN ('rejected_by_checker', 'rejected_by_approver')
                        ");
                        $findRelatedStmt->bind_param("isi", $test_standard_id, $selected['method'], $edit_id_post);
                        $findRelatedStmt->execute();
                        $relatedResult = $findRelatedStmt->get_result();
                        
                        $related_ids_to_update = [];
                        while ($row = $relatedResult->fetch_assoc()) {
                            $related_test_data = json_decode($row['test_data'] ?? '{}', true);
                            if (isset($related_test_data['is_bulk_reference']) && $related_test_data['is_bulk_reference'] &&
                                isset($related_test_data['bulk_from_reference']) && isset($related_test_data['bulk_to_reference']) &&
                                $related_test_data['bulk_from_reference'] === $bulk_from &&
                                $related_test_data['bulk_to_reference'] === $bulk_to) {
                                $related_ids_to_update[] = $row['id'];
                            }
                        }
                        $findRelatedStmt->close();
                        
                        // Update all related reports to the same status
                        if (!empty($related_ids_to_update)) {
                            $placeholders = str_repeat('?,', count($related_ids_to_update) - 1) . '?';
                            $updateRelatedStmt = $conn->prepare("
                                UPDATE qc_test_orders 
                                SET status = ?, 
                                    checked_by = NULL, 
                                    checked_at = NULL, 
                                    checker_remarks = NULL,
                                    approved_by = NULL, 
                                    approved_at = NULL, 
                                    admin_remarks = NULL,
                                    updated_at = NOW()
                                WHERE id IN ($placeholders)
                            ");
                            $params = array_merge([$status], $related_ids_to_update);
                            $types = 's' . str_repeat('i', count($related_ids_to_update));
                            $updateRelatedStmt->bind_param($types, ...$params);
                            $updateRelatedStmt->execute();
                            $updateRelatedStmt->close();
                            error_log("QC Test Order: Resubmitted " . count($related_ids_to_update) . " related reports in bulk reference range");
                        }
                    }
                }
                
                // For forwarded external tests, don't check inspector_id (test was created by AGM, submitted by tester)
                // For regular rejected tests, check inspector_id to ensure only owner can resubmit
                if ($is_forwarded_external_submit) {
                    // Forwarded external test: update without checking inspector_id
                    // Also update inspector_name and inspector_id to reflect the tester who submitted it
                    error_log("QC Test Order: Forwarded external submit detected. Original status: " . $original_status . ", New status: " . $status . ", Test ID: " . $edit_id_post);
                    $stmt = $conn->prepare("UPDATE qc_test_orders 
                        SET sample_reference_id = ?, test_standard_id = ?, chosen_method = ?, test_data = ?, 
                            status = ?, inspector_id = ?, inspector_name = ?, 
                            checked_by = NULL, checked_at = NULL, checker_remarks = NULL, 
                            approved_by = NULL, approved_at = NULL, admin_remarks = NULL, updated_at = NOW()
                        WHERE id = ?");
                    $stmt->bind_param("sisssisi", $final_reference, $test_standard_id, $selected['method'], $test_data_json, $status, $reporter_id, $reporter_name, $edit_id_post);
                } else {
                    // Regular rejected test: check inspector_id to ensure only owner can resubmit
                    $stmt = $conn->prepare("UPDATE qc_test_orders 
                        SET sample_reference_id = ?, test_standard_id = ?, chosen_method = ?, test_data = ?, 
                            status = ?, checked_by = NULL, checked_at = NULL, checker_remarks = NULL, 
                            approved_by = NULL, approved_at = NULL, admin_remarks = NULL, updated_at = NOW()
                        WHERE id = ? AND inspector_id = ?");
                    $stmt->bind_param("sisssii", $final_reference, $test_standard_id, $selected['method'], $test_data_json, $status, $edit_id_post, $reporter_id);
                }
            } else {
                // NEW submission
                // Check if this is an external product
                $is_external_product = isset($_POST['is_external_product']) && $_POST['is_external_product'] == '1';
                
                // Also check test_data for external flag (in case POST wasn't set correctly)
                if (!$is_external_product && isset($test_data['is_external_product']) && ($test_data['is_external_product'] == '1' || $test_data['is_external_product'] === true)) {
                    $is_external_product = true;
                }
                
                // Debug logging
                error_log("QC Test Order: is_admin=" . ($is_admin ? 'true' : 'false') . ", is_external_product=" . ($is_external_product ? 'true' : 'false') . ", POST[is_external_product]=" . ($_POST['is_external_product'] ?? 'NOT SET'));
                
                // If admin/AGM Ops submits, auto-approve ONLY for production products
                // For external products, AGM creates order → tester performs tests → checker reviews (same as production)
                if ($is_admin && !$is_external_product) {
                    // Check for duplicate before inserting - match by test_name and method from test_standards
                    $check_duplicate = $conn->prepare("
                        SELECT qto.id, qto.report_number, qto.status, ts.test_name, ts.standard_code
                        FROM qc_test_orders qto
                        INNER JOIN test_standards ts ON qto.test_standard_id = ts.id
                        WHERE qto.sample_reference_id = ? 
                        AND ts.test_name = ?
                        AND ts.standard_code = ?
                        LIMIT 1
                    ");
                    $check_duplicate->bind_param("sss", $final_reference, $selected['test_name'], $selected['method']);
                    $check_duplicate->execute();
                    $duplicate_result = $check_duplicate->get_result();
                    
                    if ($duplicate_result && $duplicate_row = $duplicate_result->fetch_assoc()) {
                        $check_duplicate->close();
                        $conn->rollback();
                        $_SESSION['error_message'] = "❌ Test has already been submitted for reference: " . htmlspecialchars($final_reference) . " with test: " . htmlspecialchars($selected['test_name']) . " (" . htmlspecialchars($selected['method']) . "). Report Number: " . htmlspecialchars($duplicate_row['report_number']);
                        header("Location: " . $_SERVER['PHP_SELF']);
                        exit;
                    }
                    $check_duplicate->close();
                    
                    $status = 'approved';
                    $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
                    $approved_at = date('Y-m-d H:i:s');
                    
                    // Insert with approved status
                    $stmt = $conn->prepare("INSERT INTO qc_test_orders (sample_reference_id, report_number, test_standard_id, chosen_method, test_data, inspector_id, inspector_name, status, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssississss", $final_reference, $report_number, $test_standard_id, $selected['method'], $test_data_json, $reporter_id, $reporter_name, $status, $approved_by, $approved_at);
                } else {
                    // Determine status based on product type and user role
                    if ($is_external_product) {
                        // External products: AGM submits → pending_tester → tester submits → pending_checker → checker approves → pending_approval
                        $status = 'pending_tester';
                        error_log("QC Test Order: External product detected, setting status to pending_tester. is_external_product=" . ($is_external_product ? 'true' : 'false'));
                    } else {
                        // Production products: Regular workflow
                        // First 5 tests go to checker, others go directly to admin
                        $status = in_array($selected['test_name'], $tests_requiring_checker) ? 'pending_checker' : 'pending_approval';
                        error_log("QC Test Order: Production product, setting status to " . $status);
                    }
                    
                    // Check if test order already exists for this reference and test
                    // Check by joining with test_standards to match by test_name and method
                    $check_duplicate = $conn->prepare("
                        SELECT qto.id, qto.report_number, qto.status, ts.test_name, ts.standard_code
                        FROM qc_test_orders qto
                        INNER JOIN test_standards ts ON qto.test_standard_id = ts.id
                        WHERE qto.sample_reference_id = ? 
                        AND ts.test_name = ?
                        AND ts.standard_code = ?
                        AND qto.id != ?
                        AND qto.status NOT IN ('rejected_by_checker', 'rejected_by_approver')
                        LIMIT 1
                    ");
                    $check_id = $is_editing ? $edit_id_post : 0;
                    $check_duplicate->bind_param("sssi", $final_reference, $selected['test_name'], $selected['method'], $check_id);
                    $check_duplicate->execute();
                    $duplicate_result = $check_duplicate->get_result();
                    
                    // Check if this is a bulk submission (multiple references in range)
                    $is_bulk_submission = !empty($bulk_rolls) && count($bulk_rolls) > 1;
                    $already_submitted = false;
                    
                    if ($duplicate_result && $duplicate_row = $duplicate_result->fetch_assoc()) {
                        $already_submitted = true;
                        
                        if ($is_bulk_submission) {
                            // For bulk submissions: skip this reference but continue with others
                            $check_duplicate->close();
                            error_log("QC Test Order: Skipping reference $final_reference - test already submitted (Report: " . $duplicate_row['report_number'] . ")");
                            $bulk_submission_results['skipped'][] = [
                                'reference' => $final_reference,
                                'reason' => 'Already submitted',
                                'report_number' => $duplicate_row['report_number'],
                                'date' => $duplicate_row['status']
                            ];
                            continue; // Skip to next reference in bulk
                        } else {
                            // For single reference: show error and stop
                            $check_duplicate->close();
                            $conn->rollback();
                            $_SESSION['error_message'] = "❌ Test has already been submitted for reference: " . htmlspecialchars($final_reference) . " with test: " . htmlspecialchars($selected['test_name']) . " (" . htmlspecialchars($selected['method']) . "). Report Number: " . htmlspecialchars($duplicate_row['report_number']);
                            header("Location: " . $_SERVER['PHP_SELF'] . ($is_editing && $edit_id_post > 0 ? "?edit_id=" . $edit_id_post : ""));
                            exit;
                        }
                    }
                    $check_duplicate->close();
                    
                    // Skip insertion if already submitted (for bulk submissions)
                    if ($already_submitted) {
                        continue; // Skip to next reference
                    }
                    
                    // Insert with appropriate status - EXPLICITLY set status to avoid database defaults
                    // Ensure status is set before binding
                    if (empty($status)) {
                        error_log("QC Test Order: ERROR - Status is empty before INSERT! Setting default to pending_checker");
                        $status = 'pending_checker'; // Fallback default
                    }
                    error_log("QC Test Order: About to insert with status: " . $status . " for report: " . $report_number);
                    $stmt = $conn->prepare("INSERT INTO qc_test_orders (sample_reference_id, report_number, test_standard_id, chosen_method, test_data, inspector_id, inspector_name, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssississ", $final_reference, $report_number, $test_standard_id, $selected['method'], $test_data_json, $reporter_id, $reporter_name, $status);
                    error_log("QC Test Order: Inserting with status: " . $status . " for report: " . $report_number);
                }
            }
        
            // Ensure status is set before executing
            if (empty($status) && isset($stmt)) {
                // Check for duplicate again before re-binding
                $check_duplicate = $conn->prepare("
                    SELECT qto.id, qto.report_number, qto.status, ts.test_name, ts.standard_code
                    FROM qc_test_orders qto
                    INNER JOIN test_standards ts ON qto.test_standard_id = ts.id
                    WHERE qto.sample_reference_id = ? 
                    AND ts.test_name = ?
                    AND ts.standard_code = ?
                    AND qto.id != ?
                    LIMIT 1
                ");
                $check_id = $is_editing ? $edit_id_post : 0;
                $check_duplicate->bind_param("sssi", $final_reference, $selected['test_name'], $selected['method'], $check_id);
                $check_duplicate->execute();
                $duplicate_result = $check_duplicate->get_result();
                
                if ($duplicate_result && $duplicate_row = $duplicate_result->fetch_assoc()) {
                    $check_duplicate->close();
                    $conn->rollback();
                    $_SESSION['error_message'] = "❌ Test has already been submitted for reference: " . htmlspecialchars($final_reference) . " with test: " . htmlspecialchars($selected['test_name']) . " (" . htmlspecialchars($selected['method']) . "). Report Number: " . htmlspecialchars($duplicate_row['report_number']);
                    header("Location: " . $_SERVER['PHP_SELF'] . ($is_editing && $edit_id_post > 0 ? "?edit_id=" . $edit_id_post : ""));
                    exit;
                }
                $check_duplicate->close();
                
                error_log("QC Test Order: WARNING - Status is empty before execute! Setting default to pending_checker");
                $status = 'pending_checker'; // Fallback default
                // Re-bind with the default status
                $stmt->close();
                $stmt = $conn->prepare("INSERT INTO qc_test_orders (sample_reference_id, report_number, test_standard_id, chosen_method, test_data, inspector_id, inspector_name, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssississ", $final_reference, $report_number, $test_standard_id, $selected['method'], $test_data_json, $reporter_id, $reporter_name, $status);
            }
            
            if ($stmt->execute()) {
                if ($is_editing) {
                    // For UPDATE queries, check affected_rows
                    $affected_rows = $stmt->affected_rows;
                    error_log("QC Test Order: UPDATE executed. Affected rows: " . $affected_rows . ", Status set to: " . $status);
                    if ($affected_rows == 0) {
                        error_log("QC Test Order: WARNING - UPDATE affected 0 rows! Test ID: " . $edit_id_post . ", Status: " . $status);
                    } else {
                        // Verify the status was saved correctly
                        $verify_stmt = $conn->prepare("SELECT status FROM qc_test_orders WHERE id = ?");
                        $verify_stmt->bind_param("i", $edit_id_post);
                        $verify_stmt->execute();
                        $verify_result = $verify_stmt->get_result();
                        if ($verify_row = $verify_result->fetch_assoc()) {
                            error_log("QC Test Order: Verified saved status: " . $verify_row['status'] . " for test ID: " . $edit_id_post);
                            if ($verify_row['status'] !== $status) {
                                error_log("QC Test Order: WARNING - Status mismatch! Expected: " . $status . ", Got: " . $verify_row['status']);
                            }
                        }
                        $verify_stmt->close();
                    }
                } else {
                    $inserted_count++;
                    // Track successful submission for bulk references
                    if ($is_bulk_submission && $bulk_roll_ref) {
                        $bulk_submission_results['submitted'][] = [
                            'reference' => $final_reference,
                            'report_number' => $report_number
                        ];
                    }
                    // Verify the status was saved correctly
                    $verify_stmt = $conn->prepare("SELECT status FROM qc_test_orders WHERE report_number = ?");
                    $verify_stmt->bind_param("s", $report_number);
                    $verify_stmt->execute();
                    $verify_result = $verify_stmt->get_result();
                    if ($verify_row = $verify_result->fetch_assoc()) {
                        error_log("QC Test Order: Verified saved status: " . $verify_row['status'] . " for report: " . $report_number);
                        if ($verify_row['status'] !== $status) {
                            error_log("QC Test Order: WARNING - Status mismatch! Expected: " . $status . ", Got: " . $verify_row['status']);
                        }
                    }
                    $verify_stmt->close();
                }
            } else {
                // Log insert error
                $debug_msg = "=== QC INSERT ERROR ===\n";
                $debug_msg .= "Error: " . $stmt->error . "\n";
                $debug_msg .= "Test: " . $selected['test_name'] . " - " . $selected['method'] . "\n";
                file_put_contents('qc_debug.txt', $debug_msg, FILE_APPEND);
                error_log("QC INSERT ERROR for " . $selected['test_name'] . ": " . $stmt->error);
            }
            $stmt->close();
            } // End foreach selected_methods
            
            // Restore original values after processing each bulk roll
            if ($bulk_roll_ref) {
                if ($original_individual_ref) {
                    $_POST['individual_roll_reference'] = $original_individual_ref;
                } else {
                    unset($_POST['individual_roll_reference']);
                }
                $user_reference = $original_user_ref;
            }
        } // End foreach rolls_to_process
        
        $conn->commit();

        // Save general information from the last submission for auto-prefill (to database)
        $last_general = [];
        
        // Save top-level general fields (from main form)
        $top_fields = [
            'sample_details', 'batch_information', 'sample_collected_from', 
            'sample_received_datetime', 'sample_production_date', 
            'temperature', 'rh_percentage', 'test_period_from', 'test_period_to',
            'customer_reference', 'sample_received_from'
        ];
        foreach ($top_fields as $field) {
            if (isset($_POST[$field]) && $_POST[$field] !== '') {
                $last_general[$field] = $_POST[$field];
            }
        }
        
        // Save to database for persistence across sessions
        if (!empty($last_general)) {
            // Verify user exists (check both new_user and old users tables)
            $user_exists = false;
            
            // First try new_user table
            $check_user = $conn->prepare("SELECT id FROM new_user WHERE id = ?");
            $check_user->bind_param("i", $reporter_id);
            $check_user->execute();
            $user_exists = $check_user->get_result()->num_rows > 0;
            $check_user->close();
            
            // If not found, try old users table
            if (!$user_exists) {
                $check_old = $conn->prepare("SELECT id FROM users WHERE id = ?");
                $check_old->bind_param("i", $reporter_id);
                $check_old->execute();
                $user_exists = $check_old->get_result()->num_rows > 0;
                $check_old->close();
                
                if ($user_exists) {
                    error_log("QC Preferences: User {$reporter_id} found in old 'users' table, preferences will still be saved");
                }
            }
            
            if ($user_exists) {
                $saved_count = 0;
                foreach ($last_general as $field_name => $field_value) {
                    try {
                        // Remove the foreign key constraint temporarily or save without it
                        $save_stmt = $conn->prepare("INSERT INTO user_qc_preferences (user_id, field_name, field_value) 
                            VALUES (?, ?, ?) 
                            ON DUPLICATE KEY UPDATE field_value = ?, updated_at = NOW()");
                        $save_stmt->bind_param("isss", $reporter_id, $field_name, $field_value, $field_value);
                        if ($save_stmt->execute()) {
                            $saved_count++;
                        }
                        $save_stmt->close();
                    } catch (Exception $e) {
                        error_log("Failed to save QC preference '{$field_name}' for user {$reporter_id}: " . $e->getMessage());
                    }
                }
                error_log("QC Preferences: Saved {$saved_count}/" . count($last_general) . " preferences for user {$reporter_id}");
            } else {
                error_log("Cannot save QC preferences: User ID {$reporter_id} does not exist in new_user OR users table");
            }
        }
        
        // Also save to session as backup
        $_SESSION['qc_last_general'] = $last_general;
        error_log("Saved QC_LAST_GENERAL to database (" . count($last_general) . " fields)");
        
        // Get the report number from POST
        $report_number = $_POST['report_no'] ?? 'N/A';
        
        // Create a more descriptive message with test names and status
        $test_names = array_map(function($m) { return $m['test_name']; }, $selected_methods);
        $test_list = implode(', ', $test_names);
        
        // Determine status message based on user role, test type, and edit mode
        if ($is_editing) {
            $first_test = $selected_methods[0]['test_name'];
            $status_msg = in_array($first_test, $tests_requiring_checker) 
                ? "Pending Checker Approval" 
                : "Pending Admin/AGM Approval (Checker review not required)";
            
            // Determine message verb based on original status
            // FORWARDED EXTERNAL TEST WORKFLOW:
            // - AGM creates test with pending status (forwarded to tester)
            // - Tester submits test (status stays pending, but now has test results)
            // - This is a "submitted" action, NOT "resubmitted"
            // RESUBMITTED TEST WORKFLOW:
            // - Test was rejected by checker/approver
            // - Tester resubmits rejected test
            // - This is a "resubmitted" action
            
            $was_rejected = false;
            if ($original_status !== null) {
                $was_rejected = in_array($original_status, ['rejected_by_checker', 'rejected_by_approver']);
            }
            
            // Use "submitted" for forwarded external tests (first-time submission by tester)
            // Use "resubmitted" ONLY if original status was rejected
            $submit_verb = ($is_forwarded_external_submit || !$was_rejected) ? "submitted" : "resubmitted";
            $message = "Successfully {$submit_verb} test: {$test_list} | Report No: {$report_number} | Status: {$status_msg}";
            
            // Clear edit session data
            unset($_SESSION['edit_qc_test_order_id']);
            unset($_SESSION['edit_qc_test_order_data']);
            unset($_SESSION['edit_qc_test_order_test_data']);
            
            // Redirect to dashboard if it's a forwarded external test
            if ($is_forwarded_external_submit) {
                $_SESSION['qc_success_message'] = $message;
                header("Location: ../admin/forwarded_external_test_dashboard.php?submitted=1");
                exit();
            }
            
            // Check if we should return to rejected reports dashboard
            $return_to = $_GET['return'] ?? $_POST['return'] ?? '';
            if ($return_to === 'tester_rejected_reports') {
                $_SESSION['success_message'] = $message;
                header("Location: ../tester_rejected_reports.php");
                exit();
            }
            
            // Redirect to clean URL without edit parameter
            $_SESSION['qc_success_message'] = $message;
            header("Location: qc_test_order.php?submitted=1");
            exit();
        } else {
            // Check if this is an external product
            $is_external_product = isset($_POST['is_external_product']) && $_POST['is_external_product'] == '1';
            
            if ($is_admin && !$is_external_product) {
                $status_msg = "Auto-Approved (Admin/AGM Ops submission)";
            } elseif ($is_admin && $is_external_product) {
                // AGM forwarded an external product to testers
                $status_msg = "Forwarded to Tester (External Product)";
            } else {
                // For external products submitted by testers, or regular users (production products)
                $first_test = $selected_methods[0]['test_name'];
                $status_msg = in_array($first_test, $tests_requiring_checker) 
                    ? "Pending Checker Approval" 
                    : "Pending Admin/AGM Approval (Checker review not required)";
            }
            
            // Build message with bulk submission details if applicable
            $is_bulk = !empty($bulk_rolls) && count($bulk_rolls) > 1;
            if ($is_bulk && !empty($bulk_submission_results)) {
                $submittedCount = count($bulk_submission_results['submitted']);
                $skippedCount = count($bulk_submission_results['skipped']);
                $totalCount = $submittedCount + $skippedCount;
                
                $message = "Bulk Submission Complete: {$submittedCount} reference(s) submitted, {$skippedCount} skipped (already submitted)";
                if ($submittedCount > 0) {
                    $message .= " | Test: {$test_list} | Status: {$status_msg}";
                }
                if ($skippedCount > 0) {
                    $skippedRefs = array_column($bulk_submission_results['skipped'], 'reference');
                    $message .= " | Skipped: " . implode(', ', array_slice($skippedRefs, 0, 5)) . ($skippedCount > 5 ? " (+" . ($skippedCount - 5) . " more)" : "");
                }
            } else {
                $message = "Successfully submitted {$inserted_count} test(s): {$test_list} | Report No: {$report_number} | Status: {$status_msg}";
            }
            
            // Redirect to reload page with fresh data (auto-refresh preferences)
            $_SESSION['qc_success_message'] = $message;
            header("Location: qc_test_order.php?submitted=1");
            exit();
        }
        
    } catch (Exception $e) {
            $conn->rollback();
        $error = "Error: " . $e->getMessage();
    }
}

// Handle checker approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checker_action']) && $is_checker) {
    try {
        $action = $_POST['checker_action'];
        $comment = trim($_POST['checker_comment'] ?? '');
        
        // Handle rejection reasons checkboxes
        if ($action === 'rejected' && isset($_POST['rejection_reasons']) && is_array($_POST['rejection_reasons'])) {
            $rejection_reasons = array_map('trim', $_POST['rejection_reasons']);
            $reasons_text = implode(', ', $rejection_reasons);
            $comment = "Rejection Reasons: " . $reasons_text . ($comment ? "\n\nAdditional Comments: " . $comment : '');
        }
        
        $status = ($action === 'approved') ? 'pending_approval' : 'rejected_by_checker';
        $checked_by = $_SESSION['full_name'] ?? $_SESSION['username'];
        $checked_at = date('Y-m-d H:i:s');
        
        // Check if this is a bulk action (multiple report numbers)
        if (isset($_POST['checker_report_numbers']) && !empty($_POST['checker_report_numbers'])) {
            // Bulk action - process multiple reports
            $report_numbers_str = trim($_POST['checker_report_numbers']);
            $report_numbers = array_filter(array_map('trim', explode(',', $report_numbers_str)));
            
            if (empty($report_numbers)) {
                throw new Exception("No report numbers provided");
            }
            
            $success_count = 0;
            $failed_reports = [];
            
            foreach ($report_numbers as $report_number) {
                $stmt = $conn->prepare("UPDATE qc_test_orders SET status = ?, checked_by = ?, checked_at = ?, checker_remarks = ?, updated_at = NOW() WHERE report_number = ?");
                $stmt->bind_param("sssss", $status, $checked_by, $checked_at, $comment, $report_number);
                
                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    $failed_reports[] = $report_number . ' (' . $stmt->error . ')';
                }
                $stmt->close();
            }
            
            if ($success_count > 0) {
                $message = "$success_count report(s) have been " . ($action === 'approved' ? 'forwarded to AGM/Admin for approval' : 'rejected') . " successfully!";
                if (!empty($failed_reports)) {
                    $message .= " Failed: " . implode(', ', $failed_reports);
                }
                header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($message));
                exit();
            } else {
                throw new Exception("Failed to update all reports: " . implode(', ', $failed_reports));
            }
        } else {
            // Single report action (backward compatibility)
            $report_number = trim($_POST['checker_report_number'] ?? '');
            if (empty($report_number)) {
                throw new Exception("No report number provided");
            }
            
        $stmt = $conn->prepare("UPDATE qc_test_orders SET status = ?, checked_by = ?, checked_at = ?, checker_remarks = ?, updated_at = NOW() WHERE report_number = ?");
        $stmt->bind_param("sssss", $status, $checked_by, $checked_at, $comment, $report_number);
        
        if ($stmt->execute()) {
            $stmt->close();
            $message = "Report $report_number has been " . ($action === 'approved' ? 'forwarded to AGM/Admin for approval' : 'rejected') . " successfully!";
            header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($message));
            exit();
        } else {
            $error_msg = $stmt->error;
            $stmt->close();
            throw new Exception("Failed to update report: " . $error_msg);
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle admin approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action']) && $is_admin) {
    try {
        $action = $_POST['admin_action'];
        $comment = trim($_POST['admin_comment'] ?? '');
        $destination = trim($_POST['roll_destination'] ?? '');
        
        // Add roll_destination column if not exists
        $conn->query("ALTER TABLE qc_test_orders ADD COLUMN IF NOT EXISTS roll_destination VARCHAR(50) AFTER status");
        
        // Check if this is a bulk action (multiple report numbers)
        if (isset($_POST['admin_report_numbers']) && !empty($_POST['admin_report_numbers'])) {
            // Bulk action - process multiple reports
            $report_numbers_str = trim($_POST['admin_report_numbers']);
            $report_numbers = array_filter(array_map('trim', explode(',', $report_numbers_str)));
            
            if (empty($report_numbers)) {
                throw new Exception("No report numbers provided");
            }
            
            $status = ($action === 'approved') ? 'approved' : 'rejected_by_approver';
            $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
            $approved_at = date('Y-m-d H:i:s');
            
            $success_count = 0;
            $failed_reports = [];
            
            foreach ($report_numbers as $report_number) {
                if ($action === 'approved' && !empty($destination)) {
                    $stmt = $conn->prepare("UPDATE qc_test_orders SET status = ?, approved_by = ?, approved_at = ?, admin_remarks = ?, roll_destination = ?, updated_at = NOW() WHERE report_number = ?");
                    $stmt->bind_param("ssssss", $status, $approved_by, $approved_at, $comment, $destination, $report_number);
                } else {
                    $stmt = $conn->prepare("UPDATE qc_test_orders SET status = ?, approved_by = ?, approved_at = ?, admin_remarks = ?, updated_at = NOW() WHERE report_number = ?");
                    $stmt->bind_param("sssss", $status, $approved_by, $approved_at, $comment, $report_number);
                }
                
                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    $failed_reports[] = $report_number . ' (' . $stmt->error . ')';
                }
                $stmt->close();
            }
            
            if ($success_count > 0) {
                $message = "$success_count report(s) have been " . ($action === 'approved' ? 'approved' : 'rejected') . " successfully!";
                if (!empty($failed_reports)) {
                    $message .= " Failed: " . implode(', ', $failed_reports);
                }
                header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($message));
                exit();
            } else {
                throw new Exception("Failed to update all reports: " . implode(', ', $failed_reports));
            }
        } else {
            // Single report action (backward compatibility)
            $report_number = trim($_POST['admin_report_number'] ?? '');
            if (empty($report_number)) {
                throw new Exception("No report number provided");
            }
        
        $status = ($action === 'approved') ? 'approved' : 'rejected_by_approver';
        $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
        $approved_at = date('Y-m-d H:i:s');
        
        // Validate destination for approval
        if ($action === 'approved' && empty($destination)) {
            throw new Exception("Please select a destination for the approved roll.");
        }
        
        $stmt = $conn->prepare("UPDATE qc_test_orders SET status = ?, approved_by = ?, approved_at = ?, admin_remarks = ?, roll_destination = ?, updated_at = NOW() WHERE report_number = ?");
        $stmt->bind_param("ssssss", $status, $approved_by, $approved_at, $comment, $destination, $report_number);
        
        if ($stmt->execute()) {
            $stmt->close();
            $dest_text = ($destination === 'fg_production') ? 'FG' : (($destination === 'bag_production') ? 'Bag Production' : '');
            $message = "Report $report_number has been " . ($status === 'approved' ? "approved for $dest_text" : 'rejected') . " successfully!";
            header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($message));
            exit();
        } else {
            $error_msg = $stmt->error;
            $stmt->close();
            throw new Exception("Failed to update report: " . $error_msg);
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get pending reports for checker (only first 5 tests, exclude admin submissions)
$pending_for_checker = [];
if ($is_checker) {
    // Get test_standard_ids for first 5 tests
    $first_five_test_names = [
        'Thickness (Under 2kPa Pressure)',
        'Mass Per Unit Area (GSM)',
        'Strip Tensile Test',
        'CBR Puncture Resistance',
        'Grab Tensile Test'
    ];
    
    // Get admin/AGM Ops user IDs to exclude their submissions
    $admin_roles = ['admin', 'agm ops', 'agm operations'];
    $admin_ids_query = $conn->query("SELECT id FROM new_user WHERE LOWER(TRIM(role)) IN ('admin', 'agm ops', 'agm operations')");
    $admin_ids = [];
    if ($admin_ids_query) {
        while ($row = $admin_ids_query->fetch_assoc()) {
            $admin_ids[] = $row['id'];
        }
    }
    
    // Schema detection for qc_test_orders
    $qctoCols = [];
    $colRes = $conn->query("SHOW COLUMNS FROM qc_test_orders");
    if ($colRes) {
        while ($r = $colRes->fetch_assoc()) {
            $qctoCols[] = strtolower($r['Field']);
        }
    }
    $hasStatus = in_array('status', $qctoCols, true);
    $hasUpdated = in_array('updated_at', $qctoCols, true);
    $statusFilter = $hasStatus ? "WHERE qto.status = 'pending_checker'" : "";
    $updatedOrder = $hasUpdated ? "qto.updated_at" : "qto.created_at";
    
    $stmt = $conn->query("
        SELECT qto.*, ts.test_name 
        FROM qc_test_orders qto
        LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
        $statusFilter
        ORDER BY qto.sample_reference_id ASC, $updatedOrder DESC 
        LIMIT 50
    ");
    if ($stmt) {
        while ($row = $stmt->fetch_assoc()) {
            // Only include if:
            // 1. It's one of the first 5 tests
            // 2. NOT submitted by admin/AGM Ops (should already be auto-approved)
            if (in_array($row['test_name'], $first_five_test_names) && 
                !in_array($row['inspector_id'], $admin_ids)) {
                $pending_for_checker[] = $row;
            }
        }
    }
}

// Get pending reports for admin approval
$pending_for_admin = [];
if ($is_admin) {
    $qctoColsAdmin = [];
    $colResAdmin = $conn->query("SHOW COLUMNS FROM qc_test_orders");
    if ($colResAdmin) {
        while ($r = $colResAdmin->fetch_assoc()) {
            $qctoColsAdmin[] = strtolower($r['Field']);
        }
    }
    $hasStatusAdmin = in_array('status', $qctoColsAdmin, true);
    $hasUpdatedAdmin = in_array('updated_at', $qctoColsAdmin, true);
    $statusFilterAdmin = $hasStatusAdmin ? "WHERE status = 'pending_approval'" : "";
    $orderAdmin = $hasUpdatedAdmin ? "updated_at" : "created_at";

    $stmt = $conn->query("SELECT * FROM qc_test_orders $statusFilterAdmin ORDER BY sample_reference_id ASC, $orderAdmin DESC LIMIT 50");
    if ($stmt) {
        while ($row = $stmt->fetch_assoc()) {
            $pending_for_admin[] = $row;
        }
    }
}

// Get rejected reports for tester
$rejected_reports = [];
if ($is_tester) {
    $stmt = $conn->prepare("SELECT * FROM qc_test_orders WHERE inspector_id = ? AND (status = 'rejected_by_checker' OR status = 'rejected_by_approver') ORDER BY sample_reference_id ASC, updated_at DESC LIMIT 20");
    $stmt->bind_param("i", $reporter_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rejected_reports[] = $row;
    }
    $stmt->close();
}

// Function to generate sample reference ID with proper sequence
function generateSampleReferenceId() {
    global $conn;
    
    $now = new DateTime();
    $hour = (int)$now->format('H');
    
    // Determine shift (8AM to 7:59AM next day is day shift)
    $isDayShift = $hour >= 8;
    
    // Get shift date
    $shiftDate = clone $now;
    if (!$isDayShift && $hour < 8) {
        // Night shift - if before 8AM, it's still previous day's night shift
        $shiftDate->modify('-1 day');
    }
    
    $dateStr = $shiftDate->format('Ymd');
    
    // Get next sequence number for this date
    $query = "SELECT MAX(CAST(SUBSTRING(sample_reference_id, -3) AS UNSIGNED)) as max_seq 
              FROM qc_test_orders 
              WHERE sample_reference_id LIKE ?";
    $stmt = $conn->prepare($query);
    $pattern = "GEOCIL-LAB-TR-{$dateStr}%";
    $stmt->bind_param("s", $pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $nextSeq = ($row['max_seq'] ?? 0) + 1;
    $stmt->close();
    
    return "GEOCIL-LAB-TR-{$dateStr}" . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
}

// Function to generate report number starting from 1 each shift-day (8:00 AM to next day 7:59 AM)
function generateReportNumber() {
    global $conn;

    $now = new DateTime();
    $hour = (int)$now->format('H');

    // Determine shift-day window (from 8:00 of shift date to next day 07:59:59)
    $shiftDate = clone $now;
    if ($hour < 8) {
        // Before 8 AM belongs to previous shift-day
        $shiftDate->modify('-1 day');
    }

    // Start at 08:00:00 of shiftDate
    $start = (clone $shiftDate)->setTime(8, 0, 0);
    // End just before 08:00:00 next day
    $end = (clone $start)->modify('+1 day');

    // Count existing orders in this window to derive next sequence
    $count = 0;
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM qc_test_orders WHERE created_at >= ? AND created_at < ?");
    if ($stmt) {
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');
        $stmt->bind_param('ss', $startStr, $endStr);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            $row = $res->fetch_assoc();
            $count = (int)($row['c'] ?? 0);
        }
        $stmt->close();
    }

    $seq = $count + 1;
    $dateStr = $shiftDate->format('Ymd');
    return 'RPT-' . $dateStr . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

// Function to generate external reference number for outside/vendor samples
function generateExternalReference() {
    global $conn;
    
    $today = date('Ymd');
    $pattern = "EXT-{$today}-%";
    
    // Count existing external references for today
    $count = 0;
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c 
        FROM qc_test_orders 
        WHERE sample_reference_id LIKE ? 
        AND DATE(created_at) = CURDATE()
    ");
    
    if ($stmt) {
        $stmt->bind_param('s', $pattern);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            $row = $res->fetch_assoc();
            $count = (int)($row['c'] ?? 0);
        }
        $stmt->close();
    }
    
    $seq = $count + 1;
    return 'EXT-' . $today . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QC Test Order</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:0; color:#2c3e50; }
  .container { max-width:100%; margin:0; background:#fff; border-radius:0; padding:35px; box-shadow:none; box-sizing: border-box; width: 100%;} 
  h1 { text-align:center; font-size:28px; margin-bottom:20px; margin-top:0; }
  .form-group { margin-bottom:10px; }
  label { font-weight:600; display:block; margin-bottom:5px; }
  input[type="text"], input[type="number"], select { padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px); }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:10px; background:#f0f0f0; }
  .actions { margin-top:15px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .readonly { background:#ecf0f1; }
  .btn-group { display:flex; flex-wrap:wrap; gap:10px; }
  .btn { padding:10px 16px; font-size:14px; border:none; border-radius:6px; cursor:pointer; background-color:#f8f9fa; }
  .btn:hover { background-color:#ccc; }
  .btn.selected { background-color:#3498db; color:white; }
  .test-section { border:1px solid #eee; border-radius:8px; padding:10px; margin-bottom:10px; }
  .test-item { margin-bottom:5px; padding:8px; border:1px solid #ddd; border-radius:6px; }
  .test-item:hover { background-color:#f8f9fa; }
  .test-item.selected { background-color:#e8f5e9; border-color:#4caf50; }
  .product-header { background:#f8f9fa; padding:8px; border-radius:6px; margin-bottom:10px; font-weight:bold; }
  .alert { padding:10px; border-radius:6px; margin-bottom:10px; }
  .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
  .alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
  
  /* Modern Toast Notification System */
  .toast-container {
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
    min-width: 320px;
    max-width: 450px;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    padding: 16px 20px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    pointer-events: auto;
    animation: slideInRight 0.3s ease-out;
    border-left: 4px solid;
    transition: all 0.3s ease;
  }
  
  .toast.error {
    border-left-color: #dc3545;
    background: #fff5f5;
  }
  
  .toast.warning {
    border-left-color: #ff9800;
    background: #fff8f0;
  }
  
  .toast.success {
    border-left-color: #28a745;
    background: #f0fff4;
  }
  
  .toast.info {
    border-left-color: #17a2b8;
    background: #f0f9ff;
  }
  
  .toast-icon {
    font-size: 20px;
    flex-shrink: 0;
    margin-top: 2px;
  }
  
  .toast.error .toast-icon { color: #dc3545; }
  .toast.warning .toast-icon { color: #ff9800; }
  .toast.success .toast-icon { color: #28a745; }
  .toast.info .toast-icon { color: #17a2b8; }
  
  .toast-content {
    flex: 1;
  }
  
  .toast-title {
    font-weight: 600;
    font-size: 15px;
    margin-bottom: 4px;
    color: #2c3e50;
  }
  
  .toast-message {
    font-size: 13px;
    color: #6c757d;
    line-height: 1.5;
  }
  
  .toast-close {
    background: none;
    border: none;
    font-size: 18px;
    color: #adb5bd;
    cursor: pointer;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: all 0.2s;
    flex-shrink: 0;
  }
  
  .toast-close:hover {
    background: rgba(0, 0, 0, 0.05);
    color: #495057;
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
    animation: slideOutRight 0.3s ease-out forwards;
  }
  
  /* Modern Inline Validation */
  .validation-message {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
    padding: 10px 12px;
    border-radius: 6px;
    font-size: 13px;
    animation: fadeIn 0.3s ease;
  }
  
  .validation-message.error {
    background: #fff5f5;
    color: #dc3545;
    border: 1px solid #fecaca;
  }
  
  .validation-message.warning {
    background: #fff8f0;
    color: #ff9800;
    border: 1px solid #ffe0b2;
  }
  
  .validation-message.info {
    background: #f0f9ff;
    color: #17a2b8;
    border: 1px solid #b3e5fc;
  }
  
  @keyframes fadeIn {
    from { opacity: 0; transform: translateY(-5px); }
    to { opacity: 1; transform: translateY(0); }
  }
  
  /* Enhanced Test Checkbox Styling for Already Submitted */
  .test-checkbox[data-already-submitted="true"] {
    position: relative;
    cursor: not-allowed !important;
  }
  
  /* Modern Badge for Already Submitted Tests */
  .already-submitted-badge {
    display: inline-flex !important;
    align-items: center;
    gap: 4px;
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    color: #991b1b;
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 9px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    margin-left: 0;
    border: 1px solid #fca5a5;
    box-shadow: 0 2px 4px rgba(220, 53, 69, 0.1);
    white-space: nowrap;
    flex-shrink: 0;
    line-height: 1.2;
  }
  
  .already-submitted-badge i {
    font-size: 11px;
  }
  
  /* Enhanced Label Styling for Disabled Tests */
  label:has(.test-checkbox[data-already-submitted="true"]),
  label.disabled-test-label {
    background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%) !important;
    border: 2px solid #e5e7eb !important;
    border-left: 4px solid #dc3545 !important;
    position: relative;
    opacity: 0.85;
    transition: all 0.3s ease;
    display: flex !important;
    align-items: center !important;
    flex-wrap: nowrap !important;
    gap: 8px !important;
    width: 100% !important;
    margin-bottom: 5px !important;
  }
  
  label:has(.test-checkbox[data-already-submitted="true"]):hover,
  label.disabled-test-label:hover {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%) !important;
    border-color: #fca5a5 !important;
    transform: translateX(-2px);
    box-shadow: 0 4px 8px rgba(220, 53, 69, 0.15);
  }
  
  /* Checkbox Styling for Disabled State */
  .test-checkbox[data-already-submitted="true"] {
    appearance: none;
    width: 20px;
    height: 20px;
    border: 2px solid #dc3545;
    border-radius: 4px;
    background: #fee2e2;
    position: relative;
    cursor: not-allowed;
    opacity: 0.7;
  }
  
  .test-checkbox[data-already-submitted="true"]::before {
    content: '✓';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: #dc3545;
    font-size: 14px;
    font-weight: bold;
    line-height: 1;
  }
  
  /* Method Text Styling for Disabled Tests */
  label:has(.test-checkbox[data-already-submitted="true"]) span,
  label.disabled-test-label span:first-of-type {
    color: #6b7280 !important;
    text-decoration: line-through;
    text-decoration-color: #dc3545;
    text-decoration-thickness: 2px;
    position: relative;
  }
  
  /* Tooltip for More Information */
  .test-info-tooltip {
    position: relative;
    display: inline-block !important;
    margin-left: 0;
    flex-shrink: 0;
  }
  
  .test-info-tooltip .tooltip-icon {
    color: #9ca3af;
    font-size: 12px;
    cursor: help;
    transition: color 0.2s;
  }
  
  .test-info-tooltip:hover .tooltip-icon {
    color: #dc3545;
  }
  
  .test-info-tooltip .tooltip-content {
    visibility: hidden;
    position: absolute;
    bottom: 125%;
    left: 50%;
    transform: translateX(-50%);
    background: #1f2937;
    color: #fff;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: 11px;
    white-space: nowrap;
    z-index: 1000;
    opacity: 0;
    transition: opacity 0.3s, visibility 0.3s;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
  }
  
  .test-info-tooltip .tooltip-content::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    border: 5px solid transparent;
    border-top-color: #1f2937;
  }
  
  .test-info-tooltip:hover .tooltip-content {
    visibility: visible;
    opacity: 1;
  }
  
  /* Pulse animation for blocked items */
  @keyframes pulse {
    0%, 100% { opacity: 0.85; }
    50% { opacity: 0.6; }
  }
  
  label:has(.test-checkbox[data-already-submitted="true"]):hover {
    animation: pulse 1.5s infinite;
  }
  
  /* Modern Card-like Appearance */
  .test-item:has(.test-checkbox[data-already-submitted="true"]),
  .test-item.has-disabled-test {
    background: #fef2f2;
    border-left: 4px solid #dc3545;
  }
  
  /* Shake animation for form validation errors */
  @keyframes shake {
    0%, 100% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
    20%, 40%, 60%, 80% { transform: translateX(5px); }
  }
  .form-row { 
    display:flex; 
    flex-wrap:wrap;
    gap:20px; 
    margin-bottom:30px; 
    clear: both;
    width: 100%;
    box-sizing: border-box;
  }
  .form-row .form-group {
    flex: 0 1 calc((100% - 40px) / 3);
    min-width: 240px;
    max-width: calc((100% - 40px) / 3);
    box-sizing: border-box;
  }
  /* Tab/Tablet view - 2 fields per row */
  @media (max-width: 1024px) and (min-width: 769px) {
    .form-row .form-group {
      flex: 0 1 calc((100% - 20px) / 2);
      min-width: 220px;
      max-width: calc((100% - 20px) / 2);
    }
  }
  @media (max-width: 768px) {
    .form-row {
      gap: 20px;
    }
    .form-row .form-group {
      flex: 1 1 100%;
      min-width: 100%;
      max-width: 100%;
    }
  }
  .form-group { 
    margin-bottom:25px; 
    box-sizing: border-box;
  }
  .form-row .form-group {
    margin-bottom: 0;
  }
  .form-group label { font-weight:600; display:block; margin-bottom:12px; font-size:14px; }
  .form-group input, .form-group textarea, .form-group select {
    width:100%;
    padding:16px;
    border:1px solid #ccc;
    border-radius:6px;
    font-size:14px;
    box-sizing: border-box;
  }
  .locked-general-info {
    position: relative;
    border-color:#cbd5f5 !important;
    background:#eef2ff !important;
  }
  .locked-general-info input:not([type="hidden"]),
  .locked-general-info textarea,
  .locked-general-info select {
    pointer-events:none;
    background:#f3f4ff;
    color:#6b7280;
  }
  .locked-general-info label {
    color:#6b7280;
  }
  .locked-banner {
    background:#e0e7ff;
    border:1px solid #c7d2fe;
    color:#3730a3;
    padding:10px 14px;
    border-radius:6px;
    font-size:13px;
    margin-bottom:15px;
    display:flex;
    align-items:center;
    gap:8px;
    font-weight:600;
  }
    </style>
</head>
<body>
<!-- Modern Toast Notification Container -->
<div class="toast-container" id="toastContainer"></div>

<div class="container">
  
  <?php if (!$is_checker || $is_admin): ?>
  <h1>QC Test Order</h1>
  
  <?php if (!empty($preselected_reference)): ?>
    <div style="background: linear-gradient(135deg, #48bb78 0%, #38a169 100%); color: white; padding: 15px 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 6px 20px rgba(72, 187, 120, 0.3);">
      <div style="display: flex; align-items: center;">
        <i class="fas fa-info-circle" style="font-size: 24px; margin-right: 15px;"></i>
        <div>
          <strong style="font-size: 16px;">Roll Entry Reference Pre-filled</strong>
          <p style="margin: 5px 0 0 0; font-size: 14px; opacity: 0.95;">
            Roll reference <strong><?= htmlspecialchars($preselected_reference) ?></strong> has been automatically selected. Please proceed with the QC test.
          </p>
        </div>
      </div>
    </div>
  <?php endif; ?>
  <?php endif; ?>

                <?php if ($message): ?>
    <div class="alert alert-success">
      <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
    <div class="alert alert-error">
      ❌ <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

  <!-- Checker Dashboard Section -->
  <?php if ($is_checker && !empty($pending_for_checker)): ?>
  <div style="margin-bottom:15px;">
    <!-- Back Button -->
    <div style="margin-bottom:10px;">
      <a href="../index.php" style="display:inline-block; padding:10px 20px; background:#6c757d; color:#fff; text-decoration:none; border-radius:6px; font-size:14px; transition:background 0.3s;">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
      </a>
    </div>
    
    <!-- Pending for Checking Section -->
    <div style="background:#fff; padding:10px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1);">
      <div style="border-bottom:2px solid #ff9800; padding-bottom:15px; margin-bottom:20px;">
        <h2 style="color:#333; margin:0; font-size:24px; font-weight:600;">Pending for Checking</h2>
        <p style="color:#666; margin:5px 0 0 0; font-size:14px;">First 5 tests only: Thickness, GSM, Strip Tensile, CBR, Grab Tensile (<?php echo count($pending_for_checker); ?> pending)</p>
      </div>
      
      <?php 
        // Group reports by reference (handle bulk references)
        $grouped_checker_reports = [];
        $bulk_reference_groups = []; // Track bulk reference groups
        $processed_reports = []; // Track which reports have been processed
        
        // First pass: Process reports with explicit bulk reference metadata
        foreach ($pending_for_checker as $idx => $report) {
          $test_data = json_decode($report['test_data'] ?? '{}', true);
          
          // Check if this is a bulk reference submission
          if (isset($test_data['is_bulk_reference']) && $test_data['is_bulk_reference'] && 
              isset($test_data['bulk_from_reference']) && isset($test_data['bulk_to_reference'])) {
            $from_ref = $test_data['bulk_from_reference'];
            $to_ref = $test_data['bulk_to_reference'];
            $bulk_count = $test_data['bulk_reference_count'] ?? 0;
            $bulk_key = $from_ref . '|' . $to_ref; // Use pipe separator for grouping key
            
            if (!isset($bulk_reference_groups[$bulk_key])) {
              $bulk_reference_groups[$bulk_key] = [
                'from' => $from_ref,
                'to' => $to_ref,
                'count' => $bulk_count,
                'reports' => []
              ];
            }
            $bulk_reference_groups[$bulk_key]['reports'][] = $report;
            $processed_reports[] = $idx;
          }
        }
        
        // Second pass: Detect sequential references that might be from bulk submissions
        // Look for references with the same base but sequential numbers (e.g., -1, -2, -3)
        $remaining_reports = [];
        foreach ($pending_for_checker as $idx => $report) {
          if (!in_array($idx, $processed_reports)) {
            $remaining_reports[] = $report;
          }
        }
        
        // Group remaining reports by base reference pattern
        $base_reference_groups = [];
        foreach ($remaining_reports as $report) {
          $sample_ref = $report['sample_reference_id'] ?? '';
          
          // Extract base reference (everything before the last dash and number)
          // Pattern: matches references ending with -N where N is a number
          // Examples: "4.0L226JAN05-R01-H0.1-1" -> base: "4.0L226JAN05-R01-H0.1", num: 1
          if (preg_match('/^(.+)-(\d+)$/', $sample_ref, $matches)) {
            $base_ref = $matches[1];
            $roll_num = (int)$matches[2];
            
            if (!isset($base_reference_groups[$base_ref])) {
              $base_reference_groups[$base_ref] = [
                'base' => $base_ref,
                'references' => [],
                'reports' => []
              ];
            }
            $base_reference_groups[$base_ref]['references'][$roll_num] = $sample_ref;
            $base_reference_groups[$base_ref]['reports'][] = $report;
          } else {
            // No pattern match, treat as single reference
            $reference = $sample_ref ?: 'No Reference';
          if (!isset($grouped_checker_reports[$reference])) {
            $grouped_checker_reports[$reference] = [];
          }
          $grouped_checker_reports[$reference][] = $report;
        }
        }
        
        // Check if base reference groups have sequential references (likely bulk)
        foreach ($base_reference_groups as $base_ref => $group) {
          $refs = $group['references'];
          ksort($refs); // Sort by roll number
          $roll_nums = array_keys($refs);
          
          // If we have 2+ sequential references, treat as bulk
          if (count($roll_nums) >= 2) {
            $min_roll = min($roll_nums);
            $max_roll = max($roll_nums);
            $from_ref = $base_ref . '-' . $min_roll;
            $to_ref = $base_ref . '-' . $max_roll;
            $bulk_key = $from_ref . '|' . $to_ref;
            
            // Check if this bulk group already exists (from explicit metadata)
            if (!isset($bulk_reference_groups[$bulk_key])) {
              $bulk_reference_groups[$bulk_key] = [
                'from' => $from_ref,
                'to' => $to_ref,
                'count' => count($refs),
                'reports' => $group['reports']
              ];
            } else {
              // Merge reports if group already exists
              $bulk_reference_groups[$bulk_key]['reports'] = array_merge(
                $bulk_reference_groups[$bulk_key]['reports'],
                $group['reports']
              );
              // Update count if needed
              if (count($refs) > $bulk_reference_groups[$bulk_key]['count']) {
                $bulk_reference_groups[$bulk_key]['count'] = count($refs);
              }
            }
          } else {
            // Single reference, add to regular grouping
            $reference = reset($refs); // Get the only reference
            if (!isset($grouped_checker_reports[$reference])) {
              $grouped_checker_reports[$reference] = [];
            }
            $grouped_checker_reports[$reference] = array_merge(
              $grouped_checker_reports[$reference],
              $group['reports']
            );
          }
        }
      ?>
      
      <?php 
        // Display bulk reference groups first
        foreach ($bulk_reference_groups as $bulk_key => $bulk_group): 
          // Group reports by test name within this bulk reference group
          $test_groups = [];
          foreach ($bulk_group['reports'] as $report) {
            $test_name = $report['test_name'] ?? 'Unknown Test';
            $test_key = $test_name . '|' . ($report['chosen_method'] ?? '');
            if (!isset($test_groups[$test_key])) {
              $test_groups[$test_key] = [
                'test_name' => $test_name,
                'method' => $report['chosen_method'] ?? '',
                'reports' => []
              ];
            }
            $test_groups[$test_key]['reports'][] = $report;
          }
      ?>
      <div style="margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 20px; font-weight: 600; font-size: 16px;">
          <i class="fas fa-tag"></i> Reference Range: <?php echo htmlspecialchars($bulk_group['from']); ?> to <?php echo htmlspecialchars($bulk_group['to']); ?>
          <span style="float: right; font-size: 14px; opacity: 0.9;"><?php echo count($bulk_group['reports']); ?> report(s) | <?php echo $bulk_group['count']; ?> reference(s)</span>
        </div>
        <div style="overflow-x:auto;">
          <table style="width:100%; border-collapse:collapse; min-width:900px;">
            <thead>
              <tr style="background:#f8f9fa; border-bottom:2px solid #dee2e6;">
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Test Name</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Reference Range</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Customer Ref</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Tested By</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Submitted At</th>
                <th style="padding:12px 15px; text-align:center; font-weight:600; color:#495057; font-size:14px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($test_groups as $test_key => $test_group): 
                $first_report = $test_group['reports'][0];
                $test_data = json_decode($first_report['test_data'], true);
                $customer_ref = $test_data['customer_reference'] ?? $test_data['product_reference'] ?? 'N/A';
                $report_numbers = array_column($test_group['reports'], 'report_number');
                $report_numbers_str = implode(',', $report_numbers);
                $report_count = count($test_group['reports']);
                $earliest_date = min(array_map(function($r) { return strtotime($r['updated_at']); }, $test_group['reports']));
                $latest_date = max(array_map(function($r) { return strtotime($r['updated_at']); }, $test_group['reports']));
              ?>
              <tr style="border-bottom:1px solid #dee2e6; transition:background 0.2s;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='#fff'">
                <td style="padding:12px 15px; font-weight:500; color:#333;">
                  <?php echo htmlspecialchars($test_group['test_name']); ?>
                  <?php if (!empty($test_group['method'])): ?>
                    <br><span style="font-size:12px; color:#6c757d;">Method: <?php echo htmlspecialchars($test_group['method']); ?></span>
                  <?php endif; ?>
                  <br><span style="font-size:11px; color:#999;"><?php echo $report_count; ?> report(s)</span>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php echo htmlspecialchars($bulk_group['from']); ?> to <?php echo htmlspecialchars($bulk_group['to']); ?>
                  <br><span style="font-size:12px; color:#6c757d;">(<?php echo $bulk_group['count']; ?> references)</span>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php echo htmlspecialchars($customer_ref); ?>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php echo htmlspecialchars($first_report['inspector_name'] ?? 'N/A'); ?>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php 
                    if ($earliest_date == $latest_date) {
                      echo date('M d, Y - g:i A', $earliest_date);
                    } else {
                      echo date('M d, Y - g:i A', $earliest_date) . '<br><span style="font-size:11px; color:#999;">to ' . date('M d, Y - g:i A', $latest_date) . '</span>';
                    }
                  ?>
                </td>
                <td style="padding:12px 15px; text-align:center; white-space:nowrap;">
                  <button type="button" onclick="viewBulkReports(['<?php echo implode("','", $report_numbers); ?>'])" style="display:inline-block; padding:8px 16px; background:#17a2b8; color:#fff; text-decoration:none; border:none; border-radius:4px; font-size:13px; font-weight:500; margin-right:5px; transition:background 0.3s; cursor:pointer;" onmouseover="this.style.background='#138496'" onmouseout="this.style.background='#17a2b8'">
                    <i class="fas fa-eye"></i> View (<?php echo $report_count; ?>)
                  </button>
                  <form method="POST" style="display:inline; margin-right:5px;" onsubmit="return confirmBulkApproval(<?php echo $report_count; ?>)">
                    <input type="hidden" name="checker_report_numbers" value="<?php echo htmlspecialchars($report_numbers_str); ?>">
                    <button type="submit" name="checker_action" value="approved" style="padding:8px 16px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:13px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#218838'" onmouseout="this.style.background='#28a745'">
                      <i class="fas fa-check"></i> Approve All
                    </button>
                  </form>
                  <button type="button" onclick="openBulkCheckerRejectModal('<?php echo htmlspecialchars($report_numbers_str); ?>', <?php echo $report_count; ?>)" style="padding:8px 16px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:13px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#c82333'" onmouseout="this.style.background='#dc3545'">
                      <i class="fas fa-times"></i> Reject All
                    </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endforeach; ?>
      
      <?php foreach ($grouped_checker_reports as $reference => $reports): ?>
      <div style="margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 20px; font-weight: 600; font-size: 16px;">
          <i class="fas fa-tag"></i> Reference: <?php echo htmlspecialchars($reference); ?>
          <span style="float: right; font-size: 14px; opacity: 0.9;"><?php echo count($reports); ?> report(s)</span>
        </div>
        <div style="overflow-x:auto;">
          <table style="width:100%; border-collapse:collapse; min-width:900px;">
            <thead>
              <tr style="background:#f8f9fa; border-bottom:2px solid #dee2e6;">
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Report No</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Sample</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Customer Ref</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Tested By</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Submitted At</th>
                <th style="padding:12px 15px; text-align:center; font-weight:600; color:#495057; font-size:14px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($reports as $report): 
                $test_data = json_decode($report['test_data'], true);
                // Get sample reference (could be from different fields)
                $sample_ref = $report['sample_reference_id'] ?? 'N/A';
                // Get customer reference from test_data
                $customer_ref = $test_data['customer_reference'] ?? $test_data['product_reference'] ?? 'N/A';
              ?>
              <tr style="border-bottom:1px solid #dee2e6; transition:background 0.2s;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='#fff'">
                <td style="padding:12px 15px; font-weight:500; color:#333;">
                  <?php echo htmlspecialchars($report['report_number']); ?>
                  <br>
                  <span style="font-size:12px; color:#6c757d;"><?php echo htmlspecialchars($report['test_name'] ?? 'N/A'); ?></span>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php echo htmlspecialchars($sample_ref); ?>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php echo htmlspecialchars($customer_ref); ?>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php echo htmlspecialchars($report['inspector_name'] ?? 'N/A'); ?>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php echo date('M d, Y - g:i A', strtotime($report['updated_at'])); ?>
                </td>
                <td style="padding:12px 15px; text-align:center; white-space:nowrap;">
                  <a href="../admin/view_qc_test_order.php?report_number=<?php echo htmlspecialchars($report['report_number']); ?>" target="_blank" style="display:inline-block; padding:8px 16px; background:#17a2b8; color:#fff; text-decoration:none; border-radius:4px; font-size:13px; font-weight:500; margin-right:5px; transition:background 0.3s;" onmouseover="this.style.background='#138496'" onmouseout="this.style.background='#17a2b8'">
                    <i class="fas fa-eye"></i> View
                  </a>
                  <form method="POST" style="display:inline; margin-right:5px;" onsubmit="return confirmApproval()">
                    <input type="hidden" name="checker_report_number" value="<?php echo htmlspecialchars($report['report_number']); ?>">
                    <button type="submit" name="checker_action" value="approved" style="padding:8px 16px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:13px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#218838'" onmouseout="this.style.background='#28a745'">
                      <i class="fas fa-check"></i> Approve
                    </button>
                  </form>
                  <button type="button" onclick="openCheckerRejectModal('<?php echo htmlspecialchars($report['report_number']); ?>')" style="padding:8px 16px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:13px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#c82333'" onmouseout="this.style.background='#dc3545'">
                      <i class="fas fa-times"></i> Reject
                    </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Admin Dashboard Section -->
  <?php if ($is_admin && !empty($pending_for_admin)): ?>
  <div style="margin-bottom:30px;">
    <!-- Back Button -->
    <div style="margin-bottom:20px;">
      <a href="../index.php" style="display:inline-block; padding:10px 20px; background:#6c757d; color:#fff; text-decoration:none; border-radius:6px; font-size:14px; transition:background 0.3s;">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
      </a>
    </div>
    
    <!-- Pending for Final Approval Section -->
    <div style="background:#fff; padding:25px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1);">
      <div style="border-bottom:2px solid #2196f3; padding-bottom:15px; margin-bottom:20px;">
        <h2 style="color:#333; margin:0; font-size:24px; font-weight:600;">Pending for Final Approval</h2>
        <p style="color:#666; margin:5px 0 0 0; font-size:14px;">QC Test Orders requiring admin approval (<?php echo count($pending_for_admin); ?> pending)</p>
      </div>
      
      <?php 
        // Group reports by reference (handle bulk references)
        $grouped_admin_reports = [];
        $bulk_admin_reference_groups = []; // Track bulk reference groups
        $processed_admin_reports = []; // Track which reports have been processed
        
        // First pass: Process reports with explicit bulk reference metadata
        foreach ($pending_for_admin as $idx => $report) {
          $test_data = json_decode($report['test_data'] ?? '{}', true);
          
          // Check if this is a bulk reference submission
          if (isset($test_data['is_bulk_reference']) && $test_data['is_bulk_reference'] && 
              isset($test_data['bulk_from_reference']) && isset($test_data['bulk_to_reference'])) {
            $from_ref = $test_data['bulk_from_reference'];
            $to_ref = $test_data['bulk_to_reference'];
            $bulk_count = $test_data['bulk_reference_count'] ?? 0;
            $bulk_key = $from_ref . '|' . $to_ref; // Use pipe separator for grouping key
            
            if (!isset($bulk_admin_reference_groups[$bulk_key])) {
              $bulk_admin_reference_groups[$bulk_key] = [
                'from' => $from_ref,
                'to' => $to_ref,
                'count' => $bulk_count,
                'reports' => []
              ];
            }
            $bulk_admin_reference_groups[$bulk_key]['reports'][] = $report;
            $processed_admin_reports[] = $idx;
          }
        }
        
        // Second pass: Detect sequential references that might be from bulk submissions
        $remaining_admin_reports = [];
        foreach ($pending_for_admin as $idx => $report) {
          if (!in_array($idx, $processed_admin_reports)) {
            $remaining_admin_reports[] = $report;
          }
        }
        
        // Group remaining reports by base reference pattern
        $base_admin_reference_groups = [];
        foreach ($remaining_admin_reports as $report) {
          $sample_ref = $report['sample_reference_id'] ?? '';
          
          // Extract base reference (everything before the last dash and number)
          if (preg_match('/^(.+)-(\d+)$/', $sample_ref, $matches)) {
            $base_ref = $matches[1];
            $roll_num = (int)$matches[2];
            
            if (!isset($base_admin_reference_groups[$base_ref])) {
              $base_admin_reference_groups[$base_ref] = [
                'base' => $base_ref,
                'references' => [],
                'reports' => []
              ];
            }
            $base_admin_reference_groups[$base_ref]['references'][$roll_num] = $sample_ref;
            $base_admin_reference_groups[$base_ref]['reports'][] = $report;
          } else {
            // No pattern match, treat as single reference
            $reference = $sample_ref ?: 'No Reference';
          if (!isset($grouped_admin_reports[$reference])) {
            $grouped_admin_reports[$reference] = [];
          }
          $grouped_admin_reports[$reference][] = $report;
        }
        }
        
        // Check if base reference groups have sequential references (likely bulk)
        foreach ($base_admin_reference_groups as $base_ref => $group) {
          $refs = $group['references'];
          ksort($refs); // Sort by roll number
          $roll_nums = array_keys($refs);
          
          // If we have 2+ sequential references, treat as bulk
          if (count($roll_nums) >= 2) {
            $min_roll = min($roll_nums);
            $max_roll = max($roll_nums);
            $from_ref = $base_ref . '-' . $min_roll;
            $to_ref = $base_ref . '-' . $max_roll;
            $bulk_key = $from_ref . '|' . $to_ref;
            
            // Check if this bulk group already exists (from explicit metadata)
            if (!isset($bulk_admin_reference_groups[$bulk_key])) {
              $bulk_admin_reference_groups[$bulk_key] = [
                'from' => $from_ref,
                'to' => $to_ref,
                'count' => count($refs),
                'reports' => $group['reports']
              ];
            } else {
              // Merge reports if group already exists
              $bulk_admin_reference_groups[$bulk_key]['reports'] = array_merge(
                $bulk_admin_reference_groups[$bulk_key]['reports'],
                $group['reports']
              );
              // Update count if needed
              if (count($refs) > $bulk_admin_reference_groups[$bulk_key]['count']) {
                $bulk_admin_reference_groups[$bulk_key]['count'] = count($refs);
              }
            }
          } else {
            // Single reference, add to regular grouping
            $reference = reset($refs); // Get the only reference
            if (!isset($grouped_admin_reports[$reference])) {
              $grouped_admin_reports[$reference] = [];
            }
            $grouped_admin_reports[$reference] = array_merge(
              $grouped_admin_reports[$reference],
              $group['reports']
            );
          }
        }
      ?>
      
      <?php 
        // Display bulk reference groups first
        foreach ($bulk_admin_reference_groups as $bulk_key => $bulk_group): 
          // Group reports by test name within this bulk reference group
          $admin_test_groups = [];
          foreach ($bulk_group['reports'] as $report) {
            $test_name = $report['test_name'] ?? 'Unknown Test';
            $test_key = $test_name . '|' . ($report['chosen_method'] ?? '');
            if (!isset($admin_test_groups[$test_key])) {
              $admin_test_groups[$test_key] = [
                'test_name' => $test_name,
                'method' => $report['chosen_method'] ?? '',
                'reports' => []
              ];
            }
            $admin_test_groups[$test_key]['reports'][] = $report;
          }
      ?>
      <div style="margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 20px; font-weight: 600; font-size: 16px;">
          <i class="fas fa-tag"></i> Reference Range: <?php echo htmlspecialchars($bulk_group['from']); ?> to <?php echo htmlspecialchars($bulk_group['to']); ?>
          <span style="float: right; font-size: 14px; opacity: 0.9;"><?php echo count($bulk_group['reports']); ?> report(s) | <?php echo $bulk_group['count']; ?> reference(s)</span>
        </div>
        <div style="overflow-x:auto;">
          <table style="width:100%; border-collapse:collapse; min-width:900px;">
            <thead>
              <tr style="background:#f8f9fa; border-bottom:2px solid #dee2e6;">
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Test Name</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Reference Range</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Customer Ref</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Tested By</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Submitted At</th>
                <th style="padding:12px 15px; text-align:center; font-weight:600; color:#495057; font-size:14px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($admin_test_groups as $test_key => $test_group): 
                $first_report = $test_group['reports'][0];
                $test_data = json_decode($first_report['test_data'], true);
                $customer_ref = $test_data['customer_reference'] ?? $test_data['product_reference'] ?? 'N/A';
                $report_numbers = array_column($test_group['reports'], 'report_number');
                $report_numbers_str = implode(',', $report_numbers);
                $report_count = count($test_group['reports']);
                $earliest_date = min(array_map(function($r) { return strtotime($r['updated_at']); }, $test_group['reports']));
                $latest_date = max(array_map(function($r) { return strtotime($r['updated_at']); }, $test_group['reports']));
              ?>
              <tr style="border-bottom:1px solid #dee2e6; transition:background 0.2s;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='#fff'">
                <td style="padding:12px 15px; font-weight:500; color:#333;">
                  <?php echo htmlspecialchars($test_group['test_name']); ?>
                  <?php if (!empty($test_group['method'])): ?>
                    <br><span style="font-size:12px; color:#6c757d;">Method: <?php echo htmlspecialchars($test_group['method']); ?></span>
                  <?php endif; ?>
                  <br><span style="font-size:11px; color:#999;"><?php echo $report_count; ?> report(s)</span>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php echo htmlspecialchars($bulk_group['from']); ?> to <?php echo htmlspecialchars($bulk_group['to']); ?>
                  <br><span style="font-size:12px; color:#6c757d;">(<?php echo $bulk_group['count']; ?> references)</span>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php echo htmlspecialchars($customer_ref); ?>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php echo htmlspecialchars($first_report['inspector_name'] ?? 'N/A'); ?>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php 
                    if ($earliest_date == $latest_date) {
                      echo date('M d, Y - g:i A', $earliest_date);
                    } else {
                      echo date('M d, Y - g:i A', $earliest_date) . '<br><span style="font-size:11px; color:#999;">to ' . date('M d, Y - g:i A', $latest_date) . '</span>';
                    }
                  ?>
                </td>
                <td style="padding:12px 15px; text-align:center; white-space:nowrap;">
                  <button type="button" onclick="viewBulkReports(['<?php echo implode("','", $report_numbers); ?>'])" style="display:inline-block; padding:8px 16px; background:#17a2b8; color:#fff; text-decoration:none; border:none; border-radius:4px; font-size:13px; font-weight:500; margin-right:5px; transition:background 0.3s; cursor:pointer;" onmouseover="this.style.background='#138496'" onmouseout="this.style.background='#17a2b8'">
                    <i class="fas fa-eye"></i> View (<?php echo $report_count; ?>)
                    </button>
                  <form method="POST" style="display:inline; margin-right:5px;" onsubmit="return confirmBulkAdminApproval(<?php echo $report_count; ?>)">
                    <input type="hidden" name="admin_report_numbers" value="<?php echo htmlspecialchars($report_numbers_str); ?>">
                    <button type="submit" name="admin_action" value="approved" style="padding:8px 16px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:13px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#218838'" onmouseout="this.style.background='#28a745'">
                      <i class="fas fa-check"></i> Approve All
                    </button>
                  </form>
                  <button type="button" onclick="openBulkAdminRejectModal('<?php echo htmlspecialchars($report_numbers_str); ?>', <?php echo $report_count; ?>)" style="padding:8px 16px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:13px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#c82333'" onmouseout="this.style.background='#dc3545'">
                      <i class="fas fa-times"></i> Reject All
                    </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endforeach; ?>
      
      <?php foreach ($grouped_admin_reports as $reference => $reports): ?>
      <div style="margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
        <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 20px; font-weight: 600; font-size: 16px;">
          <i class="fas fa-tag"></i> Reference: <?php echo htmlspecialchars($reference); ?>
          <span style="float: right; font-size: 14px; opacity: 0.9;"><?php echo count($reports); ?> report(s)</span>
        </div>
        <div style="overflow-x:auto;">
          <table style="width:100%; border-collapse:collapse; min-width:900px;">
            <thead>
              <tr style="background:#f8f9fa; border-bottom:2px solid #dee2e6;">
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Report No</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Sample</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Customer Ref</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Tested By</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Checked By</th>
                <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Submitted At</th>
                <th style="padding:12px 15px; text-align:center; font-weight:600; color:#495057; font-size:14px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($reports as $report): 
                $test_data = json_decode($report['test_data'], true);
                $sample_ref = $report['sample_reference_id'] ?? 'N/A';
                $customer_ref = $test_data['customer_reference'] ?? $test_data['product_reference'] ?? 'N/A';
              ?>
              <tr style="border-bottom:1px solid #dee2e6; transition:background 0.2s;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='#fff'">
                <td style="padding:12px 15px; font-weight:500; color:#333;">
                  <?php echo htmlspecialchars($report['report_number']); ?>
                  <br>
                  <span style="font-size:12px; color:#6c757d;"><?php echo htmlspecialchars($report['chosen_method'] ?? 'N/A'); ?></span>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php echo htmlspecialchars($sample_ref); ?>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php echo htmlspecialchars($customer_ref); ?>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php echo htmlspecialchars($report['inspector_name'] ?? 'N/A'); ?>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php echo htmlspecialchars($report['checked_by'] ?? 'Not checked'); ?>
                </td>
                <td style="padding:12px 15px; color:#495057; font-size:14px;">
                  <?php echo date('M d, Y - g:i A', strtotime($report['updated_at'])); ?>
                </td>
                <td style="padding:12px 15px; text-align:center; white-space:nowrap;">
                  <a href="../admin/view_qc_test_order.php?report_number=<?php echo htmlspecialchars($report['report_number']); ?>" target="_blank" style="display:inline-block; padding:8px 16px; background:#17a2b8; color:#fff; text-decoration:none; border-radius:4px; font-size:13px; font-weight:500; margin-right:5px; transition:background 0.3s;" onmouseover="this.style.background='#138496'" onmouseout="this.style.background='#17a2b8'">
                    <i class="fas fa-eye"></i> View
                  </a>
                  <button type="button" onclick="openApprovalModal('<?php echo htmlspecialchars($report['report_number']); ?>')" style="padding:8px 16px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:13px; font-weight:500; transition:background 0.3s; margin-right:5px;" onmouseover="this.style.background='#218838'" onmouseout="this.style.background='#28a745'">
                      <i class="fas fa-check"></i> Approve
                    </button>
                  <button type="button" onclick="openAdminRejectModal('<?php echo htmlspecialchars($report['report_number']); ?>')" style="padding:8px 16px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:13px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#c82333'" onmouseout="this.style.background='#dc3545'">
                      <i class="fas fa-times"></i> Reject
                    </button>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$is_checker || $is_admin): ?>
  <!-- Hide form from pure checkers, show only to testers and admin -->
  
  <!-- Back to Dashboard Link (for form users only) -->
    <div style="margin-bottom: 20px;">
      <a href="../index.php" class="clear-btn" style="text-decoration: none; display: inline-block;">
        ← Back to Dashboard
      </a>
                                </div>
  
                <form method="POST" action="" id="qc_test_form" novalidate>
    
    <!-- Hidden edit ID for resubmission -->
    <?php if ($edit_mode && !$edit_external_forward): ?>
    <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">
    
    <!-- Edit Mode Banner (Single Banner) -->
    <div style="background:#ff9800; color:#fff; padding:15px 20px; margin-bottom:25px; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.1);">
      <h3 style="margin:0 0 8px 0; font-size:18px; display:flex; align-items:center; gap:10px;">
        <i class="fas fa-edit"></i> Editing Rejected Report
      </h3>
      <p style="margin:0; font-size:14px; opacity:0.95;">
        <strong>Report No:</strong> <?php echo htmlspecialchars($existing_report['report_number']); ?> | 
        <strong>Status:</strong> <?php echo getStatusLabel($existing_report['status']); ?>
        <?php 
        $rejection_reason = '';
        if ($existing_report['status'] === 'rejected_by_checker' && !empty($existing_report['checker_remarks'])) {
            $rejection_reason = $existing_report['checker_remarks'];
        } elseif ($existing_report['status'] === 'rejected_by_approver' && !empty($existing_report['admin_remarks'])) {
            $rejection_reason = $existing_report['admin_remarks'];
        }
        if (!empty($rejection_reason)): 
        ?>
        <br><strong>Rejection Reason:</strong> <?php echo nl2br(htmlspecialchars($rejection_reason)); ?>
        <?php endif; ?>
      </p>
      <p style="margin:10px 0 0 0; font-size:13px; opacity:0.9;">
        <i class="fas fa-info-circle"></i> Make your corrections below and click Submit to resubmit for approval.
      </p>
    </div>
    <?php elseif ($edit_mode && $edit_external_forward): ?>
    <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">
    <div style="background:#e0e7ff; color:#1e1b4b; padding:15px 20px; margin-bottom:25px; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
      <h3 style="margin:0 0 6px 0; font-size:16px; display:flex; align-items:center; gap:8px;">
        <i class="fas fa-flask"></i> Forwarded External Test
      </h3>
      <p style="margin:0; font-size:13px; opacity:0.95;">
        <strong>Reference:</strong> <?php echo htmlspecialchars($existing_test_data['external_reference'] ?? $existing_report['sample_reference_id']); ?> |
        <strong>Report No:</strong> <?php echo htmlspecialchars($existing_report['report_number']); ?>
      </p>
      <p style="margin:8px 0 0 0; font-size:13px; opacity:0.9;">
        <i class="fas fa-info-circle"></i> AGM has provided the general information. Please perform the test and fill in the test measurements only.
      </p>
    </div>
    <?php endif; ?>

    <!-- Date/Time and Shift Display -->
    <div id="dateTimeDisplay" class="summary-info"></div>
    <div id="shiftBanner" class="summary-info"></div>

    <!-- Hidden datetime + shift -->
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">

    <!-- Report Number (only) -->
    <div class="form-group">
      <label>Report No.:</label>
      <input type="text" id="report_no" name="report_no" readonly class="readonly" value="<?php echo htmlspecialchars($edit_mode ? $existing_report['report_number'] : generateReportNumber()); ?>">
                                    </div>

    <!-- Hidden Sample Reference ID (kept for backend, not shown) -->
    <input type="hidden" id="sample_reference_id" name="sample_reference_id" value="<?php echo isset($_POST['sample_reference_id']) ? htmlspecialchars($_POST['sample_reference_id']) : generateSampleReferenceId(); ?>">

    <!-- Inspector -->
    <div class="form-group">
      <label>Inspector:</label>
      <input type="text" value="<?php echo htmlspecialchars($reporter_name); ?>" readonly class="readonly">
      <input type="hidden" name="inspector_id" value="<?php echo (int)$reporter_id; ?>">
                                </div>

    <!-- Product Type Selection -->
    <?php if (!$lock_general_fields): ?>
    <div class="form-group" style="background:#e8f4fd; padding:20px; border-radius:8px; margin-bottom:20px; border:2px solid #2196F3; clear: both; box-sizing: border-box;">
      <label style="font-weight:700; font-size:16px; color:#1976D2; margin-bottom:15px; display:block;">
        <i class="fas fa-box-open"></i> Product Type: <span style="color: red;">*</span>
      </label>
      <div style="display:flex; gap:30px; flex-wrap:wrap;">
        <?php if (!$is_admin): ?>
        <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:15px; padding:12px 20px; background:#fff; border:2px solid #2196F3; border-radius:8px; transition:all 0.3s;">
          <input type="radio" name="product_type" id="product_type_production" value="production" <?php echo ($product_type_default === 'production') ? 'checked' : ''; ?> onchange="handleProductTypeChange()" style="width:18px; height:18px; cursor:pointer;">
          <span style="font-weight:600; color:#1976D2;">
            <i class="fas fa-industry"></i> Production Product
          </span>
          <span style="font-size:13px; color:#666; margin-left:5px;">(From DB Reference)</span>
        </label>
        <?php else: ?>
        <!-- Admin/AGM sees both options -->
        <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:15px; padding:12px 20px; background:#fff; border:2px solid #2196F3; border-radius:8px; transition:all 0.3s;">
          <input type="radio" name="product_type" id="product_type_production" value="production" <?php echo ($product_type_default === 'production') ? 'checked' : ''; ?> onchange="handleProductTypeChange()" style="width:18px; height:18px; cursor:pointer;">
          <span style="font-weight:600; color:#1976D2;">
            <i class="fas fa-industry"></i> Production Product
          </span>
          <span style="font-size:13px; color:#666; margin-left:5px;">(From DB Reference)</span>
        </label>
        
        <label style="display:flex; align-items:center; gap:10px; cursor:pointer; font-size:15px; padding:12px 20px; background:#fff; border:2px solid #FF9800; border-radius:8px; transition:all 0.3s;">
          <input type="radio" name="product_type" id="product_type_external" value="external" <?php echo ($product_type_default === 'external') ? 'checked' : ''; ?> onchange="handleProductTypeChange()" style="width:18px; height:18px; cursor:pointer;">
          <span style="font-weight:600; color:#F57C00;">
            <i class="fas fa-parachute-box"></i> External/Outside Product
          </span>
          <span style="font-size:13px; color:#666; margin-left:5px;">(Vendor Sample - Manual Reference)</span>
        </label>
        <?php endif; ?>
      </div>
      <input type="hidden" id="is_external_product" name="is_external_product" value="<?php echo $product_type_default === 'external' ? '1' : '0'; ?>">
    </div>
    <?php else: ?>
    <!-- Product Type is locked - hidden from view, but value is preserved in hidden input -->
    <input type="hidden" name="product_type" value="<?php echo $product_type_default; ?>">
    <input type="hidden" id="is_external_product" name="is_external_product" value="<?php echo $product_type_default === 'external' ? '1' : '0'; ?>">
    <?php endif; ?>

    <!-- Sample Details Section - for main tests -->
    <div id="general-info-section" class="<?php echo $lock_general_fields ? 'locked-general-info' : ''; ?>" style="background:#f8f9fa; padding:40px; border-radius:8px; margin-bottom:30px; border:1px solid #dee2e6; clear: both; box-sizing: border-box;">
      <?php if ($lock_general_fields): ?>
      <div class="locked-banner">
        <i class="fas fa-lock"></i>
        AGM-provided general information (view only). Please record only the test measurements below.
      </div>
      <?php endif; ?>
      <div class="form-row">
        <div class="form-group">
          <label>Batch Information:</label>
          <input type="text" name="batch_information" id="qc_batch_info" placeholder="GT9.H1" readonly class="readonly" value="<?php echo $edit_mode && isset($existing_test_data['batch_information']) ? htmlspecialchars($existing_test_data['batch_information']) : ''; ?>">
        </div>
        
        <div class="form-group">
          <label>Sample Details:</label>
          <input type="text" name="sample_details" id="sample_details" <?php echo $lock_general_fields ? 'readonly class="readonly"' : ''; ?> value="<?php echo $edit_mode && isset($existing_test_data['sample_details']) ? htmlspecialchars($existing_test_data['sample_details']) : ''; ?>">
        </div>
        
        <div class="form-group">
          <label>Sample Collected From:</label>
          <input type="text" name="sample_collected_from" id="sample_collected_from" <?php echo $lock_general_fields ? 'readonly class="readonly"' : ''; ?> value="<?php echo $edit_mode && isset($existing_test_data['sample_collected_from']) ? htmlspecialchars($existing_test_data['sample_collected_from']) : ''; ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Sample Received Date & Time:</label>
          <input type="datetime-local" name="sample_received_datetime" id="sample_received_datetime" <?php echo $lock_general_fields ? 'readonly class="readonly"' : ''; ?> value="<?php echo $sample_received_datetime_value; ?>">
        </div>
        
        <div class="form-group">
          <label>Sample Production Date:</label>
          <input type="date" name="sample_production_date" id="sample_production_date" <?php echo $lock_general_fields ? 'readonly class="readonly"' : ''; ?> value="<?php echo $edit_mode && isset($existing_test_data['sample_production_date']) ? htmlspecialchars($existing_test_data['sample_production_date']) : ''; ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Temperature (°C):</label>
          <input type="number" name="temperature" id="temperature" step="0.1" <?php echo $lock_general_fields ? 'readonly class="readonly"' : ''; ?> value="<?php echo $edit_mode && isset($existing_test_data['temperature']) ? htmlspecialchars($existing_test_data['temperature']) : ''; ?>">
        </div>
        
        <div class="form-group">
          <label>RH (%):</label>
          <input type="number" name="rh_percentage" id="rh_percentage" step="0.1" <?php echo $lock_general_fields ? 'readonly class="readonly"' : ''; ?> value="<?php echo $edit_mode && isset($existing_test_data['rh_percentage']) ? htmlspecialchars($existing_test_data['rh_percentage']) : ''; ?>">
        </div>
        
        <div class="form-group">
          <label>Test Period From:</label>
          <input type="date" name="test_period_from" id="test_period_from" <?php echo $lock_general_fields ? 'readonly class="readonly"' : 'onchange="validateTestPeriod()"'; ?> value="<?php echo $edit_mode && isset($existing_test_data['test_period_from']) ? htmlspecialchars($existing_test_data['test_period_from']) : ''; ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Test Period To:</label>
          <input type="date" name="test_period_to" id="test_period_to" <?php echo $lock_general_fields ? 'readonly class="readonly"' : 'onchange="validateTestPeriod()"'; ?> value="<?php echo $edit_mode && isset($existing_test_data['test_period_to']) ? htmlspecialchars($existing_test_data['test_period_to']) : ''; ?>">
        </div>
        
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Reference: <span style="color: red;">*</span></label>
          
          <?php if ($lock_general_fields && $product_type_default === 'external'): ?>
            <input type="text" value="<?php echo htmlspecialchars($external_reference_display); ?>" readonly class="readonly" style="width:100%; padding:10px; border:2px solid #FF9800; border-radius:6px; background:#fffacd; font-weight:600; color:#F57C00;">
            <input type="hidden" id="external_reference" name="external_reference" value="<?php echo htmlspecialchars($external_reference_display); ?>">
          <?php else: ?>
            <?php 
            // Disable reference fields only when editing rejected QC test orders (not external forwarded tests)
            $disable_refs_in_edit = ($edit_mode && !$edit_external_forward && isset($existing_report) && in_array($existing_report['status'], ['rejected_by_checker', 'rejected_by_approver']));
            ?>
            <!-- Line Selection Buttons -->
            <div id="line_selection_buttons" style="display:<?php echo ($product_type_default === 'external') ? 'none' : 'flex'; ?>; gap:10px; margin-bottom:10px;">
              <button type="button" id="line1_btn" class="line-btn" onclick="filterByLine('L1')" style="padding:8px 16px; border:2px solid #3498db; border-radius:6px; background:#e3f2fd; color:#1565C0; font-weight:600; cursor:pointer;" <?php echo $disable_refs_in_edit ? 'disabled' : ''; ?>>
                Line 1
              </button>
              <button type="button" id="line2_btn" class="line-btn" onclick="filterByLine('L2')" style="padding:8px 16px; border:2px solid #3498db; border-radius:6px; background:#e3f2fd; color:#1565C0; font-weight:600; cursor:pointer;" <?php echo $disable_refs_in_edit ? 'disabled' : ''; ?>>
                Line 2
              </button>
              <button type="button" id="line_all_btn" class="line-btn active" onclick="filterByLine('all')" style="padding:8px 16px; border:2px solid #3498db; border-radius:6px; background:#2196F3; color:#ffffff; font-weight:600; cursor:pointer;" <?php echo $disable_refs_in_edit ? 'disabled' : ''; ?>>
                All Lines
              </button>
            </div>
            
            <!-- From/To Reference Selection (shown when line is selected) -->
            <div id="bulk_reference_selection" style="display:none; margin-bottom:10px; padding:10px; background:#f8f9fa; border:1px solid #ddd; border-radius:6px;">
              <?php if ($disable_refs_in_edit && !empty($bulk_from_ref) && !empty($bulk_to_ref)): ?>
                <!-- Display read-only reference range in edit mode -->
                <div style="padding:10px; background:#fff3cd; border:2px solid #ffc107; border-radius:6px; margin-bottom:10px;">
                  <div style="display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-info-circle" style="color:#856404; font-size:18px;"></i>
                    <div>
                      <strong style="color:#856404;">Original Reference Range (Cannot be changed):</strong>
                      <div style="margin-top:5px; font-size:16px; color:#333;">
                        <strong><?php echo htmlspecialchars($bulk_from_ref); ?></strong> to <strong><?php echo htmlspecialchars($bulk_to_ref); ?></strong>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endif; ?>
              <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <label style="font-weight:600; margin:0;">From Reference:</label>
                <select id="from_reference" name="from_reference" style="min-width:250px; padding:5px; border:1px solid #ccc; border-radius:4px; <?php echo $disable_refs_in_edit ? 'background:#f5f5f5; cursor:not-allowed;' : ''; ?>" onchange="updateReferenceRange(true); handleFromToReferenceChange();" <?php echo $disable_refs_in_edit ? 'disabled readonly' : ''; ?> required>
                  <option value="">-- Select From Reference --</option>
                </select>
                <label style="font-weight:600; margin:0;">To Reference:</label>
                <select id="to_reference" name="to_reference" style="min-width:250px; padding:5px; border:1px solid #ccc; border-radius:4px; <?php echo $disable_refs_in_edit ? 'background:#f5f5f5; cursor:not-allowed;' : ''; ?>" onchange="updateReferenceRange(false); handleFromToReferenceChange();" <?php echo $disable_refs_in_edit ? 'disabled readonly' : ''; ?> required>
                  <option value="">-- Select To Reference --</option>
                </select>
                <button type="button" onclick="applyBulkReferenceSelection()" style="padding:6px 12px; background:#3498db; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:600; <?php echo $disable_refs_in_edit ? 'opacity:0.5; cursor:not-allowed;' : ''; ?>" <?php echo $disable_refs_in_edit ? 'disabled' : ''; ?>>
                  Apply
                </button>
                <?php if (!$disable_refs_in_edit): ?>
                <button type="button" onclick="clearBulkReferenceSelection()" style="padding:6px 12px; background:#6c757d; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:600;">
                  Clear
                </button>
                <?php endif; ?>
              </div>
              
              <!-- Notification area for submitted tests -->
              <div id="submitted_tests_notification" style="display:none; margin-top:10px; padding:12px; background:#fff3cd; border:2px solid #ffc107; border-radius:6px;">
                <div style="display:flex; align-items:flex-start; gap:10px;">
                  <i class="fas fa-exclamation-triangle" style="color:#856404; font-size:18px; margin-top:2px;"></i>
                  <div style="flex:1;">
                    <strong style="color:#856404; display:block; margin-bottom:5px;">⚠️ Tests Already Submitted for This Range:</strong>
                    <div id="submitted_tests_list" style="color:#333; font-size:14px; line-height:1.6;">
                      <!-- List of submitted tests will be populated here -->
                    </div>
                    <small style="color:#856404; display:block; margin-top:8px; font-style:italic;">
                      These tests are disabled below. You can only submit different test methods for this reference range.
                    </small>
                  </div>
                </div>
              </div>
              
              <!-- Per-Reference Test Status Display (shown when Apply is clicked with bulk range) -->
              <div id="reference_test_status_container" style="display:none; margin-top:20px; padding:0; background:#ffffff; border:2px solid #e0e0e0; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.1);">
                <div style="padding:20px; background:linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius:12px 12px 0 0; color:white;">
                  <div style="display:flex; align-items:center; gap:12px;">
                    <i class="fas fa-list-check" style="font-size:24px;"></i>
                    <h4 style="margin:0; font-size:18px; font-weight:600;" id="reference_status_title">
                      Reference-Level Test Status
                    </h4>
                  </div>
                </div>
                <div id="reference_test_status_list" style="padding:20px; max-height:600px; overflow-y:auto;">
                  <!-- Per-reference status will be populated here -->
                </div>
                <div id="reference_status_summary" style="padding:15px; background:#f8f9fa; border-top:1px solid #e0e0e0; border-radius:0 0 12px 12px; font-size:14px; color:#495057;">
                  <!-- Summary will be shown here -->
                </div>
              </div>
              <!-- Hidden input to store the selected product_reference when line-based selection is used -->
              <input type="hidden" id="line_based_product_reference" name="product_reference" value="">
            </div>
            
            <!-- Production Product Reference Dropdown (shown when "All Lines" is selected) -->
            <select name="product_reference" id="product_reference" onchange="handleReferenceSelection(this.value)" style="display:<?php echo ($product_type_default === 'external') ? 'none' : 'block'; ?>;" <?php echo ($product_type_default === 'external' || $lock_general_fields || $disable_refs_in_edit) ? 'disabled' : ''; ?> <?php echo ($lock_general_fields || $disable_refs_in_edit) ? 'class="readonly"' : ''; ?>>
              <option value="">-- Select Reference --</option>
              <?php foreach($bundleReferences as $bundle): ?>
                <option value="<?php echo htmlspecialchars($bundle['reference']); ?>" data-is-bundle="true" data-base-ref="<?php echo htmlspecialchars($bundle['base_reference']); ?>" data-roll-count="<?php echo $bundle['roll_count']; ?>" data-line="<?php echo (strpos($bundle['reference'], 'L1') !== false) ? 'L1' : ((strpos($bundle['reference'], 'L2') !== false) ? 'L2' : ''); ?>" <?php echo ($current_reference_selection === $bundle['reference']) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($bundle['reference']); ?> (Bundle - <?php echo $bundle['roll_count']; ?> rolls)
                </option>
              <?php endforeach; ?>
              <?php foreach($references as $ref): 
                  if (!isset($ref['is_individual']) || !$ref['is_individual']): 
                    $lineIndicator = '';
                    if (strpos($ref['reference'], 'L1') !== false) {
                        $lineIndicator = 'L1';
                    } elseif (strpos($ref['reference'], 'L2') !== false) {
                        $lineIndicator = 'L2';
                    }
                ?>
                <option value="<?php echo htmlspecialchars($ref['reference']); ?>" data-is-bundle="false" data-line="<?php echo $lineIndicator; ?>" <?php echo ($current_reference_selection === $ref['reference']) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($ref['reference']); ?>
                </option>
              <?php endif; endforeach; ?>
            </select>
            
            
            <!-- External Product Reference (Manually set by AGM) -->
            <div id="external_reference_container" style="display:<?php echo ($product_type_default === 'external') ? 'block' : 'none'; ?>;">
              <input type="text" name="external_reference" id="external_reference" 
                     placeholder="Enter external reference" 
                     value="<?php echo htmlspecialchars($external_reference_value); ?>"
                     <?php echo ($lock_general_fields || $disable_refs_in_edit) ? 'readonly class="readonly" disabled' : 'onchange="checkAndDisableSubmittedTests(this.value)" onblur="checkAndDisableSubmittedTests(this.value)"'; ?>
                     <?php echo (!$lock_general_fields && !$disable_refs_in_edit && $product_type_default === 'external') ? 'required' : ''; ?>
                     style="background:#fffacd; border:2px solid #FF9800; font-weight:600; color:#F57C00; width:100%;">
            </div>
          <?php endif; ?>
          
          <div id="qc_reference_error" style="color:red; margin-top:5px; font-size:12px;"></div>
          <?php if ($is_admin): ?>
          <div id="external_reference_info" style="display:<?php echo ($product_type_default === 'external') ? 'block' : 'none'; ?>; margin-top:8px; padding:10px; background:#fff3e0; border-left:4px solid #FF9800; border-radius:4px; font-size:13px; color:#E65100;">
            <i class="fas fa-info-circle"></i> <strong>AGM:</strong> Please manually enter the external reference number.
          </div>
          <?php endif; ?>
        </div>
        
        <div class="form-group">
          <label>Customer Reference:</label>
          <input type="text" name="customer_reference" id="customer_reference" <?php echo $lock_general_fields ? 'readonly class="readonly"' : ''; ?> value="<?php echo $edit_mode && isset($existing_test_data['customer_reference']) ? htmlspecialchars($existing_test_data['customer_reference']) : ''; ?>">
        </div>
        
        <div class="form-group">
          <label>Sample Received From:</label>
          <input type="text" name="sample_received_from" id="sample_received_from" <?php echo $lock_general_fields ? 'readonly class="readonly"' : ''; ?> value="<?php echo $edit_mode && isset($existing_test_data['sample_received_from']) ? htmlspecialchars($existing_test_data['sample_received_from']) : ''; ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Lighthouse Reference (Optional):</label>
          <input type="text" name="lighthouse_reference" <?php echo $lock_general_fields ? 'readonly class="readonly"' : ''; ?> value="<?php echo $edit_mode && isset($existing_test_data['lighthouse_reference']) ? htmlspecialchars($existing_test_data['lighthouse_reference']) : ''; ?>">
        </div>
      </div>

      <div class="form-row" id="other-info-row">
        <div class="form-group" style="flex:100%;">
          <label>Other Information (Optional):</label>
          <textarea name="other_information" <?php echo $lock_general_fields ? 'readonly class="readonly"' : ''; ?> rows="2" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;"><?php echo $edit_mode && isset($existing_test_data['other_information']) ? htmlspecialchars($existing_test_data['other_information']) : ''; ?></textarea>
        </div>
      </div>
    </div>

    <!-- Simplified General Info Section - for fiber/yarn tests -->
    <div id="fiber-info-section" style="background:#f8f9fa; padding:25px; border-radius:8px; margin-bottom:30px; border:1px solid #dee2e6; display:none;">
      <div class="form-row">
        <div class="form-group">
          <label>Sample Name:</label>
          <input type="text" name="fiber_sample_name">
        </div>
        
        <div class="form-group">
          <label>Reference No: <span style="color: red;">*</span></label>
          <select name="fiber_reference_no" id="fiber_reference_no" onchange="loadFiberReferenceData(this.value)" <?php echo $disable_refs_in_edit ? 'disabled readonly' : ''; ?>>
            <option value="">-- Select Reference --</option>
            <?php foreach($references as $ref): ?>
              <option value="<?php echo htmlspecialchars($ref['reference']); ?>">
                <?php echo htmlspecialchars($ref['reference']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div id="fiber_reference_error" style="color:red; margin-top:5px; font-size:12px;"></div>
        </div>
      </div>
      
      <div class="form-row">
        <div class="form-group">
          <label>Sample Received Date:</label>
          <input type="date" name="fiber_sample_received_date">
        </div>
        
        <div class="form-group">
          <label>Sample Tested:</label>
          <input type="date" name="fiber_sample_tested_date">
        </div>
      </div>
    </div>

    <!-- Tenacity of Yarn General Info Section -->
    <div id="yarn-info-section" style="background:#f8f9fa; padding:25px; border-radius:8px; margin-bottom:30px; border:1px solid #dee2e6; display:none;">
      <div class="form-row">
        <div class="form-group">
          <label>Sample Description: <span style="color: red;">*</span></label>
          <input type="text" name="yarn_sample_description">
        </div>
        
        <div class="form-group">
          <label>Sample Received From: <span style="color: red;">*</span></label>
          <input type="text" name="yarn_sample_received_from">
        </div>
        
        <div class="form-group">
          <label>Sample Collected From: <span style="color: red;">*</span></label>
          <input type="text" name="yarn_sample_collected_from">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Reference: <span style="color: red;">*</span></label>
          <select name="yarn_reference_no" id="yarn_reference_no" onchange="loadYarnReferenceData(this.value)" <?php echo $disable_refs_in_edit ? 'disabled readonly' : ''; ?>>
            <option value="">-- Select Reference --</option>
            <?php foreach($references as $ref): ?>
              <option value="<?php echo htmlspecialchars($ref['reference']); ?>">
                <?php echo htmlspecialchars($ref['reference']); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div id="yarn_reference_error" style="color:red; margin-top:5px; font-size:12px;"></div>
        </div>
        
        <div class="form-group">
          <label>Received Date: <span style="color: red;">*</span></label>
          <input type="date" name="yarn_received_date">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Test Start Date: <span style="color: red;">*</span></label>
          <input type="date" name="yarn_test_start_date" id="yarn_test_start_date" onchange="validateYarnTestDates()">
        </div>
        
        <div class="form-group">
          <label>Test End Date: <span style="color: red;">*</span></label>
          <input type="date" name="yarn_test_end_date" id="yarn_test_end_date" onchange="validateYarnTestDates()">
          <div id="yarn_date_error" style="color:red; margin-top:5px; font-size:12px;"></div>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label>Test Temperature (°C): <span style="color: red;">*</span></label>
          <input type="number" name="yarn_temperature" step="0.1">
        </div>
        
        <div class="form-group">
          <label>RH (%): <span style="color: red;">*</span></label>
          <input type="number" name="yarn_rh_percentage" step="0.1">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:100%;">
          <label>Others Information:</label>
          <textarea name="yarn_other_information" rows="2" placeholder="Enter others information" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;"></textarea>
        </div>
      </div>
    </div>

    <!-- Test Standards Selection -->
    <div class="form-group">
      <label>Available Test Standards:</label>
      <div style="margin-bottom: 15px;">
        <strong>Selected Methods: <span id="selected-count" style="color: #27ae60;"><?php echo ($edit_mode && $edit_external_forward) ? max(1, (int)$total_available_tests) : 0; ?></span></strong> | 
        <strong>Total Available: <span id="total-count" style="color: #3498db;"><?php echo $total_available_tests; ?></span></strong>
                            </div>
                                        
      <div class="test-section">
        <?php
        // Get already submitted tests for the current reference to disable checkboxes
        $submitted_tests = [];
        if (!$edit_mode) {
            // Determine the reference being used
            $current_reference = '';
            if (isset($_POST['external_reference']) && !empty($_POST['external_reference'])) {
                $current_reference = $_POST['external_reference'];
            } elseif (isset($_POST['product_reference']) && !empty($_POST['product_reference'])) {
                $current_reference = $_POST['product_reference'];
            } elseif (isset($_POST['from_reference']) && !empty($_POST['from_reference'])) {
                // For bulk selection, check the from_reference
                $current_reference = $_POST['from_reference'];
            } elseif (isset($_POST['individual_roll_reference']) && !empty($_POST['individual_roll_reference'])) {
                $current_reference = $_POST['individual_roll_reference'];
            }
            
            // If we have a reference, query for already submitted tests
            if (!empty($current_reference)) {
                $stmt_submitted = $conn->prepare("
                    SELECT ts.test_name, ts.standard_code
                    FROM qc_test_orders qto
                    INNER JOIN test_standards ts ON qto.test_standard_id = ts.id
                    WHERE qto.sample_reference_id = ?
                ");
                if ($stmt_submitted) {
                    $stmt_submitted->bind_param("s", $current_reference);
                    $stmt_submitted->execute();
                    $result_submitted = $stmt_submitted->get_result();
                    while ($row = $result_submitted->fetch_assoc()) {
                        $submitted_tests[$row['test_name'] . '_' . $row['standard_code']] = true;
                    }
                    $stmt_submitted->close();
                }
            }
        }
        ?>
        <?php 
        // In edit mode for rejected reports, get the submitted test name and method
        $edit_test_name = '';
        $edit_method = '';
        if ($edit_mode && !$edit_external_forward && isset($existing_report)) {
            $edit_test_name = $existing_report['test_name'] ?? '';
            $edit_method = $existing_report['chosen_method'] ?? '';
        }
        
        foreach ($display_test_methods as $test_name => $methods): 
          // In edit mode for rejected reports: only show the test that was submitted
          if ($edit_mode && !$edit_external_forward && !empty($edit_test_name)) {
              // If we have bulk submitted methods, check if this test name is in the list
              if (!empty($submitted_test_methods)) {
                  if (!isset($submitted_test_methods[$test_name])) {
                      continue; // Skip this test entirely - it wasn't submitted
                  }
              } else {
                  // Single report edit - only show the exact test name
                  if ($test_name !== $edit_test_name) {
                      continue; // Skip this test - it's not the one being edited
                  }
              }
          }
        ?>
          <div class="test-item" style="margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 6px; overflow: visible;">
            <div style="display: flex; align-items: center; gap: 15px; width: 100%;">
              <div style="min-width: 200px; max-width: 200px; font-weight: bold; flex-shrink: 0;">
                <?php echo htmlspecialchars($test_name); ?>
                        </div>
              <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; flex: 1; min-width: 300px; overflow: visible;">
                <?php 
                  // Display all methods as checkboxes
                  foreach ($methods as $method):
                ?>
                  <?php
                    if ($edit_mode && $edit_external_forward && $locked_test_method && $method !== $locked_test_method) {
                        continue;
                    }
                    
                    // In edit mode for rejected reports: only show the method that was submitted
                    if ($edit_mode && !$edit_external_forward && !empty($edit_method)) {
                        // If we have bulk submitted methods, check if this method is in the list for this test
                        if (!empty($submitted_test_methods)) {
                            if (isset($submitted_test_methods[$test_name])) {
                                if (!in_array($method, $submitted_test_methods[$test_name])) {
                                    continue; // Skip this method - it wasn't submitted
                                }
                            } else {
                                continue; // Test name not in submitted list
                            }
                        } else {
                            // Single report edit - only show the exact method
                            if ($method !== $edit_method || $test_name !== $edit_test_name) {
                                continue; // Skip this method - it's not the one being edited
                            }
                        }
                    }
                  ?>
                  <?php
                    // Check if this test has already been submitted
                    $test_key = $test_name . '_' . $method;
                    $is_already_submitted = isset($submitted_tests[$test_key]);
                    
                    // In edit mode with bulk reference: only enable methods that were submitted
                    $is_disabled = false;
                    $is_checked = false;
                    if ($edit_mode && !empty($submitted_test_methods)) {
                        // Check if this test name has submitted methods
                        if (isset($submitted_test_methods[$test_name])) {
                            // Only enable if this method was submitted
                            $is_disabled = !in_array($method, $submitted_test_methods[$test_name]);
                            $is_checked = in_array($method, $submitted_test_methods[$test_name]);
                        } else {
                            // This test name was not submitted at all - disable all methods
                            $is_disabled = true;
                        }
                    } else {
                        // Regular logic: disable if already submitted and not in edit mode
                    $is_disabled = $is_already_submitted && !$edit_mode;
                        // In edit mode for single report, check the method
                        if ($edit_mode && !$edit_external_forward && !empty($edit_method)) {
                            $is_checked = ($method === $edit_method && $test_name === $edit_test_name);
                        }
                    }
                  ?>
                  <label style="display: <?php echo $is_disabled ? 'flex' : 'inline-flex'; ?>; align-items: center; flex-wrap: nowrap; gap: 6px; <?php echo $is_disabled ? 'cursor: not-allowed; width: 100%; margin-bottom: 5px;' : 'cursor: pointer;'; ?> background: <?php echo $is_disabled ? '#f9fafb' : '#f8f9fa'; ?>; padding: 8px 12px; border-radius: 6px; border: <?php echo $is_disabled ? '2px solid #e5e7eb' : '1px solid #dee2e6'; ?>; <?php echo $is_disabled ? 'border-left: 4px solid #dc3545;' : ''; ?> transition: all 0.2s ease; white-space: nowrap; <?php echo $is_disabled ? 'flex-shrink: 1;' : 'flex-shrink: 0;'; ?>" <?php 
                    if ($is_disabled): 
                        if ($is_already_submitted && !$edit_mode): 
                            // Already submitted test - show notification
                            echo 'onclick="event.preventDefault(); showToast(\'⚠️ This test method (' . htmlspecialchars($test_name) . ' - ' . htmlspecialchars($method) . ') has already been submitted for the selected reference. Please select a different test method.\', \'warning\', 5000); return false;"';
                        else: 
                            // Bulk group editing scenario
                            echo 'onclick="showToast(\'This test method was not submitted in the bulk group. Only submitted methods can be edited.\', \'warning\', 4000); return false;"';
                        endif;
                    endif; 
                  ?>>
                    <input type="checkbox" 
                           name="test_<?php echo md5($test_name . '_' . $method); ?>"
                           id="test_<?php echo md5($test_name . '_' . $method); ?>"
                           data-test-name="<?php echo htmlspecialchars($test_name); ?>"
                           data-method="<?php echo htmlspecialchars($method); ?>"
                           class="test-checkbox"
                           <?php if ($is_disabled): ?>
                               disabled readonly data-already-submitted="true" 
                               title="<?php echo $is_already_submitted && !$edit_mode ? 'This test method has already been submitted for the selected reference.' : 'This test method was not submitted in the bulk group. Only submitted methods can be edited.'; ?>"
                               onclick="event.preventDefault(); event.stopPropagation(); event.stopImmediatePropagation(); this.checked = false; <?php 
                                 if ($is_already_submitted && !$edit_mode): 
                                     echo "showToast('⚠️ This test method (" . htmlspecialchars($test_name, ENT_QUOTES) . " - " . htmlspecialchars($method, ENT_QUOTES) . ") has already been submitted for the selected reference. Please select a different test method.', 'warning', 5000);";
                                 else:
                                     echo "showToast('This test method was not submitted in the bulk group.', 'warning', 4000);";
                                 endif;
                               ?> return false;"
                               onchange="event.preventDefault(); event.stopPropagation(); event.stopImmediatePropagation(); this.checked = false; <?php 
                                 if ($is_already_submitted && !$edit_mode): 
                                     echo "showToast('⚠️ This test method (" . htmlspecialchars($test_name, ENT_QUOTES) . " - " . htmlspecialchars($method, ENT_QUOTES) . ") has already been submitted for the selected reference. Please select a different test method.', 'warning', 5000);";
                                 else:
                                     echo "showToast('This test method was not submitted in the bulk group.', 'warning', 4000);";
                                 endif;
                               ?> return false;"
                           <?php elseif ($is_checked && $edit_mode): ?>
                               checked
                           <?php elseif ($edit_mode && $edit_external_forward && (!$locked_test_method || $locked_test_method === $method)): ?>
                               checked onclick="return false;" onkeydown="return false;" data-locked-test="1"
                           <?php endif; ?>
                           style="transform: scale(1.2); <?php echo $is_disabled ? 'pointer-events: none; cursor: not-allowed;' : ''; ?>"
                           onchange="<?php 
                             if ($is_disabled): 
                                 if ($is_already_submitted && !$edit_mode): 
                                     echo 'event.preventDefault(); event.stopPropagation(); this.checked = false; showToast(\'⚠️ This test method (' . htmlspecialchars($test_name, ENT_QUOTES) . ' - ' . htmlspecialchars($method, ENT_QUOTES) . ') has already been submitted for the selected reference. Please select a different test method.\', \'warning\', 5000); return false;';
                                 else:
                                     echo 'event.preventDefault(); event.stopPropagation(); this.checked = false; showToast(\'This test method was not submitted in the bulk group.\', \'warning\', 4000); return false;';
                                 endif;
                             else: 
                                 echo 'handleTestSelection(this)';
                             endif; 
                           ?>"
                           onclick="<?php 
                             if ($is_disabled): 
                                 if ($is_already_submitted && !$edit_mode): 
                                     echo 'event.preventDefault(); event.stopPropagation(); this.checked = false; showToast(\'⚠️ This test method (' . htmlspecialchars($test_name, ENT_QUOTES) . ' - ' . htmlspecialchars($method, ENT_QUOTES) . ') has already been submitted for the selected reference. Please select a different test method.\', \'warning\', 5000); return false;';
                                 else:
                                     echo 'event.preventDefault(); event.stopPropagation(); this.checked = false; showToast(\'This test method was not submitted in the bulk group.\', \'warning\', 4000); return false;';
                                 endif;
                             endif; 
                           ?>">
                    <span style="font-size: 12px; font-weight: 500; color: <?php echo $is_disabled ? '#6b7280' : '#495057'; ?>; <?php echo $is_disabled ? 'text-decoration: line-through; text-decoration-color: #dc3545; text-decoration-thickness: 2px;' : ''; ?> flex-shrink: 0; white-space: nowrap;">
                      <?php echo htmlspecialchars($method); ?>
                    </span>
                      <?php if ($is_disabled): ?>
                      <span class="already-submitted-badge" style="flex-shrink: 0; font-size: 9px; padding: 3px 8px;">
                        <i class="fas fa-lock" style="font-size: 9px;"></i>
                        <span>Already Submitted</span>
                    </span>
                      <span class="test-info-tooltip" style="flex-shrink: 0;">
                        <i class="fas fa-info-circle tooltip-icon" style="font-size: 11px;"></i>
                        <span class="tooltip-content">This test method has already been submitted for the selected reference. Select a different test method to proceed.</span>
                      </span>
                    <?php endif; ?>
                    <?php if ($edit_mode && $edit_external_forward && (!$locked_test_method || $locked_test_method === $method)): ?>
                        <input type="hidden" name="test_<?php echo md5($test_name . '_' . $method); ?>" value="on">
                    <?php endif; ?>
                                                    </label>
                <?php endforeach; ?>
                                </div>
                                </div>
            <!-- Test Parameters Section (initially hidden, only shown for testers) -->
            <?php if (!$is_admin): ?>
            <div id="params_<?php echo md5($test_name); ?>" class="test-parameters" style="display: none; margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
              <div id="params_content_<?php echo md5($test_name); ?>"></div>
                            </div>
            <?php endif; ?>
          </div>
                                        <?php endforeach; ?>
                        </div>
                    </div>

    <div class="actions">
      <button type="submit" name="submit_order" class="submit-btn" id="submit_btn">
         Submit
                        </button>
      <button type="button" class="clear-btn" onclick="clearForm()">Clear</button>
                        </div>
                </form>
  <?php endif; ?>
  <!-- End of form - hidden from checkers -->
  
  <?php if ($is_checker && !$is_admin && empty($pending_for_checker)): ?>
  <!-- Message for checker when no pending reports and form is hidden -->
  <div style="background:#e3f2fd; border:1px solid #2196f3; padding:30px; border-radius:8px; text-align:center; margin-top:20px;">
    <h3 style="color:#1976d2; margin-top:0;">✅ No QC Test Orders Pending for Checking</h3>
    <p style="color:#555; font-size:16px;">All first 5 tests (Thickness, GSM, Strip Tensile, CBR, Grab Tensile) have been checked.</p>
    <p style="color:#666; font-size:14px; margin-top:10px;">New test orders will appear here when testers submit them.</p>
    <div style="margin-top:20px;">
      <a href="../index.php" style="display:inline-block; padding:12px 24px; background:#2196f3; color:#fff; text-decoration:none; border-radius:6px; font-size:14px;">
        ← Back to Dashboard
      </a>
    </div>
  </div>
  <?php endif; ?>
                
                <script>
                // Sync all summary data to hidden inputs before submit
                function syncAllSummaryData() {
                    console.log('Syncing all summary data...');
                    // This function will be called before form submission to ensure all calculated values are saved
                    return true;
                }
                
                // Validate QC Form before submission
                function validateQCFormBeforeSubmit(event) {
                    try {
                        console.log('🔍 Validating QC Form before submission...');
                        
                        // First sync all summary data
                        if (typeof syncAllSummaryData === 'function') {
                            try {
                                syncAllSummaryData();
                            } catch (e) {
                                console.error('Error in syncAllSummaryData:', e);
                                // Continue with validation even if sync fails
                            }
                        }
                        
                        // Get form elements
                    const isExternalProductFlag = document.getElementById('is_external_product');
                    const productReference = document.getElementById('product_reference');
                    const externalReference = document.getElementById('external_reference');
                    
                    if (!isExternalProductFlag) {
                        console.error('❌ is_external_product flag not found!');
                        return true; // Let form submit if we can't validate
                    }
                    
                    const isExternalProduct = isExternalProductFlag.value === '1';
                    const productRefValue = productReference?.value || '';
                    const externalRefValue = externalReference?.value || '';
                    
                    console.log('📋 Form validation state:', {
                        isExternalProduct: isExternalProduct,
                        productRefValue: productRefValue,
                        externalRefValue: externalRefValue,
                        productRefDisabled: productReference?.disabled,
                        externalRefDisplay: externalReference?.style.display
                    });
                    
                    // Validate reference based on product type
                    if (isExternalProduct) {
                        // External product - validate external reference
                        if (!externalRefValue || externalRefValue.trim() === '') {
                            console.error('❌ External reference validation failed');
                            showToast('External Reference is Required!<br><br>Please enter an external reference number.', 'error', 5000);
                            if (event) event.preventDefault();
                            if (externalReference) {
                                externalReference.focus();
                                externalReference.style.border = '2px solid #e74c3c';
                            }
                            return false;
                        }
                        
                        // AGM can enter any format - no strict validation needed
                        // Just ensure it's not empty (already checked above)
                        console.log('✅ External reference validated:', externalRefValue);
                    } else {
                        // Production product - check product reference
                        // Check if line-based selection is active (Line 1 or Line 2)
                        const fromReference = document.getElementById('from_reference');
                        const toReference = document.getElementById('to_reference');
                        const bulkRefSelection = document.getElementById('bulk_reference_selection');
                        const isLineBasedSelection = bulkRefSelection && bulkRefSelection.style.display !== 'none' && bulkRefSelection.style.display !== '';
                        
                        console.log('📋 Line-based selection check:', {
                            bulkRefSelection: !!bulkRefSelection,
                            display: bulkRefSelection?.style.display,
                            isLineBasedSelection: isLineBasedSelection
                        });
                        
                        if (isLineBasedSelection) {
                            // Line-based selection: check From/To references
                            const fromRefValue = fromReference?.value || '';
                            const toRefValue = toReference?.value || '';
                            
                            if (!fromRefValue || fromRefValue.trim() === '' || !toRefValue || toRefValue.trim() === '') {
                                console.error('❌ From/To reference validation failed');
                                showToast('Reference Range is Required!<br><br>Please select both "From Reference" and "To Reference" from the dropdown lists.', 'error', 5000);
                                if (event) event.preventDefault();
                                if (fromReference && !fromRefValue) {
                                    fromReference.focus();
                                    fromReference.style.border = '2px solid #e74c3c';
                                } else if (toReference && !toRefValue) {
                                    toReference.focus();
                                    toReference.style.border = '2px solid #e74c3c';
                                }
                                return false;
                            }
                            console.log('✅ From/To references validated:', fromRefValue, 'to', toRefValue);
                        } else {
                            // Standard selection: check product reference dropdown
                            // Only validate if the dropdown is visible and not disabled
                            const productRefVisible = productReference && 
                                                    productReference.style.display !== 'none' && 
                                                    productReference.style.display !== '' &&
                                                    !productReference.disabled;
                            
                            if (productRefVisible && (!productRefValue || productRefValue.trim() === '')) {
                                console.error('❌ Product reference validation failed');
                                showToast('Reference is Required!<br><br>Please select a reference from the dropdown list.', 'error', 5000);
                                if (event) event.preventDefault();
                                if (productReference) {
                                    productReference.focus();
                                    productReference.style.border = '2px solid #e74c3c';
                                }
                                return false;
                            }
                            console.log('✅ Product reference validated:', productRefValue, 'Visible:', productRefVisible);
                        }
                        
                        // Check if bundle is selected and individual roll is required (only for standard selection)
                        if (!isLineBasedSelection) {
                            const selectedOption = productReference.options[productReference.selectedIndex];
                            // Bundle detection removed - no longer required
                        }
                    }
                    
                    // CRITICAL: Check if any disabled/already-submitted checkboxes are checked
                    const checkedDisabledTests = [];
                    document.querySelectorAll('.test-checkbox:checked').forEach(checkbox => {
                        if (checkbox.disabled || checkbox.hasAttribute('data-already-submitted') || checkbox.readOnly) {
                            const testName = checkbox.getAttribute('data-test-name');
                            const method = checkbox.getAttribute('data-method');
                            checkedDisabledTests.push((testName || 'Unknown') + ' - ' + (method || 'Unknown'));
                            // Force uncheck
                            checkbox.checked = false;
                        }
                    });
                    
                    if (checkedDisabledTests.length > 0) {
                        console.error('❌ Blocked submission: Attempted to submit already-submitted tests:', checkedDisabledTests);
                        
                        // Modern toast notification instead of alert
                        const blockedList = checkedDisabledTests.map(test => `• ${test}`).join('<br>');
                        showToast(
                            `You cannot submit tests that have already been submitted for the selected reference.<br><br><strong>Blocked tests:</strong><br>${blockedList}`,
                            'error',
                            8000
                        );
                        
                        // Add visual feedback - shake animation on form
                        const form = document.getElementById('qc_test_form');
                        if (form) {
                            form.style.animation = 'shake 0.5s';
                            setTimeout(() => {
                                form.style.animation = '';
                            }, 500);
                        }
                        
                        // Scroll to first blocked test
                        const firstBlocked = document.querySelector('.test-checkbox[data-already-submitted="true"]');
                        if (firstBlocked) {
                            firstBlocked.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            firstBlocked.closest('label')?.style.setProperty('background', '#fff5f5', 'important');
                            setTimeout(() => {
                                firstBlocked.closest('label')?.style.removeProperty('background');
                            }, 2000);
                        }
                        
                        if (event) event.preventDefault();
                        return false;
                    }
                    
                    // All validations passed
                    console.log('✅ Form validation passed - submitting form');
                    
                    // Make sure form can submit
                    const form = document.getElementById('qc_test_form');
                    if (form) {
                        console.log('✅ Form found, ready to submit');
                    }
                    
                    return true;
                } catch (error) {
                    // If there's any error, always allow form to submit (fail open)
                    return true;
                }
                }
                
                // Override form submit to always allow submission
                document.addEventListener('DOMContentLoaded', function() {
                    const form = document.getElementById('qc_test_form');
                    if (form) {
                        form.addEventListener('submit', function(e) {
                            // Always allow form to submit
                            return true;
                        });
                    }
                    
                    // Prevent checking disabled checkboxes - add to all checkboxes
                    // Use a flag per checkbox to prevent multiple toasts
                    const checkboxToastFlags = new WeakMap();
                    
                    document.querySelectorAll('.test-checkbox').forEach(checkbox => {
                        // Prevent click on disabled checkboxes
                        checkbox.addEventListener('click', function(e) {
                            if (this.disabled || this.hasAttribute('data-already-submitted')) {
                                e.preventDefault();
                                e.stopPropagation();
                                e.stopImmediatePropagation();
                                this.checked = false;
                                
                                // Only show toast if not already shown recently for this checkbox
                                const lastToastTime = checkboxToastFlags.get(this) || 0;
                                const now = Date.now();
                                if (now - lastToastTime > 1000) { // 1 second cooldown
                                    checkboxToastFlags.set(this, now);
                                    showToast('This test has already been submitted for the selected reference and cannot be selected again.', 'warning', 4000);
                                }
                                return false;
                            }
                        }, true); // Use capture phase
                        
                        // Prevent change on disabled checkboxes (no toast on change, only on click)
                        checkbox.addEventListener('change', function(e) {
                            if (this.disabled || this.hasAttribute('data-already-submitted')) {
                                e.preventDefault();
                                e.stopPropagation();
                                e.stopImmediatePropagation();
                                this.checked = false;
                                return false;
                            }
                        }, true); // Use capture phase
                        
                        // Prevent mousedown on disabled checkboxes
                        checkbox.addEventListener('mousedown', function(e) {
                            if (this.disabled || this.hasAttribute('data-already-submitted')) {
                                e.preventDefault();
                                e.stopPropagation();
                                e.stopImmediatePropagation();
                                return false;
                            }
                        }, true); // Use capture phase
                    });
                    
                    // Check submitted tests on page load if reference is already selected
                    setTimeout(function() {
                        const productRef = document.getElementById('product_reference');
                        const externalRef = document.getElementById('external_reference');
                        const individualRollRef = document.getElementById('individual_roll_reference');
                        const fromRef = document.getElementById('from_reference');
                        
                        let currentRef = '';
                        if (fromRef && fromRef.value) {
                            currentRef = fromRef.value;
                        } else if (individualRollRef && individualRollRef.value) {
                            currentRef = individualRollRef.value;
                        } else if (productRef && productRef.value) {
                            currentRef = productRef.value;
                        } else if (externalRef && externalRef.value) {
                            currentRef = externalRef.value;
                        }
                        
                        if (currentRef) {
                            checkAndDisableSubmittedTests(currentRef);
                        }
                    }, 1000); // Delay to ensure DOM is ready
                });
                </script>
                                </div>

    <script>

// Load user preferences (from database + session)
        <?php 
        error_log("QC_LAST_GENERAL Data: " . json_encode($user_qc_prefs));
        ?>
        console.log('=== QC PREFERENCES DEBUG ===');
        console.log('User ID: <?php echo $reporter_id; ?>');
        console.log('User QC Preferences:', <?php echo json_encode($user_qc_prefs); ?>);
        console.log('Preference data count:', Object.keys(<?php echo json_encode($user_qc_prefs); ?>).length);
        window.QC_LAST_GENERAL = <?php echo json_encode($user_qc_prefs); ?>;
        console.log('window.QC_LAST_GENERAL set:', window.QC_LAST_GENERAL);
        console.log('=== END DEBUG ===');
        
        // Show debug info if no preferences loaded
        <?php if (empty($user_qc_prefs)): ?>
        console.warn('⚠️ NO PREFERENCES LOADED - You may need to submit a QC test order first');
        console.warn('This will happen on your first submission or if the database table is missing');
        <?php endif; ?>
        
        // Make test methods available to JavaScript for reference status checking
        window.AVAILABLE_TESTS = <?php 
            $jsTests = [];
            foreach ($display_test_methods as $test_name => $methods) {
                foreach ($methods as $method) {
                    $jsTests[] = [
                        'testName' => $test_name,
                        'method' => $method
                    ];
                }
            }
            echo json_encode($jsTests);
        ?>;
        console.log('Available tests loaded:', window.AVAILABLE_TESTS.length);
// Load fiber reference data when reference is selected
function loadFiberReferenceData(reference) {
    const errorDiv = document.getElementById('fiber_reference_error');
    if (errorDiv) {
        errorDiv.textContent = '';
        errorDiv.style.background = '';
        errorDiv.style.padding = '';
    }
    
    if (!reference || reference === '') {
        // Show all test checkboxes
        document.querySelectorAll('.test-checkbox').forEach(cb => {
            const label = cb.closest('label');
            if (label) label.style.display = '';
        });
        return;
    }
    
    fetch(`api/get_reference_data.php?reference=${encodeURIComponent(reference)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // You can populate other fiber-related fields here if needed from the reference
            } else {
                document.getElementById('fiber_reference_error').textContent = data.error;
            }
        })
        .catch(err => {
            console.error('Error loading reference data:', err);
            document.getElementById('fiber_reference_error').textContent = 'Error loading reference data';
        });
    
    // Fetch already submitted tests for this reference and hide them
    fetch(`api/get_submitted_tests.php?reference=${encodeURIComponent(reference)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.tests) {
                // Show all checkboxes first
                document.querySelectorAll('.test-checkbox').forEach(cb => {
                    const label = cb.closest('label');
                    if (label) label.style.display = '';
                });
                
                // Hide checkboxes for already submitted tests
                data.tests.forEach(test => {
                    const testName = test.test_name;
                    const method = test.method;
                    // Find and hide the checkbox
                    document.querySelectorAll('.test-checkbox').forEach(cb => {
                        if (cb.getAttribute('data-test-name') === testName && 
                            cb.getAttribute('data-method') === method) {
                            const label = cb.closest('label');
                            if (label) {
                                label.style.display = 'none';
                                // If this checkbox was selected, uncheck it and reset the form
                                if (cb.checked) {
                                    cb.checked = false;
                                    const paramsDiv = cb.closest('.test-item')?.querySelector('.test-parameters');
                                    if (paramsDiv) paramsDiv.style.display = 'none';
                                    // Show all test items again
                                    document.querySelectorAll('.test-item').forEach(item => {
                                        item.style.display = 'block';
                                    });
                                }
                            }
                        }
                    });
                });
            }
        })
        .catch(err => {
            console.error('Error loading submitted tests:', err);
        });
}

// Update individual roll dropdown based on selected test method
// Bundle detection functions disabled - no longer needed
function updateIndividualRollDropdown() {
    // Function disabled - bundle detection removed
        return;
    }
    
function populateIndividualRolls(baseRef, rollCount, testedRolls) {
    // Function disabled - bundle detection removed
    return;
}

// Filter references by Line (L1 or L2)
let currentLineFilter = 'all';
function filterByLine(line) {
    currentLineFilter = line;
    const productRefSelect = document.getElementById('product_reference');
    const bulkRefSelection = document.getElementById('bulk_reference_selection');
    if (!productRefSelect) return;
    
    // Update button styles
    document.querySelectorAll('.line-btn').forEach(btn => {
        btn.style.background = '#e3f2fd';
        btn.style.color = '#1565C0';
        btn.style.borderColor = '#3498db';
    });
    
    if (line === 'L1') {
        document.getElementById('line1_btn').style.background = '#2196F3';
        document.getElementById('line1_btn').style.color = '#ffffff';
        document.getElementById('line1_btn').style.borderColor = '#1976D2';
    } else if (line === 'L2') {
        document.getElementById('line2_btn').style.background = '#2196F3';
        document.getElementById('line2_btn').style.color = '#ffffff';
        document.getElementById('line2_btn').style.borderColor = '#1976D2';
    } else {
        document.getElementById('line_all_btn').style.background = '#2196F3';
        document.getElementById('line_all_btn').style.color = '#ffffff';
        document.getElementById('line_all_btn').style.borderColor = '#1976D2';
    }
    
    // If a specific line is selected, show From/To reference dropdowns instead of single dropdown
    if (line === 'L1' || line === 'L2') {
        // Hide single reference dropdown
        productRefSelect.style.display = 'none';
        productRefSelect.value = '';
        
        // Show From/To reference selection
        if (bulkRefSelection) {
            bulkRefSelection.style.display = 'block';
            populateLineReferences(line);
        }
    } else {
        // Show single reference dropdown for "All Lines"
        productRefSelect.style.display = 'block';
        productRefSelect.setAttribute('required', 'required');
        
        // Hide From/To reference selection
        if (bulkRefSelection) {
            bulkRefSelection.style.display = 'none';
            clearBulkReferenceSelection();
        }
        
        // Clear hidden input
        const lineBasedProductRef = document.getElementById('line_based_product_reference');
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
                handleReferenceSelection('');
            }
        }
    }
}

// Populate From/To reference dropdowns with references for selected line
function populateLineReferences(line) {
    const productRefSelect = document.getElementById('product_reference');
    const fromRefSelect = document.getElementById('from_reference');
    const toRefSelect = document.getElementById('to_reference');
    
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
    // Example: "3.2L126JAN13-R03-GT0.9.H0.1" -> date is "JAN13" (Jan 13)
    function extractDateFromReference(ref) {
        // Match pattern: month abbreviation (JAN, FEB, etc.) followed by 2 digits
        // Look for pattern after line number (L followed by digits, then month)
        const monthAbbr = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
        for (let i = 0; i < monthAbbr.length; i++) {
            const month = monthAbbr[i];
            const pattern = new RegExp(month + '(\\d{2})');
            const match = ref.match(pattern);
            if (match) {
                const day = parseInt(match[1]);
                // Return a sortable number: month*100 + day
                // This ensures chronological order (JAN06 = 106, JAN13 = 113, FEB01 = 201)
                return ((i + 1) * 100) + day;
            }
        }
        // If date can't be extracted, use a very large number so it sorts last
        return 9999;
    }
    
    // Sort by date first, then alphabetically for same date
    lineReferences.sort((a, b) => {
        const dateA = extractDateFromReference(a.value);
        const dateB = extractDateFromReference(b.value);
        if (dateA !== dateB) {
            return dateA - dateB; // Sort by date (chronological)
        }
        return a.value.localeCompare(b.value); // Same date, sort alphabetically
    });
    
    // Populate both dropdowns
    lineReferences.forEach(ref => {
        const fromOption = document.createElement('option');
        fromOption.value = ref.value;
        fromOption.textContent = ref.text;
        fromOption.setAttribute('data-is-bundle', ref.isBundle);
        fromOption.setAttribute('data-base-ref', ref.baseRef);
        fromOption.setAttribute('data-roll-count', ref.rollCount);
        fromRefSelect.appendChild(fromOption);
        
        const toOption = document.createElement('option');
        toOption.value = ref.value;
        toOption.textContent = ref.text;
        toOption.setAttribute('data-is-bundle', ref.isBundle);
        toOption.setAttribute('data-base-ref', ref.baseRef);
        toOption.setAttribute('data-roll-count', ref.rollCount);
        toRefSelect.appendChild(toOption);
    });
}

// Update To Reference dropdown based on From Reference selection
// autoSelect: true when called from From Reference change, false when called from To Reference change
function updateReferenceRange(autoSelect = true) {
    const fromRefSelect = document.getElementById('from_reference');
    const toRefSelect = document.getElementById('to_reference');
    
    if (!fromRefSelect || !toRefSelect) return;
    
    const fromValue = fromRefSelect.value;
    if (!fromValue) {
        // If From is cleared, reset To dropdown to show all options
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
        // Extract base reference by removing the roll number suffix (e.g., "4.0L226JAN05-R01-H0.1-1" -> "4.0L226JAN05-R01-H0.1")
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
        // Find the last roll of the bundle (e.g., if bundle is "4.0L226JAN05-R01-H0.1-3", last roll is "4.0L226JAN05-R01-H0.1-3")
        const lastRollRef = baseRef + '-' + rollCount;
        
        // Find this last roll reference in the To dropdown
        let foundLastRoll = false;
        Array.from(toRefSelect.options).forEach((option, index) => {
            if (option.value === lastRollRef) {
                targetFromIndex = index;
                foundLastRoll = true;
            }
        });
        
        // If we couldn't find the last roll, use the next reference after the bundle
        if (!foundLastRoll) {
            // Look for the next reference that comes after this bundle
            Array.from(toRefSelect.options).forEach((option, index) => {
                if (index > fromIndex && option.value) {
                    const optionBaseRef = option.getAttribute('data-base-ref') || option.value.replace(/-\d+$/, '');
                    // If it's not from the same bundle, use this as the starting point
                    if (optionBaseRef !== baseRef) {
                        if (targetFromIndex === fromIndex) {
                            targetFromIndex = index;
                        }
                    }
                }
            });
            
            // If still not found, use the index right after the bundle
            if (targetFromIndex === fromIndex) {
                targetFromIndex = fromIndex + 1;
            }
        }
    }
    
    // Show only references from the target From reference onwards
    Array.from(toRefSelect.options).forEach((option, index) => {
        if (index === 0) {
            // Keep the placeholder
            option.style.display = '';
        } else if (index >= targetFromIndex) {
            // Show this option and onwards
            option.style.display = '';
        } else {
            // Hide options before the target From reference
            option.style.display = 'none';
        }
    });
    
    // Auto-select the last roll of the bundle in To dropdown
    // First, determine the base reference and roll count from the selected value
    let actualBaseRef = baseRef;
    let actualRollCount = rollCount;
    
    if (!actualBaseRef) {
        // Extract base reference from the selected value (e.g., "4.0L226JAN05-R01-H0.1-1" -> "4.0L226JAN05-R01-H0.1")
        const rollMatch = fromValue.match(/^(.+)-(\d+)$/);
        if (rollMatch) {
            actualBaseRef = rollMatch[1];
        } else {
            actualBaseRef = fromValue;
        }
    }
    
    // Find the bundle reference to get the actual roll count
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
    
    // Auto-select the last roll of the bundle (only when From Reference changes)
    if (autoSelect) {
    if (actualBaseRef && actualRollCount > 1) {
        const lastRollRef = actualBaseRef + '-' + actualRollCount;
        // Find and select the last roll in To dropdown (only if it's visible)
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
            let maxRoll = 0;
            let maxRollRef = '';
            Array.from(toRefSelect.options).forEach(option => {
                if (option.value && option.style.display !== 'none') {
                    const optionBaseRef = option.getAttribute('data-base-ref') || option.value.replace(/-\d+$/, '');
                    if (optionBaseRef === actualBaseRef) {
                        const rollMatch = option.value.match(/-(\d+)$/);
                        if (rollMatch) {
                            const rollNum = parseInt(rollMatch[1]);
                            if (rollNum > maxRoll) {
                                maxRoll = rollNum;
                                maxRollRef = option.value;
                            }
                        }
                    }
                }
            });
            if (maxRollRef) {
                toRefSelect.value = maxRollRef;
            }
        }
    } else if (fromValue) {
        // Single roll, auto-select the same reference
        toRefSelect.value = fromValue;
        }
    }
}

// Handle From/To reference selection change - check submitted tests
function handleFromToReferenceChange() {
            // Check reference-level status for any selected tests
            const selectedTests = document.querySelectorAll('.test-checkbox:checked');
            selectedTests.forEach(checkbox => {
                if (!checkbox.disabled && !checkbox.hasAttribute('data-already-submitted')) {
                    checkReferenceLevelTestStatus(checkbox);
                }
            });
            
            // If no tests selected, hide status display
            if (selectedTests.length === 0) {
                hideReferenceTestStatus();
            }
    const fromRefSelect = document.getElementById('from_reference');
    const toRefSelect = document.getElementById('to_reference');
    const notificationDiv = document.getElementById('submitted_tests_notification');
    
    if (!fromRefSelect || !toRefSelect) return;
    
    const fromValue = fromRefSelect.value;
    const toValue = toRefSelect.value;
    
    // If both From and To are selected, check submitted tests for the entire reference range
    if (fromValue && fromValue.trim() !== '' && toValue && toValue.trim() !== '') {
        // Check submitted tests for the reference range
        checkAndDisableSubmittedTestsForRange(fromValue, toValue);
    } else if (fromValue && fromValue.trim() !== '') {
        // If only From is selected, check submitted tests for the From reference
        // In edit mode, exclude the current report ID
        const excludeReportId = <?php echo ($edit_mode && isset($edit_id)) ? $edit_id : 'null'; ?>;
        checkAndDisableSubmittedTests(fromValue, excludeReportId);
        // Hide notification when only one reference is selected
        if (notificationDiv) {
            notificationDiv.style.display = 'none';
        }
    } else if (!fromValue || fromValue.trim() === '') {
        // If From reference is cleared, enable all tests
        checkAndDisableSubmittedTests('');
        // Hide notification when references are cleared
        if (notificationDiv) {
            notificationDiv.style.display = 'none';
        }
    }
}

// Check for submitted tests in a reference range
function checkAndDisableSubmittedTestsForRange(fromRef, toRef) {
    if (!fromRef || !toRef) {
        checkAndDisableSubmittedTests('');
        return;
    }
    
    const excludeReportId = <?php echo ($edit_mode && isset($edit_id)) ? $edit_id : 'null'; ?>;
    const apiPath = window.location.pathname.includes('/forms/') ? 'api/check_submitted_tests_range.php' : 'forms/api/check_submitted_tests_range.php';
    let apiUrl = apiPath + '?from_reference=' + encodeURIComponent(fromRef) + '&to_reference=' + encodeURIComponent(toRef);
    if (excludeReportId) {
        apiUrl += '&exclude_report_id=' + encodeURIComponent(excludeReportId);
    }
    
    fetch(apiUrl)
        .then(response => {
            if (!response.ok) {
                throw new Error('API response not OK: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('API Response:', data);
            const notificationDiv = document.getElementById('submitted_tests_notification');
            const submittedTestsList = document.getElementById('submitted_tests_list');
            
            // Ensure parent container is visible
            const bulkRefSelection = document.getElementById('bulk_reference_selection');
            if (bulkRefSelection && notificationDiv) {
                // Make sure parent is visible
                if (bulkRefSelection.style.display === 'none') {
                    bulkRefSelection.style.display = 'block';
                }
            }
            
            if (data.success && data.submitted_tests) {
                // Collect submitted test names for display
                let submittedTestNames = [];
                const submittedTestsObj = data.submitted_tests || {};
                
                console.log('Submitted tests from API:', submittedTestsObj);
                console.log('Submitted tests list from API:', data.submitted_tests_list);
                
                // First, try to use the list from API if available (more reliable)
                if (data.submitted_tests_list && Array.isArray(data.submitted_tests_list) && data.submitted_tests_list.length > 0) {
                    submittedTestNames = data.submitted_tests_list.map(test => {
                        const testName = test.test_name || 'Unknown Test';
                        const method = test.method || test.standard_code || 'N/A';
                        return testName + ' (' + method + ')';
                    });
                    console.log('Using test list from API:', submittedTestNames);
                } else {
                    // Fallback: collect from test keys
                    Object.keys(submittedTestsObj).forEach(testKey => {
                        if (submittedTestsObj[testKey]) {
                            // Split the key to get test name and method
                            const parts = testKey.split('_');
                            if (parts.length >= 2) {
                                const method = parts.pop(); // Last part is method
                                const testName = parts.join('_'); // Rest is test name
                                submittedTestNames.push(testName + ' (' + method + ')');
                            } else {
                                // Fallback if format is different
                                submittedTestNames.push(testKey);
                            }
                        }
                    });
                    console.log('Collected submitted test names from keys:', submittedTestNames);
                }
                
                // Disable checkboxes for submitted tests
                document.querySelectorAll('.test-checkbox').forEach(checkbox => {
                    if (checkbox.hasAttribute('data-locked-test')) {
                        return; // Skip locked tests
                    }
                    
                    const testName = checkbox.getAttribute('data-test-name');
                    const method = checkbox.getAttribute('data-method');
                    const testKey = testName + '_' + method;
                    
                    if (submittedTestsObj[testKey]) {
                        // Force disable and uncheck
                        checkbox.disabled = true;
                        checkbox.readOnly = true;
                        checkbox.checked = false;
                        checkbox.setAttribute('data-already-submitted', 'true');
                        checkbox.title = 'This test has already been submitted for the selected reference range. You can only submit different test methods.';
                        
                        // Hide test parameters and disable inputs
                        const testItem = checkbox.closest('.test-item');
                        if (testItem) {
                            const params = testItem.querySelector('.test-parameters');
                            if (params) {
                                params.style.display = 'none';
                                params.querySelectorAll('input, textarea, select').forEach(input => {
                                    input.disabled = true;
                                    input.readOnly = true;
                                });
                            }
                        }
                        
                        // Force disable the checkbox
                        checkbox.setAttribute('disabled', 'disabled');
                        checkbox.setAttribute('readonly', 'readonly');
                        checkbox.removeAttribute('onclick');
                        checkbox.removeAttribute('onchange');
                        
                        // Add multiple layers of event prevention
                        // Use a flag to prevent multiple toasts from showing
                        // Store flag on the checkbox element itself to share between handlers
                        let toastShown = false;
                        let toastTimeout = null;
                        
                        const preventInteraction = function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            this.checked = false;
                            
                            // Only show toast on click event, and only if not already shown
                            // Skip if this was triggered by a label click (label will show its own toast)
                            if (e.type === 'click' && !toastShown && !e._fromLabel) {
                                toastShown = true;
                                const testName = this.getAttribute('data-test-name') || 'Unknown';
                                const method = this.getAttribute('data-method') || 'Unknown';
                                showToast(
                                    `⚠️ "${testName} (${method})" has already been submitted for this reference range and cannot be selected again. Please select a different test method.`,
                                    'warning',
                                    5000
                                );
                                
                                // Reset flag after 1 second to allow showing again if user clicks again later
                                if (toastTimeout) clearTimeout(toastTimeout);
                                toastTimeout = setTimeout(() => {
                                    toastShown = false;
                                }, 1000);
                            }
                            
                            return false;
                        };
                        
                        // Remove all existing listeners by cloning (but keep attributes)
                        const oldCheckbox = checkbox;
                        const newCheckbox = oldCheckbox.cloneNode(false);
                        // Copy all attributes
                        Array.from(oldCheckbox.attributes).forEach(attr => {
                            newCheckbox.setAttribute(attr.name, attr.value);
                        });
                        // Ensure it's disabled
                        newCheckbox.disabled = true;
                        newCheckbox.readOnly = true;
                        newCheckbox.checked = false;
                        newCheckbox.setAttribute('data-already-submitted', 'true');
                        
                        // Replace the old checkbox
                        oldCheckbox.parentNode.replaceChild(newCheckbox, oldCheckbox);
                        checkbox = newCheckbox;
                        
                        // Add event listeners with capture phase - only show toast on click
                        ['click', 'change', 'mousedown', 'mouseup', 'keydown', 'keyup'].forEach(eventType => {
                            checkbox.addEventListener(eventType, preventInteraction, true);
                        });
                        
                        checkbox.style.pointerEvents = 'none';
                        checkbox.style.cursor = 'not-allowed';
                        checkbox.style.opacity = '0.6';
                        
                        // Update label styling and prevent all interactions
                        const label = checkbox.closest('label');
                        if (label) {
                            label.classList.add('disabled-test-label');
                            label.style.cursor = 'not-allowed';
                            label.style.display = 'flex';
                            label.style.width = '100%';
                            label.style.marginBottom = '5px';
                            label.style.background = 'linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%)';
                            label.style.border = '2px solid #e5e7eb';
                            label.style.borderLeft = '4px solid #dc3545';
                            label.style.padding = '8px 12px';
                            label.style.borderRadius = '6px';
                            label.style.opacity = '0.85';
                            
                            // Remove existing onclick and 'for' attribute to prevent label from activating checkbox
                            label.removeAttribute('onclick');
                            label.removeAttribute('for');
                            
                            // Prevent label from activating checkbox - use capture phase
                            // Use a flag to prevent multiple toasts from showing
                            // Share the same flag with checkbox to prevent duplicate toasts
                            const preventLabelClick = function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                e.stopImmediatePropagation();
                                
                                // Mark this event as coming from label to prevent checkbox handler from also showing toast
                                if (e.type === 'click') {
                                    e._fromLabel = true;
                                }
                                
                                // Find the checkbox (it might have been replaced)
                                const currentCheckbox = this.querySelector('.test-checkbox') || document.getElementById(checkbox.id);
                                if (currentCheckbox) {
                                    currentCheckbox.checked = false;
                                    
                                    const testItem = currentCheckbox.closest('.test-item');
                                    if (testItem) {
                                        const params = testItem.querySelector('.test-parameters');
                                        if (params) {
                                            params.style.display = 'none';
                                            params.querySelectorAll('input, textarea, select').forEach(input => {
                                                input.disabled = true;
                                                input.readOnly = true;
                                            });
                                        }
                                    }
                                    
                                    // Only show toast once per interaction (on click event) and only if not already shown
                                    if (e.type === 'click' && !toastShown) {
                                        toastShown = true;
                                        const testName = currentCheckbox.getAttribute('data-test-name') || 'Unknown';
                                        const method = currentCheckbox.getAttribute('data-method') || 'Unknown';
                                        showToast(
                                            `⚠️ "${testName} (${method})" has already been submitted for this reference range and cannot be selected again. Please select a different test method.`,
                                            'warning',
                                            5000
                                        );
                                        
                                        // Reset flag after 1 second to allow showing again if user clicks again later
                                        if (toastTimeout) clearTimeout(toastTimeout);
                                        toastTimeout = setTimeout(() => {
                                            toastShown = false;
                                        }, 1000);
                                    }
                                }
                                
                                return false;
                            };
                            
                            // Add multiple event listeners to label with capture phase
                            ['click', 'mousedown', 'mouseup', 'touchstart'].forEach(eventType => {
                                label.addEventListener(eventType, preventLabelClick, true);
                            });
                            
                            // Use CSS to prevent pointer events on the label when checkbox is disabled
                            label.style.userSelect = 'none';
                            label.style.webkitUserSelect = 'none';
                        }
                        
                        // Update span styling
                        const span = checkbox.nextElementSibling;
                        if (span) {
                            span.style.color = '#6b7280';
                            span.style.textDecoration = 'line-through';
                            span.style.textDecorationColor = '#dc3545';
                            span.style.textDecorationThickness = '2px';
                            span.style.fontWeight = '500';
                        }
                    } else {
                        // Enable checkbox if not submitted
                        if (!checkbox.hasAttribute('data-locked-test')) {
                            checkbox.disabled = false;
                            checkbox.readOnly = false;
                            checkbox.removeAttribute('data-already-submitted');
                            checkbox.title = '';
                            checkbox.style.pointerEvents = 'auto';
                            checkbox.style.cursor = 'pointer';
                            
                            const label = checkbox.closest('label');
                            if (label) {
                                label.classList.remove('disabled-test-label');
                                label.style.cursor = 'pointer';
                                label.style.display = 'inline-flex';
                                label.style.width = 'auto';
                                label.style.marginBottom = '0';
                                label.style.background = '#f8f9fa';
                                label.style.border = '1px solid #dee2e6';
                                label.style.borderLeft = '1px solid #dee2e6';
                                label.style.opacity = '1';
                                label.onclick = null;
                                
                                // Remove event listeners
                                const newLabel = label.cloneNode(true);
                                label.parentNode.replaceChild(newLabel, label);
                            }
                            
                            const span = checkbox.nextElementSibling;
                            if (span) {
                                span.style.color = '#495057';
                                span.style.textDecoration = 'none';
                            }
                        }
                    }
                });
                
                // Show notification with list of submitted tests
                console.log('Final submitted test names count:', submittedTestNames.length);
                console.log('Notification div exists:', !!notificationDiv);
                console.log('Submitted tests list div exists:', !!submittedTestsList);
                
                if (submittedTestNames.length > 0) {
                    if (notificationDiv && submittedTestsList) {
                        // Remove duplicates
                        const uniqueTests = [...new Set(submittedTestNames)];
                        submittedTestsList.innerHTML = uniqueTests.map(test => 
                            '<div style="padding:4px 0; border-bottom:1px solid #fecaca;"><i class="fas fa-check-circle" style="color:#dc2626; margin-right:6px;"></i>' + 
                            htmlspecialchars(test) + '</div>'
                        ).join('');
                        notificationDiv.style.display = 'block';
                        console.log('Notification displayed with', uniqueTests.length, 'tests');
                    } else {
                        console.error('Notification elements not found!', {
                            notificationDiv: !!notificationDiv,
                            submittedTestsList: !!submittedTestsList
                        });
                    }
                } else {
                    if (notificationDiv) {
                        notificationDiv.style.display = 'none';
                    }
                    console.log('No submitted tests found, hiding notification');
                }
            } else {
                // If API call failed or no submitted tests, re-enable all checkboxes
                document.querySelectorAll('.test-checkbox').forEach(cb => {
                    if (!cb.hasAttribute('data-locked-test') && !cb.hasAttribute('data-already-submitted')) {
                        cb.style.pointerEvents = 'auto';
                    }
                });
                
                // Hide notification if no submitted tests
                if (notificationDiv) {
                    notificationDiv.style.display = 'none';
                }
            }
        })
        .catch(err => {
            console.error('Error checking submitted tests for range:', err);
            // Re-enable checkboxes on error
            document.querySelectorAll('.test-checkbox').forEach(cb => {
                if (!cb.hasAttribute('data-locked-test') && !cb.hasAttribute('data-already-submitted')) {
                    cb.style.pointerEvents = 'auto';
                }
            });
            
            // Hide notification on error
            const notificationDiv = document.getElementById('submitted_tests_notification');
            if (notificationDiv) {
                notificationDiv.style.display = 'none';
            }
            
            // Fallback to checking just the from reference
            checkAndDisableSubmittedTests(fromRef, excludeReportId);
        });
}

// Helper function to escape HTML (simple version)
function htmlspecialchars(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Apply bulk reference selection - create test orders for range of references
function applyBulkReferenceSelection() {
    const fromRefSelect = document.getElementById('from_reference');
    const toRefSelect = document.getElementById('to_reference');
    
    if (!fromRefSelect || !toRefSelect) {
        alert('Please select both From and To references');
        return;
    }
    
    const fromValue = fromRefSelect.value;
    const toValue = toRefSelect.value;
    
    if (!fromValue || !toValue) {
        alert('Please select both From and To references');
        return;
    }
    
    // Immediately disable all checkboxes to prevent clicking while checking
    document.querySelectorAll('.test-checkbox').forEach(cb => {
        if (!cb.hasAttribute('data-locked-test')) {
            cb.style.pointerEvents = 'none';
        }
    });
    
    // Check for submitted tests in the reference range
    checkAndDisableSubmittedTestsForRange(fromValue, toValue);
    
    // Show reference-level status for all tests
    showAllTestsReferenceStatus(fromValue, toValue);
    
    // Get all references between From and To
    const allRefs = Array.from(fromRefSelect.options).map(opt => opt.value).filter(v => v);
    const fromIndex = allRefs.indexOf(fromValue);
    const toIndex = allRefs.indexOf(toValue);
    
    if (fromIndex === -1 || toIndex === -1) {
        alert('Invalid reference selection');
        return;
    }
    
    if (fromIndex > toIndex) {
        alert('From reference must come before To reference');
        return;
    }
    
    // Extract base references and roll numbers from From and To
    const fromOption = fromRefSelect.querySelector(`option[value="${fromValue}"]`);
    const toOption = toRefSelect.querySelector(`option[value="${toValue}"]`);
    
    let fromBaseRef = '';
    let fromRollNum = 0;
    let toBaseRef = '';
    let toRollNum = 0;
    
    // Extract base reference and roll number from From value
    const fromMatch = fromValue.match(/^(.+)-(\d+)$/);
    if (fromMatch) {
        fromBaseRef = fromMatch[1];
        fromRollNum = parseInt(fromMatch[2]);
    } else {
        fromBaseRef = fromValue;
        fromRollNum = 1;
    }
    
    // Extract base reference and roll number from To value
    const toMatch = toValue.match(/^(.+)-(\d+)$/);
    if (toMatch) {
        toBaseRef = toMatch[1];
        toRollNum = parseInt(toMatch[2]);
    } else {
        toBaseRef = toValue;
        toRollNum = 1;
    }
    
    // Collect all references in the range
    const selectedRefs = [];
    
    // Check if From and To are from the same bundle
    if (fromBaseRef === toBaseRef && fromRollNum > 0 && toRollNum > 0) {
        // Same bundle - include ALL rolls from the bundle (from roll 1 to the last roll)
        // Find the bundle to get the total roll count
        let bundleRollCount = Math.max(fromRollNum, toRollNum);
        
        // Check all options to find the bundle reference for this base
        Array.from(fromRefSelect.options).forEach(option => {
            if (option.value && option.value !== '') {
                const optionBaseRef = option.getAttribute('data-base-ref') || option.value.replace(/-\d+$/, '');
                const optionIsBundle = option.getAttribute('data-is-bundle') === 'true';
                if (optionIsBundle && optionBaseRef === fromBaseRef) {
                    const optionRollCount = parseInt(option.getAttribute('data-roll-count')) || 1;
                    if (optionRollCount > bundleRollCount) {
                        bundleRollCount = optionRollCount;
                    }
                }
            }
        });
        
        // Include all rolls from 1 to bundleRollCount
        for (let roll = 1; roll <= bundleRollCount; roll++) {
            selectedRefs.push({
                reference: fromBaseRef + '-' + roll,
                rollNumber: roll,
                bundleRef: ''
            });
        }
    } else {
        // Different bundles or references - process serially
        for (let i = fromIndex; i <= toIndex; i++) {
            const refValue = allRefs[i];
            const option = fromRefSelect.querySelector(`option[value="${refValue}"]`);
            if (option) {
                const isBundle = option.getAttribute('data-is-bundle') === 'true';
                const baseRef = option.getAttribute('data-base-ref') || '';
                let rollCount = parseInt(option.getAttribute('data-roll-count')) || 1;
                
                // Extract base reference if not provided
                let actualBaseRef = baseRef;
                let refRollNum = 0;
                if (!actualBaseRef) {
                    const refMatch = refValue.match(/^(.+)-(\d+)$/);
                    if (refMatch) {
                        actualBaseRef = refMatch[1];
                        refRollNum = parseInt(refMatch[2]);
                    } else {
                        actualBaseRef = refValue;
                        refRollNum = 1;
                    }
                } else {
                    const refMatch = refValue.match(/-(\d+)$/);
                    if (refMatch) {
                        refRollNum = parseInt(refMatch[1]);
                    }
                }
                
                // Find bundle roll count if this is an individual roll
                if (!isBundle && actualBaseRef) {
                    Array.from(fromRefSelect.options).forEach(opt => {
                        if (opt.value && opt.value !== '') {
                            const optBaseRef = opt.getAttribute('data-base-ref') || opt.value.replace(/-\d+$/, '');
                            const optIsBundle = opt.getAttribute('data-is-bundle') === 'true';
                            if (optIsBundle && optBaseRef === actualBaseRef) {
                                rollCount = parseInt(opt.getAttribute('data-roll-count')) || rollCount;
                            }
                        }
                    });
                }
                
                // Check if we need to generate serial references
                // If this is the first reference and To has the same base, generate all serials
                let needsSerial = false;
                let endRollNum = refRollNum;
                
                if (i === fromIndex && actualBaseRef === toBaseRef) {
                    needsSerial = true;
                    endRollNum = toRollNum;
                } else if (i === fromIndex) {
                    // Check if any reference in the range has the same base
                    for (let j = i + 1; j <= toIndex; j++) {
                        const checkRef = allRefs[j];
                        const checkOption = fromRefSelect.querySelector(`option[value="${checkRef}"]`);
                        if (checkOption) {
                            let checkBaseRef = checkOption.getAttribute('data-base-ref') || '';
                            if (!checkBaseRef) {
                                const checkMatch = checkRef.match(/^(.+)-(\d+)$/);
                                if (checkMatch) {
                                    checkBaseRef = checkMatch[1];
                                } else {
                                    checkBaseRef = checkRef;
                                }
                            }
                            
                            if (checkBaseRef === actualBaseRef) {
                                needsSerial = true;
                                const checkMatch = checkRef.match(/-(\d+)$/);
                                if (checkMatch) {
                                    const checkRollNum = parseInt(checkMatch[1]);
                                    if (checkRollNum > endRollNum) {
                                        endRollNum = checkRollNum;
                                    }
                                }
                            } else if (needsSerial) {
                                // Found a different base, stop
                                break;
                            }
                        }
                    }
                }
                
                if (needsSerial && endRollNum > refRollNum) {
                    // Generate all serial references from refRollNum to endRollNum
                    for (let roll = refRollNum; roll <= endRollNum; roll++) {
                        selectedRefs.push({
                            reference: actualBaseRef + '-' + roll,
                            rollNumber: roll,
                            bundleRef: ''
                        });
                    }
                } else if (isBundle || rollCount > 1) {
                    // For bundles or when we have a roll count, add all individual rolls
                    for (let roll = 1; roll <= rollCount; roll++) {
                        selectedRefs.push({
                            reference: actualBaseRef + '-' + roll,
                            rollNumber: roll,
                            bundleRef: isBundle ? refValue : ''
                        });
                    }
                } else {
                    // For individual references, add as is
                    selectedRefs.push({
                        reference: refValue,
                        rollNumber: 1,
                        bundleRef: ''
                    });
                }
            }
        }
    }
    
    // Store in sessionStorage for form submission
    sessionStorage.setItem('bulk_reference_selection', JSON.stringify(selectedRefs));
    sessionStorage.setItem('bulk_from_ref', fromValue);
    sessionStorage.setItem('bulk_to_ref', toValue);
    
    // Check submitted tests for the From reference to enable/disable appropriate test checkboxes
    if (fromValue && fromValue.trim() !== '') {
        checkAndDisableSubmittedTests(fromValue);
    }
    
    showToast(`Bulk selection applied: ${selectedRefs.length} reference(s) will be tested. Submit the form to create test orders for all selected references.`, 'success', 5000);
}

// Clear bulk reference selection
function clearBulkReferenceSelection() {
    const fromRefSelect = document.getElementById('from_reference');
    const toRefSelect = document.getElementById('to_reference');
    const notificationDiv = document.getElementById('submitted_tests_notification');
    
    if (fromRefSelect) fromRefSelect.value = '';
    if (toRefSelect) {
        toRefSelect.value = '';
        // Reset To dropdown to show all options
        Array.from(toRefSelect.options).forEach(option => {
            option.style.display = '';
        });
    }
    
    // Hide notification when references are cleared
    if (notificationDiv) {
        notificationDiv.style.display = 'none';
    }
    
    // Hide reference status display
    hideReferenceTestStatus();
    
    // Re-enable all tests
    checkAndDisableSubmittedTests('');
    
    sessionStorage.removeItem('bulk_reference_selection');
    sessionStorage.removeItem('bulk_from_ref');
    sessionStorage.removeItem('bulk_to_ref');
}

// Handle reference selection - check if bundle and show individual roll selector
// Function to check and disable already submitted tests for a reference
let isCheckingTests = false; // Prevent multiple simultaneous calls
function checkAndDisableSubmittedTests(reference, excludeReportId = null) {
    // Prevent multiple simultaneous calls
    if (isCheckingTests) {
        console.log('⏳ Already checking submitted tests, skipping duplicate call');
        return;
    }
    
    isCheckingTests = true;
    
    // Clean up function to reset the flag
    const resetFlag = () => {
        setTimeout(() => {
            isCheckingTests = false;
        }, 500);
    };
    
    if (!reference || reference.trim() === '') {
        // Enable all checkboxes if no reference selected
        document.querySelectorAll('.test-checkbox').forEach(checkbox => {
            if (!checkbox.hasAttribute('data-locked-test')) {
                checkbox.disabled = false;
                checkbox.checked = false; // Uncheck if no reference
                checkbox.removeAttribute('data-already-submitted');
                checkbox.title = '';
                checkbox.style.pointerEvents = 'auto';
                
                const label = checkbox.closest('label');
                if (label) {
                    label.classList.remove('disabled-test-label');
                    label.style.cursor = 'pointer';
                    label.style.opacity = '1';
                    label.style.background = '#f8f9fa';
                    label.style.border = '1px solid #dee2e6';
                    label.style.borderLeft = '1px solid #dee2e6';
                    label.style.padding = '8px 12px';
                    label.style.borderRadius = '4px';
                    label.style.pointerEvents = 'auto';
                    label.onclick = null;
                    
                    // Remove ALL badges and tooltips
                    const allBadges = label.querySelectorAll('.already-submitted-badge');
                    allBadges.forEach(b => b.remove());
                    const allTooltips = label.querySelectorAll('.test-info-tooltip');
                    allTooltips.forEach(t => t.remove());
                }
                
                const span = checkbox.nextElementSibling;
                if (span) {
                    span.style.color = '#495057';
                    span.style.textDecoration = 'none';
                    span.style.fontWeight = 'normal';
                    // Remove any old "Already Submitted" text if present
                    const alreadySubmitted = span.querySelector('span[style*="color: #dc3545"]');
                    if (alreadySubmitted) {
                        alreadySubmitted.remove();
                    }
                }
            }
        });
        resetFlag();
        return;
    }
    
    // Fetch submitted tests for this reference via AJAX
    console.log('🔍 Checking submitted tests for reference:', reference);
    // Use absolute path from forms directory
    const apiPath = window.location.pathname.includes('/forms/') ? 'api/check_submitted_tests.php' : 'forms/api/check_submitted_tests.php';
    let apiUrl = apiPath + '?reference=' + encodeURIComponent(reference);
    if (excludeReportId) {
        apiUrl += '&exclude_report_id=' + encodeURIComponent(excludeReportId);
    }
    fetch(apiUrl)
        .then(response => {
            console.log('📡 API Response status:', response.status, response.statusText);
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            console.log('✅ API Response data:', data);
            if (data.success && data.submitted_tests) {
                console.log('📋 Submitted tests found:', Object.keys(data.submitted_tests));
                // Disable checkboxes for submitted tests
                document.querySelectorAll('.test-checkbox').forEach(checkbox => {
                    if (checkbox.hasAttribute('data-locked-test')) {
                        return; // Skip locked tests
                    }
                    
                    const testName = checkbox.getAttribute('data-test-name');
                    const method = checkbox.getAttribute('data-method');
                    const testKey = testName + '_' + method;
                    
                    if (data.submitted_tests[testKey]) {
                        // Force disable and uncheck
                        checkbox.disabled = true;
                        checkbox.readOnly = true;
                        checkbox.checked = false; // Force uncheck
                        checkbox.setAttribute('data-already-submitted', 'true');
                        checkbox.title = 'This test has already been submitted for the selected reference. You can only submit different test methods.';
                        
                        // Hide test parameters and disable all inputs
                        const testItem = checkbox.closest('.test-item');
                        if (testItem) {
                            const params = testItem.querySelector('.test-parameters');
                            if (params) {
                                params.style.display = 'none';
                                // Disable all inputs within test parameters
                                params.querySelectorAll('input, textarea, select').forEach(input => {
                                    input.disabled = true;
                                    input.readOnly = true;
                                });
                            }
                        }
                        
                        // Add inline event handlers to prevent checking
                        checkbox.onclick = function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            this.checked = false;
                            
                            // Hide test parameters and disable inputs
                            const testItem = this.closest('.test-item');
                            if (testItem) {
                                const params = testItem.querySelector('.test-parameters');
                                if (params) {
                                    params.style.display = 'none';
                                    params.querySelectorAll('input, textarea, select').forEach(input => {
                                        input.disabled = true;
                                        input.readOnly = true;
                                    });
                                }
                            }
                            
                            // Modern toast notification
                            const testName = this.getAttribute('data-test-name') || 'Unknown';
                            const method = this.getAttribute('data-method') || 'Unknown';
                            showToast(
                                `⚠️ "${testName} (${method})" has already been submitted for this reference and cannot be selected again. Please select a different test method.`,
                                'warning',
                                5000
                            );
                            
                            return false;
                        };
                        checkbox.onchange = function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            e.stopImmediatePropagation();
                            this.checked = false;
                            
                            // Hide test parameters and disable inputs
                            const testItem = this.closest('.test-item');
                            if (testItem) {
                                const params = testItem.querySelector('.test-parameters');
                                if (params) {
                                    params.style.display = 'none';
                                    params.querySelectorAll('input, textarea, select').forEach(input => {
                                        input.disabled = true;
                                        input.readOnly = true;
                                    });
                                }
                            }
                            
                            return false;
                        };
                        checkbox.style.pointerEvents = 'none';
                        
                        const label = checkbox.closest('label');
                        if (label) {
                            // Modern styling for disabled label
                            label.classList.add('disabled-test-label');
                            label.style.cursor = 'not-allowed';
                            label.style.display = 'flex'; // Change to flex to take full width
                            label.style.width = '100%'; // Take full width to wrap to new line
                            label.style.marginBottom = '5px'; // Add spacing when wrapped
                            label.style.background = 'linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%)';
                            label.style.border = '2px solid #e5e7eb';
                            label.style.borderLeft = '4px solid #dc3545';
                            label.style.padding = '8px 12px';
                            label.style.borderRadius = '6px';
                            label.style.opacity = '0.85';
                            label.style.pointerEvents = 'auto'; // Allow hover effects
                            
                            label.onclick = function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                
                                // Ensure checkbox is unchecked
                                const checkbox = label.querySelector('.test-checkbox');
                                if (checkbox) {
                                    checkbox.checked = false;
                                    
                                    // Hide test parameters and disable inputs
                                    const testItem = checkbox.closest('.test-item');
                                    if (testItem) {
                                        const params = testItem.querySelector('.test-parameters');
                                        if (params) {
                                            params.style.display = 'none';
                                            params.querySelectorAll('input, textarea, select').forEach(input => {
                                                input.disabled = true;
                                                input.readOnly = true;
                                            });
                                        }
                                    }
                                    
                                    // Modern toast notification
                                    const testName = checkbox.getAttribute('data-test-name') || 'Unknown';
                                    const method = checkbox.getAttribute('data-method') || 'Unknown';
                                    showToast(
                                        `⚠️ "${testName} (${method})" has already been submitted for this reference and cannot be selected again. Please select a different test method.`,
                                        'warning',
                                        5000
                                    );
                                }
                                
                                return false;
                            };
                        }
                        
                        const span = checkbox.nextElementSibling;
                        if (span) {
                            // Update span styling
                            span.style.color = '#6b7280';
                            span.style.textDecoration = 'line-through';
                            span.style.textDecorationColor = '#dc3545';
                            span.style.textDecorationThickness = '2px';
                            span.style.fontWeight = '500';
                        }
                        
                        // CRITICAL: Always remove ALL existing badges and tooltips first to prevent duplicates
                        if (label) {
                            // Remove ALL badges (in case there are multiple)
                            const allBadges = label.querySelectorAll('.already-submitted-badge');
                            allBadges.forEach(b => b.remove());
                            
                            // Remove ALL tooltips (in case there are multiple)
                            const allTooltips = label.querySelectorAll('.test-info-tooltip');
                            allTooltips.forEach(t => t.remove());
                            
                            // Only add badge if it doesn't already exist
                            if (!label.querySelector('.already-submitted-badge')) {
                                const badge = document.createElement('span');
                                badge.className = 'already-submitted-badge';
                                badge.style.fontSize = '9px';
                                badge.style.padding = '3px 8px';
                                badge.innerHTML = '<i class="fas fa-lock" style="font-size: 9px;"></i><span>Already Submitted</span>';
                                label.appendChild(badge);
                            }
                            
                            // Only add tooltip if it doesn't already exist
                            if (!label.querySelector('.test-info-tooltip')) {
                                const tooltip = document.createElement('span');
                                tooltip.className = 'test-info-tooltip';
                                tooltip.style.flexShrink = '0';
                                tooltip.innerHTML = '<i class="fas fa-info-circle tooltip-icon"></i><span class="tooltip-content">This test method has already been submitted for the selected reference. Select a different test method to proceed.</span>';
                                label.appendChild(tooltip);
                            }
                        }
                    } else {
                        checkbox.disabled = false;
                        checkbox.removeAttribute('data-already-submitted');
                        checkbox.title = '';
                        checkbox.style.pointerEvents = 'auto';
                        
                        const label = checkbox.closest('label');
                        if (label) {
                            // Reset label styling
                            label.classList.remove('disabled-test-label');
                            label.style.cursor = 'pointer';
                            label.style.opacity = '1';
                            label.style.background = '#f8f9fa';
                            label.style.border = '1px solid #dee2e6';
                            label.style.borderLeft = '1px solid #dee2e6';
                            label.style.padding = '8px 12px';
                            label.style.borderRadius = '4px';
                            label.style.pointerEvents = 'auto';
                            label.onclick = null; // Remove click handler
                            
                            // Remove badge and tooltip
                            const badge = label.querySelector('.already-submitted-badge');
                            if (badge) badge.remove();
                            const tooltip = label.querySelector('.test-info-tooltip');
                            if (tooltip) tooltip.remove();
                        }
                        
                        const span = checkbox.nextElementSibling;
                        if (span) {
                            // Reset span styling
                            span.style.color = '#495057';
                            span.style.textDecoration = 'none';
                            span.style.fontWeight = 'normal';
                        }
                    }
                });
            }
        })
        .catch(error => {
            console.error('Error checking submitted tests:', error);
        })
        .finally(() => {
            resetFlag();
        });
}

// Prevent checking disabled checkboxes
document.addEventListener('DOMContentLoaded', function() {
    // GLOBAL INTERCEPTOR: Continuously monitor and prevent already-submitted checkboxes from being checked
    setInterval(function() {
        document.querySelectorAll('.test-checkbox[data-already-submitted="true"]').forEach(checkbox => {
            if (checkbox.checked) {
                checkbox.checked = false;
                checkbox.disabled = true;
                checkbox.readOnly = true;
                
                const testItem = checkbox.closest('.test-item');
                if (testItem) {
                    const params = testItem.querySelector('.test-parameters');
                    if (params) {
                        params.style.display = 'none';
                        params.querySelectorAll('input, textarea, select').forEach(input => {
                            input.disabled = true;
                            input.readOnly = true;
                        });
                    }
                }
            }
        });
    }, 100); // Check every 100ms
    
    // Add global event listener on document to catch ALL checkbox clicks (capture phase)
    document.addEventListener('click', function(e) {
        const checkbox = e.target.closest('.test-checkbox');
        if (checkbox && (checkbox.hasAttribute('data-already-submitted') || checkbox.disabled || checkbox.readOnly)) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            checkbox.checked = false;
            checkbox.disabled = true;
            checkbox.readOnly = true;
            
            const testName = checkbox.getAttribute('data-test-name') || 'Test';
            const method = checkbox.getAttribute('data-method') || 'Method';
            showToast('⚠️ This test method (' + testName + ' - ' + method + ') has already been submitted for the selected reference. Please select a different test method.', 'warning', 5000);
            
            const testItem = checkbox.closest('.test-item');
            if (testItem) {
                const params = testItem.querySelector('.test-parameters');
                if (params) {
                    params.style.display = 'none';
                    params.querySelectorAll('input, textarea, select').forEach(input => {
                        input.disabled = true;
                        input.readOnly = true;
                    });
                }
            }
            return false;
        }
    }, true); // Use capture phase to catch events early
    
    // Add global change listener (capture phase)
    document.addEventListener('change', function(e) {
        const checkbox = e.target;
        if (checkbox && checkbox.classList.contains('test-checkbox') && 
            (checkbox.hasAttribute('data-already-submitted') || checkbox.disabled || checkbox.readOnly)) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();
            checkbox.checked = false;
            checkbox.disabled = true;
            checkbox.readOnly = true;
            return false;
        }
    }, true); // Use capture phase
    
    // Add click prevention for disabled checkboxes
    document.querySelectorAll('.test-checkbox').forEach(checkbox => {
        checkbox.addEventListener('click', function(e) {
            if (this.disabled || this.hasAttribute('data-already-submitted') || this.readOnly) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                this.checked = false;
                this.disabled = true;
                this.readOnly = true;
                
                const testName = this.getAttribute('data-test-name') || 'Test';
                const method = this.getAttribute('data-method') || 'Method';
                showToast('⚠️ This test method (' + testName + ' - ' + method + ') has already been submitted for the selected reference. Please select a different test method.', 'warning', 5000);
                
                // Hide any test parameters that might have been shown
                const testItem = this.closest('.test-item');
                if (testItem) {
                    const params = testItem.querySelector('.test-parameters');
                    if (params) {
                        params.style.display = 'none';
                        params.querySelectorAll('input, textarea, select').forEach(input => {
                            input.disabled = true;
                            input.readOnly = true;
                        });
                    }
                }
                
                return false;
            }
        }, true); // Use capture phase
        
        checkbox.addEventListener('change', function(e) {
            if (this.disabled || this.hasAttribute('data-already-submitted') || this.readOnly) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                this.checked = false;
                this.disabled = true;
                this.readOnly = true;
                
                const testName = this.getAttribute('data-test-name') || 'Test';
                const method = this.getAttribute('data-method') || 'Method';
                showToast('⚠️ This test method (' + testName + ' - ' + method + ') has already been submitted for the selected reference. Please select a different test method.', 'warning', 5000);
                
                // Hide any test parameters that might have been shown
                const testItem = this.closest('.test-item');
                if (testItem) {
                    const params = testItem.querySelector('.test-parameters');
                    if (params) {
                        params.style.display = 'none';
                        // Disable all inputs within test parameters
                        params.querySelectorAll('input, textarea, select').forEach(input => {
                            input.disabled = true;
                            input.readOnly = true;
                        });
                    }
                }
                
                return false;
            }
        }, true); // Use capture phase
    });
    
    // Check submitted tests on page load if reference is already selected
    const productRef = document.getElementById('product_reference');
    const externalRef = document.getElementById('external_reference');
    const individualRollRef = document.getElementById('individual_roll_reference');
    const fromRef = document.getElementById('from_reference');
    
    let currentRef = '';
    if (fromRef && fromRef.value) {
        currentRef = fromRef.value;
    } else if (individualRollRef && individualRollRef.value) {
        currentRef = individualRollRef.value;
    } else if (productRef && productRef.value) {
        currentRef = productRef.value;
    } else if (externalRef && externalRef.value) {
        currentRef = externalRef.value;
    }
    
    if (currentRef) {
        setTimeout(function() {
            checkAndDisableSubmittedTests(currentRef);
        }, 500); // Small delay to ensure DOM is ready
    }
});

function handleReferenceSelection(selectedValue) {
    const productRefSelect = document.getElementById('product_reference');
    
    // Check and disable submitted tests for the selected reference
    checkAndDisableSubmittedTests(selectedValue);
    
    // Check if fields are locked (read-only mode for resubmitted external reports)
    <?php if ($lock_general_fields): ?>
    if (productRefSelect && productRefSelect.disabled) {
        return; // Exit early if fields are locked
    }
    <?php endif; ?>
    
    const individualRollSelect = document.getElementById('individual_roll_reference');
    const bundleInfo = document.getElementById('bundle_info');
    
    if (!selectedValue || selectedValue === '') {
        // Hide bulk roll selection when no reference is selected
        const bulkSelection = document.getElementById('bulk_roll_selection');
        if (bulkSelection) bulkSelection.style.display = 'none';
        // Hide individual roll selector
        if (individualRollSelect) {
            individualRollSelect.style.display = 'none';
            individualRollSelect.value = '';
            individualRollSelect.removeAttribute('required');
        }
        if (bundleInfo) bundleInfo.style.display = 'none';
        loadQCReferenceData('');
        return;
    }
    
    // Get the selected option
    const selectedOption = productRefSelect.options[productRefSelect.selectedIndex];
    
    // Bundle detection removed - always load reference data directly
    // Hide individual roll selector if it exists
        if (individualRollSelect) {
            individualRollSelect.style.display = 'none';
            individualRollSelect.value = '';
            individualRollSelect.removeAttribute('required');
        }
        if (bundleInfo) bundleInfo.style.display = 'none';
        
    // Load reference data for the selected reference
        loadQCReferenceData(selectedValue);
        
        // Check and disable submitted tests for this reference
        checkAndDisableSubmittedTests(selectedValue);
}

// Handle individual roll selection from bundle
function handleIndividualRollSelection(selectedValue) {
    const individualRollSelect = document.getElementById('individual_roll_reference');
    
    // Check if fields are locked (read-only mode for resubmitted external reports)
    <?php if ($lock_general_fields): ?>
    if (individualRollSelect && individualRollSelect.disabled) {
        return; // Exit early if fields are locked
    }
    <?php endif; ?>
    
    if (selectedValue && selectedValue !== '') {
        // Check and disable submitted tests for the selected individual roll
        checkAndDisableSubmittedTests(selectedValue);
        // Check if this is from a bundle and get first test data to pre-fill
        const productRefSelect = document.getElementById('product_reference');
        const selectedOption = productRefSelect.options[productRefSelect.selectedIndex];
        const isBundle = selectedOption?.getAttribute('data-is-bundle') === 'true';
        const bundleRef = selectedOption?.value || '';
        
        if (isBundle && bundleRef) {
            // Fetch first test data from bundle to pre-fill form
            fetch(`api/get_first_bundle_test_data_qc.php?bundle_ref=${encodeURIComponent(bundleRef)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        // Pre-fill form with first test data
                        if (data.data.batch_information) document.getElementById('qc_batch_info').value = data.data.batch_information;
                        if (data.data.sample_details) document.getElementById('sample_details').value = data.data.sample_details;
                        if (data.data.sample_collected_from) document.getElementById('sample_collected_from').value = data.data.sample_collected_from;
                        if (data.data.sample_received_datetime) document.getElementById('sample_received_datetime').value = data.data.sample_received_datetime;
                        if (data.data.sample_production_date) document.getElementById('sample_production_date').value = data.data.sample_production_date;
                        if (data.data.temperature) document.getElementById('temperature').value = data.data.temperature;
                        if (data.data.rh_percentage) document.getElementById('rh_percentage').value = data.data.rh_percentage;
                        if (data.data.test_period_from) document.getElementById('test_period_from').value = data.data.test_period_from;
                        if (data.data.test_period_to) document.getElementById('test_period_to').value = data.data.test_period_to;
                        if (data.data.roll_number) {
                            const rollNumberField = document.getElementById('roll_number');
                            if (rollNumberField) rollNumberField.value = data.data.roll_number;
                        }
                        if (data.data.gsm) {
                            const gsmField = document.getElementById('gsm');
                            if (gsmField) gsmField.value = data.data.gsm;
                        }
                        if (data.data.customer_reference) {
                            const customerRefField = document.getElementById('customer_reference');
                            if (customerRefField) customerRefField.value = data.data.customer_reference;
                        }
                        if (data.data.sample_received_from) {
                            const sampleReceivedFromField = document.getElementById('sample_received_from');
                            if (sampleReceivedFromField) sampleReceivedFromField.value = data.data.sample_received_from;
                        }
                        if (data.data.lighthouse_reference) {
                            const lighthouseRefField = document.querySelector('input[name="lighthouse_reference"]');
                            if (lighthouseRefField) lighthouseRefField.value = data.data.lighthouse_reference;
                        }
                        if (data.data.other_info) {
                            const otherInfoField = document.querySelector('textarea[name="other_information"]');
                            if (otherInfoField) otherInfoField.value = data.data.other_info;
                        }
                    }
                    // Still load reference data (may have roll-specific info)
                    loadQCReferenceData(selectedValue);
                })
                .catch(error => {
                    console.error('Error fetching bundle test data:', error);
                    // Still load reference data
                    loadQCReferenceData(selectedValue);
                });
        } else {
            loadQCReferenceData(selectedValue);
        }
    }
}

// Load QC reference data when reference is selected
function loadQCReferenceData(reference) {
    const errorDiv = document.getElementById('qc_reference_error');
    if (errorDiv) {
        errorDiv.textContent = '';
        errorDiv.style.background = '';
        errorDiv.style.padding = '';
    }
    
    if (!reference || reference === '') {
        // Show all test checkboxes
        document.querySelectorAll('.test-checkbox').forEach(cb => {
            const label = cb.closest('label');
            if (label) label.style.display = '';
        });
        return;
    }
    
    fetch(`api/get_reference_data.php?reference=${encodeURIComponent(reference)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Only fill if not already filled from bundle data
                if (!document.getElementById('qc_batch_info').value && data.data.batch_info) {
                    document.getElementById('qc_batch_info').value = data.data.batch_info || '';
                }
            } else {
                // Don't show error if bundle data was already loaded
                if (!document.getElementById('qc_batch_info').value) {
                    document.getElementById('qc_reference_error').textContent = data.error;
                    document.getElementById('qc_batch_info').value = '';
                }
            }
        })
        .catch(err => {
            console.error('Error loading reference data:', err);
            // Don't show error if bundle data was already loaded
            if (!document.getElementById('qc_batch_info').value) {
                document.getElementById('qc_reference_error').textContent = 'Error loading reference data';
            }
        });
    
    // Fetch already submitted tests for this reference and hide them
    fetch(`api/get_submitted_tests.php?reference=${encodeURIComponent(reference)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.tests) {
                // Show all checkboxes first
                document.querySelectorAll('.test-checkbox').forEach(cb => {
                    const label = cb.closest('label');
                    if (label) label.style.display = '';
                });
                
                // Hide checkboxes for already submitted tests
                data.tests.forEach(test => {
                    const testName = test.test_name;
                    const method = test.method;
                    // Find and hide the checkbox
                    document.querySelectorAll('.test-checkbox').forEach(cb => {
                        if (cb.getAttribute('data-test-name') === testName && 
                            cb.getAttribute('data-method') === method) {
                            const label = cb.closest('label');
                            if (label) {
                                label.style.display = 'none';
                                // If this checkbox was selected, uncheck it and reset the form
                                if (cb.checked) {
                                    cb.checked = false;
                                    const paramsDiv = cb.closest('.test-item')?.querySelector('.test-parameters');
                                    if (paramsDiv) paramsDiv.style.display = 'none';
                                    // Show all test items again
                                    document.querySelectorAll('.test-item').forEach(item => {
                                        item.style.display = 'block';
                                    });
                                }
                            }
                        }
                    });
                });
            }
        })
        .catch(err => {
            console.error('Error loading submitted tests:', err);
        });
}

// Handle product type change (Production vs External)
        function handleProductTypeChange() {
    // Check if product type is locked (read-only mode for resubmitted external reports)
    const productTypeContainer = document.querySelector('.form-group[style*="opacity: 0.7"]');
    const productionRadio = document.getElementById('product_type_production');
    const externalRadio = document.getElementById('product_type_external');
    
    // If product type is locked, prevent changes
    <?php if ($lock_general_fields): ?>
    if (productTypeContainer || (productionRadio && productionRadio.disabled) || (externalRadio && externalRadio.disabled)) {
        // Restore the original selection
        const isExternalFlag = document.getElementById('is_external_product');
        if (isExternalFlag && isExternalFlag.value === '1') {
            if (externalRadio) externalRadio.checked = true;
        } else {
            if (productionRadio) productionRadio.checked = true;
        }
        return; // Exit early, don't process changes
    }
    <?php endif; ?>
    
    const productReferenceDropdown = document.getElementById('product_reference');
    const externalReferenceInput = document.getElementById('external_reference');
    const externalReferenceInfo = document.getElementById('external_reference_info');
    const isExternalFlag = document.getElementById('is_external_product');
    
    // Check which radio button is actually selected (admin can choose either)
    if (externalRadio && externalRadio.checked) {
        // External Product Mode
        productReferenceDropdown.style.display = 'none';
        productReferenceDropdown.removeAttribute('required');
        productReferenceDropdown.value = '';
        productReferenceDropdown.disabled = true; // Disable to exclude from validation
        
        // Show external reference container
        const externalRefContainer = document.getElementById('external_reference_container');
        if (externalRefContainer) {
            externalRefContainer.style.display = 'block';
        }
        externalReferenceInput.style.display = 'block';
        externalReferenceInfo.style.display = 'block';
        
        // Make external reference editable (AGM sets manually, tester uses provided reference)
        // BUT only if fields are not locked
        <?php if (!$lock_general_fields): ?>
        externalReferenceInput.removeAttribute('readonly');
        externalReferenceInput.classList.remove('readonly');
        externalReferenceInput.setAttribute('required', 'required');
        
        // Make Batch Info editable for external products (AGM fills this)
        const batchInfoField = document.getElementById('qc_batch_info');
        
        if (batchInfoField) {
            batchInfoField.removeAttribute('readonly');
            batchInfoField.classList.remove('readonly');
            batchInfoField.style.background = '#fffef7';
            batchInfoField.style.border = '2px solid #FFB74D';
            batchInfoField.placeholder = 'GT9.H1';
            if (!batchInfoField.value) {
                batchInfoField.value = '';
            }
        }
        <?php else: ?>
        // Fields are locked - keep them readonly
        if (externalReferenceInput) {
            externalReferenceInput.setAttribute('readonly', 'readonly');
            externalReferenceInput.classList.add('readonly');
        }
        <?php endif; ?>
        
        isExternalFlag.value = '1';
        
        console.log('✅ Switched to External Product mode - AGM must manually enter external reference');
        
    } else {
        // Production Product Mode
        productReferenceDropdown.style.display = 'block';
        productReferenceDropdown.setAttribute('required', 'required');
        productReferenceDropdown.disabled = false; // Re-enable for production mode
        
        // Hide external reference container
        const externalRefContainer = document.getElementById('external_reference_container');
        if (externalRefContainer) {
            externalRefContainer.style.display = 'none';
        }
        externalReferenceInput.style.display = 'none';
        externalReferenceInput.value = '';
        externalReferenceInput.removeAttribute('required');
        externalReferenceInfo.style.display = 'none';
        
        isExternalFlag.value = '0';
        
        // Make Batch Info readonly again for production products
        const batchInfoField = document.getElementById('qc_batch_info');
        
        if (batchInfoField) {
            batchInfoField.setAttribute('readonly', 'readonly');
            batchInfoField.classList.add('readonly');
            batchInfoField.style.background = '#ecf0f1';
            batchInfoField.style.border = '1px solid #ccc';
            batchInfoField.placeholder = 'GT9.H1';
        }
        
        console.log('✅ Switched to Production Product mode - Select reference from dropdown');
    }
}

// External reference is manually set by AGM - no auto-generation function needed

// Update date/time and shift display
function updateDateTimeAndShift() {
    const now = new Date();
    const dateTimeStr = now.toLocaleString('en-US', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    });
    
    // Determine shift based on time (8AM to 7:59AM next day)
    const hour = now.getHours();
    let shift = '';
    if (hour >= 8) {
        shift = 'Day Shift';
    } else {
        shift = 'Night Shift';
    }
    
    document.getElementById('dateTimeDisplay').innerHTML = 
        ' Date & Time: ' + dateTimeStr;
    document.getElementById('shiftBanner').innerHTML = 
        ' Shift: ' + shift;
    
    // Update hidden fields
    document.getElementById('dateTime').value = dateTimeStr;
    document.getElementById('shift').value = shift;
    
    // Update sample reference ID
    updateSampleReferenceId();
}

// Generate sample reference ID based on shift
function updateSampleReferenceId() {
    const now = new Date();
    const hour = now.getHours();
    
    // Determine if it's day shift (8AM to 7:59AM next day)
    let isDayShift = hour >= 8;
    
    // Get current date for the shift period
    let shiftDate = new Date(now);
    if (!isDayShift && hour < 8) {
        // Night shift - if it's before 8AM, it's still the previous day's night shift
        shiftDate.setDate(shiftDate.getDate() - 1);
    }
    
    const dateStr = shiftDate.toISOString().split('T')[0].replace(/-/g, '');
    
    // For display purposes, show the format with sequence 001
    // The actual sequence will be generated by PHP on form submission
    const sampleRefId = `GEOCIL-LAB-TR-${dateStr}001`;
    
    // Update the input field
    const sampleRefInput = document.getElementById('sample_reference_id');
    sampleRefInput.value = sampleRefId;
}

        // Update selection count
        function updateSelectionCount() {
    const selected = document.querySelectorAll('input[type="checkbox"]:checked');
            document.getElementById('selected-count').textContent = selected.length;
            
    // Update item styling
            selected.forEach(checkbox => {
        const item = checkbox.closest('.test-item');
        if (item) {
            item.style.backgroundColor = '#e8f5e9';
            item.style.borderColor = '#4caf50';
        }
    });
    
    // Remove styling from unchecked items
    document.querySelectorAll('input[type="checkbox"]:not(:checked)').forEach(checkbox => {
        const item = checkbox.closest('.test-item');
        if (item) {
            item.style.backgroundColor = '';
            item.style.borderColor = '#ddd';
        }
            });
        }

        // GSM grouped table helpers (Mass Per Unit Area)
        const GSM_GROUP_OPTIONS = {
            "Left": ["Left-1", "Left-2", "Left-3", "Left-4"],
            "Middle Left": ["Middle Left-1", "Middle Left-2", "Middle Left-3", "Middle Left-4"],
            "Middle Right": ["Middle Right-1", "Middle Right-2", "Middle Right-3", "Middle Right-4"],
            "Right": ["Right-1", "Right-2", "Right-3", "Right-4"]
        };
        const gsmGroupedState = {};
        function initGsmGrouped(suffixId) {
            const body = document.getElementById(`gsmBody_${suffixId}`);
            if (!body) return;
            gsmGroupedState[suffixId] = { groups: {}, body };
            Object.keys(GSM_GROUP_OPTIONS).forEach(group => createGsmGroupSection(suffixId, group));
        }
        function createGsmGroupSection(suffixId, group) {
            const st = gsmGroupedState[suffixId];
            st.groups[group] = 0;
            gsmAddRow(suffixId, group);
            const btnRow = document.createElement('tr');
            const btnCell = document.createElement('td');
            btnCell.colSpan = 3;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-add';
            btn.textContent = `Add 1 More Row (${group})`;
            // enforce green style
            btn.style.background = 'linear-gradient(90deg, #16a34a 0%, #22c55e 100%)';
            btn.style.color = '#ffffff';
            btn.style.border = 'none';
            btn.style.padding = '6px 10px';
            btn.style.borderRadius = '6px';
            btn.style.fontSize = '12px';
            btn.style.cursor = 'pointer';
            btn.addEventListener('click', () => gsmAddRow(suffixId, group));
            btnCell.appendChild(btn);
            btnRow.appendChild(btnCell);
            st.body.appendChild(btnRow);
        }
        function gsmFindButtonRow(suffixId, group) {
            const st = gsmGroupedState[suffixId];
            return Array.from(st.body.querySelectorAll('tr'))
                .find(r => r.textContent.includes(`Add 1 More Row (${group})`));
        }
        function gsmUpdateAverage(suffixId, group) {
            const st = gsmGroupedState[suffixId];
            const rows = Array.from(st.body.querySelectorAll(`tr[data-group="${group}"]`));
            // Calculate average from Calculated GSM (manual input) values
            const gsmValues = rows.map(r => parseFloat(r.querySelector('.calc-gsm')?.value) || 0).filter(v => v > 0);
            const avg = gsmValues.length ? (gsmValues.reduce((a, b) => a + b, 0) / gsmValues.length).toFixed(2) : '0.0';
            rows.forEach((r, i) => {
                const c = r.querySelector('.avg-cell');
                if (!c) return;
                c.innerHTML = (i === Math.floor(rows.length / 2)) ? `<span class=\"avg-badge\">${avg}</span>` : '';
            });
        }
        function gsmAddRow(suffixId, group) {
            const st = gsmGroupedState[suffixId];
            const count = st.groups[group] || 0;
            if (count >= 4) { alert(`Max 4 rows for ${group}`); return; }
            const label = GSM_GROUP_OPTIONS[group][count];
            
            // Calculate global row index for proper naming
            const allRows = Array.from(st.body.querySelectorAll('tr[data-group]'));
            const globalIndex = allRows.length + 1;
            
            const row = document.createElement('tr');
            row.setAttribute('data-group', group);
            const pos = document.createElement('td');
            const w = document.createElement('td');
            const calc = document.createElement('td');
            const avg = document.createElement('td');
            
            // build dropdown with all group options
            const select = document.createElement('select');
            select.name = `gsm_position_${globalIndex}`;
            select.id = `gsm_position_${globalIndex}`;
            GSM_GROUP_OPTIONS[group].forEach(opt => {
                const o = document.createElement('option');
                o.value = opt;
                o.textContent = opt;
                if (opt === label) o.selected = true;
                select.appendChild(o);
            });
            pos.appendChild(select);
            
            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'weight-input';
            input.name = `gsm_weight_${globalIndex}`;
            input.placeholder = 'Enter weight';
            input.min = '0';
            input.step = '0.01';
            
            // Calculated GSM - now a manual input field
            const calcInput = document.createElement('input');
            calcInput.type = 'number';
            calcInput.className = 'calc-gsm';
            calcInput.name = `gsm_calculated_${globalIndex}`;
            calcInput.placeholder = 'Enter GSM';
            calcInput.min = '0';
            calcInput.step = '0.01';
            calcInput.style.width = '100%';
            calcInput.style.padding = '6px 10px';
            calcInput.style.border = '1px solid #e5e7eb';
            calcInput.style.borderRadius = '8px';
            calc.classList.add('calc-cell');
            calc.appendChild(calcInput);
            
            input.addEventListener('input', () => { 
                gsmUpdateAverage(suffixId, group);
                if (typeof recalcGsmSummary === 'function') recalcGsmSummary(suffixId);
            });
            
            calcInput.addEventListener('input', () => { 
                gsmUpdateAverage(suffixId, group);
                if (typeof recalcGsmSummary === 'function') recalcGsmSummary(suffixId);
            });
            
            w.appendChild(input);
            avg.classList.add('avg-cell');
            row.appendChild(pos); row.appendChild(w); row.appendChild(calc); row.appendChild(avg);
            st.body.insertBefore(row, gsmFindButtonRow(suffixId, group));
            st.groups[group] = count + 1;
            gsmUpdateAverage(suffixId, group);
            if (typeof recalcGsmSummary === 'function') recalcGsmSummary(suffixId);
        }

        // Strip Tensile grouped table using same group logic as GSM
        const stripGroupedState = {};
        function initStripGrouped(suffixId) {
            const body = document.getElementById(`stripBody_${suffixId}`);
            if (!body) return;
            stripGroupedState[suffixId] = { groups: {}, body, indexCounter: 0, mdStrengths: {}, cdStrengths: {} };
            Object.keys(GSM_GROUP_OPTIONS).forEach(group => createStripGroupSection(suffixId, group));
        }
        function createStripGroupSection(suffixId, group) {
            const st = stripGroupedState[suffixId];
            st.groups[group] = 0;
            stripAddRow(suffixId, group);
            const btnRow = document.createElement('tr');
            const btnCell = document.createElement('td');
            btnCell.colSpan = 5;
            const btn = document.createElement('button');
            btn.textContent = `Add 1 More Row (${group})`;
            btn.className = 'btn-add';
            btn.addEventListener('click', function(e) { e.preventDefault(); stripAddRow(suffixId, group); });
            btnCell.appendChild(btn);
            btnRow.appendChild(btnCell);
            st.body.appendChild(btnRow);
        }
        function stripFindButtonRow(suffixId, group) {
            const st = stripGroupedState[suffixId];
            return Array.from(st.body.querySelectorAll('tr'))
                .find(r => r.textContent.includes(`Add 1 More Row (${group})`));
        }
        function stripAddRow(suffixId, group) {
            const st = stripGroupedState[suffixId];
            const count = st.groups[group] || 0;
            if (count >= 4) { alert(`Max 4 rows for ${group}`); return; }
            const label = GSM_GROUP_OPTIONS[group][count];
            const row = document.createElement('tr');
            row.setAttribute('data-group', group);
            const pos = document.createElement('td');
            const dirCell = document.createElement('td');
            const strengthCell = document.createElement('td');
            const ratioCell = document.createElement('td');
            const elongCell = document.createElement('td');
            const select = document.createElement('select');
            GSM_GROUP_OPTIONS[group].forEach(opt => {
                const o = document.createElement('option');
                o.value = opt; o.textContent = opt; if (opt === label) o.selected = true; select.appendChild(o);
            });
            const idx = ++st.indexCounter;
            select.name = `strip_position_${idx}`;
            select.id = `strip_position_${idx}`;
            pos.appendChild(select);
            const dirSelect = document.createElement('select');
            dirSelect.name = `strip_direction_${idx}`;
            dirSelect.id = `strip_direction_${idx}`;
            dirSelect.style.width = '100%';
            dirSelect.style.padding = '6px 10px';
            dirSelect.style.border = '1px solid #e5e7eb';
            dirSelect.style.borderRadius = '8px';
            const mdOpt = document.createElement('option');
            mdOpt.value = 'MD'; mdOpt.textContent = 'MD';
            const cdOpt = document.createElement('option');
            cdOpt.value = 'CD'; cdOpt.textContent = 'CD';
            dirSelect.appendChild(mdOpt);
            dirSelect.appendChild(cdOpt);
            dirSelect.addEventListener('change', () => { stripCalculateRatio(suffixId, idx); });
            dirCell.appendChild(dirSelect);
            const strengthInput = document.createElement('input');
            strengthInput.type = 'number';
            strengthInput.className = 'strip-strength';
            strengthInput.placeholder = 'Enter strength';
            strengthInput.min = '0';
            strengthInput.step = '0.0001';
            strengthInput.name = `strip_strength_${idx}`;
            strengthInput.addEventListener('input', () => { stripCalculateRatio(suffixId, idx); });
            strengthCell.appendChild(strengthInput);
            const ratioSpan = document.createElement('span');
            ratioSpan.className = 'strip-ratio';
            ratioSpan.id = `strip_ratio_${idx}`;
            ratioSpan.style.fontWeight = 'bold';
            ratioSpan.style.color = '#27ae60';
            ratioSpan.textContent = '0.00';
            ratioCell.appendChild(ratioSpan);
            ratioCell.style.textAlign = 'center';
            ratioCell.style.background = '#f8f9fa';
            const elongInput = document.createElement('input');
            elongInput.type = 'number';
            elongInput.className = 'strip-elongation';
            elongInput.placeholder = 'Enter elongation';
            elongInput.min = '0';
            elongInput.step = '0.0001';
            elongInput.name = `strip_elongation_${idx}`;
            elongInput.addEventListener('input', () => { if (typeof recalcStripSummary === 'function') recalcStripSummary(suffixId); });
            elongCell.appendChild(elongInput);
            row.appendChild(pos); row.appendChild(dirCell); row.appendChild(strengthCell); row.appendChild(ratioCell); row.appendChild(elongCell);
            st.body.insertBefore(row, stripFindButtonRow(suffixId, group));
            st.groups[group] = count + 1;
        }
        
        function stripCalculateRatio(suffixId, rowIdx) {
            const st = stripGroupedState[suffixId];
            const body = document.getElementById(`stripBody_${suffixId}`);
            if (!body) return;
            
            const allRows = Array.from(body.querySelectorAll('tr[data-group]'));
            
            // Clear all ratios first
            allRows.forEach(row => {
                const ratioSpan = row.querySelector('.strip-ratio');
                if (ratioSpan) ratioSpan.textContent = '';
            });
            
            // Group rows into MD-CD pairs
            let mdRow = null;
            let mdValue = null;
            
            allRows.forEach((row, idx) => {
                const dirSelect = row.querySelector('select[name^="strip_direction_"]');
                const strengthInput = row.querySelector('.strip-strength');
                
                if (!dirSelect || !strengthInput) return;
                
                const dir = dirSelect.value;
                const strength = parseFloat(strengthInput.value);
                
                if (!isNaN(strength) && strength > 0) {
                    if (dir === 'MD') {
                        // Store MD row and value for pairing
                        mdRow = row;
                        mdValue = strength;
                    } else if (dir === 'CD' && mdRow && mdValue) {
                        // Found a CD after an MD - calculate ratio for this pair
                        const cdValue = strength;
                        const ratio = mdValue > 0 ? cdValue / mdValue : 0;
                        const ratioText = `1:${ratio.toFixed(2)}`;
                        
                        // Show ratio in the CD row
                        const ratioSpan = row.querySelector('.strip-ratio');
                        if (ratioSpan) {
                            ratioSpan.textContent = ratioText;
                        }
                        
                        // Reset for next pair
                        mdRow = null;
                        mdValue = null;
                    }
                }
            });
            
            // Recalculate summary
            if (typeof recalcStripSummary === 'function') recalcStripSummary(suffixId);
        }
        
        // Recalculate summary stats for Strip Tensile table (separate MD and CD)
        function recalcStripSummary(suffixId) {
            const body = document.getElementById(`stripBody_${suffixId}`);
            if (!body) return;
            const rows = Array.from(body.querySelectorAll('tr[data-group]'));
            
            // Collect all values with their direction
            const mdStrengths = [];
            const mdElongs = [];
            const cdStrengths = [];
            const cdElongs = [];
            
            rows.forEach(row => {
                const dirSelect = row.querySelector('select[name^="strip_direction_"]');
                const strengthInput = row.querySelector('.strip-strength');
                const elongInput = row.querySelector('.strip-elongation');
                
                if (!dirSelect || !strengthInput || !elongInput) return;
                
                const dir = dirSelect.value;
                const strength = parseFloat(strengthInput.value);
                const elong = parseFloat(elongInput.value);
                
                if (dir === 'MD') {
                    if (!isNaN(strength) && strength > 0) mdStrengths.push(strength);
                    if (!isNaN(elong) && elong > 0) mdElongs.push(elong);
                } else if (dir === 'CD') {
                    if (!isNaN(strength) && strength > 0) cdStrengths.push(strength);
                    if (!isNaN(elong) && elong > 0) cdElongs.push(elong);
                }
            });
            
            // Helper to calc stats (Average, SD, CV%, Max, Min)
            const calcStats = (vals) => {
                const n = vals.length;
                if (n === 0) return { avg: 0, sd: 0, cv: 0, max: 0, min: 0 };
                
                const sum = vals.reduce((a,b)=>a+b,0);
                const avg = sum / n;
                
                let sd = 0;
                if (n > 0) {
                    let sumSquaredDiff = 0;
                    for (let i = 0; i < vals.length; i++) {
                        const diff = vals[i] - avg;
                        sumSquaredDiff += diff * diff;
                    }
                    const variance = sumSquaredDiff / n;
                    sd = Math.sqrt(variance);
                }
                
                const cv = (avg > 0 && sd > 0) ? (sd / avg) * 100 : 0;
                const max = n > 0 ? Math.max(...vals) : 0;
                const min = n > 0 ? Math.min(...vals) : 0;
                
                return { avg, sd, cv, max, min };
            };
            
            const mdStrengthStats = calcStats(mdStrengths);
            const mdElongStats = calcStats(mdElongs);
            const cdStrengthStats = calcStats(cdStrengths);
            const cdElongStats = calcStats(cdElongs);
            
            const fmt2 = v => (isFinite(v) ? v.toFixed(2) : '0.00');
            const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            const setInputVal = (id, val) => { const el = document.getElementById(id); if (el) el.value = val; };
            
            // MD Strength
            setText(`strip_sum_md_strength_avg_${suffixId}`, fmt2(mdStrengthStats.avg));
            setInputVal(`strip_input_md_strength_avg_${suffixId}`, mdStrengthStats.avg);
            setText(`strip_sum_md_strength_sd_${suffixId}`, fmt2(mdStrengthStats.sd));
            setInputVal(`strip_input_md_strength_sd_${suffixId}`, mdStrengthStats.sd);
            setText(`strip_sum_md_strength_cv_${suffixId}`, fmt2(mdStrengthStats.cv));
            setInputVal(`strip_input_md_strength_cv_${suffixId}`, mdStrengthStats.cv);
            setText(`strip_sum_md_strength_max_${suffixId}`, fmt2(mdStrengthStats.max));
            setInputVal(`strip_input_md_strength_max_${suffixId}`, mdStrengthStats.max);
            setText(`strip_sum_md_strength_min_${suffixId}`, fmt2(mdStrengthStats.min));
            setInputVal(`strip_input_md_strength_min_${suffixId}`, mdStrengthStats.min);
            
            // MD Elongation
            setText(`strip_sum_md_elong_avg_${suffixId}`, fmt2(mdElongStats.avg));
            setInputVal(`strip_input_md_elong_avg_${suffixId}`, mdElongStats.avg);
            setText(`strip_sum_md_elong_sd_${suffixId}`, fmt2(mdElongStats.sd));
            setInputVal(`strip_input_md_elong_sd_${suffixId}`, mdElongStats.sd);
            setText(`strip_sum_md_elong_cv_${suffixId}`, fmt2(mdElongStats.cv));
            setInputVal(`strip_input_md_elong_cv_${suffixId}`, mdElongStats.cv);
            setText(`strip_sum_md_elong_max_${suffixId}`, fmt2(mdElongStats.max));
            setInputVal(`strip_input_md_elong_max_${suffixId}`, mdElongStats.max);
            setText(`strip_sum_md_elong_min_${suffixId}`, fmt2(mdElongStats.min));
            setInputVal(`strip_input_md_elong_min_${suffixId}`, mdElongStats.min);
            
            // CD Strength
            setText(`strip_sum_cd_strength_avg_${suffixId}`, fmt2(cdStrengthStats.avg));
            setInputVal(`strip_input_cd_strength_avg_${suffixId}`, cdStrengthStats.avg);
            setText(`strip_sum_cd_strength_sd_${suffixId}`, fmt2(cdStrengthStats.sd));
            setInputVal(`strip_input_cd_strength_sd_${suffixId}`, cdStrengthStats.sd);
            setText(`strip_sum_cd_strength_cv_${suffixId}`, fmt2(cdStrengthStats.cv));
            setInputVal(`strip_input_cd_strength_cv_${suffixId}`, cdStrengthStats.cv);
            setText(`strip_sum_cd_strength_max_${suffixId}`, fmt2(cdStrengthStats.max));
            setInputVal(`strip_input_cd_strength_max_${suffixId}`, cdStrengthStats.max);
            setText(`strip_sum_cd_strength_min_${suffixId}`, fmt2(cdStrengthStats.min));
            setInputVal(`strip_input_cd_strength_min_${suffixId}`, cdStrengthStats.min);
            
            // CD Elongation
            setText(`strip_sum_cd_elong_avg_${suffixId}`, fmt2(cdElongStats.avg));
            setInputVal(`strip_input_cd_elong_avg_${suffixId}`, cdElongStats.avg);
            setText(`strip_sum_cd_elong_sd_${suffixId}`, fmt2(cdElongStats.sd));
            setInputVal(`strip_input_cd_elong_sd_${suffixId}`, cdElongStats.sd);
            setText(`strip_sum_cd_elong_cv_${suffixId}`, fmt2(cdElongStats.cv));
            setInputVal(`strip_input_cd_elong_cv_${suffixId}`, cdElongStats.cv);
            setText(`strip_sum_cd_elong_max_${suffixId}`, fmt2(cdElongStats.max));
            setInputVal(`strip_input_cd_elong_max_${suffixId}`, cdElongStats.max);
            setText(`strip_sum_cd_elong_min_${suffixId}`, fmt2(cdElongStats.min));
            setInputVal(`strip_input_cd_elong_min_${suffixId}`, cdElongStats.min);
        }

        // CBR Puncture Resistance grouped table using same group logic as GSM
        const cbrGroupedState = {};
        function initCbrGrouped(suffixId) {
            const body = document.getElementById(`cbrBody_${suffixId}`);
            if (!body) return;
            cbrGroupedState[suffixId] = { groups: {}, body, indexCounter: 0 };
            Object.keys(GSM_GROUP_OPTIONS).forEach(group => createCbrGroupSection(suffixId, group));
        }
        function createCbrGroupSection(suffixId, group) {
            const st = cbrGroupedState[suffixId];
            st.groups[group] = 0;
            cbrAddRow(suffixId, group);
            const btnRow = document.createElement('tr');
            const btnCell = document.createElement('td');
            btnCell.colSpan = 3;
            const btn = document.createElement('button');
            btn.textContent = `Add 1 More Row (${group})`;
            btn.className = 'btn-add';
            btn.addEventListener('click', function(e) { e.preventDefault(); cbrAddRow(suffixId, group); });
            btnCell.appendChild(btn);
            btnRow.appendChild(btnCell);
            st.body.appendChild(btnRow);
        }
        function cbrFindButtonRow(suffixId, group) {
            const st = cbrGroupedState[suffixId];
            return Array.from(st.body.querySelectorAll('tr'))
                .find(r => r.textContent.includes(`Add 1 More Row (${group})`));
        }
        function cbrAddRow(suffixId, group) {
            const st = cbrGroupedState[suffixId];
            const count = st.groups[group] || 0;
            if (count >= 4) { alert(`Max 4 rows for ${group}`); return; }
            const label = GSM_GROUP_OPTIONS[group][count];
            const row = document.createElement('tr');
            row.setAttribute('data-group', group);
            const pos = document.createElement('td');
            const forceCell = document.createElement('td');
            const displCell = document.createElement('td');
            const select = document.createElement('select');
            GSM_GROUP_OPTIONS[group].forEach(opt => {
                const o = document.createElement('option');
                o.value = opt; o.textContent = opt; if (opt === label) o.selected = true; select.appendChild(o);
            });
            const idx = ++st.indexCounter;
            select.name = `cbr_position_${idx}`;
            select.id = `cbr_position_${idx}`;
            pos.appendChild(select);
            const forceInput = document.createElement('input');
            forceInput.type = 'number';
            forceInput.className = 'cbr-force';
            forceInput.placeholder = 'Enter force';
            forceInput.min = '0';
            forceInput.step = '0.0001';
            forceInput.name = `cbr_force_${idx}`;
            forceInput.addEventListener('input', () => { if (typeof recalcCbrSummary === 'function') recalcCbrSummary(suffixId); });
            forceCell.appendChild(forceInput);
            const displInput = document.createElement('input');
            displInput.type = 'number';
            displInput.className = 'cbr-displacement';
            displInput.placeholder = 'Enter displacement';
            displInput.min = '0';
            displInput.step = '0.0001';
            displInput.name = `cbr_displacement_${idx}`;
            displInput.addEventListener('input', () => { if (typeof recalcCbrSummary === 'function') recalcCbrSummary(suffixId); });
            displCell.appendChild(displInput);
            row.appendChild(pos); row.appendChild(forceCell); row.appendChild(displCell);
            st.body.insertBefore(row, cbrFindButtonRow(suffixId, group));
            st.groups[group] = count + 1;
        }

        // Recalculate summary stats for CBR table (ALL values combined, not separated by MD/CD)
        function recalcCbrSummary(suffixId) {
            const body = document.getElementById(`cbrBody_${suffixId}`);
            if (!body) return;
            const rows = Array.from(body.querySelectorAll('tr[data-group]'));
            
            // Collect ALL values (not separated by direction)
            const allForces = [];
            const allDispls = [];
            
            rows.forEach(row => {
                const forceInput = row.querySelector('.cbr-force');
                const displInput = row.querySelector('.cbr-displacement');
                
                if (!forceInput || !displInput) return;
                
                const force = parseFloat(forceInput.value);
                const displ = parseFloat(displInput.value);
                
                if (!isNaN(force) && force > 0) allForces.push(force);
                if (!isNaN(displ) && displ > 0) allDispls.push(displ);
            });
            
            // Helper to calc stats (Average, SD, CV%, Max, Min)
            const calcStats = (vals) => {
                const n = vals.length;
                if (n === 0) return { avg: 0, sd: 0, cv: 0, max: 0, min: 0 };
                
                // Calculate average
                const sum = vals.reduce((a,b)=>a+b,0);
                const avg = sum / n;
                
                // Calculate SD - using population standard deviation (divide by n)
                let sd = 0;
                if (n > 0) {
                    let sumSquaredDiff = 0;
                    for (let i = 0; i < vals.length; i++) {
                        const diff = vals[i] - avg;
                        sumSquaredDiff += diff * diff;
                    }
                    const variance = sumSquaredDiff / n;  // Use n instead of n-1
                    sd = Math.sqrt(variance);
                }
                
                // Calculate CV% = (SD / Average) * 100
                const cv = (avg > 0 && sd > 0) ? (sd / avg) * 100 : 0;
                
                // Calculate Max and Min
                const max = n > 0 ? Math.max(...vals) : 0;
                const min = n > 0 ? Math.min(...vals) : 0;
                
                return { avg, sd, cv, max, min };
            };
            
            const forceStats = calcStats(allForces);
            const displStats = calcStats(allDispls);
            
            const fmt1 = v => (isFinite(v) ? v.toFixed(1) : '0.0');
            const fmt2 = v => (isFinite(v) ? v.toFixed(2) : '0.00');
            const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            const setInput = (id, val) => { const el = document.getElementById(id); if (el) el.value = val; };
            
            // Force stats
            setText(`cbr_sum_force_avg_${suffixId}`, fmt2(forceStats.avg));
            setText(`cbr_sum_force_sd_${suffixId}`, fmt1(forceStats.sd));
            setText(`cbr_sum_force_cv_${suffixId}`, fmt1(forceStats.cv));
            setText(`cbr_sum_force_max_${suffixId}`, fmt2(forceStats.max));
            setText(`cbr_sum_force_min_${suffixId}`, fmt2(forceStats.min));
            setInput(`cbr_sum_force_avg_input_${suffixId}`, forceStats.avg);
            setInput(`cbr_sum_force_sd_input_${suffixId}`, forceStats.sd);
            setInput(`cbr_sum_force_cv_input_${suffixId}`, forceStats.cv);
            setInput(`cbr_sum_force_max_input_${suffixId}`, forceStats.max);
            setInput(`cbr_sum_force_min_input_${suffixId}`, forceStats.min);
            
            // Displacement stats
            setText(`cbr_sum_displ_avg_${suffixId}`, fmt1(displStats.avg));
            setText(`cbr_sum_displ_sd_${suffixId}`, fmt1(displStats.sd));
            setText(`cbr_sum_displ_cv_${suffixId}`, fmt1(displStats.cv));
            setText(`cbr_sum_displ_max_${suffixId}`, fmt1(displStats.max));
            setText(`cbr_sum_displ_min_${suffixId}`, fmt1(displStats.min));
            setInput(`cbr_sum_displ_avg_input_${suffixId}`, displStats.avg);
            setInput(`cbr_sum_displ_sd_input_${suffixId}`, displStats.sd);
            setInput(`cbr_sum_displ_cv_input_${suffixId}`, displStats.cv);
            setInput(`cbr_sum_displ_max_input_${suffixId}`, displStats.max);
            setInput(`cbr_sum_displ_min_input_${suffixId}`, displStats.min);
        }

        // Grab Tensile grouped table using same group logic as GSM
        const grabGroupedState = {};
        function initGrabGrouped(suffixId) {
            const body = document.getElementById(`grabBody_${suffixId}`);
            if (!body) return;
            grabGroupedState[suffixId] = { groups: {}, body, indexCounter: 0 };
            Object.keys(GSM_GROUP_OPTIONS).forEach(group => createGrabGroupSection(suffixId, group));
        }
        function createGrabGroupSection(suffixId, group) {
            const st = grabGroupedState[suffixId];
            st.groups[group] = 0;
            grabAddRow(suffixId, group);
            const btnRow = document.createElement('tr');
            const btnCell = document.createElement('td');
            btnCell.colSpan = 4;
            const btn = document.createElement('button');
            btn.textContent = `Add 1 More Row (${group})`;
            btn.className = 'btn-add';
            btn.addEventListener('click', function(e) { e.preventDefault(); grabAddRow(suffixId, group); });
            btnCell.appendChild(btn);
            btnRow.appendChild(btnCell);
            st.body.appendChild(btnRow);
        }
        function grabFindButtonRow(suffixId, group) {
            const st = grabGroupedState[suffixId];
            return Array.from(st.body.querySelectorAll('tr'))
                .find(r => r.textContent.includes(`Add 1 More Row (${group})`));
        }
        function grabAddRow(suffixId, group) {
            const st = grabGroupedState[suffixId];
            const count = st.groups[group] || 0;
            if (count >= 4) { alert(`Max 4 rows for ${group}`); return; }
            const label = GSM_GROUP_OPTIONS[group][count];
            const row = document.createElement('tr');
            row.setAttribute('data-group', group);
            const pos = document.createElement('td');
            const dirCell = document.createElement('td');
            const forceCell = document.createElement('td');
            const elongCell = document.createElement('td');
            const select = document.createElement('select');
            GSM_GROUP_OPTIONS[group].forEach(opt => {
                const o = document.createElement('option');
                o.value = opt; o.textContent = opt; if (opt === label) o.selected = true; select.appendChild(o);
            });
            const idx = ++st.indexCounter;
            select.name = `grab_position_${idx}`;
            select.id = `grab_position_${idx}`;
            pos.appendChild(select);
            const dirSelect = document.createElement('select');
            dirSelect.name = `grab_direction_${idx}`;
            dirSelect.id = `grab_direction_${idx}`;
            dirSelect.style.width = '100%';
            dirSelect.style.padding = '6px 10px';
            dirSelect.style.border = '1px solid #e5e7eb';
            dirSelect.style.borderRadius = '8px';
            const mdOpt = document.createElement('option');
            mdOpt.value = 'MD'; mdOpt.textContent = 'MD';
            const cdOpt = document.createElement('option');
            cdOpt.value = 'CD'; cdOpt.textContent = 'CD';
            dirSelect.appendChild(mdOpt);
            dirSelect.appendChild(cdOpt);
            dirSelect.addEventListener('change', () => { if (typeof recalcGrabSummary === 'function') recalcGrabSummary(suffixId); });
            dirCell.appendChild(dirSelect);
            const forceInput = document.createElement('input');
            forceInput.type = 'number';
            forceInput.className = 'grab-force';
            forceInput.placeholder = 'Enter force';
            forceInput.min = '0';
            forceInput.step = '0.0001';
            forceInput.name = `grab_force_${idx}`;
            forceInput.addEventListener('input', () => { if (typeof recalcGrabSummary === 'function') recalcGrabSummary(suffixId); });
            forceCell.appendChild(forceInput);
            const elongInput = document.createElement('input');
            elongInput.type = 'number';
            elongInput.className = 'grab-elongation';
            elongInput.placeholder = 'Enter elongation';
            elongInput.min = '0';
            elongInput.step = '0.0001';
            elongInput.name = `grab_elongation_${idx}`;
            elongInput.addEventListener('input', () => { if (typeof recalcGrabSummary === 'function') recalcGrabSummary(suffixId); });
            elongCell.appendChild(elongInput);
            row.appendChild(pos); row.appendChild(dirCell); row.appendChild(forceCell); row.appendChild(elongCell);
            st.body.insertBefore(row, grabFindButtonRow(suffixId, group));
            st.groups[group] = count + 1;
        }

        // Recalculate summary stats for Grab Tensile table (separate MD and CD)
        function recalcGrabSummary(suffixId) {
            const body = document.getElementById(`grabBody_${suffixId}`);
            if (!body) return;
            const rows = Array.from(body.querySelectorAll('tr[data-group]'));
            
            // Collect all values with their direction
            const mdForces = [];
            const mdElongs = [];
            const cdForces = [];
            const cdElongs = [];
            
            rows.forEach(row => {
                const dirSelect = row.querySelector('select[name^="grab_direction_"]');
                const forceInput = row.querySelector('.grab-force');
                const elongInput = row.querySelector('.grab-elongation');
                
                if (!dirSelect || !forceInput || !elongInput) return;
                
                const dir = dirSelect.value;
                const force = parseFloat(forceInput.value);
                const elong = parseFloat(elongInput.value);
                
                if (dir === 'MD') {
                    if (!isNaN(force) && force > 0) mdForces.push(force);
                    if (!isNaN(elong) && elong > 0) mdElongs.push(elong);
                } else if (dir === 'CD') {
                    if (!isNaN(force) && force > 0) cdForces.push(force);
                    if (!isNaN(elong) && elong > 0) cdElongs.push(elong);
                }
            });
            
            // Helper to calc stats (Average, SD, CV%, Max, Min)
            const calcStats = (vals) => {
                const n = vals.length;
                if (n === 0) return { avg: 0, sd: 0, cv: 0, max: 0, min: 0 };
                
                // Calculate average
                const avg = vals.reduce((a,b)=>a+b,0) / n;
                
                // Calculate SD using sample standard deviation (n-1)
                let sd = 0;
                if (n > 1) {
                    const sumSquaredDiff = vals.reduce((acc, v) => acc + Math.pow(v - avg, 2), 0);
                    sd = Math.sqrt(sumSquaredDiff / (n - 1));
                }
                
                // Calculate CV%
                const cv = (avg > 0 && sd > 0) ? (sd / avg) * 100 : 0;
                
                // Calculate Max and Min
                const max = n > 0 ? Math.max(...vals) : 0;
                const min = n > 0 ? Math.min(...vals) : 0;
                
                return { avg, sd, cv, max, min };
            };
            
            const mdForceStats = calcStats(mdForces);
            const mdElongStats = calcStats(mdElongs);
            const cdForceStats = calcStats(cdForces);
            const cdElongStats = calcStats(cdElongs);
            
            const fmt2 = v => (isFinite(v) ? v.toFixed(2) : '0.00');
            const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            const setInputVal = (id, val) => { const el = document.getElementById(id); if (el) el.value = val; };
            
            // MD Force
            setText(`grab_sum_md_force_avg_${suffixId}`, fmt2(mdForceStats.avg));
            setInputVal(`grab_input_md_force_avg_${suffixId}`, mdForceStats.avg);
            setText(`grab_sum_md_force_sd_${suffixId}`, fmt2(mdForceStats.sd));
            setInputVal(`grab_input_md_force_sd_${suffixId}`, mdForceStats.sd);
            setText(`grab_sum_md_force_cv_${suffixId}`, fmt2(mdForceStats.cv));
            setInputVal(`grab_input_md_force_cv_${suffixId}`, mdForceStats.cv);
            setText(`grab_sum_md_force_max_${suffixId}`, fmt2(mdForceStats.max));
            setInputVal(`grab_input_md_force_max_${suffixId}`, mdForceStats.max);
            setText(`grab_sum_md_force_min_${suffixId}`, fmt2(mdForceStats.min));
            setInputVal(`grab_input_md_force_min_${suffixId}`, mdForceStats.min);
            
            // MD Elongation
            setText(`grab_sum_md_elong_avg_${suffixId}`, fmt2(mdElongStats.avg));
            setInputVal(`grab_input_md_elong_avg_${suffixId}`, mdElongStats.avg);
            setText(`grab_sum_md_elong_sd_${suffixId}`, fmt2(mdElongStats.sd));
            setInputVal(`grab_input_md_elong_sd_${suffixId}`, mdElongStats.sd);
            setText(`grab_sum_md_elong_cv_${suffixId}`, fmt2(mdElongStats.cv));
            setInputVal(`grab_input_md_elong_cv_${suffixId}`, mdElongStats.cv);
            setText(`grab_sum_md_elong_max_${suffixId}`, fmt2(mdElongStats.max));
            setInputVal(`grab_input_md_elong_max_${suffixId}`, mdElongStats.max);
            setText(`grab_sum_md_elong_min_${suffixId}`, fmt2(mdElongStats.min));
            setInputVal(`grab_input_md_elong_min_${suffixId}`, mdElongStats.min);
            
            // CD Force
            setText(`grab_sum_cd_force_avg_${suffixId}`, fmt2(cdForceStats.avg));
            setInputVal(`grab_input_cd_force_avg_${suffixId}`, cdForceStats.avg);
            setText(`grab_sum_cd_force_sd_${suffixId}`, fmt2(cdForceStats.sd));
            setInputVal(`grab_input_cd_force_sd_${suffixId}`, cdForceStats.sd);
            setText(`grab_sum_cd_force_cv_${suffixId}`, fmt2(cdForceStats.cv));
            setInputVal(`grab_input_cd_force_cv_${suffixId}`, cdForceStats.cv);
            setText(`grab_sum_cd_force_max_${suffixId}`, fmt2(cdForceStats.max));
            setInputVal(`grab_input_cd_force_max_${suffixId}`, cdForceStats.max);
            setText(`grab_sum_cd_force_min_${suffixId}`, fmt2(cdForceStats.min));
            setInputVal(`grab_input_cd_force_min_${suffixId}`, cdForceStats.min);
            
            // CD Elongation
            setText(`grab_sum_cd_elong_avg_${suffixId}`, fmt2(cdElongStats.avg));
            setInputVal(`grab_input_cd_elong_avg_${suffixId}`, cdElongStats.avg);
            setText(`grab_sum_cd_elong_sd_${suffixId}`, fmt2(cdElongStats.sd));
            setInputVal(`grab_input_cd_elong_sd_${suffixId}`, cdElongStats.sd);
            setText(`grab_sum_cd_elong_cv_${suffixId}`, fmt2(cdElongStats.cv));
            setInputVal(`grab_input_cd_elong_cv_${suffixId}`, cdElongStats.cv);
            setText(`grab_sum_cd_elong_max_${suffixId}`, fmt2(cdElongStats.max));
            setInputVal(`grab_input_cd_elong_max_${suffixId}`, cdElongStats.max);
            setText(`grab_sum_cd_elong_min_${suffixId}`, fmt2(cdElongStats.min));
            setInputVal(`grab_input_cd_elong_min_${suffixId}`, cdElongStats.min);
        }

        // Thickness grouped table (Under 2kPa) using same group logic as GSM
        const thicknessGroupedState = {};
        function initThicknessGrouped(suffixId) {
            const body = document.getElementById(`thkBody_${suffixId}`);
            if (!body) return;
            thicknessGroupedState[suffixId] = { groups: {}, body, indexCounter: 0 };
            Object.keys(GSM_GROUP_OPTIONS).forEach(group => createThicknessGroupSection(suffixId, group));
        }
        function createThicknessGroupSection(suffixId, group) {
            const st = thicknessGroupedState[suffixId];
            st.groups[group] = 0;
            thicknessAddRow(suffixId, group);
            const btnRow = document.createElement('tr');
            const btnCell = document.createElement('td');
            btnCell.colSpan = 3;
            const btn = document.createElement('button');
            btn.textContent = `Add 1 More Row (${group})`;
            btn.className = 'btn-add';
            btn.addEventListener('click', function(e) { e.preventDefault(); thicknessAddRow(suffixId, group); });
            btnCell.appendChild(btn);
            btnRow.appendChild(btnCell);
            st.body.appendChild(btnRow);
        }
        function thicknessFindButtonRow(suffixId, group) {
            const st = thicknessGroupedState[suffixId];
            return Array.from(st.body.querySelectorAll('tr'))
                .find(r => r.textContent.includes(`Add 1 More Row (${group})`));
        }
        function thicknessUpdateAverage(suffixId, group) {
            const st = thicknessGroupedState[suffixId];
            const rows = Array.from(st.body.querySelectorAll(`tr[data-group="${group}"]`));
            const vals = rows.map(r => parseFloat(r.querySelector('.thk-input')?.value) || 0).filter(v => v > 0);
            const avg = vals.length ? (vals.reduce((a, b) => a + b, 0) / vals.length).toFixed(2) : '0.0';
            rows.forEach((r, i) => {
                const c = r.querySelector('.avg-cell');
                if (!c) return;
                c.innerHTML = (i === Math.floor(rows.length / 2)) ? `<span class=\"avg-badge\">${avg}</span>` : '';
            });
            if (typeof recalcThicknessSummary === 'function') recalcThicknessSummary(suffixId);
        }
        function thicknessAddRow(suffixId, group) {
            const st = thicknessGroupedState[suffixId];
            const count = st.groups[group] || 0;
            if (count >= 4) { alert(`Max 4 rows for ${group}`); return; }
            const label = GSM_GROUP_OPTIONS[group][count];
            const row = document.createElement('tr');
            row.setAttribute('data-group', group);
            const pos = document.createElement('td');
            const val = document.createElement('td');
            const avg = document.createElement('td');
            const select = document.createElement('select');
            GSM_GROUP_OPTIONS[group].forEach(opt => {
                const o = document.createElement('option');
                o.value = opt; o.textContent = opt; if (opt === label) o.selected = true; select.appendChild(o);
            });
            // assign index names-compatible with PHP collection
            const idx = ++st.indexCounter;
            select.name = `astmd5199_position_${idx}`;
            select.id = `astmd5199_position_${idx}`;
            pos.appendChild(select);
            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'thk-input';
            input.placeholder = 'Enter thickness';
            input.min = '0';
            input.step = '0.0001';
            input.name = `astmd5199_under2kpa_${idx}`;
            input.addEventListener('input', () => { thicknessUpdateAverage(suffixId, group); recalcThicknessSummary(suffixId); });
            val.appendChild(input);
            avg.classList.add('avg-cell');
            row.appendChild(pos); row.appendChild(val); row.appendChild(avg);
            st.body.insertBefore(row, thicknessFindButtonRow(suffixId, group));
            st.groups[group] = count + 1;
            thicknessUpdateAverage(suffixId, group);
            if (typeof recalcThicknessSummary === 'function') recalcThicknessSummary(suffixId);
        }

        // Recalculate summary stats for thickness table (all inputs across groups)
        function recalcThicknessSummary(suffixId) {
            const body = document.getElementById(`thkBody_${suffixId}`);
            if (!body) return;
            const inputs = Array.from(body.querySelectorAll('input.thk-input'));
            const values = inputs.map(i => parseFloat(i.value)).filter(v => !isNaN(v) && v > 0);
            const n = values.length;
            const avg = n ? (values.reduce((a,b)=>a+b,0) / n) : 0;
            // Population SD to match general UI behavior; adjust if needed
            const variance = (n > 1) ? values.reduce((acc,v)=> acc + Math.pow(v - avg, 2), 0) / (n-1) : 0;
            const sd = Math.sqrt(variance);
            const cv = avg > 0 ? (sd / avg) * 100 : 0;
            const max = n ? Math.max(...values) : 0;
            const min = n ? Math.min(...values) : 0;
            const fmt3 = v => (isFinite(v) ? v.toFixed(3) : '0.000');
            const fmt2 = v => (isFinite(v) ? v.toFixed(2) : '0.00');
            const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            const setInput = (id, val) => { const el = document.getElementById(id); if (el) el.value = val; };
            setText(`thk_sum_avg_${suffixId}`, fmt3(avg));
            setText(`thk_sum_sd_${suffixId}`, fmt3(sd));
            setText(`thk_sum_cv_${suffixId}`, fmt2(cv));
            setText(`thk_sum_max_${suffixId}`, fmt3(max));
            setText(`thk_sum_min_${suffixId}`, fmt3(min));
            // Update hidden inputs
            setInput(`thk_sum_avg_input_${suffixId}`, avg);
            setInput(`thk_sum_sd_input_${suffixId}`, sd);
            setInput(`thk_sum_cv_input_${suffixId}`, cv);
            setInput(`thk_sum_max_input_${suffixId}`, max);
            setInput(`thk_sum_min_input_${suffixId}`, min);
        }

        // Recalculate summary stats for GSM table (Calculated GSM values)
        function recalcGsmSummary(suffixId) {
            const body = document.getElementById(`gsmBody_${suffixId}`);
            if (!body) return;
            const inputs = Array.from(body.querySelectorAll('input.calc-gsm'));
            const values = inputs.map(i => parseFloat(i.value)).filter(v => !isNaN(v) && v > 0);
            const n = values.length;
            const avg = n ? (values.reduce((a,b)=>a+b,0) / n) : 0;
            const variance = (n > 1) ? values.reduce((acc,v)=> acc + Math.pow(v - avg, 2), 0) / (n - 1) : 0;
            const sd = Math.sqrt(variance);
            const cv = avg > 0 ? (sd / avg) * 100 : 0;
            const max = n ? Math.max(...values) : 0;
            const min = n ? Math.min(...values) : 0;
            const fmt2 = v => (isFinite(v) ? v.toFixed(2) : '0.00');
            const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            const setInput = (id, val) => { const el = document.getElementById(id); if (el) el.value = val; };
            setText(`gsm_sum_avg_${suffixId}`, fmt2(avg));
            setText(`gsm_sum_sd_${suffixId}`, fmt2(sd));
            setText(`gsm_sum_cv_${suffixId}`, fmt2(cv));
            setText(`gsm_sum_max_${suffixId}`, fmt2(max));
            setText(`gsm_sum_min_${suffixId}`, fmt2(min));
            // Update hidden inputs
            setInput(`gsm_sum_avg_input_${suffixId}`, avg);
            setInput(`gsm_sum_sd_input_${suffixId}`, sd);
            setInput(`gsm_sum_cv_input_${suffixId}`, cv);
            setInput(`gsm_sum_max_input_${suffixId}`, max);
            setInput(`gsm_sum_min_input_${suffixId}`, min);
        }

        // Clear form function
        function clearForm() {
            // Uncheck all checkboxes
            document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                checkbox.checked = false;
            });
            
            // Reset selection count
            updateSelectionCount();
            
            // Clear any form validation messages
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => alert.remove());
            
            // Generate new sample reference ID
            updateSampleReferenceId();
            
            // Hide all test parameters
            document.querySelectorAll('.test-parameters').forEach(params => {
                params.style.display = 'none';
            });
        }

        // Test parameters data
        const testParameters = {
            'Thickness (Under 2kPa Pressure)': {
                'ASTM D5199': { type: 'table_thickness_grouped' },
                'ISO 9863-1': { type: 'table_thickness_grouped' }
            },
            'Mass Per Unit Area (GSM)': {
                'ASTM D5261': {
                    type: 'table_gsm',
                    columns: ['Position of Sampling', 'Weight(gm)'],
                    rows: 4,
                    groups: []
                },
                'ISO 9864': {
                    type: 'table_gsm',
                    columns: ['Position of Sampling', 'Weight(gm)'],
                    rows: 4,
                    groups: []
                }
            },
            'Strip Tensile Test': {
                'ASTM D4595': {
                    type: 'table_strip_grouped'
                },
                'ISO 10319': {
                    type: 'table_strip_grouped'
                }
            },
            'CBR Puncture Resistance': {
                'ASTM D6241': {
                    type: 'table_cbr_grouped'
                },
                'ISO 12236': {
                    type: 'table_cbr_grouped'
                }
            },
            'Grab Tensile Test': {
                'ASTM D4632': {
                    type: 'table_grab_grouped'
                }
            },
            'Weathering Exposure Test': {
				'ASTM D4533': {
                    type: 'no_fields'
				}
            },
            'Seam/Joint Test': {
                'ISO 10321': []
            }
        };

        // Validate that test end date is not earlier than test start date
        function validateYarnTestDates() {
            const startDateInput = document.getElementById('yarn_test_start_date');
            const endDateInput = document.getElementById('yarn_test_end_date');
            const errorDiv = document.getElementById('yarn_date_error');
            
            if (!startDateInput || !endDateInput || !errorDiv) return;
            
            const startDate = startDateInput.value;
            const endDate = endDateInput.value;
            
            if (startDate && endDate) {
                if (endDate < startDate) {
                    errorDiv.textContent = 'Test End Date must not be earlier than Test Start Date';
                    endDateInput.setCustomValidity('Test End Date must not be earlier than Test Start Date');
                } else {
                    errorDiv.textContent = '';
                    endDateInput.setCustomValidity('');
                }
            } else {
                errorDiv.textContent = '';
                endDateInput.setCustomValidity('');
            }
        }

        // Show general information based on test type
        function toggleGeneralInfoVisibility() {
            const generalInfoDiv = document.getElementById('general-info-section');
            const fiberInfoDiv = document.getElementById('fiber-info-section');
            const yarnInfoDiv = document.getElementById('yarn-info-section');
            const productRefSelect = document.getElementById('product_reference');
            const fiberRefSelect = document.getElementById('fiber_reference_no');
            const yarnRefSelect = document.getElementById('yarn_reference_no');
            
            if (!generalInfoDiv || !fiberInfoDiv || !yarnInfoDiv) return;
            
            const selected = document.querySelector('input[type="checkbox"]:checked');
            if (!selected) {
                generalInfoDiv.style.display = '';
                fiberInfoDiv.style.display = 'none';
                yarnInfoDiv.style.display = 'none';
                if (productRefSelect) productRefSelect.required = true;
                if (fiberRefSelect) fiberRefSelect.required = false;
                if (yarnRefSelect) yarnRefSelect.required = false;
                return;
            }
            
            const testName = selected.getAttribute('data-test-name');
            const showMainTests = [
                'Thickness (Under 2kPa Pressure)',
                'Mass Per Unit Area (GSM)',
                'Strip Tensile Test',
                'CBR Puncture Resistance',
                'Grab Tensile Test'
            ];
            
            if (showMainTests.includes(testName)) {
                generalInfoDiv.style.display = '';
                fiberInfoDiv.style.display = 'none';
                yarnInfoDiv.style.display = 'none';
                if (productRefSelect) productRefSelect.required = true;
                if (fiberRefSelect) fiberRefSelect.required = false;
                if (yarnRefSelect) yarnRefSelect.required = false;
            } else {
                // For other tests (Weathering, Seam/Joint), hide all
                generalInfoDiv.style.display = 'none';
                fiberInfoDiv.style.display = 'none';
                yarnInfoDiv.style.display = 'none';
                if (productRefSelect) productRefSelect.required = false;
                if (fiberRefSelect) fiberRefSelect.required = false;
                if (yarnRefSelect) yarnRefSelect.required = false;
            }
        }

        // Toggle Other Information visibility when GSM-only is selected
        function toggleOtherInfoForGsmOnly() {
            const row = document.getElementById('other-info-row');
            if (!row) return;
            const selected = document.querySelector('input[type="checkbox"]:checked');
            const isGsmOnly = !!selected && selected.getAttribute('data-test-name') === 'Mass Per Unit Area (GSM)';
            row.style.display = isGsmOnly ? 'none' : '';
        }

        // Handle test selection - only one test at a time
        function handleTestSelection(checkbox) {
            // CRITICAL CHECK: Prevent if checkbox is disabled or already submitted
            if (checkbox.disabled || checkbox.hasAttribute('data-already-submitted') || checkbox.readOnly) {
                console.warn('⚠️ BLOCKED: Attempted to select disabled/already-submitted test:', checkbox.getAttribute('data-test-name'), checkbox.getAttribute('data-method'));
                checkbox.checked = false;
                checkbox.disabled = true;
                checkbox.readOnly = true;
                
                const testName = checkbox.getAttribute('data-test-name') || 'Test';
                const method = checkbox.getAttribute('data-method') || 'Method';
                showToast('⚠️ This test method (' + testName + ' - ' + method + ') has already been submitted for the selected reference. Please select a different test method.', 'warning', 5000);
                
                // Hide any test parameters that might have been shown
                const testItem = checkbox.closest('.test-item');
                if (testItem) {
                    const params = testItem.querySelector('.test-parameters');
                    if (params) {
                        params.style.display = 'none';
                        params.querySelectorAll('input, textarea, select').forEach(input => {
                            input.disabled = true;
                            input.readOnly = true;
                        });
                    }
                }
                return false;
            }
            
            const isExternalProduct = document.getElementById('is_external_product')?.value === '1';
            
            // AGM should not see test parameters - only test selection
            <?php if ($is_admin): ?>
            // For AGM with external products: allow multiple selections
            if (checkbox.checked) {
                const selectedItem = checkbox.closest('.test-item');
                if (selectedItem) selectedItem.style.display = 'block';
            }
            updateSelectionCount();
            return; // Don't show test parameters for AGM
            <?php endif; ?>
            
            if (checkbox.checked) {
                // DOUBLE CHECK: Make sure it's not already submitted (in case it was checked before this function ran)
                if (checkbox.hasAttribute('data-already-submitted') || checkbox.disabled || checkbox.readOnly) {
                    checkbox.checked = false;
                    checkbox.disabled = true;
                    checkbox.readOnly = true;
                    return false;
                }
                
                // For production products, enforce single selection
                // For external products, allow multiple selections
                if (!isExternalProduct) {
                    // Uncheck all other checkboxes and hide their test items
                    document.querySelectorAll('.test-checkbox').forEach(cb => {
                        if (cb !== checkbox) {
                            cb.checked = false;
                            // Hide the entire test item (not just parameters)
                            const item = cb.closest('.test-item');
                            if (item) item.style.display = 'none';
                            const params = item?.querySelector('.test-parameters');
                            if (params) params.style.display = 'none';
                        }
                    });
                    // Show only the selected test item
                    const selectedItem = checkbox.closest('.test-item');
                    if (selectedItem) selectedItem.style.display = 'block';
                    
                    // Filter reference dropdowns based on selected test method
                    filterReferencesByTestMethod(checkbox);
                    
                    // Bundle detection removed - no longer needed
                } else {
                    // External product: allow multiple selections, show all selected items
                    const selectedItem = checkbox.closest('.test-item');
                    if (selectedItem) selectedItem.style.display = 'block';
                }
            } else {
                // If unchecking, show all test items again (for production)
                if (!isExternalProduct) {
                    document.querySelectorAll('.test-item').forEach(item => {
                        item.style.display = 'block';
                    });
                    // Reset reference dropdowns to show all
                    resetReferenceDropdowns();
                }
            }
            // Only show test parameters for testers, not AGM
            // Check again before showing parameters to prevent already-submitted tests
            console.log('About to show test parameters. Checkbox state:', {
                disabled: checkbox.disabled,
                hasDataAlreadySubmitted: checkbox.hasAttribute('data-already-submitted'),
                readOnly: checkbox.readOnly,
                checked: checkbox.checked
            });
            
            if (!checkbox.disabled && !checkbox.hasAttribute('data-already-submitted') && !checkbox.readOnly) {
                console.log('Calling showTestParameters for:', checkbox.getAttribute('data-test-name'), checkbox.getAttribute('data-method'));
                showTestParameters(checkbox);
            } else {
                console.warn('NOT showing test parameters because checkbox is disabled/already-submitted/readOnly');
                // If somehow the checkbox is checked but disabled, uncheck it and hide parameters
                checkbox.checked = false;
                const testItem = checkbox.closest('.test-item');
                if (testItem) {
                    const params = testItem.querySelector('.test-parameters');
                    if (params) params.style.display = 'none';
                }
            }
            toggleOtherInfoForGsmOnly();
            toggleGeneralInfoVisibility();
            updateSelectionCount();
            
            // Check reference-level test status if bulk range is selected
            if (checkbox.checked) {
                checkReferenceLevelTestStatus(checkbox);
            } else {
                // Hide status display when test is unchecked
                hideReferenceTestStatus();
            }
        }
        
        // Check reference-level test status for bulk range
        function checkReferenceLevelTestStatus(checkbox) {
            const fromRef = document.getElementById('from_reference')?.value;
            const toRef = document.getElementById('to_reference')?.value;
            const testName = checkbox.getAttribute('data-test-name');
            const method = checkbox.getAttribute('data-method');
            
            // Only check if bulk range is selected
            if (!fromRef || !toRef || !testName || !method) {
                hideReferenceTestStatus();
                return;
            }
            
            // Show loading state
            const container = document.getElementById('reference_test_status_container');
            const statusList = document.getElementById('reference_test_status_list');
            if (container && statusList) {
                container.style.display = 'block';
                statusList.innerHTML = '<div style="padding:20px; text-align:center; color:#666;"><i class="fas fa-spinner fa-spin"></i> Checking reference status...</div>';
            }
            
            // Fetch reference-level status
            const url = `?action=check_reference_test_status&from_reference=${encodeURIComponent(fromRef)}&to_reference=${encodeURIComponent(toRef)}&test_name=${encodeURIComponent(testName)}&method=${encodeURIComponent(method)}`;
            
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.references) {
                        displayReferenceTestStatus(data);
                    } else {
                        console.error('Error checking reference status:', data.error);
                        hideReferenceTestStatus();
                    }
                })
                .catch(error => {
                    console.error('Error fetching reference status:', error);
                    hideReferenceTestStatus();
                });
        }
        
        // Display reference-level test status
        function displayReferenceTestStatus(data) {
            const container = document.getElementById('reference_test_status_container');
            const statusList = document.getElementById('reference_test_status_list');
            const statusTitle = document.getElementById('reference_status_title');
            const statusSummary = document.getElementById('reference_status_summary');
            
            if (!container || !statusList) return;
            
            // Update title
            if (statusTitle) {
                statusTitle.textContent = `${data.test_name} (${data.method}) - Reference Status`;
            }
            
            // Build status list
            let html = '';
            let pendingCount = 0;
            let submittedCount = 0;
            
            data.references.forEach(ref => {
                if (ref.status === 'submitted') {
                    submittedCount++;
                    const dateStr = ref.submitted_date ? new Date(ref.submitted_date).toLocaleDateString('en-US', { year: 'numeric', month: '2-digit', day: '2-digit' }) : 'N/A';
                    html += `
                        <div style="padding:12px; margin-bottom:8px; background:#d4edda; border-left:4px solid #28a745; border-radius:4px;">
                            <strong style="color:#155724;">${ref.reference}</strong>
                            <div style="font-size:12px; color:#6c757d; margin-top:4px;">
                                <i class="fas fa-check-circle" style="color:#28a745;"></i> Already Submitted (${dateStr})
                            </div>
                            ${ref.report_number ? `<div style="font-size:11px; color:#6c757d; margin-top:2px;">Report: ${ref.report_number}</div>` : ''}
                        </div>
                    `;
                } else {
                    pendingCount++;
                    html += `
                        <div style="padding:12px; margin-bottom:8px; background:#fff3cd; border-left:4px solid #ffc107; border-radius:4px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                                <div style="flex:1;">
                                    <strong style="color:#856404;">${ref.reference}</strong>
                                    <div style="font-size:12px; color:#856404; margin-top:4px;">
                                        <i class="fas fa-clock" style="color:#ffc107;"></i> Pending - Not Yet Submitted
                                    </div>
                                </div>
                                <div style="font-size:12px; color:#856404;">
                                    <span style="padding:4px 8px; background:#ffc107; color:#856404; border-radius:4px; font-size:11px; font-weight:600;">
                                        Will Submit
                                    </span>
                                </div>
                            </div>
                        </div>
                    `;
                }
            });
            
            statusList.innerHTML = html;
            
            // Update summary
            if (statusSummary) {
                const total = data.references.length;
                statusSummary.innerHTML = `
                    <div style="display:flex; align-items:center; gap:15px; flex-wrap:wrap;">
                        <div><strong>Total References:</strong> ${total}</div>
                        <div style="color:#28a745;"><strong>Submitted:</strong> ${submittedCount}</div>
                        <div style="color:#ffc107;"><strong>Pending:</strong> ${pendingCount}</div>
                        ${pendingCount > 0 ? '<div style="color:#2196F3;"><strong>Action:</strong> Will submit for ${pendingCount} pending reference(s) only</div>' : '<div style="color:#dc3545;"><strong>Note:</strong> All references already have this test submitted</div>'}
                    </div>
                `;
            }
        }
        
        // Hide reference test status display
        function hideReferenceTestStatus() {
            const container = document.getElementById('reference_test_status_container');
            if (container) {
                container.style.display = 'none';
            }
        }
        
        // View report function
        function viewReport(reportId) {
            if (reportId) {
                window.open(`?edit=${reportId}`, '_blank');
            }
        }
        
        // Show reference-level status for ALL tests when Apply is clicked
        function showAllTestsReferenceStatus(fromRef, toRef) {
            if (!fromRef || !toRef) {
                hideReferenceTestStatus();
                return;
            }
            
            console.log('showAllTestsReferenceStatus called with:', fromRef, toRef);
            
            const container = document.getElementById('reference_test_status_container');
            const statusList = document.getElementById('reference_test_status_list');
            const statusTitle = document.getElementById('reference_status_title');
            const statusSummary = document.getElementById('reference_status_summary');
            
            if (!container || !statusList) {
                console.error('Reference status container elements not found!');
                return;
            }
            
            // Show container with loading state
            container.style.display = 'block';
            if (statusTitle) statusTitle.textContent = 'Reference-Level Test Status';
            statusList.innerHTML = '<div style="padding:40px; text-align:center; color:#666;"><i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><div style="margin-top:10px;">Loading test status for all references...</div></div>';
            if (statusSummary) statusSummary.innerHTML = '';
            
            // Wait a bit to ensure DOM is ready, then get all test checkboxes
            setTimeout(() => {
                let testsToCheck = [];
                const seenKeys = new Set();
                
                // First, try to use the PHP-generated test list (most reliable)
                if (window.AVAILABLE_TESTS && window.AVAILABLE_TESTS.length > 0) {
                    console.log('Using PHP-generated test list:', window.AVAILABLE_TESTS.length);
                    window.AVAILABLE_TESTS.forEach(test => {
                        const key = `${test.testName}|||${test.method}`;
                        if (!seenKeys.has(key)) {
                            seenKeys.add(key);
                            testsToCheck.push({
                                testName: test.testName,
                                method: test.method,
                                key: key
                            });
                        }
                    });
                } else {
                    // Fallback: Try to find tests from DOM
                    console.log('PHP test list not available, searching DOM...');
                    let allTestCheckboxes = document.querySelectorAll('.test-checkbox');
                    console.log('Found checkboxes:', allTestCheckboxes.length);
                    
                    // If no checkboxes found, try finding test items
                    if (allTestCheckboxes.length === 0) {
                        const testItems = document.querySelectorAll('.test-item');
                        console.log('Found test items:', testItems.length);
                        testItems.forEach(item => {
                            const checkboxes = item.querySelectorAll('input[type="checkbox"][data-test-name]');
                            allTestCheckboxes = Array.from(allTestCheckboxes).concat(Array.from(checkboxes));
                        });
                    }
                    
                    allTestCheckboxes.forEach(cb => {
                        const testName = cb.getAttribute('data-test-name');
                        const method = cb.getAttribute('data-method');
                        if (testName && method) {
                            const key = `${testName}|||${method}`;
                            if (!seenKeys.has(key)) {
                                seenKeys.add(key);
                                testsToCheck.push({
                                    testName: testName,
                                    method: method,
                                    key: key
                                });
                            }
                        }
                    });
                }
                
                console.log('Tests to check:', testsToCheck.length, testsToCheck);
                
                if (testsToCheck.length === 0) {
                    statusList.innerHTML = '<div style="padding:20px; text-align:center; color:#999;"><i class="fas fa-exclamation-triangle"></i> No tests found. Please ensure test checkboxes are visible on the page.</div>';
                    return;
                }
                
                // Check status for all tests
                let completedChecks = 0;
                const allResults = [];
                
                testsToCheck.forEach((test, index) => {
                    const url = `?action=check_reference_test_status&from_reference=${encodeURIComponent(fromRef)}&to_reference=${encodeURIComponent(toRef)}&test_name=${encodeURIComponent(test.testName)}&method=${encodeURIComponent(test.method)}`;
                    console.log(`Checking test ${index + 1}/${testsToCheck.length}:`, test.testName, test.method);
                    
                    fetch(url)
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(`HTTP error! status: ${response.status}`);
                            }
                            return response.json();
                        })
                        .then(data => {
                            console.log(`Result for ${test.testName} (${test.method}):`, data);
                            if (data.success && data.references) {
                                // Always add results, even if empty (to show pending status)
                                allResults.push({
                                    testName: test.testName,
                                    method: test.method,
                                    references: data.references
                                });
                                console.log(`Added ${data.references.length} references for ${test.testName}`);
                            } else {
                                console.error(`API error for ${test.testName}:`, data.error || 'Unknown error', data);
                            }
                            
                            completedChecks++;
                            if (completedChecks === testsToCheck.length) {
                                console.log('All checks completed. Total results:', allResults.length, allResults);
                                displayAllTestsReferenceStatus(allResults, fromRef, toRef);
                            }
                        })
                        .catch(error => {
                            console.error(`Fetch error for ${test.testName}:`, error);
                            completedChecks++;
                            if (completedChecks === testsToCheck.length) {
                                console.log('All checks completed (with errors). Total results:', allResults.length);
                                displayAllTestsReferenceStatus(allResults, fromRef, toRef);
                            }
                        });
                });
            }, 100); // Small delay to ensure DOM is ready
        }
        
        // Display all tests reference status in modern UI
        function displayAllTestsReferenceStatus(allResults, fromRef, toRef) {
            const statusList = document.getElementById('reference_test_status_list');
            const statusSummary = document.getElementById('reference_status_summary');
            
            if (!statusList) {
                console.error('Status list element not found!');
                return;
            }
            
            console.log('Displaying results:', allResults.length, allResults);
            
            if (allResults.length === 0) {
                statusList.innerHTML = `
                    <div style="padding:30px; text-align:center; color:#999;">
                        <i class="fas fa-exclamation-triangle" style="font-size:32px; color:#ffc107; margin-bottom:15px;"></i>
                        <div style="font-size:16px; font-weight:600; margin-bottom:10px;">No test data available</div>
                        <div style="font-size:14px; color:#666;">
                            This may mean:<br>
                            • No tests have been checked yet<br>
                            • There was an error fetching the data<br>
                            • Please check the browser console (F12) for details
                        </div>
                        <div style="margin-top:15px; font-size:12px; color:#999;">
                            From: ${fromRef}<br>
                            To: ${toRef}
                        </div>
                    </div>
                `;
                if (statusSummary) statusSummary.innerHTML = '';
                return;
            }
            
            // Filter to only show tests that have at least one submitted reference
            const filteredResults = allResults.filter(testResult => {
                return testResult.references && testResult.references.some(ref => ref.status === 'submitted');
            });
            
            console.log('Filtered results (only tests with submitted references):', filteredResults.length, filteredResults);
            
            if (filteredResults.length === 0) {
                statusList.innerHTML = `
                    <div style="padding:30px; text-align:center; color:#999;">
                        <i class="fas fa-info-circle" style="font-size:32px; color:#2196F3; margin-bottom:15px;"></i>
                        <div style="font-size:16px; font-weight:600; margin-bottom:10px;">No Submitted Tests Found</div>
                        <div style="font-size:14px; color:#666;">
                            No tests have been submitted for the selected reference range yet.<br>
                            All tests are pending submission.
                        </div>
                        <div style="margin-top:15px; font-size:12px; color:#999;">
                            From: ${fromRef}<br>
                            To: ${toRef}
                        </div>
                    </div>
                `;
                if (statusSummary) statusSummary.innerHTML = '';
                return;
            }
            
            let html = '';
            let totalPending = 0;
            let totalSubmitted = 0;
            
            filteredResults.forEach((testResult, testIndex) => {
                const { testName, method, references } = testResult;
                let testPending = 0;
                let testSubmitted = 0;
                
                // Test header
                html += `
                    <div style="margin-bottom:25px; border:1px solid #e0e0e0; border-radius:8px; overflow:hidden; background:#fff;">
                        <div style="padding:15px; background:linear-gradient(135deg, #667eea 0%, #764ba2 100%); color:white;">
                            <div style="display:flex; align-items:center; gap:10px;">
                                <i class="fas fa-flask" style="font-size:18px;"></i>
                                <strong style="font-size:16px;">${testName}</strong>
                                <span style="font-size:14px; opacity:0.9;">(${method})</span>
                            </div>
                        </div>
                        <div style="padding:15px;">
                `;
                
                // Reference statuses
                references.forEach(ref => {
                    if (ref.status === 'submitted') {
                        testSubmitted++;
                        totalSubmitted++;
                        const dateStr = ref.submitted_date ? new Date(ref.submitted_date).toLocaleDateString('en-US', { year: 'numeric', month: '2-digit', day: '2-digit' }) : 'N/A';
                        html += `
                            <div style="padding:15px; margin-bottom:10px; background:#f0f9ff; border-left:4px solid #28a745; border-radius:6px;">
                                <div style="display:flex; align-items:center; gap:10px; margin-bottom:5px;">
                                    <i class="fas fa-check-circle" style="color:#28a745; font-size:18px;"></i>
                                    <strong style="color:#155724; font-size:15px;">${ref.reference}</strong>
                                </div>
                                <div style="color:#6c757d; font-size:13px; margin-left:28px;">
                                    <span style="color:#28a745; font-weight:600;">✓ Already Submitted</span> (${dateStr})
                                    ${ref.report_number ? ` • Report: ${ref.report_number}` : ''}
                                </div>
                            </div>
                        `;
                    } else {
                        testPending++;
                        totalPending++;
                        html += `
                            <div style="padding:15px; margin-bottom:10px; background:#fffbf0; border-left:4px solid #ffc107; border-radius:6px; display:flex; align-items:center; justify-content:space-between; gap:15px;">
                                <div style="flex:1;">
                                    <div style="display:flex; align-items:center; gap:10px; margin-bottom:5px;">
                                        <i class="fas fa-clock" style="color:#ffc107; font-size:18px;"></i>
                                        <strong style="color:#856404; font-size:15px;">${ref.reference}</strong>
                                    </div>
                                    <div style="color:#856404; font-size:13px; margin-left:28px;">
                                        <span style="color:#ffc107; font-weight:600;">⏳ Pending</span> - Not Yet Submitted
                                    </div>
                                </div>
                                <div>
                                    <span style="padding:8px 16px; background:#ffc107; color:#856404; border-radius:6px; font-size:13px; font-weight:600;">
                                        ✅ Will Submit
                                    </span>
                                </div>
                            </div>
                        `;
                    }
                });
                
                // Test summary
                html += `
                        </div>
                        <div style="padding:10px 15px; background:#f8f9fa; border-top:1px solid #e0e0e0; font-size:13px; color:#495057;">
                            <strong>Summary:</strong> ${testSubmitted} submitted, ${testPending} pending
                        </div>
                    </div>
                `;
            });
            
            statusList.innerHTML = html;
            
            // Overall summary
            if (statusSummary) {
                const totalRefs = filteredResults[0]?.references?.length || 0;
                statusSummary.innerHTML = `
                    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:15px;">
                        <div><strong style="color:#495057;">Tests with Submitted References:</strong> <span style="color:#2196F3; font-weight:600;">${filteredResults.length}</span></div>
                        <div><strong style="color:#495057;">Total References:</strong> <span style="color:#2196F3; font-weight:600;">${totalRefs}</span></div>
                        <div><strong style="color:#28a745;">Submitted:</strong> <span style="color:#28a745; font-weight:600;">${totalSubmitted}</span></div>
                        <div><strong style="color:#ffc107;">Pending:</strong> <span style="color:#ffc107; font-weight:600;">${totalPending}</span></div>
                        ${totalPending > 0 ? '<div style="color:#2196F3; font-weight:600;"><i class="fas fa-info-circle"></i> Only pending references will be submitted</div>' : '<div style="color:#dc3545; font-weight:600;"><i class="fas fa-exclamation-triangle"></i> All references already have tests submitted</div>'}
                    </div>
                `;
            }
        }
        
        // Existing tests data (reference => [methods])
        const existingTests = <?php echo json_encode($existing_tests); ?>;
        
        // Modern Toast Notification System
        function showToast(message, type = 'info', duration = 5000) {
            const container = document.getElementById('toastContainer');
            if (!container) return;
            
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            const icons = {
                error: 'fas fa-exclamation-circle',
                warning: 'fas fa-exclamation-triangle',
                success: 'fas fa-check-circle',
                info: 'fas fa-info-circle'
            };
            
            const titles = {
                error: 'Error',
                warning: 'Warning',
                success: 'Success',
                info: 'Information'
            };
            
            toast.innerHTML = `
                <i class="${icons[type] || icons.info} toast-icon"></i>
                <div class="toast-content">
                    <div class="toast-title">${titles[type] || 'Information'}</div>
                    <div class="toast-message">${message}</div>
                </div>
                <button class="toast-close" onclick="this.parentElement.remove()" aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            container.appendChild(toast);
            
            // Auto-remove after duration
            setTimeout(() => {
                toast.classList.add('hiding');
                setTimeout(() => toast.remove(), 300);
            }, duration);
            
            return toast;
        }
        
        // Filter reference dropdowns to hide already-submitted references for the selected test method
        function filterReferencesByTestMethod(checkbox) {
            const testName = checkbox.getAttribute('data-test-name');
            const method = checkbox.getAttribute('data-method');
            
            // Get references that have already been submitted for this test method
            const usedRefs = [];
            for (const [ref, methods] of Object.entries(existingTests)) {
                if (methods.includes(method)) {
                    usedRefs.push(ref);
                }
            }
            
            console.log('Filtering references for method:', method, 'Used refs:', usedRefs);
                        
                        // Filter product_reference dropdown
                        const productRefSelect = document.getElementById('product_reference');
                        if (productRefSelect) {
                const currentValue = productRefSelect.value;
                            Array.from(productRefSelect.options).forEach(option => {
                                if (option.value && usedRefs.includes(option.value)) {
                                    option.style.display = 'none';
                                    option.disabled = true;
                                } else {
                                    option.style.display = '';
                                    option.disabled = false;
                                }
                            });
                // Clear selection if currently selected reference is in the used list
                if (currentValue && usedRefs.includes(currentValue)) {
                    productRefSelect.value = '';
                    // Also clear related fields
                    document.getElementById('qc_batch_info').value = '';
                    // Show warning message
                    const errorDiv = document.getElementById('qc_reference_error');
                    if (errorDiv) {
                        errorDiv.textContent = `⚠️ Reference "${currentValue}" already has this test (${method}) submitted. Please select a different reference.`;
                        errorDiv.style.color = '#856404';
                        errorDiv.style.background = '#fff3cd';
                        errorDiv.style.padding = '10px';
                        errorDiv.style.borderRadius = '4px';
                        errorDiv.style.marginTop = '5px';
                    }
                }
                        }
                        
                        // Filter fiber_reference_no dropdown
                        const fiberRefSelect = document.getElementById('fiber_reference_no');
                        if (fiberRefSelect) {
                const currentValue = fiberRefSelect.value;
                            Array.from(fiberRefSelect.options).forEach(option => {
                                if (option.value && usedRefs.includes(option.value)) {
                                    option.style.display = 'none';
                                    option.disabled = true;
                                } else {
                                    option.style.display = '';
                                    option.disabled = false;
                                }
                            });
                // Clear selection if currently selected reference is in the used list
                if (currentValue && usedRefs.includes(currentValue)) {
                    fiberRefSelect.value = '';
                    // Show warning message
                    const errorDiv = document.getElementById('fiber_reference_error');
                    if (errorDiv) {
                        errorDiv.textContent = `⚠️ Reference "${currentValue}" already has this test (${method}) submitted. Please select a different reference.`;
                        errorDiv.style.color = '#856404';
                        errorDiv.style.background = '#fff3cd';
                        errorDiv.style.padding = '10px';
                        errorDiv.style.borderRadius = '4px';
                        errorDiv.style.marginTop = '5px';
                    }
                }
                        }
                        
                        // Filter yarn_reference_no dropdown
                        const yarnRefSelect = document.getElementById('yarn_reference_no');
                        if (yarnRefSelect) {
                const currentValue = yarnRefSelect.value;
                            Array.from(yarnRefSelect.options).forEach(option => {
                                if (option.value && usedRefs.includes(option.value)) {
                                    option.style.display = 'none';
                                    option.disabled = true;
                                } else {
                                    option.style.display = '';
                                    option.disabled = false;
                                }
                            });
                // Clear selection if currently selected reference is in the used list
                if (currentValue && usedRefs.includes(currentValue)) {
                    yarnRefSelect.value = '';
                    // Show warning message
                    const errorDiv = document.getElementById('yarn_reference_error');
                    if (errorDiv) {
                        errorDiv.textContent = `⚠️ Reference "${currentValue}" already has this test (${method}) submitted. Please select a different reference.`;
                        errorDiv.style.color = '#856404';
                        errorDiv.style.background = '#fff3cd';
                        errorDiv.style.padding = '10px';
                        errorDiv.style.borderRadius = '4px';
                        errorDiv.style.marginTop = '5px';
                    }
                }
            }
        }
        
        // Reset reference dropdowns to show all options
        function resetReferenceDropdowns() {
            const productRefSelect = document.getElementById('product_reference');
            if (productRefSelect) {
                Array.from(productRefSelect.options).forEach(option => {
                    option.style.display = '';
                    option.disabled = false;
                });
            }
            
            const fiberRefSelect = document.getElementById('fiber_reference_no');
            if (fiberRefSelect) {
                Array.from(fiberRefSelect.options).forEach(option => {
                    option.style.display = '';
                    option.disabled = false;
                });
            }
            
            const yarnRefSelect = document.getElementById('yarn_reference_no');
            if (yarnRefSelect) {
                Array.from(yarnRefSelect.options).forEach(option => {
                    option.style.display = '';
                    option.disabled = false;
                });
            }
            
            // Clear all error messages
            const errorDivs = ['qc_reference_error', 'fiber_reference_error', 'yarn_reference_error'];
            errorDivs.forEach(divId => {
                const errorDiv = document.getElementById(divId);
                if (errorDiv) {
                    errorDiv.textContent = '';
                    errorDiv.style.background = '';
                    errorDiv.style.padding = '';
                }
            });
        }
        
        // Show test parameters when checkbox is checked (only for testers, not AGM)
        function showTestParameters(checkbox) {
            // AGM should not see test parameters - they only select tests
            <?php if ($is_admin): ?>
            return;
            <?php endif; ?>
            
            // CRITICAL: Prevent showing parameters for disabled/already-submitted tests
            if (checkbox.disabled || checkbox.hasAttribute('data-already-submitted') || checkbox.readOnly) {
                console.warn('⚠️ BLOCKED: Attempted to show parameters for disabled/already-submitted test');
                checkbox.checked = false;
                const testItem = checkbox.closest('.test-item');
                if (testItem) {
                    const params = testItem.querySelector('.test-parameters');
                    if (params) params.style.display = 'none';
                }
                return;
            }
            
            console.log('showTestParameters called');
            const testName = checkbox.getAttribute('data-test-name');
            const method = checkbox.getAttribute('data-method');
            
            console.log('Test name:', testName, 'Method:', method);
            
            
            // Find the correct params div using a simpler approach
            const testItem = checkbox.closest('.test-item');
            if (!testItem) {
                console.error('Test item not found for checkbox');
                return;
            }
            const paramsDiv = testItem.querySelector('.test-parameters');
            if (!paramsDiv) {
                console.error('Params div not found for test:', testName);
                return; // No params div for AGM
            }
            
            // Find paramsContent div - PHP generates it with md5 hash of test name
            // The structure is: <div class="test-parameters" id="params_<md5>">
            //                    <div id="params_content_<md5>"></div>
            // Find the first div inside paramsDiv (which should be params_content)
            let paramsContent = paramsDiv.querySelector('div[id^="params_content"]') || 
                                paramsDiv.querySelector('div:first-child') ||
                                paramsDiv.querySelector('div');
            
            // If still not found, use paramsDiv itself (fallback)
            if (!paramsContent || paramsContent === paramsDiv) {
                // Look for any child element
                paramsContent = paramsDiv.firstElementChild || paramsDiv;
            }
            
            console.log('Params div found:', !!paramsDiv, 'Params content found:', !!paramsContent);
            console.log('Params div ID:', paramsDiv.id);
            if (paramsContent) {
                console.log('Params content ID:', paramsContent.id, 'Tag:', paramsContent.tagName);
            }
            
            if (checkbox.checked) {
                // Show parameters for this test
                console.log('Checking testParameters:', testParameters);
                console.log('testParameters[testName]:', testParameters[testName]);
                console.log('testParameters[testName][method]:', testParameters[testName]?.[method]);
                
                if (testParameters[testName] && testParameters[testName][method]) {
                    const testConfig = testParameters[testName][method];
                    console.log('Test config found:', testConfig);
                    
                    if (testConfig.type === 'table') {
                        // Generate table for thickness test
                        // Use 'astmd5199' as the common prefix for both ASTM D5199 and ISO 9863-1
                        const fieldPrefix = 'astmd5199';
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                              <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Position of Sampling</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Under 2kPa(mm)</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Average(mm)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;
                        
                        // Generate initial 4 rows with dropdown - show all position options
                        const allPositionOptions = [
                            'Left-1', 'Left-2', 'Left-3', 'Left-4',
                            'Middle Left-1', 'Middle Left-2', 'Middle Left-3', 'Middle Left-4',
                            'Middle Right-1', 'Middle Right-2', 'Middle Right-3', 'Middle Right-4',
                            'Right-1', 'Right-2', 'Right-3', 'Right-4'
                        ];
                        for (let i = 1; i <= 4; i++) {
                            // All rows show all position options
                            const optionsHtml = allPositionOptions.map(opt => `<option value="${opt}">${opt}</option>`).join('');
                            
                            html += `
                                <tr class=\"gsm-row\">
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <select class="posSelect" name="${fieldPrefix}_position_${i}" id="${fieldPrefix}_position_${i}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" onchange="updateThicknessDropdowns(); calculateThicknessAverages()">
                                            <option value="">-- Select Position --</option>
                                            ${optionsHtml}
                                        </select>
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="number" 
                                               class="under2kpa"
                                               name="${fieldPrefix}_under2kpa_${i}"
                                               step="any" max="1000" 
                                               min="0"
                                               
                                               style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;"
                                               onchange="calculateThicknessAverages()"
                                               oninput="if(this.value < 0) this.value = 0">
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa;">
                                        <span class="avgCell" style="font-weight: bold; color: #27ae60;">0.000</span>
                                    </td>
                                </tr>
                                ${i < 4 ? `
                                <tr>
                                    <td colspan="3" style="border: none; padding: 5px; text-align: center; background: #f8f9fa;">
                                        <button type="button" 
                                                onclick="addSingleThicknessRow(${i})"
                                                style="padding: 5px 12px; background:rgb(21, 75, 14); color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                            <i class="fa fa-plus"></i> Add 1 More Row
                                        </button>
                                    </td>
                                </tr>
                                ` : ''}
                            `;
                        }
                        
                        html += `
                                    </tbody>
                                </table>
                </div>
                                <!-- Add Row Button -->
                                <div style="margin: 15px 0; text-align: center;">
                                    <button type="button" 
                                            class="btn btn-sm btn-primary" 
                                            onclick="addSingleThicknessRow(4)"
                                            style="padding: 5px 12px; background:rgb(21, 75, 14); color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                        <i class="fa fa-plus"></i> Add 1 More Row
                                    </button>
                                </div>
                                        
                                <!-- Hidden fields for statistics -->
                                <input type="hidden" name="${fieldPrefix}_avg" id="${fieldPrefix}_avg">
                                <input type="hidden" name="${fieldPrefix}_sd" id="${fieldPrefix}_sd">
                                <input type="hidden" name="${fieldPrefix}_cv" id="${fieldPrefix}_cv">
                                <input type="hidden" name="${fieldPrefix}_max" id="${fieldPrefix}_max">
                                <input type="hidden" name="${fieldPrefix}_min" id="${fieldPrefix}_min">

                                <!-- Thickness Summary -->
                                <div style="margin-top: 20px;">
                                    <h3 style="text-align: center; margin-bottom: 15px;">Summary of Thickness (Under 2kPa)</h3>
                                    <div style="overflow-x: auto;">
                                        <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                                            <thead>
                                                <tr style="background: #1976d2; color: #fff;">
                                                    <th style="border: 1px solid #ddd; padding: 10px; text-align: center;">Statistics</th>
                                                    <th style="border: 1px solid #ddd; padding: 10px; text-align: center;">Under 2kPa (mm)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">Average:</td>
                                                    <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                                                        <input type="text" id="thk_summary_avg" readonly class="readonly" style="width: 100%; padding: 5px; border: none; background: #f8f9fa; text-align: center;">
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">SD:</td>
                                                    <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                                                        <input type="text" id="thk_summary_sd" readonly class="readonly" style="width: 100%; padding: 5px; border: none; background: #f8f9fa; text-align: center;">
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">CV%:</td>
                                                    <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                                                        <input type="text" id="thk_summary_cv" readonly class="readonly" style="width: 100%; padding: 5px; border: none; background: #f8f9fa; text-align: center;">
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">Maximum:</td>
                                                    <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                                                        <input type="text" id="thk_summary_max" readonly class="readonly" style="width: 100%; padding: 5px; border: none; background: #f8f9fa; text-align: center;">
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">Minimum:</td>
                                                    <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                                                        <input type="text" id="thk_summary_min" readonly class="readonly" style="width: 100%; padding: 5px; border: none; background: #f8f9fa; text-align: center;">
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                        
                        // Initialize dropdowns for first 4 rows
                        updateThicknessDropdowns();
                    } else if (testConfig.type === 'table_gsm') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div class=\"gsm-card\" style=\"margin-bottom: 20px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; box-shadow: 0 2px 8px rgba(0,0,0,0.05);\"> 
                              <style>
                                #gsmTable_${suffixId} { 
                                    width: 100%; 
                                    min-width: 780px;
                                    border-collapse: separate; 
                                    border-spacing: 0; 
                                }
                                #gsmTable_${suffixId} thead th {
                                    background: #2563eb;
                                    color: #ffffff;
                                    font-weight: 700;
                                    padding: 8px 10px;
                                    border-bottom: 0;
                                    text-align: center;
                                    letter-spacing: 0.2px;
                                }
                                #gsmTable_${suffixId} td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
                                #gsmTable_${suffixId} tr:last-child td { border-bottom: none; }
                                #gsmTable_${suffixId} select,
                                #gsmTable_${suffixId} input[type=number] { 
                                    width: 100%; 
                                    min-width: 165px;
                                    padding: 6px 10px; 
                                    border: 1px solid #e5e7eb; 
                                    border-radius: 8px; 
                                    background: #ffffff; 
                                    box-sizing: border-box;
                                }
                                #gsmTable_${suffixId} .avg-cell { text-align: center; }
                                #gsmTable_${suffixId} .calc-cell { text-align: center; }
                                #gsmTable_${suffixId} .avg-badge { display: inline-block; min-width: 64px; padding: 6px 12px; border-radius: 999px; background: #22c55e1a; color: #16a34a; font-weight: 700; border: 1px solid #86efac; }
                                #gsmTable_${suffixId} .group-header { background: #f1f5f9; color: #0f172a; font-weight: 700; text-align: left; border-left: 4px solid #3b82f6; padding: 8px 10px; }
                                #gsmTable_${suffixId} .btn-add {
                                    background: linear-gradient(90deg, #16a34a 0%, #22c55e 100%);
                                    color: #ffffff;
                                    border: none;
                                    padding: 6px 10px;
                                    border-radius: 6px;
                                    font-size: 12px;
                                    cursor: pointer;
                                    box-shadow: 0 3px 8px rgba(34, 197, 94, 0.2);
                                    transition: transform .08s ease, box-shadow .2s ease, filter .2s ease;
                                }
                                #gsmTable_${suffixId} .btn-add:hover { filter: brightness(1.05); box-shadow: 0 4px 10px rgba(34, 197, 94, 0.28); }
                                #gsmTable_${suffixId} .btn-add:active { transform: translateY(1px); }
                              </style>
                              <div style=\"overflow-x: auto;\"> 
                                <table id=\"gsmTable_${suffixId}\"> 
                                    <thead> 
                                        <tr> 
                                            <th>Position of Sampling</th> 
                                            <th>Weight (gm)</th> 
                                            <th>Calculated (GSM)</th> 
                                            <th>Average (GSM)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody id=\"gsmBody_${suffixId}\"></tbody> 
                                </table> 
                              </div> 
                              <div class=\"product-header\" style=\"margin-top: 16px; text-align: center;\">Summary of Test Result</div>
                              <div style=\"margin-top: 8px;\"> 
                                <table style=\"width: 100%; border-collapse: collapse;\"> 
                                    <thead> 
                                        <tr style=\"background: #d1fae5;\"> 
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: left;\">Statistics</th> 
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">Mass Per Unit Area (GSM)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">Average</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"gsm_sum_avg_${suffixId}\">0.00</span>
                                                <input type=\"hidden\" name=\"gsm_avg\" id=\"gsm_sum_avg_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">SD</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"gsm_sum_sd_${suffixId}\">0.00</span>
                                                <input type=\"hidden\" name=\"gsm_sd\" id=\"gsm_sum_sd_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">CV%</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"gsm_sum_cv_${suffixId}\">0.00</span>
                                                <input type=\"hidden\" name=\"gsm_cv\" id=\"gsm_sum_cv_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">Maximum</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"gsm_sum_max_${suffixId}\">0.00</span>
                                                <input type=\"hidden\" name=\"gsm_max\" id=\"gsm_sum_max_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">Minimum</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"gsm_sum_min_${suffixId}\">0.00</span>
                                                <input type=\"hidden\" name=\"gsm_min\" id=\"gsm_sum_min_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                    </tbody> 
                                </table> 
                              </div> 
                            </div> 
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                        console.log('GSM table HTML set, paramsDiv display:', paramsDiv.style.display);
                        
                        // Force display in case it was hidden
                        paramsDiv.style.display = 'block';
                        paramsDiv.style.visibility = 'visible';
                        
                        if (typeof initGsmGrouped === 'function') {
                            console.log('Calling initGsmGrouped with suffixId:', suffixId);
                            initGsmGrouped(suffixId);
                        } else {
                            console.warn('initGsmGrouped function not found!');
                        }
                        if (typeof applyLastGeneralInfo === 'function') { 
                            applyLastGeneralInfo(suffixId); 
                        }
                    } else if (testConfig.type === 'table_thickness_grouped') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div class=\"gsm-card\" style=\"margin-bottom: 20px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; box-shadow: 0 2px 8px rgba(0,0,0,0.05);\"> 
                              <style>
                                #thkTable_${suffixId} { width: 100%; border-collapse: separate; border-spacing: 0; }
                                #thkTable_${suffixId} thead th {
                                    background: #2563eb;
                                    color: #ffffff;
                                    font-weight: 700;
                                    padding: 8px 10px;
                                    border-bottom: 0;
                                    text-align: center;
                                    letter-spacing: 0.2px;
                                }
                                #thkTable_${suffixId} td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
                                #thkTable_${suffixId} tr:last-child td { border-bottom: none; }
                                #thkTable_${suffixId} input[type=number] { width: 100%; padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 8px; background: #ffffff; }
                                #thkTable_${suffixId} .avg-cell { text-align: center; }
                                #thkTable_${suffixId} .avg-badge { display: inline-block; min-width: 64px; padding: 6px 12px; border-radius: 999px; background: #22c55e1a; color: #16a34a; font-weight: 700; border: 1px solid #86efac; }
                                #thkTable_${suffixId} .btn-add { background: linear-gradient(90deg, #16a34a 0%, #22c55e 100%); color: #ffffff; border: none; padding: 6px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; box-shadow: 0 3px 8px rgba(34, 197, 94, 0.2); transition: transform .08s ease, box-shadow .2s ease, filter .2s ease; }
                                #thkTable_${suffixId} .btn-add:hover { filter: brightness(1.05); box-shadow: 0 4px 10px rgba(34, 197, 94, 0.28); }
                                #thkTable_${suffixId} .btn-add:active { transform: translateY(1px); }
                              </style>
                              <div style=\"overflow-x: auto;\"> 
                                <table id=\"thkTable_${suffixId}\"> 
                                    <thead> 
                                        <tr> 
                                            <th>Position of Sampling</th> 
                                            <th>Under 2kPa (mm)</th> 
                                            <th>Average (GSM)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody id=\"thkBody_${suffixId}\"></tbody> 
                                </table> 
                              </div> 
                              <div class=\"product-header\" style=\"margin-top: 16px; text-align: center;\">Summary of Test Result</div>
                              <div style=\"margin-top: 8px;\"> 
                                <table style=\"width: 100%; border-collapse: collapse;\"> 
                                    <thead> 
                                        <tr style=\"background: #d1fae5;\"> 
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: left;\">Statistics</th> 
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">Thickness Test Under 2kPa (mm)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">Average</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"thk_sum_avg_${suffixId}\">0.000</span>
                                                <input type=\"hidden\" name=\"astmd5199_avg\" id=\"thk_sum_avg_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">SD</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"thk_sum_sd_${suffixId}\">0.000</span>
                                                <input type=\"hidden\" name=\"astmd5199_sd\" id=\"thk_sum_sd_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">CV%</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"thk_sum_cv_${suffixId}\">0.00</span>
                                                <input type=\"hidden\" name=\"astmd5199_cv\" id=\"thk_sum_cv_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">Maximum</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"thk_sum_max_${suffixId}\">0.000</span>
                                                <input type=\"hidden\" name=\"astmd5199_max\" id=\"thk_sum_max_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">Minimum</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"thk_sum_min_${suffixId}\">0.000</span>
                                                <input type=\"hidden\" name=\"astmd5199_min\" id=\"thk_sum_min_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                    </tbody> 
                                </table> 
                              </div>
                            </div> 
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                        if (typeof initThicknessGrouped === 'function') {
                            initThicknessGrouped(suffixId);
                            if (typeof recalcThicknessSummary === 'function') {
                                recalcThicknessSummary(suffixId);
                            }
                        }
                        if (typeof applyLastGeneralInfo === 'function') { applyLastGeneralInfo(suffixId); }
                    } else if (testConfig.type === 'table_grab_grouped') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div class="gsm-card" style="margin-bottom: 20px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; box-shadow: 0 2px 8px rgba(0,0,0,0.05);"> 
                              <style>
                                #grabTable_${suffixId} { width: 100%; border-collapse: separate; border-spacing: 0; }
                                #grabTable_${suffixId} thead th {
                                    background: #2563eb;
                                    color: #ffffff;
                                    font-weight: 700;
                                    padding: 8px 10px;
                                    border-bottom: 0;
                                    text-align: center;
                                    letter-spacing: 0.2px;
                                }
                                #grabTable_${suffixId} td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
                                #grabTable_${suffixId} tr:last-child td { border-bottom: none; }
                                #grabTable_${suffixId} input[type=number] { width: 100%; padding: 6px 10px; border: 1px solid #e5e7eb; border-radius: 8px; background: #ffffff; }
                                #grabTable_${suffixId} .btn-add { background: linear-gradient(90deg, #16a34a 0%, #22c55e 100%); color: #ffffff; border: none; padding: 6px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; box-shadow: 0 3px 8px rgba(34, 197, 94, 0.2); transition: transform .08s ease, box-shadow .2s ease, filter .2s ease; }
                                #grabTable_${suffixId} .btn-add:hover { filter: brightness(1.05); box-shadow: 0 4px 10px rgba(34, 197, 94, 0.28); }
                                #grabTable_${suffixId} .btn-add:active { transform: translateY(1px); }
                              </style>
                              <div style="overflow-x: auto;"> 
                                <table id="grabTable_${suffixId}"> 
                                    <thead> 
                                        <tr> 
                                            <th>Position of Sampling</th> 
                                            <th>Test Direction</th> 
                                            <th>Force (N)</th> 
                                            <th>Elongation (%)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody id="grabBody_${suffixId}"></tbody> 
                                </table> 
                              </div>
                              <div class="product-header" style="margin-top: 16px; text-align: center;">Summary of Test Result</div>
                              <div style="margin-top: 8px;"> 
                                <table style="width: 100%; border-collapse: collapse;"> 
                                    <thead> 
                                        <tr style="background: #d1fae5;"> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: left;">Grab Test</th> 
                                            <th colspan="2" style="border: 1px solid #ddd; padding: 6px; text-align: center;">MD</th> 
                                            <th colspan="2" style="border: 1px solid #ddd; padding: 6px; text-align: center;">CD</th> 
                                        </tr> 
                                        <tr style="background: #f0fdf4;"> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: left;"></th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Force (N)</th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Elongation (%)</th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Force (N)</th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Elongation (%)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Average:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_force_avg_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_elong_avg_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_force_avg_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_elong_avg_${suffixId}">0.00</span></td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">SD:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_force_sd_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_elong_sd_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_force_sd_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_elong_sd_${suffixId}">0.00</span></td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">CV%:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_force_cv_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_elong_cv_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_force_cv_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_elong_cv_${suffixId}">0.00</span></td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Maximum:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_force_max_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_elong_max_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_force_max_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_elong_max_${suffixId}">0.00</span></td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Minimum:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_force_min_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_elong_min_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_force_min_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_elong_min_${suffixId}">0.00</span></td> 
                                        </tr> 
                                    </tbody> 
                                </table>
                                <!-- Hidden inputs for summary statistics -->
                                <div style="display:none;">
                                    <input type="hidden" name="grab_md_force_avg" id="grab_input_md_force_avg_${suffixId}">
                                    <input type="hidden" name="grab_md_force_sd" id="grab_input_md_force_sd_${suffixId}">
                                    <input type="hidden" name="grab_md_force_cv" id="grab_input_md_force_cv_${suffixId}">
                                    <input type="hidden" name="grab_md_force_max" id="grab_input_md_force_max_${suffixId}">
                                    <input type="hidden" name="grab_md_force_min" id="grab_input_md_force_min_${suffixId}">
                                    <input type="hidden" name="grab_md_elongation_avg" id="grab_input_md_elong_avg_${suffixId}">
                                    <input type="hidden" name="grab_md_elongation_sd" id="grab_input_md_elong_sd_${suffixId}">
                                    <input type="hidden" name="grab_md_elongation_cv" id="grab_input_md_elong_cv_${suffixId}">
                                    <input type="hidden" name="grab_md_elongation_max" id="grab_input_md_elong_max_${suffixId}">
                                    <input type="hidden" name="grab_md_elongation_min" id="grab_input_md_elong_min_${suffixId}">
                                    <input type="hidden" name="grab_cd_force_avg" id="grab_input_cd_force_avg_${suffixId}">
                                    <input type="hidden" name="grab_cd_force_sd" id="grab_input_cd_force_sd_${suffixId}">
                                    <input type="hidden" name="grab_cd_force_cv" id="grab_input_cd_force_cv_${suffixId}">
                                    <input type="hidden" name="grab_cd_force_max" id="grab_input_cd_force_max_${suffixId}">
                                    <input type="hidden" name="grab_cd_force_min" id="grab_input_cd_force_min_${suffixId}">
                                    <input type="hidden" name="grab_cd_elongation_avg" id="grab_input_cd_elong_avg_${suffixId}">
                                    <input type="hidden" name="grab_cd_elongation_sd" id="grab_input_cd_elong_sd_${suffixId}">
                                    <input type="hidden" name="grab_cd_elongation_cv" id="grab_input_cd_elong_cv_${suffixId}">
                                    <input type="hidden" name="grab_cd_elongation_max" id="grab_input_cd_elong_max_${suffixId}">
                                    <input type="hidden" name="grab_cd_elongation_min" id="grab_input_cd_elong_min_${suffixId}">
                                </div> 
                              </div>
                            </div> 
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                        if (typeof initGrabGrouped === 'function') {
                            initGrabGrouped(suffixId);
                        }
                        if (typeof recalcGrabSummary === 'function') {
                            recalcGrabSummary(suffixId);
                        }
                        if (typeof applyLastGeneralInfo === 'function') { applyLastGeneralInfo(suffixId); }
                    } else if (testConfig.type === 'table_strip_grouped') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div class="gsm-card" style="margin-bottom: 20px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; box-shadow: 0 2px 8px rgba(0,0,0,0.05);"> 
                              <style>
                                #stripTable_${suffixId} { 
                                    width: 100%; 
                                    min-width: 900px;
                                    border-collapse: separate; 
                                    border-spacing: 0; 
                                }
                                #stripTable_${suffixId} thead th {
                                    background: #2563eb;
                                    color: #ffffff;
                                    font-weight: 700;
                                    padding: 8px 10px;
                                    border-bottom: 0;
                                    text-align: center;
                                    letter-spacing: 0.2px;
                                }
                                #stripTable_${suffixId} td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
                                #stripTable_${suffixId} tr:last-child td { border-bottom: none; }
                                #stripTable_${suffixId} select,
                                #stripTable_${suffixId} input[type=number] { 
                                    width: 100%; 
                                    min-width: 160px;
                                    padding: 6px 10px; 
                                    border: 1px solid #e5e7eb; 
                                    border-radius: 8px; 
                                    background: #ffffff; 
                                    box-sizing: border-box;
                                }
                                #stripTable_${suffixId} .btn-add { background: linear-gradient(90deg, #16a34a 0%, #22c55e 100%); color: #ffffff; border: none; padding: 6px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; box-shadow: 0 3px 8px rgba(34, 197, 94, 0.2); transition: transform .08s ease, box-shadow .2s ease, filter .2s ease; }
                                #stripTable_${suffixId} .btn-add:hover { filter: brightness(1.05); box-shadow: 0 4px 10px rgba(34, 197, 94, 0.28); }
                                #stripTable_${suffixId} .btn-add:active { transform: translateY(1px); }
                              </style>
                              <div style="overflow-x: auto;"> 
                                <table id="stripTable_${suffixId}"> 
                                    <thead> 
                                        <tr> 
                                            <th>Position of Sampling</th> 
                                            <th>Test Direction</th> 
                                            <th>Strength (kN/m)</th> 
                                            <th>MD:CD Ratio</th> 
                                            <th>Elongation (%)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody id="stripBody_${suffixId}"></tbody> 
                                </table> 
                              </div>
                              <div class="product-header" style="margin-top: 16px; text-align: center;">Summary of Test Result</div>
                              <div style="margin-top: 8px;"> 
                                <table style="width: 100%; border-collapse: collapse;"> 
                                    <thead> 
                                        <tr style="background: #d1fae5;"> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: left;">Strip Tensile Strength Test</th> 
                                            <th colspan="2" style="border: 1px solid #ddd; padding: 6px; text-align: center;">MD</th> 
                                            <th colspan="2" style="border: 1px solid #ddd; padding: 6px; text-align: center;">CD</th> 
                                        </tr> 
                                        <tr style="background: #f0fdf4;"> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: left;"></th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Strength (kN/m)</th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Elongation (%)</th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Strength (kN/m)</th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Elongation (%)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Average:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_strength_avg_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_elong_avg_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_strength_avg_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_elong_avg_${suffixId}">0.00</span></td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">SD:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_strength_sd_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_elong_sd_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_strength_sd_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_elong_sd_${suffixId}">0.00</span></td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">CV%:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_strength_cv_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_elong_cv_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_strength_cv_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_elong_cv_${suffixId}">0.00</span></td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Maximum:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_strength_max_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_elong_max_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_strength_max_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_elong_max_${suffixId}">0.00</span></td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Minimum:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_strength_min_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_elong_min_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_strength_min_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_elong_min_${suffixId}">0.00</span></td> 
                                        </tr> 
                                    </tbody> 
                                </table>
                                <!-- Hidden inputs for summary statistics -->
                                <div style="display:none;">
                                    <input type="hidden" name="strip_md_strength_avg" id="strip_input_md_strength_avg_${suffixId}">
                                    <input type="hidden" name="strip_md_strength_sd" id="strip_input_md_strength_sd_${suffixId}">
                                    <input type="hidden" name="strip_md_strength_cv" id="strip_input_md_strength_cv_${suffixId}">
                                    <input type="hidden" name="strip_md_strength_max" id="strip_input_md_strength_max_${suffixId}">
                                    <input type="hidden" name="strip_md_strength_min" id="strip_input_md_strength_min_${suffixId}">
                                    <input type="hidden" name="strip_md_elongation_avg" id="strip_input_md_elong_avg_${suffixId}">
                                    <input type="hidden" name="strip_md_elongation_sd" id="strip_input_md_elong_sd_${suffixId}">
                                    <input type="hidden" name="strip_md_elongation_cv" id="strip_input_md_elong_cv_${suffixId}">
                                    <input type="hidden" name="strip_md_elongation_max" id="strip_input_md_elong_max_${suffixId}">
                                    <input type="hidden" name="strip_md_elongation_min" id="strip_input_md_elong_min_${suffixId}">
                                    <input type="hidden" name="strip_cd_strength_avg" id="strip_input_cd_strength_avg_${suffixId}">
                                    <input type="hidden" name="strip_cd_strength_sd" id="strip_input_cd_strength_sd_${suffixId}">
                                    <input type="hidden" name="strip_cd_strength_cv" id="strip_input_cd_strength_cv_${suffixId}">
                                    <input type="hidden" name="strip_cd_strength_max" id="strip_input_cd_strength_max_${suffixId}">
                                    <input type="hidden" name="strip_cd_strength_min" id="strip_input_cd_strength_min_${suffixId}">
                                    <input type="hidden" name="strip_cd_elongation_avg" id="strip_input_cd_elong_avg_${suffixId}">
                                    <input type="hidden" name="strip_cd_elongation_sd" id="strip_input_cd_elong_sd_${suffixId}">
                                    <input type="hidden" name="strip_cd_elongation_cv" id="strip_input_cd_elong_cv_${suffixId}">
                                    <input type="hidden" name="strip_cd_elongation_max" id="strip_input_cd_elong_max_${suffixId}">
                                    <input type="hidden" name="strip_cd_elongation_min" id="strip_input_cd_elong_min_${suffixId}">
                                </div> 
                              </div>
                            </div> 
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                        if (typeof initStripGrouped === 'function') {
                            initStripGrouped(suffixId);
                        }
                        if (typeof recalcStripSummary === 'function') {
                            recalcStripSummary(suffixId);
                        }
                        if (typeof applyLastGeneralInfo === 'function') { applyLastGeneralInfo(suffixId); }
                    } else if (testConfig.type === 'table_cbr_grouped') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div class="gsm-card" style="margin-bottom: 20px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; box-shadow: 0 2px 8px rgba(0,0,0,0.05);"> 
                              <style>
                                #cbrTable_${suffixId} { 
                                    width: 100%; 
                                    min-width: 720px;
                                    border-collapse: separate; 
                                    border-spacing: 0; 
                                }
                                #cbrTable_${suffixId} thead th {
                                    background: #2563eb;
                                    color: #ffffff;
                                    font-weight: 700;
                                    padding: 8px 10px;
                                    border-bottom: 0;
                                    text-align: center;
                                    letter-spacing: 0.2px;
                                }
                                #cbrTable_${suffixId} td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
                                #cbrTable_${suffixId} tr:last-child td { border-bottom: none; }
                                #cbrTable_${suffixId} input[type=number] { 
                                    width: 100%; 
                                    min-width: 175px;
                                    padding: 6px 10px; 
                                    border: 1px solid #e5e7eb; 
                                    border-radius: 8px; 
                                    background: #ffffff; 
                                    box-sizing: border-box;
                                }
                                #cbrTable_${suffixId} .btn-add { background: linear-gradient(90deg, #16a34a 0%, #22c55e 100%); color: #ffffff; border: none; padding: 6px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; box-shadow: 0 3px 8px rgba(34, 197, 94, 0.2); transition: transform .08s ease, box-shadow .2s ease, filter .2s ease; }
                                #cbrTable_${suffixId} .btn-add:hover { filter: brightness(1.05); box-shadow: 0 4px 10px rgba(34, 197, 94, 0.28); }
                                #cbrTable_${suffixId} .btn-add:active { transform: translateY(1px); }
                              </style>
                              <div style="overflow-x: auto;"> 
                                <table id="cbrTable_${suffixId}"> 
                                    <thead> 
                                        <tr> 
                                            <th>Position of Sampling</th> 
                                            <th>Ultimate Force (N)</th> 
                                            <th>Ultimate Displacement (mm)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody id="cbrBody_${suffixId}"></tbody> 
                                </table> 
                              </div>
                              <div class="product-header" style="margin-top: 16px; text-align: center;">Summary of Test Result</div>
                              <div style="margin-top: 8px;"> 
                                <table style="width: 100%; border-collapse: collapse;"> 
                                    <thead> 
                                        <tr style="background: #d1fae5;"> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: left;">CBR Test</th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Ultimate Force (N)</th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Ultimate Displacement (mm)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Average:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_force_avg_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_force_avg" id="cbr_sum_force_avg_input_${suffixId}" value="0">
                                            </td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_displ_avg_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_displacement_avg" id="cbr_sum_displ_avg_input_${suffixId}" value="0">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">SD:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_force_sd_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_force_sd" id="cbr_sum_force_sd_input_${suffixId}" value="0">
                                            </td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_displ_sd_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_displacement_sd" id="cbr_sum_displ_sd_input_${suffixId}" value="0">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">CV%:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_force_cv_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_force_cv" id="cbr_sum_force_cv_input_${suffixId}" value="0">
                                            </td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_displ_cv_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_displacement_cv" id="cbr_sum_displ_cv_input_${suffixId}" value="0">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Maximum:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_force_max_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_force_max" id="cbr_sum_force_max_input_${suffixId}" value="0">
                                            </td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_displ_max_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_displacement_max" id="cbr_sum_displ_max_input_${suffixId}" value="0">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Minimum:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_force_min_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_force_min" id="cbr_sum_force_min_input_${suffixId}" value="0">
                                            </td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_displ_min_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_displacement_min" id="cbr_sum_displ_min_input_${suffixId}" value="0">
                                            </td> 
                                        </tr> 
                                    </tbody> 
                                </table> 
                              </div>
                            </div> 
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                        if (typeof initCbrGrouped === 'function') {
                            initCbrGrouped(suffixId);
                        }
                        if (typeof recalcCbrSummary === 'function') {
                            recalcCbrSummary(suffixId);
                        }
                        if (typeof applyLastGeneralInfo === 'function') { applyLastGeneralInfo(suffixId); }
                    } else if (testConfig.type === 'table_cut_length') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                              <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">SL No</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Parameter</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Test Standard</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Unit</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Test Result</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">1</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Cut Length</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">${method}</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">mm</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="cut_length_result" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="cut_length_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                              </div>
                            </div>
                        `;
                        
                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                    } else if (testConfig.type === 'table_fineness') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                              <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">SL No</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Parameter</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Test Standard</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Unit</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Test Result</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">1</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Unit Weight</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">EN ISO 1973</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">dTex</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="unit_weight_result" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="unit_weight_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                              </div>
                            </div>
                        `;
                        
                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                    } else if (testConfig.type === 'table_tenacity_fiber') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                              <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">SL No</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Parameter</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Test Standard</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Unit</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Test Result</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">1</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Tenacity at Break</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">EN ISO 5079</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">cN/dTex</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="tenacity_at_break" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="tenacity_at_break_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">2</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Std Deviation</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">EN ISO 5079</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">cN/dTex</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="std_deviation" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="std_deviation_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">3</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">CV%</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">EN ISO 5079</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">%</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="cv_percent" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="cv_percent_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">4</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Elongation at Break</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">EN ISO 5079</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">%</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="elongation_at_break" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="elongation_at_break_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">5</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Cross Section</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">-</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">-</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa;">
                                                <span style="font-weight: bold;">Round</span>
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="cross_section_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                              </div>
                              
                              <!-- Acceptance Criteria Table -->
                              <div style="margin-top: 20px;">
                                <h3 style="text-align: center; margin-bottom: 15px;">Acceptance Criteria</h3>
                                <div style="overflow-x: auto;">
                                  <table style="width: 100%; border-collapse: collapse;">
                                      <thead>
                                          <tr style="background: #1976d2; color: #fff;">
                                              <th style="border: 1px solid #ddd; padding: 10px; text-align: center;">Acceptance Range</th>
                                              <th style="border: 1px solid #ddd; padding: 10px; text-align: center;">High</th>
                                              <th style="border: 1px solid #ddd; padding: 10px; text-align: center;">Good</th>
                                              <th style="border: 1px solid #ddd; padding: 10px; text-align: center;">Medium</th>
                                              <th style="border: 1px solid #ddd; padding: 10px; text-align: center;">BWDB Requirements</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <tr>
                                              <td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">Tenacity</td>
                                              <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">5.4+</td>
                                              <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">5+</td>
                                              <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">4.5+</td>
                                              <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">-</td>
                                          </tr>
                                          <tr>
                                              <td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">Elongation</td>
                                              <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">0.8</td>
                                              <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">0.6</td>
                                              <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">-</td>
                                              <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">60%+</td>
                                          </tr>
                                      </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                        `;
                        
                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                    } else if (testConfig.type === 'table_tenacity_yarn') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                              <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">SL No</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Parameter</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Test Standard</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Unit</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Test Result</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">1</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Tenacity at Break</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">ASTM D2256</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">cN/dTex</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="yarn_tenacity_at_break" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="yarn_tenacity_at_break_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">2</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Std Deviation</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">ASTM D2256</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">cN/dTex</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="yarn_std_deviation" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="yarn_std_deviation_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">3</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">CV%</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">ASTM D2256</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">%</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="yarn_cv_percent" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="yarn_cv_percent_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">4</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Elongation at Break</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">ASTM D2256</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">%</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="yarn_elongation_at_break" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="yarn_elongation_at_break_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                              </div>
                            </div>
                        `;
                        
                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                    } else if (testConfig.type === 'table_strip') {
                        // Generate Strip Tensile (ASTM D4595) table (without Sample Details/Common fields)
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div style=\"margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;\">
                              <div style=\"overflow-x: auto;\">
                                <table style=\"width: 100%; border-collapse: collapse;\">
                                    <thead>
                                        <tr style=\"background: #f8f9fa;\">
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">Position of Sampling</th>
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">Test Direction</th>
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">Strength (kN/m)</th>
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">MD:CD Ratio</th>
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">Elongation (%)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;
                        for (let i = 1; i <= 4; i++) {
                            const dir = (i % 2 === 1) ? 'MD' : 'CD';
                            html += `
                                <tr>
                                    <td style=\"border: 1px solid #ddd; padding: 6px;\">
                                        <input type="text" placeholder="Position ${i}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </td>
                                    <td style=\"border: 1px solid #ddd; padding: 6px; text-align:center;\">
                                        <input type="text" value="${dir}" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; text-align:center;">
                                    </td>
                                    <td style=\"border: 1px solid #ddd; padding: 6px;\">
                                        <input type="number" step="any" max="1000" min="0" class="strength_input" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" onchange="updateMdCdRatio(this)" oninput="if(this.value < 0) this.value = 0">
                                    </td>
                                    <td style=\"border: 1px solid #ddd; padding: 6px; text-align:center; background:#f8f9fa;\">
                                        <span class="mdcd_ratio" style="display:inline-block; padding:2px 8px; border-radius:12px; background:#eef3ff; border:1px solid #c9d6ff;">0</span>
                                    </td>
                                    <td style=\"border: 1px solid #ddd; padding: 6px;\">
                                        <input type="number" step="any" max="1000" min="0" class="elong_input" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </td>
                                </tr>
                            `;
                            // No partition between MD and CD rows within a pair
                        }
                        // Partition after four rows for Position column only
                        html += `
                                    <tr>
                                        <td style="border-top: 2px solid #999; height: 1px;"></td>
                                        <td style="border-top: none;"></td>
                                        <td style="border-top: none;"></td>
                                        <td style="border-top: none;"></td>
                                        <td style="border-top: none;"></td>
                                    </tr>
                                    </tbody>
                                </table>
                    </div>
                                <div style=\"margin: 15px 0; text-align: center;\">
                                    <button type="button" class="btn btn-sm btn-primary" onclick="addStripGroup()" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                        <i class="fa fa-plus"></i> Add Group of 4 Rows
                        </button>
                                </div>
                            </div>
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                    } else if (testConfig.type === 'table_cbr') {
                        // Generate CBR table similar to GSM pattern
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            ${isCommonFieldsTest(testName, method) ? buildCommonFieldsHtml(suffixId) : ''}
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                              <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Position of Sampling</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Ultimate Force (N)</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Ultimate Displacement (mm)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;
                        for (let i = 1; i <= 4; i++) {
                            html += `
                                <tr>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="text" placeholder="Position ${i}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="number" step="any" max="1000" min="0" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="number" step="any" max="1000" min="0" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                    </td>
                                </tr>
                            `;
                        }
                        html += `
                                    </tbody>
                                </table>
                                </div>
                                <div style="margin: 15px 0; text-align: center;">
                                    <button type="button" class="btn btn-sm btn-primary" onclick="addCbrGroup()" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                        <i class="fa fa-plus"></i> Add Group of 4 Rows
                                    </button>
                                    </div>
                                </div>
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                    } else if (testConfig.type === 'table_grab') {
                        // Generate Grab Tensile table similar to GSM pattern
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            ${isCommonFieldsTest(testName, method) ? buildCommonFieldsHtml(suffixId) : ''}
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                              <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Position of Sampling</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Force (N)</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Elongation (%)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;
                        for (let i = 1; i <= 4; i++) {
                            html += `
                                <tr>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="text" placeholder="Position ${i}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="number" step="any" max="1000" min="0" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="number" step="any" max="1000" min="0" placeholder="0.0000" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                    </td>
                                </tr>
                            `;
                        }
                        html += `
                                    </tbody>
                                </table>
                            </div>
                                <div style="margin: 15px 0; text-align: center;">
                                    <button type="button" class="btn btn-sm btn-primary" onclick="addGrabGroup()" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                        <i class="fa fa-plus"></i> Add Group of 4 Rows
                                    </button>
                        </div>
                                </div>
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
					} else if (testConfig.type === 'form_fields') {
                        // Custom form fields for Tenacity of Yarn with uniform styling
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        const testPrefix = 'ty';
                        const testParams = ['Denier','Tenacity at Break','Std Deviation','CV%','Elongation at Break'];
                        const testUnits = ['dTex', 'cN/dTex', '%', '%', '%'];
                        const testStandards = ['ISO 2060', 'ASTM D2256', 'ASTM D2256', 'ASTM D2256', 'ASTM D2256'];
                        
                        let html = `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px;">
                        `;
                        
                        testConfig.fields.forEach(field => {
                            const required = field.required ? 'required' : '';
                            const nowLocal = new Date();
                            const y = nowLocal.getFullYear();
                            const m = String(nowLocal.getMonth() + 1).padStart(2, '0');
                            const d = String(nowLocal.getDate()).padStart(2, '0');
                            const hh = String(nowLocal.getHours()).padStart(2, '0');
                            const mm = String(nowLocal.getMinutes()).padStart(2, '0');
                            const localDateTime = `${y}-${m}-${d}T${hh}:${mm}`;
                            const currentValue = field.value === 'current' ? localDateTime : '';
                            const valueAttr = currentValue ? `value="${currentValue}"` : '';
                            const unitText = field.unit ? ` (${field.unit})` : '';
                            
                            if (field.type === 'textarea') {
                                html += `
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">${field.name}${field.required ? ' *' : ''}:</label>
                                        <textarea ${required}
                                                  placeholder="Enter ${field.name.toLowerCase()}"
                                                  maxlength="255"
                                                  style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; min-height: 60px; resize: vertical;"></textarea>
                                </div>
                                `;
                            } else {
                                const stepAttr = field.step ? `step="${field.step}"` : '';
                                const maxAttr = field.max ? `max="${field.max}"` : '';
                                const minAttr = field.min ? `min="${field.min}"` : '';
                                                const placeholder = field.type === 'number' ? '' : '';
                                const negativeGuard = field.type === 'number' ? 'oninput="if(this.value < 0) this.value = 0"' : '';
                                
                                html += `
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">${field.name}${unitText}${field.required ? ' *' : ''}:</label>
                                        <input type="${field.type}"
                                               ${required}
                                               ${stepAttr}
                                               ${maxAttr}
                                               ${minAttr}
                                               ${valueAttr}
                                               ${placeholder}
                                               style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;"
                                               ${negativeGuard}>
                            </div>
                                `;
                            }
                        });
                        
                        html += `
                        </div>
                                <div style="margin-top: 15px;">
                                    <table style="width: 100%; border-collapse: collapse;">
                                        <thead>
                                            <tr style="background:#f8f9fa;">
                                                <th style="border:1px solid #ddd; padding:6px; text-align:center;">SI NO</th>
                                                <th style="border:1px solid #ddd; padding:6px; text-align:center;">Parameter</th>
                                                <th style="border:1px solid #ddd; padding:6px; text-align:center;">Test Standard</th>
                                                <th style="border:1px solid #ddd; padding:6px; text-align:center;">Unit</th>
                                                <th style="border:1px solid #ddd; padding:6px; text-align:center;">Test Result</th>
                                                <th style="border:1px solid #ddd; padding:6px; text-align:center;">Remarks</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${[1,2,3,4,5].map(i => `
                                                <tr>
                                                    <td style=\"border:1px solid #ddd; padding:6px; text-align:center;\">${i}</td>
                                                    <td style=\"border:1px solid #ddd; padding:6px;\"><input type=\"text\" name=\"${testPrefix}_param_${i}\" value=\"${testParams[i-1]}\" readonly class=\"readonly\" style=\"width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;\"></td>
                                                    <td style=\"border:1px solid #ddd; padding:6px;\"><input type=\"text\" name=\"${testPrefix}_std_${i}\" value=\"${testStandards[i-1]}\" readonly class=\"readonly\" style=\"width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;\"></td>
                                                    <td style=\"border:1px solid #ddd; padding:6px;\"><input type=\"text\" name=\"${testPrefix}_unit_${i}\" value=\"${testUnits[i-1]}\" readonly class=\"readonly\" style=\"width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;\"></td>
                                                    <td style=\"border:1px solid #ddd; padding:6px;\"><input type=\"number\" step=\"any\" max=\"1000\" min=\"0\" name=\"${testPrefix}_result_${i}\" style=\"width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;\" oninput=\"if(this.value < 0) this.value = 0\" required></td>
                                                    <td style=\"border:1px solid #ddd; padding:6px;\"><input type=\"text\" name=\"${testPrefix}_remarks_${i}\" placeholder=\"Remarks (optional)\" style=\"width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;\"></td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                    </div>
                            </div>
                        `;
						paramsContent.innerHTML = html;
						paramsDiv.style.display = 'block';
					} else if (testConfig.type === 'form_fields_weathering') {
						// Weathering Exposure Test custom layout
						const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
						const nowLocal = new Date();
						const y = nowLocal.getFullYear();
						const m = String(nowLocal.getMonth() + 1).padStart(2, '0');
						const d = String(nowLocal.getDate()).padStart(2, '0');
						const hh = String(nowLocal.getHours()).padStart(2, '0');
						const mm = String(nowLocal.getMinutes()).padStart(2, '0');
						const localDateTime = `${y}-${m}-${d}T${hh}:${mm}`;

						let html = `
							<div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
								<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px;">
									<div class="form-group"><label>Sample Received From *</label><input type="text" name="wx_sample_received_from_${suffixId}" required></div>
									<div class="form-group"><label>Sample Collected From *</label><input type="text" name="wx_sample_collected_from_${suffixId}" required></div>
									<div class="form-group"><label>Reference *</label>
										<select name="wx_reference_${suffixId}" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
											<option value="">-- Select Reference --</option>
											<?php foreach($references as $ref): ?>
												<option value="<?php echo htmlspecialchars($ref['reference']); ?>"><?php echo htmlspecialchars($ref['reference']); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="form-group" style="grid-column: 1 / -1;"><label>Sample Description *</label><input type="text" name="wx_sample_description_${suffixId}" required></div>
									<div class="form-group"><label>Recipe *</label><input type="text" name="wx_recipe_${suffixId}" required></div>
									<div class="form-group"><label>Received Date *</label><input type="datetime-local" name="wx_received_date_${suffixId}" value="${localDateTime}" required></div>
									<div class="form-group"><label>Test Start Date *</label><input type="date" name="wx_test_start_${suffixId}" id="wx_test_start_${suffixId}" onchange="validateWeatheringTestDate('${suffixId}')" required></div>
									<div class="form-group"><label>Test End Date *</label><input type="date" name="wx_test_end_${suffixId}" id="wx_test_end_${suffixId}" onchange="validateWeatheringTestDate('${suffixId}')" required></div>
									<div class="form-group"><label>Testing Method *</label><input type="text" name="wx_testing_method_${suffixId}" required></div>
									<div class="form-group"><label>Test Name *</label><input type="text" name="wx_test_name_${suffixId}" required></div>
                        </div>
								<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; margin-top: 10px;">
									<div class="form-group">
										<label>Test Speed / Gauge Length / Specimen Size *</label>
										<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
											<div style="display:flex; flex-direction:column; min-width:160px;">
												<small style="color:#6c757d; font-weight:600;">Test Speed</small>
												<input type="text" name="wx_test_speed_${suffixId}" required>
                                </div>
											<div style="display:flex; flex-direction:column; min-width:160px;">
\t\t\t\t\t\t\t\t\t\t\t<small style=\"color:#6c757d; font-weight:600;\">Gauge Length</small>
\t\t\t\t\t\t\t\t\t\t\t<input type=\"text\" name=\"wx_gauge_length_${suffixId}\" required>
\t\t\t\t\t\t\t\t\t\t</div>
											<div style="display:flex; flex-direction:column; min-width:200px; flex:1;">
												<small style="color:#6c757d; font-weight:600;">Specimen Size</small>
												<input type="text" name="wx_specimen_size_${suffixId}" style="width: 140px;" required>
											</div>
										</div>
									</div>
								</div>
								<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; margin-top: 10px;">
									<div class="form-group" style="grid-column: 1 / -1;"><label>Note (if any)</label><input type="text" name="wx_note_${suffixId}" maxlength="255" placeholder="Optional"></div>
									<div class="form-group"><label>Temperature (°C) *</label><input type="number" step="any" min="0" max="1000" name="wx_temperature_${suffixId}" oninput="if(this.value < 0) this.value = 0" required></div>
									<div class="form-group"><label>RH% *</label><input type="number" step="any" min="0" max="100" name="wx_rh_${suffixId}" oninput="if(this.value < 0) this.value = 0" required></div>
								</div>
								<div style="margin-top: 15px;">
									<table style="width:100%; border-collapse: collapse;">
										<thead>
											<tr style="background:#f8f9fa;">
												<th style="border:1px solid #ddd; padding:4px; text-align:center; width:60px; min-width:50px; white-space:nowrap;">Specimen No</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;">Test Direction</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;" colspan="2">Breaking Force (N)</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;">Force Retain (%)</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;" colspan="2">Elongation (%)</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;">Action</th>
											</tr>
											<tr style="background:#fafafa;">
												<th style="border:1px solid #ddd; padding:4px; text-align:center; width:60px; min-width:50px;"></th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;"></th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;">After</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;">Before</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;"></th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;">After</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;">Before</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;"></th>
											</tr>
										</thead>
										<tbody id="wx_tbody_${suffixId}">
											<tr>
												<td style="border:1px solid #ddd; padding:6px; text-align:center;">1</td>
												<td style="border:1px solid #ddd; padding:6px; text-align:center;">
													<div style="display:inline-flex; gap:6px; align-items:center;">
														<button type="button" class="dir-btn" data-row="1" data-suffix="${suffixId}" onclick="setWxDirection(this,'MD')" style="padding:4px 10px; border:1px solid #ccc; border-radius:4px; background:#007bff; color:#fff;">MD1</button>
														<button type="button" class="dir-btn" data-row="1" data-suffix="${suffixId}" onclick="setWxDirection(this,'CD')" style="padding:4px 10px; border:1px solid #ccc; border-radius:4px; background:#fff; color:#2c3e50;">CD1</button>
														<input type="hidden" name="wx_dir_${suffixId}_1" value="MD">
													</div>
												</td>
												<td style="border:1px solid #ddd; padding:6px;"><input type="number" step="any" min="0" max="1000" name="wx_bf_after_${suffixId}_1" data-row="1" data-suffix="${suffixId}" class="wx_bf_after" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0; calculateForceRetainWeathering(this)"></td>
												<td style="border:1px solid #ddd; padding:6px;"><input type="number" step="any" min="0" max="1000" name="wx_bf_before_${suffixId}_1" data-row="1" data-suffix="${suffixId}" class="wx_bf_before" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0; calculateForceRetainWeathering(this)"></td>
												<td style="border:1px solid #ddd; padding:6px;"><input type="text" name="wx_fr_${suffixId}_1" class="wx_fr" readonly value="" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px; background:#f8f9fa;"></td>
												<td style="border:1px solid #ddd; padding:6px;"><input type="number" step="any" min="0" max="1000" name="wx_el_after_${suffixId}_1" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0"></td>
												<td style="border:1px solid #ddd; padding:6px;"><input type="number" step="any" min="0" max="1000" name="wx_el_before_${suffixId}_1" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0"></td>
												<td style="border:1px solid #ddd; padding:6px;"></td>
											</tr>
										</tbody>
									<tbody>
										${['Average','SD','CV','Maximum','Minimum'].map((label, idx) => `
											<tr>
												${idx === 0 ? `<td style="border:1px solid #ddd; padding:6px; text-align:center; font-weight:600;" rowspan="5">Total</td>` : ''}
												<td style="border:1px solid #ddd; padding:6px;">${label}</td>
												<td style="border:1px solid #ddd; padding:6px; text-align:center;"><span id="wx_stat_bf_after_${suffixId}_${label.toLowerCase()}"></span></td>
												<td style="border:1px solid #ddd; padding:6px; text-align:center;"><span id="wx_stat_bf_before_${suffixId}_${label.toLowerCase()}"></span></td>
												<td style="border:1px solid #ddd; padding:6px; text-align:center;"><span id="wx_stat_fr_${suffixId}_${label.toLowerCase()}"></span></td>
												<td style="border:1px solid #ddd; padding:6px; text-align:center;"><span id="wx_stat_el_after_${suffixId}_${label.toLowerCase()}"></span></td>
												<td style="border:1px solid #ddd; padding:6px; text-align:center;"><span id="wx_stat_el_before_${suffixId}_${label.toLowerCase()}"></span></td>
											</tr>
										`).join('')}
									</tbody>
									</table>
									<!-- Test Result summary table -->
									<div style="margin-top: 16px;">
										<h3 style="text-align:center; margin:8px 0;">Test Result</h3>
										<table style="width:100%; border-collapse: collapse;">
											<thead>
												<tr style="background:#f8f9fa;">
													<th style="border:1px solid #ddd; padding:6px; text-align:center;"></th>
													<th style="border:1px solid #ddd; padding:6px; text-align:center;">After Exposure</th>
													<th style="border:1px solid #ddd; padding:6px; text-align:center;">Before Exposure</th>
													<th style="border:1px solid #ddd; padding:6px; text-align:center;">Retain (%)</th>
												</tr>
											</thead>
											<tbody>
												<tr>
													<td style="border:1px solid #ddd; padding:6px;">Force (N)</td>
													<td style="border:1px solid #ddd; padding:6px; text-align:center;"><span id="wx_result_after_${suffixId}"></span></td>
													<td style="border:1px solid #ddd; padding:6px; text-align:center;"><span id="wx_result_before_${suffixId}"></span></td>
													<td style="border:1px solid #ddd; padding:6px; text-align:center;"><span id="wx_result_retain_${suffixId}"></span></td>
												</tr>
											</tbody>
										</table>
									</div>
									<div style="margin-top: 10px;">
										<button type="button" onclick="addWxRow('${suffixId}')" style="padding:8px 16px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer; font-weight:600;">Add 1 More Row</button>
									</div>
								</div>
							</div>
						`;

						paramsContent.innerHTML = html;
						paramsDiv.style.display = 'block';
                    } else if (testConfig.type === 'form_iso12956') {
                        // ISO 12956 Determination of the Characteristics test
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        const now = new Date();
                        const yyyy = now.getFullYear();
                        const mm = String(now.getMonth() + 1).padStart(2, '0');
                        const dd = String(now.getDate()).padStart(2, '0');
                        const hh = String(now.getHours()).padStart(2, '0');
                        const min = String(now.getMinutes()).padStart(2, '0');
                        const currentDateTime = `${yyyy}-${mm}-${dd}T${hh}:${min}`;
                        const currentDate = `${yyyy}-${mm}-${dd}`;

                        let html = `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                                <h3 style="margin-top: 0; color: #2c3e50;">Test Parameters</h3>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px;">
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Test Materials:</label>
                                        <input type="text" name="iso12956_materials_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">GSM:</label>
                                        <input type="number" step="0.0001" min="0" name="iso12956_gsm_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Roll Number:</label>
                                        <input type="text" name="iso12956_roll_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Lab Test No:</label>
                                        <input type="text" name="iso12956_line_${suffixId}" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; background:#f8f9fa;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Sample ID:</label>
                                        <input type="text" name="iso12956_sampleid_${suffixId}" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Specimens Size:</label>
                                        <div style="display: flex; gap: 5px;">
                                            <input type="number" step="0.0001" min="0" name="iso12956_specimens_${suffixId}" required style="flex: 1; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                            <select name="iso12956_specimens_unit_${suffixId}" style="width: 60px; padding: 5px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px;">
                                                <option value="mm">mm</option>
                                                <option value="mm2">mm2</option>
                                                <option value="cm">cm</option>
                                                <option value="m">m</option>
                                                <option value="in">in</option>
                                                <option value="ft">ft</option>
                                                    </select>
                                                </div>
                                            </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Sand Type:</label>
                                        <input type="text" name="iso12956_sandtype_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Total Sand Weight:</label>
                                        <div style="display: flex; gap: 5px;">
                                            <input type="number" step="0.0001" min="0" name="iso12956_sandweight_${suffixId}" required style="flex: 1; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0; calcIso12956('${suffixId}')">
                                            <select name="iso12956_sandweight_unit_${suffixId}" style="width: 60px; padding: 5px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px;">
                                                <option value="g">g</option>
                                                <option value="kg">kg</option>
                                                <option value="mg">mg</option>
                                                <option value="lb">lb</option>
                                                <option value="oz">oz</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Sieving time:</label>
                                        <div style="display: flex; gap: 5px;">
                                            <input type="number" step="0.0001" min="0" name="iso12956_sievingtime_${suffixId}" required style="flex: 1; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                            <select name="iso12956_sievingtime_unit_${suffixId}" style="width: 60px; padding: 5px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px;">
                                                <option value="min">min</option>
                                                <option value="s">s</option>
                                                <option value="h">h</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Checked By:</label>
                                        <input type="text" name="iso12956_checkedby_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Sample Received:</label>
                                        <input type="date" name="iso12956_received_${suffixId}" value="${currentDate}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Sample Tested:</label>
                                        <input type="datetime-local" name="iso12956_tested_${suffixId}" value="${currentDateTime}" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa;">
                        </div>
                    </div>

                                <!-- Sieve Analysis Data Table -->
                                <div style="margin-top: 20px;">
                                    <h3 style="text-align: center; margin-bottom: 15px; color: #2c3e50;">Sieve Analysis Data</h3>
                                    <div style="overflow-x: auto;">
                                        <table id="iso12956_table_${suffixId}" style="width: 100%; border-collapse: collapse;">
                                            <thead>
                                                <tr style="background: #f8f9fa;">
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center;">Sieve Size(mm)</th>
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center;">Retained</th>
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center;">Cumulative</th>
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center;">Cumulative Passing(%)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                        `;
                        
                        // Generate 8 rows for sieve analysis data
                        for (let i = 1; i <= 8; i++) {
                            html += `
                                <tr>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="number" step="0.0001" min="0" name="iso12956_sievesize_${suffixId}_${i}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="number" step="0.0001" min="0" name="iso12956_retained_${suffixId}_${i}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0; calcIso12956('${suffixId}')">
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa;">
                                        <span id="iso12956_cum_${suffixId}_${i}" style="font-weight: bold; color: #27ae60;">0.00</span>
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa;">
                                        <span id="iso12956_pass_${suffixId}_${i}" style="font-weight: bold; color: #27ae60;">0.00</span>
                                    </td>
                                </tr>
                            `;
                        }
                        
                        html += `
                                            </tbody>
                                        </table>
                                        </div>
                                        
                                    <!-- Calculation Section -->
                                    <div style="margin-top: 20px; padding: 15px; border: 2px solid #27ae60; border-radius: 8px; background: #d5f4e6;">
                                        <h3 style="text-align: center; margin-bottom: 15px; color: #2c3e50;"> Calculation:</h3>
                                        <div style="margin-bottom: 15px;">
                                            <label style="font-size: 14px; font-weight: bold; margin-bottom: 8px; display: block;">Enter O-Value (%):</label>
                                            <div style="display: flex; gap: 10px; align-items: center;">
                                                <input type="number" id="o_value_input_${suffixId}" placeholder="e.g., 90" min="0" max="100" step="0.1" style="width: 100px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                                <button type="button" onclick="calculateOpeningSize('${suffixId}')" style="padding: 8px 16px; background: #27ae60; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold;">
                                                    Calculate
                        </button>
                                                </div>
        </div>
                                        <div id="opening_size_results_${suffixId}" style="display: none;">
                                            <div style="background: white; padding: 15px; border-radius: 6px; margin-bottom: 10px;">
                                                <h4 style="margin-top: 0; color: #2c3e50;">Calculation Details:</h4>
                                                <div id="calculation_details_${suffixId}" style="line-height: 1.6; font-size: 14px;"></div>
    </div>
                                            <div style="background: #e8f5e9; padding: 15px; border-radius: 6px; margin-bottom: 10px; border: 2px solid #4caf50;">
                                                <h4 style="margin-top: 0; color: #2c3e50;">Results:</h4>
                                                <div id="final_result_${suffixId}" style="font-size: 16px; font-weight: bold; color: #27ae60;"></div>
                                            </div>
                                            <div style="background: #f8f9fa; padding: 10px; border-radius: 6px; font-size: 12px; color: #6c757d;">
                                                <strong>Note:</strong> O90 corresponds to 90% passing as per ISO 12956 standard. It represents the Apparent Opening Size (AOS) — the pore size through which 90% of particles can pass.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                        
                        // Add event listeners for auto-generation
                        addIso12956EventListeners(suffixId);
                    } else if (testConfig.type === 'form_iso11058') {
                        // ISO 11058 Water Permeability Test
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        const now = new Date();
                        const yyyy = now.getFullYear();
                        const mm = String(now.getMonth() + 1).padStart(2, '0');
                        const dd = String(now.getDate()).padStart(2, '0');
                        const currentDate = `${yyyy}-${mm}-${dd}`;

                        let html = `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                                <h3 style="margin-top: 0; color: #2c3e50;">Test Parameters</h3>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px;">
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Test Name:</label>
                                        <input type="text" name="iso11058_testname_${suffixId}" value="Water Permeability Characteristics Normal to the Plane, Without Load" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Specimen Area, A:</label>
                                        <div style="display: flex; gap: 5px;">
                                            <input type="number" step="0.0001" min="0" name="iso11058_area_${suffixId}" required style="flex: 1; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                            <select name="iso11058_area_unit_${suffixId}" style="width: 60px; padding: 5px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px;">
                                                <option value="mm2">mm2</option>
                                                <option value="cm2">cm2</option>
                                                <option value="m2">m2</option>
                                                    </select>
                                                </div>
                                            </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Water Temperature:</label>
                                        <div style="display: flex; gap: 5px; align-items: center;">
                                            <input type="number" step="0.0001" min="0" name="iso11058_watertemp_${suffixId}" required style="flex: 1; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                            <span style="font-size: 12px;">°C</span>
                                    </div>
                        </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Correction Factor:</label>
                                        <input type="number" step="0.0001" min="0" name="iso11058_correction_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Water Type:</label>
                                        <input type="text" name="iso11058_watertype_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">GSM:</label>
                                        <input type="number" step="0.0001" min="0" name="iso11058_gsm_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0; iso11058_updateReport('${suffixId}')">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Lab Test No:</label>
                                        <input type="text" id="iso11058_labtest_${suffixId}" name="iso11058_labtest_${suffixId}" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Roll number:</label>
                                        <input type="text" name="iso11058_roll_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="iso11058_updateReport('${suffixId}')">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Test Report No:</label>
                                        <input type="text" name="iso11058_report_${suffixId}" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Test Date:</label>
                                        <input type="date" name="iso11058_testdate_${suffixId}" value="${currentDate}" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Number of Specimens:</label>
                                        <input type="number" step="1" min="1" name="iso11058_numspec_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 1) this.value = 1">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Relative Humidity:</label>
                                        <input type="text" name="iso11058_rh_${suffixId}" placeholder="50~60%" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Dissolved Oxygen Values:</label>
                                        <div style="display:flex; align-items:center; gap:6px;">
                                            <input type="text" name="iso11058_do_${suffixId}" placeholder="<5" style="flex:1; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            <span style="font-size:12px; color:#2c3e50;">ppm</span>
                                        </div>
                                    </div>
                                </div>
                                <div style="margin-top: 8px; font-size: 12px; color: #2c3e50;">Test specimen were sampled and tested as instructed by the reference standard.</div>

                                <div style="margin-top: 16px; padding: 12px; border: 1px solid #bbb; border-radius: 6px; background: #fcfcfc;">
                                    <h4 style="margin: 0 0 10px 0; color: #2c3e50;">Calculation Inputs</h4>
                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px;">
                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                            <label style="font-size: 12px; font-weight: bold;">Exposed area of the test specimen (a)</label>
                                            <input type="number" step="0.0001" min="0" name="iso11058_area_specimen_${suffixId}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="recalculateAllRows('${suffixId}')">
                    </div>
                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                            <label style="font-size: 12px; font-weight: bold;">Exposed area of stand pipe (m2)</label>
                                            <input type="number" step="0.0001" min="0" name="iso11058_area_pipe_${suffixId}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="recalculateAllRows('${suffixId}')">
        </div>
                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                            <label style="font-size: 12px; font-weight: bold;">Laboratory Temperature °C</label>
                                            <input type="number" step="0.0001" min="0" name="iso11058_labtemp_${suffixId}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
    </div>
                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                            <label style="font-size: 12px; font-weight: bold;">Avg. Water Temperature °C</label>
                                            <input type="number" step="0.0001" min="0" name="iso11058_avgwatertemp_${suffixId}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                        </div>
                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                            <label style="font-size: 12px; font-weight: bold;">Avg. Correction Factor</label>
                                            <input type="number" step="0.0001" min="0" name="iso11058_avgcorr_${suffixId}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                        </div>
                                    </div>
                                </div>

                                <!-- Experimental Data and Calculations Table -->
                                <div style="margin-top: 20px; padding: 15px; border: 1px solid #bbb; border-radius: 6px; background: #fcfcfc;">
                                    <h4 style="margin: 0 0 15px 0; color: #2c3e50;">Table: Experimental data and calculations for a geotextile or geatexile related product specimen (falling head method)</h4>
                                    
                                    <div style="overflow-x: auto;">
                                        <table style="width: 100%; border-collapse: collapse; border: 1px solid #ddd; font-size: 12px;">
                                            <thead>
                                                <tr style="background: #f8f9fa;">
                                                    <!-- Chosen Water Level Interval -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" colspan="5">Chosen Water Level Interval</th>
                                                    <!-- Thickness -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" rowspan="3">Thickness</th>
                                                    <!-- Water Level at v=0 -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" rowspan="3">Water Level at v=0</th>
                                                    <!-- Temp -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" rowspan="3">Temp.</th>
                                                    <!-- Correction Factor -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" rowspan="3">Correction Factor</th>
                                                    <!-- Head Difference -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" rowspan="3">Δh (m)</th>
                                                    <!-- Time -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" rowspan="3">Time</th>
                                                    <!-- Velocity -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" rowspan="3">Velocity (m/s×10⁻³)</th>
                                                    <!-- Permeability -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" rowspan="3">Permeability 10⁻³(m/s)</th>
                                                </tr>
                                                <tr style="background: #f8f9fa;">
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;" rowspan="2">No</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;" colspan="2">Upper Limit</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;" colspan="2">Lower Limit</th>
                                                </tr>
                                                <tr style="background: #f8f9fa;">
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">h₀ (m)</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">t₁ (s)</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">h₁ (m)</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">t₂ (s)</th>
                                                </tr>
                                                <tr style="background: #f8f9fa;">
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;"></th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;"></th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;"></th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;"></th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;"></th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">mm</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">h₀ (m)</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">T (°C)</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">RT</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">(m)</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">(s)</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">V₂₀</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">K</th>
                                                </tr>
                                            </thead>
                                            <tbody id="experimental_data_body_${suffixId}">
                                                <!-- Data rows will be dynamically added here -->
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" name="exp_no_1_${suffixId}" value="1" readonly style="width: 40px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h0_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 1)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t1_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 1)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h1_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 1)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t2_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 1)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_thickness_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 1)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_water_level_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 1)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_temp_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 1)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_correction_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 1)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_head_diff_1_${suffixId}" readonly style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_time_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_velocity_1_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_permeability_1_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" name="exp_no_2_${suffixId}" value="2" readonly style="width: 40px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h0_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 2)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t1_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 2)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h1_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 2)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t2_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 2)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_thickness_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 2)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_water_level_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 2)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_temp_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 2)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_correction_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 2)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_head_diff_2_${suffixId}" readonly style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_time_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_velocity_2_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_permeability_2_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" name="exp_no_3_${suffixId}" value="3" readonly style="width: 40px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h0_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 3)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t1_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 3)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h1_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 3)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t2_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 3)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_thickness_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 3)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_water_level_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 3)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_temp_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 3)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_correction_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 3)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_head_diff_3_${suffixId}" readonly style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_time_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_velocity_3_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_permeability_3_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" name="exp_no_4_${suffixId}" value="4" readonly style="width: 40px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h0_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 4)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t1_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 4)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h1_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 4)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t2_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 4)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_thickness_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 4)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_water_level_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 4)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_temp_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 4)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_correction_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 4)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_head_diff_4_${suffixId}" readonly style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_time_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_velocity_4_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_permeability_4_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" name="exp_no_5_${suffixId}" value="5" readonly style="width: 40px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h0_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 5)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t1_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 5)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h1_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 5)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t2_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 5)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_thickness_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 5)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_water_level_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 5)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_temp_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 5)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_correction_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 5)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_head_diff_5_${suffixId}" readonly style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_time_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_velocity_5_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_permeability_5_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <div style="margin-top: 10px; text-align: center;">
                                        <button type="button" onclick="addExperimentalRow('${suffixId}')" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                            Add Row
                                        </button>
                                        <button type="button" onclick="removeExperimentalRow('${suffixId}')" style="padding: 8px 16px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; margin-left: 10px;">
                                            Remove Last Row
                                        </button>
                                    </div>
                                    
                                    <!-- Summary Results Table -->
                                    <div style="margin-top: 20px; padding: 15px; border: 1px solid #bbb; border-radius: 6px; background: #fff;">
                                        <h4 style="margin: 0 0 15px 0; color: #2c3e50;">Summary Results</h4>
                                        
                                        <div style="overflow-x: auto;">
                                            <table style="width: 100%; border-collapse: collapse; border: 1px solid #ddd; font-size: 12px;">
                                                <thead>
                                                    <tr style="background: #f8f9fa;">
                                                        <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;">Parameter</th>
                                                        <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;">Formula</th>
                                                        <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;">Average Value</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center; font-weight: bold;">Permeability, k</td>
                                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">K = (a × L) / (A × t) × log₁₀(h₀/h₁) × RT</td>
                                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                                                            <input type="text" name="avg_permeability_${suffixId}" readonly id="avg_permeability_${suffixId}" style="width: 100%; padding: 4px; border: 1px solid #ddd; text-align: center; background: #f8f9fa; font-weight: bold;">
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center; font-weight: bold;">Flow Velocity, V₂₀</td>
                                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">V₂₀ = Δh/t × RT</td>
                                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                                                            <input type="text" name="avg_velocity_${suffixId}" readonly id="avg_velocity_${suffixId}" style="width: 100%; padding: 4px; border: 1px solid #ddd; text-align: center; background: #f8f9fa; font-weight: bold;">
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                        
                        // Auto-generate Lab Test No from Sample Reference ID
                        const sampleRef = document.getElementById('sample_reference_id')?.value || '';
                        const labTestField = document.getElementById(`iso11058_labtest_${suffixId}`);
                        if (labTestField && sampleRef) {
                            const match = sampleRef.match(/-(\d+)$/);
                            if (match) {
                                const num = parseInt(match[1]);
                                labTestField.value = num > 99 ? match[1].slice(-3) : match[1].slice(-2);
                            }
                        }
                        
                        iso11058_updateReport(suffixId);
                    } else {
                        // Regular parameter inputs - compact uniform card layout
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        const params = testConfig;
                        let html = '';
                        if (isCommonFieldsTest(testName, method)) {
                            html += buildCommonFieldsHtml(suffixId);
                        }
                        html += `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px;">
                        `;
                        
                        params.forEach(param => {
                            const isReadonly = param.readonly ? 'readonly' : '';
                            const value = param.value ? `value="${param.value}"` : '';
                            const calculated = param.calculated ? 'class="calculated-field"' : '';
                                    const placeholder = param.type === 'number' ? `` : `placeholder="Enter ${param.name.toLowerCase()}"`;
                            const unitText = param.unit ? ` (${param.unit})` : '';
                            const negativeGuard = param.type === 'number' ? 'oninput="if(this.value < 0) this.value = 0"' : '';
                            
                            html += `
                                <div style="display: flex; flex-direction: column; gap: 5px;">
                                    <label style="font-size: 12px; font-weight: bold;">${param.name}${unitText}:</label>
                                    <input type="${param.type}" 
                                           step="${param.step || ''}" 
                                           ${isReadonly} 
                                           ${value} 
                                           ${calculated}
                                           ${placeholder}
                                           style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;"
                                           ${negativeGuard}>
                                </div>
                            `;
                        });
                        
                        html += `
                                </div>
                            </div>
                        `;
						paramsContent.innerHTML = html;
						paramsDiv.style.display = 'block';
						
						// Initial compute of stats after render
						const anyInput = paramsDiv.querySelector('input[name^="wx_bf_after_"]');
						if (anyInput) {
                            recalcWeatheringStats(paramsDiv, suffixId);
                        }
                    }
                } else {
                    paramsContent.innerHTML = '<p style="color: red;">⚠️ No parameters defined for this test method.</p>';
                    paramsDiv.style.display = 'block';
                }
            } else {
                // Hide parameters
                paramsDiv.style.display = 'none';
            }
        }

        // Calculate averages for thickness test - show average in 4th row of each group
        function calculateThicknessAverages() {
            const table = document.querySelector('.test-parameters table tbody');
            if (!table) return;
            
            const rows = Array.from(table.querySelectorAll('tr.gsm-row'));
            
            // Group rows into groups of 4
            for (let groupIndex = 0; groupIndex < rows.length; groupIndex += 4) {
                const groupRows = rows.slice(groupIndex, groupIndex + 4);
                let groupTotal = 0, groupCount = 0;
                
                // Calculate average for this group
                groupRows.forEach((row, rowIdx) => {
                    const input = row.querySelector('.under2kpa');
                    const value = parseFloat(input?.value || 0);
                    if (value > 0) {
                        groupTotal += value;
                        groupCount += 1;
                    }
                });
                
                const groupAvg = (groupCount > 0) ? (groupTotal / groupCount).toFixed(3) : '0.000';
                
                // Find the last filled row in this group
                let lastFilledRowIndex = -1;
                groupRows.forEach((row, index) => {
                    const value = parseFloat(row.querySelector('.under2kpa')?.value || 0);
                    if (value > 0) {
                        lastFilledRowIndex = index;
                    }
                });
                
                // Show average only in the last filled row of this group
                if (lastFilledRowIndex >= 0 && groupRows[lastFilledRowIndex]) {
                    const avgCell = groupRows[lastFilledRowIndex].querySelector('.avgCell');
                    if (avgCell) {
                        avgCell.textContent = groupAvg;
                    }
                }
                
                // Clear averages for other rows in this group
                groupRows.forEach((row, index) => {
                    if (index !== lastFilledRowIndex) {
                        const avgCell = row.querySelector('.avgCell');
                        if (avgCell) {
                            avgCell.textContent = '';
                        }
                    }
                });
            }

            // Calculate Average, SD, CV%, Max, Min
            const validValues = Array.from(rows)
                .map(row => parseFloat(row.querySelector('.under2kpa')?.value || 0))
                .filter(v => v > 0);
            
            if (validValues.length > 0) {
                // Average
                const overallAvg = (validValues.reduce((a,b)=>a+b,0) / validValues.length).toFixed(3);
                const avgNum = parseFloat(overallAvg);
                const avgHidden = document.getElementById('astmd5199_avg');
                if (avgHidden) avgHidden.value = overallAvg;
                const avgOut = document.getElementById('thk_summary_avg');
                if (avgOut) avgOut.value = overallAvg;
                
                // Standard Deviation
                const squareDiffs = validValues.map(val => Math.pow(val - avgNum, 2));
                const variance = squareDiffs.reduce((a, b) => a + b, 0) / validValues.length;
                const sd = Math.sqrt(variance);
                const sdStr = sd.toFixed(3);
                const sdHidden = document.getElementById('astmd5199_sd');
                if (sdHidden) sdHidden.value = sdStr;
                const sdOut = document.getElementById('thk_summary_sd');
                if (sdOut) sdOut.value = sdStr;
                
                // CV%
                const cv = (sd / (avgNum || 1)) * 100;
                const cvStr = cv.toFixed(2);
                const cvHidden = document.getElementById('astmd5199_cv');
                if (cvHidden) cvHidden.value = cvStr;
                const cvOut = document.getElementById('thk_summary_cv');
                if (cvOut) cvOut.value = cvStr;
                
                // Max and Min
                const max = Math.max(...validValues);
                const min = Math.min(...validValues);
                const maxStr = max.toFixed(3);
                const minStr = min.toFixed(3);
                const maxHidden = document.getElementById('astmd5199_max');
                if (maxHidden) maxHidden.value = maxStr;
                const minHidden = document.getElementById('astmd5199_min');
                if (minHidden) minHidden.value = minStr;
                const maxOut = document.getElementById('thk_summary_max');
                if (maxOut) maxOut.value = maxStr;
                const minOut = document.getElementById('thk_summary_min');
                if (minOut) minOut.value = minStr;
            }
        }

        // Group-wise average that updates only the last row in each group
        document.addEventListener('input', function(e) {
            if (e.target.classList && (e.target.classList.contains('weight') || e.target.classList.contains('position'))) {
                calculateGroupWiseAverage();
            }
        });

        function getGroupKey(positionValue) {
            if (!positionValue) return '';
            if (positionValue.startsWith('Middle Left')) return 'Middle Left';
            if (positionValue.startsWith('Middle Right')) return 'Middle Right';
            if (positionValue.startsWith('Left')) return 'Left';
            if (positionValue.startsWith('Right')) return 'Right';
            return '';
        }

        function calculateGroupWiseAverage() {
            const rows = Array.from(document.querySelectorAll('#gsmTable .gsm-row'));

            // Clear all avg cells first
            rows.forEach(r => { const c = r.querySelector('.gsm-avg'); if (c) c.textContent = ''; });

            const group = {
                'Left': { values: [], lastRow: null },
                'Middle Left': { values: [], lastRow: null },
                'Middle Right': { values: [], lastRow: null },
                'Right': { values: [], lastRow: null }
            };

            rows.forEach(r => {
                const posVal = (r.querySelector('.position')?.value || '').trim();
                const key = getGroupKey(posVal);
                if (!key) return;
                const val = parseFloat(r.querySelector('.weight')?.value || '');
                if (!isNaN(val) && val > 0) group[key].values.push(val);
                group[key].lastRow = r; // track last occurrence
            });

            Object.keys(group).forEach(k => {
                const { values, lastRow } = group[k];
                if (!lastRow) return;
                const cell = lastRow.querySelector('.gsm-avg');
                if (!cell) return;
                if (values.length === 0) { cell.textContent = '0.0'; return; }
                const avg = values.reduce((a,b)=>a+b,0)/values.length;
                cell.textContent = avg.toFixed(1);
            });
        }

        function calculateGsmSummary(values) {
            // Filter valid values (greater than 0)
            const validValues = values.filter(v => v > 0);
            
            // GSM summary not needed; no-op to avoid errors
                return;
            }
            
        function addGsmRowAfter(button) {
            const buttonRow = button.closest('tr');
            if (!buttonRow) return;
            const tbody = buttonRow.closest('tbody');
            if (!tbody) return;
            const dataRow = buttonRow.previousElementSibling;
            if (!dataRow) return;
            const firstDataRow = tbody.querySelector('tr');

            // Build a new data row (plain inputs)
            const newDataRow = document.createElement('tr');
            const isAfterFirst = (dataRow === firstDataRow);
            const positionCellHtml = isAfterFirst
                ? `<select style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                     <option value="Left-1">Left-1</option>
                     <option value="Left-2">Left-2</option>
                     <option value="Left-3">Left-3</option>
                     <option value="Left-4">Left-4</option>
                   </select>`
                : `<input type="text" placeholder="Position" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">`;

            newDataRow.innerHTML = `
                <td style="border: 1px solid #ddd; padding: 6px;">${positionCellHtml}</td>
                <td style="border: 1px solid #ddd; padding: 6px;">
                    <input type="number" class="weight" step="any" max="1000" min="0" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" onchange="calculateGroupWiseAverage()" oninput="if(this.value < 0) this.value = 0">
                </td>
                <td style="border: 1px solid #ddd; padding: 6px; text-align:center; background:#f8f9fa;"><span class="gsm-avg" style="font-weight:bold; color:#27ae60;">0.0</span></td>
            `;

            // If not the first row, inherit group label from previous data row for averaging
            if (!isAfterFirst) {
                const prevSelect = dataRow.querySelector('td:first-child select');
                if (prevSelect) {
                    newDataRow.setAttribute('data-inherited-group', prevSelect.options[prevSelect.selectedIndex]?.text || '');
                }
            }
            // Insert the new data row BEFORE the clicked button row
            tbody.insertBefore(newDataRow, buttonRow);
        }

        function addGsmGroup() {
            const tbody = document.querySelector('.test-parameters table tbody');
            if (!tbody) return;
            const currentRows = tbody.querySelectorAll('tr').length;
            const startPos = currentRows + 1;
            const groupNumber = Math.ceil(currentRows / 4) + 1;

            for (let i = 0; i < 4; i++) {
                const pos = startPos + i;
                const isEnd = (i === 3);
                const row = document.createElement('tr');
                // Determine group index (1-based)
                const groupIdx = Math.ceil(pos / 4);
                // For group 2 (rows 5-8), use Middle Left dropdown in first row of that group
                const positionCellHtml = (() => {
                    const idxInGroup = ((pos - 1) % 4) + 1;
                    if (groupIdx === 2 && idxInGroup === 1) {
                        return `<select style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                  <option value="Middle Left-1">Middle Left-1</option>
                                  <option value="Middle Left-2">Middle Left-2</option>
                                  <option value="Middle Left-3">Middle Left-3</option>
                                  <option value="Middle Left-4">Middle Left-4</option>
                                </select>`;
                    }
                    return `<input type="text" placeholder="Position ${pos}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">`;
                })();
                row.innerHTML = `
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        ${positionCellHtml}
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        <input type="number" class="weight" step="any" min="0" max="1000" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" onchange="calculateGroupWiseAverage()" oninput="this.value = Math.max(0, this.value)"> 
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align:center; background:#f8f9fa;">
                        <span class="gsm-avg" style="font-weight:bold; color:#27ae60;">0.0</span>
                    </td>
                `;
                tbody.appendChild(row);
            }
            
            // Recalculate summary after adding rows
            const table = tbody.closest('table');
            const rows = table.querySelectorAll('tbody tr');
            const values = Array.from(rows).map(r => {
                const inp = r.querySelector('.weight');
                const v = parseFloat(inp?.value || '0');
                return isNaN(v) ? 0 : v;
            });
            calculateGsmSummary(values);
        }

        // Calculate Force Retain (%) = After / Before * 100 with 1 decimal, per row
        function calculateForceRetainWeathering(changedInput) {
            const rowIndex = changedInput.getAttribute('data-row');
            const suffix = changedInput.getAttribute('data-suffix');
            if (!rowIndex || !suffix) return;
            const container = changedInput.closest('.test-parameters');
            if (!container) return;
            const afterInput = container.querySelector(`input[name="wx_bf_after_${suffix}_${rowIndex}"]`);
            const beforeInput = container.querySelector(`input[name="wx_bf_before_${suffix}_${rowIndex}"]`);
            const frSingle = container.querySelector(`input[name="wx_fr_${suffix}_${rowIndex}"]`);

            const afterVal = parseFloat(afterInput?.value || '0');
            const beforeVal = parseFloat(beforeInput?.value || '0');
            let fr = 0;
            if (beforeVal > 0) {
                fr = (afterVal / beforeVal) * 100;
            }
            if (frSingle) frSingle.value = fr.toFixed(1);
            // Recompute aggregate statistics
            const holder = changedInput.closest('.test-parameters');
            if (holder) {
                const suffixEl = holder.querySelector('input[name^="wx_bf_after_"]');
                if (suffixEl) {
                    const m = suffixEl.name.match(/^wx_bf_after_(.+?)_\d+$/);
                    if (m && m[1]) recalcWeatheringStats(holder, m[1]);
                }
            }
        }

        // Compute Average, SD, CV, Max, Min for Weathering table columns
        function recalcWeatheringStats(container, suffix) {
            const collect = (sel) => Array.from(container.querySelectorAll(sel))
                .map(i => parseFloat(i.value || i.textContent || ''))
                .filter(v => !isNaN(v));
            const stats = (arr) => {
                const n = arr.length;
                if (!n) return {avg:'', sd:'', cv:'', max:'', min:''};
                const sum = arr.reduce((a,b)=>a+b,0);
                const avg = sum / n;
                const variance = n > 1 ? arr.reduce((s,v)=> s + Math.pow(v-avg,2),0) / (n-1) : 0;
                const sd = Math.sqrt(variance);
                const cv = avg !== 0 ? (sd/avg)*100 : 0;
                return {avg, sd, cv, max: Math.max(...arr), min: Math.min(...arr)};
            };
            const set = (idSuffix, obj, digits=1) => {
                const map = { 'average':'avg', 'sd':'sd', 'cv':'cv', 'maximum':'max', 'minimum':'min' };
                Object.keys(map).forEach(k => {
                    const el = container.querySelector(`#wx_stat_${idSuffix}_${suffix}_${k}`) || container.querySelector(`#wx_stat_${idSuffix}_${suffix}_${k}`);
                    const target = container.querySelector(`#wx_stat_${idSuffix}_${suffix}_${k}`);
                    if (target) target.textContent = (obj[map[k]] ?? '').toString() ? (obj[map[k]].toFixed(digits)) : '';
                });
            };

            const bfAfter = collect(`input[name^="wx_bf_after_${suffix}_"]`);
            const bfBefore = collect(`input[name^="wx_bf_before_${suffix}_"]`);
            const frVals = collect(`input[name^="wx_fr_${suffix}_"]`).map(v => parseFloat(v));
            const elAfter = collect(`input[name^="wx_el_after_${suffix}_"]`);
            const elBefore = collect(`input[name^="wx_el_before_${suffix}_"]`);

            const s1 = stats(bfAfter); set('bf_after', s1, 1);
            const s2 = stats(bfBefore); set('bf_before', s2, 1);
            // Update Test Result summary: use averages with 2 decimal retain
            const afterEl = container.querySelector(`#wx_result_after_${suffix}`);
            const beforeEl = container.querySelector(`#wx_result_before_${suffix}`);
            const retainEl = container.querySelector(`#wx_result_retain_${suffix}`);
            if (afterEl) afterEl.textContent = (typeof s1.avg === 'number') ? s1.avg.toFixed(1) : '';
            if (beforeEl) beforeEl.textContent = (typeof s2.avg === 'number') ? s2.avg.toFixed(1) : '';
            const retain = (s1.avg && s2.avg) ? ((s2.avg / s1.avg) * 100) : 0;
            if (retainEl) retainEl.textContent = isFinite(retain) ? retain.toFixed(2) : '';
            const s3 = stats(frVals); // display only avg,max,min for FR
            // Write FR stats selectively
            const writeFr = (key, val) => {
                const el = container.querySelector(`#wx_stat_fr_${suffix}_${key}`);
                if (el) el.textContent = (typeof val === 'number') ? val.toFixed(1) : '';
            };
            writeFr('average', s3.avg);
            writeFr('maximum', s3.max);
            writeFr('minimum', s3.min);

            const s4 = stats(elAfter); set('el_after', s4, 1);
            const s5 = stats(elBefore); set('el_before', s5, 1);
        }

        // Calculate ISO 12956 Sieve Analysis Data
        function calcIso12956(suffixId) {
            const table = document.getElementById(`iso12956_table_${suffixId}`);
            if (!table) return;

            const weightInput = document.querySelector(`[name="iso12956_sandweight_${suffixId}"]`);
            const totalWeight = parseFloat(weightInput?.value || '0');
            if (totalWeight <= 0) return;

            let cumulative = 0;
            for (let i = 1; i <= 8; i++) {
                const retainedVal = parseFloat(document.querySelector(`[name="iso12956_retained_${suffixId}_${i}"]`)?.value || '0');
                cumulative += retainedVal;
                const cumulativePassing = ((totalWeight - cumulative) / totalWeight) * 100;

                // Round up to one decimal place using Math.ceil
                const cumulativeRounded = Math.ceil(cumulative * 10) / 10;
                const cumulativePassingRounded = Math.ceil(cumulativePassing * 10) / 10;

                document.getElementById(`iso12956_cum_${suffixId}_${i}`).textContent = cumulativeRounded.toFixed(1);
                document.getElementById(`iso12956_pass_${suffixId}_${i}`).textContent = cumulativePassingRounded.toFixed(1);
            }
        }

        // Generate Sample ID for ISO 12956 test
        function generateSampleId(suffixId) {
            const gsmInput = document.querySelector(`[name="iso12956_gsm_${suffixId}"]`);
            const lineInput = document.querySelector(`[name="iso12956_line_${suffixId}"]`);
            const rollInput = document.querySelector(`[name="iso12956_roll_${suffixId}"]`);
            const sampleIdInput = document.querySelector(`[name="iso12956_sampleid_${suffixId}"]`);
            
            if (!gsmInput || !lineInput || !rollInput || !sampleIdInput) return;
            
            const gsm = parseFloat(gsmInput.value || '0');
            const line = lineInput.value.trim();
            const roll = rollInput.value.trim();
            
            // Only generate Sample ID if all required fields have meaningful values
            if (gsm > 0 && line && roll) {
                const now = new Date();
                const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                const month = monthNames[now.getMonth()];
                const day = String(now.getDate()).padStart(2, '0');
                
            // Generate Sample ID: GSM.0L{LabTestNo}{month}{day}-LT{last2OfLabTestNo}-R{roll}
            // Example: GSM=400, LabTestNo=GEOCIL-LAB-TR-20251005-001, Roll=25 => 4.0LGEOCIL-LAB-TR-20251005-001Oct05-LT01-R25
            const gsmDecimal = (gsm / 100).toFixed(1);
            const lastTwo = (line.slice(-2) || '').padStart(2, '0');
            const sampleId = `${gsmDecimal}L${line}${month}${day}-LT${lastTwo}-R${roll}`;
                
                sampleIdInput.value = sampleId;
            } else {
                // Clear the Sample ID if any required field is empty
                sampleIdInput.value = '';
            }
        }

        // Add event listeners for ISO 12956 fields to update Sample ID
        function addIso12956EventListeners(suffixId) {
            const gsmInput = document.querySelector(`[name="iso12956_gsm_${suffixId}"]`);
            const lineInput = document.querySelector(`[name="iso12956_line_${suffixId}"]`);
            const rollInput = document.querySelector(`[name="iso12956_roll_${suffixId}"]`);
            
            // Auto-generate Lab Test No from Sample Reference ID (last 2-3 digits)
            const sampleRefEl = document.getElementById('sample_reference_id');
            const syncLabTestNo = () => { 
                if (lineInput && sampleRefEl) {
                    const sampleRef = sampleRefEl.value;
                    const match = sampleRef.match(/-(\d+)$/);
                    if (match) {
                        const num = parseInt(match[1]);
                        lineInput.value = num > 99 ? match[1].slice(-3) : match[1].slice(-2);
                    }
                }
            };
            syncLabTestNo();
            if (sampleRefEl) sampleRefEl.addEventListener('input', syncLabTestNo);
            
            if (gsmInput) gsmInput.addEventListener('input', () => generateSampleId(suffixId));
            if (rollInput) rollInput.addEventListener('input', () => generateSampleId(suffixId));
        }

        // Calculate Apparent Opening Size (O-values) automatically
        function calculateOpeningSize(suffixId) {
            // Get user input for O-value
            const oValueInput = document.getElementById(`o_value_input_${suffixId}`);
            const targetPercent = parseFloat(oValueInput.value);
            
            if (isNaN(targetPercent) || targetPercent < 0 || targetPercent > 100) {
                alert('Please enter a valid O-value between 0 and 100.');
                return;
            }
            
            // Predefined sieve sizes (mm) for standard test sieves
            const sieveSizes = [0.150, 0.125, 0.090, 0.075, 0.063, 0.040];
            
            // Get cumulative passing values from the table
            const cumulativePassing = [];
            for (let i = 1; i <= 8; i++) {
                const passingElement = document.getElementById(`iso12956_pass_${suffixId}_${i}`);
                if (passingElement && passingElement.textContent.trim() !== '0.0') {
                    const passingValue = parseFloat(passingElement.textContent);
                    if (!isNaN(passingValue)) {
                        cumulativePassing.push(passingValue);
                    }
                }
            }
            
            if (cumulativePassing.length === 0) {
                alert('Please enter sieve data and calculate cumulative passing values first.');
                return;
            }
            
            // Calculate the O-value with detailed process
            const result = calculateOpeningSizeWithDetails(sieveSizes, cumulativePassing, targetPercent);
            
            // Display detailed results
            displayDetailedOpeningSizeResults(suffixId, targetPercent, result);
        }
        
        // Calculate Opening Size with detailed process information
        function calculateOpeningSizeWithDetails(sieveSizes, cumulativePassing, targetPercent) {
            // Combine sieve size and passing %
            const data = [];
            for (let i = 0; i < Math.min(sieveSizes.length, cumulativePassing.length); i++) {
                data.push([sieveSizes[i], cumulativePassing[i]]);
            }
            
            // Sort data descending by sieve size
            data.sort((a, b) => b[0] - a[0]);
            
            // Find where target lies using linear interpolation
            for (let i = 0; i < data.length - 1; i++) {
                const [size1, pass1] = data[i];
                const [size2, pass2] = data[i + 1];
                
                if ((pass1 >= targetPercent && targetPercent >= pass2) ||
                    (pass2 >= targetPercent && targetPercent >= pass1)) {
                    
                    // Linear interpolation
                    const openingSize = size2 + ((targetPercent - pass2) / (pass1 - pass2)) * (size1 - size2);
                    const roundedSize = Math.round(openingSize * 1000) / 1000;
                    
                    return {
                        found: true,
                        size1: size1,
                        pass1: pass1,
                        size2: size2,
                        pass2: pass2,
                        target: targetPercent,
                        result: roundedSize,
                        formula: `${size2} + ((${targetPercent} - ${pass2}) / (${pass1} - ${pass2})) * (${size1} - ${size2})`
                    };
                }
            }
            
            return { found: false };
        }
        
        // Display detailed opening size calculation results
        function displayDetailedOpeningSizeResults(suffixId, targetPercent, result) {
            const resultsDiv = document.getElementById(`opening_size_results_${suffixId}`);
            const detailsDiv = document.getElementById(`calculation_details_${suffixId}`);
            const finalResultDiv = document.getElementById(`final_result_${suffixId}`);
            
            if (!resultsDiv || !detailsDiv || !finalResultDiv) return;
            
            if (!result.found) {
                detailsDiv.innerHTML = `<span style="color: #e74c3c;">O${targetPercent} could not be calculated from the given data.</span>`;
                finalResultDiv.innerHTML = `<span style="color: #e74c3c;">Apparent Opening Size(O${targetPercent}): Could not be calculated</span>`;
                resultsDiv.style.display = 'block';
                return;
            }
            
            // Calculate the actual interpolation result
            const calculationResult = result.size2 + ((result.target - result.pass2) / (result.pass1 - result.pass2)) * (result.size1 - result.size2);
            
            // Create detailed calculation display
            let detailsHTML = '';
            detailsHTML += `O${targetPercent} lies between sieve size ${result.size1} mm (${result.pass1}% passing) and ${result.size2} mm (${result.pass2}% passing).<br><br>`;
            detailsHTML += `Interpolation formula:<br>`;
            detailsHTML += `${result.formula}<br><br>`;
            detailsHTML += `<strong>O${targetPercent} = ${result.result} mm (${(result.result * 1000).toFixed(0)} µm)</strong>`;
            
            // Create final result display
            const finalResultHTML = `Apparent Opening Size(O${targetPercent}): ${result.result} mm (${(result.result * 1000).toFixed(0)} µm)`;
            
            detailsDiv.innerHTML = detailsHTML;
            finalResultDiv.innerHTML = finalResultHTML;
            resultsDiv.style.display = 'block';
        }

        function addCbrGroup() {
            const table = document.querySelector('.test-parameters table tbody');
            if (!table) return;
            const currentRows = table.querySelectorAll('tr').length;
            const startPos = currentRows + 1;
            for (let i = 0; i < 4; i++) {
                const pos = startPos + i;
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        <input type="text" placeholder="Position ${pos}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        <input type="number" step="any" max="1000" min="0" placeholder="0.0000" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        <input type="number" step="any" max="1000" min="0" placeholder="0.0000" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                    </td>
                `;
                table.appendChild(row);
            }
        }

        function addGrabGroup() {
            const table = document.querySelector('.test-parameters table tbody');
            if (!table) return;
            const currentRows = table.querySelectorAll('tr').length;
            const startPos = currentRows + 1;
            for (let i = 0; i < 4; i++) {
                const pos = startPos + i;
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td style=\"border: 1px solid #ddd; padding: 8px;\">\n                        <input type=\"text\" placeholder=\"Position ${pos}\" style=\"width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;\">\n                    </td>\n                    <td style=\"border: 1px solid #ddd; padding: 8px;\">\n                        <input type=\"number\" step=\"any\" max=\"1000\" min=\"0\" placeholder=\"0.0000\" style=\"width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;\" oninput=\"if(this.value < 0) this.value = 0\">\n                    </td>\n                    <td style=\"border: 1px solid #ddd; padding: 8px;\">\n                        <input type=\"number\" step=\"any\" max=\"1000\" min=\"0\" placeholder=\"0.0000\" style=\"width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;\" oninput=\"if(this.value < 0) this.value = 0\">\n                    </td>`;
                table.appendChild(row);
            }
        }

        // Weathering: toggle MD/CD selection buttons per row
        function setWxDirection(button, dir) {
            const row = button.getAttribute('data-row');
            const suffix = button.getAttribute('data-suffix');
            if (!row || !suffix) return;
            const container = button.closest('td');
            const all = container.querySelectorAll('.dir-btn');
            all.forEach(b => { b.style.background = '#fff'; b.style.color = '#2c3e50'; });
            // highlight selected
            button.style.background = '#007bff';
            button.style.color = '#fff';
            const hidden = container.querySelector(`input[name="wx_dir_${suffix}_${row}"]`);
            if (hidden) hidden.value = dir;
        }
        
        function addWxRow(suffixId) {
            const tbody = document.getElementById(`wx_tbody_${suffixId}`);
            const currentRows = tbody.querySelectorAll('tr');
            const newRowNum = currentRows.length + 1;
            
            const row = document.createElement('tr');
            row.innerHTML = `
                <td style="border:1px solid #ddd; padding:6px; text-align:center;">${newRowNum}</td>
                <td style="border:1px solid #ddd; padding:6px; text-align:center;">
                    <div style="display:inline-flex; gap:6px; align-items:center;">
                        <button type="button" class="dir-btn" data-row="${newRowNum}" data-suffix="${suffixId}" onclick="setWxDirection(this,'MD')" style="padding:4px 10px; border:1px solid #ccc; border-radius:4px; background:#007bff; color:#fff;">MD${newRowNum}</button>
                        <button type="button" class="dir-btn" data-row="${newRowNum}" data-suffix="${suffixId}" onclick="setWxDirection(this,'CD')" style="padding:4px 10px; border:1px solid #ccc; border-radius:4px; background:#fff; color:#2c3e50;">CD${newRowNum}</button>
                        <input type="hidden" name="wx_dir_${suffixId}_${newRowNum}" value="MD">
                    </div>
                </td>
                <td style="border:1px solid #ddd; padding:6px;"><input type="number" step="any" min="0" max="1000" name="wx_bf_after_${suffixId}_${newRowNum}" data-row="${newRowNum}" data-suffix="${suffixId}" class="wx_bf_after" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0; calculateForceRetainWeathering(this)"></td>
                <td style="border:1px solid #ddd; padding:6px;"><input type="number" step="any" min="0" max="1000" name="wx_bf_before_${suffixId}_${newRowNum}" data-row="${newRowNum}" data-suffix="${suffixId}" class="wx_bf_before" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0; calculateForceRetainWeathering(this)"></td>
                <td style="border:1px solid #ddd; padding:6px;"><input type="text" name="wx_fr_${suffixId}_${newRowNum}" class="wx_fr" readonly value="" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px; background:#f8f9fa;"></td>
                <td style="border:1px solid #ddd; padding:6px;"><input type="number" step="any" min="0" max="1000" name="wx_el_after_${suffixId}_${newRowNum}" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0"></td>
                <td style="border:1px solid #ddd; padding:6px;"><input type="number" step="any" min="0" max="1000" name="wx_el_before_${suffixId}_${newRowNum}" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0"></td>
                <td style="border:1px solid #ddd; padding:6px;"><button type="button" onclick="removeWxRow(this, '${suffixId}')" style="padding:4px 8px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:pointer;">Remove</button></td>
            `;
            tbody.appendChild(row);
        }
        
        function removeWxRow(button, suffixId) {
            const row = button.closest('tr');
            const tbody = row.closest('tbody');
            
            // Check if this is the only row
            if (tbody.querySelectorAll('tr').length <= 1) {
                alert('You cannot remove the last row. At least one row is required.');
                return;
            }
            
            row.remove();
            
            // Renumber remaining rows
            const rows = tbody.querySelectorAll('tr');
            rows.forEach((r, idx) => {
                const rowNum = idx + 1;
                r.cells[0].textContent = rowNum;
                
                // Update all attributes with new row number
                const inputs = r.querySelectorAll('input[name*="' + rowNum + '"]');
                r.querySelectorAll('[data-row], [name*="' + rowNum + '"]').forEach(el => {
                    if (el.hasAttribute('name')) {
                        el.setAttribute('name', el.getAttribute('name').replace(/_\d+(?=.*)/, '_' + rowNum));
                    }
                    if (el.hasAttribute('data-row')) {
                        el.setAttribute('data-row', rowNum);
                    }
                });
                
                // Update button text
                const mdBtn = r.querySelector('.dir-btn[onclick*="MD"]');
                const cdBtn = r.querySelector('.dir-btn[onclick*="CD"]');
                if (mdBtn) mdBtn.textContent = 'MD' + rowNum;
                if (cdBtn) cdBtn.textContent = 'CD' + rowNum;
            });
        }

        // Compute MD:CD ratio per MD/CD pair within a 4-row group
        function updateMdCdRatio(changedInput) {
            const row = changedInput.closest('tr');
            const tbody = row.closest('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.querySelector('.strength_input'));
            const index = rows.indexOf(row);
            if (index < 0) return;
            const groupStart = Math.floor(index / 4) * 4;

            const computeAndSetForPair = (mdRow, cdRow) => {
                if (!mdRow || !cdRow) return;
                const mdVal = parseFloat(mdRow.querySelector('.strength_input')?.value || '0') || 0;
                const cdVal = parseFloat(cdRow.querySelector('.strength_input')?.value || '0') || 0;
                const display = (mdVal > 0) ? `1:${(cdVal / mdVal).toFixed(2)}` : '1:0.00';
                const mdRatioEl = mdRow.querySelector('.mdcd_ratio');
                const cdRatioEl = cdRow.querySelector('.mdcd_ratio');
                if (mdRatioEl) mdRatioEl.textContent = '';
                if (cdRatioEl) cdRatioEl.textContent = display; // show in one row (CD row)
            };

            // First MD/CD pair (rows groupStart, groupStart+1)
            computeAndSetForPair(rows[groupStart], rows[groupStart + 1]);
            // Second MD/CD pair (rows groupStart+2, groupStart+3)
            computeAndSetForPair(rows[groupStart + 2], rows[groupStart + 3]);
        }

        function addStripGroup() {
            const table = document.querySelector('.test-parameters table');
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const existing = Array.from(tbody.querySelectorAll('tr')).filter(r => r.querySelector('input,span'));
            const currentRows = existing.filter(r => r.querySelector('.strength_input')).length;
            const startIndex = currentRows + 1;
            for (let i = 0; i < 4; i++) {
                const pos = startIndex + i;
                const dir = ((pos % 2) === 1) ? 'MD' : 'CD';
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        <input type="text" placeholder="Position ${pos}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align:center;">
                        <input type="text" value="${dir}" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; text-align:center;">
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px;">
                                            <input type="number" step="any" max="1000" min="0" class="strength_input" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" onchange="updateMdCdRatio(this)" oninput="if(this.value < 0) this.value = 0"> 
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align:center; background:#f8f9fa;">
                        <span class="mdcd_ratio">0</span>
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        <input type="number" step="any" max="1000" min="0" class="elong_input" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                    </td>
                `;
                tbody.appendChild(tr);
                if (i === 1) {
                    const part = document.createElement('tr');
                    part.innerHTML = `<td colspan="5" style="border-top: 2px dashed #bbb; height: 1px;"></td>`;
                    tbody.appendChild(part);
                }
            }
            const gpart = document.createElement('tr');
            gpart.innerHTML = `<td colspan="5" style="border-top: 2px solid #999; height: 1px;"></td>`;
            tbody.appendChild(gpart);
        }

        // Check if selected test requires common sample/test fields
        function isCommonFieldsTest(testName, method) {
            const key = `${testName}|||${method}`.toLowerCase();
            const allowed = new Set([
                'mass per unit area (gsm)|||astm d5261',
                'mass per unit area (gsm)|||iso 9864',
                'strip tensile test|||astm d4595',
                'strip tensile test|||iso 10319'
            ]);
            return allowed.has(key);
        }

        // Build the common fields block HTML
        function buildCommonFieldsHtml(suffixId) {
            const now = new Date();
            const yyyy = now.getFullYear();
            const mm = String(now.getMonth() + 1).padStart(2, '0');
            const dd = String(now.getDate()).padStart(2, '0');
            const isoNow = `${yyyy}-${mm}-${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;

            return `
                <div class="form-section" style="margin-top: 10px;">
                    
                    <div class="form-row" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:12px;">
                        <div class="form-group"><label>Sample Details</label><input type="text" name="sample_details_${suffixId}" required></div>
                        <div class="form-group"><label>Batch Information</label><input type="text" id="batch_${suffixId}" name="batch_info_${suffixId}" placeholder="GT9.H1" oninput="this.value=this.value.toUpperCase(); updateProductReference('${suffixId}')" required></div>
                    </div>
                    <div class="form-row" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:12px;">
                        <div class="form-group"><label>Sample Collected From</label><input type="text" name="sample_collected_from_${suffixId}" required></div>
                        <div class="form-group"><label>Sample Received Date & Time</label><input type="datetime-local" name="sample_received_dt_${suffixId}" value="${yyyy}-${mm}-${dd}T${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}" required></div>
                    </div>
                    <div class="form-row" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:12px;">
                        <div class="form-group"><label>Sample Production Date</label><input type="date" name="sample_production_date_${suffixId}" style="padding:8px; border:1px solid #ddd; border-radius:6px;" required></div>
                        <div class="form-group"><label>Temperature (°C)</label><input type="number" step="any" max="1000" min="0" name="temperature_c_${suffixId}" style="padding:8px; border:1px solid #ddd; border-radius:6px;" required></div>
                        <div class="form-group"><label>RH (%)</label><input type="number" step="any" max="1000" min="0" name="rh_percent_${suffixId}" style="padding:8px; border:1px solid #ddd; border-radius:6px;" required></div>
                        <div class="form-group">
                            <label>Test Period</label>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <input type="date" name="test_period_from_${suffixId}" style="padding:8px; border:1px solid #ddd; border-radius:6px;" required>
                                <span style="color:#6c757d;">to</span>
                                <input type="date" name="test_period_to_${suffixId}" style="padding:8px; border:1px solid #ddd; border-radius:6px;" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-row" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:12px;">
                        <div class="form-group"><label>GSM</label><input type="number" step="any" max="1000" min="0" id="gsm_${suffixId}" name="gsm_${suffixId}" oninput="updateProductReference('${suffixId}')" required></div>
                        <div class="form-group"><label>Lab Test No</label><input type="text" id="line_${suffixId}" name="line_${suffixId}" readonly class="readonly"></div>
                        <div class="form-group"><label>Roll Number</label><input type="text" id="roll_${suffixId}" name="roll_${suffixId}" oninput="updateProductReference('${suffixId}')" required></div>
                    </div>
                    <div class="form-row" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:12px;">
                        <div class="form-group"><label>Product Reference</label><input type="text" id="product_ref_${suffixId}" name="product_ref_${suffixId}" readonly class="readonly"></div>
                        <div class="form-group"><label>Customer Reference</label><input type="text" name="customer_ref_${suffixId}" required></div>
                        <div class="form-group"><label>Sample Received From</label><input type="text" name="sample_received_from_detail_${suffixId}" required></div>
                    </div>
                    <div class="form-row" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:12px;">
                        <div class="form-group"><label>Lighthouse Reference (Optional)</label><input type="text" name="lighthouse_ref_${suffixId}"></div>
                        <div class="form-group" style="grid-column: 1 / -1;"><label>Other Information (Optional)</label><textarea name="other_info_${suffixId}" rows="2" style="width:100%;" maxlength="255"></textarea></div>
                    </div>
                </div>
            `;
        }

        // Auto-generate Product Reference using the provided formula
        async function updateProductReference(suffixId) {
            // Auto-generate Lab Test No - fetch current count from server
            const labTestNoField = document.getElementById(`line_${suffixId}`);
            if (labTestNoField) {
                try {
                    const response = await fetch('get_next_lab_test_number.php');
                    const data = await response.json();
                    if (data.lab_test_number) {
                        labTestNoField.value = data.lab_test_number;
                    }
                } catch (error) {
                    // Fallback: use sample reference ID
                    const sampleRef = document.getElementById('sample_reference_id')?.value || '';
                    const match = sampleRef.match(/-(\d+)$/);
                    if (match) {
                        const num = parseInt(match[1]);
                        labTestNoField.value = num > 99 ? match[1].slice(-3) : match[1].slice(-2);
                    }
                }
            }
            
            const gsmVal = parseFloat(document.getElementById(`gsm_${suffixId}`)?.value || '0');
            const lineVal = (document.getElementById(`line_${suffixId}`)?.value || '').toString().trim();
            const rollVal = (document.getElementById(`roll_${suffixId}`)?.value || '').toString().trim();
            const batchVal = (document.getElementById(`batch_${suffixId}`)?.value || '').toString().trim();

            const now = new Date();
            const year = now.getFullYear();
            const yearShort = String(year).slice(-2); // Last 2 digits of year (e.g., 25 for 2025)
            const monthNames = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
            const mon3 = monthNames[now.getMonth()];
            const dd = String(now.getDate()).padStart(2, '0');

            // Format: {GSM/100}.0L{yearShort}{month}{date}-R{roll}-{batch decimated}
            // Example: 3.0L25OCT09-R23-GT0.9H0.1
            const gsmFormatted = (gsmVal / 100).toFixed(1); // 300 -> 3.0
            const decimateBatch = (val) => {
                if (!val) return '';
                // Replace digit sequences with 0.x form preserving separators/letters
                return val.replace(/(\d+)(?=([^\d]|$))/g, (m) => {
                    const n = parseInt(m, 10);
                    if (isNaN(n)) return m;
                    return '0.' + n;
                });
            };
            const batchPart = decimateBatch(batchVal.toUpperCase());
            const ref = `${gsmFormatted}L${yearShort}${mon3}${dd}-R${rollVal || '0'}-${batchPart}`;
            const target = document.getElementById(`product_ref_${suffixId}`);
            if (target) target.value = ref;
        }

        // Row counter for thickness test
        let thicknessRowCounter = 1;

        // Helper function to get all selected positions
        function getSelectedThicknessPositions() {
            const selected = [];
            const selects = document.querySelectorAll('[id^="astmd5199_position_"]');
            selects.forEach(select => {
                if (select.value) {
                    selected.push(select.value);
                }
            });
            return selected;
        }

        // Helper function to generate position options (excluding selected ones)
        function generateThicknessPositionOptions(currentIndex) {
            const positionOptions = ['Left-1', 'Left-2', 'Left-3', 'Left-4'];
            const selected = getSelectedThicknessPositions();
            const currentSelect = document.getElementById('astmd5199_position_' + currentIndex);
            const currentValue = currentSelect ? currentSelect.value : '';
            
            return positionOptions.map(opt => {
                if (selected.includes(opt) && opt !== currentValue) {
                    return '';
                }
                return `<option value="${opt}">${opt}</option>`;
            }).join('');
        }

        // Update all dropdowns to show all positions (allow any position in any row)
        function updateThicknessDropdowns() {
            const selects = document.querySelectorAll('[id^="astmd5199_position_"]');
            const selected = getSelectedThicknessPositions();
            
            selects.forEach((select) => {
                const currentValue = select.value;
                
                // All position options available
                const allOptions = [
                    'Left-1', 'Left-2', 'Left-3', 'Left-4',
                    'Middle Left-1', 'Middle Left-2', 'Middle Left-3', 'Middle Left-4',
                    'Middle Right-1', 'Middle Right-2', 'Middle Right-3', 'Middle Right-4',
                    'Right-1', 'Right-2', 'Right-3', 'Right-4'
                ];
                
                // Filter out positions already selected in OTHER rows
                const availableOptions = allOptions.filter(opt => {
                        // Always show the current row's selection
                        if (opt === currentValue) return true;
                    // Hide options selected in other rows
                        return !selected.includes(opt);
                    });
                
                // Update dropdown with available options
                select.innerHTML = '';
                // Add empty option first
                const emptyOption = document.createElement('option');
                emptyOption.value = '';
                emptyOption.textContent = '-- Select Position --';
                select.appendChild(emptyOption);
                
                availableOptions.forEach(opt => {
                    const option = document.createElement('option');
                    option.value = opt;
                    option.textContent = opt;
                    if (opt === currentValue) option.selected = true;
                    select.appendChild(option);
                });
            });
        }

        // Add single thickness row
        function addSingleThicknessRow(afterRowNumber) {
            const table = document.querySelector('.test-parameters table tbody');
            if (!table) return;
            
            const currentRows = table.querySelectorAll('tr');
            // Count only data rows (exclude button rows)
            const dataRows = Array.from(currentRows).filter(row => row.querySelector('select[name*="position"]') && !row.querySelector('button'));
            const newRowNumber = dataRows.length + 1;
            
            // Find the button row and insert after it
            let insertIndex = -1;
            for (let i = 0; i < currentRows.length; i++) {
                const btn = currentRows[i].querySelector('button[onclick*="addSingleThicknessRow(' + afterRowNumber + ')"]');
                if (btn) {
                    insertIndex = i + 1;
                    break;
                }
            }
            
            if (insertIndex === -1) {
                insertIndex = currentRows.length;
            }
            
            // Insert new data row
            const selected = getSelectedThicknessPositions();
            
            // All position options available
            const allPositionOptions = [
                'Left-1', 'Left-2', 'Left-3', 'Left-4',
                'Middle Left-1', 'Middle Left-2', 'Middle Left-3', 'Middle Left-4',
                'Middle Right-1', 'Middle Right-2', 'Middle Right-3', 'Middle Right-4',
                'Right-1', 'Right-2', 'Right-3', 'Right-4'
            ];
            const availableOptions = allPositionOptions
                .filter(opt => !selected.includes(opt))
                    .map(opt => `<option value="${opt}">${opt}</option>`).join('');
            
            const newRow = table.insertRow(insertIndex);
            newRow.className = 'gsm-row';
            newRow.innerHTML = `
                <td style="border: 1px solid #ddd; padding: 6px;">
                    <select class="posSelect" name="astmd5199_position_${newRowNumber}" id="astmd5199_position_${newRowNumber}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" onchange="updateThicknessDropdowns(); calculateThicknessAverages()">
                        <option value="">-- Select Position --</option>
                        ${availableOptions}
                    </select>
                </td>
                <td style="border: 1px solid #ddd; padding: 6px;">
                    <input type="number" 
                           class="under2kpa"
                           name="astmd5199_under2kpa_${newRowNumber}"
                           step="0.0001" max="1000" 
                           min="0"
                           style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;"
                           onchange="calculateThicknessAverages()"
                           oninput="if(this.value < 0) this.value = 0">
                </td>
                <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa;">
                    <span class="avgCell" style="font-weight: bold; color: #27ae60;">0.000</span>
                </td>
            `;
            
            // Insert "Add Row" button row after new data row
            const btnRow = table.insertRow(insertIndex + 1);
            btnRow.innerHTML = `
                <td colspan="3" style="border: none; padding: 5px; text-align: center; background: #f8f9fa;">
                    <button type="button" 
                            onclick="addSingleThicknessRow(${newRowNumber})"
                            style="padding: 5px 12px; background:rgb(21, 75, 14); color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                        <i class="fa fa-plus"></i> Add 1 More Row
                    </button>
                </td>
            `;
            
            // Remove the old button row
            if (insertIndex > 0) {
                currentRows[insertIndex - 1].remove();
            }

            // De-duplicate accidental multiple consecutive button rows
            const allRows = Array.from(table.querySelectorAll('tr'));
            for (let i = allRows.length - 2; i >= 0; i--) {
                const hasBtn = !!allRows[i].querySelector('button[onclick*="addSingleThicknessRow"]');
                const nextHasBtn = !!allRows[i+1]?.querySelector?.('button[onclick*="addSingleThicknessRow"]');
                if (hasBtn && nextHasBtn) {
                    allRows[i].remove();
                }
            }
            
            // Increment row counter
            thicknessRowCounter++;
            
            // Update dropdowns to show correct options
            updateThicknessDropdowns();
        }

        function removeLastThicknessRow() {
            const table = document.querySelector('.test-parameters table tbody');
            if (!table) return;
            
            const rows = Array.from(table.querySelectorAll('tr'));
            
            // Count data rows (rows without buttons)
            const dataRows = rows.filter(row => !row.querySelector('button') && row.querySelector('select[name*="position"]'));
            
            // Don't remove if only initial 4 rows exist
            // Allow removal if there are more than 4 rows (meaning at least 1 row was added)
            if (dataRows.length <= 4) {
                alert('Cannot remove. Minimum 4 rows required.');
                return;
            }
            
            // Find the last button row from the end
            for (let i = rows.length - 1; i >= 0; i--) {
                const btn = rows[i].querySelector('button[onclick*="addSingleThicknessRow"]');
                if (btn) {
                    // Find the data row above this button row
                    if (i > 0 && !rows[i-1].querySelector('button')) {
                        rows[i-1].remove(); // Remove data row
                    }
                    rows[i].remove(); // Remove button row
                    updateThicknessDropdowns();
                    calculateAllGroupAverages();
                    break;
                }
            }
        }

        // Add thickness group (4 rows at a time)
        function addThicknessGroup() {
            const table = document.querySelector('.test-parameters table tbody');
            if (!table) return;
            
            const currentRows = table.querySelectorAll('tr').length;
            const groupNumber = Math.ceil(currentRows / 4) + 1;
            const startPosition = currentRows + 1;
            
            for (let i = 0; i < 4; i++) {
                const positionNumber = startPosition + i;
                const isGroupEnd = (i === 3);
                
                const row = table.insertRow();
                row.innerHTML = `
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        <input type="text" 
                               placeholder="Position ${positionNumber}"
                               style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;"
                               onchange="calculateThicknessAverages()">
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        <input type="number" 
                               step="0.0001" max="1000" 
                               min="0"
                               
                               style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;"
                               onchange="calculateThicknessAverages()"
                               oninput="if(this.value < 0) this.value = 0">
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #f8f9fa;">
                        ${isGroupEnd ? `<span id="group_avg_${groupNumber}" style="font-weight: bold; color: #27ae60;">0.0000</span>` : ''}
                    </td>
                `;
            }
        }
        
        // Add event listeners
        document.addEventListener('DOMContentLoaded', function() {
    updateDateTimeAndShift();
    setInterval(updateDateTimeAndShift, 1000); // Update every second
    
    // Auto-load reference data if coming from Roll Entry
    const productRef = document.getElementById('product_reference');
    if (productRef && productRef.value) {
        console.log('🔄 Auto-loading reference data for:', productRef.value);
        loadQCReferenceData(productRef.value);
        // Also check for submitted tests
        setTimeout(() => {
            checkAndDisableSubmittedTests(productRef.value);
        }, 500);
    }
    
    // Check for individual roll reference
    const individualRollRef = document.getElementById('individual_roll_reference');
    if (individualRollRef && individualRollRef.value) {
        console.log('🔄 Auto-checking submitted tests for individual roll:', individualRollRef.value);
        setTimeout(() => {
            checkAndDisableSubmittedTests(individualRollRef.value);
        }, 500);
    }
    
    // Initialize general info visibility to set proper required attributes
    toggleGeneralInfoVisibility();
    
    const checkboxes = document.querySelectorAll('input[type="checkbox"]');
            
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateSelectionCount);
            });
            
            updateSelectionCount();
            toggleOtherInfoForGsmOnly();
            
            // Initialize averages for all forms on page load
            setTimeout(() => {
                const allForms = document.querySelectorAll('[id$="_form"]');
                allForms.forEach(form => {
                    const suffixId = form.id.replace('_form', '');
                    calculateAverages(suffixId);
                });
            }, 100);
        });
        
        // Form validation is now handled by onclick in the submit button

        // Functions for experimental data table management
        function addExperimentalRow(suffixId) {
            const tbody = document.getElementById(`experimental_data_body_${suffixId}`);
            const existingRows = tbody.querySelectorAll('tr');
            const nextRowNumber = existingRows.length + 1;
            
            const newRow = document.createElement('tr');
            newRow.innerHTML = `
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" name="exp_no_${nextRowNumber}_${suffixId}" value="${nextRowNumber}" readonly style="width: 40px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.0001" name="exp_h0_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.1" name="exp_t1_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.0001" name="exp_h1_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.1" name="exp_t2_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.0001" name="exp_thickness_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.0001" name="exp_water_level_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.1" name="exp_temp_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.0001" name="exp_correction_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.0001" name="exp_head_diff_${nextRowNumber}_${suffixId}" readonly style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.1" name="exp_time_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.0001" name="exp_velocity_${nextRowNumber}_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.0001" name="exp_permeability_${nextRowNumber}_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                </td>
            `;
            
            tbody.appendChild(newRow);
            
            // Automatically calculate averages for the new row
            calculateAverages(suffixId);
        }

        function removeExperimentalRow(suffixId) {
            const tbody = document.getElementById(`experimental_data_body_${suffixId}`);
            const rows = tbody.querySelectorAll('tr');
            
            if (rows.length > 1) { // Keep at least one row
                const lastRow = rows[rows.length - 1];
                lastRow.remove();
                
                // Automatically recalculate averages after removing a row
                calculateAverages(suffixId);
            }
        }

        function calculateExperimentalData(suffixId) {
            // Get calculation inputs
            const areaSpecimen = parseFloat(document.querySelector(`input[name="iso11058_area_specimen_${suffixId}"]`).value) || 0;
            const areaPipe = parseFloat(document.querySelector(`input[name="iso11058_area_pipe_${suffixId}"]`).value) || 0;
            
            if (areaSpecimen === 0 || areaPipe === 0) {
                alert('Please fill in the Exposed area of the test specimen (a) and Exposed area of stand pipe (A) first.');
                return;
            }
            
            // Get all rows in the experimental data table
            const tbody = document.getElementById(`experimental_data_body_${suffixId}`);
            const rows = tbody.querySelectorAll('tr');
            
            rows.forEach((row, index) => {
                const rowNum = index + 1;
                
                // Get input values for this row
                const h0 = parseFloat(row.querySelector(`input[name="exp_h0_${rowNum}_${suffixId}"]`).value) || 0;
                const h1 = parseFloat(row.querySelector(`input[name="exp_h1_${rowNum}_${suffixId}"]`).value) || 0;
                const t1 = parseFloat(row.querySelector(`input[name="exp_t1_${rowNum}_${suffixId}"]`).value) || 0;
                const t2 = parseFloat(row.querySelector(`input[name="exp_t2_${rowNum}_${suffixId}"]`).value) || 0;
                const thickness = parseFloat(row.querySelector(`input[name="exp_thickness_${rowNum}_${suffixId}"]`).value) || 0;
                const time = parseFloat(row.querySelector(`input[name="exp_time_${rowNum}_${suffixId}"]`).value) || 0;
                const correctionFactor = parseFloat(row.querySelector(`input[name="exp_correction_${rowNum}_${suffixId}"]`).value) || 1;
                
                // Calculate Head Difference (Δh) = h₀ - h₁
                const headDifference = h0 - h1;
                
                // Calculate Velocity (V₂₀) = Δh/t × RT
                // Use t2 - t1 for time difference as per falling head method
                const timeDifference = t2 - t1;
                const velocity = timeDifference > 0 ? (headDifference / timeDifference) * correctionFactor : 0;
                
                // Calculate Permeability (K) using the specified logic
                let K = 0;
                const a = areaSpecimen; // Exposed area of specimen
                const A = areaPipe;     // Exposed area of stand pipe
                const L = thickness;    // Thickness
                const t = timeDifference; // Time difference (t2 - t1)
                const h1_val = h0;      // Initial water level
                const h2_val = h1;      // Final water level
                const RT = correctionFactor; // Correction factor
                
                // Only calculate if all required inputs are valid
                if (a > 0 && A > 0 && t > 0 && h1_val > 0 && h2_val > 0 && h1_val !== h2_val) {
                    K = (a * L) / (A * t) * Math.log10(h1_val / h2_val) * RT;
                }
                
                // Update the calculated fields
                row.querySelector(`input[name="exp_head_diff_${rowNum}_${suffixId}"]`).value = headDifference.toFixed(3);
                row.querySelector(`input[name="exp_velocity_${rowNum}_${suffixId}"]`).value = velocity.toFixed(3);
                row.querySelector(`input[name="exp_permeability_${rowNum}_${suffixId}"]`).value = K.toFixed(6);
            });
            
            alert('Calculations completed successfully!');
        }

        function calculateRowData(suffixId, rowNumber) {
            // Get calculation inputs
            const areaSpecimen = parseFloat(document.querySelector(`input[name="iso11058_area_specimen_${suffixId}"]`).value) || 0;
            const areaPipe = parseFloat(document.querySelector(`input[name="iso11058_area_pipe_${suffixId}"]`).value) || 0;
            
            // Debug: Log the values to check if they're being retrieved correctly
            console.log(`Row ${rowNumber} - Specimen Area (a): ${areaSpecimen}, Pipe Area (A): ${areaPipe}`);
            
            // Get input values for this specific row
            const h0 = parseFloat(document.querySelector(`input[name="exp_h0_${rowNumber}_${suffixId}"]`).value) || 0;
            const h1 = parseFloat(document.querySelector(`input[name="exp_h1_${rowNumber}_${suffixId}"]`).value) || 0;
            const t1 = parseFloat(document.querySelector(`input[name="exp_t1_${rowNumber}_${suffixId}"]`).value) || 0;
            const t2 = parseFloat(document.querySelector(`input[name="exp_t2_${rowNumber}_${suffixId}"]`).value) || 0;
            const thickness = parseFloat(document.querySelector(`input[name="exp_thickness_${rowNumber}_${suffixId}"]`).value) || 0;
            const time = parseFloat(document.querySelector(`input[name="exp_time_${rowNumber}_${suffixId}"]`).value) || 0;
            const correctionFactor = parseFloat(document.querySelector(`input[name="exp_correction_${rowNumber}_${suffixId}"]`).value) || 1;
            
            // Calculate Head Difference (Δh) = h₀ - h₁
            const headDifference = h0 - h1;
            
            // Calculate Velocity (V₂₀) = Δh/t × RT
            // Use t2 - t1 for time difference as per falling head method
            const timeDifference = t2 - t1;
            const velocity = timeDifference > 0 ? (headDifference / timeDifference) * correctionFactor : 0;
            
            // Calculate Permeability (K) using natural logarithm
            let K = 0;
            const a = areaSpecimen; // Exposed area of specimen
            const A = areaPipe;     // Exposed area of stand pipe
            const L = thickness;    // Thickness
            const t = timeDifference; // Time difference (t2 - t1)
            const h1_val = h0;      // Initial water level
            const h2_val = h1;      // Final water level
            const RT = correctionFactor; // Correction factor
            
            // Only calculate if all required inputs are valid
            if (a > 0 && A > 0 && t > 0 && h1_val > 0 && h2_val > 0 && h1_val !== h2_val) {
                K = (a * L) / (A * t) * Math.log10(h1_val / h2_val) * RT;
            }
            
            // Update the calculated fields for this row
            document.querySelector(`input[name="exp_head_diff_${rowNumber}_${suffixId}"]`).value = headDifference.toFixed(3);
            document.querySelector(`input[name="exp_velocity_${rowNumber}_${suffixId}"]`).value = velocity.toFixed(3);
            document.querySelector(`input[name="exp_permeability_${rowNumber}_${suffixId}"]`).value = K.toFixed(6);
            
            // Automatically update averages after each calculation
            calculateAverages(suffixId);
        }

        function recalculateAllRows(suffixId) {
            // Get all rows in the experimental data table
            const tbody = document.getElementById(`experimental_data_body_${suffixId}`);
            if (!tbody) return;
            
            const rows = tbody.querySelectorAll('tr');
            rows.forEach((row, index) => {
                const rowNum = index + 1;
                calculateRowData(suffixId, rowNum);
            });
            
            // Automatically update averages after recalculating all rows
            calculateAverages(suffixId);
        }

        function calculateAverages(suffixId) {
            // Get all rows in the experimental data table
            const tbody = document.getElementById(`experimental_data_body_${suffixId}`);
            if (!tbody) return;
            
            const rows = tbody.querySelectorAll('tr');
            let permeabilitySum = 0;
            let velocitySum = 0;
            let validPermeabilityCount = 0;
            let validVelocityCount = 0;
            
            rows.forEach((row, index) => {
                const rowNum = index + 1;
                
                // Get calculated values for this row
                const permeabilityValue = parseFloat(row.querySelector(`input[name="exp_permeability_${rowNum}_${suffixId}"]`).value) || 0;
                const velocityValue = parseFloat(row.querySelector(`input[name="exp_velocity_${rowNum}_${suffixId}"]`).value) || 0;
                
                // Add to sum if value is valid (not 0)
                if (permeabilityValue !== 0) {
                    permeabilitySum += permeabilityValue;
                    validPermeabilityCount++;
                }
                
                if (velocityValue !== 0) {
                    velocitySum += velocityValue;
                    validVelocityCount++;
                }
            });
            
            // Calculate averages
            const avgPermeability = validPermeabilityCount > 0 ? (permeabilitySum / validPermeabilityCount) : 0;
            const avgVelocity = validVelocityCount > 0 ? (velocitySum / validVelocityCount) : 0;
            
            // Update the average fields
            document.getElementById(`avg_permeability_${suffixId}`).value = avgPermeability.toFixed(6);
            document.getElementById(`avg_velocity_${suffixId}`).value = avgVelocity.toFixed(3);
        }
        
        function validateTestPeriod() {
            const fromDate = document.getElementById('test_period_from').value;
            const toDate = document.getElementById('test_period_to').value;
            
            if (fromDate && toDate) {
                if (new Date(toDate) < new Date(fromDate)) {
                    alert('Test Period To cannot be earlier than Test Period From');
                    document.getElementById('test_period_to').value = '';
                }
            }
        }
        
        function validateWeatheringTestDate(suffixId) {
            const startDate = document.getElementById('wx_test_start_' + suffixId)?.value;
            const endDate = document.getElementById('wx_test_end_' + suffixId)?.value;
            
            if (startDate && endDate) {
                if (new Date(endDate) < new Date(startDate)) {
                    alert('Test End Date cannot be earlier than Test Start Date');
                    document.getElementById('wx_test_end_' + suffixId).value = '';
                }
            }
        }

        
        function applyLastGeneralInfo(suffixId) {
            if (!window.QC_LAST_GENERAL) return;
            const map = {
                sample_details: `sample_details_${suffixId}`,
                batch_info: `batch_info_${suffixId}`,
                sample_collected_from: `sample_collected_from_${suffixId}`,
                sample_received_dt: `sample_received_dt_${suffixId}`,
                sample_production_date: `sample_production_date_${suffixId}`,
                temperature_c: `temperature_c_${suffixId}`,
                rh_percent: `rh_percent_${suffixId}`,
                test_period_from: `test_period_from_${suffixId}`,
                test_period_to: `test_period_to_${suffixId}`,
                gsm: `gsm_${suffixId}`,
                line: `line_${suffixId}`,
                roll: `roll_${suffixId}`,
                product_ref: `product_ref_${suffixId}`
            };
            Object.keys(map).forEach(key => {
                const v = window.QC_LAST_GENERAL[key];
                if (v !== undefined) {
                    const el = document.getElementsByName(map[key])[0] || document.getElementById(map[key]);
                    if (el) el.value = v;
                }
            });
        }
        
        // Apply last general info to top-level form fields on page load
        function applyLastGeneralToTopFields() {
            console.log('=== APPLY AUTO-FILL CALLED ===');
            console.log('Edit mode:', <?php echo $edit_mode ? 'true' : 'false'; ?>);
            
            // Don't auto-fill if in edit mode
            const isEditMode = <?php echo $edit_mode ? 'true' : 'false'; ?>;
            if (isEditMode) {
                console.log('❌ Skipping auto-fill - in edit mode');
                return;
            }
            
            if (!window.QC_LAST_GENERAL || typeof window.QC_LAST_GENERAL !== 'object') {
                console.log('❌ No QC_LAST_GENERAL data found');
                console.log('window.QC_LAST_GENERAL is:', typeof window.QC_LAST_GENERAL, window.QC_LAST_GENERAL);
                return;
            }
            
            const prefCount = Object.keys(window.QC_LAST_GENERAL).length;
            if (prefCount === 0) {
                console.log('⚠️ QC_LAST_GENERAL is empty - no preferences saved yet');
                console.log('Submit a QC test order to save your preferences');
                return;
            }
            
            console.log('✅ QC_LAST_GENERAL found:', window.QC_LAST_GENERAL);
            console.log('Number of fields to fill:', prefCount);
            
            const topFieldMap = {
                'sample_details': 'sample_details',
                'batch_information': 'batch_information',
                'sample_collected_from': 'sample_collected_from',
                'sample_received_datetime': 'sample_received_datetime',
                'sample_production_date': 'sample_production_date',
                'temperature': 'temperature',
                'rh_percentage': 'rh_percentage',
                'test_period_from': 'test_period_from',
                'test_period_to': 'test_period_to',
                'customer_reference': 'customer_reference',
                'sample_received_from': 'sample_received_from'
            };
            
            let applied = 0;
            let skipped = 0;
            let notFound = 0;
            
            Object.keys(topFieldMap).forEach(key => {
                const v = window.QC_LAST_GENERAL[key];
                if (v !== undefined && v !== '') {
                    const fieldName = topFieldMap[key];
                    // Try to find by name first, then by ID
                    let el = document.getElementsByName(fieldName)[0];
                    if (!el) {
                        // Try batch_information -> qc_batch_info ID mapping
                        if (fieldName === 'batch_information') {
                            el = document.getElementById('qc_batch_info');
                        } else {
                            el = document.getElementById(fieldName);
                        }
                    }
                    
                    if (el) {
                        // Skip readonly fields that are populated by reference selection
                        const isRefPopulatedField = ['qc_batch_info', 'qc_gsm', 'qc_roll_number'].includes(el.id);
                        
                        if (isRefPopulatedField && el.value && el.value !== '') {
                            // Field already populated by reference selection, don't override
                            console.log(`Skipping ${fieldName} (populated by reference selection): ${el.value}`);
                            skipped++;
                        } else {
                            // Fill the field
                            console.log(`Setting ${fieldName} to:`, v);
                            el.value = v;
                            applied++;
                        }
                    } else {
                        console.log(`❌ Field ${fieldName} not found`);
                        notFound++;
                    }
                } else {
                    skipped++;
                }
            });
            console.log('=== AUTO-FILL SUMMARY ===');
            console.log(`✅ Applied: ${applied} fields`);
            console.log(`⚠️ Skipped (no value): ${skipped} fields`);
            console.log(`❌ Not found: ${notFound} fields`);
            console.log('=== END AUTO-FILL ===');
            
            // Show a brief notification if fields were filled
            if (applied > 0) {
                console.log(`✅ Success! Auto-filled ${applied} fields from your last submission`);
                
                // Optional: Show a brief visual notification
                const notification = document.createElement('div');
                notification.style.cssText = 'position:fixed;top:20px;right:20px;background:#27ae60;color:white;padding:12px 20px;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:10000;font-size:14px;font-weight:600;';
                notification.innerHTML = `✅ Auto-filled ${applied} field${applied > 1 ? 's' : ''} from previous submission`;
                document.body.appendChild(notification);
                
                // Remove notification after 3 seconds
                setTimeout(() => {
                    notification.style.transition = 'opacity 0.3s';
                    notification.style.opacity = '0';
                    setTimeout(() => notification.remove(), 300);
                }, 3000);
            }
        }
        
        // Multiple attempts to ensure auto-fill works
        let autoFillAttempts = 0;
        const maxAttempts = 5;
        
        function attemptAutoFill() {
            autoFillAttempts++;
            console.log(`Auto-fill attempt ${autoFillAttempts}/${maxAttempts}`);
            
            // Check if general info section exists and is visible
            const generalSection = document.getElementById('general-info-section');
            if (!generalSection) {
                console.log('General info section not found yet');
                if (autoFillAttempts < maxAttempts) {
                    setTimeout(attemptAutoFill, 300);
                }
                return;
            }
            
                applyLastGeneralToTopFields();
        }
        
        // Run on page load - with a delay to ensure fields are ready
        document.addEventListener('DOMContentLoaded', function() {
            console.log('📋 DOMContentLoaded - starting auto-fill');
            setTimeout(attemptAutoFill, 200);
        });
        
        // Also run after window is fully loaded (backup)
        window.addEventListener('load', function() {
            console.log('📋 Window loaded - running backup auto-fill');
            setTimeout(function() {
                console.log('⏰ Running auto-fill from window.load (backup)');
                applyLastGeneralToTopFields();
            }, 500);
            
            // Initialize product type functionality
            // Initialize product type on page load
            handleProductTypeChange();
            
            // AGM/testers editing external products should always be in external product mode
            <?php if ($is_admin || $lock_general_fields): ?>
            const externalRadio = document.getElementById('product_type_external');
            if (externalRadio) {
                externalRadio.checked = true;
                const isExternalFlag = document.getElementById('is_external_product');
                if (isExternalFlag) {
                    isExternalFlag.value = '1';
                }
                handleProductTypeChange();
            }
            <?php endif; ?>
            
            console.log('✅ Product type functionality initialized');
        });
    </script>
    
    <?php if ($edit_mode && !empty($existing_test_data)): ?>
    <!-- Pre-fill form with existing data for edit mode -->
    <script>
    // Pre-fill data for edit mode
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Edit mode: Pre-filling form with existing data');
        
        const existingData = <?php echo json_encode($existing_test_data); ?>;
        const reportData = <?php echo json_encode($existing_report); ?>;
        
        setTimeout(function() {
            // Pre-fill all form fields from existing data
            const fieldMappings = {
                'sample_details': 'sample_details',
                'batch_information': 'batch_information',
                'sample_collected_from': 'sample_collected_from',
                'sample_received_datetime': 'sample_received_datetime',
                'sample_production_date': 'sample_production_date',
                'gsm': 'gsm',
                'temperature': 'temperature',
                'rh_percentage': 'rh_percentage',
                'test_period_from': 'test_period_from',
                'test_period_to': 'test_period_to',
                'roll_number': 'roll_number',
                'customer_reference': 'customer_reference',
                'sample_received_from': 'sample_received_from',
                'lighthouse_reference': 'lighthouse_reference',
                'other_info': 'other_info'
            };
            
            // Pre-fill all matching fields
            Object.keys(fieldMappings).forEach(dataKey => {
                const fieldId = fieldMappings[dataKey];
                if (existingData[dataKey]) {
                    const field = document.getElementById(fieldId) || document.querySelector(`[name="${fieldId}"]`);
                    if (field) {
                        field.value = existingData[dataKey];
                    }
                }
            });
            
            // Pre-fill bulk reference fields if this is a bulk submission
            <?php if ($edit_mode && !empty($bulk_from_ref) && !empty($bulk_to_ref)): ?>
            const bulkFromRef = <?php echo json_encode($bulk_from_ref); ?>;
            const bulkToRef = <?php echo json_encode($bulk_to_ref); ?>;
            const submittedMethods = <?php echo json_encode($submitted_test_methods); ?>;
            const currentReportId = <?php echo $edit_id; ?>;
            
            console.log('🔍 Pre-populating bulk references:', { bulkFromRef, bulkToRef });
            
            // Pre-populate and disable From/To reference fields
            setTimeout(() => {
                // Determine which line was used based on the reference format
                const lineMatch = bulkFromRef.match(/L(\d+)/);
                if (lineMatch) {
                    const lineNum = lineMatch[1];
                    const lineValue = 'L' + lineNum;
                    console.log('📍 Detected line:', lineValue);
                    
                    const lineRadio = document.querySelector(`input[name="line"][value="${lineValue}"]`);
                    if (lineRadio) {
                        console.log('✅ Found line radio button, selecting it');
                        lineRadio.checked = true;
                        
                        // Trigger filterByLine function to populate dropdowns
                        if (typeof filterByLine === 'function') {
                            console.log('📋 Calling filterByLine function');
                            filterByLine(lineValue);
                        } else {
                            console.log('⚠️ filterByLine not found, dispatching change event');
                            const changeEvent = new Event('change', { bubbles: true });
                            lineRadio.dispatchEvent(changeEvent);
                        }
                        
                        // Wait for dropdowns to populate, then set values - try multiple times
                        let attempts = 0;
                        const maxAttempts = 20;
                        const trySetValues = () => {
                            attempts++;
                            console.log(`🔄 Attempt ${attempts} to set bulk reference values`);
                            
                            const fromRefSelect = document.getElementById('from_reference');
                            const toRefSelect = document.getElementById('to_reference');
                            const bulkRefSelection = document.getElementById('bulk_reference_selection');
                            
                            // Make sure bulk reference selection is visible
                            if (bulkRefSelection) {
                                bulkRefSelection.style.display = 'block';
                                console.log('✅ Bulk reference selection div is visible');
                            }
                            
                            // Check if options are populated
                            const fromHasOptions = fromRefSelect && fromRefSelect.options.length > 1;
                            const toHasOptions = toRefSelect && toRefSelect.options.length > 1;
                            
                            console.log('📊 Dropdown status:', {
                                fromHasOptions,
                                fromOptionsCount: fromRefSelect ? fromRefSelect.options.length : 0,
                                toHasOptions,
                                toOptionsCount: toRefSelect ? toRefSelect.options.length : 0
                            });
                            
                            if (fromHasOptions && toHasOptions) {
                                // Options are populated, set values
                                let foundFrom = false;
                                let foundTo = false;
                                
                                if (fromRefSelect) {
                                    // Find the option that matches bulkFromRef
                                    for (let i = 0; i < fromRefSelect.options.length; i++) {
                                        const option = fromRefSelect.options[i];
                                        if (option.value === bulkFromRef) {
                                            fromRefSelect.selectedIndex = i;
                                            foundFrom = true;
                                            console.log('✅ Found and selected From reference:', bulkFromRef);
                                            break;
                                        }
                                    }
                                    
                                    if (foundFrom) {
                                        fromRefSelect.disabled = true;
                                        fromRefSelect.style.background = '#f5f5f5';
                                        fromRefSelect.style.cursor = 'not-allowed';
                                        fromRefSelect.setAttribute('readonly', 'readonly');
                                    } else {
                                        console.warn('⚠️ From reference not found in dropdown:', bulkFromRef);
                                        // Log all available options for debugging
                                        console.log('Available From options:', Array.from(fromRefSelect.options).map(opt => opt.value));
                                    }
                                }
                                
                                if (toRefSelect) {
                                    // Find the option that matches bulkToRef
                                    for (let i = 0; i < toRefSelect.options.length; i++) {
                                        const option = toRefSelect.options[i];
                                        if (option.value === bulkToRef) {
                                            toRefSelect.selectedIndex = i;
                                            foundTo = true;
                                            console.log('✅ Found and selected To reference:', bulkToRef);
                                            break;
                                        }
                                    }
                                    
                                    if (foundTo) {
                                        toRefSelect.disabled = true;
                                        toRefSelect.style.background = '#f5f5f5';
                                        toRefSelect.style.cursor = 'not-allowed';
                                        toRefSelect.setAttribute('readonly', 'readonly');
                                    } else {
                                        console.warn('⚠️ To reference not found in dropdown:', bulkToRef);
                                        // Log all available options for debugging
                                        console.log('Available To options:', Array.from(toRefSelect.options).map(opt => opt.value));
                                    }
                                }
                                
                                if (foundFrom && foundTo) {
                                    console.log('✅ Successfully set both From and To references');
                                    // Don't check for already submitted tests since we're in edit mode
                                    // The test methods are already filtered in PHP to only show submitted ones
                                } else if (attempts < maxAttempts) {
                                    // Values not found yet, try again
                                    setTimeout(trySetValues, 500);
                                }
                            } else if (attempts < maxAttempts) {
                                // Options not ready yet, try again
                                setTimeout(trySetValues, 500);
                            } else {
                                console.error('❌ Failed to populate dropdowns after', maxAttempts, 'attempts');
                            }
                        };
                        
                        // Start trying after initial delay to allow filterByLine to complete
                        setTimeout(trySetValues, 1000);
                    } else {
                        console.error('❌ Line radio button not found for:', lineValue);
                    }
                } else {
                    console.error('❌ Could not extract line number from reference:', bulkFromRef);
                }
            }, 1000);
            <?php endif; ?>
            
            // Pre-fill reference fields and trigger data loading
            if (existingData.product_reference) {
                const productRef = document.getElementById('product_reference');
                if (productRef) {
                    productRef.value = existingData.product_reference;
                    // Trigger the reference data load to populate GSM, roll number, batch info
                    if (typeof loadQCReferenceData === 'function') {
                        loadQCReferenceData(existingData.product_reference);
                        
                        // After reference data loads, re-apply the original submitted values
                        // (in case user had manually edited them)
                        setTimeout(() => {
                            Object.keys(fieldMappings).forEach(dataKey => {
                                const fieldId = fieldMappings[dataKey];
                                if (existingData[dataKey]) {
                                    const field = document.getElementById(fieldId) || document.querySelector(`[name="${fieldId}"]`);
                                    if (field) {
                                        field.value = existingData[dataKey];
                                    }
                                }
                            });
                        }, 800);
                    }
                }
            }
            
            if (existingData.fiber_reference_no) {
                const fiberRef = document.getElementById('fiber_reference_no');
                if (fiberRef) {
                    fiberRef.value = existingData.fiber_reference_no;
                    // Trigger the reference data load
                    if (typeof loadFiberReferenceData === 'function') {
                        loadFiberReferenceData(existingData.fiber_reference_no);
                        
                        // Re-apply original values after lookup
                        setTimeout(() => {
                            Object.keys(fieldMappings).forEach(dataKey => {
                                const fieldId = fieldMappings[dataKey];
                                if (existingData[dataKey]) {
                                    const field = document.getElementById(fieldId) || document.querySelector(`[name="${fieldId}"]`);
                                    if (field) {
                                        field.value = existingData[dataKey];
                                    }
                                }
                            });
                        }, 800);
                    }
                }
            }
            
            if (existingData.yarn_reference_no) {
                const yarnRef = document.getElementById('yarn_reference_no');
                if (yarnRef) {
                    yarnRef.value = existingData.yarn_reference_no;
                }
            }
            
            // Auto-select the test method checkbox for the current report
            const selectedMethod = reportData.chosen_method;
            const selectedTestName = reportData.test_name;
            
            <?php if ($edit_mode && !empty($submitted_test_methods)): ?>
            // In bulk edit mode, only check methods that were submitted
            const submittedMethods = <?php echo json_encode($submitted_test_methods); ?>;
            
            // Try multiple times to ensure checkboxes are found
            let checkboxAttempts = 0;
            const maxCheckboxAttempts = 10;
            const tryCheckCheckboxes = () => {
                checkboxAttempts++;
                let foundAny = false;
                
                Object.keys(submittedMethods).forEach(testName => {
                    const submittedMethodsForTest = submittedMethods[testName];
                    const testCheckboxes = document.querySelectorAll(`input.test-checkbox[data-test-name="${testName}"]`);
                    
                    testCheckboxes.forEach(checkbox => {
                        const method = checkbox.getAttribute('data-method');
                        if (submittedMethodsForTest.includes(method)) {
                            foundAny = true;
                            checkbox.checked = true;
                            // Trigger change event first
                            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                            // Then trigger test selection to load parameters
                            if (typeof handleTestSelection === 'function') {
                                setTimeout(() => {
                                    handleTestSelection(checkbox);
                                }, 100);
                            }
                        }
                    });
                });
                
                if (!foundAny && checkboxAttempts < maxCheckboxAttempts) {
                    setTimeout(tryCheckCheckboxes, 300);
                } else if (foundAny) {
                    // Pre-fill test data for all checked methods
                    setTimeout(() => {
                        Object.keys(submittedMethods).forEach(testName => {
                            const submittedMethodsForTest = submittedMethods[testName];
                            submittedMethodsForTest.forEach(method => {
                                if (typeof prefillTestData === 'function') {
                                    prefillTestData(existingData, testName, method);
                                }
                            });
                        });
                    }, 1500);
                }
            };
            
            setTimeout(tryCheckCheckboxes, 2000);
            <?php else: ?>
            // Single report edit mode
            setTimeout(() => {
            const testCheckboxes = document.querySelectorAll('.test-checkbox');
            
            testCheckboxes.forEach(cb => {
                const method = cb.getAttribute('data-method');
                const testName = cb.getAttribute('data-test-name');
                if (method === selectedMethod && testName === selectedTestName) {
                    cb.checked = true;
                        // Trigger change event first
                        cb.dispatchEvent(new Event('change', { bubbles: true }));
                        // Then trigger test selection
                        if (typeof handleTestSelection === 'function') {
                            setTimeout(() => {
                    handleTestSelection(cb);
                            }, 100);
                        }
                    
                    // Wait for parameters to be generated, then pre-fill them
                    setTimeout(function() {
                        prefillTestData(existingData, selectedTestName, selectedMethod);
                        }, 1500);
                }
            });
            }, 1000);
            <?php endif; ?>
            
        }, 300);
        
        function prefillTestData(data, testName, method) {
            const methodSuffix = (method || '').replace(/\s+/g, '_').toLowerCase();
            // Pre-fill Thickness test data
            if (testName === 'Thickness (Under 2kPa Pressure)' && data.positions) {
                // Wait a bit longer to ensure the form is fully rendered
                setTimeout(() => {
                    // Group positions by type for average calculation
                    const groups = {
                        'Left': [],
                        'Middle Left': [],
                        'Middle Right': [],
                        'Right': []
                    };
                    
                    // Pre-fill values and group them
                    data.positions.forEach((pos, idx) => {
                        const i = idx + 1;
                        const posSelect = document.getElementById('astmd5199_position_' + i);
                        const thicknessInput = document.querySelector('input[name="astmd5199_under2kpa_' + i + '"]');
                        
                        if (posSelect && thicknessInput) {
                            // Set the value FIRST before any dropdown updates
                            posSelect.value = pos.position;
                            // Handle both 'thickness' and 'value' fields
                            const thicknessValue = pos.thickness ?? pos.value ?? '';
                            thicknessInput.value = thicknessValue;
                            
                            // Group by position type
                            const posType = pos.position.split('-')[0]; // "Left-1" -> "Left"
                            if (groups[posType]) {
                                const thicknessValue = parseFloat(pos.thickness ?? pos.value ?? 0);
                                if (thicknessValue > 0) {
                                    groups[posType].push({
                                        index: i,
                                        value: thicknessValue
                                    });
                                }
                            }
                        }
                    });
                    
                    // DO NOT call updateThicknessDropdowns() in edit mode as it may reset pre-filled values
                    // The dropdowns already have their values set above
                    
                    // Calculate and display row averages for each group
                    setTimeout(() => {
                        Object.keys(groups).forEach(groupName => {
                            const groupData = groups[groupName];
                            if (groupData.length > 0) {
                                // Calculate group average
                                const sum = groupData.reduce((acc, item) => acc + item.value, 0);
                                const avg = (sum / groupData.length).toFixed(3);
                                
                                // Show average in the last row of this group
                                const lastIndex = groupData[groupData.length - 1].index;
                                const rows = document.querySelectorAll('tr.gsm-row');
                                if (rows[lastIndex - 1]) {
                                    const avgCell = rows[lastIndex - 1].querySelector('.avgCell');
                                    if (avgCell) {
                                        avgCell.textContent = avg;
                                    }
                                }
                            }
                        });
                        
                        // Pre-fill summary display fields
                        if (data.average !== undefined) {
                            const avgDisplay = document.getElementById('thk_summary_avg');
                            const sdDisplay = document.getElementById('thk_summary_sd');
                            const cvDisplay = document.getElementById('thk_summary_cv');
                            const maxDisplay = document.getElementById('thk_summary_max');
                            const minDisplay = document.getElementById('thk_summary_min');
                            
                            const avgHidden = document.getElementById('astmd5199_avg');
                            const sdHidden = document.getElementById('astmd5199_sd');
                            const cvHidden = document.getElementById('astmd5199_cv');
                            const maxHidden = document.getElementById('astmd5199_max');
                            const minHidden = document.getElementById('astmd5199_min');
                            
                            if (avgDisplay) avgDisplay.value = (data.average || 0).toFixed(3);
                            if (sdDisplay) sdDisplay.value = (data.sd || 0).toFixed(3);
                            if (cvDisplay) cvDisplay.value = (data.cv || 0).toFixed(2);
                            if (maxDisplay) maxDisplay.value = (data.max || 0).toFixed(3);
                            if (minDisplay) minDisplay.value = (data.min || 0).toFixed(3);
                            
                            if (avgHidden) avgHidden.value = (data.average || 0).toFixed(3);
                            if (sdHidden) sdHidden.value = (data.sd || 0).toFixed(3);
                            if (cvHidden) cvHidden.value = (data.cv || 0).toFixed(2);
                            if (maxHidden) maxHidden.value = (data.max || 0).toFixed(3);
                            if (minHidden) minHidden.value = (data.min || 0).toFixed(3);
                        }
                    }, 300);
                    
                    // After values are loaded, recompute summary statistics to reflect actual entries
                    setTimeout(() => {
                        if (typeof calculateThicknessAverages === 'function') {
                            calculateThicknessAverages();
                        }
                    }, 400);
            }, 200);
            }
            
            // Pre-fill GSM test data
            if (testName === 'Mass Per Unit Area (GSM)' && data.positions) {
                setTimeout(() => {
                    // FIRST: Pre-fill summary statistics from saved data (preserve original values)
                    const suffixId = methodSuffix || (document.querySelector('[id^="gsmBody_"]')?.id.replace('gsmBody_', '') || '');
                    const gsmBody = document.getElementById(`gsmBody_${suffixId}`) || document.querySelector('[id^="gsmBody_"]');
                    
                    if (suffixId && (data.average !== undefined || data.sd !== undefined || data.cv !== undefined)) {
                        // Load saved summary statistics into the summary display fields
                        const fmt2 = v => (isFinite(v) && v !== null && v !== undefined) ? parseFloat(v).toFixed(2) : '0.00';
                        
                        if (data.average !== undefined && data.average !== null) {
                            const avgEl = document.getElementById(`gsm_sum_avg_${suffixId}`);
                            const avgInput = document.getElementById(`gsm_sum_avg_input_${suffixId}`);
                            if (avgEl) avgEl.textContent = fmt2(data.average);
                            if (avgInput) avgInput.value = data.average;
                        }
                        
                        if (data.sd !== undefined && data.sd !== null) {
                            const sdEl = document.getElementById(`gsm_sum_sd_${suffixId}`);
                            const sdInput = document.getElementById(`gsm_sum_sd_input_${suffixId}`);
                            if (sdEl) sdEl.textContent = fmt2(data.sd);
                            if (sdInput) sdInput.value = data.sd;
                        }
                        
                        if (data.cv !== undefined && data.cv !== null) {
                            const cvEl = document.getElementById(`gsm_sum_cv_${suffixId}`);
                            const cvInput = document.getElementById(`gsm_sum_cv_input_${suffixId}`);
                            if (cvEl) cvEl.textContent = fmt2(data.cv);
                            if (cvInput) cvInput.value = data.cv;
                        }
                        
                        if (data.max !== undefined && data.max !== null) {
                            const maxEl = document.getElementById(`gsm_sum_max_${suffixId}`);
                            const maxInput = document.getElementById(`gsm_sum_max_input_${suffixId}`);
                            if (maxEl) maxEl.textContent = fmt2(data.max);
                            if (maxInput) maxInput.value = data.max;
                        }
                        
                        if (data.min !== undefined && data.min !== null) {
                            const minEl = document.getElementById(`gsm_sum_min_${suffixId}`);
                            const minInput = document.getElementById(`gsm_sum_min_input_${suffixId}`);
                            if (minEl) minEl.textContent = fmt2(data.min);
                            if (minInput) minInput.value = data.min;
                        }
                        
                        // Also update legacy hidden inputs if they exist
                        const avgInputLegacy = document.querySelector('input[name="gsm_avg"]');
                        const sdInputLegacy = document.querySelector('input[name="gsm_sd"]');
                        const cvInputLegacy = document.querySelector('input[name="gsm_cv"]');
                        const maxInputLegacy = document.querySelector('input[name="gsm_max"]');
                        const minInputLegacy = document.querySelector('input[name="gsm_min"]');
                        
                        if (avgInputLegacy && data.average !== undefined) avgInputLegacy.value = fmt2(data.average);
                        if (sdInputLegacy && data.sd !== undefined) sdInputLegacy.value = fmt2(data.sd);
                        if (cvInputLegacy && data.cv !== undefined) cvInputLegacy.value = fmt2(data.cv);
                        if (maxInputLegacy && data.max !== undefined) maxInputLegacy.value = fmt2(data.max);
                        if (minInputLegacy && data.min !== undefined) minInputLegacy.value = fmt2(data.min);
                    }
                    
                    // THEN: Pre-fill position, weight, and calculated GSM values
                    data.positions.forEach((pos, idx) => {
                        const i = idx + 1;
                        const posSelect = document.getElementById('gsm_position_' + i);
                        const weightInput = document.querySelector('input[name="gsm_weight_' + i + '"]');
                        const calcInput = document.querySelector('input[name="gsm_calculated_' + i + '"]');
                        
                        if (posSelect && pos.position) {
                            posSelect.value = pos.position;
                        }
                        if (weightInput && pos.weight !== undefined && pos.weight !== null) {
                            weightInput.value = pos.weight;
                        }
                        if (calcInput && pos.gsm !== undefined && pos.gsm !== null) {
                            calcInput.value = pos.gsm;
                        }
                    });
                    
                    // Update group averages after pre-filling (but don't overwrite summary stats)
                    setTimeout(() => {
                        if (gsmBody && typeof gsmUpdateAverage === 'function') {
                            // Update averages for each group
                            const groups = ['A', 'B', 'C', 'D'];
                            groups.forEach(group => {
                                gsmUpdateAverage(suffixId, group);
                            });
                        }
                    }, 100);
                }, 200);
            }
            
            // Pre-fill Strip Tensile Test data
            if (testName === 'Strip Tensile Test' && data.strip_data) {
                setTimeout(() => {
                    data.strip_data.forEach((row, idx) => {
                        const i = idx + 1;
                        const posSelect = document.querySelector(`select[name="strip_position_${i}"]`);
                        const dirSelect = document.querySelector(`select[name="strip_direction_${i}"]`);
                        const strengthInput = document.querySelector(`input[name="strip_strength_${i}"]`);
                        const elongInput = document.querySelector(`input[name="strip_elongation_${i}"]`);
                        
                        if (posSelect) posSelect.value = row.position || '';
                        if (dirSelect) dirSelect.value = row.direction || '';
                        if (strengthInput) strengthInput.value = row.strength || '';
                        if (elongInput) elongInput.value = row.elongation || '';
                    });
                    
                    // Trigger summary recalculation after pre-filling
                    setTimeout(() => {
                        const stripBody = document.querySelector('[id^="stripBody_"]');
                        if (stripBody && typeof recalcStripSummary === 'function') {
                            const suffixId = stripBody.id.replace('stripBody_', '');
                            recalcStripSummary(suffixId);
                        }
                    }, 300);
                    
                    // Pre-fill summary statistics (if available)
                    setTimeout(() => {
                        if (data.summary) {
                            ['md', 'cd'].forEach(dir => {
                                if (data.summary[dir]) {
                                    const stats = data.summary[dir];
                                    
                                    // Strength stats
                                    const strengthAvg = document.querySelector(`input[name="strip_${dir}_strength_avg"]`);
                                    const strengthSd = document.querySelector(`input[name="strip_${dir}_strength_sd"]`);
                                    const strengthCv = document.querySelector(`input[name="strip_${dir}_strength_cv"]`);
                                    const strengthMax = document.querySelector(`input[name="strip_${dir}_strength_max"]`);
                                    const strengthMin = document.querySelector(`input[name="strip_${dir}_strength_min"]`);
                                    
                                    if (strengthAvg) strengthAvg.value = (stats.strength_avg || 0).toFixed(2);
                                    if (strengthSd) strengthSd.value = (stats.strength_sd || 0).toFixed(2);
                                    if (strengthCv) strengthCv.value = (stats.strength_cv || 0).toFixed(2);
                                    if (strengthMax) strengthMax.value = (stats.strength_max || 0).toFixed(2);
                                    if (strengthMin) strengthMin.value = (stats.strength_min || 0).toFixed(2);
                                    
                                    // Elongation stats
                                    const elongAvg = document.querySelector(`input[name="strip_${dir}_elongation_avg"]`);
                                    const elongSd = document.querySelector(`input[name="strip_${dir}_elongation_sd"]`);
                                    const elongCv = document.querySelector(`input[name="strip_${dir}_elongation_cv"]`);
                                    const elongMax = document.querySelector(`input[name="strip_${dir}_elongation_max"]`);
                                    const elongMin = document.querySelector(`input[name="strip_${dir}_elongation_min"]`);
                                    
                                    if (elongAvg) elongAvg.value = (stats.elongation_avg || 0).toFixed(2);
                                    if (elongSd) elongSd.value = (stats.elongation_sd || 0).toFixed(2);
                                    if (elongCv) elongCv.value = (stats.elongation_cv || 0).toFixed(2);
                                    if (elongMax) elongMax.value = (stats.elongation_max || 0).toFixed(2);
                                    if (elongMin) elongMin.value = (stats.elongation_min || 0).toFixed(2);
                                }
                            });
                        }
                    }, 300);
                }, 200);
            }
            
            // Pre-fill CBR test data
            if (testName === 'CBR Puncture Resistance' && data.cbr_data) {
                setTimeout(() => {
                    data.cbr_data.forEach((row, idx) => {
                        const i = idx + 1;
                        const posSelect = document.querySelector(`select[name="cbr_position_${i}"]`);
                        const forceInput = document.querySelector(`input[name="cbr_force_${i}"]`);
                        const displacementInput = document.querySelector(`input[name="cbr_displacement_${i}"]`);
                        
                        if (posSelect) posSelect.value = row.position || '';
                        if (forceInput) forceInput.value = row.force || '';
                        if (displacementInput) displacementInput.value = row.displacement || '';
                    });
                    
                    // Trigger summary recalculation
                    setTimeout(() => {
                        // Find the suffixId from the form
                        const cbrBody = document.querySelector('[id^="cbrBody_"]');
                        if (cbrBody) {
                            const suffixId = cbrBody.id.replace('cbrBody_', '');
                            if (typeof recalcCbrSummary === 'function') {
                                recalcCbrSummary(suffixId);
                            }
                        }
                        
                        // Also pre-fill summary if available (backup)
                        if (data.summary) {
                            // Force stats
                            if (data.summary.force) {
                                const forceAvg = document.querySelector('input[name="cbr_force_avg"]');
                                const forceSd = document.querySelector('input[name="cbr_force_sd"]');
                                const forceCv = document.querySelector('input[name="cbr_force_cv"]');
                                const forceMax = document.querySelector('input[name="cbr_force_max"]');
                                const forceMin = document.querySelector('input[name="cbr_force_min"]');
                                
                                if (forceAvg && !forceAvg.value) forceAvg.value = (data.summary.force.avg || 0).toFixed(2);
                                if (forceSd && !forceSd.value) forceSd.value = (data.summary.force.sd || 0).toFixed(2);
                                if (forceCv && !forceCv.value) forceCv.value = (data.summary.force.cv || 0).toFixed(2);
                                if (forceMax && !forceMax.value) forceMax.value = (data.summary.force.max || 0).toFixed(2);
                                if (forceMin && !forceMin.value) forceMin.value = (data.summary.force.min || 0).toFixed(2);
                            }
                            
                            // Displacement stats
                            if (data.summary.displacement) {
                                const displAvg = document.querySelector('input[name="cbr_displacement_avg"]');
                                const displSd = document.querySelector('input[name="cbr_displacement_sd"]');
                                const displCv = document.querySelector('input[name="cbr_displacement_cv"]');
                                const displMax = document.querySelector('input[name="cbr_displacement_max"]');
                                const displMin = document.querySelector('input[name="cbr_displacement_min"]');
                                
                                if (displAvg && !displAvg.value) displAvg.value = (data.summary.displacement.avg || 0).toFixed(2);
                                if (displSd && !displSd.value) displSd.value = (data.summary.displacement.sd || 0).toFixed(2);
                                if (displCv && !displCv.value) displCv.value = (data.summary.displacement.cv || 0).toFixed(2);
                                if (displMax && !displMax.value) displMax.value = (data.summary.displacement.max || 0).toFixed(2);
                                if (displMin && !displMin.value) displMin.value = (data.summary.displacement.min || 0).toFixed(2);
                            }
                        }
                    }, 400);
                }, 200);
            }
            
            // Pre-fill Grab Tensile Test data
            if (testName === 'Grab Tensile Test' && data.grab_data) {
                setTimeout(() => {
                    data.grab_data.forEach((row, idx) => {
                        const i = idx + 1;
                        const posSelect = document.querySelector(`select[name="grab_position_${i}"]`);
                        const dirSelect = document.querySelector(`select[name="grab_direction_${i}"]`);
                        const forceInput = document.querySelector(`input[name="grab_breaking_force_${i}"]`);
                        const elongInput = document.querySelector(`input[name="grab_elongation_${i}"]`);
                        
                        if (posSelect) posSelect.value = row.position || '';
                        if (dirSelect) dirSelect.value = row.direction || '';
                        if (forceInput) forceInput.value = row.breaking_force || '';
                        if (elongInput) elongInput.value = row.elongation || '';
                    });
                    
                    // Trigger summary recalculation after pre-filling
                    setTimeout(() => {
                        const grabBody = document.querySelector('[id^="grabBody_"]');
                        if (grabBody && typeof recalcGrabSummary === 'function') {
                            const suffixId = grabBody.id.replace('grabBody_', '');
                            recalcGrabSummary(suffixId);
                        }
                    }, 300);
                    
                    // Pre-fill summary statistics
                    setTimeout(() => {
                        if (data.summary) {
                            ['md', 'cd'].forEach(dir => {
                                if (data.summary[dir]) {
                                    const stats = data.summary[dir];
                                    
                                    // Breaking Force stats
                                    const forceAvg = document.querySelector(`input[name="grab_${dir}_breaking_force_avg"]`);
                                    const forceSd = document.querySelector(`input[name="grab_${dir}_breaking_force_sd"]`);
                                    const forceCv = document.querySelector(`input[name="grab_${dir}_breaking_force_cv"]`);
                                    const forceMax = document.querySelector(`input[name="grab_${dir}_breaking_force_max"]`);
                                    const forceMin = document.querySelector(`input[name="grab_${dir}_breaking_force_min"]`);
                                    
                                    if (forceAvg) forceAvg.value = (stats.breaking_force_avg || 0).toFixed(2);
                                    if (forceSd) forceSd.value = (stats.breaking_force_sd || 0).toFixed(2);
                                    if (forceCv) forceCv.value = (stats.breaking_force_cv || 0).toFixed(2);
                                    if (forceMax) forceMax.value = (stats.breaking_force_max || 0).toFixed(2);
                                    if (forceMin) forceMin.value = (stats.breaking_force_min || 0).toFixed(2);
                                    
                                    // Elongation stats
                                    const elongAvg = document.querySelector(`input[name="grab_${dir}_elongation_avg"]`);
                                    const elongSd = document.querySelector(`input[name="grab_${dir}_elongation_sd"]`);
                                    const elongCv = document.querySelector(`input[name="grab_${dir}_elongation_cv"]`);
                                    const elongMax = document.querySelector(`input[name="grab_${dir}_elongation_max"]`);
                                    const elongMin = document.querySelector(`input[name="grab_${dir}_elongation_min"]`);
                                    
                                    if (elongAvg) elongAvg.value = (stats.elongation_avg || 0).toFixed(2);
                                    if (elongSd) elongSd.value = (stats.elongation_sd || 0).toFixed(2);
                                    if (elongCv) elongCv.value = (stats.elongation_cv || 0).toFixed(2);
                                    if (elongMax) elongMax.value = (stats.elongation_max || 0).toFixed(2);
                                    if (elongMin) elongMin.value = (stats.elongation_min || 0).toFixed(2);
                                }
                            });
                        }
                    }, 300);
                }, 200);
            }
            
            // Pre-fill Fiber/Yarn test data
            if (data.fiber_table_data && Array.isArray(data.fiber_table_data)) {
                data.fiber_table_data.forEach((row, idx) => {
                    // Handle Fineness of Fiber specific fields
                    if (row.parameter === 'Unit Weight') {
                        const unitWeightResult = document.querySelector('input[name="unit_weight_result"]');
                        const unitWeightRemarks = document.querySelector('input[name="unit_weight_remarks"]');
                        if (unitWeightResult) unitWeightResult.value = row.result || '';
                        if (unitWeightRemarks) unitWeightRemarks.value = row.remarks || '';
                    }
                    
                    // Handle generic indexed fields
                    const i = idx + 1;
                    const resultInput = document.querySelector(`input[name="fiber_result_${i}"]`);
                    const remarksInput = document.querySelector(`input[name="fiber_remarks_${i}"]`);
                    
                    if (resultInput) resultInput.value = row.result || '';
                    if (remarksInput) remarksInput.value = row.remarks || '';
                });
            }
            
            if (data.yarn_table_data && Array.isArray(data.yarn_table_data)) {
                data.yarn_table_data.forEach((row, idx) => {
                    if (row.parameter && row.parameter.includes('Tenacity at Break')) {
                        const input = document.querySelector('input[name="yarn_tenacity_at_break"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="yarn_tenacity_at_break_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    } else if (row.parameter && row.parameter.includes('Std Deviation')) {
                        const input = document.querySelector('input[name="yarn_std_deviation"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="yarn_std_deviation_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    } else if (row.parameter && row.parameter.includes('CV%')) {
                        const input = document.querySelector('input[name="yarn_cv_percent"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="yarn_cv_percent_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    } else if (row.parameter && row.parameter.includes('Elongation at Break')) {
                        const input = document.querySelector('input[name="yarn_elongation_at_break"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="yarn_elongation_at_break_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    }
                });
            }
            
            // Pre-fill Tenacity of Fiber test data
            if (data.tenacity_table_data && Array.isArray(data.tenacity_table_data)) {
                data.tenacity_table_data.forEach((row, idx) => {
                    if (row.parameter && row.parameter.includes('Tenacity at Break')) {
                        const input = document.querySelector('input[name="tenacity_at_break"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="tenacity_at_break_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    } else if (row.parameter && row.parameter.includes('Std Deviation')) {
                        const input = document.querySelector('input[name="std_deviation"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="std_deviation_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    } else if (row.parameter && row.parameter.includes('CV%')) {
                        const input = document.querySelector('input[name="cv_percentage"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="cv_percentage_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    } else if (row.parameter && row.parameter.includes('Elongation at Break')) {
                        const input = document.querySelector('input[name="elongation_at_break"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="elongation_at_break_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    } else if (row.parameter && row.parameter.includes('Breaking Force')) {
                        const input = document.querySelector('input[name="breaking_force"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="breaking_force_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    }
                });
            }
            
            // Pre-fill Cut Length of Fiber test data
            if (data.cut_length_table_data && Array.isArray(data.cut_length_table_data)) {
                data.cut_length_table_data.forEach((row, idx) => {
                    if (row.parameter && row.parameter.includes('Cut Length')) {
                        const input = document.querySelector('input[name="cut_length_result"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="cut_length_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    }
                });
            }
            
            // Pre-fill fiber/yarn sample info fields
            if (data.fiber_sample_name) {
                const input = document.querySelector('input[name="fiber_sample_name"]');
                if (input) input.value = data.fiber_sample_name;
            }
            if (data.yarn_sample_description) {
                const input = document.querySelector('input[name="yarn_sample_description"]');
                if (input) input.value = data.yarn_sample_description;
            }
            if (data.yarn_sample_received_from) {
                const input = document.querySelector('input[name="yarn_sample_received_from"]');
                if (input) input.value = data.yarn_sample_received_from;
            }
            if (data.yarn_sample_collected_from) {
                const input = document.querySelector('input[name="yarn_sample_collected_from"]');
                if (input) input.value = data.yarn_sample_collected_from;
            }
            
            console.log('Test data pre-filled successfully');
        }
        });
    </script>
    <?php endif; ?>

    <!-- Admin Approval Modal with Destination -->
    <div id="approvalModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; overflow-y:auto;">
      <div style="background:#fff; max-width:550px; margin:50px auto; padding:35px; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
        <h2 style="margin-top:0; color:#28a745; margin-bottom:20px;"><i class="fas fa-check-circle"></i> Approve Roll & Select Destination</h2>
        <form method="POST" id="approvalForm">
          <input type="hidden" name="admin_report_number" id="approvalReportNumber">
          <input type="hidden" name="admin_action" value="approved">
          
          <div style="margin-bottom:20px;">
            <label style="display:block; margin-bottom:8px; font-weight:600; color:#333;">
              <i class="fas fa-route"></i> Select Roll Destination: <span style="color:red;">*</span>
            </label>
            <select name="roll_destination" required style="width:100%; padding:12px; border:1px solid #ced4da; border-radius:6px; font-size:14px;">
              <option value="">-- Select Destination --</option>
              <option value="fg_production">FG Production (Finished Goods)</option>
              <option value="bag_production">Bag Production</option>
            </select>
            <small style="color:#6c757d; display:block; margin-top:5px;">
              Choose where this approved roll should go next.
            </small>
          </div>
          
          <div style="margin-bottom:20px;">
            <label style="display:block; margin-bottom:8px; font-weight:600; color:#333;">
              <i class="fas fa-comment"></i> Comments (Optional):
            </label>
            <textarea name="admin_comment" style="width:100%; padding:12px; border:1px solid #ced4da; border-radius:6px; min-height:80px; font-size:14px; font-family:inherit;" placeholder="Add any remarks..."></textarea>
          </div>
          
          <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:25px; padding-top:20px; border-top:1px solid #dee2e6;">
            <button type="button" onclick="closeApprovalModal()" style="padding:10px 20px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:14px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#5a6268'" onmouseout="this.style.background='#6c757d'">
              Cancel
            </button>
            <button type="submit" style="padding:10px 20px; background:#28a745; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:14px; font-weight:600; transition:background 0.3s;" onmouseover="this.style.background='#218838'" onmouseout="this.style.background='#28a745'">
              <i class="fas fa-check"></i> Approve & Set Destination
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Checker Rejection Modal -->
    <div id="checkerRejectModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; overflow-y:auto;">
      <div style="background:#fff; max-width:550px; margin:50px auto; padding:35px; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
        <h2 style="margin-top:0; color:#f44336; margin-bottom:20px;"><i class="fas fa-exclamation-triangle"></i> Reject QC Test Order</h2>
        <form method="POST" id="checkerRejectForm">
          <input type="hidden" name="checker_report_number" id="checkerRejectReportNumber">
          <input type="hidden" name="checker_report_numbers" id="checkerRejectReportNumbers">
          <input type="hidden" name="checker_action" value="rejected">
          
          <div class="form-group" style="margin-bottom:25px;">
            <label style="font-weight:600; margin-bottom:12px; display:block; color:#333; font-size:15px;">Rejection Reasons: <span style="color:#dc3545;">*</span></label>
            <div style="background:#f8f9fa; padding:15px; border-radius:6px; border:1px solid #dee2e6;">
              <label style="display:flex; align-items:center; padding:10px; margin-bottom:8px; background:#fff; border-radius:4px; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#e9ecef'" onmouseout="this.style.background='#fff'">
                <input type="checkbox" name="rejection_reasons[]" value="Incomplete data" style="width:18px; height:18px; margin-right:12px; cursor:pointer;">
                <span style="color:#333; font-size:14px;">Incomplete data</span>
              </label>
              <label style="display:flex; align-items:center; padding:10px; margin-bottom:8px; background:#fff; border-radius:4px; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#e9ecef'" onmouseout="this.style.background='#fff'">
                <input type="checkbox" name="rejection_reasons[]" value="Incorrect measurements" style="width:18px; height:18px; margin-right:12px; cursor:pointer;">
                <span style="color:#333; font-size:14px;">Incorrect measurements</span>
              </label>
              <label style="display:flex; align-items:center; padding:10px; margin-bottom:8px; background:#fff; border-radius:4px; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#e9ecef'" onmouseout="this.style.background='#fff'">
                <input type="checkbox" name="rejection_reasons[]" value="Missing required fields" style="width:18px; height:18px; margin-right:12px; cursor:pointer;">
                <span style="color:#333; font-size:14px;">Missing required fields</span>
              </label>
              <label style="display:flex; align-items:center; padding:10px; margin-bottom:8px; background:#fff; border-radius:4px; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#e9ecef'" onmouseout="this.style.background='#fff'">
                <input type="checkbox" name="rejection_reasons[]" value="Data inconsistency" style="width:18px; height:18px; margin-right:12px; cursor:pointer;">
                <span style="color:#333; font-size:14px;">Data inconsistency</span>
              </label>
              <label style="display:flex; align-items:center; padding:10px; background:#fff; border-radius:4px; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#e9ecef'" onmouseout="this.style.background='#fff'">
                <input type="checkbox" name="rejection_reasons[]" value="Calculation errors" style="width:18px; height:18px; margin-right:12px; cursor:pointer;">
                <span style="color:#333; font-size:14px;">Calculation errors</span>
              </label>
            </div>
          </div>
          
          <div class="form-group" style="margin-bottom:25px;">
            <label for="checkerRejectComment" style="font-weight:600; display:block; color:#333; font-size:15px; margin-bottom:8px;">Additional Comments: <span style="color:#999; font-size:13px;">(Optional)</span></label>
            <textarea name="checker_comment" id="checkerRejectComment" rows="4" style="width:100%; padding:12px; border:1px solid #dee2e6; border-radius:6px; font-family:inherit; font-size:14px; resize:vertical;" placeholder="Add any additional details about the rejection..."></textarea>
          </div>
          
          <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:25px; padding-top:20px; border-top:1px solid #dee2e6;">
            <button type="button" onclick="closeCheckerRejectModal()" style="padding:10px 20px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:14px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#5a6268'" onmouseout="this.style.background='#6c757d'">
              Cancel
            </button>
            <button type="submit" style="padding:10px 20px; background:#dc3545; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:14px; font-weight:600; transition:background 0.3s;" onmouseover="this.style.background='#c82333'" onmouseout="this.style.background='#dc3545'">
              <i class="fas fa-times"></i> Reject Report
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Admin Rejection Modal -->
    <div id="adminRejectModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; overflow-y:auto;">
      <div style="background:#fff; max-width:550px; margin:50px auto; padding:35px; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
        <h2 class="modal-title" style="margin-top:0; color:#f44336; margin-bottom:20px;"><i class="fas fa-exclamation-triangle"></i> Reject Report</h2>
        <form method="POST" id="adminRejectForm">
          <input type="hidden" name="admin_report_number" id="adminRejectReportNumber">
          <input type="hidden" name="admin_report_numbers" id="adminRejectReportNumbers">
          <input type="hidden" name="admin_action" value="rejected">
          
          <div class="form-group" style="margin-bottom:25px;">
            <label for="adminRejectComment" style="font-weight:600; display:block; color:#333; font-size:15px; margin-bottom:10px;">Rejection Reason: <span style="color:#dc3545;">*</span></label>
            <textarea name="admin_comment" id="adminRejectComment" rows="5" style="width:100%; padding:12px; border:1px solid #dee2e6; border-radius:6px; font-family:inherit; font-size:14px; resize:vertical;" placeholder="Please provide detailed reason for rejection..." required></textarea>
            <p style="color:#6c757d; font-size:12px; margin:8px 0 0 0;">This reason will be shown to the tester so they can make corrections.</p>
          </div>
          
          <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:25px; padding-top:20px; border-top:1px solid #dee2e6;">
            <button type="button" onclick="closeAdminRejectModal()" style="padding:10px 20px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:14px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#5a6268'" onmouseout="this.style.background='#6c757d'">
              Cancel
            </button>
            <button type="submit" style="padding:10px 20px; background:#dc3545; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:14px; font-weight:600; transition:background 0.3s;" onmouseover="this.style.background='#c82333'" onmouseout="this.style.background='#dc3545'">
              <i class="fas fa-times"></i> Reject Report
            </button>
          </div>
        </form>
      </div>
    </div>

    <script>
    function confirmApproval() {
      return confirm('Are you sure you want to approve this QC Test Order?');
    }

    function openCheckerRejectModal(reportNumber) {
      document.getElementById('checkerRejectReportNumber').value = reportNumber;
      document.getElementById('checkerRejectReportNumbers').value = ''; // Clear bulk field
      document.getElementById('checkerRejectModal').style.display = 'block';
    }

    function openBulkCheckerRejectModal(reportNumbersStr, count) {
      document.getElementById('checkerRejectReportNumbers').value = reportNumbersStr;
      document.getElementById('checkerRejectReportNumber').value = ''; // Clear single field
      document.getElementById('checkerRejectModal').style.display = 'block';
      // Update modal title to show bulk action
      var modalTitle = document.querySelector('#checkerRejectModal .modal-title');
      if (modalTitle) {
        modalTitle.textContent = 'Reject ' + count + ' Report(s)';
      }
    }

    function closeCheckerRejectModal() {
      document.getElementById('checkerRejectModal').style.display = 'none';
      document.getElementById('checkerRejectForm').reset();
      document.getElementById('checkerRejectReportNumbers').value = '';
      // Reset modal title
      var modalTitle = document.querySelector('#checkerRejectModal .modal-title');
      if (modalTitle) {
        modalTitle.textContent = 'Reject Report';
      }
    }

    function viewBulkReports(reportNumbers) {
      // Open each report in a new tab
      reportNumbers.forEach(function(reportNumber) {
        window.open('../admin/view_qc_test_order.php?report_number=' + encodeURIComponent(reportNumber), '_blank');
      });
    }

    function confirmBulkApproval(count) {
      return confirm('Are you sure you want to approve all ' + count + ' report(s)? This action will forward them to AGM/Admin for final approval.');
    }

    function openApprovalModal(reportNumber) {
      document.getElementById('approvalReportNumber').value = reportNumber;
      document.getElementById('approvalModal').style.display = 'block';
    }

    function closeApprovalModal() {
      document.getElementById('approvalModal').style.display = 'none';
      document.getElementById('approvalForm').reset();
    }

    function openAdminRejectModal(reportNumber) {
      document.getElementById('adminRejectReportNumber').value = reportNumber;
      document.getElementById('adminRejectReportNumbers').value = ''; // Clear bulk field
      document.getElementById('adminRejectModal').style.display = 'block';
    }

    function openBulkAdminRejectModal(reportNumbersStr, count) {
      document.getElementById('adminRejectReportNumbers').value = reportNumbersStr;
      document.getElementById('adminRejectReportNumber').value = ''; // Clear single field
      document.getElementById('adminRejectModal').style.display = 'block';
      // Update modal title to show bulk action
      var modalTitle = document.querySelector('#adminRejectModal .modal-title');
      if (modalTitle) {
        modalTitle.textContent = 'Reject ' + count + ' Report(s)';
      }
    }

    function closeAdminRejectModal() {
      document.getElementById('adminRejectModal').style.display = 'none';
      document.getElementById('adminRejectForm').reset();
      document.getElementById('adminRejectReportNumbers').value = '';
      // Reset modal title
      var modalTitle = document.querySelector('#adminRejectModal .modal-title');
      if (modalTitle) {
        modalTitle.textContent = 'Reject Report';
      }
    }

    function confirmBulkAdminApproval(count) {
      return confirm('Are you sure you want to approve all ' + count + ' report(s)? This action will finalize the approval.');
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
      const approvalModal = document.getElementById('approvalModal');
      const checkerModal = document.getElementById('checkerRejectModal');
      const adminModal = document.getElementById('adminRejectModal');
      if (event.target === approvalModal) {
        closeApprovalModal();
      }
      if (event.target === checkerModal) {
        closeCheckerRejectModal();
      }
      if (event.target === adminModal) {
        closeAdminRejectModal();
      }
    }
    </script>
</body>
</html>
