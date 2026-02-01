<?php
// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();

// âœ… Set timezone to Asia/Dhaka (GMT+6) to ensure correct time display
date_default_timezone_set('Asia/Dhaka');

// Check if already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
    // Check session timeout (30 minutes)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 1800) {
        session_destroy();
        header("Location: login.html?error=timeout");
        exit();
    }

    // Update session activity
    $_SESSION['last_activity'] = time();

    // Redirect to dashboard
    header("Location: index.php");
    exit();
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        header("Location: login.html?error=empty");
        exit();
    }

    // Single database connection for entire login process
    // Default XAMPP: root user, no password; DB name is 'geobagg'
    $conn = @new mysqli("127.0.0.1", "root", "", "geobagg", 3307);
    if ($conn->connect_error) {
        error_log("Login DB connection failed: " . $conn->connect_error);
        header("Location: login.html?error=db");
        exit();
    }
    // Set charset early for better performance
    $conn->set_charset("utf8mb4");

    // Optimized user lookup - try users table first, then new_user
    // Cache table existence to avoid multiple SHOW TABLES queries
    $user = null;
    
    // Try users table first (most common)
    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            error_log("User found in users table with ID: " . $user['id']);
        }
        $stmt->close();
    }
    
    // If not found in users, try new_user table
    if (!$user) {
        $stmt = $conn->prepare("SELECT * FROM new_user WHERE username = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                error_log("User found in new_user table with ID: " . $user['id']);
            }
            $stmt->close();
        }
    }

    // Get IP address for logging (optimized - check REMOTE_ADDR first)
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    // Only use getRealIPAddress if behind proxy (check common proxy headers first)
    if (empty($_SERVER['HTTP_X_FORWARDED_FOR']) && empty($_SERVER['HTTP_X_REAL_IP']) && empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        // Not behind proxy, use REMOTE_ADDR directly (fastest)
    } else {
        require_once 'get_real_ip.php';
        $ip_address = getRealIPAddress();
    }
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // Check if user exists
    if (!$user) {
        error_log("Login attempt failed - User not found: " . $username);
        
        // Log failed attempt
        $log_stmt = $conn->prepare("INSERT INTO login_attempts (username, ip_address, user_agent, success, failure_reason) VALUES (?, ?, ?, 0, 'User not found')");
        $log_stmt->bind_param("sss", $username, $ip_address, $user_agent);
        $log_stmt->execute();
        
        header("Location: login.html?error=invalid");
        exit();
    }

    error_log("User found: " . $username . " in table with ID: " . $user['id']);

    // Check account status
    if (isset($user['is_active']) && $user['is_active'] == 0) {
        header("Location: login.html?error=disabled");
        exit();
    }

    if (isset($user['lock_until']) && $user['lock_until'] && strtotime($user['lock_until']) > time()) {
        $remaining_seconds = strtotime($user['lock_until']) - time();
        $minutes = floor($remaining_seconds / 60);
        $seconds = $remaining_seconds % 60;

        if ($minutes > 0) {
            $formatted_time = sprintf("%d min %d sec", $minutes, $seconds);
        } else {
            $formatted_time = sprintf("%d seconds", $seconds);
        }

        header("Location: login.html?error=locked&time=" . urlencode($formatted_time));
        exit();
    }

    // Verify password - try both password and password_hash columns
    $password_valid = false;

    error_log("Password verification for user: " . $username);

    if (isset($user['password_hash']) && !empty($user['password_hash'])) {
        $password_valid = password_verify($password, $user['password_hash']);
        error_log("password_hash verification result: " . ($password_valid ? 'SUCCESS' : 'FAILED'));
    }

    if (!$password_valid && isset($user['password']) && !empty($user['password'])) {
        if (password_get_info($user['password'])['algo'] !== null) {
            $password_valid = password_verify($password, $user['password']);
            error_log("password column (hashed) verification result: " . ($password_valid ? 'SUCCESS' : 'FAILED'));
        } else {
            $password_valid = ($password === $user['password']);
            error_log("password column (plain text) verification result: " . ($password_valid ? 'SUCCESS' : 'FAILED'));
        }
    }

    if (!$password_valid) {
        // Failed login - ALWAYS record in new_user table for security dashboard
        error_log("Failed login attempt for user: " . $username);
        
        // Get current wrong_attempts from new_user table
        $check_stmt = $conn->prepare("SELECT wrong_attempts FROM new_user WHERE username = ?");
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $current_attempts = 0;
        
        if ($check_result && $check_result->num_rows > 0) {
            $row = $check_result->fetch_assoc();
            $current_attempts = $row['wrong_attempts'] ?? 0;
        }
        $check_stmt->close();
        
        $wrong_attempts = $current_attempts + 1;
        $lock_duration = $wrong_attempts >= 10 ? 600 : 300;
        
        error_log("Updating wrong_attempts for $username: $current_attempts -> $wrong_attempts");

        if ($wrong_attempts >= 5) {
            $lock_until = date('Y-m-d H:i:s', time() + $lock_duration);
            // Update new_user table (always update this for security dashboard)
            $stmt = $conn->prepare("UPDATE new_user SET wrong_attempts = ?, lock_until = ?, last_activity = NOW() WHERE username = ?");
            $stmt->bind_param("iss", $wrong_attempts, $lock_until, $username);
            $stmt->execute();
            error_log("User $username locked until: $lock_until");
        } else {
            // Update new_user table (always update this for security dashboard)
            $stmt = $conn->prepare("UPDATE new_user SET wrong_attempts = ?, last_activity = NOW() WHERE username = ?");
            $stmt->bind_param("is", $wrong_attempts, $username);
            $stmt->execute();
        }

        // Log failed password attempt
        $log_stmt = $conn->prepare("INSERT INTO login_attempts (username, ip_address, user_agent, success, failure_reason) VALUES (?, ?, ?, 0, 'Invalid password')");
        $log_stmt->bind_param("sss", $username, $ip_address, $user_agent);
        $log_stmt->execute();

        // Redirect with appropriate error message
        if ($wrong_attempts >= 5) {
            $minutes = floor($lock_duration / 60);
            $seconds = $lock_duration % 60;
            $formatted_time = sprintf("%d min %d sec", $minutes, $seconds);
            header("Location: login.html?error=locked&time=" . urlencode($formatted_time));
        } else {
            $remaining_attempts = 5 - $wrong_attempts;
            header("Location: login.html?error=invalid&attempts=" . $remaining_attempts);
        }
        exit();
    }

    // Successful login - batch operations for better performance
    // Update user table and log in parallel (non-blocking for logging)
    $user_id = $user['id'];
    
    // Critical: Update user status (must complete before redirect)
    try {
        $stmt = $conn->prepare("UPDATE new_user SET wrong_attempts = 0, lock_until = NULL, last_login = NOW(), last_activity = NOW() WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        // Non-critical, continue
    }
    
    // Non-critical logging - execute but don't wait (defer to background if possible)
    // Use INSERT IGNORE or try-catch to prevent blocking on errors
    try {
        // Log successful login (non-blocking)
        $log_stmt = $conn->prepare("INSERT INTO login_attempts (username, ip_address, user_agent, success, failure_reason) VALUES (?, ?, ?, 1, NULL)");
        if ($log_stmt) {
            $log_stmt->bind_param("sss", $username, $ip_address, $user_agent);
            $log_stmt->execute();
            $log_stmt->close();
        }
    } catch (Exception $e) {
        // Non-critical logging error - continue
    }
    
    // Enterprise audit log (non-blocking)
    try {
        $login_details_json = json_encode([
            'event' => 'login',
            'username' => $user['username'],
            'role' => $user['role'] ?? 'user',
            'status' => 'success'
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $audit_stmt = $conn->prepare("INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, changed_by, user_id, ip_address, user_agent) VALUES ('auth', NULL, 'LOGIN', NULL, ?, ?, ?, ?, ?)");
        if ($audit_stmt) {
            $audit_stmt->bind_param("ssiss", $login_details_json, $username, $user_id, $ip_address, $user_agent);
            $audit_stmt->execute();
            $audit_stmt->close();
        }
    } catch (Exception $e) {
        // Table doesn't exist or error - silently continue (non-critical)
    }

    // Set session variables
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'] ?? 'user';
    $_SESSION['full_name'] = $user['full_name'] ?? $user['username'];
    $_SESSION['first_name'] = $user['first_name'] ?? $user['full_name'] ?? 'User';
    $_SESSION['last_name'] = $user['last_name'] ?? '';
    $_SESSION['last_activity'] = time();
    $_SESSION['new_user_system'] = true;
    
    // DEBUG: Log what session variables are being set
    error_log("SESSION SET - User ID: " . $_SESSION['user_id'] . 
              ", Username: " . $_SESSION['username'] . 
              ", Role: " . $_SESSION['role'] . 
              ", Full Name: " . $_SESSION['full_name']);

    $bangladesh_time = date('Y-m-d H:i:s');
    // Note: last_login already updated above in new_user table, skip duplicate update

    // Record active session - non-blocking (defer if table doesn't exist)
    $session_id = session_id();
    $email = $user['email'] ?? null;
    $role = $user['role'] ?? 'user';
    $uname = $user['username'];

    // Try to insert/update active_sessions (non-blocking)
    try {
        // Standard columns that should exist in active_sessions
        $sql = "INSERT INTO active_sessions (user_id, username, session_id, ip_address, user_agent, login_time, last_activity, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                ON DUPLICATE KEY UPDATE 
                    user_id = VALUES(user_id),
                    session_id = VALUES(session_id),
                    ip_address = VALUES(ip_address),
                    user_agent = VALUES(user_agent),
                    login_time = VALUES(login_time),
                    last_activity = VALUES(last_activity),
                    is_active = 1";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("issssss", $user_id, $uname, $session_id, $ip_address, $user_agent, $bangladesh_time, $bangladesh_time);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Exception $e) {
        // Table doesn't exist or missing columns - non-critical, skip silently
    }

    $conn->close();

    // Redirect everyone to the main dashboard (index.php)
    $redirectPath = 'index.php';

    header("Location: " . $redirectPath);
    exit();
}

// If not POST request, redirect to login page
header("Location: login.html");
exit();
?>


