<?php
// Delete all BOM entries and remove polymer materials

$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

echo "<h2>Cleaning up BOM...</h2>";

// 1. Delete all BOM entries
$result = $conn->query("SELECT COUNT(*) as count FROM bom");
$before = $result->fetch_assoc()['count'];

echo "<p><strong>1. Deleting all BOM entries...</strong></p>";
echo "<p>Current BOM entries: <strong>$before</strong></p>";

$result = $conn->query("DELETE FROM bom");
if ($result) {
    $result = $conn->query("SELECT COUNT(*) as count FROM bom");
    $after = $result->fetch_assoc()['count'];
    echo "<p style='color: green;'>âœ“ Deleted <strong>" . ($before - $after) . "</strong> BOM entries</p>";
} else {
    echo "<p style='color: red;'>âœ— Error: " . $conn->error . "</p>";
}

// 2. Remove polymer materials
echo "<br><p><strong>2. Removing polymer materials from database...</strong></p>";

// Remove all materials containing these keywords
$keywords = ['polyester', 'polyethylene', 'polypropylene', 'polyethene', 'polyester'];

foreach ($keywords as $keyword) {
    $stmt = $conn->prepare("DELETE FROM materials WHERE LOWER(material_name) LIKE ?");
    $search = "%$keyword%";
    $stmt->bind_param('s', $search);
    
    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        if ($affected > 0) {
            echo "<p style='color: green;'>âœ“ Removed materials containing '$keyword' ($affected row(s))</p>";
        }
    } else {
        echo "<p style='color: red;'>âœ— Error with keyword '$keyword': " . $stmt->error . "</p>";
    }
    $stmt->close();
}

// Also remove any variations with PE, PP, PS
$variations = ['PE', 'PP', 'PS', '(PE)', '(PP)', '(PS)'];
foreach ($variations as $var) {
    $stmt = $conn->prepare("DELETE FROM materials WHERE material_name LIKE ?");
    $search = "%$var%";
    $stmt->bind_param('s', $search);
    
    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        if ($affected > 0) {
            echo "<p style='color: green;'>âœ“ Removed materials containing '$var' ($affected row(s))</p>";
        }
    }
    $stmt->close();
}

echo "<br><h3>Remaining materials:</h3>";
$result = $conn->query("SELECT id, material_name FROM materials WHERE is_deleted = 0 ORDER BY material_name");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "<p>" . $row['id'] . ': ' . htmlspecialchars($row['material_name']) . "</p>";
    }
}

$conn->close();

echo "<br><p><a href='../forms/BOM_entry.php'>Go to BOM Entry</a></p>";
echo "<p><a href='../index.php'>Back to Dashboard</a></p>";
?>



