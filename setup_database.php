<?php
/**
 * Database Setup Script
 * Creates the required tables for the GEOCIL Automation System
 */

echo "ðŸ”§ Setting up GEOCIL Automation Database\n";
echo "=======================================\n\n";

// Database connection
$host = "localhost";
$username = "root";
$password = "";
$dbname = "geobagg";

$conn = new mysqli($host, $username, $password);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if it doesn't exist
$sql = "CREATE DATABASE IF NOT EXISTS $dbname CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
if ($conn->query($sql) === TRUE) {
    echo "âœ… Database '$dbname' created or already exists\n";
} else {
    echo "âŒ Error creating database: " . $conn->error . "\n";
    exit;
}

// Select the database
$conn->select_db($dbname);

// Create new_user table
$sql = "CREATE TABLE IF NOT EXISTS new_user (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql) === TRUE) {
    echo "âœ… Table 'new_user' created successfully\n";
} else {
    echo "âŒ Error creating table new_user: " . $conn->error . "\n";
}

// Create active_sessions table
$sql = "CREATE TABLE IF NOT EXISTS active_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_id VARCHAR(128) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    FOREIGN KEY (user_id) REFERENCES new_user(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql) === TRUE) {
    echo "âœ… Table 'active_sessions' created successfully\n";
} else {
    echo "âŒ Error creating table active_sessions: " . $conn->error . "\n";
}

// Create projects table
$sql = "CREATE TABLE IF NOT EXISTS projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_name VARCHAR(100) NOT NULL,
    description TEXT,
    status ENUM('active', 'inactive', 'completed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($sql) === TRUE) {
    echo "âœ… Table 'projects' created successfully\n";
} else {
    echo "âŒ Error creating table projects: " . $conn->error . "\n";
}

// Insert default admin user
$admin_password = password_hash('admin123', PASSWORD_DEFAULT);
$sql = "INSERT IGNORE INTO new_user (username, password_hash, email, first_name, last_name, role, is_active) 
        VALUES ('admin', ?, 'admin@geocil.com', 'System', 'Administrator', 'admin', 1)";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $admin_password);

if ($stmt->execute()) {
    echo "âœ… Default admin user created (username: admin, password: admin123)\n";
} else {
    echo "âŒ Error creating admin user: " . $stmt->error . "\n";
}

// Insert sample project
$sql = "INSERT IGNORE INTO projects (project_name, description, status) 
        VALUES ('Sample Project', 'Default project for testing', 'active')";

if ($conn->query($sql) === TRUE) {
    echo "âœ… Sample project created\n";
} else {
    echo "âŒ Error creating sample project: " . $conn->error . "\n";
}

$conn->close();

echo "\nðŸŽ‰ Database setup completed successfully!\n";
echo "You can now login with:\n";
echo "Username: admin\n";
echo "Password: admin123\n";
?>


