<?php
// Delete FG entries not from today
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// Optional: require auth if desired
// if (!isset($_SESSION['user_id'])) { http_response_code(403); exit('Unauthorized'); }

require_once __DIR__ . '/config/security_config.php';

date_default_timezone_set('Asia/Dhaka');

try {
    $conn = SecurityConfig::getConnection();

    // Check table existence
    $tableCheck = $conn->query("SHOW TABLES LIKE 'fg_entry'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        echo 'Table fg_entry does not exist. Nothing to delete.';
        exit;
    }

    // Ensure session time zone
    $conn->query("SET time_zone = '+06:00'");

    // Detect date_time column type
    $colType = 'datetime';
    $colInfo = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'date_time'");
    if ($colInfo && $c = $colInfo->fetch_assoc()) {
        $colType = strtolower($c['Type'] ?? 'datetime');
    }

    // Build predicates for keeping only today (Dhaka)
    $keepStart = "CURDATE()"; // 00:00 today per session time_zone
    $keepEnd = "DATE_ADD(CURDATE(), INTERVAL 1 DAY)"; // next day 00:00

    if (strpos($colType, 'date') !== false || strpos($colType, 'time') !== false) {
        $notTodayPredicate = "date_time IS NULL OR NOT (date_time >= $keepStart AND date_time < $keepEnd)";
    } else {
        // Fallback: treat as string
        $notTodayPredicate = "date_time IS NULL OR NOT (STR_TO_DATE(date_time,'%Y-%m-%d %H:%i:%s') >= $keepStart AND STR_TO_DATE(date_time,'%Y-%m-%d %H:%i:%s') < $keepEnd)";
    }

    // Count rows to be deleted (anything not within today's window)
    $countSql = "SELECT COUNT(*) AS cnt FROM fg_entry WHERE $notTodayPredicate";
    $countRes = $conn->query($countSql);
    $toDelete = 0;
    if ($countRes && $row = $countRes->fetch_assoc()) {
        $toDelete = (int)$row['cnt'];
    }

    // Perform delete
    $delSql = "DELETE FROM fg_entry WHERE $notTodayPredicate";
    if (!$conn->query($delSql)) {
        throw new Exception('Delete failed: ' . $conn->error);
    }

    // Drop columns if exist: batch_number, qc_inspector
    $cols = ['batch_number','qc_inspector'];
    foreach ($cols as $col) {
        $check = $conn->query("SHOW COLUMNS FROM fg_entry LIKE '" . $conn->real_escape_string($col) . "'");
        if ($check && $check->num_rows > 0) {
            if (!$conn->query("ALTER TABLE fg_entry DROP COLUMN `{$col}`")) {
                throw new Exception('Failed to drop column ' . $col . ': ' . $conn->error);
            }
        }
    }

    echo "Deleted {$toDelete} FG entries not from today. Dropped columns where present: batch_number, qc_inspector.";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error: ' . $e->getMessage();
}
?>



