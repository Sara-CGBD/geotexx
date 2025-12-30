<?php
session_start();
require_once '../config/security_config.php';

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$ok = $conn && !$conn->connect_error;
echo "Conn OK: " . ($ok ? 'yes' : 'no') . "\n";
if (!$ok) { echo "Conn error: " . ($conn ? $conn->connect_error : 'no conn') . "\n"; }

$dbRes = $conn->query("SELECT DATABASE() AS db");
$dbRow = $dbRes ? $dbRes->fetch_assoc() : null;
echo "DB: " . ($dbRow['db'] ?? 'unknown') . "\n";

$tbl = $conn->query("SHOW TABLES LIKE 'bom'");
echo "Has bom: " . (($tbl && $tbl->num_rows>0)?'yes':'no') . "\n";

$result = $conn->query("SELECT id, bag_size, unit_price, created_at FROM bom ORDER BY id ASC");
if (!$result) {
    echo "Query error: " . $conn->error . "\n";
    exit(1);
}

echo "Rows: " . $result->num_rows . "\n";
while ($row = $result->fetch_assoc()) {
    echo $row['id'] . "\t" . $row['bag_size'] . "\t" . $row['unit_price'] . "\t" . $row['created_at'] . "\n";
}

$conn->close();
?>



