<?php
session_start();
require_once '../config/security_config.php';

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
// Allowed: Finance, Admin (Full Access)
// Contains sensitive cost data (labor, utility, overhead)
$allowed_roles = ['admin', 'finance'];
$user_role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>ðŸš« Access Denied</h2>
        <p>You do not have permission to access Production Cost Report.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <p>Allowed roles: Admin, Finance</p>
        <p style='margin-top: 10px; font-size: 13px; color: #555;'>This report contains sensitive cost data restricted to financial oversight only.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// Database connection
$conn = SecurityConfig::getConnection();

// Determine which sewing table exists
$sewingTableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
$sewingTable = ($sewingTableCheck && $sewingTableCheck->num_rows > 0) ? 'sewing_machine_entry' : 'swing_machine_entry';

// No need to add extra columns - CNC entry uses cutting_roll_quantity only

// Get filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$product_filter = $_GET['product'] ?? '';
$production_type_filter = $_GET['production_type'] ?? '';
$shift_filter = $_GET['shift'] ?? '';

// Get production cost settings from database
$cost_query = "SELECT cost_type, cost_per_unit FROM production_cost_settings WHERE cost_type IN ('labor_cost', 'utility_cost', 'overhead_cost')";
$cost_result = $conn->query($cost_query);
$cost_settings = [];
while ($row = $cost_result->fetch_assoc()) {
    $cost_settings[$row['cost_type']] = $row['cost_per_unit'];
}

$labor_cost_per_unit = $cost_settings['labor_cost'] ?? 15; // Default ৳15 if not set
$utility_cost_per_unit = $cost_settings['utility_cost'] ?? 5; // Default ৳5 if not set
$overhead_cost_per_unit = $cost_settings['overhead_cost'] ?? 10; // Default ৳10 if not set

// Debug: Check if we have any production data
$debug_cnc = $conn->query("SELECT COUNT(*) as count FROM cnc_entries WHERE DATE(date_time) BETWEEN '$start_date' AND '$end_date'")->fetch_assoc();
$debug_sewing = $conn->query("SELECT COUNT(*) as count FROM $sewingTable WHERE DATE(date_time) BETWEEN '$start_date' AND '$end_date'")->fetch_assoc();
$debug_branding = $conn->query("SELECT COUNT(*) as count FROM branding_entries WHERE DATE(date_time) BETWEEN '$start_date' AND '$end_date'")->fetch_assoc();

$total_production_entries = ($debug_cnc['count'] ?? 0) + ($debug_sewing['count'] ?? 0) + ($debug_branding['count'] ?? 0);

// Get list of projects for filter dropdown
$projects_query = "SELECT DISTINCT id, project_name FROM projects ORDER BY project_name";
$projects_result = $conn->query($projects_query);
$projects = [];
while ($row = $projects_result->fetch_assoc()) {
    $projects[] = $row;
}

// Build dynamic WHERE clauses for filters
$cnc_where = "DATE(c.date_time) BETWEEN ? AND ?";
$sewing_where = "DATE(s.date_time) BETWEEN ? AND ?";
$branding_where = "DATE(b.date_time) BETWEEN ? AND ?";

$params = [];
$types = '';

// Add product filter
if ($product_filter) {
    $cnc_where .= " AND c.project_id = ?";
    $sewing_where .= " AND s.project_id = ?";
    $branding_where .= " AND b.project_id = ?";
}

// Add shift filter
if ($shift_filter) {
    $cnc_where .= " AND c.shift = ?";
    $sewing_where .= " AND s.shift = ?";
    // Branding doesn't have a shift column, it's derived
}

// Query production data from multiple sources
$query_parts = [];

// CNC query
if (!$production_type_filter || $production_type_filter == 'CNC') {
    $query_parts[] = "
    SELECT 
        'CNC' as production_type,
        DATE(c.date_time) as production_date,
        c.shift,
        COALESCE(p.project_name, 'Unknown') as product_name,
        COALESCE(SUM(c.cutting_roll_quantity), 0) as quantity,
        COALESCE(SUM(c.cutting_roll_quantity), 0) * ? as labor_cost,
        COALESCE(SUM(c.cutting_roll_quantity), 0) * ? as utility_cost,
        COALESCE(SUM(c.cutting_roll_quantity), 0) * ? as overhead_cost,
        COALESCE(SUM(c.cutting_roll_quantity), 0) * (? + ? + ?) as total_cost
    FROM cnc_entries c
    LEFT JOIN projects p ON c.project_id = p.id
    WHERE $cnc_where
    GROUP BY DATE(c.date_time), c.shift, p.project_name";
}

