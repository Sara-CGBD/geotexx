<?php
session_start();
require_once '../../config/security_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$entry_id = $_GET['entry_id'] ?? '';
if (empty($entry_id)) {
    echo json_encode(['success' => false, 'message' => 'Missing entry ID']);
    exit();
}

$conn = SecurityConfig::getConnection();
$userId = $_SESSION['user_id'];

// Fetch all rows for this entry_id (only if belongs to current user)
$stmt = $conn->prepare("SELECT * FROM length_calibrations WHERE entry_id = ? AND user_id = ? AND status = 'rejected' ORDER BY id");
$stmt->bind_param("si", $entry_id, $userId);
$stmt->execute();
$result = $stmt->get_result();

$calibrations = [];
while ($row = $result->fetch_assoc()) {
    $calibrations[] = $row;
}

$stmt->close();
$conn->close();

if (count($calibrations) > 0) {
    echo json_encode(['success' => true, 'data' => $calibrations]);
} else {
    echo json_encode(['success' => false, 'message' => 'Entry not found or access denied']);
}
?>


