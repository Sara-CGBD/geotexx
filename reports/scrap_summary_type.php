<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'production_user', 'recycle', 'management', 'agm ops'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$scrapTypeFilter = $_GET['scrap_type'] ?? '';

// Build query with filters
$whereConditions = ["s.is_deleted = 0"];
$params = [];
$types = '';

if (!empty($dateFrom) && !empty($dateTo)) {
    $whereConditions[] = "DATE(s.date_time) BETWEEN ? AND ?";
    $params[] = $dateFrom;
    $params[] = $dateTo;
    $types .= 'ss';
}

if (!empty($scrapTypeFilter)) {
    $whereConditions[] = "s.scrap_type = ?";
    $params[] = $scrapTypeFilter;
    $types .= 's';
}

$whereClause = implode(' AND ', $whereConditions);

// Get scrap summary by type
$summaryQuery = "SELECT 
    s.scrap_type,
    s.scrap_category,
    COUNT(*) as entry_count,
    SUM(s.qty) as total_qty,
    AVG(s.qty) as avg_qty,
    MIN(s.qty) as min_qty,
    MAX(s.qty) as max_qty
FROM scrap s
WHERE $whereClause
GROUP BY s.scrap_type, s.scrap_category
ORDER BY total_qty DESC";

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
    s.scrap_type,
    s.scrap_category,
    s.scrap_product,
    s.reference_number,
    s.cutting_batch,
    s.qty,
    s.date_time,
    s.shift,
    s.remarks
FROM scrap s
WHERE $whereClause
ORDER BY s.date_time DESC";

$stmt = $conn->prepare($detailQuery);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$detailData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get unique scrap types for filter
$typesQuery = "SELECT DISTINCT scrap_type FROM scrap WHERE is_deleted = 0 AND scrap_type IS NOT NULL ORDER BY scrap_type";
$typesResult = $conn->query($typesQuery);
$scrapTypes = $typesResult->fetch_all(MYSQLI_ASSOC);

