<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'php_error.log');
error_reporting(E_ALL);

session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../forms/scrap_entry.php');
    exit;
}

// Check scrap category
if (!isset($_POST['scrap_category']) || $_POST['scrap_category'] === '') {
    header('Location: ../forms/scrap_entry.php?error=' . urlencode('Missing field: scrap_category'));
    exit;
}

$scrapCategory = trim($_POST['scrap_category']);
$referenceNumber = '';
$cuttingBatch = '';
$scrapProduct = '';
$scrapType = '';
$qty = 0;
$shift = isset($_POST['shift']) ? trim($_POST['shift']) : '';

// Handle Sheet Production Scrap
if ($scrapCategory === 'Sheet Production Scrap') {
    $required = ['sheet_reference', 'sheet_product', 'sheet_type', 'sheet_qty'];
    foreach ($required as $key) {
        if (!isset($_POST[$key]) || $_POST[$key] === '') {
            header('Location: ../forms/scrap_entry.php?error=' . urlencode('Missing field: ' . $key));
            exit;
        }
    }
    $referenceNumber = substr(trim($_POST['sheet_reference']), 0, 100);
    $scrapProduct = substr(trim($_POST['sheet_product']), 0, 100);
    $scrapType = substr(trim($_POST['sheet_type']), 0, 50);
    $qty = (float)$_POST['sheet_qty'];
}

// Handle Sewing Scrap
if ($scrapCategory === 'Sewing Scrap') {
    $required = ['swing_cutting_batch', 'swing_product', 'swing_type', 'swing_qty'];
    foreach ($required as $key) {
        if (!isset($_POST[$key]) || $_POST[$key] === '') {
            header('Location: ../forms/scrap_entry.php?error=' . urlencode('Missing field: ' . $key));
            exit;
        }
    }
    $cuttingBatch = substr(trim($_POST['swing_cutting_batch']), 0, 100);
    $scrapProduct = substr(trim($_POST['swing_product']), 0, 100);
    $scrapType = substr(trim($_POST['swing_type']), 0, 50);
    $qty = (float)$_POST['swing_qty'];
}
// Optional fields from form
$scrapIdFromForm = isset($_POST['scrap_id']) ? trim($_POST['scrap_id']) : '';
$dateTime = isset($_POST['dateTime']) ? trim($_POST['dateTime']) : '';

try {
    $conn = SecurityConfig::getConnection();

    // Ensure columns exist on scrap table
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS scrap_id VARCHAR(50) UNIQUE");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS date_time DATETIME");

    // Relax legacy FK to production and allow NULL prod_id so category-based scrap can be saved
    // Check if FK exists before dropping - using prepared statement
    $dbNameResult = $conn->query("SELECT DATABASE() AS d");
    $dbName = ($dbNameResult) ? $dbNameResult->fetch_assoc()['d'] : '';
    if ($dbName) {
        $fkCheckStmt = $conn->prepare("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'scrap' AND CONSTRAINT_NAME = 'scrap_ibfk_1'");
        if ($fkCheckStmt) {
            $fkCheckStmt->bind_param("s", $dbName);
            $fkCheckStmt->execute();
            $fkCheckResult = $fkCheckStmt->get_result();
            
            if ($fkCheckResult && $fkCheckResult->num_rows > 0) {
                $conn->query("SET FOREIGN_KEY_CHECKS=0");
                $conn->query("ALTER TABLE scrap DROP FOREIGN KEY scrap_ibfk_1");
                $conn->query("SET FOREIGN_KEY_CHECKS=1");
            }
            $fkCheckStmt->close();
        }
    }
    
    // Allow NULL for prod_id
    $conn->query("ALTER TABLE scrap MODIFY COLUMN prod_id INT NULL");

    // No external FK validation; scrap product/type are categorical

    // If scrap_id not provided, generate one (SC-YYYYMMDD-XXX) - using prepared statement
    if ($scrapIdFromForm === '') {
        date_default_timezone_set('Asia/Dhaka');
        $today = date('Y-m-d');
        $nextNum = 1;
        $resStmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(scrap_id, -3) AS UNSIGNED)) AS last_num FROM scrap WHERE DATE(COALESCE(date_time, NOW())) = ?");
        if ($resStmt) {
            $resStmt->bind_param("s", $today);
            $resStmt->execute();
            $resResult = $resStmt->get_result();
            if ($resResult && $row = $resResult->fetch_assoc()) {
                if (!empty($row['last_num'])) { $nextNum = ((int)$row['last_num']) + 1; }
            }
            $resStmt->close();
        }
        $scrapIdFromForm = 'SC-' . date('Ymd') . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
    }

    // If dateTime not provided, use current BD time
    if ($dateTime === '') {
        date_default_timezone_set('Asia/Dhaka');
        $dateTime = date('Y-m-d H:i:s');
    }

    // Get reporter info from session
    $reporterId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $reporterName = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : (isset($_SESSION['username']) ? $_SESSION['username'] : '');

    // Ensure columns exist
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS scrap_category VARCHAR(100)");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS reference_number VARCHAR(100)");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS cutting_batch VARCHAR(100)");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS scrap_product VARCHAR(100)");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS scrap_type VARCHAR(100)");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS shift VARCHAR(20)");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS reporter_id INT");
    $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS reporter_name VARCHAR(100)");

    // Check if this is an update
    if (isset($_POST['edit_id']) && !empty($_POST['edit_id'])) {
        $editId = (int)$_POST['edit_id'];
        $stmt = $conn->prepare('UPDATE scrap SET scrap_category = ?, reference_number = ?, cutting_batch = ?, scrap_product = ?, scrap_type = ?, qty = ?, shift = ?, reporter_id = ?, reporter_name = ? WHERE id = ?');
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('sssssdsisi', $scrapCategory, $referenceNumber, $cuttingBatch, $scrapProduct, $scrapType, $qty, $shift, $reporterId, $reporterName, $editId);
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        $message = 'Scrap entry updated successfully';
        header('Location: ../forms/scrap_entry.php?success=' . urlencode($message) . '&scrap_id=' . urlencode($scrapIdFromForm));
    } else {
        // Insert new entry
        $stmt = $conn->prepare('INSERT INTO scrap (scrap_id, date_time, scrap_category, reference_number, cutting_batch, scrap_product, scrap_type, qty, shift, reporter_id, reporter_name, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)');
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        $stmt->bind_param('sssssssdsis', $scrapIdFromForm, $dateTime, $scrapCategory, $referenceNumber, $cuttingBatch, $scrapProduct, $scrapType, $qty, $shift, $reporterId, $reporterName);
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        $insertedId = $conn->insert_id;
        $message = 'Scrap entry saved successfully';
        header('Location: ../forms/scrap_entry.php?success=' . urlencode($message) . '&scrap_id=' . urlencode($scrapIdFromForm) . '&last_id=' . $insertedId);
    }
    exit;
} catch (Throwable $e) {
    header('Location: ../forms/scrap_entry.php?error=' . urlencode($e->getMessage()));
    exit;
}
?>



