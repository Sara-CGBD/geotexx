<?php
/**
 * API to fetch reference data from roll_entry
 * Returns all related data for a given roll reference number
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
    
    $reference_number = $_GET['reference'] ?? '';
    
    if (empty($reference_number)) {
        echo json_encode(['success' => false, 'error' => 'Reference number is required']);
        exit;
    }
    
    // Fetch data from roll_entry and fiber_to_roll_entry (for batch info)
    // First, extract base reference (remove -1, -2, etc. suffix)
    $base_reference = preg_replace('/-\d+$/', '', $reference_number);
    
    $stmt = $conn->prepare("
        SELECT 
            re.reference_number,
            re.material_type,
            re.total_weight,
            re.created_at,
            ftr.batch_info
        FROM roll_entry re
        LEFT JOIN fiber_to_roll_entry ftr ON ftr.reference_number = ?
        WHERE re.reference_number = ? 
        ORDER BY re.created_at DESC 
        LIMIT 1
    ");
    
    $stmt->bind_param("ss", $base_reference, $reference_number);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        
        // Return the data (roll_entry + batch_info from fiber_to_roll_entry)
        echo json_encode([
            'success' => true,
            'data' => [
                'reference_number' => $row['reference_number'] ?? '',
                'material_type' => $row['material_type'] ?? '',
                'total_weight' => $row['total_weight'] ?? '',
                'batch_info' => $row['batch_info'] ?? '', // Fetched from fiber_to_roll_entry
                'line_number' => '', // Not available
                'origin' => '' // Not available
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'error' => '❌ Invalid Reference Number.'
        ]);
    }
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>


