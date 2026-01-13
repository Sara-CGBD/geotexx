<?php
session_start();
require_once '../config/security_config.php';

header('Content-Type: application/json');

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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
    // Check if updated_at column exists
    $hasUpdatedAt = false;
    $colCheck = $conn->query("SHOW COLUMNS FROM daily_gsm_checks LIKE 'updated_at'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $hasUpdatedAt = true;
    }
    
    // Try multiple query strategies - first with line_number matching, then without if needed
    // Strategy 1: With line_number matching (most specific)
    if ($hasUpdatedAt) {
        $gsmQuery = "SELECT 
            MAX(COALESCE(approved_at, updated_at, created_at)) as last_date,
            MAX(status) as test_status
            FROM daily_gsm_checks 
            WHERE reference_number = ? 
            AND roll_no = ? 
            AND (
                line_number = ? 
                OR line_number = ? 
                OR line_number = ? 
                OR line_number IS NULL
                OR line_number = ''
            )
            AND LOWER(TRIM(status)) = 'approved' 
            LIMIT 1";
    } else {
        $gsmQuery = "SELECT 
            MAX(COALESCE(approved_at, created_at)) as last_date,
            MAX(status) as test_status
            FROM daily_gsm_checks 
            WHERE reference_number = ? 
            AND roll_no = ? 
            AND (
                line_number = ? 
                OR line_number = ? 
                OR line_number = ? 
                OR line_number IS NULL
                OR line_number = ''
            )
            AND LOWER(TRIM(status)) = 'approved' 
            LIMIT 1";
    }
    
    $gsmStmt = $conn->prepare($gsmQuery);
    if ($gsmStmt) {
        $gsmStmt->bind_param('sssss', $refNumber, $rollNo, $lineNumberRaw, $lineNumberPrefix, $lineNumberNumeric);
        if ($gsmStmt->execute()) {
            $gsmResult = $gsmStmt->get_result();
            if ($gsmResult && $gsmRow = $gsmResult->fetch_assoc()) {
                // If status is approved, mark as done even if approved_at is NULL
                if (strtolower(trim($gsmRow['test_status'])) === 'approved') {
                    $gsmDone = true;
                    if ($gsmRow['last_date']) {
                        $gsmDate = date('Y-m-d H:i', strtotime($gsmRow['last_date']));
                    } else {
                        $gsmDate = 'Approved';
                    }
                }
            }
        } else {
            error_log("GSM query error: " . $gsmStmt->error);
        }
        $gsmStmt->close();
    }
    
    // Strategy 2: If not found, try without line_number check (fallback)
    if (!$gsmDone) {
        if ($hasUpdatedAt) {
            $gsmQuery2 = "SELECT 
                MAX(COALESCE(approved_at, updated_at, created_at)) as last_date,
                MAX(status) as test_status
                FROM daily_gsm_checks 
                WHERE reference_number = ? 
                AND roll_no = ? 
                AND LOWER(TRIM(status)) = 'approved' 
                LIMIT 1";
        } else {
            $gsmQuery2 = "SELECT 
                MAX(COALESCE(approved_at, created_at)) as last_date,
                MAX(status) as test_status
                FROM daily_gsm_checks 
                WHERE reference_number = ? 
                AND roll_no = ? 
                AND LOWER(TRIM(status)) = 'approved' 
                LIMIT 1";
        }
        
        $gsmStmt2 = $conn->prepare($gsmQuery2);
        if ($gsmStmt2) {
            $gsmStmt2->bind_param('ss', $refNumber, $rollNo);
            if ($gsmStmt2->execute()) {
                $gsmResult2 = $gsmStmt2->get_result();
                if ($gsmResult2 && $gsmRow2 = $gsmResult2->fetch_assoc()) {
                    if (strtolower(trim($gsmRow2['test_status'])) === 'approved') {
                        $gsmDone = true;
                        if ($gsmRow2['last_date']) {
                            $gsmDate = date('Y-m-d H:i', strtotime($gsmRow2['last_date']));
                        } else {
                            $gsmDate = 'Approved';
                        }
                    }
                }
            }
            $gsmStmt2->close();
        }
    }
    
    // Strategy 3: If still not found, try with just reference_number (most flexible fallback)
    if (!$gsmDone) {
        if ($hasUpdatedAt) {
            $gsmQuery3 = "SELECT 
                MAX(COALESCE(approved_at, updated_at, created_at)) as last_date,
                MAX(status) as test_status
                FROM daily_gsm_checks 
                WHERE (reference_number = ? OR reference_number LIKE ?)
                AND LOWER(TRIM(status)) = 'approved' 
                ORDER BY approved_at DESC, created_at DESC
                LIMIT 1";
        } else {
            $gsmQuery3 = "SELECT 
                MAX(COALESCE(approved_at, created_at)) as last_date,
                MAX(status) as test_status
                FROM daily_gsm_checks 
                WHERE (reference_number = ? OR reference_number LIKE ?)
                AND LOWER(TRIM(status)) = 'approved' 
                ORDER BY approved_at DESC, created_at DESC
                LIMIT 1";
        }
        
        $refPattern = $refNumber . '%';
        $gsmStmt3 = $conn->prepare($gsmQuery3);
        if ($gsmStmt3) {
            $gsmStmt3->bind_param('ss', $refNumber, $refPattern);
            if ($gsmStmt3->execute()) {
                $gsmResult3 = $gsmStmt3->get_result();
                if ($gsmResult3 && $gsmRow3 = $gsmResult3->fetch_assoc()) {
                    if (strtolower(trim($gsmRow3['test_status'])) === 'approved') {
                        $gsmDone = true;
                        if ($gsmRow3['last_date']) {
                            $gsmDate = date('Y-m-d H:i', strtotime($gsmRow3['last_date']));
                        } else {
                            $gsmDate = 'Approved';
                        }
                    }
                }
            }
            $gsmStmt3->close();
        }
    }
}

