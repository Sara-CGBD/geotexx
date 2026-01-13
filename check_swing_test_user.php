<?php
/**
 * Diagnostic script to check swing_test user and password
 */

require_once 'config/security_config.php';

$conn = SecurityConfig::getConnection();

$username = 'swing_test';
$password = 'Test@123';

echo "<h2>Swing Test User Diagnostic</h2>";
echo "<p>Checking username: <strong>{$username}</strong></p>";
echo "<p>Testing password: <strong>{$password}</strong></p>";
echo "<hr>";

// Check users table
echo "<h3>1. Checking 'users' table:</h3>";
$result1 = $conn->query("SELECT * FROM users WHERE username = '{$username}'");
if ($result1 && $result1->num_rows > 0) {
    $user = $result1->fetch_assoc();
    echo "<p style='color: green;'>✓ User found in 'users' table</p>";
    echo "<p>ID: {$user['id']}</p>";
    echo "<p>Username: {$user['username']}</p>";
    echo "<p>Full Name: " . ($user['full_name'] ?? 'N/A') . "</p>";
    echo "<p>Role: " . ($user['role'] ?? 'N/A') . "</p>";
    echo "<p>Email: " . ($user['email'] ?? 'N/A') . "</p>";
    echo "<p>Status: " . ($user['status'] ?? 'N/A') . "</p>";
    echo "<p>Is Active: " . (isset($user['is_active']) ? ($user['is_active'] ? 'Yes' : 'No') : 'N/A') . "</p>";
    
    // Check password fields
    $has_password = isset($user['password']) && !empty($user['password']);
    $has_password_hash = isset($user['password_hash']) && !empty($user['password_hash']);
    
    echo "<p>Has 'password' field: " . ($has_password ? 'Yes' : 'No') . "</p>";
    echo "<p>Has 'password_hash' field: " . ($has_password_hash ? 'Yes' : 'No') . "</p>";
    
    if ($has_password) {
        $pass_value = $user['password'];
        $pass_preview = substr($pass_value, 0, 30) . '...';
        echo "<p>Password field value (first 30 chars): {$pass_preview}</p>";
        
        // Test password verification
        if (password_get_info($pass_value)['algo'] !== null) {
            // It's a hash
            $verify = password_verify($password, $pass_value);
            echo "<p>Password verification (hash): " . ($verify ? '<span style="color: green;">✓ SUCCESS</span>' : '<span style="color: red;">✗ FAILED</span>') . "</p>";
        } else {
            // Plain text
            $verify = ($password === $pass_value);
            echo "<p>Password verification (plain): " . ($verify ? '<span style="color: green;">✓ SUCCESS</span>' : '<span style="color: red;">✗ FAILED</span>') . "</p>";
        }
    }
    
    if ($has_password_hash) {
        $pass_hash_value = $user['password_hash'];
        $pass_hash_preview = substr($pass_hash_value, 0, 30) . '...';
        echo "<p>Password_hash field value (first 30 chars): {$pass_hash_preview}</p>";
        
        // Test password verification
        $verify_hash = password_verify($password, $pass_hash_value);
        echo "<p>Password_hash verification: " . ($verify_hash ? '<span style="color: green;">✓ SUCCESS</span>' : '<span style="color: red;">✗ FAILED</span>') . "</p>";
    }
    
} else {
    echo "<p style='color: red;'>✗ User NOT found in 'users' table</p>";
}

echo "<hr>";

