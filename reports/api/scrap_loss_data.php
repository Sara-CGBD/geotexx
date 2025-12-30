<?php
session_start();
require_once '../../config/security_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Check role-based access
$user_role = strtolower($_SESSION['role'] ?? '');
$allowed_roles = ['admin', 'finance', 'agm ops'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Check if user has full access (Finance and Admin only)
$has_full_access = in_array($user_role, ['admin', 'finance']);

header('Content-Type: application/json');
date_default_timezone_set('Asia/Dhaka');

try {
    // Database connection
    $conn = SecurityConfig::getConnection();
    if (!$conn) {
        throw new Exception('Database connection failed');
    }

    // Get filters
    $start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-3 months'));
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $scrap_type_filter = $_GET['scrap_type'] ?? '';

    // Query scrap data with simplified formula
    // Formula: Gross Loss Value (৳) = Scrap Qty (kg) × Rate per kg
    // Formula: Net Loss Qty (kg) = Scrap Qty – Recycled Qty
    // Formula: Net Loss Value (৳) = Net Loss Qty × Rate per kg
    $query = "
        SELECT 
            DATE(s.date_time) as scrap_date,
            s.scrap_type,
            s.scrap_product,
            COALESCE(SUM(s.qty), 0) as scrap_qty,
            COALESCE((SELECT SUM(recycled_qty) FROM scrap_recycle sr WHERE sr.scrap_id = s.id), 0) as recycled_qty,
            COALESCE(stc.cost_per_kg, 50.00) as cost_per_kg,
            
            -- Gross Loss Value (৳) = Scrap Qty (kg) × Rate per kg
            COALESCE(SUM(s.qty), 0) * COALESCE(stc.cost_per_kg, 50.00) as gross_loss_value,
            
            -- Net Loss Qty (kg) = Scrap Qty – Recycled Qty
            COALESCE(SUM(s.qty), 0) - COALESCE((SELECT SUM(recycled_qty) FROM scrap_recycle sr WHERE sr.scrap_id = s.id), 0) as net_loss_qty,
            
            -- Net Loss Value (৳) = Net Loss Qty × Rate per kg
            (COALESCE(SUM(s.qty), 0) - COALESCE((SELECT SUM(recycled_qty) FROM scrap_recycle sr WHERE sr.scrap_id = s.id), 0)) * COALESCE(stc.cost_per_kg, 50.00) as net_loss_value
            
        FROM scrap s
        LEFT JOIN scrap_type_costs stc ON s.scrap_type = stc.scrap_type AND s.scrap_product = stc.scrap_product
        WHERE s.is_deleted = 0 AND DATE(s.date_time) BETWEEN ? AND ?
    ";

    $params = [$start_date, $end_date];
    $types = 'ss';

    if (!empty($scrap_type_filter)) {
        $query .= " AND s.scrap_type = ?";
        $params[] = $scrap_type_filter;
        $types .= "s";
    }

    $query .= " GROUP BY DATE(s.date_time), s.scrap_type, s.scrap_product, stc.cost_per_kg ORDER BY scrap_date DESC";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Failed to prepare statement: ' . $conn->error);
    }
    
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $scrap_data = [];
    $total_scrap_qty = 0;
    $total_recycled_qty = 0;
    $total_net_loss_qty = 0;
    $total_gross_loss_value = 0;
    $total_net_loss_value = 0;

    // For cumulative chart
    $cumulative_data = [];
    $cumulative = 0;

    while ($row = $result->fetch_assoc()) {
        $scrap_data[] = $row;
        $total_scrap_qty += $row['scrap_qty'];
        $total_recycled_qty += $row['recycled_qty'];
        $total_net_loss_qty += $row['net_loss_qty'];
        $total_gross_loss_value += $row['gross_loss_value'];
        $total_net_loss_value += $row['net_loss_value'];
        
        // Accumulate for chart
        $cumulative += $row['net_loss_value'];
        $cumulative_data[] = [
            'date' => $row['scrap_date'],
            'cumulative' => $cumulative
        ];
    }

    // Return JSON response
    echo json_encode([
        'success' => true,
        'data' => $scrap_data,
        'summary' => [
            'total_scrap_qty' => number_format($total_scrap_qty, 2),
            'total_recycled_qty' => number_format($total_recycled_qty, 2),
            'total_net_loss_qty' => number_format($total_net_loss_qty, 2),
            'total_gross_loss_value' => number_format($total_gross_loss_value, 2),
            'total_net_loss_value' => number_format($total_net_loss_value, 2)
        ],
        'cumulative_data' => $cumulative_data,
        'has_full_access' => $has_full_access
    ]);

    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load data: ' . $e->getMessage()
    ]);
}
?>

