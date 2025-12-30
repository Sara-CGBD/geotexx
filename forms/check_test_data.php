<?php
session_start();
require_once 'security_config.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$conn = SecurityConfig::getConnection();
$reference = "4.0L125OCT28-R03-GT0.9.H0.1";

echo "<h1>Checking test_data structure for: $reference</h1>";
echo "<style>
    body { font-family: Arial; padding: 20px; }
    pre { background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; white-space: pre-wrap; }
    .success { color: green; font-weight: bold; }
    h3 { color: #2c3e50; margin-top: 30px; border-bottom: 2px solid #3498db; padding-bottom: 5px; }
</style>";

$query = "SELECT qto.id, qto.sample_reference_id, qto.test_data, ts.test_name, qto.chosen_method
          FROM qc_test_orders qto
          INNER JOIN test_standards ts ON qto.test_standard_id = ts.id
          WHERE JSON_UNQUOTE(JSON_EXTRACT(qto.test_data, '$.fiber_reference_no')) = ?
             OR JSON_UNQUOTE(JSON_EXTRACT(qto.test_data, '$.yarn_reference_no')) = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $reference, $reference);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "<p class='success'>✓ Found " . $result->num_rows . " record(s)</p>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<h3>Record ID: {$row['id']} - {$row['test_name']} ({$row['chosen_method']})</h3>";
        echo "<p><strong>Sample Reference ID:</strong> {$row['sample_reference_id']}</p>";
        echo "<p><strong>test_data JSON:</strong></p>";
        
        $test_data = json_decode($row['test_data'], true);
        echo "<pre>" . json_encode($test_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
        
        echo "<p><strong>Available keys in test_data:</strong></p>";
        echo "<ul>";
        foreach (array_keys($test_data) as $key) {
            echo "<li><strong>$key</strong> (" . gettype($test_data[$key]) . ")";
            if (is_array($test_data[$key])) {
                echo " - " . count($test_data[$key]) . " items";
            }
            echo "</li>";
        }
        echo "</ul>";
    }
} else {
    echo "<p style='color:red;'>✗ No records found</p>";
}

$conn->close();
?>