// Check if Length Calibration is done (using reference_number, roll_no, and line_number from length_calibrations)
// Only consider tests with status = 'approved'
$lengthDone = false;
$lengthDate = null;
if ($hasLength) {
    // Check if updated_at column exists
    $hasUpdatedAt = false;
    $colCheck = $conn->query("SHOW COLUMNS FROM length_calibrations LIKE 'updated_at'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $hasUpdatedAt = true;
    }
    
    // Try multiple query strategies - first with line_number matching, then without if needed
    // Strategy 1: With line_number matching (most specific)
    if ($hasUpdatedAt) {
        $lengthQuery = "SELECT 
            MAX(COALESCE(approved_at, updated_at, created_at)) as last_date,
            MAX(status) as test_status
            FROM length_calibrations 
            WHERE reference_number = ? 
            AND roll_no = ? 
            AND (
                line_number = ? 
                OR line_number = ? 
                OR line_number = ? 
                OR line_number IS NULL
                OR line_number = ''
            )
            AND LOWER(TRIM(status)) = 'approved' 
            LIMIT 1";
    } else {
        $lengthQuery = "SELECT 
            MAX(COALESCE(approved_at, created_at)) as last_date,
            MAX(status) as test_status
            FROM length_calibrations 
            WHERE reference_number = ? 
            AND roll_no = ? 
            AND (
                line_number = ? 
                OR line_number = ? 
                OR line_number = ? 
                OR line_number IS NULL
                OR line_number = ''
            )
            AND LOWER(TRIM(status)) = 'approved' 
            LIMIT 1";
    }
    
    $lengthStmt = $conn->prepare($lengthQuery);
    if ($lengthStmt) {
        $lengthStmt->bind_param('sssss', $refNumber, $rollNo, $lineNumberRaw, $lineNumberPrefix, $lineNumberNumeric);
        if ($lengthStmt->execute()) {
            $lengthResult = $lengthStmt->get_result();
            if ($lengthResult && $lengthRow = $lengthResult->fetch_assoc()) {
                // If status is approved, mark as done even if approved_at is NULL
                if (strtolower(trim($lengthRow['test_status'])) === 'approved') {
                    $lengthDone = true;
                    if ($lengthRow['last_date']) {
                        $lengthDate = date('Y-m-d H:i', strtotime($lengthRow['last_date']));
                    } else {
                        $lengthDate = 'Approved';
                    }
                }
            }
        } else {
            error_log("Length Calibration query error: " . $lengthStmt->error);
        }
        $lengthStmt->close();
    }
    
    // Strategy 2: If not found, try without line_number check (fallback)
    if (!$lengthDone) {
        if ($hasUpdatedAt) {
            $lengthQuery2 = "SELECT 
                MAX(COALESCE(approved_at, updated_at, created_at)) as last_date,
                MAX(status) as test_status
                FROM length_calibrations 
                WHERE reference_number = ? 
                AND roll_no = ? 
                AND LOWER(TRIM(status)) = 'approved' 
                LIMIT 1";
        } else {
            $lengthQuery2 = "SELECT 
                MAX(COALESCE(approved_at, created_at)) as last_date,
                MAX(status) as test_status
                FROM length_calibrations 
                WHERE reference_number = ? 
                AND roll_no = ? 
                AND LOWER(TRIM(status)) = 'approved' 
                LIMIT 1";
        }
        
        $lengthStmt2 = $conn->prepare($lengthQuery2);
        if ($lengthStmt2) {
            $lengthStmt2->bind_param('ss', $refNumber, $rollNo);
            if ($lengthStmt2->execute()) {
                $lengthResult2 = $lengthStmt2->get_result();
                if ($lengthResult2 && $lengthRow2 = $lengthResult2->fetch_assoc()) {
                    if (strtolower(trim($lengthRow2['test_status'])) === 'approved') {
                        $lengthDone = true;
                        if ($lengthRow2['last_date']) {
                            $lengthDate = date('Y-m-d H:i', strtotime($lengthRow2['last_date']));
                        } else {
                            $lengthDate = 'Approved';
                        }
                    }
                }
            }
            $lengthStmt2->close();
        }
    }
    
    // Strategy 3: If still not found, try with just reference_number (most flexible fallback)
    if (!$lengthDone) {
        if ($hasUpdatedAt) {
            $lengthQuery3 = "SELECT 
                MAX(COALESCE(approved_at, updated_at, created_at)) as last_date,
                MAX(status) as test_status
                FROM length_calibrations 
                WHERE (reference_number = ? OR reference_number LIKE ? OR reference_number IS NULL)
                AND (roll_no = ? OR roll_no IS NULL)
                AND LOWER(TRIM(status)) = 'approved' 
                ORDER BY approved_at DESC, created_at DESC
                LIMIT 1";
        } else {
            $lengthQuery3 = "SELECT 
                MAX(COALESCE(approved_at, created_at)) as last_date,
                MAX(status) as test_status
                FROM length_calibrations 
                WHERE (reference_number = ? OR reference_number LIKE ? OR reference_number IS NULL)
                AND (roll_no = ? OR roll_no IS NULL)
                AND LOWER(TRIM(status)) = 'approved' 
                ORDER BY approved_at DESC, created_at DESC
                LIMIT 1";
        }
        
        $refPattern = $refNumber . '%';
        $lengthStmt3 = $conn->prepare($lengthQuery3);
        if ($lengthStmt3) {
            $lengthStmt3->bind_param('sss', $refNumber, $refPattern, $rollNo);
            if ($lengthStmt3->execute()) {
                $lengthResult3 = $lengthStmt3->get_result();
                if ($lengthResult3 && $lengthRow3 = $lengthResult3->fetch_assoc()) {
                    if (strtolower(trim($lengthRow3['test_status'])) === 'approved') {
                        $lengthDone = true;
                        if ($lengthRow3['last_date']) {
                            $lengthDate = date('Y-m-d H:i', strtotime($lengthRow3['last_date']));
                        } else {
                            $lengthDate = 'Approved';
                        }
                    }
                }
            }
            $lengthStmt3->close();
        }
    }
    
    // Strategy 4: Last resort - find ANY approved record for this roll_no (ignore reference_number)
    if (!$lengthDone) {
        if ($hasUpdatedAt) {
            $lengthQuery4 = "SELECT 
                approved_at as last_date,
                status as test_status
                FROM length_calibrations 
                WHERE roll_no = ? 
                AND LOWER(TRIM(status)) = 'approved' 
                ORDER BY approved_at DESC, created_at DESC
                LIMIT 1";
        } else {
            $lengthQuery4 = "SELECT 
                created_at as last_date,
                status as test_status
                FROM length_calibrations 
                WHERE roll_no = ? 
                AND LOWER(TRIM(status)) = 'approved' 
                ORDER BY created_at DESC
                LIMIT 1";
        }
        
        $lengthStmt4 = $conn->prepare($lengthQuery4);
        if ($lengthStmt4) {
            $lengthStmt4->bind_param('s', $rollNo);
            if ($lengthStmt4->execute()) {
                $lengthResult4 = $lengthStmt4->get_result();
                if ($lengthResult4 && $lengthRow4 = $lengthResult4->fetch_assoc()) {
                    if (strtolower(trim($lengthRow4['test_status'])) === 'approved') {
                        $lengthDone = true;
                        if ($lengthRow4['last_date']) {
                            $lengthDate = date('Y-m-d H:i', strtotime($lengthRow4['last_date']));
                        } else {
                            $lengthDate = 'Approved';
                        }
                    }
                }
            }
            $lengthStmt4->close();
        }
    }
    
    // Strategy 5: Absolute last resort - find most recent approved record (ignore all matching)
    // Only use if we have an approved record but can't match by reference/roll
    if (!$lengthDone) {
        if ($hasUpdatedAt) {
            $lengthQuery5 = "SELECT 
                approved_at as last_date,
                status as test_status,
                reference_number,
                roll_no
                FROM length_calibrations 
                WHERE LOWER(TRIM(status)) = 'approved' 
                AND approved_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY approved_at DESC
                LIMIT 1";
        } else {
            $lengthQuery5 = "SELECT 
                created_at as last_date,
                status as test_status,
                reference_number,
                roll_no
                FROM length_calibrations 
                WHERE LOWER(TRIM(status)) = 'approved' 
                AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY created_at DESC
                LIMIT 1";
        }
        
        $lengthStmt5 = $conn->prepare($lengthQuery5);
        if ($lengthStmt5 && $lengthStmt5->execute()) {
            $lengthResult5 = $lengthStmt5->get_result();
            if ($lengthResult5 && $lengthRow5 = $lengthResult5->fetch_assoc()) {
                // Only use this if the reference_number or roll_no matches (even partially), or if both are NULL
                $dbRef = $lengthRow5['reference_number'] ?? '';
                $dbRoll = $lengthRow5['roll_no'] ?? '';
                
                $refMatch = empty($dbRef) || empty($refNumber) || 
                           (strpos($dbRef, $refNumber) !== false || strpos($refNumber, $dbRef) !== false);
                $rollMatch = empty($dbRoll) || empty($rollNo) || 
                            ($dbRoll == $rollNo || strpos($dbRoll, $rollNo) !== false || strpos($rollNo, $dbRoll) !== false);
                
                // If both reference and roll are NULL in DB, or if there's a match, accept it
                $bothNull = (empty($dbRef) && empty($dbRoll));
                
                if (strtolower(trim($lengthRow5['test_status'])) === 'approved' && ($refMatch || $rollMatch || $bothNull)) {
                    $lengthDone = true;
                    if ($lengthRow5['last_date']) {
                        $lengthDate = date('Y-m-d H:i', strtotime($lengthRow5['last_date']));
                    } else {
                        $lengthDate = 'Approved';
                    }
                }
            }
            $lengthStmt5->close();
        }
    }
}

