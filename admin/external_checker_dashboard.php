<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if ($user_role !== 'checker') {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>Only Checkers can access this dashboard.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$message = '';
$error = '';

if (isset($_GET['success'])) {
    $message = $_GET['success'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $test_id = (int)($_POST['test_id'] ?? 0);
    $checker_remarks = trim($_POST['checker_remarks'] ?? '');
    $checker_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown';
    $checked_at = date('Y-m-d H:i:s');
    
    if ($test_id > 0) {
        if ($action === 'approve') {
            // Approve: Change status to pending_approval (for admin/AGM final approval)
            $stmt = $conn->prepare("UPDATE qc_test_orders SET status = 'pending_approval', checked_by = ?, checked_at = ?, checker_remarks = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("sssi", $checker_name, $checked_at, $checker_remarks, $test_id);
            if ($stmt->execute()) {
                $_SESSION['qc_success_message'] = "Test approved successfully. It will be forwarded to admin/AGM for final approval.";
                header("Location: external_checker_dashboard.php");
                exit();
            } else {
                $error = "Failed to approve test: " . $conn->error;
            }
        } elseif ($action === 'reject') {
            // Reject: Change status to rejected_by_checker (bounces back to tester)
            // Handle rejection reasons checkboxes
            $rejection_reasons = [];
            if (isset($_POST['qc_rejection_reasons']) && is_array($_POST['qc_rejection_reasons'])) {
                $rejection_reasons = array_map('trim', $_POST['qc_rejection_reasons']);
            }
            
            // Combine rejection reasons with additional comments
            $additional_comments = trim($_POST['checker_remarks'] ?? '');
            if (!empty($rejection_reasons)) {
                $reasons_text = implode(', ', $rejection_reasons);
                $checker_remarks = "Rejection Reasons: " . $reasons_text;
                if (!empty($additional_comments)) {
                    $checker_remarks .= "\n\nAdditional Comments: " . $additional_comments;
                }
            } else {
                $checker_remarks = !empty($additional_comments) ? $additional_comments : "Rejected by checker";
            }
            
            $stmt = $conn->prepare("UPDATE qc_test_orders SET status = 'rejected_by_checker', checked_by = ?, checked_at = ?, checker_remarks = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("sssi", $checker_name, $checked_at, $checker_remarks, $test_id);
            if ($stmt->execute()) {
                $_SESSION['qc_success_message'] = "Test rejected. It will be sent back to the tester for resubmission.";
                header("Location: external_checker_dashboard.php");
                exit();
            } else {
                $error = "Failed to reject test: " . $conn->error;
            }
        }
    } else {
        $error = "Invalid test ID";
    }
}

// Check for success message from session (after redirect)
if (isset($_SESSION['qc_success_message'])) {
    $message = $_SESSION['qc_success_message'];
    unset($_SESSION['qc_success_message']);
}

$external_tests = [];
// SIMPLE: Fetch tests with pending_checker status that are external
// External tests should ONLY show here, not in regular QC Test Order dashboard
// Include tests with:
// 1. EXT- or TOKEN- prefix in sample_reference_id
// 2. is_external_product flag in test_data JSON
$qctoCols = [];
$colRes = $conn->query("SHOW COLUMNS FROM qc_test_orders");
if ($colRes) {
    while ($r = $colRes->fetch_assoc()) {
        $qctoCols[] = strtolower($r['Field']);
    }
}
$hasStatus = in_array('status', $qctoCols, true);
$hasUpdatedAt = in_array('updated_at', $qctoCols, true);
$statusSelect = $hasStatus ? "qto.status" : "'' as status";
$statusFilter = $hasStatus ? "qto.status = 'pending_checker' AND" : "";
$updatedSelect = $hasUpdatedAt ? "qto.updated_at" : "qto.created_at as updated_at";
$orderUpdated = $hasUpdatedAt ? "qto.updated_at" : "qto.created_at";

$result = $conn->query("SELECT qto.*, ts.test_name, ts.standard_code,
    $statusSelect,
    $updatedSelect,
    LOWER(TRIM(u.role)) as inspector_role
    FROM qc_test_orders qto
    LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
    LEFT JOIN new_user u ON qto.inspector_id = u.id
    WHERE $statusFilter (
        qto.sample_reference_id LIKE 'EXT-%' 
        OR qto.sample_reference_id LIKE 'TOKEN-%' 
        OR JSON_EXTRACT(qto.test_data, '$.is_external_product') IN ('1', 1, true)
    )
    ORDER BY qto.sample_reference_id ASC, $orderUpdated DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $test_data = json_decode($row['test_data'] ?? '{}', true);
        
        // Only include tests that HAVE been submitted (have test results)
        // Don't filter by inspector_role - tests created by AGM but submitted by tester should show
        $has_test_results = false;
            
            // Check for statistics (indicates test was completed)
            if (isset($test_data['average']) && (floatval($test_data['average']) > 0 || $test_data['average'] !== '')) {
                $has_test_results = true;
            }
            if (isset($test_data['avg']) && (floatval($test_data['avg']) > 0 || $test_data['avg'] !== '')) {
                $has_test_results = true;
            }
            if (isset($test_data['sd']) && floatval($test_data['sd']) > 0) {
                $has_test_results = true;
            }
            if (isset($test_data['cv']) && floatval($test_data['cv']) > 0) {
                $has_test_results = true;
            }
            if (isset($test_data['max']) && floatval($test_data['max']) > 0) {
                $has_test_results = true;
            }
            if (isset($test_data['min']) && floatval($test_data['min']) > 0) {
                $has_test_results = true;
            }
            if (isset($test_data['summary']) && is_array($test_data['summary']) && !empty($test_data['summary'])) {
                $has_test_results = true;
            }
            
            // Check for test data arrays with actual values
            if (isset($test_data['positions']) && is_array($test_data['positions']) && count($test_data['positions']) > 0) {
                foreach ($test_data['positions'] as $pos) {
                    if ((isset($pos['value']) && floatval($pos['value']) > 0) ||
                        (isset($pos['thickness']) && floatval($pos['thickness']) > 0) ||
                        (isset($pos['weight']) && floatval($pos['weight']) > 0) ||
                        (isset($pos['gsm']) && floatval($pos['gsm']) > 0)) {
                        $has_test_results = true;
                        break;
                    }
                }
            }
            
            if (isset($test_data['strip_data']) && is_array($test_data['strip_data']) && count($test_data['strip_data']) > 0) {
                foreach ($test_data['strip_data'] as $strip) {
                    if (isset($strip['strength']) && floatval($strip['strength']) > 0) {
                        $has_test_results = true;
                        break;
                    }
                }
            }
            
            if (isset($test_data['cbr_data']) && is_array($test_data['cbr_data']) && count($test_data['cbr_data']) > 0) {
                foreach ($test_data['cbr_data'] as $cbr) {
                    if (isset($cbr['force']) && floatval($cbr['force']) > 0) {
                        $has_test_results = true;
                        break;
                    }
                }
            }
            
            if (isset($test_data['grab_data']) && is_array($test_data['grab_data']) && count($test_data['grab_data']) > 0) {
                foreach ($test_data['grab_data'] as $grab) {
                    if (isset($grab['strength']) && floatval($grab['strength']) > 0) {
                        $has_test_results = true;
                        break;
                    }
                }
            }
            
            if (isset($test_data['weathering_data']) && is_array($test_data['weathering_data']) && count($test_data['weathering_data']) > 0) {
                $has_test_results = true;
            }
            
            if (isset($test_data['seam_data']) && is_array($test_data['seam_data']) && count($test_data['seam_data']) > 0) {
                $has_test_results = true;
            }
            
        // Only include if test has been submitted (has test results)
        // If status is pending_checker, it means tester has submitted it, so include it
        // even if test results check fails (might be edge case with zero values)
        if ($has_test_results || $row['status'] === 'pending_checker') {
            $row['external_reference'] = $test_data['external_reference'] ?? $row['sample_reference_id'] ?? 'N/A';
            $row['batch_information'] = $test_data['batch_information'] ?? $test_data['batch_info'] ?? 'N/A';
            $row['sample_details'] = $test_data['sample_details'] ?? 'N/A';
            $row['sample_received_from'] = $test_data['sample_received_from'] ?? 'N/A';
            $row['customer_reference'] = $test_data['customer_reference'] ?? 'N/A';
            $row['sample_collected_from'] = $test_data['sample_collected_from'] ?? 'N/A';
            $external_tests[] = $row;
        }
    }
}

$grouped_tests = [];
foreach ($external_tests as $test) {
    $ref = $test['external_reference'] ?? 'Unknown';
    if (!isset($grouped_tests[$ref])) {
        $grouped_tests[$ref] = [];
    }
    $grouped_tests[$ref][] = $test;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>External Test Checker Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --bg-body: #f1f4fb;
            --card-bg: #ffffff;
            --primary: #4f46e5;
            --primary-dark: #4338ca;
            --secondary: #ec4899;
            --accent: #06b6d4;
            --text-dark: #1f2937;
            --text-muted: #6b7280;
            --border: #e2e8f0;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg-body);
            padding: 32px 20px 80px;
            color: var(--text-dark);
        }
        .container {
            max-width: 1460px;
            margin: 0 auto;
        }
        .header {
            background: linear-gradient(120deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 28px 32px;
            border-radius: 16px;
            margin-bottom: 28px;
            box-shadow: 0 15px 35px rgba(79, 70, 229, 0.35);
            position: relative;
            overflow: hidden;
        }
        .header::after {
            content: '';
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.12);
            top: -40px;
            right: -60px;
        }
        .header h1 {
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
            z-index: 1;
        }
        .header p {
            opacity: 0.95;
            font-size: 15px;
            position: relative;
            z-index: 1;
        }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
            transition: all 0.3s;
        }
        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
        }
        .test-group {
            background: white;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            overflow: hidden;
            border: 1px solid var(--border);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .test-group:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        .group-header {
            background: linear-gradient(120deg, #8b5cf6 0%, #7c3aed 100%);
            color: white;
            padding: 18px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            font-size: 16px;
        }
        .badge {
            background: rgba(255,255,255,0.2);
            padding: 6px 14px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 13px;
        }
        .test-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card-bg);
        }
        .test-table thead {
            background: #f1f5f9;
        }
        .test-table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            font-size: 13px;
        }
        .test-table th:nth-child(3) { background:#e0e7ff; color:#312e81; }
        .test-table th:nth-child(4) { background:#fef3c7; color:#7c2d12; }
        .test-table th:nth-child(5) { background:#dcfce7; color:#065f46; }
        .test-table th:nth-child(6) { background:#ffe4e6; color:#9f1239; }
        .test-table td {
            padding: 15px;
            border-bottom: 1px solid #e9ecef;
            font-size: 14px;
            color: var(--text-dark);
        }
        .test-table tbody tr:hover {
            background: #fdf4ff;
        }
        .test-table tbody tr:last-child td {
            border-bottom: none;
        }
        .col-batch {
            background:#eef2ff;
            color:#312e81;
            font-weight:600;
            padding: 8px 12px;
            border-radius: 6px;
            display: inline-block;
        }
        .col-sample {
            background:#fef9c3;
            color:#7c2d12;
            font-weight:600;
            padding: 8px 12px;
            border-radius: 6px;
            display: inline-block;
        }
        .col-received {
            background:#dcfce7;
            color:#065f46;
            font-weight:600;
            padding: 8px 12px;
            border-radius: 6px;
            display: inline-block;
        }
        .col-customer {
            background:#ffe4e6;
            color:#9f1239;
            font-weight:600;
            padding: 8px 12px;
            border-radius: 6px;
            display: inline-block;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-pending_checker {
            background: #fff3cd;
            color: #856404;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn-view, .btn-approve, .btn-reject {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .btn-view {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }
        .btn-view:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4);
        }
        .btn-approve {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        .btn-approve:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, 0.4);
        }
        .btn-reject {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        .btn-reject:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(239, 68, 68, 0.4);
        }
        .status-muted {
            color: var(--text-muted);
            font-size: 13px;
        }
        @media (max-width: 992px) {
            body {
                padding: 24px 16px 60px;
            }
            .header {
                padding: 24px;
            }
            .test-table thead {
                display: none;
            }
            .test-table, .test-table tbody, .test-table tr, .test-table td {
                display: block;
                width: 100%;
            }
            .test-table tr {
                margin-bottom: 18px;
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                padding: 12px 14px;
                background: white;
            }
            .test-table td {
                border: none;
                padding: 8px 0;
            }
            .test-table td::before {
                content: attr(data-label);
                font-weight: 600;
                display: block;
                font-size: 12px;
                text-transform: uppercase;
                color: var(--text-muted);
                margin-bottom: 2px;
            }
            .action-buttons {
                flex-direction: column;
                width: 100%;
            }
            .btn-view, .btn-approve, .btn-reject {
                width: 100%;
                justify-content: center;
            }
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
        }
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 10px;
            color: #475569;
        }
        .empty-state p {
            font-size: 16px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="../index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

        <div class="header">
            <h1><i class="fas fa-vial"></i> External Test Checker Dashboard</h1>
            <p>Review external QC test orders submitted by testers</p>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (empty($grouped_tests)): ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h3>No Pending External Tests</h3>
            <p>External QC test orders submitted by testers will appear here for checking.</p>
        </div>
        <?php else: ?>
            <?php foreach ($grouped_tests as $ref => $tests): ?>
            <div class="test-group">
                <div class="group-header">
                    <div><i class="fas fa-tag"></i> External Reference: <?php echo htmlspecialchars($ref); ?></div>
                    <span class="badge"><i class="fas fa-flask"></i> <?php echo count($tests); ?> test(s)</span>
                </div>
                <table class="test-table">
                    <thead>
                        <tr>
                            <th>Report No</th>
                            <th>Test Name</th>
                            <th>Method</th>
                            <th>Batch Info</th>
                            <th>Sample Details</th>
                            <th>Received From</th>
                            <th>Customer Ref</th>
                            <th>Status</th>
                            <th>Submitted At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tests as $test): 
                            $statusClass = 'status-' . str_replace([' ', '-'], '_', strtolower($test['status']));
                            $statusLabel = 'Pending Checker';
                        ?>
                        <tr>
                            <td data-label="Report No"><strong><?php echo htmlspecialchars($test['report_number']); ?></strong></td>
                            <td data-label="Test Name"><?php echo htmlspecialchars($test['test_name'] ?? 'N/A'); ?></td>
                            <td data-label="Method"><?php echo htmlspecialchars($test['chosen_method'] ?? 'N/A'); ?></td>
                            <td data-label="Batch Info">
                                <?php if (!empty($test['batch_information']) && $test['batch_information'] !== 'N/A'): ?>
                                    <span class="col-batch"><?php echo htmlspecialchars($test['batch_information']); ?></span>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">-</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Sample Details">
                                <?php if (!empty($test['sample_details']) && $test['sample_details'] !== 'N/A'): ?>
                                    <span class="col-sample"><?php echo htmlspecialchars($test['sample_details']); ?></span>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">-</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Received From">
                                <?php if (!empty($test['sample_received_from']) && $test['sample_received_from'] !== 'N/A'): ?>
                                    <span class="col-received"><?php echo htmlspecialchars($test['sample_received_from']); ?></span>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">-</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Customer Ref">
                                <?php if (!empty($test['customer_reference']) && $test['customer_reference'] !== 'N/A'): ?>
                                    <span class="col-customer"><?php echo htmlspecialchars($test['customer_reference']); ?></span>
                                <?php else: ?>
                                    <span style="color: #9ca3af;">-</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Status">
                                <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                    <?php echo htmlspecialchars($statusLabel); ?>
                                </span>
                            </td>
                            <td data-label="Submitted At"><?php echo date('M d, Y - g:i A', strtotime($test['updated_at'])); ?></td>
                            <td data-label="Action">
                                <div class="action-buttons">
                                    <a class="btn-view" href="../admin/view_qc_test_order.php?id=<?php echo (int)$test['id']; ?>&return=external_checker_dashboard" target="_blank">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <button class="btn-approve" onclick="openApproveModal(<?php echo (int)$test['id']; ?>, '<?php echo htmlspecialchars($test['report_number'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-check"></i> Approve
                                    </button>
                                    <button class="btn-reject" onclick="openRejectModal(<?php echo (int)$test['id']; ?>, '<?php echo htmlspecialchars($test['report_number'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Approve Modal -->
    <div id="approveModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-check-circle"></i> Approve Test</h3>
                <span class="modal-close" onclick="closeApproveModal()">&times;</span>
            </div>
            <form id="approveForm" method="POST">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="test_id" id="approve_test_id">
                <div class="modal-body">
                    <p>Are you sure you want to approve <strong id="approve_report_number"></strong>?</p>
                    <p style="color: #6b7280; font-size: 14px; margin-top: 10px;">This test will be forwarded to admin/AGM for final approval.</p>
                    <div style="margin-top: 20px;">
                        <label for="approve_remarks" style="display: block; margin-bottom: 8px; font-weight: 600; color: #374151;">Remarks (Optional):</label>
                        <textarea id="approve_remarks" name="checker_remarks" rows="4" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-family: inherit; font-size: 14px;" placeholder="Add any remarks or comments..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" onclick="closeApproveModal()">Cancel</button>
                    <button type="submit" class="btn-modal-submit">
                        <i class="fas fa-check"></i> Approve Test
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle"></i> Reject Test</h3>
                <span class="modal-close" onclick="closeRejectModal()">&times;</span>
            </div>
            <form id="rejectForm" method="POST">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="test_id" id="reject_test_id">
                <div class="modal-body">
                    <p>Are you sure you want to reject <strong id="reject_report_number"></strong>?</p>
                    <p style="color: #dc2626; font-size: 14px; margin-top: 10px;">This test will be sent back to the tester for resubmission.</p>
                    <div style="margin-top: 20px;">
                        <label style="display: block; margin-bottom: 12px; font-weight: 600; color: #374151;">Rejection Reasons <span style="color: #dc2626;">*</span>:</label>
                        <div style="background: #f9fafb; padding: 15px; border-radius: 6px; border: 1px solid #e5e7eb; max-height: 250px; overflow-y: auto;">
                            <label style="display: flex; align-items: center; margin-bottom: 10px; cursor: pointer; padding: 8px; border-radius: 4px; transition: background 0.2s;">
                                <input type="checkbox" name="qc_rejection_reasons[]" value="Incorrect Test Method Applied" style="margin-right: 10px; width: 18px; height: 18px; cursor: pointer;">
                                <span>Incorrect Test Method Applied</span>
                            </label>
                            <label style="display: flex; align-items: center; margin-bottom: 10px; cursor: pointer; padding: 8px; border-radius: 4px; transition: background 0.2s;">
                                <input type="checkbox" name="qc_rejection_reasons[]" value="Missing or Incomplete Test Data" style="margin-right: 10px; width: 18px; height: 18px; cursor: pointer;">
                                <span>Missing or Incomplete Test Data</span>
                            </label>
                            <label style="display: flex; align-items: center; margin-bottom: 10px; cursor: pointer; padding: 8px; border-radius: 4px; transition: background 0.2s;">
                                <input type="checkbox" name="qc_rejection_reasons[]" value="Test Results Out of Specification" style="margin-right: 10px; width: 18px; height: 18px; cursor: pointer;">
                                <span>Test Results Out of Specification</span>
                            </label>
                            <label style="display: flex; align-items: center; margin-bottom: 10px; cursor: pointer; padding: 8px; border-radius: 4px; transition: background 0.2s;">
                                <input type="checkbox" name="qc_rejection_reasons[]" value="Calculation Errors" style="margin-right: 10px; width: 18px; height: 18px; cursor: pointer;">
                                <span>Calculation Errors</span>
                            </label>
                            <label style="display: flex; align-items: center; margin-bottom: 10px; cursor: pointer; padding: 8px; border-radius: 4px; transition: background 0.2s;">
                                <input type="checkbox" name="qc_rejection_reasons[]" value="Incorrect Sample Information" style="margin-right: 10px; width: 18px; height: 18px; cursor: pointer;">
                                <span>Incorrect Sample Information</span>
                            </label>
                            <label style="display: flex; align-items: center; margin-bottom: 10px; cursor: pointer; padding: 8px; border-radius: 4px; transition: background 0.2s;">
                                <input type="checkbox" name="qc_rejection_reasons[]" value="Test Not Performed According to Standard" style="margin-right: 10px; width: 18px; height: 18px; cursor: pointer;">
                                <span>Test Not Performed According to Standard</span>
                            </label>
                            <label style="display: flex; align-items: center; margin-bottom: 10px; cursor: pointer; padding: 8px; border-radius: 4px; transition: background 0.2s;">
                                <input type="checkbox" id="reject_other_checkbox" name="qc_rejection_reasons[]" value="Other" onchange="toggleOtherReason()" style="margin-right: 10px; width: 18px; height: 18px; cursor: pointer;">
                                <span>Other (please specify)</span>
                            </label>
                            <textarea id="reject_other_reason" name="checker_remarks" rows="3" style="width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-family: inherit; font-size: 14px; margin-top: 8px; display: none;" placeholder="Enter other reason..."></textarea>
                        </div>
                        <p style="font-size: 12px; color: #6b7280; margin-top: 8px;">Please select at least one rejection reason.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-modal-cancel" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="btn-modal-submit" id="rejectSubmitBtn" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);" disabled>
                        <i class="fas fa-times"></i> Reject Test
                    </button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
        }
        .modal-content {
            background-color: #ffffff;
            margin: 5% auto;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease-out;
        }
        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
            border-radius: 12px 12px 0 0;
        }
        .modal-header h3 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .modal-close {
            color: #6b7280;
            font-size: 28px;
            font-weight: 300;
            cursor: pointer;
            line-height: 1;
            transition: color 0.2s;
        }
        .modal-close:hover {
            color: #1f2937;
        }
        .modal-body {
            padding: 24px;
        }
        .modal-body p {
            margin: 0 0 10px 0;
            color: #374151;
            font-size: 15px;
            line-height: 1.6;
        }
        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            background: #f9fafb;
            border-radius: 0 0 12px 12px;
        }
        .btn-modal-cancel {
            padding: 10px 20px;
            background: #f3f4f6;
            color: #374151;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-modal-cancel:hover {
            background: #e5e7eb;
            border-color: #9ca3af;
        }
        .btn-modal-submit {
            padding: 10px 20px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-modal-submit:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        .btn-modal-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .btn-modal-submit:disabled:hover {
            transform: none;
            box-shadow: none;
        }
        #rejectForm label:hover {
            background: #f3f4f6;
        }
        #rejectForm input[type="checkbox"]:checked + span {
            font-weight: 600;
            color: #1f2937;
        }
    </style>

    <script>
        function openApproveModal(testId, reportNumber) {
            document.getElementById('approve_test_id').value = testId;
            document.getElementById('approve_report_number').textContent = reportNumber;
            document.getElementById('approveModal').style.display = 'block';
        }

        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
            document.getElementById('approve_remarks').value = '';
        }

        function openRejectModal(testId, reportNumber) {
            document.getElementById('reject_test_id').value = testId;
            document.getElementById('reject_report_number').textContent = reportNumber;
            document.getElementById('rejectModal').style.display = 'block';
            
            // Reset form
            document.querySelectorAll('#rejectForm input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
                cb.addEventListener('change', validateRejectForm);
            });
            const otherTextarea = document.getElementById('reject_other_reason');
            otherTextarea.value = '';
            otherTextarea.style.display = 'none';
            // Add event listener to "Other" textarea (only once)
            otherTextarea.removeEventListener('input', validateRejectForm);
            otherTextarea.addEventListener('input', validateRejectForm);
            
            // Validate on load
            validateRejectForm();
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
            // Reset all checkboxes
            document.querySelectorAll('#rejectForm input[type="checkbox"]').forEach(cb => {
                cb.checked = false;
                cb.removeEventListener('change', validateRejectForm);
            });
            document.getElementById('reject_other_reason').value = '';
            document.getElementById('reject_other_reason').style.display = 'none';
            // Reset submit button
            const submitBtn = document.querySelector('#rejectForm .btn-modal-submit');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.6';
                submitBtn.style.cursor = 'not-allowed';
            }
        }
        
        function toggleOtherReason() {
            const otherCheckbox = document.getElementById('reject_other_checkbox');
            const otherTextarea = document.getElementById('reject_other_reason');
            if (otherCheckbox.checked) {
                otherTextarea.style.display = 'block';
                otherTextarea.required = true;
            } else {
                otherTextarea.style.display = 'none';
                otherTextarea.required = false;
                otherTextarea.value = '';
            }
            validateRejectForm();
        }
        
        function validateRejectForm() {
            const checkboxes = document.querySelectorAll('#rejectForm input[name="qc_rejection_reasons[]"]');
            const submitBtn = document.querySelector('#rejectForm .btn-modal-submit');
            let atLeastOneChecked = false;
            
            checkboxes.forEach(cb => {
                if (cb.checked) {
                    atLeastOneChecked = true;
                }
            });
            
            // If "Other" is checked, make sure textarea has value
            const otherCheckbox = document.getElementById('reject_other_checkbox');
            const otherTextarea = document.getElementById('reject_other_reason');
            if (otherCheckbox && otherCheckbox.checked) {
                if (!otherTextarea.value.trim()) {
                    atLeastOneChecked = false;
                }
            }
            
            if (submitBtn) {
                if (atLeastOneChecked) {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                    submitBtn.style.cursor = 'pointer';
                } else {
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.6';
                    submitBtn.style.cursor = 'not-allowed';
                }
            }
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const approveModal = document.getElementById('approveModal');
            const rejectModal = document.getElementById('rejectModal');
            if (event.target == approveModal) {
                closeApproveModal();
            }
            if (event.target == rejectModal) {
                closeRejectModal();
            }
        }

        // Close modal on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeApproveModal();
                closeRejectModal();
            }
        });
    </script>
</body>
</html>


