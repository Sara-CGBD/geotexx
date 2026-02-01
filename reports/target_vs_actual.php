<?php
session_start();
require_once '../forms/security_config.php';

// Security/session checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}
if (SecurityConfig::checkSessionTimeout()) {
    session_destroy();
    header("Location: ../login.html?error=timeout");
    exit();
}
SecurityConfig::updateSessionActivity();
if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    session_destroy();
    header("Location: ../login.html?error=disabled");
    exit();
}

date_default_timezone_set('Asia/Dhaka');

// Database connection (XAMPP default: root with no password)
$conn = new mysqli("127.0.0.1", "root", "", "geobagg", 3307);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get filters
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today
$module_filter = $_GET['module'] ?? '';
$period_filter = $_GET['period'] ?? '';

// Fetch all modules
$modules_query = "SELECT id, module_name FROM modules ORDER BY id";
$modules_result = $conn->query($modules_query);
$modules = [];
while ($row = $modules_result->fetch_assoc()) {
    $modules[$row['id']] = $row['module_name'];
}

// Build query with filters
$where_clauses = ["pt.target_date BETWEEN ? AND ?"];
$params = [$date_from, $date_to];
$types = "ss";

if (!empty($module_filter)) {
    $where_clauses[] = "pt.module_id = ?";
    $params[] = $module_filter;
    $types .= "i";
}

if (!empty($period_filter)) {
    $where_clauses[] = "pt.target_period = ?";
    $params[] = $period_filter;
    $types .= "s";
}

$where_sql = implode(" AND ", $where_clauses);

// Main query - get target vs actual data
$query = "
    SELECT 
        pt.target_id,
        pt.module_id,
        m.module_name,
        pt.target_period,
        pt.target_date,
        pt.target_qty,
        pt.production_qty,
        ROUND((pt.production_qty / pt.target_qty) * 100, 1) as achievement_percent,
        CASE 
            WHEN pt.production_qty >= pt.target_qty THEN 'Achieved'
            WHEN pt.production_qty >= (pt.target_qty * 0.8) THEN 'Near Target'
            ELSE 'Below Target'
        END as status
    FROM production_targets pt
    INNER JOIN modules m ON pt.module_id = m.id
    WHERE $where_sql
    ORDER BY pt.target_date DESC, m.module_name
";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

// Calculate summary statistics
$total_targets = 0;
$total_achieved = 0;
$by_module = [];
$by_date = [];
$by_status = ['Achieved' => 0, 'Near Target' => 0, 'Below Target' => 0];

foreach ($data as $row) {
    $total_targets++;
    if ($row['status'] === 'Achieved') $total_achieved++;
    
    // Group by status
    $by_status[$row['status']]++;
    
    // Group by module for chart
    if (!isset($by_module[$row['module_name']])) {
        $by_module[$row['module_name']] = [
            'target' => 0,
            'actual' => 0,
            'count' => 0
        ];
    }
    $by_module[$row['module_name']]['target'] += $row['target_qty'];
    $by_module[$row['module_name']]['actual'] += $row['production_qty'];
    $by_module[$row['module_name']]['count']++;
    
    // Group by date for line chart
    $date_key = $row['target_date'];
    if (!isset($by_date[$date_key])) {
        $by_date[$date_key] = [];
    }
    if (!isset($by_date[$date_key][$row['module_name']])) {
        $by_date[$date_key][$row['module_name']] = [
            'target' => 0,
            'actual' => 0
        ];
    }
    $by_date[$date_key][$row['module_name']]['target'] += $row['target_qty'];
    $by_date[$date_key][$row['module_name']]['actual'] += $row['production_qty'];
}

// Sort dates
ksort($by_date);

