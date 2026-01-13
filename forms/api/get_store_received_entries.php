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

$conn = SecurityConfig::getConnection();

// Build query to fetch store received entries with available stock
$query = "SELECT entry_number, manufacturer_name, material_type, amount_kg, date_time 
          FROM store_received_entries 
          WHERE amount_kg > 0";

$params = [];
$types = '';

if (!empty($manufacturer)) {
    $query .= " AND manufacturer_name = ?";
    $params[] = $manufacturer;
    $types .= 's';
}

if (!empty($material_type)) {
    $query .= " AND material_type = ?";
    $params[] = $material_type;
    $types .= 's';
}

$query .= " ORDER BY date_time ASC, created_at ASC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$entries = [];
while ($row = $result->fetch_assoc()) {
    $entries[] = [
        'entry_number' => $row['entry_number'],
        'manufacturer_name' => $row['manufacturer_name'],
        'material_type' => $row['material_type'],
        'amount_kg' => number_format($row['amount_kg'], 2, '.', ''),
        'date_time' => $row['date_time']
    ];
}

$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'entries' => $entries
]);
?>

