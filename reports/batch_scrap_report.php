<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'production_user', 'management', 'agm ops'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access Scrap Reports.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$batchFilter = $_GET['batch'] ?? '';

// Build query for Sewing Scrap (linked to CNC cutting batches)
$whereConditions = ["s.is_deleted = 0", "s.scrap_category IN ('Sewing Scrap', 'Swing Scrap')", "s.cutting_batch IS NOT NULL"];
$params = [];
$types = '';

if (!empty($dateFrom) && !empty($dateTo)) {
    $whereConditions[] = "DATE(s.date_time) BETWEEN ? AND ?";
    $params[] = $dateFrom;
    $params[] = $dateTo;
    $types .= 'ss';
}

if (!empty($batchFilter)) {
    $whereConditions[] = "s.cutting_batch LIKE ?";
    $params[] = "%$batchFilter%";
    $types .= 's';
}

$whereClause = implode(' AND ', $whereConditions);

// Get batch-wise summary
$summaryQuery = "SELECT 
    s.cutting_batch,
    COUNT(*) as entry_count,
    SUM(s.qty) as total_scrap_qty,
    MIN(DATE(s.date_time)) as first_scrap_date,
    MAX(DATE(s.date_time)) as last_scrap_date
FROM scrap s
WHERE $whereClause
GROUP BY s.cutting_batch
ORDER BY total_scrap_qty DESC";

$stmt = $conn->prepare($summaryQuery);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$summaryData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get detailed scrap entries
$detailQuery = "SELECT 
    s.scrap_id,
    s.cutting_batch,
    s.date_time,
    s.shift,
    s.scrap_category,
    s.scrap_type,
    s.scrap_product,
    s.qty
FROM scrap s
WHERE $whereClause
ORDER BY s.cutting_batch ASC, s.date_time DESC";

$stmt = $conn->prepare($detailQuery);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$detailData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get unique batches for filter dropdown
$batchesQuery = "SELECT DISTINCT s.cutting_batch 
                 FROM scrap s 
                 WHERE s.is_deleted = 0 
                   AND s.scrap_category IN ('Sewing Scrap', 'Swing Scrap') 
                   AND s.cutting_batch IS NOT NULL 
                 ORDER BY s.cutting_batch DESC 
                 LIMIT 100";
$batchesResult = $conn->query($batchesQuery);
$batches = $batchesResult ? $batchesResult->fetch_all(MYSQLI_ASSOC) : [];

