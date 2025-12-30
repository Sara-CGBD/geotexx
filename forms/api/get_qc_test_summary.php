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
    
    // Debug logging
    error_log("QC Summary API - Searching for reference: " . $reference);
    
    // Query QC test orders with this reference
    // Match by reference in test_data JSON OR sample_reference_id
    $stmt = $conn->prepare("
        SELECT 
            qto.id,
            qto.sample_reference_id,
            qto.report_number,
            qto.test_data,
            qto.created_at,
            ts.test_name,
            qto.chosen_method as method
        FROM qc_test_orders qto
        INNER JOIN test_standards ts ON qto.test_standard_id = ts.id
        WHERE (
            JSON_UNQUOTE(JSON_EXTRACT(qto.test_data, '$.product_reference')) = ?
            OR JSON_UNQUOTE(JSON_EXTRACT(qto.test_data, '$.fiber_reference_no')) = ?
            OR JSON_UNQUOTE(JSON_EXTRACT(qto.test_data, '$.yarn_reference_no')) = ?
            OR qto.sample_reference_id LIKE CONCAT('%', ?, '%')
        )
        ORDER BY qto.created_at DESC
    ");
    
    $stmt->bind_param("ssss", $reference, $reference, $reference, $reference);
    if (!$stmt->execute()) {
        error_log("QC Summary API - Execute error: " . $stmt->error);
        throw new Exception("Query execution failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    $tests = [];
    while ($row = $result->fetch_assoc()) {
        $tests[] = [
            'id' => $row['id'],
            'sample_reference_id' => $row['sample_reference_id'],
            'report_number' => $row['report_number'],
            'test_name' => $row['test_name'],
            'method' => $row['method'],
            'test_data' => $row['test_data'],
            'created_at' => $row['created_at']
        ];
    }
    
    error_log("QC Summary API - Found " . count($tests) . " tests for reference: " . $reference);
    error_log("QC Summary API - SQL: " . $stmt->sqlstate);
    
    $stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'tests' => $tests,
        'count' => count($tests),
        'debug_reference' => $reference
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}


