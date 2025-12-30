<?php
session_start();
require_once '../../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'management', 'agm ops', 'qc_inspector'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied']);
    exit();
}

header('Content-Type: application/json');

try {
    date_default_timezone_set('Asia/Dhaka');
    $conn = SecurityConfig::getConnection();
    
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $batchNo = $_GET['batch_no'] ?? '';
    $projectId = $_GET['project_id'] ?? '';
    
    $query = "SELECT 
        fg.id,
        CONCAT('FG-', LPAD(fg.id, 3, '0')) as fg_id,
        fg.product_name,
        fg.batch_number,
        fg.batch_number as bag_size,
        fg.received_qty as quality_checked,
        fg.received_qty as passed_qty,
        0 as rejected_qty,
        fg.client,
        fg.created_at as date_time,
        IF(HOUR(fg.created_at) >= 8 AND HOUR(fg.created_at) < 20, 'Day', 'Night') as shift,
        'QC Inspector' as qc_inspector,
        p.project_name,
        (SELECT SUM(delivery_qty) FROM fg_delivery WHERE fg_id = fg.id) as total_delivered
    FROM fg
    LEFT JOIN projects p ON fg.project_id = p.id
    WHERE fg.is_deleted = 0";
    
    $params = [];
    $types = '';
    
    if ($dateFrom) {
        $query .= " AND DATE(fg.created_at) >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }
    if ($dateTo) {
        $query .= " AND DATE(fg.created_at) <= ?";
        $params[] = $dateTo;
        $types .= 's';
    }
    if ($batchNo) {
        $query .= " AND fg.batch_number LIKE ?";
        $params[] = "%$batchNo%";
        $types .= 's';
    }
    if ($projectId) {
        $query .= " AND fg.project_id = ?";
        $params[] = $projectId;
        $types .= 'i';
    }
    
    $query .= " ORDER BY fg.created_at DESC, fg.batch_number DESC";
    
    $stmt = $conn->prepare($query);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $batches = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Calculate statistics
    $totalBatches = count($batches);
    $totalProduced = array_sum(array_column($batches, 'quality_checked'));
    $totalPassed = array_sum(array_column($batches, 'passed_qty'));
    $totalRejected = array_sum(array_column($batches, 'rejected_qty'));
    $passRate = $totalProduced > 0 ? ($totalPassed / $totalProduced) * 100 : 0;
    
    // Get filter options
    $projects = $conn->query("SELECT id, project_name FROM projects ORDER BY project_name")->fetch_all(MYSQLI_ASSOC);
    $batchNumbers = $conn->query("SELECT DISTINCT batch_number FROM fg WHERE batch_number IS NOT NULL AND batch_number != '' ORDER BY batch_number DESC")->fetch_all(MYSQLI_ASSOC);
    
    $response = [
        'success' => true,
        'data' => $batches,
        'summary' => [
            'total_batches' => $totalBatches,
            'total_produced' => number_format($totalProduced, 2),
            'total_passed' => number_format($totalPassed, 2),
            'total_rejected' => number_format($totalRejected, 2),
            'pass_rate' => number_format($passRate, 2)
        ],
        'filter_options' => [
            'projects' => $projects,
            'batch_numbers' => $batchNumbers
        ]
    ];
    
    echo json_encode($response);
    $conn->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?>