// Sewing query
if (!$production_type_filter || $production_type_filter == 'Sewing') {
    $query_parts[] = "
    SELECT 
        'Sewing' as production_type,
        DATE(s.date_time) as production_date,
        s.shift,
        COALESCE(p.project_name, 'Unknown') as product_name,
        COALESCE(SUM(s.sewing_qty), 0) as quantity,
        COALESCE(SUM(s.sewing_qty), 0) * ? as labor_cost,
        COALESCE(SUM(s.sewing_qty), 0) * ? as utility_cost,
        COALESCE(SUM(s.sewing_qty), 0) * ? as overhead_cost,
        COALESCE(SUM(s.sewing_qty), 0) * (? + ? + ?) as total_cost
    FROM $sewingTable s
    LEFT JOIN projects p ON s.project_id = p.id
    WHERE $sewing_where
    GROUP BY DATE(s.date_time), s.shift, p.project_name";
}

// Branding query (only if shift filter is not set, or matches derived shift)
if (!$production_type_filter || $production_type_filter == 'Branding') {
    $branding_shift_case = "CASE 
            WHEN HOUR(b.date_time) >= 8 AND HOUR(b.date_time) < 20 THEN 'Day'
            ELSE 'Night'
        END";
    
    if ($shift_filter) {
        $branding_where .= " AND $branding_shift_case = ?";
    }
    
    $query_parts[] = "
    SELECT 
        'Branding' as production_type,
        DATE(b.date_time) as production_date,
        $branding_shift_case as shift,
        COALESCE(p.project_name, 'Unknown') as product_name,
        COALESCE(SUM(b.print_qty), 0) as quantity,
        COALESCE(SUM(b.print_qty), 0) * ? as labor_cost,
        COALESCE(SUM(b.print_qty), 0) * ? as utility_cost,
        COALESCE(SUM(b.print_qty), 0) * ? as overhead_cost,
        COALESCE(SUM(b.print_qty), 0) * (? + ? + ?) as total_cost
    FROM branding_entries b
    LEFT JOIN projects p ON b.project_id = p.id
    WHERE $branding_where
    GROUP BY DATE(b.date_time), $branding_shift_case, p.project_name";
}

$query = implode(" UNION ALL ", $query_parts) . " ORDER BY production_date DESC, production_type";

// Build bind parameters dynamically
$bind_params = [];
$bind_types = '';

// For each query part, add the cost parameters and date parameters
foreach ($query_parts as $i => $part) {
    // Add cost parameters (6 doubles for each query)
    $bind_params[] = $labor_cost_per_unit;
    $bind_params[] = $utility_cost_per_unit;
    $bind_params[] = $overhead_cost_per_unit;
    $bind_params[] = $labor_cost_per_unit;
    $bind_params[] = $utility_cost_per_unit;
    $bind_params[] = $overhead_cost_per_unit;
    $bind_types .= 'dddddd';
    
    // Add date parameters
    $bind_params[] = $start_date;
    $bind_params[] = $end_date;
    $bind_types .= 'ss';
    
    // Add product filter if set
    if ($product_filter) {
        $bind_params[] = $product_filter;
        $bind_types .= 'i';
    }
    
    // Add shift filter if set
    if ($shift_filter) {
        $bind_params[] = $shift_filter;
        $bind_types .= 's';
    }
}

$stmt = $conn->prepare($query);
if (!empty($bind_params)) {
    $stmt->bind_param($bind_types, ...$bind_params);
}
$stmt->execute();
$result = $stmt->get_result();
$production_data = [];
$total_labor = 0;
$total_utility = 0;
$total_overhead = 0;
$total_cost = 0;

while ($row = $result->fetch_assoc()) {
    $production_data[] = $row;
    $total_labor += $row['labor_cost'];
    $total_utility += $row['utility_cost'];
    $total_overhead += $row['overhead_cost'];
    $total_cost += $row['total_cost'];
}

