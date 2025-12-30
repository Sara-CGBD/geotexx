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
// AGM OPS: View Only (monitors process loss)
$allowed_roles = ['admin', 'finance', 'agm ops', 'agm operations'];
$user_role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>Access Denied</h2>
        <p>You do not have permission to access Scrap Loss Report.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <p>Allowed roles: Admin, Finance, AGM Operations</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

// Check if user has full access (Finance and Admin only)
$has_full_access = in_array($user_role, ['admin', 'finance']);

date_default_timezone_set('Asia/Dhaka');

// Database connection
$conn = SecurityConfig::getConnection();

// Get filters
$start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-3 months'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$scrap_type_filter = $_GET['scrap_type'] ?? '';

// Query scrap data with simplified formula
// Formula: Gross Loss Value (à§³) = Scrap Qty (kg) Ã— Rate per kg
// Formula: Net Loss Qty (kg) = Scrap Qty â€“ Recycled Qty
// Formula: Net Loss Value (à§³) = Net Loss Qty Ã— Rate per kg
$query = "
    SELECT 
        DATE(s.date_time) as scrap_date,
        s.scrap_type,
        s.scrap_product,
        COALESCE(SUM(s.qty), 0) as scrap_qty,
        COALESCE((SELECT SUM(recycled_qty) FROM scrap_recycle WHERE scrap_id = s.id), 0) as recycled_qty,
        COALESCE(stc.cost_per_kg, 50.00) as cost_per_kg,
        
        -- Gross Loss Value (à§³) = Scrap Qty (kg) Ã— Rate per kg
        COALESCE(SUM(s.qty), 0) * COALESCE(stc.cost_per_kg, 50.00) as gross_loss_value,
        
        -- Net Loss Qty (kg) = Scrap Qty â€“ Recycled Qty
        COALESCE(SUM(s.qty), 0) - COALESCE((SELECT SUM(recycled_qty) FROM scrap_recycle WHERE scrap_id = s.id), 0) as net_loss_qty,
        
        -- Net Loss Value (à§³) = Net Loss Qty Ã— Rate per kg
        (COALESCE(SUM(s.qty), 0) - COALESCE((SELECT SUM(recycled_qty) FROM scrap_recycle WHERE scrap_id = s.id), 0)) * COALESCE(stc.cost_per_kg, 50.00) as net_loss_value
        
    FROM scrap s
    LEFT JOIN scrap_type_costs stc ON s.scrap_type = stc.scrap_type AND s.scrap_product = stc.scrap_product
    WHERE DATE(s.date_time) BETWEEN ? AND ?
";

$params = [$start_date, $end_date];
$types = 'ss';

if (!empty($scrap_type_filter)) {
    $query .= " AND s.scrap_type = ?";
    $params[] = $scrap_type_filter;
    $types .= "s";
}

$query .= " GROUP BY DATE(s.date_time), s.scrap_type, s.scrap_product, stc.cost_per_kg ORDER BY scrap_date DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$scrap_data = [];
$total_scrap_qty = 0;
$total_recycled_qty = 0;
$total_net_loss_qty = 0;
$total_gross_loss_value = 0;
$total_net_loss_value = 0;

// For cumulative chart
$cumulative_data = [];
$cumulative = 0;

while ($row = $result->fetch_assoc()) {
    $scrap_data[] = $row;
    $total_scrap_qty += $row['scrap_qty'];
    $total_recycled_qty += $row['recycled_qty'];
    $total_net_loss_qty += $row['net_loss_qty'];
    $total_gross_loss_value += $row['gross_loss_value'];
    $total_net_loss_value += $row['net_loss_value'];
    
    // Accumulate for chart
    $cumulative += $row['net_loss_value'];
    $cumulative_data[] = [
        'date' => $row['scrap_date'],
        'value' => $cumulative
    ];
}

$stmt->close();

// Get scrap types for filter
$scrap_types = $conn->query("SELECT DISTINCT scrap_type FROM scrap ORDER BY scrap_type")->fetch_all(MYSQLI_ASSOC);

$conn->close();

