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

// Query: group by cnc_cutting_batch (+ bag_size); use merged_print_qty when available, else SUM(print_qty) (max for Quality Checked in FG entry)
$selectList = "cnc_cutting_batch";
$groupBy = "GROUP BY cnc_cutting_batch";
if ($hasBagSize) {
    $selectList .= ", bag_size";
    $groupBy .= ", bag_size";
}
$selectList .= ", MIN($dateCol) as first_date";
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

$result = $conn->query("SELECT $selectList FROM branding_entries 
    WHERE cnc_cutting_batch IS NOT NULL AND cnc_cutting_batch != '' 
    $groupBy 
    ORDER BY first_date DESC 
    LIMIT 50");
$batches = [];

// Already submitted (quality_checked) per (cnc_cutting_batch, bag_size) from fg_entry - deduct from max
$usedByBatchBag = [];
if ($conn->query("SHOW TABLES LIKE 'fg_entry'")->num_rows > 0) {
    $feCols = [];
    $feR = $conn->query("SHOW COLUMNS FROM fg_entry");
    if ($feR) while ($feC = $feR->fetch_assoc()) $feCols[$feC['Field']] = true;
    if (!empty($feCols['product_type']) && !empty($feCols['cnc_cutting_batch']) && !empty($feCols['quality_checked'])) {
        $hasFgBagSize = !empty($feCols['bag_size']);
        $usedSelect = "TRIM(COALESCE(cnc_cutting_batch,'')) AS cnc_cutting_batch";
        $usedGroup = "GROUP BY TRIM(COALESCE(cnc_cutting_batch,''))";
        if ($hasFgBagSize) {
            $usedSelect .= ", TRIM(COALESCE(bag_size,'')) AS bag_size";
            $usedGroup .= ", TRIM(COALESCE(bag_size,''))";
        } else {
            $usedSelect .= ", '' AS bag_size";
        }
        $usedQuery = "SELECT $usedSelect, COALESCE(SUM(quality_checked), 0) AS used FROM fg_entry 
            WHERE product_type = 'bag' AND cnc_cutting_batch IS NOT NULL AND cnc_cutting_batch != '' 
            $usedGroup";
        $usedRes = $conn->query($usedQuery);
        if ($usedRes) {
            while ($ur = $usedRes->fetch_assoc()) {
                $kb = trim($ur['cnc_cutting_batch'] ?? '');
                $kbs = isset($ur['bag_size']) ? trim($ur['bag_size'] ?? '') : '';
                $key = $kb . '||' . $kbs;
                $usedByBatchBag[$key] = (int)($ur['used'] ?? 0);
            }
        }
    }
}

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $batch = trim($row['cnc_cutting_batch']);
        if ($batch === '') continue;
        $bagSize = $hasBagSize ? trim($row['bag_size'] ?? '') : '';
        $entryDate = $row['first_date'] ?? '';
        $batchDate = is_string($entryDate) && strlen($entryDate) >= 10 ? substr($entryDate, 0, 10) : $entryDate;
        $totalPrintQty = (int)($row['total_print_qty'] ?? 0);
        $key = $batch . '||' . $bagSize;
        $used = isset($usedByBatchBag[$key]) ? $usedByBatchBag[$key] : 0;
        $totalPrintQty = max(0, $totalPrintQty - $used);
        $totalNcp = (int)($row['total_ncp_piece'] ?? 0);
        $batches[] = [
            'batch' => $batch,
            'cnc_cutting_batch' => $batch,
            'bag_size' => $bagSize,
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
        'batches' => $batches
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
