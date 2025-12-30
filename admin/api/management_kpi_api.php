<?php
session_start();
require_once '../../config/security_config.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Role-based access control - Management and Admin
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'management', 'agm ops', 'agm operations'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Management or Admin role required.']);
    exit();
}

try {
    $conn = SecurityConfig::getConnection();
    date_default_timezone_set('Asia/Dhaka');
    
    // GET: Fetch KPI data
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
        $dateTo = $_GET['date_to'] ?? date('Y-m-d'); // Today
        
        $kpiData = [];
        
        // Production Metrics
        try {
            // Roll Production
            $rollQuery = "SELECT 
                COUNT(*) as total_rolls,
                COALESCE(SUM(total_weight), 0) as total_weight,
                COALESCE(AVG(total_weight), 0) as avg_weight
            FROM roll_entry 
            WHERE DATE(date_time) BETWEEN ? AND ? AND is_deleted = 0";
            $stmt = $conn->prepare($rollQuery);
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $kpiData['roll_production'] = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            // Fiber to Roll Conversion
            $fiberToRollQuery = "SELECT 
                COALESCE(SUM(bale_weight), 0) as total_fiber_used,
                COALESCE(SUM(total_weight), 0) as total_roll_produced
            FROM fiber_to_roll_entry 
            WHERE DATE(date_time) BETWEEN ? AND ?";
            $stmt = $conn->prepare($fiberToRollQuery);
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $fiberData = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $kpiData['fiber_to_roll'] = $fiberData;
            $kpiData['conversion_efficiency'] = $fiberData['total_fiber_used'] > 0 
                ? round(($fiberData['total_roll_produced'] / $fiberData['total_fiber_used']) * 100, 2)
                : 0;
            
            // CNC Production
            $cncQuery = "SELECT COUNT(*) as total_cnc, COALESCE(SUM(actual_weight), 0) as total_cnc_qty 
            FROM cnc_entries 
            WHERE DATE(date_time) BETWEEN ? AND ?";
            $stmt = $conn->prepare($cncQuery);
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $kpiData['cnc_production'] = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            // Finished Goods Production
            $fgQuery = "SELECT COUNT(*) as total_fg, COALESCE(SUM(received_qty), 0) as total_fg_weight 
            FROM fg 
            WHERE DATE(created_at) BETWEEN ? AND ?";
            $stmt = $conn->prepare($fgQuery);
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $kpiData['fg_production'] = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
        } catch (Exception $e) {
            error_log("Production Metrics Error: " . $e->getMessage());
            $kpiData['production_error'] = $e->getMessage();
        }
        
        // Quality Metrics
        try {
            $qcQuery = "SELECT 
                COUNT(*) as total_inspections,
                SUM(CASE WHEN qc_result = 'Pass' THEN 1 ELSE 0 END) as passed,
                SUM(CASE WHEN qc_result = 'Fail' THEN 1 ELSE 0 END) as failed
            FROM qc_entries 
            WHERE DATE(date_time) BETWEEN ? AND ?";
            $stmt = $conn->prepare($qcQuery);
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $qcData = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $kpiData['qc_stats'] = $qcData;
            $kpiData['qc_pass_rate'] = $qcData['total_inspections'] > 0 
                ? round(($qcData['passed'] / $qcData['total_inspections']) * 100, 2)
                : 0;
                
        } catch (Exception $e) {
            error_log("QC Metrics Error: " . $e->getMessage());
            $kpiData['qc_error'] = $e->getMessage();
        }
        
        // Scrap & Waste Metrics
        try {
            $scrapQuery = "SELECT 
                COUNT(*) as total_scrap_records,
                COALESCE(SUM(qty), 0) as total_scrap_qty,
                s.scrap_type,
                COALESCE(SUM(s.qty), 0) as qty_by_type
            FROM scrap s
            WHERE DATE(date_time) BETWEEN ? AND ? AND is_deleted = 0
            GROUP BY s.scrap_type";
            $stmt = $conn->prepare($scrapQuery);
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $scrapResult = $stmt->get_result();
            
            $scrapByType = [];
            $totalScrapQty = 0;
            while ($row = $scrapResult->fetch_assoc()) {
                $scrapByType[] = $row;
                $totalScrapQty += $row['qty_by_type'];
            }
            $stmt->close();
            
            $kpiData['scrap_stats'] = [
                'total_scrap_qty' => $totalScrapQty,
                'by_type' => $scrapByType
            ];
            
            // Recycle Stats
            $recycleQuery = "SELECT 
                COUNT(*) as total_recycle_records,
                COALESCE(SUM(recycled_qty), 0) as total_recycled_qty
            FROM scrap_recycle
            WHERE DATE(recycled_at) BETWEEN ? AND ?";
            $stmt = $conn->prepare($recycleQuery);
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $recycleData = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $kpiData['recycle_stats'] = $recycleData;
            $kpiData['recycle_rate'] = $totalScrapQty > 0 
                ? round(($recycleData['total_recycled_qty'] / $totalScrapQty) * 100, 2)
                : 0;
                
        } catch (Exception $e) {
            error_log("Scrap Metrics Error: " . $e->getMessage());
            $kpiData['scrap_error'] = $e->getMessage();
        }
        
        // Inventory Metrics
        try {
            // FG Stock
            $fgStockQuery = "SELECT 
                COUNT(*) as total_items,
                COALESCE(SUM(fg.received_qty - COALESCE((SELECT SUM(delivery_qty) FROM fg_delivery WHERE fg_id = fg.id), 0)), 0) as total_stock_weight
            FROM fg";
            $fgStockResult = $conn->query($fgStockQuery);
            $kpiData['fg_stock'] = $fgStockResult->fetch_assoc();
            
            // FG Deliveries (in date range)
            $fgDeliveryQuery = "SELECT 
                COUNT(*) as total_deliveries,
                COALESCE(SUM(delivery_qty), 0) as total_delivered_weight
            FROM fg_delivery
            WHERE DATE(date_time) BETWEEN ? AND ?";
            $stmt = $conn->prepare($fgDeliveryQuery);
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $kpiData['fg_delivery'] = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
        } catch (Exception $e) {
            error_log("Inventory Metrics Error: " . $e->getMessage());
            $kpiData['inventory_error'] = $e->getMessage();
        }
        
        // Target Achievement
        try {
            $targetQuery = "SELECT 
                COALESCE(SUM(target_quantity), 0) as total_target,
                COALESCE(SUM(actual_quantity), 0) as total_actual
            FROM production_targets
            WHERE DATE(target_date) BETWEEN ? AND ?";
            $stmt = $conn->prepare($targetQuery);
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $targetData = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            $kpiData['target_stats'] = $targetData;
            $kpiData['target_achievement'] = $targetData['total_target'] > 0 
                ? round(($targetData['total_actual'] / $targetData['total_target']) * 100, 2)
                : 0;
                
        } catch (Exception $e) {
            error_log("Target Metrics Error: " . $e->getMessage());
            $kpiData['target_error'] = $e->getMessage();
        }
        
        // Project Stats
        try {
            $projectQuery = "SELECT COUNT(*) as total_projects FROM projects WHERE status = 'active'";
            $projectResult = $conn->query($projectQuery);
            $kpiData['active_projects'] = $projectResult->fetch_assoc();
        } catch (Exception $e) {
            error_log("Project Metrics Error: " . $e->getMessage());
        }
        
        // Active Users
        try {
            $userQuery = "SELECT COUNT(*) as active_users FROM new_user WHERE status = 'active'";
            $userResult = $conn->query($userQuery);
            $kpiData['active_users'] = $userResult->fetch_assoc();
        } catch (Exception $e) {
            error_log("User Metrics Error: " . $e->getMessage());
        }
        
        // Overall Performance Value (OPV) Score
        $hasOpvData = false;
        if (($kpiData['qc_stats']['total_inspections'] ?? 0) > 0) { $hasOpvData = true; }
        if (($kpiData['target_stats']['total_target'] ?? 0) > 0) { $hasOpvData = true; }
        if (($kpiData['fiber_to_roll']['total_fiber_used'] ?? 0) > 0 || ($kpiData['fiber_to_roll']['total_roll_produced'] ?? 0) > 0) { $hasOpvData = true; }
        if (($kpiData['scrap_stats']['total_scrap_qty'] ?? 0) > 0) { $hasOpvData = true; }

        if ($hasOpvData) {
        $opvScore = (
            ($kpiData['qc_pass_rate'] ?? 0) * 0.3 +
            ($kpiData['target_achievement'] ?? 0) * 0.3 +
            ($kpiData['conversion_efficiency'] ?? 0) * 0.2 +
            ((100 - ($kpiData['recycle_rate'] ?? 0)) * 0.2) // Lower waste is better
        );
        $kpiData['opv_score'] = round($opvScore, 2);
        } else {
            $kpiData['opv_score'] = 0;
        }
        
        echo json_encode([
            'success' => true,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'data' => $kpiData
        ]);
    }
    
    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>


