<?php
session_start();
require_once '../../config/security_config.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Role-based access control - Admin only
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if ($user_role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin role required.']);
    exit();
}

try {
    $conn = SecurityConfig::getConnection();
    
    // GET: Fetch all product prices
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $query = "SELECT id, product_name, unit_price FROM fg ORDER BY product_name";
        $result = $conn->query($query);
        
        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = [
                'id' => (int)$row['id'],
                'product_name' => $row['product_name'],
                'unit_price' => (float)$row['unit_price']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $products,
            'total' => count($products)
        ]);
    }
    
    // POST: Update product prices
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['prices']) || !is_array($input['prices'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid input. Expected prices array.']);
            exit();
        }
        
        $success_count = 0;
        $errors = [];
        
        $conn->begin_transaction();
        
        try {
            $stmt = $conn->prepare("UPDATE fg SET unit_price = ? WHERE id = ?");
            
            foreach ($input['prices'] as $price_data) {
                if (!isset($price_data['id']) || !isset($price_data['unit_price'])) {
                    $errors[] = "Missing id or unit_price for an entry";
                    continue;
                }
                
                $fg_id = (int)$price_data['id'];
                $unit_price = (float)$price_data['unit_price'];
                
                $stmt->bind_param('di', $unit_price, $fg_id);
                
                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    $errors[] = "Failed to update product ID $fg_id: " . $stmt->error;
                }
            }
            
            $stmt->close();
            $conn->commit();
            
            echo json_encode([
                'success' => true,
                'message' => "Successfully updated pricing for $success_count product(s)",
                'updated_count' => $success_count,
                'errors' => $errors
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }
    
    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>


