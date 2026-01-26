<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'production_user', 'management', 'agm ops'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$machineId = $_GET['machine_id'] ?? '';
$shift = $_GET['shift'] ?? '';

// Get recycle efficiency data by machine
$machineQuery = "SELECT 
    sr.machine_id,
    COUNT(DISTINCT sr.scrap_id) as scrap_batches_processed,
    COUNT(*) as total_operations,
    SUM(sr.recycled_qty) as total_recycled,
    AVG(sr.recycled_qty) as avg_per_operation,
    MIN(sr.recycled_qty) as min_recycled,
    MAX(sr.recycled_qty) as max_recycled
FROM scrap_recycle sr
WHERE DATE(sr.recycled_at) BETWEEN ? AND ?";

$params = [$dateFrom, $dateTo];
$types = 'ss';

if ($machineId) {
    $machineQuery .= " AND sr.machine_id = ?";
    $params[] = $machineId;
    $types .= 's';
}

$machineQuery .= " GROUP BY sr.machine_id ORDER BY total_recycled DESC";

$stmt = $conn->prepare($machineQuery);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$machineData = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get daily efficiency data
$dailyQuery = "SELECT 
    DATE(sr.recycled_at) as recycle_date,
    COUNT(*) as operations,
    SUM(sr.recycled_qty) as daily_recycled,
    COUNT(DISTINCT sr.machine_id) as machines_used
FROM scrap_recycle sr
WHERE DATE(sr.recycled_at) BETWEEN ? AND ?";

$dailyParams = [$dateFrom, $dateTo];
$dailyTypes = 'ss';

if ($machineId) {
    $dailyQuery .= " AND sr.machine_id = ?";
    $dailyParams[] = $machineId;
    $dailyTypes .= 's';
}

$dailyQuery .= " GROUP BY DATE(sr.recycled_at) ORDER BY recycle_date DESC";

