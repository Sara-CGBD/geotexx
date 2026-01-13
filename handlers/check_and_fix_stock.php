<?php
/**
 * Quick Check and Fix Stock Script
 * Directly checks the database and fixes the stock for SRE-20260113-001
 */

session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    die("Unauthorized. Please login first.");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$entry_number = 'SRE-20260113-001';
$message = '';
$error = '';

// Get current database values
$getStmt = $conn->prepare("SELECT entry_number, original_amount_kg, amount_kg, manufacturer_name, material_type 
                          FROM store_received_entries 
                          WHERE entry_number = ?");
$getStmt->bind_param("s", $entry_number);
$getStmt->execute();
$result = $getStmt->get_result();

if ($result->num_rows === 0) {
    die("Entry not found: $entry_number");
}

$storeEntry = $result->fetch_assoc();
$getStmt->close();

$original_amount = floatval($storeEntry['original_amount_kg'] ?? $storeEntry['amount_kg']);
$current_amount = floatval($storeEntry['amount_kg']);
$manufacturer = $storeEntry['manufacturer_name'];
$material_type = $storeEntry['material_type'];

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

// Calculate expected remaining
$expected_remaining = $original_amount - $usedFromFiber;
$missing_amount = $expected_remaining - $current_amount;

// Check if fix is needed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_now'])) {
    if ($missing_amount > 0.01) {
        try {
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
            
            // Refresh values
            $getStmt2 = $conn->prepare("SELECT amount_kg FROM store_received_entries WHERE entry_number = ?");
            $getStmt2->bind_param("s", $entry_number);
            $getStmt2->execute();
            $result2 = $getStmt2->get_result();
            $newEntry = $result2->fetch_assoc();
            $current_amount = floatval($newEntry['amount_kg']);
            $getStmt2->close();
            
            $message = "Stock restored successfully! Restored " . number_format($missing_amount, 2) . " kg. New remaining: " . number_format($current_amount, 2) . " kg";
        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    } else {
        $message = "No fix needed. Stock is already correct.";
    }
}

// Check available amount from API query
$apiQuery = $conn->prepare("SELECT sre.entry_number, sre.amount_kg
                          FROM store_received_entries sre
                          LEFT JOIN fineness_fiber_reports ff ON sre.entry_number COLLATE utf8mb4_unicode_ci = ff.store_entry_reference
                          LEFT JOIN cut_length_fiber_reports cl ON sre.entry_number COLLATE utf8mb4_unicode_ci = cl.store_entry_reference
                          LEFT JOIN tenacity_fiber_reports tf ON sre.entry_number COLLATE utf8mb4_unicode_ci = tf.store_entry_reference
                          LEFT JOIN tenacity_yarn_reports ty ON sre.entry_number COLLATE utf8mb4_unicode_ci = ty.store_entry_reference
                          LEFT JOIN fiber_test_reports ft ON sre.entry_number COLLATE utf8mb4_unicode_ci = ft.store_entry_reference
                          LEFT JOIN sewing_thread_reports st ON sre.entry_number COLLATE utf8mb4_unicode_ci = st.store_entry_reference
                          WHERE sre.manufacturer_name = ? 
                          AND sre.material_type = ? 
                          AND sre.amount_kg > 0
                          AND (
                              (sre.material_type LIKE '%Fiber%' OR sre.material_type LIKE '%PP%')
                              AND ff.status = 'approved' 
                              AND cl.status = 'approved' 
                              AND tf.status = 'approved' 
                              AND ty.status = 'approved' 
                              AND ft.status = 'approved' 
                              AND st.status = 'approved'
                              OR
                              (sre.material_type LIKE '%Thread%' OR sre.material_type LIKE '%Sewing%')
                              AND st.status = 'approved'
                          )
                          GROUP BY sre.entry_number, sre.amount_kg");
$apiQuery->bind_param("ss", $manufacturer, $material_type);
$apiQuery->execute();
$apiResult = $apiQuery->get_result();

$api_total = 0;
while ($row = $apiResult->fetch_assoc()) {
    $api_total += floatval($row['amount_kg']);
}
$apiQuery->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Check and Fix Stock</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:0; color:#2c3e50; }
        .container { max-width:100%; margin:0; background:#fff; border-radius:0; padding:25px 80px; box-shadow:none;}
        h1 { text-align:center; font-size:28px; margin-bottom:30px; color:#2c3e50; }
        .info-box { background: #e8f4f8; padding: 15px; border-radius: 8px; margin: 15px 0; border: 1px solid #b3d9ff; }
        .info-box h3 { margin: 0 0 15px 0; color: #0066cc; font-size: 18px; }
        .value { font-weight: 600; color: #2c3e50; }
        .missing { color: #e74c3c; font-weight: 600; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #f5c6cb; }
        button { background: #2ecc71; color: white; padding: 12px 24px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: 600; transition: all 0.2s; }
        button:hover { background: #27ae60; transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; border: 1px solid #ddd; text-align: left; }
        th { background: #f8f9fa; font-weight: 600; color: #2c3e50; }
        tr:nth-child(even) { background: #f8f9fa; }
        .note { margin-top: 20px; color: #7f8c8d; font-size: 14px; padding: 15px; background: #f0f0f0; border-radius: 6px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Stock Check and Fix for <?php echo htmlspecialchars($entry_number); ?></h1>
        
        <?php if ($message): ?>
            <div class="success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="info-box">
            <h3>Current Database Values:</h3>
            <table>
                <tr>
                    <th>Field</th>
                    <th>Value</th>
                </tr>
                <tr>
                    <td>Entry Number</td>
                    <td class="value"><?php echo htmlspecialchars($entry_number); ?></td>
                </tr>
                <tr>
                    <td>Manufacturer</td>
                    <td><?php echo htmlspecialchars($manufacturer); ?></td>
                </tr>
                <tr>
                    <td>Material Type</td>
                    <td><?php echo htmlspecialchars($material_type); ?></td>
                </tr>
                <tr>
                    <td>Original Amount (kg)</td>
                    <td class="value"><?php echo number_format($original_amount, 2); ?></td>
                </tr>
                <tr>
                    <td>Used from Fiber Entries (kg)</td>
                    <td><?php echo number_format($usedFromFiber, 2); ?></td>
                </tr>
                <tr>
                    <td>Expected Remaining (kg)</td>
                    <td class="value"><?php echo number_format($expected_remaining, 2); ?></td>
                </tr>
                <tr>
                    <td>Current Remaining in DB (kg)</td>
                    <td class="value"><?php echo number_format($current_amount, 2); ?></td>
                </tr>
                <tr>
                    <td>Missing Amount (kg)</td>
                    <td class="missing"><?php echo number_format($missing_amount, 2); ?></td>
                </tr>
            </table>
        </div>
        
        <div class="info-box">
            <h3>API Calculation Result:</h3>
            <p>Available Amount (from get_approved_available_material.php): <span class="value"><?php echo number_format($api_total, 2); ?> kg</span></p>
        </div>
        
        <?php if ($missing_amount > 0.01): ?>
            <form method="POST">
                <input type="hidden" name="fix_now" value="1">
                <button type="submit">Fix Stock Now - Restore <?php echo number_format($missing_amount, 2); ?> kg</button>
            </form>
        <?php else: ?>
            <p style="color: green; font-weight: bold;">✓ Stock is correct. No fix needed.</p>
        <?php endif; ?>
        
        <div class="note">
            <strong>Note:</strong> After fixing, refresh the Material Issue Entry form to see the updated available amount.
        </div>
    </div>
</body>
</html>
