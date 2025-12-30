<?php
// Test what FG entries are being loaded by the delivery form
session_start();
require_once '../config/security_config.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>FG Entries Loading Test</h2>";
echo "<pre>";

$conn = SecurityConfig::getConnection();

// Ensure delivered_quantity column exists
$colCheck = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'delivered_quantity'");
if ($colCheck && $colCheck->num_rows == 0) {
    echo "⚠️  Creating delivered_quantity column...\n";
    $conn->query("ALTER TABLE fg_entry ADD COLUMN delivered_quantity DECIMAL(10,2) DEFAULT 0 AFTER batch_number");
}

// Use the EXACT same query as fg_delivery_entry.php
$fgQuery = "SELECT 
              fe.id,
              fe.fg_id,
              fe.reference_number,
              fe.cnc_cutting_batch,
              fe.bag_size,
              fe.packaging_type,
              fe.passed_qty,
              fe.actual_weight,
              fe.product_type,
              fe.roll_entry_type,
              COALESCE(fe.delivered_quantity, 0) as delivered_quantity,
              CASE 
                WHEN fe.product_type = 'roll' THEN (fe.actual_weight - COALESCE(fe.delivered_quantity, 0))
                ELSE (fe.passed_qty - COALESCE(fe.delivered_quantity, 0))
              END as remaining_quantity,
              p.project_name
            FROM fg_entry fe
            LEFT JOIN projects p ON fe.project_id = p.id
            WHERE fe.reference_number IS NOT NULL
              AND fe.reference_number != ''
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
              )
            ORDER BY fe.created_at DESC
            LIMIT 100";

echo "📋 Running Query...\n\n";

$fgResult = $conn->query($fgQuery);

if (!$fgResult) {
    echo "❌ QUERY FAILED!\n";
    echo "Error: " . $conn->error . "\n";
    exit;
}

$fgEntries = [];
while ($row = $fgResult->fetch_assoc()) {
    $fgEntries[] = $row;
}

echo "✅ Query executed successfully!\n";
echo "📊 Total Entries Found: " . count($fgEntries) . "\n\n";

if (count($fgEntries) == 0) {
    echo "⚠️  NO ENTRIES FOUND!\n\n";
    echo "Checking why...\n\n";
    
    // Check if any entries exist at all
    $anyEntries = $conn->query("SELECT COUNT(*) as total FROM fg_entry WHERE reference_number IS NOT NULL")->fetch_assoc();
    echo "Total entries with reference_number: " . $anyEntries['total'] . "\n\n";
    
    // Check specific entry
    $specific = $conn->query("SELECT * FROM fg_entry WHERE reference_number = '3.5L225NOV13-R01-GT0.9.H0.1'");
    if ($specific && $specific->num_rows > 0) {
        $entry = $specific->fetch_assoc();
        echo "Found target entry:\n";
        echo "  ID: {$entry['id']}\n";
        echo "  Reference: {$entry['reference_number']}\n";
        echo "  Product Type: " . ($entry['product_type'] ?? 'NULL') . "\n";
        echo "  Passed Qty: {$entry['passed_qty']}\n";
        echo "  Delivered Qty: " . ($entry['delivered_quantity'] ?? '0') . "\n";
        echo "  Actual Weight: " . ($entry['actual_weight'] ?? '0') . "\n";
        
        // Check each filter condition
        echo "\nFilter Test Results:\n";
        echo "  reference_number IS NOT NULL: " . ($entry['reference_number'] ? "✅ PASS" : "❌ FAIL") . "\n";
        echo "  reference_number != '': " . ($entry['reference_number'] != '' ? "✅ PASS" : "❌ FAIL") . "\n";
        
        $productTest = ($entry['product_type'] === 'bag' && $entry['passed_qty'] > 0);
        echo "  product_type='bag' AND passed_qty>0: " . ($productTest ? "✅ PASS" : "❌ FAIL") . "\n";
        
        $remaining = $entry['passed_qty'] - ($entry['delivered_quantity'] ?? 0);
        echo "  remaining_quantity > 0: " . ($remaining > 0 ? "✅ PASS ({$remaining})" : "❌ FAIL ({$remaining})") . "\n";
    } else {
        echo "❌ Target entry not found in database!\n";
    }
    
} else {
    echo "📋 Entries Found:\n";
    echo str_repeat("-", 100) . "\n";
    echo str_pad("ID", 5) . str_pad("Reference", 40) . str_pad("Type", 10) . str_pad("Passed", 10) . str_pad("Delivered", 12) . "Remaining\n";
    echo str_repeat("-", 100) . "\n";
    
    foreach ($fgEntries as $entry) {
        echo str_pad($entry['id'], 5) . 
             str_pad(substr($entry['reference_number'], 0, 38), 40) . 
             str_pad($entry['product_type'] ?? 'NULL', 10) . 
             str_pad($entry['passed_qty'], 10) . 
             str_pad($entry['delivered_quantity'], 12) . 
             $entry['remaining_quantity'] . "\n";
    }
    
    echo str_repeat("-", 100) . "\n\n";
    
    // Show JSON that would be passed to JavaScript
    echo "📦 JSON Data (first 2 entries):\n";
    echo json_encode(array_slice($fgEntries, 0, 2), JSON_PRETTY_PRINT) . "\n";
}

echo "\n</pre>";
echo "<p><a href='reload_delivery_form.php' style='padding:10px 20px; background:#27ae60; color:white; text-decoration:none; border-radius:5px;'>✅ Reload FG Delivery Form (Force Fresh)</a></p>";

$conn->close();
?>


