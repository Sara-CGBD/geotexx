<?php
header('Content-Type: application/json');
session_start();
require_once '../../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn = SecurityConfig::getConnection();
$referenceNumbers = [];
$refQuery = "SELECT DISTINCT reference_number FROM roll_entry WHERE reference_number IS NOT NULL ORDER BY reference_number DESC LIMIT 100";
$refResult = $conn->query($refQuery);
if ($refResult) {
    while ($row = $refResult->fetch_assoc()) {
        $referenceNumbers[] = $row['reference_number'];
    }
}
$conn->close();

echo json_encode(['success' => true, 'references' => $referenceNumbers]);
?>


