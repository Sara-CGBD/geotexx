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

        // Check available approved amount (only from AGM approved materials in inventory)
        // For Fiber/PP: All 6 tests must be approved
        // For Thread/Sewing: Only sewing_thread_report must be approved
        $checkQuery = "SELECT COALESCE(SUM(sre.amount_kg), 0) as available_amount 
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
                       )";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("ss", $manufacturer_name, $material_type);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $total_available = 0;
        while ($row = $checkResult->fetch_assoc()) {
            $total_available += floatval($row['available_amount']);
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
                (issue_number, date_time, shift, manufacturer_name, material_type, amount_kg, reported_by, reporter_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->bind_param("sssssdsi", $issue_number, $date_time, $shift, $manufacturer_name, $material_type, $amount_kg, $reported_by, $reporter_id);
            
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
            header("Location: ../forms/material_issue_entry.php?success=" . urlencode("Material issue entry saved successfully! Issue Number: " . $issue_number));
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

