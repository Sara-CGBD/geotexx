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
$referenceNumberInput = substr(trim($_POST['reference_number']), 0, 500); // Allow longer string for comma-separated refs
$trip = isset($_POST['trip']) && !empty($_POST['trip']) ? (int)$_POST['trip'] : NULL;
$bundleRefsJson = isset($_POST['bundle_refs']) ? $_POST['bundle_refs'] : '';

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
        trip INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_deleted TINYINT(1) DEFAULT 0,
        who_did VARCHAR(100) DEFAULT NULL,
        deleted_at DATETIME DEFAULT NULL
    )";
    $conn->query($createTable);
    
    // Add reference_number column if upgrading from old schema
    $checkRefCol = $conn->query("SHOW COLUMNS FROM roll_received LIKE 'reference_number'");
    if (!$checkRefCol || $checkRefCol->num_rows == 0) {
        $conn->query("ALTER TABLE roll_received ADD COLUMN reference_number VARCHAR(100) DEFAULT NULL");
    }
    
    // Add trip column if it doesn't exist
    $checkTripCol = $conn->query("SHOW COLUMNS FROM roll_received LIKE 'trip'");
    if (!$checkTripCol || $checkTripCol->num_rows == 0) {
        $conn->query("ALTER TABLE roll_received ADD COLUMN trip INT DEFAULT NULL");
    }

    // Prepare INSERT statement
    $stmt = $conn->prepare("INSERT INTO roll_received (
        reporting_time, reporter_id, receiver_name, project_id, reference_number, trip
    ) VALUES (?, ?, ?, ?, ?, ?)");

    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    // Check if this is a bundle submission
    $bundleRefs = [];
    if (!empty($bundleRefsJson)) {
        $bundleRefs = json_decode($bundleRefsJson, true);
        if (!is_array($bundleRefs)) {
            $bundleRefs = [];
        }
    }

    // If bundle refs are provided, insert a record for each individual roll
    if (!empty($bundleRefs) && count($bundleRefs) > 0) {
        $insertedCount = 0;
        foreach ($bundleRefs as $bundleRef) {
            $bundleRef = trim($bundleRef);
            if (empty($bundleRef)) {
                continue;
            }
            
            $stmt->bind_param(
                'sisisi',
                $reportingTime,
                $reporterId,
                $receiverName,
                $projectId,
                $bundleRef,
                $trip
            );

            if (!$stmt->execute()) {
                throw new Exception('Execute failed for bundle ref ' . $bundleRef . ': ' . $stmt->error);
            }
            $insertedCount++;
        }
        
        $message = $insertedCount > 1 
            ? "Bundle received! " . $insertedCount . " rolls saved."
            : "Roll received entry saved!";
    } else {
        // Handle comma-separated references (all references for a trip)
        $referenceNumbers = [];
        if (strpos($referenceNumberInput, ',') !== false) {
            // Split by comma and trim each reference
            $referenceNumbers = array_map('trim', explode(',', $referenceNumberInput));
            $referenceNumbers = array_filter($referenceNumbers); // Remove empty values
        } else {
            // Single reference
            $referenceNumbers = [trim($referenceNumberInput)];
        }
        
        $insertedCount = 0;
        foreach ($referenceNumbers as $ref) {
            $ref = trim($ref);
            if (empty($ref)) {
                continue;
            }
            
            $stmt->bind_param(
                'sisisi',
                $reportingTime,
                $reporterId,
                $receiverName,
                $projectId,
                $ref,
                $trip
            );

            if (!$stmt->execute()) {
                throw new Exception('Execute failed for reference ' . $ref . ': ' . $stmt->error);
            }
            $insertedCount++;
        }
        
        if ($insertedCount > 1) {
            $message = "$insertedCount references received successfully";
        } else {
            $message = "Roll received entry saved! Reference Number: " . $referenceNumberInput;
        }
    }

    $stmt->close();

    header('Location: ../forms/roll_received_entry.php?success=' . urlencode($message));
    exit;
} catch (Throwable $e) {
    header('Location: ../forms/roll_received_entry.php?error=' . urlencode($e->getMessage()));
    exit;
}
?>

