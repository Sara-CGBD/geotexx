<?php
/**
 * Debug script to test QC status check for a specific reference
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

$refNumber = $_GET['ref'] ?? '4.0L226JAN04-R05-GT0.9.H0.1';
$results = [];

// Get reference details from fiber_to_roll_entry
$ftrStmt = $conn->prepare("SELECT * FROM fiber_to_roll_entry WHERE reference_number = ? LIMIT 1");
$ftrStmt->bind_param("s", $refNumber);
$ftrStmt->execute();
$ftrResult = $ftrStmt->get_result();
$ftrData = $ftrResult->fetch_assoc();
$ftrStmt->close();

if ($ftrData) {
    $rollNo = $ftrData['roll_no'];
    $lineNo = $ftrData['line_no'];
    
    $results['fiber_to_roll_entry'] = $ftrData;
    $results['roll_no'] = $rollNo;
    $results['line_no'] = $lineNo;
    
    // Prepare line variants (same as check_qc_status.php)
    $lineNumberRaw = $lineNo;
    $lineNumberPrefix = is_numeric($lineNo) ? "Line " . $lineNo : $lineNo;
    $lineNumberNumeric = is_numeric($lineNo) ? (string)(int)$lineNo : $lineNo;
    
    $results['line_variants'] = [
        'raw' => $lineNumberRaw,
        'prefix' => $lineNumberPrefix,
        'numeric' => $lineNumberNumeric
    ];
    
    // Check Daily GSM Checks - ALL records first
    $allGsmStmt = $conn->prepare("SELECT id, reference_number, roll_no, line_number, status, approved_at, created_at 
                                   FROM daily_gsm_checks 
                                   WHERE reference_number = ? AND roll_no = ? 
                                   ORDER BY created_at DESC");
    $allGsmStmt->bind_param('ss', $refNumber, $rollNo);
    $allGsmStmt->execute();
    $allGsmResult = $allGsmStmt->get_result();
    $results['gsm_all_records'] = [];
    while ($row = $allGsmResult->fetch_assoc()) {
        $results['gsm_all_records'][] = $row;
    }
    $allGsmStmt->close();
    
    // Check Daily GSM Checks - with line_number matching
    $gsmStmt = $conn->prepare("SELECT id, reference_number, roll_no, line_number, status, approved_at, created_at 
                                FROM daily_gsm_checks 
                                WHERE reference_number = ? 
                                AND roll_no = ? 
                                AND (line_number = ? OR line_number = ? OR line_number = ?) 
                                ORDER BY created_at DESC");
    $gsmStmt->bind_param('sssss', $refNumber, $rollNo, $lineNumberRaw, $lineNumberPrefix, $lineNumberNumeric);
    $gsmStmt->execute();
    $gsmResult = $gsmStmt->get_result();
    $results['gsm_matching_records'] = [];
    while ($row = $gsmResult->fetch_assoc()) {
        $results['gsm_matching_records'][] = $row;
    }
    $gsmStmt->close();
    
    // Check Daily GSM Checks - approved only
    $gsmApprovedStmt = $conn->prepare("SELECT id, reference_number, roll_no, line_number, status, approved_at, created_at 
                                        FROM daily_gsm_checks 
                                        WHERE reference_number = ? 
                                        AND roll_no = ? 
                                        AND (line_number = ? OR line_number = ? OR line_number = ?) 
                                        AND status = 'approved' 
                                        ORDER BY created_at DESC");
    $gsmApprovedStmt->bind_param('sssss', $refNumber, $rollNo, $lineNumberRaw, $lineNumberPrefix, $lineNumberNumeric);
    $gsmApprovedStmt->execute();
    $gsmApprovedResult = $gsmApprovedStmt->get_result();
    $results['gsm_approved_records'] = [];
    while ($row = $gsmApprovedResult->fetch_assoc()) {
        $results['gsm_approved_records'][] = $row;
    }
    $gsmApprovedStmt->close();
    
    // Check Length Calibrations - ALL records first
    $allLcStmt = $conn->prepare("SELECT id, reference_number, roll_no, line_number, status, approved_at, created_at 
                                  FROM length_calibrations 
                                  WHERE reference_number = ? AND roll_no = ? 
                                  ORDER BY created_at DESC");
    $allLcStmt->bind_param('ss', $refNumber, $rollNo);
    $allLcStmt->execute();
    $allLcResult = $allLcStmt->get_result();
    $results['lc_all_records'] = [];
    while ($row = $allLcResult->fetch_assoc()) {
        $results['lc_all_records'][] = $row;
    }
    $allLcStmt->close();
    
    // Check Length Calibrations - with line_number matching
    $lcStmt = $conn->prepare("SELECT id, reference_number, roll_no, line_number, status, approved_at, created_at 
                               FROM length_calibrations 
                               WHERE reference_number = ? 
                               AND roll_no = ? 
                               AND (line_number = ? OR line_number = ? OR line_number = ?) 
                               ORDER BY created_at DESC");
    $lcStmt->bind_param('sssss', $refNumber, $rollNo, $lineNumberRaw, $lineNumberPrefix, $lineNumberNumeric);
    $lcStmt->execute();
    $lcResult = $lcStmt->get_result();
    $results['lc_matching_records'] = [];
    while ($row = $lcResult->fetch_assoc()) {
        $results['lc_matching_records'][] = $row;
    }
    $lcStmt->close();
    
    // Check Length Calibrations - approved only
    $lcApprovedStmt = $conn->prepare("SELECT id, reference_number, roll_no, line_number, status, approved_at, created_at 
                                       FROM length_calibrations 
                                       WHERE reference_number = ? 
                                       AND roll_no = ? 
                                       AND (line_number = ? OR line_number = ? OR line_number = ?) 
                                       AND status = 'approved' 
                                       ORDER BY created_at DESC");
    $lcApprovedStmt->bind_param('sssss', $refNumber, $rollNo, $lineNumberRaw, $lineNumberPrefix, $lineNumberNumeric);
    $lcApprovedStmt->execute();
    $lcApprovedResult = $lcApprovedStmt->get_result();
    $results['lc_approved_records'] = [];
    while ($row = $lcApprovedResult->fetch_assoc()) {
        $results['lc_approved_records'][] = $row;
    }
    $lcApprovedStmt->close();
    
    // Test the actual query from check_qc_status.php
    $hasUpdatedAtGsm = false;
    $colCheck = $conn->query("SHOW COLUMNS FROM daily_gsm_checks LIKE 'updated_at'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $hasUpdatedAtGsm = true;
    }
    
    if ($hasUpdatedAtGsm) {
        $testGsmQuery = "SELECT 
            MAX(COALESCE(approved_at, updated_at, created_at)) as last_date,
            MAX(status) as test_status
            FROM daily_gsm_checks 
            WHERE reference_number = ? 
            AND roll_no = ? 
            AND (line_number = ? OR line_number = ? OR line_number = ?) 
            AND status = 'approved' 
            LIMIT 1";
    } else {
        $testGsmQuery = "SELECT 
            MAX(COALESCE(approved_at, created_at)) as last_date,
            MAX(status) as test_status
            FROM daily_gsm_checks 
            WHERE reference_number = ? 
            AND roll_no = ? 
            AND (line_number = ? OR line_number = ? OR line_number = ?) 
            AND status = 'approved' 
            LIMIT 1";
    }
    
    $testGsmStmt = $conn->prepare($testGsmQuery);
    $testGsmStmt->bind_param('sssss', $refNumber, $rollNo, $lineNumberRaw, $lineNumberPrefix, $lineNumberNumeric);
    $testGsmStmt->execute();
    $testGsmResult = $testGsmStmt->get_result();
    $results['gsm_query_result'] = $testGsmResult->fetch_assoc();
    $testGsmStmt->close();
    
    // Test Length Calibration query
    $hasUpdatedAtLc = false;
    $colCheck = $conn->query("SHOW COLUMNS FROM length_calibrations LIKE 'updated_at'");
    if ($colCheck && $colCheck->num_rows > 0) {
        $hasUpdatedAtLc = true;
    }
    
    if ($hasUpdatedAtLc) {
        $testLcQuery = "SELECT 
            MAX(COALESCE(approved_at, updated_at, created_at)) as last_date,
            MAX(status) as test_status
            FROM length_calibrations 
            WHERE reference_number = ? 
            AND roll_no = ? 
            AND (line_number = ? OR line_number = ? OR line_number = ?) 
            AND status = 'approved' 
            LIMIT 1";
    } else {
        $testLcQuery = "SELECT 
            MAX(COALESCE(approved_at, created_at)) as last_date,
            MAX(status) as test_status
            FROM length_calibrations 
            WHERE reference_number = ? 
            AND roll_no = ? 
            AND (line_number = ? OR line_number = ? OR line_number = ?) 
            AND status = 'approved' 
            LIMIT 1";
    }
    
    $testLcStmt = $conn->prepare($testLcQuery);
    $testLcStmt->bind_param('sssss', $refNumber, $rollNo, $lineNumberRaw, $lineNumberPrefix, $lineNumberNumeric);
    $testLcStmt->execute();
    $testLcResult = $testLcStmt->get_result();
    $results['lc_query_result'] = $testLcResult->fetch_assoc();
    $testLcStmt->close();
    
} else {
    $results['error'] = "Reference number not found in fiber_to_roll_entry";
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test QC Status Check</title>
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
        .section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #6c757d;
        }
        .section-ok { border-left-color: #28a745; }
        .section-fail { border-left-color: #dc3545; }
        pre {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
            border-left: 4px solid #007bff;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            font-size: 12px;
        }
        th { background: #e9ecef; }
        .status-ok { color: #28a745; font-weight: 600; }
        .status-fail { color: #dc3545; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Test QC Status Check</h1>
        <p><strong>Reference:</strong> <?php echo htmlspecialchars($refNumber); ?></p>
        
        <?php if (isset($results['error'])): ?>
            <div class="section section-fail">
                <p class="status-fail"><?php echo htmlspecialchars($results['error']); ?></p>
            </div>
        <?php else: ?>
            
            <h2>Reference Details</h2>
            <div class="section">
                <pre><?php echo htmlspecialchars(json_encode($results['fiber_to_roll_entry'], JSON_PRETTY_PRINT)); ?></pre>
                <p><strong>Roll No:</strong> <?php echo htmlspecialchars($results['roll_no']); ?></p>
                <p><strong>Line No:</strong> <?php echo htmlspecialchars($results['line_no']); ?></p>
                <p><strong>Line Variants:</strong></p>
                <ul>
                    <li>Raw: <?php echo htmlspecialchars($results['line_variants']['raw']); ?></li>
                    <li>Prefix: <?php echo htmlspecialchars($results['line_variants']['prefix']); ?></li>
                    <li>Numeric: <?php echo htmlspecialchars($results['line_variants']['numeric']); ?></li>
                </ul>
            </div>
            
            <h2>Daily GSM Checks</h2>
            
            <div class="section">
                <h3>All Records (reference_number + roll_no only)</h3>
                <?php if (empty($results['gsm_all_records'])): ?>
                    <p class="status-fail">✗ No records found</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Reference</th>
                                <th>Roll No</th>
                                <th>Line Number</th>
                                <th>Status</th>
                                <th>Approved At</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results['gsm_all_records'] as $record): ?>
                                <tr>
                                    <td><?php echo $record['id']; ?></td>
                                    <td><?php echo htmlspecialchars($record['reference_number']); ?></td>
                                    <td><?php echo htmlspecialchars($record['roll_no']); ?></td>
                                    <td><?php echo htmlspecialchars($record['line_number'] ?? 'NULL'); ?></td>
                                    <td class="<?php echo $record['status'] === 'approved' ? 'status-ok' : 'status-fail'; ?>">
                                        <?php echo htmlspecialchars($record['status']); ?>
                                    </td>
                                    <td><?php echo $record['approved_at'] ?? 'NULL'; ?></td>
                                    <td><?php echo $record['created_at']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <div class="section">
                <h3>Matching Records (with line_number matching)</h3>
                <?php if (empty($results['gsm_matching_records'])): ?>
                    <p class="status-fail">✗ No matching records found (line_number mismatch?)</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Line Number</th>
                                <th>Status</th>
                                <th>Approved At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results['gsm_matching_records'] as $record): ?>
                                <tr>
                                    <td><?php echo $record['id']; ?></td>
                                    <td><?php echo htmlspecialchars($record['line_number'] ?? 'NULL'); ?></td>
                                    <td class="<?php echo $record['status'] === 'approved' ? 'status-ok' : 'status-fail'; ?>">
                                        <?php echo htmlspecialchars($record['status']); ?>
                                    </td>
                                    <td><?php echo $record['approved_at'] ?? 'NULL'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <div class="section <?php echo !empty($results['gsm_approved_records']) ? 'section-ok' : 'section-fail'; ?>">
                <h3>Approved Records Only</h3>
                <?php if (empty($results['gsm_approved_records'])): ?>
                    <p class="status-fail">✗ No approved records found</p>
                <?php else: ?>
                    <p class="status-ok">✓ Found <?php echo count($results['gsm_approved_records']); ?> approved record(s)</p>
                    <pre><?php echo htmlspecialchars(json_encode($results['gsm_approved_records'], JSON_PRETTY_PRINT)); ?></pre>
                <?php endif; ?>
            </div>
            
            <div class="section">
                <h3>Query Result (from check_qc_status.php logic)</h3>
                <pre><?php echo htmlspecialchars(json_encode($results['gsm_query_result'], JSON_PRETTY_PRINT)); ?></pre>
                <?php if ($results['gsm_query_result'] && $results['gsm_query_result']['test_status'] === 'approved'): ?>
                    <p class="status-ok">✓ GSM Check should show as APPROVED</p>
                <?php else: ?>
                    <p class="status-fail">✗ GSM Check will show as PENDING</p>
                <?php endif; ?>
            </div>
            
            <h2>Length Calibrations</h2>
            
            <div class="section">
                <h3>All Records (reference_number + roll_no only)</h3>
                <?php if (empty($results['lc_all_records'])): ?>
                    <p class="status-fail">✗ No records found</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Reference</th>
                                <th>Roll No</th>
                                <th>Line Number</th>
                                <th>Status</th>
                                <th>Approved At</th>
                                <th>Created At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results['lc_all_records'] as $record): ?>
                                <tr>
                                    <td><?php echo $record['id']; ?></td>
                                    <td><?php echo htmlspecialchars($record['reference_number']); ?></td>
                                    <td><?php echo htmlspecialchars($record['roll_no']); ?></td>
                                    <td><?php echo htmlspecialchars($record['line_number'] ?? 'NULL'); ?></td>
                                    <td class="<?php echo $record['status'] === 'approved' ? 'status-ok' : 'status-fail'; ?>">
                                        <?php echo htmlspecialchars($record['status']); ?>
                                    </td>
                                    <td><?php echo $record['approved_at'] ?? 'NULL'; ?></td>
                                    <td><?php echo $record['created_at']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <div class="section">
                <h3>Matching Records (with line_number matching)</h3>
                <?php if (empty($results['lc_matching_records'])): ?>
                    <p class="status-fail">✗ No matching records found (line_number mismatch?)</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Line Number</th>
                                <th>Status</th>
                                <th>Approved At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results['lc_matching_records'] as $record): ?>
                                <tr>
                                    <td><?php echo $record['id']; ?></td>
                                    <td><?php echo htmlspecialchars($record['line_number'] ?? 'NULL'); ?></td>
                                    <td class="<?php echo $record['status'] === 'approved' ? 'status-ok' : 'status-fail'; ?>">
                                        <?php echo htmlspecialchars($record['status']); ?>
                                    </td>
                                    <td><?php echo $record['approved_at'] ?? 'NULL'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <div class="section <?php echo !empty($results['lc_approved_records']) ? 'section-ok' : 'section-fail'; ?>">
                <h3>Approved Records Only</h3>
                <?php if (empty($results['lc_approved_records'])): ?>
                    <p class="status-fail">✗ No approved records found</p>
                <?php else: ?>
                    <p class="status-ok">✓ Found <?php echo count($results['lc_approved_records']); ?> approved record(s)</p>
                    <pre><?php echo htmlspecialchars(json_encode($results['lc_approved_records'], JSON_PRETTY_PRINT)); ?></pre>
                <?php endif; ?>
            </div>
            
            <div class="section">
                <h3>Query Result (from check_qc_status.php logic)</h3>
                <pre><?php echo htmlspecialchars(json_encode($results['lc_query_result'], JSON_PRETTY_PRINT)); ?></pre>
                <?php if ($results['lc_query_result'] && $results['lc_query_result']['test_status'] === 'approved'): ?>
                    <p class="status-ok">✓ Length Calibration should show as APPROVED</p>
                <?php else: ?>
                    <p class="status-fail">✗ Length Calibration will show as PENDING</p>
                <?php endif; ?>
            </div>
            
        <?php endif; ?>
        
        <div style="margin-top: 30px;">
            <a href="../index.php" style="padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 4px;">← Back to Dashboard</a>
        </div>
    </div>
</body>
</html>

