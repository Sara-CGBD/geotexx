<?php
/**
 * CNC Entry API
 * 
 * GET: Fetch form data (projects, generate CNC ID)
 * POST: Submit new CNC entry
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
        
        // Generate CNC ID (CNC-YYYYMMDD-XXX with 8 AM daily reset)
        $current_hour = (int)date('H');
        $shift_date = ($current_hour < 8) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
        $date_part = date('Ymd', strtotime($shift_date));
        $cnc_prefix = 'CNC-' . $date_part . '-';
        $next_cnc_number = 1;
        
        // Check if cnc_entries table exists
        $tbl = $conn->query("SHOW TABLES LIKE 'cnc_entries'");
        if ($tbl && $tbl->num_rows > 0) {
            $col = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'cnc_id'");
            if ($col && $col->num_rows > 0) {
                $seq = $conn->query("SELECT MAX(CAST(SUBSTRING(cnc_id, -3) AS UNSIGNED)) AS last_num FROM cnc_entries WHERE DATE(date_time) = '$shift_date'");
                if ($seq && $row = $seq->fetch_assoc()) {
                    $last_num = (int)($row['last_num'] ?? 0);
                    $next_cnc_number = $last_num + 1;
                }
            }
        }
        
        $cnc_id = $cnc_prefix . str_pad((string)$next_cnc_number, 3, '0', STR_PAD_LEFT);
        
        // Get current date/time and shift
        $current_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $current_hour = (int)$current_time->format('H');
        $shift = ($current_hour >= 8 && $current_hour < 20) ? 'Day' : 'Night';
        
        echo json_encode([
            'success' => true,
            'projects' => $projects,
            'cnc_id' => $cnc_id,
            'current_datetime' => $current_time->format('Y-m-d H:i:s'),
            'shift' => $shift,
            'reporter_id' => $_SESSION['user_id'],
            'reporter_name' => $_SESSION['username']
        ]);
    }
    
    // Handle POST request - submit CNC entry
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required = ['cnc_id', 'project_id', 'bag_size', 'recommended_weight', 'actual_weight'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
                exit;
            }
        }
        
        $cnc_id = trim($data['cnc_id']);
        $date_time = isset($data['date_time']) ? $data['date_time'] : date('Y-m-d H:i:s');
        $shift = isset($data['shift']) ? $data['shift'] : 'Day';
        $reporter_id = isset($data['reporter_id']) ? (int)$data['reporter_id'] : $_SESSION['user_id'];
        $project_id = (int)$data['project_id'];
        $bag_size = trim($data['bag_size']);
        $recommended_weight = (float)$data['recommended_weight'];
        $actual_weight = (float)$data['actual_weight'];
        
        // Validate numeric values
        if ($recommended_weight <= 0 || $actual_weight <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Weights must be greater than 0']);
            exit;
        }
        
        // Ensure table structure
        $conn->query("ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS cnc_id VARCHAR(50) UNIQUE");
        $conn->query("ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS date_time DATETIME");
        $conn->query("ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS shift VARCHAR(10)");
        $conn->query("ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS reporter_id INT");
        $conn->query("ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS project_id INT");
        $conn->query("ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS bag_size VARCHAR(100)");
        $conn->query("ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS recommended_weight DECIMAL(10,2)");
        // Note: actual_weight column should already exist from database migration
        // If you get errors about missing column, run: fix_cnc_actual_weight.php
        
        // Insert CNC entry
        $stmt = $conn->prepare("INSERT INTO cnc_entries (cnc_id, date_time, shift, reporter_id, project_id, bag_size, recommended_weight, actual_weight) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param("sssiisdd", $cnc_id, $date_time, $shift, $reporter_id, $project_id, $bag_size, $recommended_weight, $actual_weight);
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        
        $entry_id = $conn->insert_id;
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => 'CNC entry saved successfully',
            'entry_id' => $entry_id,
            'cnc_id' => $cnc_id
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


