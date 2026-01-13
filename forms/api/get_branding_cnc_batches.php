<?php
// get_branding_cnc_batches.php
// Returns distinct CNC cutting batches from branding_entries for FG delivery dropdown

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

// Check if branding_entries table exists
$tableCheck = $conn->query("SHOW TABLES LIKE 'branding_entries'");
if (!$tableCheck || $tableCheck->num_rows == 0) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'batches' => [],
        'message' => 'No branding entries found'
    ]);
    $conn->close();
    exit;
}

// Check if required columns exist
$cncBatchCheck = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'cnc_cutting_batch'");
$printQtyCheck = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'print_qty'");
$isDeletedCheck = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'is_deleted'");
$bagSizeCheck = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'bag_size'");
$projectIdCheck = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'project_id'");

$hasCncBatch = ($cncBatchCheck && $cncBatchCheck->num_rows > 0);
$hasPrintQty = ($printQtyCheck && $printQtyCheck->num_rows > 0);
$hasIsDeleted = ($isDeletedCheck && $isDeletedCheck->num_rows > 0);
$hasBagSize = ($bagSizeCheck && $bagSizeCheck->num_rows > 0);
$hasProjectId = ($projectIdCheck && $projectIdCheck->num_rows > 0);

if (!$hasCncBatch || !$hasPrintQty) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'batches' => [],
        'message' => 'Required columns not found in branding_entries'
    ]);
    $conn->close();
    exit;
}

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

// Fetch distinct CNC cutting batches from branding entries
// Calculate remaining quantity (print_qty - delivered_qty)
$batches = [];
$query = "SELECT 
    cnc_cutting_batch,
    COUNT(*) as entry_count,
    MIN(date_time) as first_entry_date,
    MAX(date_time) as last_entry_date,
    SUM(COALESCE(print_qty, 0)) as total_print_qty";

if ($hasBagSize) {
    $query .= ", MAX(bag_size) as bag_size";
}
if ($hasProjectId) {
    $query .= ", MAX(project_id) as project_id";
}

$query .= " FROM branding_entries
WHERE cnc_cutting_batch IS NOT NULL 
AND cnc_cutting_batch != ''";

if ($hasIsDeleted) {
    $query .= " AND (is_deleted = 0 OR is_deleted IS NULL)";
}

$query .= " GROUP BY cnc_cutting_batch 
ORDER BY last_entry_date DESC, cnc_cutting_batch DESC
LIMIT 200";

$result = $conn->query($query);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $batch = $row['cnc_cutting_batch'];
        $total_print_qty = (int)$row['total_print_qty'];
        
        // Calculate delivered quantity for this batch
        $delivered_qty = isset($delivered_quantities[$batch]) ? $delivered_quantities[$batch] : 0;
        
        // Calculate remaining quantity
        $remaining_qty = $total_print_qty - $delivered_qty;
        
        // Only include batches with remaining quantity > 0
        if ($remaining_qty > 0) {
            $batchData = [
                'batch' => $batch,
                'entry_count' => (int)$row['entry_count'],
                'first_entry_date' => $row['first_entry_date'],
                'last_entry_date' => $row['last_entry_date'],
                'total_print_qty' => $total_print_qty,
                'remaining_qty' => $remaining_qty,
                'delivered_qty' => $delivered_qty
            ];
            
            if ($hasBagSize) {
                $batchData['bag_size'] = $row['bag_size'];
            }
            if ($hasProjectId) {
                $batchData['project_id'] = $row['project_id'];
            }
            
            $batches[] = $batchData;
        }
    }
} else {
    error_log("Query failed: " . $conn->error);
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
