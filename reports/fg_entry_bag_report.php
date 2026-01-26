<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'management', 'agm ops', 'qc_user', 'production_user', 'sewing_test'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$referenceNumber = $_GET['reference_number'] ?? '';
$cncBatch = $_GET['cnc_batch'] ?? '';
$bagSizeFilter = $_GET['bag_size'] ?? '';

$hasDateFilter = !empty($dateFrom) || !empty($dateTo);
if (!$hasDateFilter) {
    $dateTo = date('Y-m-d');
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
}

$fgCols = [];
$fgColRes = $conn->query("SHOW COLUMNS FROM fg_entry");
if ($fgColRes) {
    while ($r = $fgColRes->fetch_assoc()) {
        $fgCols[] = strtolower($r['Field']);
    }
}
$hasProductType = in_array('product_type', $fgCols, true);
$hasIsDeleted = in_array('is_deleted', $fgCols, true);
$hasShiftInCharge = in_array('shift_in_charge', $fgCols, true);

$where = $hasIsDeleted ? "WHERE (fe.is_deleted = 0 OR fe.is_deleted IS NULL)" : "WHERE 1=1";
$where .= $hasProductType ? " AND (fe.product_type = 'bag' OR fe.product_type IS NULL)" : "";

$params = [];
$types = '';

if ($dateFrom) {
    $where .= " AND fe.date_time >= ?";
    $params[] = $dateFrom . ' 00:00:00';
    $types .= 's';
}
if ($dateTo) {
    $where .= " AND fe.date_time <= ?";
    $params[] = $dateTo . ' 23:59:59';
    $types .= 's';
}
if ($referenceNumber !== '') {
    $where .= " AND fe.reference_number = ?";
    $params[] = $referenceNumber;
    $types .= 's';
}
if ($cncBatch !== '') {
    $where .= " AND fe.cnc_cutting_batch = ?";
    $params[] = $cncBatch;
    $types .= 's';
}
if ($bagSizeFilter !== '') {
    $where .= " AND TRIM(COALESCE(fe.bag_size,'')) = ?";
    $params[] = $bagSizeFilter;
    $types .= 's';
}

$select = "SELECT fe.fg_id, fe.date_time, fe.shift, fe.reference_number, fe.cnc_cutting_batch, fe.bag_size,
    fe.quality_checked, fe.passed_qty, fe.rejected_qty,
    ROUND((fe.passed_qty / NULLIF(fe.quality_checked, 0)) * 100, 2) AS pass_percentage,
    fe.actual_weight, fe.recommended_weight,
    " . ($hasShiftInCharge ? "fe.shift_in_charge" : "NULL AS shift_in_charge") . ",
    p.project_name
FROM fg_entry fe
LEFT JOIN projects p ON fe.project_id = p.id
$where
ORDER BY fe.date_time DESC, fe.fg_id DESC
LIMIT 1000";

$stmt = $conn->prepare($select);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$rows = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalEntries = count($rows);
$totalQty = 0;
$totalPassed = 0;
$totalRejected = 0;
foreach ($rows as $r) {
    $totalQty += (int)($r['quality_checked'] ?? 0);
    $totalPassed += (int)($r['passed_qty'] ?? 0);
    $totalRejected += (int)($r['rejected_qty'] ?? 0);
}
$avgPassRate = $totalQty > 0 ? round(($totalPassed / $totalQty) * 100, 2) : 0;

$baseWhere = $hasIsDeleted ? "AND (is_deleted = 0 OR is_deleted IS NULL)" : "";
$baseWhere .= $hasProductType ? " AND (product_type = 'bag' OR product_type IS NULL)" : "";

$refRes = $conn->query("SELECT DISTINCT reference_number FROM fg_entry WHERE reference_number IS NOT NULL AND reference_number != '' $baseWhere ORDER BY reference_number");
$referenceNumbers = $refRes ? $refRes->fetch_all(MYSQLI_ASSOC) : [];

