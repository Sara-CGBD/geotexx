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
    $is_bulk = isset($_POST['is_bulk']) && $_POST['is_bulk'] == '1';
    
    if (empty($roll_reference)) {
        throw new Exception("Roll reference is required");
    }
    
    if (empty($destination)) {
        throw new Exception("Please select a destination for the roll");
    }
    
    if (!in_array($destination, ['fg_production', 'bag_production'])) {
        throw new Exception("Invalid destination selected");
    }
    
    // Handle bulk routing (comma-separated references) or single reference
    $roll_references = [];
    if ($is_bulk && strpos($roll_reference, ',') !== false) {
        // Bulk routing - multiple references
        $roll_references = array_map('trim', explode(',', $roll_reference));
        $roll_references = array_filter($roll_references); // Remove empty values
    } else {
        // Single reference
        $roll_references = [$roll_reference];
    }
    
    if (empty($roll_references)) {
        throw new Exception("No valid roll references provided");
    }
    
    $routing_note = !empty($comment) ? "Routed to " . ($destination === 'fg_production' ? 'FG' : 'Bag') . " Production. " . $comment : "Routed to " . ($destination === 'fg_production' ? 'FG' : 'Bag') . " Production.";
    $dest_text = ($destination === 'fg_production') ? 'FG Production' : 'Bag Production';
    
    $total_affected = 0;
    $routed_refs = [];
    
    // Route each reference
    foreach ($roll_references as $ref) {
        $ref = trim($ref);
        if (empty($ref)) {
            continue;
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
    
        $stmt->bind_param("ssss", $destination, $routing_note, $routing_note, $ref);
    
    if ($stmt->execute()) {
        $affected_rows = $stmt->affected_rows;
            $total_affected += $affected_rows;
            if ($affected_rows > 0) {
                $routed_refs[] = $ref;
            }
        } else {
            error_log("Failed to route roll $ref: " . $stmt->error);
        }
        $stmt->close();
    }
    
    if ($total_affected > 0) {
        $ref_count = count($routed_refs);
        if ($ref_count > 1) {
            $ref_display = count($routed_refs) <= 3 
                ? implode(', ', $routed_refs) 
                : $routed_refs[0] . ' to ' . end($routed_refs) . ' (' . $ref_count . ' references)';
            $message = "✅ Bulk routing: $ref_count roll(s) routed to $dest_text successfully! ($total_affected test(s) updated)";
        } else {
            $message = "✅ Roll " . $routed_refs[0] . " routed to $dest_text successfully! ($total_affected test(s) updated)";
        }
        header("Location: ../admin/qc_test_approval_dashboard.php?success=" . urlencode($message));
        exit();
    } else {
        throw new Exception("No tests were updated. Please verify the roll references are approved and not already routed.");
    }
    
} catch (Exception $e) {
    error_log("Roll Routing Error: " . $e->getMessage());
    header("Location: ../admin/qc_test_approval_dashboard.php?error=" . urlencode($e->getMessage()));
    exit();
}

$conn->close();
?>


