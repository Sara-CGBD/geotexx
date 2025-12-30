<?php
header('Content-Type: application/json');

require_once '../../config/security_config.php';

$bagSize = $_GET['bag_size'] ?? '';
$gsm = isset($_GET['gsm']) ? floatval($_GET['gsm']) : 0;

if (!$bagSize) {
    echo json_encode(['thicknessList' => []]);
    exit;
}

$conn = SecurityConfig::getConnection();

// Get distinct thickness values for this bag size and GSM
$thicknessList = [];
if ($gsm > 0) {
    $stmt = $conn->prepare("SELECT DISTINCT thickness FROM bag_size_master WHERE bag_size = ? AND gsm = ? ORDER BY thickness");
    $stmt->bind_param('sd', $bagSize, $gsm);
} else {
    $stmt = $conn->prepare("SELECT DISTINCT thickness FROM bag_size_master WHERE bag_size = ? ORDER BY thickness");
    $stmt->bind_param('s', $bagSize);
}

$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $thicknessList[] = $row['thickness'];
}
$stmt->close();

echo json_encode(['thicknessList' => $thicknessList]);
?>



