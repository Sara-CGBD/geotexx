<?php
// get_branding_cnc_batches.php
// Returns distinct CNC cutting batches from fg_received_entry for FG delivery dropdown (bag deliveries only)

session_start();
require_once '../../config/security_config.php';

// Security check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Database connection
$conn = SecurityConfig::getConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Note: This API now fetches ONLY from fg_received_entry for bag deliveries
// Removed dependency on branding_entries table

// Get delivered quantities per batch from fg_deliveries
$delivered_quantities = [];
$fgDeliveriesTableCheck = $conn->query("SHOW TABLES LIKE 'fg_deliveries'");
if ($fgDeliveriesTableCheck && $fgDeliveriesTableCheck->num_rows > 0) {
    // Check if fg_deliveries has cnc_cutting_batch column
    $fgDelCncCheck = $conn->query("SHOW COLUMNS FROM fg_deliveries LIKE 'cnc_cutting_batch'");
    if ($fgDelCncCheck && $fgDelCncCheck->num_rows > 0) {
        // Check for delivery_quantity or delivery_qty column
        $fgDelQtyCheck = $conn->query("SHOW COLUMNS FROM fg_deliveries LIKE 'delivery_quantity'");
        $hasDeliveryQty = ($fgDelQtyCheck && $fgDelQtyCheck->num_rows > 0);
        if (!$hasDeliveryQty) {
            $fgDelQtyCheck = $conn->query("SHOW COLUMNS FROM fg_deliveries LIKE 'delivery_qty'");
            $hasDeliveryQty = ($fgDelQtyCheck && $fgDelQtyCheck->num_rows > 0);
        }
        
        if ($hasDeliveryQty) {
            $delQtyCol = $fgDelQtyCheck->fetch_assoc()['Field'];
            $delivered_query = "SELECT 
                               cnc_cutting_batch,
                               SUM(COALESCE($delQtyCol, 0)) as delivered_qty
                               FROM fg_deliveries 
                               WHERE cnc_cutting_batch IS NOT NULL 
                               AND cnc_cutting_batch != ''";
            
            $delIsDeletedCheck = $conn->query("SHOW COLUMNS FROM fg_deliveries LIKE 'is_deleted'");
            if ($delIsDeletedCheck && $delIsDeletedCheck->num_rows > 0) {
                $delivered_query .= " AND (is_deleted = 0 OR is_deleted IS NULL)";
            }
            
            $delivered_query .= " GROUP BY cnc_cutting_batch";
            
            $delivered_result = $conn->query($delivered_query);
            if ($delivered_result) {
                while ($row = $delivered_result->fetch_assoc()) {
                    $delivered_quantities[$row['cnc_cutting_batch']] = (int)$row['delivered_qty'];
                }
            }
        }
    }
}

// Fetch CNC batches ONLY from FG Received entries (for bag deliveries)
$batches = [];
$fgReceivedCheck = $conn->query("SHOW TABLES LIKE 'fg_received_entry'");
if ($fgReceivedCheck && $fgReceivedCheck->num_rows > 0) {
    // Check if required columns exist in fg_received_entry
    $fgCncCheck = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'cnc_cutting_batch'");
    $fgReceivedQtyCheck = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'received_quantity'");
    $fgProductTypeCheck = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'product_type'");
    $fgIsDeletedCheck = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'is_deleted'");
    $fgBagSizeCheck = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'bag_size'");
    $fgProjectIdCheck = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'project_id'");
    
    $hasFgCnc = ($fgCncCheck && $fgCncCheck->num_rows > 0);
    $hasFgReceivedQty = ($fgReceivedQtyCheck && $fgReceivedQtyCheck->num_rows > 0);
    $hasFgProductType = ($fgProductTypeCheck && $fgProductTypeCheck->num_rows > 0);
    $hasFgIsDeleted = ($fgIsDeletedCheck && $fgIsDeletedCheck->num_rows > 0);
    $hasFgBagSize = ($fgBagSizeCheck && $fgBagSizeCheck->num_rows > 0);
    $hasFgProjectId = ($fgProjectIdCheck && $fgProjectIdCheck->num_rows > 0);
    
    if ($hasFgCnc && $hasFgReceivedQty) {
        $fgQuery = "
            SELECT 
                TRIM(cnc_cutting_batch) as cnc_cutting_batch,
                SUM(COALESCE(received_quantity, 0)) as total_received";
        
        if ($hasFgBagSize) {
            $fgQuery .= ", MAX(bag_size) as bag_size";
        }
        if ($hasFgProjectId) {
            $fgQuery .= ", MAX(project_id) as project_id";
        }
        
        $fgQuery .= "
            FROM fg_received_entry
            WHERE cnc_cutting_batch IS NOT NULL
              AND cnc_cutting_batch != ''";
        
        if ($hasFgProductType) {
            $fgQuery .= " AND product_type = 'bag'";
        }
        
        if ($hasFgIsDeleted) {
            $fgQuery .= " AND (is_deleted = 0 OR is_deleted IS NULL)";
        }
        
        $fgQuery .= "
            GROUP BY cnc_cutting_batch
            HAVING total_received > 0
            ORDER BY cnc_cutting_batch DESC
            LIMIT 200";

        $fgResult = $conn->query($fgQuery);
        if ($fgResult) {
            while ($row = $fgResult->fetch_assoc()) {
                $batch = trim($row['cnc_cutting_batch']);
                if ($batch === '') continue;

                $totalReceived = (int)$row['total_received'];
                $delivered_qty = $delivered_quantities[$batch] ?? 0;
                $remaining_qty = $totalReceived - $delivered_qty;

                // Only include batches with remaining quantity > 0
                if ($remaining_qty > 0) {
                    $batchData = [
                        'batch' => $batch,
                        'total_print_qty' => $totalReceived,
                        'remaining_qty' => $remaining_qty,
                        'delivered_qty' => $delivered_qty
                    ];
                    
                    if ($hasFgProjectId && isset($row['project_id'])) {
                        $batchData['project_id'] = $row['project_id'];
                    }
                    
                    if ($hasFgBagSize && isset($row['bag_size'])) {
                        $batchData['bag_size'] = $row['bag_size'];
                    }

                    $batches[] = $batchData;
                }
            }
        } else {
            error_log("FG received query failed: " . $conn->error);
        }
    } else {
        error_log("Required columns not found in fg_received_entry table");
    }
} else {
    error_log("fg_received_entry table does not exist");
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'batches' => $batches,
    'debug_info' => [
        'batch_count' => count($batches),
        'delivered_batches_count' => count($delivered_quantities)
    ]
]);

$conn->close();
?>
