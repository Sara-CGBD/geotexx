<?php
// fix_cnc_quantities.php
// One-time script to fix incorrect used_qty and remaining_qty values in cnc_entries

session_start();
require_once '../config/security_config.php';

// Security check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    die('Unauthorized');
}

$conn = SecurityConfig::getConnection();

// Ensure columns exist
$conn->query("ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS used_qty INT DEFAULT 0");
$conn->query("ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS remaining_qty INT DEFAULT 0");

// Fix entries where used_qty exceeds cutting_roll_quantity
$fix_query = "UPDATE cnc_entries 
              SET used_qty = cutting_roll_quantity,
                  remaining_qty = 0
              WHERE used_qty > cutting_roll_quantity";

$result = $conn->query($fix_query);
$fixed_count = $conn->affected_rows;

// Recalculate remaining_qty for all entries
$recalc_query = "UPDATE cnc_entries 
                 SET remaining_qty = GREATEST(0, cutting_roll_quantity - COALESCE(used_qty, 0))
                 WHERE cutting_roll_quantity > 0";

$recalc_result = $conn->query($recalc_query);
$recalc_count = $conn->affected_rows;

echo "Fixed {$fixed_count} entries with incorrect used_qty<br>";
echo "Recalculated remaining_qty for {$recalc_count} entries<br>";
echo "Done!";

$conn->close();
?>
