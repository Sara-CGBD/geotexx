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
    header("Location: ../forms/material_consumption_entry.php");
    exit();
}

// Get form data
$consumption_id = intval($_POST['consumption_id'] ?? 0);
$material_name = trim($_POST['material_name'] ?? ''); // Get material_name directly from form
$material_id = 0; // Not using foreign key, set to 0
$manufacturer_name = trim($_POST['manufacturer_name'] ?? '');
$consumption_type = trim($_POST['consumption_type'] ?? '');
$project_id = !empty($_POST['project_id']) ? intval($_POST['project_id']) : NULL;
$quantity = floatval($_POST['quantity'] ?? 0);
$unit = trim($_POST['unit'] ?? '');
$operator = trim($_POST['operator'] ?? $_SESSION['username']);
$remarks = trim($_POST['remarks'] ?? '');
$dateTime = $_POST['dateTime'] ?? date('Y-m-d H:i:s');
$shift = $_POST['shift'] ?? '';

// Validate input
$errors = [];

if ($consumption_id <= 0) {
    $errors[] = "Invalid Consumption ID";
}
if (empty($material_name)) {
    $errors[] = "Material name is required";
}
if (empty($manufacturer_name)) {
    $errors[] = "Please enter manufacturer name";
}
if (empty($consumption_type)) {
    $errors[] = "Please select a consumption type";
}
if ($quantity <= 0) {
    $errors[] = "Quantity must be greater than 0";
}
if (empty($unit)) {
    $errors[] = "Please select a unit";
}

// If there are validation errors, redirect back with error
if (!empty($errors)) {
    $error_msg = implode(', ', $errors);
    header("Location: ../forms/material_consumption_entry.php?error=" . urlencode($error_msg));
    exit();
}

// Database connection
$conn = SecurityConfig::getConnection();

// Check if manufacturer_name column exists, if not add it
$check_column = $conn->query("SHOW COLUMNS FROM material_consumption LIKE 'manufacturer_name'");
if ($check_column && $check_column->num_rows == 0) {
    $conn->query("ALTER TABLE material_consumption ADD COLUMN manufacturer_name VARCHAR(255) NULL AFTER material_name");
}

// Insert consumption entry (material_id set to 0, using material_name directly)
$insert_sql = "INSERT INTO material_consumption 
    (consumption_date, shift, material_id, material_name, manufacturer_name, consumption_type, project_id, quantity, unit, operator, remarks, created_by, who_did) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
$insert_stmt = $conn->prepare($insert_sql);
$who_did = $_SESSION['username'];
$insert_stmt->bind_param("ssisssidsssis", 
    $dateTime, 
    $shift, 
    $material_id, 
    $material_name,
    $manufacturer_name,
    $consumption_type, 
    $project_id, 
    $quantity, 
    $unit, 
    $operator, 
    $remarks,
    $_SESSION['user_id'],
    $who_did
);

if ($insert_stmt->execute()) {
    $conn->close();
    header("Location: ../forms/material_consumption_entry.php?success=" . urlencode("Material consumption recorded successfully!"));
    exit();
} else {
    $error_msg = $insert_stmt->error;
    $conn->close();
    header("Location: ../forms/material_consumption_entry.php?error=" . urlencode("Failed to record consumption: " . $error_msg));
    exit();
}
?>



