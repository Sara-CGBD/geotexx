<?php
session_start();
require_once '../config/security_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$refNumber = $_GET['ref_number'] ?? '';
$rollNo = $_GET['roll_no'] ?? '';
$lineNo = $_GET['line_number'] ?? '';

if (empty($refNumber) || empty($rollNo) || empty($lineNo)) {
    echo json_encode(['error' => 'Missing parameters']);
    exit();
}

$conn = SecurityConfig::getConnection();

// Helper: ensure column exists (safely)
$ensureColumn = function($table, $column, $definition, $after = null) use ($conn) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return;
    $colEsc = $conn->real_escape_string($column);
    $exists = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$colEsc}'");
    if ($exists && $exists->num_rows > 0) return;
    if ($after) {
        $afterEsc = $conn->real_escape_string($after);
        if (!$conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition} AFTER `{$afterEsc}`")) {
            // Retry without AFTER if it fails
            $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    } else {
        $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
};

// Ensure tables exist (with key columns) and then add any missing columns
$hasGsm = false;
$hasLength = false;

// Create daily_gsm_checks if missing
$conn->query("CREATE TABLE IF NOT EXISTS daily_gsm_checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_id INT NULL,
    reference_number VARCHAR(200),
    roll_no VARCHAR(100),
    line_number VARCHAR(100),
    status VARCHAR(50) DEFAULT 'pending',
    approved_at DATETIME NULL,
    size_type VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Create length_calibrations if missing
$conn->query("CREATE TABLE IF NOT EXISTS length_calibrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_id INT NULL,
    reference_number VARCHAR(200),
    roll_no VARCHAR(100),
    line_number VARCHAR(100),
    status VARCHAR(50) DEFAULT 'pending',
    approved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Check table existence after creation
$tableCheck = $conn->query("SHOW TABLES LIKE 'daily_gsm_checks'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $hasGsm = true;
    $ensureColumn('daily_gsm_checks', 'reference_number', "VARCHAR(200)", 'entry_id');
    $ensureColumn('daily_gsm_checks', 'roll_no', "VARCHAR(100)", 'reference_number');
    $ensureColumn('daily_gsm_checks', 'line_number', "VARCHAR(100)", 'roll_no');
    $ensureColumn('daily_gsm_checks', 'status', "VARCHAR(50) DEFAULT 'pending'", 'line_number');
    $ensureColumn('daily_gsm_checks', 'approved_at', "DATETIME NULL", 'status');
    // Add size_type without AFTER to avoid failures on older schemas
    $ensureColumn('daily_gsm_checks', 'size_type', "VARCHAR(50) NULL");
}

$tableCheck2 = $conn->query("SHOW TABLES LIKE 'length_calibrations'");
if ($tableCheck2 && $tableCheck2->num_rows > 0) {
    $hasLength = true;
    $ensureColumn('length_calibrations', 'reference_number', "VARCHAR(200)", 'entry_id');
    $ensureColumn('length_calibrations', 'roll_no', "VARCHAR(100)", 'reference_number');
    $ensureColumn('length_calibrations', 'line_number', "VARCHAR(100)", 'roll_no');
    $ensureColumn('length_calibrations', 'status', "VARCHAR(50) DEFAULT 'pending'", 'line_number');
    // Add approval columns without relying on AFTER to avoid schema mismatches
    $ensureColumn('length_calibrations', 'approved_by', "VARCHAR(100) NULL");
    $ensureColumn('length_calibrations', 'approved_at', "DATETIME NULL");
    // Tolerate optional user_id used in some schemas
    $ensureColumn('length_calibrations', 'user_id', "INT NULL");
    // Tolerate rejection_reason used by approval flows
    $ensureColumn('length_calibrations', 'rejection_reason', "TEXT NULL");
    // Final safety: ensure columns exist via direct ALTER if still missing
    $conn->query("ALTER TABLE length_calibrations ADD COLUMN IF NOT EXISTS approved_by VARCHAR(100) NULL");
    $conn->query("ALTER TABLE length_calibrations ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL");
    $conn->query("ALTER TABLE length_calibrations ADD COLUMN IF NOT EXISTS user_id INT NULL");
    $conn->query("ALTER TABLE length_calibrations ADD COLUMN IF NOT EXISTS rejection_reason TEXT NULL");
}

// Prepare line variants to handle different storage formats
$lineNumberRaw = $lineNo;
$lineNumberPrefix = is_numeric($lineNo) ? "Line " . $lineNo : $lineNo;
$lineNumberNumeric = is_numeric($lineNo) ? (string)(int)$lineNo : $lineNo;

// Check if Daily GSM Check is done (using reference_number, roll_no, and line_number from daily_gsm_checks)
// Only consider tests with status = 'approved'
$gsmDone = false;
$gsmDate = null;
if ($hasGsm) {
    $gsmStmt = $conn->prepare("SELECT MAX(approved_at) as last_date FROM daily_gsm_checks WHERE reference_number = ? AND roll_no = ? AND (line_number = ? OR line_number = ? OR line_number = ?) AND status = 'approved' LIMIT 1");
    if ($gsmStmt) {
        $gsmStmt->bind_param('sssss', $refNumber, $rollNo, $lineNumberRaw, $lineNumberPrefix, $lineNumberNumeric);
        $gsmStmt->execute();
        $gsmResult = $gsmStmt->get_result();
        if ($gsmResult && $gsmRow = $gsmResult->fetch_assoc()) {
            if ($gsmRow['last_date']) {
                $gsmDone = true;
                $gsmDate = date('Y-m-d H:i', strtotime($gsmRow['last_date']));
            }
        }
        $gsmStmt->close();
    }
}

// Check if Length Calibration is done (using reference_number, roll_no, and line_number from length_calibrations)
// Only consider tests with status = 'approved'
$lengthDone = false;
$lengthDate = null;
if ($hasLength) {
    $lengthStmt = $conn->prepare("SELECT MAX(approved_at) as last_date FROM length_calibrations WHERE reference_number = ? AND roll_no = ? AND (line_number = ? OR line_number = ? OR line_number = ?) AND status = 'approved' LIMIT 1");
    if ($lengthStmt) {
        $lengthStmt->bind_param('sssss', $refNumber, $rollNo, $lineNumberRaw, $lineNumberPrefix, $lineNumberNumeric);
        $lengthStmt->execute();
        $lengthResult = $lengthStmt->get_result();
        if ($lengthResult && $lengthRow = $lengthResult->fetch_assoc()) {
            if ($lengthRow['last_date']) {
                $lengthDone = true;
                $lengthDate = date('Y-m-d H:i', strtotime($lengthRow['last_date']));
            }
        }
        $lengthStmt->close();
    }
}

$conn->close();

echo json_encode([
    'gsm_done' => $gsmDone,
    'gsm_date' => $gsmDate,
    'length_done' => $lengthDone,
    'length_date' => $lengthDate,
    'all_done' => ($gsmDone && $lengthDone)
]);
?>


