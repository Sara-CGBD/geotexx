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

// Role-based access control for Material Requirement Report
// Allowed roles: Admin, Management, Planning User, AGM Ops, Production User
$allowed_roles = ['admin', 'management', 'planning_user', 'agm ops', 'agm operations', 'production_user'];
$user_role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>ðŸš« Access Denied</h2>
        <p>You do not have permission to access Material Requirement Report.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <p>Allowed roles: Admin, Management, Planning User, AGM Operations, Production User</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// Database connection
require_once '../forms/security_config.php';
$conn = SecurityConfig::getConnection();

// Schema helpers
function mrrColExists(mysqli $conn, string $table, string $column): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    $col = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    return $res && $res->num_rows > 0;
}

// Get filters
$material_filter = $_GET['material'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Query to get material requirements from BOM and compare with available stock
$query = "
    SELECT 
        m.id,
        m.material_name,
        COALESCE(SUM(b.unit_price), 0) as total_required_value,
        COUNT(DISTINCT CASE WHEN b.id IS NOT NULL THEN b.product_id END) as products_using,
        COALESCE(
            (SELECT COALESCE(SUM(" . (
                    mrrColExists($conn, 'fiber_entries', 'amount_kg') ? "fe.amount_kg" :
                    (mrrColExists($conn, 'fiber_entries', 'amount') ? "fe.amount" :
                    (mrrColExists($conn, 'fiber_entries', 'total_amount') ? "fe.total_amount" : "0"))
                ) . "), 0)
             FROM fiber_entries fe 
             WHERE LOWER(fe.material_type) = LOWER(m.material_name)
                " . (mrrColExists($conn, 'fiber_entries', 'is_deleted') ? "AND fe.is_deleted = 0" : "") . "
            ), 
            0
        ) as available_stock,
        (
            COALESCE(
                (SELECT COALESCE(SUM(ftre.total_weight), 0)
                 FROM fiber_to_roll_entry ftre 
                 WHERE LOWER(ftre.material_type) = LOWER(m.material_name)), 
                0
            ) + 
            COALESCE(
                (SELECT COALESCE(SUM(mc.quantity), 0)
                 FROM material_consumption mc 
                 WHERE mc.material_id = m.id
                 AND mc.is_deleted = 0), 
                0
            )
        ) as used_stock,
        COALESCE(SUM(b.cost), 0) as total_bom_cost,
        COUNT(CASE WHEN b.id IS NOT NULL THEN b.id END) as bom_entries
    FROM materials m
    LEFT JOIN bom b ON m.id = b.material_id AND b.is_deleted = 0
    WHERE m.is_deleted = 0
";

$params = [];
$types = '';

if (!empty($material_filter)) {
    $query .= " AND m.id = ?";
    $params[] = $material_filter;
    $types .= "i";
}

$query .= " GROUP BY m.id, m.material_name ORDER BY m.material_name";

$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$materials_data = [];

while ($row = $result->fetch_assoc()) {
    // Calculate net available (available - used)
    $net_available = $row['available_stock'] - $row['used_stock'];
    
    // Determine status based on available vs required
    // For simplicity, we assume requirement = total_required_value or products_using
    // You can adjust this logic based on actual requirements
    $required_qty = $row['products_using'] * 100; // Example: each product needs 100 units
    
    if ($net_available >= $required_qty) {
        $status = 'OK';
    } elseif ($net_available >= ($required_qty * 0.5)) {
        $status = 'Low Stock';
    } else {
        $status = 'Shortage';
    }
    
    $row['net_available'] = $net_available;
    $row['required_qty'] = $required_qty;
    $row['status'] = $status;
    
    // Apply status filter
    if (empty($status_filter) || $status_filter === $status) {
        $materials_data[] = $row;
    }
}

$stmt->close();

// Get all materials for filter dropdown
$materials_list = $conn->query("SELECT id, material_name FROM materials ORDER BY material_name")->fetch_all(MYSQLI_ASSOC);

// Calculate summary statistics
$total_materials = count($materials_data);
$shortage_count = count(array_filter($materials_data, fn($m) => $m['status'] === 'Shortage'));
$low_stock_count = count(array_filter($materials_data, fn($m) => $m['status'] === 'Low Stock'));
$ok_count = count(array_filter($materials_data, fn($m) => $m['status'] === 'OK'));

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Material Requirement Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background:rgb(215, 220, 228);
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
            color:rgb(63, 79, 95);
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
        .filter-group select {
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
        .btn-success {
            background: #27ae60;
            color: white;
        }
        .table-wrapper {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
            font-size: 13px;
        }
        th, td {
            padding: 10px 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            white-space: nowrap;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
            color: #2c3e50;
            position: sticky;
            top: 0;
            font-size: 12px;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .status-badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }
        .status-ok {
            background: #d4edda;
            color: #155724;
        }
        .status-low {
            background: #fff3cd;
            color: #856404;
        }
        .status-shortage {
            background: #f8d7da;
            color: #721c24;
        }
        .action-buttons {
            margin: 20px 0;
            display: flex;
            gap: 10px;
        }
        .legend {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .legend-color {
            width: 20px;
            height: 20px;
            border-radius: 4px;
        }
        @media print {
            @page {
                size: A4 landscape;
                margin: 10mm;
            }
            .filters, .action-buttons { display: none; }
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
    <h1><i class="fas fa-boxes"></i> Material Requirement Report</h1>
    <p class="subtitle">Raw material required vs available with stock status</p>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <a href="../index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print"></i> Print
        </button>
        <button onclick="exportToCSV()" class="btn btn-success">
            <i class="fas fa-file-csv"></i> Export CSV
        </button>
    </div>

    <!-- Summary Statistics -->
    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="stat-label">Total Materials</div>
            <div class="stat-value" id="total_materials"><?php echo $total_materials; ?></div>
        </div>
        <div class="stat-card green">
            <div class="stat-label">OK Stock</div>
            <div class="stat-value" id="ok_count"><?php echo $ok_count; ?></div>
        </div>
        <div class="stat-card orange">
            <div class="stat-label">Low Stock</div>
            <div class="stat-value" id="low_stock_count"><?php echo $low_stock_count; ?></div>
        </div>
        <div class="stat-card red">
            <div class="stat-label">Shortage</div>
            <div class="stat-value" id="shortage_count"><?php echo $shortage_count; ?></div>
        </div>
    </div>

    <div id="loadingMessage" style="display: none; text-align: center; padding: 20px; background: #e8f5e9; border-radius: 8px; margin: 20px 0;">
        <i class="fas fa-spinner fa-spin"></i> Loading data...
    </div>

    <!-- Legend -->
    <div class="legend">
        <div class="legend-item">
            <div class="legend-color" style="background: #d4edda;"></div>
            <span><strong>OK:</strong> Available â‰¥ Required</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: #fff3cd;"></div>
            <span><strong>Low Stock:</strong> Available = 50-99% of Required</span>
        </div>
        <div class="legend-item">
            <div class="legend-color" style="background: #f8d7da;"></div>
            <span><strong>Shortage:</strong> Available < 50% of Required</span>
        </div>
    </div>
    
    <div style="background: #e3f2fd; padding: 12px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #2196f3;">
        <strong><i class="fas fa-info-circle"></i> Note:</strong> Available Stock shows fiber entries for each material type. Used Stock shows actual material consumption from fiber-to-roll conversions. Make sure to specify the material type when creating fiber entries for accurate tracking.
    </div>

    <!-- Filters -->
    <form method="GET" class="filters">
        <div class="filter-group">
            <label>Material:</label>
            <select id="material" name="material" onchange="applyFilters()">
                <option value="">All Materials</option>
                <?php foreach ($materials_list as $mat): ?>
                    <option value="<?php echo $mat['id']; ?>" <?php echo ($material_filter == $mat['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($mat['material_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Status:</label>
            <select id="status" name="status" onchange="applyFilters()">
                <option value="">All Status</option>
                <option value="OK" <?php echo ($status_filter === 'OK') ? 'selected' : ''; ?>>OK</option>
                <option value="Low Stock" <?php echo ($status_filter === 'Low Stock') ? 'selected' : ''; ?>>Low Stock</option>
                <option value="Shortage" <?php echo ($status_filter === 'Shortage') ? 'selected' : ''; ?>>Shortage</option>
            </select>
        </div>
        <div class="filter-group" style="align-self: flex-end;">
            <button type="button" class="btn btn-primary" style="width: 100%;" onclick="applyFilters()">
                <i class="fas fa-filter"></i> Apply
            </button>
        </div>
        <div class="filter-group" style="align-self: flex-end;">
            <button type="button" class="btn btn-secondary" style="width: 100%;" onclick="resetFilters()">
                <i class="fas fa-redo"></i> Reset
            </button>
        </div>
    </form>

    <!-- Material Requirement Table -->
    <?php if (empty($materials_data)): ?>
        <div style="text-align: center; padding: 40px; color: #7f8c8d;">
            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 20px;"></i>
            <p>No material data found for the selected filters.</p>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
        <table id="dataTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Material Name</th>
                    <th>Products Using</th>
                    <th>BOM Entries</th>
                    <th>Available Stock</th>
                    <th>Used Stock</th>
                    <th>Net Available</th>
                    <th>Required Qty</th>
                    <th>Difference</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($materials_data as $row): 
                    $difference = $row['net_available'] - $row['required_qty'];
                    $status_class = 'status-ok';
                    if ($row['status'] === 'Low Stock') $status_class = 'status-low';
                    if ($row['status'] === 'Shortage') $status_class = 'status-shortage';
                ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><strong><?php echo htmlspecialchars($row['material_name']); ?></strong></td>
                        <td><?php echo $row['products_using']; ?></td>
                        <td><?php echo $row['bom_entries']; ?></td>
                        <td><?php echo number_format($row['available_stock'], 2); ?></td>
                        <td><?php echo number_format($row['used_stock'], 2); ?></td>
                        <td><strong><?php echo number_format($row['net_available'], 2); ?></strong></td>
                        <td><?php echo number_format($row['required_qty'], 2); ?></td>
                        <td style="color: <?php echo $difference >= 0 ? '#27ae60' : '#e74c3c'; ?>; font-weight: 600;">
                            <?php echo ($difference >= 0 ? '+' : '') . number_format($difference, 2); ?>
                        </td>
                        <td>
                            <span class="status-badge <?php echo $status_class; ?>">
                                <?php echo $row['status']; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>

<script>
// Export to CSV function
function exportToCSV() {
    const table = document.getElementById('dataTable');
    if (!table) return;
    
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
    a.download = 'material_requirement_report_' + new Date().toISOString().split('T')[0] + '.csv';
    a.click();
}

// AJAX functionality
function applyFilters() {
    const material = document.getElementById('material').value;
    const status = document.getElementById('status').value;
    
    const loadingMessage = document.getElementById('loadingMessage');
    loadingMessage.style.display = 'block';
    
    const params = new URLSearchParams();
    if (material) params.append('material', material);
    if (status) params.append('status', status);
    
    fetch(`api/material_requirement_data.php?${params.toString()}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('total_materials').textContent = data.summary.total_materials;
                document.getElementById('ok_count').textContent = data.summary.ok_count;
                document.getElementById('low_stock_count').textContent = data.summary.low_stock_count;
                document.getElementById('shortage_count').textContent = data.summary.shortage_count;
                
                const tbody = document.querySelector('#dataTable tbody');
                if (data.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:20px;">No data found</td></tr>';
                } else {
                    tbody.innerHTML = data.data.map((row, i) => {
                        const statusClass = row.status === 'OK' ? 'status-ok' : 
                                           row.status === 'Low Stock' ? 'status-low' : 'status-shortage';
                        return `
                            <tr class="${statusClass}">
                                <td>${i + 1}</td>
                                <td>${row.material_name || 'N/A'}</td>
                                <td>${row.products_using || 0}</td>
                                <td>${row.bom_entries || 0}</td>
                                <td>${parseFloat(row.available_stock || 0).toLocaleString('en-BD', {minimumFractionDigits: 2})}</td>
                                <td>${parseFloat(row.used_stock || 0).toLocaleString('en-BD', {minimumFractionDigits: 2})}</td>
                                <td>${parseFloat(row.net_available || 0).toLocaleString('en-BD', {minimumFractionDigits: 2})}</td>
                                <td>${parseFloat(row.total_required || 0).toLocaleString('en-BD', {minimumFractionDigits: 2})}</td>
                                <td>${parseFloat(row.difference || 0).toLocaleString('en-BD', {minimumFractionDigits: 2})}</td>
                                <td><span class="status-badge ${statusClass}">${row.status || 'N/A'}</span></td>
                            </tr>
                        `;
                    }).join('');
                }
            }
            loadingMessage.style.display = 'none';
        })
        .catch(error => {
            console.error('Error:', error);
            loadingMessage.style.display = 'none';
        });
}

function resetFilters() {
    document.getElementById('material').value = '';
    document.getElementById('status').value = '';
    applyFilters();
}
</script>
</body>
</html>



