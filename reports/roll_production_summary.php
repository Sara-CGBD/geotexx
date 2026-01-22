<?php
session_start();
require_once '../config/security_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Role-based access control - Admin, Production User, Management can view
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'production_user', 'management', 'agm ops', 'agm operations'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access Sheet Production Reports.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$referenceNumber = $_GET['reference_number'] ?? '';
$materialType = $_GET['material_type'] ?? '';
$shift = $_GET['shift'] ?? '';

// Performance: Set default date range (last 30 days) if no filters provided
$hasDateFilter = !empty($dateFrom) || !empty($dateTo);
if (!$hasDateFilter) {
    $dateTo = date('Y-m-d');
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
}

// Build query for roll_entry table with calculated shift
$query = "SELECT 
    re.*,
    u.username as operator_name,
    p.project_name,
    CASE 
        WHEN HOUR(re.date_time) >= 8 AND HOUR(re.date_time) < 20 THEN 'Day'
        ELSE 'Night'
    END as calculated_shift
FROM roll_entry re
LEFT JOIN users u ON re.operator_id = u.id
LEFT JOIN projects p ON re.project_id = p.id
WHERE re.is_deleted = 0";

$params = [];
$types = '';

if ($dateFrom) {
    $query .= " AND re.date_time >= ?";
    $params[] = $dateFrom . ' 00:00:00';
    $types .= 's';
}
if ($dateTo) {
    $query .= " AND re.date_time <= ?";
    $params[] = $dateTo . ' 23:59:59';
    $types .= 's';
}
if ($referenceNumber) {
    $query .= " AND re.reference_number LIKE ?";
    $params[] = "%$referenceNumber%";
    $types .= 's';
}
if ($materialType) {
    $query .= " AND re.material_type = ?";
    $params[] = $materialType;
    $types .= 's';
}

// Add shift filter using HAVING clause
if ($shift) {
    $query .= " HAVING calculated_shift = ?";
    $params[] = $shift;
    $types .= 's';
}

$query .= " ORDER BY re.date_time DESC LIMIT 1000";

// Execute query
$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$productions = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate statistics
$totalRolls = count($productions);
$totalWeight = array_sum(array_column($productions, 'total_weight'));

// Group by shift
$shiftStats = [];
foreach ($productions as $prod) {
    $shiftValue = $prod['calculated_shift'];
    if (!isset($shiftStats[$shiftValue])) {
        $shiftStats[$shiftValue] = ['count' => 0, 'weight' => 0];
    }
    $shiftStats[$shiftValue]['count']++;
    $shiftStats[$shiftValue]['weight'] += $prod['total_weight'];
}

// Group by material type
$materialStats = [];
foreach ($productions as $prod) {
    $material = $prod['material_type'] ?? 'Unknown';
    if (!isset($materialStats[$material])) {
        $materialStats[$material] = ['count' => 0, 'weight' => 0];
    }
    $materialStats[$material]['count']++;
    $materialStats[$material]['weight'] += $prod['total_weight'];
}

// Group by project
$projectStats = [];
foreach ($productions as $prod) {
    $project = $prod['project_name'] ?? 'Unassigned';
    if (!isset($projectStats[$project])) {
        $projectStats[$project] = ['count' => 0, 'weight' => 0];
    }
    $projectStats[$project]['count']++;
    $projectStats[$project]['weight'] += $prod['total_weight'];
}

// Group by date
$dateStats = [];
foreach ($productions as $prod) {
    $dateTime = $prod['date_time'];
    if (!empty($dateTime) && $dateTime != '0000-00-00 00:00:00' && strtotime($dateTime)) {
        $date = date('Y-m-d', strtotime($dateTime));
        if (!isset($dateStats[$date])) {
            $dateStats[$date] = ['count' => 0, 'weight' => 0];
        }
        $dateStats[$date]['count']++;
        $dateStats[$date]['weight'] += $prod['total_weight'];
    }
}

