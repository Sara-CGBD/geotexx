<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'agm ops', 'management'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Determine which sewing table exists
$sewingTableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
$sewingTable = ($sewingTableCheck && $sewingTableCheck->num_rows > 0) ? 'sewing_machine_entry' : 'swing_machine_entry';

$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Define stages
$stages = ['Roll', 'CNC', 'Production', 'FG'];
$stageData = [];

foreach ($stages as $stage) {
    $stockCount = 0;
    $passCount = 0;
    $failCount = 0;
    $totalQC = 0;
    
    // Get stock count based on stage
    switch ($stage) {
        case 'Roll':
            // Count roll entries (active rolls - not deleted) if column exists
            $rollCols = [];
            $rollColRes = $conn->query("SHOW COLUMNS FROM roll_entry");
            if ($rollColRes) {
                while ($r = $rollColRes->fetch_assoc()) {
                    $rollCols[] = strtolower($r['Field']);
                }
            }
            $hasIsDeleted = in_array('is_deleted', $rollCols, true);
            $stockQuery = $hasIsDeleted
                ? "SELECT COUNT(*) as count FROM roll_entry WHERE (is_deleted = 0 OR is_deleted IS NULL)"
                : "SELECT COUNT(*) as count FROM roll_entry";
            $stockResult = $conn->query($stockQuery);
            if ($stockResult) {
                $stockRow = $stockResult->fetch_assoc();
                $stockCount = (int)$stockRow['count'];
            }
            break;
            
        case 'CNC':
            // Count CNC entries (recent entries)
            $stockQuery = "SELECT COUNT(*) as count FROM cnc_entries WHERE DATE(date_time) BETWEEN ? AND ?";
            $stmt = $conn->prepare($stockQuery);
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $stockResult = $stmt->get_result();
            if ($stockResult) {
                $stockRow = $stockResult->fetch_assoc();
                $stockCount = (int)$stockRow['count'];
            }
            $stmt->close();
            break;
            
        case 'Production':
            // Count production entries from CNC, Sewing, and Branding tables
            // Branding may not have is_deleted; detect first
            $brandingCols = [];
            $brandingColRes = $conn->query("SHOW COLUMNS FROM branding_entries");
            if ($brandingColRes) {
                while ($r = $brandingColRes->fetch_assoc()) {
                    $brandingCols[] = strtolower($r['Field']);
                }
            }
            $brandingHasIsDeleted = in_array('is_deleted', $brandingCols, true);
            $brandingFilter = $brandingHasIsDeleted ? "is_deleted = 0 AND" : "";

            $stockQuery = "SELECT 
                            (SELECT COUNT(*) FROM cnc_entries WHERE DATE(date_time) BETWEEN ? AND ?) +
                            (SELECT COUNT(*) FROM $sewingTable WHERE DATE(date_time) BETWEEN ? AND ?) +
                            (SELECT COUNT(*) FROM branding_entries WHERE $brandingFilter DATE(date_time) BETWEEN ? AND ?) as count";
            $stmt = $conn->prepare($stockQuery);
            $stmt->bind_param('ssssss', $dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo);
            $stmt->execute();
            $stockResult = $stmt->get_result();
            if ($stockResult) {
                $stockRow = $stockResult->fetch_assoc();
                $stockCount = (int)$stockRow['count'];
            }
            $stmt->close();
            break;
            
        case 'FG':
            // Count FG entries in stock (not fully delivered)
            // Detect product_type column
            $fgCols = [];
            $fgColRes = $conn->query("SHOW COLUMNS FROM fg_entry");
            if ($fgColRes) {
                while ($r = $fgColRes->fetch_assoc()) {
                    $fgCols[] = strtolower($r['Field']);
                }
            }
            $hasProductType = in_array('product_type', $fgCols, true);

            if ($hasProductType) {
            $stockQuery = "SELECT COUNT(*) as count FROM fg_entry 
                          WHERE (
                              (product_type = 'roll' AND (actual_weight - COALESCE(delivered_quantity, 0)) > 0)
                              OR 
                              ((product_type IS NULL OR product_type != 'roll') AND (passed_qty - COALESCE(delivered_quantity, 0)) > 0)
                          )";
            } else {
                // Fallback: count where either actual_weight or passed_qty minus delivered_quantity > 0
                $stockQuery = "SELECT COUNT(*) as count FROM fg_entry 
                              WHERE (
                                  (actual_weight - COALESCE(delivered_quantity, 0)) > 0
                                  OR 
                                  (passed_qty - COALESCE(delivered_quantity, 0)) > 0
                              )";
            }
            $stockResult = $conn->query($stockQuery);
            if ($stockResult) {
                $stockRow = $stockResult->fetch_assoc();
                $stockCount = (int)$stockRow['count'];
            }
            break;
    }
    
    // Get QC pass/fail counts for this stage
    $qcQuery = "SELECT 
                    qc_result,
                    COUNT(*) as count
                FROM qc_entries
                WHERE qc_stage = ? AND DATE(date_time) BETWEEN ? AND ?
                GROUP BY qc_result";
    $stmt = $conn->prepare($qcQuery);
    $stmt->bind_param('sss', $stage, $dateFrom, $dateTo);
    $stmt->execute();
    $qcResult = $stmt->get_result();
    
    while ($row = $qcResult->fetch_assoc()) {
        if ($row['qc_result'] == 'Pass') {
            $passCount = (int)$row['count'];
        } else {
            $failCount = (int)$row['count'];
        }
    }
    $stmt->close();
    
    $totalQC = $passCount + $failCount;
    
    // Calculate percentages
    $totalStock = array_sum(array_column($stageData, 'stock_count')) + $stockCount;
    $stockPercentage = 0; // Will calculate after all stages
    
    $passPercentage = $totalQC > 0 ? ($passCount / $totalQC) * 100 : 0;
    $failPercentage = $totalQC > 0 ? ($failCount / $totalQC) * 100 : 0;
    
    $stageData[$stage] = [
        'stock_count' => $stockCount,
        'pass_count' => $passCount,
        'fail_count' => $failCount,
        'total_qc' => $totalQC,
        'pass_percentage' => round($passPercentage, 1),
        'fail_percentage' => round($failPercentage, 1)
    ];
}

