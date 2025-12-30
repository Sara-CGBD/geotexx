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

if (empty($action) || empty($report_type) || empty($report_number)) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
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
    if ($action === 'approve') {
        // For QC Test Orders, use different field names
        if ($report_type === 'qc_test_order') {
            $approved_at = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = NOW() WHERE report_number = ?");
            $stmt->bind_param("sss", $approver, $approved_at, $report_number);
        } elseif ($report_type === 'characteristics') {
            // Characteristics tests use approver_name instead of approved_by
            $approved_at = date('Y-m-d H:i:s');
            $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approver_name = ?, approved_at = ? WHERE report_number = ?");
            $stmt->bind_param("sss", $approver, $approved_at, $report_number);
        } else {
            $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approved_by = ? WHERE report_number = ?");
            $stmt->bind_param("ss", $approver, $report_number);
        }
    } else {
        // For QC Test Orders, use admin_remarks instead of remarks
        if ($report_type === 'qc_test_order') {
            $approved_at = date('Y-m-d H:i:s');
            $status = 'rejected_by_approver';
            $stmt = $conn->prepare("UPDATE $table SET status = ?, admin_remarks = ?, approved_by = ?, approved_at = ?, updated_at = NOW() WHERE report_number = ?");
            $stmt->bind_param("sssss", $status, $comments, $approver, $approved_at, $report_number);
        } elseif ($report_type === 'characteristics') {
            // Characteristics tests use approver_name instead of approved_by
            $stmt = $conn->prepare("UPDATE $table SET status = 'rejected', remarks = ?, approver_name = ? WHERE report_number = ?");
            $stmt->bind_param("sss", $comments, $approver, $report_number);
        } else {
            $stmt = $conn->prepare("UPDATE $table SET status = 'rejected', remarks = ?, approved_by = ? WHERE report_number = ?");
            $stmt->bind_param("sss", $comments, $approver, $report_number);
        }
    }
    
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $stmt->close();
        echo json_encode([
            'success' => true,
            'message' => ucfirst($action) . "d successfully! Report: " . $report_number,
            'report_number' => $report_number,
            'report_type' => $report_type,
            'action' => $action
        ]);
    } else {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Failed to ' . $action . ' report. Report may not exist or already processed.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>


