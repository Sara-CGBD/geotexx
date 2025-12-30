<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit;
}

// Check module access
$allowedRoles = ['admin', 'production'];
$hasAccess = false;
if (in_array(strtolower($_SESSION['role']), $allowedRoles)) {
    $hasAccess = true;
}

if (!$hasAccess) {
    die("<div style='padding:20px; text-align:center;'>
        <h2>Access Denied</h2>
        <p>You do not have permission to access the Scrap/Waste module.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background:rgb(219, 77, 52); color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Get current shift info
$currentDate = date('Y-m-d');
$currentHour = (int)date('H');
$currentShift = ($currentHour >= 8 && $currentHour <= 19) ? 'Day' : 'Night';

// Fetch all scrap entries from current shift
$entries = [];
$query = "SELECT s.* 
          FROM scrap s
          WHERE DATE(s.date_time) = ? AND s.shift = ?
          ORDER BY s.date_time DESC";
$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param('ss', $currentDate, $currentShift);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $entries[] = $row;
    }
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scrap Entries - Current Shift</title>
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
        
        /* Tables */
        .section { margin-bottom: 40px; }
        .section-title { font-size: 1.3em; color: #34495e; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 3px solid #3498db; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; table-layout: fixed; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ecf0f1; word-wrap: break-word; overflow: hidden; text-overflow: ellipsis; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; }
        tr:hover { background: #f8f9fa; }
        tr:nth-child(even) { background: #f9f9f9; }
        
        /* Column widths */
        th:nth-child(1), td:nth-child(1) { width: 10%; } /* Scrap ID */
        th:nth-child(2), td:nth-child(2) { width: 12%; } /* Date & Time */
        th:nth-child(3), td:nth-child(3) { width: 12%; } /* Category */
        th:nth-child(4), td:nth-child(4) { width: 15%; } /* Reference/Batch */
        th:nth-child(5), td:nth-child(5) { width: 10%; } /* Scrap Product */
        th:nth-child(6), td:nth-child(6) { width: 10%; } /* Scrap Type */
        th:nth-child(7), td:nth-child(7) { width: 10%; } /* Quantity */
        th:nth-child(8), td:nth-child(8) { width: 11%; } /* Action */
        
        .badge { padding: 4px 10px; border-radius: 4px; font-size: 0.85em; font-weight: 600; }
        .badge-sheet { background: #d4edda; color: #155724; }
        .badge-swing { background: #d1ecf1; color: #0c5460; }
        .badge-raw { background: #fff3cd; color: #856404; }
        .badge-process { background: #f8d7da; color: #721c24; }
        .badge-unseen { background: #e2e3e5; color: #383d41; }
        .badge-yarn { background: #cce5ff; color: #004085; }
        
        /* Buttons */
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; margin-right: 10px; transition: all 0.3s; }
        .btn-edit { background: #27ae60; color: white; padding: 8px 16px; }
        .btn-edit:hover { background: #229954; }
        .btn-back { background: #95a5a6; color: white; }
        .btn-back:hover { background: #7f8c8d; }
        .btn-new { background: #3498db; color: white; }
        .btn-new:hover { background: #2980b9; }
        
        .action-btns { margin-bottom: 20px; }
        
        .no-data { text-align: center; padding: 60px; color: #95a5a6; font-size: 1.1em; }
        
        .shift-badge { display: inline-block; background: #3498db; color: white; padding: 8px 16px; border-radius: 5px; font-weight: 600; margin-bottom: 20px; }
        
        .alert { padding: 15px 20px; border-radius: 5px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid: #f5c6cb; }
        
        @media print {
            .action-btns, .btn { display: none; }
            body { background: white; padding: 0; }
            .container { box-shadow: none; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-recycle"></i> Current Shift Scrap Entries</h1>
    <p class="subtitle">
        <span class="shift-badge">
            📅 <?php echo date('M d, Y'); ?> | 
            <?php echo $currentShift === 'Day' ? '☀️ Day Shift' : '🌙 Night Shift'; ?>
        </span>
    </p>
    
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            ✅ <?php echo htmlspecialchars($_GET['success']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-error">
            ❌ <?php echo htmlspecialchars($_GET['error']); ?>
        </div>
    <?php endif; ?>
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo count($entries); ?></div>
            <div class="stat-label">Total Entries (This Shift)</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value"><?php 
                $sheetCount = count(array_filter($entries, function($e) { 
                    return $e['scrap_category'] === 'Sheet Production Scrap'; 
                }));
                echo $sheetCount;
            ?></div>
            <div class="stat-label">Sheet Production Scrap</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-value"><?php 
                $swingCount = count(array_filter($entries, function($e) { 
                    return $e['scrap_category'] === 'Swing Scrap'; 
                }));
                echo $swingCount;
            ?></div>
            <div class="stat-label">Swing Production Scrap</div>
        </div>
        <div class="stat-card blue">
            <div class="stat-value"><?php 
                $totalQty = array_sum(array_column($entries, 'qty'));
                echo number_format($totalQty, 2);
            ?> kg</div>
            <div class="stat-label">Total Quantity</div>
        </div>
    </div>
    
    <div class="action-btns">
        <a href="scrap_entry.php" class="btn btn-new">
            <i class="fas fa-plus"></i> New Scrap Entry
        </a>
        <a href="../index.php" class="btn btn-back">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
    
    <?php if (empty($entries)): ?>
        <div class="no-data">
            <i class="fas fa-inbox" style="font-size: 3em; margin-bottom: 20px; opacity: 0.3;"></i>
            <p>No scrap entries have been recorded for the current shift.</p>
        </div>
    <?php else: ?>
        <div class="section">
            <h2 class="section-title"><i class="fas fa-list"></i> Scrap Entry Details</h2>
            <table>
                <thead>
                    <tr>
                        <th>Scrap ID</th>
                        <th>Date & Time</th>
                        <th>Category</th>
                        <th>Reference/Batch</th>
                        <th>Scrap Product</th>
                        <th>Scrap Type</th>
                        <th>Quantity (kg)</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($entry['scrap_id']); ?></strong></td>
                            <td><?php echo date('M d, Y h:i A', strtotime($entry['date_time'])); ?></td>
                            <td>
                                <span class="badge <?php echo $entry['scrap_category'] === 'Sheet Production Scrap' ? 'badge-sheet' : 'badge-swing'; ?>">
                                    <?php echo htmlspecialchars($entry['scrap_category']); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                if ($entry['scrap_category'] === 'Sheet Production Scrap') {
                                    echo htmlspecialchars($entry['reference_number']);
                                } else {
                                    echo htmlspecialchars($entry['cutting_batch']);
                                }
                                ?>
                            </td>
                            <td>
                                <span class="badge badge-raw">
                                    <?php echo htmlspecialchars($entry['scrap_product']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php 
                                    if ($entry['scrap_type'] === 'Process') echo 'badge-process';
                                    elseif ($entry['scrap_type'] === 'Unseen') echo 'badge-unseen';
                                    elseif ($entry['scrap_type'] === 'Yarn') echo 'badge-yarn';
                                    else echo 'badge-sheet';
                                ?>">
                                    <?php echo htmlspecialchars($entry['scrap_type']); ?>
                                </span>
                            </td>
                            <td><strong><?php echo number_format($entry['qty'], 2); ?> kg</strong></td>
                            <td>
                                <a href="scrap_entry.php?id=<?php echo $entry['id']; ?>" class="btn btn-edit">
                                    <i class="fas fa-edit"></i> Edit
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
    // Print functionality
    function printReport() {
        window.print();
    }
    
    // Add print button shortcut
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'p') {
            e.preventDefault();
            printReport();
        }
    });
</script>

</body>
</html>

