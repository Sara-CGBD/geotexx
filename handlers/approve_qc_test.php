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
    $comment = trim($_POST['admin_comment'] ?? '');
    $destination = trim($_POST['roll_destination'] ?? '');
    $is_external = isset($_POST['is_external']) && $_POST['is_external'] == '1';
    
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
        // Destination optional; routing happens separately
        if ($is_external) {
            $destination = ''; // ignore for external
        }
        $destination = $destination ?: null;

        $status = 'approved';
        $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
        $approved_at = date('Y-m-d H:i:s');
        $success_count = 0;
        $processed_refs = [];
        
        // Process each report number
        foreach ($report_numbers as $rpt_num) {
        $stmt = $conn->prepare("UPDATE qc_test_orders 
                               SET status = ?, approved_by = ?, approved_at = ?, admin_remarks = ?, roll_destination = ?, updated_at = NOW() 
                               WHERE report_number = ?");
            $stmt->bind_param("ssssss", $status, $approved_by, $approved_at, $comment, $destination, $rpt_num);
        
        if ($stmt->execute()) {
                $success_count++;
            
            // Get the reference number from the QC test order
            $refStmt = $conn->prepare("SELECT sample_reference_id FROM qc_test_orders WHERE report_number = ? LIMIT 1");
                $refStmt->bind_param("s", $rpt_num);
            $refStmt->execute();
            $refResult = $refStmt->get_result();
            if ($refResult && $refRow = $refResult->fetch_assoc()) {
                $reference_number = $refRow['sample_reference_id'] ?? '';
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
            throw new Exception("Failed to approve any reports");
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
        
        $status = 'rejected_by_approver';
        $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
        $approved_at = date('Y-m-d H:i:s');
        $success_count = 0;
        
        // Process each report number
        foreach ($report_numbers as $rpt_num) {
        $stmt = $conn->prepare("UPDATE qc_test_orders 
                               SET status = ?, approved_by = ?, approved_at = ?, admin_remarks = ?, updated_at = NOW() 
                               WHERE report_number = ?");
            $stmt->bind_param("sssss", $status, $approved_by, $approved_at, $final_comment, $rpt_num);
        
        if ($stmt->execute()) {
                $success_count++;
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
            throw new Exception("Failed to reject any reports");
        }
        
    } else {
        throw new Exception("Invalid action");
    }
    
} catch (Exception $e) {
    error_log("QC Test Approval Error: " . $e->getMessage());
    header("Location: ../admin/qc_test_approval_dashboard.php?error=" . urlencode($e->getMessage()));
    exit();
}


