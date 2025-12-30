<?php
// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();
require_once '../config/security_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

$success_message = '';
$error_message = '';
$user_data = [];

// Get current user data
$user_id = $_SESSION['user_id'];
$conn = SecurityConfig::getConnection();

// Try both tables like the login system does
$tables_to_try = ['users', 'new_user'];
$user_data = [];

foreach ($tables_to_try as $table) {
    // Check which columns exist in this table
    $columns_result = $conn->query("SHOW COLUMNS FROM $table");
    $available_columns = [];
    
    if ($columns_result) {
        while ($column = $columns_result->fetch_assoc()) {
            $available_columns[] = $column['Field'];
        }
    }
    
    // Build SELECT query based on available columns
    $select_columns = [];
    $desired_columns = ['username', 'email', 'first_name', 'last_name', 'role', 'designation', 'phone', 'employee_id', 'phone_number', 'mobile', 'contact'];
    
    foreach ($desired_columns as $col) {
        if (in_array($col, $available_columns)) {
            $select_columns[] = $col;
        }
    }
    
    // Debug: Log what columns are found
    error_log("Table: $table, Available columns: " . implode(', ', $available_columns));
    error_log("Selected columns: " . implode(', ', $select_columns));
    
    if (!empty($select_columns)) {
        $select_query = "SELECT " . implode(', ', $select_columns) . " FROM $table WHERE id = ?";
        $stmt = $conn->prepare($select_query);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $user_data = $result->fetch_assoc();
                error_log("User data fetched: " . print_r($user_data, true));
                break; // Found user, stop trying other tables
            }
        }
    }
}

