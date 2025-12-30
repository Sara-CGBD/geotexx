<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$role = strtolower(trim($_SESSION['role'] ?? 'user'));

// Only Admin and AGM can access
if (!in_array($role, ['admin', 'agm', 'agm ops', 'agm operations', 'management'])) {
    die('Access denied. Only Admin and AGM can approve QC tests.');
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Helper: check if column exists
function colExists(mysqli $conn, string $table, string $column): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    $col = $conn->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$table}` LIKE '{$col}'";
    $result = $conn->query($sql);
    return $result && $result->num_rows > 0;
}

// Helper: ensure column exists (add if missing)
function ensureColumnExists(mysqli $conn, string $table, string $column, string $definition): void {
    if (!colExists($conn, $table, $column)) {
        $result = $conn->query("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
        // Fallback without positional clause if first attempt failed (e.g., missing reference column for AFTER)
        if (!$result) {
            $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` VARCHAR(50) NOT NULL DEFAULT 'pending'");
        }
    }
}

// Ensure required tables exist to avoid runtime failures on missing tables
function ensureTableExists(mysqli $conn, string $table, string $createSql): void {
    $safeTable = $conn->real_escape_string($table);
    $check = $conn->query("SHOW TABLES LIKE '{$safeTable}'");
    if ($check && $check->num_rows === 0) {
        $conn->query($createSql);
    }
}

