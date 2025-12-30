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
        <p>You do not have permission to access Production Reports.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$projectId = $_GET['project_id'] ?? '';
$shift = $_GET['shift'] ?? '';

// Fetch projects for filter
$projects = [];
$projectResult = $conn->query("SELECT id, project_name FROM projects WHERE status = 'active' ORDER BY project_name");
if ($projectResult) {
    $projects = $projectResult->fetch_all(MYSQLI_ASSOC);
}

// Build WHERE clause (will be customized per table)
$projectFilter = "";
$projectParam = [];
$projectType = '';

if ($projectId) {
    $projectFilter = " AND project_id = ?";
    $projectParam[] = $projectId;
    $projectType = 'i';
}

// ==== ROLL ENTRY STATISTICS ====
$rollWhere = "1=1";
$rollParams = [];
$rollTypes = '';

if ($dateFrom) {
    $rollWhere .= " AND DATE(created_at) >= ?";
    $rollParams[] = $dateFrom;
    $rollTypes .= 's';
}
if ($dateTo) {
    $rollWhere .= " AND DATE(created_at) <= ?";
    $rollParams[] = $dateTo;
    $rollTypes .= 's';
}
if ($projectId) {
    $rollWhere .= $projectFilter;
    $rollParams = array_merge($rollParams, $projectParam);
    $rollTypes .= $projectType;
}

$rollQuery = "SELECT 
    DATE(created_at) as production_date,
    HOUR(created_at) as production_hour,
    COUNT(*) as roll_count,
    SUM(total_weight) as total_weight,
    material_type,
    CASE 
        WHEN HOUR(created_at) >= 8 AND HOUR(created_at) < 20 THEN 'Day'
        ELSE 'Night'
    END as calculated_shift
FROM roll_entry
WHERE $rollWhere AND created_at IS NOT NULL";

if ($shift) {
    $rollQuery .= " HAVING calculated_shift = '$shift'";
}

$rollQuery .= " GROUP BY production_date, production_hour, material_type, calculated_shift
ORDER BY production_date DESC, production_hour DESC";

$rollStmt = $conn->prepare($rollQuery);
if ($rollTypes) {
    $rollStmt->bind_param($rollTypes, ...$rollParams);
}
$rollStmt->execute();
$rollHourlyData = $rollStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$rollStmt->close();

// ==== CNC ENTRY STATISTICS ====
$cncWhere = "1=1";
$cncParams = [];
$cncTypes = '';

if ($dateFrom) {
    $cncWhere .= " AND DATE(date_time) >= ?";
    $cncParams[] = $dateFrom;
    $cncTypes .= 's';
}
if ($dateTo) {
    $cncWhere .= " AND DATE(date_time) <= ?";
    $cncParams[] = $dateTo;
    $cncTypes .= 's';
}
if ($projectId) {
    $cncWhere .= $projectFilter;
    $cncParams = array_merge($cncParams, $projectParam);
    $cncTypes .= $projectType;
}

$cncQuery = "SELECT 
    DATE(date_time) as production_date,
    HOUR(date_time) as production_hour,
    COUNT(*) as cnc_count,
    cnc_cutting_batch,
    CASE 
        WHEN HOUR(date_time) >= 8 AND HOUR(date_time) < 20 THEN 'Day'
        ELSE 'Night'
    END as calculated_shift
FROM cnc_entries
WHERE $cncWhere";

if ($shift) {
    $cncQuery .= " HAVING calculated_shift = '$shift'";
}

$cncQuery .= " GROUP BY production_date, production_hour, cnc_cutting_batch, calculated_shift
ORDER BY production_date DESC, production_hour DESC";

$cncStmt = $conn->prepare($cncQuery);
if ($cncTypes) {
    $cncStmt->bind_param($cncTypes, ...$cncParams);
}
$cncStmt->execute();
$cncHourlyData = $cncStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$cncStmt->close();

// ==== SEWING MACHINE STATISTICS ====
$sewingWhere = "1=1";
$sewingParams = [];
$sewingTypes = '';

if ($dateFrom) {
    $sewingWhere .= " AND DATE(date_time) >= ?";
    $sewingParams[] = $dateFrom;
    $sewingTypes .= 's';
}
if ($dateTo) {
    $sewingWhere .= " AND DATE(date_time) <= ?";
    $sewingParams[] = $dateTo;
    $sewingTypes .= 's';
}
if ($projectId) {
    $sewingWhere .= $projectFilter;
    $sewingParams = array_merge($sewingParams, $projectParam);
    $sewingTypes .= $projectType;
}

