<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$is_admin = in_array($user_role, ['admin', 'agm', 'agm ops', 'agm operations']);

if (!$is_admin) {
    header("Location: ../admin/qc_test_approval_dashboard.php?error=" . urlencode('Access Denied'));
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

try {
    $action = $_POST['action'] ?? '';
    $roll_reference = trim($_POST['roll_reference'] ?? '');
    $destination = trim($_POST['roll_destination'] ?? '');
    $comment = trim($_POST['routing_comment'] ?? '');
    
    if (empty($roll_reference)) {
        throw new Exception("Roll reference is required");
    }
    
    if (empty($destination)) {
        throw new Exception("Please select a destination for the roll");
    }
    
    if (!in_array($destination, ['fg_production', 'bag_production'])) {
        throw new Exception("Invalid destination selected");
    }
    
    // Update all approved tests for this roll reference with the destination
    $stmt = $conn->prepare("UPDATE qc_test_orders 
                           SET roll_destination = ?, 
                               admin_remarks = CASE 
                                   WHEN admin_remarks IS NULL OR admin_remarks = '' THEN ?
                                   ELSE CONCAT(admin_remarks, '\n\nRouting: ', ?)
                               END,
                               updated_at = NOW() 
                           WHERE sample_reference_id = ? 
                           AND status = 'approved'");
    
    $routing_note = !empty($comment) ? "Routed to " . ($destination === 'fg_production' ? 'FG' : 'Bag') . " Production. " . $comment : "Routed to " . ($destination === 'fg_production' ? 'FG' : 'Bag') . " Production.";
    
    $stmt->bind_param("ssss", $destination, $routing_note, $routing_note, $roll_reference);
    
    if ($stmt->execute()) {
        $affected_rows = $stmt->affected_rows;
        $stmt->close();
        
        $dest_text = ($destination === 'fg_production') ? 'FG Production' : 'Bag Production';
        $message = "✅ Roll $roll_reference routed to $dest_text successfully! ($affected_rows test(s) updated)";
        header("Location: ../admin/qc_test_approval_dashboard.php?success=" . urlencode($message));
        exit();
    } else {
        throw new Exception("Failed to route roll: " . $stmt->error);
    }
    
} catch (Exception $e) {
    error_log("Roll Routing Error: " . $e->getMessage());
    header("Location: ../admin/qc_test_approval_dashboard.php?error=" . urlencode($e->getMessage()));
    exit();
}

$conn->close();
?>


