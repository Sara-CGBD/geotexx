<?php
session_start();
require_once '../config/security_config.php';

// Security/session checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}
if (SecurityConfig::checkSessionTimeout()) {
    session_destroy();
    header("Location: ../login.html?error=timeout");
    exit();
}
SecurityConfig::updateSessionActivity();
if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    session_destroy();
    header("Location: ../login.html?error=disabled");
    exit();
}

// Role-based access control - Only Admin can access
$allowed_roles = ['admin'];
$user_role = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>ðŸš« Access Denied</h2>
        <p>You do not have permission to access Production Cost Settings.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <p>Allowed roles: Admin</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// Database connection
$conn = SecurityConfig::getConnection();

$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_costs'])) {
    $labor_cost = floatval($_POST['labor_cost'] ?? 0);
    $utility_cost = floatval($_POST['utility_cost'] ?? 0);
    $overhead_cost = floatval($_POST['overhead_cost'] ?? 0);
    $user_id = $_SESSION['user_id'];
    
    // Update costs
    $update_query = "
        INSERT INTO production_cost_settings (cost_type, cost_per_unit, updated_by)
        VALUES 
            ('labor_cost', ?, ?),
            ('utility_cost', ?, ?),
            ('overhead_cost', ?, ?)
        ON DUPLICATE KEY UPDATE 
            cost_per_unit = VALUES(cost_per_unit),
            updated_by = VALUES(updated_by),
            updated_at = CURRENT_TIMESTAMP
    ";
    
    $stmt = $conn->prepare($update_query);
    $stmt->bind_param('dididi', $labor_cost, $user_id, $utility_cost, $user_id, $overhead_cost, $user_id);
    
    if ($stmt->execute()) {
        $message = 'Production cost settings updated successfully!';
        $message_type = 'success';
    } else {
        $message = 'Error updating settings: ' . $conn->error;
        $message_type = 'error';
    }
    $stmt->close();
}

// Get current settings
$settings_query = "SELECT * FROM production_cost_settings ORDER BY cost_type";
$settings_result = $conn->query($settings_query);
$settings = [];
while ($row = $settings_result->fetch_assoc()) {
    $settings[$row['cost_type']] = $row;
}

$labor = $settings['labor_cost']['cost_per_unit'] ?? 15;
$utility = $settings['utility_cost']['cost_per_unit'] ?? 5;
$overhead = $settings['overhead_cost']['cost_per_unit'] ?? 10;
$total = $labor + $utility + $overhead;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production Cost Settings - Geotex ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f4f6f9;
            padding: 20px;
            color: #2c3e50;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        h1 {
            color: #34495e;
            margin-bottom: 8px;
            font-size: 26px;
            text-align: center;
        }
        .subtitle {
            color: #7f8c8d;
            margin-bottom: 25px;
            font-size: 13px;
        }
        
        .top-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
            font-family: 'Inter', sans-serif;
        }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #229954; }
        .btn-secondary { background: #95a5a6; color: white; }
        .btn-secondary:hover { background: #7f8c8d; }
        
        .alert {
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-box {
            background: #34495e;
            color: white;
            padding: 18px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        .stat-box.labor { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .stat-box.utility { 
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .stat-box.overhead { 
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        .stat-box.total { 
            background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
        }
        .stat-box h3 { font-size: 28px; margin-bottom: 5px; }
        .stat-box p { font-size: 12px; opacity: 0.9; }
        
        .info-box {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #2196f3;
            font-size: 13px;
        }
        .info-box i { color: #2196f3; margin-right: 8px; }
        
        .settings-card {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .settings-card h3 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 18px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            background: white;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #e0e0e0;
        }
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #2c3e50;
            font-size: 13px;
        }
        .form-group label i {
            margin-right: 5px;
            color: #3498db;
        }
        .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            text-align: center;
            transition: all 0.3s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #3498db;
            background: #f0f8ff;
        }
        .form-group .help-text {
            font-size: 11px;
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        .save-section {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid #ecf0f1;
            text-align: right;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Production Cost Settings</h1>
    <p class="subtitle">Configure cost parameters for production cost calculations</p>

    <div class="top-actions">
        <a href="../index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <a href="../reports/production_cost_report.php" class="btn btn-primary">
            <i class="fas fa-chart-line"></i> View Report
        </a>
    </div>

    <?php if ($message && $message_type === 'success'): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($message); ?></span>
    </div>
    <?php elseif ($message && $message_type === 'error'): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo htmlspecialchars($message); ?></span>
    </div>
    <?php endif; ?>

    <div class="stats-row">
        <div class="stat-box labor">
            <h3>৳<?php echo number_format($labor, 2); ?></h3>
            <p>Labor Cost / Unit</p>
        </div>
        <div class="stat-box utility">
            <h3>৳<?php echo number_format($utility, 2); ?></h3>
            <p>Utility Cost / Unit</p>
        </div>
        <div class="stat-box overhead">
            <h3>৳<?php echo number_format($overhead, 2); ?></h3>
            <p>Overhead Cost / Unit</p>
        </div>
        <div class="stat-box total">
            <h3>৳<?php echo number_format($total, 2); ?></h3>
            <p>Total Cost / Unit</p>
        </div>
    </div>

    <div class="info-box">
        <i class="fas fa-info-circle"></i>
        <strong>Note:</strong> These costs are calculated per production unit (kg, piece, etc.) and are used in the Production Cost Report for financial analysis.
    </div>

    <form method="POST" action="">
        <div class="settings-card">
            <h3><i class="fas fa-sliders-h"></i> Cost Configuration</h3>
            
            <div class="form-row">
                <div class="form-group">
                    <label>
                        <i class="fas fa-users"></i> Labor Cost per Unit (৳)
                    </label>
                    <input 
                        type="number" 
                        name="labor_cost" 
                        value="<?php echo number_format($labor, 2, '.', ''); ?>" 
                        step="0.01" 
                        min="0"
                        required
                    >
                    <div class="help-text">Wages and employee benefits per unit</div>
                </div>

                <div class="form-group">
                    <label>
                        <i class="fas fa-bolt"></i> Utility Cost per Unit (৳)
                    </label>
                    <input 
                        type="number" 
                        name="utility_cost" 
                        value="<?php echo number_format($utility, 2, '.', ''); ?>" 
                        step="0.01" 
                        min="0"
                        required
                    >
                    <div class="help-text">Electricity, water, gas per unit</div>
                </div>

                <div class="form-group">
                    <label>
                        <i class="fas fa-building"></i> Overhead Cost per Unit (৳)
                    </label>
                    <input 
                        type="number" 
                        name="overhead_cost" 
                        value="<?php echo number_format($overhead, 2, '.', ''); ?>" 
                        step="0.01" 
                        min="0"
                        required
                    >
                    <div class="help-text">Rent, admin, maintenance, depreciation</div>
                </div>
            </div>
            
            <div class="save-section">
                <button type="submit" name="update_costs" class="btn btn-success" style="font-size: 15px; padding: 12px 30px;">
                    <i class="fas fa-save"></i> Save Cost Settings
                </button>
            </div>
        </div>
    </form>
</div>
</body>
</html>


