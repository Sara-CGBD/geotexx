<?php
session_start();

$username = "planning_test";
$password = "Test@123";

echo "<h1>ðŸ” Login Debug</h1>";
echo "<div style='font-family: monospace; background: #f5f5f5; padding: 20px;'>";

// Connect to database
$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h3>Step 1: Checking 'users' table first (login.php checks this FIRST)</h3>";

$tables_to_try = ['users', 'new_user'];
$user = null;
$found_in = null;

foreach ($tables_to_try as $table) {
    $stmt = $conn->prepare("SELECT * FROM $table WHERE username = ?");
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $found_in = $table;
            echo "<p style='color: green;'>âœ… User found in table: <strong>{$table}</strong></p>";
            break;
        } else {
            echo "<p style='color: orange;'>âš ï¸ User NOT found in table: <strong>{$table}</strong></p>";
        }
    }
}

if (!$user) {
    echo "<div style='background: #e74c3c; color: white; padding: 20px; margin-top: 20px;'>";
    echo "<h2>âŒ USER NOT FOUND IN ANY TABLE!</h2>";
    echo "<p>Username '{$username}' does not exist in 'users' or 'new_user' tables.</p>";
    echo "</div>";
    exit();
}

echo "<hr>";
echo "<h3>Step 2: User Details</h3>";
echo "<pre>";
echo "Found in table: {$found_in}\n";
echo "Username: {$user['username']}\n";
echo "Role: " . ($user['role'] ?? 'NOT SET') . "\n";
echo "Email: " . ($user['email'] ?? 'NOT SET') . "\n";
echo "Is Active: " . ($user['is_active'] ?? $user['status'] ?? 'NOT SET') . "\n";
echo "Password Hash: " . substr($user['password'], 0, 60) . "...\n";
echo "Password Length: " . strlen($user['password']) . "\n";
echo "</pre>";

echo "<hr>";
echo "<h3>Step 3: Password Verification</h3>";

// Check if account is active
if (isset($user['is_active']) && $user['is_active'] == 0) {
    echo "<p style='color: red;'>âŒ Account is INACTIVE!</p>";
}

if (isset($user['status']) && $user['status'] != 'active') {
    echo "<p style='color: red;'>âŒ Account status is: {$user['status']}</p>";
}

// Check if locked
if (isset($user['lock_until']) && $user['lock_until'] && strtotime($user['lock_until']) > time()) {
    echo "<p style='color: red;'>âŒ Account is LOCKED until: {$user['lock_until']}</p>";
}

echo "<p>Testing password: <strong>{$password}</strong></p>";

// Get password hash info
$hash_info = password_get_info($user['password']);
echo "<p><strong>Hash Algorithm:</strong> " . ($hash_info['algo'] ?? 'unknown') . "</p>";
echo "<p><strong>Hash Algorithm Name:</strong> " . ($hash_info['algoName'] ?? 'unknown') . "</p>";

// Try verification
$password_valid = false;

// Try password_hash column first (some tables have this)
if (isset($user['password_hash']) && !empty($user['password_hash'])) {
    echo "<p>Trying 'password_hash' column...</p>";
    $password_valid = password_verify($password, $user['password_hash']);
    echo "<p>Result: " . ($password_valid ? "âœ… SUCCESS" : "âŒ FAILED") . "</p>";
}

// Try password column
if (!$password_valid && isset($user['password']) && !empty($user['password'])) {
    echo "<p>Trying 'password' column...</p>";
    
    // Check if it's hashed
    if (password_get_info($user['password'])['algo'] !== null) {
        echo "<p>Password is HASHED (bcrypt)</p>";
        $password_valid = password_verify($password, $user['password']);
        echo "<p>Result: " . ($password_valid ? "âœ… SUCCESS" : "âŒ FAILED") . "</p>";
    } else {
        echo "<p>Password might be PLAIN TEXT</p>";
        $password_valid = ($password === $user['password']);
        echo "<p>Result: " . ($password_valid ? "âœ… SUCCESS" : "âŒ FAILED") . "</p>";
    }
}

echo "<hr>";

if ($password_valid) {
    echo "<div style='background: #2ecc71; color: white; padding: 30px; border-radius: 10px; text-align: center;'>";
    echo "<h1>âœ… PASSWORD IS CORRECT!</h1>";
    echo "<h2>Login should work!</h2>";
    echo "<p>But there might be another issue...</p>";
    echo "</div>";
} else {
    echo "<div style='background: #e74c3c; color: white; padding: 30px; border-radius: 10px;'>";
    echo "<h1>âŒ PASSWORD VERIFICATION FAILED</h1>";
    echo "<p>The password 'Test@123' does NOT match the stored hash.</p>";
    echo "<h3>FIX:</h3>";
    
    // Generate new hash
    $new_hash = password_hash($password, PASSWORD_DEFAULT);
    echo "<p>Generated new hash: " . substr($new_hash, 0, 60) . "...</p>";
    
    // Test new hash immediately
    $test = password_verify($password, $new_hash);
    echo "<p>New hash test: " . ($test ? "âœ… WORKS" : "âŒ FAILED") . "</p>";
    
    // Update database
    if ($test) {
        $update = $conn->prepare("UPDATE {$found_in} SET password = ? WHERE username = ?");
        $update->bind_param("ss", $new_hash, $username);
        
        if ($update->execute()) {
            echo "<p style='font-size: 20px; font-weight: bold; margin-top: 20px;'>âœ… PASSWORD UPDATED IN DATABASE!</p>";
            echo "<p><a href='debug_login.php' style='background: white; color: #e74c3c; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>REFRESH TO TEST AGAIN</a></p>";
        } else {
            echo "<p>âŒ Failed to update: " . $conn->error . "</p>";
        }
    }
    
    echo "</div>";
}

// Also update both tables to be safe
if ($password_valid) {
    $new_hash = $user['password'];
    echo "<hr><h3>Ensuring BOTH tables have the correct password...</h3>";
    
    $conn->query("UPDATE users SET password = '$new_hash' WHERE username = '$username'");
    $conn->query("UPDATE new_user SET password = '$new_hash' WHERE username = '$username'");
    
    echo "<p>âœ… Both tables updated!</p>";
    echo "<div style='background: #3498db; color: white; padding: 20px; margin-top: 20px; text-align: center;'>";
    echo "<h2>NOW TRY LOGGING IN:</h2>";
    echo "<p style='font-size: 20px;'><strong>Username:</strong> planning_test</p>";
    echo "<p style='font-size: 20px;'><strong>Password:</strong> Test@123</p>";
    echo "<p><a href='login.html' style='background: white; color: #3498db; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 18px; display: inline-block; margin-top: 20px;'>GO TO LOGIN PAGE</a></p>";
    echo "</div>";
}

echo "</div>";
$conn->close();
?>



