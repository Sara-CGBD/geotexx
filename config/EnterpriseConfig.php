<?php
/**
 * Enterprise Configuration Class
 * Handles enterprise-level features: caching, monitoring, security, optimization
 */

class EnterpriseConfig {
    
    // Application Settings
    const APP_VERSION = '2.0.0';
    const APP_NAME = 'GeoTex QC Management System';
    const ENV = 'production'; // production, staging, development
    
    // Performance Settings
    const ENABLE_QUERY_CACHE = true;
    const CACHE_LIFETIME = 300; // 5 minutes
    const MAX_CONNECTIONS = 100;
    const QUERY_TIMEOUT = 30; // seconds
    
    // Security Settings
    const ENABLE_CSRF_PROTECTION = true;
    const SESSION_TIMEOUT = 1800; // 30 minutes
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOCKOUT_DURATION = 300; // 5 minutes
    const PASSWORD_MIN_LENGTH = 8;
    const REQUIRE_STRONG_PASSWORD = true;
    
    // Audit & Logging
    const ENABLE_AUDIT_LOG = true;
    const LOG_QUERIES = false; // Enable in development only
    const LOG_ERRORS = true;
    const LOG_RETENTION_DAYS = 90;
    
    // File Upload Settings
    const MAX_UPLOAD_SIZE = 10485760; // 10MB
    const ALLOWED_FILE_TYPES = ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'csv'];
    
    // Rate Limiting
    const ENABLE_RATE_LIMIT = true;
    const MAX_REQUESTS_PER_MINUTE = 60;
    
    // Backup Settings
    const AUTO_BACKUP_ENABLED = true;
    const BACKUP_RETENTION_DAYS = 30;
    
    /**
     * Generate CSRF Token
     */
    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Verify CSRF Token
     */
    public static function verifyCSRFToken($token) {
        if (!self::ENABLE_CSRF_PROTECTION) return true;
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Sanitize Input
     */
    public static function sanitizeInput($data) {
        if (is_array($data)) {
            return array_map([self::class, 'sanitizeInput'], $data);
        }
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Validate Email
     */
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
    
    /**
     * Validate Strong Password
     */
    public static function validatePassword($password) {
        if (strlen($password) < self::PASSWORD_MIN_LENGTH) {
            return false;
        }
        
        if (self::REQUIRE_STRONG_PASSWORD) {
            // Must contain: uppercase, lowercase, number, special char
            return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#])[A-Za-z\d@$!%*?&#]/', $password);
        }
        
        return true;
    }
    
    /**
     * Log Activity (Enterprise Audit Trail)
     */
    public static function logActivity($conn, $user_id, $event_type, $details, $ip_address = null) {
        if (!self::ENABLE_AUDIT_LOG) return;
        
        try {
            $ip = $ip_address ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $who_did = $_SESSION['username'] ?? 'system';
            
            $stmt = $conn->prepare("INSERT INTO audit_log (user_id, event_type, ip_address, details, who_did, created_at) 
                                    VALUES (?, ?, ?, ?, ?, NOW())");
            $stmt->bind_param("issss", $user_id, $event_type, $ip, $details, $who_did);
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            error_log("Audit log failed: " . $e->getMessage());
        }
    }
    
    /**
     * Rate Limiting Check
     */
    public static function checkRateLimit($identifier) {
        if (!self::ENABLE_RATE_LIMIT) return true;
        
        $key = 'rate_limit_' . $identifier;
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['count' => 0, 'time' => time()];
        }
        
        $data = $_SESSION[$key];
        
        // Reset if minute has passed
        if (time() - $data['time'] > 60) {
            $_SESSION[$key] = ['count' => 1, 'time' => time()];
            return true;
        }
        
        // Check limit
        if ($data['count'] >= self::MAX_REQUESTS_PER_MINUTE) {
            return false;
        }
        
        $_SESSION[$key]['count']++;
        return true;
    }
    
    /**
     * Secure File Upload
     */
    public static function validateUpload($file) {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Upload failed'];
        }
        
        if ($file['size'] > self::MAX_UPLOAD_SIZE) {
            return ['success' => false, 'error' => 'File too large'];
        }
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_FILE_TYPES)) {
            return ['success' => false, 'error' => 'Invalid file type'];
        }
        
        return ['success' => true];
    }
    
    /**
     * Performance Monitor
     */
    public static function startPerformanceMonitor() {
        $_SESSION['performance_start'] = microtime(true);
        $_SESSION['performance_queries'] = 0;
    }
    
    public static function endPerformanceMonitor() {
        if (!isset($_SESSION['performance_start'])) return null;
        
        $duration = microtime(true) - $_SESSION['performance_start'];
        $queries = $_SESSION['performance_queries'] ?? 0;
        
        return [
            'duration' => round($duration, 3),
            'queries' => $queries,
            'memory' => round(memory_get_peak_usage() / 1024 / 1024, 2) . ' MB'
        ];
    }
    
    /**
     * Database Health Check
     */
    public static function checkDatabaseHealth($conn) {
        try {
            // Check connection
            if (!$conn->ping()) {
                return ['status' => 'error', 'message' => 'Database connection lost'];
            }
            
            // Check key tables exist
            $tables = ['new_user', 'qc_test_orders', 'audit_log'];
            foreach ($tables as $table) {
                $result = $conn->query("SHOW TABLES LIKE '$table'");
                if ($result->num_rows === 0) {
                    return ['status' => 'error', 'message' => "Table $table missing"];
                }
            }
            
            return ['status' => 'ok', 'message' => 'Database healthy'];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Generate Report Number (with collision prevention)
     */
    public static function generateReportNumber($conn, $prefix = 'RPT') {
        $max_attempts = 100;
        $date = date('Ymd');
        
        for ($i = 0; $i < $max_attempts; $i++) {
            $counter = str_pad(rand(1, 9999), 3, '0', STR_PAD_LEFT);
            $report_number = "{$prefix}-{$date}-{$counter}";
            
            // Check if exists in any table
            $exists = false;
            $tables = ['qc_test_orders', 'water_permeability_tests', 'characteristics_tests'];
            
            foreach ($tables as $table) {
                $check = $conn->query("SELECT 1 FROM $table WHERE report_number = '$report_number' LIMIT 1");
                if ($check && $check->num_rows > 0) {
                    $exists = true;
                    break;
                }
            }
            
            if (!$exists) {
                return $report_number;
            }
        }
        
        // Fallback with microseconds
        return "{$prefix}-{$date}-" . substr(microtime(true) * 10000, -4);
    }
}
?>