// Group by hour
$hourStats = [];
foreach ($productions as $prod) {
    $dateTime = $prod['date_time'];
    if (!empty($dateTime) && $dateTime != '0000-00-00 00:00:00' && strtotime($dateTime)) {
        $hour = date('Y-m-d H:00', strtotime($dateTime)); // Group by hour
        $hourDisplay = date('M d, Y h:00 A', strtotime($dateTime));
        if (!isset($hourStats[$hour])) {
            $hourStats[$hour] = ['count' => 0, 'weight' => 0, 'display' => $hourDisplay];
        }
        $hourStats[$hour]['count']++;
        $hourStats[$hour]['weight'] += $prod['total_weight'];
    }
}

// Get unique material types and reference numbers for filters
$materialsResult = $conn->query("SELECT DISTINCT material_type FROM roll_entry WHERE is_deleted = 0 AND material_type IS NOT NULL ORDER BY material_type");
$materials = $materialsResult ? $materialsResult->fetch_all(MYSQLI_ASSOC) : [];

$refsResult = $conn->query("SELECT DISTINCT reference_number FROM roll_entry WHERE is_deleted = 0 AND reference_number IS NOT NULL ORDER BY reference_number DESC LIMIT 50");
$referenceNumbers = $refsResult ? $refsResult->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sheet Production Summary Report</title>
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
        
        /* Roll Size column alignment - target by position */
        table th:nth-child(8), 
        table td:nth-child(8) { 
            text-align: center !important; 
            white-space: nowrap;
            vertical-align: middle;
        }
        
        /* More specific selector for production table */
        #productionTable th:nth-child(8), 
        #productionTable td:nth-child(8) {
            text-align: center !important;
            white-space: nowrap;
            vertical-align: middle;
        }
        
        /* All tables - Roll Size column */
        .table-wrapper table th:nth-child(8),
        .table-wrapper table td:nth-child(8),
        .table-container table th:nth-child(8),
        .table-container table td:nth-child(8) {
            text-align: center !important;
            white-space: nowrap;
        }
        
        .badge { padding: 4px 10px; border-radius: 4px; font-size: 0.85em; font-weight: 600; }
        .badge-cnc { background: #e3f2fd; color: #1565c0; }
        .badge-other { background: #fff3e0; color: #e65100; }
        
        /* Export Button */
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; }
        .export-btn:hover { background: #229954; }
        
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
            min-width: 1200px;
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
    <h1><i class="fas fa-industry"></i> Sheet Production Summary Report</h1>
    <p class="subtitle">Comprehensive overview of sheet/roll production activities</p>
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($totalRolls); ?></div>
            <div class="stat-label">Total Rolls Produced</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value"><?php echo number_format($totalWeight, 2); ?> kg</div>
            <div class="stat-label">Total Weight</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-value"><?php echo count($materialStats); ?></div>
            <div class="stat-label">Material Types</div>
        </div>
        <div class="stat-card blue">
            <div class="stat-value"><?php echo count($projectStats); ?></div>
            <div class="stat-label">Projects</div>
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
                    <label for="reference_number"><i class="fas fa-barcode"></i> Reference Number</label>
                    <select id="reference_number" name="reference_number">
                        <option value="">All References</option>
                        <?php foreach ($referenceNumbers as $ref): ?>
                            <option value="<?php echo htmlspecialchars($ref['reference_number']); ?>" 
                                <?php echo $referenceNumber == $ref['reference_number'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ref['reference_number']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="material_type"><i class="fas fa-layer-group"></i> Material Type</label>
                    <select id="material_type" name="material_type">
                        <option value="">All Materials</option>
                        <?php foreach ($materials as $mat): ?>
                            <option value="<?php echo htmlspecialchars($mat['material_type']); ?>" 
                                <?php echo $materialType == $mat['material_type'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($mat['material_type']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="shift"><i class="fas fa-clock"></i> Shift</label>
                    <select id="shift" name="shift">
                        <option value="">All Shifts</option>
                        <option value="Day" <?php echo $shift == 'Day' ? 'selected' : ''; ?>>Day Shift (8 AM - 7:59 PM)</option>
                        <option value="Night" <?php echo $shift == 'Night' ? 'selected' : ''; ?>>Night Shift (8 PM - 7:59 AM)</option>
                    </select>
                </div>
                <div class="filter-group" style="display: flex; gap: 10px; align-items: flex-end;">
                    <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Filter</button>
                    <a href="roll_production_summary.php" class="reset-btn" style="text-decoration: none; display: inline-block; line-height: 1.5;"><i class="fas fa-redo"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
    
    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print Report</button>
    <button onclick="exportToCSV()" class="export-btn" style="background: #e67e22; margin-left: 10px;"><i class="fas fa-file-csv"></i> Export to CSV</button>
    
    <?php if ($totalRolls > 0): ?>
        
        <!-- Summary by Shift -->
        <?php if (count($shiftStats) > 0): ?>
        <div class="section">
            <h2 class="section-title"><i class="fas fa-clock"></i> Summary by Shift</h2>
            <table>
                <thead>
                    <tr>
                        <th>Shift</th>
                        <th>Count</th>
                        <th>Total Quantity</th>
                        <th>Percentage</th>
                        <th>Average per Entry</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shiftStats as $shiftName => $stats): ?>
                        <tr>
                            <td>
                                <strong>
                                    <i class="fas fa-<?php echo $shiftName == 'Day' ? 'sun' : 'moon'; ?>"></i>
                                    <?php echo htmlspecialchars($shiftName); ?> Shift
                                </strong>
                            </td>
                            <td><?php echo number_format($stats['count']); ?></td>
                            <td><?php echo number_format($stats['weight'], 2); ?> kg</td>
                            <td><?php echo number_format(($stats['weight'] / $totalWeight) * 100, 1); ?>%</td>
                            <td><?php echo number_format($stats['weight'] / $stats['count'], 2); ?> kg</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Summary by Material Type -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-layer-group"></i> Summary by Material Type</h2>
            <table>
                <thead>
                    <tr>
                        <th>Material Type</th>
                        <th>Count</th>
                        <th>Total Weight</th>
                        <th>Percentage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($materialStats as $material => $stats): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($material); ?></strong></td>
                            <td><?php echo number_format($stats['count']); ?></td>
                            <td><?php echo number_format($stats['weight'], 2); ?> kg</td>
                            <td><?php echo $totalWeight > 0 ? number_format(($stats['weight'] / $totalWeight) * 100, 1) : 0; ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Summary by Project -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-project-diagram"></i> Summary by Project</h2>
            <table>
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Count</th>
                        <th>Total Weight</th>
                        <th>Percentage</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // Sort by weight descending
                    uasort($projectStats, function($a, $b) { return $b['weight'] <=> $a['weight']; });
                    foreach ($projectStats as $project => $stats): 
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($project); ?></strong></td>
                            <td><?php echo number_format($stats['count']); ?></td>
                            <td><?php echo number_format($stats['weight'], 2); ?> kg</td>
                            <td><?php echo $totalWeight > 0 ? number_format(($stats['weight'] / $totalWeight) * 100, 1) : 0; ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Hourly Production Summary -->
        <?php if (count($hourStats) > 0): ?>
        <div class="section">
            <h2 class="section-title"><i class="fas fa-clock"></i> Hourly Production Summary</h2>
            <table>
                <thead>
                    <tr>
                        <th>Hour</th>
                        <th>Rolls Produced</th>
                        <th>Total Weight</th>
                        <th>Average Weight</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    krsort($hourStats); // Sort by hour descending (most recent first)
                    foreach ($hourStats as $hour => $stats): 
                    ?>
                        <tr>
                            <td><strong><?php echo $stats['display']; ?></strong></td>
                            <td><?php echo number_format($stats['count']); ?></td>
                            <td><?php echo number_format($stats['weight'], 2); ?> kg</td>
                            <td><?php echo number_format($stats['weight'] / $stats['count'], 2); ?> kg</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- Daily Production Summary -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-calendar-day"></i> Daily Production Summary</h2>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Entries</th>
                        <th>Total Quantity</th>
                        <th>Average Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    krsort($dateStats); // Sort by date descending
                    foreach ($dateStats as $date => $stats): 
                    ?>
                        <tr>
                            <td><strong><?php 
                                if (!empty($date) && $date != '0000-00-00' && strtotime($date)) {
                                    echo date('M d, Y', strtotime($date));
                                } else {
                                    echo 'N/A';
                                }
                            ?></strong></td>
                            <td><?php echo number_format($stats['count']); ?></td>
                            <td><?php echo number_format($stats['weight'], 2); ?> kg</td>
                            <td><?php echo number_format($stats['weight'] / $stats['count'], 2); ?> kg</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Detailed Production List -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-list"></i> Detailed Production List</h2>
            <div class="table-wrapper">
                <table id="productionTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Entry ID</th>
                            <th>Date & Time</th>
                            <th>Shift</th>
                            <th>Reference Number</th>
                            <th>Project</th>
                            <th>Material Type</th>
                            <th>Roll Size</th>
                            <th>Weight (kg)</th>
                            <th>Operator</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = 1;
                        foreach ($productions as $prod): 
                        ?>
                            <tr>
                                <td><?php echo $counter++; ?></td>
                                <td><strong><?php echo htmlspecialchars($prod['entry_id'] ?? 'N/A'); ?></strong></td>
                                <td><?php 
                                    $dateTime = $prod['date_time'];
                                    if (!empty($dateTime) && $dateTime != '0000-00-00 00:00:00' && strtotime($dateTime)) {
                                        echo date('M d, Y g:i A', strtotime($dateTime));
                                    } else {
                                        echo 'N/A';
                                    }
                                ?></td>
                                <td>
                                    <strong>
                                        <i class="fas fa-<?php echo $prod['calculated_shift'] == 'Day' ? 'sun' : 'moon'; ?>" 
                                           style="color: <?php echo $prod['calculated_shift'] == 'Day' ? '#f39c12' : '#34495e'; ?>;"></i>
                                        <?php echo $prod['calculated_shift']; ?>
                                    </strong>
                                </td>
                                <td><strong><?php echo htmlspecialchars($prod['reference_number'] ?? 'N/A'); ?></strong></td>
                                <td><?php echo htmlspecialchars($prod['project_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($prod['material_type'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($prod['roll_size'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format($prod['total_weight'], 2); ?></td>
                                <td><?php echo htmlspecialchars($prod['operator_name'] ?? 'Unknown'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-inbox" style="font-size: 3em; margin-bottom: 15px; opacity: 0.3;"></i>
            <p>No production records found for the selected filters.</p>
    </div>
    <?php endif; ?>
  </div>

<script>
// Align Roll Size columns in all tables
function alignRollSizeColumns() {
    const tables = document.querySelectorAll('table');
    tables.forEach(table => {
        const headers = table.querySelectorAll('thead th');
        headers.forEach((th, index) => {
            if (th.textContent.trim().toLowerCase().includes('roll size')) {
                const colIndex = index + 1; // nth-child is 1-based
                // Align header
                th.style.textAlign = 'center';
                th.style.whiteSpace = 'nowrap';
                // Align all cells in this column
                const rows = table.querySelectorAll('tbody tr');
                rows.forEach(row => {
                    const cell = row.querySelector(`td:nth-child(${colIndex})`);
                    if (cell) {
                        cell.style.textAlign = 'center';
                        cell.style.whiteSpace = 'nowrap';
                        cell.style.verticalAlign = 'middle';
                    }
                });
            }
        });
    });
}

// Run alignment on page load
document.addEventListener('DOMContentLoaded', function() {
    alignRollSizeColumns();
});

// Re-run alignment when tabs are switched (if applicable)
if (typeof switchTab !== 'undefined') {
    const originalSwitchTab = window.switchTab;
    window.switchTab = function(tabName, buttonElement) {
        if (originalSwitchTab) {
            originalSwitchTab(tabName, buttonElement);
        }
        setTimeout(alignRollSizeColumns, 100);
    };
}

function exportToCSV() {
    const table = document.getElementById('productionTable');
    let csv = [];
    
    // Headers
    const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent);
    csv.push(headers.join(','));
    
    // Rows
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cols = Array.from(row.querySelectorAll('td')).map(td => {
            let text = td.textContent.trim();
            // Escape commas and quotes
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
    link.setAttribute('download', 'roll_production_summary_' + new Date().toISOString().slice(0,10) + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>

