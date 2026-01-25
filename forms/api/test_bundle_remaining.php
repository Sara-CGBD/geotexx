<?php
// Test script to debug bundle remaining quantity calculation
session_start();
require_once '../../config/security_config.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}

$conn = SecurityConfig::getConnection();
$reference = $_GET['reference'] ?? '';

if (empty($reference)) {
    die('Please provide ?reference=BUNDLE_REFERENCE');
}

echo "<h2>Debug: Bundle Remaining Calculation for: " . htmlspecialchars($reference) . "</h2>";

// Check if it's a bundle
$isBundle = preg_match('/^(.+)-(\d+)-(\d+)$/', $reference, $matches);
if (!$isBundle) {
    die("<p>This is not a bundle reference. Bundles should be in format: REF-1-4</p>");
}

$baseRef = $matches[1];
$startNum = (int)$matches[2];
$endNum = (int)$matches[3];

echo "<p><strong>Bundle Details:</strong></p>";
echo "<p>Base Reference: " . htmlspecialchars($baseRef) . "</p>";
echo "<p>Roll Range: " . $startNum . " to " . $endNum . "</p>";
echo "<p>Total Rolls: " . ($endNum - $startNum + 1) . "</p>";

$maxPerRoll = 36;
$bundleTotalRemaining = 0;

echo "<table border='1' cellpadding='10' style='border-collapse:collapse; margin-top:20px;'>";
echo "<tr><th>Roll #</th><th>Reference</th><th>Used Qty</th><th>Product Amount</th><th>Roll Remaining</th><th>Cumulative Total</th></tr>";

for ($rollNum = $startNum; $rollNum <= $endNum; $rollNum++) {
    $individualRef = $baseRef . '-' . $rollNum;
    $individualRefEscaped = $conn->real_escape_string($individualRef);
    
    // Get used quantity
    $usedQty = 0;
    $hasRefQuantities = false;
    $refQtyColCheck = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'reference_quantities'");
    if ($refQtyColCheck && $refQtyColCheck->num_rows > 0) {
        $hasRefQuantities = true;
    }
    
    if ($hasRefQuantities) {
        $usedQuery = "SELECT reference_quantities
                     FROM cnc_entries c
                     WHERE (FIND_IN_SET('$individualRefEscaped', c.reference_number) > 0
                            OR c.reference_number = '$individualRefEscaped')
                     AND c.reference_quantities IS NOT NULL
                     AND c.reference_quantities != ''
                     AND (c.is_deleted = 0 OR c.is_deleted IS NULL)";
        
        $usedResult = $conn->query($usedQuery);
        if ($usedResult) {
            while ($usedRow = $usedResult->fetch_assoc()) {
                $refQuantitiesJson = $usedRow['reference_quantities'];
                if (!empty($refQuantitiesJson)) {
                    $refQuantities = json_decode($refQuantitiesJson, true);
                    if (is_array($refQuantities)) {
                        foreach ($refQuantities as $storedRef => $qty) {
                            if (strcasecmp(trim($storedRef), trim($individualRef)) === 0) {
                                $usedQty += (int)$qty;
                                break;
                            }
                        }
                    }
                }
            }
        }
    }
    
    // Get product amount
    $productAmount = 0;
    $hasQcReport = false;
    $qcQuery = "SELECT COALESCE(product_amount, 0) as product_amount
                FROM roll_qc_reports rqc
                WHERE rqc.reference_number = '$individualRefEscaped'
                AND (rqc.approved = 1 OR rqc.overall_status IN ('approved', 'Done'))
                ORDER BY rqc.created_at DESC
                LIMIT 1";
    $qcResult = $conn->query($qcQuery);
    if ($qcResult && $qcRow = $qcResult->fetch_assoc()) {
        $productAmount = (float)$qcRow['product_amount'];
        $hasQcReport = true;
    }
    
    // Calculate roll remaining
    $rollRemaining = 0;
    if ($usedQty >= $maxPerRoll) {
        $rollRemaining = 0;
    } else if ($hasQcReport && $productAmount > 0) {
        $rollRemaining = max(0, floor($productAmount) - $usedQty);
        $rollRemaining = min($rollRemaining, $maxPerRoll);
    } else {
        $rollRemaining = max(0, $maxPerRoll - $usedQty);
    }
    
    $bundleTotalRemaining += $rollRemaining;
    
    echo "<tr>";
    echo "<td>" . $rollNum . "</td>";
    echo "<td>" . htmlspecialchars($individualRef) . "</td>";
    echo "<td>" . $usedQty . "</td>";
    echo "<td>" . ($hasQcReport ? $productAmount : 'No QC data') . "</td>";
    echo "<td><strong>" . $rollRemaining . "</strong></td>";
    echo "<td><strong>" . $bundleTotalRemaining . "</strong></td>";
    echo "</tr>";
}

echo "</table>";

echo "<h3 style='color:green;'>Bundle Total Remaining: <strong>" . $bundleTotalRemaining . " pieces</strong></h3>";
echo "<p>Expected for " . ($endNum - $startNum + 1) . " unused rolls: " . ($maxPerRoll * ($endNum - $startNum + 1)) . " pieces</p>";

$conn->close();
?>
