<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'management', 'planning_user', 'finance_user'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$productId = $_GET['product_id'] ?? '';

$query = "SELECT 
    b.*,
    COALESCE(fg.product_name, p.project_name, 'N/A') as product_name,
    COALESCE(m.material_name, 'N/A') as material_name,
    COALESCE(m.price_per_unit, b.unit_price, 0) as effective_price,
    COALESCE(nu.full_name, nu.username, u.username, 'Unknown') as creator_name
FROM bom b
LEFT JOIN fg ON b.product_id = fg.id
LEFT JOIN projects p ON b.product_id = p.id
LEFT JOIN materials m ON b.material_id = m.id
LEFT JOIN new_user nu ON b.created_by = nu.id
LEFT JOIN users u ON b.created_by = u.id
WHERE b.is_deleted = 0";

$params = [];
$types = '';

if ($dateFrom) {
    $query .= " AND DATE(b.created_at) >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo) {
    $query .= " AND DATE(b.created_at) <= ?";
    $params[] = $dateTo;
    $types .= 's';
}
if ($productId) {
    $query .= " AND b.product_id = ?";
    $params[] = $productId;
    $types .= 'i';
}

$query .= " ORDER BY b.created_at DESC";

$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$bomEntries = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalEntries = count($bomEntries);
$totalCost = array_sum(array_column($bomEntries, 'cost'));

