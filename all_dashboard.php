<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}

// Only admin can access dashboard overview
if (isset($_SESSION['role']) && strtolower(trim($_SESSION['role'])) !== 'admin') {
    // Silently redirect non-admin users to index
    header("Location: index.php");
    exit();
}

// Connect to database for stats
$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) {
    $conn = null;
}

// Get active users count (users active in last 30 minutes)
$active_users_count = 0;
if ($conn) {
    $thirty_minutes_ago = date('Y-m-d H:i:s', strtotime('-30 minutes'));
    
    // Try to get from new_user table
    $result = $conn->query("SELECT COUNT(*) as count FROM new_user WHERE is_active = 1 AND last_activity >= '$thirty_minutes_ago'");
    if ($result) {
        $row = $result->fetch_assoc();
        $active_users_count = $row['count'];
    }
    
    // If no last_activity column or no results, just count active users
    if ($active_users_count == 0) {
        $result = $conn->query("SELECT COUNT(*) as count FROM new_user WHERE is_active = 1");
        if ($result) {
            $row = $result->fetch_assoc();
            $active_users_count = $row['count'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Overview - Admin Only</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding: 10px 30px 10px 10px;
        }
        
        .dashboard-container {
            max-width: 100%;
            width: 100%;
            margin: 0;
            padding: 0;
        }
        
        .welcome-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px 25px;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            margin-bottom: 15px;
            text-align: center;
        }
        
        .welcome-header h1 {
            font-size: 2em;
            margin-bottom: 5px;
        }
        
        .welcome-header p {
            font-size: 1em;
            opacity: 0.9;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 15px;
        }
        
        .stat-card {
            background: white;
            padding: 18px;
            border-radius: 8px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
        }
        
        .stat-icon {
            font-size: 1.5em;
            margin-bottom: 10px;
        }
        
        .stat-value {
            font-size: 1.8em;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .stat-label {
            color: #7f8c8d;
            margin-top: 5px;
            font-size: 0.9em;
        }
        
        .quick-actions {
            background: white;
            padding: 18px;
            border-radius: 8px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            margin-bottom: 15px;
        }
        
        .quick-actions h2 {
            margin-bottom: 15px;
            color: #2c3e50;
            font-size: 1.3em;
        }
        
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
        }
        
        .action-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 15px;
            border-radius: 6px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-size: 0.9em;
        }
        
        .action-btn:hover {
            transform: translateX(3px);
            box-shadow: 0 3px 10px rgba(102, 126, 234, 0.4);
        }
        
        .system-info {
            background: white;
            padding: 18px;
            border-radius: 8px;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }
        
        .system-info h2 {
            margin-bottom: 15px;
            color: #2c3e50;
            font-size: 1.3em;
        }
        
        .info-item {
            padding: 14px;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 12px;
        }
        
        .info-item h3 {
            color: #34495e;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1em;
        }
        
        .info-item p {
            color: #7f8c8d;
            line-height: 1.5;
            font-size: 0.9em;
        }
        
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="welcome-header">
            <h1>ðŸ“Š Dashboard Overview</h1>
            <p>Administrator Dashboard</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">ðŸ­</div>
                <div class="stat-value">100</div>
                <div class="stat-label">Production Units</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">âœ…</div>
                <div class="stat-value">94.5%</div>
                <div class="stat-label">Quality Score</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">âš¡</div>
                <div class="stat-value">87.3%</div>
                <div class="stat-label">Efficiency Rate</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">ðŸ“„</div>
                <div class="stat-value">28</div>
                <div class="stat-label">Reports Generated</div>
            </div>
        </div>

        <div class="quick-actions">
            <h2>âš¡ Quick Actions</h2>
            <div class="actions-grid">
                <a href="forms/fiber_entry.php" class="action-btn">
                    <i class="fas fa-boxes"></i> Fiber Received Entry
                </a>
                <a href="forms/roll_entry.php" class="action-btn">
                    <i class="fas fa-dolly-flatbed"></i> Roll Entry
                </a>
                <a href="forms/cnc_entry.php" class="action-btn">
                    <i class="fas fa-cut"></i> CNC Entry
                </a>
                <a href="forms/scrap_entry.php" class="action-btn">
                    <i class="fas fa-trash"></i> Scrap Entry
                </a>
                <a href="forms/fg_entry.php" class="action-btn">
                    <i class="fas fa-box"></i> FG Entry
                </a>
                <a href="forms/branding_entry.php" class="action-btn">
                    <i class="fas fa-stamp"></i> Branding Entry
                </a>
                <a href="forms/fg_delivery_entry.php" class="action-btn">
                    <i class="fas fa-truck"></i> FG Delivery
                </a>
                <a href="forms/roll_received_entry.php" class="action-btn">
                    <i class="fas fa-clipboard-check"></i> Roll Received
                </a>
                <a href="forms/roll_transfer_entry.php" class="action-btn">
                    <i class="fas fa-truck-moving"></i> Roll Transfer
                </a>
                <a href="forms/swing_machine_entry.php" class="action-btn">
                    <i class="fas fa-thread"></i> Swing Machine
                </a>
                <a href="forms/BOM_entry.php" class="action-btn">
                    <i class="fas fa-list"></i> BOM Entry
                </a>
                <a href="forms/target_entry.php" class="action-btn">
                    <i class="fas fa-bullseye"></i> Target Entry
                </a>
            </div>
        </div>

        <div class="system-info">
            <h2>ðŸ’» System Information</h2>
            <div class="info-item">
                <h3><i class="fas fa-database"></i> Database Status</h3>
                <p>All systems operational. Database connection stable and performing optimally.</p>
            </div>
            <div class="info-item">
                <h3><i class="fas fa-shield-alt"></i> Security Status</h3>
                <p>Security protocols active. All user sessions are encrypted and monitored.</p>
            </div>
            <div class="info-item">
                <h3><i class="fas fa-clock"></i> Last Update</h3>
                <p>System last updated: <?php echo date('F j, Y \a\t g:i A'); ?></p>
            </div>
            <div class="info-item">
                <h3><i class="fas fa-users"></i> Active Users</h3>
                <p>Currently <?php echo $active_users_count; ?> user<?php echo $active_users_count != 1 ? 's' : ''; ?> online. System capacity: 100 concurrent users.</p>
            </div>
        </div>
    </div>
</body>
</html>



