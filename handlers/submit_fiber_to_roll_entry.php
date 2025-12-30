<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'php_error.log');
error_reporting(E_ALL);

session_start();
require_once 'security_config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../forms/fiber_to_roll_entry.php');
    exit;
}

// Validate required fields
$required = ['date_time','operator_id','project_id','bale_opener_number','bale_number','bale_weight','gsm','line_no','material_type','origin','roll_no','batch_info','reference_number','total_weight'];
foreach ($required as $key) {
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        header('Location: ../forms/fiber_to_roll_entry.php?error=' . urlencode('Missing field: ' . $key));
        exit;
    }
}

$dateTime = $_POST['date_time'];
$operatorId = (int)$_POST['operator_id'];
$projectId = (int)$_POST['project_id'];
$baleOpenerNumber = (int)$_POST['bale_opener_number'];
$baleNumber = trim($_POST['bale_number']);
$baleWeight = (int)$_POST['bale_weight'];
$gsm = (float)$_POST['gsm'];
$lineNo = (int)$_POST['line_no'];
$materialType = substr(trim($_POST['material_type']), 0, 100);
$origin = substr(trim($_POST['origin']), 0, 100);
$rollNo = (int)$_POST['roll_no'];
$batchInfo = substr(trim($_POST['batch_info']), 0, 100);
$referenceNumber = substr(trim($_POST['reference_number']), 0, 200);
$totalWeight = (float)$_POST['total_weight'];

try {
    $conn = SecurityConfig::getConnection();

    // Create fiber_to_roll_entry table if it doesn't exist
    $createTable = "CREATE TABLE IF NOT EXISTS fiber_to_roll_entry (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date_time DATETIME,
        operator_id INT,
        project_id INT,
        bale_opener_number INT,
        bale_number VARCHAR(100),
        bale_weight INT,
        gsm DECIMAL(10,2),
        line_no INT,
        material_type VARCHAR(100),
        origin VARCHAR(100),
        roll_no INT,
        batch_info VARCHAR(100),
        reference_number VARCHAR(200),
        total_weight DECIMAL(10,2),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($createTable);

    // Alter existing table to add new columns and rename old ones
    // Check if fiber_type column exists and rename to material_type
    $checkCol = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'fiber_type'");
    if ($checkCol && $checkCol->num_rows > 0) {
        $conn->query("ALTER TABLE fiber_to_roll_entry CHANGE fiber_type material_type VARCHAR(100)");
    }
    
    // Check if roll_number column exists and rename to roll_no
    $checkRoll = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'roll_number'");
    if ($checkRoll && $checkRoll->num_rows > 0) {
        $conn->query("ALTER TABLE fiber_to_roll_entry CHANGE roll_number roll_no INT");
    }
    
    // Add batch_info if it doesn't exist
    $checkBatch = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'batch_info'");
    if ($checkBatch && $checkBatch->num_rows == 0) {
        $conn->query("ALTER TABLE fiber_to_roll_entry ADD COLUMN batch_info VARCHAR(100) AFTER roll_no");
    }
    
    // Add reference_number if it doesn't exist
    $checkRef = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'reference_number'");
    if ($checkRef && $checkRef->num_rows == 0) {
        $conn->query("ALTER TABLE fiber_to_roll_entry ADD COLUMN reference_number VARCHAR(200) AFTER batch_info");
    }
    
    // Modify bale_number to VARCHAR if it's INT
    $checkBaleNum = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'bale_number'");
    if ($checkBaleNum && $checkBaleNum->num_rows > 0) {
        $row = $checkBaleNum->fetch_assoc();
        if (stripos($row['Type'], 'int') !== false) {
            $conn->query("ALTER TABLE fiber_to_roll_entry MODIFY bale_number VARCHAR(100)");
        }
    }
    
    // Modify gsm to DECIMAL if it's INT
    $checkGsm = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'gsm'");
    if ($checkGsm && $checkGsm->num_rows > 0) {
        $row = $checkGsm->fetch_assoc();
        if (stripos($row['Type'], 'int') !== false) {
            $conn->query("ALTER TABLE fiber_to_roll_entry MODIFY gsm DECIMAL(10,2)");
        }
    }

    $stmt = $conn->prepare("INSERT INTO fiber_to_roll_entry (date_time, operator_id, project_id, bale_opener_number, bale_number, bale_weight, gsm, line_no, material_type, origin, roll_no, batch_info, reference_number, total_weight) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    // Insert single entry only (no multiple rolls in fiber_to_roll_entry)
    $stmt->bind_param(
        'siiisidisssssd',
        $dateTime,
        $operatorId,
        $projectId,
        $baleOpenerNumber,
        $baleNumber,
        $baleWeight,
        $gsm,
        $lineNo,
        $materialType,
        $origin,
        $rollNo,
        $batchInfo,
        $referenceNumber,
        $totalWeight
    );

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    
    $stmt->close();

    header('Location: ../forms/fiber_to_roll_entry.php?success=' . urlencode('Fiber to roll entry saved successfully'));
    exit;
} catch (Throwable $e) {
    header('Location: ../forms/fiber_to_roll_entry.php?error=' . urlencode($e->getMessage()));
    exit;
}
?>

