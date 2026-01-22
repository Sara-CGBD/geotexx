<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit;
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_entry'])) {
    try {
        $date_time = trim($_POST['dateTime'] ?? '');
        $shift = trim($_POST['shift'] ?? '');
        $manufacturer_name = trim($_POST['manufacturerName'] ?? '');
        $material_type = trim($_POST['materialType'] ?? '');
        $amount_kg = floatval($_POST['amountKg'] ?? 0);
        $material_request_number = trim($_POST['materialRequestNumber'] ?? '');
        $reported_by = $_SESSION['full_name'] ?? $_SESSION['username'];
        $reporter_id = $_SESSION['user_id'];

        // Validation
        if (empty($date_time)) {
            throw new Exception("Date and time is required.");
        }
        if (empty($shift)) {
            throw new Exception("Shift is required.");
        }
        if (empty($manufacturer_name)) {
            throw new Exception("Manufacturer name is required.");
        }
        if (empty($material_type)) {
            throw new Exception("Material type is required.");
        }
        if ($amount_kg <= 0) {
            throw new Exception("Amount must be greater than 0 kg.");
        }

        // Check available approved amount using same calculation as API
        // Calculate: original_amount - (fiber_usage + issue_deductions) = remaining
        // Detect fiber_entries columns for usage sum
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
        
        // Query to get approved materials with original amounts and fiber usage
        $checkQuery = "SELECT 
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
        
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("ss", $manufacturer_name, $material_type);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        
        // Calculate total available: original - (fiber usage + issue deductions)
        $total_available = 0;
        while ($row = $checkResult->fetch_assoc()) {
            $entryNumber = $row['entry_number'];
            $originalAmount = floatval($row['original_amount'] ?? 0);
            $usageFromFibers = floatval($row['used_amount'] ?? 0);
            $deductedAmount = isset($allDeductions[$entryNumber]) ? $allDeductions[$entryNumber] : 0;
            
            $totalUsed = $usageFromFibers + $deductedAmount;
            $remainingAmount = max(0, $originalAmount - $totalUsed);
            
            $total_available += $remainingAmount;
        }
        $checkStmt->close();

        if ($amount_kg > $total_available) {
            throw new Exception("Amount cannot exceed available approved amount of " . number_format($total_available, 2) . " kg.");
        }

        // Generate issue number
        $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $hour = (int)$now->format('H');
        if ($hour < 8) {
            $now->modify('-1 day');
        }
        $dateKey = $now->format('Ymd');
        $pattern = 'MIE-' . $dateKey . '-%';
        $countStmt = $conn->prepare("SELECT COUNT(*) as entry_count FROM store_issue_entries WHERE issue_number LIKE ?");
        $countStmt->bind_param("s", $pattern);
        $countStmt->execute();
        $result = $countStmt->get_result();
        $counter = 1;
        if ($result && $row = $result->fetch_assoc()) {
            $counter = (int)$row['entry_count'] + 1;
        }
        $countStmt->close();
        $issue_number = sprintf("MIE-%s-%03d", $dateKey, $counter);

        // Start transaction
        $conn->begin_transaction();

        try {
            // Insert issue entry (without store_entry_reference, will be set during deduction)
            $stmt = $conn->prepare("INSERT INTO store_issue_entries 
                (issue_number, date_time, shift, manufacturer_name, material_type, amount_kg, material_request_number, reported_by, reporter_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->bind_param("sssssdssi", $issue_number, $date_time, $shift, $manufacturer_name, $material_type, $amount_kg, $material_request_number, $reported_by, $reporter_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to save issue entry: " . $stmt->error);
            }
            $stmt->close();

            // Deduct from approved store entries (FIFO - First In First Out)
            // Only from AGM approved materials that are in inventory
            $remaining_to_deduct = $amount_kg;
            $deductQuery = "SELECT sre.id, sre.entry_number, sre.amount_kg 
                           FROM store_received_entries sre
                           LEFT JOIN fineness_fiber_reports ff ON sre.entry_number COLLATE utf8mb4_unicode_ci = ff.store_entry_reference
                           LEFT JOIN cut_length_fiber_reports cl ON sre.entry_number COLLATE utf8mb4_unicode_ci = cl.store_entry_reference
                           LEFT JOIN tenacity_fiber_reports tf ON sre.entry_number COLLATE utf8mb4_unicode_ci = tf.store_entry_reference
                           LEFT JOIN tenacity_yarn_reports ty ON sre.entry_number COLLATE utf8mb4_unicode_ci = ty.store_entry_reference
                           LEFT JOIN fiber_test_reports ft ON sre.entry_number COLLATE utf8mb4_unicode_ci = ft.store_entry_reference
                           LEFT JOIN sewing_thread_reports st ON sre.entry_number COLLATE utf8mb4_unicode_ci = st.store_entry_reference
                           WHERE sre.manufacturer_name = ? 
                           AND sre.material_type = ? 
                           AND sre.amount_kg > 0
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
                           ORDER BY sre.date_time ASC, sre.created_at ASC";
            $deductStmt = $conn->prepare($deductQuery);
            $deductStmt->bind_param("ss", $manufacturer_name, $material_type);
            $deductStmt->execute();
            $deductResult = $deductStmt->get_result();

            // Track all deductions for proper restoration on deletion
            $store_entry_references = [];
            $deduction_details = []; // Store entry_number => deducted_amount
            
            while ($remaining_to_deduct > 0 && $row = $deductResult->fetch_assoc()) {
                $entry_id = $row['id'];
                $entry_number = $row['entry_number'];
                $available = floatval($row['amount_kg']);
                
                $deduct_amount = min($remaining_to_deduct, $available);
                
                $updateStmt = $conn->prepare("UPDATE store_received_entries 
                                              SET amount_kg = amount_kg - ? 
                                              WHERE id = ?");
                $updateStmt->bind_param("di", $deduct_amount, $entry_id);
                $updateStmt->execute();
                $updateStmt->close();
                
                $store_entry_references[] = $entry_number;
                $deduction_details[$entry_number] = $deduct_amount; // Track how much was deducted from each
                $remaining_to_deduct -= $deduct_amount;
            }
            $deductStmt->close();
            
            // Update issue entry with first store entry reference and all deductions as JSON
            if (!empty($store_entry_references)) {
                $deductions_json = json_encode($deduction_details); // Store all deductions
                $updateIssueStmt = $conn->prepare("UPDATE store_issue_entries 
                                                   SET store_entry_reference = ?, 
                                                       deduction_details = ? 
                                                   WHERE issue_number = ?");
                $updateIssueStmt->bind_param("sss", $store_entry_references[0], $deductions_json, $issue_number);
                $updateIssueStmt->execute();
                $updateIssueStmt->close();
            }

            $conn->commit();
            
            // If this was from a material request, redirect to material request list, otherwise back to form
            if (!empty($material_request_number)) {
                header("Location: ../reports/material_request_list.php?success=" . urlencode("Material has been issued successfully! Issue Number: " . $issue_number));
            } else {
                header("Location: ../forms/material_issue_entry.php?success=" . urlencode("Material issue entry saved successfully! Issue Number: " . $issue_number));
            }
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
        header("Location: ../forms/material_issue_entry.php?error=" . urlencode($error));
        exit;
    }
} else {
    header("Location: ../forms/material_issue_entry.php");
    exit;
}
?>

