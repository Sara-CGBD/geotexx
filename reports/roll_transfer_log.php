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
$allowed_roles = ['admin', 'production_user', 'management', 'agm ops', 'agm operations', 'delivery_user'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access Roll Transfer Log.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$fromLocation = $_GET['from_location'] ?? '';
$toLocation = $_GET['to_location'] ?? '';
$driverName = $_GET['driver_name'] ?? '';

// Build query
$query = "SELECT 
    rt.*,
    COALESCE(NULLIF(rt.driver_name, ''), 'N/A') as driver_full_name
FROM roll_transfer rt
WHERE 1=1";

$params = [];
$types = '';

if ($dateFrom) {
    $query .= " AND DATE(rt.date_time) >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo) {
    $query .= " AND DATE(rt.date_time) <= ?";
    $params[] = $dateTo;
    $types .= 's';
}
if ($fromLocation) {
    $query .= " AND rt.from_location LIKE ?";
    $params[] = "%$fromLocation%";
    $types .= 's';
}
if ($toLocation) {
    $query .= " AND rt.to_location LIKE ?";
    $params[] = "%$toLocation%";
    $types .= 's';
}
if ($driverName) {
    $query .= " AND rt.driver_name LIKE ?";
    $params[] = "%$driverName%";
    $types .= 's';
}

$query .= " ORDER BY rt.date_time DESC";

// Execute query
$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$allTransfers = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate statistics from original data (before grouping)
$totalTransfers = count($allTransfers);
$totalWeight = array_sum(array_column($allTransfers, 'amount_kg'));

// Group transfers by transfer_id and base reference to identify bundles
$groupedTransfers = [];
$processedKeys = []; // Track processed (transfer_id, base_reference) combinations

foreach ($allTransfers as $transfer) {
    $transferId = $transfer['transfer_id'];
    $refNum = trim($transfer['reference_number'] ?? '');
    
    // Check if this reference matches bundle pattern (ends with -N where N is a number)
    if (preg_match('/^(.+)-(\d+)$/', $refNum, $matches)) {
        $baseRef = $matches[1];
        $processKey = $transferId . '|' . $baseRef;
        
        // Check if this (transfer_id, base_reference) combination was already processed
        if (in_array($processKey, $processedKeys)) {
            continue;
        }
        
        // Find all transfers with same transfer_id and base reference pattern
        $bundleRefs = [];
        $bundleTransfers = [];
        foreach ($allTransfers as $t) {
            $tRefNum = trim($t['reference_number'] ?? '');
            if ($t['transfer_id'] == $transferId && preg_match('/^' . preg_quote($baseRef, '/') . '-(\d+)$/', $tRefNum, $m)) {
                $bundleRefs[] = $tRefNum;
                $bundleTransfers[] = $t;
            }
        }
        
        // If we found 2 or more references with the same base and transfer_id, it's a bundle
        if (count($bundleRefs) >= 2) {
            // Sort by roll number
            usort($bundleRefs, function($a, $b) {
                preg_match('/-(\d+)$/', $a, $ma);
                preg_match('/-(\d+)$/', $b, $mb);
                return intval($ma[1] ?? 0) - intval($mb[1] ?? 0);
            });
            
            // Extract roll numbers to create range display (e.g., "1.1-1.4" for 1.1-1, 1.1-2, 1.1-3, 1.1-4)
            $firstRollNum = '';
            $lastRollNum = '';
            preg_match('/-(\d+)$/', $bundleRefs[0], $firstMatch);
            preg_match('/-(\d+)$/', $bundleRefs[count($bundleRefs) - 1], $lastMatch);
            $firstRollNum = $firstMatch[1] ?? '';
            $lastRollNum = $lastMatch[1] ?? '';
            
            // Create bundle display: baseRef-firstRollNum.lastRollNum (e.g., "1.1-1.4")
            if ($firstRollNum && $lastRollNum) {
                $refDisplay = $baseRef . '-' . $firstRollNum . '.' . $lastRollNum;
            } else {
                $refDisplay = $baseRef . ' (Bundle: ' . implode(', ', $bundleRefs) . ')';
            }
            
            // Create bundle entry
            $firstTransfer = $bundleTransfers[0];
            $totalWeight = array_sum(array_column($bundleTransfers, 'amount_kg'));
            
            $groupedTransfers[] = [
                'transfer_id' => $firstTransfer['transfer_id'],
                'trip' => $firstTransfer['trip'] ?? '1',
                'reference_number' => $refDisplay,
                'is_bundle' => true,
                'bundle_refs' => $bundleRefs,
                'date_time' => $firstTransfer['date_time'],
                'from_location' => $firstTransfer['from_location'],
                'to_location' => $firstTransfer['to_location'],
                'amount_kg' => $totalWeight,
                'driver_full_name' => $firstTransfer['driver_full_name']
            ];
            
            // Mark this (transfer_id, base_reference) combination as processed
            $processedKeys[] = $processKey;
        } else {
            // Single reference, not part of a bundle
            $groupedTransfers[] = $transfer;
        }
    } else {
        // Reference doesn't match bundle pattern - add as single entry
        $groupedTransfers[] = $transfer;
    }
}

$transfers = $groupedTransfers;

// Group by route (from -> to)
$routeStats = [];
foreach ($transfers as $transfer) {
    $route = $transfer['from_location'] . ' → ' . $transfer['to_location'];
    if (!isset($routeStats[$route])) {
        $routeStats[$route] = ['count' => 0, 'weight' => 0];
    }
    $routeStats[$route]['count']++;
    $routeStats[$route]['weight'] += $transfer['amount_kg'];
}

// Group by driver
$driverStats = [];
foreach ($transfers as $transfer) {
    $driver = $transfer['driver_full_name'];
    if (!isset($driverStats[$driver])) {
        $driverStats[$driver] = ['count' => 0, 'weight' => 0];
    }
    $driverStats[$driver]['count']++;
    $driverStats[$driver]['weight'] += $transfer['amount_kg'];
}

// Group by from location
$fromLocationStats = [];
foreach ($transfers as $transfer) {
    $from = $transfer['from_location'];
    if (!isset($fromLocationStats[$from])) {
        $fromLocationStats[$from] = ['count' => 0, 'weight' => 0];
    }
    $fromLocationStats[$from]['count']++;
    $fromLocationStats[$from]['weight'] += $transfer['amount_kg'];
}

// Group by to location
$toLocationStats = [];
foreach ($transfers as $transfer) {
    $to = $transfer['to_location'];
    if (!isset($toLocationStats[$to])) {
        $toLocationStats[$to] = ['count' => 0, 'weight' => 0];
    }
    $toLocationStats[$to]['count']++;
    $toLocationStats[$to]['weight'] += $transfer['amount_kg'];
}

// Get unique values for filters
$fromLocationsResult = $conn->query("SELECT DISTINCT from_location FROM roll_transfer WHERE from_location IS NOT NULL ORDER BY from_location");
$fromLocations = $fromLocationsResult ? $fromLocationsResult->fetch_all(MYSQLI_ASSOC) : [];

$toLocationsResult = $conn->query("SELECT DISTINCT to_location FROM roll_transfer WHERE to_location IS NOT NULL ORDER BY to_location");
$toLocations = $toLocationsResult ? $toLocationsResult->fetch_all(MYSQLI_ASSOC) : [];

$driversResult = $conn->query("SELECT DISTINCT driver_name FROM roll_transfer WHERE driver_name IS NOT NULL AND driver_name != '' ORDER BY driver_name");
$drivers = $driversResult ? $driversResult->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roll Transfer Log Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
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
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 0.9em; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; font-size: 0.85em; }
        tr:hover { background: #f8f9fa; }
        tr:nth-child(even) { background: #f9f9f9; }
        
        .badge { padding: 4px 10px; border-radius: 4px; font-size: 0.85em; font-weight: 600; white-space: nowrap; }
        .badge-from { background: #e3f2fd; color: #1565c0; }
        .badge-to { background: #e8f5e9; color: #2e7d32; }
        .badge-bundle { background: #fff3cd; color: #856404; font-weight: 600; }
        
        .route-arrow { color: #3498db; font-weight: bold; margin: 0 5px; }
        
        tr.bundle-row { background: #fffbf0 !important; }
        tr.bundle-row:hover { background: #fff8e1 !important; }
        
        /* Export Buttons */
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
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
            * { 
                box-sizing: border-box;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            body { 
                background: white !important; 
                padding: 5px !important; 
                margin: 0 !important;
                font-size: 9px !important;
                overflow: visible !important;
            }
            .container { 
                box-shadow: none !important; 
                padding: 5px !important;
                margin: 0 !important;
                max-width: 100% !important;
                width: 100% !important;
            }
            .filters, .export-btn { display: none !important; }
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
                page-break-inside: auto;
                border-collapse: collapse !important;
                margin-bottom: 8px !important;
            }
            th, td { 
                padding: 3px 2px !important; 
                font-size: 7px !important;
                line-height: 1.1 !important;
                border: 1px solid #ddd !important;
            }
            th { 
                font-size: 8px !important; 
                font-weight: 600 !important;
            }
            .table-wrapper { 
                overflow: visible !important; 
                page-break-inside: auto;
            }
            .stats-grid { 
                grid-template-columns: repeat(4, 1fr) !important;
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
    <h1><i class="fas fa-truck-moving"></i> Roll Transfer Log Report</h1>
    <p class="subtitle">Track movement of rolls between stages and locations</p>
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($totalTransfers); ?></div>
            <div class="stat-label">Total Transfers</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value"><?php echo number_format($totalWeight, 2); ?> kg</div>
            <div class="stat-label">Total Weight Transferred</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-value"><?php echo count($routeStats); ?></div>
            <div class="stat-label">Unique Routes</div>
        </div>
        <div class="stat-card blue">
            <div class="stat-value"><?php echo count($driverStats); ?></div>
            <div class="stat-label">Active Drivers</div>
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
                    <label for="from_location"><i class="fas fa-map-marker-alt"></i> From Location</label>
                    <select id="from_location" name="from_location">
                        <option value="">All Locations</option>
                        <?php foreach ($fromLocations as $loc): ?>
                            <option value="<?php echo htmlspecialchars($loc['from_location']); ?>" 
                                <?php echo $fromLocation == $loc['from_location'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc['from_location']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="to_location"><i class="fas fa-map-pin"></i> To Location</label>
                    <select id="to_location" name="to_location">
                        <option value="">All Locations</option>
                        <?php foreach ($toLocations as $loc): ?>
                            <option value="<?php echo htmlspecialchars($loc['to_location']); ?>" 
                                <?php echo $toLocation == $loc['to_location'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc['to_location']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="driver_name"><i class="fas fa-user"></i> Driver</label>
                    <select id="driver_name" name="driver_name">
                        <option value="">All Drivers</option>
                        <?php foreach ($drivers as $driver): ?>
                            <option value="<?php echo htmlspecialchars($driver['driver_name']); ?>" 
                                <?php echo $driverName == $driver['driver_name'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($driver['driver_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group" style="display: flex; gap: 10px; align-items: flex-end;">
                    <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Filter</button>
                    <a href="roll_transfer_log.php" class="reset-btn" style="text-decoration: none; display: inline-block; line-height: 1.5;"><i class="fas fa-redo"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>
    
    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print Report</button>
    <button onclick="exportToCSV()" class="export-btn" style="background: #e67e22;"><i class="fas fa-file-csv"></i> Export to CSV</button>
    
    <?php if ($totalTransfers > 0): ?>
        
        <!-- Summary by Route -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-route"></i> Summary by Route</h2>
            <table>
                <thead>
                    <tr>
                        <th>Route (From → To)</th>
                        <th>Transfers</th>
                        <th>Total Weight (kg)</th>
                        <th>Average Weight (kg)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    uasort($routeStats, function($a, $b) { return $b['count'] <=> $a['count']; });
                    foreach ($routeStats as $route => $stats): 
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($route); ?></strong></td>
                            <td><?php echo number_format($stats['count']); ?></td>
                            <td><?php echo number_format($stats['weight'], 2); ?></td>
                            <td><?php echo number_format($stats['weight'] / $stats['count'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Summary by Driver -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-users"></i> Summary by Driver</h2>
            <table>
                <thead>
                    <tr>
                        <th>Driver Name</th>
                        <th>Transfers</th>
                        <th>Total Weight (kg)</th>
                        <th>Average Weight (kg)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    uasort($driverStats, function($a, $b) { return $b['weight'] <=> $a['weight']; });
                    foreach ($driverStats as $driver => $stats): 
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($driver); ?></strong></td>
                            <td><?php echo number_format($stats['count']); ?></td>
                            <td><?php echo number_format($stats['weight'], 2); ?></td>
                            <td><?php echo number_format($stats['weight'] / $stats['count'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Summary by From Location -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-map-marker-alt"></i> Summary by Origin Location</h2>
            <table>
                <thead>
                    <tr>
                        <th>From Location</th>
                        <th>Transfers Out</th>
                        <th>Total Weight (kg)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    uasort($fromLocationStats, function($a, $b) { return $b['weight'] <=> $a['weight']; });
                    foreach ($fromLocationStats as $from => $stats): 
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($from); ?></strong></td>
                            <td><?php echo number_format($stats['count']); ?></td>
                            <td><?php echo number_format($stats['weight'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Summary by To Location -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-map-pin"></i> Summary by Destination Location</h2>
            <table>
                <thead>
                    <tr>
                        <th>To Location</th>
                        <th>Transfers In</th>
                        <th>Total Weight (kg)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    uasort($toLocationStats, function($a, $b) { return $b['weight'] <=> $a['weight']; });
                    foreach ($toLocationStats as $to => $stats): 
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($to); ?></strong></td>
                            <td><?php echo number_format($stats['count']); ?></td>
                            <td><?php echo number_format($stats['weight'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Detailed Transfer Log -->
        <div class="section">
            <h2 class="section-title"><i class="fas fa-list"></i> Detailed Transfer Log</h2>
            <div class="table-wrapper">
                <table id="transferTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Transfer ID</th>
                            <th>Trip</th>
                            <th>Reference Number</th>
                            <th>Date & Time</th>
                            <th>From Location</th>
                            <th>To Location</th>
                            <th>Weight (kg)</th>
                            <th>Driver</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = 1;
                        foreach ($transfers as $transfer): 
                            $isBundle = isset($transfer['is_bundle']) && $transfer['is_bundle'];
                        ?>
                            <tr<?php echo $isBundle ? ' class="bundle-row"' : ''; ?>>
                                <td><?php echo $counter++; ?></td>
                                <td><strong><?php echo htmlspecialchars($transfer['transfer_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($transfer['trip'] ?? '1'); ?></td>
                                <td>
                                    <?php if ($isBundle): ?>
                                        <span class="badge badge-bundle">
                                            <i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($transfer['reference_number'] ?? 'N/A'); ?>
                                        </span>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($transfer['reference_number'] ?? 'N/A'); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M d, Y g:i A', strtotime($transfer['date_time'])); ?></td>
                                <td>
                                    <span class="badge badge-from">
                                        <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($transfer['from_location']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-to">
                                        <i class="fas fa-map-pin"></i> <?php echo htmlspecialchars($transfer['to_location']); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($transfer['amount_kg'], 2); ?></td>
                                <td><?php echo htmlspecialchars($transfer['driver_full_name']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-inbox" style="font-size: 3em; margin-bottom: 15px; opacity: 0.3;"></i>
            <p>No roll transfer records found for the selected filters.</p>
        </div>
    <?php endif; ?>
</div>

<script>
function exportToCSV() {
    const table = document.getElementById('transferTable');
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
    link.setAttribute('download', 'roll_transfer_log_' + new Date().toISOString().slice(0,10) + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>


