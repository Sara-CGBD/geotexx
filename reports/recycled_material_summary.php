<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'production_user', 'management', 'agm ops', 'recycle_user'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$scrapType = $_GET['scrap_type'] ?? '';
$machineId = $_GET['machine_id'] ?? '';

// Main query
$query = "SELECT 
    sr.id,
    sr.recycle_id,
    sr.scrap_id,
    sr.recycled_qty,
    sr.machine_id,
    sr.remarks,
    sr.recycled_at,
    s.scrap_type,
    s.scrap_category,
    s.scrap_product,
    s.qty as original_scrap_qty,
    s.reference_number,
    s.cutting_batch
FROM scrap_recycle sr
LEFT JOIN scrap s ON sr.scrap_id = s.id
WHERE DATE(sr.recycled_at) BETWEEN ? AND ?";

$params = [$dateFrom, $dateTo];
$types = 'ss';

if ($scrapType) {
    $query .= " AND s.scrap_type = ?";
    $params[] = $scrapType;
    $types .= 's';
}

if ($machineId) {
    $query .= " AND sr.machine_id = ?";
    $params[] = $machineId;
    $types .= 's';
}

$query .= " ORDER BY sr.recycled_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$recycleData = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate statistics
$totalRecycled = array_sum(array_column($recycleData, 'recycled_qty'));
$totalRecords = count($recycleData);

// Group by scrap type
$byType = [];
foreach ($recycleData as $record) {
    $type = $record['scrap_type'] ?? 'Unknown';
    if (!isset($byType[$type])) {
        $byType[$type] = ['qty' => 0, 'count' => 0];
    }
    $byType[$type]['qty'] += $record['recycled_qty'];
    $byType[$type]['count']++;
}

// Group by machine
$byMachine = [];
foreach ($recycleData as $record) {
    $machine = $record['machine_id'] ?? 'Unknown';
    if (!isset($byMachine[$machine])) {
        $byMachine[$machine] = ['qty' => 0, 'count' => 0];
    }
    $byMachine[$machine]['qty'] += $record['recycled_qty'];
    $byMachine[$machine]['count']++;
}

// Get filter options
$scrapTypes = $conn->query("SELECT DISTINCT scrap_type FROM scrap WHERE scrap_type IS NOT NULL ORDER BY scrap_type")->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recycled Material Summary</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1800px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; padding: 25px; color: white; text-align: center; }
        .stat-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-card.purple { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
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
        .charts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 25px; margin-bottom: 30px; }
        .chart-box { background: #f8f9fa; border-radius: 12px; padding: 20px; }
        .chart-canvas { position: relative; height: 300px; }
        
        .table-wrapper { overflow-x: auto; margin-bottom: 20px; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; min-width: 1200px; font-size: 12px; }
        th, td { padding: 10px 8px; text-align: left; border-bottom: 1px solid #ecf0f1; white-space: nowrap; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; font-size: 11px; }
        tr:hover { background: #f8f9fa; }
        
        .badge { padding: 4px 10px; border-radius: 4px; font-size: 0.85em; font-weight: 600; }
        .badge-green { background: #d5f4e6; color: #27ae60; }
        .badge-blue { background: #d6eaf8; color: #3498db; }
        
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        
        @media print {
            .filters, .export-btn { display: none; }
            body { background: white; padding: 0; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-recycle"></i> Recycled Material Summary</h1>
    <p class="subtitle">Track and analyze recycled scrap materials</p>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card green">
            <div class="stat-value"><?php echo number_format($totalRecycled, 2); ?></div>
            <div class="stat-label">Total Recycled (kg)</div>
        </div>
        <div class="stat-card purple">
            <div class="stat-value"><?php echo $totalRecords; ?></div>
            <div class="stat-label">Total Records</div>
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
                    <label>Scrap Type:</label>
                    <select name="scrap_type">
                        <option value="">All Types</option>
                        <?php foreach($scrapTypes as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['scrap_type']); ?>" 
                                    <?php echo ($scrapType == $type['scrap_type']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['scrap_type']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
                    <a href="recycled_material_summary.php" class="filter-btn reset-btn" style="text-decoration: none; display: inline-block; text-align: center;"><i class="fas fa-redo"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>

    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print Report</button>

    <!-- Charts -->
    <div class="charts-grid">
        <!-- By Type Chart -->
        <div class="chart-box">
            <div class="chart-title">Recycled Quantity by Type</div>
            <div class="chart-canvas">
                <canvas id="typeChart"></canvas>
            </div>
        </div>
        
        <!-- By Machine Chart -->
        <div class="chart-box">
            <div class="chart-title">Recycled Quantity by Machine</div>
            <div class="chart-canvas">
                <canvas id="machineChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Detailed Table -->
    <div class="table-wrapper">
        <h2 style="margin-bottom: 20px; color: #2c3e50;">
            <i class="fas fa-table"></i> Recycle Details (<?php echo $totalRecords; ?> records)
        </h2>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Recycle ID</th>
                    <th>Date</th>
                    <th>Scrap ID</th>
                    <th>Scrap Type</th>
                    <th>Category</th>
                    <th>Reference No.</th>
                    <th>CNC Batch</th>
                    <th>Original Qty</th>
                    <th>Recycled Qty</th>
                    <th>Machine ID</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($recycleData as $record): 
                ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><strong><?php echo htmlspecialchars($record['recycle_id'] ?? 'N/A'); ?></strong></td>
                        <td><?php echo date('M d, Y h:i A', strtotime($record['recycled_at'])); ?></td>
                        <td>Scrap#<?php echo htmlspecialchars($record['scrap_id'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($record['scrap_type'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($record['scrap_category'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($record['reference_number'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($record['cutting_batch'] ?? 'N/A'); ?></td>
                        <td><?php echo number_format($record['original_scrap_qty'] ?? 0, 2); ?> kg</td>
                        <td><strong style="color: #27ae60;"><?php echo number_format($record['recycled_qty'], 2); ?> kg</strong></td>
                        <td><span class="badge badge-blue">Machine <?php echo htmlspecialchars($record['machine_id'] ?? 'N/A'); ?></span></td>
                        <td><?php echo htmlspecialchars($record['remarks'] ?? '-'); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recycleData)): ?>
                    <tr>
                        <td colspan="12" style="text-align: center; padding: 40px; color: #7f8c8d;">
                            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 10px; opacity: 0.5;"></i>
                            <div>No recycled materials found</div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Type Chart
const typeCtx = document.getElementById('typeChart');
if (typeCtx) {
    new Chart(typeCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_keys($byType)); ?>,
            datasets: [{
                label: 'Recycled Quantity (kg)',
                data: <?php echo json_encode(array_column($byType, 'qty')); ?>,
                backgroundColor: 'rgba(17, 153, 142, 0.8)',
                borderColor: 'rgba(17, 153, 142, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true }
            }
        }
    });
}

// Machine Chart
const machineCtx = document.getElementById('machineChart');
if (machineCtx) {
    new Chart(machineCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_map(function($m) { return 'Machine ' . $m; }, array_keys($byMachine))); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($byMachine, 'qty')); ?>,
                backgroundColor: [
                    'rgba(52, 152, 219, 0.8)',
                    'rgba(46, 204, 113, 0.8)',
                    'rgba(155, 89, 182, 0.8)',
                    'rgba(241, 196, 15, 0.8)',
                    'rgba(231, 76, 60, 0.8)'
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
}
</script>
</body>
</html>

