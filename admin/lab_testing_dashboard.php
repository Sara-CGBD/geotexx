<?php
session_start();
// Optional dev auto-reload
$devReload = __DIR__ . '/../dev/auto_reload.php';
if (file_exists($devReload)) {
    include_once $devReload;
}

// Performance monitoring
require_once '../config/PerformanceMonitor.php';
PerformanceMonitor::start();

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

// Only checker can access
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if ($user_role !== 'checker') {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>Only Checkers can access Lab Testing Reports Dashboard.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$message = '';
$error = '';

// Handle approval/rejection from view page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $report_type = $_POST['report_type'];
    $report_number = $_POST['report_number'];
    $comments = $_POST['comments'] ?? '';
    $related_report_numbers = isset($_POST['related_report_numbers']) ? trim($_POST['related_report_numbers']) : '';
    
    // Handle rejection reasons checkboxes
    if ($action === 'reject' && isset($_POST['qc_rejection_reasons']) && is_array($_POST['qc_rejection_reasons'])) {
        $rejection_reasons = array_map('trim', $_POST['qc_rejection_reasons']);
        $reasons_text = implode(', ', $rejection_reasons);
        $comments = "Rejection Reasons: " . $reasons_text . ($comments ? "\n\nAdditional Comments: " . $comments : '');
    }
    
    // Parse related report numbers if provided
    $all_report_numbers = [$report_number];
    if (!empty($related_report_numbers)) {
        $related = array_filter(array_map('trim', explode(',', $related_report_numbers)));
        $all_report_numbers = array_merge($all_report_numbers, $related);
    }
    
    // For QC test orders, automatically find all reports with the same bulk reference range
    if ($report_type === 'qc_test_order') {
        // Get the current report's test_data to check for bulk reference range
        $getReportStmt = $conn->prepare("SELECT test_data, test_standard_id, chosen_method FROM qc_test_orders WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
        $getReportStmt->bind_param("s", $report_number);
        $getReportStmt->execute();
        $reportResult = $getReportStmt->get_result();
        $currentReport = $reportResult->fetch_assoc();
        $getReportStmt->close();
        
        if ($currentReport) {
            $test_data = json_decode($currentReport['test_data'], true) ?? [];
            
            // Check if this is a bulk reference submission
            if (isset($test_data['is_bulk_reference']) && $test_data['is_bulk_reference'] && 
                isset($test_data['bulk_from_reference']) && isset($test_data['bulk_to_reference'])) {
                
                $bulk_from = $test_data['bulk_from_reference'];
                $bulk_to = $test_data['bulk_to_reference'];
                $test_standard_id = $currentReport['test_standard_id'];
                $chosen_method = $currentReport['chosen_method'];
                
                // Find all reports with the same bulk reference range, test, and method
                // For approval: find all with same range that are pending_checker
                // For rejection: find all with same range and same status (to reject all pending ones)
                $findRelatedStmt = $conn->prepare("
                    SELECT qto.report_number, qto.test_data
                    FROM qc_test_orders qto
                    WHERE qto.test_standard_id = ?
                    AND qto.chosen_method = ?
                    AND qto.status = 'pending_checker'
                    AND qto.report_number COLLATE utf8mb4_unicode_ci != ?
                ");
                $findRelatedStmt->bind_param("iss", $test_standard_id, $chosen_method, $report_number);
                
                $findRelatedStmt->execute();
                $relatedResult = $findRelatedStmt->get_result();
                
                // Verify each report has the same bulk reference range
                while ($row = $relatedResult->fetch_assoc()) {
                    $related_test_data = json_decode($row['test_data'] ?? '{}', true);
                    if (isset($related_test_data['is_bulk_reference']) && $related_test_data['is_bulk_reference'] &&
                        isset($related_test_data['bulk_from_reference']) && isset($related_test_data['bulk_to_reference']) &&
                        $related_test_data['bulk_from_reference'] === $bulk_from &&
                        $related_test_data['bulk_to_reference'] === $bulk_to) {
                        if (!in_array($row['report_number'], $all_report_numbers)) {
                            $all_report_numbers[] = $row['report_number'];
                        }
                    }
                }
                $findRelatedStmt->close();
            }
        }
    }
    
    $table_map = [
        'qc_test_order' => 'qc_test_orders',
    ];
    
    $table = $table_map[$report_type] ?? null;
    
    if ($table) {
        $checker_name = $_SESSION['full_name'] ?? $_SESSION['username'];
        $success_count = 0;
        $failed_reports = [];
        
        // Process all reports in the bulk group
        foreach ($all_report_numbers as $current_report_number) {
            $stmt = null;
            if ($action === 'approve') {
                $stmt = $conn->prepare("UPDATE $table SET status = 'pending_approval', checked_by = ?, checked_at = NOW(), checker_remarks = NULL, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                if ($stmt) {
                    $stmt->bind_param("ss", $checker_name, $current_report_number);
                }
            } elseif ($action === 'reject') {
                $stmt = $conn->prepare("UPDATE $table SET status = 'rejected_by_checker', checked_by = ?, checked_at = NOW(), checker_remarks = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                if ($stmt) {
                    $stmt->bind_param("sss", $checker_name, $comments, $current_report_number);
                }
            }
            
            if ($stmt && $stmt->execute() && $stmt->affected_rows > 0) {
                $success_count++;
            } else {
                $error_msg = $stmt ? $stmt->error : $conn->error;
                error_log("Failed to update report {$current_report_number}: {$error_msg}");
                $failed_reports[] = $current_report_number;
            }
            if ($stmt) {
                $stmt->close();
            }
        }
        
        if ($success_count > 0) {
            $message = count($all_report_numbers) > 1 
                ? ucfirst($action) . "d {$success_count} report(s): " . implode(', ', array_slice($all_report_numbers, 0, 3)) . (count($all_report_numbers) > 3 ? '...' : '')
                : ucfirst($action) . "d report: " . $report_number;
            
            if (!empty($failed_reports)) {
                $message .= " (Failed: " . implode(', ', $failed_reports) . ")";
            }
            
            header("Location: " . $_SERVER['PHP_SELF'] . "?success=" . urlencode($message));
            exit();
        } else {
            $error = "Failed to $action report(s): " . implode(', ', $failed_reports);
        }
    }
}

// Check for success message from redirect
if (isset($_GET['success'])) {
    $message = $_GET['success'];
}

// Get all pending reports from all lab test tables
$pendingReports = [];

// First 5 tests that require checker approval
$first_five_test_names = [
    'Thickness (Under 2kPa Pressure)',
    'Mass Per Unit Area (GSM)',
    'Strip Tensile Test',
    'CBR Puncture Resistance',
    'Grab Tensile Test'
];

// 1. QC Test Orders (first 5 tests only, pending checker approval, exclude external references and non-tester submissions)
// EXCLUDE external tests - they should only show in External Checker Dashboard
$qctoCols = [];
$colRes = $conn->query("SHOW COLUMNS FROM qc_test_orders");
if ($colRes) {
    while ($r = $colRes->fetch_assoc()) {
        $qctoCols[] = strtolower($r['Field']);
    }
}
$hasReportNumber = in_array('report_number', $qctoCols, true);
$reportSelect = $hasReportNumber ? "qto.report_number" : "qto.id as report_number";
$hasInspectorName = in_array('inspector_name', $qctoCols, true);
$inspectorNameSelect = $hasInspectorName ? "qto.inspector_name as tested_by" : "u.username as tested_by";
$hasStatus = in_array('status', $qctoCols, true);
$statusSelect = $hasStatus ? "qto.status" : "'' as status";
// Include QC test orders that are NOT approved (exclude approved status)
// Show pending_checker, pending_approval, checked, rejected, etc. - but NOT approved
$statusWhere = "WHERE qto.status != 'approved' AND qto.status != 'pending_approval'";
$hasUpdatedAt = in_array('updated_at', $qctoCols, true);
$updatedSelect = $hasUpdatedAt ? "qto.updated_at" : "qto.created_at as updated_at";
$orderUpdated = $hasUpdatedAt ? "qto.updated_at" : "qto.created_at";

$result = $conn->query("SELECT qto.id, qto.inspector_id, 'qc_test_order' as type, 
    CONCAT(ts.test_name, ' | ', qto.chosen_method) as test_name,
    ts.test_name as test_name_only,
    $reportSelect, 
    qto.sample_reference_id,
    qto.sample_reference_id as reference_number,
    DATE(qto.created_at) as test_date, 
    $inspectorNameSelect, 
    $statusSelect, 
    $updatedSelect,
    qto.test_data,
    LOWER(TRIM(u.role)) as inspector_role
    FROM qc_test_orders qto
    LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
    LEFT JOIN new_user u ON qto.inspector_id = u.id
    $statusWhere
    ORDER BY qto.sample_reference_id ASC, $orderUpdated DESC");

$qcTestOrderCount = 0;
if ($result) {
    $qcTestOrderCount = $result->num_rows;
    error_log("Lab Testing Dashboard: Found $qcTestOrderCount QC test orders from query");
    while ($row = $result->fetch_assoc()) {
        // EXCLUDE external tests - they should only show in External Checker Dashboard
        // Check multiple ways to identify external tests:
        $sample_ref = trim($row['sample_reference_id'] ?? '');
        $is_external = false;
        
        // Method 1: Check sample_reference_id prefix
        if (!empty($sample_ref) && (stripos($sample_ref, 'EXT-') === 0 || stripos($sample_ref, 'TOKEN-') === 0)) {
            $is_external = true;
        }
        
        // Method 2: Check is_external_product flag in test_data
        if (!$is_external) {
            $test_data = json_decode($row['test_data'] ?? '{}', true);
            if (isset($test_data['is_external_product']) && ($test_data['is_external_product'] == '1' || $test_data['is_external_product'] === true)) {
                $is_external = true;
            }
        }
        
        // Skip external tests - they belong in External Checker Dashboard only
        if ($is_external) {
            // Debug: Log why test was excluded
            error_log("Lab Testing Dashboard: Excluding external test - Report: " . ($row['report_number'] ?? 'N/A') . ", Sample Ref: " . $sample_ref);
            continue;
        }
        
        // Additional check: Make absolutely sure it's not external by checking report_number pattern
        $report_num = $row['report_number'] ?? '';
        if (stripos($report_num, 'EXT-') !== false || stripos($report_num, 'TOKEN-') !== false) {
            error_log("Lab Testing Dashboard: Excluding test with external pattern in report_number - " . $report_num);
            continue;
        }
        
        // Final check: Make absolutely sure it's not external before adding
        if ($is_external) {
            error_log("Lab Testing Dashboard: Final exclusion - Report: " . ($row['report_number'] ?? 'N/A') . ", Sample: " . $sample_ref);
            continue;
        }
        
        $role = $row['inspector_role'] ?? '';
        // Include ALL QC test orders - remove restrictions on test names and roles
        // All submitted tests from QC test orders should be visible
        // Debug: Log what we're checking
        error_log("Lab Testing Dashboard QC Test: Report=" . ($row['report_number'] ?? 'N/A') . ", Role=" . $role . ", Test=" . ($row['test_name_only'] ?? 'N/A'));
        
        // Include all roles for QC test orders - don't restrict by role
        // Make sure test_data is preserved in the array
        $pendingReports[] = $row;
        error_log("Lab Testing Dashboard: Added QC test order to pendingReports - Report: " . ($row['report_number'] ?? 'N/A') . ", Type: " . ($row['type'] ?? 'N/A'));
    }
} else {
    error_log("Lab Testing Dashboard: QC test orders query failed: " . $conn->error);
}
error_log("Lab Testing Dashboard: Total QC test orders added to pendingReports: " . count(array_filter($pendingReports, function($r) { return $r['type'] === 'qc_test_order'; })));

// 2. Fabric Pre-Production Tests
// Check which reference columns exist
$refColCheck = $conn->query("SHOW COLUMNS FROM fabric_pre_production_tests LIKE 'reference_number'");
$hasRefCol = ($refColCheck && $refColCheck->num_rows > 0);
$sampleRefColCheck = $conn->query("SHOW COLUMNS FROM fabric_pre_production_tests LIKE 'sample_reference_id'");
$hasSampleRefCol = ($sampleRefColCheck && $sampleRefColCheck->num_rows > 0);

if ($hasRefCol && $hasSampleRefCol) {
    $refSelect = "COALESCE(reference_number, sample_reference_id, '') as reference_number";
} elseif ($hasRefCol) {
    $refSelect = "COALESCE(reference_number, '') as reference_number";
} elseif ($hasSampleRefCol) {
    $refSelect = "COALESCE(sample_reference_id, '') as reference_number";
} else {
    $refSelect = "'' as reference_number";
}

$result = $conn->query("SELECT id, 'fabric_pre' as type, 'Fabric Pre-Production Test' as test_name, report_number, 
    sample_received_date as test_date, test_performed_by as tested_by, status, updated_at,
    $refSelect
    FROM fabric_pre_production_tests 
    WHERE status = 'pending_checker' AND status != 'approved'
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pendingReports[] = $row;
    }
}

// 3. Water Permeability Tests
$refColCheck = $conn->query("SHOW COLUMNS FROM water_permeability_tests LIKE 'reference_number'");
$hasRefCol = ($refColCheck && $refColCheck->num_rows > 0);
$bundleColCheck = $conn->query("SHOW COLUMNS FROM water_permeability_tests LIKE 'bundle_reference'");
$hasBundleCol = ($bundleColCheck && $bundleColCheck->num_rows > 0);
$refSelect = $hasRefCol ? "COALESCE(wpt.reference_number, '') as reference_number" : "'' as reference_number";
$bundleSelect = $hasBundleCol ? ", COALESCE(wpt.bundle_reference, '') as bundle_reference" : ", '' as bundle_reference";

// Join with roll_entry to get full reference when stored reference is incomplete (like "2")
$result = $conn->query("SELECT wpt.id, 'water_perm' as type, 'Water Permeability Test' as test_name, wpt.report_number, 
    wpt.test_date, wpt.test_performed_by as tested_by, wpt.status, wpt.updated_at,
    $refSelect $bundleSelect,
    CASE 
        WHEN LENGTH(TRIM(wpt.reference_number)) <= 3 THEN 
            COALESCE(re.reference_number, wpt.reference_number)
        ELSE wpt.reference_number
    END as full_reference
    FROM water_permeability_tests wpt
    LEFT JOIN roll_entry re ON (
        (LENGTH(TRIM(wpt.reference_number)) <= 3 AND re.reference_number LIKE CONCAT('%', wpt.reference_number, '%'))
        OR re.reference_number = wpt.reference_number
        OR re.reference_number LIKE CONCAT(wpt.reference_number, '-%')
        OR wpt.reference_number LIKE CONCAT(re.reference_number, '-%')
    )
    WHERE wpt.status = 'pending' AND wpt.status != 'approved'
    GROUP BY wpt.id
    ORDER BY wpt.updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pendingReports[] = $row;
    }
}

// 4. Characteristics Tests
$refColCheck = $conn->query("SHOW COLUMNS FROM characteristics_tests LIKE 'reference_number'");
$hasRefCol = ($refColCheck && $refColCheck->num_rows > 0);
$refSelect = $hasRefCol ? "COALESCE(reference_number, '') as reference_number" : "'' as reference_number";
$bundleColCheck = $conn->query("SHOW COLUMNS FROM characteristics_tests LIKE 'bundle_reference'");
$hasBundleCol = ($bundleColCheck && $bundleColCheck->num_rows > 0);
$bundleSelect = $hasBundleCol ? ", COALESCE(bundle_reference, '') as bundle_reference" : ", '' as bundle_reference";

$result = $conn->query("SELECT id, 'characteristics' as type, 'Characteristics Test (ISO 12956)' as test_name, report_number, 
    DATE(sample_tested) as test_date, test_performed_by as tested_by, status, updated_at,
    $refSelect $bundleSelect
    FROM characteristics_tests 
    WHERE status = 'pending' AND status != 'approved'
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pendingReports[] = $row;
    }
}

