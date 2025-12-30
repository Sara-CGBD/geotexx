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

$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$lineNo = $_GET['line_no'] ?? '';
$shift = $_GET['shift'] ?? '';

// Query combining CNC, Sewing, and Branding production
$baseQuery = "
SELECT 
    'CNC' COLLATE utf8mb4_unicode_ci as production_type,
    c.date_time,
    CASE WHEN c.shift = 'Day' THEN 'Day' WHEN c.shift = 'Night' THEN 'Night' WHEN HOUR(c.date_time) >= 8 AND HOUR(c.date_time) < 20 THEN 'Day' ELSE 'Night' END COLLATE utf8mb4_unicode_ci as calculated_shift,
    CAST('0' AS CHAR) COLLATE utf8mb4_unicode_ci as line_no,
    COALESCE(p.project_name, 'N/A') COLLATE utf8mb4_unicode_ci as project_name,
    0 as gsm,
    CAST('' AS CHAR) COLLATE utf8mb4_unicode_ci as fiber_type,
    CAST(c.cnc_id AS CHAR) COLLATE utf8mb4_unicode_ci as roll_id,
    c.actual_weight as total_weight,
    CAST(c.bag_size AS CHAR) COLLATE utf8mb4_unicode_ci as batch_number,
    CAST(COALESCE((SELECT full_name FROM new_user WHERE id = c.reporter_id LIMIT 1), (SELECT username FROM new_user WHERE id = c.reporter_id LIMIT 1), (SELECT username FROM users WHERE id = c.reporter_id LIMIT 1), 'Admin') AS CHAR) COLLATE utf8mb4_unicode_ci as operator_name
FROM cnc_entries c
LEFT JOIN projects p ON c.project_id = p.id
WHERE DATE(c.date_time) BETWEEN ? AND ?

UNION ALL

SELECT 
    'Sewing' COLLATE utf8mb4_unicode_ci as production_type,
    s.date_time,
    CASE WHEN s.shift = 'Day' THEN 'Day' WHEN s.shift = 'Night' THEN 'Night' WHEN HOUR(s.date_time) >= 8 AND HOUR(s.date_time) < 20 THEN 'Day' ELSE 'Night' END COLLATE utf8mb4_unicode_ci as calculated_shift,
    CAST(s.line_no AS CHAR) COLLATE utf8mb4_unicode_ci as line_no,
    COALESCE(p.project_name, 'N/A') COLLATE utf8mb4_unicode_ci as project_name,
    0 as gsm,
    CAST('' AS CHAR) COLLATE utf8mb4_unicode_ci as fiber_type,
    CAST(s.swing_id AS CHAR) COLLATE utf8mb4_unicode_ci as roll_id,
    s.sewing_qty as total_weight,
    CAST(CONCAT('Line-', s.line_no) AS CHAR) COLLATE utf8mb4_unicode_ci as batch_number,
    CAST(COALESCE((SELECT full_name FROM new_user WHERE id = s.operator_id LIMIT 1), (SELECT username FROM new_user WHERE id = s.operator_id LIMIT 1), (SELECT username FROM users WHERE id = s.operator_id LIMIT 1), 'Admin') AS CHAR) COLLATE utf8mb4_unicode_ci as operator_name
FROM swing_machine_entry s
LEFT JOIN projects p ON s.project_id = p.id
WHERE DATE(s.date_time) BETWEEN ? AND ?

UNION ALL

SELECT 
    'Branding' COLLATE utf8mb4_unicode_ci as production_type,
    b.date_time,
    CASE WHEN HOUR(b.date_time) >= 8 AND HOUR(b.date_time) < 20 THEN 'Day' ELSE 'Night' END COLLATE utf8mb4_unicode_ci as calculated_shift,
    CAST('0' AS CHAR) COLLATE utf8mb4_unicode_ci as line_no,
    COALESCE(p.project_name, 'N/A') COLLATE utf8mb4_unicode_ci as project_name,
    0 as gsm,
    CAST('' AS CHAR) COLLATE utf8mb4_unicode_ci as fiber_type,
    CAST(b.branding_id AS CHAR) COLLATE utf8mb4_unicode_ci as roll_id,
    b.print_qty as total_weight,
    CAST(b.bag_size AS CHAR) COLLATE utf8mb4_unicode_ci as batch_number,
    CAST(COALESCE((SELECT full_name FROM new_user WHERE id = b.reporter_id LIMIT 1), (SELECT username FROM new_user WHERE id = b.reporter_id LIMIT 1), (SELECT username FROM users WHERE id = b.reporter_id LIMIT 1), b.who_did, 'Admin') AS CHAR) COLLATE utf8mb4_unicode_ci as operator_name
