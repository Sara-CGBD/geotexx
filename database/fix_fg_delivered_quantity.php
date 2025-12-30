<?php
/**
 * One-time script to recalculate delivered_quantity in fg_entry table
 * based on actual deliveries from fg_deliveries table
 * 
 * This fixes any inconsistencies where deliveries were deleted but
 * the cached delivered_quantity value wasn't updated.
 */

require_once '../config/security_config.php';

$conn = SecurityConfig::getConnection();

echo "Starting to recalculate delivered_quantity for all FG entries...\n\n";

// Recalculate delivered_quantity for all FG entries
$updateQuery = "UPDATE fg_entry fe
                SET delivered_quantity = (
                    SELECT COALESCE(SUM(fd.delivery_quantity), 0)
                    FROM fg_deliveries fd
                    WHERE fd.fg_entry_id = fe.id
                )";

if ($conn->query($updateQuery)) {
    $affectedRows = $conn->affected_rows;
    echo "✅ Successfully updated delivered_quantity for {$affectedRows} FG entries.\n";
    echo "All cached values are now in sync with actual deliveries.\n";
} else {
    echo "❌ Error: " . $conn->error . "\n";
}

$conn->close();
?>


