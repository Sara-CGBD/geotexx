<?php
session_start();
require_once '../../config/security_config.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Role-based access control - Admin only
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if ($user_role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin role required.']);
    exit();
}

try {
    $conn = SecurityConfig::getConnection();
    
    // GET: Fetch all users or single user by ID
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $user_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
        
        if ($user_id) {
            // Fetch single user
            $stmt = $conn->prepare("SELECT * FROM new_user WHERE id = ?");
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            $stmt->close();
            
            if ($user) {
                // Remove password from response
                unset($user['password']);
                
                echo json_encode([
                    'success' => true,
                    'data' => $user
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found']);
            }
        } else {
            // Fetch all users
            $query = "SELECT id, username, full_name, email, role, mobile, employee_id, status, created_at 
                      FROM new_user 
                      ORDER BY id DESC";
            $result = $conn->query($query);
            
            $users = [];
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
            
            echo json_encode([
                'success' => true,
                'data' => $users,
                'total' => count($users)
            ]);
        }
    }
    
    // POST: Create new user
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $required_fields = ['username', 'password', 'full_name', 'email', 'role'];
        foreach ($required_fields as $field) {
            if (!isset($input[$field]) || empty($input[$field])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
                exit();
            }
        }
        
        $username = $input['username'];
        $password = password_hash($input['password'], PASSWORD_DEFAULT);
        $full_name = $input['full_name'];
        $email = $input['email'];
        $role = $input['role'];
        $mobile = $input['mobile'] ?? '';
        $employee_id = $input['employee_id'] ?? '';
        $status = $input['status'] ?? 'active';
        
        // Check if username or email already exists
        $check_stmt = $conn->prepare("SELECT id FROM new_user WHERE username = ? OR email = ?");
        $check_stmt->bind_param('ss', $username, $email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Username or email already exists']);
            exit();
        }
        $check_stmt->close();
        
        $stmt = $conn->prepare("INSERT INTO new_user (username, password, full_name, email, role, mobile, employee_id, status) 
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssssss', $username, $password, $full_name, $email, $role, $mobile, $employee_id, $status);
        
        if ($stmt->execute()) {
            $new_user_id = $conn->insert_id;
            $stmt->close();
            
            echo json_encode([
                'success' => true,
                'message' => 'User created successfully',
                'user_id' => $new_user_id
            ]);
        } else {
            throw new Exception('Failed to create user: ' . $stmt->error);
        }
    }
    
    // PUT: Update existing user
    elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            exit();
        }
        
        $user_id = (int)$input['id'];
        
        // Build update query dynamically based on provided fields
        $update_fields = [];
        $params = [];
        $types = '';
        
        if (isset($input['username'])) {
            $update_fields[] = "username = ?";
            $params[] = $input['username'];
            $types .= 's';
        }
        if (isset($input['full_name'])) {
            $update_fields[] = "full_name = ?";
            $params[] = $input['full_name'];
            $types .= 's';
        }
        if (isset($input['email'])) {
            $update_fields[] = "email = ?";
            $params[] = $input['email'];
            $types .= 's';
        }
        if (isset($input['role'])) {
            $update_fields[] = "role = ?";
            $params[] = $input['role'];
            $types .= 's';
        }
        if (isset($input['mobile'])) {
            $update_fields[] = "mobile = ?";
            $params[] = $input['mobile'];
            $types .= 's';
        }
        if (isset($input['employee_id'])) {
            $update_fields[] = "employee_id = ?";
            $params[] = $input['employee_id'];
            $types .= 's';
        }
        if (isset($input['status'])) {
            $update_fields[] = "status = ?";
            $params[] = $input['status'];
            $types .= 's';
        }
        if (isset($input['password']) && !empty($input['password'])) {
            $update_fields[] = "password = ?";
            $params[] = password_hash($input['password'], PASSWORD_DEFAULT);
            $types .= 's';
        }
        
        if (empty($update_fields)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No fields to update']);
            exit();
        }
        
        $params[] = $user_id;
        $types .= 'i';
        
        $sql = "UPDATE new_user SET " . implode(', ', $update_fields) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            $stmt->close();
            echo json_encode([
                'success' => true,
                'message' => 'User updated successfully'
            ]);
        } else {
            throw new Exception('Failed to update user: ' . $stmt->error);
        }
    }
    
    // DELETE: Delete user
    elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'User ID is required']);
            exit();
        }
        
        $user_id = (int)$input['id'];
        
        // Prevent deleting the current admin user
        if ($user_id === $_SESSION['user_id']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Cannot delete your own account']);
            exit();
        }
        
        $stmt = $conn->prepare("DELETE FROM new_user WHERE id = ?");
        $stmt->bind_param('i', $user_id);
        
        if ($stmt->execute()) {
            $stmt->close();
            echo json_encode([
                'success' => true,
                'message' => 'User deleted successfully'
            ]);
        } else {
            throw new Exception('Failed to delete user: ' . $stmt->error);
        }
    }
    
    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>


