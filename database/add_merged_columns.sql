-- Add merged_sewing_qty and merged_print_qty to branding_entries (for tracking same batch+bag_size)
-- Run in phpMyAdmin: select your database (e.g. geobagg), open SQL tab, run each statement.
-- If you get "Duplicate column name", that column already exists; skip or ignore.

-- 1) branding_entries
ALTER TABLE branding_entries ADD COLUMN merged_sewing_qty INT NULL;
ALTER TABLE branding_entries ADD COLUMN merged_print_qty INT NULL;

-- 2) sewing_machine_entry (only if this table exists in your DB)
ALTER TABLE sewing_machine_entry ADD COLUMN merged_sewing_qty INT NULL;
