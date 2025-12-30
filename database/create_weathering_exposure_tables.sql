-- Create UV Test Tables
-- This file creates all necessary tables for the UV Test (Weathering Exposure Test) functionality

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
    INDEX idx_reporter_id (reporter_id)
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
    INDEX idx_created_at (created_at)
);

-- Show table creation status
SELECT 'weathering_exposure_reports' as table_name, COUNT(*) as record_count FROM weathering_exposure_reports
UNION ALL
SELECT 'weathering_exposure_counters' as table_name, COUNT(*) as record_count FROM weathering_exposure_counters
UNION ALL
SELECT 'weathering_exposure_logs' as table_name, COUNT(*) as record_count FROM weathering_exposure_logs;

