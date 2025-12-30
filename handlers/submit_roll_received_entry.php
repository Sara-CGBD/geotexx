<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'php_error.log');
error_reporting(E_ALL);

session_start();
require_once 'security_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../forms/roll_received_entry.php');
    exit;
}

// Validate required fields
$required = ['reporting_time','reporter_id','receiver_name','project_id','reference_number'];
foreach ($required as $key) {
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        header('Location: ../forms/roll_received_entry.php?error=' . urlencode('Missing field: ' . $key));
        exit;
    }
}

$reportingTime = $_POST['reporting_time'];
$reporterId = (int)$_POST['reporter_id'];
$receiverName = substr(trim($_POST['receiver_name']), 0, 100);
$projectId = (int)$_POST['project_id'];
$referenceNumber = substr(trim($_POST['reference_number']), 0, 100);

try {
    $conn = SecurityConfig::getConnection();

    // Create roll_received table if it doesn't exist
    $createTable = "CREATE TABLE IF NOT EXISTS roll_received (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reporting_time DATETIME NOT NULL,
        reporter_id INT NOT NULL,
        receiver_name VARCHAR(100) NOT NULL,
        project_id INT NOT NULL,
        reference_number VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_deleted TINYINT(1) DEFAULT 0,
        who_did VARCHAR(100) DEFAULT NULL,
        deleted_at DATETIME DEFAULT NULL
    )";
    $conn->query($createTable);
    
    // Add reference_number column if upgrading from old schema
    $conn->query("ALTER TABLE roll_received ADD COLUMN IF NOT EXISTS reference_number VARCHAR(100) DEFAULT NULL");

    // Prepare INSERT statement
    $stmt = $conn->prepare("INSERT INTO roll_received (
        reporting_time, reporter_id, receiver_name, project_id, reference_number
    ) VALUES (?, ?, ?, ?, ?)");

    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    // Bind parameters (5 parameters total)
    $stmt->bind_param(
        'sisis',
        $reportingTime,        // s - reporting_time
        $reporterId,           // i - reporter_id
        $receiverName,         // s - receiver_name
        $projectId,            // i - project_id
        $referenceNumber       // s - reference_number
    );

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    header('Location: ../forms/roll_received_entry.php?success=' . urlencode("Roll received entry saved! Reference Number: $referenceNumber"));
    exit;
} catch (Throwable $e) {
    header('Location: ../forms/roll_received_entry.php?error=' . urlencode($e->getMessage()));
    exit;
}
?>

