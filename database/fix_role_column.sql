-- Fix Role Column for New Role Types
-- This script converts the role column from ENUM to VARCHAR to support all new roles

-- Step 1: Modify role column to VARCHAR (better for extensibility)
ALTER TABLE new_user 
MODIFY COLUMN role VARCHAR(50) DEFAULT 'user';

-- Step 2: Update any old role names to new standardized names (optional)
-- UPDATE new_user SET role = 'production_user' WHERE role = 'production';
-- UPDATE new_user SET role = 'qc_inspector' WHERE role = 'qc';
-- UPDATE new_user SET role = 'finance_user' WHERE role = 'finance';
-- UPDATE new_user SET role = 'planning_user' WHERE role = 'planning';

-- Step 3: Verify changes
SELECT DISTINCT role, COUNT(*) as count 
FROM new_user 
GROUP BY role 
ORDER BY role;

-- Expected roles:
-- admin
-- production_user
-- qc_inspector
-- agm ops (or agm operations)
-- tester
-- checker
-- finance_user
-- planning_user
-- management
-- user (default)

/*
=============================================================================
WHY USE VARCHAR INSTEAD OF ENUM?
=============================================================================

1. FLEXIBILITY - Easy to add new roles without ALTER TABLE
2. NO ENUM LIMITATIONS - ENUM has max 65,535 values but limited in practice
3. EASIER UPDATES - Can add roles dynamically via application
4. BETTER FOR RBAC - Role-based systems need flexibility
5. SIMPLER QUERIES - No need to check ENUM values

=============================================================================
AFTER RUNNING THIS SCRIPT:
=============================================================================

You can now add ANY role name without modifying the table structure!

Example:
INSERT INTO new_user (username, password, email, role, is_active) 
VALUES ('new_role_user', 'password_hash', 'email@test.com', 'any_new_role', 1);

=============================================================================
*/

