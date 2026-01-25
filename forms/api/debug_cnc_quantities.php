<?php
// Debug script to check stored reference quantities
session_start();
require_once '../../config/security_config.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

$conn = SecurityConfig::getConnection();
$reference = $_GET['reference'] ?? '';

if (empty($reference)) {
    die('Please provide ?reference=REFERENCE_NUMBER');
}

echo "<h2>Debug: Reference Quantities for: " . htmlspecialchars($reference) . "</h2>";

// Check if column exists
$colCheck = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'reference_quantities'");
if (!$colCheck || $colCheck->num_rows === 0) {
    die("<p style='color:red;'>ERROR: reference_quantities column does not exist in cnc_entries table!</p>");
}

// Get all entries with this reference
$refEscaped = $conn->real_escape_string($reference);
$query = "SELECT cnc_id, reference_number, cutting_roll_quantity, reference_quantities, date_time
          FROM cnc_entries 
          WHERE (FIND_IN_SET('$refEscaped', reference_number) > 0 OR reference_number = '$refEscaped')
          ORDER BY date_time DESC";

$result = $conn->query($query);

echo "<table border='1' cellpadding='10' style='border-collapse:collapse;'>";
echo "<tr><th>CNC ID</th><th>Reference Number</th><th>Total Cutting Qty</th><th>Reference Quantities (JSON)</th><th>Date</th></tr>";

$totalUsed = 0;
if ($result) {
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['cnc_id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['reference_number']) . "</td>";
        echo "<td>" . htmlspecialchars($row['cutting_roll_quantity']) . "</td>";
        
        $refQuantities = $row['reference_quantities'];
        if (!empty($refQuantities)) {
            $json = json_decode($refQuantities, true);
            if (is_array($json)) {
                echo "<td><pre>" . htmlspecialchars(json_encode($json, JSON_PRETTY_PRINT)) . "</pre></td>";
                // Check if our reference is in the JSON
                $found = false;
                foreach ($json as $storedRef => $qty) {
                    if (strcasecmp(trim($storedRef), trim($reference)) === 0) {
                        $totalUsed += (int)$qty;
                        $found = true;
                        echo "<td style='background:#90EE90;'>✓ Found: " . $qty . " pieces</td>";
                        break;
                    }
                }
                if (!$found) {
                    echo "<td style='background:#FFB6C1;'>✗ Reference not found in JSON</td>";
                }
            } else {
                echo "<td style='color:red;'>Invalid JSON</td>";
            }
        } else {
            echo "<td style='color:orange;'>Empty (old entry, no per-reference tracking)</td>";
        }
        
        echo "<td>" . htmlspecialchars($row['date_time']) . "</td>";
        echo "</tr>";
    }
}

echo "</table>";

echo "<h3>Total Used Quantity for this reference: <strong>" . $totalUsed . " pieces</strong></h3>";

// Check product_amount from roll_qc_reports
$qcQuery = "SELECT product_amount FROM roll_qc_reports 
            WHERE reference_number = '$refEscaped' 
            ORDER BY created_at DESC LIMIT 1";
$qcResult = $conn->query($qcQuery);
if ($qcResult && $qcRow = $qcResult->fetch_assoc()) {
    $productAmount = (float)$qcRow['product_amount'];
    $remaining = max(0, floor($productAmount) - $totalUsed);
    echo "<h3>QC Report Data:</h3>";
    echo "<p>Product Amount: <strong>" . $productAmount . " pieces</strong></p>";
    echo "<p>Used: <strong>" . $totalUsed . " pieces</strong></p>";
    echo "<p>Remaining: <strong>" . $remaining . " pieces</strong></p>";
    if ($remaining <= 0) {
        echo "<p style='color:red; font-weight:bold;'>This reference should be FILTERED OUT (no remaining quantity)</p>";
    } else {
        echo "<p style='color:green; font-weight:bold;'>This reference should be SHOWN (has remaining quantity)</p>";
    }
} else {
    echo "<p style='color:orange;'>No QC report found for this reference</p>";
}

$conn->close();
?>
