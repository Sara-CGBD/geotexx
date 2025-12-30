<?php
/**
 * Swing Machine Entry API
 * 
 * GET: Fetch form data (projects, operators, helpers, generate Swing ID)
 * POST: Submit new swing machine entry
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
        
        // Fetch operators
        $operators = [];
        $opRes = $conn->query("SELECT id, operator_name FROM operators ORDER BY operator_name ASC");
        if ($opRes) {
            while ($op = $opRes->fetch_assoc()) {
                $operators[] = $op;
            }
        }
        
        // Fetch helpers
        $helpers = [];
        $helpRes = $conn->query("SELECT id, helper_name FROM helpers ORDER BY helper_name ASC");
        if ($helpRes) {
            while ($help = $helpRes->fetch_assoc()) {
                $helpers[] = $help;
            }
        }
        
        // Generate Swing ID (SWING-YYYYMMDD-XXX with 8 AM daily reset)
        $current_hour = (int)date('H');
        $shift_date = ($current_hour < 8) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
        $date_part = date('Ymd', strtotime($shift_date));
        $swing_prefix = 'SWING-' . $date_part . '-';
        $next_swing_number = 1;
        
        // Check if swing_machine_entry table exists
        $tbl = $conn->query("SHOW TABLES LIKE 'swing_machine_entry'");
        if ($tbl && $tbl->num_rows > 0) {
            $col = $conn->query("SHOW COLUMNS FROM swing_machine_entry LIKE 'swing_id'");
            if ($col && $col->num_rows > 0) {
                $seq = $conn->query("SELECT MAX(CAST(SUBSTRING(swing_id, -3) AS UNSIGNED)) AS last_num FROM swing_machine_entry WHERE DATE(date_time) = '$shift_date'");
                if ($seq && $row = $seq->fetch_assoc()) {
                    $last_num = (int)($row['last_num'] ?? 0);
                    $next_swing_number = $last_num + 1;
                }
            }
        }
        
        $swing_id = $swing_prefix . str_pad((string)$next_swing_number, 3, '0', STR_PAD_LEFT);
        
        // Get current date/time and shift
        $current_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $current_hour = (int)$current_time->format('H');
        $shift = ($current_hour >= 8 && $current_hour < 20) ? 'Day' : 'Night';
        
        echo json_encode([
            'success' => true,
            'projects' => $projects,
            'operators' => $operators,
            'helpers' => $helpers,
            'swing_id' => $swing_id,
            'current_datetime' => $current_time->format('Y-m-d H:i:s'),
            'shift' => $shift,
            'reporter_id' => $_SESSION['user_id']
        ]);
    }
    
    // Handle POST request - submit swing machine entry
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required = ['swing_id', 'project_id', 'operator_id', 'helper_id', 'line_no', 'sewing_qty', 'ncp_piece'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
                exit;
            }
        }
        
        $swing_id = trim($data['swing_id']);
        $date_time = isset($data['date_time']) ? $data['date_time'] : date('Y-m-d H:i:s');
        $shift = isset($data['shift']) ? $data['shift'] : 'Day';
        $reporter_id = isset($data['reporter_id']) ? (int)$data['reporter_id'] : $_SESSION['user_id'];
        $project_id = (int)$data['project_id'];
        $operator_id = (int)$data['operator_id'];
        $helper_id = (int)$data['helper_id'];
        $line_no = trim($data['line_no']);
        $sewing_qty = (int)$data['sewing_qty'];
        $ncp_piece = (int)$data['ncp_piece'];
        
        // Validate numeric values
        if ($sewing_qty < 0 || $ncp_piece < 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Quantities cannot be negative']);
            exit;
        }
        
        // Ensure table exists
        $create_table = "CREATE TABLE IF NOT EXISTS swing_machine_entry (
            id INT AUTO_INCREMENT PRIMARY KEY,
            swing_id VARCHAR(50) UNIQUE NOT NULL,
            date_time DATETIME NOT NULL,
            shift VARCHAR(10) NOT NULL,
            reporter_id INT NOT NULL,
            project_id INT NOT NULL,
            operator_id INT NOT NULL,
            helper_id INT NOT NULL,
            line_no VARCHAR(50) NOT NULL,
            sewing_qty INT NOT NULL,
            ncp_piece INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $conn->query($create_table);
        
        // Insert swing machine entry
        $stmt = $conn->prepare("INSERT INTO swing_machine_entry (swing_id, date_time, shift, reporter_id, project_id, operator_id, helper_id, line_no, sewing_qty, ncp_piece) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param("sssiiiisii", $swing_id, $date_time, $shift, $reporter_id, $project_id, $operator_id, $helper_id, $line_no, $sewing_qty, $ncp_piece);
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        
        $entry_id = $conn->insert_id;
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => 'Swing machine entry saved successfully',
            'entry_id' => $entry_id,
            'swing_id' => $swing_id
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


