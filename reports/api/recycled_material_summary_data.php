<?php
session_start();
require_once '../../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'production_user', 'management', 'agm ops'];
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
    $scrapType = $_GET['scrap_type'] ?? '';
    
    $query = "SELECT 
        sr.*,
        s.scrap_type,
        s.scrap_product,
        s.qty as original_scrap_qty,
        COALESCE(
            (SELECT full_name FROM new_user WHERE id = sr.user_id LIMIT 1),
            (SELECT username FROM new_user WHERE id = sr.user_id LIMIT 1),
            (SELECT username FROM users WHERE id = sr.user_id LIMIT 1),
            'Unknown'
        ) as recycler_name,
        '' as recycler_username
    FROM scrap_recycle sr
    LEFT JOIN scrap s ON sr.scrap_id = s.id
    WHERE DATE(sr.recycled_at) BETWEEN ? AND ?";
    
    $params = [$dateFrom, $dateTo];
    $types = 'ss';
    
    if ($scrapType) {
        $query .= " AND s.scrap_type = ?";
        $params[] = $scrapType;
        $types .= 's';
    }
    
    $query .= " ORDER BY sr.recycled_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $recycleData = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $totalRecycled = array_sum(array_column($recycleData, 'recycled_qty'));
    $totalRecords = count($recycleData);
    
    // Group by scrap type
    $byType = [];
    foreach ($recycleData as $record) {
        $type = $record['scrap_type'] ?? 'Unknown';
        if (!isset($byType[$type])) {
            $byType[$type] = ['qty' => 0, 'count' => 0];
        }
        $byType[$type]['qty'] += $record['recycled_qty'];
        $byType[$type]['count']++;
    }
    
    $response = [
        'success' => true,
        'data' => $recycleData,
        'summary' => [
            'total_recycled' => number_format($totalRecycled, 2),
            'total_records' => $totalRecords
        ],
        'by_type' => $byType
    ];
    
    echo json_encode($response);
    $conn->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>


