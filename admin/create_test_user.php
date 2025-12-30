<?php
// Create a test user for login testing
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Creating Test User</h2>";

// Database connection
$conn = new mysqli("localhost", "root", "root123", "geobagg");

if ($conn->connect_error) {
    echo "âŒ Database connection failed: " . $conn->connect_error;
    exit;
}

echo "âœ… Database connected successfully<br>";

// Create users table if it doesn't exist
$create_users_table = "
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    role ENUM('admin', 'production', 'scrap', 'fg', 'recycle', 'planning', 'qc', 'finance', 'management', 'user') DEFAULT 'user',
    is_active TINYINT(1) DEFAULT 1,
    wrong_attempts INT DEFAULT 0,
    lock_until DATETIME NULL,
    last_login DATETIME NULL,
    last_activity DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

if ($conn->query($create_users_table)) {
    echo "âœ… Users table created/verified<br>";
} else {
    echo "âŒ Error creating users table: " . $conn->error . "<br>";
}

// Create test user with simple password
$test_username = "admin";
$test_password = "admin123";
$test_email = "admin@test.com";
$test_first_name = "Admin";
$test_last_name = "User";
$test_role = "admin";

// Hash the password
$password_hash = password_hash($test_password, PASSWORD_DEFAULT);

// Insert test user (replace if exists)
$insert_user = "
INSERT INTO users (username, password, password_hash, email, first_name, last_name, role, is_active) 
VALUES (?, ?, ?, ?, ?, ?, ?, 1)
ON DUPLICATE KEY UPDATE 
password = VALUES(password),
password_hash = VALUES(password_hash),
email = VALUES(email),
first_name = VALUES(first_name),
last_name = VALUES(last_name),
role = VALUES(role),
is_active = 1
";

$stmt = $conn->prepare($insert_user);
$stmt->bind_param("sssssss", $test_username, $test_password, $password_hash, $test_email, $test_first_name, $test_last_name, $test_role);

if ($stmt->execute()) {
    echo "âœ… Test user created/updated successfully<br>";
    echo "Username: " . $test_username . "<br>";
    echo "Password: " . $test_password . "<br>";
    echo "Email: " . $test_email . "<br>";
    echo "Role: " . $test_role . "<br>";
} else {
    echo "âŒ Error creating test user: " . $stmt->error . "<br>";
}

// Also create in new_user table
$insert_new_user = "
INSERT INTO new_user (username, password_hash, email, first_name, last_name, role, is_active) 
VALUES (?, ?, ?, ?, ?, ?, 1)
ON DUPLICATE KEY UPDATE 
password_hash = VALUES(password_hash),
email = VALUES(email),
first_name = VALUES(first_name),
last_name = VALUES(last_name),
role = VALUES(role),
is_active = 1
";

$stmt2 = $conn->prepare($insert_new_user);
$stmt2->bind_param("ssssss", $test_username, $password_hash, $test_email, $test_first_name, $test_last_name, $test_role);

if ($stmt2->execute()) {
    echo "âœ… Test user also created in new_user table<br>";
} else {
    echo "âŒ Error creating test user in new_user table: " . $stmt2->error . "<br>";
}

// Show all users
echo "<br><h3>All Users in Database:</h3>";

// Users table
echo "<h4>Users Table:</h4>";
$result = $conn->query("SELECT id, username, email, role, is_active FROM users");
if ($result && $result->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Active</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['username'] . "</td>";
        echo "<td>" . $row['email'] . "</td>";
        echo "<td>" . $row['role'] . "</td>";
        echo "<td>" . ($row['is_active'] ? 'Yes' : 'No') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No users found in users table<br>";
}

// New_user table
echo "<h4>New_User Table:</h4>";
$result = $conn->query("SELECT id, username, email, role, is_active FROM new_user");
if ($result && $result->num_rows > 0) {
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Active</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['username'] . "</td>";
        echo "<td>" . $row['email'] . "</td>";
        echo "<td>" . $row['role'] . "</td>";
        echo "<td>" . ($row['is_active'] ? 'Yes' : 'No') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "No users found in new_user table<br>";
}

$conn->close();

echo "<br><h3>Test Login:</h3>";
echo "You can now test login with:<br>";
echo "Username: <strong>admin</strong><br>";
echo "Password: <strong>admin123</strong><br>";
echo "<br><a href='login.html'>Go to Login Page</a>";
?>