$sewingQuery = "SELECT 
    DATE(date_time) as production_date,
    HOUR(date_time) as production_hour,
    COUNT(*) as sewing_count,
    SUM(sewing_qty) as total_production,
    line_no,
    CASE 
        WHEN HOUR(date_time) >= 8 AND HOUR(date_time) < 20 THEN 'Day'
        ELSE 'Night'
    END as calculated_shift
FROM swing_machine_entry
WHERE $sewingWhere";

if ($shift) {
    $sewingQuery .= " HAVING calculated_shift = '$shift'";
}

$sewingQuery .= " GROUP BY production_date, production_hour, line_no, calculated_shift
ORDER BY production_date DESC, production_hour DESC";

$sewingStmt = $conn->prepare($sewingQuery);
if ($sewingTypes) {
    $sewingStmt->bind_param($sewingTypes, ...$sewingParams);
}
$sewingStmt->execute();
$sewingHourlyData = $sewingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$sewingStmt->close();

// ==== DAILY SUMMARY ====
$dailyQuery = "SELECT 
    DATE(date_time) as production_date,
    CASE 
        WHEN HOUR(date_time) >= 8 AND HOUR(date_time) < 20 THEN 'Day'
        ELSE 'Night'
    END as calculated_shift,
    line_no,
    COUNT(*) as entry_count,
    SUM(sewing_qty) as total_qty
FROM swing_machine_entry
WHERE $sewingWhere
GROUP BY production_date, calculated_shift, line_no
ORDER BY production_date DESC, calculated_shift, line_no";

$dailyStmt = $conn->prepare($dailyQuery);
if ($sewingTypes) {
    $dailyStmt->bind_param($sewingTypes, ...$sewingParams);
}
$dailyStmt->execute();
$dailyData = $dailyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dailyStmt->close();

// ==== SHIFT-WISE SUMMARY ====
$shiftQuery = "SELECT 
    CASE 
        WHEN HOUR(date_time) >= 8 AND HOUR(date_time) < 20 THEN 'Day'
        ELSE 'Night'
    END as calculated_shift,
    line_no,
    COUNT(*) as entry_count,
    SUM(sewing_qty) as total_qty
FROM swing_machine_entry
WHERE $sewingWhere
GROUP BY calculated_shift, line_no
ORDER BY calculated_shift, line_no";

$shiftStmt = $conn->prepare($shiftQuery);
if ($sewingTypes) {
    $shiftStmt->bind_param($sewingTypes, ...$sewingParams);
}
$shiftStmt->execute();
$shiftData = $shiftStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$shiftStmt->close();

// ==== BRANDING STATISTICS ====
$brandingWhere = "1=1";
$brandingParams = [];
$brandingTypes = '';

if ($dateFrom) {
    $brandingWhere .= " AND DATE(date_time) >= ?";
    $brandingParams[] = $dateFrom;
    $brandingTypes .= 's';
}
if ($dateTo) {
    $brandingWhere .= " AND DATE(date_time) <= ?";
    $brandingParams[] = $dateTo;
    $brandingTypes .= 's';
}
if ($projectId) {
    $brandingWhere .= $projectFilter;
    $brandingParams = array_merge($brandingParams, $projectParam);
    $brandingTypes .= $projectType;
}

$brandingQuery = "SELECT 
    DATE(date_time) as production_date,
    HOUR(date_time) as production_hour,
    COUNT(*) as branding_count,
    SUM(print_qty) as total_printed,
    CASE 
        WHEN HOUR(date_time) >= 8 AND HOUR(date_time) < 20 THEN 'Day'
        ELSE 'Night'
    END as calculated_shift
FROM branding_entries
WHERE $brandingWhere";

if ($shift) {
    $brandingQuery .= " HAVING calculated_shift = '$shift'";
}

$brandingQuery .= " GROUP BY production_date, production_hour, calculated_shift
ORDER BY production_date DESC, production_hour DESC";

$brandingStmt = $conn->prepare($brandingQuery);
if ($brandingTypes) {
    $brandingStmt->bind_param($brandingTypes, ...$brandingParams);
}
$brandingStmt->execute();
$brandingHourlyData = $brandingStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$brandingStmt->close();

