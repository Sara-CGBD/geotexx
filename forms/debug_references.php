<?php
session_start();
require_once 'security_config.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$conn = SecurityConfig::getConnection();

echo "<h2>Debug: Reference Numbers in Database</h2>";
echo "<style>table {border-collapse: collapse; width: 100%;} th, td {border: 1px solid #ddd; padding: 8px; text-align: left;} th {background: #4CAF50; color: white;}</style>";

// Check QC Test Orders
echo "<h3>1. QC Test Orders (qc_test_orders)</h3>";
$query = "SELECT id, sample_reference_id, test_data, created_at FROM qc_test_orders ORDER BY id DESC LIMIT 10";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    echo "<table><tr><th>ID</th><th>Sample Ref ID</th><th>Product Ref</th><th>Fiber Ref</th><th>Yarn Ref</th><th>Raw test_data</th><th>Created</th></tr>";
    while ($row = $result->fetch_assoc()) {
        $test_data = json_decode($row['test_data'], true);
        $product_ref = $test_data['product_reference'] ?? 'NULL';
        $fiber_ref = $test_data['fiber_reference_no'] ?? 'NULL';
        $yarn_ref = $test_data['yarn_reference_no'] ?? 'NULL';
        
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['sample_reference_id']}</td>";
        echo "<td>" . htmlspecialchars($product_ref) . "</td>";
        echo "<td>" . htmlspecialchars($fiber_ref) . "</td>";
        echo "<td>" . htmlspecialchars($yarn_ref) . "</td>";
        echo "<td><pre style='font-size:10px; max-width:300px; overflow:auto;'>" . htmlspecialchars(substr($row['test_data'], 0, 200)) . "...</pre></td>";
        echo "<td>{$row['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    echo "<p style='color:green;'><strong>Total records: " . $result->num_rows . "</strong></p>";
} else {
    echo "<p style='color:red;'>No data found or table doesn't exist. Error: " . $conn->error . "</p>";
}

// Check UV Test
echo "<h3>2. UV Test (weathering_exposure_reports)</h3>";
$query = "SELECT id, report_number, reference, sample_description, created_at FROM weathering_exposure_reports ORDER BY id DESC LIMIT 10";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    echo "<table><tr><th>ID</th><th>Report Number</th><th>Reference</th><th>Sample Description</th><th>Created</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['report_number']}</td>";
        echo "<td>" . htmlspecialchars($row['reference']) . "</td>";
        echo "<td>" . htmlspecialchars($row['sample_description']) . "</td>";
        echo "<td>{$row['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No data found or table doesn't exist</p>";
}

// Check Water Permeability
echo "<h3>3. Water Permeability Test (water_permeability_tests)</h3>";
$query = "SELECT id, report_number, reference_number, gsm, roll_number, created_at FROM water_permeability_tests ORDER BY id DESC LIMIT 10";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    echo "<table><tr><th>ID</th><th>Report Number</th><th>Reference Number</th><th>GSM</th><th>Roll Number</th><th>Created</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['report_number']}</td>";
        echo "<td>" . htmlspecialchars($row['reference_number']) . "</td>";
        echo "<td>{$row['gsm']}</td>";
        echo "<td>" . htmlspecialchars($row['roll_number']) . "</td>";
        echo "<td>{$row['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No data found or table doesn't exist</p>";
}

// Check Sun Test
echo "<h3>4. Sun Test (sun_test_reports)</h3>";
$query = "SELECT id, report_number, reference_number, sample_description, created_at FROM sun_test_reports ORDER BY id DESC LIMIT 10";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    echo "<table><tr><th>ID</th><th>Report Number</th><th>Reference Number</th><th>Sample Description</th><th>Created</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['report_number']}</td>";
        echo "<td>" . htmlspecialchars($row['reference_number']) . "</td>";
        echo "<td>" . htmlspecialchars($row['sample_description']) . "</td>";
        echo "<td>{$row['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No data found or table doesn't exist</p>";
}

// Check Fiber Test
echo "<h3>5. Fiber Test (fiber_test_reports)</h3>";
$query = "SELECT id, report_number, sample_id, sample_tested_date, created_at FROM fiber_test_reports ORDER BY id DESC LIMIT 10";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    echo "<table><tr><th>ID</th><th>Report Number</th><th>Sample ID</th><th>Sample Tested Date</th><th>Created</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['report_number']}</td>";
        echo "<td>" . htmlspecialchars($row['sample_id']) . "</td>";
        echo "<td>{$row['sample_tested_date']}</td>";
        echo "<td>{$row['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No data found or table doesn't exist</p>";
}

echo "<hr><h3>Searching for reference: 4.0L125OCT28-R03-GT0.9.H0.1</h3>";

// Test the actual query used in the API
$test_ref = "4.0L125OCT28-R03-GT0.9.H0.1";

echo "<h4>QC Test Orders Query:</h4>";
$stmt = $conn->prepare("
    SELECT 
        qto.id,
        qto.sample_reference_id,
        qto.test_data
    FROM qc_test_orders qto
    WHERE (
        JSON_UNQUOTE(JSON_EXTRACT(qto.test_data, '$.product_reference')) = ?
        OR JSON_UNQUOTE(JSON_EXTRACT(qto.test_data, '$.fiber_reference_no')) = ?
        OR JSON_UNQUOTE(JSON_EXTRACT(qto.test_data, '$.yarn_reference_no')) = ?
    )
");
$stmt->bind_param("sss", $test_ref, $test_ref, $test_ref);
$stmt->execute();
$result = $stmt->get_result();
echo "<p>Found " . $result->num_rows . " records</p>";
if ($result->num_rows > 0) {
    echo "<table><tr><th>ID</th><th>Sample Ref ID</th><th>Test Data</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>{$row['sample_reference_id']}</td><td><pre>" . htmlspecialchars($row['test_data']) . "</pre></td></tr>";
    }
    echo "</table>";
}

$conn->close();
?>


