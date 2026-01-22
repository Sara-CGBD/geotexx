<?php
// Start output buffering to prevent any output before JSON
ob_start();

session_start();
require_once '../../config/security_config.php';

// Set JSON header
header('Content-Type: application/json');

// Clear any output buffer
ob_clean();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
$conn = SecurityConfig::getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Database connection error']);
    exit;
}

// Check if is_deleted column exists in roll_received
$hasReceivedIsDeleted = false;
$checkReceivedIsDeleted = $conn->query("SHOW COLUMNS FROM roll_received LIKE 'is_deleted'");
if ($checkReceivedIsDeleted && $checkReceivedIsDeleted->num_rows > 0) {
    $hasReceivedIsDeleted = true;
}

// Check if is_deleted column exists in cnc_entries
$hasCncIsDeleted = false;
$checkCncIsDeleted = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'is_deleted'");
if ($checkCncIsDeleted && $checkCncIsDeleted->num_rows > 0) {
    $hasCncIsDeleted = true;
}

// Build query with conditional is_deleted checks
$receivedDeletedCondition = $hasReceivedIsDeleted ? "AND (r.is_deleted = 0 OR r.is_deleted IS NULL)" : "";
$cncDeletedCondition = $hasCncIsDeleted ? "AND (c.is_deleted = 0 OR c.is_deleted IS NULL)" : "";

// Fetch all references from roll_received that haven't been submitted to cnc_entries
// Reference numbers in cnc_entries are stored as comma-separated values (e.g., "REF1,REF2,REF3")
$allRefs = [];
$refsWithDates = []; // Store references with their creation dates for sorting

