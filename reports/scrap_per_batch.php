<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'production_user', 'qc_inspector', 'management', 'agm ops'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$batchNo = $_GET['batch_no'] ?? '';
$scrapType = $_GET['scrap_type'] ?? '';

// Get scrap data with batch information
$query = "SELECT 
    s.*,
    COALESCE(
        (SELECT full_name FROM new_user WHERE id = s.prod_id LIMIT 1),
        (SELECT username FROM new_user WHERE id = s.prod_id LIMIT 1),
        (SELECT username FROM users WHERE id = s.prod_id LIMIT 1),
        s.who_did,
        (SELECT COALESCE(nu.full_name, nu.username, u.username, 'Admin') 
         FROM scrap_recycle sr2 
         LEFT JOIN new_user nu ON sr2.user_id = nu.id 
         LEFT JOIN users u ON sr2.user_id = u.id 
         WHERE sr2.scrap_id = s.id 
         ORDER BY sr2.recycled_at ASC 
         LIMIT 1),
        'Admin'
    ) as reporter_name,
    (SELECT COUNT(*) FROM scrap_recycle sr WHERE sr.scrap_id = s.id) as recycle_count,
    (SELECT SUM(recycled_qty) FROM scrap_recycle sr WHERE sr.scrap_id = s.id) as total_recycled
FROM scrap s
WHERE s.is_deleted = 0";

$params = [];
$types = '';

if ($dateFrom) {
    $query .= " AND DATE(s.date_time) >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo) {
    $query .= " AND DATE(s.date_time) <= ?";
    $params[] = $dateTo;
    $types .= 's';
}
if ($batchNo) {
    $query .= " AND s.scrap_id LIKE ?";
    $params[] = "%$batchNo%";
    $types .= 's';
}
if ($scrapType) {
    $query .= " AND s.scrap_type = ?";
    $params[] = $scrapType;
    $types .= 's';
}

$query .= " ORDER BY s.date_time DESC";

$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$entries = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate statistics
$totalScrap = array_sum(array_column($entries, 'qty'));
$totalRecycled = array_sum(array_column($entries, 'total_recycled'));

// Group by batch/roll for summary
$batchSummary = [];
foreach ($entries as $entry) {
    $batchId = $entry['scrap_id'] ?: 'Unknown';
    if (!isset($batchSummary[$batchId])) {
        $batchSummary[$batchId] = [
            'scrap_qty' => 0,
            'recycled_qty' => 0,
            'count' => 0,
            'scrap_type' => $entry['scrap_type'] ?? 'N/A',
            'scrap_product' => $entry['scrap_product'] ?? 'N/A',
            'latest_date' => $entry['date_time']
        ];
    }
    $batchSummary[$batchId]['scrap_qty'] += $entry['qty'];
    $batchSummary[$batchId]['recycled_qty'] += $entry['total_recycled'] ?? 0;
    $batchSummary[$batchId]['count']++;
}

