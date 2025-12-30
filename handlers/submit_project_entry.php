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
    header("Location: ../forms/project_entry.php");
    exit();
}

// Get form data
$project_id = intval($_POST['project_id'] ?? 0);
$project_name = trim($_POST['project_name'] ?? '');
$project_value = floatval($_POST['project_value'] ?? 0);
$produced_qty = intval($_POST['produced_qty'] ?? 0);
$dateTime = $_POST['dateTime'] ?? date('Y-m-d H:i:s');
$shift = $_POST['shift'] ?? '';

// Validate input
$errors = [];

if ($project_id <= 0) {
    $errors[] = "Invalid Project ID";
}
if (empty($project_name)) {
    $errors[] = "Project name is required";
}
if ($project_value <= 0) {
    $errors[] = "Project value must be greater than 0";
}
if ($produced_qty <= 0) {
    $errors[] = "Produced quantity must be greater than 0";
}

// If there are validation errors, redirect back with error
if (!empty($errors)) {
    $error_msg = implode(', ', $errors);
    header("Location: ../forms/project_entry.php?error=" . urlencode($error_msg));
    exit();
}

// Database connection
$conn = SecurityConfig::getConnection();

// Check if project ID already exists
$check_sql = "SELECT id FROM projects WHERE id = ?";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("i", $project_id);
$check_stmt->execute();
$result = $check_stmt->get_result();

if ($result->num_rows > 0) {
    $conn->close();
    header("Location: ../forms/project_entry.php?error=" . urlencode("Project ID already exists"));
    exit();
}

// Insert project entry - store additional data in description
$description = "Project Value: " . number_format($project_value, 2) . " BDT | Produced Quantity: " . $produced_qty;
$insert_sql = "INSERT INTO projects (id, project_name, belt_weight, description, who_did, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
$insert_stmt = $conn->prepare($insert_sql);
$belt_weight = 0.00; // Default value
$who_did = $_SESSION['username'] ?? 'Unknown';
$insert_stmt->bind_param("isdss", $project_id, $project_name, $belt_weight, $description, $who_did);

if ($insert_stmt->execute()) {
    $conn->close();
    header("Location: ../forms/project_entry.php?success=" . urlencode("Project entry created successfully!"));
    exit();
} else {
    $error_msg = $insert_stmt->error;
    $conn->close();
    header("Location: ../forms/project_entry.php?error=" . urlencode("Failed to create project entry: " . $error_msg));
    exit();
}
?>



