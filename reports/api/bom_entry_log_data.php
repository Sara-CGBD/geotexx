<?php
session_start();
require_once '../../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'management', 'planning_user', 'finance_user'];
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
    $productId = $_GET['product_id'] ?? '';
    
    $query = "SELECT 
        b.*,
        COALESCE(fg.product_name, p.project_name, 'N/A') as product_name,
        COALESCE(m.material_name, 'N/A') as material_name,
        COALESCE(nu.full_name, nu.username, u.username, b.who_did, 'Unknown') as creator_name
    FROM bom b
    LEFT JOIN fg ON b.product_id = fg.id
    LEFT JOIN projects p ON b.product_id = p.id
    LEFT JOIN materials m ON b.material_id = m.id
    LEFT JOIN new_user nu ON b.created_by = nu.id
    LEFT JOIN users u ON b.created_by = u.id
    WHERE b.is_deleted = 0";
    
    $params = [];
    $types = '';
    
    if ($dateFrom) {
        $query .= " AND DATE(b.created_at) >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }
    if ($dateTo) {
        $query .= " AND DATE(b.created_at) <= ?";
        $params[] = $dateTo;
        $types .= 's';
    }
    if ($productId) {
        $query .= " AND b.product_id = ?";
        $params[] = $productId;
        $types .= 'i';
    }
    
    $query .= " ORDER BY b.created_at DESC";
    
    $stmt = $conn->prepare($query);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $bomEntries = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    $totalEntries = count($bomEntries);
    $totalCost = array_sum(array_column($bomEntries, 'cost'));
    
    $response = [
        'success' => true,
        'data' => $bomEntries,
        'summary' => [
            'total_entries' => $totalEntries,
            'total_cost' => number_format($totalCost, 2)
        ]
    ];
    
    echo json_encode($response);
    $conn->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>


