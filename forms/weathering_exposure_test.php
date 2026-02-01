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

// Fetch reference numbers from roll_entry that don't have UV reports yet - support bundles and line detection
// Tests are done AFTER roll entry submission
// Exclude references that have been routed by AGM
$references = [];
$bundleReferences = [];

// Helper function to check if a reference has been routed
$isReferenceRouted = function($conn, $reference) {
    // Check if routed table exists
    $routedTableExists = $conn->query("SHOW TABLES LIKE 'routed'")->num_rows > 0;
    if (!$routedTableExists) {
        return false;
    }
    
    // Check exact match first
    $stmt = $conn->prepare("SELECT 1 FROM routed WHERE reference_number = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $result = $stmt->get_result();
        $routed = ($result && $result->num_rows > 0);
        $stmt->close();
        if ($routed) return true;
    }
    
    // Check prefix match (stored ref is prefix of query ref)
    $stmt = $conn->prepare("SELECT 1 FROM routed WHERE ? LIKE CONCAT(reference_number, '%') LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $result = $stmt->get_result();
        $routed = ($result && $result->num_rows > 0);
        $stmt->close();
        if ($routed) return true;
    }
    
    // Check prefix match (query ref is prefix of stored ref)
    $stmt = $conn->prepare("SELECT 1 FROM routed WHERE reference_number LIKE CONCAT(?, '%') LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $result = $stmt->get_result();
        $routed = ($result && $result->num_rows > 0);
        $stmt->close();
        return $routed;
    }
    
    return false;
};

