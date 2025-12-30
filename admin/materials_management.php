<?php
session_start();
require_once '../config/security_config.php';

// Security checks
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

// Role-based access control - Admin and Finance only
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'finance'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>ðŸš« Access Denied</h2>
        <p>You do not have permission to access Materials Management.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <p>Required roles: Admin, Finance</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// Database connection
$conn = SecurityConfig::getConnection();

// Handle adding new material
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_material'])) {
    $material_name = trim($_POST['material_name']);
    $price_per_unit = floatval($_POST['price_per_unit']);
    
    // Check if material already exists
    $check = $conn->prepare("SELECT id FROM materials WHERE LOWER(TRIM(material_name)) = LOWER(TRIM(?)) AND is_deleted = 0");
    $check->bind_param('s', $material_name);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows > 0) {
        $message = "error";
        $message_text = "Material already exists!";
    } else {
        $stmt = $conn->prepare("INSERT INTO materials (material_name, price_per_unit, is_deleted) VALUES (?, ?, 0)");
        $stmt->bind_param('sd', $material_name, $price_per_unit);
        
        if ($stmt->execute()) {
            $message = "success";
            $message_text = "Material added successfully!";
            
            // Trigger refresh in other tabs
            echo "<script>localStorage.setItem('materialAdded', Date.now());</script>";
        } else {
            $message = "error";
            $message_text = "Failed to add material: " . $stmt->error;
        }
        $stmt->close();
    }
    $check->close();
}

// Handle updating prices
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_prices'])) {
    $prices = $_POST['price_per_unit'] ?? [];
    $updated = 0;
    
    $stmt = $conn->prepare("UPDATE materials SET price_per_unit = ? WHERE id = ? AND is_deleted = 0");
    
    foreach ($prices as $material_id => $price) {
        $price = floatval($price);
        $material_id = intval($material_id);
        
        $stmt->bind_param('di', $price, $material_id);
        if ($stmt->execute()) {
            $updated++;
        }
    }
    $stmt->close();
    
    if ($updated > 0) {
        $message = "success";
        $message_text = "Updated pricing for $updated material(s)!";
    }
}

// Handle soft delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $material_id = intval($_GET['delete']);
    $stmt = $conn->prepare("UPDATE materials SET is_deleted = 1 WHERE id = ?");
    $stmt->bind_param('i', $material_id);
    
    if ($stmt->execute()) {
        header("Location: materials_management.php?deleted=1");
        exit();
    }
    $stmt->close();
}

// Fetch all materials
$query = "SELECT 
            id, 
            material_name, 
            price_per_unit
          FROM materials 
          WHERE is_deleted = 0 
          ORDER BY material_name ASC";

$materials = [];
$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $materials[] = $row;
    }
}

// Get statistics
$total_materials = count($materials);
$materials_without_price = 0;
$total_value = 0;

