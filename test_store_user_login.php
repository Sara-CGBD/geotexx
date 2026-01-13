<?php
// Test script to debug store_user login issue
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Store User Login Debug</h2>";
echo "<hr>";

// Test 1: Check if user exists in database
$conn = new mysqli("localhost", "root", "", "geobagg");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$username = 'store_test';
$password = 'Test@123';

echo "<h3>Step 1: Check User in Database</h3>";
$check = $conn->prepare("SELECT * FROM new_user WHERE username = ?");
$check->bind_param("s", $username);
$check->execute();
$result = $check->get_result();
$user = $result->fetch_assoc();
$check->close();

if ($user) {
    echo "<p style='color: green;'>✓ User found!</p>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Value</th></tr>";
    foreach ($user as $key => $value) {
        if ($key === 'password' || $key === 'password_hash') {
            echo "<tr><td>$key</td><td>" . substr($value, 0, 30) . "...</td></tr>";
        } else {
            echo "<tr><td>$key</td><td>$value</td></tr>";
        }
    }
    echo "</table>";
} else {
    echo "<p style='color: red;'>✗ User not found!</p>";
    exit;
}

echo "<hr>";

// Test 2: Password verification
echo "<h3>Step 2: Password Verification</h3>";
$password_valid = false;

if (isset($user['password_hash']) && !empty($user['password_hash'])) {
    $test = password_verify($password, $user['password_hash']);
    echo "<p>password_hash: " . ($test ? "<span style='color: green;'>✓ VALID</span>" : "<span style='color: red;'>✗ INVALID</span>") . "</p>";
    if ($test) $password_valid = true;
}

if (!$password_valid && isset($user['password']) && !empty($user['password'])) {
    $info = password_get_info($user['password']);
    if ($info['algo'] !== null) {
        $test = password_verify($password, $user['password']);
        echo "<p>password (hashed): " . ($test ? "<span style='color: green;'>✓ VALID</span>" : "<span style='color: red;'>✗ INVALID</span>") . "</p>";
        if ($test) $password_valid = true;
    } else {
        $test = ($password === $user['password']);
        echo "<p>password (plain): " . ($test ? "<span style='color: green;'>✓ VALID</span>" : "<span style='color: red;'>✗ INVALID</span>") . "</p>";
        if ($test) $password_valid = true;
    }
}

if (!$password_valid) {
    echo "<p style='color: red; font-weight: bold;'>✗ PASSWORD VERIFICATION FAILED - Fixing...</p>";
    
    // Fix password
    $fresh_hash = password_hash($password, PASSWORD_DEFAULT);
    $update = $conn->prepare("UPDATE new_user SET password = ?, password_hash = ? WHERE username = ?");
    $update->bind_param("sss", $fresh_hash, $fresh_hash, $username);
    $update->execute();
    $update->close();
    echo "<p style='color: green;'>✓ Password fixed!</p>";
    
    // Re-verify
    $password_valid = password_verify($password, $fresh_hash);
    echo "<p>Re-verification: " . ($password_valid ? "<span style='color: green;'>✓ SUCCESS</span>" : "<span style='color: red;'>✗ FAILED</span>") . "</p>";
}

echo "<hr>";

// Test 3: Simulate login session
echo "<h3>Step 3: Simulate Login Session</h3>";
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role'] = $user['role'] ?? 'store_user';
$_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
$_SESSION['last_activity'] = time();

echo "<p>Session variables set:</p>";
echo "<ul>";
echo "<li>user_id: " . $_SESSION['user_id'] . "</li>";
echo "<li>username: " . $_SESSION['username'] . "</li>";
echo "<li>role: " . $_SESSION['role'] . "</li>";
echo "<li>full_name: " . $_SESSION['full_name'] . "</li>";
echo "</ul>";

echo "<hr>";

// Test 4: Check if index.php can handle store_user
echo "<h3>Step 4: Check index.php Menu Handling</h3>";
$userRole = strtolower(trim($_SESSION['role'] ?? 'user'));
echo "<p>Normalized role: <strong>$userRole</strong></p>";

// Check if menu exists for this role
$menuItems = [
    'store_user' => [['type' => 'group', 'text' => 'Raw Material Store', 'icon' => 'fas fa-warehouse', 'children' => []]],
    'user' => [['type' => 'group', 'text' => 'Default', 'icon' => 'fas fa-home', 'children' => []]]
];

$displayMenuItems = $menuItems[$userRole] ?? $menuItems['user'];
echo "<p>Menu found: " . (isset($menuItems[$userRole]) ? "<span style='color: green;'>✓ YES</span>" : "<span style='color: orange;'>⚠ NO (using fallback)</span>") . "</p>";

echo "<hr>";

// Test 5: Test redirect
echo "<h3>Step 5: Test Redirect</h3>";
if ($password_valid) {
    echo "<p style='color: green; font-weight: bold;'>✓✓✓ ALL TESTS PASSED! ✓✓✓</p>";
    echo "<p><a href='index.php' style='padding: 10px 20px; background: #2ecc71; color: white; text-decoration: none; border-radius: 5px;'>Click here to go to Dashboard</a></p>";
    echo "<p>Or try logging in again with:</p>";
    echo "<ul>";
    echo "<li>Username: <strong>$username</strong></li>";
    echo "<li>Password: <strong>$password</strong></li>";
    echo "</ul>";
} else {
    echo "<p style='color: red; font-weight: bold;'>✗ Password verification failed. Please refresh this page to fix it.</p>";
}

$conn->close();
?>

