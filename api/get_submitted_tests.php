<?php
session_start();
require_once '../forms/security_config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$reference = $_GET['reference'] ?? '';

if (empty($reference)) {
    echo json_encode(['success' => false, 'error' => 'Reference is required']);
    exit();
}

try {
    $conn = SecurityConfig::getConnection();
    
    // Get all submitted tests for this reference
    $stmt = $conn->prepare("
        SELECT DISTINCT ts.test_name, qto.chosen_method as method
        FROM qc_test_orders qto
        JOIN test_standards ts ON qto.test_standard_id = ts.id
        WHERE qto.sample_reference_id = ?
        ORDER BY ts.test_name, qto.chosen_method
    ");
    
    $stmt->bind_param("s", $reference);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $tests = [];
    while ($row = $result->fetch_assoc()) {
        $tests[] = [
            'test_name' => $row['test_name'],
            'method' => $row['method']
        ];
    }
    
    $stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'tests' => $tests
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>


