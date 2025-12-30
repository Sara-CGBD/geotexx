<?php
// Fix QC Inspector and all other test users with correct passwords

$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$password = "Test@123";
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "<h1>ðŸ”§ Fixing All Test User Passwords</h1>";
echo "<p><strong>Password:</strong> Test@123</p>";
echo "<p><strong>Generated Hash:</strong> " . substr($hash, 0, 40) . "...</p>";
echo "<hr>";

// Test the hash works
$test = password_verify($password, $hash);
echo "<p><strong>Hash Test:</strong> " . ($test ? "âœ… WORKS" : "âŒ FAILED") . "</p>";
echo "<hr>";

$test_users = [
    ['username' => 'qc_test', 'full_name' => 'QC Inspector Test', 'email' => 'qc@test.com', 'role' => 'qc_inspector'],
    ['username' => 'planning_test', 'full_name' => 'Planning Test User', 'email' => 'planning@test.com', 'role' => 'planning_user'],
    ['username' => 'prod_test', 'full_name' => 'Production Test User', 'email' => 'prod@test.com', 'role' => 'production_user'],
    ['username' => 'tester_test', 'full_name' => 'Lab Tester Test', 'email' => 'tester@test.com', 'role' => 'tester'],
    ['username' => 'checker_test', 'full_name' => 'Checker Test', 'email' => 'checker@test.com', 'role' => 'checker'],
    ['username' => 'agm_test', 'full_name' => 'AGM Operations Test', 'email' => 'agm@test.com', 'role' => 'agm ops'],
    ['username' => 'finance_test', 'full_name' => 'Finance Test User', 'email' => 'finance@test.com', 'role' => 'finance_user'],
    ['username' => 'mgmt_test', 'full_name' => 'Management Test', 'email' => 'mgmt@test.com', 'role' => 'management'],
];

echo "<h3>Creating/Updating Users:</h3>";
echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>#</th><th>Username</th><th>Role</th><th>Users Table</th><th>New_User Table</th><th>Test Login</th></tr>";

$count = 1;
foreach ($test_users as $user) {
    // Delete from users table first
    $conn->query("DELETE FROM users WHERE username = '{$user['username']}'");
    
    // Insert into users table
    $stmt = $conn->prepare("INSERT INTO users (username, full_name, password, role, email, status) VALUES (?, ?, ?, ?, ?, 'active')");
    $stmt->bind_param("sssss", $user['username'], $user['full_name'], $hash, $user['role'], $user['email']);
    $users_result = $stmt->execute() ? "âœ…" : "âŒ " . $conn->error;
    
    // Update new_user table
    $stmt2 = $conn->prepare("UPDATE new_user SET password = ?, role = ? WHERE username = ?");
    $stmt2->bind_param("sss", $hash, $user['role'], $user['username']);
    $new_user_result = $stmt2->execute() ? "âœ…" : "âŒ";
    
    // Verify it works
    $verify_stmt = $conn->prepare("SELECT password FROM users WHERE username = ?");
    $verify_stmt->bind_param("s", $user['username']);
    $verify_stmt->execute();
    $verify_result = $verify_stmt->get_result();
    $verify_user = $verify_result->fetch_assoc();
    
    $verify_test = "âŒ";
    if ($verify_user && password_verify($password, $verify_user['password'])) {
        $verify_test = "âœ…";
    }
    
    echo "<tr>";
    echo "<td>{$count}</td>";
    echo "<td><strong>{$user['username']}</strong></td>";
    echo "<td>{$user['role']}</td>";
    echo "<td>{$users_result}</td>";
    echo "<td>{$new_user_result}</td>";
    echo "<td>{$verify_test}</td>";
    echo "</tr>";
    
    $count++;
}

echo "</table>";

echo "<div style='background: #2ecc71; color: white; padding: 40px; margin-top: 30px; border-radius: 10px; text-align: center;'>";
echo "<h1>âœ… ALL USERS FIXED!</h1>";
echo "<h2>Password for ALL users: Test@123</h2>";
echo "<table style='margin: 30px auto; color: white; text-align: left;'>";
echo "<tr><td style='padding: 5px 20px;'><strong>QC Inspector:</strong></td><td style='padding: 5px 20px;'>qc_test / Test@123</td></tr>";
echo "<tr><td style='padding: 5px 20px;'><strong>Planning:</strong></td><td style='padding: 5px 20px;'>planning_test / Test@123</td></tr>";
echo "<tr><td style='padding: 5px 20px;'><strong>Production:</strong></td><td style='padding: 5px 20px;'>prod_test / Test@123</td></tr>";
echo "<tr><td style='padding: 5px 20px;'><strong>Tester:</strong></td><td style='padding: 5px 20px;'>tester_test / Test@123</td></tr>";
echo "<tr><td style='padding: 5px 20px;'><strong>Checker:</strong></td><td style='padding: 5px 20px;'>checker_test / Test@123</td></tr>";
echo "<tr><td style='padding: 5px 20px;'><strong>AGM Ops:</strong></td><td style='padding: 5px 20px;'>agm_test / Test@123</td></tr>";
echo "<tr><td style='padding: 5px 20px;'><strong>Finance:</strong></td><td style='padding: 5px 20px;'>finance_test / Test@123</td></tr>";
echo "<tr><td style='padding: 5px 20px;'><strong>Management:</strong></td><td style='padding: 5px 20px;'>mgmt_test / Test@123</td></tr>";
echo "</table>";
echo "<a href='login.html' style='background: white; color: #2ecc71; padding: 20px 40px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 20px; display: inline-block; margin-top: 20px;'>GO TO LOGIN PAGE</a>";
echo "</div>";

$conn->close();
?>