$achievement_rate = $total_targets > 0 ? round(($total_achieved / $total_targets) * 100, 1) : 0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Target vs Actual Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }
        .subtitle {
            color: #7f8c8d;
            margin-bottom: 30px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            color: white;
        }
        .stat-card.blue { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-card.green { background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%); }
        .stat-card.orange { background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%); }
        .stat-card.red { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); }
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            margin: 10px 0;
        }
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        .filters {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        .filter-group label {
            font-weight: 600;
            margin-bottom: 5px;
            color: #2c3e50;
        }
        .filter-group select,
        .filter-group input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #3498db;
            color: white;
        }
        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
        .chart-container {
            margin: 30px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .chart-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: #2c3e50;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .badge {
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        .action-buttons {
            margin: 20px 0;
            display: flex;
            gap: 10px;
        }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-bullseye"></i> Target vs Actual Report</h1>
    <p class="subtitle">Monitor production performance against targets</p>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <a href="../index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print"></i> Print
        </button>
        <button onclick="exportToCSV()" class="btn btn-primary">
            <i class="fas fa-file-csv"></i> Export CSV
        </button>
    </div>

    <!-- Summary Statistics -->
    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="stat-label">Total Targets</div>
            <div class="stat-value" id="total_targets"><?php echo $total_targets; ?></div>
        </div>
        <div class="stat-card green">
            <div class="stat-label">Achieved</div>
            <div class="stat-value" id="achieved_count"><?php echo $by_status['Achieved']; ?></div>
        </div>
        <div class="stat-card orange">
            <div class="stat-label">Near Target</div>
            <div class="stat-value" id="near_target_count"><?php echo $by_status['Near Target']; ?></div>
        </div>
        <div class="stat-card red">
            <div class="stat-label">Below Target</div>
            <div class="stat-value" id="below_target_count"><?php echo $by_status['Below Target']; ?></div>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="filters" id="filterForm">
        <div class="filter-group">
            <label>Date From:</label>
            <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" onchange="applyFilters()">
        </div>
        <div class="filter-group">
            <label>Date To:</label>
            <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" onchange="applyFilters()">
        </div>
        <div class="filter-group">
            <label>Module:</label>
            <select id="module" name="module" onchange="applyFilters()">
                <option value="">All Modules</option>
                <?php foreach ($modules as $id => $name): ?>
                    <option value="<?php echo $id; ?>" <?php echo ($module_filter == $id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Period:</label>
            <select id="period" name="period" onchange="applyFilters()">
                <option value="">All Periods</option>
                <option value="Daily" <?php echo ($period_filter === 'Daily') ? 'selected' : ''; ?>>Daily</option>
                <option value="Weekly" <?php echo ($period_filter === 'Weekly') ? 'selected' : ''; ?>>Weekly</option>
                <option value="Monthly" <?php echo ($period_filter === 'Monthly') ? 'selected' : ''; ?>>Monthly</option>
            </select>
        </div>
        <div class="filter-group" style="align-self: flex-end;">
            <button type="button" class="btn btn-primary" style="width: 100%;" onclick="applyFilters()">
                <i class="fas fa-filter"></i> Apply
            </button>
        </div>
        <div class="filter-group" style="align-self: flex-end;">
            <button type="button" class="btn" style="width: 100%; background: #95a5a6; color: white;" onclick="resetFilters()">
                <i class="fas fa-undo"></i> Reset
            </button>
        </div>
    </form>

    <!-- Loading Message -->
    <div id="loadingMessage" style="display: none; text-align: center; padding: 20px; background: #e8f5e9; border-radius: 8px; margin: 20px 0;">
        <i class="fas fa-spinner fa-spin"></i> Loading data...
    </div>

    <!-- Per Module Performance Line Chart -->
    <div class="chart-container">
        <h3 class="chart-title"><i class="fas fa-chart-line"></i> Per Module Performance (Date vs Achievement)</h3>
        <canvas id="modulePerformanceChart" height="80"></canvas>
    </div>

    <!-- Target vs Actual by Module Bar Chart -->
    <div class="chart-container">
        <h3 class="chart-title"><i class="fas fa-chart-bar"></i> Target vs Actual by Module</h3>
        <canvas id="targetActualChart" height="80"></canvas>
    </div>

    <!-- Detailed Data Table -->
    <h3 style="margin-top: 40px; margin-bottom: 20px;">
        <i class="fas fa-table"></i> Detailed Target vs Actual Data
    </h3>
    
    <?php if (empty($data)): ?>
        <div style="text-align: center; padding: 40px; color: #7f8c8d;">
            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 20px;"></i>
            <p>No target data found for the selected filters.</p>
        </div>
    <?php else: ?>
        <table id="dataTable">
            <thead>
                <tr>
                    <th>Target ID</th>
                    <th>Date</th>
                    <th>Module</th>
                    <th>Period</th>
                    <th>Target Qty</th>
                    <th>Actual Qty</th>
                    <th>Achievement %</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $row): ?>
                    <tr>
                        <td>TARGET-<?php echo str_pad($row['target_id'], 4, '0', STR_PAD_LEFT); ?></td>
                        <td><?php echo date('M d, Y', strtotime($row['target_date'])); ?></td>
                        <td><?php echo htmlspecialchars($row['module_name']); ?></td>
                        <td><?php echo htmlspecialchars($row['target_period']); ?></td>
                        <td><?php echo number_format($row['target_qty']); ?></td>
                        <td><?php echo number_format($row['production_qty']); ?></td>
                        <td><?php echo $row['achievement_percent']; ?>%</td>
                        <td>
                            <?php
                            $badge_class = 'badge-danger';
                            if ($row['status'] === 'Achieved') $badge_class = 'badge-success';
                            elseif ($row['status'] === 'Near Target') $badge_class = 'badge-warning';
                            ?>
                            <span class="badge <?php echo $badge_class; ?>">
                                <?php echo $row['status']; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
// Prepare data for charts
const moduleNames = <?php echo json_encode(array_values($modules)); ?>;
const moduleData = <?php echo json_encode($by_module); ?>;
const dateData = <?php echo json_encode($by_date); ?>;

// Chart 1: Target vs Actual by Module (Bar Chart)
const targetData = [];
const actualData = [];
Object.keys(moduleData).forEach(module => {
    targetData.push(moduleData[module].target);
    actualData.push(moduleData[module].actual);
});

const ctx1 = document.getElementById('targetActualChart').getContext('2d');
new Chart(ctx1, {
    type: 'bar',
    data: {
        labels: Object.keys(moduleData),
        datasets: [
            {
                label: 'Target',
                data: targetData,
                backgroundColor: 'rgba(52, 152, 219, 0.7)',
                borderColor: 'rgba(52, 152, 219, 1)',
                borderWidth: 1
            },
            {
                label: 'Actual',
                data: actualData,
                backgroundColor: 'rgba(46, 204, 113, 0.7)',
                borderColor: 'rgba(46, 204, 113, 1)',
                borderWidth: 1
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: 'Quantity'
                }
            }
        },
        plugins: {
            legend: {
                display: true,
                position: 'top'
            },
            title: {
                display: false
            }
        }
    }
});

