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
    $reference_quantities = $_POST['reference_quantities'] ?? ''; // JSON string of per-reference quantities
    
    // Debug: Log reference_quantities to see what we're receiving
    error_log("CNC Entry Submit - reference_quantities received: " . substr($reference_quantities, 0, 500) . (strlen($reference_quantities) > 500 ? '...' : ''));
    error_log("CNC Entry Submit - reference_quantities length: " . strlen($reference_quantities));
    
    // Validate and ensure reference_quantities is valid JSON
    if (!empty($reference_quantities)) {
        $decoded = json_decode($reference_quantities, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("CNC Entry Submit - Invalid JSON in reference_quantities: " . json_last_error_msg());
            error_log("CNC Entry Submit - Raw value: " . substr($reference_quantities, 0, 200));
            // Try to fix common issues
            $reference_quantities = stripslashes($reference_quantities);
            $decoded = json_decode($reference_quantities, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("CNC Entry Submit - Still invalid after stripslashes");
                $reference_quantities = ''; // Clear invalid JSON
            } else {
                error_log("CNC Entry Submit - Fixed JSON after stripslashes");
                $reference_quantities = json_encode($decoded); // Re-encode to ensure clean JSON
            }
        } else {
            error_log("CNC Entry Submit - Valid JSON, contains " . count($decoded) . " references");
        }
    }
    
    // Validate required fields
    if (empty($cnc_id) || empty($reference_number) || empty($cnc_cutting_batch) || empty($project_id) || empty($cnc_machine_id) || empty($cutting_roll_quantity) || empty($bag_size)) {
        header("Location: ../forms/cnc_entry.php?error=missing_fields");
        exit();
    }
    
    // Ensure cnc_id column exists in the table
    $alter_table = "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS cnc_id VARCHAR(50) UNIQUE";
    $conn->query($alter_table);
    
    // Check if cnc_id already exists, if so generate a new one
    $check_stmt = $conn->prepare("SELECT cnc_id FROM cnc_entries WHERE cnc_id = ?");
    if ($check_stmt) {
        $check_stmt->bind_param("s", $cnc_id);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        if ($result->num_rows > 0) {
            // ID already exists, generate a new one - using prepared statement
            $current_date = date('Y-m-d');
            $last_cnc_stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(cnc_id, -3) AS UNSIGNED)) as last_num FROM cnc_entries WHERE DATE(date_time) = ?");
            if ($last_cnc_stmt) {
                $last_cnc_stmt->bind_param("s", $current_date);
                $last_cnc_stmt->execute();
                $last_cnc_result = $last_cnc_stmt->get_result();
                $next_cnc_number = 1;
                if ($last_cnc_result && $last_cnc_result->num_rows > 0) {
                    $row = $last_cnc_result->fetch_assoc();
                    if ($row['last_num']) {
                        $next_cnc_number = $row['last_num'] + 1;
                    }
                }
                $last_cnc_stmt->close();
            } else {
                $next_cnc_number = 1;
            }
            $cnc_id = "CNC" . date('Ymd') . str_pad($next_cnc_number, 3, '0', STR_PAD_LEFT);
        }
        $check_stmt->close();
    }
    
    // Also ensure other columns exist
    $alter_columns = [
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS date_time DATETIME",
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS shift VARCHAR(10)",
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS reporter_id INT",
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS reference_number TEXT",
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS cnc_cutting_batch VARCHAR(150)",
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS project_id INT",
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS cnc_machine_id VARCHAR(50)",
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS cutting_roll_quantity INT",
        "ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS bag_size VARCHAR(100)"
    ];
    
    foreach ($alter_columns as $sql) {
        $conn->query($sql);
    }
    
    // Add reference_quantities column separately (IF NOT EXISTS doesn't work for TEXT in some MySQL versions)
    // CRITICAL: Ensure it's TEXT (not VARCHAR) to support up to 100 references with quantities
    $refQtyCheck = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'reference_quantities'");
    if (!$refQtyCheck || $refQtyCheck->num_rows === 0) {
        $conn->query("ALTER TABLE cnc_entries ADD COLUMN reference_quantities TEXT NULL AFTER bag_size");
    } else {
        // Ensure it's TEXT type (not VARCHAR) to support large JSON
        $colInfo = $refQtyCheck->fetch_assoc();
        if (isset($colInfo['Type']) && strpos(strtolower($colInfo['Type']), 'varchar') !== false) {
            $conn->query("ALTER TABLE cnc_entries MODIFY COLUMN reference_quantities TEXT NULL");
        }
    }
    // Ensure cnc_machine_id is VARCHAR even if it existed before as INT
    $conn->query("ALTER TABLE cnc_entries MODIFY COLUMN cnc_machine_id VARCHAR(50)");
    
    // CRITICAL: Ensure reference_number can store up to 100 references (change from VARCHAR(100) to TEXT)
    // This allows storing comma-separated list of many references
    $refNumCheck = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'reference_number'");
    if ($refNumCheck && $refNumCheck->num_rows > 0) {
        $colInfo = $refNumCheck->fetch_assoc();
        // If it's VARCHAR with a small size, change it to TEXT
        if (isset($colInfo['Type']) && strpos(strtolower($colInfo['Type']), 'varchar') !== false) {
            $conn->query("ALTER TABLE cnc_entries MODIFY COLUMN reference_number TEXT");
        }
    }
    
    // Ensure remaining_qty column exists and initialize it
    $conn->query("ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS remaining_qty INT DEFAULT 0");
    $conn->query("ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS used_qty INT DEFAULT 0");
    
    // Insert data (added cnc_cutting_batch, reference_quantities, used_qty, and remaining_qty)
    // Initialize remaining_qty = cutting_roll_quantity for new entries (nothing used yet)
    $stmt = $conn->prepare("INSERT INTO cnc_entries (cnc_id, date_time, shift, reporter_id, reference_number, cnc_cutting_batch, project_id, cnc_machine_id, cutting_roll_quantity, bag_size, reference_quantities, used_qty, remaining_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
    
    if ($stmt) {
        // Types: s=string, i=integer
        // cnc_id(s), date_time(s), shift(s), reporter_id(i), reference_number(s), cnc_cutting_batch(s), project_id(i), cnc_machine_id(s), cutting_roll_quantity(i), bag_size(s), reference_quantities(s), used_qty(i=0 hardcoded), remaining_qty(i=cutting_roll_quantity)
        // Correct mapping: s s s i s s i s i s s i
        // Note: used_qty is hardcoded as 0 in SQL, so we only bind remaining_qty (which equals cutting_roll_quantity for new entries)
        $stmt->bind_param("sssissisissi", $cnc_id, $date_time, $shift, $reporter_id, $reference_number, $cnc_cutting_batch, $project_id, $cnc_machine_id, $cutting_roll_quantity, $bag_size, $reference_quantities, $cutting_roll_quantity);
        
        if ($stmt->execute()) {
            // Verify the data was saved correctly
            $verifyQuery = $conn->prepare("SELECT reference_quantities, reference_number FROM cnc_entries WHERE cnc_id = ?");
            if ($verifyQuery) {
                $verifyQuery->bind_param("s", $cnc_id);
                $verifyQuery->execute();
                $verifyResult = $verifyQuery->get_result();
                if ($verifyRow = $verifyResult->fetch_assoc()) {
                    $savedQuantities = $verifyRow['reference_quantities'] ?? '';
                    $savedRefNumber = $verifyRow['reference_number'] ?? '';
                    
                    error_log("CNC Entry Submit - Verified saved reference_quantities length: " . strlen($savedQuantities));
                    error_log("CNC Entry Submit - Verified saved reference_number: " . substr($savedRefNumber, 0, 200));
                    
                    if (!empty($reference_quantities)) {
                        if (empty($savedQuantities)) {
                            error_log("CNC Entry Submit - ERROR: reference_quantities was NOT saved! Expected length: " . strlen($reference_quantities));
                        } else {
                            error_log("CNC Entry Submit - SUCCESS: reference_quantities was saved correctly!");
                            // Decode and count references
                            $savedDecoded = json_decode($savedQuantities, true);
                            if (is_array($savedDecoded)) {
                                error_log("CNC Entry Submit - Saved JSON contains " . count($savedDecoded) . " reference entries");
                            }
                        }
                    }
                }
                $verifyQuery->close();
            }
            
            $stmt->close();
            header("Location: ../forms/cnc_entry.php?success=cnc_entry_saved_plain&entry_id=" . urlencode($cnc_id));
            exit();
        } else {
            $error_msg = "Insert failed: " . $stmt->error;
            error_log("CNC Entry Submit - Database error: " . $error_msg);
            error_log("CNC Entry Submit - reference_quantities length: " . strlen($reference_quantities));
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


