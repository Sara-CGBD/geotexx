<?php
session_start();
require_once 'security_config.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$conn = SecurityConfig::getConnection();
$reference = "4.0L125OCT28-R03-GT0.9.H0.1";

echo "<h1>Checking Database for Reference: $reference</h1>";
echo "<style>
    body { font-family: Arial; padding: 20px; }
    pre { background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; }
    .success { color: green; font-weight: bold; }
    .error { color: red; font-weight: bold; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background: #4CAF50; color: white; }
</style>";

// Check ALL qc_test_orders records
echo "<h2>1. ALL qc_test_orders records (last 20)</h2>";
$query = "SELECT id, sample_reference_id, test_standard_id, chosen_method, 
          JSON_EXTRACT(test_data, '$.product_reference') as product_ref,
          JSON_EXTRACT(test_data, '$.fiber_reference_no') as fiber_ref,
          JSON_EXTRACT(test_data, '$.yarn_reference_no') as yarn_ref,
          created_at
          FROM qc_test_orders 
          ORDER BY created_at DESC
          LIMIT 20";
$result = $conn->query($query);

if ($result->num_rows > 0) {
    echo "<p class='success'>✓ Found " . $result->num_rows . " total records</p>";
    echo "<table><tr><th>ID</th><th>Sample Ref ID</th><th>Test Standard ID</th><th>Method</th><th>Product Ref</th><th>Fiber Ref</th><th>Yarn Ref</th><th>Created At</th></tr>";
    while ($row = $result->fetch_assoc()) {
        $highlight = (
            strpos($row['sample_reference_id'], $reference) !== false ||
            strpos($row['product_ref'], $reference) !== false ||
            strpos($row['fiber_ref'], $reference) !== false ||
            strpos($row['yarn_ref'], $reference) !== false
        ) ? "style='background: yellow;'" : "";
        
        echo "<tr $highlight>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['sample_reference_id']}</td>";
        echo "<td>{$row['test_standard_id']}</td>";
        echo "<td>{$row['chosen_method']}</td>";
        echo "<td>{$row['product_ref']}</td>";
        echo "<td>{$row['fiber_ref']}</td>";
        echo "<td>{$row['yarn_ref']}</td>";
        echo "<td>{$row['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>✗ No records found at all!</p>";
}

// Search for specific reference
echo "<h2>2. Searching for specific reference: $reference</h2>";
$query = "SELECT id, sample_reference_id, test_standard_id, chosen_method, test_data
          FROM qc_test_orders 
          WHERE sample_reference_id LIKE ? 
             OR JSON_EXTRACT(test_data, '$.product_reference') LIKE ?
             OR JSON_EXTRACT(test_data, '$.fiber_reference_no') LIKE ?
             OR JSON_EXTRACT(test_data, '$.yarn_reference_no') LIKE ?";
$stmt = $conn->prepare($query);
$likeRef = "%$reference%";
$stmt->bind_param("ssss", $likeRef, $likeRef, $likeRef, $likeRef);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "<p class='success'>✓ Found " . $result->num_rows . " matching record(s)</p>";
    echo "<table><tr><th>ID</th><th>Sample Ref ID</th><th>Test Standard ID</th><th>Method</th><th>Test Data (first 300 chars)</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['sample_reference_id']}</td>";
        echo "<td>{$row['test_standard_id']}</td>";
        echo "<td>{$row['chosen_method']}</td>";
        echo "<td><pre>" . htmlspecialchars(substr($row['test_data'], 0, 300)) . "...</pre></td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='error'>✗ NO RECORDS FOUND FOR THIS REFERENCE!</p>";
    echo "<p>This means the data was NOT saved in qc_test_orders table.</p>";
}

// Check test_standards table
echo "<h2>3. Check test_standards table</h2>";
$query = "SELECT id, test_name, standard_code FROM test_standards";
$result = $conn->query($query);
if ($result->num_rows > 0) {
    echo "<p class='success'>✓ Found " . $result->num_rows . " test standards</p>";
    echo "<table><tr><th>ID</th><th>Test Name</th><th>Standard Code</th></tr>";
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>{$row['test_name']}</td><td>{$row['standard_code']}</td></tr>";
        $count++;
        if ($count >= 10) {
            echo "<tr><td colspan='3'>... (showing first 10 only)</td></tr>";
            break;
        }
    }
    echo "</table>";
} else {
    echo "<p class='error'>✗ No test standards found!</p>";
}

$conn->close();
?>


