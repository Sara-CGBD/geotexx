<?php
session_start();
header('Content-Type: application/json');

require_once '../../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    echo json_encode(['weight' => null, 'error' => 'Unauthorized']);
    exit;
}

$bagSize = trim($_GET['bag_size'] ?? '');
$gsm = isset($_GET['gsm']) ? floatval($_GET['gsm']) : 0;
$thickness = isset($_GET['thickness']) ? floatval($_GET['thickness']) : 0;

if ($bagSize === '') {
    echo json_encode(['weight' => null]);
    exit;
}

$conn = SecurityConfig::getConnection();
if (!$conn) {
    echo json_encode(['weight' => null]);
    exit;
}

// Try to find matching entry
if ($gsm > 0 && $thickness > 0) {
    // Both GSM and thickness provided
    $stmt = $conn->prepare("SELECT bag_capacity FROM bag_size_master WHERE bag_size = ? AND gsm = ? AND thickness = ? LIMIT 1");
    $stmt->bind_param('sdd', $bagSize, $gsm, $thickness);
} elseif ($gsm > 0) {
    // Only GSM provided
    $stmt = $conn->prepare("SELECT bag_capacity FROM bag_size_master WHERE bag_size = ? AND gsm = ? LIMIT 1");
    $stmt->bind_param('sd', $bagSize, $gsm);
} else {
    // Just bag size
    $stmt = $conn->prepare("SELECT bag_capacity FROM bag_size_master WHERE bag_size = ? LIMIT 1");
    $stmt->bind_param('s', $bagSize);
}

$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if ($row) {
    // Extract the number from "XXX kg" format
    $capacity = $row['bag_capacity'];
    $weight = preg_replace('/[^0-9.]/', '', $capacity);
    echo json_encode(['weight' => floatval($weight)]);
} else {
    echo json_encode(['weight' => null]);
}

$stmt->close();
?>



