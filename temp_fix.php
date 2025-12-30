<?php
// Temporary file to fix the type string
$content = file_get_contents('handlers/submit_fiber_entry.php');
$content = str_replace("'ssisdisis',", "'ssisdisis',", $content);
file_put_contents('handlers/submit_fiber_entry.php', $content);
echo "Fixed!";
?>

