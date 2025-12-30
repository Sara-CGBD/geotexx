-- Add full_name column to new_user table if it doesn't exist
-- This ensures compatibility with the role-based access control system

-- Step 1: Add full_name column
ALTER TABLE new_user 
ADD COLUMN full_name VARCHAR(100) NULL AFTER username;

-- If you get an error that the column already exists, that's fine - skip to Step 2

-- Step 2: Create test users for all 9 roles
-- Password for all users: Test@123
-- Hash: $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi

-- Delete existing test users if they exist (to avoid duplicates)
DELETE FROM new_user WHERE username IN (
    'planning_test', 'prod_test', 'qc_test', 'tester_test', 
    'checker_test', 'agm_test', 'finance_test', 'mgmt_test'
);

-- Insert test users for all roles
INSERT INTO new_user (username, password, full_name, email, role, is_active, created_at) VALUES
-- Planning User
('planning_test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Planning Test User', 'planning@test.com', 'planning_user', 1, NOW()),

-- Production User
('prod_test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Production Test User', 'prod@test.com', 'production_user', 1, NOW()),

-- QC Inspector
('qc_test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'QC Inspector Test', 'qc@test.com', 'qc_inspector', 1, NOW()),

-- Lab Tester
('tester_test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Lab Tester Test', 'tester@test.com', 'tester', 1, NOW()),

-- Checker
('checker_test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Checker Test User', 'checker@test.com', 'checker', 1, NOW()),

-- AGM Operations
('agm_test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'AGM Operations Test', 'agm@test.com', 'agm ops', 1, NOW()),

-- Finance User
('finance_test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Finance Test User', 'finance@test.com', 'finance_user', 1, NOW()),

-- Management
('mgmt_test', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Management Test User', 'mgmt@test.com', 'management', 1, NOW());

-- Step 3: Verify the users were created
SELECT username, full_name, email, role, is_active FROM new_user WHERE username LIKE '%_test';

-- Expected output: 8 test users with all roles

/*
=============================================================================
TEST USER CREDENTIALS (All users have the same password)
=============================================================================

Password for ALL users: Test@123

1. Planning Module:
   Username: planning_test
   Password: Test@123

2. Production Module:
   Username: prod_test
   Password: Test@123

3. QC Inspector:
   Username: qc_test
   Password: Test@123

4. Lab Tester:
   Username: tester_test
   Password: Test@123

5. Checker (Fabric Pre-Production only):
   Username: checker_test
   Password: Test@123

6. AGM Operations:
   Username: agm_test
   Password: Test@123

7. Finance User:
   Username: finance_test
   Password: Test@123

8. Management (View-only):
   Username: mgmt_test
   Password: Test@123

9. Admin (already exists in your system):
   Username: admin
   Password: (your existing admin password)

=============================================================================
TO RUN THIS SCRIPT:
=============================================================================

Option 1: Via Command Line
   mysql -u root -p geobagg < database/add_full_name_and_test_users.sql

Option 2: Via phpMyAdmin
   1. Open phpMyAdmin: http://localhost/phpmyadmin
   2. Select 'geobagg' database
   3. Click 'SQL' tab
   4. Copy and paste this entire script
   5. Click 'Go'

=============================================================================
*/


