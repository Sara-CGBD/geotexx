<?php
/**
 * API to fetch which individual rolls from a bundle have already been tested
 * for Weathering Exposure Test (UV Test)
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
    
    // Parse bundle reference to get base reference
    if (!preg_match('/-(\d+)$/', $bundleRef, $matches)) {
        echo json_encode(['success' => false, 'error' => 'Invalid bundle reference']);
        exit;
    }
    
    $baseRef = preg_replace('/-\d+$/', '', $bundleRef);
    
    // Find all individual roll references that have been tested for this bundle
    $testedRolls = [];
    
    $query = "SELECT DISTINCT reference 
              FROM weathering_exposure_reports 
              WHERE bundle_reference = ? 
              AND reference LIKE ? 
              AND status NOT IN ('rejected')";
    
    $pattern = $baseRef . '-%';
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $bundleRef, $pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $ref = $row['reference'];
        // Verify it matches individual roll pattern (baseRef-N)
        if (preg_match('/^' . preg_quote($baseRef, '/') . '-(\d+)$/', $ref)) {
            $testedRolls[] = $ref;
        }
    }
    
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'tested_rolls' => array_unique($testedRolls)
    ]);
    
    $conn->close();
    
} catch (Exception $e) {
    error_log("API Error in get_tested_rolls_uv.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
?>


