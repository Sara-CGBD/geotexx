<?php
// Fix the bind_param type string
$content = file_get_contents('handlers/submit_fiber_to_roll_entry.php');
$content = str_replace("'siissiissid',", "'siissiissid',", $content);
file_put_contents('handlers/submit_fiber_to_roll_entry.php', $content);
echo "Fixed!";
?>

