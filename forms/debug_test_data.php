<?php
session_start();
require_once 'security_config.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$conn = SecurityConfig::getConnection();
$reference = "4.0L125OCT28-R03-GT0.9.H0.1";

echo "<h1>Debug Test Data for: $reference</h1>";
echo "<style>
    body { font-family: Arial; padding: 20px; }
    pre { background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; white-space: pre-wrap; }
    .success { color: green; font-weight: bold; }
    h3 { color: #2c3e50; margin-top: 30px; }
</style>";

$query = "SELECT qto.id, qto.sample_reference_id, qto.test_data, ts.test_name, qto.chosen_method
          FROM qc_test_orders qto
          INNER JOIN test_standards ts ON qto.test_standard_id = ts.id
          WHERE qto.sample_reference_id = ?
          ORDER BY qto.created_at DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $reference);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo "<p class='success'>✓ Found " . $result->num_rows . " test(s)</p>";
    
    while ($row = $result->fetch_assoc()) {
        echo "<h3>Test: {$row['test_name']} ({$row['chosen_method']}) - ID: {$row['id']}</h3>";
        echo "<p><strong>Sample Reference ID:</strong> {$row['sample_reference_id']}</p>";
        
        $test_data = json_decode($row['test_data'], true);
        echo "<p><strong>Test Data JSON (Pretty Print):</strong></p>";
        echo "<pre>" . json_encode($test_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "</pre>";
        
        echo "<p><strong>Available Keys:</strong></p>";
        echo "<ul>";
        foreach (array_keys($test_data) as $key) {
            $type = gettype($test_data[$key]);
            $info = $type;
            if (is_array($test_data[$key])) {
                $info .= " (" . count($test_data[$key]) . " items)";
            }
            echo "<li><strong>$key</strong>: $info</li>";
        }
        echo "</ul>";
        
        echo "<hr>";
    }
} else {
    echo "<p style='color:red;'>✗ No tests found for this reference</p>";
}

$conn->close();
?>


