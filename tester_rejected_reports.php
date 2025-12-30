<?php
session_start();
$devAutoReload = __DIR__ . '/dev/auto_reload.php';
if (is_file($devAutoReload)) {
    include_once $devAutoReload; // Auto-reload for development (optional)
}

// Prevent browser caching to ensure fresh data loads
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'config/security_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$current_user_id = $_SESSION['user_id'];

// Build a safe remarks expression for qc_test_orders based on available columns
$qcRemarksSelect = "''";
$hasCheckerRemarks = false;
$hasAdminRemarks = false;
$hasCheckedBy = false;
$hasApprovedBy = false;
$qctoColumns = $conn->query("SHOW COLUMNS FROM qc_test_orders");
if ($qctoColumns) {
    while ($col = $qctoColumns->fetch_assoc()) {
        $field = strtolower($col['Field']);
        if ($field === 'checker_remarks') {
            $hasCheckerRemarks = true;
        } elseif ($field === 'admin_remarks') {
            $hasAdminRemarks = true;
        } elseif ($field === 'checked_by') {
            $hasCheckedBy = true;
        } elseif ($field === 'approved_by') {
            $hasApprovedBy = true;
        }
    }
}
$remarksParts = [];
if ($hasCheckerRemarks) {
    $remarksParts[] = 'qto.checker_remarks';
}
if ($hasAdminRemarks) {
    $remarksParts[] = 'qto.admin_remarks';
}
if (!empty($remarksParts)) {
    $remarksParts[] = "''";
    $qcRemarksSelect = 'COALESCE(' . implode(', ', $remarksParts) . ')';
}

$selectCheckedBy = $hasCheckedBy ? 'qto.checked_by' : "''";
$selectApprovedBy = $hasApprovedBy ? 'qto.approved_by' : "''";
$joinChecker = $hasCheckedBy 
    ? "LEFT JOIN new_user checker ON qto.checked_by COLLATE utf8mb4_unicode_ci = checker.username COLLATE utf8mb4_unicode_ci"
    : "";
$joinAdmin = $hasApprovedBy 
    ? "LEFT JOIN new_user admin ON qto.approved_by COLLATE utf8mb4_unicode_ci = admin.username COLLATE utf8mb4_unicode_ci"
    : "";
$rejectedByCase = "
    CASE 
        WHEN qto.status = 'rejected_by_checker' THEN " . ($hasCheckedBy ? "COALESCE(checker.full_name, qto.checked_by, 'Checker')" : "'Checker'") . "
        WHEN qto.status = 'rejected_by_approver' THEN " . ($hasApprovedBy ? "COALESCE(admin.full_name, qto.approved_by, 'Admin')" : "'Admin'") . "
        ELSE 'Unknown'
    END
";

// Get all rejected reports for current user
$rejectedReports = [];

// 1. Water Permeability Tests
$result = $conn->query("SELECT id, 'water_perm' as type, 'Water Permeability Test' as test_name, report_number, 
    test_date, test_performed_by as tested_by, status, updated_at, remarks,
    COALESCE(checked_by, approved_by, '') as rejected_by
    FROM water_permeability_tests 
    WHERE status IN ('rejected', 'rejected_by_checker') AND reporter_id = $current_user_id
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rejectedReports[] = $row;
    }
}

// 2. Characteristics Tests
$result = $conn->query("SELECT id, 'characteristics' as type, 'Characteristics Test (ISO 12956)' as test_name, report_number, 
    DATE(sample_tested) as test_date, test_performed_by as tested_by, status, updated_at, remarks,
    COALESCE(checker_name, approver_name, '') as rejected_by
    FROM characteristics_tests 
    WHERE status IN ('rejected', 'rejected_by_checker') AND reporter_id = $current_user_id
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rejectedReports[] = $row;
    }
}

// 3. Fiber Test Reports
$result = $conn->query("SELECT id, 'fiber' as type, 'Fiber Test' as test_name, report_number, 
    sample_tested_date as test_date, test_performed_by as tested_by, status, updated_at, 
    COALESCE(remarks, '') as remarks, approved_by as rejected_by
    FROM fiber_test_reports 
    WHERE status IN ('rejected', 'rejected_by_checker') AND reporter_id = $current_user_id
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rejectedReports[] = $row;
    }
}

