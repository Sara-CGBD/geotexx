<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'management', 'agm ops', 'production_user', 'prod_user'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$refFilter = trim($_GET['reference_number'] ?? '');

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
$where .= $hasProductType ? " AND fe.product_type = 'roll'" : "";

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
if ($refFilter !== '') {
    $where .= " AND (fe.reference_number = ? OR fe.reference_number LIKE ?)";
    $params[] = $refFilter;
    $params[] = '%' . $refFilter . '%';
    $types .= 'ss';
}

// Roll size from roll_entry (fg_entry stores reference; details from roll tables)
$reExists = $conn->query("SHOW TABLES LIKE 'roll_entry'")->num_rows > 0;
$reHasRollSize = $reExists && $conn->query("SHOW COLUMNS FROM roll_entry LIKE 'roll_size'")->num_rows > 0;
$rollSizeSub = ($reExists && $reHasRollSize)
    ? "COALESCE((SELECT MAX(re.roll_size) FROM roll_entry re WHERE TRIM(re.reference_number) = TRIM(fe.reference_number)), fe.bag_size)"
    : "fe.bag_size";

$select = "SELECT fe.fg_id, fe.date_time, fe.shift, fe.reference_number,
    {$rollSizeSub} AS bag_size,
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

$baseWhere = " AND reference_number IS NOT NULL AND TRIM(reference_number) != ''";
$baseWhere .= $hasIsDeleted ? " AND (is_deleted = 0 OR is_deleted IS NULL)" : "";
$baseWhere .= $hasProductType ? " AND product_type = 'roll'" : "";

$refRes = $conn->query("SELECT DISTINCT TRIM(COALESCE(reference_number,'')) AS reference_number FROM fg_entry WHERE 1=1 $baseWhere ORDER BY reference_number");
$references = $refRes ? $refRes->fetch_all(MYSQLI_ASSOC) : [];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FG Entry (Roll) Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1800px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }

        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; padding: 22px; color: white; text-align: center; }
        .stat-card.blue { background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); }
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
        <h1><i class="fas fa-scroll"></i> FG Entry (Roll) Report</h1>
        <p class="subtitle">Roll entries from FG module (product_type = roll)</p>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($totalEntries); ?></div>
                <div class="stat-label">Total Entries</div>
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
                        <label>Reference Number</label>
                        <select name="reference_number">
                            <option value="">All</option>
                            <?php foreach ($references as $ref): $refVal = $ref['reference_number'] ?? ''; ?>
                                <option value="<?php echo htmlspecialchars($refVal); ?>" <?php echo ($refFilter === $refVal) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($refVal); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <button type="submit" class="filter-btn"><i class="fas fa-search"></i> Apply</button>
                    </div>
                    <div class="filter-group">
                        <a href="fg_entry_roll_report.php" class="filter-btn reset-btn" style="text-decoration: none; display: inline-block; text-align: center;"><i class="fas fa-redo"></i> Reset</a>
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
                        <th>Reference Number</th>
                        <th>Roll Size</th>
                        <th>Shift in Charge</th>
                        <th>Project</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $sn = 1; foreach ($rows as $r): ?>
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
                            <td><?php echo htmlspecialchars($r['bag_size'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($r['shift_in_charge'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($r['project_name'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div style="text-align: center; padding: 60px; color: #7f8c8d;">
            <i class="fas fa-scroll" style="font-size: 64px; margin-bottom: 20px; opacity: 0.5;"></i>
            <p style="font-size: 1.1em;">No roll entries found for the selected filters.</p>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
