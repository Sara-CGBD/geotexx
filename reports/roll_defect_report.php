<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'production_user', 'qc_inspector', 'tester', 'checker', 'management', 'agm ops', 'agm operations'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access the Roll QC Defect Report.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Handle AJAX requests for filter options (loads asynchronously after page render)
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_GET['action'] === 'get_line_options') {
        $lineOptions = [];
        $lineRes = $conn->query("SELECT DISTINCT line_number FROM roll_qc_reports WHERE line_number IS NOT NULL AND line_number != '' ORDER BY line_number LIMIT 100");
        if ($lineRes) {
            $lineOptions = $lineRes->fetch_all(MYSQLI_ASSOC);
            $lineOptions = array_column($lineOptions, 'line_number');
        }
        echo json_encode(['success' => true, 'lines' => $lineOptions]);
        exit;
    } elseif ($_GET['action'] === 'get_inspector_options') {
        $inspectorOptions = [];
        $inspRes = $conn->query("SELECT DISTINCT inspector FROM roll_qc_reports WHERE inspector IS NOT NULL AND inspector != '' ORDER BY inspector LIMIT 100");
        if ($inspRes) {
            $inspectorOptions = $inspRes->fetch_all(MYSQLI_ASSOC);
            $inspectorOptions = array_column($inspectorOptions, 'inspector');
        }
        echo json_encode(['success' => true, 'inspectors' => $inspectorOptions]);
        exit;
    }
}

// Performance optimization: Set default date range (last 30 days) if no filters provided
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$overallStatus = $_GET['overall_status'] ?? '';
$lineNumber = $_GET['line_number'] ?? '';
$inspector = $_GET['inspector'] ?? '';

// If no date filters, default to last 30 days for faster initial load
$hasDateFilter = !empty($dateFrom) || !empty($dateTo);
if (!$hasDateFilter) {
    $dateTo = date('Y-m-d');
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
}

// Check if gsm_roll_entry table exists
$tableCheck = $conn->query("SHOW TABLES LIKE 'gsm_roll_entry'");
$gsmTableExists = ($tableCheck && $tableCheck->num_rows > 0);

// Check if gsm_roll_entry has project_id column
$hasProjectId = false;
if ($gsmTableExists) {
    $colCheck = $conn->query("SHOW COLUMNS FROM gsm_roll_entry LIKE 'project_id'");
    $hasProjectId = ($colCheck && $colCheck->num_rows > 0);
}

$query = "
    SELECT 
        rqr.*,
        COALESCE(g.roll_no, rqr.roll_no) AS roll_no_final,
        COALESCE(g.line_number, rqr.line_number) AS line_no_final,
        COALESCE(g.total_weight, 0) AS total_weight,
        " . ($hasProjectId ? "p.project_name" : "NULL AS project_name") . "
    FROM roll_qc_reports rqr
    " . ($gsmTableExists ? "LEFT JOIN gsm_roll_entry g ON rqr.reference_number = g.reference" : "") . "
    " . ($hasProjectId ? "LEFT JOIN projects p ON g.project_id = p.id" : "") . "
    WHERE 1=1
";

$params = [];
$types = '';

if ($dateFrom) {
    $query .= " AND rqr.created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
    $types .= 's';
}
if ($dateTo) {
    $query .= " AND rqr.created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
    $types .= 's';
}
if ($overallStatus) {
    $query .= " AND rqr.overall_status = ?";
    $params[] = $overallStatus;
    $types .= 's';
}
if ($lineNumber) {
    $query .= " AND rqr.line_number = ?";
    $params[] = $lineNumber;
    $types .= 's';
}
if ($inspector) {
    $query .= " AND rqr.inspector = ?";
    $params[] = $inspector;
    $types .= 's';
}

$query .= " ORDER BY rqr.created_at DESC LIMIT 1000";

$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$reports = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Performance: Defer filter options loading - will be loaded via AJAX after page renders
// This allows the page to display instantly while filter options load in background
$lineOptions = [];
$inspectorOptions = [];

$totalReports = count($reports);
$completedReports = 0;
$pendingReports = 0;
$totalProductAmount = 0;

$lineStats = [];
$statusStats = [];
$gsmStats = ['Done' => 0, 'Pending' => 0];
$lengthStats = ['Done' => 0, 'Pending' => 0];

