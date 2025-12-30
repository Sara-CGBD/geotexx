-- Enhanced Database Schema for Sewing Thread Report System
-- This file contains all the tables needed for the enhanced backend functionality

-- Main reports table with enhanced structure
CREATE TABLE IF NOT EXISTS sewing_thread_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_number VARCHAR(100) UNIQUE NOT NULL,
    sample_description VARCHAR(255) NOT NULL,
    sample_received_from VARCHAR(255) NOT NULL,
    sample_collected_from VARCHAR(255) NOT NULL,
    reference VARCHAR(255) NOT NULL,
    received_date DATETIME NOT NULL,
    test_start_date DATE NOT NULL,
    test_end_date DATE NOT NULL,
    others_information TEXT,
    test_temperature DECIMAL(10,2) NOT NULL,
    rh_percent DECIMAL(5,2) NOT NULL,
    test_performed_by VARCHAR(100) NOT NULL,
    approved_by VARCHAR(100) NOT NULL,
    test_results JSON,
    reporter_id INT NOT NULL,
    reporter_name VARCHAR(255) NOT NULL,
    status ENUM('draft', 'submitted', 'approved', 'rejected') DEFAULT 'submitted',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_report_number (report_number),
    INDEX idx_received_date (received_date),
    INDEX idx_reporter (reporter_id),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Counter table for report numbering with enhanced structure
CREATE TABLE IF NOT EXISTS sewing_thread_counters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    day_key VARCHAR(8) UNIQUE NOT NULL,
    counter INT DEFAULT 0 NOT NULL,
    last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_day_key (day_key),
    INDEX idx_last_used (last_used)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Activity logs table for audit trail
CREATE TABLE IF NOT EXISTS sewing_thread_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(50) NOT NULL,
    report_id INT,
    report_number VARCHAR(100),
    user_id INT NOT NULL,
    user_name VARCHAR(255) NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action (action),
    INDEX idx_user (user_id),
    INDEX idx_timestamp (timestamp),
    INDEX idx_report_id (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backup table for data recovery
CREATE TABLE IF NOT EXISTS sewing_thread_backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_name VARCHAR(255) NOT NULL,
    backup_data JSON NOT NULL,
    created_by VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    file_size INT,
    INDEX idx_created_at (created_at),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Report statistics cache table for performance
CREATE TABLE IF NOT EXISTS sewing_thread_stats_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stat_type VARCHAR(50) NOT NULL,
    stat_value JSON NOT NULL,
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    INDEX idx_stat_type (stat_type),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Approval comments table for tracking approval decisions
CREATE TABLE IF NOT EXISTS sewing_thread_approval_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    action VARCHAR(20) NOT NULL,
    comments TEXT,
    approver_id INT NOT NULL,
    approver_name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report_id (report_id),
    INDEX idx_action (action),
    INDEX idx_approver (approver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User preferences table
CREATE TABLE IF NOT EXISTS sewing_thread_user_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    preference_key VARCHAR(100) NOT NULL,
    preference_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_preference (user_id, preference_key),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Report templates table for future enhancement
CREATE TABLE IF NOT EXISTS sewing_thread_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_name VARCHAR(255) NOT NULL,
    template_data JSON NOT NULL,
    created_by VARCHAR(255) NOT NULL,
    is_public BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_template_name (template_name),
    INDEX idx_created_by (created_by),
    INDEX idx_is_public (is_public)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert some default data
INSERT IGNORE INTO sewing_thread_templates (template_name, template_data, created_by, is_public) VALUES
('Standard Sewing Thread Test', '{"test_parameters":[{"param":"Denier","standards":["ISO 2060"],"unit":"dTex"},{"param":"Tenacity at Break","standards":["ASTM D2256"],"unit":"cN/dTex"},{"param":"Std Deviation","standards":["ASTM D2256"],"unit":"cN/dTex"},{"param":"CV%","standards":["ASTM D2256"],"unit":"%"},{"param":"Elongation at Break","standards":["ASTM D2256"],"unit":"%"}]}', 'system', TRUE);

-- Create views for common queries
CREATE OR REPLACE VIEW sewing_thread_reports_summary AS
SELECT 
    r.id,
    r.report_number,
    r.sample_description,
    r.sample_received_from,
    r.status,
    r.created_at,
    r.updated_at,
    u.username as reporter_name,
    COUNT(l.id) as log_count
FROM sewing_thread_reports r
LEFT JOIN users u ON r.reporter_id = u.id
LEFT JOIN sewing_thread_logs l ON r.id = l.report_id
GROUP BY r.id, r.report_number, r.sample_description, r.sample_received_from, r.status, r.created_at, r.updated_at, u.username;

-- Create view for daily statistics
CREATE OR REPLACE VIEW sewing_thread_daily_stats AS
SELECT 
    DATE(created_at) as report_date,
    COUNT(*) as total_reports,
    COUNT(CASE WHEN status = 'submitted' THEN 1 END) as submitted_reports,
    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_reports,
    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_reports,
    COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft_reports
FROM sewing_thread_reports
GROUP BY DATE(created_at)
ORDER BY report_date DESC;
