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

$roleLower = strtolower($_SESSION['role'] ?? '');
$user_role = strtolower(trim($_SESSION['role'] ?? ''));

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

// Manufacturer names for dropdown (Simplified short names)
$manufacturerNames = [
    'Natpet',
    'APT',
    'Texofib',
    'Hubei Botao',
    'Jiangsu Botao',
    'Taizhu Hailun',
    'PSF',
    'Other'
];

// Fetch store received entries for reference dropdown (exclude only if fineness fiber test already done)
$storeEntries = [];
try {
    $storeQuery = $conn->query("SELECT sre.entry_number, sre.material_type, sre.amount_kg, sre.date_time as received_date, sre.manufacturer_name 
                                 FROM store_received_entries sre
                                 WHERE sre.entry_number NOT IN (
                                     SELECT DISTINCT store_entry_reference 
                                     FROM fineness_fiber_reports 
                                     WHERE store_entry_reference IS NOT NULL
                                 )
                                 ORDER BY sre.date_time DESC, sre.created_at DESC 
                                 LIMIT 100");
    if ($storeQuery) {
        while ($row = $storeQuery->fetch_assoc()) {
            $storeEntries[] = $row;
        }
    }
} catch (Exception $e) {
    // Silently fail if table doesn't exist yet
    error_log("Error fetching store entries for fineness fiber test: " . $e->getMessage());
}

$message = '';
$error = '';

// Check for success message from session (after redirect)
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Enhanced backend functionality for Fineness of Fiber Report
class FinenessFiberReportHandler {
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
    
    // Get next report number without incrementing (for display only)
    public function getNextReportNumber() {
        $this->ensureTableExists();
        
        try {
            $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
            $hour = (int)$now->format('H');
            
            $dayKey = $now->format('Ymd');
            if ($hour < 8) {
                $yesterday = clone $now;
                $yesterday->modify('-1 day');
                $dayKey = $yesterday->format('Ymd');
            }
            
            $pattern = 'FFR-' . $dayKey . '-%';
            $countStmt = $this->conn->prepare("SELECT COUNT(*) as report_count FROM fineness_fiber_reports WHERE report_number LIKE ?");
            $countStmt->bind_param("s", $pattern);
            $countStmt->execute();
            $result = $countStmt->get_result();
            
            if ($result && $row = $result->fetch_assoc()) {
                $counter = (int)$row['report_count'] + 1;
            } else {
                $counter = 1;
            }
            
            $countStmt->close();
            return "FFR-{$dayKey}-" . str_pad($counter, 5, '0', STR_PAD_LEFT);
            
        } catch (Exception $e) {
            error_log("Tenacity Yarn Report - Get next number error: " . $e->getMessage());
            return "FFR-" . date('YmdHis') . "-" . rand(1000, 9999);
        }
    }
    
    // Generate and increment report number (ONLY called on successful submission)
    public function generateAndIncrementReportNumber() {
        $this->ensureTableExists();
        
        try {
            $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
            $hour = (int)$now->format('H');
            
            $dayKey = $now->format('Ymd');
            if ($hour < 8) {
                $yesterday = clone $now;
                $yesterday->modify('-1 day');
                $dayKey = $yesterday->format('Ymd');
            }
            
            $pattern = 'FFR-' . $dayKey . '-%';
            $countStmt = $this->conn->prepare("SELECT COUNT(*) as report_count FROM fineness_fiber_reports WHERE report_number LIKE ?");
            $countStmt->bind_param("s", $pattern);
            $countStmt->execute();
            $result = $countStmt->get_result();
            
            $counter = 1;
            if ($result && $row = $result->fetch_assoc()) {
                $counter = (int)$row['report_count'] + 1;
            }
            $countStmt->close();
            
            return "FFR-{$dayKey}-" . str_pad($counter, 5, '0', STR_PAD_LEFT);
            
        } catch (Exception $e) {
            error_log("Error generating report number: " . $e->getMessage());
            return "FFR-" . date('YmdHis') . "-" . rand(1000, 9999);
        }
    }
    
    // Enhanced data validation
    public function validateFormData($data) {
        $errors = [];
        
        // Only validate fields that are actually in the simplified form
        $required_fields = [
            'report_number' => 'Report Number',
            'store_entry_reference' => 'Store Entry Reference',
            'test_performed_by' => 'Test Performed By',
        ];
        
        foreach ($required_fields as $field => $label) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                $errors[] = "$label is required.";
            }
        }
        
        // Date validation: Sample Tested date cannot be earlier than Sample Received Date (same date is allowed)
        if (!empty($data['received_date']) && !empty($data['test_start_date'])) {
            // Normalize dates to compare only date part (ignore time)
            $received_date = new DateTime($data['received_date']);
            $test_start_date = new DateTime($data['test_start_date']);
            $received_date->setTime(0, 0, 0);
            $test_start_date->setTime(0, 0, 0);
            
            if ($test_start_date < $received_date) {
                $errors[] = "Sample Tested date cannot be earlier than Sample Received Date.";
            }
        }
        
        // Test results validation
        if (isset($data['test_results']) && is_array($data['test_results'])) {
            foreach ($data['test_results'] as $index => $result) {
                if (isset($result['test_result']) && !is_numeric($result['test_result'])) {
                    $errors[] = "Test result for row " . ($index + 1) . " must be a valid number.";
                }
            }
        }
        
        return $errors;
    }
    
    // Enhanced database operations
    public function saveReport($data) {
        try {
            $this->conn->begin_transaction();
            
            // Validate data
            $validation_errors = $this->validateFormData($data);
            if (!empty($validation_errors)) {
                throw new Exception("Validation failed: " . implode(" ", $validation_errors));
            }
            
            // Sanitize and prepare data
            $report_number = $this->sanitizeInput($data['report_number']);
            $sample_description = $this->sanitizeInput($data['sample_description'] ?? '');
            $lc_no = $this->sanitizeInput($data['lc_no'] ?? '');
            $manufacturer_name = $this->sanitizeInput($data['manufacturer_name'] ?? '');
            $received_date = !empty($data['received_date']) ? $this->sanitizeInput($data['received_date']) : date('Y-m-d H:i:s');
            $test_start_date = !empty($data['test_start_date']) ? $this->sanitizeInput($data['test_start_date']) : date('Y-m-d');
            $test_end_date = $test_start_date; // Use test_start_date as test_end_date for this form
            $rh_percent = floatval($data['rh_percent']);
            $test_performed_by = $this->sanitizeInput($data['test_performed_by']);
            $approved_by = isset($data['approved_by']) ? $this->sanitizeInput($data['approved_by']) : null;
            
            // Create enhanced table structure FIRST
            $createTable = "CREATE TABLE IF NOT EXISTS fineness_fiber_reports (
                id INT AUTO_INCREMENT PRIMARY KEY,
                report_number VARCHAR(100) UNIQUE NOT NULL,
                store_entry_reference VARCHAR(100) NULL,
                sample_description TEXT NULL,
                lc_no VARCHAR(100) NULL,
                manufacturer_name VARCHAR(255) NULL,
                received_date DATE NULL,
                test_start_date DATE NULL,
                test_end_date DATE NULL,
                rh_percent DECIMAL(5,2) DEFAULT 0,
                test_performed_by VARCHAR(100) NOT NULL,
                approved_by VARCHAR(100) NULL,
                test_results JSON,
                reporter_id INT NOT NULL,
                reporter_name VARCHAR(255) NOT NULL,
                status ENUM('pending','approved','rejected') DEFAULT 'pending',
                remarks TEXT NULL,
                rejected_by VARCHAR(100) NULL,
                rejected_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_report_number (report_number),
                INDEX idx_status (status),
                INDEX idx_received_date (received_date),
                INDEX idx_reporter (reporter_id),
                INDEX idx_store_entry_reference (store_entry_reference),
                INDEX idx_status_reporter (status, reporter_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            if (!$this->conn->query($createTable)) {
                throw new Exception("Failed to create table: " . $this->conn->error);
            }
            
            // Add/modify columns to match new schema
            $this->conn->query("ALTER TABLE fineness_fiber_reports ADD COLUMN IF NOT EXISTS store_entry_reference VARCHAR(100) NULL AFTER report_number");
            @$this->conn->query("ALTER TABLE fineness_fiber_reports MODIFY sample_description TEXT NULL");
            @$this->conn->query("ALTER TABLE fineness_fiber_reports ADD COLUMN IF NOT EXISTS lc_no VARCHAR(100) NULL AFTER sample_description");
            @$this->conn->query("ALTER TABLE fineness_fiber_reports MODIFY received_date DATE NULL");
            @$this->conn->query("ALTER TABLE fineness_fiber_reports MODIFY test_start_date DATE NULL");
            @$this->conn->query("ALTER TABLE fineness_fiber_reports MODIFY test_end_date DATE NULL");
            @$this->conn->query("ALTER TABLE fineness_fiber_reports MODIFY rh_percent DECIMAL(5,2) DEFAULT 0");
            @$this->conn->query("ALTER TABLE fineness_fiber_reports MODIFY approved_by VARCHAR(100) NULL");
            @$this->conn->query("ALTER TABLE fineness_fiber_reports ADD COLUMN IF NOT EXISTS manufacturer_name VARCHAR(255) NULL AFTER lc_no");
            @$this->conn->query("ALTER TABLE fineness_fiber_reports ADD COLUMN IF NOT EXISTS remarks TEXT NULL AFTER approved_by");
            @$this->conn->query("ALTER TABLE fineness_fiber_reports ADD COLUMN IF NOT EXISTS rejected_by VARCHAR(100) NULL AFTER remarks");
            @$this->conn->query("ALTER TABLE fineness_fiber_reports ADD COLUMN IF NOT EXISTS rejected_at TIMESTAMP NULL AFTER rejected_by");

            // Check for duplicate report number
            $duplicateCheck = $this->conn->prepare("SELECT id FROM fineness_fiber_reports WHERE report_number = ?");
            $duplicateCheck->bind_param("s", $report_number);
            $duplicateCheck->execute();
            if ($duplicateCheck->get_result()->num_rows > 0) {
                throw new Exception("Report number already exists. Please refresh the page to get a new report number.");
            }
            $duplicateCheck->close();
            
            // Prepare test results JSON
            $test_results_processed = [];
            if (isset($data['test_results']) && is_array($data['test_results'])) {
                // Define parameter names for processing
                $param_names = [
                    1 => 'Unit Weight'
                ];
                foreach ($data['test_results'] as $sl_no => $result) {
                    $test_result = $result['result'] ?? '';
                    $test_results_processed[] = [
                        'sl_no' => $sl_no,
                        'parameter' => $param_names[$sl_no] ?? '',
                        'unit' => $result['unit'] ?? '',
                        'test_result' => $test_result,
                        'remarks' => $result['remarks'] ?? ''
                    ];
                }
            }
            $test_results_json = json_encode($test_results_processed, JSON_UNESCAPED_UNICODE);
            
            // Insert with enhanced error handling
            $stmt = $this->conn->prepare(
                "INSERT INTO fineness_fiber_reports (
                    report_number, store_entry_reference, sample_description, lc_no, manufacturer_name,
                    received_date, test_start_date, test_end_date, 
                    rh_percent, test_performed_by, approved_by, 
                    test_results, reporter_id, reporter_name, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            // Auto-approve if submitted by admin or AGM Ops
            $user_role = strtolower(trim($_SESSION['role'] ?? ''));
            $status = ($user_role === 'admin' || $user_role === 'agm ops' || $user_role === 'agm operations') ? 'approved' : 'pending';
            
            // Ensure status is never empty
            if (empty($status) || trim($status) === '') {
                $status = 'pending';
            }
            
            // store_entry_reference is the Store Entry Reference field from the form
            $stmt->bind_param("ssssssssdsssiss", 
                $report_number, $data['store_entry_reference'], $sample_description, $lc_no, $manufacturer_name,
                $received_date, $test_start_date, $test_end_date,
                $rh_percent, $test_performed_by, $approved_by,
                $test_results_json, $this->reporter_id, $this->reporter_full_name, $status
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to save report: " . $stmt->error);
            }
            
            $report_id = $this->conn->insert_id;
            $stmt->close();
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => "Fineness of Fiber Report saved successfully! Report Number: $report_number",
                'report_id' => $report_id,
                'report_number' => $report_number
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Tenacity Yarn Report Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error: " . $e->getMessage()
            ];
        }
    }
    
    public function getRejectedReportsForUser($user_id) {
        $this->ensureTableExists();
        $this->ensureRemarksColumn();
        
        // Only show reports that are still rejected (not resubmitted)
        $stmt = $this->conn->prepare(
            "SELECT id, report_number, sample_description, status, remarks, created_at, approved_by
             FROM fineness_fiber_reports 
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
    
    private function ensureRemarksColumn() {
        $check = $this->conn->query("SHOW COLUMNS FROM fineness_fiber_reports LIKE 'remarks'");
        if ($check && $check->num_rows == 0) {
            $this->conn->query("ALTER TABLE fineness_fiber_reports ADD COLUMN remarks TEXT NULL AFTER approved_by");
        }
    }
    
    public function canApproveReports() {
        $user_role = strtolower(trim($_SESSION['role'] ?? ''));
        return in_array($user_role, ['admin', 'agm ops', 'agm operations', 'management']);
    }
    
    // Ensure table exists before querying
    public function ensureTableExists() {
        $createTable = "CREATE TABLE IF NOT EXISTS fineness_fiber_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_number VARCHAR(100) UNIQUE NOT NULL,
            store_entry_reference VARCHAR(100) NULL,
            sample_description TEXT NULL,
            lc_no VARCHAR(100) NULL,
            manufacturer_name VARCHAR(255) NULL,
            received_date DATE NULL,
            test_start_date DATE NULL,
            test_end_date DATE NULL,
            rh_percent DECIMAL(5,2) DEFAULT 0,
            test_performed_by VARCHAR(100) NOT NULL,
            approved_by VARCHAR(100) NULL,
            test_results JSON,
            reporter_id INT NOT NULL,
            reporter_name VARCHAR(255) NOT NULL,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            remarks TEXT NULL,
            rejected_by VARCHAR(100) NULL,
            rejected_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_report_number (report_number),
            INDEX idx_status (status),
            INDEX idx_received_date (received_date),
            INDEX idx_reporter (reporter_id),
            INDEX idx_store_entry_reference (store_entry_reference),
            INDEX idx_status_reporter (status, reporter_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $this->conn->query($createTable);
        
        // Update existing table structure to match new schema
        @$this->conn->query("ALTER TABLE fineness_fiber_reports ADD COLUMN IF NOT EXISTS store_entry_reference VARCHAR(100) NULL AFTER report_number");
        @$this->conn->query("ALTER TABLE fineness_fiber_reports MODIFY sample_description TEXT NULL");
        @$this->conn->query("ALTER TABLE fineness_fiber_reports MODIFY received_date DATE NULL");
        @$this->conn->query("ALTER TABLE fineness_fiber_reports MODIFY test_start_date DATE NULL");
        @$this->conn->query("ALTER TABLE fineness_fiber_reports MODIFY test_end_date DATE NULL");
        @$this->conn->query("ALTER TABLE fineness_fiber_reports MODIFY rh_percent DECIMAL(5,2) DEFAULT 0");
        @$this->conn->query("ALTER TABLE fineness_fiber_reports MODIFY approved_by VARCHAR(100) NULL");
        @$this->conn->query("ALTER TABLE fineness_fiber_reports ADD COLUMN IF NOT EXISTS manufacturer_name VARCHAR(255) NULL AFTER lc_no");
        @$this->conn->query("ALTER TABLE fineness_fiber_reports ADD COLUMN IF NOT EXISTS remarks TEXT NULL AFTER approved_by");
        @$this->conn->query("ALTER TABLE fineness_fiber_reports ADD COLUMN IF NOT EXISTS rejected_by VARCHAR(100) NULL AFTER remarks");
        @$this->conn->query("ALTER TABLE fineness_fiber_reports ADD COLUMN IF NOT EXISTS rejected_at TIMESTAMP NULL AFTER rejected_by");
    }
    
    public function getPendingReports($limit = 20) {
        $this->ensureTableExists();
        
        $stmt = $this->conn->prepare(
            "SELECT id, report_number, sample_description AS material, test_performed_by AS tested_by, status, created_at, updated_at
             FROM fineness_fiber_reports WHERE status = 'pending' ORDER BY updated_at DESC LIMIT ?"
        );
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close();
        return $rows;
    }
    
    public function approveOrRejectByReportNumber($report_number, $action, $comments = '') {
        $this->ensureTableExists();
        $this->ensureRemarksColumn();
        
        $action = strtolower($action) === 'approved' ? 'approved' : 'rejected';
        $stmt = $this->conn->prepare("UPDATE fineness_fiber_reports SET status = ?, approved_by = ?, remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE report_number = ?");
        $stmt->bind_param("ssss", $action, $this->reporter_full_name, $comments, $report_number);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['success' => false, 'message' => 'Update failed'];
        }
        $updated = $stmt->affected_rows;
        $stmt->close();
        if ($updated <= 0) {
            return ['success' => false, 'message' => 'Report not found'];
        }
        return ['success' => true, 'message' => 'Report ' . ucfirst($action) . ' successfully.'];
    }
    
    private function sanitizeInput($data) {
        return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
    }
    
    private function logAction($action, $report_id, $details = '') {
        try {
            $logTable = "CREATE TABLE IF NOT EXISTS tenacity_yarn_report_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                report_id INT NOT NULL,
                action VARCHAR(50) NOT NULL,
                details TEXT,
                user_id INT NOT NULL,
                user_name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_report_id (report_id),
                INDEX idx_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            $this->conn->query($logTable);
            
            $stmt = $this->conn->prepare("INSERT INTO tenacity_yarn_report_logs (report_id, action, details, user_id, user_name) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issis", $report_id, $action, $details, $this->reporter_id, $this->reporter_full_name);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            error_log("Failed to log action: " . $e->getMessage());
        }
    }
    
    public function getReportStats() {
        $this->ensureTableExists();
        
        $stats = [
            'total' => 0,
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0
        ];
        
        try {
            $result = $this->conn->query("SELECT status, COUNT(*) as count FROM fineness_fiber_reports GROUP BY status");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $stats['total'] += $row['count'];
                    $stats[$row['status']] = $row['count'];
                }
            }
        } catch (Exception $e) {
            error_log("Error getting stats: " . $e->getMessage());
        }
        
        return $stats;
    }
    
    public function getApprovers() {
        $approvers = [];
        try {
            $result = $this->conn->query("SELECT username, full_name FROM users WHERE role IN ('admin', 'agm ops', 'agm operations', 'management') ORDER BY username");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $approvers[] = $row['full_name'] ?: $row['username'];
                }
            }
        } catch (Exception $e) {
            error_log("Error getting approvers: " . $e->getMessage());
        }
        return $approvers;
    }
}

