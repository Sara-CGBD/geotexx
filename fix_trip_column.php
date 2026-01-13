<?php
/**
 * Script to add trip column to roll_received table
 * Run this once to add the column if it doesn't exist
 */
require_once 'config/security_config.php';

$conn = SecurityConfig::getConnection();

echo "Checking if trip column exists in roll_received table...\n";

$check = $conn->query("SHOW COLUMNS FROM roll_received LIKE 'trip'");

if ($check && $check->num_rows > 0) {
    echo "✓ Trip column already exists in roll_received table.\n";
    $col = $check->fetch_assoc();
    echo "Column details:\n";
    print_r($col);
} else {
    echo "✗ Trip column does NOT exist. Adding it now...\n";
    $result = $conn->query("ALTER TABLE roll_received ADD COLUMN trip INT DEFAULT NULL");
    
    if ($result) {
        echo "✓ SUCCESS: Trip column added to roll_received table.\n";
        
        // Verify it was added
        $verify = $conn->query("SHOW COLUMNS FROM roll_received LIKE 'trip'");
        if ($verify && $verify->num_rows > 0) {
            $col = $verify->fetch_assoc();
            echo "✓ Verified: Column added successfully.\n";
            echo "Column details:\n";
            print_r($col);
        }
    } else {
        echo "✗ ERROR: Failed to add trip column.\n";
        echo "Error: " . $conn->error . "\n";
    }
}

$conn->close();
?>

