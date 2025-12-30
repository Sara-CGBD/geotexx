<?php
require_once 'security_config.php';

$conn = SecurityConfig::getConnection();

// Check existing test standards
echo "Existing test standards:\n";
$result = $conn->query("SELECT test_name, standard_code FROM test_standards ORDER BY test_name");
while ($row = $result->fetch_assoc()) {
    echo "  - " . $row['test_name'] . " - " . $row['standard_code'] . "\n";
}
echo "\n";

// Test standards that should exist based on qc_test_order.php
$required_standards = [
    ['test_name' => 'Thickness (Under 2kPa Pressure)', 'standard_code' => 'ASTM D5199', 'description' => 'Thickness Test Under 2kPa Pressure'],
    ['test_name' => 'Thickness (Under 2kPa Pressure)', 'standard_code' => 'ISO 9863-1', 'description' => 'Thickness Test Under 2kPa Pressure'],
    ['test_name' => 'Mass Per Unit Area (GSM)', 'standard_code' => 'ASTM D5261', 'description' => 'Mass Per Unit Area Test'],
    ['test_name' => 'Mass Per Unit Area (GSM)', 'standard_code' => 'ISO 9864', 'description' => 'Mass Per Unit Area Test'],
    ['test_name' => 'Strip Tensile Test', 'standard_code' => 'ASTM D4595', 'description' => 'Strip Tensile Test'],
    ['test_name' => 'Strip Tensile Test', 'standard_code' => 'ISO 10319', 'description' => 'Strip Tensile Test'],
    ['test_name' => 'CBR Puncture Resistance', 'standard_code' => 'ASTM D6241', 'description' => 'CBR Puncture Resistance Test'],
    ['test_name' => 'CBR Puncture Resistance', 'standard_code' => 'ISO 12236', 'description' => 'CBR Puncture Resistance Test'],
    ['test_name' => 'Grab Tensile Test', 'standard_code' => 'ASTM D4632', 'description' => 'Grab Tensile Test'],
    ['test_name' => 'Weathering Exposure Test', 'standard_code' => 'ASTM D4533', 'description' => 'Weathering Exposure Test'],
    ['test_name' => 'Seam/Joint Test', 'standard_code' => 'ISO 10321', 'description' => 'Seam/Joint Test'],
    ['test_name' => 'Fineness of Fiber', 'standard_code' => 'ISO 1973', 'description' => 'Fineness of Fiber Test'],
    ['test_name' => 'Cut Length of Fiber', 'standard_code' => 'ASTM D5103', 'description' => 'Cut Length of Fiber Test'],
    ['test_name' => 'Cut Length of Fiber', 'standard_code' => 'ASTM D5199', 'description' => 'Cut Length of Fiber Test'],
    ['test_name' => 'Tenacity of Fiber', 'standard_code' => 'ISO 5079', 'description' => 'Tenacity of Fiber Test'],
    ['test_name' => 'Tenacity of Yarn', 'standard_code' => 'ASTM D2256', 'description' => 'Tenacity of Yarn Test']
];

$added_count = 0;
$skipped_count = 0;

foreach ($required_standards as $standard) {
    // Check if it already exists
    $stmt = $conn->prepare("SELECT id FROM test_standards WHERE test_name = ? AND standard_code = ?");
    $stmt->bind_param("ss", $standard['test_name'], $standard['standard_code']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        echo "SKIP: " . $standard['test_name'] . " - " . $standard['standard_code'] . " (already exists)\n";
        $skipped_count++;
    } else {
        // Insert it
        $stmt = $conn->prepare("INSERT INTO test_standards (test_name, standard_code, description) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $standard['test_name'], $standard['standard_code'], $standard['description']);
        
        if ($stmt->execute()) {
            echo "ADDED: " . $standard['test_name'] . " - " . $standard['standard_code'] . "\n";
            $added_count++;
        } else {
            echo "ERROR: Failed to add " . $standard['test_name'] . " - " . $standard['standard_code'] . "\n";
        }
    }
    $stmt->close();
}

echo "\nSummary:\n";
echo "  Added: $added_count\n";
echo "  Skipped (already exists): $skipped_count\n";
echo "  Total required: " . count($required_standards) . "\n";

$conn->close();


