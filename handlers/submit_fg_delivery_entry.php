<?php
session_start();
require_once '../config/security_config.php';

date_default_timezone_set('Asia/Dhaka');

// Session & security checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../forms/fg_delivery_entry.php");
    exit();
}

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

try {
    // Get form data
    $deliveryId = $_POST['delivery_id'] ?? '';
    $fgEntryId = $_POST['fg_entry_id'] ?? '';
    $referenceNumber = $_POST['reference_number'] ?? '';
    $bagSize = $_POST['bag_size'] ?? '';
    $packagingType = $_POST['packaging_type'] ?? '';
    $deliveryQty = (float)($_POST['delivery_qty'] ?? 0);
    $deliveryProductType = $_POST['delivery_product_type'] ?? 'bag'; // roll or bag
    
    // Parse individual reference quantities (for rolls)
    $referenceQuantitiesMap = [];
    if ($deliveryProductType === 'roll' && !empty($_POST['reference_quantities'])) {
        $referenceQuantitiesJson = $_POST['reference_quantities'];
        $referenceQuantitiesArray = json_decode($referenceQuantitiesJson, true);
        if (is_array($referenceQuantitiesArray)) {
            foreach ($referenceQuantitiesArray as $refQty) {
                if (isset($refQty['reference']) && isset($refQty['delivery_quantity'])) {
                    $referenceQuantitiesMap[trim($refQty['reference'])] = (float)$refQty['delivery_quantity'];
                }
            }
        }
    }
    
    // CNC cutting batch is only for bags, not for rolls
    $cncCuttingBatch = '';
    if ($deliveryProductType === 'bag') {
        $cncCuttingBatch = $_POST['cnc_cutting_batch'] ?? '';
    }
    $deliveryRollEntryType = $_POST['delivery_roll_entry_type'] ?? ''; // individual or bundle
    $deliveryQuantityUnit = $_POST['delivery_quantity_unit'] ?? 'kg'; // kg or sqm
    $clientId = !empty($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
    $clientName = $_POST['client_name'] ?? '';
    $challanNo = $_POST['challan_no'] ?? '';
    $truckNo = $_POST['truck_no'] ?? '';
    $destination = $_POST['destination'] ?? '';
    $unitPrice = (float)($_POST['unit_price'] ?? 0);
    $shift = $_POST['shift'] ?? '';
    
    // Get delivery date and time
    $dateTimeInput = $_POST['date_time'] ?? date('Y-m-d H:i:s');
    $deliveryDate = date('Y-m-d', strtotime($dateTimeInput)); // DATE format for delivery_date column
    $deliveredAt = date('Y-m-d H:i:s', strtotime($dateTimeInput)); // Full DATETIME
    
    $remarks = $_POST['remarks'] ?? '';
    $deliveredBy = $_SESSION['username'] ?? 'System';
    
    // Calculate total cost
    $totalCost = $deliveryQty * $unitPrice;
    
    // Validate required fields based on product type
    if ($deliveryQty <= 0) {
        header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('Invalid delivery quantity!'));
        exit();
    }
    
    // For rolls, reference_number is required
    // For bags, cnc_cutting_batch is required (reference_number is not used)
    if ($deliveryProductType === 'roll' && empty($referenceNumber)) {
        header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('Please select a reference number!'));
        exit();
    }
    
    if ($deliveryProductType === 'bag' && empty($cncCuttingBatch)) {
        header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('Please select a CNC cutting batch!'));
        exit();
    }
    
    if (empty($clientName)) {
        header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('Please select or enter a client name!'));
        exit();
    }
    
    // If client_id is 0 or empty, create a new client in the clients table
    if (empty($clientId) || $clientId == '0' || $clientId == 0) {
        // Check if client already exists by name
        $checkClientStmt = $conn->prepare("SELECT id FROM clients WHERE client_name = ?");
        $checkClientStmt->bind_param('s', $clientName);
        $checkClientStmt->execute();
        $clientResult = $checkClientStmt->get_result();
        
        if ($clientResult->num_rows > 0) {
            // Client exists, use existing ID
            $clientRow = $clientResult->fetch_assoc();
            $clientId = $clientRow['id'];
        } else {
            // Create new client
            $createClientStmt = $conn->prepare("INSERT INTO clients (client_name, is_active) VALUES (?, 1)");
            $createClientStmt->bind_param('s', $clientName);
            if ($createClientStmt->execute()) {
                $clientId = $conn->insert_id;
            } else {
                // If insert fails, still proceed with client_id = 0 and client_name
                $clientId = 0;
            }
            $createClientStmt->close();
        }
        $checkClientStmt->close();
    }
    
    // --- ROBUST STOCK VALIDATION ---
    // For bags: Calculate remaining quantity from branding entries (print_qty - delivered_qty)
    // For rolls: Get stock from FG entry using reference_number
    
    // Initialize rollReferencesData for rolls (will be populated in the else block)
    $rollReferencesData = [];
    
    if ($deliveryProductType === 'bag') {
        // For bags, calculate remaining quantity from branding entries
        // Check if is_deleted column exists in branding_entries
        $isDeletedCheck = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'is_deleted'");
        $hasIsDeleted = ($isDeletedCheck && $isDeletedCheck->num_rows > 0);
        
        // Get total print_qty from branding entries for this CNC batch
        $query = "SELECT 
                    SUM(COALESCE(print_qty, 0)) as total_print_qty
                  FROM branding_entries
                  WHERE cnc_cutting_batch = ?";
        
        if ($hasIsDeleted) {
            $query .= " AND (is_deleted = 0 OR is_deleted IS NULL)";
        }
        
        $brandingCheck = $conn->prepare($query);
        $brandingCheck->bind_param('s', $cncCuttingBatch);
        $brandingCheck->execute();
        $brandingResult = $brandingCheck->get_result();
        
        if ($brandingResult->num_rows === 0) {
            header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('No branding entries found for this CNC cutting batch!'));
            $brandingCheck->close();
            exit();
        }
        
        $brandingData = $brandingResult->fetch_assoc();
        $totalPrintQty = (float)$brandingData['total_print_qty'];
        $brandingCheck->close();
        
        // Get total delivered quantity for this CNC batch
        $deliveredCheck = $conn->prepare("SELECT 
                                            SUM(COALESCE(delivery_quantity, 0)) as total_delivered
                                          FROM fg_deliveries
                                          WHERE cnc_cutting_batch = ?");
        $deliveredCheck->bind_param('s', $cncCuttingBatch);
        $deliveredCheck->execute();
        $deliveredResult = $deliveredCheck->get_result();
        $deliveredData = $deliveredResult->fetch_assoc();
        $totalDelivered = (float)($deliveredData['total_delivered'] ?? 0);
        $deliveredCheck->close();
        
        $remainingQty = $totalPrintQty - $totalDelivered;
        $passedQty = $totalPrintQty;
        $actualWeight = 0;
        $productType = 'bag';
        $deliveredQty = $totalDelivered;
        $refWeightKg = 0; // Bags don't have weight_kg
        $refAreaSqm = 0; // Bags don't have area_sqm
        
        // Set reference_number to empty for bags (not used)
        $referenceNumber = '';
        
        // For bags, we don't need fg_entry_id, set to NULL
        if (empty($fgEntryId)) {
            $fgEntryId = null;
        }
        
    } else {
        // For rolls, expand references first (handles comma-separated and ranges)
        $referenceNumber = trim($referenceNumber);
        
        if (empty($referenceNumber)) {
            header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('Invalid reference number!'));
            exit();
        }
        
        // Expand references (handles comma-separated and ranges like "REF-1 to REF-4")
        $individualReferences = expandReferenceRanges($referenceNumber);
        
        if (empty($individualReferences)) {
            header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('Invalid reference number!'));
            exit();
        }
        
        // For rolls, we'll process each reference separately
        // Store reference data for processing later (already initialized above)
        $checkStmt = $conn->prepare("SELECT 
                                        fre.id,
                                        fre.reference_number,
                                        fre.received_quantity,
                                        fre.available_quantity,
                                        fre.product_type,
                                        COALESCE(fre.weight_kg, 0) as weight_kg,
                                        COALESCE(fre.area_sqm, 0) as area_sqm,
                                        COALESCE(SUM(fd.delivery_quantity), 0) as delivered_quantity,
                                        (fre.received_quantity - COALESCE(SUM(fd.delivery_quantity), 0)) as remaining_quantity
                                      FROM fg_received_entry fre
                                      LEFT JOIN fg_deliveries fd ON fre.id = fd.fg_entry_id AND fd.delivery_product_type = 'roll'
                                      WHERE fre.product_type = 'roll'
                                        AND fre.reference_number = ?
                                      GROUP BY fre.id, fre.reference_number, fre.received_quantity, fre.available_quantity, fre.product_type, fre.weight_kg, fre.area_sqm
                                      LIMIT 1");
        
        foreach ($individualReferences as $ref) {
            $ref = trim($ref);
            if (empty($ref)) continue;
            
            $checkStmt->bind_param('s', $ref);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows === 0) {
                $checkStmt->close();
                
                // Better error message
                $errorMsg = 'No FG Received Entry found for reference: "' . htmlspecialchars($ref) . '"\n\n';
                $errorMsg .= 'Solution:\n';
                $errorMsg .= '1. Check FG Received Entry form - ensure this reference exists\n';
                $errorMsg .= '2. If no FG Received Entry exists, create one first';
                
                header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode($errorMsg));
                exit();
            }
            
            $fgData = $result->fetch_assoc();
            $rollReferencesData[] = [
                'reference' => $ref,
                'fg_entry_id' => $fgData['id'],
                'received_quantity' => (float)$fgData['received_quantity'],
                'delivered_quantity' => (float)$fgData['delivered_quantity'],
                'remaining_quantity' => (float)$fgData['remaining_quantity'],
                'weight_kg' => (float)($fgData['weight_kg'] ?? 0),
                'area_sqm' => (float)($fgData['area_sqm'] ?? 0)
            ];
        }
        
        $checkStmt->close();
        
        // For backward compatibility with existing code structure, use first reference data
        // But we'll process all references in the delivery insertion section
        if (!empty($rollReferencesData)) {
            $firstRefData = $rollReferencesData[0];
            if (empty($fgEntryId)) {
                $fgEntryId = $firstRefData['fg_entry_id'];
            }
            $passedQty = $firstRefData['received_quantity'];
            $actualWeight = $firstRefData['received_quantity'];
            $productType = 'roll';
            $deliveredQty = $firstRefData['delivered_quantity'];
            
            // Calculate total remaining quantity across all references based on selected unit
            $deliveryQuantityUnit = $_POST['delivery_quantity_unit'] ?? 'kg'; // kg or sqm
            
            if ($deliveryQuantityUnit === 'sqm') {
                // For sqm, use area_sqm (which is fixed per reference, not reduced by deliveries)
                $totalRemainingQty = 0;
                foreach ($rollReferencesData as $refData) {
                    $totalRemainingQty += $refData['area_sqm'];
                }
                $remainingQty = $totalRemainingQty;
            } else {
                // For kg, use remaining_quantity (received - delivered)
                $totalRemainingQty = 0;
                foreach ($rollReferencesData as $refData) {
                    $totalRemainingQty += $refData['remaining_quantity'];
                }
                $remainingQty = $totalRemainingQty;
            }
            
            $refWeightKg = $firstRefData['weight_kg'];
            $refAreaSqm = $firstRefData['area_sqm'];
        } else {
            header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('No valid references found!'));
            exit();
        }
    }
    
    // Get delivery quantity unit for validation
    $deliveryQuantityUnit = $_POST['delivery_quantity_unit'] ?? 'kg';
    
    // Check if there's any stock available based on selected unit
    if ($deliveryQuantityUnit === 'sqm' && $productType === 'roll') {
        // For sqm, check if area_sqm > 0
        $totalAreaSqm = 0;
        if (!empty($rollReferencesData)) {
            foreach ($rollReferencesData as $refData) {
                $totalAreaSqm += $refData['area_sqm'];
            }
        }
        if ($totalAreaSqm <= 0) {
            header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('No area (sqm) available for this reference!'));
            exit();
        }
        // Update remainingQty to total area for sqm validation
        $remainingQty = $totalAreaSqm;
    } else if ($remainingQty <= 0) {
        if ($productType === 'roll' && $actualWeight <= 0) {
            header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('No stock available for delivery!\n\nReason:\n• The FG Received Entry for this reference has 0 Received Quantity, OR\n• All stock has already been delivered\n\nSolution:\n1. Check FG Received Entry form - ensure Received Quantity > 0\n2. Verify delivery history for this reference'));
            exit();
        } else if ($passedQty <= 0) {
            header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('No stock available for delivery!\n\nReason:\n• The FG Received Entry for this reference has 0 Received Quantity, OR\n• All stock has already been delivered\n\nSolution:\n1. Check FG Received Entry form - ensure Received Quantity > 0\n2. Verify delivery history for this reference'));
            exit();
        } else {
            header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('All stock has already been delivered for this reference!\n\nReceived Qty: ' . $passedQty . '\nDelivered: ' . $deliveredQty . '\nRemaining: 0'));
            exit();
        }
    }
    
    // Prevent delivery quantity exceeding available stock
    // For rolls with multiple references, $remainingQty is now the sum of all remaining quantities
    if ($deliveryQty > $remainingQty) {
        $unit = ($deliveryProductType === 'roll') ? ($deliveryQuantityUnit === 'sqm' ? 'sqm' : 'kg') : 'pcs';
        header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode("Delivery quantity ({$deliveryQty} {$unit}) exceeds available stock ({$remainingQty} {$unit})!\n\nPlease reduce the quantity."));
        exit();
    }
    
    // Create fg_deliveries table if not exists
    $conn->query("CREATE TABLE IF NOT EXISTS fg_deliveries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        delivery_id VARCHAR(50) UNIQUE,
        fg_entry_id INT,
        reference_number VARCHAR(100),
        cnc_cutting_batch VARCHAR(100),
        delivery_date DATE NOT NULL,
        shift VARCHAR(20),
        challan_no VARCHAR(50),
        truck_no VARCHAR(50),
        destination VARCHAR(200),
        bag_size VARCHAR(50),
        packaging_type VARCHAR(50),
        delivery_quantity DECIMAL(10,2) NOT NULL,
        weight_kg DECIMAL(10,2) DEFAULT NULL,
        area_sqm DECIMAL(10,2) DEFAULT NULL,
        client_id INT,
        client_name VARCHAR(200),
        unit_price DECIMAL(10,2),
        total_cost DECIMAL(10,2),
        remarks TEXT,
        delivered_by VARCHAR(100),
        delivered_at DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Add foreign key constraint only if it doesn't exist and fg_entry_id column is NOT NULL
    // For bags, fg_entry_id can be NULL, so we'll make it nullable
    $colCheck = $conn->query("SHOW COLUMNS FROM fg_deliveries LIKE 'fg_entry_id'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $colData = $colCheck->fetch_assoc();
        if ($colData['Null'] === 'NO') {
            // Make fg_entry_id nullable for bags
            $conn->query("ALTER TABLE fg_deliveries MODIFY COLUMN fg_entry_id INT NULL");
        }
    }
    
    // Add columns if they don't exist (proper MySQL syntax)
    $deliveryColsCheck = $conn->query("SHOW COLUMNS FROM fg_deliveries");
    $existingDeliveryCols = [];
    if ($deliveryColsCheck) {
        while ($row = $deliveryColsCheck->fetch_assoc()) {
            $existingDeliveryCols[] = $row['Field'];
        }
    }
    if (!in_array('destination', $existingDeliveryCols)) {
        $conn->query("ALTER TABLE fg_deliveries ADD COLUMN destination VARCHAR(200) AFTER truck_no");
    }
    if (!in_array('delivery_product_type', $existingDeliveryCols)) {
        $conn->query("ALTER TABLE fg_deliveries ADD COLUMN delivery_product_type VARCHAR(20) DEFAULT 'bag' AFTER delivery_quantity");
    }
    if (!in_array('delivery_roll_entry_type', $existingDeliveryCols)) {
        $conn->query("ALTER TABLE fg_deliveries ADD COLUMN delivery_roll_entry_type VARCHAR(20) DEFAULT '' AFTER delivery_product_type");
    }
    if (!in_array('delivered_by', $existingDeliveryCols)) {
        $conn->query("ALTER TABLE fg_deliveries ADD COLUMN delivered_by VARCHAR(100) AFTER remarks");
    }
    if (!in_array('delivered_at', $existingDeliveryCols)) {
        $conn->query("ALTER TABLE fg_deliveries ADD COLUMN delivered_at DATETIME AFTER delivered_by");
    }
    if (!in_array('weight_kg', $existingDeliveryCols)) {
        $conn->query("ALTER TABLE fg_deliveries ADD COLUMN weight_kg DECIMAL(10,2) DEFAULT NULL AFTER delivery_quantity");
    }
    if (!in_array('area_sqm', $existingDeliveryCols)) {
        $conn->query("ALTER TABLE fg_deliveries ADD COLUMN area_sqm DECIMAL(10,2) DEFAULT NULL AFTER weight_kg");
    }
    if (!in_array('delivery_quantity_unit', $existingDeliveryCols)) {
        $conn->query("ALTER TABLE fg_deliveries ADD COLUMN delivery_quantity_unit VARCHAR(10) DEFAULT 'kg' AFTER delivery_quantity");
    }
    
    // Prepare INSERT statement
    $stmt = $conn->prepare("INSERT INTO fg_deliveries 
        (delivery_id, fg_entry_id, reference_number, cnc_cutting_batch, delivery_date, shift, 
         challan_no, truck_no, destination, bag_size, packaging_type, delivery_quantity, 
         delivery_quantity_unit, weight_kg, area_sqm, delivery_product_type, delivery_roll_entry_type, client_id, client_name, 
         unit_price, total_cost, remarks, delivered_by, delivered_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $insertedCount = 0;
    $totalDeliveredQty = 0;
    $updatedFgEntryIds = [];
    
    // For rolls with multiple references, store each reference in a separate row
    if ($deliveryProductType === 'roll' && !empty($rollReferencesData)) {
        $deliveryIdBase = $deliveryId;
        foreach ($rollReferencesData as $index => $refData) {
            $currentDeliveryId = $deliveryIdBase;
            // For multiple references, append suffix to delivery_id (except first one keeps original)
            if (count($rollReferencesData) > 1 && $index > 0) {
                $currentDeliveryId = $deliveryIdBase . '-' . ($index + 1);
            }
            
            // Use user-input delivery quantity if provided, otherwise use remaining quantity
            $refReferenceNumber = $refData['reference'];
            $refDeliveryQty = isset($referenceQuantitiesMap[$refReferenceNumber]) 
                ? $referenceQuantitiesMap[$refReferenceNumber] 
                : $refData['remaining_quantity'];
            $refFgEntryId = $refData['fg_entry_id'];
            $refWeightKg = $refData['weight_kg'];
            $refAreaSqm = $refData['area_sqm'];
            
            // Calculate cost for this reference
            $refTotalCost = $refDeliveryQty * $unitPrice;
            
            // Bind parameters for this reference
            // Type string: 24 parameters
            // s(1) i(1) s(10) d(1) s(1) d(3) s(2) i(1) s(1) d(2) s(1) = 24
            $stmt->bind_param(
                'sisssssssssdsddssisddsss',  // Type string: 24 characters
                $currentDeliveryId,        // 1. s - delivery_id
                $refFgEntryId,             // 2. i - fg_entry_id
                $refReferenceNumber,       // 3. s - reference_number
                $cncCuttingBatch,          // 4. s - cnc_cutting_batch (empty for rolls)
                $deliveryDate,             // 5. s - delivery_date
                $shift,                    // 6. s - shift
                $challanNo,                // 7. s - challan_no
                $truckNo,                  // 8. s - truck_no
                $destination,              // 9. s - destination
                $bagSize,                  // 10. s - bag_size (empty for rolls)
                $packagingType,            // 11. s - packaging_type (empty for rolls)
                $refDeliveryQty,           // 12. d - delivery_quantity
                $deliveryQuantityUnit,     // 13. s - delivery_quantity_unit (kg or sqm)
                $refWeightKg,              // 14. d - weight_kg
                $refAreaSqm,               // 15. d - area_sqm
                $deliveryProductType,      // 16. s - delivery_product_type
                $deliveryRollEntryType,    // 17. s - delivery_roll_entry_type
                $clientId,                 // 18. i - client_id
                $clientName,               // 19. s - client_name
                $unitPrice,                // 20. d - unit_price
                $refTotalCost,             // 21. d - total_cost
                $remarks,                  // 22. s - remarks
                $deliveredBy,              // 23. s - delivered_by
                $deliveredAt              // 24. s - delivered_at
            );
            
            if ($stmt->execute()) {
                $insertedCount++;
                $totalDeliveredQty += $refDeliveryQty;
                if (!in_array($refFgEntryId, $updatedFgEntryIds)) {
                    $updatedFgEntryIds[] = $refFgEntryId;
                }
            } else {
                error_log("FG Delivery - Execute failed for reference {$refReferenceNumber}: " . $stmt->error);
                throw new Exception('Failed to insert delivery record for reference ' . $refReferenceNumber . ': ' . $stmt->error);
            }
        }
        
        $stmt->close();
        
        // Update fg_received_entry for each reference
        foreach ($updatedFgEntryIds as $fgEntryIdToUpdate) {
            // Calculate total delivered quantity for this fg_received_entry
            $totalDeliveredStmt = $conn->prepare("SELECT COALESCE(SUM(fd.delivery_quantity), 0) as total_delivered
                                                   FROM fg_deliveries fd
                                                   WHERE fd.fg_entry_id = ? AND fd.delivery_product_type = 'roll'");
            $totalDeliveredStmt->bind_param('i', $fgEntryIdToUpdate);
            $totalDeliveredStmt->execute();
            $totalDeliveredResult = $totalDeliveredStmt->get_result();
            $totalDeliveredData = $totalDeliveredResult->fetch_assoc();
            $totalDelivered = (float)($totalDeliveredData['total_delivered'] ?? 0);
            $totalDeliveredStmt->close();
            
            // Get original received_quantity to calculate new available_quantity
            $getReceivedStmt = $conn->prepare("SELECT received_quantity FROM fg_received_entry WHERE id = ?");
            $getReceivedStmt->bind_param('i', $fgEntryIdToUpdate);
            $getReceivedStmt->execute();
            $getReceivedResult = $getReceivedStmt->get_result();
            $getReceivedData = $getReceivedResult->fetch_assoc();
            $originalReceived = (float)($getReceivedData['received_quantity'] ?? 0);
            $getReceivedStmt->close();
            
            // Update delivered_quantity and available_quantity
            $newAvailable = $originalReceived - $totalDelivered;
            $updateStmt = $conn->prepare("UPDATE fg_received_entry 
                                           SET delivered_quantity = ?,
                                               available_quantity = ?
                                           WHERE id = ?");
            $updateStmt->bind_param('ddi', $totalDelivered, $newAvailable, $fgEntryIdToUpdate);
            
            if (!$updateStmt->execute()) {
                throw new Exception('Failed to update delivered quantity: ' . $updateStmt->error);
            }
            $updateStmt->close();
        }
        
        // Calculate new remaining for success message
        $newRemaining = $remainingQty - $totalDeliveredQty;
        
    } else {
        // For bags, use existing single-row logic
        // Handle nullable fg_entry_id for bags - use 0 for bags, actual ID for rolls
        $fgEntryIdValue = ($deliveryProductType === 'bag' && (empty($fgEntryId) || $fgEntryId == 0)) ? null : (int)$fgEntryId;
        $fgEntryIdForBind = ($fgEntryIdValue === null) ? 0 : (int)$fgEntryIdValue;
        
        $stmt->bind_param(
            'sisssssssssdsdddssisdds',  // Type string: 24 characters
            $deliveryId,              // 1. s - delivery_id
            $fgEntryIdForBind,        // 2. i - fg_entry_id (0 for bags)
            $referenceNumber,         // 3. s - reference_number
            $cncCuttingBatch,         // 4. s - cnc_cutting_batch
            $deliveryDate,            // 5. s - delivery_date
            $shift,                   // 6. s - shift
            $challanNo,               // 7. s - challan_no
            $truckNo,                 // 8. s - truck_no
            $destination,             // 9. s - destination
            $bagSize,                 // 10. s - bag_size
            $packagingType,           // 11. s - packaging_type
            $deliveryQty,             // 12. d - delivery_quantity
            $deliveryQuantityUnit,    // 13. s - delivery_quantity_unit (kg or sqm)
            $refWeightKg,             // 14. d - weight_kg
            $refAreaSqm,              // 15. d - area_sqm
            $deliveryProductType,     // 16. s - delivery_product_type
            $deliveryRollEntryType,   // 17. s - delivery_roll_entry_type
            $clientId,                // 18. i - client_id
            $clientName,              // 19. s - client_name
            $unitPrice,               // 20. d - unit_price
            $totalCost,               // 21. d - total_cost
            $remarks,                 // 22. s - remarks
            $deliveredBy,             // 23. s - delivered_by
            $deliveredAt              // 24. s - delivered_at
        );
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to insert delivery record: ' . $stmt->error);
        }
        
        $insertId = $conn->insert_id;
        $stmt->close();
        
        // If fg_entry_id should be NULL for bags, update it after insert
        if ($fgEntryIdValue === null && $insertId) {
            $conn->query("UPDATE fg_deliveries SET fg_entry_id = NULL WHERE id = $insertId");
        }
        
        // For bags, calculate new remaining
        $newRemaining = $remainingQty - $deliveryQty;
        $totalDeliveredQty = $deliveryQty;
    }
    
    $conn->close();
    
    // Success message with detailed information
    $unitLabel = ($deliveryProductType === 'roll') ? 'kg' : 'pcs';
    $productLabel = ($deliveryProductType === 'roll') ? 'Rolls' : 'Bags';
    
    if ($deliveryProductType === 'roll' && !empty($rollReferencesData) && count($rollReferencesData) > 1) {
        $successMsg = count($rollReferencesData) . " reference(s) delivered successfully!\n\n";
        $successMsg .= "Delivery ID: {$deliveryId} (and variations)\n";
        $successMsg .= "Total Quantity: {$totalDeliveredQty} {$unitLabel}\n";
        $successMsg .= "Client: {$clientName}";
    } else {
        $successMsg = " {$totalDeliveredQty} {$productLabel} delivered successfully!\n\n";
        $successMsg .= "Delivery ID: {$deliveryId}\n";
        if ($deliveryProductType === 'roll') {
            $successMsg .= "Reference: {$referenceNumber}\n";
        } else {
            $successMsg .= "CNC Batch: {$cncCuttingBatch}\n";
        }
        $successMsg .= "Quantity: {$totalDeliveredQty} {$unitLabel}\n";
        $successMsg .= "Remaining Stock: {$newRemaining} {$unitLabel}\n";
        $successMsg .= "Client: {$clientName}";
    }
    
    header("Location: ../forms/fg_delivery_entry.php?success=" . urlencode($successMsg));
    exit();
    
} catch (Exception $e) {
    error_log("FG Delivery Error: " . $e->getMessage());
    header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('System error: ' . $e->getMessage()));
    exit();
}
?>

