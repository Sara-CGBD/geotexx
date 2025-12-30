<?php
/**
 * API to fetch the first test data from a bundle for Characteristics Test
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
    
    // Get the first test data from this bundle (ordered by created_at ASC)
    $query = "SELECT test_materials, gsm, roll_number, specimen_size, specimen_size_unit, sand_type, 
                     sand_weight, sand_weight_unit, sieving_time, sieving_time_unit, test_results
              FROM characteristics_tests 
              WHERE bundle_reference = ? 
              ORDER BY created_at ASC 
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $bundleRef);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // Parse test_results JSON to extract sieve_data
        $sieve_data = [];
        if (!empty($row['test_results'])) {
            $test_results = json_decode($row['test_results'], true);
            if (isset($test_results['sieve_data']) && is_array($test_results['sieve_data'])) {
                $sieve_data = $test_results['sieve_data'];
            }
        }
        
        echo json_encode([
            'success' => true,
            'data' => [
                'test_materials' => $row['test_materials'] ?? '',
                'gsm' => $row['gsm'] ?? '',
                'roll_number' => $row['roll_number'] ?? '',
                'specimen_size' => $row['specimen_size'] ?? '',
                'specimen_size_unit' => $row['specimen_size_unit'] ?? '',
                'sand_type' => $row['sand_type'] ?? '',
                'sand_weight' => $row['sand_weight'] ?? '',
                'sand_weight_unit' => $row['sand_weight_unit'] ?? '',
                'sieving_time' => $row['sieving_time'] ?? '',
                'sieving_time_unit' => $row['sieving_time_unit'] ?? '',
                'sieve_data' => $sieve_data
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'No previous test data found for this bundle'
        ]);
    }
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    error_log("API Error in get_first_bundle_test_data_char.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>


