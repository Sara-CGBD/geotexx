<?php
/**
 * API endpoint for AJAX-based report approval/rejection
 * Returns JSON response for dynamic dashboard updates
 */

session_start();
require_once __DIR__ . '/../../config/security_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only admin and AGM can access
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if (!in_array($user_role, ['admin', 'agm', 'agm ops', 'agm operations', 'checker'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';
$report_type = $_POST['report_type'] ?? '';
$report_number = $_POST['report_number'] ?? '';
$comments = $_POST['comments'] ?? '';
$related_report_numbers = isset($_POST['related_report_numbers']) ? trim($_POST['related_report_numbers']) : '';
$roll_destination = $_POST['roll_destination'] ?? '';

if (empty($action) || empty($report_type) || empty($report_number)) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

// Parse related report numbers if provided
$all_report_numbers = [$report_number];
if (!empty($related_report_numbers)) {
    $related = array_filter(array_map('trim', explode(',', $related_report_numbers)));
    $all_report_numbers = array_merge($all_report_numbers, $related);
}

// If report_number contains commas, it's a bulk operation - parse it
if (strpos($report_number, ',') !== false) {
    $all_report_numbers = array_filter(array_map('trim', explode(',', $report_number)));
}

// For QC test orders, automatically find all reports with the same bulk reference range
if ($report_type === 'qc_test_order') {
    $conn = SecurityConfig::getConnection();
    
    // Get the current report's test_data to check for bulk reference range
    $getReportStmt = $conn->prepare("SELECT test_data, test_standard_id, chosen_method, status FROM qc_test_orders WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
    $getReportStmt->bind_param("s", $report_number);
    $getReportStmt->execute();
    $reportResult = $getReportStmt->get_result();
    $currentReport = $reportResult->fetch_assoc();
    $getReportStmt->close();
    
    if ($currentReport) {
        $test_data = json_decode($currentReport['test_data'], true) ?? [];
        
        // Check if this is a bulk reference submission
        if (isset($test_data['is_bulk_reference']) && $test_data['is_bulk_reference'] && 
            isset($test_data['bulk_from_reference']) && isset($test_data['bulk_to_reference'])) {
            
            $bulk_from = $test_data['bulk_from_reference'];
            $bulk_to = $test_data['bulk_to_reference'];
            $test_standard_id = $currentReport['test_standard_id'];
            $chosen_method = $currentReport['chosen_method'];
            $current_status = $currentReport['status'];
            
            // Find all reports with the same bulk reference range, test, and method
            // For approval: find all with same range that are pending_approval
            // For rejection: find all with same range and same status
            $status_filter = ($action === 'approve') ? "AND qto.status = 'pending_approval'" : "AND qto.status = ?";
            
            if ($action === 'approve') {
                $findRelatedStmt = $conn->prepare("
                    SELECT qto.report_number, qto.test_data
                    FROM qc_test_orders qto
                    WHERE qto.test_standard_id = ?
                    AND qto.chosen_method = ?
                    AND qto.status = 'pending_approval'
                    AND qto.report_number COLLATE utf8mb4_unicode_ci != ?
                ");
                $findRelatedStmt->bind_param("iss", $test_standard_id, $chosen_method, $report_number);
            } else {
                $findRelatedStmt = $conn->prepare("
                    SELECT qto.report_number, qto.test_data
                    FROM qc_test_orders qto
                    WHERE qto.test_standard_id = ?
                    AND qto.chosen_method = ?
                    AND qto.status = ?
                    AND qto.report_number COLLATE utf8mb4_unicode_ci != ?
                ");
                $findRelatedStmt->bind_param("isss", $test_standard_id, $chosen_method, $current_status, $report_number);
            }
            
            $findRelatedStmt->execute();
            $relatedResult = $findRelatedStmt->get_result();
            
            // Verify each report has the same bulk reference range
            while ($row = $relatedResult->fetch_assoc()) {
                $related_test_data = json_decode($row['test_data'] ?? '{}', true);
                if (isset($related_test_data['is_bulk_reference']) && $related_test_data['is_bulk_reference'] &&
                    isset($related_test_data['bulk_from_reference']) && isset($related_test_data['bulk_to_reference']) &&
                    $related_test_data['bulk_from_reference'] === $bulk_from &&
                    $related_test_data['bulk_to_reference'] === $bulk_to) {
                    if (!in_array($row['report_number'], $all_report_numbers)) {
                        $all_report_numbers[] = $row['report_number'];
                    }
                }
            }
            $findRelatedStmt->close();
        }
    }
}

// Handle rejection reasons checkboxes
if ($action === 'reject' && isset($_POST['qc_rejection_reasons']) && is_array($_POST['qc_rejection_reasons'])) {
    $rejection_reasons = array_map('trim', $_POST['qc_rejection_reasons']);
    $reasons_text = implode(', ', $rejection_reasons);
    $comments = "Rejection Reasons: " . $reasons_text . ($comments ? "\n\nAdditional Comments: " . $comments : '');
}

$table_map = [
    'qc_test_order' => 'qc_test_orders',
    'sewing' => 'sewing_thread_reports',
    'uv' => 'weathering_exposure_reports',
    'uv_test' => 'weathering_exposure_reports', // Also support uv_test format
    'fiber' => 'fiber_test_reports',
    'fabric_pre' => 'fabric_pre_production_tests',
    'fabric_after' => 'fabric_after_production_tests',
    'sun' => 'sun_test_reports',
    'sun_test' => 'sun_test_reports', // Also support sun_test format
    'water_perm' => 'water_permeability_tests',
    'water_permeability' => 'water_permeability_tests', // Also support water_permeability format
    'characteristics' => 'characteristics_tests'
];

if (!isset($table_map[$report_type])) {
    echo json_encode(['success' => false, 'message' => 'Invalid report type']);
    exit;
}

$table = $table_map[$report_type];
$conn = SecurityConfig::getConnection();
$approver = $_SESSION['full_name'] ?? $_SESSION['username'];

try {
    $success_count = 0;
    $failed_reports = [];
    $approved_at = date('Y-m-d H:i:s');
    
    // Handle routing action separately (it processes all report numbers at once)
    if ($action === 'route') {
        // Handle routing for already approved tests
        if (empty($roll_destination)) {
            echo json_encode(['success' => false, 'message' => 'Routing destination is required']);
            exit;
        }
        
        // Ensure roll_destination column exists
        $checkRollDest = $conn->query("SHOW COLUMNS FROM $table LIKE 'roll_destination'");
        if ($checkRollDest && $checkRollDest->num_rows === 0) {
            @$conn->query("ALTER TABLE $table ADD COLUMN roll_destination VARCHAR(255) NULL");
        }
        
        // Handle comma-separated report numbers (bulk routing)
        $report_nums_to_route = [];
        if (strpos($report_number, ',') !== false) {
            $report_nums_to_route = array_filter(array_map('trim', explode(',', $report_number)));
        } else {
            $report_nums_to_route = [$report_number];
        }
        
        // Update routing destination for all report numbers
        foreach ($report_nums_to_route as $report_num) {
            if ($report_type === 'qc_test_order') {
                $stmt = $conn->prepare("UPDATE $table SET roll_destination = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status = 'approved'");
                $stmt->bind_param("ss", $roll_destination, $report_num);
            } elseif ($report_type === 'characteristics') {
                $stmt = $conn->prepare("UPDATE $table SET roll_destination = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status = 'approved'");
                $stmt->bind_param("ss", $roll_destination, $report_num);
            } else {
                // Water Permeability, Sun Test, UV Test
                $stmt = $conn->prepare("UPDATE $table SET roll_destination = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status = 'approved'");
                $stmt->bind_param("ss", $roll_destination, $report_num);
            }
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success_count++;
            } else {
                $failed_reports[] = $report_num;
            }
            $stmt->close();
        }
        
        // Return success/failure for routing
        if ($success_count > 0) {
            $message = count($report_nums_to_route) > 1 
                ? "Routed {$success_count} report(s) successfully!"
                : "Routed successfully!";
            
            if (!empty($failed_reports)) {
                $message .= " Failed: " . implode(', ', $failed_reports);
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'report_number' => $report_number,
                'report_type' => $report_type,
                'action' => $action,
                'total_processed' => $success_count
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to route report(s). Reports may not exist or are not approved.']);
        }
        exit;
    }
    
    // Process all reports in the bulk group for approve/reject actions
    // First, verify which report numbers actually exist in the database
    $valid_report_numbers = [];
    $invalid_report_numbers = [];
    
    foreach ($all_report_numbers as $current_report_number) {
        $checkStmt = $conn->prepare("SELECT report_number, status FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
        $checkStmt->bind_param("s", $current_report_number);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult && $checkResult->num_rows > 0) {
            $valid_report_numbers[] = $current_report_number;
        } else {
            $invalid_report_numbers[] = $current_report_number;
        }
        $checkStmt->close();
    }
    
    // If no valid reports found, return error
    if (empty($valid_report_numbers)) {
        $error_details = !empty($invalid_report_numbers) ? ' All report numbers not found: ' . implode(', ', $invalid_report_numbers) : '';
        echo json_encode(['success' => false, 'message' => 'Failed to ' . $action . ' report(s). No valid reports found.' . $error_details]);
        exit;
    }
    
    // Log invalid report numbers but continue processing valid ones
    if (!empty($invalid_report_numbers)) {
        error_log("Warning: Some report numbers not found in database: " . implode(', ', $invalid_report_numbers));
    }
    
    // For non-QC test orders (water_permeability, characteristics, sun, uv), check for bundle_reference ranges
    // If a report has a bundle_reference with pipe separator (from|to), find all reports with the same bundle_reference
    $reports_to_process = [];
    if (in_array($report_type, ['water_permeability', 'water_perm', 'characteristics', 'sun_test', 'sun', 'uv_test', 'uv'])) {
        // Get bundle_reference from the first valid report
        $first_report_number = $valid_report_numbers[0];
        $getBundleStmt = $conn->prepare("SELECT bundle_reference FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
        $getBundleStmt->bind_param("s", $first_report_number);
        $getBundleStmt->execute();
        $bundleResult = $getBundleStmt->get_result();
        $bundleRow = $bundleResult->fetch_assoc();
        $bundle_reference = $bundleRow['bundle_reference'] ?? null;
        $getBundleStmt->close();
        
        // If bundle_reference exists and contains a pipe (|), it's a range - find all reports with same bundle_reference
        if ($bundle_reference && strpos($bundle_reference, '|') !== false) {
            // Determine status filter based on action
            $status_filter = '';
            if ($action === 'approve') {
                $status_filter = "AND status IN ('checked', 'pending')";
            } else {
                // For rejection, get current status from first report
                $getStatusStmt = $conn->prepare("SELECT status FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                $getStatusStmt->bind_param("s", $first_report_number);
                $getStatusStmt->execute();
                $statusResult = $getStatusStmt->get_result();
                $statusRow = $statusResult->fetch_assoc();
                $current_status = $statusRow['status'] ?? '';
                $getStatusStmt->close();
                
                if (!empty($current_status)) {
                    $status_filter = "AND status = ?";
                }
            }
            
            // Find all reports with the same bundle_reference
            if ($action === 'approve') {
                $findBulkStmt = $conn->prepare("SELECT report_number FROM $table WHERE bundle_reference = ? $status_filter");
                $findBulkStmt->bind_param("s", $bundle_reference);
            } else {
                $findBulkStmt = $conn->prepare("SELECT report_number FROM $table WHERE bundle_reference = ? $status_filter");
                $findBulkStmt->bind_param("ss", $bundle_reference, $current_status);
            }
            
            $findBulkStmt->execute();
            $bulkResult = $findBulkStmt->get_result();
            while ($bulkRow = $bulkResult->fetch_assoc()) {
                $reports_to_process[] = $bulkRow['report_number'];
            }
            $findBulkStmt->close();
            
            error_log("Bulk reference range detected: $bundle_reference. Found " . count($reports_to_process) . " reports to process.");
        } else {
            // No bundle reference range, process only the provided report numbers
            $reports_to_process = $valid_report_numbers;
        }
    } else {
        // For QC test orders, use the valid report numbers (bulk range handling already done above)
        $reports_to_process = $valid_report_numbers;
    }
    
    // If no reports to process, return error
    if (empty($reports_to_process)) {
        echo json_encode(['success' => false, 'message' => 'Failed to ' . $action . ' report(s). No reports found in bulk reference range.']);
        exit;
    }
    
    // Process all reports in the bulk range
    foreach ($reports_to_process as $current_report_number) {
    if ($action === 'approve') {
        // Ensure roll_destination column exists for all tables
        $checkRollDest = $conn->query("SHOW COLUMNS FROM $table LIKE 'roll_destination'");
        if ($checkRollDest && $checkRollDest->num_rows === 0) {
            @$conn->query("ALTER TABLE $table ADD COLUMN roll_destination VARCHAR(255) NULL");
        }
        
        // For QC Test Orders, use different field names and include roll_destination
        if ($report_type === 'qc_test_order') {
            if (!empty($roll_destination)) {
                $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approved_by = ?, approved_at = ?, roll_destination = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status = 'pending_approval'");
                $stmt->bind_param("ssss", $approver, $approved_at, $roll_destination, $current_report_number);
            } else {
                $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status = 'pending_approval'");
                $stmt->bind_param("sss", $approver, $approved_at, $current_report_number);
            }
        } elseif ($report_type === 'characteristics') {
            // Characteristics tests use approver_name instead of approved_by
            // Status should be 'checked' or 'pending' for characteristics tests
            if (!empty($roll_destination)) {
                $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approver_name = ?, approved_at = ?, roll_destination = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status IN ('checked', 'pending')");
                $stmt->bind_param("ssss", $approver, $approved_at, $roll_destination, $current_report_number);
            } else {
                $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approver_name = ?, approved_at = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status IN ('checked', 'pending')");
                $stmt->bind_param("sss", $approver, $approved_at, $current_report_number);
            }
        } else {
            // Water Permeability, Sun Test, UV Test - include roll_destination and approved_at
            // Status should be 'checked' or 'pending' for these tests
            if (!empty($roll_destination)) {
                $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approved_by = ?, approved_at = ?, roll_destination = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status IN ('checked', 'pending')");
                $stmt->bind_param("ssss", $approver, $approved_at, $roll_destination, $current_report_number);
            } else {
                $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status IN ('checked', 'pending')");
                $stmt->bind_param("sss", $approver, $approved_at, $current_report_number);
            }
        }
        
        // Execute the approval statement
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $success_count++;
            } else {
                // No rows affected - check if report exists and its current status
                $checkStmt = $conn->prepare("SELECT status FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                $checkStmt->bind_param("s", $current_report_number);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                if ($checkRow = $checkResult->fetch_assoc()) {
                    $current_status = $checkRow['status'];
                    if ($current_status === 'approved') {
                        error_log("Approval skipped for report {$current_report_number} (type: {$report_type}): Already approved.");
                        // Don't count as failed if already approved
                        $success_count++;
                    } else {
                        $failed_reports[] = $current_report_number . " (status: {$current_status})";
                        error_log("Approval failed for report {$current_report_number} (type: {$report_type}): Current status is '{$current_status}', expected 'pending_approval' or 'checked'.");
                    }
                } else {
                    $failed_reports[] = $current_report_number . " (not found)";
                    error_log("Approval failed for report {$current_report_number} (type: {$report_type}): Report does not exist.");
                }
                $checkStmt->close();
            }
        } else {
            // Execution failed
            $failed_reports[] = $current_report_number . " (error: " . $stmt->error . ")";
            error_log("Approval failed for report {$current_report_number} (type: {$report_type}): " . $stmt->error);
        }
        $stmt->close();
    } elseif ($action === 'reject') {
        // For QC Test Orders, use admin_remarks instead of remarks
        if ($report_type === 'qc_test_order') {
            $status = 'rejected_by_approver';
            $stmt = $conn->prepare("UPDATE $table SET status = ?, admin_remarks = ?, approved_by = ?, approved_at = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status = 'pending_approval'");
            $stmt->bind_param("sssss", $status, $comments, $approver, $approved_at, $current_report_number);
        } elseif ($report_type === 'characteristics') {
            // Characteristics tests use approver_name instead of approved_by
            // Status should be 'checked' or 'pending' for characteristics tests
            $stmt = $conn->prepare("UPDATE $table SET status = 'rejected', remarks = ?, approver_name = ?, approved_at = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status IN ('checked', 'pending')");
            $stmt->bind_param("ssss", $comments, $approver, $approved_at, $current_report_number);
        } else {
            // Water Permeability, Sun Test, UV Test
            // Status should be 'checked' or 'pending' for these tests
            $stmt = $conn->prepare("UPDATE $table SET status = 'rejected', remarks = ?, approved_by = ?, approved_at = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status IN ('checked', 'pending')");
            $stmt->bind_param("ssss", $comments, $approver, $approved_at, $current_report_number);
        }
        
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $success_count++;
            } else {
                // No rows affected - check if report exists and its current status
                $checkStmt = $conn->prepare("SELECT status FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                $checkStmt->bind_param("s", $current_report_number);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                if ($checkRow = $checkResult->fetch_assoc()) {
                    $current_status = $checkRow['status'];
                    if (in_array($current_status, ['rejected', 'rejected_by_approver', 'rejected_by_checker'])) {
                        error_log("Rejection skipped for report {$current_report_number} (type: {$report_type}): Already rejected.");
                        // Don't count as failed if already rejected
                        $success_count++;
                    } else {
                        $failed_reports[] = $current_report_number . " (status: {$current_status})";
                        error_log("Rejection failed for report {$current_report_number} (type: {$report_type}): Current status is '{$current_status}', expected 'pending_approval' or 'checked'.");
                    }
                } else {
                    $failed_reports[] = $current_report_number . " (not found)";
                    error_log("Rejection failed for report {$current_report_number} (type: {$report_type}): Report does not exist.");
                }
                $checkStmt->close();
            }
        } else {
            // Execution failed
            $failed_reports[] = $current_report_number . " (error: " . $stmt->error . ")";
            error_log("Rejection failed for report {$current_report_number} (type: {$report_type}): " . $stmt->error);
        }
        $stmt->close();
    }
    } // Close foreach loop
    
    if ($success_count > 0) {
        $message = count($all_report_numbers) > 1 
            ? ucfirst($action) . "d {$success_count} report(s) successfully!"
            : ucfirst($action) . "d successfully! Report: " . $report_number;
        
        if (!empty($failed_reports)) {
            $message .= " Failed: " . implode(', ', $failed_reports);
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'report_number' => $report_number,
            'report_type' => $report_type,
            'action' => $action,
            'total_processed' => $success_count
        ]);
    } else {
        $error_details = !empty($failed_reports) ? ' Details: ' . implode(', ', $failed_reports) : '';
        echo json_encode(['success' => false, 'message' => 'Failed to ' . $action . ' report(s). Reports may not exist or already processed.' . $error_details]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>


