<?php
header('Content-Type: application/json');
session_start();
require_once '../../config/security_config.php';

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check role authorization
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'qc_inspector', 'management', 'agm ops', 'tester', 'lab_tester'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

try {
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $dateTo = $_GET['date_to'] ?? date('Y-m-d');
    $stage = $_GET['stage'] ?? '';
    
    // Get QC data
    $query = "SELECT 
        DATE(date_time) as qc_date,
        qc_stage,
        qc_result,
        COUNT(*) as count
    FROM qc_entries
    WHERE DATE(date_time) BETWEEN ? AND ?";
    
    $params = [$dateFrom, $dateTo];
    $types = 'ss';
    
    if ($stage) {
        $query .= " AND qc_stage = ?";
        $params[] = $stage;
        $types .= 's';
    }
    
    $query .= " GROUP BY DATE(date_time), qc_stage, qc_result ORDER BY qc_date DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $qcData = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Process data for charts
    $dailyTrend = [];
    $stageStats = [];
    $totalPass = 0;
    $totalFail = 0;
    
    foreach ($qcData as $row) {
        $date = $row['qc_date'];
        $stageVal = $row['qc_stage'];
        $resultVal = $row['qc_result'];
        $count = $row['count'];
        
        // Daily trend
        if (!isset($dailyTrend[$date])) {
            $dailyTrend[$date] = ['pass' => 0, 'fail' => 0];
        }
        $dailyTrend[$date][$resultVal == 'Pass' ? 'pass' : 'fail'] += $count;
        
        // Stage stats
        if (!isset($stageStats[$stageVal])) {
            $stageStats[$stageVal] = ['pass' => 0, 'fail' => 0];
        }
        $stageStats[$stageVal][$resultVal == 'Pass' ? 'pass' : 'fail'] += $count;
        
        // Totals
        if ($resultVal == 'Pass') {
            $totalPass += $count;
        } else {
            $totalFail += $count;
        }
    }
    
    $totalInspections = $totalPass + $totalFail;
    $passRate = $totalInspections > 0 ? ($totalPass / $totalInspections) * 100 : 0;
    
    // Format stage stats for table
    $stage_table_data = [];
    foreach ($stageStats as $stageName => $data) {
        $stageTotal = $data['pass'] + $data['fail'];
        $stagePassRate = $stageTotal > 0 ? ($data['pass'] / $stageTotal) * 100 : 0;
        
        $stage_table_data[] = [
            'stage' => $stageName,
            'total' => $stageTotal,
            'pass' => $data['pass'],
            'fail' => $data['fail'],
            'pass_rate' => round($stagePassRate, 1)
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'daily_trend' => $dailyTrend,
            'stage_stats' => $stageStats,
            'stage_table' => $stage_table_data,
            'total_inspections' => $totalInspections,
            'total_pass' => $totalPass,
            'total_fail' => $totalFail,
            'pass_rate' => round($passRate, 1)
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>


