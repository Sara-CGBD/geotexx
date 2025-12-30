-- Add user directly to users table
USE geobagg;

-- Insert admin user with hashed password
INSERT INTO users (username, password, email, role) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@test.com', 'admin');

-- Show the result
SELECT * FROM users WHERE username = 'admin';

-- Insert AGM Ops user (password: ChangeMe123!)
-- NOTE: Replace password hash as needed. This is a sample bcrypt hash for 'ChangeMe123!'
INSERT INTO users (username, password, email, role)
VALUES ('Mosiur Rahman', '$2y$10$u5j8yZ2Oq9c9gk7q0b2Jt.5x3u1mP2pK3b8QwJ1fQbE2n8JbFQmQ2', 'mosiur.rahman@example.com', 'AGM Ops');

SELECT * FROM users WHERE username = 'Mosiur Rahman';

-- Ensure roles table exists and add 'tester' role
CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  role VARCHAR(100) UNIQUE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO roles (role) VALUES ('tester');

-- Verify role
SELECT * FROM roles WHERE role = 'tester';
