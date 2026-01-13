<?php
session_start();
require_once '../config/security_config.php';

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Set timezone to Bangladesh
date_default_timezone_set('Asia/Dhaka');

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Get POST data
$brandingId = trim($_POST['brandingId'] ?? '');
$dateTime = $_POST['dateTime'] ?? '';
$shiftIncharge = trim($_POST['shiftIncharge'] ?? '');
$referenceNumber = trim($_POST['referenceNumber'] ?? '');
$cncCuttingBatch = trim($_POST['cncCuttingBatch'] ?? '');
$projectId = (int)($_POST['project'] ?? 0);
$printMachine = trim($_POST['printMachine'] ?? '');
$bagSize = trim($_POST['bagSize'] ?? '');
$printQty = (int)($_POST['printQty'] ?? 0);
$reporterId = $_SESSION['user_id'] ?? 0;
$reporterName = $_SESSION['username'] ?? 'Unknown';

// Validate required fields (referenceNumber is now optional)
$required = ['brandingId', 'dateTime', 'shiftIncharge', 'cncCuttingBatch', 'projectId', 'printMachine', 'bagSize', 'printQty'];
foreach ($required as $field) {
    if (empty($$field)) {
        header("Location: ../forms/branding_entry.php?error=" . urlencode("Missing required field: $field"));
        exit;
    }
}

try {
    $conn = SecurityConfig::getConnection();

    // Create branding_entries table if it doesn't exist
    $createTable = "CREATE TABLE IF NOT EXISTS branding_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branding_id VARCHAR(50) UNIQUE,
        date_time DATETIME,
        shift_incharge VARCHAR(100),
        reference_number VARCHAR(100),
        cnc_cutting_batch VARCHAR(100),
        project_id INT,
        print_machine VARCHAR(100),
        bag_size VARCHAR(100),
        print_qty INT,
        reporter_id INT,
        reporter_name VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_deleted TINYINT(1) DEFAULT 0,
        who_did VARCHAR(100) DEFAULT '',
        deleted_at DATETIME NULL
    )";
    $conn->query($createTable);

    // Ensure all columns exist
    $conn->query("ALTER TABLE branding_entries ADD COLUMN IF NOT EXISTS branding_id VARCHAR(50) UNIQUE");
    $conn->query("ALTER TABLE branding_entries ADD COLUMN IF NOT EXISTS reference_number VARCHAR(100)");
    $conn->query("ALTER TABLE branding_entries ADD COLUMN IF NOT EXISTS cnc_cutting_batch VARCHAR(100)");
    $conn->query("ALTER TABLE branding_entries ADD COLUMN IF NOT EXISTS print_machine VARCHAR(100)");
    
    // Drop obsolete columns
    $conn->query("ALTER TABLE branding_entries DROP COLUMN IF EXISTS machine_id");
    $conn->query("ALTER TABLE branding_entries DROP COLUMN IF EXISTS ncp_pcs");

    // Insert the branding entry (reference_number is optional, can be NULL)
    $stmt = $conn->prepare("INSERT INTO branding_entries (branding_id, date_time, shift_incharge, reference_number, cnc_cutting_batch, project_id, print_machine, bag_size, print_qty, reporter_id, reporter_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    // Set reference_number to empty string if not provided (will be stored as NULL in DB)
    if (empty($referenceNumber)) {
        $referenceNumber = '';
    }

    $stmt->bind_param(
        'ssssssssiis',  // branding_id(s), date_time(s), shift_incharge(s), reference_number(s), cnc_cutting_batch(s), project_id(i), print_machine(s), bag_size(s), print_qty(i), reporter_id(i), reporter_name(s)
        $brandingId,
        $dateTime,
        $shiftIncharge,
        $referenceNumber,
        $cncCuttingBatch,
        $projectId,
        $printMachine,
        $bagSize,
        $printQty,
        $reporterId,
        $reporterName
    );

    // Execute the statement
    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    $stmt->close();
    $conn->close();

    // Redirect to form with success message
    header("Location: ../forms/branding_entry.php?success=branding_entry_saved&branding_id=" . urlencode($brandingId));

} catch (Exception $e) {
    error_log("Branding entry error: " . $e->getMessage());
    header("Location: ../forms/branding_entry.php?error=" . urlencode($e->getMessage()));
}
?>
