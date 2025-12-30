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
    
    if (empty($report_number)) {
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
        
        $stmt = $conn->prepare("UPDATE qc_test_orders 
                               SET status = ?, approved_by = ?, approved_at = ?, admin_remarks = ?, roll_destination = ?, updated_at = NOW() 
                               WHERE report_number = ?");
        $stmt->bind_param("ssssss", $status, $approved_by, $approved_at, $comment, $destination, $report_number);
        
        if ($stmt->execute()) {
            $stmt->close();
            $dest_text = ($destination === 'fg_production') ? 'FG' : 'Bag Production';
            $message = "✅ QC Test $report_number approved for $dest_text successfully!";
            header("Location: ../admin/qc_test_approval_dashboard.php?success=" . urlencode($message));
            exit();
        } else {
            throw new Exception("Failed to approve: " . $stmt->error);
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
        
        $stmt = $conn->prepare("UPDATE qc_test_orders 
                               SET status = ?, approved_by = ?, approved_at = ?, admin_remarks = ?, updated_at = NOW() 
                               WHERE report_number = ?");
        $stmt->bind_param("sssss", $status, $approved_by, $approved_at, $final_comment, $report_number);
        
        if ($stmt->execute()) {
            $stmt->close();
            $message = "❌ QC Test $report_number rejected successfully!";
            header("Location: ../admin/qc_test_approval_dashboard.php?success=" . urlencode($message));
            exit();
        } else {
            throw new Exception("Failed to reject: " . $stmt->error);
        }
        
    } else {
        throw new Exception("Invalid action");
    }
    
} catch (Exception $e) {
    error_log("QC Test Approval Error: " . $e->getMessage());
    header("Location: ../admin/qc_test_approval_dashboard.php?error=" . urlencode($e->getMessage()));
    exit();
}


