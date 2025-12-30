<?php
/**
 * Scrap Cost Settings API
 * 
 * GET: Fetch all scrap type costs
 * POST: Create or update scrap cost entry
 * DELETE: Remove a scrap cost entry
 */

session_start();
header('Content-Type: application/json');
require_once '../../config/security_config.php';

// Session validation
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Check session timeout
if (SecurityConfig::checkSessionTimeout()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired']);
    exit;
}

SecurityConfig::updateSessionActivity();

// Check account lock
if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Account is locked']);
    exit;
}

// Only Admin can access
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if ($user_role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only Admins can access Scrap Cost Settings']);
    exit;
}

date_default_timezone_set('Asia/Dhaka');

try {
    $conn = SecurityConfig::getConnection();
    
    // Ensure scrap_type_costs table exists
    $createTable = "
    CREATE TABLE IF NOT EXISTS scrap_type_costs (
        id INT PRIMARY KEY AUTO_INCREMENT,
        scrap_type VARCHAR(100) NOT NULL,
        scrap_product VARCHAR(100),
        cost_per_kg DECIMAL(10,2) NOT NULL DEFAULT 50.00,
        salvage_percentage DECIMAL(5,2) NOT NULL DEFAULT 30.00,
        updated_by INT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_scrap(scrap_type, scrap_product)
    )";
    $conn->query($createTable);
    
    // Handle GET request - fetch all scrap costs
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $scrap_type_filter = $_GET['scrap_type'] ?? '';
        $scrap_product_filter = $_GET['scrap_product'] ?? '';
        
        $query = "SELECT 
            stc.*,
            COALESCE(nu.full_name, nu.username, u.username, 'Unknown') as updated_by_name
        FROM scrap_type_costs stc
        LEFT JOIN new_user nu ON stc.updated_by = nu.id
        LEFT JOIN users u ON stc.updated_by = u.id
        WHERE 1=1";
        
        $params = [];
        $types = '';
        
        if ($scrap_type_filter) {
            $query .= " AND stc.scrap_type = ?";
            $params[] = $scrap_type_filter;
            $types .= 's';
        }
        
        if ($scrap_product_filter) {
            $query .= " AND stc.scrap_product = ?";
            $params[] = $scrap_product_filter;
            $types .= 's';
        }
        
        $query .= " ORDER BY stc.scrap_type, stc.scrap_product";
        
        $stmt = $conn->prepare($query);
        if ($types) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $costs = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Get predefined scrap products and types
        $scrap_products = [
            'Woven Polypropylene Roll',
            'Non-Woven Polypropylene Roll',
            'Valve Bags',
            'FIBC (Jumbo Bags)',
            'Finished Geo Bags',
            'Other'
        ];
        
        $scrap_types = [
            'Edge Trim',
            'Defective Material',
            'Production Waste',
            'Off-spec Product',
            'Contaminated Material',
            'Other'
        ];
        
        echo json_encode([
            'success' => true,
            'costs' => $costs,
            'scrap_products' => $scrap_products,
            'scrap_types' => $scrap_types
        ]);
    }
    
    // Handle POST request - create or update scrap cost
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        if (!isset($data['scrap_type']) || !isset($data['cost_per_kg'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing required fields']);
            exit;
        }
        
        $scrap_type = trim($data['scrap_type']);
        $scrap_product = isset($data['scrap_product']) ? trim($data['scrap_product']) : '';
        $cost_per_kg = floatval($data['cost_per_kg']);
        $salvage_percentage = isset($data['salvage_percentage']) ? floatval($data['salvage_percentage']) : 30.00;
        
        // Validate cost
        if ($cost_per_kg < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Cost per kg must be non-negative']);
            exit;
        }
        
        // Check if entry exists
        $check = $conn->prepare("SELECT id FROM scrap_type_costs WHERE scrap_type = ? AND scrap_product = ?");
        $check->bind_param('ss', $scrap_type, $scrap_product);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();
        
        if ($exists) {
            // Update existing
            $stmt = $conn->prepare("UPDATE scrap_type_costs SET cost_per_kg = ?, salvage_percentage = ?, updated_by = ? WHERE scrap_type = ? AND scrap_product = ?");
            $stmt->bind_param('ddiss', $cost_per_kg, $salvage_percentage, $_SESSION['user_id'], $scrap_type, $scrap_product);
            $action = 'updated';
        } else {
            // Insert new
            $stmt = $conn->prepare("INSERT INTO scrap_type_costs (scrap_type, scrap_product, cost_per_kg, salvage_percentage, updated_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('ssddi', $scrap_type, $scrap_product, $cost_per_kg, $salvage_percentage, $_SESSION['user_id']);
            $action = 'created';
        }
        
        if ($stmt->execute()) {
            $stmt->close();
            echo json_encode([
                'success' => true,
                'message' => "Scrap cost $action successfully",
                'action' => $action
            ]);
        } else {
            throw new Exception('Failed to save scrap cost: ' . $stmt->error);
        }
    }
    
    // Handle DELETE request - remove scrap cost entry
    elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($data['id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing cost ID']);
            exit;
        }
        
        $id = (int)$data['id'];
        
        $stmt = $conn->prepare("DELETE FROM scrap_type_costs WHERE id = ?");
        $stmt->bind_param('i', $id);
        
        if ($stmt->execute()) {
            $stmt->close();
            echo json_encode([
                'success' => true,
                'message' => 'Scrap cost deleted successfully'
            ]);
        } else {
            throw new Exception('Failed to delete scrap cost: ' . $stmt->error);
        }
    }
    
    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}


