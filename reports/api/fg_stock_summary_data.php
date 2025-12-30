<?php
session_start();
require_once '../../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
// Normalize AGM Operations variations
if ($user_role === 'agm operations' || $user_role === 'agm_ops' || $user_role === 'agm_operations') {
    $user_role = 'agm ops';
}
$allowed_roles = ['admin', 'management', 'agm ops', 'finance_user'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit();
}

header('Content-Type: application/json');

try {
    date_default_timezone_set('Asia/Dhaka');
    $conn = SecurityConfig::getConnection();
    
    $projectId = $_GET['project_id'] ?? '';
    $bagSize = $_GET['bag_size'] ?? '';
    
    // Get FG stock (items not delivered)
    $query = "SELECT 
        fg.*,
        (SELECT SUM(delivery_qty) FROM fg_delivery WHERE fg_id = fg.id) as total_delivered,
        fg.received_qty as passed_qty,
        fg.product_name,
        fg.client,
        p.project_name
    FROM fg fg
    LEFT JOIN projects p ON fg.project_id = p.id
    WHERE fg.is_deleted = 0";
    
    $params = [];
    $types = '';
    
    if ($projectId) {
        $query .= " AND fg.project_id = ?";
        $params[] = $projectId;
        $types .= 'i';
    }
    if ($bagSize) {
        $query .= " AND fg.batch_number LIKE ?";
        $params[] = "%$bagSize%";
        $types .= 's';
    }
    
    $query .= " ORDER BY fg.id DESC";
    
    $stmt = $conn->prepare($query);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $allEntries = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Filter to show only items in stock (not fully delivered)
    $stockEntries = [];
    $totalStockWeight = 0;
    $totalStockQty = 0;
    
    foreach ($allEntries as $entry) {
        $delivered = $entry['total_delivered'] ?? 0;
        $received = $entry['received_qty'] ?? 0;
        $remaining = $received - $delivered;
        
        if ($remaining > 0) {
            $entry['remaining_qty'] = $remaining;
            $entry['stock_weight'] = $remaining;
            $entry['bag_size'] = $entry['batch_number'];
            $entry['packaging_type'] = 'Standard';
            $stockEntries[] = $entry;
            $totalStockWeight += $remaining;
            $totalStockQty += 1;
        }
    }
    
    // Group by project
    $byProject = [];
    foreach ($stockEntries as $entry) {
        $proj = $entry['project_name'] ?? 'Unassigned';
        if ($entry['project_name'] === null || $entry['project_name'] === '') {
            $proj = 'Unassigned';
        }
        if (!isset($byProject[$proj])) {
            $byProject[$proj] = ['qty' => 0, 'weight' => 0, 'count' => 0];
        }
        $byProject[$proj]['qty'] += $entry['remaining_qty'];
        $byProject[$proj]['weight'] += $entry['stock_weight'];
        $byProject[$proj]['count']++;
    }
    
    // Group by client
    $byClient = [];
    foreach ($stockEntries as $entry) {
        $client = $entry['client'] ?? 'Unknown';
        if (!isset($byClient[$client])) {
            $byClient[$client] = ['qty' => 0, 'weight' => 0, 'count' => 0];
        }
        $byClient[$client]['qty'] += $entry['remaining_qty'];
        $byClient[$client]['weight'] += $entry['stock_weight'];
        $byClient[$client]['count']++;
    }
    
    // Group by bag size
    $byBagSize = [];
    foreach ($stockEntries as $entry) {
        $size = $entry['bag_size'];
        if (!isset($byBagSize[$size])) {
            $byBagSize[$size] = ['qty' => 0, 'weight' => 0, 'count' => 0];
        }
        $byBagSize[$size]['qty'] += $entry['remaining_qty'];
        $byBagSize[$size]['weight'] += $entry['stock_weight'];
        $byBagSize[$size]['count']++;
    }
    
    // Get filter options
    $projects = $conn->query("SELECT id, project_name FROM projects ORDER BY project_name")->fetch_all(MYSQLI_ASSOC);
    $bagSizes = $conn->query("SELECT DISTINCT batch_number FROM fg WHERE batch_number IS NOT NULL AND batch_number != '' ORDER BY batch_number")->fetch_all(MYSQLI_ASSOC);
    
    $response = [
        'success' => true,
        'data' => $stockEntries,
        'summary' => [
            'total_stock_weight' => number_format($totalStockWeight, 2),
            'total_stock_qty' => $totalStockQty,
            'total_items' => count($stockEntries)
        ],
        'by_project' => $byProject,
        'by_client' => $byClient,
        'by_bag_size' => $byBagSize,
        'filter_options' => [
            'projects' => $projects,
            'bag_sizes' => $bagSizes
        ]
    ];
    
    echo json_encode($response);
    $conn->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>


