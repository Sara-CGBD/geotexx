<?php
/**
 * Fiber to Roll Production Report API
 * 
 * GET: Fetch fiber to roll production data with filters
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

if (SecurityConfig::checkSessionTimeout()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expired']);
    exit;
}

SecurityConfig::updateSessionActivity();

if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Account is locked']);
    exit;
}

date_default_timezone_set('Asia/Dhaka');

try {
    $conn = SecurityConfig::getConnection();
    
    // Get date range filters
    $start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default to first day of current month
    $end_date = $_GET['end_date'] ?? date('Y-m-d'); // Default to today
    
    // Ensure tables exist
    $conn->query("CREATE TABLE IF NOT EXISTS fiber_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date_time DATETIME NOT NULL,
        operator_id INT NOT NULL,
        project_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        bale_weight INT NOT NULL,
        bale_number VARCHAR(100) NOT NULL,
        origin VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    $conn->query("CREATE TABLE IF NOT EXISTS fiber_to_roll_entry (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date_time DATETIME NOT NULL,
        operator_id INT NOT NULL,
        project_id INT NOT NULL,
        bale_opener_number VARCHAR(100) NOT NULL,
        bale_number VARCHAR(100) NOT NULL,
        bale_weight INT NOT NULL,
        gsm INT NOT NULL,
        line_no VARCHAR(50) NOT NULL,
        fiber_type VARCHAR(100) NOT NULL,
        origin VARCHAR(100) NOT NULL,
        roll_number INT NOT NULL,
        total_weight DECIMAL(10,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Get fiber entries data
    $fiberQuery = "SELECT * FROM fiber_entries WHERE DATE(date_time) BETWEEN ? AND ? ORDER BY date_time DESC";
    $fiberStmt = $conn->prepare($fiberQuery);
    $fiberStmt->bind_param('ss', $start_date, $end_date);
    $fiberStmt->execute();
    $fiberResult = $fiberStmt->get_result();
    $fiberEntries = [];
    while ($row = $fiberResult->fetch_assoc()) {
        $fiberEntries[] = $row;
    }
    $fiberStmt->close();
    
    // Get fiber to roll conversion data
    $conversionQuery = "SELECT * FROM fiber_to_roll_entry WHERE DATE(date_time) BETWEEN ? AND ? ORDER BY date_time DESC";
    $conversionStmt = $conn->prepare($conversionQuery);
    $conversionStmt->bind_param('ss', $start_date, $end_date);
    $conversionStmt->execute();
    $conversionResult = $conversionStmt->get_result();
    $conversionEntries = [];
    while ($row = $conversionResult->fetch_assoc()) {
        $conversionEntries[] = $row;
    }
    $conversionStmt->close();
    
    // Calculate summary statistics
    $totalFiberEntries = count($fiberEntries);
    $totalFiberWeight = array_sum(array_column($fiberEntries, 'amount'));
    $totalConversionEntries = count($conversionEntries);
    $totalRollWeight = array_sum(array_column($conversionEntries, 'total_weight'));
    
    // Group by origin
    $fiberByOrigin = [];
    foreach ($fiberEntries as $entry) {
        $origin = $entry['origin'];
        if (!isset($fiberByOrigin[$origin])) {
            $fiberByOrigin[$origin] = ['count' => 0, 'weight' => 0];
        }
        $fiberByOrigin[$origin]['count']++;
        $fiberByOrigin[$origin]['weight'] += $entry['amount'];
    }
    
    $conversionByOrigin = [];
    foreach ($conversionEntries as $entry) {
        $origin = $entry['origin'];
        if (!isset($conversionByOrigin[$origin])) {
            $conversionByOrigin[$origin] = ['count' => 0, 'weight' => 0];
        }
        $conversionByOrigin[$origin]['count']++;
        $conversionByOrigin[$origin]['weight'] += $entry['total_weight'];
    }
    
    // Group by date
    $fiberByDate = [];
    foreach ($fiberEntries as $entry) {
        $date = date('Y-m-d', strtotime($entry['date_time']));
        if (!isset($fiberByDate[$date])) {
            $fiberByDate[$date] = ['count' => 0, 'weight' => 0];
        }
        $fiberByDate[$date]['count']++;
        $fiberByDate[$date]['weight'] += $entry['amount'];
    }
    
    $conversionByDate = [];
    foreach ($conversionEntries as $entry) {
        $date = date('Y-m-d', strtotime($entry['date_time']));
        if (!isset($conversionByDate[$date])) {
            $conversionByDate[$date] = ['count' => 0, 'weight' => 0];
        }
        $conversionByDate[$date]['count']++;
        $conversionByDate[$date]['weight'] += $entry['total_weight'];
    }
    
    // Calculate conversion efficiency
    $conversionEfficiency = $totalFiberWeight > 0 ? round(($totalRollWeight / $totalFiberWeight) * 100, 2) : 0;
    
    echo json_encode([
        'success' => true,
        'fiber_entries' => $fiberEntries,
        'conversion_entries' => $conversionEntries,
        'summary' => [
            'total_fiber_entries' => $totalFiberEntries,
            'total_fiber_weight' => $totalFiberWeight,
            'total_conversion_entries' => $totalConversionEntries,
            'total_roll_weight' => $totalRollWeight,
            'conversion_efficiency' => $conversionEfficiency,
            'fiber_by_origin' => $fiberByOrigin,
            'conversion_by_origin' => $conversionByOrigin,
            'fiber_by_date' => $fiberByDate,
            'conversion_by_date' => $conversionByDate
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}


