<?php
// Add new user script
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Prevent mysqli from throwing exceptions on duplicate keys
if (function_exists('mysqli_report')) { mysqli_report(MYSQLI_REPORT_OFF); }

echo "<h2>Adding New User</h2>";

// Database connection
$conn = new mysqli("localhost", "root", "root123", "geobagg");

if ($conn->connect_error) {
    echo "âŒ Database connection failed: " . $conn->connect_error;
    exit;
}

echo "âœ… Database connected successfully<br>";

// Create a new user
$username = 'testuser';
$password = 'test123';
$email = 'test@geocil.com';
$first_name = 'Test';
$last_name = 'User';
$role = 'admin';
$is_active = 1;

// Store password as plain text per current system policy
$stored_password = $password;

// Insert the user (ignore if already exists)
$stmt = $conn->prepare("INSERT IGNORE INTO new_user (username, password, email, first_name, last_name, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssssi", $username, $stored_password, $email, $first_name, $last_name, $role, $is_active);
if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo "âœ… New user created successfully!<br>";
        echo "Username: <strong>$username</strong><br>";
        echo "Password: <strong>$password</strong><br>";
        echo "Role: <strong>$role</strong><br>";
        echo "Email: <strong>$email</strong><br>";
    } else {
        echo "â„¹ï¸ User '<strong>$username</strong>' already exists. Skipping creation.<br>";
    }
} else {
    echo "âŒ Failed to create user: " . htmlspecialchars($stmt->error) . "<br>";
}

// Also create/update admin user
$admin_password = 'admin123';
$admin_stored = $admin_password;

// Check if admin exists
$result = $conn->query("SELECT id FROM new_user WHERE username = 'admin'");
if ($result->num_rows > 0) {
    // Update existing admin
    $stmt = $conn->prepare("UPDATE new_user SET password = ? WHERE username = 'admin'");
    $stmt->bind_param("s", $admin_stored);
    if ($stmt->execute()) {
        echo "âœ… Admin user password updated!<br>";
    } else {
        echo "âŒ Failed to update admin: " . $stmt->error . "<br>";
    }
} else {
    // Create new admin (ignore if exists)
    $adminUser = 'admin';
    $adminEmail = 'admin@geocil.com';
    $adminFN = 'Admin';
    $adminLN = 'User';
    $adminRole = 'admin';
    $active = 1;
    $stmt = $conn->prepare("INSERT IGNORE INTO new_user (username, password, email, first_name, last_name, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssssi", $adminUser, $admin_stored, $adminEmail, $adminFN, $adminLN, $adminRole, $active);
    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            echo "âœ… Admin user created!<br>";
        } else {
            echo "â„¹ï¸ Admin user already exists. Skipping creation.<br>";
        }
    } else {
        echo "âŒ Failed to create admin: " . htmlspecialchars($stmt->error) . "<br>";
    }
}

// Show all users
echo "<br><h3>All Users in Database:</h3>";
$result = $conn->query("SELECT username, email, role, is_active FROM new_user ORDER BY id");
if ($result) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Username</th><th>Email</th><th>Role</th><th>Active</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['username'] . "</td>";
        echo "<td>" . $row['email'] . "</td>";
        echo "<td>" . $row['role'] . "</td>";
        echo "<td>" . ($row['is_active'] ? 'Yes' : 'No') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

$conn->close();

echo "<br><h3>Login Credentials:</h3>";
echo "<strong>Option 1:</strong> Username: <code>testuser</code> | Password: <code>test123</code><br>";
echo "<strong>Option 2:</strong> Username: <code>admin</code> | Password: <code>admin123</code><br>";
echo "<br><a href='login.html' style='background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Try Login Now</a>";
?>


