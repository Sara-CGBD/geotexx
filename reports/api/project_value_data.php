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
    
    $projectId = $_GET['project_id'] ?? '';
    
    $query = "SELECT 
        p.*,
        (SELECT COUNT(*) FROM roll_entry WHERE project_id = p.id AND is_deleted = 0) as roll_count,
        (SELECT SUM(total_weight) FROM roll_entry WHERE project_id = p.id AND is_deleted = 0) as total_production,
        (SELECT COUNT(*) FROM fg_entry WHERE project_id = p.id AND is_deleted = 0) as fg_count,
        (SELECT SUM(passed_qty) FROM fg_entry WHERE project_id = p.id AND is_deleted = 0) as fg_produced,
        (SELECT SUM(cost) FROM bom WHERE product_id = p.id AND is_deleted = 0) as total_cost
    FROM projects p
    WHERE p.is_deleted = 0";
    
    if ($projectId) {
        $query .= " AND p.id = ?";
    }
    
    $query .= " ORDER BY p.created_at DESC";
    
    if ($projectId) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $projectId);
        $stmt->execute();
        $result = $stmt->get_result();
        $projects = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        $projects = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
    }
    
    $totalProjects = count($projects);
    $totalProduction = array_sum(array_column($projects, 'total_production'));
    $totalCost = array_sum(array_column($projects, 'total_cost'));
    
    $response = [
        'success' => true,
        'data' => $projects,
        'summary' => [
            'total_projects' => $totalProjects,
            'total_production' => number_format($totalProduction, 2),
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


