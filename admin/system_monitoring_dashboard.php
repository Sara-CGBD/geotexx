<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Check if user is admin or management
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'management', 'agm ops', 'agm operations'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>ðŸš« Access Denied</h2>
        <p>You do not have permission to access System Monitoring Dashboard.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

$pageTitle = "System Monitoring Dashboard";

// Get database statistics
$db_size_query = "SELECT 
    table_schema AS 'Database',
    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'Size_MB'
FROM information_schema.tables 
WHERE table_schema = 'geobagg'
GROUP BY table_schema";

$db_size_result = $conn->query($db_size_query);
$db_size = $db_size_result->fetch_assoc()['Size_MB'] ?? 0;

// Get table statistics
$table_stats_query = "SELECT 
    table_name AS 'Table',
    table_rows AS 'Rows',
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size_MB',
    ROUND((data_length / 1024 / 1024), 2) AS 'Data_MB',
    ROUND((index_length / 1024 / 1024), 2) AS 'Index_MB'
FROM information_schema.tables
WHERE table_schema = 'geobagg'
ORDER BY (data_length + index_length) DESC
LIMIT 20";

$table_stats = $conn->query($table_stats_query);

// Get connection statistics
$conn_stats = $conn->query("SHOW STATUS LIKE 'Threads_connected'");
$connections = $conn_stats->fetch_assoc()['Value'] ?? 0;

// Get uptime
$uptime_stats = $conn->query("SHOW STATUS LIKE 'Uptime'");
$uptime = $uptime_stats->fetch_assoc()['Value'] ?? 0;
$uptime_hours = round($uptime / 3600, 1);

// Get query statistics
$queries_stats = $conn->query("SHOW STATUS LIKE 'Questions'");
$total_queries = $queries_stats->fetch_assoc()['Value'] ?? 0;

// Get slow query count
$slow_queries_stats = $conn->query("SHOW STATUS LIKE 'Slow_queries'");
$slow_queries = $slow_queries_stats->fetch_assoc()['Value'] ?? 0;

// Get recent activity
$recent_activity = $conn->query("SELECT 
    DATE(timestamp) as date,
    COUNT(*) as actions
FROM audit_log 
WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY DATE(timestamp)
ORDER BY date DESC");

// Get user activity
$user_activity = $conn->query("SELECT 
    who_did,
    COUNT(*) as action_count,
    MAX(timestamp) as last_activity
FROM audit_log
WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
GROUP BY who_did
ORDER BY action_count DESC
LIMIT 10");

// Get table record counts
$record_counts = [
    'Projects' => $conn->query("SELECT COUNT(*) as cnt FROM projects")->fetch_assoc()['cnt'],
    'QC Tests' => $conn->query("SELECT COUNT(*) as cnt FROM qc_entries")->fetch_assoc()['cnt'],
    'Water Perm Tests' => $conn->query("SELECT COUNT(*) as cnt FROM water_permeability_tests")->fetch_assoc()['cnt'],
    'FG Products' => $conn->query("SELECT COUNT(*) as cnt FROM fg")->fetch_assoc()['cnt'],
    'Roll Entries' => $conn->query("SELECT COUNT(*) as cnt FROM roll_entry")->fetch_assoc()['cnt'],
    'Production Entries' => $conn->query("SELECT COUNT(*) as cnt FROM production_entry")->fetch_assoc()['cnt'],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - GEOTEX</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        min-height: 100vh;
        padding: 20px;
    }
    
    .container {
        max-width: 1400px;
        margin: 0 auto;
        background: white;
        padding: 30px;
        border-radius: 15px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
    }
    
<style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 15px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: linear-gradient(135deg, rgb(32, 60, 78), rgb(93, 133, 160));
        color: white;
        padding: 15px;
        border-radius: 10px;
        text-align: center;
    }
    
    .stat-label {
        font-size: 0.9rem;
        opacity: 0.9;
    }
    
    .stat-value {
        font-size: 1.8rem;
        font-weight: bold;
        margin-top: 10px;
    }
    
    .stat-unit {
        font-size: 1rem;
        margin-left: 5px;
    }
    
    .record-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 15px;
        margin: 20px 0;
    }
    
    .record-card {
        background: linear-gradient(135deg,rgb(32, 60, 78),rgb(7, 25, 37));
        color: white;
        padding: 15px;
        border-radius: 10px;
        text-align: center;
    }
    
    .record-card-label {
        font-size: 0.9rem;
        opacity: 0.9;
    }
    
    .record-card-value {
        font-size: 1.8rem;
        font-weight: bold;
        margin-top: 10px;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        background: white;
    }
    
    thead {
        background: #2c3e50;
        color: white;
    }
    
    th, td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #ecf0f1;
    }
    
    tbody tr:hover {
        background: #f8f9fa;
    }
    
    .section-title {
        font-size: 1.4rem;
        color: #2c3e50;
        margin: 30px 0 15px 0;
        padding-bottom: 10px;
        border-bottom: 2px solid #3498db;
    }
    
    .refresh-btn {
        background: linear-gradient(135deg, #27ae60, #229954);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        margin-bottom: 20px;
        transition: all 0.3s ease;
    }
    
    .refresh-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(39, 174, 96, 0.3);
    }
