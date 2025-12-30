<?php
/**
 * API to fetch list of reference numbers from fiber_to_roll_entry
 * Returns recent unique reference numbers for dropdown
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
    
    // Fetch reference numbers from roll_received (roll received entry) - support bundles
    $references = [];
    $bundleReferences = [];
    
    $result = $conn->query("
        SELECT DISTINCT r.reference_number, MAX(r.created_at) as created_at
        FROM roll_received r
        LEFT JOIN sun_test_reports s ON r.reference_number = s.reference_number
        WHERE r.reference_number IS NOT NULL 
          AND r.reference_number != ''
          AND r.is_deleted = 0
          AND s.reference_number IS NULL
        GROUP BY r.reference_number
        ORDER BY created_at DESC 
        LIMIT 100
    ");
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $ref = $row['reference_number'];
            
            // Check if this is a bundle reference (ends with -N pattern)
            if (preg_match('/-(\d+)$/', $ref, $matches)) {
                $rollCount = (int)$matches[1];
                $baseRef = preg_replace('/-\d+$/', '', $ref);
                
                $bundleReferences[] = [
                    'reference' => $ref,
                    'base_reference' => $baseRef,
                    'roll_count' => $rollCount
                ];
            } else {
                $references[] = $ref;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'references' => $references,
        'bundleReferences' => $bundleReferences
    ]);
    
    $conn->close();
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>


