<?php
session_start();
require_once '../../config/security_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$material_type = $_GET['material_type'] ?? '';
$manufacturer_name = $_GET['manufacturer_name'] ?? '';

if (empty($material_type)) {
    echo json_encode(['success' => false, 'error' => 'Material type is required']);
    exit;
}

try {
    $conn = SecurityConfig::getConnection();
    
    // Get available amount from fiber_entries
    // Available = sum of amount_kg (or amount or total_amount depending on column name) for matching material_type
    // Check which column exists
    $colCheck = $conn->query("SHOW COLUMNS FROM fiber_entries LIKE 'amount_kg'");
    $hasAmountKg = $colCheck && $colCheck->num_rows > 0;
    
    if (!$hasAmountKg) {
        $colCheck = $conn->query("SHOW COLUMNS FROM fiber_entries LIKE 'amount'");
        $hasAmount = $colCheck && $colCheck->num_rows > 0;
        
        if (!$hasAmount) {
            $colCheck = $conn->query("SHOW COLUMNS FROM fiber_entries LIKE 'total_amount'");
            $hasTotalAmount = $colCheck && $colCheck->num_rows > 0;
            
            if (!$hasTotalAmount) {
                echo json_encode(['success' => false, 'error' => 'No amount column found in fiber_entries']);
                exit;
            } else {
                $amountColumn = 'total_amount';
            }
        } else {
            $amountColumn = 'amount';
        }
    } else {
        $amountColumn = 'amount_kg';
    }
    
    // Check if is_deleted column exists
    $colCheck = $conn->query("SHOW COLUMNS FROM fiber_entries LIKE 'is_deleted'");
    $hasIsDeleted = $colCheck && $colCheck->num_rows > 0;
    
    // Check if manufacturer_name column exists
    $colCheck = $conn->query("SHOW COLUMNS FROM fiber_entries LIKE 'manufacturer_name'");
    $hasManufacturer = $colCheck && $colCheck->num_rows > 0;
    
    // Calculate remaining weight = (received in fiber_entries) - (used in fiber_to_roll_entry)
    // Build query for received amount from fiber_entries
    $receivedQuery = "SELECT COALESCE(SUM($amountColumn), 0) as received_quantity 
                      FROM fiber_entries 
                      WHERE LOWER(TRIM(material_type)) = LOWER(TRIM(?))";
    
    if ($hasManufacturer && !empty($manufacturer_name)) {
        $receivedQuery .= " AND LOWER(TRIM(manufacturer_name)) = LOWER(TRIM(?))";
    }
    
    if ($hasIsDeleted) {
        $receivedQuery .= " AND (is_deleted = 0 OR is_deleted IS NULL)";
    }
    
    // Build query for used amount from fiber_to_roll_entry
    $colCheck = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'material_type'");
    $hasFtrMaterialType = $colCheck && $colCheck->num_rows > 0;
    
    $colCheck = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'manufacturer_name'");
    $hasFtrManufacturer = $colCheck && $colCheck->num_rows > 0;
    
    $colCheck = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'total_weight'");
    $hasFtrTotalWeight = $colCheck && $colCheck->num_rows > 0;
    
    $usedQuery = "SELECT COALESCE(SUM(total_weight), 0) as used_quantity 
                  FROM fiber_to_roll_entry 
                  WHERE LOWER(TRIM(material_type)) = LOWER(TRIM(?))";
    
    if ($hasFtrManufacturer && !empty($manufacturer_name)) {
        $usedQuery .= " AND LOWER(TRIM(manufacturer_name)) = LOWER(TRIM(?))";
    }
    
    // Get received quantity
    $stmt = $conn->prepare($receivedQuery);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    
    if ($hasManufacturer && !empty($manufacturer_name)) {
        $stmt->bind_param("ss", $material_type, $manufacturer_name);
    } else {
        $stmt->bind_param("s", $material_type);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $received_quantity = (float)($row['received_quantity'] ?? 0);
    $stmt->close();
    
    // Get used quantity
    $used_quantity = 0;
    if ($hasFtrMaterialType && $hasFtrTotalWeight) {
        $stmt2 = $conn->prepare($usedQuery);
        if ($stmt2) {
            if ($hasFtrManufacturer && !empty($manufacturer_name)) {
                $stmt2->bind_param("ss", $material_type, $manufacturer_name);
            } else {
                $stmt2->bind_param("s", $material_type);
            }
            $stmt2->execute();
            $result2 = $stmt2->get_result();
            $row2 = $result2->fetch_assoc();
            $used_quantity = (float)($row2['used_quantity'] ?? 0);
            $stmt2->close();
        }
    }
    
    // Calculate remaining quantity
    $remaining_quantity = max(0, $received_quantity - $used_quantity);
    
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'available_quantity' => number_format($remaining_quantity, 2, '.', ''),
        'received_quantity' => number_format($received_quantity, 2, '.', ''),
        'used_quantity' => number_format($used_quantity, 2, '.', ''),
        'remaining_quantity' => number_format($remaining_quantity, 2, '.', '')
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

