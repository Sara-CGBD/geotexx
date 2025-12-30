<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'php_error.log');
error_reporting(E_ALL);

session_start();
require_once 'security_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../forms/roll_transfer_entry.php');
    exit;
}

// Validate required fields
$required = ['transfer_id','date_time','operator_id','reference_number','amount','from_location','to_location'];
foreach ($required as $key) {
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        header('Location: ../forms/roll_transfer_entry.php?error=' . urlencode('Missing field: ' . $key));
        exit;
    }
}

$transferId = (int)$_POST['transfer_id'];
$dateTime = $_POST['date_time'];
$operatorId = (int)$_POST['operator_id'];
$operatorName = isset($_POST['operator_name']) ? substr(trim($_POST['operator_name']), 0, 100) : '';
$referenceNumber = substr(trim($_POST['reference_number']), 0, 200);
$amount = (float)$_POST['amount'];
$fromLocation = substr(trim($_POST['from_location']), 0, 100);
$toLocation = substr(trim($_POST['to_location']), 0, 100);

// Handle driver fields (either driver_id or driver_name)
$driverId = null;
$driverName = null;
if (isset($_POST['driver_id']) && !empty($_POST['driver_id'])) {
    $driverId = (int)$_POST['driver_id'];
} elseif (isset($_POST['driver_name']) && !empty($_POST['driver_name'])) {
    $driverName = substr(trim($_POST['driver_name']), 0, 100);
}

try {
    $conn = SecurityConfig::getConnection();

    // Create roll_transfer table if it doesn't exist
    $createTable = "CREATE TABLE IF NOT EXISTS roll_transfer (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transfer_id INT,
        date_time DATETIME NOT NULL,
        operator_id INT NOT NULL,
        operator_name VARCHAR(100) DEFAULT NULL,
        reference_number VARCHAR(200) NOT NULL,
        driver_id INT DEFAULT NULL,
        driver_name VARCHAR(100) DEFAULT NULL,
        amount DECIMAL(10,2) NOT NULL,
        from_location VARCHAR(100) NOT NULL,
        to_location VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_deleted TINYINT(1) DEFAULT 0,
        who_did VARCHAR(100) DEFAULT NULL,
        deleted_at DATETIME DEFAULT NULL
    )";
    $conn->query($createTable);
    
    // Ensure all required columns exist (in case table already existed)
    $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS operator_name VARCHAR(100) DEFAULT NULL AFTER operator_id");
    $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS reference_number VARCHAR(200) AFTER operator_name");
    $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS from_location VARCHAR(100) DEFAULT ''");
    $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS to_location VARCHAR(100) DEFAULT ''");
    $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS driver_id INT DEFAULT NULL");
    $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS driver_name VARCHAR(100) DEFAULT NULL");

    // Use transfer ID from form

    // Prepare INSERT statement
    $stmt = $conn->prepare("INSERT INTO roll_transfer (
        transfer_id, date_time, operator_id, operator_name, reference_number, 
        driver_id, driver_name, amount_kg, from_location, to_location
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    // Bind parameters (10 parameters total)
    $stmt->bind_param(
        'isissisdss',
        $transferId,          // i - transfer_id
        $dateTime,            // s - date_time
        $operatorId,          // i - operator_id
        $operatorName,        // s - operator_name
        $referenceNumber,     // s - reference_number
        $driverId,            // i - driver_id (can be null)
        $driverName,          // s - driver_name (can be null)
        $amount,              // d - amount_kg
        $fromLocation,        // s - from_location
        $toLocation           // s - to_location
    );

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    header('Location: ../forms/roll_transfer_entry.php?success=' . urlencode("Roll transfer saved with Transfer ID: $transferId"));
    exit;
} catch (Throwable $e) {
    header('Location: ../forms/roll_transfer_entry.php?error=' . urlencode($e->getMessage()));
    exit;
}
?>

