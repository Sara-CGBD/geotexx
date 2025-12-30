-- Update fabric_after_production_tests table for new Reference field structure
-- Run this in phpMyAdmin

USE geobagg;

-- Drop old columns that are no longer needed
ALTER TABLE fabric_after_production_tests DROP COLUMN IF EXISTS gsm;
ALTER TABLE fabric_after_production_tests DROP COLUMN IF EXISTS line_number;
ALTER TABLE fabric_after_production_tests DROP COLUMN IF EXISTS roll_number;
ALTER TABLE fabric_after_production_tests DROP COLUMN IF EXISTS batch_information;

-- Ensure the table exists with correct structure (without gsm, line_number, roll_number, batch_information)
CREATE TABLE IF NOT EXISTS fabric_after_production_tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_number VARCHAR(50) UNIQUE NOT NULL,
    sample_id VARCHAR(200) COMMENT 'Reference from fiber_to_roll_entry',
    received_from VARCHAR(200),
    sample_received_date DATETIME,
    sample_tested_date DATETIME,
    test_performed_by VARCHAR(200),
    note TEXT,
    test_results JSON,
    approved_by VARCHAR(200),
    qc_entry_id INT,
    reporter_id INT,
    reporter_name VARCHAR(100),
    status VARCHAR(50) DEFAULT 'pending',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_deleted TINYINT(1) DEFAULT 0,
    INDEX idx_status (status),
    INDEX idx_reference (sample_id),
    INDEX idx_report_date (sample_tested_date),
    INDEX idx_reporter (reporter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Increase sample_id column size to handle longer reference numbers
ALTER TABLE fabric_after_production_tests 
MODIFY COLUMN sample_id VARCHAR(200) COMMENT 'Reference from fiber_to_roll_entry';

-- Add index on sample_id for faster lookups if not exists
CREATE INDEX IF NOT EXISTS idx_reference ON fabric_after_production_tests(sample_id);

-- Verify the structure
SELECT 'Table structure updated successfully' AS Status;
DESCRIBE fabric_after_production_tests;

-- Show sample data
SELECT 
    id,
    report_number,
    sample_id as reference,
    received_from,
    status,
    test_performed_by,
    created_at
FROM fabric_after_production_tests
ORDER BY id DESC
LIMIT 10;


