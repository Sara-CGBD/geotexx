<?php
/**
 * API to fetch list of reference numbers from roll_entry for Water Permeability Test
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
    
    // Fetch reference numbers from roll_entry - support bundles
    // Tests are done AFTER roll entry submission
    $references = [];
    $bundleReferences = [];
    
    // Fetch references from roll_entry that have NOT been submitted for water permeability tests
    // Exclude references that already exist in water_permeability_tests table
    $result = $conn->query("
        SELECT DISTINCT re.reference_number, MAX(re.date_time) as created_at
        FROM roll_entry re
        LEFT JOIN water_permeability_tests wpt ON (
            re.reference_number = wpt.reference_number 
            OR re.reference_number LIKE CONCAT(wpt.reference_number, '-%')
            OR wpt.reference_number LIKE CONCAT(re.reference_number, '-%')
        )
        WHERE re.reference_number IS NOT NULL 
          AND re.reference_number != ''
          AND wpt.reference_number IS NULL
        GROUP BY re.reference_number
        ORDER BY created_at DESC 
        LIMIT 100
    ");
    
    // If query failed, log error and try simpler query
    if (!$result) {
        error_log("WPT API Query Error: " . $conn->error);
        // Fallback to just roll_entry without exclusion
        $result = $conn->query("
            SELECT DISTINCT re.reference_number, MAX(re.date_time) as created_at
            FROM roll_entry re
            WHERE re.reference_number IS NOT NULL 
              AND re.reference_number != ''
            GROUP BY re.reference_number
            ORDER BY created_at DESC 
            LIMIT 100
        ");
    }
    
    $totalFetched = 0;
    $skippedInvalid = 0;
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $ref = $row['reference_number'];
            $totalFetched++;
            
            // Skip invalid references (too short or missing letters)
            if (empty($ref) || strlen(trim($ref)) <= 3 || !preg_match('/[A-Za-z]/', $ref)) {
                $skippedInvalid++;
                continue; // Skip this reference
            }
            
            // Detect line number (L1 or L2) from reference
            $lineIndicator = '';
            if (strpos($ref, 'L1') !== false) {
                $lineIndicator = 'L1';
            } elseif (strpos($ref, 'L2') !== false) {
                $lineIndicator = 'L2';
            }
            
            // Check if this is a bundle reference (ends with -N pattern)
            if (preg_match('/-(\d+)$/', $ref, $matches)) {
                $rollCount = (int)$matches[1];
                $baseRef = preg_replace('/-\d+$/', '', $ref);
                
                $bundleReferences[] = [
                    'reference' => $ref,
                    'base_reference' => $baseRef,
                    'roll_count' => $rollCount,
                    'line' => $lineIndicator,
                    'date' => $row['created_at']
                ];
            } else {
                $references[] = [
                    'reference' => $ref,
                    'line' => $lineIndicator,
                    'date' => $row['created_at']
                ];
            }
        }
    }
    
    // Debug info (can be removed later)
    $debugInfo = [
        'total_fetched' => $totalFetched,
        'skipped_invalid' => $skippedInvalid,
        'valid_single' => count($references),
        'valid_bundles' => count($bundleReferences)
    ];
    
    echo json_encode([
        'success' => true,
        'references' => $references,
        'bundleReferences' => $bundleReferences,
        'debug' => $debugInfo  // Remove this in production if not needed
    ]);
    
    $conn->close();
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
