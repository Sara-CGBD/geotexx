<?php
// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();

// ✅ Set timezone to Asia/Dhaka (GMT+6) to ensure correct time display
date_default_timezone_set('Asia/Dhaka');

// Debug session info (temporary)
error_log("Security Dashboard - Session Check: " . print_r([
    'user_id' => $_SESSION['user_id'] ?? 'NOT SET',
    'username' => $_SESSION['username'] ?? 'NOT SET',
    'role' => $_SESSION['role'] ?? 'NOT SET'
], true));

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
  error_log("Security Dashboard - Redirecting to login: Session not set");
  header("Location: ../login.html");
  exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if ($user_role !== 'admin') {
  error_log("Security Dashboard - Access denied for role: " . $user_role);
  http_response_code(403);
  die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
      <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
      <p>Only administrators can access the Security Dashboard.</p>
      <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
      </div>");
}

// Include security configuration
require_once '../config/security_config.php';

// Database connection using SecurityConfig
$conn = SecurityConfig::getConnection();

// Function to parse user agent string
function parseUserAgent($user_agent) {
    $browser = 'Unknown';
    $os = 'Unknown';
    $icon = 'fas fa-desktop';
    
    if (empty($user_agent)) {
        return ['browser' => $browser, 'os' => $os, 'icon' => $icon];
    }
    
    // Detect browser
    if (strpos($user_agent, 'Chrome') !== false) {
        $browser = 'Chrome';
        $icon = 'fab fa-chrome';
    } elseif (strpos($user_agent, 'Firefox') !== false) {
        $browser = 'Firefox';
        $icon = 'fab fa-firefox-browser';
    } elseif (strpos($user_agent, 'Safari') !== false) {
        $browser = 'Safari';
        $icon = 'fab fa-safari';
    } elseif (strpos($user_agent, 'Edge') !== false) {
        $browser = 'Edge';
        $icon = 'fab fa-edge';
    } elseif (strpos($user_agent, 'Opera') !== false) {
        $browser = 'Opera';
        $icon = 'fab fa-opera';
    } elseif (strpos($user_agent, 'MSIE') !== false || strpos($user_agent, 'Trident') !== false) {
        $browser = 'Internet Explorer';
        $icon = 'fab fa-internet-explorer';
    }
    
    // Detect operating system
    if (strpos($user_agent, 'Windows') !== false) {
        $os = 'Windows';
        $icon = 'fab fa-windows';
    } elseif (strpos($user_agent, 'Mac') !== false) {
        $os = 'macOS';
        $icon = 'fab fa-apple';
    } elseif (strpos($user_agent, 'Linux') !== false) {
        $os = 'Linux';
        $icon = 'fab fa-linux';
    } elseif (strpos($user_agent, 'Android') !== false) {
        $os = 'Android';
        $icon = 'fas fa-mobile-alt';
    } elseif (strpos($user_agent, 'iPhone') !== false || strpos($user_agent, 'iPad') !== false) {
        $os = 'iOS';
        $icon = 'fab fa-apple';
    }
    
    return ['browser' => $browser, 'os' => $os, 'icon' => $icon];
}

// Handle security actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'unlock_all':
            $conn->query("UPDATE new_user SET wrong_attempts = 0, lock_until = NULL");
            $_SESSION['security_msg'] = '<div class="alert alert-success">✅ All accounts unlocked successfully.</div>';
            break;
            
        case 'reset_attempts':
            $conn->query("UPDATE new_user SET wrong_attempts = 0");
            $_SESSION['security_msg'] = '<div class="alert alert-success">✅ All wrong attempts reset successfully.</div>';
            break;
            
        case 'enable_all':
            $conn->query("UPDATE new_user SET is_active = 1");
            $_SESSION['security_msg'] = '<div class="alert alert-success">✅ All accounts enabled successfully.</div>';
            break;
            
        case 'clear_locks':
            $conn->query("UPDATE new_user SET lock_until = NULL");
            $_SESSION['security_msg'] = '<div class="alert alert-success">✅ All account locks cleared successfully.</div>';
            break;
            
        case 'security_audit':
            // Generate security audit report
            $audit_data = generateSecurityAudit($conn);
            $_SESSION['audit_data'] = $audit_data;
            $_SESSION['security_msg'] = '<div class="alert alert-info">📊 Security audit generated successfully.</div>';
            break;
            
        case 'unlock_user':
            $username = $_POST['username'] ?? '';
            if ($username) {
                $stmt = $conn->prepare("UPDATE new_user SET wrong_attempts = 0, lock_until = NULL WHERE username = ?");
                $stmt->bind_param("s", $username);
                if ($stmt->execute()) {
                    $_SESSION['security_msg'] = '<div class="alert alert-success">✅ User ' . htmlspecialchars($username) . ' unlocked successfully.</div>';
                } else {
                    $_SESSION['security_msg'] = '<div class="alert alert-danger">❌ Error unlocking user.</div>';
                }
            }
            break;
            
        case 'force_logout':
            $username = $_POST['username'] ?? '';
            if ($username) {
                // Force logout by deactivating all sessions for this user
                $stmt = $conn->prepare("UPDATE active_sessions SET is_active = 0 WHERE username = ?");
                $stmt->bind_param("s", $username);
                if ($stmt->execute()) {
                    $_SESSION['security_msg'] = '<div class="alert alert-success">✅ User ' . htmlspecialchars($username) . ' force logged out successfully.</div>';
                } else {
                    $_SESSION['security_msg'] = '<div class="alert alert-danger">❌ Error force logging out user.</div>';
                }
            }
            break;
    }
    
    header('Location: security_dashboard.php');
    exit();
}

