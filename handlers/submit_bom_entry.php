<?php
// submit_bom_entry.php

session_start();
require_once '../config/security_config.php';

// Security/session checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}
if (SecurityConfig::checkSessionTimeout()) {
    session_destroy();
    header("Location: login.html?error=timeout");
    exit();
}
SecurityConfig::updateSessionActivity();
if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    session_destroy();
    header("Location: login.html?error=disabled");
    exit();
}

date_default_timezone_set('Asia/Dhaka');

// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../forms/BOM_entry.php");
    exit();
}

// Get form data
$bom_id = intval($_POST['bom_id'] ?? 0);
$product_id = 0; // Removed product selection from form
$product_type = trim($_POST['product_type'] ?? 'bag'); // 'bag' or 'roll'
$material_id = intval($_POST['material_id'] ?? 0);
$material_name = trim($_POST['material_name'] ?? '');
$unit_price = floatval($_POST['unit_price'] ?? 0);
$cost = floatval($_POST['cost'] ?? 0);

// Get size, weight, GSM, thickness based on product type
if ($product_type === 'roll') {
    $bag_size = trim($_POST['roll_size'] ?? '');
    // Use custom roll size if provided and no preset selected
    if (empty($bag_size) && !empty($_POST['roll_size_custom'])) {
        $bag_size = trim($_POST['roll_size_custom']);
    }
    $weight = floatval($_POST['roll_weight'] ?? 0);
    $gsm = floatval($_POST['roll_gsm'] ?? 0);
    $thickness_mm = 0; // Thickness not required for roll
} else {
    $bag_size = trim($_POST['bag_size'] ?? '');
    $weight = floatval($_POST['weight'] ?? 0);
    $gsm = floatval($_POST['gsm'] ?? 0);
    $thickness_mm = floatval($_POST['thickness_mm'] ?? 0);
}

// Validate input
$errors = [];

if ($bom_id <= 0) {
    $errors[] = "Invalid BOM ID";
}
if (!in_array($product_type, ['bag', 'roll'])) {
    $errors[] = "Please select a product type (Bag or Roll)";
}
// Accept either material_id or material_name
if ($material_id <= 0 && $material_name === '') {
    $errors[] = "Please select a material type";
}
if (empty($bag_size)) {
    $errors[] = ($product_type === 'roll') ? "Roll size is required" : "Bag size is required";
}
if ($product_type === 'bag') {
    if ($gsm <= 0) {
        $errors[] = "GSM is required and must be greater than 0";
    }
    if ($thickness_mm <= 0) {
        $errors[] = "Thickness is required and must be greater than 0";
    }
}
if ($weight <= 0) {
    $errors[] = "Weight must be greater than 0";
}
if ($cost <= 0) {
    $errors[] = "Material cost must be greater than 0";
}

// If there are validation errors, redirect back with error
if (!empty($errors)) {
    $error_msg = implode(', ', $errors);
    header("Location: ../forms/BOM_entry.php?error=" . urlencode($error_msg));
    exit();
}

// Database connection
$conn = SecurityConfig::getConnection();

// Check if BOM ID already exists
$check_sql = "SELECT id FROM bom WHERE id = ?";
$check_stmt = $conn->prepare($check_sql);
$check_stmt->bind_param("i", $bom_id);
$check_stmt->execute();
$result = $check_stmt->get_result();

if ($result->num_rows > 0) {
    $conn->close();
    header("Location: ../forms/BOM_entry.php?error=" . urlencode("BOM ID already exists"));
    exit();
}

// Resolve material_id from material_name if needed (create if not exists)
if ($material_id <= 0 && $material_name !== '') {
    $check = $conn->prepare("SELECT id FROM materials WHERE material_name = ? AND is_deleted = 0 LIMIT 1");
    $check->bind_param('s', $material_name);
    $check->execute();
    $res = $check->get_result();
    if ($row = $res->fetch_assoc()) {
        $material_id = (int)$row['id'];
    } else {
        $ins = $conn->prepare("INSERT INTO materials (material_name, is_deleted) VALUES (?, 0)");
        $ins->bind_param('s', $material_name);
        $ins->execute();
        $material_id = $ins->insert_id;
        $ins->close();
    }
    $check->close();
}

// Check if bom table has columns, if not add them
$check_weight_col = $conn->query("SHOW COLUMNS FROM bom LIKE 'weight'");
if (!$check_weight_col || $check_weight_col->num_rows == 0) {
    $conn->query("ALTER TABLE bom ADD COLUMN weight DECIMAL(10,2) DEFAULT 0");
}
$check_gsm_col = $conn->query("SHOW COLUMNS FROM bom LIKE 'gsm'");
if (!$check_gsm_col || $check_gsm_col->num_rows == 0) {
    $conn->query("ALTER TABLE bom ADD COLUMN gsm DECIMAL(10,2) DEFAULT NULL");
}
$check_thickness_col = $conn->query("SHOW COLUMNS FROM bom LIKE 'thickness_mm'");
if (!$check_thickness_col || $check_thickness_col->num_rows == 0) {
    $conn->query("ALTER TABLE bom ADD COLUMN thickness_mm DECIMAL(10,2) DEFAULT NULL");
}
// Add product_type column for Roll/Bag distinction
$check_product_type_col = $conn->query("SHOW COLUMNS FROM bom LIKE 'product_type'");
if (!$check_product_type_col || $check_product_type_col->num_rows == 0) {
    $conn->query("ALTER TABLE bom ADD COLUMN product_type VARCHAR(20) DEFAULT 'bag' AFTER id");
}

// Make product_id nullable if not already
$check_product_id = $conn->query("SHOW COLUMNS FROM bom WHERE Field = 'product_id'");
if ($check_product_id && $check_product_id->num_rows > 0) {
    $col_info = $check_product_id->fetch_assoc();
    if ($col_info['Null'] === 'NO') {
        $conn->query("ALTER TABLE bom MODIFY COLUMN product_id INT DEFAULT NULL");
    }
}

// Insert BOM entry (product_id set to NULL)
$insert_sql = "INSERT INTO bom (id, product_type, product_id, material_id, unit_price, bag_size, weight, cost, gsm, thickness_mm, created_by, created_at) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
$insert_stmt = $conn->prepare($insert_sql);
$insert_stmt->bind_param("isidsddddi", $bom_id, $product_type, $material_id, $unit_price, $bag_size, $weight, $cost, $gsm, $thickness_mm, $_SESSION['user_id']);

if ($insert_stmt->execute()) {
    $conn->close();
    $typeLabel = ($product_type === 'roll') ? 'Roll' : 'Bag';
    header("Location: ../forms/BOM_entry.php?success=" . urlencode("BOM entry for $typeLabel created successfully!"));
    exit();
} else {
    $conn->close();
    header("Location: ../forms/BOM_entry.php?error=" . urlencode("Failed to create BOM entry: " . $conn->error));
    exit();
}
?>


