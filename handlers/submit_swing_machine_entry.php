<?php
session_start();
require_once '../config/security_config.php';

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Enable error reporting for debugging (remove in production)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Database connection
$conn = SecurityConfig::getConnection();

// Set timezone to Bangladesh
date_default_timezone_set('Asia/Dhaka');

// Check if form submitted
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Collect form data
    $swing_id    = $_POST['swing_id'] ?? '';
    $date_time   = $_POST['date_time'] ?? date('Y-m-d H:i:s');
    // Note: $date_time is already in Bangladesh time format from frontend
    
    $reporter_id = $_POST['reporter_id'] ?? $_SESSION['user_id'];
    $cnc_cutting_batch = $_POST['cnc_cutting_batch'] ?? '';
    $reference_number = $_POST['reference_number'] ?? '';
    $project_id  = $_POST['project_id'] ?? '';
    $line_no     = $_POST['line_no'] ?? '';
    $sewing_qty  = $_POST['sewing_qty'] ?? '';
    $ncp_piece   = $_POST['ncp_piece'] ?? '';

    // Validate required fields
    $missing = [];
    if (empty($swing_id)) $missing[] = 'swing_id';
    if (empty($cnc_cutting_batch)) $missing[] = 'cnc_cutting_batch';
    if (empty($reference_number)) $missing[] = 'reference_number';
    if (empty($project_id)) $missing[] = 'project_id';
    if (empty($line_no)) $missing[] = 'line_no';
    if (empty($sewing_qty)) $missing[] = 'sewing_qty';
    if (empty($ncp_piece) && $ncp_piece !== '0') $missing[] = 'ncp_piece';
    
    if (!empty($missing)) {
        $missing_fields_text = implode(', ', $missing);
        header("Location: ../forms/swing_machine_entry.php?error=" . urlencode("Missing fields: " . $missing_fields_text));
        exit();
    }

    // Ensure table exists
    $create_table = "
        CREATE TABLE IF NOT EXISTS swing_machine_entry (
            id INT AUTO_INCREMENT PRIMARY KEY,
            swing_id VARCHAR(50) UNIQUE NOT NULL,
            date_time DATETIME NOT NULL,
            shift VARCHAR(10) NOT NULL,
            reporter_id INT NOT NULL,
            cnc_cutting_batch VARCHAR(150),
            reference_number VARCHAR(100),
            project_id INT NOT NULL,
            line_no VARCHAR(50) NOT NULL,
            sewing_qty INT NOT NULL,
            ncp_piece INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $conn->query($create_table);
    
    // Also add columns if they don't exist
$alter_columns = [
    "ALTER TABLE swing_machine_entry ADD COLUMN IF NOT EXISTS cnc_cutting_batch VARCHAR(150)",
    "ALTER TABLE swing_machine_entry ADD COLUMN IF NOT EXISTS reference_number VARCHAR(100)"
];
foreach ($alter_columns as $sql) {
    $conn->query($sql);
}

    // Determine shift using Bangladesh timezone
    $dhaka_hour = (int)date('H');
    $shift = ($dhaka_hour >= 8 && $dhaka_hour < 20) ? 'Day' : 'Night';

    // Insert data
    $stmt = $conn->prepare("
        INSERT INTO swing_machine_entry 
        (swing_id, date_time, shift, reporter_id, cnc_cutting_batch, reference_number, project_id, line_no, sewing_qty, ncp_piece)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if ($stmt) {
         // Types: swing_id(s), date_time(s), shift(s), reporter_id(i), cnc_cutting_batch(s), reference_number(s),
         // project_id(i), line_no(s), sewing_qty(i), ncp_piece(i)
         $stmt->bind_param(
             "ssisssisis",
             $swing_id,
             $date_time,
             $shift,
             $reporter_id,
             $cnc_cutting_batch,
             $reference_number,
             $project_id,
             $line_no,
             $sewing_qty,
             $ncp_piece
         );

        try {
            $stmt->execute();
            header("Location: ../forms/swing_machine_entry.php?success=swing_entry_saved");
            exit();
        } catch (mysqli_sql_exception $e) {
            $error_msg = urlencode("Insert failed: " . $e->getMessage());
            header("Location: ../forms/swing_machine_entry.php?error=" . $error_msg);
            exit();
        }
    } else {
        $error_msg = urlencode("Prepare failed: " . $conn->error);
        header("Location: ../forms/swing_machine_entry.php?error=" . $error_msg);
        exit();
    }
} else {
    header("Location: ../forms/swing_machine_entry.php");
    exit();
}

$conn->close();
?>

