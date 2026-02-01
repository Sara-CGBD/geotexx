<?php
// ✅ Set timezone to Asia/Dhaka (GMT+6) to ensure correct time display
date_default_timezone_set('Asia/Dhaka');

/**
 * Security Configuration
 * Centralized security settings
 */

// Security constants
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 300); // 5 minutes
define('SESSION_TIMEOUT', 1800); // 30 minutes
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_REQUIRE_SPECIAL', true);

// Security headers
function set_security_headers() {
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';");
}

// Session security settings
function configure_secure_session() {
    session_set_cookie_params([
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
}

// Input sanitization
function sanitize_input($input, $type = 'string') {
    switch ($type) {
        case 'email':
            return filter_var(trim($input), FILTER_SANITIZE_EMAIL);
        case 'string':
            return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
        case 'int':
            return filter_var(trim($input), FILTER_SANITIZE_NUMBER_INT);
        default:
            return trim($input);
    }
}

// Password strength validation
function validate_password_strength($password) {
    $errors = [];
    
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        $errors[] = "Password must be at least " . PASSWORD_MIN_LENGTH . " characters long";
    }
    
    if (PASSWORD_REQUIRE_SPECIAL && !preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    return $errors;
}

// Rate limiting check
function check_rate_limit($ip, $max_attempts = MAX_LOGIN_ATTEMPTS, $lockout_time = LOGIN_LOCKOUT_TIME) {
    $attempt_key = "login_attempts_" . $ip;
    
    if (isset($_SESSION[$attempt_key]) && $_SESSION[$attempt_key]['count'] >= $max_attempts) {
        $time_diff = time() - $_SESSION[$attempt_key]['time'];
        if ($time_diff < $lockout_time) {
            return [
                'locked' => true,
                'remaining' => $lockout_time - $time_diff
            ];
        } else {
            // Reset attempts after lockout period
            unset($_SESSION[$attempt_key]);
        }
    }
    
    return ['locked' => false];
}

// Increment login attempts
function increment_login_attempts($ip) {
    $attempt_key = "login_attempts_" . $ip;
    
    if (!isset($_SESSION[$attempt_key])) {
        $_SESSION[$attempt_key] = ['count' => 1, 'time' => time()];
    } else {
        $_SESSION[$attempt_key]['count']++;
        $_SESSION[$attempt_key]['time'] = time();
    }
}

// Clear login attempts on successful login
function clear_login_attempts($ip) {
    $attempt_key = "login_attempts_" . $ip;
    unset($_SESSION[$attempt_key]);
}

// Session timeout check
function check_session_timeout($timeout = SESSION_TIMEOUT) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

// CSRF token generation
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// CSRF token validation
function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Security Configuration and Functions
class SecurityConfig {
    // Session timeout settings (in seconds)
    const SESSION_TIMEOUT = 1800; // 30 minutes
    const MAX_WRONG_ATTEMPTS = 5; // Maximum wrong attempts before lock
    const LOCK_DURATION = 300; // 5 minutes lock duration
    const HIGH_SECURITY_LOCK_DURATION = 600; // 10 minutes for high security
    
    // Database connection (optimized with connection reuse)
    private static $conn = null;
    
    public static function getConnection() {
        if (self::$conn === null || !self::$conn->ping()) {
            // Default XAMPP: root user, no password; DB name is 'geobagg'
            self::$conn = new mysqli("localhost", "root", "", "geobagg");
            if (self::$conn->connect_error) {
                error_log("Connection failed: " . self::$conn->connect_error);
                die("Connection failed: " . self::$conn->connect_error);
            }
            // Optimize connection settings for performance
            self::$conn->set_charset("utf8mb4");
        }
        return self::$conn;
    }
    
    // Check session timeout
    public static function checkSessionTimeout() {
        if (!isset($_SESSION['last_activity'])) {
            return false;
        }
        
        $timeout = time() - $_SESSION['last_activity'];
        return $timeout > self::SESSION_TIMEOUT;
    }
    
    // Update session activity
    public static function updateSessionActivity() {
        $_SESSION['last_activity'] = time();
    }
    
    // Check if user account is locked
    public static function isAccountLocked($username) {
        $conn = self::getConnection();
        $stmt = $conn->prepare("SELECT is_active, lock_until FROM new_user WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if (!$user) {
            return false;
        }
        
        // Check if account is disabled
        if ($user['is_active'] == 0) {
            return true;
        }
        
        // Check if account is temporarily locked
        if ($user['lock_until'] && strtotime($user['lock_until']) > time()) {
            return true;
        }
        
        return false;
    }
    
    // Optimized version that accepts user data to avoid extra queries
    public static function isAccountLockedWithData($user_data) {
        if (!$user_data) {
            return false;
        }
        
        // Check if account is disabled
        if ($user_data['is_active'] == 0) {
            return true;
        }
        
        // Check if account is temporarily locked
        if ($user_data['lock_until'] && strtotime($user_data['lock_until']) > time()) {
            return true;
        }
        
        return false;
    }
    
    // Record wrong login attempt
    public static function recordWrongAttempt($username) {
        $conn = self::getConnection();
        
        // Get current wrong attempts
        $stmt = $conn->prepare("SELECT wrong_attempts FROM new_user WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if (!$user) {
            return false;
        }
        
        $wrong_attempts = ($user['wrong_attempts'] ?? 0) + 1;
        
        // Determine lock duration based on attempt count
        $lock_duration = $wrong_attempts >= 10 ? self::HIGH_SECURITY_LOCK_DURATION : self::LOCK_DURATION;
        
        if ($wrong_attempts >= self::MAX_WRONG_ATTEMPTS) {
            // Lock the account
            $lock_until = date('Y-m-d H:i:s', time() + $lock_duration);
            $stmt = $conn->prepare("UPDATE new_user SET wrong_attempts = ?, lock_until = ? WHERE username = ?");
            $stmt->bind_param("iss", $wrong_attempts, $lock_until, $username);
        } else {
            // Just update wrong attempts
            $stmt = $conn->prepare("UPDATE new_user SET wrong_attempts = ? WHERE username = ?");
            $stmt->bind_param("is", $wrong_attempts, $username);
        }
        
        return $stmt->execute();
    }
    
    // Reset wrong attempts on successful login
    public static function resetWrongAttempts($username) {
        $conn = self::getConnection();
        $stmt = $conn->prepare("UPDATE new_user SET wrong_attempts = 0, lock_until = NULL WHERE username = ?");
        $stmt->bind_param("s", $username);
        return $stmt->execute();
    }
    
    // Get remaining lock time
    public static function getRemainingLockTime($username) {
        $conn = self::getConnection();
        $stmt = $conn->prepare("SELECT lock_until FROM new_user WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        
        if (!$user || !$user['lock_until']) {
            return 0;
        }
        
        $remaining = strtotime($user['lock_until']) - time();
        return max(0, $remaining);
    }
    
    // Unlock account (admin function)
    public static function unlockAccount($username) {
        $conn = self::getConnection();
        $stmt = $conn->prepare("UPDATE new_user SET wrong_attempts = 0, lock_until = NULL WHERE username = ?");
        $stmt->bind_param("s", $username);
        return $stmt->execute();
    }
    
    // Enable/Disable account (admin function)
    public static function setAccountStatus($username, $is_active) {
        $conn = self::getConnection();
        $stmt = $conn->prepare("UPDATE new_user SET is_active = ? WHERE username = ?");
        $stmt->bind_param("is", $is_active, $username);
        return $stmt->execute();
    }
    
    // Get account status info
    public static function getAccountStatus($username) {
        $conn = self::getConnection();
        $stmt = $conn->prepare("SELECT is_active, wrong_attempts, lock_until FROM new_user WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    // Format time remaining
    public static function formatTimeRemaining($seconds) {
        if ($seconds <= 0) {
            return "Unlocked";
        }
        
        $minutes = floor($seconds / 60);
        $remaining_seconds = $seconds % 60;
        
        if ($minutes > 0) {
            return sprintf("%d min %d sec", $minutes, $remaining_seconds);
        } else {
            return sprintf("%d seconds", $remaining_seconds);
        }
    }
}
