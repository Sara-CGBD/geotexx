<?php
$conn = new mysqli("localhost", "root", "root123", "geobagg");
$password_to_test = "Test@123";
$username = "planning_test";

// Get from users table (which login.php checks FIRST)
$stmt = $conn->prepare("SELECT username, password, role FROM users WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

echo "<div style='font-family: Arial; padding: 40px; background: #f5f5f5;'>";
echo "<h1>ðŸ§ª Final Login Test</h1>";

if ($user) {
    echo "<div style='background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px;'>";
    echo "<h3>User Found in 'users' Table:</h3>";
    echo "<p><strong>Username:</strong> {$user['username']}</p>";
    echo "<p><strong>Role:</strong> {$user['role']}</p>";
    echo "<p><strong>Password Hash:</strong> " . substr($user['password'], 0, 50) . "...</p>";
    echo "</div>";
    
    $verify = password_verify($password_to_test, $user['password']);
    
    if ($verify) {
        echo "<div style='background: #2ecc71; color: white; padding: 40px; border-radius: 10px; text-align: center;'>";
        echo "<h1>âœ… SUCCESS!</h1>";
        echo "<h2>LOGIN WILL WORK NOW!</h2>";
        echo "<p style='font-size: 24px; margin: 20px 0;'><strong>Username:</strong> planning_test</p>";
        echo "<p style='font-size: 24px; margin: 20px 0;'><strong>Password:</strong> Test@123</p>";
        echo "<a href='login.html' style='background: white; color: #2ecc71; padding: 20px 40px; text-decoration: none; border-radius: 5px; font-size: 20px; font-weight: bold; display: inline-block; margin-top: 20px;'>CLICK HERE TO LOGIN</a>";
        echo "</div>";
    } else {
        echo "<div style='background: #e74c3c; color: white; padding: 30px; border-radius: 10px;'>";
        echo "<h2>âŒ Password Verification Failed</h2>";
        echo "<p>Checking login.php for issues...</p>";
        echo "</div>";
        
        // Check what password_get_info says
        $info = password_get_info($user['password']);
        echo "<div style='background: white; padding: 20px; border-radius: 10px; margin-top: 20px;'>";
        echo "<h3>Password Hash Info:</h3>";
        echo "<pre>" . print_r($info, true) . "</pre>";
        echo "</div>";
    }
} else {
    echo "<div style='background: #e74c3c; color: white; padding: 30px; border-radius: 10px;'>";
    echo "<h2>âŒ User Not Found in 'users' Table</h2>";
    echo "<p>This is the problem. Login.php checks 'users' table first!</p>";
    echo "</div>";
}

$conn->close();
echo "</div>";
?>



