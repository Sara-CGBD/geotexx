<?php
session_start();
require_once '../config/security_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$role = strtolower(trim($_SESSION['role'] ?? 'user'));

// Only Admin and AGM can approve
if (!in_array($role, ['admin', 'agm', 'agm ops', 'agm operations', 'management'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

date_default_timezone_set('Asia/Dhaka');

$input = json_decode(file_get_contents('php://input'), true);
$type = $input['type'] ?? ''; // 'gsm' or 'lc'
$entryId = $input['entry_id'] ?? '';
$action = $input['action'] ?? ''; // 'approve' or 'reject'
$reason = $input['reason'] ?? '';

if (empty($type) || empty($entryId) || empty($action)) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit();
}

$conn = SecurityConfig::getConnection();

try {
    $approvedBy = $_SESSION['username'];
    $approvedAt = date('Y-m-d H:i:s');
    $status = $action === 'approve' ? 'approved' : 'rejected';
    
    if ($type === 'gsm') {
        // Ensure columns exist for older schemas
        @$conn->query("ALTER TABLE daily_gsm_checks ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL");
        // Update daily_gsm_checks
        $stmt = $conn->prepare("UPDATE daily_gsm_checks 
                               SET status = ?, approved_by = ?, approved_at = ?, rejection_reason = ? 
                               WHERE entry_id = ?");
        $stmt->bind_param("sssss", $status, $approvedBy, $approvedAt, $reason, $entryId);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'GSM Check ' . $status]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No records updated']);
        }
        $stmt->close();
    } elseif ($type === 'lc') {
        // Ensure columns exist for older schemas
        @$conn->query("ALTER TABLE length_calibrations ADD COLUMN IF NOT EXISTS approved_by VARCHAR(100) NULL");
        @$conn->query("ALTER TABLE length_calibrations ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL");
        @$conn->query("ALTER TABLE length_calibrations ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL");
        // Update length_calibrations
        $stmt = $conn->prepare("UPDATE length_calibrations 
                               SET status = ?, approved_by = ?, approved_at = ?, rejection_reason = ? 
                               WHERE entry_id = ?");
        $stmt->bind_param("sssss", $status, $approvedBy, $approvedAt, $reason, $entryId);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'Length Calibration ' . $status]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No records updated']);
        }
        $stmt->close();
    } elseif ($type === 'qc_entry') {
        // Update qc_entries
        $stmt = $conn->prepare("UPDATE qc_entries 
                               SET status = ?, approved_by = ?, approved_at = ?, rejection_reason = ? 
                               WHERE id = ?");
        $stmt->bind_param("ssssi", $status, $approvedBy, $approvedAt, $reason, $entryId);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            echo json_encode(['success' => true, 'message' => 'QC Entry ' . $status]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No records updated']);
        }
        $stmt->close();
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid type']);
    }
    
    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>


