<?php
session_start();
require_once '../config/security_config.php';
require_once '../config/EnterpriseConfig.php';

// Check if user is admin
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if (!in_array($user_role, ['admin'])) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Perform health checks
$db_health = EnterpriseConfig::checkDatabaseHealth($conn);

// Get system statistics
$stats = [];

// User statistics
$result = $conn->query("SELECT 
    COUNT(*) as total_users,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_users,
    SUM(CASE WHEN last_login > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as active_today
    FROM new_user");
$stats['users'] = $result->fetch_assoc();

// QC Test Orders statistics (handle missing status/created_at columns)
$qcCols = [];
$qcColRes = $conn->query("SHOW COLUMNS FROM qc_test_orders");
if ($qcColRes) {
    while ($row = $qcColRes->fetch_assoc()) {
        $qcCols[] = strtolower($row['Field']);
    }
}
$hasStatus = in_array('status', $qcCols, true);
$hasCreatedAt = in_array('created_at', $qcCols, true);

if ($hasStatus) {
$result = $conn->query("SELECT 
    COUNT(*) as total_orders,
    SUM(CASE WHEN status = 'pending_checker' THEN 1 ELSE 0 END) as pending_checker,
    SUM(CASE WHEN status = 'pending_approval' THEN 1 ELSE 0 END) as pending_approval,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved," .
        ($hasCreatedAt ? " SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as created_today" : " 0 as created_today") . "
        FROM qc_test_orders");
    $stats['qc_orders'] = $result ? $result->fetch_assoc() : [];
} else {
    $result = $conn->query("SELECT 
        COUNT(*) as total_orders," .
        ($hasCreatedAt ? " SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as created_today" : " 0 as created_today") . "
    FROM qc_test_orders");
    $row = $result ? $result->fetch_assoc() : [];
    $stats['qc_orders'] = array_merge([
        'total_orders' => 0,
        'pending_checker' => 0,
        'pending_approval' => 0,
        'approved' => 0,
        'created_today' => 0,
    ], $row ?: []);
}

// Audit log statistics (handle different audit_log schemas)
$auditCols = [];
$auditColRes = $conn->query("SHOW COLUMNS FROM audit_log");
if ($auditColRes) {
    while ($row = $auditColRes->fetch_assoc()) {
        $auditCols[] = strtolower($row['Field']);
    }
}
$hasEventType = in_array('event_type', $auditCols, true);
$hasAction = in_array('action', $auditCols, true);
$hasUserId = in_array('user_id', $auditCols, true);
$timeCol = null;
if (in_array('timestamp', $auditCols, true)) {
    $timeCol = 'timestamp';
} elseif (in_array('changed_at', $auditCols, true)) {
    $timeCol = 'changed_at';
}

if ($hasEventType) {
    $timeFilter = $timeCol ? "WHERE $timeCol > DATE_SUB(NOW(), INTERVAL 7 DAY)" : "";
$result = $conn->query("SELECT 
    COUNT(*) as total_logs,
        " . ($hasUserId ? "COUNT(DISTINCT user_id)" : "0") . " as unique_users,
    COUNT(DISTINCT event_type) as event_types,
        " . ($timeCol ? "MAX($timeCol)" : "NULL") . " as last_activity
        FROM audit_log
        $timeFilter");
    $stats['audit'] = $result ? $result->fetch_assoc() : [];
} elseif ($hasAction) {
    $timeFilter = $timeCol ? "WHERE $timeCol > DATE_SUB(NOW(), INTERVAL 7 DAY)" : "";
    $result = $conn->query("SELECT 
        COUNT(*) as total_logs,
        " . ($hasUserId ? "COUNT(DISTINCT user_id)" : "0") . " as unique_users,
        COUNT(DISTINCT action) as event_types,
        " . ($timeCol ? "MAX($timeCol)" : "NULL") . " as last_activity
    FROM audit_log 
        $timeFilter");
    $stats['audit'] = $result ? $result->fetch_assoc() : [];
} else {
    $stats['audit'] = [
        'total_logs' => 0,
        'unique_users' => 0,
        'event_types' => 0,
        'last_activity' => null,
    ];
}

// Database size
$result = $conn->query("SELECT 
    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
    FROM information_schema.TABLES
    WHERE table_schema = 'geobagg'");
$db_size = $result->fetch_assoc()['size_mb'];

// Recent errors
$recent_errors = [];
$check_table = $conn->query("SHOW TABLES LIKE 'error_log'");
if ($check_table && $check_table->num_rows > 0) {
    $result = $conn->query("SELECT * FROM error_log ORDER BY occurred_at DESC LIMIT 10");
    while ($row = $result->fetch_assoc()) {
        $recent_errors[] = $row;
    }
}

// System performance metrics
$perf_metrics = [];
$check_table = $conn->query("SHOW TABLES LIKE 'system_performance'");
if ($check_table && $check_table->num_rows > 0) {
    // Detect columns
    $perfCols = [];
    $colRes = $conn->query("SHOW COLUMNS FROM system_performance");
    if ($colRes) {
        while ($r = $colRes->fetch_assoc()) {
            $perfCols[] = strtolower($r['Field']);
        }
    }
    $hasResponseTime = in_array('response_time', $perfCols, true);
    $hasMemoryUsed = in_array('memory_used', $perfCols, true);
    $hasTimestamp = in_array('timestamp', $perfCols, true);

    if ($hasResponseTime && $hasTimestamp) {
    // Get average response time for recent requests
    $result = $conn->query("SELECT 
        AVG(response_time) as avg_response_time,
        MAX(response_time) as max_response_time,
            COUNT(*) as total_requests" .
            ($hasMemoryUsed ? ", AVG(memory_used) as avg_memory" : "") . "
        FROM system_performance 
        WHERE timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    
    if ($result && $row = $result->fetch_assoc()) {
            $perf_metrics['avg_response_time'] = round($row['avg_response_time'] ?? 0, 2);
            $perf_metrics['max_response_time'] = round($row['max_response_time'] ?? 0, 2);
            $perf_metrics['total_requests'] = $row['total_requests'] ?? 0;
            if ($hasMemoryUsed) {
                $perf_metrics['avg_memory'] = round(($row['avg_memory'] ?? 0) / 1024 / 1024, 2); // Convert to MB
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enterprise Monitor - <?php echo EnterpriseConfig::APP_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 5px 10px 5px 5px; color: #2c3e50; }
        .container { max-width: 100%; margin: 0; margin-left: 0; background: white; border-radius: 8px; padding: 15px 25px 15px 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #34495e; margin-bottom: 5px; font-size: 24px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 15px; font-size: 14px; }
        .header-info { 
            text-align: center; 
            color: #7f8c8d; 
            margin-bottom: 15px; 
            padding: 10px; 
            background: #ecf0f1; 
            border-radius: 6px;
            font-size: 13px;
        }
        .header-info span {
            margin: 0 15px;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-ok {
            background: #10b981;
            color: white;
        }
        .status-warning {
            background: #f59e0b;
            color: white;
        }
        .status-error {
            background: #ef4444;
            color: white;
        }
        .stats { 
            display: flex; 
            justify-content: center; 
            gap: 30px; 
            margin-bottom: 15px; 
            padding: 15px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            border-radius: 8px; 
            color: white; 
            flex-wrap: wrap;
        }
        .stat-item { text-align: center; min-width: 120px; }
        .stat-value { font-size: 2.5em; font-weight: bold; }
        .stat-label { font-size: 0.9em; opacity: 0.9; }
        .section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }
        .section h2 {
            font-size: 18px;
            margin-bottom: 15px;
            color: #34495e;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 6px;
            overflow: hidden;
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
            font-size: 13px;
        }
        tr:hover {
            background: #f8f9fa;
        }
        td {
            font-size: 14px;
            color: #2c3e50;
        }
        .btn {
            background: #3498db;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
        }
        .btn:hover {
            background: #2980b9;
        }
        .refresh-info {
            text-align: center;
            padding: 12px;
            background: #d1ecf1;
            border-radius: 6px;
            margin-bottom: 15px;
            color: #0c5460;
            font-size: 13px;
            border: 1px solid #bee5eb;
        }
    </style>
    <script>
        // Auto-refresh every 30 seconds
        setTimeout(function() {
            location.reload();
        }, 30000);
    </script>
</head>
<body>
    <div class="container">
        
        <a href="../index.php" style="display: inline-block; margin-bottom: 20px; padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 500; transition: background 0.3s;" onmouseover="this.style.background='#5a6268'" onmouseout="this.style.background='#6c757d'">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1><i class="fas fa-chart-line"></i> Enterprise System Monitor</h1>
        <p class="subtitle">Real-Time Performance & Health Dashboard</p>
        
        <div class="header-info">
            <span><i class="fas fa-info-circle"></i> Version: <?php echo EnterpriseConfig::APP_VERSION; ?></span>
            <span><i class="fas fa-server"></i> Environment: <?php echo strtoupper(EnterpriseConfig::ENV); ?></span>
            <span><i class="fas fa-clock"></i> <?php echo date('Y-m-d H:i:s'); ?></span>
        </div>
        
        <div class="refresh-info">
            <i class="fas fa-sync-alt"></i> Auto-refreshing every 30 seconds | Last Updated: <?php echo date('H:i:s'); ?>
        </div>
        
        <!-- Statistics Grid -->
        <div class="stats">
            <div class="stat-item">
                <div class="stat-value"><?php echo $stats['users']['total_users']; ?></div>
                <div class="stat-label">Total Users</div>
                <div style="font-size: 0.85em; margin-top: 5px;"><?php echo $stats['users']['active_users']; ?> active | <?php echo $stats['users']['active_today']; ?> today</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $stats['qc_orders']['total_orders']; ?></div>
                <div class="stat-label">QC Test Orders</div>
                <div style="font-size: 0.85em; margin-top: 5px;"><?php echo $stats['qc_orders']['created_today']; ?> created today</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $stats['qc_orders']['pending_checker']; ?></div>
                <div class="stat-label">Pending Checker</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $stats['qc_orders']['approved']; ?></div>
                <div class="stat-label">Approved</div>
            </div>
        </div>
        
        <!-- System Health -->
        <div class="section">
            <h2><i class="fas fa-heartbeat"></i> System Health</h2>
            <table>
                <tr>
                    <th>Component</th>
                    <th>Status</th>
                    <th>Message</th>
                </tr>
                <tr>
                    <td>Database Connection</td>
                    <td><span class="status-badge status-<?php echo $db_health['status']; ?>"><?php echo $db_health['status']; ?></span></td>
                    <td><?php echo $db_health['message']; ?></td>
                </tr>
                <tr>
                    <td>Database Size</td>
                    <td><span class="status-badge status-ok">OK</span></td>
                    <td><?php echo $db_size; ?> MB</td>
                </tr>
                <tr>
                    <td>PHP Version</td>
                    <td><span class="status-badge status-ok">OK</span></td>
                    <td><?php echo phpversion(); ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Audit Activity -->
        <div class="section">
            <h2><i class="fas fa-history"></i> Recent Activity (Last 7 Days)</h2>
            <table>
                <tr>
                    <th>Metric</th>
                    <th>Value</th>
                </tr>
                <tr>
                    <td>Total Actions</td>
                    <td><?php echo number_format($stats['audit']['total_logs']); ?></td>
                </tr>
                <tr>
                    <td>Unique Users</td>
                    <td><?php echo $stats['audit']['unique_users']; ?></td>
                </tr>
                <tr>
                    <td>Event Types</td>
                    <td><?php echo $stats['audit']['event_types']; ?></td>
                </tr>
                <tr>
                    <td>Last Activity</td>
                    <td><?php echo $stats['audit']['last_activity'] ?? 'N/A'; ?></td>
                </tr>
            </table>
        </div>
        
        <?php if (!empty($recent_errors)): ?>
        <!-- Recent Errors -->
        <div class="section">
            <h2><i class="fas fa-exclamation-triangle"></i> Recent Errors</h2>
            <table>
                <tr>
                    <th>Time</th>
                    <th>Type</th>
                    <th>Message</th>
                    <th>User</th>
                </tr>
                <?php foreach (array_slice($recent_errors, 0, 5) as $error): ?>
                <tr>
                    <td><?php echo date('Y-m-d H:i', strtotime($error['occurred_at'])); ?></td>
                    <td><?php echo htmlspecialchars($error['error_type']); ?></td>
                    <td><?php echo htmlspecialchars(substr($error['error_message'], 0, 100)); ?>...</td>
                    <td><?php echo $error['user_id'] ?? 'N/A'; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
        
        <div style="text-align: center; margin-top: 20px; padding: 15px;">
            <a href="javascript:location.reload()" class="btn"><i class="fas fa-sync-alt"></i> Refresh Now</a>
        </div>
    </div>
</body>
</html>