$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Production Cost Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f4f6f9;
            padding: 20px;
            color: #2c3e50;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        h1 {
            color: #34495e;
            margin-bottom: 8px;
            font-size: 26px;
            text-align: center;
        }
        .subtitle {
            color: #7f8c8d;
            margin-bottom: 25px;
            font-size: 13px;
        }
        
        .top-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        .btn-group {
            display: flex;
            gap: 8px;
        }
        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
        }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #229954; }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-secondary:hover { background: #7f8c8d; }
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 18px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .stat-box.green { background: linear-gradient(135deg,rgb(10, 47, 49) 0%,rgb(137, 170, 149) 100%); }
        .stat-box.orange { background: linear-gradient(135deg,rgb(133, 30, 80) 0%,rgb(112, 69, 38) 100%); }
        .stat-box.red { background: linear-gradient(135deg, #2E3192 0%,rgb(163, 77, 103) 100%); }
        .stat-box h3 { font-size: 28px; margin-bottom: 5px; }
        .stat-box p { font-size: 12px; opacity: 0.9; }
        
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
        .filter-group input {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
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
            font-size: 13px;
        }
        thead {
            background: #34495e;
            color: white;
        }
        th {
            padding: 12px 10px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        td {
            padding: 12px 10px;
            border-bottom: 1px solid #ecf0f1;
        }
        tbody tr:hover {
            background-color: #f8f9fa;
        }
        tbody tr:nth-child(even) {
            background-color: #fafafa;
        }
        .total-row {
            background: #f1f8ff !important;
            font-weight: 600;
            font-size: 13px;
            border-top: 3px solid #3498db;
        }
        .cost-cell {
            color: #e74c3c;
            font-weight: 600;
        }
        
        @media print {
            @page {
                size: A4 landscape;
                margin: 10mm;
            }
            body { background: white; padding: 0; }
            .action-buttons, .filters { display: none; }
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
    <h1>Production Cost Report</h1>
    <p class="subtitle">Labor, utility, and overhead cost tracking</p>

    <div class="top-actions">
        <div class="btn-group">
            <a href="../index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <a href="../admin/production_cost_settings.php" class="btn" style="background: #3498db; color: white;">
                <i class="fas fa-cog"></i> Cost Settings
            </a>
            <button onclick="exportToCSV()" class="btn btn-success">
                <i class="fas fa-file-csv"></i> Export CSV
            </button>
        </div>
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print"></i> Print
        </button>
    </div>

    <?php if ($total_production_entries == 0): ?>
    <div style="background: #fff3cd; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #ffc107;">
        <i class="fas fa-exclamation-triangle" style="color: #856404; margin-right: 8px;"></i>
        <strong>No Production Data Found!</strong> No CNC, Sewing, or Branding entries exist in the selected date range (<?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?>). 
        <br><br>
        Create production entries in: 
        <a href="../forms/cnc_entry.php" style="color: #3498db;">CNC Entry</a>, 
        <a href="../forms/swing_machine_entry.php" style="color: #3498db;">Sewing Entry</a>, or 
        <a href="../forms/branding_entry.php" style="color: #3498db;">Branding Entry</a>
    </div>
    <?php endif; ?>
    
    <?php if ($labor_cost_per_unit == 0 && $utility_cost_per_unit == 0 && $overhead_cost_per_unit == 0): ?>
    <div style="background: #f8d7da; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #dc3545;">
        <i class="fas fa-exclamation-circle" style="color: #721c24; margin-right: 8px;"></i>
        <strong>Cost Settings Not Configured!</strong> Labor, utility, and overhead costs are all set to â‚¹0. 
        <br><br>
        <a href="../admin/production_cost_settings.php" class="btn btn-primary" style="margin-top: 10px;">
            <i class="fas fa-cog"></i> Configure Production Costs
        </a>
    </div>
    <?php endif; ?>

    <!-- Summary Statistics -->
    <div class="stats-row">
        <div class="stat-box">
            <h3>৳<?php echo number_format($total_cost, 2); ?></h3>
            <p>Total Cost</p>
        </div>
        <div class="stat-box green">
            <h3>৳<?php echo number_format($total_labor, 2); ?></h3>
            <p>Labor Cost</p>
        </div>
        <div class="stat-box orange">
            <h3>৳<?php echo number_format($total_utility, 2); ?></h3>
            <p>Utility Cost</p>
        </div>
        <div class="stat-box red">
            <h3>৳<?php echo number_format($total_overhead, 2); ?></h3>
            <p>Overhead Cost</p>
        </div>
    </div>

    <!-- Filters -->
    <div id="loadingMessage" style="display: none; text-align: center; padding: 15px; background: #fff3cd; border-radius: 6px; margin-bottom: 20px;">
        <i class="fas fa-spinner fa-spin"></i> Loading data...
    </div>
    
    <form id="filterForm" method="GET" class="filters">
        <div class="filter-group">
            <label>Start Date:</label>
            <input type="date" id="start_date" name="start_date" value="<?php echo $start_date; ?>" required>
        </div>
        <div class="filter-group">
            <label>End Date:</label>
            <input type="date" id="end_date" name="end_date" value="<?php echo $end_date; ?>" required>
        </div>
        <div class="filter-group">
            <label>Product/Project:</label>
            <select id="product" name="product">
                <option value="">All Products</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?php echo $project['id']; ?>" <?php echo ($product_filter == $project['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($project['project_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Production Type:</label>
            <select id="production_type" name="production_type">
                <option value="">All Types</option>
                <option value="CNC" <?php echo ($production_type_filter == 'CNC') ? 'selected' : ''; ?>>CNC</option>
                <option value="Sewing" <?php echo ($production_type_filter == 'Sewing') ? 'selected' : ''; ?>>Sewing</option>
                <option value="Branding" <?php echo ($production_type_filter == 'Branding') ? 'selected' : ''; ?>>Branding</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Shift:</label>
            <select id="shift" name="shift">
                <option value="">All Shifts</option>
                <option value="Day" <?php echo ($shift_filter == 'Day') ? 'selected' : ''; ?>>Day</option>
                <option value="Night" <?php echo ($shift_filter == 'Night') ? 'selected' : ''; ?>>Night</option>
            </select>
        </div>
        <div class="filter-group" style="align-self: flex-end;">
            <button type="button" onclick="applyFilters()" class="btn btn-primary" style="width: 100%; margin-bottom: 0;">
                <i class="fas fa-search"></i> Apply
            </button>
        </div>
        <div class="filter-group" style="align-self: flex-end;">
            <button type="button" onclick="resetFilters()" class="btn btn-secondary" style="width: 100%;">
                <i class="fas fa-redo"></i> Reset
            </button>
        </div>
    </form>

    <!-- Data Table -->
    <?php if (empty($production_data)): ?>
        <div style="text-align: center; padding: 40px; color: #7f8c8d;">
            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 20px;"></i>
            <p>No production data found for the selected period.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
        <table id="dataTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th>Shift</th>
                    <th>Type</th>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Labor (৳)</th>
                    <th>Utility (৳)</th>
                    <th>Overhead (৳)</th>
                    <th>Total (৳)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($production_data as $row): 
                ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><?php echo date('d M, Y', strtotime($row['production_date'])); ?></td>
                        <td><?php echo htmlspecialchars($row['shift'] ?? 'N/A'); ?></td>
                        <td><?php echo $row['production_type']; ?></td>
                        <td><strong><?php echo htmlspecialchars($row['product_name']); ?></strong></td>
                        <td><?php echo number_format($row['quantity'], 2); ?></td>
                        <td>৳<?php echo number_format($row['labor_cost'], 0); ?></td>
                        <td>৳<?php echo number_format($row['utility_cost'], 0); ?></td>
                        <td>৳<?php echo number_format($row['overhead_cost'], 0); ?></td>
                        <td><strong>৳<?php echo number_format($row['total_cost'], 2); ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="6" style="text-align: right;"><strong>TOTAL:</strong></td>
                    <td><strong>৳<?php echo number_format($total_labor, 0); ?></strong></td>
                    <td><strong>৳<?php echo number_format($total_utility, 0); ?></strong></td>
                    <td><strong>৳<?php echo number_format($total_overhead, 0); ?></strong></td>
                    <td><strong>৳<?php echo number_format($total_cost, 2); ?></strong></td>
                </tr>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<script>
let filterTimeout;
let costChart;

function applyFilters() {
    clearTimeout(filterTimeout);
    
    filterTimeout = setTimeout(() => {
        const startDate = document.getElementById('start_date').value;
        const endDate = document.getElementById('end_date').value;
        const product = document.getElementById('product').value;
        const productionType = document.getElementById('production_type').value;
        const shift = document.getElementById('shift').value;
        
        document.getElementById('loadingMessage').style.display = 'block';
        
        const params = new URLSearchParams({
            start_date: startDate,
            end_date: endDate
        });
        
        if (product) params.append('product', product);
        if (productionType) params.append('production_type', productionType);
        if (shift) params.append('shift', shift);
        
        fetch(`api/production_cost_data.php?${params.toString()}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateSummary(data.summary);
                    updateTable(data.data, data.summary);
                    updateChart(data.summary);
                    document.getElementById('loadingMessage').style.display = 'none';
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                    document.getElementById('loadingMessage').style.display = 'none';
                }
            })
            .catch(error => {
                alert('Error: ' + error.message);
                document.getElementById('loadingMessage').style.display = 'none';
            });
    }, 500);
}

function updateSummary(summary) {
    const statCards = document.querySelectorAll('.stat-card .stat-value');
    if (statCards[0]) statCards[0].textContent = '৳' + summary.total_cost.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    if (statCards[1]) statCards[1].textContent = '৳' + summary.total_labor.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    if (statCards[2]) statCards[2].textContent = '৳' + summary.total_utility.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    if (statCards[3]) statCards[3].textContent = '৳' + summary.total_overhead.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

function updateTable(data, summary) {
    const tbody = document.querySelector('#dataTable tbody');
    
    if (!data || data.length === 0) {
        const existingTable = document.getElementById('dataTable');
        if (existingTable) {
            existingTable.parentElement.innerHTML = `
                <div style="text-align: center; padding: 40px; color: #7f8c8d;">
                    <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 20px;"></i>
                    <p>No production data found for the selected period.</p>
                </div>
            `;
        }
        return;
    }
    
    let html = '';
    data.forEach((row, index) => {
        const date = new Date(row.production_date);
        const formattedDate = date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        
        html += `
            <tr>
                <td>${index + 1}</td>
                <td>${formattedDate}</td>
                <td>${row.shift || 'N/A'}</td>
                <td>${row.production_type}</td>
                <td><strong>${row.product_name}</strong></td>
                <td>${parseFloat(row.quantity).toFixed(2)}</td>
                <td>৳${parseFloat(row.labor_cost).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}</td>
                <td>৳${parseFloat(row.utility_cost).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}</td>
                <td>৳${parseFloat(row.overhead_cost).toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}</td>
                <td><strong>৳${parseFloat(row.total_cost).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
            </tr>
        `;
    });
    
    html += `
        <tr class="total-row">
            <td colspan="6" style="text-align: right;"><strong>TOTAL:</strong></td>
            <td><strong>৳${summary.total_labor.toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}</strong></td>
            <td><strong>৳${summary.total_utility.toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}</strong></td>
            <td><strong>৳${summary.total_overhead.toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0})}</strong></td>
            <td><strong>৳${summary.total_cost.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</strong></td>
        </tr>
    `;
    
    tbody.innerHTML = html;
}

function updateChart(summary) {
    if (costChart) {
        costChart.data.datasets[0].data = [summary.total_labor, summary.total_utility, summary.total_overhead];
        costChart.update();
    }
}

function resetFilters() {
    document.getElementById('start_date').value = '<?php echo date('Y-m-01'); ?>';
    document.getElementById('end_date').value = '<?php echo date('Y-m-d'); ?>';
    applyFilters();
}

function exportToCSV() {
    const table = document.getElementById('dataTable');
    if (!table) {
        alert('No data to export');
        return;
    }
    
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
    downloadLink.download = 'production_cost_report.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

// Store chart reference
const ctx = document.getElementById('costChart').getContext('2d');
costChart = new Chart(ctx, {
    type: 'pie',
    data: {
        labels: ['Labor Cost', 'Utility Cost', 'Overhead Cost'],
        datasets: [{
            data: [
                <?php echo $total_labor; ?>,
                <?php echo $total_utility; ?>,
                <?php echo $total_overhead; ?>
            ],
            backgroundColor: [
                'rgba(52, 152, 219, 0.8)',
                'rgba(241, 196, 15, 0.8)',
                'rgba(231, 76, 60, 0.8)'
            ],
            borderColor: [
                'rgba(52, 152, 219, 1)',
                'rgba(241, 196, 15, 1)',
                'rgba(231, 76, 60, 1)'
            ],
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        plugins: {
            title: {
                display: true,
                text: 'Cost Breakdown Summary',
                font: { size: 18 }
            },
            legend: {
                position: 'bottom'
            }
        }
    }
});
</script>
</body>
</html>



