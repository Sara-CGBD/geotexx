<?php
// Fix all test user passwords
$password = "Test@123";
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "<h2>ðŸ”§ Fixing All Test User Passwords</h2>";
echo "<p><strong>Password:</strong> {$password}</p>";
echo "<p><strong>New Hash:</strong> " . substr($hash, 0, 50) . "...</p>";
echo "<hr>";

// Connect to database
$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Update all test users
$test_users = ['planning_test', 'prod_test', 'qc_test', 'tester_test', 'checker_test', 'agm_test', 'finance_test', 'mgmt_test'];

echo "<h3>Updating Passwords...</h3>";
echo "<ul>";

foreach ($test_users as $username) {
    $stmt = $conn->prepare("UPDATE new_user SET password = ? WHERE username = ?");
    $stmt->bind_param("ss", $hash, $username);
    
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo "<li style='color: green;'>âœ… Updated: <strong>{$username}</strong></li>";
        } else {
            echo "<li style='color: orange;'>âš ï¸ User not found: <strong>{$username}</strong></li>";
        }
    } else {
        echo "<li style='color: red;'>âŒ Failed: <strong>{$username}</strong></li>";
    }
}

echo "</ul>";
echo "<hr>";

// Verify one user
echo "<h3>Verification Test:</h3>";
$stmt = $conn->prepare("SELECT username, password FROM new_user WHERE username = 'planning_test'");
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user && password_verify($password, $user['password'])) {
    echo "<p style='color: green; font-size: 18px; font-weight: bold;'>âœ… PASSWORD VERIFICATION SUCCESSFUL!</p>";
    echo "<p>All passwords have been fixed. You can now login with:</p>";
    echo "<div style='background: #ecf0f1; padding: 20px; border-radius: 5px; margin-top: 20px;'>";
    echo "<h3>Test Login Credentials:</h3>";
    echo "<ul>";
    foreach ($test_users as $username) {
        echo "<li><strong>Username:</strong> {$username} | <strong>Password:</strong> Test@123</li>";
    }
    echo "</ul>";
    echo "<p><a href='login.html' style='background: #3498db; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px;'>Go to Login Page</a></p>";
    echo "</div>";
} else {
    echo "<p style='color: red;'>âŒ Verification failed. Please contact support.</p>";
}

$conn->close();
?>



