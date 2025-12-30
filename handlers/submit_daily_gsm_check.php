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
    
    // Generate Entry ID (GSM-YYYYMMDD-XXX) with 8 AM daily reset
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
    $tbl = $conn->query("SHOW TABLES LIKE 'daily_gsm_checks'");
    $nextId = 1;
    
    if ($tbl && $tbl->num_rows > 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM daily_gsm_checks WHERE created_at >= ?");
        $stmt->bind_param('s', $reset_timestamp);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $nextId = ($row['count'] ?? 0) + 1;
        }
        $stmt->close();
    }
    
    $entry_id = 'GSM-' . $date_part . '-' . str_pad($nextId, 3, '0', STR_PAD_LEFT);
    
    // Get array data
    $referenceNumbers = $_POST['reference_number'] ?? [];
    $rollNos = $_POST['roll_no'] ?? [];
    $sizeTypes = $_POST['size_type'] ?? [];
    $sizeValues = $_POST['size_value'] ?? [];
    $weightLeft = $_POST['weight_left'] ?? [];
    $weightLeftMiddle = $_POST['weight_left_middle'] ?? [];
    $weightRightMiddle = $_POST['weight_right_middle'] ?? [];
    $weightRight = $_POST['weight_right'] ?? [];
    $gsmLeft = $_POST['gsm_left'] ?? [];
    $gsmLeftMiddle = $_POST['gsm_left_middle'] ?? [];
    $gsmRightMiddle = $_POST['gsm_right_middle'] ?? [];
    $gsmRight = $_POST['gsm_right'] ?? [];
    $avgGsm = $_POST['avg_gsm'] ?? [];
    $remarks = $_POST['remarks'] ?? [];
    
    // Validate
    if (empty($dateTime) || empty($shift) || empty($lineNumber) || empty($rollNos)) {
        header("Location: ../forms/daily_gsm_check.php?error=" . urlencode('Missing required fields'));
        exit();
    }
    
    // Create table if not exists
    $conn->query("CREATE TABLE IF NOT EXISTS daily_gsm_checks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_id VARCHAR(50),
        reference_number VARCHAR(200),
        date_time DATETIME NOT NULL,
        shift VARCHAR(20) NOT NULL,
        line_number VARCHAR(20) NOT NULL,
        roll_no VARCHAR(50) NOT NULL,
        size_type VARCHAR(50) NOT NULL,
        size_value VARCHAR(50),
        weight_left DECIMAL(10,2),
        weight_left_middle DECIMAL(10,2),
        weight_right_middle DECIMAL(10,2),
        weight_right DECIMAL(10,2),
        gsm_left DECIMAL(10,2),
        gsm_left_middle DECIMAL(10,2),
        gsm_right_middle DECIMAL(10,2),
        gsm_right DECIMAL(10,2),
        avg_gsm DECIMAL(10,2),
        remarks TEXT,
        inspector VARCHAR(100),
        user_id INT,
        status VARCHAR(20) DEFAULT 'pending',
        approved_by VARCHAR(100),
        approved_at DATETIME,
        rejection_reason TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Ensure entry_id column exists and is VARCHAR (not numeric defaulting to 0)
    $colRes = $conn->query("SHOW COLUMNS FROM daily_gsm_checks LIKE 'entry_id'");
    if ($colRes && $col = $colRes->fetch_assoc()) {
        $type = strtolower($col['Type'] ?? '');
        if (strpos($type, 'varchar') === false) {
            @$conn->query("ALTER TABLE daily_gsm_checks MODIFY entry_id VARCHAR(50)");
        }
    } else {
        @$conn->query("ALTER TABLE daily_gsm_checks ADD COLUMN entry_id VARCHAR(50) AFTER id");
    }
    
    // Add missing columns if they don't exist (for existing tables)
    $checkSizeValue = $conn->query("SHOW COLUMNS FROM daily_gsm_checks LIKE 'size_value'");
    if ($checkSizeValue && $checkSizeValue->num_rows == 0) {
        $conn->query("ALTER TABLE daily_gsm_checks ADD COLUMN size_value VARCHAR(50) AFTER size_type");
    }
    
    $checkStatus = $conn->query("SHOW COLUMNS FROM daily_gsm_checks LIKE 'status'");
    if ($checkStatus && $checkStatus->num_rows == 0) {
        $conn->query("ALTER TABLE daily_gsm_checks ADD COLUMN status VARCHAR(20) DEFAULT 'pending' AFTER user_id");
        $conn->query("ALTER TABLE daily_gsm_checks ADD COLUMN approved_by VARCHAR(100) AFTER status");
        $conn->query("ALTER TABLE daily_gsm_checks ADD COLUMN approved_at DATETIME AFTER approved_by");
        $conn->query("ALTER TABLE daily_gsm_checks ADD COLUMN rejection_reason TEXT AFTER approved_at");
    }
    
    // Add reference_number column if it doesn't exist
    $checkRefNumber = $conn->query("SHOW COLUMNS FROM daily_gsm_checks LIKE 'reference_number'");
    if ($checkRefNumber && $checkRefNumber->num_rows == 0) {
        $conn->query("ALTER TABLE daily_gsm_checks ADD COLUMN reference_number VARCHAR(200) AFTER entry_id");
    }
    
    // Repair any legacy rows with empty/zero entry_id
    @$conn->query("UPDATE daily_gsm_checks 
        SET entry_id = CONCAT('GSM-', DATE_FORMAT(COALESCE(created_at, NOW()), '%Y%m%d'), '-', LPAD(id, 3, '0'))
        WHERE entry_id IS NULL OR entry_id = '' OR entry_id = '0'");

    // Insert each row
    $insertedCount = 0;
    for ($i = 0; $i < count($rollNos); $i++) {
        if (empty($rollNos[$i])) continue;
        
        $stmt = $conn->prepare("INSERT INTO daily_gsm_checks 
            (entry_id, reference_number, date_time, shift, line_number, roll_no, size_type, size_value,
             weight_left, weight_left_middle, weight_right_middle, weight_right,
             gsm_left, gsm_left_middle, gsm_right_middle, gsm_right, avg_gsm,
             remarks, inspector, user_id, status, approved_by, approved_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        // Prepare variables for bind_param (cannot use expressions directly)
        $refNumber = $referenceNumbers[$i] ?? '';
        $sizeType = $sizeTypes[$i] ?? 'N/A';
        $sizeValue = $sizeValues[$i] ?? '';
        $remark = $remarks[$i] ?? '';
        
        $stmt->bind_param('ssssssssdddddddddssisss',
            $entry_id,
            $refNumber,
            $dateTime,
            $shift,
            $lineNumber,
            $rollNos[$i],
            $sizeType,
            $sizeValue,
            $weightLeft[$i],
            $weightLeftMiddle[$i],
            $weightRightMiddle[$i],
            $weightRight[$i],
            $gsmLeft[$i],
            $gsmLeftMiddle[$i],
            $gsmRightMiddle[$i],
            $gsmRight[$i],
            $avgGsm[$i],
            $remark,
            $inspector,
            $userId,
            $autoApproveStatus,
            $approvedBy,
            $approvedAt
        );
        
        if ($stmt->execute()) {
            $insertedCount++;
        }
        $stmt->close();
    }
    
    $conn->close();
    
    $successMsg = $isAdminOrAGM
        ? "GSM check submitted! Entry ID: $entry_id | $insertedCount row(s) recorded. Status: Auto-Approved"
        : "GSM check submitted! Entry ID: $entry_id | $insertedCount row(s) recorded. Status: Pending AGM/Admin Approval";
    
    header("Location: ../forms/daily_gsm_check.php?success=" . urlencode($successMsg));
    exit();
    
} catch (Exception $e) {
    error_log("Daily GSM Check Error: " . $e->getMessage());
    header("Location: ../forms/daily_gsm_check.php?error=" . urlencode('System error: ' . $e->getMessage()));
    exit();
}
?>
