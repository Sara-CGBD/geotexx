<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
// Optional dev auto-reload (skip if missing)
$devReload = __DIR__ . '/../dev/auto_reload.php';
if (file_exists($devReload)) {
    include_once $devReload;
}

// Performance monitoring
require_once '../config/PerformanceMonitor.php';
PerformanceMonitor::start();

require_once '../config/security_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Role-based access control - Only management and admin
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'management', 'agm ops', 'agm operations'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access Management KPI Dashboard.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Get date range filter (default: show all data - from 2020 to today)
$dateFrom = $_GET['date_from'] ?? '2020-01-01';
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Initialize all variables to prevent undefined variable errors
$rollProduction = ['total_rolls' => 0, 'total_weight' => 0, 'avg_weight' => 0];
$fiberToRoll = ['total_fiber_used' => 0, 'total_roll_produced' => 0];
$conversionEfficiency = 0;
$cncProduction = ['total_cnc' => 0, 'total_cnc_qty' => 0];
$fgProduction = ['total_fg' => 0, 'total_fg_weight' => 0];
$qcStats = ['total_inspections' => 0, 'passed' => 0, 'failed' => 0];
$qcPassRate = 0;
$defectsByStage = [];
$scrapStats = [];
$totalScrapQty = 0;
$totalScrapRecords = 0;
$recycleStats = ['total_recycle_records' => 0, 'total_recycled_qty' => 0];
$recycleRate = 0;
$fgStock = ['total_items' => 0, 'total_stock_weight' => 0];
$fgDelivery = ['total_deliveries' => 0, 'total_delivered_weight' => 0];
$activeProjects = ['total_projects' => 0];
$targetStats = ['total_target' => 0, 'total_actual' => 0];
$targetAchievement = 0;
$transferStats = ['total_transfers' => 0, 'total_transfer_weight' => 0];
$activeUsers = ['active_users' => 0];
$dailyProduction = [];
$dailyQC = [];
$wastePercentage = 0;
$opvScore = 0;

// ==================== PRODUCTION METRICS ====================

try {
    // Total Production (Roll Entry)
    $rollProductionQuery = "SELECT 
        COUNT(*) as total_rolls,
        COALESCE(SUM(total_weight), 0) as total_weight,
        COALESCE(AVG(total_weight), 0) as avg_weight
    FROM roll_entry 
    WHERE DATE(date_time) BETWEEN ? AND ? AND is_deleted = 0";
    $stmt = $conn->prepare($rollProductionQuery);
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $rollProduction = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Exception $e) {
    error_log("Roll Production Query Error: " . $e->getMessage());
}

// Fiber to Roll Conversion Efficiency
$fiberToRollQuery = "SELECT 
    COALESCE(SUM(bale_weight), 0) as total_fiber_used,
    COALESCE(SUM(total_weight), 0) as total_roll_produced
