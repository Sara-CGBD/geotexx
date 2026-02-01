<?php
/**
 * Production Entry API
 * 
 * GET: Fetch form data (projects, operators, next roll/batch numbers)
 * POST: Submit new production entry
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
    
    // Handle GET request - fetch form data
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Fetch projects
        $projects = [];
        $res = $conn->query("SELECT id, project_name FROM projects ORDER BY project_name ASC");
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $projects[] = $r;
            }
        }
        
        // Fetch operators
        $operators = [];
        $opRes = $conn->query("SELECT id, operator_name FROM operators ORDER BY operator_name ASC");
        if ($opRes) {
            while ($op = $opRes->fetch_assoc()) {
                $operators[] = $op;
            }
        }
        
        // Determine DB name
        $dbRow = $conn->query("SELECT DATABASE() AS d")->fetch_assoc();
        $dbName = $dbRow ? $dbRow['d'] : 'geobagg';
        
        // Auto-generate next roll number
        $next_roll = 1;
        $rollCol = null;
        $rollTable = null;
        
        // Find roll source: prefer roll_entry.roll_number, else production_entry.roll_number/roll_no - using prepared statements
        $colResStmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'roll_entry'");
        if ($colResStmt) {
            $colResStmt->bind_param("s", $dbName);
            $colResStmt->execute();
            $colRes = $colResStmt->get_result();
            if ($colRes) {
                $cols = [];
                while ($cr = $colRes->fetch_assoc()) {
                    $cols[] = $cr['COLUMN_NAME'];
                }
                if (in_array('roll_number', $cols)) {
                    $rollTable = 'roll_entry';
                    $rollCol = 'roll_number';
                }
            }
            $colResStmt->close();
        }
        
        if (!$rollCol) {
            $colRes2Stmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'production_entry'");
            if ($colRes2Stmt) {
                $colRes2Stmt->bind_param("s", $dbName);
                $colRes2Stmt->execute();
                $colRes2 = $colRes2Stmt->get_result();
                if ($colRes2) {
                    $cols2 = [];
                    while ($cr = $colRes2->fetch_assoc()) {
                        $cols2[] = $cr['COLUMN_NAME'];
                    }
                    if (in_array('roll_number', $cols2)) {
                        $rollTable = 'production_entry';
                        $rollCol = 'roll_number';
                    } elseif (in_array('roll_no', $cols2)) {
                        $rollTable = 'production_entry';
                        $rollCol = 'roll_no';
                    }
                }
                $colRes2Stmt->close();
            }
        }
        
        if ($rollCol && $rollTable && preg_match('/^[A-Za-z0-9_]+$/', $rollCol) && preg_match('/^[A-Za-z0-9_]+$/', $rollTable)) {
            $q = $conn->query("SELECT MAX(`$rollCol`) AS m FROM `$rollTable`");
            if ($q && ($row = $q->fetch_assoc())) {
                $next_roll = ((int)($row['m'] ?? 0)) + 1;
            }
        }
        
        // Auto-generate next batch number - using prepared statements
        $next_batch = 1;
        $batchCol = null;
        $batchTable = null;
        
        $bColResStmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'roll_entry'");
        if ($bColResStmt) {
            $bColResStmt->bind_param("s", $dbName);
            $bColResStmt->execute();
            $bColRes = $bColResStmt->get_result();
            if ($bColRes) {
                $bcols = [];
                while ($cr = $bColRes->fetch_assoc()) {
                    $bcols[] = $cr['COLUMN_NAME'];
                }
                if (in_array('batch_number', $bcols)) {
                    $batchTable = 'roll_entry';
                    $batchCol = 'batch_number';
                }
            }
            $bColResStmt->close();
        }
        
        if (!$batchCol) {
            $bColRes2Stmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'production_entry'");
            if ($bColRes2Stmt) {
                $bColRes2Stmt->bind_param("s", $dbName);
                $bColRes2Stmt->execute();
                $bColRes2 = $bColRes2Stmt->get_result();
                if ($bColRes2) {
                    $bcols2 = [];
                    while ($cr = $bColRes2->fetch_assoc()) {
                        $bcols2[] = $cr['COLUMN_NAME'];
                    }
                    if (in_array('batch_number', $bcols2)) {
                        $batchTable = 'production_entry';
                        $batchCol = 'batch_number';
                    }
                }
                $bColRes2Stmt->close();
            }
        }
        
        if ($batchCol && $batchTable) {
            $q = $conn->query("SELECT MAX(`$batchCol`) AS m FROM `$batchTable`");
            if ($q && ($row = $q->fetch_assoc())) {
                $next_batch = ((int)($row['m'] ?? 0)) + 1;
            }
        }
        
        // Get current date/time and shift
        $current_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        $current_hour = (int)$current_time->format('H');
        $shift = ($current_hour >= 8 && $current_hour < 20) ? 'Day' : 'Night';
        
        echo json_encode([
            'success' => true,
            'projects' => $projects,
            'operators' => $operators,
            'next_roll_number' => $next_roll,
            'next_batch_number' => $next_batch,
            'current_datetime' => $current_time->format('Y-m-d H:i:s'),
            'shift' => $shift
        ]);
    }
    
    // Handle POST request - submit production entry
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required = ['project_id', 'gsm', 'line_no', 'fiber_type', 'roll_no', 'total_weight', 'batch_number', 'date_time', 'shift', 'operator_id'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
                exit;
            }
        }
        
        $operator_id = (int)$data['operator_id'];
        $project_id = (int)$data['project_id'];
        $gsm = (float)$data['gsm'];
        $line_no = substr(trim($data['line_no']), 0, 50);
        $fiber_type = substr(trim($data['fiber_type']), 0, 50);
        $roll_no = (int)$data['roll_no'];
        $total_weight = (float)$data['total_weight'];
        $batch_number = (int)$data['batch_number'];
        $date_time = $data['date_time'];
        $shift = $data['shift'];
        
        // Validate numeric values
        if ($gsm <= 0 || $total_weight <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'GSM and Total Weight must be greater than 0']);
            exit;
        }
        
        // Verify project exists
        $stmtCheck = $conn->prepare('SELECT id FROM projects WHERE id = ?');
        $stmtCheck->bind_param('i', $project_id);
        $stmtCheck->execute();
        if (!$stmtCheck->get_result()->fetch_assoc()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid project selection']);
            exit;
        }
        $stmtCheck->close();
        
        // Insert production entry
        $stmt = $conn->prepare('INSERT INTO production_entry 
            (operator_id, project_id, gsm, line_no, fiber_type, roll_number, total_weight, batch_number, date_time, shift)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $conn->error);
        }
        
        $stmt->bind_param(
            'iidssiddss',
            $operator_id,
            $project_id,
            $gsm,
            $line_no,
            $fiber_type,
            $roll_no,
            $total_weight,
            $batch_number,
            $date_time,
            $shift
        );
        
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }
        
        $entry_id = $conn->insert_id;
        $stmt->close();
        
        echo json_encode([
            'success' => true,
            'message' => 'Production entry saved successfully',
            'entry_id' => $entry_id,
            'roll_number' => $roll_no,
            'batch_number' => $batch_number
        ]);
    }
    
    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}



