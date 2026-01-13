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

// Get available amount from store_received_entries
// Available = remaining amount_kg for matching manufacturer and material_type
$query = "SELECT COALESCE(SUM(amount_kg), 0) as available_amount 
          FROM store_received_entries 
          WHERE manufacturer_name = ? 
          AND material_type = ? 
          AND amount_kg > 0";
    
    $stmt = $conn->prepare($query);
$stmt->bind_param("ss", $manufacturer, $material_type);
$stmt->execute();
    $result = $stmt->get_result();
$row = $result->fetch_assoc();

$available_amount = $row['available_amount'] ?? 0;
    
    $stmt->close();
    $conn->close();
    
echo json_encode([
    'success' => true,
    'available_amount' => number_format($available_amount, 2, '.', '')
]);
?>
