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

// Check if routed table exists
$routedTableExists = $conn->query("SHOW TABLES LIKE 'routed'")->num_rows > 0;

// Helper function to check if a reference has been routed
$isReferenceRouted = function($conn, $reference, $routedTableExists) {
    if (!$routedTableExists) {
        return false;
    }
    
    // Check exact match first
    $stmt = $conn->prepare("SELECT 1 FROM routed WHERE reference_number = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $result = $stmt->get_result();
        $routed = ($result && $result->num_rows > 0);
        $stmt->close();
        if ($routed) return true;
    }
    
    // Check prefix match (stored ref is prefix of query ref)
    $stmt = $conn->prepare("SELECT 1 FROM routed WHERE ? LIKE CONCAT(reference_number, '%') LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $result = $stmt->get_result();
        $routed = ($result && $result->num_rows > 0);
        $stmt->close();
        if ($routed) return true;
    }
    
    // Check prefix match (query ref is prefix of stored ref)
    $stmt = $conn->prepare("SELECT 1 FROM routed WHERE reference_number LIKE CONCAT(?, '%') LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $reference);
        $stmt->execute();
        $result = $stmt->get_result();
        $routed = ($result && $result->num_rows > 0);
        $stmt->close();
        return $routed;
    }
    
    return false;
};

$refQuery = "SELECT DISTINCT reference_number FROM roll_entry WHERE reference_number IS NOT NULL ORDER BY reference_number DESC LIMIT 100";
$refResult = $conn->query($refQuery);
if ($refResult) {
    while ($row = $refResult->fetch_assoc()) {
        $ref = $row['reference_number'];
        
        // Skip if reference has been routed by AGM
        if (!$isReferenceRouted($conn, $ref, $routedTableExists)) {
            $referenceNumbers[] = $ref;
        }
    }
}
$conn->close();

echo json_encode(['success' => true, 'references' => $referenceNumbers]);
?>


