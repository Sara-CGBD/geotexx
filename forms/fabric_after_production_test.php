<?php
session_start();
require_once 'security_config.php';

// Prevent browser caching to ensure fresh dropdown data after submission
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}
if (SecurityConfig::checkSessionTimeout()) {
    session_destroy();
    header("Location: ../login.html?error=timeout");
    exit();
}
SecurityConfig::updateSessionActivity();
if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    session_destroy();
    header("Location: ../login.html?error=disabled");
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();
$reporter_id = $_SESSION['user_id'];
$reporter_name = $_SESSION['username'];

// Fetch full name from database
$reporter_full_name = $reporter_name; // Default fallback
try {
    $stmt = $conn->prepare("SELECT full_name FROM users WHERE username = ?");
    $stmt->bind_param("s", $reporter_name);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $reporter_full_name = $row['full_name'] ?: $reporter_name;
    }
    $stmt->close();
} catch (Exception $e) {
    $reporter_full_name = $reporter_name;
}

// Fetch reference numbers from roll_entry and fiber_to_roll_entry (support bundles)
$references = [];
$bundleReferences = []; // Store bundle references separately

// Check if number_of_rolls column exists in roll_entry
$checkCol = $conn->query("SHOW COLUMNS FROM roll_entry LIKE 'number_of_rolls'");
$hasNumberOfRolls = ($checkCol && $checkCol->num_rows > 0);

