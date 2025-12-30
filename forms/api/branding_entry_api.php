<?php
/**
 * Branding Entry API
 * 
 * GET: Fetch form data (projects, machines, generate Branding ID)
 * POST: Submit new branding entry
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
        
        // Fetch machines (branding machines)
        $machines = [];
        $machineRes = $conn->query("SELECT id, machine_name FROM machines WHERE machine_type = 'Branding' OR machine_type IS NULL ORDER BY machine_name ASC");
        if ($machineRes) {
            while ($m = $machineRes->fetch_assoc()) {
                $machines[] = $m;
            }
        }
        
        // If no machines found, provide default options
        if (empty($machines)) {
            $machines = [
                ['id' => 1, 'machine_name' => 'Machine 1'],
                ['id' => 2, 'machine_name' => 'Machine 2'],
                ['id' => 3, 'machine_name' => 'Machine 3']
            ];
        }
        
        // Generate Branding ID (BR-YYYYMMDD-XXX)
        $current_date = date('Y-m-d');
        $date_part = date('Ymd');
        $branding_prefix = 'BR-' . $date_part . '-';
        $next_branding_number = 1;
        
        // Check if branding_entries table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'branding_entries'");
        if ($table_check && $table_check->num_rows > 0) {
            $last_branding = $conn->query("SELECT MAX(CAST(SUBSTRING(branding_id, -3) AS UNSIGNED)) as last_num FROM branding_entries WHERE DATE(date_time) = '$current_date'");
            if ($last_branding && $last_branding->num_rows > 0) {
                $row = $last_branding->fetch_assoc();
                if ($row['last_num']) {
                    $next_branding_number = $row['last_num'] + 1;
                }
            }
        }
        
        $branding_id = $branding_prefix . str_pad((string)$next_branding_number, 3, '0', STR_PAD_LEFT);
        
        // Get current date/time and shift
        $current_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $current_hour = (int)$current_time->format('H');
        $shift = ($current_hour >= 8 && $current_hour < 20) ? 'Day' : 'Night';
        
        echo json_encode([
            'success' => true,
            'projects' => $projects,
            'machines' => $machines,
            'branding_id' => $branding_id,
            'current_datetime' => $current_time->format('Y-m-d H:i:s'),
            'shift' => $shift,
            'reporter_id' => $_SESSION['user_id'],
            'reporter_name' => $_SESSION['username']
        ]);
    }
    
    // Handle POST request - submit branding entry
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        // Make projectId optional (default to 0 if not provided)
        $required = ['dateTime', 'shiftIncharge', 'machineId', 'bagSize', 'printQty'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
                exit;
            }
        }
        
        $dateTime = $data['dateTime'];
        $shiftIncharge = trim($data['shiftIncharge']);
        $projectId = isset($data['projectId']) && $data['projectId'] !== '' ? (int)$data['projectId'] : 0;
        $machineId = (int)$data['machineId'];
        $bagSize = trim($data['bagSize']);
        $printQty = (int)$data['printQty'];
        $ncpPcs = isset($data['ncpPcs']) ? (int)$data['ncpPcs'] : 0;
        $reporterId = isset($data['reporterId']) ? (int)$data['reporterId'] : $_SESSION['user_id'];
        $reporterName = isset($data['reporterName']) ? $data['reporterName'] : $_SESSION['username'];
        
        // Auto-generate branding ID if not provided
        $brandingId = isset($data['branding_id']) ? $data['branding_id'] : null;
        if (!$brandingId) {
            $current_date = date('Y-m-d');
            $next_branding_number = 1;
            
            $table_check = $conn->query("SHOW TABLES LIKE 'branding_entries'");
            if ($table_check && $table_check->num_rows > 0) {
                $last_branding = $conn->query("SELECT MAX(CAST(SUBSTRING(branding_id, -3) AS UNSIGNED)) as last_num FROM branding_entries WHERE DATE(date_time) = '$current_date'");
                if ($last_branding && $last_branding->num_rows > 0) {
                    $row = $last_branding->fetch_assoc();
                    if ($row['last_num']) {
                        $next_branding_number = $row['last_num'] + 1;
                    }
                }
            }
            
            $brandingId = 'BR-' . date('Ymd') . '-' . str_pad($next_branding_number, 3, '0', STR_PAD_LEFT);
        }
        
        // Validate numeric values
        if ($printQty <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Print quantity must be greater than 0']);
            exit;
        }
        
        // Create branding_entries table if it doesn't exist
        $createTable = "CREATE TABLE IF NOT EXISTS branding_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branding_id VARCHAR(50) UNIQUE,
            date_time DATETIME NOT NULL,
            shift_incharge VARCHAR(100),
            reporter_id INT,
            reporter_name VARCHAR(100),
            project_id INT,
            machine_id INT,
            bag_size VARCHAR(100),
            print_qty INT,
            ncp_pcs INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $conn->query($createTable);
        
        // Insert branding entry
        $stmt = $conn->prepare("INSERT INTO branding_entries (branding_id, date_time, shift_incharge, reporter_id, reporter_name, project_id, machine_id, bag_size, print_qty, ncp_pcs) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param("sssisssiii", $brandingId, $dateTime, $shiftIncharge, $reporterId, $reporterName, $projectId, $machineId, $bagSize, $printQty, $ncpPcs);
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        
        $entry_id = $conn->insert_id;
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => 'Branding entry saved successfully',
            'entry_id' => $entry_id,
            'branding_id' => $brandingId
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


