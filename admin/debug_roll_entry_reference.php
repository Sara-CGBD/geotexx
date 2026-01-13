<?php
/**
 * Debug script to check why a reference number is not appearing in Roll Entry dropdown
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

$reference_number = $_GET['ref'] ?? $_POST['ref'] ?? '';
$debug_info = [];

if (!empty($reference_number)) {
    // Get reference from fiber_to_roll_entry
    $ftrStmt = $conn->prepare("SELECT * FROM fiber_to_roll_entry WHERE reference_number = ? LIMIT 1");
    $ftrStmt->bind_param("s", $reference_number);
    $ftrStmt->execute();
    $ftrResult = $ftrStmt->get_result();
    $ftrData = $ftrResult->fetch_assoc();
    $ftrStmt->close();
    
    if ($ftrData) {
        $debug_info['fiber_to_roll_entry'] = $ftrData;
        
        // Check Daily GSM Check
        $gsmStmt = $conn->prepare("SELECT * FROM daily_gsm_checks 
                                   WHERE reference_number = ? AND roll_no = ? 
                                   ORDER BY created_at DESC LIMIT 1");
        $gsmStmt->bind_param("ss", $reference_number, $ftrData['roll_no']);
        $gsmStmt->execute();
        $gsmResult = $gsmStmt->get_result();
        $gsmData = $gsmResult->fetch_assoc();
        $gsmStmt->close();
        
        $debug_info['gsm_check'] = [
            'exists' => !empty($gsmData),
            'status' => $gsmData['status'] ?? 'N/A',
            'approved' => ($gsmData['status'] ?? '') === 'approved',
            'data' => $gsmData
        ];
        
        // Check Length Calibration
        $lcStmt = $conn->prepare("SELECT * FROM length_calibrations 
                                  WHERE reference_number = ? AND roll_no = ? 
                                  ORDER BY created_at DESC LIMIT 1");
        $lcStmt->bind_param("ss", $reference_number, $ftrData['roll_no']);
        $lcStmt->execute();
        $lcResult = $lcStmt->get_result();
        $lcData = $lcResult->fetch_assoc();
        $lcStmt->close();
        
        $debug_info['length_calibration'] = [
            'exists' => !empty($lcData),
            'status' => $lcData['status'] ?? 'N/A',
            'approved' => ($lcData['status'] ?? '') === 'approved',
            'data' => $lcData
        ];
        
        // Check Roll QC Report
        $rqcStmt = $conn->prepare("SELECT * FROM roll_qc_reports 
                                   WHERE reference_number = ? 
                                   ORDER BY created_at DESC LIMIT 1");
        $rqcStmt->bind_param("s", $reference_number);
        $rqcStmt->execute();
        $rqcResult = $rqcStmt->get_result();
        $rqcData = $rqcResult->fetch_assoc();
        $rqcStmt->close();
        
        $debug_info['roll_qc_report'] = [
            'exists' => !empty($rqcData),
            'approved' => ($rqcData['approved'] ?? 0) == 1,
            'overall_status' => $rqcData['overall_status'] ?? 'N/A',
            'approved_by' => $rqcData['approved_by'] ?? 'N/A',
            'data' => $rqcData
        ];
        
        // Check used weight
        $usedStmt = $conn->prepare("SELECT COALESCE(SUM(total_weight), 0) as used_weight 
                                    FROM roll_entry 
                                    WHERE reference_number = ? 
                                    OR reference_number LIKE CONCAT(?, '-%')");
        $usedStmt->bind_param("ss", $reference_number, $reference_number);
        $usedStmt->execute();
        $usedResult = $usedStmt->get_result();
        $usedData = $usedResult->fetch_assoc();
        $usedStmt->close();
        
        $available_weight = $ftrData['total_weight'] - ($usedData['used_weight'] ?? 0);
        
        $debug_info['weight'] = [
            'original' => $ftrData['total_weight'],
            'used' => $usedData['used_weight'] ?? 0,
            'available' => $available_weight,
            'has_available' => $available_weight > 0
        ];
        
        // Final verdict
        $debug_info['all_conditions_met'] = (
            $debug_info['gsm_check']['approved'] &&
            $debug_info['length_calibration']['approved'] &&
            $debug_info['roll_qc_report']['approved'] &&
            $debug_info['weight']['has_available']
        );
        
        $debug_info['missing_conditions'] = [];
        if (!$debug_info['gsm_check']['approved']) {
            $debug_info['missing_conditions'][] = "GSM Check not approved (Status: " . $debug_info['gsm_check']['status'] . ")";
        }
        if (!$debug_info['length_calibration']['approved']) {
            $debug_info['missing_conditions'][] = "Length Calibration not approved (Status: " . $debug_info['length_calibration']['status'] . ")";
        }
        if (!$debug_info['roll_qc_report']['approved']) {
            $debug_info['missing_conditions'][] = "Roll QC Report not approved (Approved: " . ($rqcData['approved'] ?? 0) . ", Overall Status: " . ($rqcData['overall_status'] ?? 'N/A') . ")";
        }
        if (!$debug_info['weight']['has_available']) {
            $debug_info['missing_conditions'][] = "No available weight (Available: " . $available_weight . " kg)";
        }
    } else {
        $debug_info['error'] = "Reference number not found in fiber_to_roll_entry";
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Roll Entry Reference</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
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
        h1 {
            color: #2c3e50;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .btn {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .btn:hover {
            background: #0056b3;
        }
        .debug-section {
            margin-top: 30px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 4px solid #007bff;
        }
        .status-ok {
            color: #28a745;
            font-weight: 600;
        }
        .status-fail {
            color: #dc3545;
            font-weight: 600;
        }
        .status-pending {
            color: #ffc107;
            font-weight: 600;
        }
        pre {
            background: #f4f4f4;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-size: 12px;
        }
        .missing-conditions {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            border-radius: 4px;
            margin-top: 15px;
        }
        .missing-conditions ul {
            margin: 10px 0 0 0;
            padding-left: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Debug Roll Entry Reference</h1>
        
        <form method="GET" action="">
            <div class="form-group">
                <label>Reference Number:</label>
                <input type="text" name="ref" value="<?php echo htmlspecialchars($reference_number); ?>" 
                       placeholder="Enter reference number (e.g., 4.0L226JAN04-R05-GT0.9.H0.1)" required>
            </div>
            <button type="submit" class="btn">Check Reference</button>
        </form>
        
        <?php if (!empty($debug_info)): ?>
            <div class="debug-section">
                <h2>Debug Results for: <?php echo htmlspecialchars($reference_number); ?></h2>
                
                <?php if (isset($debug_info['error'])): ?>
                    <p class="status-fail"><?php echo htmlspecialchars($debug_info['error']); ?></p>
                <?php else: ?>
                    
                    <h3>Overall Status:</h3>
                    <?php if ($debug_info['all_conditions_met']): ?>
                        <p class="status-ok">✓ All conditions met! Reference should appear in Roll Entry dropdown.</p>
                    <?php else: ?>
                        <p class="status-fail">✗ Not all conditions met. Reference will NOT appear in Roll Entry dropdown.</p>
                        
                        <?php if (!empty($debug_info['missing_conditions'])): ?>
                            <div class="missing-conditions">
                                <strong>Missing Conditions:</strong>
                                <ul>
                                    <?php foreach ($debug_info['missing_conditions'] as $condition): ?>
                                        <li><?php echo htmlspecialchars($condition); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <h3>Detailed Checks:</h3>
                    
                    <p><strong>1. Daily GSM Check:</strong>
                        <?php if ($debug_info['gsm_check']['exists']): ?>
                            <?php if ($debug_info['gsm_check']['approved']): ?>
                                <span class="status-ok">✓ Approved</span>
                            <?php else: ?>
                                <span class="status-fail">✗ Status: <?php echo htmlspecialchars($debug_info['gsm_check']['status']); ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="status-fail">✗ Not found</span>
                        <?php endif; ?>
                    </p>
                    
                    <p><strong>2. Length Calibration:</strong>
                        <?php if ($debug_info['length_calibration']['exists']): ?>
                            <?php if ($debug_info['length_calibration']['approved']): ?>
                                <span class="status-ok">✓ Approved</span>
                            <?php else: ?>
                                <span class="status-fail">✗ Status: <?php echo htmlspecialchars($debug_info['length_calibration']['status']); ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="status-fail">✗ Not found</span>
                        <?php endif; ?>
                    </p>
                    
                    <p><strong>3. Roll QC Report:</strong>
                        <?php if ($debug_info['roll_qc_report']['exists']): ?>
                            <?php if ($debug_info['roll_qc_report']['approved']): ?>
                                <span class="status-ok">✓ Approved</span>
                                (By: <?php echo htmlspecialchars($debug_info['roll_qc_report']['approved_by']); ?>)
                            <?php else: ?>
                                <span class="status-fail">✗ Not approved</span>
                                (Approved: <?php echo $debug_info['roll_qc_report']['data']['approved'] ?? 0; ?>, 
                                Overall Status: <?php echo htmlspecialchars($debug_info['roll_qc_report']['overall_status']); ?>)
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="status-fail">✗ Not found</span>
                        <?php endif; ?>
                    </p>
                    
                    <p><strong>4. Available Weight:</strong>
                        <?php if ($debug_info['weight']['has_available']): ?>
                            <span class="status-ok">✓ Available: <?php echo number_format($debug_info['weight']['available'], 2); ?> kg</span>
                        <?php else: ?>
                            <span class="status-fail">✗ No available weight (Available: <?php echo number_format($debug_info['weight']['available'], 2); ?> kg)</span>
                        <?php endif; ?>
                        (Original: <?php echo number_format($debug_info['weight']['original'], 2); ?> kg, 
                        Used: <?php echo number_format($debug_info['weight']['used'], 2); ?> kg)
                    </p>
                    
                    <h3>Raw Data:</h3>
                    <pre><?php echo htmlspecialchars(json_encode($debug_info, JSON_PRETTY_PRINT)); ?></pre>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <div style="margin-top: 30px;">
            <a href="../index.php" class="btn" style="text-decoration: none; display: inline-block;">← Back to Dashboard</a>
        </div>
    </div>
</body>
</html>

