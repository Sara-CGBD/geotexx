<?php
session_start();
require_once '../../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'production_user', 'qc_inspector', 'management', 'agm ops'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit();
}

header('Content-Type: application/json');

try {
    date_default_timezone_set('Asia/Dhaka');
    $conn = SecurityConfig::getConnection();
    
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $dateTo = $_GET['date_to'] ?? date('Y-m-d');
    
    // Get scrap data
    $scrapQuery = "SELECT 
        DATE(date_time) as scrap_date,
        scrap_type,
        SUM(qty) as total_scrap_qty
    FROM scrap
    WHERE is_deleted = 0
    AND DATE(date_time) BETWEEN ? AND ?
    GROUP BY DATE(date_time), scrap_type
    ORDER BY scrap_date DESC";
    
    $stmt = $conn->prepare($scrapQuery);
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $scrapData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Get recycle data
    $recycleQuery = "SELECT 
        DATE(sr.recycled_at) as recycle_date,
        s.scrap_type,
        SUM(sr.recycled_qty) as total_recycled_qty
    FROM scrap_recycle sr
    LEFT JOIN scrap s ON sr.scrap_id = s.id
    WHERE DATE(sr.recycled_at) BETWEEN ? AND ?
    GROUP BY DATE(sr.recycled_at), s.scrap_type
    ORDER BY recycle_date DESC";
    
    $stmt = $conn->prepare($recycleQuery);
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $recycleData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Calculate totals
    $totalScrap = array_sum(array_column($scrapData, 'total_scrap_qty'));
    $totalRecycled = array_sum(array_column($recycleData, 'total_recycled_qty'));
    $recycleRate = $totalScrap > 0 ? ($totalRecycled / $totalScrap) * 100 : 0;
    $wasteRate = 100 - $recycleRate;
    
    // Group by type
    $typeComparison = [];
    foreach ($scrapData as $scrap) {
        $type = $scrap['scrap_type'];
        if (!isset($typeComparison[$type])) {
            $typeComparison[$type] = ['scrap' => 0, 'recycled' => 0];
        }
        $typeComparison[$type]['scrap'] += $scrap['total_scrap_qty'];
    }
    foreach ($recycleData as $recycle) {
        $type = $recycle['scrap_type'] ?? 'Unknown';
        if (!isset($typeComparison[$type])) {
            $typeComparison[$type] = ['scrap' => 0, 'recycled' => 0];
        }
        $typeComparison[$type]['recycled'] += $recycle['total_recycled_qty'];
    }
    
    // Daily comparison for chart
    $dailyComparison = [];
    foreach ($scrapData as $scrap) {
        $date = $scrap['scrap_date'];
        if (!isset($dailyComparison[$date])) {
            $dailyComparison[$date] = ['scrap' => 0, 'recycled' => 0];
        }
        $dailyComparison[$date]['scrap'] += $scrap['total_scrap_qty'];
    }
    foreach ($recycleData as $recycle) {
        $date = $recycle['recycle_date'];
        if (!isset($dailyComparison[$date])) {
            $dailyComparison[$date] = ['scrap' => 0, 'recycled' => 0];
        }
        $dailyComparison[$date]['recycled'] += $recycle['total_recycled_qty'];
    }
    ksort($dailyComparison);
    
    $response = [
        'success' => true,
        'summary' => [
            'total_scrap' => number_format($totalScrap, 2),
            'total_recycled' => number_format($totalRecycled, 2),
            'recycle_rate' => number_format($recycleRate, 2),
            'waste_rate' => number_format($wasteRate, 2)
        ],
        'type_comparison' => $typeComparison,
        'daily_comparison' => $dailyComparison
    ];
    
    echo json_encode($response);
    $conn->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>


