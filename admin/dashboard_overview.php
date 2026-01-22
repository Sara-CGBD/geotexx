<?php
session_start();
require_once '../config/security_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Role-based access control - Only admin and agm ops can access this reporting dashboard
$user_role = strtolower(trim($_SESSION['role'] ?? ''));

// Normalize role names
if ($user_role === 'prod_test') {
    $user_role = 'prod_user';
}
if ($user_role === 'agm operations' || $user_role === 'agm_ops') {
    $user_role = 'agm ops';
}

// Only allow admin and agm ops to access this dashboard
$allowedRoles = ['admin', 'agm ops'];
if (!in_array($user_role, $allowedRoles, true)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access this reporting dashboard. Only Admin and AGM Operations can view this dashboard.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Detect sewing table name (sewing_machine_entry or swing_machine_entry)
$sewingTable = 'sewing_machine_entry';
$tableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
if (!$tableCheck || $tableCheck->num_rows == 0) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'swing_machine_entry'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $sewingTable = 'swing_machine_entry';
    }
}

// Detect optional columns used in queries
$fgHasProductType = false;
$fgColCheck = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'product_type'");
if ($fgColCheck && $fgColCheck->num_rows > 0) {
    $fgHasProductType = true;
}
$fgRollCondition = $fgHasProductType ? "AND product_type = 'roll'" : "";
$fgBagCondition = $fgHasProductType ? "AND (product_type IS NULL OR product_type != 'roll')" : "";

// FG deliveries table optional product type column
$fgDeliveriesHasType = false;
$fgDelColCheck = $conn->query("SHOW COLUMNS FROM fg_deliveries LIKE 'delivery_product_type'");
if ($fgDelColCheck && $fgDelColCheck->num_rows > 0) {
    $fgDeliveriesHasType = true;
}

// Get filter parameters - Default to today's date
$dateFrom = $_GET['date_from'] ?? date('Y-m-d');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// ==================== SHEET PRODUCTION METRICS ====================

// Sheet Production (Roll Entry)
$sheetProductionQuery = "SELECT 
    COUNT(*) as total_sheets,
    COALESCE(SUM(total_weight), 0) as total_weight
