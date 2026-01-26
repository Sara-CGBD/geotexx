-- Branding entries: support grouped sewing data and traceability
-- Run this after branding_entries table exists (handler may create it with different columns).

-- Add sewing_entry_ids for traceability (comma-separated sewing_machine_entry.id)
ALTER TABLE branding_entries ADD COLUMN IF NOT EXISTS sewing_entry_ids TEXT NULL COMMENT 'Comma-separated sewing_machine_entry IDs' AFTER remaining_quantity;

-- Add status for workflow (optional)
ALTER TABLE branding_entries ADD COLUMN IF NOT EXISTS status ENUM('pending', 'in_progress', 'completed') DEFAULT 'pending' AFTER sewing_entry_ids;

-- Index for lookups
CREATE INDEX IF NOT EXISTS idx_branding_cutting_batch ON branding_entries(cnc_cutting_batch);
CREATE INDEX IF NOT EXISTS idx_branding_bag_size ON branding_entries(bag_size(50));
