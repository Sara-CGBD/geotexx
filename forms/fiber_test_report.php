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

// Fetch store received entries for reference dropdown (exclude if already submitted)
$storeEntries = [];
try {
    // First check if fiber_test_reports table exists
    $tableExists = $conn->query("SHOW TABLES LIKE 'fiber_test_reports'");
    $hasFiberTestTable = ($tableExists && $tableExists->num_rows > 0);
    
    if ($hasFiberTestTable) {
        // Exclude entries that have been approved (only approved reports block re-submission)
        // First, get all approved entry references
        $submittedRefs = [];
        $refQuery = $conn->query("SELECT DISTINCT store_entry_reference 
                                  FROM fiber_test_reports 
                                  WHERE store_entry_reference IS NOT NULL 
                                  AND store_entry_reference != ''
                                  AND status = 'approved'");
        if ($refQuery) {
            while ($refRow = $refQuery->fetch_assoc()) {
                $submittedRefs[] = trim($refRow['store_entry_reference']);
            }
        }
        
        // Now get store entries excluding the submitted ones
        $stmt = null;
        if (!empty($submittedRefs)) {
            // Use prepared statement to avoid SQL injection
            $placeholders = str_repeat('?,', count($submittedRefs) - 1) . '?';
            $stmt = $conn->prepare("SELECT entry_number, material_type, amount_kg, date_time as received_date, manufacturer_name 
                                     FROM store_received_entries 
                                     WHERE entry_number NOT IN ($placeholders)
                                     ORDER BY date_time DESC, created_at DESC 
                                     LIMIT 100");
            $stmt->bind_param(str_repeat('s', count($submittedRefs)), ...$submittedRefs);
            $stmt->execute();
            $storeQuery = $stmt->get_result();
        } else {
            // No submitted entries, show all
            $storeQuery = $conn->query("SELECT entry_number, material_type, amount_kg, date_time as received_date, manufacturer_name 
                                        FROM store_received_entries 
                                        ORDER BY date_time DESC, created_at DESC 
                                        LIMIT 100");
        }
    } else {
        // If table doesn't exist, show all entries
        $storeQuery = $conn->query("SELECT sre.entry_number, sre.material_type, sre.amount_kg, sre.date_time as received_date, sre.manufacturer_name 
                                     FROM store_received_entries sre
                                     ORDER BY sre.date_time DESC, sre.created_at DESC 
                                     LIMIT 100");
    }
    
    if ($storeQuery) {
        // Handle both mysqli_result and mysqli_stmt result
        if (is_object($storeQuery) && method_exists($storeQuery, 'fetch_assoc')) {
            while ($row = $storeQuery->fetch_assoc()) {
                $storeEntries[] = $row;
            }
        }
        // Close statement if it was a prepared statement
        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
            $stmt->close();
        }
    } else {
        // If query failed, try simple query without exclusions
        error_log("Store entries query returned false, trying simple query");
        $simpleQuery = $conn->query("SELECT entry_number, material_type, amount_kg, date_time as received_date, manufacturer_name 
                                      FROM store_received_entries 
                                      ORDER BY date_time DESC 
                                      LIMIT 100");
        if ($simpleQuery) {
            while ($row = $simpleQuery->fetch_assoc()) {
                $storeEntries[] = $row;
            }
        }
    }
} catch (Exception $e) {
    // Log error but try to fetch all entries as fallback
    error_log("Error fetching store entries for fiber test: " . $e->getMessage());
    try {
        $fallbackQuery = $conn->query("SELECT entry_number, material_type, amount_kg, date_time as received_date, manufacturer_name 
                                        FROM store_received_entries 
                                        ORDER BY date_time DESC 
                                        LIMIT 100");
        if ($fallbackQuery) {
            while ($row = $fallbackQuery->fetch_assoc()) {
                $storeEntries[] = $row;
            }
        }
    } catch (Exception $e2) {
        error_log("Fallback query also failed: " . $e2->getMessage());
    }
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
 * Fiber Test Report Handler
 */
class FiberTestReportHandler {
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
        $sql = "CREATE TABLE IF NOT EXISTS fiber_test_counters (
            date_key VARCHAR(8) PRIMARY KEY,
            counter INT NOT NULL DEFAULT 0,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $this->conn->query($sql);
    }
    
    public function createMainTable() {
        $sql = "CREATE TABLE IF NOT EXISTS fiber_test_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_number VARCHAR(100) UNIQUE NOT NULL,
            store_entry_reference VARCHAR(100) NULL,
            sample_name VARCHAR(255) NULL,
            lc_no VARCHAR(100) NULL,
            sample_received_date DATE NULL,
            manufacturer_name VARCHAR(100) NULL,
            sample_id VARCHAR(100) NULL,
            sample_tested_date DATE NOT NULL,
            test_performed_by VARCHAR(100) NOT NULL,
            approved_by VARCHAR(100) NULL,
            test_results JSON NOT NULL,
            comments TEXT,
            reporter_id INT NOT NULL,
            reporter_name VARCHAR(255) NOT NULL,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            approved_at DATETIME NULL,
            remarks TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_report_number (report_number),
            INDEX idx_status (status),
            INDEX idx_sample_id (sample_id),
            INDEX idx_store_entry_reference (store_entry_reference)
        )";
        $this->conn->query($sql);
        
        // Add column if it doesn't exist (for existing databases)
        $this->conn->query("ALTER TABLE fiber_test_reports ADD COLUMN IF NOT EXISTS store_entry_reference VARCHAR(100) NULL AFTER report_number");
        
        // Add missing columns if they don't exist
        $columns_to_add = [
            'report_number' => "ALTER TABLE fiber_test_reports ADD COLUMN report_number VARCHAR(100) UNIQUE NOT NULL AFTER id",
            'sample_name' => "ALTER TABLE fiber_test_reports ADD COLUMN sample_name VARCHAR(255) NULL AFTER report_number",
            'lc_no' => "ALTER TABLE fiber_test_reports ADD COLUMN lc_no VARCHAR(100) NULL AFTER sample_name",
            'sample_received_date' => "ALTER TABLE fiber_test_reports ADD COLUMN sample_received_date DATE NULL AFTER lc_no",
            'manufacturer_name' => "ALTER TABLE fiber_test_reports ADD COLUMN manufacturer_name VARCHAR(100) NULL AFTER sample_received_date",
            'reporter_id' => "ALTER TABLE fiber_test_reports ADD COLUMN reporter_id INT NOT NULL AFTER comments",
            'reporter_name' => "ALTER TABLE fiber_test_reports ADD COLUMN reporter_name VARCHAR(255) NOT NULL AFTER reporter_id",
            'status' => "ALTER TABLE fiber_test_reports ADD COLUMN status ENUM('pending','approved','rejected') DEFAULT 'pending' AFTER reporter_name",
            'approved_at' => "ALTER TABLE fiber_test_reports ADD COLUMN approved_at DATETIME NULL AFTER status",
            'remarks' => "ALTER TABLE fiber_test_reports ADD COLUMN remarks TEXT NULL AFTER approved_at"
        ];
        
        foreach ($columns_to_add as $col => $sql) {
            $check = $this->conn->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
                                         WHERE TABLE_SCHEMA = DATABASE() 
                                         AND TABLE_NAME = 'fiber_test_reports' 
                                         AND COLUMN_NAME = '$col'");
            if ($check && $check->fetch_assoc()['cnt'] == 0) {
                $this->conn->query($sql);
            }
        }
    }
    
    public function getNextReportNumber() {
        $this->createCounterTable();
        
        $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $hour = (int)$now->format('H');
        
        if ($hour < 8) {
            $now->modify('-1 day');
        }
        
        $dateKey = $now->format('Ymd');
        
        $stmt = $this->conn->prepare("SELECT counter FROM fiber_test_counters WHERE date_key = ?");
        $stmt->bind_param("s", $dateKey);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            $nextCounter = $row['counter'] + 1;
        } else {
            $nextCounter = 1;
        }
        $stmt->close();
        
        return sprintf("FTR-%s-%05d", $dateKey, $nextCounter);
    }
    
    public function generateAndIncrementReportNumber() {
        $this->createCounterTable();
        
        $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $hour = (int)$now->format('H');
        
        if ($hour < 8) {
            $now->modify('-1 day');
        }
        
        $dateKey = $now->format('Ymd');
        
        $stmt = $this->conn->prepare(
            "INSERT INTO fiber_test_counters (date_key, counter) VALUES (?, 1) 
             ON DUPLICATE KEY UPDATE counter = counter + 1"
        );
        $stmt->bind_param("s", $dateKey);
        $stmt->execute();
        $stmt->close();
        
        $stmt = $this->conn->prepare("SELECT counter FROM fiber_test_counters WHERE date_key = ?");
        $stmt->bind_param("s", $dateKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $counter = $row['counter'];
        $stmt->close();
        
        return sprintf("FTR-%s-%05d", $dateKey, $counter);
    }
    
    public function saveReport($data, $reportId = null) {
        try {
            $this->createMainTable();
            
            $report_number = $this->generateAndIncrementReportNumber();
            
            $test_results = [];
            $test_results_data = isset($data['test_results']) ? $data['test_results'] : [];
            
            // Get test parameters to include parameter names - must match the form display
            $test_parameters = [
                1 => 'Unit Weight',
                2 => 'Cut Length',
                3 => 'Tenacity at Break',
                4 => 'Std Deviation',
                5 => 'CV%',
                6 => 'Elongation at Break',
                7 => 'Cross Section',
                8 => 'No of Crimps',
                9 => 'UV Weathering'
            ];
            
            if (!empty($test_results_data)) {
                foreach ($test_results_data as $sl_no => $result) {
                    // Always save the row if it has data or if it's a valid parameter
                    $test_results[] = [
                        'sl_no' => $sl_no,
                        'parameter' => $test_parameters[$sl_no] ?? '',
                        'unit' => $result['unit'] ?? '',
                        'test_result' => $result['result'] ?? '',
                        'remarks' => $result['remarks'] ?? ''
                    ];
                }
            }
            
        $test_results_json = json_encode($test_results);
            
            $user_role = strtolower(trim($_SESSION['role'] ?? ''));
            $status = ($user_role === 'admin' || $user_role === 'agm ops' || $user_role === 'agm operations') ? 'approved' : 'pending';
            
            $approved_by_value = (isset($data['approved_by']) && !empty($data['approved_by'])) ? $data['approved_by'] : null;
            
            $stmt = $this->conn->prepare(
                "INSERT INTO fiber_test_reports (
                    report_number, store_entry_reference, sample_name, lc_no, sample_received_date, manufacturer_name,
                    sample_id, sample_tested_date, test_performed_by, approved_by,
                    test_results, comments, reporter_id, reporter_name, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            $stmt->bind_param(
                "sssssssssssssss",
                $report_number,
                $data['store_entry_reference'],
                $data['sample_name'],
                $data['lc_no'],
                $data['sample_received_date'],
                $data['manufacturer_name'],
                $data['sample_id'],
                $data['sample_tested_date'],
                $data['test_performed_by'],
                $approved_by_value,
                $test_results_json,
                $data['comments'],
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
                'message' => "Fiber Test Report saved successfully! Report Number: $report_number",
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
             FROM fiber_test_reports WHERE status = 'pending' ORDER BY updated_at DESC LIMIT ?"
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
                "UPDATE fiber_test_reports 
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
             FROM fiber_test_reports 
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
$fiberTestHandler = new FiberTestReportHandler($conn, $reporter_id, $reporter_name, $reporter_full_name);

// Ensure tables exist
$fiberTestHandler->createMainTable();
$fiberTestHandler->createCounterTable();

// Fetch pending reports for admin/AGM Ops
$pending_reports = [];
if ($can_approve) {
    $pending_reports = $fiberTestHandler->getPendingReports(20);
}

// Generate report number for display (without incrementing) - ALWAYS fetch fresh from DB
$generated_report_number = $fiberTestHandler->getNextReportNumber();

// Force fresh query by re-fetching to ensure real-time accuracy
$now_check = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
$hour_check = (int)$now_check->format('H');
$dayKey_check = $now_check->format('Ymd');
if ($hour_check < 8) {
    $yesterday_check = clone $now_check;
    $yesterday_check->modify('-1 day');
    $dayKey_check = $yesterday_check->format('Ymd');
}
$pattern_check = 'FTR-' . $dayKey_check . '-%';
$fresh_count = $conn->query("SELECT COUNT(*) as cnt FROM fiber_test_reports WHERE report_number LIKE '{$pattern_check}'");
if ($fresh_count && $row_check = $fresh_count->fetch_assoc()) {
    $next_num = (int)$row_check['cnt'] + 1;
    $generated_report_number = "FTR-{$dayKey_check}-" . str_pad($next_num, 5, '0', STR_PAD_LEFT);
}

// Check if editing an existing report
$editMode = false;
$editData = null;
if (isset($_GET['id'])) {
    $editId = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT * FROM fiber_test_reports WHERE id = ? AND reporter_id = ?");
    $stmt->bind_param("ii", $editId, $reporter_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $editData = $result->fetch_assoc();
        // Fiber test report allows editing any report by the same reporter
        $editMode = true;
        $generated_report_number = $editData['report_number'];
    } else {
        $error = "Report not found or you don't have permission to edit it.";
    }
    $stmt->close();
}

// If not in edit mode, fetch last submitted report to pre-fill general information
$lastSubmittedData = null;
if (!$editMode) {
    $stmt = $conn->prepare("SELECT * FROM fiber_test_reports WHERE reporter_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param("i", $reporter_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $lastSubmittedData = $result->fetch_assoc();
    }
    $stmt->close();
}

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wf_action'], $_POST['wf_report_number'])) {
    try {
        $action = $_POST['wf_action'];
        $report_number = trim($_POST['wf_report_number']);
        $comment = trim($_POST['wf_comment'] ?? '');
        
        // Handle rejection reasons checkboxes for fiber test
        if ($action === 'rejected' && isset($_POST['fiber_rejection_reasons']) && is_array($_POST['fiber_rejection_reasons'])) {
            $rejection_reasons = array_map('trim', $_POST['fiber_rejection_reasons']);
            $reasons_text = implode(', ', $rejection_reasons);
            // Prepend reasons to comment
            $comment = "Rejection Reasons: " . $reasons_text . ($comment ? "\n\nAdditional Comments: " . $comment : '');
        }
        
        if (!$fiberTestHandler->canApproveReports()) {
            throw new Exception("You don't have permission to approve reports.");
        }
        
        $result = $fiberTestHandler->approveOrReject($report_number, $action, $comment);
        $message = $result['message'];
        
        // Refresh pending reports
        $pending_reports = $fiberTestHandler->getPendingReports(20);
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    try {
        if ($editMode && isset($_POST['report_id'])) {
            // Update existing report
            $reportId = (int)$_POST['report_id'];
            $_POST['report_number'] = $editData['report_number']; // Keep same report number
            $_POST['status'] = 'pending'; // Reset to pending after resubmission
            
            // Call update method (we'll use the same saveReport but check for ID)
            $result = $fiberTestHandler->saveReport($_POST, $reportId);
            $message = "✅ Fiber Test Report resubmitted successfully! Report Number: " . $editData['report_number'] . " (Status: Pending Approval)";
            
            // Exit edit mode
            $editMode = false;
            $editData = null;
        } else {
            // Create new report
            $result = $fiberTestHandler->saveReport($_POST);
            $message = $result['message'];
            
            // Auto-approval message
            if ($can_approve) {
                $message = "✅ Fiber Test Report saved and auto-approved! Report Number: " . $result['report_number'];
            } else {
                $message = "✅ Fiber Test Report submitted successfully! Report Number: " . $result['report_number'] . " (Status: Pending Approval)";
            }
            
            // Store success message in session and redirect to refresh dropdown
            $_SESSION['success_message'] = $message;
            $success_msg = urlencode($message);
            header("Location: fiber_test_report.php?success=1&msg={$success_msg}&t=" . time());
            exit();
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Test parameters with their standards and units
$test_parameters = [
    1 => ['param' => 'Unit Weight', 'standards' => ['EN ISO 1973'], 'unit' => 'dTex'],
    2 => ['param' => 'Cut Length', 'standards' => ['ASTM D5103'], 'unit' => 'mm'],
    3 => ['param' => 'Tenacity at Break', 'standards' => ['EN ISO 5079'], 'unit' => 'cN/dTex'],
    4 => ['param' => 'Std Deviation', 'standards' => ['EN ISO 5079'], 'unit' => 'cN/dTex'],
    5 => ['param' => 'CV%', 'standards' => ['EN ISO 5079'], 'unit' => '%'],
    6 => ['param' => 'Elongation at Break', 'standards' => ['EN ISO 5079'], 'unit' => '%'],
    7 => ['param' => 'Cross Section', 'standards' => ['Round'], 'unit' => '', 'no_test_result' => true],
    8 => ['param' => 'No of Crimps', 'standards' => ['ASTM D3937'], 'unit' => 'Nos/25mm'],
    9 => ['param' => 'UV Weathering', 'standards' => ['ASTM D4355'], 'unit' => '%']
];

// Unit options for dropdown
$unit_options = ['dTex', 'mm', 'cN/dTex', '%', 'Nos/25mm'];

// Acceptance criteria
$acceptance_criteria = [
    'Tenacity' => ['High' => '5.4+', 'Good' => '5+', 'Medium' => '4.5+', 'BWDB Requirements' => ''],
    'Elongation' => ['High' => '0.8', 'Good' => '0.6', 'Medium' => '', 'BWDB Requirements' => '60%+']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fiber Test Report</title>
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
  .acceptance-table { width:100%; border-collapse:collapse; margin-top:20px; }
  .acceptance-table th, .acceptance-table td { border:1px solid #ddd; padding:8px; text-align:center; }
  .acceptance-table th { background:#27ae60; color:#fff; font-weight:600; }
  .acceptance-table input { width:100%; border:none; background:transparent; text-align:center; }
  .form-row { display:flex; gap:20px; margin-bottom:25px; }
  .form-row .form-group { flex:1; }
</style>
</head>
<body>
<div class="container">
  <h1>🧪 Fiber Test Report</h1>

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

  <?php if ($can_approve): ?>
  <!-- Pending Approval Queue -->
    <?php if (!empty($pending_reports)): ?>
    <div style="margin-top:16px; padding:12px; border:1px solid #ddd; border-radius:8px; background:#fff;">
      <h3 style="margin:0 0 12px 0;">Pending Reports</h3>
      <table class="test-table">
        <thead>
          <tr>
            <th>Report No</th>
            <th>Sample ID</th>
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
              <a href="../admin/view_fiber_report.php?id=<?php echo $pr['id']; ?>" target="_blank" class="submit-btn" style="padding:6px 10px; text-decoration:none; display:inline-block; background:#3498db; margin-right:4px;">View</a>
              <form method="POST" action="" style="display:inline; margin-right:4px;" onsubmit="return confirmApproval(this);">
                <input type="hidden" name="wf_report_number" value="<?php echo htmlspecialchars($pr['report_number']); ?>">
                <input type="hidden" name="wf_comment" value="Approved from queue">
                <button type="submit" name="wf_action" value="approved" class="submit-btn" style="padding:6px 10px;">Approve</button>
              </form>
              <button type="button" onclick="openFiberRejectModal('<?php echo htmlspecialchars($pr['report_number']); ?>')" class="clear-btn" style="padding:6px 10px; border:none; cursor:pointer;">Reject</button>
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
  <?php $rejected = $fiberTestHandler->getRejectedReportsForUser($reporter_id); if (!empty($rejected)): ?>
  <div style="margin-top:16px; padding:12px; border:1px solid #f8d7da; border-radius:8px; background:#fff3cd;">
    <h3 style="margin:0 0 12px 0; color:#721c24;">❌ Rejected Reports - Action Required</h3>
    <p style="margin:0 0 12px 0; color:#856404;">The following reports were rejected. Please review the comments and make corrections.</p>
    <table class="test-table">
      <thead>
        <tr>
          <th>Report No</th>
          <th>Sample ID</th>
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
            <a href="edit_fiber_report.php?id=<?php echo $rj['id']; ?>" class="submit-btn" style="padding:6px 10px; text-decoration:none; display:inline-block; background:#e67e22; color:#fff;">Edit & Resubmit</a>
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
    <?php if ($editMode): ?>
    <input type="hidden" name="report_id" value="<?php echo $editData['id']; ?>">
    <div class="alert alert-info" style="background:#e3f2fd; color:#1565c0; padding:12px; border-radius:6px; margin-bottom:20px;">
      <i class="fas fa-edit"></i> <strong>Edit Mode:</strong> You are resubmitting Report #<?php echo htmlspecialchars($editData['report_number']); ?>
      <?php if (empty($editData['sample_name']) && empty($editData['lc_no']) && empty($editData['manufacturer_name'])): ?>
      <br><small style="color:#e74c3c;">⚠️ Warning: Some general information fields appear to be empty in the database.</small>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <div class="form-row">
      <div class="form-group">
        <label>Report Number:</label>
        <input type="text" name="report_number" id="report_number" value="<?php echo htmlspecialchars($generated_report_number); ?>" readonly class="readonly">
      </div>
      <div class="form-group">
        <label>Store Entry Reference: <span style="color: #e74c3c;">*</span></label>
        <select name="store_entry_reference" id="store_entry_reference" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; background: #fff; font-family: inherit;">
          <option value="">-- Select Store Entry --</option>
          <?php if (empty($storeEntries)): ?>
            <option value="" disabled>No store entries available</option>
          <?php else: ?>
            <?php foreach ($storeEntries as $entry): ?>
              <option value="<?php echo htmlspecialchars($entry['entry_number']); ?>" 
                data-manufacturer="<?php echo htmlspecialchars($entry['manufacturer_name'] ?? ''); ?>"
                <?php 
                  $storeSelected = false;
                  if ($editMode && ($editData['store_entry_reference'] ?? '') === $entry['entry_number']) {
                      $storeSelected = true;
                  } elseif (!$editMode && $lastSubmittedData && ($lastSubmittedData['store_entry_reference'] ?? '') === $entry['entry_number']) {
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
          <?php endif; ?>
          <?php if ($editMode && !empty($editData['store_entry_reference'])): ?>
            <?php
            // Check if the store entry is not in the dropdown (already used)
            $store_found = false;
            if (!empty($storeEntries)) {
                foreach ($storeEntries as $entry) {
                    if ($entry['entry_number'] === $editData['store_entry_reference']) {
                        $store_found = true;
                        break;
                    }
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
        <small style="color: #7f8c8d; font-size: 0.85em;">Select the material from store that you are testing</small>
      </div>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label>Sample Name:</label>
        <input type="text" name="sample_name" placeholder="Enter sample name" value="<?php 
          if ($editMode) {
              echo htmlspecialchars($editData['sample_name'] ?? '');
          } elseif ($lastSubmittedData) {
              echo htmlspecialchars($lastSubmittedData['sample_name'] ?? '');
          }
        ?>" required>
      </div>
      <div class="form-group">
        <label>LC No:</label>
        <input type="text" name="lc_no" placeholder="Enter LC number" value="<?php 
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
        <label>Manufacturer Name:</label>
        <select name="manufacturer_name" id="manufacturer_name" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; background: #fff; font-family: inherit;">
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
      <div class="form-group">
        <label>Sample Received Date:</label>
        <input type="date" name="sample_received_date" value="<?php 
          if ($editMode) {
              echo htmlspecialchars($editData['sample_received_date'] ?? '');
          } elseif ($lastSubmittedData) {
              echo htmlspecialchars($lastSubmittedData['sample_received_date'] ?? '');
          }
        ?>" required>
      </div>
      <div class="form-group">
        <label>Sample Tested Date:</label>
        <input type="date" name="sample_tested_date" value="<?php 
          if ($editMode) {
              echo htmlspecialchars($editData['sample_tested_date']);
          } elseif ($lastSubmittedData && !empty($lastSubmittedData['sample_tested_date'])) {
              echo htmlspecialchars($lastSubmittedData['sample_tested_date']);
          } else {
              echo date('Y-m-d');
          }
        ?>" required>
      </div>
    </div>
    
    <input type="hidden" name="sample_id" id="sample_id_hidden" value="<?php echo $editMode ? htmlspecialchars($editData['sample_id'] ?? '') : ''; ?>">

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
          <?php if ($param['param'] === 'Cross Section'): ?>
            <td colspan="3" style="text-align:center;">Round</td>
          <?php else: ?>
            <td><?php echo htmlspecialchars($param['standards'][0]); ?></td>
            <td>
              <select name="test_results[<?php echo $sl_no; ?>][unit]">
                <option value="">Select Unit</option>
                <?php foreach ($unit_options as $unit): ?>
                <option value="<?php echo htmlspecialchars($unit); ?>" <?php echo ($unit === $param['unit']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($unit); ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <input type="number" step="0.01" name="test_results[<?php echo $sl_no; ?>][result]">
            </td>
          <?php endif; ?>
          <td><input type="text" name="test_results[<?php echo $sl_no; ?>][remarks]"></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Acceptance Criteria Table -->
    <h3 style="text-align:center; margin:30px 0 20px 0;">Acceptance Criteria</h3>
    <table class="acceptance-table">
      <thead>
        <tr>
          <th>Acceptance Range</th>
          <th>High</th>
          <th>Good</th>
          <th>Medium</th>
          <th>BWDB Requirements</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>Tenacity</td>
          <td><input type="text" value="5.4+" readonly class="readonly"></td>
          <td><input type="text" value="5+" readonly class="readonly"></td>
          <td><input type="text" value="4.5+" readonly class="readonly"></td>
          <td><input type="text" value="" readonly class="readonly"></td>
        </tr>
        <tr>
          <td>Elongation</td>
          <td><input type="text" value="0.8" readonly class="readonly"></td>
          <td><input type="text" value="0.6" readonly class="readonly"></td>
          <td><input type="text" value="" readonly class="readonly"></td>
          <td><input type="text" value="60%+" readonly class="readonly"></td>
        </tr>
      </tbody>
    </table>

    <!-- Comments -->
    <div class="form-group">
      <label>Comments:</label>
      <textarea name="comments" rows="3"></textarea>
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
</div>

<script>
// Edit mode data
const editMode = <?php echo $editMode ? 'true' : 'false'; ?>;
const editData = <?php echo $editMode && $editData ? json_encode($editData) : 'null'; ?>;

// Initialize time display
document.addEventListener('DOMContentLoaded', function() {
    updateTimeAndShift();
    
    // Populate form with edit data if in edit mode
    if (editMode && editData) {
        populateEditData();
    }
    
    // Auto-generate sample_id when form is submitted
    document.querySelector('form').addEventListener('submit', function(e) {
        const sampleName = document.querySelector('input[name="sample_name"]').value;
        const lcNo = document.querySelector('input[name="lc_no"]').value;
        const manufacturer = document.querySelector('select[name="manufacturer_name"]').value;
        
        // Auto-generate sample_id as combination of sample_name + manufacturer + LC
        let sampleId = sampleName;
        if (manufacturer) sampleId += ' - ' + manufacturer;
        if (lcNo) sampleId += ' - LC:' + lcNo;
        
        document.getElementById('sample_id_hidden').value = sampleId;
    });
    
    // Auto-select manufacturer name when store entry reference is selected
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

function populateEditData() {
    if (!editData) return;
    
    console.log('Populating edit data:', editData);
    
    // Populate test performed by if exists
    if (editData.test_performed_by) {
        const performedByInput = document.querySelector('input[name="test_performed_by"]');
        if (performedByInput) performedByInput.value = editData.test_performed_by;
    }
    
    // Populate approved by if exists and visible
    if (editData.approved_by) {
        const approvedByInput = document.querySelector('input[name="approved_by"]');
        if (approvedByInput) approvedByInput.value = editData.approved_by;
    }
    
    // Parse and populate test results if they exist
    if (editData.test_results) {
        try {
            const testResults = JSON.parse(editData.test_results);
            console.log('Test results:', testResults);
            
            // Populate each test parameter
            Object.keys(testResults).forEach(paramId => {
                const result = testResults[paramId];
                
                // Find the row for this parameter
                const specInput = document.querySelector(`input[name="specification_${paramId}"]`);
                const resultInput = document.querySelector(`input[name="test_result_${paramId}"]`);
                const remarksInput = document.querySelector(`input[name="test_remarks_${paramId}"]`);
                
                if (specInput && result.specification) {
                    specInput.value = result.specification;
                }
                if (resultInput && result.result) {
                    resultInput.value = result.result;
                }
                if (remarksInput && result.remarks) {
                    remarksInput.value = result.remarks;
                }
            });
        } catch (e) {
            console.error('Error parsing test results:', e);
        }
    }
    
    // Populate remarks if exists
    if (editData.remarks) {
        const remarksTextarea = document.querySelector('textarea[name="remarks"]');
        if (remarksTextarea) remarksTextarea.value = editData.remarks;
    }
}

function clearForm() {
    if (confirm('Are you sure you want to clear all data?')) {
        document.querySelector('form').reset();
        // Reset readonly fields
        document.querySelector('input[name="test_performed_by"]').value = '<?php echo htmlspecialchars($reporter_full_name); ?>';
        document.querySelector('input[name="report_number"]').value = '<?php echo htmlspecialchars($generated_report_number); ?>';
        document.querySelector('input[name="sample_tested_date"]').value = '<?php echo date('Y-m-d'); ?>';
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

// Fiber Test rejection modal functions
function openFiberRejectModal(reportNumber) {
    document.getElementById('fiberRejectReportNumber').value = reportNumber;
    document.getElementById('fiberRejectionModal').style.display = 'block';
}

function closeFiberRejectModal() {
    document.getElementById('fiberRejectionModal').style.display = 'none';
    document.getElementById('fiberAdminRejectForm').reset();
}

function submitFiberAdminRejection() {
    const checkboxes = document.querySelectorAll('input[name="fiber_rejection_reasons[]"]');
    const checked = Array.from(checkboxes).filter(cb => cb.checked);
    
    if (checked.length === 0) {
        alert('❌ Please select at least one reason for rejection!');
        return false;
    }
    
    if (confirm('Are you sure you want to reject this report?')) {
        const form = document.getElementById('fiberAdminRejectForm');
        form.onsubmit = null;
        form.submit();
    }
}
</script>

<!-- Rejection Modal for Admin (Fiber Test) -->
<div id="fiberRejectionModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow-y:auto;">
  <div style="max-width:600px; margin:50px auto; background:#fff; border-radius:8px; padding:25px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
    <h3 style="margin-top:0; color:#dc3545; border-bottom:2px solid #dc3545; padding-bottom:10px;">
      ❌ Reject Fiber Test Report
    </h3>
    
    <form id="fiberAdminRejectForm" method="POST" action="" onsubmit="return false;">
      <input type="hidden" id="fiberRejectReportNumber" name="wf_report_number" value="">
      <input type="hidden" name="wf_action" value="rejected">
      
      <label style="font-weight:600; display:block; margin-bottom:10px;">Reason for Rejection (Select at least one):</label>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="fiber_rejection_reasons[]" value="Incorrect Roll Identification" style="margin-right:8px;">
          Incorrect Roll Identification
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="fiber_rejection_reasons[]" value="Incorrect Fiber Specification Entry" style="margin-right:8px;">
          Incorrect Fiber Specification Entry
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="fiber_rejection_reasons[]" value="Excessive Sampling" style="margin-right:8px;">
          Excessive Sampling
        </label>
      </div>
      
      <label style="font-weight:bold; display:block; margin:15px 0 8px 0;">
        Additional Comments (Optional):
      </label>
      <textarea name="wf_comment" id="fiberAdminRejectComment" rows="4" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-family:inherit;" placeholder="Provide additional details..."></textarea>
      
      <div style="margin-top:20px; text-align:right;">
        <button type="button" onclick="closeFiberRejectModal()" style="padding:10px 20px; margin-right:10px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer;">Cancel</button>
        <button type="button" onclick="submitFiberAdminRejection()" style="padding:10px 20px; background:#dc3545; color:#fff; border:none; border-radius:6px; cursor:pointer;">Submit Rejection</button>
      </div>
    </form>
  </div>
</div>

</body>
</html>

