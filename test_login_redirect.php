<?php
// Simple test to check if login redirect works
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Login Redirect Test</h2>";

// Simulate successful login
$conn = new mysqli("localhost", "root", "", "geobagg");
$username = 'store_test';

$stmt = $conn->prepare("SELECT * FROM new_user WHERE username = ?");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if ($user) {
    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'] ?? 'store_user';
    $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
    $_SESSION['last_activity'] = time();
    
    echo "<p style='color: green;'>✓ Session set successfully!</p>";
    echo "<p>Session data:</p>";
    echo "<pre>";
    print_r($_SESSION);
    echo "</pre>";
    
    echo "<hr>";
    echo "<p><a href='index.php' style='padding: 10px 20px; background: #2ecc71; color: white; text-decoration: none; border-radius: 5px; font-size: 18px;'>Click to Test index.php</a></p>";
    
    // Also try automatic redirect
    echo "<p>Auto-redirecting in 2 seconds...</p>";
    echo "<script>setTimeout(function(){ window.location.href = 'index.php'; }, 2000);</script>";
} else {
    echo "<p style='color: red;'>✗ User not found!</p>";
}

$conn->close();
?>

