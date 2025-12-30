<?php
session_start();
require_once 'security_config.php';

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

// Check user role for approval permissions
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$can_approve = in_array($user_role, ['admin', 'agm ops', 'agm operations'], true);
$roleLower = $user_role;

$message = '';
$error = '';

/**
 * Fabric Pre-Production Test Handler
 */
class FabricPreProductionTestHandler {
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
        $sql = "CREATE TABLE IF NOT EXISTS fabric_pre_production_counters (
            date_key VARCHAR(8) PRIMARY KEY,
            counter INT NOT NULL DEFAULT 0,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $this->conn->query($sql);
    }
    
    public function createMainTable() {
        $sql = "CREATE TABLE IF NOT EXISTS fabric_pre_production_tests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_number VARCHAR(50) UNIQUE,
            sample_details VARCHAR(255),
            sample_collected_from VARCHAR(255),
            batch_information VARCHAR(100),
            gsm INT,
            line_no INT,
            roll_number VARCHAR(100),
            product_reference VARCHAR(255),
            customer_reference VARCHAR(255),
            sample_received_date DATETIME,
            sample_production_date DATE,
            test_period_from DATE,
            test_period_to DATE,
            sample_received_from VARCHAR(255),
            lighthouse_reference VARCHAR(255),
            test_performed_by VARCHAR(100),
            temperature DECIMAL(5,2),
            rh_percentage DECIMAL(5,2),
            others_information TEXT,
            checked_by VARCHAR(100) NULL,
            checked_at DATETIME NULL,
            checker_remarks TEXT NULL,
            approved_by VARCHAR(100) NULL,
            qc_entry_id INT NULL,
            test_data JSON,
            reporter_id INT NOT NULL,
            reporter_name VARCHAR(255) NOT NULL,
            status ENUM('pending_checker','pending_approval','approved','rejected_by_checker','rejected_by_approver') DEFAULT 'pending_checker',
            approved_at DATETIME NULL,
            remarks TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (qc_entry_id) REFERENCES qc_entries(id)
        )";
        $this->conn->query($sql);
        
        // Add missing columns if they don't exist
        $columns_to_add = [
            'checked_by' => "ALTER TABLE fabric_pre_production_tests ADD COLUMN checked_by VARCHAR(100) NULL AFTER others_information",
            'checked_at' => "ALTER TABLE fabric_pre_production_tests ADD COLUMN checked_at DATETIME NULL AFTER checked_by",
            'checker_remarks' => "ALTER TABLE fabric_pre_production_tests ADD COLUMN checker_remarks TEXT NULL AFTER checked_at",
            'approved_by' => "ALTER TABLE fabric_pre_production_tests ADD COLUMN approved_by VARCHAR(100) NULL AFTER checker_remarks",
            'reporter_id' => "ALTER TABLE fabric_pre_production_tests ADD COLUMN reporter_id INT NOT NULL AFTER test_data",
            'reporter_name' => "ALTER TABLE fabric_pre_production_tests ADD COLUMN reporter_name VARCHAR(255) NOT NULL AFTER reporter_id",
            'status' => "ALTER TABLE fabric_pre_production_tests ADD COLUMN status ENUM('pending_checker','pending_approval','approved','rejected_by_checker','rejected_by_approver') DEFAULT 'pending_checker' AFTER reporter_name",
            'approved_at' => "ALTER TABLE fabric_pre_production_tests ADD COLUMN approved_at DATETIME NULL AFTER status",
            'remarks' => "ALTER TABLE fabric_pre_production_tests ADD COLUMN remarks TEXT NULL AFTER approved_at"
        ];
        
        foreach ($columns_to_add as $col => $sql) {
            $check = $this->conn->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
                                         WHERE TABLE_SCHEMA = DATABASE() 
                                         AND TABLE_NAME = 'fabric_pre_production_tests' 
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
        $pattern = 'FPPT-' . $dateKey . '-%';
        $countStmt = $this->conn->prepare("SELECT COUNT(*) as report_count FROM fabric_pre_production_tests WHERE report_number LIKE ?");
        $countStmt->bind_param("s", $pattern);
        $countStmt->execute();
        $result = $countStmt->get_result();
        
        $nextCounter = 1;
        if ($result && $row = $result->fetch_assoc()) {
            $nextCounter = (int)$row['report_count'] + 1;
        }
        $countStmt->close();
        
        return sprintf("FPPT-%s-%05d", $dateKey, $nextCounter);
    }
    
    public function generateAndIncrementReportNumber() {
        $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $hour = (int)$now->format('H');
        
        if ($hour < 8) {
            $now->modify('-1 day');
        }
        
        $dateKey = $now->format('Ymd');
        
        // Count actual reports for this day key (real-time)
        $pattern = 'FPPT-' . $dateKey . '-%';
        $countStmt = $this->conn->prepare("SELECT COUNT(*) as report_count FROM fabric_pre_production_tests WHERE report_number LIKE ?");
        $countStmt->bind_param("s", $pattern);
        $countStmt->execute();
        $result = $countStmt->get_result();
        
        $counter = 1;
        if ($result && $row = $result->fetch_assoc()) {
            $counter = (int)$row['report_count'] + 1;
        }
        $countStmt->close();
        
        return sprintf("FPPT-%s-%05d", $dateKey, $counter);
    }
    
    public function getPendingReportsForChecker($limit = 20) {
        $stmt = $this->conn->prepare(
            "SELECT id, report_number, sample_details, customer_reference, test_performed_by AS tested_by, status, updated_at
             FROM fabric_pre_production_tests WHERE status = 'pending_checker' ORDER BY updated_at DESC LIMIT ?"
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
    
    public function getPendingReportsForApprover($limit = 20) {
        $stmt = $this->conn->prepare(
            "SELECT id, report_number, sample_details, customer_reference, test_performed_by AS tested_by, checked_by, status, updated_at
             FROM fabric_pre_production_tests WHERE status = 'pending_approval' ORDER BY updated_at DESC LIMIT ?"
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
    
    public function checkerApproveOrReject($report_number, $action, $comment = '') {
        try {
            $status = ($action === 'approved') ? 'pending_approval' : 'rejected_by_checker';
            $checked_by = $_SESSION['full_name'] ?? $_SESSION['username'];
            $checked_at = date('Y-m-d H:i:s');
            
            $stmt = $this->conn->prepare(
                "UPDATE fabric_pre_production_tests 
                 SET status = ?, checked_by = ?, checked_at = ?, checker_remarks = ?
                 WHERE report_number = ?"
            );
            
            $stmt->bind_param("sssss", $status, $checked_by, $checked_at, $comment, $report_number);
            
            if ($stmt->execute()) {
                $stmt->close();
                return [
                    'success' => true,
                    'message' => "Report $report_number has been " . ($action === 'approved' ? 'forwarded to AGM/Admin for approval' : 'rejected') . " successfully!"
                ];
            } else {
                throw new Exception("Failed to update report: " . $stmt->error);
            }
            
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    public function approveOrReject($report_number, $action, $comment = '') {
        try {
            $status = ($action === 'approved') ? 'approved' : 'rejected_by_approver';
            $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
            $approved_at = date('Y-m-d H:i:s');
            
            $stmt = $this->conn->prepare(
                "UPDATE fabric_pre_production_tests 
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
            "SELECT id, report_number, sample_details, status, remarks, checker_remarks, created_at, approved_by, checked_by
             FROM fabric_pre_production_tests 
             WHERE reporter_id = ? AND (status = 'rejected_by_checker' OR status = 'rejected_by_approver')
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
    
    public function isChecker() {
        $role = strtolower(trim($_SESSION['role'] ?? ''));
        return in_array($role, ['checker'], true);
    }
    
    public function canApproveReports() {
        $role = strtolower(trim($_SESSION['role'] ?? ''));
        return in_array($role, ['admin', 'agm ops', 'agm operations'], true);
    }
    
    // Helper functions for statistics
    private function to_float_array($arr) {
        if (!isset($arr)) return [];
        if (!is_array($arr)) $arr = [$arr];
        $out = [];
        foreach ($arr as $v) {
            if ($v === '' || $v === null) continue;
            $v = str_replace(',', '.', trim($v));
            if ($v === '') continue;
            if (!is_numeric($v)) continue;
            $out[] = (float)$v;
        }
        return $out;
    }

    private function population_sd(array $a) {
        $n = count($a);
        if ($n === 0) return null;
        if ($n === 1) return 0.0;
        $mean = array_sum($a) / $n;
        $sumSq = 0.0;
        foreach ($a as $x) $sumSq += pow($x - $mean, 2);
        return sqrt($sumSq / $n);
    }

    private function stats(array $a) {
        if (count($a) === 0) return ['avg'=>null,'sd'=>null,'cv'=>null,'max'=>null,'min'=>null,'n'=>0];
        $n = count($a);
        $avg = array_sum($a)/$n;
        $sd = $this->population_sd($a);
        $cv = ($avg != 0 && $sd !== null) ? ($sd / $avg * 100) : 0;
        return ['avg'=>$avg,'sd'=>$sd,'cv'=>$cv,'max'=>max($a),'min'=>min($a),'n'=>$n];
    }

    public function saveReport($data) {
        try {
            $this->conn->begin_transaction();
            
            $this->createMainTable();
            
            $report_number = $this->generateAndIncrementReportNumber();
            
            // Process strip tensile test data to calculate correct statistics
            $test_data = $data['test_data'] ?? [];
            
            // Include summary data from the form
            if (isset($data['summary']) && is_array($data['summary'])) {
                $test_data['summary'] = $data['summary'];
            }
            
            // Extract strip tensile data
            $directions = isset($test_data['strip_direction']) ? (array)$test_data['strip_direction'] : [];
            $strengths  = isset($test_data['strip_strength']) ? (array)$test_data['strip_strength'] : [];
            $elongations = isset($test_data['strip_elongation']) ? (array)$test_data['strip_elongation'] : [];
            
            $md_strengths = $md_elong = $cd_strengths = $cd_elong = [];
            
            $rows = max(count($directions), count($strengths), count($elongations));
            for ($i=0; $i < $rows; $i++) {
                $dir = strtoupper(trim($directions[$i] ?? ''));
                $s = isset($strengths[$i]) ? str_replace(',', '.', trim($strengths[$i])) : '';
                $e = isset($elongations[$i]) ? str_replace(',', '.', trim($elongations[$i])) : '';

                if ($s !== '' && is_numeric($s)) {
                    $sVal = (float)$s;
                    if (strpos($dir, 'MD') === 0) $md_strengths[] = $sVal;
                    elseif (strpos($dir, 'CD') === 0) $cd_strengths[] = $sVal;
                }
                if ($e !== '' && is_numeric($e)) {
                    $eVal = (float)$e;
                    if (strpos($dir, 'MD') === 0) $md_elong[] = $eVal;
                    elseif (strpos($dir, 'CD') === 0) $cd_elong[] = $eVal;
                }
            }
            
            // Compute statistics
            $mdStrStats = $this->stats($md_strengths);
            $mdElStats  = $this->stats($md_elong);
            $cdStrStats = $this->stats($cd_strengths);
            $cdElStats  = $this->stats($cd_elong);
            
            // Store computed statistics in test_data for later retrieval
            $test_data['strip_stats'] = [
                'md_strength' => [
                    'avg' => $mdStrStats['avg'] !== null ? round($mdStrStats['avg'], 2) : null,
                    'sd'  => $mdStrStats['sd']  !== null ? round($mdStrStats['sd'], 2)  : null,
                    'cv'  => $mdStrStats['cv']  !== null ? round($mdStrStats['cv'], 2)  : null,
                    'max' => $mdStrStats['max'] !== null ? round($mdStrStats['max'], 2) : null,
                    'min' => $mdStrStats['min'] !== null ? round($mdStrStats['min'], 2) : null
                ],
                'md_elongation' => [
                    'avg' => $mdElStats['avg'] !== null ? round($mdElStats['avg'], 2) : null,
                    'sd'  => $mdElStats['sd']  !== null ? round($mdElStats['sd'], 2)  : null,
                    'cv'  => $mdElStats['cv']  !== null ? round($mdElStats['cv'], 2)  : null,
                    'max' => $mdElStats['max'] !== null ? round($mdElStats['max'], 2) : null,
                    'min' => $mdElStats['min'] !== null ? round($mdElStats['min'], 2) : null
                ],
                'cd_strength' => [
                    'avg' => $cdStrStats['avg'] !== null ? round($cdStrStats['avg'], 2) : null,
                    'sd'  => $cdStrStats['sd']  !== null ? round($cdStrStats['sd'], 2)  : null,
                    'cv'  => $cdStrStats['cv']  !== null ? round($cdStrStats['cv'], 2)  : null,
                    'max' => $cdStrStats['max'] !== null ? round($cdStrStats['max'], 2) : null,
                    'min' => $cdStrStats['min'] !== null ? round($cdStrStats['min'], 2) : null
                ],
                'cd_elongation' => [
                    'avg' => $cdElStats['avg'] !== null ? round($cdElStats['avg'], 2) : null,
                    'sd'  => $cdElStats['sd']  !== null ? round($cdElStats['sd'], 2)  : null,
                    'cv'  => $cdElStats['cv']  !== null ? round($cdElStats['cv'], 2)  : null,
                    'max' => $cdElStats['max'] !== null ? round($cdElStats['max'], 2) : null,
                    'min' => $cdElStats['min'] !== null ? round($cdElStats['min'], 2) : null
                ]
            ];
            
            // Debug: Log test_data before encoding
            error_log("=== SAVING REPORT ===");
            error_log("test_data array count: " . count($test_data));
            error_log("test_data keys: " . implode(', ', array_keys($test_data)));
            error_log("Has summary: " . (isset($test_data['summary']) ? 'YES' : 'NO'));
            if (isset($test_data['summary'])) {
                error_log("Summary keys: " . implode(', ', array_keys($test_data['summary'])));
            }
            
            $test_data_json = json_encode($test_data);
            error_log("test_data_json length: " . strlen($test_data_json));
            error_log("test_data_json preview: " . substr($test_data_json, 0, 500));
            
            $user_role = strtolower(trim($_SESSION['role'] ?? ''));
            $status = ($user_role === 'admin' || $user_role === 'agm ops' || $user_role === 'agm operations') ? 'approved' : 'pending';
            
            $approved_by_value = (isset($data['approved_by']) && !empty($data['approved_by'])) ? $data['approved_by'] : null;
            $qc_entry_id = isset($data['qc_entry_id']) ? (int)$data['qc_entry_id'] : null;
            
            $stmt = $this->conn->prepare("
                INSERT INTO fabric_pre_production_tests (
                    report_number, sample_details, sample_collected_from, batch_information, gsm, line_no, 
                    roll_number, product_reference, customer_reference, sample_received_date, 
                    sample_production_date, test_period_from, test_period_to, sample_received_from, 
                    lighthouse_reference, test_performed_by, temperature, rh_percentage, others_information, 
                    approved_by, qc_entry_id, test_data, reporter_id, reporter_name, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->bind_param(
                "ssssiissssssssssddssisiss",
                $report_number,              // 1: s - VARCHAR
                $data['sample_details'],     // 2: s - VARCHAR
                $data['sample_collected_from'], // 3: s - VARCHAR
                $data['batch_information'],  // 4: s - VARCHAR
                $data['gsm'],                // 5: i - INT
                $data['line_no'],            // 6: i - INT
                $data['roll_number'],        // 7: s - VARCHAR
                $data['product_reference'],  // 8: s - VARCHAR
                $data['customer_reference'], // 9: s - VARCHAR
                $data['sample_received_date'], // 10: s - DATETIME
                $data['sample_production_date'], // 11: s - DATE
                $data['test_period_from'],   // 12: s - DATE
                $data['test_period_to'],     // 13: s - DATE
                $data['sample_received_from'], // 14: s - VARCHAR
                $data['lighthouse_reference'], // 15: s - VARCHAR
                $data['test_performed_by'],  // 16: s - VARCHAR
                $data['temperature'],        // 17: d - DECIMAL(5,2)
                $data['rh_percentage'],      // 18: d - DECIMAL(5,2)
                $data['others_information'], // 19: s - TEXT
                $approved_by_value,          // 20: s - VARCHAR
                $qc_entry_id,                // 21: i - INT
                $test_data_json,             // 22: s - JSON (CRITICAL!)
                $this->reporter_id,          // 23: i - INT
                $this->reporter_full_name,   // 24: s - VARCHAR
                $status                      // 25: s - ENUM
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to save report: " . $stmt->error);
            }
            
            $report_id = $this->conn->insert_id;
            $stmt->close();
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => "Fabric Pre-Production Test Report saved successfully! Report Number: $report_number",
                'report_id' => $report_id,
                'report_number' => $report_number,
                'status' => $status
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }
}

// Initialize the handler
$fabricPreProdHandler = new FabricPreProductionTestHandler($conn, $reporter_id, $reporter_name, $reporter_full_name);

// Helper function to get readable status labels
function getStatusLabel($status) {
    $labels = [
        'pending_checker' => 'Pending Checker Review',
        'pending_approval' => 'Forwarded to AGM/Admin',
        'approved' => 'Approved',
        'rejected_by_checker' => 'Rejected by Checker',
        'rejected_by_approver' => 'Rejected by Admin'
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

// Ensure tables exist
$fabricPreProdHandler->createMainTable();
$fabricPreProdHandler->createCounterTable();

// Check user roles
$is_checker = $fabricPreProdHandler->isChecker();

// Fetch pending reports based on role
$pending_reports = [];
if ($is_checker) {
    $pending_reports = $fabricPreProdHandler->getPendingReportsForChecker(20);
} else if ($can_approve) {
    $pending_reports = $fabricPreProdHandler->getPendingReportsForApprover(20);
}

// Generate report number for display (without incrementing)
$generated_report_number = $fabricPreProdHandler->getNextReportNumber();

// Handle checker approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checker_action'], $_POST['checker_report_number'])) {
    try {
        $action = $_POST['checker_action'];
        $report_number = trim($_POST['checker_report_number']);
        $comment = trim($_POST['checker_comment'] ?? '');
        
        // Handle rejection reasons checkboxes
        if ($action === 'rejected' && isset($_POST['rejection_reasons']) && is_array($_POST['rejection_reasons'])) {
            $rejection_reasons = array_map('trim', $_POST['rejection_reasons']);
            $reasons_text = implode(', ', $rejection_reasons);
            // Prepend reasons to comment
            $comment = "Rejection Reasons: " . $reasons_text . ($comment ? "\n\nAdditional Comments: " . $comment : '');
        }
        
        if (!$is_checker) {
            throw new Exception("You don't have permission to check reports.");
        }
        
        $result = $fabricPreProdHandler->checkerApproveOrReject($report_number, $action, $comment);
        $message = $result['message'];
        
        // Refresh pending reports
        $pending_reports = $fabricPreProdHandler->getPendingReportsForChecker(20);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle final approval/rejection (Admin/AGM)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wf_action'], $_POST['wf_report_number'])) {
    try {
        $action = $_POST['wf_action'];
        $report_number = trim($_POST['wf_report_number']);
        $comment = trim($_POST['wf_comment'] ?? '');
        
        // Handle rejection reasons checkboxes for admin
        if ($action === 'rejected' && isset($_POST['admin_rejection_reasons']) && is_array($_POST['admin_rejection_reasons'])) {
            $rejection_reasons = array_map('trim', $_POST['admin_rejection_reasons']);
            $reasons_text = implode(', ', $rejection_reasons);
            // Prepend reasons to comment
            $comment = "Rejection Reasons: " . $reasons_text . ($comment ? "\n\nAdditional Comments: " . $comment : '');
        }
        
        if (!$fabricPreProdHandler->canApproveReports()) {
            throw new Exception("You don't have permission to approve reports.");
        }
        
        $result = $fabricPreProdHandler->approveOrReject($report_number, $action, $comment);
        $message = $result['message'];
        
        // Refresh pending reports
        $pending_reports = $fabricPreProdHandler->getPendingReportsForApprover(20);
        
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
        
        $result = $fabricPreProdHandler->saveReport($_POST);
        $message = $result['message'];
        
        // Debug: Log summary data
        $summary_count = isset($_POST['summary']) ? count($_POST['summary']) : 0;
        $test_data_count = isset($_POST['test_data']) ? count($_POST['test_data']) : 0;
        // Auto-approval message
        if ($can_approve) {
            $message = "Fabric Pre-Production Test saved and auto-approved! Report Number: " . $result['report_number'];
        } else {
            $message = "Fabric Pre-Production Test submitted successfully! Report Number: " . $result['report_number'] . " (Status: Pending Approval)";
        }
        
        // Regenerate report number for next submission
        $generated_report_number = $fabricPreProdHandler->getNextReportNumber();
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// OLD CODE - BACKUP (remove after testing)
if (false && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report_OLD'])) {
    try {
        $conn->begin_transaction();
        
        $report_number = trim($_POST['report_number']);
        $sample_details = trim($_POST['sample_details']);
        $sample_collected_from = trim($_POST['sample_collected_from']);
        $batch_information = trim($_POST['batch_information']);
        $gsm = (int)$_POST['gsm'];
        $line_no = (int)$_POST['line_no'];
        $roll_number = trim($_POST['roll_number']);
        $product_reference = trim($_POST['product_reference']);
        $customer_reference = trim($_POST['customer_reference']);
        $sample_received_date = trim($_POST['sample_received_date']);
        $sample_production_date = trim($_POST['sample_production_date']);
        $test_period_from = trim($_POST['test_period_from']);
        $test_period_to = trim($_POST['test_period_to']);
        $sample_received_from = trim($_POST['sample_received_from']);
        $lighthouse_reference = trim($_POST['lighthouse_reference']);
        $test_performed_by = trim($_POST['test_performed_by']);
        $temperature = trim($_POST['temperature']);
        $rh_percentage = trim($_POST['rh_percentage']);
        $others_information = trim($_POST['others_information']);
        $test_data = $_POST['test_data'] ?? [];
        $qc_entry_id = isset($_POST['qc_entry_id']) ? (int)$_POST['qc_entry_id'] : null;
        
        if (empty($report_number) || empty($sample_details) || empty($sample_collected_from) || empty($batch_information) || empty($gsm) || empty($line_no) || empty($roll_number) || empty($customer_reference) || empty($sample_received_date) || empty($sample_production_date) || empty($test_period_from) || empty($test_period_to) || empty($sample_received_from)) {
            throw new Exception("All mandatory fields are required.");
        }
        
        // Create fabric_pre_production_tests table if not exists
        $createTable = "CREATE TABLE IF NOT EXISTS fabric_pre_production_tests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_number VARCHAR(50) UNIQUE,
            sample_details VARCHAR(255),
            sample_collected_from VARCHAR(255),
            batch_information VARCHAR(100),
            gsm INT,
            line_no INT,
            roll_number VARCHAR(100),
            product_reference VARCHAR(255),
            customer_reference VARCHAR(255),
            sample_received_date DATETIME,
            sample_production_date DATE,
            test_period_from DATE,
            test_period_to DATE,
            sample_received_from VARCHAR(255),
            lighthouse_reference VARCHAR(255),
            test_performed_by VARCHAR(100),
            temperature DECIMAL(5,2),
            rh_percentage DECIMAL(5,2),
            others_information TEXT,
            qc_entry_id INT NULL,
            test_data JSON,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (qc_entry_id) REFERENCES qc_entries(id)
        )";
        $conn->query($createTable);
        
        // Insert test data
        $test_data_json = json_encode($test_data);
        $stmt = $conn->prepare("
            INSERT INTO fabric_pre_production_tests (
                report_number, sample_details, sample_collected_from, batch_information, gsm, line_no, 
                roll_number, product_reference, customer_reference, sample_received_date, 
                sample_production_date, test_period_from, test_period_to, sample_received_from, 
                lighthouse_reference, test_performed_by, temperature, rh_percentage, others_information, qc_entry_id, test_data
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->bind_param("sssssiisssssssssddsis", 
            $report_number, $sample_details, $sample_collected_from, $batch_information, $gsm, $line_no,
            $roll_number, $product_reference, $customer_reference, $sample_received_date,
            $sample_production_date, $test_period_from, $test_period_to, $sample_received_from,
            $lighthouse_reference, $test_performed_by, $temperature, $rh_percentage, $others_information, $qc_entry_id, $test_data_json
        );
        
        if ($stmt->execute()) {
            $conn->commit();
            $message = "Fabric Pre-Production Test Report saved successfully! Report Number: $report_number";
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
    $tableCheck = $conn->query("SHOW TABLES LIKE 'fabric_pre_production_tests'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        // Get next sequence number for this date
        $query = "SELECT MAX(CAST(SUBSTRING(report_number, -3) AS UNSIGNED)) as max_seq 
                  FROM fabric_pre_production_tests 
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
    
    return 'FPP-' . $dateStr . '-' . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
}

// Function to generate sample reference ID
function generateSampleReferenceId() {
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
    $tableCheck = $conn->query("SHOW TABLES LIKE 'fabric_pre_production_tests'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        // Get next sequence number for this date
        $query = "SELECT MAX(CAST(SUBSTRING(sample_reference_id, -3) AS UNSIGNED)) as max_seq 
                  FROM fabric_pre_production_tests 
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
    
    return 'SR-' . $dateStr . '-' . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fabric Pre-Production Test Report</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
  .container { max-width:1200px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);} 
  h1 { text-align:center; font-size:28px; margin-bottom:30px; color:#2c3e50; }
  .form-group { margin-bottom:15px; }
  label { font-weight:600; display:block; margin-bottom:5px; font-size:14px; }
  input[type="text"], input[type="number"], select, textarea { padding:8px; border:1px solid #ccc; border-radius:4px; width:calc(100% - 18px); font-size:14px; }
  .form-row { display:flex; gap:15px; margin-bottom:15px; }
  .form-col { flex:1; }
  .form-col .form-group { margin-bottom:12px; }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .readonly { background:#ecf0f1; }
  .test-section { border:1px solid #ddd; border-radius:8px; padding:20px; margin-bottom:20px; background:#f9f9f9; }
  .test-table { width:100%; border-collapse:collapse; margin-top:15px; font-size:12px; }
  .test-table th, .test-table td { border:1px solid #ddd; padding:6px; text-align:center; white-space:nowrap; }
  .test-table th { background:#3498db; color:#fff; font-weight:600; font-size:11px; }
  .test-table td:last-child { white-space:normal; } /* Allow wrapping in Actions column */
  .test-table input { width:100%; border:none; background:transparent; text-align:center; font-size:11px; }
  .test-table select { width:100%; border:none; background:transparent; text-align:center; font-size:11px; }
  .test-table .readonly { background:#f8f9fa; }
  .add-row-btn { background:#27ae60; color:#fff; border:none; padding:8px 16px; border-radius:4px; cursor:pointer; margin-top:10px; }
  .stats-row { background:#ecf0f1; font-weight:bold; }
  .table-container { overflow-x: auto; border: 1px solid #ddd; border-radius: 8px; max-width:100%; }
</style>
</head>
<body>
<div class="container">
  <h1> Fabric Pre-Production Test Report</h1>

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

  <?php if ($is_checker): ?>
  <!-- Checker Queue -->
    <?php if (!empty($pending_reports)): ?>
    <div style="margin-top:16px; padding:12px; border:1px solid #ddd; border-radius:8px; background:#fff;">
      <h3 style="margin:0 0 12px 0;">Pending for Checking</h3>
      <table class="test-table">
        <thead>
          <tr>
            <th>Report No</th>
            <th>Sample</th>
            <th>Customer Ref</th>
            <th>Tested By</th>
            <th>Submitted At</th>
            <th style="width:120px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending_reports as $pr): ?>
          <tr>
            <td><?php echo htmlspecialchars($pr['report_number']); ?></td>
            <td><?php echo htmlspecialchars($pr['sample_details']); ?></td>
            <td><?php echo htmlspecialchars($pr['customer_reference']); ?></td>
            <td><?php echo htmlspecialchars($pr['tested_by']); ?></td>
            <td><?php echo htmlspecialchars($pr['updated_at']); ?></td>
            <td>
              <a href="../admin/check_fabric_pre_prod_report.php?id=<?php echo $pr['id']; ?>" target="_blank" class="submit-btn" style="padding:6px 10px; text-decoration:none; display:inline-block; background:#3498db;">Check</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  <?php elseif ($can_approve): ?>
  <!-- Approver Queue -->
    <?php if (!empty($pending_reports)): ?>
    <div style="margin-top:16px; padding:12px; border:1px solid #ddd; border-radius:8px; background:#fff;">
      <h3 style="margin:0 0 12px 0;">Pending for Approval (Checked by Checker)</h3>
      <table class="test-table">
        <thead>
          <tr>
            <th>Report No</th>
            <th>Sample</th>
            <th>Customer Ref</th>
            <th>Tested By</th>
            <th>Checked By</th>
            <th>Submitted At</th>
            <th style="width:200px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending_reports as $pr): ?>
          <tr>
            <td><?php echo htmlspecialchars($pr['report_number']); ?></td>
            <td><?php echo htmlspecialchars($pr['sample_details']); ?></td>
            <td><?php echo htmlspecialchars($pr['customer_reference']); ?></td>
            <td><?php echo htmlspecialchars($pr['tested_by']); ?></td>
            <td><?php echo htmlspecialchars($pr['checked_by'] ?? '-'); ?></td>
            <td><?php echo htmlspecialchars($pr['updated_at']); ?></td>
            <td>
              <a href="../admin/view_fabric_pre_prod_report.php?id=<?php echo $pr['id']; ?>" target="_blank" class="submit-btn" style="padding:6px 10px; text-decoration:none; display:inline-block; background:#3498db; margin-right:4px;">View</a>
              <form method="POST" action="" style="display:inline; margin-right:4px;" onsubmit="return confirmApproval(this);">
                <input type="hidden" name="wf_report_number" value="<?php echo htmlspecialchars($pr['report_number']); ?>">
                <input type="hidden" name="wf_comment" value="Approved from queue">
                <button type="submit" name="wf_action" value="approved" class="submit-btn" style="padding:6px 10px;">Approve</button>
              </form>
              <button type="button" onclick="openRejectModal('<?php echo htmlspecialchars($pr['report_number']); ?>')" class="clear-btn" style="padding:6px 10px; border:none; cursor:pointer;">Reject</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($is_checker && empty($pending_reports)): ?>
  <!-- Message for checker when no reports are pending -->
  <div style="background:#e3f2fd; border:1px solid #2196f3; padding:20px; border-radius:8px; text-align:center; margin-top:20px;">
    <h3 style="color:#1976d2; margin-top:0;">✅ No Reports Pending for Checking</h3>
    <p style="color:#555;">All reports have been checked. New reports will appear here when testers submit them.</p>
  </div>
  <?php endif; ?>

  <?php if (!$is_checker && !$can_approve): ?>
  <!-- Rejected Reports for Tester to Review -->
  <?php $rejected = $fabricPreProdHandler->getRejectedReportsForUser($reporter_id); if (!empty($rejected)): ?>
  <div style="margin-top:16px; padding:12px; border:1px solid #f8d7da; border-radius:8px; background:#fff3cd;">
    <h3 style="margin:0 0 12px 0; color:#721c24;">❌ Rejected Reports - Action Required</h3>
    <p style="margin:0 0 12px 0; color:#856404;">The following reports were rejected. Please review the comments and make corrections.</p>
    <table class="test-table">
      <thead>
        <tr>
          <th>Report No</th>
          <th>Sample</th>
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
          <td><?php echo htmlspecialchars($rj['sample_details']); ?></td>
          <td><?php echo htmlspecialchars(($rj['status'] === 'rejected_by_checker' ? $rj['checked_by'] : $rj['approved_by']) ?? 'N/A'); ?></td>
          <td style="text-align:left; max-width:300px; color:#721c24; font-weight:600;">
            <?php 
            $comment = ($rj['status'] === 'rejected_by_checker' ? $rj['checker_remarks'] : $rj['remarks']) ?? 'No comments';
            echo htmlspecialchars($comment);
            ?>
          </td>
          <td><?php echo htmlspecialchars($rj['created_at']); ?></td>
          <td>
            <a href="edit_fabric_pre_prod.php?id=<?php echo $rj['id']; ?>" class="submit-btn" style="padding:6px 10px; text-decoration:none; display:inline-block; background:#f39c12; color:#fff;">Edit & Resubmit</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin:12px 0 0 0; color:#856404; font-style:italic;">💡 Note: Click "Edit & Resubmit" to pre-fill and modify the rejected report.</p>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php if (!$is_checker): ?>
  <!-- Form is only visible to testers and admins, not checkers -->
  <form method="POST" action="" autocomplete="off" id="fabricPreProdForm" onsubmit="return prepareFormSubmit()">

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

    <!-- Report Information -->
    <div class="form-group">
      <label>Report No.:</label>
      <input type="text" id="report_no" name="report_number" readonly class="readonly" value="<?php echo htmlspecialchars($generated_report_number); ?>">
    </div>

    <!-- Form Fields in Two Columns -->
    <div class="form-row">
      <div class="form-col">
        <!-- Column 1 -->
        <div class="form-group">
          <label>Sample Details:</label>
          <input type="text" name="sample_details" required>
        </div>
        
        <div class="form-group">
          <label>Sample Collected From:</label>
          <input type="text" name="sample_collected_from" required>
        </div>
        
        <div class="form-group">
          <label>Batch Information:</label>
          <input type="text" name="batch_information" required placeholder="e.g., GT9.H1" onchange="updateProductReference()">
        </div>
        
        <div class="form-group">
          <label>GSM:</label>
          <input type="number" name="gsm" required min="1" step="1" onchange="updateProductReference()">
        </div>
        
        <div class="form-group">
          <label>Line No:</label>
          <input type="number" name="line_no" required min="1" step="1" onchange="updateProductReference()">
        </div>
      </div>
      
      <div class="form-col">
        <!-- Column 2 -->
        <div class="form-group">
          <label>Roll Number:</label>
          <input type="text" name="roll_number" required onchange="updateProductReference()">
        </div>
        
        <div class="form-group">
          <label>Product Reference:</label>
          <input type="text" name="product_reference" readonly class="readonly">
        </div>
        
        <div class="form-group">
          <label>Customer Reference:</label>
          <input type="text" name="customer_reference" required>
        </div>
        
        <div class="form-group">
          <label>Sample Received Date:</label>
          <input type="datetime-local" name="sample_received_date" required value="<?php echo date('Y-m-d\TH:i'); ?>" onchange="validateProductionDate(); validateTestPeriod();">
        </div>
        
        <div class="form-group">
          <label>Sample Production Date:</label>
          <input type="datetime-local" name="sample_production_date" required onchange="validateProductionDate()">
        </div>
      </div>
    </div>

    <!-- Test Period Row -->
    <div class="form-group">
      <label>Test Period:</label>
      <div style="display: flex; gap: 10px; align-items: center;">
        <input type="date" name="test_period_from" required style="flex: 1;" onchange="validateTestPeriod()">
        <span style="font-weight: bold;">to</span>
        <input type="date" name="test_period_to" required style="flex: 1;" onchange="validateTestPeriod()">
      </div>
    </div>

    <!-- Additional Fields Row -->
    <div class="form-row">
      <div class="form-col">
        <div class="form-group">
          <label>Sample Received From:</label>
          <input type="text" name="sample_received_from" required>
        </div>
      </div>
      
      <div class="form-col">
        <div class="form-group">
          <label>Lighthouse Reference:</label>
          <input type="text" name="lighthouse_reference">
        </div>
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

    <!-- Temperature and RH -->
    <div class="form-row" style="display:flex; gap:12px; align-items:flex-end;">
      <div class="form-col" style="flex:1;">
        <div class="form-group" style="margin-bottom: 0;">
          <label>Temperature (C):</label>
          <input type="number" name="temperature" step="0.1" min="-50" max="100" placeholder="25.0">
        </div>
      </div>
      <div class="form-col" style="flex:1;">
        <div class="form-group" style="margin-bottom: 0;">
          <label>RH%:</label>
          <input type="number" name="rh_percentage" step="0.1" min="0" max="100" placeholder="65.0">
        </div>
      </div>
    </div>

    <!-- Others Information -->
    <div class="form-group">
      <label>Others Information:</label>
      <textarea name="others_information" rows="2"></textarea>
    </div>

    <!-- Test Raw Data Table -->
    <div class="test-section">
      <h3 style="text-align: center;"> Test Raw Data</h3>
      <div class="table-container">
        <table class="test-table" id="rawDataTable" style="min-width: 1400px;">
          <thead>
            <tr>
              <th colspan="4" style="background:#1976d2; color:#fff;">GSM Test - ASTM D5261</th>
              <th colspan="2" style="background:#7b1fa2; color:#fff;">Thickness Test - ASTM D5199</th>
              <th colspan="4" style="background:#388e3c; color:#fff;">Strip Tensile Test - ASTM D4595</th>
              <th colspan="2" style="background:#f57c00; color:#fff;">CBR Test - ASTM D6241</th>
              <th colspan="3" style="background:#c2185b; color:#fff;">Grab Tensile Test - ASTM D4632</th>
            </tr>
            <tr>
              <!-- GSM Test columns -->
              <th style="background:#1976d2; color:#fff; width: 80px;">Position</th>
              <th style="background:#1976d2; color:#fff; width: 80px;">Weight(gm)</th>
              <th style="background:#1976d2; color:#fff; width: 80px;">Calculated(GSM)</th>
              <th style="background:#1976d2; color:#fff; width: 80px;">Average(GSM)</th>
              <!-- Thickness Test columns -->
              <th style="background:#7b1fa2; color:#fff; width: 100px;">Under 2KPa(mm)</th>
              <th style="background:#7b1fa2; color:#fff; width: 80px;">Average(mm)</th>
              <!-- Strip Tensile Test columns -->
              <th style="background:#388e3c; color:#fff; width: 80px;">Test Direction</th>
              <th style="background:#388e3c; color:#fff; width: 80px;">Strength(kN/m)</th>
              <th style="background:#388e3c; color:#fff; width: 80px;">MD:CD Ratio</th>
              <th style="background:#388e3c; color:#fff; width: 80px;">Elongation(%)</th>
              <!-- CBR Test columns -->
              <th style="background:#f57c00; color:#fff; width: 100px;">Ultimate Force(N)</th>
              <th style="background:#f57c00; color:#fff; width: 100px;">Ultimate Displacement(mm)</th>
              <!-- Grab Tensile Test columns -->
              <th style="background:#c2185b; color:#fff; width: 80px;">Test Direction</th>
              <th style="background:#c2185b; color:#fff; width: 80px;">Force(N)</th>
              <th style="background:#c2185b; color:#fff; width: 80px;">Elongation(%)</th>
            </tr>
          </thead>
          <tbody id="rawDataBody">
            <!-- Rows will be added dynamically -->
          </tbody>
        </table>
      </div>
      <button type="button" class="add-row-btn" onclick="addFourRows()">+ Add 4 More Rows</button>
    </div>

    

    <!-- Summary of Test Result Table -->
    <div class="test-section">
      <h3 style="text-align: center;"> Summary of Test Result</h3>
      <div class="table-container">
        <table class="test-table" id="summaryTable" style="min-width: 1200px;">
          <thead>
            <tr>
              <th rowspan="2" style="background:#f8f9fa; color:#000; width: 100px;">Statistics</th>
              <th colspan="1" style="background:#1976d2; color:#fff;">Mass Per Unit Area(GSM Test)</th>
              <th colspan="1" style="background:#7b1fa2; color:#fff;">Thickness Test Under 2kPa(mm)</th>
              <th colspan="4" style="background:#388e3c; color:#fff;">Strip Tensile Strength Test</th>
              <th colspan="2" style="background:#f57c00; color:#fff;">CBR Test</th>
              <th colspan="4" style="background:#c2185b; color:#fff;">Grab Test</th>
            </tr>
            <tr>
              <!-- GSM Test -->
              <th style="background:#1976d2; color:#fff;"></th>
              <!-- Thickness Test -->
              <th style="background:#7b1fa2; color:#fff;"></th>
              <!-- Strip Tensile Test -->
              <th colspan="2" style="background:#388e3c; color:#fff;">MD</th>
              <th colspan="2" style="background:#388e3c; color:#fff;">CD</th>
              <!-- CBR Test -->
              <th style="background:#f57c00; color:#fff;">Ultimate Force(N)</th>
              <th style="background:#f57c00; color:#fff;">Ultimate Displacement(mm)</th>
              <!-- Grab Test -->
              <th colspan="2" style="background:#c2185b; color:#fff;">MD</th>
              <th colspan="2" style="background:#c2185b; color:#fff;">CD</th>
            </tr>
            <tr>
              <!-- Third header row: only show sub-headers where needed (Strip, Grab). Others left blank -->
              <th style="background:#f8f9fa; color:#000;"></th>
              <th style="background:#1976d2; color:#fff;"></th>
              <th style="background:#7b1fa2; color:#fff;"></th>
              <th style="background:#388e3c; color:#fff;">Strength(kN/m)</th>
              <th style="background:#388e3c; color:#fff;">Elongation(%)</th>
              <th style="background:#388e3c; color:#fff;">Strength(kN/m)</th>
              <th style="background:#388e3c; color:#fff;">Elongation(%)</th>
              <th style="background:#f57c00; color:#fff;"></th>
              <th style="background:#f57c00; color:#fff;"></th>
              <th style="background:#c2185b; color:#fff;">Force(N)</th>
              <th style="background:#c2185b; color:#fff;">Elongation(%)</th>
              <th style="background:#c2185b; color:#fff;">Force(N)</th>
              <th style="background:#c2185b; color:#fff;">Elongation(%)</th>
            </tr>
          </thead>
          <tbody id="summaryBody">
            <tr>
              <td style="font-weight: bold;">Average:</td>
              <td><input type="number" step="0.01" name="summary[gsm_avg]" readonly class="readonly"></td>
              <td><input type="number" step="0.001" name="summary[thickness_avg]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_md_strength_avg]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_md_elongation_avg]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_cd_strength_avg]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_cd_elongation_avg]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[cbr_force_avg]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[cbr_displacement_avg]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_md_force_avg]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_md_elongation_avg]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_cd_force_avg]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_cd_elongation_avg]" readonly class="readonly"></td>
            </tr>
            <tr>
              <td style="font-weight: bold;">SD:</td>
              <td><input type="number" step="0.01" name="summary[gsm_sd]" readonly class="readonly"></td>
              <td><input type="number" step="0.001" name="summary[thickness_sd]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_md_strength_sd]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_md_elongation_sd]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_cd_strength_sd]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_cd_elongation_sd]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[cbr_force_sd]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[cbr_displacement_sd]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_md_force_sd]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_md_elongation_sd]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_cd_force_sd]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_cd_elongation_sd]" readonly class="readonly"></td>
            </tr>
            <tr>
              <td style="font-weight: bold;">CV%:</td>
              <td><input type="number" step="0.01" name="summary[gsm_cv]" readonly class="readonly"></td>
              <td><input type="number" step="0.001" name="summary[thickness_cv]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_md_strength_cv]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_md_elongation_cv]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_cd_strength_cv]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_cd_elongation_cv]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[cbr_force_cv]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[cbr_displacement_cv]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_md_force_cv]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_md_elongation_cv]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_cd_force_cv]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_cd_elongation_cv]" readonly class="readonly"></td>
            </tr>
            <tr>
              <td style="font-weight: bold;">Maximum:</td>
              <td><input type="number" step="0.01" name="summary[gsm_max]" readonly class="readonly"></td>
              <td><input type="number" step="0.001" name="summary[thickness_max]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_md_strength_max]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_md_elongation_max]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_cd_strength_max]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_cd_elongation_max]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[cbr_force_max]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[cbr_displacement_max]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_md_force_max]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_md_elongation_max]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_cd_force_max]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_cd_elongation_max]" readonly class="readonly"></td>
            </tr>
            <tr>
              <td style="font-weight: bold;">Minimum:</td>
              <td><input type="number" step="0.01" name="summary[gsm_min]" readonly class="readonly"></td>
              <td><input type="number" step="0.001" name="summary[thickness_min]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_md_strength_min]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_md_elongation_min]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_cd_strength_min]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[strip_cd_elongation_min]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[cbr_force_min]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[cbr_displacement_min]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_md_force_min]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_md_elongation_min]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_cd_force_min]" readonly class="readonly"></td>
              <td><input type="number" step="0.01" name="summary[grab_cd_elongation_min]" readonly class="readonly"></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Approved By (Only visible to Admin/AGM Ops) -->
    <?php if ($can_approve): ?>
    <div class="form-group">
      <label>Approved By:</label>
      <input type="text" name="approved_by" value="<?php echo htmlspecialchars($reporter_full_name); ?>" readonly class="readonly">
    </div>
    <?php endif; ?>

    <div class="actions">
      <button type="submit" name="submit_report" class="submit-btn">Submit</button>
      <button type="button" class="clear-btn" onclick="clearForm()">Clear</button>
    </div>
  </form>
  <?php endif; ?>
  <!-- End of form section (hidden from checkers) -->

</div>

<script>
let rowCount = 0;
let gsmGroupCount = 0;
let thicknessGroupCount = 0;
let stripGroupCount = 0;

// Initialize with 4 rows
document.addEventListener('DOMContentLoaded', function() {
    addFourRows();
    updateTimeAndShift();
});

function initializeAverages() {
    // Clear all average fields initially
    for (let i = 1; i <= rowCount; i++) {
        document.querySelector(`input[name="test_data[gsm_average][${i}]"]`).value = '';
        document.querySelector(`input[name="test_data[thickness_average][${i}]"]`).value = '';
    }
}

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

function addFourRows() {
    const tbody = document.getElementById('rawDataBody');
    
    for (let i = 0; i < 4; i++) {
        const row = document.createElement('tr');
        
        row.innerHTML = `
            <!-- GSM Test columns -->
            <td><input type="text" name="test_data[position][${rowCount}]" placeholder="Position" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;"></td>
            <td><input type="number" step="0.0001" min="0" name="test_data[gsm_weight][${rowCount}]" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" autocomplete="off" onkeydown="blockNonNumeric(event)"></td>
            <td><input type="number" step="0.01" min="0" name="test_data[gsm_calculated][${rowCount}]" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" autocomplete="off" onkeydown="blockNonNumeric(event)" oninput="calculateGsmAverage(${rowCount}); updateSummaryOfResults();"></td>
            <td><input type="number" step="0.01" min="0" name="test_data[gsm_average][${rowCount}]" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; background-color: #f5f5f5;"></td>
            <!-- Thickness Test columns -->
            <td><input type="number" step="0.001" min="0" name="test_data[thickness][${rowCount}]" oninput="calculateThicknessAverage(${rowCount}); updateSummaryOfResults();" autocomplete="off" onkeydown="blockNonNumeric(event)"></td>
            <td><input type="number" step="0.001" min="0" name="test_data[thickness_average][${rowCount}]" readonly class="readonly"></td>
            <!-- Strip Tensile Test columns -->
            <td>
                <select name="test_data[strip_direction][${rowCount}]" oninput="calculateStripRatio(${rowCount}); updateSummaryOfResults();">
                    <option value="MD" ${rowCount % 2 === 1 ? 'selected' : ''}>MD</option>
                    <option value="CD" ${rowCount % 2 === 0 ? 'selected' : ''}>CD</option>
                </select>
            </td>
            <td><input type="number" step="0.01" min="0" name="test_data[strip_strength][${rowCount}]" oninput="calculateStripRatio(${rowCount}); updateSummaryOfResults();" autocomplete="off" onkeydown="blockNonNumeric(event)"></td>
            <td><input type="text" name="test_data[strip_ratio][${rowCount}]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" min="0" name="test_data[strip_elongation][${rowCount}]" oninput="updateSummaryOfResults()" onkeydown="blockNonNumeric(event)"></td>
            <!-- CBR Test columns -->
            <td><input type="number" step="0.01" min="0" name="test_data[cbr_force][${rowCount}]" oninput="updateSummaryOfResults()" onkeydown="blockNonNumeric(event)"></td>
            <td><input type="number" step="0.01" min="0" name="test_data[cbr_displacement][${rowCount}]" oninput="updateSummaryOfResults()" onkeydown="blockNonNumeric(event)"></td>
            <!-- Grab Tensile Test columns -->
            <td>
                <select name="test_data[grab_direction][${rowCount}]" oninput="updateSummaryOfResults();">
                    <option value="MD" ${rowCount % 2 === 1 ? 'selected' : ''}>MD</option>
                    <option value="CD" ${rowCount % 2 === 0 ? 'selected' : ''}>CD</option>
                </select>
            </td>
            <td><input type="number" step="0.01" min="0" name="test_data[grab_force][${rowCount}]" oninput="updateSummaryOfResults()" onkeydown="blockNonNumeric(event)"></td>
            <td><input type="number" step="0.01" min="0" name="test_data[grab_elongation][${rowCount}]" oninput="updateSummaryOfResults()" onkeydown="blockNonNumeric(event)"></td>
        `;
        tbody.appendChild(row);
        
        // Assuming rowCount increments *after* each row is appended
        rowCount++; // increment after adding a row
        
        // Add separator after every 2 rows (MD/CD pair)
        if (rowCount % 2 === 0) {
            const stripSeparatorRow = document.createElement('tr');
            stripSeparatorRow.innerHTML = `
                <td style="border-top: none;"></td>
                <td style="border-top: none;"></td>
                <td style="border-top: none;"></td>
                <td style="border-top: none;"></td>
                <td style="border-top: none;"></td>
                <td style="border-top: none;"></td>
                <td colspan="4" style="border-top: 2px solid #999; height: 2px; padding: 0;"></td>
                <td style="border-top: none;"></td>
                <td style="border-top: none;"></td>
                <td colspan="3" style="border-top: 2px solid #999; height: 2px; padding: 0;"></td>
            `;
            tbody.appendChild(stripSeparatorRow); // append after the 2nd row
        }
        
        // Add separator line after every 4 rows (use ash color like MD/CD separator)
        if (rowCount % 4 === 0 && rowCount > 0) {
            const separatorRow = document.createElement('tr');
            separatorRow.innerHTML = '<td colspan="14" style="border-top: 2px solid #999; height: 2px; padding: 0;"></td>';
            tbody.appendChild(separatorRow);
        }
    }
    
    // Update all calculations
    updateAllCalculations();
}

function calculateGsm(rowNum) {
    const weight = parseFloat(document.querySelector(`input[name="test_data[gsm_weight][${rowNum}]"]`).value) || 0;
    const area = 100; // Standard area for GSM calculation
    
    if (area > 0) {
        const gsm = (weight / area) * 10000; // Convert to g/m²
        document.querySelector(`input[name="test_data[gsm_calculated][${rowNum}]"]`).value = gsm.toFixed(2);
        calculateGsmAverage(rowNum);
    }
}

function calculateGsmAverage(rowNum) {
    // 0-based grouping: 0-3, 4-7, 8-11, 12-15
    const maxIndex = Math.max(0, rowCount - 1);
    const groupStart = Math.floor(rowNum / 4) * 4;
    const groupEnd = Math.min(groupStart + 3, maxIndex);
    
    // Clear all average fields in the current group first
    for (let i = groupStart; i <= groupEnd; i++) {
        const avgEl = document.querySelector(`input[name="test_data[gsm_average][${i}]"]`);
        if (avgEl) avgEl.value = '';
    }
    
    // Collect all calculated values in the group
    const groupValues = [];
    for (let i = groupStart; i <= groupEnd; i++) {
        const el = document.querySelector(`input[name="test_data[gsm_calculated][${i}]"]`);
        if (!el) continue;
        const val = parseFloat(el.value);
        if (!isNaN(val)) groupValues.push(val);
    }
    
    // Only show average in the last index of the current 4-row block
    if (groupValues.length > 0) {
        const groupAvg = groupValues.reduce((a, b) => a + b, 0) / groupValues.length;
        const targetEl = document.querySelector(`input[name="test_data[gsm_average][${groupEnd}]"]`);
        if (targetEl) targetEl.value = groupAvg.toFixed(1);
    }
}

function calculateThicknessAverage(rowNum) {
    // Determine the row's index among thickness rows to avoid index drift
    const tbody = document.getElementById('rawDataBody');
    const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.querySelector('input[name^="test_data[thickness]["]'));
    // Find the row element using the input that triggered this calculation
    const currentInput = document.querySelector(`input[name="test_data[thickness][${rowNum}]"]`);
    if (!currentInput) return;
    const currentRow = currentInput.closest('tr');
    const index = rows.indexOf(currentRow);
    if (index < 0) return;

    const groupStart = Math.floor(index / 4) * 4;
    const groupEndIndex = Math.min(groupStart + 3, rows.length - 1);

    // Clear current group's average display cells
    for (let i = groupStart; i <= groupEndIndex; i++) {
        const avgEl = rows[i].querySelector('input[name^="test_data[thickness_average]"]');
        if (avgEl) avgEl.value = '';
    }

    // Collect values for this 4-row block only
    const vals = [];
    for (let i = groupStart; i <= groupEndIndex; i++) {
        const el = rows[i].querySelector('input[name^="test_data[thickness]["]');
        if (!el) continue;
        const v = parseFloat(el.value);
        if (!isNaN(v)) vals.push(v);
    }

    if (vals.length > 0) {
        const avg = vals.reduce((a, b) => a + b, 0) / vals.length;
        const rounded = Math.ceil(avg * 1000) / 1000; // always round up to 3 decimals
        const targetAvgEl = rows[groupEndIndex].querySelector('input[name^="test_data[thickness_average]"]');
        if (targetAvgEl) targetAvgEl.value = rounded.toFixed(3);
    }
}

function calculateStripRatio(rowNum) {
    const row = document.querySelector(`input[name="test_data[strip_strength][${rowNum}]"]`).closest('tr');
    const tbody = row.closest('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.querySelector('input[name*="strip_strength"]'));
    const index = rows.indexOf(row);
    if (index < 0) return;
    const groupStart = Math.floor(index / 4) * 4;

    const computeAndSetForPair = (mdRow, cdRow) => {
        if (!mdRow || !cdRow) return;
        const mdVal = parseFloat(mdRow.querySelector('input[name*="strip_strength"]')?.value || '0') || 0;
        const cdVal = parseFloat(cdRow.querySelector('input[name*="strip_strength"]')?.value || '0') || 0;
        
        const mdRatioEl = mdRow.querySelector('input[name*="strip_ratio"]');
        const cdRatioEl = cdRow.querySelector('input[name*="strip_ratio"]');
        
        // Clear both ratio fields first
        if (mdRatioEl) mdRatioEl.value = '';
        if (cdRatioEl) cdRatioEl.value = '';
        
        // Only show ratio if both values are entered and greater than 0
        if (mdVal > 0 && cdVal > 0) {
            const display = `1:${(cdVal / mdVal).toFixed(2)}`;
            if (cdRatioEl) cdRatioEl.value = display; // show in CD row
        }
    };

    // First MD/CD pair (rows groupStart, groupStart+1)
    computeAndSetForPair(rows[groupStart], rows[groupStart + 1]);
    // Second MD/CD pair (rows groupStart+2, groupStart+3)
    computeAndSetForPair(rows[groupStart + 2], rows[groupStart + 3]);
}

function updateAllCalculations() {
    // Update all calculations for existing rows (0-based indexing)
    for (let i = 0; i < rowCount; i++) {
        calculateGsmAverage(i);
        calculateThicknessAverage(i);
        calculateStripRatio(i);
    }
    updateSummaryOfResults();
}

// Compute Summary of Test Result table values from the raw data table
function updateSummaryOfResults() {
    // Helper to compute average from numeric array
    const computeAverage = (arr) => {
        if (!arr.length) return null;
        const sum = arr.reduce((a, b) => a + b, 0);
        return sum / arr.length;
    };
    // Helper to compute population standard deviation (n)
    const computePopulationSd = (arr) => {
        if (!arr || arr.length === 0) return null;
        if (arr.length === 1) return 0;
        const mean = computeAverage(arr);
        const variance = arr.reduce((acc, x) => acc + Math.pow(x - mean, 2), 0) / arr.length;
        return Math.sqrt(variance);
    };

    // GSM average: average of group averages (use 0-based groups; pick 3,7,11...)
    const gsmGroupAverages = [];
    for (let start = 0; start < rowCount; start += 4) {
        const idx = Math.min(start + 3, rowCount - 1);
        const el = document.querySelector(`input[name="test_data[gsm_average][${idx}]"]`);
        if (el) {
            const v = parseFloat(el.value);
            if (!isNaN(v)) gsmGroupAverages.push(v);
        }
    }
    const gsmAvg = computeAverage(gsmGroupAverages);
    const gsmAvgEl = document.querySelector('input[name="summary[gsm_avg]"]');
    if (gsmAvgEl) {
        // Round to 1 decimal with proper rounding (406.55 -> 406.6)
        gsmAvgEl.value = gsmAvg != null ? parseFloat(gsmAvg.toFixed(1)) : '';
    }
    // GSM SD and CV% (compute from raw values so it works with only 4 rows too)
    const gsmAllForStats = [];
    for (let i = 0; i < rowCount; i++) {
        const el = document.querySelector(`input[name="test_data[gsm_calculated][${i}]"]`);
        if (el) {
            const v = parseFloat(el.value);
            if (!isNaN(v) && v > 0) gsmAllForStats.push(v);
        }
    }
    let gsmSd = computePopulationSd(gsmAllForStats);
    if (gsmSd == null) gsmSd = computePopulationSd(gsmGroupAverages);
    const gsmSdEl = document.querySelector('input[name="summary[gsm_sd]"]');
    if (gsmSdEl) gsmSdEl.value = gsmSd != null ? gsmSd.toFixed(1) : '';
    const gsmCvEl = document.querySelector('input[name="summary[gsm_cv]"]');
    if (gsmCvEl) {
        const baseAvg = gsmAllForStats.length ? computeAverage(gsmAllForStats) : gsmAvg;
        if (baseAvg && gsmSd != null && baseAvg !== 0) {
            const cv = (gsmSd / baseAvg) * 100;
            // Floor/truncate CV to 2 decimals: 3.96xx -> 3.96
            gsmCvEl.value = (Math.floor(cv * 100) / 100).toFixed(2);
        } else {
            gsmCvEl.value = '';
        }
    }
    // GSM Max and Min
    const gsmMaxEl = document.querySelector('input[name="summary[gsm_max]"]');
    const gsmMinEl = document.querySelector('input[name="summary[gsm_min]"]');
    if (gsmAllForStats.length) {
        if (gsmMaxEl) gsmMaxEl.value = Math.max(...gsmAllForStats).toFixed(1);
        if (gsmMinEl) gsmMinEl.value = Math.min(...gsmAllForStats).toFixed(1);
    } else {
        if (gsmMaxEl) gsmMaxEl.value = '';
        if (gsmMinEl) gsmMinEl.value = '';
    }

    // Thickness average: average of group averages (0-based groups; pick 3,7,11...)
    const thkGroupAverages = [];
    for (let start = 0; start < rowCount; start += 4) {
        const idx = Math.min(start + 3, rowCount - 1);
        const el = document.querySelector(`input[name="test_data[thickness_average][${idx}]"]`);
        if (el) {
            const v = parseFloat(el.value);
            if (!isNaN(v)) thkGroupAverages.push(v);
        }
    }
    const thkAvg = computeAverage(thkGroupAverages);
    const thkAvgEl = document.querySelector('input[name="summary[thickness_avg]"]');
    if (thkAvgEl) thkAvgEl.value = thkAvg != null ? thkAvg.toFixed(3) : '';
    // Thickness SD and CV% (compute from raw values so it works with only 4 rows too)
    const thkAllForStats = [];
    for (let i = 0; i < rowCount; i++) {
        const el = document.querySelector(`input[name="test_data[thickness][${i}]"]`);
        if (el) {
            const v = parseFloat(el.value);
            if (!isNaN(v) && v > 0) thkAllForStats.push(v);
        }
    }
    let thkSd = computePopulationSd(thkAllForStats);
    if (thkSd == null) thkSd = computePopulationSd(thkGroupAverages);
    const thkSdEl = document.querySelector('input[name="summary[thickness_sd]"]');
    if (thkSdEl) thkSdEl.value = thkSd != null ? thkSd.toFixed(3) : '';
    const thkCvEl = document.querySelector('input[name="summary[thickness_cv]"]');
    if (thkCvEl) {
        const baseAvg = thkAllForStats.length ? computeAverage(thkAllForStats) : thkAvg;
        if (baseAvg && thkSd != null && baseAvg !== 0) {
            const cv = (thkSd / baseAvg) * 100;
            // Floor/truncate CV to 2 decimals
            thkCvEl.value = (Math.floor(cv * 100) / 100).toFixed(2);
    } else {
            thkCvEl.value = '';
        }
    }
    // Thickness Max and Min
    const thkMaxEl = document.querySelector('input[name="summary[thickness_max]"]');
    const thkMinEl = document.querySelector('input[name="summary[thickness_min]"]');
    if (thkAllForStats.length) {
        if (thkMaxEl) thkMaxEl.value = Math.max(...thkAllForStats).toFixed(3);
        if (thkMinEl) thkMinEl.value = Math.min(...thkAllForStats).toFixed(3);
    } else {
        if (thkMaxEl) thkMaxEl.value = '';
        if (thkMinEl) thkMinEl.value = '';
    }

    // Compute stats helper with population SD
    const computeStats = (arr) => {
        if (!Array.isArray(arr) || arr.length === 0) {
            return { avg: null, sd: null, cv: null, max: null, min: null };
        }
        const avg = computeAverage(arr);
        const sd = computePopulationSd(arr);
        // Floor CV to 2 decimals (truncate, not round)
        let cv = 0;
        if (avg && sd != null && avg !== 0) {
            const cvRaw = (sd / avg) * 100;
            cv = Math.floor(cvRaw * 100) / 100;
        }
        return {
            avg,
            sd,
            cv,
            max: Math.max(...arr),
            min: Math.min(...arr)
        };
    };
    
    // Helper: collect numeric values from inputs
    const collectNumeric = (baseName, filterPositive = false) => {
        const out = [];
    for (let i = 0; i < rowCount; i++) {
            const el = document.querySelector(`input[name="test_data[${baseName}][${i}]"]`);
            if (!el) continue;
            const v = parseFloat(el.value);
            if (isNaN(v)) continue;
            if (filterPositive && v <= 0) continue;
            out.push(v);
        }
        return out;
    };
    
    // CBR Test calculations
    const cbrForce = collectNumeric('cbr_force', true);
    const cbrDisp = collectNumeric('cbr_displacement', true);
    const cbrStats_force = computeStats(cbrForce);
    const cbrStats_disp = computeStats(cbrDisp);
    
    const setVal = (sel, val, digits) => { const el = document.querySelector(sel); if (el) el.value = val!=null? val.toFixed(digits):''; };
    setVal('input[name="summary[cbr_force_avg]"]', cbrStats_force.avg, 1);
    setVal('input[name="summary[cbr_displacement_avg]"]', cbrStats_disp.avg, 1);
    setVal('input[name="summary[cbr_force_sd]"]', cbrStats_force.sd, 1);
    setVal('input[name="summary[cbr_displacement_sd]"]', cbrStats_disp.sd, 1);
    setVal('input[name="summary[cbr_force_cv]"]', cbrStats_force.cv, 2);
    setVal('input[name="summary[cbr_displacement_cv]"]', cbrStats_disp.cv, 2);
    setVal('input[name="summary[cbr_force_max]"]', cbrStats_force.max, 1);
    setVal('input[name="summary[cbr_force_min]"]', cbrStats_force.min, 1);
    setVal('input[name="summary[cbr_displacement_max]"]', cbrStats_disp.max, 1);
    setVal('input[name="summary[cbr_displacement_min]"]', cbrStats_disp.min, 1);
    
    // Grab Test calculations
    const grabMdForce = [], grabMdElong = [], grabCdForce = [], grabCdElong = [];
    for (let i = 0; i < rowCount; i++) {
        const dirEl = document.querySelector(`input[name="test_data[grab_direction][${i}]"]`) || document.querySelector(`select[name="test_data[grab_direction][${i}]"]`);
        const forceEl = document.querySelector(`input[name="test_data[grab_force][${i}]"]`);
        const elongEl = document.querySelector(`input[name="test_data[grab_elongation][${i}]"]`);
        const dir = dirEl ? (dirEl.value || '').toString().trim().toUpperCase() : '';
        const f = forceEl ? parseFloat(forceEl.value) : NaN;
        const e = elongEl ? parseFloat(elongEl.value) : NaN;
        if (!isNaN(f) && f > 0) (dir.startsWith('MD') ? grabMdForce : grabCdForce).push(f);
        if (!isNaN(e) && e > 0) (dir.startsWith('MD') ? grabMdElong : grabCdElong).push(e);
    }
    
    setVal('input[name="summary[grab_md_force_avg]"]', computeStats(grabMdForce).avg, 1);
    setVal('input[name="summary[grab_md_elongation_avg]"]', computeStats(grabMdElong).avg, 1);
    setVal('input[name="summary[grab_cd_force_avg]"]', computeStats(grabCdForce).avg, 1);
    setVal('input[name="summary[grab_cd_elongation_avg]"]', computeStats(grabCdElong).avg, 1);
    setVal('input[name="summary[grab_md_force_sd]"]', computeStats(grabMdForce).sd, 1);
    setVal('input[name="summary[grab_md_elongation_sd]"]', computeStats(grabMdElong).sd, 1);
    setVal('input[name="summary[grab_cd_force_sd]"]', computeStats(grabCdForce).sd, 1);
    setVal('input[name="summary[grab_cd_elongation_sd]"]', computeStats(grabCdElong).sd, 1);
    setVal('input[name="summary[grab_md_force_cv]"]', computeStats(grabMdForce).cv, 2);
    setVal('input[name="summary[grab_md_elongation_cv]"]', computeStats(grabMdElong).cv, 2);
    setVal('input[name="summary[grab_cd_force_cv]"]', computeStats(grabCdForce).cv, 2);
    setVal('input[name="summary[grab_cd_elongation_cv]"]', computeStats(grabCdElong).cv, 2);
    setVal('input[name="summary[grab_md_force_max]"]', computeStats(grabMdForce).max, 1);
    setVal('input[name="summary[grab_md_elongation_max]"]', computeStats(grabMdElong).max, 1);
    setVal('input[name="summary[grab_cd_force_max]"]', computeStats(grabCdForce).max, 1);
    setVal('input[name="summary[grab_cd_elongation_max]"]', computeStats(grabCdElong).max, 1);
    setVal('input[name="summary[grab_md_force_min]"]', computeStats(grabMdForce).min, 1);
    setVal('input[name="summary[grab_md_elongation_min]"]', computeStats(grabMdElong).min, 1);
    setVal('input[name="summary[grab_cd_force_min]"]', computeStats(grabCdForce).min, 1);
    setVal('input[name="summary[grab_cd_elongation_min]"]', computeStats(grabCdElong).min, 1);
    
    // Strip Tensile Test calculations
    const stripMdStrength = [], stripMdElong = [], stripCdStrength = [], stripCdElong = [];
    for (let i = 0; i < rowCount; i++) {
        const dirEl = document.querySelector(`input[name="test_data[strip_direction][${i}]"]`) || document.querySelector(`select[name="test_data[strip_direction][${i}]"]`);
        const strengthEl = document.querySelector(`input[name="test_data[strip_strength][${i}]"]`);
        const elongEl = document.querySelector(`input[name="test_data[strip_elongation][${i}]"]`);
        const dir = dirEl ? (dirEl.value || '').toString().trim().toUpperCase() : '';
        const s = strengthEl ? parseFloat(strengthEl.value) : NaN;
        const e = elongEl ? parseFloat(elongEl.value) : NaN;
        if (!isNaN(s) && s > 0) (dir.startsWith('MD') ? stripMdStrength : stripCdStrength).push(s);
        if (!isNaN(e) && e > 0) (dir.startsWith('MD') ? stripMdElong : stripCdElong).push(e);
    }
    
    const set1 = (sel, val) => { const el = document.querySelector(sel); if (el) el.value = val!=null? val.toFixed(1):''; };
    const set2 = (sel, val) => { const el = document.querySelector(sel); if (el) el.value = val!=null? val.toFixed(2):''; };
    set1('input[name="summary[strip_md_strength_avg]"]', computeStats(stripMdStrength).avg);
    set1('input[name="summary[strip_md_elongation_avg]"]', computeStats(stripMdElong).avg);
    set1('input[name="summary[strip_cd_strength_avg]"]', computeStats(stripCdStrength).avg);
    set1('input[name="summary[strip_cd_elongation_avg]"]', computeStats(stripCdElong).avg);
    set1('input[name="summary[strip_md_strength_sd]"]', computeStats(stripMdStrength).sd);
    set1('input[name="summary[strip_md_elongation_sd]"]', computeStats(stripMdElong).sd);
    set1('input[name="summary[strip_cd_strength_sd]"]', computeStats(stripCdStrength).sd);
    set1('input[name="summary[strip_cd_elongation_sd]"]', computeStats(stripCdElong).sd);
    set2('input[name="summary[strip_md_strength_cv]"]', computeStats(stripMdStrength).cv);
    set2('input[name="summary[strip_md_elongation_cv]"]', computeStats(stripMdElong).cv);
    set2('input[name="summary[strip_cd_strength_cv]"]', computeStats(stripCdStrength).cv);
    set2('input[name="summary[strip_cd_elongation_cv]"]', computeStats(stripCdElong).cv);
    set1('input[name="summary[strip_md_strength_max]"]', computeStats(stripMdStrength).max);
    set1('input[name="summary[strip_md_elongation_max]"]', computeStats(stripMdElong).max);
    set1('input[name="summary[strip_cd_strength_max]"]', computeStats(stripCdStrength).max);
    set1('input[name="summary[strip_cd_elongation_max]"]', computeStats(stripCdElong).max);
    set1('input[name="summary[strip_md_strength_min]"]', computeStats(stripMdStrength).min);
    set1('input[name="summary[strip_md_elongation_min]"]', computeStats(stripMdElong).min);
    set1('input[name="summary[strip_cd_strength_min]"]', computeStats(stripCdStrength).min);
    set1('input[name="summary[strip_cd_elongation_min]"]', computeStats(stripCdElong).min);
}

function updateProductReference() {
    const gsm = document.querySelector('input[name="gsm"]').value;
    const lineNo = document.querySelector('input[name="line_no"]').value;
    const rollNumber = document.querySelector('input[name="roll_number"]').value;
    const batchInfo = document.querySelector('input[name="batch_information"]').value;
    
    if (gsm && lineNo && rollNumber && batchInfo) {
        const now = new Date();
        const months = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
        const currentMonth = months[now.getMonth()];
        const currentDate = String(now.getDate()).padStart(2, '0');
        
        // GSM: 400 -> 4.0
        const gsmValue = (parseFloat(gsm) / 100).toFixed(1);
        
        // Roll Number: 8 -> 08
        const rollNumPadded = String(rollNumber).padStart(2, '0');
        
        // Batch Info: GT9.H1 -> GT0.9H0.1, GT9 -> GT0.9
        let batchFormatted = batchInfo.toUpperCase().trim();
        
        // Split by dot first: GT9.H1 -> ['GT9', 'H1']
        const parts = batchFormatted.split('.');
        let result = '';
        
        for (let part of parts) {
            // Match letters and numbers: GT9 -> ['GT', '9']
            const match = part.match(/([A-Z]+)(\d+(?:\.\d+)?)/);
            if (match) {
                const letters = match[1]; // GT or H
                let num = match[2]; // 9 or 1 or 0.9
                
                // Convert single digit to decimal: 9 -> 0.9, keep 0.9 as 0.9
                if (num && !num.includes('.')) {
                    num = '0.' + num;
                }
                
                result += letters + num;
            } else {
                result += part;
            }
        }
        
        batchFormatted = result;
        
        // Format: 4.0L1JUN18-R08-GT0.9H0.1
        const productRef = `${gsmValue}L${lineNo}${currentMonth}${currentDate}-R${rollNumPadded}-${batchFormatted}`;
        
        document.querySelector('input[name="product_reference"]').value = productRef;
    }
}

// Validate Production Date is not later than Received Date
function validateProductionDate() {
    const receivedDateInput = document.querySelector('input[name="sample_received_date"]');
    const productionDateInput = document.querySelector('input[name="sample_production_date"]');
    
    if (receivedDateInput.value && productionDateInput.value) {
        const receivedDate = new Date(receivedDateInput.value);
        const productionDate = new Date(productionDateInput.value);
        
        if (productionDate > receivedDate) {
            alert('⚠️ Warning: Sample Production Date cannot be later than Sample Received Date!\n\nPlease correct the dates.');
            productionDateInput.value = '';
            productionDateInput.focus();
        }
    }
}

// Validate Test Period dates are not earlier than Sample Received Date
function validateTestPeriod() {
    const receivedDateInput = document.querySelector('input[name="sample_received_date"]');
    const testPeriodFromInput = document.querySelector('input[name="test_period_from"]');
    const testPeriodToInput = document.querySelector('input[name="test_period_to"]');
    
    if (receivedDateInput.value && testPeriodFromInput.value) {
        // Compare only dates (ignore time from datetime-local)
        const receivedDate = new Date(receivedDateInput.value);
        receivedDate.setHours(0, 0, 0, 0);
        const testPeriodFrom = new Date(testPeriodFromInput.value);
        testPeriodFrom.setHours(0, 0, 0, 0);
        
        if (testPeriodFrom < receivedDate) {
            alert('⚠️ Warning: Test Period From date cannot be earlier than Sample Received Date!\n\nSame date or later is allowed.');
            testPeriodFromInput.value = '';
            testPeriodFromInput.focus();
            return;
        }
    }
    
    if (receivedDateInput.value && testPeriodToInput.value) {
        // Compare only dates (ignore time from datetime-local)
        const receivedDate = new Date(receivedDateInput.value);
        receivedDate.setHours(0, 0, 0, 0);
        const testPeriodTo = new Date(testPeriodToInput.value);
        testPeriodTo.setHours(0, 0, 0, 0);
        
        if (testPeriodTo < receivedDate) {
            alert('⚠️ Warning: Test Period To date cannot be earlier than Sample Received Date!\n\nSame date or later is allowed.');
            testPeriodToInput.value = '';
            testPeriodToInput.focus();
            return;
        }
    }
    
    // Also validate Test Period To is not earlier than Test Period From
    if (testPeriodFromInput.value && testPeriodToInput.value) {
        const testPeriodFrom = new Date(testPeriodFromInput.value);
        const testPeriodTo = new Date(testPeriodToInput.value);
        
        if (testPeriodTo < testPeriodFrom) {
            alert('⚠️ Warning: Test Period To date cannot be earlier than Test Period From date!\n\nPlease correct the dates.');
            testPeriodToInput.value = '';
            testPeriodToInput.focus();
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

// Open rejection modal for admin
function openRejectModal(reportNumber) {
    document.getElementById('rejectReportNumber').value = reportNumber;
    document.getElementById('rejectionModal').style.display = 'block';
}

// Close rejection modal
function closeRejectModal() {
    document.getElementById('rejectionModal').style.display = 'none';
    document.getElementById('adminRejectForm').reset();
}

// Validate and submit rejection
function submitAdminRejection() {
    const checkboxes = document.querySelectorAll('input[name="admin_rejection_reasons[]"]');
    const checked = Array.from(checkboxes).filter(cb => cb.checked);
    
    if (checked.length === 0) {
        alert('❌ Please select at least one reason for rejection!');
        return false;
    }
    
    if (confirm('Are you sure you want to reject this report?')) {
        const form = document.getElementById('adminRejectForm');
        form.onsubmit = null; // Remove the return false
        form.submit();
    }
}

// Prepare form for submission - remove readonly from summary fields so they submit
function prepareFormSubmit() {
    // Find all readonly summary fields and remove readonly attribute temporarily
    const summaryFields = document.querySelectorAll('input[name^="summary["]');
    summaryFields.forEach(field => {
        if (field.hasAttribute('readonly')) {
            field.removeAttribute('readonly');
        }
    });
    return true; // Allow form to submit
}
</script>

<!-- Rejection Modal for Admin -->
<div id="rejectionModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow-y:auto;">
  <div style="max-width:600px; margin:50px auto; background:#fff; border-radius:8px; padding:25px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
    <h3 style="margin-top:0; color:#dc3545; border-bottom:2px solid #dc3545; padding-bottom:10px;">
      ❌ Reject Report
    </h3>
    
    <form id="adminRejectForm" method="POST" action="" onsubmit="return false;">
      <input type="hidden" id="rejectReportNumber" name="wf_report_number" value="">
      <input type="hidden" name="wf_action" value="rejected">
      
      <label style="font-weight:600; display:block; margin-bottom:10px;">Reason for Rejection (Select at least one):</label>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="admin_rejection_reasons[]" value="Incorrect Roll Identification" style="margin-right:8px;">
          Incorrect Roll Identification
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="admin_rejection_reasons[]" value="Incorrect Fiber Specification Entry" style="margin-right:8px;">
          Incorrect Fiber Specification Entry
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="admin_rejection_reasons[]" value="Excessive Sampling" style="margin-right:8px;">
          Excessive Sampling
        </label>
      </div>
      
      <label style="font-weight:bold; display:block; margin:15px 0 8px 0;">
        Additional Comments (Optional):
      </label>
      <textarea name="wf_comment" id="adminRejectComment" rows="4" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-family:inherit;" placeholder="Provide additional details..."></textarea>
      
      <div style="margin-top:20px; text-align:right;">
        <button type="button" onclick="closeRejectModal()" style="padding:10px 20px; margin-right:10px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer;">Cancel</button>
        <button type="button" onclick="submitAdminRejection()" class="submit-btn" style="padding:10px 20px; background:#dc3545;">Submit Rejection</button>
      </div>
    </form>
  </div>
</div>

</body>
</html>

