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

// Check if is_deleted column exists in cnc_entries
$hasIsDeleted = false;
$checkIsDeleted = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'is_deleted'");
if ($checkIsDeleted && $checkIsDeleted->num_rows > 0) {
    $hasIsDeleted = true;
}

// Check if cnc_cutting_batch column exists in cnc_entries
$hasCncBatch = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'cnc_cutting_batch'")->num_rows > 0;
$hasCreatedAt = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'created_at'")->num_rows > 0;
$hasDateTime = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'date_time'")->num_rows > 0;

if (!$hasCncBatch) {
    ob_clean();
    echo json_encode([
        'success' => true, 
        'batches' => []
    ]);
    ob_end_flush();
    exit;
}

// Check if branding_entries table exists and has bag_size column
$hasBrandingTable = $conn->query("SHOW TABLES LIKE 'branding_entries'")->num_rows > 0;
$hasBrandingBagSize = false;
$hasBrandingIsDeleted = false;
$hasBrandingCncBatch = false;

if ($hasBrandingTable) {
    $hasBrandingBagSize = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'bag_size'")->num_rows > 0;
    $hasBrandingIsDeleted = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'is_deleted'")->num_rows > 0;
    $hasBrandingCncBatch = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'cnc_cutting_batch'")->num_rows > 0;
}

// Get bag_size and total_printed from branding_entries for each batch
$bagSizeMap = [];
$totalPrintedMap = [];
if ($hasBrandingTable && $hasBrandingCncBatch) {
    $hasBrandingPrintQty = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'print_qty'")->num_rows > 0;
    $brandingDeletedFilter = $hasBrandingIsDeleted ? "AND (be.is_deleted = 0 OR be.is_deleted IS NULL)" : "";
    
    $brandingQuery = "
        SELECT 
            be.cnc_cutting_batch";
    
    if ($hasBrandingBagSize) {
        $brandingQuery .= ",
            MAX(be.bag_size) as bag_size";
    }
    
    if ($hasBrandingPrintQty) {
        $brandingQuery .= ",
            SUM(COALESCE(be.print_qty, 0)) as total_printed";
    }
    
    $brandingQuery .= "
        FROM branding_entries be
        WHERE be.cnc_cutting_batch IS NOT NULL
          AND be.cnc_cutting_batch != ''
          {$brandingDeletedFilter}
        GROUP BY be.cnc_cutting_batch
    ";
    
    $brandingResult = $conn->query($brandingQuery);
    if ($brandingResult) {
        while ($brandingRow = $brandingResult->fetch_assoc()) {
            if ($hasBrandingBagSize && !empty($brandingRow['bag_size'])) {
                $bagSizeMap[$brandingRow['cnc_cutting_batch']] = $brandingRow['bag_size'];
            }
            if ($hasBrandingPrintQty) {
                $totalPrintedMap[$brandingRow['cnc_cutting_batch']] = (int)($brandingRow['total_printed'] ?? 0);
            }
        }
    }
}

// Fetch each CNC entry individually - NO grouping, NO merging
// Each row in cnc_entries is a separate entry with its own batch number, date, bag_size, and cutting_roll_quantity
$isDeletedFilter = $hasIsDeleted ? "AND (ce.is_deleted = 0 OR ce.is_deleted IS NULL)" : "";

// Use date_time if available, otherwise created_at
$dateColumn = $hasDateTime ? 'date_time' : ($hasCreatedAt ? 'created_at' : 'cnc_cutting_batch');

// Fetch each entry individually - NO GROUP BY, NO SUM - each row is separate
// Include remaining_qty and used_qty to show actual available quantity
$query = "
    SELECT 
        ce.id,
        ce.cnc_cutting_batch,
        DATE(ce.{$dateColumn}) as batch_date,
        ce.{$dateColumn} as entry_date_time,
        ce.bag_size,
        ce.cutting_roll_quantity,
        COALESCE(ce.used_qty, 0) as used_qty,
        COALESCE(ce.remaining_qty, GREATEST(0, ce.cutting_roll_quantity - COALESCE(ce.used_qty, 0))) as remaining_qty
    FROM cnc_entries ce
    WHERE ce.cnc_cutting_batch IS NOT NULL
      AND ce.cnc_cutting_batch != ''
      AND ce.bag_size IS NOT NULL
      AND ce.bag_size != ''
      AND ce.cutting_roll_quantity IS NOT NULL
      AND ce.cutting_roll_quantity > 0
      {$isDeletedFilter}
    ORDER BY ce.{$dateColumn} DESC, ce.id DESC
    LIMIT 200
";

$result = $conn->query($query);
$batches = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Get exact values from this specific row - NO merging, NO grouping
        $batch = trim($row['cnc_cutting_batch'] ?? '');
        $bagSize = trim($row['bag_size'] ?? '');
        $total_cutting_qty = (int)($row['cutting_roll_quantity'] ?? 0);
        $entryId = (int)($row['id'] ?? 0);
        
        // Skip if essential data is missing
        if (empty($batch) || empty($bagSize) || $total_cutting_qty <= 0) {
            continue;
        }
        
        // Format the date for display
        $batchDate = '';
        if (!empty($row['batch_date'])) {
            $dateObj = new DateTime($row['batch_date']);
            $batchDate = $dateObj->format('Y-m-d'); // Format as YYYY-MM-DD
        }
        
        // Get total_printed from branding_entries if available (for this specific batch)
        $totalPrinted = isset($totalPrintedMap[$batch]) ? $totalPrintedMap[$batch] : 0;
        
        // Get used_qty and remaining_qty from database (stored in cnc_entries)
        $used_qty = (int)($row['used_qty'] ?? 0);
        $remaining_qty = (int)($row['remaining_qty'] ?? 0);
        
        // If remaining_qty is 0 or negative but should have a value, recalculate
        if ($remaining_qty <= 0 && $total_cutting_qty > $used_qty) {
            $remaining_qty = max(0, $total_cutting_qty - $used_qty);
            
            // Update the database with calculated remaining_qty for future use
            if ($entryId > 0) {
                $update_remaining = $conn->prepare("UPDATE cnc_entries SET remaining_qty = ? WHERE id = ?");
                if ($update_remaining) {
                    $update_remaining->bind_param("ii", $remaining_qty, $entryId);
                    $update_remaining->execute();
                    $update_remaining->close();
                }
            }
        }
        
        // CRITICAL: Skip batches that have no remaining quantity (maxed out)
        // Only show batches where remaining_qty > 0 (has available quantity)
        if ($remaining_qty <= 0) {
            continue; // Skip this batch - it's maxed out
        }
        
        // Add this entry directly to batches array - NO intermediate storage, NO merging
        // Each entry is completely separate, even if batch number is the same
        $batches[] = [
            'id' => $entryId, // Include ID to make each entry unique
            'cnc_cutting_batch' => $batch,  // Exact cnc_cutting_batch from this specific row
            'batch' => $batch, // Alias for backward compatibility
            'batch_date' => $batchDate, // Date from this specific row
            'total_cutting_quantity' => $total_cutting_qty, // Exact cutting_roll_quantity from THIS row only
            'total_cutting_qty' => $total_cutting_qty, // Alias
            'remaining_qty' => $remaining_qty, // Actual remaining quantity (for max display)
            'used_qty' => $used_qty, // Used quantity (for reference)
            'bag_size' => $bagSize, // Exact bag_size from THIS row only
            'total_printed' => $totalPrinted
        ];
    }
}

// Ensure no output before JSON
ob_clean();

echo json_encode([
    'success' => true, 
    'batches' => $batches
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// End output buffering
ob_end_flush();
exit;
?>