$stmt = $conn->prepare($dailyQuery);
$stmt->bind_param($dailyTypes, ...$dailyParams);
$stmt->execute();
$result = $stmt->get_result();
$dailyData = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate overall statistics
$totalRecycled = array_sum(array_column($machineData, 'total_recycled'));
$totalOperations = array_sum(array_column($machineData, 'total_operations'));
$avgEfficiency = $totalOperations > 0 ? round($totalRecycled / $totalOperations, 2) : 0;
$activeMachines = count($machineData);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recycle Efficiency Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1800px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; padding: 25px; color: white; text-align: center; }
        .stat-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-card.blue { background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); }
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
        
        .chart-container { background: #f8f9fa; border-radius: 12px; padding: 25px; margin-bottom: 30px; }
        .chart-title { font-size: 1.2em; font-weight: 700; color: #2c3e50; margin-bottom: 20px; text-align: center; }
        .chart-canvas { position: relative; height: 350px; max-width: 900px; margin: 0 auto; }
        
        .table-wrapper { overflow-x: auto; margin-bottom: 20px; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 12px; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; font-size: 11px; }
        tr:hover { background: #f8f9fa; }
        
        .badge { padding: 5px 12px; border-radius: 4px; font-size: 0.85em; font-weight: 600; display: inline-block; }
        .badge-high { background: #d5f4e6; color: #27ae60; }
        .badge-medium { background: #fff3cd; color: #f39c12; }
        .badge-low { background: #f8d7da; color: #e74c3c; }
        
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
    <h1><i class="fas fa-tachometer-alt"></i> Recycle Efficiency Report</h1>
    <p class="subtitle">Monitor machine performance and recycling operations efficiency</p>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card green">
            <div class="stat-value"><?php echo number_format($totalRecycled, 2); ?></div>
            <div class="stat-label">Total Recycled (kg)</div>
        </div>
        <div class="stat-card blue">
            <div class="stat-value"><?php echo $totalOperations; ?></div>
            <div class="stat-label">Total Operations</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-value"><?php echo number_format($avgEfficiency, 2); ?></div>
            <div class="stat-label">Avg per Operation (kg)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $activeMachines; ?></div>
            <div class="stat-label">Active Machines</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters">
        <form method="GET">
            <div class="filter-row">
                <div class="filter-group">
                    <label>Date From:</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="filter-group">
                    <label>Date To:</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="filter-group">
                    <label>Machine ID:</label>
                    <select name="machine_id">
                        <option value="">All Machines</option>
                        <option value="1" <?php echo ($machineId == '1') ? 'selected' : ''; ?>>Machine 1</option>
                        <option value="2" <?php echo ($machineId == '2') ? 'selected' : ''; ?>>Machine 2</option>
                        <option value="3" <?php echo ($machineId == '3') ? 'selected' : ''; ?>>Machine 3</option>
                        <option value="4" <?php echo ($machineId == '4') ? 'selected' : ''; ?>>Machine 4</option>
                        <option value="5" <?php echo ($machineId == '5') ? 'selected' : ''; ?>>Machine 5</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="filter-btn"><i class="fas fa-search"></i> Apply</button>
                </div>
                <div class="filter-group">
                    <a href="recycle_efficiency_report.php" class="filter-btn reset-btn" style="text-decoration: none; display: inline-block; text-align: center;"><i class="fas fa-redo"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>

    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print Report</button>

    <!-- Chart -->
    <div class="chart-container">
        <div class="chart-title">Recycling Performance by Machine</div>
        <div class="chart-canvas">
            <canvas id="machineChart"></canvas>
        </div>
    </div>

    <!-- Machine Performance Table -->
    <div class="table-wrapper">
        <h2 style="margin-bottom: 20px; color: #2c3e50;">
            <i class="fas fa-cogs"></i> Machine Performance Summary
        </h2>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Machine ID</th>
                    <th>Scrap Batches Processed</th>
                    <th>Total Operations</th>
                    <th>Total Recycled (kg)</th>
                    <th>Avg per Operation (kg)</th>
                    <th>Min (kg)</th>
                    <th>Max (kg)</th>
                    <th>Efficiency Rating</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($machineData as $machine): 
                    $efficiency = $machine['avg_per_operation'];
                    $badgeClass = $efficiency >= 10 ? 'badge-high' : ($efficiency >= 5 ? 'badge-medium' : 'badge-low');
                ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><strong>Machine <?php echo htmlspecialchars($machine['machine_id']); ?></strong></td>
                        <td><?php echo number_format($machine['scrap_batches_processed']); ?></td>
                        <td><?php echo number_format($machine['total_operations']); ?></td>
                        <td style="color: #27ae60;"><strong><?php echo number_format($machine['total_recycled'], 2); ?> kg</strong></td>
                        <td><?php echo number_format($machine['avg_per_operation'], 2); ?> kg</td>
                        <td><?php echo number_format($machine['min_recycled'], 2); ?> kg</td>
                        <td><?php echo number_format($machine['max_recycled'], 2); ?> kg</td>
                        <td>
                            <span class="badge <?php echo $badgeClass; ?>">
                                <?php 
                                if ($efficiency >= 10) echo 'Excellent';
                                else if ($efficiency >= 5) echo 'Good';
                                else echo 'Needs Improvement';
                                ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($machineData)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: #7f8c8d;">
                            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 10px; opacity: 0.5;"></i>
                            <div>No recycling data found</div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Daily Performance Table -->
    <div class="table-wrapper">
        <h2 style="margin-bottom: 20px; color: #2c3e50;">
            <i class="fas fa-calendar-alt"></i> Daily Recycling Performance
        </h2>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Operations</th>
                    <th>Machines Used</th>
                    <th>Total Recycled (kg)</th>
                    <th>Avg per Operation (kg)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($dailyData as $daily): 
                    $dailyAvg = $daily['operations'] > 0 ? $daily['daily_recycled'] / $daily['operations'] : 0;
                ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><strong><?php echo date('M d, Y', strtotime($daily['recycle_date'])); ?></strong></td>
                        <td><?php echo number_format($daily['operations']); ?></td>
                        <td><?php echo number_format($daily['machines_used']); ?></td>
                        <td style="color: #27ae60;"><strong><?php echo number_format($daily['daily_recycled'], 2); ?> kg</strong></td>
                        <td><?php echo number_format($dailyAvg, 2); ?> kg</td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($dailyData)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: #7f8c8d;">
                            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 10px; opacity: 0.5;"></i>
                            <div>No daily data found</div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Machine Performance Chart
const ctx = document.getElementById('machineChart');
if (ctx) {
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_map(function($m) { return 'Machine ' . $m['machine_id']; }, $machineData)); ?>,
            datasets: [
                {
                    label: 'Total Recycled (kg)',
                    data: <?php echo json_encode(array_column($machineData, 'total_recycled')); ?>,
                    backgroundColor: 'rgba(17, 153, 142, 0.7)',
                    borderColor: 'rgba(17, 153, 142, 1)',
                    borderWidth: 2,
                    yAxisID: 'y'
                },
                {
                    label: 'Operations Count',
                    data: <?php echo json_encode(array_column($machineData, 'total_operations')); ?>,
                    backgroundColor: 'rgba(52, 152, 219, 0.7)',
                    borderColor: 'rgba(52, 152, 219, 1)',
                    borderWidth: 2,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top'
                }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Recycled Quantity (kg)'
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    grid: {
                        drawOnChartArea: false
                    },
                    title: {
                        display: true,
                        text: 'Operations Count'
                    }
                }
            }
        }
    });
}
</script>
</body>
</html>

