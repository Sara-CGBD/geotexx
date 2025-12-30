<?php
session_start();
require_once 'security_config.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$conn = SecurityConfig::getConnection();

// Get today's date
$today = date('Y-m-d');

// Count total test orders for today
$stmt = $conn->prepare("SELECT COUNT(*) as count FROM qc_test_orders WHERE DATE(created_at) = ?");
$stmt->bind_param("s", $today);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

// Next lab test number is count + 1
$next_number = ($row['count'] ?? 0) + 1;

// Format with leading zeros
if ($next_number > 99) {
    $lab_test_number = str_pad($next_number, 3, '0', STR_PAD_LEFT);
} else {
    $lab_test_number = str_pad($next_number, 2, '0', STR_PAD_LEFT);
}

echo json_encode([
    'lab_test_number' => $lab_test_number,
    'count' => $row['count'],
    'date' => $today
]);
?>


