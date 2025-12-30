<?php
// Check specific FG entry for delivery availability
session_start();
require_once '../config/security_config.php';

echo "<h2>Diagnostic: Check Specific FG Entry</h2>";
echo "<pre>";

$conn = SecurityConfig::getConnection();

// The reference you're trying to deliver
$targetRef = '3.5L225NOV13-R01-GT0.9.H0.1';

echo "🔍 Searching for reference: <strong>$targetRef</strong>\n\n";

// Check if entry exists
$checkQuery = "SELECT 
    id,
    fg_id,
    reference_number,
    product_type,
    roll_entry_type,
    passed_qty,
    actual_weight,
    COALESCE(delivered_quantity, 0) as delivered_quantity,
    CASE 
        WHEN product_type = 'roll' THEN (actual_weight - COALESCE(delivered_quantity, 0))
        ELSE (passed_qty - COALESCE(delivered_quantity, 0))
    END as remaining_quantity,
    cnc_cutting_batch,
    bag_size,
    created_at
FROM fg_entry 
WHERE reference_number = ?";

$stmt = $conn->prepare($checkQuery);
$stmt->bind_param('s', $targetRef);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "❌ <strong>NO ENTRY FOUND with this reference!</strong>\n\n";
    echo "This means:\n";
    echo "  • The entry doesn't exist in fg_entry table, OR\n";
    echo "  • The reference_number doesn't match exactly\n\n";
    
    // Show similar entries
    echo "🔍 Looking for similar entries...\n";
    $similarQuery = "SELECT reference_number FROM fg_entry WHERE reference_number LIKE ? LIMIT 5";
    $similarStmt = $conn->prepare($similarQuery);
    $similarPattern = '%3.5L225NOV13%';
    $similarStmt->bind_param('s', $similarPattern);
    $similarStmt->execute();
    $similarResult = $similarStmt->get_result();
    
    if ($similarResult->num_rows > 0) {
        echo "\n📋 Similar references found:\n";
        while ($row = $similarResult->fetch_assoc()) {
            echo "  • {$row['reference_number']}\n";
        }
    } else {
        echo "  • No similar entries found\n";
    }
    
} else {
    echo "✅ <strong>ENTRY FOUND!</strong>\n\n";
    
    $entry = $result->fetch_assoc();
    
    echo "📊 Entry Details:\n";
    echo str_repeat("-", 60) . "\n";
    echo "  ID:                 {$entry['id']}\n";
    echo "  FG ID:              {$entry['fg_id']}\n";
    echo "  Reference:          {$entry['reference_number']}\n";
    echo "  Product Type:       " . ($entry['product_type'] ?: 'NULL') . "\n";
    echo "  Roll Entry Type:    " . ($entry['roll_entry_type'] ?: 'NULL') . "\n";
    echo "  CNC Batch:          {$entry['cnc_cutting_batch']}\n";
    echo "  Bag Size:           {$entry['bag_size']}\n";
    echo "  Passed Qty:         {$entry['passed_qty']}\n";
    echo "  Actual Weight:      {$entry['actual_weight']}\n";
    echo "  Delivered Qty:      {$entry['delivered_quantity']}\n";
    echo "  <strong>REMAINING QTY:      {$entry['remaining_quantity']}</strong>\n";
    echo "  Created At:         {$entry['created_at']}\n";
    echo str_repeat("-", 60) . "\n\n";
    
    // Now check if it would pass the frontend filter
    echo "🧪 Frontend Query Filter Tests:\n";
    echo str_repeat("-", 60) . "\n";
    
    $passTests = true;
    
    // Test 1: reference_number IS NOT NULL
    if ($entry['reference_number']) {
        echo "  ✅ Test 1: reference_number IS NOT NULL\n";
    } else {
        echo "  ❌ Test 1: reference_number IS NULL\n";
        $passTests = false;
    }
    
    // Test 2: Product type and quantity check
    if ($entry['product_type'] === 'roll' && $entry['actual_weight'] > 0) {
        echo "  ✅ Test 2: product_type='roll' AND actual_weight > 0\n";
    } else if ($entry['product_type'] === 'bag' && $entry['passed_qty'] > 0) {
        echo "  ✅ Test 2: product_type='bag' AND passed_qty > 0\n";
    } else if (!$entry['product_type'] && $entry['passed_qty'] > 0) {
        echo "  ✅ Test 2: product_type IS NULL AND passed_qty > 0\n";
    } else {
        echo "  ❌ Test 2 FAILED:\n";
        echo "     product_type = " . ($entry['product_type'] ?: 'NULL') . "\n";
        echo "     passed_qty = {$entry['passed_qty']}\n";
        echo "     actual_weight = {$entry['actual_weight']}\n";
        $passTests = false;
    }
    
    // Test 3: Remaining quantity > 0
    if ($entry['remaining_quantity'] > 0) {
        echo "  ✅ Test 3: remaining_quantity > 0 (value: {$entry['remaining_quantity']})\n";
    } else {
        echo "  ❌ Test 3: remaining_quantity <= 0 (value: {$entry['remaining_quantity']})\n";
        $passTests = false;
    }
    
    echo str_repeat("-", 60) . "\n\n";
    
    if ($passTests) {
        echo "✅ <strong>RESULT: This entry SHOULD appear in the dropdown!</strong>\n\n";
        echo "If it's not showing up, the issue is likely:\n";
        echo "  1. Browser cache - Press Ctrl+Shift+R to hard refresh\n";
        echo "  2. JavaScript error - Check browser console (F12)\n";
        echo "  3. Product type selection - Make sure you select 'Bag' first\n";
    } else {
        echo "❌ <strong>RESULT: This entry will NOT appear in the dropdown</strong>\n\n";
        echo "The entry doesn't meet the filter criteria.\n";
        echo "You need to fix the entry data in the FG Entry form.\n";
    }
}

echo "\n📋 Query Used by Frontend:\n";
echo str_repeat("-", 60) . "\n";
echo "SELECT fe.id, fe.reference_number, fe.product_type, fe.passed_qty,
       COALESCE(fe.delivered_quantity, 0) as delivered_quantity,
       CASE 
         WHEN fe.product_type = 'roll' THEN (fe.actual_weight - COALESCE(fe.delivered_quantity, 0))
         ELSE (fe.passed_qty - COALESCE(fe.delivered_quantity, 0))
       END as remaining_quantity
FROM fg_entry fe
WHERE fe.reference_number IS NOT NULL
  AND (
    (fe.product_type = 'roll' AND fe.actual_weight > 0)
    OR 
    (fe.product_type = 'bag' AND fe.passed_qty > 0)
    OR
    (fe.product_type IS NULL AND fe.passed_qty > 0)
  )
  AND (
    (fe.product_type = 'roll' AND (fe.actual_weight - COALESCE(fe.delivered_quantity, 0)) > 0)
    OR
    (fe.product_type != 'roll' AND (fe.passed_qty - COALESCE(fe.delivered_quantity, 0)) > 0)
    OR
    (fe.product_type IS NULL AND (fe.passed_qty - COALESCE(fe.delivered_quantity, 0)) > 0)
  )";

echo "\n</pre>";
echo "<p><a href='../forms/fg_delivery_entry.php' style='padding:10px 20px; background:#27ae60; color:white; text-decoration:none; border-radius:5px;'>Go to FG Delivery Form</a></p>";

$conn->close();
?>


