<!DOCTYPE html>
<html>
<head>
    <title>Test Login Credentials</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 1200px; margin: 50px auto; padding: 20px; background: #f5f5f5; }
        h1 { color: #2c3e50; }
        table { width: 100%; border-collapse: collapse; background: white; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #3498db; color: white; }
        tr:hover { background: #f9f9f9; }
        .success { color: #27ae60; font-weight: bold; }
        .error { color: #e74c3c; font-weight: bold; }
        .info { background: #3498db; color: white; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .test-btn { background: #27ae60; color: white; padding: 8px 15px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
        .test-btn:hover { background: #229954; }
    </style>
</head>
<body>
    <div class="info">
        <h2>ðŸ” Role-Based Access Control - Test Login Credentials</h2>
        <p><strong>All test users have the password:</strong> <code>Test@123</code></p>
    </div>

    <h1>âœ… Test Users in Database</h1>

    <?php
    // Connect to database
    $conn = new mysqli("localhost", "root", "root123", "geobagg");
    
    if ($conn->connect_error) {
        echo "<p class='error'>âŒ Database Connection Failed: " . $conn->connect_error . "</p>";
        exit();
    }

    // Get all test users
    $query = "SELECT username, full_name, email, role, is_active, created_at FROM new_user WHERE username LIKE '%_test' ORDER BY username";
    $result = $conn->query($query);

    if ($result && $result->num_rows > 0) {
        echo "<table>";
        echo "<tr>
                <th>#</th>
                <th>Username</th>
                <th>Full Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Active</th>
                <th>Test Login</th>
              </tr>";
        
        $count = 1;
        while ($row = $result->fetch_assoc()) {
            $status = $row['is_active'] == 1 ? "<span class='success'>âœ… Active</span>" : "<span class='error'>âŒ Inactive</span>";
            $role_display = $row['role'] ?: '<span class="error">âŒ NO ROLE</span>';
            
            echo "<tr>";
            echo "<td>{$count}</td>";
            echo "<td><strong>{$row['username']}</strong></td>";
            echo "<td>{$row['full_name']}</td>";
            echo "<td>{$row['email']}</td>";
            echo "<td>{$role_display}</td>";
            echo "<td>{$status}</td>";
            echo "<td><a href='login.html' class='test-btn'>Test Login</a></td>";
            echo "</tr>";
            $count++;
        }
        
        echo "</table>";
        
        echo "<div style='margin-top: 30px; padding: 20px; background: #ecf0f1; border-radius: 5px;'>";
        echo "<h3>ðŸ“‹ How to Test:</h3>";
        echo "<ol>";
        echo "<li>Click 'Test Login' button for any user</li>";
        echo "<li>Use the <strong>username</strong> from the table</li>";
        echo "<li>Use password: <code><strong>Test@123</strong></code></li>";
        echo "<li>You should see a role-specific dashboard</li>";
        echo "</ol>";
        echo "</div>";
        
    } else {
        echo "<p class='error'>âŒ No test users found!</p>";
    }

    $conn->close();
    ?>

    <div style="margin-top: 30px; padding: 15px; background: #2ecc71; color: white; border-radius: 5px;">
        <h3>âœ… All Test Users Ready!</h3>
        <p>All users have been created successfully. You can now login and test the role-based access control system.</p>
        <p><strong>Common Password for all test users:</strong> <code style="background: rgba(0,0,0,0.2); padding: 5px 10px; border-radius: 3px;">Test@123</code></p>
    </div>

</body>
</html>



