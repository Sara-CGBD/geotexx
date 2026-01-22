<?php
session_start();
require_once '../config/security_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Role-based access control
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'production_user', 'management', 'agm ops', 'agm operations'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access Fiber to Roll Conversion Reports.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$projectId = $_GET['project_id'] ?? '';
$lineNo = $_GET['line_no'] ?? '';
$materialType = $_GET['material_type'] ?? '';

// Build query
$query = "SELECT 
    ftr.*,
    p.project_name,
    COALESCE(
        (SELECT full_name FROM new_user WHERE id = ftr.operator_id LIMIT 1),
        (SELECT username FROM new_user WHERE id = ftr.operator_id LIMIT 1),
        (SELECT username FROM users WHERE id = ftr.operator_id LIMIT 1),
        'Admin'
    ) as operator_name
FROM fiber_to_roll_entry ftr
LEFT JOIN projects p ON ftr.project_id = p.id
WHERE 1=1";

$params = [];
$types = '';

if ($dateFrom) {
    $query .= " AND DATE(ftr.date_time) >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo) {
    $query .= " AND DATE(ftr.date_time) <= ?";
    $params[] = $dateTo;
    $types .= 's';
}
if ($projectId) {
    $query .= " AND ftr.project_id = ?";
    $params[] = $projectId;
    $types .= 'i';
}
if ($lineNo) {
    $query .= " AND ftr.line_no = ?";
    $params[] = $lineNo;
    $types .= 's';
}
if ($materialType) {
    $query .= " AND ftr.material_type LIKE ?";
    $params[] = "%$materialType%";
    $types .= 's';
}

$query .= " ORDER BY ftr.date_time DESC";

// Execute query
$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$conversions = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate statistics
$totalRecords = count($conversions);
$totalFiberWeight = array_sum(array_column($conversions, 'bale_weight'));
$totalRollWeight = array_sum(array_filter(array_column($conversions, 'total_weight')));
// Conversion efficiency: What percentage of fiber input became roll output
// If roll weight > fiber weight, cap at 100% (no loss scenario)
$conversionEfficiency = $totalFiberWeight > 0 ? min(($totalRollWeight / $totalFiberWeight) * 100, 100) : 0;

// Group by project
$projectStats = [];
foreach ($conversions as $conv) {
    $project = $conv['project_name'] ?? 'Unknown';
    if (!isset($projectStats[$project])) {
        $projectStats[$project] = ['count' => 0, 'fiber_weight' => 0, 'roll_weight' => 0];
    }
    $projectStats[$project]['count']++;
    $projectStats[$project]['fiber_weight'] += $conv['bale_weight'] ?? 0;
    $projectStats[$project]['roll_weight'] += $conv['total_weight'] ?? 0;
}

// Group by line
$lineStats = [];
foreach ($conversions as $conv) {
    $line = $conv['line_no'] ?? 'Unknown';
    if (!isset($lineStats[$line])) {
        $lineStats[$line] = ['count' => 0, 'fiber_weight' => 0, 'roll_weight' => 0];
    }
    $lineStats[$line]['count']++;
    $lineStats[$line]['fiber_weight'] += $conv['bale_weight'] ?? 0;
    $lineStats[$line]['roll_weight'] += $conv['total_weight'] ?? 0;
}

// Group by fiber type
$fiberTypeStats = [];
foreach ($conversions as $conv) {
    $type = $conv['material_type'] ?? 'Unknown';
    if (!isset($fiberTypeStats[$type])) {
        $fiberTypeStats[$type] = ['count' => 0, 'fiber_weight' => 0, 'roll_weight' => 0];
    }
    $fiberTypeStats[$type]['count']++;
    $fiberTypeStats[$type]['fiber_weight'] += $conv['bale_weight'] ?? 0;
    $fiberTypeStats[$type]['roll_weight'] += $conv['total_weight'] ?? 0;
}

// Group by date for daily trend
$dailyStats = [];
foreach ($conversions as $conv) {
    $date = date('Y-m-d', strtotime($conv['date_time']));
    if (!isset($dailyStats[$date])) {
        $dailyStats[$date] = ['count' => 0, 'fiber_weight' => 0, 'roll_weight' => 0];
    }
    $dailyStats[$date]['count']++;
    $dailyStats[$date]['fiber_weight'] += $conv['bale_weight'] ?? 0;
    $dailyStats[$date]['roll_weight'] += $conv['total_weight'] ?? 0;
}

// Get unique values for filters
$projectsResult = $conn->query("SELECT id, project_name FROM projects ORDER BY project_name");
$projects = $projectsResult ? $projectsResult->fetch_all(MYSQLI_ASSOC) : [];

