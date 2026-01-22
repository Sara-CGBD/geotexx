<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$is_admin = in_array($user_role, ['admin', 'agm', 'agm ops', 'agm operations']);

if (!$is_admin) {
    header("Location: ../admin/qc_test_approval_dashboard.php?error=" . urlencode('Access Denied'));
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

try {
    $action = $_POST['action'] ?? '';
    $report_number = trim($_POST['report_number'] ?? '');
    $test_type = trim($_POST['test_type'] ?? 'qc_test_order'); // Default to qc_test_order for backward compatibility
    $comment = trim($_POST['admin_comment'] ?? '');
    $destination = trim($_POST['roll_destination'] ?? '');
    $is_external = isset($_POST['is_external']) && $_POST['is_external'] == '1';
    
    // Debug logging
    error_log("QC Test Approval Handler - Action: $action, Report Number: $report_number, Test Type: $test_type");
    
    // Map test types to table names and field names
    $table_map = [
        'qc_test_order' => ['table' => 'qc_test_orders', 'ref_field' => 'sample_reference_id', 'approved_by_field' => 'approved_by'],
        'water_permeability' => ['table' => 'water_permeability_tests', 'ref_field' => 'reference_number', 'approved_by_field' => 'approved_by'],
        'characteristics' => ['table' => 'characteristics_tests', 'ref_field' => 'reference_number', 'approved_by_field' => 'approver_name'],
        'sun_test' => ['table' => 'sun_test_reports', 'ref_field' => 'reference_number', 'approved_by_field' => 'approved_by'],
        'uv_test' => ['table' => 'weathering_exposure_reports', 'ref_field' => 'reference', 'approved_by_field' => 'approved_by']
    ];
    
    if (!isset($table_map[$test_type])) {
        throw new Exception("Invalid test type: " . $test_type);
    }
    
    $table_info = $table_map[$test_type];
    $table = $table_info['table'];
    $ref_field = $table_info['ref_field'];
    $approved_by_field = $table_info['approved_by_field'];
    
    // Check if this is a bulk operation (comma-separated report numbers)
    $report_numbers = [];
    if (strpos($report_number, ',') !== false) {
        $report_numbers = array_map('trim', explode(',', $report_number));
        $report_numbers = array_filter($report_numbers); // Remove empty values
    } else {
        $report_numbers = [$report_number];
    }
    
    if (empty($report_numbers) || empty($report_numbers[0])) {
        throw new Exception("Report number is required");
    }
    
    if ($action === 'approve') {
        // Destination optional; routing happens separately (only for qc_test_orders)
        if ($is_external || $test_type !== 'qc_test_order') {
            $destination = ''; // ignore for external or non-qc_test_order tests
        }
        $destination = $destination ?: null;

        $status = 'approved';
        $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
        $approved_at = date('Y-m-d H:i:s');
        $success_count = 0;
        $processed_refs = [];
        
        // Process each report number
        foreach ($report_numbers as $rpt_num) {
            // Trim whitespace and normalize the report number
            $rpt_num = trim($rpt_num);
            if (empty($rpt_num)) {
                error_log("QC Test Approval: Empty report number provided");
                continue;
            }
            
            // First, verify the record exists and get current status
            // Use COLLATE to handle case-insensitive matching
            $checkStmt = $conn->prepare("SELECT id, status, report_number FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ? LIMIT 1");
            $checkStmt->bind_param("s", $rpt_num);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if (!$checkResult || $checkResult->num_rows === 0) {
                // Try to find similar report numbers for debugging
                $debugStmt = $conn->prepare("SELECT report_number FROM $table WHERE report_number LIKE ? LIMIT 5");
                $searchPattern = '%' . $rpt_num . '%';
                $debugStmt->bind_param("s", $searchPattern);
                $debugStmt->execute();
                $debugResult = $debugStmt->get_result();
                $similar = [];
                while ($row = $debugResult->fetch_assoc()) {
                    $similar[] = $row['report_number'];
                }
                $debugStmt->close();
                
                error_log("QC Test Approval: Report not found - report_number: '$rpt_num', test_type: $test_type, table: $table. Similar reports: " . implode(', ', $similar));
                $checkStmt->close();
                continue; // Skip this report number
            }
            
            $checkRow = $checkResult->fetch_assoc();
            $current_status = $checkRow['status'] ?? '';
            $actual_report_number = $checkRow['report_number'] ?? $rpt_num; // Use actual DB value
            $checkStmt->close();
            
            // Build UPDATE query based on test type
            // Use the actual report_number from DB to ensure exact match
            if ($test_type === 'qc_test_order') {
                $stmt = $conn->prepare("UPDATE $table 
                                       SET status = ?, $approved_by_field = ?, approved_at = ?, admin_remarks = ?, roll_destination = ?, updated_at = NOW() 
                                       WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                $stmt->bind_param("ssssss", $status, $approved_by, $approved_at, $comment, $destination, $actual_report_number);
            } elseif ($test_type === 'characteristics') {
                $stmt = $conn->prepare("UPDATE $table 
                                       SET status = ?, $approved_by_field = ?, approved_at = ?, remarks = ?, updated_at = NOW() 
                                       WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                $stmt->bind_param("sssss", $status, $approved_by, $approved_at, $comment, $actual_report_number);
            } else {
                // Water Permeability, Sun Test, UV Test
                $stmt = $conn->prepare("UPDATE $table 
                                       SET status = ?, $approved_by_field = ?, approved_at = ?, remarks = ?, updated_at = NOW() 
                                       WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                $stmt->bind_param("sssss", $status, $approved_by, $approved_at, $comment, $actual_report_number);
            }
        
            if ($stmt->execute()) {
                // Check if any rows were actually updated
                if ($stmt->affected_rows > 0) {
                    $success_count++;
                
                    // Get the reference number (only for qc_test_orders to update roll_qc_reports)
                    if ($test_type === 'qc_test_order') {
                        $refStmt = $conn->prepare("SELECT $ref_field FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ? LIMIT 1");
                        $refStmt->bind_param("s", $actual_report_number);
                        $refStmt->execute();
                        $refResult = $refStmt->get_result();
                        if ($refResult && $refRow = $refResult->fetch_assoc()) {
                            $reference_number = $refRow[$ref_field] ?? '';
                            if (!empty($reference_number) && !in_array($reference_number, $processed_refs)) {
                                $processed_refs[] = $reference_number;
                                // Check if roll_qc_reports table exists and has approval columns
                                $conn->query("ALTER TABLE roll_qc_reports ADD COLUMN IF NOT EXISTS approved TINYINT(1) DEFAULT 0");
                                $conn->query("ALTER TABLE roll_qc_reports ADD COLUMN IF NOT EXISTS approved_by VARCHAR(255) NULL");
                                $conn->query("ALTER TABLE roll_qc_reports ADD COLUMN IF NOT EXISTS approved_at TIMESTAMP NULL");
                                
                                // Update roll_qc_reports to mark as approved
                                $updateRqcStmt = $conn->prepare("UPDATE roll_qc_reports 
                                                                 SET approved = 1, approved_by = ?, approved_at = NOW(), overall_status = 'approved'
                                                                 WHERE reference_number = ?");
                                $updateRqcStmt->bind_param("ss", $approved_by, $reference_number);
                                $updateRqcStmt->execute();
                                $updateRqcStmt->close();
                            }
                        }
                        $refStmt->close();
                    }
                } else {
                    // No rows were updated - report number might not exist or already processed
                    error_log("QC Test Approval: No rows updated for report_number: $rpt_num, test_type: $test_type, table: $table");
                }
            } else {
                // Query execution failed
                error_log("QC Test Approval: Query execution failed for report_number: $rpt_num, test_type: $test_type, error: " . $stmt->error);
            }
            $stmt->close();
        }
            
        if ($success_count > 0) {
            $dest_text = ($destination === 'fg_production') ? 'FG' : 'Bag Production';
            if (count($report_numbers) > 1) {
                $message = "✅ $success_count QC Test(s) approved for $dest_text successfully!";
            } else {
                $message = "✅ QC Test " . $report_numbers[0] . " approved for $dest_text successfully!";
            }
            header("Location: ../admin/qc_test_approval_dashboard.php?success=" . urlencode($message));
            exit();
        } else {
            $error_msg = "Failed to approve any reports. ";
            if (count($report_numbers) === 1) {
                $error_msg .= "Report number '" . $report_numbers[0] . "' not found or already processed.";
            } else {
                $error_msg .= "None of the report numbers were found or could be updated.";
            }
            throw new Exception($error_msg);
        }
        
    } elseif ($action === 'reject') {
        // Handle rejection reasons from checkboxes
        $rejection_reasons = $_POST['rejection_reasons'] ?? [];
        $other_reason = trim($_POST['other_reason'] ?? '');
        
        if (empty($rejection_reasons)) {
            throw new Exception("Please select at least one rejection reason");
        }
        
        // Build rejection text
        $reasons_text = implode(', ', $rejection_reasons);
        if (in_array('Other', $rejection_reasons) && !empty($other_reason)) {
            $reasons_text = str_replace('Other', 'Other: ' . $other_reason, $reasons_text);
        }
        
        // Combine with additional comments if provided
        $final_comment = "Rejection Reasons: " . $reasons_text;
        if (!empty($comment)) {
            $final_comment .= "\n\nAdditional Comments: " . $comment;
        }
        
        // Set rejection status based on test type
        if ($test_type === 'qc_test_order') {
            $status = 'rejected_by_approver';
        } else {
            $status = 'rejected';
        }
        
        $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
        $approved_at = date('Y-m-d H:i:s');
        $success_count = 0;
        
        // Process each report number
        foreach ($report_numbers as $rpt_num) {
            // Trim whitespace and normalize the report number
            $rpt_num = trim($rpt_num);
            if (empty($rpt_num)) {
                error_log("QC Test Rejection: Empty report number provided");
                continue;
            }
            
            // First, verify the record exists
            // Use COLLATE to handle case-insensitive matching
            $checkStmt = $conn->prepare("SELECT id, status, report_number FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ? LIMIT 1");
            $checkStmt->bind_param("s", $rpt_num);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if (!$checkResult || $checkResult->num_rows === 0) {
                // Try to find similar report numbers for debugging
                $debugStmt = $conn->prepare("SELECT report_number FROM $table WHERE report_number LIKE ? LIMIT 5");
                $searchPattern = '%' . $rpt_num . '%';
                $debugStmt->bind_param("s", $searchPattern);
                $debugStmt->execute();
                $debugResult = $debugStmt->get_result();
                $similar = [];
                while ($row = $debugResult->fetch_assoc()) {
                    $similar[] = $row['report_number'];
                }
                $debugStmt->close();
                
                error_log("QC Test Rejection: Report not found - report_number: '$rpt_num', test_type: $test_type, table: $table. Similar reports: " . implode(', ', $similar));
                $checkStmt->close();
                continue; // Skip this report number
            }
            
            $checkRow = $checkResult->fetch_assoc();
            $actual_report_number = $checkRow['report_number'] ?? $rpt_num; // Use actual DB value
            $checkStmt->close();
            
            // Build UPDATE query based on test type
            // Use the actual report_number from DB to ensure exact match
            if ($test_type === 'qc_test_order') {
                $stmt = $conn->prepare("UPDATE $table 
                                       SET status = ?, $approved_by_field = ?, approved_at = ?, admin_remarks = ?, updated_at = NOW() 
                                       WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                $stmt->bind_param("sssss", $status, $approved_by, $approved_at, $final_comment, $actual_report_number);
            } elseif ($test_type === 'characteristics') {
                $stmt = $conn->prepare("UPDATE $table 
                                       SET status = ?, $approved_by_field = ?, remarks = ?, updated_at = NOW() 
                                       WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                $stmt->bind_param("ssss", $status, $approved_by, $final_comment, $actual_report_number);
            } else {
                // Water Permeability, Sun Test, UV Test
                $stmt = $conn->prepare("UPDATE $table 
                                       SET status = ?, $approved_by_field = ?, remarks = ?, updated_at = NOW() 
                                       WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                $stmt->bind_param("ssss", $status, $approved_by, $final_comment, $actual_report_number);
            }
        
            if ($stmt->execute()) {
                // Check if any rows were actually updated
                if ($stmt->affected_rows > 0) {
                    $success_count++;
                } else {
                    // No rows were updated - report number might not exist or already processed
                    error_log("QC Test Rejection: No rows updated for report_number: $rpt_num, test_type: $test_type, table: $table");
                }
            } else {
                // Query execution failed
                error_log("QC Test Rejection: Query execution failed for report_number: $rpt_num, test_type: $test_type, error: " . $stmt->error);
            }
            $stmt->close();
        }
        
        if ($success_count > 0) {
            if (count($report_numbers) > 1) {
                $message = "❌ $success_count QC Test(s) rejected successfully!";
            } else {
                $message = "❌ QC Test " . $report_numbers[0] . " rejected successfully!";
            }
            header("Location: ../admin/qc_test_approval_dashboard.php?success=" . urlencode($message));
            exit();
        } else {
            $error_msg = "Failed to reject any reports. ";
            if (count($report_numbers) === 1) {
                $error_msg .= "Report number '" . $report_numbers[0] . "' not found or already processed.";
            } else {
                $error_msg .= "None of the report numbers were found or could be updated.";
            }
            throw new Exception($error_msg);
        }
        
    } else {
        throw new Exception("Invalid action");
    }
    
} catch (Exception $e) {
    error_log("QC Test Approval Error: " . $e->getMessage());
    header("Location: ../admin/qc_test_approval_dashboard.php?error=" . urlencode($e->getMessage()));
    exit();
}