$refQuery = $conn->query("
    SELECT DISTINCT re.reference_number, MAX(re.date_time) as created_at
    FROM roll_entry re
    WHERE re.reference_number IS NOT NULL 
    AND re.reference_number NOT IN (
        SELECT DISTINCT reference 
        FROM weathering_exposure_reports 
        WHERE reference IS NOT NULL AND reference != ''
    )
    GROUP BY re.reference_number
    ORDER BY created_at DESC 
    LIMIT 50
");
if ($refQuery) {
    while ($row = $refQuery->fetch_assoc()) {
        $ref = $row['reference_number'];
        
        // Check if this reference has been routed by AGM - skip if routed
        if ($isReferenceRouted($conn, $ref)) {
            continue;
        }
        
        // Detect line number (L1 or L2) from reference
        $lineIndicator = '';
        if (strpos($ref, 'L1') !== false) {
            $lineIndicator = 'L1';
        } elseif (strpos($ref, 'L2') !== false) {
            $lineIndicator = 'L2';
        }
        
        // Check if this is a bundle reference (ends with -N pattern)
        if (preg_match('/-(\d+)$/', $ref, $matches)) {
            $rollCount = (int)$matches[1];
            $baseRef = preg_replace('/-\d+$/', '', $ref);
            
            $bundleReferences[] = [
                'reference' => $ref,
                'base_reference' => $baseRef,
                'roll_count' => $rollCount,
                'line' => $lineIndicator,
                'date' => $row['created_at']
            ];
        } else {
            $references[] = [
                'reference' => $ref,
                'line' => $lineIndicator,
                'date' => $row['created_at']
            ];
        }
    }
}


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

// Check for success message from redirect
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

/**
 * Enhanced UV Test Report Handler Class
 */
class WeatheringExposureTestHandler {
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
        $likePrefix = "UV-{$dayKey}-%";
        $countStmt = $this->conn->prepare("SELECT COUNT(*) AS num_reports FROM weathering_exposure_reports WHERE report_number LIKE ?");
        $countStmt->bind_param("s", $likePrefix);
        $countStmt->execute();
        $countRes = $countStmt->get_result();
        $countRow = $countRes->fetch_assoc();
        $numReportsToday = (int)($countRow['num_reports'] ?? 0);
        $countStmt->close();

        $nextSeq = $numReportsToday + 1; // show 1 if none submitted, else next
        
        // Generate report number: UV-YYYYMMDD-XXXXX
        return "UV-{$dayKey}-" . str_pad($nextSeq, 5, '0', STR_PAD_LEFT);
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
        $likePrefix = "UV-{$dayKey}-%";
        $countStmt = $this->conn->prepare("SELECT COUNT(*) AS num_reports FROM weathering_exposure_reports WHERE report_number LIKE ?");
        $countStmt->bind_param("s", $likePrefix);
        $countStmt->execute();
        $countRes = $countStmt->get_result();
        $countRow = $countRes->fetch_assoc();
        $numReportsToday = (int)($countRow['num_reports'] ?? 0);
        $countStmt->close();

        // Initialize or bump counter
        $initStmt = $this->conn->prepare("
            INSERT INTO weathering_exposure_counters (day_key, counter) VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE counter = GREATEST(counter, VALUES(counter))
        ");
        $targetStart = max(0, $numReportsToday); // store last used value; will add +1 next
        $initStmt->bind_param("si", $dayKey, $targetStart);
        $initStmt->execute();
        $initStmt->close();

        // Atomic increment
        $incStmt = $this->conn->prepare("UPDATE weathering_exposure_counters SET counter = counter + 1 WHERE day_key = ?");
        $incStmt->bind_param("s", $dayKey);
        $incStmt->execute();
        $incStmt->close();

        // Read back
        $selectStmt = $this->conn->prepare("SELECT counter FROM weathering_exposure_counters WHERE day_key = ?");
        $selectStmt->bind_param("s", $dayKey);
        $selectStmt->execute();
        $result = $selectStmt->get_result();
        $row = $result->fetch_assoc();
        $counter = (int)($row['counter'] ?? 1);
        $selectStmt->close();
        
        // Generate report number: UV-YYYYMMDD-XXXXX
        return "UV-{$dayKey}-" . str_pad($counter, 5, '0', STR_PAD_LEFT);
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
            'sample_collected_from', 
            'sample_description', 'recipe', 'received_date', 'test_start_date', 
            'test_end_date', 'testing_method', 'test_name', 'test_speed', 
            'gauge_length', 'specimen_size', 'temperature', 'rh_percent', 
            'test_performed_by'
        ];
        
        foreach ($required_fields as $field) {
            if (empty(trim($data[$field] ?? ''))) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
            }
        }
        
        // Validate reference: either 'reference', 'individual_roll_reference', or 'from_reference'/'to_reference' must be provided
        $hasReference = false;
        if (!empty(trim($data['individual_roll_reference'] ?? ''))) {
            $hasReference = true;
        } elseif (!empty(trim($data['from_reference'] ?? '')) && !empty(trim($data['to_reference'] ?? ''))) {
            $hasReference = true;
        } elseif (!empty(trim($data['reference'] ?? ''))) {
            // Check if it's a range format (from|to)
            if (strpos($data['reference'], '|') !== false) {
                $parts = explode('|', $data['reference']);
                if (count($parts) === 2 && !empty(trim($parts[0])) && !empty(trim($parts[1]))) {
                    $hasReference = true;
                }
            } else {
                $hasReference = true;
            }
        }
        
        if (!$hasReference) {
            $errors[] = 'Reference is required. Please select a reference or use From/To reference selection.';
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
            
            // Insert report (without tested_by column; status bound as placeholder)
            $stmt = $this->conn->prepare(
                "INSERT INTO weathering_exposure_reports (
                    report_number, sample_collected_from, reference, bundle_reference,
                    sample_description, recipe, received_date, test_start_date, test_end_date, 
                    testing_method, test_name, test_speed, gauge_length, specimen_size, note, 
                    temperature, rh_percent, test_performed_by, approved_by, test_results, 
                    reporter_id, reporter_name, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            // Auto-approve if submitted by admin or AGM Ops
            $user_role = strtolower(trim($_SESSION['role'] ?? ''));
            $status = ($user_role === 'admin' || $user_role === 'agm ops' || $user_role === 'agm operations') ? 'approved' : 'pending';
            
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
                    error_log("UV Test: Generated " . count($bulk_rolls) . " individual references from range: " . $fromRef . " to " . $toRef);
                } else {
                    // Different bases - add both endpoints
                    $bulk_rolls[] = $fromRef;
                    if ($toRef !== $fromRef) {
                        $bulk_rolls[] = $toRef;
                    }
                    error_log("UV Test: WARNING - Different base references in range. Generated " . count($bulk_rolls) . " references.");
                }
            }
            
            // Get reference - use individual roll reference if bundle is selected
            // Or use from_reference/to_reference if line-based selection is used
            $reference = '';
            $bundle_reference = null;
            
            if (isset($data['individual_roll_reference']) && !empty($data['individual_roll_reference'])) {
                $reference = trim($data['individual_roll_reference']);
                // Get bundle reference if individual roll is selected
                if (isset($data['reference']) && !empty($data['reference'])) {
                    $bundle_reference = trim($data['reference']); // Original bundle reference
                }
            } elseif (!empty($bulk_rolls)) {
                // Range selected - bundle_reference will be set for all rolls
                $bundle_reference = trim($data['from_reference']) . '|' . trim($data['to_reference']);
                // reference will be set per roll in the loop
            } elseif (isset($data['reference']) && !empty($data['reference'])) {
                $reference = trim($data['reference']);
                // Check if this is a range format (from|to)
                if (strpos($reference, '|') !== false) {
                    $parts = explode('|', $reference);
                    if (count($parts) === 2) {
                        $reference = trim($parts[0]); // Use from reference
                        $bundle_reference = $reference; // Store the range format
                    }
                }
            }
            
            // Process each roll in the range (or single reference)
            $rolls_to_process = !empty($bulk_rolls) ? $bulk_rolls : [];
            if (empty($rolls_to_process) && !empty($reference)) {
                $rolls_to_process = [$reference];
            }
            
            if (empty($rolls_to_process)) {
                throw new Exception("No reference selected. Please select a reference or range.");
            }
            
            $inserted_count = 0;
            $report_numbers = [];
            $base_report_number = $report_number;
            
            foreach ($rolls_to_process as $roll_ref) {
                // For bulk rolls, use the individual roll as reference
                $current_reference = !empty($bulk_rolls) ? $roll_ref : $reference;
                
                // Generate unique report number for each roll
                if ($inserted_count > 0) {
                    // Increment report number for subsequent rolls
                    $seq = $this->generateAndIncrementReportNumber();
                    $report_number = $seq;
                }
                $report_numbers[] = $report_number;
            
            // Bind types: 20 strings, 1 int (reporter_id), 2 strings
            $stmt->bind_param(
                "ssssssssssssssssssssiss",
                $report_number, 
                $data['sample_collected_from'], 
                    $current_reference,
                $bundle_reference, 
                $data['sample_description'], 
                $data['recipe'], 
                $data['received_date'], 
                $data['test_start_date'], 
                $data['test_end_date'], 
                $data['testing_method'], 
                $data['test_name'], 
                $data['test_speed'], 
                $data['gauge_length'], 
                $data['specimen_size'], 
                $data['note'], 
                $data['temperature'], 
                $data['rh_percent'], 
                $data['test_performed_by'], 
                $data['approved_by'], 
                $test_results_json, 
                $this->reporter_id, 
                $this->reporter_full_name, 
                $status
            );
            
            if (!$stmt->execute()) {
                    error_log("UV Test: Failed to insert roll " . $roll_ref . ": " . $stmt->error);
                    continue;
            }
            
                $inserted_count++;

            // Check bundle completion if this is from a bundle and was approved
            if ($bundle_reference && $status === 'approved') {
                $this->checkAndMarkBundleComplete('weathering_exposure_reports', $bundle_reference);
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
            $sample_ref = $data['reference'] ?? '';
            $client = $data['sample_received_from'] ?? '';
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
                ? "{$inserted_count} UV test reports submitted successfully! Report Numbers: " . implode(', ', array_slice($report_numbers, 0, 3)) . (count($report_numbers) > 3 ? '...' : '')
                : "UV test report submitted successfully! Report Number: " . $report_numbers[0];
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
        // Safely add new columns if missing (for existing installations)
        $this->ensureColumnExists('weathering_exposure_reports', 'tested_by', "ALTER TABLE weathering_exposure_reports ADD COLUMN tested_by VARCHAR(100) NULL AFTER approved_by");
        $this->ensureColumnExists('weathering_exposure_reports', 'approved_at', "ALTER TABLE weathering_exposure_reports ADD COLUMN approved_at DATETIME NULL AFTER tested_by");
        $this->ensureColumnExists('weathering_exposure_reports', 'remarks', "ALTER TABLE weathering_exposure_reports ADD COLUMN remarks TEXT NULL AFTER approved_at");
        $this->ensureColumnExists('weathering_exposure_reports', 'bundle_reference', "ALTER TABLE weathering_exposure_reports ADD COLUMN bundle_reference VARCHAR(255) NULL AFTER reference");
        // Make approved_by nullable to support pending submissions without approver
        @$this->conn->query("ALTER TABLE weathering_exposure_reports MODIFY approved_by VARCHAR(100) NULL");
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
             FROM weathering_exposure_reports 
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
            $query = "SELECT reference, status 
                      FROM {$table_name} 
                      WHERE bundle_reference = ? 
                      AND reference IN ({$placeholders})
                      AND status = 'approved'";
            
            $stmt = $this->conn->prepare($query);
            $params = array_merge([$bundle_reference], $expectedRolls);
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $approvedRolls = [];
            while ($row = $result->fetch_assoc()) {
                $approvedRolls[] = $row['reference'];
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

    /**
     * Fetch pending reports for approval queue (latest first)
     */
    public function getPendingReports($limit = 20) {
        $this->createMainTable();
        $stmt = $this->conn->prepare("SELECT id, report_number, sample_description, test_name, reporter_name, created_at, updated_at FROM weathering_exposure_reports WHERE status = 'pending' ORDER BY updated_at DESC LIMIT ?");
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
        $stmt = $this->conn->prepare("UPDATE weathering_exposure_reports SET status = ?, approved_by = ?, approved_at = NOW(), remarks = ?, updated_at = NOW() WHERE report_number = ?");
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
        $reportRow = $this->conn->prepare("SELECT id FROM weathering_exposure_reports WHERE report_number = ?");
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
}

// Initialize handler
$handler = new WeatheringExposureTestHandler($conn, $reporter_id, $reporter_name, $reporter_full_name);

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
                $message_text = isset($result['count']) && $result['count'] > 1
                    ? "{$result['count']} UV Test Reports saved and auto-approved! Report Numbers: " . implode(', ', array_slice($result['report_numbers'], 0, 3)) . (count($result['report_numbers']) > 3 ? '...' : '')
                    : "UV Test Report saved and auto-approved! Report Number: " . $result['report_number'];
                $_SESSION['success_message'] = $message_text;
            } else {
                $message_text = isset($result['count']) && $result['count'] > 1
                    ? "{$result['count']} UV Test Reports submitted successfully! Report Numbers: " . implode(', ', array_slice($result['report_numbers'], 0, 3)) . (count($result['report_numbers']) > 3 ? '...' : '') . " - Status: Pending Approval"
                    : "UV Test Report submitted successfully! Report Number: " . $result['report_number'] . " - Status: Pending Approval";
                $_SESSION['success_message'] = $message_text;
            }
            // Redirect to refresh the page and update the dropdown
            // Add timestamp to force fresh load and prevent caching
            header("Location: weathering_exposure_test.php?success=1&t=" . time());
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
    
    // Handle rejection reasons checkboxes for UV test
    if ($wf_action === 'rejected' && isset($_POST['uv_rejection_reasons']) && is_array($_POST['uv_rejection_reasons'])) {
        $rejection_reasons = array_map('trim', $_POST['uv_rejection_reasons']);
        $reasons_text = implode(', ', $rejection_reasons);
        // Prepend reasons to comment
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>UV Test Report</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; overflow-x:hidden; }
  .container { max-width:1200px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08); overflow-x:hidden; } 
  h1 { text-align:center; font-size:28px; margin-bottom:30px; color:#2c3e50; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  input[type="text"], input[type="number"], input[type="datetime-local"], input[type="date"], select, textarea { padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px); }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .readonly { background:#ecf0f1; }
  .test-table { width:100%; min-width:900px; border-collapse:collapse; margin-top:15px; border-spacing:0; table-layout:fixed; }
  .test-table th, .test-table td { border:1px solid #ddd; padding:10px 8px; text-align:center; vertical-align:middle; }
  .test-table th { background:#3498db; color:#fff; font-weight:600; padding:12px 8px; }
  .test-table tbody tr { height:auto; min-height:52px; }
  .test-table input, .test-table select { width:100%; border:none; background:transparent; text-align:center; padding:8px 6px; box-sizing:border-box; }
  .test-table select { padding:8px 6px; }
  .test-table input.fr { background:#f8f9fa; cursor:not-allowed; }
  .test-table-wrapper { overflow-x:auto; margin-top:15px; width:100%; max-width:100%; -webkit-overflow-scrolling:touch; }
  
  /* Set consistent column widths */
  .test-table th:nth-child(1), .test-table td:nth-child(1) { width:8%; } /* Specimen No */
  .test-table th:nth-child(2), .test-table td:nth-child(2) { width:10%; } /* Test Direction */
  .test-table th:nth-child(3), .test-table td:nth-child(3) { width:13%; } /* Breaking Force After */
  .test-table th:nth-child(4), .test-table td:nth-child(4) { width:13%; } /* Breaking Force Before */
  .test-table th:nth-child(5), .test-table td:nth-child(5) { width:13%; } /* Force Retain */
  .test-table th:nth-child(6), .test-table td:nth-child(6) { width:13%; } /* Elongation After */
  .test-table th:nth-child(7), .test-table td:nth-child(7) { width:13%; } /* Elongation Before */
  .test-table th:nth-child(8), .test-table td:nth-child(8) { width:12%; } /* Action */
  .form-row { display:flex; gap:20px; margin-bottom:25px; }
  .form-row .form-group { flex:1; }
  
  @media (max-width: 1024px) {
    body { padding:20px 15px; }
    .container { padding:20px 15px; }
    .form-group { margin-bottom:25px; }
    .form-row { gap:15px; margin-bottom:25px; flex-wrap:wrap; }
    .form-row .form-group { min-width:100%; }
    .test-table-wrapper { width:calc(100% + 30px); margin-left:-15px; margin-right:-15px; }
    .test-table th, .test-table td { padding:10px 6px; }
    .test-table th { padding:12px 6px; }
    .test-table input, .test-table select { padding:8px 4px; }
  }
  .dir-btn { padding:6px 12px; border:1px solid #ccc; border-radius:4px; cursor:pointer; margin:0; font-size:13px; width:50px; height:32px; box-sizing:border-box; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:inline-flex; align-items:center; justify-content:center; line-height:1; font-weight:500; }
  .dir-btn.active { background:#007bff; color:#fff; border-color:#007bff; }
  .dir-btn:not(.active) { background:#fff; color:#2c3e50; }
  .dir-btn:not(.active):hover { background:#f0f0f0; }
  .dir-btn-container { display:flex; gap:4px; align-items:center; justify-content:center; width:100%; height:100%; }
  .test-table th:nth-child(2), .test-table td:nth-child(2) { overflow:hidden; }
</style>
</head>
<body>
<div class="container">
  <h1> UV Test</h1>

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

  <form method="POST" action="" onsubmit="return validateUVForm();">
    <!-- Back to Dashboard Link -->
    <div style="margin-bottom: 15px;">
      <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
        ← Back to Dashboard
      </a>
    </div>

    <?php if ($handler->canApproveReports()): ?>
    <!-- Pending Approval Queue -->
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
            <th style="width:200px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($pending as $row): ?>
          <tr>
            <td><?php echo htmlspecialchars($row['report_number']); ?></td>
            <td><?php echo htmlspecialchars($row['sample_description']); ?></td>
            <td><?php echo htmlspecialchars($row['test_name']); ?></td>
            <td><?php echo htmlspecialchars($row['reporter_name']); ?></td>
            <td><?php echo htmlspecialchars($row['updated_at']); ?></td>
            <td>
              <a href="../admin/view_uv_report.php?id=<?php echo $row['id']; ?>" target="_blank" class="submit-btn" style="padding:6px 10px; text-decoration:none; display:inline-block; background:#3498db; margin-right:4px;">View</a>
              <form method="POST" action="" style="display:inline; margin-right:4px;" onsubmit="return confirmApproval(this);">
                <input type="hidden" name="wf_report_number" value="<?php echo htmlspecialchars($row['report_number']); ?>">
                <input type="hidden" name="wf_comment" value="Approved from queue">
                <button type="submit" name="wf_action" value="approved" class="submit-btn" style="padding:6px 10px;">Approve</button>
              </form>
              <button type="button" onclick="openUVRejectModal('<?php echo htmlspecialchars($row['report_number']); ?>')" class="clear-btn" style="padding:6px 10px; border:none; cursor:pointer;">Reject</button>
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
              <a href="edit_uv_report.php?id=<?php echo $rj['id']; ?>" class="submit-btn" style="padding:6px 10px; text-decoration:none; display:inline-block; background:#e67e22; color:#fff;">Edit & Resubmit</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p style="margin:12px 0 0 0; color:#856404; font-style:italic;">💡 Note: Click "Edit & Resubmit" to modify the rejected report, or submit a new report using the form below.</p>
    </div>
    <?php endif; ?>
    <?php endif; ?>

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
        <label>Sample Collected From:</label>
        <input type="text" name="sample_collected_from" required>
      </div>
      <div class="form-group" style="grid-column: 1 / -1;">
        <label>Reference:</label>
        
        <!-- Line Selection Buttons -->
        <div id="uv_line_selection_buttons" style="display:flex; gap:10px; margin-bottom:10px;">
          <button type="button" id="uv_line1_btn" class="line-btn" onclick="filterUVByLine('L1')" style="padding:8px 16px; border:2px solid #3498db; border-radius:6px; background:#e3f2fd; color:#1565C0; font-weight:600; cursor:pointer;">
            Line 1
          </button>
          <button type="button" id="uv_line2_btn" class="line-btn" onclick="filterUVByLine('L2')" style="padding:8px 16px; border:2px solid #3498db; border-radius:6px; background:#e3f2fd; color:#1565C0; font-weight:600; cursor:pointer;">
            Line 2
          </button>
          <button type="button" id="uv_line_all_btn" class="line-btn active" onclick="filterUVByLine('all')" style="padding:8px 16px; border:2px solid #3498db; border-radius:6px; background:#2196F3; color:#ffffff; font-weight:600; cursor:pointer;">
            All Lines
          </button>
        </div>
        
        <!-- From/To Reference Selection (shown when line is selected) -->
        <div id="uv_bulk_reference_selection" style="display:none; margin-bottom:10px; padding:10px; background:#f8f9fa; border:1px solid #ddd; border-radius:6px;">
          <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
            <label style="font-weight:600; margin:0;">From Reference:</label>
            <select id="uv_from_reference" style="min-width:250px; padding:5px; border:1px solid #ccc; border-radius:4px;" onchange="updateUVReferenceRange(true); handleUVFromToReferenceChange();">
              <option value="">-- Select From Reference --</option>
            </select>
            <label style="font-weight:600; margin:0;">To Reference:</label>
            <select id="uv_to_reference" style="min-width:250px; padding:5px; border:1px solid #ccc; border-radius:4px;" onchange="updateUVReferenceRange(false); handleUVFromToReferenceChange();">
              <option value="">-- Select To Reference --</option>
            </select>
            <button type="button" onclick="applyUVBulkReferenceSelection()" style="padding:6px 12px; background:#3498db; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:600;">
              Apply
            </button>
            <button type="button" onclick="clearUVBulkReferenceSelection()" style="padding:6px 12px; background:#6c757d; color:white; border:none; border-radius:4px; cursor:pointer; font-weight:600;">
              Clear
            </button>
          </div>
          
          <!-- Reference Test Status Display (shown when Apply is clicked) -->
          <div id="uv_reference_test_status_container" style="display:none; margin-top:15px; padding:0; background:#ffffff; border:1px solid #e0e0e0; border-radius:8px; box-shadow:0 1px 4px rgba(0,0,0,0.08); max-width:550px; margin-left:auto; margin-right:auto;">
            <div style="padding:12px 16px; background:linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius:8px 8px 0 0; color:white;">
              <div style="display:flex; align-items:center; justify-content:space-between; gap:10px;">
                <div style="display:flex; align-items:center; gap:8px;">
                  <i class="fas fa-list-check" style="font-size:16px;"></i>
                  <h3 style="margin:0; font-size:14px; font-weight:600;" id="uv_reference_status_title">Test Status</h3>
                </div>
                <div style="font-size:11px; opacity:0.95; font-weight:500;" id="uv_reference_status_summary"></div>
              </div>
            </div>
            <div id="uv_reference_test_status_list" style="padding:12px; max-height:300px; overflow-y:auto;">
              <!-- Status will be populated here -->
            </div>
          </div>
          
          <!-- Hidden input to store the selected product_reference when line-based selection is used -->
          <input type="hidden" id="uv_line_based_product_reference" name="product_reference" value="">
        </div>
        
        <!-- Production Product Reference Dropdown (shown when "All Lines" is selected) -->
        <select name="reference" id="uv_reference" onchange="handleUVReferenceSelection(this.value)" style="padding:10px; border:1px solid #ccc; border-radius:6px; width:100%;">
          <option value="">-- Select Reference Number --</option>
          <?php 
          // Show bundle references
          foreach($bundleReferences as $bundle): ?>
            <option value="<?php echo htmlspecialchars($bundle['reference']); ?>" data-is-bundle="true" data-base-ref="<?php echo htmlspecialchars($bundle['base_reference']); ?>" data-roll-count="<?php echo $bundle['roll_count']; ?>" data-line="<?php echo htmlspecialchars($bundle['line'] ?? ''); ?>">
              <?php echo htmlspecialchars($bundle['reference']); ?> (Bundle - <?php echo $bundle['roll_count']; ?> rolls)
            </option>
          <?php endforeach; ?>
          <?php 
          // Show single roll references
          foreach($references as $ref): 
            $refValue = is_array($ref) ? $ref['reference'] : $ref;
            $lineIndicator = is_array($ref) ? ($ref['line'] ?? '') : '';
          ?>
            <option value="<?php echo htmlspecialchars($refValue); ?>" data-is-bundle="false" data-line="<?php echo htmlspecialchars($lineIndicator); ?>">
              <?php echo htmlspecialchars($refValue); ?>
            </option>
          <?php endforeach; ?>
        </select>
        
        <!-- Individual Roll Selector (shown when bundle is selected) -->
        <select name="individual_roll_reference" id="uv_individual_roll_reference" onchange="handleUVIndividualRollSelection(this.value)" style="display:none; margin-top:10px; padding:10px; border:2px solid #3498db; border-radius:6px; background:#f8f9fa;">
          <option value="">-- Select Individual Roll for Testing --</option>
        </select>
        <div id="uv_bundle_info" style="display:none; margin-top:8px; padding:10px; background:#e3f2fd; border-left:4px solid #2196F3; border-radius:4px; font-size:13px; color:#1565C0;">
          <i class="fas fa-info-circle"></i> <strong>Bundle Detected:</strong> This reference contains multiple rolls. <strong>Please select the specific roll number</strong> you want to test individually.
        </div>
      </div>
    </div>

    <div class="form-group">
      <label>Sample Description:</label>
      <input type="text" name="sample_description" required>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Recipe:</label>
        <input type="text" name="recipe" required>
      </div>
      <div class="form-group">
        <label>Received Date:</label>
        <input type="datetime-local" name="received_date" required>
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
        <input type="text" name="testing_method" value="Tensile Test(ASTM D5035)" readonly class="readonly" required>
      </div>
      <div class="form-group">
        <label>Test Name:</label>
        <input type="text" name="test_name" value="Breaking Force and Elongation of Textile Fabrics (Strip Method)" readonly class="readonly" required>
      </div>
    </div>

    <!-- Test Speed / Gauge Length / Specimen Size -->
    <div class="form-group">
      <label>Test Speed / Gauge Length / Specimen Size:</label>
      <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <div style="display:flex; flex-direction:column; min-width:160px;">
          <small style="color:#6c757d; font-weight:600;">Test Speed (mm/min)</small>
          <input type="text" name="test_speed" required placeholder="e.g., 300">
        </div>
        <div style="display:flex; flex-direction:column; min-width:160px;">
          <small style="color:#6c757d; font-weight:600;">Gauge Length (mm)</small>
          <input type="text" name="gauge_length" required placeholder="e.g., 75">
        </div>
        <div style="display:flex; flex-direction:column; min-width:200px; flex:1;">
          <small style="color:#6c757d; font-weight:600;">Specimen Size</small>
          <input type="text" name="specimen_size" style="width: 140px;" required placeholder="150mm*50mm">
        </div>
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
          <th>Specimen No</th>
          <th>Test Direction</th>
          <th colspan="2">Breaking Force (N)</th>
          <th>Force Retain (%)</th>
          <th colspan="2">Elongation (%)</th>
          <th>Action</th>
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
      <tbody id="wx_tbody">
        <tr>
          <td>1</td>
          <td>
            <div class="dir-btn-container">
              <button type="button" class="dir-btn active" data-row="1" onclick="setDirection(this,'MD')">MD1</button>
              <button type="button" class="dir-btn" data-row="1" onclick="setDirection(this,'CD')">CD1</button>
              <input type="hidden" name="test_direction_1" value="MD">
            </div>
          </td>
          <td><input type="number" step="0.01" min="0" name="breaking_force_after_1" data-row="1" class="bf-after" oninput="if(this.value < 0) this.value = 0; calculateForceRetain(this)"></td>
          <td><input type="number" step="0.01" min="0" name="breaking_force_before_1" data-row="1" class="bf-before" oninput="if(this.value < 0) this.value = 0; calculateForceRetain(this)"></td>
          <td><input type="text" name="force_retain_1" class="fr" readonly value=""></td>
          <td><input type="number" step="0.01" min="0" name="elongation_after_1" oninput="if(this.value < 0) this.value = 0"></td>
          <td><input type="number" step="0.01" min="0" name="elongation_before_1" oninput="if(this.value < 0) this.value = 0"></td>
          <td></td>
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
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <!-- Add Row Button -->
    <div style="margin-top: 10px;">
      <button type="button" onclick="addWxRow()" style="padding:8px 16px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer; font-weight:600;">Add 1 More Row</button>
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
    loadUVReferencesList();
    
    // Auto-fill received date with current date/time
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');
    const hours = String(now.getHours()).padStart(2, '0');
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const currentDateTime = `${year}-${month}-${day}T${hours}:${minutes}`;
    
    document.querySelector('input[name="received_date"]').value = currentDateTime;
    
    // Add event listeners to date inputs
    document.querySelector('input[name="test_start_date"]').addEventListener('change', validateDates);
    document.querySelector('input[name="test_end_date"]').addEventListener('change', validateDates);
    
    // Add event listener to form submission
    document.querySelector('form').addEventListener('submit', function(event) {
        if (!validateUVForm()) {
            event.preventDefault(); // Prevent form submission if validation fails
        }
    });
});

// Load references list from API
function loadUVReferencesList() {
    fetch('api/get_uv_references_list.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const select = document.getElementById('uv_reference');
                if (!select) return;
                
                // Clear existing options except the first placeholder
                select.innerHTML = '<option value="">-- Select Reference Number --</option>';
                
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

// Filter references by Line (L1 or L2)
function filterUVByLine(line) {
    const productRefSelect = document.getElementById('uv_reference');
    const bulkRefSelection = document.getElementById('uv_bulk_reference_selection');
    
    // Update button styles
    const line1Btn = document.getElementById('uv_line1_btn');
    const line2Btn = document.getElementById('uv_line2_btn');
    const lineAllBtn = document.getElementById('uv_line_all_btn');
    
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
        productRefSelect.removeAttribute('name'); // Remove name temporarily so it's not submitted
        
        // Show From/To reference selection
        if (bulkRefSelection) {
            bulkRefSelection.style.display = 'block';
            populateUVLineReferences(line);
        }
        
        // Make From/To required
        const fromRefSelect = document.getElementById('uv_from_reference');
        const toRefSelect = document.getElementById('uv_to_reference');
        if (fromRefSelect) {
            fromRefSelect.setAttribute('required', 'required');
            fromRefSelect.setAttribute('name', 'from_reference'); // Ensure name is set
        }
        if (toRefSelect) {
            toRefSelect.setAttribute('required', 'required');
            toRefSelect.setAttribute('name', 'to_reference'); // Ensure name is set
        }
    } else {
        // Show single reference dropdown for "All Lines"
        productRefSelect.style.display = 'block';
        productRefSelect.setAttribute('required', 'required');
        productRefSelect.setAttribute('name', 'reference'); // Ensure name is set
        
        // Hide From/To reference selection
        if (bulkRefSelection) {
            bulkRefSelection.style.display = 'none';
            clearUVBulkReferenceSelection();
        }
        
        // Remove required from From/To and remove names so they're not submitted
        const fromRefSelect = document.getElementById('uv_from_reference');
        const toRefSelect = document.getElementById('uv_to_reference');
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
        const lineBasedProductRef = document.getElementById('uv_line_based_product_reference');
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
                handleUVReferenceSelection('');
            }
        }
    }
}

// Populate From/To reference dropdowns with references for selected line
function populateUVLineReferences(line) {
    const productRefSelect = document.getElementById('uv_reference');
    const fromRefSelect = document.getElementById('uv_from_reference');
    const toRefSelect = document.getElementById('uv_to_reference');
    
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
        // Check if this reference is part of a bundle (ends with -N pattern)
        const isPartOfBundle = /-\d+$/.test(ref.value);
        const displayText = isPartOfBundle ? ref.text + ' (Bundle)' : ref.text;
        
        const fromOption = document.createElement('option');
        fromOption.value = ref.value;
        fromOption.textContent = displayText;
        fromOption.setAttribute('data-is-bundle', ref.isBundle);
        fromOption.setAttribute('data-base-ref', ref.baseRef);
        fromOption.setAttribute('data-roll-count', ref.rollCount);
        fromRefSelect.appendChild(fromOption);
        
        const toOption = document.createElement('option');
        toOption.value = ref.value;
        toOption.textContent = displayText;
        toOption.setAttribute('data-is-bundle', ref.isBundle);
        toOption.setAttribute('data-base-ref', ref.baseRef);
        toOption.setAttribute('data-roll-count', ref.rollCount);
        toRefSelect.appendChild(toOption);
    });
}

// Update To Reference dropdown based on From Reference selection
function updateUVReferenceRange(autoSelect = true) {
    const fromRefSelect = document.getElementById('uv_from_reference');
    const toRefSelect = document.getElementById('uv_to_reference');
    
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
        
        // Extract base reference from the selected value
        const rollMatch = fromValue.match(/^(.+)-(\d+)$/);
        if (rollMatch) {
            actualBaseRef = rollMatch[1];
            const selectedRollNum = parseInt(rollMatch[2]);
            
            // If first roll (-1) is selected, find the highest roll number for this base reference
            if (selectedRollNum === 1) {
                // Find all rolls for this base reference in the To dropdown
                let highestRoll = 0;
                let highestRollRef = '';
                
                Array.from(toRefSelect.options).forEach(option => {
                    if (option.value && option.value !== '') {
                        const optionRollMatch = option.value.match(/^(.+)-(\d+)$/);
                        if (optionRollMatch) {
                            const optionBaseRef = optionRollMatch[1];
                            const optionRollNum = parseInt(optionRollMatch[2]);
                            
                            // If it's from the same base reference
                            if (optionBaseRef === actualBaseRef && optionRollNum > highestRoll) {
                                highestRoll = optionRollNum;
                                highestRollRef = option.value;
                            }
                        }
                    }
                });
                
                // Auto-select the highest roll found
                if (highestRollRef && highestRoll > 1) {
                    toRefSelect.value = highestRollRef;
                    return; // Exit early since we've found and selected the last roll
                }
            }
        }
        
        // Fallback to original logic for other cases
        if (!actualBaseRef) {
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
function applyUVBulkReferenceSelection() {
    const fromRef = document.getElementById('uv_from_reference').value;
    const toRef = document.getElementById('uv_to_reference').value;
    
    if (!fromRef || !toRef) {
        alert('Please select both From and To references');
        return;
    }
    
    // Store the range in hidden input
    const lineBasedProductRef = document.getElementById('uv_line_based_product_reference');
    if (lineBasedProductRef) {
        lineBasedProductRef.value = fromRef + ' to ' + toRef;
    }
    
    // Update the main reference dropdown to show the range
    const productRefSelect = document.getElementById('uv_reference');
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
    
    // Check which references in the range have been submitted
    checkUVReferenceTestStatus(fromRef, toRef);
}

// Check which references in range have been submitted for UV Test
function checkUVReferenceTestStatus(fromRef, toRef) {
    const container = document.getElementById('uv_reference_test_status_container');
    const statusList = document.getElementById('uv_reference_test_status_list');
    const statusTitle = document.getElementById('uv_reference_status_title');
    const statusSummary = document.getElementById('uv_reference_status_summary');
    
    if (!container || !statusList) {
        console.error('Reference status container elements not found!');
        return;
    }
    
    // Show container with loading state
    container.style.display = 'block';
    if (statusTitle) statusTitle.textContent = 'Test Status';
    statusList.innerHTML = '<div style="padding:20px; text-align:center; color:#666;"><i class="fas fa-spinner fa-spin" style="font-size:18px;"></i><div style="margin-top:8px; font-size:12px;">Loading...</div></div>';
    if (statusSummary) statusSummary.innerHTML = '';
    
    // Fetch submitted references
    fetch(`api/check_submitted_tests_range_uv.php?from_reference=${encodeURIComponent(fromRef)}&to_reference=${encodeURIComponent(toRef)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayUVReferenceStatus(data, fromRef, toRef);
            } else {
                statusList.innerHTML = `<div style="padding:12px; text-align:center; color:#dc3545; font-size:12px;"><i class="fas fa-exclamation-triangle"></i> ${data.error || 'Unknown error'}</div>`;
            }
        })
        .catch(error => {
            console.error('Error checking reference status:', error);
            statusList.innerHTML = `<div style="padding:12px; text-align:center; color:#dc3545; font-size:12px;"><i class="fas fa-exclamation-triangle"></i> Error loading status</div>`;
        });
}

// Display reference status with modern UI (same logic as Characteristics)
function displayUVReferenceStatus(data, fromRef, toRef) {
    const statusList = document.getElementById('uv_reference_test_status_list');
    const statusSummary = document.getElementById('uv_reference_status_summary');
    
    if (!statusList) return;
    
    const submittedRefs = data.submitted_references || [];
    const submittedRefSet = new Set(submittedRefs.map(r => r.reference));
    
    // Get all references in range
    const fromSelect = document.getElementById('uv_from_reference');
    const toSelect = document.getElementById('uv_to_reference');
    const allRefs = [];
    
    if (fromSelect && toSelect) {
        const fromIndex = Array.from(fromSelect.options).findIndex(opt => opt.value === fromRef);
        const toIndex = Array.from(toSelect.options).findIndex(opt => opt.value === toRef);
        
        if (fromIndex !== -1 && toIndex !== -1) {
            for (let i = fromIndex; i <= toIndex; i++) {
                const opt = fromSelect.options[i];
                if (opt && opt.value) {
                    allRefs.push(opt.value);
                }
            }
        }
    }
    
    // If we couldn't get refs from dropdown, use submitted refs to infer
    if (allRefs.length === 0) {
        const fromMatch = fromRef.match(/^(.+?)-(\d+)$/);
        const toMatch = toRef.match(/^(.+?)-(\d+)$/);
        if (fromMatch && toMatch && fromMatch[1] === toMatch[1]) {
            const base = fromMatch[1];
            const fromNum = parseInt(fromMatch[2]);
            const toNum = parseInt(toMatch[2]);
            for (let i = fromNum; i <= toNum; i++) {
                allRefs.push(base + '-' + i);
            }
        } else {
            allRefs.push(fromRef, toRef);
        }
    }
    
    const pendingRefs = allRefs.filter(ref => !submittedRefSet.has(ref));
    const submittedCount = submittedRefs.length;
    const pendingCount = pendingRefs.length;
    
    // Update summary
    if (statusSummary) {
        statusSummary.innerHTML = `Total: ${allRefs.length} references | Submitted: ${submittedCount} | Pending: ${pendingCount}`;
    }
    
    // Build HTML (same as Characteristics)
    let html = '';
    
    if (submittedCount > 0) {
        html += `
            <div style="margin-bottom:25px;">
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px; padding:12px; background:#e8f5e9; border-radius:8px;">
                    <i class="fas fa-check-circle" style="color:#4caf50; font-size:18px;"></i>
                    <h4 style="margin:0; color:#2e7d32; font-size:16px; font-weight:600;">Already Submitted (${submittedCount})</h4>
                </div>
                <div style="display:grid; gap:8px;">
        `;
        
        submittedRefs.forEach(ref => {
            const statusBadge = ref.status === 'approved' ? '<span style="background:#4caf50; color:white; padding:2px 8px; border-radius:4px; font-size:11px; margin-left:8px;">Approved</span>' :
                           '<span style="background:#ff9800; color:white; padding:2px 8px; border-radius:4px; font-size:11px; margin-left:8px;">Pending</span>';
            html += `
                <div style="padding:10px 12px; background:#f5f5f5; border-left:3px solid #4caf50; border-radius:4px;">
                    <div style="display:flex; align-items:center; justify-content:space-between;">
                        <span style="font-weight:500; color:#333;">${ref.reference}</span>
                        ${statusBadge}
                    </div>
                </div>
            `;
        });
        
        html += `</div></div>`;
    }
    
    if (pendingCount > 0) {
        html += `
            <div>
                <div style="display:flex; align-items:center; gap:8px; margin-bottom:12px; padding:12px; background:#fff3e0; border-radius:8px;">
                    <i class="fas fa-clock" style="color:#ff9800; font-size:18px;"></i>
                    <h4 style="margin:0; color:#e65100; font-size:16px; font-weight:600;">Pending Submission (${pendingCount})</h4>
                </div>
                <div style="display:grid; gap:8px;">
        `;
        
        pendingRefs.forEach(ref => {
            html += `
                <div style="padding:10px 12px; background:#f5f5f5; border-left:3px solid #ff9800; border-radius:4px;">
                    <span style="font-weight:500; color:#333;">${ref}</span>
                    <span style="background:#ff9800; color:white; padding:2px 8px; border-radius:4px; font-size:11px; margin-left:8px;">Will Submit</span>
                </div>
            `;
        });
        
        html += `</div></div>`;
    }
    
    if (submittedCount === 0 && pendingCount === 0) {
        html = `
            <div style="padding:30px; text-align:center; color:#999;">
                <i class="fas fa-info-circle" style="font-size:32px; color:#2196F3; margin-bottom:15px;"></i>
                <div style="font-size:16px; font-weight:600; margin-bottom:10px;">No References Found</div>
                <div style="font-size:14px; color:#666;">Could not determine references in the selected range.</div>
            </div>
        `;
    }
    
    statusList.innerHTML = html;
}

// Clear bulk reference selection
function clearUVBulkReferenceSelection() {
    const fromRefSelect = document.getElementById('uv_from_reference');
    const toRefSelect = document.getElementById('uv_to_reference');
    const lineBasedProductRef = document.getElementById('uv_line_based_product_reference');
    
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
function handleUVFromToReferenceChange() {
    // This function can be extended to perform validation or other actions
    // when From/To references change
}

// Validate form before submission
function validateUVForm() {
    const productRefSelect = document.getElementById('uv_reference');
    const fromRefSelect = document.getElementById('uv_from_reference');
    const toRefSelect = document.getElementById('uv_to_reference');
    const individualRollSelect = document.getElementById('uv_individual_roll_reference');
    
    // Check if line-based selection is active (From/To visible)
    const bulkRefSelection = document.getElementById('uv_bulk_reference_selection');
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
            productRefSelect.setAttribute('name', 'reference');
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
function handleUVReferenceSelection(selectedValue) {
    const productRefSelect = document.getElementById('uv_reference');
    const individualRollSelect = document.getElementById('uv_individual_roll_reference');
    const bundleInfo = document.getElementById('uv_bundle_info');
    
    if (!selectedValue || selectedValue === '') {
        // Hide individual roll selector
        if (individualRollSelect) {
            individualRollSelect.style.display = 'none';
            individualRollSelect.value = '';
            individualRollSelect.removeAttribute('required');
        }
        if (bundleInfo) bundleInfo.style.display = 'none';
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
        fetch(`api/get_tested_rolls_uv.php?bundle_ref=${encodeURIComponent(bundleRef)}`)
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
            });
    } else {
        // Hide individual roll selector
        if (individualRollSelect) {
            individualRollSelect.style.display = 'none';
            individualRollSelect.value = '';
            individualRollSelect.removeAttribute('required');
        }
        if (bundleInfo) bundleInfo.style.display = 'none';
    }
}

// Handle individual roll selection from bundle
function handleUVIndividualRollSelection(selectedValue) {
    if (selectedValue && selectedValue !== '') {
        // Check if this is from a bundle and get first test data to pre-fill
        const productRefSelect = document.getElementById('uv_reference');
        const selectedOption = productRefSelect.options[productRefSelect.selectedIndex];
        const isBundle = selectedOption?.getAttribute('data-is-bundle') === 'true';
        const bundleRef = selectedOption?.value || '';
        
        if (isBundle && bundleRef) {
            // Fetch first test data from bundle to pre-fill form
            fetch(`api/get_first_bundle_test_data_uv.php?bundle_ref=${encodeURIComponent(bundleRef)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.data) {
                        // Pre-fill form with first test data
                        if (data.data.sample_description) document.querySelector('input[name="sample_description"]').value = data.data.sample_description;
                        if (data.data.recipe) document.querySelector('input[name="recipe"]').value = data.data.recipe;
                        if (data.data.testing_method) document.querySelector('input[name="testing_method"]').value = data.data.testing_method;
                        if (data.data.test_name) document.querySelector('input[name="test_name"]').value = data.data.test_name;
                        if (data.data.test_speed) document.querySelector('input[name="test_speed"]').value = data.data.test_speed;
                        if (data.data.gauge_length) document.querySelector('input[name="gauge_length"]').value = data.data.gauge_length;
                        if (data.data.specimen_size) document.querySelector('input[name="specimen_size"]').value = data.data.specimen_size;
                        if (data.data.temperature) document.querySelector('input[name="temperature"]').value = data.data.temperature;
                        if (data.data.rh_percent) document.querySelector('input[name="rh_percent"]').value = data.data.rh_percent;
                    }
                })
                .catch(error => {
                    console.error('Error fetching bundle test data:', error);
                });
        }
    }
}
setInterval(updateTimeAndShift, 1000);

function setDirection(button, direction) {
    const row = button.getAttribute('data-row');
    const buttons = document.querySelectorAll(`[data-row="${row}"]`);
    buttons.forEach(btn => {
        btn.classList.remove('active');
        btn.style.background = '#fff';
        btn.style.color = '#2c3e50';
        btn.style.borderColor = '#ccc';
    });
    button.classList.add('active');
    button.style.background = '#007bff';
    button.style.color = '#fff';
    button.style.borderColor = '#007bff';
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

    // Dynamic specimens count based on rows in table
    const maxRows = 20; // Increased limit for dynamic rows
    for (let i = 1; i <= maxRows; i++) {
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
        const cv = (n > 1 && avg !== 0) ? (sd / avg) * 100 : 0;
        return { avg, sd, cv, max: Math.max(...arr), min: Math.min(...arr) };
    }

    // update DOM helper (IDs used in your markup)
    function updateDom(key, statObj) {
        // stat keys in your HTML are: stat_<key>_average, stat_<key>_sd, stat_<key>_cv, stat_<key>_maximum, stat_<key>_minimum
        const fmt1 = x => Number.isFinite(x) ? x.toFixed(1) : '0.0';
        const fmt2 = x => Number.isFinite(x) ? x.toFixed(2) : '0.00';
        const elAvg = document.getElementById(`stat_${key}_average`);
        const elSd  = document.getElementById(`stat_${key}_sd`);
        const elCv  = document.getElementById(`stat_${key}_cv`);
        const elMax = document.getElementById(`stat_${key}_maximum`);
        const elMin = document.getElementById(`stat_${key}_minimum`);
        if (elAvg) elAvg.textContent = fmt1(statObj.avg);
        if (elSd)  elSd.textContent  = fmt2(statObj.sd);
        if (elCv)  elCv.textContent  = fmt2(statObj.cv);
        if (elMax) elMax.textContent = fmt1(statObj.max);
        if (elMin) elMin.textContent = fmt1(statObj.min);
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

function addWxRow() {
    const tbody = document.getElementById('wx_tbody');
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
            <div class="dir-btn-container">
                <button type="button" class="dir-btn active" data-row="${newRowNum}" onclick="setDirection(this,'MD')">MD${newRowNum}</button>
                <button type="button" class="dir-btn" data-row="${newRowNum}" onclick="setDirection(this,'CD')">CD${newRowNum}</button>
                <input type="hidden" name="test_direction_${newRowNum}" value="MD">
            </div>
        </td>
        <td><input type="number" step="0.01" min="0" name="breaking_force_after_${newRowNum}" data-row="${newRowNum}" class="bf-after" oninput="if(this.value < 0) this.value = 0; calculateForceRetain(this)"></td>
        <td><input type="number" step="0.01" min="0" name="breaking_force_before_${newRowNum}" data-row="${newRowNum}" class="bf-before" oninput="if(this.value < 0) this.value = 0; calculateForceRetain(this)"></td>
        <td><input type="text" name="force_retain_${newRowNum}" class="fr" readonly value=""></td>
        <td><input type="number" step="0.01" min="0" name="elongation_after_${newRowNum}" data-row="${newRowNum}" class="el-after" oninput="if(this.value < 0) this.value = 0; calculateStatistics()"></td>
        <td><input type="number" step="0.01" min="0" name="elongation_before_${newRowNum}" data-row="${newRowNum}" class="el-before" oninput="if(this.value < 0) this.value = 0; calculateStatistics()"></td>
        <td><button type="button" onclick="removeWxRow(this)" style="padding:4px 8px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:pointer;">Remove</button></td>
    `;
    tbody.appendChild(row);
    
    // Update statistics
    calculateStatistics();
}

function removeWxRow(button) {
    const row = button.closest('tr');
    const tbody = row.closest('tbody');
    
    // Check if this is the only row
    if (tbody.querySelectorAll('tr').length <= 1) {
        alert('You cannot remove the last row. At least one row is required.');
        return;
    }
    
    row.remove();
    
    // Renumber remaining rows
    const rows = tbody.querySelectorAll('tr');
    rows.forEach((r, idx) => {
        const rowNum = idx + 1;
        r.cells[0].textContent = rowNum;
        
        // Update all attributes with new row number
        const mdBtn = r.querySelector('.dir-btn[onclick*="MD"]');
        const cdBtn = r.querySelector('.dir-btn[onclick*="CD"]');
        if (mdBtn) {
            mdBtn.textContent = 'MD' + rowNum;
            mdBtn.setAttribute('data-row', rowNum);
            mdBtn.setAttribute('onclick', `setDirection(this,'MD')`);
        }
        if (cdBtn) {
            cdBtn.textContent = 'CD' + rowNum;
            cdBtn.setAttribute('data-row', rowNum);
            cdBtn.setAttribute('onclick', `setDirection(this,'CD')`);
        }
        
        // Update all input names - replace the last number in the name
        r.querySelectorAll('[name]').forEach(el => {
            const name = el.getAttribute('name');
            if (name && name.includes('_')) {
                const newName = name.replace(/_\d+$/, `_${rowNum}`);
                el.setAttribute('name', newName);
            }
        });
        
        // Update data-row attributes for all inputs
        r.querySelectorAll('[data-row]').forEach(el => {
            el.setAttribute('data-row', rowNum);
        });
    });
    
    // Update statistics after removal
    calculateStatistics();
}

// === Watch elongation inputs too ===
document.addEventListener('DOMContentLoaded', () => {
    // Setup event listeners for existing rows
    function setupRowListeners() {
        const rows = document.querySelectorAll('#wx_tbody tr');
        rows.forEach(row => {
            row.querySelectorAll('[name^="elongation_after_"], [name^="elongation_before_"]').forEach(el => {
                el.addEventListener('input', () => {
                    if (parseNumberFromInputEl(el) !== null) {
                        calculateStatistics();
                    }
                });
            });
        });
    }
    setupRowListeners();
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
                btn.style.borderColor = '#ccc';
            });
            if (buttons[0]) {
                buttons[0].classList.add('active');
                buttons[0].style.background = '#007bff';
                buttons[0].style.color = '#fff';
                buttons[0].style.borderColor = '#007bff';
            }
            const dirInput = document.querySelector(`input[name="test_direction_${i}"]`);
            if (dirInput) dirInput.value = 'MD';
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

// UV Test rejection modal functions
function openUVRejectModal(reportNumber) {
    document.getElementById('uvRejectReportNumber').value = reportNumber;
    document.getElementById('uvRejectionModal').style.display = 'block';
}

function closeUVRejectModal() {
    document.getElementById('uvRejectionModal').style.display = 'none';
    document.getElementById('uvAdminRejectForm').reset();
}

function submitUVAdminRejection() {
    const checkboxes = document.querySelectorAll('input[name="uv_rejection_reasons[]"]');
    const checked = Array.from(checkboxes).filter(cb => cb.checked);
    
    if (checked.length === 0) {
        alert('❌ Please select at least one reason for rejection!');
        return false;
    }
    
    if (confirm('Are you sure you want to reject this report?')) {
        const form = document.getElementById('uvAdminRejectForm');
        form.onsubmit = null; // Remove the return false
        form.submit();
    }
}
</script>

<!-- Rejection Modal for Admin (UV Test) -->
<div id="uvRejectionModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow-y:auto;">
  <div style="max-width:600px; margin:50px auto; background:#fff; border-radius:8px; padding:25px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
    <h3 style="margin-top:0; color:#dc3545; border-bottom:2px solid #dc3545; padding-bottom:10px;">
      ❌ Reject UV Test Report
    </h3>
    
    <form id="uvAdminRejectForm" method="POST" action="" onsubmit="return false;">
      <input type="hidden" id="uvRejectReportNumber" name="wf_report_number" value="">
      <input type="hidden" name="wf_action" value="rejected">
      
      <label style="font-weight:600; display:block; margin-bottom:10px;">Reason for Rejection (Select at least one):</label>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="uv_rejection_reasons[]" value="Incorrect Roll Identification" style="margin-right:8px;">
          Incorrect Roll Identification
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="uv_rejection_reasons[]" value="Incorrect Fiber Specification Entry" style="margin-right:8px;">
          Incorrect Fiber Specification Entry
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="uv_rejection_reasons[]" value="Excessive Sampling" style="margin-right:8px;">
          Excessive Sampling
        </label>
      </div>
      
      <label style="font-weight:bold; display:block; margin:15px 0 8px 0;">
        Additional Comments (Optional):
      </label>
      <textarea name="wf_comment" id="uvAdminRejectComment" rows="4" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-family:inherit;" placeholder="Provide additional details..."></textarea>
      
      <div style="margin-top:20px; text-align:right;">
        <button type="button" onclick="closeUVRejectModal()" style="padding:10px 20px; margin-right:10px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer;">Cancel</button>
        <button type="button" onclick="submitUVAdminRejection()" style="padding:10px 20px; background:#dc3545; color:#fff; border:none; border-radius:6px; cursor:pointer;">Submit Rejection</button>
      </div>
    </form>
  </div>
</div>

</body>
</html>

