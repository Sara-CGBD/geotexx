<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'management', 'agm ops', 'qc_user', 'production_user'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Get filters
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$referenceNumber = $_GET['reference_number'] ?? '';
$cncBatch = $_GET['cnc_batch'] ?? '';
$projectId = $_GET['project_id'] ?? '';

// Performance: Set default date range (last 30 days) if no filters provided
$hasDateFilter = !empty($dateFrom) || !empty($dateTo);
if (!$hasDateFilter) {
    $dateTo = date('Y-m-d');
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
}

// Build query
// Detect fg_entry schema
$fgCols = [];
$fgColRes = $conn->query("SHOW COLUMNS FROM fg_entry");
if ($fgColRes) {
    while ($r = $fgColRes->fetch_assoc()) {
        $fgCols[] = strtolower($r['Field']);
    }
}
$hasProductType = in_array('product_type', $fgCols, true);
$hasIsDeleted = in_array('is_deleted', $fgCols, true);
$hasRollEntryType = in_array('roll_entry_type', $fgCols, true);

$query = "SELECT 
    fe.fg_id as batch_no,
    fe.reference_number,
    fe.cnc_cutting_batch,
    p.project_name,
    " . ($hasProductType ? "COALESCE(fe.product_type, 'bag')" : "'bag'") . " as product_type,
    fe.quality_checked as total_bags_produced,
    fe.passed_qty,
    fe.rejected_qty,
    ROUND((fe.passed_qty / NULLIF(fe.quality_checked, 0)) * 100, 2) as pass_percentage,
    fe.actual_weight,
    DATE(fe.date_time) as qc_date,
    fe.shift,
    fe.bag_size,
    fe.packaging_type,
    " . ($hasRollEntryType ? "fe.roll_entry_type" : "NULL as roll_entry_type") . "
FROM fg_entry fe
LEFT JOIN projects p ON fe.project_id = p.id
" . ($hasIsDeleted ? "WHERE fe.is_deleted = 0" : "WHERE 1=1");

$params = [];
$types = '';

if ($dateFrom) {
    $query .= " AND fe.date_time >= ?";
    $params[] = $dateFrom . ' 00:00:00';
    $types .= 's';
}
if ($dateTo) {
    $query .= " AND fe.date_time <= ?";
    $params[] = $dateTo . ' 23:59:59';
    $types .= 's';
}
if ($referenceNumber) {
    $query .= " AND fe.reference_number = ?";
    $params[] = $referenceNumber;
    $types .= 's';
}
if ($cncBatch) {
    $query .= " AND fe.cnc_cutting_batch = ?";
    $params[] = $cncBatch;
    $types .= 's';
}
if ($projectId) {
    $query .= " AND fe.project_id = ?";
    $params[] = $projectId;
    $types .= 'i';
}

$query .= " ORDER BY fe.date_time DESC, fe.fg_id DESC LIMIT 1000";

$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$batches = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate statistics (separate for bags and rolls)
$totalBatches = count($batches);
$bagBatches = array_filter($batches, function($b) { return ($b['product_type'] ?? 'bag') !== 'roll'; });
$rollBatches = array_filter($batches, function($b) { return ($b['product_type'] ?? 'bag') === 'roll'; });

$totalProduced = 0;
$totalPassed = 0;
$totalRejected = 0;
$totalRollWeight = 0;

foreach ($batches as $batch) {
    if (($batch['product_type'] ?? 'bag') === 'roll') {
        // For rolls, use actual_weight
        $totalRollWeight += floatval($batch['actual_weight'] ?? 0);
    } else {
        // For bags, use bag-specific fields
        $totalProduced += floatval($batch['total_bags_produced'] ?? 0);
        $totalPassed += floatval($batch['passed_qty'] ?? 0);
        $totalRejected += floatval($batch['rejected_qty'] ?? 0);
    }
}

$avgPassRate = $totalProduced > 0 ? round(($totalPassed / $totalProduced) * 100, 2) : 0;

