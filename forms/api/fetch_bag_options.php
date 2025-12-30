<?php
header('Content-Type: application/json');

require_once '../../config/security_config.php';

$bagSize = $_GET['bag_size'] ?? '';

if (!$bagSize) {
    echo json_encode(['gsmList' => [], 'hasThickness' => false]);
    exit;
}

$conn = SecurityConfig::getConnection();

// Get distinct GSM values for this bag size
$gsmList = [];
$stmt = $conn->prepare("SELECT DISTINCT gsm FROM bag_size_master WHERE bag_size = ? ORDER BY gsm");
$stmt->bind_param('s', $bagSize);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $gsmList[] = $row['gsm'];
}
$stmt->close();

// Check if this bag size has different thickness values
$thicknessCount = 0;
$stmt = $conn->prepare("SELECT COUNT(DISTINCT thickness) as count FROM bag_size_master WHERE bag_size = ?");
$stmt->bind_param('s', $bagSize);
$stmt->execute();
$result = $stmt->get_result();
if ($row = $result->fetch_assoc()) {
    $thicknessCount = $row['count'];
}
$stmt->close();

echo json_encode([
    'gsmList' => $gsmList,
    'hasThickness' => $thicknessCount > 1
]);
?>



