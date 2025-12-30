<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Overview</title>
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
        
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .welcome-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            border-radius: 20px;
            text-align: center;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .welcome-header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        .welcome-header p {
            font-size: 1.2em;
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
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            text-align: center;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }
        
        .stat-card i {
            font-size: 3em;
            margin-bottom: 15px;
            display: block;
        }
        
        .stat-card .production { color: #3498db; }
        .stat-card .quality { color: #2ecc71; }
        .stat-card .efficiency { color: #f39c12; }
        .stat-card .reports { color: #e74c3c; }
        
        .stat-card h3 {
            font-size: 2em;
            margin-bottom: 10px;
            color: #2c3e50;
        }
        
        .stat-card p {
            color: #7f8c8d;
            font-size: 1.1em;
        }
        
        .quick-actions {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }
        
        .quick-actions h2 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 1.8em;
        }
        
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .action-btn {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: #2c3e50;
            font-size: 1.1em;
            font-weight: 500;
        }
        
        .action-btn:hover {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .action-btn i {
            font-size: 1.5em;
        }
        
        .system-info {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            margin-top: 30px;
        }
        
        .system-info h2 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 1.8em;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .info-item {
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border-left: 4px solid #667eea;
        }
        
        .info-item h4 {
            color: #2c3e50;
            margin-bottom: 10px;
            font-size: 1.2em;
        }
        
        .info-item p {
            color: #7f8c8d;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <div class="welcome-header">
            <h1><i class="fas fa-tachometer-alt"></i> Dashboard Overview</h1>
            <p>Welcome to GEOCIL Automation System</p>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <i class="fas fa-cogs production"></i>
                <h3>1,247</h3>
                <p>Production Units</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-check-circle quality"></i>
                <h3>98.5%</h3>
                <p>Quality Score</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-chart-line efficiency"></i>
                <h3>87.3%</h3>
                <p>Efficiency Rate</p>
            </div>
            <div class="stat-card">
                <i class="fas fa-file-alt reports"></i>
                <h3>24</h3>
                <p>Reports Generated</p>
            </div>
        </div>
        
        <div class="quick-actions">
            <h2><i class="fas fa-bolt"></i> Quick Actions</h2>
            <div class="actions-grid">
                <a href="forms/fiber_entry_iframe.php" class="action-btn" target="main">
                    <i class="fas fa-boxes"></i>
                    <span>Fiber Entry</span>
                </a>
                <a href="forms/roll_entry_iframe.php" class="action-btn" target="main">
                    <i class="fas fa-dolly-flatbed"></i>
                    <span>Roll Entry</span>
                </a>
                <a href="forms/cnc_entry.php" class="action-btn" target="main">
                    <i class="fas fa-cut"></i>
                    <span>CNC Entry</span>
                </a>
                <a href="forms/scrap_entry.php" class="action-btn" target="main">
                    <i class="fas fa-recycle"></i>
                    <span>Scrap Entry</span>
                </a>
                <a href="forms/fg_entry.php" class="action-btn" target="main">
                    <i class="fas fa-box"></i>
                    <span>FG Entry</span>
                </a>
                <a href="forms/branding_entry.php" class="action-btn" target="main">
                    <i class="fas fa-stamp"></i>
                    <span>Branding Entry</span>
                </a>
                <a href="forms/fg_delivery_entry.php" class="action-btn" target="main">
                    <i class="fas fa-truck"></i>
                    <span>FG Delivery Entry</span>
                </a>
                <a href="forms/roll_received_entry.php" class="action-btn" target="main">
                    <i class="fas fa-clipboard-check"></i>
                    <span>Roll Received Entry</span>
                </a>
                <a href="forms/roll_transfer_entry.php" class="action-btn" target="main">
                    <i class="fas fa-truck-moving"></i>
                    <span>Roll Transfer Entry</span>
                </a>
                <a href="forms/cnc_machine_entry.php" class="action-btn" target="main">
                    <i class="fas fa-cut"></i>
                    <span>CNC Machine Entry</span>
                </a>
                <a href="forms/swing_machine_entry.php" class="action-btn" target="main">
                    <i class="fas fa-industry"></i>
                    <span>Swing Machine Entry</span>
                </a>
                <a href="forms/BOM_entry.php" class="action-btn" target="main">
                    <i class="fas fa-list"></i>
                    <span>BOM Entry</span>
                </a>
                <a href="forms/production_entry.php" class="action-btn" target="main">
                    <i class="fas fa-cogs"></i>
                    <span>Production Entry</span>
                </a>
                <a href="forms/project_entry.php" class="action-btn" target="main">
                    <i class="fas fa-project-diagram"></i>
                    <span>Project Entry</span>
                </a>
                <a href="forms/scrap_recycle_entry.php" class="action-btn" target="main">
                    <i class="fas fa-recycle"></i>
                    <span>Scrap Recycle Entry</span>
                </a>
                <a href="forms/target_entry.php" class="action-btn" target="main">
                    <i class="fas fa-bullseye"></i>
                    <span>Target Entry</span>
                </a>
            </div>
        </div>
        
        <div class="system-info">
            <h2><i class="fas fa-info-circle"></i> System Information</h2>
            <div class="info-grid">
                <div class="info-item">
                    <h4><i class="fas fa-database"></i> Database Status</h4>
                    <p>All systems operational. Database connection stable and performing optimally.</p>
                </div>
                <div class="info-item">
                    <h4><i class="fas fa-shield-alt"></i> Security Status</h4>
                    <p>Security protocols active. All user sessions are encrypted and monitored.</p>
                </div>
                <div class="info-item">
                    <h4><i class="fas fa-clock"></i> Last Update</h4>
                    <p>System last updated: <?php echo date('F j, Y \a\t g:i A'); ?></p>
                </div>
                <div class="info-item">
                    <h4><i class="fas fa-users"></i> Active Users</h4>
                    <p>Currently 3 users online. System capacity: 100 concurrent users.</p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>


