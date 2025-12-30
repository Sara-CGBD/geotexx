
<?php
session_start();
require_once '../security_config.php';

// Security/session checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Role-based access control for Recycle module
require_once '../../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_RECYCLE, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit();
}

header('Content-Type: application/json');
date_default_timezone_set('Asia/Dhaka');

// DB connection
$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

// Generate Recycle ID function
function generateRecycleId($conn) {
    try {
        // Ensure scrap_recycle table exists
        $conn->query("CREATE TABLE IF NOT EXISTS scrap_recycle (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recycle_id VARCHAR(20) UNIQUE NOT NULL,
            scrap_id INT NOT NULL,
            recycled_qty DECIMAL(10,2) NOT NULL,
            user_id INT NOT NULL,
            recycled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            remarks TEXT NULL,
            machine_id VARCHAR(20) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Generate unique recycle ID based on shift logic
        $current_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $current_hour = (int)$current_time->format('H');
        
        // Determine shift (8am-7:59pm = day, 8pm-7:59am = night)
        $shift = ($current_hour >= 8 && $current_hour < 20) ? 'day' : 'night';
        
        // Get shift start time for the current day
        if ($shift === 'day') {
            $shift_start = clone $current_time;
            $shift_start->setTime(8, 0, 0);
        } else {
            if ($current_hour < 8) {
                $shift_start = clone $current_time;
                $shift_start->modify('-1 day')->setTime(20, 0, 0);
            } else {
                $shift_start = clone $current_time;
                $shift_start->setTime(20, 0, 0);
            }
        }
        
        $date_str = $shift_start->format('Ymd');
        $shift_code = ($shift === 'day') ? 'D' : 'N';
        
        // Get the next recycle ID for this shift
        $pattern = 'RC' . $date_str . $shift_code . '%';
        $stmt = $conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(recycle_id, -3) AS UNSIGNED)), 0) + 1 as next_id 
                                FROM scrap_recycle 
                                WHERE recycle_id LIKE ?");
        $stmt->bind_param("s", $pattern);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $row = $result->fetch_assoc()) {
            $next_id = $row['next_id'];
        } else {
            $next_id = 1;
        }
        $stmt->close();
        
        // Format recycle ID: RC + YYYYMMDD + Shift + 3-digit number
        $recycle_id = 'RC' . $date_str . $shift_code . str_pad($next_id, 3, '0', STR_PAD_LEFT);
        
        return $recycle_id;
        
    } catch (Exception $e) {
        // Fallback ID if anything fails
        $date_str = date('Ymd');
        $current_hour = (int)date('H');
        $shift_code = ($current_hour >= 8 && $current_hour < 20) ? 'D' : 'N';
        return 'RC' . $date_str . $shift_code . '001';
    }
}

try {
    // Handle GET request - fetch available scraps and generate recycle ID
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Fetch Scrap entries
        $scraps = [];
        $res = $conn->query("SELECT * FROM scrap WHERE is_deleted = 0 ORDER BY id DESC");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $scraps[] = [
                    'id' => $row['id'] ?? ($row['scrap_id'] ?? ''),
                    'scrap_type' => $row['scrap_type'] ?? ($row['type'] ?? 'Unknown'),
                    'scrap_product' => $row['scrap_product'] ?? '',
                    'qty' => $row['qty'] ?? ($row['scrap_qty'] ?? ($row['quantity'] ?? '0')),
                    'date_time' => $row['date_time'] ?? ''
                ];
            }
        }
        
        // Generate recycle ID
        $recycle_id = generateRecycleId($conn);
        
        // Get current date/time and shift
        $current_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $current_hour = (int)$current_time->format('H');
        $shift = ($current_hour >= 8 && $current_hour < 20) ? 'Day' : 'Night';
        
        echo json_encode([
            'success' => true,
            'scraps' => $scraps,
            'recycle_id' => $recycle_id,
            'current_datetime' => $current_time->format('Y-m-d H:i:s'),
            'shift' => $shift
        ]);
    }
    
    // Handle POST request - submit new recycle entry
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        if (empty($data['scrap_id']) || empty($data['recycled_qty'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
            exit();
        }
        
        $recycle_id = $data['recycle_id'] ?? generateRecycleId($conn);
        $scrap_id = $data['scrap_id'];
        $recycled_qty = $data['recycled_qty'];
        $machine_id = $data['machine_id'] ?? null;
        $remarks = $data['remarks'] ?? null;
        $user_id = $_SESSION['user_id'];
        
        // Insert into scrap_recycle table
        $stmt = $conn->prepare("INSERT INTO scrap_recycle (recycle_id, scrap_id, recycled_qty, user_id, machine_id, remarks) 
                                VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sidiss", $recycle_id, $scrap_id, $recycled_qty, $user_id, $machine_id, $remarks);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Recycle entry submitted successfully',
                'recycle_id' => $recycle_id,
                'insert_id' => $stmt->insert_id
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to insert recycle entry: ' . $stmt->error]);
        }
        
        $stmt->close();
    }
    
    // Handle unsupported methods
    else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
    
    $conn->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>