// Check new_user table
echo "<h3>2. Checking 'new_user' table:</h3>";
$result2 = $conn->query("SELECT * FROM new_user WHERE username = '{$username}'");
if ($result2 && $result2->num_rows > 0) {
    $user = $result2->fetch_assoc();
    echo "<p style='color: green;'>✓ User found in 'new_user' table</p>";
    echo "<p>ID: {$user['id']}</p>";
    echo "<p>Username: {$user['username']}</p>";
    echo "<p>Full Name: " . ($user['full_name'] ?? 'N/A') . "</p>";
    echo "<p>Role: " . ($user['role'] ?? 'N/A') . "</p>";
    echo "<p>Email: " . ($user['email'] ?? 'N/A') . "</p>";
    echo "<p>Is Active: " . (isset($user['is_active']) ? ($user['is_active'] ? 'Yes' : 'No') : 'N/A') . "</p>";
    
    // Check password fields
    $has_password = isset($user['password']) && !empty($user['password']);
    $has_password_hash = isset($user['password_hash']) && !empty($user['password_hash']);
    
    echo "<p>Has 'password' field: " . ($has_password ? 'Yes' : 'No') . "</p>";
    echo "<p>Has 'password_hash' field: " . ($has_password_hash ? 'Yes' : 'No') . "</p>";
    
    if ($has_password) {
        $pass_value = $user['password'];
        $pass_preview = substr($pass_value, 0, 30) . '...';
        echo "<p>Password field value (first 30 chars): {$pass_preview}</p>";
        
        // Test password verification
        if (password_get_info($pass_value)['algo'] !== null) {
            // It's a hash
            $verify = password_verify($password, $pass_value);
            echo "<p>Password verification (hash): " . ($verify ? '<span style="color: green;">✓ SUCCESS</span>' : '<span style="color: red;">✗ FAILED</span>') . "</p>";
        } else {
            // Plain text
            $verify = ($password === $pass_value);
            echo "<p>Password verification (plain): " . ($verify ? '<span style="color: green;">✓ SUCCESS</span>' : '<span style="color: red;">✗ FAILED</span>') . "</p>";
        }
    }
    
    if ($has_password_hash) {
        $pass_hash_value = $user['password_hash'];
        $pass_hash_preview = substr($pass_hash_value, 0, 30) . '...';
        echo "<p>Password_hash field value (first 30 chars): {$pass_hash_preview}</p>";
        
        // Test password verification
        $verify_hash = password_verify($password, $pass_hash_value);
        echo "<p>Password_hash verification: " . ($verify_hash ? '<span style="color: green;">✓ SUCCESS</span>' : '<span style="color: red;">✗ FAILED</span>') . "</p>";
    }
    
} else {
    echo "<p style='color: orange;'>⚠ User NOT found in 'new_user' table</p>";
}

echo "<hr>";

// Generate correct hash
echo "<h3>3. Generating correct password hash:</h3>";
$correct_hash = password_hash($password, PASSWORD_BCRYPT);
echo "<p>Correct hash for '{$password}':</p>";
echo "<p style='font-family: monospace; background: #f0f0f0; padding: 10px;'>{$correct_hash}</p>";

// Test the generated hash
$test_verify = password_verify($password, $correct_hash);
echo "<p>Verification test: " . ($test_verify ? '<span style="color: green;">✓ SUCCESS</span>' : '<span style="color: red;">✗ FAILED</span>') . "</p>";

echo "<hr>";
echo "<h3>4. Recommendation:</h3>";
if ($result1 && $result1->num_rows > 0) {
    $user = $result1->fetch_assoc();
    $has_valid_pass = false;
    
    if (isset($user['password_hash']) && password_verify($password, $user['password_hash'])) {
        $has_valid_pass = true;
    } elseif (isset($user['password'])) {
        if (password_get_info($user['password'])['algo'] !== null) {
            if (password_verify($password, $user['password'])) {
                $has_valid_pass = true;
            }
        } else {
            if ($password === $user['password']) {
                $has_valid_pass = true;
            }
        }
    }
    
    if (!$has_valid_pass) {
        echo "<p style='color: red;'>✗ Password verification FAILED. Please run <strong>fix_swing_test_user.php</strong> to update the password.</p>";
    } else {
        echo "<p style='color: green;'>✓ Password verification SUCCESS. User should be able to login.</p>";
    }
} else {
    echo "<p style='color: red;'>✗ User does not exist. Please run <strong>fix_swing_test_user.php</strong> to create the user.</p>";
}

$conn->close();
?>

