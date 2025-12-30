<?php
// Generate a working password hash and update the database

$password = "Test@123";

echo "<h2>ðŸ” Generating Fresh Password Hash</h2>";
echo "<p><strong>Password:</strong> {$password}</p>";
echo "<hr>";

// Generate multiple hashes to test
echo "<h3>Testing Hash Generation:</h3>";
for ($i = 1; $i <= 3; $i++) {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $verify = password_verify($password, $hash);
    echo "<p><strong>Hash {$i}:</strong> " . substr($hash, 0, 60) . "...</p>";
    echo "<p><strong>Verification:</strong> " . ($verify ? "âœ… SUCCESS" : "âŒ FAILED") . "</p>";
    echo "<hr>";
}

// Use the last generated hash
$final_hash = password_hash($password, PASSWORD_DEFAULT);

// Connect to database
$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h3>ðŸ“Š Current Database Status:</h3>";

// Check current password for planning_test
$stmt = $conn->prepare("SELECT username, password FROM new_user WHERE username = 'planning_test'");
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user) {
    echo "<p><strong>Username:</strong> {$user['username']}</p>";
    echo "<p><strong>Current Hash:</strong> " . substr($user['password'], 0, 70) . "...</p>";
    echo "<p><strong>Current Hash Length:</strong> " . strlen($user['password']) . "</p>";
    
    $current_verify = password_verify($password, $user['password']);
    echo "<p><strong>Current Hash Works:</strong> " . ($current_verify ? "âœ… YES" : "âŒ NO") . "</p>";
    
    if (!$current_verify) {
        echo "<hr>";
        echo "<h3>ðŸ”§ Updating Password...</h3>";
        
        // Update with new hash
        $update_stmt = $conn->prepare("UPDATE new_user SET password = ? WHERE username = 'planning_test'");
        $update_stmt->bind_param("s", $final_hash);
        
        if ($update_stmt->execute()) {
            echo "<p style='color: green; font-size: 18px;'>âœ… Password updated successfully!</p>";
            
            // Verify the update
            $verify_stmt = $conn->prepare("SELECT password FROM new_user WHERE username = 'planning_test'");
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();
            $updated_user = $verify_result->fetch_assoc();
            
            $updated_verify = password_verify($password, $updated_user['password']);
            echo "<p><strong>New Hash Verification:</strong> " . ($updated_verify ? "âœ… SUCCESS" : "âŒ FAILED") . "</p>";
            
            if ($updated_verify) {
                echo "<div style='background: #2ecc71; color: white; padding: 20px; border-radius: 5px; margin-top: 20px;'>";
                echo "<h3>âœ… PASSWORD IS NOW WORKING!</h3>";
                echo "<p><strong>Username:</strong> planning_test</p>";
                echo "<p><strong>Password:</strong> Test@123</p>";
                echo "<p><a href='login.html' style='background: white; color: #2ecc71; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px; font-weight: bold;'>Go to Login Page</a></p>";
                echo "</div>";
                
                // Update all other test users
                echo "<hr>";
                echo "<h3>Updating All Test Users...</h3>";
                $test_users = ['prod_test', 'qc_test', 'tester_test', 'checker_test', 'agm_test', 'finance_test', 'mgmt_test'];
                
                foreach ($test_users as $test_username) {
                    $bulk_update = $conn->prepare("UPDATE new_user SET password = ? WHERE username = ?");
                    $bulk_update->bind_param("ss", $final_hash, $test_username);
                    if ($bulk_update->execute()) {
                        echo "<p>âœ… Updated: {$test_username}</p>";
                    }
                }
                
                echo "<p style='color: green; font-weight: bold; margin-top: 20px;'>All test users updated! Password: Test@123</p>";
            }
        } else {
            echo "<p style='color: red;'>âŒ Failed to update password</p>";
        }
    } else {
        echo "<div style='background: #2ecc71; color: white; padding: 20px; border-radius: 5px; margin-top: 20px;'>";
        echo "<h3>âœ… PASSWORD IS ALREADY WORKING!</h3>";
        echo "<p><strong>Username:</strong> planning_test</p>";
        echo "<p><strong>Password:</strong> Test@123</p>";
        echo "<p><a href='login.html' style='background: white; color: #2ecc71; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 10px; font-weight: bold;'>Go to Login Page</a></p>";
        echo "</div>";
    }
}

$conn->close();
?>



