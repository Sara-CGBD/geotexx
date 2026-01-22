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

// Fetch store received entries for reference dropdown (exclude only if tenacity yarn test already done)
$storeEntries = [];
try {
    // Use NOT EXISTS for more reliable exclusion of submitted references
    $storeQuery = $conn->query("SELECT DISTINCT sre.entry_number, sre.material_type, sre.amount_kg, sre.date_time as received_date 
                                 FROM store_received_entries sre
                                 WHERE sre.entry_number IS NOT NULL 
                                 AND sre.entry_number != ''
                                 AND TRIM(sre.entry_number) != ''
                                 AND NOT EXISTS (
                                     SELECT 1 
                                     FROM tenacity_yarn_reports tyr 
                                     WHERE tyr.store_entry_reference IS NOT NULL
                                     AND tyr.store_entry_reference != ''
                                     AND (
                                         tyr.store_entry_reference = sre.entry_number
                                         OR FIND_IN_SET(sre.entry_number, tyr.store_entry_reference) > 0
                                         OR FIND_IN_SET(TRIM(sre.entry_number), TRIM(tyr.store_entry_reference)) > 0
                                     )
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
    error_log("Error fetching store entries for tenacity yarn test: " . $e->getMessage());
}

$message = '';
$error = '';

// Check for success message from session (after redirect)
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Enhanced backend functionality for Tenacity of Yarn Report
class TenacityYarnReportHandler {
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
            
            $pattern = 'TYR-' . $dayKey . '-%';
            $countStmt = $this->conn->prepare("SELECT COUNT(*) as report_count FROM tenacity_yarn_reports WHERE report_number LIKE ?");
            $countStmt->bind_param("s", $pattern);
            $countStmt->execute();
            $result = $countStmt->get_result();
            
            if ($result && $row = $result->fetch_assoc()) {
                $counter = (int)$row['report_count'] + 1;
            } else {
                $counter = 1;
            }
            
            $countStmt->close();
            return "TYR-{$dayKey}-" . str_pad($counter, 5, '0', STR_PAD_LEFT);
            
        } catch (Exception $e) {
            error_log("Tenacity Yarn Report - Get next number error: " . $e->getMessage());
            return "TYR-" . date('YmdHis') . "-" . rand(1000, 9999);
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
            
            $pattern = 'TYR-' . $dayKey . '-%';
            $countStmt = $this->conn->prepare("SELECT COUNT(*) as report_count FROM tenacity_yarn_reports WHERE report_number LIKE ?");
            $countStmt->bind_param("s", $pattern);
            $countStmt->execute();
            $result = $countStmt->get_result();
            
            $counter = 1;
            if ($result && $row = $result->fetch_assoc()) {
                $counter = (int)$row['report_count'] + 1;
            }
            $countStmt->close();
            
            return "TYR-{$dayKey}-" . str_pad($counter, 5, '0', STR_PAD_LEFT);
            
        } catch (Exception $e) {
            error_log("Error generating report number: " . $e->getMessage());
            return "TYR-" . date('YmdHis') . "-" . rand(1000, 9999);
        }
    }
    
    // Enhanced data validation
    public function validateFormData($data) {
        $errors = [];
        
        $required_fields = [
            'report_number' => 'Report Number',
            'sample_description' => 'Sample Description',
            'sample_received_from' => 'Sample Received From',
            'sample_collected_from' => 'Sample Collected From',
            'received_date' => 'Received Date',
            'test_start_date' => 'Test Start Date',
            'test_end_date' => 'Test End Date',
            'test_temperature' => 'Test Temperature',
            'rh_percent' => 'RH%',
            'test_performed_by' => 'Test Performed By',
        ];
        
        foreach ($required_fields as $field => $label) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                $errors[] = "$label is required.";
            }
        }
        
        // Date validation: Test End Date cannot be earlier than Test Start Date
        if (!empty($data['test_start_date']) && !empty($data['test_end_date'])) {
            $start_date = new DateTime($data['test_start_date']);
            $end_date = new DateTime($data['test_end_date']);
            if ($end_date < $start_date) {
                $errors[] = "Test End Date cannot be earlier than Test Start Date.";
            }
        }
        
        // Temperature validation
        if (!empty($data['test_temperature'])) {
            $temp = floatval($data['test_temperature']);
            if ($temp < 0 || $temp > 1000) {
                $errors[] = "Test temperature must be between 0 and 1000°C.";
            }
        }
        
        // RH validation
        if (!empty($data['rh_percent'])) {
            $rh = floatval($data['rh_percent']);
            if ($rh < 0 || $rh > 100) {
                $errors[] = "RH% must be between 0 and 100%.";
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
            $sample_description = $this->sanitizeInput($data['sample_description']);
            $sample_received_from = $this->sanitizeInput($data['sample_received_from']);
            $sample_collected_from = $this->sanitizeInput($data['sample_collected_from']);
            $reference = $this->sanitizeInput($data['reference'] ?? '');
            $received_date = $this->sanitizeInput($data['received_date']);
            $test_start_date = $this->sanitizeInput($data['test_start_date']);
            $test_end_date = $this->sanitizeInput($data['test_end_date']);
            $others_information = $this->sanitizeInput($data['others_information'] ?? '');
            $test_temperature = floatval($data['test_temperature']);
            $rh_percent = floatval($data['rh_percent']);
            $test_performed_by = $this->sanitizeInput($data['test_performed_by']);
            $approved_by = isset($data['approved_by']) ? $this->sanitizeInput($data['approved_by']) : null;
            
            // Create enhanced table structure FIRST
            $createTable = "CREATE TABLE IF NOT EXISTS tenacity_yarn_reports (
                id INT AUTO_INCREMENT PRIMARY KEY,
                report_number VARCHAR(100) UNIQUE NOT NULL,
                store_entry_reference VARCHAR(100) NULL,
                sample_description TEXT NOT NULL,
                sample_received_from VARCHAR(255) NOT NULL,
                sample_collected_from VARCHAR(255) NOT NULL,
                reference VARCHAR(255) NULL,
                received_date DATETIME NOT NULL,
                test_start_date DATE NOT NULL,
                test_end_date DATE NOT NULL,
                others_information TEXT,
                test_temperature DECIMAL(10,2) NOT NULL,
                rh_percent DECIMAL(5,2) NOT NULL,
                test_performed_by VARCHAR(100) NOT NULL,
                approved_by VARCHAR(100) NULL,
                test_results JSON,
                reporter_id INT NOT NULL,
                reporter_name VARCHAR(255) NOT NULL,
                status ENUM('pending','approved','rejected') DEFAULT 'pending',
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
            
            // Add column if it doesn't exist
            $this->conn->query("ALTER TABLE tenacity_yarn_reports ADD COLUMN IF NOT EXISTS store_entry_reference VARCHAR(100) NULL AFTER report_number");
            @$this->conn->query("ALTER TABLE tenacity_yarn_reports MODIFY approved_by VARCHAR(100) NULL");
            @$this->conn->query("ALTER TABLE tenacity_yarn_reports MODIFY reference VARCHAR(255) NULL");

            // Check for duplicate report number
            $duplicateCheck = $this->conn->prepare("SELECT id FROM tenacity_yarn_reports WHERE report_number = ?");
            $duplicateCheck->bind_param("s", $report_number);
            $duplicateCheck->execute();
            if ($duplicateCheck->get_result()->num_rows > 0) {
                throw new Exception("Report number already exists. Please refresh the page to get a new report number.");
            }
            $duplicateCheck->close();
            
            // Prepare test results JSON - filter out Denier entries
            $test_results_processed = [];
            if (isset($data['test_results']) && is_array($data['test_results'])) {
                // Define parameter names for processing (matching the form's test_parameters)
                $param_names = [
                    1 => 'Tenacity at Break',
                    2 => 'Std Deviation',
                    3 => 'CV%',
                    4 => 'Elongation at Break'
                ];
                
                foreach ($data['test_results'] as $sl_no => $result) {
                    // Skip Denier entries (sl_no 1 should be Tenacity at Break, not Denier)
                    $param_name = $param_names[$sl_no] ?? '';
                    if (strtolower(trim($param_name)) === 'denier') {
                        continue; // Skip Denier entries
                    }
                    
                    $test_results_processed[] = [
                        'sl_no' => $sl_no,
                        'parameter' => $param_name,
                        'unit' => $result['unit'] ?? '',
                        'test_result' => $result['result'] ?? '',
                        'remarks' => $result['remarks'] ?? ''
                    ];
                }
            }
            $test_results_json = json_encode($test_results_processed, JSON_UNESCAPED_UNICODE);
            
            // Insert with enhanced error handling
            $stmt = $this->conn->prepare(
                "INSERT INTO tenacity_yarn_reports (
                    report_number, store_entry_reference, sample_description, sample_received_from, sample_collected_from, reference,
                    received_date, test_start_date, test_end_date, others_information, 
                    test_temperature, rh_percent, test_performed_by, approved_by, 
                    test_results, reporter_id, reporter_name, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            // Auto-approve if submitted by admin or AGM Ops
            $user_role = strtolower(trim($_SESSION['role'] ?? ''));
            $status = ($user_role === 'admin' || $user_role === 'agm ops' || $user_role === 'agm operations') ? 'approved' : 'pending';
            
            $stmt->bind_param("ssssssssssddsssiss", 
                $report_number, $data['store_entry_reference'], $sample_description, $sample_received_from, $sample_collected_from, $reference,
                $received_date, $test_start_date, $test_end_date, $others_information,
                $test_temperature, $rh_percent, $test_performed_by, $approved_by,
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
                'message' => "Tenacity of Yarn Report saved successfully! Report Number: $report_number",
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
        
        $stmt = $this->conn->prepare(
            "SELECT id, report_number, sample_description, status, remarks, created_at, approved_by
             FROM tenacity_yarn_reports 
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
        $check = $this->conn->query("SHOW COLUMNS FROM tenacity_yarn_reports LIKE 'remarks'");
        if ($check && $check->num_rows == 0) {
            $this->conn->query("ALTER TABLE tenacity_yarn_reports ADD COLUMN remarks TEXT NULL AFTER approved_by");
        }
    }
    
    public function canApproveReports() {
        $user_role = strtolower(trim($_SESSION['role'] ?? ''));
        return in_array($user_role, ['admin', 'agm ops', 'agm operations', 'management']);
    }
    
    // Ensure table exists before querying
    // Clean up Denier entries from existing records
    public function cleanupDenierEntries() {
        $this->ensureTableExists();
        
        try {
            // Get all records with test_results
            $result = $this->conn->query("SELECT id, test_results FROM tenacity_yarn_reports WHERE test_results IS NOT NULL AND test_results != ''");
            if ($result) {
                $updated_count = 0;
                while ($row = $result->fetch_assoc()) {
                    $test_results = json_decode($row['test_results'], true);
                    if (!is_array($test_results)) {
                        continue;
                    }
                    
                    // Filter out Denier entries (check both 'parameter' and 'param' fields)
                    $cleaned_results = [];
                    $needs_update = false;
                    foreach ($test_results as $result_item) {
                        $param_name = '';
                        if (isset($result_item['parameter'])) {
                            $param_name = strtolower(trim($result_item['parameter']));
                        } elseif (isset($result_item['param'])) {
                            $param_name = strtolower(trim($result_item['param']));
                        }
                        
                        if ($param_name === 'denier') {
                            $needs_update = true;
                            continue; // Skip Denier entries
                        }
                        $cleaned_results[] = $result_item;
                    }
                    
                    // Update if Denier was found and removed
                    if ($needs_update) {
                        $cleaned_json = json_encode($cleaned_results, JSON_UNESCAPED_UNICODE);
                        $updateStmt = $this->conn->prepare("UPDATE tenacity_yarn_reports SET test_results = ? WHERE id = ?");
                        $updateStmt->bind_param("si", $cleaned_json, $row['id']);
                        $updateStmt->execute();
                        $updateStmt->close();
                        $updated_count++;
                    }
                }
                if ($updated_count > 0) {
                    error_log("Tenacity Yarn Report: Cleaned up Denier entries from $updated_count records");
                }
            }
        } catch (Exception $e) {
            error_log("Error cleaning up Denier entries: " . $e->getMessage());
        }
    }
    
    public function ensureTableExists() {
        $createTable = "CREATE TABLE IF NOT EXISTS tenacity_yarn_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_number VARCHAR(100) UNIQUE NOT NULL,
            store_entry_reference VARCHAR(100) NULL,
            sample_description TEXT NOT NULL,
            sample_received_from VARCHAR(255) NOT NULL,
            sample_collected_from VARCHAR(255) NOT NULL,
            reference VARCHAR(255) NULL,
            received_date DATETIME NOT NULL,
            test_start_date DATE NOT NULL,
            test_end_date DATE NOT NULL,
            others_information TEXT,
            test_temperature DECIMAL(10,2) NOT NULL,
            rh_percent DECIMAL(5,2) NOT NULL,
            test_performed_by VARCHAR(100) NOT NULL,
            approved_by VARCHAR(100) NULL,
            test_results JSON,
            reporter_id INT NOT NULL,
            reporter_name VARCHAR(255) NOT NULL,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
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
        
        // Add columns if they don't exist
        @$this->conn->query("ALTER TABLE tenacity_yarn_reports ADD COLUMN IF NOT EXISTS store_entry_reference VARCHAR(100) NULL AFTER report_number");
        @$this->conn->query("ALTER TABLE tenacity_yarn_reports MODIFY approved_by VARCHAR(100) NULL");
        @$this->conn->query("ALTER TABLE tenacity_yarn_reports MODIFY reference VARCHAR(255) NULL");
        @$this->conn->query("ALTER TABLE tenacity_yarn_reports ADD COLUMN IF NOT EXISTS remarks TEXT NULL AFTER approved_by");
    }
    
    public function getPendingReports($limit = 20) {
        $this->ensureTableExists();
        
        $stmt = $this->conn->prepare(
            "SELECT id, report_number, sample_received_from AS client, sample_description AS material, test_performed_by AS tested_by, status, created_at, updated_at
             FROM tenacity_yarn_reports WHERE status = 'pending' ORDER BY updated_at DESC LIMIT ?"
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
        $stmt = $this->conn->prepare("UPDATE tenacity_yarn_reports SET status = ?, approved_by = ?, remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE report_number = ?");
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
            $result = $this->conn->query("SELECT status, COUNT(*) as count FROM tenacity_yarn_reports GROUP BY status");
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
$tenacityYarnHandler = new TenacityYarnReportHandler($conn, $reporter_id, $reporter_name, $reporter_full_name);
$tenacityYarnHandler->ensureTableExists();
// Clean up any Denier entries from existing records
$tenacityYarnHandler->cleanupDenierEntries();

// Fetch pending reports for admin/AGM Ops
$pending_reports = [];
if ($tenacityYarnHandler->canApproveReports()) {
    $pending_reports = $tenacityYarnHandler->getPendingReports(20);
}

// Check if viewing a report (read-only mode)
$viewMode = false;
$viewData = null;
$viewReportId = null;

if (isset($_GET['view']) && !empty($_GET['view'])) {
    $viewReportId = (int)$_GET['view'];
    $tenacityYarnHandler->ensureTableExists();
    
    $stmt = $conn->prepare("SELECT * FROM tenacity_yarn_reports WHERE id = ?");
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
    $tenacityYarnHandler->ensureTableExists();
    
    $stmt = $conn->prepare("SELECT * FROM tenacity_yarn_reports WHERE id = ? AND reporter_id = ?");
    $stmt->bind_param("ii", $editReportId, $reporter_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $editData = $result->fetch_assoc();
        
        // Clean up Denier from this specific record if present
        if (!empty($editData['test_results'])) {
            $test_results = json_decode($editData['test_results'], true);
            if (is_array($test_results)) {
                $cleaned_results = [];
                $needs_update = false;
                foreach ($test_results as $result_item) {
                    $param_name = '';
                    if (isset($result_item['parameter'])) {
                        $param_name = strtolower(trim($result_item['parameter']));
                    } elseif (isset($result_item['param'])) {
                        $param_name = strtolower(trim($result_item['param']));
                    }
                    
                    if ($param_name === 'denier') {
                        $needs_update = true;
                        continue; // Skip Denier entries
                    }
                    $cleaned_results[] = $result_item;
                }
                
                if ($needs_update) {
                    $cleaned_json = json_encode($cleaned_results, JSON_UNESCAPED_UNICODE);
                    $updateStmt = $conn->prepare("UPDATE tenacity_yarn_reports SET test_results = ? WHERE id = ?");
                    $updateStmt->bind_param("si", $cleaned_json, $editReportId);
                    $updateStmt->execute();
                    $updateStmt->close();
                    // Reload the data after cleanup
                    $editData['test_results'] = $cleaned_json;
                }
            }
        }
        
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
    $stmt = $conn->prepare("SELECT * FROM tenacity_yarn_reports WHERE reporter_id = ? ORDER BY created_at DESC LIMIT 1");
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
    $generated_report_number = $tenacityYarnHandler->getNextReportNumber();
    
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
    $tenacityYarnHandler->ensureTableExists();
    $pattern_check = 'TYR-' . $dayKey_check . '-%';
    $fresh_count = $conn->query("SELECT COUNT(*) as cnt FROM tenacity_yarn_reports WHERE report_number LIKE '{$pattern_check}'");
    if ($fresh_count && $row_check = $fresh_count->fetch_assoc()) {
        $next_num = (int)$row_check['cnt'] + 1;
        $generated_report_number = "TYR-{$dayKey_check}-" . str_pad($next_num, 5, '0', STR_PAD_LEFT);
    }
}

// Test parameters with their standards and units (define early for use in form submission)
$test_parameters = [
    1 => ['param' => 'Tenacity at Break', 'standards' => ['ASTM D2256'], 'unit' => 'cN/dTex'],
    2 => ['param' => 'Std Deviation', 'standards' => ['ASTM D2256'], 'unit' => 'cN/dTex'],
    3 => ['param' => 'CV%', 'standards' => ['ASTM D2256'], 'unit' => '%'],
    4 => ['param' => 'Elongation at Break', 'standards' => ['ASTM D2256'], 'unit' => '%']
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    $user_role = strtolower(trim($_SESSION['role'] ?? ''));
    
    // Check if updating an existing report
    if ($editMode && isset($_POST['report_id'])) {
        $report_id = (int)$_POST['report_id'];
        $tenacityYarnHandler->ensureTableExists();
        
        // Decode existing test results
        $test_results = json_decode($editData['test_results'] ?? '[]', true) ?? [];
        $updated_results = [];
        
        // Prepare updated test results from form - filter out Denier entries
        if (isset($_POST['test_results']) && is_array($_POST['test_results'])) {
            foreach ($_POST['test_results'] as $sl_no => $result) {
                // Skip Denier entries - filter out any Denier parameters
                $param_name = $test_parameters[$sl_no]['param'] ?? '';
                if (strtolower(trim($param_name)) === 'denier') {
                    continue; // Skip Denier entries
                }
                
                $updated_results[] = [
                    'sl_no' => $sl_no,
                    'parameter' => $param_name,
                    'unit' => $result['unit'] ?? '',
                    'test_result' => $result['result'] ?? '',
                    'remarks' => $result['remarks'] ?? ''
                ];
            }
        }
        $test_results_json = json_encode($updated_results, JSON_UNESCAPED_UNICODE);
        
        // Determine status - auto-approve if admin/AGM, otherwise pending
        $status = (in_array($user_role, ['admin', 'agm ops', 'agm operations'])) ? 'approved' : 'pending';
        $approved_by = (in_array($user_role, ['admin', 'agm ops', 'agm operations'])) ? $reporter_full_name : null;
        
        // Update the report - change status from 'rejected' to 'pending' when resubmitted
        $updateStmt = $conn->prepare("
            UPDATE tenacity_yarn_reports 
            SET store_entry_reference = ?, sample_description = ?, sample_received_from = ?, sample_collected_from = ?,
                reference = ?, received_date = ?, test_start_date = ?, test_end_date = ?,
                others_information = ?, test_temperature = ?, rh_percent = ?,
                test_results = ?, status = ?, approved_by = ?, remarks = NULL, updated_at = CURRENT_TIMESTAMP
            WHERE id = ? AND reporter_id = ?
        ");
        
        $store_entry_ref = $_POST['store_entry_reference'] ?? null;
        $sample_desc = $_POST['sample_description'];
        $sample_received = $_POST['sample_received_from'];
        $sample_collected = $_POST['sample_collected_from'];
        $reference_val = $_POST['reference'] ?? '';
        $received_date_val = $_POST['received_date'];
        $test_start_date_val = $_POST['test_start_date'];
        $test_end_date_val = $_POST['test_end_date'];
        $others_info = $_POST['others_information'] ?? '';
        $test_temp = floatval($_POST['test_temperature']);
        $rh_percent_val = floatval($_POST['rh_percent']);
        
        $updateStmt->bind_param(
            "ssssssssssdsssii",
            $store_entry_ref,
            $sample_desc,
            $sample_received,
            $sample_collected,
            $reference_val,
            $received_date_val,
            $test_start_date_val,
            $test_end_date_val,
            $others_info,
            $test_temp,
            $rh_percent_val,
            $test_results_json,
            $status,
            $approved_by,
            $report_id,
            $reporter_id
        );
        
        if ($updateStmt->execute()) {
            if (in_array($user_role, ['admin', 'agm ops', 'agm operations'])) {
                $message = "Tenacity of Yarn Report updated and auto-approved! Report Number: " . $editData['report_number'];
            } else {
                $message = "Tenacity of Yarn Report updated and resubmitted! Report Number: " . $editData['report_number'] . " - Status: Pending Approval";
            }
            $_SESSION['success_message'] = $message;
            header("Location: tenacity_yarn_report.php?success=1&t=" . time());
            exit();
        } else {
            $error = "Error updating report: " . $updateStmt->error;
        }
        $updateStmt->close();
    } else {
        // Create new report
        $actual_report_number = $tenacityYarnHandler->generateAndIncrementReportNumber();
        $_POST['report_number'] = $actual_report_number;
        
        $result = $tenacityYarnHandler->saveReport($_POST);
        if ($result['success']) {
            if (in_array($user_role, ['admin', 'agm ops', 'agm operations'])) {
                $message = "Tenacity of Yarn Report saved and auto-approved! Report Number: " . $actual_report_number;
            } else {
                $message = "Tenacity of Yarn Report submitted successfully! Report Number: " . $actual_report_number . " - Status: Pending Approval";
            }
            
            $_SESSION['success_message'] = $message;
            $success_msg = urlencode($message);
            header("Location: tenacity_yarn_report.php?success=1&msg={$success_msg}&t=" . time());
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
        $result = $tenacityYarnHandler->approveOrRejectByReportNumber($wf_report_number, $wf_action, $wf_comment);
        if ($result['success']) {
            $_SESSION['success_message'] = $result['message'];
            header("Location: tenacity_yarn_report.php?success=1&t=" . time());
            exit();
        } else {
            $error = $result['message'];
        }
    }
}

// Get report statistics
$report_stats = $tenacityYarnHandler->getReportStats();

// Unit options for dropdown
$unit_options = ['dTex', 'cN/dTex', '%'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Tenacity of Yarn Report (ASTM D2256)</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
  .form-row { display:flex; gap:20px; margin-bottom:25px; }
  .form-row .form-group { flex:1; }
  select { padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px); background:#fff; font-family:'Inter',sans-serif; }
  select:focus { outline:none; border-color:#3498db; box-shadow:0 0 0 2px rgba(52,152,219,0.2); }
  select:hover { border-color:#bdc3c7; }
</style>
</head>
<body>
<div class="container">
  <h1><?php echo $viewMode ? 'View ' : ($editMode ? 'Edit Rejected ' : ''); ?>Tenacity of Yarn Report (ASTM D2256)</h1>

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
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Sample Description</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['sample_description'] ?? 'N/A'); ?></div>
    </div>
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Reference</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['reference'] ?? 'N/A'); ?></div>
    </div>
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Store Entry Reference</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['store_entry_reference'] ?? 'N/A'); ?></div>
    </div>
  </div>

  <div class="info-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:15px; margin-bottom:20px;">
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Sample Received From</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['sample_received_from'] ?? 'N/A'); ?></div>
    </div>
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Sample Collected From</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['sample_collected_from'] ?? 'N/A'); ?></div>
    </div>
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Sample Received Date</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo $viewData['received_date'] ? date('d M Y H:i', strtotime($viewData['received_date'])) : 'N/A'; ?></div>
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
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Test Start Date</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo $viewData['test_start_date'] ? date('d M Y', strtotime($viewData['test_start_date'])) : 'N/A'; ?></div>
    </div>
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Test End Date</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo $viewData['test_end_date'] ? date('d M Y', strtotime($viewData['test_end_date'])) : 'N/A'; ?></div>
    </div>
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Test Temperature</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['test_temperature'] ?? 'N/A'); ?> °C</div>
    </div>
    <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db;">
      <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">RH %</div>
      <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['rh_percent'] ?? 'N/A'); ?>%</div>
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

  <?php if (!empty($viewData['others_information'])): ?>
  <div class="info-item" style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db; margin-top:15px;">
    <div class="info-label" style="font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px;">Other Information</div>
    <div class="info-value" style="font-size:14px; color:#2c3e50;"><?php echo htmlspecialchars($viewData['others_information']); ?></div>
  </div>
  <?php endif; ?>

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
        <td style="border:1px solid #ddd; padding:10px; text-align:left;">ASTM D2256</td>
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
  <!-- Tenacity of Yarn Test Rejection Modal -->
  <div id="yarnRejectModal" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); overflow-y:auto;">
      <div class="modal-content" style="background:#fff; margin:2% auto; padding:20px; border-radius:12px; width:90%; max-width:500px; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
          <span class="close" onclick="closeYarnRejectModal()" style="float:right; font-size:24px; font-weight:bold; cursor:pointer; color:#aaa; line-height:1;">&times;</span>
          <div class="modal-header" style="font-size:18px; font-weight:600; margin-bottom:12px; color:#e74c3c; flex-shrink:0;">
              <i class="fas fa-exclamation-triangle"></i> Reject Tenacity of Yarn Test
          </div>
          
          <form method="POST" id="yarnRejectForm" onsubmit="return submitYarnRejection(event)">
              <input type="hidden" name="yarn_report_number" id="yarn_report_number_input">
              <input type="hidden" name="yarn_action" value="rejected">
              
              <div class="modal-body" style="flex:1; overflow-y:auto; padding-right:5px;">
                  <div class="checkbox-group" style="margin:10px 0; max-height:200px; overflow-y:auto; padding:5px; border:1px solid #e0e0e0; border-radius:6px;">
                      <strong style="display:block; margin-bottom:8px; font-size:13px;">Rejection Reasons:</strong>
                      <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                          <input type="checkbox" name="yarn_rejection_reasons[]" value="Sample contamination" id="yarn_reason1" style="transform:scale(1.1); cursor:pointer;">
                          <label for="yarn_reason1" style="cursor:pointer; flex:1; font-size:12px;">Sample contamination</label>
                      </div>
                      <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                          <input type="checkbox" name="yarn_rejection_reasons[]" value="Incorrect test procedure (ASTM D2256)" id="yarn_reason2" style="transform:scale(1.1); cursor:pointer;">
                          <label for="yarn_reason2" style="cursor:pointer; flex:1; font-size:12px;">Incorrect test procedure (ASTM D2256)</label>
                      </div>
                      <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                          <input type="checkbox" name="yarn_rejection_reasons[]" value="Out of specification results" id="yarn_reason3" style="transform:scale(1.1); cursor:pointer;">
                          <label for="yarn_reason3" style="cursor:pointer; flex:1; font-size:12px;">Out of specification results</label>
                      </div>
                      <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                          <input type="checkbox" name="yarn_rejection_reasons[]" value="Incomplete test data" id="yarn_reason4" style="transform:scale(1.1); cursor:pointer;">
                          <label for="yarn_reason4" style="cursor:pointer; flex:1; font-size:12px;">Incomplete test data</label>
                      </div>
                      <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                          <input type="checkbox" name="yarn_rejection_reasons[]" value="Equipment calibration issue" id="yarn_reason5" style="transform:scale(1.1); cursor:pointer;">
                          <label for="yarn_reason5" style="cursor:pointer; flex:1; font-size:12px;">Equipment calibration issue</label>
                      </div>
                      <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                          <input type="checkbox" name="yarn_rejection_reasons[]" value="Documentation error" id="yarn_reason6" style="transform:scale(1.1); cursor:pointer;">
                          <label for="yarn_reason6" style="cursor:pointer; flex:1; font-size:12px;">Documentation error</label>
                      </div>
                      <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                          <input type="checkbox" name="yarn_rejection_reasons[]" value="Yarn quality issue" id="yarn_reason7" style="transform:scale(1.1); cursor:pointer;">
                          <label for="yarn_reason7" style="cursor:pointer; flex:1; font-size:12px;">Yarn quality issue</label>
                      </div>
                  </div>
                  
                  <textarea name="yarn_remarks" class="remarks-box" placeholder="Additional comments (optional)" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; margin-top:10px; min-height:80px;"></textarea>
              </div>
              
              <div class="modal-footer" style="flex-shrink:0; margin-top:15px; padding-top:15px; border-top:1px solid #ddd; text-align:right;">
                  <button type="button" onclick="closeYarnRejectModal()" style="padding:8px 18px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer; margin-right:8px; font-size:13px;">
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
  function submitYarnRejection(e) {
      e.preventDefault();
      const checkboxes = document.querySelectorAll('input[name="yarn_rejection_reasons[]"]:checked');
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
      actionInput.name = 'yarn_action';
      actionInput.value = 'rejected';
      submitForm.appendChild(actionInput);
      
      const reportInput = document.createElement('input');
      reportInput.type = 'hidden';
      reportInput.name = 'yarn_report_number';
      reportInput.value = document.getElementById('yarn_report_number_input').value;
      submitForm.appendChild(reportInput);
      
      // Add rejection reasons
      checkboxes.forEach(function(checkbox) {
          const reasonInput = document.createElement('input');
          reasonInput.type = 'hidden';
          reasonInput.name = 'yarn_rejection_reasons[]';
          reasonInput.value = checkbox.value;
          submitForm.appendChild(reasonInput);
      });
      
      // Add remarks if any
      const remarksField = form.querySelector('textarea[name="yarn_remarks"]');
      if (remarksField && remarksField.value.trim()) {
          const remarksInput = document.createElement('input');
          remarksInput.type = 'hidden';
          remarksInput.name = 'yarn_remarks';
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
      if (confirm('Approve Tenacity of Yarn Report ' + reportNumber + '?')) {
          // Create form in current window and submit to dashboard
          const form = document.createElement('form');
          form.method = 'POST';
          form.action = '../admin/raw_material_test_approval_dashboard.php';
          form.style.display = 'none';
          
          const actionInput = document.createElement('input');
          actionInput.type = 'hidden';
          actionInput.name = 'yarn_action';
          actionInput.value = 'approved';
          form.appendChild(actionInput);
          
          const reportInput = document.createElement('input');
          reportInput.type = 'hidden';
          reportInput.name = 'yarn_report_number';
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
      document.getElementById('yarn_report_number_input').value = reportNumber;
      document.getElementById('yarnRejectModal').style.display = 'block';
  }

  function closeYarnRejectModal() {
      document.getElementById('yarnRejectModal').style.display = 'none';
      document.getElementById('yarnRejectForm').reset();
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
      <?php echo htmlspecialchars($message); ?>
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

  <?php if ($tenacityYarnHandler->canApproveReports()): ?>
  <!-- Pending Approval Queue -->
    <?php if (!empty($pending_reports)): ?>
    <div style="margin-top:16px; padding:12px; border:1px solid #ddd; border-radius:8px; background:#fff;">
      <h3 style="margin:0 0 12px 0;">Pending Reports</h3>
      <table class="test-table">
        <thead>
          <tr>
            <th>Report No</th>
            <th>Client</th>
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
            <td><?php echo htmlspecialchars($pr['client']); ?></td>
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

  <?php if (!$tenacityYarnHandler->canApproveReports() && !$editMode): ?>
  <!-- Rejected Reports for Tester to Review - Only show when NOT in edit mode -->
  <?php $rejected = $tenacityYarnHandler->getRejectedReportsForUser($reporter_id); if (!empty($rejected)): ?>
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
            <a href="tenacity_yarn_report.php?id=<?php echo $rj['id']; ?>" class="submit-btn" style="padding:6px 10px; text-decoration:none; display:inline-block; background:#f39c12; color:#fff;">Edit & Resubmit</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin:12px 0 0 0; color:#856404; font-style:italic;">💡 Note: Click "Edit & Resubmit" to pre-fill and modify the rejected report.</p>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <form method="POST" action="" onsubmit="return validateTestDates();">
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
                // Filter out Denier entries (remove if parameter is 'Denier')
                $param_name = '';
                if (isset($result['parameter'])) {
                    $param_name = strtolower(trim($result['parameter']));
                } elseif (isset($result['param'])) {
                    $param_name = strtolower(trim($result['param']));
                }
                
                if ($param_name === 'denier') {
                    continue; // Skip Denier entries
                }
                
                // Only include results that match valid sl_no in test_parameters
                $sl_no = $result['sl_no'] ?? null;
                if ($sl_no !== null && isset($test_parameters[$sl_no])) {
                    $existing_test_results[$sl_no] = $result;
                }
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
      <div class="form-group">
        <label>Store Entry Reference: <span style="color: #e74c3c;">*</span></label>
        <select name="store_entry_reference" required>
          <option value="">-- Select Store Entry --</option>
          <?php foreach ($storeEntries as $entry): ?>
            <option value="<?php echo htmlspecialchars($entry['entry_number']); ?>" <?php 
              $storeSelected = false;
              if ($editMode && $editData['store_entry_reference'] === $entry['entry_number']) {
                  $storeSelected = true;
              } elseif (!$editMode && $lastSubmittedData && $lastSubmittedData['store_entry_reference'] === $entry['entry_number']) {
                  $storeSelected = true;
              }
              echo $storeSelected ? 'selected' : '';
            ?>>
              <?php echo htmlspecialchars($entry['entry_number']); ?> - 
              <?php echo htmlspecialchars($entry['material_type']); ?> 
              (<?php echo number_format($entry['amount_kg'], 2); ?> kg) - 
              <?php echo date('d M Y', strtotime($entry['received_date'])); ?>
            </option>
          <?php endforeach; ?>
          <?php 
          // Only show "Previously Selected" option in edit mode (when editing a rejected report)
          // For new submissions, don't show already submitted references
          if ($editMode && !empty($editData['store_entry_reference'])) {
              $storeRefToCheck = $editData['store_entry_reference'];
              
              // Check if the store entry is not in the dropdown (already used by another report)
              $store_found = false;
              foreach ($storeEntries as $entry) {
                  if ($entry['entry_number'] === $storeRefToCheck) {
                      $store_found = true;
                      break;
                  }
              }
              // Only show "Previously Selected" if it's not in the dropdown (meaning it was used in this report being edited)
              if (!$store_found) {
              ?>
              <option value="<?php echo htmlspecialchars($storeRefToCheck); ?>" selected>
                <?php echo htmlspecialchars($storeRefToCheck); ?> (Previously Selected)
              </option>
              <?php 
              }
          }
          ?>
        </select>
        <small style="color: #7f8c8d; font-size: 0.85em;">Select the material from store that you are testing</small>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Sample Description:</label>
        <input type="text" name="sample_description" value="<?php 
          if ($editMode) {
              echo htmlspecialchars($editData['sample_description'] ?? '');
          } elseif ($lastSubmittedData) {
              echo htmlspecialchars($lastSubmittedData['sample_description'] ?? '');
          }
        ?>" required>
      </div>
      <div class="form-group">
        <label>Ref:</label>
        <input type="text" name="reference" value="<?php 
          if ($editMode) {
              echo htmlspecialchars($editData['reference'] ?? '');
          } elseif ($lastSubmittedData) {
              echo htmlspecialchars($lastSubmittedData['reference'] ?? '');
          }
        ?>">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Sample Received From:</label>
        <input type="text" name="sample_received_from" value="<?php 
          if ($editMode) {
              echo htmlspecialchars($editData['sample_received_from'] ?? '');
          } elseif ($lastSubmittedData) {
              echo htmlspecialchars($lastSubmittedData['sample_received_from'] ?? '');
          }
        ?>" required>
      </div>
      <div class="form-group">
        <label>Sample Collected From:</label>
        <input type="text" name="sample_collected_from" value="<?php 
          if ($editMode) {
              echo htmlspecialchars($editData['sample_collected_from'] ?? '');
          } elseif ($lastSubmittedData) {
              echo htmlspecialchars($lastSubmittedData['sample_collected_from'] ?? '');
          }
        ?>" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Received Date:</label>
        <input type="datetime-local" name="received_date" value="<?php 
          if ($editMode && !empty($editData['received_date'])) {
              echo date('Y-m-d\TH:i', strtotime($editData['received_date']));
          } elseif ($lastSubmittedData && !empty($lastSubmittedData['received_date'])) {
              echo date('Y-m-d\TH:i', strtotime($lastSubmittedData['received_date']));
          }
        ?>" required>
      </div>
      <div class="form-group">
        <label>Test Start Date:</label>
        <input type="date" name="test_start_date" id="test_start_date" value="<?php 
          if ($editMode) {
              echo htmlspecialchars($editData['test_start_date'] ?? '');
          } elseif ($lastSubmittedData) {
              echo htmlspecialchars($lastSubmittedData['test_start_date'] ?? '');
          }
        ?>" required onchange="validateTestDates()">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Test End Date:</label>
        <input type="date" name="test_end_date" id="test_end_date" value="<?php 
          if ($editMode) {
              echo htmlspecialchars($editData['test_end_date'] ?? '');
          } elseif ($lastSubmittedData) {
              echo htmlspecialchars($lastSubmittedData['test_end_date'] ?? '');
          }
        ?>" required onchange="validateTestDates()">
        <small id="date_error" style="color: #e74c3c; display: none;">Test End Date cannot be earlier than Test Start Date.</small>
      </div>
      <div class="form-group">
        <!-- Empty space for layout balance -->
      </div>
    </div>

    <div class="form-group">
      <label>Others Information:</label>
      <textarea name="others_information" rows="3" placeholder="Enter additional information (optional)"><?php 
        if ($editMode) {
            echo htmlspecialchars($editData['others_information'] ?? '');
        } elseif ($lastSubmittedData) {
            echo htmlspecialchars($lastSubmittedData['others_information'] ?? '');
        }
      ?></textarea>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Test Temperature (°C):</label>
        <input type="number" name="test_temperature" step="0.01" max="1000" min="0" value="<?php 
          if ($editMode) {
              echo htmlspecialchars($editData['test_temperature'] ?? '');
          } elseif ($lastSubmittedData) {
              echo htmlspecialchars($lastSubmittedData['test_temperature'] ?? '');
          }
        ?>" required oninput="if(this.value < 0) this.value = 0">
      </div>
      <div class="form-group">
        <label>RH%:</label>
        <input type="number" name="rh_percent" step="0.01" max="100" min="0" value="<?php 
          if ($editMode) {
              echo htmlspecialchars($editData['rh_percent'] ?? '');
          } elseif ($lastSubmittedData) {
              echo htmlspecialchars($lastSubmittedData['rh_percent'] ?? '');
          }
        ?>" required oninput="if(this.value < 0) this.value = 0">
      </div>
    </div>

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
        // Skip if parameter is Denier (shouldn't happen, but double-check)
        if (strtolower(trim($param['param'])) === 'denier') {
            continue;
        }
        
        $existing_result = $existing_test_results[$sl_no] ?? null;
        // Double-check existing result is not Denier and matches the expected parameter
        if ($existing_result) {
            $existing_param = '';
            if (isset($existing_result['parameter'])) {
                $existing_param = strtolower(trim($existing_result['parameter']));
            } elseif (isset($existing_result['param'])) {
                $existing_param = strtolower(trim($existing_result['param']));
            }
            
            // If it's Denier or doesn't match the expected parameter name, ignore it
            if ($existing_param === 'denier' || ($existing_param !== '' && $existing_param !== strtolower(trim($param['param'])))) {
                $existing_result = null; // Ignore Denier entries or mismatched parameters
            }
        }
        
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
            // Also call the inline validation function to show error message
            validateTestDates();
            alert('Test End Date cannot be earlier than Test Start Date. Please correct the dates.');
            endInput.focus();
            return false;
        } else {
            // Clear error message if dates are valid
            const errorMsg = document.getElementById('date_error');
            if (errorMsg) errorMsg.style.display = 'none';
            if (endInput) endInput.setCustomValidity('');
        }
        return true;
    }

    startInput?.addEventListener('change', validateDatesOrAlert);
    endInput?.addEventListener('change', validateDatesOrAlert);

    formEl?.addEventListener('submit', function(e) {
        // Validate dates before submission
        if (!validateTestDates()) {
            e.preventDefault();
            alert('Please correct the dates.');
            return false;
        }
        if (!validateDatesOrAlert()) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
        // If all validations pass, allow form to submit
    });
});

// Validate that Test End Date is not earlier than Test Start Date (with inline error message)
function validateTestDates() {
    const startDateInput = document.getElementById('test_start_date');
    const endDateInput = document.getElementById('test_end_date');
    const errorMsg = document.getElementById('date_error');
    
    if (startDateInput && endDateInput && startDateInput.value && endDateInput.value) {
        const start = new Date(startDateInput.value);
        const end = new Date(endDateInput.value);
        
        if (end < start) {
            errorMsg.style.display = 'block';
            endDateInput.setCustomValidity('Test End Date cannot be earlier than Test Start Date.');
            return false;
        } else {
            errorMsg.style.display = 'none';
            endDateInput.setCustomValidity('');
            return true;
        }
    }
    return true;
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
</script>
<?php endif; ?>
</body>
</html>


