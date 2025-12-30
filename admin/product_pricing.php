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
        <p>You do not have permission to access Product Pricing Settings.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <p>Required roles: Admin, Finance</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// Database connection
$conn = SecurityConfig::getConnection();

// Add unit_price column to bag_size_master if it doesn't exist
$check_column = $conn->query("SHOW COLUMNS FROM bag_size_master LIKE 'unit_price'");
if ($check_column->num_rows == 0) {
    $conn->query("ALTER TABLE bag_size_master ADD COLUMN unit_price DECIMAL(10,2) DEFAULT 0 AFTER bag_capacity");
}

// Handle adding new bag configuration
$message = '';
$message_text = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_bag'])) {
    $bag_size = trim($_POST['bag_size']);
    $gsm = floatval($_POST['gsm']);
    $thickness = floatval($_POST['thickness']);
    $bag_capacity = trim($_POST['bag_capacity']);
    $unit_price = floatval($_POST['new_unit_price']);
    
    // Check if this configuration already exists
    $check = $conn->prepare("SELECT id FROM bag_size_master WHERE bag_size = ? AND gsm = ? AND thickness = ?");
    $check->bind_param('sdd', $bag_size, $gsm, $thickness);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows > 0) {
        $message = "error";
        $message_text = "This bag configuration already exists!";
    } else {
        $stmt = $conn->prepare("INSERT INTO bag_size_master (bag_size, gsm, thickness, bag_capacity, unit_price) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('sddsd', $bag_size, $gsm, $thickness, $bag_capacity, $unit_price);
        
        if ($stmt->execute()) {
            $message = "success";
            $message_text = "New bag configuration added successfully!";
        } else {
            $message = "error";
            $message_text = "Failed to add bag configuration.";
        }
        $stmt->close();
    }
    $check->close();
}

// Auto-fix BOM mismatches silently on page load
$auto_fixed = 0;
$bom_to_fix = $conn->query("SELECT id, bag_size, gsm, thickness_mm FROM bom WHERE is_deleted = 0 AND bag_size NOT LIKE '%mm%'");

if ($bom_to_fix && $bom_to_fix->num_rows > 0) {
    while ($bom = $bom_to_fix->fetch_assoc()) {
        // Try to find matching bag in bag_size_master based on GSM and thickness
        $find_stmt = $conn->prepare("SELECT bag_size FROM bag_size_master WHERE gsm = ? AND ABS(thickness - ?) < 0.01 LIMIT 1");
        $find_stmt->bind_param('dd', $bom['gsm'], $bom['thickness_mm']);
        $find_stmt->execute();
        $find_result = $find_stmt->get_result();
        
        if ($find_result->num_rows > 0) {
            $match = $find_result->fetch_assoc();
            $correct_bag_size = $match['bag_size'];
            
            // Update BOM with correct bag size
            $update_stmt = $conn->prepare("UPDATE bom SET bag_size = ? WHERE id = ?");
            $update_stmt->bind_param('si', $correct_bag_size, $bom['id']);
            if ($update_stmt->execute()) {
                $auto_fixed++;
            }
            $update_stmt->close();
        }
        $find_stmt->close();
    }
    
    // Redirect to refresh the page if we fixed anything
    if ($auto_fixed > 0 && !isset($_POST['update_prices']) && !isset($_POST['add_bag'])) {
        header("Location: product_pricing.php");
        exit();
    }
}

// Clear unit prices for bags that don't have BOM costs
// This ensures you can't set a selling price without knowing the cost first
$conn->query("
    UPDATE bag_size_master bsm
    LEFT JOIN bom b ON TRIM(bsm.bag_size) = TRIM(b.bag_size) 
                    AND ABS(bsm.gsm - b.gsm) < 0.01
                    AND ABS(bsm.thickness - b.thickness_mm) < 0.01
                    AND b.is_deleted = 0
    SET bsm.unit_price = 0
    WHERE b.id IS NULL AND bsm.unit_price > 0
");

// Handle form submission - Update prices
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_prices'])) {
    $success_count = 0;
    
    foreach ($_POST['unit_price'] as $bag_id => $unit_price) {
        $unit_price = floatval($unit_price);
        
        $stmt = $conn->prepare("UPDATE bag_size_master SET unit_price = ? WHERE id = ?");
        $stmt->bind_param('di', $unit_price, $bag_id);
        
        if ($stmt->execute()) {
            $success_count++;
        }
        $stmt->close();
    }
    
    $message = "success";
    $message_text = "Successfully updated pricing for $success_count bag configuration(s)!";
}

