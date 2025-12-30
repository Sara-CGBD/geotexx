<?php
/**
 * Scrap Entry API
 * 
 * GET: Fetch scrap categories and generate new scrap ID
 * POST: Submit new scrap entry
 */

session_start();
header('Content-Type: application/json');
require_once '../../config/security_config.php';
require_once '../../config/AccessControl.php';

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

// Check module access
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_SCRAP, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied to Scrap module']);
    exit;
}

date_default_timezone_set('Asia/Dhaka');

try {
    $conn = SecurityConfig::getConnection();
    
    // Handle GET request - fetch data for form initialization
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Generate Scrap ID (SC-YYYYMMDD-XXX)
        $today = date('Y-m-d');
        $date_part = date('Ymd');
        $scrap_prefix = 'SC-' . $date_part . '-';
        $next_scrap_number = 1;
        
        // Check if scrap table exists
        $tbl = $conn->query("SHOW TABLES LIKE 'scrap'");
        if ($tbl && $tbl->num_rows > 0) {
            $col = $conn->query("SHOW COLUMNS FROM scrap LIKE 'scrap_id'");
            if ($col && $col->num_rows > 0) {
                $seq = $conn->query("SELECT MAX(CAST(SUBSTRING(scrap_id, -3) AS UNSIGNED)) AS last_num FROM scrap WHERE DATE(date_time) = '$today'");
                if ($seq && $row = $seq->fetch_assoc()) {
                    $last_num = (int)($row['last_num'] ?? 0);
                    $next_scrap_number = $last_num + 1;
                }
            }
        }
        
        $scrap_id = $scrap_prefix . str_pad((string)$next_scrap_number, 3, '0', STR_PAD_LEFT);
        
        // Get predefined scrap products
        $scrap_products = [
            'Woven Polypropylene Roll',
            'Non-Woven Polypropylene Roll',
            'Valve Bags',
            'FIBC (Jumbo Bags)',
            'Finished Geo Bags',
            'Other'
        ];
        
        // Get predefined scrap types
        $scrap_types = [
            'Edge Trim',
            'Defective Material',
            'Production Waste',
            'Off-spec Product',
            'Contaminated Material',
            'Other'
        ];
        
        // Get current date/time
        $current_datetime = date('Y-m-d H:i:s');
        
        echo json_encode([
            'success' => true,
            'scrap_id' => $scrap_id,
            'scrap_products' => $scrap_products,
            'scrap_types' => $scrap_types,
            'current_datetime' => $current_datetime
        ]);
    }
    
    // Handle POST request - submit new scrap entry
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required = ['scrap_product', 'scrap_type', 'scrap_qty'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
                exit;
            }
        }
        
        $scrapProduct = substr(trim($data['scrap_product']), 0, 100);
        $scrapType = substr(trim($data['scrap_type']), 0, 100);
        $qty = (float)$data['scrap_qty'];
        $scrapId = isset($data['scrap_id']) ? trim($data['scrap_id']) : '';
        $dateTime = isset($data['dateTime']) ? trim($data['dateTime']) : '';
        $remarks = isset($data['remarks']) ? trim($data['remarks']) : null;
        
        // Validate quantity
        if ($qty <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Quantity must be greater than 0']);
            exit;
        }
        
        // Ensure table structure
        $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS scrap_id VARCHAR(50) UNIQUE");
        $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS date_time DATETIME");
        $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS scrap_product VARCHAR(100)");
        $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS scrap_type VARCHAR(100)");
        $conn->query("ALTER TABLE scrap ADD COLUMN IF NOT EXISTS remarks TEXT");
        
        // Relax FK constraints - check if FK exists before dropping
        $dbName = $conn->query("SELECT DATABASE() AS d")->fetch_assoc()['d'];
        $fkCheck = $conn->query("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = '$dbName' AND TABLE_NAME = 'scrap' AND CONSTRAINT_NAME = 'scrap_ibfk_1'");
        
        if ($fkCheck && $fkCheck->num_rows > 0) {
            $conn->query("SET FOREIGN_KEY_CHECKS=0");
            $conn->query("ALTER TABLE scrap DROP FOREIGN KEY scrap_ibfk_1");
            $conn->query("SET FOREIGN_KEY_CHECKS=1");
        }
        
        // Allow NULL for prod_id
        $conn->query("ALTER TABLE scrap MODIFY COLUMN prod_id INT NULL");
        
        // Generate scrap_id if not provided
        if ($scrapId === '') {
            $today = date('Y-m-d');
            $nextNum = 1;
            $res = $conn->query("SELECT MAX(CAST(SUBSTRING(scrap_id, -3) AS UNSIGNED)) AS last_num FROM scrap WHERE DATE(COALESCE(date_time, NOW())) = '$today'");
            if ($res && $row = $res->fetch_assoc()) {
                if (!empty($row['last_num'])) {
                    $nextNum = ((int)$row['last_num']) + 1;
                }
            }
            $scrapId = 'SC-' . date('Ymd') . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
        }
        
        // Use current datetime if not provided
        if ($dateTime === '') {
            $dateTime = date('Y-m-d H:i:s');
        }
        
        // Insert scrap entry
        $stmt = $conn->prepare('INSERT INTO scrap (scrap_id, date_time, scrap_product, scrap_type, qty, remarks) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param('ssssds', $scrapId, $dateTime, $scrapProduct, $scrapType, $qty, $remarks);
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => 'Scrap entry saved successfully',
            'scrap_id' => $scrapId,
            'date_time' => $dateTime
        ]);
    }
    
    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}


