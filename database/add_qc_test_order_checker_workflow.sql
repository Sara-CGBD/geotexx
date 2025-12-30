-- Add checker workflow columns to qc_test_orders table
-- This enables a two-tier approval process: Checker -> Admin

-- Check if table exists, create if not
CREATE TABLE IF NOT EXISTS qc_test_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sample_reference_id VARCHAR(255) NOT NULL,
    report_number VARCHAR(50) UNIQUE,
    test_standard_id INT,
    chosen_method VARCHAR(100),
    test_data JSON,
    inspector_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Add checker workflow columns if they don't exist
-- Use procedure to check column existence for MySQL/MariaDB compatibility

SET @dbname = DATABASE();
SET @tablename = 'qc_test_orders';

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'status');
SET @sqlstmt = IF(@col_exists = 0, 
    'ALTER TABLE qc_test_orders ADD COLUMN status ENUM(''pending_checker'',''pending_approval'',''approved'',''rejected_by_checker'',''rejected_by_approver'') DEFAULT ''pending_checker''', 
    'SELECT ''Column status already exists'' as msg');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'checked_by');
SET @sqlstmt = IF(@col_exists = 0, 
    'ALTER TABLE qc_test_orders ADD COLUMN checked_by VARCHAR(100) NULL', 
    'SELECT ''Column checked_by already exists'' as msg');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'checked_at');
SET @sqlstmt = IF(@col_exists = 0, 
    'ALTER TABLE qc_test_orders ADD COLUMN checked_at DATETIME NULL', 
    'SELECT ''Column checked_at already exists'' as msg');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'checker_remarks');
SET @sqlstmt = IF(@col_exists = 0, 
    'ALTER TABLE qc_test_orders ADD COLUMN checker_remarks TEXT NULL', 
    'SELECT ''Column checker_remarks already exists'' as msg');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'approved_by');
SET @sqlstmt = IF(@col_exists = 0, 
    'ALTER TABLE qc_test_orders ADD COLUMN approved_by VARCHAR(100) NULL', 
    'SELECT ''Column approved_by already exists'' as msg');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'approved_at');
SET @sqlstmt = IF(@col_exists = 0, 
    'ALTER TABLE qc_test_orders ADD COLUMN approved_at DATETIME NULL', 
    'SELECT ''Column approved_at already exists'' as msg');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'admin_remarks');
SET @sqlstmt = IF(@col_exists = 0, 
    'ALTER TABLE qc_test_orders ADD COLUMN admin_remarks TEXT NULL', 
    'SELECT ''Column admin_remarks already exists'' as msg');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = @dbname AND TABLE_NAME = @tablename AND COLUMN_NAME = 'inspector_name');
SET @sqlstmt = IF(@col_exists = 0, 
    'ALTER TABLE qc_test_orders ADD COLUMN inspector_name VARCHAR(255) NULL', 
    'SELECT ''Column inspector_name already exists'' as msg');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;

-- Update existing rows to have pending_checker status if they are NULL
UPDATE qc_test_orders SET status = 'pending_checker' WHERE status IS NULL;

-- Add indexes for performance (ignore if exist)
ALTER TABLE qc_test_orders ADD INDEX idx_qc_status (status);
ALTER TABLE qc_test_orders ADD INDEX idx_qc_inspector (inspector_id);
ALTER TABLE qc_test_orders ADD INDEX idx_qc_report_number (report_number);

