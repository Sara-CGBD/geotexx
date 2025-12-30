<?php
/**
 * API to fetch which individual rolls from a bundle have already been tested
 * with a specific test method
 */

session_start();
require_once '../security_config.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = SecurityConfig::getConnection();
    
    $bundleRef = $_GET['bundle_ref'] ?? '';
    $testName = $_GET['test_name'] ?? '';
    $method = $_GET['method'] ?? '';
    
    if (empty($bundleRef) || empty($testName) || empty($method)) {
        echo json_encode(['success' => false, 'error' => 'Bundle reference, test name, and method are required']);
        exit;
    }
    
    // Extract base reference from bundle (remove the -N suffix)
    $baseRef = preg_replace('/-\d+$/', '', $bundleRef);
    
    // Get test standard ID
    $stmt = $conn->prepare("SELECT id FROM test_standards WHERE test_name = ? AND standard_code = ? LIMIT 1");
    $stmt->bind_param("ss", $testName, $method);
    $stmt->execute();
    $result = $stmt->get_result();
    $testStandard = $result->fetch_assoc();
    $stmt->close();
    
    if (!$testStandard) {
        echo json_encode(['success' => false, 'error' => 'Test standard not found']);
        exit;
    }
    
    $testStandardId = $testStandard['id'];
    
    // Find all individual roll references that have been tested with this method
    // We need to check test_data JSON for product_reference or individual_roll_reference
    // Also check sample_reference_id which might contain the individual roll reference
    $testedRolls = [];
    
    $query = "
        SELECT test_data, sample_reference_id 
        FROM qc_test_orders 
        WHERE test_standard_id = ? 
        AND status NOT IN ('rejected_by_checker', 'rejected_by_approver')
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $testStandardId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $testData = json_decode($row['test_data'], true) ?? [];
        
        // Check multiple sources for the individual roll reference
        $rollRef = null;
        
        // Priority 1: individual_roll_reference from test_data
        if (!empty($testData['individual_roll_reference'])) {
            $rollRef = $testData['individual_roll_reference'];
        }
        // Priority 2: product_reference from test_data (if it matches bundle pattern)
        elseif (!empty($testData['product_reference'])) {
            $productRef = $testData['product_reference'];
            // Check if it matches individual roll pattern (baseRef-N)
            if (preg_match('/^' . preg_quote($baseRef, '/') . '-(\d+)$/', $productRef)) {
                $rollRef = $productRef;
            }
        }
        // Priority 3: sample_reference_id (might contain individual roll reference)
        elseif (!empty($row['sample_reference_id'])) {
            $sampleRef = $row['sample_reference_id'];
            // Check if it matches individual roll pattern
            if (preg_match('/^' . preg_quote($baseRef, '/') . '-(\d+)$/', $sampleRef)) {
                $rollRef = $sampleRef;
            }
        }
        
        if (!empty($rollRef)) {
            // Double-check it matches our bundle pattern (baseRef-N)
            if (preg_match('/^' . preg_quote($baseRef, '/') . '-(\d+)$/', $rollRef)) {
                $testedRolls[] = $rollRef;
            }
        }
    }
    
    $stmt->close();
    
    // Remove duplicates
    $testedRolls = array_unique($testedRolls);
    $testedRolls = array_values($testedRolls); // Re-index array
    
    echo json_encode([
        'success' => true,
        'tested_rolls' => $testedRolls,
        'count' => count($testedRolls)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}