</style>

<body>
<div class="container">

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <a href="../index.php" class="btn" style="background: #7f8c8d; color: white; text-decoration: none;">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
    <button onclick="location.reload()" class="refresh-btn">
        <i class="fas fa-sync-alt"></i> Refresh Data
    </button>
</div>

<h1 style="text-align: center; color: #2c3e50; margin-bottom: 10px;">
    <i class="fas fa-desktop"></i> System Monitoring Dashboard
</h1>
<p style="text-align: center; color: #7f8c8d; margin-bottom: 30px;">Real-time database and system performance metrics</p>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Database Size</div>
        <div class="stat-value"><?php echo number_format($db_size, 2); ?><span class="stat-unit">MB</span></div>
    </div>
    
    <div class="stat-card">
        <div class="stat-label">Active Connections</div>
        <div class="stat-value"><?php echo $connections; ?></div>
    </div>
    
    <div class="stat-card">
        <div class="stat-label">System Uptime</div>
        <div class="stat-value"><?php echo number_format($uptime_hours, 1); ?><span class="stat-unit">hrs</span></div>
    </div>
    
    <div class="stat-card">
        <div class="stat-label">Total Queries</div>
        <div class="stat-value"><?php echo number_format($total_queries); ?></div>
    </div>
    
    <div class="stat-card">
        <div class="stat-label">Slow Queries</div>
        <div class="stat-value"><?php echo number_format($slow_queries); ?></div>
    </div>
</div>

<!-- Database Records -->
<h2 class="section-title"><i class="fas fa-database"></i> Database Records</h2>
<div class="record-grid">
    <?php foreach ($record_counts as $label => $count): ?>
        <div class="record-card">
            <div class="record-card-label"><?php echo $label; ?></div>
            <div class="record-card-value"><?php echo number_format($count); ?></div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Top Tables by Size -->
<h2 class="section-title"><i class="fas fa-chart-bar"></i> Top Tables by Size</h2>
<table>
    <thead>
        <tr>
            <th>Table Name</th>
            <th>Rows</th>
            <th>Total Size (MB)</th>
            <th>Data (MB)</th>
            <th>Index (MB)</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($table_stats && $table_stats->num_rows > 0): ?>
            <?php while ($row = $table_stats->fetch_assoc()): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['Table']); ?></strong></td>
                    <td><?php echo number_format($row['Rows']); ?></td>
                    <td><?php echo number_format($row['Size_MB'], 2); ?></td>
                    <td><?php echo number_format($row['Data_MB'], 2); ?></td>
                    <td><?php echo number_format($row['Index_MB'], 2); ?></td>
                </tr>
            <?php endwhile; ?>
        <?php endif; ?>
    </tbody>
</table>

<!-- Top Users (Last 7 Days) -->
<h2 class="section-title"><i class="fas fa-users"></i> Top Users (Last 7 Days)</h2>
<table>
    <thead>
        <tr>
            <th>User</th>
            <th>Actions</th>
            <th>Last Activity</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($user_activity && $user_activity->num_rows > 0): ?>
            <?php while ($row = $user_activity->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['who_did'] ?? 'System'); ?></td>
                    <td><?php echo number_format($row['action_count']); ?></td>
                    <td><?php echo date('M d, Y H:i', strtotime($row['last_activity'])); ?></td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="3" style="text-align: center; padding: 20px; color: #7f8c8d;">
                    No recent activity
                </td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- Daily Activity (Last 7 Days) -->
<h2 class="section-title"><i class="fas fa-calendar-alt"></i> Daily Activity (Last 7 Days)</h2>
<table>
    <thead>
        <tr>
            <th>Date</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($recent_activity && $recent_activity->num_rows > 0): ?>
            <?php while ($row = $recent_activity->fetch_assoc()): ?>
                <tr>
                    <td><?php echo date('M d, Y', strtotime($row['date'])); ?></td>
                    <td><?php echo number_format($row['actions']); ?></td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="2" style="text-align: center; padding: 20px; color: #7f8c8d;">
                    No recent activity
                </td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<script>
// Auto-refresh every 60 seconds
setTimeout(function() {
    location.reload();
}, 60000);
</script>

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

</div>
</body>
</html>


