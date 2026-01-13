<?php
session_start();
require_once '../config/security_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$role = strtolower(trim($_SESSION['role'] ?? 'user'));

// Only Admin and AGM can toggle auto-approval
if (!in_array($role, ['admin', 'agm', 'agm ops', 'agm operations', 'management'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$testType = $input['test_type'] ?? '';
$enabled = (int)($input['enabled'] ?? 0);

if (empty($testType) || !in_array($testType, ['gsm', 'length_calibration'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid test type']);
    exit();
}

$conn = SecurityConfig::getConnection();

try {
    $userId = $_SESSION['user_id'];
    
    // Ensure table exists
    $conn->query("CREATE TABLE IF NOT EXISTS agm_auto_approval_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        test_type ENUM('gsm', 'length_calibration') NOT NULL,
        auto_approve_enabled TINYINT(1) DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_test (user_id, test_type),
        INDEX idx_user_id (user_id),
        INDEX idx_test_type (test_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Insert or update setting
    $stmt = $conn->prepare("INSERT INTO agm_auto_approval_settings (user_id, test_type, auto_approve_enabled) 
                           VALUES (?, ?, ?)
                           ON DUPLICATE KEY UPDATE auto_approve_enabled = ?, updated_at = CURRENT_TIMESTAMP");
    $stmt->bind_param("isii", $userId, $testType, $enabled, $enabled);
    $stmt->execute();
    $stmt->close();
    
    echo json_encode(['success' => true, 'message' => 'Auto-approval setting updated']);
    
    $conn->close();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
