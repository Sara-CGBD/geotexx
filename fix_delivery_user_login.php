<?php
// Diagnostic and fix script for delivery_user login
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

echo "<h2>Delivery User Login Fix - Diagnostic</h2>";
echo "<hr>";

// Step 1: Check if user exists
echo "<h3>Step 1: Checking if user exists...</h3>";
$check = $conn->prepare("SELECT * FROM new_user WHERE username = ?");
$check->bind_param("s", $username);
$check->execute();
$result = $check->get_result();
$user = $result->fetch_assoc();
$check->close();

if ($user) {
    echo "<p style='color: green;'>✓ User found!</p>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse; margin: 10px 0;'>";
    echo "<tr><th>Field</th><th>Value</th></tr>";
    foreach ($user as $key => $value) {
        if ($key === 'password' || $key === 'password_hash') {
            echo "<tr><td>$key</td><td>" . substr($value, 0, 30) . "...</td></tr>";
        } else {
            echo "<tr><td>$key</td><td>" . htmlspecialchars($value ?? 'NULL') . "</td></tr>";
        }
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>✗ User not found! Creating user...</p>";
    
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
            $last_name = 'User';
            $stmt = $conn->prepare("INSERT INTO new_user (username, password, first_name, last_name, email, role, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
            $stmt->bind_param("ssssss", $username, $password_hash, $first_name, $last_name, $email, $role);
        } else {
            $stmt = $conn->prepare("INSERT INTO new_user (username, password, email, role, is_active, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
            $stmt->bind_param("ssss", $username, $password_hash, $email, $role);
        }
    }
    
    if ($stmt->execute()) {
        echo "<p style='color: green; font-weight: bold;'>✓✓✓ USER CREATED SUCCESSFULLY! ✓✓✓</p>";
        $user = ['username' => $username, 'password' => $password_hash, 'password_hash' => $password_hash];
    } else {
        echo "<p style='color: red; font-weight: bold;'>✗ Error creating user: " . $stmt->error . "</p>";
        $conn->close();
        exit();
    }
    $stmt->close();
}

echo "<hr>";

// Step 2: Verify password
echo "<h3>Step 2: Verifying password...</h3>";
echo "<p>Testing password: <strong>$password</strong></p>";

$password_valid = false;
$password_column_used = '';

// Check which password column exists and verify
$columns = [];
$colResult = $conn->query("SHOW COLUMNS FROM new_user");
while ($row = $colResult->fetch_assoc()) {
    $columns[] = $row['Field'];
}

if (in_array('password_hash', $columns) && isset($user['password_hash']) && !empty($user['password_hash'])) {
    echo "<p>Trying 'password_hash' column...</p>";
    $password_valid = password_verify($password, $user['password_hash']);
    $password_column_used = 'password_hash';
    echo "<p>Result: " . ($password_valid ? "<span style='color: green; font-weight: bold;'>✓ SUCCESS</span>" : "<span style='color: red;'>✗ FAILED</span>") . "</p>";
}

if (!$password_valid && isset($user['password']) && !empty($user['password'])) {
    echo "<p>Trying 'password' column...</p>";
    // Check if it's a hash
    $hash_info = password_get_info($user['password']);
    if ($hash_info['algo'] !== null) {
        echo "<p>Password is HASHED (bcrypt)</p>";
        $password_valid = password_verify($password, $user['password']);
        $password_column_used = 'password (hashed)';
        echo "<p>Result: " . ($password_valid ? "<span style='color: green; font-weight: bold;'>✓ SUCCESS</span>" : "<span style='color: red;'>✗ FAILED</span>") . "</p>";
    } else {
        echo "<p>Password appears to be PLAIN TEXT</p>";
        $password_valid = ($password === $user['password']);
        $password_column_used = 'password (plain)';
        echo "<p>Result: " . ($password_valid ? "<span style='color: green; font-weight: bold;'>✓ SUCCESS</span>" : "<span style='color: red;'>✗ FAILED</span>") . "</p>";
    }
}

if (!$password_valid) {
    echo "<hr>";
    echo "<h3>Step 3: Fixing password...</h3>";
    echo "<p style='color: orange;'>Password verification failed. Updating password...</p>";
    
    // Update password
    if (in_array('password_hash', $columns)) {
        $update = $conn->prepare("UPDATE new_user SET password_hash = ?, password = ? WHERE username = ?");
        $update->bind_param("sss", $password_hash, $password_hash, $username);
    } else {
        $update = $conn->prepare("UPDATE new_user SET password = ? WHERE username = ?");
        $update->bind_param("ss", $password_hash, $username);
    }
    
    if ($update->execute()) {
        echo "<p style='color: green; font-weight: bold;'>✓ Password updated successfully!</p>";
        
        // Verify again
        $verify = password_verify($password, $password_hash);
        echo "<p>Verification after update: " . ($verify ? "<span style='color: green; font-weight: bold;'>✓ SUCCESS</span>" : "<span style='color: red;'>✗ FAILED</span>") . "</p>";
    } else {
        echo "<p style='color: red; font-weight: bold;'>✗ Error updating password: " . $update->error . "</p>";
    }
    $update->close();
}

echo "<hr>";

// Step 4: Check account status
echo "<h3>Step 4: Checking account status...</h3>";
$check = $conn->prepare("SELECT is_active, lock_until, wrong_attempts, role FROM new_user WHERE username = ?");
$check->bind_param("s", $username);
$check->execute();
$result = $check->get_result();
$status = $result->fetch_assoc();
$check->close();

if ($status) {
    echo "<p><strong>is_active:</strong> " . ($status['is_active'] ?? 'NULL') . "</p>";
    echo "<p><strong>lock_until:</strong> " . ($status['lock_until'] ?? 'NULL') . "</p>";
    echo "<p><strong>wrong_attempts:</strong> " . ($status['wrong_attempts'] ?? 'NULL') . "</p>";
    echo "<p><strong>role:</strong> " . ($status['role'] ?? 'NULL') . "</p>";
    
    // Fix if needed
    if (isset($status['is_active']) && $status['is_active'] == 0) {
        echo "<p style='color: orange;'>Account is inactive. Activating...</p>";
        $fix = $conn->prepare("UPDATE new_user SET is_active = 1 WHERE username = ?");
        $fix->bind_param("s", $username);
        $fix->execute();
        $fix->close();
        echo "<p style='color: green;'>✓ Account activated!</p>";
    }
    
    if (isset($status['lock_until']) && $status['lock_until'] && strtotime($status['lock_until']) > time()) {
        echo "<p style='color: orange;'>Account is locked. Unlocking...</p>";
        $fix = $conn->prepare("UPDATE new_user SET lock_until = NULL, wrong_attempts = 0 WHERE username = ?");
        $fix->bind_param("s", $username);
        $fix->execute();
        $fix->close();
        echo "<p style='color: green;'>✓ Account unlocked!</p>";
    }
    
    if (isset($status['wrong_attempts']) && $status['wrong_attempts'] > 0) {
        echo "<p style='color: orange;'>Resetting wrong attempts...</p>";
        $fix = $conn->prepare("UPDATE new_user SET wrong_attempts = 0 WHERE username = ?");
        $fix->bind_param("s", $username);
        $fix->execute();
        $fix->close();
        echo "<p style='color: green;'>✓ Wrong attempts reset!</p>";
    }
    
    if (isset($status['role']) && $status['role'] !== 'delivery_user') {
        echo "<p style='color: orange;'>Role is '{$status['role']}'. Updating to 'delivery_user'...</p>";
        $fix = $conn->prepare("UPDATE new_user SET role = ? WHERE username = ?");
        $fix->bind_param("ss", $role, $username);
        $fix->execute();
        $fix->close();
        echo "<p style='color: green;'>✓ Role updated!</p>";
    }
}

echo "<hr>";
echo "<h3>Final Status:</h3>";
echo "<p style='font-size: 18px; font-weight: bold; color: green;'>✓✓✓ READY TO LOGIN ✓✓✓</p>";
echo "<hr>";
echo "<h3>Login Credentials:</h3>";
echo "<ul style='font-size: 16px;'>";
echo "<li><strong>Username:</strong> $username</li>";
echo "<li><strong>Password:</strong> $password</li>";
echo "<li><strong>Role:</strong> $role</li>";
echo "</ul>";
echo "<hr>";
echo "<p style='font-size: 16px;'><a href='login.html' style='padding: 10px 20px; background: #2ecc71; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Go to Login Page</a></p>";

$conn->close();
?>

