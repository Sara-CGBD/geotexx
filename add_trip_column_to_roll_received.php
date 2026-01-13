<?php
/**
 * Script to add trip column to roll_received table
 * Run this file directly in your browser or via command line
 */
require_once 'config/security_config.php';

$conn = SecurityConfig::getConnection();

echo "<h2>Adding trip column to roll_received table</h2>";
echo "<pre>";

// Check if column exists
$check = $conn->query("SHOW COLUMNS FROM roll_received LIKE 'trip'");

if ($check && $check->num_rows > 0) {
    echo "✓ Trip column already exists in roll_received table.\n";
    $col = $check->fetch_assoc();
    echo "Column details:\n";
    print_r($col);
} else {
    echo "✗ Trip column does NOT exist. Adding it now...\n\n";
    
    // Try to add the column
    $result = $conn->query("ALTER TABLE roll_received ADD COLUMN trip INT DEFAULT NULL");
    
    if ($result) {
        echo "✓ SUCCESS: Trip column added to roll_received table.\n\n";
        
        // Verify it was added
        $verify = $conn->query("SHOW COLUMNS FROM roll_received LIKE 'trip'");
        if ($verify && $verify->num_rows > 0) {
            $col = $verify->fetch_assoc();
            echo "✓ Verified: Column added successfully.\n";
            echo "Column details:\n";
            print_r($col);
            echo "\n✓ You can now refresh phpMyAdmin to see the trip column.\n";
        } else {
            echo "⚠ WARNING: Column was not found after adding. Please check manually.\n";
        }
    } else {
        echo "✗ ERROR: Failed to add trip column.\n";
        echo "MySQL Error: " . $conn->error . "\n";
        echo "Error Code: " . $conn->errno . "\n";
    }
}

echo "</pre>";
echo "<p><a href='forms/roll_received_entry.php'>Go to Roll Received Entry Form</a></p>";

$conn->close();
?>

