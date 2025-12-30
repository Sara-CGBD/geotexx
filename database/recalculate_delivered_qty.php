<?php
// Recalculate delivered_quantity for all fg_entry records based on actual deliveries
session_start();
require_once '../config/security_config.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Recalculate Delivered Quantities</h2>";
echo "<pre>";

$conn = SecurityConfig::getConnection();

echo "🔄 Recalculating delivered quantities...\n\n";

// Get all FG entries
$entries = $conn->query("SELECT id, reference_number, passed_qty, delivered_quantity FROM fg_entry WHERE reference_number IS NOT NULL");

$updated = 0;
$unchanged = 0;

while ($entry = $entries->fetch_assoc()) {
    $fgEntryId = $entry['id'];
    $reference = $entry['reference_number'];
    $oldDeliveredQty = $entry['delivered_quantity'];
    
    // Calculate actual delivered quantity from fg_deliveries table
    $sumQuery = $conn->prepare("SELECT COALESCE(SUM(delivery_quantity), 0) as total_delivered 
                                 FROM fg_deliveries 
                                 WHERE fg_entry_id = ?");
    $sumQuery->bind_param('i', $fgEntryId);
    $sumQuery->execute();
    $result = $sumQuery->get_result();
    $row = $result->fetch_assoc();
    $actualDeliveredQty = $row['total_delivered'];
    $sumQuery->close();
    
    // Update if different
    if ($oldDeliveredQty != $actualDeliveredQty) {
        $updateStmt = $conn->prepare("UPDATE fg_entry SET delivered_quantity = ? WHERE id = ?");
        $updateStmt->bind_param('di', $actualDeliveredQty, $fgEntryId);
        $updateStmt->execute();
        $updateStmt->close();
        
        echo "✅ Updated: {$reference}\n";
        echo "   Old: {$oldDeliveredQty} → New: {$actualDeliveredQty}\n";
        echo "   Remaining: " . ($entry['passed_qty'] - $actualDeliveredQty) . "\n\n";
        $updated++;
    } else {
        $unchanged++;
    }
}

echo str_repeat("-", 60) . "\n";
echo "✅ Recalculation Complete!\n";
echo "   Updated: {$updated} entries\n";
echo "   Unchanged: {$unchanged} entries\n";
echo str_repeat("-", 60) . "\n";

echo "\n</pre>";
echo "<p><strong>✅ All delivered quantities have been recalculated!</strong></p>";
echo "<p><a href='../forms/fg_delivery_entry.php' style='padding:10px 20px; background:#27ae60; color:white; text-decoration:none; border-radius:5px;'>Go to FG Delivery Form</a></p>";

$conn->close();
?>


