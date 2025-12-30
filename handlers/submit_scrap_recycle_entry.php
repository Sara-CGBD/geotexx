<?php
// handlers/submit_scrap_recycle_entry.php

session_start();
require_once '../config/security_config.php';

// Block direct GET access
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../forms/scrap_recycle_entry.php?error=invalid_request");
    exit();
}

// Session check
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

try {
    $conn = SecurityConfig::getConnection();

    // Ensure scrap_recycle table exists
    $conn->query("CREATE TABLE IF NOT EXISTS scrap_recycle (
        id INT AUTO_INCREMENT PRIMARY KEY,
        scrap_id INT NOT NULL,
        recycled_qty DECIMAL(10,2) NOT NULL,
        user_id INT NOT NULL,
        recycled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        remarks TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ensure all required columns exist
    $required_columns = [
        'recycle_id' => 'VARCHAR(20)',
        'remarks' => 'TEXT',
        'scrap_type' => 'VARCHAR(20)'
    ];
    
    foreach ($required_columns as $column_name => $column_type) {
        $column_check = $conn->query("SHOW COLUMNS FROM scrap_recycle LIKE '$column_name'");
        if ($column_check && $column_check->num_rows === 0) {
            if ($column_name === 'recycle_id') {
                // Add recycle_id column without UNIQUE constraint first
                $conn->query("ALTER TABLE scrap_recycle ADD COLUMN $column_name $column_type AFTER id");
                // Then add UNIQUE constraint
                $conn->query("ALTER TABLE scrap_recycle ADD UNIQUE KEY unique_recycle_id ($column_name)");
            } else {
                // Add other columns normally
                $conn->query("ALTER TABLE scrap_recycle ADD COLUMN $column_name $column_type");
            }
        }
        if ($column_check) $column_check->close();
    }

    // Collect and validate POST data
    $recycle_id = trim($_POST['recycle_id'] ?? '');
    $scrap_id = (int)($_POST['scrap_id'] ?? 0);
    $scrap_type = trim($_POST['scrap_type'] ?? 'scrap'); // 'scrap' or 'side_cut'
    $recycled_qty = (float)($_POST['recycled_qty'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? '');
    $user_id = $_SESSION['user_id'];

    // Debug logging
    error_log("Scrap recycle POST data: " . print_r($_POST, true));
    error_log("Scrap recycle processed data - recycle_id: $recycle_id, scrap_id: $scrap_id, recycled_qty: $recycled_qty, user_id: $user_id");

    // Validation
    if (empty($recycle_id)) {
        header("Location: ../forms/scrap_recycle_entry.php?error=" . urlencode('Recycle ID is required'));
        exit();
    }

    if ($scrap_id <= 0) {
        header("Location: ../forms/scrap_recycle_entry.php?error=" . urlencode('Please select a valid scrap entry'));
        exit();
    }

    if ($recycled_qty <= 0) {
        header("Location: ../forms/scrap_recycle_entry.php?error=" . urlencode('Recycled quantity must be greater than 0'));
        exit();
    }

    // Check if scrap exists and get available quantity from the correct table
    $table_name = ($scrap_type === 'side_cut') ? 'side_cut_scrap' : 'scrap';
    $qty_column = ($scrap_type === 'side_cut') ? 'quantity_kg' : 'qty';
    
    $check_query = "SELECT id, $qty_column as scrap_qty FROM $table_name WHERE id = ?";
    $check = $conn->prepare($check_query);
    $check->bind_param("i", $scrap_id);
    $check->execute();
    $result = $check->get_result();
    
    if ($result->num_rows === 0) {
        $check->close();
        header("Location: ../forms/scrap_recycle_entry.php?error=" . urlencode('Selected scrap entry not found'));
        exit();
    }
    
    $scrap_data = $result->fetch_assoc();
    $available_qty = (float)$scrap_data['scrap_qty'];
    $check->close();

    // Check if recycled quantity exceeds available scrap
    if ($recycled_qty > $available_qty) {
        header("Location: ../forms/scrap_recycle_entry.php?error=" . urlencode('Recycled quantity (' . $recycled_qty . ') exceeds available scrap quantity (' . $available_qty . ')'));
        exit();
    }

    // Check for existing recycling records for this scrap (filter by scrap_type)
    $existing_check = $conn->prepare("SELECT SUM(recycled_qty) as total_recycled FROM scrap_recycle WHERE scrap_id = ? AND scrap_type = ?");
    $existing_check->bind_param("is", $scrap_id, $scrap_type);
    $existing_check->execute();
    $existing_result = $existing_check->get_result();
    $total_recycled = (float)($existing_result->fetch_assoc()['total_recycled'] ?? 0);
    $existing_check->close();

    // Check if total recycled quantity (including this entry) exceeds available scrap
    if (($total_recycled + $recycled_qty) > $available_qty) {
        header("Location: ../forms/scrap_recycle_entry.php?error=" . urlencode('Total recycled quantity would exceed available scrap. Already recycled: ' . $total_recycled . ', Available: ' . $available_qty));
        exit();
    }

    // Check if recycle ID already exists
    $duplicate_check = $conn->prepare("SELECT id FROM scrap_recycle WHERE recycle_id = ?");
    $duplicate_check->bind_param("s", $recycle_id);
    $duplicate_check->execute();
    if ($duplicate_check->get_result()->num_rows > 0) {
        $duplicate_check->close();
        header("Location: ../forms/scrap_recycle_entry.php?error=" . urlencode('Recycle ID already exists. Please refresh the page to get a new ID.'));
        exit();
    }
    $duplicate_check->close();

    // Insert recycling record
    $stmt = $conn->prepare("
        INSERT INTO scrap_recycle (recycle_id, scrap_id, scrap_type, recycled_qty, user_id, remarks, machine_id, recycled_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $machine_id = $_POST['machine_id'] ?? '';
    $stmt->bind_param("sisdiss", $recycle_id, $scrap_id, $scrap_type, $recycled_qty, $user_id, $remarks, $machine_id);

    if ($stmt->execute()) {
        $insert_id = $stmt->insert_id;
        $stmt->close();
        
        // Log the activity
        error_log("Scrap recycling successful - Recycle ID: $recycle_id, DB ID: $insert_id, Scrap ID: $scrap_id, Quantity: $recycled_qty, User: " . $_SESSION['username']);
        
        header("Location: ../forms/scrap_recycle_entry.php?success=" . urlencode('Scrap recycling recorded successfully! Recycle ID: ' . $recycle_id));
    } else {
        $stmt->close();
        header("Location: ../forms/scrap_recycle_entry.php?error=" . urlencode('Database error occurred. Please try again.'));
    }

    $conn->close();

} catch (Exception $e) {
    error_log("Scrap recycle insert error: " . $e->getMessage());
    error_log("Scrap recycle error trace: " . $e->getTraceAsString());
    header("Location: ../forms/scrap_recycle_entry.php?error=" . urlencode('Server error occurred. Please try again. Error: ' . $e->getMessage()));
    exit();
} catch (Throwable $e) {
    error_log("Scrap recycle fatal error: " . $e->getMessage());
    error_log("Scrap recycle fatal trace: " . $e->getTraceAsString());
    header("Location: ../forms/scrap_recycle_entry.php?error=" . urlencode('Unexpected error occurred. Please contact administrator. Error: ' . $e->getMessage()));
    exit();
}
?>

