<?php
session_start();
require_once '../../forms/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$allowed_roles = ['admin', 'management', 'planning_user', 'agm ops', 'agm operations', 'production_user'];
$user_role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit();
}

header('Content-Type: application/json');

try {
    date_default_timezone_set('Asia/Dhaka');
    $conn = new mysqli("127.0.0.1", "root", "root123", "geobagg", 3307);
    
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }
    
    $material_filter = $_GET['material'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    
    $query = "
        SELECT 
            m.id,
            m.material_name,
            COALESCE(SUM(b.unit_price), 0) as total_required_value,
            COUNT(DISTINCT CASE WHEN b.id IS NOT NULL THEN b.product_id END) as products_using,
            COALESCE(
                (SELECT COALESCE(SUM(fe.amount), 0)
                 FROM fiber_entries fe 
                 WHERE LOWER(fe.material_type) = LOWER(m.material_name)
                 AND fe.is_deleted = 0), 
                0
            ) as available_stock,
            (
                COALESCE(
                    (SELECT COALESCE(SUM(ftre.total_weight), 0)
                     FROM fiber_to_roll_entry ftre 
                     WHERE LOWER(ftre.fiber_type) = LOWER(m.material_name)), 
                    0
                ) + 
                COALESCE(
                    (SELECT COALESCE(SUM(mc.quantity), 0)
                     FROM material_consumption mc 
                     WHERE mc.material_id = m.id
                     AND mc.is_deleted = 0), 
                    0
                )
            ) as used_stock,
            COALESCE(SUM(b.cost), 0) as total_bom_cost,
            COUNT(CASE WHEN b.id IS NOT NULL THEN b.id END) as bom_entries
        FROM materials m
        LEFT JOIN bom b ON m.id = b.material_id AND b.is_deleted = 0
        WHERE m.is_deleted = 0";
    
    $params = [];
    $types = '';
    
    if (!empty($material_filter)) {
        $query .= " AND m.id = ?";
        $params[] = $material_filter;
        $types .= 'i';
    }
    
    $query .= " GROUP BY m.id, m.material_name";
    
    $stmt = $conn->prepare($query);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $materials_data = [];
    while ($row = $result->fetch_assoc()) {
        $net_available = $row['available_stock'] - $row['used_stock'];
        $total_required = $row['total_bom_cost'];
        $difference = $net_available - $total_required;
        
        if ($total_required > 0) {
            if ($net_available >= $total_required) {
                $status = 'OK';
            } elseif ($net_available >= ($total_required * 0.5)) {
                $status = 'Low Stock';
            } else {
                $status = 'Shortage';
            }
        } else {
            $status = 'OK';
        }
        
        $row['net_available'] = $net_available;
        $row['total_required'] = $total_required;
        $row['difference'] = $difference;
        $row['status'] = $status;
        
        if (empty($status_filter) || $status === $status_filter) {
            $materials_data[] = $row;
        }
    }
    
    $stmt->close();
    
    $total_materials = count($materials_data);
    $shortage_count = count(array_filter($materials_data, fn($m) => $m['status'] === 'Shortage'));
    $low_stock_count = count(array_filter($materials_data, fn($m) => $m['status'] === 'Low Stock'));
    $ok_count = count(array_filter($materials_data, fn($m) => $m['status'] === 'OK'));
    
    $response = [
        'success' => true,
        'data' => $materials_data,
        'summary' => [
            'total_materials' => $total_materials,
            'shortage_count' => $shortage_count,
            'low_stock_count' => $low_stock_count,
            'ok_count' => $ok_count
        ]
    ];
    
    echo json_encode($response);
    $conn->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>



