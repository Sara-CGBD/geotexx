<?php
// Prevent browser caching to ensure fresh data
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

session_start();
require_once '../config/security_config.php';

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

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$is_tester = in_array($user_role, ['tester', 'qc_inspector']);

if (!$is_tester) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>Only Testers can access this dashboard.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$message = '';
$error = '';

// Check for success message from session (after redirect from submission)
if (isset($_SESSION['qc_success_message'])) {
    $message = $_SESSION['qc_success_message'];
    unset($_SESSION['qc_success_message']); // Clear it after displaying
}

if (isset($_GET['success'])) {
    $message = $_GET['success'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

// Get external test orders forwarded by AGM
// These are external products (EXT- prefix) that are pending for tester to perform tests
// Exclude tests that have already been submitted (have test results in test_data)
$external_tests = [];

// SIMPLE: Just fetch tests with pending_tester status
// If status is pending_tester, it means AGM forwarded it to tester - show it!
// Fetch both pending_tester (new forwarded tests) and rejected tests (rejected_by_checker, rejected_by_approver)
$stmt = $conn->query("
    SELECT qto.*, ts.test_name, ts.standard_code,
    LOWER(TRIM(u.role)) as inspector_role,
    TIMESTAMPDIFF(SECOND, qto.created_at, qto.updated_at) as seconds_since_creation,
    qto.created_at as created_at_raw,
    qto.updated_at as updated_at_raw
    FROM qc_test_orders qto
    LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
    LEFT JOIN new_user u ON qto.inspector_id = u.id
    WHERE qto.status IN ('pending_tester', 'rejected_by_checker', 'rejected_by_approver')
    ORDER BY 
        CASE qto.status 
            WHEN 'rejected_by_checker' THEN 1
            WHEN 'rejected_by_approver' THEN 2
            WHEN 'pending_tester' THEN 3
        END,
        qto.created_at DESC
    LIMIT 200
");
if ($stmt) {
    while ($row = $stmt->fetch_assoc()) {
        // Decode test_data
        $raw_test_data = $row['test_data'] ?? '{}';
        $test_data = json_decode($raw_test_data, true);
        if (!is_array($test_data)) {
            $test_data = [];
        }
        
        // SIMPLE: If status is pending_tester, it's an external test forwarded by AGM - show it!
        // Only filter out if test has been submitted (has test results)
        $has_test_results = false;
        
        // SIMPLIFIED CHECK: If test data contains any test result arrays, test has been submitted by tester
        // These arrays are ONLY created when tester fills and submits the test form
        
        // Check for test result arrays (most reliable indicator)
        // IMPORTANT: Only count as "submitted" if arrays contain actual data with non-zero values
        // Empty arrays or arrays with all zero values don't count as submitted
        $test_result_arrays = ['positions', 'strip_data', 'cbr_data', 'grab_data', 'weathering_data', 'seam_data'];
        foreach ($test_result_arrays as $array_key) {
            if (isset($test_data[$array_key]) && is_array($test_data[$array_key]) && count($test_data[$array_key]) > 0) {
                // Check if array has actual non-zero values (not just empty placeholders)
                $has_actual_data = false;
                foreach ($test_data[$array_key] as $entry) {
                    if (is_array($entry)) {
                        // Check common value fields in test data entries
                        // Include thickness field for thickness tests
                        $value_fields = ['value', 'thickness', 'result', 'force', 'displacement', 'strength', 'load', 'breaking_force', 'elongation', 'gsm', 'weight'];
                        foreach ($value_fields as $field) {
                            if (isset($entry[$field]) && is_numeric($entry[$field]) && floatval($entry[$field]) > 0) {
                                $has_actual_data = true;
                                break 2; // Break out of both loops
                            }
                        }
                        // For positions array, check if any entry has a non-zero value or thickness
                        if ((isset($entry['value']) && is_numeric($entry['value']) && floatval($entry['value']) > 0) ||
                            (isset($entry['thickness']) && is_numeric($entry['thickness']) && floatval($entry['thickness']) > 0)) {
                            $has_actual_data = true;
                            break;
                        }
                    } elseif (is_numeric($entry) && floatval($entry) > 0) {
                        // Entry is a direct numeric value
                        $has_actual_data = true;
                    break;
                }
            }
                
                if ($has_actual_data) {
                $has_test_results = true;
                    break; // Found test results, no need to check further
                }
            }
        }
        
        // Check for statistics (average, sd, cv, max, min) - BUT only if test data arrays also exist
        // Statistics alone don't mean test was submitted - they must be accompanied by actual test data
        // This prevents false positives where AGM might have saved some general info with statistics
        // HOWEVER: If statistics exist AND status is pending_checker/pending_approval (not pending_tester),
        // it means tester has submitted the test, so we should filter it out
        if (!$has_test_results) {
            $has_stats = (isset($test_data['average']) || isset($test_data['avg']) || 
                         isset($test_data['sd']) || isset($test_data['cv']) || 
                         isset($test_data['max']) || isset($test_data['min']));
            
            // If status is pending_checker or pending_approval (not pending_tester), and statistics exist,
            // it means tester has submitted the test, so filter it out
            $current_status = $row['status'] ?? '';
            if ($has_stats && in_array($current_status, ['pending_checker', 'pending_approval'])) {
                // Check if statistics have actual calculated values (not just 0 or empty)
                $has_real_stats = false;
                if (isset($test_data['average']) && is_numeric($test_data['average']) && floatval($test_data['average']) > 0) {
                    $has_real_stats = true;
                } elseif (isset($test_data['avg']) && is_numeric($test_data['avg']) && floatval($test_data['avg']) > 0) {
                    $has_real_stats = true;
                } elseif (isset($test_data['sd']) && is_numeric($test_data['sd']) && floatval($test_data['sd']) > 0) {
                    $has_real_stats = true;
                } elseif (isset($test_data['cv']) && is_numeric($test_data['cv']) && floatval($test_data['cv']) > 0) {
                    $has_real_stats = true;
                } elseif (isset($test_data['max']) && is_numeric($test_data['max']) && floatval($test_data['max']) > 0) {
                    $has_real_stats = true;
                } elseif (isset($test_data['min']) && is_numeric($test_data['min']) && floatval($test_data['min']) > 0) {
                    $has_real_stats = true;
                }
                
                // If status changed from pending_tester to pending_checker/pending_approval, test was submitted
                // Even if statistics are zero, the status change indicates submission
                if ($has_real_stats || in_array($current_status, ['pending_checker', 'pending_approval'])) {
                    $has_test_results = true;
                }
            } elseif ($has_stats) {
                // Statistics exist but status is still pending_tester - check if they're real values
                $has_real_stats = false;
                if (isset($test_data['average']) && is_numeric($test_data['average']) && floatval($test_data['average']) > 0) {
                    $has_real_stats = true;
                } elseif (isset($test_data['avg']) && is_numeric($test_data['avg']) && floatval($test_data['avg']) > 0) {
                    $has_real_stats = true;
                } elseif (isset($test_data['sd']) && is_numeric($test_data['sd']) && floatval($test_data['sd']) > 0) {
                    $has_real_stats = true;
                } elseif (isset($test_data['cv']) && is_numeric($test_data['cv']) && floatval($test_data['cv']) > 0) {
                    $has_real_stats = true;
                }
                
                // Also check if test data arrays exist with actual non-zero data (not just empty placeholders)
                $has_any_array_with_data = false;
                foreach ($test_result_arrays as $array_key) {
                    if (isset($test_data[$array_key]) && is_array($test_data[$array_key]) && count($test_data[$array_key]) > 0) {
                        // Check if array has actual non-zero values
                        foreach ($test_data[$array_key] as $entry) {
                            if (is_array($entry)) {
                                $value_fields = ['value', 'result', 'force', 'displacement', 'strength', 'load', 'breaking_force', 'elongation'];
                                foreach ($value_fields as $field) {
                                    if (isset($entry[$field]) && is_numeric($entry[$field]) && floatval($entry[$field]) > 0) {
                                        $has_any_array_with_data = true;
                                        break 2; // Break out of both loops
                                    }
                                }
                            } elseif (is_numeric($entry) && floatval($entry) > 0) {
                                $has_any_array_with_data = true;
                    break;
                }
            }
                        if ($has_any_array_with_data) break;
                    }
                }
                
                // Only mark as having results if we have real calculated statistics (> 0) OR test data arrays with actual non-zero data
                // Statistics with value 0 or arrays with only zero values don't count as submitted tests
                if ($has_real_stats || $has_any_array_with_data) {
                    $has_test_results = true;
                }
            }
        }
        
        // Check for summary object (for strip/grab tests) - only if it has actual non-zero calculated data
        if (!$has_test_results && isset($test_data['summary']) && is_array($test_data['summary']) && !empty($test_data['summary'])) {
            // Check if summary has actual calculated values (non-zero), not just empty structure or zero values
            $has_summary_data = false;
            $summary_fields_to_check = [
                'md', 'cd', 'force', 'displacement', 
                'strip_md_strength_avg', 'strip_cd_strength_avg', 'strip_md_elongation_avg', 'strip_cd_elongation_avg',
                'grab_md_force_avg', 'grab_cd_force_avg', 'grab_md_elongation_avg', 'grab_cd_elongation_avg',
                'md_avg', 'cd_avg', 'md_sd', 'cd_sd', 'md_cv', 'cd_cv'
            ];
            
            foreach ($summary_fields_to_check as $field) {
                if (isset($test_data['summary'][$field])) {
                    $value = $test_data['summary'][$field];
                    // Check if it's a numeric value > 0
                    if (is_numeric($value) && floatval($value) > 0) {
                        $has_summary_data = true;
                        break;
                    }
                }
            }
            
            // Also check nested summary objects (like summary.md.avg, summary.cd.avg)
            if (!$has_summary_data) {
                if (isset($test_data['summary']['md']) && is_array($test_data['summary']['md'])) {
                    foreach (['avg', 'average', 'mean', 'force', 'strength'] as $subfield) {
                        if (isset($test_data['summary']['md'][$subfield]) && is_numeric($test_data['summary']['md'][$subfield]) && floatval($test_data['summary']['md'][$subfield]) > 0) {
                            $has_summary_data = true;
                            break;
                        }
                    }
                }
                if (!$has_summary_data && isset($test_data['summary']['cd']) && is_array($test_data['summary']['cd'])) {
                    foreach (['avg', 'average', 'mean', 'force', 'strength'] as $subfield) {
                        if (isset($test_data['summary']['cd'][$subfield]) && is_numeric($test_data['summary']['cd'][$subfield]) && floatval($test_data['summary']['cd'][$subfield]) > 0) {
                            $has_summary_data = true;
                            break;
                        }
                    }
                }
            }
            
            if ($has_summary_data) {
                $has_test_results = true;
            }
        }
        
        // Detect external tests by multiple methods:
        // 1. Status is pending_tester (AGM forwarded external test)
        // 2. Status is rejected_by_checker or rejected_by_approver AND has external indicators
        // 3. Sample reference starts with EXT- or TOKEN-
        // 4. is_external_product flag in test_data
        $is_external = false;
        $sample_ref = trim($row['sample_reference_id'] ?? '');
        
        // Method 1: Check status
        if ($row['status'] === 'pending_tester') {
            $is_external = true;
        }
        
        // Method 2: Check sample_reference_id prefix (for rejected tests)
        if (!$is_external && !empty($sample_ref) && (stripos($sample_ref, 'EXT-') === 0 || stripos($sample_ref, 'TOKEN-') === 0)) {
            $is_external = true;
        }
        
        // Method 3: Check is_external_product flag in test_data
        if (!$is_external && isset($test_data['is_external_product']) && ($test_data['is_external_product'] == '1' || $test_data['is_external_product'] === true)) {
            $is_external = true;
        }
        
        // Method 4: If status is rejected and was originally external, it's still external
        if (!$is_external && in_array($row['status'], ['rejected_by_checker', 'rejected_by_approver'])) {
            // Check if it has external indicators
            if (!empty($sample_ref) && (stripos($sample_ref, 'EXT-') === 0 || stripos($sample_ref, 'TOKEN-') === 0)) {
                $is_external = true;
            } elseif (isset($test_data['is_external_product']) && ($test_data['is_external_product'] == '1' || $test_data['is_external_product'] === true)) {
                $is_external = true;
            } elseif (isset($test_data['external_reference']) && !empty($test_data['external_reference'])) {
                $is_external = true;
            }
        }
        
        // SIMPLE LOGIC: Show external tests if:
        // 1. Status is pending_tester (new forwarded tests) - unless already submitted
        // 2. Status is rejected_by_checker or rejected_by_approver (rejected tests need resubmission)
        $should_show = false;
        if ($is_external) {
            if (in_array($row['status'], ['rejected_by_checker', 'rejected_by_approver'])) {
                // Rejected tests: always show (tester needs to resubmit)
                $should_show = true;
            } elseif ($row['status'] === 'pending_tester' && !$has_test_results) {
                // Pending tests: show only if not yet submitted
                $should_show = true;
            }
        }
        
        if ($should_show) {
            // Get external reference and other info from test_data
            $external_ref = $test_data['external_reference'] ?? $row['sample_reference_id'] ?? $row['report_number'] ?? 'EXT-UNKNOWN';
            
            $row['external_reference'] = $external_ref;
            $row['batch_information'] = $test_data['batch_information'] ?? $test_data['batch_info'] ?? 'N/A';
            $row['sample_details'] = $test_data['sample_details'] ?? 'N/A';
            $row['sample_received_from'] = $test_data['sample_received_from'] ?? 'N/A';
            $row['customer_reference'] = $test_data['customer_reference'] ?? 'N/A';
            $row['sample_collected_from'] = $test_data['sample_collected_from'] ?? 'N/A';
            
            // Add to list - simple!
            $external_tests[] = $row;
        }
    }
}

// Group by external reference
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
    <title>Forwarded External Test Dashboard</title>
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
        }
        .header p {
            opacity: 0.95;
            font-size: 15px;
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
            display: inline-block;
            margin-bottom: 20px;
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            transition: background 0.3s;
        }
        .back-btn:hover {
            background: #5a6268;
        }
        .test-group {
            background: white;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
            border:1px solid var(--border);
        }
        .group-header {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 18px 25px;
            font-weight: 600;
            font-size: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .group-header i {
            margin-right: 10px;
        }
        .badge {
            background: rgba(255,255,255,0.25);
            padding: 5px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .col-batch {
            background:#eef2ff;
            color:#312e81;
            font-weight:600;
        }
        .col-sample {
            background:#fef9c3;
            color:#7c2d12;
        }
        .col-received {
            background:#dcfce7;
            color:#065f46;
        }
        .col-customer {
            background:#ffe4e6;
            color:#9f1239;
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
        .test-table th:nth-child(4) { background:#e0e7ff; color:#312e81; }
        .test-table th:nth-child(5) { background:#fef3c7; color:#7c2d12; }
        .test-table th:nth-child(6) { background:#dcfce7; color:#065f46; }
        .test-table th:nth-child(7) { background:#ffe4e6; color:#9f1239; }
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
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-pending-checker {
            background: #fff3cd;
            color: #856404;
        }
        .status-pending_checker {
            background: #fff3cd;
            color: #856404;
        }
        .status-pending-approval {
            background: #cfe2ff;
            color: #084298;
        }
        .status-pending_approval {
            background: #cfe2ff;
            color: #084298;
        }
        .btn-view {
            display: inline-block;
            padding: 8px 16px;
            background: #17a2b8;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 13px;
            font-weight: 500;
            transition: background 0.3s;
            box-shadow: 0 8px 20px rgba(23,162,184,0.25);
        }
        .btn-view:hover {
            background: #138496;
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
            .btn-view {
                width: 100%;
                text-align: center;
                margin-top: 8px;
            }
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        .empty-state h3 {
            font-size: 24px;
            margin-bottom: 10px;
        }
        .empty-state p {
            font-size: 16px;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="../index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="header">
            <h1><i class="fas fa-inbox"></i> Forwarded External Test Dashboard</h1>
            <p>External test orders forwarded by AGM for testing</p>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-success" id="success-message">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <?php if (empty($grouped_tests)): ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <h3>No External Tests Forwarded</h3>
            <p>There are currently no external test orders forwarded by AGM.</p>
        </div>
        <?php else: ?>
            <?php foreach ($grouped_tests as $ref => $tests): ?>
            <?php $firstTest = $tests[0]; ?>
            <div class="test-group">
                <div class="group-header">
                    <div>
                        <i class="fas fa-tag"></i> External Reference: <?php echo htmlspecialchars($ref); ?>
                    </div>
                    <span class="badge"><i class="fas fa-flask"></i><?php echo count($tests); ?> test(s)</span>
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
                            <th>Forwarded At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tests as $test): 
                            $statusClass = 'status-' . str_replace([' ', '-'], '_', strtolower($test['status']));
                            $statusLabel = 'Pending';
                            if ($test['status'] === 'pending_approval') {
                                $statusLabel = 'Pending Approval';
                            } elseif ($test['status'] === 'approved') {
                                $statusLabel = 'Approved';
                            } elseif ($test['status'] === 'rejected_by_checker') {
                                $statusLabel = 'Rejected by Checker';
                            } elseif ($test['status'] === 'rejected_by_approver') {
                                $statusLabel = 'Rejected by Admin';
                            }
                        ?>
                        <tr>
                            <td data-label="Report No"><strong><?php echo htmlspecialchars($test['report_number']); ?></strong></td>
                            <td data-label="Test Name"><?php echo htmlspecialchars($test['test_name'] ?? 'N/A'); ?></td>
                            <td data-label="Method"><?php echo htmlspecialchars($test['chosen_method'] ?? 'N/A'); ?></td>
                            <td data-label="Batch Info" class="col-batch"><?php echo htmlspecialchars($test['batch_information']); ?></td>
                            <td data-label="Sample Details" class="col-sample"><?php echo htmlspecialchars($test['sample_details']); ?></td>
                            <td data-label="Received From" class="col-received"><?php echo htmlspecialchars($test['sample_received_from']); ?></td>
                            <td data-label="Customer Ref" class="col-customer"><?php echo htmlspecialchars($test['customer_reference']); ?></td>
                            <td data-label="Status">
                                <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                    <?php echo htmlspecialchars($statusLabel); ?>
                                </span>
                                <?php if (in_array($test['status'], ['rejected_by_checker', 'rejected_by_approver'])): 
                                    $rejection_remarks = ($test['status'] === 'rejected_by_checker') ? ($test['checker_remarks'] ?? '') : ($test['admin_remarks'] ?? '');
                                    if (!empty($rejection_remarks)):
                                ?>
                                    <div style="margin-top: 5px; font-size: 11px; color: #dc2626; font-style: italic;">
                                        <i class="fas fa-comment-alt"></i> <?php echo htmlspecialchars(substr($rejection_remarks, 0, 50)); ?><?php echo strlen($rejection_remarks) > 50 ? '...' : ''; ?>
                                    </div>
                                <?php endif; endif; ?>
                            </td>
                            <td data-label="Forwarded At"><?php echo date('M d, Y - g:i A', strtotime($test['created_at'])); ?></td>
                            <td data-label="Action">
                                <?php 
                                // Check if test has been submitted (has test data)
                                $test_data_check = json_decode($test['test_data'] ?? '{}', true);
                                $has_been_submitted = false;
                                
                                // Check for test data arrays
                                if (isset($test_data_check['positions']) && is_array($test_data_check['positions']) && count($test_data_check['positions']) > 0) {
                                    foreach ($test_data_check['positions'] as $pos) {
                                        if ((isset($pos['value']) && floatval($pos['value']) > 0) || 
                                            (isset($pos['thickness']) && floatval($pos['thickness']) > 0)) {
                                            $has_been_submitted = true;
                                            break;
                                        }
                                    }
                                }
                                
                                // Check for other test data arrays
                                $test_data_arrays = ['strip_data', 'cbr_data', 'grab_data', 'weathering_data', 'seam_data'];
                                foreach ($test_data_arrays as $array_key) {
                                    if (isset($test_data_check[$array_key]) && is_array($test_data_check[$array_key]) && count($test_data_check[$array_key]) > 0) {
                                        $has_been_submitted = true;
                                        break;
                                    }
                                }
                                
                                // Check for statistics (indicates test was completed)
                                if (!$has_been_submitted && (isset($test_data_check['average']) || isset($test_data_check['avg']))) {
                                    $avg = floatval($test_data_check['average'] ?? $test_data_check['avg'] ?? 0);
                                    if ($avg > 0) {
                                        $has_been_submitted = true;
                                    }
                                }
                                
                                // Show "Edit & Resubmit" only if:
                                // 1. Status is rejected (rejected_by_checker or rejected_by_approver)
                                // 2. AND test has been submitted (has test data)
                                $show_edit_resubmit = in_array($test['status'], ['rejected_by_checker', 'rejected_by_approver']) && $has_been_submitted;
                                ?>
                                <?php if ($show_edit_resubmit): ?>
                                    <a href="../forms/qc_test_order.php?edit=<?php echo $test['id']; ?>" target="_blank" class="btn-view" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                                        <i class="fas fa-edit"></i> Edit & Resubmit
                                    </a>
                                <?php else: ?>
                                    <a href="../forms/qc_test_order.php?edit=<?php echo $test['id']; ?>" target="_blank" class="btn-view">
                                        <i class="fas fa-flask"></i> Perform Test
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <script>
        // Auto-refresh the dashboard every 30 seconds
        let autoRefreshInterval = setInterval(function() {
            // Only refresh if no success message is showing (to avoid interrupting user)
            const successMsg = document.getElementById('success-message');
            if (!successMsg || successMsg.style.display === 'none') {
                location.reload();
            }
        }, 30000); // 30 seconds
        
        // Clear success message after 5 seconds
        const successMsg = document.getElementById('success-message');
        if (successMsg) {
            setTimeout(function() {
                successMsg.style.transition = 'opacity 0.5s';
                successMsg.style.opacity = '0';
                setTimeout(function() {
                    successMsg.style.display = 'none';
                }, 500);
            }, 5000);
        }
        
        // Stop auto-refresh when user is interacting with the page
        let userActivityTimeout;
        document.addEventListener('mousemove', function() {
            clearTimeout(userActivityTimeout);
            clearInterval(autoRefreshInterval);
            // Restart auto-refresh after 60 seconds of inactivity
            userActivityTimeout = setTimeout(function() {
                autoRefreshInterval = setInterval(function() {
                    const successMsg = document.getElementById('success-message');
                    if (!successMsg || successMsg.style.display === 'none') {
                        location.reload();
                    }
                }, 30000);
            }, 60000);
        });
    </script>
</body>
</html>


