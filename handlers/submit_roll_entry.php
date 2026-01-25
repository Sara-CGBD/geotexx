<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'php_error.log');
error_reporting(E_ALL);

session_start();
require_once 'security_config.php';

// Set timezone to Bangladesh
date_default_timezone_set('Asia/Dhaka');

// Validate required fields (roll_size is optional)
$required = ['date_time','operator_id','entry_id','reference_number','project_id','material_type','number_of_rolls','total_weight','total_area'];
foreach ($required as $key) {
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        header('Location: ../forms/roll_entry.php?error=' . urlencode('Missing field: ' . $key));
        exit;
    }
}

$dateTime        = trim($_POST['date_time']);
$operatorId      = (int)$_POST['operator_id'];
$entryId         = substr(trim($_POST['entry_id']), 0, 50);
$referenceNumber = substr(trim($_POST['reference_number']), 0, 200);
$projectId       = (int)$_POST['project_id'];
$materialType    = substr(trim($_POST['material_type']), 0, 100);
$rollSize        = isset($_POST['roll_size']) ? substr(trim($_POST['roll_size']), 0, 50) : '';
$numberOfRolls   = (int)$_POST['number_of_rolls'];
$totalWeight     = (float)$_POST['total_weight'];
$totalArea       = (float)$_POST['total_area'];
$actualGSM       = isset($_POST['actual_gsm']) ? (float)$_POST['actual_gsm'] : 0;

// Validate number of rolls
if ($numberOfRolls < 1 || $numberOfRolls > 10) {
    header('Location: ../forms/roll_entry.php?error=' . urlencode('Number of rolls must be between 1 and 10'));
    exit;
}

