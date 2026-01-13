<?php
/**
 * Fix Missing Stock Script
 * 
 * This script calculates and restores stock that was deducted by deleted store_issue_entries
 * 
 * It compares:
 * - Original amount vs Remaining amount in store_received_entries
 * - Used amount from fiber_entries
 * - The difference is what was deducted by store_issue_entries
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
$fixes = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_stock'])) {
    try {
        $entry_number = trim($_POST['entry_number'] ?? '');
        
        if (empty($entry_number)) {
            throw new Exception("Entry number is required.");
        }
        
        // Get store entry details
        $getStmt = $conn->prepare("SELECT entry_number, original_amount_kg, amount_kg, manufacturer_name, material_type 
                                  FROM store_received_entries 
                                  WHERE entry_number = ?");
        $getStmt->bind_param("s", $entry_number);
        $getStmt->execute();
        $result = $getStmt->get_result();
        
        if ($result->num_rows === 0) {
            throw new Exception("Store entry not found: $entry_number");
        }
        
        $storeEntry = $result->fetch_assoc();
        $getStmt->close();
        
        $original_amount = floatval($storeEntry['original_amount_kg'] ?? $storeEntry['amount_kg']);
        $remaining_amount = floatval($storeEntry['amount_kg']);
        
        // Calculate used from fiber_entries
        $usedFromFiber = 0;
        $fiberQuery = $conn->prepare("SELECT COALESCE(SUM(amount_kg), 0) as used_amount 
                                     FROM fiber_entries 
                                     WHERE reference = ? 
                                     AND (is_deleted = 0 OR is_deleted IS NULL)");
        $fiberQuery->bind_param("s", $entry_number);
        $fiberQuery->execute();
        $fiberResult = $fiberQuery->get_result();
        if ($fiberRow = $fiberResult->fetch_assoc()) {
            $usedFromFiber = floatval($fiberRow['used_amount']);
        }
        $fiberQuery->close();
        
        // Calculate what should be remaining
        $expected_remaining = $original_amount - $usedFromFiber;
        $missing_amount = $expected_remaining - $remaining_amount;
        
        if ($missing_amount > 0) {
            // Restore the missing amount
            $conn->begin_transaction();
            
            $restoreStmt = $conn->prepare("UPDATE store_received_entries 
                                          SET amount_kg = amount_kg + ? 
                                          WHERE entry_number = ?");
            $restoreStmt->bind_param("ds", $missing_amount, $entry_number);
            
            if (!$restoreStmt->execute()) {
                throw new Exception("Failed to restore stock: " . $restoreStmt->error);
            }
            
            $restoreStmt->close();
            $conn->commit();
            
            $message = "Stock restored successfully! Restored $missing_amount kg to entry $entry_number";
            $fixes[] = [
                'entry_number' => $entry_number,
                'original' => $original_amount,
                'used_from_fiber' => $usedFromFiber,
                'expected_remaining' => $expected_remaining,
                'actual_remaining' => $remaining_amount,
                'missing' => $missing_amount,
                'restored' => $missing_amount
            ];
        } else {
            $message = "No missing stock found for entry $entry_number. Stock appears correct.";
        }
        
    } catch (Exception $e) {
        if ($conn->in_transaction) {
            $conn->rollback();
        }
        $error = $e->getMessage();
    }
}

// Get all store entries with discrepancies
$discrepancies = [];
$query = "
    SELECT 
        sre.entry_number,
        sre.manufacturer_name,
        sre.material_type,
        COALESCE(sre.original_amount_kg, sre.amount_kg) as original_amount,
        sre.amount_kg as remaining_amount,
        COALESCE((
            SELECT COALESCE(SUM(amount_kg), 0)
            FROM fiber_entries fe
            WHERE fe.reference = sre.entry_number
            AND (fe.is_deleted = 0 OR fe.is_deleted IS NULL)
        ), 0) as used_from_fiber
    FROM store_received_entries sre
    ORDER BY sre.date_time DESC
    LIMIT 50
";

$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $original = floatval($row['original_amount']);
        $remaining = floatval($row['remaining_amount']);
        $usedFromFiber = floatval($row['used_from_fiber']);
        $expected = $original - $usedFromFiber;
        $missing = $expected - $remaining;
        
        if ($missing > 0.01) { // More than 0.01 kg missing
            $discrepancies[] = [
                'entry_number' => $row['entry_number'],
                'manufacturer' => $row['manufacturer_name'],
                'material_type' => $row['material_type'],
                'original' => $original,
                'used_from_fiber' => $usedFromFiber,
                'expected_remaining' => $expected,
                'actual_remaining' => $remaining,
                'missing' => $missing
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Fix Missing Stock</title>
    <style>
        body { font-family: Arial; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input, select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #27ae60; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .success { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 8px; border: 1px solid #ddd; text-align: left; }
        th { background: #f8f9fa; }
        .missing { color: #e74c3c; font-weight: bold; }
        .fix-btn { background: #3498db; padding: 5px 10px; border: none; border-radius: 4px; color: white; cursor: pointer; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Fix Missing Stock</h1>
        <p>This tool identifies and fixes stock discrepancies caused by deleted material issue entries.</p>
        
        <?php if ($message): ?>
            <div class="success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="hidden" name="fix_stock" value="1">
            <div class="form-group">
                <label>Store Entry Number:</label>
                <input type="text" name="entry_number" placeholder="e.g., SRE-20260113-001" required>
            </div>
            <button type="submit">Fix Stock for This Entry</button>
        </form>
        
        <h2>Entries with Missing Stock</h2>
        <?php if (count($discrepancies) > 0): ?>
            <table>
                <tr>
                    <th>Entry Number</th>
                    <th>Manufacturer</th>
                    <th>Material Type</th>
                    <th>Original (kg)</th>
                    <th>Used from Fiber (kg)</th>
                    <th>Expected Remaining (kg)</th>
                    <th>Actual Remaining (kg)</th>
                    <th>Missing (kg)</th>
                    <th>Action</th>
                </tr>
                <?php foreach ($discrepancies as $disc): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($disc['entry_number']); ?></strong></td>
                        <td><?php echo htmlspecialchars($disc['manufacturer']); ?></td>
                        <td><?php echo htmlspecialchars($disc['material_type']); ?></td>
                        <td><?php echo number_format($disc['original'], 2); ?></td>
                        <td><?php echo number_format($disc['used_from_fiber'], 2); ?></td>
                        <td><?php echo number_format($disc['expected_remaining'], 2); ?></td>
                        <td><?php echo number_format($disc['actual_remaining'], 2); ?></td>
                        <td class="missing"><?php echo number_format($disc['missing'], 2); ?></td>
                        <td>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="fix_stock" value="1">
                                <input type="hidden" name="entry_number" value="<?php echo htmlspecialchars($disc['entry_number']); ?>">
                                <button type="submit" class="fix-btn">Restore</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p style="color: green;">✓ No missing stock found. All entries are balanced.</p>
        <?php endif; ?>
    </div>
</body>
</html>