// Calculate statistics
$totalEntries = count($detailData);
$grandTotalQty = array_sum(array_column($detailData, 'qty'));
$uniqueBatches = count($summaryData);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>CNC Cutting Batch-wise Scrap Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1600px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }
        
        /* Statistics Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; padding: 25px; color: white; text-align: center; }
        .stat-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-card.orange { background: linear-gradient(135deg, #ee0979 0%, #ff6a00 100%); }
        .stat-card.blue { background: linear-gradient(135deg, #2193b0 0%, #6dd5ed 100%); }
        .stat-value { font-size: 2.5em; font-weight: bold; margin-bottom: 5px; }
        .stat-label { font-size: 0.9em; opacity: 0.95; }
        
        /* Filters */
        .filters { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-size: 0.85em; font-weight: 600; color: #555; margin-bottom: 5px; }
        .filter-group input, .filter-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 0.9em; }
        .filter-btn { background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; }
        .filter-btn:hover { background: #2980b9; }
        .reset-btn { background: #95a5a6; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-left: 10px; }
        .reset-btn:hover { background: #7f8c8d; }
        
        /* Tables */
        .section { margin-bottom: 40px; }
        .section-title { font-size: 1.3em; color: #34495e; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 3px solid #3498db; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; }
        tr:hover { background: #f8f9fa; }
        tr:nth-child(even) { background: #f9f9f9; }
        
        .badge { padding: 4px 10px; border-radius: 4px; font-size: 0.85em; font-weight: 600; }
        .badge-day { background: #fff3cd; color: #856404; }
        .badge-night { background: #d1ecf1; color: #0c5460; }
        .badge-yarn { background: #cce5ff; color: #004085; }
        .badge-sheet { background: #e2e3e5; color: #383d41; }
        
        /* Export Button */
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        .export-btn:hover { background: #229954; }
        
        .no-data { text-align: center; padding: 60px; color: #95a5a6; font-size: 1.1em; }
        
        .highlight-high { background: #ffe8e8 !important; }
        
        @media print {
            @page {
                size: A4 landscape;
                margin: 10mm;
            }
            .filters, .export-btn { display: none; }
            body { background: white; padding: 0; }
            .container { box-shadow: none; max-width: 100%; padding: 10px; }
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
    <h1><i class="fas fa-cut"></i> CNC Cutting Batch-wise Scrap Report</h1>
    <p class="subtitle">Track scrap generation by CNC cutting batch for process improvement</p>
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($totalEntries); ?></div>
            <div class="stat-label">Total Scrap Entries</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value"><?php echo number_format($grandTotalQty, 2); ?> kg</div>
            <div class="stat-label">Total Scrap Quantity</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-value"><?php echo number_format($uniqueBatches); ?></div>
            <div class="stat-label">Unique CNC Batches</div>
        </div>
        <div class="stat-card blue">
            <div class="stat-value"><?php echo $uniqueBatches > 0 ? number_format($grandTotalQty / $uniqueBatches, 2) : '0.00'; ?> kg</div>
            <div class="stat-label">Average Scrap per Batch</div>
        </div>
    </div>
    
    <!-- Filters -->
    <form method="GET" action="">
        <div class="filters">
            <div class="filter-row">
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> From Date</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" required>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> To Date</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" required>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-barcode"></i> CNC Cutting Batch</label>
                    <select name="batch">
                        <option value="">All Batches</option>
                        <?php foreach ($batches as $batch): ?>
                            <option value="<?php echo htmlspecialchars($batch['cutting_batch']); ?>" 
                                <?php echo $batchFilter == $batch['cutting_batch'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($batch['cutting_batch']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Apply</button>
                    <button type="button" onclick="window.location.href='batch_scrap_report.php'" class="reset-btn">Reset</button>
                </div>
            </div>
        </div>
    </form>
    
    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print</button>
    <button onclick="exportToCSV()" class="export-btn" style="background: #e67e22;"><i class="fas fa-file-csv"></i> Export CSV</button>
    
    <?php if (count($summaryData) > 0): ?>
    
    <!-- Summary by CNC Cutting Batch -->
    <div class="section">
        <h2 class="section-title"><i class="fas fa-chart-bar"></i> Summary by CNC Cutting Batch</h2>
        <table id="summaryTable">
            <thead>
                <tr>
                    <th>Cutting Batch No.</th>
                    <th>Scrap Entries</th>
                    <th>Total Scrap (kg)</th>
                    <th>First Scrap Date</th>
                    <th>Last Scrap Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaryData as $row): 
                    $isHighScrap = $row['total_scrap_qty'] > 30; // Highlight if > 30kg
                ?>
                    <tr <?php echo $isHighScrap ? 'class="highlight-high"' : ''; ?>>
                        <td><strong><?php echo htmlspecialchars($row['cutting_batch']); ?></strong></td>
                        <td><?php echo $row['entry_count']; ?></td>
                        <td><strong><?php echo number_format($row['total_scrap_qty'], 2); ?> kg</strong></td>
                        <td><?php echo date('M d, Y', strtotime($row['first_scrap_date'])); ?></td>
                        <td><?php echo date('M d, Y', strtotime($row['last_scrap_date'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr style="background: #f8f9fa; font-weight: bold;">
                    <td>TOTAL</td>
                    <td><?php echo array_sum(array_column($summaryData, 'entry_count')); ?></td>
                    <td><?php echo number_format($grandTotalQty, 2); ?> kg</td>
                    <td colspan="2">-</td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <!-- Detailed Entries -->
    <div class="section">
        <h2 class="section-title"><i class="fas fa-list-alt"></i> Detailed Scrap Entries</h2>
        <table id="detailTable">
            <thead>
                <tr>
                    <th>Cutting Batch No.</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Scrap Qty (kg)</th>
                    <th>Date & Time</th>
                    <th>Shift</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($detailData as $row): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['cutting_batch']); ?></strong></td>
                        <td><?php echo htmlspecialchars($row['scrap_product'] ?? 'N/A'); ?></td>
                        <td>
                            <span class="badge <?php 
                                if ($row['scrap_type'] === 'Yarn') echo 'badge-yarn';
                                else echo 'badge-sheet';
                            ?>">
                                <?php echo htmlspecialchars($row['scrap_type']); ?>
                            </span>
                        </td>
                        <td><strong><?php echo number_format($row['qty'], 2); ?> kg</strong></td>
                        <td><?php echo date('M d, Y H:i', strtotime($row['date_time'])); ?></td>
                        <td>
                            <span class="badge <?php echo $row['shift'] === 'Day' ? 'badge-day' : 'badge-night'; ?>">
                                <?php echo htmlspecialchars($row['shift']); ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?php else: ?>
    <div class="no-data">
        <i class="fas fa-inbox" style="font-size: 4em; margin-bottom: 20px; opacity: 0.3;"></i>
        <p>No scrap data found for the selected CNC cutting batches and date range.</p>
    </div>
    <?php endif; ?>
</div>

<script>
function exportToCSV() {
    let csv = [];
    csv.push(['CNC Cutting Batch-wise Scrap Report']);
    csv.push(['Period: <?php echo $dateFrom; ?> to <?php echo $dateTo; ?>']);
    csv.push(['Total Entries: <?php echo $totalEntries; ?>']);
    csv.push(['Total Scrap Quantity: <?php echo number_format($grandTotalQty, 2); ?> kg']);
    csv.push(['Unique Batches: <?php echo $uniqueBatches; ?>']);
    csv.push([]);
    
    // Summary Table
    csv.push(['SUMMARY BY BATCH']);
    const summaryTable = document.getElementById('summaryTable');
    if (summaryTable) {
        const headers = Array.from(summaryTable.querySelectorAll('thead th')).map(th => th.textContent);
        csv.push(headers.join(','));
        
        const rows = summaryTable.querySelectorAll('tbody tr');
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
    }
    
    csv.push([]);
    csv.push(['DETAILED ENTRIES']);
    const detailTable = document.getElementById('detailTable');
    if (detailTable) {
        const headers = Array.from(detailTable.querySelectorAll('thead th')).map(th => th.textContent);
        csv.push(headers.join(','));
        
        const rows = detailTable.querySelectorAll('tbody tr');
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
    }
    
    // Download
    const csvContent = csv.map(row => Array.isArray(row) ? row : [row]).join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'batch_scrap_report_' + new Date().toISOString().slice(0,10) + '.csv';
    link.click();
}
</script>

</body>
</html>


