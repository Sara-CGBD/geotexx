-- SQL Script to remove GSM, line_no, roll_no columns from roll_entry table
-- Run this in phpMyAdmin or MySQL command line

USE geobagg;

-- Check current structure
DESC roll_entry;

-- Remove columns if they exist
SET @exist_gsm = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                   WHERE TABLE_SCHEMA = 'geobagg' AND TABLE_NAME = 'roll_entry' AND COLUMN_NAME = 'gsm');
SET @exist_line_no = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                      WHERE TABLE_SCHEMA = 'geobagg' AND TABLE_NAME = 'roll_entry' AND COLUMN_NAME = 'line_no');
SET @exist_roll_no = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                      WHERE TABLE_SCHEMA = 'geobagg' AND TABLE_NAME = 'roll_entry' AND COLUMN_NAME = 'roll_no');
SET @exist_line_number = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                          WHERE TABLE_SCHEMA = 'geobagg' AND TABLE_NAME = 'roll_entry' AND COLUMN_NAME = 'line_number');
SET @exist_roll_number = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                          WHERE TABLE_SCHEMA = 'geobagg' AND TABLE_NAME = 'roll_entry' AND COLUMN_NAME = 'roll_number');

-- Drop columns (will fail silently if they don't exist)
ALTER TABLE roll_entry DROP COLUMN IF EXISTS gsm;
ALTER TABLE roll_entry DROP COLUMN IF EXISTS line_no;
ALTER TABLE roll_entry DROP COLUMN IF EXISTS roll_no;
ALTER TABLE roll_entry DROP COLUMN IF EXISTS line_number;
ALTER TABLE roll_entry DROP COLUMN IF EXISTS roll_number;

-- Show final structure
SELECT 'Final table structure:' AS Info;
DESC roll_entry;


