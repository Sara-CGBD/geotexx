<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'qc_inspector', 'management', 'agm ops', 'tester', 'lab_tester'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$stage = $_GET['stage'] ?? '';

// Performance: Use full datetime strings for index usage (avoid DATE() function)
$dateFromFull = $dateFrom . ' 00:00:00';
$dateToFull = $dateTo . ' 23:59:59';

// Get QC data - Optimized query (avoid DATE() function, add LIMIT)
$query = "SELECT 
    DATE(date_time) as qc_date,
    qc_stage,
    qc_result,
    COUNT(*) as count
FROM qc_entries
WHERE date_time >= ? AND date_time <= ?";

$params = [$dateFromFull, $dateToFull];
$types = 'ss';

if ($stage) {
    $query .= " AND qc_stage = ?";
    $params[] = $stage;
    $types .= 's';
}

$query .= " GROUP BY DATE(date_time), qc_stage, qc_result ORDER BY qc_date DESC LIMIT 1000";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$qcData = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Process data for charts
$dailyTrend = [];
$stageStats = [];
$totalPass = 0;
$totalFail = 0;

foreach ($qcData as $row) {
    $date = $row['qc_date'];
    $stageVal = $row['qc_stage'];
    $resultVal = $row['qc_result'];
    $count = $row['count'];
    
    // Daily trend
    if (!isset($dailyTrend[$date])) {
        $dailyTrend[$date] = ['pass' => 0, 'fail' => 0];
    }
    $dailyTrend[$date][$resultVal == 'Pass' ? 'pass' : 'fail'] += $count;
    
    // Stage stats
    if (!isset($stageStats[$stageVal])) {
        $stageStats[$stageVal] = ['pass' => 0, 'fail' => 0];
    }
    $stageStats[$stageVal][$resultVal == 'Pass' ? 'pass' : 'fail'] += $count;
    
    // Totals
    if ($resultVal == 'Pass') {
        $totalPass += $count;
    } else {
        $totalFail += $count;
    }
}

$totalInspections = $totalPass + $totalFail;
$passRate = $totalInspections > 0 ? ($totalPass / $totalInspections) * 100 : 0;