FROM branding_entries b
LEFT JOIN projects p ON b.project_id = p.id
WHERE b.is_deleted = 0 AND DATE(b.date_time) BETWEEN ? AND ?
";

$params = [$dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo];
$types = 'ssssss';

$query = "SELECT * FROM ($baseQuery) as combined WHERE 1=1";

if ($lineNo) {
    $query .= " AND line_no = ?";
    $params[] = $lineNo;
    $types .= 's';
}

if ($shift) {
    $query .= " AND calculated_shift = ?";
    $params[] = $shift;
    $types .= 's';
}

$query .= " ORDER BY date_time DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$productions = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate statistics
$totalEntries = count($productions);
$totalWeight = array_sum(array_column($productions, 'total_weight'));

// Group by shift
$byShift = [];
foreach ($productions as $prod) {
    $shiftVal = $prod['calculated_shift'];
    if (!isset($byShift[$shiftVal])) {
        $byShift[$shiftVal] = ['count' => 0, 'weight' => 0];
    }
    $byShift[$shiftVal]['count']++;
    $byShift[$shiftVal]['weight'] += $prod['total_weight'];
}

// Group by line
$byLine = [];
foreach ($productions as $prod) {
    $line = $prod['line_no'];
    if (!isset($byLine[$line])) {
        $byLine[$line] = ['count' => 0, 'weight' => 0];
    }
    $byLine[$line]['count']++;
    $byLine[$line]['weight'] += $prod['total_weight'];
}

// Group by date
$byDate = [];
foreach ($productions as $prod) {
    $date = date('Y-m-d', strtotime($prod['date_time']));
    if (!isset($byDate[$date])) {
        $byDate[$date] = ['count' => 0, 'weight' => 0, 'day' => 0, 'night' => 0];
    }
    $byDate[$date]['count']++;
    $byDate[$date]['weight'] += $prod['total_weight'];
    if ($prod['calculated_shift'] == 'Day') {
        $byDate[$date]['day'] += $prod['total_weight'];
    } else {
        $byDate[$date]['night'] += $prod['total_weight'];
    }
}
ksort($byDate);

// Group by line and shift
$byLineShift = [];
foreach ($productions as $prod) {
    $key = $prod['line_no'] . ' - ' . $prod['calculated_shift'];
    if (!isset($byLineShift[$key])) {
        $byLineShift[$key] = ['count' => 0, 'weight' => 0, 'line' => $prod['line_no'], 'shift' => $prod['calculated_shift']];
    }
    $byLineShift[$key]['count']++;
    $byLineShift[$key]['weight'] += $prod['total_weight'];
}

