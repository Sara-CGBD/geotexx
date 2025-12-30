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
$allowed_roles = ['admin', 'finance'];
$user_role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

date_default_timezone_set('Asia/Dhaka');

// Database connection
$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Get filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$product_filter = $_GET['product'] ?? '';
$production_type_filter = $_GET['production_type'] ?? '';
$shift_filter = $_GET['shift'] ?? '';

// Get production cost settings from database
$cost_query = "SELECT cost_type, cost_per_unit FROM production_cost_settings WHERE cost_type IN ('labor_cost', 'utility_cost', 'overhead_cost')";
$cost_result = $conn->query($cost_query);
$cost_settings = [];
while ($row = $cost_result->fetch_assoc()) {
    $cost_settings[$row['cost_type']] = $row['cost_per_unit'];
}

$labor_cost_per_unit = $cost_settings['labor_cost'] ?? 15;
$utility_cost_per_unit = $cost_settings['utility_cost'] ?? 5;
$overhead_cost_per_unit = $cost_settings['overhead_cost'] ?? 10;

// Build dynamic WHERE clauses for filters
$cnc_where = "DATE(c.date_time) BETWEEN ? AND ?";
$sewing_where = "DATE(s.date_time) BETWEEN ? AND ?";
$branding_where = "DATE(b.date_time) BETWEEN ? AND ?";

// Add product filter
if ($product_filter) {
    $cnc_where .= " AND c.project_id = ?";
    $sewing_where .= " AND s.project_id = ?";
    $branding_where .= " AND b.project_id = ?";
}

// Add shift filter
if ($shift_filter) {
    $cnc_where .= " AND c.shift = ?";
    $sewing_where .= " AND s.shift = ?";
}

// Query production data
$query_parts = [];

// CNC query
if (!$production_type_filter || $production_type_filter == 'CNC') {
    $query_parts[] = "
    SELECT 
        'CNC' as production_type,
        DATE(c.date_time) as production_date,
        c.shift,
        COALESCE(p.project_name, 'Unknown') as product_name,
        COALESCE(SUM(c.actual_weight), 0) as quantity,
        COALESCE(SUM(c.actual_weight), 0) * ? as labor_cost,
        COALESCE(SUM(c.actual_weight), 0) * ? as utility_cost,
        COALESCE(SUM(c.actual_weight), 0) * ? as overhead_cost,
        COALESCE(SUM(c.actual_weight), 0) * (? + ? + ?) as total_cost
    FROM cnc_entries c
    LEFT JOIN projects p ON c.project_id = p.id
    WHERE $cnc_where
    GROUP BY DATE(c.date_time), c.shift, p.project_name";
}

// Sewing query
if (!$production_type_filter || $production_type_filter == 'Sewing') {
    $query_parts[] = "
    SELECT 
        'Sewing' as production_type,
        DATE(s.date_time) as production_date,
        s.shift,
        COALESCE(p.project_name, 'Unknown') as product_name,
        COALESCE(SUM(s.sewing_qty), 0) as quantity,
        COALESCE(SUM(s.sewing_qty), 0) * ? as labor_cost,
        COALESCE(SUM(s.sewing_qty), 0) * ? as utility_cost,
        COALESCE(SUM(s.sewing_qty), 0) * ? as overhead_cost,
        COALESCE(SUM(s.sewing_qty), 0) * (? + ? + ?) as total_cost
    FROM swing_machine_entry s
    LEFT JOIN projects p ON s.project_id = p.id
    WHERE $sewing_where
    GROUP BY DATE(s.date_time), s.shift, p.project_name";
}

// Branding query
if (!$production_type_filter || $production_type_filter == 'Branding') {
    $branding_shift_case = "CASE 
            WHEN HOUR(b.date_time) >= 8 AND HOUR(b.date_time) < 20 THEN 'Day'
            ELSE 'Night'
        END";
    
    if ($shift_filter) {
        $branding_where .= " AND $branding_shift_case = ?";
    }
    
    $query_parts[] = "
    SELECT 
        'Branding' as production_type,
        DATE(b.date_time) as production_date,
        $branding_shift_case as shift,
        COALESCE(p.project_name, 'Unknown') as product_name,
        COALESCE(SUM(b.print_qty), 0) as quantity,
        COALESCE(SUM(b.print_qty), 0) * ? as labor_cost,
        COALESCE(SUM(b.print_qty), 0) * ? as utility_cost,
        COALESCE(SUM(b.print_qty), 0) * ? as overhead_cost,
        COALESCE(SUM(b.print_qty), 0) * (? + ? + ?) as total_cost
    FROM branding_entries b
    LEFT JOIN projects p ON b.project_id = p.id
    WHERE $branding_where
    GROUP BY DATE(b.date_time), $branding_shift_case, p.project_name";
}

$query = implode(" UNION ALL ", $query_parts) . " ORDER BY production_date DESC, production_type";

// Build bind parameters dynamically
$bind_params = [];
$bind_types = '';

// For each query part, add the cost parameters and date parameters
foreach ($query_parts as $i => $part) {
    // Add cost parameters (6 doubles for each query)
    $bind_params[] = $labor_cost_per_unit;
    $bind_params[] = $utility_cost_per_unit;
    $bind_params[] = $overhead_cost_per_unit;
    $bind_params[] = $labor_cost_per_unit;
    $bind_params[] = $utility_cost_per_unit;
    $bind_params[] = $overhead_cost_per_unit;
    $bind_types .= 'dddddd';
    
    // Add date parameters
    $bind_params[] = $start_date;
    $bind_params[] = $end_date;
    $bind_types .= 'ss';
    
    // Add product filter if set
    if ($product_filter) {
        $bind_params[] = $product_filter;
        $bind_types .= 'i';
    }
    
    // Add shift filter if set
    if ($shift_filter) {
        $bind_params[] = $shift_filter;
        $bind_types .= 's';
    }
}

$stmt = $conn->prepare($query);
if (!empty($bind_params)) {
    $stmt->bind_param($bind_types, ...$bind_params);
}
$stmt->execute();
$result = $stmt->get_result();
$production_data = [];
$total_labor = 0;
$total_utility = 0;
$total_overhead = 0;
$total_cost = 0;

while ($row = $result->fetch_assoc()) {
    $production_data[] = $row;
    $total_labor += $row['labor_cost'];
    $total_utility += $row['utility_cost'];
    $total_overhead += $row['overhead_cost'];
    $total_cost += $row['total_cost'];
}

$stmt->close();
$conn->close();

// Return JSON response
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'data' => $production_data,
    'summary' => [
        'total_cost' => $total_cost,
        'total_labor' => $total_labor,
        'total_utility' => $total_utility,
        'total_overhead' => $total_overhead
    ]
]);
?>