foreach ($materials as $mat) {
    if ($mat['price_per_unit'] == 0) {
        $materials_without_price++;
    }
    $total_value += $mat['price_per_unit'];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Materials Management - Geotex ERP</title>
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
            max-width: 1400px;
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
            display: flex;
            align-items: center;
            gap: 10px;
            justify-content: center;
        }
        h1 i { color: #3498db; }
        .subtitle {
            color: #7f8c8d;
            margin-bottom: 25px;
            font-size: 13px;
            text-align: center;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(102,126,234,0.4);
        }
        .stat-card.warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .stat-card.success {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        .stat-label {
            font-size: 12px;
            opacity: 0.9;
            margin-bottom: 8px;
        }
        .stat-value {
            font-size: 28px;
            font-weight: 600;
        }
        
        .top-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .btn-group {
            display: flex;
            gap: 8px;
        }
        
        .btn {
            padding: 10px 18px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #3498db;
            color: white;
        }
        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(52,152,219,0.4);
        }
        .btn-success {
            background: #27ae60;
            color: white;
        }
        .btn-success:hover {
            background: #229954;
        }
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .add-form {
            background: #e8f5e9;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
            border: 2px solid #27ae60;
        }
        .add-form h3 {
            color: #27ae60;
            margin-bottom: 15px;
            font-size: 16px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 2fr 1.5fr auto;
            gap: 10px;
            align-items: end;
        }
        .form-field {
            display: flex;
            flex-direction: column;
        }
        .form-field label {
            font-size: 12px;
            color: #555;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .form-field input, .form-field select {
            padding: 10px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        .form-field input:focus, .form-field select:focus {
            outline: none;
            border-color: #27ae60;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 13px;
        }
        th {
            background: #34495e;
            color: white;
            padding: 12px 10px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        th:first-child { border-radius: 8px 0 0 0; }
        th:last-child { border-radius: 0 8px 0 0; }
        
        td {
            padding: 12px 10px;
            border-bottom: 1px solid #ecf0f1;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .price-input {
            width: 100%;
            padding: 8px 12px;
            border: 2px solid #3498db;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            background: white;
        }
        .price-input:focus {
            outline: none;
            border-color: #2980b9;
            box-shadow: 0 0 0 3px rgba(52,152,219,0.1);
        }
        .no-price {
            background: #fff3cd !important;
        }
        
        .message {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .delete-btn {
            background: none;
            border: none;
            color: #e74c3c;
            cursor: pointer;
            padding: 5px 10px;
            border-radius: 4px;
            transition: all 0.3s;
        }
        .delete-btn:hover {
            background: #fee;
            color: #c0392b;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            table {
                font-size: 11px;
            }
            td, th {
                padding: 8px 5px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <h1><i class="fas fa-boxes"></i> Materials Management</h1>
    <p class="subtitle">Manage raw material prices for cost calculations</p>
    
    <?php if (isset($_GET['deleted'])): ?>
    <div class="message success">
        <i class="fas fa-check-circle"></i> Material deleted successfully!
    </div>
    <?php endif; ?>
    
    <?php if (isset($message)): ?>
    <div class="message <?php echo $message; ?>">
        <i class="fas fa-<?php echo $message === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo $message_text; ?>
    </div>
    <?php endif; ?>
    
    <div class="stats">
        <div class="stat-card">
            <div class="stat-label">Total Materials</div>
            <div class="stat-value"><?php echo $total_materials; ?></div>
        </div>
        <div class="stat-card warning">
            <div class="stat-label">Without Price Set</div>
            <div class="stat-value"><?php echo $materials_without_price; ?></div>
        </div>
        <div class="stat-card success">
            <div class="stat-label">Avg Material Price</div>
            <div class="stat-value">à§³<?php echo $total_materials > 0 ? number_format($total_value / $total_materials, 2) : '0.00'; ?></div>
        </div>
    </div>
    
    <!-- Add New Material Form -->
    <div class="add-form">
        <h3><i class="fas fa-plus-circle"></i> Add New Material</h3>
        <form method="POST">
            <div class="form-row">
                <div class="form-field">
                    <label>Material Name <span style="color: red;">*</span></label>
                    <input type="text" name="material_name" placeholder="e.g., PP Stable Fiber (Natpet)" required>
                </div>
                <div class="form-field">
                    <label>Price per kg (à§³) <span style="color: red;">*</span></label>
                    <input type="number" name="price_per_unit" step="0.01" min="0" placeholder="0.00" required>
                </div>
                <button type="submit" name="add_material" class="btn btn-success">
                    <i class="fas fa-plus"></i> Add Material
                </button>
            </div>
        </form>
    </div>
    
    <div class="top-actions">
        <a href="../index.php" class="btn btn-danger">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <div class="btn-group">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>
    
    <?php if ($materials_without_price > 0): ?>
    <div class="message error">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>Warning:</strong> <?php echo $materials_without_price; ?> material(s) have no price set. This will cause â‚¹0.00 costs in reports!
    </div>
    <?php endif; ?>
    
    <form method="POST">
        <table>
            <thead>
                <tr>
                    <th style="width: 50px;">SL No</th>
                    <th>Material Name</th>
                    <th style="width: 180px;">Price per kg (à§³)</th>
                    <th style="width: 80px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($materials)): ?>
                <tr>
                    <td colspan="4" style="text-align: center; padding: 30px; color: #95a5a6;">
                        <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 10px;"></i>
                        <p>No materials found. Add your first material above!</p>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach($materials as $index => $material): ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td>
                        <strong><?php echo htmlspecialchars($material['material_name']); ?></strong>
                        <?php if ($material['price_per_unit'] == 0): ?>
                        <br><small style="color: #e74c3c; font-size: 10px;">âš  Price not set</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <input 
                            type="number" 
                            name="price_per_unit[<?php echo $material['id']; ?>]" 
                            value="<?php echo $material['price_per_unit'] > 0 ? number_format($material['price_per_unit'], 2, '.', '') : ''; ?>" 
                            step="0.01" 
                            min="0"
                            placeholder="Enter price"
                            class="price-input <?php echo $material['price_per_unit'] == 0 ? 'no-price' : ''; ?>"
                        >
                    </td>
                    <td>
                        <button 
                            type="button" 
                            class="delete-btn" 
                            onclick="if(confirm('Delete this material?')) window.location.href='?delete=<?php echo $material['id']; ?>'"
                            title="Delete Material"
                        >
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php if (!empty($materials)): ?>
        <div style="margin-top: 20px; text-align: right;">
            <button type="submit" name="update_prices" class="btn btn-success" style="font-size: 15px; padding: 12px 30px;">
                <i class="fas fa-save"></i> Save All Prices
            </button>
        </div>
        <?php endif; ?>
    </form>
</div>

<script>
// Auto-refresh when material is added in other tabs
window.addEventListener('storage', function(e) {
    if (e.key === 'materialAdded') {
        location.reload();
    }
});

// Trigger localStorage event when adding material
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form[method="POST"]');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (e.submitter && e.submitter.name === 'add_material') {
                localStorage.setItem('materialAdded', Date.now());
            }
        });
    }
});
</script>

</body>
</html>



