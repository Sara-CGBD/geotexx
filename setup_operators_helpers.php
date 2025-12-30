<?php
/**
 * Setup script to create dedicated operators and helpers tables
 * Run this script once to set up the tables
 */

echo "ðŸ”§ Setting up Operators and Helpers Tables\n";
echo "==========================================\n\n";

// Database connection
$host = "localhost";
$username = "root";
$password = "";
$dbname = "geobagg";

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create operators table
$create_operators = "
CREATE TABLE IF NOT EXISTS operators (
    id INT AUTO_INCREMENT PRIMARY KEY,
    operator_code VARCHAR(20) UNIQUE NOT NULL,
    operator_name VARCHAR(100) NOT NULL,
    department VARCHAR(50),
    skill_level ENUM('Beginner', 'Intermediate', 'Advanced', 'Expert') DEFAULT 'Beginner',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

if ($conn->query($create_operators)) {
    echo "âœ… Operators table created successfully\n";
} else {
    echo "âŒ Error creating operators table: " . $conn->error . "\n";
}

// Create helpers table
$create_helpers = "
CREATE TABLE IF NOT EXISTS helpers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    helper_code VARCHAR(20) UNIQUE NOT NULL,
    helper_name VARCHAR(100) NOT NULL,
    department VARCHAR(50),
    skill_level ENUM('Beginner', 'Intermediate', 'Advanced') DEFAULT 'Beginner',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

if ($conn->query($create_helpers)) {
    echo "âœ… Helpers table created successfully\n";
} else {
    echo "âŒ Error creating helpers table: " . $conn->error . "\n";
}

// Insert sample data
$sample_operators = [
    ['OP001', 'Ahmed Hassan', 'Production', 'Advanced'],
    ['OP002', 'Fatima Ali', 'Production', 'Expert'],
    ['OP003', 'Mohammad Rahman', 'Production', 'Intermediate'],
    ['OP004', 'Aisha Khan', 'Production', 'Advanced'],
    ['OP005', 'Karim Uddin', 'Production', 'Beginner']
];

$sample_helpers = [
    ['HL001', 'Rashid Ahmed', 'Production', 'Intermediate'],
    ['HL002', 'Nasrin Begum', 'Production', 'Advanced'],
    ['HL003', 'Sajjad Ali', 'Production', 'Beginner'],
    ['HL004', 'Rokeya Khatun', 'Production', 'Intermediate'],
    ['HL005', 'Jamal Uddin', 'Production', 'Advanced']
];

// Insert sample operators
foreach ($sample_operators as $op) {
    $insert = "INSERT IGNORE INTO operators (operator_code, operator_name, department, skill_level) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($insert);
    $stmt->bind_param("ssss", $op[0], $op[1], $op[2], $op[3]);
    if ($stmt->execute()) {
        echo "âœ… Inserted operator: {$op[1]}\n";
    }
    $stmt->close();
}

// Insert sample helpers
foreach ($sample_helpers as $hl) {
    $insert = "INSERT IGNORE INTO helpers (helper_code, helper_name, department, skill_level) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($insert);
    $stmt->bind_param("ssss", $hl[0], $hl[1], $hl[2], $hl[3]);
    if ($stmt->execute()) {
        echo "âœ… Inserted helper: {$hl[1]}\n";
    }
    $stmt->close();
}

echo "\nðŸŽ‰ Setup completed successfully!\n";
echo "\nYou can now:\n";
echo "1. Use dedicated operators and helpers tables\n";
echo "2. Or continue using filtered users table\n";
echo "\nTo switch to dedicated tables, update the swing_machine_entry.php form.\n";

$conn->close();
?>


