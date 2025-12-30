-- Add 'pending_tester' status to qc_test_orders table ENUM
-- This allows AGM to forward external tests to testers

-- Update the status ENUM to include 'pending_tester'
-- DEFAULT is set to 'pending_checker' for backward compatibility
-- Note: PHP code explicitly sets status, so DEFAULT is only a fallback
ALTER TABLE qc_test_orders 
MODIFY COLUMN status ENUM('pending_tester','pending_checker','pending_approval','approved','rejected_by_checker','rejected_by_approver') 
DEFAULT 'pending_checker';

-- Note: The PHP code explicitly sets status, so DEFAULT NULL is just a safety measure
-- If any NULL statuses exist, they should be reviewed and set appropriately
-- UPDATE qc_test_orders SET status = 'pending_checker' WHERE status IS NULL;

