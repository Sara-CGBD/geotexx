<?php
session_start();
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display, but log

try {
    require_once '../../config/security_config.php';
    
    // Session validation
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        echo json_encode(['success' => false, 'error' => 'Session not found. Please refresh the page.']);
        exit;
    }

    date_default_timezone_set('Asia/Dhaka');
    $conn = SecurityConfig::getConnection();
    
    // Get material type from query
    $materialType = $_GET['material_type'] ?? '';
    
    if (empty($materialType)) {
        echo json_encode(['success' => false, 'error' => 'Material type is required']);
        exit;
    }
    
    // Schema helpers
    $colExists = function(mysqli $conn, string $table, string $column): bool {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
        $col = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
        return $res && $res->num_rows > 0;
    };

    $amountCol = 'amount_kg';
    if (!$colExists($conn, 'fiber_entries', 'amount_kg')) {
        if ($colExists($conn, 'fiber_entries', 'amount')) $amountCol = 'amount';
        elseif ($colExists($conn, 'fiber_entries', 'total_amount')) $amountCol = 'total_amount';
        else $amountCol = '0';
    }
    $isDeletedClause = $colExists($conn, 'fiber_entries', 'is_deleted') ? "AND fe.is_deleted = 0" : "";
    $totalWeightCol = $colExists($conn, 'fiber_to_roll_entry', 'total_weight') ? 'ftr.total_weight' : '0';

    // Calculate available quantity from fiber_entries minus fiber_to_roll usage
    $query = "
        SELECT 
            COALESCE(SUM({$amountCol}), 0) - COALESCE(
                (SELECT SUM({$totalWeightCol}) 
                 FROM fiber_to_roll_entry ftr 
                 WHERE ftr.material_type = ?), 
            0) as available_quantity
        FROM fiber_entries fe
        WHERE fe.material_type = ?
        {$isDeletedClause}
    ";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param('ss', $materialType, $materialType);
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $availableQuantity = max(0, (float)$row['available_quantity']); // Ensure non-negative
        
        echo json_encode([
            'success' => true,
            'material_type' => $materialType,
            'available_quantity' => $availableQuantity
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'material_type' => $materialType,
            'available_quantity' => 0
        ]);
    }
    
    $stmt->close();
    $conn->close();
    
} catch (Exception $e) {
    error_log("Get available material error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>