// Initialize the handler
$FinenessFiberHandler = new FinenessFiberReportHandler($conn, $reporter_id, $reporter_name, $reporter_full_name);

// Fetch pending reports (hide from AGM view)
$pending_reports = [];
$is_agm = in_array($user_role, ['agm ops', 'agm operations']);
$showPendingQueue = $FinenessFiberHandler->canApproveReports() && !$is_agm;
if ($showPendingQueue) {
    $pending_reports = $FinenessFiberHandler->getPendingReports(20);
}

// Check if viewing a report (read-only mode)
$viewMode = false;
$viewData = null;
$viewReportId = null;

if (isset($_GET['view']) && !empty($_GET['view'])) {
    $viewReportId = (int)$_GET['view'];
    $FinenessFiberHandler->ensureTableExists();
    
    $stmt = $conn->prepare("SELECT * FROM fineness_fiber_reports WHERE id = ?");
    $stmt->bind_param("i", $viewReportId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $viewData = $result->fetch_assoc();
        $user_role = strtolower(trim($_SESSION['role'] ?? ''));
        // Admin/AGM can view any report, testers can view their own
        $can_view = in_array($user_role, ['admin', 'agm ops', 'agm operations', 'management']) || 
                    ($viewData['reporter_id'] == $reporter_id);
        
        if ($can_view) {
            $viewMode = true;
        } else {
            $error = "You don't have permission to view this report.";
        }
    } else {
        $error = "Report not found.";
    }
    $stmt->close();
}

