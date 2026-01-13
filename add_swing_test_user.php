<?php
/**
 * Add swing_test user for Bag Production Module
 * Password: Test@123
 */

require_once 'config/security_config.php';

$conn = SecurityConfig::getConnection();

// User details
$username = 'swing_test';
$full_name = 'Swing Test User';
$email = 'swing@test.com';
$role = 'swing_test';
$password = 'Test@123';
// Generate correct password hash dynamically
$password_hash = password_hash($password, PASSWORD_BCRYPT);

echo "<h2>Adding Swing Test User</h2>";
echo "<p>Username: <strong>{$username}</strong></p>";
echo "<p>Password: <strong>{$password}</strong></p>";
echo "<p>Role: <strong>{$role}</strong></p>";
echo "<hr>";

// Delete existing user if exists
$conn->query("DELETE FROM users WHERE username = '{$username}'");
$conn->query("DELETE FROM new_user WHERE username = '{$username}'");
echo "<p>✓ Cleaned up existing user (if any)</p>";

// Insert into users table
$stmt1 = $conn->prepare("INSERT INTO users (username, full_name, password, role, email, status) VALUES (?, ?, ?, ?, ?, 'active')");
if ($stmt1) {
    $stmt1->bind_param("sssss", $username, $full_name, $password_hash, $role, $email);
    if ($stmt1->execute()) {
        echo "<p style='color: green;'>✓ User created in <strong>users</strong> table</p>";
    } else {
        echo "<p style='color: red;'>✗ Error creating user in users table: " . $stmt1->error . "</p>";
    }
    $stmt1->close();
} else {
    echo "<p style='color: orange;'>⚠ Could not prepare statement for users table: " . $conn->error . "</p>";
}

// Insert into new_user table
$stmt2 = $conn->prepare("INSERT INTO new_user (username, full_name, password_hash, email, role, is_active) VALUES (?, ?, ?, ?, ?, 1)");
if ($stmt2) {
    $stmt2->bind_param("sssss", $username, $full_name, $password_hash, $role, $email);
    if ($stmt2->execute()) {
        echo "<p style='color: green;'>✓ User created in <strong>new_user</strong> table</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Could not create in new_user table: " . $stmt2->error . "</p>";
    }
    $stmt2->close();
} else {
    echo "<p style='color: orange;'>⚠ Could not prepare statement for new_user table: " . $conn->error . "</p>";
}

// Verify user
echo "<hr><h3>Verification:</h3>";
$result1 = $conn->query("SELECT id, username, full_name, role, email, status FROM users WHERE username = '{$username}'");
if ($result1 && $result1->num_rows > 0) {
    $user = $result1->fetch_assoc();
    echo "<p style='color: green;'>✓ Found in users table: ID {$user['id']}, Role: {$user['role']}</p>";
} else {
    echo "<p style='color: red;'>✗ Not found in users table</p>";
}

$result2 = $conn->query("SELECT id, username, full_name, role, email, is_active FROM new_user WHERE username = '{$username}'");
if ($result2 && $result2->num_rows > 0) {
    $user = $result2->fetch_assoc();
    echo "<p style='color: green;'>✓ Found in new_user table: ID {$user['id']}, Role: {$user['role']}</p>";
} else {
    echo "<p style='color: orange;'>⚠ Not found in new_user table (may not exist)</p>";
}

echo "<hr>";
echo "<h3>Login Credentials:</h3>";
echo "<p><strong>Username:</strong> {$username}</p>";
echo "<p><strong>Password:</strong> {$password}</p>";
echo "<p><strong>Role:</strong> {$role}</p>";
echo "<p style='color: blue;'><strong>Access:</strong> Bag Production Module Only</p>";

$conn->close();
?>

