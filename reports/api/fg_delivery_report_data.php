<?php
session_start();
require_once '../../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
// Normalize AGM Operations variations
if ($user_role === 'agm operations' || $user_role === 'agm_ops' || $user_role === 'agm_operations') {
    $user_role = 'agm ops';
}
$allowed_roles = ['admin', 'management', 'agm ops', 'finance_user'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit();
}

header('Content-Type: application/json');

try {
    date_default_timezone_set('Asia/Dhaka');
    $conn = SecurityConfig::getConnection();
    
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $clientId = $_GET['client_id'] ?? '';
    
    $query = "SELECT 
        d.*,
        COALESCE(c.client_name, c.name, '') as client_name,
        COALESCE(c.phone, '') as client_phone,
        fg.product_name as fg_reference,
        fg.batch_number as bag_size,
        fg.received_qty as actual_weight,
        'Standard' as packaging_type,
        p.project_name,
        COALESCE(
            (SELECT full_name FROM new_user WHERE id = d.reporter_id LIMIT 1),
            (SELECT username FROM new_user WHERE id = d.reporter_id LIMIT 1),
            (SELECT username FROM users WHERE id = d.reporter_id LIMIT 1),
            d.reporter_name,
            'Unknown'
        ) as reporter_full_name,
        '' as reporter_username
    FROM fg_delivery d
    LEFT JOIN clients c ON d.client_id = c.id
    LEFT JOIN fg ON d.fg_id = fg.id
    LEFT JOIN projects p ON fg.project_id = p.id
    WHERE 1=1";
    
    $params = [];
    $types = '';
    
    if ($dateFrom) {
        $query .= " AND DATE(d.date_time) >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }
    if ($dateTo) {
        $query .= " AND DATE(d.date_time) <= ?";
        $params[] = $dateTo;
        $types .= 's';
    }
    if ($clientId) {
        $query .= " AND d.client_id = ?";
        $params[] = $clientId;
        $types .= 'i';
    }
    
    $query .= " ORDER BY d.date_time DESC";
    
    $stmt = $conn->prepare($query);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $deliveries = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $totalDeliveries = count($deliveries);
    $totalQuantity = array_sum(array_column($deliveries, 'delivery_qty'));
    
    // Group by client
    $byClient = [];
    foreach ($deliveries as $delivery) {
        $client = $delivery['client_name'] ?? 'Unknown';
        if (!isset($byClient[$client])) {
            $byClient[$client] = ['count' => 0, 'qty' => 0];
        }
        $byClient[$client]['count']++;
        $byClient[$client]['qty'] += $delivery['delivery_qty'];
    }
    
    // Get filter options
    $clients = $conn->query("SELECT id, client_name FROM clients ORDER BY client_name")->fetch_all(MYSQLI_ASSOC);
    
    $response = [
        'success' => true,
        'data' => $deliveries,
        'summary' => [
            'total_deliveries' => $totalDeliveries,
            'total_quantity' => number_format($totalQuantity, 2)
        ],
        'by_client' => $byClient,
        'filter_options' => [
            'clients' => $clients
        ]
    ];
    
    echo json_encode($response);
    $conn->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>


