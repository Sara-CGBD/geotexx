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
    SELECT qto.*, ts.test_name, ts.standard_code
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

// Group reports by bulk reference (similar to checker dashboard logic)
$grouped_bulk_reports = [];
$grouped_individual_reports = [];

$processed_indices = []; // Track which reports have been processed

// First pass: Process reports with explicit bulk reference metadata
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

// Second pass: Check for reports that might belong to existing bulk groups
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
                
                // Debug: Log matched reports
                error_log("Matched report to bulk group: " . ($report['report_number'] ?? 'N/A') . 
                          " | Sample Ref: " . $sample_ref . 
                          " | Test: " . ($report['test_name'] ?? 'N/A'));
                break; // Found a match, move to next report
            }
        }
    }
}

// Additional pass: Detect sequential references that form bulk groups even without explicit flag
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
    if (count($roll_nums) >= 2) {
        $min_roll = min($roll_nums);
        $max_roll = max($roll_nums);
        $from_ref = $base_ref . '-' . $min_roll;
        $to_ref = $base_ref . '-' . $max_roll;
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

// Third pass: Remaining reports are individual
foreach ($production_tests as $idx => $report) {
    if (in_array($idx, $processed_indices)) {
        continue; // Already processed
    }
    
    // Individual report - group by reference
    $refId = $report['sample_reference_id'] ?? 'Unknown';
    if (!isset($grouped_individual_reports[$refId])) {
        $grouped_individual_reports[$refId] = [];
    }
    $grouped_individual_reports[$refId][] = $report;
}

// Group tests within each bulk reference by test_name and chosen_method
// Normalize test names and methods to handle whitespace/case differences
$final_grouped_bulk = [];
foreach ($grouped_bulk_reports as $bulk_key => $bulk_group) {
    $test_groups = [];
    foreach ($bulk_group['reports'] as $report) {
        // Normalize test_name and chosen_method (trim whitespace, handle nulls)
        $test_name = trim($report['test_name'] ?? '');
        $chosen_method = trim($report['chosen_method'] ?? '');
        
        // Debug: Log all reports being processed
        error_log("Processing report: " . ($report['report_number'] ?? 'N/A') . 
                  " | Test: " . $test_name . 
                  " | Method: " . $chosen_method . 
                  " | Sample Ref: " . ($report['sample_reference_id'] ?? 'N/A'));
        
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
    
    // Debug: Log grouping results
    foreach ($test_groups as $key => $group) {
        error_log("Test Group: " . $key . " has " . count($group['reports']) . " reports");
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
// Find rolls with all tests approved and not yet routed (non-external)
// Also fetch test_data to check for bulk references
$routingSql = "
    SELECT qto.sample_reference_id, COUNT(*) AS approved_tests, MAX(qto.approved_at) AS last_approved_at,
           qto.test_data
    FROM qc_test_orders qto
    WHERE qto.sample_reference_id IS NOT NULL
      AND qto.sample_reference_id != ''
      AND qto.status = 'approved'
      AND (qto.roll_destination IS NULL OR qto.roll_destination = '')
    GROUP BY qto.sample_reference_id, qto.test_data
    ORDER BY last_approved_at DESC
    LIMIT 200
";
$routingRes = $conn->query($routingSql);
if ($routingRes) {
    while ($r = $routingRes->fetch_assoc()) {
        $sample_ref = trim($r['sample_reference_id'] ?? '');
        if ($sample_ref === '') {
            continue;
        }
        // Check if external (by prefix or test_data flag)
        $test_data = json_decode($r['test_data'] ?? '{}', true);
            $is_external = false;
            if (!empty($sample_ref) && (stripos($sample_ref, 'EXT-') === 0 || stripos($sample_ref, 'TOKEN-') === 0)) {
                $is_external = true;
        } elseif (isset($test_data['is_external_product']) && ($test_data['is_external_product'] === true || $test_data['is_external_product'] === '1')) {
                    $is_external = true;
        }
        if (!$is_external) {
            $ready_for_routing[] = $r;
        }
    }
}

// Group ready_for_routing by bulk reference (similar to approval grouping)
$grouped_bulk_routing = [];
$grouped_individual_routing = [];
$routing_processed_indices = [];

// First pass: Process references with explicit bulk reference metadata
foreach ($ready_for_routing as $idx => $roll) {
    $test_data = json_decode($roll['test_data'] ?? '{}', true);
    
    if (is_string($test_data)) {
        $test_data = json_decode($test_data, true) ?? [];
    }
    
    if (isset($test_data['is_bulk_reference']) && $test_data['is_bulk_reference'] && 
        isset($test_data['bulk_from_reference']) && isset($test_data['bulk_to_reference'])) {
        $from_ref = trim($test_data['bulk_from_reference']);
        $to_ref = trim($test_data['bulk_to_reference']);
        $bulk_key = $from_ref . '|' . $to_ref;
        
        if (!isset($grouped_bulk_routing[$bulk_key])) {
            $grouped_bulk_routing[$bulk_key] = [
                'from_reference' => $from_ref,
                'to_reference' => $to_ref,
                'references' => []
            ];
        }
        $grouped_bulk_routing[$bulk_key]['references'][] = $roll;
        $routing_processed_indices[] = $idx;
    }
}

// Second pass: Match other references to existing bulk ranges
foreach ($ready_for_routing as $idx => $roll) {
    if (in_array($idx, $routing_processed_indices)) {
        continue;
    }
    
    $sample_ref = trim($roll['sample_reference_id'] ?? '');
    if (empty($sample_ref)) {
        continue;
    }
    
    // Check if this reference falls within any existing bulk range
    $matched = false;
    foreach ($grouped_bulk_routing as $bulk_key => $bulk_group) {
        $from_ref = $bulk_group['from_reference'];
        $to_ref = $bulk_group['to_reference'];
        
        // Extract base pattern and number from references
        if (preg_match('/^(.+?)(\d+)$/', $from_ref, $from_match) && 
            preg_match('/^(.+?)(\d+)$/', $to_ref, $to_match) &&
            preg_match('/^(.+?)(\d+)$/', $sample_ref, $ref_match)) {
            
            $from_base = $from_match[1];
            $to_base = $to_match[1];
            $ref_base = $ref_match[1];
            
            if ($from_base === $to_base && $from_base === $ref_base) {
                $from_num = intval($from_match[2]);
                $to_num = intval($to_match[2]);
                $ref_num = intval($ref_match[2]);
                
                if ($ref_num >= $from_num && $ref_num <= $to_num) {
                    $grouped_bulk_routing[$bulk_key]['references'][] = $roll;
                    $routing_processed_indices[] = $idx;
                    $matched = true;
                    break;
                }
            }
        }
    }
    
    if ($matched) {
        continue;
    }
    
    // Third pass: Detect sequential patterns for new bulk groups
    $matched = false;
    foreach ($ready_for_routing as $other_idx => $other_roll) {
        if ($other_idx === $idx || in_array($other_idx, $routing_processed_indices)) {
            continue;
        }
        
        $other_ref = trim($other_roll['sample_reference_id'] ?? '');
        if (empty($other_ref)) {
            continue;
        }
        
        // Check if sequential pattern exists
        if (preg_match('/^(.+?)(\d+)$/', $sample_ref, $ref_match) &&
            preg_match('/^(.+?)(\d+)$/', $other_ref, $other_match)) {
            
            $ref_base = $ref_match[1];
            $other_base = $other_match[1];
            
            if ($ref_base === $other_base) {
                $ref_num = intval($ref_match[2]);
                $other_num = intval($other_match[2]);
                
                // If sequential (difference of 1), create a bulk group
                if (abs($ref_num - $other_num) === 1) {
                    $from_ref = $ref_num < $other_num ? $sample_ref : $other_ref;
                    $to_ref = $ref_num < $other_num ? $other_ref : $sample_ref;
                    $bulk_key = $from_ref . '|' . $to_ref;
                    
                    if (!isset($grouped_bulk_routing[$bulk_key])) {
                        $grouped_bulk_routing[$bulk_key] = [
                            'from_reference' => $from_ref,
                            'to_reference' => $to_ref,
                            'references' => []
                        ];
                    }
                    
                    if (!in_array($idx, $routing_processed_indices)) {
                        $grouped_bulk_routing[$bulk_key]['references'][] = $roll;
                        $routing_processed_indices[] = $idx;
                    }
                    if (!in_array($other_idx, $routing_processed_indices)) {
                        $grouped_bulk_routing[$bulk_key]['references'][] = $other_roll;
                        $routing_processed_indices[] = $other_idx;
                    }
                    $matched = true;
                    break;
                }
            }
        }
    }
    
    if ($matched) {
        continue;
    }
    
    // Individual reference - not part of any bulk group
    $grouped_individual_routing[] = $roll;
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
      Review and approve QC test orders. When approving a roll, select its destination: <strong>FG</strong> or <strong>Bag Production</strong>.
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
        <p style="margin:5px 0 0 0; color:#6c757d; font-size:13px;">Internal production rolls requiring QC approval and routing to FG or Bag Production</p>
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
                  $earliest_date = min(array_map(function($r) { return strtotime($r['updated_at']); }, $test_group['reports']));
                  $latest_date = max(array_map(function($r) { return strtotime($r['updated_at']); }, $test_group['reports']));
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
                    <button type="button" onclick="viewBulkReports('<?php echo htmlspecialchars($report_numbers_str); ?>')" 
                            class="btn btn-view">
                      <i class="fas fa-eye"></i> View All (<?php echo count($test_group['reports']); ?>)
                    </button>
                    <button type="button" onclick="openBulkApprovalModal('<?php echo htmlspecialchars($report_numbers_str); ?>', <?php echo count($test_group['reports']); ?>, '<?php echo htmlspecialchars($bulk_group['from']); ?>', '<?php echo htmlspecialchars($bulk_group['to']); ?>')" 
                            class="btn btn-approve">
                      <i class="fas fa-check"></i> Approve All (<?php echo count($test_group['reports']); ?>)
                    </button>
                    <button type="button" onclick="openBulkRejectModal('<?php echo htmlspecialchars($report_numbers_str); ?>', <?php echo count($test_group['reports']); ?>)" 
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
                  <th>Report No</th>
                  <th>Test Method</th>
                  <th>Inspector</th>
                  <th>Checked By</th>
                  <th>Submitted</th>
                  <th style="text-align:center;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($tests as $test): 
                  $test_data = json_decode($test['test_data'], true);
                ?>
                <tr>
                  <td style="font-weight:600; color:#333;">
                    <?php echo htmlspecialchars($test['report_number']); ?>
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
                    <a href="../admin/view_qc_test_order.php?report_number=<?php echo htmlspecialchars($test['report_number']); ?>&return=qc_test_approval_dashboard" 
                       target="_blank" class="btn btn-view">
                      <i class="fas fa-eye"></i> View
                    </a>
                    <button type="button" onclick="openApprovalModal('<?php echo htmlspecialchars($test['report_number']); ?>', '<?php echo htmlspecialchars($test['sample_reference_id']); ?>')" 
                            class="btn btn-approve">
                      <i class="fas fa-check"></i> Approve
                    </button>
                    <button type="button" onclick="openRejectModal('<?php echo htmlspecialchars($test['report_number']); ?>')" 
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
  
<!-- Rolls Ready for Routing (All Tests Approved & Not Routed) -->
<?php 
$total_routing_items = count($grouped_bulk_routing) + count($grouped_individual_routing);
if ($total_routing_items > 0): 
?>
  <div style="background:#fff; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); margin-bottom:30px; border-top:4px solid #28a745;">
    <div style="padding:20px; border-bottom:2px solid #28a745;">
      <h2 style="margin:0; font-size:20px; color:#333;">
        <i class="fas fa-check-double" style="color:#28a745;"></i> Rolls Ready for Routing
        <span class="badge" style="background:#28a745; color:#fff;"><?php echo $total_routing_items; ?> Ready</span>
      </h2>
      <p style="margin:5px 0 0 0; color:#6c757d; font-size:13px;">All QC tests approved—route the roll(s) to FG or Bag Production.</p>
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
              <th style="padding:10px; text-align:left; border-bottom:1px solid #28a745; font-weight:700;">Reference Range</th>
              <th style="padding:10px; text-align:left; border-bottom:1px solid #28a745; font-weight:700;">Count</th>
              <th style="padding:10px; text-align:left; width:420px; border-bottom:1px solid #28a745; font-weight:700;">Route</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($grouped_bulk_routing as $bulk_key => $bulk_group): 
              $all_refs = array_map(function($r) { return trim($r['sample_reference_id'] ?? ''); }, $bulk_group['references']);
              $all_refs = array_filter($all_refs);
              $unique_refs = array_unique($all_refs);
              $ref_count = count($unique_refs);
              $ref_list = implode(',', $unique_refs);
            ?>
            <tr style="border-bottom:1px solid #28a745; background:#fff;">
              <td style="padding:10px; font-weight:600; color:#0f172a;">
                <i class="fas fa-layer-group" style="color:#667eea; margin-right:5px;"></i>
                <?php echo htmlspecialchars($bulk_group['from_reference']); ?> 
                <span style="color:#6c757d;">to</span> 
                <?php echo htmlspecialchars($bulk_group['to_reference']); ?>
              </td>
              <td style="padding:10px; color:#0f172a;">
                <span class="badge" style="background:#667eea; color:#fff; padding:4px 10px; border-radius:12px; font-size:13px;">
                  <?php echo $ref_count; ?> reference(s)
                </span>
              </td>
              <td style="padding:10px;">
                <form method="POST" action="../handlers/route_roll.php" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                  <input type="hidden" name="action" value="route">
                  <input type="hidden" name="roll_reference" value="<?php echo htmlspecialchars($ref_list); ?>">
                  <input type="hidden" name="is_bulk" value="1">
                  <select name="roll_destination" required style="padding:8px; border:1px solid #d1d5db; border-radius:8px;">
                    <option value="">-- Select Destination --</option>
                    <option value="fg_production">🟢 FG Production</option>
                    <option value="bag_production">🔵 Bag Production</option>
                  </select>
                  <input type="text" name="routing_comment" placeholder="Comments (optional)" style="padding:8px; border:1px solid #d1d5db; border-radius:8px; flex:1; min-width:180px;">
                  <button type="submit" class="btn" style="background:#28a745; color:white; padding:10px 16px; border-radius:10px; border:none; font-weight:600;">
                    <i class="fas fa-route"></i> Route All (<?php echo $ref_count; ?>)
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      
      <!-- Individual References (Not in Bundle) -->
      <?php if (!empty($grouped_individual_routing)): ?>
        <h3 style="margin:0 0 15px 0; color:#333; font-size:16px;">
          <i class="fas fa-tag"></i> Individual References
        </h3>
      <table style="width:100%; border-collapse:collapse; border:1px solid #28a745; border-radius:8px; overflow:hidden;">
        <thead>
          <tr style="background:#e8f6ec; color:#0f172a;">
            <th style="padding:10px; text-align:left; border-bottom:1px solid #28a745; font-weight:700;">Reference</th>
            <th style="padding:10px; text-align:left; width:420px; border-bottom:1px solid #28a745; font-weight:700;">Route</th>
          </tr>
        </thead>
        <tbody>
            <?php foreach ($grouped_individual_routing as $roll): ?>
          <tr style="border-bottom:1px solid #28a745; background:#fff;">
              <td style="padding:10px; font-weight:600; color:#0f172a;">
                <i class="fas fa-tag" style="color:#6c757d; margin-right:5px;"></i>
                <?php echo htmlspecialchars($roll['sample_reference_id']); ?>
              </td>
            <td style="padding:10px;">
              <form method="POST" action="../handlers/route_roll.php" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="action" value="route">
                <input type="hidden" name="roll_reference" value="<?php echo htmlspecialchars($roll['sample_reference_id']); ?>">
                <select name="roll_destination" required style="padding:8px; border:1px solid #d1d5db; border-radius:8px;">
                  <option value="">-- Select Destination --</option>
                  <option value="fg_production">🟢 FG Production</option>
                  <option value="bag_production">🔵 Bag Production</option>
                </select>
                <input type="text" name="routing_comment" placeholder="Comments (optional)" style="padding:8px; border:1px solid #d1d5db; border-radius:8px; flex:1; min-width:180px;">
                <button type="submit" class="btn" style="background:#28a745; color:white; padding:10px 16px; border-radius:10px; border:none; font-weight:600;">
                  <i class="fas fa-route"></i> Route
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>
  
</div>

<!-- Production Roll Approval Modal (routing handled separately) -->
<div id="approvalModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2><i class="fas fa-check-circle"></i> Approve Production Roll QC Test</h2>
      <p style="margin:10px 0 0 0; color:#6c757d; font-size:14px;">
        Roll Reference: <strong id="approvalRollRef"></strong>
      </p>
      <p style="margin:8px 0 0 0; color:#6c757d; font-size:12px;">
        Routing will be done later in the routing page after all tests are approved.
      </p>
    </div>
    
    <form method="POST" action="../handlers/approve_qc_test.php" id="approvalForm">
      <input type="hidden" name="report_number" id="approvalReportNumber">
      <input type="hidden" name="action" value="approve">
      <input type="hidden" name="roll_destination" value="">
      
      <div class="form-group">
        <label>
          <i class="fas fa-comment"></i> Approval Comments (Optional):
        </label>
        <textarea name="admin_comment" placeholder="Add any remarks or instructions..."></textarea>
      </div>
      
      <div class="modal-actions">
        <button type="button" onclick="closeApprovalModal()" class="btn btn-cancel">
          Cancel
        </button>
        <button type="submit" class="btn btn-submit">
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
    
    <form method="POST" action="../handlers/approve_qc_test.php" id="rejectForm" onsubmit="return validateRejectionForm()">
      <input type="hidden" name="report_number" id="rejectReportNumber">
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
function viewBulkReports(reportNumbersStr) {
  const reportNumbers = reportNumbersStr.split(',');
  reportNumbers.forEach(reportNumber => {
    if (reportNumber.trim()) {
      window.open(`../admin/view_qc_test_order.php?report_number=${encodeURIComponent(reportNumber.trim())}&return=qc_test_approval_dashboard`, '_blank');
    }
  });
}

// Open bulk approval modal
function openBulkApprovalModal(reportNumbersStr, count, fromRef, toRef) {
  document.getElementById('approvalReportNumber').value = reportNumbersStr;
  document.getElementById('approvalRollRef').textContent = fromRef + ' to ' + toRef;
  document.getElementById('approvalModal').style.display = 'block';
  // Update form to handle multiple reports
  const form = document.getElementById('approvalForm');
  form.setAttribute('data-bulk-mode', 'true');
  form.setAttribute('data-report-count', count);
}

// Open bulk reject modal
function openBulkRejectModal(reportNumbersStr, count) {
  document.getElementById('rejectReportNumber').value = reportNumbersStr;
  document.getElementById('rejectModal').style.display = 'block';
  // Update form to handle multiple reports
  const form = document.getElementById('rejectForm');
  form.setAttribute('data-bulk-mode', 'true');
  form.setAttribute('data-report-count', count);
}

function openApprovalModal(reportNumber, rollRef) {
  document.getElementById('approvalReportNumber').value = reportNumber;
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

function openRejectModal(reportNumber) {
  document.getElementById('rejectReportNumber').value = reportNumber;
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

function validateRejectionForm() {
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
  
  return true;
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