// 4. Sewing Thread Reports
$result = $conn->query("SELECT id, 'sewing_thread' as type, 'Sewing Thread Report' as test_name, report_number, 
    test_start_date as test_date, test_performed_by as tested_by, status, updated_at, 
    COALESCE(remarks, '') as remarks, approved_by as rejected_by
    FROM sewing_thread_reports 
    WHERE status = 'rejected' AND reporter_id = $current_user_id
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rejectedReports[] = $row;
    }
}

// 5. Sun Test Reports
$result = $conn->query("SELECT id, 'sun' as type, 'Sun/UV Test' as test_name, report_number, 
    test_start_date as test_date, test_performed_by as tested_by, status, updated_at, remarks,
    '' as rejected_by
    FROM sun_test_reports 
    WHERE status IN ('rejected', 'rejected_by_checker') AND reporter_id = $current_user_id
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rejectedReports[] = $row;
    }
}

// 6. Fabric Pre-Production Tests
$result = $conn->query("SELECT id, 'fabric_pre' as type, 'Fabric Pre-Production Test' as test_name, report_number, 
    sample_received_date as test_date, test_performed_by as tested_by, status, updated_at, 
    COALESCE(checker_remarks, remarks, '') as remarks, COALESCE(checked_by, approved_by, '') as rejected_by
    FROM fabric_pre_production_tests 
    WHERE status IN ('rejected', 'rejected_by_checker', 'rejected_by_approver') AND reporter_id = $current_user_id
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rejectedReports[] = $row;
    }
}

// 7. Fabric After Production Tests
$result = $conn->query("SELECT id, 'fabric_after' as type, 'Fabric After Production Test' as test_name, report_number, 
    sample_received_date as test_date, test_performed_by as tested_by, status, updated_at, 
    COALESCE(remarks, '') as remarks, COALESCE(approved_by, '') as rejected_by
    FROM fabric_after_production_tests 
    WHERE status = 'rejected' AND reporter_id = $current_user_id
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rejectedReports[] = $row;
    }
}

// 8. QC Entries (for QC inspectors)
$result = $conn->query("SELECT id, 'qc_entry' as type, 'QC Entry' as test_name, qc_id as report_number, 
    date_time as test_date, '' as tested_by, status, created_at as updated_at, 
    COALESCE(rejection_reason, remarks, '') as remarks, approved_by as rejected_by
    FROM qc_entries 
    WHERE status = 'rejected' AND reporter_id = $current_user_id
    ORDER BY created_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rejectedReports[] = $row;
    }
}

// 9. QC Test Orders
// Include both regular rejected tests (where user is the owner) AND external rejected tests (any tester can resubmit)
$result = $conn->query("SELECT qto.id, 'qc_test_order' as type, 
    CONCAT(ts.test_name, ' (', qto.chosen_method, ')') as test_name,
    qto.report_number, 
    DATE(qto.created_at) as test_date, 
    qto.inspector_name as tested_by, 
    qto.status, 
    qto.updated_at, 
    qto.sample_reference_id,
    qto.test_data,
    {$qcRemarksSelect} as remarks,
    {$selectCheckedBy} as checked_by,
    {$selectApprovedBy} as approved_by,
    {$rejectedByCase} as rejected_by
    FROM qc_test_orders qto
    LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
    {$joinChecker}
    {$joinAdmin}
    WHERE qto.status IN ('rejected_by_checker', 'rejected_by_approver')
    AND (
        qto.inspector_id = $current_user_id 
        OR 
        (qto.sample_reference_id LIKE 'EXT-%' OR qto.sample_reference_id LIKE 'TOKEN-%' OR JSON_EXTRACT(qto.test_data, '$.is_external_product') IN ('1', 1, true))
    )
    ORDER BY qto.updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rejectedReports[] = $row;
    }
}

// 10. Tenacity of Yarn Reports
$result = $conn->query("SELECT id, 'tenacity_yarn' as type, 'Tenacity of Yarn Report (ASTM D2256)' as test_name, report_number, 
    DATE(test_start_date) as test_date, test_performed_by as tested_by, status, updated_at, 
    COALESCE(remarks, '') as remarks, approved_by as rejected_by
    FROM tenacity_yarn_reports 
    WHERE status = 'rejected' AND reporter_id = $current_user_id
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rejectedReports[] = $row;
    }
}

