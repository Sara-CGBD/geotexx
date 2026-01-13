<?php
// Fix store_user login - Diagnostic and Fix Script
error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = new mysqli("localhost", "root", "", "geobagg");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$username = 'store_test';
$password = 'Test@123';

echo "<h2>Store User Login Fix - Diagnostic</h2>";
echo "<hr>";

// Step 1: Check if user exists
echo "<h3>Step 1: Checking if user exists...</h3>";
$check = $conn->prepare("SELECT * FROM new_user WHERE username = ?");
$check->bind_param("s", $username);
$check->execute();
$result = $check->get_result();
$user = $result->fetch_assoc();
$check->close();

if (!$user) {
    echo "<p style='color: red;'>✗ User not found! Creating user...</p>";
    
    // Create user
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $full_name = 'Store User Test';
    $email = 'store@test.com';
    $role = 'store_user';
    
    // Check which columns exist
    $colCheck = $conn->query("SHOW COLUMNS FROM new_user LIKE 'password_hash'");
    $hasPasswordHash = $colCheck && $colCheck->num_rows > 0;
    
    if ($hasPasswordHash) {
        $create = $conn->prepare("INSERT INTO new_user (username, password, password_hash, full_name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
        $create->bind_param("ssssss", $username, $password_hash, $password_hash, $full_name, $email, $role);
    } else {
        $create = $conn->prepare("INSERT INTO new_user (username, password, full_name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, 1, NOW())");
        $create->bind_param("sssss", $username, $password_hash, $full_name, $email, $role);
    }
    
    if ($create->execute()) {
        echo "<p style='color: green;'>✓ User created successfully!</p>";
        $user = ['username' => $username, 'password' => $password_hash, 'password_hash' => $password_hash];
    } else {
        die("<p style='color: red;'>✗ Failed to create user: " . $create->error . "</p>");
    }
    $create->close();
} else {
    echo "<p style='color: green;'>✓ User found!</p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Value</th></tr>";
    foreach ($user as $key => $value) {
        if ($key === 'password' || $key === 'password_hash') {
            echo "<tr><td>$key</td><td>" . substr($value, 0, 50) . "...</td></tr>";
        } else {
            echo "<tr><td>$key</td><td>$value</td></tr>";
        }
    }
    echo "</table>";
}

echo "<hr>";

// Step 2: Check password verification
echo "<h3>Step 2: Testing password verification...</h3>";

$password_valid = false;
$test_method = '';

// Test password_hash column
if (isset($user['password_hash']) && !empty($user['password_hash'])) {
    $test = password_verify($password, $user['password_hash']);
    echo "<p>Testing password_hash column: " . ($test ? "<span style='color: green;'>✓ VALID</span>" : "<span style='color: red;'>✗ INVALID</span>") . "</p>";
    if ($test) {
        $password_valid = true;
        $test_method = 'password_hash';
    }
}

// Test password column
if (!$password_valid && isset($user['password']) && !empty($user['password'])) {
    $info = password_get_info($user['password']);
    if ($info['algo'] !== null) {
        // It's a hash
        $test = password_verify($password, $user['password']);
        echo "<p>Testing password column (hashed): " . ($test ? "<span style='color: green;'>✓ VALID</span>" : "<span style='color: red;'>✗ INVALID</span>") . "</p>";
        if ($test) {
            $password_valid = true;
            $test_method = 'password (hashed)';
        }
    } else {
        // It's plain text
        $test = ($password === $user['password']);
        echo "<p>Testing password column (plain text): " . ($test ? "<span style='color: green;'>✓ VALID</span>" : "<span style='color: red;'>✗ INVALID</span>") . "</p>";
        if ($test) {
            $password_valid = true;
            $test_method = 'password (plain text)';
        }
    }
}

echo "<hr>";

// Step 3: Fix password if needed
if (!$password_valid) {
    echo "<h3>Step 3: Fixing password...</h3>";
    
    // Generate fresh hash
    $fresh_hash = password_hash($password, PASSWORD_DEFAULT);
    echo "<p>Generated fresh hash: " . substr($fresh_hash, 0, 50) . "...</p>";
    
    // Check which columns exist and update accordingly
    $colCheck = $conn->query("SHOW COLUMNS FROM new_user LIKE 'password_hash'");
    $hasPasswordHash = $colCheck && $colCheck->num_rows > 0;
    
    if ($hasPasswordHash) {
        $update = $conn->prepare("UPDATE new_user SET password = ?, password_hash = ? WHERE username = ?");
        $update->bind_param("sss", $fresh_hash, $fresh_hash, $username);
    } else {
        $update = $conn->prepare("UPDATE new_user SET password = ? WHERE username = ?");
        $update->bind_param("ss", $fresh_hash, $username);
    }
    
    if ($update->execute()) {
        echo "<p style='color: green; font-weight: bold;'>✓ Password updated successfully!</p>";
        
        // Verify the update worked
        $verify = password_verify($password, $fresh_hash);
        echo "<p>Verification test: " . ($verify ? "<span style='color: green; font-weight: bold;'>✓ SUCCESS</span>" : "<span style='color: red;'>✗ FAILED</span>") . "</p>";
    } else {
        echo "<p style='color: red;'>✗ Failed to update password: " . $update->error . "</p>";
    }
    $update->close();
} else {
    echo "<h3>Step 3: Password is valid!</h3>";
    echo "<p>Password verification works via: <strong>$test_method</strong></p>";
}

echo "<hr>";

// Step 4: Check account status
echo "<h3>Step 4: Checking account status...</h3>";
$status_check = $conn->prepare("SELECT is_active, lock_until FROM new_user WHERE username = ?");
$status_check->bind_param("s", $username);
$status_check->execute();
$status_result = $status_check->get_result();
$status = $status_result->fetch_assoc();
$status_check->close();

if ($status) {
    echo "<p>Account Active: " . ($status['is_active'] == 1 ? "<span style='color: green;'>✓ YES</span>" : "<span style='color: red;'>✗ NO</span>") . "</p>";
    
    if ($status['is_active'] != 1) {
        echo "<p style='color: orange;'>⚠ Account is inactive. Activating...</p>";
        $activate = $conn->prepare("UPDATE new_user SET is_active = 1 WHERE username = ?");
        $activate->bind_param("s", $username);
        $activate->execute();
        $activate->close();
        echo "<p style='color: green;'>✓ Account activated!</p>";
    }
    
    if ($status['lock_until'] && strtotime($status['lock_until']) > time()) {
        echo "<p style='color: red;'>⚠ Account is locked until: " . $status['lock_until'] . "</p>";
        echo "<p>Unlocking account...</p>";
        $unlock = $conn->prepare("UPDATE new_user SET lock_until = NULL, wrong_attempts = 0 WHERE username = ?");
        $unlock->bind_param("s", $username);
        $unlock->execute();
        $unlock->close();
        echo "<p style='color: green;'>✓ Account unlocked!</p>";
    } else {
        echo "<p>Account Lock Status: <span style='color: green;'>✓ NOT LOCKED</span></p>";
    }
}

echo "<hr>";

// Step 5: Final verification
echo "<h3>Step 5: Final Login Test</h3>";
$final_check = $conn->prepare("SELECT username, password, password_hash, role, is_active FROM new_user WHERE username = ?");
$final_check->bind_param("s", $username);
$final_check->execute();
$final_result = $final_check->get_result();
$final_user = $final_result->fetch_assoc();
$final_check->close();

if ($final_user) {
    $final_valid = false;
    
    if (isset($final_user['password_hash']) && !empty($final_user['password_hash'])) {
        $final_valid = password_verify($password, $final_user['password_hash']);
    }
    
    if (!$final_valid && isset($final_user['password']) && !empty($final_user['password'])) {
        $info = password_get_info($final_user['password']);
        if ($info['algo'] !== null) {
            $final_valid = password_verify($password, $final_user['password']);
        } else {
            $final_valid = ($password === $final_user['password']);
        }
    }
    
    echo "<p><strong>Login Credentials:</strong></p>";
    echo "<ul>";
    echo "<li>Username: <strong>$username</strong></li>";
    echo "<li>Password: <strong>$password</strong></li>";
    echo "<li>Role: <strong>" . $final_user['role'] . "</strong></li>";
    echo "<li>Account Active: <strong>" . ($final_user['is_active'] == 1 ? 'YES' : 'NO') . "</strong></li>";
    echo "</ul>";
    
    if ($final_valid) {
        echo "<p style='color: green; font-size: 18px; font-weight: bold;'>✓✓✓ PASSWORD VERIFICATION SUCCESSFUL! ✓✓✓</p>";
        echo "<p style='color: green; font-weight: bold;'>You should now be able to login!</p>";
    } else {
        echo "<p style='color: red; font-size: 18px; font-weight: bold;'>✗✗✗ PASSWORD VERIFICATION FAILED ✗✗✗</p>";
        echo "<p style='color: red;'>Please refresh this page to try again.</p>";
    }
}

$conn->close();
?>

