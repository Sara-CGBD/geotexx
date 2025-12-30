<?php
/**
 * Fiber to Roll Entry API
 * 
 * GET: Fetch form data (projects, operators)
 * POST: Submit new fiber to roll entry
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
        // Fetch projects
        $projects = [];
        $res = $conn->query("SELECT id, project_name FROM projects ORDER BY project_name ASC");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $projects[] = $r;
            }
        }
        
        // Get current date/time and shift
        $current_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $current_hour = (int)$current_time->format('H');
        $shift = ($current_hour >= 8 && $current_hour < 20) ? 'Day' : 'Night';
        
        echo json_encode([
            'success' => true,
            'projects' => $projects,
            'current_datetime' => $current_time->format('Y-m-d H:i:s'),
            'shift' => $shift,
            'operator_id' => $_SESSION['user_id']
        ]);
    }
    
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $required = ['date_time','operator_id','project_id','bale_opener_number','bale_number','bale_weight','gsm','line_no','fiber_type','origin','roll_number','total_weight'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
                exit;
            }
        }
        
        $dateTime = $data['date_time'];
        $operatorId = (int)$data['operator_id'];
        $projectId = (int)$data['project_id'];
        $baleOpenerNumber = (int)$data['bale_opener_number'];
        $baleNumber = (int)$data['bale_number'];
        $baleWeight = (int)$data['bale_weight'];
        $gsm = (int)$data['gsm'];
        $lineNo = (int)$data['line_no'];
        $fiberType = substr(trim($data['fiber_type']), 0, 100);
        $origin = substr(trim($data['origin']), 0, 100);
        $rollNumber = (int)$data['roll_number'];
        $totalWeight = (float)$data['total_weight'];
        
        $createTable = "CREATE TABLE IF NOT EXISTS fiber_to_roll_entry (
            id INT AUTO_INCREMENT PRIMARY KEY,
            date_time DATETIME,
            operator_id INT,
            project_id INT,
            bale_opener_number INT,
            bale_number INT,
            bale_weight INT,
            gsm INT,
            line_no INT,
            fiber_type VARCHAR(100),
            origin VARCHAR(100),
            roll_number INT,
            total_weight DECIMAL(10,2),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $conn->query($createTable);
        
        $stmt = $conn->prepare("INSERT INTO fiber_to_roll_entry (date_time, operator_id, project_id, bale_opener_number, bale_number, bale_weight, gsm, line_no, fiber_type, origin, roll_number, total_weight) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param('siiiiiiissid', $dateTime, $operatorId, $projectId, $baleOpenerNumber, $baleNumber, $baleWeight, $gsm, $lineNo, $fiberType, $origin, $rollNumber, $totalWeight);
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        
        $entry_id = $conn->insert_id;
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => 'Fiber to roll entry saved successfully',
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


