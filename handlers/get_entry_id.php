<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$date = $_GET['date'] ?? date('Y-m-d');
$module = $_GET['module'] ?? '';

try {
    // Generate Entry ID for Length Calibration or Daily GSM Check with 8 AM daily reset
    if ($module == 'length_calibration' || $module == 'daily_gsm_check') {
        $table = $module == 'length_calibration' ? 'length_calibrations' : 'daily_gsm_checks';
        $prefix = $module == 'length_calibration' ? 'LC-' : 'GSM-';
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
        $checkTable = $conn->query("SHOW TABLES LIKE '$table'");
        $nextId = 1;
        
        if ($checkTable && $checkTable->num_rows > 0) {
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM $table WHERE created_at >= ?");
            $stmt->bind_param('s', $reset_timestamp);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $nextId = ($row['count'] ?? 0) + 1;
            }
            $stmt->close();
        }
        
        $entry_id = $prefix . $date_part . '-' . str_pad($nextId, 3, '0', STR_PAD_LEFT);
        echo json_encode(['entry_id' => $entry_id]);
    } else {
        echo json_encode(['entry_id' => 'N/A']);
    }
    
} catch (Exception $e) {
    echo json_encode(['entry_id' => 'LC-00000000-000', 'error' => $e->getMessage()]);
}

$conn->close();
?>


