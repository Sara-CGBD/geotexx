<?php
/**
 * Performance Configuration
 * Optimizes page load times and database connections
 */

// Enable output buffering for faster page rendering
if (!ob_get_level()) {
    ob_start();
}

// Database connection pooling
class PerformanceConfig {
    private static $conn = null;
    private static $queryCache = [];
    private static $cacheTimeout = 60; // 60 seconds cache
    
    /**
     * Get optimized database connection
     * Reuses existing connection for better performance
     */
    public static function getConnection() {
        if (self::$conn === null || !self::$conn->ping()) {
            // Default XAMPP: root user, no password; DB name is 'geobagg'
            self::$conn = new mysqli("localhost", "root", "", "geobagg");
            if (self::$conn->connect_error) {
                error_log("Database connection failed: " . self::$conn->connect_error);
                die("Database connection failed. Please contact system administrator.");
            }
            // Optimize connection settings
            self::$conn->set_charset("utf8mb4");
            // Disable autocommit for better performance (can be enabled per transaction)
            self::$conn->autocommit(true);
        }
        return self::$conn;
    }
    
    /**
     * Execute query with optional caching
     * @param string $query SQL query
     * @param array $params Parameters for prepared statement
     * @param bool $useCache Whether to use cache (default: false for writes, true for reads)
     * @return mysqli_result|bool
     */
    public static function executeQuery($query, $params = [], $useCache = false) {
        $conn = self::getConnection();
        
        // Check cache for read queries
        if ($useCache && empty($params)) {
            $cacheKey = md5($query);
            if (isset(self::$queryCache[$cacheKey])) {
                $cached = self::$queryCache[$cacheKey];
                if (time() - $cached['time'] < self::$cacheTimeout) {
                    return $cached['result'];
                }
                unset(self::$queryCache[$cacheKey]);
            }
        }
        
        // Execute query
        if (empty($params)) {
            $result = $conn->query($query);
        } else {
            $stmt = $conn->prepare($query);
            if ($stmt) {
                $types = str_repeat('s', count($params)); // Default to string, can be optimized
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                $result = $stmt->get_result();
                $stmt->close();
            } else {
                $result = false;
            }
        }
        
        // Cache result if successful and caching enabled
        if ($useCache && $result && empty($params)) {
            $cacheKey = md5($query);
            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            // Reset result pointer
            $result->data_seek(0);
            self::$queryCache[$cacheKey] = [
                'result' => $result,
                'time' => time(),
                'data' => $data
            ];
        }
        
        return $result;
    }
    
    /**
     * Clear query cache
     */
    public static function clearCache() {
        self::$queryCache = [];
    }
    
    /**
     * Close connection (called at end of script)
     */
    public static function closeConnection() {
        if (self::$conn !== null) {
            self::$conn->close();
            self::$conn = null;
        }
    }
}

// Auto-close connection at end of script
register_shutdown_function(function() {
    PerformanceConfig::closeConnection();
    if (ob_get_level()) {
        ob_end_flush();
    }
});

// Optimize session handling
if (session_status() === PHP_SESSION_NONE) {
    // Use faster session storage
    ini_set('session.gc_maxlifetime', 1800);
    ini_set('session.cookie_lifetime', 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Lax');
}

// Enable compression if available
if (extension_loaded('zlib') && !ob_get_level()) {
    ob_start('ob_gzhandler');
}
?>


