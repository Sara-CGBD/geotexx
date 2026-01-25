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
    
    // Calculate total weight from selected fiber input entries
    // Fetch actual weights from fiber_to_roll_entry table using entry IDs
    $totalWeight = 0;
    if (!empty($fiberInputEntries)) {
        $entryIds = [];
        foreach ($fiberInputEntries as $entry) {
            if (isset($entry['entry_id']) && !empty($entry['entry_id'])) {
                $entryIds[] = $conn->real_escape_string($entry['entry_id']);
            }
        }
        
        if (!empty($entryIds)) {
            $entryIdsStr = "'" . implode("','", $entryIds) . "'";
            $weightQuery = $conn->query("
                SELECT COALESCE(SUM(total_weight), 0) as total_weight_sum
                FROM fiber_to_roll_entry
                WHERE entry_id IN ({$entryIdsStr})
            ");
            if ($weightQuery && $weightRow = $weightQuery->fetch_assoc()) {
                $totalWeight = (float)$weightRow['total_weight_sum'];
            }
        }
    }

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
        total_weight DECIMAL(10,2) DEFAULT 0,
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
    
    // Add total_weight column if it doesn't exist
    $checkTotalWeight = $conn->query("SHOW COLUMNS FROM gsm_roll_entry LIKE 'total_weight'");
    if ($checkTotalWeight && $checkTotalWeight->num_rows == 0) {
        $conn->query("ALTER TABLE gsm_roll_entry ADD COLUMN total_weight DECIMAL(10,2) DEFAULT 0 AFTER reference");
    }
    
    // Determine shift
    $dateTimeObj = new DateTime($dateTime);
    $hour = (int)$dateTimeObj->format('H');
    $shift = ($hour >= 8 && $hour <= 19) ? 'Day' : 'Night';
    
    // Convert fiber input entries to JSON string
    $fiberEntriesJson = json_encode($fiberInputEntries);

    $stmt = $conn->prepare("INSERT INTO gsm_roll_entry (entry_id, date_time, shift, line_number, gsm, roll_no, reference, total_weight, fiber_input_entries) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    // 9 placeholders: entry_id, date_time, shift, line_number, gsm, roll_no, reference, total_weight, fiber_input_entries
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }


    // 9 parameters: 1.s(entryId), 2.s(dateTime), 3.s(shift), 4.s(lineNumber), 5.i(gsm), 6.i(rollNo), 7.s(reference), 8.d(totalWeight), 9.s(fiberEntriesJson)
    // Type string must be exactly 9 characters matching 9 parameters
    // Current 'ssssiissds' has 10 chars - fix: change pos 8 from 's' to 'd', remove pos 10 's'
    // Correct: 'ssssiissds' = s(1-4) + i(5-6) + s(7) + d(8) + s(9) = 9 chars
    $stmt->bind_param('ssssiisds', $entryId, $dateTime, $shift, $lineNumber, $gsm, $rollNo, $reference, $totalWeight, $fiberEntriesJson);

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
