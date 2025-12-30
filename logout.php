<?php
/**
 * Secure Logout Script
 * Properly destroy session and log logout
 */
session_start();

// Log logout event before destroying session
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    require_once 'audit_log.php';
    log_security_event($_SESSION['user_id'], 'logout', $_SERVER['REMOTE_ADDR'], 'User logged out');
    
    // Remove active session
    require_once 'config/security_config.php';
    $conn = SecurityConfig::getConnection();
    if ($conn) {
        // Check if active_sessions table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'active_sessions'");
        if ($table_check && $table_check->num_rows > 0) {
            $session_id = session_id();
            $stmt = $conn->prepare("UPDATE active_sessions SET is_active = 0 WHERE session_id = ?");
            if ($stmt) {
                $stmt->bind_param("s", $session_id);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        // Enterprise audit log (using existing audit_log structure) - handle both schemas
        require_once 'get_real_ip.php';
        $ip_address = getRealIPAddress();
        $logout_details = 'User logged out';
        $cols = [];
        $res = $conn->query("SHOW COLUMNS FROM audit_log");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $cols[] = strtolower($row['Field']);
            }
        }

        $user_id = $_SESSION['user_id'];
        $username = $_SESSION['username'];

        if (in_array('event_type', $cols, true)) {
        $audit_stmt = $conn->prepare("INSERT INTO audit_log (user_id, event_type, ip_address, details, who_did) VALUES (?, 'logout', ?, ?, ?)");
        if ($audit_stmt) {
            $audit_stmt->bind_param("isss", $user_id, $ip_address, $logout_details, $username);
            $audit_stmt->execute();
            $audit_stmt->close();
            }
        } else {
            $new_values = json_encode([
                'event'   => 'logout',
                'details' => $logout_details,
                'user'    => $username,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $audit_stmt = $conn->prepare("INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, changed_by, user_id, ip_address, user_agent) VALUES ('auth', NULL, 'LOGOUT', NULL, ?, ?, ?, ?, ?)");
            if ($audit_stmt) {
                $audit_stmt->bind_param("ssiss", $new_values, $username, $user_id, $ip_address, $user_agent);
                $audit_stmt->execute();
                $audit_stmt->close();
            }
        }
        
        $conn->close();
    }
}

// Clear all session variables
$_SESSION = [];

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// 
echo "<script>
        alert('You have been logged out successfully.');
        window.location.href='login.html';
      </script>";
exit;

