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
$fgEntries = [];

// Get first reference of each product from fg_entry
// Only fetch entries for bags (not rolls)
// Group by reference_number to get the first one, and get the amount (passed_qty for bags)
$fgQuery = "SELECT 
    reference_number,
    passed_qty as amount,
    product_type
FROM fg_entry 
WHERE reference_number IS NOT NULL 
  AND reference_number != ''
  AND is_deleted = 0
  AND product_type = 'bag'
GROUP BY reference_number, amount, product_type
ORDER BY reference_number DESC
LIMIT 100";

$fgResult = $conn->query($fgQuery);
if ($fgResult) {
    while ($row = $fgResult->fetch_assoc()) {
        $fgEntries[] = [
            'reference_number' => $row['reference_number'],
            'amount' => $row['amount'] ?? 0
        ];
    }
}
$conn->close();

echo json_encode(['success' => true, 'fgEntries' => $fgEntries]);
?>


