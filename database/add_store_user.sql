-- Add store_user test account
-- Password: Test@123
-- IMPORTANT: Run the PHP script (add_store_user.php) instead for a fresh hash
-- Or generate a new hash using: SELECT password_hash('Test@123', PASSWORD_DEFAULT);

-- Delete existing store_user test account if it exists
DELETE FROM new_user WHERE username = 'store_test';

-- Insert store_user test account
-- NOTE: You need to generate a fresh hash. Use the PHP script instead.
-- Or run this in PHP to get the hash:
-- <?php echo password_hash('Test@123', PASSWORD_DEFAULT); ?>

-- Example with a generated hash (replace with fresh hash):
-- INSERT INTO new_user (username, password, password_hash, full_name, email, role, is_active, created_at) VALUES
-- ('store_test', 'GENERATED_HASH_HERE', 'GENERATED_HASH_HERE', 'Store User Test', 'store@test.com', 'store_user', 1, NOW());

-- Verify the user was created
SELECT username, full_name, email, role, is_active 
FROM new_user 
WHERE username = 'store_test';

