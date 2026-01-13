<?php
// Create store_user test account
// This script will generate a fresh password hash and create the user

$conn = new mysqli("localhost", "root", "", "geobagg");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$username = 'store_test';
$password = 'Test@123';
$full_name = 'Store User Test';
$email = 'store@test.com';
$role = 'store_user';

// Generate fresh password hash
$password_hash = password_hash($password, PASSWORD_DEFAULT);

echo "<h2>Creating Store User Account</h2>";
echo "<p><strong>Username:</strong> $username</p>";
echo "<p><strong>Password:</strong> $password</p>";
echo "<p><strong>Role:</strong> $role</p>";
echo "<p><strong>Generated Hash:</strong> " . substr($password_hash, 0, 50) . "...</p>";
echo "<hr>";

// Delete existing user if exists
$delete = $conn->prepare("DELETE FROM new_user WHERE username = ?");
$delete->bind_param("s", $username);
$delete->execute();
$delete->close();
echo "<p>✓ Cleared existing user (if any)</p>";

// Check which columns exist
$colCheck = $conn->query("SHOW COLUMNS FROM new_user LIKE 'password_hash'");
$hasPasswordHash = $colCheck && $colCheck->num_rows > 0;

// Insert into new_user table
if ($hasPasswordHash) {
    $stmt = $conn->prepare("INSERT INTO new_user (username, password, password_hash, full_name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
    $stmt->bind_param("ssssss", $username, $password_hash, $password_hash, $full_name, $email, $role);
} else {
    $stmt = $conn->prepare("INSERT INTO new_user (username, password, full_name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
    $stmt->bind_param("sssss", $username, $password_hash, $full_name, $email, $role);
}

if ($stmt->execute()) {
    echo "<p style='color: green; font-weight: bold;'>✓ Store user created successfully in new_user table!</p>";
    echo "<p><strong>Login Credentials:</strong></p>";
    echo "<ul>";
    echo "<li>Username: <strong>$username</strong></li>";
    echo "<li>Password: <strong>$password</strong></li>";
    echo "</ul>";
} else {
    echo "<p style='color: red;'>✗ Error creating user: " . $stmt->error . "</p>";
}
$stmt->close();

// Also try to insert into users table (for compatibility)
$stmt2 = $conn->prepare("INSERT INTO users (username, password, password_hash, email, first_name, last_name, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active') ON DUPLICATE KEY UPDATE password = VALUES(password), password_hash = VALUES(password_hash)");
$first_name = 'Store';
$last_name = 'User Test';
$stmt2->bind_param("sssssss", $username, $password_hash, $password_hash, $email, $first_name, $last_name, $role);

if ($stmt2->execute()) {
    echo "<p style='color: green;'>✓ Store user also created/updated in users table!</p>";
} else {
    echo "<p style='color: orange;'>⚠ Could not create in users table (may not exist or have different structure): " . $stmt2->error . "</p>";
}
$stmt2->close();

// Verify the user was created
$verify = $conn->prepare("SELECT username, full_name, email, role, is_active FROM new_user WHERE username = ?");
$verify->bind_param("s", $username);
$verify->execute();
$result = $verify->get_result();
$user = $result->fetch_assoc();

if ($user) {
    echo "<hr>";
    echo "<h3>✓ Verification:</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Value</th></tr>";
    foreach ($user as $key => $value) {
        echo "<tr><td>$key</td><td>$value</td></tr>";
    }
    echo "</table>";
    
    // Test password verification
    $test_verify = password_verify($password, $password_hash);
    echo "<p><strong>Password Verification Test:</strong> " . ($test_verify ? "<span style='color: green;'>✓ SUCCESS</span>" : "<span style='color: red;'>✗ FAILED</span>") . "</p>";
} else {
    echo "<p style='color: red;'>✗ User verification failed - user not found!</p>";
}

$conn->close();
?>

