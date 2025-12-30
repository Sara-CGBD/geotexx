<?php
session_start();
if (file_exists(__DIR__ . '/../dev/auto_reload.php')) {
    include_once(__DIR__ . '/../dev/auto_reload.php'); // Optional auto-reload
}

// Performance monitoring
require_once '../config/PerformanceMonitor.php';
PerformanceMonitor::start();

// Prevent browser caching to ensure fresh data loads
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../config/security_config.php';

// Schema helpers
function colExists(mysqli $conn, string $table, string $column): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    $col = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    return $result && $result->num_rows > 0;
}

function ensureColumn(mysqli $conn, string $table, string $column, string $definition): void {
    if (!colExists($conn, $table, $column)) {
        $res = $conn->query("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
        // Fallback without positional clause if it fails (e.g., missing reference column in AFTER)
        if (!$res) {
            $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` VARCHAR(255) NULL");
        }
    }
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Only admin and AGM Ops can access
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if (!in_array($user_role, ['admin', 'agm ops', 'agm operations'])) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>Only Admin and AGM Operations can access QC Reports Dashboard.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Ensure required columns in qc_test_orders
ensureColumn($conn, 'qc_test_orders', 'report_number', " `report_number` VARCHAR(100) NULL AFTER id");
ensureColumn($conn, 'qc_test_orders', 'status', " `status` VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER report_number");
ensureColumn($conn, 'qc_test_orders', 'checked_by', " `checked_by` VARCHAR(100) NULL AFTER status");
ensureColumn($conn, 'qc_test_orders', 'inspector_name', " `inspector_name` VARCHAR(255) NULL AFTER sample_reference_id");

// Helper function to get readable status labels
function getStatusLabel($status) {
    $labels = [
        'pending_checker' => 'Pending Checker Review',
        'pending_approval' => 'Forwarded to AGM/Admin',
        'approved' => 'Approved',
        'rejected_by_checker' => 'Rejected by Checker',
        'rejected_by_approver' => 'Rejected by Admin',
        'pending' => 'Pending',
        'checked' => 'Checked - Forwarded to Admin'
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

$message = '';
$error = '';

// Check for success message from session (after redirect)
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $report_type = $_POST['report_type'];
    $report_number = $_POST['report_number'];
    $comments = $_POST['comments'] ?? '';
    
    // Handle rejection reasons checkboxes from QC Dashboard
    if ($action === 'reject' && isset($_POST['qc_rejection_reasons']) && is_array($_POST['qc_rejection_reasons'])) {
        $rejection_reasons = array_map('trim', $_POST['qc_rejection_reasons']);
        $reasons_text = implode(', ', $rejection_reasons);
        // Prepend reasons to comment
        $comments = "Rejection Reasons: " . $reasons_text . ($comments ? "\n\nAdditional Comments: " . $comments : '');
    }
    
    $table_map = [
        'qc_test_order' => 'qc_test_orders',
        'sewing' => 'sewing_thread_reports',
        'uv' => 'weathering_exposure_reports',
        'fiber' => 'fiber_test_reports',
        'fabric_pre' => 'fabric_pre_production_tests',
        'fabric_after' => 'fabric_after_production_tests',
        'sun' => 'sun_test_reports',
        'water_perm' => 'water_permeability_tests',
        'characteristics' => 'characteristics_tests'
    ];
    
    if (isset($table_map[$report_type])) {
        $table = $table_map[$report_type];
        $approver = $_SESSION['full_name'] ?? $_SESSION['username'];
        
        if ($action === 'approve') {
            // For QC Test Orders, use different field names
            if ($report_type === 'qc_test_order') {
                $approved_at = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = NOW() WHERE report_number = ?");
                $stmt->bind_param("sss", $approver, $approved_at, $report_number);
            } elseif ($report_type === 'characteristics') {
                // Characteristics tests use approver_name instead of approved_by
                $approved_at = date('Y-m-d H:i:s');
                $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approver_name = ?, approved_at = ? WHERE report_number = ?");
                $stmt->bind_param("sss", $approver, $approved_at, $report_number);
            } else {
                $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approved_by = ? WHERE report_number = ?");
                $stmt->bind_param("ss", $approver, $report_number);
            }
        } else {
            // For QC Test Orders, use admin_remarks instead of remarks
            if ($report_type === 'qc_test_order') {
                $approved_at = date('Y-m-d H:i:s');
                $status = 'rejected_by_approver';
                $stmt = $conn->prepare("UPDATE $table SET status = ?, admin_remarks = ?, approved_by = ?, approved_at = ?, updated_at = NOW() WHERE report_number = ?");
                $stmt->bind_param("sssss", $status, $comments, $approver, $approved_at, $report_number);
            } elseif ($report_type === 'characteristics') {
                // Characteristics tests use approver_name instead of approved_by
                $stmt = $conn->prepare("UPDATE $table SET status = 'rejected', remarks = ?, approver_name = ? WHERE report_number = ?");
                $stmt->bind_param("sss", $comments, $approver, $report_number);
            } else {
                $stmt = $conn->prepare("UPDATE $table SET status = 'rejected', remarks = ?, approved_by = ? WHERE report_number = ?");
                $stmt->bind_param("sss", $comments, $approver, $report_number);
            }
        }
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['success_message'] = ucfirst($action) . "d successfully! Report: " . $report_number;
            $stmt->close();
            // Prevent caching and redirect to refresh the page
            header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
            header("Cache-Control: post-check=0, pre-check=0", false);
            header("Pragma: no-cache");
            header("Location: qc_reports_dashboard.php?t=" . time());
            exit();
        } else {
            $error = "Failed to " . $action . " report";
            $stmt->close();
        }
    }
}

// Get all pending reports from all QC test tables
$pendingReports = [];

// 1. QC Test Orders (now included in approval workflow with checker system)
// EXCLUDE external tests - they should show in AGM External Test Dashboard
$result = $conn->query("SELECT qto.id, 'qc_test_order' as type, 
    CONCAT(ts.test_name, ' (', qto.chosen_method, ')') as test_name, 
    qto.report_number, 
    qto.sample_reference_id as sample_description,
    COALESCE(qto.sample_reference_id, '') as reference_number,
    DATE(qto.created_at) as test_date, 
    qto.inspector_name as tested_by, 
    COALESCE(qto.checked_by, '') as checked_by,
    qto.status, 
    qto.updated_at,
    qto.test_data
    FROM qc_test_orders qto
    LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
    WHERE qto.status = 'pending_approval'
    ORDER BY qto.updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // EXCLUDE external tests - they belong in AGM External Test Dashboard
        $test_data = json_decode($row['test_data'] ?? '{}', true);
        $sample_ref = trim($row['sample_description'] ?? '');
        $is_external = false;
        
        // Check if external by prefix
        if (!empty($sample_ref) && (stripos($sample_ref, 'EXT-') === 0 || stripos($sample_ref, 'TOKEN-') === 0)) {
            $is_external = true;
        }
        // Check if external by flag
        if (!$is_external && isset($test_data['is_external_product']) && ($test_data['is_external_product'] == '1' || $test_data['is_external_product'] === true)) {
            $is_external = true;
        }
        
        // Skip external tests
        if ($is_external) {
            continue;
        }
        
        $pendingReports[] = $row;
    }
}

// 2. Sewing Thread Reports (exclude admin-created reports)
$result = $conn->query("SELECT id, 'sewing' as type, 'Sewing Thread' as test_name, report_number, sample_description, test_start_date as test_date, test_performed_by as tested_by, status, updated_at, COALESCE(reference, '') as reference_number, '' as bundle_reference
    FROM sewing_thread_reports 
    WHERE status = 'pending' 
    AND reporter_id NOT IN (SELECT id FROM new_user WHERE role = 'admin')
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pendingReports[] = $row;
    }
}

// 3. UV Test (Weathering Exposure) (exclude admin-created reports)
// Check if bundle_reference column exists
$uvBundleColCheck = $conn->query("SHOW COLUMNS FROM weathering_exposure_reports LIKE 'bundle_reference'");
$uvHasBundleCol = ($uvBundleColCheck && $uvBundleColCheck->num_rows > 0);
$uvBundleSelect = $uvHasBundleCol ? ", COALESCE(bundle_reference, '') as bundle_reference" : ", '' as bundle_reference";
$uvRefSelect = ", COALESCE(reference, '') as reference_number";

$result = $conn->query("SELECT id, 'uv' as type, 'UV Test' as test_name, report_number, sample_description, test_start_date as test_date, COALESCE(test_performed_by, tested_by) as tested_by, status, updated_at $uvRefSelect $uvBundleSelect
    FROM weathering_exposure_reports 
    WHERE status = 'pending' 
    AND reporter_id NOT IN (SELECT id FROM new_user WHERE role = 'admin')
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pendingReports[] = $row;
    }
}

// 4. Fiber Test Reports (exclude admin-created reports)
// Only include for admin; AGM Ops does not need to see raw material fiber tests here
if ($user_role === 'admin') {
$fiberRefColCheck = $conn->query("SHOW COLUMNS FROM fiber_test_reports LIKE 'store_entry_reference'");
$fiberHasRefCol = ($fiberRefColCheck && $fiberRefColCheck->num_rows > 0);
$fiberRefSelect = $fiberHasRefCol ? ", COALESCE(store_entry_reference, '') as reference_number" : ", '' as reference_number";

$result = $conn->query("SELECT id, 'fiber' as type, 'Fiber Test' as test_name, report_number, sample_id as sample_description, sample_tested_date as test_date, test_performed_by as tested_by, status, updated_at $fiberRefSelect, '' as bundle_reference
    FROM fiber_test_reports 
    WHERE status = 'pending' 
    AND reporter_id NOT IN (SELECT id FROM new_user WHERE role = 'admin')
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pendingReports[] = $row;
        }
    }
}

// 5. Fabric Pre-Production (using fabric_pre_production_tests table) (exclude admin-created reports)
// Check if product_reference or customer_reference columns exist
$fabricPreRefColCheck = $conn->query("SHOW COLUMNS FROM fabric_pre_production_tests LIKE 'product_reference'");
$fabricPreHasRefCol = ($fabricPreRefColCheck && $fabricPreRefColCheck->num_rows > 0);
$fabricPreRefSelect = $fabricPreHasRefCol ? ", COALESCE(NULLIF(product_reference, ''), NULLIF(customer_reference, ''), '') as reference_number" : ", '' as reference_number";

$result = $conn->query("SELECT id, 'fabric_pre' as type, 'Fabric Pre-Production' as test_name, report_number, sample_details as sample_description, test_period_from as test_date, test_performed_by as tested_by, status, updated_at $fabricPreRefSelect, '' as bundle_reference
    FROM fabric_pre_production_tests 
    WHERE status = 'pending_approval' 
    AND reporter_id NOT IN (SELECT id FROM new_user WHERE role = 'admin')
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pendingReports[] = $row;
    }
}

// 6. Fabric After Production (using fabric_after_production_tests table) (exclude admin-created reports)
// Check if product_reference or customer_reference columns exist
$fabricAfterRefColCheck = $conn->query("SHOW COLUMNS FROM fabric_after_production_tests LIKE 'product_reference'");
$fabricAfterHasRefCol = ($fabricAfterRefColCheck && $fabricAfterRefColCheck->num_rows > 0);
$fabricAfterRefSelect = $fabricAfterHasRefCol ? ", COALESCE(NULLIF(product_reference, ''), NULLIF(customer_reference, ''), '') as reference_number" : ", '' as reference_number";

$result = $conn->query("SELECT id, 'fabric_after' as type, 'Fabric After Production' as test_name, report_number, sample_id as sample_description, sample_tested_date as test_date, test_performed_by as tested_by, status, updated_at $fabricAfterRefSelect, '' as bundle_reference
    FROM fabric_after_production_tests 
    WHERE status = 'pending' 
    AND reporter_id NOT IN (SELECT id FROM new_user WHERE role = 'admin')
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pendingReports[] = $row;
    }
}

// 7. Sun Test Reports (exclude admin-created reports)
// Check if reference_number and bundle_reference columns exist
$sunRefColCheck = $conn->query("SHOW COLUMNS FROM sun_test_reports LIKE 'reference_number'");
$sunHasRefCol = ($sunRefColCheck && $sunRefColCheck->num_rows > 0);
$sunRefSelect = $sunHasRefCol ? ", COALESCE(reference_number, '') as reference_number" : ", '' as reference_number";
$sunBundleColCheck = $conn->query("SHOW COLUMNS FROM sun_test_reports LIKE 'bundle_reference'");
$sunHasBundleCol = ($sunBundleColCheck && $sunBundleColCheck->num_rows > 0);
$sunBundleSelect = $sunHasBundleCol ? ", COALESCE(bundle_reference, '') as bundle_reference" : ", '' as bundle_reference";

$result = $conn->query("SELECT id, 'sun' as type, 'Sun Test' as test_name, report_number, sample_description, test_start_date as test_date, test_performed_by as tested_by, status, updated_at $sunRefSelect $sunBundleSelect
    FROM sun_test_reports 
    WHERE status = 'pending' 
    AND reporter_id NOT IN (SELECT id FROM new_user WHERE role = 'admin')
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pendingReports[] = $row;
    }
}

// 8. Water Permeability Test Reports (exclude admin-created reports)
// Only show 'checked' status - these have been approved by checker and are waiting for admin/AGM approval
// Check if reference_number and bundle_reference columns exist
$wpRefColCheck = $conn->query("SHOW COLUMNS FROM water_permeability_tests LIKE 'reference_number'");
$wpHasRefCol = ($wpRefColCheck && $wpRefColCheck->num_rows > 0);
$wpRefSelect = $wpHasRefCol ? ", COALESCE(reference_number, '') as reference_number" : ", '' as reference_number";
$wpBundleColCheck = $conn->query("SHOW COLUMNS FROM water_permeability_tests LIKE 'bundle_reference'");
$wpHasBundleCol = ($wpBundleColCheck && $wpBundleColCheck->num_rows > 0);
$wpBundleSelect = $wpHasBundleCol ? ", COALESCE(bundle_reference, '') as bundle_reference" : ", '' as bundle_reference";

$result = $conn->query("SELECT id, 'water_perm' as type, 'Water Permeability Test' as test_name, report_number, 
    CONCAT('GSM: ', gsm, ', Roll: ', roll_number) as sample_description, 
    test_date, test_performed_by as tested_by, COALESCE(checked_by, '') as checked_by, status, updated_at $wpRefSelect $wpBundleSelect
    FROM water_permeability_tests 
    WHERE status = 'checked'
    AND reporter_id NOT IN (SELECT id FROM new_user WHERE role = 'admin')
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pendingReports[] = $row;
    }
}

// 9. Characteristics Test Reports (ISO 12956) (exclude admin-created reports)
// Only show 'checked' status - these have been approved by checker and are waiting for admin/AGM approval
// Check if reference_number and bundle_reference columns exist
$charRefColCheck = $conn->query("SHOW COLUMNS FROM characteristics_tests LIKE 'reference_number'");
$charHasRefCol = ($charRefColCheck && $charRefColCheck->num_rows > 0);
$charRefSelect = $charHasRefCol ? ", COALESCE(reference_number, '') as reference_number" : ", '' as reference_number";
$charBundleColCheck = $conn->query("SHOW COLUMNS FROM characteristics_tests LIKE 'bundle_reference'");
$charHasBundleCol = ($charBundleColCheck && $charBundleColCheck->num_rows > 0);
$charBundleSelect = $charHasBundleCol ? ", COALESCE(bundle_reference, '') as bundle_reference" : ", '' as bundle_reference";

$result = $conn->query("SELECT id, 'characteristics' as type, 'Characteristics Test (ISO 12956)' as test_name, report_number, 
    CONCAT('GSM: ', gsm, ', Sample: ', sample_id) as sample_description, 
    DATE(sample_tested) as test_date, test_performed_by as tested_by, COALESCE(checker_name, '') as checked_by, status, updated_at $charRefSelect $charBundleSelect
    FROM characteristics_tests 
    WHERE status = 'checked'
    AND reporter_id NOT IN (SELECT id FROM new_user WHERE role = 'admin')
    ORDER BY updated_at DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $pendingReports[] = $row;
    }
}

// Group reports by bundle_reference (if exists) or reference_number
// This ensures all bundle references show together for the same tests
$groupedReports = [];
$reportsWithoutReference = [];

foreach ($pendingReports as $report) {
    // Prioritize bundle_reference over reference_number for grouping
    $bundleRef = trim($report['bundle_reference'] ?? '');
    $reference = trim($report['reference_number'] ?? '');
    
    // Use bundle_reference if it exists and is not empty, otherwise use reference_number
    $groupKey = !empty($bundleRef) ? $bundleRef : $reference;
    
    if (empty($groupKey)) {
        $groupKey = 'Other Reports';
    }
    
    if (!isset($groupedReports[$groupKey])) {
        $groupedReports[$groupKey] = [];
    }
    $groupedReports[$groupKey][] = $report;
}

// Sort each group by updated_at descending
foreach ($groupedReports as $ref => $reports) {
    usort($groupedReports[$ref], function($a, $b) {
        return strtotime($b['updated_at']) - strtotime($a['updated_at']);
    });
}

// Sort reference groups: put "Other Reports" last, others alphabetically
uksort($groupedReports, function($a, $b) {
    if ($a === 'Other Reports') return 1;
    if ($b === 'Other Reports') return -1;
    return strcmp($a, $b);
});

$totalPending = count($pendingReports);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QC Reports Dashboard - Approval Center</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fb; padding: 0; margin: 0; color: #0f172a; min-height: 100vh; }
        .container { width: 100%; min-height: 100vh; margin: 0; background: #ffffff; border-radius: 0; padding: 30px 3vw 60px; box-shadow: none; border: none; }
        h1 { text-align: center; color: #0f172a; margin-bottom: 6px; font-size: 32px; letter-spacing: 0.5px; }
        .subtitle { text-align: center; color: #64748b; margin-bottom: 25px; font-size: 15px; text-transform: uppercase; letter-spacing: 3px; }
        .stats { display: flex; justify-content: center; gap: 20px; margin-bottom: 30px; }
        .stat-item { flex: 1; min-width: 200px; background: linear-gradient(135deg, #2563eb, #7c3aed); border-radius: 18px; padding: 18px 25px; color: #f8fafc; box-shadow: 0 15px 35px rgba(37,99,235,0.4); text-align: center; }
        .stat-value { font-size: 38px; font-weight: 700; margin-bottom: 5px; }
        .stat-label { font-size: 13px; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.9; }
        .alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: 600; border: 1px solid; }
        .alert-success { background: #ecfdf5; color: #047857; border-color: #6ee7b7; }
        .alert-error { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        th, td { padding: 14px 16px; text-align: left; }
        th { background: rgba(15,23,42,0.95); color: #e2e8f0; font-weight: 700; position: sticky; top: 0; text-transform: uppercase; font-size: 13px; letter-spacing: 0.6px; border-bottom: 1px solid rgba(255,255,255,0.15); }
        tbody td { border-bottom: 1px solid rgba(226,232,240,0.8); }
        tbody tr:nth-child(even) { background: rgba(248,250,252,0.85); }
        tbody tr:hover { background: #f1f5f9; box-shadow: 0 8px 20px rgba(15,23,42,0.08); transform: translateY(-2px); transition: 0.25s ease; }
        .test-badge { padding: 8px 14px; border-radius: 999px; font-size: 0.83em; font-weight: 600; display: inline-block; line-height: 1.4; box-shadow: inset 0 0 0 1px rgba(15,23,42,0.08); }
        .badge-qc-test-order { background: #e1f5fe; color: #01579b; }
        .badge-sewing { background: #e8f5e9; color: #2e7d32; }
        .badge-uv { background: #fff3e0; color: #e65100; }
        .badge-fiber { background: #e3f2fd; color: #1565c0; }
        .badge-fabric-pre { background: #f3e5f5; color: #6a1b9a; }
        .badge-fabric-after { background: #fce4ec; color: #c2185b; }
        .badge-sun { background: #fff9c4; color: #f57f17; }
        .badge-water-perm { background: #e0f2f1; color: #004d40; }
        .badge-characteristics { background: #fce4ec; color: #880e4f; }
        .approve-btn, .reject-btn, .view-btn { 
            border: none; border-radius: 10px; padding: 8px 18px; font-size: 0.85em; font-weight: 600; cursor: pointer; 
            box-shadow: 0 10px 20px rgba(15,23,42,0.15); transition: transform 0.2s ease, box-shadow 0.2s ease; 
        }
        .approve-btn { background: linear-gradient(135deg, #059669, #10b981); color: white; }
        .reject-btn { background: linear-gradient(135deg, #dc2626, #f97316); color: white; margin-left: 6px; }
        .view-btn { background: linear-gradient(135deg, #2563eb, #3b82f6); color: white; margin-left: 6px; text-decoration: none; display: inline-block; }
        .approve-btn:hover, .reject-btn:hover, .view-btn:hover { transform: translateY(-2px); box-shadow: 0 14px 30px rgba(15,23,42,0.25); }
        .no-data { text-align: center; padding: 80px; color: #94a3b8; }
    </style>
</head>
<body>
<div class="container">
    
    <a href="../index.php" style="display: inline-block; margin-bottom: 20px; padding: 10px 20px; background: #6c757d; color: white; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 500; transition: background 0.3s;" onmouseover="this.style.background='#5a6268'" onmouseout="this.style.background='#6c757d'">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
    
    <h1><i class="fas fa-clipboard-check"></i> QC Reports Dashboard</h1>
    <p class="subtitle">Centralized Approval Center for All QC Test Reports</p>
    
    <?php if ($message): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <!-- Statistics -->
    <div class="stats">
        <div class="stat-item">
            <div class="stat-value"><?php echo $totalPending; ?></div>
            <div class="stat-label">Pending Reports</div>
        </div>
    </div>
    
    <!-- Pending Reports Grouped by Reference Number -->
    <?php if ($totalPending > 0): ?>
        <h2 style="margin-bottom: 15px; color: #34495e;"><i class="fas fa-hourglass-half"></i> Pending Reports for Approval (Grouped by Reference)</h2>
        
        <?php 
        $groupCount = 0;
        $totalGroups = count(array_filter($groupedReports, function($reports) { return !empty($reports); }));
        foreach ($groupedReports as $referenceNumber => $reports): 
            // Skip empty reference groups
            if (empty($reports)) continue;
            $groupCount++;
            $isLast = ($groupCount === $totalGroups);
        ?>
            <div style="margin-bottom: <?php echo $isLast ? '0' : '15px'; ?>; border: 1px solid #e0e0e0; border-radius: 8px; overflow: hidden;">
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 12px 20px; font-weight: 600; font-size: 16px;">
                    <?php 
                    // Check if this is a bundle reference (has bundle_reference in any report)
                    $isBundle = false;
                    $bundleRef = '';
                    $individualRefs = [];
                    foreach ($reports as $rpt) {
                        if (!empty(trim($rpt['bundle_reference'] ?? ''))) {
                            $isBundle = true;
                            $bundleRef = trim($rpt['bundle_reference']);
                            break;
                        }
                    }
                    // Collect individual reference numbers for display
                    foreach ($reports as $rpt) {
                        $indRef = trim($rpt['reference_number'] ?? '');
                        if (!empty($indRef) && !in_array($indRef, $individualRefs)) {
                            $individualRefs[] = $indRef;
                        }
                    }
                    ?>
                    <i class="fas fa-tag"></i> 
                    <?php if ($isBundle): ?>
                        Bundle Reference: <strong><?php echo htmlspecialchars($bundleRef); ?></strong>
                        <?php if (count($individualRefs) > 0): ?>
                            <br><small style="font-size: 12px; opacity: 0.9; font-weight: normal;">
                                Individual References: <?php echo htmlspecialchars(implode(', ', $individualRefs)); ?>
                            </small>
                        <?php endif; ?>
                    <?php else: ?>
                        Reference: <?php echo htmlspecialchars($referenceNumber); ?>
                    <?php endif; ?>
                    <span style="float: right; background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 12px; font-size: 14px;">
                        <?php echo count($reports); ?> test(s)
                    </span>
                </div>
                <div style="overflow-x: auto; padding: 0;">
                    <table style="margin: 0; border-spacing: 0;">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th style="min-width:200px;">Test Type & Method</th>
                                <th>Report Number & Sample</th>
                                <th>Test Date</th>
                                <th>Tested By</th>
                                <th>Checked By</th>
                                <th>Status</th>
                                <th>Last Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $counter = 1;
                            foreach ($reports as $report): 
                                $badgeClass = 'badge-' . str_replace('_', '-', $report['type']);
                                
                                // Determine view link
                                $viewLinks = [
                                    'qc_test_order' => 'view_qc_test_order.php',
                                    'sewing' => 'view_sewing_report.php',
                                    'uv' => 'view_uv_report.php',
                                    'fiber' => 'view_fiber_report.php',
                                    'fabric_pre' => 'view_fabric_pre_prod_report.php',
                                    'fabric_after' => 'view_fabric_after_prod_report.php',
                                    'sun' => 'view_sun_report.php',
                                    'water_perm' => 'view_water_permeability.php',
                                    'characteristics' => 'view_characteristics.php'
                                ];
                                $viewLink = $viewLinks[$report['type']] ?? '#';
                                
                                // For qc_test_order, use report_number parameter instead of id
                                $viewParam = ($report['type'] === 'qc_test_order') ? "report_number={$report['report_number']}" : "id={$report['id']}";
                            ?>
                                <tr data-report-type="<?php echo htmlspecialchars($report['type']); ?>" data-report-number="<?php echo htmlspecialchars($report['report_number']); ?>">
                                    <td><?php echo $counter++; ?></td>
                                    <td>
                                        <span class="test-badge <?php echo $badgeClass; ?>">
                                            <?php 
                                            // For QC Test Orders, split test name and method for better display
                                            if ($report['type'] === 'qc_test_order' && strpos($report['test_name'], '(') !== false) {
                                                // Extract test name and method
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
                                    <td>
                                        <strong><?php echo htmlspecialchars($report['report_number']); ?></strong>
                                        <?php if (!empty($report['sample_description'])): ?>
                                        <br><small style="color:#6c757d; font-size:11px;"><?php echo htmlspecialchars($report['sample_description']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($report['test_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($report['tested_by']); ?></td>
                                    <td><?php echo (!empty($report['checked_by']) && trim($report['checked_by']) !== '') ? htmlspecialchars($report['checked_by']) : '<span style="color:#999;">N/A</span>'; ?></td>
                                    <td>
                                        <span style="display:inline-block; background:#ff9800; color:#fff; padding:5px 12px; border-radius:4px; font-size:12px; font-weight:600;">
                                            <?php 
                                            // For Water Permeability and Characteristics tests, always show "Pending"
                                            if ($report['type'] === 'water_perm' || $report['type'] === 'characteristics') {
                                                echo 'Pending';
                                            } else {
                                                echo getStatusLabel($report['status'] ?? 'pending_approval');
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y g:i A', strtotime($report['updated_at'])); ?></td>
                                    <td style="white-space: nowrap;">
                                        <a href="<?php echo $viewLink; ?>?<?php echo $viewParam; ?>" 
                                           class="view-btn" target="_blank">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <button type="button" onclick="approveReportFromDashboard('<?php echo $report['type']; ?>', '<?php echo htmlspecialchars($report['report_number']); ?>')" class="approve-btn">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button type="button" onclick="openQCRejectModal('<?php echo $report['type']; ?>', '<?php echo htmlspecialchars($report['report_number']); ?>')" class="reject-btn">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-check-circle" style="font-size: 4em; margin-bottom: 15px; color: #27ae60;"></i>
            <h3>All Caught Up!</h3>
            <p>No pending QC reports for approval at this time.</p>
        </div>
    <?php endif; ?>
    
</div>

<script>
function confirmApproval() {
    return confirm('Are you sure you want to approve this report?');
}

function confirmRejection(form) {
    const comments = prompt('Please enter rejection reason (mandatory):');
    if (comments === null || comments.trim() === '') {
        alert('Rejection reason is mandatory!');
        return false;
    }
    form.querySelector('.rejection-comments').value = comments;
    return confirm('Are you sure you want to reject this report with the reason: "' + comments + '"?');
}

// QC Dashboard rejection modal functions
function openQCRejectModal(reportType, reportNumber) {
    document.getElementById('qcRejectType').value = reportType;
    document.getElementById('qcRejectNumber').value = reportNumber;
    document.getElementById('qcRejectionModal').style.display = 'block';
}

function closeQCRejectModal() {
    document.getElementById('qcRejectionModal').style.display = 'none';
    document.getElementById('qcRejectForm').reset();
}

function approveReportFromDashboard(reportType, reportNumber) {
    if (!confirm('Are you sure you want to approve this report?')) {
        return;
    }
    
    const row = document.querySelector(`tr[data-report-type="${reportType}"][data-report-number="${reportNumber}"]`);
    if (!row) return;
    
    const approveBtn = row.querySelector('.approve-btn');
    const originalText = approveBtn.innerHTML;
    approveBtn.disabled = true;
    approveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Approving...';
    
    const formData = new FormData();
    formData.append('action', 'approve');
    formData.append('report_type', reportType);
    formData.append('report_number', reportNumber);
    
    fetch('api/approve_reject_report.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            removeReportRow(reportType, reportNumber);
            updatePendingCount();
            alert('✅ ' + data.message);
        } else {
            alert('❌ ' + data.message);
            approveBtn.disabled = false;
            approveBtn.innerHTML = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('❌ An error occurred. Please try again.');
        approveBtn.disabled = false;
        approveBtn.innerHTML = originalText;
    });
}

function submitQCRejection() {
    const form = document.getElementById('qcRejectForm');
    const reportType = document.getElementById('qcRejectType').value;
    const reportNumber = document.getElementById('qcRejectNumber').value;
    const checkboxes = document.querySelectorAll('input[name="qc_rejection_reasons[]"]');
    const checked = Array.from(checkboxes).filter(cb => cb.checked);
    
    if (checked.length === 0) {
        alert('❌ Please select at least one reason for rejection!');
        return false;
    }
    
    if (!confirm('Are you sure you want to reject this report?')) {
        return false;
    }
    
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Rejecting...';
    
    const formData = new FormData(form);
    formData.append('action', 'reject');
    
    fetch('api/approve_reject_report.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeQCRejectModal();
            removeReportRow(reportType, reportNumber);
            updatePendingCount();
            alert('✅ ' + data.message);
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

// Remove report row from dashboard
function removeReportRow(reportType, reportNumber) {
    const row = document.querySelector(`tr[data-report-type="${reportType}"][data-report-number="${reportNumber}"]`);
    if (!row) return;
    
    // Find the parent reference group
    const referenceGroup = row.closest('div[style*="border"]');
    const table = row.closest('table');
    const tbody = table.querySelector('tbody');
    
    // Remove the row
    row.remove();
    
    // Check if there are any more rows in this reference group
    const remainingRows = tbody.querySelectorAll('tr[data-report-type]');
    if (remainingRows.length === 0 && referenceGroup) {
        // No more reports in this reference group, remove the entire group
        referenceGroup.remove();
    }
    
    // Check if there are any more reference groups
    const remainingGroups = document.querySelectorAll('div[style*="border"]');
    if (remainingGroups.length === 0) {
        // Show "All Caught Up" message
        const container = document.querySelector('.container');
        const noDataDiv = document.createElement('div');
        noDataDiv.className = 'no-data';
        noDataDiv.innerHTML = `
            <i class="fas fa-check-circle" style="font-size: 4em; margin-bottom: 15px; color: #27ae60;"></i>
            <h3>All Caught Up!</h3>
            <p>No pending QC reports for approval at this time.</p>
        `;
        const statsDiv = document.querySelector('.stats');
        if (statsDiv && statsDiv.nextSibling) {
            statsDiv.parentNode.insertBefore(noDataDiv, statsDiv.nextSibling);
        }
    }
}

// Update pending count
function updatePendingCount() {
    const rows = document.querySelectorAll('tr[data-report-type]');
    const count = rows.length;
    const statValue = document.querySelector('.stat-value');
    if (statValue) {
        statValue.textContent = count;
    }
}

// Listen for messages from child windows
window.addEventListener('message', function(event) {
    // Verify origin for security
    if (event.origin !== window.location.origin) {
        return;
    }
    
    if (event.data && event.data.type === 'report_processed') {
        removeReportRow(event.data.report_type, event.data.report_number);
        updatePendingCount();
    }
});
</script>

<!-- Rejection Modal for QC Dashboard -->
<div id="qcRejectionModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow-y:auto;">
  <div style="max-width:600px; margin:50px auto; background:#fff; border-radius:8px; padding:25px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
    <h3 style="margin-top:0; color:#dc3545; border-bottom:2px solid #dc3545; padding-bottom:10px;">
      ❌ Reject QC Report
    </h3>
    
    <form id="qcRejectForm" onsubmit="return submitQCRejection();">
      <input type="hidden" id="qcRejectType" name="report_type" value="">
      <input type="hidden" id="qcRejectNumber" name="report_number" value="">
      <input type="hidden" name="action" value="reject">
      
      <label style="font-weight:600; display:block; margin-bottom:10px;">Reason for Rejection (Select at least one):</label>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="qc_rejection_reasons[]" value="Incorrect Roll Identification" style="margin-right:8px;">
          Incorrect Roll Identification
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="qc_rejection_reasons[]" value="Incorrect Fiber Specification Entry" style="margin-right:8px;">
          Incorrect Fiber Specification Entry
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="qc_rejection_reasons[]" value="Excessive Sampling" style="margin-right:8px;">
          Excessive Sampling
        </label>
      </div>
      
      <label style="font-weight:bold; display:block; margin:15px 0 8px 0;">
        Additional Comments (Optional):
      </label>
      <textarea name="comments" id="qcRejectComments" rows="4" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-family:inherit;" placeholder="Provide additional details..."></textarea>
      
      <div style="margin-top:20px; text-align:right;">
        <button type="button" onclick="closeQCRejectModal()" style="padding:10px 20px; margin-right:10px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer;">Cancel</button>
        <button type="submit" style="padding:10px 20px; background:#dc3545; color:#fff; border:none; border-radius:6px; cursor:pointer;">Submit Rejection</button>
      </div>
    </form>
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
    
    // Listen for messages from child windows (approval/rejection pages)
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

<?php PerformanceMonitor::end('qc_reports_dashboard'); ?>
</body>
</html>


