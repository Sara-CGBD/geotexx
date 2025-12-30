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
        $reporter_full_name = $row['full_name'] ?: $reporter_name; // Use full_name if available, fallback to username
    }
    $stmt->close();
} catch (Exception $e) {
    // If full_name column doesn't exist yet, just use username
    $reporter_full_name = $reporter_name;
}

// Fetch store received entries for reference dropdown (exclude only if sewing test already done)
$storeEntries = [];
try {
    $storeQuery = $conn->query("SELECT sre.entry_number, sre.material_type, sre.amount_kg, sre.date_time as received_date 
                                 FROM store_received_entries sre
                                 WHERE sre.entry_number NOT IN (
                                     SELECT DISTINCT store_entry_reference 
                                     FROM sewing_thread_reports 
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
    error_log("Error fetching store entries for sewing test: " . $e->getMessage());
}

$message = '';
$error = '';

// Check for success message from session (after redirect)
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Enhanced backend functionality for Sewing Thread Report
class SewingThreadReportHandler {
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
        try {
            $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
            $hour = (int)$now->format('H');
            
            // Calculate the day key for 8 AM reset cycle
            $dayKey = $now->format('Ymd');
            
            // If it's before 8 AM, use previous day's key
            if ($hour < 8) {
                $yesterday = clone $now;
                $yesterday->modify('-1 day');
                $dayKey = $yesterday->format('Ymd');
            }
            
            // Count actual reports for this day key (preview what next number will be)
            $pattern = 'STR-' . $dayKey . '-%';
            $countStmt = $this->conn->prepare("SELECT COUNT(*) as report_count FROM sewing_thread_reports WHERE report_number LIKE ?");
            $countStmt->bind_param("s", $pattern);
            $countStmt->execute();
            $result = $countStmt->get_result();
            
            if ($result && $row = $result->fetch_assoc()) {
                $counter = (int)$row['report_count'] + 1; // Next number = count + 1
            } else {
                $counter = 1; // First report of the day
            }
            
            $countStmt->close();
            
            // Debug logging
            error_log("Sewing Thread Report - Display number for day $dayKey: counter=$counter (NOT incrementing)");
            
            // Generate report number: STR-YYYYMMDD-XXXXX
            return "STR-{$dayKey}-" . str_pad($counter, 5, '0', STR_PAD_LEFT);
            
        } catch (Exception $e) {
            error_log("Sewing Thread Report - Get next number error: " . $e->getMessage());
            // Fallback to timestamp-based number
            return "STR-" . date('YmdHis') . "-" . rand(1000, 9999);
        }
    }
    
    // Generate and increment report number (ONLY called on successful submission)
    public function generateAndIncrementReportNumber() {
        try {
            $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
            $hour = (int)$now->format('H');
            
            // Calculate the day key for 8 AM reset cycle
            $dayKey = $now->format('Ymd');
            
            // If it's before 8 AM, use previous day's key
            if ($hour < 8) {
                $yesterday = clone $now;
                $yesterday->modify('-1 day');
                $dayKey = $yesterday->format('Ymd');
            }
            
            // Count actual reports for this day key (more reliable than counter table)
            $pattern = 'STR-' . $dayKey . '-%';
            $countStmt = $this->conn->prepare("SELECT COUNT(*) as report_count FROM sewing_thread_reports WHERE report_number LIKE ?");
            $countStmt->bind_param("s", $pattern);
            $countStmt->execute();
            $result = $countStmt->get_result();
            
            $counter = 1; // Default to 1
            if ($result && $row = $result->fetch_assoc()) {
                $counter = (int)$row['report_count'] + 1; // Next number = count + 1
            }
            $countStmt->close();
            
            // Generate report number: STR-YYYYMMDD-XXXXX
            return "STR-{$dayKey}-" . str_pad($counter, 5, '0', STR_PAD_LEFT);
            
        } catch (Exception $e) {
            error_log("Error generating report number: " . $e->getMessage());
            // Fallback to timestamp-based number
            return "STR-" . date('YmdHis') . "-" . rand(1000, 9999);
        }
    }
    
    // Enhanced data validation
    public function validateFormData($data) {
        $errors = [];
        
        // Required field validation
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
            // 'approved_by' removed from required for non-admin
        ];
        
        foreach ($required_fields as $field => $label) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                $errors[] = "$label is required.";
            }
        }
        
        // Date validation
        if (!empty($data['test_start_date']) && !empty($data['test_end_date'])) {
            $start_date = new DateTime($data['test_start_date']);
            $end_date = new DateTime($data['test_end_date']);
            if ($start_date > $end_date) {
                $errors[] = "Test start date cannot be after test end date.";
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
                $test_result_value = $result['test_result'] ?? $result['result'] ?? '';
                if (!empty($test_result_value) && !is_numeric($test_result_value)) {
                    $errors[] = "Test result for row " . ($index + 1) . " must be a valid number.";
                }
            }
        }
        
        // Approved by validation - check if selected approver has permission
        if (!empty($data['approved_by'])) {
            $approver_name = trim($data['approved_by']);
            if (!$this->canUserApproveReports($approver_name)) {
                $errors[] = "Selected approver does not have permission to approve reports.";
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
            $createTable = "CREATE TABLE IF NOT EXISTS sewing_thread_reports (
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
            
            // Debug: Log table creation attempt
            error_log("Sewing Thread Report - Attempting to create table: sewing_thread_reports");
            
            if (!$this->conn->query($createTable)) {
                $error_msg = "Failed to create table: " . $this->conn->error;
                error_log("Sewing Thread Report - Table creation error: " . $error_msg);
                throw new Exception($error_msg);
            }
            
            // Add column if it doesn't exist (for existing databases)
            $this->conn->query("ALTER TABLE sewing_thread_reports ADD COLUMN IF NOT EXISTS store_entry_reference VARCHAR(100) NULL AFTER report_number");
            
            // Debug: Log successful table creation
            error_log("Sewing Thread Report - Table creation successful: sewing_thread_reports");
            
            // Make approved_by nullable in existing installations
            @$this->conn->query("ALTER TABLE sewing_thread_reports MODIFY approved_by VARCHAR(100) NULL");
            
            // Make reference nullable
            @$this->conn->query("ALTER TABLE sewing_thread_reports MODIFY reference VARCHAR(255) NULL");

            // Check for duplicate report number AFTER table creation
            $duplicateCheck = $this->conn->prepare("SELECT id FROM sewing_thread_reports WHERE report_number = ?");
            $duplicateCheck->bind_param("s", $report_number);
            $duplicateCheck->execute();
            if ($duplicateCheck->get_result()->num_rows > 0) {
                throw new Exception("Report number already exists. Please refresh the page to get a new report number.");
            }
            $duplicateCheck->close();
            
            // Prepare test results JSON - include sl_no and parameter name
            $test_parameters_map = [
                1 => ['param' => 'Denier', 'standards' => ['ISO 2060']],
                2 => ['param' => 'Tenacity at Break', 'standards' => ['ASTM D2256']],
                3 => ['param' => 'Std Deviation', 'standards' => ['ASTM D2256']],
                4 => ['param' => 'CV%', 'standards' => ['ASTM D2256']],
                5 => ['param' => 'Elongation at Break', 'standards' => ['ASTM D2256']]
            ];
            
            $processed_test_results = [];
            if (isset($data['test_results']) && is_array($data['test_results'])) {
                foreach ($data['test_results'] as $sl_no => $result_data) {
                    // Check if there's any data for this parameter
                    $has_result = !empty($result_data['result']) || !empty($result_data['test_result']);
                    $has_unit = !empty($result_data['unit']);
                    
                    if ($has_result || $has_unit) {
                        $processed_test_results[] = [
                            'sl_no' => (int)$sl_no,
                            'parameter' => $test_parameters_map[(int)$sl_no]['param'] ?? 'Unknown',
                            'test_result' => $result_data['test_result'] ?? $result_data['result'] ?? '',
                            'unit' => $result_data['unit'] ?? '',
                            'remarks' => $result_data['remarks'] ?? ''
                        ];
                    }
                }
            }
            $test_results_json = json_encode($processed_test_results, JSON_UNESCAPED_UNICODE);
            
            // Insert with enhanced error handling
            $stmt = $this->conn->prepare(
                "INSERT INTO sewing_thread_reports (
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

            // If no rows affected (rare), try a lightweight fallback insert to ensure persistence
            if ($stmt->affected_rows <= 0) {
                $stmt->close();
                $fallbackJson = $test_results_json ?: '[]';
                $fallback = $this->conn->prepare(
                    "INSERT INTO sewing_thread_reports (
                        report_number, store_entry_reference, sample_description, sample_received_from, sample_collected_from, reference,
                        received_date, test_start_date, test_end_date, others_information,
                        test_temperature, rh_percent, test_performed_by, approved_by,
                        test_results, reporter_id, reporter_name, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                if (!$fallback) {
                    throw new Exception("Fallback prepare failed: " . $this->conn->error);
                }
                $fallback->bind_param("ssssssssssddsssiss",
                    $report_number, $data['store_entry_reference'], $sample_description, $sample_received_from, $sample_collected_from, $reference,
                    $received_date, $test_start_date, $test_end_date, $others_information,
                    $test_temperature, $rh_percent, $test_performed_by, $approved_by,
                    $fallbackJson, $this->reporter_id, $this->reporter_full_name, $status
                );
                if (!$fallback->execute()) {
                    throw new Exception("Fallback insert failed: " . $fallback->error);
                }
                $report_id = $this->conn->insert_id;
                $fallback->close();
            } else {
                $report_id = $this->conn->insert_id;
            }
            $stmt->close();
            
            // Log a successful save for diagnostics
            error_log("Sewing Thread Report - saved report {$report_number} with id {$report_id} and store ref {$data['store_entry_reference']}");
            
            // Log the action
            $this->logAction('report_created', $report_id, $report_number);
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => "Sewing Thread Report saved successfully! Report Number: $report_number",
                'report_id' => $report_id,
                'report_number' => $report_number
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Sewing Thread Report Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error: " . $e->getMessage()
            ];
        }
    }
    
    // Get rejected reports for current tester
    public function getRejectedReportsForUser($user_id) {
        // First ensure remarks column exists
        $this->ensureRemarksColumn();
        
        $stmt = $this->conn->prepare(
            "SELECT id, report_number, sample_description, status, remarks, created_at, approved_by
             FROM sewing_thread_reports 
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
    
    // Ensure remarks column exists
    private function ensureRemarksColumn() {
        $check = $this->conn->query("SHOW COLUMNS FROM sewing_thread_reports LIKE 'remarks'");
        if ($check && $check->num_rows == 0) {
            $this->conn->query("ALTER TABLE sewing_thread_reports ADD COLUMN remarks TEXT NULL AFTER approved_by");
        }
    }
    
    // Check if user can approve reports
    public function canApproveReports() {
        $user_role = strtolower(trim($_SESSION['role'] ?? ''));
        return in_array($user_role, ['admin', 'agm ops', 'agm operations', 'management']);
    }
    
    // Check if specific user can approve reports
    public function canUserApproveReports($username) {
        try {
            $stmt = $this->conn->prepare("SELECT role FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stmt->close();
                $userRole = strtolower(trim($row['role']));
                return in_array($userRole, ['admin', 'agm ops', 'agm operations', 'management']);
            }
            
            $stmt->close();
            return false;
        } catch (Exception $e) {
            error_log("Failed to check user approval permission: " . $e->getMessage());
            return false;
        }
    }
    
    // Get approval status for current user
    public function getApprovalStatus($report_id) {
        try {
            $stmt = $this->conn->prepare("
                SELECT status, approved_by, updated_at 
                FROM sewing_thread_reports 
                WHERE id = ? AND reporter_id = ?
            ");
            $stmt->bind_param("ii", $report_id, $this->reporter_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $report = $result->fetch_assoc();
                $stmt->close();
                return $report;
            }
            
            $stmt->close();
            return null;
        } catch (Exception $e) {
            error_log("Failed to get approval status: " . $e->getMessage());
            return null;
        }
    }

    // Fetch latest pending reports for admin queue
    public function getPendingReports($limit = 20) {
        $stmt = $this->conn->prepare(
            "SELECT id, report_number, sample_received_from AS client, sample_description AS material, test_performed_by AS tested_by, status, created_at, updated_at
             FROM sewing_thread_reports WHERE status = 'pending' ORDER BY updated_at DESC LIMIT ?"
        );
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close();
        return $rows;
    }

    // Admin approve/reject by report number with optional comments
    public function approveOrRejectByReportNumber($report_number, $action, $comments = '') {
        // Ensure remarks column exists
        $this->ensureRemarksColumn();
        
        $action = strtolower($action) === 'approved' ? 'approved' : 'rejected';
        $stmt = $this->conn->prepare("UPDATE sewing_thread_reports SET status = ?, approved_by = ?, remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE report_number = ?");
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
        // Log action
        $idStmt = $this->conn->prepare("SELECT id FROM sewing_thread_reports WHERE report_number = ?");
        $idStmt->bind_param("s", $report_number);
        $idStmt->execute();
        $res = $idStmt->get_result();
        $row = $res->fetch_assoc();
        $idStmt->close();
        if ($row && isset($row['id'])) {
            $this->logAction('report_' . $action, (int)$row['id'], $comments);
        }
        return ['success' => true, 'message' => 'Report ' . ucfirst($action) . ' successfully.'];
    }
    
    // Approve or reject report (only for admin/AGM Ops)
    public function approveReport($report_id, $action, $comments = '') {
        try {
            if (!$this->canApproveReports()) {
                throw new Exception("You don't have permission to approve reports.");
            }
            
            $valid_actions = ['approve', 'reject'];
            if (!in_array($action, $valid_actions)) {
                throw new Exception("Invalid action. Must be 'approve' or 'reject'.");
            }
            
            $status = $action === 'approve' ? 'approved' : 'rejected';
            
            $this->conn->begin_transaction();
            
            // Update report status
            $stmt = $this->conn->prepare("
                UPDATE sewing_thread_reports 
                SET status = ?, approved_by = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->bind_param("ssi", $status, $this->reporter_name, $report_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update report status: " . $stmt->error);
            }
            
            $affected_rows = $stmt->affected_rows;
            $stmt->close();
            
            if ($affected_rows > 0) {
                // Log the approval action
                $this->logAction("report_{$action}d", $report_id, $status);
                
                // Add approval comment if provided
                if (!empty($comments)) {
                    $this->addApprovalComment($report_id, $action, $comments);
                }
                
                $this->conn->commit();
                return [
                    'success' => true,
                    'message' => "Report {$action}d successfully.",
                    'status' => $status
                ];
            } else {
                $this->conn->rollback();
                return [
                    'success' => false,
                    'message' => "Report not found or already processed."
                ];
            }
            
        } catch (Exception $e) {
            $this->conn->rollback();
            error_log("Failed to approve report: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Error: " . $e->getMessage()
            ];
        }
    }
    
    // Add approval comments
    private function addApprovalComment($report_id, $action, $comments) {
        try {
            // Create approval comments table if not exists
            $createCommentsTable = "CREATE TABLE IF NOT EXISTS sewing_thread_approval_comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                report_id INT NOT NULL,
                action VARCHAR(20) NOT NULL,
                comments TEXT,
                approver_id INT NOT NULL,
                approver_name VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_report_id (report_id),
                INDEX idx_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $this->conn->query($createCommentsTable);
            
            $stmt = $this->conn->prepare("
                INSERT INTO sewing_thread_approval_comments (report_id, action, comments, approver_id, approver_name) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("issss", $report_id, $action, $comments, $this->reporter_id, $this->reporter_name);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            error_log("Failed to add approval comment: " . $e->getMessage());
        }
    }
    
    // Get approval comments for a report
    public function getApprovalComments($report_id) {
        try {
            $stmt = $this->conn->prepare("
                SELECT action, comments, approver_name, created_at 
                FROM sewing_thread_approval_comments 
                WHERE report_id = ? 
                ORDER BY created_at DESC
            ");
            $stmt->bind_param("i", $report_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $comments = [];
            while ($row = $result->fetch_assoc()) {
                $comments[] = $row;
            }
            
            $stmt->close();
            return $comments;
        } catch (Exception $e) {
            error_log("Failed to get approval comments: " . $e->getMessage());
            return [];
        }
    }
    
    // Get reports pending approval (for admin/AGM Ops)
    public function getPendingApprovalReports($limit = 50) {
        try {
            if (!$this->canApproveReports()) {
                return [];
            }
            
            $stmt = $this->conn->prepare("
                SELECT r.*, u.username as reporter_name 
                FROM sewing_thread_reports r
                LEFT JOIN users u ON r.reporter_id = u.id
                WHERE r.status = 'submitted' 
                ORDER BY r.created_at ASC 
                LIMIT ?
            ");
            $stmt->bind_param("i", $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $reports = [];
            while ($row = $result->fetch_assoc()) {
                $row['test_results'] = json_decode($row['test_results'], true);
                $reports[] = $row;
            }
            
            $stmt->close();
            return $reports;
        } catch (Exception $e) {
            error_log("Failed to get pending approval reports: " . $e->getMessage());
            return [];
        }
    }
    
    // Get approvers (Admin and AGM Ops users)
    public function getApprovers() {
        try {
            $stmt = $this->conn->prepare("
                SELECT id, username, role 
                FROM users 
                WHERE role IN ('admin', 'AGM Ops') 
                ORDER BY role, username
            ");
            $stmt->execute();
            $result = $stmt->get_result();
            
            $approvers = [];
            while ($row = $result->fetch_assoc()) {
                $approvers[] = [
                    'id' => $row['id'],
                    'name' => $row['username'],
                    'role' => $row['role']
                ];
            }
            
            $stmt->close();
            return $approvers;
        } catch (Exception $e) {
            error_log("Failed to get approvers: " . $e->getMessage());
            return [];
        }
    }
    
    // Input sanitization
    private function sanitizeInput($input) {
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
    
    // Action logging with enhanced table creation
    private function logAction($action, $report_id, $report_number) {
        try {
            // Create logs table if not exists
            $createLogTable = "CREATE TABLE IF NOT EXISTS sewing_thread_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                action VARCHAR(50) NOT NULL,
                report_id INT,
                report_number VARCHAR(100),
                user_id INT NOT NULL,
                user_name VARCHAR(255) NOT NULL,
                ip_address VARCHAR(45),
                user_agent TEXT,
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_action (action),
                INDEX idx_user (user_id),
                INDEX idx_timestamp (timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $this->conn->query($createLogTable);
            
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            $logStmt = $this->conn->prepare("
                INSERT INTO sewing_thread_logs (action, report_id, report_number, user_id, user_name, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $logStmt->bind_param("sisssss", $action, $report_id, $report_number, $this->reporter_id, $this->reporter_name, $ip_address, $user_agent);
            $logStmt->execute();
            $logStmt->close();
        } catch (Exception $e) {
            error_log("Failed to log action: " . $e->getMessage());
        }
    }
    
    // Get recent reports for the current user
    public function getRecentReports($limit = 10) {
        try {
            $stmt = $this->conn->prepare("
                SELECT report_number, sample_description, created_at, status 
                FROM sewing_thread_reports 
                WHERE reporter_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->bind_param("ii", $this->reporter_id, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $reports = [];
            while ($row = $result->fetch_assoc()) {
                $reports[] = $row;
            }
            
            $stmt->close();
            return $reports;
        } catch (Exception $e) {
            error_log("Failed to get recent reports: " . $e->getMessage());
            return [];
        }
    }
    
    // Export report data to CSV
    public function exportToCSV($report_ids = []) {
        try {
            $where_clause = "";
            $params = [];
            $types = "";
            
            if (!empty($report_ids)) {
                $placeholders = str_repeat('?,', count($report_ids) - 1) . '?';
                $where_clause = "WHERE id IN ($placeholders)";
                $params = $report_ids;
                $types = str_repeat('i', count($report_ids));
            }
            
            $stmt = $this->conn->prepare("
                SELECT report_number, sample_description, sample_received_from, sample_collected_from, 
                       reference, received_date, test_start_date, test_end_date, test_temperature, 
                       rh_percent, test_performed_by, approved_by, status, created_at
                FROM sewing_thread_reports 
                $where_clause
                ORDER BY created_at DESC
            ");
            
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            $csv_data = [];
            $csv_data[] = [
                'Report Number', 'Sample Description', 'Sample Received From', 'Sample Collected From',
                'Ref', 'Received Date', 'Test Start Date', 'Test End Date', 'Temperature (°C)',
                'RH%', 'Test Performed By', 'Approved By', 'Status', 'Created At'
            ];
            
            while ($row = $result->fetch_assoc()) {
                $csv_data[] = array_values($row);
            }
            
            $stmt->close();
            return $csv_data;
        } catch (Exception $e) {
            error_log("Failed to export CSV: " . $e->getMessage());
            return [];
        }
    }
    
    // Get report by ID
    public function getReportById($report_id) {
        try {
            $stmt = $this->conn->prepare("
                SELECT * FROM sewing_thread_reports WHERE id = ? AND reporter_id = ?
            ");
            $stmt->bind_param("ii", $report_id, $this->reporter_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $report = $result->fetch_assoc();
                $report['test_results'] = json_decode($report['test_results'], true);
                $stmt->close();
                return $report;
            }
            
            $stmt->close();
            return null;
        } catch (Exception $e) {
            error_log("Failed to get report by ID: " . $e->getMessage());
            return null;
        }
    }
    
    // Update report status
    public function updateReportStatus($report_id, $status) {
        try {
            $valid_statuses = ['draft', 'submitted', 'approved', 'rejected'];
            if (!in_array($status, $valid_statuses)) {
                throw new Exception("Invalid status");
            }
            
            $stmt = $this->conn->prepare("
                UPDATE sewing_thread_reports 
                SET status = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ? AND reporter_id = ?
            ");
            $stmt->bind_param("sii", $status, $report_id, $this->reporter_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update status: " . $stmt->error);
            }
            
            $affected_rows = $stmt->affected_rows;
            $stmt->close();
            
            if ($affected_rows > 0) {
                $this->logAction('status_updated', $report_id, $status);
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Failed to update status: " . $e->getMessage());
            return false;
        }
    }
    
    // Get report statistics
    public function getReportStats() {
        try {
            $stats = [];
            
            // Total reports
            $totalResult = $this->conn->query("SELECT COUNT(*) as total FROM sewing_thread_reports");
            $stats['total_reports'] = $totalResult->fetch_assoc()['total'];
            
            // Reports today
            $todayResult = $this->conn->query("SELECT COUNT(*) as today FROM sewing_thread_reports WHERE DATE(created_at) = CURDATE()");
            $stats['reports_today'] = $todayResult->fetch_assoc()['today'];
            
            // Reports this month
            $monthResult = $this->conn->query("SELECT COUNT(*) as month FROM sewing_thread_reports WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
            $stats['reports_this_month'] = $monthResult->fetch_assoc()['month'];
            
            // Reports by status
            $statusResult = $this->conn->query("
                SELECT status, COUNT(*) as count 
                FROM sewing_thread_reports 
                GROUP BY status
            ");
            $stats['by_status'] = [];
            while ($row = $statusResult->fetch_assoc()) {
                $stats['by_status'][$row['status']] = $row['count'];
            }
            
            return $stats;
        } catch (Exception $e) {
            error_log("Failed to get stats: " . $e->getMessage());
            return [];
        }
    }
    
    // Search reports
    public function searchReports($search_term, $limit = 50) {
        try {
            $search_term = "%{$search_term}%";
            $stmt = $this->conn->prepare("
                SELECT id, report_number, sample_description, sample_received_from, 
                       created_at, status 
                FROM sewing_thread_reports 
                WHERE (report_number LIKE ? OR sample_description LIKE ? OR 
                       sample_received_from LIKE ? OR sample_collected_from LIKE ?)
                AND reporter_id = ?
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            $stmt->bind_param("ssssii", $search_term, $search_term, $search_term, $search_term, $this->reporter_id, $limit);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $reports = [];
            while ($row = $result->fetch_assoc()) {
                $reports[] = $row;
            }
            
            $stmt->close();
            return $reports;
        } catch (Exception $e) {
            error_log("Failed to search reports: " . $e->getMessage());
            return [];
        }
    }
    
    // Backup and restore functionality
    public function createBackup() {
        try {
            $backup_data = [];
            
            // Get all reports
            $stmt = $this->conn->query("SELECT * FROM sewing_thread_reports ORDER BY created_at");
            $backup_data['reports'] = [];
            while ($row = $stmt->fetch_assoc()) {
                $row['test_results'] = json_decode($row['test_results'], true);
                $backup_data['reports'][] = $row;
            }
            
            // Get counters
            $stmt = $this->conn->query("SELECT * FROM sewing_thread_counters");
            $backup_data['counters'] = [];
            while ($row = $stmt->fetch_assoc()) {
                $backup_data['counters'][] = $row;
            }
            
            // Get logs
            $stmt = $this->conn->query("SELECT * FROM sewing_thread_logs ORDER BY timestamp");
            $backup_data['logs'] = [];
            while ($row = $stmt->fetch_assoc()) {
                $backup_data['logs'][] = $row;
            }
            
            $backup_data['created_at'] = date('Y-m-d H:i:s');
            $backup_data['created_by'] = $this->reporter_name;
            
            return $backup_data;
        } catch (Exception $e) {
            error_log("Failed to create backup: " . $e->getMessage());
            return null;
        }
    }
}

// Initialize the handler
$sewingThreadHandler = new SewingThreadReportHandler($conn, $reporter_id, $reporter_name, $reporter_full_name);

// Fetch pending reports for admin/AGM Ops
$pending_reports = [];
$all_tester_reports = [];
$fetch_error = '';
if (in_array($roleLower, ['admin', 'agm ops', 'agm operations'])) {
    $pending_reports = $sewingThreadHandler->getPendingReports(20);
    // Also fetch all tester-submitted reports (pending, approved, rejected) for AGM
    try {
        // Ensure table exists first
        $conn->query("CREATE TABLE IF NOT EXISTS sewing_thread_reports (
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
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Fetch only pending reports (exclude approved tests)
        $query = "SELECT id, report_number, 
                         COALESCE(sample_received_from, 'N/A') AS client, 
                         COALESCE(sample_description, 'N/A') AS material, 
                         COALESCE(test_performed_by, 'N/A') AS tested_by, 
                         COALESCE(status, 'pending') AS status, 
                         created_at, 
                         updated_at,
                         COALESCE(reporter_name, 'N/A') AS reporter_name,
                         test_results
                  FROM sewing_thread_reports 
                  WHERE status != 'approved'
                  ORDER BY created_at DESC 
                  LIMIT 50";
        
        $result = $conn->query($query);
        if ($result && $result->num_rows > 0) {
            while ($r = $result->fetch_assoc()) { 
                // Decode test results JSON
                if (!empty($r['test_results'])) {
                    $r['test_results_decoded'] = json_decode($r['test_results'], true);
                } else {
                    $r['test_results_decoded'] = [];
                }
                $all_tester_reports[] = $r; 
            }
        } elseif ($result && $result->num_rows === 0) {
            // No reports found - this is fine, just empty array
            $all_tester_reports = [];
        } else {
            $fetch_error = "Query failed: " . $conn->error;
            error_log("Sewing Thread Report - Error fetching tester reports: " . $conn->error);
        }
    } catch (Exception $e) {
        $fetch_error = "Exception: " . $e->getMessage();
        error_log("Sewing Thread Report - Exception fetching all tester reports: " . $e->getMessage());
    }
}

// If not in edit mode, fetch last submitted report to pre-fill general information
$lastSubmittedData = null;
$stmt = $conn->prepare("SELECT * FROM sewing_thread_reports WHERE reporter_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param("i", $reporter_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $lastSubmittedData = $result->fetch_assoc();
}
$stmt->close();

// Generate report number for display (without incrementing) - ALWAYS fetch fresh from DB
$generated_report_number = $sewingThreadHandler->getNextReportNumber();

// Force fresh query by re-fetching to ensure real-time accuracy
$now_check = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
$hour_check = (int)$now_check->format('H');
$dayKey_check = $now_check->format('Ymd');
if ($hour_check < 8) {
    $yesterday_check = clone $now_check;
    $yesterday_check->modify('-1 day');
    $dayKey_check = $yesterday_check->format('Ymd');
}
$pattern_check = 'STR-' . $dayKey_check . '-%';
$fresh_count = $conn->query("SELECT COUNT(*) as cnt FROM sewing_thread_reports WHERE report_number LIKE '{$pattern_check}'");
if ($fresh_count && $row_check = $fresh_count->fetch_assoc()) {
    $next_num = (int)$row_check['cnt'] + 1;
    $generated_report_number = "STR-{$dayKey_check}-" . str_pad($next_num, 5, '0', STR_PAD_LEFT);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    // Generate the actual report number for this submission (this will increment the counter)
    $actual_report_number = $sewingThreadHandler->generateAndIncrementReportNumber();
    
    // Add the generated report number to the POST data
    $_POST['report_number'] = $actual_report_number;
    
    $result = $sewingThreadHandler->saveReport($_POST);
    if ($result['success']) {
        $user_role = strtolower(trim($_SESSION['role'] ?? ''));
        if (in_array($user_role, ['admin', 'agm ops', 'agm operations'])) {
            $message = "✅ Sewing Thread Report saved and auto-approved! Report Number: " . $actual_report_number;
        } else {
            $message = "✅ Sewing Thread Report submitted successfully! Report Number: " . $actual_report_number . " - Status: Pending Approval";
        }
        
        // Store success message in session and redirect to refresh dropdown
        $_SESSION['success_message'] = $message;
        $success_msg = urlencode($message);
        header("Location: sewing_thread_report.php?success=1&msg={$success_msg}&t=" . time());
        exit();
    } else {
        $error = $result['message'];
    }
}

// Handle approval requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approval_action'])) {
    $report_id = intval($_POST['report_id']);
    $action = $_POST['approval_action'];
    $comments = trim($_POST['approval_comments'] ?? '');
    
    $result = $sewingThreadHandler->approveReport($report_id, $action, $comments);
    if ($result['success']) {
        $_SESSION['success_message'] = $result['message'];
        header("Location: sewing_thread_report.php?success=1&t=" . time());
        exit();
    } else {
        $error = $result['message'];
    }
}

// Admin quick approve/reject by report number
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wf_action'])) {
    $wf_report_number = trim($_POST['wf_report_number'] ?? '');
    $wf_action = $_POST['wf_action'];
    $wf_comment = trim($_POST['wf_comment'] ?? '');
    
    // Handle rejection reasons checkboxes for admin
    if ($wf_action === 'rejected' && isset($_POST['st_rejection_reasons']) && is_array($_POST['st_rejection_reasons'])) {
        $rejection_reasons = array_map('trim', $_POST['st_rejection_reasons']);
        $reasons_text = implode(', ', $rejection_reasons);
        // Prepend reasons to comment
        $wf_comment = "Rejection Reasons: " . $reasons_text . ($wf_comment ? "\n\nAdditional Comments: " . $wf_comment : '');
    }
    
    if ($wf_report_number === '') {
        $error = '❌ Error: Report Number is required for approval.';
    } else {
        $result = $sewingThreadHandler->approveOrRejectByReportNumber($wf_report_number, $wf_action, $wf_comment);
        if ($result['success']) {
            $_SESSION['success_message'] = $result['message'];
            header("Location: sewing_thread_report.php?success=1&t=" . time());
            exit();
        } else {
            $error = $result['message'];
        }
    }
}

// Get report statistics
$report_stats = $sewingThreadHandler->getReportStats();

// Get approvers list
$approvers = $sewingThreadHandler->getApprovers();

// Test parameters with their standards and units
$test_parameters = [
    1 => ['param' => 'Denier', 'standards' => ['ISO 2060'], 'unit' => 'dTex'],
    2 => ['param' => 'Tenacity at Break', 'standards' => ['ASTM D2256'], 'unit' => 'cN/dTex'],
    3 => ['param' => 'Std Deviation', 'standards' => ['ASTM D2256'], 'unit' => 'cN/dTex'],
    4 => ['param' => 'CV%', 'standards' => ['ASTM D2256'], 'unit' => '%'],
    5 => ['param' => 'Elongation at Break', 'standards' => ['ASTM D2256'], 'unit' => '%']
];

// Unit options for dropdown
$unit_options = ['dTex', 'cN/dTex', '%'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sewing Thread Report</title>
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
  <h1> Sewing Thread Report</h1>

  <?php 
  // Check for session-based success message (from edit page)
  if (isset($_SESSION['update_success'])) {
      $message = $_SESSION['update_success'];
      unset($_SESSION['update_success']);
  }
  ?>
  
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

  <?php if (in_array($roleLower, ['admin','agm ops','agm operations']) && !empty($all_tester_reports)): ?>
  <!-- All Tester Submitted Reports -->
    <div style="margin-top:16px; padding:12px; border:1px solid #ddd; border-radius:8px; background:#fff;">
      <h3 style="margin:0 0 12px 0;">Tester Submitted Reports</h3>
      <?php if ($fetch_error): ?>
      <div style="padding: 12px; background: #f8d7da; color: #721c24; border-radius: 4px; margin-bottom: 12px;">
        <strong>Error:</strong> <?php echo htmlspecialchars($fetch_error); ?>
      </div>
      <?php endif; ?>
      <?php if (!empty($all_tester_reports)): ?>
      <table class="test-table" style="width: 100%; border-collapse: collapse;">
        <thead>
          <tr>
            <th style="border: 1px solid #ddd; padding: 8px; background: #3498db; color: #fff;">Report No</th>
            <th style="border: 1px solid #ddd; padding: 8px; background: #3498db; color: #fff;">Material</th>
            <th style="border: 1px solid #ddd; padding: 8px; background: #3498db; color: #fff;">Tested By</th>
            <th style="border: 1px solid #ddd; padding: 8px; background: #3498db; color: #fff;">Test Results</th>
            <th style="border: 1px solid #ddd; padding: 8px; background: #3498db; color: #fff;">Status</th>
            <th style="border: 1px solid #ddd; padding: 8px; background: #3498db; color: #fff;">Submitted Date</th>
            <th style="border: 1px solid #ddd; padding: 8px; background: #3498db; color: #fff; width:200px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          // Test parameters for display
          $test_params_display = [
            1 => 'Denier',
            2 => 'Tenacity at Break',
            3 => 'Std Deviation',
            4 => 'CV%',
            5 => 'Elongation at Break'
          ];
          foreach ($all_tester_reports as $pr): 
            $test_results = $pr['test_results_decoded'] ?? [];
          ?>
          <tr>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo htmlspecialchars($pr['report_number'] ?? 'N/A'); ?></td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo htmlspecialchars($pr['material'] ?? 'N/A'); ?></td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo htmlspecialchars($pr['tested_by'] ?? 'N/A'); ?></td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: left; max-width: 300px;">
              <?php if (!empty($test_results) && is_array($test_results)): ?>
                <div style="font-size: 12px; line-height: 1.6;">
                  <?php 
                  foreach ($test_results as $result): 
                    $sl_no = $result['sl_no'] ?? $result['slNo'] ?? null;
                    $param_name = $result['parameter'] ?? $result['param'] ?? '';
                    $test_result = $result['test_result'] ?? $result['result'] ?? '';
                    $unit = $result['unit'] ?? '';
                    
                    if ($sl_no && isset($test_params_display[$sl_no])) {
                      $param_name = $test_params_display[$sl_no];
                    }
                    
                    if (!empty($test_result) || !empty($param_name)):
                  ?>
                    <div style="margin-bottom: 4px;">
                      <strong><?php echo htmlspecialchars($param_name); ?>:</strong> 
                      <?php echo htmlspecialchars($test_result); ?>
                      <?php if (!empty($unit)): ?>
                        <span style="color: #7f8c8d;"><?php echo htmlspecialchars($unit); ?></span>
                      <?php endif; ?>
                    </div>
                  <?php 
                    endif;
                  endforeach; 
                  ?>
                </div>
              <?php else: ?>
                <span style="color: #7f8c8d; font-style: italic;">No test results</span>
              <?php endif; ?>
            </td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
              <span style="padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600;
                <?php 
                if (($pr['status'] ?? '') === 'approved') echo 'background: #d4edda; color: #155724;';
                elseif (($pr['status'] ?? '') === 'rejected') echo 'background: #f8d7da; color: #721c24;';
                else echo 'background: #fff3cd; color: #856404;';
                ?>">
                <?php echo strtoupper(htmlspecialchars($pr['status'] ?? 'UNKNOWN')); ?>
              </span>
            </td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?php echo htmlspecialchars($pr['created_at'] ?? 'N/A'); ?></td>
            <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
              <a href="../admin/view_sewing_report.php?id=<?php echo $pr['id'] ?? 0; ?>" target="_blank" class="submit-btn" style="padding:6px 10px; text-decoration:none; display:inline-block; background:#3498db; margin-right:4px; color: #fff; border-radius: 4px;">View</a>
              <?php if (($pr['status'] ?? '') === 'pending'): ?>
              <form method="POST" action="" style="display:inline; margin-right:4px;" onsubmit="return confirmApproval(this);">
                <input type="hidden" name="wf_report_number" value="<?php echo htmlspecialchars($pr['report_number'] ?? ''); ?>">
                <input type="hidden" name="wf_comment" value="Approved from queue">
                <button type="submit" name="wf_action" value="approved" class="submit-btn" style="padding:6px 10px; background: #2ecc71; color: #fff; border: none; border-radius: 4px; cursor: pointer;">Approve</button>
              </form>
              <button type="button" onclick="openSTRejectModal('<?php echo htmlspecialchars($pr['report_number'] ?? ''); ?>')" class="clear-btn" style="padding:6px 10px; border:none; cursor:pointer; background: #e74c3c; color: #fff; border-radius: 4px;">Reject</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!$sewingThreadHandler->canApproveReports()): ?>
  <!-- Rejected Reports for Tester to Review -->
  <?php $rejected = $sewingThreadHandler->getRejectedReportsForUser($reporter_id); if (!empty($rejected)): ?>
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
            <a href="edit_sewing_report.php?id=<?php echo $rj['id']; ?>" class="submit-btn" style="padding:6px 10px; text-decoration:none; display:inline-block; background:#ff9800; color:#fff;">Edit & Resubmit</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin:12px 0 0 0; color:#856404; font-style:italic;">💡 Note: Click "Edit & Resubmit" to modify the rejected report, or submit a new report using the form below.</p>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <form method="POST" action="">
    <!-- Date/Time and Shift Display -->
    <div id="dateTimeDisplay" class="summary-info"></div>
    <div id="shiftBanner" class="summary-info"></div>

    <!-- Hidden fields -->
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">

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
              echo ($lastSubmittedData && $lastSubmittedData['store_entry_reference'] === $entry['entry_number']) ? 'selected' : '';
            ?>>
              <?php echo htmlspecialchars($entry['entry_number']); ?> - 
              <?php echo htmlspecialchars($entry['material_type']); ?> 
              (<?php echo number_format($entry['amount_kg'], 2); ?> kg) - 
              <?php echo date('d M Y', strtotime($entry['received_date'])); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small style="color: #7f8c8d; font-size: 0.85em;">Select the material from store that you are testing</small>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Sample Description:</label>
        <input type="text" name="sample_description" value="<?php echo $lastSubmittedData ? htmlspecialchars($lastSubmittedData['sample_description'] ?? '') : ''; ?>" required>
      </div>
      <div class="form-group">
        <label>Ref:</label>
        <input type="text" name="reference" value="<?php echo $lastSubmittedData ? htmlspecialchars($lastSubmittedData['reference'] ?? '') : ''; ?>">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Sample Received From:</label>
        <input type="text" name="sample_received_from" value="<?php echo $lastSubmittedData ? htmlspecialchars($lastSubmittedData['sample_received_from'] ?? '') : ''; ?>" required>
      </div>
      <div class="form-group">
        <label>Sample Collected From:</label>
        <input type="text" name="sample_collected_from" value="<?php echo $lastSubmittedData ? htmlspecialchars($lastSubmittedData['sample_collected_from'] ?? '') : ''; ?>" required>
      </div>
    </div>


    <div class="form-row">
      <div class="form-group">
        <label>Received Date:</label>
        <input type="datetime-local" name="received_date" value="<?php 
          if ($lastSubmittedData && !empty($lastSubmittedData['received_date'])) {
              echo date('Y-m-d\TH:i', strtotime($lastSubmittedData['received_date']));
          }
        ?>" required>
      </div>
      <div class="form-group">
        <label>Test Start Date:</label>
        <input type="date" name="test_start_date" value="<?php echo $lastSubmittedData ? htmlspecialchars($lastSubmittedData['test_start_date'] ?? '') : ''; ?>" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Test End Date:</label>
        <input type="date" name="test_end_date" value="<?php echo $lastSubmittedData ? htmlspecialchars($lastSubmittedData['test_end_date'] ?? '') : ''; ?>" required>
      </div>
      <div class="form-group">
        <!-- Empty space for layout balance -->
      </div>
    </div>

    <div class="form-group">
      <label>Others Information:</label>
      <textarea name="others_information" rows="3" placeholder="Enter additional information (optional)"><?php echo $lastSubmittedData ? htmlspecialchars($lastSubmittedData['others_information'] ?? '') : ''; ?></textarea>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Test Temperature (°C):</label>
        <input type="number" name="test_temperature" step="0.01" max="1000" min="0" value="<?php echo $lastSubmittedData ? htmlspecialchars($lastSubmittedData['test_temperature'] ?? '') : ''; ?>" required oninput="if(this.value < 0) this.value = 0">
      </div>
      <div class="form-group">
        <label>RH%:</label>
        <input type="number" name="rh_percent" step="0.01" max="100" min="0" value="<?php echo $lastSubmittedData ? htmlspecialchars($lastSubmittedData['rh_percent'] ?? '') : ''; ?>" required oninput="if(this.value < 0) this.value = 0">
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
        <tr>
          <td><?php echo $sl_no; ?></td>
          <td><?php echo htmlspecialchars($param['param']); ?></td>
          <td><?php echo htmlspecialchars($param['standards'][0]); ?></td>
          <td>
            <select name="test_results[<?php echo $sl_no; ?>][unit]" required>
              <option value="">Select Unit</option>
              <?php foreach ($unit_options as $unit): ?>
              <option value="<?php echo htmlspecialchars($unit); ?>" <?php echo ($unit === $param['unit']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($unit); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <input type="number" step="0.01" name="test_results[<?php echo $sl_no; ?>][result]">
          </td>
          <td><input type="text" name="test_results[<?php echo $sl_no; ?>][remarks]"></td>
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
    
    // Auto-fill received date with current date/time
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const currentDateTime = `${year}-${month}-${day}T${hours}:${minutes}`;
    
    document.querySelector('input[name="received_date"]').value = currentDateTime;

    // Attach realtime validation to date fields
    const startInput = document.querySelector('input[name="test_start_date"]');
    const endInput = document.querySelector('input[name="test_end_date"]');
    const formEl = document.querySelector('form');

    function isEndBeforeStart() {
        const startVal = startInput?.value || '';
        const endVal = endInput?.value || '';
        if (!startVal || !endVal) return false;
        // Dates are yyyy-mm-dd strings; string compare works, but be explicit
        const start = new Date(startVal);
        const end = new Date(endVal);
        return end < start;
    }

    function validateDatesOrAlert() {
        if (isEndBeforeStart()) {
            alert('Test End Date cannot be earlier than Test Start Date. Please correct the dates.');
            // Focus the end date for correction
            endInput.focus();
            return false;
        }
        return true;
    }

    // On change of either field, validate immediately
    startInput?.addEventListener('change', validateDatesOrAlert);
    endInput?.addEventListener('change', validateDatesOrAlert);

    // On submit, block submission if invalid
    formEl?.addEventListener('submit', function(e) {
        if (!validateDatesOrAlert()) {
            e.preventDefault();
            e.stopPropagation();
        }
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
        
        // Reset dropdown to default option
        document.querySelector('select[name="approved_by"]').selectedIndex = 0;
        
        // Auto-fill received date with current date/time
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

// Sewing Thread rejection modal functions
function openSTRejectModal(reportNumber) {
    document.getElementById('stRejectReportNumber').value = reportNumber;
    document.getElementById('stRejectionModal').style.display = 'block';
}

function closeSTRejectModal() {
    document.getElementById('stRejectionModal').style.display = 'none';
    document.getElementById('stAdminRejectForm').reset();
}

function submitSTAdminRejection() {
    const checkboxes = document.querySelectorAll('input[name="st_rejection_reasons[]"]');
    const checked = Array.from(checkboxes).filter(cb => cb.checked);
    
    if (checked.length === 0) {
        alert('❌ Please select at least one reason for rejection!');
        return false;
    }
    
    if (confirm('Are you sure you want to reject this report?')) {
        const form = document.getElementById('stAdminRejectForm');
        form.onsubmit = null; // Remove the return false
        form.submit();
    }
}
</script>

<!-- Rejection Modal for Admin (Sewing Thread Report) -->
<div id="stRejectionModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow-y:auto;">
  <div style="max-width:600px; margin:50px auto; background:#fff; border-radius:8px; padding:25px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
    <h3 style="margin-top:0; color:#dc3545; border-bottom:2px solid #dc3545; padding-bottom:10px;">
      ❌ Reject Sewing Thread Report
    </h3>
    
    <form id="stAdminRejectForm" method="POST" action="" onsubmit="return false;">
      <input type="hidden" id="stRejectReportNumber" name="wf_report_number" value="">
      <input type="hidden" name="wf_action" value="rejected">
      
      <label style="font-weight:600; display:block; margin-bottom:10px;">Reason for Rejection (Select at least one):</label>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="st_rejection_reasons[]" value="Incorrect Roll Identification" style="margin-right:8px;">
          Incorrect Roll Identification
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="st_rejection_reasons[]" value="Incorrect Fiber Specification Entry" style="margin-right:8px;">
          Incorrect Fiber Specification Entry
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="st_rejection_reasons[]" value="Excessive Sampling" style="margin-right:8px;">
          Excessive Sampling
        </label>
      </div>
      
      <label style="font-weight:bold; display:block; margin:15px 0 8px 0;">
        Additional Comments (Optional):
      </label>
      <textarea name="wf_comment" id="stAdminRejectComment" rows="4" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-family:inherit;" placeholder="Provide additional details..."></textarea>
      
      <div style="margin-top:20px; text-align:right;">
        <button type="button" onclick="closeSTRejectModal()" style="padding:10px 20px; margin-right:10px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer;">Cancel</button>
        <button type="button" onclick="submitSTAdminRejection()" style="padding:10px 20px; background:#dc3545; color:#fff; border:none; border-radius:6px; cursor:pointer;">Submit Rejection</button>
      </div>
    </form>
  </div>
</div>

</body>
</html>
</html>
