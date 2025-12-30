<?php
header('Content-Type: application/json');
session_start();
require_once '../../config/security_config.php';

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check role authorization
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'qc_inspector', 'management', 'agm ops'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access Denied']);
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

try {
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $dateTo = $_GET['date_to'] ?? date('Y-m-d');
    $stage = $_GET['stage'] ?? '';
    $projectId = $_GET['project_id'] ?? '';
    
    $query = "SELECT 
        q.*,
        COALESCE(
            (SELECT project_name FROM projects WHERE id = q.project_prod AND q.project_prod > 0 LIMIT 1),
            (SELECT project_name FROM projects WHERE id = q.project_roll AND q.project_roll > 0 LIMIT 1),
            (SELECT project_name FROM projects WHERE id = q.project_cnc AND q.project_cnc > 0 LIMIT 1),
            (SELECT project_name FROM projects WHERE id = q.project_fg AND q.project_fg > 0 LIMIT 1),
            'Unassigned'
        ) as project_name,
        COALESCE(
            (SELECT full_name FROM new_user WHERE id = q.reporter_id LIMIT 1),
            (SELECT username FROM new_user WHERE id = q.reporter_id LIMIT 1),
            (SELECT username FROM users WHERE id = q.reporter_id LIMIT 1),
            'Admin'
        ) as inspector_name
    FROM qc_entries q
    WHERE q.qc_result = 'Fail'
    AND DATE(q.date_time) BETWEEN ? AND ?";
    
    $params = [$dateFrom, $dateTo];
    $types = 'ss';
    
    if ($stage) {
        $query .= " AND q.qc_stage = ?";
        $params[] = $stage;
        $types .= 's';
    }
    if ($projectId) {
        $query .= " AND (q.project_prod = ? OR q.project_roll = ? OR q.project_cnc = ? OR q.project_fg = ?)";
        $params[] = $projectId;
        $params[] = $projectId;
        $params[] = $projectId;
        $params[] = $projectId;
        $types .= 'iiii';
    }
    
    $query .= " ORDER BY q.date_time DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $defects = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Analyze defect categories
    $byStage = [];
    $byType = [];
    $byProject = [];
    
    foreach ($defects as $defect) {
        $stageVal = $defect['qc_stage'] ?? 'Unknown Stage';
        $typeVal = $defect['qc_type'] ?? 'Unknown Type';
        $projectVal = !empty($defect['project_name']) && $defect['project_name'] != 'N/A' ? $defect['project_name'] : 'No Project Assigned';
        
        $byStage[$stageVal] = ($byStage[$stageVal] ?? 0) + 1;
        $byType[$typeVal] = ($byType[$typeVal] ?? 0) + 1;
        $byProject[$projectVal] = ($byProject[$projectVal] ?? 0) + 1;
    }
    
    arsort($byStage);
    arsort($byType);
    arsort($byProject);
    
    // Format defects for output
    $formatted_defects = [];
    foreach ($defects as $defect) {
        $formatted_defects[] = [
            'qc_id' => $defect['qc_id'],
            'date_time' => date('M d, Y g:i A', strtotime($defect['date_time'])),
            'stage' => $defect['qc_stage'] ?? 'N/A',
            'type' => $defect['qc_type'] ?? 'N/A',
            'project_name' => !empty($defect['project_name']) ? $defect['project_name'] : 'No Project',
            'inspector_name' => $defect['inspector_name'] ?? 'Unknown',
            'remarks' => $defect['remarks'] ?? ''
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'defects' => $formatted_defects,
            'total_defects' => count($defects),
            'by_stage' => $byStage,
            'by_type' => $byType,
            'by_project' => array_slice($byProject, 0, 10, true),
            'stage_count' => count($byStage),
            'type_count' => count($byType)
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>