ensureTableExists($conn, 'daily_gsm_checks', "
    CREATE TABLE IF NOT EXISTS daily_gsm_checks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_id INT NULL,
        reference_number VARCHAR(100) NULL,
        roll_no VARCHAR(50) NULL,
        date_time DATETIME NULL,
        shift VARCHAR(50) NULL,
        line_number VARCHAR(50) NULL,
        inspector VARCHAR(100) NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
        INDEX idx_reference_number (reference_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

ensureTableExists($conn, 'length_calibrations', "
    CREATE TABLE IF NOT EXISTS length_calibrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_id INT NULL,
        reference_number VARCHAR(100) NULL,
        roll_no VARCHAR(50) NULL,
        date_time DATETIME NULL,
        shift VARCHAR(50) NULL,
        line_number VARCHAR(50) NULL,
        inspector VARCHAR(100) NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        status VARCHAR(50) NOT NULL DEFAULT 'pending',
        INDEX idx_reference_number (reference_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$gsmHasRef = colExists($conn, 'daily_gsm_checks', 'reference_number');
$lcHasRef = colExists($conn, 'length_calibrations', 'reference_number');

// Ensure status columns exist for upcoming queries
ensureColumnExists($conn, 'daily_gsm_checks', 'status', " `status` VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER inspector");
ensureColumnExists($conn, 'length_calibrations', 'status', " `status` VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER inspector");
ensureColumnExists($conn, 'qc_entries', 'status', " `status` VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER inspector_name");
ensureColumnExists($conn, 'qc_entries', 'inspector_name', " `inspector_name` VARCHAR(255) NULL AFTER qc_result");

// Fetch pending GSM checks
$gsmQuery = $conn->query("
    SELECT id, entry_id, " . ($gsmHasRef ? "reference_number" : "NULL AS reference_number") . ", roll_no, date_time, shift, line_number, inspector, created_at, status
    FROM daily_gsm_checks
    WHERE status = 'pending'
    ORDER BY created_at DESC, entry_id, roll_no
");
$gsmChecks = [];
if ($gsmQuery) {
    while ($row = $gsmQuery->fetch_assoc()) {
        $refNum = $row['reference_number'] ?? 'No Reference';
        if (!isset($gsmChecks[$refNum])) {
            $gsmChecks[$refNum] = [];
        }
        $gsmChecks[$refNum][] = $row;
    }
}

// Fetch pending length calibrations
$lcQuery = $conn->query("
    SELECT id, entry_id, " . ($lcHasRef ? "reference_number" : "NULL AS reference_number") . ", roll_no, date_time, shift, line_number, inspector, created_at, status
    FROM length_calibrations
    WHERE status = 'pending'
    ORDER BY created_at DESC, entry_id, roll_no
");
$lcCalibrations = [];
if ($lcQuery) {
    while ($row = $lcQuery->fetch_assoc()) {
        $refNum = $row['reference_number'] ?? 'No Reference';
        if (!isset($lcCalibrations[$refNum])) {
            $lcCalibrations[$refNum] = [];
        }
        $lcCalibrations[$refNum][] = $row;
    }
}

// Fetch pending QC entries
$qcEntriesQuery = $conn->query("
    SELECT id, qc_id, date_time, shift, qc_stage, qc_type, qc_result, remarks, 
           inspector_name, status, created_at
    FROM qc_entries
    WHERE status = 'pending'
    ORDER BY created_at DESC
    LIMIT 100
");
$qcEntries = [];
if ($qcEntriesQuery) {
    while ($row = $qcEntriesQuery->fetch_assoc()) {
        $qcEntries[] = $row;
    }
}

// Handle QC entry approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['qc_id'])) {
    $qc_id = (int)$_POST['qc_id'];
    $action = $_POST['action'];
    $approvedBy = $_SESSION['full_name'] ?? $_SESSION['username'];
    $approvedAt = date('Y-m-d H:i:s');
    $status = $action === 'approve' ? 'approved' : 'rejected';
    
    if ($action === 'approve') {
        // For approval, get final result (Pass/Fail) from AGM
        $final_result = trim($_POST['final_result'] ?? '');
        $approval_comments = trim($_POST['approval_comments'] ?? '');
        
        if (empty($final_result) || !in_array($final_result, ['Pass', 'Fail'])) {
            $_SESSION['error_message'] = "Please select a final result (Pass or Fail)";
            header("Location: qc_approval_dashboard.php");
            exit();
        }
        
        // Update with final result set by AGM
        $stmt = $conn->prepare("UPDATE qc_entries 
                               SET status = ?, approved_by = ?, approved_at = ?, 
                                   qc_result = ?, remarks = CONCAT(COALESCE(remarks, ''), 
                                   CASE WHEN ? != '' THEN CONCAT(CHAR(10), 'AGM Comments: ', ?) ELSE '' END)
                               WHERE id = ?");
        $stmt->bind_param("ssssssi", $status, $approvedBy, $approvedAt, $final_result, $approval_comments, $approval_comments, $qc_id);
        $stmt->execute();
        $stmt->close();
        
        $_SESSION['success_message'] = "QC Entry approved successfully with final result: " . $final_result;
    } else {
        // For rejection
        $rejection_reasons = $_POST['rejection_reasons'] ?? [];
        $rejection_comments = trim($_POST['rejection_comments'] ?? '');
        
        $rejection_reason = '';
        if (!empty($rejection_reasons)) {
            $reasons_text = implode(', ', $rejection_reasons);
            $rejection_reason = $reasons_text . ($rejection_comments ? "\n\nAdditional Comments: " . $rejection_comments : '');
        }
        
        $stmt = $conn->prepare("UPDATE qc_entries 
                               SET status = ?, approved_by = ?, approved_at = ?, rejection_reason = ? 
                               WHERE id = ?");
        $stmt->bind_param("ssssi", $status, $approvedBy, $approvedAt, $rejection_reason, $qc_id);
        $stmt->execute();
        $stmt->close();
        
        $_SESSION['success_message'] = "QC Entry rejected successfully!";
    }
    
    header("Location: qc_approval_dashboard.php");
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title> GSM And Length Calibration Approval Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f4f6f9; color: #2c3e50; padding: 20px; }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 28px; margin-bottom: 8px; }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            padding: 10px 20px;
            background: #e74c3c;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .back-link:hover { background: #c0392b; }
        .section { background: white; padding: 25px; border-radius: 12px; margin-bottom: 25px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .section h2 { color: #667eea; margin-bottom: 20px; font-size: 20px; border-bottom: 2px solid #e0e6ed; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        thead { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        th { padding: 15px; text-align: left; font-weight: 600; font-size: 14px; }
        td { padding: 15px; border-bottom: 1px solid #ecf0f1; font-size: 14px; }
        tbody tr:hover { background: #f8f9fa; }
        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #fff3cd;
            color: #856404;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s;
            margin: 0 5px;
            box-sizing: border-box;
            line-height: 1.5;
            vertical-align: middle;
            height: auto;
            min-height: 36px;
            min-width: 100px;
            text-align: center;
        }
        .btn-approve { background: #27ae60; color: white; }
        .btn-approve:hover { background: #229954; }
        .btn-reject { background: #e74c3c; color: white; }
        .btn-reject:hover { background: #c0392b; }
        .btn-view { 
            background: #3498db; 
            color: white; 
            text-decoration: none; 
            display: inline-block;
            box-sizing: border-box;
            line-height: 1.5;
            vertical-align: middle;
            min-width: 100px;
            text-align: center;
        }
        .btn-view:hover { background: #2980b9; }
        .empty-state {
            text-align: center;
            padding: 60px;
            color: #95a5a6;
        }
        .empty-state i { font-size: 4em; margin-bottom: 20px; }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .modal-header {
            border-bottom: 2px solid #e74c3c;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .modal-header h2 {
            color: #e74c3c;
            font-size: 22px;
            margin: 0;
        }
        .modal-body {
            margin-bottom: 20px;
        }
        .checkbox-group {
            margin-bottom: 15px;
        }
        .checkbox-group label {
            display: flex;
            align-items: center;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            margin-bottom: 8px;
        }
        .checkbox-group label:hover {
            background: #e9ecef;
        }
        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 12px;
            cursor: pointer;
        }
        .other-reason {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            margin-top: 10px;
            font-family: 'Inter', sans-serif;
            display: none;
        }
        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .btn-modal-submit {
            background: #e74c3c;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
        }
        .btn-modal-submit:hover {
            background: #c0392b;
        }
        .btn-modal-cancel {
            background: #6c757d;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
        }
        .btn-modal-cancel:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <!-- Rejection Modal for GSM -->
    <div id="rejectGSMModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-times-circle"></i> Reject GSM Check</h2>
                <p style="margin-top: 10px; color: #6c757d;">Entry ID: <strong id="gsm_reject_entry_id"></strong></p>
            </div>
            <div class="modal-body">
                <label style="font-weight: 600; margin-bottom: 10px; display: block;">Select Rejection Reasons:</label>
                <div class="checkbox-group">
                    <label>
                        <input type="checkbox" name="gsm_reason" value="GSM values out of tolerance range">
                        GSM values out of tolerance range
                    </label>
                    <label>
                        <input type="checkbox" name="gsm_reason" value="Weight measurements incorrect">
                        Weight measurements incorrect
                    </label>
                    <label>
                        <input type="checkbox" name="gsm_reason" value="Missing or incomplete data">
                        Missing or incomplete data
                    </label>
                    <label>
                        <input type="checkbox" name="gsm_reason" value="Average GSM calculation error">
                        Average GSM calculation error
                    </label>
                    <label>
                        <input type="checkbox" name="gsm_reason" value="Incorrect roll number">
                        Incorrect roll number
                    </label>
                    <label>
                        <input type="checkbox" id="gsm_other_checkbox" name="gsm_reason" value="Other" onchange="toggleOtherReason('gsm')">
                        Other (please specify)
                    </label>
                    <textarea id="gsm_other_reason" class="other-reason" placeholder="Enter other reason..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeRejectModal('gsm')">Cancel</button>
                <button type="button" class="btn-modal-submit" onclick="submitGSMRejection()">
                    <i class="fas fa-times"></i> Reject Entry
                </button>
            </div>
        </div>
    </div>

    <!-- Approval Modal for QC Entry -->
    <div id="approveQCEntryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-check-circle"></i> Approve QC Entry</h2>
                <p style="margin-top: 10px; color: #6c757d;">QC ID: <strong id="qc_entry_approve_id"></strong></p>
            </div>
            <div class="modal-body">
                <label style="font-weight: 600; margin-bottom: 10px; display: block;">Final Result (Pass/Fail):</label>
                <div style="display: flex; gap: 15px; margin-bottom: 15px;">
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="radio" name="final_result" value="Pass" checked style="margin-right: 8px; width: auto;">
                        <span style="padding: 8px 16px; background: #d4edda; color: #155724; border-radius: 6px; font-weight: 600;">Pass</span>
                    </label>
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="radio" name="final_result" value="Fail" style="margin-right: 8px; width: auto;">
                        <span style="padding: 8px 16px; background: #f8d7da; color: #721c24; border-radius: 6px; font-weight: 600;">Fail</span>
                    </label>
                </div>
                <div style="margin-top:15px;">
                    <label style="font-weight:600; display:block; margin-bottom:8px;">Comments (Optional):</label>
                    <textarea name="approval_comments" id="qc_entry_approval_comments" rows="3" placeholder="Add any comments..." style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px; font-family:inherit;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeApproveModal('qc_entry')">Cancel</button>
                <button type="button" class="btn-modal-submit" style="background: #27ae60;" onclick="submitQCEntryApproval()">
                    <i class="fas fa-check"></i> Approve Entry
                </button>
            </div>
        </div>
    </div>

    <!-- Rejection Modal for QC Entry -->
    <div id="rejectQCEntryModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-times-circle"></i> Reject QC Entry</h2>
                <p style="margin-top: 10px; color: #6c757d;">QC ID: <strong id="qc_entry_reject_id"></strong></p>
            </div>
            <div class="modal-body">
                <label style="font-weight: 600; margin-bottom: 10px; display: block;">Select Rejection Reasons:</label>
                <div class="checkbox-group">
                    <label>
                        <input type="checkbox" name="qc_entry_reason" value="Incorrect QC Type Selected">
                        Incorrect QC Type Selected
                    </label>
                    <label>
                        <input type="checkbox" name="qc_entry_reason" value="Failed Quality Standards">
                        Failed Quality Standards
                    </label>
                    <label>
                        <input type="checkbox" name="qc_entry_reason" value="Incomplete Information">
                        Incomplete Information
                    </label>
                    <label>
                        <input type="checkbox" name="qc_entry_reason" value="Incorrect Stage/Process">
                        Incorrect Stage/Process
                    </label>
                    <label>
                        <input type="checkbox" name="qc_entry_reason" value="Data Entry Errors">
                        Data Entry Errors
                    </label>
                    <label>
                        <input type="checkbox" id="qc_entry_other_checkbox" name="qc_entry_reason" value="Other" onchange="toggleOtherReason('qc_entry')">
                        Other (please specify)
                    </label>
                    <textarea id="qc_entry_other_reason" class="other-reason" placeholder="Enter other reason..."></textarea>
                </div>
                <div style="margin-top:15px;">
                    <label style="font-weight:600; display:block; margin-bottom:8px;">Additional Comments (Optional):</label>
                    <textarea name="rejection_comments" id="qc_entry_rejection_comments" rows="3" placeholder="Add any additional details..." style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px; font-family:inherit;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeRejectModal('qc_entry')">Cancel</button>
                <button type="button" class="btn-modal-submit" onclick="submitQCEntryRejection()">
                    <i class="fas fa-times"></i> Reject Entry
                </button>
            </div>
        </div>
    </div>

    <!-- Rejection Modal for LC -->
    <div id="rejectLCModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-times-circle"></i> Reject Length Calibration</h2>
                <p style="margin-top: 10px; color: #6c757d;">Entry ID: <strong id="lc_reject_entry_id"></strong></p>
            </div>
            <div class="modal-body">
                <label style="font-weight: 600; margin-bottom: 10px; display: block;">Select Rejection Reasons:</label>
                <div class="checkbox-group">
                    <label>
                        <input type="checkbox" name="lc_reason" value="Calibration values out of range">
                        Calibration values out of range
                    </label>
                    <label>
                        <input type="checkbox" name="lc_reason" value="Difference calculation error">
                        Difference calculation error
                    </label>
                    <label>
                        <input type="checkbox" name="lc_reason" value="Incorrect reference length">
                        Incorrect reference length
                    </label>
                    <label>
                        <input type="checkbox" name="lc_reason" value="Machine setting mismatch">
                        Machine setting mismatch
                    </label>
                    <label>
                        <input type="checkbox" name="lc_reason" value="Missing or incomplete data">
                        Missing or incomplete data
                    </label>
                    <label>
                        <input type="checkbox" id="lc_other_checkbox" name="lc_reason" value="Other" onchange="toggleOtherReason('lc')">
                        Other (please specify)
                    </label>
                    <textarea id="lc_other_reason" class="other-reason" placeholder="Enter other reason..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeRejectModal('lc')">Cancel</button>
                <button type="button" class="btn-modal-submit" onclick="submitLCRejection()">
                    <i class="fas fa-times"></i> Reject Entry
                </button>
            </div>
        </div>
    </div>
    <a href="../index.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div style="background:#d4edda;color:#155724;padding:12px;border-radius:6px;border:1px solid #c3e6cb;margin-bottom:15px;">
            ✅ <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?>
        </div>
    <?php endif; ?>

    <div class="header">
        <h1><i class="fas fa-clipboard-check"></i> QC Approval Dashboard</h1>
        <p>Approve or reject QC Entries, Daily GSM Checks and Length Calibrations</p>
    </div>

    <!-- QC Entries Section -->
    <div class="section">
        <h2><i class="fas fa-clipboard-list"></i> Pending QC Entries (<?php echo count($qcEntries); ?>)</h2>
        <?php if (count($qcEntries) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>QC ID</th>
                    <th>Date & Time</th>
                    <th>Shift</th>
                    <th>Stage</th>
                    <th>Type</th>
                    <th>Result</th>
                    <th>Inspector</th>
                    <th>Submitted At</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($qcEntries as $qc): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($qc['qc_id']); ?></strong></td>
                    <td><?php echo date('d M Y, h:i A', strtotime($qc['date_time'])); ?></td>
                    <td><?php echo htmlspecialchars($qc['shift']); ?></td>
                    <td><?php echo htmlspecialchars($qc['qc_stage']); ?></td>
                    <td><?php echo htmlspecialchars($qc['qc_type']); ?></td>
                    <td>
                        <span class="badge" style="<?php echo $qc['qc_result'] === 'Pass' ? 'background:#d4edda;color:#155724;' : 'background:#f8d7da;color:#721c24;'; ?>">
                            <?php echo htmlspecialchars($qc['qc_result']); ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($qc['inspector_name'] ?? 'N/A'); ?></td>
                    <td><?php echo date('d M Y, h:i A', strtotime($qc['created_at'])); ?></td>
                    <td><span class="badge">Pending</span></td>
                    <td>
                        <a href="view_qc_entry.php?id=<?php echo $qc['id']; ?>" class="btn btn-view" target="_blank">
                            <i class="fas fa-eye"></i> View
                        </a>
                        <button class="btn btn-approve" onclick="approveQCEntry(<?php echo $qc['id']; ?>)">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button class="btn btn-reject" onclick="rejectQCEntry(<?php echo $qc['id']; ?>)">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <p>No pending QC entries</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Daily GSM Check Section -->
    <div class="section">
        <h2><i class="fas fa-weight"></i> Pending Daily GSM Checks (<?php 
            $totalGsmTests = 0;
            foreach ($gsmChecks as $tests) { $totalGsmTests += count($tests); }
            echo $totalGsmTests; 
        ?>)</h2>
        <?php if (count($gsmChecks) > 0): ?>
            <?php foreach ($gsmChecks as $refNumber => $tests): ?>
            <div style="margin-bottom: 30px; border: 2px solid #667eea; border-radius: 8px; padding: 15px; background: #f8f9ff;">
                <h3 style="color: #667eea; margin: 0 0 15px 0; font-size: 16px;">
                    <i class="fas fa-barcode"></i> Reference: <strong><?php echo htmlspecialchars($refNumber); ?></strong>
                    <span style="font-size: 14px; color: #666; font-weight: normal;">(<?php echo count($tests); ?> test<?php echo count($tests) > 1 ? 's' : ''; ?>)</span>
                </h3>
                <table>
                    <thead>
                        <tr>
                            <th>Entry ID</th>
                            <th>Roll No</th>
                            <th>Date & Time</th>
                            <th>Shift</th>
                            <th>Line Number</th>
                            <th>Inspector</th>
                            <th>Submitted At</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tests as $gsm): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($gsm['entry_id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($gsm['roll_no'] ?? 'N/A'); ?></td>
                            <td><?php echo date('d M Y, h:i A', strtotime($gsm['date_time'])); ?></td>
                            <td><?php echo htmlspecialchars($gsm['shift']); ?></td>
                            <td><?php echo htmlspecialchars($gsm['line_number']); ?></td>
                            <td><?php echo htmlspecialchars($gsm['inspector']); ?></td>
                            <td><?php echo date('d M Y, h:i A', strtotime($gsm['created_at'])); ?></td>
                            <td><span class="badge">Pending</span></td>
                            <td>
                                <a href="view_gsm_check.php?id=<?php echo urlencode($gsm['entry_id']); ?>" class="btn btn-view" target="_blank">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <button class="btn btn-approve" onclick="approveGSM('<?php echo htmlspecialchars($gsm['entry_id']); ?>')">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="btn btn-reject" onclick="rejectGSM('<?php echo htmlspecialchars($gsm['entry_id']); ?>')">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <p>No pending GSM checks</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Length Calibration Section -->
    <div class="section">
        <h2><i class="fas fa-ruler"></i> Pending Length Calibrations (<?php 
            $totalLcTests = 0;
            foreach ($lcCalibrations as $tests) { $totalLcTests += count($tests); }
            echo $totalLcTests; 
        ?>)</h2>
        <?php if (count($lcCalibrations) > 0): ?>
            <?php foreach ($lcCalibrations as $refNumber => $tests): ?>
            <div style="margin-bottom: 30px; border: 2px solid #667eea; border-radius: 8px; padding: 15px; background: #f8f9ff;">
                <h3 style="color: #667eea; margin: 0 0 15px 0; font-size: 16px;">
                    <i class="fas fa-barcode"></i> Reference: <strong><?php echo htmlspecialchars($refNumber); ?></strong>
                    <span style="font-size: 14px; color: #666; font-weight: normal;">(<?php echo count($tests); ?> test<?php echo count($tests) > 1 ? 's' : ''; ?>)</span>
                </h3>
                <table>
                    <thead>
                        <tr>
                            <th>Entry ID</th>
                            <th>Roll No</th>
                            <th>Date & Time</th>
                            <th>Shift</th>
                            <th>Line Number</th>
                            <th>Inspector</th>
                            <th>Submitted At</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tests as $lc): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($lc['entry_id']); ?></strong></td>
                            <td><?php echo htmlspecialchars($lc['roll_no'] ?? 'N/A'); ?></td>
                            <td><?php echo date('d M Y, h:i A', strtotime($lc['date_time'])); ?></td>
                            <td><?php echo htmlspecialchars($lc['shift']); ?></td>
                            <td><?php echo htmlspecialchars($lc['line_number']); ?></td>
                            <td><?php echo htmlspecialchars($lc['inspector']); ?></td>
                            <td><?php echo date('d M Y, h:i A', strtotime($lc['created_at'])); ?></td>
                            <td><span class="badge">Pending</span></td>
                            <td>
                                <a href="view_length_calibration.php?id=<?php echo urlencode($lc['entry_id']); ?>" class="btn btn-view" target="_blank">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <button class="btn btn-approve" onclick="approveLC('<?php echo htmlspecialchars($lc['entry_id']); ?>')">
                                    <i class="fas fa-check"></i> Approve
                                </button>
                                <button class="btn btn-reject" onclick="rejectLC('<?php echo htmlspecialchars($lc['entry_id']); ?>')">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <p>No pending length calibrations</p>
        </div>
        <?php endif; ?>
    </div>

    <script>
    let currentRejectEntryId = '';
    
    function toggleOtherReason(type) {
        const checkbox = document.getElementById(type + '_other_checkbox');
        const textarea = document.getElementById(type + '_other_reason');
        textarea.style.display = checkbox.checked ? 'block' : 'none';
        if (!checkbox.checked) {
            textarea.value = '';
        }
    }
    
    function approveGSM(entryId) {
        if (confirm('Approve GSM Check: ' + entryId + '?')) {
            fetch('../handlers/approve_qc.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ type: 'gsm', entry_id: entryId, action: 'approve' })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('GSM Check approved successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }
    }

    function rejectGSM(entryId) {
        currentRejectEntryId = entryId;
        document.getElementById('gsm_reject_entry_id').textContent = entryId;
        document.getElementById('rejectGSMModal').style.display = 'block';
        
        // Clear previous selections
        document.querySelectorAll('#rejectGSMModal input[type="checkbox"]').forEach(cb => cb.checked = false);
        document.getElementById('gsm_other_reason').value = '';
        document.getElementById('gsm_other_reason').style.display = 'none';
    }
    
    function submitGSMRejection() {
        const checkboxes = document.querySelectorAll('#rejectGSMModal input[name="gsm_reason"]:checked');
        const reasons = [];
        
        checkboxes.forEach(cb => {
            if (cb.value === 'Other') {
                const otherReason = document.getElementById('gsm_other_reason').value.trim();
                if (otherReason) {
                    reasons.push('Other: ' + otherReason);
                }
            } else {
                reasons.push(cb.value);
            }
        });
        
        if (reasons.length === 0) {
            alert('Please select at least one rejection reason');
            return;
        }
        
        const reason = reasons.join('; ');
        
        fetch('../handlers/approve_qc.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ type: 'gsm', entry_id: currentRejectEntryId, action: 'reject', reason: reason })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('GSM Check rejected.');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }

    function approveLC(entryId) {
        if (confirm('Approve Length Calibration: ' + entryId + '?')) {
            fetch('../handlers/approve_qc.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ type: 'lc', entry_id: entryId, action: 'approve' })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('Length Calibration approved successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }
    }

    function rejectLC(entryId) {
        currentRejectEntryId = entryId;
        document.getElementById('lc_reject_entry_id').textContent = entryId;
        document.getElementById('rejectLCModal').style.display = 'block';
        
        // Clear previous selections
        document.querySelectorAll('#rejectLCModal input[type="checkbox"]').forEach(cb => cb.checked = false);
        document.getElementById('lc_other_reason').value = '';
        document.getElementById('lc_other_reason').style.display = 'none';
    }
    
    function submitLCRejection() {
        const checkboxes = document.querySelectorAll('#rejectLCModal input[name="lc_reason"]:checked');
        const reasons = [];
        
        checkboxes.forEach(cb => {
            if (cb.value === 'Other') {
                const otherReason = document.getElementById('lc_other_reason').value.trim();
                if (otherReason) {
                    reasons.push('Other: ' + otherReason);
                }
            } else {
                reasons.push(cb.value);
            }
        });
        
        if (reasons.length === 0) {
            alert('Please select at least one rejection reason');
            return;
        }
        
        const reason = reasons.join('; ');
        
        fetch('../handlers/approve_qc.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ type: 'lc', entry_id: currentRejectEntryId, action: 'reject', reason: reason })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('Length Calibration rejected.');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        });
    }
    
    function closeRejectModal(type) {
        if (type === 'gsm') {
            document.getElementById('rejectGSMModal').style.display = 'none';
        } else if (type === 'lc') {
            document.getElementById('rejectLCModal').style.display = 'none';
        } else if (type === 'qc_entry') {
            document.getElementById('rejectQCEntryModal').style.display = 'none';
        }
        currentRejectEntryId = '';
    }

    function closeApproveModal(type) {
        if (type === 'qc_entry') {
            document.getElementById('approveQCEntryModal').style.display = 'none';
        }
    }

    let currentRejectQCEntryId = '';
    let currentApproveQCEntryId = '';

    function approveQCEntry(qcId) {
        currentApproveQCEntryId = qcId;
        // Get QC ID from the table row
        const button = event.target.closest('button');
        const row = button.closest('tr');
        const qcIdCell = row.querySelector('td:first-child');
        const qcIdText = qcIdCell ? (qcIdCell.querySelector('strong')?.textContent || qcIdCell.textContent.trim()) : 'QC-' + qcId;
        document.getElementById('qc_entry_approve_id').textContent = qcIdText;
        document.getElementById('approveQCEntryModal').style.display = 'block';
        
        // Reset form
        const passRadio = document.querySelector('#approveQCEntryModal input[name="final_result"][value="Pass"]');
        if (passRadio) passRadio.checked = true;
        const commentsField = document.getElementById('qc_entry_approval_comments');
        if (commentsField) commentsField.value = '';
    }

    function submitQCEntryApproval() {
        const modal = document.getElementById('approveQCEntryModal');
        const checkedRadio = modal.querySelector('input[name="final_result"]:checked');
        if (!checkedRadio) {
            alert('Please select a final result (Pass or Fail)');
            return;
        }
        const finalResult = checkedRadio.value;
        const commentsField = document.getElementById('qc_entry_approval_comments');
        const comments = commentsField ? commentsField.value.trim() : '';
        
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="qc_id" value="${currentApproveQCEntryId}">
            <input type="hidden" name="final_result" value="${finalResult}">
            <input type="hidden" name="approval_comments" value="${comments.replace(/"/g, '&quot;').replace(/'/g, '&#39;')}">
        `;
        document.body.appendChild(form);
        form.submit();
    }

    function rejectQCEntry(qcId) {
        currentRejectQCEntryId = qcId;
        // Get QC ID from the table row
        const button = event.target;
        const row = button.closest('tr');
        const qcIdText = row.querySelector('td:first-child strong').textContent;
        document.getElementById('qc_entry_reject_id').textContent = qcIdText;
        document.getElementById('rejectQCEntryModal').style.display = 'block';
        
        // Clear previous selections
        document.querySelectorAll('#rejectQCEntryModal input[type="checkbox"]').forEach(cb => cb.checked = false);
        document.getElementById('qc_entry_other_reason').value = '';
        document.getElementById('qc_entry_other_reason').style.display = 'none';
        document.getElementById('qc_entry_rejection_comments').value = '';
    }
    
    function submitQCEntryRejection() {
        const checkboxes = document.querySelectorAll('#rejectQCEntryModal input[name="qc_entry_reason"]:checked');
        const reasons = [];
        
        checkboxes.forEach(cb => {
            if (cb.value === 'Other') {
                const otherReason = document.getElementById('qc_entry_other_reason').value.trim();
                if (otherReason) {
                    reasons.push('Other: ' + otherReason);
                }
            } else {
                reasons.push(cb.value);
            }
        });
        
        if (reasons.length === 0) {
            alert('Please select at least one rejection reason');
            return;
        }
        
        const comments = document.getElementById('qc_entry_rejection_comments').value.trim();
        
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="qc_id" value="${currentRejectQCEntryId}">
            ${reasons.map(r => `<input type="hidden" name="rejection_reasons[]" value="${r.replace(/"/g, '&quot;')}">`).join('')}
            <input type="hidden" name="rejection_comments" value="${comments.replace(/"/g, '&quot;')}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const gsmModal = document.getElementById('rejectGSMModal');
        const lcModal = document.getElementById('rejectLCModal');
        const qcEntryRejectModal = document.getElementById('rejectQCEntryModal');
        const qcEntryApproveModal = document.getElementById('approveQCEntryModal');
        if (event.target === gsmModal) {
            closeRejectModal('gsm');
        } else if (event.target === lcModal) {
            closeRejectModal('lc');
        } else if (event.target === qcEntryRejectModal) {
            closeRejectModal('qc_entry');
        } else if (event.target === qcEntryApproveModal) {
            closeApproveModal('qc_entry');
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

