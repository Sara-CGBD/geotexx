<?php
// Verify password hash
$password = "Test@123";

// Generate new hash
$new_hash = password_hash($password, PASSWORD_DEFAULT);

echo "<h2>Password Verification</h2>";
echo "<p><strong>Testing Password:</strong> {$password}</p>";
echo "<p><strong>New Generated Hash:</strong> {$new_hash}</p>";
echo "<hr>";

// Connect to database
$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get user from database
$stmt = $conn->prepare("SELECT username, password FROM new_user WHERE username = ?");
$username = "planning_test";
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user) {
    echo "<h3>User Found: {$user['username']}</h3>";
    echo "<p><strong>Stored Hash:</strong> " . substr($user['password'], 0, 50) . "...</p>";
    
    // Test verification
    $verify = password_verify($password, $user['password']);
    
    if ($verify) {
        echo "<p style='color: green; font-size: 20px; font-weight: bold;'>âœ… PASSWORD VERIFICATION SUCCESSFUL!</p>";
        echo "<p>The password 'Test@123' works correctly with the stored hash.</p>";
    } else {
        echo "<p style='color: red; font-size: 20px; font-weight: bold;'>âŒ PASSWORD VERIFICATION FAILED!</p>";
        echo "<p>The stored hash doesn't match. Need to update it.</p>";
        
        // Update with correct hash
        echo "<hr>";
        echo "<h3>Fixing Password Hash...</h3>";
        
        $update_stmt = $conn->prepare("UPDATE new_user SET password = ? WHERE username = ?");
        $update_stmt->bind_param("ss", $new_hash, $username);
        
        if ($update_stmt->execute()) {
            echo "<p style='color: green;'>âœ… Password hash updated successfully!</p>";
            echo "<p><strong>Try logging in again with:</strong></p>";
            echo "<ul>";
            echo "<li>Username: planning_test</li>";
            echo "<li>Password: Test@123</li>";
            echo "</ul>";
        } else {
            echo "<p style='color: red;'>âŒ Failed to update password</p>";
        }
    }
} else {
    echo "<p style='color: red;'>âŒ User not found!</p>";
}

$conn->close();
?>



