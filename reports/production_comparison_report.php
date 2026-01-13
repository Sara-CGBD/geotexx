<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'production_user', 'production', 'prod_test', 'management', 'agm ops', 'sewing_test'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Determine which sewing table exists
$sewingTableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
$sewingTable = ($sewingTableCheck && $sewingTableCheck->num_rows > 0) ? 'sewing_machine_entry' : 'swing_machine_entry';

$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$viewMode = $_GET['view_mode'] ?? 'daily'; // daily or weekly

// Get CNC Production by date
$cncQuery = "SELECT 
    DATE(date_time) as prod_date,
    COUNT(*) as count,
    SUM(cutting_roll_quantity) as total_qty
FROM cnc_entries 
WHERE DATE(date_time) BETWEEN ? AND ?
GROUP BY DATE(date_time)
ORDER BY prod_date ASC";
$stmt = $conn->prepare($cncQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$cncData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get Sewing Production by date
$sewingQuery = "SELECT 
    DATE(date_time) as prod_date,
    COUNT(*) as count,
    SUM(sewing_qty) as total_qty
FROM $sewingTable 
WHERE DATE(date_time) BETWEEN ? AND ?
GROUP BY DATE(date_time)
ORDER BY prod_date ASC";
$stmt = $conn->prepare($sewingQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$sewingData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get Branding Production by date
$brandingQuery = "SELECT 
    DATE(date_time) as prod_date,
    COUNT(*) as count,
    SUM(print_qty) as total_qty
FROM branding_entries 
WHERE DATE(date_time) BETWEEN ? AND ?
GROUP BY DATE(date_time)
ORDER BY prod_date ASC";
$stmt = $conn->prepare($brandingQuery);
$stmt->bind_param('ss', $dateFrom, $dateTo);
$stmt->execute();
$brandingData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Merge data by date (only dates with actual production)
$dateRange = [];

foreach ($cncData as $row) {
    $dateKey = $row['prod_date'];
    if (!isset($dateRange[$dateKey])) {
        $dateRange[$dateKey] = [
            'date' => $dateKey,
            'cnc' => 0,
            'sewing' => 0,
            'branding' => 0,
            'total' => 0
        ];
    }
    $dateRange[$dateKey]['cnc'] = (int)($row['total_qty'] ?? 0);
    $dateRange[$dateKey]['total'] += (int)($row['total_qty'] ?? 0);
}

foreach ($sewingData as $row) {
    $dateKey = $row['prod_date'];
    if (!isset($dateRange[$dateKey])) {
        $dateRange[$dateKey] = [
            'date' => $dateKey,
            'cnc' => 0,
            'sewing' => 0,
            'branding' => 0,
            'total' => 0
        ];
    }
    $dateRange[$dateKey]['sewing'] = (int)($row['total_qty'] ?? 0);
    $dateRange[$dateKey]['total'] += (int)($row['total_qty'] ?? 0);
}

foreach ($brandingData as $row) {
    $dateKey = $row['prod_date'];
    if (!isset($dateRange[$dateKey])) {
        $dateRange[$dateKey] = [
            'date' => $dateKey,
            'cnc' => 0,
            'sewing' => 0,
            'branding' => 0,
            'total' => 0
        ];
    }
    $dateRange[$dateKey]['branding'] = (int)($row['total_qty'] ?? 0);
    $dateRange[$dateKey]['total'] += (int)($row['total_qty'] ?? 0);
}

// Sort by date ascending
ksort($dateRange);

// Calculate weekly data if needed
$weeklyData = [];
if ($viewMode == 'weekly') {
    foreach ($dateRange as $dateKey => $data) {
        $weekNum = date('W', strtotime($dateKey));
        $year = date('Y', strtotime($dateKey));
        $weekKey = $year . '-W' . $weekNum;
        
        if (!isset($weeklyData[$weekKey])) {
            $weeklyData[$weekKey] = [
                'week' => 'Week ' . $weekNum . ', ' . $year,
                'cnc' => 0,
                'sewing' => 0,
                'branding' => 0,
                'total' => 0
            ];
        }
        
        $weeklyData[$weekKey]['cnc'] += $data['cnc'];
        $weeklyData[$weekKey]['sewing'] += $data['sewing'];
        $weeklyData[$weekKey]['branding'] += $data['branding'];
        $weeklyData[$weekKey]['total'] += $data['total'];
    }
}

$displayData = $viewMode == 'weekly' ? $weeklyData : $dateRange;

// Calculate totals
$totalCNC = array_sum(array_column($displayData, 'cnc'));
$totalSewing = array_sum(array_column($displayData, 'sewing'));
$totalBranding = array_sum(array_column($displayData, 'branding'));
$grandTotal = $totalCNC + $totalSewing + $totalBranding;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production Comparison Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1800px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); overflow-x: hidden; }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; padding: 25px; color: white; text-align: center; }
        .stat-card.blue { background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); }
        .stat-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-card.orange { background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%); }
        .stat-value { font-size: 2.5em; font-weight: bold; margin-bottom: 5px; }
        .stat-label { font-size: 0.9em; opacity: 0.95; }
        
        .filters { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-size: 0.85em; font-weight: 600; color: #555; margin-bottom: 5px; }
        .filter-group input, .filter-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 0.9em; }
        .filter-btn { background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; }
        .filter-btn:hover { background: #2980b9; }
        .reset-btn { background: #95a5a6; }
        
        .chart-card { background: #f8f9fa; border-radius: 12px; padding: 30px; margin-bottom: 30px; }
        .chart-title { font-size: 1.4em; font-weight: 700; color: #2c3e50; margin-bottom: 25px; text-align: center; }
        .chart-container { position: relative; height: 450px; }
        
        .table-wrapper { overflow-x: auto; width: 100%; margin-bottom: 20px; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9em; min-width: 800px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ecf0f1; white-space: nowrap; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; }
        tr:hover { background: #f8f9fa; }
        td:first-child, th:first-child { position: sticky; left: 0; background: white; z-index: 1; }
        th:first-child { background: #34495e; z-index: 2; }
        tr:hover td:first-child { background: #f8f9fa; }
        
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        
        /* Prevent overlapping when print dialog is open */
        body.printing {
            overflow: hidden !important;
        }
        
        body.printing .container {
            margin: 0 auto !important;
            max-width: 100% !important;
        }
        
        @media print {
            /* COMPREHENSIVE RESET - Prevent ALL overlapping */
            * {
                box-sizing: border-box !important;
                position: static !important;
                float: none !important;
                clear: both !important;
                overflow: visible !important;
                transform: none !important;
                z-index: auto !important;
                top: auto !important;
                left: auto !important;
                right: auto !important;
                bottom: auto !important;
            }
            
            /* Hide all navigation and UI elements */
            nav, aside, .sidebar, header:not(.print-header), footer, .filters, .export-btn { 
                display: none !important; 
            }
            
            html, body { 
                background: white !important; 
                padding: 0 !important; 
                margin: 0 !important;
                font-size: 10px !important;
                width: 100% !important;
                height: auto !important;
                overflow: visible !important;
                position: static !important;
            }
            
            .container { 
                box-shadow: none !important; 
                padding: 10px !important;
                margin: 0 auto !important;
                max-width: 100% !important;
                width: 100% !important;
                position: static !important;
                overflow: visible !important;
                border-radius: 0 !important;
                page-break-inside: avoid;
            }
            
            h1 { 
                font-size: 16px !important; 
                margin: 5px 0 !important; 
                padding: 0 !important;
                page-break-after: avoid;
                position: static !important;
                clear: both !important;
            }
            
            .subtitle { 
                font-size: 10px !important; 
                margin: 2px 0 10px !important; 
                padding: 0 !important;
                position: static !important;
                clear: both !important;
            }
            
            .section-title { 
                font-size: 12px !important; 
                margin: 10px 0 5px !important; 
                padding: 3px 0 !important; 
                page-break-after: avoid;
                position: static !important;
                clear: both !important;
            }
            
            .section { 
                margin-bottom: 15px !important;
                page-break-inside: avoid;
                overflow: visible !important;
                position: static !important;
                clear: both !important;
            }
            
            .chart-card {
                page-break-inside: avoid;
                position: static !important;
                overflow: visible !important;
                margin-bottom: 20px !important;
                padding: 15px !important;
                clear: both !important;
                border-radius: 0 !important;
            }
            
            .chart-title {
                margin-bottom: 10px !important;
                page-break-after: avoid;
            }
            
            table { 
                font-size: 8px !important; 
                width: 100% !important;
                page-break-inside: auto;
                border-collapse: collapse !important;
                margin-bottom: 10px !important;
                position: static !important;
                clear: both !important;
                table-layout: auto !important;
            }
            
            th, td { 
                padding: 4px 3px !important; 
                font-size: 8px !important;
                line-height: 1.2 !important;
                border: 1px solid #ddd !important;
                position: static !important;
                white-space: normal !important;
            }
            
            /* Remove ALL sticky positioning for print */
            td:first-child, th:first-child { 
                position: static !important; 
                background: white !important; 
                z-index: auto !important;
                left: auto !important;
            }
            
            th:first-child { 
                background: #34495e !important; 
                z-index: auto !important;
            }
            
            th { 
                background: #34495e !important;
                color: white !important;
                font-size: 9px !important; 
                font-weight: 600 !important;
                position: static !important;
                top: auto !important;
            }
            
            tr:hover td:first-child, tr:hover { 
                background: white !important;
            }
            
            .chart-container { 
                height: 250px !important; 
                max-height: 250px !important;
                min-height: 250px !important;
                position: static !important;
                overflow: visible !important;
                page-break-inside: avoid;
                clear: both !important;
                width: 100% !important;
            }
            
            canvas {
                max-width: 100% !important;
                height: auto !important;
                position: static !important;
            }
            
            .stats-grid { 
                grid-template-columns: repeat(4, 1fr) !important;
                gap: 8px !important;
                margin-bottom: 15px !important;
                position: static !important;
                clear: both !important;
            }
            
            .stat-card { 
                padding: 10px 8px !important;
                page-break-inside: avoid;
                margin-bottom: 0 !important;
                position: static !important;
                clear: both !important;
            }
            
            .stat-value { 
                font-size: 1.4em !important; 
                margin-bottom: 3px !important;
            }
            
            .stat-label { 
                font-size: 0.8em !important; 
            }
            
            tr { 
                page-break-inside: avoid;
                position: static !important;
            }
            
            thead { 
                display: table-header-group !important;
                position: static !important;
            }
            
            tfoot { 
                display: table-footer-group !important;
                position: static !important;
            }
            
            .table-wrapper {
                overflow: visible !important;
                position: static !important;
                width: 100% !important;
                clear: both !important;
            }
            
            /* Ensure no element overlaps */
            div, section, article {
                clear: both !important;
                position: static !important;
            }
            
            @page {
                size: A4 landscape;
                margin: 0.5cm;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-chart-line"></i> Production Comparison Report</h1>
    <p class="subtitle">Compare daily or weekly production trends across CNC, Sewing, and Branding</p>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($grandTotal, 2); ?></div>
            <div class="stat-label">Total Production</div>
        </div>
        <div class="stat-card blue">
            <div class="stat-value"><?php echo number_format($totalCNC, 2); ?></div>
            <div class="stat-label">CNC Production</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value"><?php echo number_format($totalSewing); ?></div>
            <div class="stat-label">Sewing Production</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-value"><?php echo number_format($totalBranding); ?></div>
            <div class="stat-label">Branding Production</div>
        </div>
    </div>
    
    <form method="GET" action="">
        <div class="filters">
            <div class="filter-row">
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> From Date</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" required>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> To Date</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" required>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-chart-bar"></i> View Mode</label>
                    <select name="view_mode">
                        <option value="daily" <?php echo $viewMode == 'daily' ? 'selected' : ''; ?>>Daily Comparison</option>
                        <option value="weekly" <?php echo $viewMode == 'weekly' ? 'selected' : ''; ?>>Weekly Comparison</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Apply Filter</button>
                    <a href="production_comparison_report.php" class="filter-btn reset-btn" style="text-decoration: none; display: inline-block; text-align: center; line-height: 2;">Reset</a>
                </div>
            </div>
        </div>
    </form>
    
    <button onclick="handlePrint()" class="export-btn"><i class="fas fa-print"></i> Print</button>
    <button onclick="exportToCSV()" class="export-btn" style="background: #e67e22;"><i class="fas fa-file-csv"></i> Export CSV</button>
    
    <?php if (count($displayData) > 0): ?>
    
    <!-- Main Trend Chart -->
    <div class="chart-card">
        <div class="chart-title">
            <i class="fas fa-chart-line"></i> 
            <?php echo ucfirst($viewMode); ?> Production Trend Comparison
        </div>
        <div class="chart-container">
            <canvas id="trendChart"></canvas>
        </div>
    </div>
    
    <!-- Stacked Area Chart -->
    <div class="chart-card">
        <div class="chart-title">
            <i class="fas fa-chart-area"></i> 
            <?php echo ucfirst($viewMode); ?> Production Breakdown (Stacked)
        </div>
        <div class="chart-container">
            <canvas id="stackedChart"></canvas>
        </div>
    </div>
    
    <!-- Data Table -->
    <h2 style="font-size: 1.3em; color: #34495e; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 3px solid #3498db;">
        <i class="fas fa-table"></i> <?php echo ucfirst($viewMode); ?> Production Data
    </h2>
    <div class="table-wrapper">
    <table id="comparisonTable">
        <thead>
            <tr>
                <th><?php echo $viewMode == 'weekly' ? 'Week' : 'Date'; ?></th>
                <th>CNC (kg)</th>
                <th>Sewing (pcs)</th>
                <th>Branding (pcs)</th>
                <th>Total</th>
                <th>% CNC</th>
                <th>% Sewing</th>
                <th>% Branding</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($displayData as $key => $data): 
                $total = $data['total'];
                $cncPct = $total > 0 ? ($data['cnc'] / $total) * 100 : 0;
                $sewingPct = $total > 0 ? ($data['sewing'] / $total) * 100 : 0;
                $brandingPct = $total > 0 ? ($data['branding'] / $total) * 100 : 0;
            ?>
                <tr>
                    <td>
                        <strong>
                            <?php echo $viewMode == 'weekly' ? $data['week'] : date('M d, Y', strtotime($data['date'])); ?>
                        </strong>
                    </td>
                    <td><?php echo number_format($data['cnc'], 2); ?></td>
                    <td><?php echo number_format($data['sewing']); ?></td>
                    <td><?php echo number_format($data['branding']); ?></td>
                    <td><strong><?php echo number_format($total, 2); ?></strong></td>
                    <td><?php echo number_format($cncPct, 1); ?>%</td>
                    <td><?php echo number_format($sewingPct, 1); ?>%</td>
                    <td><?php echo number_format($brandingPct, 1); ?>%</td>
                </tr>
            <?php endforeach; ?>
            <tr style="background: #f8f9fa; font-weight: bold;">
                <td>TOTAL</td>
                <td><?php echo number_format($totalCNC, 2); ?></td>
                <td><?php echo number_format($totalSewing); ?></td>
                <td><?php echo number_format($totalBranding); ?></td>
                <td><strong><?php echo number_format($grandTotal, 2); ?></strong></td>
                <td><?php echo $grandTotal > 0 ? number_format(($totalCNC / $grandTotal) * 100, 1) : 0; ?>%</td>
                <td><?php echo $grandTotal > 0 ? number_format(($totalSewing / $grandTotal) * 100, 1) : 0; ?>%</td>
                <td><?php echo $grandTotal > 0 ? number_format(($totalBranding / $grandTotal) * 100, 1) : 0; ?>%</td>
            </tr>
        </tbody>
    </table>
    </div>
    
    <?php else: ?>
    <div style="text-align: center; padding: 60px; color: #95a5a6;">
        <i class="fas fa-inbox" style="font-size: 4em; margin-bottom: 20px;"></i>
        <p style="font-size: 1.2em;">No production data found for the selected period.</p>
    </div>
    <?php endif; ?>
</div>

<script>
// Print handler to ensure proper layout
function handlePrint() {
    // Add print class to body
    document.body.classList.add('printing');
    
    // Force all charts to resize first
    if (window.trendChart) {
        window.trendChart.resize();
    }
    if (window.stackedChart) {
        window.stackedChart.resize();
    }
    
    // Wait for charts to render, then print
    setTimeout(function() {
        // Force another resize to ensure proper sizing
        if (window.trendChart) window.trendChart.resize();
        if (window.stackedChart) window.stackedChart.resize();
        
        // Trigger print
        window.print();
        
        // Remove print class after printing
        setTimeout(function() {
            document.body.classList.remove('printing');
            // Restore chart sizes
            if (window.trendChart) window.trendChart.resize();
            if (window.stackedChart) window.stackedChart.resize();
        }, 1000);
    }, 300);
}

// Handle beforeprint event to ensure charts are ready
window.addEventListener('beforeprint', function() {
    // Force chart resize for print
    if (window.trendChart) {
        window.trendChart.resize();
        setTimeout(() => window.trendChart.resize(), 50);
    }
    if (window.stackedChart) {
        window.stackedChart.resize();
        setTimeout(() => window.stackedChart.resize(), 50);
    }
});

// Handle afterprint event
window.addEventListener('afterprint', function() {
    // Restore chart sizes
    if (window.trendChart) window.trendChart.resize();
    if (window.stackedChart) window.stackedChart.resize();
    document.body.classList.remove('printing');
});

<?php if (count($displayData) > 0): ?>
// Trend Comparison Chart
const trendCtx = document.getElementById('trendChart');
window.trendChart = new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($viewMode == 'weekly' ? array_column($displayData, 'week') : array_keys($displayData)); ?>,
        datasets: [
            {
                label: 'CNC (kg)',
                data: <?php echo json_encode(array_column($displayData, 'cnc')); ?>,
                borderColor: '#3498db',
                backgroundColor: 'rgba(52, 152, 219, 0.1)',
                borderWidth: 3,
                tension: 0.4,
                fill: false
            },
            {
                label: 'Sewing (pcs)',
                data: <?php echo json_encode(array_column($displayData, 'sewing')); ?>,
                borderColor: '#27ae60',
                backgroundColor: 'rgba(39, 174, 96, 0.1)',
                borderWidth: 3,
                tension: 0.4,
                fill: false
            },
            {
                label: 'Branding (pcs)',
                data: <?php echo json_encode(array_column($displayData, 'branding')); ?>,
                borderColor: '#f39c12',
                backgroundColor: 'rgba(243, 156, 18, 0.1)',
                borderWidth: 3,
                tension: 0.4,
                fill: false
            },
            {
                label: 'Total',
                data: <?php echo json_encode(array_column($displayData, 'total')); ?>,
                borderColor: '#9b59b6',
                backgroundColor: 'rgba(155, 89, 182, 0.1)',
                borderWidth: 4,
                tension: 0.4,
                fill: false,
                borderDash: [5, 5]
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top' },
            tooltip: {
                mode: 'index',
                intersect: false
            }
        },
        scales: {
            y: { 
                beginAtZero: true,
                title: {
                    display: true,
                    text: 'Production Quantity'
                }
            },
            x: {
                title: {
                    display: true,
                    text: '<?php echo ucfirst($viewMode); ?> Period'
                }
            }
        }
    }
});

// Stacked Area Chart
const stackedCtx = document.getElementById('stackedChart');
window.stackedChart = new Chart(stackedCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($viewMode == 'weekly' ? array_column($displayData, 'week') : array_keys($displayData)); ?>,
        datasets: [
            {
                label: 'CNC',
                data: <?php echo json_encode(array_column($displayData, 'cnc')); ?>,
                backgroundColor: 'rgba(52, 152, 219, 0.7)',
                borderColor: '#3498db',
                borderWidth: 2
            },
            {
                label: 'Sewing',
                data: <?php echo json_encode(array_column($displayData, 'sewing')); ?>,
                backgroundColor: 'rgba(39, 174, 96, 0.7)',
                borderColor: '#27ae60',
                borderWidth: 2
            },
            {
                label: 'Branding',
                data: <?php echo json_encode(array_column($displayData, 'branding')); ?>,
                backgroundColor: 'rgba(243, 156, 18, 0.7)',
                borderColor: '#f39c12',
                borderWidth: 2
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
            y: { 
                beginAtZero: true,
                stacked: true,
                title: {
                    display: true,
                    text: 'Production Quantity'
                }
            },
            x: { 
                stacked: true,
                title: {
                    display: true,
                    text: '<?php echo ucfirst($viewMode); ?> Period'
                }
            }
        }
    }
});
<?php endif; ?>

function exportToCSV() {
    const table = document.getElementById('comparisonTable');
    if (!table) return;
    
    let csv = [];
    csv.push(['Production Comparison Report']);
    csv.push(['Period: <?php echo $dateFrom; ?> to <?php echo $dateTo; ?>']);
    csv.push(['View Mode: <?php echo ucfirst($viewMode); ?>']);
    csv.push(['Total Production: <?php echo number_format($grandTotal, 2); ?>']);
    csv.push([]);
    
    const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent);
    csv.push(headers.join(','));
    
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cols = Array.from(row.querySelectorAll('td')).map(td => {
            let text = td.textContent.trim();
            if (text.includes(',') || text.includes('"')) {
                text = '"' + text.replace(/"/g, '""') + '"';
            }
            return text;
        });
        csv.push(cols.join(','));
    });
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', 'production_comparison_<?php echo $viewMode; ?>_' + new Date().toISOString().slice(0,10) + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>


