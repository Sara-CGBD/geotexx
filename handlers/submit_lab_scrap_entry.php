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
    // Get form data
    $scrapId = $_POST['scrap_id'] ?? '';
    $dateTime = $_POST['date_time'] ?? '';
    $shift = $_POST['shift'] ?? '';
    $referenceNumber = $_POST['reference_number'] ?? '';
    $cuttingBatch = $_POST['cutting_batch'] ?? '';
    $weight = (float)($_POST['weight'] ?? 0);
    $scrapType = $_POST['scrap_type'] ?? '';
    $inspector = $_POST['inspector'] ?? '';
    $remarks = $_POST['remarks'] ?? '';
    $sendToRecycle = (int)($_POST['send_to_recycle'] ?? 1);
    $reporterId = $_SESSION['user_id'];
    $reporterName = $_SESSION['full_name'] ?? $_SESSION['username'];
    
    // Validate required fields
    if (empty($scrapId) || empty($referenceNumber) || $weight <= 0 || empty($scrapType)) {
        header("Location: ../forms/lab_testing_scrap_entry.php?error=" . urlencode('Missing required fields'));
        exit();
    }
    
    // Ensure scrap table has required columns for lab testing
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS scrap_id VARCHAR(50) UNIQUE AFTER id");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS date_time DATETIME AFTER scrap_id");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS shift VARCHAR(20) AFTER date_time");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS scrap_product VARCHAR(100) AFTER scrap_type");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS scrap_category VARCHAR(100) AFTER scrap_product");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS reference_number VARCHAR(100) AFTER scrap_category");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS cutting_batch VARCHAR(100) AFTER reference_number");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS reporter_id INT AFTER cutting_batch");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS reporter_name VARCHAR(100) AFTER reporter_id");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER reporter_name");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS send_to_recycle TINYINT(1) DEFAULT 1 AFTER remarks");
    
    // Insert lab testing scrap entry
    $stmt = $conn->prepare("INSERT INTO scrap 
        (scrap_id, date_time, shift, scrap_type, scrap_product, scrap_category, reference_number, cutting_batch, 
         qty, reporter_id, reporter_name, remarks, send_to_recycle, is_deleted)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)");
    
    $scrapProduct = 'Lab Testing';
    $scrapCategory = 'Sheet Production Scrap';
    
    $stmt->bind_param('ssssssssdissi', 
        $scrapId, 
        $dateTime, 
        $shift, 
        $scrapType, 
        $scrapProduct, 
        $scrapCategory, 
        $referenceNumber, 
        $cuttingBatch,
        $weight,
        $reporterId,
        $reporterName,
        $remarks,
        $sendToRecycle
    );
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to insert lab scrap entry: ' . $stmt->error);
    }
    
    $stmt->close();
    $conn->close();
    
    $recycleStatus = $sendToRecycle ? 'Will be sent to Recycle' : 'Not for recycling';
    header("Location: ../forms/lab_testing_scrap_entry.php?success=" . urlencode('Lab scrap entry saved! ID: ' . $scrapId . ' | Weight: ' . $weight . ' kg | ' . $recycleStatus));
    exit();
    
} catch (Exception $e) {
    error_log("Lab Scrap Entry Error: " . $e->getMessage());
    header("Location: ../forms/lab_testing_scrap_entry.php?error=" . urlencode('System error: ' . $e->getMessage()));
    exit();
}
?>


