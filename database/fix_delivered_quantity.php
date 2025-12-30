<?php
// Database fix script - Add delivered_quantity column to fg_entry table
session_start();
require_once '../config/security_config.php';

echo "<h2>Database Fix Script - Add delivered_quantity Column</h2>";
echo "<pre>";

$conn = SecurityConfig::getConnection();

// Check if fg_entry table exists
$tableCheck = $conn->query("SHOW TABLES LIKE 'fg_entry'");
if ($tableCheck->num_rows == 0) {
    echo "❌ ERROR: fg_entry table does not exist!\n";
    exit;
}
echo "✅ fg_entry table exists\n\n";

// Check if delivered_quantity column exists
$columnCheck = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'delivered_quantity'");

if ($columnCheck->num_rows > 0) {
    echo "✅ Column 'delivered_quantity' already exists!\n";
    
    // Show sample data
    $sampleData = $conn->query("SELECT reference_number, passed_qty, delivered_quantity, 
                                 (passed_qty - COALESCE(delivered_quantity, 0)) as remaining_qty 
                                 FROM fg_entry 
                                 WHERE reference_number IS NOT NULL 
                                 LIMIT 5");
    
    if ($sampleData && $sampleData->num_rows > 0) {
        echo "\n📊 Sample Data:\n";
        echo str_pad("Reference", 40) . str_pad("Passed", 12) . str_pad("Delivered", 12) . "Remaining\n";
        echo str_repeat("-", 76) . "\n";
        while ($row = $sampleData->fetch_assoc()) {
            echo str_pad($row['reference_number'], 40) . 
                 str_pad($row['passed_qty'], 12) . 
                 str_pad($row['delivered_quantity'], 12) . 
                 $row['remaining_qty'] . "\n";
        }
    }
} else {
    echo "⚠️  Column 'delivered_quantity' does NOT exist. Creating it now...\n";
    
    // Add the column
    $alterQuery = "ALTER TABLE fg_entry ADD COLUMN delivered_quantity DECIMAL(10,2) DEFAULT 0 AFTER batch_number";
    
    if ($conn->query($alterQuery)) {
        echo "✅ Column 'delivered_quantity' created successfully!\n";
        
        // Verify it was created
        $verifyCheck = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'delivered_quantity'");
        if ($verifyCheck->num_rows > 0) {
            echo "✅ Verified: Column exists in database\n";
        } else {
            echo "❌ ERROR: Column creation failed verification\n";
        }
    } else {
        echo "❌ ERROR creating column: " . $conn->error . "\n";
    }
}

// Check roll_entry_type column
echo "\n";
$rollTypeCheck = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'roll_entry_type'");
if ($rollTypeCheck->num_rows == 0) {
    echo "⚠️  Column 'roll_entry_type' does NOT exist. Creating it now...\n";
    $conn->query("ALTER TABLE fg_entry ADD COLUMN roll_entry_type VARCHAR(20) AFTER product_type");
    echo "✅ Column 'roll_entry_type' created successfully!\n";
} else {
    echo "✅ Column 'roll_entry_type' already exists\n";
}

// Show all columns in fg_entry
echo "\n📋 All columns in fg_entry table:\n";
$allColumns = $conn->query("SHOW COLUMNS FROM fg_entry");
while ($col = $allColumns->fetch_assoc()) {
    echo "  • {$col['Field']} ({$col['Type']})\n";
}

echo "\n</pre>";
echo "<p><strong>✅ Database fix complete!</strong></p>";
echo "<p><a href='../forms/fg_delivery_entry.php' style='padding:10px 20px; background:#27ae60; color:white; text-decoration:none; border-radius:5px;'>Go to FG Delivery Form</a></p>";

$conn->close();
?>