// 11. Tenacity of Fiber Reports
$result = $conn->query("SELECT id, 'tenacity_fiber' as type, 'Tenacity of Fiber Report (EN ISO 5079)' as test_name, report_number, 
    DATE(test_start_date) as test_date, test_performed_by as tested_by, status, updated_at, 
    COALESCE(remarks, '') as remarks, approved_by as rejected_by
    FROM tenacity_fiber_reports 
    WHERE status = 'rejected' AND reporter_id = $current_user_id
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rejectedReports[] = $row;
    }
}

// 12. Cut Length of Fiber Reports
$result = $conn->query("SELECT id, 'cut_length_fiber' as type, 'Cut Length of Fiber Report (ASTM D5103)' as test_name, report_number, 
    DATE(received_date) as test_date, test_performed_by as tested_by, status, updated_at, 
    COALESCE(remarks, '') as remarks, approved_by as rejected_by
    FROM cut_length_fiber_reports 
    WHERE status = 'rejected' AND reporter_id = $current_user_id
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rejectedReports[] = $row;
    }
}

// 13. Fineness of Fiber Reports
$result = $conn->query("SELECT id, 'fineness_fiber' as type, 'Fineness of Fiber Report (ISO 1973)' as test_name, report_number, 
    DATE(received_date) as test_date, test_performed_by as tested_by, status, updated_at, 
    COALESCE(remarks, '') as remarks, approved_by as rejected_by
    FROM fineness_fiber_reports 
    WHERE status = 'rejected' AND reporter_id = $current_user_id
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rejectedReports[] = $row;
    }
}

// Sort by updated_at descending
usort($rejectedReports, function($a, $b) {
    return strtotime($b['updated_at']) - strtotime($a['updated_at']);
});

