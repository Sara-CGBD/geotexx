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

try {
    // Get form data
    $deliveryId = $_POST['delivery_id'] ?? '';
    $fgEntryId = $_POST['fg_entry_id'] ?? '';
    $referenceNumber = $_POST['reference_number'] ?? '';
    $bagSize = $_POST['bag_size'] ?? '';
    $packagingType = $_POST['packaging_type'] ?? '';
    $deliveryQty = (float)($_POST['delivery_qty'] ?? 0);
    $deliveryProductType = $_POST['delivery_product_type'] ?? 'bag'; // roll or bag
    
    // CNC cutting batch is only for bags, not for rolls
    $cncCuttingBatch = '';
    if ($deliveryProductType === 'bag') {
        $cncCuttingBatch = $_POST['cnc_cutting_batch'] ?? '';
    }
    $deliveryRollEntryType = $_POST['delivery_roll_entry_type'] ?? ''; // individual or bundle
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
        
        // Set reference_number to empty for bags (not used)
        $referenceNumber = '';
        
        // For bags, we don't need fg_entry_id, set to NULL
        if (empty($fgEntryId)) {
            $fgEntryId = null;
        }
        
    } else {
        // For rolls, use existing logic with reference_number
        $checkStmt = $conn->prepare("SELECT 
                                        fe.id,
                                        fe.passed_qty, 
                                        fe.actual_weight,
                                        fe.product_type,
                                        COALESCE(SUM(fd.delivery_quantity), 0) as delivered_quantity,
                                        CASE 
                                            WHEN fe.product_type = 'roll' THEN (fe.actual_weight - COALESCE(SUM(fd.delivery_quantity), 0))
                                            ELSE (fe.passed_qty - COALESCE(SUM(fd.delivery_quantity), 0))
                                        END as remaining_quantity
                                      FROM fg_entry fe
                                      LEFT JOIN fg_deliveries fd ON fe.id = fd.fg_entry_id
                                      WHERE fe.reference_number = ?
                                      GROUP BY fe.id, fe.passed_qty, fe.actual_weight, fe.product_type");
        $checkStmt->bind_param('s', $referenceNumber);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        
        if ($result->num_rows === 0) {
            header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('No FG Entry found for this reference!\n\nSolution:\n1. Check FG Entry form - ensure this reference exists\n2. If no FG Entry exists, create one first'));
            $checkStmt->close();
            exit();
        }
        
        $fgData = $result->fetch_assoc();
        $checkStmt->close();
        
        // Use the ID from the query if not provided in form
        if (empty($fgEntryId)) {
            $fgEntryId = $fgData['id'];
        }
        
        $passedQty = (float)$fgData['passed_qty'];
        $actualWeight = (float)$fgData['actual_weight'];
        $productType = $fgData['product_type'];
        $deliveredQty = (float)$fgData['delivered_quantity'];
        $remainingQty = (float)$fgData['remaining_quantity'];
    }
    
    // Check if there's any stock available
    if ($remainingQty <= 0) {
        if ($productType === 'roll' && $actualWeight <= 0) {
            header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('No stock available for delivery!\n\nReason:\n• The FG Entry for this reference has 0 Actual Weight, OR\n• All stock has already been delivered\n\nSolution:\n1. Check FG Entry form - ensure Actual Weight > 0\n2. Verify delivery history for this reference'));
            exit();
        } else if ($passedQty <= 0) {
            header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('No stock available for delivery!\n\nReason:\n• The FG Entry for this reference has 0 Passed Quantity, OR\n• All stock has already been delivered\n\nSolution:\n1. Check FG Entry form - ensure Passed Quantity > 0\n2. Verify delivery history for this reference'));
            exit();
        } else {
            header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('All stock has already been delivered for this reference!\n\nPassed Qty: ' . $passedQty . '\nDelivered: ' . $deliveredQty . '\nRemaining: 0'));
            exit();
        }
    }
    
    // Prevent delivery quantity exceeding available stock
    if ($deliveryQty > $remainingQty) {
        $unit = ($deliveryProductType === 'roll') ? 'kg' : 'pcs';
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
    
    // Insert delivery record
    // Parameters breakdown:
    // 1. delivery_id - string (s)
    // 2. fg_entry_id - integer (i)
    // 3. reference_number - string (s)
    // 4. cnc_cutting_batch - string (s)
    // 5. delivery_date - string (s)
    // 6. shift - string (s)
    // 7. challan_no - string (s)
    // 8. truck_no - string (s)
    // 9. destination - string (s)
    // 10. bag_size - string (s)
    // 11. packaging_type - string (s)
    // 12. delivery_quantity - decimal (d)
    // 13. delivery_product_type - string (s)
    // 14. delivery_roll_entry_type - string (s)
    // 15. client_id - integer (i)
    // 16. client_name - string (s)
    // 17. unit_price - decimal (d)
    // 18. total_cost - decimal (d)
    // 19. remarks - string (s)
    // 20. delivered_by - string (s)
    // 21. delivered_at - string (s)
    // Type string: "sisssssssssdssisddsss" (21 characters)
    
    $stmt = $conn->prepare("INSERT INTO fg_deliveries 
        (delivery_id, fg_entry_id, reference_number, cnc_cutting_batch, delivery_date, shift, 
         challan_no, truck_no, destination, bag_size, packaging_type, delivery_quantity, 
         delivery_product_type, delivery_roll_entry_type, client_id, client_name, 
         unit_price, total_cost, remarks, delivered_by, delivered_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Handle nullable fg_entry_id for bags - use 0 for bags, actual ID for rolls
    // MySQL will treat 0 as NULL if column allows NULL, or we can use actual NULL
    $fgEntryIdValue = ($deliveryProductType === 'bag' && (empty($fgEntryId) || $fgEntryId == 0)) ? null : (int)$fgEntryId;
    
    // For NULL values in mysqli, we need to use a different approach
    // Convert NULL to 0 for binding, then update after if needed
    $fgEntryIdForBind = ($fgEntryIdValue === null) ? 0 : (int)$fgEntryIdValue;
    
    $stmt->bind_param(
        'sisssssssssdssisddsss',  // Type string: 21 characters
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
        $deliveryProductType,     // 13. s - delivery_product_type
        $deliveryRollEntryType,   // 14. s - delivery_roll_entry_type
        $clientId,                // 15. i - client_id
        $clientName,              // 16. s - client_name
        $unitPrice,               // 17. d - unit_price
        $totalCost,               // 18. d - total_cost
        $remarks,                 // 19. s - remarks
        $deliveredBy,             // 20. s - delivered_by
        $deliveredAt              // 21. s - delivered_at
    );
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to insert delivery record: ' . $stmt->error);
    }
    
    // Get insert ID before closing statement
    $insertId = $conn->insert_id;
    $stmt->close();
    
    // If fg_entry_id should be NULL for bags, update it after insert
    if ($fgEntryIdValue === null && $insertId) {
        $conn->query("UPDATE fg_deliveries SET fg_entry_id = NULL WHERE id = $insertId");
    }
    
    // Update fg_entry: recalculate delivered_quantity from actual deliveries to ensure accuracy
    // Only for rolls (bags don't use fg_entry_id)
    if ($deliveryProductType === 'roll' && !empty($fgEntryId) && $fgEntryId > 0) {
        $updateStmt = $conn->prepare("UPDATE fg_entry fe
                                       SET delivered_quantity = (
                                           SELECT COALESCE(SUM(fd.delivery_quantity), 0)
                                           FROM fg_deliveries fd
                                           WHERE fd.fg_entry_id = fe.id
                                       )
                                       WHERE fe.id = ?");
        $updateStmt->bind_param('i', $fgEntryId);
        
        if (!$updateStmt->execute()) {
            throw new Exception('Failed to update delivered quantity: ' . $updateStmt->error);
        }
        $updateStmt->close();
        
        // Check if fully delivered and update status
        $newRemaining = $remainingQty - $deliveryQty;
        if ($newRemaining <= 0) {
            $conn->query("UPDATE fg_entry SET status = 'Fully Delivered' WHERE id = $fgEntryId");
        }
    } else {
        // For bags, calculate new remaining
        $newRemaining = $remainingQty - $deliveryQty;
    }
    
    $conn->close();
    
    // Success message with detailed information
    $unitLabel = ($deliveryProductType === 'roll') ? 'kg' : 'pcs';
    $productLabel = ($deliveryProductType === 'roll') ? 'Rolls' : 'Bags';
    
    $successMsg = " {$deliveryQty} {$productLabel} delivered successfully!\n\n";
    $successMsg .= "Delivery ID: {$deliveryId}\n";
    if ($deliveryProductType === 'roll') {
        $successMsg .= "Reference: {$referenceNumber}\n";
    } else {
        $successMsg .= "CNC Batch: {$cncCuttingBatch}\n";
    }
    $successMsg .= "Quantity: {$deliveryQty} {$unitLabel}\n";
    $successMsg .= "Remaining Stock: {$newRemaining} {$unitLabel}\n";
    $successMsg .= "Client: {$clientName}";
    
    header("Location: ../forms/fg_delivery_entry.php?success=" . urlencode($successMsg));
    exit();
    
} catch (Exception $e) {
    error_log("FG Delivery Error: " . $e->getMessage());
    header("Location: ../forms/fg_delivery_entry.php?error=" . urlencode('System error: ' . $e->getMessage()));
    exit();
}
?>

