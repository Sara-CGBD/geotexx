-- Enhanced UV Test Database Schema
-- This file contains all necessary tables for the UV Test (Weathering Exposure Test) functionality

USE geobagg;

-- Main UV Test Reports Table
CREATE TABLE IF NOT EXISTS weathering_exposure_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_number VARCHAR(100) UNIQUE NOT NULL,
    sample_received_from VARCHAR(255) NOT NULL,
    sample_collected_from VARCHAR(255) NOT NULL,
    reference VARCHAR(255) NOT NULL,
    sample_description TEXT NOT NULL,
    recipe VARCHAR(255) NOT NULL,
    received_date DATETIME NOT NULL,
    test_start_date DATE NOT NULL,
    test_end_date DATE NOT NULL,
    testing_method VARCHAR(255) NOT NULL,
    test_name VARCHAR(255) NOT NULL,
    test_speed VARCHAR(255) NOT NULL,
    gauge_length VARCHAR(255) NOT NULL,
    specimen_size VARCHAR(255) NOT NULL,
    note TEXT,
    temperature DECIMAL(10,2) NOT NULL,
    rh_percent DECIMAL(5,2) NOT NULL,
    test_performed_by VARCHAR(100) NOT NULL,
    approved_by VARCHAR(100) NOT NULL,
    test_results JSON NOT NULL,
    reporter_id INT NOT NULL,
    reporter_name VARCHAR(255) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_report_number (report_number),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_reporter_id (reporter_id),
    INDEX idx_approved_by (approved_by)
);

-- Counter Table for Report Number Generation
CREATE TABLE IF NOT EXISTS weathering_exposure_counters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    day_key VARCHAR(8) UNIQUE NOT NULL,
    counter INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_day_key (day_key)
);

-- Activity Logs Table
CREATE TABLE IF NOT EXISTS weathering_exposure_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT,
    action VARCHAR(100) NOT NULL,
    details TEXT,
    user_id INT,
    username VARCHAR(255),
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report_id (report_id),
    INDEX idx_action (action),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_username (username)
);

-- Approval Comments Table
CREATE TABLE IF NOT EXISTS weathering_exposure_approval_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    approver_name VARCHAR(255) NOT NULL,
    approver_role VARCHAR(100) NOT NULL,
    action ENUM('approved', 'rejected') NOT NULL,
    comments TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report_id (report_id),
    INDEX idx_approver_name (approver_name),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
);

-- Statistics Cache Table
CREATE TABLE IF NOT EXISTS weathering_exposure_statistics_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(100) UNIQUE NOT NULL,
    cache_value TEXT NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cache_key (cache_key),
    INDEX idx_expires_at (expires_at)
);

-- User Preferences Table
CREATE TABLE IF NOT EXISTS weathering_exposure_user_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    username VARCHAR(255) NOT NULL,
    preference_key VARCHAR(100) NOT NULL,
    preference_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_preference (user_id, preference_key),
    INDEX idx_user_id (user_id),
    INDEX idx_username (username),
    INDEX idx_preference_key (preference_key)
);

-- Insert sample data for testing (optional)
-- INSERT INTO weathering_exposure_counters (day_key, counter) VALUES ('20241201', 0) ON DUPLICATE KEY UPDATE counter = counter;

-- Show table creation status
SELECT 'weathering_exposure_reports' as table_name, COUNT(*) as record_count FROM weathering_exposure_reports
UNION ALL
SELECT 'weathering_exposure_counters' as table_name, COUNT(*) as record_count FROM weathering_exposure_counters
UNION ALL
SELECT 'weathering_exposure_logs' as table_name, COUNT(*) as record_count FROM weathering_exposure_logs
UNION ALL
SELECT 'weathering_exposure_approval_comments' as table_name, COUNT(*) as record_count FROM weathering_exposure_approval_comments
UNION ALL
SELECT 'weathering_exposure_statistics_cache' as table_name, COUNT(*) as record_count FROM weathering_exposure_statistics_cache
UNION ALL
SELECT 'weathering_exposure_user_preferences' as table_name, COUNT(*) as record_count FROM weathering_exposure_user_preferences;

