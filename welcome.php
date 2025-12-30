<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}

$role = strtolower(trim($_SESSION['role'] ?? 'user'));

// Define role-specific content
$roleConfig = [
    'production_user' => [
        'title' => 'Production Department',
        'badge' => 'Production User',
        'icon' => 'fas fa-cogs',
        'access' => 'Roll Production, Production, Scrap, and Recycle modules'
    ],
    'qc_inspector' => [
        'title' => 'Quality Control Department',
        'badge' => 'QC Inspector',
        'icon' => 'fas fa-clipboard-check',
        'access' => 'All QC forms and inspections'
    ],
    'tester' => [
        'title' => 'Laboratory Testing',
        'badge' => 'Lab Tester',
        'icon' => 'fas fa-flask',
        'access' => 'Lab testing forms (Sewing Thread, UV, Fiber, Fabric, Sun tests)'
    ],
    'checker' => [
        'title' => 'Quality Checking',
        'badge' => 'Quality Checker',
        'icon' => 'fas fa-check-circle',
        'access' => 'Fabric Pre-Production Test verification'
    ],
    'agm ops' => [
        'title' => 'Operations Management',
        'badge' => 'AGM Operations',
        'icon' => 'fas fa-user-tie',
        'access' => 'QC approval and Production monitoring'
    ],
    'finance_user' => [
        'title' => 'Finance Department',
        'badge' => 'Finance User',
        'icon' => 'fas fa-dollar-sign',
        'access' => 'Finance module, BOM management, and Costing'
    ],
    'planning_user' => [
        'title' => 'Planning Department',
        'badge' => 'Planning User',
        'icon' => 'fas fa-bullseye',
        'access' => 'Target setting, Project planning, and BOM entry'
    ],
    'management' => [
        'title' => 'Management',
        'badge' => 'Management',
        'icon' => 'fas fa-chart-line',
        'access' => 'All reports and analytics (view only)'
    ],
    'user' => [
        'title' => 'User',
        'badge' => 'General User',
        'icon' => 'fas fa-user',
        'access' => 'Basic system access'
    ]
];

// Get current role config or default
$config = $roleConfig[$role] ?? $roleConfig['user'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .welcome-container {
            text-align: center;
            max-width: 600px;
        }
        
        .welcome-box {
            background: white;
            padding: 60px 40px;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        }
        
        .logo {
            margin-bottom: 20px;
            animation: float 3s ease-in-out infinite;
        }
        
        .logo img {
            max-width: 300px;
            height: auto;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        h1 {
            color: #2c3e50;
            font-size: 2.5em;
            margin-bottom: 15px;
            font-weight: 700;
        }
        
        .subtitle {
            color: #7f8c8d;
            font-size: 1.2em;
            margin-bottom: 30px;
        }
        
        .role-badge {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border-radius: 30px;
            font-weight: 600;
            margin-top: 20px;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        
        .instruction {
            margin-top: 40px;
            padding: 20px;
            background: #ecf0f1;
            border-radius: 10px;
            color: #34495e;
        }
        
        .instruction i {
            font-size: 1.5em;
            color: #3498db;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="welcome-container">
        <div class="welcome-box">
            <div class="logo">
                <img src="assets/images/logo.jpg" alt="GEOCIL Logo">
            </div>
            <h1>Welcome to Automation</h1>
            <p class="subtitle"><?php echo htmlspecialchars($config['title']); ?></p>
            
            <div class="role-badge">
                <i class="<?php echo htmlspecialchars($config['icon']); ?>"></i> <?php echo htmlspecialchars($config['badge']); ?>
            </div>
            
            <div class="instruction">
                <i class="fas fa-arrow-left"></i>
                <p><strong>Select a menu item from the sidebar to begin</strong></p>
                <p style="margin-top: 15px; font-size: 0.95em; color: #7f8c8d;">You have access to: <?php echo htmlspecialchars($config['access']); ?></p>
            </div>
        </div>
    </div>
</body>
</html>