$products = $conn->query("SELECT id, product_name FROM fg ORDER BY product_name")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BOM Entry Log</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1800px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; padding: 25px; color: white; text-align: center; }
        .stat-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
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
        
        .table-wrapper {
            overflow-x: auto;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            min-width: 1100px;
            font-size: 13px;
        }
        th, td { 
            padding: 10px 8px; 
            text-align: left; 
            border-bottom: 1px solid #ecf0f1;
            white-space: nowrap;
        }
        th { 
            background: #34495e; 
            color: white; 
            font-weight: 600; 
            position: sticky; 
            top: 0;
            font-size: 12px;
        }
        tr:hover { background: #f8f9fa; }
        
        .material-list { max-width: 250px; white-space: normal; font-size: 0.85em; word-wrap: break-word; }
        
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
    <h1><i class="fas fa-list-alt"></i> BOM Entry Log</h1>
    <p class="subtitle">List of Bill of Materials entries made per product</p>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value" id="total_entries"><?php echo number_format($totalEntries); ?></div>
            <div class="stat-label">Total BOM Entries</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value" id="total_cost">৳<?php echo number_format($totalCost, 2); ?></div>
            <div class="stat-label">Total Cost</div>
        </div>
    </div>
    
    <div id="loadingMessage" style="display: none; text-align: center; padding: 20px; background: #e8f5e9; border-radius: 8px; margin: 20px 0;">
        <i class="fas fa-spinner fa-spin"></i> Loading data...
    </div>
    
    <form method="GET" action="">
        <div class="filters">
            <div class="filter-row">
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> From Date</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" onchange="applyFilters()">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> To Date</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" onchange="applyFilters()">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-box"></i> Product</label>
                    <select id="product_id" name="product_id" onchange="applyFilters()">
                        <option value="">All Products</option>
                        <?php foreach ($products as $prod): ?>
                            <option value="<?php echo $prod['id']; ?>" 
                                <?php echo $productId == $prod['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($prod['product_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="button" class="filter-btn" onclick="applyFilters()"><i class="fas fa-filter"></i> Apply</button>
                    <button type="button" class="filter-btn reset-btn" onclick="resetFilters()">Reset</button>
                </div>
            </div>
        </div>
    </form>
    
    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print</button>
    <button onclick="exportToCSV()" class="export-btn" style="background: #e67e22;"><i class="fas fa-file-csv"></i> Export CSV</button>
    
    <?php if (count($bomEntries) > 0): ?>
    <div class="table-wrapper">
    <table id="bomTable">
        <thead>
            <tr>
                <th>#</th>
                <th>BOM ID</th>
                <th>Material</th>
                <th>Bag Size</th>
                <th>Weight (kg)</th>
                <th>GSM</th>
                <th>Thickness (mm)</th>
                <th>Cost</th>
                <th>Created By</th>
                <th>Created Date</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $counter = 1;
            foreach ($bomEntries as $entry): 
            ?>
                <tr>
                    <td><?php echo $counter++; ?></td>
                    <td><strong><?php echo $entry['id']; ?></strong></td>
                    <td><?php echo htmlspecialchars($entry['material_name']); ?></td>
                    <td><?php echo htmlspecialchars($entry['bag_size'] ?? 'N/A'); ?></td>
                    <td><?php 
                        $weight = $entry['weight'] ?? 0;
                        echo $weight > 0 ? number_format($weight, 2) : '<span style="color:#e67e22;">0.00</span>'; 
                    ?></td>
                    <td><?php 
                        $gsm = $entry['gsm'] ?? 0;
                        echo $gsm > 0 ? number_format($gsm, 2) : '<span style="color:#e67e22;">0.00</span>'; 
                    ?></td>
                    <td><?php 
                        $thickness = $entry['thickness_mm'] ?? 0;
                        echo $thickness > 0 ? number_format($thickness, 2) : '<span style="color:#e67e22;">0.00</span>'; 
                    ?></td>
                    <td><strong>৳<?php echo number_format($entry['cost'] ?? 0, 2); ?></strong></td>
                    <td><?php echo htmlspecialchars($entry['creator_name']); ?></td>
                    <td><?php echo date('M d, Y g:i A', strtotime($entry['created_at'])); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <div style="text-align: center; padding: 60px; color: #95a5a6;">
        <i class="fas fa-file-alt" style="font-size: 4em; margin-bottom: 20px;"></i>
        <p style="font-size: 1.2em;">No BOM entries found matching the selected filters.</p>
    </div>
    <?php endif; ?>
</div>

<script>
function exportToCSV() {
    const table = document.getElementById('bomTable');
    if (!table) return;
    
    let csv = [];
    csv.push(['BOM Entry Log']);
    csv.push(['Generated: ' + new Date().toLocaleString()]);
    csv.push(['Total Entries: <?php echo $totalEntries; ?>']);
    csv.push(['Total Cost: <?php echo number_format($totalCost, 2); ?>']);
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
    link.setAttribute('download', 'bom_entry_log_' + new Date().toISOString().slice(0,10) + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// AJAX functionality
function applyFilters() {
    const dateFrom = document.getElementById('date_from').value;
    const dateTo = document.getElementById('date_to').value;
    const productId = document.getElementById('product_id').value;
    
    const loadingMessage = document.getElementById('loadingMessage');
    loadingMessage.style.display = 'block';
    
    const params = new URLSearchParams();
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (productId) params.append('product_id', productId);
    
    fetch(`api/bom_entry_log_data.php?${params.toString()}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('total_entries').textContent = data.summary.total_entries.toLocaleString('en-BD');
                document.getElementById('total_cost').textContent = '৳' + data.summary.total_cost;
                
                const tbody = document.querySelector('#bomTable tbody');
                if (data.data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;">No data found</td></tr>';
                } else {
                    tbody.innerHTML = data.data.map((row, i) => `
                        <tr>
                            <td>${i + 1}</td>
                            <td>${row.product_name || 'N/A'}</td>
                            <td>${row.material_name || 'N/A'}</td>
                            <td>${parseFloat(row.quantity || 0).toLocaleString('en-BD', {minimumFractionDigits: 2})}</td>
                            <td>৳${parseFloat(row.cost || 0).toLocaleString('en-BD', {minimumFractionDigits: 2})}</td>
                            <td>${row.created_at ? new Date(row.created_at).toLocaleDateString('en-GB') : 'N/A'}</td>
                        </tr>
                    `).join('');
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
    document.getElementById('date_from').value = '';
    document.getElementById('date_to').value = '';
    document.getElementById('product_id').value = '';
    applyFilters();
}
</script>
</body>
</html>


