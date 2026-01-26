<?php
/**
 * One-off debug: show why "remaining" might be 0 for a batch.
 * Usage: open in browser with ?batch=CW-01 or ?batch=CW-01&bag_size=1000mmx700mm
 * Or run: php forms/api/debug_branding_remaining.php (no params = show all branding for recent batches)
 */
session_start();
require_once __DIR__ . '/../../config/security_config.php';
header('Content-Type: application/json; charset=utf-8');

$conn = SecurityConfig::getConnection();
if (!$conn) {
    echo json_encode(['error' => 'No DB connection'], JSON_PRETTY_PRINT);
    exit;
}

$batch = trim($_GET['batch'] ?? '');
$bagSize = trim($_GET['bag_size'] ?? '');

$brandingHasBagSize = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'bag_size'")->num_rows > 0;

$where = "1=1";
$params = [];
$types = "";
if ($batch !== '') {
    $where .= " AND TRIM(COALESCE(cnc_cutting_batch,'')) = ?";
    $params[] = $batch;
    $types .= "s";
}
if ($bagSize !== '' && $brandingHasBagSize) {
    $where .= " AND TRIM(COALESCE(bag_size,'')) = ?";
    $params[] = $bagSize;
    $types .= "s";
}

$sql = "SELECT id, branding_id, cnc_cutting_batch, bag_size, print_qty, ncp_piece, (COALESCE(print_qty,0) + COALESCE(ncp_piece,0)) as used FROM branding_entries WHERE $where ORDER BY id DESC LIMIT 50";
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
$totalUsed = 0;
while ($r = $res->fetch_assoc()) {
    $rows[] = $r;
    $totalUsed += (int)($r['used'] ?? 0);
}
$stmt->close();

echo json_encode([
    'batch_filter' => $batch ?: '(all)',
    'bag_size_filter' => $bagSize ?: '(any)',
    'branding_entries_has_bag_size_column' => $brandingHasBagSize,
    'rows_count' => count($rows),
    'total_branding_used' => $totalUsed,
    'rows' => $rows,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
