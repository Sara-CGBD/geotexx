-- Update characteristics_test database table to match new structure
-- This ensures the table has all required columns for the new report number format

USE geotex;

-- Ensure the table exists with correct structure
CREATE TABLE IF NOT EXISTS characteristics_tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_number VARCHAR(100) UNIQUE NOT NULL,
    lab_test_number VARCHAR(50) NOT NULL,
    test_standard VARCHAR(100) NULL,
    test_materials VARCHAR(255) NOT NULL,
    reference_number VARCHAR(200) NULL,
    gsm DECIMAL(10,4) NOT NULL,
    roll_number VARCHAR(100) NOT NULL,
    sample_id VARCHAR(100) NOT NULL,
    specimen_size DECIMAL(10,4) NOT NULL,
    specimen_size_unit VARCHAR(10) NOT NULL,
    sand_type VARCHAR(100) NOT NULL,
    sand_weight DECIMAL(10,4) NOT NULL,
    sand_weight_unit VARCHAR(10) NOT NULL,
    sieving_time DECIMAL(10,4) NOT NULL,
    sieving_time_unit VARCHAR(10) NOT NULL,
    sample_received DATE NOT NULL,
    sample_tested DATETIME NOT NULL,
    test_results JSON NOT NULL,
    test_performed_by VARCHAR(255) NOT NULL,
    checker_name VARCHAR(255) NULL,
    approver_name VARCHAR(255) NULL,
    checked_at DATETIME NULL,
    approved_at DATETIME NULL,
    reporter_id INT NOT NULL,
    reporter_name VARCHAR(255) NOT NULL,
    status ENUM('pending', 'checked', 'approved', 'rejected', 'resubmitted') DEFAULT 'pending',
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_report_number (report_number),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_reporter_id (reporter_id)
);

-- Add missing columns for existing installations
ALTER TABLE characteristics_tests ADD COLUMN IF NOT EXISTS test_standard VARCHAR(100) NULL AFTER lab_test_number;
ALTER TABLE characteristics_tests ADD COLUMN IF NOT EXISTS reference_number VARCHAR(200) NULL AFTER test_materials;

-- Add indexes if they don't exist (MySQL 8.0+ supports IF NOT EXISTS for indexes)
-- Note: For older MySQL versions, you may need to check and add indexes manually

SELECT 'Database update completed successfully!' AS status;