// Get unique stages for filter - Optimized with LIMIT
$stages = $conn->query("SELECT DISTINCT qc_stage FROM qc_entries WHERE qc_stage IS NOT NULL ORDER BY qc_stage LIMIT 50")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QC Pass/Fail Trend</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1600px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; padding: 25px; color: white; text-align: center; }
        .stat-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-card.red { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); }
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
        
        .chart-card { background: #f8f9fa; border-radius: 12px; padding: 25px; margin-bottom: 30px; }
        .chart-title { font-size: 1.3em; font-weight: 700; color: #2c3e50; margin-bottom: 20px; text-align: center; }
        .chart-container { position: relative; height: 400px; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 0.9em; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        th { background: #34495e; color: white; font-weight: 600; }
        tr:hover { background: #f8f9fa; }
        
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        
        @media print {
            @page {
                size: A4 landscape;
                margin: 10mm;
            }
            .filters, .export-btn { display: none; }
            body { background: white; padding: 0; }
            .container { max-width: 100%; padding: 10px; }
            h1, h2 { font-size: 1.2em; }
            .table-wrapper {
                overflow: visible;
                width: 100%;
            }
            table {
                width: 100% !important;
                min-width: auto !important;
                max-width: 100%;
                font-size: 9px;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            th, td {
                padding: 4px 3px;
                font-size: 9px;
                white-space: normal;
            }
            th {
                font-size: 9px;
                position: static;
            }
            table thead {
                display: table-header-group;
            }
            table tbody {
                display: table-row-group;
            }
            table tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-chart-line"></i> QC Pass/Fail Trend Report</h1>
    <p class="subtitle">Trend of defects across time and production stages</p>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($totalInspections); ?></div>
            <div class="stat-label">Total Inspections</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value"><?php echo number_format($totalPass); ?></div>
            <div class="stat-label">Passed</div>
        </div>
        <div class="stat-card red">
            <div class="stat-value"><?php echo number_format($totalFail); ?></div>
            <div class="stat-label">Failed</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-value"><?php echo number_format($passRate, 1); ?>%</div>
            <div class="stat-label">Pass Rate</div>
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
                    <label><i class="fas fa-layer-group"></i> QC Stage</label>
                    <select name="stage">
                        <option value="">All Stages</option>
                        <?php foreach ($stages as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['qc_stage']); ?>" 
                                <?php echo $stage == $s['qc_stage'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['qc_stage']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Apply Filter</button>
                    <a href="qc_pass_fail_trend.php" class="filter-btn reset-btn" style="text-decoration: none; display: inline-block; text-align: center; line-height: 2;">Reset</a>
                </div>
            </div>
        </div>
    </form>
    
    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print</button>
    <button onclick="exportToCSV()" class="export-btn" style="background: #e67e22;"><i class="fas fa-file-csv"></i> Export CSV</button>
    
    <?php if (count($dailyTrend) > 0): ?>
    <div class="chart-card">
        <div class="chart-title">Daily Pass/Fail Trend</div>
        <div class="chart-container">
            <canvas id="dailyTrendChart"></canvas>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (count($stageStats) > 0): ?>
    <div class="chart-card">
        <div class="chart-title">Pass/Fail by QC Stage</div>
        <div class="chart-container">
            <canvas id="stageChart"></canvas>
        </div>
    </div>
    
    <h2 style="font-size: 1.3em; color: #34495e; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 3px solid #3498db;">
        <i class="fas fa-table"></i> Stage-wise Statistics
    </h2>
    <table id="stageTable">
        <thead>
            <tr>
                <th>#</th>
                <th>QC Stage</th>
                <th>Total Inspections</th>
                <th>Passed</th>
                <th>Failed</th>
                <th>Pass Rate</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $counter = 1;
            foreach ($stageStats as $stageName => $data): 
                $stageTotal = $data['pass'] + $data['fail'];
                $stagePassRate = $stageTotal > 0 ? ($data['pass'] / $stageTotal) * 100 : 0;
            ?>
                <tr>
                    <td><?php echo $counter++; ?></td>
                    <td><strong><?php echo htmlspecialchars($stageName); ?></strong></td>
                    <td><?php echo number_format($stageTotal); ?></td>
                    <td style="color: #27ae60; font-weight: 600;"><?php echo number_format($data['pass']); ?></td>
                    <td style="color: #e74c3c; font-weight: 600;"><?php echo number_format($data['fail']); ?></td>
                    <td><strong><?php echo number_format($stagePassRate, 1); ?>%</strong></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
<?php if (count($dailyTrend) > 0): ?>
// Daily Trend Chart
const dailyCtx = document.getElementById('dailyTrendChart');
new Chart(dailyCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_keys($dailyTrend)); ?>,
        datasets: [
            {
                label: 'Passed',
                data: <?php echo json_encode(array_column($dailyTrend, 'pass')); ?>,
                borderColor: '#27ae60',
                backgroundColor: 'rgba(39, 174, 96, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4
            },
            {
                label: 'Failed',
                data: <?php echo json_encode(array_column($dailyTrend, 'fail')); ?>,
                borderColor: '#e74c3c',
                backgroundColor: 'rgba(231, 76, 60, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4
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
            y: { beginAtZero: true }
        }
    }
});
<?php endif; ?>

<?php if (count($stageStats) > 0): ?>
// Stage Chart
const stageCtx = document.getElementById('stageChart');
new Chart(stageCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($stageStats)); ?>,
        datasets: [
            {
                label: 'Passed',
                data: <?php echo json_encode(array_column($stageStats, 'pass')); ?>,
                backgroundColor: '#27ae60'
            },
            {
                label: 'Failed',
                data: <?php echo json_encode(array_column($stageStats, 'fail')); ?>,
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
<?php endif; ?>

function exportToCSV() {
    const table = document.getElementById('stageTable');
    if (!table) return;
    
    let csv = [];
    csv.push(['QC Pass/Fail Trend Report']);
    csv.push(['Period: <?php echo $dateFrom; ?> to <?php echo $dateTo; ?>']);
    csv.push(['Total Inspections: <?php echo $totalInspections; ?>']);
    csv.push(['Pass Rate: <?php echo number_format($passRate, 1); ?>%']);
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
    link.setAttribute('download', 'qc_pass_fail_trend_' + new Date().toISOString().slice(0,10) + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>


