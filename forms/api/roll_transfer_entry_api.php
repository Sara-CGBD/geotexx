<?php
/**
 * Roll Transfer Entry API
 * 
 * GET: Fetch form data
 * POST: Submit new roll transfer entry
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

if (SecurityConfig::checkSessionTimeout()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired']);
    exit;
}

SecurityConfig::updateSessionActivity();

if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Account is locked']);
    exit;
}

date_default_timezone_set('Asia/Dhaka');

try {
    $conn = SecurityConfig::getConnection();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get next transfer ID
        $lastTransfer = $conn->query("SELECT MAX(transfer_id) as last_id FROM roll_transfer");
        $nextTransferId = 1;
        if ($lastTransfer && $lastTransfer->num_rows > 0) {
            $lt = $lastTransfer->fetch_assoc();
            $nextTransferId = ($lt['last_id'] ?? 0) + 1;
        }
        
        $current_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        
        echo json_encode([
            'success' => true,
            'next_transfer_id' => $nextTransferId,
            'current_datetime' => $current_time->format('Y-m-d H:i:s'),
            'operator_id' => $_SESSION['user_id']
        ]);
    }
    
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $required = ['transfer_id','date_time','operator_id','amount','from_location','to_location'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
                exit;
            }
        }
        
        $transferId = (int)$data['transfer_id'];
        $dateTime = $data['date_time'];
        $operatorId = (int)$data['operator_id'];
        $operatorName = isset($data['operator_name']) ? substr(trim($data['operator_name']), 0, 100) : '';
        $amount = (float)$data['amount'];
        $fromLocation = substr(trim($data['from_location']), 0, 100);
        $toLocation = substr(trim($data['to_location']), 0, 100);
        
        $driverName = null;
        if (isset($data['driver_name']) && !empty($data['driver_name'])) {
            $driverName = substr(trim($data['driver_name']), 0, 100);
        }
        
        $referenceNumber = isset($data['reference_number']) ? substr(trim($data['reference_number']), 0, 200) : '';
        
        $createTable = "CREATE TABLE IF NOT EXISTS roll_transfer (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transfer_id INT,
            date_time DATETIME NOT NULL,
            operator_id INT NOT NULL,
            operator_name VARCHAR(100) DEFAULT NULL,
            reference_number VARCHAR(200) NOT NULL,
            driver_name VARCHAR(100) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL,
            from_location VARCHAR(100) NOT NULL,
            to_location VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_deleted TINYINT(1) DEFAULT 0,
            who_did VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME DEFAULT NULL
        )";
        $conn->query($createTable);
        
        $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS operator_name VARCHAR(100) DEFAULT NULL AFTER operator_id");
        $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS reference_number VARCHAR(200) AFTER operator_name");
        $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS from_location VARCHAR(100) DEFAULT ''");
        $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS to_location VARCHAR(100) DEFAULT ''");
        $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS driver_name VARCHAR(100) DEFAULT NULL");
        
        $stmt = $conn->prepare("INSERT INTO roll_transfer (transfer_id, date_time, operator_id, operator_name, reference_number, driver_name, amount_kg, from_location, to_location) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param('isissisdss', $transferId, $dateTime, $operatorId, $operatorName, $referenceNumber, $driverName, $amount, $fromLocation, $toLocation);
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        
        $entry_id = $conn->insert_id;
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => 'Roll transfer entry saved successfully',
            'entry_id' => $entry_id,
            'transfer_id' => $transferId
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