// Calculate stock percentages (relative to total stock across all stages)
$totalStockAllStages = array_sum(array_column($stageData, 'stock_count'));
foreach ($stageData as $stage => &$data) {
    $data['stock_percentage'] = $totalStockAllStages > 0 ? round(($data['stock_count'] / $totalStockAllStages) * 100, 1) : 0;
}
unset($data);

// Get overall totals
$totalStockAll = $totalStockAllStages;
$totalPassAll = array_sum(array_column($stageData, 'pass_count'));
$totalFailAll = array_sum(array_column($stageData, 'fail_count'));
$totalQCAll = $totalPassAll + $totalFailAll;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stage-wise Stock & QC Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1600px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }
        
        .filters { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-size: 0.85em; font-weight: 600; color: #555; margin-bottom: 5px; }
        .filter-group input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 0.9em; }
        .filter-btn { background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; }
        .filter-btn:hover { background: #2980b9; }
        .reset-btn { background: #95a5a6; }
        
        .cards-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 25px; margin-bottom: 30px; }
        .stage-card { background: linear-gradient(135deg,rgb(46, 54, 90) 0%,rgb(69, 45, 94) 100%); border-radius: 12px; padding: 30px; color: white; box-shadow: 0 4px 15px rgba(0,0,0,0.1); transition: transform 0.3s ease; }
        .stage-card:hover { transform: translateY(-5px); box-shadow: 0 6px 20px rgba(161, 48, 48, 0.15); }
        .stage-card.roll { background: linear-gradient(135deg, #5d6d7e 0%, #566573 100%); }
        .stage-card.cnc { background: linear-gradient(135deg,rgb(76, 107, 124) 0%, #5dade2 100%); }
        .stage-card.production { background: linear-gradient(135deg, #52be80 0%, #58d68d 100%); }
        .stage-card.fg { background: linear-gradient(135deg, #a569bd 0%, #bb8fce 100%); }
        
        .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 25px; }
        .card-title { font-size: 1.5em; font-weight: 700; }
        .card-icon { font-size: 2.5em; opacity: 0.9; }
        
        .metric { margin-bottom: 20px; }
        .metric-label { font-size: 0.85em; opacity: 0.9; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .metric-value { font-size: 2.2em; font-weight: 700; margin-bottom: 5px; }
        .metric-detail { font-size: 0.9em; opacity: 0.85; }
        
        .progress-bar { background: rgba(255,255,255,0.2); border-radius: 10px; height: 12px; margin-top: 8px; overflow: hidden; }
        .progress-fill { background: rgba(255,255,255,0.9); height: 100%; border-radius: 10px; transition: width 0.5s ease; }
        
        .summary-section { background: #f8f9fa; padding: 25px; border-radius: 8px; margin-top: 30px; }
        .summary-title { font-size: 1.3em; color: #34495e; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 3px solid #3498db; }
        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
        .summary-card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .summary-card-label { font-size: 0.85em; color: #7f8c8d; margin-bottom: 8px; }
        .summary-card-value { font-size: 1.8em; font-weight: 700; color: #2c3e50; }
        
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        .export-btn:hover { background: #229954; }
        
        @media print {
            .filters, .export-btn { display: none; }
            body { background: white; padding: 0; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-chart-pie"></i> Stage-wise Stock & QC Report</h1>
    <p class="subtitle">Stock percentages, Pass/Fail rates for each production stage</p>
    
    <form method="GET" action="">
        <div class="filters">
            <div class="filter-row">
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> Date From</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" required>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> Date To</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" required>
                </div>
                <div class="filter-group">
                    <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Filter</button>
                    <a href="stage_wise_stock_qc_report.php" class="filter-btn reset-btn" style="text-decoration: none; display: inline-block; text-align: center; line-height: 2; margin-top: 5px;">Reset</a>
                </div>
            </div>
        </div>
    </form>
    
    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print</button>
    
    <div class="cards-grid">
        <?php foreach ($stages as $stage): 
            $data = $stageData[$stage];
            $stageLower = strtolower($stage);
        ?>
        <div class="stage-card <?php echo $stageLower; ?>">
            <div class="card-header">
                <div class="card-title"><?php echo htmlspecialchars($stage); ?> Stage</div>
                <div class="card-icon">
                    <?php 
                    $icons = [
                        'roll' => 'fas fa-roll',
                        'cnc' => 'fas fa-cut',
                        'production' => 'fas fa-industry',
                        'fg' => 'fas fa-box'
                    ];
                    echo '<i class="' . ($icons[$stageLower] ?? 'fas fa-cube') . '"></i>';
                    ?>
                </div>
            </div>
            
            <!-- Stock Percentage -->
            <div class="metric">
                <div class="metric-label">Stock Percentage</div>
                <div class="metric-value"><?php echo $data['stock_percentage']; ?>%</div>
                <div class="metric-detail"><?php echo number_format($data['stock_count']); ?> items in stock</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min($data['stock_percentage'], 100); ?>%;"></div>
                </div>
            </div>
            
            <!-- Pass Percentage -->
            <div class="metric">
                <div class="metric-label">Pass Percentage</div>
                <div class="metric-value"><?php echo $data['pass_percentage']; ?>%</div>
                <div class="metric-detail"><?php echo number_format($data['pass_count']); ?> passed out of <?php echo number_format($data['total_qc']); ?> QC tests</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min($data['pass_percentage'], 100); ?>%;"></div>
                </div>
            </div>
            
            <!-- Fail Percentage -->
            <div class="metric">
                <div class="metric-label">Fail Percentage</div>
                <div class="metric-value"><?php echo $data['fail_percentage']; ?>%</div>
                <div class="metric-detail"><?php echo number_format($data['fail_count']); ?> failed out of <?php echo number_format($data['total_qc']); ?> QC tests</div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo min($data['fail_percentage'], 100); ?>%;"></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div class="summary-section">
        <h2 class="summary-title"><i class="fas fa-chart-bar"></i> Overall Summary</h2>
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-card-label">Total Stock (All Stages)</div>
                <div class="summary-card-value"><?php echo number_format($totalStockAll); ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-card-label">Total QC Tests</div>
                <div class="summary-card-value"><?php echo number_format($totalQCAll); ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-card-label">Total Passed</div>
                <div class="summary-card-value" style="color: #27ae60;"><?php echo number_format($totalPassAll); ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-card-label">Total Failed</div>
                <div class="summary-card-value" style="color: #e74c3c;"><?php echo number_format($totalFailAll); ?></div>
            </div>
            <div class="summary-card">
                <div class="summary-card-label">Overall Pass Rate</div>
                <div class="summary-card-value" style="color: #3498db;">
                    <?php echo $totalQCAll > 0 ? number_format(($totalPassAll / $totalQCAll) * 100, 1) : 0; ?>%
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>


