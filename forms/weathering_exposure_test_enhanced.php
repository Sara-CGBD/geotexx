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

$message = '';
$error = '';

/**
 * Enhanced UV Test Report Handler Class
 */
class WeatheringExposureTestHandler {
    private $conn;
    private $reporter_id;
    private $reporter_name;
    
    public function __construct($connection, $reporter_id, $reporter_name) {
        $this->conn = $connection;
        $this->reporter_id = $reporter_id;
        $this->reporter_name = $reporter_name;
    }
    
    /**
     * Get next report number for display (without incrementing)
     */
    public function getNextReportNumber() {
        $now = new DateTime();
        $year = $now->format('Y');
        $month = $now->format('m');
        $day = $now->format('d');
        $hour = (int)$now->format('H');
        
        // Calculate the day key for 8 AM reset cycle
        $dayKey = $year . $month . $day;
        
        // If it's before 8 AM, use previous day's key
        if ($hour < 8) {
            $yesterday = clone $now;
            $yesterday->modify('-1 day');
            $dayKey = $yesterday->format('Y') . $yesterday->format('m') . $yesterday->format('d');
        }
        
        // Create counter table if not exists
        $this->createCounterTable();
        
        // Get current counter for this day
        $stmt = $this->conn->prepare("SELECT counter FROM weathering_exposure_counters WHERE day_key = ?");
        $stmt->bind_param("s", $dayKey);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $counter = 1;
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $counter = $row['counter'] + 1;
        }
        
        $stmt->close();
        
