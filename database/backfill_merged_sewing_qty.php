<?php
/**
 * Backfill merged_sewing_qty in sewing_machine_entry.
 * For each row, set merged_sewing_qty = total sewing qty for the same cnc_cutting_batch + bag_size.
 * Run once: php database/backfill_merged_sewing_qty.php
 * Or in browser: http://localhost/geotexx/database/backfill_merged_sewing_qty.php
 */
require_once __DIR__ . '/../config/security_config.php';

$conn = SecurityConfig::getConnection();
if (!$conn) {
    die("Database connection failed.\n");
}

// Use same table name as rest of app
$sewingTableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
$sewingTable = ($sewingTableCheck && $sewingTableCheck->num_rows > 0) ? 'sewing_machine_entry' : 'swing_machine_entry';

$colCheck = $conn->query("SHOW COLUMNS FROM `$sewingTable` LIKE 'merged_sewing_qty'");
if (!$colCheck || $colCheck->num_rows === 0) {
    die("Column merged_sewing_qty does not exist in $sewingTable. Run add_merged_sewing_qty_columns.php first.\n");
}

$hasIsDeleted = $conn->query("SHOW COLUMNS FROM `$sewingTable` LIKE 'is_deleted'")->num_rows > 0;

// Get grouped totals: (cnc_cutting_batch, bag_size) -> SUM(sewing_qty)
$whereDeleted = $hasIsDeleted ? " AND (is_deleted = 0 OR is_deleted IS NULL)" : "";
$groupQuery = "SELECT cnc_cutting_batch, COALESCE(NULLIF(TRIM(bag_size), ''), '') AS bag_size, SUM(COALESCE(sewing_qty, 0)) AS total_sewing_qty FROM `$sewingTable` WHERE 1=1 $whereDeleted GROUP BY cnc_cutting_batch, COALESCE(NULLIF(TRIM(bag_size), ''), '')";
$res = $conn->query($groupQuery);
if (!$res) {
    die("Query failed: " . $conn->error . "\n");
}

$updated = 0;
$whereClause = "cnc_cutting_batch = ? AND COALESCE(NULLIF(TRIM(bag_size), ''), '') = ?" . ($hasIsDeleted ? " AND (is_deleted = 0 OR is_deleted IS NULL)" : "");
$stmt = $conn->prepare("UPDATE `$sewingTable` SET merged_sewing_qty = ? WHERE $whereClause");
if (!$stmt) {
    die("Prepare failed: " . $conn->error . "\n");
}
while ($row = $res->fetch_assoc()) {
    $batch = $row['cnc_cutting_batch'] ?? '';
    $bagSize = $row['bag_size'] ?? '';
    $total = (int) $row['total_sewing_qty'];
    $stmt->bind_param("iss", $total, $batch, $bagSize);
    $stmt->execute();
    $updated += $stmt->affected_rows;
}
$stmt->close();

echo "Backfilled merged_sewing_qty for $updated row(s) in $sewingTable.\n";
echo "Done.\n";
