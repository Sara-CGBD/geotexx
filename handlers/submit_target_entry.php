<?php
session_start();
require_once '../config/security_config.php';

// Security/session checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}
if (SecurityConfig::checkSessionTimeout()) {
    session_destroy();
    header("Location: ../login.html?error=timeout");
    exit();
}
SecurityConfig::updateSessionActivity();
if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    session_destroy();
    header("Location: ../login.html?error=disabled");
    exit();
}

date_default_timezone_set('Asia/Dhaka');

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../forms/target_entry.php");
    exit();
}

// Get form data
$module_id = intval($_POST['module_id'] ?? 0);
$target_qty = floatval($_POST['target_qty'] ?? 0);
$target_period = trim($_POST['target_period'] ?? '');
$production_qty = floatval($_POST['production_qty'] ?? 0);
$target_date = trim($_POST['target_date'] ?? '');
$remarks = trim($_POST['remarks'] ?? '');
$dateTime = $_POST['dateTime'] ?? date('Y-m-d H:i:s');

// Validate input
$errors = [];

if ($module_id <= 0) {
    $errors[] = "Please select a module";
}
if ($target_qty <= 0) {
    $errors[] = "Target quantity must be greater than 0";
}
if (empty($target_period)) {
    $errors[] = "Please select a target period";
}
if ($production_qty < 0) {
    $errors[] = "Production quantity cannot be negative";
}
if (empty($target_date)) {
    $errors[] = "Target date is required";
}

// If there are validation errors, redirect back with error
if (!empty($errors)) {
    $error_msg = implode(', ', $errors);
    header("Location: ../forms/target_entry.php?error=" . urlencode($error_msg));
    exit();
}

// Database connection
$conn = SecurityConfig::getConnection();

// Insert target entry
$insert_sql = "INSERT INTO production_targets (module_id, target_qty, target_period, production_qty, target_date) VALUES (?, ?, ?, ?, ?)";
$insert_stmt = $conn->prepare($insert_sql);
$insert_stmt->bind_param("idsds", $module_id, $target_qty, $target_period, $production_qty, $target_date);

if ($insert_stmt->execute()) {
    $conn->close();
    header("Location: ../forms/target_entry.php?success=1");
    exit();
} else {
    $error_msg = $insert_stmt->error;
    $conn->close();
    header("Location: ../forms/target_entry.php?error=" . urlencode("Failed to create target entry: " . $error_msg));
    exit();
}
?>


