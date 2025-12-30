<?php
session_start();
// Optional dev auto-reload
$__dev_reload = __DIR__ . '/../dev/auto_reload.php';
if (file_exists($__dev_reload)) {
    include_once $__dev_reload;
}

// Prevent browser caching to ensure fresh data loads
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../config/security_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Check access - checkers, admin, AGM Ops can view
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$is_checker = ($user_role === 'checker' || $user_role === 'admin');
$is_admin = ($user_role === 'admin' || $user_role === 'agm ops' || $user_role === 'agm operations');

if (!$is_checker && !$is_admin) {
    die("Access denied. Only checkers and admins can view QC test order reports.");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Get report ID or report number
$report_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$report_number = isset($_GET['report_number']) ? trim($_GET['report_number']) : '';

// Fetch the report
$report = null;
if ($report_id > 0) {
    $stmt = $conn->prepare("
        SELECT qto.*, ts.test_name, ts.standard_code 
        FROM qc_test_orders qto
        LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
        WHERE qto.id = ?
    ");
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $report = $result->fetch_assoc();
    $stmt->close();
} elseif (!empty($report_number)) {
    $stmt = $conn->prepare("
        SELECT qto.*, ts.test_name, ts.standard_code 
        FROM qc_test_orders qto
        LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
        WHERE qto.report_number = ?
    ");
    $stmt->bind_param("s", $report_number);
    $stmt->execute();
    $result = $stmt->get_result();
    $report = $result->fetch_assoc();
    $stmt->close();
}

if (!$report) {
    die("Report not found.");
}

// Decode test data
$test_data = json_decode($report['test_data'], true) ?? [];

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View QC Test Order - <?php echo htmlspecialchars($report['report_number']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Force 2 columns - Updated */
        body { font-family: 'Inter', sans-serif; background: #f4f6f9; margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; margin-bottom: 10px; }
        .subtitle { color: #666; margin-bottom: 30px; }
        .container .info-grid,
        .info-grid { 
            display: grid !important; 
            grid-template-columns: repeat(2, 1fr) !important; 
            -ms-grid-columns: 1fr 20px 1fr !important;
            gap: 20px !important; 
            margin-bottom: 30px; 
            align-items: stretch; 
            width: 100%;
        }
        .container .info-grid > *,
        .info-grid > * {
            max-width: 100% !important;
        }
        @media (max-width: 768px) {
            .container .info-grid,
            .info-grid { 
                grid-template-columns: 1fr !important; 
                -ms-grid-columns: 1fr !important;
            }
        }
        .info-item { 
            background: #ffffff; 
            padding: 16px; 
            border-radius: 8px; 
            border: 1px solid #e0e0e0;
            border-left: 4px solid #007bff;
            display: grid;
            grid-template-rows: auto 1fr;
            gap: 10px;
            height: 100%;
            box-sizing: border-box;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: box-shadow 0.2s;
        }
        .info-item:hover {
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .info-label { 
            font-weight: 600; 
            color: #666; 
            font-size: 11px; 
            text-transform: uppercase; 
            letter-spacing: 0.5px;
            line-height: 1.4; 
            min-height: 32px;
            display: flex;
            align-items: flex-start;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        .info-value { 
            color: #333; 
            font-size: 15px; 
            font-weight: 500; 
            word-wrap: break-word; 
            overflow-wrap: break-word; 
            line-height: 1.6; 
            margin: 0;
            display: flex;
            align-items: flex-start;
        }
        .status-badge { display: inline-block; padding: 6px 12px; border-radius: 4px; font-size: 13px; font-weight: 600; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .test-data-section { background: #f8f9fa; padding: 20px; border-radius: 6px; margin-top: 20px; }
        .test-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .test-table th { background: #343a40; color: white; padding: 10px; text-align: left; }
        .test-table td { padding: 10px; border-bottom: 1px solid #ddd; }
        .back-btn { display: inline-block; padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 4px; margin-bottom: 20px; }
        .back-btn:hover { background: #5a6268; }
    </style>
</head>
<body>
<div class="container">
    <?php 
    $return_page = $_GET['return'] ?? '';
    $back_url = '../forms/qc_test_order.php';
    $back_text = 'Back to QC Test Order';
    if ($return_page === 'qc_test_approval_dashboard') {
        $back_url = '../admin/qc_test_approval_dashboard.php';
        $back_text = 'Back to QC Test Approval Dashboard';
    } elseif ($return_page === 'external_checker_dashboard') {
        $back_url = '../admin/external_checker_dashboard.php';
        $back_text = 'Back to External Test Checker Dashboard';
    } elseif ($return_page === 'agm_external_test_dashboard') {
        $back_url = '../admin/agm_external_test_dashboard.php';
        $back_text = 'Back to AGM External Test Dashboard';
    }
    ?>
    <a href="<?php echo $back_url; ?>" class="back-btn"><i class="fas fa-arrow-left"></i> <?php echo $back_text; ?></a>
    
    <h1><i class="fas fa-file-alt"></i> QC Test Order Report</h1>
    <p class="subtitle">Report Number: <strong><?php echo htmlspecialchars($report['report_number']); ?></strong></p>
    
    <!-- Status Information -->
    <div class="info-grid">
        <div class="info-item">
            <div class="info-label">Report Number</div>
            <div class="info-value"><?php echo htmlspecialchars($report['report_number']); ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Status</div>
            <div class="info-value">
                <?php 
                $status_class = 'status-pending';
                if ($report['status'] === 'approved') $status_class = 'status-approved';
                if (strpos($report['status'], 'rejected') !== false) $status_class = 'status-rejected';
                ?>
                <span class="status-badge <?php echo $status_class; ?>">
                    <?php echo strtoupper(str_replace('_', ' ', $report['status'])); ?>
                </span>
            </div>
        </div>
        <div class="info-item">
            <div class="info-label">Test Name</div>
            <div class="info-value"><?php echo htmlspecialchars($report['test_name'] ?? 'N/A'); ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Test Method</div>
            <div class="info-value"><?php echo htmlspecialchars($report['chosen_method'] ?? 'N/A'); ?></div>
        </div>
    </div>
    
    <!-- General Information -->
    <div class="info-grid">
        <div class="info-item">
            <div class="info-label">Sample Reference ID</div>
            <div class="info-value"><?php echo htmlspecialchars($report['sample_reference_id']); ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Inspector/Tester</div>
            <div class="info-value"><?php echo htmlspecialchars($report['inspector_name'] ?? 'N/A'); ?></div>
        </div>
        <div class="info-item">
            <div class="info-label">Submitted At</div>
            <div class="info-value"><?php echo date('M d, Y - g:i A', strtotime($report['created_at'])); ?></div>
        </div>
        <?php if (!empty($report['checked_by'])): ?>
        <div class="info-item">
            <div class="info-label">Checked By</div>
            <div class="info-value"><?php echo htmlspecialchars($report['checked_by']); ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($report['approved_by'])): ?>
        <div class="info-item">
            <div class="info-label">Approved By</div>
            <div class="info-value"><?php echo htmlspecialchars($report['approved_by']); ?></div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Additional References from test_data -->
    <?php if (!empty($test_data['product_reference']) || !empty($test_data['customer_reference']) || !empty($test_data['fiber_reference_no']) || !empty($test_data['yarn_reference_no'])): ?>
    <div class="info-grid">
        <?php if (!empty($test_data['product_reference'])): ?>
        <div class="info-item">
            <div class="info-label">Product Reference</div>
            <div class="info-value"><?php echo htmlspecialchars($test_data['product_reference']); ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($test_data['customer_reference'])): ?>
        <div class="info-item">
            <div class="info-label">Customer Reference</div>
            <div class="info-value"><?php echo htmlspecialchars($test_data['customer_reference']); ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($test_data['fiber_reference_no'])): ?>
        <div class="info-item">
            <div class="info-label">Fiber Reference No</div>
            <div class="info-value"><?php echo htmlspecialchars($test_data['fiber_reference_no']); ?></div>
        </div>
        <?php endif; ?>
        <?php if (!empty($test_data['yarn_reference_no'])): ?>
        <div class="info-item">
            <div class="info-label">Yarn Reference No</div>
            <div class="info-value"><?php echo htmlspecialchars($test_data['yarn_reference_no']); ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- General Information from test_data -->
    <?php 
    $general_fields = [
        'sample_details' => 'Sample Details',
        'batch_information' => 'Batch Information',
        'sample_collected_from' => 'Sample Collected From',
        'sample_received_datetime' => 'Sample Received Date/Time',
        'sample_production_date' => 'Sample Production Date',
        'temperature' => 'Temperature',
        'rh_percentage' => 'RH Percentage',
        'test_period_from' => 'Test Period From',
        'test_period_to' => 'Test Period To',
        'roll_number' => 'Roll Number',
        'gsm' => 'GSM',
        'sample_received_from' => 'Sample Received From',
        'lighthouse_reference' => 'Lighthouse Reference',
        'other_info' => 'Other Information'
    ];
    $has_general_info = false;
    foreach ($general_fields as $key => $label) {
        if (!empty($test_data[$key])) {
            $has_general_info = true;
            break;
        }
    }
    ?>
    <?php if ($has_general_info): ?>
    <div class="info-grid">
        <?php foreach ($general_fields as $key => $label): ?>
            <?php if (!empty($test_data[$key])): ?>
            <div class="info-item">
                <div class="info-label"><?php echo htmlspecialchars($label); ?></div>
                <div class="info-value"><?php echo htmlspecialchars($test_data[$key]); ?></div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <!-- Test Data Section -->
    <div class="test-data-section">
        <h3><i class="fas fa-vial"></i> Test Data Details</h3>
        
        <?php 
        // Check if test data has been submitted
        // Check for all possible test data types
        $has_test_data = false;
        
        // Check for positions array (thickness, GSM tests)
        if (!empty($test_data['positions']) && is_array($test_data['positions'])) {
            foreach ($test_data['positions'] as $pos) {
                if (is_array($pos)) {
                    // Check for thickness/value (thickness test)
                    $thickness = $pos['thickness'] ?? $pos['value'] ?? null;
                    if ($thickness !== null && is_numeric($thickness) && floatval($thickness) > 0) {
                        $has_test_data = true;
                        break;
                    }
                    // Check for weight/gsm (GSM test)
                    $weight = $pos['weight'] ?? null;
                    $gsm = $pos['gsm'] ?? null;
                    if (($weight !== null && is_numeric($weight) && floatval($weight) > 0) ||
                        ($gsm !== null && is_numeric($gsm) && floatval($gsm) > 0)) {
                        $has_test_data = true;
                        break;
                    }
                }
            }
        }
        
        // Check for other test data arrays
        if (!$has_test_data) {
            $test_data_arrays = ['strip_data', 'cbr_data', 'grab_data', 'weathering_data', 'seam_data'];
            foreach ($test_data_arrays as $array_key) {
                if (!empty($test_data[$array_key]) && is_array($test_data[$array_key]) && count($test_data[$array_key]) > 0) {
                    $has_test_data = true;
                    break;
                }
            }
        }
        
        // Check for statistics (indicates test was completed)
        if (!$has_test_data) {
            if (isset($test_data['average']) && is_numeric($test_data['average']) && floatval($test_data['average']) > 0) {
                $has_test_data = true;
            } elseif (isset($test_data['avg']) && is_numeric($test_data['avg']) && floatval($test_data['avg']) > 0) {
                $has_test_data = true;
            } elseif (isset($test_data['sd']) && is_numeric($test_data['sd']) && floatval($test_data['sd']) > 0) {
                $has_test_data = true;
            } elseif (isset($test_data['summary']) && is_array($test_data['summary']) && !empty($test_data['summary'])) {
                $has_test_data = true;
            }
        }
        
        // Only show "no test data" message if we truly have no test data
        if (!$has_test_data): ?>
        <!-- No test data submitted yet -->
        <div style="padding: 20px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; margin: 20px 0;">
            <p style="margin: 0; color: #856404; font-weight: 600;">
                <i class="fas fa-exclamation-triangle"></i> No test data has been submitted yet.
            </p>
            <p style="margin: 10px 0 0 0; color: #856404;">
                The tester needs to fill in the test data table and submit the test. Once submitted, the test data will appear here.
            </p>
        </div>
        
        <?php elseif (!empty($test_data['positions']) && (isset($test_data['positions'][0]['thickness']) || isset($test_data['positions'][0]['value']))): ?>
        <!-- For Thickness test with grouped positions and averages -->
        <h4 style="margin-top:0;">Test Data - Thickness (Under 2kPa Pressure)</h4>
        <table class="test-table">
            <thead>
                <tr>
                    <th>Position</th>
                    <th>Thickness (mm)</th>
                    <th>Average (mm)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Group positions by base position (Left, Middle Left, etc.) for sequential display
                // Show Left-1, Left-2 together, then Middle Left-1, Middle Left-2, etc.
                $grouped_positions = [];
                $groups_for_avg = ['Left' => [], 'Middle Left' => [], 'Middle Right' => [], 'Right' => []];
                
                foreach ($test_data['positions'] as $pos) {
                    $position = trim($pos['position'] ?? '');
                    
                    // Extract base position (e.g., "Left" from "Left-1" or "Left-2")
                    $base_pos = preg_replace('/[\s-]*\d+[\s-]*$/', '', $position);
                    $base_pos = trim($base_pos);
                    
                    if (empty($base_pos)) {
                        // If no base position, add to a catch-all group
                        $base_pos = 'Other';
                    }
                    
                    // Extract suffix number for sorting within group
                    if (preg_match('/[\s-]*(\d+)[\s-]*$/', $position, $matches)) {
                        $suffix = (int)$matches[1];
                    } else {
                        $suffix = 0;
                    }
                    
                    if (!isset($grouped_positions[$base_pos])) {
                        $grouped_positions[$base_pos] = [];
                    }
                    
                    $grouped_positions[$base_pos][$suffix] = $pos;
                    
                    // Also collect for average calculation
                    $posType = $base_pos;
                    if (isset($groups_for_avg[$posType])) {
                        // Handle both 'thickness' and 'value' fields
                        $thicknessValue = $pos['thickness'] ?? $pos['value'] ?? null;
                        if ($thicknessValue !== null && is_numeric($thicknessValue)) {
                            $groups_for_avg[$posType][] = floatval($thicknessValue);
                        }
                    }
                }
                
                // Calculate averages for each group
                $groupAverages = [];
                foreach ($groups_for_avg as $groupName => $values) {
                    if (count($values) > 0) {
                        $groupAverages[$groupName] = number_format(array_sum($values) / count($values), 3);
                    } else {
                        $groupAverages[$groupName] = '';
                    }
                }
                
                // Define display order
                $position_order = ['Left', 'Middle Left', 'Middle Right', 'Right'];
                
                // Add any other positions that don't match standard order
                foreach ($grouped_positions as $base_pos => $data) {
                    if (!in_array($base_pos, $position_order)) {
                        $position_order[] = $base_pos;
                    }
                }
                
                // Display grouped by position base, sorted by suffix within each group
                foreach ($position_order as $base_pos) {
                    if (!isset($grouped_positions[$base_pos])) continue;
                    
                    // Sort by suffix (1, 2, 3, etc.) to ensure correct order within group
                    ksort($grouped_positions[$base_pos]);
                    
                    // Get position type for average calculation
                    $posType = $base_pos;
                    
                    // Display each position in the group
                    $group_count = 0;
                    $group_total = count($grouped_positions[$base_pos]);
                    foreach ($grouped_positions[$base_pos] as $suffix => $pos) {
                        $group_count++;
                        $groupAvg = '';
                        
                        // Show average only in last row of each group
                        if ($group_count === $group_total && isset($groupAverages[$posType])) {
                            $groupAvg = $groupAverages[$posType];
                        }
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($pos['position'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($pos['thickness'] ?? $pos['value'] ?? ''); ?></td>
                            <td style="background:#d4edda; font-weight:600; color:#155724; text-align:center;">
                                <?php echo $groupAvg; ?>
                            </td>
                        </tr>
                        <?php
                    }
                }
                ?>
            </tbody>
        </table>
        
        <!-- Summary Statistics Table -->
        <?php if (isset($test_data['average'])): ?>
        <h4 style="margin-top:25px;">Summary of Thickness (Under 2kPa)</h4>
        <table class="test-table">
            <thead>
                <tr>
                    <th>Statistics</th>
                    <th>Under 2kPa (mm)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="font-weight:600;">Average</td>
                    <td><?php echo number_format($test_data['average'], 3); ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600;">SD</td>
                    <td><?php echo number_format($test_data['sd'] ?? 0, 3); ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600;">CV%</td>
                    <td><?php echo number_format($test_data['cv'] ?? 0, 2); ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600;">Maximum</td>
                    <td><?php echo number_format($test_data['max'] ?? 0, 3); ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600;">Minimum</td>
                    <td><?php echo number_format($test_data['min'] ?? 0, 3); ?></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php elseif (!empty($test_data['positions']) && (isset($test_data['positions'][0]['weight']) || isset($test_data['positions'][0]['gsm']))): ?>
        <!-- For GSM test with positions -->
        <h4 style="margin-top:0;">Test Data - Mass Per Unit Area (GSM)</h4>
        <table class="test-table">
            <thead>
                <tr>
                    <th>Position</th>
                    <th>Weight (g)</th>
                    <th>Calculated (GSM)</th>
                    <th>Average (GSM)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Group positions by base position (Left, Middle Left, etc.) for sequential display
                // Same logic as Grab Tensile - show Left-1, Left-2 together, then Middle Left-1, Middle Left-2, etc.
                $grouped_positions = [];
                $groups_for_avg = ['Left' => [], 'Middle Left' => [], 'Middle Right' => [], 'Right' => []];
                
                foreach ($test_data['positions'] as $pos) {
                    $position = trim($pos['position'] ?? '');
                    
                    // Extract base position (e.g., "Left" from "Left-1" or "Left-2")
                    $base_pos = preg_replace('/[\s-]*\d+[\s-]*$/', '', $position);
                    $base_pos = trim($base_pos);
                    
                    if (empty($base_pos)) {
                        // If no base position, add to a catch-all group
                        $base_pos = 'Other';
                    }
                    
                    // Extract suffix number for sorting within group
                    if (preg_match('/[\s-]*(\d+)[\s-]*$/', $position, $matches)) {
                        $suffix = (int)$matches[1];
                    } else {
                        $suffix = 0;
                    }
                    
                    if (!isset($grouped_positions[$base_pos])) {
                        $grouped_positions[$base_pos] = [];
                    }
                    
                    $grouped_positions[$base_pos][$suffix] = $pos;
                    
                    // Also collect for average calculation
                    $posType = explode('-', $position)[0];
                    if (isset($groups_for_avg[$posType]) && isset($pos['gsm'])) {
                        $groups_for_avg[$posType][] = floatval($pos['gsm']);
                    }
                }
                
                // Calculate averages for each group
                $groupAverages = [];
                foreach ($groups_for_avg as $groupName => $values) {
                    if (count($values) > 0) {
                        $groupAverages[$groupName] = number_format(array_sum($values) / count($values), 2);
                    } else {
                        $groupAverages[$groupName] = '';
                    }
                }
                
                // Define display order
                $position_order = ['Left', 'Middle Left', 'Middle Right', 'Right'];
                
                // Add any other positions that don't match standard order
                foreach ($grouped_positions as $base_pos => $data) {
                    if (!in_array($base_pos, $position_order)) {
                        $position_order[] = $base_pos;
                    }
                }
                
                // Display grouped by position base, sorted by suffix within each group
                foreach ($position_order as $base_pos) {
                    if (!isset($grouped_positions[$base_pos])) continue;
                    
                    // Sort by suffix (1, 2, 3, etc.) to ensure correct order within group
                    ksort($grouped_positions[$base_pos]);
                    
                    // Get position type for average calculation
                    $posType = $base_pos;
                    
                    // Display each position in the group
                    $group_count = 0;
                    $group_total = count($grouped_positions[$base_pos]);
                    foreach ($grouped_positions[$base_pos] as $suffix => $pos) {
                        $group_count++;
                        $groupAvg = '';
                        
                        // Show average only in last row of each group
                        if ($group_count === $group_total && isset($groupAverages[$posType])) {
                            $groupAvg = $groupAverages[$posType];
                        }
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($pos['position'] ?? ''); ?></td>
                            <td><?php echo isset($pos['weight']) ? htmlspecialchars($pos['weight']) : ''; ?></td>
                            <td><?php echo isset($pos['gsm']) ? htmlspecialchars($pos['gsm']) : ''; ?></td>
                            <td style="background:#d4edda; font-weight:600; color:#155724; text-align:center;">
                                <?php echo $groupAvg; ?>
                            </td>
                        </tr>
                        <?php
                    }
                }
                ?>
            </tbody>
        </table>
        
        <!-- Summary Statistics Table -->
        <?php if (isset($test_data['average'])): ?>
        <h4 style="margin-top:25px;">Summary of GSM</h4>
        <table class="test-table">
            <thead>
                <tr>
                    <th>Statistics</th>
                    <th>GSM</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="font-weight:600;">Average</td>
                    <td><?php echo number_format($test_data['average'], 2); ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600;">SD</td>
                    <td><?php echo number_format($test_data['sd'] ?? 0, 2); ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600;">CV%</td>
                    <td><?php echo number_format($test_data['cv'] ?? 0, 2); ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600;">Maximum</td>
                    <td><?php echo number_format($test_data['max'] ?? 0, 2); ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600;">Minimum</td>
                    <td><?php echo number_format($test_data['min'] ?? 0, 2); ?></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php elseif (!empty($test_data['strip_data'])): ?>
        <!-- For Strip Tensile Test -->
        <h4 style="margin-top:0;">Test Data - Strip Tensile Test</h4>
        <table class="test-table">
            <thead>
                <tr>
                    <th>Position</th>
                    <th>Direction</th>
                    <th>Strength (N)</th>
                    <th>MD:CD Ratio</th>
                    <th>Elongation (%)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Group data by position base (Left, Middle Left, Middle Right, Right)
                // to display MD and CD pairs together
                $grouped_data = [];
                foreach ($test_data['strip_data'] as $row) {
                    $position = trim($row['position'] ?? '');
                    $direction = strtoupper(trim($row['direction'] ?? ''));
                    
                    // Extract base position (e.g., "Left" from "Left-1" or "Left-2")
                    // Handle variations like "Left-1", "Left - 1", etc.
                    $base_pos = preg_replace('/[\s-]*\d+[\s-]*$/', '', $position);
                    $base_pos = trim($base_pos);
                    
                    if (empty($base_pos)) continue;
                    
                    if (!isset($grouped_data[$base_pos])) {
                        $grouped_data[$base_pos] = ['MD' => null, 'CD' => null, 'original_order' => count($grouped_data)];
                    }
                    
                    if (strpos($direction, 'MD') !== false || $direction === 'MD') {
                        $grouped_data[$base_pos]['MD'] = $row;
                    } elseif (strpos($direction, 'CD') !== false || $direction === 'CD') {
                        $grouped_data[$base_pos]['CD'] = $row;
                    }
                }
                
                // Define display order - try to match common patterns
                $position_order = ['Left', 'Middle Left', 'Middle Right', 'Right'];
                
                // If we have positions that don't match the standard order, add them
                foreach ($grouped_data as $base_pos => $data) {
                    if (!in_array($base_pos, $position_order)) {
                        $position_order[] = $base_pos;
                    }
                }
                
                // Display pairs in order
                foreach ($position_order as $base_pos) {
                    if (!isset($grouped_data[$base_pos])) continue;
                    
                    $md_row = $grouped_data[$base_pos]['MD'];
                    $cd_row = $grouped_data[$base_pos]['CD'];
                    
                    // Calculate ratio in "1: X.XX" format (CD/MD)
                    $md_strength = $md_row ? floatval($md_row['strength'] ?? 0) : 0;
                    $cd_strength = $cd_row ? floatval($cd_row['strength'] ?? 0) : 0;
                    $ratio = '';
                    if ($md_strength > 0 && $cd_strength > 0) {
                        $ratio_value = $cd_strength / $md_strength;
                        $ratio = '1: ' . number_format($ratio_value, 2);
                    }
                    
                    // Display MD row (no ratio shown for MD row)
                    if ($md_row) {
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($md_row['position'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($md_row['direction'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($md_row['strength'] ?? ''); ?></td>
                            <td><?php echo ''; ?></td>
                            <td><?php echo htmlspecialchars($md_row['elongation'] ?? ''); ?></td>
                        </tr>
                        <?php
                    }
                    
                    // Display CD row (ratio shown in CD row)
                    if ($cd_row) {
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($cd_row['position'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($cd_row['direction'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($cd_row['strength'] ?? ''); ?></td>
                            <td><?php echo isset($cd_row['ratio']) && $cd_row['ratio'] !== '' ? htmlspecialchars($cd_row['ratio']) : $ratio; ?></td>
                            <td><?php echo htmlspecialchars($cd_row['elongation'] ?? ''); ?></td>
                        </tr>
                        <?php
                    }
                }
                ?>
            </tbody>
        </table>
        
        <?php if (isset($test_data['summary'])): ?>
        <h4 style="margin-top:25px;">Summary Statistics - Strip Tensile</h4>
        <table class="test-table">
            <thead>
                <tr>
                    <th>Direction</th>
                    <th>Parameter</th>
                    <th>Average</th>
                    <th>SD</th>
                    <th>CV%</th>
                    <th>Max</th>
                    <th>Min</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (['md' => 'Machine Direction (MD)', 'cd' => 'Cross Direction (CD)'] as $dir => $dirName): ?>
                    <?php if (isset($test_data['summary'][$dir])): ?>
                    <tr>
                        <td rowspan="2" style="font-weight:600;"><?php echo $dirName; ?></td>
                        <td>Strength (N)</td>
                        <td><?php echo number_format($test_data['summary'][$dir]['strength_avg'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($test_data['summary'][$dir]['strength_sd'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($test_data['summary'][$dir]['strength_cv'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($test_data['summary'][$dir]['strength_max'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($test_data['summary'][$dir]['strength_min'] ?? 0, 2); ?></td>
                    </tr>
                    <tr>
                        <td>Elongation (%)</td>
                        <td><?php echo number_format($test_data['summary'][$dir]['elongation_avg'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($test_data['summary'][$dir]['elongation_sd'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($test_data['summary'][$dir]['elongation_cv'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($test_data['summary'][$dir]['elongation_max'] ?? 0, 2); ?></td>
                        <td><?php echo number_format($test_data['summary'][$dir]['elongation_min'] ?? 0, 2); ?></td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php elseif (!empty($test_data['cbr_data'])): ?>
        <h4 style="margin-top:0;">Test Data - CBR Puncture Resistance</h4>
        <table class="test-table">
            <thead>
                <tr>
                    <th>Position</th>
                    <th>Force (N)</th>
                    <th>Displacement (mm)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Group CBR data by position base (Left, Middle Left, Middle Right, Right)
                // to display pairs together (Left-1, Left-2, then Middle Left-1, Middle Left-2, etc.)
                $grouped_cbr = [];
                foreach ($test_data['cbr_data'] as $row) {
                    $position = trim($row['position'] ?? '');
                    
                    // Extract base position (e.g., "Left" from "Left-1" or "Left-2")
                    // Handle variations like "Left-1", "Left - 1", etc.
                    $base_pos = preg_replace('/[\s-]*\d+[\s-]*$/', '', $position);
                    $base_pos = trim($base_pos);
                    
                    if (empty($base_pos)) continue;
                    
                    // Extract the number suffix (-1, -2, etc.)
                    if (preg_match('/[\s-]*(\d+)[\s-]*$/', $position, $matches)) {
                        $suffix = (int)$matches[1];
                    } else {
                        $suffix = 0;
                    }
                    
                    if (!isset($grouped_cbr[$base_pos])) {
                        $grouped_cbr[$base_pos] = [];
                    }
                    
                    $grouped_cbr[$base_pos][$suffix] = $row;
                }
                
                // Define display order
                $position_order = ['Left', 'Middle Left', 'Middle Right', 'Right'];
                
                // If we have positions that don't match the standard order, add them
                foreach ($grouped_cbr as $base_pos => $data) {
                    if (!in_array($base_pos, $position_order)) {
                        $position_order[] = $base_pos;
                    }
                }
                
                // Display pairs in order (Left-1, Left-2, then Middle Left-1, Middle Left-2, etc.)
                foreach ($position_order as $base_pos) {
                    if (!isset($grouped_cbr[$base_pos])) continue;
                    
                    // Sort by suffix (1, 2, etc.) to ensure correct order
                    ksort($grouped_cbr[$base_pos]);
                    
                    // Display each position in the pair
                    foreach ($grouped_cbr[$base_pos] as $suffix => $row) {
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['position'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['force'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['displacement'] ?? ''); ?></td>
                        </tr>
                        <?php
                    }
                }
                ?>
            </tbody>
        </table>
        
        <?php if (isset($test_data['summary'])): ?>
        <h4 style="margin-top:25px;">Summary Statistics - CBR</h4>
        <table class="test-table">
            <thead>
                <tr>
                    <th>Parameter</th>
                    <th>Average</th>
                    <th>SD</th>
                    <th>CV%</th>
                    <th>Max</th>
                    <th>Min</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="font-weight:600;">Force (N)</td>
                    <td><?php echo number_format($test_data['summary']['force']['avg'] ?? 0, 2); ?></td>
                    <td><?php echo number_format($test_data['summary']['force']['sd'] ?? 0, 2); ?></td>
                    <td><?php echo number_format($test_data['summary']['force']['cv'] ?? 0, 2); ?></td>
                    <td><?php echo number_format($test_data['summary']['force']['max'] ?? 0, 2); ?></td>
                    <td><?php echo number_format($test_data['summary']['force']['min'] ?? 0, 2); ?></td>
                </tr>
                <tr>
                    <td style="font-weight:600;">Displacement (mm)</td>
                    <td><?php echo number_format($test_data['summary']['displacement']['avg'] ?? 0, 2); ?></td>
                    <td><?php echo number_format($test_data['summary']['displacement']['sd'] ?? 0, 2); ?></td>
                    <td><?php echo number_format($test_data['summary']['displacement']['cv'] ?? 0, 2); ?></td>
                    <td><?php echo number_format($test_data['summary']['displacement']['max'] ?? 0, 2); ?></td>
                    <td><?php echo number_format($test_data['summary']['displacement']['min'] ?? 0, 2); ?></td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php elseif (!empty($test_data['grab_data'])): ?>
        <h4 style="margin-top:0;">Test Data - Grab Tensile Test</h4>
        <table class="test-table">
            <thead>
                <tr>
                    <th>Position</th>
                    <th>Direction</th>
                    <th>Breaking Force (N)</th>
                    <th>Elongation (%)</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Display data grouped by position base (Left, Middle Left, Middle Right, Right)
                // to show Left-1, Left-2 together, then Middle Left-1, Middle Left-2, etc.
                if (!empty($test_data['grab_data']) && is_array($test_data['grab_data'])) {
                    // Group by base position
                    $grouped_grab = [];
                    foreach ($test_data['grab_data'] as $row) {
                        $position = trim($row['position'] ?? '');
                        
                        // Extract base position (e.g., "Left" from "Left-1" or "Left-2")
                        $base_pos = preg_replace('/[\s-]*\d+[\s-]*$/', '', $position);
                        $base_pos = trim($base_pos);
                        
                        if (empty($base_pos)) {
                            // If no base position, add to a catch-all group
                            $base_pos = 'Other';
                        }
                        
                        // Extract suffix number for sorting within group
                        if (preg_match('/[\s-]*(\d+)[\s-]*$/', $position, $matches)) {
                            $suffix = (int)$matches[1];
                        } else {
                            $suffix = 0;
                        }
                        
                        if (!isset($grouped_grab[$base_pos])) {
                            $grouped_grab[$base_pos] = [];
                        }
                        
                        $grouped_grab[$base_pos][$suffix] = $row;
                    }
                    
                    // Define display order
                    $position_order = ['Left', 'Middle Left', 'Middle Right', 'Right'];
                    
                    // Add any other positions that don't match standard order
                    foreach ($grouped_grab as $base_pos => $data) {
                        if (!in_array($base_pos, $position_order)) {
                            $position_order[] = $base_pos;
                        }
                    }
                    
                    // Display grouped by position base, sorted by suffix within each group
                    foreach ($position_order as $base_pos) {
                        if (!isset($grouped_grab[$base_pos])) continue;
                        
                        // Sort by suffix (1, 2, 3, etc.) to ensure correct order within group
                        ksort($grouped_grab[$base_pos]);
                        
                        // Display each position in the group
                        foreach ($grouped_grab[$base_pos] as $suffix => $row) {
                            $force = isset($row['breaking_force']) ? floatval($row['breaking_force']) : 0;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['position'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['direction'] ?? ''); ?></td>
                                <td><?php echo $force > 0 ? number_format($force, 0) : '0'; ?></td>
                                <td><?php echo htmlspecialchars($row['elongation'] ?? ''); ?></td>
                            </tr>
                            <?php
                        }
                    }
                }
                ?>
            </tbody>
        </table>
        
        <?php 
        // Calculate statistics from actual data if summary is missing or has zeros
        $md_forces = [];
        $md_elongations = [];
        $cd_forces = [];
        $cd_elongations = [];
        
        foreach ($test_data['grab_data'] as $row) {
            $direction = strtoupper(trim($row['direction'] ?? ''));
            $force = floatval($row['breaking_force'] ?? 0);
            $elongation = floatval($row['elongation'] ?? 0);
            
            if (strpos($direction, 'MD') !== false || $direction === 'MD') {
                if ($force > 0) $md_forces[] = $force;
                if ($elongation > 0) $md_elongations[] = $elongation;
            } elseif (strpos($direction, 'CD') !== false || $direction === 'CD') {
                if ($force > 0) $cd_forces[] = $force;
                if ($elongation > 0) $cd_elongations[] = $elongation;
            }
        }
        
        // Helper function to calculate statistics
        $calcStats = function($values) {
            if (empty($values)) return ['avg' => 0, 'sd' => 0, 'cv' => 0, 'max' => 0, 'min' => 0];
            $count = count($values);
            $avg = array_sum($values) / $count;
            $variance = 0;
            foreach ($values as $val) {
                $variance += pow($val - $avg, 2);
            }
            $sd = $count > 1 ? sqrt($variance / ($count - 1)) : 0;
            $cv = $avg > 0 ? ($sd / $avg) * 100 : 0;
            return [
                'avg' => $avg,
                'sd' => $sd,
                'cv' => $cv,
                'max' => max($values),
                'min' => min($values)
            ];
        };
        
        $md_force_stats = $calcStats($md_forces);
        $md_elong_stats = $calcStats($md_elongations);
        $cd_force_stats = $calcStats($cd_forces);
        $cd_elong_stats = $calcStats($cd_elongations);
        
        // Use summary if available and has non-zero values, otherwise use calculated stats
        if (isset($test_data['summary']['md'])) {
            $md_summary = $test_data['summary']['md'];
            $md_force_avg = ($md_summary['breaking_force_avg'] ?? $md_summary['force_avg'] ?? 0) > 0 
                ? ($md_summary['breaking_force_avg'] ?? $md_summary['force_avg'] ?? 0) 
                : $md_force_stats['avg'];
            $md_force_sd = ($md_summary['breaking_force_sd'] ?? $md_summary['force_sd'] ?? 0) > 0 
                ? ($md_summary['breaking_force_sd'] ?? $md_summary['force_sd'] ?? 0) 
                : $md_force_stats['sd'];
            $md_force_cv = ($md_summary['breaking_force_cv'] ?? $md_summary['force_cv'] ?? 0) > 0 
                ? ($md_summary['breaking_force_cv'] ?? $md_summary['force_cv'] ?? 0) 
                : $md_force_stats['cv'];
            $md_force_max = ($md_summary['breaking_force_max'] ?? $md_summary['force_max'] ?? 0) > 0 
                ? ($md_summary['breaking_force_max'] ?? $md_summary['force_max'] ?? 0) 
                : $md_force_stats['max'];
            $md_force_min = ($md_summary['breaking_force_min'] ?? $md_summary['force_min'] ?? 0) > 0 
                ? ($md_summary['breaking_force_min'] ?? $md_summary['force_min'] ?? 0) 
                : $md_force_stats['min'];
        } else {
            $md_force_avg = $md_force_stats['avg'];
            $md_force_sd = $md_force_stats['sd'];
            $md_force_cv = $md_force_stats['cv'];
            $md_force_max = $md_force_stats['max'];
            $md_force_min = $md_force_stats['min'];
        }
        
        if (isset($test_data['summary']['cd'])) {
            $cd_summary = $test_data['summary']['cd'];
            $cd_force_avg = ($cd_summary['breaking_force_avg'] ?? $cd_summary['force_avg'] ?? 0) > 0 
                ? ($cd_summary['breaking_force_avg'] ?? $cd_summary['force_avg'] ?? 0) 
                : $cd_force_stats['avg'];
            $cd_force_sd = ($cd_summary['breaking_force_sd'] ?? $cd_summary['force_sd'] ?? 0) > 0 
                ? ($cd_summary['breaking_force_sd'] ?? $cd_summary['force_sd'] ?? 0) 
                : $cd_force_stats['sd'];
            $cd_force_cv = ($cd_summary['breaking_force_cv'] ?? $cd_summary['force_cv'] ?? 0) > 0 
                ? ($cd_summary['breaking_force_cv'] ?? $cd_summary['force_cv'] ?? 0) 
                : $cd_force_stats['cv'];
            $cd_force_max = ($cd_summary['breaking_force_max'] ?? $cd_summary['force_max'] ?? 0) > 0 
                ? ($cd_summary['breaking_force_max'] ?? $cd_summary['force_max'] ?? 0) 
                : $cd_force_stats['max'];
            $cd_force_min = ($cd_summary['breaking_force_min'] ?? $cd_summary['force_min'] ?? 0) > 0 
                ? ($cd_summary['breaking_force_min'] ?? $cd_summary['force_min'] ?? 0) 
                : $cd_force_stats['min'];
        } else {
            $cd_force_avg = $cd_force_stats['avg'];
            $cd_force_sd = $cd_force_stats['sd'];
            $cd_force_cv = $cd_force_stats['cv'];
            $cd_force_max = $cd_force_stats['max'];
            $cd_force_min = $cd_force_stats['min'];
        }
        
        $md_elong_avg = $test_data['summary']['md']['elongation_avg'] ?? $md_elong_stats['avg'];
        $md_elong_sd = $test_data['summary']['md']['elongation_sd'] ?? $md_elong_stats['sd'];
        $md_elong_cv = $test_data['summary']['md']['elongation_cv'] ?? $md_elong_stats['cv'];
        $md_elong_max = $test_data['summary']['md']['elongation_max'] ?? $md_elong_stats['max'];
        $md_elong_min = $test_data['summary']['md']['elongation_min'] ?? $md_elong_stats['min'];
        
        $cd_elong_avg = $test_data['summary']['cd']['elongation_avg'] ?? $cd_elong_stats['avg'];
        $cd_elong_sd = $test_data['summary']['cd']['elongation_sd'] ?? $cd_elong_stats['sd'];
        $cd_elong_cv = $test_data['summary']['cd']['elongation_cv'] ?? $cd_elong_stats['cv'];
        $cd_elong_max = $test_data['summary']['cd']['elongation_max'] ?? $cd_elong_stats['max'];
        $cd_elong_min = $test_data['summary']['cd']['elongation_min'] ?? $cd_elong_stats['min'];
        ?>
        <h4 style="margin-top:25px;">Summary Statistics - Grab Tensile</h4>
        <table class="test-table">
            <thead>
                <tr>
                    <th>Direction</th>
                    <th>Parameter</th>
                    <th>Average</th>
                    <th>SD</th>
                    <th>CV%</th>
                    <th>Max</th>
                    <th>Min</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td rowspan="2" style="font-weight:600;">Machine Direction (MD)</td>
                    <td>Breaking Force (N)</td>
                    <td><?php echo number_format($md_force_avg, 2); ?></td>
                    <td><?php echo number_format($md_force_sd, 2); ?></td>
                    <td><?php echo number_format($md_force_cv, 2); ?></td>
                    <td><?php echo number_format($md_force_max, 2); ?></td>
                    <td><?php echo number_format($md_force_min, 2); ?></td>
                </tr>
                <tr>
                    <td>Elongation (%)</td>
                    <td><?php echo number_format($md_elong_avg, 2); ?></td>
                    <td><?php echo number_format($md_elong_sd, 2); ?></td>
                    <td><?php echo number_format($md_elong_cv, 2); ?></td>
                    <td><?php echo number_format($md_elong_max, 2); ?></td>
                    <td><?php echo number_format($md_elong_min, 2); ?></td>
                </tr>
                <tr>
                    <td rowspan="2" style="font-weight:600;">Cross Direction (CD)</td>
                    <td>Breaking Force (N)</td>
                    <td><?php echo number_format($cd_force_avg, 2); ?></td>
                    <td><?php echo number_format($cd_force_sd, 2); ?></td>
                    <td><?php echo number_format($cd_force_cv, 2); ?></td>
                    <td><?php echo number_format($cd_force_max, 2); ?></td>
                    <td><?php echo number_format($cd_force_min, 2); ?></td>
                </tr>
                <tr>
                    <td>Elongation (%)</td>
                    <td><?php echo number_format($cd_elong_avg, 2); ?></td>
                    <td><?php echo number_format($cd_elong_sd, 2); ?></td>
                    <td><?php echo number_format($cd_elong_cv, 2); ?></td>
                    <td><?php echo number_format($cd_elong_max, 2); ?></td>
                    <td><?php echo number_format($cd_elong_min, 2); ?></td>
                </tr>
            </tbody>
        </table>
        
        <?php elseif (!empty($test_data['fiber_table_data']) || !empty($test_data['yarn_table_data']) || !empty($test_data['tenacity_table_data']) || !empty($test_data['cut_length_table_data'])): ?>
        <!-- For Fiber/Yarn tests -->
        <?php if (!empty($test_data['fiber_table_data'])): ?>
        <h4 style="margin-top:0;">Test Data - <?php echo htmlspecialchars($report['test_name']); ?></h4>
        <table class="test-table">
            <thead>
                <tr>
                    <th>SL No</th>
                    <th>Parameter</th>
                    <th>Standard</th>
                    <th>Unit</th>
                    <th>Result</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($test_data['fiber_table_data'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['sl_no'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['parameter'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['standard'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['unit'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['result'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['remarks'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php if (!empty($test_data['tenacity_table_data'])): ?>
        <h4 style="margin-top:0;">Test Data - Tenacity of Fiber</h4>
        <table class="test-table">
            <thead>
                <tr>
                    <th>SL No</th>
                    <th>Parameter</th>
                    <th>Standard</th>
                    <th>Unit</th>
                    <th>Result</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($test_data['tenacity_table_data'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['sl_no'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['parameter'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['standard'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['unit'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['result'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['remarks'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php if (!empty($test_data['cut_length_table_data'])): ?>
        <h4 style="margin-top:0;">Test Data - Cut Length of Fiber</h4>
        <table class="test-table">
            <thead>
                <tr>
                    <th>SL No</th>
                    <th>Parameter</th>
                    <th>Standard</th>
                    <th>Unit</th>
                    <th>Result</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($test_data['cut_length_table_data'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['sl_no'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['parameter'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['standard'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['unit'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['result'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['remarks'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php if (!empty($test_data['yarn_table_data'])): ?>
        <h4 style="margin-top:0;">Test Data - <?php echo htmlspecialchars($report['test_name']); ?></h4>
        <table class="test-table">
            <thead>
                <tr>
                    <th>SL No</th>
                    <th>Parameter</th>
                    <th>Standard</th>
                    <th>Unit</th>
                    <th>Result</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($test_data['yarn_table_data'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['sl_no'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['parameter'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['standard'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['unit'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['result'] ?? ''); ?></td>
                    <td><?php echo htmlspecialchars($row['remarks'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php else: ?>
        <!-- Display all test data as JSON if no specific structure -->
        <div style="background:#f1f3f5; padding:15px; border-radius:4px; margin-top:10px;">
            <strong>Test Data:</strong>
            <pre style="margin-top:10px; background:white; padding:15px; border-radius:4px; overflow-x:auto; max-height:400px;"><?php echo json_encode($test_data, JSON_PRETTY_PRINT); ?></pre>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Workflow Information -->
    <?php if (!empty($report['checker_remarks']) || !empty($report['admin_remarks'])): ?>
    <div style="margin-top:20px; padding:20px; background:#fff3cd; border-left:4px solid #ffc107; border-radius:4px;">
        <h3 style="margin-top:0; color:#856404;"><i class="fas fa-comment"></i> Remarks</h3>
        <?php if (!empty($report['checker_remarks'])): ?>
        <div style="margin-bottom:15px;">
            <strong>Checker Remarks:</strong>
            <p style="margin:5px 0 0 0; color:#333;"><?php echo nl2br(htmlspecialchars($report['checker_remarks'])); ?></p>
        </div>
        <?php endif; ?>
        <?php if (!empty($report['admin_remarks'])): ?>
        <div>
            <strong>Admin Remarks:</strong>
            <p style="margin:5px 0 0 0; color:#333;"><?php echo nl2br(htmlspecialchars($report['admin_remarks'])); ?></p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Action Buttons for Checker/Admin -->
    <?php if ($report['status'] === 'pending_checker' && $is_checker): ?>
    <div style="margin-top:30px; padding:20px; background:#f8f9fa; border-radius:6px; text-align:center;">
        <p style="margin-bottom:15px; color:#666;">Review the test data above and approve or reject this report:</p>
        <form method="POST" action="../admin/lab_testing_dashboard.php" style="display:inline; margin-right:10px;">
            <input type="hidden" name="report_type" value="qc_test_order">
            <input type="hidden" name="report_number" value="<?php echo htmlspecialchars($report['report_number']); ?>">
            <input type="hidden" name="action" value="approve">
            <button type="submit" style="padding:12px 24px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:15px; font-weight:500;">
                <i class="fas fa-check"></i> Approve This Report
            </button>
        </form>
        <button type="button" onclick="showCheckerRejectModal('<?php echo htmlspecialchars($report['report_number']); ?>')" style="padding:12px 24px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:15px; font-weight:500;">
            <i class="fas fa-times"></i> Reject This Report
        </button>
    </div>
    <?php endif; ?>
    
    <?php if ($report['status'] === 'pending_approval' && $is_admin): ?>
    <div style="margin-top:30px; padding:20px; background:#f8f9fa; border-radius:6px; text-align:center;">
        <p style="margin-bottom:15px; color:#666;">Review the test data above and approve or reject this report:</p>
        <button type="button" id="approveBtn" onclick="approveReport('qc_test_order', '<?php echo htmlspecialchars($report['report_number']); ?>')" style="padding:12px 24px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:15px; font-weight:500; margin-right:10px;">
            <i class="fas fa-check"></i> Approve This Report
        </button>
        <button type="button" onclick="showAdminRejectModal('<?php echo htmlspecialchars($report['report_number']); ?>')" style="padding:12px 24px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:15px; font-weight:500;">
            <i class="fas fa-times"></i> Reject This Report
        </button>
    </div>
    <?php endif; ?>
    
</div>

<!-- Admin Rejection Modal with Checkboxes -->
<div id="adminRejectModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:center;">
  <div style="max-width:600px; margin:50px auto; background:#fff; border-radius:8px; padding:25px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
    <h3 style="margin-top:0; color:#dc3545; border-bottom:2px solid #dc3545; padding-bottom:10px;">
      ❌ Reject QC Test Report
    </h3>
    
    <form id="adminRejectForm" onsubmit="return rejectReport('qc_test_order', document.getElementById('adminRejectReportNumber').value, event)">
      <input type="hidden" name="report_type" value="qc_test_order">
      <input type="hidden" id="adminRejectReportNumber" name="report_number" value="<?php echo htmlspecialchars($report['report_number']); ?>">
      <input type="hidden" name="action" value="reject">
      
      <label style="font-weight:600; display:block; margin-bottom:10px;">Reason for Rejection (Select at least one):</label>
      
      <!-- Checkboxes for Fiber/Yarn Tests -->
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="qc_rejection_reasons[]" value="Incorrect Test Method Applied" style="margin-right:8px;">
          Incorrect Test Method Applied
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="qc_rejection_reasons[]" value="Test Results Out of Specification" style="margin-right:8px;">
          Test Results Out of Specification
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="qc_rejection_reasons[]" value="Insufficient Sample Data" style="margin-right:8px;">
          Insufficient Sample Data
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="qc_rejection_reasons[]" value="Missing Required Information" style="margin-right:8px;">
          Missing Required Information
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="qc_rejection_reasons[]" value="Calculation Errors" style="margin-right:8px;">
          Calculation Errors
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" id="adminRejectOther" name="qc_rejection_reasons[]" value="Other" style="margin-right:8px;">
          Other (Specify below)
        </label>
      </div>
      
      <div style="margin-top:15px;">
        <label style="font-weight:600; display:block; margin-bottom:8px;">Additional Comments (Optional):</label>
        <textarea name="comments" rows="4" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px; font-family:inherit;"></textarea>
      </div>
      
      <div style="margin-top:20px; text-align:right; border-top:1px solid #dee2e6; padding-top:15px;">
        <button type="button" onclick="closeAdminRejectModal()" style="padding:10px 20px; background:#6c757d; color:#fff; border:none; border-radius:4px; cursor:pointer; margin-right:10px;">
          Cancel
        </button>
        <button type="submit" id="adminRejectSubmitBtn" disabled style="padding:10px 20px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:not-allowed; opacity:0.6;">
          <i class="fas fa-times"></i> Confirm Rejection
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function showAdminRejectModal(reportNumber) {
    document.getElementById('adminRejectReportNumber').value = reportNumber;
    document.getElementById('adminRejectModal').style.display = 'flex';
    
    // Enable/disable submit button based on checkbox selection
    const checkboxes = document.querySelectorAll('input[name="qc_rejection_reasons[]"]');
    const submitBtn = document.getElementById('adminRejectSubmitBtn');
    
    checkboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            const anyChecked = Array.from(checkboxes).some(c => c.checked);
            submitBtn.disabled = !anyChecked;
            submitBtn.style.cursor = anyChecked ? 'pointer' : 'not-allowed';
            submitBtn.style.opacity = anyChecked ? '1' : '0.6';
        });
    });
}

function closeAdminRejectModal() {
    document.getElementById('adminRejectModal').style.display = 'none';
    document.getElementById('adminRejectForm').reset();
}

// AJAX function to approve report
function approveReport(reportType, reportNumber) {
    if (!confirm('Are you sure you want to approve this report?')) {
        return;
    }
    
    const btn = document.getElementById('approveBtn');
    if (!btn) {
        console.error('Approve button not found');
        alert('❌ Error: Approve button not found. Please refresh the page.');
        return;
    }
    
    const originalText = btn.innerHTML;
    const originalBackground = btn.style.background;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Approving...';
    btn.style.cursor = 'wait';
    
    const formData = new FormData();
    formData.append('action', 'approve');
    formData.append('report_type', reportType);
    formData.append('report_number', reportNumber);
    
    fetch('../admin/api/approve_reject_report.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Notify parent window to remove this report
            if (typeof notifyParentDashboard === 'function') {
                notifyParentDashboard(reportType, reportNumber, 'approve');
            }
            
            // Check if we came from QC Test Approval Dashboard
            const urlParams = new URLSearchParams(window.location.search);
            const returnPage = urlParams.get('return');
            
            if (returnPage === 'qc_test_approval_dashboard') {
                // Redirect back to QC Test Approval Dashboard
                window.location.href = '../admin/qc_test_approval_dashboard.php';
            } else {
                // Reload the page immediately to show updated status
                window.location.reload();
            }
        } else {
            // Only show error alert if approval failed
            alert('❌ ' + (data.message || 'Failed to approve report. Please try again.'));
            btn.disabled = false;
            btn.innerHTML = originalText;
            btn.style.background = originalBackground;
            btn.style.cursor = 'pointer';
        }
    })
    .catch(error => {
        console.error('Approval error:', error);
        alert('❌ An error occurred while approving the report. Please try again.');
        btn.disabled = false;
        btn.innerHTML = originalText;
        btn.style.background = originalBackground;
        btn.style.cursor = 'pointer';
    });
}

// AJAX function to reject report
function rejectReport(reportType, reportNumber, event) {
    event.preventDefault();
    
    const form = event.target;
    const checkboxes = form.querySelectorAll('input[name="qc_rejection_reasons[]"]');
    const checked = Array.from(checkboxes).filter(cb => cb.checked);
    
    if (checked.length === 0) {
        alert('❌ Please select at least one reason for rejection!');
        return false;
    }
    
    const formData = new FormData(form);
    formData.append('action', 'reject');
    formData.append('report_type', reportType);
    formData.append('report_number', reportNumber);
    
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Rejecting...';
    
    fetch('../admin/api/approve_reject_report.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            closeAdminRejectModal();
            // Notify parent window to remove this report
            notifyParentDashboard(reportType, reportNumber, 'reject');
            // Check if we came from QC Test Approval Dashboard
            const urlParams = new URLSearchParams(window.location.search);
            const returnPage = urlParams.get('return');
            
            // Close this window or redirect
            if (window.opener && !window.opener.closed) {
                window.close();
            } else {
                if (returnPage === 'qc_test_approval_dashboard') {
                    window.location.href = '../admin/qc_test_approval_dashboard.php';
                } else {
                    window.location.href = '../admin/qc_reports_dashboard.php';
                }
            }
        } else {
            alert('❌ ' + data.message);
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('❌ An error occurred. Please try again.');
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
    
    return false;
}

// Notify parent dashboard to remove the report
function notifyParentDashboard(reportType, reportNumber, action) {
    if (window.opener && !window.opener.closed) {
        try {
            // Send message to parent window
            window.opener.postMessage({
                type: 'report_processed',
                report_type: reportType,
                report_number: reportNumber,
                action: action
            }, window.location.origin);
        } catch(e) {
            // Cross-origin or other error, try to refresh parent
            try {
                window.opener.location.href = window.opener.location.href.split("?")[0] + "?t=" + new Date().getTime();
            } catch(e2) {
                // Ignore
            }
        }
    }
}

// Close modal on outside click
document.getElementById('adminRejectModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAdminRejectModal();
    }
});

function showCheckerRejectModal(reportNumber) {
    document.getElementById('checkerRejectReportNumber').value = reportNumber;
    document.getElementById('checkerRejectModal').style.display = 'flex';
    
    // Enable/disable submit button based on checkbox selection
    const checkboxes = document.querySelectorAll('#checkerRejectModal input[name="qc_rejection_reasons[]"]');
    const submitBtn = document.getElementById('checkerRejectSubmitBtn');
    
    checkboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            const anyChecked = Array.from(checkboxes).some(c => c.checked);
            submitBtn.disabled = !anyChecked;
            submitBtn.style.cursor = anyChecked ? 'pointer' : 'not-allowed';
            submitBtn.style.opacity = anyChecked ? '1' : '0.6';
        });
    });
}

function closeCheckerRejectModal() {
    document.getElementById('checkerRejectModal').style.display = 'none';
}

// Close checker modal on outside click
document.getElementById('checkerRejectModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeCheckerRejectModal();
    }
});

// Maintain responsive columns for info-grid sections (desktop: 2 cols, tablet/mobile: 1 col)
(function() {
    function enforceResponsiveColumns() {
        const isTabletOrSmaller = window.innerWidth <= 1024;
        const template = isTabletOrSmaller ? 'repeat(1, 1fr)' : 'repeat(2, 1fr)';
        const grids = document.querySelectorAll('.info-grid');
        grids.forEach(grid => {
            grid.style.setProperty('display', 'grid', 'important');
            grid.style.setProperty('grid-template-columns', template, 'important');
        });
    }
    enforceResponsiveColumns();
    window.addEventListener('load', enforceResponsiveColumns);
    window.addEventListener('resize', enforceResponsiveColumns);
    // Also run after a short delay to override any late-loading styles
    setTimeout(enforceResponsiveColumns, 100);
})();
</script>

<!-- Checker Rejection Modal with Checkboxes -->
<div id="checkerRejectModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:center;">
  <div style="max-width:600px; margin:50px auto; background:#fff; border-radius:8px; padding:25px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
    <h3 style="margin-top:0; color:#dc3545; border-bottom:2px solid #dc3545; padding-bottom:10px;">
      ❌ Reject QC Test Report (Checker Review)
    </h3>
    
    <form id="checkerRejectForm" method="POST" action="../admin/lab_testing_dashboard.php">
      <input type="hidden" name="report_type" value="qc_test_order">
      <input type="hidden" id="checkerRejectReportNumber" name="report_number" value="">
      <input type="hidden" name="action" value="reject">
      
      <label style="font-weight:600; display:block; margin-bottom:10px;">Reason for Rejection (Select at least one):</label>
      
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="qc_rejection_reasons[]" value="Incorrect Test Method Applied" style="margin-right:8px;">
          Incorrect Test Method Applied
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="qc_rejection_reasons[]" value="Test Results Out of Specification" style="margin-right:8px;">
          Test Results Out of Specification
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="qc_rejection_reasons[]" value="Insufficient Sample Data" style="margin-right:8px;">
          Insufficient Sample Data
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="qc_rejection_reasons[]" value="Missing Required Information" style="margin-right:8px;">
          Missing Required Information
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="qc_rejection_reasons[]" value="Calculation Errors" style="margin-right:8px;">
          Calculation Errors
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="qc_rejection_reasons[]" value="Other" style="margin-right:8px;">
          Other (Specify below)
        </label>
      </div>
      
      <div style="margin-top:15px;">
        <label style="font-weight:600; display:block; margin-bottom:8px;">Additional Comments (Optional):</label>
        <textarea name="comments" rows="4" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px; font-family:inherit;"></textarea>
      </div>
      
      <div style="margin-top:20px; text-align:right; border-top:1px solid #dee2e6; padding-top:15px;">
        <button type="button" onclick="closeCheckerRejectModal()" style="padding:10px 20px; background:#6c757d; color:#fff; border:none; border-radius:4px; cursor:pointer; margin-right:10px;">
          Cancel
        </button>
        <button type="submit" id="checkerRejectSubmitBtn" disabled style="padding:10px 20px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:not-allowed; opacity:0.6;">
          <i class="fas fa-times"></i> Confirm Rejection
        </button>
      </div>
    </form>
  </div>
</div>

</body>
</html>


