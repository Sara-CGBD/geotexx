<?php
/**
 * Manual Stock Restoration Script
 * 
 * Use this to restore stock when an issue entry was deleted directly from the database
 * without going through the proper deletion handler.
 * 
 * This script allows you to manually restore stock by specifying:
 * - The issue number (if entry still exists)
 * - OR manually specify: amount, store_entry_reference, manufacturer, material_type
 */

session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    die("Unauthorized. Please login first.");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_stock'])) {
    try {
        $issue_number = trim($_POST['issue_number'] ?? '');
        $manual_amount = floatval($_POST['manual_amount'] ?? 0);
        $manual_reference = trim($_POST['manual_reference'] ?? '');
        $manufacturer_name = trim($_POST['manufacturer_name'] ?? '');
        $material_type = trim($_POST['material_type'] ?? '');
        
        $conn->begin_transaction();
        
        if (!empty($issue_number)) {
            // Try to get from existing entry
            $getStmt = $conn->prepare("SELECT issue_number, amount_kg, store_entry_reference, deduction_details, manufacturer_name, material_type 
                                      FROM store_issue_entries 
                                      WHERE issue_number = ?");
            $getStmt->bind_param("s", $issue_number);
            $getStmt->execute();
            $result = $getStmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("Issue entry not found: $issue_number");
            }
            
            $issueEntry = $result->fetch_assoc();
            $getStmt->close();
            
            $amount_kg = floatval($issueEntry['amount_kg']);
            $store_entry_reference = $issueEntry['store_entry_reference'];
            $deduction_details_json = $issueEntry['deduction_details'] ?? null;
            $manufacturer_name = $issueEntry['manufacturer_name'];
            $material_type = $issueEntry['material_type'];
            
            // Restore using deduction_details if available
            if (!empty($deduction_details_json)) {
                $deduction_details = json_decode($deduction_details_json, true);
                if (is_array($deduction_details)) {
                    foreach ($deduction_details as $entry_number => $deducted_amount) {
                        $restoreStmt = $conn->prepare("UPDATE store_received_entries 
                                                      SET amount_kg = amount_kg + ? 
                                                      WHERE entry_number = ?");
                        $restoreStmt->bind_param("ds", $deducted_amount, $entry_number);
                        $restoreStmt->execute();
                        $restoreStmt->close();
                    }
                    $message = "Stock restored successfully from deduction_details for issue: $issue_number";
                }
            } elseif (!empty($store_entry_reference) && $amount_kg > 0) {
                // Fallback: restore to first reference
                $restoreStmt = $conn->prepare("UPDATE store_received_entries 
                                              SET amount_kg = amount_kg + ? 
                                              WHERE entry_number = ?");
                $restoreStmt->bind_param("ds", $amount_kg, $store_entry_reference);
                $restoreStmt->execute();
                $restoreStmt->close();
                $message = "Stock restored to first reference for issue: $issue_number (Note: If multiple entries were deducted, only the first was restored)";
            }
        } elseif ($manual_amount > 0 && !empty($manual_reference)) {
            // Manual restoration
            $restoreStmt = $conn->prepare("UPDATE store_received_entries 
                                          SET amount_kg = amount_kg + ? 
                                          WHERE entry_number = ?");
            $restoreStmt->bind_param("ds", $manual_amount, $manual_reference);
            
            if (!$restoreStmt->execute()) {
                throw new Exception("Failed to restore stock: " . $restoreStmt->error);
            }
            
            $affected = $restoreStmt->affected_rows;
            $restoreStmt->close();
            
            if ($affected === 0) {
                throw new Exception("Store entry reference not found: $manual_reference");
            }
            
            $message = "Stock restored manually: $manual_amount kg to entry: $manual_reference";
        } else {
            throw new Exception("Please provide either issue_number OR manual_amount and manual_reference");
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = $e->getMessage();
    }
}

// Get recent issue entries for reference
$recentIssues = [];
$issuesQuery = $conn->query("SELECT issue_number, amount_kg, store_entry_reference, manufacturer_name, material_type, date_time 
                            FROM store_issue_entries 
                            ORDER BY date_time DESC 
                            LIMIT 20");
if ($issuesQuery) {
    while ($row = $issuesQuery->fetch_assoc()) {
        $recentIssues[] = $row;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Manual Stock Restoration</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #27ae60; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 8px; border: 1px solid #ddd; text-align: left; }
        th { background: #f8f9fa; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Manual Stock Restoration</h1>
        
        <?php if ($message): ?>
            <div class="success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="restore_stock" value="1">
            
            <h2>Option 1: Restore from Issue Number</h2>
            <div class="form-group">
                <label>Issue Number:</label>
                <input type="text" name="issue_number" placeholder="e.g., MIE-20260113-001">
            </div>
            
            <hr>
            
            <h2>Option 2: Manual Restoration</h2>
            <div class="form-group">
                <label>Amount (kg):</label>
                <input type="number" step="0.01" name="manual_amount" placeholder="e.g., 100.00">
            </div>
            <div class="form-group">
                <label>Store Entry Reference:</label>
                <input type="text" name="manual_reference" placeholder="e.g., SRE-20260113-001">
            </div>
            <div class="form-group">
                <label>Manufacturer Name:</label>
                <input type="text" name="manufacturer_name" placeholder="e.g., PSF">
            </div>
            <div class="form-group">
                <label>Material Type:</label>
                <input type="text" name="material_type" placeholder="e.g., PP Stable Fiber">
            </div>
            
            <button type="submit">Restore Stock</button>
        </form>
        
        <h2>Recent Issue Entries (for reference)</h2>
        <table>
            <tr>
                <th>Issue Number</th>
                <th>Amount (kg)</th>
                <th>Store Reference</th>
                <th>Manufacturer</th>
                <th>Material Type</th>
                <th>Date</th>
            </tr>
            <?php foreach ($recentIssues as $issue): ?>
                <tr>
                    <td><?php echo htmlspecialchars($issue['issue_number']); ?></td>
                    <td><?php echo number_format($issue['amount_kg'], 2); ?></td>
                    <td><?php echo htmlspecialchars($issue['store_entry_reference'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($issue['manufacturer_name']); ?></td>
                    <td><?php echo htmlspecialchars($issue['material_type']); ?></td>
                    <td><?php echo htmlspecialchars($issue['date_time']); ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</body>
</html>
