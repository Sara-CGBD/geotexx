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


$query = "SELECT sre.entry_number, sre.amount_kg
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
          GROUP BY sre.entry_number, sre.amount_kg";

$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $manufacturer, $material_type);
$stmt->execute();
$result = $stmt->get_result();

$total_available = 0;
while ($row = $result->fetch_assoc()) {
    $total_available += floatval($row['amount_kg']);
}

$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'available_amount' => number_format($total_available, 2, '.', '')
]);
?>