// Check if editing a rejected report
$editMode = false;
$editData = null;
$editReportId = null;

if (isset($_GET['id']) && !empty($_GET['id'])) {
    $editReportId = (int)$_GET['id'];
    $FinenessFiberHandler->ensureTableExists();
    
    $stmt = $conn->prepare("SELECT * FROM fineness_fiber_reports WHERE id = ? AND reporter_id = ?");
    $stmt->bind_param("ii", $editReportId, $reporter_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $editData = $result->fetch_assoc();
        // Only allow editing rejected reports (unless admin/AGM)
        $user_role = strtolower(trim($_SESSION['role'] ?? ''));
        $can_edit = in_array($user_role, ['admin', 'agm ops', 'agm operations']) || $editData['status'] === 'rejected';
        
        if ($can_edit) {
            $editMode = true;
            $generated_report_number = $editData['report_number'];
        } else {
            $error = "You can only edit rejected reports. This report status is: " . $editData['status'];
        }
    } else {
        $error = "Report not found or you don't have permission to edit it.";
    }
    $stmt->close();
}

// If not in edit mode, fetch last submitted report to pre-fill general information
$lastSubmittedData = null;
if (!$editMode && !$viewMode) {
    $stmt = $conn->prepare("SELECT * FROM fineness_fiber_reports WHERE reporter_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("i", $reporter_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $lastSubmittedData = $result->fetch_assoc();
    }
    $stmt->close();
}