// Calculate totals
$grandTotalQty = array_sum(array_column($summaryData, 'total_qty'));
$totalEntries = array_sum(array_column($summaryData, 'entry_count'));
$avgQty = $totalEntries > 0 ? $grandTotalQty / $totalEntries : 0;
$typeCount = count($summaryData);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Scrap Summary by Type</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1800px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; padding: 25px; color: white; text-align: center; }
        .stat-card.orange { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-card.green { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
        .stat-card.red { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .stat-card.purple { background: linear-gradient(135deg,rgb(149, 168, 168) 0%,rgb(182, 157, 212) 100%); }
        .stat-value { font-size: 2.5em; font-weight: bold; margin-bottom: 5px; }
        .stat-label { font-size: 0.9em; opacity: 0.95; }
        
        .filters { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-size: 0.85em; font-weight: 600; color: #555; margin-bottom: 5px; }
        .filter-group input, .filter-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 0.9em; }
        .filter-btn { background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-top: 5px; }
        .filter-btn:hover { background: #2980b9; }
        .reset-btn { background: #95a5a6; }
        .reset-btn:hover { background: #7f8c8d; }
        
        
        .table-wrapper { overflow-x: auto; margin-bottom: 20px; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9em; min-width: 1000px; }
        th, td { padding: 10px 8px; text-align: left; border-bottom: 1px solid #ecf0f1; white-space: nowrap; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; z-index: 10; }
        tr:hover { background: #f8f9fa; }
        
        /* Responsive column widths */
        #detailTable th:nth-child(1), #detailTable td:nth-child(1) { min-width: 80px; } /* Scrap ID */
        #detailTable th:nth-child(2), #detailTable td:nth-child(2) { min-width: 140px; } /* Date & Time */
        #detailTable th:nth-child(3), #detailTable td:nth-child(3) { min-width: 140px; } /* Category */
        #detailTable th:nth-child(4), #detailTable td:nth-child(4) { min-width: 100px; } /* Type */
        #detailTable th:nth-child(5), #detailTable td:nth-child(5) { min-width: 100px; } /* Product */
        #detailTable th:nth-child(6), #detailTable td:nth-child(6) { min-width: 120px; } /* Reference/Batch */
        #detailTable th:nth-child(7), #detailTable td:nth-child(7) { min-width: 100px; } /* Quantity */
        #detailTable th:nth-child(8), #detailTable td:nth-child(8) { min-width: 80px; } /* Shift */
        #detailTable th:nth-child(9), #detailTable td:nth-child(9) { min-width: 150px; white-space: normal; word-wrap: break-word; max-width: 200px; } /* Remarks */
        
        .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 0.85em; font-weight: 600; }
        .badge-danger { background: #fee; color: #c0392b; }
        .badge-success { background: #e8f8f5; color: #27ae60; }
        .badge-warning { background: #fef5e7; color: #f39c12; }
        
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        
        @media (max-width: 1200px) {
            .container { padding: 15px; }
            table { font-size: 0.85em; }
            th, td { padding: 8px 6px; }
        }
        
        @media (max-width: 768px) {
            .container { padding: 10px; }
            table { font-size: 0.8em; min-width: 900px; }
            th, td { padding: 6px 4px; }
            #detailTable th:nth-child(9), #detailTable td:nth-child(9) { max-width: 150px; font-size: 0.75em; }
        }
        
        @media print {
            @page {
                size: A4 landscape;
                margin: 10mm;
            }
            .filters, .export-btn { display: none; }
            body { background: white; padding: 0; }
            .container { max-width: 100%; padding: 10px; }
            h1, h2 { font-size: 1.2em; }
            .table-wrapper { overflow: visible; width: 100%; }
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
    <h1><i class="fas fa-trash-alt"></i> Scrap Summary by Type</h1>
    <p class="subtitle">Comprehensive analysis of scrap generation by type and category</p>
    
    <div class="stats-grid">
        <div class="stat-card orange">
            <div class="stat-value"><?php echo $totalEntries; ?></div>
            <div class="stat-label">Total Scrap Entries</div>
        </div>
        <div class="stat-card red">
            <div class="stat-value"><?php echo number_format($grandTotalQty, 2); ?> kg</div>
            <div class="stat-label">Total Scrap Quantity</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value"><?php echo number_format($avgQty, 2); ?> kg</div>
            <div class="stat-label">Average Quantity per Entry</div>
        </div>
        <div class="stat-card purple">
            <div class="stat-value"><?php echo $typeCount; ?></div>
            <div class="stat-label">Scrap Types</div>
        </div>
    </div>
    
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
                    <label><i class="fas fa-filter"></i> Scrap Type</label>
                    <select name="scrap_type">
                        <option value="">All Types</option>
                        <?php foreach ($scrapTypes as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['scrap_type']); ?>" 
                                <?php echo $scrapTypeFilter == $type['scrap_type'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['scrap_type']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Apply Filter</button>
                    <button type="button" onclick="window.location.href='scrap_summary_type.php'" class="filter-btn reset-btn">Reset</button>
                </div>
            </div>
        </div>
    </form>
    
    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print</button>
    <button onclick="exportToCSV()" class="export-btn" style="background: #e67e22;"><i class="fas fa-file-csv"></i> Export CSV</button>
    
    <?php if (count($summaryData) > 0): ?>
    
    <!-- Summary Table -->
    <h2 style="font-size: 1.3em; color: #34495e; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 3px solid #e74c3c;">
        <i class="fas fa-list"></i> Summary by Scrap Type & Category
    </h2>
    <div class="table-wrapper">
    <table id="summaryTable">
        <thead>
            <tr>
                <th>Scrap Type</th>
                <th>Category</th>
                <th>Entries</th>
                <th>Total Qty (kg)</th>
                <th>Avg Qty (kg)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($summaryData as $row): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['scrap_type']); ?></strong></td>
                    <td>
                        <span class="badge <?php echo $row['scrap_category'] === 'Sheet Production Scrap' ? 'badge-success' : 'badge-warning'; ?>">
                            <?php echo htmlspecialchars($row['scrap_category']); ?>
                        </span>
                    </td>
                    <td><?php echo $row['entry_count']; ?></td>
                    <td><?php echo number_format($row['total_qty'], 2); ?></td>
                    <td><?php echo number_format($row['avg_qty'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr style="background: #f8f9fa; font-weight: bold;">
                <td colspan="2">TOTAL</td>
                <td><?php echo $totalEntries; ?></td>
                <td><?php echo number_format($grandTotalQty, 2); ?></td>
                <td><?php echo number_format($avgQty, 2); ?></td>
            </tr>
        </tbody>
    </table>
    </div>
    
    <!-- Detailed Entries -->
    <h2 style="font-size: 1.3em; color: #34495e; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 3px solid #e74c3c; margin-top: 40px;">
        <i class="fas fa-list-alt"></i> Detailed Scrap Entries
    </h2>
    <div class="table-wrapper">
    <table id="detailTable">
        <thead>
            <tr>
                <th>Scrap ID</th>
                <th>Date & Time</th>
                <th>Category</th>
                <th>Type</th>
                <th>Product</th>
                <th>Reference/Batch</th>
                <th>Quantity (kg)</th>
                <th>Shift</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($detailData as $row): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['scrap_id']); ?></strong></td>
                    <td><?php echo date('M d, Y H:i', strtotime($row['date_time'])); ?></td>
                    <td>
                        <span class="badge <?php echo $row['scrap_category'] === 'Sheet Production Scrap' ? 'badge-success' : 'badge-warning'; ?>">
                            <?php echo htmlspecialchars($row['scrap_category'] ?? 'N/A'); ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge badge-danger">
                            <?php echo htmlspecialchars($row['scrap_type']); ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($row['scrap_product'] ?? 'N/A'); ?></td>
                    <td><?php 
                        if ($row['scrap_category'] === 'Sheet Production Scrap') {
                            echo htmlspecialchars($row['reference_number'] ?? 'N/A');
                        } else {
                            echo htmlspecialchars($row['cutting_batch'] ?? 'N/A');
                        }
                    ?></td>
                    <td><?php echo number_format($row['qty'], 2); ?></td>
                    <td><?php echo htmlspecialchars($row['shift'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($row['remarks'] ?? '-'); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    
    <?php else: ?>
    <div style="text-align: center; padding: 60px; color: #95a5a6;">
        <i class="fas fa-inbox" style="font-size: 4em; margin-bottom: 20px;"></i>
        <p style="font-size: 1.2em;">No scrap data found for the selected period.</p>
    </div>
    <?php endif; ?>
  </div>

<script>
function exportToCSV() {
    let csv = [];
    csv.push(['Scrap Summary by Type Report']);
    csv.push(['Period: <?php echo $dateFrom; ?> to <?php echo $dateTo; ?>']);
    csv.push(['Total Entries: <?php echo $totalEntries; ?>']);
    csv.push(['Total Quantity: <?php echo number_format($grandTotalQty, 2); ?>']);
    csv.push(['Total Recycled: <?php echo number_format($grandTotalRecycled, 2); ?>']);
    csv.push(['Recycle Rate: <?php echo number_format($recycleRate, 1); ?>%']);
    csv.push([]);
    
    // Summary Table
    csv.push(['SUMMARY BY TYPE']);
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
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', 'scrap_summary_by_type_' + new Date().toISOString().slice(0,10) + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>

