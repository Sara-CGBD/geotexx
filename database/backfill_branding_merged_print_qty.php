<?php
/**
 * Backfill merged_print_qty in branding_entries for existing rows.
 * Sets merged_print_qty = SUM(print_qty) only for each (cnc_cutting_batch, bag_size) group.
 * Run once: php database/backfill_branding_merged_print_qty.php
 * Or in browser: http://localhost/geotexx/database/backfill_branding_merged_print_qty.php
 */
require_once __DIR__ . '/../config/security_config.php';

$conn = SecurityConfig::getConnection();
if (!$conn) {
    die("Database connection failed.\n");
}

$colCheck = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'merged_print_qty'");
if (!$colCheck || $colCheck->num_rows === 0) {
    die("Column branding_entries.merged_print_qty does not exist. Run add_merged_columns.sql or add_merged_sewing_qty_columns.php first.\n");
}

$hasIsDeleted = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'is_deleted'")->num_rows > 0;
$whereDel = $hasIsDeleted ? " AND (is_deleted = 0 OR is_deleted IS NULL)" : "";

$groupQuery = "SELECT TRIM(COALESCE(cnc_cutting_batch,'')) AS cnc_cutting_batch, TRIM(COALESCE(bag_size,'')) AS bag_size, SUM(COALESCE(print_qty,0)) AS total FROM branding_entries WHERE 1=1 $whereDel GROUP BY TRIM(COALESCE(cnc_cutting_batch,'')), TRIM(COALESCE(bag_size,''))";
$res = $conn->query($groupQuery);
if (!$res) {
    die("Query failed: " . $conn->error . "\n");
}

$updateWhere = "TRIM(COALESCE(cnc_cutting_batch,'')) = ? AND TRIM(COALESCE(bag_size,'')) = ?" . ($hasIsDeleted ? " AND (is_deleted = 0 OR is_deleted IS NULL)" : "");
$stmt = $conn->prepare("UPDATE branding_entries SET merged_print_qty = ? WHERE {$updateWhere}");
if (!$stmt) {
    die("Prepare failed: " . $conn->error . "\n");
}

$updated = 0;
while ($row = $res->fetch_assoc()) {
    $batch = $row['cnc_cutting_batch'] ?? '';
    $bagSize = $row['bag_size'] ?? '';
    $total = (int) $row['total'];
    $stmt->bind_param("iss", $total, $batch, $bagSize);
    $stmt->execute();
    $updated += $stmt->affected_rows;
}
$stmt->close();

echo "Backfilled merged_print_qty for $updated row(s) in branding_entries.\n";
echo "Done.\n";
