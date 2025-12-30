<?php
session_start();
require_once '../config/security_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Role-based access control - Finance roles can view
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'finance', 'finance_user', 'management'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access Roll Production Price Report.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Get filter parameters
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$referenceNumber = $_GET['reference_number'] ?? '';
$materialType = $_GET['material_type'] ?? '';
$projectId = $_GET['project_id'] ?? '';
$shift = $_GET['shift'] ?? '';

// Build query for roll_entry table with calculated shift
// Note: Pricing can be added later when roll_pricing table is created
$query = "SELECT 
    re.*,
    u.username as operator_name,
    p.project_name,
    p.id as project_id,
    CASE 
        WHEN HOUR(re.date_time) >= 8 AND HOUR(re.date_time) < 20 THEN 'Day'
        ELSE 'Night'
    END as calculated_shift,
    0 as unit_price_per_kg,
    0 as total_price
FROM roll_entry re
LEFT JOIN users u ON re.operator_id = u.id
LEFT JOIN projects p ON re.project_id = p.id
WHERE re.is_deleted = 0";

$params = [];
$types = '';

if ($dateFrom) {
    $query .= " AND DATE(re.date_time) >= ?";
    $params[] = $dateFrom;
    $types .= 's';
}
if ($dateTo) {
    $query .= " AND DATE(re.date_time) <= ?";
    $params[] = $dateTo;
    $types .= 's';
}
if ($referenceNumber) {
    $query .= " AND re.reference_number LIKE ?";
    $params[] = "%$referenceNumber%";
    $types .= 's';
}
if ($materialType) {
    $query .= " AND re.material_type = ?";
    $params[] = $materialType;
    $types .= 's';
}
if ($projectId) {
    $query .= " AND re.project_id = ?";
    $params[] = $projectId;
    $types .= 'i';
}

// Add shift filter using HAVING clause
if ($shift) {
    $query .= " HAVING calculated_shift = ?";
    $params[] = $shift;
    $types .= 's';
}

$query .= " ORDER BY re.date_time DESC";

// Execute query
$stmt = $conn->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$productions = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate statistics
$totalRolls = count($productions);
$totalWeight = array_sum(array_column($productions, 'total_weight'));
// Pricing will be calculated when roll_pricing table is available
$totalPrice = 0; // Will be calculated from pricing data when available
$avgPricePerKg = 0; // Will be calculated from pricing data when available

// Get unique material types, reference numbers, and projects for filters
$materialsResult = $conn->query("SELECT DISTINCT material_type FROM roll_entry WHERE is_deleted = 0 AND material_type IS NOT NULL ORDER BY material_type");
$materials = $materialsResult ? $materialsResult->fetch_all(MYSQLI_ASSOC) : [];

$refsResult = $conn->query("SELECT DISTINCT reference_number FROM roll_entry WHERE is_deleted = 0 AND reference_number IS NOT NULL ORDER BY reference_number DESC LIMIT 50");
$referenceNumbers = $refsResult ? $refsResult->fetch_all(MYSQLI_ASSOC) : [];

