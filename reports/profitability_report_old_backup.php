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

// Role-based access control
// Allowed: Finance, Admin, AGM OPS
// Finance/Admin: Full Access
// AGM OPS: Summary View Only (project-wise profit margin for decision-making)
$allowed_roles = ['admin', 'finance', 'agm ops', 'agm operations'];
$user_role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>ðŸš« Access Denied</h2>
        <p>You do not have permission to access Profitability Report.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <p>Allowed roles: Admin, Finance, AGM Operations</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

// Check if user has full access (Finance and Admin only)
$has_full_access = in_array($user_role, ['admin', 'finance']);
$is_summary_view = in_array($user_role, ['agm ops', 'agm operations']);

date_default_timezone_set('Asia/Dhaka');

// Database connection
$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get filters
$view_type = $_GET['view_type'] ?? 'product'; // product or project
$start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-3 months'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Query cost analysis data
$profitability_data = [];

if ($view_type === 'product') {
    // Product-wise profitability analysis (from fg table)
    // Note: Shows all products, calculates revenue/costs based on date range
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
} else {
    // Project-wise cost analysis
    $query = "
        SELECT 
            p.id,
            p.project_name as name,
            0 as revenue,
            0 as material_cost,
            COALESCE((SELECT SUM(actual_weight) FROM cnc_entries WHERE project_id = p.id), 0) * 30 as production_cost,
            0 - (COALESCE((SELECT SUM(actual_weight) FROM cnc_entries WHERE project_id = p.id), 0) * 30) as profit,
            0 as profit_margin,
            0 as bom_entries,
            COALESCE((SELECT SUM(actual_weight) FROM cnc_entries WHERE project_id = p.id), 0) as total_qty
        FROM projects p
        WHERE p.is_deleted = 0
        AND DATE(p.created_at) BETWEEN ? AND ?
        GROUP BY p.id, p.project_name
        ORDER BY production_cost DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ss', $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $profitability_data[] = $row;
    }
    
    $stmt->close();
}

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

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Profitability Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .subtitle {
            color: #7f8c8d;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #229954; }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-secondary:hover { background: #7f8c8d; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 18px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            min-width: 0;
        }
        .stat-card.green { background: linear-gradient(135deg, #11998e 0%,rgb(21, 99, 51) 100%); }
        .stat-card.orange { background: linear-gradient(135deg,rgb(129, 6, 74) 0%, #ff6a00 100%); }
        .stat-card.red { background: linear-gradient(135deg,rgb(83, 14, 22) 0%, #f45c43 100%); }
        .stat-card.yellow { background: linear-gradient(135deg,rgb(75, 4, 83) 0%, #f5576c 100%); }
        .stat-card.blue { background: linear-gradient(135deg,rgb(6, 54, 95) 0%, #00f2fe 100%); }
        .stat-label {
            font-size: 12px;
            opacity: 0.9;
            margin-bottom: 6px;
            white-space: nowrap;
        }
        .stat-value {
            font-size: 22px;
            font-weight: 600;
            word-wrap: break-word;
            line-height: 1.2;
            overflow-wrap: break-word;
        }
        
        .filters {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        .filter-group label {
            font-size: 13px;
            color: #555;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .filter-group input,
        .filter-group select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .chart-container {
            margin: 30px 0;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .table-wrapper {
            overflow-x: auto;
            margin-top: 20px;
        }
        table {
            width: 100%;
            min-width: 1000px;
            border-collapse: collapse;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            font-size: 12px;
        }
        thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        th, td {
            padding: 10px 8px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
            white-space: nowrap;
        }
        th {
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        tbody tr:hover {
            background-color: #f8f9fa;
        }
        .total-row {
            background: #f1f8ff !important;
            font-weight: 600;
            font-size: 12px;
            border-top: 3px solid #3498db;
        }
        .profit-positive {
            color: #27ae60;
            font-weight: 600;
        }
        .profit-negative {
            color: #e74c3c;
            font-weight: 600;
        }
        
        @media print {
            body { background: white; padding: 0; }
            .action-buttons, .filters { display: none; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-chart-pie"></i> Profitability Report</h1>
    <p class="subtitle">Product-wise profitability analysis and cost breakdown</p>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <a href="../index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print"></i> Print
        </button>
        <?php if ($has_full_access): ?>
        <button onclick="exportToCSV()" class="btn btn-success">
            <i class="fas fa-file-csv"></i> Export CSV
        </button>
        <?php endif; ?>
    </div>
    
    <?php if ($is_summary_view): ?>
    <div style="background: #fff3cd; padding: 12px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #ffc107;">
        <strong><i class="fas fa-info-circle"></i> Summary View Mode:</strong> You are viewing summary data only. Detailed cost breakdowns are restricted to Finance and Admin.
    </div>
    <?php endif; ?>

    <!-- Summary Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value" id="total_revenue">à§³<?php echo number_format($total_revenue, 2); ?></div>
        </div>
        <div class="stat-card yellow">
            <div class="stat-label">Material Cost</div>
            <div class="stat-value" id="total_material_cost">à§³<?php echo number_format($total_material_cost, 2); ?></div>
        </div>
        <div class="stat-card blue">
            <div class="stat-label">Production Cost</div>
            <div class="stat-value" id="total_production_cost">à§³<?php echo number_format($total_production_cost, 2); ?></div>
        </div>
        <div class="stat-card green">
            <div class="stat-label">Total Profit</div>
            <div class="stat-value" id="total_profit">à§³<?php echo number_format($total_profit, 2); ?></div>
        </div>
        <div class="stat-card red">
            <div class="stat-label">Profit Margin</div>
            <div class="stat-value" id="avg_profit_margin"><?php echo number_format($overall_margin, 2); ?>%</div>
        </div>
    </div>

    <!-- Charts -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
        <div class="chart-container">
            <canvas id="profitChart"></canvas>
        </div>
        <div class="chart-container">
            <canvas id="marginChart"></canvas>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters">
        <div class="filter-group">
            <label>Start Date:</label>
            <input type="date" id="start_date" name="start_date" value="<?php echo $start_date; ?>" onchange="applyFilters()" required>
        </div>
        <div class="filter-group">
            <label>End Date:</label>
            <input type="date" id="end_date" name="end_date" value="<?php echo $end_date; ?>" onchange="applyFilters()" required>
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
    </div>

    <!-- Loading Message -->
    <div id="loadingMessage" style="display: none; text-align: center; padding: 20px; background: #e8f5e9; border-radius: 8px; margin: 20px 0;">
        <i class="fas fa-spinner fa-spin"></i> Loading data...
    </div>

    <!-- Data Table -->
    <div id="dataTableContainer">
    <?php if (empty($profitability_data)): ?>
        <div style="text-align: center; padding: 40px; color: #7f8c8d;">
            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 20px;"></i>
            <p>No profitability data found for the selected period.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
        <table id="dataTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Product Name</th>
                    <?php if ($has_full_access): ?>
                    <th>Revenue (à§³)</th>
                    <th>Material Cost (à§³)</th>
                    <th>Production Cost (à§³)</th>
                    <th>Total Cost (à§³)</th>
                    <?php endif; ?>
                    <th>Profit (à§³)</th>
                    <th>Profit Margin (%)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($profitability_data as $row): 
                    $total_cost = $row['material_cost'] + $row['production_cost'];
                    $profit_class = $row['profit'] >= 0 ? 'profit-positive' : 'profit-negative';
                ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                        <?php if ($has_full_access): ?>
                        <td>à§³<?php echo number_format($row['revenue'], 2); ?></td>
                        <td>à§³<?php echo number_format($row['material_cost'], 2); ?></td>
                        <td>à§³<?php echo number_format($row['production_cost'], 2); ?></td>
                        <td>à§³<?php echo number_format($total_cost, 2); ?></td>
                        <?php endif; ?>
                        <td class="<?php echo $profit_class; ?>">à§³<?php echo number_format($row['profit'], 2); ?></td>
                        <td class="<?php echo $profit_class; ?>"><?php echo number_format($row['profit_margin'], 2); ?>%</td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="2" style="text-align: right;"><strong>TOTAL:</strong></td>
                    <?php if ($has_full_access): ?>
                    <td><strong>à§³<?php echo number_format($total_revenue, 2); ?></strong></td>
                    <td><strong>à§³<?php echo number_format($total_material_cost, 2); ?></strong></td>
                    <td><strong>à§³<?php echo number_format($total_production_cost, 2); ?></strong></td>
                    <td><strong>à§³<?php echo number_format($total_material_cost + $total_production_cost, 2); ?></strong></td>
                    <?php endif; ?>
                    <td class="<?php echo $total_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                        <strong>à§³<?php echo number_format($total_profit, 2); ?></strong>
                    </td>
                    <td class="<?php echo $total_profit >= 0 ? 'profit-positive' : 'profit-negative'; ?>">
                        <strong><?php echo number_format($overall_margin, 2); ?>%</strong>
                    </td>
                </tr>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
    </div>
</div>

<script>
// Pass PHP variables to JavaScript
const hasFullAccess = <?php echo json_encode($has_full_access); ?>;
const isSummaryView = <?php echo json_encode($is_summary_view); ?>;
const viewType = '<?php echo $view_type; ?>';

// Bar Chart - Profit by Project/Product
const ctx1 = document.getElementById('profitChart').getContext('2d');
new Chart(ctx1, {
    type: 'bar',
    data: {
        labels: [
            <?php 
            foreach ($profitability_data as $row) {
                $name = $row['name'];
                echo "'" . addslashes($name) . "',";
            }
            ?>
        ],
        datasets: [{
            label: 'Profit (à§³)',
            data: [
                <?php 
                foreach ($profitability_data as $row) {
                    echo $row['profit'] . ",";
                }
                ?>
            ],
            backgroundColor: 'rgba(52, 152, 219, 0.8)',
            borderColor: 'rgba(52, 152, 219, 1)',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        plugins: {
            title: {
                display: true,
                text: 'Profit by <?php echo ucfirst($view_type); ?>',
                font: { size: 16 }
            }
        },
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

// Line Chart - Profit Margin
const ctx2 = document.getElementById('marginChart').getContext('2d');
new Chart(ctx2, {
    type: 'line',
    data: {
        labels: [
            <?php 
            foreach ($profitability_data as $row) {
                $name = $row['name'];
                echo "'" . addslashes($name) . "',";
            }
            ?>
        ],
        datasets: [{
            label: 'Profit Margin (%)',
            data: [
                <?php 
                foreach ($profitability_data as $row) {
                    echo $row['profit_margin'] . ",";
                }
                ?>
            ],
            borderColor: 'rgba(39, 174, 96, 1)',
            backgroundColor: 'rgba(39, 174, 96, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        plugins: {
            title: {
                display: true,
                text: 'Profit Margin Trend',
                font: { size: 16 }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return value + '%';
                    }
                }
            }
        }
    }
});

// AJAX filter functionality
let profitChart = null;
let marginChart = null;

function applyFilters() {
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    
    const loadingMessage = document.getElementById('loadingMessage');
    loadingMessage.style.display = 'block';
    
    // Build API URL
    const apiUrl = `api/profitability_data.php?start_date=${startDate}&end_date=${endDate}`;
    
    fetch(apiUrl)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateSummaryCards(data.summary);
                updateTable(data.data, hasFullAccess, isSummaryView, viewType);
                updateCharts(data.data, viewType);
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
    document.getElementById('total_revenue').textContent = 'à§³' + summary.total_revenue;
    document.getElementById('total_material_cost').textContent = 'à§³' + summary.total_material_cost;
    document.getElementById('total_production_cost').textContent = 'à§³' + summary.total_production_cost;
    document.getElementById('total_profit').textContent = 'à§³' + summary.total_profit;
    document.getElementById('avg_profit_margin').textContent = summary.avg_profit_margin + '%';
}

function updateTable(data, hasFullAccess, isSummaryView, viewType) {
    const tbody = document.querySelector('#dataTable tbody');
    const nameLabel = viewType === 'project' ? 'Project' : 'Product';
    
    // Update table header
    const thead = document.querySelector('#dataTable thead tr');
    const columnCount = isSummaryView ? 3 : (hasFullAccess ? 8 : 8);
    
    if (data.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${columnCount}" style="text-align:center;padding:20px;">No data found for selected filters</td></tr>`;
        return;
    }
    
    // Calculate totals
    let totalRevenue = 0;
    let totalMaterialCost = 0;
    let totalProductionCost = 0;
    let totalProfit = 0;
    
    data.forEach(row => {
        totalRevenue += parseFloat(row.revenue);
        totalMaterialCost += parseFloat(row.material_cost);
        totalProductionCost += parseFloat(row.production_cost);
        totalProfit += parseFloat(row.profit);
    });
    
    const totalCostSum = totalMaterialCost + totalProductionCost;
    const overallMargin = totalRevenue > 0 ? (totalProfit / totalRevenue) * 100 : 0;
    const totalProfitClass = totalProfit >= 0 ? 'profit-positive' : 'profit-negative';
    
    const dataRows = data.map((row, index) => {
        const name = row.name;
        const profitClass = parseFloat(row.profit) >= 0 ? 'profit-positive' : 'profit-negative';
        const marginClass = parseFloat(row.profit_margin) >= 0 ? 'profit-positive' : 'profit-negative';
        
        if (isSummaryView) {
            // AGM OPS: Summary view only
            return `
                <tr>
                    <td>${index + 1}</td>
                    <td>${name || 'N/A'}</td>
                    <td class="${marginClass}">${parseFloat(row.profit_margin).toFixed(2)}%</td>
                </tr>
            `;
        } else {
            // Finance/Admin: Full access
            const totalCost = parseFloat(row.material_cost) + parseFloat(row.production_cost);
            return `
                <tr>
                    <td>${index + 1}</td>
                    <td>${name || 'N/A'}</td>
                    <td>à§³${parseFloat(row.revenue).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    <td>à§³${parseFloat(row.material_cost).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    <td>à§³${parseFloat(row.production_cost).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    <td>à§³${totalCost.toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    <td class="${profitClass}">à§³${parseFloat(row.profit).toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</td>
                    <td class="${marginClass}">${parseFloat(row.profit_margin).toFixed(2)}%</td>
                </tr>
            `;
        }
    }).join('');
    
    // Add totals row
    let totalsRow = '';
    if (isSummaryView) {
        totalsRow = `
            <tr class="total-row">
                <td colspan="2" style="text-align: right;"><strong>AVERAGE:</strong></td>
                <td class="${totalProfitClass}"><strong>${overallMargin.toFixed(2)}%</strong></td>
            </tr>
        `;
    } else {
        totalsRow = `
            <tr class="total-row">
                <td colspan="2" style="text-align: right;"><strong>TOTAL:</strong></td>
                <td><strong>à§³${totalRevenue.toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
                <td><strong>à§³${totalMaterialCost.toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
                <td><strong>à§³${totalProductionCost.toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
                <td><strong>à§³${totalCostSum.toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
                <td class="${totalProfitClass}"><strong>à§³${totalProfit.toLocaleString('en-BD', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
                <td class="${totalProfitClass}"><strong>${overallMargin.toFixed(2)}%</strong></td>
            </tr>
        `;
    }
    
    tbody.innerHTML = dataRows + totalsRow;
}

function updateCharts(data, viewType) {
    const labels = data.map(row => row.name);
    const profitData = data.map(row => parseFloat(row.profit));
    const marginData = data.map(row => parseFloat(row.profit_margin));
    
    // Update profit bar chart
    const ctx1 = document.getElementById('profitChart').getContext('2d');
    if (profitChart) {
        profitChart.destroy();
    }
    
    profitChart = new Chart(ctx1, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Profit (à§³)',
                data: profitData,
                backgroundColor: profitData.map(v => v >= 0 ? 'rgba(39, 174, 96, 0.7)' : 'rgba(231, 76, 60, 0.7)'),
                borderColor: profitData.map(v => v >= 0 ? 'rgba(39, 174, 96, 1)' : 'rgba(231, 76, 60, 1)'),
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: viewType === 'project' ? 'Project-wise Profit' : 'Product-wise Profit',
                    font: { size: 16 }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return 'à§³' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
    
    // Update margin line chart
    const ctx2 = document.getElementById('marginChart').getContext('2d');
    if (marginChart) {
        marginChart.destroy();
    }
    
    marginChart = new Chart(ctx2, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Profit Margin (%)',
                data: marginData,
                borderColor: 'rgba(39, 174, 96, 1)',
                backgroundColor: 'rgba(39, 174, 96, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Profit Margin Trend',
                    font: { size: 16 }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                }
            }
        }
    });
}

function resetFilters() {
    const defaultStart = '<?php echo date('Y-m-01', strtotime('-3 months')); ?>';
    const defaultEnd = '<?php echo date('Y-m-d'); ?>';
    
    document.getElementById('start_date').value = defaultStart;
    document.getElementById('end_date').value = defaultEnd;
    
    applyFilters();
}

function exportToCSV() {
    const table = document.getElementById('dataTable');
    let csv = [];
    
    for (let i = 0; i < table.rows.length; i++) {
        let row = [], cols = table.rows[i].querySelectorAll('td, th');
        for (let j = 0; j < cols.length; j++) {
            row.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
        }
        csv.push(row.join(','));
    }
    
    const csvFile = new Blob([csv.join('\n')], { type: 'text/csv' });
    const downloadLink = document.createElement('a');
    downloadLink.download = 'profitability_report.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>
</body>
</html>



