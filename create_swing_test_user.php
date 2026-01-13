<?php
/**
 * Create sewing_test user - Simple and direct approach
 * Matches how other test users are created
 */

require_once 'config/security_config.php';

$conn = SecurityConfig::getConnection();

$username = 'sewing_test';
$full_name = 'Sewing Test User';
$email = 'sewing@test.com';
$role = 'sewing_test';
$password = 'Test@123';

// Generate password hash using PASSWORD_DEFAULT (same as other test users)
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "<h2>Creating Sewing Test User</h2>";
echo "<p>Username: <strong>{$username}</strong></p>";
echo "<p>Password: <strong>{$password}</strong></p>";
echo "<p>Role: <strong>{$role}</strong></p>";
echo "<p>Password Hash: <code>" . substr($hash, 0, 50) . "...</code></p>";
echo "<hr>";

// Delete existing user
$conn->query("DELETE FROM users WHERE username = '{$username}'");
$conn->query("DELETE FROM new_user WHERE username = '{$username}'");
echo "<p>✓ Cleaned up existing user</p>";

// Insert into users table (same approach as add_all_test_users.php)
$stmt = $conn->prepare("INSERT INTO users (username, full_name, password, role, email, status) VALUES (?, ?, ?, ?, ?, 'active')
    ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password = VALUES(password), role = VALUES(role), email = VALUES(email), status = 'active'");
$stmt->bind_param("sssss", $username, $full_name, $hash, $role, $email);
if ($stmt->execute()) {
    echo "<p style='color: green;'>✓ User created/updated in <strong>users</strong> table</p>";
} else {
    echo "<p style='color: red;'>✗ Error: " . $stmt->error . "</p>";
}
$stmt->close();

// Insert into new_user table - uses 'password' column (not password_hash)
$stmt2 = $conn->prepare("INSERT INTO new_user (username, full_name, password, email, role, is_active) VALUES (?, ?, ?, ?, ?, 1)
    ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password = VALUES(password), email = VALUES(email), role = VALUES(role), is_active = 1");
$stmt2->bind_param("sssss", $username, $full_name, $hash, $email, $role);
if ($stmt2->execute()) {
    echo "<p style='color: green;'>✓ User created/updated in <strong>new_user</strong> table</p>";
} else {
    echo "<p style='color: orange;'>⚠ Error: " . $stmt2->error . "</p>";
}
$stmt2->close();

// Verify and test
echo "<hr><h3>Verification:</h3>";

// Check users table
$result = $conn->query("SELECT * FROM users WHERE username = '{$username}'");
if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo "<p style='color: green;'>✓ Found in users table (ID: {$user['id']})</p>";
    
    // Test password
    $stored_pass = $user['password'] ?? '';
    if (password_verify($password, $stored_pass)) {
        echo "<p style='color: green;'>✓ Password verification: <strong>SUCCESS</strong></p>";
    } else {
        echo "<p style='color: red;'>✗ Password verification: <strong>FAILED</strong></p>";
        echo "<p>Stored hash: <code>" . substr($stored_pass, 0, 50) . "...</code></p>";
        echo "<p>Expected hash: <code>" . substr($hash, 0, 50) . "...</code></p>";
    }
} else {
    echo "<p style='color: red;'>✗ Not found in users table</p>";
}

// Check new_user table
$result2 = $conn->query("SELECT * FROM new_user WHERE username = '{$username}'");
if ($result2 && $result2->num_rows > 0) {
    $user = $result2->fetch_assoc();
    echo "<p style='color: green;'>✓ Found in new_user table (ID: {$user['id']})</p>";
    
    // Test password - check both password and password_hash columns
    $stored_hash = $user['password_hash'] ?? $user['password'] ?? '';
    if (!empty($stored_hash) && password_verify($password, $stored_hash)) {
        echo "<p style='color: green;'>✓ Password verification: <strong>SUCCESS</strong></p>";
    } else {
        echo "<p style='color: red;'>✗ Password verification: <strong>FAILED</strong></p>";
    }
} else {
    echo "<p style='color: orange;'>⚠ Not found in new_user table</p>";
}

// Final test - simulate login check
echo "<hr><h3>Login Simulation:</h3>";
$test_stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
$test_stmt->bind_param("s", $username);
$test_stmt->execute();
$test_result = $test_stmt->get_result();
$test_user = $test_result->fetch_assoc();
$test_stmt->close();

if ($test_user) {
    $login_valid = false;
    
    // Check password_hash first (as login.php does)
    if (isset($test_user['password_hash']) && !empty($test_user['password_hash'])) {
        $login_valid = password_verify($password, $test_user['password_hash']);
    }
    
    // Then check password column
    if (!$login_valid && isset($test_user['password']) && !empty($test_user['password'])) {
        if (password_get_info($test_user['password'])['algo'] !== null) {
            $login_valid = password_verify($password, $test_user['password']);
        } else {
            $login_valid = ($password === $test_user['password']);
        }
    }
    
    if ($login_valid) {
        echo "<p style='color: green; font-size: 18px; font-weight: bold;'>✓✓✓ LOGIN WILL SUCCEED ✓✓✓</p>";
    } else {
        echo "<p style='color: red; font-size: 18px; font-weight: bold;'>✗✗✗ LOGIN WILL FAIL ✗✗✗</p>";
    }
} else {
    echo "<p style='color: red;'>✗ User not found - login will fail</p>";
}

echo "<hr>";
echo "<h3>Login Credentials:</h3>";
echo "<p><strong>Username:</strong> {$username}</p>";
echo "<p><strong>Password:</strong> {$password}</p>";
echo "<p><strong>Role:</strong> {$role}</p>";

$conn->close();
?>