// Return results with error handling
try {
    // Debug: Log what we found (remove in production)
    if (!$lengthDone) {
        // Check what's actually in the database for debugging
        $debugStmt = $conn->prepare("SELECT id, reference_number, roll_no, line_number, status, approved_at 
                                     FROM length_calibrations 
                                     WHERE status = 'approved' 
                                     ORDER BY approved_at DESC 
                                     LIMIT 5");
        if ($debugStmt && $debugStmt->execute()) {
            $debugResult = $debugStmt->get_result();
            $debugData = [];
            while ($row = $debugResult->fetch_assoc()) {
                $debugData[] = $row;
            }
            error_log("Length Calibration Debug - Looking for ref: $refNumber, roll: $rollNo, line: $lineNo. Found approved records: " . json_encode($debugData));
            $debugStmt->close();
        }
    }
    
    $result = [
        'gsm_done' => $gsmDone,
        'gsm_date' => $gsmDate,
        'length_done' => $lengthDone,
        'length_date' => $lengthDate,
        'all_done' => ($gsmDone && $lengthDone)
    ];
    
    $conn->close();
    echo json_encode($result);
} catch (Exception $e) {
    error_log("check_qc_status.php error: " . $e->getMessage());
    echo json_encode([
        'error' => 'Database error',
        'message' => $e->getMessage()
    ]);
}
?>


