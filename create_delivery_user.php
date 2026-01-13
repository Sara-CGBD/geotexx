<?php
// Simple script to create delivery_user account
error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = new mysqli("localhost", "root", "", "geobagg");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$username = 'delivery_user';
$password = 'Test@123';
$password_hash = password_hash($password, PASSWORD_DEFAULT);
$full_name = 'Delivery User';
$email = 'delivery@test.com';
$role = 'delivery_user';

echo "<h2>Creating Delivery User Account</h2>";
echo "<hr>";

// Delete existing user if exists
$delete = $conn->prepare("DELETE FROM new_user WHERE username = ?");
$delete->bind_param("s", $username);
$delete->execute();
$delete->close();
echo "<p>✓ Cleared existing user (if any)</p>";

// Check table structure
$columns = [];
$colResult = $conn->query("SHOW COLUMNS FROM new_user");
while ($row = $colResult->fetch_assoc()) {
    $columns[] = $row['Field'];
}

echo "<p>Available columns: " . implode(', ', $columns) . "</p>";

// Build INSERT query based on available columns
$hasPasswordHash = in_array('password_hash', $columns);
$hasFullName = in_array('full_name', $columns);
$hasFirstName = in_array('first_name', $columns);
$hasLastName = in_array('last_name', $columns);

if ($hasPasswordHash) {
    if ($hasFullName) {
        $stmt = $conn->prepare("INSERT INTO new_user (username, password, password_hash, full_name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
        $stmt->bind_param("ssssss", $username, $password_hash, $password_hash, $full_name, $email, $role);
    } else {
        $stmt = $conn->prepare("INSERT INTO new_user (username, password, password_hash, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
        $stmt->bind_param("sssss", $username, $password_hash, $password_hash, $email, $role);
    }
} else {
    if ($hasFullName) {
        $stmt = $conn->prepare("INSERT INTO new_user (username, password, full_name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
        $stmt->bind_param("sssss", $username, $password_hash, $full_name, $email, $role);
    } else if ($hasFirstName && $hasLastName) {
        $first_name = 'Delivery';
        $last_name = 'User Test';
        $stmt = $conn->prepare("INSERT INTO new_user (username, password, first_name, last_name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
        $stmt->bind_param("ssssss", $username, $password_hash, $first_name, $last_name, $email, $role);
    } else {
        $stmt = $conn->prepare("INSERT INTO new_user (username, password, email, role, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
        $stmt->bind_param("ssss", $username, $password_hash, $email, $role);
    }
}

if ($stmt->execute()) {
    echo "<p style='color: green; font-weight: bold; font-size: 18px;'>✓✓✓ USER CREATED SUCCESSFULLY! ✓✓✓</p>";
    echo "<hr>";
    echo "<h3>Login Credentials:</h3>";
    echo "<ul style='font-size: 16px;'>";
    echo "<li><strong>Username:</strong> $username</li>";
    echo "<li><strong>Password:</strong> $password</li>";
    echo "<li><strong>Role:</strong> $role</li>";
    echo "</ul>";
    
    // Verify password
    $verify = password_verify($password, $password_hash);
    echo "<p>Password Hash Verification: " . ($verify ? "<span style='color: green; font-weight: bold;'>✓ SUCCESS</span>" : "<span style='color: red;'>✗ FAILED</span>") . "</p>";
    
    // Verify user was created
    $check = $conn->prepare("SELECT * FROM new_user WHERE username = ?");
    $check->bind_param("s", $username);
    $check->execute();
    $result = $check->get_result();
    $user = $result->fetch_assoc();
    $check->close();
    
    if ($user) {
        echo "<hr>";
        echo "<h3>User Details:</h3>";
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr><th>Field</th><th>Value</th></tr>";
        foreach ($user as $key => $value) {
            if ($key === 'password' || $key === 'password_hash') {
                echo "<tr><td>$key</td><td>" . substr($value, 0, 30) . "...</td></tr>";
            } else {
                echo "<tr><td>$key</td><td>" . htmlspecialchars($value) . "</td></tr>";
            }
        }
        echo "</table>";
    }
    
    echo "<hr>";
    echo "<p style='font-size: 16px;'><a href='login.html' style='padding: 10px 20px; background: #2ecc71; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Go to Login Page</a></p>";
} else {
    echo "<p style='color: red; font-weight: bold;'>✗ Error creating user: " . $stmt->error . "</p>";
}

$stmt->close();
$conn->close();
?>

