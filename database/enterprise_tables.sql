-- Enterprise Tables Creation
-- Simple and reliable table creation

-- 1. System Performance
CREATE TABLE IF NOT EXISTS system_performance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    metric_name VARCHAR(100) NOT NULL,
    metric_value DECIMAL(10,2),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_metric_time (metric_name, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. System Health Checks
CREATE TABLE IF NOT EXISTS system_health_checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    check_type VARCHAR(50) NOT NULL,
    status ENUM('ok', 'warning', 'error') NOT NULL,
    message TEXT,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_check_status (check_type, status, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Error Log
CREATE TABLE IF NOT EXISTS error_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    error_type VARCHAR(50),
    error_message TEXT,
    file_path VARCHAR(255),
    line_number INT,
    user_id INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    request_url TEXT,
    occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_error_type_time (error_type, occurred_at),
    INDEX idx_error_user (user_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. API Access Log
CREATE TABLE IF NOT EXISTS api_access_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(255) NOT NULL,
    method VARCHAR(10) NOT NULL,
    user_id INT,
    ip_address VARCHAR(45),
    request_data TEXT,
    response_code INT,
    response_time DECIMAL(10,3),
    accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_endpoint (endpoint, accessed_at),
    INDEX idx_api_user (user_id, accessed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Backup Log
CREATE TABLE IF NOT EXISTS backup_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_type ENUM('full', 'incremental', 'differential') NOT NULL,
    file_name VARCHAR(255),
    file_size BIGINT,
    status ENUM('started', 'completed', 'failed') NOT NULL,
    started_at TIMESTAMP NOT NULL,
    completed_at TIMESTAMP NULL,
    error_message TEXT,
    INDEX idx_backup_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Archive Settings
CREATE TABLE IF NOT EXISTS archive_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(100) NOT NULL UNIQUE,
    archive_after_days INT NOT NULL DEFAULT 365,
    is_enabled TINYINT(1) DEFAULT 1,
    last_archived_at TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Slow Query Log
CREATE TABLE IF NOT EXISTS slow_query_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    query_hash VARCHAR(64),
    query_text TEXT,
    execution_time DECIMAL(10,3),
    rows_examined INT,
    user_id INT,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_query_time (execution_time DESC, executed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default archive settings
INSERT INTO archive_settings (table_name, archive_after_days, is_enabled) VALUES
('qc_test_orders', 730, 0),
('audit_log', 180, 1),
('login_attempts', 90, 1),
('error_log', 90, 1)
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP;

-- Add performance indexes
CREATE INDEX IF NOT EXISTS idx_qc_status_inspector ON qc_test_orders(status, inspector_id, updated_at);
CREATE INDEX IF NOT EXISTS idx_qc_report_status ON qc_test_orders(report_number, status);
CREATE INDEX IF NOT EXISTS idx_audit_user_event ON audit_log(user_id, event_type, created_at);
CREATE INDEX IF NOT EXISTS idx_user_role_active ON new_user(role, is_active, last_login);

SELECT 'All enterprise tables created successfully!' as status;

