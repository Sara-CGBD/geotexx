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
$required = ['date_time','operator_id','project_id','bale_opener_number','bale_number','bale_weight','line_no','material_type','origin','batch_info','total_weight','entry_id'];
foreach ($required as $key) {
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        header('Location: ../forms/fiber_to_roll_entry.php?error=' . urlencode('Missing field: ' . $key));
        exit;
    }
}

$dateTime = $_POST['date_time'];
$operatorId = (int)$_POST['operator_id'];
$projectId = (int)$_POST['project_id'];
$baleOpenerNumber = trim($_POST['bale_opener_number']); // Can be comma-separated values like "1,2,3"
$baleNumber = trim($_POST['bale_number']);
$baleWeight = (int)$_POST['bale_weight'];
$lineNo = (int)$_POST['line_no'];
$materialType = substr(trim($_POST['material_type']), 0, 100);
$manufacturerName = substr(trim($_POST['manufacturer_name'] ?? ''), 0, 255);
$materialPercentage = isset($_POST['material_percentage']) && $_POST['material_percentage'] !== '' ? (float)$_POST['material_percentage'] : null;
$origin = substr(trim($_POST['origin']), 0, 100);
$batchInfo = substr(trim($_POST['batch_info']), 0, 100);
$totalWeight = (float)$_POST['total_weight'];
$entryId = trim($_POST['entry_id']);

try {
    $conn = SecurityConfig::getConnection();

    // Create fiber_to_roll_entry table if it doesn't exist
    $createTable = "CREATE TABLE IF NOT EXISTS fiber_to_roll_entry (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_id VARCHAR(30) UNIQUE,
        date_time DATETIME,
        operator_id INT,
        project_id INT,
        bale_opener_number VARCHAR(50),
        bale_number VARCHAR(100),
        bale_weight INT,
        gsm DECIMAL(10,2),
        line_no INT,
        material_type VARCHAR(100),
        manufacturer_name VARCHAR(255),
        material_percentage DECIMAL(5,2),
        origin VARCHAR(100),
        roll_no INT,
        batch_info VARCHAR(100),
        reference_number VARCHAR(200),
        total_weight DECIMAL(10,2),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($createTable);
    
    // Modify bale_opener_number to VARCHAR if it's INT (to support multiple values)
    $checkBaleOpener = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'bale_opener_number'");
    if ($checkBaleOpener && $checkBaleOpener->num_rows > 0) {
        $row = $checkBaleOpener->fetch_assoc();
        if (stripos($row['Type'], 'int') !== false) {
            $conn->query("ALTER TABLE fiber_to_roll_entry MODIFY bale_opener_number VARCHAR(50)");
        }
    }
    
    // Add entry_id column if it doesn't exist
    $checkEntryId = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'entry_id'");
    if ($checkEntryId && $checkEntryId->num_rows == 0) {
        $conn->query("ALTER TABLE fiber_to_roll_entry ADD COLUMN entry_id VARCHAR(30) UNIQUE AFTER id");
    }

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
    
    // Add manufacturer_name if it doesn't exist
    $checkManufacturer = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'manufacturer_name'");
    if ($checkManufacturer && $checkManufacturer->num_rows == 0) {
        $conn->query("ALTER TABLE fiber_to_roll_entry ADD COLUMN manufacturer_name VARCHAR(255) AFTER material_type");
    }
    
    // Add material_percentage if it doesn't exist
    $checkMaterialPercentage = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'material_percentage'");
    if ($checkMaterialPercentage && $checkMaterialPercentage->num_rows == 0) {
        $conn->query("ALTER TABLE fiber_to_roll_entry ADD COLUMN material_percentage DECIMAL(5,2) AFTER manufacturer_name");
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

    $stmt = $conn->prepare("INSERT INTO fiber_to_roll_entry (entry_id, date_time, operator_id, project_id, bale_opener_number, bale_number, bale_weight, line_no, material_type, manufacturer_name, material_percentage, origin, batch_info, total_weight) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    // Insert single entry only (no multiple rolls in fiber_to_roll_entry)
    // bale_opener_number can be comma-separated values like "1,2,3"
    // Type string: s=string, i=integer, d=double
    // Parameters: entry_id(s), date_time(s), operator_id(i), project_id(i), bale_opener_number(s), bale_number(s), bale_weight(i), line_no(i), material_type(s), manufacturer_name(s), material_percentage(d), origin(s), batch_info(s), total_weight(d)
    $stmt->bind_param(
        'ssiissiisssdsd',
        $entryId,            // s - string
        $dateTime,           // s - string
        $operatorId,         // i - integer
        $projectId,          // i - integer
        $baleOpenerNumber,   // s - string
        $baleNumber,         // s - string
        $baleWeight,         // i - integer
        $lineNo,             // i - integer
        $materialType,       // s - string
        $manufacturerName,   // s - string
        $materialPercentage, // d - double (DECIMAL)
        $origin,             // s - string
        $batchInfo,          // s - string
        $totalWeight         // d - double
    );

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }
    
    $stmt->close();

    header('Location: ../forms/fiber_to_roll_entry.php?success=' . urlencode('Fiber Input Entry saved successfully! Entry ID: ' . $entryId));
    exit;
} catch (Throwable $e) {
    header('Location: ../forms/fiber_to_roll_entry.php?error=' . urlencode($e->getMessage()));
    exit;
}
?>

