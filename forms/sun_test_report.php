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

// Allow tester, admin, AGM Ops, and management access
$__role = strtolower(trim($_SESSION['role'] ?? ''));
$__allowed = ['tester', 'admin', 'agm ops', 'agm operations', 'management'];

if (!in_array($__role, $__allowed, true)) {
    die('Access denied. Only testers, admin, AGM Ops, and management can access this page.');
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
        $reporter_full_name = $row['full_name'] ?: $reporter_name; // Use full_name if available, fallback to username
    }
    $stmt->close();
} catch (Exception $e) {
    // If full_name column doesn't exist yet, just use username
    $reporter_full_name = $reporter_name;
}

$message = '';
$error = '';

// Check for success message from session (after redirect)
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

/**
 * Sun Test Report Handler Class
 */
class SunTestReportHandler {
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
    
    /**
     * Get next report number for display (without incrementing)
     */
    public function getNextReportNumber() {
        $now = new DateTime();
        $year = $now->format('Y');
        $month = $now->format('m');
        $day = $now->format('d');
        $hour = (int)$now->format('H');
        
        // Calculate the day key for 8:00 AM reset cycle
        $dayKey = $year . $month . $day;
        
        // If it's before 8:00 AM, use previous day's key
        if ($hour < 8) {
            $yesterday = clone $now;
            $yesterday->modify('-1 day');
            $dayKey = $yesterday->format('Y') . $yesterday->format('m') . $yesterday->format('d');
        }
        
        // Ensure main table exists before counting
        $this->createMainTable();

        // Derive next number from actual saved reports to avoid inflated counters
        $likePrefix = "SUN-{$dayKey}-%";
        $countStmt = $this->conn->prepare("SELECT COUNT(*) AS num_reports FROM sun_test_reports WHERE report_number LIKE ?");
        $countStmt->bind_param("s", $likePrefix);
        $countStmt->execute();
        $countRes = $countStmt->get_result();
        $countRow = $countRes->fetch_assoc();
        $numReportsToday = (int)($countRow['num_reports'] ?? 0);
        $countStmt->close();

        $nextSeq = $numReportsToday + 1; // show 1 if none submitted, else next
        
        // Generate report number: SUN-YYYYMMDD-XXXXX
        return "SUN-{$dayKey}-" . str_pad($nextSeq, 5, '0', STR_PAD_LEFT);
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
        
        // Calculate the day key for 8:00 AM reset cycle
        $dayKey = $year . $month . $day;
        
        // If it's before 8:00 AM, use previous day's key
        if ($hour < 8) {
            $yesterday = clone $now;
            $yesterday->modify('-1 day');
            $dayKey = $yesterday->format('Y') . $yesterday->format('m') . $yesterday->format('d');
        }
        
        // Create counter table if not exists
        $this->createCounterTable();
        
        // Create counter table if not exists
        $this->createCounterTable();

        // Ensure main table exists before counting and counter sync
        $this->createMainTable();

        // Ensure counter is at least current number of saved reports + 1, then increment atomically
        $likePrefix = "SUN-{$dayKey}-%";
        $countStmt = $this->conn->prepare("SELECT COUNT(*) AS num_reports FROM sun_test_reports WHERE report_number LIKE ?");
        $countStmt->bind_param("s", $likePrefix);
        $countStmt->execute();
        $countRes = $countStmt->get_result();
        $countRow = $countRes->fetch_assoc();
        $numReportsToday = (int)($countRow['num_reports'] ?? 0);
        $countStmt->close();

        // Initialize or bump counter
        $initStmt = $this->conn->prepare("
            INSERT INTO sun_test_counters (day_key, counter) VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE counter = GREATEST(counter, VALUES(counter))
        ");
        $targetStart = max(0, $numReportsToday); // store last used value; will add +1 next
        $initStmt->bind_param("si", $dayKey, $targetStart);
        $initStmt->execute();
        $initStmt->close();

        // Atomic increment
        $incStmt = $this->conn->prepare("UPDATE sun_test_counters SET counter = counter + 1 WHERE day_key = ?");
        $incStmt->bind_param("s", $dayKey);
        $incStmt->execute();
        $incStmt->close();

        // Read back
        $selectStmt = $this->conn->prepare("SELECT counter FROM sun_test_counters WHERE day_key = ?");
        $selectStmt->bind_param("s", $dayKey);
        $selectStmt->execute();
        $result = $selectStmt->get_result();
        $row = $result->fetch_assoc();
        $counter = (int)($row['counter'] ?? 1);
        $selectStmt->close();
        
        // Generate report number: UV-YYYYMMDD-XXXXX
        return "SUN-{$dayKey}-" . str_pad($counter, 5, '0', STR_PAD_LEFT);
    }
    
    /**
     * Create counter table
     */
    private function createCounterTable() {
        $createCounterTable = "CREATE TABLE IF NOT EXISTS sun_test_counters (
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
            'reference_name', 'lab_test_number',
            'sample_description', 'received_date', 
            'test_start_date', 'test_end_date', 'testing_method', 'test_name', 
            'test_speed', 'gauge_length', 'specimen_size', 'temperature', 
            'rh_percent', 'test_performed_by'
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
            $checkStmt = $this->conn->prepare("SELECT id FROM sun_test_reports WHERE report_number = ?");
            $checkStmt->bind_param("s", $report_number);
            $checkStmt->execute();
            $result = $checkStmt->get_result();
            
            if ($result->num_rows > 0) {
                throw new Exception("Report number already exists. Please try again.");
            }
            $checkStmt->close();
            
            // Prepare test results data (dynamic specimens)
            $test_results = [];
            for ($i = 1; $i <= 20; $i++) {
                if (!empty($data["breaking_force_after_$i"]) || !empty($data["breaking_force_before_$i"])) {
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
            }
            
            $test_results_json = json_encode($test_results);
            
            // Auto-approve if submitted by admin or AGM Ops
            $user_role = strtolower(trim($_SESSION['role'] ?? ''));
            $status = ($user_role === 'admin' || $user_role === 'agm ops' || $user_role === 'agm operations') ? 'approved' : 'pending';
            
            // Determine approved_by value
            $approved_by_value = null;
            if ($user_role === 'admin' || $user_role === 'agm ops' || $user_role === 'agm operations') {
                $approved_by_value = $this->reporter_full_name;
            }
            
            // Insert report
            // Generate individual roll references from from-to range (same as QC test order and characteristics test)
            $bulk_rolls = [];
            if (isset($data['from_reference']) && isset($data['to_reference']) && 
                !empty($data['from_reference']) && !empty($data['to_reference'])) {
                
                $fromRef = trim($data['from_reference']);
                $toRef = trim($data['to_reference']);
                
                // Extract base reference and roll numbers
                $fromBaseRef = '';
                $fromRollNum = 0;
                $toBaseRef = '';
                $toRollNum = 0;
                
                if (preg_match('/^(.+)-(\d+)$/', $fromRef, $fromMatches)) {
                    $fromBaseRef = $fromMatches[1];
                    $fromRollNum = (int)$fromMatches[2];
                } else {
                    $fromBaseRef = $fromRef;
                    $fromRollNum = 1;
                }
                
                if (preg_match('/^(.+)-(\d+)$/', $toRef, $toMatches)) {
                    $toBaseRef = $toMatches[1];
                    $toRollNum = (int)$toMatches[2];
                } else {
                    $toBaseRef = $toRef;
                    $toRollNum = 1;
                }
                
                // If same base, generate all references from fromRollNum to toRollNum
                if ($fromBaseRef === $toBaseRef && $fromRollNum > 0 && $toRollNum > 0) {
                    for ($roll = $fromRollNum; $roll <= $toRollNum; $roll++) {
                        $bulk_rolls[] = $fromBaseRef . '-' . $roll;
                    }
                    error_log("Sun Test: Generated " . count($bulk_rolls) . " individual references from range: " . $fromRef . " to " . $toRef);
                } else {
                    // Different bases - add both endpoints
                    $bulk_rolls[] = $fromRef;
                    if ($toRef !== $fromRef) {
                        $bulk_rolls[] = $toRef;
                    }
                    error_log("Sun Test: WARNING - Different base references in range. Generated " . count($bulk_rolls) . " references.");
                }
            }
            
            // Get reference number - use individual roll reference if bundle is selected
            $reference_number = '';
            $bundle_reference = null;
            
            if (isset($data['individual_roll_reference']) && !empty($data['individual_roll_reference'])) {
                $reference_number = trim($data['individual_roll_reference']);
                // Get bundle reference if individual roll is selected
                if (isset($data['reference_number']) && !empty($data['reference_number'])) {
                    $bundle_reference = trim($data['reference_number']); // Original bundle reference
                }
            } elseif (!empty($bulk_rolls)) {
                // Range selected - bundle_reference will be set for all rolls
                $bundle_reference = trim($data['from_reference']) . '|' . trim($data['to_reference']);
                // reference_number will be set per roll in the loop
            } elseif (isset($data['reference_number']) && !empty($data['reference_number'])) {
                $reference_number = trim($data['reference_number']);
                // Check if this is a range format (from|to)
                if (strpos($reference_number, '|') !== false) {
                    $parts = explode('|', $reference_number);
                    if (count($parts) === 2) {
                        $reference_number = trim($parts[0]); // Use from reference
                        $bundle_reference = $reference_number; // Store the range format
                    }
                }
            }
            $reference_name = $data['reference_name'] ?? '';
            $sample_received_from = $data['sample_received_from'] ?? '';
            $sample_collected_from = $data['sample_collected_from'] ?? '';
            
            // Process each roll in the range (or single reference)
            $rolls_to_process = !empty($bulk_rolls) ? $bulk_rolls : [];
            if (empty($rolls_to_process) && !empty($reference_number)) {
                $rolls_to_process = [$reference_number];
            }
            
            if (empty($rolls_to_process)) {
                throw new Exception("No reference selected. Please select a reference or range.");
            }
            
            $stmt = $this->conn->prepare(
                "INSERT INTO sun_test_reports (
                    report_number, reference_number, bundle_reference, reference_name, lab_test_number,
                    sample_description, sample_received_from, sample_collected_from, sample_production_date, received_date, 
                    test_start_date, test_end_date, testing_method, test_name, 
                    test_speed, gauge_length, specimen_size, note, 
                    temperature, rh_percent, test_performed_by, approved_by, test_results, 
                    reporter_id, reporter_name, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            $inserted_count = 0;
            $report_numbers = [];
            $base_report_number = $report_number;
            
            foreach ($rolls_to_process as $roll_ref) {
                // For bulk rolls, use the individual roll as reference_number
                $current_reference_number = !empty($bulk_rolls) ? $roll_ref : $reference_number;
                
                // Generate unique report number for each roll
                if ($inserted_count > 0) {
                    // Increment report number for subsequent rolls
                    $seq = $this->generateAndIncrementReportNumber();
                    $report_number = $seq;
                }
                $report_numbers[] = $report_number;
            
            $stmt->bind_param(
                "ssssssssssssssssssddsssiss",
                $report_number,           // 1: s - report_number
                    $current_reference_number, // 2: s - reference_number
                $bundle_reference,        // 3: s - bundle_reference
                    $reference_name,          // 4: s - reference_name
                    $data['lab_test_number'], // 5: s - lab_test_number
                    $data['sample_description'], // 6: s - sample_description
                    $sample_received_from,     // 7: s - sample_received_from
                    $sample_collected_from,    // 8: s - sample_collected_from
                    $data['sample_production_date'], // 9: s - sample_production_date
                    $data['received_date'],   // 10: s - received_date
                    $data['test_start_date'], // 11: s - test_start_date
                    $data['test_end_date'],   // 12: s - test_end_date
                    $data['testing_method'],  // 13: s - testing_method
                    $data['test_name'],       // 14: s - test_name
                    $data['test_speed'],      // 15: s - test_speed
                    $data['gauge_length'],    // 16: s - gauge_length
                    $data['specimen_size'],   // 17: s - specimen_size
                    $data['note'],            // 18: s - note
                    $data['temperature'],     // 19: d - temperature
                    $data['rh_percent'],      // 20: d - rh_percent
                    $data['test_performed_by'], // 21: s - test_performed_by
                    $approved_by_value,       // 22: s - approved_by
                    $test_results_json,       // 23: s - test_results
                    $this->reporter_id,       // 24: i - reporter_id
                    $this->reporter_full_name, // 25: s - reporter_name
                    $status                   // 26: s - status
            );
            
            if (!$stmt->execute()) {
                    error_log("Sun Test: Failed to insert roll " . $roll_ref . ": " . $stmt->error);
                    continue;
            }
            
                $inserted_count++;

            // Check bundle completion if this is from a bundle and was approved
            if ($bundle_reference && $status === 'approved') {
                $this->checkAndMarkBundleComplete('sun_test_reports', $bundle_reference);
            }
            }
            
            $stmt->close();
            
            if ($inserted_count === 0) {
                throw new Exception("Failed to save any reports. Please check your data and try again.");
            }
            
            $report_id = $this->conn->insert_id;

            // Compatibility insert disabled
            if (false) {
            // Compatibility table for requested schema: weathering_exposure_test
            $this->conn->query("CREATE TABLE IF NOT EXISTS weathering_exposure_test (
                id INT AUTO_INCREMENT PRIMARY KEY,
                report_no VARCHAR(100) UNIQUE,
                sample_ref VARCHAR(255),
                client VARCHAR(255),
                material VARCHAR(255),
                batch_no VARCHAR(255),
                exposure_type VARCHAR(100),
                exposure_date DATETIME NULL,
                test_start_date DATE NULL,
                test_end_date DATE NULL,
                test_name VARCHAR(255),
                test_parameter VARCHAR(255),
                before_exposure DECIMAL(12,4) NULL,
                after_exposure DECIMAL(12,4) NULL,
                exposure_hours DECIMAL(12,4) NULL,
                retain_percentage DECIMAL(12,4) NULL,
                tested_by VARCHAR(100) NULL,
                approved_by VARCHAR(100) NULL,
                result_json JSON NULL,
                status VARCHAR(20) DEFAULT 'Pending',
                created_by VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_status (status),
                INDEX idx_report_no (report_no)
            )");

            // Derive summary values from test_results
            $sum_before = 0.0; $cnt_before = 0;
            $sum_after = 0.0; $cnt_after = 0;
            $sum_retain = 0.0; $cnt_retain = 0;
            foreach ($test_results as $tr) {
                $bf_before = is_numeric($tr['breaking_force_before']) ? (float)$tr['breaking_force_before'] : null;
                $bf_after  = is_numeric($tr['breaking_force_after']) ? (float)$tr['breaking_force_after'] : null;
                // force_retain may contain string like "12.3 %"; extract numeric part
                $fr_raw = $tr['force_retain'];
                if (is_string($fr_raw)) {
                    $fr_raw = preg_replace('/[^\\d.\\-eE]+/', '', $fr_raw);
                }
                $fr = is_numeric($fr_raw) ? (float)$fr_raw : null;
                if ($bf_before !== null) { $sum_before += $bf_before; $cnt_before++; }
                if ($bf_after !== null) { $sum_after += $bf_after; $cnt_after++; }
                if ($fr !== null) { $sum_retain += $fr; $cnt_retain++; }
            }
            $avg_before = $cnt_before ? ($sum_before / $cnt_before) : null;
            $avg_after  = $cnt_after ? ($sum_after / $cnt_after) : null;
            $avg_retain = $cnt_retain ? ($sum_retain / $cnt_retain) : null;

            // Map fields to requested schema
            $report_no = $report_number;
            $sample_ref = '';
            $client = '';
            $material = $data['sample_description'] ?? '';
            $batch_no = $data['recipe'] ?? '';
            $exposure_type = 'UV';
            $exposure_date = $data['received_date'] ?? null;
            $test_param = trim(($data['test_speed'] ?? '') . ' / ' . ($data['gauge_length'] ?? '') . ' / ' . ($data['specimen_size'] ?? ''));
            $exposure_hours = null; // not captured in form
            $tested_by_val = $data['test_performed_by'] ?? ($this->reporter_name ?? null);
            $approved_by_val = $data['approved_by'] ?? null; // may be null for pending
            $result_json = $test_results_json;
            $status_val = 'Pending';
            $created_by = $this->reporter_name;

            $compat = $this->conn->prepare("INSERT INTO weathering_exposure_test (
                report_no, sample_ref, client, material, batch_no, exposure_type, exposure_date,
                test_start_date, test_end_date, test_name, test_parameter, before_exposure,
                after_exposure, exposure_hours, retain_percentage, tested_by, approved_by,
                result_json, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $compat->bind_param(
                "ssssssssssssssssssss",
                $report_no,
                $sample_ref,
                $client,
                $material,
                $batch_no,
                $exposure_type,
                $exposure_date,
                $data['test_start_date'],
                $data['test_end_date'],
                $data['test_name'],
                $test_param,
                $avg_before,
                $avg_after,
                $exposure_hours,
                $avg_retain,
                $tested_by_val,
                $approved_by_val,
                $result_json,
                $status_val,
                $created_by
            );
            $compat->execute();
            $compat->close();
            }
            
            // Log the action for the first report
            if ($inserted_count > 0) {
                $this->logAction('report_created', $report_id, "Report created with number: " . $report_numbers[0] . ($inserted_count > 1 ? " (and " . ($inserted_count - 1) . " more)" : ""));
            }
            
            $this->conn->commit();
            $message = $inserted_count > 1 
                ? "{$inserted_count} Sun test reports submitted successfully! Report Numbers: " . implode(', ', array_slice($report_numbers, 0, 3)) . (count($report_numbers) > 3 ? '...' : '')
                : "Sun test report submitted successfully! Report Number: " . $report_numbers[0];
            return ['success' => true, 'report_number' => $report_numbers[0], 'report_id' => $report_id, 'count' => $inserted_count, 'report_numbers' => $report_numbers];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Create main table
     */
    private function createMainTable() {
        $createTable = "CREATE TABLE IF NOT EXISTS sun_test_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_number VARCHAR(100) UNIQUE NOT NULL,
            reference_number VARCHAR(200) NULL,
            reference_name VARCHAR(255) NULL,
            lab_test_number VARCHAR(50) NOT NULL,
            sample_description VARCHAR(255) NOT NULL,
            sample_received_from VARCHAR(255) NOT NULL,
            sample_collected_from VARCHAR(255) NOT NULL,
            sample_production_date DATE NOT NULL,
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
            approved_by VARCHAR(100) NULL,
            tested_by VARCHAR(100) NULL,
            approved_at DATETIME NULL,
            remarks TEXT NULL,
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
        // Add reference_number column if missing
        $this->ensureColumnExists('sun_test_reports', 'reference_number', "ALTER TABLE sun_test_reports ADD COLUMN reference_number VARCHAR(200) NULL AFTER report_number");
        $this->ensureColumnExists('sun_test_reports', 'bundle_reference', "ALTER TABLE sun_test_reports ADD COLUMN bundle_reference VARCHAR(200) NULL AFTER reference_number");
        $this->ensureColumnExists('sun_test_reports', 'reference_name', "ALTER TABLE sun_test_reports ADD COLUMN reference_name VARCHAR(255) NULL AFTER bundle_reference");
        $this->ensureColumnExists('sun_test_reports', 'sample_received_from', "ALTER TABLE sun_test_reports ADD COLUMN sample_received_from VARCHAR(255) NOT NULL AFTER sample_description");
        $this->ensureColumnExists('sun_test_reports', 'sample_collected_from', "ALTER TABLE sun_test_reports ADD COLUMN sample_collected_from VARCHAR(255) NOT NULL AFTER sample_received_from");
        $this->ensureColumnExists('sun_test_reports', 'sample_production_date', "ALTER TABLE sun_test_reports ADD COLUMN sample_production_date DATE NOT NULL AFTER sample_collected_from");
        $this->ensureColumnExists('sun_test_reports', 'lab_test_number', "ALTER TABLE sun_test_reports ADD COLUMN lab_test_number VARCHAR(50) NOT NULL AFTER reference_name");
        
        // Remove old columns (gsm, roll_number, batch_number) if they exist
        $this->removeColumnIfExists('sun_test_reports', 'gsm');
        $this->removeColumnIfExists('sun_test_reports', 'roll_number');
        $this->removeColumnIfExists('sun_test_reports', 'batch_number');
        $this->ensureColumnExists('sun_test_reports', 'tested_by', "ALTER TABLE sun_test_reports ADD COLUMN tested_by VARCHAR(100) NULL AFTER approved_by");
        $this->ensureColumnExists('sun_test_reports', 'approved_at', "ALTER TABLE sun_test_reports ADD COLUMN approved_at DATETIME NULL AFTER tested_by");
        $this->ensureColumnExists('sun_test_reports', 'remarks', "ALTER TABLE sun_test_reports ADD COLUMN remarks TEXT NULL AFTER approved_at");
        // Make approved_by nullable to support pending submissions without approver
        @$this->conn->query("ALTER TABLE sun_test_reports MODIFY approved_by VARCHAR(100) NULL");
    }

    private function ensureColumnExists($table, $column, $alterSql) {
        $check = $this->conn->prepare("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $check->bind_param("ss", $table, $column);
        $check->execute();
        $res = $check->get_result();
        $row = $res->fetch_assoc();
        $check->close();
        if (empty($row['c'])) {
            $this->conn->query($alterSql);
        }
    }
    
    private function removeColumnIfExists($table, $column) {
        $check = $this->conn->prepare("SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $check->bind_param("ss", $table, $column);
        $check->execute();
        $res = $check->get_result();
        $row = $res->fetch_assoc();
        $check->close();
        if (!empty($row['c'])) {
            @$this->conn->query("ALTER TABLE $table DROP COLUMN $column");
        }
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
     * Get rejected reports for current tester
     */
    public function getRejectedReportsForUser($user_id) {
        $stmt = $this->conn->prepare(
            "SELECT id, report_number, sample_description, status, remarks, created_at, approved_by
             FROM sun_test_reports 
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
    
    /**
     * Check if current user can approve reports
     */
    public function canApproveReports() {
        // Check session role first (faster)
        $session_role = strtolower(trim($_SESSION['role'] ?? ''));
        if (in_array($session_role, ['admin', 'agm ops', 'agm operations'])) {
            return true;
        }
        
        // Fallback to database check
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
     * Check if all rolls from a bundle are tested and approved, then mark bundle as complete
     */
    private function checkAndMarkBundleComplete($table_name, $bundle_reference) {
        try {
            // Parse bundle reference to get base reference and roll count (e.g., "REF-4" -> base="REF", count=4)
            if (!preg_match('/-(\d+)$/', $bundle_reference, $matches)) {
                return false; // Not a valid bundle reference
            }
            
            $rollCount = (int)$matches[1];
            $baseRef = preg_replace('/-\d+$/', '', $bundle_reference);
            
            // Generate all individual roll references (REF-1, REF-2, etc.)
            $expectedRolls = [];
            for ($i = 1; $i <= $rollCount; $i++) {
                $expectedRolls[] = $baseRef . '-' . $i;
            }
            
            // Check if all individual rolls are tested and approved
            $placeholders = str_repeat('?,', count($expectedRolls) - 1) . '?';
            $query = "SELECT reference_number, status 
                      FROM {$table_name} 
                      WHERE bundle_reference = ? 
                      AND reference_number IN ({$placeholders})
                      AND status = 'approved'";
            
            $stmt = $this->conn->prepare($query);
            $params = array_merge([$bundle_reference], $expectedRolls);
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $approvedRolls = [];
            while ($row = $result->fetch_assoc()) {
                $approvedRolls[] = $row['reference_number'];
            }
            $stmt->close();
            
            // If all rolls are approved, mark bundle as complete
            if (count($approvedRolls) >= $rollCount) {
                // Create bundle_test_completion table if it doesn't exist
                $createTable = "CREATE TABLE IF NOT EXISTS bundle_test_completion (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    bundle_reference VARCHAR(100) NOT NULL,
                    test_type VARCHAR(50) NOT NULL,
                    status ENUM('pending', 'completed', 'passed') DEFAULT 'pending',
                    completed_at DATETIME NULL,
                    approved_by VARCHAR(255) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY (bundle_reference, test_type),
                    INDEX idx_bundle_status (bundle_reference, status),
                    INDEX idx_test_type (test_type)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
                $this->conn->query($createTable);
                
                // Determine test type from table name
                $testType = '';
                switch ($table_name) {
                    case 'water_permeability_tests':
                        $testType = 'water_permeability';
                        break;
                    case 'characteristics_tests':
                        $testType = 'characteristics';
                        break;
                    case 'sun_test_reports':
                        $testType = 'sun_test';
                        break;
                    case 'weathering_exposure_reports':
                        $testType = 'weathering_exposure';
                        break;
                    default:
                        return false;
                }
                
                // Get the approver name from the last approved test
                $approverQuery = "SELECT approved_by FROM {$table_name} 
                                 WHERE bundle_reference = ? AND status = 'approved' 
                                 ORDER BY approved_at DESC LIMIT 1";
                $approverStmt = $this->conn->prepare($approverQuery);
                $approverStmt->bind_param("s", $bundle_reference);
                $approverStmt->execute();
                $approverResult = $approverStmt->get_result();
                $approverRow = $approverResult->fetch_assoc();
                $approverName = $approverRow['approved_by'] ?? null;
                $approverStmt->close();
                
                // Insert or update bundle completion record
                $insertQuery = "INSERT INTO bundle_test_completion 
                               (bundle_reference, test_type, status, completed_at, approved_by) 
                               VALUES (?, ?, 'passed', NOW(), ?)
                               ON DUPLICATE KEY UPDATE 
                               status = 'passed', 
                               completed_at = NOW(), 
                               approved_by = ?";
                $insertStmt = $this->conn->prepare($insertQuery);
                $insertStmt->bind_param("ssss", $bundle_reference, $testType, $approverName, $approverName);
                $insertStmt->execute();
                $insertStmt->close();
                
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error checking bundle completion: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Log action
     */
    private function logAction($action, $report_id, $details = '') {
        $this->createLogTable();
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $stmt = $this->conn->prepare("
            INSERT INTO sun_test_logs (report_id, action, details, user_id, username, ip_address, user_agent) 
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
        $createLogTable = "CREATE TABLE IF NOT EXISTS sun_test_logs (
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

    /**
     * Fetch pending reports for approval queue (latest first)
     */
    public function getPendingReports($limit = 20) {
        $this->createMainTable();
        $stmt = $this->conn->prepare("SELECT id, report_number, sample_description, test_name, reporter_name, created_at, updated_at FROM sun_test_reports WHERE status = 'pending' ORDER BY updated_at DESC LIMIT ?");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $stmt->close();
        return $rows;
    }

    /**
     * Approve or reject a report by report_number
     */
    public function approveOrReject($report_number, $action, $comment = '') {
        if (!in_array($action, ['approved', 'rejected'], true)) {
            return ['success' => false, 'error' => 'Invalid action'];
        }
        if (!$this->canApproveReports()) {
            return ['success' => false, 'error' => 'Not authorized'];
        }
        // Ensure main table exists
        $this->createMainTable();
        $stmt = $this->conn->prepare("UPDATE sun_test_reports SET status = ?, approved_by = ?, approved_at = NOW(), remarks = ?, updated_at = NOW() WHERE report_number = ?");
        $stmt->bind_param("ssss", $action, $this->reporter_name, $comment, $report_number);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['success' => false, 'error' => 'Failed to update: ' . $this->conn->error];
        }
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected <= 0) {
            return ['success' => false, 'error' => 'Report not found'];
        }
        // Log action
        $reportRow = $this->conn->prepare("SELECT id FROM sun_test_reports WHERE report_number = ?");
        $reportRow->bind_param("s", $report_number);
        $reportRow->execute();
        $res = $reportRow->get_result();
        $row = $res->fetch_assoc();
        $reportRow->close();
        if ($row && isset($row['id'])) {
            $this->logAction('report_' . $action, (int)$row['id'], $comment);
        }
        return ['success' => true];
    }
    
    /**
     * Delete a report by report_number
     */
    public function deleteReport($report_number) {
        // Get report ID for logging
        $getReportId = $this->conn->prepare("SELECT id, reporter_id FROM sun_test_reports WHERE report_number = ?");
        $getReportId->bind_param("s", $report_number);
        $getReportId->execute();
        $result = $getReportId->get_result();
        $report = $result->fetch_assoc();
        $getReportId->close();
        
        if (!$report) {
            return ['success' => false, 'error' => 'Report not found'];
        }
        
        // Check permissions - only allow deleting own reports or if user can approve
        $can_delete = false;
        if ($this->canApproveReports()) {
            $can_delete = true; // Admins can delete any report
        } elseif ($report['reporter_id'] == $this->reporter_id) {
            $can_delete = true; // Testers can delete their own reports
        }
        
        if (!$can_delete) {
            return ['success' => false, 'error' => 'Not authorized to delete this report'];
        }
        
        // Delete the report
        $deleteStmt = $this->conn->prepare("DELETE FROM sun_test_reports WHERE report_number = ?");
        $deleteStmt->bind_param("s", $report_number);
        if (!$deleteStmt->execute()) {
            $deleteStmt->close();
            return ['success' => false, 'error' => 'Failed to delete report: ' . $this->conn->error];
        }
        $deleteStmt->close();
        
        // Log the action
        if (isset($report['id'])) {
            $this->logAction('report_deleted', (int)$report['id'], "Report deleted: $report_number");
        }
        
        return ['success' => true];
    }
}

// Initialize handler
$handler = new SunTestReportHandler($conn, $reporter_id, $reporter_name, $reporter_full_name);

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
            $user_role = strtolower(trim($_SESSION['role'] ?? ''));
            if (in_array($user_role, ['admin', 'agm ops', 'agm operations'])) {
                $message = "Sun Test Report saved and auto-approved! Report Number: " . $result['report_number'];
            } else {
                $message_text = isset($result['count']) && $result['count'] > 1
                    ? "{$result['count']} Sun Test Reports submitted successfully! Report Numbers: " . implode(', ', array_slice($result['report_numbers'], 0, 3)) . (count($result['report_numbers']) > 3 ? '...' : '') . " - Status: Pending Approval"
                    : "Sun Test Report submitted successfully! Report Number: " . $result['report_number'] . " - Status: Pending Approval";
                $message = $message_text;
            }
            
            // Store success message in session and redirect to refresh dropdown
            $_SESSION['success_message'] = $message;
            $success_msg = urlencode($message);
            header("Location: sun_test_report.php?success=1&msg={$success_msg}&t=" . time());
            exit();
        } else {
            $error = "❌ Error: " . $result['error'];
        }
    } else {
        $error = "❌ Error: Validation failed: " . implode(', ', $validation_errors);
    }
}

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wf_action'])) {
    $wf_action = $_POST['wf_action']; // 'approved' or 'rejected'
    $wf_report_number = trim($_POST['wf_report_number'] ?? '');
    $wf_comment = trim($_POST['wf_comment'] ?? '');
    
    // Handle rejection reasons checkboxes for sun test
    if ($wf_action === 'rejected' && isset($_POST['sun_rejection_reasons']) && is_array($_POST['sun_rejection_reasons'])) {
        $rejection_reasons = array_map('trim', $_POST['sun_rejection_reasons']);
        $reasons_text = implode(', ', $rejection_reasons);
        $wf_comment = "Rejection Reasons: " . $reasons_text . ($wf_comment ? "\n\nAdditional Comments: " . $wf_comment : '');
    }
    
    if ($wf_report_number === '') {
        $error = '❌ Error: Report Number is required for approval.';
    } else {
        $result = $handler->approveOrReject($wf_report_number, $wf_action, $wf_comment);
        if ($result['success']) {
            $message = ($wf_action === 'approved' ? 'Approved: ' : 'Rejected: ') . htmlspecialchars($wf_report_number);
        } else {
            $error = '❌ Error: ' . $result['error'];
        }
    }
}

// Handle delete actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_report'])) {
    $delete_report_number = trim($_POST['delete_report_number'] ?? '');
    
    if ($delete_report_number === '') {
        $error = '❌ Error: Report Number is required for deletion.';
    } else {
        $result = $handler->deleteReport($delete_report_number);
        if ($result['success']) {
            $message = 'Deleted: ' . htmlspecialchars($delete_report_number);
        } else {
            $error = '❌ Error: ' . $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Sun Test Report</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; overflow-x:hidden; }
  .container { max-width:1200px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08); overflow-x:hidden; } 
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
  .test-table { width:100%; min-width:900px; border-collapse:collapse; margin-top:0; }
  .test-table th, .test-table td { border:1px solid #ddd; padding:8px; text-align:center; }
  .test-table th { background:#3498db; color:#fff; font-weight:600; }
  .test-table input, .test-table select { width:100%; min-width:120px; border:none; background:transparent; text-align:center; box-sizing:border-box; }
  .test-table select { padding:5px; }
  .test-table-wrapper { overflow-x:auto; margin-top:15px; width:100%; max-width:100%; -webkit-overflow-scrolling:touch; }
  @media (max-width: 1024px) {
    body { padding:20px 15px; overflow-x:hidden; }
    .container { padding:20px 15px; overflow-x:hidden; max-width:100%; }
    .test-table-wrapper { 
      width:100%; 
      max-width:100%;
      margin-left:0; 
      margin-right:0; 
      padding:0;
      box-sizing:border-box;
    }
    .test-table { 
      min-width:900px;
      width:auto;
    }
  }
  .form-row { display:flex; gap:15px; margin-bottom:20px; flex-wrap:wrap; }
  .form-row .form-group { flex:1; min-width:200px; }
  .form-row .form-group:only-child { max-width:100%; }
  .dir-btn { padding:4px 10px; border:1px solid #ccc; border-radius:4px; cursor:pointer; margin:2px; }
  .dir-btn.active { background:#007bff; color:#fff; }
  .dir-btn:not(.active) { background:#fff; color:#2c3e50; }
</style>
</head>
<body>
<div class="container">
  <h1> Sun Test Report</h1>

  <?php 
  // Check for session-based success message (from edit page)
  if (isset($_SESSION['update_success'])) {
      $message = $_SESSION['update_success'];
      unset($_SESSION['update_success']);
  }
  ?>
  
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

  <!-- Back to Dashboard Link -->
  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
  </div>

  <?php if ($handler->canApproveReports()): ?>
  <!-- Pending Approval Queue (Outside Form) -->
  <?php $pending = $handler->getPendingReports(20); if (!empty($pending)): ?>
  <div style="margin-top:16px; padding:12px; border:1px solid #ddd; border-radius:8px; background:#fff;">
    <h3 style="margin:0 0 12px 0;">Pending Reports</h3>
    <table class="test-table">
      <thead>
        <tr>
          <th>Report No</th>
          <th>Sample</th>
          <th>Test Name</th>
          <th>Submitted By</th>
          <th>Last Updated</th>
          <th style="width:250px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pending as $row): ?>
        <tr>
          <td><?php echo htmlspecialchars($row['report_number']); ?></td>
          <td><?php echo htmlspecialchars($row['sample_description']); ?></td>
          <td><?php echo htmlspecialchars($row['test_name'] ?? 'Breaking Force and Elongation of Textile Fabrics (Strip Method)'); ?></td>
          <td><?php echo htmlspecialchars($row['reporter_name']); ?></td>
          <td><?php echo htmlspecialchars($row['updated_at']); ?></td>
          <td>
            <a href="../admin/view_sun_report.php?id=<?php echo $row['id']; ?>" target="_blank" class="submit-btn" style="padding:6px 10px; text-decoration:none; display:inline-block; background:#3498db; margin-right:4px; font-size:12px;">View</a>
            <form method="POST" action="" style="display:inline; margin-right:4px;" onsubmit="return confirmApproval(this);">
              <input type="hidden" name="wf_report_number" value="<?php echo htmlspecialchars($row['report_number']); ?>">
              <input type="hidden" name="wf_comment" value="Approved from queue">
              <button type="submit" name="wf_action" value="approved" class="submit-btn" style="padding:6px 10px; font-size:12px;">Approve</button>
            </form>
            <button type="button" onclick="openSunRejectModal('<?php echo htmlspecialchars($row['report_number']); ?>')" class="clear-btn" style="padding:6px 10px; border:none; cursor:pointer; font-size:12px;">Reject</button>
            <button type="button" onclick="deleteSunReport('<?php echo htmlspecialchars($row['report_number']); ?>')" style="padding:6px 10px; background:#dc3545; color:#fff; border:none; cursor:pointer; font-size:12px;">Delete</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php if (!$handler->canApproveReports()): ?>
  <!-- Rejected Reports for Tester to Review -->
  <?php $rejected = $handler->getRejectedReportsForUser($reporter_id); if (!empty($rejected)): ?>
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
          <th style="width:250px;">Action</th>
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
            <a href="edit_sun_report.php?id=<?php echo $rj['id']; ?>" class="submit-btn" style="padding:6px 10px; text-decoration:none; display:inline-block; background:#ff9800; color:#fff; font-size:12px;">Edit & Resubmit</a>
            <button type="button" onclick="deleteSunReport('<?php echo htmlspecialchars($rj['report_number']); ?>')" style="padding:6px 10px; background:#dc3545; color:#fff; border:none; cursor:pointer; font-size:12px; margin-left:4px;">Delete</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin:12px 0 0 0; color:#856404; font-style:italic;">💡 Note: Click "Edit & Resubmit" to modify the rejected report, or submit a new report using the form below.</p>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <form method="POST" action="" onsubmit="return validateSunForm();">

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
        <label>Lab Test Number:</label>
        <input type="text" name="lab_test_number" id="lab_test_number" readonly class="readonly" required>
      </div>
      <div class="form-group">
        <label>Sample Description:</label>
        <input type="text" name="sample_description" id="sample_description" readonly class="readonly" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group" style="grid-column: 1 / -1;">
        <label>Reference Number: <span style="color:red;">*</span></label>
        
        <!-- Line Selection Buttons -->
        <div id="sun_line_selection_buttons" style="display:flex; gap:10px; margin-bottom:10px;">
          <button type="button" id="sun_line1_btn" class="line-btn" onclick="filterSunByLine('L1')" style="padding:8px 16px; border:2px solid #3498db; border-radius:6px; background:#e3f2fd; color:#1565C0; font-weight:600; cursor:pointer;">
            Line 1
          </button>
          <button type="button" id="sun_line2_btn" class="line-btn" onclick="filterSunByLine('L2')" style="padding:8px 16px; border:2px solid #3498db; border-radius:6px; background:#e3f2fd; color:#1565C0; font-weight:600; cursor:pointer;">
            Line 2
          </button>
          <button type="button" id="sun_line_all_btn" class="line-btn active" onclick="filterSunByLine('all')" style="padding:8px 16px; border:2px solid #3498db; border-radius:6px; background:#2196F3; color:#ffffff; font-weight:600; cursor:pointer;">
            All Lines
          </button>
        </div>
        
        <!-- From/To Reference Selection (shown when line is selected) -->
        <div id="sun_bulk_reference_selection" style="display:none; margin-bottom:10px; padding:10px; background:#f8f9fa; border:1px solid #ddd; border-radius:6px;">
          <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <label style="font-weight:600; margin:0;">From Reference:</label>
            <select id="sun_from_reference" style="min-width:250px; padding:5px; border:1px solid #ccc; border-radius:4px;" onchange="updateSunReferenceRange(true); handleSunFromToReferenceChange();">
              <option value="">-- Select From Reference --</option>
            </select>
            <label style="font-weight:600; margin:0;">To Reference:</label>
            <select id="sun_to_reference" style="min-width:250px; padding:5px; border:1px solid #ccc; border-radius:4px;" onchange="updateSunReferenceRange(false); handleSunFromToReferenceChange();">
              <option value="">-- Select To Reference --</option>
            </select>
            <button type="button" onclick="applySunBulkReferenceSelection()" style="padding:6px 12px; background:#3498db; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:600;">
              Apply
            </button>
            <button type="button" onclick="clearSunBulkReferenceSelection()" style="padding:6px 12px; background:#6c757d; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:600;">
              Clear
            </button>
          </div>
          <!-- Hidden input to store the selected product_reference when line-based selection is used -->
          <input type="hidden" id="sun_line_based_product_reference" name="product_reference" value="">
        </div>
        
        <!-- Production Product Reference Dropdown (shown when "All Lines" is selected) -->
        <select name="reference_number" id="reference_number" required style="padding:10px; border:1px solid #ccc; border-radius:6px; width:100%;" onchange="handleSunReferenceSelection(this.value)">
          <option value="">Select Reference</option>
        </select>
        
        <!-- Individual Roll Selector (shown when bundle is selected) -->
        <select name="individual_roll_reference" id="sun_individual_roll_reference" onchange="handleSunIndividualRollSelection(this.value)" style="display:none; margin-top:10px; padding:10px; border:2px solid #3498db; border-radius:6px; background:#f8f9fa;">
          <option value="">-- Select Individual Roll for Testing --</option>
        </select>
        <div id="sun_bundle_info" style="display:none; margin-top:8px; padding:10px; background:#e3f2fd; border-left:4px solid #2196F3; border-radius:4px; font-size:13px; color:#1565C0;">
          <i class="fas fa-info-circle"></i> <strong>Bundle Detected:</strong> This reference contains multiple rolls. <strong>Please select the specific roll number</strong> you want to test individually.
        </div>
        <div id="reference_error" style="color:red; margin-top:5px;"></div>
      </div>
      <div class="form-group">
        <label>Reference Name: <span style="color:red;">*</span></label>
        <input type="text" name="reference_name" id="reference_name" required placeholder="Enter reference name">
      </div>
      <div class="form-group">
        <label>Sample Received From:</label>
        <input type="text" name="sample_received_from" required>
      </div>
      <div class="form-group">
        <label>Sample Collected From:</label>
        <input type="text" name="sample_collected_from" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Sample Production Date:</label>
        <input type="date" name="sample_production_date" id="sample_production_date" required onchange="validateSampleDates()">
      </div>
      <div class="form-group">
        <label>Sample Received Date & Time:</label>
        <input type="datetime-local" name="received_date" id="received_date" required onchange="validateSampleDates()">
        <div id="sample_date_error" style="color:red; margin-top:5px; font-size:12px;"></div>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Test Start Date:</label>
        <input type="date" name="test_start_date" required onchange="validateDates()">
      </div>
      <div class="form-group">
        <label>Test End Date:</label>
        <input type="date" name="test_end_date" required onchange="validateDates()">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Testing Method:</label>
        <input type="text" name="testing_method" value="Tensile Test(ASTM D4595)" readonly class="readonly" required>
      </div>
      <div class="form-group">
        <label>Test Name:</label>
        <input type="text" name="test_name" value="Breaking Force and Elongation of Textile Fabrics (Strip Method)" readonly class="readonly" required>
      </div>
    </div>

    <!-- Test Speed / Gauge Length / Specimen Size -->
    <div class="form-row">
      <div class="form-group">
        <label>Test Speed (mm/min):</label>
        <input type="text" name="test_speed" required placeholder="e.g., 300">
      </div>
      <div class="form-group">
        <label>Gauge Length (mm):</label>
        <input type="text" name="gauge_length" required placeholder="e.g., 75">
      </div>
      <div class="form-group">
        <label>Specimen Size:</label>
        <input type="text" name="specimen_size" required placeholder="150mm*50mm">
      </div>
    </div>

    <div class="form-group">
      <label>Note (if any):</label>
      <input type="text" name="note" maxlength="255" placeholder="Optional">
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Temperature (°C):</label>
        <input type="number" name="temperature" step="0.01" min="0" max="1000" required oninput="if(this.value < 0) this.value = 0">
      </div>
      <div class="form-group">
        <label>RH%:</label>
        <input type="number" name="rh_percent" step="0.01" min="0" max="100" required oninput="if(this.value < 0) this.value = 0">
      </div>
    </div>

    <?php if (!$handler->canApproveReports()): ?>
    <div class="form-group">
      <label>Test Performed By:</label>
      <input type="text" name="test_performed_by" value="<?php echo htmlspecialchars($reporter_full_name); ?>" readonly class="readonly">
    </div>
    <?php else: ?>
    <input type="hidden" name="test_performed_by" value="<?php echo htmlspecialchars($reporter_full_name); ?>">
    <?php endif; ?>

    <!-- Test Results Table -->
    <h3 style="text-align:center; margin:30px 0 20px 0;">Test Results</h3>
    <div class="test-table-wrapper">
    <table class="test-table">
      <thead>
        <tr>
          <th style="width:60px;">Specimen No</th>
          <th>Test Direction</th>
          <th colspan="2">Breaking Force (N)</th>
          <th>Force Retain (%)</th>
          <th colspan="2">Elongation (%)</th>
          <th style="width:80px;">Action</th>
        </tr>
        <tr>
          <th></th>
          <th></th>
          <th>After</th>
          <th>Before</th>
          <th></th>
          <th>After</th>
          <th>Before</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="sun_tbody">
        <tr>
          <td>1</td>
          <td>
            <div style="display:inline-flex; gap:6px; align-items:center;">
              <button type="button" class="dir-btn active" data-row="1" onclick="setDirection(this,'MD')">MD1</button>
              <button type="button" class="dir-btn" data-row="1" onclick="setDirection(this,'CD')">CD1</button>
              <input type="hidden" name="test_direction_1" value="MD">
            </div>
          </td>
          <td><input type="number" step="0.01" min="0" name="breaking_force_after_1" data-row="1" class="bf-after" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0; calculateForceRetain(this)"></td>
          <td><input type="number" step="0.01" min="0" name="breaking_force_before_1" data-row="1" class="bf-before" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0; calculateForceRetain(this)"></td>
          <td><input type="text" name="force_retain_1" class="fr" readonly value="" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px; background:#f8f9fa;"></td>
          <td><input type="number" step="0.01" min="0" name="elongation_after_1" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0"></td>
          <td><input type="number" step="0.01" min="0" name="elongation_before_1" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0"></td>
          <td><button type="button" onclick="removeSunRow(this)" style="padding:4px 8px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:12px;">Delete</button></td>
        </tr>
      </tbody>
      <tbody>
        <?php 
        $statistics = ['Average','SD','CV','Maximum','Minimum'];
        foreach ($statistics as $idx => $label): 
        ?>
        <tr>
          <?php if ($idx === 0): ?>
          <td style="font-weight:600;" rowspan="5">Total</td>
          <?php endif; ?>
          <td><?php echo $label; ?></td>
          <td><span id="stat_bf_after_<?php echo strtolower($label); ?>"></span></td>
          <td><span id="stat_bf_before_<?php echo strtolower($label); ?>"></span></td>
          <td><span id="stat_fr_<?php echo strtolower($label); ?>"></span></td>
          <td><span id="stat_el_after_<?php echo strtolower($label); ?>"></span></td>
          <td><span id="stat_el_before_<?php echo strtolower($label); ?>"></span></td>
          <td></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <!-- Add Row Button -->
    <div style="margin-top: 10px;">
      <button type="button" onclick="addSunRow()" style="padding:8px 16px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer; font-weight:600;">Add 1 More Row</button>
    </div>

    <!-- Test Result Summary -->
    <div style="margin-top: 16px;">
      <h3 style="text-align:center; margin:8px 0;">Test Result</h3>
      <table class="test-table">
        <thead>
          <tr>
            <th></th>
            <th>After Exposure</th>
            <th>Before Exposure</th>
            <th>Retain (%)</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Force (N)</td>
            <td><span id="result_after"></span></td>
            <td><span id="result_before"></span></td>
            <td><span id="result_retain"></span></td>
          </tr>
        </tbody>
      </table>
    </div>

    <?php if ($handler->canApproveReports()): ?>
    <!-- Approved By (Admin/AGM Ops only) -->
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
    extractLabTestNumber();
    updateSampleDescription();
    loadReferencesList();
    updateDeleteButtonStates();
    
    // Auto-fill received date with current date/time
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const currentDateTime = `${year}-${month}-${day}T${hours}:${minutes}`;
    
    document.querySelector('input[name="received_date"]').value = currentDateTime;
    
    // Add event listener for reference_number to update sample description
    const referenceNumberSelect = document.getElementById('reference_number');
    if (referenceNumberSelect) {
        referenceNumberSelect.addEventListener('change', function() {
            if (this.value) {
                generateSampleDescriptionFromReference(this.value);
            } else {
                updateSampleDescription();
            }
        });
    }
    
    // Add event listener for reference_name to update sample description (fallback)
    const referenceNameInput = document.getElementById('reference_name');
    if (referenceNameInput) {
        referenceNameInput.addEventListener('input', updateSampleDescription);
    }
    
    // Add event listeners to date inputs
    document.querySelector('input[name="test_start_date"]').addEventListener('change', validateDates);
    document.querySelector('input[name="test_end_date"]').addEventListener('change', validateDates);
    
    // Add event listener to form submission
    document.querySelector('form').addEventListener('submit', function(event) {
        if (!validateSunForm()) {
            event.preventDefault(); // Prevent form submission if validation fails
        }
    });
});

// Load references list on page load
function loadReferencesList() {
    fetch('api/get_references_list.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const select = document.getElementById('reference_number');
                
                // Add bundle references
                if (data.bundleReferences && data.bundleReferences.length > 0) {
                    data.bundleReferences.forEach(bundle => {
                        const option = document.createElement('option');
                        option.value = bundle.reference;
                        option.setAttribute('data-is-bundle', 'true');
                        option.setAttribute('data-base-ref', bundle.base_reference);
                        option.setAttribute('data-roll-count', bundle.roll_count);
                        option.setAttribute('data-line', bundle.line || '');
                        option.textContent = bundle.reference + ' (Bundle - ' + bundle.roll_count + ' rolls)';
                        select.appendChild(option);
                    });
                }
                
                // Add single roll references
                if (data.references && data.references.length > 0) {
                    data.references.forEach(ref => {
                        const refValue = typeof ref === 'string' ? ref : ref.reference;
                        const lineIndicator = typeof ref === 'object' ? (ref.line || '') : '';
                        const option = document.createElement('option');
                        option.value = refValue;
                        option.setAttribute('data-is-bundle', 'false');
                        option.setAttribute('data-line', lineIndicator);
                        option.textContent = refValue;
                        select.appendChild(option);
                    });
                }
            }
        })
        .catch(err => console.error('Error loading references:', err));
}

// Filter references by Line (L1 or L2)
function filterSunByLine(line) {
    const productRefSelect = document.getElementById('reference_number');
    const bulkRefSelection = document.getElementById('sun_bulk_reference_selection');
    
    // Update button styles
    const line1Btn = document.getElementById('sun_line1_btn');
    const line2Btn = document.getElementById('sun_line2_btn');
    const lineAllBtn = document.getElementById('sun_line_all_btn');
    
    if (line1Btn) line1Btn.classList.remove('active');
    if (line2Btn) line2Btn.classList.remove('active');
    if (lineAllBtn) lineAllBtn.classList.remove('active');
    
    if (line === 'L1' && line1Btn) {
        line1Btn.classList.add('active');
        line1Btn.style.background = '#2196F3';
        line1Btn.style.color = '#ffffff';
        line2Btn.style.background = '#e3f2fd';
        line2Btn.style.color = '#1565C0';
        lineAllBtn.style.background = '#e3f2fd';
        lineAllBtn.style.color = '#1565C0';
    } else if (line === 'L2' && line2Btn) {
        line2Btn.classList.add('active');
        line2Btn.style.background = '#2196F3';
        line2Btn.style.color = '#ffffff';
        line1Btn.style.background = '#e3f2fd';
        line1Btn.style.color = '#1565C0';
        lineAllBtn.style.background = '#e3f2fd';
        lineAllBtn.style.color = '#1565C0';
    } else if (line === 'all' && lineAllBtn) {
        lineAllBtn.classList.add('active');
        lineAllBtn.style.background = '#2196F3';
        lineAllBtn.style.color = '#ffffff';
        line1Btn.style.background = '#e3f2fd';
        line1Btn.style.color = '#1565C0';
        line2Btn.style.background = '#e3f2fd';
        line2Btn.style.color = '#1565C0';
    }
    
    if (line === 'L1' || line === 'L2') {
        // Hide single reference dropdown
        productRefSelect.style.display = 'none';
        productRefSelect.value = '';
        productRefSelect.removeAttribute('required');
        productRefSelect.removeAttribute('name');
        
        // Show From/To reference selection
        if (bulkRefSelection) {
            bulkRefSelection.style.display = 'block';
            populateSunLineReferences(line);
        }
        
        // Make From/To required
        const fromRefSelect = document.getElementById('sun_from_reference');
        const toRefSelect = document.getElementById('sun_to_reference');
        if (fromRefSelect) {
            fromRefSelect.setAttribute('required', 'required');
            fromRefSelect.setAttribute('name', 'from_reference');
        }
        if (toRefSelect) {
            toRefSelect.setAttribute('required', 'required');
            toRefSelect.setAttribute('name', 'to_reference');
        }
    } else {
        // Show single reference dropdown for "All Lines"
        productRefSelect.style.display = 'block';
        productRefSelect.setAttribute('required', 'required');
        productRefSelect.setAttribute('name', 'reference_number');
        
        // Hide From/To reference selection
        if (bulkRefSelection) {
            bulkRefSelection.style.display = 'none';
            clearSunBulkReferenceSelection();
        }
        
        // Remove required from From/To and remove names
        const fromRefSelect = document.getElementById('sun_from_reference');
        const toRefSelect = document.getElementById('sun_to_reference');
        if (fromRefSelect) {
            fromRefSelect.removeAttribute('required');
            fromRefSelect.removeAttribute('name');
            fromRefSelect.value = '';
        }
        if (toRefSelect) {
            toRefSelect.removeAttribute('required');
            toRefSelect.removeAttribute('name');
            toRefSelect.value = '';
        }
        
        // Clear hidden input
        const lineBasedProductRef = document.getElementById('sun_line_based_product_reference');
        if (lineBasedProductRef) {
            lineBasedProductRef.value = '';
        }
        
        // Filter options for "All Lines"
        const currentValue = productRefSelect.value;
        Array.from(productRefSelect.options).forEach(option => {
            if (option.value === '') {
                option.style.display = '';
                return;
            }
            option.style.display = '';
        });
        
        // Clear selection if current value doesn't match filter
        if (currentValue) {
            const selectedOption = productRefSelect.querySelector(`option[value="${currentValue}"]`);
            if (selectedOption && selectedOption.style.display === 'none') {
                productRefSelect.value = '';
                handleSunReferenceSelection('');
            }
        }
    }
}

// Populate From/To reference dropdowns with references for selected line
function populateSunLineReferences(line) {
    const productRefSelect = document.getElementById('reference_number');
    const fromRefSelect = document.getElementById('sun_from_reference');
    const toRefSelect = document.getElementById('sun_to_reference');
    
    if (!productRefSelect || !fromRefSelect || !toRefSelect) return;
    
    // Clear existing options
    fromRefSelect.innerHTML = '<option value="">-- Select From Reference --</option>';
    toRefSelect.innerHTML = '<option value="">-- Select To Reference --</option>';
    
    // Collect all references for the selected line
    const lineReferences = [];
    Array.from(productRefSelect.options).forEach(option => {
        if (option.value && option.value !== '') {
            const optionLine = option.getAttribute('data-line') || '';
            if (optionLine === line) {
                const isBundle = option.getAttribute('data-is-bundle') === 'true';
                lineReferences.push({
                    value: option.value,
                    text: option.textContent,
                    isBundle: isBundle,
                    baseRef: option.getAttribute('data-base-ref') || '',
                    rollCount: parseInt(option.getAttribute('data-roll-count')) || 1
                });
            }
        }
    });
    
    // Sort references by date (extract date from reference number)
    function extractDateFromReference(ref) {
        const monthAbbr = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
        for (let i = 0; i < monthAbbr.length; i++) {
            const month = monthAbbr[i];
            const pattern = new RegExp(month + '(\\d{2})');
            const match = ref.match(pattern);
            if (match) {
                const day = parseInt(match[1]);
                return ((i + 1) * 100) + day;
            }
        }
        return 9999;
    }
    
    // Sort by date first, then alphabetically for same date
    lineReferences.sort((a, b) => {
        const dateA = extractDateFromReference(a.value);
        const dateB = extractDateFromReference(b.value);
        if (dateA !== dateB) {
            return dateA - dateB;
        }
        return a.value.localeCompare(b.value);
    });
    
    // Populate both dropdowns
    lineReferences.forEach(ref => {
        const fromOption = document.createElement('option');
        fromOption.value = ref.value;
        fromOption.textContent = ref.text;
        fromOption.setAttribute('data-is-bundle', ref.isBundle);
        fromOption.setAttribute('data-base-ref', ref.baseRef);
        fromOption.setAttribute('data-roll-count', ref.rollCount);
        fromRefSelect.appendChild(fromOption);
        
        const toOption = document.createElement('option');
        toOption.value = ref.value;
        toOption.textContent = ref.text;
        toOption.setAttribute('data-is-bundle', ref.isBundle);
        toOption.setAttribute('data-base-ref', ref.baseRef);
        toOption.setAttribute('data-roll-count', ref.rollCount);
        toRefSelect.appendChild(toOption);
    });
}

// Update To Reference dropdown based on From Reference selection
function updateSunReferenceRange(autoSelect = true) {
    const fromRefSelect = document.getElementById('sun_from_reference');
    const toRefSelect = document.getElementById('sun_to_reference');
    
    if (!fromRefSelect || !toRefSelect) return;
    
    const fromValue = fromRefSelect.value;
    if (!fromValue) {
        const allOptions = Array.from(toRefSelect.options);
        allOptions.forEach(option => {
            option.style.display = '';
        });
        return;
    }
    
    // Get the selected From reference option
    const fromOption = fromRefSelect.options[fromRefSelect.selectedIndex];
    const isBundle = fromOption?.getAttribute('data-is-bundle') === 'true';
    let baseRef = fromOption?.getAttribute('data-base-ref') || '';
    let rollCount = parseInt(fromOption?.getAttribute('data-roll-count')) || 1;
    
    // Extract base reference from the selected value if not provided
    if (!baseRef) {
        const rollMatch = fromValue.match(/^(.+)-(\d+)$/);
        if (rollMatch) {
            baseRef = rollMatch[1];
        } else {
            baseRef = fromValue;
        }
    }
    
    // Find the bundle reference to get the actual roll count
    if (baseRef) {
        Array.from(fromRefSelect.options).forEach(option => {
            if (option.value && option.value !== '') {
                const optionBaseRef = option.getAttribute('data-base-ref') || option.value.replace(/-\d+$/, '');
                const optionIsBundle = option.getAttribute('data-is-bundle') === 'true';
                if (optionIsBundle && optionBaseRef === baseRef) {
                    rollCount = parseInt(option.getAttribute('data-roll-count')) || rollCount;
                }
            }
        });
    }
    
    // Find the index of the selected From reference in the To dropdown
    let fromIndex = -1;
    Array.from(toRefSelect.options).forEach((option, index) => {
        if (option.value === fromValue) {
            fromIndex = index;
        }
    });
    
    // If From reference is a bundle, we need to find the last roll of that bundle
    let targetFromIndex = fromIndex;
    if (isBundle && baseRef && rollCount > 1) {
        const lastRollRef = baseRef + '-' + rollCount;
        
        let foundLastRoll = false;
        Array.from(toRefSelect.options).forEach((option, index) => {
            if (option.value === lastRollRef) {
                targetFromIndex = index;
                foundLastRoll = true;
            }
        });
        
        if (!foundLastRoll) {
            Array.from(toRefSelect.options).forEach((option, index) => {
                if (index > fromIndex && option.value) {
                    const optionBaseRef = option.getAttribute('data-base-ref') || option.value.replace(/-\d+$/, '');
                    if (optionBaseRef !== baseRef) {
                        if (targetFromIndex === fromIndex) {
                            targetFromIndex = index;
                        }
                    }
                }
            });
            
            if (targetFromIndex === fromIndex) {
                targetFromIndex = fromIndex + 1;
            }
        }
    }
    
    // Show only references from the target From reference onwards
    Array.from(toRefSelect.options).forEach((option, index) => {
        if (index === 0) {
            option.style.display = '';
        } else if (index >= targetFromIndex) {
            option.style.display = '';
        } else {
            option.style.display = 'none';
        }
    });
    
    // Auto-select the last roll of the bundle in To dropdown
    if (autoSelect) {
        let actualBaseRef = baseRef;
        let actualRollCount = rollCount;
        
        if (!actualBaseRef) {
            const rollMatch = fromValue.match(/^(.+)-(\d+)$/);
            if (rollMatch) {
                actualBaseRef = rollMatch[1];
            } else {
                actualBaseRef = fromValue;
            }
        }
        
        if (actualBaseRef) {
            Array.from(fromRefSelect.options).forEach(option => {
                if (option.value && option.value !== '') {
                    const optionBaseRef = option.getAttribute('data-base-ref') || option.value.replace(/-\d+$/, '');
                    const optionIsBundle = option.getAttribute('data-is-bundle') === 'true';
                    if (optionIsBundle && optionBaseRef === actualBaseRef) {
                        actualRollCount = parseInt(option.getAttribute('data-roll-count')) || actualRollCount;
                    }
                }
            });
        }
        
        if (actualBaseRef && actualRollCount > 1) {
            const lastRollRef = actualBaseRef + '-' + actualRollCount;
            let found = false;
            Array.from(toRefSelect.options).forEach(option => {
                if (option.value === lastRollRef && option.style.display !== 'none') {
                    toRefSelect.value = lastRollRef;
                    found = true;
                    return;
                }
            });
            
            // If exact match not found, find the highest roll number from this bundle that's visible
            if (!found) {
                let highestRoll = 0;
                let highestRollRef = '';
                Array.from(toRefSelect.options).forEach(option => {
                    if (option.style.display !== 'none' && option.value) {
                        const optionBaseRef = option.getAttribute('data-base-ref') || option.value.replace(/-\d+$/, '');
                        if (optionBaseRef === actualBaseRef) {
                            const rollMatch = option.value.match(/-(\d+)$/);
                            if (rollMatch) {
                                const rollNum = parseInt(rollMatch[1]);
                                if (rollNum > highestRoll && rollNum <= actualRollCount) {
                                    highestRoll = rollNum;
                                    highestRollRef = option.value;
                                }
                            }
                        }
                    }
                });
                if (highestRollRef) {
                    toRefSelect.value = highestRollRef;
                }
            }
        } else if (fromIndex >= 0) {
            // Not a bundle, just select the same reference
            toRefSelect.value = fromValue;
        }
    }
}

// Apply bulk reference selection
function applySunBulkReferenceSelection() {
    const fromRef = document.getElementById('sun_from_reference').value;
    const toRef = document.getElementById('sun_to_reference').value;
    
    if (!fromRef || !toRef) {
        alert('Please select both From and To references');
        return;
    }
    
    // Store the range in hidden input
    const lineBasedProductRef = document.getElementById('sun_line_based_product_reference');
    if (lineBasedProductRef) {
        lineBasedProductRef.value = fromRef + ' to ' + toRef;
    }
    
    // Update the main reference dropdown to show the range
    const productRefSelect = document.getElementById('reference_number');
    if (productRefSelect) {
        // Find or create an option for the range
        let rangeOption = Array.from(productRefSelect.options).find(opt => opt.value === fromRef + '|' + toRef);
        if (!rangeOption) {
            rangeOption = document.createElement('option');
            rangeOption.value = fromRef + '|' + toRef;
            rangeOption.textContent = fromRef + ' to ' + toRef;
            productRefSelect.appendChild(rangeOption);
        }
        productRefSelect.value = rangeOption.value;
    }
}

// Clear bulk reference selection
function clearSunBulkReferenceSelection() {
    const fromRefSelect = document.getElementById('sun_from_reference');
    const toRefSelect = document.getElementById('sun_to_reference');
    const lineBasedProductRef = document.getElementById('sun_line_based_product_reference');
    
    if (fromRefSelect) fromRefSelect.value = '';
    if (toRefSelect) toRefSelect.value = '';
    if (lineBasedProductRef) lineBasedProductRef.value = '';
    
    // Reset To dropdown to show all options
    if (toRefSelect) {
        Array.from(toRefSelect.options).forEach(option => {
            option.style.display = '';
        });
    }
}

// Handle From/To reference change
function handleSunFromToReferenceChange() {
    // This function can be extended to perform validation or other actions
    // when From/To references change
}

// Validate form before submission
function validateSunForm() {
    const productRefSelect = document.getElementById('reference_number');
    const fromRefSelect = document.getElementById('sun_from_reference');
    const toRefSelect = document.getElementById('sun_to_reference');
    const individualRollSelect = document.getElementById('sun_individual_roll_reference');
    
    // Check if line-based selection is active (From/To visible)
    const bulkRefSelection = document.getElementById('sun_bulk_reference_selection');
    const isLineBased = bulkRefSelection && bulkRefSelection.style.display !== 'none';
    
    if (isLineBased) {
        // Validate From/To references
        const fromValue = fromRefSelect ? fromRefSelect.value : '';
        const toValue = toRefSelect ? toRefSelect.value : '';
        
        if (!fromValue || !toValue) {
            alert('Please select both From Reference and To Reference');
            if (!fromValue && fromRefSelect) fromRefSelect.focus();
            else if (!toValue && toRefSelect) toRefSelect.focus();
            return false;
        }
        
        // Ensure the reference field name is removed so it doesn't interfere
        if (productRefSelect) {
            productRefSelect.removeAttribute('name');
        }
        
        // Ensure from/to have their names set
        if (fromRefSelect) fromRefSelect.setAttribute('name', 'from_reference');
        if (toRefSelect) toRefSelect.setAttribute('name', 'to_reference');
    } else {
        // Validate single reference or individual roll
        const refValue = productRefSelect ? productRefSelect.value : '';
        const individualValue = individualRollSelect && individualRollSelect.style.display !== 'none' 
            ? individualRollSelect.value : '';
        
        if (!refValue && !individualValue) {
            alert('Please select a reference');
            if (productRefSelect) productRefSelect.focus();
            return false;
        }
        
        // Ensure the reference field has its name set
        if (productRefSelect) {
            productRefSelect.setAttribute('name', 'reference_number');
        }
        
        // Remove names from from/to so they don't interfere
        if (fromRefSelect) fromRefSelect.removeAttribute('name');
        if (toRefSelect) toRefSelect.removeAttribute('name');
    }
    
    // Also validate dates
    if (typeof validateDates === 'function') {
        if (!validateDates()) {
            return false;
        }
    }
    
    return true;
}

// Handle reference selection - check if bundle and show individual roll selector
function handleSunReferenceSelection(selectedValue) {
    const productRefSelect = document.getElementById('reference_number');
    const individualRollSelect = document.getElementById('sun_individual_roll_reference');
    const bundleInfo = document.getElementById('sun_bundle_info');
    
    if (!selectedValue || selectedValue === '') {
        // Hide individual roll selector
        if (individualRollSelect) {
            individualRollSelect.style.display = 'none';
            individualRollSelect.value = '';
            individualRollSelect.removeAttribute('required');
        }
        if (bundleInfo) bundleInfo.style.display = 'none';
        loadReferenceData('');
        return;
    }
    
    // Get the selected option
    const selectedOption = productRefSelect.options[productRefSelect.selectedIndex];
    const isBundle = selectedOption?.getAttribute('data-is-bundle') === 'true';
    
    if (isBundle) {
        // Show individual roll selector
        const baseRef = selectedOption.getAttribute('data-base-ref');
        const rollCount = parseInt(selectedOption.getAttribute('data-roll-count')) || 1;
        const bundleRef = selectedValue;
        
        // Fetch already tested rolls from this bundle
        fetch(`api/get_tested_rolls_sun.php?bundle_ref=${encodeURIComponent(bundleRef)}`)
            .then(response => response.json())
            .then(data => {
                const testedRolls = data.success ? data.tested_rolls : [];
                
                // Populate individual roll dropdown, excluding already tested rolls
                if (individualRollSelect) {
                    individualRollSelect.innerHTML = '<option value="">-- Select Individual Roll for Testing --</option>';
                    for (let i = 1; i <= rollCount; i++) {
                        const individualRef = baseRef + '-' + i;
                        
                        // Skip if this roll has already been tested
                        if (testedRolls.includes(individualRef)) {
                            continue;
                        }
                        
                        const option = document.createElement('option');
                        option.value = individualRef;
                        option.textContent = `Roll ${i} - ${individualRef}`;
                        individualRollSelect.appendChild(option);
                    }
                    individualRollSelect.style.display = 'block';
                    individualRollSelect.setAttribute('required', 'required');
                }
                if (bundleInfo) bundleInfo.style.display = 'block';
                
                // Don't load reference data yet - wait for individual roll selection
                loadReferenceData('');
            })
            .catch(error => {
                console.error('Error fetching tested rolls:', error);
                // Fallback: show all rolls if API fails
                if (individualRollSelect) {
                    individualRollSelect.innerHTML = '<option value="">-- Select Individual Roll for Testing --</option>';
                    for (let i = 1; i <= rollCount; i++) {
                        const option = document.createElement('option');
                        const individualRef = baseRef + '-' + i;
                        option.value = individualRef;
                        option.textContent = `Roll ${i} - ${individualRef}`;
                        individualRollSelect.appendChild(option);
                    }
                    individualRollSelect.style.display = 'block';
                    individualRollSelect.setAttribute('required', 'required');
                }
                if (bundleInfo) bundleInfo.style.display = 'block';
                
                // Don't load reference data yet - wait for individual roll selection
                loadReferenceData('');
            });
    } else {
        // Hide individual roll selector
        if (individualRollSelect) {
            individualRollSelect.style.display = 'none';
            individualRollSelect.value = '';
            individualRollSelect.removeAttribute('required');
        }
        if (bundleInfo) bundleInfo.style.display = 'none';
        
        // Load reference data for single roll
        loadReferenceData(selectedValue);
    }
}

// Handle individual roll selection from bundle
function handleSunIndividualRollSelection(selectedValue) {
    if (selectedValue && selectedValue !== '') {
        // Check if this is from a bundle and get first test data to pre-fill
        const productRefSelect = document.getElementById('reference_number');
        const selectedOption = productRefSelect.options[productRefSelect.selectedIndex];
        const isBundle = selectedOption?.getAttribute('data-is-bundle') === 'true';
        const bundleRef = selectedOption?.value || '';
        
        if (isBundle && bundleRef) {
            // Fetch first test data from bundle to pre-fill form
            fetch(`api/get_first_bundle_test_data_sun.php?bundle_ref=${encodeURIComponent(bundleRef)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        // Pre-fill form with first test data
                        if (data.data.reference_name) document.querySelector('input[name="reference_name"]').value = data.data.reference_name;
                        if (data.data.sample_description) document.querySelector('input[name="sample_description"]').value = data.data.sample_description;
                        if (data.data.sample_received_from) document.querySelector('input[name="sample_received_from"]').value = data.data.sample_received_from;
                        if (data.data.sample_collected_from) document.querySelector('input[name="sample_collected_from"]').value = data.data.sample_collected_from;
                        if (data.data.testing_method) document.querySelector('input[name="testing_method"]').value = data.data.testing_method;
                        if (data.data.test_name) document.querySelector('input[name="test_name"]').value = data.data.test_name;
                        if (data.data.test_speed) document.querySelector('input[name="test_speed"]').value = data.data.test_speed;
                        if (data.data.gauge_length) document.querySelector('input[name="gauge_length"]').value = data.data.gauge_length;
                        if (data.data.specimen_size) document.querySelector('input[name="specimen_size"]').value = data.data.specimen_size;
                        if (data.data.temperature) document.querySelector('input[name="temperature"]').value = data.data.temperature;
                        if (data.data.rh_percent) document.querySelector('input[name="rh_percent"]').value = data.data.rh_percent;
                        updateSampleDescription();
                    }
                    // Still load reference data for sample description
                    loadReferenceData(selectedValue);
                })
                .catch(error => {
                    console.error('Error fetching bundle test data:', error);
                    loadReferenceData(selectedValue);
                });
        } else {
            loadReferenceData(selectedValue);
        }
    }
}

// Load reference data when reference is selected
function loadReferenceData(reference) {
    document.getElementById('reference_error').textContent = '';
    
    if (!reference || reference === '') {
        // Clear fields if not pre-filled from bundle
        if (!document.getElementById('sample_description').value) {
            document.getElementById('sample_description').value = '';
        }
        return;
    }
    
    // Generate sample description from reference number (if not already filled)
    if (!document.getElementById('sample_description').value) {
        generateSampleDescriptionFromReference(reference);
    }
}

function extractLabTestNumber() {
    const reportNumber = document.getElementById('report_number').value;
    // Report format: SUN-YYYYMMDD-XXXXX
    // Extract last 2 or 3 digits based on the number
    const match = reportNumber.match(/-(\d{5})$/);
    if (match) {
        const fullNumber = match[1]; // "00001", "00099", "00100"
        const numericValue = parseInt(fullNumber, 10); // 1, 99, 100
        
        if (numericValue > 99) {
            // Use last 3 digits for numbers > 99
            document.getElementById('lab_test_number').value = fullNumber.slice(-3); // "100"
        } else {
            // Use last 2 digits for numbers <= 99
            document.getElementById('lab_test_number').value = fullNumber.slice(-2); // "01"
        }
        updateSampleDescription();
    }
}

// Generate sample description from reference number
// Example: "3.2L225NOV17-R08-GT0.9.H0.1" -> "3.2L25NOV17-LT01-R08-JI0.9H0.1"
function generateSampleDescriptionFromReference(referenceNumber) {
    if (!referenceNumber || referenceNumber === '') {
        document.getElementById('sample_description').value = '';
        return;
    }
    
    // Get lab test number (ensure it's extracted first)
    const labTestNumber = document.getElementById('lab_test_number').value;
    if (!labTestNumber) {
        // Try to extract if not already set
        extractLabTestNumber();
        const labTestNum = document.getElementById('lab_test_number').value;
        if (!labTestNum) {
            document.getElementById('sample_description').value = '';
            return;
        }
    }
    
    // Format lab test number: 1 -> LT01, 99 -> LT99, 100 -> LT100
    const labTestFormatted = 'LT' + String(document.getElementById('lab_test_number').value).padStart(2, '0');
    
    // Split reference number by "-"
    const parts = referenceNumber.split('-');
    
    if (parts.length < 2) {
        // If format doesn't match expected, use fallback
        updateSampleDescription();
        return;
    }
    
    // Transform first part: "3.2L225NOV17" -> "3.2L25NOV17"
    // Extract date pattern and modify: looks like "225" becomes "25" (last 2 digits)
    let firstPart = parts[0];
    // Match pattern like "3.2L225NOV17" - extract the number before month
    const firstMatch = firstPart.match(/^(.+?)(\d+)([A-Z]{3}\d{2})$/);
    if (firstMatch) {
        const prefix = firstMatch[1]; // "3.2L"
        const numberPart = firstMatch[2]; // "225"
        const datePart = firstMatch[3]; // "NOV17"
        // Take last 2 digits of the number part
        const modifiedNumber = numberPart.slice(-2);
        firstPart = prefix + modifiedNumber + datePart; // "3.2L25NOV17"
    }
    
    // Middle parts stay the same (like "R08")
    const middleParts = parts.slice(1, -1);
    
    // Transform last part: "GT0.9.H0.1" -> "JI0.9H0.1"
    // GT becomes JI, and remove dot between numbers and H
    let lastPart = parts[parts.length - 1];
    lastPart = lastPart.replace(/^GT/, 'JI'); // GT -> JI
    lastPart = lastPart.replace(/\.([A-Z])/, '$1'); // Remove dot before letter (like .H -> H)
    
    // Build sample description: PART1-LTXX-PART2-PART3...-LASTPART
    let sampleDesc = firstPart;
    sampleDesc += '-' + labTestFormatted;
    if (middleParts.length > 0) {
        sampleDesc += '-' + middleParts.join('-');
    }
    sampleDesc += '-' + lastPart;
    
    document.getElementById('sample_description').value = sampleDesc;
}

function updateSampleDescription() {
    const labTestNumber = document.getElementById('lab_test_number').value;
    const referenceName = document.getElementById('reference_name').value;
    const referenceNumber = document.getElementById('reference_number').value;
    
    // If reference number is selected, use it to generate sample description
    if (referenceNumber && referenceNumber !== '') {
        generateSampleDescriptionFromReference(referenceNumber);
        return;
    }
    
    if (!labTestNumber) {
        document.getElementById('sample_description').value = '';
        return;
    }
    
    // Get current year (last 2 digits), month (3 letters), and date
    const now = new Date();
    const year = now.getFullYear().toString().slice(-2); // Last 2 digits: 2025 -> 25
    const monthNames = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
    const month = monthNames[now.getMonth()];
    const date = String(now.getDate()).padStart(2, '0');
    
    // Format lab test number: 1 -> LT01, 99 -> LT99, 100 -> LT100
    const labTestFormatted = 'LT' + String(labTestNumber).padStart(2, '0');
    
    // Generate sample description: YYMonDD-LTXX-ReferenceName
    let sampleDesc = `${year}${month}${date}-${labTestFormatted}`;
    if (referenceName) {
        sampleDesc += `-${referenceName}`;
    }
    
    document.getElementById('sample_description').value = sampleDesc;
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

function validateDates() {
    const testStartDateInput = document.querySelector('input[name="test_start_date"]');
    const testEndDateInput = document.querySelector('input[name="test_end_date"]');

    if (testStartDateInput && testEndDateInput) {
        const startDate = new Date(testStartDateInput.value);
        const endDate = new Date(testEndDateInput.value);

        if (endDate < startDate) {
            alert('Test End Date cannot be earlier than Test Start Date. Please correct the date.');
            testEndDateInput.focus();
            return false;
        }
    }
    return true;
}

function validateSampleDates() {
    const productionDateInput = document.getElementById('sample_production_date');
    const receivedDateInput = document.getElementById('received_date');
    const errorDiv = document.getElementById('sample_date_error');
    
    if (productionDateInput && receivedDateInput && productionDateInput.value && receivedDateInput.value) {
        const productionDate = new Date(productionDateInput.value);
        const receivedDate = new Date(receivedDateInput.value);
        
        if (receivedDate < productionDate) {
            errorDiv.textContent = '❌ Sample Received Date cannot be earlier than Sample Production Date';
            receivedDateInput.focus();
            return false;
        } else {
            errorDiv.textContent = '';
        }
    }
    return true;
}
setInterval(updateTimeAndShift, 1000);

function setDirection(button, direction) {
    const row = button.getAttribute('data-row');
    const buttons = document.querySelectorAll(`[data-row="${row}"]`);
    buttons.forEach(btn => {
        btn.classList.remove('active');
        btn.style.background = '#fff';
        btn.style.color = '#2c3e50';
    });
    button.classList.add('active');
    button.style.background = '#007bff';
    button.style.color = '#fff';
    document.querySelector(`input[name="test_direction_${row}"]`).value = direction;
}

// === Utility: robust parse that strips % and commas and extracts the first numeric token ===
function parseNumberFromInputEl(el) {
    if (!el) return null;
    const raw = (el.value ?? '').toString().trim();
    if (raw === '') return null;
    // remove commas and percent sign, allow scientific notation
    const cleaned = raw.replace(/,/g, '').replace(/%/g, '');
    const m = cleaned.match(/-?\d+(\.\d+)?(e[+\-]?\d+)?/i);
    if (!m) return null;
    const n = parseFloat(m[0]);
    return Number.isFinite(n) ? n : null;
}

// === Called when user edits after/before breaking force for a row ===
function calculateForceRetain(input) {
    const row = input.getAttribute('data-row');
    const afterEl  = document.querySelector(`input[name="breaking_force_after_${row}"]`);
    const beforeEl = document.querySelector(`input[name="breaking_force_before_${row}"]`);
    const frEl     = document.querySelector(`input[name="force_retain_${row}"]`);

    const after  = parseNumberFromInputEl(afterEl)  ?? 0;
    const before = parseNumberFromInputEl(beforeEl) ?? 0;

    if (frEl) {
        if (before > 0) {
            const retainNumeric = (after / before) * 100;
            // show human-friendly value but keep numeric in data-value for calculations and server
            frEl.value = retainNumeric.toFixed(1) + ' %';
            frEl.dataset.value = retainNumeric.toFixed(6); // keep high precision for calculations
        } else {
            frEl.value = '';
            frEl.dataset.value = '';
        }
    }

    calculateStatistics(); // refresh all aggregates
}

// === Compute stats (average, sample SD, CV, max, min) for all columns and update the DOM ===
function calculateStatistics() {
    // collect numeric arrays for each column
    const arrays = {
        bf_after:  [],
        bf_before: [],
        fr:        [],
        el_after:  [],
        el_before: []
    };

    // Dynamic specimens count - up to 20
    for (let i = 1; i <= 20; i++) {
        const v_bf_after  = parseNumberFromInputEl(document.querySelector(`input[name="breaking_force_after_${i}"]`));
        const v_bf_before = parseNumberFromInputEl(document.querySelector(`input[name="breaking_force_before_${i}"]`));
        const frEl = document.querySelector(`input[name="force_retain_${i}"]`);
        let v_fr = null;
        if (frEl) {
            // prefer the stored numeric dataset if present (set by calculateForceRetain), else parse the displayed string
            v_fr = frEl.dataset.value ? parseFloat(frEl.dataset.value) : parseNumberFromInputEl(frEl);
        }
        const v_el_after  = parseNumberFromInputEl(document.querySelector(`input[name="elongation_after_${i}"]`));
        const v_el_before = parseNumberFromInputEl(document.querySelector(`input[name="elongation_before_${i}"]`));

        if (v_bf_after !== null) arrays.bf_after.push(v_bf_after);
        if (v_bf_before !== null) arrays.bf_before.push(v_bf_before);
        if (v_fr !== null && !isNaN(v_fr)) arrays.fr.push(v_fr);
        if (v_el_after !== null) arrays.el_after.push(v_el_after);
        if (v_el_before !== null) arrays.el_before.push(v_el_before);
    }

    // helper to compute stats (sample SD)
    function computeStats(arr) {
        if (!Array.isArray(arr) || arr.length === 0) return { avg: 0, sd: 0, cv: 0, max: 0, min: 0 };
        const n = arr.length;
        const sum = arr.reduce((s, v) => s + v, 0);
        const avg = sum / n;
        const sd = (n > 1) ? Math.sqrt(arr.reduce((s, v) => s + Math.pow(v - avg, 2), 0) / (n - 1)) : 0;
        const cv = (avg !== 0) ? (sd / avg) * 100 : 0;
        return { avg, sd, cv, max: Math.max(...arr), min: Math.min(...arr) };
    }

    // update DOM helper (IDs used in your markup)
    function updateDom(key, statObj) {
        // stat keys in your HTML are: stat_<key>_average, stat_<key>_sd, stat_<key>_cv, stat_<key>_maximum, stat_<key>_minimum
        const fmt1 = x => Number.isFinite(x) ? x.toFixed(1) : '0.0';
        const elAvg = document.getElementById(`stat_${key}_average`);
        const elSd  = document.getElementById(`stat_${key}_sd`);
        const elCv  = document.getElementById(`stat_${key}_cv`);
        const elMax = document.getElementById(`stat_${key}_maximum`);
        const elMin = document.getElementById(`stat_${key}_minimum`);
        
        if (elAvg) elAvg.textContent = fmt1(statObj.avg);
        if (elMax) elMax.textContent = fmt1(statObj.max);
        if (elMin) elMin.textContent = fmt1(statObj.min);
        
        // Skip SD and CV for Force Retain (fr)
        if (key !== 'fr') {
            if (elSd) elSd.textContent = fmt1(statObj.sd); // SD with 1 decimal
            if (elCv) elCv.textContent = fmt1(statObj.cv);
        } else {
            if (elSd) elSd.textContent = '-';
            if (elCv) elCv.textContent = '-';
        }
    }

    // compute + update for each
    updateDom('bf_after',  computeStats(arrays.bf_after));
    updateDom('bf_before', computeStats(arrays.bf_before));
    updateDom('fr',        computeStats(arrays.fr));
    updateDom('el_after',  computeStats(arrays.el_after));
    updateDom('el_before', computeStats(arrays.el_before));

    // update the summary result fields (After, Before, Retain)
    const avgStr = (arr) => arr.length ? (arr.reduce((a,b)=>a+b,0)/arr.length).toFixed(1) : '0.0';
    const outAfter = document.getElementById('result_after');
    const outBefore = document.getElementById('result_before');
    const outRetain = document.getElementById('result_retain');
    if (outAfter)  outAfter.textContent  = avgStr(arrays.bf_after);
    if (outBefore) outBefore.textContent = avgStr(arrays.bf_before);
    if (outRetain) outRetain.textContent = avgStr(arrays.fr);
}

// === Watch elongation inputs too ===
document.addEventListener('DOMContentLoaded', () => {
    // Whenever an elongation input changes, recompute stats
    for (let i = 1; i <= 20; i++) {
        const elAfter  = document.querySelector(`input[name="elongation_after_${i}"]`);
        const elBefore = document.querySelector(`input[name="elongation_before_${i}"]`);
        [elAfter, elBefore].forEach(el => {
            if (el) el.addEventListener('input', () => {
                if (parseNumberFromInputEl(el) !== null) {
                    calculateStatistics();
                }
            });
        });
    }
    
    // Also add event listeners for dynamically added rows
    document.addEventListener('input', (e) => {
        if (e.target.name && (e.target.name.startsWith('elongation_after_') || e.target.name.startsWith('elongation_before_'))) {
            calculateStatistics();
        }
    });
});

function clearForm() {
    if (confirm('Are you sure you want to clear all form data?')) {
        document.querySelector('form').reset();
        document.querySelector('input[name="test_performed_by"]').value = '<?php echo htmlspecialchars($reporter_full_name); ?>';
        // Regenerate report number
        const newReportNumber = '<?php echo htmlspecialchars($handler->getNextReportNumber()); ?>';
        document.querySelector('input[name="report_number"]').value = newReportNumber;
        
        // Reset direction buttons
        for (let i = 1; i <= 10; i++) {
            const buttons = document.querySelectorAll(`[data-row="${i}"]`);
            buttons.forEach(btn => {
                btn.classList.remove('active');
                btn.style.background = '#fff';
                btn.style.color = '#2c3e50';
            });
            buttons[0].classList.add('active');
            buttons[0].style.background = '#007bff';
            buttons[0].style.color = '#fff';
            document.querySelector(`input[name="test_direction_${i}"]`).value = 'MD';
        }
        
        // Clear statistics
        calculateStatistics();
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
        // User clicked Cancel
        return false;
    }
    
    if (comment.trim() === '') {
        alert('❌ Comment is required when rejecting a report!\n\nPlease provide a reason for rejection.');
        return confirmRejection(form); // Recursively ask again
    }
    
    // Update the hidden comment field with the actual comment
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

// Sun Test rejection modal functions
function openSunRejectModal(reportNumber) {
    document.getElementById('sunRejectReportNumber').value = reportNumber;
    document.getElementById('sunRejectionModal').style.display = 'block';
}

function closeSunRejectModal() {
    document.getElementById('sunRejectionModal').style.display = 'none';
    document.getElementById('sunAdminRejectForm').reset();
}

function submitSunAdminRejection() {
    const checkboxes = document.querySelectorAll('input[name="sun_rejection_reasons[]"]');
    const checked = Array.from(checkboxes).filter(cb => cb.checked);
    
    if (checked.length === 0) {
        alert('❌ Please select at least one reason for rejection!');
        return false;
    }
    
    if (confirm('Are you sure you want to reject this report?')) {
        const form = document.getElementById('sunAdminRejectForm');
        form.onsubmit = null;
        form.submit();
    }
}

function updateDeleteButtonStates() {
    const tbody = document.getElementById('sun_tbody');
    if (!tbody) return;
    const rows = tbody.querySelectorAll('tr');
    const deleteButtons = tbody.querySelectorAll('button[onclick*="removeSunRow"]');
    
    // If only one row, disable all delete buttons
    if (rows.length <= 1) {
        deleteButtons.forEach(btn => {
            btn.disabled = true;
            btn.style.opacity = '0.5';
            btn.style.cursor = 'not-allowed';
            btn.title = 'At least one row is required';
        });
    } else {
        // Enable all delete buttons
        deleteButtons.forEach(btn => {
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
            btn.title = '';
        });
    }
}

function addSunRow() {
    const tbody = document.getElementById('sun_tbody');
    if (!tbody) {
        alert('Error: Could not find table body');
        return;
    }
    const currentRows = tbody.querySelectorAll('tr');
    const newRowNum = currentRows.length + 1;
    
    const row = document.createElement('tr');
    row.innerHTML = `
        <td>${newRowNum}</td>
        <td>
            <div style="display:inline-flex; gap:6px; align-items:center;">
                <button type="button" class="dir-btn active" data-row="${newRowNum}" onclick="setDirection(this,'MD')">MD${newRowNum}</button>
                <button type="button" class="dir-btn" data-row="${newRowNum}" onclick="setDirection(this,'CD')">CD${newRowNum}</button>
                <input type="hidden" name="test_direction_${newRowNum}" value="MD">
            </div>
        </td>
        <td><input type="number" step="0.01" min="0" name="breaking_force_after_${newRowNum}" data-row="${newRowNum}" class="bf-after" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0; calculateForceRetain(this)"></td>
        <td><input type="number" step="0.01" min="0" name="breaking_force_before_${newRowNum}" data-row="${newRowNum}" class="bf-before" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0; calculateForceRetain(this)"></td>
        <td><input type="text" name="force_retain_${newRowNum}" class="fr" readonly value="" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px; background:#f8f9fa;"></td>
        <td><input type="number" step="0.01" min="0" name="elongation_after_${newRowNum}" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0"></td>
        <td><input type="number" step="0.01" min="0" name="elongation_before_${newRowNum}" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0"></td>
        <td><button type="button" onclick="removeSunRow(this)" style="padding:4px 8px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:12px;">Delete</button></td>
    `;
    tbody.appendChild(row);
    
    // Update delete button states
    updateDeleteButtonStates();
    
    // Update statistics
    calculateStatistics();
}

function removeSunRow(button) {
    const tbody = document.getElementById('sun_tbody');
    const rows = tbody.querySelectorAll('tr');
    
    // Prevent deletion if there's only one row remaining
    if (rows.length <= 1) {
        alert('At least one row is required. Cannot delete the last row.');
        return;
    }
    
    const row = button.closest('tr');
    if (confirm('Are you sure you want to delete this row?')) {
        row.remove();
        // Renumber all rows
        const remainingRows = tbody.querySelectorAll('tr');
        remainingRows.forEach((row, index) => {
            const rowNum = index + 1;
            row.querySelector('td:first-child').textContent = rowNum;
            // Update all inputs and buttons in this row
            row.querySelectorAll('input, button').forEach(input => {
                if (input.name) {
                    input.name = input.name.replace(/\d+/, rowNum);
                }
                if (input.hasAttribute('data-row')) {
                    input.setAttribute('data-row', rowNum);
                }
            });
            // Update button labels
            const buttons = row.querySelectorAll('.dir-btn');
            buttons.forEach((btn, btnIndex) => {
                btn.textContent = (btnIndex === 0 ? 'MD' : 'CD') + rowNum;
            });
        });
        // Update delete button states
        updateDeleteButtonStates();
        // Update statistics
        calculateStatistics();
    }
}

function deleteSunReport(reportNumber) {
    if (confirm('Are you sure you want to delete this report? This action cannot be undone.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="delete_report_number" value="${reportNumber}">
            <input type="hidden" name="delete_report" value="1">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<!-- Rejection Modal for Admin (Sun Test) -->
<div id="sunRejectionModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow-y:auto;">
  <div style="max-width:600px; margin:50px auto; background:#fff; border-radius:8px; padding:25px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
    <h3 style="margin-top:0; color:#dc3545; border-bottom:2px solid #dc3545; padding-bottom:10px;">
      ❌ Reject Sun Test Report
    </h3>
    
    <form id="sunAdminRejectForm" method="POST" action="" onsubmit="return false;">
      <input type="hidden" id="sunRejectReportNumber" name="wf_report_number" value="">
      <input type="hidden" name="wf_action" value="rejected">
      
      <label style="font-weight:600; display:block; margin-bottom:10px;">Reason for Rejection (Select at least one):</label>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="sun_rejection_reasons[]" value="Incorrect Roll Identification" style="margin-right:8px;">
          Incorrect Roll Identification
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="sun_rejection_reasons[]" value="Incorrect Fiber Specification Entry" style="margin-right:8px;">
          Incorrect Fiber Specification Entry
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="sun_rejection_reasons[]" value="Excessive Sampling" style="margin-right:8px;">
          Excessive Sampling
        </label>
      </div>
      
      <label style="font-weight:bold; display:block; margin:15px 0 8px 0;">
        Additional Comments (Optional):
      </label>
      <textarea name="wf_comment" id="sunAdminRejectComment" rows="4" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-family:inherit;" placeholder="Provide additional details..."></textarea>
      
      <div style="margin-top:20px; text-align:right;">
        <button type="button" onclick="closeSunRejectModal()" style="padding:10px 20px; margin-right:10px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer;">Cancel</button>
        <button type="button" onclick="submitSunAdminRejection()" style="padding:10px 20px; background:#dc3545; color:#fff; border:none; border-radius:6px; cursor:pointer;">Submit Rejection</button>
      </div>
    </form>
  </div>
</div>

</body>
</html>