// Sort by updated_at descending
usort($pendingReports, function($a, $b) {
    return strtotime($b['updated_at']) - strtotime($a['updated_at']);
});

$totalPending = count($pendingReports);

// Debug: Log summary of reports by type
$reportTypes = [];
foreach ($pendingReports as $report) {
    $type = $report['type'] ?? 'unknown';
    $reportTypes[$type] = ($reportTypes[$type] ?? 0) + 1;
}
error_log("Lab Testing Dashboard: Total reports: $totalPending. Breakdown by type: " . json_encode($reportTypes));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lab Testing Reports Dashboard - Checker</title>
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
        margin-left: 0;
        background: white;
        border-radius: 16px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        overflow: hidden;
    }
    .header {
        background: linear-gradient(135deg,rgb(5, 9, 32) 0%,rgb(188, 166, 211) 100%);
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
        box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        text-align: center;
        border-left: 4px solid #667eea;
    }
    .stat-number {
        font-size: 36px;
        font-weight: 700;
        color: #667eea;
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
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
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
        padding: 6px 12px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        display: inline-block;
        line-height: 1.4;
    }
    .badge-qc { background: #e1f5fe; color: #01579b; }
    .badge-fabric { background: #e3f2fd; color: #1976d2; }
    .badge-water { background: #e0f2f1; color: #00796b; }
    .badge-char { background: #fff3e0; color: #e65100; }
    .status-badge {
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
    }
    .status-pending { background: #fff3cd; color: #856404; }
    .status-pending_checker { background: #fff3cd; color: #856404; }
    .action-btn {
        padding: 6px 12px;
        border-radius: 6px;
        text-decoration: none;
        font-size: 12px;
        font-weight: 600;
        transition: all 0.3s;
        display: inline-block;
    }
    .btn-check {
        background: #28a745;
        color: white;
    }
    .btn-check:hover {
        background: #218838;
        transform: translateY(-2px);
        box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
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
    .alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }
    .alert-success {
        background: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
    }
    .alert-error {
        background: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
    }
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1><i class="fas fa-clipboard-check"></i> Lab Testing Reports Dashboard</h1>
        <p>Checker: <?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></p>
    </div>

    <div class="stats">
        <div class="stat-card">
            <div class="stat-number"><?php echo $totalPending; ?></div>
            <div class="stat-label">Pending Reports</div>
        </div>
    </div>

    <div class="content">
        <a href="../index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($pendingReports)): 
            // Helper function to extract base reference (remove bundle suffix like -1, -2, etc.)
            $extractBaseRef = function($ref) {
                if (empty($ref)) return '';
                return preg_replace('/-\d+$/', '', trim($ref));
            };
            
            // Group reports by reference number
            // Filter out external tests before grouping
            $filteredReports = [];
            foreach ($pendingReports as $report) {
                $reference = 'Other Reports';
                
                // Get reference number based on report type
                if ($report['type'] === 'qc_test_order' && isset($report['sample_reference_id'])) {
                    // Use base reference for better matching
                    $sample_ref = trim($report['sample_reference_id'] ?? '');
                    $reference = !empty($sample_ref) ? $extractBaseRef($sample_ref) : 'Other Reports';
                } elseif (isset($report['reference_number']) && !empty($report['reference_number'])) {
                    // For water permeability tests, use full_reference if available (from roll_entry join)
                    if ($report['type'] === 'water_perm' && isset($report['full_reference']) && !empty($report['full_reference'])) {
                        // Use full_reference if it's longer than the stored reference_number
                        if (strlen(trim($report['full_reference'])) > strlen(trim($report['reference_number']))) {
                            $reference = $report['full_reference'];
                        } else {
                            $reference = $report['reference_number'];
                        }
                    } 
                    // Fallback: prefer bundle_reference if available and reference_number is too short
                    elseif ($report['type'] === 'water_perm' && isset($report['bundle_reference']) && !empty($report['bundle_reference'])) {
                        if (strlen(trim($report['reference_number'])) <= 3) {
                            $reference = $report['bundle_reference'];
                        } else {
                            $reference = $report['reference_number'];
                        }
                    } else {
                        $reference = $report['reference_number'];
                    }
                    // Normalize reference (extract base) for consistent matching with QC test orders
                    $reference = $extractBaseRef($reference);
                }
                
                // Debug: Log reference matching for QC test orders
                if ($report['type'] === 'qc_test_order') {
                    error_log("Lab Testing Dashboard: QC Test Order grouped - Original: " . ($report['sample_reference_id'] ?? 'N/A') . ", Base: " . $reference);
                }
                
                // EXCLUDE external tests - they should only show in External Checker Dashboard
                $ref_trimmed = trim($reference);
                if (!empty($ref_trimmed) && $ref_trimmed !== 'Other Reports' && 
                    (stripos($ref_trimmed, 'EXT-') === 0 || stripos($ref_trimmed, 'TOKEN-') === 0)) {
                    // Skip external references
                    continue;
                }
                
                // Also check if test_data has external flag (for QC test orders)
                if ($report['type'] === 'qc_test_order' && isset($report['test_data'])) {
                    $test_data = json_decode($report['test_data'], true);
                    if (isset($test_data['is_external_product']) && 
                        ($test_data['is_external_product'] == '1' || $test_data['is_external_product'] === true)) {
                        // Skip external tests
                        continue;
                    }
                }
                
                $filteredReports[] = $report;
            }
            
            // Helper function to extract base reference (remove bundle suffix like -1, -2, etc.)
            $extractBaseReference = function($ref) {
                if (empty($ref)) return '';
                // Remove bundle suffix pattern: -N where N is a number
                return preg_replace('/-\d+$/', '', trim($ref));
            };
            
            // Helper function to normalize reference for matching
            $normalizeReference = function($ref) {
                if (empty($ref)) return '';
                $base = preg_replace('/-\d+$/', '', trim($ref));
                // Also check if it matches bundle pattern (e.g., "2.0L126JAN16-R06-GT0.9.H0.1-1")
                return $base;
            };
            
            $groupedReports = [];
            foreach ($filteredReports as $report) {
                $reference = 'Other Reports';
                
                // PRIORITY: Use bundle_reference (range) for grouping if it exists and contains a range (|)
                // This ensures all tests from the same range are grouped together
                if (isset($report['bundle_reference']) && !empty($report['bundle_reference'])) {
                    $bundle_ref = trim($report['bundle_reference']);
                    if (strpos($bundle_ref, '|') !== false) {
                        // It's a range - use bundle_reference as the grouping key
                        $reference = $bundle_ref;
                    }
                }
                
                // If no bundle_reference range, fall back to other methods
                if ($reference === 'Other Reports') {
                    // Get reference number based on report type
                    if ($report['type'] === 'qc_test_order' && isset($report['sample_reference_id'])) {
                        $sample_ref = trim($report['sample_reference_id'] ?? '');
                        if (!empty($sample_ref)) {
                            // For QC test orders, check test_data for bulk reference range
                            if (isset($report['test_data'])) {
                                $test_data = json_decode($report['test_data'] ?? '{}', true);
                                if (isset($test_data['bulk_from_reference']) && isset($test_data['bulk_to_reference']) &&
                                    !empty($test_data['bulk_from_reference']) && !empty($test_data['bulk_to_reference'])) {
                                    $reference = trim($test_data['bulk_from_reference']) . '|' . trim($test_data['bulk_to_reference']);
                                } else {
                                    // Extract base reference for better matching
                                    $reference = $extractBaseReference($sample_ref);
                                    $report['original_sample_reference'] = $sample_ref;
                                }
                            } else {
                                $reference = $extractBaseReference($sample_ref);
                                $report['original_sample_reference'] = $sample_ref;
                            }
                        } else {
                            $reference = 'Other Reports';
                        }
                    } elseif (isset($report['reference_number']) && !empty($report['reference_number'])) {
                        // For water permeability tests, use full_reference if available (from roll_entry join)
                        if ($report['type'] === 'water_perm' && isset($report['full_reference']) && !empty($report['full_reference'])) {
                            // Use full_reference if it's longer than the stored reference_number
                            if (strlen(trim($report['full_reference'])) > strlen(trim($report['reference_number']))) {
                                $reference = $report['full_reference'];
                            } else {
                                $reference = $report['reference_number'];
                            }
                        } else {
                            $reference = $report['reference_number'];
                        }
                    }
                }
                
                if (!isset($groupedReports[$reference])) {
                    $groupedReports[$reference] = [];
                }
                $groupedReports[$reference][] = $report;
            }
        ?>
        
        <?php foreach ($groupedReports as $reference => $reports): 
            // Check if this group has QC test orders with reference ranges
            $hasReferenceRange = false;
            $referenceRangeDisplay = '';
            $referenceRanges = [];
            
            foreach ($reports as $report) {
                // Check for QC test orders with bulk reference ranges in test_data
                if ($report['type'] === 'qc_test_order' && isset($report['test_data'])) {
                    $test_data = json_decode($report['test_data'] ?? '{}', true);
                    if (isset($test_data['bulk_from_reference']) && isset($test_data['bulk_to_reference']) &&
                        !empty($test_data['bulk_from_reference']) && !empty($test_data['bulk_to_reference'])) {
                        $from_ref = trim($test_data['bulk_from_reference']);
                        $to_ref = trim($test_data['bulk_to_reference']);
                        $range_key = $from_ref . '|' . $to_ref;
                        if (!in_array($range_key, $referenceRanges)) {
                            $referenceRanges[$range_key] = ['from' => $from_ref, 'to' => $to_ref];
                            $hasReferenceRange = true;
                        }
                    }
                }
                // Check for characteristics, water_perm, sun, uv tests with bundle_reference containing range (pipe separator)
                elseif (in_array($report['type'], ['characteristics', 'water_perm', 'sun', 'uv']) && isset($report['bundle_reference']) && !empty($report['bundle_reference'])) {
                    $bundle_ref = trim($report['bundle_reference']);
                    if (strpos($bundle_ref, '|') !== false) {
                        $parts = explode('|', $bundle_ref, 2);
                        if (count($parts) === 2) {
                            $from_ref = trim($parts[0]);
                            $to_ref = trim($parts[1]);
                            $range_key = $from_ref . '|' . $to_ref;
                            if (!in_array($range_key, $referenceRanges)) {
                                $referenceRanges[$range_key] = ['from' => $from_ref, 'to' => $to_ref];
                                $hasReferenceRange = true;
                            }
                        }
                    }
                }
            }
            
            // Build display text
            // If the reference itself is a range (contains |), parse and display it
            if (strpos($reference, '|') !== false) {
                $parts = explode('|', $reference, 2);
                if (count($parts) === 2) {
                    $from_ref = trim($parts[0]);
                    $to_ref = trim($parts[1]);
                    $referenceRangeDisplay = htmlspecialchars($from_ref) . ' to ' . htmlspecialchars($to_ref);
                    $hasReferenceRange = true;
                } else {
                    $referenceRangeDisplay = htmlspecialchars($reference);
                }
            } elseif ($hasReferenceRange) {
                if (count($referenceRanges) === 1) {
                    // Single range
                    $range = reset($referenceRanges);
                    $referenceRangeDisplay = htmlspecialchars($range['from']) . ' to ' . htmlspecialchars($range['to']);
                } else {
                    // Multiple ranges - show all
                    $rangeStrings = [];
                    foreach ($referenceRanges as $range) {
                        $rangeStrings[] = htmlspecialchars($range['from']) . ' to ' . htmlspecialchars($range['to']);
                    }
                    $referenceRangeDisplay = implode('; ', $rangeStrings);
                }
            } else {
                // No range, use the grouped reference
                $referenceRangeDisplay = htmlspecialchars($reference);
            }
            
            // Group reports by test name and method - show only one report per test method
            $testMethodGroups = [];
            foreach ($reports as $report) {
                // Create a unique key for test name + method
                $testKey = $report['test_name'] ?? '';
                // For QC test orders, use test_name which already includes method
                // For other types, use test_name as-is
                if (!isset($testMethodGroups[$testKey])) {
                    $testMethodGroups[$testKey] = [];
                }
                $testMethodGroups[$testKey][] = $report;
            }
            
            // For each test method group, keep only the most recent report (by updated_at)
            $uniqueReports = [];
            foreach ($testMethodGroups as $testKey => $testReports) {
                // Sort by updated_at descending to get most recent first
                usort($testReports, function($a, $b) {
                    return strtotime($b['updated_at']) - strtotime($a['updated_at']);
                });
                // Keep only the first (most recent) report for this test method
                $uniqueReports[] = $testReports[0];
            }
            
            // Sort unique reports by updated_at descending
            usort($uniqueReports, function($a, $b) {
                return strtotime($b['updated_at']) - strtotime($a['updated_at']);
            });
            
            // Update reports to show only unique ones
            $reports = $uniqueReports;
        ?>
        <div class="reference-group" style="margin-bottom: 30px; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px 20px; font-weight: 600; font-size: 16px;">
                <i class="fas fa-tag"></i> <?php echo $hasReferenceRange ? 'Reference Range:' : 'Reference:'; ?> <?php echo $referenceRangeDisplay; ?>
                <span style="float: right; font-size: 14px; opacity: 0.9;"><?php echo count($reports); ?> report(s)</span>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th style="min-width:200px;">Test Type & Method</th>
                            <th>Report Number</th>
                            <th>Test Date</th>
                            <th>Tested By</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $report): 
                            $badge_class = 'badge-fabric';
                            $check_link = '';
                            
                            switch($report['type']) {
                                case 'qc_test_order':
                                    $badge_class = 'badge-qc';
                                    $check_link = 'view_qc_test_order.php?report_number=' . urlencode($report['report_number']) . '&return=lab_testing_dashboard';
                                    break;
                                case 'fabric_pre':
                                    $badge_class = 'badge-fabric';
                                    $check_link = '../forms/fabric_pre_production_test.php?id=' . $report['id'];
                                    break;
                                case 'water_perm':
                                    $badge_class = 'badge-water';
                                    $check_link = 'check_water_permeability.php?id=' . $report['id'];
                                    break;
                                case 'characteristics':
                                    $badge_class = 'badge-char';
                                    $check_link = 'check_characteristics.php?id=' . $report['id'];
                                    break;
                            }
                        ?>
                        <tr>
                            <td>
                                <span class="test-badge <?php echo $badge_class; ?>">
                                    <?php 
                                    // For QC Test Orders, split test name and method for better display
                                    if ($report['type'] === 'qc_test_order' && strpos($report['test_name'], '(') !== false) {
                                        preg_match('/^(.+?)\s*\(([^)]+)\)$/', $report['test_name'], $matches);
                                        if ($matches) {
                                            echo htmlspecialchars($matches[1]);
                                            echo '<br><small style="font-size:10px; opacity:0.8;">' . htmlspecialchars($matches[2]) . '</small>';
                                        } else {
                                            echo htmlspecialchars($report['test_name']);
                                        }
                                    } else {
                                        echo htmlspecialchars($report['test_name']);
                                    }
                                    ?>
                                </span>
                            </td>
                            <td><strong><?php echo htmlspecialchars($report['report_number']); ?></strong></td>
                            <td><?php echo date('M d, Y', strtotime($report['test_date'])); ?></td>
                            <td><?php echo htmlspecialchars($report['tested_by']); ?></td>
                            <td><?php echo date('M d, Y H:i', strtotime($report['updated_at'])); ?></td>
                            <td>
                                <a href="<?php echo $check_link; ?>" target="_blank" class="action-btn btn-check">
                                    <i class="fas fa-check-circle"></i> Check
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-clipboard-check"></i>
            <h3 style="margin-bottom: 10px; color: #2c3e50;">✅ All Caught Up!</h3>
            <p>No pending reports to review. New tests will appear here when testers submit them.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Refresh only when approve/reject actions happen
(function() {
    let isRefreshing = false;
    
    // Function to refresh the dashboard
    function refreshDashboard() {
        if (isRefreshing) return;
        isRefreshing = true;
        
        // Reload the page with cache busting
        window.location.href = window.location.href.split('?')[0] + '?t=' + new Date().getTime();
    }
    
    // Listen for messages from child windows (checker approval pages)
    window.addEventListener('message', function(event) {
        // Verify origin for security
        if (event.origin !== window.location.origin) {
            return;
        }
        
        // If message indicates a report was processed (approved/rejected), refresh
        if (event.data && (event.data.type === 'report_processed' || event.data.type === 'report_approved' || event.data.type === 'report_rejected')) {
            // Small delay to ensure database is updated
            setTimeout(function() {
                refreshDashboard();
            }, 300);
        }
    });
})();
</script>

<?php 
PerformanceMonitor::end('lab_testing_dashboard');
$conn->close(); 
?>
</body>
</html>


