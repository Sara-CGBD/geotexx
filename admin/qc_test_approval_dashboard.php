<?php
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
$is_admin = in_array($user_role, ['admin', 'agm', 'agm ops', 'agm operations']);

if (!$is_admin) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access this page.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$message = '';
$error = '';

// Helper to check column existence (table name sanitized, column bound)
function columnExists(mysqli $conn, string $table, string $column): bool {
    // Allow only safe table names
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    $col = $conn->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$table}` LIKE '{$col}'";
    $result = $conn->query($sql);
    return $result && $result->num_rows > 0;
}

$statusExists = columnExists($conn, 'qc_test_orders', 'status');
$updatedAtExists = columnExists($conn, 'qc_test_orders', 'updated_at');
$createdAtExists = columnExists($conn, 'qc_test_orders', 'created_at');
$approvedAtExists = columnExists($conn, 'qc_test_orders', 'approved_at');
$rollDestinationExists = columnExists($conn, 'qc_test_orders', 'roll_destination');
if (!$rollDestinationExists) {
    @$conn->query("ALTER TABLE qc_test_orders ADD COLUMN roll_destination VARCHAR(255) NULL");
    $rollDestinationExists = columnExists($conn, 'qc_test_orders', 'roll_destination');
}

// Ensure roll_destination column exists for all test tables
$tables_to_check = ['water_permeability_tests', 'characteristics_tests', 'sun_test_reports', 'weathering_exposure_reports'];
foreach ($tables_to_check as $table) {
    if (!columnExists($conn, $table, 'roll_destination')) {
        @$conn->query("ALTER TABLE $table ADD COLUMN roll_destination VARCHAR(255) NULL");
    }
}

$orderField = $updatedAtExists ? 'qto.updated_at' : ($createdAtExists ? 'qto.created_at' : 'qto.id');

if (isset($_GET['success'])) {
    $message = $_GET['success'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

// Get pending QC test orders for approval - Production Products (grouped by bulk reference)
// EXCLUDE external tests - they should only show in AGM External Test Dashboard
$production_tests = [];
$statusFilter = $statusExists ? "WHERE qto.status = 'pending_approval'" : "WHERE 1=1";
$orderByClause = "ORDER BY qto.sample_reference_id ASC, {$orderField} DESC";
$stmt = $conn->query("
    SELECT qto.*, ts.test_name, ts.standard_code, 'qc_test_order' as test_type
    FROM qc_test_orders qto
    LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
    {$statusFilter}
    {$orderByClause}
    LIMIT 200
");
if ($stmt) {
    while ($row = $stmt->fetch_assoc()) {
        // EXCLUDE external tests - they belong in AGM External Test Dashboard
        $test_data = json_decode($row['test_data'] ?? '{}', true);
        $sample_ref = trim($row['sample_reference_id'] ?? '');
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
        
        $production_tests[] = $row;
    }
}

// Get pending Water Permeability Tests (status = 'checked' or 'pending')
// Join with roll_entry to get full reference if stored reference is incomplete
// Check if roll_destination column exists
$wpt_roll_dest_exists = columnExists($conn, 'water_permeability_tests', 'roll_destination');
$wpt_roll_dest_select = $wpt_roll_dest_exists ? 'wpt.roll_destination,' : 'NULL as roll_destination,';

$wptQuery = $conn->query("
    SELECT 
        wpt.id,
        wpt.report_number as report_number,
        wpt.lab_test_number,
        CASE 
            WHEN LENGTH(TRIM(wpt.reference_number)) <= 3 THEN 
                COALESCE(re.reference_number, wpt.reference_number)
            ELSE wpt.reference_number
        END as sample_reference_id,
        wpt.bundle_reference,
        wpt.test_date,
        wpt.test_performed_by as inspector_name,
        wpt.checked_by,
        wpt.status,
        $wpt_roll_dest_select
        wpt.created_at,
        wpt.updated_at,
        'Water Permeability Test' as test_name,
        '' as chosen_method,
        'water_permeability' as test_type,
        NULL as test_data
    FROM water_permeability_tests wpt
    LEFT JOIN roll_entry re ON (
        re.reference_number = wpt.reference_number 
        OR re.reference_number LIKE CONCAT(wpt.reference_number, '-%')
        OR wpt.reference_number LIKE CONCAT(re.reference_number, '-%')
        OR (LENGTH(TRIM(wpt.reference_number)) <= 3 AND re.reference_number LIKE CONCAT('%', wpt.reference_number, '%'))
    )
    WHERE wpt.status IN ('pending', 'checked')
    GROUP BY wpt.id
    ORDER BY sample_reference_id ASC, wpt.updated_at DESC
    LIMIT 200
");
if ($wptQuery) {
    while ($row = $wptQuery->fetch_assoc()) {
        // For water permeability tests, prioritize bundle_reference if available and reference_number is too short
        if (!empty($row['sample_reference_id']) && strlen(trim($row['sample_reference_id'])) <= 3) {
            if (!empty($row['bundle_reference'])) {
                $row['sample_reference_id'] = $row['bundle_reference'];
            }
        }
        $production_tests[] = $row;
    }
}

// Get pending Characteristics Tests (status = 'checked' or 'pending')
$char_roll_dest_exists = columnExists($conn, 'characteristics_tests', 'roll_destination');
$char_roll_dest_select = $char_roll_dest_exists ? 'roll_destination,' : 'NULL as roll_destination,';

$charQuery = $conn->query("
    SELECT 
        id,
        report_number as report_number,
        lab_test_number,
        reference_number as sample_reference_id,
        bundle_reference,
        sample_tested as test_date,
        test_performed_by as inspector_name,
        checker_name as checked_by,
        status,
        $char_roll_dest_select
        created_at,
        updated_at,
        'Characteristics Test' as test_name,
        '' as chosen_method,
        'characteristics' as test_type,
        NULL as test_data
    FROM characteristics_tests
    WHERE status IN ('pending', 'checked')
    ORDER BY reference_number ASC, updated_at DESC
    LIMIT 200
");
if ($charQuery) {
    while ($row = $charQuery->fetch_assoc()) {
        $production_tests[] = $row;
    }
}

// Get pending Sun Test Reports (status = 'pending' - goes directly to AGM)
// Note: Sun test uses 'reference_name' column, not 'reference_number'
$sun_roll_dest_exists = columnExists($conn, 'sun_test_reports', 'roll_destination');
$sun_roll_dest_select = $sun_roll_dest_exists ? 'roll_destination,' : 'NULL as roll_destination,';

$sunQuery = $conn->query("
    SELECT 
        id,
        report_number as report_number,
        lab_test_number,
        reference_name as sample_reference_id,
        test_start_date as test_date,
        test_performed_by as inspector_name,
        '' as checked_by,
        status,
        $sun_roll_dest_select
        created_at,
        updated_at,
        'Sun Test' as test_name,
        '' as chosen_method,
        'sun_test' as test_type,
        NULL as test_data
    FROM sun_test_reports
    WHERE status = 'pending'
    ORDER BY reference_name ASC, updated_at DESC
    LIMIT 200
");
if ($sunQuery) {
    while ($row = $sunQuery->fetch_assoc()) {
        $production_tests[] = $row;
    }
}

// Get pending UV/Weathering Exposure Tests (status = 'pending' - goes directly to AGM)
$uv_roll_dest_exists = columnExists($conn, 'weathering_exposure_reports', 'roll_destination');
$uv_roll_dest_select = $uv_roll_dest_exists ? 'roll_destination,' : 'NULL as roll_destination,';

$uvQuery = $conn->query("
    SELECT 
        id,
        report_number as report_number,
        '' as lab_test_number,
        reference as sample_reference_id,
        test_start_date as test_date,
        test_performed_by as inspector_name,
        '' as checked_by,
        status,
        $uv_roll_dest_select
        created_at,
        updated_at,
        'UV Test (Weathering Exposure)' as test_name,
        '' as chosen_method,
        'uv_test' as test_type,
        NULL as test_data
    FROM weathering_exposure_reports
    WHERE status = 'pending'
    ORDER BY reference ASC, updated_at DESC
    LIMIT 200
");
if ($uvQuery) {
    while ($row = $uvQuery->fetch_assoc()) {
        $production_tests[] = $row;
    }
}

// Group reports by bulk reference (similar to checker dashboard logic)
$grouped_bulk_reports = [];
$grouped_individual_reports = [];

$processed_indices = []; // Track which reports have been processed

// First pass: Process reports with bundle_reference (pipe-separated range format: from|to)
// This applies to water_permeability, characteristics, sun, and UV tests
foreach ($production_tests as $idx => $report) {
    $bundle_ref = trim($report['bundle_reference'] ?? '');
    
    // Check if bundle_reference exists and contains a pipe separator (range format)
    if (!empty($bundle_ref) && strpos($bundle_ref, '|') !== false) {
        $parts = explode('|', $bundle_ref);
        if (count($parts) === 2) {
            $from_ref = trim($parts[0]);
            $to_ref = trim($parts[1]);
            
            // If from and to are the same, treat as individual reference
            if ($from_ref === $to_ref) {
                $refId = $from_ref;
                if (!isset($grouped_individual_reports[$refId])) {
                    $grouped_individual_reports[$refId] = [];
                }
                $grouped_individual_reports[$refId][] = $report;
                $processed_indices[] = $idx;
            } else {
                $bulk_key = $from_ref . '|' . $to_ref;
                
                if (!isset($grouped_bulk_reports[$bulk_key])) {
                    $grouped_bulk_reports[$bulk_key] = [
                        'from' => $from_ref,
                        'to' => $to_ref,
                        'count' => 0,
                        'reports' => []
                    ];
                }
                $grouped_bulk_reports[$bulk_key]['reports'][] = $report;
                $processed_indices[] = $idx;
            }
        }
    }
}

// Second pass: Process reports with explicit bulk reference metadata (for QC test orders)
foreach ($production_tests as $idx => $report) {
    $test_data = json_decode($report['test_data'] ?? '{}', true);
    
    // Also check if test_data might be a string that needs decoding
    if (is_string($test_data)) {
        $test_data = json_decode($test_data, true) ?? [];
    }
    
    if (isset($test_data['is_bulk_reference']) && $test_data['is_bulk_reference'] && 
        isset($test_data['bulk_from_reference']) && isset($test_data['bulk_to_reference'])) {
        $from_ref = trim($test_data['bulk_from_reference']);
        $to_ref = trim($test_data['bulk_to_reference']);
        
        // If from and to are the same, treat as individual reference, not bulk
        if ($from_ref === $to_ref) {
            $refId = $from_ref;
            if (!isset($grouped_individual_reports[$refId])) {
                $grouped_individual_reports[$refId] = [];
            }
            $grouped_individual_reports[$refId][] = $report;
            $processed_indices[] = $idx;
        } else {
            $bulk_key = $from_ref . '|' . $to_ref;
            
            if (!isset($grouped_bulk_reports[$bulk_key])) {
                $grouped_bulk_reports[$bulk_key] = [
                    'from' => $from_ref,
                    'to' => $to_ref,
                    'count' => 0, // Will be calculated from actual reports, not metadata
                    'reports' => []
                ];
            }
            $grouped_bulk_reports[$bulk_key]['reports'][] = $report;
            $processed_indices[] = $idx;
        }
    }
}

// Third pass: Match reports to existing bulk groups by bundle_reference
// For reports that have bundle_reference matching an existing bulk group
foreach ($production_tests as $idx => $report) {
    if (in_array($idx, $processed_indices)) {
        continue; // Already processed
    }
    
    $bundle_ref = trim($report['bundle_reference'] ?? '');
    if (empty($bundle_ref)) {
        continue;
    }
    
    // Check if this bundle_reference matches any existing bulk group
    foreach ($grouped_bulk_reports as $bulk_key => $bulk_group) {
        $expected_bundle = $bulk_group['from'] . '|' . $bulk_group['to'];
        if ($bundle_ref === $expected_bundle) {
            $grouped_bulk_reports[$bulk_key]['reports'][] = $report;
            $processed_indices[] = $idx;
            break; // Found a match, move to next report
        }
    }
}

// Fourth pass: Check for reports that might belong to existing bulk groups
// by matching their sample_reference_id to the bulk reference range
foreach ($production_tests as $idx => $report) {
    if (in_array($idx, $processed_indices)) {
        continue; // Already processed
    }
    
    $sample_ref = trim($report['sample_reference_id'] ?? '');
    if (empty($sample_ref)) {
        continue;
    }
    
    // Check if this reference falls within any existing bulk reference range
    foreach ($grouped_bulk_reports as $bulk_key => $bulk_group) {
        $from_ref = $bulk_group['from'];
        $to_ref = $bulk_group['to'];
        
        // Extract base reference and roll number from sample_ref
        // Pattern: e.g., "4.0L226JAN05-R01-H0.1-1" where -1 is the roll number
        if (preg_match('/^(.+)-(\d+)$/', $sample_ref, $matches) && 
            preg_match('/^(.+)-(\d+)$/', $from_ref, $from_matches) &&
            preg_match('/^(.+)-(\d+)$/', $to_ref, $to_matches)) {
            
            $base_ref = $matches[1];
            $roll_num = (int)$matches[2];
            $from_base = $from_matches[1];
            $from_num = (int)$from_matches[2];
            $to_base = $to_matches[1];
            $to_num = (int)$to_matches[2];
            
            // If base references match and roll number is within range, add to bulk group
            if ($base_ref === $from_base && $base_ref === $to_base && 
                $roll_num >= $from_num && $roll_num <= $to_num) {
                $grouped_bulk_reports[$bulk_key]['reports'][] = $report;
                $processed_indices[] = $idx;
                
                break; // Found a match, move to next report
            }
        }
    }
}

// Fourth pass: Detect sequential references that form bulk groups even without explicit flag
// Group remaining unprocessed reports by base reference pattern
$base_reference_groups = [];
foreach ($production_tests as $idx => $report) {
    if (in_array($idx, $processed_indices)) {
        continue; // Already processed
    }
    
    $sample_ref = trim($report['sample_reference_id'] ?? '');
    if (empty($sample_ref)) {
        continue;
    }
    
    // Extract base reference (everything before the last dash and number)
    if (preg_match('/^(.+)-(\d+)$/', $sample_ref, $matches)) {
        $base_ref = $matches[1];
        $roll_num = (int)$matches[2];
        
        if (!isset($base_reference_groups[$base_ref])) {
            $base_reference_groups[$base_ref] = [
                'base' => $base_ref,
                'references' => [],
                'reports' => [],
                'indices' => []
            ];
        }
        $base_reference_groups[$base_ref]['references'][$roll_num] = $sample_ref;
        $base_reference_groups[$base_ref]['reports'][] = $report;
        $base_reference_groups[$base_ref]['indices'][] = $idx;
    }
}

// Check if base reference groups have sequential references (likely bulk)
foreach ($base_reference_groups as $base_ref => $group) {
    $refs = $group['references'];
    ksort($refs); // Sort by roll number
    $roll_nums = array_keys($refs);
    
    // If we have 2+ sequential references, treat as bulk
    // BUT only if from and to are different (not the same reference)
    if (count($roll_nums) >= 2) {
        $min_roll = min($roll_nums);
        $max_roll = max($roll_nums);
        $from_ref = $base_ref . '-' . $min_roll;
        $to_ref = $base_ref . '-' . $max_roll;
        
        // If from and to are the same, treat as individual references
        if ($from_ref === $to_ref) {
            // Add each report as individual
            foreach ($group['reports'] as $report) {
                // For water permeability tests, check bundle_reference if sample_reference_id is missing or too short
                $refId = $report['sample_reference_id'] ?? '';
                if (empty($refId) || (strlen(trim($refId)) <= 3 && isset($report['bundle_reference']) && !empty($report['bundle_reference']))) {
                    $refId = $report['bundle_reference'] ?? 'Unknown';
                }
                if (empty($refId)) {
                    $refId = 'Unknown';
                }
                if (!isset($grouped_individual_reports[$refId])) {
                    $grouped_individual_reports[$refId] = [];
                }
                $grouped_individual_reports[$refId][] = $report;
            }
            $processed_indices = array_merge($processed_indices, $group['indices']);
        } else {
            $bulk_key = $from_ref . '|' . $to_ref;
            
            // Check if this bulk group already exists
            if (!isset($grouped_bulk_reports[$bulk_key])) {
                $grouped_bulk_reports[$bulk_key] = [
                    'from' => $from_ref,
                    'to' => $to_ref,
                    'count' => count($refs),
                    'reports' => $group['reports']
                ];
                // Mark all these reports as processed
                $processed_indices = array_merge($processed_indices, $group['indices']);
            } else {
                // Merge reports if group already exists
                $grouped_bulk_reports[$bulk_key]['reports'] = array_merge(
                    $grouped_bulk_reports[$bulk_key]['reports'],
                    $group['reports']
                );
                // Count will be calculated from actual reports, not metadata
                // Mark all these reports as processed
                $processed_indices = array_merge($processed_indices, $group['indices']);
            }
        }
    }
}

// Fifth pass: Remaining reports are individual
foreach ($production_tests as $idx => $report) {
    if (in_array($idx, $processed_indices)) {
        continue; // Already processed
    }
    
    // Individual report - group by reference
    // For water permeability tests, check bundle_reference if sample_reference_id is missing or too short
    $refId = $report['sample_reference_id'] ?? '';
    if (empty($refId) || (strlen(trim($refId)) <= 3 && isset($report['bundle_reference']) && !empty($report['bundle_reference']))) {
        $refId = $report['bundle_reference'] ?? 'Unknown';
    }
    if (empty($refId)) {
        $refId = 'Unknown';
    }
    if (!isset($grouped_individual_reports[$refId])) {
        $grouped_individual_reports[$refId] = [];
    }
    $grouped_individual_reports[$refId][] = $report;
}

// Group tests within each bulk reference by test_name and chosen_method
// Normalize test names and methods to handle whitespace/case differences
// Filter out bulk groups where from and to are the same (treat as individual)
$final_grouped_bulk = [];
foreach ($grouped_bulk_reports as $bulk_key => $bulk_group) {
    // Skip bulk groups where from and to are the same - these should be individual
    if ($bulk_group['from'] === $bulk_group['to']) {
        // Move to individual reports instead
        $refId = $bulk_group['from'];
        if (!isset($grouped_individual_reports[$refId])) {
            $grouped_individual_reports[$refId] = [];
        }
        $grouped_individual_reports[$refId] = array_merge(
            $grouped_individual_reports[$refId],
            $bulk_group['reports']
        );
        continue; // Skip this bulk group
    }
    $test_groups = [];
    foreach ($bulk_group['reports'] as $report) {
        // Normalize test_name and chosen_method (trim whitespace, handle nulls)
        $test_name = trim($report['test_name'] ?? '');
        $chosen_method = trim($report['chosen_method'] ?? '');
        
        
        $test_key = $test_name . '|' . $chosen_method;
        
        if (!isset($test_groups[$test_key])) {
            $test_groups[$test_key] = [
                'test_name' => $test_name,
                'chosen_method' => $chosen_method,
                'reports' => []
            ];
        }
        $test_groups[$test_key]['reports'][] = $report;
    }
    
    
    // Calculate actual reference count from the reports we have
    // Extract unique sample_reference_id values from all reports
    $actual_references = [];
    foreach ($bulk_group['reports'] as $report) {
        $sample_ref = trim($report['sample_reference_id'] ?? '');
        if (!empty($sample_ref) && !in_array($sample_ref, $actual_references)) {
            $actual_references[] = $sample_ref;
        }
    }
    $actual_count = count($actual_references);
    
    // Use the actual count if it's different from metadata count
    // This handles cases where metadata says 4 but only 2 reports exist
    $display_count = ($actual_count > 0) ? $actual_count : $bulk_group['count'];
    
    $final_grouped_bulk[$bulk_key] = [
        'from' => $bulk_group['from'],
        'to' => $bulk_group['to'],
        'count' => $display_count, // Use actual count of reports, not metadata
        'test_groups' => $test_groups
    ];
}

$ready_for_routing = [];

// Check if is_deleted columns exist in roll_received and roll_transfer tables
$rr_has_is_deleted = columnExists($conn, 'roll_received', 'is_deleted');
$rt_has_is_deleted = columnExists($conn, 'roll_transfer', 'is_deleted');
$rr_is_deleted_cond = $rr_has_is_deleted ? "AND (rr.is_deleted = 0 OR rr.is_deleted IS NULL)" : "";
$rt_is_deleted_cond = $rt_has_is_deleted ? "AND (rt.is_deleted = 0 OR rt.is_deleted IS NULL)" : "";

// Get all approved tests with routing (from all test types)
// Exclude tests that were routed through the separate routing handler (those have "Routing:" in admin_remarks)
// Also exclude references that exist in roll_received or roll_transfer (already routed/received)
// Show recently approved tests (last 30 days) or all if approved_at is NULL
// 1. QC Test Orders with routing
// Ensure roll_destination column exists
$qc_roll_dest_check = columnExists($conn, 'qc_test_orders', 'roll_destination');
if (!$qc_roll_dest_check) {
    @$conn->query("ALTER TABLE qc_test_orders ADD COLUMN roll_destination VARCHAR(255) NULL");
}
// Check if approved_at column exists
// Remove date filter - show ALL approved tests regardless of approval date
// This ensures all approved tests appear in routing section immediately
$qc_approved_at_condition = "";
$qc_routing_query = "
    SELECT qto.report_number, 
           CASE 
               WHEN LENGTH(TRIM(qto.sample_reference_id)) <= 3 OR qto.sample_reference_id IS NULL OR qto.sample_reference_id = '' THEN 
                   COALESCE(re.reference_number, qto.sample_reference_id)
               ELSE qto.sample_reference_id
           END as sample_reference_id, 
           qto.sample_reference_id as original_sample_reference_id,
           COALESCE(qto.roll_destination, '') as roll_destination, qto.approved_at, qto.test_data,
           ts.test_name, 'qc_test_order' as test_type
    FROM qc_test_orders qto
    LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
    LEFT JOIN roll_entry re ON (
        re.reference_number = qto.sample_reference_id
        OR (qto.sample_reference_id LIKE CONCAT(re.reference_number, '-%') AND LENGTH(re.reference_number) < LENGTH(qto.sample_reference_id))
    )
    LEFT JOIN roll_received rr ON (
        (rr.reference_number = qto.sample_reference_id)
        " . ($rr_has_is_deleted ? "AND (rr.is_deleted = 0 OR rr.is_deleted IS NULL)" : "") . "
    )
    LEFT JOIN roll_transfer rt ON (
        (rt.reference_number = qto.sample_reference_id)
        " . ($rt_has_is_deleted ? "AND (rt.is_deleted = 0 OR rt.is_deleted IS NULL)" : "") . "
    )
    WHERE qto.status = 'approved'
      AND (qto.sample_reference_id IS NOT NULL AND qto.sample_reference_id != '' 
           OR (qto.test_data IS NOT NULL AND qto.test_data != '' AND qto.test_data != '{}'))
      {$qc_approved_at_condition}
      AND rr.id IS NULL  -- Not in roll_received (exact match only - not routed/received, or soft-deleted)
      AND rt.id IS NULL  -- Not in roll_transfer (exact match only - not transferred, or soft-deleted)
    ORDER BY COALESCE(qto.approved_at, qto.updated_at, qto.created_at) DESC
    LIMIT 200
";
$qc_routing_res = $conn->query($qc_routing_query);
if (!$qc_routing_res) {
    error_log("QC routing query failed: " . $conn->error . " | Query: " . $qc_routing_query);
} else {
    // Reset result set pointer for processing
    $qc_routing_res->data_seek(0);
    
    $qc_count = 0;
    $qc_total_rows = $qc_routing_res->num_rows;
    $row_num = 0;
    while ($r = $qc_routing_res->fetch_assoc()) {
        $row_num++;
        
        $sample_ref = trim($r['sample_reference_id'] ?? '');
        $original_ref = $sample_ref; // Keep original for fallback
        
        
        // Parse test_data first to check for bulk references
        $test_data = json_decode($r['test_data'] ?? '{}', true);
        if (is_string($test_data)) {
            $test_data = json_decode($test_data, true) ?? [];
        }
        
        // ALWAYS check for bulk references in test_data first (for bulk reference ranges)
        // This ensures bulk reference ranges are properly extracted
        if (isset($test_data['is_bulk_reference']) && $test_data['is_bulk_reference'] && 
            isset($test_data['bulk_from_reference']) && !empty(trim($test_data['bulk_from_reference']))) {
            // For bulk references, use from_reference as the display reference
            $sample_ref = trim($test_data['bulk_from_reference']);
            $r['sample_reference_id'] = $sample_ref; // Update the resolved reference
            $r['is_bulk_reference'] = true; // Mark as bulk reference for later processing
            $r['bulk_from_reference'] = trim($test_data['bulk_from_reference']);
            $r['bulk_to_reference'] = isset($test_data['bulk_to_reference']) ? trim($test_data['bulk_to_reference']) : '';
        } elseif (strlen($sample_ref) <= 3 || empty($sample_ref)) {
            // If reference is too short or empty, try to get from test_data bulk references
            if (isset($test_data['bulk_from_reference']) && !empty(trim($test_data['bulk_from_reference']))) {
                $sample_ref = trim($test_data['bulk_from_reference']);
                $r['sample_reference_id'] = $sample_ref; // Update the resolved reference
            } elseif (isset($test_data['bulk_to_reference']) && !empty(trim($test_data['bulk_to_reference']))) {
                $sample_ref = trim($test_data['bulk_to_reference']);
                $r['sample_reference_id'] = $sample_ref; // Update the resolved reference
            } elseif (isset($test_data['product_reference']) && !empty(trim($test_data['product_reference']))) {
                // Also check product_reference
                $sample_ref = trim($test_data['product_reference']);
                $r['sample_reference_id'] = $sample_ref;
            }
        }
        
        // If still short, try to query roll_entry with the original reference to find a match
        // Only do this if the reference is very short (1-3 chars) to avoid too many queries
        if (strlen($sample_ref) <= 3 && strlen($original_ref) <= 3 && !empty($original_ref) && strlen($original_ref) > 0) {
            try {
                $ref_lookup = $conn->prepare("SELECT reference_number FROM roll_entry WHERE reference_number LIKE ? OR reference_number LIKE ? ORDER BY LENGTH(reference_number) DESC LIMIT 1");
                if ($ref_lookup) {
                    $pattern1 = '%' . $conn->real_escape_string($original_ref) . '%';
                    $pattern2 = $conn->real_escape_string($original_ref) . '-%';
                    $ref_lookup->bind_param("ss", $pattern1, $pattern2);
                    if ($ref_lookup->execute()) {
                        $ref_result = $ref_lookup->get_result();
                        if ($ref_row = $ref_result->fetch_assoc()) {
                            $resolved_ref = trim($ref_row['reference_number']);
                            if (strlen($resolved_ref) > 3) {
                                $sample_ref = $resolved_ref;
                                $r['sample_reference_id'] = $sample_ref;
                            }
                        }
                    }
                    $ref_lookup->close();
                }
            } catch (Exception $e) {
                // If lookup fails, just use the original reference
                error_log("Reference lookup failed: " . $e->getMessage());
            }
        }
        
        if ($sample_ref === '') {
            continue; // Skip only if completely empty
        }
        
        // Check if external
            $is_external = false;
            if (!empty($sample_ref) && (stripos($sample_ref, 'EXT-') === 0 || stripos($sample_ref, 'TOKEN-') === 0)) {
                $is_external = true;
        } elseif (isset($test_data['is_external_product']) && ($test_data['is_external_product'] === true || $test_data['is_external_product'] === '1')) {
                    $is_external = true;
        }
        if (!$is_external) {
            $ready_for_routing[] = $r;
            $qc_count++;
        } else {
        }
    }
}

// 2. Water Permeability Tests with routing (approved but not routed)
$wpt_roll_dest_exists_check = columnExists($conn, 'water_permeability_tests', 'roll_destination');
// Create roll_destination column if it doesn't exist
if (!$wpt_roll_dest_exists_check) {
    @$conn->query("ALTER TABLE water_permeability_tests ADD COLUMN roll_destination VARCHAR(255) NULL");
    $wpt_roll_dest_exists_check = true;
}
// Check if approved_at column exists
$wpt_approved_at_exists = columnExists($conn, 'water_permeability_tests', 'approved_at');
$wpt_approved_at_condition = $wpt_approved_at_exists 
    ? "AND (wpt.approved_at IS NULL OR wpt.approved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))"
    : "AND 1=1"; // Always true if column doesn't exist
if ($wpt_roll_dest_exists_check) {
    $wpt_routing_query = "
        SELECT wpt.report_number, 
               CASE 
                   WHEN LENGTH(TRIM(wpt.reference_number)) <= 3 AND LENGTH(TRIM(COALESCE(wpt.bundle_reference, ''))) > 3 THEN 
                       wpt.bundle_reference
                   WHEN LENGTH(TRIM(wpt.reference_number)) <= 3 THEN 
                       COALESCE(re.reference_number, wpt.bundle_reference, wpt.reference_number)
                   ELSE wpt.reference_number
               END as sample_reference_id, 
               wpt.bundle_reference,
               COALESCE(wpt.roll_destination, '') as roll_destination, wpt.approved_at,
               'Water Permeability Test' as test_name, 'water_permeability' as test_type,
               NULL as test_data
        FROM water_permeability_tests wpt
        LEFT JOIN roll_entry re ON (
            (wpt.reference_number IS NOT NULL AND wpt.reference_number != '' AND (
                re.reference_number = wpt.reference_number 
                OR re.reference_number LIKE CONCAT(wpt.reference_number, '-%')
                OR wpt.reference_number LIKE CONCAT(re.reference_number, '-%')
                OR (LENGTH(TRIM(wpt.reference_number)) <= 3 AND re.reference_number LIKE CONCAT('%', wpt.reference_number, '%'))
            ))
            OR (wpt.bundle_reference IS NOT NULL AND wpt.bundle_reference != '' AND (
                re.reference_number = wpt.bundle_reference
                OR re.reference_number LIKE CONCAT(wpt.bundle_reference, '-%')
                OR wpt.bundle_reference LIKE CONCAT(re.reference_number, '-%')
            ))
        )
        LEFT JOIN roll_received rr ON (
            (rr.reference_number = COALESCE(re.reference_number, wpt.reference_number)
            OR rr.reference_number LIKE CONCAT(COALESCE(re.reference_number, wpt.reference_number), '-%')
            OR COALESCE(re.reference_number, wpt.reference_number) LIKE CONCAT(rr.reference_number, '-%'))
            " . ($rr_has_is_deleted ? "AND (rr.is_deleted = 0 OR rr.is_deleted IS NULL)" : "") . "
        )
        LEFT JOIN roll_transfer rt ON (
            (rt.reference_number = COALESCE(re.reference_number, wpt.reference_number)
            OR rt.reference_number LIKE CONCAT(COALESCE(re.reference_number, wpt.reference_number), '-%')
            OR COALESCE(re.reference_number, wpt.reference_number) LIKE CONCAT(rt.reference_number, '-%'))
            " . ($rt_has_is_deleted ? "AND (rt.is_deleted = 0 OR rt.is_deleted IS NULL)" : "") . "
        )
        WHERE wpt.status = 'approved'
          {$wpt_approved_at_condition}
          AND rr.id IS NULL  -- Not in roll_received (not routed/received)
          AND rt.id IS NULL  -- Not in roll_transfer (not transferred)
        GROUP BY wpt.id
        ORDER BY wpt.approved_at DESC
        LIMIT 200
    ";
    $wpt_routing_res = $conn->query($wpt_routing_query);
    if (!$wpt_routing_res) {
        error_log("Water Permeability routing query failed: " . $conn->error);
    }
    if ($wpt_routing_res) {
        while ($r = $wpt_routing_res->fetch_assoc()) {
            $sample_ref = trim($r['sample_reference_id'] ?? '');
            $original_ref = trim($r['sample_reference_id'] ?? '');
            
            // If reference is still too short, use bundle_reference if available
            if (strlen($sample_ref) <= 3 && !empty($r['bundle_reference']) && strlen(trim($r['bundle_reference'])) > 3) {
                $sample_ref = trim($r['bundle_reference']);
                $r['sample_reference_id'] = $sample_ref; // Update the resolved reference
            }
            
            // If still short, try to find a matching reference from roll_entry
            // Only do this if the reference is very short to avoid too many queries
            if (strlen($sample_ref) <= 3 && !empty($sample_ref)) {
                try {
                    $ref_search = $conn->prepare("SELECT reference_number FROM roll_entry WHERE reference_number LIKE ? OR reference_number LIKE ? ORDER BY LENGTH(reference_number) DESC LIMIT 1");
                    if ($ref_search) {
                        $pattern1 = '%' . $conn->real_escape_string($sample_ref) . '%';
                        $pattern2 = $conn->real_escape_string($sample_ref) . '-%';
                        $ref_search->bind_param("ss", $pattern1, $pattern2);
                        if ($ref_search->execute()) {
                            $ref_search_result = $ref_search->get_result();
                            if ($ref_search_row = $ref_search_result->fetch_assoc()) {
                                $found_ref = trim($ref_search_row['reference_number']);
                                if (strlen($found_ref) > 3) {
                                    $sample_ref = $found_ref;
                                    $r['sample_reference_id'] = $sample_ref;
                                }
                            }
                        }
                        $ref_search->close();
                    }
                } catch (Exception $e) {
                    // If lookup fails, just use the original reference
                    error_log("Water permeability reference lookup failed: " . $e->getMessage());
                }
            }
            
            if (!empty($sample_ref)) {
                $ready_for_routing[] = $r;
            }
        }
    }
}

// 3. Characteristics Tests with routing (approved but not routed)
$char_roll_dest_exists_check = columnExists($conn, 'characteristics_tests', 'roll_destination');
// Create roll_destination column if it doesn't exist
if (!$char_roll_dest_exists_check) {
    @$conn->query("ALTER TABLE characteristics_tests ADD COLUMN roll_destination VARCHAR(255) NULL");
    $char_roll_dest_exists_check = true;
}
if ($char_roll_dest_exists_check) {
    $char_routing_query = "
        SELECT ct.report_number, 
               CASE 
                   WHEN LENGTH(TRIM(ct.reference_number)) <= 3 THEN 
                       COALESCE(re.reference_number, ct.reference_number)
                   ELSE ct.reference_number
               END as sample_reference_id, 
               COALESCE(ct.roll_destination, '') as roll_destination, ct.approved_at,
               'Characteristics Test' as test_name, 'characteristics' as test_type,
               NULL as test_data
        FROM characteristics_tests ct
        LEFT JOIN roll_entry re ON (
            re.reference_number = ct.reference_number
            OR re.reference_number LIKE CONCAT(ct.reference_number, '-%')
            OR ct.reference_number LIKE CONCAT(re.reference_number, '-%')
            OR (LENGTH(TRIM(ct.reference_number)) <= 3 AND re.reference_number LIKE CONCAT('%', ct.reference_number, '%'))
        )
        LEFT JOIN roll_received rr ON (
            (rr.reference_number = COALESCE(re.reference_number, ct.reference_number)
            OR rr.reference_number LIKE CONCAT(COALESCE(re.reference_number, ct.reference_number), '-%')
            OR COALESCE(re.reference_number, ct.reference_number) LIKE CONCAT(rr.reference_number, '-%'))
            " . ($rr_has_is_deleted ? "AND (rr.is_deleted = 0 OR rr.is_deleted IS NULL)" : "") . "
        )
        LEFT JOIN roll_transfer rt ON (
            (rt.reference_number = COALESCE(re.reference_number, ct.reference_number)
            OR rt.reference_number LIKE CONCAT(COALESCE(re.reference_number, ct.reference_number), '-%')
            OR COALESCE(re.reference_number, ct.reference_number) LIKE CONCAT(rt.reference_number, '-%'))
            " . ($rt_has_is_deleted ? "AND (rt.is_deleted = 0 OR rt.is_deleted IS NULL)" : "") . "
        )
        WHERE ct.status = 'approved'
          AND (ct.approved_at IS NULL OR ct.approved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))  -- Show recently approved (last 30 days) or if approved_at is NULL
          AND (ct.reference_number IS NOT NULL AND ct.reference_number != '')  -- Must have reference number for routing
          AND rr.id IS NULL  -- Not in roll_received (not routed/received)
          AND rt.id IS NULL  -- Not in roll_transfer (not transferred)
        ORDER BY ct.approved_at DESC
        LIMIT 200
    ";
    $char_routing_res = $conn->query($char_routing_query);
    if ($char_routing_res) {
        while ($r = $char_routing_res->fetch_assoc()) {
            $ready_for_routing[] = $r;
        }
    }
}

// 4. Sun Test Reports with routing (approved but not routed)
$sun_roll_dest_exists_check = columnExists($conn, 'sun_test_reports', 'roll_destination');
// Create roll_destination column if it doesn't exist
if (!$sun_roll_dest_exists_check) {
    @$conn->query("ALTER TABLE sun_test_reports ADD COLUMN roll_destination VARCHAR(255) NULL");
    $sun_roll_dest_exists_check = true;
}
if ($sun_roll_dest_exists_check) {
    // Check if reference_number column exists in sun_test_reports
    $sun_ref_num_check = columnExists($conn, 'sun_test_reports', 'reference_number');
    $sun_bundle_check = columnExists($conn, 'sun_test_reports', 'bundle_reference');
    
    $sun_ref_select = "COALESCE(str.reference_number, str.reference_name, '') as sample_reference_id";
    if ($sun_ref_num_check) {
        $sun_ref_select = "CASE 
            WHEN LENGTH(TRIM(COALESCE(str.reference_number, ''))) > 3 THEN str.reference_number
            WHEN LENGTH(TRIM(COALESCE(str.bundle_reference, ''))) > 3 THEN str.bundle_reference
            ELSE COALESCE(str.reference_number, str.reference_name, '')
        END as sample_reference_id";
    }
    
    $sun_routing_query = "
        SELECT str.report_number, $sun_ref_select, COALESCE(str.roll_destination, '') as roll_destination, str.approved_at,
               'Sun Test' as test_name, 'sun_test' as test_type,
               NULL as test_data
        FROM sun_test_reports str
        LEFT JOIN roll_entry re ON (
            (str.reference_number IS NOT NULL AND str.reference_number != '' AND re.reference_number = str.reference_number)
            OR (str.reference_number IS NOT NULL AND str.reference_number != '' AND re.reference_number LIKE CONCAT(str.reference_number, '-%'))
            OR (str.bundle_reference IS NOT NULL AND str.bundle_reference != '' AND re.reference_number = str.bundle_reference)
            OR (str.bundle_reference IS NOT NULL AND str.bundle_reference != '' AND re.reference_number LIKE CONCAT(str.bundle_reference, '-%'))
        )
        LEFT JOIN roll_received rr ON (
            ((str.reference_number IS NOT NULL AND str.reference_number != '' AND (
                rr.reference_number = COALESCE(re.reference_number, str.reference_number)
                OR rr.reference_number LIKE CONCAT(COALESCE(re.reference_number, str.reference_number), '-%')
                OR COALESCE(re.reference_number, str.reference_number) LIKE CONCAT(rr.reference_number, '-%')
            ))
            OR (str.bundle_reference IS NOT NULL AND str.bundle_reference != '' AND (
                rr.reference_number = COALESCE(re.reference_number, str.bundle_reference)
                OR rr.reference_number LIKE CONCAT(COALESCE(re.reference_number, str.bundle_reference), '-%')
            )))
            " . ($rr_has_is_deleted ? "AND (rr.is_deleted = 0 OR rr.is_deleted IS NULL)" : "") . "
        )
        LEFT JOIN roll_transfer rt ON (
            ((str.reference_number IS NOT NULL AND str.reference_number != '' AND (
                rt.reference_number = COALESCE(re.reference_number, str.reference_number)
                OR rt.reference_number LIKE CONCAT(COALESCE(re.reference_number, str.reference_number), '-%')
                OR COALESCE(re.reference_number, str.reference_number) LIKE CONCAT(rt.reference_number, '-%')
            ))
            OR (str.bundle_reference IS NOT NULL AND str.bundle_reference != '' AND (
                rt.reference_number = COALESCE(re.reference_number, str.bundle_reference)
                OR rt.reference_number LIKE CONCAT(COALESCE(re.reference_number, str.bundle_reference), '-%')
            )))
            " . ($rt_has_is_deleted ? "AND (rt.is_deleted = 0 OR rt.is_deleted IS NULL)" : "") . "
        )
        WHERE str.status = 'approved'
          AND (str.reference_number IS NOT NULL AND str.reference_number != '' OR str.reference_name IS NOT NULL AND str.reference_name != '')
          AND (str.approved_at IS NULL OR str.approved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))  -- Show recently approved (last 30 days) or if approved_at is NULL
          AND rr.id IS NULL  -- Not in roll_received (not routed/received)
          AND rt.id IS NULL  -- Not in roll_transfer (not transferred)
        GROUP BY str.id
        ORDER BY str.approved_at DESC
        LIMIT 200
    ";
    $sun_routing_res = $conn->query($sun_routing_query);
    if ($sun_routing_res) {
        while ($r = $sun_routing_res->fetch_assoc()) {
            if (!empty($r['sample_reference_id'])) {
                $ready_for_routing[] = $r;
            }
        }
    }
}

// 5. UV Test Reports with routing (approved but not routed)
$uv_roll_dest_exists_check = columnExists($conn, 'weathering_exposure_reports', 'roll_destination');
// Create roll_destination column if it doesn't exist
if (!$uv_roll_dest_exists_check) {
    @$conn->query("ALTER TABLE weathering_exposure_reports ADD COLUMN roll_destination VARCHAR(255) NULL");
    $uv_roll_dest_exists_check = true;
}
if ($uv_roll_dest_exists_check) {
    $uv_routing_query = "
        SELECT wer.report_number, 
               CASE 
                   WHEN LENGTH(TRIM(wer.reference)) <= 3 THEN 
                       COALESCE(re.reference_number, wer.reference)
                   ELSE wer.reference
               END as sample_reference_id, 
               COALESCE(wer.roll_destination, '') as roll_destination, wer.approved_at,
               'UV Test (Weathering Exposure)' as test_name, 'uv_test' as test_type,
               NULL as test_data
        FROM weathering_exposure_reports wer
        LEFT JOIN roll_entry re ON (
            re.reference_number = wer.reference
            OR re.reference_number LIKE CONCAT(wer.reference, '-%')
            OR wer.reference LIKE CONCAT(re.reference_number, '-%')
            OR (LENGTH(TRIM(wer.reference)) <= 3 AND re.reference_number LIKE CONCAT('%', wer.reference, '%'))
        )
        LEFT JOIN roll_received rr ON (
            (rr.reference_number = COALESCE(re.reference_number, wer.reference)
            OR rr.reference_number LIKE CONCAT(COALESCE(re.reference_number, wer.reference), '-%')
            OR COALESCE(re.reference_number, wer.reference) LIKE CONCAT(rr.reference_number, '-%'))
            " . ($rr_has_is_deleted ? "AND (rr.is_deleted = 0 OR rr.is_deleted IS NULL)" : "") . "
        )
        LEFT JOIN roll_transfer rt ON (
            (rt.reference_number = COALESCE(re.reference_number, wer.reference)
            OR rt.reference_number LIKE CONCAT(COALESCE(re.reference_number, wer.reference), '-%')
            OR COALESCE(re.reference_number, wer.reference) LIKE CONCAT(rt.reference_number, '-%'))
            " . ($rt_has_is_deleted ? "AND (rt.is_deleted = 0 OR rt.is_deleted IS NULL)" : "") . "
        )
        WHERE wer.status = 'approved'
          AND wer.reference IS NOT NULL
          AND wer.reference != ''
          AND (wer.approved_at IS NULL OR wer.approved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))  -- Show recently approved (last 30 days) or if approved_at is NULL
          AND rr.id IS NULL  -- Not in roll_received (not routed/received)
          AND rt.id IS NULL  -- Not in roll_transfer (not transferred)
        ORDER BY wer.approved_at DESC
        LIMIT 200
    ";
    $uv_routing_res = $conn->query($uv_routing_query);
    if ($uv_routing_res) {
        while ($r = $uv_routing_res->fetch_assoc()) {
            $ready_for_routing[] = $r;
        }
    }
}

// Group ready_for_routing by reference (one reference for all tests under that reference)
// Helper function to extract base reference (remove bundle suffixes like -1, -2, etc.)
$extractBaseRefRouting = function($ref) {
    if (empty($ref)) return '';
    // Remove bundle suffix pattern like -1, -2, -N at the end
    return preg_replace('/-\d+$/', '', trim($ref));
};

// Group by normalized base reference
$routing_by_reference = [];

foreach ($ready_for_routing as $roll) {
    $sample_ref = trim($roll['sample_reference_id'] ?? '');
    
    // Check if this is a bulk reference (has from/to in test_data)
    $test_data = json_decode($roll['test_data'] ?? '{}', true);
    if (is_string($test_data)) {
        $test_data = json_decode($test_data, true) ?? [];
    }
    
    // ALWAYS check for bulk references first (for bulk reference ranges)
    // This ensures bulk reference ranges are properly extracted even if sample_reference_id exists
    if (isset($test_data['is_bulk_reference']) && $test_data['is_bulk_reference'] && 
        isset($test_data['bulk_from_reference']) && !empty(trim($test_data['bulk_from_reference']))) {
        // For bulk references, prefer the bulk_from_reference for display
        $bulk_from = trim($test_data['bulk_from_reference']);
        // Keep the original sample_ref if it's a full individual roll reference (e.g., REF-1)
        // Otherwise use bulk_from_reference
        if (empty($sample_ref) || (strlen($sample_ref) <= 3) || 
            (isset($test_data['individual_roll_reference']) && $sample_ref === trim($test_data['individual_roll_reference']))) {
            // If sample_ref is empty, short, or matches individual_roll_reference, use bulk_from
            $sample_ref = $bulk_from;
            $roll['sample_reference_id'] = $sample_ref;
        }
    } elseif ((strlen($sample_ref) <= 3 || empty($sample_ref)) && isset($test_data['bulk_from_reference']) && !empty(trim($test_data['bulk_from_reference']))) {
        // Fallback: if reference is short/empty, try bulk_from_reference
        $sample_ref = trim($test_data['bulk_from_reference']);
        $roll['sample_reference_id'] = $sample_ref;
    }
    
    // Also check if sample_reference_id is empty but we have a product_reference in test_data
    if (empty($sample_ref) && isset($test_data['product_reference']) && !empty(trim($test_data['product_reference']))) {
        $sample_ref = trim($test_data['product_reference']);
        $roll['sample_reference_id'] = $sample_ref;
    }
    
    if (empty($sample_ref)) {
        continue; // Skip only if completely empty
    }
    // Don't skip short references - show them as-is
    
    $ref_key = '';
    $is_bulk_range = false;
    $from_ref = '';
    $to_ref = '';
    
    // Check for explicit bulk reference metadata (from test_data or from first loop processing)
    $has_bulk_refs = false;
    if (isset($roll['is_bulk_reference']) && $roll['is_bulk_reference'] && 
        isset($roll['bulk_from_reference']) && isset($roll['bulk_to_reference'])) {
        // Use bulk references from first loop processing
        $from_ref = trim($roll['bulk_from_reference']);
        $to_ref = trim($roll['bulk_to_reference']);
        $has_bulk_refs = !empty($from_ref) && !empty($to_ref);
    } elseif (isset($test_data['is_bulk_reference']) && $test_data['is_bulk_reference'] && 
        isset($test_data['bulk_from_reference']) && isset($test_data['bulk_to_reference'])) {
        // Extract from test_data if not already set
        $from_ref = trim($test_data['bulk_from_reference']);
        $to_ref = trim($test_data['bulk_to_reference']);
        $has_bulk_refs = !empty($from_ref) && !empty($to_ref);
    }
    
    if ($has_bulk_refs) {
        $ref_key = $from_ref . '|' . $to_ref;
        $is_bulk_range = true;
        // Use the from_ref as the display reference if sample_ref is still short or empty
        if ((strlen($sample_ref) <= 3 || empty($sample_ref)) && strlen($from_ref) > strlen($sample_ref)) {
            $sample_ref = $from_ref;
            $roll['sample_reference_id'] = $sample_ref;
        }
    }
    
    if (!$is_bulk_range) {
        // Use normalized base reference for grouping (remove bundle suffix like -1, -2, etc.)
        // This groups all bundle rolls (e.g., REF-1, REF-2) under the same base reference
        $base_ref = $extractBaseRefRouting($sample_ref);
        $ref_key = !empty($base_ref) ? $base_ref : $sample_ref; // Fallback to original if extraction fails
        // Keep the full reference (including -1 suffix) for display
        $from_ref = $sample_ref; // Use the full resolved reference (or original if short)
        $to_ref = '';
    }
    
    if (empty($ref_key)) {
        // If ref_key is empty but sample_ref exists, use sample_ref as ref_key
        if (!empty($sample_ref)) {
            $ref_key = $sample_ref;
        } else {
            continue;
        }
    }
    
        
    if (!isset($routing_by_reference[$ref_key])) {
        $routing_by_reference[$ref_key] = [
            'reference' => $sample_ref,  // Keep original full reference
            'base_reference' => $is_bulk_range ? '' : $sample_ref,  // For display, use full reference, not extracted
            'is_bulk_range' => $is_bulk_range,
                            'from_reference' => $from_ref,
                            'to_reference' => $to_ref,
            'tests' => [],
            'report_numbers' => [],
            'test_types' => [],
            'routing_destination' => $roll['roll_destination'] ?? '',
            'approved_at' => $roll['approved_at'] ?? ''
        ];
    } else {
    }
    
    // Keep the most complete reference (longest one) for display
    if (strlen(trim($sample_ref)) > strlen(trim($routing_by_reference[$ref_key]['reference']))) {
        $routing_by_reference[$ref_key]['reference'] = $sample_ref;
        if (!$routing_by_reference[$ref_key]['is_bulk_range']) {
            $routing_by_reference[$ref_key]['base_reference'] = $sample_ref;
        }
    }
    
    // Add this test to the reference group
    $routing_by_reference[$ref_key]['tests'][] = [
        'test_name' => $roll['test_name'] ?? 'N/A',
        'report_number' => $roll['report_number'] ?? '',
        'test_type' => $roll['test_type'] ?? 'qc_test_order',
        'routing_destination' => $roll['roll_destination'] ?? ''
    ];
    
    // Collect all report numbers for this reference
    if (!empty($roll['report_number'])) {
        $routing_by_reference[$ref_key]['report_numbers'][] = $roll['report_number'];
    }
    
    // Collect all test types (use the most common one or first one)
    if (!empty($roll['test_type'])) {
        $routing_by_reference[$ref_key]['test_types'][] = $roll['test_type'];
    }
    
    // Use the most recent approved_at
    if (!empty($roll['approved_at']) && 
        (empty($routing_by_reference[$ref_key]['approved_at']) || 
         $roll['approved_at'] > $routing_by_reference[$ref_key]['approved_at'])) {
        $routing_by_reference[$ref_key]['approved_at'] = $roll['approved_at'];
    }
    
    // Use routing destination if set (prioritize non-empty)
    if (!empty($roll['roll_destination']) && empty($routing_by_reference[$ref_key]['routing_destination'])) {
        $routing_by_reference[$ref_key]['routing_destination'] = $roll['roll_destination'];
    } elseif (!empty($roll['roll_destination']) && !empty($routing_by_reference[$ref_key]['routing_destination']) &&
              $roll['roll_destination'] !== $routing_by_reference[$ref_key]['routing_destination']) {
        // If multiple tests have different routing, use the first one (or could show "Mixed")
        // For now, keep the first one
    }
}

// Separate bulk ranges and individual references
$grouped_bulk_routing = [];
$grouped_individual_routing = [];

foreach ($routing_by_reference as $ref_key => $ref_group) {
    if ($ref_group['is_bulk_range']) {
        $grouped_bulk_routing[$ref_key] = $ref_group;
    } else {
        $grouped_individual_routing[] = $ref_group;
    }
}

// Keep connection open for routing queries
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>QC Test Approval Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
  body { font-family:'Inter',sans-serif; background:#f5f7fb; margin:0; padding:0; color:#0f172a; min-height:100vh; }
  .container { width:100%; min-height:100vh; margin:0; background:#ffffff; border-radius:0; padding:35px 3vw 60px; box-shadow:none; border:none; }
  h1 { text-align:center; font-size:34px; margin-bottom:10px; color:#0f172a; letter-spacing:0.6px; }
  .subtitle { text-align:center; color:#64748b; margin-bottom:25px; text-transform:uppercase; letter-spacing:4px; font-size:13px; }
  .alert { padding:15px 18px; border-radius:12px; margin-bottom:20px; font-weight:600; border:1px solid; }
  .alert-success { background:#ecfdf5; color:#047857; border-color:#6ee7b7; }
  .alert-error { background:#fef2f2; color:#b91c1c; border-color:#fecaca; }
  .info-banner { background:linear-gradient(120deg,#0ea5e9,#6366f1); color:white; padding:24px; border-radius:18px; margin-bottom:25px; box-shadow:0 15px 35px rgba(14,165,233,0.45); }
  table { width:100%; border-collapse:separate; border-spacing:0; margin-top:20px; }
  thead tr { background:rgba(15,23,42,0.95); color:#e2e8f0; }
  th { padding:16px 18px; text-align:left; font-weight:700; text-transform:uppercase; font-size:13px; letter-spacing:0.7px; border-bottom:1px solid rgba(226,232,240,0.3); position:sticky; top:0; }
  td { padding:14px 18px; border-bottom:1px solid rgba(226,232,240,0.8); }
  tbody tr:nth-child(even) { background:rgba(248,250,252,0.9); }
  tbody tr:hover { background:#f1f5f9; box-shadow:0 10px 24px rgba(15,23,42,0.12); transform:translateY(-2px); transition:0.25s ease; }
  .btn { padding:10px 18px; border:none; border-radius:12px; cursor:pointer; font-weight:600; font-size:13px; margin:0 6px; transition:all 0.25s; box-shadow:0 12px 24px rgba(15,23,42,0.18); }
  .btn-view { background:linear-gradient(135deg,#0ea5e9,#3b82f6); color:white; }
  .btn-approve { background:linear-gradient(135deg,#059669,#10b981); color:white; }
  .btn-reject { background:linear-gradient(135deg,#dc2626,#f97316); color:white; }
  .btn:hover { transform:translateY(-2px); box-shadow:0 16px 30px rgba(15,23,42,0.25); }
  .modal { display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.55); z-index:10000; overflow-y:auto; padding:30px 15px; backdrop-filter:blur(4px); }
  .modal-content { background:white; max-width:600px; margin:40px auto; padding:40px; border-radius:20px; box-shadow:0 25px 70px rgba(15,23,42,0.3); border:1px solid rgba(226,232,240,0.8); }
  .modal-header { margin-bottom:20px; padding-bottom:15px; border-bottom:2px solid #e2e8f0; }
  .modal-header h2 { margin:0; color:#0f172a; font-size:24px; }
  .form-group { margin-bottom:20px; }
  .form-group label { display:block; margin-bottom:8px; font-weight:600; color:#0f172a; }
  .form-group select, .form-group textarea { width:100%; padding:12px; border:1px solid #cbd5f5; border-radius:10px; font-size:14px; background:#f8fafc; }
  .form-group textarea { min-height:100px; font-family:inherit; }
  .modal-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:25px; padding-top:20px; border-top:1px solid #e2e8f0; }
  .btn-cancel { background:#94a3b8; color:white; }
  .btn-submit { background:linear-gradient(135deg,#059669,#10b981); color:white; padding:12px 26px; }
  .badge { display:inline-block; padding:6px 12px; border-radius:999px; font-size:12px; font-weight:600; }
  .badge-pending { background:#fde68a; color:#92400e; }
  .badge-approved { background:#34d399; color:#065f46; }
</style>
</head>
<body>
<div class="container">
  <h1><i class="fas fa-clipboard-check"></i> QC Test Approval Dashboard</h1>

  <?php if ($message): ?>
    <div class="alert alert-success">
      <strong>✓</strong> <?php echo htmlspecialchars($message); ?>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-error">
      <strong>✗</strong> <?php echo htmlspecialchars($error); ?>
    </div>
  <?php endif; ?>

  <div class="info-banner">
    <h3 style="margin:0 0 10px 0;"><i class="fas fa-info-circle"></i> Roll QC Approval & Routing</h3>
    <p style="margin:0; font-size:14px; opacity:0.95;">
      Review and approve QC test orders. When approving a roll, select its destination: <strong>FG</strong> or <strong>Bag</strong>.
    </p>
  </div>

  <div style="margin-bottom:15px;">
    <a href="../index.php" style="background:#6c757d; color:#fff; text-decoration:none; padding:10px 20px; border-radius:6px; display:inline-block;">
      <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
  </div>

  <?php if (empty($production_tests)): ?>
    <div style="text-align:center; padding:60px 20px; background:#f8f9fa; border-radius:8px; margin-top:20px;">
      <i class="fas fa-check-circle" style="font-size:60px; color:#28a745; margin-bottom:20px;"></i>
      <h3 style="color:#6c757d; margin:0;">No Pending QC Tests</h3>
      <p style="color:#adb5bd; margin:10px 0 0 0;">All QC tests have been processed.</p>
    </div>
  <?php endif; ?>
  
  <!-- Production Products Section -->
  <?php if (!empty($production_tests)): ?>
    <div style="background:#fff; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); margin-bottom:30px;">
      <div style="padding:20px; border-bottom:2px solid #4CAF50;">
        <h2 style="margin:0; font-size:20px; color:#333;">
          <i class="fas fa-industry"></i> Production Products - Pending Approval
          <span class="badge badge-pending"><?php echo count($production_tests); ?> Pending</span>
        </h2>
        <p style="margin:5px 0 0 0; color:#6c757d; font-size:13px;">Internal production rolls requiring QC approval and routing to FG or Bag</p>
      </div>
      
      <div style="padding:20px;">
        <?php 
        // Display bulk reference groups first
        foreach ($final_grouped_bulk as $bulk_key => $bulk_group): 
          $total_reports_in_bulk = count(array_merge(...array_values(array_map(function($tg) { return $tg['reports']; }, $bulk_group['test_groups']))));
        ?>
          <div style="background:#f8f9fa; border-radius:10px; padding:20px; margin-bottom:25px; border-left:4px solid #667eea;">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:15px;">
              <h3 style="margin:0; color:#333; font-size:18px;">
                <i class="fas fa-tags" style="color:#667eea;"></i> Reference Range: <strong style="color:#667eea;"><?php echo htmlspecialchars($bulk_group['from']); ?></strong> to <strong style="color:#667eea;"><?php echo htmlspecialchars($bulk_group['to']); ?></strong>
              </h3>
              <span class="badge badge-pending"><?php echo $total_reports_in_bulk; ?> report(s) | <?php echo $bulk_group['count']; ?> reference(s)</span>
            </div>
            
            <table style="margin:0;">
              <thead>
                <tr>
                  <th>Test Method</th>
                  <th>Reference Range</th>
                  <th>Inspector</th>
                  <th>Checked By</th>
                  <th>Submitted</th>
                  <th style="text-align:center;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($bulk_group['test_groups'] as $test_key => $test_group): 
                  $first_report = $test_group['reports'][0];
                  $report_numbers = array_column($test_group['reports'], 'report_number');
                  $report_numbers_str = implode(',', $report_numbers);
                  $test_type = $first_report['test_type'] ?? 'qc_test_order';
                  
                  // For non-QC test orders, we need IDs instead of report numbers
                  $ids = [];
                  $ids_str = '';
                  if ($test_type !== 'qc_test_order') {
                    $ids = array_filter(array_column($test_group['reports'], 'id'));
                    $ids_str = implode(',', $ids);
                  }
                  
                  $earliest_date = min(array_map(function($r) { return strtotime($r['updated_at'] ?? $r['created_at']); }, $test_group['reports']));
                  $latest_date = max(array_map(function($r) { return strtotime($r['updated_at'] ?? $r['created_at']); }, $test_group['reports']));
                ?>
                <tr>
                  <td>
                    <div style="font-weight:600; color:#333;"><?php echo htmlspecialchars($test_group['test_name']); ?></div>
                    <small style="color:#6c757d;"><?php echo htmlspecialchars($test_group['chosen_method']); ?></small>
                    <br><span style="font-size:11px; color:#999;"><?php echo count($test_group['reports']); ?> report(s)</span>
                  </td>
                  <td>
                    <?php echo htmlspecialchars($bulk_group['from']); ?> to <?php echo htmlspecialchars($bulk_group['to']); ?>
                    <br><span style="font-size:11px; color:#999;">(<?php echo $bulk_group['count']; ?> references)</span>
                  </td>
                  <td><?php echo htmlspecialchars($first_report['inspector_name']); ?></td>
                  <td><?php 
                    $testName = $test_group['test_name'] ?? '';
                    $testsRequiringChecker = ['Thickness (Under 2kPa Pressure)', 'Mass Per Unit Area (GSM)', 'Strip Tensile Test', 'CBR Puncture Resistance', 'Grab Tensile Test'];
                    $needsChecker = in_array($testName, $testsRequiringChecker);
                    
                    if ($first_report['checked_by'] && $first_report['checked_by'] !== 'N/A') {
                      echo htmlspecialchars($first_report['checked_by']);
                    } elseif (!$needsChecker) {
                      echo '<span style="color:#999; font-style:italic;">Not Required</span>';
                    } else {
                      echo 'N/A';
                    }
                  ?></td>
                  <td>
                    <?php 
                    if ($earliest_date == $latest_date) {
                      echo '<small>' . date('d M Y, h:i A', $earliest_date) . '</small>';
                    } else {
                      echo '<small>' . date('d M Y, h:i A', $earliest_date) . '</small><br><small style="color:#999;">to ' . date('d M Y, h:i A', $latest_date) . '</small>';
                    }
                    ?>
                  </td>
                  <td style="text-align:center; white-space:nowrap;">
                    <button type="button" onclick="viewBulkReports('<?php echo htmlspecialchars($test_type === 'qc_test_order' ? $report_numbers_str : $ids_str); ?>', '<?php echo htmlspecialchars($test_type); ?>')" 
                            class="btn btn-view">
                      <i class="fas fa-eye"></i> View All (<?php echo count($test_group['reports']); ?>)
                    </button>
                    <button type="button" onclick="openBulkApprovalModal('<?php echo htmlspecialchars($report_numbers_str); ?>', <?php echo count($test_group['reports']); ?>, '<?php echo htmlspecialchars($bulk_group['from']); ?>', '<?php echo htmlspecialchars($bulk_group['to']); ?>', '<?php echo htmlspecialchars($test_type); ?>')" 
                            class="btn btn-approve">
                      <i class="fas fa-check"></i> Approve All (<?php echo count($test_group['reports']); ?>)
                    </button>
                    <button type="button" onclick="openBulkRejectModal('<?php echo htmlspecialchars($report_numbers_str); ?>', <?php echo count($test_group['reports']); ?>, '<?php echo htmlspecialchars($test_type); ?>')" 
                            class="btn btn-reject">
                      <i class="fas fa-times"></i> Reject All (<?php echo count($test_group['reports']); ?>)
                    </button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endforeach; ?>
        
        <?php 
        // Display individual reports (non-bulk)
        foreach ($grouped_individual_reports as $refId => $tests): 
        ?>
          <div style="background:#f8f9fa; border-radius:10px; padding:20px; margin-bottom:25px; border-left:4px solid #667eea;">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:15px;">
              <h3 style="margin:0; color:#333; font-size:18px;">
                <i class="fas fa-scroll" style="color:#667eea;"></i> Roll Reference: <strong style="color:#667eea;"><?php echo htmlspecialchars($refId); ?></strong>
              </h3>
              <span class="badge badge-pending"><?php echo count($tests); ?> Test<?php echo count($tests) > 1 ? 's' : ''; ?></span>
            </div>
            
            <table style="margin:0;">
              <thead>
                <tr>
                  <th>Reference Number</th>
                  <th>Test Method</th>
                  <th>Inspector</th>
                  <th>Checked By</th>
                  <th>Submitted</th>
                  <th style="text-align:center;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($tests as $test): 
                  $test_data = json_decode($test['test_data'] ?? '{}', true);
                  // Display the actual reference number, not report number
                  $display_ref = htmlspecialchars($test['sample_reference_id'] ?? 'N/A');
                  
                  // Determine view link based on test type
                  $test_type = $test['test_type'] ?? 'qc_test_order';
                  $viewLinks = [
                      'qc_test_order' => 'view_qc_test_order.php',
                      'water_permeability' => 'view_water_permeability.php',
                      'water_perm' => 'view_water_permeability.php',
                      'sun_test' => 'view_sun_report.php',
                      'sun' => 'view_sun_report.php',
                      'uv_test' => 'view_uv_report.php',
                      'uv' => 'view_uv_report.php',
                      'characteristics' => 'view_characteristics.php'
                  ];
                  $viewLink = $viewLinks[$test_type] ?? 'view_qc_test_order.php';
                  
                  // Determine parameter name (some use id, some use report_number)
                  // QC test orders use report_number, all others use id
                  $useId = !in_array($test_type, ['qc_test_order']);
                  $viewParam = $useId ? "id={$test['id']}" : "report_number=" . htmlspecialchars($test['report_number']);
                ?>
                <tr>
                  <td style="font-weight:600; color:#333;">
                    <?php echo $display_ref; ?>
                    <br><small style="color:#999; font-weight:normal;">Report: <?php echo htmlspecialchars($test['report_number']); ?></small>
                  </td>
                  <td>
                    <div><?php echo htmlspecialchars($test['test_name'] ?? 'N/A'); ?></div>
                    <small style="color:#6c757d;"><?php echo htmlspecialchars($test['chosen_method'] ?? ''); ?></small>
                  </td>
                  <td><?php echo htmlspecialchars($test['inspector_name']); ?></td>
                  <td><?php 
                    $testName = $test['test_name'] ?? '';
                    $testsRequiringChecker = ['Thickness (Under 2kPa Pressure)', 'Mass Per Unit Area (GSM)', 'Strip Tensile Test', 'CBR Puncture Resistance', 'Grab Tensile Test'];
                    $needsChecker = in_array($testName, $testsRequiringChecker);
                    
                    if ($test['checked_by'] && $test['checked_by'] !== 'N/A') {
                      echo htmlspecialchars($test['checked_by']);
                    } elseif (!$needsChecker) {
                      echo '<span style="color:#999; font-style:italic;">Not Required</span>';
                    } else {
                      echo 'N/A';
                    }
                  ?></td>
                  <td>
                    <small><?php echo date('d M Y, h:i A', strtotime($test['updated_at'])); ?></small>
                  </td>
                  <td style="text-align:center; white-space:nowrap;">
                    <a href="../admin/<?php echo $viewLink; ?>?<?php echo $viewParam; ?>&return=qc_test_approval_dashboard" 
                       target="_blank" class="btn btn-view">
                      <i class="fas fa-eye"></i> View
                    </a>
                    <button type="button" onclick="openApprovalModal('<?php echo htmlspecialchars($test['report_number']); ?>', '<?php echo htmlspecialchars($test['sample_reference_id']); ?>', '<?php echo htmlspecialchars($test['test_type'] ?? 'qc_test_order'); ?>')" 
                            class="btn btn-approve">
                      <i class="fas fa-check"></i> Approve
                    </button>
                    <button type="button" onclick="openRejectModal('<?php echo htmlspecialchars($test['report_number']); ?>', '<?php echo htmlspecialchars($test['test_type'] ?? 'qc_test_order'); ?>')" 
                            class="btn btn-reject">
                      <i class="fas fa-times"></i> Reject
                    </button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
  

<!-- Approved Tests with Routing -->
<?php 
$total_routing_items = count($grouped_bulk_routing) + count($grouped_individual_routing);
?>
  <div style="background:#fff; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); margin-bottom:30px; border-top:4px solid #28a745;">
    <div style="padding:20px; border-bottom:2px solid #28a745;">
      <h2 style="margin:0; font-size:20px; color:#333;">
        <i class="fas fa-route" style="color:#28a745;"></i> Approved Tests with Routing
        <?php if ($total_routing_items > 0): ?>
        <span class="badge" style="background:#28a745; color:#fff;"><?php echo $total_routing_items; ?> Test(s)</span>
        <?php endif; ?>
      </h2>
      <p style="margin:5px 0 0 0; color:#6c757d; font-size:13px;">Approved tests ready for routing. AGM can set routing destination (FG or Bag) from here.</p>
    </div>
    
    <div style="padding:20px;">
      <!-- Bulk References (Grouped) -->
      <?php if (!empty($grouped_bulk_routing)): ?>
        <h3 style="margin:0 0 15px 0; color:#333; font-size:16px;">
          <i class="fas fa-layer-group"></i> Bulk References (Bundle)
        </h3>
        <table style="width:100%; border-collapse:collapse; border:1px solid #28a745; border-radius:8px; overflow:hidden; margin-bottom:20px;">
          <thead>
            <tr style="background:#e8f6ec; color:#0f172a;">
              <th style="padding:10px; text-align:left; border-bottom:1px solid #28a745; font-weight:700;">Reference</th>
              <th style="padding:10px; text-align:left; border-bottom:1px solid #28a745; font-weight:700;">Routing Destination</th>
              <th style="padding:10px; text-align:left; border-bottom:1px solid #28a745; font-weight:700;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($grouped_bulk_routing as $bulk_key => $bulk_group): 
              $routing_dest = $bulk_group['routing_destination'] ?? '';
              $routing_display = '';
              if ($routing_dest === 'fg_production') {
                  $routing_display = '🟢 FG';
              } elseif ($routing_dest === 'bag_production') {
                  $routing_display = '🔵 Bag';
              }
              
              $test_names = array_map(function($t) { return $t['test_name']; }, $bulk_group['tests']);
              $test_name_str = implode(', ', array_unique($test_names));
              $test_count = count($bulk_group['tests']);
              $approved_at = $bulk_group['approved_at'] ?? '';
              $report_numbers_str = implode(',', array_unique($bulk_group['report_numbers']));
              $test_types = array_unique($bulk_group['test_types']);
              $test_type = $test_types[0] ?? 'qc_test_order';
            ?>
            <tr style="border-bottom:1px solid #28a745; background:#fff;">
              <td style="padding:10px; font-weight:600; color:#0f172a;">
                <i class="fas fa-layer-group" style="color:#667eea; margin-right:5px;"></i>
                Roll Reference: <?php echo htmlspecialchars($bulk_group['from_reference']); ?> 
                <span style="color:#6c757d;">to</span> 
                <?php echo htmlspecialchars($bulk_group['to_reference']); ?>
              </td>
              <td style="padding:10px;">
                <?php if ($routing_display): ?>
                  <span style="padding:6px 12px; background:#e8f6ec; color:#065f46; border-radius:6px; font-weight:600;">
                    <?php echo $routing_display; ?>
                </span>
                <?php else: ?>
                  <span style="color:#999;">Not set</span>
                <?php endif; ?>
              </td>
              <td style="padding:10px;">
                <form onsubmit="return setRouting('<?php echo htmlspecialchars($report_numbers_str); ?>', '<?php echo htmlspecialchars($test_type); ?>', '<?php echo htmlspecialchars($bulk_group['from_reference']); ?>', event)" style="display:inline-block;">
                  <select name="roll_destination" required style="padding:6px 8px; border:1px solid #ccc; border-radius:4px; font-size:13px; margin-right:5px;">
                    <option value="">-- Select --</option>
                    <option value="fg_production" <?php echo ($routing_dest === 'fg_production') ? 'selected' : ''; ?>>🟢 FG</option>
                    <option value="bag_production" <?php echo ($routing_dest === 'bag_production') ? 'selected' : ''; ?>>🔵 Bag</option>
                  </select>
                  <button type="submit" class="btn" style="padding:6px 12px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:13px;">
                    <i class="fas fa-route"></i> Route
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      
      <!-- Individual References (Not in Bundle) -->
      <?php 
      if (!empty($grouped_individual_routing)): 
      ?>
        <h3 style="margin:0 0 15px 0; color:#333; font-size:16px;">
          <i class="fas fa-tag"></i> Individual References
        </h3>
      <table style="width:100%; border-collapse:collapse; border:1px solid #28a745; border-radius:8px; overflow:hidden;">
        <thead>
          <tr style="background:#e8f6ec; color:#0f172a;">
            <th style="padding:10px; text-align:left; border-bottom:1px solid #28a745; font-weight:700;">Reference</th>
            <th style="padding:10px; text-align:left; border-bottom:1px solid #28a745; font-weight:700;">Routing Destination</th>
            <th style="padding:10px; text-align:left; border-bottom:1px solid #28a745; font-weight:700;">Action</th>
          </tr>
        </thead>
        <tbody>
            <?php foreach ($grouped_individual_routing as $ref_group): 
              $routing_dest = $ref_group['routing_destination'] ?? '';
              $routing_display = '';
              if ($routing_dest === 'fg_production') {
                  $routing_display = '🟢 FG';
              } elseif ($routing_dest === 'bag_production') {
                  $routing_display = '🔵 Bag';
              }
              $approved_at = $ref_group['approved_at'] ?? '';
              $test_types = array_unique($ref_group['test_types']);
              $test_type = $test_types[0] ?? 'qc_test_order';
              // Always use the full reference for display
              $ref_display = $ref_group['reference'];
              
              $test_names = array_map(function($t) { return $t['test_name']; }, $ref_group['tests']);
              $test_name_str = implode(', ', array_unique($test_names));
              $test_count = count($ref_group['tests']);
              $report_numbers_str = implode(',', array_unique($ref_group['report_numbers']));
            ?>
          <tr style="border-bottom:1px solid #28a745; background:#fff;">
              <td style="padding:10px; font-weight:600; color:#0f172a;">
                <i class="fas fa-tag" style="color:#6c757d; margin-right:5px;"></i>
                Roll Reference: <?php echo htmlspecialchars($ref_display); ?>
              </td>
            <td style="padding:10px;">
                <?php if ($routing_display): ?>
                  <span style="padding:6px 12px; background:#e8f6ec; color:#065f46; border-radius:6px; font-weight:600;">
                    <?php echo $routing_display; ?>
                  </span>
                <?php else: ?>
                  <span style="color:#999;">Not set</span>
                <?php endif; ?>
              </td>
              <td style="padding:10px;">
                <form onsubmit="return setRouting('<?php echo htmlspecialchars($report_numbers_str); ?>', '<?php echo htmlspecialchars($test_type); ?>', '<?php echo htmlspecialchars($ref_display); ?>', event)" style="display:inline-block;">
                  <select name="roll_destination" required style="padding:6px 8px; border:1px solid #ccc; border-radius:4px; font-size:13px; margin-right:5px;">
                    <option value="">-- Select --</option>
                    <option value="fg_production" <?php echo ($routing_dest === 'fg_production') ? 'selected' : ''; ?>>🟢 FG</option>
                    <option value="bag_production" <?php echo ($routing_dest === 'bag_production') ? 'selected' : ''; ?>>🔵 Bag</option>
                </select>
                  <button type="submit" class="btn" style="padding:6px 12px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:13px;">
                  <i class="fas fa-route"></i> Route
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
      
      <?php if ($total_routing_items === 0): ?>
      <div style="padding:40px; text-align:center; color:#6c757d;">
        <i class="fas fa-inbox" style="font-size:48px; color:#cbd5e1; margin-bottom:15px;"></i>
        <p style="font-size:16px; margin:0;">No approved tests ready for routing.</p>
        <p style="font-size:14px; margin:10px 0 0 0; color:#94a3b8;">Approved tests will appear here once they are approved.</p>
  </div>
<?php endif; ?>
    </div>
  </div>
  
</div>

<!-- Production Roll Approval Modal (routing handled separately) -->
<div id="approvalModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2><i class="fas fa-check-circle"></i> Approve Production Roll QC Test</h2>
      <p style="margin:10px 0 0 0; color:#6c757d; font-size:14px;">
        Roll Reference: <strong id="approvalRollRef"></strong>
      </p>
    </div>
    
    <form id="approvalForm" onsubmit="return submitApprovalForm(event)">
      <input type="hidden" name="report_number" id="approvalReportNumber">
      <input type="hidden" name="test_type" id="approvalTestType" value="qc_test_order">
      <input type="hidden" name="action" value="approve">
      
      <div class="form-group">
        <label>
          <i class="fas fa-comment"></i> Approval Comments (Optional):
        </label>
        <textarea name="admin_comment" id="approvalComment" placeholder="Add any remarks or instructions..."></textarea>
      </div>
      
      <p style="margin:15px 0; padding:12px; background:#e8f6ec; border-left:4px solid #28a745; border-radius:4px; color:#065f46; font-size:14px;">
        <i class="fas fa-info-circle"></i> <strong>Note:</strong> After approval, this test will appear in the "Approved Tests with Routing" section below where you can set the routing destination (FG or Bag).
      </p>
      
      <div class="modal-actions">
        <button type="button" onclick="closeApprovalModal()" class="btn btn-cancel">
          Cancel
        </button>
        <button type="submit" class="btn btn-submit" id="approvalSubmitBtn">
          <i class="fas fa-check"></i> Approve
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Routing modal removed (handled in separate routing page) -->

<!-- External Product Approval Modal removed - external tests now show in AGM External Test Dashboard -->

<!-- Rejection Modal -->
<div id="rejectModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2 style="color:#dc3545;"><i class="fas fa-times-circle"></i> Reject QC Test</h2>
    </div>
    
    <form id="rejectForm" onsubmit="return submitRejectionForm(event)">
      <input type="hidden" name="report_number" id="rejectReportNumber">
      <input type="hidden" name="test_type" id="rejectTestType" value="qc_test_order">
      <input type="hidden" name="action" value="reject">
      
      <div class="form-group">
        <label>
          <i class="fas fa-exclamation-triangle"></i> Rejection Reasons: <span style="color:red;">*</span>
        </label>
        <div style="background:#f8f9fa; padding:15px; border-radius:6px; border:1px solid #dee2e6;">
          <label style="display:block; margin-bottom:10px; font-weight:normal; cursor:pointer;">
            <input type="checkbox" name="rejection_reasons[]" value="Test parameters incorrect" style="margin-right:8px;">
            Test parameters incorrect
          </label>
          <label style="display:block; margin-bottom:10px; font-weight:normal; cursor:pointer;">
            <input type="checkbox" name="rejection_reasons[]" value="Test method not appropriate" style="margin-right:8px;">
            Test method not appropriate
          </label>
          <label style="display:block; margin-bottom:10px; font-weight:normal; cursor:pointer;">
            <input type="checkbox" name="rejection_reasons[]" value="Incomplete test data" style="margin-right:8px;">
            Incomplete test data
          </label>
          <label style="display:block; margin-bottom:10px; font-weight:normal; cursor:pointer;">
            <input type="checkbox" name="rejection_reasons[]" value="Sample reference mismatch" style="margin-right:8px;">
            Sample reference mismatch
          </label>
          <label style="display:block; margin-bottom:10px; font-weight:normal; cursor:pointer;">
            <input type="checkbox" name="rejection_reasons[]" value="Test results out of specification" style="margin-right:8px;">
            Test results out of specification
          </label>
          <label style="display:block; margin-bottom:0; font-weight:normal; cursor:pointer;">
            <input type="checkbox" id="rejectOtherCheckbox" name="rejection_reasons[]" value="Other" style="margin-right:8px;" onchange="toggleOtherReason()">
            Other (Specify below)
          </label>
        </div>
      </div>
      
      <div class="form-group" id="otherReasonGroup" style="display:none;">
        <label>
          <i class="fas fa-edit"></i> Specify Other Reason:
        </label>
        <textarea id="otherReasonText" name="other_reason" placeholder="Please specify the reason..."></textarea>
      </div>
      
      <div class="modal-actions">
        <button type="button" onclick="closeRejectModal()" class="btn btn-cancel">
          Cancel
        </button>
        <button type="submit" class="btn btn-reject">
          <i class="fas fa-times"></i> Reject Test
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// View multiple reports in bulk
// Note: This function needs test_type to be passed, but currently it's only used for QC test orders
// For now, we'll assume QC test orders. If needed, we can update the button to pass test_type.
function viewBulkReports(identifiersStr, testType) {
  const identifiers = identifiersStr.split(',');
  const test_type = testType || 'qc_test_order';
  
  // Map test types to view pages
  const viewLinks = {
    'qc_test_order': 'view_qc_test_order.php',
    'water_permeability': 'view_water_permeability.php',
    'water_perm': 'view_water_permeability.php',
    'sun_test': 'view_sun_report.php',
    'sun': 'view_sun_report.php',
    'uv_test': 'view_uv_report.php',
    'uv': 'view_uv_report.php',
    'characteristics': 'view_characteristics.php'
  };
  
  const viewLink = viewLinks[test_type] || 'view_qc_test_order.php';
  const useId = !['qc_test_order'].includes(test_type);
  
  identifiers.forEach(identifier => {
    if (identifier.trim()) {
      if (useId) {
        // For non-QC test orders, use ID parameter
        window.open(`../admin/${viewLink}?id=${encodeURIComponent(identifier.trim())}&return=qc_test_approval_dashboard`, '_blank');
      } else {
        // For QC test orders, use report_number parameter
        window.open(`../admin/${viewLink}?report_number=${encodeURIComponent(identifier.trim())}&return=qc_test_approval_dashboard`, '_blank');
      }
    }
  });
}

// Open bulk approval modal
function openBulkApprovalModal(reportNumbersStr, count, fromRef, toRef, testType) {
  document.getElementById('approvalReportNumber').value = reportNumbersStr;
  document.getElementById('approvalTestType').value = testType || 'qc_test_order';
  document.getElementById('approvalRollRef').textContent = fromRef + ' to ' + toRef;
  document.getElementById('approvalModal').style.display = 'block';
  // Update form to handle multiple reports
  const form = document.getElementById('approvalForm');
  form.setAttribute('data-bulk-mode', 'true');
  form.setAttribute('data-report-count', count);
}

// Open bulk reject modal
function openBulkRejectModal(reportNumbersStr, count, testType) {
  document.getElementById('rejectReportNumber').value = reportNumbersStr;
  document.getElementById('rejectTestType').value = testType || 'qc_test_order';
  document.getElementById('rejectModal').style.display = 'block';
  // Update form to handle multiple reports
  const form = document.getElementById('rejectForm');
  form.setAttribute('data-bulk-mode', 'true');
  form.setAttribute('data-report-count', count);
}

function openApprovalModal(reportNumber, rollRef, testType) {
  document.getElementById('approvalReportNumber').value = reportNumber;
  document.getElementById('approvalTestType').value = testType || 'qc_test_order';
  document.getElementById('approvalRollRef').textContent = rollRef;
  document.getElementById('approvalModal').style.display = 'block';
  // Reset bulk mode
  const form = document.getElementById('approvalForm');
  form.removeAttribute('data-bulk-mode');
  form.removeAttribute('data-report-count');
}

function closeApprovalModal() {
  document.getElementById('approvalModal').style.display = 'none';
  document.getElementById('approvalForm').reset();
}

// External approval modal functions removed - external tests now show in AGM External Test Dashboard

function openRejectModal(reportNumber, testType) {
  document.getElementById('rejectReportNumber').value = reportNumber;
  document.getElementById('rejectTestType').value = testType || 'qc_test_order';
  document.getElementById('rejectModal').style.display = 'block';
}

function closeRejectModal() {
  document.getElementById('rejectModal').style.display = 'none';
  document.getElementById('rejectForm').reset();
  document.getElementById('otherReasonGroup').style.display = 'none';
}

function toggleOtherReason() {
  const otherCheckbox = document.getElementById('rejectOtherCheckbox');
  const otherReasonGroup = document.getElementById('otherReasonGroup');
  const otherReasonText = document.getElementById('otherReasonText');
  
  if (otherCheckbox.checked) {
    otherReasonGroup.style.display = 'block';
    otherReasonText.required = true;
  } else {
    otherReasonGroup.style.display = 'none';
    otherReasonText.required = false;
    otherReasonText.value = '';
  }
}

function submitApprovalForm(event) {
  event.preventDefault();
  
  const reportNumber = document.getElementById('approvalReportNumber').value;
  const testType = document.getElementById('approvalTestType').value;
  const comment = document.getElementById('approvalComment').value;
  
  if (!reportNumber || reportNumber.trim() === '') {
    alert('Error: Report number is missing');
    return false;
  }
  
  // Map test_type to report_type format used by API
  // API uses: water_perm, sun, uv, but dashboard uses: water_permeability, sun_test, uv_test
  const reportTypeMap = {
    'qc_test_order': 'qc_test_order',
    'water_permeability': 'water_permeability', // API now supports both formats
    'water_perm': 'water_permeability',
    'sun_test': 'sun_test', // API now supports both formats
    'sun': 'sun_test',
    'uv_test': 'uv_test', // API now supports both formats
    'uv': 'uv_test',
    'characteristics': 'characteristics'
  };
  
  const reportType = reportTypeMap[testType] || testType;
  
  const submitBtn = document.getElementById('approvalSubmitBtn');
  const originalText = submitBtn.innerHTML;
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Approving...';
  
  const formData = new FormData();
  formData.append('action', 'approve');
  formData.append('report_type', reportType);
  formData.append('report_number', reportNumber);
  // Don't send roll_destination - routing will be set in the routing section below
  if (comment) {
    formData.append('comments', comment);
  }
  
  fetch('api/approve_reject_report.php', {
    method: 'POST',
    body: formData
  })
  .then(response => {
    if (!response.ok) {
      throw new Error('Network response was not ok: ' + response.status);
    }
    return response.text().then(text => {
      try {
        return JSON.parse(text);
      } catch (e) {
        console.error('Failed to parse JSON response:', text);
        throw new Error('Invalid response from server: ' + text.substring(0, 100));
      }
    });
  })
  .then(data => {
    if (data.success) {
      alert('✅ ' + data.message + '\n\nThe approved test will appear in the routing section below where you can set the routing destination.');
      closeApprovalModal();
      // Reload the page to refresh the dashboard and show approved test in routing section
      window.location.reload();
    } else {
      alert('❌ ' + (data.message || 'Failed to approve report. Please try again.'));
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalText;
    }
  })
  .catch(error => {
    console.error('Error:', error);
    alert('❌ An error occurred: ' + error.message + '\n\nPlease check the console for more details.');
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalText;
  });
  
  return false;
}

function submitRejectionForm(event) {
  event.preventDefault();
  
  const checkboxes = document.querySelectorAll('input[name="rejection_reasons[]"]:checked');
  if (checkboxes.length === 0) {
    alert('Please select at least one rejection reason');
    return false;
  }
  
  const otherCheckbox = document.getElementById('rejectOtherCheckbox');
  const otherReasonText = document.getElementById('otherReasonText');
  
  if (otherCheckbox.checked && !otherReasonText.value.trim()) {
    alert('Please specify the other reason');
    otherReasonText.focus();
    return false;
  }
  
  const reportNumber = document.getElementById('rejectReportNumber').value;
  const testType = document.getElementById('rejectTestType').value;
  // Additional comments field might not exist, so use optional chaining
  const comment = document.getElementById('rejectComment')?.value || document.getElementById('rejectAdditionalComment')?.value || '';
  
  if (!reportNumber || reportNumber.trim() === '') {
    alert('Error: Report number is missing');
    return false;
  }
  
  // Map test_type to report_type format used by API
  // API uses: water_perm, sun, uv, but dashboard uses: water_permeability, sun_test, uv_test
  const reportTypeMap = {
    'qc_test_order': 'qc_test_order',
    'water_permeability': 'water_permeability', // API now supports both formats
    'water_perm': 'water_permeability',
    'sun_test': 'sun_test', // API now supports both formats
    'sun': 'sun_test',
    'uv_test': 'uv_test', // API now supports both formats
    'uv': 'uv_test',
    'characteristics': 'characteristics'
  };
  
  const reportType = reportTypeMap[testType] || testType;
  
  // Build rejection reasons
  const rejectionReasons = Array.from(checkboxes).map(cb => cb.value);
  if (otherCheckbox.checked && otherReasonText.value.trim()) {
    rejectionReasons.push('Other: ' + otherReasonText.value.trim());
  }
  
  const submitBtn = document.querySelector('#rejectForm button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Rejecting...';
  
  const formData = new FormData();
  formData.append('action', 'reject');
  formData.append('report_type', reportType);
  formData.append('report_number', reportNumber);
  rejectionReasons.forEach(reason => {
    formData.append('qc_rejection_reasons[]', reason);
  });
  if (comment) {
    formData.append('comments', comment);
  }
  
  fetch('api/approve_reject_report.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      alert('✅ ' + data.message);
      closeRejectModal();
      // Reload the page to refresh the dashboard
      window.location.reload();
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

function setRouting(reportNumber, testType, reference, event) {
  event.preventDefault();
  
  const form = event.target;
  const select = form.querySelector('select[name="roll_destination"]');
  const rollDestination = select.value;
  
  if (!rollDestination || rollDestination.trim() === '') {
    alert('Please select a routing destination');
    return false;
  }
  
  if (!confirm('Are you sure you want to route this test to ' + (rollDestination === 'fg_production' ? 'FG' : 'Bag') + '?')) {
    return false;
  }
  
  // Map test_type to report_type format used by API
  const reportTypeMap = {
    'qc_test_order': 'qc_test_order',
    'water_permeability': 'water_permeability',
    'water_perm': 'water_permeability',
    'sun_test': 'sun_test',
    'sun': 'sun_test',
    'uv_test': 'uv_test',
    'uv': 'uv_test',
    'characteristics': 'characteristics'
  };
  
  const reportType = reportTypeMap[testType] || testType;
  
  const submitBtn = form.querySelector('button[type="submit"]');
  const originalText = submitBtn.innerHTML;
  submitBtn.disabled = true;
  submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  
  const formData = new FormData();
  formData.append('action', 'route');
  formData.append('report_type', reportType);
  formData.append('report_number', reportNumber);
  formData.append('roll_destination', rollDestination);
  
  fetch('api/approve_reject_report.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      const routingMsg = rollDestination === 'fg_production' ? 'FG' : 'Bag';
      alert('✅ Routing set successfully! Routed to: ' + routingMsg);
      // Reload the page to refresh the dashboard
      window.location.reload();
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

// Close modal when clicking outside
window.onclick = function(event) {
  const approvalModal = document.getElementById('approvalModal');
  const rejectModal = document.getElementById('rejectModal');
  
  if (event.target === approvalModal) {
    closeApprovalModal();
  }
  if (event.target === rejectModal) {
    closeRejectModal();
  }
}
</script>

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
</body>
</html>

