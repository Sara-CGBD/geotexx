<?php
session_start();
require_once '../security_config.php';

// Security/session checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Role-based access control for Finished Goods module
require_once '../../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_FINISHED_GOODS, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit();
}

header('Content-Type: application/json');
date_default_timezone_set('Asia/Dhaka');

// DB connection
$conn = SecurityConfig::getConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

try {
    // Handle GET request - fetch available projects and generate FG ID
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Fetch projects
        $projects = [];
        $res = $conn->query("SELECT id, project_name FROM projects ORDER BY project_name ASC");
        if ($res) while ($r = $res->fetch_assoc()) $projects[] = $r;
        
        // FG ID generation (shift-based reset)
        $current_hour = (int)date('H');
        $shift_date = ($current_hour < 8) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
        
        $next_fg_number = 1;
        $table_exists = $conn->query("SHOW TABLES LIKE 'fg_entry'")->num_rows > 0;
        
        if ($table_exists) {
            $column_check = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'fg_id'");
            if ($column_check && $column_check->num_rows > 0) {
                $last_fg = $conn->query("SELECT MAX(fg_id) as last_fg FROM fg_entry 
                                          WHERE DATE(date_time) = '$shift_date'");
                if ($last_fg && $last_fg->num_rows > 0) {
                    $l = $last_fg->fetch_assoc();
                    $last_fg_id = $l['last_fg'] ?? '';
                    if (preg_match('/FG-(\d{8})-(\d{3})$/', $last_fg_id, $matches)) {
                        $next_fg_number = intval($matches[2]) + 1;
                    }
                }
            }
        }
        
        $fg_id = 'FG-' . date('Ymd') . '-' . str_pad($next_fg_number, 3, '0', STR_PAD_LEFT);
        
        // Get current date/time and shift
        $current_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $current_hour = (int)$current_time->format('H');
        $shift = ($current_hour >= 8 && $current_hour < 20) ? 'Day' : 'Night';
        
        echo json_encode([
            'success' => true,
            'projects' => $projects,
            'fg_id' => $fg_id,
            'current_datetime' => $current_time->format('Y-m-d H:i:s'),
            'shift' => $shift
        ]);
    }
    
    // Handle POST request - submit new FG entry
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required_fields = ['fg_id', 'project_id', 'product_name', 'batch_number', 'received_qty'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Missing required field: $field"]);
                exit();
            }
        }
        
        $fg_id = $data['fg_id'];
        $project_id = $data['project_id'];
        $product_name = $data['product_name'];
        $batch_number = $data['batch_number'];
        $received_qty = $data['received_qty'];
        $client = $data['client'] ?? null;
        $remarks = $data['remarks'] ?? null;
        $user_id = $_SESSION['user_id'];
        
        // Insert into fg table
        $stmt = $conn->prepare("INSERT INTO fg (project_id, product_name, batch_number, received_qty, client, remarks, user_id) 
                                VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issdss i", $project_id, $product_name, $batch_number, $received_qty, $client, $remarks, $user_id);
        
        if ($stmt->execute()) {
            $insert_id = $stmt->insert_id;
            
            // Also insert into fg_entry table if it exists
            $table_exists = $conn->query("SHOW TABLES LIKE 'fg_entry'")->num_rows > 0;
            if ($table_exists) {
                $stmt2 = $conn->prepare("INSERT INTO fg_entry (fg_id, project_id, product_name, batch_number, received_qty, client, remarks, user_id, date_time) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt2->bind_param("sissdssi", $fg_id, $project_id, $product_name, $batch_number, $received_qty, $client, $remarks, $user_id);
                $stmt2->execute();
                $stmt2->close();
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'FG entry submitted successfully',
                'fg_id' => $fg_id,
                'insert_id' => $insert_id,
                'batch_number' => $batch_number
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to insert FG entry: ' . $stmt->error]);
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