$totalRejected = count($rejectedReports);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rejected Reports Dashboard - Tester</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { 
        font-family: 'Inter', sans-serif; 
        background: linear-gradient(135deg,rgb(30, 39, 83) 0%,rgb(234, 226, 241) 100%);
        padding: 5px 10px 5px 5px;
        color: #2c3e50;
    }
    .container {
        max-width: 100%;
        margin: 0;
        background: white;
        border-radius: 16px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        overflow: hidden;
    }
    .header {
        background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        color: white;
        padding: 30px;
        text-align: center;
    }
    .header h1 {
        font-size: 32px;
        margin-bottom: 10px;
        font-weight: 700;
    }
    .header p {
        font-size: 16px;
        opacity: 0.9;
    }
    .stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        padding: 30px;
        background:rgb(124, 148, 121);
    }
    .stat-card {
        background: white;
        padding: 20px;
        border-radius: 12px;
        box-shadow: 0 2px 10px rgba(48, 11, 11, 0.08);
        text-align: center;
        border-left: 4px solidrgb(90, 21, 13);
    }
    .stat-number {
        font-size: 36px;
        font-weight: 700;
        color:rgb(121, 27, 16);
        margin-bottom: 5px;
    }
    .stat-label {
        font-size: 14px;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .content {
        padding: 30px;
    }
    .back-btn {
        background: #e74c3c;
        color: white;
        padding: 10px 20px;
        border-radius: 6px;
        text-decoration: none;
        display: inline-block;
        margin-bottom: 20px;
        font-size: 14px;
        transition: background 0.3s;
    }
    .back-btn:hover {
        background: #c0392b;
    }
    .table-wrapper {
        overflow-x: auto;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    }
    table {
        width: 100%;
        border-collapse: collapse;
        background: white;
        font-size: 13px;
    }
    th {
        background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
        color: white;
        padding: 12px;
        text-align: left;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 11px;
        letter-spacing: 0.5px;
    }
    td {
        padding: 12px;
        border-bottom: 1px solid #e9ecef;
    }
    tr:hover {
        background: #f8f9fa;
    }
    .test-badge {
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
    }
    .badge-water { background: #e0f2f1; color: #00796b; }
    .badge-char { background: #fff3e0; color: #e65100; }
    .badge-fiber { background: #f3e5f5; color: #7b1fa2; }
    .badge-sewing_thread { background: #ede7f6; color: #5e35b1; }
    .badge-sun { background: #fff9c4; color: #f57f17; }
    .badge-fabric_pre { background: #e3f2fd; color: #1976d2; }
    .badge-fabric_after { background: #e8f5e9; color: #388e3c; }
    .badge-qc_entry { background: #fce4ec; color: #c2185b; }
    .badge-tenacity_yarn { background: #e1bee7; color: #6a1b9a; }
    .badge-tenacity_fiber { background: #ffccbc; color: #bf360c; }
    .badge-cut_length_fiber { background: #b2dfdb; color: #004d40; }
    .badge-fineness_fiber { background: #c5e1a5; color: #33691e; }
    
    .status-badge {
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
    }
    .status-rejected { background: #f8d7da; color: #721c24; }
    
    .action-btn {
        padding: 6px 12px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 12px;
        font-weight: 600;
        transition: all 0.3s;
        display: inline-block;
    }
    .btn-resubmit {
        background: #f39c12;
        color: white;
    }
    .btn-resubmit:hover {
        background: #e67e22;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(243, 156, 18, 0.3);
    }
    .empty-state {
        text-align: center;
        padding: 60px 30px;
        color: #6c757d;
    }
    .empty-state i {
        font-size: 64px;
        margin-bottom: 20px;
        opacity: 0.3;
    }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1><i class="fas fa-exclamation-triangle"></i> Rejected Reports Dashboard</h1>
        <p>Reports returned by Checker/Admin that need resubmission</p>
    </div>
    
    <div class="stats">
        <div class="stat-card">
            <div class="stat-number"><?php echo $totalRejected; ?></div>
            <div class="stat-label">Total Rejected Reports</div>
        </div>
    </div>
    
    <div class="content">
        <a href="index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        
        <?php if ($totalRejected > 0): ?>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Test Type</th>
                        <th>Report Number</th>
                        <th>Test Date</th>
                        <th>Rejected By</th>
                        <th>Rejection Reason</th>
                        <th>Last Updated</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    foreach ($rejectedReports as $report): 
                        $view_link = '';
                        switch($report['type']) {
                            case 'fabric_pre':
                                $view_link = 'forms/edit_fabric_pre_prod.php?id=' . $report['id'];
                                break;
                            case 'water_perm':
                                $view_link = 'forms/edit_water_permeability.php?edit=' . $report['id'];
                                break;
                            case 'characteristics':
                                $view_link = 'forms/edit_characteristics.php?id=' . $report['id'];
                                break;
                            case 'fiber':
                                $view_link = 'forms/fiber_test_report.php?id=' . $report['id'];
                                break;
                            case 'sewing_thread':
                                $view_link = 'forms/sewing_thread_report.php?id=' . $report['id'];
                                break;
                            case 'sun':
                                $view_link = 'forms/edit_sun_report.php?id=' . $report['id'];
                                break;
                            case 'fabric_after':
                                $view_link = 'forms/edit_fabric_after_prod.php?id=' . $report['id'];
                                break;
                            case 'qc_entry':
                                $view_link = 'forms/qc_entry.php?edit=' . $report['id'];
                                break;
                            case 'qc_test_order':
                                $view_link = 'forms/qc_test_order.php?edit=' . $report['id'];
                                break;
                            case 'tenacity_yarn':
                                $view_link = 'forms/tenacity_yarn_report.php?id=' . $report['id'];
                                break;
                            case 'tenacity_fiber':
                                $view_link = 'forms/tenacity_fiber_report.php?id=' . $report['id'];
                                break;
                            case 'cut_length_fiber':
                                $view_link = 'forms/cut_length_fiber_report.php?id=' . $report['id'];
                                break;
                            case 'fineness_fiber':
                                $view_link = 'forms/fineness_fiber_report.php?id=' . $report['id'];
                                break;
                        }
                    ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td>
                            <span class="test-badge badge-<?php echo $report['type']; ?>">
                                <?php 
                                // For QC Test Orders, show test name and method
                                echo htmlspecialchars($report['test_name']); 
                                ?>
                            </span>
                        </td>
                        <td><strong><?php echo htmlspecialchars($report['report_number']); ?></strong></td>
                        <td><?php echo date('M d, Y', strtotime($report['test_date'])); ?></td>
                        <td><?php echo htmlspecialchars($report['rejected_by'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars(substr($report['remarks'] ?? 'No reason', 0, 100)); ?></td>
                        <td><?php echo date('M d, Y H:i', strtotime($report['updated_at'])); ?></td>
                        <td>
                            <a href="<?php echo $view_link; ?>" class="action-btn btn-resubmit">
                                <i class="fas fa-edit"></i> Resubmit
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-check-circle"></i>
            <h3>No Rejected Reports!</h3>
            <p>You don't have any reports awaiting resubmission. Great work!</p>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>

