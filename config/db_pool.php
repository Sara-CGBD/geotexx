<?php
/**
 * GEOTEX Enterprise Database Connection Pool
 * Reuses database connections for better performance
 */

class DBPool {
    private static $instance = null;
    private $connection = null;
    private $host;
    private $user;
    private $pass;
    private $db;
    
    private function __construct() {
        // Load database configuration
        $config_file = __DIR__ . '/config.php';
        if (file_exists($config_file)) {
            include $config_file;
            // Prefer config.php values, fallback to sensible defaults
            $this->host = $host ?? 'localhost';
            $this->user = $username ?? $user ?? 'root';
            $this->pass = $password ?? $pass ?? '';
            $this->db = $dbname ?? $db ?? 'geobagg';
        } else {
            // Fallback to defaults
            $this->host = 'localhost';
            $this->user = 'root';
            $this->pass = '';
            $this->db = 'geobagg';
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        if ($this->connection === null || !$this->connection->ping()) {
            $this->connection = new mysqli(
                $this->host,
                $this->user,
                $this->pass,
                $this->db
            );
            
            if ($this->connection->connect_error) {
                error_log("Database connection failed: " . $this->connection->connect_error);
                die("Database connection failed. Please contact system administrator.");
            }
            
            // Set charset to UTF-8
            $this->connection->set_charset("utf8mb4");
            
            // Set timezone
            $this->connection->query("SET time_zone = '+06:00'");
            
            // Enable query cache
            $this->connection->query("SET SESSION query_cache_type = ON");
        }
        
        return $this->connection;
    }
    
    public function closeConnection() {
        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }
    }
    
    // Prevent cloning
    private function __clone() {}
    
    // Prevent unserialization
    private function __wakeup() {}
    
    // Clean up on destruct
    public function __destruct() {
        $this->closeConnection();
    }
}

// Helper function for backward compatibility
function getDBConnection() {
    return DBPool::getInstance()->getConnection();
}
?>


