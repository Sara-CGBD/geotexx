<?php
/**
 * Roll Entry API
 * 
 * GET: Fetch form data (projects, next roll/batch numbers)
 * POST: Submit new roll entry
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
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_ROLL_PRODUCTION, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied to Roll Production module']);
    exit;
}

date_default_timezone_set('Asia/Dhaka');

try {
    $conn = SecurityConfig::getConnection();
    
    // Handle GET request - fetch form data
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Fetch projects
        $projects = [];
        $res = $conn->query("SELECT id, project_name FROM projects ORDER BY project_name ASC");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $projects[] = $r;
            }
        }
        
        // Get next roll and batch numbers
        $current_date = date('Y-m-d');
        $last_roll = $conn->query("SELECT MAX(roll_number) as last_roll FROM roll_entry WHERE DATE(date_time) = '$current_date'");
        $next_roll_number = 1;
        if ($last_roll && $last_roll->num_rows > 0) {
            $l = $last_roll->fetch_assoc();
            $next_roll_number = ($l['last_roll'] ?? 0) + 1;
        }
        
        $last_batch = $conn->query("SELECT MAX(batch_number) as last_batch FROM roll_entry");
        $next_batch_number = 1;
        if ($last_batch) {
            $b = $last_batch->fetch_assoc();
            $next_batch_number = ($b['last_batch'] ?? 0) + 1;
        }
        
        // Get current date/time and shift
        $current_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $current_hour = (int)$current_time->format('H');
        $shift = ($current_hour >= 8 && $current_hour < 20) ? 'Day' : 'Night';
        
        echo json_encode([
            'success' => true,
            'projects' => $projects,
            'next_roll_number' => $next_roll_number,
            'next_batch_number' => $next_batch_number,
            'current_datetime' => $current_time->format('Y-m-d H:i:s'),
            'shift' => $shift,
            'operator_id' => $_SESSION['user_id'],
            'operator_name' => $_SESSION['username']
        ]);
    }
    
    // Handle POST request - submit roll entry
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required = ['date_time', 'operator_id', 'project_id', 'gsm', 'line_no', 'fiber_type', 'roll_number', 'total_weight'];
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
        $gsm = (int)$data['gsm'];
        $lineNo = substr(trim($data['line_no']), 0, 50);
        $fiberType = substr(trim($data['fiber_type']), 0, 100);
        $rollNumber = (int)$data['roll_number'];
        $totalWeight = (float)$data['total_weight'];
        
        // Validate numeric values
        if ($gsm <= 0 || $totalWeight <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'GSM and Total Weight must be greater than 0']);
            exit;
        }
        
        // Create table if not exists
        $createTable = "CREATE TABLE IF NOT EXISTS roll_entry (
            id INT AUTO_INCREMENT PRIMARY KEY,
            date_time DATETIME NOT NULL,
            operator_id INT NOT NULL,
            project_id INT NOT NULL,
            gsm INT NOT NULL,
            line_no VARCHAR(50) NOT NULL,
            fiber_type VARCHAR(100) NOT NULL,
            roll_number INT NOT NULL,
            total_weight DECIMAL(10,2) NOT NULL,
            batch_number INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_deleted TINYINT(1) DEFAULT 0,
            who_did VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME DEFAULT NULL,
            entry_id VARCHAR(50) DEFAULT NULL,
            UNIQUE KEY (entry_id)
        )";
        $conn->query($createTable);
        
        // Batch Number Generation Logic
        $dt = new DateTime($dateTime);
        $time = $dt->format('H:i:s');
        $date = $dt->format('Y-m-d');
        
        // Determine ShiftDate based on shift timing
        if ($time < '08:00:00') {
            $shiftDate = (new DateTime($date))->modify('-1 day');
            $shiftCode = 'N';
        } elseif ($time >= '08:00:00' && $time < '20:00:00') {
            $shiftDate = new DateTime($date);
            $shiftCode = 'D';
        } else {
            $shiftDate = new DateTime($date);
            $shiftCode = 'N';
        }
        
        // Format YYMonDD (e.g., 25Sep21)
        $YYMonDD = $shiftDate->format('y') . $shiftDate->format('M') . $shiftDate->format('d');
        
        // Count existing similar rolls for sequence number
        $stmtSeq = $conn->prepare("SELECT COUNT(*) as count FROM roll_entry WHERE gsm = ? AND line_no = ? AND fiber_type = ? AND DATE(date_time) = ?");
        $dateFormatted = $shiftDate->format('Y-m-d');
        $stmtSeq->bind_param('isss', $gsm, $lineNo, $fiberType, $dateFormatted);
        $stmtSeq->execute();
        $countRow = $stmtSeq->get_result()->fetch_assoc();
        $sequence = ($countRow['count'] ?? 0) + 1;
        $stmtSeq->close();
        
        // Build batch number: GSM_LineNo_FiberType_YYMonDD_ShiftCode_Sequence
        $batchNumber = $gsm . '_' . $lineNo . '_' . $fiberType . '_' . $YYMonDD . '_' . $shiftCode . '_' . $sequence;
        
        // Insert roll entry
        $stmt = $conn->prepare("INSERT INTO roll_entry (date_time, operator_id, project_id, gsm, line_no, fiber_type, roll_number, total_weight, batch_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param('siiissids', $dateTime, $operatorId, $projectId, $gsm, $lineNo, $fiberType, $rollNumber, $totalWeight, $batchNumber);
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        
        $entry_id = $conn->insert_id;
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => 'Roll entry saved successfully',
            'entry_id' => $entry_id,
            'roll_number' => $rollNumber,
            'batch_number' => $batchNumber
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