FROM fiber_to_roll_entry 
WHERE DATE(date_time) BETWEEN ? AND ?";
$stmt = $conn->prepare($fiberToRollQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$fiberToRoll = $stmt->get_result()->fetch_assoc();
$stmt->close();
// Conversion efficiency should be less than 100% (you can't create more weight than you input)
// Formula: (Output Weight / Input Weight) * 100
$conversionEfficiency = $fiberToRoll['total_fiber_used'] > 0 
    ? min(($fiberToRoll['total_roll_produced'] / $fiberToRoll['total_fiber_used']) * 100, 100)
    : 0;

// CNC Production
$cncQuery = "SELECT COUNT(*) as total_cnc, COALESCE(SUM(cutting_roll_quantity), 0) as total_cnc_qty 
FROM cnc_entries 
WHERE DATE(date_time) BETWEEN ? AND ?";
$stmt = $conn->prepare($cncQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$cncProduction = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Finished Goods
$fgQuery = "SELECT COUNT(*) as total_fg, COALESCE(SUM(passed_qty), 0) as total_fg_weight 
FROM fg_entry 
WHERE DATE(date_time) BETWEEN ? AND ? AND is_deleted = 0";
$stmt = $conn->prepare($fgQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$fgProduction = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ==================== QUALITY METRICS ====================

// QC Pass/Fail Statistics
$qcQuery = "SELECT 
    COUNT(*) as total_inspections,
    SUM(CASE WHEN qc_result = 'Pass' THEN 1 ELSE 0 END) as passed,
    SUM(CASE WHEN qc_result = 'Fail' THEN 1 ELSE 0 END) as failed
FROM qc_entries 
WHERE DATE(date_time) BETWEEN ? AND ?";
$stmt = $conn->prepare($qcQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$qcStats = $stmt->get_result()->fetch_assoc();
$stmt->close();
$qcPassRate = $qcStats['total_inspections'] > 0 
    ? ($qcStats['passed'] / $qcStats['total_inspections']) * 100 
    : 0;

// Defect Analysis by Stage
$defectByStageQuery = "SELECT 
    qc_stage,
    COUNT(*) as defect_count
FROM qc_entries 
WHERE qc_result = 'Fail' 
AND DATE(date_time) BETWEEN ? AND ?
GROUP BY qc_stage
ORDER BY defect_count DESC";
$stmt = $conn->prepare($defectByStageQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$defectsByStage = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ==================== SCRAP & WASTE METRICS ====================

// Scrap Statistics
$scrapQuery = "SELECT 
    COUNT(*) as total_scrap_records,
    COALESCE(SUM(qty), 0) as total_scrap_qty,
    scrap_type,
    COUNT(*) as type_count
FROM scrap 
WHERE is_deleted = 0 
AND DATE(date_time) BETWEEN ? AND ?
GROUP BY scrap_type";
$stmt = $conn->prepare($scrapQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$scrapStats = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalScrapQty = array_sum(array_column($scrapStats, 'total_scrap_qty'));
$totalScrapRecords = array_sum(array_column($scrapStats, 'type_count'));

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

$recycleRate = $totalScrapQty > 0 
    ? ($recycleStats['total_recycled_qty'] / $totalScrapQty) * 100 
    : 0;

// ==================== INVENTORY & STOCK ====================

// Current FG Stock (All FG entries that haven't been fully delivered)
$fgStockQuery = "SELECT 
    COUNT(*) as total_items,
    COALESCE(SUM(passed_qty - COALESCE(delivered_quantity, 0)), 0) as total_stock_weight
FROM fg_entry 
WHERE is_deleted = 0 
AND (passed_qty - COALESCE(delivered_quantity, 0)) > 0";
$fgStock = $conn->query($fgStockQuery)->fetch_assoc();

// FG Deliveries
$fgDeliveryQuery = "SELECT 
    COUNT(*) as total_deliveries,
    COALESCE(SUM(delivery_quantity), 0) as total_delivered_weight
FROM fg_deliveries 
WHERE DATE(delivery_date) BETWEEN ? AND ?";
$stmt = $conn->prepare($fgDeliveryQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$fgDelivery = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ==================== PROJECT METRICS ====================

// Active Projects
$activeProjectsQuery = "SELECT COUNT(*) as total_projects FROM projects";
$activeProjects = $conn->query($activeProjectsQuery)->fetch_assoc();

// Target vs Actual
$targetQuery = "SELECT 
    COALESCE(SUM(target_qty), 0) as total_target,
    COALESCE(SUM(production_qty), 0) as total_actual
FROM production_targets 
WHERE DATE(target_date) BETWEEN ? AND ?";
$stmt = $conn->prepare($targetQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$targetStats = $stmt->get_result()->fetch_assoc();
$stmt->close();

$targetAchievement = $targetStats['total_target'] > 0 
    ? ($targetStats['total_actual'] / $targetStats['total_target']) * 100 
    : 0;

// ==================== OPERATIONAL METRICS ====================

// Roll Transfers
try {
    $transferQuery = "SELECT 
        COUNT(*) as total_transfers,
        COALESCE(SUM(amount_kg), 0) as total_transfer_weight
    FROM roll_transfer 
    WHERE DATE(date_time) BETWEEN ? AND ?";
    $stmt = $conn->prepare($transferQuery);
    $stmt->bind_param('ss', $dateFrom, $dateTo);
    $stmt->execute();
    $transferStats = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Exception $e) {
    error_log("Roll Transfer Query Error: " . $e->getMessage());
    $transferStats = ['total_transfers' => 0, 'total_transfer_weight' => 0];
}

// Active Users
$activeUsersQuery = "SELECT COUNT(*) as active_users FROM new_user WHERE role != 'admin'";
$activeUsers = $conn->query($activeUsersQuery)->fetch_assoc();

// ==================== TREND DATA (Last 7 Days with Data) ====================

// Daily Production Trend - Get last 7 days that have actual data
$dailyProductionQuery = "SELECT 
    DATE(date_time) as prod_date,
    COUNT(*) as roll_count,
    COALESCE(SUM(total_weight), 0) as total_weight
FROM roll_entry 
WHERE is_deleted = 0 
AND DATE(date_time) BETWEEN ? AND ?
GROUP BY DATE(date_time)
ORDER BY prod_date DESC
LIMIT 7";
$stmt = $conn->prepare($dailyProductionQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$dailyProductionTemp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
// Reverse to show chronologically
$dailyProduction = array_reverse($dailyProductionTemp);

// Daily QC Trend - Get last 7 days that have actual data
$dailyQCQuery = "SELECT 
    DATE(date_time) as qc_date,
    COUNT(*) as total_checks,
    SUM(CASE WHEN qc_result = 'Pass' THEN 1 ELSE 0 END) as passed,
    SUM(CASE WHEN qc_result = 'Fail' THEN 1 ELSE 0 END) as failed
FROM qc_entries 
WHERE DATE(date_time) BETWEEN ? AND ?
GROUP BY DATE(date_time)
ORDER BY qc_date DESC
LIMIT 7";
$stmt = $conn->prepare($dailyQCQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$dailyQCTemp = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
// Reverse to show chronologically
$dailyQC = array_reverse($dailyQCTemp);

// Calculate waste percentage
$wastePercentage = ($rollProduction['total_weight'] > 0) 
    ? ($totalScrapQty / $rollProduction['total_weight']) * 100 
    : 0;

// Overall Production Value Score (OPV) - show 0 when no data exists
$hasOpvData = false;
if (($qcStats['total_inspections'] ?? 0) > 0) { $hasOpvData = true; }
if (($targetStats['total_target'] ?? 0) > 0) { $hasOpvData = true; }
if (($fiberToRoll['total_fiber_used'] ?? 0) > 0 || ($fiberToRoll['total_roll_produced'] ?? 0) > 0) { $hasOpvData = true; }
if (($rollProduction['total_weight'] ?? 0) > 0) { $hasOpvData = true; }
if ($totalScrapQty > 0) { $hasOpvData = true; }

if ($hasOpvData) {
    // Weighted average of key metrics, capped at 100
$opvScore = min((
    (min($qcPassRate, 100) * 0.3) + 
    (min($conversionEfficiency, 100) * 0.25) + 
    (min($targetAchievement, 100) * 0.25) + 
    (min($recycleRate, 100) * 0.1) + 
    (min((100 - $wastePercentage), 100) * 0.1)
), 100);
} else {
    $opvScore = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Management KPI Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px; 
            color: #2c3e50; 
            min-height: 100vh;
        }
        .dashboard-container { 
            max-width: 1900px; 
            margin: 0 auto; 
        }
        
        /* Header */
        .dashboard-header {
            background: white;
            border-radius: 15px;
            padding: 25px 35px;
            margin-bottom: 25px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-left h1 {
            font-size: 2em;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .header-left .subtitle {
            color: #7f8c8d;
            font-size: 0.95em;
        }
        .header-right {
            display: flex;
            gap: 15px;
            align-items: center;
        }
        
        /* Filters */
        .filters {
            background: white;
            border-radius: 12px;
            padding: 20px 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            display: flex;
            gap: 15px;
            align-items: end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        .filter-group label {
            font-size: 0.85em;
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
        }
        .filter-group input {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 0.95em;
        }
        .filter-btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        .filter-btn:hover {
            background: #2980b9;
            transform: translateY(-2px);
        }
        
        /* OPV Score Hero Card */
        .opv-hero {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            padding: 40px;
            margin-bottom: 25px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            color: white;
            text-align: center;
        }
        .opv-score {
            font-size: 5em;
            font-weight: 800;
            margin: 20px 0;
            text-shadow: 2px 2px 10px rgba(0,0,0,0.2);
        }
        .opv-label {
            font-size: 1.5em;
            font-weight: 600;
            opacity: 0.95;
        }
        .opv-description {
            margin-top: 15px;
            font-size: 0.95em;
            opacity: 0.9;
        }
        
        /* KPI Grid */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .kpi-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            position: relative;
            overflow: hidden;
            transition: all 0.3s;
        }
        .kpi-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
        }
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #667eea, #764ba2);
        }
        .kpi-card.green::before { background: linear-gradient(90deg, #11998e, #38ef7d); }
        .kpi-card.orange::before { background: linear-gradient(90deg, #f39c12, #e67e22); }
        .kpi-card.red::before { background: linear-gradient(90deg, #e74c3c, #c0392b); }
        .kpi-card.blue::before { background: linear-gradient(90deg, #3498db, #2980b9); }
        .kpi-card.purple::before { background: linear-gradient(90deg, #8e44ad, #9b59b6); }
        
        .kpi-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        .kpi-title {
            font-size: 0.9em;
            color: #7f8c8d;
            font-weight: 600;
            text-transform: uppercase;
        }
        .kpi-icon {
            font-size: 2em;
            opacity: 0.15;
        }
        .kpi-value {
            font-size: 2.5em;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        .kpi-subtitle {
            font-size: 0.85em;
            color: #95a5a6;
        }
        .kpi-trend {
            display: inline-block;
            margin-top: 10px;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
        }
        .kpi-trend.up {
            background: #d5f4e6;
            color: #27ae60;
        }
        .kpi-trend.down {
            background: #fadbd8;
            color: #e74c3c;
        }
        
        /* Charts Section */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }
        .chart-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .chart-title {
            font-size: 1.2em;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
        
        /* Table Styles */
        .table-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        .table-title {
            font-size: 1.3em;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #555;
            font-size: 0.9em;
        }
        tr:hover {
            background: #f8f9fa;
        }
        
        @media print {
            body { background: white; }
            .filters, .filter-btn { display: none; }
        }
        
        /* Tablet/Medium Screen Responsive Styles */
        @media (max-width: 1024px) {
            .filter-btn {
                padding: 8px 16px;
                font-size: 0.85em;
                font-weight: 500;
            }
            .filters {
                flex-wrap: wrap;
                gap: 10px;
            }
            .filter-group {
                flex: 1 1 auto;
                min-width: 150px;
            }
        }
        
        @media (max-width: 768px) {
            .filter-btn {
                padding: 8px 14px;
                font-size: 0.8em;
            }
            .filters {
                flex-direction: column;
                align-items: stretch;
            }
            .filter-group {
                width: 100%;
            }
            .filter-group input {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    
    <!-- Header -->
    <div class="dashboard-header">
        <div class="header-left">
            <p style="font-size: 0.85em; color: #7f8c8d; margin-top: 5px;">
                <i class="fas fa-sync-alt" style="color: #27ae60;"></i> <span id="lastUpdateTime">Last updated: <?php echo date('g:i:s A'); ?></span>
            </p>
        </div>
        <div class="header-right">
            <button onclick="window.print()" class="filter-btn" style="background: #27ae60;">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
    
    <!-- Date Filters -->
    <form method="GET" action="">
        <div class="filters">
            <div class="filter-group">
                <label><i class="fas fa-calendar"></i> From Date</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" required>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-calendar"></i> To Date</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" required>
            </div>
            <button type="submit" class="filter-btn">
                <i class="fas fa-filter"></i> Apply Filter
            </button>
            <a href="management_kpi_dashboard.php" class="filter-btn" style="background: #95a5a6; text-decoration: none; display: inline-block; line-height: 1.5;">
                <i class="fas fa-redo"></i> Reset
            </a>
        </div>
    </form>
    
    <!-- OPV Score Hero -->
    <div class="opv-hero">
        <div class="opv-label">Overall Performance Value (OPV)</div>
        <div class="opv-score"><?php echo number_format($opvScore, 1); ?>%</div>
        <div class="opv-description">
            Composite score based on Quality (30%), Efficiency (25%), Target Achievement (25%), Recycle Rate (10%), and Waste Control (10%)
        </div>
    </div>
    
    <!-- KPI Cards Grid -->
    <div class="kpi-grid">
        
        <!-- Production KPIs -->
        <div class="kpi-card blue">
            <div class="kpi-header">
                <div class="kpi-title">Total Roll Production</div>
                <i class="fas fa-industry kpi-icon"></i>
            </div>
            <div class="kpi-value"><?php echo number_format((float)($rollProduction['total_rolls'] ?? 0)); ?></div>
            <div class="kpi-subtitle"><?php echo number_format((float)($rollProduction['total_weight'] ?? 0), 2); ?> kg produced</div>
        </div>
        
        <div class="kpi-card green">
            <div class="kpi-header">
                <div class="kpi-title">Conversion Efficiency</div>
                <i class="fas fa-sync-alt kpi-icon"></i>
            </div>
            <div class="kpi-value"><?php echo number_format((float)$conversionEfficiency, 1); ?>%</div>
            <div class="kpi-subtitle">Fiber to Roll conversion rate</div>
        </div>
        
        <div class="kpi-card purple">
            <div class="kpi-header">
                <div class="kpi-title">QC Pass Rate</div>
                <i class="fas fa-check-circle kpi-icon"></i>
            </div>
            <div class="kpi-value"><?php echo number_format((float)$qcPassRate, 1); ?>%</div>
            <div class="kpi-subtitle"><?php echo number_format((float)($qcStats['passed'] ?? 0)); ?> passed / <?php echo number_format((float)($qcStats['total_inspections'] ?? 0)); ?> total</div>
        </div>
        
        <div class="kpi-card orange">
            <div class="kpi-header">
                <div class="kpi-title">Target Achievement</div>
                <i class="fas fa-bullseye kpi-icon"></i>
            </div>
            <div class="kpi-value"><?php echo number_format((float)$targetAchievement, 1); ?>%</div>
            <div class="kpi-subtitle"><?php echo number_format((float)($targetStats['total_actual'] ?? 0)); ?> / <?php echo number_format((float)($targetStats['total_target'] ?? 0)); ?> pcs</div>
        </div>
        
        <!-- Waste & Recycle -->
        <div class="kpi-card red">
            <div class="kpi-header">
                <div class="kpi-title">Total Scrap</div>
                <i class="fas fa-trash-alt kpi-icon"></i>
            </div>
            <div class="kpi-value"><?php echo number_format((float)$totalScrapQty, 2); ?></div>
            <div class="kpi-subtitle"><?php echo $totalScrapRecords; ?> scrap records</div>
        </div>
        
        <div class="kpi-card green">
            <div class="kpi-header">
                <div class="kpi-title">Recycle Rate</div>
                <i class="fas fa-recycle kpi-icon"></i>
            </div>
            <div class="kpi-value"><?php echo number_format((float)$recycleRate, 1); ?>%</div>
            <div class="kpi-subtitle"><?php echo number_format((float)($recycleStats['total_recycled_qty'] ?? 0), 2); ?> kg recycled</div>
        </div>
        
        <!-- Inventory & Stock -->
        <div class="kpi-card">
            <div class="kpi-header">
                <div class="kpi-title">FG Stock</div>
                <i class="fas fa-boxes kpi-icon"></i>
            </div>
            <div class="kpi-value"><?php echo number_format($fgStock['total_items']); ?></div>
            <div class="kpi-subtitle"><?php echo number_format($fgStock['total_stock_weight'], 2); ?> kg in stock</div>
        </div>
        
        <div class="kpi-card blue">
            <div class="kpi-header">
                <div class="kpi-title">FG Deliveries</div>
                <i class="fas fa-truck kpi-icon"></i>
            </div>
            <div class="kpi-value"><?php echo number_format($fgDelivery['total_deliveries']); ?></div>
            <div class="kpi-subtitle"><?php echo number_format($fgDelivery['total_delivered_weight'], 2); ?> kg delivered</div>
        </div>
        
        <!-- Operational -->
        <div class="kpi-card purple">
            <div class="kpi-header">
                <div class="kpi-title">CNC Production</div>
                <i class="fas fa-cut kpi-icon"></i>
            </div>
            <div class="kpi-value"><?php echo number_format($cncProduction['total_cnc']); ?></div>
            <div class="kpi-subtitle"><?php echo number_format($cncProduction['total_cnc_qty'], 2); ?> qty processed</div>
        </div>
        
        <div class="kpi-card orange">
            <div class="kpi-header">
                <div class="kpi-title">Roll Transfers</div>
                <i class="fas fa-truck-moving kpi-icon"></i>
            </div>
            <div class="kpi-value"><?php echo number_format($transferStats['total_transfers']); ?></div>
            <div class="kpi-subtitle"><?php echo number_format($transferStats['total_transfer_weight'], 2); ?> kg transferred</div>
        </div>
        
        <div class="kpi-card green">
            <div class="kpi-header">
                <div class="kpi-title">Active Projects</div>
                <i class="fas fa-project-diagram kpi-icon"></i>
            </div>
            <div class="kpi-value"><?php echo number_format($activeProjects['total_projects']); ?></div>
            <div class="kpi-subtitle">Currently in progress</div>
        </div>
        
        <div class="kpi-card blue">
            <div class="kpi-header">
                <div class="kpi-title">System Users</div>
                <i class="fas fa-users kpi-icon"></i>
            </div>
            <div class="kpi-value"><?php echo number_format($activeUsers['active_users']); ?></div>
            <div class="kpi-subtitle">Active user accounts</div>
        </div>
        
    </div>
    
    <!-- Charts Section -->
    <div class="charts-grid">
        
        <!-- Daily Production Trend -->
        <div class="chart-card">
            <div class="chart-title">
                <i class="fas fa-chart-area"></i> Daily Production Trend (Last 7 Days with Data)
            </div>
            <div class="chart-container">
                <canvas id="productionTrendChart"></canvas>
            </div>
        </div>
        
        <!-- Daily QC Pass/Fail Trend -->
        <div class="chart-card">
            <div class="chart-title">
                <i class="fas fa-chart-bar"></i> QC Pass/Fail Trend (Last 7 Days with Data)
            </div>
            <div class="chart-container">
                <canvas id="qcTrendChart"></canvas>
            </div>
        </div>
        
        <!-- Scrap by Type -->
        <div class="chart-card">
            <div class="chart-title">
                <i class="fas fa-chart-pie"></i> Scrap Distribution by Type
            </div>
            <div class="chart-container">
                <canvas id="scrapTypeChart"></canvas>
            </div>
        </div>
        
        <!-- Defects by Stage -->
        <div class="chart-card">
            <div class="chart-title">
                <i class="fas fa-chart-line"></i> Defects by Production Stage
            </div>
            <div class="chart-container">
                <canvas id="defectStageChart"></canvas>
            </div>
        </div>
        
    </div>
    
    <!-- Detailed Tables -->
    <?php if (!empty($defectsByStage)): ?>
    <div class="table-card">
        <div class="table-title">
            <i class="fas fa-exclamation-triangle"></i> Defect Analysis by Stage
        </div>
        <table>
            <thead>
                <tr>
                    <th>QC Stage</th>
                    <th>Defect Count</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $totalDefects = array_sum(array_column($defectsByStage, 'defect_count'));
                foreach ($defectsByStage as $defect): 
                    $percentage = $totalDefects > 0 ? ($defect['defect_count'] / $totalDefects) * 100 : 0;
                ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($defect['qc_stage']); ?></strong></td>
                        <td><?php echo number_format($defect['defect_count']); ?></td>
                        <td><?php echo number_format($percentage, 1); ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($scrapStats)): ?>
    <div class="table-card">
        <div class="table-title">
            <i class="fas fa-trash-alt"></i> Scrap Summary by Type
        </div>
        <table>
            <thead>
                <tr>
                    <th>Scrap Type</th>
                    <th>Count</th>
                    <th>Total Quantity</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($scrapStats as $scrap): 
                    $percentage = $totalScrapQty > 0 ? ($scrap['total_scrap_qty'] / $totalScrapQty) * 100 : 0;
                ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($scrap['scrap_type']); ?></strong></td>
                        <td><?php echo number_format($scrap['type_count']); ?></td>
                        <td><?php echo number_format($scrap['total_scrap_qty'], 2); ?> kg</td>
                        <td><?php echo number_format($percentage, 1); ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
</div>

<script>
console.log('Daily Production Data:', <?php echo json_encode($dailyProduction); ?>);
console.log('Daily QC Data:', <?php echo json_encode($dailyQC); ?>);
console.log('Scrap Stats:', <?php echo json_encode($scrapStats); ?>);
console.log('Defects by Stage:', <?php echo json_encode($defectsByStage); ?>);

// Daily Production Trend Chart
const productionCtx = document.getElementById('productionTrendChart');
if (productionCtx) {
    const productionData = <?php echo json_encode($dailyProduction); ?>;
    if (productionData && productionData.length > 0) {
        new Chart(productionCtx, {
            type: 'line',
            data: {
                labels: productionData.map(d => d.prod_date),
                datasets: [{
                    label: 'Roll Count',
                    data: productionData.map(d => d.roll_count),
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true }
                },
                scales: {
                    y: { beginAtZero: true }
                }
            }
        });
    } else {
        productionCtx.parentElement.innerHTML = '<div style="padding: 50px; text-align: center; color: #95a5a6;">No production data available</div>';
    }
}

// QC Trend Chart
const qcCtx = document.getElementById('qcTrendChart');
if (qcCtx) {
    const qcData = <?php echo json_encode($dailyQC); ?>;
    if (qcData && qcData.length > 0) {
        new Chart(qcCtx, {
            type: 'bar',
            data: {
                labels: qcData.map(d => d.qc_date),
                datasets: [
                    {
                        label: 'Passed',
                        data: qcData.map(d => parseInt(d.passed) || 0),
                        backgroundColor: '#27ae60'
                    },
                    {
                        label: 'Failed',
                        data: qcData.map(d => parseInt(d.failed) || 0),
                        backgroundColor: '#e74c3c'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'top' }
                },
                scales: {
                    y: { beginAtZero: true, stacked: true },
                    x: { stacked: true }
                }
            }
        });
    } else {
        qcCtx.parentElement.innerHTML = '<div style="padding: 50px; text-align: center; color: #95a5a6;">No QC data available</div>';
    }
}

// Scrap Type Chart
const scrapCtx = document.getElementById('scrapTypeChart');
if (scrapCtx) {
    const scrapData = <?php echo json_encode($scrapStats); ?>;
    if (scrapData && scrapData.length > 0) {
        new Chart(scrapCtx, {
            type: 'doughnut',
            data: {
                labels: scrapData.map(d => d.scrap_type),
                datasets: [{
                    data: scrapData.map(d => parseFloat(d.total_scrap_qty) || 0),
                    backgroundColor: [
                        '#3498db', '#e74c3c', '#f39c12', '#27ae60', '#9b59b6', 
                        '#1abc9c', '#e67e22', '#34495e'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right' }
                }
            }
        });
    } else {
        scrapCtx.parentElement.innerHTML = '<div style="padding: 50px; text-align: center; color: #95a5a6;">No scrap data available</div>';
    }
}

// Defect Stage Chart
const defectCtx = document.getElementById('defectStageChart');
if (defectCtx) {
    const defectData = <?php echo json_encode($defectsByStage); ?>;
    if (defectData && defectData.length > 0) {
        new Chart(defectCtx, {
            type: 'bar',
            data: {
                labels: defectData.map(d => d.qc_stage),
                datasets: [{
                    label: 'Defect Count',
                    data: defectData.map(d => parseInt(d.defect_count) || 0),
                    backgroundColor: 'rgba(231, 76, 60, 0.7)',
                    borderColor: '#e74c3c',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { beginAtZero: true }
                }
            }
        });
    } else {
        defectCtx.parentElement.innerHTML = '<div style="padding: 50px; text-align: center; color: #95a5a6;">No defect data available</div>';
    }
}

// ==================== AUTO-REFRESH SYSTEM ====================

// Auto-refresh every 60 seconds - only updates if data changed
const refreshInterval = 60000; // 60 seconds

// Store current data hash to detect changes
let currentDataHash = null;
let isRefreshing = false;

// Function to update last refresh time display
function updateLastRefreshTime() {
    const now = new Date();
    const timeStr = now.toLocaleTimeString('en-US', { hour12: true });
    const lastUpdateEl = document.getElementById('lastUpdateTime');
    if (lastUpdateEl) {
        lastUpdateEl.textContent = `Last updated: ${timeStr}`;
    }
}

// Function to calculate a simple hash of key data on the page
function calculateDataHash() {
    // Get key metric values that would indicate data changes
    const keyElements = [
        document.querySelector('[data-metric="opv"]'),
        document.querySelector('[data-metric="roll-production"]'),
        document.querySelector('[data-metric="fg-production"]'),
        document.querySelector('[data-metric="qc-pass-rate"]')
    ];
    
    let hashString = '';
    keyElements.forEach(el => {
        if (el) {
            hashString += el.textContent.trim();
        }
    });
    
    // Simple hash function
    let hash = 0;
    for (let i = 0; i < hashString.length; i++) {
        const char = hashString.charCodeAt(i);
        hash = ((hash << 5) - hash) + char;
        hash = hash & hash; // Convert to 32bit integer
    }
    return hash;
}

// Function to check for data changes and refresh only if needed
async function checkAndRefreshDashboard() {
    if (isRefreshing) return;
    
    try {
        // Get current date range from URL or form
        const urlParams = new URLSearchParams(window.location.search);
        const dateFrom = urlParams.get('date_from') || '2020-01-01';
        const dateTo = urlParams.get('date_to') || new Date().toISOString().split('T')[0];
        
        // Fetch fresh data from API
        const response = await fetch(`api/management_kpi_api.php?date_from=${encodeURIComponent(dateFrom)}&date_to=${encodeURIComponent(dateTo)}`);
        if (!response.ok) {
            console.log('Failed to fetch updated data');
            return;
        }
        
        const newData = await response.json();
        
        // Calculate hash of new data
        const newDataHash = JSON.stringify(newData).length + (newData.opv || 0) + (newData.roll_production?.total_rolls || 0);
        
        // Compare with current hash
        if (currentDataHash === null) {
            // First run - just store the hash
            currentDataHash = newDataHash;
            updateLastRefreshTime();
            console.log('Initial data hash stored');
            return;
        }
        
        if (newDataHash !== currentDataHash) {
            // Data has changed - reload the page
            console.log('Data changed detected - refreshing dashboard...');
            currentDataHash = newDataHash;
            isRefreshing = true;
            
            // Preserve scroll position in sessionStorage
            sessionStorage.setItem('kpi_dashboard_scrollY', window.scrollY);
            sessionStorage.setItem('kpi_dashboard_scrollX', window.scrollX);
            
            // Reload page
            window.location.reload();
        } else {
            // No changes - just update the timestamp (no reload, no jump!)
            updateLastRefreshTime();
            console.log('No data changes - skipping refresh');
        }
    } catch (error) {
        console.error('Error checking for updates:', error);
        // On error, just update timestamp without reloading
        updateLastRefreshTime();
    }
}

// Initialize auto-refresh on page load
document.addEventListener('DOMContentLoaded', function() {
    // Restore scroll position if it was saved
    const savedScrollY = sessionStorage.getItem('kpi_dashboard_scrollY');
    const savedScrollX = sessionStorage.getItem('kpi_dashboard_scrollX');
    if (savedScrollY !== null && savedScrollX !== null) {
        // Use setTimeout to ensure page is fully rendered
        setTimeout(() => {
            window.scrollTo(parseInt(savedScrollX), parseInt(savedScrollY));
            // Clear saved position after restoring
            sessionStorage.removeItem('kpi_dashboard_scrollY');
            sessionStorage.removeItem('kpi_dashboard_scrollX');
        }, 100);
    }
    
    // Calculate initial data hash
    currentDataHash = calculateDataHash();
    updateLastRefreshTime();
    
    // Start auto-refresh timer - checks for changes every 60 seconds
    setInterval(checkAndRefreshDashboard, refreshInterval);
    console.log('Auto-refresh enabled (checking every 60 seconds) - Only refreshes if data changed');
});
</script>

<script>
// Refresh only when approve/reject actions happen
(function() {
    let isRefreshing = false;
    
    // Function to refresh the dashboard
    function refreshDashboard() {
        if (isRefreshing) return;
        isRefreshing = true;
        
        // Reload the page with cache busting
        window.location.href = window.location.href.split('?')[0] + '?t=' + new Date().getTime();
    }
    
    // Listen for messages from child windows (approval/rejection pages)
    window.addEventListener('message', function(event) {
        // Verify origin for security
        if (event.origin !== window.location.origin) {
            return;
        }
        
        // If message indicates a report was processed (approved/rejected), refresh
        if (event.data && (event.data.type === 'report_processed' || event.data.type === 'report_approved' || event.data.type === 'report_rejected')) {
            // Small delay to ensure database is updated
            setTimeout(function() {
                refreshDashboard();
            }, 300);
        }
    });
})();
</script>

<?php PerformanceMonitor::end('management_kpi_dashboard'); ?>
</body>
</html>


