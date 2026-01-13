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

// Only admin and AGM Ops can access
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if (!in_array($user_role, ['admin', 'agm ops', 'agm operations', 'checker'])) {
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
    'fiber' => 'fiber_test_reports',
    'fabric_pre' => 'fabric_pre_production_tests',
    'fabric_after' => 'fabric_after_production_tests',
    'sun' => 'sun_test_reports',
    'water_perm' => 'water_permeability_tests',
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
    
    // Process all reports in the bulk group
    foreach ($all_report_numbers as $current_report_number) {
    if ($action === 'approve') {
        // For QC Test Orders, use different field names
        if ($report_type === 'qc_test_order') {
            $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = NOW() WHERE report_number = ?");
                $stmt->bind_param("sss", $approver, $approved_at, $current_report_number);
        } elseif ($report_type === 'characteristics') {
            // Characteristics tests use approver_name instead of approved_by
            $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approver_name = ?, approved_at = ? WHERE report_number = ?");
                $stmt->bind_param("sss", $approver, $approved_at, $current_report_number);
        } else {
            $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approved_by = ? WHERE report_number = ?");
                $stmt->bind_param("ss", $approver, $current_report_number);
        }
    } else {
        // For QC Test Orders, use admin_remarks instead of remarks
        if ($report_type === 'qc_test_order') {
            $status = 'rejected_by_approver';
            $stmt = $conn->prepare("UPDATE $table SET status = ?, admin_remarks = ?, approved_by = ?, approved_at = ?, updated_at = NOW() WHERE report_number = ?");
                $stmt->bind_param("sssss", $status, $comments, $approver, $approved_at, $current_report_number);
        } elseif ($report_type === 'characteristics') {
            // Characteristics tests use approver_name instead of approved_by
            $stmt = $conn->prepare("UPDATE $table SET status = 'rejected', remarks = ?, approver_name = ? WHERE report_number = ?");
                $stmt->bind_param("sss", $comments, $approver, $current_report_number);
            } else {
                $stmt = $conn->prepare("UPDATE $table SET status = 'rejected', remarks = ?, approved_by = ? WHERE report_number = ?");
                $stmt->bind_param("sss", $comments, $approver, $current_report_number);
            }
        }
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $success_count++;
        } else {
            $failed_reports[] = $current_report_number;
        }
        $stmt->close();
    }
    
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
        echo json_encode(['success' => false, 'message' => 'Failed to ' . $action . ' report(s). Reports may not exist or already processed.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>


