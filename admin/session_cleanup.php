<?php
/**
 * Session Cleanup Script
 * Removes expired sessions from active_sessions table
 */

require_once 'config.php';

function cleanupExpiredSessions() {
    global $conn;
    
    // Remove sessions that haven't been active for more than 30 minutes
    $sql = "UPDATE active_sessions SET is_active = 0 
            WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 MINUTE) 
            AND is_active = 1";
    
    $result = $conn->query($sql);
    
    if ($result) {
        $affected_rows = $conn->affected_rows;
        echo "✅ Cleaned up $affected_rows expired sessions\n";
        return $affected_rows;
    } else {
        echo "❌ Error cleaning up sessions: " . $conn->error . "\n";
        return 0;
    }
}

// Run cleanup if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    echo "=== Session Cleanup ===\n";
    cleanupExpiredSessions();
    echo "🎉 Cleanup completed!\n";
}
?> 
