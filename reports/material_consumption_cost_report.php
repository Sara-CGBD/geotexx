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
// Allowed: Finance, AGM OPS, Admin
// Finance/Admin: Full Access (View + Export)
// AGM OPS: View Only
$allowed_roles = ['admin', 'finance', 'agm ops', 'agm operations'];
$user_role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>ðŸš« Access Denied</h2>
        <p>You do not have permission to access Material Consumption Cost Report.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <p>Allowed roles: Admin, Finance, AGM Operations</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

// Check if user has export permission (Finance and Admin only)
$can_export = in_array($user_role, ['admin', 'finance']);

date_default_timezone_set('Asia/Dhaka');

// Database connection
$conn = SecurityConfig::getConnection();

// Get filters
$start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-3 months'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$project_filter = $_GET['project'] ?? '';

// Auto-create/fix materials with missing prices
if (isset($_GET['create_missing']) && $_GET['create_missing'] === '1') {
    // First, update existing materials with 0 price
    $updated_count = 0;
    $update_zero_prices = $conn->query("
        UPDATE materials 
        SET price_per_unit = 50.00 
        WHERE price_per_unit = 0 
          AND is_deleted = 0
          AND material_name IN (
              SELECT DISTINCT material_name FROM material_consumption WHERE is_deleted = 0
          )
    ");
    $updated_count = $conn->affected_rows;
    
    // Then create missing materials
    $missing_materials = $conn->query("
        SELECT DISTINCT mc.material_name 
        FROM material_consumption mc
        WHERE mc.material_name IS NOT NULL 
          AND mc.material_name != ''
          AND mc.is_deleted = 0
          AND NOT EXISTS (
              SELECT 1 FROM materials m 
              WHERE LOWER(TRIM(m.material_name)) = LOWER(TRIM(mc.material_name))
          )
    ");
    
    $created_count = 0;
    if ($missing_materials) {
        while ($row = $missing_materials->fetch_assoc()) {
            $mat_name = $row['material_name'];
            $ins = $conn->prepare("INSERT INTO materials (material_name, price_per_unit, is_deleted) VALUES (?, 50.00, 0)");
            $ins->bind_param('s', $mat_name);
            if ($ins->execute()) {
                $created_count++;
            }
            $ins->close();
        }
    }
    
    $total_fixed = $updated_count + $created_count;
    header("Location: material_consumption_cost_report.php?created=$created_count&updated=$updated_count");
    exit();
}

// Fetch data from material_consumption table
$consumption_data = [];
$query = "SELECT 
            mc.id,
            mc.consumption_date,
            mc.shift,
            mc.material_name,
            mc.consumption_type,
            mc.project_id,
            COALESCE(p.project_name, 'Unassigned') as project_name,
            mc.quantity,
            mc.unit,
            mc.operator,
            COALESCE(m.price_per_unit, 0) as unit_price,
            mc.quantity * COALESCE(m.price_per_unit, 0) as total_cost
          FROM material_consumption mc
          LEFT JOIN materials m ON LOWER(TRIM(mc.material_name)) = LOWER(TRIM(m.material_name))
          LEFT JOIN projects p ON mc.project_id = p.id
          WHERE mc.is_deleted = 0
            AND DATE(mc.consumption_date) BETWEEN ? AND ?";

$params = [$start_date, $end_date];
$types = 'ss';

if (!empty($project_filter)) {
    $query .= " AND mc.project_id = ?";
    $params[] = $project_filter;
    $types .= 'i';
}

$query .= " ORDER BY mc.consumption_date DESC, mc.id DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$total_cost = 0;
$total_consumed = 0;

while ($row = $result->fetch_assoc()) {
    $consumption_data[] = $row;
    $total_cost += $row['total_cost'];
    $total_consumed += $row['quantity'];
}

$stmt->close();

// Get projects list for filter
$projects_list = $conn->query("SELECT id, project_name FROM projects ORDER BY project_name")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Material Consumption Cost Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
            color: white;
            padding: 18px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        .stat-box.green { 
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        .stat-box.orange { 
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
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
        .filter-group input,
        .filter-group select {
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
            body { background: white; padding: 0; }
            .top-actions, .filters { display: none; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Material Consumption Cost Report</h1>
    <p class="subtitle">Material usage cost tracking and analysis</p>

    <div class="top-actions">
        <div class="btn-group">
            <a href="../index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <?php if ($can_export): ?>
            <a href="../admin/materials_management.php" class="btn" style="background: #3498db; color: white;">
                <i class="fas fa-boxes"></i> Manage Material Prices
            </a>
            <a href="?create_missing=1" class="btn" style="background: #27ae60; color: white;" 
               onclick="return confirm('This will auto-create missing materials with default price (৳50/unit). Continue?');">
                <i class="fas fa-plus-circle"></i> Create Missing Materials
            </a>
            <button onclick="exportToCSV()" class="btn btn-success">
                <i class="fas fa-file-csv"></i> Export CSV
            </button>
            <?php endif; ?>
        </div>
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print"></i> Print
        </button>
    </div>

    <?php if (isset($_GET['created']) || isset($_GET['updated'])): ?>
    <div style="background: #d4edda; padding: 12px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #28a745;">
        <i class="fas fa-check-circle" style="color: #155724; margin-right: 8px;"></i>
        <strong>Success!</strong> 
        <?php 
        $created = intval($_GET['created'] ?? 0);
        $updated = intval($_GET['updated'] ?? 0);
        if ($created > 0) echo "Created $created new material(s). ";
        if ($updated > 0) echo "Updated $updated material(s) with default price ৳50/unit.";
        ?>
        Refresh to see updated costs.
    </div>
    <?php endif; ?>

    <?php if ($total_cost == 0 && count($consumption_data) > 0): ?>
    <div style="background: #fff3cd; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #ffc107;">
        <i class="fas fa-exclamation-triangle" style="color: #856404; margin-right: 8px;"></i>
        <strong>Materials Have No Prices!</strong> You have <?php echo count($consumption_data); ?> consumption entries, but total cost is â‚¹0 because materials don't have prices set in the materials master table.
        <br><br>
        <strong>Solution:</strong> 
        <ol style="margin-left: 20px; margin-top: 10px;">
            <li>Click <a href="../admin/materials_management.php" style="color: #3498db; font-weight: 600;">Manage Material Prices</a> to set prices for all materials, OR</li>
            <li>Click "Create Missing Materials" button above to auto-create missing materials with default price â‚¹50/unit</li>
        </ol>
    </div>
    <?php endif; ?>

    <!-- Summary Statistics -->
    <div class="stats-row">
        <div class="stat-box orange">
            <h3>৳<?php echo number_format($total_cost, 2); ?></h3>
            <p>Total Cost</p>
        </div>
        <div class="stat-box green">
            <h3><?php echo number_format($total_consumed, 2); ?></h3>
            <p>Total Consumed (kg)</p>
        </div>
        <div class="stat-box">
            <h3>৳<?php echo $total_consumed > 0 ? number_format($total_cost / $total_consumed, 2) : '0.00'; ?></h3>
            <p>Avg Cost/kg</p>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="filters">
        <div class="filter-group">
            <label>Start Date:</label>
            <input type="date" name="start_date" value="<?php echo $start_date; ?>" required>
        </div>
        <div class="filter-group">
            <label>End Date:</label>
            <input type="date" name="end_date" value="<?php echo $end_date; ?>" required>
        </div>
        <div class="filter-group">
            <label>Project:</label>
            <select name="project">
                <option value="">All Projects</option>
                <?php foreach ($projects_list as $proj): ?>
                    <option value="<?php echo $proj['id']; ?>" <?php echo ($project_filter == $proj['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($proj['project_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group" style="align-self: flex-end;">
            <button type="submit" class="btn btn-primary" style="width: 100%;">
                <i class="fas fa-search"></i> Apply
            </button>
        </div>
        <div class="filter-group" style="align-self: flex-end;">
            <a href="?" class="btn btn-secondary" style="width: 100%; justify-content: center;">
                <i class="fas fa-redo"></i> Reset
            </a>
        </div>
    </form>

    <!-- Data Table -->
    <?php if (empty($consumption_data)): ?>
        <div style="text-align: center; padding: 40px; color: #7f8c8d;">
            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 20px;"></i>
            <p>No consumption data found for the selected period.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
        <table id="dataTable">
            <thead>
                <tr>
                    <th>SL</th>
                    <th>Date</th>
                    <th>Shift</th>
                    <th>Process Type</th>
                    <th>Project</th>
                    <th>Material Name</th>
                    <th>Qty Consumed</th>
                    <th>Unit</th>
                    <th>Unit Price (৳)</th>
                    <th>Total Cost (৳)</th>
                    <th>Operator</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($consumption_data as $row): 
                    $has_price = $row['unit_price'] > 0;
                ?>
                    <tr<?php echo !$has_price ? ' style="background: #fff3cd;"' : ''; ?>>
                        <td><?php echo $counter++; ?></td>
                        <td><?php echo date('d M, Y', strtotime($row['consumption_date'])); ?></td>
                        <td><?php echo htmlspecialchars($row['shift']); ?></td>
                        <td><?php echo htmlspecialchars($row['consumption_type']); ?></td>
                        <td><strong><?php echo htmlspecialchars($row['project_name']); ?></strong></td>
                        <td>
                            <?php 
                            echo htmlspecialchars($row['material_name']);
                            if (!$has_price) {
                                echo '<br><small style="color: #856404;">âš  <a href="../admin/materials_management.php" style="color: #856404; text-decoration: underline;">Set price in Materials Management</a></small>';
                            }
                            ?>
                        </td>
                        <td><?php echo number_format($row['quantity'], 2); ?></td>
                        <td><?php echo htmlspecialchars($row['unit']); ?></td>
                        <td><?php echo number_format($row['unit_price'], 2); ?></td>
                        <td class="cost-cell">৳<?php echo number_format($row['total_cost'], 2); ?></td>
                        <td><?php echo htmlspecialchars($row['operator']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="6" style="text-align: right;"><strong>TOTAL:</strong></td>
                    <td><strong><?php echo number_format($total_consumed, 2); ?></strong></td>
                    <td></td>
                    <td></td>
                    <td class="cost-cell"><strong>৳<?php echo number_format($total_cost, 2); ?></strong></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<script>
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
    downloadLink.download = 'material_consumption_cost_report.csv';
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = 'none';
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}
</script>
</body>
</html>