// Reverse cumulative data for proper chart display (oldest to newest)
$cumulative_data = array_reverse($cumulative_data);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Scrap Loss Report</title>
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
            justify-content: center;
        }
        .subtitle {
            color: #7f8c8d;
            margin-bottom: 30px;
            font-size: 14px;
            text-align: center;
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
        .stat-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-card.orange { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-card.red { background: linear-gradient(135deg,rgb(90, 19, 27) 0%, #f45c43 100%); }
        .stat-card.yellow { background: linear-gradient(135deg, #FA8BFF 0%, #2BD2FF 90%); }
        .stat-card.dark-red { background: linear-gradient(135deg, #c94b4b 0%, #4b134f 100%); }
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
        .loss-cell {
            color:rgb(161, 29, 84);
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
    <h1><i class="fas fa-trash-alt"></i> Scrap Loss Report</h1>
    <p class="subtitle">Financial impact of waste</p>

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

    <!-- Summary Statistics -->
    <div class="stats-grid">
        <div class="stat-card red">
            <div class="stat-label">Total Scrap (kg)</div>
            <div class="stat-value" id="total_scrap_qty"><?php echo number_format($total_scrap_qty, 2); ?></div>
        </div>
        <div class="stat-card green">
            <div class="stat-label">Recycled (kg)</div>
            <div class="stat-value" id="total_recycled_qty"><?php echo number_format($total_recycled_qty, 2); ?></div>
        </div>
        <div class="stat-card yellow">
            <div class="stat-label">Net Loss Qty (kg)</div>
            <div class="stat-value" id="total_net_loss_qty"><?php echo number_format($total_net_loss_qty, 2); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Gross Loss Value (à§³)</div>
            <div class="stat-value" id="total_gross_loss_value">à§³<?php echo number_format($total_gross_loss_value, 2); ?></div>
        </div>
        <div class="stat-card dark-red">
            <div class="stat-label">Net Loss Value (à§³)</div>
            <div class="stat-value" id="total_net_loss_value">à§³<?php echo number_format($total_net_loss_value, 2); ?></div>
        </div>
    </div>

    <!-- Cumulative Loss Chart -->
    <div class="chart-container">
        <canvas id="lossChart"></canvas>
    </div>

    <!-- Filters -->
    <div class="filters">
        <div class="filter-group">
            <label>Start Date:</label>
            <input type="date" id="start_date" name="start_date" value="<?php echo $start_date; ?>" required>
        </div>
        <div class="filter-group">
            <label>End Date:</label>
            <input type="date" id="end_date" name="end_date" value="<?php echo $end_date; ?>" required>
        </div>
        <div class="filter-group">
            <label>Scrap Type:</label>
            <select id="scrap_type" name="scrap_type">
                <option value="">All Types</option>
                <?php foreach ($scrap_types as $type): ?>
                    <option value="<?php echo htmlspecialchars($type['scrap_type']); ?>" 
                        <?php echo ($scrap_type_filter === $type['scrap_type']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($type['scrap_type']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group" style="align-self: flex-end;">
            <button type="button" class="btn btn-primary" style="width: 100%; margin-bottom: 0;" onclick="applyFilters()">
                <i class="fas fa-search"></i> Apply
            </button>
        </div>
        <div class="filter-group" style="align-self: flex-end;">
            <button type="button" class="btn" style="width: 100%; background: #95a5a6; color: white;" onclick="resetFilters()">
                <i class="fas fa-undo"></i> Reset
            </button>
        </div>
    </div>

    <!-- Data Table -->
    <?php if (empty($scrap_data)): ?>
        <div style="text-align: center; padding: 40px; color: #7f8c8d;">
            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 20px;"></i>
            <p>No scrap data found for the selected period.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
        <table id="dataTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th>Product</th>
                    <th>Scrap Qty (kg)</th>
                    <th>Recycled (kg)</th>
                    <th>Rate per kg (à§³)</th>
                    <th>Gross Loss Value (à§³)</th>
                    <th>Net Loss Qty (kg)</th>
                    <th>Net Loss Value (à§³)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($scrap_data as $row): 
                ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><?php echo date('d M, Y', strtotime($row['scrap_date'])); ?></td>
                        <td><?php echo htmlspecialchars($row['scrap_type']); ?></td>
                        <td><?php echo htmlspecialchars($row['scrap_product'] ?? 'N/A'); ?></td>
                        <td><?php echo number_format($row['scrap_qty'], 2); ?></td>
                        <td><?php echo number_format($row['recycled_qty'], 2); ?></td>
                        <td>à§³<?php echo number_format($row['cost_per_kg'], 2); ?></td>
                        <td>à§³<?php echo number_format($row['gross_loss_value'], 2); ?></td>
                        <td><?php echo number_format($row['net_loss_qty'], 2); ?></td>
                        <td class="loss-cell">à§³<?php echo number_format($row['net_loss_value'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="4" style="text-align: right;"><strong>TOTAL:</strong></td>
                    <td><strong><?php echo number_format($total_scrap_qty, 2); ?> kg</strong></td>
                    <td><strong><?php echo number_format($total_recycled_qty, 2); ?> kg</strong></td>
                    <td></td>
                    <td><strong>à§³<?php echo number_format($total_gross_loss_value, 2); ?></strong></td>
                    <td><strong><?php echo number_format($total_net_loss_qty, 2); ?> kg</strong></td>
                    <td class="loss-cell"><strong>à§³<?php echo number_format($total_net_loss_value, 2); ?></strong></td>
                </tr>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<script>
// AJAX filter functionality
let currentChart = null;

// Initialize chart on page load
const ctx = document.getElementById('lossChart').getContext('2d');
currentChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: [
            <?php 
            foreach ($cumulative_data as $item) {
                echo "'" . date('M d', strtotime($item['date'])) . "',";
            }
            ?>
        ],
        datasets: [{
            label: 'Cumulative Loss Value (à§³)',
            data: [
                <?php 
                foreach ($cumulative_data as $item) {
                    echo $item['value'] . ",";
                }
                ?>
            ],
            borderColor: 'rgb(84, 21, 121)',
            backgroundColor: 'rgba(231, 76, 60, 0.1)',
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
                text: 'Cumulative Loss Chart',
                font: { size: 18 }
            },
            legend: {
                display: true
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

function applyFilters() {
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    const scrapType = document.getElementById('scrap_type').value;
    
    // Show loading
    const tbody = document.querySelector('#dataTable tbody');
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin"></i> Loading data...</td></tr>';
    
    // Build query params
    const params = new URLSearchParams({
        start_date: startDate,
        end_date: endDate,
        scrap_type: scrapType
    });
    
    // Fetch data
    fetch(`api/scrap_loss_data.php?${params}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data); // Debug log
            if (data.success) {
                updateSummaryCards(data.summary);
                updateTable(data.data, data.has_full_access);
                updateChart(data.cumulative_data);
            } else {
                console.error('API Error:', data.error);
                tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;padding:20px;color:#e74c3c;">Error: ${data.error || 'Unknown error'}</td></tr>`;
            }
        })
        .catch(error => {
            console.error('Fetch Error:', error);
            tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;padding:20px;color:#e74c3c;">Failed to load data: ${error.message}</td></tr>`;
        });
}

function updateSummaryCards(summary) {
    document.getElementById('total_scrap_qty').textContent = summary.total_scrap_qty;
    document.getElementById('total_recycled_qty').textContent = summary.total_recycled_qty;
    document.getElementById('total_net_loss_qty').textContent = summary.total_net_loss_qty;
    document.getElementById('total_gross_loss_value').textContent = 'à§³' + summary.total_gross_loss_value;
    document.getElementById('total_net_loss_value').textContent = 'à§³' + summary.total_net_loss_value;
}

function updateTable(data, hasFullAccess) {
    const tbody = document.querySelector('#dataTable tbody');
    
    if (data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:20px;">No data found for selected filters</td></tr>';
        return;
    }
    
    let counter = 1;
    tbody.innerHTML = data.map(row => `
        <tr>
            <td>${counter++}</td>
            <td>${row.scrap_date}</td>
            <td>${row.scrap_type || 'N/A'}</td>
            <td>${row.scrap_product || 'N/A'}</td>
            <td>${parseFloat(row.scrap_qty).toFixed(2)}</td>
            <td>${parseFloat(row.recycled_qty).toFixed(2)}</td>
            <td>à§³${parseFloat(row.cost_per_kg).toFixed(2)}</td>
            <td>à§³${parseFloat(row.gross_loss_value).toFixed(2)}</td>
            <td>${parseFloat(row.net_loss_qty).toFixed(2)}</td>
            <td class="loss-cell">à§³${parseFloat(row.net_loss_value).toFixed(2)}</td>
        </tr>
    `).join('');
}

function updateChart(cumulativeData) {
    const ctx = document.getElementById('lossChart').getContext('2d');
    
    // Destroy existing chart if exists
    if (currentChart) {
        currentChart.destroy();
    }
    
    currentChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: cumulativeData.map(d => d.date),
            datasets: [{
                label: 'Cumulative Loss (à§³)',
                data: cumulativeData.map(d => d.cumulative),
                borderColor: 'rgba(231, 76, 60, 1)',
                backgroundColor: 'rgba(231, 76, 60, 0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Cumulative Loss Chart',
                    font: { size: 18 }
                },
                legend: {
                    display: true
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
}

function resetFilters() {
    document.getElementById('start_date').value = '<?php echo date('Y-m-01'); ?>';
    document.getElementById('end_date').value = '<?php echo date('Y-m-d'); ?>';
    document.getElementById('scrap_type').value = '';
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
    downloadLink.download = 'scrap_loss_report.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>
</body>
</html>



