<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
// Normalize AGM Operations variations
if ($user_role === 'agm operations' || $user_role === 'agm_ops' || $user_role === 'agm_operations') {
    $user_role = 'agm ops';
}
$allowed_roles = ['admin', 'management', 'agm ops', 'finance_user'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Rename qc_inspector column to shift_in_charge if it exists
$colCheck = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'qc_inspector'");
if ($colCheck && $colCheck->num_rows > 0) {
    $colCheck2 = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'shift_in_charge'");
    if (!$colCheck2 || $colCheck2->num_rows == 0) {
        $conn->query("ALTER TABLE fg_entry CHANGE COLUMN qc_inspector shift_in_charge VARCHAR(100)");
    }
}

$referenceNumber = $_GET['reference_number'] ?? '';
$bagSize = $_GET['bag_size'] ?? '';

// Detect columns in fg_entry
$fgCols = [];
$fgColRes = $conn->query("SHOW COLUMNS FROM fg_entry");
if ($fgColRes) {
    while ($r = $fgColRes->fetch_assoc()) {
        $fgCols[] = strtolower($r['Field']);
    }
}
$hasProductType = in_array('product_type', $fgCols, true);

// Get FG stock from fg_entry table (including both bags and rolls)
$query = "SELECT 
    fe.fg_id,
    fe.reference_number,
    fe.cnc_cutting_batch,
    fe.bag_size,
    fe.packaging_type,
    fe.passed_qty,
    fe.actual_weight,
    fe.delivered_quantity,
    fe.status,
    fe.shift_in_charge,
    fe.date_time," .
    ($hasProductType ? " fe.product_type," : " NULL as product_type,") . "
    " . (in_array('roll_entry_type', $fgCols, true) ? "fe.roll_entry_type" : "NULL as roll_entry_type") . ",
    CASE 
        WHEN " . ($hasProductType ? "COALESCE(fe.product_type, 'bag')" : "'bag'") . " = 'roll' THEN (fe.actual_weight - COALESCE(fe.delivered_quantity, 0))
        ELSE (fe.passed_qty - COALESCE(fe.delivered_quantity, 0))
    END as remaining_qty,
    COALESCE(fe.project_id, ftr.project_id) as project_id,
    COALESCE(p1.project_name, p2.project_name, '') as project_name
FROM fg_entry fe
LEFT JOIN fiber_to_roll_entry ftr ON fe.reference_number = ftr.reference_number
LEFT JOIN projects p1 ON fe.project_id = p1.id
LEFT JOIN projects p2 ON ftr.project_id = p2.id
WHERE (
    (" . ($hasProductType ? "fe.product_type = 'roll'" : "1=1") . " AND (fe.actual_weight - COALESCE(fe.delivered_quantity, 0)) > 0)
    OR 
    (" . ($hasProductType ? "(fe.product_type IS NULL OR fe.product_type != 'roll')" : "1=1") . " AND (fe.passed_qty - COALESCE(fe.delivered_quantity, 0)) > 0)
)";

$params = [];
$types = '';

if ($referenceNumber) {
    $query .= " AND fe.reference_number = ?";
    $params[] = $referenceNumber;
    $types .= 's';
}
if ($bagSize) {
    $query .= " AND fe.bag_size = ?";
    $params[] = $bagSize;
    $types .= 's';
}

$query .= " ORDER BY fe.date_time DESC";

$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$stockEntries = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Debug: Check if shift_in_charge column exists and has data (uncomment to debug)
// if (!empty($stockEntries)) {
//     error_log("First entry shift_in_charge value: " . var_export($stockEntries[0]['shift_in_charge'] ?? 'NOT SET', true));
//     error_log("First entry keys: " . implode(', ', array_keys($stockEntries[0] ?? [])));
// }

// Calculate totals
$totalStockWeight = 0;
$totalStockQty = 0;

foreach ($stockEntries as $entry) {
    $productType = $entry['product_type'] ?? null;
    if ($productType == 'roll') {
        // For rolls, remaining_qty is in kg (weight)
        $totalStockWeight += $entry['remaining_qty'];
        $totalStockQty += 1; // Count rolls as pieces
    } else {
        // For bags, remaining_qty is in pieces
    $totalStockQty += $entry['remaining_qty'];
        // Estimate weight if actual_weight is available, otherwise use passed_qty as approximation
        $weight = $entry['actual_weight'] ?? $entry['remaining_qty'];
        $totalStockWeight += $weight;
    }
}

// Group by project
$byProject = [];
foreach ($stockEntries as $entry) {
    $proj = !empty($entry['project_name']) ? $entry['project_name'] : '0';
    if (!isset($byProject[$proj])) {
        $byProject[$proj] = ['qty' => 0, 'weight' => 0, 'count' => 0];
    }
    $productType = $entry['product_type'] ?? null;
    if ($productType == 'roll') {
        $byProject[$proj]['weight'] += $entry['remaining_qty']; // weight in kg
        $byProject[$proj]['qty'] += 1; // count as 1 roll
    } else {
    $byProject[$proj]['qty'] += $entry['remaining_qty'];
        $byProject[$proj]['weight'] += $entry['actual_weight'] ?? $entry['remaining_qty'];
    }
    $byProject[$proj]['count']++;
}


// Group by bag size / product type
$byBagSize = [];
foreach ($stockEntries as $entry) {
    $productType = $entry['product_type'] ?? null;
    if ($productType == 'roll') {
        $size = 'Roll';
    } else {
        $size = $entry['bag_size'] ?? '0';
    }
    if (!isset($byBagSize[$size])) {
        $byBagSize[$size] = ['qty' => 0, 'weight' => 0, 'count' => 0];
    }
    if ($productType == 'roll') {
        $byBagSize[$size]['weight'] += $entry['remaining_qty']; // weight in kg
        $byBagSize[$size]['qty'] += 1; // count as 1 roll
    } else {
    $byBagSize[$size]['qty'] += $entry['remaining_qty'];
        $byBagSize[$size]['weight'] += $entry['actual_weight'] ?? $entry['remaining_qty'];
    }
    $byBagSize[$size]['count']++;
}

// Fetch filter options
$referenceNumbers = $conn->query("SELECT DISTINCT reference_number FROM fg_entry WHERE reference_number IS NOT NULL ORDER BY reference_number DESC")->fetch_all(MYSQLI_ASSOC);
if ($hasProductType) {
$bagSizes = $conn->query("SELECT DISTINCT bag_size FROM fg_entry WHERE bag_size IS NOT NULL AND bag_size != '' AND (product_type IS NULL OR product_type != 'roll') ORDER BY bag_size")->fetch_all(MYSQLI_ASSOC);
} else {
    // product_type missing: return all non-empty bag sizes
    $bagSizes = $conn->query("SELECT DISTINCT bag_size FROM fg_entry WHERE bag_size IS NOT NULL AND bag_size != '' ORDER BY bag_size")->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FG Stock Summary</title>
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
        .stat-value { font-size: 2.5em; font-weight: bold; margin-bottom: 5px; }
        .stat-label { font-size: 0.9em; opacity: 0.95; }
        
        .filters { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-size: 0.85em; font-weight: 600; color: #555; margin-bottom: 5px; }
        .filter-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 0.9em; }
        .filter-btn { background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; }
        .filter-btn:hover { background: #2980b9; }
        .reset-btn { background: #95a5a6; }
        
        .charts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 25px; margin-bottom: 30px; }
        .chart-card { background: #f8f9fa; border-radius: 12px; padding: 25px; }
        .chart-title { font-size: 1.2em; font-weight: 700; color: #2c3e50; margin-bottom: 20px; text-align: center; }
        .chart-container { position: relative; height: 350px; }
        
        .section { margin-bottom: 30px; }
        .section-title { font-size: 1.3em; color: #34495e; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 3px solid #3498db; }
        
        .table-wrapper { overflow-x: auto; width: 100%; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 0.85em; min-width: 1400px; }
        th, td { padding: 10px 8px; text-align: left; border-bottom: 1px solid #ecf0f1; white-space: nowrap; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; z-index: 10; }
        tr:hover { background: #f8f9fa; }
        
        /* Make specific columns more compact */
        th:nth-child(1), td:nth-child(1) { width: 40px; min-width: 40px; } /* # */
        th:nth-child(2), td:nth-child(2) { width: 80px; min-width: 80px; } /* FG ID */
        th:nth-child(3), td:nth-child(3) { width: 100px; min-width: 100px; } /* Product Type */
        th:nth-child(4), td:nth-child(4) { width: 120px; min-width: 120px; } /* Reference */
        th:nth-child(5), td:nth-child(5) { width: 120px; min-width: 120px; } /* CNC Batch */
        th:nth-child(6), td:nth-child(6) { width: 100px; min-width: 100px; } /* Project */
        th:nth-child(7), td:nth-child(7) { width: 100px; min-width: 100px; } /* Bag Size */
        th:nth-child(8), td:nth-child(8) { width: 120px; min-width: 120px; } /* Passed Qty */
        th:nth-child(9), td:nth-child(9) { width: 100px; min-width: 100px; } /* Delivered */
        th:nth-child(10), td:nth-child(10) { width: 100px; min-width: 100px; } /* In Stock */
        th:nth-child(11), td:nth-child(11) { width: 100px; min-width: 100px; } /* Packaging */
        th:nth-child(12), td:nth-child(12) { width: 120px; min-width: 120px; } /* Shift in Charge */
        th:nth-child(13), td:nth-child(13) { width: 100px; min-width: 100px; } /* Date */
        
        .badge { padding: 4px 10px; border-radius: 4px; font-size: 0.85em; font-weight: 600; }
        .badge-stock { background: #d5f4e6; color: #27ae60; }
        
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        
        @media print {
            .filters, .export-btn { display: none; }
            body { background: white; padding: 0; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-warehouse"></i> FG Stock Summary Report</h1>
    <p class="subtitle">Total finished goods (bags and rolls) in stock by project and product type</p>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format(count($stockEntries)); ?></div>
            <div class="stat-label">Items in Stock</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value"><?php echo number_format($totalStockQty); ?></div>
            <div class="stat-label">Total Quantity (pcs/rolls)</div>
        </div>
        <div class="stat-card blue">
            <div class="stat-value"><?php echo number_format($totalStockWeight, 2); ?> kg</div>
            <div class="stat-label">Total Weight</div>
        </div>
    </div>
    
    <form method="GET" action="">
        <div class="filters">
            <div class="filter-row">
                <div class="filter-group">
                    <label><i class="fas fa-hashtag"></i> Reference Number</label>
                    <select name="reference_number">
                        <option value="">All References</option>
                        <?php foreach ($referenceNumbers as $ref): ?>
                            <option value="<?php echo htmlspecialchars($ref['reference_number']); ?>" 
                                <?php echo $referenceNumber == $ref['reference_number'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ref['reference_number']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-box"></i> Bag Size (Bags Only)</label>
                    <select name="bag_size">
                        <option value="">All Bag Sizes</option>
                        <?php foreach ($bagSizes as $size): ?>
                            <option value="<?php echo htmlspecialchars($size['bag_size']); ?>" 
                                <?php echo $bagSize == $size['bag_size'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($size['bag_size']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Filter</button>
                    <a href="fg_stock_summary.php" class="filter-btn reset-btn" style="text-decoration: none; display: inline-block; text-align: center; line-height: 2;">Reset</a>
                </div>
            </div>
        </div>
    </form>
    
    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print</button>
    <button onclick="exportToCSV()" class="export-btn" style="background: #e67e22;"><i class="fas fa-file-csv"></i> Export CSV</button>
    <button onclick="exportToExcel()" class="export-btn" style="background: #27ae60;"><i class="fas fa-file-excel"></i> Export Excel</button>
    
    <?php if (count($stockEntries) > 0): ?>
    <div class="charts-grid">
        <div class="chart-card">
            <div class="chart-title">Stock by Project</div>
            <div class="chart-container">
                <canvas id="projectChart"></canvas>
            </div>
        </div>
        
        <div class="chart-card">
            <div class="chart-title">Stock by Bag Size / Product Type</div>
            <div class="chart-container">
                <canvas id="bagSizeChart"></canvas>
            </div>
        </div>
    </div>
    
    <div class="section">
        <h2 class="section-title"><i class="fas fa-table"></i> Detailed Stock Inventory</h2>
        <div class="table-wrapper">
        <table id="stockTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>FG ID</th>
                    <th>Product Type</th>
                    <th>Reference Number</th>
                    <th>CNC Cutting Batch</th>
                    <th>Project</th>
                    <th>Bag Size</th>
                    <th>Passed Qty / Weight</th>
                    <th>Delivered</th>
                    <th>In Stock</th>
                    <th>Packaging</th>
                    <th>Shift in Charge</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($stockEntries as $entry): 
                ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><strong><?php echo htmlspecialchars($entry['fg_id']); ?></strong></td>
                        <td>
                            <?php 
                            $productType = $entry['product_type'] ?? 'bag';
                            echo $productType == 'roll' ? '<span style="color: #e67e22; font-weight: 600;">Roll</span>' : '<span style="color: #3498db; font-weight: 600;">Bag</span>'; 
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($entry['reference_number'] ?? '0'); ?></td>
                        <td><?php echo !empty($entry['cnc_cutting_batch']) ? htmlspecialchars($entry['cnc_cutting_batch']) : '0'; ?></td>
                        <td><?php 
                            $projectName = trim($entry['project_name'] ?? '');
                            echo !empty($projectName) && $projectName !== '0' ? htmlspecialchars($projectName) : '0'; 
                        ?></td>
                        <td><?php echo $productType == 'roll' ? '0' : (!empty($entry['bag_size']) ? htmlspecialchars($entry['bag_size']) : '0'); ?></td>
                        <td>
                            <?php 
                            if ($productType == 'roll') {
                                echo number_format($entry['actual_weight'] ?? 0, 2) . ' kg';
                            } else {
                                echo number_format($entry['passed_qty'] ?? 0, 0) . ' pcs';
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            if ($productType == 'roll') {
                                echo number_format($entry['delivered_quantity'] ?? 0, 2) . ' kg';
                            } else {
                                echo number_format($entry['delivered_quantity'] ?? 0, 0) . ' pcs';
                            }
                            ?>
                        </td>
                        <td>
                            <span class="badge badge-stock">
                                <?php 
                                if ($productType == 'roll') {
                                    echo number_format($entry['remaining_qty'], 2) . ' kg';
                                } else {
                                    echo number_format($entry['remaining_qty'], 0) . ' pcs';
                                }
                                ?>
                            </span>
                        </td>
                        <td><?php echo !empty($entry['packaging_type']) ? htmlspecialchars($entry['packaging_type']) : '0'; ?></td>
                        <td><?php 
                            // Get shift_in_charge value
                            $shiftInCharge = $entry['shift_in_charge'] ?? null;
                            
                            // Convert to string and check if it's valid
                            if ($shiftInCharge !== null && $shiftInCharge !== false) {
                                $shiftInCharge = trim((string)$shiftInCharge);
                                // Display if not empty, not '0', and not just whitespace
                                if ($shiftInCharge !== '' && $shiftInCharge !== '0' && strlen($shiftInCharge) > 0) {
                                    echo htmlspecialchars($shiftInCharge);
                                } else {
                                    echo '0';
                                }
                            } else {
                                echo '0';
                            }
                        ?></td>
                        <td><?php echo date('M d, Y', strtotime($entry['date_time'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php else: ?>
    <div style="text-align: center; padding: 60px; color: #95a5a6;">
        <i class="fas fa-box-open" style="font-size: 4em; margin-bottom: 20px;"></i>
        <p style="font-size: 1.2em;">No stock available matching the selected filters.</p>
    </div>
    <?php endif; ?>
  </div>

<script>
<?php if (count($stockEntries) > 0): ?>
// Project Chart
const projectCtx = document.getElementById('projectChart');
new Chart(projectCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_keys($byProject)); ?>,
        datasets: [{
            data: <?php echo json_encode(array_column($byProject, 'qty')); ?>,
            backgroundColor: ['#3498db', '#e74c3c', '#f39c12', '#27ae60', '#9b59b6', '#1abc9c', '#e67e22']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'right' } }
    }
});

// Bag Size Chart
const bagSizeCtx = document.getElementById('bagSizeChart');
new Chart(bagSizeCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($byBagSize)); ?>,
        datasets: [{
            label: 'Quantity',
            data: <?php echo json_encode(array_column($byBagSize, 'qty')); ?>,
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
<?php endif; ?>

function exportToCSV() {
    const table = document.getElementById('stockTable');
    if (!table) return;
    
    let csv = [];
    csv.push(['FG Stock Summary Report']);
    csv.push(['Generated: ' + new Date().toLocaleString()]);
    csv.push(['Total Items: <?php echo count($stockEntries); ?>']);
    csv.push(['Total Quantity: <?php echo $totalStockQty; ?> pcs/rolls']);
    csv.push(['Total Weight: <?php echo number_format($totalStockWeight, 2); ?> kg']);
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
    link.setAttribute('download', 'fg_stock_summary_' + new Date().toISOString().slice(0,10) + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function exportToExcel() {
    exportToCSV(); // Same as CSV for now
}
</script>
</body>
</html>

