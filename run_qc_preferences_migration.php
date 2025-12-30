<?php
require_once 'config/security_config.php';

echo "<h2>Creating User QC Preferences Table...</h2>";

$conn = SecurityConfig::getConnection();

// Read and execute the SQL file
$sql = file_get_contents('database/add_user_qc_preferences.sql');

if ($conn->multi_query($sql)) {
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->next_result());
}

if ($conn->error) {
    echo "<p style='color:red;'>Error: " . $conn->error . "</p>";
} else {
    echo "<p style='color:green;'>✅ User QC Preferences table created successfully!</p>";
    
    // Verify table structure
    $result = $conn->query("DESCRIBE user_qc_preferences");
    if ($result) {
        echo "<h3>Table Structure:</h3>";
        echo "<table border='1' style='border-collapse:collapse; margin:20px 0;'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['Field']}</td>";
            echo "<td>{$row['Type']}</td>";
            echo "<td>{$row['Null']}</td>";
            echo "<td>{$row['Key']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
}

$conn->close();
echo "<p><a href='forms/qc_test_order.php'>Go to QC Test Order Form</a></p>";
?>


