<?php
session_start();
require_once '../../config/config.php';

// Security check
if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

try {
    // Get filters
    $start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-3 months'));
    $end_date = $_GET['end_date'] ?? date('Y-m-d');

    // Query profitability data
    $profitability_data = [];
    
    $query = "
        SELECT 
            fg.id,
            fg.product_name as name,
            COALESCE(SUM(CASE WHEN DATE(fgd.date_time) BETWEEN ? AND ? THEN fgd.delivery_qty ELSE 0 END), 0) * COALESCE(fg.unit_price, 0) as revenue,
            COALESCE(SUM(CASE WHEN DATE(b.created_at) BETWEEN ? AND ? THEN b.cost ELSE 0 END), 0) as material_cost,
            COALESCE(SUM(CASE WHEN DATE(fgd.date_time) BETWEEN ? AND ? THEN fgd.delivery_qty ELSE 0 END), 0) * 30 as production_cost,
            (COALESCE(SUM(CASE WHEN DATE(fgd.date_time) BETWEEN ? AND ? THEN fgd.delivery_qty ELSE 0 END), 0) * COALESCE(fg.unit_price, 0)) - COALESCE(SUM(CASE WHEN DATE(b.created_at) BETWEEN ? AND ? THEN b.cost ELSE 0 END), 0) - (COALESCE(SUM(CASE WHEN DATE(fgd.date_time) BETWEEN ? AND ? THEN fgd.delivery_qty ELSE 0 END), 0) * 30) as profit,
            CASE 
                WHEN COALESCE(SUM(CASE WHEN DATE(fgd.date_time) BETWEEN ? AND ? THEN fgd.delivery_qty ELSE 0 END), 0) * COALESCE(fg.unit_price, 0) > 0 THEN
                    (((COALESCE(SUM(CASE WHEN DATE(fgd.date_time) BETWEEN ? AND ? THEN fgd.delivery_qty ELSE 0 END), 0) * COALESCE(fg.unit_price, 0)) - COALESCE(SUM(CASE WHEN DATE(b.created_at) BETWEEN ? AND ? THEN b.cost ELSE 0 END), 0) - (COALESCE(SUM(CASE WHEN DATE(fgd.date_time) BETWEEN ? AND ? THEN fgd.delivery_qty ELSE 0 END), 0) * 30)) / (COALESCE(SUM(CASE WHEN DATE(fgd.date_time) BETWEEN ? AND ? THEN fgd.delivery_qty ELSE 0 END), 0) * COALESCE(fg.unit_price, 0))) * 100
                ELSE 0
            END as profit_margin,
            COUNT(DISTINCT CASE WHEN DATE(b.created_at) BETWEEN ? AND ? THEN b.id ELSE NULL END) as bom_entries,
            COALESCE(SUM(CASE WHEN DATE(fgd.date_time) BETWEEN ? AND ? THEN fgd.delivery_qty ELSE 0 END), 0) as total_qty,
            COALESCE(fg.unit_price, 0) as unit_price
        FROM fg
        LEFT JOIN fg_delivery fgd ON fg.id = fgd.fg_id
        LEFT JOIN bom b ON fg.id = b.product_id AND b.is_deleted = 0
        GROUP BY fg.id, fg.product_name, fg.unit_price
        HAVING revenue > 0 OR material_cost > 0 OR production_cost > 0
        ORDER BY profit DESC
    ";
    
    $stmt = $conn->prepare($query);
    // Bind all 26 date parameters (13 pairs of start_date, end_date)
    $stmt->bind_param('ssssssssssssssssssssssssss', 
        $start_date, $end_date,  // 1. revenue - fgd.date_time (delivery date)
        $start_date, $end_date,  // 2. material_cost - b.created_at
        $start_date, $end_date,  // 3. production_cost - fgd.date_time
        $start_date, $end_date,  // 4. profit - fgd.date_time (first)
        $start_date, $end_date,  // 5. profit - b.created_at
        $start_date, $end_date,  // 6. profit - fgd.date_time (second)
        $start_date, $end_date,  // 7. profit_margin - fgd.date_time (WHEN condition)
        $start_date, $end_date,  // 8. profit_margin - fgd.date_time (numerator first)
        $start_date, $end_date,  // 9. profit_margin - b.created_at (numerator)
        $start_date, $end_date,  // 10. profit_margin - fgd.date_time (numerator second)
        $start_date, $end_date,  // 11. profit_margin - fgd.date_time (denominator)
        $start_date, $end_date,  // 12. bom_entries - b.created_at
        $start_date, $end_date   // 13. total_qty - fgd.date_time
    );
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $profitability_data[] = $row;
    }
    
    $stmt->close();

    // Calculate totals
    $total_revenue = 0;
    $total_material_cost = 0;
    $total_production_cost = 0;
    $total_profit = 0;

    foreach ($profitability_data as $row) {
        $total_revenue += $row['revenue'];
        $total_material_cost += $row['material_cost'];
        $total_production_cost += $row['production_cost'];
        $total_profit += $row['profit'];
    }

    $overall_margin = $total_revenue > 0 ? ($total_profit / $total_revenue) * 100 : 0;

    // Prepare response
    $response = [
        'success' => true,
        'data' => $profitability_data,
        'summary' => [
            'total_revenue' => number_format($total_revenue, 2),
            'total_material_cost' => number_format($total_material_cost, 2),
            'total_production_cost' => number_format($total_production_cost, 2),
            'total_profit' => number_format($total_profit, 2),
            'avg_profit_margin' => number_format($overall_margin, 2)
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

$conn->close();
?>


