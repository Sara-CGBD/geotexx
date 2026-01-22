<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'production_user', 'production', 'prod_test', 'management', 'agm ops', 'sewing_test'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Determine which sewing table exists
$sewingTableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
$sewingTable = ($sewingTableCheck && $sewingTableCheck->num_rows > 0) ? 'sewing_machine_entry' : 'swing_machine_entry';

// Check which ID column exists in the table
$idColumn = 'sewing_id';
$colCheck = $conn->query("SHOW COLUMNS FROM $sewingTable LIKE 'sewing_id'");
if (!$colCheck || $colCheck->num_rows == 0) {
    $colCheck2 = $conn->query("SHOW COLUMNS FROM $sewingTable LIKE 'swing_id'");
    if ($colCheck2 && $colCheck2->num_rows > 0) {
        $idColumn = 'swing_id';
    }
}

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$lineNo = $_GET['line_no'] ?? '';
$shift = $_GET['shift'] ?? '';

// Performance: Set default date range (last 30 days) if no filters provided
$hasDateFilter = !empty($dateFrom) || !empty($dateTo);
if (!$hasDateFilter) {
    $dateTo = date('Y-m-d');
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
}

$query = "SELECT 
    s.*,
    s.$idColumn as entry_id,
    p.project_name,
    COALESCE(
        (SELECT full_name FROM new_user WHERE id = s.reporter_id LIMIT 1),
        (SELECT username FROM new_user WHERE id = s.reporter_id LIMIT 1),
        (SELECT username FROM users WHERE id = s.reporter_id LIMIT 1),
        'Admin'
    ) as reporter_name
FROM $sewingTable s
LEFT JOIN projects p ON s.project_id = p.id
WHERE 1=1";

$params = [];
$types = '';

if ($dateFrom) {
    $query .= " AND s.date_time >= ?";
    $params[] = $dateFrom . ' 00:00:00';
    $types .= 's';
}
if ($dateTo) {
    $query .= " AND s.date_time <= ?";
    $params[] = $dateTo . ' 23:59:59';
    $types .= 's';
}
if ($lineNo) {
    $query .= " AND s.line_no = ?";
    $params[] = $lineNo;
    $types .= 's';
}
if ($shift) {
    $query .= " AND s.shift = ?";
    $params[] = $shift;
    $types .= 's';
}

$query .= " ORDER BY s.date_time DESC LIMIT 1000";

$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$entries = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalSewing = array_sum(array_column($entries, 'sewing_qty'));
$totalNCP = array_sum(array_column($entries, 'ncp_piece'));

// Performance: Defer filter options - load asynchronously after page render
$lineList = [];

// Get hourly production data
$hourlyQuery = "SELECT 
    DATE(s.date_time) as production_date,
    HOUR(s.date_time) as production_hour,
    s.line_no,
    COUNT(*) as entry_count,
    SUM(s.sewing_qty) as total_qty,
    CASE 
        WHEN HOUR(s.date_time) >= 8 AND HOUR(s.date_time) < 20 THEN 'Day'
        ELSE 'Night'
    END as calculated_shift
FROM $sewingTable s
WHERE 1=1";

if ($dateFrom) {
    $hourlyQuery .= " AND s.date_time >= '$dateFrom 00:00:00'";
}
if ($dateTo) {
    $hourlyQuery .= " AND s.date_time <= '$dateTo 23:59:59'";
}
if ($lineNo) {
    $hourlyQuery .= " AND s.line_no = '$lineNo'";
}

$hourlyQuery .= " GROUP BY production_date, production_hour, s.line_no, calculated_shift
ORDER BY production_date DESC, production_hour DESC, s.line_no";

