<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

try {
    $refNumber = $_POST['ref_number'] ?? '';
    $productAmount = $_POST['product_amount'] ?? '';
    $userId = $_SESSION['user_id'];
    $inspector = $_SESSION['full_name'] ?? $_SESSION['username'];
    
    if (empty($refNumber) || empty($productAmount)) {
        header("Location: ../forms/roll_qc_report.php?error=" . urlencode('Missing required fields'));
        exit();
    }
    
    // Ensure required columns exist on related tables (legacy schemas)
    $conn->query("ALTER TABLE daily_gsm_checks ADD COLUMN IF NOT EXISTS line_number VARCHAR(100) NULL");
    $conn->query("ALTER TABLE daily_gsm_checks ADD COLUMN IF NOT EXISTS roll_no VARCHAR(50) NULL");
    $conn->query("ALTER TABLE length_calibrations ADD COLUMN IF NOT EXISTS line_number VARCHAR(100) NULL");
    $conn->query("ALTER TABLE length_calibrations ADD COLUMN IF NOT EXISTS roll_no VARCHAR(50) NULL");

    // Get roll_no and line_no from reference
    // First check gsm_roll_entry (primary source - references that passed through GSM and Roll Entry)
    // Then fall back to fiber_to_roll_entry for backward compatibility
    $rollNo = null;
    $lineNo = null;
    
    // Check if gsm_roll_entry table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'gsm_roll_entry'");
    $gsmTableExists = ($tableCheck && $tableCheck->num_rows > 0);
    
    // Check gsm_roll_entry table first (if it exists)
    if ($gsmTableExists) {
        $refStmt = $conn->prepare("SELECT roll_no, line_number FROM gsm_roll_entry WHERE reference = ? LIMIT 1");
        if ($refStmt) {
            $refStmt->bind_param('s', $refNumber);
            $refStmt->execute();
            $refResult = $refStmt->get_result();
            
            if ($refResult && $refResult->num_rows > 0) {
                $refData = $refResult->fetch_assoc();
                $rollNo = $refData['roll_no'];
                $lineNo = $refData['line_number'] ?? $refData['line_no'] ?? null;
                $refStmt->close();
            } else {
                $refStmt->close();
            }
        }
    }
    
    // If not found in gsm_roll_entry, fallback to fiber_to_roll_entry (for backward compatibility)
    if ($rollNo === null) {
        $refStmt = $conn->prepare("SELECT roll_no, line_no FROM fiber_to_roll_entry WHERE reference_number = ? LIMIT 1");
        if ($refStmt) {
            $refStmt->bind_param('s', $refNumber);
            $refStmt->execute();
            $refResult = $refStmt->get_result();
            
            if ($refResult && $refResult->num_rows > 0) {
                $refData = $refResult->fetch_assoc();
                $rollNo = $refData['roll_no'];
                $lineNo = $refData['line_no'];
                $refStmt->close();
            } else {
                $refStmt->close();
                header("Location: ../forms/roll_qc_report.php?error=" . urlencode('Reference number not found'));
                exit();
            }
        } else {
            header("Location: ../forms/roll_qc_report.php?error=" . urlencode('Reference number not found'));
            exit();
        }
    }
    
    // Convert numeric line_no to "Line X" format for querying
    $lineNumber = is_numeric($lineNo) ? "Line " . $lineNo : $lineNo;
    
    // Check if user is AGM/Admin for auto-approval
    $userRole = strtolower(trim($_SESSION['role'] ?? ''));
    $isAGM = in_array($userRole, ['admin', 'agm', 'agm ops', 'agm operations', 'management']);
    
    // Check QC status (daily_gsm_checks and length_calibrations use line_number column)
    // Only count records with status = 'approved' (case-insensitive)
    $gsmDone = false;
    $gsmStmt = $conn->prepare("SELECT COUNT(*) as count FROM daily_gsm_checks 
                               WHERE roll_no = ? 
                               AND line_number = ? 
                               AND LOWER(TRIM(status)) = 'approved'");
    $gsmStmt->bind_param('ss', $rollNo, $lineNumber);
    $gsmStmt->execute();
    $gsmResult = $gsmStmt->get_result();
    if ($gsmResult && $gsmRow = $gsmResult->fetch_assoc()) {
        $gsmDone = ($gsmRow['count'] > 0);
    }
    $gsmStmt->close();
    
    $lengthDone = false;
    $lengthStmt = $conn->prepare("SELECT COUNT(*) as count FROM length_calibrations 
                                  WHERE roll_no = ? 
                                  AND line_number = ? 
                                  AND LOWER(TRIM(status)) = 'approved'");
    $lengthStmt->bind_param('ss', $rollNo, $lineNumber);
    $lengthStmt->execute();
    $lengthResult = $lengthStmt->get_result();
    if ($lengthResult && $lengthRow = $lengthResult->fetch_assoc()) {
        $lengthDone = ($lengthRow['count'] > 0);
    }
    $lengthStmt->close();
    
    // Determine overall status and approval fields
    if ($isAGM) {
        // AGM submission: Auto-approve
        $overallStatus = 'approved';
        $approved = 1;
        $approvedBy = $_SESSION['full_name'] ?? $_SESSION['username'];
        $approvedAt = date('Y-m-d H:i:s');
    } else {
        // Regular user submission
        $overallStatus = ($gsmDone && $lengthDone) ? 'Done' : 'Pending';
        $approved = 0;
        $approvedBy = null;
        $approvedAt = null;
    }
    
    // Create table if not exists
    $conn->query("CREATE TABLE IF NOT EXISTS roll_qc_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reference_number VARCHAR(100) NOT NULL,
        roll_no VARCHAR(50) NOT NULL,
        line_number VARCHAR(50) NOT NULL,
        product_amount DECIMAL(10,2) NOT NULL,
        gsm_check_status VARCHAR(20) NOT NULL,
        length_calibration_status VARCHAR(20) NOT NULL,
        overall_status VARCHAR(20) NOT NULL,
        inspector VARCHAR(100),
        user_id INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ref (reference_number),
        INDEX idx_roll (roll_no),
        INDEX idx_line (line_number)
    )");
    
    // Ensure approval columns exist
    $conn->query("ALTER TABLE roll_qc_reports ADD COLUMN IF NOT EXISTS approved TINYINT(1) DEFAULT 0");
    $conn->query("ALTER TABLE roll_qc_reports ADD COLUMN IF NOT EXISTS approved_by VARCHAR(100) NULL");
    $conn->query("ALTER TABLE roll_qc_reports ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL");
    
    // Insert report
    if ($isAGM) {
        $stmt = $conn->prepare("INSERT INTO roll_qc_reports 
            (reference_number, roll_no, line_number, product_amount, gsm_check_status, length_calibration_status, overall_status, inspector, user_id, approved, approved_by, approved_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    } else {
        $stmt = $conn->prepare("INSERT INTO roll_qc_reports 
            (reference_number, roll_no, line_number, product_amount, gsm_check_status, length_calibration_status, overall_status, inspector, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    }
    
    $gsmStatus = $gsmDone ? 'Done' : 'Pending';
    $lengthStatus = $lengthDone ? 'Done' : 'Pending';
    
    if ($isAGM) {
        $stmt->bind_param('sssdssssiiss', 
            $refNumber, 
            $rollNo, 
            $lineNumber, 
            $productAmount, 
            $gsmStatus, 
            $lengthStatus, 
            $overallStatus, 
            $inspector, 
            $userId,
            $approved,
            $approvedBy,
            $approvedAt
        );
    } else {
        $stmt->bind_param('sssdssssi', 
            $refNumber, 
            $rollNo, 
            $lineNumber, 
            $productAmount, 
            $gsmStatus, 
            $lengthStatus, 
            $overallStatus, 
            $inspector, 
            $userId
        );
    }
    
    if ($stmt->execute()) {
        $stmt->close();
        $conn->close();
        $statusMsg = $isAGM ? "Roll QC Report submitted and auto-approved successfully!" : "Roll QC Report submitted successfully! Status: $overallStatus";
        header("Location: ../forms/roll_qc_report.php?success=" . urlencode($statusMsg));
        exit();
    } else {
        throw new Exception("Failed to insert report");
    }
    
} catch (Exception $e) {
    error_log("Roll QC Report Error: " . $e->getMessage());
    header("Location: ../forms/roll_qc_report.php?error=" . urlencode('System error: ' . $e->getMessage()));
    exit();
}
?>
