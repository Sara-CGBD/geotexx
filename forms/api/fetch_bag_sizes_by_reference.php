<?php
// API endpoint to fetch bag sizes for a given reference number from branding_entries
session_start();
require_once '../../config/security_config.php';

// Session & security checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

if (SecurityConfig::checkSessionTimeout()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session timeout']);
    exit();
}

SecurityConfig::updateSessionActivity();

if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Account locked']);
    exit();
}

header('Content-Type: application/json');

try {
    $conn = SecurityConfig::getConnection();
    
    // Get reference number from GET parameter
    $referenceNumber = isset($_GET['reference_number']) ? trim($_GET['reference_number']) : '';
    
    if (empty($referenceNumber)) {
        echo json_encode(['success' => false, 'error' => 'Reference number is required']);
        exit();
    }
    
    // Fetch distinct bag sizes for this reference number from branding_entries
    $stmt = $conn->prepare("
        SELECT DISTINCT bag_size 
        FROM branding_entries 
        WHERE reference_number = ? 
        AND bag_size IS NOT NULL 
        AND bag_size != ''
        AND (is_deleted = 0 OR is_deleted IS NULL)
        ORDER BY bag_size ASC
    ");
    
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    
    $stmt->bind_param('s', $referenceNumber);
    
    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    
    $result = $stmt->get_result();
    $bagSizes = [];
    
    while ($row = $result->fetch_assoc()) {
        $bagSizes[] = $row['bag_size'];
    }
    
    $stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'bag_sizes' => $bagSizes,
        'count' => count($bagSizes)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>


