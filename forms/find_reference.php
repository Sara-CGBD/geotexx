<?php
session_start();
require_once 'security_config.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$conn = SecurityConfig::getConnection();
$search_ref = $_GET['ref'] ?? "1.8L1250CT21-R02-GT0.9.H0.1";

echo "<h1>Finding Reference: $search_ref</h1>";
echo "<form method='GET' style='margin: 20px 0; padding: 15px; background: #f0f0f0; border-radius: 5px;'>";
echo "<label>Search for different reference: </label>";
echo "<input type='text' name='ref' value='" . htmlspecialchars($search_ref) . "' style='padding: 8px; width: 300px;'>";
echo "<button type='submit' style='padding: 8px 20px; background: #4CAF50; color: white; border: none; cursor: pointer;'>Search</button>";
echo "</form>";
echo "<style>
    body { font-family: Arial; padding: 20px; }
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background: #4CAF50; color: white; }
    .found { background: #d4edda; }
    .not-found { background: #f8d7da; }
    pre { background: #f5f5f5; padding: 10px; overflow-x: auto; }
</style>";

// 1. Check QC Test Orders
echo "<h2>1. QC Test Orders (qc_test_orders)</h2>";
$query = "SELECT id, sample_reference_id, test_data FROM qc_test_orders";
$result = $conn->query($query);
$found = false;
if ($result && $result->num_rows > 0) {
    echo "<table><tr><th>ID</th><th>Sample Ref ID</th><th>Match?</th><th>Test Data (first 500 chars)</th></tr>";
    while ($row = $result->fetch_assoc()) {
        $test_data = $row['test_data'];
        $matches = (
            strpos($test_data, $search_ref) !== false ||
            strpos($row['sample_reference_id'], $search_ref) !== false
        );
        
        if ($matches) {
            $found = true;
            echo "<tr class='found'>";
            echo "<td>{$row['id']}</td>";
            echo "<td>{$row['sample_reference_id']}</td>";
            echo "<td><strong>✓ FOUND!</strong></td>";
            echo "<td><pre>" . htmlspecialchars(substr($test_data, 0, 500)) . "...</pre></td>";
            echo "</tr>";
        }
    }
    echo "</table>";
    if (!$found) echo "<p class='not-found'>Reference NOT found in qc_test_orders</p>";
} else {
    echo "<p class='not-found'>No records in qc_test_orders</p>";
}

// 2. Check UV Test
echo "<h2>2. UV Test (weathering_exposure_reports)</h2>";
$query = "SELECT id, report_number, reference, sample_description FROM weathering_exposure_reports WHERE reference LIKE '%$search_ref%'";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    echo "<p class='found'><strong>✓ FOUND " . $result->num_rows . " record(s)</strong></p>";
    echo "<table><tr><th>ID</th><th>Report Number</th><th>Reference</th><th>Sample Description</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr class='found'>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['report_number']}</td>";
        echo "<td><strong>{$row['reference']}</strong></td>";
        echo "<td>{$row['sample_description']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='not-found'>Reference NOT found in weathering_exposure_reports</p>";
}

// 3. Check Water Permeability
echo "<h2>3. Water Permeability (water_permeability_tests)</h2>";
$query = "SELECT id, report_number, reference_number, gsm FROM water_permeability_tests WHERE reference_number LIKE '%$search_ref%'";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    echo "<p class='found'><strong>✓ FOUND " . $result->num_rows . " record(s)</strong></p>";
    echo "<table><tr><th>ID</th><th>Report Number</th><th>Reference Number</th><th>GSM</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr class='found'>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['report_number']}</td>";
        echo "<td><strong>{$row['reference_number']}</strong></td>";
        echo "<td>{$row['gsm']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='not-found'>Reference NOT found in water_permeability_tests</p>";
}

// 4. Check Sun Test
echo "<h2>4. Sun Test (sun_test_reports)</h2>";
$query = "SELECT id, report_number, reference_number, sample_description FROM sun_test_reports WHERE reference_number LIKE '%$search_ref%'";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    echo "<p class='found'><strong>✓ FOUND " . $result->num_rows . " record(s)</strong></p>";
    echo "<table><tr><th>ID</th><th>Report Number</th><th>Reference Number</th><th>Sample Description</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr class='found'>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['report_number']}</td>";
        echo "<td><strong>{$row['reference_number']}</strong></td>";
        echo "<td>{$row['sample_description']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='not-found'>Reference NOT found in sun_test_reports</p>";
}

// 5. Check Fiber Test
echo "<h2>5. Fiber Test (fiber_test_reports)</h2>";
$query = "SELECT id, report_number, sample_id, sample_tested_date FROM fiber_test_reports WHERE sample_id LIKE '%$search_ref%'";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    echo "<p class='found'><strong>✓ FOUND " . $result->num_rows . " record(s)</strong></p>";
    echo "<table><tr><th>ID</th><th>Report Number</th><th>Sample ID</th><th>Sample Tested Date</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr class='found'>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['report_number']}</td>";
        echo "<td><strong>{$row['sample_id']}</strong></td>";
        echo "<td>{$row['sample_tested_date']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p class='not-found'>Reference NOT found in fiber_test_reports</p>";
}

// 6. Check where the reference appears in the dropdown query
echo "<h2>6. Dropdown Source - Where does '4.0L125OCT28-R03-GT0.9.H0.1' come from?</h2>";
echo "<h3>From QC Test Orders JSON:</h3>";
$query = "SELECT id, 
    JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.product_reference')) as prod_ref,
    JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.fiber_reference_no')) as fiber_ref,
    JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.yarn_reference_no')) as yarn_ref
FROM qc_test_orders 
WHERE JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.product_reference')) LIKE '%$search_ref%'
   OR JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.fiber_reference_no')) LIKE '%$search_ref%'
   OR JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.yarn_reference_no')) LIKE '%$search_ref%'";
$result = $conn->query($query);
if ($result && $result->num_rows > 0) {
    echo "<p class='found'><strong>✓ FOUND in QC Test Orders JSON</strong></p>";
    while ($row = $result->fetch_assoc()) {
        echo "<pre>";
        print_r($row);
        echo "</pre>";
    }
} else {
    echo "<p class='not-found'>NOT in QC Test Orders JSON</p>";
}

$conn->close();
?>