$hourlyResult = $conn->query($hourlyQuery);
$hourlyData = $hourlyResult ? $hourlyResult->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sewing Output Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1800px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); overflow-x: hidden; }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; padding: 25px; color: white; text-align: center; }
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
        
        .table-wrapper { overflow-x: auto; width: 100%; margin-bottom: 20px; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: collapse; font-size: 0.9em; min-width: 1300px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ecf0f1; white-space: nowrap; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; }
        tr:hover { background: #f8f9fa; }
        
        /* Sticky first column */
        td:first-child, th:first-child { position: sticky; left: 0; background: white; z-index: 1; min-width: 40px; }
        th:first-child { background: #34495e; z-index: 2; }
        tr:hover td:first-child { background: #f8f9fa; }
        
        /* Column widths for better readability */
        table th:nth-child(1), table td:nth-child(1) { width: 50px; } /* # */
        table th:nth-child(2), table td:nth-child(2) { min-width: 120px; } /* Swing ID */
        table th:nth-child(3), table td:nth-child(3) { min-width: 150px; } /* Date & Time */
        table th:nth-child(4), table td:nth-child(4) { min-width: 80px; } /* Shift */
        table th:nth-child(5), table td:nth-child(5) { min-width: 80px; } /* Line No */
        table th:nth-child(6), table td:nth-child(6) { min-width: 120px; } /* Project */
        table th:nth-child(7), table td:nth-child(7) { min-width: 100px; } /* Sewing Qty */
        table th:nth-child(8), table td:nth-child(8) { min-width: 100px; } /* NCP Pieces */
        table th:nth-child(9), table td:nth-child(9) { min-width: 120px; } /* Reporter */
        
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        .export-btn:hover { background: #229954; }
        
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; }
        .tab { padding: 12px 24px; border: none; background: transparent; cursor: pointer; font-weight: 600; color: #718096; border-bottom: 3px solid transparent; transition: all 0.3s; }
        .tab:hover { color: #667eea; }
        .tab.active { color: #667eea; border-bottom-color: #667eea; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .section-title { font-size: 1.2em; color: #34495e; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #3498db; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-day { background: #fef5e7; color: #f39c12; }
        .badge-night { background: #e8eaf6; color: #3f51b5; }
        
        @media print {
            @page {
                size: A4 landscape;
                margin: 10mm;
            }
            * { box-sizing: border-box; }
            body { 
                background: white; 
                padding: 10px !important; 
                margin: 0 !important;
                font-size: 10px !important;
            }
            .container { 
                box-shadow: none; 
                padding: 10px !important;
                margin: 0 !important;
                max-width: 100% !important;
            }
            .filters, .export-btn, .tabs { display: none !important; }
            .tab-content { display: block !important; }
            h1 { 
                font-size: 14px !important; 
                margin: 3px 0 !important; 
                padding: 0 !important;
                page-break-after: avoid;
            }
            .subtitle { 
                font-size: 9px !important; 
                margin: 2px 0 8px !important; 
                padding: 0 !important;
            }
            .section-title { 
                font-size: 11px !important; 
                margin: 8px 0 3px !important; 
                padding: 3px 0 !important; 
                page-break-after: avoid;
            }
            .section { 
                margin-bottom: 10px !important;
                page-break-inside: avoid;
                overflow: visible !important;
            }
            table { 
                font-size: 7px !important; 
                width: 100% !important;
                min-width: auto !important;
                max-width: 100% !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                border-collapse: collapse !important;
                margin-bottom: 8px !important;
                table-layout: auto !important;
            }
            th, td { 
                padding: 3px 2px !important; 
                font-size: 7px !important;
                line-height: 1.1 !important;
                border: 1px solid #ddd !important;
                white-space: normal !important;
                word-wrap: break-word !important;
                position: static !important;
            }
            th { 
                font-size: 7px !important; 
                font-weight: 600 !important;
                position: static !important;
                top: auto !important;
            }
            .table-wrapper {
                overflow: visible !important;
                width: 100% !important;
            }
            td:first-child, th:first-child {
                position: static !important;
                left: auto !important;
                background: transparent !important;
                z-index: auto !important;
            }
            th:first-child {
                background: #34495e !important;
            }
            .stats-grid { 
                grid-template-columns: repeat(3, 1fr) !important;
                gap: 5px !important;
                margin-bottom: 10px !important;
            }
            .stat-card { 
                padding: 8px 5px !important;
                page-break-inside: avoid;
                margin-bottom: 0 !important;
            }
            .stat-value { 
                font-size: 1.2em !important; 
                margin-bottom: 2px !important;
            }
            .stat-label { 
                font-size: 0.75em !important; 
            }
            tr { page-break-inside: avoid; }
            thead { display: table-header-group !important; }
            tfoot { display: table-footer-group !important; }
            @page {
                size: A4 landscape;
                margin: 0.3cm;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-sewing-machine"></i> Sewing Output Report</h1>
    <p class="subtitle">Total sewing production per shift, line, and operator</p>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($totalSewing); ?></div>
            <div class="stat-label">Total Sewing Qty</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-value"><?php echo number_format($totalNCP); ?></div>
            <div class="stat-label">Total NCP Pieces</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value"><?php echo count($entries); ?></div>
            <div class="stat-label">Total Entries</div>
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
                    <label><i class="fas fa-industry"></i> Line No</label>
                    <select name="line_no">
                        <option value="">All Lines</option>
                        <?php foreach ($lineList as $line): ?>
                            <option value="<?php echo htmlspecialchars($line['line_no']); ?>" 
                                <?php echo $lineNo == $line['line_no'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($line['line_no']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-clock"></i> Shift</label>
                    <select name="shift">
                        <option value="">All Shifts</option>
                        <option value="Day" <?php echo $shift == 'Day' ? 'selected' : ''; ?>>Day</option>
                        <option value="Night" <?php echo $shift == 'Night' ? 'selected' : ''; ?>>Night</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Filter</button>
                    <a href="sewing_output_report.php" class="filter-btn reset-btn" style="text-decoration: none; display: inline-block; text-align: center; line-height: 2;">Reset</a>
                </div>
            </div>
        </div>
    </form>
    
    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print</button>
    <button onclick="exportToCSV()" class="export-btn" style="background: #e67e22;"><i class="fas fa-file-csv"></i> Export CSV</button>
    
    <!-- Tabs -->
    <div class="tabs">
        <button class="tab active" onclick="switchTab('detailed', this)">Detailed View</button>
        <button class="tab" onclick="switchTab('hourly', this)">Hourly Production</button>
    </div>
    
    <!-- Detailed Table -->
    <div id="detailed-tab" class="tab-content active">
    <h3 class="section-title">Detailed Sewing Entries</h3>
    <div class="table-wrapper">
    <table id="dataTable">
        <thead>
            <tr>
                <th>#</th>
                <th>Swing ID</th>
                <th>Date & Time</th>
                <th>Shift</th>
                <th>Line No</th>
                <th>Project</th>
                <th>Sewing Qty</th>
                <th>NCP Pieces</th>
                <th>Reporter</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $counter = 1;
            foreach ($entries as $entry): 
            ?>
                <tr>
                    <td><?php echo $counter++; ?></td>
                    <td><strong><?php echo htmlspecialchars($entry['entry_id'] ?? $entry['sewing_id'] ?? $entry['swing_id'] ?? 'N/A'); ?></strong></td>
                    <td><?php echo date('M d, Y g:i A', strtotime($entry['date_time'])); ?></td>
                    <td><?php 
                        // Calculate shift from date_time if shift is empty, 0, or invalid
                        $shiftValue = $entry['shift'] ?? '';
                        if (empty($shiftValue) || $shiftValue === '0' || !in_array($shiftValue, ['Day', 'Night'])) {
                            $hour = (int)date('H', strtotime($entry['date_time']));
                            $shiftValue = ($hour >= 8 && $hour < 20) ? 'Day' : 'Night';
                        }
                        echo htmlspecialchars($shiftValue);
                    ?></td>
                    <td><?php echo htmlspecialchars($entry['line_no']); ?></td>
                    <td><?php echo htmlspecialchars($entry['project_name'] ?? 'N/A'); ?></td>
                    <td><?php echo number_format($entry['sewing_qty']); ?></td>
                    <td><?php echo number_format($entry['ncp_piece']); ?></td>
                    <td><?php echo htmlspecialchars($entry['reporter_name'] ?? 'Unknown'); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
    
    <!-- Hourly Production Tab -->
    <div id="hourly-tab" class="tab-content">
    <h3 class="section-title">Hourly Sewing Production</h3>
    <?php if (count($hourlyData) > 0): ?>
    <div class="table-wrapper">
    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Hour</th>
                <th>Shift</th>
                <th>Line No</th>
                <th>Entry Count</th>
                <th>Total Sewing Qty</th>
                <th>Avg per Entry</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($hourlyData as $row): ?>
                <tr>
                    <td><?php echo date('M d, Y', strtotime($row['production_date'])); ?></td>
                    <td><?php echo str_pad($row['production_hour'], 2, '0', STR_PAD_LEFT) . ':00'; ?></td>
                    <td><span class="badge badge-<?php echo strtolower($row['calculated_shift']); ?>"><?php echo $row['calculated_shift']; ?></span></td>
                    <td><strong>Line <?php echo htmlspecialchars($row['line_no']); ?></strong></td>
                    <td><?php echo number_format($row['entry_count']); ?></td>
                    <td><strong><?php echo number_format($row['total_qty'] ?? 0); ?></strong></td>
                    <td><?php echo $row['total_qty'] ? number_format($row['total_qty'] / $row['entry_count'], 0) : '0'; ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
        <p style="text-align: center; padding: 40px; color: #95a5a6; font-style: italic;">No hourly production data found for the selected filters.</p>
    <?php endif; ?>
    </div>
</div>

<script>
// Cache DOM elements for better performance
let tabContents = null;
let tabs = null;

// Initialize cached elements once
function initTabs() {
    if (!tabContents) {
        tabContents = document.querySelectorAll('.tab-content');
        tabs = document.querySelectorAll('.tab');
    }
}

// Optimized tab switching function
function switchTab(tabName, buttonElement) {
    initTabs();
    
    // Hide all tab contents using cached elements
    tabContents.forEach(content => {
        content.classList.remove('active');
    });
    
    // Remove active class from all tabs using cached elements
    tabs.forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Show selected tab content
    const targetContent = document.getElementById(tabName + '-tab');
    if (targetContent) {
        targetContent.classList.add('active');
    }
    
    // Add active class to clicked tab
    if (buttonElement) {
        buttonElement.classList.add('active');
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', initTabs);


function exportToCSV() {
    const table = document.getElementById('dataTable');
    let csv = [];
    
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
    link.setAttribute('download', 'sewing_output_report_' + new Date().toISOString().slice(0,10) + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>