// Fetch all bag configurations with BOM costs
$bags = [];
$query = "SELECT 
            bsm.id, 
            bsm.bag_size, 
            bsm.gsm, 
            bsm.thickness, 
            bsm.bag_capacity, 
            bsm.unit_price,
            b.id as bom_id,
            b.bag_size as bom_bag_size,
            b.gsm as bom_gsm,
            b.thickness_mm as bom_thickness,
            COALESCE(b.cost, 0) as bom_cost,
            COALESCE(b.unit_price, 0) as bom_unit_price
          FROM bag_size_master bsm
          LEFT JOIN bom b ON TRIM(bsm.bag_size) = TRIM(b.bag_size) 
                          AND ABS(bsm.gsm - b.gsm) < 0.01
                          AND ABS(bsm.thickness - b.thickness_mm) < 0.01
                          AND b.is_deleted = 0
          WHERE bsm.bag_size IS NOT NULL AND bsm.bag_size != '' 
          ORDER BY bsm.bag_size ASC, bsm.gsm DESC, bsm.thickness DESC";
$result = $conn->query($query);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $bags[] = $row;
    }
}

$total_configs = count($bags);
$priced_configs = count(array_filter($bags, fn($b) => $b['unit_price'] > 0));
$with_bom = count(array_filter($bags, fn($b) => $b['bom_cost'] > 0));

// Debug: Check if any BOM entries exist
$debug_bom = $conn->query("SELECT COUNT(*) as count FROM bom WHERE is_deleted = 0")->fetch_assoc();
$total_bom_entries = $debug_bom['count'] ?? 0;

