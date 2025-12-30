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
    
    // Query characteristics_tests (Characteristics Test - Opening Size Calculation/AOS)
    $stmt = $conn->prepare("
        SELECT 
            id,
            report_number,
            reference_number,
            lab_test_number,
            test_standard,
            test_materials,
            gsm,
            roll_number,
            sample_id,
            specimen_size,
            specimen_size_unit,
            sand_type,
            sand_weight,
            sand_weight_unit,
            sieving_time,
            sieving_time_unit,
            sample_received,
            sample_tested,
            test_performed_by,
            approver_name,
            status,
            test_results,
            created_at
        FROM characteristics_tests
        WHERE reference_number = ? OR roll_number = ?
        ORDER BY created_at DESC
    ");
    
    $stmt->bind_param("ss", $reference, $reference);
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


