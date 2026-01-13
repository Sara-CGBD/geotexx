<?php
/**
 * Test the actual Roll Entry query to see why reference numbers aren't appearing
 */
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$is_admin = in_array($user_role, ['admin', 'agm', 'agm ops', 'agm operations']);

if (!$is_admin) {
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$reference_number = $_GET['ref'] ?? '4.0L226JAN04-R05-GT0.9.H0.1';
$results = [];

// Replicate the exact logic from roll_entry.php
$colExists = function(mysqli $conn, string $table, string $column): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    $col = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    return $res && $res->num_rows > 0;
};

// Get collation
$collation = 'utf8mb4_unicode_ci';
$colRes = $conn->query("SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME IN ('fiber_to_roll_entry', 'roll_entry') 
    AND COLUMN_NAME = 'reference_number'");
if ($colRes) {
    while ($row = $colRes->fetch_assoc()) {
        if ($row['COLLATION_NAME']) {
            $collation = $row['COLLATION_NAME'];
            break;
        }
    }
}

// Build EXISTS clauses (same as roll_entry.php)
$gsmExistsClause = '';
$lcExistsClause = '';
$rqcExistsClause = '';

$hasGsmRef = $colExists($conn, 'daily_gsm_checks', 'reference_number');
$hasGsmRoll = $colExists($conn, 'daily_gsm_checks', 'roll_no');
$hasGsmLine = $colExists($conn, 'daily_gsm_checks', 'line_number');
$hasGsmStatus = $colExists($conn, 'daily_gsm_checks', 'status');
if ($hasGsmRef && $hasGsmRoll && $hasGsmStatus) {
    $gsmLineCond = $hasGsmLine ? "AND dgc.line_number COLLATE {$collation} = CONCAT('Line ', ftr.line_no) COLLATE {$collation}" : "";
    $gsmExistsClause = "
    AND EXISTS (
        SELECT 1 FROM daily_gsm_checks dgc 
        WHERE dgc.reference_number COLLATE {$collation} = ftr.reference_number COLLATE {$collation} 
        AND dgc.roll_no = ftr.roll_no 
        {$gsmLineCond}
        AND dgc.status = 'approved'
    )";
    $results['gsm_check'] = ['exists' => true, 'clause' => $gsmExistsClause];
} else {
    $results['gsm_check'] = ['exists' => false, 'missing' => []];
    if (!$hasGsmRef) $results['gsm_check']['missing'][] = 'reference_number';
    if (!$hasGsmRoll) $results['gsm_check']['missing'][] = 'roll_no';
    if (!$hasGsmStatus) $results['gsm_check']['missing'][] = 'status';
}

$hasLcRef = $colExists($conn, 'length_calibrations', 'reference_number');
$hasLcRoll = $colExists($conn, 'length_calibrations', 'roll_no');
$hasLcLine = $colExists($conn, 'length_calibrations', 'line_number');
$hasLcStatus = $colExists($conn, 'length_calibrations', 'status');
if ($hasLcRef && $hasLcRoll && $hasLcStatus) {
    $lcLineCond = $hasLcLine ? "AND lc.line_number COLLATE {$collation} = CONCAT('Line ', ftr.line_no) COLLATE {$collation}" : "";
    $lcExistsClause = "
    AND EXISTS (
        SELECT 1 FROM length_calibrations lc 
        WHERE lc.reference_number COLLATE {$collation} = ftr.reference_number COLLATE {$collation} 
        AND lc.roll_no = ftr.roll_no 
        {$lcLineCond}
        AND lc.status = 'approved'
    )";
    $results['length_calibration'] = ['exists' => true, 'clause' => $lcExistsClause];
} else {
    $results['length_calibration'] = ['exists' => false, 'missing' => []];
    if (!$hasLcRef) $results['length_calibration']['missing'][] = 'reference_number';
    if (!$hasLcRoll) $results['length_calibration']['missing'][] = 'roll_no';
    if (!$hasLcStatus) $results['length_calibration']['missing'][] = 'status';
}

// Check for approved Roll QC Reports
$hasRqcRef = $colExists($conn, 'roll_qc_reports', 'reference_number');
$hasRqcApproved = $colExists($conn, 'roll_qc_reports', 'approved');
$hasRqcOverallStatus = $colExists($conn, 'roll_qc_reports', 'overall_status');

if ($hasRqcRef) {
    if ($hasRqcApproved && $hasRqcOverallStatus) {
        // If both approved column and overall_status exist, check for approved = 1 OR overall_status IN ('approved', 'Done')
        $rqcExistsClause = "
        AND EXISTS (
            SELECT 1 FROM roll_qc_reports rqc 
            WHERE rqc.reference_number COLLATE {$collation} = ftr.reference_number COLLATE {$collation}
            AND (rqc.approved = 1 OR rqc.overall_status IN ('approved', 'Done'))
        )";
        $results['roll_qc_report'] = ['exists' => true, 'using' => 'approved column OR overall_status (approved/Done)', 'clause' => $rqcExistsClause];
    } elseif ($hasRqcApproved) {
        $rqcExistsClause = "
        AND EXISTS (
            SELECT 1 FROM roll_qc_reports rqc 
            WHERE rqc.reference_number COLLATE {$collation} = ftr.reference_number COLLATE {$collation}
            AND rqc.approved = 1
        )";
        $results['roll_qc_report'] = ['exists' => true, 'using' => 'approved column', 'clause' => $rqcExistsClause];
    } elseif ($hasRqcOverallStatus) {
        $rqcExistsClause = "
        AND EXISTS (
            SELECT 1 FROM roll_qc_reports rqc 
            WHERE rqc.reference_number COLLATE {$collation} = ftr.reference_number COLLATE {$collation}
            AND rqc.overall_status IN ('approved', 'Done')
        )";
        $results['roll_qc_report'] = ['exists' => true, 'using' => 'overall_status column (approved/Done)', 'clause' => $rqcExistsClause];
    } else {
        $results['roll_qc_report'] = ['exists' => false, 'missing' => 'No approval columns found'];
    }
} else {
    $results['roll_qc_report'] = ['exists' => false, 'missing' => 'reference_number column not found'];
}

// Build the full query
$fullQuery = "
    SELECT 
        ftr.id, 
        ftr.reference_number, 
        ftr.material_type,
        ftr.roll_no,
        ftr.line_no,
        ftr.total_weight as original_weight,
        COALESCE(SUM(re.total_weight), 0) as used_weight,
        (ftr.total_weight - COALESCE(SUM(re.total_weight), 0)) as available_weight
    FROM fiber_to_roll_entry ftr
    LEFT JOIN roll_entry re ON (
        ftr.reference_number COLLATE {$collation} = re.reference_number COLLATE {$collation}
        OR re.reference_number COLLATE {$collation} LIKE CONCAT(ftr.reference_number COLLATE {$collation}, '-%')
    )
    WHERE ftr.reference_number = '{$reference_number}'
    {$gsmExistsClause}
    {$lcExistsClause}
    {$rqcExistsClause}
    GROUP BY ftr.id, ftr.reference_number, ftr.material_type, ftr.roll_no, ftr.line_no, ftr.total_weight
    HAVING available_weight > 0
    ORDER BY ftr.created_at DESC
";

$results['full_query'] = $fullQuery;

// Test the query
$testResult = $conn->query($fullQuery);
$results['query_executed'] = $testResult !== false;
if ($testResult) {
    $results['rows_returned'] = $testResult->num_rows;
    $results['data'] = [];
    while ($row = $testResult->fetch_assoc()) {
        $results['data'][] = $row;
    }
} else {
    $results['query_error'] = $conn->error;
}

// Test without the EXISTS clauses to see if the reference exists at all
$simpleQuery = "
    SELECT 
        ftr.id, 
        ftr.reference_number, 
        ftr.roll_no,
        ftr.line_no,
        ftr.total_weight as original_weight,
        COALESCE(SUM(re.total_weight), 0) as used_weight,
        (ftr.total_weight - COALESCE(SUM(re.total_weight), 0)) as available_weight
    FROM fiber_to_roll_entry ftr
    LEFT JOIN roll_entry re ON (
        ftr.reference_number COLLATE {$collation} = re.reference_number COLLATE {$collation}
        OR re.reference_number COLLATE {$collation} LIKE CONCAT(ftr.reference_number COLLATE {$collation}, '-%')
    )
    WHERE ftr.reference_number = '{$reference_number}'
    GROUP BY ftr.id, ftr.reference_number, ftr.roll_no, ftr.line_no, ftr.total_weight
    HAVING available_weight > 0
";

$simpleResult = $conn->query($simpleQuery);
$results['simple_query'] = [
    'executed' => $simpleResult !== false,
    'rows' => $simpleResult ? $simpleResult->num_rows : 0,
    'error' => $simpleResult ? null : $conn->error
];

if ($simpleResult && $simpleResult->num_rows > 0) {
    $results['simple_query']['data'] = $simpleResult->fetch_assoc();
}

// Check individual conditions for this specific reference
$ftrCheck = $conn->query("SELECT * FROM fiber_to_roll_entry WHERE reference_number = '{$reference_number}' LIMIT 1");
if ($ftrCheck && $ftrCheck->num_rows > 0) {
    $ftrData = $ftrCheck->fetch_assoc();
    $results['fiber_to_roll_entry'] = $ftrData;
    
    // Check GSM
    $gsmCheck = $conn->query("SELECT * FROM daily_gsm_checks 
                              WHERE reference_number = '{$reference_number}' 
                              AND roll_no = '{$ftrData['roll_no']}' 
                              AND status = 'approved' 
                              LIMIT 1");
    $results['gsm_actual'] = [
        'found' => $gsmCheck && $gsmCheck->num_rows > 0,
        'data' => $gsmCheck && $gsmCheck->num_rows > 0 ? $gsmCheck->fetch_assoc() : null
    ];
    
    // Check Length Calibration
    $lcCheck = $conn->query("SELECT * FROM length_calibrations 
                             WHERE reference_number = '{$reference_number}' 
                             AND roll_no = '{$ftrData['roll_no']}' 
                             AND status = 'approved' 
                             LIMIT 1");
    $results['lc_actual'] = [
        'found' => $lcCheck && $lcCheck->num_rows > 0,
        'data' => $lcCheck && $lcCheck->num_rows > 0 ? $lcCheck->fetch_assoc() : null
    ];
    
    // Check Roll QC Report
    $rqcCheck = $conn->query("SELECT * FROM roll_qc_reports 
                              WHERE reference_number = '{$reference_number}' 
                              LIMIT 1");
    if ($rqcCheck && $rqcCheck->num_rows > 0) {
        $rqcData = $rqcCheck->fetch_assoc();
        $isApproved = ($rqcData['approved'] ?? 0) == 1;
        $overallStatus = $rqcData['overall_status'] ?? 'N/A';
        $isDone = in_array(strtolower($overallStatus), ['approved', 'done']);
        $results['rqc_actual'] = [
            'found' => true,
            'approved' => $isApproved || $isDone,
            'approved_column' => $isApproved,
            'overall_status' => $overallStatus,
            'overall_status_approved' => $isDone,
            'data' => $rqcData
        ];
    } else {
        $results['rqc_actual'] = ['found' => false];
    }
} else {
    $results['fiber_to_roll_entry'] = null;
    $results['error'] = "Reference number not found in fiber_to_roll_entry";
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial.0">
    <title>Test Roll Entry Query</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1400px;
            margin: 20px auto;
            padding: 20px;
            background: #f4f6f9;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 { color: #2c3e50; margin-bottom: 20px; }
        h2 { color: #34495e; margin-top: 30px; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
        .status-ok { color: #28a745; font-weight: 600; }
        .status-fail { color: #dc3545; font-weight: 600; }
        .status-warn { color: #ffc107; font-weight: 600; }
        pre {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
            border-left: 4px solid #007bff;
        }
        .section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #6c757d;
        }
        .section-ok { border-left-color: #28a745; }
        .section-fail { border-left-color: #dc3545; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th { background: #e9ecef; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Test Roll Entry Query</h1>
        <p><strong>Testing Reference:</strong> <?php echo htmlspecialchars($reference_number); ?></p>
        
        <h2>Query Conditions Status</h2>
        
        <div class="section <?php echo $results['gsm_check']['exists'] ? 'section-ok' : 'section-fail'; ?>">
            <h3>1. Daily GSM Check</h3>
            <?php if ($results['gsm_check']['exists']): ?>
                <p class="status-ok">✓ Condition will be checked</p>
                <pre><?php echo htmlspecialchars($results['gsm_check']['clause']); ?></pre>
            <?php else: ?>
                <p class="status-fail">✗ Condition will NOT be checked (missing columns: <?php echo implode(', ', $results['gsm_check']['missing']); ?>)</p>
            <?php endif; ?>
        </div>
        
        <div class="section <?php echo $results['length_calibration']['exists'] ? 'section-ok' : 'section-fail'; ?>">
            <h3>2. Length Calibration</h3>
            <?php if ($results['length_calibration']['exists']): ?>
                <p class="status-ok">✓ Condition will be checked</p>
                <pre><?php echo htmlspecialchars($results['length_calibration']['clause']); ?></pre>
            <?php else: ?>
                <p class="status-fail">✗ Condition will NOT be checked (missing columns: <?php echo implode(', ', $results['length_calibration']['missing']); ?>)</p>
            <?php endif; ?>
        </div>
        
        <div class="section <?php echo $results['roll_qc_report']['exists'] ? 'section-ok' : 'section-fail'; ?>">
            <h3>3. Roll QC Report</h3>
            <?php if ($results['roll_qc_report']['exists']): ?>
                <p class="status-ok">✓ Condition will be checked (using: <?php echo $results['roll_qc_report']['using']; ?>)</p>
                <pre><?php echo htmlspecialchars($results['roll_qc_report']['clause']); ?></pre>
            <?php else: ?>
                <p class="status-fail">✗ Condition will NOT be checked: <?php echo htmlspecialchars($results['roll_qc_report']['missing']); ?></p>
            <?php endif; ?>
        </div>
        
        <h2>Actual Data Check</h2>
        
        <?php if (isset($results['fiber_to_roll_entry'])): ?>
            <div class="section">
                <h3>Fiber to Roll Entry</h3>
                <p class="status-ok">✓ Found</p>
                <pre><?php echo htmlspecialchars(json_encode($results['fiber_to_roll_entry'], JSON_PRETTY_PRINT)); ?></pre>
            </div>
            
            <div class="section <?php echo $results['gsm_actual']['found'] ? 'section-ok' : 'section-fail'; ?>">
                <h3>GSM Check (Actual)</h3>
                <?php if ($results['gsm_actual']['found']): ?>
                    <p class="status-ok">✓ Found and approved</p>
                <?php else: ?>
                    <p class="status-fail">✗ NOT found or NOT approved</p>
                <?php endif; ?>
            </div>
            
            <div class="section <?php echo $results['lc_actual']['found'] ? 'section-ok' : 'section-fail'; ?>">
                <h3>Length Calibration (Actual)</h3>
                <?php if ($results['lc_actual']['found']): ?>
                    <p class="status-ok">✓ Found and approved</p>
                <?php else: ?>
                    <p class="status-fail">✗ NOT found or NOT approved</p>
                <?php endif; ?>
            </div>
            
            <div class="section <?php echo ($results['rqc_actual']['found'] && $results['rqc_actual']['approved']) ? 'section-ok' : 'section-fail'; ?>">
                <h3>Roll QC Report (Actual)</h3>
                <?php if ($results['rqc_actual']['found']): ?>
                    <?php if ($results['rqc_actual']['approved']): ?>
                        <p class="status-ok">✓ Found and approved</p>
                        <?php if ($results['rqc_actual']['approved_column']): ?>
                            <p style="color: #28a745;">  → Approved via 'approved' column</p>
                        <?php endif; ?>
                        <?php if ($results['rqc_actual']['overall_status_approved']): ?>
                            <p style="color: #28a745;">  → Approved via 'overall_status' = '<?php echo $results['rqc_actual']['overall_status']; ?>'</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="status-fail">✗ Found but NOT approved (approved: <?php echo $results['rqc_actual']['data']['approved'] ?? 0; ?>, overall_status: <?php echo $results['rqc_actual']['overall_status']; ?>)</p>
                    <?php endif; ?>
                    <pre><?php echo htmlspecialchars(json_encode($results['rqc_actual']['data'], JSON_PRETTY_PRINT)); ?></pre>
                <?php else: ?>
                    <p class="status-fail">✗ NOT found</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="section section-fail">
                <p class="status-fail">✗ Reference number not found in fiber_to_roll_entry</p>
            </div>
        <?php endif; ?>
        
        <h2>Query Results</h2>
        
        <div class="section <?php echo $results['simple_query']['rows'] > 0 ? 'section-ok' : 'section-fail'; ?>">
            <h3>Simple Query (Without EXISTS clauses)</h3>
            <p>Rows returned: <strong><?php echo $results['simple_query']['rows']; ?></strong></p>
            <?php if ($results['simple_query']['rows'] > 0): ?>
                <p class="status-ok">✓ Reference exists and has available weight</p>
                <pre><?php echo htmlspecialchars(json_encode($results['simple_query']['data'], JSON_PRETTY_PRINT)); ?></pre>
            <?php else: ?>
                <p class="status-fail">✗ Reference not found or no available weight</p>
                <?php if ($results['simple_query']['error']): ?>
                    <p class="status-fail">Error: <?php echo htmlspecialchars($results['simple_query']['error']); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <div class="section <?php echo $results['rows_returned'] > 0 ? 'section-ok' : 'section-fail'; ?>">
            <h3>Full Query (With all EXISTS clauses)</h3>
            <p>Rows returned: <strong><?php echo $results['rows_returned'] ?? 0; ?></strong></p>
            <?php if ($results['rows_returned'] > 0): ?>
                <p class="status-ok">✓ Reference appears in dropdown!</p>
                <pre><?php echo htmlspecialchars(json_encode($results['data'], JSON_PRETTY_PRINT)); ?></pre>
            <?php else: ?>
                <p class="status-fail">✗ Reference does NOT appear in dropdown</p>
                <?php if (isset($results['query_error'])): ?>
                    <p class="status-fail">Error: <?php echo htmlspecialchars($results['query_error']); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <h2>Full SQL Query</h2>
        <pre><?php echo htmlspecialchars($results['full_query']); ?></pre>
        
        <div style="margin-top: 30px;">
            <a href="../index.php" style="padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 4px;">← Back to Dashboard</a>
        </div>
    </div>
</body>
</html>

