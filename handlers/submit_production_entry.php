<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_error.log');
error_reporting(E_ALL);

session_start();
require_once 'security_config.php';

// Session & auth checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: login.html');
    exit;
}
if (SecurityConfig::checkSessionTimeout()) {
    session_destroy();
    header('Location: login.html?error=timeout');
    exit;
}
SecurityConfig::updateSessionActivity();
if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    session_destroy();
    header('Location: login.html?error=disabled');
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../forms/production_entry.php');
    exit;
}

// Required fields
$required = ['project_id','gsm','line_no','fiber_type','roll_no','total_weight','batch_number','date_time','shift','operator_id'];
foreach ($required as $key) {
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        header('Location: ../forms/production_entry.php?error=' . urlencode("Missing field: $key"));
        exit;
    }
}

// Sanitize & assign variables
$operator_id = (int)$_POST['operator_id'];
$project_id = (int)$_POST['project_id'];
$gsm = (float)$_POST['gsm'];
$line_no = substr(trim($_POST['line_no']),0,50);
$fiber_type = substr(trim($_POST['fiber_type']),0,50);
$roll_no = (int)$_POST['roll_no'];
$total_weight = (float)$_POST['total_weight'];
$batch_number = (int)$_POST['batch_number'];
$date_time = $_POST['date_time'];
$shift = $_POST['shift'];

try {
    $conn = SecurityConfig::getConnection();

    // Optional: verify project exists
    $stmtCheck = $conn->prepare('SELECT id FROM projects WHERE id = ?');
    $stmtCheck->bind_param('i', $project_id);
    $stmtCheck->execute();
    if (!$stmtCheck->get_result()->fetch_assoc()) {
        throw new Exception('Invalid project selection.');
    }

    // Insert production entry
    $stmt = $conn->prepare('INSERT INTO production_entry 
        (operator_id, project_id, gsm, line_no, fiber_type, roll_number, total_weight, batch_number, date_time, shift)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);

    $stmt->bind_param(
        'iidssiddss',
        $operator_id,
        $project_id,
        $gsm,
        $line_no,
        $fiber_type,
        $roll_no,
        $total_weight,
        $batch_number,
        $date_time,
        $shift
    );

    if (!$stmt->execute()) throw new Exception('Execute failed: ' . $stmt->error);

    header('Location: ../forms/production_entry.php?success=' . urlencode('Production entry saved successfully.'));
    exit;

} catch (Throwable $e) {
    header('Location: ../forms/production_entry.php?error=' . urlencode($e->getMessage()));
    exit;
}
?>

