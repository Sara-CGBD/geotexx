<?php
session_start();
require_once '../../config/security_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$material_type = $_GET['material_type'] ?? '';

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
    
    $query = "SELECT COALESCE(SUM($amountColumn), 0) as available_quantity 
              FROM fiber_entries 
              WHERE LOWER(TRIM(material_type)) = LOWER(TRIM(?))";
    
    if ($hasIsDeleted) {
        $query .= " AND (is_deleted = 0 OR is_deleted IS NULL)";
    }
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    
    $stmt->bind_param("s", $material_type);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $available_quantity = $row['available_quantity'] ?? 0;
    
    $stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'available_quantity' => number_format($available_quantity, 2, '.', '')
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

