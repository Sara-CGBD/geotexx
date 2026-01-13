<?php
session_start();
require_once '../../config/security_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$role = strtolower(trim($_SESSION['role'] ?? 'user'));

// Only Admin and AGM can access
if (!in_array($role, ['admin', 'agm', 'agm ops', 'agm operations', 'management'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Helper functions (same as in main dashboard)
function colExists(mysqli $conn, string $table, string $column): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    $col = $conn->real_escape_string($column);
    $sql = "SHOW COLUMNS FROM `{$table}` LIKE '{$col}'";
    $result = $conn->query($sql);
    return $result && $result->num_rows > 0;
}

$gsmHasRef = colExists($conn, 'daily_gsm_checks', 'reference_number');
$lcHasRef = colExists($conn, 'length_calibrations', 'reference_number');

// Fetch current auto-approval settings
$userId = $_SESSION['user_id'];
$autoApproveGSM = false;
$autoApproveLC = false;

$settingsQuery = $conn->prepare("SELECT test_type, auto_approve_enabled FROM agm_auto_approval_settings WHERE user_id = ?");
$settingsQuery->bind_param("i", $userId);
$settingsQuery->execute();
$settingsResult = $settingsQuery->get_result();
while ($row = $settingsResult->fetch_assoc()) {
    if ($row['test_type'] === 'gsm') {
        $autoApproveGSM = (bool)$row['auto_approve_enabled'];
    } elseif ($row['test_type'] === 'length_calibration') {
        $autoApproveLC = (bool)$row['auto_approve_enabled'];
    }
}
$settingsQuery->close();

// Fetch pending GSM checks
$gsmPendingQuery = $conn->query("
    SELECT id, entry_id, " . ($gsmHasRef ? "reference_number" : "NULL AS reference_number") . ", roll_no, date_time, shift, line_number, inspector, created_at, status, 
           COALESCE(approved_by, '') as approved_by, COALESCE(approved_at, '') as approved_at, COALESCE(auto_approved, 0) as auto_approved
    FROM daily_gsm_checks
    WHERE status = 'pending'
    ORDER BY created_at DESC, entry_id, roll_no
");
$gsmChecks = [];
if ($gsmPendingQuery) {
    while ($row = $gsmPendingQuery->fetch_assoc()) {
        $refNum = $row['reference_number'] ?? 'No Reference';
        if (!isset($gsmChecks[$refNum])) {
            $gsmChecks[$refNum] = [];
        }
        $gsmChecks[$refNum][] = $row;
    }
}

// Fetch auto-approved GSM checks
$gsmAutoApprovedQuery = $conn->query("
    SELECT id, entry_id, " . ($gsmHasRef ? "reference_number" : "NULL AS reference_number") . ", roll_no, date_time, shift, line_number, inspector, created_at, status, 
           COALESCE(approved_by, '') as approved_by, COALESCE(approved_at, '') as approved_at, COALESCE(auto_approved, 0) as auto_approved
    FROM daily_gsm_checks
    WHERE status = 'approved' AND auto_approved = 1
    ORDER BY approved_at DESC, entry_id, roll_no
    LIMIT 50
");
$gsmAutoApproved = [];
if ($gsmAutoApprovedQuery) {
    while ($row = $gsmAutoApprovedQuery->fetch_assoc()) {
        $refNum = $row['reference_number'] ?? 'No Reference';
        if (!isset($gsmAutoApproved[$refNum])) {
            $gsmAutoApproved[$refNum] = [];
        }
        $gsmAutoApproved[$refNum][] = $row;
    }
}

// Fetch pending length calibrations
$lcPendingQuery = $conn->query("
    SELECT id, entry_id, " . ($lcHasRef ? "reference_number" : "NULL AS reference_number") . ", roll_no, date_time, shift, line_number, inspector, created_at, status,
           COALESCE(approved_by, '') as approved_by, COALESCE(approved_at, '') as approved_at, COALESCE(auto_approved, 0) as auto_approved
    FROM length_calibrations
    WHERE status = 'pending'
    ORDER BY created_at DESC, entry_id, roll_no
");
$lcCalibrations = [];
if ($lcPendingQuery) {
    while ($row = $lcPendingQuery->fetch_assoc()) {
        $refNum = $row['reference_number'] ?? 'No Reference';
        if (!isset($lcCalibrations[$refNum])) {
            $lcCalibrations[$refNum] = [];
        }
        $lcCalibrations[$refNum][] = $row;
    }
}

// Fetch auto-approved length calibrations
$lcAutoApprovedQuery = $conn->query("
    SELECT id, entry_id, " . ($lcHasRef ? "reference_number" : "NULL AS reference_number") . ", roll_no, date_time, shift, line_number, inspector, created_at, status,
           COALESCE(approved_by, '') as approved_by, COALESCE(approved_at, '') as approved_at, COALESCE(auto_approved, 0) as auto_approved
    FROM length_calibrations
    WHERE status = 'approved' AND auto_approved = 1
    ORDER BY approved_at DESC, entry_id, roll_no
    LIMIT 50
");
$lcAutoApproved = [];
if ($lcAutoApprovedQuery) {
    while ($row = $lcAutoApprovedQuery->fetch_assoc()) {
        $refNum = $row['reference_number'] ?? 'No Reference';
        if (!isset($lcAutoApproved[$refNum])) {
            $lcAutoApproved[$refNum] = [];
        }
        $lcAutoApproved[$refNum][] = $row;
    }
}

$conn->close();

echo json_encode([
    'success' => true,
    'auto_approve_gsm' => $autoApproveGSM,
    'auto_approve_lc' => $autoApproveLC,
    'gsm_checks' => $gsmChecks,
    'gsm_auto_approved' => $gsmAutoApproved,
    'lc_calibrations' => $lcCalibrations,
    'lc_auto_approved' => $lcAutoApproved
]);
?>
