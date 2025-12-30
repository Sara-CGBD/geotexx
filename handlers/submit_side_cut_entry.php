<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../forms/side_cut_entry.php');
    exit;
}

// Validate required fields
$required = ['entry_id', 'entry_date', 'shift', 'category', 'quantity_kg', 'reporter_id', 'reporter_name'];
foreach ($required as $key) {
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        header('Location: ../forms/side_cut_entry.php?error=' . urlencode('Missing field: ' . $key));
        exit;
    }
}

// Get form data
$entryId = trim($_POST['entry_id']);
$entryDate = trim($_POST['entry_date']);
$shift = trim($_POST['shift']);
$category = trim($_POST['category']);
$referenceNumber = isset($_POST['reference_number']) ? trim($_POST['reference_number']) : NULL;
$cuttingBatchNo = isset($_POST['cutting_batch_no']) ? trim($_POST['cutting_batch_no']) : NULL;
$quantityKg = (float)$_POST['quantity_kg'];
$reporterId = (int)$_POST['reporter_id'];
$reporterName = trim($_POST['reporter_name']);
$remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : NULL;

// Additional validation
if ($category === 'Sheet Production' && empty($referenceNumber)) {
    header('Location: ../forms/side_cut_entry.php?error=' . urlencode('Reference Number is required for Sheet Production'));
    exit;
}
if ($category === 'Swing Production' && empty($cuttingBatchNo)) {
    header('Location: ../forms/side_cut_entry.php?error=' . urlencode('CNC Cutting Batch is required for Swing Production'));
    exit;
}

try {
    $conn = SecurityConfig::getConnection();
    
    // Ensure columns exist
    $conn->query("ALTER TABLE side_cut_scrap ADD COLUMN IF NOT EXISTS entry_id VARCHAR(50) UNIQUE");
    $conn->query("ALTER TABLE side_cut_scrap ADD COLUMN IF NOT EXISTS reference_number VARCHAR(100)");
    
    // Insert new entry (entry_id is unique, so duplicate will fail gracefully)
    $insertStmt = $conn->prepare("INSERT INTO side_cut_scrap (entry_id, entry_date, shift, category, reference_number, cutting_batch_no, quantity_kg, reporter_id, reporter_name, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insertStmt->bind_param('sssssssdis', $entryId, $entryDate, $shift, $category, $referenceNumber, $cuttingBatchNo, $quantityKg, $reporterId, $reporterName, $remarks);
    
    if (!$insertStmt->execute()) {
        throw new Exception('Insert failed: ' . $insertStmt->error);
    }
    $insertStmt->close();
    $message = 'Side Cut Entry saved successfully!';
    
    $conn->close();
    
    header('Location: ../forms/side_cut_entry.php?success=' . urlencode($message));
    exit;
    
} catch (Exception $e) {
    error_log("Side Cut Entry error: " . $e->getMessage());
    header('Location: ../forms/side_cut_entry.php?error=' . urlencode($e->getMessage()));
    exit;
}
?>


