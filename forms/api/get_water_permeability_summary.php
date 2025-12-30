<?php
session_start();
require_once '../security_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $conn = SecurityConfig::getConnection();
    $reference = $_GET['reference'] ?? '';
    
    if (empty($reference)) {
        echo json_encode(['success' => false, 'error' => 'Reference number is required']);
        exit;
    }
    
    // Query water_permeability_tests
    $stmt = $conn->prepare("
        SELECT 
            id,
            report_number,
            sample_id,
            lab_test_number,
            reference_number,
            gsm,
            roll_number,
            test_date,
            shift,
            test_performed_by,
            approved_by,
            status,
            test_results,
            created_at
        FROM water_permeability_tests
        WHERE reference_number = ?
        ORDER BY created_at DESC
    ");
    
    $stmt->bind_param("s", $reference);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $tests = [];
    while ($row = $result->fetch_assoc()) {
        $tests[] = $row;
    }
    
    $stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'tests' => $tests,
        'count' => count($tests)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}