// Generate report number for display (without incrementing) - only if not editing or viewing
if (!$editMode && !$viewMode) {
    $generated_report_number = $FinenessFiberHandler->getNextReportNumber();
    
    // Force fresh query (table will be created by getNextReportNumber if needed)
    $now_check = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
    $hour_check = (int)$now_check->format('H');
    $dayKey_check = $now_check->format('Ymd');
    if ($hour_check < 8) {
        $yesterday_check = clone $now_check;
        $yesterday_check->modify('-1 day');
        $dayKey_check = $yesterday_check->format('Ymd');
    }
    // Ensure table exists before querying
    $FinenessFiberHandler->ensureTableExists();
    $pattern_check = 'FFR-' . $dayKey_check . '-%';
    $fresh_count = $conn->query("SELECT COUNT(*) as cnt FROM fineness_fiber_reports WHERE report_number LIKE '{$pattern_check}'");
    if ($fresh_count && $row_check = $fresh_count->fetch_assoc()) {
        $next_num = (int)$row_check['cnt'] + 1;
        $generated_report_number = "FFR-{$dayKey_check}-" . str_pad($next_num, 5, '0', STR_PAD_LEFT);
    }
}

// Test parameters with their standards and units (define early for use in form submission)
$test_parameters = [
    1 => ['param' => 'Unit Weight', 'standards' => ['ISO 1973'], 'unit' => 'dTex']
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    $user_role = strtolower(trim($_SESSION['role'] ?? ''));
    
    // Check if updating an existing report
    if ($editMode && isset($_POST['report_id'])) {
        $report_id = (int)$_POST['report_id'];
        $FinenessFiberHandler->ensureTableExists();
        
        // Decode existing test results
        $test_results = json_decode($editData['test_results'] ?? '[]', true) ?? [];
        $updated_results = [];
        
        // Prepare updated test results from form
        if (isset($_POST['test_results']) && is_array($_POST['test_results'])) {
            foreach ($_POST['test_results'] as $sl_no => $result) {
                $updated_results[] = [
                    'sl_no' => $sl_no,
                    'parameter' => $test_parameters[$sl_no]['param'] ?? '',
                    'unit' => $result['unit'] ?? '',
                    'test_result' => $result['result'] ?? '',
                    'remarks' => $result['remarks'] ?? ''
                ];
            }
        }
        $test_results_json = json_encode($updated_results, JSON_UNESCAPED_UNICODE);
        
        // Determine status - auto-approve if admin/AGM, otherwise pending
        $status = (in_array($user_role, ['admin', 'agm ops', 'agm operations'])) ? 'approved' : 'pending';
        // Ensure status is never empty - fallback to 'pending' if somehow empty
        if (empty($status) || trim($status) === '' || !in_array($status, ['pending', 'approved', 'rejected'])) {
            $status = 'pending';
        }
        $approved_by = (in_array($user_role, ['admin', 'agm ops', 'agm operations'])) ? $reporter_full_name : null;
        
        // Update the report - change status from 'rejected' to 'pending' when resubmitted
        $updateStmt = $conn->prepare("
            UPDATE fineness_fiber_reports 
            SET store_entry_reference = ?, sample_description = ?, lc_no = ?, manufacturer_name = ?,
                received_date = ?, test_start_date = ?, test_end_date = ?,
                rh_percent = ?,
                test_results = ?, status = ?, approved_by = ?, remarks = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND reporter_id = ?
        ");
        
        // Store Entry Reference - this is the main reference field from the form dropdown
        $store_entry_ref = $_POST['store_entry_reference'] ?? null;
        $sample_desc = $_POST['sample_description'] ?? '';
        $lc_no_val = $_POST['lc_no'] ?? '';
        $manufacturer_name_val = $_POST['manufacturer_name'] ?? '';
        $received_date_val = !empty($_POST['received_date']) ? $_POST['received_date'] : date('Y-m-d H:i:s');
        $test_start_date_val = !empty($_POST['test_start_date']) ? $_POST['test_start_date'] : date('Y-m-d');
        $test_end_date_val = $test_start_date_val; // Use test_start_date as test_end_date
        $rh_percent_val = 0; // Not used in this form
        
        // Ensure status is set - if empty, default to 'pending'
        if (empty($status) || trim($status) === '') {
            $status = 'pending';
        }
        
        $updateStmt->bind_param(
            "sssssssdsisii",
            $store_entry_ref,
            $sample_desc,
            $lc_no_val,
            $manufacturer_name_val,
            $received_date_val,
            $test_start_date_val,
            $test_end_date_val,
            $rh_percent_val,
            $test_results_json,
            $status,
            $approved_by,
            $report_id,
            $reporter_id
        );
        
        if ($updateStmt->execute()) {
            if (in_array($user_role, ['admin', 'agm ops', 'agm operations'])) {
                $message = "✅ Fineness of Fiber Report updated and auto-approved! Report Number: " . $editData['report_number'];
            } else {
                $message = "✅ Fineness of Fiber Report updated and resubmitted! Report Number: " . $editData['report_number'] . " - Status: Pending Approval";
            }
            $_SESSION['success_message'] = $message;
            // Redirect to clear edit mode and refresh the page - this will remove it from rejected list
            header("Location: fineness_fiber_report.php?success=1&t=" . time());
            exit();
        } else {
            $error = "Error updating report: " . $updateStmt->error;
            // Log the error for debugging
            error_log("Fineness Fiber Report Update Error: " . $updateStmt->error);
        }
        $updateStmt->close();
    } else {
        // Create new report
        $actual_report_number = $FinenessFiberHandler->generateAndIncrementReportNumber();
        $_POST['report_number'] = $actual_report_number;
        
        $result = $FinenessFiberHandler->saveReport($_POST);
        if ($result['success']) {
            if (in_array($user_role, ['admin', 'agm ops', 'agm operations'])) {
                $message = "✅ Fineness of Fiber Report saved and auto-approved! Report Number: " . $actual_report_number;
            } else {
                $message = "✅ Fineness of Fiber Report submitted successfully! Report Number: " . $actual_report_number . " - Status: Pending Approval";
            }
            
            $_SESSION['success_message'] = $message;
            $success_msg = urlencode($message);
            header("Location: fineness_fiber_report.php?success=1&msg={$success_msg}&t=" . time());
            exit();
        } else {
            $error = $result['message'];
        }
    }
}

// Admin quick approve/reject by report number
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wf_action'])) {
    $wf_report_number = trim($_POST['wf_report_number'] ?? '');
    $wf_action = $_POST['wf_action'];
    $wf_comment = trim($_POST['wf_comment'] ?? '');
    
    if ($wf_report_number === '') {
        $error = '❌ Error: Report Number is required for approval.';
    } else {
        $result = $FinenessFiberHandler->approveOrRejectByReportNumber($wf_report_number, $wf_action, $wf_comment);
        if ($result['success']) {
            $_SESSION['success_message'] = $result['message'];
            header("Location: fineness_fiber_report.php?success=1&t=" . time());
            exit();
        } else {
            $error = $result['message'];
        }
    }
}

// Get report statistics
$report_stats = $FinenessFiberHandler->getReportStats();

