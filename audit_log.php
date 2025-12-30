<?php
/**
 * Audit Logging System
 * Tracks security events and user actions
 */

// Include database connection
require_once 'config/security_config.php';

// Cache audit_log columns to adapt to differing schemas
function audit_log_columns() {
    static $cols = null;
    if ($cols !== null) {
        return $cols;
    }
    $cols = [];
    $conn = SecurityConfig::getConnection();
    $res = $conn->query("SHOW COLUMNS FROM audit_log");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[] = strtolower($row['Field']);
        }
    }
    return $cols;
}

function log_security_event($event_type, $user_id, $details, $ip_address = null) {
    $conn = SecurityConfig::getConnection();
    $cols = audit_log_columns();
    
    if (!$ip_address) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    // Legacy schema: user_id, event_type, ip_address, details
    if (in_array('event_type', $cols, true)) {
    $stmt = $conn->prepare("INSERT INTO audit_log (user_id, event_type, ip_address, details) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            return false;
        }
    $stmt->bind_param("isss", $user_id, $event_type, $ip_address, $details);
        return $stmt->execute();
    }

    // Enterprise schema: table_name, action, JSON values, etc.
    $table_name   = 'auth';
    $record_id    = null;
    $action       = strtoupper($event_type);
    $old_values   = null;
    $new_values   = json_encode([
        'event'   => $event_type,
        'details' => $details,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $changed_by   = $user_id !== null ? (string)$user_id : 'system';
    $uid          = $user_id;

    $stmt = $conn->prepare("INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, changed_by, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param(
        "sissssiss",
        $table_name,
        $record_id,
        $action,
        $old_values,
        $new_values,
        $changed_by,
        $uid,
        $ip_address,
        $user_agent
    );
    return $stmt->execute();
}

function log_login_attempt($username, $success, $reason = '') {
    $event_type = $success ? 'LOGIN_SUCCESS' : 'LOGIN_FAILED';
    $details = "Username: $username" . ($reason ? ", Reason: $reason" : '');
    
    log_security_event($event_type, null, $details);
}

function log_user_action($user_id, $action, $details = '') {
    log_security_event('USER_ACTION', $user_id, "$action: $details");
}

function log_admin_action($user_id, $action, $target = '') {
    $details = "Admin action: $action" . ($target ? " on $target" : '');
    log_security_event('ADMIN_ACTION', $user_id, $details);
}

function log_security_violation($event_type, $details) {
    log_security_event('SECURITY_VIOLATION', null, "$event_type: $details");
}

// Create audit_log table if it doesn't exist
function create_audit_log_table() {
    $conn = SecurityConfig::getConnection();
    
    // If table already exists, do nothing
    $exists = $conn->query("SHOW TABLES LIKE 'audit_log'");
    if ($exists && $exists->num_rows > 0) {
        return true;
    }

    // Create enterprise-style audit_log table
    $sql = "CREATE TABLE IF NOT EXISTS audit_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        table_name VARCHAR(100) NOT NULL,
        record_id INT NULL,
        action VARCHAR(50) NOT NULL,
        old_values JSON NULL,
        new_values JSON NULL,
        changed_by VARCHAR(100),
        user_id INT NULL,
        ip_address VARCHAR(50),
        user_agent VARCHAR(255),
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_audit_table (table_name),
        INDEX idx_audit_action (action),
        INDEX idx_audit_user (changed_by),
        INDEX idx_audit_date (changed_at),
        INDEX idx_audit_record (table_name, record_id)
    ) ENGINE=InnoDB";
    
    return $conn->query($sql);
}

// Initialize audit log table
create_audit_log_table();
?> 
