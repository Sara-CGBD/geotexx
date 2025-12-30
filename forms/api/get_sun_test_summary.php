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
    
    // Query sun_test_reports
    $stmt = $conn->prepare("
        SELECT 
            id,
            report_number,
            reference_number,
            gsm,
            roll_number,
            batch_number,
            lab_test_number,
            sample_description,
            sample_received_from,
            sample_collected_from,
            sample_production_date,
            received_date,
            test_start_date,
            test_end_date,
            testing_method,
            test_name,
            test_speed,
            gauge_length,
            specimen_size,
            note,
            temperature,
            rh_percent,
            test_performed_by,
            approved_by,
            test_results,
            status,
            created_at
        FROM sun_test_reports
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