// Unit options for dropdown
$unit_options = ['dTex'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fineness of Fiber Report (ISO 1973)</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
  .form-row { display:flex; gap:20px; margin-bottom:25px; }
  .form-row .form-group { flex:1; }
  select { padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px); background:#fff; font-family:'Inter',sans-serif; }
  select:focus { outline:none; border-color:#3498db; box-shadow:0 0 0 2px rgba(52,152,219,0.2); }
  select:hover { border-color:#bdc3c7; }
</style>
</head>
<body>
<div class="container">
  <h1><?php echo $viewMode ? 'View ' : ($editMode ? 'Edit Rejected ' : ''); ?>Fineness of Fiber Report (ISO 1973)</h1>

  <?php if ($viewMode): ?>
  <!-- View Mode (Read-Only) - Display form as submitted -->
  <?php
  $test_results = json_decode($viewData['test_results'] ?? '[]', true) ?? [];
  ?>
  
  <div class="info-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:15px; margin-bottom:20px;">
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Report Number</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['report_number']); ?></div>
    </div>
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Sample Name</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['sample_description'] ?? 'N/A'); ?></div>
    </div>
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">LC No</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['lc_no'] ?? 'N/A'); ?></div>
    </div>
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Manufacturer Name</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['manufacturer_name'] ?? 'N/A'); ?></div>
    </div>
  </div>

  <div class="info-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:15px; margin-bottom:20px;">
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Store Entry Reference</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['store_entry_reference'] ?? 'N/A'); ?></div>
    </div>
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Sample Received Date</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo $viewData['received_date'] ? date('d M Y', strtotime($viewData['received_date'])) : 'N/A'; ?></div>
    </div>
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Sample Tested Date</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo $viewData['test_start_date'] ? date('d M Y', strtotime($viewData['test_start_date'])) : 'N/A'; ?></div>
    </div>
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Status</div>
      <div class="info-value">
        <span class="status-badge" style="padding:4px 12px; border-radius:12px; font-size:12px; font-weight:600; display:inline-block; 
          background:<?php echo $viewData['status'] === 'approved' ? '#d4edda' : ($viewData['status'] === 'rejected' ? '#f8d7da' : '#fff3cd'); ?>; 
          color:<?php echo $viewData['status'] === 'approved' ? '#155724' : ($viewData['status'] === 'rejected' ? '#721c24' : '#856404'); ?>;">
          <?php echo strtoupper(htmlspecialchars($viewData['status'])); ?>
        </span>
      </div>
    </div>
  </div>

  <div class="info-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:15px; margin-bottom:20px;">
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Test Performed By</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['test_performed_by']); ?></div>
    </div>
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Submitted At</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['created_at']); ?></div>
    </div>
    <?php if ($viewData['approved_by']): ?>
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Approved By</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['approved_by']); ?></div>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($viewData['remarks'])): ?>
  <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db; margin-top:15px;">
    <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Remarks (Approval Notes)</div>
    <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['remarks']); ?></div>
  </div>
  <?php endif; ?>

  <h3 style="margin-top:30px;">Test Results</h3>
  <?php if (!empty($test_results) && is_array($test_results)): ?>
  <table style="width:100%; border-collapse:collapse; margin-top:20px;">
    <thead>
      <tr>
        <th style="border:1px solid #ddd; padding:10px; text-align:left; background:#3498db; color:#fff; font-weight:600;">Parameter</th>
        <th style="border:1px solid #ddd; padding:10px; text-align:left; background:#3498db; color:#fff; font-weight:600;">Test Standard</th>
        <th style="border:1px solid #ddd; padding:10px; text-align:left; background:#3498db; color:#fff; font-weight:600;">Unit</th>
        <th style="border:1px solid #ddd; padding:10px; text-align:left; background:#3498db; color:#fff; font-weight:600;">Test Result</th>
        <th style="border:1px solid #ddd; padding:10px; text-align:left; background:#3498db; color:#fff; font-weight:600;">Remarks</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($test_results as $result): 
        // Skip if no parameter or no test result
        if (empty($result['parameter']) || $result['parameter'] === 'N/A') continue;
        if (empty($result['test_result']) && empty($result['unit'])) continue;
      ?>
      <tr>
        <td style="border:1px solid #ddd; padding:10px; text-align:left;"><strong><?php echo htmlspecialchars($result['parameter']); ?></strong></td>
        <td style="border:1px solid #ddd; padding:10px; text-align:left;">ISO 1973</td>
        <td style="border:1px solid #ddd; padding:10px; text-align:left;"><?php echo htmlspecialchars($result['unit'] ?? '-'); ?></td>
        <td style="border:1px solid #ddd; padding:10px; text-align:left;"><strong><?php echo htmlspecialchars($result['test_result'] ?? '-'); ?></strong></td>
        <td style="border:1px solid #ddd; padding:10px; text-align:left;"><?php echo htmlspecialchars($result['remarks'] ?? '-'); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p>No test results available.</p>
  <?php endif; ?>

  <div style="margin-top:20px; display:flex; flex-direction:column; gap:10px; align-items:center;">
    <button onclick="closeWindow()" class="btn btn-back" style="background:#6c757d; color:#fff; padding:8px 16px; border:none; border-radius:6px; cursor:pointer; text-decoration:none; display:inline-block;">Close Window</button>
    
    <?php 
    $isApprovalDashboard = isset($_GET['approval_dashboard']) && $_GET['approval_dashboard'] == '1';
    $reportNumber = isset($_GET['report_number']) ? $_GET['report_number'] : ($viewData['report_number'] ?? '');
    if ($isApprovalDashboard && isset($viewData) && $viewData['status'] === 'pending'): 
    ?>
    <div style="display:flex; gap:10px; margin-top:10px;">
      <button onclick="approveReport('<?php echo htmlspecialchars($reportNumber); ?>')" style="background:#27ae60; color:#fff; padding:10px 20px; border:none; border-radius:6px; cursor:pointer; font-size:14px; font-weight:bold;">
        <i class="fas fa-check"></i> Approve
      </button>
      <button onclick="rejectReport('<?php echo htmlspecialchars($reportNumber); ?>')" style="background:#e74c3c; color:#fff; padding:10px 20px; border:none; border-radius:6px; cursor:pointer; font-size:14px; font-weight:bold;">
        <i class="fas fa-times"></i> Reject
      </button>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($isApprovalDashboard && isset($viewData) && $viewData['status'] === 'pending'): ?>
  <!-- Fineness of Fiber Test Rejection Modal -->
  <div id="finenessFiberRejectModal" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); overflow-y:auto;">
      <div class="modal-content" style="background:#fff; margin:2% auto; padding:20px; border-radius:12px; width:90%; max-width:500px; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
          <span class="close" onclick="closeFinenessFiberRejectModal()" style="float:right; font-size:24px; font-weight:bold; cursor:pointer; color:#aaa; line-height:1;">&times;</span>
          <div class="modal-header" style="font-size:18px; font-weight:600; margin-bottom:12px; color:#e74c3c; flex-shrink:0;">
              <i class="fas fa-exclamation-triangle"></i> Reject Fineness of Fiber Test
          </div>
          
          <form method="POST" id="finenessFiberRejectForm" onsubmit="return submitFinenessFiberRejection(event)">
              <input type="hidden" name="fineness_fiber_report_number" id="fineness_fiber_report_number_input">
              <input type="hidden" name="fineness_fiber_action" value="rejected">
              
              <div class="modal-body" style="flex:1; overflow-y:auto; padding-right:5px;">
                  <div class="checkbox-group" style="margin:10px 0; max-height:200px; overflow-y:auto; padding:5px; border:1px solid #e0e0e0; border-radius:6px;">
                      <strong style="display:block; margin-bottom:8px; font-size:13px;">Rejection Reasons:</strong>
                      <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                          <input type="checkbox" name="fineness_fiber_rejection_reasons[]" value="Sample contamination" id="fineness_fiber_reason1" style="transform:scale(1.1); cursor:pointer;">
                          <label for="fineness_fiber_reason1" style="cursor:pointer; flex:1; font-size:12px;">Sample contamination</label>
                      </div>
                      <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                          <input type="checkbox" name="fineness_fiber_rejection_reasons[]" value="Incorrect test procedure (ISO 1973)" id="fineness_fiber_reason2" style="transform:scale(1.1); cursor:pointer;">
                          <label for="fineness_fiber_reason2" style="cursor:pointer; flex:1; font-size:12px;">Incorrect test procedure (ISO 1973)</label>
                      </div>
                      <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                          <input type="checkbox" name="fineness_fiber_rejection_reasons[]" value="Out of specification results" id="fineness_fiber_reason3" style="transform:scale(1.1); cursor:pointer;">
                          <label for="fineness_fiber_reason3" style="cursor:pointer; flex:1; font-size:12px;">Out of specification results</label>
                      </div>
                      <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                          <input type="checkbox" name="fineness_fiber_rejection_reasons[]" value="Incomplete test data" id="fineness_fiber_reason4" style="transform:scale(1.1); cursor:pointer;">
                          <label for="fineness_fiber_reason4" style="cursor:pointer; flex:1; font-size:12px;">Incomplete test data</label>
                      </div>
                      <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                          <input type="checkbox" name="fineness_fiber_rejection_reasons[]" value="Equipment calibration issue" id="fineness_fiber_reason5" style="transform:scale(1.1); cursor:pointer;">
                          <label for="fineness_fiber_reason5" style="cursor:pointer; flex:1; font-size:12px;">Equipment calibration issue</label>
                      </div>
                      <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                          <input type="checkbox" name="fineness_fiber_rejection_reasons[]" value="Documentation error" id="fineness_fiber_reason6" style="transform:scale(1.1); cursor:pointer;">
                          <label for="fineness_fiber_reason6" style="cursor:pointer; flex:1; font-size:12px;">Documentation error</label>
                      </div>
                      <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                          <input type="checkbox" name="fineness_fiber_rejection_reasons[]" value="Fiber quality issue" id="fineness_fiber_reason7" style="transform:scale(1.1); cursor:pointer;">
                          <label for="fineness_fiber_reason7" style="cursor:pointer; flex:1; font-size:12px;">Fiber quality issue</label>
                      </div>
                  </div>
                  
                  <textarea name="fineness_fiber_remarks" class="remarks-box" placeholder="Additional comments (optional)" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; margin-top:10px; min-height:80px;"></textarea>
              </div>
              
              <div class="modal-footer" style="flex-shrink:0; margin-top:15px; padding-top:15px; border-top:1px solid #ddd; text-align:right;">
                  <button type="button" onclick="closeFinenessFiberRejectModal()" style="padding:8px 18px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer; margin-right:8px; font-size:13px;">
                      Cancel
                  </button>
                  <button type="submit" style="padding:8px 18px; background:#e74c3c; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; font-size:13px;">
                      <i class="fas fa-ban"></i> Reject Report
                  </button>
              </div>
          </form>
      </div>
  </div>

  <script>
  function submitFinenessFiberRejection(e) {
      e.preventDefault();
      const checkboxes = document.querySelectorAll('input[name="fineness_fiber_rejection_reasons[]"]:checked');
      if (checkboxes.length === 0) {
          alert('Please select at least one rejection reason.');
          return false;
      }
      
      const form = e.target;
      const formData = new FormData(form);
      
      // Create form in current window and submit to dashboard
      const submitForm = document.createElement('form');
      submitForm.method = 'POST';
      submitForm.action = '../admin/raw_material_test_approval_dashboard.php';
      submitForm.style.display = 'none';
      
      // Add action and report number
      const actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'fineness_fiber_action';
      actionInput.value = 'rejected';
      submitForm.appendChild(actionInput);
      
      const reportInput = document.createElement('input');
      reportInput.type = 'hidden';
      reportInput.name = 'fineness_fiber_report_number';
      reportInput.value = document.getElementById('fineness_fiber_report_number_input').value;
      submitForm.appendChild(reportInput);
      
      // Add rejection reasons
      checkboxes.forEach(function(checkbox) {
          const reasonInput = document.createElement('input');
          reasonInput.type = 'hidden';
          reasonInput.name = 'fineness_fiber_rejection_reasons[]';
          reasonInput.value = checkbox.value;
          submitForm.appendChild(reasonInput);
      });
      
      // Add remarks if any
      const remarksField = form.querySelector('textarea[name="fineness_fiber_remarks"]');
      if (remarksField && remarksField.value.trim()) {
          const remarksInput = document.createElement('input');
          remarksInput.type = 'hidden';
          remarksInput.name = 'fineness_fiber_remarks';
          remarksInput.value = remarksField.value.trim();
          submitForm.appendChild(remarksInput);
      }
      
      document.body.appendChild(submitForm);
      submitForm.submit();
      
      // Refresh parent window after a short delay
      if (window.opener && !window.opener.closed) {
          setTimeout(function() {
              window.opener.location.reload();
              window.close();
          }, 500);
      }
      
      return false;
  }
  </script>
  <?php endif; ?>

  <script>
  function closeWindow() {
      if (window.opener) {
          window.close();
      } else {
          // Check for return parameter
          const urlParams = new URLSearchParams(window.location.search);
          const returnPage = urlParams.get('return');
          const isApprovalDashboard = urlParams.get('approval_dashboard') === '1';
          
          if (isApprovalDashboard) {
              window.location.href = '../admin/raw_material_test_approval_dashboard.php';
          } else if (returnPage === 'approved_material_inventory') {
              window.location.href = '../reports/approved_material_inventory.php';
          } else if (window.history.length > 1) {
              window.history.back();
          } else {
              window.location.href = '../index.php';
          }
      }
  }

  <?php if ($isApprovalDashboard && isset($viewData) && $viewData['status'] === 'pending'): ?>
  function approveReport(reportNumber) {
      if (confirm('Approve Fineness of Fiber Report ' + reportNumber + '?')) {
          // Create form in current window and submit to dashboard
          const form = document.createElement('form');
          form.method = 'POST';
          form.action = '../admin/raw_material_test_approval_dashboard.php';
          form.style.display = 'none';
          
          const actionInput = document.createElement('input');
          actionInput.type = 'hidden';
          actionInput.name = 'fineness_fiber_action';
          actionInput.value = 'approved';
          form.appendChild(actionInput);
          
          const reportInput = document.createElement('input');
          reportInput.type = 'hidden';
          reportInput.name = 'fineness_fiber_report_number';
          reportInput.value = reportNumber;
          form.appendChild(reportInput);
          
          document.body.appendChild(form);
          
          // Submit form and then refresh parent window
          form.submit();
          
          // Refresh parent window after a short delay
          if (window.opener && !window.opener.closed) {
              setTimeout(function() {
                  window.opener.location.reload();
                  window.close();
              }, 500);
          }
      }
  }

  function rejectReport(reportNumber) {
      document.getElementById('fineness_fiber_report_number_input').value = reportNumber;
      document.getElementById('finenessFiberRejectModal').style.display = 'block';
  }

  function closeFinenessFiberRejectModal() {
      document.getElementById('finenessFiberRejectModal').style.display = 'none';
      document.getElementById('finenessFiberRejectForm').reset();
  }
  <?php endif; ?>

  document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape') {
          closeWindow();
      }
  });
  </script>
  <?php elseif ($editMode): ?>
  <div class="alert alert-warning" style="background:#fff3cd;color:#856404;padding:12px;border-radius:6px;border:1px solid #ffc107;margin-bottom:15px;">
    <strong>⚠️ Report Rejected:</strong> This report was rejected by <strong><?php echo htmlspecialchars($editData['approved_by'] ?? 'Admin'); ?></strong><br>
    <strong>Reason:</strong> <?php echo htmlspecialchars($editData['remarks'] ?? 'No comments provided'); ?><br>
    <strong>Original Report Number:</strong> <?php echo htmlspecialchars($editData['report_number']); ?>
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

  <?php if (!$viewMode): ?>
  <!-- Back to Dashboard Link -->
  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
  </div>

  <?php if ($showPendingQueue): ?>
  <!-- Pending Approval Queue -->
    <?php if (!empty($pending_reports)): ?>
    <div style="margin-top:16px; padding:12px; border:1px solid #ddd; border-radius:8px; background:#fff;">
      <h3 style="margin:0 0 12px 0;">Pending Reports</h3>
      <table class="test-table">
        <thead>
          <tr>
            <th>Report No</th>
            <th>Material</th>
            <th>Tested By</th>
            <th>Last Updated</th>
            <th style="width:200px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending_reports as $pr): ?>
          <tr>
            <td><?php echo htmlspecialchars($pr['report_number']); ?></td>
            <td><?php echo htmlspecialchars($pr['material']); ?></td>
            <td><?php echo htmlspecialchars($pr['tested_by']); ?></td>
            <td><?php echo htmlspecialchars($pr['updated_at']); ?></td>
            <td>
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

  <?php if (!$FinenessFiberHandler->canApproveReports() && !$editMode): ?>
  <!-- Rejected Reports for Tester to Review - Only show when NOT in edit mode -->
  <?php $rejected = $FinenessFiberHandler->getRejectedReportsForUser($reporter_id); if (!empty($rejected)): ?>
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
          <td><?php echo htmlspecialchars($rj['sample_description']); ?></td>
          <td><?php echo htmlspecialchars($rj['approved_by'] ?? 'N/A'); ?></td>
          <td style="text-align:left; max-width:300px; color:#721c24; font-weight:600;"><?php echo htmlspecialchars($rj['remarks'] ?? 'No comments'); ?></td>
          <td><?php echo htmlspecialchars($rj['created_at']); ?></td>
          <td>
            <a href="fineness_fiber_report.php?id=<?php echo $rj['id']; ?>" class="submit-btn" style="padding:6px 10px; text-decoration:none; display:inline-block; background:#f39c12; color:#fff;">Edit & Resubmit</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin:12px 0 0 0; color:#856404; font-style:italic;">💡 Note: Click "Edit & Resubmit" to pre-fill and modify the rejected report.</p>
  </div>
  <?php endif; ?>
  <?php endif; ?>
  <?php endif; ?>

  <?php if (!$viewMode): ?>
  <form method="POST" action="" id="finenessForm" onsubmit="return validateTestDate();">
    <?php if ($editMode): ?>
    <input type="hidden" name="report_id" value="<?php echo $editReportId; ?>">
    <?php endif; ?>
    
    <!-- Date/Time and Shift Display -->
    <div id="dateTimeDisplay" class="summary-info"></div>
    <div id="shiftBanner" class="summary-info"></div>

    <!-- Hidden fields -->
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">
    
    <?php
    // Decode test results for pre-filling
    $existing_test_results = [];
    if ($editMode && !empty($editData['test_results'])) {
        $decoded = json_decode($editData['test_results'], true);
        if (is_array($decoded)) {
            foreach ($decoded as $result) {
                $existing_test_results[$result['sl_no']] = $result;
            }
        }
    }
    ?>

    <!-- Sample Information -->
    <div class="form-row">
      <div class="form-group">
        <label>Report Number:</label>
        <input type="text" name="report_number" id="report_number" value="<?php echo htmlspecialchars($generated_report_number); ?>" readonly class="readonly">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Sample Name:</label>
        <input type="text" name="sample_description" value="<?php 
          if ($editMode) {
              echo htmlspecialchars($editData['sample_description'] ?? '');
          } elseif ($lastSubmittedData) {
              echo htmlspecialchars($lastSubmittedData['sample_description'] ?? '');
          }
        ?>">
      </div>
      <div class="form-group">
        <label>LC No:</label>
        <input type="text" name="lc_no" value="<?php 
          if ($editMode) {
              echo htmlspecialchars($editData['lc_no'] ?? '');
          } elseif ($lastSubmittedData) {
              echo htmlspecialchars($lastSubmittedData['lc_no'] ?? '');
          }
        ?>">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Store Entry Reference: <span style="color: #e74c3c;">*</span></label>
        <select name="store_entry_reference" required>
          <option value="">-- Select Store Entry Reference --</option>
          <?php foreach ($storeEntries as $entry): ?>
            <option value="<?php echo htmlspecialchars($entry['entry_number']); ?>" 
              data-manufacturer="<?php echo htmlspecialchars($entry['manufacturer_name'] ?? ''); ?>"
              <?php 
                $storeSelected = false;
                if ($editMode && $editData['store_entry_reference'] === $entry['entry_number']) {
                    $storeSelected = true;
                } elseif (!$editMode && $lastSubmittedData && $lastSubmittedData['store_entry_reference'] === $entry['entry_number']) {
                    $storeSelected = true;
                }
                echo $storeSelected ? 'selected' : '';
              ?>>
              <?php echo htmlspecialchars($entry['entry_number']); ?>
            </option>
          <?php endforeach; ?>
          <?php if ($editMode && !empty($editData['store_entry_reference'])): ?>
            <?php
            // Check if the store entry is not in the dropdown (already used)
            $store_found = false;
            foreach ($storeEntries as $entry) {
                if ($entry['entry_number'] === $editData['store_entry_reference']) {
                    $store_found = true;
                    break;
                }
            }
            if (!$store_found):
            ?>
            <option value="<?php echo htmlspecialchars($editData['store_entry_reference']); ?>" selected>
              <?php echo htmlspecialchars($editData['store_entry_reference']); ?> (Previously Selected)
            </option>
            <?php endif; ?>
          <?php endif; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Manufacturer Name:</label>
        <select name="manufacturer_name">
          <option value="">-- Select Manufacturer --</option>
          <?php foreach ($manufacturerNames as $mfr): ?>
            <option value="<?php echo htmlspecialchars($mfr); ?>" 
              <?php 
                $manufacturerSelected = false;
                if ($editMode && isset($editData['manufacturer_name']) && $editData['manufacturer_name'] === $mfr) {
                    $manufacturerSelected = true;
                } elseif (!$editMode && $lastSubmittedData && isset($lastSubmittedData['manufacturer_name']) && $lastSubmittedData['manufacturer_name'] === $mfr) {
                    $manufacturerSelected = true;
                }
                echo $manufacturerSelected ? 'selected' : '';
              ?>>
              <?php echo htmlspecialchars($mfr); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Sample Received Date:</label>
        <input type="date" name="received_date" id="received_date" value="<?php 
          if ($editMode && !empty($editData['received_date'])) {
              echo date('Y-m-d', strtotime($editData['received_date']));
          } elseif ($lastSubmittedData && !empty($lastSubmittedData['received_date'])) {
              echo date('Y-m-d', strtotime($lastSubmittedData['received_date']));
          }
        ?>" onchange="validateTestDate()">
      </div>
      <div class="form-group">
        <label>Sample Tested:</label>
        <input type="date" name="test_start_date" id="test_start_date" value="<?php 
          if ($editMode) {
              echo htmlspecialchars($editData['test_start_date'] ?? '');
          } elseif ($lastSubmittedData) {
              echo htmlspecialchars($lastSubmittedData['test_start_date'] ?? '');
          }
        ?>" onchange="validateTestDate()">
        <small id="date_error" style="color: #e74c3c; display: none;">Sample Tested date cannot be earlier than Sample Received Date.</small>
      </div>
    </div>

    <!-- Hidden fields for compatibility with database structure -->
    <input type="hidden" name="test_end_date" value="<?php echo $editMode && !empty($editData['test_start_date']) ? htmlspecialchars($editData['test_start_date']) : ''; ?>">
    <input type="hidden" name="rh_percent" value="0">

    <?php if (!in_array($roleLower, ['admin', 'agm ops', 'agm operations'])): ?>
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
        <?php
        $existing_result = $existing_test_results[$sl_no] ?? null;
        $existing_unit = $existing_result['unit'] ?? $param['unit'];
        $existing_value = $existing_result['test_result'] ?? '';
        $existing_remarks = $existing_result['remarks'] ?? '';
        ?>
        <tr>
          <td><?php echo $sl_no; ?></td>
          <td><?php echo htmlspecialchars($param['param']); ?></td>
          <td><?php echo htmlspecialchars($param['standards'][0]); ?></td>
          <td>
            <select name="test_results[<?php echo $sl_no; ?>][unit]" required>
              <option value="">Select Unit</option>
              <?php foreach ($unit_options as $unit): ?>
              <option value="<?php echo htmlspecialchars($unit); ?>" <?php echo ($unit === $existing_unit) ? 'selected' : ''; ?>><?php echo htmlspecialchars($unit); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <input type="number" step="0.01" name="test_results[<?php echo $sl_no; ?>][result]" value="<?php echo htmlspecialchars($existing_value); ?>">
          </td>
          <td><input type="text" name="test_results[<?php echo $sl_no; ?>][remarks]" value="<?php echo htmlspecialchars($existing_remarks); ?>"></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Approved By -->
    <?php if (in_array($roleLower, ['admin', 'agm ops', 'agm operations'])): ?>
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
</div>

