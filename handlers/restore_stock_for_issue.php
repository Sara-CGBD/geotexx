<?php
/**
 * Utility script to restore stock for a deleted store issue entry
 * 
 * This script helps restore stock when an issue entry was deleted directly from the database
 * without going through the proper deletion handler.
 * 
 * Usage: POST with issue_number, or GET with issue_number to see what would be restored
 */

session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    die(json_encode(['success' => false, 'message' => 'Not authenticated']));
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

header('Content-Type: application/json');

$issue_number = $_POST['issue_number'] ?? $_GET['issue_number'] ?? '';

if (empty($issue_number)) {
    echo json_encode(['success' => false, 'message' => 'Issue number is required']);
    exit;
}

// Check if issue entry still exists
$checkStmt = $conn->prepare("SELECT issue_number, amount_kg, store_entry_reference, manufacturer_name, material_type 
                             FROM store_issue_entries 
                             WHERE issue_number = ?");
$checkStmt->bind_param("s", $issue_number);
$checkStmt->execute();
$result = $checkStmt->get_result();
$issueEntry = $result->fetch_assoc();
$checkStmt->close();

if (!$issueEntry) {
    // Entry doesn't exist - might have been deleted
    // Try to restore based on manufacturer and material type
    // We'll need to manually specify the amount and reference
    echo json_encode([
        'success' => false, 
        'message' => 'Issue entry not found. Please provide: amount_kg, store_entry_reference, manufacturer_name, material_type',
        'note' => 'If you know the details, you can manually restore using the restore endpoint with all parameters'
    ]);
    exit;
}

$amount_kg = floatval($issueEntry['amount_kg']);
$store_entry_reference = $issueEntry['store_entry_reference'];
$manufacturer_name = $issueEntry['manufacturer_name'];
$material_type = $issueEntry['material_type'];

// If this is a GET request, just show what would be restored
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'success' => true,
        'issue_entry' => $issueEntry,
        'restore_info' => [
            'amount_to_restore' => $amount_kg,
            'store_entry_reference' => $store_entry_reference,
            'note' => 'This will restore ' . $amount_kg . ' kg to entry: ' . $store_entry_reference . '. However, if the issue deducted from multiple entries, only the first one will be restored.'
        ],
        'warning' => 'If the issue deducted from multiple store entries, this will only restore to the first reference. You may need to manually restore to other entries.'
    ]);
    exit;
}

// POST request - actually restore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_restore'])) {
    try {
        $conn->begin_transaction();
        
        // Restore stock to store_received_entries
        if (!empty($store_entry_reference) && $amount_kg > 0) {
            $restoreStmt = $conn->prepare("UPDATE store_received_entries 
                                          SET amount_kg = amount_kg + ? 
                                          WHERE entry_number = ?");
            $restoreStmt->bind_param("ds", $amount_kg, $store_entry_reference);
            
            if (!$restoreStmt->execute()) {
                throw new Exception("Failed to restore stock: " . $restoreStmt->error);
            }
            
            $affected = $restoreStmt->affected_rows;
            $restoreStmt->close();
            
            if ($affected === 0) {
                throw new Exception("Store entry reference not found: " . $store_entry_reference);
            }
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Stock restored successfully',
            'restored' => [
                'amount' => $amount_kg,
                'to_entry' => $store_entry_reference
            ]
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Please confirm restoration by setting confirm_restore=1'
    ]);
}

$conn->close();
?>
