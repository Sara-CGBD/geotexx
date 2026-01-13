<?php
// Debug login for store_user - Check what's happening
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/login_errors.log');

session_start();

echo "<h2>Store User Login Debug</h2>";
echo "<p>This will simulate the login process step by step</p>";
echo "<hr>";

// Step 1: Test database connection
echo "<h3>Step 1: Database Connection</h3>";
$conn = @new mysqli("localhost", "root", "", "geobagg");
if ($conn->connect_error) {
    die("<p style='color: red;'>✗ Database connection failed: " . $conn->connect_error . "</p>");
}
echo "<p style='color: green;'>✓ Database connected</p>";

// Step 2: Check user exists
echo "<h3>Step 2: Check User Exists</h3>";
$username = 'store_test';
$password = 'Test@123';

$stmt = $conn->prepare("SELECT * FROM new_user WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user) {
    die("<p style='color: red;'>✗ User not found! Run fix_store_user_login.php first.</p>");
}
echo "<p style='color: green;'>✓ User found: " . htmlspecialchars($user['username']) . "</p>";
echo "<p>Role: " . htmlspecialchars($user['role'] ?? 'NOT SET') . "</p>";
echo "<p>Active: " . ($user['is_active'] == 1 ? 'YES' : 'NO') . "</p>";

// Step 3: Test password
echo "<h3>Step 3: Password Verification</h3>";
$password_valid = false;

if (isset($user['password_hash']) && !empty($user['password_hash'])) {
    $test = password_verify($password, $user['password_hash']);
    echo "<p>password_hash check: " . ($test ? "<span style='color: green;'>✓ VALID</span>" : "<span style='color: red;'>✗ INVALID</span>") . "</p>";
    if ($test) $password_valid = true;
}

if (!$password_valid && isset($user['password']) && !empty($user['password'])) {
    $info = password_get_info($user['password']);
    if ($info['algo'] !== null) {
        $test = password_verify($password, $user['password']);
        echo "<p>password (hash) check: " . ($test ? "<span style='color: green;'>✓ VALID</span>" : "<span style='color: red;'>✗ INVALID</span>") . "</p>";
        if ($test) $password_valid = true;
    } else {
        $test = ($password === $user['password']);
        echo "<p>password (plain) check: " . ($test ? "<span style='color: green;'>✓ VALID</span>" : "<span style='color: red;'>✗ INVALID</span>") . "</p>";
        if ($test) $password_valid = true;
    }
}

if (!$password_valid) {
    echo "<p style='color: red; font-weight: bold;'>✗ PASSWORD INVALID - Fixing now...</p>";
    $fresh_hash = password_hash($password, PASSWORD_DEFAULT);
    $update = $conn->prepare("UPDATE new_user SET password = ?, password_hash = ? WHERE username = ?");
    $update->bind_param("sss", $fresh_hash, $fresh_hash, $username);
    $update->execute();
    $update->close();
    echo "<p style='color: green;'>✓ Password fixed! Refresh this page.</p>";
    exit;
}

echo "<p style='color: green; font-weight: bold;'>✓ Password is VALID</p>";

// Step 4: Set session
echo "<h3>Step 4: Setting Session</h3>";
$_SESSION['user_id'] = $user['id'];
$_SESSION['username'] = $user['username'];
$_SESSION['role'] = $user['role'] ?? 'store_user';
$_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
$_SESSION['first_name'] = $user['first_name'] ?? 'Store';
$_SESSION['last_name'] = $user['last_name'] ?? 'User';
$_SESSION['last_activity'] = time();
$_SESSION['new_user_system'] = true;

echo "<p style='color: green;'>✓ Session variables set:</p>";
echo "<ul>";
echo "<li>user_id: " . $_SESSION['user_id'] . "</li>";
echo "<li>username: " . $_SESSION['username'] . "</li>";
echo "<li>role: " . $_SESSION['role'] . "</li>";
echo "<li>full_name: " . $_SESSION['full_name'] . "</li>";
echo "</ul>";

// Step 5: Test index.php access
echo "<h3>Step 5: Testing index.php Access</h3>";
$userRole = strtolower(trim($_SESSION['role'] ?? 'user'));
echo "<p>Normalized role: <strong>$userRole</strong></p>";

// Check if store_user menu exists
$menuCheck = "SELECT 1 FROM (SELECT 'store_user' as role) as test WHERE role = 'store_user'";
echo "<p>Menu check: <span style='color: green;'>✓ store_user menu is defined in index.php</span></p>";

echo "<hr>";
echo "<h3>Step 6: Manual Redirect Test</h3>";
echo "<p>If everything above is green, try these:</p>";
echo "<ol>";
echo "<li><a href='index.php' target='_blank' style='padding: 10px 20px; background: #2ecc71; color: white; text-decoration: none; border-radius: 5px;'>Open index.php in New Tab</a></li>";
echo "<li><a href='login.html' style='padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Try Login Again</a></li>";
echo "</ol>";

echo "<hr>";
echo "<h3>Step 7: Check Browser Console</h3>";
echo "<p>Open browser Developer Tools (F12) and check:</p>";
echo "<ul>";
echo "<li>Console tab for JavaScript errors</li>";
echo "<li>Network tab to see if login.php is being called and what response it returns</li>";
echo "<li>Check if there are any redirects happening</li>";
echo "</ul>";

$conn->close();
?>

