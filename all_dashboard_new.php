<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}

// Only admin can access dashboard overview
if (isset($_SESSION['role']) && strtolower(trim($_SESSION['role'])) !== 'admin') {
    // Redirect non-admin users to index
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Access Denied</title>
        <style>
            body { font-family: Arial, sans-serif; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; background: #f5f5f5; }
            .box { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; max-width: 500px; }
            h1 { color: #e74c3c; margin-bottom: 20px; }
            p { color: #666; margin-bottom: 30px; font-size: 16px; }
            .btn { background: #3498db; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; }
            .btn:hover { background: #2980b9; }
        </style>
    </head>
    <body>
        <div class='box'>
            <h1>🚫 Access Denied</h1>
            <p>Dashboard Overview is only available for Admin users.</p>
            <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
            <a href='index.php' class='btn'>Go to Your Dashboard</a>
        </div>
    </body>
    </html>";
    exit();
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
            padding: 20px;
        }
        
        .admin-badge {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #e74c3c;
            color: white;
            padding: 10px 20px;
            border-radius: 20px;
            font-weight: bold;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .welcome-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            margin-bottom: 30px;
        }
        
        .welcome-header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .welcome-header p {
            font-size: 1.1em;
            opacity: 0.9;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            font-size: 2em;
            margin-bottom: 15px;
        }
        
        .stat-value {
            font-size: 2em;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .stat-label {
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        .quick-actions {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        .quick-actions h2 {
            margin-bottom: 20px;
            color: #2c3e50;
        }
        
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .action-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .system-info {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .system-info h2 {
            margin-bottom: 20px;
            color: #2c3e50;
        }
        
        .info-item {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .info-item h3 {
            color: #34495e;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-item p {
            color: #7f8c8d;
            line-height: 1.6;
        }
        
        .back-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #3498db;
            color: white;
            padding: 15px 30px;
            border-radius: 50px;
            text-decoration: none;
            box-shadow: 0 5px 20px rgba(52, 152, 219, 0.4);
            transition: all 0.3s ease;
            font-weight: bold;
        }
        
        .back-btn:hover {
            background: #2980b9;
            transform: scale(1.05);
        }
    </style>
</head>
<body>
    <div class="admin-badge">
        👑 ADMIN VIEW
    </div>

    <div class="dashboard-container">
        <div class="welcome-header">
            <h1>📊 Dashboard Overview</h1>
            <p>Welcome to GEOCIL Automation System - Administrator Dashboard</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">🏭</div>
                <div class="stat-value">1,247</div>
                <div class="stat-label">Production Units</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">✅</div>
                <div class="stat-value">98.5%</div>
                <div class="stat-label">Quality Score</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⚡</div>
                <div class="stat-value">87.3%</div>
                <div class="stat-label">Efficiency Rate</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">📄</div>
                <div class="stat-value">24</div>
                <div class="stat-label">Reports Generated</div>
            </div>
        </div>

        <div class="quick-actions">
            <h2>⚡ Quick Actions</h2>
            <div class="actions-grid">
                <a href="forms/fiber_entry.php" class="action-btn">
                    <i class="fas fa-boxes"></i> Fiber Entry
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
            <h2>💻 System Information</h2>
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
                <p>Currently 3 users online. System capacity: 100 concurrent users.</p>
            </div>
        </div>
    </div>

    <a href="index.php" class="back-btn">
        <i class="fas fa-arrow-left"></i> Back to Main Menu
    </a>
</body>
</html>


