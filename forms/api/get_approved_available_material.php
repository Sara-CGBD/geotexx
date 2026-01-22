<?php
session_start();
require_once '../../config/security_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$manufacturer = $_GET['manufacturer'] ?? '';
$material_type = $_GET['material_type'] ?? '';

if (empty($manufacturer) || empty($material_type)) {
    echo json_encode(['success' => false, 'message' => 'Manufacturer and material type are required']);
    exit;
}

$conn = SecurityConfig::getConnection();

// Detect fiber_entries columns for usage sum (same logic as inventory_report.php)
$feCols = [];
$feColRes = $conn->query("SHOW COLUMNS FROM fiber_entries");
if ($feColRes) {
    while ($r = $feColRes->fetch_assoc()) {
        $feCols[] = strtolower($r['Field']);
    }
}
$amountCol = "amount_kg";
if (!in_array('amount_kg', $feCols, true)) {
    if (in_array('total_amount', $feCols, true)) {
        $amountCol = "total_amount";
    } elseif (in_array('amount', $feCols, true)) {
        $amountCol = "amount";
    } else {
        $amountCol = null;
    }
}
$feHasIsDeleted = in_array('is_deleted', $feCols, true);

// Detect reference column in fiber_entries
$refCol = null;
foreach (['reference', 'reference_number', 'entry_number', 'ref_number'] as $candidate) {
    if (in_array($candidate, $feCols, true)) {
        $refCol = $candidate;
        break;
    }
}
$usedSumExpr = ($amountCol && $refCol) ? "COALESCE(SUM(fe2.$amountCol), 0)" : "0";
$usedWhere = $feHasIsDeleted ? "AND fe2.is_deleted = 0" : "";

// Build used_amount projection safely (from fiber_entries)
if ($amountCol && $refCol) {
    $usedSubquery = "(SELECT $usedSumExpr 
         FROM fiber_entries fe2 
         WHERE fe2.$refCol COLLATE utf8mb4_unicode_ci = sre.entry_number 
         $usedWhere) as used_amount";
} else {
    $usedSubquery = "0 as used_amount";
}

// Query to get approved materials with original amounts and fiber usage
$query = "SELECT 
            sre.entry_number, 
            COALESCE(sre.original_amount_kg, sre.amount_kg) as original_amount,
            $usedSubquery
          FROM store_received_entries sre
          LEFT JOIN fineness_fiber_reports ff ON sre.entry_number COLLATE utf8mb4_unicode_ci = ff.store_entry_reference
          LEFT JOIN cut_length_fiber_reports cl ON sre.entry_number COLLATE utf8mb4_unicode_ci = cl.store_entry_reference
          LEFT JOIN tenacity_fiber_reports tf ON sre.entry_number COLLATE utf8mb4_unicode_ci = tf.store_entry_reference
          LEFT JOIN tenacity_yarn_reports ty ON sre.entry_number COLLATE utf8mb4_unicode_ci = ty.store_entry_reference
          LEFT JOIN fiber_test_reports ft ON sre.entry_number COLLATE utf8mb4_unicode_ci = ft.store_entry_reference
          LEFT JOIN sewing_thread_reports st ON sre.entry_number COLLATE utf8mb4_unicode_ci = st.store_entry_reference
          WHERE sre.manufacturer_name = ? 
          AND sre.material_type = ? 
          AND (
              -- For Fiber/PP materials: All 6 tests must be approved
              (sre.material_type LIKE '%Fiber%' OR sre.material_type LIKE '%PP%')
              AND ff.status = 'approved' 
              AND cl.status = 'approved' 
              AND tf.status = 'approved' 
              AND ty.status = 'approved' 
              AND ft.status = 'approved' 
              AND st.status = 'approved'
              OR
              -- For Thread/Sewing materials: Only sewing_thread_report must be approved
              (sre.material_type LIKE '%Thread%' OR sre.material_type LIKE '%Sewing%')
              AND st.status = 'approved'
          )
          GROUP BY sre.entry_number, sre.original_amount_kg, sre.amount_kg";

$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $manufacturer, $material_type);
$stmt->execute();
$result = $stmt->get_result();

// Pre-fetch all deduction details from material issue entries
$allDeductions = [];
$issueTableCheck = $conn->query("SHOW TABLES LIKE 'store_issue_entries'");
if ($issueTableCheck && $issueTableCheck->num_rows > 0) {
    $deductionQuery = "SELECT deduction_details 
                      FROM store_issue_entries 
                      WHERE deduction_details IS NOT NULL 
                      AND deduction_details != '' 
                      AND deduction_details != 'null'";
    $deductionResult = $conn->query($deductionQuery);
    if ($deductionResult) {
        while ($deductionRow = $deductionResult->fetch_assoc()) {
            $deductionDetails = json_decode($deductionRow['deduction_details'], true);
            if (is_array($deductionDetails)) {
                foreach ($deductionDetails as $entryNumber => $deductedAmount) {
                    if (!isset($allDeductions[$entryNumber])) {
                        $allDeductions[$entryNumber] = 0;
                    }
                    $allDeductions[$entryNumber] += floatval($deductedAmount);
                }
            }
        }
    }
}

// Calculate total available: original - (fiber usage + issue deductions)
$total_available = 0;
while ($row = $result->fetch_assoc()) {
    $entryNumber = $row['entry_number'];
    $originalAmount = floatval($row['original_amount'] ?? 0);
    $usageFromFibers = floatval($row['used_amount'] ?? 0);
    $deductedAmount = isset($allDeductions[$entryNumber]) ? $allDeductions[$entryNumber] : 0;
    
    $totalUsed = $usageFromFibers + $deductedAmount;
    $remainingAmount = max(0, $originalAmount - $totalUsed);
    
    $total_available += $remainingAmount;
}

$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'available_amount' => number_format($total_available, 2, '.', '')
]);
?>