// Performance: Defer filter options - load asynchronously after page render
$referenceNumbers = [];
$cncBatches = [];
$projects = [];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FG Batch Report - QC Summary</title>
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
        .stat-card.blue { background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); }
        .stat-card.red { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); }
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
        
        .table-wrapper { overflow-x: auto; margin-bottom: 20px; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 12px; }
        #bagTable { min-width: 1300px; }
        #rollTable { min-width: 1000px; }
        th, td { padding: 10px 8px; text-align: left; border-bottom: 1px solid #ecf0f1; white-space: nowrap; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; font-size: 11px; }
        tr:hover { background: #f8f9fa; }
        
        .badge { padding: 4px 10px; border-radius: 4px; font-size: 0.85em; font-weight: 600; }
        .badge-high { background: #d5f4e6; color: #27ae60; }
        .badge-medium { background: #fff3cd; color: #f39c12; }
        .badge-low { background: #f8d7da; color: #e74c3c; }
        
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        
        @media print {
            .filters, .export-btn { display: none; }
            body { background: white; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-boxes"></i> FG Batch Report</h1>
        <p class="subtitle">Batch Performance & Quality Summary</p>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($totalBatches); ?></div>
                <div class="stat-label">Total Batches</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-value"><?php echo number_format($totalProduced); ?></div>
                <div class="stat-label">Total Bags Produced (pcs)</div>
            </div>
            <div class="stat-card green">
                <div class="stat-value"><?php echo number_format($totalPassed); ?></div>
                <div class="stat-label">Total Bags Passed (pcs)</div>
            </div>
            <div class="stat-card" style="background: linear-gradient(135deg, #8e44ad 0%, #9b59b6 100%);">
                <div class="stat-value"><?php echo number_format($totalRollWeight, 2); ?></div>
                <div class="stat-label">Total Roll Weight (kg)</div>
            </div>
            <div class="stat-card red">
                <div class="stat-value"><?php echo $avgPassRate; ?>%</div>
                <div class="stat-label">Average Pass Rate</div>
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
                        <label>Reference Number:</label>
                        <select name="reference_number">
                            <option value="">All References</option>
                            <?php foreach($referenceNumbers as $ref): ?>
                                <option value="<?php echo htmlspecialchars($ref['reference_number']); ?>" 
                                        <?php echo ($referenceNumber == $ref['reference_number']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ref['reference_number']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>CNC Cutting Batch:</label>
                        <select name="cnc_batch">
                            <option value="">All CNC Batches</option>
                            <?php foreach($cncBatches as $batch): ?>
                                <option value="<?php echo htmlspecialchars($batch['cnc_cutting_batch']); ?>" 
                                        <?php echo ($cncBatch == $batch['cnc_cutting_batch']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($batch['cnc_cutting_batch']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Project:</label>
                        <select name="project_id">
                            <option value="">All Projects</option>
                            <?php foreach($projects as $proj): ?>
                                <option value="<?php echo $proj['id']; ?>" 
                                        <?php echo ($projectId == $proj['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($proj['project_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <button type="submit" class="filter-btn"><i class="fas fa-search"></i> Apply</button>
                    </div>
                    <div class="filter-group">
                        <a href="fg_batch_report.php" class="filter-btn reset-btn" style="text-decoration: none; display: inline-block; text-align: center;"><i class="fas fa-redo"></i> Reset</a>
                    </div>
                </div>
            </form>
        </div>
        
        <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print Report</button>

        <?php 
        // Separate batches into bags and rolls
        $bagBatches = array_filter($batches, function($b) { return ($b['product_type'] ?? 'bag') !== 'roll'; });
        $rollBatches = array_filter($batches, function($b) { return ($b['product_type'] ?? 'bag') === 'roll'; });
        ?>

        <!-- Bags Section -->
        <?php if (!empty($bagBatches)): ?>
        <div class="table-wrapper" style="margin-bottom: 40px;">
            <h2 style="margin-bottom: 20px; color: #2c3e50; padding-bottom: 10px; border-bottom: 3px solid #e65100;">
                <i class="fas fa-shopping-bag"></i> Bag Batches (<?php echo count($bagBatches); ?> batches)
            </h2>
            <table id="bagTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Batch No.</th>
                        <th>Reference No.</th>
                        <th>CNC Cutting Batch</th>
                        <th>Project</th>
                        <th>Bag Size</th>
                        <th>Packaging</th>
                        <th>Total Produced</th>
                        <th>Passed Qty</th>
                        <th>Rejected Qty</th>
                        <th>Pass %</th>
                        <th>Date</th>
                        <th>Shift</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    foreach ($bagBatches as $batch): 
                        $passRate = $batch['pass_percentage'] ?? 0;
                        $badgeClass = $passRate >= 95 ? 'badge-high' : ($passRate >= 85 ? 'badge-medium' : 'badge-low');
                    ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td><strong><?php echo htmlspecialchars($batch['batch_no']); ?></strong></td>
                            <td><?php echo htmlspecialchars($batch['reference_number'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($batch['cnc_cutting_batch'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($batch['project_name'] ?? 'Unassigned'); ?></td>
                            <td><?php echo htmlspecialchars($batch['bag_size'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($batch['packaging_type'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format($batch['total_bags_produced'] ?? 0); ?> pcs</td>
                            <td><strong style="color: #27ae60;"><?php echo number_format($batch['passed_qty'] ?? 0); ?></strong></td>
                            <td><strong style="color: #e74c3c;"><?php echo number_format($batch['rejected_qty'] ?? 0); ?></strong></td>
                            <td>
                                <span class="badge <?php echo $badgeClass; ?>">
                                    <?php echo number_format($passRate, 2); ?>%
                                </span>
                            </td>
                            <td><?php echo $batch['qc_date'] ? date('M d, Y', strtotime($batch['qc_date'])) : 'N/A'; ?></td>
                            <td>
                                <span class="badge" style="background: <?php echo ($batch['shift'] == 'Day') ? '#d5f4e6' : '#e3f2fd'; ?>; color: <?php echo ($batch['shift'] == 'Day') ? '#27ae60' : '#2980b9'; ?>;">
                                    <?php echo htmlspecialchars($batch['shift'] ?? 'N/A'); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Rolls Section -->
        <?php if (!empty($rollBatches)): ?>
        <div class="table-wrapper">
            <h2 style="margin-bottom: 20px; color: #2c3e50; padding-bottom: 10px; border-bottom: 3px solid #1976d2;">
                <i class="fas fa-dolly-flatbed"></i> Roll Batches (<?php echo count($rollBatches); ?> batches)
            </h2>
            <table id="rollTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Batch No.</th>
                        <th>Reference No.</th>
                        <th>CNC Cutting Batch</th>
                        <th>Project</th>
                        <th>Roll Size</th>
                        <th>Packaging</th>
                        <th>Weight (kg)</th>
                        <th>Date</th>
                        <th>Shift</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    foreach ($rollBatches as $batch): 
                        $rollWeight = $batch['actual_weight'] ?? 0;
                    ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td><strong><?php echo htmlspecialchars($batch['batch_no']); ?></strong></td>
                            <td><?php echo htmlspecialchars($batch['reference_number'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($batch['cnc_cutting_batch'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($batch['project_name'] ?? 'Unassigned'); ?></td>
                            <td><?php echo htmlspecialchars($batch['bag_size'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($batch['packaging_type'] ?? 'N/A'); ?></td>
                            <td><strong style="color: #8e44ad;"><?php echo number_format($rollWeight, 2); ?> kg</strong></td>
                            <td><?php echo $batch['qc_date'] ? date('M d, Y', strtotime($batch['qc_date'])) : 'N/A'; ?></td>
                            <td>
                                <span class="badge" style="background: <?php echo ($batch['shift'] == 'Day') ? '#d5f4e6' : '#e3f2fd'; ?>; color: <?php echo ($batch['shift'] == 'Day') ? '#27ae60' : '#2980b9'; ?>;">
                                    <?php echo htmlspecialchars($batch['shift'] ?? 'N/A'); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (empty($batches)): ?>
        <div style="text-align: center; padding: 60px; color: #7f8c8d;">
            <i class="fas fa-inbox" style="font-size: 64px; margin-bottom: 20px; opacity: 0.5;"></i>
            <div style="font-size: 1.2em;">No batches found for the selected period</div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>

