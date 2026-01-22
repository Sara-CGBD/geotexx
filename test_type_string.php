<?php
$typeString = 'sisssssssssdsdddssisddss';
echo "Type string: " . $typeString . "\n";
echo "Length: " . strlen($typeString) . "\n";

// Count each type
$sCount = substr_count($typeString, 's');
$iCount = substr_count($typeString, 'i');
$dCount = substr_count($typeString, 'd');

echo "s count: " . $sCount . "\n";
echo "i count: " . $iCount . "\n";
echo "d count: " . $dCount . "\n";
echo "Total: " . ($sCount + $iCount + $dCount) . "\n";

// Expected: 24 parameters
// 1. s - delivery_id
// 2. i - fg_entry_id
// 3-11. s (9 more) - reference_number, cnc_cutting_batch, delivery_date, shift, challan_no, truck_no, destination, bag_size, packaging_type
// 12. d - delivery_quantity
// 13. s - delivery_quantity_unit
// 14-15. d (2 more) - weight_kg, area_sqm
// 16-17. s (2 more) - delivery_product_type, delivery_roll_entry_type
// 18. i - client_id
// 19. s - client_name
// 20-21. d (2 more) - unit_price, total_cost
// 22-24. s (3 more) - remarks, delivered_by, delivered_at

$expected = 'sisssssssssdsdddssisddss';
echo "\nExpected: " . $expected . "\n";
echo "Expected length: " . strlen($expected) . "\n";
?>
