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

// Role-based access control - Admin only
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if ($user_role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin role required.']);
    exit();
}

try {
    $conn = SecurityConfig::getConnection();
    date_default_timezone_set('Asia/Dhaka');
    
    // GET: Fetch security dashboard data
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $data_type = $_GET['type'] ?? 'summary';
        
        switch ($data_type) {
            case 'summary':
                // Get security summary statistics
                $stats = [];
                
                // Total active sessions
                $activeSessionsQuery = "SELECT COUNT(DISTINCT session_id) as total FROM user_sessions 
                                       WHERE is_active = 1 AND expires_at > NOW()";
                $result = $conn->query($activeSessionsQuery);
                $stats['active_sessions'] = $result->fetch_assoc()['total'] ?? 0;
                
                // Failed login attempts (last 24 hours)
                $failedLoginsQuery = "SELECT COUNT(*) as total FROM login_attempts 
                                     WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
                $result = $conn->query($failedLoginsQuery);
                $stats['failed_logins_24h'] = $result->fetch_assoc()['total'] ?? 0;
                
                // Locked accounts
                $lockedAccountsQuery = "SELECT COUNT(*) as total FROM new_user WHERE status = 'locked'";
                $result = $conn->query($lockedAccountsQuery);
                $stats['locked_accounts'] = $result->fetch_assoc()['total'] ?? 0;
                
                // Recent security events (last 24 hours)
                $securityEventsQuery = "SELECT COUNT(*) as total FROM audit_log 
                                       WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
                $result = $conn->query($securityEventsQuery);
                $stats['security_events_24h'] = $result->fetch_assoc()['total'] ?? 0;
                
                echo json_encode([
                    'success' => true,
                    'data' => $stats
                ]);
                break;
                
            case 'active_sessions':
                // Get active user sessions
                $sessionsQuery = "SELECT 
                    us.session_id,
                    us.user_id,
                    u.username,
                    u.full_name,
                    u.role,
                    us.ip_address,
                    us.user_agent,
                    us.created_at,
                    us.last_activity,
                    us.expires_at
                FROM user_sessions us
                LEFT JOIN new_user u ON us.user_id = u.id
                WHERE us.is_active = 1 AND us.expires_at > NOW()
                ORDER BY us.last_activity DESC";
                
                $result = $conn->query($sessionsQuery);
                $sessions = [];
                while ($row = $result->fetch_assoc()) {
                    $sessions[] = $row;
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => $sessions,
                    'total' => count($sessions)
                ]);
                break;
                
            case 'failed_logins':
                // Get recent failed login attempts
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
                
                $failedLoginsQuery = "SELECT 
                    username,
                    ip_address,
                    user_agent,
                    created_at,
                    failure_reason
                FROM login_attempts
                WHERE success = 0
                ORDER BY created_at DESC
                LIMIT ?";
                
                $stmt = $conn->prepare($failedLoginsQuery);
                $stmt->bind_param('i', $limit);
                $stmt->execute();
                $result = $stmt->get_result();
                
                $failed_logins = [];
                while ($row = $result->fetch_assoc()) {
                    $failed_logins[] = $row;
                }
                $stmt->close();
                
                echo json_encode([
                    'success' => true,
                    'data' => $failed_logins,
                    'total' => count($failed_logins)
                ]);
                break;
                
            case 'audit_log':
                // Get recent audit log entries
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
                $action_type = $_GET['action_type'] ?? null;
                
                $query = "SELECT 
                    al.id,
                    al.user_id,
                    u.username,
                    al.action_type,
                    al.action_details,
                    al.ip_address,
                    al.created_at
                FROM audit_log al
                LEFT JOIN new_user u ON al.user_id = u.id";
                
                if ($action_type) {
                    $query .= " WHERE al.action_type = ?";
                }
                
                $query .= " ORDER BY al.created_at DESC LIMIT ?";
                
                $stmt = $conn->prepare($query);
                if ($action_type) {
                    $stmt->bind_param('si', $action_type, $limit);
                } else {
                    $stmt->bind_param('i', $limit);
                }
                $stmt->execute();
                $result = $stmt->get_result();
                
                $audit_entries = [];
                while ($row = $result->fetch_assoc()) {
                    $audit_entries[] = $row;
                }
                $stmt->close();
                
                echo json_encode([
                    'success' => true,
                    'data' => $audit_entries,
                    'total' => count($audit_entries)
                ]);
                break;
                
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid data type']);
        }
    }
    
    // POST: Perform security actions
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
        
        switch ($action) {
            case 'terminate_session':
                if (!isset($input['session_id'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Session ID required']);
                    exit();
                }
                
                $session_id = $input['session_id'];
                $stmt = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE session_id = ?");
                $stmt->bind_param('s', $session_id);
                
                if ($stmt->execute()) {
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Session terminated successfully']);
                } else {
                    throw new Exception('Failed to terminate session');
                }
                break;
                
            case 'unlock_account':
                if (!isset($input['user_id'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'User ID required']);
                    exit();
                }
                
                $user_id = (int)$input['user_id'];
                $stmt = $conn->prepare("UPDATE new_user SET status = 'active' WHERE id = ?");
                $stmt->bind_param('i', $user_id);
                
                if ($stmt->execute()) {
                    $stmt->close();
                    echo json_encode(['success' => true, 'message' => 'Account unlocked successfully']);
                } else {
                    throw new Exception('Failed to unlock account');
                }
                break;
                
            case 'clear_failed_logins':
                $hours = isset($input['hours']) ? (int)$input['hours'] : 24;
                $stmt = $conn->prepare("DELETE FROM login_attempts WHERE success = 0 AND created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)");
                $stmt->bind_param('i', $hours);
                
                if ($stmt->execute()) {
                    $affected_rows = $stmt->affected_rows;
                    $stmt->close();
                    echo json_encode([
                        'success' => true, 
                        'message' => "Cleared $affected_rows failed login records"
                    ]);
                } else {
                    throw new Exception('Failed to clear failed logins');
                }
                break;
                
            default:
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
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


