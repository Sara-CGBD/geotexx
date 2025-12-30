<?php
session_start();

// Performance monitoring
require_once '../config/PerformanceMonitor.php';
PerformanceMonitor::start();

require_once '../config/security_config.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    die("Access Denied");
}

$conn = SecurityConfig::getConnection();

// Get date range
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Check if performance table exists
$tableCheck = $conn->query("SHOW TABLES LIKE 'system_performance'");
$tableExists = ($tableCheck && $tableCheck->num_rows > 0);

if ($tableExists) {
    // Detect available columns to avoid schema errors
    $perfCols = [];
    $colRes = $conn->query("SHOW COLUMNS FROM system_performance");
    if ($colRes) {
        while ($row = $colRes->fetch_assoc()) {
            $perfCols[] = strtolower($row['Field']);
        }
    }
    $hasPageName = in_array('page_name', $perfCols, true);
    $hasResponseTime = in_array('response_time', $perfCols, true);
    $hasMemoryUsed = in_array('memory_used', $perfCols, true);
    $hasQueryCount = in_array('query_count', $perfCols, true);
    $hasUrl = in_array('url', $perfCols, true);
    $hasTimestamp = in_array('timestamp', $perfCols, true);

    // If we don't even have response_time, skip processing and return empty datasets
    if (!$hasResponseTime) {
        $slowestPages = [];
        $performanceTimeline = [];
        $recentSlow = [];
        $stats = null;
    } else {

    // Get slowest pages
    $slowestPagesQuery = $hasPageName
        ? "SELECT page_name, 
               COUNT(*) as request_count,
               AVG(response_time) as avg_time,
               MAX(response_time) as max_time,
               MIN(response_time) as min_time,
               AVG(memory_used) as avg_memory,
               AVG(query_count) as avg_queries
        FROM system_performance
        WHERE DATE(timestamp) BETWEEN '$dateFrom' AND '$dateTo'
        GROUP BY page_name
        ORDER BY avg_time DESC
        LIMIT 20"
        : "SELECT 'n/a' as page_name,
               COUNT(*) as request_count,
               AVG(response_time) as avg_time,
               MAX(response_time) as max_time,
               MIN(response_time) as min_time,
               AVG(memory_used) as avg_memory,
               AVG(query_count) as avg_queries
        FROM system_performance
        WHERE DATE(timestamp) BETWEEN '$dateFrom' AND '$dateTo'
        ORDER BY avg_time DESC
        LIMIT 20";
    $slowestPages = $conn->query($slowestPagesQuery)->fetch_all(MYSQLI_ASSOC);
    
    // Get performance over time
    $performanceTimeline = $conn->query("
        SELECT DATE(timestamp) as date,
               AVG(response_time) as avg_time,
               COUNT(*) as total_requests,
               SUM(CASE WHEN response_time > 2 THEN 1 ELSE 0 END) as slow_requests
        FROM system_performance
        WHERE DATE(timestamp) BETWEEN '$dateFrom' AND '$dateTo'
        GROUP BY DATE(timestamp)
        ORDER BY date ASC
    ")->fetch_all(MYSQLI_ASSOC);
    
    // Get recent slow requests
    $recentSlowQuery = $hasPageName
        ? "SELECT page_name, response_time, memory_used, query_count, url, timestamp
        FROM system_performance
        WHERE response_time > 2
        AND DATE(timestamp) BETWEEN '$dateFrom' AND '$dateTo'
        ORDER BY timestamp DESC
        LIMIT 50"
        : "SELECT 'n/a' as page_name, response_time, memory_used, query_count, url, timestamp
        FROM system_performance
        WHERE response_time > 2
        AND DATE(timestamp) BETWEEN '$dateFrom' AND '$dateTo'
        ORDER BY timestamp DESC
        LIMIT 50";
    $recentSlow = $conn->query($recentSlowQuery)->fetch_all(MYSQLI_ASSOC);
    
    // Overall statistics
    $stats = $conn->query("
        SELECT 
            COUNT(*) as total_requests,
            AVG(response_time) as avg_response_time,
            MAX(response_time) as max_response_time,
            AVG(memory_used) as avg_memory,
            AVG(query_count) as avg_queries,
            SUM(CASE WHEN response_time < 0.5 THEN 1 ELSE 0 END) as excellent,
            SUM(CASE WHEN response_time >= 0.5 AND response_time < 1 THEN 1 ELSE 0 END) as good,
            SUM(CASE WHEN response_time >= 1 AND response_time < 2 THEN 1 ELSE 0 END) as fair,
            SUM(CASE WHEN response_time >= 2 THEN 1 ELSE 0 END) as poor
        FROM system_performance
        WHERE DATE(timestamp) BETWEEN '$dateFrom' AND '$dateTo'
    ")->fetch_assoc();
    }
} else {
    $slowestPages = [];
    $performanceTimeline = [];
    $recentSlow = [];
    $stats = null;
}

function formatBytes($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Performance Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: #f5f7fa; 
            padding: 5px 10px 5px 5px; 
            color: #2c3e50; 
        }
        .container { 
            max-width: 100%; 
            margin: 0; 
            margin-left: 0; 
            background: white; 
            border-radius: 8px; 
            padding: 15px 25px 15px 10px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.08); 
        }
        h1 { 
            text-align: center; 
            color: #34495e; 
            margin-bottom: 5px; 
            font-size: 24px; 
        }
        .subtitle { 
            text-align: center; 
            color: #7f8c8d; 
            margin-bottom: 15px; 
            font-size: 14px; 
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
        .stat-item { 
            text-align: center; 
            min-width: 150px;
        }
        .stat-value { 
            font-size: 2.5em; 
            font-weight: bold; 
        }
        .stat-label { 
            font-size: 0.9em; 
            opacity: 0.9; 
        }
        .section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #ecf0f1;
        }
        .section h2 {
            color: #34495e;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 18px;
            font-weight: 600;
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
            position: sticky;
            top: 0;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .perf-excellent { color: #27ae60; font-weight: bold; }
        .perf-good { color: #2ecc71; }
        .perf-fair { color: #f39c12; }
        .perf-poor { color: #e74c3c; font-weight: bold; }
        .perf-critical { color: #c0392b; font-weight: bold; }
        .back-btn {
            display: inline-block;
            margin-bottom: 20px;
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.3s;
        }
        .back-btn:hover {
            background: #5a6268;
        }
        .filter-form {
            background: #f8f9fa;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            align-items: end;
            border: 1px solid #ecf0f1;
            flex-wrap: wrap;
        }
        .filter-form input {
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-family: 'Inter', sans-serif;
        }
        .filter-form button {
            padding: 10px 20px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            transition: background 0.3s;
        }
        .filter-form button:hover {
            background: #5568d3;
        }
        .filter-form a {
            padding: 10px 20px;
            background: #95a5a6;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 500;
            transition: background 0.3s;
        }
        .filter-form a:hover {
            background: #7f8c8d;
        }
        .no-data {
            text-align: center;
            padding: 60px;
            color: #95a5a6;
        }
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .progress-bar {
            height: 20px;
            background: #ecf0f1;
            border-radius: 10px;
            overflow: hidden;
            margin-top: 10px;
        }
        .progress-fill {
            height: 100%;
            background: #3498db;
            transition: width 0.3s;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="../index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1><i class="fas fa-tachometer-alt"></i> Performance Dashboard</h1>
        <p class="subtitle">Monitor application performance and identify bottlenecks</p>
        
        <?php if (!$tableExists): ?>
        <div class="alert alert-warning">
            <strong><i class="fas fa-exclamation-triangle"></i> Performance Tracking Not Available</strong><br>
            The system_performance table doesn't exist. Performance data is not being collected.<br>
            Run the enterprise upgrade script to enable performance monitoring.
        </div>
        <?php else: ?>
        
        <!-- Date Filter -->
        <form method="GET" class="filter-form">
            <div>
                <label style="display:block; margin-bottom:5px; font-weight:600;">From Date</label>
                <input type="date" name="date_from" value="<?php echo $dateFrom; ?>" required>
            </div>
            <div>
                <label style="display:block; margin-bottom:5px; font-weight:600;">To Date</label>
                <input type="date" name="date_to" value="<?php echo $dateTo; ?>" required>
            </div>
            <button type="submit"><i class="fas fa-filter"></i> Filter</button>
            <a href="performance_dashboard.php" style="padding:10px 20px; background:#95a5a6; color:white; text-decoration:none; border-radius:4px;">
                <i class="fas fa-redo"></i> Reset
            </a>
        </form>
        
        <!-- Overall Statistics -->
        <?php if ($stats && $stats['total_requests'] > 0): ?>
        <div class="stats">
            <div class="stat-item">
                <div class="stat-value"><?php echo number_format($stats['total_requests']); ?></div>
                <div class="stat-label">Total Requests</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo number_format($stats['avg_response_time'], 2); ?>s</div>
                <div class="stat-label">Avg Response</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo number_format($stats['max_response_time'], 2); ?>s</div>
                <div class="stat-label">Max Response</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo number_format($stats['avg_memory'] / 1024 / 1024, 1); ?>MB</div>
                <div class="stat-label">Avg Memory</div>
            </div>
        </div>
        
        <!-- Performance Distribution -->
        <div class="section">
            <h2><i class="fas fa-chart-pie"></i> Performance Distribution</h2>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:15px;">
                <div>
                    <strong style="color:#27ae60;">Excellent (< 0.5s)</strong>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width:<?php echo ($stats['excellent']/$stats['total_requests'])*100; ?>%; background:#27ae60;"></div>
                    </div>
                    <small><?php echo number_format(($stats['excellent']/$stats['total_requests'])*100, 1); ?>% (<?php echo $stats['excellent']; ?> requests)</small>
                </div>
                <div>
                    <strong style="color:#2ecc71;">Good (0.5-1s)</strong>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width:<?php echo ($stats['good']/$stats['total_requests'])*100; ?>%; background:#2ecc71;"></div>
                    </div>
                    <small><?php echo number_format(($stats['good']/$stats['total_requests'])*100, 1); ?>% (<?php echo $stats['good']; ?> requests)</small>
                </div>
                <div>
                    <strong style="color:#f39c12;">Fair (1-2s)</strong>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width:<?php echo ($stats['fair']/$stats['total_requests'])*100; ?>%; background:#f39c12;"></div>
                    </div>
                    <small><?php echo number_format(($stats['fair']/$stats['total_requests'])*100, 1); ?>% (<?php echo $stats['fair']; ?> requests)</small>
                </div>
                <div>
                    <strong style="color:#e74c3c;">Poor (> 2s)</strong>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width:<?php echo ($stats['poor']/$stats['total_requests'])*100; ?>%; background:#e74c3c;"></div>
                    </div>
                    <small><?php echo number_format(($stats['poor']/$stats['total_requests'])*100, 1); ?>% (<?php echo $stats['poor']; ?> requests)</small>
                </div>
            </div>
        </div>
        
        <!-- Slowest Pages -->
        <div class="section">
            <h2><i class="fas fa-hourglass-half"></i> Slowest Pages (Top 20)</h2>
            <?php if (!empty($slowestPages)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Page Name</th>
                        <th>Requests</th>
                        <th>Avg Time</th>
                        <th>Max Time</th>
                        <th>Min Time</th>
                        <th>Avg Memory</th>
                        <th>Avg Queries</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($slowestPages as $page): 
                        $level_class = 'perf-excellent';
                        if ($page['avg_time'] >= 0.5 && $page['avg_time'] < 1) $level_class = 'perf-good';
                        if ($page['avg_time'] >= 1 && $page['avg_time'] < 2) $level_class = 'perf-fair';
                        if ($page['avg_time'] >= 2 && $page['avg_time'] < 5) $level_class = 'perf-poor';
                        if ($page['avg_time'] >= 5) $level_class = 'perf-critical';
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($page['page_name']); ?></strong></td>
                        <td><?php echo number_format($page['request_count']); ?></td>
                        <td class="<?php echo $level_class; ?>"><?php echo number_format($page['avg_time'], 3); ?>s</td>
                        <td><?php echo number_format($page['max_time'], 3); ?>s</td>
                        <td><?php echo number_format($page['min_time'], 3); ?>s</td>
                        <td><?php echo formatBytes($page['avg_memory']); ?></td>
                        <td><?php echo number_format($page['avg_queries'], 1); ?></td>
                        <td class="<?php echo $level_class; ?>">
                            <?php 
                            if ($page['avg_time'] < 0.5) echo '✅ Excellent';
                            elseif ($page['avg_time'] < 1) echo '✅ Good';
                            elseif ($page['avg_time'] < 2) echo '⚠️ Fair';
                            elseif ($page['avg_time'] < 5) echo '❌ Poor';
                            else echo '🔴 Critical';
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="no-data">No performance data available for selected date range</div>
            <?php endif; ?>
        </div>
        
        <!-- Recent Slow Requests -->
        <?php if (!empty($recentSlow)): ?>
        <div class="section">
            <h2><i class="fas fa-exclamation-triangle"></i> Recent Slow Requests (> 2s)</h2>
            <table>
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Page</th>
                        <th>Response Time</th>
                        <th>Memory</th>
                        <th>Queries</th>
                        <th>URL</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentSlow as $req): ?>
                    <tr>
                        <td><?php echo date('M d, H:i:s', strtotime($req['timestamp'])); ?></td>
                        <td><?php echo htmlspecialchars($req['page_name']); ?></td>
                        <td class="perf-poor"><?php echo number_format($req['response_time'], 3); ?>s</td>
                        <td><?php echo formatBytes($req['memory_used']); ?></td>
                        <td><?php echo $req['query_count']; ?></td>
                        <td><small><?php echo htmlspecialchars(substr($req['url'], 0, 60)); ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Performance Timeline -->
        <?php if (!empty($performanceTimeline)): ?>
        <div class="section">
            <h2><i class="fas fa-chart-line"></i> Performance Timeline</h2>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Total Requests</th>
                        <th>Avg Response Time</th>
                        <th>Slow Requests</th>
                        <th>Slow %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($performanceTimeline as $day): 
                        $slow_percent = ($day['slow_requests'] / $day['total_requests']) * 100;
                    ?>
                    <tr>
                        <td><strong><?php echo date('M d, Y', strtotime($day['date'])); ?></strong></td>
                        <td><?php echo number_format($day['total_requests']); ?></td>
                        <td class="<?php echo $day['avg_time'] < 1 ? 'perf-good' : ($day['avg_time'] < 2 ? 'perf-fair' : 'perf-poor'); ?>">
                            <?php echo number_format($day['avg_time'], 3); ?>s
                        </td>
                        <td><?php echo $day['slow_requests']; ?></td>
                        <td class="<?php echo $slow_percent < 10 ? 'perf-good' : ($slow_percent < 25 ? 'perf-fair' : 'perf-poor'); ?>">
                            <?php echo number_format($slow_percent, 1); ?>%
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- Recommendations -->
        <div class="section">
            <h2><i class="fas fa-lightbulb"></i> Optimization Recommendations</h2>
            
            <?php
            $recommendations = [];
            
            if ($stats && $stats['avg_response_time'] > 1) {
                $recommendations[] = [
                    'priority' => 'high',
                    'title' => 'Slow Average Response Time',
                    'message' => 'Average response time is ' . number_format($stats['avg_response_time'], 2) . 's (should be < 1s)',
                    'action' => 'Add database indexes, implement caching, optimize queries'
                ];
            }
            
            if ($stats && $stats['avg_queries'] > 10) {
                $recommendations[] = [
                    'priority' => 'medium',
                    'title' => 'High Query Count',
                    'message' => 'Pages average ' . number_format($stats['avg_queries'], 1) . ' queries (should be < 10)',
                    'action' => 'Combine queries, use JOINs instead of separate queries, implement caching'
                ];
            }
            
            if ($stats && ($stats['poor'] / $stats['total_requests']) > 0.1) {
                $recommendations[] = [
                    'priority' => 'high',
                    'title' => 'Too Many Slow Requests',
                    'message' => number_format(($stats['poor']/$stats['total_requests'])*100, 1) . '% of requests are slow (> 2s)',
                    'action' => 'Urgent optimization needed - check slowest pages above'
                ];
            }
            
            if ($stats && $stats['avg_memory'] > 50 * 1024 * 1024) {
                $recommendations[] = [
                    'priority' => 'medium',
                    'title' => 'High Memory Usage',
                    'message' => 'Average memory usage is ' . formatBytes($stats['avg_memory']) . ' (should be < 50 MB)',
                    'action' => 'Optimize data loading, use pagination, clear unnecessary variables'
                ];
            }
            
            if (empty($recommendations)) {
                echo '<div style="text-align:center; padding:40px; color:#27ae60;">';
                echo '<i class="fas fa-check-circle" style="font-size:48px; margin-bottom:15px;"></i>';
                echo '<h3>Performance is Good!</h3>';
                echo '<p>No critical issues detected. System is running efficiently.</p>';
                echo '</div>';
            } else {
                foreach ($recommendations as $rec) {
                    $color = $rec['priority'] === 'high' ? '#e74c3c' : '#f39c12';
                    echo '<div style="background:#f8f9fa; border-left:4px solid ' . $color . '; padding:15px; margin-bottom:15px; border-radius:4px;">';
                    echo '<strong style="color:' . $color . ';">' . strtoupper($rec['priority']) . ' PRIORITY:</strong> ' . $rec['title'] . '<br>';
                    echo '<small style="color:#7f8c8d;">' . $rec['message'] . '</small><br>';
                    echo '<small style="color:#2c3e50;"><strong>Action:</strong> ' . $rec['action'] . '</small>';
                    echo '</div>';
                }
            }
            ?>
        </div>
        
        <?php endif; ?>
        <?php endif; ?>
    </div>
    
<script>
// Refresh only when approve/reject actions happen
(function() {
    let isRefreshing = false;
    
    // Function to refresh the dashboard
    function refreshDashboard() {
        if (isRefreshing) return;
        isRefreshing = true;
        
        // Reload the page with cache busting
        window.location.href = window.location.href.split('?')[0] + '?t=' + new Date().getTime();
    }
    
    // Listen for messages from child windows (approval/rejection pages)
    window.addEventListener('message', function(event) {
        // Verify origin for security
        if (event.origin !== window.location.origin) {
            return;
        }
        
        // If message indicates a report was processed (approved/rejected), refresh
        if (event.data && (event.data.type === 'report_processed' || event.data.type === 'report_approved' || event.data.type === 'report_rejected')) {
            // Small delay to ensure database is updated
            setTimeout(function() {
                refreshDashboard();
            }, 300);
        }
    });
})();
</script>
    
<?php PerformanceMonitor::end('performance_dashboard'); ?>
</body>
</html>


