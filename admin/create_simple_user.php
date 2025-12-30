<?php
// Create a simple user in the existing users table
$conn = new mysqli("localhost", "root", "root123", "geobagg");

if ($conn->connect_error) {
    echo "Connection failed: " . $conn->connect_error;
    exit;
}

// First, let's see what columns exist
$result = $conn->query("SHOW COLUMNS FROM users");
$columns = [];
while ($row = $result->fetch_assoc()) {
    $columns[] = $row['Field'];
}

echo "Available columns: " . implode(", ", $columns) . "\n";

// Create user with basic columns
$username = 'admin';
$password = password_hash('admin123', PASSWORD_DEFAULT);
$email = 'admin@test.com';

// Try to insert with common column names
$sql = "INSERT INTO users (username, password, email) VALUES (?, ?, ?)";
$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("sss", $username, $password, $email);
    if ($stmt->execute()) {
        echo "User created successfully!\n";
    } else {
        echo "Error: " . $stmt->error . "\n";
        
        // Try alternative column names
        $sql2 = "INSERT INTO users (username, password_hash, email) VALUES (?, ?, ?)";
        $stmt2 = $conn->prepare($sql2);
        if ($stmt2) {
            $stmt2->bind_param("sss", $username, $password, $email);
            if ($stmt2->execute()) {
                echo "User created with password_hash column!\n";
            } else {
                echo "Error with password_hash: " . $stmt2->error . "\n";
            }
        }
    }
} else {
    echo "Could not prepare statement: " . $conn->error . "\n";
}

$conn->close();
?>


