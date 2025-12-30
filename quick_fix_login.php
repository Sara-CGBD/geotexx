<?php
// EMERGENCY FIX - Add test users to BOTH tables with fresh hash

$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$password = "Test@123";
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "<h2>ðŸ”§ EMERGENCY LOGIN FIX</h2>";
echo "<p>Password: <strong>{$password}</strong></p>";
echo "<p>Hash: " . substr($hash, 0, 50) . "...</p>";
echo "<hr>";

// Test the hash immediately
$test = password_verify($password, $hash);
echo "<p><strong>Hash Test:</strong> " . ($test ? "âœ… WORKS" : "âŒ FAILED") . "</p>";
echo "<hr>";

$test_users = [
    ['username' => 'planning_test', 'full_name' => 'Planning Test User', 'email' => 'planning@test.com', 'role' => 'planning_user'],
    ['username' => 'prod_test', 'full_name' => 'Production Test User', 'email' => 'prod@test.com', 'role' => 'production_user'],
    ['username' => 'qc_test', 'full_name' => 'QC Inspector Test', 'email' => 'qc@test.com', 'role' => 'qc_inspector'],
    ['username' => 'tester_test', 'full_name' => 'Lab Tester Test', 'email' => 'tester@test.com', 'role' => 'tester'],
];

echo "<h3>Fixing Users Table:</h3>";

foreach ($test_users as $user) {
    // Delete from users table first
    $conn->query("DELETE FROM users WHERE username = '{$user['username']}'");
    
    // Insert into users table
    $stmt = $conn->prepare("INSERT INTO users (username, full_name, password, role, email, status) VALUES (?, ?, ?, ?, ?, 'active')");
    $stmt->bind_param("sssss", $user['username'], $user['full_name'], $hash, $user['role'], $user['email']);
    
    if ($stmt->execute()) {
        echo "<p>âœ… Added to <strong>users</strong> table: {$user['username']}</p>";
    } else {
        echo "<p>âŒ Failed users: {$user['username']} - " . $conn->error . "</p>";
    }
    
    // Update new_user table
    $stmt2 = $conn->prepare("UPDATE new_user SET password = ? WHERE username = ?");
    $stmt2->bind_param("ss", $hash, $user['username']);
    
    if ($stmt2->execute()) {
        echo "<p>âœ… Updated <strong>new_user</strong> table: {$user['username']}</p>";
    }
    
    echo "<br>";
}

echo "<hr>";
echo "<h3>ðŸ§ª TESTING LOGIN NOW...</h3>";

// Test if it works
$test_username = 'planning_test';
$test_password = 'Test@123';

$stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
$stmt->bind_param("s", $test_username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user) {
    echo "<p>âœ… User found in <strong>users</strong> table</p>";
    echo "<p>Username: {$user['username']}</p>";
    echo "<p>Role: {$user['role']}</p>";
    echo "<p>Password Length: " . strlen($user['password']) . "</p>";
    
    $verify = password_verify($test_password, $user['password']);
    
    if ($verify) {
        echo "<div style='background: #2ecc71; color: white; padding: 30px; border-radius: 10px; margin-top: 20px; text-align: center;'>";
        echo "<h2>âœ… SUCCESS! LOGIN SHOULD WORK NOW!</h2>";
        echo "<p style='font-size: 20px;'><strong>Username:</strong> planning_test</p>";
        echo "<p style='font-size: 20px;'><strong>Password:</strong> Test@123</p>";
        echo "<p style='margin-top: 20px;'><a href='login.html' style='background: white; color: #2ecc71; padding: 15px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold; font-size: 18px;'>GO TO LOGIN PAGE NOW!</a></p>";
        echo "</div>";
    } else {
        echo "<p style='color: red; font-size: 20px;'>âŒ VERIFICATION STILL FAILED!</p>";
        echo "<p>This is a PHP version issue. Let me try different hash...</p>";
        
        // Try with BCRYPT explicitly
        $hash2 = password_hash($test_password, PASSWORD_BCRYPT, ['cost' => 10]);
        $conn->query("UPDATE users SET password = '$hash2' WHERE username = 'planning_test'");
        $conn->query("UPDATE new_user SET password = '$hash2' WHERE username = 'planning_test'");
        
        echo "<p>Updated with BCRYPT hash. <a href='javascript:location.reload()'>Refresh this page</a></p>";
    }
} else {
    echo "<p style='color: red;'>âŒ User not found in users table!</p>";
}

$conn->close();
?>



