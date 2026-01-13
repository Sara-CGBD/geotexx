<?php
// get_sewing_cnc_batches.php
// Returns distinct CNC cutting batches from sewing_machine_entry for branding entry dropdown

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

// Check which sewing table exists
$sewingTableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
$sewingTable = ($sewingTableCheck && $sewingTableCheck->num_rows > 0) ? 'sewing_machine_entry' : 'swing_machine_entry';

// Check the actual column name for cnc_cutting_batch in sewing table
$cncBatchColumn = null;
$colCheck = $conn->query("SHOW COLUMNS FROM $sewingTable");
if ($colCheck) {
    while ($col = $colCheck->fetch_assoc()) {
        $fieldName = $col['Field'];
        // Check for exact match or variations
        if ($fieldName === 'cnc_cutting_batch' || 
            strpos($fieldName, 'cnc_cutting') !== false) {
            $cncBatchColumn = $fieldName;
            break;
        }
    }
}

if (!$cncBatchColumn) {
    // Column doesn't exist, return empty
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'batches' => [],
        'debug' => 'Column cnc_cutting_batch not found in ' . $sewingTable
    ]);
    $conn->close();
    exit;
}

// Get used quantities per batch from branding entries
$used_quantities = [];
$brandingTableCheck = $conn->query("SHOW TABLES LIKE 'branding_entries'");
if ($brandingTableCheck && $brandingTableCheck->num_rows > 0) {
    $colCheck = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'cnc_cutting_batch'");
    if ($colCheck && $colCheck->num_rows > 0) {
        // Check if is_deleted column exists
        $isDeletedCheck = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'is_deleted'");
        $hasIsDeleted = ($isDeletedCheck && $isDeletedCheck->num_rows > 0);
        
        // Check if print_qty column exists
        $printQtyCheck = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'print_qty'");
        $hasPrintQty = ($printQtyCheck && $printQtyCheck->num_rows > 0);
        
        if ($hasPrintQty) {
            $used_query = "SELECT 
                           cnc_cutting_batch,
                           SUM(COALESCE(print_qty, 0)) as used_qty
                           FROM branding_entries 
                           WHERE cnc_cutting_batch IS NOT NULL 
                           AND cnc_cutting_batch != ''";
            
            // Only add is_deleted condition if the column exists
            if ($hasIsDeleted) {
                $used_query .= " AND (is_deleted = 0 OR is_deleted IS NULL)";
            }
            
            $used_query .= " GROUP BY cnc_cutting_batch";
            
            $used_result = $conn->query($used_query);
            if ($used_result) {
                while ($row = $used_result->fetch_assoc()) {
                    $used_quantities[$row['cnc_cutting_batch']] = (int)$row['used_qty'];
                }
            }
        }
    }
}

// Check if reference_number column exists in sewing table
$hasReferenceColumn = false;
$refColCheck = $conn->query("SHOW COLUMNS FROM $sewingTable");
if ($refColCheck) {
    while ($col = $refColCheck->fetch_assoc()) {
        if ($col['Field'] === 'reference_number' || strpos($col['Field'], 'reference') !== false) {
            $hasReferenceColumn = true;
            break;
        }
    }
}

// Fetch distinct CNC cutting batches from sewing machine entries
// Exclude batches that have already been used in branding entries
$batches = [];
$query = "SELECT 
    $cncBatchColumn as cnc_cutting_batch,
    COUNT(*) as entry_count,
    MIN(date_time) as first_entry_date,
    MAX(date_time) as last_entry_date,
    SUM(sewing_qty) as total_sewing_qty,
    SUM(COALESCE(ncp_piece, 0)) as total_ncp";

// Add reference numbers if column exists
if ($hasReferenceColumn) {
    $query .= ",
    GROUP_CONCAT(DISTINCT CASE 
        WHEN reference_number IS NOT NULL 
        AND reference_number != '' 
        AND reference_number != 'NULL' 
        THEN reference_number 
        ELSE NULL 
    END ORDER BY reference_number SEPARATOR ', ') as references_raw";
}

$query .= " FROM $sewingTable
WHERE $cncBatchColumn IS NOT NULL 
AND $cncBatchColumn != ''
GROUP BY $cncBatchColumn 
ORDER BY last_entry_date DESC, cnc_cutting_batch DESC
LIMIT 200";

$result = $conn->query($query);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $batch = $row['cnc_cutting_batch'];
        $total_qty = (int)$row['total_sewing_qty'];
        
        // Calculate used quantity for this batch
        $used_qty = isset($used_quantities[$batch]) ? $used_quantities[$batch] : 0;
        
        // Calculate remaining quantity
        $remaining_qty = $total_qty - $used_qty;
        
        // Only include batches with remaining quantity > 0
        if ($remaining_qty > 0) {
            // Process reference numbers
            $references = [];
            if ($hasReferenceColumn && !empty($row['references_raw'])) {
                $refs_raw = $row['references_raw'];
                // Split by comma and clean up
                $all_refs = explode(',', $refs_raw);
                foreach ($all_refs as $ref) {
                    $ref = trim($ref);
                    // Only add non-empty references that are not already in the array
                    if (!empty($ref) && $ref !== 'NULL' && strtoupper($ref) !== 'NULL' && !in_array($ref, $references)) {
                        $references[] = $ref;
                    }
                }
            }
            
            // If no references found but we have entries, try to get distinct references directly
            if (empty($references) && $hasReferenceColumn) {
                $ref_query = "SELECT DISTINCT reference_number 
                             FROM $sewingTable 
                             WHERE $cncBatchColumn = ? 
                             AND reference_number IS NOT NULL 
                             AND reference_number != '' 
                             AND reference_number != 'NULL'";
                $ref_stmt = $conn->prepare($ref_query);
                if ($ref_stmt) {
                    $ref_stmt->bind_param("s", $batch);
                    $ref_stmt->execute();
                    $ref_result = $ref_stmt->get_result();
                    while ($ref_row = $ref_result->fetch_assoc()) {
                        $ref_val = trim($ref_row['reference_number']);
                        if (!empty($ref_val) && !in_array($ref_val, $references)) {
                            $references[] = $ref_val;
                        }
                    }
                    $ref_stmt->close();
                }
            }
            
            $batches[] = [
                'batch' => $batch,
                'entry_count' => (int)$row['entry_count'],
                'first_entry_date' => $row['first_entry_date'],
                'last_entry_date' => $row['last_entry_date'],
                'total_sewing_qty' => $total_qty,
                'remaining_qty' => $remaining_qty,
                'used_qty' => $used_qty,
                'total_ncp' => (int)$row['total_ncp'],
                'references' => $references
            ];
        }
    }
} else {
    // Query failed, log error
    error_log("Query failed: " . $conn->error);
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'batches' => $batches,
    'debug_info' => [
        'table' => $sewingTable,
        'column' => $cncBatchColumn,
        'batch_count' => count($batches),
        'used_batches_count' => count($used_quantities)
    ]
]);

$conn->close();
?>