// Improved query to exclude already submitted references
// This checks if the reference appears anywhere in the comma-separated reference_number field
// Using FIND_IN_SET for better comma-separated value matching
$refQuery = "SELECT DISTINCT r.reference_number, MAX(r.created_at) as created_at
             FROM roll_received r 
             WHERE r.reference_number IS NOT NULL 
             AND r.reference_number != ''
             AND TRIM(r.reference_number) != ''
             {$receivedDeletedCondition}
             AND NOT EXISTS (
                 SELECT 1 FROM cnc_entries c 
                 WHERE (
                     -- Use FIND_IN_SET for comma-separated values (most reliable)
                     FIND_IN_SET(r.reference_number, c.reference_number) > 0
                     -- Also check exact match (in case it's a single reference, not comma-separated)
                     OR c.reference_number = r.reference_number
                     -- Also check with trimmed values
                     OR FIND_IN_SET(TRIM(r.reference_number), TRIM(c.reference_number)) > 0
                     OR TRIM(c.reference_number) = TRIM(r.reference_number)
                 )
                 {$cncDeletedCondition}
             )
             GROUP BY r.reference_number
             ORDER BY created_at DESC
             LIMIT 500";
$refResult = $conn->query($refQuery);
if ($refResult) {
    while ($row = $refResult->fetch_assoc()) {
        $allRefs[] = $row['reference_number'];
        $refsWithDates[$row['reference_number']] = $row['created_at'];
    }
}


// Group references into bundles and individual refs
$bundles = [];
$individualRefs = [];
$bundleMap = [];

// Group references by base pattern (e.g., "REF-1", "REF-2" -> base "REF")
foreach ($allRefs as $ref) {
    if (preg_match('/^(.+)-(\d+)$/', $ref, $matches)) {
        $baseRef = $matches[1];
        $rollNum = (int)$matches[2];
        
        if (!isset($bundleMap[$baseRef])) {
            $bundleMap[$baseRef] = [];
        }
        $bundleMap[$baseRef][] = ['ref' => $ref, 'num' => $rollNum];
    } else {
        // Single reference (not part of a bundle pattern)
        $individualRefs[] = [
            'reference' => $ref,
            'roll_count' => 1,
            'is_bundle' => false,
            'display' => $ref,
            'created_at' => isset($refsWithDates[$ref]) ? $refsWithDates[$ref] : null
        ];
    }
}

// Process bundles - if 2+ references with same base, it's a bundle
foreach ($bundleMap as $baseRef => $refs) {
    if (count($refs) >= 2) {
        // Sort by roll number
        usort($refs, function($a, $b) {
            return $a['num'] - $b['num'];
        });
        
        $rollCount = count($refs);
        $firstNum = $refs[0]['num'];
        $lastNum = $refs[count($refs) - 1]['num'];
        
        // Create bundle display: "REF-1-4 (Bundle)" or "REF-1 (Bundle)"
        $bundleDisplay = $firstNum === $lastNum 
            ? $baseRef . '-' . $firstNum . ' (Bundle)'
            : $baseRef . '-' . $firstNum . '-' . $lastNum . ' (Bundle)';
        
        // Store all individual references in the bundle
        $bundleRefs = array_column($refs, 'ref');
        
        // Get the earliest created_at from bundle refs for sorting
        $bundleCreatedAt = null;
        foreach ($bundleRefs as $bundleRef) {
            if (isset($refsWithDates[$bundleRef])) {
                if ($bundleCreatedAt === null || $refsWithDates[$bundleRef] < $bundleCreatedAt) {
                    $bundleCreatedAt = $refsWithDates[$bundleRef];
                }
            }
        }
        
        $bundles[] = [
            'reference' => $baseRef . '-' . $firstNum . ($firstNum !== $lastNum ? '-' . $lastNum : ''),
            'roll_count' => $rollCount,
            'is_bundle' => true,
            'display' => $bundleDisplay,
            'base_reference' => $baseRef,
            'bundle_refs' => $bundleRefs,
            'created_at' => $bundleCreatedAt
        ];
    } else {
        // Single reference that matched bundle pattern but isn't actually a bundle
        $singleRef = $refs[0]['ref'];
        $individualRefs[] = [
            'reference' => $singleRef,
            'roll_count' => 1,
            'is_bundle' => false,
            'display' => $singleRef,
            'created_at' => isset($refsWithDates[$singleRef]) ? $refsWithDates[$singleRef] : null
        ];
    }
}

// Combine bundles and individual refs
// IMPORTANT: Put individual refs FIRST so they appear before bundles in the list
$references = array_merge($individualRefs, $bundles);

// Additional filtering: Exclude bundles if any of their references have been submitted
// Note: Individual references are already filtered by the SQL query above, so we only check bundles
$filteredReferences = [];
foreach ($references as $ref) {
    $shouldExclude = false;
    
    if ($ref['is_bundle'] && isset($ref['bundle_refs'])) {
        // For bundles, check multiple things:
        // 1. Check if the bundle reference format itself exists (e.g., "4.0L226JAN05-R01-H0.1-1-4")
        // 2. Check if any individual reference in the bundle has been submitted
        // 3. Check if the bundle reference appears in a comma-separated list
        
        $bundleRefFormat = $ref['reference']; // e.g., "4.0L226JAN05-R01-H0.1-1-4"
        
        // First check: Is the bundle reference format itself submitted?
        // e.g., "4.0L226JAN05-R01-H0.1-1-4" might be stored in cnc_entries
        $checkBundleQuery = "SELECT COUNT(*) as count FROM cnc_entries c 
                            WHERE (
                                -- Use FIND_IN_SET for comma-separated values (most reliable)
                                FIND_IN_SET(?, c.reference_number) > 0
                                -- Also check exact match
                                OR c.reference_number = ?
                                -- Also check with trimmed values
                                OR FIND_IN_SET(TRIM(?), TRIM(c.reference_number)) > 0
                                OR TRIM(c.reference_number) = TRIM(?)
                            )
                            {$cncDeletedCondition}";
        $checkBundleStmt = $conn->prepare($checkBundleQuery);
        if ($checkBundleStmt) {
            $checkBundleStmt->bind_param('ssss', $bundleRefFormat, $bundleRefFormat, $bundleRefFormat, $bundleRefFormat);
            $checkBundleStmt->execute();
            $checkBundleResult = $checkBundleStmt->get_result();
            if ($checkBundleResult && ($checkBundleRow = $checkBundleResult->fetch_assoc())) {
                if ($checkBundleRow['count'] > 0) {
                    $shouldExclude = true;
                    $checkBundleStmt->close();
                    // Bundle format found in cnc_entries, skip individual ref checks
                    continue;
                }
            }
            $checkBundleStmt->close();
        }
        
        // Second check: Check if any individual reference in the bundle has been submitted
        // Note: Individual refs are already filtered by main query, but we double-check here for safety
        foreach ($ref['bundle_refs'] as $bundleRef) {
            $checkQuery = "SELECT COUNT(*) as count FROM cnc_entries c 
                          WHERE (
                              -- Use FIND_IN_SET for comma-separated values (most reliable)
                              FIND_IN_SET(?, c.reference_number) > 0
                              -- Also check exact match
                              OR c.reference_number = ?
                              -- Also check with trimmed values
                              OR FIND_IN_SET(TRIM(?), TRIM(c.reference_number)) > 0
                              OR TRIM(c.reference_number) = TRIM(?)
                          )
                          {$cncDeletedCondition}";
            $checkStmt = $conn->prepare($checkQuery);
            if ($checkStmt) {
                $checkStmt->bind_param('ssss', $bundleRef, $bundleRef, $bundleRef, $bundleRef);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                if ($checkResult && ($checkRow = $checkResult->fetch_assoc())) {
                    if ($checkRow['count'] > 0) {
                        $shouldExclude = true;
                        $checkStmt->close();
                        break;
                    }
                }
                $checkStmt->close();
            }
        }
    } else {
        // For individual (non-bundle) references, double-check they haven't been submitted
        // This is a safety check even though the main query should have filtered them
        $individualRef = $ref['reference'];
        $checkIndividualQuery = "SELECT COUNT(*) as count FROM cnc_entries c 
                                  WHERE (
                                      -- Use FIND_IN_SET for comma-separated values (most reliable)
                                      FIND_IN_SET(?, c.reference_number) > 0
                                      -- Also check exact match
                                      OR c.reference_number = ?
                                      -- Also check with trimmed values
                                      OR FIND_IN_SET(TRIM(?), TRIM(c.reference_number)) > 0
                                      OR TRIM(c.reference_number) = TRIM(?)
                                  )
                                  {$cncDeletedCondition}";
        $checkIndividualStmt = $conn->prepare($checkIndividualQuery);
        if ($checkIndividualStmt) {
            $checkIndividualStmt->bind_param('ssss', $individualRef, $individualRef, $individualRef, $individualRef);
            $checkIndividualStmt->execute();
            $checkIndividualResult = $checkIndividualStmt->get_result();
            if ($checkIndividualResult && ($checkIndividualRow = $checkIndividualResult->fetch_assoc())) {
                if ($checkIndividualRow['count'] > 0) {
                    $shouldExclude = true;
                }
            }
            $checkIndividualStmt->close();
        }
    }
    
    // Only include if not excluded (both bundles and individual refs are checked above)
    if (!$shouldExclude) {
        $filteredReferences[] = $ref;
    }
}

$references = $filteredReferences;

// Sort references by created_at DESC (newest first) to maintain database order
// This ensures all references, including the last ones, are properly ordered
usort($references, function($a, $b) {
    $dateA = $a['created_at'] ?? null;
    $dateB = $b['created_at'] ?? null;
    
    // If both have dates, sort by date DESC (newest first)
    if ($dateA && $dateB) {
        return strtotime($dateB) - strtotime($dateA);
    }
    // If only one has date, prioritize it
    if ($dateA && !$dateB) return -1;
    if (!$dateA && $dateB) return 1;
    // If neither has date, maintain original order
    return 0;
});

// Ensure no output before JSON
ob_clean();

echo json_encode([
    'success' => true, 
    'references' => $references
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// End output buffering
ob_end_flush();
exit;
?>
