-- Add a checker user for testing the workflow
-- Password: checker123 (hashed)

INSERT INTO users (username, password, role, full_name, email, created_at) 
VALUES (
    'checker',
    '$2y$10$ZKxH8qF.yW5yqvH3bGxQv.R7KX7P.QL8W0fZC5vQV7WZlG8X8ZQ8S', 
    'checker',
    'QC Checker',
    'checker@example.com',
    NOW()
)
ON DUPLICATE KEY UPDATE 
    role = 'checker',
    full_name = 'QC Checker';

-- Update existing test data if any
UPDATE fabric_pre_production_tests 
SET status = 'pending_checker' 
WHERE status = 'pending';

