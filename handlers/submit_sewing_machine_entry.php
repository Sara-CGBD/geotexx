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
    $sewing_id    = $_POST['sewing_id'] ?? '';
    $date_time   = $_POST['date_time'] ?? date('Y-m-d H:i:s');
    // Note: $date_time is already in Bangladesh time format from frontend
    
    $reporter_id = $_POST['reporter_id'] ?? $_SESSION['user_id'];
    $cnc_cutting_batch = $_POST['cnc_cutting_batch'] ?? '';
    // reference_number is optional - the cnc_cutting_batch serves as the trace to CNC entries
    $reference_number = $_POST['reference_number'] ?? '';
    $project_id  = $_POST['project_id'] ?? '';
    $line_no     = $_POST['line_no'] ?? '';
    $sewing_qty  = $_POST['sewing_qty'] ?? '';
    $ncp_piece   = $_POST['ncp_piece'] ?? '';

    // Validate required fields
    // Note: reference_number is now optional - the cnc_cutting_batch serves as the trace to CNC entries
    $missing = [];
    if (empty($sewing_id)) $missing[] = 'sewing_id';
    if (empty($cnc_cutting_batch)) $missing[] = 'cnc_cutting_batch';
    // reference_number is optional - removed from validation
    if (empty($project_id)) $missing[] = 'project_id';
    if (empty($line_no)) $missing[] = 'line_no';
    if (empty($sewing_qty)) $missing[] = 'sewing_qty';
    if (empty($ncp_piece) && $ncp_piece !== '0') $missing[] = 'ncp_piece';
    
    if (!empty($missing)) {
        $missing_fields_text = implode(', ', $missing);
        header("Location: ../forms/sewing_machine_entry.php?error=" . urlencode("Missing fields: " . $missing_fields_text));
        exit();
    }

    // Check if old table exists and rename it, or create new table
    $old_table_check = $conn->query("SHOW TABLES LIKE 'swing_machine_entry'");
    if ($old_table_check && $old_table_check->num_rows > 0) {
        // Rename old table to new name if it doesn't exist
        $new_table_check = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
        if (!$new_table_check || $new_table_check->num_rows == 0) {
            $conn->query("RENAME TABLE swing_machine_entry TO sewing_machine_entry");
        }
        // Rename old column to new name if it exists
        $old_col_check = $conn->query("SHOW COLUMNS FROM sewing_machine_entry LIKE 'swing_id'");
        if ($old_col_check && $old_col_check->num_rows > 0) {
            $new_col_check = $conn->query("SHOW COLUMNS FROM sewing_machine_entry LIKE 'sewing_id'");
            if (!$new_col_check || $new_col_check->num_rows == 0) {
                $conn->query("ALTER TABLE sewing_machine_entry CHANGE swing_id sewing_id VARCHAR(50) UNIQUE NOT NULL");
            }
        }
    }
    
    // Ensure table exists
    $create_table = "
        CREATE TABLE IF NOT EXISTS sewing_machine_entry (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sewing_id VARCHAR(50) UNIQUE NOT NULL,
            date_time DATETIME NOT NULL,
            shift VARCHAR(10) NOT NULL,
            reporter_id INT NOT NULL,
            cnc_cutting_batch VARCHAR(150),
            reference_number VARCHAR(100),
            source_cnc_id VARCHAR(50),
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
    "ALTER TABLE sewing_machine_entry ADD COLUMN IF NOT EXISTS cnc_cutting_batch VARCHAR(150)",
    "ALTER TABLE sewing_machine_entry ADD COLUMN IF NOT EXISTS reference_number VARCHAR(100)",
    "ALTER TABLE sewing_machine_entry ADD COLUMN IF NOT EXISTS source_cnc_id VARCHAR(50)",
    "ALTER TABLE sewing_machine_entry ADD COLUMN IF NOT EXISTS sewing_id VARCHAR(50)"
];
foreach ($alter_columns as $sql) {
    $conn->query($sql);
}

    // Determine shift using Bangladesh timezone
    $dhaka_hour = (int)date('H');
    $shift = ($dhaka_hour >= 8 && $dhaka_hour < 20) ? 'Day' : 'Night';

    // Track which CNC entry this batch came from
    // Find the first CNC entry with this batch number to establish the trace
    $source_cnc_id = null;
    if (!empty($cnc_cutting_batch)) {
        $cnc_trace_query = $conn->prepare("SELECT cnc_id FROM cnc_entries WHERE cnc_cutting_batch = ? ORDER BY date_time ASC LIMIT 1");
        if ($cnc_trace_query) {
            $cnc_trace_query->bind_param("s", $cnc_cutting_batch);
            $cnc_trace_query->execute();
            $cnc_trace_result = $cnc_trace_query->get_result();
            if ($cnc_trace_result && $cnc_trace_row = $cnc_trace_result->fetch_assoc()) {
                $source_cnc_id = $cnc_trace_row['cnc_id'];
            }
            $cnc_trace_query->close();
        }
    }

    // Insert data
    $stmt = $conn->prepare("
        INSERT INTO sewing_machine_entry 
        (sewing_id, date_time, shift, reporter_id, cnc_cutting_batch, reference_number, source_cnc_id, project_id, line_no, sewing_qty, ncp_piece)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if ($stmt) {
         // Types: sewing_id(s), date_time(s), shift(s), reporter_id(i), cnc_cutting_batch(s), reference_number(s),
         // source_cnc_id(s), project_id(i), line_no(s), sewing_qty(i), ncp_piece(i)
         // Total: 11 parameters = sssississii
         $stmt->bind_param(
             "sssississii",
             $sewing_id,
             $date_time,
             $shift,
             $reporter_id,
             $cnc_cutting_batch,
             $reference_number,
             $source_cnc_id,
             $project_id,
             $line_no,
             $sewing_qty,
             $ncp_piece
         );

        try {
            $stmt->execute();
            $entry_id = $sewing_id; // Use the sewing_id as entry ID
            header("Location: ../forms/swing_machine_entry.php?success=sewing_entry_saved&entry_id=" . urlencode($entry_id));
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

