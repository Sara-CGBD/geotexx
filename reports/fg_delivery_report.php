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
$allowed_roles = ['admin', 'management', 'agm ops', 'finance_user', 'delivery_user'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$clientName = $_GET['client_name'] ?? '';
$referenceNumber = $_GET['reference_number'] ?? '';

// Performance: Set default date range (last 30 days) if no filters provided
$hasDateFilter = !empty($dateFrom) || !empty($dateTo);
if (!$hasDateFilter) {
    $dateTo = date('Y-m-d');
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
}

$query = "SELECT 
    d.*,
    fe.fg_id,
    fe.bag_size as fg_bag_size,
    fe.packaging_type as fg_packaging_type,
    p.project_name
FROM fg_deliveries d
LEFT JOIN fg_entry fe ON d.fg_entry_id = fe.id
LEFT JOIN projects p ON fe.project_id = p.id
WHERE 1=1";

$params = [];
$types = '';

if ($dateFrom) {
    $query .= " AND d.delivery_date >= ?";
    $params[] = $dateFrom . ' 00:00:00';
    $types .= 's';
}
if ($dateTo) {
    $query .= " AND d.delivery_date <= ?";
    $params[] = $dateTo . ' 23:59:59';
    $types .= 's';
}
if ($clientName) {
    $query .= " AND d.client_name LIKE ?";
    $params[] = "%$clientName%";
    $types .= 's';
}
if ($referenceNumber) {
    $query .= " AND d.reference_number = ?";
    $params[] = $referenceNumber;
    $types .= 's';
}

$query .= " ORDER BY d.delivery_date DESC, d.delivery_id DESC LIMIT 1000";

$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$deliveries = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalDeliveries = count($deliveries);
$totalQuantity = array_sum(array_column($deliveries, 'delivery_quantity'));
$totalValue = array_sum(array_map(function($d) {
    return $d['delivery_quantity'] * ($d['unit_price'] ?? 0);
}, $deliveries));

// Group by client
$byClient = [];
foreach ($deliveries as $delivery) {
    $client = $delivery['client_name'] ?? 'Unknown';
    if (!isset($byClient[$client])) {
        $byClient[$client] = ['count' => 0, 'qty' => 0, 'value' => 0];
    }
    $byClient[$client]['count']++;
    $byClient[$client]['qty'] += $delivery['delivery_quantity'];
    $byClient[$client]['value'] += $delivery['delivery_quantity'] * ($delivery['unit_price'] ?? 0);
}

// Performance: Defer filter options - load asynchronously after page render
$clientNames = [];
$referenceNumbers = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FG Delivery Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; overflow-x: hidden; }
        .container { max-width: 1800px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); box-sizing: border-box; width: 100%; }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }
        
        .stats-grid { 
            display: grid; 
            grid-template-columns: repeat(4, 1fr); 
            gap: 20px; 
            margin-bottom: 30px; 
            width: 100%;
            box-sizing: border-box;
        }
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        @media (max-width: 600px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
        .stat-card { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            border-radius: 16px; 
            padding: 25px 20px; 
            color: white; 
            text-align: center; 
            box-shadow: 0 8px 16px rgba(102, 126, 234, 0.25), 0 4px 8px rgba(0, 0, 0, 0.1); 
            transition: all 0.3s ease; 
            position: relative;
            overflow: hidden;
            box-sizing: border-box;
            min-width: 0;
            max-width: 100%;
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .stat-card:hover { 
            transform: translateY(-4px); 
            box-shadow: 0 12px 24px rgba(102, 126, 234, 0.35), 0 6px 12px rgba(0, 0, 0, 0.15);
        }
        .stat-card:hover::before {
            opacity: 1;
        }
        .stat-card.green { 
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); 
            box-shadow: 0 8px 16px rgba(17, 153, 142, 0.25), 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .stat-card.green:hover {
            box-shadow: 0 12px 24px rgba(17, 153, 142, 0.35), 0 6px 12px rgba(0, 0, 0, 0.15);
        }
        .stat-card.blue { 
            background: linear-gradient(135deg, #2980b9 0%, #3498db 100%); 
            box-shadow: 0 8px 16px rgba(41, 128, 185, 0.25), 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .stat-card.blue:hover {
            box-shadow: 0 12px 24px rgba(41, 128, 185, 0.35), 0 6px 12px rgba(0, 0, 0, 0.15);
        }
        .stat-card.amber { 
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); 
            box-shadow: 0 8px 16px rgba(245, 87, 108, 0.25), 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        .stat-card.amber:hover {
            box-shadow: 0 12px 24px rgba(245, 87, 108, 0.35), 0 6px 12px rgba(0, 0, 0, 0.15);
        }
        .stat-value { 
            font-size: 2.5em; 
            font-weight: 700; 
            margin-bottom: 8px; 
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            position: relative;
            z-index: 1;
            word-break: break-word;
            line-height: 1.2;
        }
        .stat-label { 
            font-size: 0.95em; 
            opacity: 0.95; 
            font-weight: 500; 
            letter-spacing: 0.5px;
            position: relative;
            z-index: 1;
        }
        
        .filters { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-size: 0.85em; font-weight: 600; color: #555; margin-bottom: 5px; }
        .filter-group input, .filter-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 0.9em; }
        .filter-btn { background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; }
        .filter-btn:hover { background: #2980b9; }
        .reset-btn { background: #95a5a6; }
        
        .chart-card { background: #f8f9fa; border-radius: 12px; padding: 25px; margin-bottom: 30px; }
        .chart-title { font-size: 1.2em; font-weight: 700; color: #2c3e50; margin-bottom: 20px; text-align: center; }
        .chart-container { position: relative; height: 350px; max-width: 800px; margin: 0 auto; }
        
        .table-wrapper {
            overflow-x: auto;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-bottom: 20px; 
            min-width: 1200px;
            font-size: 12px;
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
            font-size: 11px;
        }
        tr:hover { background: #f8f9fa; }
        
        .badge { padding: 4px 10px; border-radius: 4px; font-size: 0.85em; font-weight: 600; }
        .badge-delivered { background: #d5f4e6; color: #27ae60; }
        
        .challan-btn { background: #3498db; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.85em; }
        .challan-btn:hover { background: #2980b9; }
        
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        
        @media print {
            .filters, .export-btn, .challan-btn { display: none; }
            body { background: white; padding: 0; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-truck"></i> FG Delivery Report</h1>
    <p class="subtitle">Delivery log of finished goods items with client tracking</p>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($totalDeliveries); ?></div>
            <div class="stat-label">Total Deliveries</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value"><?php echo number_format($totalQuantity, 2); ?></div>
            <div class="stat-label">Total Quantity Delivered (mixed units)</div>
        </div>
        <div class="stat-card blue">
            <div class="stat-value">৳<?php echo number_format($totalValue, 2); ?></div>
            <div class="stat-label">Total Value</div>
        </div>
        <div class="stat-card amber">
            <div class="stat-value"><?php echo count($byClient); ?></div>
            <div class="stat-label">Unique Clients</div>
        </div>
    </div>
    
    <form method="GET" action="">
        <div class="filters">
            <div class="filter-row">
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> From Date</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> To Date</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-building"></i> Client Name</label>
                    <select name="client_name">
                        <option value="">All Clients</option>
                        <?php foreach ($clientNames as $client): ?>
                            <option value="<?php echo htmlspecialchars($client['client_name']); ?>" 
                                <?php echo $clientName == $client['client_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($client['client_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
                    <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Filter</button>
                    <a href="fg_delivery_report.php" class="filter-btn reset-btn" style="text-decoration: none; display: inline-block; text-align: center; line-height: 2;">Reset</a>
                </div>
            </div>
        </div>
    </form>
    
    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print</button>
    <button onclick="exportToCSV()" class="export-btn" style="background: #e67e22;"><i class="fas fa-file-csv"></i> Export CSV</button>
    
    <?php if (count($byClient) > 0): ?>
    <div class="chart-card">
        <div class="chart-title">Deliveries by Client</div>
        <div class="chart-container">
            <canvas id="clientChart"></canvas>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (count($deliveries) > 0): ?>
    <h2 style="font-size: 1.3em; color: #34495e; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 3px solid #3498db;">
        <i class="fas fa-list"></i> Delivery Records
    </h2>
    <div class="table-wrapper">
    <table id="deliveryTable">
        <thead>
            <tr>
                <th>#</th>
                <th>Delivery ID</th>
                <th>Date</th>
                <th>Shift</th>
                <th>Reference No.</th>
                <th>CNC Cutting Batch</th>
                <th>Project</th>
                <th>Client</th>
                <th>Bag Size</th>
                <th>Packaging</th>
                <th>Quantity</th>
                <th>Unit Price</th>
                <th>Total Value</th>
                <th>Lighthouse Challan No.</th>
                <th>Truck No.</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $counter = 1;
            foreach ($deliveries as $delivery): 
                $rowTotalValue = $delivery['delivery_quantity'] * ($delivery['unit_price'] ?? 0);
            ?>
                <tr>
                    <td><?php echo $counter++; ?></td>
                    <td><strong><?php echo htmlspecialchars($delivery['delivery_id'] ?? 'N/A'); ?></strong></td>
                    <td><?php 
                        $deliveryDate = $delivery['delivery_date'];
                        if (!empty($deliveryDate) && $deliveryDate != '0000-00-00' && $deliveryDate != '0000-00-00 00:00:00' && strtotime($deliveryDate)) {
                            echo date('M d, Y g:i A', strtotime($deliveryDate));
                        } else {
                            echo 'N/A';
                        }
                    ?></td>
                    <td><span class="badge" style="background: <?php echo ($delivery['shift'] == 'Day') ? '#d5f4e6' : '#e3f2fd'; ?>; color: <?php echo ($delivery['shift'] == 'Day') ? '#27ae60' : '#2980b9'; ?>;"><?php echo htmlspecialchars($delivery['shift'] ?? 'N/A'); ?></span></td>
                    <td><?php echo htmlspecialchars($delivery['reference_number'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($delivery['cnc_cutting_batch'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($delivery['project_name'] ?? 'Unassigned'); ?></td>
                    <td><strong><?php 
                        $clientName = $delivery['client_name'] ?? '';
                        echo htmlspecialchars((!empty($clientName) && $clientName != '0') ? $clientName : 'Unknown'); 
                    ?></strong></td>
                    <td><?php echo htmlspecialchars($delivery['bag_size'] ?: $delivery['fg_bag_size'] ?: 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($delivery['packaging_type'] ?: $delivery['fg_packaging_type'] ?: 'N/A'); ?></td>
                    <td>
                        <span class="badge badge-delivered">
                            <?php 
                            $deliveryUnit = $delivery['delivery_unit'] ?? 'piece';
                            $unitLabel = ($deliveryUnit === 'kg') ? 'kg' : 'pcs';
                            echo number_format($delivery['delivery_quantity'], 2) . ' ' . $unitLabel; 
                            ?>
                        </span>
                    </td>
                    <td>৳<?php echo number_format($delivery['unit_price'] ?? 0, 2); ?> / <?php echo ($deliveryUnit === 'kg') ? 'kg' : 'pc'; ?></td>
                    <td><strong>৳<?php echo number_format($rowTotalValue, 2); ?></strong></td>
                    <td><?php echo htmlspecialchars($delivery['challan_no'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($delivery['truck_no'] ?? 'N/A'); ?></td>
                    <td>
                        <button class="challan-btn" onclick="printChallan(<?php echo $delivery['id']; ?>)">
                            <i class="fas fa-file-invoice"></i> Challan
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <div style="text-align: center; padding: 60px; color: #95a5a6;">
        <i class="fas fa-truck-loading" style="font-size: 4em; margin-bottom: 20px;"></i>
        <p style="font-size: 1.2em;">No deliveries found matching the selected filters.</p>
    </div>
    <?php endif; ?>
</div>

<script>
<?php if (count($byClient) > 0): ?>
const clientCtx = document.getElementById('clientChart');
new Chart(clientCtx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_keys($byClient)); ?>,
        datasets: [{
            label: 'Deliveries',
            data: <?php echo json_encode(array_column($byClient, 'count')); ?>,
            backgroundColor: 'rgba(52, 152, 219, 0.7)',
            borderColor: '#3498db',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true } }
    }
});
<?php endif; ?>

function printChallan(deliveryId) {
    // Open challan in new window for printing
    const challanWindow = window.open('fg_delivery_challan.php?id=' + deliveryId, '_blank', 'width=900,height=800');
    if (!challanWindow) {
        alert('Please allow pop-ups to view the delivery challan');
    }
}

function exportToCSV() {
    const table = document.getElementById('deliveryTable');
    if (!table) return;
    
    let csv = [];
    csv.push(['FG Delivery Report']);
    csv.push(['Generated: ' + new Date().toLocaleString()]);
    csv.push(['Total Deliveries: <?php echo $totalDeliveries; ?>']);
    csv.push(['Total Quantity: <?php echo $totalQuantity; ?> pcs']);
    csv.push([]);
    
    const headers = Array.from(table.querySelectorAll('thead th')).slice(0, -1).map(th => th.textContent);
    csv.push(headers.join(','));
    
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cols = Array.from(row.querySelectorAll('td')).slice(0, -1).map(td => {
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
    link.setAttribute('download', 'fg_delivery_report_' + new Date().toISOString().slice(0,10) + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>


