<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'php_error.log');
error_reporting(E_ALL);

session_start();
require_once 'security_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../forms/gsm_roll_entry.php');
    exit;
}

// Validate required fields
$required = ['date_time', 'entry_id', 'line_number', 'gsm', 'roll_no', 'reference', 'fiber_input_entries'];
foreach ($required as $key) {
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        header('Location: ../forms/gsm_roll_entry.php?error=' . urlencode('Missing field: ' . $key));
        exit;
    }
}

$dateTime = $_POST['date_time'];
$entryId = trim($_POST['entry_id']);
$lineNumber = trim($_POST['line_number']);
$gsm = (int)$_POST['gsm'];
$rollNo = (int)$_POST['roll_no'];
$reference = trim($_POST['reference']);
$fiberInputEntriesJson = $_POST['fiber_input_entries'];

// Parse fiber input entries
$fiberInputEntries = json_decode($fiberInputEntriesJson, true);
if (!is_array($fiberInputEntries) || empty($fiberInputEntries)) {
    header('Location: ../forms/gsm_roll_entry.php?error=' . urlencode('At least one Fiber Input Entry is required'));
    exit;
}

try {
    $conn = SecurityConfig::getConnection();

    // Create gsm_roll_entry table if it doesn't exist
    $createTable = "CREATE TABLE IF NOT EXISTS gsm_roll_entry (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_id VARCHAR(50) UNIQUE,
        date_time DATETIME,
        shift VARCHAR(20),
        line_number VARCHAR(50),
        gsm INT,
        roll_no INT,
        reference VARCHAR(255),
        fiber_input_entries TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($createTable);
    
    // Add shift column if it doesn't exist
    $checkShift = $conn->query("SHOW COLUMNS FROM gsm_roll_entry LIKE 'shift'");
    if ($checkShift && $checkShift->num_rows == 0) {
        $conn->query("ALTER TABLE gsm_roll_entry ADD COLUMN shift VARCHAR(20) AFTER date_time");
    }
    
    // Add line_number column if it doesn't exist
    $checkLineNumber = $conn->query("SHOW COLUMNS FROM gsm_roll_entry LIKE 'line_number'");
    if ($checkLineNumber && $checkLineNumber->num_rows == 0) {
        $conn->query("ALTER TABLE gsm_roll_entry ADD COLUMN line_number VARCHAR(50) AFTER shift");
    }
    
    // Determine shift
    $dateTimeObj = new DateTime($dateTime);
    $hour = (int)$dateTimeObj->format('H');
    $shift = ($hour >= 8 && $hour <= 19) ? 'Day' : 'Night';
    
    // Convert fiber input entries to JSON string
    $fiberEntriesJson = json_encode($fiberInputEntries);

    $stmt = $conn->prepare("INSERT INTO gsm_roll_entry (entry_id, date_time, shift, line_number, gsm, roll_no, reference, fiber_input_entries) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    $stmt->bind_param(
        'ssssiiss',
        $entryId,
        $dateTime,
        $shift,
        $lineNumber,
        $gsm,
        $rollNo,
        $reference,
        $fiberEntriesJson
    );

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    
    $stmt->close();

    header('Location: ../forms/gsm_roll_entry.php?success=' . urlencode('GSM and Roll Input saved successfully! Entry ID: ' . $entryId));
    exit;
} catch (Throwable $e) {
    header('Location: ../forms/gsm_roll_entry.php?error=' . urlencode($e->getMessage()));
    exit;
}
?>
