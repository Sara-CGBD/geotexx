<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Delete all old BOM entries
$result = $conn->query("DELETE FROM bom");

if ($result) {
    $affected = $conn->affected_rows;
    echo "<h1>✅ BOM Data Cleared</h1>";
    echo "<p>Successfully deleted $affected BOM entries.</p>";
} else {
    echo "<h1>❌ Error</h1>";
    echo "<p>Failed to delete BOM entries: " . $conn->error . "</p>";
}

echo "<p><a href='../forms/BOM_entry.php'>Go to BOM Entry</a></p>";

$conn->close();
?>


