<?php
// Script to create fiber_to_roll_entry table
require_once 'config/security_config.php';

try {
    $conn = SecurityConfig::getConnection();
    
    // Create fiber_to_roll_entry table
    $createTable = "CREATE TABLE IF NOT EXISTS fiber_to_roll_entry (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date_time DATETIME,
        operator_id INT,
        project_id INT,
        bale_opener_number VARCHAR(100),
        bale_number VARCHAR(100),
        bale_weight INT,
        gsm INT,
        line_no VARCHAR(50),
        fiber_type VARCHAR(100),
        origin VARCHAR(100),
        roll_number INT,
        total_weight DECIMAL(10,2),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    
    if ($conn->query($createTable)) {
        echo "✅ Table 'fiber_to_roll_entry' created successfully!<br>";
        echo "Table structure:<br>";
        echo "- id (Primary Key)<br>";
        echo "- date_time<br>";
        echo "- operator_id<br>";
        echo "- project_id<br>";
        echo "- bale_opener_number<br>";
        echo "- bale_number<br>";
        echo "- bale_weight<br>";
        echo "- gsm<br>";
        echo "- line_no<br>";
        echo "- fiber_type<br>";
        echo "- origin<br>";
        echo "- roll_number<br>";
        echo "- total_weight<br>";
        echo "- created_at<br>";
    } else {
        echo "❌ Error creating table: " . $conn->error;
    }
    
    // Show existing tables
    echo "<br><br>📋 Existing tables in database:<br>";
    $result = $conn->query("SHOW TABLES");
    while ($row = $result->fetch_array()) {
        echo "- " . $row[0] . "<br>";
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>

