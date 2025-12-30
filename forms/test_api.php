<?php
session_start();
require_once 'security_config.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$conn = SecurityConfig::getConnection();
$reference = "4.0L125OCT28-R03-GT0.9.H0.1";

echo "<h1>Testing QC Summary API for: $reference</h1>";
echo "<style>
    body { font-family: Arial; padding: 20px; }
    pre { background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background: #4CAF50; color: white; }
</style>";

// Test 1: Check qc_test_orders table
echo "<h2>Test 1: Check qc_test_orders records</h2>";
$query = "SELECT id, sample_reference_id, test_standard_id, chosen_method, test_data 
          FROM qc_test_orders 
          WHERE JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.product_reference')) = ?
             OR JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.fiber_reference_no')) = ?
             OR JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.yarn_reference_no')) = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("sss", $reference, $reference, $reference);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "<p class='success'>✓ Found " . $result->num_rows . " record(s)</p>";
    echo "<table><tr><th>ID</th><th>Sample Ref ID</th><th>Test Standard ID</th><th>Chosen Method</th><th>Test Data</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['sample_reference_id']}</td>";
        echo "<td>{$row['test_standard_id']}</td>";
        echo "<td>{$row['chosen_method']}</td>";
        echo "<td><pre>" . htmlspecialchars(substr($row['test_data'], 0, 200)) . "...</pre></td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>✗ No records found</p>";
}

// Test 2: Check if test_standards table has matching records
echo "<h2>Test 2: Check test_standards table</h2>";
$query = "SELECT id, test_name, standard_code FROM test_standards ORDER BY id";
$result = $conn->query($query);
if ($result->num_rows > 0) {
    echo "<p class='success'>✓ Found " . $result->num_rows . " test standards</p>";
    echo "<table><tr><th>ID</th><th>Test Name</th><th>Standard Code</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>{$row['test_name']}</td><td>{$row['standard_code']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>✗ No test standards found - THIS IS THE PROBLEM!</p>";
}

// Test 3: Try the JOIN query
echo "<h2>Test 3: Try the actual API query (with JOIN)</h2>";
$query = "SELECT 
    qto.id,
    qto.sample_reference_id,
    qto.test_data,
    qto.created_at,
    qto.test_standard_id,
    ts.test_name,
    qto.chosen_method as method
FROM qc_test_orders qto
INNER JOIN test_standards ts ON qto.test_standard_id = ts.id
WHERE (
    JSON_UNQUOTE(JSON_EXTRACT(qto.test_data, '$.product_reference')) = ?
    OR JSON_UNQUOTE(JSON_EXTRACT(qto.test_data, '$.fiber_reference_no')) = ?
    OR JSON_UNQUOTE(JSON_EXTRACT(qto.test_data, '$.yarn_reference_no')) = ?
)
ORDER BY qto.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("sss", $reference, $reference, $reference);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "<p class='success'>✓ JOIN query found " . $result->num_rows . " record(s)</p>";
    echo "<table><tr><th>ID</th><th>Sample Ref ID</th><th>Test Standard ID</th><th>Test Name</th><th>Method</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['sample_reference_id']}</td>";
        echo "<td>{$row['test_standard_id']}</td>";
        echo "<td>{$row['test_name']}</td>";
        echo "<td>{$row['method']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>✗ JOIN query found NO records - The test_standard_id doesn't exist in test_standards table!</p>";
}

// Test 4: Check for orphaned records
echo "<h2>Test 4: Check for orphaned qc_test_orders (no matching test_standard)</h2>";
$query = "SELECT qto.id, qto.test_standard_id, qto.sample_reference_id
          FROM qc_test_orders qto
          LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
          WHERE ts.id IS NULL";
$result = $conn->query($query);
if ($result->num_rows > 0) {
    echo "<p class='error'>✗ Found " . $result->num_rows . " orphaned record(s) - test_standard_id doesn't exist!</p>";
    echo "<table><tr><th>QC Order ID</th><th>Test Standard ID (invalid)</th><th>Sample Ref ID</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>{$row['test_standard_id']}</td><td>{$row['sample_reference_id']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='success'>✓ No orphaned records found</p>";
}

$conn->close();
?>


