<?php
/**
 * Add branding_entries columns for grouped sewing: sewing_entry_ids, status.
 * Run once: php database/add_branding_grouped_columns.php
 */
require_once __DIR__ . '/../config/security_config.php';

$conn = SecurityConfig::getConnection();
if (!$conn) {
    die("Database connection failed.\n");
}

$conn->query("SET @db = DATABASE()");

// Only add columns; do not create table (handler creates branding_entries with full structure)
$tableCheck = $conn->query("SHOW TABLES LIKE 'branding_entries'");
if (!$tableCheck || $tableCheck->num_rows === 0) {
    die("branding_entries table does not exist. Submit a branding entry once to create it, then run this script.\n");
}

function columnExists($conn, $table, $col) {
    $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($col) . "'");
    return $r && $r->num_rows > 0;
}

// Add sewing_entry_ids if missing
if (!columnExists($conn, 'branding_entries', 'sewing_entry_ids')) {
    $conn->query("ALTER TABLE branding_entries ADD COLUMN sewing_entry_ids TEXT NULL COMMENT 'Comma-separated sewing_machine_entry IDs'");
    echo "Added branding_entries.sewing_entry_ids.\n";
}

// Add status if missing
if (!columnExists($conn, 'branding_entries', 'status')) {
    $conn->query("ALTER TABLE branding_entries ADD COLUMN status VARCHAR(20) DEFAULT 'pending'");
    echo "Added branding_entries.status.\n";
}

echo "Done.\n";
