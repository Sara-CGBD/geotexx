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

// Build query to get distinct CNC cutting batches from cnc_entries
$isDeletedFilter = $hasIsDeleted ? "AND (ce.is_deleted = 0 OR ce.is_deleted IS NULL)" : "";

// Use date_time if available, otherwise created_at
$dateCol = $hasDateTime ? "MAX(ce.date_time)" : ($hasCreatedAt ? "MAX(ce.created_at)" : "NULL");
$orderCol = $hasDateTime ? "MAX(ce.date_time)" : ($hasCreatedAt ? "MAX(ce.created_at)" : "MAX(ce.cnc_cutting_batch)");

$query = "
    SELECT 
        ce.cnc_cutting_batch,
        {$dateCol} as batch_date,
        SUM(COALESCE(ce.cutting_roll_quantity, 0)) as total_cutting_quantity
    FROM cnc_entries ce
    WHERE ce.cnc_cutting_batch IS NOT NULL
      AND ce.cnc_cutting_batch != ''
      {$isDeletedFilter}
    GROUP BY ce.cnc_cutting_batch
    ORDER BY {$orderCol} DESC
    LIMIT 200
";

$result = $conn->query($query);
$batches = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Format the date for display
        $batchDate = '';
        if (!empty($row['batch_date'])) {
            $dateObj = new DateTime($row['batch_date']);
            $batchDate = $dateObj->format('Y-m-d'); // Format as YYYY-MM-DD
        }
        
        // Get bag_size and total_printed from branding_entries if available
        $bagSize = isset($bagSizeMap[$row['cnc_cutting_batch']]) ? $bagSizeMap[$row['cnc_cutting_batch']] : null;
        $totalPrinted = isset($totalPrintedMap[$row['cnc_cutting_batch']]) ? $totalPrintedMap[$row['cnc_cutting_batch']] : 0;
        
        $batches[] = [
            'cnc_cutting_batch' => $row['cnc_cutting_batch'],
            'batch_date' => $batchDate,
            'total_cutting_quantity' => (int)($row['total_cutting_quantity'] ?? 0),
            'bag_size' => $bagSize,
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