// Get unique scrap types for filter
$typeList = $conn->query("SELECT DISTINCT scrap_type FROM scrap WHERE scrap_type IS NOT NULL AND scrap_type != '' AND is_deleted = 0 ORDER BY scrap_type")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scrap Per Batch Report</title>
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
        .stat-card.red { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); }
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
        .reset-btn:hover { background: #7f8c8d; }
        
        .section { margin-bottom: 40px; }
        .section-title { font-size: 1.3em; color: #34495e; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 3px solid #3498db; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 0.9em; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; }
        tr:hover { background: #f8f9fa; }
        
        .badge { padding: 4px 10px; border-radius: 4px; font-size: 0.85em; font-weight: 600; }
        .badge-recycled { background: #d5f4e6; color: #27ae60; }
        .badge-scrap { background: #fadbd8; color: #e74c3c; }
        
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        .export-btn:hover { background: #229954; }
        
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
    <h1><i class="fas fa-boxes"></i> Scrap Per Batch Report</h1>
    <p class="subtitle">Waste per production batch/roll tracking</p>
    
    <div class="stats-grid">
        <div class="stat-card red">
            <div class="stat-value"><?php echo number_format($totalScrap, 2); ?> kg</div>
            <div class="stat-label">Total Scrap Quantity</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value"><?php echo number_format($totalRecycled, 2); ?> kg</div>
            <div class="stat-label">Total Recycled</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-value"><?php echo count($batchSummary); ?></div>
            <div class="stat-label">Unique Batches/Rolls</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo count($entries); ?></div>
            <div class="stat-label">Total Scrap Records</div>
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
                    <label><i class="fas fa-barcode"></i> Batch/Roll No</label>
                    <input type="text" name="batch_no" placeholder="Search batch/roll..." value="<?php echo htmlspecialchars($batchNo); ?>">
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-trash"></i> Scrap Type</label>
                    <select name="scrap_type">
                        <option value="">All Types</option>
                        <?php foreach ($typeList as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['scrap_type']); ?>" 
                                <?php echo $scrapType == $type['scrap_type'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['scrap_type']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Filter</button>
                    <a href="scrap_per_batch.php" class="filter-btn reset-btn" style="text-decoration: none; display: inline-block; text-align: center; line-height: 2;">Reset</a>
                </div>
            </div>
        </div>
    </form>
    
    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print</button>
    <button onclick="exportToCSV()" class="export-btn" style="background: #e67e22;"><i class="fas fa-file-csv"></i> Export CSV</button>
    
    <!-- Batch Summary -->
    <div class="section">
        <h2 class="section-title"><i class="fas fa-layer-group"></i> Summary by Batch/Roll</h2>
        <table id="summaryTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Batch/Roll ID</th>
                    <th>Scrap Type</th>
                    <th>Product</th>
                    <th>Scrap Records</th>
                    <th>Total Scrap (kg)</th>
                    <th>Total Recycled (kg)</th>
                    <th>Waste Rate</th>
                    <th>Latest Date</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($batchSummary as $batchId => $data): 
                    $wasteRate = $data['scrap_qty'] > 0 ? (($data['scrap_qty'] - $data['recycled_qty']) / $data['scrap_qty']) * 100 : 0;
                ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><strong><?php echo htmlspecialchars($batchId); ?></strong></td>
                        <td><?php echo htmlspecialchars($data['scrap_type']); ?></td>
                        <td><?php echo htmlspecialchars($data['scrap_product']); ?></td>
                        <td><?php echo $data['count']; ?></td>
                        <td><?php echo number_format($data['scrap_qty'], 2); ?></td>
                        <td><?php echo number_format($data['recycled_qty'], 2); ?></td>
                        <td>
                            <span class="badge <?php echo $wasteRate < 50 ? 'badge-recycled' : 'badge-scrap'; ?>">
                                <?php echo number_format($wasteRate, 1); ?>%
                            </span>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($data['latest_date'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Detailed Records -->
    <div class="section">
        <h2 class="section-title"><i class="fas fa-list"></i> Detailed Scrap Records</h2>
        <table id="detailTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Scrap ID</th>
                    <th>Date & Time</th>
                    <th>Batch/Roll ID</th>
                    <th>Scrap Type</th>
                    <th>Scrap Product</th>
                    <th>Quantity (kg)</th>
                    <th>Recycled (kg)</th>
                    <th>Status</th>
                    <th>Reporter</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($entries as $entry): 
                    $hasRecycle = ($entry['recycle_count'] > 0);
                ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><strong><?php echo $entry['id']; ?></strong></td>
                        <td><?php echo date('M d, Y g:i A', strtotime($entry['date_time'])); ?></td>
                        <td><?php echo htmlspecialchars($entry['scrap_id'] ?: 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($entry['scrap_type']); ?></td>
                        <td><?php echo htmlspecialchars($entry['scrap_product'] ?? 'N/A'); ?></td>
                        <td><?php echo number_format($entry['qty'], 2); ?></td>
                        <td><?php echo number_format($entry['total_recycled'] ?? 0, 2); ?></td>
                        <td>
                            <span class="badge <?php echo $hasRecycle ? 'badge-recycled' : 'badge-scrap'; ?>">
                                <?php echo $hasRecycle ? 'Recycled' : 'Scrapped'; ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($entry['reporter_name']); ?></td>
                        <td><?php echo htmlspecialchars($entry['remarks'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function exportToCSV() {
    const table = document.getElementById('summaryTable');
    let csv = [];
    
    csv.push(['Scrap Per Batch Summary Report']);
    csv.push(['Generated: ' + new Date().toLocaleString()]);
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
    link.setAttribute('download', 'scrap_per_batch_' + new Date().toISOString().slice(0,10) + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>


