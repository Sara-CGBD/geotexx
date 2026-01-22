<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'production_user', 'management', 'agm ops', 'recycle_user'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$scrapType = $_GET['scrap_type'] ?? '';
$category = $_GET['category'] ?? '';

// Get scrap data with recycled amounts
$combinedQuery = "SELECT 
    s.scrap_type,
    s.scrap_category,
    SUM(s.qty) as total_scrap,
    SUM(COALESCE(recycle_summary.total_recycled, 0)) as total_recycled
FROM scrap s
LEFT JOIN (
    SELECT scrap_id, SUM(recycled_qty) as total_recycled
    FROM scrap_recycle
    WHERE DATE(recycled_at) BETWEEN ? AND ?
    GROUP BY scrap_id
) recycle_summary ON s.id = recycle_summary.scrap_id
WHERE s.is_deleted = 0
AND DATE(s.date_time) BETWEEN ? AND ?";

$combinedParams = [$dateFrom, $dateTo, $dateFrom, $dateTo];
$combinedTypes = 'ssss';

if ($scrapType) {
    $combinedQuery .= " AND s.scrap_type = ?";
    $combinedParams[] = $scrapType;
    $combinedTypes .= 's';
}

if ($category) {
    $combinedQuery .= " AND s.scrap_category = ?";
    $combinedParams[] = $category;
    $combinedTypes .= 's';
}

$combinedQuery .= " GROUP BY s.scrap_type, s.scrap_category";

$stmt = $conn->prepare($combinedQuery);
$stmt->bind_param($combinedTypes, ...$combinedParams);
$stmt->execute();
$result = $stmt->get_result();
$scrapData = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate totals and prepare combined data
$totalScrapQty = array_sum(array_column($scrapData, 'total_scrap'));
$totalRecycledQty = array_sum(array_column($scrapData, 'total_recycled'));
$recycleRatio = $totalScrapQty > 0 ? round(($totalRecycledQty / $totalScrapQty) * 100, 2) : 0;
$unrecycledQty = $totalScrapQty - $totalRecycledQty;

// Process combined data
$combinedData = [];
foreach ($scrapData as $scrap) {
    $key = ($scrap['scrap_type'] ?? 'Unknown') . '|' . ($scrap['scrap_category'] ?? 'Unknown');
    $combinedData[$key] = [
        'type' => $scrap['scrap_type'] ?? 'Unknown',
        'category' => $scrap['scrap_category'] ?? 'Unknown',
        'scrap_qty' => $scrap['total_scrap'],
        'recycled_qty' => $scrap['total_recycled'],
        'unrecycled' => $scrap['total_scrap'] - $scrap['total_recycled'],
        'ratio' => $scrap['total_scrap'] > 0 ? round(($scrap['total_recycled'] / $scrap['total_scrap']) * 100, 2) : 0
    ];
}

