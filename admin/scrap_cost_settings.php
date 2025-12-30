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

// Only Admin can access
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if ($user_role !== 'admin') {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>ðŸš« Access Denied</h2>
        <p>Only Admins can access Scrap Cost Settings.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// Database connection
$conn = SecurityConfig::getConnection();

// Create scrap_type_costs table if not exists
$createTable = "
CREATE TABLE IF NOT EXISTS scrap_type_costs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    scrap_type VARCHAR(100) NOT NULL,
    scrap_product VARCHAR(100),
    cost_per_kg DECIMAL(10,2) NOT NULL DEFAULT 50.00,
    salvage_percentage DECIMAL(5,2) NOT NULL DEFAULT 30.00,
    updated_by INT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_scrap(scrap_type, scrap_product)
)";
$conn->query($createTable);

$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    $saved_count = 0;
    
    foreach ($_POST['scrap_data'] as $key => $data) {
        $scrap_type = $data['scrap_type'] ?? '';
        $scrap_product = $data['scrap_product'] ?? '';
        $cost_per_kg = floatval($data['cost_per_kg'] ?? 50);
        $salvage_percentage = 30.00;
        
        // Check if entry exists
        $check = $conn->prepare("SELECT id FROM scrap_type_costs WHERE scrap_type = ? AND scrap_product = ?");
        $check->bind_param('ss', $scrap_type, $scrap_product);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
        
        if ($exists) {
            $stmt = $conn->prepare("UPDATE scrap_type_costs SET cost_per_kg = ?, salvage_percentage = ?, updated_by = ? WHERE scrap_type = ? AND scrap_product = ?");
            $stmt->bind_param('ddiss', $cost_per_kg, $salvage_percentage, $_SESSION['user_id'], $scrap_type, $scrap_product);
        } else {
            $stmt = $conn->prepare("INSERT INTO scrap_type_costs (scrap_type, scrap_product, cost_per_kg, salvage_percentage, updated_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('ssddi', $scrap_type, $scrap_product, $cost_per_kg, $salvage_percentage, $_SESSION['user_id']);
        }
        
        if ($stmt->execute()) {
            $saved_count++;
        }
        $stmt->close();
    }
    
    $message = "Successfully saved costs for $saved_count scrap type(s)!";
    $message_type = 'success';
}

// Get all scrap types from database
$query = "
    SELECT 
        s.scrap_type,
        s.scrap_product,
        SUM(s.qty) as total_qty,
        COALESCE(stc.cost_per_kg, 50.00) as cost_per_kg
    FROM scrap s
    LEFT JOIN scrap_type_costs stc ON s.scrap_type = stc.scrap_type AND s.scrap_product = stc.scrap_product
    WHERE s.scrap_type IS NOT NULL AND s.scrap_type != ''
    GROUP BY s.scrap_type, s.scrap_product, stc.cost_per_kg
    ORDER BY s.scrap_type, s.scrap_product
";
$result = $conn->query($query);
$scrap_items = [];
while ($row = $result->fetch_assoc()) {
    $scrap_items[] = $row;
}

$total_scrap_types = count($scrap_items);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scrap Cost Settings - Geotex ERP</title>
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
            max-width: 1200px;
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
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-box {
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            color: white;
            padding: 18px;
            border-radius: 8px;
            text-align: center;
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
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 13px;
        }
        thead {
            background: #34495e;
            color: white;
        }
        th {
            padding: 12px 10px;
            text-align: left;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        td {
            padding: 12px 10px;
            border-bottom: 1px solid #ecf0f1;
        }
        tbody tr:hover {
            background-color: #f8f9fa;
        }
        tbody tr:nth-child(even) {
            background-color: #fafafa;
        }
        
        .cost-input {
            width: 130px;
            padding: 8px 10px;
            border: 2px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            font-family: 'Inter', sans-serif;
        }
        .cost-input:focus {
            outline: none;
            border-color: #3498db;
            background: #f0f8ff;
        }
        
        .save-section {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid #ecf0f1;
            text-align: right;
        }
        
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #95a5a6;
        }
        .no-data i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Scrap Cost Settings</h1>
    <p class="subtitle">Configure material cost per kg for scrap types</p>

    <div class="top-actions">
        <a href="../index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
        <a href="../reports/scrap_loss_report.php" class="btn btn-primary">
            <i class="fas fa-chart-line"></i> View Scrap Loss Report
        </a>
    </div>

    <?php if ($message && $message_type === 'success'): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <span><?php echo htmlspecialchars($message); ?></span>
    </div>
    <?php endif; ?>

    <div class="stats-row">
        <div class="stat-box">
            <h3><?php echo $total_scrap_types; ?></h3>
            <p>Scrap Types Configured</p>
        </div>
    </div>

    <div class="info-box">
        <i class="fas fa-info-circle"></i>
        <strong>Note:</strong> Set the material cost per kg for each scrap type. These costs are used in the Scrap Loss Report to calculate financial impact of waste.
    </div>

    <?php if (empty($scrap_items)): ?>
        <div class="no-data">
            <i class="fas fa-inbox"></i>
            <h3 style="margin-bottom: 10px;">No Scrap Data Found</h3>
            <p>Scrap entries will appear here once you start recording scrap data.</p>
        </div>
    <?php else: ?>
        <form method="POST" action="">
            <table>
                <thead>
                    <tr>
                        <th style="width: 50px;">SL</th>
                        <th>Scrap Type</th>
                        <th>Scrap Product</th>
                        <th style="width: 150px;">Total Quantity (kg)</th>
                        <th style="width: 180px;">Cost per kg (à§³)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    foreach ($scrap_items as $index => $item): 
                    ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td><strong><?php echo htmlspecialchars($item['scrap_type'] ?? 'N/A'); ?></strong></td>
                            <td><?php echo htmlspecialchars($item['scrap_product'] ?? 'N/A'); ?></td>
                            <td style="font-weight: 600;"><?php echo number_format($item['total_qty'], 2); ?> kg</td>
                            <td>
                                <input 
                                    type="number" 
                                    name="scrap_data[<?php echo $index; ?>][cost_per_kg]" 
                                    value="<?php echo number_format($item['cost_per_kg'], 2, '.', ''); ?>" 
                                    step="0.01" 
                                    min="0" 
                                    placeholder="0.00"
                                    class="cost-input"
                                    required
                                >
                                <input type="hidden" name="scrap_data[<?php echo $index; ?>][scrap_type]" value="<?php echo htmlspecialchars($item['scrap_type']); ?>">
                                <input type="hidden" name="scrap_data[<?php echo $index; ?>][scrap_product]" value="<?php echo htmlspecialchars($item['scrap_product']); ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="save-section">
                <button type="submit" name="submit" class="btn btn-success" style="font-size: 15px; padding: 12px 30px;">
                    <i class="fas fa-save"></i> Save All Cost Settings
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>
</body>
</html>