// Optimized: Pre-process and cache lowercase values to avoid repeated strtolower() calls
foreach ($reports as $report) {
    $productAmount = (float)($report['product_amount'] ?? 0);
    $totalProductAmount += $productAmount;

    // Cache lowercase status once
    $status = strtolower($report['overall_status'] ?? '');
    if ($status === 'done' || $status === 'approved') {
        $completedReports++;
    } else {
        $pendingReports++;
    }
    
    $statusKey = $report['overall_status'] ?? 'Unknown';
    if (!isset($statusStats[$statusKey])) {
        $statusStats[$statusKey] = ['count' => 0, 'amount' => 0];
    }
    $statusStats[$statusKey]['count']++;
    $statusStats[$statusKey]['amount'] += $productAmount;

    $lineKey = $report['line_number'] ?? 'Unknown';
    if (!isset($lineStats[$lineKey])) {
        $lineStats[$lineKey] = ['count' => 0, 'amount' => 0];
    }
    $lineStats[$lineKey]['count']++;
    $lineStats[$lineKey]['amount'] += $productAmount;

    // Cache status comparisons
    $gsmStatus = ($report['gsm_check_status'] ?? '') === 'Done' ? 'Done' : 'Pending';
    $lengthStatus = ($report['length_calibration_status'] ?? '') === 'Done' ? 'Done' : 'Pending';
    $gsmStats[$gsmStatus]++;
    $lengthStats[$lengthStatus]++;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roll QC Defect Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1800px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); overflow-x: hidden; }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { border-radius: 12px; padding: 22px; color: white; }
        .stat-card.primary { background: linear-gradient(135deg, #8e44ad, #6c3483); }
        .stat-card.success { background: linear-gradient(135deg, #27ae60, #1e8449); }
        .stat-card.warning { background: linear-gradient(135deg, #e67e22, #d35400); }
        .stat-card.info { background: linear-gradient(135deg, #3498db, #2e86c1); }
        .stat-value { font-size: 2.4em; font-weight: 700; margin-bottom: 6px; }
        .stat-label { font-size: 0.95em; opacity: 0.9; }
        .filters { background: #f8f9fa; padding: 20px; border-radius: 10px; margin-bottom: 25px; }
        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-size: 0.85em; font-weight: 600; color: #555; margin-bottom: 5px; }
        .filter-group input, .filter-group select { padding: 9px 12px; border: 1px solid #dcdde1; border-radius: 6px; font-size: 0.9em; }
        .filter-btn, .reset-btn { border: none; padding: 10px 18px; border-radius: 6px; font-weight: 600; cursor: pointer; }
        .filter-btn { background: #2980b9; color: white; }
        .reset-btn { background: #bdc3c7; color: #2c3e50; }
        .section { margin-bottom: 35px; }
        .section-title { font-size: 1.2em; color: #34495e; margin-bottom: 10px; font-weight: 600; }
        .table-wrapper { width: 100%; overflow-x: auto; margin-top: 15px; border-radius: 10px; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; min-width: 1200px; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #ecf0f1; white-space: nowrap; }
        th { background: #34495e; color: white; font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.5px; position: sticky; top: 0; }
        tr:nth-child(even) { background: #fafbfc; }
        tr:hover { background: #f0f3f4; }
        
        /* Sticky first column for detailed table */
        #defectTable td:first-child, #defectTable th:first-child { position: sticky; left: 0; background: white; z-index: 1; min-width: 40px; }
        #defectTable th:first-child { background: #34495e; z-index: 2; }
        #defectTable tr:hover td:first-child { background: #f0f3f4; }
        #defectTable tr:nth-child(even) td:first-child { background: #fafbfc; }
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 999px; font-size: 0.8em; font-weight: 600; }
        .badge.done { background: #e8f5e9; color: #1e8449; }
        .badge.pending { background: #fff6e6; color: #d35400; }
        .badge.overall-done { background: #e3f2fd; color: #1f618d; }
        .badge.overall-pending { background: #fdecea; color: #c0392b; }
        .no-data { text-align: center; padding: 60px 20px; color: #95a5a6; }
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: 600; margin-bottom: 20px; }
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
    <h1><i class="fas fa-clipboard-check"></i> Roll QC Defect Report</h1>
    <p class="subtitle">Live view of roll QC submissions originating from the Roll QC Report form</p>

    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-value"><?php echo number_format($totalReports); ?></div>
            <div class="stat-label">Total QC Reports</div>
        </div>
        <div class="stat-card success">
            <div class="stat-value"><?php echo number_format($completedReports); ?></div>
            <div class="stat-label">Completed Checks</div>
        </div>
        <div class="stat-card warning">
            <div class="stat-value"><?php echo number_format($pendingReports); ?></div>
            <div class="stat-label">Pending / Defects</div>
        </div>
        <div class="stat-card info">
            <div class="stat-value"><?php echo number_format($totalProductAmount, 2); ?> kg</div>
            <div class="stat-label">Total Product Amount</div>
        </div>
    </div>

    <div class="filters">
        <?php if (!$hasDateFilter): ?>
        <div style="background: #e3f2fd; padding: 10px 15px; border-radius: 6px; margin-bottom: 15px; color: #1976d2; font-size: 0.9em;">
            <i class="fas fa-info-circle"></i> Showing last 30 days by default. Use date filters to view other periods.
        </div>
        <?php endif; ?>
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
                    <label for="overall_status"><i class="fas fa-flag"></i> Overall Status</label>
                    <select id="overall_status" name="overall_status">
                        <option value="">All Statuses</option>
                        <option value="Done" <?php echo $overallStatus === 'Done' ? 'selected' : ''; ?>>Done</option>
                        <option value="Approved" <?php echo $overallStatus === 'Approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="Pending" <?php echo $overallStatus === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="line_number"><i class="fas fa-stream"></i> Line Number</label>
                    <select id="line_number" name="line_number">
                        <option value="">All Lines</option>
                        <?php if (!empty($lineNumber)): ?>
                            <option value="<?php echo htmlspecialchars($lineNumber); ?>" selected><?php echo htmlspecialchars($lineNumber); ?></option>
                        <?php endif; ?>
                    </select>
                    <small id="line_loading" style="display: block; color: #7f8c8d; font-size: 0.75em; margin-top: 2px;">Loading options...</small>
                </div>
                <div class="filter-group">
                    <label for="inspector"><i class="fas fa-user-check"></i> Inspector</label>
                    <select id="inspector" name="inspector">
                        <option value="">All Inspectors</option>
                        <?php if (!empty($inspector)): ?>
                            <option value="<?php echo htmlspecialchars($inspector); ?>" selected><?php echo htmlspecialchars($inspector); ?></option>
                        <?php endif; ?>
                    </select>
                    <small id="inspector_loading" style="display: block; color: #7f8c8d; font-size: 0.75em; margin-top: 2px;">Loading options...</small>
                </div>
                <div class="filter-group" style="display:flex; gap:10px; align-items:flex-end;">
                    <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Filter</button>
                    <a href="roll_defect_report.php" class="reset-btn"><i class="fas fa-redo"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>

    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print Report</button>
    <button onclick="exportToCSV()" class="export-btn" style="background:#e67e22; margin-left:10px;"><i class="fas fa-file-csv"></i> Export CSV</button>

    <?php if ($totalReports > 0): ?>
        <div class="section">
            <h2 class="section-title"><i class="fas fa-stream"></i> Summary by Line</h2>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Line</th>
                            <th>Reports</th>
                            <th>Product Amount (kg)</th>
                            <th>Completion %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lineStats as $line => $stats): ?>
                            <?php $completion = $stats['count'] > 0 ? number_format(($stats['amount'] / max($totalProductAmount, 1)) * 100, 1) : 0; ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($line); ?></strong></td>
                                <td><?php echo number_format($stats['count']); ?></td>
                                <td><?php echo number_format($stats['amount'], 2); ?></td>
                                <td><?php echo $completion; ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="section">
            <h2 class="section-title"><i class="fas fa-clipboard-list"></i> Summary by Status</h2>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Reports</th>
                            <th>Product Amount (kg)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statusStats as $status => $stats): 
                            // Optimized: Cache lowercase check
                            $statusLower = strtolower($status);
                            $badgeClass = ($statusLower === 'done' || $statusLower === 'approved') ? 'overall-done' : 'overall-pending';
                        ?>
                            <tr>
                                <td>
                                    <span class="badge <?php echo $badgeClass; ?>">
                                        <?php echo htmlspecialchars($status); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($stats['count']); ?></td>
                                <td><?php echo number_format($stats['amount'], 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="section">
            <h2 class="section-title"><i class="fas fa-list"></i> Detailed Roll QC Entries</h2>
            <div class="table-wrapper">
                <table id="defectTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Reference</th>
                            <th>Roll No.</th>
                            <th>Line</th>
                            <th>Product Amount (kg)</th>
                            <th>GSM Check</th>
                            <th>Length Calibration</th>
                            <th>Overall Status</th>
                            <th>Inspector</th>
                            <th>Created At</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = 1;
                        foreach ($reports as $report): 
                            // Optimized: Cache lowercase values and status checks once per row
                            $gsmStatus = strtolower($report['gsm_check_status'] ?? '');
                            $lengthStatus = strtolower($report['length_calibration_status'] ?? '');
                            $overallStatus = strtolower($report['overall_status'] ?? '');
                            
                            $issues = [];
                            if ($gsmStatus !== 'done') {
                                $issues[] = 'GSM Pending';
                            }
                            if ($lengthStatus !== 'done') {
                                $issues[] = 'Length Calibration Pending';
                            }
                            if (empty($issues) && ($overallStatus === 'done' || $overallStatus === 'approved')) {
                                $issues[] = 'All checks completed';
                            }
                            
                            // Cache formatted values
                            $gsmBadgeClass = $gsmStatus === 'done' ? 'done' : 'pending';
                            $lengthBadgeClass = $lengthStatus === 'done' ? 'done' : 'pending';
                            $overallBadgeClass = ($overallStatus === 'done' || $overallStatus === 'approved') ? 'overall-done' : 'overall-pending';
                            $createdAtFormatted = date('M d, Y g:i A', strtotime($report['created_at']));
                            $issuesText = implode(', ', $issues);
                        ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td><strong><?php echo htmlspecialchars($report['reference_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($report['roll_no_final']); ?></td>
                                <td><?php echo htmlspecialchars($report['line_no_final']); ?></td>
                                <td><?php echo number_format($report['product_amount'], 2); ?></td>
                                <td>
                                    <span class="badge <?php echo $gsmBadgeClass; ?>">
                                        <?php echo htmlspecialchars($report['gsm_check_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $lengthBadgeClass; ?>">
                                        <?php echo htmlspecialchars($report['length_calibration_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $overallBadgeClass; ?>">
                                        <?php echo htmlspecialchars($report['overall_status']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($report['inspector'] ?? 'N/A'); ?></td>
                                <td><?php echo $createdAtFormatted; ?></td>
                                <td><?php echo htmlspecialchars($issuesText); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-smile" style="font-size:3em; color:#27ae60; margin-bottom:15px;"></i>
            <p>No roll QC entries found for the selected filters.</p>
            <p style="font-size:0.9em; margin-top:10px; color:#7f8c8d;">All rolls are compliant or no QC reports have been submitted yet.</p>
        </div>
    <?php endif; ?>
</div>

<script>
// Optimized: Cache table reference
let defectTable = null;

function exportToCSV() {
    if (!defectTable) {
        defectTable = document.getElementById('defectTable');
    }
    if (!defectTable) return;

    // Optimized: Use direct references instead of multiple querySelectorAll
    const rows = defectTable.rows;
    const csv = [];
    
    // Process header row
    const headerRow = rows[0];
    const headerCols = Array.from(headerRow.cells).map(cell => {
        let text = cell.textContent.trim();
        if (text.includes('"') || text.includes(',')) {
            text = '"' + text.replace(/"/g, '""') + '"';
        }
        return text;
    });
    csv.push(headerCols.join(','));
    
    // Process data rows
    for (let i = 1; i < rows.length; i++) {
        const cols = Array.from(rows[i].cells).map(cell => {
            let text = cell.textContent.trim();
            if (text.includes('"') || text.includes(',')) {
                text = '"' + text.replace(/"/g, '""') + '"';
            }
            return text;
        });
        csv.push(cols.join(','));
    }

    const blob = new Blob([csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'roll_qc_defect_report_' + new Date().toISOString().slice(0,10) + '.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(link.href); // Clean up object URL
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    defectTable = document.getElementById('defectTable');
    
    // Load filter options asynchronously after page renders for instant page load
    loadFilterOptions();
});

// Load filter options asynchronously to avoid blocking page render
function loadFilterOptions() {
    // Load line options
    fetch('?action=get_line_options')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.lines) {
                const lineSelect = document.getElementById('line_number');
                const currentValue = lineSelect.value;
                const loadingText = document.getElementById('line_loading');
                if (loadingText) loadingText.style.display = 'none';
                
                data.lines.forEach(line => {
                    // Skip if option already exists (from current filter)
                    if (lineSelect.querySelector(`option[value="${line}"]`)) return;
                    const option = document.createElement('option');
                    option.value = line;
                    option.textContent = line;
                    if (line === currentValue) option.selected = true;
                    lineSelect.appendChild(option);
                });
            }
        })
        .catch(err => {
            console.error('Error loading line options:', err);
            const lineSelect = document.getElementById('line_number');
            const loadingText = document.getElementById('line_loading');
            if (loadingText) loadingText.textContent = 'Failed to load';
        });
    
    // Load inspector options
    fetch('?action=get_inspector_options')
        .then(response => response.json())
        .then(data => {
            if (data.success && data.inspectors) {
                const inspectorSelect = document.getElementById('inspector');
                const currentValue = inspectorSelect.value;
                const loadingText = document.getElementById('inspector_loading');
                if (loadingText) loadingText.style.display = 'none';
                
                data.inspectors.forEach(inspector => {
                    // Skip if option already exists (from current filter)
                    if (inspectorSelect.querySelector(`option[value="${inspector}"]`)) return;
                    const option = document.createElement('option');
                    option.value = inspector;
                    option.textContent = inspector;
                    if (inspector === currentValue) option.selected = true;
                    inspectorSelect.appendChild(option);
                });
            }
        })
        .catch(err => {
            console.error('Error loading inspector options:', err);
            const inspectorSelect = document.getElementById('inspector');
            const loadingText = document.getElementById('inspector_loading');
            if (loadingText) loadingText.textContent = 'Failed to load';
        });
}
</script>
</body>
</html>


