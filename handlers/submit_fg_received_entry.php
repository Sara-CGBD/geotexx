<?php
// submit_fg_received_entry.php

session_start();
require_once '../config/security_config.php';

// Set timezone to Bangladesh
date_default_timezone_set('Asia/Dhaka');

// Session & security checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}
if (SecurityConfig::checkSessionTimeout()) {
    session_destroy();
    header("Location: ../login.html?error=timeout");
    exit();
}
SecurityConfig::updateSessionActivity();
if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    session_destroy();
    header("Location: ../login.html?error=disabled");
    exit();
}

// Connect to database
$conn = SecurityConfig::getConnection();

/**
 * Expand reference ranges into individual references
 * Handles formats like "REF-1 to REF-4" -> ["REF-1", "REF-2", "REF-3", "REF-4"]
 * Also handles comma-separated references
 */
function expandReferenceRanges($referenceString) {
    if (empty($referenceString)) {
        return [];
    }
    
    $references = [];
    $referenceString = trim($referenceString);
    
    // First, handle comma-separated references
    $parts = array_map('trim', explode(',', $referenceString));
    
    foreach ($parts as $part) {
        $part = trim($part);
        if (empty($part)) continue;
        
        // Check if this part is a range (contains " to ")
        if (preg_match('/^(.+?)\s+to\s+(.+)$/i', $part, $matches)) {
            $fromRef = trim($matches[1]);
            $toRef = trim($matches[2]);
            
            // Try to extract the pattern (e.g., "REF-1" -> base="REF-", num=1)
            if (preg_match('/^(.+?)-(\d+)$/', $fromRef, $fromMatches) && 
                preg_match('/^(.+?)-(\d+)$/', $toRef, $toMatches)) {
                $baseFrom = $fromMatches[1];
                $baseTo = $toMatches[1];
                $numFrom = (int)$fromMatches[2];
                $numTo = (int)$toMatches[2];
                
                // Only expand if bases match and numbers are valid
                if ($baseFrom === $baseTo && $numFrom <= $numTo) {
                    for ($i = $numFrom; $i <= $numTo; $i++) {
                        $references[] = $baseFrom . '-' . $i;
                    }
                    continue;
                }
            }
            
            // If pattern doesn't match, just add both references as-is
            $references[] = $fromRef;
            $references[] = $toRef;
        } else {
            // Not a range, add as-is
            $references[] = $part;
        }
    }
    
    return array_unique($references); // Remove duplicates
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get form data
        $entryId = $_POST['entry_id'] ?? '';
        $dateTime = $_POST['date_time'] ?? date('Y-m-d H:i:s');
        $shift = $_POST['shift'] ?? '';
        $productType = $_POST['product_type'] ?? '';
        $tripNumber = $_POST['trip_number'] ?? '';
        $referenceNumber = $_POST['reference_number'] ?? '';
        $deliveredQuantity = trim($_POST['delivered_quantity'] ?? '0');
        $cncCuttingBatch = trim($_POST['cnc_cutting_batch'] ?? '');
        $receivedQuantity = trim($_POST['received_quantity'] ?? '0');
        
        // DEBUG: Log initial values
        error_log("FG Received Entry - Initial POST values:");
        error_log("  product_type = '" . $productType . "'");
        error_log("  cnc_cutting_batch from POST = '" . $cncCuttingBatch . "'");
        error_log("  reference_number from POST = '" . $referenceNumber . "'");
        
        // For bags, ensure CNC cutting batch is properly captured
        // IMPORTANT: If form sent CNC batch in reference_number field, extract it here
        if ($productType === 'bag') {
            // If CNC cutting batch is empty, check if it was sent in reference_number by mistake
            if (empty($cncCuttingBatch) && !empty($referenceNumber)) {
                // If reference_number looks like a batch number (e.g., "CW-01"), use it as CNC batch
                if (preg_match('/^[A-Za-z]+-?\d+$/', $referenceNumber)) {
                    $cncCuttingBatch = $referenceNumber;
                    $referenceNumber = ''; // Clear it since it was actually the CNC batch
                    error_log("FG Received Entry - Bag: Extracted cnc_cutting_batch from reference_number: " . $cncCuttingBatch);
                }
            }
            
            // Final check - ensure we have a CNC cutting batch value
            error_log("FG Received Entry - Bag: Final cncCuttingBatch = '" . $cncCuttingBatch . "'");
        }
        
        $availableQuantity = trim($_POST['available_quantity'] ?? '0');
        $shiftInCharge = trim($_POST['shift_in_charge'] ?? $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User');
        $remarks = $_POST['remarks'] ?? '';
        $summary = $_POST['summary'] ?? '';

        // Validate required fields
        if (empty($entryId)) {
            header("Location: ../forms/fg_received_entry.php?error=missing_field&field=entry_id");
            exit();
        }
        
        if (empty($productType)) {
            header("Location: ../forms/fg_received_entry.php?error=missing_field&field=product_type");
            exit();
        }
        
        if (empty($shiftInCharge) || $shiftInCharge === '0') {
            $shiftInCharge = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';
        }
        
        if ($productType === 'roll') {
            if (empty($tripNumber)) {
                header("Location: ../forms/fg_received_entry.php?error=missing_field&field=trip_number");
                exit();
            }
            if (empty($referenceNumber)) {
                header("Location: ../forms/fg_received_entry.php?error=missing_field&field=reference_number");
                exit();
            }
        } elseif ($productType === 'bag') {
            // For bags, CNC cutting batch is required
            if (empty($cncCuttingBatch)) {
                error_log("FG Received Entry - Bag validation FAILED: cncCuttingBatch is empty!");
                header("Location: ../forms/fg_received_entry.php?error=missing_field&field=cnc_cutting_batch");
                exit();
            }
            
            error_log("FG Received Entry - Bag validation passed: cncCuttingBatch = '" . $cncCuttingBatch . "'");
            
            if (empty($receivedQuantity) || $receivedQuantity <= 0) {
                header("Location: ../forms/fg_received_entry.php?error=missing_field&field=received_quantity");
                exit();
            }
        }

        // Ensure table exists
        $createTable = "
            CREATE TABLE IF NOT EXISTS fg_received_entry (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entry_id VARCHAR(50) UNIQUE,
                date_time DATETIME,
                shift VARCHAR(20),
                product_type VARCHAR(20),
                trip_number INT DEFAULT NULL,
                reference_number TEXT,
                delivered_quantity DECIMAL(10,2) DEFAULT 0,
                cnc_cutting_batch VARCHAR(100) DEFAULT NULL,
                received_quantity DECIMAL(10,2) DEFAULT 0,
                available_quantity DECIMAL(10,2) DEFAULT 0,
                weight_kg DECIMAL(10,2) DEFAULT NULL,
                area_sqm DECIMAL(10,2) DEFAULT NULL,
                shift_in_charge VARCHAR(100) DEFAULT 'User',
                remarks TEXT,
                summary TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ";
        $conn->query($createTable);
        
        // Add missing columns if table already exists
        $colsCheck = $conn->query("SHOW COLUMNS FROM fg_received_entry");
        $existingCols = [];
        if ($colsCheck) {
            while ($row = $colsCheck->fetch_assoc()) {
                $existingCols[] = $row['Field'];
            }
        }
        if (!in_array('cnc_cutting_batch', $existingCols)) {
            $conn->query("ALTER TABLE fg_received_entry ADD COLUMN cnc_cutting_batch VARCHAR(100) DEFAULT NULL AFTER delivered_quantity");
        }
        if (!in_array('received_quantity', $existingCols)) {
            $conn->query("ALTER TABLE fg_received_entry ADD COLUMN received_quantity DECIMAL(10,2) DEFAULT 0 AFTER cnc_cutting_batch");
        }
        if (!in_array('available_quantity', $existingCols)) {
            $conn->query("ALTER TABLE fg_received_entry ADD COLUMN available_quantity DECIMAL(10,2) DEFAULT 0 AFTER received_quantity");
        }
        if (!in_array('weight_kg', $existingCols)) {
            $conn->query("ALTER TABLE fg_received_entry ADD COLUMN weight_kg DECIMAL(10,2) DEFAULT NULL AFTER available_quantity");
        }
        if (!in_array('area_sqm', $existingCols)) {
            $conn->query("ALTER TABLE fg_received_entry ADD COLUMN area_sqm DECIMAL(10,2) DEFAULT NULL AFTER weight_kg");
        }
        
        // Check if entry_id already exists
        $checkStmt = $conn->prepare("SELECT id FROM fg_received_entry WHERE entry_id = ?");
        if ($checkStmt) {
            $checkStmt->bind_param("s", $entryId);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            if ($result && $result->num_rows > 0) {
                $checkStmt->close();
                header("Location: ../forms/fg_received_entry.php?error=system_error&message=Entry ID already exists");
                exit();
            }
            $checkStmt->close();
        }

        // Convert date_time string to proper format
        $dateTimeValue = $dateTime;
        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4}),?\s+(\d{1,2}):(\d{2}):?(\d{2})?\s*(AM|PM)?/i', $dateTime, $matches)) {
            $month = $matches[1];
            $day = $matches[2];
            $year = $matches[3];
            $hour = (int)$matches[4];
            $minute = $matches[5];
            $second = isset($matches[6]) ? $matches[6] : '00';
            $ampm = isset($matches[7]) ? strtoupper($matches[7]) : '';
            
            if ($ampm === 'PM' && $hour < 12) {
                $hour += 12;
            } elseif ($ampm === 'AM' && $hour == 12) {
                $hour = 0;
            }
            
            $dateTimeValue = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
        }

        // Prepare values
        $deliveredQuantityValue = (!empty($deliveredQuantity) && $deliveredQuantity !== '') ? (float)$deliveredQuantity : 0;
        $tripNumberValue = (!empty($tripNumber) && $tripNumber !== '') ? (int)$tripNumber : 0;
        $receivedQuantityValue = (!empty($receivedQuantity) && $receivedQuantity !== '') ? (float)$receivedQuantity : 0;
        $availableQuantityValue = (!empty($availableQuantity) && $availableQuantity !== '') ? (float)$availableQuantity : 0;

        // For bags, available quantity equals received quantity
        if ($productType === 'bag' && $receivedQuantityValue > 0) {
            $availableQuantityValue = $receivedQuantityValue;
        }

        // Expand reference ranges into individual references
        $individualReferences = [];
        if ($productType === 'roll') {
            if (empty($referenceNumber)) {
                header("Location: ../forms/fg_received_entry.php?error=system_error&message=" . urlencode('Reference number is required for rolls!'));
                exit();
            }
            $individualReferences = expandReferenceRanges($referenceNumber);
        } else {
            // For bags: Just use a placeholder identifier - we'll use the CNC batch value directly in the insert
            $individualReferences = ['BAG-' . $entryId];
        }

        // Only validate reference number for rolls
        if ($productType === 'roll' && (empty($individualReferences) || (count($individualReferences) === 1 && empty($individualReferences[0])))) {
            header("Location: ../forms/fg_received_entry.php?error=system_error&message=" . urlencode('Invalid reference number!'));
            exit();
        }

        // Prepare insert statement
        $stmt = $conn->prepare("
            INSERT INTO fg_received_entry (
                entry_id, date_time, shift, product_type, trip_number, reference_number, 
                delivered_quantity, cnc_cutting_batch, received_quantity, available_quantity,
                weight_kg, area_sqm, shift_in_charge, remarks, summary
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmt) {
            error_log("FG Received Entry - Prepare failed: " . $conn->error);
            header("Location: ../forms/fg_received_entry.php?error=system_error&message=" . urlencode($conn->error));
            exit();
        }

        // For rolls: fetch quantities, weights, and areas
        $refQuantities = [];
        $refWeights = [];
        $refAreas = [];

        if ($productType === 'roll' && !empty($tripNumberValue)) {
            foreach ($individualReferences as $ref) {
                $refQuantities[$ref] = 0;
                $refWeights[$ref] = 0;
                $refAreas[$ref] = 0;
                
                $qtyStmt = $conn->prepare("
                    SELECT SUM(rt.amount_kg) as total_amount, COALESCE(re.total_area, 0) as total_area
                    FROM roll_transfer rt
                    LEFT JOIN roll_entry re ON rt.reference_number = re.reference_number
                    WHERE rt.reference_number = ? AND rt.trip = ? AND rt.to_location = 'FG'
                    GROUP BY re.total_area
                ");
                
                if ($qtyStmt) {
                    $qtyStmt->bind_param("si", $ref, $tripNumberValue);
                    $qtyStmt->execute();
                    $qtyResult = $qtyStmt->get_result();
                    if ($qtyRow = $qtyResult->fetch_assoc()) {
                        $refQuantities[$ref] = (float)($qtyRow['total_amount'] ?? 0);
                        $refWeights[$ref] = (float)($qtyRow['total_amount'] ?? 0);
                        $refAreas[$ref] = (float)($qtyRow['total_area'] ?? 0);
                    }
                    $qtyStmt->close();
                }
                
                if ($refQuantities[$ref] <= 0) {
                    $qtyStmt2 = $conn->prepare("SELECT total_weight, COALESCE(total_area, 0) as total_area FROM roll_entry WHERE reference_number = ?");
                    if ($qtyStmt2) {
                        $qtyStmt2->bind_param("s", $ref);
                        $qtyStmt2->execute();
                        $qtyResult2 = $qtyStmt2->get_result();
                        if ($qtyRow2 = $qtyResult2->fetch_assoc()) {
                            $refQuantities[$ref] = (float)($qtyRow2['total_weight'] ?? 0);
                            $refWeights[$ref] = (float)($qtyRow2['total_weight'] ?? 0);
                            $refAreas[$ref] = (float)($qtyRow2['total_area'] ?? 0);
                        }
                        $qtyStmt2->close();
                    }
                }
            }
        }

        // Calculate quantity per reference
        if ($productType === 'bag') {
            $refKey = 'BAG-' . $entryId;
            $refQuantities[$refKey] = $receivedQuantityValue;
            error_log("FG Received Entry - Bag Processing: Setting quantity for key '" . $refKey . "' = " . $receivedQuantityValue);
        } else {
            // For rolls, if quantities not found, divide equally
            $totalFetchedQty = array_sum($refQuantities);
            if ($totalFetchedQty <= 0 && $receivedQuantityValue > 0) {
                $qtyPerRef = $receivedQuantityValue / count($individualReferences);
                foreach ($individualReferences as $ref) {
                    $refQuantities[$ref] = $qtyPerRef;
                }
            } else {
                // Use fetched quantities, but adjust to match total if needed
                if ($totalFetchedQty > 0 && $receivedQuantityValue > 0 && abs($totalFetchedQty - $receivedQuantityValue) > 0.01) {
                    $ratio = $receivedQuantityValue / $totalFetchedQty;
                    foreach ($refQuantities as $ref => $qty) {
                        $refQuantities[$ref] = $qty * $ratio;
                    }
                }
            }
        }

        // Insert rows
        $insertedCount = 0;
        $entryIdBase = $entryId;

        foreach ($individualReferences as $index => $ref) {
            $currentEntryId = $entryIdBase;
            
            if (count($individualReferences) > 1 && $index > 0) {
                $currentEntryId = $entryIdBase . '-' . ($index + 1);
            }
            
            $refQty = $refQuantities[$ref] ?? 0;
            $refAvailableQty = $refQty;
            $refWeight = $refWeights[$ref] ?? 0;
            $refArea = $refAreas[$ref] ?? 0;
            
            // Determine final values based on product type
            if ($productType === 'bag') {
                // For bags: reference_number is NULL/empty, cnc_cutting_batch contains the batch value
                $finalReferenceNumber = null;
                $finalCncBatch = $cncCuttingBatch; // Use the CNC batch from the form directly
                
                error_log("FG Received Entry - Bag Insert Parameters:");
                error_log("  Entry ID: " . $currentEntryId);
                error_log("  CNC Batch: '" . $finalCncBatch . "'");
                error_log("  Reference: NULL");
                error_log("  Quantity: " . $refQty);
            } else {
                // For rolls: reference_number contains the roll reference, cnc_cutting_batch is NULL
                $finalReferenceNumber = $ref;
                $finalCncBatch = null;
                
                error_log("FG Received Entry - Roll Insert Parameters:");
                error_log("  Entry ID: " . $currentEntryId);
                error_log("  Reference: " . $finalReferenceNumber);
                error_log("  CNC Batch: NULL");
                error_log("  Quantity: " . $refQty);
            }
            
            $stmt->bind_param(
                "ssssissddddssss",
                $currentEntryId,
                $dateTimeValue,
                $shift,
                $productType,
                $tripNumberValue,
                $finalReferenceNumber,
                $deliveredQuantityValue,
                $finalCncBatch,
                $refQty,
                $refAvailableQty,
                $refWeight,
                $refArea,
                $shiftInCharge,
                $remarks,
                $summary
            );
            
            if ($stmt->execute()) {
                $insertedCount++;
                error_log("FG Received Entry - Successfully inserted entry with ID: " . $currentEntryId);
            } else {
                error_log("FG Received Entry - Execute failed for " . $currentEntryId . ": " . $stmt->error);
            }
        }

        $stmt->close();

        if ($insertedCount > 0) {
            $successMsg = $insertedCount > 1 
                ? "FG Received Entry saved successfully! Created {$insertedCount} entries."
                : "FG Received Entry saved successfully!";
            header("Location: ../forms/fg_received_entry.php?success=fg_received_saved&entry_id=" . urlencode($entryIdBase));
            exit();
        } else {
            error_log("FG Received Entry - No rows inserted");
            header("Location: ../forms/fg_received_entry.php?error=system_error&message=" . urlencode('Failed to insert any records'));
            exit();
        }

    } catch (Exception $e) {
        error_log("FG Received Entry - Exception: " . $e->getMessage());
        header("Location: ../forms/fg_received_entry.php?error=system_error&message=" . urlencode($e->getMessage()));
        exit();
    }
} else {
    header("Location: ../forms/fg_received_entry.php");
    exit();
}

$conn->close();
?>