$projectsResult = $conn->query("SELECT DISTINCT p.id, p.project_name FROM projects p INNER JOIN roll_entry re ON p.id = re.project_id WHERE re.is_deleted = 0 ORDER BY p.project_name");
$projects = $projectsResult ? $projectsResult->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roll Production Price Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1800px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }
        
        /* Statistics Cards */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; padding: 25px; color: white; text-align: center; }
        .stat-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-card.orange { background: linear-gradient(135deg, #ee0979 0%, #ff6a00 100%); }
        .stat-card.blue { background: linear-gradient(135deg, #2193b0 0%, #6dd5ed 100%); }
        .stat-card.purple { background: linear-gradient(135deg, #8e2de2 0%, #4a00e0 100%); }
        .stat-value { font-size: 2.5em; font-weight: bold; margin-bottom: 5px; }
        .stat-label { font-size: 0.9em; opacity: 0.95; }
        
        /* Filters */
        .filters { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-size: 0.85em; font-weight: 600; color: #555; margin-bottom: 5px; }
        .filter-group input, .filter-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 0.9em; }
        .filter-btn { background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; }
        .filter-btn:hover { background: #2980b9; }
        .reset-btn { background: #95a5a6; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; }
        .reset-btn:hover { background: #7f8c8d; }
        
        /* Table */
        .table-wrapper { overflow-x: auto; margin-bottom: 20px; border-radius: 8px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; min-width: 1400px; font-size: 12px; }
        th, td { padding: 10px 8px; text-align: left; border-bottom: 1px solid #ecf0f1; white-space: nowrap; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; font-size: 11px; }
        tr:hover { background: #f8f9fa; }
        
        /* Roll Size column alignment - target by position (6th column in this report) */
        table th:nth-child(6), 
        table td:nth-child(6) { 
            text-align: center !important; 
            white-space: nowrap;
            vertical-align: middle;
        }
        
        /* All tables - Roll Size column */
        .table-wrapper table th:nth-child(6),
        .table-wrapper table td:nth-child(6) {
            text-align: center !important;
            white-space: nowrap;
        }
        
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        .export-btn:hover { background: #229954; }
        
        .price-highlight { color: #27ae60; font-weight: 600; }
        .no-price { color: #95a5a6; font-style: italic; }
        
        @media print {
            .filters, .export-btn { display: none; }
            body { background: white; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><i class="fas fa-dolly-flatbed"></i> Roll Production Price Report</h1>
        <p class="subtitle">Roll Production with Pricing Information</p>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($totalRolls); ?></div>
                <div class="stat-label">Total Rolls</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-value"><?php echo number_format($totalWeight, 2); ?></div>
                <div class="stat-label">Total Weight (kg)</div>
            </div>
            <div class="stat-card green">
                <div class="stat-value">৳<?php echo number_format($totalPrice, 2); ?></div>
                <div class="stat-label">Total Price</div>
            </div>
            <div class="stat-card purple">
                <div class="stat-value">৳<?php echo number_format($avgPricePerKg, 2); ?></div>
                <div class="stat-label">Avg Price per kg</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters">
            <form method="GET">
                <div class="filter-row">
                    <div class="filter-group">
                        <label>Date From:</label>
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                    </div>
                    <div class="filter-group">
                        <label>Date To:</label>
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                    </div>
                    <div class="filter-group">
                        <label>Reference Number:</label>
                        <select name="reference_number">
                            <option value="">All References</option>
                            <?php foreach($referenceNumbers as $ref): ?>
                                <option value="<?php echo htmlspecialchars($ref['reference_number']); ?>" 
                                        <?php echo ($referenceNumber == $ref['reference_number']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ref['reference_number']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Material Type:</label>
                        <select name="material_type">
                            <option value="">All Materials</option>
                            <?php foreach($materials as $mat): ?>
                                <option value="<?php echo htmlspecialchars($mat['material_type']); ?>" 
                                        <?php echo ($materialType == $mat['material_type']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($mat['material_type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Project:</label>
                        <select name="project_id">
                            <option value="">All Projects</option>
                            <?php foreach($projects as $proj): ?>
                                <option value="<?php echo $proj['id']; ?>" 
                                        <?php echo ($projectId == $proj['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($proj['project_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Shift:</label>
                        <select name="shift">
                            <option value="">All Shifts</option>
                            <option value="Day" <?php echo ($shift == 'Day') ? 'selected' : ''; ?>>Day</option>
                            <option value="Night" <?php echo ($shift == 'Night') ? 'selected' : ''; ?>>Night</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <button type="submit" class="filter-btn"><i class="fas fa-search"></i> Apply</button>
                    </div>
                    <div class="filter-group">
                        <a href="roll_production_price_report.php" class="reset-btn"><i class="fas fa-redo"></i> Reset</a>
                    </div>
                </div>
            </form>
        </div>
        
        <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print Report</button>

        <!-- Production Table -->
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Reference Number</th>
                        <th>Project</th>
                        <th>Material Type</th>
                        <th>Roll Size</th>
                        <th>Number of Rolls</th>
                        <th>Total Weight (kg)</th>
                        <th>Unit Price (৳/kg)</th>
                        <th>Total Price (৳)</th>
                        <th>Operator</th>
                        <th>Shift</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    foreach ($productions as $prod): 
                        $unitPrice = $prod['unit_price_per_kg'] ?? 0;
                        $totalPriceRow = $prod['total_price'] ?? 0;
                    ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td><?php echo $prod['date_time'] ? date('M d, Y', strtotime($prod['date_time'])) : 'N/A'; ?></td>
                            <td><?php echo htmlspecialchars($prod['reference_number'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($prod['project_name'] ?? 'Unassigned'); ?></td>
                            <td><?php echo htmlspecialchars($prod['material_type'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($prod['roll_size'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format($prod['number_of_rolls'] ?? 1); ?></td>
                            <td><?php echo number_format($prod['total_weight'] ?? 0, 2); ?></td>
                            <td class="<?php echo $unitPrice > 0 ? 'price-highlight' : 'no-price'; ?>">
                                <?php echo $unitPrice > 0 ? '৳' . number_format($unitPrice, 2) : 'Not Set'; ?>
                            </td>
                            <td class="<?php echo $totalPriceRow > 0 ? 'price-highlight' : 'no-price'; ?>">
                                <?php echo $totalPriceRow > 0 ? '৳' . number_format($totalPriceRow, 2) : 'N/A'; ?>
                            </td>
                            <td><?php echo htmlspecialchars($prod['operator_name'] ?? 'N/A'); ?></td>
                            <td>
                                <span style="background: <?php echo ($prod['calculated_shift'] == 'Day') ? '#d5f4e6' : '#e3f2fd'; ?>; color: <?php echo ($prod['calculated_shift'] == 'Day') ? '#27ae60' : '#2980b9'; ?>; padding: 4px 10px; border-radius: 4px; font-size: 0.85em;">
                                    <?php echo htmlspecialchars($prod['calculated_shift'] ?? 'N/A'); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($productions)): ?>
                        <tr>
                            <td colspan="12" style="text-align: center; padding: 40px; color: #7f8c8d;">
                                <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 10px; opacity: 0.5;"></i>
                                <div>No roll production data found</div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; font-size: 0.9em; color: #555;">
            <strong>Note:</strong> Pricing information is based on roll pricing settings. If prices are not showing, please configure pricing in the Product Pricing section.
        </div>
    </div>

<script>
// Align Roll Size columns in all tables
function alignRollSizeColumns() {
    const tables = document.querySelectorAll('table');
    tables.forEach(table => {
        const headers = table.querySelectorAll('thead th');
        headers.forEach((th, index) => {
            if (th.textContent.trim().toLowerCase().includes('roll size')) {
                const colIndex = index + 1; // nth-child is 1-based
                // Align header
                th.style.textAlign = 'center';
                th.style.whiteSpace = 'nowrap';
                // Align all cells in this column
                const rows = table.querySelectorAll('tbody tr');
                rows.forEach(row => {
                    const cell = row.querySelector(`td:nth-child(${colIndex})`);
                    if (cell) {
                        cell.style.textAlign = 'center';
                        cell.style.whiteSpace = 'nowrap';
                        cell.style.verticalAlign = 'middle';
                    }
                });
            }
        });
    });
}

// Run alignment on page load
document.addEventListener('DOMContentLoaded', function() {
    alignRollSizeColumns();
});

// Re-run alignment when tabs are switched (if applicable)
if (typeof switchTab !== 'undefined') {
    const originalSwitchTab = window.switchTab;
    window.switchTab = function(tabName, buttonElement) {
        if (originalSwitchTab) {
            originalSwitchTab(tabName, buttonElement);
        }
        setTimeout(alignRollSizeColumns, 100);
    };
}
</script>
</body>
</html>


