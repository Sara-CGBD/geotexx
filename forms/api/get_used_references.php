<?php
session_start();
require_once '../security_config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$testName = $_GET['test_name'] ?? '';
$method = $_GET['method'] ?? '';

if (empty($testName) || empty($method)) {
    echo json_encode(['success' => false, 'error' => 'Test name and method are required']);
    exit();
}

try {
    $conn = SecurityConfig::getConnection();
    
    // Get all references that have already been used for this specific test and method
    $stmt = $conn->prepare("
        SELECT DISTINCT qto.sample_reference_id as reference
        FROM qc_test_orders qto
        JOIN test_standards ts ON qto.test_standard_id = ts.id
        WHERE ts.test_name = ? AND qto.chosen_method = ?
        ORDER BY qto.sample_reference_id
    ");
    
    $stmt->bind_param("ss", $testName, $method);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $references = [];
    while ($row = $result->fetch_assoc()) {
        $references[] = $row['reference'];
    }
    
    $stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'references' => $references
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>


