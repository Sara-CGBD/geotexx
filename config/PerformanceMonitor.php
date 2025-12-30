<?php
/**
 * Performance Monitor
 * Tracks page load times, identifies bottlenecks, and helps optimize the system
 * 
 * Usage:
 * - Include at top of page: PerformanceMonitor::start();
 * - At end of page: PerformanceMonitor::end('page_name');
 */

class PerformanceMonitor {
    private static $start_time;
    private static $start_memory;
    private static $query_count = 0;
    private static $queries = [];
    private static $enabled = true;
    
    /**
     * Start monitoring a page
     */
    public static function start() {
        if (!self::$enabled) return;
        
        self::$start_time = microtime(true);
        self::$start_memory = memory_get_usage();
        self::$query_count = 0;
        self::$queries = [];
        
        // Hook into mysqli to track queries
        self::hookMySQLi();
    }
    
    /**
     * End monitoring and log results
     */
    public static function end($page_name = 'unknown') {
        if (!self::$enabled) return;
        
        $duration = microtime(true) - self::$start_time;
        $memory_used = memory_get_usage() - self::$start_memory;
        $memory_peak = memory_get_peak_usage();
        
        // Determine performance level
        $performance_level = self::getPerformanceLevel($duration);
        
        // Log to error log if slow
        if ($duration > 2) {
            error_log("⚠️ SLOW PAGE: $page_name took " . number_format($duration, 3) . "s | Memory: " . self::formatBytes($memory_used) . " | Queries: " . self::$query_count);
        }
        
        // Store in database for analysis
        self::logToDatabase($page_name, $duration, $memory_used, $memory_peak);
        
        // Return metrics (useful for debugging)
        return [
            'page' => $page_name,
            'duration' => $duration,
            'memory_used' => $memory_used,
            'memory_peak' => $memory_peak,
            'query_count' => self::$query_count,
            'performance_level' => $performance_level
        ];
    }
    
    /**
     * Track a database query
     */
    public static function trackQuery($query, $duration = 0) {
        if (!self::$enabled) return;
        
        self::$query_count++;
        self::$queries[] = [
            'query' => substr($query, 0, 200), // First 200 chars
            'duration' => $duration
        ];
        
        // Log slow queries
        if ($duration > 1) {
            error_log("🐢 SLOW QUERY (" . number_format($duration, 3) . "s): " . substr($query, 0, 100));
        }
    }
    
    /**
     * Get performance level based on duration
     */
    private static function getPerformanceLevel($duration) {
        if ($duration < 0.5) return 'Excellent';
        if ($duration < 1) return 'Good';
        if ($duration < 2) return 'Fair';
        if ($duration < 5) return 'Poor';
        return 'Critical';
    }
    
    /**
     * Log performance data to database
     */
    private static function logToDatabase($page_name, $duration, $memory_used, $memory_peak) {
        try {
            require_once __DIR__ . '/security_config.php';
            $conn = SecurityConfig::getConnection();
            
            // Check if table exists
            $tableCheck = $conn->query("SHOW TABLES LIKE 'system_performance'");
            if (!$tableCheck || $tableCheck->num_rows === 0) {
                return; // Table doesn't exist, skip logging
            }
            
            $user_id = $_SESSION['user_id'] ?? null;
            $url = $_SERVER['REQUEST_URI'] ?? '';
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            
            $stmt = $conn->prepare("INSERT INTO system_performance 
                (page_name, response_time, memory_used, memory_peak, query_count, user_id, url, method) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            if ($stmt) {
                $stmt->bind_param("sdiiiiss", 
                    $page_name, 
                    $duration, 
                    $memory_used, 
                    $memory_peak, 
                    self::$query_count, 
                    $user_id, 
                    $url, 
                    $method
                );
                $stmt->execute();
                $stmt->close();
            }
        } catch (Exception $e) {
            // Silently fail - don't break the application
            error_log("Performance Monitor DB Error: " . $e->getMessage());
        }
    }
    
    /**
     * Hook into mysqli to track queries
     */
    private static function hookMySQLi() {
        // This is a placeholder - actual query tracking would require
        // extending mysqli or using a database proxy
        // For now, queries must be tracked manually
    }
    
    /**
     * Format bytes to human-readable
     */
    private static function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Enable/disable monitoring
     */
    public static function setEnabled($enabled) {
        self::$enabled = $enabled;
    }
    
    /**
     * Get current metrics (for debugging)
     */
    public static function getCurrentMetrics() {
        if (!self::$enabled) return null;
        
        return [
            'elapsed_time' => microtime(true) - self::$start_time,
            'memory_current' => memory_get_usage(),
            'memory_used' => memory_get_usage() - self::$start_memory,
            'query_count' => self::$query_count,
            'queries' => self::$queries
        ];
    }
    
    /**
     * Display performance badge (for development)
     */
    public static function displayBadge() {
        if (!self::$enabled) return;
        
        $metrics = self::getCurrentMetrics();
        if (!$metrics) return;
        
        $duration = $metrics['elapsed_time'];
        $memory = self::formatBytes($metrics['memory_used']);
        
        $color = '#28a745'; // Green
        if ($duration > 1) $color = '#ffc107'; // Yellow
        if ($duration > 2) $color = '#dc3545'; // Red
        
        echo '<div style="position:fixed; bottom:50px; right:10px; background:' . $color . '; color:white; 
              padding:8px 15px; border-radius:20px; font-size:11px; font-weight:600; z-index:9998; 
              box-shadow:0 2px 8px rgba(0,0,0,0.2); font-family:monospace;">';
        echo '⏱️ ' . number_format($duration, 2) . 's | 💾 ' . $memory . ' | 🔍 ' . self::$query_count . ' queries';
        echo '</div>';
    }
}
?>