try {
    $conn = SecurityConfig::getConnection();

    // VALIDATION: Check if BOTH Daily GSM Check AND Length Calibration tests are APPROVED for this reference
    // AND Roll QC Report is APPROVED
    // Extract base reference (without roll suffix if present)
    $baseReference = preg_replace('/-\d+$/', '', $referenceNumber);
    
    // Get roll_no from gsm_roll_entry first (primary source - references from Roll QC Reports)
    // Then fall back to fiber_to_roll_entry for backward compatibility
    $rollNo = null;
    $lineNo = null;
    
    // Check if gsm_roll_entry table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'gsm_roll_entry'");
    $gsmTableExists = ($tableCheck && $tableCheck->num_rows > 0);
    
    // Check gsm_roll_entry table first (if it exists)
    if ($gsmTableExists) {
        $rollNoQuery = $conn->prepare("SELECT roll_no, line_number FROM gsm_roll_entry WHERE reference = ? LIMIT 1");
        if ($rollNoQuery) {
            $rollNoQuery->bind_param('s', $baseReference);
            $rollNoQuery->execute();
            $rollNoResult = $rollNoQuery->get_result();
            
            if ($rollNoResult->num_rows > 0) {
                $rollNoData = $rollNoResult->fetch_assoc();
                $rollNo = $rollNoData['roll_no'];
                $lineNo = $rollNoData['line_number'] ?? $rollNoData['line_no'] ?? null;
                $rollNoQuery->close();
            } else {
                $rollNoQuery->close();
            }
        }
    }
    
    // If not found in gsm_roll_entry, fallback to fiber_to_roll_entry (for backward compatibility)
    if ($rollNo === null) {
        $rollNoQuery = $conn->prepare("SELECT roll_no, line_no FROM fiber_to_roll_entry WHERE reference_number = ? LIMIT 1");
        if ($rollNoQuery) {
            $rollNoQuery->bind_param('s', $baseReference);
            $rollNoQuery->execute();
            $rollNoResult = $rollNoQuery->get_result();
            
            if ($rollNoResult->num_rows > 0) {
                $rollNoData = $rollNoResult->fetch_assoc();
                $rollNo = $rollNoData['roll_no'];
                $lineNo = $rollNoData['line_no'];
                $rollNoQuery->close();
            } else {
                $rollNoQuery->close();
                header('Location: ../forms/roll_entry.php?error=' . urlencode('Reference number not found'));
                exit;
            }
        } else {
            header('Location: ../forms/roll_entry.php?error=' . urlencode('Reference number not found'));
            exit;
        }
    }
    
    // Check Daily GSM Check status
    $gsmCheck = $conn->prepare("SELECT status FROM daily_gsm_checks WHERE roll_no = ? AND status = 'approved' LIMIT 1");
    $gsmCheck->bind_param('s', $rollNo);
    $gsmCheck->execute();
    $gsmResult = $gsmCheck->get_result();
    $gsmApproved = ($gsmResult->num_rows > 0);
    $gsmCheck->close();
    
    // Check Length Calibration status
    $calibrationCheck = $conn->prepare("SELECT status FROM length_calibrations WHERE roll_no = ? AND status = 'approved' LIMIT 1");
    $calibrationCheck->bind_param('s', $rollNo);
    $calibrationCheck->execute();
    $calibrationResult = $calibrationCheck->get_result();
    $calibrationApproved = ($calibrationResult->num_rows > 0);
    $calibrationCheck->close();
    
    // Check Roll QC Report approval status
    $rqcApproved = false;
    $hasRqcApproved = false;
    $hasRqcOverallStatus = false;
    
    // Check if approval columns exist
    $colCheck = $conn->query("SHOW COLUMNS FROM roll_qc_reports LIKE 'approved'");
    $hasRqcApproved = ($colCheck && $colCheck->num_rows > 0);
    $colCheck = $conn->query("SHOW COLUMNS FROM roll_qc_reports LIKE 'overall_status'");
    $hasRqcOverallStatus = ($colCheck && $colCheck->num_rows > 0);
    
    if ($hasRqcApproved || $hasRqcOverallStatus) {
        if ($hasRqcApproved && $hasRqcOverallStatus) {
            $rqcCheck = $conn->prepare("SELECT approved, overall_status FROM roll_qc_reports WHERE reference_number = ? AND (roll_no = ? OR (roll_no IS NULL AND ? IS NULL)) AND (approved = 1 OR overall_status IN ('approved', 'Done')) LIMIT 1");
            $rqcCheck->bind_param('sss', $baseReference, $rollNo, $rollNo);
        } elseif ($hasRqcApproved) {
            $rqcCheck = $conn->prepare("SELECT approved FROM roll_qc_reports WHERE reference_number = ? AND (roll_no = ? OR (roll_no IS NULL AND ? IS NULL)) AND approved = 1 LIMIT 1");
            $rqcCheck->bind_param('sss', $baseReference, $rollNo, $rollNo);
        } else {
            $rqcCheck = $conn->prepare("SELECT overall_status FROM roll_qc_reports WHERE reference_number = ? AND (roll_no = ? OR (roll_no IS NULL AND ? IS NULL)) AND overall_status IN ('approved', 'Done') LIMIT 1");
            $rqcCheck->bind_param('sss', $baseReference, $rollNo, $rollNo);
        }
        
        if ($rqcCheck) {
            $rqcCheck->execute();
            $rqcResult = $rqcCheck->get_result();
            $rqcApproved = ($rqcResult->num_rows > 0);
            $rqcCheck->close();
        }
    }
    
    // If any required test is not approved, reject submission
    $missingTests = [];
    if (!$gsmApproved) $missingTests[] = 'Daily GSM Check';
    if (!$calibrationApproved) $missingTests[] = 'Length Calibration';
    if (($hasRqcApproved || $hasRqcOverallStatus) && !$rqcApproved) {
        $missingTests[] = 'Roll QC Report';
    }
    
    if (!empty($missingTests)) {
        $errorMsg = 'Cannot proceed to Roll Entry. Missing approved tests: ' . implode(', ', $missingTests) . ' for reference ' . htmlspecialchars($baseReference);
        header('Location: ../forms/roll_entry.php?error=' . urlencode($errorMsg));
        exit;
    }

    // Create table if not exists (optional safeguard)
    $createTable = "CREATE TABLE IF NOT EXISTS roll_entry (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_id VARCHAR(50) NOT NULL,
        date_time DATETIME NOT NULL,
        operator_id INT NOT NULL,
        reference_number VARCHAR(200) NOT NULL,
        project_id INT NOT NULL,
        material_type VARCHAR(100) NOT NULL,
        roll_size VARCHAR(50),
        total_weight DECIMAL(10,2) NOT NULL,
        total_area DECIMAL(10,2),
        actual_gsm DECIMAL(10,2),
        summary TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_deleted TINYINT(1) DEFAULT 0,
        who_did VARCHAR(100) DEFAULT NULL,
        deleted_at DATETIME DEFAULT NULL,
        UNIQUE KEY (entry_id)
    )";
    $conn->query($createTable);
    
    // Alter existing table to add new columns if they don't exist
    $checkCol = $conn->query("SHOW COLUMNS FROM roll_entry LIKE 'reference_number'");
    if (!$checkCol || $checkCol->num_rows == 0) {
        $conn->query("ALTER TABLE roll_entry ADD COLUMN reference_number VARCHAR(200) AFTER operator_id");
    }
    
    $checkCol = $conn->query("SHOW COLUMNS FROM roll_entry LIKE 'material_type'");
    if (!$checkCol || $checkCol->num_rows == 0) {
        $conn->query("ALTER TABLE roll_entry ADD COLUMN material_type VARCHAR(100) AFTER project_id");
    }
    
    $checkCol = $conn->query("SHOW COLUMNS FROM roll_entry LIKE 'roll_size'");
    if (!$checkCol || $checkCol->num_rows == 0) {
        $conn->query("ALTER TABLE roll_entry ADD COLUMN roll_size VARCHAR(50) AFTER material_type");
    }
    
    // Add new fields: total_area and actual_gsm
    $checkCol = $conn->query("SHOW COLUMNS FROM roll_entry LIKE 'total_area'");
    if (!$checkCol || $checkCol->num_rows == 0) {
        $conn->query("ALTER TABLE roll_entry ADD COLUMN total_area DECIMAL(10,2) AFTER total_weight");
    }
    
    $checkCol = $conn->query("SHOW COLUMNS FROM roll_entry LIKE 'actual_gsm'");
    if (!$checkCol || $checkCol->num_rows == 0) {
        $conn->query("ALTER TABLE roll_entry ADD COLUMN actual_gsm DECIMAL(10,2) AFTER total_area");
    }

    // Prepare INSERT statement
    $stmt = $conn->prepare("INSERT INTO roll_entry (
        entry_id, date_time, operator_id, reference_number, project_id,
        material_type, roll_size, total_weight, total_area, actual_gsm
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    // Insert multiple rolls based on number_of_rolls
    $insertedCount = 0;
    for ($i = 1; $i <= $numberOfRolls; $i++) {
        // Modify entry_id and reference_number for each roll
        $currentEntryId = $entryId;
        $currentReferenceNumber = $referenceNumber;
        
        if ($numberOfRolls > 1) {
            // For multiple rolls, append roll number to entry_id and reference_number
            $currentEntryId = $entryId . '-' . $i;
            $currentReferenceNumber = $referenceNumber . '-' . $i;
        }
        
        $stmt->bind_param(
            'ssisissddd',
            $currentEntryId,
            $dateTime,
            $operatorId,
            $currentReferenceNumber,
            $projectId,
            $materialType,
            $rollSize,
            $totalWeight,
            $totalArea,
            $actualGSM
        );

        if (!$stmt->execute()) {
            throw new Exception('Execute failed for roll ' . $i . ': ' . $stmt->error);
        }
        $insertedCount++;
    }
    
    $stmt->close();

    $message = $insertedCount > 1 ? "$insertedCount roll entries saved successfully" : "Roll entry saved successfully";
    
    // Store reference number in session for QC Test link
    $_SESSION['last_roll_entry_reference'] = $referenceNumber;
    $_SESSION['show_qc_test_prompt'] = true;
    
    header('Location: ../forms/roll_entry.php?success=' . urlencode($message) . '&qc_prompt=1');
    exit;

} catch (Throwable $e) {
    header('Location: ../forms/roll_entry.php?error=' . urlencode($e->getMessage()));
    exit;
}