// Function to generate security audit
function generateSecurityAudit($conn) {
    $audit = [];
    
    // Total users
    $result = $conn->query("SELECT COUNT(*) as total FROM new_user");
    $audit['total_users'] = $result->fetch_assoc()['total'];
    
    // Active users
    $result = $conn->query("SELECT COUNT(*) as active FROM new_user WHERE is_active = 1");
    $audit['active_users'] = $result->fetch_assoc()['active'];
    
    // Locked users
    $current_time = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("SELECT COUNT(*) as locked FROM new_user WHERE lock_until IS NOT NULL AND lock_until > ?");
    $stmt->bind_param("s", $current_time);
    $stmt->execute();
    $audit['locked_users'] = $stmt->get_result()->fetch_assoc()['locked'];
    
    // Disabled users
    $result = $conn->query("SELECT COUNT(*) as disabled FROM new_user WHERE is_active = 0");
    $audit['disabled_users'] = $result->fetch_assoc()['disabled'];
    
    // Users with wrong attempts
    $result = $conn->query("SELECT COUNT(*) as wrong_attempts FROM new_user WHERE wrong_attempts > 0");
    $audit['users_with_wrong_attempts'] = $result->fetch_assoc()['wrong_attempts'];
    
    // Recent login activity
    $stmt = $conn->prepare("SELECT COUNT(*) as recent_logins FROM new_user WHERE last_login > DATE_SUB(?, INTERVAL 24 HOUR)");
    $stmt->bind_param("s", $current_time);
    $stmt->execute();
    $audit['recent_logins'] = $stmt->get_result()->fetch_assoc()['recent_logins'];
    
    // Security threats
    $result = $conn->query("SELECT COUNT(*) as threats FROM new_user WHERE wrong_attempts >= 3");
    $audit['security_threats'] = $result->fetch_assoc()['threats'];
    
    // User activity in last 7 days
    $stmt = $conn->prepare("SELECT COUNT(*) as active_week FROM new_user WHERE last_activity > DATE_SUB(?, INTERVAL 7 DAY)");
    $stmt->bind_param("s", $current_time);
    $stmt->execute();
    $audit['active_week'] = $stmt->get_result()->fetch_assoc()['active_week'];
    
    // Users created in last 30 days
    $stmt = $conn->prepare("SELECT COUNT(*) as new_users FROM new_user WHERE created_at > DATE_SUB(?, INTERVAL 30 DAY)");
    $stmt->bind_param("s", $current_time);
    $stmt->execute();
    $audit['new_users'] = $stmt->get_result()->fetch_assoc()['new_users'];
    
    // High-risk users (5+ wrong attempts)
    $result = $conn->query("SELECT COUNT(*) as high_risk FROM new_user WHERE wrong_attempts >= 5");
    $audit['high_risk_users'] = $result->fetch_assoc()['high_risk'];
    
    // Inactive users (no activity in 30 days)
    $stmt = $conn->prepare("SELECT COUNT(*) as inactive FROM new_user WHERE last_activity < DATE_SUB(?, INTERVAL 30 DAY) OR last_activity IS NULL");
    $stmt->bind_param("s", $current_time);
    $stmt->execute();
    $audit['inactive_users'] = $stmt->get_result()->fetch_assoc()['inactive'];
    
    // Currently active users
    $result = $conn->query("SELECT COUNT(*) as currently_active FROM active_sessions WHERE is_active = 1");
    $audit['currently_active'] = $result->fetch_assoc()['currently_active'];
    
    // Idle users (no activity for more than 15 minutes)
    $stmt = $conn->prepare("SELECT COUNT(*) as idle_users FROM active_sessions WHERE is_active = 1 AND last_activity < DATE_SUB(?, INTERVAL 15 MINUTE)");
    $stmt->bind_param("s", $current_time);
    $stmt->execute();
    $audit['idle_users'] = $stmt->get_result()->fetch_assoc()['idle_users'];
    
    // Device statistics
    $device_result = $conn->query("SELECT user_agent FROM active_sessions WHERE is_active = 1");
    $browsers = [];
    $operating_systems = [];
    
    while ($row = $device_result->fetch_assoc()) {
        $device_info = parseUserAgent($row['user_agent']);
        $browsers[$device_info['browser']] = ($browsers[$device_info['browser']] ?? 0) + 1;
        $operating_systems[$device_info['os']] = ($operating_systems[$device_info['os']] ?? 0) + 1;
    }
    
    $audit['browser_stats'] = $browsers;
    $audit['os_stats'] = $operating_systems;
    
    return $audit;
}

// Get current security status
$security_status = [
    'total_users' => 0,
    'active_users' => 0,
    'locked_users' => 0,
    'security_threats' => 0,
    'disabled_users' => 0,
    'users_with_wrong_attempts' => 0,
    'recent_logins' => 0
];

