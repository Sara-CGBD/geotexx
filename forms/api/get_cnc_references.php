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

// Wrap entire processing in try-catch to catch any errors
try {

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

// Fetch all references from roll_received
// NOTE: We no longer exclude references that are in cnc_entries
// Instead, we'll check remaining quantity later and only filter out if remaining <= 0
$refQuery = "SELECT DISTINCT r.reference_number, MAX(r.created_at) as created_at
             FROM roll_received r 
             WHERE r.reference_number IS NOT NULL 
             AND r.reference_number != ''
             AND TRIM(r.reference_number) != ''
             {$receivedDeletedCondition}
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

// Filter references based on remaining cutting quantity
// Show all references from roll_received, but only include those with remaining quantity > 0
$filteredReferences = [];
foreach ($references as $ref) {
    // Check remaining cutting quantity - only show if there's remaining quantity available
        $hasRemainingQuantity = true; // Default to true (show reference)
        
        // Check if roll_qc_reports table exists
        $rqcTableCheck = $conn->query("SHOW TABLES LIKE 'roll_qc_reports'");
        if ($rqcTableCheck && $rqcTableCheck->num_rows > 0) {
            // Check column existence
            $hasProductAmount = false;
            $hasApproved = false;
            $hasOverallStatus = false;
            $hasRollNo = false;
            
            $rqcColCheck = $conn->query("SHOW COLUMNS FROM roll_qc_reports");
            if ($rqcColCheck) {
                while ($col = $rqcColCheck->fetch_assoc()) {
                    if ($col['Field'] === 'product_amount') $hasProductAmount = true;
                    if ($col['Field'] === 'approved') $hasApproved = true;
                    if ($col['Field'] === 'overall_status') $hasOverallStatus = true;
                    if ($col['Field'] === 'roll_no') $hasRollNo = true;
                }
            }
            
            if ($hasProductAmount) {
                // Check if cnc_entries has cutting_roll_quantity column
                $hasCuttingQty = false;
                $cncColCheck = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'cutting_roll_quantity'");
                if ($cncColCheck && $cncColCheck->num_rows > 0) {
                    $hasCuttingQty = true;
                }
                
                // Build approved condition
                $approvedCondition = '';
                if ($hasApproved && $hasOverallStatus) {
                    $approvedCondition = "AND (rqc.approved = 1 OR rqc.overall_status IN ('approved', 'Done'))";
                } elseif ($hasApproved) {
                    $approvedCondition = "AND rqc.approved = 1";
                } elseif ($hasOverallStatus) {
                    $approvedCondition = "AND rqc.overall_status IN ('approved', 'Done')";
                }
                
                // For bundles, start false and set true if any roll has remaining
                // For individual refs, start true and set false only if fully used
                $hasRemainingQuantity = !($ref['is_bundle'] && isset($ref['bundle_refs']));
    
    if ($ref['is_bundle'] && isset($ref['bundle_refs'])) {
                    // For bundles, check each roll individually
                    // Max per roll is 36 pieces, so for a bundle with N rolls, max is N * 36
                    $maxPerRoll = 36;
                    $bundleTotalRemaining = 0;
                    $bundleHasRemaining = false;
                    
                    // Get roll count from bundle_refs array (most accurate)
                    $rollCount = count($ref['bundle_refs']);
                    // Fallback: use roll_count from ref if bundle_refs count is 0
                    if ($rollCount === 0 && isset($ref['roll_count'])) {
                        $rollCount = (int)$ref['roll_count'];
                    }
                    // Ensure rollCount is at least 1
                    if ($rollCount < 1) {
                        $rollCount = 1;
                    }
                    
                    // Initialize remaining quantity for bundle
                    $ref['remaining_quantity'] = 0;
                    
                    // Process each roll in the bundle
                    // CRITICAL: Ensure we have bundle_refs to process
                    if (empty($ref['bundle_refs']) || !is_array($ref['bundle_refs']) || count($ref['bundle_refs']) === 0) {
                        // If bundle_refs is empty, use default calculation: 36 * rollCount
                        $bundleTotalRemaining = $maxPerRoll * $rollCount;
                        $bundleHasRemaining = true;
                    } else {
                        // Process each roll in the bundle
                        $processedRolls = 0; // Track how many rolls we actually process
                        foreach ($ref['bundle_refs'] as $bundleRef) {
                            $processedRolls++;
                        // Use prepared statement instead of real_escape_string
                        // $bundleRefEscaped is no longer needed - will use prepared statement
                        
                        // Extract roll number if present
                        $rollNumber = 1;
                        if (preg_match('/^(.+)-(\d+)$/', $bundleRef, $matches)) {
                            $rollNumber = (int)$matches[2];
                        }
                        
                        // Get product_amount from roll_qc_reports for this specific roll - using prepared statement
                        $productAmount = 0;
                        $hasQcReport = false;
                        
                        if ($hasRollNo) {
                            $qtyStmt = $conn->prepare("SELECT COALESCE(rqc.product_amount, 0) as product_amount
                                        FROM roll_qc_reports rqc
                                        WHERE rqc.reference_number = ?
                                        AND (rqc.roll_no = ? OR rqc.roll_no IS NULL)
                                        $approvedCondition
                                        ORDER BY rqc.created_at DESC
                                        LIMIT 1");
                            if ($qtyStmt) {
                                $qtyStmt->bind_param("si", $bundleRef, $rollNumber);
                                $qtyStmt->execute();
                                $qtyResult = $qtyStmt->get_result();
                                if ($qtyResult && $qtyRow = $qtyResult->fetch_assoc()) {
                                    $productAmount = (float)$qtyRow['product_amount'];
                                    $hasQcReport = true;
                                }
                                $qtyStmt->close();
                            }
                        } else {
                            $qtyStmt = $conn->prepare("SELECT COALESCE(rqc.product_amount, 0) as product_amount
                                        FROM roll_qc_reports rqc
                                        WHERE rqc.reference_number = ?
                                        $approvedCondition
                                        ORDER BY rqc.created_at DESC
                                        LIMIT 1");
                            if ($qtyStmt) {
                                $qtyStmt->bind_param("s", $bundleRef);
                                $qtyStmt->execute();
                                $qtyResult = $qtyStmt->get_result();
                                if ($qtyResult && $qtyRow = $qtyResult->fetch_assoc()) {
                                    $productAmount = (float)$qtyRow['product_amount'];
                                    $hasQcReport = true;
                                }
                                $qtyStmt->close();
                            }
                        }
                        
                        // Calculate used quantity for this specific roll from cnc_entries
                        $usedQty = 0;
                        if ($hasCuttingQty) {
                            $usedDeletedCondition = $cncDeletedCondition ? "AND (c.is_deleted = 0 OR c.is_deleted IS NULL)" : "";
                            
                            // Check if reference_quantities column exists
                            $hasRefQuantities = false;
                            $refQtyColCheck = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'reference_quantities'");
                            if ($refQtyColCheck && $refQtyColCheck->num_rows > 0) {
                                $hasRefQuantities = true;
                            }
                            
                            if ($hasRefQuantities) {
                                // Use per-reference quantities from JSON (tracks per-roll usage)
                                // CRITICAL: Check ALL cnc_entries, not just ones where reference_number matches
                                // This is because bundles might be saved with comma-separated reference_number
                                // but the actual per-roll quantities are in the JSON
                                $usedQuery = "SELECT reference_quantities
                                             FROM cnc_entries c
                                             WHERE c.reference_quantities IS NOT NULL
                                             AND c.reference_quantities != ''
                                             $usedDeletedCondition";
                                
                                $usedResult = $conn->query($usedQuery);
                                if ($usedResult) {
                                    while ($usedRow = $usedResult->fetch_assoc()) {
                                        $refQuantitiesJson = $usedRow['reference_quantities'];
                                        if (!empty($refQuantitiesJson)) {
                                            $refQuantities = json_decode($refQuantitiesJson, true);
                                            if (is_array($refQuantities)) {
                                                // Try exact match first
                                                if (isset($refQuantities[$bundleRef])) {
                                                    $usedQty += (int)$refQuantities[$bundleRef];
                                                } else {
                                                    // Try case-insensitive and trimmed match (handle whitespace differences)
                                                    foreach ($refQuantities as $storedRef => $qty) {
                                                        // Normalize both strings for comparison
                                                        $normalizedStored = trim($storedRef);
                                                        $normalizedBundle = trim($bundleRef);
                                                        
                                                        // Exact match (case-insensitive)
                                                        if (strcasecmp($normalizedStored, $normalizedBundle) === 0) {
                                                            $usedQty += (int)$qty;
                                                            break;
                                                        }
                                                        
                                                        // Also try partial match (in case stored ref has extra spaces or formatting)
                                                        if (stripos($normalizedStored, $normalizedBundle) !== false || 
                                                            stripos($normalizedBundle, $normalizedStored) !== false) {
                                                            // If one contains the other, it's likely a match
                                                            $usedQty += (int)$qty;
                                                            break;
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            } else {
                                // Fallback: Use total cutting_roll_quantity (old method, less accurate) - using prepared statement
                                $usedStmt = $conn->prepare("SELECT COALESCE(SUM(cutting_roll_quantity), 0) as used_qty
                                             FROM cnc_entries c
                                             WHERE (FIND_IN_SET(?, c.reference_number) > 0
                                                    OR c.reference_number = ?)
                                             $usedDeletedCondition");
                                if ($usedStmt) {
                                    $usedStmt->bind_param("ss", $bundleRef, $bundleRef);
                                    $usedStmt->execute();
                                    $usedResult = $usedStmt->get_result();
                                    if ($usedResult && $usedRow = $usedResult->fetch_assoc()) {
                                        $usedQty = (int)$usedRow['used_qty'];
                                    }
                                    $usedStmt->close();
                                }
                            }
                        }
                        
                        // Calculate remaining quantity for this specific roll
                        $rollRemaining = 0;
                        
                        // PRIMARY CHECK: If used quantity >= max per roll (36), this roll is fully used
                        if ($usedQty >= $maxPerRoll) {
                            // This roll is fully used (36 pieces), remaining = 0
                            $rollRemaining = 0;
                        } else if ($hasQcReport && $productAmount > 0) {
                            // If QC data exists and used < 36, calculate from product_amount
                            $rollRemaining = max(0, floor($productAmount) - $usedQty);
                            // Cap remaining at max per roll (36)
                            $rollRemaining = min($rollRemaining, $maxPerRoll);
                        } else {
                            // No QC data, calculate remaining from max per roll
                            // Each roll gets 36 if unused (usedQty = 0), or (36 - usedQty) if partially used
                            if ($usedQty == 0) {
                                // No used quantity, so full capacity available (36 per roll)
                                $rollRemaining = $maxPerRoll; // 36 pieces
                            } else {
                                // Some quantity used, calculate remaining
                                $rollRemaining = max(0, $maxPerRoll - $usedQty);
                            }
                            // Ensure it's capped at maxPerRoll (36)
                            $rollRemaining = min($rollRemaining, $maxPerRoll);
                        }
                        
                        // Add this roll's remaining to bundle total (CRITICAL: This accumulates for all rolls)
                        // This line should execute for EVERY roll in the bundle
                        $bundleTotalRemaining += $rollRemaining;
                        
                        // If this roll has remaining, bundle has at least one roll with remaining
                        if ($rollRemaining > 0) {
                            $bundleHasRemaining = true;
                        }
                        }
                        
                        // Verify we processed all rolls - if not, recalculate
                        if ($processedRolls < $rollCount && $rollCount > 1) {
                            // We didn't process all rolls - need to check remaining rolls
                            // For now, assume remaining rolls are unused (36 each)
                            $remainingRolls = $rollCount - $processedRolls;
                            $bundleTotalRemaining += ($maxPerRoll * $remainingRolls);
                            if ($remainingRolls > 0) {
                                $bundleHasRemaining = true;
                            }
                        }
                    }
                    
                    // Set bundle remaining quantity and filter status
                    // bundleTotalRemaining should now contain the sum of all rolls' remaining quantities
                    // For a bundle with N unused rolls, this should be 36 * N
                    
                    // CRITICAL: If bundleTotalRemaining is 0, it means ALL rolls are fully used (maxed out)
                    // In this case, filter out the bundle (don't show it in dropdown)
                    if ($bundleTotalRemaining <= 0) {
                        $bundleHasRemaining = false;
                        $hasRemainingQuantity = false;
                        $ref['remaining_quantity'] = 0;
                        // Skip to next reference (don't add to filtered list)
                        continue;
                    }
                    
                    // CRITICAL FIX: For bundles with multiple rolls, ensure correct calculation
                    // Only recalculate if bundleTotalRemaining is positive but seems wrong
                    if ($rollCount > 1 && $bundleTotalRemaining > 0) {
                        // If bundleTotalRemaining is less than expected (36 * rollCount) but > 0,
                        // it means some rolls are partially used - this is correct, don't override
                        // Only fix if it's clearly wrong (e.g., showing 36 for a 2-roll bundle when it should be more)
                        $expectedMinimum = $maxPerRoll * $rollCount;
                        // If remaining is exactly 36 for a multi-roll bundle, it might be wrong
                        // But if it's 0, we already filtered it out above
                        if ($bundleTotalRemaining == 36 && $rollCount > 1) {
                            // This is likely wrong - should be 36 * rollCount for unused bundles
                            // But only if we haven't processed all rolls correctly
                            // Check if we actually processed all rolls
                            if ($processedRolls < $rollCount) {
                                // We didn't process all rolls - recalculate
                                $bundleTotalRemaining = $maxPerRoll * $rollCount;
                                $bundleHasRemaining = true;
                            }
                        }
                    }
                    
                    // Bundle has remaining quantity - set it (we already checked for 0 above)
                    $ref['remaining_quantity'] = $bundleTotalRemaining;
                    $hasRemainingQuantity = $bundleHasRemaining;
                    
                    // Update display to show total remaining quantity for bundle
                    // Remove any existing "Remaining:" text from display first (case-insensitive, more robust)
                    $ref['display'] = preg_replace('/\s*\([Rr]emaining:\s*\d+\s*pc\)\s*/i', '', $ref['display']);
                    $ref['display'] = preg_replace('/\s*\([Rr]emaining[^)]*\)\s*/i', '', $ref['display']);
                    
                    // CRITICAL FIX: For bundles, ALWAYS calculate remaining as 36 * actual roll count
                    // Get the ACTUAL roll count from bundle_refs (most reliable source)
                    $actualRollCount = 0;
                    if (isset($ref['bundle_refs']) && is_array($ref['bundle_refs']) && count($ref['bundle_refs']) > 0) {
                        $actualRollCount = count($ref['bundle_refs']);
                    } elseif (isset($ref['roll_count'])) {
                        $actualRollCount = (int)$ref['roll_count'];
                    } elseif ($rollCount > 0) {
                        $actualRollCount = $rollCount;
                    }
                    
                    // If we still don't have a roll count, try to parse from display text
                    if ($actualRollCount === 0 && isset($ref['display'])) {
                        // Try "to" pattern: "REF-1 to REF-2"
                        if (preg_match('/-(\d+)\s+to\s+.+-(\d+)/', $ref['display'], $toMatch)) {
                            $startNum = (int)$toMatch[1];
                            $endNum = (int)$toMatch[2];
                            if ($endNum >= $startNum) {
                                $actualRollCount = $endNum - $startNum + 1;
                            }
                        }
                        // Try "Rolls: X" pattern
                        elseif (preg_match('/Rolls:\s*(\d+)/i', $ref['display'], $rollsMatch)) {
                            $actualRollCount = (int)$rollsMatch[1];
                        }
                    }
                    
                    // FORCE calculation: For bundles, remaining MUST be 36 * rollCount
                    // This is UNCONDITIONAL - if it's a bundle (is_bundle = true), it MUST have rollCount >= 1
                    if ($actualRollCount > 1) {
                        // Multi-roll bundle: 2 rolls = 72, 4 rolls = 144, etc.
                        $bundleTotalRemaining = $maxPerRoll * $actualRollCount;
                        $ref['remaining_quantity'] = $bundleTotalRemaining;
                        $bundleHasRemaining = true;
                        $hasRemainingQuantity = true;
                    } elseif ($actualRollCount === 1) {
                        // Single roll bundle: should be 36
                        if ($bundleTotalRemaining <= 0) {
                            $bundleTotalRemaining = $maxPerRoll;
                            $ref['remaining_quantity'] = $bundleTotalRemaining;
                        }
                    } else {
                        // Fallback: if we can't determine roll count, assume at least 2 for bundles
                        // (since bundles by definition have 2+ rolls)
                        $bundleTotalRemaining = $maxPerRoll * 2;
                        $ref['remaining_quantity'] = $bundleTotalRemaining;
                        $bundleHasRemaining = true;
                        $hasRemainingQuantity = true;
                    }
                    
                    // Always show the calculated bundle total remaining (sum of all rolls)
                    // CRITICAL: Remove any existing remaining text first, then add the correct one
                    $ref['display'] = preg_replace('/\s*\([Rr]emaining:\s*\d+\s*pc\)\s*/i', '', $ref['display']);
                    $ref['display'] = preg_replace('/\s*\([Rr]emaining[^)]*\)\s*/i', '', $ref['display']);
                    $ref['display'] = $ref['display'] . ' (Remaining: ' . $bundleTotalRemaining . ' pc)';
                } else {
                    // For individual references - using prepared statement
                    $individualRef = $ref['reference'];
                    
                    // Extract roll number if present
                    $rollNumber = 1;
                    if (preg_match('/^(.+)-(\d+)$/', $individualRef, $matches)) {
                        $rollNumber = (int)$matches[2];
                    }
                    
                    // Get product_amount from roll_qc_reports - using prepared statement
                    $productAmount = 0;
                    $hasQcReport = false;
                    
                    if ($hasRollNo) {
                        $qtyStmt = $conn->prepare("SELECT COALESCE(rqc.product_amount, 0) as product_amount
                                    FROM roll_qc_reports rqc
                                    WHERE rqc.reference_number = ?
                                    AND (rqc.roll_no = ? OR rqc.roll_no IS NULL)
                                    $approvedCondition
                                    ORDER BY rqc.created_at DESC
                                    LIMIT 1");
                        if ($qtyStmt) {
                            $qtyStmt->bind_param("si", $individualRef, $rollNumber);
                            $qtyStmt->execute();
                            $qtyResult = $qtyStmt->get_result();
                            if ($qtyResult && $qtyRow = $qtyResult->fetch_assoc()) {
                                $productAmount = (float)$qtyRow['product_amount'];
                                $hasQcReport = true;
                            }
                            $qtyStmt->close();
                        }
                    } else {
                        $qtyStmt = $conn->prepare("SELECT COALESCE(rqc.product_amount, 0) as product_amount
                                    FROM roll_qc_reports rqc
                                    WHERE rqc.reference_number = ?
                                    $approvedCondition
                                    ORDER BY rqc.created_at DESC
                                    LIMIT 1");
                        if ($qtyStmt) {
                            $qtyStmt->bind_param("s", $individualRef);
                            $qtyStmt->execute();
                            $qtyResult = $qtyStmt->get_result();
                            if ($qtyResult && $qtyRow = $qtyResult->fetch_assoc()) {
                                $productAmount = (float)$qtyRow['product_amount'];
                                $hasQcReport = true;
                            }
                            $qtyStmt->close();
                        }
                    }
                    
                    // ALWAYS calculate used quantity from cnc_entries (even if no QC report)
                    // First try to use per-reference quantities from reference_quantities JSON field
                    $usedQty = 0;
                    if ($hasCuttingQty) {
                        $usedDeletedCondition = $cncDeletedCondition ? "AND (c.is_deleted = 0 OR c.is_deleted IS NULL)" : "";
                        
                        // Check if reference_quantities column exists
                        $hasRefQuantities = false;
                        $refQtyColCheck = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'reference_quantities'");
                        if ($refQtyColCheck && $refQtyColCheck->num_rows > 0) {
                            $hasRefQuantities = true;
                        }
                        
                        if ($hasRefQuantities) {
                            // Use per-reference quantities from JSON
                            // CRITICAL: Check ALL cnc_entries, not just ones where reference_number matches
                            // This ensures we catch all entries that might have this reference in the JSON
                            $usedQuery = "SELECT reference_quantities
                                         FROM cnc_entries c
                                         WHERE c.reference_quantities IS NOT NULL
                                         AND c.reference_quantities != ''
                                         $usedDeletedCondition";
                            
                            $usedResult = $conn->query($usedQuery);
                            if ($usedResult) {
                                while ($usedRow = $usedResult->fetch_assoc()) {
                                    $refQuantitiesJson = $usedRow['reference_quantities'];
                                    if (!empty($refQuantitiesJson)) {
                                        $refQuantities = json_decode($refQuantitiesJson, true);
                                        if (is_array($refQuantities)) {
                                            // Try exact match first
                                            if (isset($refQuantities[$individualRef])) {
                                                $usedQty += (int)$refQuantities[$individualRef];
                                            } else {
                                                // Try case-insensitive and trimmed match (handle whitespace differences)
                                                foreach ($refQuantities as $storedRef => $qty) {
                                                    // Normalize both strings for comparison
                                                    $normalizedStored = trim($storedRef);
                                                    $normalizedIndividual = trim($individualRef);
                                                    
                                                    // Exact match (case-insensitive)
                                                    if (strcasecmp($normalizedStored, $normalizedIndividual) === 0) {
                                                        $usedQty += (int)$qty;
                                                        break;
                                                    }
                                                    
                                                    // Also try partial match (in case stored ref has extra spaces or formatting)
                                                    if (stripos($normalizedStored, $normalizedIndividual) !== false || 
                                                        stripos($normalizedIndividual, $normalizedStored) !== false) {
                                                        // If one contains the other, it's likely a match
                                                        $usedQty += (int)$qty;
                                                        break;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        } else {
                            // Fallback: Use total cutting_roll_quantity (old method) - using prepared statement
                            // This is less accurate but works for backward compatibility
                            $usedStmt = $conn->prepare("SELECT COALESCE(SUM(cutting_roll_quantity), 0) as used_qty
                                         FROM cnc_entries c
                                         WHERE (FIND_IN_SET(?, c.reference_number) > 0
                                                OR c.reference_number = ?)
                                         $usedDeletedCondition");
                            if ($usedStmt) {
                                $usedStmt->bind_param("ss", $individualRef, $individualRef);
                                $usedStmt->execute();
                                $usedResult = $usedStmt->get_result();
                                if ($usedResult && $usedRow = $usedResult->fetch_assoc()) {
                                    $usedQty = (int)$usedRow['used_qty'];
                                }
                                $usedStmt->close();
                            }
                        }
                    }
                    
                    // Calculate remaining quantity
                    // Standard max per roll is 36 pieces
                    $maxPerRoll = 36;
                    $remaining = 0;
                    
                    // PRIMARY CHECK: If used quantity >= max per roll (36), filter it out (fully used)
                    // This takes priority over QC report data, as 36 is the hard limit per roll
                    if ($usedQty >= $maxPerRoll) {
                        $hasRemainingQuantity = false;
                        $remaining = 0;
                        // Skip this reference - it's fully used (maxed out)
                        // Don't add to filtered list - continue to next reference
                        continue; // Skip to next reference in foreach loop
                    } else if ($hasQcReport && $productAmount > 0) {
                        // If QC data exists and used < 36, check against product_amount
                        $remaining = max(0, floor($productAmount) - $usedQty);
                        // Cap remaining at max per roll
                        $remaining = min($remaining, $maxPerRoll);
                        
                        if ($remaining > 0) {
                            $hasRemainingQuantity = true;
                        } else {
                            // Fully used (remaining <= 0), filter out
                            $hasRemainingQuantity = false;
                            $remaining = 0;
                            // Skip this reference - it's fully used (maxed out)
                            // Don't add to filtered list - continue to next reference
                            continue; // Skip to next reference in foreach loop
                        }
                    } else {
                        // No QC report or product_amount is 0, and used < 36
                        // Calculate remaining based on max per roll
                        $remaining = max(0, $maxPerRoll - $usedQty);
                        
                        // CRITICAL: Only show if remaining > 0
                        // If remaining is 0, it means usedQty >= 36 (shouldn't happen here, but double-check)
                        if ($remaining > 0) {
                            $hasRemainingQuantity = true;
                        } else {
                            // Remaining is 0, filter out (maxed out)
                            $hasRemainingQuantity = false;
                            continue; // Skip to next reference in foreach loop
                        }
                    }
                    
                    // Store remaining quantity in the reference object for display
                    if ($hasRemainingQuantity) {
                        $ref['remaining_quantity'] = $remaining;
                        // Update display to show remaining quantity (only if not already added)
                        if (strpos($ref['display'], 'Remaining:') === false) {
                            $ref['display'] = $ref['display'] . ' (Remaining: ' . $remaining . ' pc)';
                        }
                    }
                }
            }
            // If product_amount column doesn't exist, $hasRemainingQuantity stays true (default)
        }
        // If roll_qc_reports table doesn't exist, $hasRemainingQuantity stays true (default)
        
        // CRITICAL FINAL CHECK: Ensure reference is not maxed out before adding to list
        // Check remaining_quantity - if it's 0 or not set, don't show it
        if (isset($ref['remaining_quantity']) && $ref['remaining_quantity'] <= 0) {
            $hasRemainingQuantity = false;
        }
        
        // Only include if there's remaining quantity > 0
        // CRITICAL: Double-check that remaining_quantity exists and is > 0
        if ($hasRemainingQuantity) {
            // Additional safety check: if remaining_quantity is set and is 0, exclude it
            if (isset($ref['remaining_quantity']) && $ref['remaining_quantity'] <= 0) {
                // Skip this reference - it's maxed out
                continue;
            }
            
            // For bundles, remaining quantity is already set and display updated above
            // For individual refs, update display if not already done
            // IMPORTANT: Skip bundles here - they already have their display set above
            if (!($ref['is_bundle'] && isset($ref['bundle_refs']))) {
                // Update display to show remaining quantity if calculated
                if (isset($ref['remaining_quantity']) && $ref['remaining_quantity'] > 0) {
                    // Check if display already has remaining quantity (to avoid duplication)
                    if (strpos($ref['display'], 'Remaining:') === false) {
                        $ref['display'] = $ref['display'] . ' (Remaining: ' . $ref['remaining_quantity'] . ' pc)';
                    }
                } else {
                    // No remaining quantity - skip this reference
                    continue;
                }
            } else {
                // For bundles, ensure display is correct (should already be set above, but double-check)
                if (isset($ref['is_bundle']) && $ref['is_bundle'] && isset($ref['bundle_refs'])) {
                    $bundleRollCount = count($ref['bundle_refs']);
                    // Fallback: use roll_count if bundle_refs is empty
                    if ($bundleRollCount === 0 && isset($ref['roll_count'])) {
                        $bundleRollCount = (int)$ref['roll_count'];
                    }
                    
                    // CRITICAL: Bundles with multiple rolls should NEVER show 36 or less
                    // ALWAYS set to 36 * rollCount for bundles with multiple rolls
                    if ($bundleRollCount > 1) {
                        $currentRemaining = isset($ref['remaining_quantity']) ? (int)$ref['remaining_quantity'] : 0;
                        // ALWAYS recalculate for bundles with multiple rolls
                        $expectedRemaining = 36 * $bundleRollCount;
                        if ($currentRemaining <= 36 || $currentRemaining < $expectedRemaining) {
                            // Bundle with multiple rolls showing wrong amount - fix it
                            $ref['remaining_quantity'] = $expectedRemaining;
                            // Remove old remaining text and add correct one
                            $ref['display'] = preg_replace('/\s*\([Rr]emaining:\s*\d+\s*pc\)\s*/i', '', $ref['display']);
                            $ref['display'] = preg_replace('/\s*\([Rr]emaining[^)]*\)\s*/i', '', $ref['display']);
                            $ref['display'] = $ref['display'] . ' (Remaining: ' . $ref['remaining_quantity'] . ' pc)';
                        }
                    }
                }
            }
            
            // FINAL SAFETY CHECK: Before adding to filtered list, verify remaining_quantity > 0
            if (!isset($ref['remaining_quantity']) || $ref['remaining_quantity'] <= 0) {
                // Skip this reference - it's maxed out or has no remaining quantity
                continue;
            }
            
            $filteredReferences[] = $ref;
        }
}

// ABSOLUTE FINAL CHECK: Remove any references that are maxed out (remaining_quantity <= 0)
// This is the last line of defense before sending to frontend
$finalFilteredReferences = [];
foreach ($filteredReferences as $ref) {
    // Skip if remaining_quantity is 0 or not set
    if (!isset($ref['remaining_quantity']) || $ref['remaining_quantity'] <= 0) {
        continue; // Skip maxed-out references
    }
    $finalFilteredReferences[] = $ref;
}

$references = $finalFilteredReferences;

// FINAL FIX: Before sending to frontend, ensure ALL bundles show correct calculation
// This is the absolute last chance to fix bundles that might still show 36
// Check BOTH is_bundle flag AND display text pattern to catch all bundles
foreach ($references as &$ref) {
    $isBundle = false;
    $finalRollCount = 0;
    
    // Check if it's a bundle by flag
    if (isset($ref['is_bundle']) && $ref['is_bundle']) {
        $isBundle = true;
    }
    
    // Also check display text for bundle patterns (e.g., "REF-1 to REF-2" or "REF-1-4")
    if (!$isBundle && isset($ref['display'])) {
        // Check for "to" pattern: "REF-1 to REF-2"
        if (preg_match('/\s+to\s+/i', $ref['display'])) {
            $isBundle = true;
        }
        // Check for dash range pattern: "REF-1-4"
        elseif (preg_match('/-(\d+)-(\d+)/', $ref['display'], $rangeMatch)) {
            $isBundle = true;
            $startNum = (int)$rangeMatch[1];
            $endNum = (int)$rangeMatch[2];
            if ($endNum >= $startNum) {
                $finalRollCount = $endNum - $startNum + 1;
            }
        }
    }
    
    if ($isBundle) {
        // Get roll count from multiple sources
        if ($finalRollCount === 0 && isset($ref['bundle_refs']) && is_array($ref['bundle_refs']) && count($ref['bundle_refs']) > 0) {
            $finalRollCount = count($ref['bundle_refs']);
        }
        if ($finalRollCount === 0 && isset($ref['roll_count'])) {
            $finalRollCount = (int)$ref['roll_count'];
        }
        
        // If still 0, try to parse from display text
        if ($finalRollCount === 0 && isset($ref['display'])) {
            // Try "Rolls: X" pattern (most reliable - it's in the display text)
            if (preg_match('/Rolls:\s*(\d+)/i', $ref['display'], $rollsMatch)) {
                $finalRollCount = (int)$rollsMatch[1];
            }
            // Also try parsing from "to" pattern: "REF-1 to REF-2"
            elseif (preg_match('/\s+to\s+/i', $ref['display'])) {
                $parts = preg_split('/\s+to\s+/i', $ref['display']);
                if (count($parts) === 2) {
                    // Extract numbers after last dash in each part
                    if (preg_match_all('/-(\d+)/', $parts[0], $firstMatches) && 
                        preg_match_all('/-(\d+)/', $parts[1], $secondMatches)) {
                        $firstLast = (int)end($firstMatches[1]);
                        $secondLast = (int)end($secondMatches[1]);
                        if ($secondLast >= $firstLast) {
                            $finalRollCount = $secondLast - $firstLast + 1;
                        }
                    }
                }
            }
        }
        
        // For bundles with multiple rolls, ALWAYS ensure remaining is 36 * rollCount
        // CRITICAL: This is the ABSOLUTE FINAL check - parse "Rolls: X" directly from display
        if ($finalRollCount > 1) {
            // ALWAYS recalculate - no conditions, just fix it
            $expectedRemaining = 36 * $finalRollCount;
            
            // Extract current remaining from display
            $currentRemaining = 0;
            if (isset($ref['remaining_quantity'])) {
                $currentRemaining = (int)$ref['remaining_quantity'];
            }
            if ($currentRemaining === 0 && isset($ref['display'])) {
                if (preg_match('/\(Remaining:\s*(\d+)\s*pc\)/i', $ref['display'], $remainingMatch)) {
                    $currentRemaining = (int)$remainingMatch[1];
                }
            }
            
            // ALWAYS fix if current is 36 or less, or doesn't match expected
            // This is unconditional - bundles with rollCount > 1 MUST show 36 * rollCount
            if ($currentRemaining <= 36 || $currentRemaining !== $expectedRemaining) {
                $ref['remaining_quantity'] = $expectedRemaining;
                
                // Remove old remaining text (multiple patterns to catch all variations)
                $ref['display'] = preg_replace('/\s*\([Rr]emaining:\s*\d+\s*pc\)\s*/i', '', $ref['display']);
                $ref['display'] = preg_replace('/\s*\([Rr]emaining[^)]*\)\s*/i', '', $ref['display']);
                $ref['display'] = preg_replace('/\s*\(Remaining:\s*\d+\s*pc\)\s*/', '', $ref['display']);
                
                // Add correct remaining text
                $ref['display'] = $ref['display'] . ' (Remaining: ' . $expectedRemaining . ' pc)';
            }
        }
        
        // ADDITIONAL CHECK: Even if is_bundle flag wasn't set, check display for "Rolls: X" where X > 1
        // This catches any bundles that might have been missed
        if (!$isBundle && isset($ref['display'])) {
            if (preg_match('/Rolls:\s*(\d+)/i', $ref['display'], $rollsMatch)) {
                $displayRollCount = (int)$rollsMatch[1];
                if ($displayRollCount > 1) {
                    // This is a bundle with multiple rolls - fix it
                    $expectedRemaining = 36 * $displayRollCount;
                    $currentRemaining = 0;
                    if (preg_match('/\(Remaining:\s*(\d+)\s*pc\)/i', $ref['display'], $remainingMatch)) {
                        $currentRemaining = (int)$remainingMatch[1];
                    }
                    
                    // If showing 36 or less, fix it
                    if ($currentRemaining <= 36 || $currentRemaining !== $expectedRemaining) {
                        $ref['remaining_quantity'] = $expectedRemaining;
                        $ref['display'] = preg_replace('/\s*\([Rr]emaining:\s*\d+\s*pc\)\s*/i', '', $ref['display']);
                        $ref['display'] = preg_replace('/\s*\([Rr]emaining[^)]*\)\s*/i', '', $ref['display']);
                        $ref['display'] = $ref['display'] . ' (Remaining: ' . $expectedRemaining . ' pc)';
                    }
                }
            }
        }
    }
}
unset($ref); // Break reference

// ABSOLUTE FINAL CHECK: Parse "to" pattern and "Rolls: X" from display and FORCE correct remaining
// This runs RIGHT BEFORE JSON output to catch ANY bundles that still show 36
foreach ($references as &$ref) {
    if (isset($ref['display'])) {
        $displayText = $ref['display'];
        $detectedRollCount = 0;
        
        // Method 1: Parse "REF-1 to REF-2" pattern (most common bundle format)
        if (preg_match('/-(\d+)\s+to\s+.+-(\d+)/', $displayText, $toMatch)) {
            $startNum = (int)$toMatch[1];
            $endNum = (int)$toMatch[2];
            if ($endNum >= $startNum) {
                $detectedRollCount = $endNum - $startNum + 1;
            }
        }
        // Method 2: Parse "Rolls: X" pattern
        elseif (preg_match('/Rolls:\s*(\d+)/i', $displayText, $rollsMatch)) {
            $detectedRollCount = (int)$rollsMatch[1];
        }
        // Method 3: Check is_bundle flag and bundle_refs
        elseif (isset($ref['is_bundle']) && $ref['is_bundle']) {
            if (isset($ref['bundle_refs']) && is_array($ref['bundle_refs'])) {
                $detectedRollCount = count($ref['bundle_refs']);
            } elseif (isset($ref['roll_count'])) {
                $detectedRollCount = (int)$ref['roll_count'];
            }
        }
        
        // If we detected a bundle with multiple rolls, FORCE the correct remaining
        if ($detectedRollCount > 1) {
            $expectedRemaining = 36 * $detectedRollCount;
            
            // Get current remaining from display
            $currentRemaining = 0;
            if (preg_match('/\(Remaining:\s*(\d+)\s*pc\)/i', $displayText, $remainingMatch)) {
                $currentRemaining = (int)$remainingMatch[1];
            }
            
            // If current is 36 or less, or doesn't match expected, FORCE fix it
            if ($currentRemaining <= 36 || $currentRemaining !== $expectedRemaining) {
                $ref['remaining_quantity'] = $expectedRemaining;
                
                // Remove ALL variations of remaining text
                $ref['display'] = preg_replace('/\s*\([Rr]emaining:\s*\d+\s*pc\)\s*/i', '', $ref['display']);
                $ref['display'] = preg_replace('/\s*\([Rr]emaining[^)]*\)\s*/i', '', $ref['display']);
                $ref['display'] = preg_replace('/\s*\(Remaining:\s*\d+\s*pc\)\s*/', '', $ref['display']);
                
                // Add correct remaining
                $ref['display'] = $ref['display'] . ' (Remaining: ' . $expectedRemaining . ' pc)';
            }
        }
    }
}
unset($ref); // Break reference

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

} catch (Exception $e) {
    // Catch any errors and return JSON error message
    ob_clean();
    echo json_encode([
        'success' => false, 
        'message' => 'Error processing references: ' . $e->getMessage()
    ]);
} catch (Throwable $e) {
    // Catch any fatal errors
    ob_clean();
    echo json_encode([
        'success' => false, 
        'message' => 'Error processing references: ' . $e->getMessage()
    ]);
}

// End output buffering
ob_end_flush();
exit;
?>
