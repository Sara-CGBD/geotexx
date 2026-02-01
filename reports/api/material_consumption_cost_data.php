<?php
session_start();
require_once '../../forms/security_config.php';

// Security/session checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Role-based access control
$allowed_roles = ['admin', 'finance', 'agm ops', 'agm operations'];
$user_role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

date_default_timezone_set('Asia/Dhaka');

// Database connection
$conn = new mysqli("127.0.0.1", "root", "root123", "geobagg", 3307);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Get filters
$start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-3 months'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$material_filter = $_GET['material'] ?? '';
$project_filter = $_GET['project'] ?? '';

// Query to calculate material consumption costs from multiple sources
$query = "
    (SELECT 
        DATE(ftre.date_time) as consumption_date,
        ftre.material_type as material_name,
        m.id as material_id,
        COALESCE(p.project_name, 'Unassigned') as project_name,
        ftre.project_id,
        COALESCE(SUM(ftre.total_weight), 0) as total_consumed,
        COALESCE(m.price_per_unit, 0) as unit_price,
        COALESCE(SUM(ftre.total_weight) * m.price_per_unit, 0) as total_cost,
        COUNT(ftre.id) as transaction_count,
        CASE 
            WHEN HOUR(ftre.date_time) >= 8 AND HOUR(ftre.date_time) < 20 THEN 'Day'
            ELSE 'Night'
        END as shift,
        'Fiber to Roll' as process_type
    FROM fiber_to_roll_entry ftre
    LEFT JOIN materials m ON LOWER(m.material_name) = LOWER(ftre.material_type)
    LEFT JOIN projects p ON ftre.project_id = p.id
    WHERE DATE(ftre.date_time) BETWEEN ? AND ?
    " . (!empty($material_filter) ? " AND m.id = ?" : "") . "
    " . (!empty($project_filter) ? " AND ftre.project_id = ?" : "") . "
    GROUP BY DATE(ftre.date_time), ftre.material_type, m.id, m.price_per_unit, p.project_name, ftre.project_id,
        CASE 
            WHEN HOUR(ftre.date_time) >= 8 AND HOUR(ftre.date_time) < 20 THEN 'Day'
            ELSE 'Night'
        END)
    
    UNION ALL
    
    (SELECT 
        DATE(mc.consumption_date) as consumption_date,
        mc.material_name as material_name,
        m.id as material_id,
        COALESCE(p.project_name, 'Unassigned') as project_name,
        mc.project_id,
        COALESCE(SUM(mc.quantity), 0) as total_consumed,
        COALESCE(m.price_per_unit, 0) as unit_price,
        COALESCE(SUM(mc.quantity) * m.price_per_unit, 0) as total_cost,
        COUNT(mc.id) as transaction_count,
        COALESCE(mc.shift, 'N/A') as shift,
        COALESCE(mc.consumption_type, 'General') as process_type
    FROM material_consumption mc
    LEFT JOIN materials m ON LOWER(TRIM(mc.material_name)) = LOWER(TRIM(m.material_name))
    LEFT JOIN projects p ON mc.project_id = p.id
    WHERE DATE(mc.consumption_date) BETWEEN ? AND ?
    AND mc.is_deleted = 0
    " . (!empty($material_filter) ? " AND m.id = ?" : "") . "
    " . (!empty($project_filter) ? " AND mc.project_id = ?" : "") . "
    GROUP BY DATE(mc.consumption_date), mc.material_name, m.id, m.price_per_unit, p.project_name, mc.project_id, mc.shift, mc.consumption_type)
    
    ORDER BY consumption_date DESC, project_name, total_cost DESC
";

$params = [$start_date, $end_date];
$types = 'ss';

if (!empty($material_filter)) {
    $params[] = $material_filter;
    $types .= "i";
}

if (!empty($project_filter)) {
    $params[] = $project_filter;
    $types .= "i";
}

// Add parameters for second query
$params[] = $start_date;
$params[] = $end_date;
$types .= 'ss';

if (!empty($material_filter)) {
    $params[] = $material_filter;
    $types .= "i";
}

if (!empty($project_filter)) {
    $params[] = $project_filter;
    $types .= "i";
}

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$consumption_data = [];
$total_cost = 0;
$total_consumed = 0;

while ($row = $result->fetch_assoc()) {
    $consumption_data[] = $row;
    $total_cost += $row['total_cost'];
    $total_consumed += $row['total_consumed'];
}

$stmt->close();
$conn->close();

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'data' => $consumption_data,
    'summary' => [
        'total_cost' => $total_cost,
        'total_consumed' => $total_consumed,
        'avg_cost_per_kg' => $total_consumed > 0 ? $total_cost / $total_consumed : 0
    ]
]);
?>



