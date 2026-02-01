<?php
// Add user to the correct 'users' table
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Adding User to 'users' Table</h2>";

// Database connection
$conn = new mysqli("127.0.0.1", "root", "root123", "geobagg", 3307);

if ($conn->connect_error) {
    echo "âŒ Database connection failed: " . $conn->connect_error;
    exit;
}

echo "âœ… Database connected successfully<br>";

// Check the structure of users table
echo "<h3>Checking 'users' table structure:</h3>";
$result = $conn->query("DESCRIBE users");
if ($result) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . $row['Default'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "âŒ Could not describe users table: " . $conn->error . "<br>";
}

// Create a test user
$username = 'admin';
$password = 'admin123';
$email = 'admin@geocil.com';
$first_name = 'Admin';
$last_name = 'User';

// Hash the password
$password_hash = password_hash($password, PASSWORD_DEFAULT);

echo "<br><h3>Adding user to 'users' table:</h3>";

// Try to insert user (handle different possible column structures)
$sql = "INSERT INTO users (username, password, email, first_name, last_name, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)";
$role = 'admin';
$is_active = 1;

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("ssssssi", $username, $password_hash, $email, $first_name, $last_name, $role, $is_active);
    
    if ($stmt->execute()) {
        echo "âœ… User added successfully!<br>";
    } else {
        echo "âŒ Failed to add user: " . $stmt->error . "<br>";
        
        // Try alternative column names
        echo "<br>Trying alternative column names...<br>";
        $sql2 = "INSERT INTO users (username, password_hash, email, first_name, last_name, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt2 = $conn->prepare($sql2);
        if ($stmt2) {
            $stmt2->bind_param("ssssssi", $username, $password_hash, $email, $first_name, $last_name, $role, $is_active);
            if ($stmt2->execute()) {
                echo "âœ… User added with alternative column names!<br>";
            } else {
                echo "âŒ Failed with alternative names: " . $stmt2->error . "<br>";
            }
        }
    }
} else {
    echo "âŒ Could not prepare statement: " . $conn->error . "<br>";
}

// Show all users
echo "<br><h3>All Users in 'users' table:</h3>";
$result = $conn->query("SELECT * FROM users LIMIT 10");
if ($result) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    $first_row = true;
    while ($row = $result->fetch_assoc()) {
        if ($first_row) {
            echo "<tr>";
            foreach (array_keys($row) as $column) {
                echo "<th>" . $column . "</th>";
            }
            echo "</tr>";
            $first_row = false;
        }
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . (strlen($value) > 50 ? substr($value, 0, 50) . "..." : $value) . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "âŒ Could not fetch users: " . $conn->error . "<br>";
}

$conn->close();

echo "<br><h3>Login Credentials:</h3>";
echo "<strong>Username:</strong> <code>admin</code><br>";
echo "<strong>Password:</strong> <code>admin123</code><br>";
echo "<br><a href='login.html' style='background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Try Login Now</a>";
?>


