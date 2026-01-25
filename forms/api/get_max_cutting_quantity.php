<?php
// API to get maximum cutting quantity (in pieces) for a reference/roll
session_start();
require_once '../../config/security_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $conn = SecurityConfig::getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    
    $reference = $_GET['reference'] ?? '';
    if (empty($reference)) {
        echo json_encode(['success' => false, 'message' => 'Reference number is required']);
        exit;
    }
    
    // Check if roll_qc_reports table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'roll_qc_reports'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'roll_qc_reports table not found']);
        exit;
    }
    
    // Check column existence
    $hasProductAmount = false;
    $hasApproved = false;
    $hasOverallStatus = false;
    $hasRollNo = false;
    
    $colCheck = $conn->query("SHOW COLUMNS FROM roll_qc_reports");
    if ($colCheck) {
        while ($col = $colCheck->fetch_assoc()) {
            if ($col['Field'] === 'product_amount') $hasProductAmount = true;
            if ($col['Field'] === 'approved') $hasApproved = true;
            if ($col['Field'] === 'overall_status') $hasOverallStatus = true;
            if ($col['Field'] === 'roll_no') $hasRollNo = true;
        }
    }
    
    if (!$hasProductAmount) {
        echo json_encode(['success' => false, 'message' => 'product_amount column not found']);
        exit;
    }
    
    // Build WHERE clause for approved reports
    $approvedCondition = '';
    if ($hasApproved && $hasOverallStatus) {
        $approvedCondition = "AND (rqc.approved = 1 OR rqc.overall_status IN ('approved', 'Done'))";
    } elseif ($hasApproved) {
        $approvedCondition = "AND rqc.approved = 1";
    } elseif ($hasOverallStatus) {
        $approvedCondition = "AND rqc.overall_status IN ('approved', 'Done')";
    }
    
    // Get product_amount from roll_qc_reports for this reference
    // For bundles, we need to check each individual reference in the bundle
    $referenceEscaped = $conn->real_escape_string($reference);
    
    // Check if this is a bundle (contains range like "REF-1-4")
    $isBundle = preg_match('/^(.+)-(\d+)-(\d+)$/', $reference, $bundleMatches);
    
    $maxQuantities = [];
    
    if ($isBundle) {
        // Handle bundle: extract base and range
        $baseRef = $bundleMatches[1];
        $startNum = (int)$bundleMatches[2];
        $endNum = (int)$bundleMatches[3];
        
        // Get max quantity for each roll in the bundle
        for ($rollNum = $startNum; $rollNum <= $endNum; $rollNum++) {
            $individualRef = $baseRef . '-' . $rollNum;
            $individualRefEscaped = $conn->real_escape_string($individualRef);
            
            // Get product_amount from roll_qc_reports
            $rollNoCondition = $hasRollNo ? "AND (rqc.roll_no = '$rollNum' OR rqc.roll_no IS NULL)" : "";
            $qtyQuery = "SELECT COALESCE(rqc.product_amount, 0) as product_amount
                        FROM roll_qc_reports rqc
                        WHERE rqc.reference_number = '$individualRefEscaped'
                        $rollNoCondition
                        $approvedCondition
                        ORDER BY rqc.created_at DESC
                        LIMIT 1";
            
            $qtyResult = $conn->query($qtyQuery);
            $productAmount = 0;
            if ($qtyResult && $qtyRow = $qtyResult->fetch_assoc()) {
                $productAmount = (float)$qtyRow['product_amount'];
            }
            
            // Calculate used quantity from cnc_entries
            // First check if reference_quantities column exists
            $hasRefQuantities = false;
            $refQtyColCheck = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'reference_quantities'");
            if ($refQtyColCheck && $refQtyColCheck->num_rows > 0) {
                $hasRefQuantities = true;
            }
            
            $usedQty = 0;
            if ($hasRefQuantities) {
                // Use reference_quantities JSON field for accurate per-reference tracking
                $usedQuery = "SELECT reference_quantities
                             FROM cnc_entries c
                             WHERE (FIND_IN_SET('$individualRefEscaped', c.reference_number) > 0
                                    OR c.reference_number = '$individualRefEscaped')
                             AND c.reference_quantities IS NOT NULL
                             AND c.reference_quantities != ''
                             AND (c.is_deleted = 0 OR c.is_deleted IS NULL)";
                
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
                                        if (strcasecmp(trim($storedRef), trim($individualRef)) === 0) {
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
                // Fallback to old method if column doesn't exist
                $usedQuery = "SELECT COALESCE(SUM(cutting_roll_quantity), 0) as used_qty
                             FROM cnc_entries c
                             WHERE (FIND_IN_SET('$individualRefEscaped', c.reference_number) > 0
                                    OR c.reference_number = '$individualRefEscaped')
                             AND (c.is_deleted = 0 OR c.is_deleted IS NULL)";
                
                $usedResult = $conn->query($usedQuery);
                if ($usedResult && $usedRow = $usedResult->fetch_assoc()) {
                    $usedQty = (int)$usedRow['used_qty'];
                }
            }
            
            // Calculate max available
            // Standard max per roll is 36 pieces
            $maxPerRoll = 36;
            $maxAvailable = 0;
            
            if ($productAmount > 0) {
                // If QC data exists, calculate from product_amount
                $maxAvailable = max(0, floor($productAmount) - $usedQty);
                $maxAvailable = min($maxAvailable, $maxPerRoll);
            } else {
                // No QC data, calculate remaining from max per roll
                // Remaining = 36 - usedQty (if usedQty < 36)
                if ($usedQty >= $maxPerRoll) {
                    $maxAvailable = 0; // Fully used
                } else {
                    $maxAvailable = $maxPerRoll - $usedQty; // Remaining capacity
                }
            }
            
            $maxQuantities[] = [
                'roll_number' => $rollNum,
                'reference' => $individualRef,
                'max_quantity' => $maxAvailable,
                'product_amount' => $productAmount,
                'used_quantity' => $usedQty
            ];
        }
    } else {
        // Handle single reference
        // Extract roll number if present (e.g., "REF-1" -> roll 1)
        $rollNumber = 1;
        if (preg_match('/^(.+)-(\d+)$/', $reference, $matches)) {
            $rollNumber = (int)$matches[2];
        }
        
        // Get product_amount from roll_qc_reports
        $rollNoCondition = $hasRollNo ? "AND (rqc.roll_no = '$rollNumber' OR rqc.roll_no IS NULL)" : "";
        $qtyQuery = "SELECT COALESCE(rqc.product_amount, 0) as product_amount
                    FROM roll_qc_reports rqc
                    WHERE rqc.reference_number = '$referenceEscaped'
                    $rollNoCondition
                    $approvedCondition
                    ORDER BY rqc.created_at DESC
                    LIMIT 1";
        
        $qtyResult = $conn->query($qtyQuery);
        $productAmount = 0;
        if ($qtyResult && $qtyRow = $qtyResult->fetch_assoc()) {
            $productAmount = (float)$qtyRow['product_amount'];
        }
        
        // Calculate used quantity from cnc_entries
        // First check if reference_quantities column exists
        $hasRefQuantities = false;
        $refQtyColCheck = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'reference_quantities'");
        if ($refQtyColCheck && $refQtyColCheck->num_rows > 0) {
            $hasRefQuantities = true;
        }
        
        $usedQty = 0;
        if ($hasRefQuantities) {
            // Use reference_quantities JSON field for accurate per-reference tracking
            $usedQuery = "SELECT reference_quantities
                         FROM cnc_entries c
                         WHERE (FIND_IN_SET('$referenceEscaped', c.reference_number) > 0
                                OR c.reference_number = '$referenceEscaped')
                         AND c.reference_quantities IS NOT NULL
                         AND c.reference_quantities != ''
                         AND (c.is_deleted = 0 OR c.is_deleted IS NULL)";
            
            $usedResult = $conn->query($usedQuery);
            if ($usedResult) {
                while ($usedRow = $usedResult->fetch_assoc()) {
                    $refQuantitiesJson = $usedRow['reference_quantities'];
                    if (!empty($refQuantitiesJson)) {
                        $refQuantities = json_decode($refQuantitiesJson, true);
                        if (is_array($refQuantities)) {
                            // Try exact match first
                            if (isset($refQuantities[$reference])) {
                                $usedQty += (int)$refQuantities[$reference];
                            } else {
                                // Try case-insensitive and trimmed match (handle whitespace differences)
                                foreach ($refQuantities as $storedRef => $qty) {
                                    if (strcasecmp(trim($storedRef), trim($reference)) === 0) {
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
            // Fallback to old method if column doesn't exist
            $usedQuery = "SELECT COALESCE(SUM(cutting_roll_quantity), 0) as used_qty
                         FROM cnc_entries c
                         WHERE (FIND_IN_SET('$referenceEscaped', c.reference_number) > 0
                                OR c.reference_number = '$referenceEscaped')
                         AND (c.is_deleted = 0 OR c.is_deleted IS NULL)";
            
            $usedResult = $conn->query($usedQuery);
            if ($usedResult && $usedRow = $usedResult->fetch_assoc()) {
                $usedQty = (int)$usedRow['used_qty'];
            }
        }
        
        // Calculate max available
        // Standard max per roll is 36 pieces
        $maxPerRoll = 36;
        $maxAvailable = 0;
        
        if ($productAmount > 0) {
            // If QC data exists, calculate from product_amount
            $maxAvailable = max(0, floor($productAmount) - $usedQty);
            $maxAvailable = min($maxAvailable, $maxPerRoll);
        } else {
            // No QC data, calculate remaining from max per roll
            // Remaining = 36 - usedQty (if usedQty < 36)
            if ($usedQty >= $maxPerRoll) {
                $maxAvailable = 0; // Fully used
            } else {
                $maxAvailable = $maxPerRoll - $usedQty; // Remaining capacity
            }
        }
        
        $maxQuantities[] = [
            'roll_number' => $rollNumber,
            'reference' => $reference,
            'max_quantity' => $maxAvailable,
            'product_amount' => $productAmount,
            'used_quantity' => $usedQty
        ];
    }
    
    echo json_encode([
        'success' => true,
        'reference' => $reference,
        'max_quantities' => $maxQuantities
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
