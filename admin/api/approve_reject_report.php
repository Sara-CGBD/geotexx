<?php
/**
 * API endpoint for AJAX-based report approval/rejection
 * Returns JSON response for dynamic dashboard updates
 */

session_start();
require_once __DIR__ . '/../../config/security_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only admin and AGM can access
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if (!in_array($user_role, ['admin', 'agm', 'agm ops', 'agm operations', 'checker'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';
$report_type = $_POST['report_type'] ?? '';
$report_number = $_POST['report_number'] ?? '';
$comments = $_POST['comments'] ?? '';
$related_report_numbers = isset($_POST['related_report_numbers']) ? trim($_POST['related_report_numbers']) : '';
$roll_destination = $_POST['roll_destination'] ?? '';

if (empty($action) || empty($report_type) || empty($report_number)) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

// Parse related report numbers if provided
$all_report_numbers = [$report_number];
if (!empty($related_report_numbers)) {
    $related = array_filter(array_map('trim', explode(',', $related_report_numbers)));
    $all_report_numbers = array_merge($all_report_numbers, $related);
}

// If report_number contains commas, it's a bulk operation - parse it
if (strpos($report_number, ',') !== false) {
    $all_report_numbers = array_filter(array_map('trim', explode(',', $report_number)));
}

// For QC test orders, automatically find all reports with the same bulk reference range
if ($report_type === 'qc_test_order') {
    $conn = SecurityConfig::getConnection();
    
    // Get the current report's test_data to check for bulk reference range
    $getReportStmt = $conn->prepare("SELECT test_data, test_standard_id, chosen_method, status FROM qc_test_orders WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
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
            $current_status = $currentReport['status'];
            
            // Find all reports with the same bulk reference range, test, and method
            // For approval: find all with same range that are pending_approval
            // For rejection: find all with same range and same status
            $status_filter = ($action === 'approve') ? "AND qto.status = 'pending_approval'" : "AND qto.status = ?";
            
            if ($action === 'approve') {
                $findRelatedStmt = $conn->prepare("
                    SELECT qto.report_number, qto.test_data
                    FROM qc_test_orders qto
                    WHERE qto.test_standard_id = ?
                    AND qto.chosen_method = ?
                    AND qto.status = 'pending_approval'
                    AND qto.report_number COLLATE utf8mb4_unicode_ci != ?
                ");
                $findRelatedStmt->bind_param("iss", $test_standard_id, $chosen_method, $report_number);
            } else {
                $findRelatedStmt = $conn->prepare("
                    SELECT qto.report_number, qto.test_data
                    FROM qc_test_orders qto
                    WHERE qto.test_standard_id = ?
                    AND qto.chosen_method = ?
                    AND qto.status = ?
                    AND qto.report_number COLLATE utf8mb4_unicode_ci != ?
                ");
                $findRelatedStmt->bind_param("isss", $test_standard_id, $chosen_method, $current_status, $report_number);
            }
            
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

// Handle rejection reasons checkboxes
if ($action === 'reject' && isset($_POST['qc_rejection_reasons']) && is_array($_POST['qc_rejection_reasons'])) {
    $rejection_reasons = array_map('trim', $_POST['qc_rejection_reasons']);
    $reasons_text = implode(', ', $rejection_reasons);
    $comments = "Rejection Reasons: " . $reasons_text . ($comments ? "\n\nAdditional Comments: " . $comments : '');
}

$table_map = [
    'qc_test_order' => 'qc_test_orders',
    'sewing' => 'sewing_thread_reports',
    'uv' => 'weathering_exposure_reports',
    'uv_test' => 'weathering_exposure_reports', // Also support uv_test format
    'fiber' => 'fiber_test_reports',
    'fabric_pre' => 'fabric_pre_production_tests',
    'fabric_after' => 'fabric_after_production_tests',
    'sun' => 'sun_test_reports',
    'sun_test' => 'sun_test_reports', // Also support sun_test format
    'water_perm' => 'water_permeability_tests',
    'water_permeability' => 'water_permeability_tests', // Also support water_permeability format
    'characteristics' => 'characteristics_tests'
];

if (!isset($table_map[$report_type])) {
    echo json_encode(['success' => false, 'message' => 'Invalid report type']);
    exit;
}

$table = $table_map[$report_type];
$conn = SecurityConfig::getConnection();
$approver = $_SESSION['full_name'] ?? $_SESSION['username'];

try {
    $success_count = 0;
    $failed_reports = [];
    $approved_at = date('Y-m-d H:i:s');
    
    // Handle routing action separately (it processes all report numbers at once)
    if ($action === 'route') {
        // Handle routing for already approved tests
        if (empty($roll_destination)) {
            echo json_encode(['success' => false, 'message' => 'Routing destination is required']);
            exit;
        }
        
        // Ensure roll_destination column exists
        $checkRollDest = $conn->query("SHOW COLUMNS FROM $table LIKE 'roll_destination'");
        if ($checkRollDest && $checkRollDest->num_rows === 0) {
            @$conn->query("ALTER TABLE $table ADD COLUMN roll_destination VARCHAR(255) NULL");
        }
        
        // Handle comma-separated report numbers (bulk routing)
        $report_nums_to_route = [];
        if (strpos($report_number, ',') !== false) {
            $report_nums_to_route = array_filter(array_map('trim', explode(',', $report_number)));
        } else {
            $report_nums_to_route = [$report_number];
        }
        
        // For QC test orders, check if any report has bulk references and update ALL rolls in that bundle
        $processed_bundles = []; // Track bundles we've already processed
        
        // Update routing destination for all report numbers and populate routed table
        foreach ($report_nums_to_route as $report_num) {
            // First, check if this is a QC test order with bulk references
            if ($report_type === 'qc_test_order') {
                // Get test_data to check for bulk references
                $checkBulkStmt = $conn->prepare("SELECT test_data, sample_reference_id FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                $checkBulkStmt->bind_param("s", $report_num);
                $checkBulkStmt->execute();
                $bulkResult = $checkBulkStmt->get_result();
                $bulkRow = $bulkResult->fetch_assoc();
                $checkBulkStmt->close();
                
                if ($bulkRow && !empty($bulkRow['test_data'])) {
                    $test_data = json_decode($bulkRow['test_data'], true);
                    if (isset($test_data['is_bulk_reference']) && $test_data['is_bulk_reference'] &&
                        isset($test_data['bulk_from_reference']) && isset($test_data['bulk_to_reference'])) {
                        
                        $bulk_from = trim($test_data['bulk_from_reference']);
                        $bulk_to = trim($test_data['bulk_to_reference']);
                        $bundle_key = $bulk_from . '|' . $bulk_to;
                        
                        // If we haven't processed this bundle yet, update ALL rolls in the bundle
                        if (!in_array($bundle_key, $processed_bundles)) {
                            $processed_bundles[] = $bundle_key;
                            
                            // Extract base reference and roll numbers
                            $fromBaseRef = '';
                            $fromRollNum = 0;
                            $toBaseRef = '';
                            $toRollNum = 0;
                            
                            if (preg_match('/^(.+)-(\d+)$/', $bulk_from, $fromMatches)) {
                                $fromBaseRef = $fromMatches[1];
                                $fromRollNum = (int)$fromMatches[2];
                            } else {
                                $fromBaseRef = $bulk_from;
                            }
                            
                            if (preg_match('/^(.+)-(\d+)$/', $bulk_to, $toMatches)) {
                                $toBaseRef = $toMatches[1];
                                $toRollNum = (int)$toMatches[2];
                            } else {
                                $toBaseRef = $bulk_to;
                            }
                            
                            // Only process if both have same base and valid roll numbers
                            if ($fromBaseRef === $toBaseRef && $fromRollNum > 0 && $toRollNum > 0 && $fromRollNum <= $toRollNum) {
                                // Generate all individual roll references in the bundle range
                                $individualRefs = [];
                                for ($i = $fromRollNum; $i <= $toRollNum; $i++) {
                                    $individualRefs[] = $fromBaseRef . '-' . $i;
                                }
                                
                                // Update ALL qc_test_orders entries for all individual rolls in this bundle
                                $placeholders = str_repeat('?,', count($individualRefs) - 1) . '?';
                                $updateBulkStmt = $conn->prepare("
                                    UPDATE $table 
                                    SET roll_destination = ?, updated_at = NOW() 
                                    WHERE status = 'approved'
                                    AND sample_reference_id IN ($placeholders)
                                ");
                                
                                $params = array_merge([$roll_destination], $individualRefs);
                                $types = str_repeat('s', count($params));
                                $updateBulkStmt->bind_param($types, ...$params);
                                
                                if ($updateBulkStmt->execute()) {
                                    $bulkAffected = $updateBulkStmt->affected_rows;
                                    $success_count += $bulkAffected;
                                    
                                    error_log("QC Bundle routing: Updated $bulkAffected rows for bundle $bundle_key with destination $roll_destination");
                                    
                                    // Update ALL test tables for all individual roll references in this bundle
                                    $allTestTables = [
                                        'qc_test_orders' => 'sample_reference_id',
                                        'water_permeability_tests' => 'reference_number',
                                        'characteristics_tests' => 'reference_number',
                                        'sun_test_reports' => 'reference_number',
                                        'weathering_exposure_reports' => 'reference'
                                    ];
                                    
                                    foreach ($allTestTables as $testTable => $refColumn) {
                                        // Ensure roll_destination column exists
                                        $checkCol = $conn->query("SHOW COLUMNS FROM $testTable LIKE 'roll_destination'");
                                        if ($checkCol && $checkCol->num_rows === 0) {
                                            @$conn->query("ALTER TABLE $testTable ADD COLUMN roll_destination VARCHAR(255) NULL");
                                        }
                                        
                                        // Update all approved tests for these references
                                        $updateAllTestsStmt = $conn->prepare("
                                            UPDATE $testTable 
                                            SET roll_destination = ?, updated_at = NOW() 
                                            WHERE status = 'approved'
                                            AND $refColumn IN ($placeholders)
                                        ");
                                        $updateParams = array_merge([$roll_destination], $individualRefs);
                                        $updateTypes = str_repeat('s', count($updateParams));
                                        $updateAllTestsStmt->bind_param($updateTypes, ...$updateParams);
                                        $updateAllTestsStmt->execute();
                                        $updateAllTestsStmt->close();
                                    }
                                    
                                    // Get all individual roll references from ALL test tables and populate routed table
                                    $allRefsWithApprovers = [];
                                    
                                    // Query each test table to get references and approvers
                                    foreach ($allTestTables as $testTable => $refColumn) {
                                        // Use correct approver column for each table
                                        $approverCol = ($testTable === 'characteristics_tests') ? 'approver_name' : 'approved_by';
                                        
                                        $getRefsStmt = $conn->prepare("
                                            SELECT DISTINCT $refColumn as ref, 
                                                   COALESCE($approverCol, 'System') as approved_by 
                                            FROM $testTable 
                                            WHERE status = 'approved'
                                            AND $refColumn IN ($placeholders)
                                        ");
                                        $getTypes = str_repeat('s', count($individualRefs));
                                        $getRefsStmt->bind_param($getTypes, ...$individualRefs);
                                        $getRefsStmt->execute();
                                        $refsResult = $getRefsStmt->get_result();
                                        
                                        while ($refRow = $refsResult->fetch_assoc()) {
                                            $ref_num = $refRow['ref'] ?? '';
                                            if (!empty($ref_num)) {
                                                // Store reference with approver, prefer non-System approvers
                                                if (!isset($allRefsWithApprovers[$ref_num]) || 
                                                    ($allRefsWithApprovers[$ref_num] === 'System' && $refRow['approved_by'] !== 'System')) {
                                                    $allRefsWithApprovers[$ref_num] = $refRow['approved_by'];
                                                }
                                            }
                                        }
                                        $getRefsStmt->close();
                                    }
                                    
                                    // Populate routed table for all references
                                    $routed_by = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System';
                                    $bundle_ref = $bundle_key;
                                    
                                    foreach ($allRefsWithApprovers as $ref_number => $rollRoutedBy) {
                                        $finalRoutedBy = ($rollRoutedBy && $rollRoutedBy !== 'System') ? $rollRoutedBy : $routed_by;
                                        
                                        // Ensure bundle_ref is not empty string
                                        $bundle_ref_final = (!empty($bundle_ref) && $bundle_ref !== '') ? $bundle_ref : null;
                                        
                                        error_log("Inserting into routed table: ref=$ref_number, bundle=$bundle_ref_final, dest=$roll_destination");
                                        
                                        $insertRoutedStmt = $conn->prepare("
                                            INSERT INTO routed (reference_number, bundle_reference, roll_destination, routed_by, routed_at)
                                            VALUES (?, ?, ?, ?, NOW())
                                            ON DUPLICATE KEY UPDATE 
                                                bundle_reference = IF(? IS NOT NULL AND ? != '', ?, bundle_reference),
                                                roll_destination = ?,
                                                routed_by = ?,
                                                updated_at = NOW()
                                        ");
                                        $insertRoutedStmt->bind_param("sssssssss", $ref_number, $bundle_ref_final, $roll_destination, $finalRoutedBy, $bundle_ref_final, $bundle_ref_final, $bundle_ref_final, $roll_destination, $finalRoutedBy);
                                        if (!$insertRoutedStmt->execute()) {
                                            error_log("Failed to insert into routed table: " . $insertRoutedStmt->error . " | Reference: $ref_number | Bundle: $bundle_ref_final");
                                        } else {
                                            error_log("Successfully inserted/updated routed table for ref=$ref_number");
                                        }
                                        $insertRoutedStmt->close();
                                    }
                                }
                                $updateBulkStmt->close();
                                
                                // Skip individual update for this report since we've already updated the bundle
                                continue;
                            }
                        }
                    }
                }
            }
            
            // Check if this is a bundle for non-QC test orders (water_permeability, characteristics, sun, uv)
            if (in_array($report_type, ['water_permeability', 'water_perm', 'characteristics', 'sun_test', 'sun', 'uv_test', 'uv'])) {
                // Get bundle_reference to check if this is a bundle
                $checkBundleStmt = $conn->prepare("SELECT bundle_reference FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                $checkBundleStmt->bind_param("s", $report_num);
                $checkBundleStmt->execute();
                $bundleResult = $checkBundleStmt->get_result();
                $bundleRow = $bundleResult->fetch_assoc();
                $checkBundleStmt->close();
                
                if ($bundleRow && !empty($bundleRow['bundle_reference']) && strpos($bundleRow['bundle_reference'], '|') !== false) {
                    $bundleParts = explode('|', $bundleRow['bundle_reference']);
                    if (count($bundleParts) === 2) {
                        $bulk_from = trim($bundleParts[0]);
                        $bulk_to = trim($bundleParts[1]);
                        $bundle_key = $bulk_from . '|' . $bulk_to;
                        
                        // If we haven't processed this bundle yet, update ALL rolls in the bundle
                        if (!in_array($bundle_key, $processed_bundles)) {
                            $processed_bundles[] = $bundle_key;
                            
                            // Extract base reference and roll numbers
                            $fromBaseRef = '';
                            $fromRollNum = 0;
                            $toBaseRef = '';
                            $toRollNum = 0;
                            
                            if (preg_match('/^(.+)-(\d+)$/', $bulk_from, $fromMatches)) {
                                $fromBaseRef = $fromMatches[1];
                                $fromRollNum = (int)$fromMatches[2];
                            } else {
                                $fromBaseRef = $bulk_from;
                            }
                            
                            if (preg_match('/^(.+)-(\d+)$/', $bulk_to, $toMatches)) {
                                $toBaseRef = $toMatches[1];
                                $toRollNum = (int)$toMatches[2];
                            } else {
                                $toBaseRef = $bulk_to;
                            }
                            
                            // Only process if both have same base and valid roll numbers
                            if ($fromBaseRef === $toBaseRef && $fromRollNum > 0 && $toRollNum > 0 && $fromRollNum <= $toRollNum) {
                                // Generate all individual roll references in the bundle range
                                $individualRefs = [];
                                for ($i = $fromRollNum; $i <= $toRollNum; $i++) {
                                    $individualRefs[] = $fromBaseRef . '-' . $i;
                                }
                                
                                // Update ALL test tables for all individual roll references in this bundle
                                $allTestTables = [
                                    'qc_test_orders' => 'sample_reference_id',
                                    'water_permeability_tests' => 'reference_number',
                                    'characteristics_tests' => 'reference_number',
                                    'sun_test_reports' => 'reference_number',
                                    'weathering_exposure_reports' => 'reference'
                                ];
                                
                                $placeholders = str_repeat('?,', count($individualRefs) - 1) . '?';
                                
                                foreach ($allTestTables as $testTable => $refColumn) {
                                    // Ensure roll_destination column exists
                                    $checkCol = $conn->query("SHOW COLUMNS FROM $testTable LIKE 'roll_destination'");
                                    if ($checkCol && $checkCol->num_rows === 0) {
                                        @$conn->query("ALTER TABLE $testTable ADD COLUMN roll_destination VARCHAR(255) NULL");
                                    }
                                    
                                    // Update all approved tests for these references
                                    $updateAllTestsStmt = $conn->prepare("
                                        UPDATE $testTable 
                                        SET roll_destination = ?, updated_at = NOW() 
                                        WHERE status = 'approved'
                                        AND $refColumn IN ($placeholders)
                                    ");
                                    $updateParams = array_merge([$roll_destination], $individualRefs);
                                    $updateTypes = str_repeat('s', count($updateParams));
                                    $updateAllTestsStmt->bind_param($updateTypes, ...$updateParams);
                                    $updateAllTestsStmt->execute();
                                    $updateAllTestsStmt->close();
                                }
                                
                                // Get all individual roll references from ALL test tables and populate routed table
                                $allRefsWithApprovers = [];
                                
                                // Query each test table to get references and approvers
                                foreach ($allTestTables as $testTable => $refColumn) {
                                    // Use correct approver column for each table
                                    $approverCol = ($testTable === 'characteristics_tests') ? 'approver_name' : 'approved_by';
                                    
                                    $getRefsStmt = $conn->prepare("
                                        SELECT DISTINCT $refColumn as ref, 
                                               COALESCE($approverCol, 'System') as approved_by 
                                        FROM $testTable 
                                        WHERE status = 'approved'
                                        AND $refColumn IN ($placeholders)
                                    ");
                                    $getTypes = str_repeat('s', count($individualRefs));
                                    $getRefsStmt->bind_param($getTypes, ...$individualRefs);
                                    $getRefsStmt->execute();
                                    $refsResult = $getRefsStmt->get_result();
                                    
                                    while ($refRow = $refsResult->fetch_assoc()) {
                                        $ref_num = $refRow['ref'] ?? '';
                                        if (!empty($ref_num)) {
                                            // Store reference with approver, prefer non-System approvers
                                            if (!isset($allRefsWithApprovers[$ref_num]) || 
                                                ($allRefsWithApprovers[$ref_num] === 'System' && $refRow['approved_by'] !== 'System')) {
                                                $allRefsWithApprovers[$ref_num] = $refRow['approved_by'];
                                            }
                                        }
                                    }
                                    $getRefsStmt->close();
                                }
                                
                                // Populate routed table for all references
                                $routed_by = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System';
                                $bundle_ref = $bundle_key;
                                
                                foreach ($allRefsWithApprovers as $ref_number => $rollRoutedBy) {
                                    $finalRoutedBy = ($rollRoutedBy && $rollRoutedBy !== 'System') ? $rollRoutedBy : $routed_by;
                                    
                                    // Ensure bundle_ref is not empty string
                                    $bundle_ref_final = (!empty($bundle_ref) && $bundle_ref !== '') ? $bundle_ref : null;
                                    
                                    error_log("Inserting into routed table (non-QC bundle): ref=$ref_number, bundle=$bundle_ref_final, dest=$roll_destination");
                                    
                                    $insertRoutedStmt = $conn->prepare("
                                        INSERT INTO routed (reference_number, bundle_reference, roll_destination, routed_by, routed_at)
                                        VALUES (?, ?, ?, ?, NOW())
                                        ON DUPLICATE KEY UPDATE 
                                            bundle_reference = IF(? IS NOT NULL AND ? != '', ?, bundle_reference),
                                            roll_destination = ?,
                                            routed_by = ?,
                                            updated_at = NOW()
                                    ");
                                    $insertRoutedStmt->bind_param("sssssssss", $ref_number, $bundle_ref_final, $roll_destination, $finalRoutedBy, $bundle_ref_final, $bundle_ref_final, $bundle_ref_final, $roll_destination, $finalRoutedBy);
                                    if (!$insertRoutedStmt->execute()) {
                                        error_log("Failed to insert into routed table: " . $insertRoutedStmt->error . " | Reference: $ref_number | Bundle: $bundle_ref_final");
                                    } else {
                                        error_log("Successfully inserted/updated routed table for ref=$ref_number");
                                    }
                                    $insertRoutedStmt->close();
                                }
                                
                                $success_count++;
                                // Skip individual update for this report since we've already updated the bundle
                                continue;
                            }
                        }
                    }
                }
            }
            
            // Regular update for non-bundle or if bundle processing failed
            if ($report_type === 'qc_test_order') {
                $stmt = $conn->prepare("UPDATE $table SET roll_destination = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status = 'approved'");
                $stmt->bind_param("ss", $roll_destination, $report_num);
            } elseif ($report_type === 'characteristics') {
                $stmt = $conn->prepare("UPDATE $table SET roll_destination = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status = 'approved'");
                $stmt->bind_param("ss", $roll_destination, $report_num);
            } else {
                // Water Permeability, Sun Test, UV Test
                $stmt = $conn->prepare("UPDATE $table SET roll_destination = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status = 'approved'");
                $stmt->bind_param("ss", $roll_destination, $report_num);
            }
            
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $success_count++;
                
                // Get reference number and bundle_reference from the updated record
                $getRefStmt = null;
                if ($report_type === 'qc_test_order') {
                    $getRefStmt = $conn->prepare("SELECT sample_reference_id as ref, test_data, approved_by FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                } elseif ($report_type === 'characteristics') {
                    $getRefStmt = $conn->prepare("SELECT reference_number as ref, bundle_reference, approver_name as approved_by FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                } else {
                    // Water Permeability, Sun Test, UV Test
                    $refCol = ($report_type === 'uv_test' || $report_type === 'uv') ? 'reference' : 'reference_number';
                    $getRefStmt = $conn->prepare("SELECT $refCol as ref, bundle_reference, approved_by FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                }
                
                if ($getRefStmt) {
                    $getRefStmt->bind_param("s", $report_num);
                    $getRefStmt->execute();
                    $refResult = $getRefStmt->get_result();
                    if ($refRow = $refResult->fetch_assoc()) {
                        $ref_number = $refRow['ref'] ?? '';
                        $bundle_ref = $refRow['bundle_reference'] ?? null;
                        $routed_by = $refRow['approved_by'] ?? $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'System';
                        
                        // For QC test orders, extract bundle from test_data JSON
                        if ($report_type === 'qc_test_order' && empty($bundle_ref) && !empty($refRow['test_data'])) {
                            $test_data = json_decode($refRow['test_data'], true);
                            if (isset($test_data['is_bulk_reference']) && $test_data['is_bulk_reference'] &&
                                isset($test_data['bulk_from_reference']) && isset($test_data['bulk_to_reference'])) {
                                $bundle_ref = $test_data['bulk_from_reference'] . '|' . $test_data['bulk_to_reference'];
                            }
                        }
                        
                        // Update ALL test tables for this reference (not just the current table)
                        if (!empty($ref_number)) {
                            $allTestTables = [
                                'qc_test_orders' => 'sample_reference_id',
                                'water_permeability_tests' => 'reference_number',
                                'characteristics_tests' => 'reference_number',
                                'sun_test_reports' => 'reference_number',
                                'weathering_exposure_reports' => 'reference'
                            ];
                            
                            // If bundle reference exists, extract individual references
                            $refsToUpdate = [$ref_number];
                            if (!empty($bundle_ref) && strpos($bundle_ref, '|') !== false) {
                                $bundleParts = explode('|', $bundle_ref);
                                $bundleFrom = trim($bundleParts[0]);
                                $bundleTo = trim($bundleParts[1]);
                                
                                // Extract base and roll numbers
                                if (preg_match('/^(.+)-(\d+)$/', $bundleFrom, $fromMatches) &&
                                    preg_match('/^(.+)-(\d+)$/', $bundleTo, $toMatches)) {
                                    $fromBase = $fromMatches[1];
                                    $fromNum = (int)$fromMatches[2];
                                    $toBase = $toMatches[1];
                                    $toNum = (int)$toMatches[2];
                                    
                                    if ($fromBase === $toBase && $fromNum > 0 && $toNum > 0) {
                                        $refsToUpdate = [];
                                        for ($i = $fromNum; $i <= $toNum; $i++) {
                                            $refsToUpdate[] = $fromBase . '-' . $i;
                                        }
                                    }
                                }
                            }
                            
                            // Update all test tables for all references
                            foreach ($allTestTables as $testTable => $refColumn) {
                                // Ensure roll_destination column exists
                                $checkCol = $conn->query("SHOW COLUMNS FROM $testTable LIKE 'roll_destination'");
                                if ($checkCol && $checkCol->num_rows === 0) {
                                    @$conn->query("ALTER TABLE $testTable ADD COLUMN roll_destination VARCHAR(255) NULL");
                                }
                                
                                // Update all approved tests for these references
                                $placeholders = str_repeat('?,', count($refsToUpdate) - 1) . '?';
                                $updateAllTestsStmt = $conn->prepare("
                                    UPDATE $testTable 
                                    SET roll_destination = ?, updated_at = NOW() 
                                    WHERE status = 'approved'
                                    AND $refColumn IN ($placeholders)
                                ");
                                $updateParams = array_merge([$roll_destination], $refsToUpdate);
                                $updateTypes = str_repeat('s', count($updateParams));
                                $updateAllTestsStmt->bind_param($updateTypes, ...$updateParams);
                                $updateAllTestsStmt->execute();
                                $updateAllTestsStmt->close();
                            }
                            
                            // Insert/update routed table for each reference
                            // Ensure bundle_ref is not empty string
                            $bundle_ref_final = (!empty($bundle_ref) && $bundle_ref !== '') ? $bundle_ref : null;
                            
                            foreach ($refsToUpdate as $refToRoute) {
                                $insertRoutedStmt = $conn->prepare("
                                    INSERT INTO routed (reference_number, bundle_reference, roll_destination, routed_by, routed_at)
                                    VALUES (?, ?, ?, ?, NOW())
                                    ON DUPLICATE KEY UPDATE 
                                        bundle_reference = COALESCE(NULLIF(?, ''), bundle_reference),
                                        roll_destination = ?,
                                        routed_by = ?,
                                        updated_at = NOW()
                                ");
                                $insertRoutedStmt->bind_param("sssssss", $refToRoute, $bundle_ref_final, $roll_destination, $routed_by, $bundle_ref_final, $roll_destination, $routed_by);
                                if (!$insertRoutedStmt->execute()) {
                                    error_log("Failed to insert into routed table: " . $insertRoutedStmt->error . " | Reference: $refToRoute | Bundle: $bundle_ref_final");
                                }
                                $insertRoutedStmt->close();
                            }
                        }
                    }
                    $getRefStmt->close();
                }
            } else {
                $failed_reports[] = $report_num;
            }
            $stmt->close();
        }
        
        // Return success/failure for routing
        if ($success_count > 0) {
            $message = count($report_nums_to_route) > 1 
                ? "Routed {$success_count} report(s) successfully!"
                : "Routed successfully!";
            
            if (!empty($failed_reports)) {
                $message .= " Failed: " . implode(', ', $failed_reports);
            }
            
            echo json_encode([
                'success' => true,
                'message' => $message,
                'report_number' => $report_number,
                'report_type' => $report_type,
                'action' => $action,
                'total_processed' => $success_count
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to route report(s). Reports may not exist or are not approved.']);
        }
        exit;
    }
    
    // Process all reports in the bulk group for approve/reject actions
    // First, verify which report numbers actually exist in the database
    $valid_report_numbers = [];
    $invalid_report_numbers = [];
    
    foreach ($all_report_numbers as $current_report_number) {
        $checkStmt = $conn->prepare("SELECT report_number, status FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
        $checkStmt->bind_param("s", $current_report_number);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        if ($checkResult && $checkResult->num_rows > 0) {
            $valid_report_numbers[] = $current_report_number;
        } else {
            $invalid_report_numbers[] = $current_report_number;
        }
        $checkStmt->close();
    }
    
    // If no valid reports found, return error
    if (empty($valid_report_numbers)) {
        $error_details = !empty($invalid_report_numbers) ? ' All report numbers not found: ' . implode(', ', $invalid_report_numbers) : '';
        echo json_encode(['success' => false, 'message' => 'Failed to ' . $action . ' report(s). No valid reports found.' . $error_details]);
        exit;
    }
    
    // Log invalid report numbers but continue processing valid ones
    if (!empty($invalid_report_numbers)) {
        error_log("Warning: Some report numbers not found in database: " . implode(', ', $invalid_report_numbers));
    }
    
    // For non-QC test orders (water_permeability, characteristics, sun, uv), check for bundle_reference ranges
    // If a report has a bundle_reference with pipe separator (from|to), find all reports with the same bundle_reference
    $reports_to_process = [];
    if (in_array($report_type, ['water_permeability', 'water_perm', 'characteristics', 'sun_test', 'sun', 'uv_test', 'uv'])) {
        // Get bundle_reference from the first valid report
        $first_report_number = $valid_report_numbers[0];
        $getBundleStmt = $conn->prepare("SELECT bundle_reference FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
        $getBundleStmt->bind_param("s", $first_report_number);
        $getBundleStmt->execute();
        $bundleResult = $getBundleStmt->get_result();
        $bundleRow = $bundleResult->fetch_assoc();
        $bundle_reference = $bundleRow['bundle_reference'] ?? null;
        $getBundleStmt->close();
        
        // If bundle_reference exists and contains a pipe (|), it's a range - find all reports with same bundle_reference
        if ($bundle_reference && strpos($bundle_reference, '|') !== false) {
            // Determine status filter based on action
            $status_filter = '';
            if ($action === 'approve') {
                $status_filter = "AND status IN ('checked', 'pending')";
            } else {
                // For rejection, get current status from first report
                $getStatusStmt = $conn->prepare("SELECT status FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                $getStatusStmt->bind_param("s", $first_report_number);
                $getStatusStmt->execute();
                $statusResult = $getStatusStmt->get_result();
                $statusRow = $statusResult->fetch_assoc();
                $current_status = $statusRow['status'] ?? '';
                $getStatusStmt->close();
                
                if (!empty($current_status)) {
                    $status_filter = "AND status = ?";
                }
            }
            
            // Find all reports with the same bundle_reference
            if ($action === 'approve') {
                $findBulkStmt = $conn->prepare("SELECT report_number FROM $table WHERE bundle_reference = ? $status_filter");
                $findBulkStmt->bind_param("s", $bundle_reference);
            } else {
                $findBulkStmt = $conn->prepare("SELECT report_number FROM $table WHERE bundle_reference = ? $status_filter");
                $findBulkStmt->bind_param("ss", $bundle_reference, $current_status);
            }
            
            $findBulkStmt->execute();
            $bulkResult = $findBulkStmt->get_result();
            while ($bulkRow = $bulkResult->fetch_assoc()) {
                $reports_to_process[] = $bulkRow['report_number'];
            }
            $findBulkStmt->close();
            
            error_log("Bulk reference range detected: $bundle_reference. Found " . count($reports_to_process) . " reports to process.");
        } else {
            // No bundle reference range, process only the provided report numbers
            $reports_to_process = $valid_report_numbers;
        }
    } else {
        // For QC test orders, use the valid report numbers (bulk range handling already done above)
        $reports_to_process = $valid_report_numbers;
    }
    
    // If no reports to process, return error
    if (empty($reports_to_process)) {
        echo json_encode(['success' => false, 'message' => 'Failed to ' . $action . ' report(s). No reports found in bulk reference range.']);
        exit;
    }
    
    // Process all reports in the bulk range
    foreach ($reports_to_process as $current_report_number) {
    if ($action === 'approve') {
        // Ensure roll_destination column exists for all tables
        $checkRollDest = $conn->query("SHOW COLUMNS FROM $table LIKE 'roll_destination'");
        if ($checkRollDest && $checkRollDest->num_rows === 0) {
            @$conn->query("ALTER TABLE $table ADD COLUMN roll_destination VARCHAR(255) NULL");
        }
        
        // For QC Test Orders, use different field names and include roll_destination
        if ($report_type === 'qc_test_order') {
            if (!empty($roll_destination)) {
                $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approved_by = ?, approved_at = ?, roll_destination = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status = 'pending_approval'");
                $stmt->bind_param("ssss", $approver, $approved_at, $roll_destination, $current_report_number);
            } else {
                $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status = 'pending_approval'");
                $stmt->bind_param("sss", $approver, $approved_at, $current_report_number);
            }
        } elseif ($report_type === 'characteristics') {
            // Characteristics tests use approver_name instead of approved_by
            // Status should be 'checked' or 'pending' for characteristics tests
            if (!empty($roll_destination)) {
                $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approver_name = ?, approved_at = ?, roll_destination = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status IN ('checked', 'pending')");
                $stmt->bind_param("ssss", $approver, $approved_at, $roll_destination, $current_report_number);
            } else {
                $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approver_name = ?, approved_at = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status IN ('checked', 'pending')");
                $stmt->bind_param("sss", $approver, $approved_at, $current_report_number);
            }
        } else {
            // Water Permeability, Sun Test, UV Test - include roll_destination and approved_at
            // Status should be 'checked' or 'pending' for these tests
            if (!empty($roll_destination)) {
                $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approved_by = ?, approved_at = ?, roll_destination = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status IN ('checked', 'pending')");
                $stmt->bind_param("ssss", $approver, $approved_at, $roll_destination, $current_report_number);
            } else {
                $stmt = $conn->prepare("UPDATE $table SET status = 'approved', approved_by = ?, approved_at = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status IN ('checked', 'pending')");
                $stmt->bind_param("sss", $approver, $approved_at, $current_report_number);
            }
        }
        
        // Execute the approval statement
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $success_count++;
                
                // If roll_destination was set during approval, update ALL test tables and populate routed table
                if (!empty($roll_destination)) {
                    // Get reference number and bundle_reference
                    $getRefStmt = null;
                    if ($report_type === 'qc_test_order') {
                        $getRefStmt = $conn->prepare("SELECT sample_reference_id as ref, test_data, approved_by FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                    } elseif ($report_type === 'characteristics') {
                        $getRefStmt = $conn->prepare("SELECT reference_number as ref, bundle_reference, approver_name as approved_by FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                    } else {
                        $refCol = ($report_type === 'uv_test' || $report_type === 'uv') ? 'reference' : 'reference_number';
                        $getRefStmt = $conn->prepare("SELECT $refCol as ref, bundle_reference, approved_by FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                    }
                    
                    if ($getRefStmt) {
                        $getRefStmt->bind_param("s", $current_report_number);
                        $getRefStmt->execute();
                        $refResult = $getRefStmt->get_result();
                        if ($refRow = $refResult->fetch_assoc()) {
                            $ref_number = $refRow['ref'] ?? '';
                            $bundle_ref = $refRow['bundle_reference'] ?? null;
                            $routed_by = $refRow['approved_by'] ?? $approver;
                            
                            // For QC test orders, extract bundle from test_data JSON
                            if ($report_type === 'qc_test_order' && empty($bundle_ref) && !empty($refRow['test_data'])) {
                                $test_data = json_decode($refRow['test_data'], true);
                                if (isset($test_data['is_bulk_reference']) && $test_data['is_bulk_reference'] &&
                                    isset($test_data['bulk_from_reference']) && isset($test_data['bulk_to_reference'])) {
                                    $bundle_ref = $test_data['bulk_from_reference'] . '|' . $test_data['bulk_to_reference'];
                                }
                            }
                            
                            // Update ALL test tables for this reference
                            if (!empty($ref_number)) {
                                $allTestTables = [
                                    'qc_test_orders' => 'sample_reference_id',
                                    'water_permeability_tests' => 'reference_number',
                                    'characteristics_tests' => 'reference_number',
                                    'sun_test_reports' => 'reference_number',
                                    'weathering_exposure_reports' => 'reference'
                                ];
                                
                                // If bundle reference exists, extract individual references
                                $refsToUpdate = [$ref_number];
                                if (!empty($bundle_ref) && strpos($bundle_ref, '|') !== false) {
                                    $bundleParts = explode('|', $bundle_ref);
                                    $bundleFrom = trim($bundleParts[0]);
                                    $bundleTo = trim($bundleParts[1]);
                                    
                                    // Extract base and roll numbers
                                    if (preg_match('/^(.+)-(\d+)$/', $bundleFrom, $fromMatches) &&
                                        preg_match('/^(.+)-(\d+)$/', $bundleTo, $toMatches)) {
                                        $fromBase = $fromMatches[1];
                                        $fromNum = (int)$fromMatches[2];
                                        $toBase = $toMatches[1];
                                        $toNum = (int)$toMatches[2];
                                        
                                        if ($fromBase === $toBase && $fromNum > 0 && $toNum > 0) {
                                            $refsToUpdate = [];
                                            for ($i = $fromNum; $i <= $toNum; $i++) {
                                                $refsToUpdate[] = $fromBase . '-' . $i;
                                            }
                                        }
                                    }
                                }
                                
                                // Update all test tables for all references
                                foreach ($allTestTables as $testTable => $refColumn) {
                                    // Ensure roll_destination column exists
                                    $checkCol = $conn->query("SHOW COLUMNS FROM $testTable LIKE 'roll_destination'");
                                    if ($checkCol && $checkCol->num_rows === 0) {
                                        @$conn->query("ALTER TABLE $testTable ADD COLUMN roll_destination VARCHAR(255) NULL");
                                    }
                                    
                                    // Update all approved tests for these references
                                    $placeholders = str_repeat('?,', count($refsToUpdate) - 1) . '?';
                                    $updateAllTestsStmt = $conn->prepare("
                                        UPDATE $testTable 
                                        SET roll_destination = ?, updated_at = NOW() 
                                        WHERE status = 'approved'
                                        AND $refColumn IN ($placeholders)
                                    ");
                                    $updateParams = array_merge([$roll_destination], $refsToUpdate);
                                    $updateTypes = str_repeat('s', count($updateParams));
                                    $updateAllTestsStmt->bind_param($updateTypes, ...$updateParams);
                                    $updateAllTestsStmt->execute();
                                    $updateAllTestsStmt->close();
                                }
                                
                                // Insert/update routed table for each reference
                                // Ensure bundle_ref is not empty string
                                $bundle_ref_final = (!empty($bundle_ref) && $bundle_ref !== '') ? $bundle_ref : null;
                                
                                foreach ($refsToUpdate as $refToRoute) {
                                    $insertRoutedStmt = $conn->prepare("
                                        INSERT INTO routed (reference_number, bundle_reference, roll_destination, routed_by, routed_at)
                                        VALUES (?, ?, ?, ?, NOW())
                                        ON DUPLICATE KEY UPDATE 
                                            bundle_reference = IF(? IS NOT NULL AND ? != '', ?, bundle_reference),
                                            roll_destination = ?,
                                            routed_by = ?,
                                            updated_at = NOW()
                                    ");
                                    $insertRoutedStmt->bind_param("sssssssss", $refToRoute, $bundle_ref_final, $roll_destination, $routed_by, $bundle_ref_final, $bundle_ref_final, $bundle_ref_final, $roll_destination, $routed_by);
                                    if (!$insertRoutedStmt->execute()) {
                                        error_log("Failed to insert into routed table (approval): " . $insertRoutedStmt->error . " | Reference: $refToRoute | Bundle: $bundle_ref_final");
                                    }
                                    $insertRoutedStmt->close();
                                }
                            }
                        }
                        $getRefStmt->close();
                    }
                }
            } else {
                // No rows affected - check if report exists and its current status
                $checkStmt = $conn->prepare("SELECT status FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                $checkStmt->bind_param("s", $current_report_number);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                if ($checkRow = $checkResult->fetch_assoc()) {
                    $current_status = $checkRow['status'];
                    if ($current_status === 'approved') {
                        error_log("Approval skipped for report {$current_report_number} (type: {$report_type}): Already approved.");
                        // Don't count as failed if already approved
                        $success_count++;
                    } else {
                        $failed_reports[] = $current_report_number . " (status: {$current_status})";
                        error_log("Approval failed for report {$current_report_number} (type: {$report_type}): Current status is '{$current_status}', expected 'pending_approval' or 'checked'.");
                    }
                } else {
                    $failed_reports[] = $current_report_number . " (not found)";
                    error_log("Approval failed for report {$current_report_number} (type: {$report_type}): Report does not exist.");
                }
                $checkStmt->close();
            }
        } else {
            // Execution failed
            $failed_reports[] = $current_report_number . " (error: " . $stmt->error . ")";
            error_log("Approval failed for report {$current_report_number} (type: {$report_type}): " . $stmt->error);
        }
        $stmt->close();
    } elseif ($action === 'reject') {
        // For QC Test Orders, use admin_remarks instead of remarks
        if ($report_type === 'qc_test_order') {
            $status = 'rejected_by_approver';
            $stmt = $conn->prepare("UPDATE $table SET status = ?, admin_remarks = ?, approved_by = ?, approved_at = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status = 'pending_approval'");
            $stmt->bind_param("sssss", $status, $comments, $approver, $approved_at, $current_report_number);
        } elseif ($report_type === 'characteristics') {
            // Characteristics tests use approver_name instead of approved_by
            // Status should be 'checked' or 'pending' for characteristics tests
            $stmt = $conn->prepare("UPDATE $table SET status = 'rejected', remarks = ?, approver_name = ?, approved_at = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status IN ('checked', 'pending')");
            $stmt->bind_param("ssss", $comments, $approver, $approved_at, $current_report_number);
        } else {
            // Water Permeability, Sun Test, UV Test
            // Status should be 'checked' or 'pending' for these tests
            $stmt = $conn->prepare("UPDATE $table SET status = 'rejected', remarks = ?, approved_by = ?, approved_at = ?, updated_at = NOW() WHERE report_number COLLATE utf8mb4_unicode_ci = ? AND status IN ('checked', 'pending')");
            $stmt->bind_param("ssss", $comments, $approver, $approved_at, $current_report_number);
        }
        
        if ($stmt->execute()) {
            if ($stmt->affected_rows > 0) {
                $success_count++;
            } else {
                // No rows affected - check if report exists and its current status
                $checkStmt = $conn->prepare("SELECT status FROM $table WHERE report_number COLLATE utf8mb4_unicode_ci = ?");
                $checkStmt->bind_param("s", $current_report_number);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                if ($checkRow = $checkResult->fetch_assoc()) {
                    $current_status = $checkRow['status'];
                    if (in_array($current_status, ['rejected', 'rejected_by_approver', 'rejected_by_checker'])) {
                        error_log("Rejection skipped for report {$current_report_number} (type: {$report_type}): Already rejected.");
                        // Don't count as failed if already rejected
                        $success_count++;
                    } else {
                        $failed_reports[] = $current_report_number . " (status: {$current_status})";
                        error_log("Rejection failed for report {$current_report_number} (type: {$report_type}): Current status is '{$current_status}', expected 'pending_approval' or 'checked'.");
                    }
                } else {
                    $failed_reports[] = $current_report_number . " (not found)";
                    error_log("Rejection failed for report {$current_report_number} (type: {$report_type}): Report does not exist.");
                }
                $checkStmt->close();
            }
        } else {
            // Execution failed
            $failed_reports[] = $current_report_number . " (error: " . $stmt->error . ")";
            error_log("Rejection failed for report {$current_report_number} (type: {$report_type}): " . $stmt->error);
        }
        $stmt->close();
    }
    } // Close foreach loop
    
    if ($success_count > 0) {
        $message = count($all_report_numbers) > 1 
            ? ucfirst($action) . "d {$success_count} report(s) successfully!"
            : ucfirst($action) . "d successfully! Report: " . $report_number;
        
        if (!empty($failed_reports)) {
            $message .= " Failed: " . implode(', ', $failed_reports);
        }
        
        echo json_encode([
            'success' => true,
            'message' => $message,
            'report_number' => $report_number,
            'report_type' => $report_type,
            'action' => $action,
            'total_processed' => $success_count
        ]);
    } else {
        $error_details = !empty($failed_reports) ? ' Details: ' . implode(', ', $failed_reports) : '';
        echo json_encode(['success' => false, 'message' => 'Failed to ' . $action . ' report(s). Reports may not exist or already processed.' . $error_details]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>


