<?php
session_start();
require_once '../security_config.php';

// Security/session checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
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
    // Handle GET request - fetch available FG products, clients, and generate delivery ID
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Pre-generate FG Delivery ID (FD-YYYYMMDD-XXX) with 8 AM daily reset
        $current_hour = (int)date('H');
        $shift_date = ($current_hour < 8) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
        $date_part = date('Ymd', strtotime($shift_date));
        $delivery_prefix = 'FD-' . $date_part . '-';
        $next_delivery_number = 1;
        
        // Check if delivery_id column exists
        $dbNameRes = $conn->query("SELECT DATABASE() AS d");
        $dbNameRow = $dbNameRes ? $dbNameRes->fetch_assoc() : null;
        $dbName = $dbNameRow ? $dbNameRow['d'] : '';
        if ($dbNameRes) { $dbNameRes->close(); }
        
        $colCheckSql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='" . $conn->real_escape_string($dbName) . "' AND TABLE_NAME='fg_delivery' AND COLUMN_NAME='delivery_id'";
        $hasDeliveryId = false;
        if ($cc = $conn->query($colCheckSql)) {
            $hasDeliveryId = $cc->num_rows > 0;
            $cc->close();
        }
        
        if ($hasDeliveryId) {
            $maxSql = "SELECT MAX(CAST(SUBSTRING_INDEX(delivery_id, '-', -1) AS UNSIGNED)) AS last_num FROM fg_delivery WHERE delivery_id LIKE '" . $conn->real_escape_string($delivery_prefix) . "%'";
            if ($r = $conn->query($maxSql)) {
                if ($row = $r->fetch_assoc()) {
                    $last_num = (int)($row['last_num'] ?? 0);
                    $next_delivery_number = $last_num + 1;
                }
                $r->close();
            }
        }
        
        $pre_delivery_id = $delivery_prefix . str_pad((string)$next_delivery_number, 3, '0', STR_PAD_LEFT);
        
        // Generate Lighthouse Challan Number (CN-YYYYMMDD-XXX)
        $challan_prefix = 'CN-' . $date_part . '-';
        $next_challan_number = 1;
        
        $colCheckChallan = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='" . $conn->real_escape_string($dbName) . "' AND TABLE_NAME='fg_delivery' AND COLUMN_NAME='challan_no'";
        $hasChallanNo = false;
        if ($cc = $conn->query($colCheckChallan)) {
            $hasChallanNo = $cc->num_rows > 0;
            $cc->close();
        }
        
        if ($hasChallanNo) {
            $maxChallanSql = "SELECT MAX(CAST(SUBSTRING_INDEX(challan_no, '-', -1) AS UNSIGNED)) AS last_num FROM fg_delivery WHERE challan_no LIKE '" . $conn->real_escape_string($challan_prefix) . "%'";
            if ($r = $conn->query($maxChallanSql)) {
                if ($row = $r->fetch_assoc()) {
                    $last_num = (int)($row['last_num'] ?? 0);
                    $next_challan_number = $last_num + 1;
                }
                $r->close();
            }
        }
        
        $pre_challan_no = $challan_prefix . str_pad((string)$next_challan_number, 3, '0', STR_PAD_LEFT);
        
        // Fetch FG products with available stock
        $products = [];
        $selectSql = "SELECT 
            fg.id, 
            fg.product_name,
            fg.batch_number,
            fg.received_qty,
            (SELECT COALESCE(SUM(delivery_qty), 0) FROM fg_delivery WHERE fg_id = fg.id) as total_delivered,
            (fg.received_qty - (SELECT COALESCE(SUM(delivery_qty), 0) FROM fg_delivery WHERE fg_id = fg.id)) as available_qty,
            p.project_name
        FROM fg
        LEFT JOIN projects p ON fg.project_id = p.id
        WHERE fg.is_deleted = 0
        HAVING available_qty > 0
        ORDER BY fg.id DESC";
        
        $res = $conn->query($selectSql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $products[] = $row;
            }
        }
        
        // Fetch Clients
        $clients = [];
        $cSelect = "SELECT id, client_name FROM clients ORDER BY client_name ASC";
        $cres = $conn->query($cSelect);
        if ($cres) {
            while ($row = $cres->fetch_assoc()) {
                $clients[] = $row;
            }
        }
        
        // Get current date/time and shift
        $current_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $current_hour = (int)$current_time->format('H');
        $shift = ($current_hour >= 8 && $current_hour < 20) ? 'Day' : 'Night';
        
        echo json_encode([
            'success' => true,
            'products' => $products,
            'clients' => $clients,
            'delivery_id' => $pre_delivery_id,
            'challan_no' => $pre_challan_no,
            'current_datetime' => $current_time->format('Y-m-d H:i:s'),
            'shift' => $shift
        ]);
    }
    
    // Handle POST request - submit new delivery entry
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required_fields = ['delivery_id', 'fg_id', 'client_id', 'delivery_qty'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Missing required field: $field"]);
                exit();
            }
        }
        
        $delivery_id = $data['delivery_id'];
        $fg_id = $data['fg_id'];
        $client_id = $data['client_id'];
        $delivery_qty = $data['delivery_qty'];
        $destination = $data['destination'] ?? null;
        $truck_no = $data['truck_no'] ?? null;
        $challan_no = $data['challan_no'] ?? null;
        $remarks = $data['remarks'] ?? null;
        $reporter_id = $_SESSION['user_id'];
        $reporter_name = $_SESSION['username'];
        
        // Check available quantity
        $check_sql = "SELECT 
            fg.received_qty,
            (SELECT COALESCE(SUM(delivery_qty), 0) FROM fg_delivery WHERE fg_id = ?) as total_delivered
        FROM fg WHERE id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $fg_id, $fg_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $stock_data = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if (!$stock_data) {
            http_response_code(400);
            echo json_encode(['error' => 'FG product not found']);
            exit();
        }
        
        $available = $stock_data['received_qty'] - $stock_data['total_delivered'];
        if ($delivery_qty > $available) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Delivery quantity exceeds available stock',
                'available_qty' => $available,
                'requested_qty' => $delivery_qty
            ]);
            exit();
        }
        
        // Insert into fg_delivery table
        $stmt = $conn->prepare("INSERT INTO fg_delivery 
            (delivery_id, fg_id, client_id, delivery_qty, destination, truck_no, challan_no, remarks, reporter_id, reporter_name, date_time) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("siidsssis", $delivery_id, $fg_id, $client_id, $delivery_qty, $destination, $truck_no, $challan_no, $remarks, $reporter_id, $reporter_name);
        
        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Delivery entry submitted successfully',
                'delivery_id' => $delivery_id,
                'insert_id' => $stmt->insert_id,
                'remaining_stock' => $available - $delivery_qty
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to insert delivery entry: ' . $stmt->error]);
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


