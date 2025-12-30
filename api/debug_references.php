<?php
session_start();
require_once '../forms/security_config.php';

header('Content-Type: text/html; charset=utf-8');

// Get database connection
$conn = SecurityConfig::getConnection();

echo "<h2>Debug: QC Test Orders Data</h2>";

// Get all qc_test_orders with test details
$query = "
    SELECT 
        qto.id,
        qto.sample_reference_id,
        ts.test_name,
        qto.chosen_method,
        qto.test_data,
        JSON_UNQUOTE(JSON_EXTRACT(qto.test_data, '$.product_reference')) as product_ref,
        JSON_UNQUOTE(JSON_EXTRACT(qto.test_data, '$.fiber_reference_no')) as fiber_ref,
        qto.created_at
    FROM qc_test_orders qto
    INNER JOIN test_standards ts ON qto.test_standard_id = ts.id
    ORDER BY qto.created_at DESC
    LIMIT 20
";

$result = $conn->query($query);

echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr>
    <th>ID</th>
    <th>Sample Ref ID</th>
    <th>Test Name</th>
    <th>Method</th>
    <th>Product Ref</th>
    <th>Fiber Ref</th>
    <th>Test Data JSON</th>
    <th>Created At</th>
</tr>";

while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['sample_reference_id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['test_name']) . "</td>";
    echo "<td>" . htmlspecialchars($row['chosen_method']) . "</td>";
    echo "<td>" . htmlspecialchars($row['product_ref'] ?? 'NULL') . "</td>";
    echo "<td>" . htmlspecialchars($row['fiber_ref'] ?? 'NULL') . "</td>";
    echo "<td><pre>" . htmlspecialchars($row['test_data']) . "</pre></td>";
    echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
    echo "</tr>";
}

echo "</table>";

// Test the specific query for Fineness of Fiber
echo "<h3>Test Query: Fineness of Fiber + ISO 1973</h3>";

$test_name = 'Fineness of Fiber';
$method = 'ISO 1973';

$stmt = $conn->prepare("
    SELECT DISTINCT 
        qto.id,
        ts.test_name,
        qto.chosen_method,
        COALESCE(
            JSON_UNQUOTE(JSON_EXTRACT(qto.test_data, '$.product_reference')),
            JSON_UNQUOTE(JSON_EXTRACT(qto.test_data, '$.fiber_reference_no'))
        ) as reference_no,
        qto.test_data
    FROM qc_test_orders qto
    INNER JOIN test_standards ts ON qto.test_standard_id = ts.id
    WHERE ts.test_name = ? 
    AND qto.chosen_method = ?
    AND (
        JSON_EXTRACT(qto.test_data, '$.product_reference') IS NOT NULL
        OR JSON_EXTRACT(qto.test_data, '$.fiber_reference_no') IS NOT NULL
    )
");

$stmt->bind_param("ss", $test_name, $method);
$stmt->execute();
$result = $stmt->get_result();

echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr><th>ID</th><th>Test Name</th><th>Method</th><th>Reference No</th><th>Test Data</th></tr>";

$refs = [];
while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['test_name']) . "</td>";
    echo "<td>" . htmlspecialchars($row['chosen_method']) . "</td>";
    echo "<td><strong>" . htmlspecialchars($row['reference_no'] ?? 'NULL') . "</strong></td>";
    echo "<td><pre>" . htmlspecialchars($row['test_data']) . "</pre></td>";
    echo "</tr>";
    
    if (!empty($row['reference_no']) && $row['reference_no'] !== 'null') {
        $refs[] = $row['reference_no'];
    }
}

echo "</table>";

echo "<h4>References to hide: " . json_encode($refs) . "</h4>";

$conn->close();
?>


