<?php
// get_branding_cnc_batches_for_fg.php
// Returns distinct CNC cutting batches from branding_entries for FG entry dropdown

// Suppress errors and warnings to prevent JSON corruption
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

session_start();
require_once '../../config/security_config.php';

// Clean any output from require_once
ob_clean();

// Security check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Database connection
try {
    $conn = SecurityConfig::getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
    ob_end_flush();
    exit;
}

// Single SHOW COLUMNS (one round-trip)
$cols = [];
$r = $conn->query("SHOW COLUMNS FROM branding_entries");
if ($r) while ($c = $r->fetch_assoc()) $cols[$c['Field']] = true;
$dateCol = isset($cols['date_time']) ? 'date_time' : (isset($cols['created_at']) ? 'created_at' : 'id');
$hasBagSize = isset($cols['bag_size']);
$hasPrintQty = isset($cols['print_qty']);
$hasNcpPiece = isset($cols['ncp_piece']);
$hasMergedPrintQty = isset($cols['merged_print_qty']);
$hasIsDeleted = isset($cols['is_deleted']);
$hasProjectId = isset($cols['project_id']);

// Query: group by cnc_cutting_batch (+ bag_size); use merged_print_qty when available, else SUM(print_qty) (max for Quality Checked in FG entry)
$selectList = "cnc_cutting_batch";
$groupBy = "GROUP BY cnc_cutting_batch";
if ($hasBagSize) {
    $selectList .= ", bag_size";
    $groupBy .= ", bag_size";
}
$selectList .= ", MIN($dateCol) as first_date";
if ($hasProjectId) {
    $selectList .= ", MAX(project_id) as project_id";
} else {
    $selectList .= ", NULL as project_id";
}
if ($hasMergedPrintQty) {
    $selectList .= ", COALESCE(MAX(merged_print_qty), 0) as total_print_qty";
} elseif ($hasPrintQty) {
    $selectList .= ", COALESCE(SUM(print_qty), 0) as total_print_qty";
} else {
    $selectList .= ", 0 as total_print_qty";
}
if ($hasNcpPiece) {
    $selectList .= ", COALESCE(SUM(ncp_piece), 0) as total_ncp_piece";
} else {
    $selectList .= ", 0 as total_ncp_piece";
}

// Filter out deleted entries
$isDeletedFilter = $hasIsDeleted ? "AND (is_deleted = 0 OR is_deleted IS NULL)" : "";

// Check if fg_entry table exists and has cnc_cutting_batch column
$fgEntryExists = $conn->query("SHOW TABLES LIKE 'fg_entry'")->num_rows > 0;
$fgHasCncBatch = false;
$fgHasBagSize = false;
$fgHasIsDeleted = false;

if ($fgEntryExists) {
    $fgCols = [];
    $fgColsResult = $conn->query("SHOW COLUMNS FROM fg_entry");
    if ($fgColsResult) while ($c = $fgColsResult->fetch_assoc()) $fgCols[$c['Field']] = true;
    $fgHasCncBatch = isset($fgCols['cnc_cutting_batch']);
    $fgHasBagSize = isset($fgCols['bag_size']);
    $fgHasIsDeleted = isset($fgCols['is_deleted']);
}

// Get list of already submitted (cnc_cutting_batch, bag_size) combinations from fg_entry
$submittedBatches = [];
if ($fgEntryExists && $fgHasCncBatch) {
    $fgDeleteFilter = $fgHasIsDeleted ? "AND (is_deleted = 0 OR is_deleted IS NULL)" : "";
    $fgBagSizeSelect = $fgHasBagSize ? ", COALESCE(TRIM(bag_size), '') as bag_size" : ", '' as bag_size";
    
    $fgQuery = "SELECT TRIM(cnc_cutting_batch) as cnc_cutting_batch $fgBagSizeSelect 
                FROM fg_entry 
                WHERE cnc_cutting_batch IS NOT NULL AND TRIM(cnc_cutting_batch) != '' 
                $fgDeleteFilter";
    $fgResult = $conn->query($fgQuery);
    if ($fgResult) {
        while ($fgRow = $fgResult->fetch_assoc()) {
            // Normalize: lowercase + trim for consistent matching
            $fgBatch = strtolower(trim($fgRow['cnc_cutting_batch'] ?? ''));
            $fgBagSize = strtolower(trim($fgRow['bag_size'] ?? ''));
            if ($fgBatch === '') continue;
            
            // Create unique key for exact (batch, bag_size) match - normalized lowercase
            $key = $fgBatch . '||' . $fgBagSize;
            $submittedBatches[$key] = true;
        }
    }
}

$result = $conn->query("SELECT $selectList FROM branding_entries 
    WHERE cnc_cutting_batch IS NOT NULL AND cnc_cutting_batch != '' 
    $isDeletedFilter
    $groupBy 
    ORDER BY first_date DESC 
    LIMIT 100");
$batches = [];

// Filter out batches that have already been submitted to fg_entry

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $batch = trim($row['cnc_cutting_batch'] ?? '');
        if ($batch === '') continue;
        $bagSize = $hasBagSize ? trim($row['bag_size'] ?? '') : '';
        
        // Normalize key for matching: lowercase + trim (same as fg_entry lookup)
        $keyNormalized = strtolower($batch) . '||' . strtolower($bagSize);
        
        // Skip if this (batch, bag_size) combination was already submitted to fg_entry
        if (isset($submittedBatches[$keyNormalized])) {
            continue; // Already submitted, exclude from dropdown
        }
        
        $entryDate = $row['first_date'] ?? '';
        $batchDate = is_string($entryDate) && strlen($entryDate) >= 10 ? substr($entryDate, 0, 10) : $entryDate;
        $totalPrintQty = (int)($row['total_print_qty'] ?? 0);
        if ($totalPrintQty <= 0) continue;
        $totalNcp = (int)($row['total_ncp_piece'] ?? 0);
        $projId = ($hasProjectId && !empty($row['project_id'])) ? (int)$row['project_id'] : null;
        $batches[] = [
            'batch' => $batch,
            'cnc_cutting_batch' => $batch,
            'bag_size' => $bagSize,
            'project_id' => $projId,
            'batch_date' => $batchDate,
            'total_print_qty' => $totalPrintQty,
            'total_ncp_piece' => $totalNcp,
            'reference_numbers' => ''
        ];
    }
}

try {
    if (!$result) {
        throw new Exception('Query failed: ' . $conn->error);
    }

    // Clean output buffer before sending JSON
    ob_clean();
    header('Content-Type: application/json');
    
    echo json_encode([
        'success' => true,
        'batches' => $batches,
        'debug' => [
            'total_batches_found' => count($batches),
            'already_submitted_count' => count($submittedBatches),
            'has_bag_size_column' => $hasBagSize
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage(),
        'batches' => []
    ]);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
    ob_end_flush();
    exit;
}
?>
