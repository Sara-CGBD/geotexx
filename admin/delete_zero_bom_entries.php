<?php
// Delete BOM entries with GSM or thickness = 0

$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>Deleting BOM entries...</h2>";

// Delete entries with 0 or NULL values for GSM and thickness
$result = $conn->query("SELECT COUNT(*) as count FROM bom WHERE (gsm = 0 OR gsm IS NULL) AND (thickness_mm = 0 OR thickness_mm IS NULL)");
$before = $result->fetch_assoc()['count'];

echo "<p>Found <strong>$before</strong> BOM entries with GSM/thickness = 0 or NULL</p>";

// Show which entries will be deleted
echo "<h3>Entries to be deleted:</h3>";
$result = $conn->query("SELECT id, material_id, bag_size, gsm, thickness_mm, weight, cost FROM bom WHERE (gsm = 0 OR gsm IS NULL) AND (thickness_mm = 0 OR thickness_mm IS NULL)");
echo "<ul>";
while ($row = $result->fetch_assoc()) {
    echo "<li>ID: {$row['id']}, Bag Size: {$row['bag_size']}, Weight: {$row['weight']}, GSM: {$row['gsm']}, Thickness: {$row['thickness_mm']}, Cost: {$row['cost']}</li>";
}
echo "</ul>";

// Delete entries
$result = $conn->query("DELETE FROM bom WHERE (gsm = 0 OR gsm IS NULL) AND (thickness_mm = 0 OR thickness_mm IS NULL)");

if ($result) {
    echo "<p style='color: green;'>âœ“ Deleted <strong>$before</strong> BOM entries successfully</p>";
} else {
    echo "<p style='color: red;'>âœ— Error: " . $conn->error . "</p>";
}

// Show remaining entries
$result = $conn->query("SELECT COUNT(*) as count FROM bom");
$remaining = $result->fetch_assoc()['count'];
echo "<p>Remaining BOM entries: <strong>$remaining</strong></p>";

$conn->close();

echo "<br><p><a href='../reports/bom_entry_log.php'>View BOM Entry Log</a></p>";
echo "<p><a href='../forms/BOM_entry.php'>Go to BOM Entry</a></p>";
echo "<p><a href='../index.php'>Back to Dashboard</a></p>";
?>



