<?php
/**
 * API to fetch list of reference numbers from roll_entry for Sun Test Report
 * Excludes references that have already been routed by AGM (same logic as QC Test Order)
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
    
    // Check if routed table exists
    $routedTableExists = $conn->query("SHOW TABLES LIKE 'routed'")->num_rows > 0;
    
    // Helper function to check if a reference has been routed (check routed table)
    $isReferenceRouted = function($conn, $reference, $routedTableExists) {
        if (!$routedTableExists) {
            return false;
        }
        
        // Check exact match first (most reliable)
        $stmt = $conn->prepare("
            SELECT 1 
            FROM routed 
            WHERE reference_number = ? 
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('s', $reference);
            $stmt->execute();
            $result = $stmt->get_result();
            $routed = ($result && $result->num_rows > 0);
            $stmt->close();
            if ($routed) {
                return true;
            }
        }
        
        // Also check if the stored reference is a prefix of the query reference
        // e.g., stored: "2.0L126JAN16-R06" matches query: "2.0L126JAN16-R06-GT0.9.H0.1"
        $stmt = $conn->prepare("
            SELECT 1 
            FROM routed 
            WHERE ? LIKE CONCAT(reference_number, '%')
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('s', $reference);
            $stmt->execute();
            $result = $stmt->get_result();
            $routed = ($result && $result->num_rows > 0);
            $stmt->close();
            if ($routed) {
                return true;
            }
        }
        
        // Also check if the query reference is a prefix of stored reference
        // e.g., query: "2.0L126JAN16-R06" matches stored: "2.0L126JAN16-R06-GT0.9.H0.1"
        $stmt = $conn->prepare("
            SELECT 1 
            FROM routed 
            WHERE reference_number LIKE CONCAT(?, '%')
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('s', $reference);
            $stmt->execute();
            $result = $stmt->get_result();
            $routed = ($result && $result->num_rows > 0);
            $stmt->close();
            return $routed;
        }
        
        return false;
    };
    
    // Fetch reference numbers from roll_entry - support bundles
    // Tests are done AFTER roll entry submission
    $references = [];
    $bundleReferences = [];
    
    // Check if number_of_rolls column exists
    $checkCol = $conn->query("SHOW COLUMNS FROM roll_entry LIKE 'number_of_rolls'");
    $hasNumberOfRolls = ($checkCol && $checkCol->num_rows > 0);
    
    // Fetch all references first, then group by base reference in PHP
    // This avoids duplicates when multiple rolls are stored separately
    $refQuery = $conn->query("
        SELECT reference_number, 
               MAX(material_type) as material_type, 
               MAX(total_weight) as total_weight, 
               MAX(created_at) as created_at" . 
               ($hasNumberOfRolls ? ", MAX(number_of_rolls) as number_of_rolls" : "") . "
        FROM roll_entry 
        WHERE reference_number IS NOT NULL 
        GROUP BY reference_number
        ORDER BY created_at DESC 
        LIMIT 200
    ");
    
    // Group references by base reference to avoid duplicates
    $baseRefGroups = [];
    if ($refQuery) {
        while ($row = $refQuery->fetch_assoc()) {
            $ref = $row['reference_number'];
            
            // Check if this is a bundle reference (ends with -N pattern)
            if (preg_match('/-(\d+)$/', $ref, $matches)) {
                $baseRef = preg_replace('/-\d+$/', '', $ref);
                $rollNum = (int)$matches[1];
                
                // Group by base reference, keep track of highest roll number
                if (!isset($baseRefGroups[$baseRef]) || $rollNum > $baseRefGroups[$baseRef]['roll_num']) {
                    $baseRefGroups[$baseRef] = [
                        'reference' => $ref,
                        'roll_num' => $rollNum,
                        'created_at' => $row['created_at'],
                        'material_type' => $row['material_type'],
                        'total_weight' => $row['total_weight'],
                        'number_of_rolls' => $hasNumberOfRolls ? ($row['number_of_rolls'] ?? null) : null
                    ];
                }
            } else {
                // Single roll reference - add directly
                $baseRefGroups[$ref] = [
                    'reference' => $ref,
                    'roll_num' => 0,
                    'created_at' => $row['created_at'],
                    'material_type' => $row['material_type'],
                    'total_weight' => $row['total_weight'],
                    'number_of_rolls' => $hasNumberOfRolls ? ($row['number_of_rolls'] ?? null) : null
                ];
            }
        }
    }
    
    // Process grouped references
    foreach ($baseRefGroups as $baseRef => $groupData) {
        $ref = $groupData['reference'];
        $rollCount = $groupData['roll_num'];
        
        // Detect line number (L1 or L2) from reference
        $lineIndicator = '';
        if (strpos($ref, 'L1') !== false) {
            $lineIndicator = 'L1';
        } elseif (strpos($ref, 'L2') !== false) {
            $lineIndicator = 'L2';
        }
        
        // Check if this is a bundle reference (has roll count > 0)
        if ($rollCount > 0) {
            // This is a bundle reference
            // Check if the bundle reference itself has been routed
            $bundleRouted = $isReferenceRouted($conn, $ref, $routedTableExists);
            
            // Check how many individual rolls have been routed
            $routedRollCount = 0;
            $routedRolls = [];
            for ($i = 1; $i <= $rollCount; $i++) {
                $individualRef = $baseRef . '-' . $i;
                if ($isReferenceRouted($conn, $individualRef, $routedTableExists)) {
                    $routedRollCount++;
                    $routedRolls[] = $i;
                }
            }
            
            // Only add bundle if not all rolls are routed and bundle itself is not routed
            if (!$bundleRouted && $routedRollCount < $rollCount) {
                // Store as bundle (only if it has at least one non-routed roll)
                $bundleReferences[] = [
                    'reference' => $ref,
                    'base_reference' => $baseRef,
                    'roll_count' => $rollCount,
                    'line' => $lineIndicator,
                    'date' => $groupData['created_at']
                ];
                
                // DON'T add individual roll references - only show bundle to avoid duplicates
            }
        } else {
            // Single roll reference (not a bundle)
            // Check if this reference has been routed
            $isRouted = $isReferenceRouted($conn, $ref, $routedTableExists);
            
            // Only add if not routed
            if (!$isRouted) {
                $references[] = [
                    'reference' => $ref,
                    'line' => $lineIndicator,
                    'date' => $groupData['created_at']
                ];
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
