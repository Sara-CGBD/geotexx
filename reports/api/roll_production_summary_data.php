<?php
/**
 * Roll Production Summary API
 * 
 * GET: Fetch roll production summary data with filters
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

// Role-based access control
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'production_user', 'management', 'agm ops', 'agm operations'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied to Roll Production Reports']);
    exit;
}

date_default_timezone_set('Asia/Dhaka');

try {
    $conn = SecurityConfig::getConnection();
    
    // Get filter parameters
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $palkId = $_GET['palk_id'] ?? '';
    $nextStage = $_GET['next_stage'] ?? '';
    $shift = $_GET['shift'] ?? '';
    
    // Build query with auto-calculated shift based on time
    // Day: 8 AM to 7:59 PM, Night: 8 PM to 7:59 AM
    $query = "SELECT *,
        CASE 
            WHEN HOUR(date) >= 8 AND HOUR(date) < 20 THEN 'Day'
            ELSE 'Night'
        END as calculated_shift
    FROM roll_production 
    WHERE is_deleted = 0";
    
    $params = [];
    $types = '';
    
    if ($dateFrom) {
        $query .= " AND DATE(date) >= ?";
        $params[] = $dateFrom;
        $types .= 's';
    }
    if ($dateTo) {
        $query .= " AND DATE(date) <= ?";
        $params[] = $dateTo;
        $types .= 's';
    }
    if ($palkId) {
        $query .= " AND palk_id LIKE ?";
        $params[] = "%$palkId%";
        $types .= 's';
    }
    if ($nextStage) {
        $query .= " AND next_stage = ?";
        $params[] = $nextStage;
        $types .= 's';
    }
    
    // Add shift filter using HAVING clause
    if ($shift) {
        $query .= " HAVING calculated_shift = ?";
        $params[] = $shift;
        $types .= 's';
    }
    
    $query .= " ORDER BY date DESC";
    
    // Execute query
    $stmt = $conn->prepare($query);
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $productions = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Calculate statistics
    $totalQty = array_sum(array_column($productions, 'qty'));
    $totalRecords = count($productions);
    
    // Group by shift
    $shiftStats = [];
    foreach ($productions as $prod) {
        $shiftValue = $prod['calculated_shift'];
        if (!isset($shiftStats[$shiftValue])) {
            $shiftStats[$shiftValue] = ['count' => 0, 'qty' => 0];
        }
        $shiftStats[$shiftValue]['count']++;
        $shiftStats[$shiftValue]['qty'] += $prod['qty'];
    }
    
    // Group by next_stage
    $stageStats = [];
    foreach ($productions as $prod) {
        $stage = $prod['next_stage'] ?? 'Not Specified';
        if (!isset($stageStats[$stage])) {
            $stageStats[$stage] = ['count' => 0, 'qty' => 0];
        }
        $stageStats[$stage]['count']++;
        $stageStats[$stage]['qty'] += $prod['qty'];
    }
    
    // Group by date
    $dateStats = [];
    foreach ($productions as $prod) {
        $date = date('Y-m-d', strtotime($prod['date']));
        if (!isset($dateStats[$date])) {
            $dateStats[$date] = ['count' => 0, 'qty' => 0];
        }
        $dateStats[$date]['count']++;
        $dateStats[$date]['qty'] += $prod['qty'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $productions,
        'summary' => [
            'total_quantity' => $totalQty,
            'total_records' => $totalRecords,
            'shift_breakdown' => $shiftStats,
            'stage_breakdown' => $stageStats,
            'date_breakdown' => $dateStats
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}