$lines = $conn->query("SELECT DISTINCT line_no FROM swing_machine_entry WHERE line_no IS NOT NULL AND line_no != '' ORDER BY line_no")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production Summary Report</title>
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
        
        .charts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(500px, 1fr)); gap: 25px; margin-bottom: 30px; }
        .chart-card { background: #f8f9fa; border-radius: 12px; padding: 25px; }
        .chart-title { font-size: 1.2em; font-weight: 700; color: #2c3e50; margin-bottom: 20px; text-align: center; }
        .chart-container { position: relative; height: 350px; }
        
        .section { margin-bottom: 30px; }
        .section-title { font-size: 1.3em; color: #34495e; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 3px solid #3498db; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 0.9em; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; }
        tr:hover { background: #f8f9fa; }
        
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        
        @media print {
            .filters, .export-btn { display: none; }
            body { background: white; padding: 0; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-cogs"></i> Production Summary Report</h1>
    <p class="subtitle">Daily and shift-wise production quantity per line with trend analysis</p>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($totalEntries); ?></div>
            <div class="stat-label">Total Production Entries</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value"><?php echo number_format($totalWeight, 2); ?> kg</div>
            <div class="stat-label">Total Weight Produced</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-value"><?php echo count($byLine); ?></div>
            <div class="stat-label">Active Production Lines</div>
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
                    <label><i class="fas fa-industry"></i> Line No</label>
                    <select name="line_no">
                        <option value="">All Lines</option>
                        <?php foreach ($lines as $line): ?>
                            <option value="<?php echo htmlspecialchars($line['line_no']); ?>" 
                                <?php echo $lineNo == $line['line_no'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($line['line_no']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-clock"></i> Shift</label>
                    <select name="shift">
                        <option value="">All Shifts</option>
                        <option value="Day" <?php echo $shift == 'Day' ? 'selected' : ''; ?>>Day Shift (8 AM - 7:59 PM)</option>
                        <option value="Night" <?php echo $shift == 'Night' ? 'selected' : ''; ?>>Night Shift (8 PM - 7:59 AM)</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Apply Filter</button>
                    <a href="production_summary_report.php" class="filter-btn reset-btn" style="text-decoration: none; display: inline-block; text-align: center; line-height: 2;">Reset</a>
                </div>
            </div>
        </div>
    </form>
    
    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print</button>
    <button onclick="exportToCSV()" class="export-btn" style="background: #e67e22;"><i class="fas fa-file-csv"></i> Export CSV</button>
    
    <?php if (count($productions) > 0): ?>
    
    <!-- Charts -->
    <div class="charts-grid">
        <div class="chart-card">
            <div class="chart-title">Daily Production Trend</div>
            <div class="chart-container">
                <canvas id="dailyTrendChart"></canvas>
            </div>
        </div>
        
        <div class="chart-card">
            <div class="chart-title">Production by Shift</div>
            <div class="chart-container">
                <canvas id="shiftChart"></canvas>
            </div>
        </div>
        
        <div class="chart-card">
            <div class="chart-title">Production by Line</div>
            <div class="chart-container">
                <canvas id="lineChart"></canvas>
            </div>
        </div>
        
        <div class="chart-card">
            <div class="chart-title">Daily Shift-wise Production</div>
            <div class="chart-container">
                <canvas id="dailyShiftChart"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Summary by Shift -->
    <div class="section">
        <h2 class="section-title"><i class="fas fa-clock"></i> Summary by Shift</h2>
        <table>
            <thead>
                <tr>
                    <th>Shift</th>
                    <th>Entries</th>
                    <th>Total Weight (kg)</th>
                    <th>Average Weight (kg)</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($byShift as $shiftName => $data): ?>
                    <tr>
                        <td>
                            <strong>
                                <i class="fas fa-<?php echo $shiftName == 'Day' ? 'sun' : 'moon'; ?>"></i>
                                <?php echo $shiftName; ?> Shift
                            </strong>
                        </td>
                        <td><?php echo number_format($data['count']); ?></td>
                        <td><?php echo number_format($data['weight'], 2); ?></td>
                        <td><?php echo number_format($data['weight'] / $data['count'], 2); ?></td>
                        <td><?php echo number_format(($data['weight'] / $totalWeight) * 100, 1); ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Summary by Line -->
    <div class="section">
        <h2 class="section-title"><i class="fas fa-industry"></i> Summary by Production Line</h2>
        <table>
            <thead>
                <tr>
                    <th>Line No</th>
                    <th>Entries</th>
                    <th>Total Weight (kg)</th>
                    <th>Average Weight (kg)</th>
                    <th>Percentage</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                uasort($byLine, function($a, $b) { return $b['weight'] <=> $a['weight']; });
                foreach ($byLine as $line => $data): 
                ?>
                    <tr>
                        <td><strong>Line <?php echo htmlspecialchars($line); ?></strong></td>
                        <td><?php echo number_format($data['count']); ?></td>
                        <td><?php echo number_format($data['weight'], 2); ?></td>
                        <td><?php echo number_format($data['weight'] / $data['count'], 2); ?></td>
                        <td><?php echo number_format(($data['weight'] / $totalWeight) * 100, 1); ?>%</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Line-Shift Matrix -->
    <div class="section">
        <h2 class="section-title"><i class="fas fa-table"></i> Line-Shift Performance Matrix</h2>
        <table>
            <thead>
                <tr>
                    <th>Line - Shift</th>
                    <th>Entries</th>
                    <th>Total Weight (kg)</th>
                    <th>Average Weight (kg)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                uasort($byLineShift, function($a, $b) { return $b['weight'] <=> $a['weight']; });
                foreach ($byLineShift as $key => $data): 
                ?>
                    <tr>
                        <td>
                            <strong><?php echo htmlspecialchars($key); ?></strong>
                            <i class="fas fa-<?php echo $data['shift'] == 'Day' ? 'sun' : 'moon'; ?>" 
                               style="color: <?php echo $data['shift'] == 'Day' ? '#f39c12' : '#34495e'; ?>; margin-left: 10px;"></i>
                        </td>
                        <td><?php echo number_format($data['count']); ?></td>
                        <td><?php echo number_format($data['weight'], 2); ?></td>
                        <td><?php echo number_format($data['weight'] / $data['count'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Daily Production Summary -->
    <div class="section">
        <h2 class="section-title"><i class="fas fa-calendar-day"></i> Daily Production Summary</h2>
        <table id="dailyTable">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Total Entries</th>
                    <th>Total Weight (kg)</th>
                    <th>Day Shift (kg)</th>
                    <th>Night Shift (kg)</th>
                    <th>Average Weight (kg)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($byDate as $date => $data): ?>
                    <tr>
                        <td><strong><?php echo date('M d, Y', strtotime($date)); ?></strong></td>
                        <td><?php echo number_format($data['count']); ?></td>
                        <td><?php echo number_format($data['weight'], 2); ?></td>
                        <td><?php echo number_format($data['day'], 2); ?></td>
                        <td><?php echo number_format($data['night'], 2); ?></td>
                        <td><?php echo number_format($data['weight'] / $data['count'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Detailed Production List -->
    <div class="section">
        <h2 class="section-title"><i class="fas fa-list"></i> Detailed Production Entries</h2>
        <table id="productionTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Type</th>
                    <th>Date & Time</th>
                    <th>Shift</th>
                    <th>Line No</th>
                    <th>Project</th>
                    <th>ID</th>
                    <th>Quantity</th>
                    <th>Batch/Bag Size</th>
                    <th>Operator</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($productions as $prod): 
                    $typeColor = $prod['production_type'] == 'CNC' ? '#3498db' : ($prod['production_type'] == 'Sewing' ? '#27ae60' : '#f39c12');
                ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td>
                            <span style="color: <?php echo $typeColor; ?>; font-weight: 600;">
                                <i class="fas fa-<?php echo $prod['production_type'] == 'CNC' ? 'cut' : ($prod['production_type'] == 'Sewing' ? 'sewing-machine' : 'stamp'); ?>"></i>
                                <?php echo $prod['production_type']; ?>
                            </span>
                        </td>
                        <td><?php echo date('M d, Y g:i A', strtotime($prod['date_time'])); ?></td>
                        <td>
                            <i class="fas fa-<?php echo $prod['calculated_shift'] == 'Day' ? 'sun' : 'moon'; ?>" 
                               style="color: <?php echo $prod['calculated_shift'] == 'Day' ? '#f39c12' : '#34495e'; ?>;"></i>
                            <?php echo $prod['calculated_shift']; ?>
                        </td>
                        <td><?php echo $prod['line_no'] != '0' ? htmlspecialchars($prod['line_no']) : '-'; ?></td>
                        <td><?php echo htmlspecialchars($prod['project_name'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($prod['roll_id']); ?></td>
                        <td><strong><?php echo number_format($prod['total_weight'], 2); ?></strong></td>
                        <td><?php echo htmlspecialchars($prod['batch_number']); ?></td>
                        <td><?php echo htmlspecialchars($prod['operator_name']); ?></td>
                    </tr>
                <?php endforeach; ?>
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
<?php if (count($productions) > 0): ?>
// Daily Trend Chart
const dailyCtx = document.getElementById('dailyTrendChart');
new Chart(dailyCtx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_keys($byDate)); ?>,
        datasets: [{
            label: 'Total Weight (kg)',
            data: <?php echo json_encode(array_column($byDate, 'weight')); ?>,
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
        plugins: { legend: { display: true } },
        scales: { y: { beginAtZero: true } }
    }
});

// Shift Chart
const shiftCtx = document.getElementById('shiftChart');
new Chart(shiftCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_keys($byShift)); ?>,
        datasets: [{
            data: <?php echo json_encode(array_column($byShift, 'weight')); ?>,
            backgroundColor: ['#f39c12', '#34495e']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } }
    }
});

// Line Chart
const lineCtx = document.getElementById('lineChart');
new Chart(lineCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($byLine)); ?>,
        datasets: [{
            label: 'Weight (kg)',
            data: <?php echo json_encode(array_column($byLine, 'weight')); ?>,
            backgroundColor: 'rgba(52, 152, 219, 0.7)',
            borderColor: '#3498db',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});

// Daily Shift Chart
const dailyShiftCtx = document.getElementById('dailyShiftChart');
new Chart(dailyShiftCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($byDate)); ?>,
        datasets: [
            {
                label: 'Day Shift',
                data: <?php echo json_encode(array_column($byDate, 'day')); ?>,
                backgroundColor: 'rgba(243, 156, 18, 0.7)',
                borderColor: '#f39c12',
                borderWidth: 2
            },
            {
                label: 'Night Shift',
                data: <?php echo json_encode(array_column($byDate, 'night')); ?>,
                backgroundColor: 'rgba(52, 73, 94, 0.7)',
                borderColor: '#34495e',
                borderWidth: 2
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'top' } },
        scales: { 
            y: { beginAtZero: true, stacked: true },
            x: { stacked: true }
        }
    }
});
<?php endif; ?>

function exportToCSV() {
    const table = document.getElementById('dailyTable');
    if (!table) return;
    
    let csv = [];
    csv.push(['Production Summary Report']);
    csv.push(['Period: <?php echo $dateFrom; ?> to <?php echo $dateTo; ?>']);
    csv.push(['Total Entries: <?php echo $totalEntries; ?>']);
    csv.push(['Total Weight: <?php echo number_format($totalWeight, 2); ?> kg']);
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
    link.setAttribute('download', 'production_summary_' + new Date().toISOString().slice(0,10) + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>


