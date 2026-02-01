<?php
/**
 * branding_entries: add merged_sewing_qty and merged_print_qty (tracking).
 * sewing_machine_entry: add merged_sewing_qty.
 * Run once: php database/add_merged_sewing_qty_columns.php
 * Or in browser: http://localhost/geotexx/database/add_merged_sewing_qty_columns.php
 */
require_once __DIR__ . '/../config/security_config.php';

$conn = SecurityConfig::getConnection();
if (!$conn) {
    die("Database connection failed.\n");
}

function columnExists($conn, $table, $col) {
    // Validate table and column names
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $col)) {
        return false;
    }
    // Use prepared statement for security
    $stmt = $conn->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    if ($stmt) {
        $stmt->bind_param("s", $col);
        $stmt->execute();
        $r = $stmt->get_result();
        $exists = $r && $r->num_rows > 0;
        $stmt->close();
        return $exists;
    }
    return false;
}

$done = [];

// branding_entries: merged_sewing_qty and merged_print_qty for tracking (same batch+bag_size)
if (!columnExists($conn, 'branding_entries', 'merged_sewing_qty')) {
    $conn->query("ALTER TABLE branding_entries ADD COLUMN merged_sewing_qty INT NULL");
    $done[] = "branding_entries.merged_sewing_qty";
}
if (!columnExists($conn, 'branding_entries', 'merged_print_qty')) {
    $conn->query("ALTER TABLE branding_entries ADD COLUMN merged_print_qty INT NULL");
    $done[] = "branding_entries.merged_print_qty";
}

// sewing table: merged_sewing_qty
$tables = ['sewing_machine_entry', 'swing_machine_entry'];
foreach ($tables as $tbl) {
    // Validate table name before using
    if (!preg_match('/^[A-Za-z0-9_]+$/', $tbl)) continue;
    
    // Use prepared statement for table check
    $existsStmt = $conn->prepare("SHOW TABLES LIKE ?");
    if ($existsStmt) {
        $existsStmt->bind_param("s", $tbl);
        $existsStmt->execute();
        $exists = $existsStmt->get_result();
        if (!$exists || $exists->num_rows === 0) {
            $existsStmt->close();
            continue;
        }
        $existsStmt->close();
    } else {
        continue;
    }
    
    if (!columnExists($conn, $tbl, 'merged_sewing_qty')) {
        // Table name is validated, safe to use directly
        $conn->query("ALTER TABLE `{$tbl}` ADD COLUMN merged_sewing_qty INT NULL");
        $done[] = "{$tbl}.merged_sewing_qty";
    }
}

if (count($done) > 0) {
    echo "Added columns: " . implode(", ", $done) . "\n";
} else {
    echo "branding_entries (merged_sewing_qty, merged_print_qty) and sewing merged_sewing_qty already exist where applicable.\n";
}
echo "Done.\n";
