<?php
/**
 * Roll Received Entry API
 * 
 * GET: Fetch form data (projects)
 * POST: Submit new roll received entry
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
        $projects = [];
        $res = $conn->query("SELECT id, project_name FROM projects ORDER BY project_name ASC");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $projects[] = $r;
            }
        }
        
        $current_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        
        echo json_encode([
            'success' => true,
            'projects' => $projects,
            'current_datetime' => $current_time->format('Y-m-d H:i:s'),
            'reporter_id' => $_SESSION['user_id']
        ]);
    }
    
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Make project_id optional; default to 0 if not provided
        $required = ['reporting_time','reporter_id','receiver_name','bag_roll_type','roll_size','roll_quantity','gsm','line_no','fiber_type','roll_number','batch_number'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
                exit;
            }
        }
        
        $reportingTime = $data['reporting_time'];
        $reporterId = (int)$data['reporter_id'];
        $receiverName = substr(trim($data['receiver_name']), 0, 100);
        $projectId = isset($data['project_id']) && $data['project_id'] !== '' ? (int)$data['project_id'] : 0;
        $bagRollType = substr(trim($data['bag_roll_type']), 0, 100);
        $rollSize = substr(trim($data['roll_size']), 0, 100);
        $rollQuantity = (int)$data['roll_quantity'];
        $gsm = (int)$data['gsm'];
        $lineNo = (int)$data['line_no'];
        $fiberType = substr(trim($data['fiber_type']), 0, 100);
        $rollNumber = (int)$data['roll_number'];
        $batchNumber = $data['batch_number'];
        
        $createTable = "CREATE TABLE IF NOT EXISTS roll_received (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reporting_time DATETIME NOT NULL,
            reporter_id INT NOT NULL,
            receiver_name VARCHAR(100) NOT NULL,
            project_id INT NOT NULL,
            bag_roll_type VARCHAR(100) NOT NULL,
            roll_size VARCHAR(100) NOT NULL,
            roll_quantity INT NOT NULL,
            gsm INT NOT NULL,
            line_no INT NOT NULL,
            fiber_type VARCHAR(100) NOT NULL,
            roll_number INT NOT NULL,
            batch_number VARCHAR(100) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_deleted TINYINT(1) DEFAULT 0,
            who_did VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME DEFAULT NULL
        )";
        $conn->query($createTable);
        
        $conn->query("ALTER TABLE roll_received ADD COLUMN IF NOT EXISTS fiber_type VARCHAR(100) DEFAULT ''");
        $conn->query("ALTER TABLE roll_received ADD COLUMN IF NOT EXISTS line_no INT DEFAULT 0");
        $conn->query("ALTER TABLE roll_received ADD COLUMN IF NOT EXISTS roll_number INT DEFAULT 0");
        $conn->query("ALTER TABLE roll_received ADD COLUMN IF NOT EXISTS batch_number VARCHAR(100) DEFAULT ''");
        
        $stmt = $conn->prepare("INSERT INTO roll_received (reporting_time, reporter_id, receiver_name, project_id, bag_roll_type, roll_size, roll_quantity, gsm, line_no, fiber_type, roll_number, batch_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param('sissssiiisis', $reportingTime, $reporterId, $receiverName, $projectId, $bagRollType, $rollSize, $rollQuantity, $gsm, $lineNo, $fiberType, $rollNumber, $batchNumber);
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        
        $entry_id = $conn->insert_id;
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => 'Roll received entry saved successfully',
            'entry_id' => $entry_id
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


