<?php
session_start();
require_once '../../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'production_user', 'management', 'agm ops', 'recycle'];
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
    $operator = $_GET['operator'] ?? '';
    
    // Get recycle efficiency data
    $query = "SELECT 
        sr.recycle_id as batch_id,
        DATE(sr.recycled_at) as recycle_date,
        sr.recycled_at,
        s.qty as input_scrap,
        sr.recycled_qty as output_material,
        (sr.recycled_qty / s.qty * 100) as efficiency,
        s.scrap_type,
        s.scrap_product,
        s.scrap_id,
        COALESCE(
            (SELECT full_name FROM new_user WHERE id = sr.user_id LIMIT 1),
            (SELECT username FROM new_user WHERE id = sr.user_id LIMIT 1),
            (SELECT username FROM users WHERE id = sr.user_id LIMIT 1),
            'Unknown'
        ) as operator_name,
        sr.machine_id
    FROM scrap_recycle sr
    LEFT JOIN scrap s ON sr.scrap_id = s.id
    WHERE DATE(sr.recycled_at) BETWEEN ? AND ?";
    
    $params = [$dateFrom, $dateTo];
    $types = 'ss';
    
    if ($operator) {
        $query .= " AND sr.user_id = ?";
        $params[] = $operator;
        $types .= 'i';
    }
    
    $query .= " ORDER BY sr.recycled_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $recycleData = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Calculate statistics
    $totalBatches = count($recycleData);
    $totalInput = array_sum(array_column($recycleData, 'input_scrap'));
    $totalOutput = array_sum(array_column($recycleData, 'output_material'));
    $avgEfficiency = $totalInput > 0 ? ($totalOutput / $totalInput) * 100 : 0;
    
    // Get max efficiency
    $maxEfficiency = 0;
    $bestBatch = '';
    foreach ($recycleData as $record) {
        if ($record['efficiency'] > $maxEfficiency) {
            $maxEfficiency = $record['efficiency'];
            $bestBatch = $record['batch_id'];
        }
    }
    
    // Group by operator
    $byOperator = [];
    foreach ($recycleData as $record) {
        $op = $record['operator_name'];
        if (!isset($byOperator[$op])) {
            $byOperator[$op] = ['input' => 0, 'output' => 0, 'count' => 0];
        }
        $byOperator[$op]['input'] += $record['input_scrap'];
        $byOperator[$op]['output'] += $record['output_material'];
        $byOperator[$op]['count']++;
    }
    
    // Get operators list
    $operators = $conn->query("
        SELECT DISTINCT sr.user_id as id, 
        COALESCE(
            (SELECT full_name FROM new_user WHERE id = sr.user_id LIMIT 1),
            (SELECT username FROM new_user WHERE id = sr.user_id LIMIT 1),
            (SELECT username FROM users WHERE id = sr.user_id LIMIT 1),
            'Unknown'
        ) as name
        FROM scrap_recycle sr
        ORDER BY name
    ")->fetch_all(MYSQLI_ASSOC);
    
    $response = [
        'success' => true,
        'data' => $recycleData,
        'summary' => [
            'total_batches' => $totalBatches,
            'total_input' => number_format($totalInput, 2),
            'total_output' => number_format($totalOutput, 2),
            'avg_efficiency' => number_format($avgEfficiency, 2),
            'max_efficiency' => number_format($maxEfficiency, 2),
            'best_batch' => $bestBatch
        ],
        'by_operator' => $byOperator,
        'operators' => $operators
    ];
    
    echo json_encode($response);
    $conn->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>