$cncRes = $conn->query("SELECT DISTINCT cnc_cutting_batch FROM fg_entry WHERE cnc_cutting_batch IS NOT NULL AND cnc_cutting_batch != '' $baseWhere ORDER BY cnc_cutting_batch");
$cncBatches = $cncRes ? $cncRes->fetch_all(MYSQLI_ASSOC) : [];

$bagRes = $conn->query("SELECT DISTINCT TRIM(COALESCE(bag_size,'')) AS bag_size FROM fg_entry WHERE bag_size IS NOT NULL AND TRIM(bag_size) != '' $baseWhere ORDER BY bag_size");
$bagSizes = $bagRes ? $bagRes->fetch_all(MYSQLI_ASSOC) : [];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FG Entry (Bag) Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1800px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); border-radius: 10px; padding: 22px; color: white; text-align: center; }
        .stat-card.purple { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .stat-card.blue { background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); }
        .stat-card.orange { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-card.red { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); }
        .stat-value { font-size: 2em; font-weight: bold; margin-bottom: 5px; }
        .stat-label { font-size: 0.85em; opacity: 0.95; }

        .filters { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-size: 0.85em; font-weight: 600; color: #555; margin-bottom: 5px; }
        .filter-group input, .filter-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 0.9em; }
        .filter-btn { background: #3498db; color: white; border: none; padding: 10px 18px; border-radius: 5px; cursor: pointer; font-weight: 600; }
        .filter-btn:hover { background: #2980b9; }
        .reset-btn { background: #95a5a6; }
        .reset-btn:hover { background: #7f8c8d; }

        .table-wrapper { overflow-x: auto; margin-bottom: 20px; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td { padding: 10px 8px; text-align: left; border-bottom: 1px solid #ecf0f1; white-space: nowrap; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; font-size: 11px; }
        tr:hover { background: #f8f9fa; }

        .badge { padding: 4px 10px; border-radius: 4px; font-size: 0.85em; font-weight: 600; }
        .badge-high { background: #d5f4e6; color: #27ae60; }
        .badge-medium { background: #fff3cd; color: #f39c12; }
        .badge-low { background: #f8d7da; color: #e74c3c; }

        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        .export-btn:hover { background: #219a52; }

        @media print {
            @page { size: A4 landscape; margin: 10mm; }
            .filters, .export-btn { display: none; }
            body { background: white; padding: 0; }
            .container { max-width: 100%; padding: 10px; }
            .table-wrapper { overflow: visible; width: 100%; }
            table { font-size: 9px; page-break-inside: avoid; }
            th, td { padding: 4px 3px; font-size: 9px; }
            th { position: static; }
            table thead { display: table-header-group; }
            table tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-shopping-bag"></i> FG Entry (Bag) Report</h1>
        <p class="subtitle">Quality-checked bag entries from FG module</p>

        <div class="stats-grid">
            <div class="stat-card purple">
                <div class="stat-value"><?php echo number_format($totalEntries); ?></div>
                <div class="stat-label">Total Entries</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($totalQty); ?></div>
                <div class="stat-label">Quality Checked (pcs)</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-value"><?php echo number_format($totalPassed); ?></div>
                <div class="stat-label">Passed (pcs)</div>
            </div>
            <div class="stat-card red">
                <div class="stat-value"><?php echo number_format($totalRejected); ?></div>
                <div class="stat-label">Rejected (pcs)</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-value"><?php echo $avgPassRate; ?>%</div>
                <div class="stat-label">Avg Pass Rate</div>
            </div>
        </div>

        <div class="filters">
            <form method="GET">
                <div class="filter-row">
                    <div class="filter-group">
                        <label>Date From</label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                    </div>
                    <div class="filter-group">
                        <label>Date To</label>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                    </div>
                    <div class="filter-group">
                        <label>Reference</label>
                        <select name="reference_number">
                            <option value="">All</option>
                            <?php foreach ($referenceNumbers as $ref): ?>
                                <option value="<?php echo htmlspecialchars($ref['reference_number']); ?>" <?php echo ($referenceNumber === $ref['reference_number']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ref['reference_number']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>CNC Batch</label>
                        <select name="cnc_batch">
                            <option value="">All</option>
                            <?php foreach ($cncBatches as $b): ?>
                                <option value="<?php echo htmlspecialchars($b['cnc_cutting_batch']); ?>" <?php echo ($cncBatch === $b['cnc_cutting_batch']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($b['cnc_cutting_batch']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Bag Size</label>
                        <select name="bag_size">
                            <option value="">All</option>
                            <?php foreach ($bagSizes as $bs): $bsVal = $bs['bag_size'] ?? ''; ?>
                                <option value="<?php echo htmlspecialchars($bsVal); ?>" <?php echo ($bagSizeFilter === $bsVal) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($bsVal); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <button type="submit" class="filter-btn"><i class="fas fa-search"></i> Apply</button>
                    </div>
                    <div class="filter-group">
                        <a href="fg_entry_bag_report.php" class="filter-btn reset-btn" style="text-decoration: none; display: inline-block; text-align: center;"><i class="fas fa-redo"></i> Reset</a>
                    </div>
                </div>
            </form>
        </div>

        <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print Report</button>

        <?php if (!empty($rows)): ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>FG ID</th>
                        <th>Date &amp; Time</th>
                        <th>Shift</th>
                        <th>Reference</th>
                        <th>CNC Cutting Batch</th>
                        <th>Bag Size</th>
                        <th>Quality Checked</th>
                        <th>Passed</th>
                        <th>Rejected</th>
                        <th>Pass %</th>
                        <th>Actual Wt (kg)</th>
                        <th>Shift in Charge</th>
                        <th>Project</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $sn = 1; foreach ($rows as $r):
                        $passRate = (float)($r['pass_percentage'] ?? 0);
                        $badgeClass = $passRate >= 95 ? 'badge-high' : ($passRate >= 85 ? 'badge-medium' : 'badge-low');
                    ?>
                        <tr>
                            <td><?php echo $sn++; ?></td>
                            <td><strong><?php echo htmlspecialchars($r['fg_id'] ?? ''); ?></strong></td>
                            <td><?php echo $r['date_time'] ? date('d-M-Y H:i', strtotime($r['date_time'])) : 'N/A'; ?></td>
                            <td>
                                <span class="badge" style="background: <?php echo (isset($r['shift']) && $r['shift'] === 'Day') ? '#d5f4e6' : '#e3f2fd'; ?>; color: <?php echo (isset($r['shift']) && $r['shift'] === 'Day') ? '#27ae60' : '#2980b9'; ?>;">
                                    <?php echo htmlspecialchars($r['shift'] ?? 'N/A'); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($r['reference_number'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($r['cnc_cutting_batch'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($r['bag_size'] ?? '—'); ?></td>
                            <td><?php echo number_format((int)($r['quality_checked'] ?? 0)); ?></td>
                            <td style="color: #27ae60; font-weight: 600;"><?php echo number_format((int)($r['passed_qty'] ?? 0)); ?></td>
                            <td style="color: #e74c3c; font-weight: 600;"><?php echo number_format((int)($r['rejected_qty'] ?? 0)); ?></td>
                            <td><span class="badge <?php echo $badgeClass; ?>"><?php echo number_format($passRate, 1); ?>%</span></td>
                            <td><?php echo isset($r['actual_weight']) && $r['actual_weight'] !== '' && $r['actual_weight'] !== null ? number_format((float)$r['actual_weight'], 2) : '—'; ?></td>
                            <td><?php echo htmlspecialchars($r['shift_in_charge'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($r['project_name'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: 60px; color: #7f8c8d;">
            <i class="fas fa-shopping-bag" style="font-size: 64px; margin-bottom: 20px; opacity: 0.5;"></i>
            <p style="font-size: 1.1em;">No bag entries found for the selected filters.</p>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
