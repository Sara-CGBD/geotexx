-- Add actual_weight column to cnc_entries table
-- This column is required for the Management KPI Dashboard

-- Add the column (position not specified to avoid dependency on other columns)
ALTER TABLE cnc_entries 
ADD COLUMN actual_weight DECIMAL(10,2) DEFAULT 0;

-- Update existing rows to have default value (if any NULL values exist)
UPDATE cnc_entries SET actual_weight = 0 WHERE actual_weight IS NULL;