FROM roll_entry 
WHERE DATE(date_time) BETWEEN ? AND ?";
$stmt = $conn->prepare($sheetProductionQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$sheetProduction = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Roll Transfers (Sheet to next stage)
$rollTransferQuery = "SELECT 
    COUNT(*) as total_transferred
FROM roll_transfer 
WHERE DATE(date_time) BETWEEN ? AND ?";
$stmt = $conn->prepare($rollTransferQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$rollTransfers = $stmt->get_result()->fetch_assoc();
$stmt->close();

$sheetRemaining = max(($sheetProduction['total_sheets'] ?? 0) - ($rollTransfers['total_transferred'] ?? 0), 0);

// ==================== BAG PRODUCTION METRICS ====================

// Bag Production (CNC Entries)
$bagProductionQuery = "SELECT 
    COUNT(*) as total_bags,
    COALESCE(SUM(cutting_roll_quantity), 0) as total_bag_qty,
    COUNT(DISTINCT cnc_cutting_batch) as total_cnc_batches
FROM cnc_entries 
WHERE DATE(date_time) BETWEEN ? AND ?";
$stmt = $conn->prepare($bagProductionQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$bagProduction = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Sewing Operations
$sewingQuery = "SELECT 
    COUNT(*) as total_sewing_operations,
    COALESCE(SUM(sewing_qty), 0) as total_sewing_qty
FROM {$sewingTable} 
WHERE DATE(date_time) BETWEEN ? AND ?";
$stmt = $conn->prepare($sewingQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$sewingStats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Branding Operations
$brandingQuery = "SELECT 
    COUNT(*) as total_branding_operations,
    COALESCE(SUM(print_qty), 0) as total_branding_qty
FROM branding_entries 
WHERE DATE(date_time) BETWEEN ? AND ?";
$stmt = $conn->prepare($brandingQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$brandingStats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ==================== FINISHED GOODS METRICS ====================

// FG Roll Production
$fgRollQuery = "SELECT 
    COUNT(*) as total_entries,
    COALESCE(SUM(actual_weight), 0) as total_weight,
    COALESCE(SUM(delivered_quantity), 0) as delivered_qty
FROM fg_entry 
WHERE DATE(date_time) BETWEEN ? AND ? 
$fgRollCondition";
$stmt = $conn->prepare($fgRollQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$fgRoll = $stmt->get_result()->fetch_assoc();
$stmt->close();
$fgRoll['current_stock'] = max(($fgRoll['total_weight'] ?? 0) - ($fgRoll['delivered_qty'] ?? 0), 0);

// FG Bag Production
$fgBagQuery = "SELECT 
    COUNT(*) as total_entries,
    COALESCE(SUM(passed_qty), 0) as total_qty,
    COALESCE(SUM(delivered_quantity), 0) as delivered_qty
FROM fg_entry 
WHERE DATE(date_time) BETWEEN ? AND ? 
$fgBagCondition";
$stmt = $conn->prepare($fgBagQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$fgBag = $stmt->get_result()->fetch_assoc();
$stmt->close();
$fgBag['current_stock'] = max(($fgBag['total_qty'] ?? 0) - ($fgBag['delivered_qty'] ?? 0), 0);

$fgTotals = [
    'entries' => ($fgRoll['total_entries'] ?? 0) + ($fgBag['total_entries'] ?? 0),
    'produced_qty' => ($fgRoll['total_weight'] ?? 0) + ($fgBag['total_qty'] ?? 0),
];

$fgDeliveryLabels = [];
$fgDeliveryRollData = [];
$fgDeliveryBagData = [];

// FG Delivery Trend Data
$fgDeliveryQuery = $fgDeliveriesHasType
    ? "SELECT 
    DATE(delivery_date) as delivery_day,
    SUM(CASE WHEN delivery_product_type = 'roll' THEN delivery_quantity ELSE 0 END) as roll_qty,
    SUM(CASE WHEN delivery_product_type IS NULL OR delivery_product_type != 'roll' THEN delivery_quantity ELSE 0 END) as bag_qty
FROM fg_deliveries
    WHERE DATE(delivery_date) BETWEEN ? AND ?
    GROUP BY delivery_day
    ORDER BY delivery_day DESC
    LIMIT 7"
    : "SELECT 
        DATE(delivery_date) as delivery_day,
        SUM(delivery_quantity) as bag_qty,
        0 as roll_qty
    FROM fg_deliveries
WHERE DATE(delivery_date) BETWEEN ? AND ?
GROUP BY delivery_day
ORDER BY delivery_day DESC
LIMIT 7";
$stmt = $conn->prepare($fgDeliveryQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$fgDeliveryRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!empty($fgDeliveryRows)) {
    $fgDeliveryRows = array_reverse($fgDeliveryRows);
    foreach ($fgDeliveryRows as $row) {
        $fgDeliveryLabels[] = date('M d', strtotime($row['delivery_day']));
        $fgDeliveryRollData[] = round((float)$row['roll_qty'], 2);
        $fgDeliveryBagData[] = round((float)$row['bag_qty'], 2);
    }
}

// ==================== SCRAP METRICS ====================

// Scrap Statistics
$scrapQuery = "SELECT 
    COALESCE(scrap_type, 'Unknown') as scrap_type,
    COUNT(*) as record_count,
    COALESCE(SUM(qty), 0) as total_qty
FROM scrap 
WHERE DATE(date_time) BETWEEN ? AND ?
GROUP BY scrap_type
ORDER BY total_qty DESC";
$stmt = $conn->prepare($scrapQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$scrapDetails = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$scrapTotals = [
    'total_scrap_records' => !empty($scrapDetails) ? array_sum(array_column($scrapDetails, 'record_count')) : 0,
    'total_scrap_qty' => !empty($scrapDetails) ? array_sum(array_column($scrapDetails, 'total_qty')) : 0,
];

// ==================== RECYCLE METRICS ====================

// Recycle Statistics
$recycleQuery = "SELECT 
    COUNT(*) as total_recycle_records,
    COALESCE(SUM(recycled_qty), 0) as total_recycled_qty
FROM scrap_recycle 
WHERE DATE(recycled_at) BETWEEN ? AND ?";
$stmt = $conn->prepare($recycleQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$recycleStats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ==================== TREND DATA ====================

// Daily Sheet Production Trend (Last 7 days)
$dailySheetQuery = "SELECT 
    DATE(date_time) as prod_date,
    COUNT(*) as sheet_count,
    COALESCE(SUM(total_weight), 0) as total_weight
FROM roll_entry 
WHERE DATE(date_time) BETWEEN ? AND ?
GROUP BY DATE(date_time)
ORDER BY prod_date DESC
LIMIT 7";
$stmt = $conn->prepare($dailySheetQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$dailySheetTemp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$dailySheet = array_reverse($dailySheetTemp);

// Daily Bag Production Trend (Last 7 days)
$dailyBagQuery = "SELECT 
    DATE(date_time) as prod_date,
    COUNT(*) as bag_count,
    COALESCE(SUM(cutting_roll_quantity), 0) as total_qty
FROM cnc_entries 
WHERE DATE(date_time) BETWEEN ? AND ?
GROUP BY DATE(date_time)
ORDER BY prod_date DESC
LIMIT 7";
$stmt = $conn->prepare($dailyBagQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$dailyBagTemp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$dailyBag = array_reverse($dailyBagTemp);

// Prepare chart data
$allDates = [];
foreach ($dailySheet as $item) $allDates[] = $item['prod_date'];
foreach ($dailyBag as $item) {
    if (!in_array($item['prod_date'], $allDates)) {
        $allDates[] = $item['prod_date'];
    }
}
sort($allDates);
$trendLabels = array_map(function($date) {
    return date('M d', strtotime($date));
}, array_slice($allDates, -7));

$sheetTrendData = [];
$bagTrendData = [];
foreach ($allDates as $date) {
    $sheetTrendData[] = 0;
    $bagTrendData[] = 0;
    foreach ($dailySheet as $item) {
        if ($item['prod_date'] == $date) {
            $sheetTrendData[count($sheetTrendData) - 1] = (int)$item['sheet_count'];
            break;
        }
    }
    foreach ($dailyBag as $item) {
        if ($item['prod_date'] == $date) {
            $bagTrendData[count($bagTrendData) - 1] = (int)$item['bag_count'];
            break;
        }
    }
}
$sheetTrendData = array_slice($sheetTrendData, -7);
$bagTrendData = array_slice($bagTrendData, -7);

// FG Delivery Trend (Roll vs Bag)
$fgDeliveryRollQuery = "SELECT 
    DATE(date_time) as delivery_date,
    COALESCE(SUM(delivered_quantity), 0) as delivered_qty
FROM fg_entry 
WHERE DATE(date_time) BETWEEN ? AND ? 
$fgRollCondition
GROUP BY DATE(date_time)
ORDER BY delivery_date DESC
LIMIT 7";
$stmt = $conn->prepare($fgDeliveryRollQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$fgDeliveryRollTemp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$fgDeliveryRollTrend = array_reverse($fgDeliveryRollTemp);

$fgDeliveryBagQuery = "SELECT 
    DATE(date_time) as delivery_date,
    COALESCE(SUM(delivered_quantity), 0) as delivered_qty
FROM fg_entry 
WHERE DATE(date_time) BETWEEN ? AND ? 
$fgBagCondition
GROUP BY DATE(date_time)
ORDER BY delivery_date DESC
LIMIT 7";
$stmt = $conn->prepare($fgDeliveryBagQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$fgDeliveryBagTemp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$fgDeliveryBagTrend = array_reverse($fgDeliveryBagTemp);

$deliveryDates = [];
foreach ($fgDeliveryRollTrend as $item) {
    $deliveryDates[] = $item['delivery_date'];
}
foreach ($fgDeliveryBagTrend as $item) {
    if (!in_array($item['delivery_date'], $deliveryDates)) {
        $deliveryDates[] = $item['delivery_date'];
    }
}
$deliveryDates = array_values(array_unique($deliveryDates));
sort($deliveryDates);
$recentDeliveryDates = array_slice($deliveryDates, -7);

$fgDeliveryTrendLabels = array_map(function($date) {
    return date('M d', strtotime($date));
}, $recentDeliveryDates);

$rollDeliveryMap = [];
foreach ($fgDeliveryRollTrend as $item) {
    $rollDeliveryMap[$item['delivery_date']] = (float)$item['delivered_qty'];
}

$bagDeliveryMap = [];
foreach ($fgDeliveryBagTrend as $item) {
    $bagDeliveryMap[$item['delivery_date']] = (float)$item['delivered_qty'];
}

$rollDeliveryTrendData = [];
$bagDeliveryTrendData = [];
foreach ($recentDeliveryDates as $date) {
    $rollDeliveryTrendData[] = isset($rollDeliveryMap[$date]) ? $rollDeliveryMap[$date] : 0;
    $bagDeliveryTrendData[] = isset($bagDeliveryMap[$date]) ? $bagDeliveryMap[$date] : 0;
}

$reportingWindowLabel = sprintf(
    '%s – %s',
    date('M d, Y', strtotime($dateFrom)),
    date('M d, Y', strtotime($dateTo))
);

// Flow Efficiency: Percentage of sheets transferred to next stage
// Cap at 100% as it's impossible to transfer more than produced
$sheetTransferRate = ($sheetProduction['total_sheets'] ?? 0) > 0
    ? min(round((($rollTransfers['total_transferred'] ?? 0) / $sheetProduction['total_sheets']) * 100, 1), 100)
    : 0;

// FG Delivery Coverage: Percentage of produced goods that were delivered
// Cap at 100% as you can't deliver more than produced
$fgDeliveryCoverage = ($fgTotals['produced_qty'] ?? 0) > 0
    ? min(round((($fgRoll['delivered_qty'] ?? 0) + ($fgBag['delivered_qty'] ?? 0)) / $fgTotals['produced_qty'] * 100, 1), 100)
    : 0;

// Circularity Recovery: Percentage of scrap that was recycled
// Cap at 100% as you can't recycle more than the scrap generated
$recycleRecoveryRate = ($scrapTotals['total_scrap_qty'] ?? 0) > 0
    ? min(round(($recycleStats['total_recycled_qty'] ?? 0) / $scrapTotals['total_scrap_qty'] * 100, 1), 100)
    : 0;

$fgStockHealth = ($fgTotals['produced_qty'] ?? 0) > 0
    ? round((($fgRoll['current_stock'] ?? 0) + ($fgBag['current_stock'] ?? 0)) / $fgTotals['produced_qty'] * 100, 1)
    : 0;

$topScrapType = $scrapDetails[0]['scrap_type'] ?? 'No Scrap';
$topScrapQty = $scrapDetails[0]['total_qty'] ?? 0;

$sheetTransferStatus = [
    'class' => 'positive',
    'label' => 'On target'
];
if ($sheetTransferRate < 80 && $sheetTransferRate >= 60) {
    $sheetTransferStatus = ['class' => 'neutral', 'label' => 'Monitor'];
} elseif ($sheetTransferRate < 60) {
    $sheetTransferStatus = ['class' => 'warning', 'label' => 'Needs attention'];
}

$fgDeliveryStatus = [
    'class' => 'positive',
    'label' => 'Healthy flow'
];
if ($fgDeliveryCoverage < 75 && $fgDeliveryCoverage >= 50) {
    $fgDeliveryStatus = ['class' => 'neutral', 'label' => 'Keep pushing'];
} elseif ($fgDeliveryCoverage < 50) {
    $fgDeliveryStatus = ['class' => 'warning', 'label' => 'Logistics lag'];
}

$recycleStatus = [
    'class' => 'positive',
    'label' => 'Recovered'
];
if ($recycleRecoveryRate < 65 && $recycleRecoveryRate >= 40) {
    $recycleStatus = ['class' => 'neutral', 'label' => 'Scaling'];
} elseif ($recycleRecoveryRate < 40) {
    $recycleStatus = ['class' => 'warning', 'label' => 'Low recovery'];
}

$fgStockStatus = [
    'class' => 'neutral',
    'label' => 'Balanced'
];
if ($fgStockHealth < 30) {
    $fgStockStatus = ['class' => 'warning', 'label' => 'Depleting'];
} elseif ($fgStockHealth > 55) {
    $fgStockStatus = ['class' => 'positive', 'label' => 'Strong buffer'];
}

$moduleTargets = [];
$moduleTargetFullList = [];
$moduleTargetLookup = [];
$moduleTargetHighlights = [];
$hasAdditionalTargets = false;

$moduleTargetQuery = "
    SELECT 
        module_id, 
        COALESCE(SUM(target_qty), 0) as total_target,
        COALESCE(SUM(production_qty), 0) as total_reported
    FROM production_targets 
    WHERE target_date BETWEEN ? AND ?
    GROUP BY module_id
";
$stmt = $conn->prepare($moduleTargetQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$moduleTargetResult = $stmt->get_result();
while ($row = $moduleTargetResult->fetch_assoc()) {
    $moduleId = (int)$row['module_id'];
    $moduleTargets[$moduleId] = [
        'target' => (float)$row['total_target'],
        'reported' => (float)$row['total_reported']
    ];
}
$stmt->close();

$moduleActualCatalog = [
    1 => ['name' => 'Production (kg)', 'value' => (float)($sheetProduction['total_weight'] ?? 0), 'unit' => 'kg'],
    2 => ['name' => 'Roll Production (rolls)', 'value' => (float)($sheetProduction['total_sheets'] ?? 0), 'unit' => 'rolls'],
    3 => ['name' => 'CNC Cutting', 'value' => (float)($bagProduction['total_bag_qty'] ?? 0), 'unit' => 'pcs'],
    4 => ['name' => 'Sewing', 'value' => (float)($sewingStats['total_sewing_qty'] ?? 0), 'unit' => 'pcs'],
    5 => ['name' => 'Branding', 'value' => (float)($brandingStats['total_branding_qty'] ?? 0), 'unit' => 'pcs'],
    7 => ['name' => 'Finished Goods', 'value' => (float)($fgTotals['produced_qty'] ?? 0), 'unit' => 'units'],
    8 => ['name' => 'Recycle', 'value' => (float)($recycleStats['total_recycled_qty'] ?? 0), 'unit' => 'kg'],
    9 => ['name' => 'Scrap / Waste', 'value' => (float)($scrapTotals['total_scrap_qty'] ?? 0), 'unit' => 'kg'],
];

foreach ($moduleActualCatalog as $moduleId => $meta) {
    if (!isset($moduleTargets[$moduleId]) || $moduleTargets[$moduleId]['target'] <= 0) {
        continue;
    }
    $targetValue = $moduleTargets[$moduleId]['target'];
    $actualValue = $meta['value'] > 0 ? $meta['value'] : ($moduleTargets[$moduleId]['reported'] ?? 0);
    $achievement = $targetValue > 0 ? round(($actualValue / $targetValue) * 100, 1) : 0;
    $moduleTargetFullList[] = [
        'module_id' => $moduleId,
        'name' => $meta['name'],
        'unit' => $meta['unit'],
        'target' => $targetValue,
        'actual' => $actualValue,
        'achievement' => $achievement
    ];
}

if (!empty($moduleTargetFullList)) {
    usort($moduleTargetFullList, function($a, $b) {
        return $b['target'] <=> $a['target'];
    });
    foreach ($moduleTargetFullList as $entry) {
        $moduleTargetLookup[$entry['module_id']] = $entry;
    }
    $moduleTargetHighlights = array_slice($moduleTargetFullList, 0, 4);
    $hasAdditionalTargets = count($moduleTargetFullList) > count($moduleTargetHighlights);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Overview - Geotex Automation</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --bg: #e8eef6;
            --bg-accent: #f5f8ff;
            --card: #ffffff;
            --card-border: rgba(15,23,42,0.06);
            --muted: #7c8ba1;
            --primary:rgb(39, 49, 78);
            --secondary:rgb(25, 59, 77);
            --danger: #ef476f;
            --warning: #ffb703;
            --success:rgb(17, 65, 58);
            --shadow: 0 20px 50px rgba(15, 23, 42, 0.12);
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, var(--bg-accent), var(--bg));
            color: #0f172a;
        }
        
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 32px 40px 40px;
        }
        
        .dashboard-header {
            background: linear-gradient(135deg,rgb(20, 24, 36) 0%,rgb(33, 44, 56) 50%,rgb(68, 170, 190) 100%);
            border-radius: 24px;
            padding: 32px 40px;
            margin-bottom: 24px;
            color: #f5f7fb;
            box-shadow: var(--shadow);
        }
        
        .dashboard-hero {
            display: flex;
            flex-wrap: wrap;
            gap: 32px;
            align-items: center;
            justify-content: space-between;
        }
        
        .hero-eyebrow {
            letter-spacing: 0.24em;
            text-transform: uppercase;
            font-size: 0.8rem;
            color: rgba(255,255,255,0.65);
        }
        
        .hero-title {
            font-size: 2.35rem;
            font-weight: 700;
            margin: 10px 0;
        }
        
        .hero-subtitle {
            color: rgba(255,255,255,0.75);
            max-width: 620px;
            font-size: 1rem;
        }
        
        .hero-metrics {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .hero-metric {
            min-width: 180px;
            padding: 18px 20px;
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--card-border);
            border-radius: 16px;
            backdrop-filter: blur(6px);
        }
        
        .hero-metric span {
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(255,255,255,0.6);
        }
        
        .hero-metric strong {
            display: block;
            margin-top: 6px;
            font-size: 1.05rem;
        }
        
        .filters-section {
            background: var(--card);
            border-radius: 18px;
            padding: 24px 28px;
            margin-bottom: 24px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 10px 20px rgba(15, 23, 42, 0.08);
        }
        
        .filters-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            align-items: end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
            margin-bottom: 8px;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 12px 14px;
            border: 1px solid rgba(15, 23, 42, 0.15);
            border-radius: 12px;
            font-size: 0.95rem;
            background: #f8fafc;
            color: #0f172a;
        }
        
        .filter-buttons {
            display: flex;
            gap: 12px;
        }
        
        .btn {
            padding: 12px 26px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.25s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), #3a5ce0);
            color: #ffffff;
            box-shadow: 0 12px 18px rgba(79, 124, 255, 0.35);
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 15px 24px rgba(79, 124, 255, 0.4);
        }
        
        .btn-danger {
            background: rgba(15, 23, 42, 0.08);
            color: #0f172a;
        }
        
        .btn-danger:hover {
            background: rgba(15, 23, 42, 0.15);
        }
        
        .last-updated {
            margin-top: 18px;
            font-size: 0.85rem;
            color: #64748b;
            text-align: right;
        }
        
        .insights-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }
        
        .insight-card {
            background: #ffffff;
            color: #0f172a;
            border-radius: 18px;
            padding: 22px 24px;
            border: 1px solid rgba(15,23,42,0.06);
            box-shadow: 0 15px 25px rgba(15, 23, 42, 0.08);
        }
        
        .insight-title {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            color: #94a3b8;
            margin-bottom: 12px;
        }
        
        .insight-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 6px;
        }
        
        .insight-meta {
            font-size: 0.95rem;
            color: #64748b;
            margin-bottom: 12px;
        }
        
        .status-chip {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
        }
        
        .status-chip.positive { background: rgba(45, 212, 191, 0.15); color: #2dd4bf; }
        .status-chip.neutral { background: rgba(255, 183, 3, 0.15); color: #ffb703; }
        .status-chip.warning { background: rgba(239, 71, 111, 0.15); color: #ef476f; }
        
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 30px;
        }
        
        .kpi-card {
            background: var(--card);
            border-radius: 20px;
            padding: 26px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 18px 30px rgba(15, 23, 42, 0.08);
        }
        
        .kpi-card-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 18px;
        }
        
        .kpi-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            font-size: 1.4rem;
            color: #ffffff;
        }
        
        .kpi-card.sheet .kpi-icon { background: linear-gradient(135deg, #3b82f6, #2563eb); }
        .kpi-card.bag .kpi-icon { background: linear-gradient(135deg, #c084fc, #9333ea); }
        .kpi-card.fg .kpi-icon { background: linear-gradient(135deg, #34d399, #059669); }
        .kpi-card.scrap .kpi-icon { background: linear-gradient(135deg, #fb7185, #e11d48); }
        .kpi-card.recycle .kpi-icon { background: linear-gradient(135deg, #fcd34d, #f97316); }
        
        .kpi-title {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: #94a3b8;
            margin-bottom: 6px;
        }
        
        .kpi-value {
            font-size: 2.4rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.1;
        }
        
        .kpi-subtitle {
            font-size: 0.95rem;
            color: #64748b;
        }
        
        .kpi-details {
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid rgba(15, 23, 42, 0.08);
        }
        
        .kpi-detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 0.95rem;
        }
        
        .kpi-detail-label {
            color: #94a3b8;
        }
        
        .kpi-detail-value {
            font-weight: 600;
            color: #0f172a;
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .target-pill {
            margin-top: 12px;
            padding: 14px 16px;
            border-radius: 14px;
            background: rgba(79, 124, 255, 0.08);
            border: 1px solid rgba(79, 124, 255, 0.15);
        }

        .target-pill-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.9rem;
            font-weight: 600;
            color: #1e293b;
        }

        .target-pill-header strong {
            font-size: 1rem;
        }

        .target-pill-body {
            margin-top: 8px;
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: #475569;
        }

        .target-progress-bar {
            margin-top: 10px;
            width: 100%;
            height: 6px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }

        .target-progress-fill {
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(135deg, #4f7cff, #2dd4bf);
            width: 0;
        }

        .target-tracker .target-row {
            margin-bottom: 14px;
            padding-bottom: 14px;
            border-bottom: 1px solid rgba(15, 23, 42, 0.08);
        }

        .target-tracker .target-row:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .target-tracker.empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
            min-height: 220px;
            text-align: center;
        }

        .target-tracker .no-targets p {
            color: #94a3b8;
            font-size: 0.95rem;
        }

        .target-tracker .no-targets .btn {
            margin-top: 8px;
        }

        .target-row-title {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            font-weight: 600;
            color: #0f172a;
        }

        .target-row-metric {
            font-size: 0.85rem;
            color: #64748b;
            margin-top: 4px;
        }

        .target-row-progress {
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .target-row-progress span {
            font-weight: 600;
            color: #0f172a;
        }

        .target-row-progress .target-progress-bar {
            margin: 0;
        }
        
        .chart-card {
            background: var(--card);
            border-radius: 20px;
            padding: 26px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            box-shadow: 0 20px 30px rgba(15, 23, 42, 0.08);
        }
        
        .chart-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 14px;
        }
        
        .chart-container {
            position: relative;
            height: 320px;
        }
        
        @media (max-width: 992px) {
            .dashboard-container {
                padding: 24px;
            }
            .dashboard-hero {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        
        @media (max-width: 600px) {
            .hero-metrics {
                flex-direction: column;
                width: 100%;
            }
            .filters-row {
                grid-template-columns: 1fr;
            }
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    
    <!-- Header -->
    <div class="dashboard-header">
        <div class="dashboard-hero">
            <div>
                <h1 class="hero-title">Geotex Automation - Reporting Dashboard</h1>
                <p class="hero-subtitle">
                    Monitor production, targets, and QC at a glance.
                </p>
            </div>
            <div class="hero-metrics">
                <div class="hero-metric">
                    <span>Reporting Window</span>
                    <strong><?php echo $reportingWindowLabel; ?></strong>
                </div>
                <div class="hero-metric">
                    <span>Data Refresh</span>
                    <strong><?php echo date('H:i:s'); ?> LT</strong>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="filters-section">
        <form method="GET" action="">
            <div class="filters-row">
                <div class="filter-group">
                    <label for="date_from"><i class="fas fa-calendar"></i> Start Date</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" required>
                </div>
                <div class="filter-group">
                    <label for="date_to"><i class="fas fa-calendar"></i> End Date</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" required>
                </div>
                <div class="filter-buttons">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sync-alt"></i> Reload
                    </button>
                    <a href="dashboard_overview.php" class="btn btn-danger" style="text-decoration: none; display: inline-block; line-height: 1.5;">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </div>
        </form>
        <div class="last-updated">
            <i class="fas fa-clock"></i> Last updated: <?php echo date('H:i:s'); ?>
        </div>
    </div>

    <!-- Executive Insights -->
    <div class="insights-grid">
        <div class="insight-card">
            <p class="insight-title">Flow Efficiency</p>
            <p class="insight-value"><?php echo number_format($sheetTransferRate, 1); ?>%</p>
            <p class="insight-meta">Sheets transferred to next stage</p>
            <span class="status-chip <?php echo $sheetTransferStatus['class']; ?>">
                <?php echo $sheetTransferStatus['label']; ?>
            </span>
        </div>
        <div class="insight-card">
            <p class="insight-title">FG Delivery Coverage</p>
            <p class="insight-value"><?php echo number_format($fgDeliveryCoverage, 1); ?>%</p>
            <p class="insight-meta">Delivered vs produced volume</p>
            <span class="status-chip <?php echo $fgDeliveryStatus['class']; ?>">
                <?php echo $fgDeliveryStatus['label']; ?>
            </span>
        </div>
        <div class="insight-card">
            <p class="insight-title">Circularity Recovery</p>
            <p class="insight-value"><?php echo number_format($recycleRecoveryRate, 1); ?>%</p>
            <p class="insight-meta">Scrap redirected to recycling</p>
            <span class="status-chip <?php echo $recycleStatus['class']; ?>">
                <?php echo $recycleStatus['label']; ?>
            </span>
        </div>
        <div class="insight-card">
            <p class="insight-title">Scrap Focus</p>
            <p class="insight-value"><?php echo htmlspecialchars($topScrapType); ?></p>
            <p class="insight-meta"><?php echo number_format($topScrapQty, 2); ?> kg logged</p>
            <span class="status-chip <?php echo $topScrapQty > 0 ? 'warning' : 'positive'; ?>">
                <?php echo $topScrapQty > 0 ? 'Key watch' : 'Clean run'; ?>
            </span>
        </div>
    </div>
    
    <!-- KPI Cards -->
    <div class="kpi-grid">
        
        <!-- Sheet Production Card -->
        <div class="kpi-card sheet">
            <div class="kpi-card-header">
                <div class="kpi-icon">
                    <i class="fas fa-layer-group"></i>
                </div>
                <div>
                    <div class="kpi-title">Sheet Production</div>
                    <div class="kpi-value"><?php echo number_format($sheetProduction['total_sheets']); ?></div>
                    <div class="kpi-subtitle">Total Sheets</div>
                </div>
            </div>
            <div class="kpi-details">
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Rolls Entered</span>
                    <span class="kpi-detail-value"><?php echo number_format($sheetProduction['total_sheets']); ?></span>
                </div>
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Total Weight</span>
                    <span class="kpi-detail-value"><?php echo number_format($sheetProduction['total_weight'], 2); ?> kg</span>
                </div>
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Rolls Transferred</span>
                    <span class="kpi-detail-value"><?php echo number_format($rollTransfers['total_transferred']); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Bag Production Card -->
        <div class="kpi-card bag">
            <div class="kpi-card-header">
                <div class="kpi-icon">
                    <i class="fas fa-boxes-stacked"></i>
                </div>
                <div>
                    <div class="kpi-title">Bag Production</div>
                    <div class="kpi-value"><?php echo number_format($bagProduction['total_bag_qty']); ?></div>
                    <div class="kpi-subtitle">Total Quantity (pcs)</div>
                </div>
            </div>
            <div class="kpi-details">
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Rolls Received</span>
                    <span class="kpi-detail-value"><?php echo number_format($sheetProduction['total_sheets']); ?> rolls</span>
                </div>
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Sewing Quantity</span>
                    <span class="kpi-detail-value"><?php echo number_format($sewingStats['total_sewing_qty']); ?> pcs</span>
                </div>
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Branding Quantity</span>
                    <span class="kpi-detail-value"><?php echo number_format($brandingStats['total_branding_qty']); ?> pcs</span>
                </div>
            </div>
        </div>
        
        <!-- Finished Goods Card -->
        <div class="kpi-card fg">
            <div class="kpi-card-header">
                <div class="kpi-icon">
                    <i class="fas fa-warehouse"></i>
                </div>
                <div>
                    <div class="kpi-title">Finished Goods</div>
                    <div class="kpi-value"><?php echo number_format($fgTotals['entries']); ?></div>
                    <div class="kpi-subtitle">Total FG Entries (Roll + Bag)</div>
                </div>
            </div>
            <div class="kpi-details">
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Total Produced Qty</span>
                    <span class="kpi-detail-value"><?php echo number_format($fgTotals['produced_qty'], 2); ?></span>
                </div>
                <div class="kpi-detail-row" style="margin-top: 10px; font-weight: 600;">
                    <span>Rolls</span>
                    <span></span>
                </div>
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Entries</span>
                    <span class="kpi-detail-value"><?php echo number_format($fgRoll['total_entries']); ?></span>
                </div>
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Produced (kg)</span>
                    <span class="kpi-detail-value"><?php echo number_format($fgRoll['total_weight'], 2); ?></span>
                </div>
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Delivered (kg)</span>
                    <span class="kpi-detail-value"><?php echo number_format($fgRoll['delivered_qty'], 2); ?></span>
                </div>
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Stock (kg)</span>
                    <span class="kpi-detail-value"><?php echo number_format($fgRoll['current_stock'], 2); ?></span>
                </div>
                <div class="kpi-detail-row" style="margin-top: 10px; font-weight: 600;">
                    <span>Bags</span>
                    <span></span>
                </div>
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Entries</span>
                    <span class="kpi-detail-value"><?php echo number_format($fgBag['total_entries']); ?></span>
                </div>
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Produced (pcs)</span>
                    <span class="kpi-detail-value"><?php echo number_format($fgBag['total_qty']); ?></span>
                </div>
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Delivered (pcs)</span>
                    <span class="kpi-detail-value"><?php echo number_format($fgBag['delivered_qty']); ?></span>
                </div>
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Stock (pcs)</span>
                    <span class="kpi-detail-value"><?php echo number_format($fgBag['current_stock']); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Scrap Card -->
        <div class="kpi-card scrap">
            <div class="kpi-card-header">
                <div class="kpi-icon">
                    <i class="fas fa-triangle-exclamation"></i>
                </div>
                <div>
                    <div class="kpi-title">Scrap</div>
                    <div class="kpi-value"><?php echo number_format($scrapTotals['total_scrap_qty'], 2); ?></div>
                    <div class="kpi-subtitle">Total Scrap (kg)</div>
                </div>
            </div>
            <div class="kpi-details">
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Scrap Quantity</span>
                    <span class="kpi-detail-value"><?php echo number_format($scrapTotals['total_scrap_qty'], 2); ?> kg</span>
                </div>
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Scrap Records</span>
                    <span class="kpi-detail-value"><?php echo number_format($scrapTotals['total_scrap_records']); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Recycle Card -->
        <div class="kpi-card recycle">
            <div class="kpi-card-header">
                <div class="kpi-icon">
                    <i class="fas fa-recycle"></i>
                </div>
                <div>
                    <div class="kpi-title">Recycle</div>
                    <div class="kpi-value"><?php echo number_format($recycleStats['total_recycled_qty'], 2); ?></div>
                    <div class="kpi-subtitle">Total Recycled (kg)</div>
                </div>
            </div>
            <?php 
            $recycleTarget = $moduleTargetLookup[8] ?? null;
            if ($recycleTarget):
                $recycleWidth = max(0, min(100, $recycleTarget['achievement']));
            ?>
                <div class="target-pill">
                    <div class="target-pill-header">
                        <span>Planner Target</span>
                        <strong><?php echo number_format($recycleTarget['target'], 2); ?> kg</strong>
                    </div>
                    <div class="target-pill-body">
                        <span><?php echo number_format($recycleTarget['actual'], 2); ?> kg actual</span>
                        <span><?php echo number_format($recycleTarget['achievement'], 1); ?>% achieved</span>
                    </div>
                    <div class="target-progress-bar">
                        <div class="target-progress-fill" style="width: <?php echo $recycleWidth; ?>%;"></div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="kpi-details">
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Recycle Quantity</span>
                    <span class="kpi-detail-value"><?php echo number_format($recycleStats['total_recycled_qty'], 2); ?> kg</span>
                </div>
                <div class="kpi-detail-row">
                    <span class="kpi-detail-label">Recycle Records</span>
                    <span class="kpi-detail-value"><?php echo number_format($recycleStats['total_recycle_records']); ?></span>
                </div>
            </div>
        </div>

        <div class="kpi-card target-tracker <?php echo empty($moduleTargetHighlights) ? 'empty' : ''; ?>">
            <div class="kpi-card-header">
                <div class="kpi-icon" style="background: linear-gradient(135deg, #f97316, #fb7185);">
                    <i class="fas fa-bullseye"></i>
                </div>
                <div>
                    <div class="kpi-title">Planner Targets</div>
                    <div class="kpi-subtitle">Actual vs target for active modules</div>
                </div>
            </div>
            <?php if (!empty($moduleTargetHighlights)): ?>
                <?php foreach ($moduleTargetHighlights as $targetRow): 
                    $rowWidth = max(0, min(100, $targetRow['achievement']));
                ?>
                    <div class="target-row">
                        <div class="target-row-title">
                            <span><?php echo htmlspecialchars($targetRow['name']); ?></span>
                            <span><?php echo number_format($targetRow['achievement'], 1); ?>%</span>
                        </div>
                        <div class="target-row-metric">
                            <?php echo number_format($targetRow['actual'], 2) . ' / ' . number_format($targetRow['target'], 2) . ' ' . $targetRow['unit']; ?>
                        </div>
                        <div class="target-row-progress">
                            <div class="target-progress-bar">
                                <div class="target-progress-fill" style="width: <?php echo $rowWidth; ?>%;"></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($hasAdditionalTargets): ?>
                    <div class="target-row-metric" style="margin-top: 12px; font-style: italic;">
                        Additional modules have targets — open the Target vs Actual report for full detail.
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-targets">
                    <p>No planner targets found for this reporting window.</p>
                    <a href="../forms/target_entry.php" class="btn btn-primary" style="justify-content:center; margin-top: 18px;">
                        <i class="fas fa-plus-circle"></i> Set Target
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
    
    <!-- Charts -->
    <div class="charts-grid">
        
        <!-- Production Trend Chart -->
        <div class="chart-card">
            <div class="chart-title">Production Trend</div>
            <div class="chart-container">
                <canvas id="productionTrendChart"></canvas>
            </div>
        </div>

        <!-- FG Delivery Trend Chart -->
        <div class="chart-card">
            <div class="chart-title">FG Delivery Trend (Roll vs Bag)</div>
            <div class="chart-container">
                <canvas id="fgDeliveryTrendChart"></canvas>
            </div>
        </div>
        
        <!-- Scrap vs Recycle Chart -->
        <div class="chart-card">
            <div class="chart-title">Scrap vs Recycle</div>
            <div class="chart-container">
                <canvas id="scrapRecycleChart"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-title">Scrap Distribution by Type</div>
            <div class="chart-container">
                <canvas id="scrapDistributionChart"></canvas>
            </div>
        </div>

    </div>
    
</div>

<script>
// Production Trend Chart
const productionCtx = document.getElementById('productionTrendChart');
if (productionCtx) {
    new Chart(productionCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($trendLabels); ?>,
            datasets: [{
                label: 'Sheet Production',
                data: <?php echo json_encode($sheetTrendData); ?>,
                borderColor: '#3498db',
                backgroundColor: 'rgba(52, 152, 219, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4
            }, {
                label: 'Bag Production',
                data: <?php echo json_encode($bagTrendData); ?>,
                borderColor: '#9b59b6',
                backgroundColor: 'rgba(155, 89, 182, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

// FG Delivery Trend Chart
const fgDeliveryCtx = document.getElementById('fgDeliveryTrendChart');
if (fgDeliveryCtx) {
    const fgLabels = <?php echo json_encode($fgDeliveryLabels); ?>;
    const fgRollData = <?php echo json_encode($fgDeliveryRollData); ?>;
    const fgBagData = <?php echo json_encode($fgDeliveryBagData); ?>;
    if (fgLabels.length > 0) {
        new Chart(fgDeliveryCtx, {
            type: 'line',
            data: {
                labels: fgLabels,
                datasets: [{
                    label: 'Roll Deliveries (kg)',
                    data: fgRollData,
                    borderColor: '#0ea5e9',
                    backgroundColor: 'rgba(14, 165, 233, 0.15)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }, {
                    label: 'Bag Deliveries (pcs)',
                    data: fgBagData,
                    borderColor: '#f97316',
                    backgroundColor: 'rgba(249, 115, 22, 0.15)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    } else {
        fgDeliveryCtx.parentElement.innerHTML = '<div style="padding: 50px; text-align: center; color: #95a5a6;">No FG delivery data available</div>';
    }
}

// Scrap vs Recycle Chart
const scrapRecycleCtx = document.getElementById('scrapRecycleChart');
if (scrapRecycleCtx) {
    new Chart(scrapRecycleCtx, {
        type: 'bar',
        data: {
            labels: ['Scrap', 'Recycle'],
            datasets: [{
                label: 'Quantity (kg)',
                data: [
                    <?php echo number_format($scrapTotals['total_scrap_qty'], 2); ?>,
                    <?php echo number_format($recycleStats['total_recycled_qty'], 2); ?>
                ],
                backgroundColor: ['#e74c3c', '#f39c12'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

// Scrap Distribution Chart
const scrapDistributionCtx = document.getElementById('scrapDistributionChart');
if (scrapDistributionCtx) {
    const scrapDistributionData = <?php echo json_encode($scrapDetails); ?>;
    if (scrapDistributionData && scrapDistributionData.length > 0) {
        new Chart(scrapDistributionCtx, {
            type: 'doughnut',
            data: {
                labels: scrapDistributionData.map(item => item.scrap_type || 'Unknown'),
                datasets: [{
                    data: scrapDistributionData.map(item => parseFloat(item.total_qty) || 0),
                    backgroundColor: ['#3498db', '#e74c3c', '#f39c12', '#1abc9c', '#9b59b6', '#2ecc71'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right'
                    }
                }
            }
        });
    } else {
        scrapDistributionCtx.parentElement.innerHTML = '<div style="padding: 50px; text-align: center; color: #95a5a6;">No scrap data available</div>';
    }
}


// Auto-refresh every 60 seconds
setInterval(function() {
    const now = new Date();
    const timeStr = now.toLocaleTimeString('en-US', { hour12: false });
    const lastUpdatedEl = document.querySelector('.last-updated');
    if (lastUpdatedEl) {
        lastUpdatedEl.innerHTML = '<i class="fas fa-clock"></i> Last updated: ' + timeStr;
    }
}, 60000);
</script>
</body>
</html>



