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
    $dateTime = $_POST['date_time'] ?? '';
    $shift = $_POST['shift'] ?? '';
    $lineNumber = $_POST['line_number'] ?? '';
    $inspector = $_POST['inspector'] ?? '';
    $userId = $_SESSION['user_id'];
    
    // Check if user is Admin/AGM - auto-approve their submissions
    $userRole = strtolower(trim($_SESSION['role'] ?? 'user'));
    $isAdminOrAGM = in_array($userRole, ['admin', 'agm', 'agm ops', 'agm operations', 'management']);
    $autoApproveStatus = $isAdminOrAGM ? 'approved' : 'pending';
    $approvedBy = $isAdminOrAGM ? $_SESSION['username'] : null;
    $approvedAt = $isAdminOrAGM ? date('Y-m-d H:i:s') : null;
    
    // Generate Entry ID (LC-YYYYMMDD-XXX) with 8 AM daily reset
    $dhaka_tz = new DateTimeZone('Asia/Dhaka');
    $now = new DateTime('now', $dhaka_tz);
    $current_hour = (int)$now->format('H');
    
    // Determine reset date (8 AM cutoff)
    $reset_date = clone $now;
    if ($current_hour < 8) {
        $reset_date->modify('-1 day');
    }
    $reset_date->setTime(8, 0, 0);
    $reset_timestamp = $reset_date->format('Y-m-d H:i:s');
    $date_part = $reset_date->format('Ymd');
    
    // Get next entry number
    $tbl = $conn->query("SHOW TABLES LIKE 'length_calibrations'");
    $nextId = 1;
    
    if ($tbl && $tbl->num_rows > 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM length_calibrations WHERE created_at >= ?");
        $stmt->bind_param('s', $reset_timestamp);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $nextId = ($row['count'] ?? 0) + 1;
        }
        $stmt->close();
    }
    
    $entry_id = 'LC-' . $date_part . '-' . str_pad($nextId, 3, '0', STR_PAD_LEFT);
    
    // Get array data
    $referenceNumbers = $_POST['reference_number'] ?? [];
    $rollNos = $_POST['roll_no'] ?? [];
    $referenceLengths = $_POST['reference_length'] ?? [];
    $setInMachines = $_POST['set_in_machine'] ?? [];
    $actualLengths = $_POST['actual_length'] ?? [];
    $calibrationLengths = $_POST['calibration_length'] ?? [];
    
    // Validate
    if (empty($dateTime) || empty($shift) || empty($lineNumber) || empty($rollNos)) {
        header("Location: ../forms/length_calibration_entry.php?error=" . urlencode('Missing required fields'));
        exit();
    }
    
    // Create table if not exists
    $conn->query("CREATE TABLE IF NOT EXISTS length_calibrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_id VARCHAR(50),
        reference_number VARCHAR(200),
        date_time DATETIME NOT NULL,
        shift VARCHAR(20) NOT NULL,
        line_number VARCHAR(20) NOT NULL,
        roll_no VARCHAR(50) NOT NULL,
        reference_length DECIMAL(10,2) NOT NULL,
        set_in_machine DECIMAL(10,2) NOT NULL,
        actual_length DECIMAL(10,2) NOT NULL,
        difference DECIMAL(10,2) NOT NULL,
        calibration_length DECIMAL(10,2) NOT NULL,
        inspector VARCHAR(100),
        user_id INT,
        status VARCHAR(20) DEFAULT 'pending',
        approved_by VARCHAR(100),
        approved_at DATETIME,
        rejection_reason TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Add missing columns if they don't exist (for existing tables)
    $checkEntryId = $conn->query("SHOW COLUMNS FROM length_calibrations LIKE 'entry_id'");
    if ($checkEntryId && $checkEntryId->num_rows == 0) {
        $conn->query("ALTER TABLE length_calibrations ADD COLUMN entry_id VARCHAR(50) AFTER id");
    }
    
    $checkStatus = $conn->query("SHOW COLUMNS FROM length_calibrations LIKE 'status'");
    if ($checkStatus && $checkStatus->num_rows == 0) {
        $conn->query("ALTER TABLE length_calibrations ADD COLUMN status VARCHAR(20) DEFAULT 'pending' AFTER user_id");
        $conn->query("ALTER TABLE length_calibrations ADD COLUMN approved_by VARCHAR(100) AFTER status");
        $conn->query("ALTER TABLE length_calibrations ADD COLUMN approved_at DATETIME AFTER approved_by");
        $conn->query("ALTER TABLE length_calibrations ADD COLUMN rejection_reason TEXT AFTER approved_at");
    }
    
    // Add reference_number column if it doesn't exist
    $checkRefNumber = $conn->query("SHOW COLUMNS FROM length_calibrations LIKE 'reference_number'");
    if ($checkRefNumber && $checkRefNumber->num_rows == 0) {
        $conn->query("ALTER TABLE length_calibrations ADD COLUMN reference_number VARCHAR(200) AFTER entry_id");
    }
    
    $successCount = 0;
    
    // Insert each row
    for ($i = 0; $i < count($rollNos); $i++) {
        if (!empty($rollNos[$i])) {
            $refLength = floatval($referenceLengths[$i]);
            $setMachine = floatval($setInMachines[$i]);
            $actualLength = floatval($actualLengths[$i]);
            $calLength = floatval($calibrationLengths[$i]);
            
            // Calculate difference (Reference - Actual)
            $difference = $refLength - $actualLength;
            
            $refNumber = $referenceNumbers[$i] ?? '';
            
            $stmt = $conn->prepare("INSERT INTO length_calibrations 
                (entry_id, reference_number, date_time, shift, line_number, roll_no, reference_length, set_in_machine, 
                 actual_length, difference, calibration_length, inspector, user_id, status, approved_by, approved_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->bind_param("ssssssdddddsisss",
                $entry_id,
                $refNumber,
                $dateTime,
                $shift,
                $lineNumber,
                $rollNos[$i],
                $refLength,
                $setMachine,
                $actualLength,
                $difference,
                $calLength,
                $inspector,
                $userId,
                $autoApproveStatus,
                $approvedBy,
                $approvedAt
            );
            
            if ($stmt->execute()) {
                $successCount++;
            }
            $stmt->close();
        }
    }
    
    $conn->close();
    
    $successMsg = $isAdminOrAGM
        ? "Length Calibration submitted! Entry ID: $entry_id | $successCount row(s) recorded. Status: Auto-Approved"
        : "Length Calibration submitted! Entry ID: $entry_id | $successCount row(s) recorded. Status: Pending AGM/Admin Approval";
    
    header("Location: ../forms/length_calibration_entry.php?success=" . urlencode($successMsg));
    
} catch (Exception $e) {
    header("Location: ../forms/length_calibration_entry.php?error=" . urlencode("Error: " . $e->getMessage()));
}
?>