try {
    // First, ensure the required columns exist in new_user table
    $conn->query("ALTER TABLE new_user 
                  ADD COLUMN IF NOT EXISTS last_activity TIMESTAMP NULL DEFAULT NULL,
                  ADD COLUMN IF NOT EXISTS last_login TIMESTAMP NULL DEFAULT NULL,
                  ADD COLUMN IF NOT EXISTS wrong_attempts INT DEFAULT 0,
                  ADD COLUMN IF NOT EXISTS lock_until TIMESTAMP NULL DEFAULT NULL,
                  ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'active'");

    // Update current user's last_activity to NOW() (since they're currently logged in) - using prepared statement
    $current_user = $_SESSION['username'];
    $updateStmt = $conn->prepare("UPDATE new_user SET 
                  last_activity = NOW(), 
                  last_login = NOW() 
                  WHERE username = ?");
    if ($updateStmt) {
        $updateStmt->bind_param("s", $current_user);
        $updateStmt->execute();
        $updateStmt->close();
    }

    // Total users
    $result = $conn->query("SELECT COUNT(*) as count FROM new_user");
    if ($result) {
        $row = $result->fetch_assoc();
        $security_status['total_users'] = $row['count'];
    }

    // Active users (logged in within last 30 minutes)
    $result = $conn->query("SELECT COUNT(*) as count FROM new_user WHERE 
                           last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE) 
                           AND last_activity IS NOT NULL");
    if ($result) {
        $row = $result->fetch_assoc();
        $security_status['active_users'] = $row['count'];
    }

    // Currently active users (same as active users)
    $security_status['currently_active'] = $security_status['active_users'];

    // Locked users
    $result = $conn->query("SELECT COUNT(*) as count FROM new_user WHERE 
                           lock_until IS NOT NULL AND lock_until > NOW()");
    if ($result) {
        $row = $result->fetch_assoc();
        $security_status['locked_users'] = $row['count'];
    }

    // Users with wrong attempts
    $result = $conn->query("SELECT COUNT(*) as count FROM new_user WHERE wrong_attempts > 0");
    if ($result) {
        $row = $result->fetch_assoc();
        $security_status['users_with_wrong_attempts'] = $row['count'];
    }

    // Disabled users
    $result = $conn->query("SELECT COUNT(*) as count FROM new_user WHERE status = 'disabled'");
    if ($result) {
        $row = $result->fetch_assoc();
        $security_status['disabled_users'] = $row['count'];
    }

    // Recent logins (last 24 hours)
    $result = $conn->query("SELECT COUNT(*) as count FROM new_user WHERE 
                           last_login > DATE_SUB(NOW(), INTERVAL 24 HOUR) 
                           AND last_login IS NOT NULL");
    if ($result) {
        $row = $result->fetch_assoc();
        $security_status['recent_logins'] = $row['count'];
    }

    // Security threats (locked + high wrong attempts)
    $security_status['security_threats'] = $security_status['locked_users'] + $security_status['users_with_wrong_attempts'];

    // Get browser and OS statistics
    $device_result = $conn->query("SELECT user_agent FROM active_sessions WHERE is_active = 1");
    $browsers = [];
    $operating_systems = [];
    
    if ($device_result) {
        while ($row = $device_result->fetch_assoc()) {
            $device_info = parseUserAgent($row['user_agent']);
            $browsers[$device_info['browser']] = ($browsers[$device_info['browser']] ?? 0) + 1;
            $operating_systems[$device_info['os']] = ($operating_systems[$device_info['os']] ?? 0) + 1;
        }
    }
    
    $security_status['browser_stats'] = $browsers;
    $security_status['os_stats'] = $operating_systems;

} catch (Exception $e) {
    error_log("Security Dashboard Error: " . $e->getMessage());
    $security_status['browser_stats'] = [];
    $security_status['os_stats'] = [];
}


// Get recent security events
$recent_events = [];
try {
    $result = $conn->query("SELECT username, wrong_attempts, lock_until, last_login, last_activity 
                           FROM new_user 
                           WHERE wrong_attempts > 0 OR lock_until IS NOT NULL 
                           ORDER BY last_activity DESC 
                           LIMIT 10");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $recent_events[] = $row;
        }
        error_log("Recent Security Events found: " . count($recent_events));
    } else {
        error_log("Recent Events Query Failed: " . $conn->error);
    }
} catch (Exception $e) {
    error_log("Recent Events Error: " . $e->getMessage());
}

// Debug output (temporary)
echo "<!-- Debug: Recent Events Count: " . count($recent_events) . " -->\n";
if (count($recent_events) > 0) {
    echo "<!-- Debug: First Event: " . htmlspecialchars(json_encode($recent_events[0])) . " -->\n";
}

// Clean up expired sessions (optional - comment out if causing issues)
// require_once 'session_cleanup.php';
// cleanupExpiredSessions();

// Get and clear flash message
$security_msg = isset($_SESSION['security_msg']) ? $_SESSION['security_msg'] : '';
unset($_SESSION['security_msg']);

$audit_data = isset($_SESSION['audit_data']) ? $_SESSION['audit_data'] : null;
unset($_SESSION['audit_data']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Security Dashboard - Admin Panel</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    body { background: #f4f6f9; font-family: 'Inter', sans-serif; }
    .container { max-width: 1400px; margin: 24px auto; }
    /* Compact cards */
    .security-card {
      background: #fff;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.06);
      padding: 12px 14px;
      margin-bottom: 12px;
    }
    /* Compact metric tiles */
    .metric-card {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      border-radius: 8px;
      padding: 10px 12px;
      text-align: center;
      margin-bottom: 12px;
      min-height: 78px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
    }
    .metric-value {
      font-size: 1.6rem;
      font-weight: 700;
      line-height: 1.1;
      margin-bottom: 4px;
    }
    .metric-label {
      font-size: 0.85rem;
      opacity: 0.95;
      white-space: nowrap;
    }
    .threat-high { background: linear-gradient(135deg, #ff6b6b, #ee5a52); }
    .threat-medium { background: linear-gradient(135deg, #feca57, #ff9ff3); }
    .threat-low { background: linear-gradient(135deg, #48dbfb, #0abde3); }
    .status-badge {
      padding: 2px 8px;
      border-radius: 14px;
      font-size: 0.75rem;
      font-weight: 600;
    }
    .status-safe { background: #d4edda; color: #155724; }
    .status-warning { background: #fff3cd; color: #856404; }
    .status-danger { background: #f8d7da; color: #721c24; }
    .action-btn { margin: 4px; border-radius: 6px; font-weight: 600; padding: 6px 10px; font-size: .9rem; }
    .device-info { text-align: center; font-size: 0.8rem; }
    .device-info i { font-size: 1.1rem; margin-bottom: 2px; display: block; }
    .table th { background: #f8f9fa; border-top: none; font-weight: 600; padding: 8px 10px; }
    .table td { padding: 8px 10px; }
    h1 { color: #2c3e50; margin-bottom: 16px; font-size: 1.6rem; }
    h4 { color: #34495e; margin-bottom: 12px; font-size: 1.05rem; }
    /* Tighten rows */
    .row { --bs-gutter-x: 12px; --bs-gutter-y: 12px; }
  </style>
</head>
<body>
<div class="container">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h1><i class="fas fa-shield-alt"></i> Security Dashboard</h1>
    <a href="user_management_new_user.php" class="btn btn-primary" target="_parent">
      <i class="fas fa-users"></i> User Management
    </a>
  </div>

 
  <?= $security_msg ?>

  <!-- Security Metrics (compact tiles) -->
  <div class="row">
    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
      <div class="metric-card">
        <div class="metric-value"><?= $security_status['total_users'] ?></div>
        <div class="metric-label">Total Users</div>
      </div>
    </div>
    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
      <div class="metric-card threat-low">
        <div class="metric-value"><?= $security_status['active_users'] ?></div>
        <div class="metric-label">Active Users</div>
      </div>
    </div>
    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
      <div class="metric-card threat-medium">
        <div class="metric-value"><?= $security_status['locked_users'] ?></div>
        <div class="metric-label">Locked Accounts</div>
      </div>
    </div>
    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
      <div class="metric-card threat-high">
        <div class="metric-value"><?= $security_status['security_threats'] ?></div>
        <div class="metric-label">Security Threats</div>
      </div>
    </div>
    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
      <div class="metric-card" style="background: linear-gradient(135deg, #4CAF50, #45a049);">
        <div class="metric-value"><?= $security_status['currently_active'] ?? 0 ?></div>
        <div class="metric-label">Currently Active</div>
      </div>
    </div>
    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
      <div class="metric-card" style="background: linear-gradient(135deg, #FF9800, #F57C00);">
        <div class="metric-value"><?= $security_status['idle_users'] ?? max(0, ($security_status['total_users'] ?? 0) - ($security_status['active_users'] ?? 0)) ?></div>
        <div class="metric-label">Idle Users</div>
      </div>
    </div>
    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
      <div class="metric-card" style="background: linear-gradient(135deg, #2196F3, #1976D2);">
        <div class="metric-value"><?= $security_status['recent_logins'] ?></div>
        <div class="metric-label">Recent Logins (24h)</div>
      </div>
    </div>
  </div>

  

  <!-- Security Overview (compact table) -->
  <div class="security-card">
    <h4><i class="fas fa-chart-pie"></i> Security Overview</h4>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <tbody>
          <tr>
            <th style="width:40%">Active Users</th>
            <td><span class="status-badge status-safe"><?= $security_status['active_users'] ?></span></td>
          </tr>
          <tr>
            <th>Disabled Users</th>
            <td><span class="status-badge status-warning"><?= $security_status['disabled_users'] ?></span></td>
          </tr>
          <tr>
            <th>Locked Accounts</th>
            <td><span class="status-badge status-danger"><?= $security_status['locked_users'] ?></span></td>
          </tr>
          <tr>
            <th>Wrong Attempts</th>
            <td><span class="status-badge status-warning"><?= $security_status['users_with_wrong_attempts'] ?></span></td>
          </tr>
          <tr>
            <th>Recent Logins (24h)</th>
            <td><span class="status-badge status-safe"><?= $security_status['recent_logins'] ?></span></td>
          </tr>
          <tr>
            <th>Security Threats</th>
            <td><span class="status-badge status-danger"><?= $security_status['security_threats'] ?></span></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Security Actions (compact vertical) -->
  <div class="security-card">
    <h4><i class="fas fa-tools"></i> Security Actions</h4>
    <form method="POST" class="d-grid gap-2">
      <button type="submit" name="action" value="unlock_all" class="btn btn-success action-btn">
        <i class="fas fa-unlock"></i> Unlock All Accounts
      </button>
      <button type="submit" name="action" value="reset_attempts" class="btn btn-warning action-btn">
        <i class="fas fa-undo"></i> Reset All Wrong Attempts
      </button>
      <button type="submit" name="action" value="enable_all" class="btn btn-primary action-btn">
        <i class="fas fa-check-circle"></i> Enable All Accounts
      </button>
      <button type="submit" name="action" value="clear_locks" class="btn btn-info action-btn">
        <i class="fas fa-broom"></i> Clear All Locks
      </button>
      <a href="security_audit_report.php" target="_parent" class="btn btn-secondary action-btn">
        <i class="fas fa-file-alt"></i> Generate Security Audit
      </a>
    </form>
  </div>

  <!-- Recent Security Events -->
  <div class="security-card">
    <h4><i class="fas fa-exclamation-triangle"></i> Recent Security Events</h4>
    <div class="table-responsive">
      <table class="table table-hover">
        <thead class="table-light">
          <tr>
            <th>Username</th>
            <th>Total Failed Attempts</th>
            <th>Account Status</th>
            <th>Last Failed Attempt</th>
            <th>Last Successful Login</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          // Get recent FAILED login attempts from audit log
          $audit_query = "SELECT la.username, 
                         MAX(la.attempt_time) as last_failed_attempt,
                         la.ip_address, 
                         nu.wrong_attempts,
                         nu.lock_until,
                         nu.last_login,
                         COUNT(*) as total_failed_attempts
                         FROM login_attempts la
                         LEFT JOIN new_user nu ON nu.username COLLATE utf8mb4_unicode_ci = la.username COLLATE utf8mb4_unicode_ci
                         WHERE la.success = 0 
                         GROUP BY la.username
                         ORDER BY MAX(la.attempt_time) DESC 
                         LIMIT 20";
          $audit_result = $conn->query($audit_query);
          
          if ($audit_result && $audit_result->num_rows > 0):
            while ($event = $audit_result->fetch_assoc()): 
          ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($event['username']); ?></strong></td>
              <td>
                <span class="badge bg-<?php echo $event['total_failed_attempts'] >= 5 ? 'danger' : ($event['total_failed_attempts'] >= 3 ? 'warning' : 'info'); ?>">
                  <?php echo $event['total_failed_attempts']; ?> failed attempt(s)
                </span>
              </td>
              <td>
                <?php 
                $currentAttempts = $event['wrong_attempts'] ?? 0;
                if (!empty($event['lock_until']) && strtotime($event['lock_until']) > time()): 
                ?>
                  <span class="badge bg-danger"><i class="fas fa-lock"></i> Locked</span>
                <?php elseif ($currentAttempts > 0): ?>
                  <span class="badge bg-warning"><i class="fas fa-exclamation-triangle"></i> <?php echo $currentAttempts; ?> pending attempt(s)</span>
                <?php else: ?>
                  <span class="badge bg-success"><i class="fas fa-check-circle"></i> Active</span>
                <?php endif; ?>
              </td>
              <td><?php echo !empty($event['last_failed_attempt']) ? date('M j, Y g:i A', strtotime($event['last_failed_attempt'])) : 'Never'; ?></td>
              <td><?php echo !empty($event['last_login']) ? date('M j, Y g:i A', strtotime($event['last_login'])) : 'Never'; ?></td>
              <td>
                <a href="user_management_new_user.php" class="btn btn-sm btn-primary" target="_parent">Manage</a>
              </td>
            </tr>
          <?php 
            endwhile;
          else: 
          ?>
            <tr>
              <td colspan="6" class="text-center text-muted">No recent security events</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Users with Active Security Issues -->
  <div class="security-card">
    <h4><i class="fas fa-exclamation-triangle"></i> Users with Security Issues</h4>
    <p class="text-muted mb-3">
      <small>Users currently locked or with failed login attempts. Found: <strong><?php echo count($recent_events); ?></strong> user(s)</small>
    </p>
    <div class="table-responsive">
      <table class="table table-hover">
        <thead class="table-light">
          <tr>
            <th>Username</th>
            <th>Wrong Attempts</th>
            <th>Lock Status</th>
            <th>Last Login</th>
            <th>Last Activity</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($recent_events) > 0): ?>
            <?php foreach ($recent_events as $event): ?>
            <tr>
              <td><strong><?php echo htmlspecialchars($event['username']); ?></strong></td>
              <td>
                <span class="badge bg-<?php echo $event['wrong_attempts'] >= 5 ? 'danger' : ($event['wrong_attempts'] >= 3 ? 'warning' : 'info'); ?>">
                  <?php echo $event['wrong_attempts']; ?>
                </span>
              </td>
              <td>
                <?php if (!empty($event['lock_until']) && strtotime($event['lock_until']) > time()): ?>
                  <span class="badge bg-danger">Locked</span>
                <?php else: ?>
                  <span class="badge bg-success">Active</span>
                <?php endif; ?>
              </td>
              <td><?php echo !empty($event['last_login']) ? date('M j, Y g:i A', strtotime($event['last_login'])) : 'Never'; ?></td>
              <td><?php echo !empty($event['last_activity']) ? date('M j, Y g:i A', strtotime($event['last_activity'])) : 'Never'; ?></td>
              <td>
                <a href="user_management_new_user.php" class="btn btn-sm btn-primary" target="_parent">Manage</a>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" class="text-center text-success">
                <i class="fas fa-shield-alt"></i> No security issues - All accounts are secure!
              </td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Security Audit Report -->
  <?php if ($audit_data): ?>
  <div class="security-card">
    <h4><i class="fas fa-file-alt"></i> Security Audit Report</h4>
    <div class="row">
      <div class="col-md-4">
        <h5>User Statistics</h5>
        <ul class="list-group">
          <li class="list-group-item d-flex justify-content-between">
            <span>Total Users:</span>
            <strong><?= $audit_data['total_users'] ?></strong>
          </li>
          <li class="list-group-item d-flex justify-content-between">
            <span>Active Users:</span>
            <strong class="text-success"><?= $audit_data['active_users'] ?></strong>
          </li>
          <li class="list-group-item d-flex justify-content-between">
            <span>Disabled Users:</span>
            <strong class="text-danger"><?= $audit_data['disabled_users'] ?></strong>
          </li>
          <li class="list-group-item d-flex justify-content-between">
            <span>New Users (30d):</span>
            <strong class="text-info"><?= $audit_data['new_users'] ?></strong>
          </li>
        </ul>
      </div>
      <div class="col-md-4">
        <h5>Security Status</h5>
        <ul class="list-group">
          <li class="list-group-item d-flex justify-content-between">
            <span>Locked Accounts:</span>
            <strong class="text-danger"><?= $audit_data['locked_users'] ?></strong>
          </li>
          <li class="list-group-item d-flex justify-content-between">
            <span>Security Threats:</span>
            <strong class="text-warning"><?= $audit_data['security_threats'] ?></strong>
          </li>
          <li class="list-group-item d-flex justify-content-between">
            <span>High Risk Users:</span>
            <strong class="text-danger"><?= $audit_data['high_risk_users'] ?></strong>
          </li>
          <li class="list-group-item d-flex justify-content-between">
            <span>Recent Logins (24h):</span>
            <strong class="text-success"><?= $audit_data['recent_logins'] ?></strong>
          </li>
        </ul>
      </div>
      <div class="col-md-4">
        <h5>Activity Metrics</h5>
        <ul class="list-group">
          <li class="list-group-item d-flex justify-content-between">
            <span>Active This Week:</span>
            <strong class="text-success"><?= $audit_data['active_week'] ?></strong>
          </li>
          <li class="list-group-item d-flex justify-content-between">
            <span>Inactive Users:</span>
            <strong class="text-warning"><?= $audit_data['inactive_users'] ?></strong>
          </li>
          <li class="list-group-item d-flex justify-content-between">
            <span>Wrong Attempts:</span>
            <strong class="text-warning"><?= $audit_data['users_with_wrong_attempts'] ?></strong>
          </li>
        </ul>
      </div>
    </div>
  </div>
  <?php endif; ?>
   <!-- Security Tools (placed right below Recent Security Events) -->
   <div class="security-card">
    <h4><i class="fas fa-wrench"></i> Security Tools</h4>
    <div class="d-flex flex-column gap-2">
      <div class="p-3 border rounded-3">
        <h5 class="mb-1"><i class="fas fa-users-cog"></i> User Management</h5>
        <p class="text-muted mb-2">Manage user accounts, passwords, and security settings</p>
        <a href="user_management_new_user.php" target="_parent" class="btn btn-primary btn-sm">
          <i class="fas fa-arrow-right"></i> Access
        </a>
      </div>
      <div class="p-3 border rounded-3">
        <h5 class="mb-1"><i class="fas fa-user-plus"></i> Create Users</h5>
        <p class="text-muted mb-2">Create new users with automatic password generation</p>
        <a href="user_create_new_user.php" target="_parent" class="btn btn-success btn-sm">
          <i class="fas fa-plus"></i> Access
      </div>
    </div>
  </div>

  <!-- Currently Active Users -->
  <div class="security-card">
    <h4><i class="fas fa-user-clock"></i> Currently Active Users</h4>
    <div class="table-responsive">
      <table class="table table-hover">
        <thead class="table-light">
          <tr>
            <th>Username</th>
            <th>Email</th>
            <th>Role</th>
            <th>IP Address</th>
            <th>Device/Browser</th>
            <th>Login Time</th>
            <th>Last Activity</th>
            <th>Session Duration</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          // Get currently active users with proper timezone handling
          $current_time = date('Y-m-d H:i:s'); // Use PHP timezone
          $active_query = "SELECT username, email, role, ip_address, user_agent, login_time, last_activity, 
                                 TIMESTAMPDIFF(MINUTE, login_time, ?) as session_minutes,
                                 TIMESTAMPDIFF(MINUTE, last_activity, ?) as idle_minutes
                          FROM active_sessions 
                          WHERE is_active = 1 
                          ORDER BY last_activity DESC";
          $stmt = $conn->prepare($active_query);
          $stmt->bind_param("ss", $current_time, $current_time);
          $stmt->execute();
          $active_result = $stmt->get_result();
          
          if ($active_result->num_rows > 0):
            while ($active_user = $active_result->fetch_assoc()): 
          ?>
          <tr class="<?= $active_user['idle_minutes'] > 15 ? 'table-warning' : '' ?>">
            <td>
              <strong><?= htmlspecialchars($active_user['username']) ?></strong>
              <?php if ($active_user['idle_minutes'] > 15): ?>
                <span class="badge bg-warning">Idle</span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($active_user['email']) ?></td>
            <td>
              <span class="badge bg-<?= $active_user['role'] === 'admin' ? 'danger' : 'primary' ?>">
                <?= ucfirst($active_user['role']) ?>
              </span>
            </td>
            <td>
              <code><?= htmlspecialchars($active_user['ip_address']) ?></code>
              <?php if (strpos($active_user['ip_address'], '127.0.0.1') !== false): ?>
                <span class="badge bg-info">Local</span>
              <?php endif; ?>
            </td>
            <td>
              <?php
              $user_agent = $active_user['user_agent'] ?? '';
              $device_info = parseUserAgent($user_agent);
              ?>
              <span title="<?= htmlspecialchars($user_agent) ?>">
                <i class="<?= $device_info['icon'] ?>"></i>
                <?= htmlspecialchars($device_info['browser']) ?> / <?= htmlspecialchars($device_info['os']) ?>
              </span>
            </td>
            <td><?= date('M j, g:i A', strtotime($active_user['login_time'])) ?></td>
            <td>
              <?php 
              $last_activity = strtotime($active_user['last_activity']);
              $minutes_ago = round((time() - $last_activity) / 60);
              echo date('M j, g:i A', $last_activity) . " ($minutes_ago min ago)";
              ?>
            </td>
            <td>
              <?php 
              $session_minutes_total = $active_user['session_minutes'] ?? 0;
              $session_hours = floor($session_minutes_total / 60);
              $session_mins = $session_minutes_total % 60;
              echo $session_hours . "h " . $session_mins . "m";
              ?>
            </td>
            <td>
              <button class="btn btn-sm btn-warning" onclick="forceLogout('<?= $active_user['username'] ?>')">
                <i class="fas fa-sign-out-alt"></i> Force Logout
              </button>
            </td>
          </tr>
          <?php 
            endwhile;
          else:
          ?>
          <tr>
            <td colspan="9" class="text-center text-muted">
              <i class="fas fa-user-slash"></i> No users currently active
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Device Statistics -->
  <?php if (!empty($security_status['browser_stats']) || !empty($security_status['os_stats'])): ?>
  <div class="security-card">
    <h4><i class="fas fa-laptop"></i> Device Statistics</h4>
    <div class="row">
      <?php if (!empty($security_status['browser_stats'])): ?>
      <div class="col-md-6">
        <h5><i class="fas fa-globe"></i> Browser Usage</h5>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead class="table-light">
              <tr>
                <th>Browser</th>
                <th>Users</th>
                <th>Percentage</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $total_browsers = array_sum($security_status['browser_stats']);
              foreach ($security_status['browser_stats'] as $browser => $count): 
                $percentage = round(($count / $total_browsers) * 100, 1);
              ?>
              <tr>
                <td><?= htmlspecialchars($browser) ?></td>
                <td><?= $count ?></td>
                <td>
                  <div class="progress" style="height: 20px;">
                    <div class="progress-bar" style="width: <?= $percentage ?>%"><?= $percentage ?>%</div>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
      
      <?php if (!empty($security_status['os_stats'])): ?>
      <div class="col-md-6">
        <h5><i class="fas fa-desktop"></i> Operating Systems</h5>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead class="table-light">
              <tr>
                <th>OS</th>
                <th>Users</th>
                <th>Percentage</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $total_os = array_sum($security_status['os_stats']);
              foreach ($security_status['os_stats'] as $os => $count): 
                $percentage = round(($count / $total_os) * 100, 1);
              ?>
              <tr>
                <td><?= htmlspecialchars($os) ?></td>
                <td><?= $count ?></td>
                <td>
                  <div class="progress" style="height: 20px;">
                    <div class="progress-bar bg-info" style="width: <?= $percentage ?>%"><?= $percentage ?>%</div>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Dynamic User List -->
  <div class="security-card">
    <h4><i class="fas fa-users"></i> User Activity Overview</h4>
    <div class="table-responsive">
      <table class="table table-hover">
        <thead class="table-light">
          <tr>
            <th>Username</th>
            <th>Status</th>
            <th>Last Login</th>
            <th>Last Activity</th>
            <th>Wrong Attempts</th>
            <th>Account Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          // Get comprehensive user list with dynamic data
          $current_time = date('Y-m-d H:i:s');
          $user_query = "SELECT username, email, is_active, wrong_attempts, lock_until, last_login, last_activity, 
                               CASE 
                                   WHEN lock_until IS NOT NULL AND lock_until > ? THEN 'Locked'
                                   WHEN is_active = 0 THEN 'Disabled'
                                   WHEN wrong_attempts >= 5 THEN 'High Risk'
                                   WHEN wrong_attempts >= 3 THEN 'Warning'
                                   ELSE 'Active'
                               END as status
                         FROM new_user 
                         ORDER BY last_activity DESC, username ASC";
          $stmt = $conn->prepare($user_query);
          $stmt->bind_param("s", $current_time);
          $stmt->execute();
          $user_result = $stmt->get_result();
          while ($user = $user_result->fetch_assoc()): 
          ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($user['username']) ?></strong>
              <br><small class="text-muted"><?= htmlspecialchars($user['email']) ?></small>
            </td>
            <td>
              <?php
              $status_class = '';
              switch($user['status']) {
                  case 'Locked': $status_class = 'danger'; break;
                  case 'Disabled': $status_class = 'secondary'; break;
                  case 'High Risk': $status_class = 'danger'; break;
                  case 'Warning': $status_class = 'warning'; break;
                  default: $status_class = 'success';
              }
              ?>
              <span class="badge bg-<?= $status_class ?>"><?= $user['status'] ?></span>
            </td>
            <td>
              <?php if ($user['last_login']): ?>
                <span class="text-success"><?= date('M j, Y g:i A', strtotime($user['last_login'])) ?></span>
              <?php else: ?>
                <span class="text-muted">Never</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($user['last_activity']): ?>
                <span class="text-info"><?= date('M j, Y g:i A', strtotime($user['last_activity'])) ?></span>
              <?php else: ?>
                <span class="text-muted">Never</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge bg-<?= $user['wrong_attempts'] >= 5 ? 'danger' : ($user['wrong_attempts'] >= 3 ? 'warning' : 'info') ?>">
                <?= $user['wrong_attempts'] ?>
              </span>
            </td>
            <td>
              <?php if ($user['is_active']): ?>
                <span class="badge bg-success">Active</span>
              <?php else: ?>
                <span class="badge bg-secondary">Disabled</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="btn-group" role="group">
                <a href="user_management_new_user.php?filter_user=<?= urlencode($user['username']) ?>" class="btn btn-sm btn-primary">Manage</a>
                <?php if ($user['status'] === 'Locked'): ?>
                  <button class="btn btn-sm btn-success" onclick="unlockUser('<?= $user['username'] ?>')">Unlock</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Function to unlock user account
function unlockUser(username) {
    if (confirm(`Are you sure you want to unlock ${username}?`)) {
        fetch('security_dashboard.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=unlock_user&username=${encodeURIComponent(username)}`
        })
        .then(response => response.text())
        .then(() => {
            location.reload();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error unlocking user. Please try again.');
        });
    }
}

// Function to force logout user
function forceLogout(username) {
    if (confirm(`Are you sure you want to force logout ${username}? This will immediately end their session.`)) {
        fetch('security_dashboard.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=force_logout&username=${encodeURIComponent(username)}`
        })
        .then(response => response.text())
        .then(() => {
            location.reload();
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error force logging out user. Please try again.');
        });
    }
}

// Auto-refresh security data every 30 seconds
setInterval(() => {
    // Refresh only the audit data without full page reload
    fetch('security_dashboard.php?refresh=1')
    .then(response => response.text())
    .then(html => {
        // Update specific sections if needed
        console.log('Security data refreshed');
    })
    .catch(error => {
        console.error('Auto-refresh error:', error);
    });
}, 30000);

// Real-time active users refresh every 15 seconds
setInterval(() => {
    fetch('security_dashboard.php?section=active_users')
    .then(response => response.text())
    .then(html => {
        // Update active users section
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');
        const newActiveUsers = doc.querySelector('.security-card:nth-child(3)');
        const currentActiveUsers = document.querySelector('.security-card:nth-child(3)');
        if (newActiveUsers && currentActiveUsers) {
            currentActiveUsers.innerHTML = newActiveUsers.innerHTML;
        }
    })
    .catch(error => {
        console.error('Active users refresh error:', error);
    });
}, 15000);

// Real-time status indicators
document.addEventListener('DOMContentLoaded', function() {
    // Add visual indicators for high-risk users
    const highRiskRows = document.querySelectorAll('tr');
    highRiskRows.forEach(row => {
        const wrongAttempts = row.querySelector('.badge');
        if (wrongAttempts && wrongAttempts.textContent >= 5) {
            row.style.backgroundColor = '#fff5f5';
            row.style.borderLeft = '4px solid #dc3545';
        }
    });
});
</script>

<script>
// Refresh only when approve/reject actions happen
(function() {
    let isRefreshing = false;
    
    // Function to refresh the dashboard
    function refreshDashboard() {
        if (isRefreshing) return;
        isRefreshing = true;
        
        // Reload the page with cache busting
        window.location.href = window.location.href.split('?')[0] + '?t=' + new Date().getTime();
    }
    
    // Listen for messages from child windows (approval/rejection pages)
    window.addEventListener('message', function(event) {
        // Verify origin for security
        if (event.origin !== window.location.origin) {
            return;
        }
        
        // If message indicates a report was processed (approved/rejected), refresh
        if (event.data && (event.data.type === 'report_processed' || event.data.type === 'report_approved' || event.data.type === 'report_rejected')) {
            // Small delay to ensure database is updated
            setTimeout(function() {
                refreshDashboard();
            }, 300);
        }
    });
})();
</script>
</body>
</html> 
