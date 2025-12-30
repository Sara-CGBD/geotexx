<?php
/**
 * Enterprise Error Handler
 * Handles all errors gracefully and logs them to database
 */

class ErrorHandler {
    private static $conn = null;
    
    /**
     * Initialize Error Handler
     */
    public static function init($conn) {
        self::$conn = $conn;
        
        // Set custom error handler
        set_error_handler([self::class, 'handleError']);
        
        // Set custom exception handler
        set_exception_handler([self::class, 'handleException']);
        
        // Set shutdown handler for fatal errors
        register_shutdown_function([self::class, 'handleFatalError']);
        
        // Don't display errors in production
        if (EnterpriseConfig::ENV === 'production') {
            ini_set('display_errors', 0);
            error_reporting(E_ALL);
        }
    }
    
    /**
     * Handle regular errors
     */
    public static function handleError($errno, $errstr, $errfile, $errline) {
        // Don't handle error if error reporting is turned off
        if (!(error_reporting() & $errno)) {
            return false;
        }
        
        $error_type = self::getErrorType($errno);
        
        // Log to database
        self::logToDatabase($error_type, $errstr, $errfile, $errline);
        
        // Log to PHP error log
        if (EnterpriseConfig::LOG_ERRORS) {
            error_log("[$error_type] $errstr in $errfile on line $errline");
        }
        
        // Display user-friendly error in development
        if (EnterpriseConfig::ENV !== 'production') {
            self::displayError($error_type, $errstr, $errfile, $errline);
        }
        
        return true;
    }
    
    /**
     * Handle exceptions
     */
    public static function handleException($exception) {
        $error_type = get_class($exception);
        $error_message = $exception->getMessage();
        $error_file = $exception->getFile();
        $error_line = $exception->getLine();
        
        // Log to database
        self::logToDatabase($error_type, $error_message, $error_file, $error_line);
        
        // Log to PHP error log
        if (EnterpriseConfig::LOG_ERRORS) {
            error_log("[EXCEPTION] $error_type: $error_message in $error_file on line $error_line");
        }
        
        // Display error page
        if (EnterpriseConfig::ENV === 'production') {
            self::showProductionErrorPage();
        } else {
            self::displayError($error_type, $error_message, $error_file, $error_line, $exception->getTraceAsString());
        }
    }
    
    /**
     * Handle fatal errors
     */
    public static function handleFatalError() {
        $error = error_get_last();
        
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            self::handleError(
                $error['type'],
                $error['message'],
                $error['file'],
                $error['line']
            );
            
            if (EnterpriseConfig::ENV === 'production') {
                self::showProductionErrorPage();
            }
        }
    }
    
    /**
     * Log error to database
     */
    private static function logToDatabase($error_type, $error_message, $file_path, $line_number) {
        if (!self::$conn) return;
        
        try {
            // Check if error_log table exists
            $check = self::$conn->query("SHOW TABLES LIKE 'error_log'");
            if (!$check || $check->num_rows === 0) return;
            
            $user_id = $_SESSION['user_id'] ?? null;
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $request_url = $_SERVER['REQUEST_URI'] ?? 'unknown';
            
            $stmt = self::$conn->prepare("INSERT INTO error_log 
                (error_type, error_message, file_path, line_number, user_id, ip_address, user_agent, request_url) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            $stmt->bind_param("ssssisss", 
                $error_type, 
                $error_message, 
                $file_path, 
                $line_number, 
                $user_id, 
                $ip_address, 
                $user_agent, 
                $request_url
            );
            
            $stmt->execute();
            $stmt->close();
        } catch (Exception $e) {
            error_log("Failed to log error to database: " . $e->getMessage());
        }
    }
    
    /**
     * Get human-readable error type
     */
    private static function getErrorType($errno) {
        $error_types = [
            E_ERROR => 'Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            E_CORE_ERROR => 'Core Error',
            E_CORE_WARNING => 'Core Warning',
            E_COMPILE_ERROR => 'Compile Error',
            E_COMPILE_WARNING => 'Compile Warning',
            E_USER_ERROR => 'User Error',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
            E_STRICT => 'Strict Notice',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED => 'Deprecated',
            E_USER_DEPRECATED => 'User Deprecated'
        ];
        
        return $error_types[$errno] ?? 'Unknown Error';
    }
    
    /**
     * Display error (development mode)
     */
    private static function displayError($type, $message, $file, $line, $trace = null) {
        echo "<div style='background:#fee; border:2px solid #c33; padding:20px; margin:20px; border-radius:8px; font-family:monospace;'>";
        echo "<h2 style='color:#c33; margin:0 0 15px 0;'>🚨 $type</h2>";
        echo "<p style='font-size:16px; margin:10px 0;'><strong>Message:</strong> $message</p>";
        echo "<p style='font-size:14px; color:#666; margin:5px 0;'><strong>File:</strong> $file</p>";
        echo "<p style='font-size:14px; color:#666; margin:5px 0;'><strong>Line:</strong> $line</p>";
        
        if ($trace) {
            echo "<details style='margin-top:15px;'>";
            echo "<summary style='cursor:pointer; color:#c33; font-weight:bold;'>Stack Trace</summary>";
            echo "<pre style='background:#fff; padding:10px; border-radius:4px; overflow:auto; margin-top:10px;'>$trace</pre>";
            echo "</details>";
        }
        
        echo "</div>";
    }
    
    /**
     * Show production error page
     */
    private static function showProductionErrorPage() {
        http_response_code(500);
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>System Error</title>
            <style>
                body {
                    font-family: 'Segoe UI', Arial, sans-serif;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    margin: 0;
                }
                .error-container {
                    background: white;
                    padding: 50px;
                    border-radius: 15px;
                    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
                    text-align: center;
                    max-width: 500px;
                }
                .error-icon {
                    font-size: 80px;
                    margin-bottom: 20px;
                }
                h1 {
                    color: #333;
                    margin-bottom: 15px;
                }
                p {
                    color: #666;
                    line-height: 1.6;
                    margin-bottom: 30px;
                }
                .btn {
                    display: inline-block;
                    padding: 12px 30px;
                    background: #667eea;
                    color: white;
                    text-decoration: none;
                    border-radius: 6px;
                    font-weight: 600;
                    transition: background 0.3s;
                }
                .btn:hover {
                    background: #5568d3;
                }
            </style>
        </head>
        <body>
            <div class="error-container">
                <div class="error-icon">⚠️</div>
                <h1>Oops! Something went wrong</h1>
                <p>We're experiencing technical difficulties. Our team has been notified and is working to fix the issue.</p>
                <p>Please try again in a few moments.</p>
                <a href="/" class="btn">Return to Home</a>
            </div>
        </body>
        </html>
        <?php
        exit();
    }
    
    /**
     * Trigger custom error
     */
    public static function trigger($message, $type = E_USER_ERROR) {
        trigger_error($message, $type);
    }
}
?>


