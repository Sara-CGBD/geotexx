<?php
// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();
require_once 'config/security_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate inputs
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error_message = "All fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "New passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $error_message = "New password must be at least 6 characters long.";
    } else {
        // Get current user's password from database
        $user_id = $_SESSION['user_id'];
        $conn = SecurityConfig::getConnection();
        
        // Try both tables like the login system does
        $user = null;
        $tables_to_try = ['users', 'new_user'];
        
        foreach ($tables_to_try as $table) {
            // Check which columns exist in this table
            $columns_result = $conn->query("SHOW COLUMNS FROM $table");
            $has_password = false;
            $has_password_hash = false;
            
            if ($columns_result) {
                while ($column = $columns_result->fetch_assoc()) {
                    if ($column['Field'] === 'password') $has_password = true;
                    if ($column['Field'] === 'password_hash') $has_password_hash = true;
                }
            }
            
            // Build SELECT query based on available columns
            $select_columns = [];
            if ($has_password) $select_columns[] = 'password';
            if ($has_password_hash) $select_columns[] = 'password_hash';
            
            if (!empty($select_columns)) {
                $select_query = "SELECT " . implode(', ', $select_columns) . " FROM $table WHERE id = ?";
                $stmt = $conn->prepare($select_query);
                if ($stmt) {
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $user = $result->fetch_assoc();
                        $update_table = $table;
                        $table_has_password = $has_password;
                        $table_has_password_hash = $has_password_hash;
                        break;
                    }
                }
            }
        }
        
        if ($user) {
            // Check password based on available columns
            $password_valid = false;
            
            // Try password_hash first if it exists
            if ($table_has_password_hash && isset($user['password_hash']) && !empty($user['password_hash'])) {
                $password_valid = password_verify($current_password, $user['password_hash']);
            }
            
            // If password_hash didn't work, try password column
            if (!$password_valid && $table_has_password && isset($user['password']) && !empty($user['password'])) {
                if (password_get_info($user['password'])['algo'] !== null) {
                    // It's hashed
                    $password_valid = password_verify($current_password, $user['password']);
                } else {
                    // It's plain text
                    $password_valid = ($current_password === $user['password']);
                }
            }
            
            if ($password_valid) {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                
                // Update the appropriate table and column
                if ($table_has_password_hash) {
                    $update_stmt = $conn->prepare("UPDATE $update_table SET password_hash = ? WHERE id = ?");
                } else {
                    $update_stmt = $conn->prepare("UPDATE $update_table SET password = ? WHERE id = ?");
                }
                
                $update_stmt->bind_param("si", $new_hash, $user_id);
                if ($update_stmt->execute()) {
                    $success_message = "Password changed successfully!";
                } else {
                    $error_message = "Failed to update password. Please try again.";
                }
            } else {
                $error_message = "Current password is incorrect.";
            }
        } else {
            $error_message = "User not found.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Change Password</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        body { 
            font-family:'Inter',sans-serif; 
            background:#f4f6f9; 
            margin:0; 
            padding:15px; 
            color:#2c3e50; 
        }
        
        .container { 
            max-width:500px; 
            margin:auto; 
            background:#fff; 
            border-radius:12px; 
            padding:25px 20px; 
            box-shadow:0 4px 20px rgba(0,0,0,0.08);
        }
        
        h1 { 
            text-align:center; 
            font-size:24px; 
            margin-bottom:15px; 
        }
        
        .form-group { 
            margin-bottom:15px; 
        }
        
        label { 
            font-weight:600; 
            display:block; 
            margin-bottom:8px; 
        }
        
        input[type="password"] {
            padding:10px; 
            border:1px solid #ccc; 
            border-radius:6px; 
            width:100%;
            font-size:15px;
        }
        
        input[type="password"]:focus {
            outline:none;
            border-color:#3498db;
        }
        
        .alert { 
            padding:12px; 
            border-radius:6px; 
            margin-bottom:15px; 
        }
        
        .alert-success { 
            background:#d4edda; 
            color:#155724; 
            border:1px solid #c3e6cb; 
        }
        
        .alert-error { 
            background:#f8d7da; 
            color:#721c24; 
            border:1px solid #f5c6cb; 
        }
        
        .actions { 
            margin-top:15px; 
            text-align:center; 
        }
        
        .actions button { 
            padding:10px 20px; 
            font-size:15px; 
            border:none; 
            border-radius:6px; 
            cursor:pointer; 
            margin:0 10px;
        }
        
        .submit-btn { 
            background:#2ecc71; 
            color:#fff; 
        }
        
        .submit-btn:hover { 
            background:#27ae60; 
        }
        
        .back-btn { 
            background:#e74c3c; 
            color:#fff; 
            text-decoration:none;
            display:inline-block;
        }
        
        .back-btn:hover { 
            background:#c0392b; 
        }
        
        .password-field-wrapper {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            font-size: 1.1em;
        }
        
        .password-toggle:hover {
            color: #3498db;
        }
        
        .requirements {
            margin-top: 10px;
            padding: 10px 12px;
            background-color: #f8f9fa;
            border-radius: 6px;
            border-left: 4px solid #3498db;
            font-size: 13px;
        }
        
        .requirements h5 {
            color: #2c3e50;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 15px;
        }
        
        .requirements ul {
            margin: 0;
            padding-left: 20px;
        }
        
        .requirements li {
            margin-bottom: 5px;
            color: #555;
        }
        
        .requirements li i {
            color: #3498db;
            margin-right: 5px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Change Password</h1>
        
        <!-- Back to Dashboard Link -->
        <div style="margin-bottom: 12px;">
            <a href="index.php" class="back-btn" style="padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
                ← Back to Dashboard
            </a>
        </div>
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <strong>✓ Success:</strong> <?= htmlspecialchars($success_message) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <strong>✗ Error:</strong> <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" id="passwordForm">
            <div class="form-group">
                <label>Current Password:</label>
                <div class="password-field-wrapper">
                    <input type="password" name="current_password" placeholder="Enter your current password" required>
                    <button type="button" class="password-toggle" onclick="togglePassword(this)">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <div class="form-group">
                <label>New Password:</label>
                <div class="password-field-wrapper">
                    <input type="password" name="new_password" id="newPassword" placeholder="Enter your new password" required>
                    <button type="button" class="password-toggle" onclick="togglePassword(this)">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <div class="form-group">
                <label>Confirm New Password:</label>
                <div class="password-field-wrapper">
                    <input type="password" name="confirm_password" id="confirmPassword" placeholder="Confirm your new password" required>
                    <button type="button" class="password-toggle" onclick="togglePassword(this)">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <div class="requirements">
                <h5>Password Requirements:</h5>
                <ul>
                    <li><i class="fas fa-check"></i> Minimum 6 characters long</li>
                    <li><i class="fas fa-check"></i> Should be different from current password</li>
                    <li><i class="fas fa-check"></i> Use a combination of letters, numbers, and symbols</li>
                </ul>
            </div>
            
            <div class="actions">
                <button type="submit" class="submit-btn">Update Password</button>
            </div>
        </form>
    </div>
    
    <script>
        function togglePassword(button) {
            const input = button.previousElementSibling;
            const icon = button.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html> 
