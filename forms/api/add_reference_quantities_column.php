<?php
// Script to manually add the reference_quantities column
session_start();
require_once '../../config/security_config.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

$conn = SecurityConfig::getConnection();

// Check if column exists
$colCheck = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'reference_quantities'");

if ($colCheck && $colCheck->num_rows > 0) {
    echo "<p style='color:green;'>✓ Column 'reference_quantities' already exists!</p>";
} else {
    // Add the column
    $sql = "ALTER TABLE cnc_entries ADD COLUMN reference_quantities TEXT NULL AFTER bag_size";
    
    if ($conn->query($sql)) {
        echo "<p style='color:green;'>✓ Successfully added 'reference_quantities' column to cnc_entries table!</p>";
    } else {
        echo "<p style='color:red;'>✗ Error adding column: " . htmlspecialchars($conn->error) . "</p>";
    }
}

// Verify it exists now
$colCheck2 = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'reference_quantities'");
if ($colCheck2 && $colCheck2->num_rows > 0) {
    $colInfo = $colCheck2->fetch_assoc();
    echo "<p><strong>Column Details:</strong></p>";
    echo "<pre>" . print_r($colInfo, true) . "</pre>";
} else {
    echo "<p style='color:red;'>✗ Column still doesn't exist after attempt to create it!</p>";
}

$conn->close();
?>