        // Generate report number: WET-YYYYMMDD-XXXXX
        return "UV-{$dayKey}-" . str_pad($counter, 5, '0', STR_PAD_LEFT);
    }
    
    /**
     * Generate and increment report number (only on submission)
     */
    public function generateAndIncrementReportNumber() {
        $now = new DateTime();
        $year = $now->format('Y');
        $month = $now->format('m');
        $day = $now->format('d');
        $hour = (int)$now->format('H');
        
        // Calculate the day key for 8 AM reset cycle
        $dayKey = $year . $month . $day;
        
        // If it's before 8 AM, use previous day's key
        if ($hour < 8) {
            $yesterday = clone $now;
            $yesterday->modify('-1 day');
            $dayKey = $yesterday->format('Y') . $yesterday->format('m') . $yesterday->format('d');
        }
        
        // Create counter table if not exists
        $this->createCounterTable();
        
        // Atomic increment operation
        $stmt = $this->conn->prepare("
            INSERT INTO weathering_exposure_counters (day_key, counter) 
            VALUES (?, 1) 
            ON DUPLICATE KEY UPDATE counter = counter + 1
        ");
        $stmt->bind_param("s", $dayKey);
        $stmt->execute();
        
        // Get the updated counter
        $selectStmt = $this->conn->prepare("SELECT counter FROM weathering_exposure_counters WHERE day_key = ?");
        $selectStmt->bind_param("s", $dayKey);
        $selectStmt->execute();
        $result = $selectStmt->get_result();
        $row = $result->fetch_assoc();
        $counter = $row['counter'];
        
        $stmt->close();
        $selectStmt->close();
        
        // Generate report number: WET-YYYYMMDD-XXXXX
        return "WET-{$dayKey}-" . str_pad($counter, 5, '0', STR_PAD_LEFT);
    }
    
    /**
     * Create counter table
     */
    private function createCounterTable() {
        $createCounterTable = "CREATE TABLE IF NOT EXISTS weathering_exposure_counters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            day_key VARCHAR(8) UNIQUE NOT NULL,
            counter INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $this->conn->query($createCounterTable);
    }
    
    /**
     * Validate form data
     */
    public function validateFormData($data) {
        $errors = [];
        
        // Required fields validation
        $required_fields = [
            'sample_received_from', 'sample_collected_from', 'reference', 
            'sample_description', 'recipe', 'received_date', 'test_start_date', 
            'test_end_date', 'testing_method', 'test_name', 'test_speed', 
            'gauge_length', 'specimen_size', 'temperature', 'rh_percent', 
            'test_performed_by', 'approved_by'
        ];
        
        foreach ($required_fields as $field) {
            if (empty(trim($data[$field]))) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        
        // Date validation
        if (!empty($data['test_start_date']) && !empty($data['test_end_date'])) {
            $start_date = new DateTime($data['test_start_date']);
            $end_date = new DateTime($data['test_end_date']);
            
            if ($end_date < $start_date) {
                $errors[] = 'Test End Date cannot be earlier than Test Start Date';
            }
        }
        
        // Numeric validation
        if (!empty($data['temperature']) && (!is_numeric($data['temperature']) || $data['temperature'] < 0)) {
            $errors[] = 'Temperature must be a positive number';
        }
        
        if (!empty($data['rh_percent']) && (!is_numeric($data['rh_percent']) || $data['rh_percent'] < 0 || $data['rh_percent'] > 100)) {
            $errors[] = 'RH% must be between 0 and 100';
        }
        
        return $errors;
    }
    
    /**
     * Save report to database
     */
    public function saveReport($data) {
        try {
            $this->conn->begin_transaction();
            
            // Create main table if not exists
            $this->createMainTable();
            
            // Generate report number
            $report_number = $this->generateAndIncrementReportNumber();
            
            // Check for duplicate report number
            $checkStmt = $this->conn->prepare("SELECT id FROM weathering_exposure_reports WHERE report_number = ?");
            $checkStmt->bind_param("s", $report_number);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows > 0) {
                throw new Exception("Report number already exists. Please try again.");
            }
            $checkStmt->close();
            
            // Prepare test results data
            $test_results = [];
            for ($i = 1; $i <= 10; $i++) {
                $test_results[] = [
                    'specimen_no' => $i,
                    'test_direction' => $data["test_direction_$i"] ?? '',
                    'breaking_force_after' => $data["breaking_force_after_$i"] ?? '',
                    'breaking_force_before' => $data["breaking_force_before_$i"] ?? '',
                    'force_retain' => $data["force_retain_$i"] ?? '',
                    'elongation_after' => $data["elongation_after_$i"] ?? '',
                    'elongation_before' => $data["elongation_before_$i"] ?? ''
                ];
            }
            
            $test_results_json = json_encode($test_results);
            
            // Insert report
            $stmt = $this->conn->prepare("
                INSERT INTO weathering_exposure_reports (
                    report_number, sample_received_from, sample_collected_from, reference, 
                    sample_description, recipe, received_date, test_start_date, test_end_date, 
                    testing_method, test_name, test_speed, gauge_length, specimen_size, note, 
                    temperature, rh_percent, test_performed_by, approved_by, test_results, 
                    reporter_id, reporter_name, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            
            $stmt->bind_param("sssssssssssssssddssssis", 
                $report_number, $data['sample_received_from'], $data['sample_collected_from'], 
                $data['reference'], $data['sample_description'], $data['recipe'], 
                $data['received_date'], $data['test_start_date'], $data['test_end_date'], 
                $data['testing_method'], $data['test_name'], $data['test_speed'], 
                $data['gauge_length'], $data['specimen_size'], $data['note'], 
                $data['temperature'], $data['rh_percent'], $data['test_performed_by'], 
                $data['approved_by'], $test_results_json, $this->reporter_id, $this->reporter_name
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Error saving report: " . $stmt->error);
            }
            
            $report_id = $this->conn->insert_id;
            $stmt->close();
            
            // Log the action
            $this->logAction('report_created', $report_id, "Report created with number: $report_number");
            
            $this->conn->commit();
            return ['success' => true, 'report_number' => $report_number, 'report_id' => $report_id];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Create main table
     */
    private function createMainTable() {
        $createTable = "CREATE TABLE IF NOT EXISTS weathering_exposure_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_number VARCHAR(100) UNIQUE NOT NULL,
            sample_received_from VARCHAR(255) NOT NULL,
            sample_collected_from VARCHAR(255) NOT NULL,
            reference VARCHAR(255) NOT NULL,
            sample_description TEXT NOT NULL,
            recipe VARCHAR(255) NOT NULL,
            received_date DATETIME NOT NULL,
            test_start_date DATE NOT NULL,
            test_end_date DATE NOT NULL,
            testing_method VARCHAR(255) NOT NULL,
            test_name VARCHAR(255) NOT NULL,
            test_speed VARCHAR(255) NOT NULL,
            gauge_length VARCHAR(255) NOT NULL,
            specimen_size VARCHAR(255) NOT NULL,
            note TEXT,
            temperature DECIMAL(10,2) NOT NULL,
            rh_percent DECIMAL(5,2) NOT NULL,
            test_performed_by VARCHAR(100) NOT NULL,
            approved_by VARCHAR(100) NOT NULL,
            test_results JSON NOT NULL,
            reporter_id INT NOT NULL,
            reporter_name VARCHAR(255) NOT NULL,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_report_number (report_number),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at),
            INDEX idx_reporter_id (reporter_id)
        )";
        $this->conn->query($createTable);
    }
    
    /**
     * Get approvers (admin and AGM Ops users)
     */
    public function getApprovers() {
        $stmt = $this->conn->prepare("
            SELECT username, role 
            FROM users 
            WHERE role IN ('admin', 'AGM Ops') 
            ORDER BY role, username
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $approvers = [];
        while ($row = $result->fetch_assoc()) {
            $approvers[] = [
                'name' => $row['username'],
                'role' => $row['role']
            ];
        }
        $stmt->close();
        
        return $approvers;
    }
    
    /**
     * Check if current user can approve reports
     */
    public function canApproveReports() {
        $stmt = $this->conn->prepare("
            SELECT role FROM users WHERE username = ? AND role IN ('admin', 'AGM Ops')
        ");
        $stmt->bind_param("s", $this->reporter_name);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $can_approve = $result->num_rows > 0;
        $stmt->close();
        
        return $can_approve;
    }
    
    /**
     * Log action
     */
    private function logAction($action, $report_id, $details = '') {
        $this->createLogTable();
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt = $this->conn->prepare("
            INSERT INTO weathering_exposure_logs (report_id, action, details, user_id, username, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issssss", $report_id, $action, $details, $this->reporter_id, $this->reporter_name, $ip_address, $user_agent);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * Create log table
     */
    private function createLogTable() {
        $createLogTable = "CREATE TABLE IF NOT EXISTS weathering_exposure_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_id INT,
            action VARCHAR(100) NOT NULL,
            details TEXT,
            user_id INT,
            username VARCHAR(255),
            ip_address VARCHAR(45),
            user_agent TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_report_id (report_id),
            INDEX idx_action (action),
            INDEX idx_user_id (user_id),
            INDEX idx_created_at (created_at)
        )";
        $this->conn->query($createLogTable);
    }
}

// Initialize handler
$handler = new WeatheringExposureTestHandler($conn, $reporter_id, $reporter_name);

// Fetch reference numbers from fiber_to_roll_entry that don't have UV reports yet
$references = [];
$refQuery = $conn->query("
    SELECT DISTINCT f.reference_number 
    FROM fiber_to_roll_entry f
    WHERE f.reference_number IS NOT NULL 
    AND f.reference_number NOT IN (
        SELECT DISTINCT reference 
        FROM weathering_exposure_reports 
        WHERE reference IS NOT NULL AND reference != ''
    )
    ORDER BY f.date_time DESC 
    LIMIT 50
");
if ($refQuery) {
    while ($row = $refQuery->fetch_assoc()) {
        $references[] = $row['reference_number'];
    }
}

// Get next report number for display
$generated_report_number = $handler->getNextReportNumber();

// Get approvers for dropdown
$approvers = $handler->getApprovers();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    $validation_errors = $handler->validateFormData($_POST);
    
    if (empty($validation_errors)) {
        $result = $handler->saveReport($_POST);
        
        if ($result['success']) {
            $message = "UV Test Report saved successfully! Report Number: " . $result['report_number'];
            // Regenerate report number for next form
            $generated_report_number = $handler->getNextReportNumber();
        } else {
            $error = "❌ Error: " . $result['error'];
        }
    } else {
        $error = "❌ Error: Validation failed: " . implode(', ', $validation_errors);
    }
}
?>

