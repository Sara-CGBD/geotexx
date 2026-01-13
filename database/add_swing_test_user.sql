-- Add swing_test user for Bag Production Module
-- Password: Test@123
-- NOTE: Run fix_swing_test_user.php instead to generate correct password hash dynamically
-- Or use: SELECT password_hash('Test@123', PASSWORD_BCRYPT) to generate hash

USE geobagg;

-- Delete existing swing_test user if it exists (to avoid duplicates)
DELETE FROM users WHERE username = 'swing_test';
DELETE FROM new_user WHERE username = 'swing_test';

-- Insert into users table
INSERT INTO users (username, full_name, password, role, email, status) 
VALUES (
    'swing_test', 
    'Swing Test User', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 
    'swing_test', 
    'swing@test.com', 
    'active'
);

-- Insert into new_user table (if it exists)
INSERT INTO new_user (username, full_name, password_hash, email, role, is_active) 
VALUES (
    'swing_test', 
    'Swing Test User', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 
    'swing@test.com', 
    'swing_test', 
    1
);

-- Verify the user was created
SELECT id, username, full_name, role, email, status FROM users WHERE username = 'swing_test';
SELECT id, username, full_name, role, email, is_active FROM new_user WHERE username = 'swing_test';

