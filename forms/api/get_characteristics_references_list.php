<?php
/**
 * API to fetch list of reference numbers from roll_entry for Characteristics Test
 * Excludes references that have already been routed by AGM (same logic as QC Test Order)
 * Also excludes references that already have characteristics tests
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
    // Exclude references that already have characteristics tests
    $references = [];
    $bundleReferences = [];
    $processedBundles = []; // Track processed base references to avoid duplicates
    
    // Check if number_of_rolls column exists
    $checkCol = $conn->query("SHOW COLUMNS FROM roll_entry LIKE 'number_of_rolls'");
    $hasNumberOfRolls = ($checkCol && $checkCol->num_rows > 0);
    
    // Fetch all individual roll references from roll_entry
    // Show each roll separately if it hasn't been routed by AGM
    $refQuery = $conn->query("
        SELECT DISTINCT re.reference_number, 
               MAX(re.date_time) as created_at" . 
               ($hasNumberOfRolls ? ", MAX(re.number_of_rolls) as number_of_rolls" : "") . "
        FROM roll_entry re 
        LEFT JOIN characteristics_tests ct ON re.reference_number = ct.reference_number 
            AND ct.status IN ('pending', 'checked', 'approved')
        WHERE re.reference_number IS NOT NULL 
            AND re.reference_number != ''
            AND ct.reference_number IS NULL
        GROUP BY re.reference_number
        ORDER BY created_at DESC 
        LIMIT 200
    ");
    
    if ($refQuery) {
        while ($row = $refQuery->fetch_assoc()) {
            $ref = $row['reference_number'];
            
            // Detect line number (L1 or L2) from reference
            $lineIndicator = '';
            if (strpos($ref, 'L1') !== false) {
                $lineIndicator = 'L1';
            } elseif (strpos($ref, 'L2') !== false) {
                $lineIndicator = 'L2';
            }
            
            // Check if this reference has been routed by AGM
            $isRouted = $isReferenceRouted($conn, $ref, $routedTableExists);
            
            // Only add if not routed
            if (!$isRouted) {
                // Check if this is a bundle reference (ends with -N pattern)
                if (preg_match('/-(\d+)$/', $ref, $matches)) {
                    $rollCount = (int)$matches[1];
                    $baseRef = preg_replace('/-\d+$/', '', $ref);
                    
                    // This is an individual roll from a bundle - add it
                    $references[] = [
                        'reference' => $ref,
                        'line' => $lineIndicator,
                        'date' => $row['created_at']
                    ];
                } else {
                    // Single roll reference (not a bundle)
                    $references[] = [
                        'reference' => $ref,
                        'line' => $lineIndicator,
                        'date' => $row['created_at']
                    ];
                }
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
