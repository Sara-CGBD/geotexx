<?php
session_start();
require_once '../config/security_config.php';

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Set timezone to Bangladesh
date_default_timezone_set('Asia/Dhaka');

// Database connection (shared config)
$conn = SecurityConfig::getConnection();

// Check if form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Get form data
    $cnc_id = $_POST['cnc_id'] ?? '';
    $date_time = $_POST['date_time'] ?? date('Y-m-d H:i:s');
    // Note: $date_time is already in Bangladesh time format from frontend
    $shift = $_POST['shift'] ?? 'Day';
    $reporter_id = $_POST['reporter_id'] ?? $_SESSION['user_id'];
    $reference_number = $_POST['reference_number'] ?? '';
    $cnc_cutting_batch = $_POST['cnc_cutting_batch'] ?? '';
    $project_id = $_POST['project_id'] ?? '';
    $cnc_machine_id = $_POST['cnc_machine_id'] ?? '';
    $cutting_roll_quantity = $_POST['cutting_roll_quantity'] ?? '';
    $bag_size = $_POST['bag_size'] ?? '';
    
    // Validate required fields
    if (empty($cnc_id) || empty($reference_number) || empty($cnc_cutting_batch) || empty($project_id) || empty($cnc_machine_id) || empty($cutting_roll_quantity) || empty($bag_size)) {
        header("Location: ../forms/cnc_entry.php?error=missing_fields");
        exit();
    }
    
    // Ensure cnc_id column exists in the table
    $alter_table = "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS cnc_id VARCHAR(50) UNIQUE";
    $conn->query($alter_table);
    
    // Also ensure other columns exist
    $alter_columns = [
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS date_time DATETIME",
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS shift VARCHAR(10)",
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS reporter_id INT",
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS reference_number VARCHAR(100)",
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS cnc_cutting_batch VARCHAR(150)",
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS project_id INT",
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS cnc_machine_id VARCHAR(50)",
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS cutting_roll_quantity INT",
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS bag_size VARCHAR(100)"
    ];
    
    foreach ($alter_columns as $sql) {
        $conn->query($sql);
    }
    // Ensure cnc_machine_id is VARCHAR even if it existed before as INT
    $conn->query("ALTER TABLE cnc_entries MODIFY COLUMN cnc_machine_id VARCHAR(50)");
    
    // Insert data (added cnc_cutting_batch)
    $stmt = $conn->prepare("INSERT INTO cnc_entries (cnc_id, date_time, shift, reporter_id, reference_number, cnc_cutting_batch, project_id, cnc_machine_id, cutting_roll_quantity, bag_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    if ($stmt) {
        // Types: s=string, i=integer
        // cnc_id(s), date_time(s), shift(s), reporter_id(i), reference_number(s), cnc_cutting_batch(s), project_id(i), cnc_machine_id(s), cutting_roll_quantity(i), bag_size(s)
        // Correct mapping: s s s i s s i s i s
        $stmt->bind_param("sssissisis", $cnc_id, $date_time, $shift, $reporter_id, $reference_number, $cnc_cutting_batch, $project_id, $cnc_machine_id, $cutting_roll_quantity, $bag_size);
        
        if ($stmt->execute()) {
            $stmt->close();
            header("Location: ../forms/cnc_entry.php?success=cnc_entry_saved_plain");
            exit();
        } else {
            $error_msg = "Insert failed: " . $stmt->error;
            $stmt->close();
            header("Location: ../forms/cnc_entry.php?error=" . urlencode($error_msg));
            exit();
        }
    } else {
        $error_msg = "Prepare failed: " . $conn->error;
        header("Location: ../forms/cnc_entry.php?error=" . urlencode($error_msg));
        exit();
    }
    
} else {
    header("Location: ../forms/cnc_entry.php");
}

$conn->close();
?>


