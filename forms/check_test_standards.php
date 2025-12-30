<?php
session_start();
require_once 'security_config.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$conn = SecurityConfig::getConnection();

echo "<h1>Checking test_standards IDs 28 and 41</h1>";
echo "<style>
    body { font-family: Arial; padding: 20px; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background: #4CAF50; color: white; }
</style>";

// Check if test_standard_id 28 and 41 exist
$query = "SELECT * FROM test_standards WHERE id IN (28, 41)";
$result = $conn->query($query);

echo "<h2>Looking for test_standard IDs 28 and 41:</h2>";
if ($result->num_rows > 0) {
    echo "<p class='success'>✓ Found " . $result->num_rows . " matching test_standard(s)</p>";
    echo "<table><tr><th>ID</th><th>Test Name</th><th>Standard Code</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>{$row['test_name']}</td><td>{$row['standard_code']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>✗ IDs 28 and 41 do NOT exist in test_standards table!</p>";
    echo "<p>This is why the JOIN fails and no data shows in the summary report.</p>";
}

// Show all test_standards
echo "<h2>ALL test_standards in database:</h2>";
$query = "SELECT * FROM test_standards ORDER BY id";
$result = $conn->query($query);
echo "<p>Total: " . $result->num_rows . " records</p>";
echo "<table><tr><th>ID</th><th>Test Name</th><th>Standard Code</th></tr>";
while ($row = $result->fetch_assoc()) {
    $highlight = ($row['id'] == 28 || $row['id'] == 41) ? "style='background: yellow;'" : "";
    echo "<tr $highlight><td>{$row['id']}</td><td>{$row['test_name']}</td><td>{$row['standard_code']}</td></tr>";
}
echo "</table>";

// Check what test_name should be for "Fineness of Fiber" and "Tenacity of Yarn"
echo "<h2>Search for Fiber and Yarn tests:</h2>";
$query = "SELECT * FROM test_standards WHERE test_name LIKE '%fiber%' OR test_name LIKE '%yarn%' OR test_name LIKE '%tenacity%' OR test_name LIKE '%fineness%'";
$result = $conn->query($query);
if ($result->num_rows > 0) {
    echo "<p class='success'>✓ Found " . $result->num_rows . " matching test(s)</p>";
    echo "<table><tr><th>ID</th><th>Test Name</th><th>Standard Code</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>{$row['test_name']}</td><td>{$row['standard_code']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>✗ No fiber/yarn tests found in test_standards!</p>";
}

$conn->close();
?>


