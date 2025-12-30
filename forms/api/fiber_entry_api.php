<?php
/**
 * Fiber Entry API
 * 
 * GET: Fetch form data (projects, generate entry code)
 * POST: Submit new fiber entry
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
        
        // Generate entry code
        $entryCode = 'FE-' . time();
        
        // Get current date/time and shift
        $current_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $current_hour = (int)$current_time->format('H');
        $shift = ($current_hour >= 8 && $current_hour < 20) ? 'Day' : 'Night';
        
        echo json_encode([
            'success' => true,
            'projects' => $projects,
            'entry_code' => $entryCode,
            'current_datetime' => $current_time->format('Y-m-d H:i:s'),
            'shift' => $shift,
            'reporter_id' => $_SESSION['user_id'],
            'reporter_name' => $_SESSION['username']
        ]);
    }
    
    // Handle POST request - submit fiber entry
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required = ['dateTime', 'shiftIncharge', 'project', 'amount', 'origin', 'beltWeight', 'beltNumber'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
                exit;
            }
        }
        
        $dateTime = $data['dateTime'];
        $shiftIncharge = trim($data['shiftIncharge']);
        $reference = trim($data['reference'] ?? '');
        $projectId = (int)$data['project'];
        $amount = (float)$data['amount'];
        $recycledType = trim($data['recycledType'] ?? 'none');
        $recycledAmount = (float)($data['recycledAmount'] ?? 0);
        $totalAmount = (float)($data['totalAmount'] ?? $amount);
        $materialType = trim($data['materialType'] ?? '');
        $origin = trim($data['origin']);
        $beltWeight = (int)$data['beltWeight'];
        $beltNumber = trim($data['beltNumber']);
        $reporterId = $_SESSION['user_id'];
        $reporterName = $_SESSION['username'];
        
        // Validate numeric values
        if ($amount <= 0 || $beltWeight <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Amount and Belt Weight must be greater than 0']);
            exit;
        }
        
        // Auto-generate entry code
        $entryCode = 'FE-' . time();
        
        // Create fiber_entries table if it doesn't exist
        $createTable = "CREATE TABLE IF NOT EXISTS fiber_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            entry_code VARCHAR(50),
            reference VARCHAR(100),
            date_time DATETIME,
            shift_incharge VARCHAR(100),
            project_id INT,
            amount_kg DECIMAL(10,2),
            recycled_type VARCHAR(50) DEFAULT 'none',
            recycled_amount_kg DECIMAL(10,2) DEFAULT 0,
            total_amount_kg DECIMAL(10,2),
            origin VARCHAR(255),
            reporter_id INT,
            reporter_name VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_deleted TINYINT(1) DEFAULT 0,
            material_type VARCHAR(100),
            belt_weight INT,
            belt_number VARCHAR(50)
        )";
        $conn->query($createTable);
        
        // Insert fiber entry
        $stmt = $conn->prepare("INSERT INTO fiber_entries (entry_code, reference, date_time, shift_incharge, project_id, amount_kg, recycled_type, recycled_amount_kg, total_amount_kg, origin, reporter_id, reporter_name, material_type, belt_weight, belt_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param('sssidsddsisssis', $entryCode, $reference, $dateTime, $shiftIncharge, $projectId, $amount, $recycledType, $recycledAmount, $totalAmount, $origin, $reporterId, $reporterName, $materialType, $beltWeight, $beltNumber);
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        
        $entry_id = $conn->insert_id;
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => 'Fiber entry saved successfully',
            'entry_id' => $entry_id,
            'entry_code' => $entryCode
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


