<?php
// API endpoint for sewing dashboard data
session_start();
require_once '../../config/security_config.php';

// Security check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Role check - only sewing machine role
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['sewing_test', 'sewing machine', 'sewing'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access Denied']);
    exit;
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Date calculations
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$weekStart = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');
$yearStart = date('Y-01-01');

// Helper functions (same as in dashboard)
function getSewingStats($conn, $dateFrom, $dateTo) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
    $tableName = ($tableCheck && $tableCheck->num_rows > 0) ? 'sewing_machine_entry' : 'swing_machine_entry';
    
    $query = "SELECT 
        COUNT(*) as total_entries,
        COALESCE(SUM(sewing_qty), 0) as total_sewing_qty,
        COALESCE(SUM(ncp_piece), 0) as total_ncp,
        COUNT(DISTINCT line_no) as unique_lines,
        COUNT(DISTINCT DATE(date_time)) as unique_days
    FROM $tableName
    WHERE DATE(date_time) BETWEEN ? AND ?";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param('ss', $dateFrom, $dateTo);
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result->fetch_assoc();
        $stmt->close();
        return $stats;
    }
    return ['total_entries' => 0, 'total_sewing_qty' => 0, 'total_ncp' => 0, 'unique_lines' => 0, 'unique_days' => 0];
}

function getNCPPieces($conn, $dateFrom, $dateTo) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
    $tableName = ($tableCheck && $tableCheck->num_rows > 0) ? 'sewing_machine_entry' : 'swing_machine_entry';
    
    $query = "SELECT COALESCE(SUM(ncp_piece), 0) as total_ncp 
              FROM $tableName 
              WHERE DATE(date_time) BETWEEN ? AND ?";
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param('ss', $dateFrom, $dateTo);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return (int)($row['total_ncp'] ?? 0);
    }
    return 0;
}

function getTotalQuantity($conn, $tableName, $dateColumn, $quantityColumn, $dateFrom, $dateTo) {
    $tableCheck = $conn->query("SHOW TABLES LIKE '$tableName'");
    if (!$tableCheck || $tableCheck->num_rows == 0) {
        return 0;
    }
    
    $dateColCheck = $conn->query("SHOW COLUMNS FROM $tableName LIKE '$dateColumn'");
    if (!$dateColCheck || $dateColCheck->num_rows == 0) {
        $dateColCheck2 = $conn->query("SHOW COLUMNS FROM $tableName LIKE 'created_at'");
        if ($dateColCheck2 && $dateColCheck2->num_rows > 0) {
            $dateColumn = 'created_at';
        } else {
            return 0;
        }
    }
    
    $qtyColCheck = $conn->query("SHOW COLUMNS FROM $tableName LIKE '$quantityColumn'");
    if (!$qtyColCheck || $qtyColCheck->num_rows == 0) {
        return 0;
    }
    
    $query = "SELECT COALESCE(SUM($quantityColumn), 0) as total_qty FROM $tableName WHERE DATE($dateColumn) BETWEEN ? AND ?";
    
    $colCheck = $conn->query("SHOW COLUMNS FROM $tableName LIKE 'is_deleted'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $query .= " AND (is_deleted = 0 OR is_deleted IS NULL)";
    }
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param('ss', $dateFrom, $dateTo);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return (float)($row['total_qty'] ?? 0);
    }
    return 0;
}

function getComprehensiveStats($conn, $dateFrom, $dateTo) {
    $sewingTableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
    $sewingTable = ($sewingTableCheck && $sewingTableCheck->num_rows > 0) ? 'sewing_machine_entry' : 'swing_machine_entry';
    
    $rollQty = getTotalQuantity($conn, 'roll_received', 'reporting_time', 'roll_quantity', $dateFrom, $dateTo);
    if ($rollQty == 0) {
        // Fallback to count if quantity column doesn't exist
        $rollQty = getEntryCounts($conn, 'roll_received', 'reporting_time', $dateFrom, $dateTo);
    }
    
    return [
        'roll_received' => $rollQty,
        'cnc_cutting' => getTotalQuantity($conn, 'cnc_entries', 'date_time', 'cutting_roll_quantity', $dateFrom, $dateTo),
        'sewing_entry' => getTotalQuantity($conn, $sewingTable, 'date_time', 'sewing_qty', $dateFrom, $dateTo),
        'branding_entry' => getTotalQuantity($conn, 'branding_entries', 'date_time', 'print_qty', $dateFrom, $dateTo),
        'ncp_pieces' => getNCPPieces($conn, $dateFrom, $dateTo)
    ];
}

function getEntryCounts($conn, $tableName, $dateColumn, $dateFrom, $dateTo) {
    $tableCheck = $conn->query("SHOW TABLES LIKE '$tableName'");
    if (!$tableCheck || $tableCheck->num_rows == 0) {
        return 0;
    }
    
    $colCheck = $conn->query("SHOW COLUMNS FROM $tableName LIKE '$dateColumn'");
    if (!$colCheck || $colCheck->num_rows == 0) {
        $colCheck2 = $conn->query("SHOW COLUMNS FROM $tableName LIKE 'created_at'");
        if ($colCheck2 && $colCheck2->num_rows > 0) {
            $dateColumn = 'created_at';
        } else {
            return 0;
        }
    }
    
    $query = "SELECT COUNT(*) as entry_count FROM $tableName WHERE DATE($dateColumn) BETWEEN ? AND ?";
    
    $colCheck = $conn->query("SHOW COLUMNS FROM $tableName LIKE 'is_deleted'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $query .= " AND (is_deleted = 0 OR is_deleted IS NULL)";
    }
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param('ss', $dateFrom, $dateTo);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return (int)($row['entry_count'] ?? 0);
    }
    return 0;
}

// Get all stats
$todayStats = getSewingStats($conn, $today, $today);
$weekStats = getSewingStats($conn, $weekStart, $today);
$monthStats = getSewingStats($conn, $monthStart, $today);
$yearStats = getSewingStats($conn, $yearStart, $today);

$todayComprehensive = getComprehensiveStats($conn, $today, $today);
$monthComprehensive = getComprehensiveStats($conn, $monthStart, $today);
$yearComprehensive = getComprehensiveStats($conn, $yearStart, $today);

// Daily production trend (last 7 days)
$tableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
$tableName = ($tableCheck && $tableCheck->num_rows > 0) ? 'sewing_machine_entry' : 'swing_machine_entry';

$dailyTrendQuery = "SELECT 
    DATE(date_time) as production_date,
    COUNT(*) as entry_count,
    COALESCE(SUM(sewing_qty), 0) as total_qty
FROM $tableName
WHERE DATE(date_time) >= DATE_SUB(?, INTERVAL 6 DAY)
GROUP BY DATE(date_time)
ORDER BY production_date ASC";

$stmt = $conn->prepare($dailyTrendQuery);
$dailyTrend = [];
if ($stmt) {
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $dailyTrend[] = $row;
    }
    $stmt->close();
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'stats' => [
        'today' => $todayStats,
        'week' => $weekStats,
        'month' => $monthStats,
        'year' => $yearStats
    ],
    'comprehensive' => [
        'today' => $todayComprehensive,
        'month' => $monthComprehensive,
        'year' => $yearComprehensive
    ],
    'dailyTrend' => $dailyTrend
]);

$conn->close();
?>