// Handle form submission for profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $email = trim($_POST['email'] ?? '');
        
        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Please enter a valid email address.";
        } else {
            // Find which table the user is in and update accordingly
            $update_table = null;
            foreach ($tables_to_try as $table) {
                $check_stmt = $conn->prepare("SELECT id FROM $table WHERE id = ?");
                if ($check_stmt) {
                    $check_stmt->bind_param("i", $user_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    if ($check_result->num_rows > 0) {
                        $update_table = $table;
                        break;
                    }
                }
            }
            
            if ($update_table) {
                // Check if email column exists in this table
                $columns_result = $conn->query("SHOW COLUMNS FROM $update_table LIKE 'email'");
                if ($columns_result && $columns_result->num_rows > 0) {
                    $update_stmt = $conn->prepare("UPDATE $update_table SET email = ? WHERE id = ?");
                    $update_stmt->bind_param("si", $email, $user_id);
                    
                    if ($update_stmt->execute()) {
                        $success_message = "Profile updated successfully!";
                        $user_data['email'] = $email; // Update local data
                    } else {
                        $error_message = "Failed to update profile. Please try again.";
                    }
                } else {
                    $error_message = "Email field not available in your user table.";
                }
            } else {
                $error_message = "User not found in database.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings - GEOCIL #333Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        body {
            background: #f0f2f5;
            font-family: 'Inter', sans-serif;
            color: #333;
        }
        
        .profile-container {
            max-width: 800px;
            margin: 50px auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }
        
        .profile-header {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            padding: 40px;
            text-align: center;
        }
        
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2.5em;
        }
        
        .profile-header h2 {
            margin: 0;
            font-weight: 600;
            font-size: 1.8em;
        }
        
        .profile-header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
            font-size: 0.95em;
        }
        
        .profile-body {
            padding: 40px;
        }
        
        .info-section {
            margin-bottom: 30px;
        }
        
        .info-section h4 {
            color: #2c3e50;
            margin-bottom: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .info-card {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #3498db;
        }
        
        .info-label {
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-value {
            color: #2c3e50;
            font-size: 1.1em;
            font-weight: 500;
        }
        
        .role-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .role-admin { background: linear-gradient(135deg, #e74c3c, #c0392b); color: white; }
        .role-management { background: linear-gradient(135deg, #f39c12, #e67e22); color: white; }
        .role-spinning { background: linear-gradient(135deg, #3498db, #2980b9); color: white; }
        .role-qc { background: linear-gradient(135deg, #27ae60, #2ecc71); color: white; }
        .role-yard { background: linear-gradient(135deg, #9b59b6, #8e44ad); color: white; }
        .role-user { background: linear-gradient(135deg, #95a5a6, #7f8c8d); color: white; }
        
        .alert {
            border-radius: 8px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 25px;
            font-weight: 500;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
        }
        
        .stats-section {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .stat-icon {
            font-size: 2em;
            color: #3498db;
            margin-bottom: 10px;
        }
        
        .stat-value {
            font-size: 1.5em;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #666;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 30px;
        }
        
        .action-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            text-decoration: none;
            color: #333;
        }
        
        .action-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            color: #3498db;
        }
        
        .action-card i {
            font-size: 2em;
            color: #3498db;
            margin-bottom: 10px;
        }
        
        .action-card h5 {
            margin: 0;
            font-weight: 600;
        }
        
        .action-card p {
            margin: 5px 0 0 0;
            font-size: 0.9em;
            color: #666;
        }
        
        .btn-secondary {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            font-size: 0.95em;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            margin-left: 10px;
        }
        
        .btn-secondary:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(149, 165, 166, 0.3);
            color: white;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-label {
            font-weight: 600;
            color: #2c3e50;
            margin-bottom: 8px;
            display: block;
        }
        
        .form-control {
            border: 2px solid #e9ecef;
            border-radius: 8px;
            padding: 12px 15px;
            font-size: 1em;
            transition: all 0.3s ease;
            background-color: #f8f9fa;
        }
        
        .form-control:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
            background-color: white;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #3498db, #2980b9);
            border: none;
            border-radius: 8px;
            padding: 12px 25px;
            font-size: 1em;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="profile-container">
            <div class="profile-header">
                <div class="profile-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <h2><?= htmlspecialchars($user_data['username'] ?? 'User') ?></h2>
                <p>Manage your account settings and preferences</p>
            </div>
            
            <div class="profile-body">
                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>
                
                <!-- User Information Section -->
                <div class="info-section">
                    <h4><i class="fas fa-user-circle"></i> Account Information</h4>
                    <div class="info-grid">
                        <div class="info-card">
                            <div class="info-label">Username</div>
                            <div class="info-value"><?= htmlspecialchars($user_data['username'] ?? 'N/A') ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Email</div>
                            <div class="info-value"><?= htmlspecialchars($user_data['email'] ?? 'N/A') ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Full Name</div>
                            <div class="info-value">
                                <?= htmlspecialchars(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? '')) ?>
                            </div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Role</div>
                            <div class="info-value">
                                <span class="role-badge role-<?= strtolower($user_data['role'] ?? 'user') ?>">
                                    <?= htmlspecialchars(ucfirst($user_data['role'] ?? 'User')) ?>
                                </span>
                            </div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Designation</div>
                            <div class="info-value"><?= htmlspecialchars($user_data['designation'] ?? 'N/A') ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Employee ID</div>
                            <div class="info-value"><?= htmlspecialchars($user_data['employee_id'] ?? 'N/A') ?></div>
                        </div>
                        <div class="info-card">
                            <div class="info-label">Phone</div>
                            <div class="info-value"><?= htmlspecialchars($user_data['phone'] ?? $user_data['phone_number'] ?? $user_data['mobile'] ?? $user_data['contact'] ?? 'N/A') ?></div>
                        </div>
                    </div>
                </div>
                
                <!-- Account Statistics -->
                <div class="stats-section">
                    <h4><i class="fas fa-chart-bar"></i> Account Statistics</h4>
                    <div class="stats-grid">
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <div class="stat-value"><?= ucfirst($user_data['role'] ?? 'User') ?></div>
                            <div class="stat-label">Access Level</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-id-badge"></i>
                            </div>
                            <div class="stat-value"><?= htmlspecialchars($user_data['designation'] ?? 'N/A') ?></div>
                            <div class="stat-label">Designation</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-icon">
                                <i class="fas fa-hashtag"></i>
                            </div>
                            <div class="stat-value"><?= htmlspecialchars($user_data['employee_id'] ?? 'N/A') ?></div>
                            <div class="stat-label">Employee ID</div>
                        </div>
                    </div>
                </div>
                
                <!-- Profile Update Form -->
                <div class="info-section">
                    <h4><i class="fas fa-edit"></i> Update Profile</h4>
                    <form method="POST">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-envelope"></i> Email Address
                            </label>
                            <input type="email" name="email" class="form-control" 
                                   value="<?= htmlspecialchars($user_data['email'] ?? '') ?>" 
                                   placeholder="Enter your email address" required>
                        </div>
                        
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Profile
                        </button>
                        <a href="../change_password.php" target="main" class="btn btn-secondary">
                            <i class="fas fa-key"></i> Change Password
                        </a>
                    </form>
                </div>
                
                <!-- Quick Actions -->
                <div class="info-section">
                    <h4><i class="fas fa-bolt"></i> Quick Actions</h4>
                    <div class="quick-actions">
                        <a href="../change_password.php" target="main" class="action-card">
                            <i class="fas fa-key"></i>
                            <h5>Change Password</h5>
                            <p>Update your account password</p>
                        </a>
                        <a href="../index.php" class="action-card">
                            <i class="fas fa-home"></i>
                            <h5>Back to Dashboard</h5>
                            <p>Return to main dashboard</p>
                        </a>
                        <a href="../logout.php" class="action-card">
                            <i class="fas fa-sign-out-alt"></i>
                            <h5>Logout</h5>
                            <p>Sign out of your account</p>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 
