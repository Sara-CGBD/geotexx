<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit;
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_entry']) && isset($_POST['issue_number'])) {
    try {
        $issue_number = trim($_POST['issue_number']);
        
        if (empty($issue_number)) {
            throw new Exception("Issue number is required.");
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Get the issue entry details
            $getStmt = $conn->prepare("SELECT issue_number, amount_kg, store_entry_reference, manufacturer_name, material_type 
                                      FROM store_issue_entries 
                                      WHERE issue_number = ?");
            $getStmt->bind_param("s", $issue_number);
            $getStmt->execute();
            $result = $getStmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("Issue entry not found.");
            }
            
            $issueEntry = $result->fetch_assoc();
            $getStmt->close();
            
            $amount_kg = floatval($issueEntry['amount_kg']);
            $store_entry_reference = $issueEntry['store_entry_reference'];
            $deduction_details_json = $issueEntry['deduction_details'] ?? null;
            
            // Restore stock to store_received_entries
            // If deduction_details exists (new format), restore to all entries
            // Otherwise, restore to first reference only (old format)
            if (!empty($deduction_details_json)) {
                $deduction_details = json_decode($deduction_details_json, true);
                if (is_array($deduction_details)) {
                    // Restore to all entries that were deducted from
                    foreach ($deduction_details as $entry_number => $deducted_amount) {
                        $restoreStmt = $conn->prepare("UPDATE store_received_entries 
                                                      SET amount_kg = amount_kg + ? 
                                                      WHERE entry_number = ?");
                        $restoreStmt->bind_param("ds", $deducted_amount, $entry_number);
                        
                        if (!$restoreStmt->execute()) {
                            throw new Exception("Failed to restore stock to entry $entry_number: " . $restoreStmt->error);
                        }
                        $restoreStmt->close();
                    }
                }
            } elseif (!empty($store_entry_reference) && $amount_kg > 0) {
                // Fallback: restore to first reference only (old format)
                $restoreStmt = $conn->prepare("UPDATE store_received_entries 
                                              SET amount_kg = amount_kg + ? 
                                              WHERE entry_number = ?");
                $restoreStmt->bind_param("ds", $amount_kg, $store_entry_reference);
                
                if (!$restoreStmt->execute()) {
                    throw new Exception("Failed to restore stock: " . $restoreStmt->error);
                }
                $restoreStmt->close();
            }
            
            // Delete the issue entry
            $deleteStmt = $conn->prepare("DELETE FROM store_issue_entries WHERE issue_number = ?");
            $deleteStmt->bind_param("s", $issue_number);
            
            if (!$deleteStmt->execute()) {
                throw new Exception("Failed to delete issue entry: " . $deleteStmt->error);
            }
            $deleteStmt->close();
            
            $conn->commit();
            
            // Redirect back with success message
            $redirectUrl = $_POST['redirect_url'] ?? '../forms/material_issue_entry.php';
            header("Location: " . $redirectUrl . "?success=" . urlencode("Material issue entry deleted successfully. Stock has been restored."));
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        $redirectUrl = $_POST['redirect_url'] ?? '../forms/material_issue_entry.php';
        header("Location: " . $redirectUrl . "?error=" . urlencode($error));
        exit;
    }
} else {
    header("Location: ../forms/material_issue_entry.php");
    exit;
}
?>