$linesResult = $conn->query("SELECT DISTINCT line_no FROM fiber_to_roll_entry WHERE line_no IS NOT NULL ORDER BY line_no");
$lines = $linesResult ? $linesResult->fetch_all(MYSQLI_ASSOC) : [];

$materialTypesResult = $conn->query("SELECT DISTINCT material_type FROM fiber_to_roll_entry WHERE material_type IS NOT NULL ORDER BY material_type");
$materialTypes = $materialTypesResult ? $materialTypesResult->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiber to Roll Conversion Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1800px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }
        
        /* Statistics Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; padding: 25px; color: white; text-align: center; }
        .stat-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-card.orange { background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%); }
        .stat-card.blue { background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); }
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
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 0.9em; table-layout: fixed; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ecf0f1; word-wrap: break-word; overflow: hidden; text-overflow: ellipsis; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; font-size: 0.85em; }
        tr:hover { background: #f8f9fa; }
        tr:nth-child(even) { background: #f9f9f9; }
        
        /* Column widths for detailed list */
        #detailTable th:nth-child(1), #detailTable td:nth-child(1) { width: 3%; } /* # */
        #detailTable th:nth-child(2), #detailTable td:nth-child(2) { width: 8%; } /* Date */
        #detailTable th:nth-child(3), #detailTable td:nth-child(3) { width: 10%; } /* Project */
        #detailTable th:nth-child(4), #detailTable td:nth-child(4) { width: 6%; } /* Line */
        #detailTable th:nth-child(5), #detailTable td:nth-child(5) { width: 6%; } /* Bale Opener */
        #detailTable th:nth-child(6), #detailTable td:nth-child(6) { width: 6%; } /* Bale No */
        #detailTable th:nth-child(7), #detailTable td:nth-child(7) { width: 7%; } /* Fiber Weight */
        #detailTable th:nth-child(8), #detailTable td:nth-child(8) { width: 9%; } /* Material Type */
        #detailTable th:nth-child(9), #detailTable td:nth-child(9) { width: 8%; } /* Origin */
        #detailTable th:nth-child(10), #detailTable td:nth-child(10) { width: 5%; } /* GSM */
        #detailTable th:nth-child(11), #detailTable td:nth-child(11) { width: 5%; } /* Roll No */
        #detailTable th:nth-child(12), #detailTable td:nth-child(12) { width: 7%; } /* Roll Weight */
        #detailTable th:nth-child(13), #detailTable td:nth-child(13) { width: 7%; } /* Efficiency */
        #detailTable th:nth-child(14), #detailTable td:nth-child(14) { width: 8%; } /* Operator */
        
        .badge { padding: 4px 10px; border-radius: 4px; font-size: 0.85em; font-weight: 600; white-space: nowrap; }
        .badge-high { background: #27ae60; color: white; }
        .badge-medium { background: #f39c12; color: white; }
        .badge-low { background: #e74c3c; color: white; }
        
        /* Export Buttons */
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        .export-btn:hover { background: #229954; }
        .export-btn.excel { background: #16a085; }
        .export-btn.excel:hover { background: #138871; }
        
        .no-data { text-align: center; padding: 60px; color: #95a5a6; font-size: 1.1em; }
        
        /* Responsive table wrapper for tablet/mobile */
        .table-wrapper {
            width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            margin-bottom: 20px;
            border-radius: 8px;
        }
        .table-wrapper table {
            min-width: 1400px;
            font-size: 0.85em;
        }
        .table-wrapper th, .table-wrapper td {
            white-space: nowrap;
            padding: 10px 8px;
        }
        
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
    <h1><i class="fas fa-sync-alt"></i> Fiber to Roll Conversion Report</h1>
    <p class="subtitle">Track fiber usage and conversion efficiency per roll production</p>
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($totalRecords); ?></div>
            <div class="stat-label">Total Conversions</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value"><?php echo number_format($totalFiberWeight); ?> kg</div>
            <div class="stat-label">Total Fiber Used</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-value"><?php echo number_format($totalRollWeight, 2); ?> kg</div>
            <div class="stat-label">Total Roll Output</div>
        </div>
        <div class="stat-card blue">
            <div class="stat-value"><?php echo number_format($conversionEfficiency, 1); ?>%</div>
            <div class="stat-label">Conversion Efficiency</div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="filters">
        <form method="GET" action="">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="date_from"><i class="fas fa-calendar"></i> Date From</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="filter-group">
                    <label for="date_to"><i class="fas fa-calendar"></i> Date To</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="filter-group">
                    <label for="project_id"><i class="fas fa-project-diagram"></i> Project</label>
                    <select id="project_id" name="project_id">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?php echo $proj['id']; ?>" 
                                <?php echo $projectId == $proj['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($proj['project_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="line_no"><i class="fas fa-stream"></i> Line Number</label>
                    <select id="line_no" name="line_no">
                        <option value="">All Lines</option>
                        <?php foreach ($lines as $line): ?>
                            <option value="<?php echo htmlspecialchars($line['line_no']); ?>" 
                                <?php echo $lineNo == $line['line_no'] ? 'selected' : ''; ?>>
                                Line <?php echo htmlspecialchars($line['line_no']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="material_type"><i class="fas fa-box"></i> Material Type</label>
                    <select id="material_type" name="material_type">
                        <option value="">All Types</option>
                        <?php foreach ($materialTypes as $type): ?>
                            <option value="<?php echo htmlspecialchars($type['material_type']); ?>" 
                                <?php echo $materialType == $type['material_type'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type['material_type']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group" style="display: flex; gap: 10px; align-items: flex-end;">
                    <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Filter</button>
                    <a href="fiber_to_roll_conversion.php" class="reset-btn" style="text-decoration: none; display: inline-block; line-height: 1.5;"><i class="fas fa-redo"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
    
    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print Report</button>
    <button onclick="exportToExcel()" class="export-btn excel"><i class="fas fa-file-excel"></i> Export to Excel</button>
    <button onclick="exportToCSV()" class="export-btn" style="background: #e67e22;"><i class="fas fa-file-csv"></i> Export to CSV</button>
    
    <?php if ($totalRecords > 0): ?>
        
        <!-- Summary by Project -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-project-diagram"></i> Summary by Project</h2>
            <table>
                <thead>
                    <tr>
                        <th>Project Name</th>
                        <th>Conversions</th>
                        <th>Fiber Weight (kg)</th>
                        <th>Roll Weight (kg)</th>
                        <th>Efficiency</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    uasort($projectStats, function($a, $b) { return $b['fiber_weight'] <=> $a['fiber_weight']; });
                    foreach ($projectStats as $project => $stats): 
                        $eff = $stats['fiber_weight'] > 0 ? min(($stats['roll_weight'] / $stats['fiber_weight']) * 100, 100) : 0;
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($project); ?></strong></td>
                            <td><?php echo number_format($stats['count']); ?></td>
                            <td><?php echo number_format($stats['fiber_weight']); ?></td>
                            <td><?php echo number_format($stats['roll_weight'], 2); ?></td>
                            <td>
                                <span class="badge <?php 
                                    echo $eff >= 90 ? 'badge-high' : ($eff >= 75 ? 'badge-medium' : 'badge-low'); 
                                ?>">
                                    <?php echo number_format($eff, 1); ?>%
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Summary by Line -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-stream"></i> Summary by Production Line</h2>
            <table>
                <thead>
                    <tr>
                        <th>Line Number</th>
                        <th>Conversions</th>
                        <th>Fiber Weight (kg)</th>
                        <th>Roll Weight (kg)</th>
                        <th>Efficiency</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    ksort($lineStats);
                    foreach ($lineStats as $line => $stats): 
                        $eff = $stats['fiber_weight'] > 0 ? min(($stats['roll_weight'] / $stats['fiber_weight']) * 100, 100) : 0;
                    ?>
                        <tr>
                            <td><strong>Line <?php echo htmlspecialchars($line); ?></strong></td>
                            <td><?php echo number_format($stats['count']); ?></td>
                            <td><?php echo number_format($stats['fiber_weight']); ?></td>
                            <td><?php echo number_format($stats['roll_weight'], 2); ?></td>
                            <td>
                                <span class="badge <?php 
                                    echo $eff >= 90 ? 'badge-high' : ($eff >= 75 ? 'badge-medium' : 'badge-low'); 
                                ?>">
                                    <?php echo number_format($eff, 1); ?>%
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Summary by Fiber Type -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-box"></i> Summary by Fiber Type</h2>
            <table>
                <thead>
                    <tr>
                        <th>Fiber Type</th>
                        <th>Conversions</th>
                        <th>Fiber Weight (kg)</th>
                        <th>Roll Weight (kg)</th>
                        <th>Efficiency</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach ($fiberTypeStats as $type => $stats): 
                        $eff = $stats['fiber_weight'] > 0 ? min(($stats['roll_weight'] / $stats['fiber_weight']) * 100, 100) : 0;
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($type); ?></strong></td>
                            <td><?php echo number_format($stats['count']); ?></td>
                            <td><?php echo number_format($stats['fiber_weight']); ?></td>
                            <td><?php echo number_format($stats['roll_weight'], 2); ?></td>
                            <td>
                                <span class="badge <?php 
                                    echo $eff >= 90 ? 'badge-high' : ($eff >= 75 ? 'badge-medium' : 'badge-low'); 
                                ?>">
                                    <?php echo number_format($eff, 1); ?>%
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Daily Trend -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-chart-line"></i> Daily Conversion Trend</h2>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Conversions</th>
                        <th>Fiber Weight (kg)</th>
                        <th>Roll Weight (kg)</th>
                        <th>Efficiency</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    krsort($dailyStats);
                    foreach ($dailyStats as $date => $stats): 
                        $eff = $stats['fiber_weight'] > 0 ? min(($stats['roll_weight'] / $stats['fiber_weight']) * 100, 100) : 0;
                    ?>
                        <tr>
                            <td><strong><?php echo date('M d, Y', strtotime($date)); ?></strong></td>
                            <td><?php echo number_format($stats['count']); ?></td>
                            <td><?php echo number_format($stats['fiber_weight']); ?></td>
                            <td><?php echo number_format($stats['roll_weight'], 2); ?></td>
                            <td>
                                <span class="badge <?php 
                                    echo $eff >= 90 ? 'badge-high' : ($eff >= 75 ? 'badge-medium' : 'badge-low'); 
                                ?>">
                                    <?php echo number_format($eff, 1); ?>%
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Detailed Conversion List -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-list"></i> Detailed Conversion List</h2>
            <div class="table-wrapper">
                <table id="conversionTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date & Time</th>
                        <th>Project</th>
                        <th>Line</th>
                        <th>Bale Opener</th>
                        <th>Bale No.</th>
                        <th>Fiber Weight (kg)</th>
                        <th>Fiber Type</th>
                        <th>Origin</th>
                        <th>GSM</th>
                        <th>Roll No.</th>
                        <th>Roll Weight (kg)</th>
                        <th>Efficiency</th>
                        <th>Operator</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    foreach ($conversions as $conv): 
                        $eff = $conv['bale_weight'] > 0 ? min(($conv['total_weight'] / $conv['bale_weight']) * 100, 100) : 0;
                    ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td><?php echo date('M d, Y g:i A', strtotime($conv['date_time'])); ?></td>
                            <td><?php echo htmlspecialchars($conv['project_name'] ?? 'N/A'); ?></td>
                            <td><strong><?php echo htmlspecialchars($conv['line_no']); ?></strong></td>
                            <td><?php echo htmlspecialchars($conv['bale_opener_number']); ?></td>
                            <td><?php echo htmlspecialchars($conv['bale_number']); ?></td>
                            <td><?php echo number_format($conv['bale_weight']); ?></td>
                            <td><?php echo htmlspecialchars($conv['material_type'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($conv['origin']); ?></td>
                            <td><?php echo htmlspecialchars($conv['gsm']); ?></td>
                            <td><?php echo htmlspecialchars($conv['roll_no'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format($conv['total_weight'], 2); ?></td>
                            <td>
                                <span class="badge <?php 
                                    echo $eff >= 90 ? 'badge-high' : ($eff >= 75 ? 'badge-medium' : 'badge-low'); 
                                ?>">
                                    <?php echo number_format($eff, 1); ?>%
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($conv['operator_name']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                </table>
            </div>
        </div>
        
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-inbox" style="font-size: 3em; margin-bottom: 15px; opacity: 0.3;"></i>
            <p>No fiber to roll conversion records found for the selected filters.</p>
        </div>
    <?php endif; ?>
</div>

<script>
function exportToCSV() {
    const table = document.getElementById('conversionTable');
    if (!table) return;
    
    let csv = [];
    
    // Headers
    const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent);
    csv.push(headers.join(','));
    
    // Rows
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
    
    // Download
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', 'fiber_to_roll_conversion_' + new Date().toISOString().slice(0,10) + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function exportToExcel() {
    const table = document.getElementById('conversionTable');
    if (!table) return;
    
    // Create workbook
    const wb = XLSX.utils.book_new();
    
    // Convert table to worksheet
    const ws = XLSX.utils.table_to_sheet(table);
    
    // Add worksheet to workbook
    XLSX.utils.book_append_sheet(wb, ws, 'Fiber to Roll Conversion');
    
    // Download
    XLSX.writeFile(wb, 'fiber_to_roll_conversion_' + new Date().toISOString().slice(0,10) + '.xlsx');
}
</script>
</body>
</html>



