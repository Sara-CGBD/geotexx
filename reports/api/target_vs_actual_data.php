<?php
/**
 * Target vs Actual API
 * 
 * GET: Fetch target vs actual production data with filters
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
    
    // Get filters
    $date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
    $date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today
    $module_filter = $_GET['module'] ?? '';
    $period_filter = $_GET['period'] ?? '';
    
    // Fetch all modules
    $modules_query = "SELECT id, module_name FROM modules ORDER BY id";
    $modules_result = $conn->query($modules_query);
    $modules = [];
    if ($modules_result) {
        while ($row = $modules_result->fetch_assoc()) {
            $modules[$row['id']] = $row['module_name'];
        }
    }
    
    // Build query with filters
    $where_clauses = ["pt.target_date BETWEEN ? AND ?"];
    $params = [$date_from, $date_to];
    $types = "ss";
    
    if (!empty($module_filter)) {
        $where_clauses[] = "pt.module_id = ?";
        $params[] = $module_filter;
        $types .= "i";
    }
    
    if (!empty($period_filter)) {
        $where_clauses[] = "pt.target_period = ?";
        $params[] = $period_filter;
        $types .= "s";
    }
    
    $where_sql = implode(" AND ", $where_clauses);
    
    // Main query - get target vs actual data
    $query = "
        SELECT 
            pt.target_id,
            pt.module_id,
            m.module_name,
            pt.target_period,
            pt.target_date,
            pt.target_qty,
            pt.production_qty,
            ROUND((pt.production_qty / pt.target_qty) * 100, 1) as achievement_percent,
            CASE 
                WHEN pt.production_qty >= pt.target_qty THEN 'Achieved'
                WHEN pt.production_qty >= (pt.target_qty * 0.8) THEN 'Near Target'
                ELSE 'Below Target'
            END as status
        FROM production_targets pt
        INNER JOIN modules m ON pt.module_id = m.id
        WHERE $where_sql
        ORDER BY pt.target_date DESC, m.module_name
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();
    
    // Calculate summary statistics
    $total_targets = 0;
    $total_achieved = 0;
    $by_module = [];
    $by_date = [];
    $by_status = ['Achieved' => 0, 'Near Target' => 0, 'Below Target' => 0];
    
    foreach ($data as $row) {
        $total_targets += $row['target_qty'];
        $total_achieved += $row['production_qty'];
        
        // By module
        $mod = $row['module_name'];
        if (!isset($by_module[$mod])) {
            $by_module[$mod] = ['target' => 0, 'actual' => 0, 'count' => 0];
        }
        $by_module[$mod]['target'] += $row['target_qty'];
        $by_module[$mod]['actual'] += $row['production_qty'];
        $by_module[$mod]['count']++;
        
        // By date
        $date = $row['target_date'];
        if (!isset($by_date[$date])) {
            $by_date[$date] = ['target' => 0, 'actual' => 0, 'count' => 0];
        }
        $by_date[$date]['target'] += $row['target_qty'];
        $by_date[$date]['actual'] += $row['production_qty'];
        $by_date[$date]['count']++;
        
        // By status
        $status = $row['status'];
        $by_status[$status]++;
    }
    
    // Calculate overall achievement percentage
    $overall_achievement = $total_targets > 0 ? round(($total_achieved / $total_targets) * 100, 1) : 0;
    
    echo json_encode([
        'success' => true,
        'data' => $data,
        'modules' => $modules,
        'summary' => [
            'total_target_qty' => $total_targets,
            'total_production_qty' => $total_achieved,
            'overall_achievement_percent' => $overall_achievement,
            'total_records' => count($data),
            'by_module' => $by_module,
            'by_date' => $by_date,
            'by_status' => $by_status
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