// Get BOM entries to show what's in the database
$bom_entries = [];
$bom_list = $conn->query("SELECT id, bag_size, gsm, thickness_mm, cost, unit_price FROM bom WHERE is_deleted = 0 ORDER BY id DESC LIMIT 10");
if ($bom_list) {
    while ($row = $bom_list->fetch_assoc()) {
        $bom_entries[] = $row;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Pricing Settings - Geotex ERP</title>
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
        
        .btn-group {
            display: flex;
            gap: 8px;
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
        .btn-warning { background: #f39c12; color: white; }
        .btn-warning:hover { background: #e67e22; }
        
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-box.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-box h3 { font-size: 28px; margin-bottom: 5px; }
        .stat-box p { font-size: 12px; opacity: 0.9; }
        
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
            padding: 10px;
            border-bottom: 1px solid #ecf0f1;
        }
        tbody tr:hover {
            background-color: #f8f9fa;
        }
        tbody tr:nth-child(even) {
            background-color: #fafafa;
        }
        
        .price-input {
            width: 130px;
            padding: 8px 10px;
            border: 2px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            text-align: right;
        }
        .price-input:focus {
            outline: none;
            border-color: #3498db;
            background: #f0f8ff;
        }
        
        .form-footer {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid #ecf0f1;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .add-config-section {
            background: #e8f5e9;
            padding: 20px;
            border-radius: 8px;
            margin-top: 25px;
            border-left: 4px solid #27ae60;
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
    <h1>Product Pricing Settings</h1>
    <p class="subtitle">Geo-Textile Bag Size Pricing Configuration</p>

    <div class="top-actions">
        <div class="btn-group">
            <a href="../index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <a href="../reports/profitability_report.php" class="btn btn-primary">
                <i class="fas fa-chart-line"></i> Profitability Report
            </a>
        </div>
    </div>

    <?php if ($message === 'success'): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <span><?php echo $message_text; ?></span>
    </div>
    <?php elseif ($message === 'error'): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <span><?php echo $message_text; ?></span>
    </div>
    <?php endif; ?>

    <div class="stats-row">
        <div class="stat-box">
            <h3><?php echo $total_configs; ?></h3>
            <p>Total Configurations</p>
        </div>
        <div class="stat-box green">
            <h3><?php echo $priced_configs; ?></h3>
            <p>Priced Items</p>
        </div>
        <div class="stat-box" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
            <h3><?php echo $with_bom; ?> / <?php echo $total_bom_entries; ?></h3>
            <p>With BOM Cost / Total BOM</p>
        </div>
    </div>
    

    <div style="background: #e3f2fd; padding: 15px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #2196f3; font-size: 13px;">
        <i class="fas fa-info-circle" style="color: #2196f3; margin-right: 8px;"></i>
        <strong>How to Set Prices:</strong> Enter your <strong>Unit Price</strong> (selling price). The <span style="background: #fff3cd; padding: 2px 6px; border-radius: 3px; font-weight: 600;">BOM Cost</span> column shows material cost from BOM Entry. The <strong>Profit</strong> column automatically calculates: Unit Price - BOM Cost.
    </div>
    
    <?php if ($with_bom > 0): ?>
    <div style="background: #d4edda; padding: 12px; border-radius: 6px; margin-bottom: 20px; border-left: 4px solid #28a745; font-size: 13px;">
        <i class="fas fa-check-circle" style="color: #155724; margin-right: 8px;"></i>
        <strong>Success!</strong> <?php echo $with_bom; ?> bag configuration(s) have BOM costs linked.
    </div>
    <?php endif; ?>

    <form method="POST" action="">
        <table>
                <thead>
                    <tr>
                        <th style="width: 50px;">SL</th>
                        <th>Bag Size</th>
                        <th style="width: 100px;">Thickness</th>
                        <th style="width: 80px;">GSM</th>
                        <th style="width: 120px;">Capacity</th>
                        <th style="width: 150px;">Unit Price (৳)</th>
                        <th style="width: 130px;">BOM Cost (৳)</th>
                        <th style="width: 100px;">Profit (৳)</th>
                    </tr>
                </thead>
            <tbody>
                <?php 
                if (empty($bags)): 
                ?>
                    <tr>
                        <td colspan="6" class="no-data">
                            <i class="fas fa-inbox"></i>
                            <p>No bag configurations found</p>
                        </td>
                    </tr>
                <?php 
                else:
                    $counter = 1;
                    foreach ($bags as $bag): 
                ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><strong><?php echo htmlspecialchars($bag['bag_size']); ?></strong></td>
                        <td><?php echo htmlspecialchars($bag['thickness']); ?> mm</td>
                        <td><?php echo htmlspecialchars($bag['gsm']); ?></td>
                        <td><?php echo htmlspecialchars($bag['bag_capacity']); ?></td>
                        <td>
                            <?php 
                            $bom_cost = $bag['bom_cost'] ?? 0;
                            $has_bom = $bom_cost > 0;
                            ?>
                            <input 
                                type="number" 
                                name="unit_price[<?php echo $bag['id']; ?>]" 
                                value="<?php echo $bag['unit_price'] > 0 ? number_format($bag['unit_price'], 2, '.', '') : ''; ?>" 
                                step="0.01" 
                                min="0"
                                placeholder="<?php echo $has_bom ? 'Enter price' : 'BOM cost required'; ?>"
                                class="price-input"
                                data-bom-cost="<?php echo $bom_cost; ?>"
                                onchange="calculateProfit(this)"
                                <?php echo !$has_bom ? 'disabled style="background: #f0f0f0; cursor: not-allowed; border-color: #ccc;"' : ''; ?>
                            >
                            <?php if (!$has_bom): ?>
                            <small style="color: #e74c3c; font-size: 10px; display: block; margin-top: 2px;">Create BOM first</small>
                            <?php endif; ?>
                        </td>
                        <td style="background: #fff3cd; font-weight: 600; color: #856404;">
                            <?php 
                            $bom_cost = $bag['bom_cost'] ?? 0;
                            $bom_id = $bag['bom_id'] ?? null;
                            if ($bom_cost > 0) {
                                echo '৳' . number_format($bom_cost, 2);
                                echo '<br><small style="color: #666; font-size: 10px;">BOM #' . $bom_id . '</small>';
                            } else {
                                echo '<span style="color: #95a5a6; font-size: 11px;">-</span>';
                            }
                            ?>
                        </td>
                        <td class="profit-cell" style="font-weight: 600; text-align: center;">
                            <?php 
                            $profit = $bag['unit_price'] - $bom_cost;
                            if ($bag['unit_price'] > 0) {
                                $color = $profit > 0 ? '#27ae60' : '#e74c3c';
                                echo "<span style='color: $color;'>৳" . number_format($profit, 2) . "</span>";
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                    </tr>
                <?php 
                    endforeach;
                endif; 
                ?>
            </tbody>
        </table>

        <?php if (!empty($bags)): ?>
        <div class="form-footer">
            <div style="color: #7f8c8d; font-size: 13px;">
                <i class="fas fa-info-circle"></i> Enter unit prices for each bag configuration
            </div>
            <button type="submit" name="update_prices" class="btn btn-success" style="font-size: 15px; padding: 12px 30px;">
                <i class="fas fa-save"></i> Save All Prices
            </button>
        </div>
        <?php endif; ?>
    </form>

    <div class="add-config-section">
        <h3 style="margin-bottom: 15px; color: #27ae60;"><i class="fas fa-plus-circle"></i> Add New Bag Configuration</h3>
        
        <form method="POST" action="" style="background: white; padding: 20px; border-radius: 6px;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: 600; color: #555;">
                        Bag Size <span style="color: red;">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="bag_size" 
                        placeholder="e.g., 1200mmX950mm"
                        required
                        style="width: 100%; padding: 8px 10px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                    >
                </div>
                
                <div>
                    <label style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: 600; color: #555;">
                        GSM <span style="color: red;">*</span>
                    </label>
                    <input 
                        type="number" 
                        name="gsm" 
                        placeholder="e.g., 450"
                        step="0.01"
                        min="0"
                        required
                        style="width: 100%; padding: 8px 10px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                    >
                </div>
                
                <div>
                    <label style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: 600; color: #555;">
                        Thickness (mm) <span style="color: red;">*</span>
                    </label>
                    <input 
                        type="number" 
                        name="thickness" 
                        placeholder="e.g., 3.3"
                        step="0.01"
                        min="0"
                        required
                        style="width: 100%; padding: 8px 10px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                    >
                </div>
                
                <div>
                    <label style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: 600; color: #555;">
                        Bag Capacity <span style="color: red;">*</span>
                    </label>
                    <input 
                        type="text" 
                        name="bag_capacity" 
                        placeholder="e.g., 250 kg"
                        required
                        style="width: 100%; padding: 8px 10px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                    >
                </div>
                
                <div>
                    <label style="display: block; margin-bottom: 5px; font-size: 12px; font-weight: 600; color: #555;">
                        Unit Price (৳)
                    </label>
                    <input 
                        type="number" 
                        name="new_unit_price" 
                        placeholder="0.00"
                        step="0.01"
                        min="0"
                        value="0"
                        style="width: 100%; padding: 8px 10px; border: 2px solid #ddd; border-radius: 4px; font-size: 13px;"
                    >
                </div>
            </div>
            
            <button type="submit" name="add_bag" class="btn btn-success">
                <i class="fas fa-plus"></i> Add Configuration
            </button>
        </form>
    </div>
</div>

<script>
// Form change detection
let formChanged = false;
const inputs = document.querySelectorAll('.price-input');
inputs.forEach(input => {
    input.addEventListener('input', () => {
        formChanged = true;
    });
});

window.addEventListener('beforeunload', (e) => {
    if (formChanged) {
        e.preventDefault();
        e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
    }
});

document.querySelector('form').addEventListener('submit', () => {
    formChanged = false;
});

// Calculate profit in real-time
function calculateProfit(input) {
    const row = input.closest('tr');
    const profitCell = row.querySelector('.profit-cell');
    const unitPrice = parseFloat(input.value) || 0;
    const bomCost = parseFloat(input.dataset.bomCost) || 0;
    const profit = unitPrice - bomCost;
    
    if (unitPrice > 0) {
        const color = profit > 0 ? '#27ae60' : '#e74c3c';
        profitCell.innerHTML = `<span style="color: ${color};">৳${profit.toFixed(2)}</span>`;
    } else {
        profitCell.textContent = '-';
    }
}

// Auto-refresh when bag added from BOM Entry
(function() {
    // Check when page becomes visible
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && localStorage.getItem('bagSizeAddedFromBOM') === 'true') {
            localStorage.removeItem('bagSizeAddedFromBOM');
            location.reload();
        }
    });
    
    // Also check on window focus
    window.addEventListener('focus', function() {
        if (localStorage.getItem('bagSizeAddedFromBOM') === 'true') {
            localStorage.removeItem('bagSizeAddedFromBOM');
            location.reload();
        }
    });
    
    // Check on page load as well
    if (localStorage.getItem('bagSizeAddedFromBOM') === 'true') {
        localStorage.removeItem('bagSizeAddedFromBOM');
        // Show notification
        const notification = document.createElement('div');
        notification.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #27ae60; color: white; padding: 15px 20px; border-radius: 5px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 9999; font-size: 14px;';
        notification.innerHTML = '<i class="fas fa-check-circle"></i> New bag configuration added from BOM Entry!';
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.style.transition = 'opacity 0.3s';
            notification.style.opacity = '0';
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }
})();
</script>
</body>
</html>


