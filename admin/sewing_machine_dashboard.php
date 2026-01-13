<?php
session_start();
require_once '../config/security_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Check if user has sewing machine role
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['sewing_test', 'admin', 'production_user', 'management', 'agm ops'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied - Sewing Machine Dashboard is only available for Sewing Machine role users.");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Date ranges
$today = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$weekStart = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');
$yearStart = date('Y-01-01');

// Helper function to get stats
function getSewingStats($conn, $dateFrom, $dateTo) {
    // Check which table exists
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

// Helper function to get entry counts for any table
function getEntryCounts($conn, $tableName, $dateColumn, $dateFrom, $dateTo) {
    // Check if table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE '$tableName'");
    if (!$tableCheck || $tableCheck->num_rows == 0) {
        return 0;
    }
    
    // Check if the specified date column exists, if not try alternatives
    $colCheck = $conn->query("SHOW COLUMNS FROM $tableName LIKE '$dateColumn'");
    if (!$colCheck || $colCheck->num_rows == 0) {
        // Try created_at as fallback
        $colCheck2 = $conn->query("SHOW COLUMNS FROM $tableName LIKE 'created_at'");
        if ($colCheck2 && $colCheck2->num_rows > 0) {
            $dateColumn = 'created_at';
        } else {
            return 0; // No date column found
        }
    }
    
    // Handle different date column names
    $query = "SELECT COUNT(*) as entry_count FROM $tableName WHERE DATE($dateColumn) BETWEEN ? AND ?";
    
    // Check if is_deleted column exists and filter out deleted entries
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

// Get entry counts for all modules
function getAllModuleStats($conn, $dateFrom, $dateTo) {
    // Check which sewing table exists (don't double count)
    $sewingTableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
    $sewingTable = ($sewingTableCheck && $sewingTableCheck->num_rows > 0) ? 'sewing_machine_entry' : 'swing_machine_entry';
    
    return [
        'roll_received' => getEntryCounts($conn, 'roll_received', 'reporting_time', $dateFrom, $dateTo),
        'cnc_entries' => getEntryCounts($conn, 'cnc_entries', 'date_time', $dateFrom, $dateTo),
        'sewing' => getEntryCounts($conn, $sewingTable, 'date_time', $dateFrom, $dateTo),
        'branding' => getEntryCounts($conn, 'branding_entries', 'date_time', $dateFrom, $dateTo)
    ];
}

// Get statistics for different periods
$todayStats = getSewingStats($conn, $today, $today);
$yesterdayStats = getSewingStats($conn, $yesterday, $yesterday);
$weekStats = getSewingStats($conn, $weekStart, $today);
$monthStats = getSewingStats($conn, $monthStart, $today);
$yearStats = getSewingStats($conn, $yearStart, $today);

// Helper function to get NCP pieces for a period
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

// Helper function to get total quantity/amount for a table
function getTotalQuantity($conn, $tableName, $dateColumn, $quantityColumn, $dateFrom, $dateTo) {
    $tableCheck = $conn->query("SHOW TABLES LIKE '$tableName'");
    if (!$tableCheck || $tableCheck->num_rows == 0) {
        return 0;
    }
    
    // Check if date column exists
    $dateColCheck = $conn->query("SHOW COLUMNS FROM $tableName LIKE '$dateColumn'");
    if (!$dateColCheck || $dateColCheck->num_rows == 0) {
        $dateColCheck2 = $conn->query("SHOW COLUMNS FROM $tableName LIKE 'created_at'");
        if ($dateColCheck2 && $dateColCheck2->num_rows > 0) {
            $dateColumn = 'created_at';
        } else {
            return 0;
        }
    }
    
    // Check if quantity column exists
    $qtyColCheck = $conn->query("SHOW COLUMNS FROM $tableName LIKE '$quantityColumn'");
    if (!$qtyColCheck || $qtyColCheck->num_rows == 0) {
        return 0; // Column doesn't exist
    }
    
    $query = "SELECT COALESCE(SUM($quantityColumn), 0) as total_qty FROM $tableName WHERE DATE($dateColumn) BETWEEN ? AND ?";
    
    // Check if is_deleted column exists and filter out deleted entries
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

// Get comprehensive stats for Today, This Month, This Year (amounts/quantities)
function getComprehensiveStats($conn, $dateFrom, $dateTo) {
    $sewingTableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
    $sewingTable = ($sewingTableCheck && $sewingTableCheck->num_rows > 0) ? 'sewing_machine_entry' : 'swing_machine_entry';
    
    // Get roll received quantity (check for roll_quantity column)
    $rollQty = getTotalQuantity($conn, 'roll_received', 'reporting_time', 'roll_quantity', $dateFrom, $dateTo);
    
    // If roll_quantity doesn't exist, try to count entries as fallback
    if ($rollQty == 0) {
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

// Get entry counts for all modules by period
$todayEntries = getAllModuleStats($conn, $today, $today);
$weekEntries = getAllModuleStats($conn, $weekStart, $today);
$monthEntries = getAllModuleStats($conn, $monthStart, $today);
$yearEntries = getAllModuleStats($conn, $yearStart, $today);

// Get comprehensive stats
$todayComprehensive = getComprehensiveStats($conn, $today, $today);
$monthComprehensive = getComprehensiveStats($conn, $monthStart, $today);
$yearComprehensive = getComprehensiveStats($conn, $yearStart, $today);

// Line-wise statistics (this month)
// Check which table exists
$tableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
$tableName = ($tableCheck && $tableCheck->num_rows > 0) ? 'sewing_machine_entry' : 'swing_machine_entry';

$lineStatsQuery = "SELECT 
    line_no,
    COUNT(*) as entry_count,
    COALESCE(SUM(sewing_qty), 0) as total_qty,
    COALESCE(SUM(ncp_piece), 0) as total_ncp,
    CASE 
        WHEN HOUR(date_time) >= 8 AND HOUR(date_time) < 20 THEN 'Day'
        ELSE 'Night'
    END as shift_type
FROM $tableName
WHERE DATE(date_time) >= ?
GROUP BY line_no, shift_type
ORDER BY line_no, shift_type";

$stmt = $conn->prepare($lineStatsQuery);
$lineStats = [];
if ($stmt) {
    $stmt->bind_param('s', $monthStart);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $lineStats[] = $row;
    }
    $stmt->close();
}

// Shift-wise statistics (this month)
$shiftStatsQuery = "SELECT 
    CASE 
        WHEN HOUR(date_time) >= 8 AND HOUR(date_time) < 20 THEN 'Day'
        ELSE 'Night'
    END as shift_type,
    COUNT(*) as entry_count,
    COALESCE(SUM(sewing_qty), 0) as total_qty,
    COALESCE(SUM(ncp_piece), 0) as total_ncp
FROM $tableName
WHERE DATE(date_time) >= ?
GROUP BY shift_type
ORDER BY shift_type";

$stmt = $conn->prepare($shiftStatsQuery);
$shiftStats = [];
if ($stmt) {
    $stmt->bind_param('s', $monthStart);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $shiftStats[] = $row;
    }
    $stmt->close();
}

// Daily production trend (last 7 days)
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

// Recent entries (last 10)
$recentQuery = "SELECT 
    s.*,
    p.project_name,
    COALESCE(
        (SELECT full_name FROM new_user WHERE id = s.reporter_id LIMIT 1),
        (SELECT username FROM new_user WHERE id = s.reporter_id LIMIT 1),
        'Unknown'
    ) as reporter_name
FROM $tableName s
LEFT JOIN projects p ON s.project_id = p.id
ORDER BY s.date_time DESC
LIMIT 10";

$recentEntries = [];
$result = $conn->query($recentQuery);
if ($result) {
    $recentEntries = $result->fetch_all(MYSQLI_ASSOC);
}

// Top performing lines (this month)
$topLinesQuery = "SELECT 
    line_no,
    COUNT(*) as entry_count,
    COALESCE(SUM(sewing_qty), 0) as total_qty
FROM $tableName
WHERE DATE(date_time) >= ?
GROUP BY line_no
ORDER BY total_qty DESC
LIMIT 5";

$stmt = $conn->prepare($topLinesQuery);
$topLines = [];
if ($stmt) {
    $stmt->bind_param('s', $monthStart);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $topLines[] = $row;
    }
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sewing Dashboard - Overview</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg: #e8eef6;
            --bg-accent: #f5f8ff;
            --card: #ffffff;
            --card-border: rgba(15,23,42,0.06);
            --muted: #7c8ba1;
            --primary: rgb(39, 49, 78);
            --secondary: rgb(25, 59, 77);
            --danger: #ef476f;
            --warning: #ffb703;
            --success: rgb(17, 65, 58);
            --shadow: 0 20px 50px rgba(15, 23, 42, 0.12);
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: linear-gradient(135deg, 
                #e8eef6 0%, 
                #f0f4f8 25%, 
                #e8eef6 50%, 
                #f5f8ff 75%, 
                #e8eef6 100%);
            background-attachment: fixed;
            min-height: 100vh;
            padding: 32px 20px;
            color: #0f172a;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Enhanced background with subtle patterns matching header colors */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: 
                radial-gradient(circle at 10% 20%, rgba(33, 44, 56, 0.03) 0%, transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(68, 170, 190, 0.04) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(20, 24, 36, 0.02) 0%, transparent 50%),
                radial-gradient(circle at 30% 70%, rgba(68, 170, 190, 0.03) 0%, transparent 35%),
                linear-gradient(135deg, 
                    rgba(33, 44, 56, 0.02) 0%, 
                    transparent 30%, 
                    rgba(68, 170, 190, 0.02) 70%, 
                    transparent 100%);
            pointer-events: none;
            z-index: 0;
            animation: float 25s ease-in-out infinite;
        }
        
        /* Additional subtle overlay for depth */
        body::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                repeating-linear-gradient(45deg, transparent, transparent 2px, rgba(68, 170, 190, 0.01) 2px, rgba(68, 170, 190, 0.01) 4px),
                repeating-linear-gradient(-45deg, transparent, transparent 2px, rgba(33, 44, 56, 0.01) 2px, rgba(33, 44, 56, 0.01) 4px);
            pointer-events: none;
            z-index: 0;
            opacity: 0.3;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) translateX(0px); }
            50% { transform: translateY(-20px) translateX(10px); }
        }
        
        .dashboard-container {
            position: relative;
            z-index: 1;
        }
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            animation: fadeInUp 0.6s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .header {
            background: linear-gradient(135deg, rgb(20, 24, 36) 0%, rgb(33, 44, 56) 50%, rgb(68, 170, 190) 100%);
            border-radius: 24px;
            padding: 32px 40px;
            margin-bottom: 24px;
            color: #f5f7fb;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2), 0 0 0 1px rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(10px);
        }
        
        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .header > * {
            position: relative;
            z-index: 1;
        }
        .header h1 {
            color: #f5f7fb;
            font-size: 2.35rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
            animation: slideInLeft 0.8s ease-out;
        }
        
        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .header h1 i {
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        .header .user-info {
            text-align: right;
            color: rgba(255,255,255,0.9);
        }
        .header .user-info strong {
            color: #ffffff;
            font-size: 1.1rem;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 24px 28px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1), 0 0 0 1px rgba(255, 255, 255, 0.5);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            animation: fadeInUp 0.6s ease-out backwards;
        }
        
        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.2s; }
        .stat-card:nth-child(3) { animation-delay: 0.3s; }
        .stat-card:nth-child(4) { animation-delay: 0.4s; }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
            transition: left 0.5s;
        }
        
        .stat-card:hover::before {
            left: 100%;
        }
        
        .stat-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(255, 255, 255, 0.6);
            border-color: rgba(102, 126, 234, 0.3);
        }
        .stat-card .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .stat-card .stat-title {
            font-size: 0.8rem;
            color: var(--muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .stat-card .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            color: white;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .stat-card:hover .stat-icon {
            transform: rotate(5deg) scale(1.1);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.3);
        }
        .stat-card .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }
        .stat-card .stat-change {
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--muted);
        }
        .stat-card .stat-change.positive { color: var(--success); }
        .stat-card .stat-change.negative { color: var(--danger); }
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        .card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 28px 32px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1), 0 0 0 1px rgba(255, 255, 255, 0.5);
            transition: all 0.3s ease;
            animation: fadeInUp 0.6s ease-out backwards;
        }
        
        .card:hover {
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.12), 0 0 0 1px rgba(255, 255, 255, 0.6);
            transform: translateY(-2px);
        }
        .card-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--card-border);
        }
        .table-container {
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table th {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            padding: 14px 16px;
            text-align: left;
            font-weight: 700;
            color: var(--primary);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            border-bottom: 2px solid rgba(102, 126, 234, 0.2);
            position: sticky;
            top: 0;
            z-index: 10;
        }
        table td {
            padding: 14px 16px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            color: #0f172a;
            transition: all 0.2s ease;
        }
        table tr {
            transition: all 0.2s ease;
        }
        table tr:hover {
            background: linear-gradient(90deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
            transform: scale(1.01);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            transition: all 0.2s ease;
        }
        .badge:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }
        .badge-success { 
            background: linear-gradient(135deg, rgba(17, 65, 58, 0.15) 0%, rgba(17, 65, 58, 0.25) 100%); 
            color: var(--success);
            border: 1px solid rgba(17, 65, 58, 0.3);
        }
        .badge-info { 
            background: linear-gradient(135deg, rgba(68, 170, 190, 0.15) 0%, rgba(68, 170, 190, 0.25) 100%); 
            color: rgb(25, 59, 77);
            border: 1px solid rgba(68, 170, 190, 0.3);
        }
        .badge-warning { 
            background: linear-gradient(135deg, rgba(255, 183, 3, 0.2) 0%, rgba(255, 183, 3, 0.3) 100%); 
            color: #b8860b;
            border: 1px solid rgba(255, 183, 3, 0.4);
        }
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 18px;
            margin-bottom: 20px;
        }
        .report-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 24px 28px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1), 0 0 0 1px rgba(255, 255, 255, 0.5);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            color: inherit;
            display: block;
            position: relative;
            overflow: hidden;
        }
        
        .report-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .report-card:hover::before {
            opacity: 1;
        }
        
        .report-card:hover {
            transform: translateY(-6px) scale(1.02);
            box-shadow: 0 16px 48px rgba(102, 126, 234, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.6);
            border-color: rgba(102, 126, 234, 0.4);
            text-decoration: none;
            color: inherit;
        }
        .report-card .report-icon {
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
            color: white;
            margin-bottom: 16px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
        }
        
        .report-card:hover .report-icon {
            transform: rotate(10deg) scale(1.15);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.3);
        }
        .report-card .report-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 8px;
        }
        .report-card .report-desc {
            font-size: 0.85rem;
            color: var(--muted);
        }
        .chart-container {
            position: relative;
            height: 300px;
            margin-top: 20px;
            background: rgba(255, 255, 255, 0.5);
            border-radius: 12px;
            padding: 16px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        
        #refresh-indicator {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(102, 126, 234, 0.95);
            backdrop-filter: blur(10px);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            box-shadow: 0 4px 16px rgba(102, 126, 234, 0.4);
            z-index: 1000;
            display: none;
            align-items: center;
            gap: 8px;
            animation: slideInRight 0.3s ease-out;
        }
        
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        #refresh-indicator i {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .quick-actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .action-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 14px 28px;
            border-radius: 14px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.4);
            position: relative;
            overflow: hidden;
        }
        
        .action-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }
        
        .action-btn:hover::before {
            width: 300px;
            height: 300px;
        }
        
        .action-btn:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 12px 28px rgba(102, 126, 234, 0.5);
            text-decoration: none;
            color: white;
        }
        
        .action-btn i {
            position: relative;
            z-index: 1;
            transition: transform 0.3s;
        }
        
        .action-btn:hover i {
            transform: rotate(10deg) scale(1.1);
        }
        
        .action-btn span {
            position: relative;
            z-index: 1;
        }
        .icon-blue { 
            background: linear-gradient(135deg, rgb(68, 170, 190) 0%, rgb(25, 59, 77) 100%); 
            box-shadow: 0 8px 20px rgba(68, 170, 190, 0.4);
        }
        .icon-green { 
            background: linear-gradient(135deg, var(--success) 0%, rgb(13, 50, 45) 100%); 
            box-shadow: 0 8px 20px rgba(17, 65, 58, 0.4);
        }
        .icon-purple { 
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%); 
            box-shadow: 0 8px 20px rgba(39, 49, 78, 0.4);
        }
        .icon-orange { 
            background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%); 
            box-shadow: 0 8px 20px rgba(255, 183, 3, 0.4);
        }
        .icon-red { 
            background: linear-gradient(135deg, var(--danger) 0%, #c92a4a 100%); 
            box-shadow: 0 8px 20px rgba(239, 71, 111, 0.4);
        }
        .icon-teal { 
            background: linear-gradient(135deg, rgb(68, 170, 190) 0%, rgb(25, 59, 77) 100%); 
            box-shadow: 0 8px 20px rgba(68, 170, 190, 0.4);
        }
        
        /* Smooth scrollbar */
        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.05);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
        }
        
        /* Loading animation for stat values */
        @keyframes numberCount {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .stat-value, .stat-card .stat-value {
            animation: numberCount 0.5s ease-out;
        }
        
        /* Enhanced card title */
        .card-title i {
            transition: transform 0.3s ease;
        }
        
        .card:hover .card-title i {
            transform: rotate(10deg) scale(1.1);
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <div class="header">
            <h1>
                <i class="fas fa-scissors"></i>
                Sewing Dashboard
            </h1>
            <div class="user-info">
                <div><strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong></div>
                <div style="font-size: 12px;"><?php echo date('l, F j, Y'); ?></div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-title">
                <i class="fas fa-bolt"></i>
                Quick Actions
            </div>
            <div class="quick-actions">
                <a href="../reports/production_summary.php" class="action-btn">
                    <i class="fas fa-chart-bar"></i>
                    Production Summary
                </a>
                <a href="../reports/cnc_cutting_summary.php" class="action-btn">
                    <i class="fas fa-cut"></i>
                    CNC Cutting Summary
                </a>
                <a href="../reports/sewing_output_report.php" class="action-btn">
                    <i class="fas fa-scissors"></i>
                    Sewing Report
                </a>
                <a href="../reports/branding_summary_report.php" class="action-btn">
                    <i class="fas fa-stamp"></i>
                    Branding Summary
                </a>
            </div>
        </div>

        <!-- Comprehensive Summary Cards -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 28px;">
            <!-- Today's Summary Card -->
            <div class="card">
                <div class="card-title">
                    <i class="fas fa-calendar-day"></i>
                    Today's Summary
                </div>
                <div style="display: grid; gap: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; background: linear-gradient(135deg, rgba(52, 152, 219, 0.08) 0%, rgba(52, 152, 219, 0.15) 100%); border-radius: 12px; border: 1px solid rgba(52, 152, 219, 0.2); transition: all 0.3s ease;" onmouseover="this.style.transform='scale(1.02)'; this.style.boxShadow='0 4px 12px rgba(52, 152, 219, 0.2)'" onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='none'">
                        <span style="font-weight: 600; color: var(--primary); display: flex; align-items: center;"><i class="fas fa-clipboard-check" style="margin-right: 10px; color: #3498db; font-size: 1.1rem;"></i>Roll Received:</span>
                        <strong style="font-size: 1.2rem; color: #3498db; font-weight: 700;" data-metric="roll_received"><?php echo number_format($todayComprehensive['roll_received']); ?> <span style="font-size: 0.85rem; color: var(--muted); font-weight: 500;">rolls</span></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; background: linear-gradient(135deg, rgba(230, 126, 34, 0.08) 0%, rgba(230, 126, 34, 0.15) 100%); border-radius: 12px; border: 1px solid rgba(230, 126, 34, 0.2); transition: all 0.3s ease;" onmouseover="this.style.transform='scale(1.02)'; this.style.boxShadow='0 4px 12px rgba(230, 126, 34, 0.2)'" onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='none'">
                        <span style="font-weight: 600; color: var(--primary); display: flex; align-items: center;"><i class="fas fa-cut" style="margin-right: 10px; color: #e67e22; font-size: 1.1rem;"></i>CNC Cutting:</span>
                        <strong style="font-size: 1.2rem; color: #e67e22; font-weight: 700;" data-metric="cnc_cutting"><?php echo number_format($todayComprehensive['cnc_cutting']); ?> <span style="font-size: 0.85rem; color: var(--muted); font-weight: 500;">rolls</span></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; background: linear-gradient(135deg, rgba(155, 89, 182, 0.08) 0%, rgba(155, 89, 182, 0.15) 100%); border-radius: 12px; border: 1px solid rgba(155, 89, 182, 0.2); transition: all 0.3s ease;" onmouseover="this.style.transform='scale(1.02)'; this.style.boxShadow='0 4px 12px rgba(155, 89, 182, 0.2)'" onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='none'">
                        <span style="font-weight: 600; color: var(--primary); display: flex; align-items: center;"><i class="fas fa-scissors" style="margin-right: 10px; color: #9b59b6; font-size: 1.1rem;"></i>Sewing Entry:</span>
                        <strong style="font-size: 1.2rem; color: #9b59b6; font-weight: 700;" data-metric="sewing_entry"><?php echo number_format($todayComprehensive['sewing_entry']); ?> <span style="font-size: 0.85rem; color: var(--muted); font-weight: 500;">pieces</span></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; background: linear-gradient(135deg, rgba(26, 188, 156, 0.08) 0%, rgba(26, 188, 156, 0.15) 100%); border-radius: 12px; border: 1px solid rgba(26, 188, 156, 0.2); transition: all 0.3s ease;" onmouseover="this.style.transform='scale(1.02)'; this.style.boxShadow='0 4px 12px rgba(26, 188, 156, 0.2)'" onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='none'">
                        <span style="font-weight: 600; color: var(--primary); display: flex; align-items: center;"><i class="fas fa-stamp" style="margin-right: 10px; color: #1abc9c; font-size: 1.1rem;"></i>Branding Entry:</span>
                        <strong style="font-size: 1.2rem; color: #1abc9c; font-weight: 700;" data-metric="branding_entry"><?php echo number_format($todayComprehensive['branding_entry']); ?> <span style="font-size: 0.85rem; color: var(--muted); font-weight: 500;">pieces</span></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 14px 16px; background: linear-gradient(135deg, rgba(255, 193, 7, 0.12) 0%, rgba(255, 193, 7, 0.2) 100%); border-radius: 12px; border: 1px solid rgba(255, 193, 7, 0.4); transition: all 0.3s ease;" onmouseover="this.style.transform='scale(1.02)'; this.style.boxShadow='0 4px 12px rgba(255, 193, 7, 0.3)'" onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='none'">
                        <span style="font-weight: 600; color: #856404; display: flex; align-items: center;"><i class="fas fa-exclamation-triangle" style="margin-right: 10px; color: #ffc107; font-size: 1.1rem;"></i>NCP Pieces:</span>
                        <strong style="font-size: 1.2rem; color: #856404; font-weight: 700;" data-metric="ncp_pieces"><?php echo number_format($todayComprehensive['ncp_pieces']); ?> <span style="font-size: 0.85rem; color: #856404; opacity: 0.8; font-weight: 500;">pieces</span></strong>
                    </div>
                </div>
            </div>

            <!-- This Month's Summary Card -->
            <div class="card" data-period="month">
                <div class="card-title">
                    <i class="fas fa-calendar-alt"></i>
                    This Month's Summary
                </div>
                <div style="display: grid; gap: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f8fafc; border-radius: 8px;">
                        <span style="font-weight: 600; color: var(--primary);"><i class="fas fa-clipboard-check" style="margin-right: 8px; color: #3498db;"></i>Roll Received:</span>
                        <strong style="font-size: 1.1rem; color: var(--primary);" data-metric="roll_received"><?php echo number_format($monthComprehensive['roll_received']); ?> <span style="font-size: 0.85rem; color: var(--muted);">rolls</span></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f8fafc; border-radius: 8px;">
                        <span style="font-weight: 600; color: var(--primary);"><i class="fas fa-cut" style="margin-right: 8px; color: #e67e22;"></i>CNC Cutting:</span>
                        <strong style="font-size: 1.1rem; color: var(--primary);" data-metric="cnc_cutting"><?php echo number_format($monthComprehensive['cnc_cutting']); ?> <span style="font-size: 0.85rem; color: var(--muted);">rolls</span></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f8fafc; border-radius: 8px;">
                        <span style="font-weight: 600; color: var(--primary);"><i class="fas fa-scissors" style="margin-right: 8px; color: #9b59b6;"></i>Sewing Entry:</span>
                        <strong style="font-size: 1.1rem; color: var(--primary);" data-metric="sewing_entry"><?php echo number_format($monthComprehensive['sewing_entry']); ?> <span style="font-size: 0.85rem; color: var(--muted);">pieces</span></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f8fafc; border-radius: 8px;">
                        <span style="font-weight: 600; color: var(--primary);"><i class="fas fa-stamp" style="margin-right: 8px; color: #1abc9c;"></i>Branding Entry:</span>
                        <strong style="font-size: 1.1rem; color: var(--primary);" data-metric="branding_entry"><?php echo number_format($monthComprehensive['branding_entry']); ?> <span style="font-size: 0.85rem; color: var(--muted);">pieces</span></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #fff3cd; border-radius: 8px; border: 1px solid #ffc107;">
                        <span style="font-weight: 600; color: #856404;"><i class="fas fa-exclamation-triangle" style="margin-right: 8px; color: #ffc107;"></i>NCP Pieces:</span>
                        <strong style="font-size: 1.1rem; color: #856404;" data-metric="ncp_pieces"><?php echo number_format($monthComprehensive['ncp_pieces']); ?> <span style="font-size: 0.85rem; color: #856404; opacity: 0.8;">pieces</span></strong>
                    </div>
                </div>
            </div>

            <!-- This Year's Summary Card -->
            <div class="card" data-period="year">
                <div class="card-title">
                    <i class="fas fa-calendar"></i>
                    This Year's Summary
                </div>
                <div style="display: grid; gap: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f8fafc; border-radius: 8px;">
                        <span style="font-weight: 600; color: var(--primary);"><i class="fas fa-clipboard-check" style="margin-right: 8px; color: #3498db;"></i>Roll Received:</span>
                        <strong style="font-size: 1.1rem; color: var(--primary);" data-metric="roll_received"><?php echo number_format($yearComprehensive['roll_received']); ?> <span style="font-size: 0.85rem; color: var(--muted);">rolls</span></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f8fafc; border-radius: 8px;">
                        <span style="font-weight: 600; color: var(--primary);"><i class="fas fa-cut" style="margin-right: 8px; color: #e67e22;"></i>CNC Cutting:</span>
                        <strong style="font-size: 1.1rem; color: var(--primary);" data-metric="cnc_cutting"><?php echo number_format($yearComprehensive['cnc_cutting']); ?> <span style="font-size: 0.85rem; color: var(--muted);">rolls</span></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f8fafc; border-radius: 8px;">
                        <span style="font-weight: 600; color: var(--primary);"><i class="fas fa-scissors" style="margin-right: 8px; color: #9b59b6;"></i>Sewing Entry:</span>
                        <strong style="font-size: 1.1rem; color: var(--primary);" data-metric="sewing_entry"><?php echo number_format($yearComprehensive['sewing_entry']); ?> <span style="font-size: 0.85rem; color: var(--muted);">pieces</span></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f8fafc; border-radius: 8px;">
                        <span style="font-weight: 600; color: var(--primary);"><i class="fas fa-stamp" style="margin-right: 8px; color: #1abc9c;"></i>Branding Entry:</span>
                        <strong style="font-size: 1.1rem; color: var(--primary);" data-metric="branding_entry"><?php echo number_format($yearComprehensive['branding_entry']); ?> <span style="font-size: 0.85rem; color: var(--muted);">pieces</span></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #fff3cd; border-radius: 8px; border: 1px solid #ffc107;">
                        <span style="font-weight: 600; color: #856404;"><i class="fas fa-exclamation-triangle" style="margin-right: 8px; color: #ffc107;"></i>NCP Pieces:</span>
                        <strong style="font-size: 1.1rem; color: #856404;" data-metric="ncp_pieces"><?php echo number_format($yearComprehensive['ncp_pieces']); ?> <span style="font-size: 0.85rem; color: #856404; opacity: 0.8;">pieces</span></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card" data-stat="today">
                <div class="stat-header">
                    <div class="stat-title">Today's Production</div>
                    <div class="stat-icon icon-blue">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                </div>
                <div class="stat-value" data-stat-value><?php echo number_format($todayStats['total_sewing_qty']); ?></div>
                <div class="stat-change">
                    <span>Pieces</span>
                </div>
                <div style="margin-top: 10px; font-size: 0.85rem; color: var(--muted);" data-stat-entries>
                    <?php echo $todayStats['total_entries']; ?> entries
                </div>
            </div>

            <div class="stat-card" data-stat="week">
                <div class="stat-header">
                    <div class="stat-title">This Week</div>
                    <div class="stat-icon icon-green">
                        <i class="fas fa-calendar-week"></i>
                    </div>
                </div>
                <div class="stat-value" data-stat-value><?php echo number_format($weekStats['total_sewing_qty']); ?></div>
                <div class="stat-change">
                    <span>Pieces</span>
                </div>
                <div style="margin-top: 10px; font-size: 0.85rem; color: var(--muted);" data-stat-entries>
                    <?php echo $weekStats['total_entries']; ?> entries
                </div>
            </div>

            <div class="stat-card" data-stat="month">
                <div class="stat-header">
                    <div class="stat-title">This Month</div>
                    <div class="stat-icon icon-purple">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                </div>
                <div class="stat-value" data-stat-value><?php echo number_format($monthStats['total_sewing_qty']); ?></div>
                <div class="stat-change">
                    <span>Pieces</span>
                </div>
                <div style="margin-top: 10px; font-size: 0.85rem; color: var(--muted);" data-stat-entries>
                    <?php echo $monthStats['total_entries']; ?> entries
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Total NCP (This Month)</div>
                    <div class="stat-icon icon-orange">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo number_format($monthStats['total_ncp']); ?></div>
                <div class="stat-change">
                    <span>Pieces</span>
                </div>
                <div style="margin-top: 10px; font-size: 0.85rem; color: var(--muted);">
                    NCP Rate: <?php 
                        $ncpRate = $monthStats['total_sewing_qty'] > 0 
                            ? ($monthStats['total_ncp'] / $monthStats['total_sewing_qty'] * 100) 
                            : 0;
                        echo number_format($ncpRate, 2); 
                    ?>%
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-title">Active Lines</div>
                    <div class="stat-icon icon-teal">
                        <i class="fas fa-layer-group"></i>
                    </div>
                </div>
                <div class="stat-value"><?php echo $monthStats['unique_lines']; ?></div>
                <div class="stat-change">
                    <span>Lines</span>
                </div>
                <div style="margin-top: 10px; font-size: 0.85rem; color: var(--muted);">
                    This month
                </div>
            </div>

            <div class="stat-card" data-stat="year">
                <div class="stat-header">
                    <div class="stat-title">This Year</div>
                    <div class="stat-icon icon-red">
                        <i class="fas fa-calendar"></i>
                    </div>
                </div>
                <div class="stat-value" data-stat-value><?php echo number_format($yearStats['total_sewing_qty']); ?></div>
                <div class="stat-change">
                    <span>Pieces</span>
                </div>
                <div style="margin-top: 10px; font-size: 0.85rem; color: var(--muted);" data-stat-entries>
                    <?php echo $yearStats['total_entries']; ?> entries
                </div>
            </div>
        </div>

        <!-- Entry Counts Overview Section -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-title">
                <i class="fas fa-list-check"></i>
                Entry Counts Overview - All Modules
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Module</th>
                            <th>Today</th>
                            <th>This Week</th>
                            <th>This Month</th>
                            <th>This Year</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <strong><i class="fas fa-clipboard-check" style="color: #3498db; margin-right: 8px;"></i>Roll Received</strong>
                            </td>
                            <td><span class="badge badge-info"><?php echo number_format($todayEntries['roll_received']); ?></span></td>
                            <td><span class="badge badge-info"><?php echo number_format($weekEntries['roll_received']); ?></span></td>
                            <td><span class="badge badge-info"><?php echo number_format($monthEntries['roll_received']); ?></span></td>
                            <td><span class="badge badge-info"><?php echo number_format($yearEntries['roll_received']); ?></span></td>
                        </tr>
                        <tr>
                            <td>
                                <strong><i class="fas fa-cut" style="color: #e67e22; margin-right: 8px;"></i>CNC Machine</strong>
                            </td>
                            <td><span class="badge badge-warning"><?php echo number_format($todayEntries['cnc_entries']); ?></span></td>
                            <td><span class="badge badge-warning"><?php echo number_format($weekEntries['cnc_entries']); ?></span></td>
                            <td><span class="badge badge-warning"><?php echo number_format($monthEntries['cnc_entries']); ?></span></td>
                            <td><span class="badge badge-warning"><?php echo number_format($yearEntries['cnc_entries']); ?></span></td>
                        </tr>
                        <tr>
                            <td>
                                <strong><i class="fas fa-scissors" style="color: #9b59b6; margin-right: 8px;"></i>Sewing Machine</strong>
                            </td>
                            <td><span class="badge badge-success"><?php echo number_format($todayEntries['sewing']); ?></span></td>
                            <td><span class="badge badge-success"><?php echo number_format($weekEntries['sewing']); ?></span></td>
                            <td><span class="badge badge-success"><?php echo number_format($monthEntries['sewing']); ?></span></td>
                            <td><span class="badge badge-success"><?php echo number_format($yearEntries['sewing']); ?></span></td>
                        </tr>
                        <tr>
                            <td>
                                <strong><i class="fas fa-stamp" style="color: #1abc9c; margin-right: 8px;"></i>Branding Entry</strong>
                            </td>
                            <td><span class="badge badge-success"><?php echo number_format($todayEntries['branding']); ?></span></td>
                            <td><span class="badge badge-success"><?php echo number_format($weekEntries['branding']); ?></span></td>
                            <td><span class="badge badge-success"><?php echo number_format($monthEntries['branding']); ?></span></td>
                            <td><span class="badge badge-success"><?php echo number_format($yearEntries['branding']); ?></span></td>
                        </tr>
                        <tr style="background: #f8fafc; font-weight: 700;">
                            <td>
                                <strong><i class="fas fa-calculator" style="color: var(--primary); margin-right: 8px;"></i>Total Entries</strong>
                            </td>
                            <td>
                                <strong style="color: var(--primary);">
                                    <?php echo number_format($todayEntries['roll_received'] + $todayEntries['cnc_entries'] + $todayEntries['sewing'] + $todayEntries['branding']); ?>
                                </strong>
                            </td>
                            <td>
                                <strong style="color: var(--primary);">
                                    <?php echo number_format($weekEntries['roll_received'] + $weekEntries['cnc_entries'] + $weekEntries['sewing'] + $weekEntries['branding']); ?>
                                </strong>
                            </td>
                            <td>
                                <strong style="color: var(--primary);">
                                    <?php echo number_format($monthEntries['roll_received'] + $monthEntries['cnc_entries'] + $monthEntries['sewing'] + $monthEntries['branding']); ?>
                                </strong>
                            </td>
                            <td>
                                <strong style="color: var(--primary);">
                                    <?php echo number_format($yearEntries['roll_received'] + $yearEntries['cnc_entries'] + $yearEntries['sewing'] + $yearEntries['branding']); ?>
                                </strong>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="content-grid">
            <!-- Production Trend Chart -->
            <div class="card">
                <div class="card-title">
                    <i class="fas fa-chart-line"></i>
                    Production Trend (Last 7 Days)
                </div>
                <div class="chart-container">
                    <canvas id="productionChart"></canvas>
                </div>
            </div>

            <!-- Top Performing Lines -->
            <div class="card">
                <div class="card-title">
                    <i class="fas fa-trophy"></i>
                    Top Performing Lines (This Month)
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Line</th>
                                <th>Production</th>
                                <th>Entries</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($topLines)): ?>
                                <tr>
                                    <td colspan="3" style="text-align: center; color: var(--muted);">No data available</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($topLines as $line): ?>
                                    <tr>
                                        <td><strong>Line <?php echo htmlspecialchars($line['line_no']); ?></strong></td>
                                        <td><?php echo number_format($line['total_qty']); ?> pcs</td>
                                        <td><?php echo $line['entry_count']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Shift and Line Statistics -->
        <div class="content-grid">
            <!-- Shift-wise Statistics -->
            <div class="card">
                <div class="card-title">
                    <i class="fas fa-clock"></i>
                    Shift-wise Performance (This Month)
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Shift</th>
                                <th>Production</th>
                                <th>NCP</th>
                                <th>Entries</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($shiftStats)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: var(--muted);">No data available</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($shiftStats as $shift): ?>
                                    <tr>
                                        <td>
                                            <span class="badge <?php echo $shift['shift_type'] == 'Day' ? 'badge-success' : 'badge-info'; ?>">
                                                <?php echo htmlspecialchars($shift['shift_type']); ?>
                                            </span>
                                        </td>
                                        <td><strong><?php echo number_format($shift['total_qty']); ?></strong> pcs</td>
                                        <td><?php echo number_format($shift['total_ncp']); ?> pcs</td>
                                        <td><?php echo $shift['entry_count']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Entries -->
            <div class="card">
                <div class="card-title">
                    <i class="fas fa-history"></i>
                    Recent Entries
                </div>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>Line</th>
                                <th>Production</th>
                                <th>Project</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentEntries)): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: var(--muted);">No recent entries</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentEntries as $entry): ?>
                                    <tr>
                                        <td><?php echo date('M j, H:i', strtotime($entry['date_time'])); ?></td>
                                        <td>Line <?php echo htmlspecialchars($entry['line_no']); ?></td>
                                        <td><strong><?php echo number_format($entry['sewing_qty']); ?></strong> pcs</td>
                                        <td><?php echo htmlspecialchars($entry['project_name'] ?? 'N/A'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Reports Section -->
        <div class="card">
            <div class="card-title">
                <i class="fas fa-file-alt"></i>
                Available Reports
            </div>
            <div class="reports-grid">
                <a href="../reports/sewing_output_report.php" class="report-card">
                    <div class="report-icon icon-blue">
                        <i class="fas fa-scissors"></i>
                    </div>
                    <div class="report-title">Sewing Output Report</div>
                    <div class="report-desc">Detailed sewing production report with filters by date, line, and shift</div>
                </a>

                <a href="../reports/production_summary.php" class="report-card">
                    <div class="report-icon icon-green">
                        <i class="fas fa-chart-bar"></i>
                    </div>
                    <div class="report-title">Production Summary</div>
                    <div class="report-desc">Complete production overview including CNC, Sewing, and Branding</div>
                </a>

                <a href="../reports/production_comparison_report.php" class="report-card">
                    <div class="report-icon icon-purple">
                        <i class="fas fa-chart-area"></i>
                    </div>
                    <div class="report-title">Production Comparison</div>
                    <div class="report-desc">Compare production performance across different periods</div>
                </a>

                <a href="../reports/cnc_cutting_summary.php" class="report-card">
                    <div class="report-icon icon-orange">
                        <i class="fas fa-cut"></i>
                    </div>
                    <div class="report-title">CNC Cutting Summary</div>
                    <div class="report-desc">View CNC cutting operations and batch details</div>
                </a>

                <a href="../reports/branding_summary_report.php" class="report-card">
                    <div class="report-icon icon-teal">
                        <i class="fas fa-stamp"></i>
                    </div>
                    <div class="report-title">Branding Summary</div>
                    <div class="report-desc">Branding operations and printing statistics</div>
                </a>
            </div>
        </div>
    </div>

    <script>
        // Production Trend Chart
        const dailyTrendData = <?php echo json_encode($dailyTrend); ?>;
        const ctx = document.getElementById('productionChart');
        
        if (ctx) {
            window.productionChart = new Chart(ctx.getContext('2d'), {
            type: 'line',
            data: {
                labels: dailyTrendData.map(d => {
                    const date = new Date(d.production_date);
                    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
                }),
                datasets: [{
                    label: 'Production (Pieces)',
                    data: dailyTrendData.map(d => parseInt(d.total_qty)),
                    borderColor: 'rgb(102, 126, 234)',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    pointBackgroundColor: 'rgb(102, 126, 234)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: {
                            font: {
                                family: 'Inter',
                                size: 12,
                                weight: '600'
                            },
                            padding: 15
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        padding: 12,
                        titleFont: {
                            family: 'Inter',
                            size: 13,
                            weight: '600'
                        },
                        bodyFont: {
                            family: 'Inter',
                            size: 12
                        },
                        callbacks: {
                            label: function(context) {
                                return 'Production: ' + context.parsed.y.toLocaleString() + ' pieces';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            font: {
                                family: 'Inter',
                                size: 11
                            },
                            callback: function(value) {
                                return value.toLocaleString();
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        ticks: {
                            font: {
                                family: 'Inter',
                                size: 11
                            }
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
        } else {
            window.productionChart = null;
        }

        // Modern AJAX-based auto-update system
        let autoUpdateInterval = null;
        let isUpdating = false;
        const UPDATE_INTERVAL = 30000; // 30 seconds

        // Function to format numbers
        function formatNumber(num) {
            return new Intl.NumberFormat().format(num);
        }

        // Function to update dashboard data
        async function updateDashboardData() {
            if (isUpdating) return;
            isUpdating = true;

            try {
                const response = await fetch('../admin/api/sewing_dashboard_data.php');
                const data = await response.json();

                if (!data.success) {
                    console.error('Failed to fetch dashboard data');
                    isUpdating = false;
                    return;
                }

                // Update comprehensive stats cards
                updateComprehensiveCard('today', data.comprehensive.today);
                updateComprehensiveCard('month', data.comprehensive.month);
                updateComprehensiveCard('year', data.comprehensive.year);

                // Update statistics cards
                updateStatCard('today', data.stats.today);
                updateStatCard('week', data.stats.week);
                updateStatCard('month', data.stats.month);
                updateStatCard('year', data.stats.year);

                // Update chart if it exists
                if (window.productionChart && data.dailyTrend) {
                    updateChart(data.dailyTrend);
                }

                // Update refresh indicator
                updateRefreshIndicator(data.timestamp);

            } catch (error) {
                console.error('Error updating dashboard:', error);
            } finally {
                isUpdating = false;
            }
        }

        // Update comprehensive card
        function updateComprehensiveCard(period, data) {
            const card = document.querySelector(`[data-period="${period}"]`);
            if (!card) return;

            const updates = {
                'roll_received': data.roll_received,
                'cnc_cutting': data.cnc_cutting,
                'sewing_entry': data.sewing_entry,
                'branding_entry': data.branding_entry,
                'ncp_pieces': data.ncp_pieces
            };

            Object.keys(updates).forEach(key => {
                const element = card.querySelector(`[data-metric="${key}"]`);
                if (element) {
                    const value = formatNumber(updates[key]);
                    const unit = key === 'ncp_pieces' ? 'pieces' : 
                                (key === 'sewing_entry' || key === 'branding_entry') ? 'pieces' : 'rolls';
                    element.innerHTML = `${value} <span style="font-size: 0.85rem; color: var(--muted);">${unit}</span>`;
                }
            });
        }

        // Update stat card
        function updateStatCard(period, stats) {
            const card = document.querySelector(`[data-stat="${period}"]`);
            if (!card) return;

            const valueEl = card.querySelector('[data-stat-value]');
            const entriesEl = card.querySelector('[data-stat-entries]');
            
            if (valueEl) {
                valueEl.textContent = formatNumber(stats.total_sewing_qty);
            }
            if (entriesEl) {
                entriesEl.textContent = `${stats.total_entries} entries`;
            }
        }

        // Update chart
        function updateChart(dailyTrend) {
            const labels = dailyTrend.map(d => {
                const date = new Date(d.production_date);
                return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            });
            const data = dailyTrend.map(d => parseInt(d.total_qty));

            window.productionChart.data.labels = labels;
            window.productionChart.data.datasets[0].data = data;
            window.productionChart.update('none'); // 'none' mode for smooth update
        }

        // Update refresh indicator
        function updateRefreshIndicator(timestamp) {
            const now = new Date();
            const timeString = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit', 
                second: '2-digit' 
            });
            
            let refreshIndicator = document.getElementById('refresh-indicator');
            if (!refreshIndicator) {
                refreshIndicator = document.createElement('div');
                refreshIndicator.id = 'refresh-indicator';
                document.body.appendChild(refreshIndicator);
            }
            
            refreshIndicator.innerHTML = `
                <i class="fas fa-sync-alt"></i>
                <span>Updated: ${timeString}</span>
            `;
            refreshIndicator.style.display = 'flex';
        }

        // Add spin animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            [data-metric], [data-stat-value], [data-stat-entries] {
                transition: opacity 0.3s ease;
            }
            .updating {
                opacity: 0.7;
            }
        `;
        document.head.appendChild(style);

        // Start auto-update
        function startAutoUpdate() {
            // Initial update
            updateDashboardData();
            
            // Update refresh time every second
            setInterval(() => {
                const now = new Date();
                const timeString = now.toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit', 
                    second: '2-digit' 
                });
                const indicator = document.getElementById('refresh-indicator');
                if (indicator) {
                    const icon = indicator.querySelector('i');
                    const span = indicator.querySelector('span');
                    if (span) {
                        span.textContent = `Updated: ${timeString}`;
                    }
                }
            }, 1000);
            
            // Update data every UPDATE_INTERVAL
            autoUpdateInterval = setInterval(updateDashboardData, UPDATE_INTERVAL);
        }

        // Stop auto-update
        function stopAutoUpdate() {
            if (autoUpdateInterval) {
                clearInterval(autoUpdateInterval);
                autoUpdateInterval = null;
            }
        }

        // Start when page loads
        document.addEventListener('DOMContentLoaded', function() {
            startAutoUpdate();
        });

        // Pause when tab is hidden, resume when visible
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                stopAutoUpdate();
            } else {
                startAutoUpdate();
                updateDashboardData(); // Immediate update when tab becomes visible
            }
        });
    </script>
</body>
</html>
