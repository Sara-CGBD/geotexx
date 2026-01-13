<?php
/**
 * Fix sewing_test user - Generate correct password hash and create user
 */

require_once 'config/security_config.php';

$conn = SecurityConfig::getConnection();

// User details
$username = 'sewing_test';
$full_name = 'Sewing Test User';
$email = 'sewing@test.com';
$role = 'sewing_test';
$password = 'Test@123';

// Generate correct password hash
$password_hash = password_hash($password, PASSWORD_BCRYPT);

echo "<h2>Fixing Sewing Test User</h2>";
echo "<p>Username: <strong>{$username}</strong></p>";
echo "<p>Password: <strong>{$password}</strong></p>";
echo "<p>Generated Hash: <strong>{$password_hash}</strong></p>";
echo "<p>Role: <strong>{$role}</strong></p>";
echo "<hr>";

// Check if user exists
echo "<h3>Checking existing user:</h3>";
$check1 = $conn->query("SELECT * FROM users WHERE username = '{$username}'");
if ($check1 && $check1->num_rows > 0) {
    $existing = $check1->fetch_assoc();
    echo "<p>Found in users table: ID {$existing['id']}</p>";
    echo "<p>Current password field: " . substr($existing['password'] ?? 'N/A', 0, 50) . "...</p>";
} else {
    echo "<p>User not found in users table</p>";
}

$check2 = $conn->query("SELECT * FROM new_user WHERE username = '{$username}'");
if ($check2 && $check2->num_rows > 0) {
    $existing = $check2->fetch_assoc();
    echo "<p>Found in new_user table: ID {$existing['id']}</p>";
    echo "<p>Current password_hash field: " . substr($existing['password_hash'] ?? 'N/A', 0, 50) . "...</p>";
} else {
    echo "<p>User not found in new_user table</p>";
}

echo "<hr>";

// Delete existing user if exists
$conn->query("DELETE FROM users WHERE username = '{$username}'");
$conn->query("DELETE FROM new_user WHERE username = '{$username}'");
echo "<p>✓ Cleaned up existing user (if any)</p>";

// Insert into users table - try with password_hash column first, fallback to password
$stmt1 = $conn->prepare("INSERT INTO users (username, full_name, password, password_hash, role, email, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
if ($stmt1) {
    $stmt1->bind_param("ssssss", $username, $full_name, $password_hash, $password_hash, $role, $email);
    if ($stmt1->execute()) {
        echo "<p style='color: green;'>✓ User created in <strong>users</strong> table</p>";
    } else {
        // Try without password_hash column
        $stmt1->close();
        $stmt1 = $conn->prepare("INSERT INTO users (username, full_name, password, role, email, status) VALUES (?, ?, ?, ?, ?, 'active')");
        if ($stmt1) {
            $stmt1->bind_param("sssss", $username, $full_name, $password_hash, $role, $email);
            if ($stmt1->execute()) {
                echo "<p style='color: green;'>✓ User created in <strong>users</strong> table (without password_hash column)</p>";
            } else {
                echo "<p style='color: red;'>✗ Error creating user in users table: " . $stmt1->error . "</p>";
            }
            $stmt1->close();
        } else {
            echo "<p style='color: red;'>✗ Could not prepare statement for users table: " . $conn->error . "</p>";
        }
    }
} else {
    echo "<p style='color: orange;'>⚠ Could not prepare statement for users table: " . $conn->error . "</p>";
}

// Insert into new_user table
$stmt2 = $conn->prepare("INSERT INTO new_user (username, full_name, password_hash, email, role, is_active) VALUES (?, ?, ?, ?, ?, 1)");
if ($stmt2) {
    $stmt2->bind_param("sssss", $username, $full_name, $password_hash, $email, $role);
    if ($stmt2->execute()) {
        echo "<p style='color: green;'>✓ User created in <strong>new_user</strong> table</p>";
    } else {
        echo "<p style='color: orange;'>⚠ Could not create in new_user table: " . $stmt2->error . "</p>";
    }
    $stmt2->close();
} else {
    echo "<p style='color: orange;'>⚠ Could not prepare statement for new_user table: " . $conn->error . "</p>";
}

// Verify user and test password
echo "<hr><h3>Verification:</h3>";
$result1 = $conn->query("SELECT * FROM users WHERE username = '{$username}'");
if ($result1 && $result1->num_rows > 0) {
    $user = $result1->fetch_assoc();
    echo "<p style='color: green;'>✓ Found in users table: ID {$user['id']}, Role: {$user['role']}</p>";
    
    // Test password verification
    $test_pass = $user['password'] ?? '';
    if (password_verify($password, $test_pass)) {
        echo "<p style='color: green;'>✓ Password verification: SUCCESS</p>";
    } else {
        echo "<p style='color: red;'>✗ Password verification: FAILED</p>";
    }
} else {
    echo "<p style='color: red;'>✗ Not found in users table</p>";
}

$result2 = $conn->query("SELECT * FROM new_user WHERE username = '{$username}'");
if ($result2 && $result2->num_rows > 0) {
    $user = $result2->fetch_assoc();
    echo "<p style='color: green;'>✓ Found in new_user table: ID {$user['id']}, Role: {$user['role']}</p>";
    
    // Test password verification
    $test_pass = $user['password_hash'] ?? '';
    if (password_verify($password, $test_pass)) {
        echo "<p style='color: green;'>✓ Password verification: SUCCESS</p>";
    } else {
        echo "<p style='color: red;'>✗ Password verification: FAILED</p>";
    }
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

