<?php
session_start();
require_once '../config/security_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$role = strtolower(trim($_SESSION['role'] ?? 'user'));

// Only Admin and AGM can auto-approve
if (!in_array($role, ['admin', 'agm', 'agm ops', 'agm operations', 'management'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$testType = $input['test_type'] ?? ''; // 'gsm', 'length_calibration', or 'both'

if (empty($testType) || !in_array($testType, ['gsm', 'length_calibration', 'both'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid test type']);
    exit();
}

$conn = SecurityConfig::getConnection();

try {
    $userId = $_SESSION['user_id'];
    $approvedBy = $_SESSION['username'];
    $approvedAt = date('Y-m-d H:i:s');
    
    $gsmCount = 0;
    $lcCount = 0;
    
    // Auto-approve GSM checks
    if ($testType === 'gsm' || $testType === 'both') {
        $gsmResult = $conn->query("
            UPDATE daily_gsm_checks 
            SET status = 'approved', 
                approved_by = '" . $conn->real_escape_string($approvedBy) . "', 
                approved_at = '" . $approvedAt . "', 
                auto_approved = 1 
            WHERE status = 'pending' AND (auto_approved = 0 OR auto_approved IS NULL)
        ");
        $gsmCount = $conn->affected_rows;
    }
    
    // Auto-approve length calibrations
    if ($testType === 'length_calibration' || $testType === 'both') {
        $lcResult = $conn->query("
            UPDATE length_calibrations 
            SET status = 'approved', 
                approved_by = '" . $conn->real_escape_string($approvedBy) . "', 
                approved_at = '" . $approvedAt . "', 
                auto_approved = 1 
            WHERE status = 'pending' AND (auto_approved = 0 OR auto_approved IS NULL)
        ");
        $lcCount = $conn->affected_rows;
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Reports auto-approved successfully',
        'gsm_count' => $gsmCount,
        'lc_count' => $lcCount
    ]);
    
    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