// Calculate overall statistics
$totalRolls = array_sum(array_column($rollHourlyData, 'roll_count'));
$totalWeight = array_sum(array_column($rollHourlyData, 'total_weight'));
$totalCNC = array_sum(array_column($cncHourlyData, 'cnc_count'));
$totalSewing = array_sum(array_column($sewingHourlyData, 'sewing_count'));
$totalBranding = array_sum(array_column($brandingHourlyData, 'branding_count'));
$totalPrinted = array_sum(array_column($brandingHourlyData, 'total_printed'));

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production Summary Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: #f5f7fa;
            padding: 20px;
            color: #2c3e50;
        }
        .container {
            max-width: 1600px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        h1 {
            text-align: center;
            color: #34495e;
            margin-bottom: 10px;
        }
        .subtitle {
            text-align: center;
            color: #7f8c8d;
            margin-bottom: 30px;
            font-size: 0.95em;
        }
        /* Filters */
        .filters {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
        }
        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        .filter-group label {
            font-size: 0.85em;
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
        }
        .filter-group input, .filter-group select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 0.9em;
        }
        .filter-btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
        }
        .filter-btn:hover {
            background: #2980b9;
        }
        .reset-btn {
            background: #95a5a6;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            margin-left: 10px;
        }
        .reset-btn:hover {
            background: #7f8c8d;
        }
        
        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 10px;
            padding: 25px;
            color: white;
            text-align: center;
        }
        .stat-card.green {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        .stat-card.orange {
            background: linear-gradient(135deg, #ee0979 0%, #ff6a00 100%);
        }
        .stat-card.blue {
            background: linear-gradient(135deg, #2193b0 0%, #6dd5ed 100%);
        }
        .stat-value {
            font-size: 2.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .stat-label {
            font-size: 0.9em;
            opacity: 0.95;
        }
        
        /* Sections */
        .section {
            margin-bottom: 40px;
        }
        .section-title {
            font-size: 1.3em;
            color: #34495e;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 3px solid #3498db;
        }
        /* Tables */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }
        th {
            background: #34495e;
            color: white;
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        tr:hover {
            background: #f8f9fa;
        }
        tr:nth-child(even) {
            background: #f9f9f9;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-day {
            background: #fef5e7;
            color: #f39c12;
        }
        .badge-night {
            background: #e8eaf6;
            color: #3f51b5;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: #95a5a6;
            font-style: italic;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #3498db;
            text-decoration: none;
            font-weight: 600;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .print-btn {
            background: #27ae60;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
        }
        .print-btn:hover {
            background: #229954;
        }
        .chart-container {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        .chart-wrapper {
            position: relative;
            height: 400px;
            margin-top: 20px;
        }
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            border-bottom: 2px solid #e2e8f0;
        }
        .tab {
            padding: 12px 24px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-weight: 600;
            color: #718096;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }
        .tab:hover {
            color: #667eea;
        }
        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        @media print {
            .filters, .back-link, .tabs, .print-btn { display: none; }
            body { background: white; padding: 0; }
            .container { box-shadow: none; }
            .tab-content { display: block !important; }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="../index.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        
        <h1>Production Summary Report</h1>
        <div class="subtitle">Complete production tracking across all stages</div>

        <!-- Filter Section -->
        <div class="filters">
            <form method="GET" class="filter-row">
                <div class="filter-group">
                    <label>Date From:</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                </div>
                <div class="filter-group">
                    <label>Date To:</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
                <div class="filter-group">
                    <label>Project:</label>
                    <select name="project_id">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $project): ?>
                            <option value="<?php echo $project['id']; ?>" <?php echo ($projectId == $project['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($project['project_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Shift:</label>
                    <select name="shift">
                        <option value="">All Shifts</option>
                        <option value="Day" <?php echo ($shift == 'Day') ? 'selected' : ''; ?>>Day Shift</option>
                        <option value="Night" <?php echo ($shift == 'Night') ? 'selected' : ''; ?>>Night Shift</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="filter-btn">Apply Filter</button>
                    <button type="button" class="print-btn" onclick="window.print()" style="margin-top:5px;">🖨️ Print</button>
                </div>
            </form>
        </div>

        <!-- Statistics Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($totalRolls); ?></div>
                <div class="stat-label">Roll Recceived<br><?php echo number_format($totalWeight, 2); ?> kg</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-value"><?php echo number_format($totalCNC); ?></div>
                <div class="stat-label">CNC Cutting Batches</div>
            </div>
            <div class="stat-card green">
                <div class="stat-value"><?php echo number_format($totalSewing); ?></div>
                <div class="stat-label">Sewing Entries</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-value"><?php echo number_format($totalBranding); ?></div>
                <div class="stat-label">Branding<br><?php echo number_format($totalPrinted); ?> pieces</div>
            </div>
        </div>

        <!-- Content -->
        <div class="section">
            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('daily', this)">Daily Summary</button>
                <button class="tab" onclick="switchTab('shift', this)">Shift-Wise</button>
                <button class="tab" onclick="switchTab('hourly', this)">Hourly Production</button>
                <button class="tab" onclick="switchTab('detailed', this)">Detailed View</button>
            </div>

            <!-- DAILY SUMMARY TAB -->
            <div id="daily-tab" class="tab-content active">
                <h3 class="section-title">Daily Production Summary - Per Line</h3>
                <?php if (count($dailyData) > 0): ?>
                    <!-- Chart -->
                    <div class="chart-container">
                        <h3 style="margin-bottom: 15px; color: #4a5568;">Daily Production Trend</h3>
                        <div class="chart-wrapper">
                            <canvas id="dailyChart"></canvas>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Shift</th>
                                    <th>Line Number</th>
                                    <th>Entries</th>
                                    <th>Total Production (Qty)</th>
                                    <th>Avg per Entry</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dailyData as $row): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($row['production_date'])); ?></td>
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
                    <div class="no-data">No daily production data found for the selected filters.</div>
                <?php endif; ?>
            </div>

            <!-- SHIFT-WISE TAB -->
            <div id="shift-tab" class="tab-content">
                <h3 class="section-title">Shift-Wise Production Summary - Per Line</h3>
                <?php if (count($shiftData) > 0): ?>
                    <!-- Chart -->
                    <div class="chart-container">
                        <h3 style="margin-bottom: 15px; color: #4a5568;">Shift Comparison by Line</h3>
                        <div class="chart-wrapper">
                            <canvas id="shiftChart"></canvas>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Shift</th>
                                    <th>Line Number</th>
                                    <th>Entries</th>
                                    <th>Total Production (Qty)</th>
                                    <th>Avg per Entry</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($shiftData as $row): ?>
                                    <tr>
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
                    <div class="no-data">No shift-wise production data found for the selected filters.</div>
                <?php endif; ?>
            </div>

            <!-- HOURLY TAB -->
            <div id="hourly-tab" class="tab-content">
                <h3 class="section-title">Hourly Production - Per Line</h3>
                <?php if (count($sewingHourlyData) > 0): ?>
                    <!-- Chart -->
                    <div class="chart-container">
                        <h3 style="margin-bottom: 15px; color: #4a5568;">Hourly Production Trend</h3>
                        <div class="chart-wrapper">
                            <canvas id="hourlyChart"></canvas>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Hour</th>
                                    <th>Shift</th>
                                    <th>Line Number</th>
                                    <th>Entries</th>
                                    <th>Total Production (Qty)</th>
                                    <th>Avg per Entry</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sewingHourlyData as $row): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($row['production_date'])); ?></td>
                                        <td><?php echo str_pad($row['production_hour'], 2, '0', STR_PAD_LEFT) . ':00'; ?></td>
                                        <td><span class="badge badge-<?php echo strtolower($row['calculated_shift']); ?>"><?php echo $row['calculated_shift']; ?></span></td>
                                        <td><strong>Line <?php echo htmlspecialchars($row['line_no']); ?></strong></td>
                                        <td><?php echo number_format($row['sewing_count']); ?></td>
                                        <td><strong><?php echo number_format($row['total_production'] ?? 0); ?></strong></td>
                                        <td><?php echo $row['total_production'] ? number_format($row['total_production'] / $row['sewing_count'], 0) : '0'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-data">No hourly production data found for the selected filters.</div>
                <?php endif; ?>
            </div>

            <!-- DETAILED VIEW TAB -->
            <div id="detailed-tab" class="tab-content">
            <!-- Roll Received Hourly -->
            <h3 class="section-title">Roll Received - Hourly Summary</h3>
            <?php if (count($rollHourlyData) > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Hour</th>
                                <th>Shift</th>
                                <th>Material Type</th>
                                <th>Roll Count</th>
                                <th>Total Weight (kg)</th>
                                <th>Avg Weight (kg)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rollHourlyData as $row): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($row['production_date'])); ?></td>
                                    <td><?php echo str_pad($row['production_hour'], 2, '0', STR_PAD_LEFT) . ':00'; ?></td>
                                    <td><span class="badge badge-<?php echo strtolower($row['calculated_shift']); ?>"><?php echo $row['calculated_shift']; ?></span></td>
                                    <td><?php echo htmlspecialchars($row['material_type']); ?></td>
                                    <td><strong><?php echo number_format($row['roll_count']); ?></strong></td>
                                    <td><?php echo number_format($row['total_weight'], 2); ?></td>
                                    <td><?php echo number_format($row['total_weight'] / $row['roll_count'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data">No roll received data found for the selected filters.</div>
            <?php endif; ?>

            <!-- CNC Cutting Hourly -->
            <h3 class="section-title">CNC Cutting - Hourly Summary</h3>
            <?php if (count($cncHourlyData) > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Hour</th>
                                <th>Shift</th>
                                <th>CNC Cutting Batch</th>
                                <th>Entry Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cncHourlyData as $row): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($row['production_date'])); ?></td>
                                    <td><?php echo str_pad($row['production_hour'], 2, '0', STR_PAD_LEFT) . ':00'; ?></td>
                                    <td><span class="badge badge-<?php echo strtolower($row['calculated_shift']); ?>"><?php echo $row['calculated_shift']; ?></span></td>
                                    <td><?php echo htmlspecialchars($row['cnc_cutting_batch']); ?></td>
                                    <td><strong><?php echo number_format($row['cnc_count']); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data">No CNC cutting data found for the selected filters.</div>
            <?php endif; ?>

            <!-- Sewing Machine Hourly -->
            <h3 class="section-title">Sewing Machine - Hourly Summary</h3>
            <?php if (count($sewingHourlyData) > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Hour</th>
                                <th>Shift</th>
                                <th>Line Number</th>
                                <th>Entry Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sewingHourlyData as $row): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($row['production_date'])); ?></td>
                                    <td><?php echo str_pad($row['production_hour'], 2, '0', STR_PAD_LEFT) . ':00'; ?></td>
                                    <td><span class="badge badge-<?php echo strtolower($row['calculated_shift']); ?>"><?php echo $row['calculated_shift']; ?></span></td>
                                    <td>Line <?php echo htmlspecialchars($row['line_no']); ?></td>
                                    <td><strong><?php echo number_format($row['sewing_count']); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data">No sewing machine data found for the selected filters.</div>
            <?php endif; ?>

            <!-- Branding Hourly -->
            <h3 class="section-title">Branding - Hourly Summary</h3>
            <?php if (count($brandingHourlyData) > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Hour</th>
                                <th>Shift</th>
                                <th>Entry Count</th>
                                <th>Total Printed</th>
                                <th>Avg per Entry</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($brandingHourlyData as $row): ?>
                                <tr>
                                    <td><?php echo date('M d, Y', strtotime($row['production_date'])); ?></td>
                                    <td><?php echo str_pad($row['production_hour'], 2, '0', STR_PAD_LEFT) . ':00'; ?></td>
                                    <td><span class="badge badge-<?php echo strtolower($row['calculated_shift']); ?>"><?php echo $row['calculated_shift']; ?></span></td>
                                    <td><strong><?php echo number_format($row['branding_count']); ?></strong></td>
                                    <td><?php echo number_format($row['total_printed']); ?></td>
                                    <td><?php echo number_format($row['total_printed'] / $row['branding_count'], 0); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data">No branding data found for the selected filters.</div>
            <?php endif; ?>
            </div>
            <!-- End Detailed Tab -->
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

        // Prepare data for charts
        <?php
        // Daily Chart Data
        $dailyLabels = [];
        $dailyDatasets = [];
        $lineColors = [
            '1' => 'rgb(102, 126, 234)',
            '2' => 'rgb(249, 115, 22)',
            '3' => 'rgb(16, 185, 129)',
            '4' => 'rgb(236, 72, 153)',
            '5' => 'rgb(139, 92, 246)',
            '6' => 'rgb(245, 158, 11)',
            '7' => 'rgb(59, 130, 246)',
            '8' => 'rgb(239, 68, 68)',
            '9' => 'rgb(20, 184, 166)',
            '10' => 'rgb(168, 85, 247)'
        ];
        
        $dailyGrouped = [];
        foreach ($dailyData as $row) {
            $label = date('M d', strtotime($row['production_date'])) . ' (' . $row['calculated_shift'] . ')';
            $line = $row['line_no'];
            if (!isset($dailyGrouped[$line])) {
                $dailyGrouped[$line] = [];
            }
            $dailyGrouped[$line][$label] = $row['total_qty'] ?? 0;
            if (!in_array($label, $dailyLabels)) {
                $dailyLabels[] = $label;
            }
        }
        
        foreach ($dailyGrouped as $line => $data) {
            $dataset = [
                'label' => 'Line ' . $line,
                'data' => [],
                'borderColor' => $lineColors[$line] ?? 'rgb(100, 100, 100)',
                'backgroundColor' => ($lineColors[$line] ?? 'rgb(100, 100, 100)') . '33',
                'tension' => 0.4
            ];
            foreach ($dailyLabels as $label) {
                $dataset['data'][] = $data[$label] ?? 0;
            }
            $dailyDatasets[] = $dataset;
        }
        
        // Shift Chart Data
        $shiftGrouped = [];
        foreach ($shiftData as $row) {
            $shift = $row['calculated_shift'];
            $line = 'Line ' . $row['line_no'];
            if (!isset($shiftGrouped[$shift])) {
                $shiftGrouped[$shift] = [];
            }
            $shiftGrouped[$shift][$line] = $row['total_qty'] ?? 0;
        }
        
        // Hourly Chart Data
        $hourlyGrouped = [];
        $hourlyLabels = [];
        foreach ($sewingHourlyData as $row) {
            $hour = str_pad($row['production_hour'], 2, '0', STR_PAD_LEFT) . ':00';
            $line = $row['line_no'];
            if (!isset($hourlyGrouped[$line])) {
                $hourlyGrouped[$line] = [];
            }
            $hourlyGrouped[$line][$hour] = ($hourlyGrouped[$line][$hour] ?? 0) + ($row['total_production'] ?? 0);
            if (!in_array($hour, $hourlyLabels)) {
                $hourlyLabels[] = $hour;
            }
        }
        sort($hourlyLabels);
        
        $hourlyDatasets = [];
        foreach ($hourlyGrouped as $line => $data) {
            $dataset = [
                'label' => 'Line ' . $line,
                'data' => [],
                'borderColor' => $lineColors[$line] ?? 'rgb(100, 100, 100)',
                'backgroundColor' => ($lineColors[$line] ?? 'rgb(100, 100, 100)') . '33',
                'tension' => 0.4
            ];
            foreach ($hourlyLabels as $hour) {
                $dataset['data'][] = $data[$hour] ?? 0;
            }
            $hourlyDatasets[] = $dataset;
        }
        ?>

        // Daily Chart
        <?php if (count($dailyData) > 0): ?>
        const dailyCtx = document.getElementById('dailyChart').getContext('2d');
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($dailyLabels); ?>,
                datasets: <?php echo json_encode($dailyDatasets); ?>
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Production Quantity'
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Shift Chart
        <?php if (count($shiftData) > 0): ?>
        const shiftCtx = document.getElementById('shiftChart').getContext('2d');
        const shiftLines = [...new Set(<?php echo json_encode(array_column($shiftData, 'line_no')); ?>)];
        new Chart(shiftCtx, {
            type: 'bar',
            data: {
                labels: shiftLines.map(l => 'Line ' + l),
                datasets: [
                    {
                        label: 'Day Shift',
                        data: shiftLines.map(line => {
                            const row = <?php echo json_encode($shiftData); ?>.find(r => r.calculated_shift === 'Day' && r.line_no == line);
                            return row ? parseInt(row.total_qty) || 0 : 0;
                        }),
                        backgroundColor: 'rgba(245, 158, 11, 0.7)',
                        borderColor: 'rgb(245, 158, 11)',
                        borderWidth: 2
                    },
                    {
                        label: 'Night Shift',
                        data: shiftLines.map(line => {
                            const row = <?php echo json_encode($shiftData); ?>.find(r => r.calculated_shift === 'Night' && r.line_no == line);
                            return row ? parseInt(row.total_qty) || 0 : 0;
                        }),
                        backgroundColor: 'rgba(63, 81, 181, 0.7)',
                        borderColor: 'rgb(63, 81, 181)',
                        borderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Production Quantity'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Production Line'
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // Hourly Chart
        <?php if (count($sewingHourlyData) > 0): ?>
        const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
        new Chart(hourlyCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($hourlyLabels); ?>,
                datasets: <?php echo json_encode($hourlyDatasets); ?>
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Production Quantity'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Hour of Day'
                        }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>


