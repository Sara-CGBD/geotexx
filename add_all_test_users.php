<?php
// Add all provided test users to BOTH tables with correct password hashes
// Database: geobagg (default XAMPP: root user, no password)

$conn = new mysqli("localhost", "root", "", "geobagg");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$existingColumns = [];
$colsResult = $conn->query("SHOW COLUMNS FROM new_user");
if ($colsResult) {
    while ($row = $colsResult->fetch_assoc()) {
        $existingColumns[] = $row['Field'];
    }
}
$hasColumn = function ($name) use ($existingColumns) {
    return in_array($name, $existingColumns, true);
};

$test_users = [
    ['username' => 'planning_test', 'full_name' => 'Planning Test User', 'email' => 'planning@test.com', 'role' => 'planning_user', 'password' => 'Test@123'],
    ['username' => 'prod_test',     'full_name' => 'Production Test User', 'email' => 'prod@test.com',     'role' => 'production_user', 'password' => 'Test@123'],
    ['username' => 'qc_test',       'full_name' => 'QC Inspector Test',    'email' => 'qc@test.com',       'role' => 'qc_inspector',    'password' => 'qc123'],
    ['username' => 'tester_test',   'full_name' => 'Lab Tester Test',      'email' => 'tester@test.com',   'role' => 'tester',          'password' => 'tester123'],
    ['username' => 'checker_test',  'full_name' => 'Checker Test',         'email' => 'checker@test.com',  'role' => 'checker',         'password' => 'Test@123'],
    ['username' => 'agm_test',      'full_name' => 'AGM Operations Test',  'email' => 'agm@test.com',      'role' => 'agm ops',         'password' => 'Test@123'],
    ['username' => 'finance_test',  'full_name' => 'Finance Test User',    'email' => 'finance@test.com',  'role' => 'finance_user',    'password' => 'Test@123'],
    ['username' => 'mgmt_test',     'full_name' => 'Management Test',      'email' => 'mgmt@test.com',     'role' => 'management',      'password' => 'Test@123'],
    ['username' => 'admin',         'full_name' => 'System Admin',         'email' => 'admin@geotex.local','role' => 'admin',           'password' => 'admin123'],
];

echo "<h2>Adding All Test Users</h2>";
echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
echo "<tr><th>Username</th><th>Role</th><th>Users Table</th><th>new_user Table</th></tr>";

foreach ($test_users as $user) {
    $hash = password_hash($user['password'], PASSWORD_DEFAULT);
    $username = $user['username'];
    $full_name = $user['full_name'];
    $email = $user['email'];
    $role = $user['role'];
    $first_name = $full_name;
    $last_name = '';

    // Upsert into users table
    $stmt = $conn->prepare("INSERT INTO users (username, full_name, password, role, email, status) VALUES (?, ?, ?, ?, ?, 'active')
        ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), password = VALUES(password), role = VALUES(role), email = VALUES(email), status = 'active'");
    $stmt->bind_param("sssss", $username, $full_name, $hash, $role, $email);
    $users_result = $stmt->execute() ? "✅" : "❌";
    $stmt->close();

    // Upsert into new_user table (handle schemas with either password_hash or password)
    $columns = ['username'];
    $placeholders = ['?'];
    $types = 's';
    $values = [$username];
    $updates = [];

    $passwordColumn = $hasColumn('password_hash') ? 'password_hash' : ($hasColumn('password') ? 'password' : null);
    if ($passwordColumn) {
        $columns[] = $passwordColumn;
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $hash;
        $updates[] = "$passwordColumn = VALUES($passwordColumn)";
    }
    if ($hasColumn('email')) {
        $columns[] = 'email';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $email;
        $updates[] = "email = VALUES(email)";
    }
    if ($hasColumn('first_name')) {
        $columns[] = 'first_name';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $first_name;
        $updates[] = "first_name = VALUES(first_name)";
    }
    if ($hasColumn('last_name')) {
        $columns[] = 'last_name';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $last_name;
        $updates[] = "last_name = VALUES(last_name)";
    }
    if ($hasColumn('role')) {
        $columns[] = 'role';
        $placeholders[] = '?';
        $types .= 's';
        $values[] = $role;
        $updates[] = "role = VALUES(role)";
    }
    if ($hasColumn('is_active')) {
        $columns[] = 'is_active';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = 1;
        $updates[] = "is_active = VALUES(is_active)";
    }
    if ($hasColumn('wrong_attempts')) {
        $columns[] = 'wrong_attempts';
        $placeholders[] = '?';
        $types .= 'i';
        $values[] = 0;
        $updates[] = "wrong_attempts = VALUES(wrong_attempts)";
    }
    if ($hasColumn('lock_until')) {
        $columns[] = 'lock_until';
        $placeholders[] = 'NULL';
        // lock_until set to NULL directly in VALUES list
    }
    if ($hasColumn('last_activity')) {
        $columns[] = 'last_activity';
        $placeholders[] = 'NOW()';
        $updates[] = "last_activity = NOW()";
    }

    $valuesSection = [];
    foreach ($placeholders as $ph) {
        $valuesSection[] = ($ph === 'NULL' || $ph === 'NOW()') ? $ph : '?';
    }

    $sql = "INSERT INTO new_user (" . implode(',', $columns) . ") VALUES (" . implode(',', $valuesSection) . ")";
    if (!empty($updates)) {
        $sql .= " ON DUPLICATE KEY UPDATE " . implode(', ', $updates);
    }

    $stmt2 = $conn->prepare($sql);
    if ($stmt2 === false) {
        $new_user_result = "❌";
    } else {
        // Filter values to those with actual '?' placeholders
        $bindValues = [];
        $bindTypes = '';
        $valueIndex = 0;
        foreach ($placeholders as $phIndex => $ph) {
            if ($ph === '?' ) {
                $bindTypes .= $types[$valueIndex];
                $bindValues[] = $values[$valueIndex];
            }
            $valueIndex++;
        }
        if ($bindTypes !== '') {
            $stmt2->bind_param($bindTypes, ...$bindValues);
        }
    $new_user_result = $stmt2->execute() ? "✅" : "❌";
        $stmt2->close();
    }
    
    echo "<tr>";
    echo "<td><strong>{$username}</strong></td>";
    echo "<td>{$role}</td>";
    echo "<td>{$users_result}</td>";
    echo "<td>{$new_user_result}</td>";
    echo "</tr>";
}

echo "</table>";

echo "<div style='background: #2ecc71; color: white; padding: 30px; margin-top: 30px; border-radius: 10px; text-align: center;'>";
echo "<h2>✅ All Test Users Created!</h2>";
echo "<p style='font-size: 16px; line-height: 1.6;'>Use the supplied credentials for each role.</p>";
echo "<a href='login.html' style='background: white; color: #2ecc71; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 18px; display: inline-block; margin-top: 20px;'>GO TO LOGIN PAGE</a>";
echo "</div>";

$conn->close();
?>
