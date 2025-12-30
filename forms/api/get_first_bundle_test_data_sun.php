<?php
/**
 * API to fetch the first test data from a bundle for Sun Test
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
    $query = "SELECT reference_name, sample_description, sample_received_from, 
                     sample_collected_from, testing_method, test_name, test_speed, 
                     gauge_length, specimen_size, temperature, rh_percent
              FROM sun_test_reports 
              WHERE bundle_reference = ? 
              ORDER BY created_at ASC 
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $bundleRef);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        echo json_encode([
            'success' => true,
            'data' => [
                'reference_name' => $row['reference_name'] ?? '',
                'sample_description' => $row['sample_description'] ?? '',
                'sample_received_from' => $row['sample_received_from'] ?? '',
                'sample_collected_from' => $row['sample_collected_from'] ?? '',
                'testing_method' => $row['testing_method'] ?? '',
                'test_name' => $row['test_name'] ?? '',
                'test_speed' => $row['test_speed'] ?? '',
                'gauge_length' => $row['gauge_length'] ?? '',
                'specimen_size' => $row['specimen_size'] ?? '',
                'temperature' => $row['temperature'] ?? '',
                'rh_percent' => $row['rh_percent'] ?? ''
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
    error_log("API Error in get_first_bundle_test_data_sun.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>