// Chart 2: Per Module Performance Over Time (Line Chart)
const dates = Object.keys(dateData);
const lineDatasets = [];

// Generate a color for each module
const colors = [
    '#3498db', '#e74c3c', '#2ecc71', '#f39c12', '#9b59b6',
    '#1abc9c', '#34495e', '#e67e22', '#95a5a6'
];

// Get unique modules from the data
const uniqueModules = new Set();
dates.forEach(date => {
    Object.keys(dateData[date]).forEach(module => {
        uniqueModules.add(module);
    });
});

// Create a dataset for each module
let colorIndex = 0;
uniqueModules.forEach(moduleName => {
    const achievementData = dates.map(date => {
        if (dateData[date][moduleName]) {
            const target = dateData[date][moduleName].target;
            const actual = dateData[date][moduleName].actual;
            return target > 0 ? ((actual / target) * 100).toFixed(1) : 0;
        }
        return null;
    });
    
    lineDatasets.push({
        label: moduleName,
        data: achievementData,
        borderColor: colors[colorIndex % colors.length],
        backgroundColor: colors[colorIndex % colors.length] + '20',
        borderWidth: 2,
        fill: false,
        tension: 0.4
    });
    colorIndex++;
});

const ctx2 = document.getElementById('modulePerformanceChart').getContext('2d');
new Chart(ctx2, {
    type: 'line',
    data: {
        labels: dates.map(date => {
            const d = new Date(date);
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        }),
        datasets: lineDatasets
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: 'Achievement (%)'
                },
                ticks: {
                    callback: function(value) {
                        return value + '%';
                    }
                }
            },
            x: {
                title: {
                    display: true,
                    text: 'Date'
                }
            }
        },
        plugins: {
            legend: {
                display: true,
                position: 'top'
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': ' + context.parsed.y + '%';
                    }
                }
            }
        }
    }
});

