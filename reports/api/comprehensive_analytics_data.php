<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); // avoid HTML error output
header('Content-Type: application/json');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

session_start();

try {
    require_once '../../forms/security_config.php';

    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
        exit();
    }

    date_default_timezone_set('Asia/Dhaka');
    $conn = SecurityConfig::getConnection();
} catch (Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => 'Connection error: ' . $e->getMessage()]);
    exit();
}

// Simple schema helper
function apiColExists(mysqli $conn, string $table, string $column): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    $col = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    return $res && $res->num_rows > 0;
}

try {
// Get filter parameters
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$projectFilter = $_GET['project'] ?? '';

// Build WHERE clauses
$dateWhere = "DATE(date_time) BETWEEN ? AND ?";
$params = [$startDate, $endDate];
$types = 'ss';

// Fetch production data from multiple sources
// 1. CNC Entries
$cncParams = [$startDate, $endDate];
$cncTypes = 'ss';
$cncQuery = "SELECT 
    p.project_name,
    p.id as project_id,
    COALESCE(SUM(ce.cutting_roll_quantity), 0) as total_produced
FROM cnc_entries ce
LEFT JOIN projects p ON ce.project_id = p.id
WHERE DATE(ce.date_time) BETWEEN ? AND ?
    AND (p.status = 'active' OR p.status IS NULL)";

if ($projectFilter) {
    $cncQuery .= " AND p.project_name = ?";
    $cncParams[] = $projectFilter;
    $cncTypes .= 's';
}
$cncQuery .= " GROUP BY p.id, p.project_name";

$cncStmt = $conn->prepare($cncQuery);
$cncStmt->bind_param($cncTypes, ...$cncParams);
$cncStmt->execute();
$cncResult = $cncStmt->get_result();
$cncProduction = [];
while ($row = $cncResult->fetch_assoc()) {
    $cncProduction[$row['project_name']] = $row['total_produced'];
}
$cncStmt->close();

// 2. Sewing/Swing Machine Entries
$swingParams = [$startDate, $endDate];
$swingTypes = 'ss';
$swingQuery = "SELECT 
    p.project_name,
    COALESCE(SUM(sme.sewing_qty), 0) as total_produced
FROM swing_machine_entry sme
LEFT JOIN projects p ON sme.project_id = p.id
WHERE DATE(sme.date_time) BETWEEN ? AND ?
    AND (p.status = 'active' OR p.status IS NULL)";

if ($projectFilter) {
    $swingQuery .= " AND p.project_name = ?";
    $swingParams[] = $projectFilter;
    $swingTypes .= 's';
}
$swingQuery .= " GROUP BY p.project_name";

$swingStmt = $conn->prepare($swingQuery);
$swingStmt->bind_param($swingTypes, ...$swingParams);
$swingStmt->execute();
$swingResult = $swingStmt->get_result();
$swingProduction = [];
while ($row = $swingResult->fetch_assoc()) {
    $swingProduction[$row['project_name']] = $row['total_produced'];
}
$swingStmt->close();

// 3. FG Entry (Final production with QC data)
$fgParams = [$startDate, $endDate];
$fgTypes = 'ss';
$prodQuery = "SELECT 
    p.project_name,
    p.id as project_id,
    COALESCE(SUM(fe.passed_qty), 0) as total_produced,
    COALESCE(SUM(fe.passed_qty * " . (apiColExists($conn, 'bag_size_master', 'unit_price') ? "bom.unit_price" : "0") . "), 0) as production_value,
    COALESCE(SUM(fe.quality_checked), 0) as qc_inspected,
    COALESCE(SUM(fe.passed_qty), 0) as qc_pass,
    COALESCE(SUM(fe.rejected_qty), 0) as qc_fail
FROM fg_entry fe
LEFT JOIN projects p ON fe.project_id = p.id
LEFT JOIN bag_size_master bom ON fe.bag_size = bom.bag_size
WHERE DATE(fe.date_time) BETWEEN ? AND ?
    AND (p.status = 'active' OR p.status IS NULL)";

if ($projectFilter) {
    $prodQuery .= " AND p.project_name = ?";
    $fgParams[] = $projectFilter;
    $fgTypes .= 's';
}

$prodQuery .= " GROUP BY p.id, p.project_name ORDER BY p.project_name";

$stmt = $conn->prepare($prodQuery);
$stmt->bind_param($fgTypes, ...$fgParams);
$stmt->execute();
$prodResult = $stmt->get_result();
$productionByProject = [];
while ($row = $prodResult->fetch_assoc()) {
    $projectName = $row['project_name'] ?: 'Unassigned';
    $productionByProject[$projectName] = $row;
}
$stmt->close();

// Fetch delivery data from fg_deliveries
$params2 = [$startDate, $endDate];
$types2 = 'ss';
$delQuery = "SELECT 
    p.project_name,
    COALESCE(SUM(d.delivery_quantity), 0) as total_delivered,
    COALESCE(SUM(d.total_cost), 0) as delivery_value
FROM fg_deliveries d
LEFT JOIN fg_entry fe ON d.fg_entry_id = fe.id
LEFT JOIN projects p ON fe.project_id = p.id
WHERE DATE(d.delivery_date) BETWEEN ? AND ?
    AND (p.status = 'active' OR p.status IS NULL)";

if ($projectFilter) {
    $delQuery .= " AND p.project_name = ?";
    $params2[] = $projectFilter;
    $types2 .= 's';
}

$delQuery .= " GROUP BY p.project_name";

$stmt2 = $conn->prepare($delQuery);
$stmt2->bind_param($types2, ...$params2);
$stmt2->execute();
$delResult = $stmt2->get_result();
$deliveryByProject = [];
while ($row = $delResult->fetch_assoc()) {
    $deliveryByProject[$row['project_name']] = $row;
}
$stmt2->close();

// Fetch scrap data (scrap table may not have project_id)
$scrapByProject = [];
try {
    $params3 = [$startDate, $endDate];
    $types3 = 'ss';
    $scrapQuery = "SELECT 
        'All Scrap' as project_name,
        COALESCE(SUM(s.qty), 0) as total_scrap,
        COALESCE(SUM(s.qty * COALESCE(stc.cost_per_kg, 50)), 0) as scrap_loss
    FROM scrap s
    LEFT JOIN scrap_type_costs stc ON s.scrap_type = stc.scrap_type
    WHERE DATE(s.date_time) BETWEEN ? AND ?";

    $scrapQuery .= " GROUP BY 'All Scrap'";

    $stmt3 = $conn->prepare($scrapQuery);
    $stmt3->bind_param($types3, ...$params3);
    $stmt3->execute();
    $scrapResult = $stmt3->get_result();
    while ($row = $scrapResult->fetch_assoc()) {
        $scrapByProject[$row['project_name']] = $row;
    }
    $stmt3->close();
} catch (Exception $e) {
    error_log('Scrap query error: ' . $e->getMessage());
    $scrapByProject = [];
}

// Fetch CURRENT STOCK (all-time, not date-filtered) from fg_entry
$stockByProject = [];
try {
    $stockParams = [];
    $stockTypes = '';
    $stockQuery = "SELECT 
        COALESCE(p.project_name, 'Unassigned') as project_name,
        COALESCE(SUM(
            CASE 
                WHEN COALESCE(fe.product_type, 'bag') = 'roll' THEN (fe.actual_weight - COALESCE(fe.delivered_quantity, 0))
                ELSE (fe.passed_qty - COALESCE(fe.delivered_quantity, 0))
            END
        ), 0) as total_stock
    FROM fg_entry fe
    LEFT JOIN projects p ON fe.project_id = p.id
    WHERE fe.is_deleted = 0
        AND (p.status = 'active' OR p.status IS NULL)";
    
    if ($projectFilter) {
        $stockQuery .= " AND p.project_name = ?";
        $stockParams[] = $projectFilter;
        $stockTypes .= 's';
    }
    
    $stockQuery .= " GROUP BY p.id, p.project_name";
    
    $stockStmt = $conn->prepare($stockQuery);
    if (!empty($stockTypes)) {
        $stockStmt->bind_param($stockTypes, ...$stockParams);
    }
    $stockStmt->execute();
    $stockResult = $stockStmt->get_result();
    while ($row = $stockResult->fetch_assoc()) {
        $stockByProject[$row['project_name']] = $row['total_stock'];
    }
    $stockStmt->close();
} catch (Exception $e) {
    error_log('Stock query error: ' . $e->getMessage());
    $stockByProject = [];
}

// Get list of active projects to filter out deleted/inactive ones
$activeProjects = [];
try {
    $activeProjectsQuery = "SELECT DISTINCT project_name FROM projects WHERE status = 'active' AND (is_deleted = 0 OR is_deleted IS NULL)";
    $activeResult = $conn->query($activeProjectsQuery);
    if ($activeResult) {
        while ($row = $activeResult->fetch_assoc()) {
            $activeProjects[] = $row['project_name'];
        }
    }
} catch (Exception $e) {
    // If is_deleted column doesn't exist, just use status
    try {
        $activeProjectsQuery = "SELECT DISTINCT project_name FROM projects WHERE status = 'active'";
        $activeResult = $conn->query($activeProjectsQuery);
        if ($activeResult) {
            while ($row = $activeResult->fetch_assoc()) {
                $activeProjects[] = $row['project_name'];
            }
        }
    } catch (Exception $e2) {
        error_log('Active projects query error: ' . $e2->getMessage());
    }
}

// Combine all data by project
$projectReportData = [];
$allProjects = array_unique(array_merge(
    array_keys($productionByProject),
    array_keys($cncProduction),
    array_keys($swingProduction),
    array_keys($deliveryByProject),
    array_keys($scrapByProject),
    array_keys($stockByProject)
));

foreach ($allProjects as $projectName) {
    if (empty($projectName)) $projectName = 'Unassigned';
    
    // Skip deleted/inactive projects (only include active projects and 'Unassigned')
    if ($projectName !== 'Unassigned' && !empty($activeProjects) && !in_array($projectName, $activeProjects)) {
        continue; // Skip this project
    }
    
    $prod = $productionByProject[$projectName] ?? [];
    $del = $deliveryByProject[$projectName] ?? [];
    $scrap = $scrapByProject[$projectName] ?? [];
    
    // Combine production from all sources (FG, CNC, Sewing)
    $fgProduction = $prod['total_produced'] ?? 0;
    $cncProd = $cncProduction[$projectName] ?? 0;
    $swingProd = $swingProduction[$projectName] ?? 0;
    
    // Use FG production as primary (it has QC data), fallback to CNC+Sewing
    $productionQty = $fgProduction > 0 ? $fgProduction : ($cncProd + $swingProd);
    
    $deliveryQty = $del['total_delivered'] ?? 0;
    $qcInspected = $prod['qc_inspected'] ?? 0;
    $qcPass = $prod['qc_pass'] ?? 0;
    $qcFail = $prod['qc_fail'] ?? 0;
    
    // Current stock should be from all-time fg_entry data, not date-filtered production - delivery
    $stock = max(0, (float)($stockByProject[$projectName] ?? 0));
    $productionValue = $prod['production_value'] ?? 0;
    // Calculate stock value based on average unit price if production exists, otherwise use 0
    $stockValue = $productionQty > 0 ? ($stock * ($productionValue / $productionQty)) : 0;
    
    $projectReportData[] = [
        'project_name' => $projectName,
        'production_qty' => (int)$productionQty,
        'production_value_cr' => $productionValue / 10000000,
        'qc_inspected' => (int)$qcInspected,
        'qc_pass' => (int)$qcPass,
        'qc_fail' => (int)$qcFail,
        'qc_pass_percent' => $qcInspected > 0 ? ($qcPass / $qcInspected * 100) : 0,
        'qc_fail_percent' => $qcInspected > 0 ? ($qcFail / $qcInspected * 100) : 0,
        'delivery_qty' => (int)$deliveryQty,
        'delivery_value_cr' => ($del['delivery_value'] ?? 0) / 10000000,
        'scrap_qty' => (float)($scrap['total_scrap'] ?? 0),
        'scrap_loss' => (float)($scrap['scrap_loss'] ?? 0),
        'stock' => (int)$stock,
        'stock_value' => (float)$stockValue
    ];
}

// Calculate summary totals
$summary = [
    'total_production_pcs' => array_sum(array_column($projectReportData, 'production_qty')),
    'production_value_cr' => array_sum(array_column($projectReportData, 'production_value_cr')),
    'total_delivered_pcs' => array_sum(array_column($projectReportData, 'delivery_qty')),
    'delivery_value_cr' => array_sum(array_column($projectReportData, 'delivery_value_cr')),
    'current_stock' => array_sum(array_column($projectReportData, 'stock')),
    'total_scrap_kg' => array_sum(array_column($projectReportData, 'scrap_qty')),
    'scrap_loss_value' => array_sum(array_column($projectReportData, 'scrap_loss')),
    'qc_pass_percentage' => 0
];

$totalInspected = array_sum(array_column($projectReportData, 'qc_inspected'));
$totalPass = array_sum(array_column($projectReportData, 'qc_pass'));
if ($totalInspected > 0) {
    $summary['qc_pass_percentage'] = round(($totalPass / $totalInspected * 100), 1);
}

// Fetch daily trend for chart - combine all production sources
$dailyTrend = [];

// Get daily FG production
$trendParams = [$startDate, $endDate];
$trendTypes = 'ss';
$trendQuery = "SELECT 
    DATE(fe.date_time) as entry_date,
    COALESCE(SUM(fe.passed_qty), 0) as daily_production,
    COALESCE(SUM(fe.passed_qty), 0) as daily_qc_pass
FROM fg_entry fe
LEFT JOIN projects p ON fe.project_id = p.id
WHERE DATE(fe.date_time) BETWEEN ? AND ?
    AND (p.status = 'active' OR p.status IS NULL)";

if ($projectFilter) {
    $trendQuery .= " AND p.project_name = ?";
    $trendParams[] = $projectFilter;
    $trendTypes .= 's';
}

$trendQuery .= " GROUP BY DATE(fe.date_time)";

$trendStmt = $conn->prepare($trendQuery);
$trendStmt->bind_param($trendTypes, ...$trendParams);
$trendStmt->execute();
$trendResult = $trendStmt->get_result();

while ($row = $trendResult->fetch_assoc()) {
    $date = $row['entry_date'];
    $dailyTrend[$date] = [
        'production' => (int)$row['daily_production'],
        'qc_pass' => (int)$row['daily_qc_pass'],
        'delivery' => 0
    ];
}
$trendStmt->close();

// Add CNC production to trend
$cncTrendParams = [$startDate, $endDate];
$cncTrendTypes = 'ss';
$cncTrendQuery = "SELECT 
    DATE(ce.date_time) as entry_date,
    COALESCE(SUM(ce.cutting_roll_quantity), 0) as daily_cnc
FROM cnc_entries ce
LEFT JOIN projects p ON ce.project_id = p.id
WHERE DATE(ce.date_time) BETWEEN ? AND ?
    AND (p.status = 'active' OR p.status IS NULL)";

if ($projectFilter) {
    $cncTrendQuery .= " AND p.project_name = ?";
    $cncTrendParams[] = $projectFilter;
    $cncTrendTypes .= 's';
}

$cncTrendQuery .= " GROUP BY DATE(ce.date_time)";

$cncTrendStmt = $conn->prepare($cncTrendQuery);
$cncTrendStmt->bind_param($cncTrendTypes, ...$cncTrendParams);
$cncTrendStmt->execute();
$cncTrendResult = $cncTrendStmt->get_result();

while ($row = $cncTrendResult->fetch_assoc()) {
    $date = $row['entry_date'];
    if (!isset($dailyTrend[$date])) {
        $dailyTrend[$date] = ['production' => 0, 'qc_pass' => 0, 'delivery' => 0];
    }
    $dailyTrend[$date]['production'] += (int)$row['daily_cnc'];
}
$cncTrendStmt->close();

// Add Sewing production to trend
$swingTrendParams = [$startDate, $endDate];
$swingTrendTypes = 'ss';
$swingTrendQuery = "SELECT 
    DATE(sme.date_time) as entry_date,
    COALESCE(SUM(sme.sewing_qty), 0) as daily_sewing
FROM swing_machine_entry sme
LEFT JOIN projects p ON sme.project_id = p.id
WHERE DATE(sme.date_time) BETWEEN ? AND ?
    AND (p.status = 'active' OR p.status IS NULL)";

if ($projectFilter) {
    $swingTrendQuery .= " AND p.project_name = ?";
    $swingTrendParams[] = $projectFilter;
    $swingTrendTypes .= 's';
}

$swingTrendQuery .= " GROUP BY DATE(sme.date_time)";

$swingTrendStmt = $conn->prepare($swingTrendQuery);
$swingTrendStmt->bind_param($swingTrendTypes, ...$swingTrendParams);
$swingTrendStmt->execute();
$swingTrendResult = $swingTrendStmt->get_result();

while ($row = $swingTrendResult->fetch_assoc()) {
    $date = $row['entry_date'];
    if (!isset($dailyTrend[$date])) {
        $dailyTrend[$date] = ['production' => 0, 'qc_pass' => 0, 'delivery' => 0];
    }
    $dailyTrend[$date]['production'] += (int)$row['daily_sewing'];
}
$swingTrendStmt->close();

// Add delivery data to trend
$delTrendParams = [$startDate, $endDate];
$delTrendTypes = 'ss';
$delTrendQuery = "SELECT 
    DATE(d.delivery_date) as delivery_date,
    COALESCE(SUM(d.delivery_quantity), 0) as daily_delivery
FROM fg_deliveries d
WHERE DATE(d.delivery_date) BETWEEN ? AND ?";

if ($projectFilter) {
    $delTrendQuery .= " AND d.fg_entry_id IN (SELECT id FROM fg_entry WHERE project_id IN (SELECT id FROM projects WHERE project_name = ? AND status = 'active'))";
    $delTrendParams[] = $projectFilter;
    $delTrendTypes .= 's';
}

$delTrendQuery .= " GROUP BY DATE(d.delivery_date)";

$delTrendStmt = $conn->prepare($delTrendQuery);
$delTrendStmt->bind_param($delTrendTypes, ...$delTrendParams);
$delTrendStmt->execute();
$delTrendResult = $delTrendStmt->get_result();

while ($row = $delTrendResult->fetch_assoc()) {
    $date = $row['delivery_date'];
    if (!isset($dailyTrend[$date])) {
        $dailyTrend[$date] = ['production' => 0, 'qc_pass' => 0, 'delivery' => 0];
    }
    $dailyTrend[$date]['delivery'] = (int)$row['daily_delivery'];
}
$delTrendStmt->close();

// Get unique projects for filter (only active projects)
$projects = [];
try {
    $projectsResult = $conn->query("SELECT DISTINCT project_name FROM projects WHERE project_name IS NOT NULL AND status = 'active' AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY project_name");
    if ($projectsResult) {
        while ($row = $projectsResult->fetch_assoc()) {
            $projects[] = $row['project_name'];
        }
    }
} catch (Exception $e) {
    // If is_deleted column doesn't exist, just use status
    $projectsResult = $conn->query("SELECT DISTINCT project_name FROM projects WHERE project_name IS NOT NULL AND status = 'active' ORDER BY project_name");
    if ($projectsResult) {
        while ($row = $projectsResult->fetch_assoc()) {
            $projects[] = $row['project_name'];
        }
    }
}

try {
    $conn->close();
    
    // Return JSON response
    echo json_encode([
        'status' => 'success',
        'current_datetime' => date('d M, Y g:i A'),
        'summary' => $summary,
        'project_report_data' => $projectReportData,
        'charts' => [
            'daily_trend' => $dailyTrend
        ],
        'available_filters' => [
            'projects' => $projects
        ]
    ]);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Error generating report: ' . $e->getMessage()
    ]);
}

} catch (Throwable $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>