// Fetch from roll_entry (supports bundles)
try {
    $rollEntryQuery = $conn->query("
        SELECT reference_number, 
               MAX(created_at) as created_at" . 
               ($hasNumberOfRolls ? ", MAX(number_of_rolls) as number_of_rolls" : "") . "
        FROM roll_entry 
        WHERE reference_number IS NOT NULL 
        GROUP BY reference_number
        ORDER BY created_at DESC 
        LIMIT 100
    ");
    
    if ($rollEntryQuery) {
        while ($row = $rollEntryQuery->fetch_assoc()) {
            $ref = $row['reference_number'];
            
            // Check if this is a bundle reference (ends with -N pattern)
            if (preg_match('/-(\d+)$/', $ref, $matches)) {
                $rollCount = (int)$matches[1];
                $baseRef = preg_replace('/-\d+$/', '', $ref);
                
                // Check if already tested using prepared statements for security
                // Check: 1. Exact reference match, 2. Part of bundle range, 3. Individual rolls
                $isTested = false;
                
                // First check: exact match and LIKE patterns for bundle ranges
                $checkStmt = $conn->prepare("
                    SELECT id FROM fabric_after_production_tests 
                    WHERE status IN ('pending', 'approved')
                    AND (
                        sample_id = ?
                        OR sample_id LIKE CONCAT(?, '|%')
                        OR sample_id LIKE CONCAT('%|', ?)
                        OR sample_id LIKE CONCAT('%|', ?, '|%')
                    )
                    LIMIT 1
                ");
                
                if ($checkStmt) {
                    $checkStmt->bind_param("ssss", $ref, $ref, $ref, $ref);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    if ($checkResult && $checkResult->num_rows > 0) {
                        $isTested = true;
                    }
                    $checkStmt->close();
                }
                
                // Also check if any individual roll in this bundle has been submitted
                if (!$isTested && $baseRef) {
                    for ($i = 1; $i <= $rollCount; $i++) {
                        $individualRef = $baseRef . '-' . $i;
                        $rollCheckStmt = $conn->prepare("
                            SELECT id FROM fabric_after_production_tests 
                            WHERE status IN ('pending', 'approved')
                            AND (
                                sample_id = ?
                                OR sample_id LIKE CONCAT(?, '|%')
                                OR sample_id LIKE CONCAT('%|', ?)
                                OR sample_id LIKE CONCAT('%|', ?, '|%')
                            )
                            LIMIT 1
                        ");
                        
                        if ($rollCheckStmt) {
                            $rollCheckStmt->bind_param("ssss", $individualRef, $individualRef, $individualRef, $individualRef);
                            $rollCheckStmt->execute();
                            $rollResult = $rollCheckStmt->get_result();
                            if ($rollResult && $rollResult->num_rows > 0) {
                                $isTested = true;
                            }
                            $rollCheckStmt->close();
                        }
                        
                        if ($isTested) break;
                    }
                }
                
                if (!$isTested) {
                    $bundleReferences[] = [
                        'reference' => $ref,
                        'base_reference' => $baseRef,
                        'roll_count' => $rollCount,
                        'date' => $row['created_at']
                    ];
                }
            } else {
                // Single roll reference - use prepared statement for security
                $isTested = false;
                
                $checkStmt = $conn->prepare("
                    SELECT id FROM fabric_after_production_tests 
                    WHERE status IN ('pending', 'approved')
                    AND (
                        sample_id = ?
                        OR sample_id LIKE CONCAT(?, '|%')
                        OR sample_id LIKE CONCAT('%|', ?)
                        OR sample_id LIKE CONCAT('%|', ?, '|%')
                    )
                    LIMIT 1
                ");
                
                if ($checkStmt) {
                    $checkStmt->bind_param("ssss", $ref, $ref, $ref, $ref);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    if ($checkResult && $checkResult->num_rows > 0) {
                        $isTested = true;
                    }
                    $checkStmt->close();
                }
                
                if (!$isTested) {
                    $references[] = [
                        'reference' => $ref,
                        'is_individual' => false
                    ];
                }
            }
        }
    }
} catch (Exception $e) {
    // Continue even if roll_entry query fails
}

// Also fetch from fiber_to_roll_entry (legacy support)
try {
    $refQuery = $conn->query("
        SELECT DISTINCT f.reference_number 
        FROM fiber_to_roll_entry f
        WHERE f.reference_number IS NOT NULL 
            AND f.reference_number != ''
        ORDER BY f.date_time DESC 
        LIMIT 50
    ");
    if ($refQuery) {
        while ($row = $refQuery->fetch_assoc()) {
            $ref = $row['reference_number'];
            
            // Check if already tested - use prepared statement for security
            $isTested = false;
            
            $checkStmt = $conn->prepare("
                SELECT id FROM fabric_after_production_tests 
                WHERE status IN ('pending', 'approved')
                AND (
                    sample_id = ?
                    OR sample_id LIKE CONCAT(?, '|%')
                    OR sample_id LIKE CONCAT('%|', ?)
                    OR sample_id LIKE CONCAT('%|', ?, '|%')
                )
                LIMIT 1
            ");
            
            if ($checkStmt) {
                $checkStmt->bind_param("ssss", $ref, $ref, $ref, $ref);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                if ($checkResult && $checkResult->num_rows > 0) {
                    $isTested = true;
                }
                $checkStmt->close();
            }
            
            // Only add if not already in references, not part of a bundle, and not tested
            if (!$isTested) {
                $exists = false;
                foreach ($references as $r) {
                    if (is_array($r) && $r['reference'] === $ref) {
                        $exists = true;
                        break;
                    } elseif (!is_array($r) && $r === $ref) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $references[] = [
                        'reference' => $ref,
                        'is_individual' => false
                    ];
                }
            }
        }
    }
} catch (Exception $e) {
    // Continue
}

// Sort bundle references: Group by base_reference, then sort by roll_count (1, 2, 3, 4...)
usort($bundleReferences, function($a, $b) {
    // First, compare by base_reference
    $baseCompare = strcmp($a['base_reference'], $b['base_reference']);
    if ($baseCompare !== 0) {
        return $baseCompare;
    }
    // If same base_reference, sort by roll_count (ascending: 1, 2, 3, 4...)
    return $a['roll_count'] - $b['roll_count'];
});

// Check user role for approval permissions
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$can_approve = in_array($user_role, ['admin', 'agm ops', 'agm operations'], true);
$roleLower = $user_role;

$message = '';
$error = '';

// Check for success message from session (after redirect)
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

/**
 * Fabric After Production Test Handler
 */
class FabricAfterProductionTestHandler {
    private $conn;
    private $reporter_id;
    private $reporter_name;
    private $reporter_full_name;
    
    public function __construct($connection, $reporter_id, $reporter_name, $reporter_full_name = null) {
        $this->conn = $connection;
        $this->reporter_id = $reporter_id;
        $this->reporter_name = $reporter_name;
        $this->reporter_full_name = $reporter_full_name ?: $reporter_name;
    }
    
    public function createCounterTable() {
        $sql = "CREATE TABLE IF NOT EXISTS fabric_after_production_counters (
            date_key VARCHAR(8) PRIMARY KEY,
            counter INT NOT NULL DEFAULT 0,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $this->conn->query($sql);
    }
    
    public function createMainTable() {
        $sql = "CREATE TABLE IF NOT EXISTS fabric_after_production_tests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_number VARCHAR(50) UNIQUE,
            sample_id VARCHAR(200),
            received_from VARCHAR(200),
            sample_received_date DATETIME,
            sample_tested_date DATE,
            test_performed_by VARCHAR(100),
            note TEXT,
            test_results JSON,
            approved_by VARCHAR(100) NULL,
            qc_entry_id INT NULL,
            reporter_id INT NOT NULL,
            reporter_name VARCHAR(255) NOT NULL,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            approved_at DATETIME NULL,
            remarks TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (qc_entry_id) REFERENCES qc_entries(id)
        )";
        $this->conn->query($sql);
        
        // Add missing columns if they don't exist
        $columns_to_add = [
            'approved_by' => "ALTER TABLE fabric_after_production_tests ADD COLUMN approved_by VARCHAR(100) NULL AFTER test_results",
            'reporter_id' => "ALTER TABLE fabric_after_production_tests ADD COLUMN reporter_id INT NOT NULL AFTER qc_entry_id",
            'reporter_name' => "ALTER TABLE fabric_after_production_tests ADD COLUMN reporter_name VARCHAR(255) NOT NULL AFTER reporter_id",
            'status' => "ALTER TABLE fabric_after_production_tests ADD COLUMN status ENUM('pending','approved','rejected') DEFAULT 'pending' AFTER reporter_name",
            'approved_at' => "ALTER TABLE fabric_after_production_tests ADD COLUMN approved_at DATETIME NULL AFTER status",
            'remarks' => "ALTER TABLE fabric_after_production_tests ADD COLUMN remarks TEXT NULL AFTER approved_at"
        ];
        
        foreach ($columns_to_add as $col => $sql) {
            $check = $this->conn->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
                                         WHERE TABLE_SCHEMA = DATABASE() 
                                         AND TABLE_NAME = 'fabric_after_production_tests' 
                                         AND COLUMN_NAME = '$col'");
            if ($check && $check->fetch_assoc()['cnt'] == 0) {
                $this->conn->query($sql);
            }
        }
    }
    
    public function getNextReportNumber() {
        $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $hour = (int)$now->format('H');
        
        if ($hour < 8) {
            $now->modify('-1 day');
        }
        
        $dateKey = $now->format('Ymd');
        
        // Count actual reports for this day key (real-time)
        $pattern = 'FAPT-' . $dateKey . '-%';
        $countStmt = $this->conn->prepare("SELECT COUNT(*) as report_count FROM fabric_after_production_tests WHERE report_number LIKE ?");
        $countStmt->bind_param("s", $pattern);
        $countStmt->execute();
        $result = $countStmt->get_result();
        
        $nextCounter = 1;
        if ($result && $row = $result->fetch_assoc()) {
            $nextCounter = (int)$row['report_count'] + 1;
        }
        $countStmt->close();
        
        return sprintf("FAPT-%s-%05d", $dateKey, $nextCounter);
    }
    
    public function generateAndIncrementReportNumber() {
        $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $hour = (int)$now->format('H');
        
        if ($hour < 8) {
            $now->modify('-1 day');
        }
        
        $dateKey = $now->format('Ymd');
        
        // Count actual reports for this day key (real-time)
        $pattern = 'FAPT-' . $dateKey . '-%';
        $countStmt = $this->conn->prepare("SELECT COUNT(*) as report_count FROM fabric_after_production_tests WHERE report_number LIKE ?");
        $countStmt->bind_param("s", $pattern);
        $countStmt->execute();
        $result = $countStmt->get_result();
        
        $counter = 1;
        if ($result && $row = $result->fetch_assoc()) {
            $counter = (int)$row['report_count'] + 1;
        }
        $countStmt->close();
        
        return sprintf("FAPT-%s-%05d", $dateKey, $counter);
    }
    
    public function saveReport($data) {
        try {
            $this->createMainTable();
            
            $report_number = $this->generateAndIncrementReportNumber();
            
            $test_results_json = json_encode($data['test_results'] ?? []);
            
            $user_role = strtolower(trim($_SESSION['role'] ?? ''));
            $status = ($user_role === 'admin' || $user_role === 'agm ops' || $user_role === 'agm operations') ? 'approved' : 'pending';
            
            $approved_by_value = (isset($data['approved_by']) && !empty($data['approved_by'])) ? $data['approved_by'] : null;
            $qc_entry_id = isset($data['qc_entry_id']) ? (int)$data['qc_entry_id'] : null;
            
            $stmt = $this->conn->prepare("
                INSERT INTO fabric_after_production_tests (
                    report_number, sample_id, 
                    received_from, sample_received_date, sample_tested_date, test_performed_by, note, 
                    test_results, approved_by, qc_entry_id, reporter_id, reporter_name, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Get sample_id - handle both single and bundle references
            $sample_id = '';
            if (isset($data['bundle_reference_range']) && !empty($data['bundle_reference_range'])) {
                // Bundle mode - use the range (from|to format)
                $sample_id = trim($data['bundle_reference_range']);
            } elseif (isset($data['from_reference']) && isset($data['to_reference']) && 
                      !empty($data['from_reference']) && !empty($data['to_reference'])) {
                // Bundle mode - use from|to format
                $sample_id = trim($data['from_reference']) . '|' . trim($data['to_reference']);
            } elseif (isset($data['sample_id']) && !empty($data['sample_id'])) {
                // Single mode
                $sample_id = trim($data['sample_id']);
            }
            
            $stmt->bind_param(
                "sssssssssiiss",
                $report_number,
                $sample_id,
                $data['received_from'],
                $data['sample_received_date'],
                $data['sample_tested_date'],
                $data['test_performed_by'],
                $data['note'],
                $test_results_json,
                $approved_by_value,
                $qc_entry_id,
                $this->reporter_id,
                $this->reporter_full_name,
                $status
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to save report: " . $stmt->error);
            }
            
            $report_id = $this->conn->insert_id;
            $stmt->close();
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => "Fabric After Production Test saved successfully! Report Number: $report_number",
                'report_id' => $report_id,
                'report_number' => $report_number,
                'status' => $status
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }
    
    public function getPendingReports($limit = 20) {
        $stmt = $this->conn->prepare(
            "SELECT id, report_number, sample_id, test_performed_by AS tested_by, status, created_at, updated_at
             FROM fabric_after_production_tests WHERE status = 'pending' ORDER BY updated_at DESC LIMIT ?"
        );
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $reports = [];
        while ($row = $result->fetch_assoc()) {
            $reports[] = $row;
        }
        $stmt->close();
        return $reports;
    }
    
    public function approveOrReject($report_number, $action, $comment = '') {
        try {
            $status = ($action === 'approved') ? 'approved' : 'rejected';
            $approved_by = $_SESSION['username'];
            $approved_at = date('Y-m-d H:i:s');
            
            $stmt = $this->conn->prepare(
                "UPDATE fabric_after_production_tests 
                 SET status = ?, approved_by = ?, approved_at = ?, remarks = ?
                 WHERE report_number = ?"
            );
            
            $stmt->bind_param("sssss", $status, $approved_by, $approved_at, $comment, $report_number);
            
            if ($stmt->execute()) {
                $stmt->close();
                return [
                    'success' => true,
                    'message' => "Report $report_number has been " . ($status === 'approved' ? 'approved' : 'rejected')
                ];
            } else {
                throw new Exception("Failed to update report: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    public function getRejectedReportsForUser($user_id) {
        $stmt = $this->conn->prepare(
            "SELECT id, report_number, sample_id, status, remarks, created_at, approved_by
             FROM fabric_after_production_tests 
             WHERE reporter_id = ? AND status = 'rejected' 
             ORDER BY created_at DESC 
             LIMIT 10"
        );
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $reports = [];
        while ($row = $result->fetch_assoc()) {
            $reports[] = $row;
        }
        $stmt->close();
        return $reports;
    }
    
    public function canApproveReports() {
        $role = strtolower(trim($_SESSION['role'] ?? ''));
        return in_array($role, ['admin', 'agm ops', 'agm operations'], true);
    }
}

// Initialize the handler
$fabricAfterProdHandler = new FabricAfterProductionTestHandler($conn, $reporter_id, $reporter_name, $reporter_full_name);

// Ensure tables exist
$fabricAfterProdHandler->createMainTable();
$fabricAfterProdHandler->createCounterTable();

// Fetch pending reports for admin/AGM Ops
$pending_reports = [];
if ($can_approve) {
    $pending_reports = $fabricAfterProdHandler->getPendingReports(20);
}

// Generate report number for display (without incrementing)
$generated_report_number = $fabricAfterProdHandler->getNextReportNumber();

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wf_action'], $_POST['wf_report_number'])) {
    try {
        $action = $_POST['wf_action'];
        $report_number = trim($_POST['wf_report_number']);
        $comment = trim($_POST['wf_comment'] ?? '');
        
        // Handle rejection reasons checkboxes for fabric after production
        if ($action === 'rejected' && isset($_POST['fabric_after_rejection_reasons']) && is_array($_POST['fabric_after_rejection_reasons'])) {
            $rejection_reasons = array_map('trim', $_POST['fabric_after_rejection_reasons']);
            $reasons_text = implode(', ', $rejection_reasons);
            $comment = "Rejection Reasons: " . $reasons_text . ($comment ? "\n\nAdditional Comments: " . $comment : '');
        }
        
        if (!$fabricAfterProdHandler->canApproveReports()) {
            throw new Exception("You don't have permission to approve reports.");
        }
        
        $result = $fabricAfterProdHandler->approveOrReject($report_number, $action, $comment);
        $message = $result['message'];
        
        // Refresh pending reports
        $pending_reports = $fabricAfterProdHandler->getPendingReports(20);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle QC Entry ID parameter (if coming from QC Entry)
$qc_entry_id = isset($_GET['qc_entry_id']) ? (int)$_GET['qc_entry_id'] : null;

// If coming from QC Entry, fetch QC entry details
$qc_entry_data = null;
if ($qc_entry_id) {
    $stmt = $conn->prepare("SELECT * FROM qc_entries WHERE id = ?");
    $stmt->bind_param("i", $qc_entry_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $qc_entry_data = $result->fetch_assoc();
    }
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    try {
        // Use the new report number from handler
        $_POST['report_number'] = $generated_report_number;
        
        $result = $fabricAfterProdHandler->saveReport($_POST);
        $message = $result['message'];
        
        // Auto-approval message
        if ($can_approve) {
            $message = "✅ Fabric Internal Production Sample Test saved and auto-approved! Report Number: " . $result['report_number'];
        } else {
            $message = "✅ Fabric Internal Production Sample Test submitted successfully! Report Number: " . $result['report_number'] . " (Status: Pending Approval)";
        }
        
        // Store success message in session and redirect to refresh dropdown
        $_SESSION['success_message'] = $message;
        $success_msg = urlencode($message);
        header("Location: fabric_after_production_test.php?success=1&msg={$success_msg}&t=" . time());
        exit();
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// OLD CODE - BACKUP (disabled)
if (false && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report_OLD'])) {
    try {
        $conn->begin_transaction();
        
        $report_number = trim($_POST['report_number']);
        // Get sample_id - use individual roll reference if bundle is selected
        $sample_id = '';
        if (isset($_POST['individual_roll_reference']) && !empty($_POST['individual_roll_reference'])) {
            $sample_id = trim($_POST['individual_roll_reference']);
        } elseif (isset($_POST['sample_id']) && !empty($_POST['sample_id'])) {
            $sample_id = trim($_POST['sample_id']);
        }
        $received_from = trim($_POST['received_from']);
        $sample_received_date = trim($_POST['sample_received_date']);
        $sample_tested_date = trim($_POST['sample_tested_date']);
        $test_performed_by = trim($_POST['test_performed_by']);
        $note = trim($_POST['note']);
        $test_results = $_POST['test_results'] ?? [];
        $qc_entry_id = isset($_POST['qc_entry_id']) ? (int)$_POST['qc_entry_id'] : null;
        
        if (empty($report_number) || empty($sample_id) || empty($received_from) || empty($sample_received_date) || empty($sample_tested_date)) {
            throw new Exception("All mandatory fields are required.");
        }
        
        // Create fabric_after_production_tests table if not exists
        $createTable = "CREATE TABLE IF NOT EXISTS fabric_after_production_tests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_number VARCHAR(50) UNIQUE,
            sample_id VARCHAR(200),
            received_from VARCHAR(200),
            sample_received_date DATETIME,
            sample_tested_date DATE,
            test_performed_by VARCHAR(100),
            note TEXT,
            test_results JSON,
            qc_entry_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (qc_entry_id) REFERENCES qc_entries(id)
        )";
        $conn->query($createTable);
        
        // Insert test data
        $test_results_json = json_encode($test_results);
        $stmt = $conn->prepare("
            INSERT INTO fabric_after_production_tests (
                report_number, sample_id, 
                received_from, sample_received_date, sample_tested_date, test_performed_by, note, test_results, qc_entry_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param("ssssssssi", $report_number, $sample_id, 
                         $received_from, $sample_received_date, $sample_tested_date, $test_performed_by, $note, $test_results_json, $qc_entry_id);
        
        if ($stmt->execute()) {
            $conn->commit();
            $message = "Fabric After Production Test Summary Summary saved successfully! Report Number: $report_number";
        } else {
            throw new Exception("Error saving test report: " . $stmt->error);
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error: " . $e->getMessage();
    }
}

// Function to generate report number
function generateReportNumber() {
    global $conn;
    
    $now = new DateTime();
    $hour = (int)$now->format('H');
    
    // Determine shift (8AM to 7:59AM next day is day shift)
    $isDayShift = $hour >= 8;
    
    // Get shift date
    $shiftDate = clone $now;
    if (!$isDayShift && $hour < 8) {
        $shiftDate->modify('-1 day');
    }
    
    $dateStr = $shiftDate->format('Ymd');
    
    // Check if table exists first
    $tableCheck = $conn->query("SHOW TABLES LIKE 'fabric_after_production_tests'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        // Get next sequence number for this date
        $query = "SELECT MAX(CAST(SUBSTRING(report_number, -3) AS UNSIGNED)) as max_seq 
                  FROM fabric_after_production_tests 
                  WHERE DATE(created_at) = ?";
        $stmt = $conn->prepare($query);
        $dateForQuery = $shiftDate->format('Y-m-d');
        $stmt->bind_param("s", $dateForQuery);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        $nextSeq = ($row['max_seq'] ?? 0) + 1;
    } else {
        $nextSeq = 1;
    }
    
    return 'FAP-' . $dateStr . '-' . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
}

// Test parameters with their standards and units
$test_parameters = [
    1 => ['param' => 'Mass Per Unit', 'standards' => ['ASTM D5199', 'ISO 9863-1'], 'unit' => 'g/m²'],
    2 => ['param' => 'Thickness(under 2kPa pressure)', 'standards' => ['ASTM D5199'], 'unit' => 'mm'],
    3 => ['param' => 'Strip Tensile Strength (x-dir**)/CMD', 'standards' => ['ASTM D4595', 'ISO 10319'], 'unit' => 'kN/m'],
    4 => ['param' => 'Strip Tensile Elongation (x-dir**)/CMD', 'standards' => ['ASTM D4595', 'ISO 10319'], 'unit' => '%'],
    5 => ['param' => 'Strip Tensile Strength (y-dir**)/MD', 'standards' => ['ASTM D4595', 'ISO 10319'], 'unit' => 'kN/m'],
    6 => ['param' => 'Strip Tensile Elongation (y-dir**)/MD', 'standards' => ['ASTM D4595', 'ISO 10319'], 'unit' => '%'],
    7 => ['param' => 'CBR Puncture Resistance', 'standards' => ['ASTM D6241', 'ISO 12236'], 'unit' => 'N'],
    8 => ['param' => 'Grab Breaking Load (x-dir**)/CMD', 'standards' => ['ASTM D4632'], 'unit' => 'N'],
    9 => ['param' => 'Grab Breaking Elongation (x-dir**)/CMD', 'standards' => ['ASTM D4632'], 'unit' => '%'],
    10 => ['param' => 'Grab Breaking Load (y-dir**)/MD', 'standards' => ['ASTM D4632'], 'unit' => 'N'],
    11 => ['param' => 'Grab Breaking Elongation (y-dir**)/MD', 'standards' => ['ASTM D4632'], 'unit' => '%']
];

// Unit options for dropdown
$unit_options = ['g/m²', 'mm', 'kN/m', '%', 'N'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fabric After Production Test Summary Summary</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
  .container { max-width:1200px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);} 
  h1 { text-align:center; font-size:28px; margin-bottom:30px; color:#2c3e50; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  input[type="text"], input[type="number"], select, textarea { padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px); }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .readonly { background:#ecf0f1; }
  .test-table { width:100%; border-collapse:collapse; margin-top:15px; }
  .test-table th, .test-table td { border:1px solid #ddd; padding:8px; text-align:center; }
  .test-table th { background:#3498db; color:#fff; font-weight:600; }
  .test-table input, .test-table select { width:100%; border:none; background:transparent; text-align:center; }
  .test-table select { padding:5px; }
  .form-row { display:flex; gap:20px; margin-bottom:20px; }
  .form-row .form-group { flex:1; }
  .note-section { margin-top:30px; padding:15px; background:#f8f9fa; border-radius:8px; border-left:4px solid #007bff; }
  .note-section h4 { margin:0 0 10px 0; color:#007bff; }
  .note-section p { margin:0; color:#6c757d; }
</style>
</head>
<body>
<div class="container">
  <h1> Fabric Internal Production Sample Test Summary </h1>

  <?php if (isset($_SESSION['update_success'])): ?>
    <div class="alert alert-success" style="background:#d4edda;color:#155724;padding:12px;border-radius:6px;border:1px solid #c3e6cb;margin-bottom:15px;">
      ✅ <?php echo htmlspecialchars($_SESSION['update_success']); unset($_SESSION['update_success']); ?>
    </div>
  <?php endif; ?>

  <?php if ($message): ?>
    <div class="alert alert-success" style="background:#d4edda;color:#155724;padding:12px;border-radius:6px;border:1px solid #c3e6cb;margin-bottom:15px;">
      ✅ <?php echo htmlspecialchars($message); ?>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-error" style="background:#f8d7da;color:#721c24;padding:12px;border-radius:6px;border:1px solid #f5c6cb;margin-bottom:15px;">
      ❌ <?php echo htmlspecialchars($error); ?>
    </div>
  <?php endif; ?>

  <!-- Back to Dashboard Link -->
  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
  </div>

  <?php if ($can_approve): ?>
  <!-- Pending Approval Queue -->
    <?php if (!empty($pending_reports)): ?>
    <div style="margin-top:16px; padding:12px; border:1px solid #ddd; border-radius:8px; background:#fff;">
      <h3 style="margin:0 0 12px 0;">Pending Reports</h3>
      <table class="test-table">
        <thead>
          <tr>
            <th>Report No</th>
            <th>Reference</th>
            <th>Tested By</th>
            <th>Last Updated</th>
            <th style="width:200px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending_reports as $pr): ?>
          <tr>
            <td><?php echo htmlspecialchars($pr['report_number']); ?></td>
            <td><?php echo htmlspecialchars($pr['sample_id']); ?></td>
            <td><?php echo htmlspecialchars($pr['tested_by']); ?></td>
            <td><?php echo htmlspecialchars($pr['updated_at']); ?></td>
            <td>
              <a href="../admin/view_fabric_after_prod_report.php?id=<?php echo $pr['id']; ?>" target="_blank" class="submit-btn" style="padding:6px 10px; text-decoration:none; display:inline-block; background:#3498db; margin-right:4px;">View</a>
              <form method="POST" action="" style="display:inline; margin-right:4px;" onsubmit="return confirmApproval(this);">
                <input type="hidden" name="wf_report_number" value="<?php echo htmlspecialchars($pr['report_number']); ?>">
                <input type="hidden" name="wf_comment" value="Approved from queue">
                <button type="submit" name="wf_action" value="approved" class="submit-btn" style="padding:6px 10px;">Approve</button>
              </form>
              <button type="button" onclick="openFabricAfterRejectModal('<?php echo htmlspecialchars($pr['report_number']); ?>')" class="clear-btn" style="padding:6px 10px; border:none; cursor:pointer;">Reject</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!$can_approve): ?>
  <!-- Rejected Reports for Tester to Review -->
  <?php $rejected = $fabricAfterProdHandler->getRejectedReportsForUser($reporter_id); if (!empty($rejected)): ?>
  <div style="margin-top:16px; padding:12px; border:1px solid #f8d7da; border-radius:8px; background:#fff3cd;">
    <h3 style="margin:0 0 12px 0; color:#721c24;">❌ Rejected Reports - Action Required</h3>
    <p style="margin:0 0 12px 0; color:#856404;">The following reports were rejected. Please review the comments and make corrections.</p>
    <table class="test-table">
      <thead>
        <tr>
          <th>Report No</th>
          <th>Reference</th>
          <th>Rejected By</th>
          <th>Rejection Comments</th>
          <th>Submitted At</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rejected as $rj): ?>
        <tr>
          <td><?php echo htmlspecialchars($rj['report_number']); ?></td>
          <td><?php echo htmlspecialchars($rj['sample_id']); ?></td>
          <td><?php echo htmlspecialchars($rj['approved_by'] ?? 'N/A'); ?></td>
          <td style="text-align:left; max-width:300px; color:#721c24; font-weight:600;"><?php echo htmlspecialchars($rj['remarks'] ?? 'No comments'); ?></td>
          <td><?php echo htmlspecialchars($rj['created_at']); ?></td>
          <td>
            <a href="edit_fabric_after_prod.php?id=<?php echo $rj['id']; ?>" class="submit-btn" style="padding:6px 10px; text-decoration:none; display:inline-block; background:#f39c12; color:#fff;">Edit & Resubmit</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin:12px 0 0 0; color:#856404; font-style:italic;">💡 Note: Click "Edit & Resubmit" to pre-fill and modify the rejected report.</p>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <form method="POST" action="">

    <!-- Date/Time and Shift Display -->
    <div id="dateTimeDisplay" class="summary-info"></div>
    <div id="shiftBanner" class="summary-info"></div>
    
    <?php if ($qc_entry_id && $qc_entry_data): ?>
    <!-- QC Entry Link Banner -->
    <div style="background:#e8f4fd; border:1px solid #3498db; border-radius:8px; padding:15px; margin-bottom:20px;">
      <h3 style="margin:0 0 10px 0; color:#2980b9;">🔗 Linked to QC Entry</h3>
      <p style="margin:5px 0;"><strong>QC ID:</strong> <?php echo htmlspecialchars($qc_entry_data['qc_id']); ?></p>
      <p style="margin:5px 0;"><strong>Stage:</strong> <?php echo htmlspecialchars($qc_entry_data['qc_stage']); ?></p>
      <p style="margin:5px 0;"><strong>Type:</strong> <?php echo htmlspecialchars($qc_entry_data['qc_type']); ?></p>
    </div>
    <?php endif; ?>

    <!-- Hidden fields -->
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">
    <?php if ($qc_entry_id): ?>
    <input type="hidden" name="qc_entry_id" value="<?php echo htmlspecialchars($qc_entry_id); ?>">
    <?php endif; ?>

    <!-- Sample Information -->
    <div class="form-group">
      <label>Report Number:</label>
      <input type="text" name="report_number" id="report_number" value="<?php echo htmlspecialchars($generated_report_number); ?>" readonly class="readonly">
    </div>

    <div class="form-group">
      <label>Reference Type:</label>
      <div style="display:flex; gap:10px; margin-bottom:15px;">
        <button type="button" id="fabric_after_ref_type_single" onclick="setFabricAfterReferenceType('single')" style="padding:8px 20px; background:#e0e0e0; color:#333; border:none; border-radius:6px; cursor:pointer; font-weight:600; font-size:14px;">
          Single
        </button>
        <button type="button" id="fabric_after_ref_type_bundle" onclick="setFabricAfterReferenceType('bundle')" style="padding:8px 20px; background:#e0e0e0; color:#333; border:none; border-radius:6px; cursor:pointer; font-weight:600; font-size:14px;">
          Bundle
        </button>
      </div>
    </div>
    
    <!-- Single Reference Selection (shown when Single is selected) -->
    <div class="form-group" id="fabric_after_single_reference_group" style="display:none;">
      <label>Reference:</label>
      <select id="sample_id" name="sample_id" onchange="handleFabricAfterReferenceSelection(this.value)">
        <option value="">-- Select Reference --</option>
        <?php 
        // Show only single roll references (not part of bundles)
        foreach($references as $ref): 
          $refValue = is_array($ref) ? $ref['reference'] : $ref;
          $isIndividual = is_array($ref) && isset($ref['is_individual']) && $ref['is_individual'];
          if (!$isIndividual): ?>
          <option value="<?php echo htmlspecialchars($refValue); ?>" data-is-bundle="false">
            <?php echo htmlspecialchars($refValue); ?>
          </option>
        <?php 
          endif;
        endforeach; ?>
      </select>
    </div>
    
    <!-- Bundle Reference Selection (shown when Bundle is selected) -->
    <div id="fabric_after_bundle_reference_group" style="display:none; margin-bottom:15px; padding:10px; background:#f8f9fa; border:1px solid #ddd; border-radius:6px;">
      <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
        <label style="font-weight:600; margin:0;">From Reference:</label>
        <select id="fabric_after_from_reference" name="from_reference" style="min-width:250px; padding:5px; border:1px solid #ccc; border-radius:4px;" onchange="updateFabricAfterReferenceRange(true);">
          <option value="">-- Select From Reference --</option>
          <?php 
          // Show only bundle references
          foreach($bundleReferences as $bundle): ?>
            <option value="<?php echo htmlspecialchars($bundle['reference']); ?>" data-is-bundle="true" data-base-ref="<?php echo htmlspecialchars($bundle['base_reference']); ?>" data-roll-count="<?php echo $bundle['roll_count']; ?>">
              <?php echo htmlspecialchars($bundle['reference']); ?> (Bundle - <?php echo $bundle['roll_count']; ?> rolls)
            </option>
          <?php endforeach; ?>
        </select>
        <label style="font-weight:600; margin:0;">To Reference:</label>
        <select id="fabric_after_to_reference" name="to_reference" style="min-width:250px; padding:5px; border:1px solid #ccc; border-radius:4px;" onchange="updateFabricAfterReferenceRange(false);">
          <option value="">-- Select To Reference --</option>
        </select>
      </div>
      <div style="display:flex; gap:10px;">
        <button type="button" onclick="applyFabricAfterBulkReferenceSelection()" style="padding:6px 12px; background:#3498db; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:600;">
          Apply
        </button>
        <button type="button" onclick="clearFabricAfterBulkReferenceSelection()" style="padding:6px 12px; background:#6c757d; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:600;">
          Clear
        </button>
      </div>
      <!-- Hidden input to store the selected bundle reference range -->
      <input type="hidden" id="fabric_after_bundle_reference_range" name="bundle_reference_range" value="">
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Received From:</label>
        <input type="text" name="received_from" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Sample Received Date:</label>
        <input type="datetime-local" name="sample_received_date" value="<?php echo date('Y-m-d\TH:i'); ?>" required>
      </div>
      <div class="form-group">
        <label>Sample Tested Date:</label>
        <input type="date" name="sample_tested_date" value="<?php echo date('Y-m-d'); ?>" required>
      </div>
    </div>

    <!-- Test Performed By -->
    <?php if (!$can_approve): ?>
    <div class="form-group">
      <label>Test Performed By:</label>
      <input type="text" name="test_performed_by" value="<?php echo htmlspecialchars($reporter_full_name); ?>" readonly class="readonly">
    </div>
    <?php else: ?>
    <input type="hidden" name="test_performed_by" value="<?php echo htmlspecialchars($reporter_full_name); ?>">
    <?php endif; ?>

    <!-- Test Results Table -->
    <h3 style="text-align:center; margin:30px 0 20px 0;">Test Results</h3>
    <table class="test-table">
      <thead>
        <tr>
          <th>SL No</th>
          <th>Parameter</th>
          <th>Test Standard</th>
          <th>Unit</th>
          <th>Test Result</th>
          <th>Remarks</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($test_parameters as $sl_no => $param): ?>
        <tr>
          <td><?php echo $sl_no; ?></td>
          <td><?php echo htmlspecialchars($param['param']); ?></td>
          <td>
            <select name="test_results[<?php echo $sl_no; ?>][standard]" required>
              <option value="">Select Standard</option>
              <?php foreach ($param['standards'] as $standard): ?>
              <option value="<?php echo htmlspecialchars($standard); ?>"><?php echo htmlspecialchars($standard); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <select name="test_results[<?php echo $sl_no; ?>][unit]" required>
              <option value="">Select Unit</option>
              <?php foreach ($unit_options as $unit): ?>
              <option value="<?php echo htmlspecialchars($unit); ?>" <?php echo ($unit === $param['unit']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($unit); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="number" step="0.01" name="test_results[<?php echo $sl_no; ?>][result]"></td>
          <td><input type="text" name="test_results[<?php echo $sl_no; ?>][remarks]"></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Note Section -->
    <div class="form-group">
      <label>Note:</label>
      <textarea name="note" rows="3"></textarea>
    </div>

    <!-- Approved By (Only visible to Admin/AGM Ops) -->
    <?php if ($can_approve): ?>
    <div class="form-group">
      <label>Approved By:</label>
      <input type="text" name="approved_by" value="<?php echo htmlspecialchars($reporter_full_name); ?>" readonly class="readonly">
    </div>
    <?php endif; ?>

    <div class="actions">
      <button type="submit" name="submit_report" class="submit-btn"> Submit</button>
      <button type="button" class="clear-btn" onclick="clearForm()"> Clear</button>
    </div>
  </form>
</div>

<script>
// Initialize time display
document.addEventListener('DOMContentLoaded', function() {
    updateTimeAndShift();
    updateSampleId();
    // Don't auto-select any reference type - let user choose
});

function updateTimeAndShift() {
    const now = new Date();
    const utc = now.getTime() + now.getTimezoneOffset()*60000;
    const dhaka = new Date(utc + 6*3600000);
    document.getElementById('dateTimeDisplay').innerHTML = 'Date & Time: ' + dhaka.toDateString() + ' ' + dhaka.toLocaleTimeString();
    const yyyy = dhaka.getFullYear();
    const mm = String(dhaka.getMonth()+1).padStart(2,'0');
    const dd = String(dhaka.getDate()).padStart(2,'0');
    const hh = String(dhaka.getHours()).padStart(2,'0');
    const min = String(dhaka.getMinutes()).padStart(2,'0');
    const ss = String(dhaka.getSeconds()).padStart(2,'0');
    document.getElementById('dateTime').value = `${yyyy}-${mm}-${dd} ${hh}:${min}:${ss}`;
    const h = dhaka.getHours();
    const shift = (h >= 8 && h <= 19) ? 'Day' : 'Night';
    document.getElementById('shiftBanner').innerText = 'Shift: ' + shift;
    document.getElementById('shift').value = shift;
}
setInterval(updateTimeAndShift, 1000);

// Reference (Sample ID) is now selected from dropdown - no auto-generation needed
function updateSampleId() {
    // Function kept for compatibility but no longer generates sample ID
}

// Set reference type (Single or Bundle)
function setFabricAfterReferenceType(type) {
    const singleBtn = document.getElementById('fabric_after_ref_type_single');
    const bundleBtn = document.getElementById('fabric_after_ref_type_bundle');
    const singleGroup = document.getElementById('fabric_after_single_reference_group');
    const bundleGroup = document.getElementById('fabric_after_bundle_reference_group');
    const sampleId = document.getElementById('sample_id');
    const fromRef = document.getElementById('fabric_after_from_reference');
    const toRef = document.getElementById('fabric_after_to_reference');
    
    if (type === 'single') {
        // Single mode
        if (singleBtn) {
            singleBtn.style.background = '#3498db';
            singleBtn.style.color = 'white';
        }
        if (bundleBtn) {
            bundleBtn.style.background = '#e0e0e0';
            bundleBtn.style.color = '#333';
        }
        if (singleGroup) singleGroup.style.display = 'block';
        if (bundleGroup) bundleGroup.style.display = 'none';
        if (sampleId) {
            sampleId.setAttribute('required', 'required');
            sampleId.value = '';
        }
        if (fromRef) {
            fromRef.removeAttribute('required');
            fromRef.value = '';
        }
        if (toRef) {
            toRef.removeAttribute('required');
            toRef.value = '';
        }
        // Clear bundle range
        const bundleRangeInput = document.getElementById('fabric_after_bundle_reference_range');
        if (bundleRangeInput) bundleRangeInput.value = '';
    } else {
        // Bundle mode
        if (singleBtn) {
            singleBtn.style.background = '#e0e0e0';
            singleBtn.style.color = '#333';
        }
        if (bundleBtn) {
            bundleBtn.style.background = '#3498db';
            bundleBtn.style.color = 'white';
        }
        if (singleGroup) singleGroup.style.display = 'none';
        if (bundleGroup) bundleGroup.style.display = 'block';
        if (sampleId) {
            sampleId.removeAttribute('required');
            sampleId.value = '';
        }
        if (fromRef) fromRef.setAttribute('required', 'required');
        if (toRef) toRef.setAttribute('required', 'required');
        
        // Populate To reference dropdown with bundle references
        populateFabricAfterToReferenceDropdown();
    }
}

// Populate To reference dropdown with bundle references
function populateFabricAfterToReferenceDropdown() {
    const fromRef = document.getElementById('fabric_after_from_reference');
    const toRef = document.getElementById('fabric_after_to_reference');
    
    if (!fromRef || !toRef) return;
    
    // Copy all options from From dropdown to To dropdown
    toRef.innerHTML = '<option value="">-- Select To Reference --</option>';
    Array.from(fromRef.options).forEach((option, index) => {
        if (index > 0 && option.value) { // Skip first option (placeholder)
            const newOption = document.createElement('option');
            newOption.value = option.value;
            newOption.textContent = option.textContent;
            newOption.setAttribute('data-is-bundle', option.getAttribute('data-is-bundle') || 'false');
            newOption.setAttribute('data-base-ref', option.getAttribute('data-base-ref') || '');
            newOption.setAttribute('data-roll-count', option.getAttribute('data-roll-count') || '1');
            toRef.appendChild(newOption);
        }
    });
    
    // If From reference is already selected, update the range
    if (fromRef.value) {
        updateFabricAfterReferenceRange(true);
    }
}

// Update To Reference dropdown based on From Reference selection
function updateFabricAfterReferenceRange(autoSelect = true) {
    const fromRef = document.getElementById('fabric_after_from_reference');
    const toRef = document.getElementById('fabric_after_to_reference');
    
    if (!fromRef || !toRef) return;
    
    const fromValue = fromRef.value;
    if (!fromValue) {
        // If From is cleared, reset To dropdown to show all options
        Array.from(toRef.options).forEach(option => {
            option.style.display = '';
        });
        toRef.value = '';
        return;
    }
    
    // Get the selected From reference option
    const fromOption = fromRef.options[fromRef.selectedIndex];
    const isBundle = fromOption?.getAttribute('data-is-bundle') === 'true';
    let baseRef = fromOption?.getAttribute('data-base-ref') || '';
    let rollCount = parseInt(fromOption?.getAttribute('data-roll-count')) || 1;
    
    // Extract base reference from the selected value if not provided
    if (!baseRef) {
        const rollMatch = fromValue.match(/^(.+?)-(\d+)$/);
        if (rollMatch) {
            baseRef = rollMatch[1];
        } else {
            baseRef = fromValue;
        }
    }
    
    // Find all rolls in the bundle to determine the last roll
    let maxRollCount = rollCount;
    let lastRollRef = null;
    
    if (baseRef) {
        // Find the maximum roll count for this base reference across all options
        Array.from(fromRef.options).forEach(option => {
            if (option.value && option.value !== '') {
                const optionBaseRef = option.getAttribute('data-base-ref') || option.value.replace(/-\d+$/, '');
                const optionIsBundle = option.getAttribute('data-is-bundle') === 'true';
                if (optionIsBundle && optionBaseRef === baseRef) {
                    const optionRollCount = parseInt(option.getAttribute('data-roll-count')) || 1;
                    if (optionRollCount > maxRollCount) {
                        maxRollCount = optionRollCount;
                    }
                }
            }
        });
        
        // The last roll reference is the one with the highest roll count for this base reference
        // Format: baseRef-maxRollCount (e.g., "REF-4" if maxRollCount is 4)
        lastRollRef = baseRef + '-' + maxRollCount;
    }
    
    // Find the index of the selected From reference in the To dropdown
    let fromIndex = -1;
    Array.from(toRef.options).forEach((option, index) => {
        if (option.value === fromValue) {
            fromIndex = index;
        }
    });
    
    // Auto-select the last roll of the bundle in To dropdown
    if (baseRef && maxRollCount > 0 && autoSelect) {
        let found = false;
        
        // First, try to find the last roll reference in To dropdown
        Array.from(toRef.options).forEach(option => {
            if (option.value === lastRollRef) {
                toRef.value = lastRollRef;
                found = true;
            }
        });
        
        // If not found, try to find any option with the same base reference and highest roll count
        if (!found && lastRollRef) {
            let bestOption = null;
            let bestRollCount = 0;
            
            Array.from(toRef.options).forEach(option => {
                if (option.value && option.value !== '') {
                    const optionBaseRef = option.getAttribute('data-base-ref') || option.value.replace(/-\d+$/, '');
                    const optionRollCount = parseInt(option.getAttribute('data-roll-count')) || 1;
                    
                    if (optionBaseRef === baseRef && optionRollCount >= bestRollCount) {
                        bestRollCount = optionRollCount;
                        bestOption = option;
                    }
                }
            });
            
            if (bestOption) {
                toRef.value = bestOption.value;
                found = true;
            }
        }
    }
    
    // Show only references from the target From reference onwards
    Array.from(toRef.options).forEach((option, index) => {
        if (index === 0) {
            // Keep the placeholder
            option.style.display = '';
        } else if (index >= fromIndex) {
            // Show this option and onwards
            option.style.display = '';
        } else {
            // Hide options before the target From reference
            option.style.display = 'none';
        }
    });
}

// Apply bulk reference selection
function applyFabricAfterBulkReferenceSelection() {
    const fromRef = document.getElementById('fabric_after_from_reference').value;
    const toRef = document.getElementById('fabric_after_to_reference').value;
    
    if (!fromRef || !toRef) {
        alert('Please select both From and To references');
        return;
    }
    
    // Store the range in hidden input
    const bundleRangeInput = document.getElementById('fabric_after_bundle_reference_range');
    if (bundleRangeInput) {
        bundleRangeInput.value = fromRef + '|' + toRef;
    }
    
    // Update the main reference dropdown to show the range (for display purposes)
    const sampleId = document.getElementById('sample_id');
    if (sampleId) {
        // Find or create an option for the range
        let rangeOption = Array.from(sampleId.options).find(opt => opt.value === fromRef + '|' + toRef);
        if (!rangeOption) {
            rangeOption = document.createElement('option');
            rangeOption.value = fromRef + '|' + toRef;
            rangeOption.textContent = fromRef + ' to ' + toRef;
            sampleId.appendChild(rangeOption);
        }
        sampleId.value = rangeOption.value;
    }
}

// Clear bulk reference selection
function clearFabricAfterBulkReferenceSelection() {
    const fromRef = document.getElementById('fabric_after_from_reference');
    const toRef = document.getElementById('fabric_after_to_reference');
    const bundleRangeInput = document.getElementById('fabric_after_bundle_reference_range');
    
    if (fromRef) fromRef.value = '';
    if (toRef) toRef.value = '';
    if (bundleRangeInput) bundleRangeInput.value = '';
    
    // Reset To dropdown to show all options
    if (toRef) {
        Array.from(toRef.options).forEach(option => {
            option.style.display = '';
        });
    }
}

// Handle reference selection (for single mode)
function handleFabricAfterReferenceSelection(selectedValue) {
    // No additional action needed for single reference selection
}

function clearForm() {
    if (confirm('Are you sure you want to clear all data?')) {
        document.querySelector('form').reset();
        document.querySelector('input[name="report_number"]').value = '<?php echo htmlspecialchars($generated_report_number); ?>';
        document.querySelector('input[name="test_performed_by"]').value = '<?php echo htmlspecialchars($reporter_full_name); ?>';
        
        // Reset reference type to Single
        setFabricAfterReferenceType('single');
        clearFabricAfterBulkReferenceSelection();
        
        // Reset sample_id dropdown
        const sampleId = document.getElementById('sample_id');
        if (sampleId) {
            sampleId.selectedIndex = 0;
        }
    }
}

// Auto-reload page after approve/reject
function confirmApproval(form) {
    form.submit();
    setTimeout(function() {
        window.location.reload();
    }, 500);
    return true;
}

function confirmRejection(form) {
    const comment = prompt('❌ Please provide a reason for rejecting this report:\n\n(This comment will be shown to the tester)');
    
    if (comment === null) {
        return false;
    }
    
    if (comment.trim() === '') {
        alert('❌ Comment is required when rejecting a report!\n\nPlease provide a reason for rejection.');
        return confirmRejection(form);
    }
    
    const commentField = form.querySelector('input[name="wf_comment"]');
    if (commentField) {
        commentField.value = comment.trim();
    }
    
    form.submit();
    setTimeout(function() {
        window.location.reload();
    }, 500);
    return true;
}

// Fabric After Production rejection modal functions
function openFabricAfterRejectModal(reportNumber) {
    document.getElementById('fabricAfterRejectReportNumber').value = reportNumber;
    document.getElementById('fabricAfterRejectionModal').style.display = 'block';
}

function closeFabricAfterRejectModal() {
    document.getElementById('fabricAfterRejectionModal').style.display = 'none';
    document.getElementById('fabricAfterAdminRejectForm').reset();
}

function submitFabricAfterAdminRejection() {
    const checkboxes = document.querySelectorAll('input[name="fabric_after_rejection_reasons[]"]');
    const checked = Array.from(checkboxes).filter(cb => cb.checked);
    
    if (checked.length === 0) {
        alert('❌ Please select at least one reason for rejection!');
        return false;
    }
    
    if (confirm('Are you sure you want to reject this report?')) {
        const form = document.getElementById('fabricAfterAdminRejectForm');
        form.onsubmit = null;
        form.submit();
    }
}
</script>

<!-- Rejection Modal for Admin (Fabric After Production) -->
<div id="fabricAfterRejectionModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow-y:auto;">
  <div style="max-width:600px; margin:50px auto; background:#fff; border-radius:8px; padding:25px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
    <h3 style="margin-top:0; color:#dc3545; border-bottom:2px solid #dc3545; padding-bottom:10px;">
      ❌ Reject Fabric After Production Test
    </h3>
    
    <form id="fabricAfterAdminRejectForm" method="POST" action="" onsubmit="return false;">
      <input type="hidden" id="fabricAfterRejectReportNumber" name="wf_report_number" value="">
      <input type="hidden" name="wf_action" value="rejected">
      
      <label style="font-weight:600; display:block; margin-bottom:10px;">Reason for Rejection (Select at least one):</label>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="fabric_after_rejection_reasons[]" value="Incorrect Roll Identification" style="margin-right:8px;">
          Incorrect Roll Identification
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="fabric_after_rejection_reasons[]" value="Incorrect Fiber Specification Entry" style="margin-right:8px;">
          Incorrect Fiber Specification Entry
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="fabric_after_rejection_reasons[]" value="Excessive Sampling" style="margin-right:8px;">
          Excessive Sampling
        </label>
      </div>
      
      <label style="font-weight:bold; display:block; margin:15px 0 8px 0;">
        Additional Comments (Optional):
      </label>
      <textarea name="wf_comment" id="fabricAfterAdminRejectComment" rows="4" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-family:inherit;" placeholder="Provide additional details..."></textarea>
      
      <div style="margin-top:20px; text-align:right;">
        <button type="button" onclick="closeFabricAfterRejectModal()" style="padding:10px 20px; margin-right:10px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer;">Cancel</button>
        <button type="button" onclick="submitFabricAfterAdminRejection()" style="padding:10px 20px; background:#dc3545; color:#fff; border:none; border-radius:6px; cursor:pointer;">Submit Rejection</button>
      </div>
    </form>
  </div>
</div>

</body>
</html>