<script>
// Initialize time display
document.addEventListener('DOMContentLoaded', function() {
    updateTimeAndShift();
    
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const currentDateTime = `${year}-${month}-${day}T${hours}:${minutes}`;
    
    document.querySelector('input[name="received_date"]').value = currentDateTime;

    const startInput = document.querySelector('input[name="test_start_date"]');
    const endInput = document.querySelector('input[name="test_end_date"]');
    const formEl = document.querySelector('form');

    function isEndBeforeStart() {
        const startVal = startInput?.value || '';
        const endVal = endInput?.value || '';
        if (!startVal || !endVal) return false;
        const start = new Date(startVal);
        const end = new Date(endVal);
        return end < start;
    }

    function validateDatesOrAlert() {
        if (isEndBeforeStart()) {
            alert('Test End Date cannot be earlier than Test Start Date. Please correct the dates.');
            endInput.focus();
            return false;
        }
        return true;
    }

    startInput?.addEventListener('change', validateDatesOrAlert);
    endInput?.addEventListener('change', validateDatesOrAlert);

    formEl?.addEventListener('submit', function(e) {
        // Validate dates before submission
        if (!validateTestDate()) {
            e.preventDefault();
            alert('Please correct the date: Sample Tested date cannot be earlier than Sample Received Date.');
            return false;
        }
        if (!validateDatesOrAlert()) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
        // If all validations pass, allow form to submit
        // Don't prevent default - let the form submit normally
    });
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

function clearForm() {
    if (confirm('Are you sure you want to clear all form data?')) {
        document.querySelector('form').reset();
        document.querySelector('input[name="test_performed_by"]').value = '<?php echo htmlspecialchars($reporter_full_name); ?>';
        document.querySelector('input[name="report_number"]').value = '<?php echo htmlspecialchars($generated_report_number); ?>';
        
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const currentDateTime = `${year}-${month}-${day}T${hours}:${minutes}`;
        
        document.querySelector('input[name="received_date"]').value = currentDateTime;
    }
}

function confirmApproval(form) {
    form.submit();
    setTimeout(function() {
        window.location.reload();
    }, 500);
    return true;
}

function openRejectModal(reportNumber) {
    const comment = prompt('Enter rejection reason:');
    if (comment !== null && comment.trim() !== '') {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="wf_report_number" value="${reportNumber}">
            <input type="hidden" name="wf_action" value="rejected">
            <input type="hidden" name="wf_comment" value="${comment}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Validate that Sample Tested date is not earlier than Sample Received Date
function validateTestDate() {
    const receivedDate = document.getElementById('received_date');
    const testDate = document.getElementById('test_start_date');
    const errorMsg = document.getElementById('date_error');
    
    if (receivedDate && testDate && receivedDate.value && testDate.value) {
        // Normalize dates to compare only date part (same date is allowed)
        const received = new Date(receivedDate.value + 'T00:00:00');
        const tested = new Date(testDate.value + 'T00:00:00');
        
        if (tested < received) {
            errorMsg.style.display = 'block';
            testDate.setCustomValidity('Sample Tested date cannot be earlier than Sample Received Date.');
            return false;
        } else {
            errorMsg.style.display = 'none';
            testDate.setCustomValidity('');
            return true;
        }
    }
    return true;
}

// Auto-select manufacturer name when store entry reference is selected
document.addEventListener('DOMContentLoaded', function() {
    const storeEntrySelect = document.querySelector('select[name="store_entry_reference"]');
    const manufacturerSelect = document.querySelector('select[name="manufacturer_name"]');
    
    if (storeEntrySelect && manufacturerSelect) {
        // Function to auto-select manufacturer
        function autoSelectManufacturer() {
            const selectedOption = storeEntrySelect.options[storeEntrySelect.selectedIndex];
            const manufacturerName = selectedOption.getAttribute('data-manufacturer');
            
            if (manufacturerName && manufacturerName.trim() !== '') {
                // Find and select the matching manufacturer option
                for (let i = 0; i < manufacturerSelect.options.length; i++) {
                    if (manufacturerSelect.options[i].value === manufacturerName) {
                        manufacturerSelect.selectedIndex = i;
                        break;
                    }
                }
            }
        }
        
        // Auto-select on change
        storeEntrySelect.addEventListener('change', autoSelectManufacturer);
        
        // Auto-select on page load if store entry is already selected (edit mode)
        if (storeEntrySelect.selectedIndex > 0) {
            autoSelectManufacturer();
        }
    }
});
</script>
</body>
</html>