// Export to CSV function
function exportToCSV() {
    const table = document.getElementById('dataTable');
    let csv = [];
    
    // Headers
    const headers = [];
    table.querySelectorAll('thead th').forEach(th => {
        headers.push(th.textContent);
    });
    csv.push(headers.join(','));
    
    // Data rows
    table.querySelectorAll('tbody tr').forEach(tr => {
        const row = [];
        tr.querySelectorAll('td').forEach(td => {
            row.push('"' + td.textContent.trim().replace(/"/g, '""') + '"');
        });
        csv.push(row.join(','));
    });
    
    // Download
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'target_vs_actual_' + new Date().toISOString().split('T')[0] + '.csv';
    a.click();
}

// AJAX functionality
let performanceChart = null;

function applyFilters() {
    const dateFrom = document.getElementById('date_from').value;
    const dateTo = document.getElementById('date_to').value;
    const module = document.getElementById('module').value;
    const period = document.getElementById('period').value;
    
    const loadingMessage = document.getElementById('loadingMessage');
    loadingMessage.style.display = 'block';
    
    // Build API URL
    const params = new URLSearchParams({
        date_from: dateFrom,
        date_to: dateTo
    });
    
    if (module) params.append('module', module);
    if (period) params.append('period', period);
    
    const apiUrl = `api/target_vs_actual_data.php?${params.toString()}`;
    
    fetch(apiUrl)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateSummaryCards(data.summary);
                updateTable(data.data);
                updateCharts(data.by_module, data.by_date);
            } else {
                alert('Error loading data: ' + (data.error || 'Unknown error'));
            }
            loadingMessage.style.display = 'none';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to load data. Please try again.');
            loadingMessage.style.display = 'none';
        });
}

function updateSummaryCards(summary) {
    document.getElementById('total_targets').textContent = summary.total_targets;
    document.getElementById('achieved_count').textContent = summary.achieved_count;
    document.getElementById('near_target_count').textContent = summary.near_target_count;
    document.getElementById('below_target_count').textContent = summary.below_target_count;
}

function updateTable(data) {
    const tbody = document.querySelector('#dataTable tbody');
    
    if (data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;">No data found for selected filters</td></tr>';
        return;
    }
    
    tbody.innerHTML = data.map(row => {
        const statusClass = row.status === 'Achieved' ? 'status-achieved' : 
                           row.status === 'Near Target' ? 'status-near' : 'status-below';
        
        return `
            <tr>
                <td>${row.target_id}</td>
                <td>${row.module_name}</td>
                <td>${row.target_period}</td>
                <td>${row.target_date}</td>
                <td>${parseFloat(row.target_qty).toLocaleString('en-BD')}</td>
                <td>${parseFloat(row.production_qty).toLocaleString('en-BD')}</td>
                <td>${row.achievement_percent}%</td>
                <td><span class="${statusClass}">${row.status}</span></td>
            </tr>
        `;
    }).join('');
}

function updateCharts(byModule, byDate) {
    // Update module performance chart
    const moduleLabels = Object.keys(byModule);
    const targetData = moduleLabels.map(module => byModule[module].target);
    const actualData = moduleLabels.map(module => byModule[module].actual);
    
    if (performanceChart) {
        performanceChart.destroy();
    }
    
    const ctx = document.getElementById('modulePerformanceChart').getContext('2d');
    performanceChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: moduleLabels,
            datasets: [{
                label: 'Target',
                data: targetData,
                borderColor: 'rgba(52, 152, 219, 1)',
                backgroundColor: 'rgba(52, 152, 219, 0.1)',
                tension: 0.4
            }, {
                label: 'Actual',
                data: actualData,
                borderColor: 'rgba(46, 204, 113, 1)',
                backgroundColor: 'rgba(46, 204, 113, 0.1)',
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Quantity'
                    }
                }
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                }
            }
        }
    });
}

function resetFilters() {
    document.getElementById('date_from').value = '<?php echo date('Y-m-01'); ?>';
    document.getElementById('date_to').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('module').value = '';
    document.getElementById('period').value = '';
    
    applyFilters();
}
</script>
</body>
</html>


