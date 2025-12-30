<?php
/**
 * API to fetch the first test data from a bundle for QC Test Order
 * Returns data from the first individual roll test to pre-fill form
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
    
    if (empty($bundleRef)) {
        echo json_encode(['success' => false, 'error' => 'Bundle reference is required']);
        exit;
    }
    
    // Extract base reference from bundle (remove the -N suffix)
    $baseRef = preg_replace('/-\d+$/', '', $bundleRef);
    
    // Get the first test data from this bundle (ordered by created_at ASC)
    // We need to check test_data JSON for bundle_reference or individual_roll_reference
    // Also check sample_reference_id which might contain the individual roll reference
    $query = "SELECT test_data, sample_reference_id 
              FROM qc_test_orders 
              WHERE status NOT IN ('rejected_by_checker', 'rejected_by_approver')
              ORDER BY created_at ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $firstTestData = null;
    
    while ($row = $result->fetch_assoc()) {
        $testData = json_decode($row['test_data'], true) ?? [];
        
        // Check if this test is from the current bundle
        $isFromBundle = false;
        
        // Check bundle_reference in test_data
        if (!empty($testData['bundle_reference']) && $testData['bundle_reference'] === $bundleRef) {
            $isFromBundle = true;
        }
        // Check individual_roll_reference or product_reference in test_data
        elseif (!empty($testData['individual_roll_reference'])) {
            $individualRef = $testData['individual_roll_reference'];
            if (preg_match('/^' . preg_quote($baseRef, '/') . '-(\d+)$/', $individualRef)) {
                $isFromBundle = true;
            }
        }
        elseif (!empty($testData['product_reference'])) {
            $productRef = $testData['product_reference'];
            if (preg_match('/^' . preg_quote($baseRef, '/') . '-(\d+)$/', $productRef)) {
                $isFromBundle = true;
            }
        }
        // Check sample_reference_id
        elseif (!empty($row['sample_reference_id'])) {
            $sampleRef = $row['sample_reference_id'];
            if (preg_match('/^' . preg_quote($baseRef, '/') . '-(\d+)$/', $sampleRef)) {
                $isFromBundle = true;
            }
        }
        
        if ($isFromBundle) {
            $firstTestData = $testData;
            break; // Found first test from bundle
        }
    }
    
    $stmt->close();
    
    if ($firstTestData) {
        // Return common form fields that should be pre-filled
        echo json_encode([
            'success' => true,
            'data' => [
                'batch_information' => $firstTestData['batch_information'] ?? '',
                'sample_details' => $firstTestData['sample_details'] ?? '',
                'sample_collected_from' => $firstTestData['sample_collected_from'] ?? '',
                'sample_received_datetime' => $firstTestData['sample_received_datetime'] ?? '',
                'sample_production_date' => $firstTestData['sample_production_date'] ?? '',
                'temperature' => $firstTestData['temperature'] ?? '',
                'rh_percentage' => $firstTestData['rh_percentage'] ?? '',
                'test_period_from' => $firstTestData['test_period_from'] ?? '',
                'test_period_to' => $firstTestData['test_period_to'] ?? '',
                'roll_number' => $firstTestData['roll_number'] ?? '',
                'gsm' => $firstTestData['gsm'] ?? '',
                'customer_reference' => $firstTestData['customer_reference'] ?? '',
                'sample_received_from' => $firstTestData['sample_received_from'] ?? '',
                'lighthouse_reference' => $firstTestData['lighthouse_reference'] ?? '',
                'other_info' => $firstTestData['other_info'] ?? ''
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'No previous test data found for this bundle'
        ]);
    }
    
    $conn->close();
    
} catch (Exception $e) {
    error_log("API Error in get_first_bundle_test_data_qc.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>


