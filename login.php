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
    // Default XAMPP ships with root user and NO password; adjust here if yours differs
    // NOTE: Current database name is 'geobagg' (not 'geobagg')
    $conn = @new mysqli("localhost", "root", "", "geobagg");
    if ($conn->connect_error) {
        error_log("Login DB connection failed: " . $conn->connect_error);
        header("Location: login.html?error=db");
        exit();
    }

    // Simple user lookup - prioritize users table first, but only query existing tables
    $user = null;
    $tables_to_try = ['users', 'new_user']; // Changed order to prioritize users table

    foreach ($tables_to_try as $table) {
        $exists = $conn->query("SHOW TABLES LIKE '{$table}'");
        if (!$exists || $exists->num_rows === 0) {
            error_log("Login lookup skipped missing table: {$table}");
            continue;
        }

        $stmt = $conn->prepare("SELECT * FROM {$table} WHERE username = ?");
        if ($stmt) {
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $user = $result->fetch_assoc();
                error_log("User found in table: " . $table . " with ID: " . $user['id']);
                break; // Found user, stop trying other tables
            }
        } else {
            error_log("Error preparing statement for table: " . $table . " - " . $conn->error);
        }
    }

    // Get IP address for logging
    require_once 'get_real_ip.php';
    $ip_address = getRealIPAddress();
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

    // Successful login - reset wrong attempts in new_user table (for security dashboard)
    $stmt = $conn->prepare("UPDATE new_user SET wrong_attempts = 0, lock_until = NULL, last_login = NOW(), last_activity = NOW() WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();

    // Log successful login
    $log_stmt = $conn->prepare("INSERT INTO login_attempts (username, ip_address, user_agent, success, failure_reason) VALUES (?, ?, ?, 1, NULL)");
    $log_stmt->bind_param("sss", $username, $ip_address, $user_agent);
    $log_stmt->execute();
    
    // Enterprise audit log (compatible with audit_log table schema: table_name, action, etc.)
    $audit_table_exists = $conn->query("SHOW TABLES LIKE 'audit_log'");
    if ($audit_table_exists && $audit_table_exists->num_rows > 0) {
        $login_details_json = json_encode([
            'event' => 'login',
            'username' => $user['username'],
            'role' => $user['role'] ?? 'user',
            'status' => 'success'
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $audit_stmt = $conn->prepare("INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, changed_by, user_id, ip_address, user_agent) VALUES ('auth', NULL, 'LOGIN', NULL, ?, ?, ?, ?, ?)");
        if ($audit_stmt) {
            $audit_stmt->bind_param("ssiss", $login_details_json, $username, $user['id'], $ip_address, $user_agent);
    $audit_stmt->execute();
        }
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

    // Determine which table to update
    $update_table = 'users';
    foreach (['users', 'new_user'] as $table) {
        $check = $conn->query("SHOW TABLES LIKE '$table'");
        if ($check && $check->num_rows > 0) {
            $columns = $conn->query("SHOW COLUMNS FROM $table LIKE 'last_login'");
            if ($columns && $columns->num_rows > 0) {
                $update_table = $table;
                break;
            }
        }
    }

    // âš¡ FIX: assign array element to variable
    $user_id = $user['id'];
    $stmt = $conn->prepare("UPDATE $update_table SET last_login = ?, last_activity = ? WHERE id = ?");
    $stmt->bind_param("ssi", $bangladesh_time, $bangladesh_time, $user_id);
    $stmt->execute();

    // Record active session
    $session_id = session_id();
    require_once 'get_real_ip.php';
    $ip_address = getRealIPAddress();
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $check_table = $conn->query("SHOW TABLES LIKE 'active_sessions'");
    if ($check_table && $check_table->num_rows > 0) {
        // Assign array values into variables before bind
        $email = $user['email'] ?? null;
        $role = $user['role'] ?? 'user';
        $uname = $user['username'];

        // Detect active_sessions columns
        $cols = [];
        $colRes = $conn->query("SHOW COLUMNS FROM active_sessions");
        if ($colRes) {
            while ($row = $colRes->fetch_assoc()) {
                $cols[] = strtolower($row['Field']);
            }
        }

        // Build column list dynamically based on existing schema
        $columns = [];
        $placeholders = [];
        $types = '';
        $values = [];

        // helper to add a column/value/type
        $addCol = function($name, $value, $type) use (&$columns, &$placeholders, &$types, &$values) {
            $columns[] = $name;
            $placeholders[] = '?';
            $types .= $type;
            $values[] = $value;
        };

        $addCol('user_id', $user_id, 'i');
        $addCol('username', $uname, 's');

        $hasEmail = in_array('email', $cols, true);
        if ($hasEmail) {
            $addCol('email', $email ?? 'user@example.com', 's');
        }

        $hasRole = in_array('role', $cols, true);
        if ($hasRole) {
            $addCol('role', $role, 's');
        }

        $addCol('session_id', $session_id, 's');
        $addCol('ip_address', $ip_address, 's');
        $addCol('user_agent', $user_agent, 's');
        $addCol('login_time', $bangladesh_time, 's');
        $addCol('last_activity', $bangladesh_time, 's');
        // is_active is constant
        $columns[] = 'is_active';
        $placeholders[] = '1';

        $updateParts = [
            "user_id = VALUES(user_id)",
            "session_id = VALUES(session_id)",
            "ip_address = VALUES(ip_address)",
            "user_agent = VALUES(user_agent)",
            "login_time = VALUES(login_time)",
            "last_activity = VALUES(last_activity)",
            "is_active = 1"
        ];
        if ($hasEmail) {
            array_splice($updateParts, 1, 0, "email = VALUES(email)");
        }
        if ($hasRole) {
            array_splice($updateParts, 1 + ($hasEmail ? 1 : 0), 0, "role = VALUES(role)");
        }

        $sql = "INSERT INTO active_sessions (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")
            ON DUPLICATE KEY UPDATE " . implode(', ', $updateParts);

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($types !== '') {
                $stmt->bind_param($types, ...$values);
            }
        $stmt->execute();
            $stmt->close();
        }
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


