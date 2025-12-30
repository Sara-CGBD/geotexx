<?php
// Force cache clear and reload FG Delivery form
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Add timestamp to force fresh load
$timestamp = time();
header("Location: ../forms/fg_delivery_entry.php?nocache=$timestamp");
exit;
?>


