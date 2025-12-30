-- Add New Roles to Users Table
-- This script ensures the users table supports all 9 defined roles

-- Check current role column definition
-- ALTER TABLE new_user MODIFY COLUMN role ENUM('admin', 'production_user', 'qc_inspector', 'agm ops', 'tester', 'checker', 'finance_user', 'planning_user', 'management', 'user') DEFAULT 'user';

-- Or if the table doesn't have the role column yet:
-- ALTER TABLE new_user ADD COLUMN IF NOT EXISTS role VARCHAR(50) DEFAULT 'user';

-- Update role column to support all roles
ALTER TABLE new_user MODIFY COLUMN role VARCHAR(50) DEFAULT 'user';

-- Add index on role for faster queries
CREATE INDEX IF NOT EXISTS idx_user_role ON new_user(role);

-- Sample data for testing (optional - uncomment to use)
-- INSERT INTO new_user (username, password, full_name, email, role, is_active) VALUES
-- ('prod_user', '$2y$10$...hashed_password...', 'Production User', 'prod@example.com', 'production_user', 1),
-- ('qc_insp', '$2y$10$...hashed_password...', 'QC Inspector', 'qc@example.com', 'qc_inspector', 1),
-- ('finance', '$2y$10$...hashed_password...', 'Finance User', 'finance@example.com', 'finance_user', 1),
-- ('planner', '$2y$10$...hashed_password...', 'Planning User', 'planning@example.com', 'planning_user', 1);

-- Show all users and their roles
SELECT id, username, full_name, role, is_active FROM new_user ORDER BY role, username;

