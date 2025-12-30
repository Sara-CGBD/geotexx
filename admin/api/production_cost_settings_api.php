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
    
    // GET: Fetch current production cost settings
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $query = "SELECT * FROM production_cost_settings ORDER BY cost_type";
        $result = $conn->query($query);
        
        $settings = [];
        $total_cost = 0;
        
        while ($row = $result->fetch_assoc()) {
            $cost_type = $row['cost_type'];
            $cost_value = (float)$row['cost_per_unit'];
            
            $settings[$cost_type] = [
                'cost_type' => $cost_type,
                'cost_per_unit' => $cost_value,
                'updated_by' => (int)$row['updated_by'],
                'updated_at' => $row['updated_at']
            ];
            
            $total_cost += $cost_value;
        }
        
        // Set defaults if not found
        if (!isset($settings['labor_cost'])) {
            $settings['labor_cost'] = ['cost_type' => 'labor_cost', 'cost_per_unit' => 15.0, 'updated_by' => null, 'updated_at' => null];
        }
        if (!isset($settings['utility_cost'])) {
            $settings['utility_cost'] = ['cost_type' => 'utility_cost', 'cost_per_unit' => 5.0, 'updated_by' => null, 'updated_at' => null];
        }
        if (!isset($settings['overhead_cost'])) {
            $settings['overhead_cost'] = ['cost_type' => 'overhead_cost', 'cost_per_unit' => 10.0, 'updated_by' => null, 'updated_at' => null];
        }
        
        // Recalculate total with defaults
        $total_cost = $settings['labor_cost']['cost_per_unit'] + 
                     $settings['utility_cost']['cost_per_unit'] + 
                     $settings['overhead_cost']['cost_per_unit'];
        
        echo json_encode([
            'success' => true,
            'data' => $settings,
            'total_cost_per_unit' => round($total_cost, 2)
        ]);
    }
    
    // POST: Update production cost settings
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['labor_cost']) || !isset($input['utility_cost']) || !isset($input['overhead_cost'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Missing required cost fields']);
            exit();
        }
        
        $labor_cost = (float)$input['labor_cost'];
        $utility_cost = (float)$input['utility_cost'];
        $overhead_cost = (float)$input['overhead_cost'];
        $user_id = $_SESSION['user_id'];
        
        $conn->begin_transaction();
        
        try {
            $update_query = "
                INSERT INTO production_cost_settings (cost_type, cost_per_unit, updated_by)
                VALUES 
                    ('labor_cost', ?, ?),
                    ('utility_cost', ?, ?),
                    ('overhead_cost', ?, ?)
                ON DUPLICATE KEY UPDATE 
                    cost_per_unit = VALUES(cost_per_unit),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP
            ";
            
            $stmt = $conn->prepare($update_query);
            $stmt->bind_param('dididi', $labor_cost, $user_id, $utility_cost, $user_id, $overhead_cost, $user_id);
            $stmt->execute();
            $stmt->close();
            
            $conn->commit();
            
            $total_cost = $labor_cost + $utility_cost + $overhead_cost;
            
            echo json_encode([
                'success' => true,
                'message' => 'Production cost settings updated successfully',
                'data' => [
                    'labor_cost' => $labor_cost,
                    'utility_cost' => $utility_cost,
                    'overhead_cost' => $overhead_cost,
                    'total_cost_per_unit' => round($total_cost, 2)
                ]
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