// Get filter options
$scrapTypesOptions = $conn->query("SELECT DISTINCT scrap_type FROM scrap WHERE scrap_type IS NOT NULL ORDER BY scrap_type")->fetch_all(MYSQLI_ASSOC);
$categoryOptions = $conn->query("SELECT DISTINCT scrap_category FROM scrap WHERE scrap_category IS NOT NULL ORDER BY scrap_category")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scrap vs Recycle Ratio</title>
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
        
        .chart-container { background: #f8f9fa; border-radius: 12px; padding: 25px; margin-bottom: 30px; }
        .chart-title { font-size: 1.2em; font-weight: 700; color: #2c3e50; margin-bottom: 20px; text-align: center; }
        .chart-canvas { position: relative; height: 400px; max-width: 900px; margin: 0 auto; }
        
        .table-wrapper { overflow-x: auto; margin-bottom: 20px; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; min-width: 900px; font-size: 12px; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; font-size: 11px; }
        tr:hover { background: #f8f9fa; }
        
        .badge { padding: 5px 12px; border-radius: 4px; font-size: 0.85em; font-weight: 600; display: inline-block; }
        .badge-high { background: #d5f4e6; color: #27ae60; }
        .badge-medium { background: #fff3cd; color: #f39c12; }
        .badge-low { background: #f8d7da; color: #e74c3c; }
        
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
    <h1><i class="fas fa-balance-scale"></i> Scrap vs Recycle Ratio</h1>
    <p class="subtitle">Monitor recycling efficiency and waste management performance</p>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card red">
            <div class="stat-value"><?php echo number_format($totalScrapQty, 2); ?></div>
            <div class="stat-label">Total Scrap (kg)</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value"><?php echo number_format($totalRecycledQty, 2); ?></div>
            <div class="stat-label">Total Recycled (kg)</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-value"><?php echo number_format($unrecycledQty, 2); ?></div>
            <div class="stat-label">Unrecycled (kg)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $recycleRatio; ?>%</div>
            <div class="stat-label">Recycle Ratio</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters">
        <form method="GET">
            <div class="filter-row">
                <div class="filter-group">
                    <label>Date From:</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="filter-group">
                    <label>Date To:</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="filter-group">
                    <label>Scrap Type:</label>
                    <select name="scrap_type">
                        <option value="">All Types</option>
                        <?php foreach($scrapTypesOptions as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['scrap_type']); ?>" 
                                    <?php echo ($scrapType == $type['scrap_type']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['scrap_type']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Category:</label>
                    <select name="category">
                        <option value="">All Categories</option>
                        <?php foreach($categoryOptions as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat['scrap_category']); ?>" 
                                    <?php echo ($category == $cat['scrap_category']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['scrap_category']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="filter-btn"><i class="fas fa-search"></i> Apply</button>
                </div>
                <div class="filter-group">
                    <a href="scrap_recycle_ratio.php" class="filter-btn reset-btn" style="text-decoration: none; display: inline-block; text-align: center;"><i class="fas fa-redo"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>

    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print Report</button>

    <!-- Chart -->
    <div class="chart-container">
        <div class="chart-title">Scrap vs Recycled Comparison</div>
        <div class="chart-canvas">
            <canvas id="ratioChart"></canvas>
        </div>
    </div>

    <!-- Detailed Table -->
    <div class="table-wrapper">
        <h2 style="margin-bottom: 20px; color: #2c3e50;">
            <i class="fas fa-table"></i> Breakdown by Type & Category
        </h2>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Scrap Type</th>
                    <th>Category</th>
                    <th>Total Scrap (kg)</th>
                    <th>Recycled (kg)</th>
                    <th>Unrecycled (kg)</th>
                    <th>Recycle Ratio</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($combinedData as $data): 
                    $ratio = $data['ratio'];
                    $badgeClass = $ratio >= 80 ? 'badge-high' : ($ratio >= 50 ? 'badge-medium' : 'badge-low');
                ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><strong><?php echo htmlspecialchars($data['type']); ?></strong></td>
                        <td><?php echo htmlspecialchars($data['category']); ?></td>
                        <td><?php echo number_format($data['scrap_qty'], 2); ?> kg</td>
                        <td style="color: #27ae60;"><strong><?php echo number_format($data['recycled_qty'], 2); ?> kg</strong></td>
                        <td style="color: #e74c3c;"><strong><?php echo number_format($data['unrecycled'], 2); ?> kg</strong></td>
                        <td>
                            <span class="badge <?php echo $badgeClass; ?>">
                                <?php echo $ratio; ?>%
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                    <?php if (empty($combinedData)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: #7f8c8d;">
                            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 10px; opacity: 0.5;"></i>
                            <div>No data found for selected filters</div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Ratio Chart
const ctx = document.getElementById('ratioChart');
if (ctx) {
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_map(function($d) { return $d['type'] . ' (' . $d['category'] . ')'; }, array_values($combinedData))); ?>,
            datasets: [
                {
                    label: 'Total Scrap (kg)',
                    data: <?php echo json_encode(array_column($combinedData, 'scrap_qty')); ?>,
                    backgroundColor: 'rgba(231, 76, 60, 0.7)',
                    borderColor: 'rgba(231, 76, 60, 1)',
                    borderWidth: 1
                },
                {
                    label: 'Recycled (kg)',
                    data: <?php echo json_encode(array_column($combinedData, 'recycled_qty')); ?>,
                    backgroundColor: 'rgba(17, 153, 142, 0.7)',
                    borderColor: 'rgba(17, 153, 142, 1)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Quantity (kg)'
                    }
                }
            }
        }
    });
}
</script>
</body>
</html>

