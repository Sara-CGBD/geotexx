<?php
// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();

// âœ… Set timezone to Asia/Dhaka (GMT+6) to ensure correct time display
date_default_timezone_set('Asia/Dhaka');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || strtolower(trim($_SESSION['role'])) !== 'admin') {
  header("Location: ../login.html");
  exit();
}

// Include security configuration
require_once '../config/security_config.php';

// Database connection (XAMPP default: root with no password)
$conn = new mysqli("127.0.0.1", "root", "", "geobagg", 3307);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Simple logging function
function log_admin_action($admin_id, $action, $details) {
    // For now, just return true to avoid errors
    // You can implement proper logging later if needed
    return true;
}

// Function to generate password from employee ID
function generatePassword($employee_id) {
    if (empty($employee_id) || strlen($employee_id) < 2) {
        return '123456'; // Default password for users without employee ID
    }
    $last2digits = substr($employee_id, -2);
    return $employee_id . '@' . $last2digits;
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_id'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_POST['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['user_msg'] = '<div class="alert alert-danger">Invalid request.</div>';
        header('Location: user_management_new_user.php');
        exit();
    }
    
    $id = intval($_POST['update_id']);
    $fields = [
        'username', 'first_name', 'last_name', 'role', 'designation', 'employee_id'
    ];
    
    // Use prepared statement for secure update
    $sql = "UPDATE new_user SET username = ?, first_name = ?, last_name = ?, role = ?, designation = ?, employee_id = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssi", 
        $_POST['username'], 
        $_POST['first_name'], 
        $_POST['last_name'], 
        $_POST['role'], 
        $_POST['designation'], 
        $_POST['employee_id'], 
        $id
    );
    
    if ($stmt->execute()) {
        $_SESSION['user_msg'] = '<div class="alert alert-success">User updated successfully.</div>';
        // Log admin action
        log_admin_action($_SESSION['user_id'], 'UPDATE_USER', "User ID: $id");
    } else {
        $_SESSION['user_msg'] = '<div class="alert alert-danger">Update failed: ' . htmlspecialchars($conn->error) . '</div>';
    }
    header('Location: user_management_new_user.php');
    exit();
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password_id'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_POST['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['user_msg'] = '<div class="alert alert-danger">Invalid request.</div>';
        header('Location: user_management_new_user.php');
        exit();
    }
    
    $id = intval($_POST['password_id']);
    $password = $_POST['new_password'];
    $hashed_password = password_hash($password, PASSWORD_DEFAULT); // Hash the password securely!
    $stmt = $conn->prepare("UPDATE new_user SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $hashed_password, $id);
    if ($stmt->execute()) {
        $_SESSION['user_msg'] = '<div class="alert alert-success">Password changed successfully.</div>';
        // Log admin action
        log_admin_action($_SESSION['user_id'], 'CHANGE_PASSWORD', "User ID: $id");
    } else {
        $_SESSION['user_msg'] = '<div class="alert alert-danger">Password change failed: ' . htmlspecialchars($conn->error) . '</div>';
    }
    header('Location: user_management_new_user.php');
    exit();
}

// Handle password reset with formula
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password_id'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_POST['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['user_msg'] = '<div class="alert alert-danger">Invalid request.</div>';
        header('Location: user_management_new_user.php');
        exit();
    }
    
    $id = intval($_POST['reset_password_id']);
    
    // Get user's employee_id
    $stmt = $conn->prepare("SELECT employee_id, username FROM new_user WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if ($user) {
        $username = $user['username'];
        $employee_id = $user['employee_id'];
        
        // Skip admin@cg-bd.com (keep 123456)
        if ($username === 'admin@cg-bd.com') {
            $new_password = '123456';
        } else {
            // Generate password using formula: employee_id@last2digits
            $new_password = generatePassword($employee_id);
        }
        
        $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT); // Hash the password securely!
        $stmt = $conn->prepare("UPDATE new_user SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $hashed_new_password, $id);
        
        if ($stmt->execute()) {
            $_SESSION['user_msg'] = '<div class="alert alert-success">Password reset successfully to: <strong>' . $new_password . '</strong></div>';
            // Log admin action
            log_admin_action($_SESSION['user_id'], 'RESET_PASSWORD', "User ID: $id, New Password: $new_password");
        } else {
            $_SESSION['user_msg'] = '<div class="alert alert-danger">Password reset failed: ' . htmlspecialchars($conn->error) . '</div>';
        }
    } else {
        $_SESSION['user_msg'] = '<div class="alert alert-danger">User not found.</div>';
    }
    header('Location: user_management_new_user.php');
    exit();
}

// Handle account status change (enable/disable)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status_id'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_POST['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['user_msg'] = '<div class="alert alert-danger">Invalid request.</div>';
        header('Location: user_management_new_user.php');
        exit();
    }
    
    $id = intval($_POST['toggle_status_id']);
    $new_status = intval($_POST['new_status']);
    
    $stmt = $conn->prepare("UPDATE new_user SET is_active = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_status, $id);
    
    if ($stmt->execute()) {
        $status_text = $new_status ? 'enabled' : 'disabled';
        $_SESSION['user_msg'] = '<div class="alert alert-success">Account ' . $status_text . ' successfully.</div>';
        // Log admin action
        log_admin_action($_SESSION['user_id'], 'TOGGLE_STATUS', "User ID: $id, Status: $status_text");
    } else {
        $_SESSION['user_msg'] = '<div class="alert alert-danger">Status change failed: ' . htmlspecialchars($conn->error) . '</div>';
    }
    header('Location: user_management_new_user.php');
    exit();
}

// Handle account unlock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unlock_id'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_POST['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['user_msg'] = '<div class="alert alert-danger">Invalid request.</div>';
        header('Location: user_management_new_user.php');
        exit();
    }
    
    $id = intval($_POST['unlock_id']);
    
    // Get username for unlock
    $stmt = $conn->prepare("SELECT username FROM new_user WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if ($user) {
        if (SecurityConfig::unlockAccount($user['username'])) {
            $_SESSION['user_msg'] = '<div class="alert alert-success">Account unlocked successfully.</div>';
            // Log admin action
            log_admin_action($_SESSION['user_id'], 'UNLOCK_ACCOUNT', "User ID: $id");
        } else {
            $_SESSION['user_msg'] = '<div class="alert alert-danger">Account unlock failed.</div>';
        }
    } else {
        $_SESSION['user_msg'] = '<div class="alert alert-danger">User not found.</div>';
    }
    header('Location: user_management_new_user.php');
    exit();
}

// Check for filter parameter from security dashboard
$filter_user = $_GET['filter_user'] ?? '';

// Fetch all users from new_user table with security info
$sql = "SELECT *, 
    CASE 
        WHEN is_active = 0 THEN 'Disabled'
        WHEN lock_until IS NOT NULL AND lock_until > NOW() THEN 'Locked'
        ELSE 'Active'
    END as account_status,
    COALESCE(wrong_attempts, 0) as wrong_attempts,
    CASE 
        WHEN lock_until IS NOT NULL AND lock_until > NOW() 
        THEN TIMESTAMPDIFF(SECOND, NOW(), lock_until)
        ELSE 0
    END as remaining_lock_time
FROM new_user";

// Add filter if specified
if (!empty($filter_user)) {
    $sql .= " WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $filter_user);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $sql .= " ORDER BY id DESC";
    $result = $conn->query($sql);
}

$users = [];
while ($row = $result->fetch_assoc()) $users[] = $row;

// Get and clear flash message
$user_msg = isset($_SESSION['user_msg']) ? $_SESSION['user_msg'] : '';
unset($_SESSION['user_msg']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>User Management - New User System</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    body { background: #f4f6f9; font-family: 'Inter', sans-serif; }
    .container { max-width: 1400px; margin: 40px auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 16px #0001; padding: 32px; }
    h2 { text-align: center; margin-bottom: 28px; }
    table { background: #fff; }
    th, td { vertical-align: middle !important; }
    .btn-sm { font-size: 0.95em; }
    .modal .form-label { font-weight: 600; }
    .password-info { font-size: 0.9em; color: #666; }
    .status-badge {
        padding: 4px 8px;
        border-radius: 12px;
        font-size: 0.8em;
        font-weight: 600;
    }
    .status-active { background: #d4edda; color: #155724; }
    .status-locked { background: #fff3cd; color: #856404; }
    .status-disabled { background: #f8d7da; color: #721c24; }
    .security-info {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 8px 12px;
        font-size: 0.85em;
        color: #6c757d;
    }
  </style>
</head>
<body>
<div class="container">
  <h2>User Management - New User System</h2>
  <div class="alert alert-info">
    <strong>Note:</strong> This page manages users in the <strong>new_user</strong> table.
    Password formula: <strong>employee_id@last2digits</strong> (except admin@cg-bd.com)
  </div>
  <?php if (!empty($filter_user)): ?>
  <div class="alert alert-success">
    <strong>Filtered View:</strong> Showing results for user: <strong><?= htmlspecialchars($filter_user) ?></strong>
    <a href="user_management_new_user.php" class="btn btn-sm btn-outline-secondary float-end">Show All Users</a>
  </div>
  <?php endif; ?>
  <?= $user_msg ?>
  
  <!-- Live Search Section -->
  <div class="row mb-3">
    <div class="col-md-6">
      <div class="input-group">
        <span class="input-group-text">🔍</span>
        <input type="text" id="searchInput" class="form-control" placeholder="Search..." autocomplete="off" value="<?= htmlspecialchars($filter_user) ?>">
        <button class="btn btn-outline-secondary" type="button" onclick="clearSearch()">Clear</button>
      </div>
    </div>
    <div class="col-md-6 text-end">
      <a href="user_create_new_user.php" class="btn btn-primary">âž• Create New User</a>
    </div>
  </div>
  
  <!-- Search Results Info -->
  <div id="searchInfo" class="alert alert-info" style="display: none;">
    <span id="searchResultsCount"></span> users found
  </div>
  
  <div class="table-responsive">
    <table class="table table-bordered table-hover align-middle">
      <thead class="table-light">
        <tr>
          <th>ID</th>
          <th>Username</th>
          <th>Name</th>
          <th>Role</th>
          <th>Designation</th>
          <th>Employee ID</th>
          <th>Email</th>
          <th>Status</th>
          <th>Security Info</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td><?= $u['id'] ?></td>
          <td><?= htmlspecialchars($u['username']) ?></td>
          <td><?= htmlspecialchars($u['first_name']) ?> <?= htmlspecialchars($u['last_name']) ?></td>
          <td><?= htmlspecialchars($u['role']) ?></td>
          <td><?= htmlspecialchars($u['designation']) ?></td>
          <td><?= htmlspecialchars($u['employee_id']) ?></td>
          <td><?= htmlspecialchars($u['email']) ?></td>
          <td>
            <span class="status-badge status-<?= strtolower($u['account_status']) ?>">
              <?= $u['account_status'] ?>
            </span>
          </td>
          <td>
            <div class="security-info">
              <div><strong>Wrong Attempts:</strong> <?= $u['wrong_attempts'] ?></div>
              <?php if ($u['remaining_lock_time'] > 0): ?>
                <div><strong>Lock Remaining:</strong> <?= SecurityConfig::formatTimeRemaining($u['remaining_lock_time']) ?></div>
              <?php endif; ?>
              <?php if ($u['last_login']): ?>
                <div><strong>Last Login:</strong> <?= date('M j, Y g:i A', strtotime($u['last_login'])) ?></div>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <div class="btn-group-vertical" role="group">
              <button class="btn btn-warning btn-sm mb-1" onclick="showEditModal(<?= $u['id'] ?>)">Edit</button>
              <button class="btn btn-info btn-sm mb-1" onclick="showPasswordModal(<?= $u['id'] ?>)">Change Password</button>
              <button class="btn btn-success btn-sm mb-1" onclick="showResetPasswordModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>', '<?= htmlspecialchars($u['employee_id']) ?>')">Reset Password</button>
              
              <?php if ($u['account_status'] === 'Locked'): ?>
                <button class="btn btn-primary btn-sm mb-1" onclick="unlockAccount(<?= $u['id'] ?>)">Unlock</button>
              <?php endif; ?>
              
              <?php if ($u['account_status'] === 'Active'): ?>
                <button class="btn btn-danger btn-sm mb-1" onclick="toggleStatus(<?= $u['id'] ?>, 0)">Disable</button>
              <?php else: ?>
                <button class="btn btn-success btn-sm mb-1" onclick="toggleStatus(<?= $u['id'] ?>, 1)">Enable</button>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="update_id" id="edit_id">
          <input type="hidden" name="csrf_token" value="<?= hash('sha256', session_id()) ?>">
          
          <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" name="username" id="edit_username" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label">First Name</label>
            <input type="text" name="first_name" id="edit_first_name" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Last Name</label>
            <input type="text" name="last_name" id="edit_last_name" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Role</label>
            <select name="role" id="edit_role" class="form-control" required>
              <option value="admin">Admin</option>
              <option value="management">Roll Production</option>
              <option value="production">Production</option>
              <option value="qc">QC</option>
              <option value="recycle">Recycle</option>
              <option value="management">Management</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Designation</label>
            <input type="text" name="designation" id="edit_designation" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Employee ID</label>
            <input type="text" name="employee_id" id="edit_employee_id" class="form-control">
            <div class="password-info">Password will be: employee_id@last2digits</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Update User</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Change Password Modal -->
<div class="modal fade" id="passwordModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Change Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="password_id" id="password_id">
          <input type="hidden" name="csrf_token" value="<?= hash('sha256', session_id()) ?>">
          
          <div class="mb-3">
            <label class="form-label">New Password</label>
            <input type="password" name="new_password" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Change Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Reset Password with Formula</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" name="reset_password_id" id="reset_password_id">
          <input type="hidden" name="csrf_token" value="<?= hash('sha256', session_id()) ?>">
          
          <div class="alert alert-info">
            <strong>User:</strong> <span id="reset_username"></span><br>
            <strong>Employee ID:</strong> <span id="reset_employee_id"></span><br>
            <strong>New Password:</strong> <span id="reset_new_password"></span>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Confirm Reset</label>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="confirmReset" required>
              <label class="form-check-label" for="confirmReset">
                I confirm that I want to reset the password using the formula
              </label>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success" id="resetPasswordBtn" disabled>Reset Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Live search functionality
document.getElementById('searchInput').addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    const rows = document.querySelectorAll('tbody tr');
    let visibleCount = 0;
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(searchTerm)) {
            row.style.display = '';
            visibleCount++;
        } else {
            row.style.display = 'none';
        }
    });
    
    const searchInfo = document.getElementById('searchInfo');
    const searchResultsCount = document.getElementById('searchResultsCount');
    
    if (searchTerm) {
        searchResultsCount.textContent = visibleCount;
        searchInfo.style.display = 'block';
    } else {
        searchInfo.style.display = 'none';
    }
});

function clearSearch() {
    document.getElementById('searchInput').value = '';
    document.querySelectorAll('tbody tr').forEach(row => row.style.display = '');
    document.getElementById('searchInfo').style.display = 'none';
    // When clear is clicked, ensure the URL parameter is removed
    if (window.location.search.includes('filter_user=')) {
        window.location.href = window.location.pathname; // Reloads page without query string
    }
}

// Auto-search when page loads with filter parameter
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const filterValue = '<?= htmlspecialchars($filter_user) ?>';
    
    if (filterValue) {
        // Trigger search automatically
        const event = new Event('input');
        searchInput.dispatchEvent(event);
        
        // Show info message
        const searchInfo = document.getElementById('searchInfo');
        const searchResultsCount = document.getElementById('searchResultsCount');
        searchResultsCount.textContent = '1';
        searchInfo.style.display = 'block';
        searchInfo.className = 'alert alert-success';
        searchInfo.innerHTML = '<span id="searchResultsCount">1</span> user found (filtered from Security Dashboard)';
    }
});

function showEditModal(id) {
    // Fetch user data and populate modal
    const row = event.target.closest('tr');
    const cells = row.cells;
    
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_username').value = cells[1].textContent.trim();
    document.getElementById('edit_first_name').value = cells[2].textContent.trim().split(' ')[0];
    document.getElementById('edit_last_name').value = cells[2].textContent.trim().split(' ').slice(1).join(' ');
    document.getElementById('edit_role').value = cells[3].textContent.trim();
    document.getElementById('edit_designation').value = cells[4].textContent.trim();
    document.getElementById('edit_employee_id').value = cells[5].textContent.trim();
    
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function showPasswordModal(id) {
    document.getElementById('password_id').value = id;
    new bootstrap.Modal(document.getElementById('passwordModal')).show();
}

function showResetPasswordModal(id, username, employee_id) {
    document.getElementById('reset_password_id').value = id;
    document.getElementById('reset_username').textContent = username;
    document.getElementById('reset_employee_id').textContent = employee_id || 'NULL';
    
    // Generate new password using formula
    let newPassword;
    if (username === 'admin@cg-bd.com') {
        newPassword = '123456';
    } else if (employee_id && employee_id.length >= 2) {
        const last2digits = employee_id.slice(-2);
        newPassword = employee_id + '@' + last2digits;
    } else {
        newPassword = '123456';
    }
    
    document.getElementById('reset_new_password').textContent = newPassword;
    
    new bootstrap.Modal(document.getElementById('resetPasswordModal')).show();
}

function toggleStatus(id, newStatus) {
    if (confirm('Are you sure you want to ' + (newStatus ? 'enable' : 'disable') + ' this account?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="toggle_status_id" value="${id}">
            <input type="hidden" name="new_status" value="${newStatus}">
            <input type="hidden" name="csrf_token" value="<?= hash('sha256', session_id()) ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function unlockAccount(id) {
    if (confirm('Are you sure you want to unlock this account?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="unlock_id" value="${id}">
            <input type="hidden" name="csrf_token" value="<?= hash('sha256', session_id()) ?>">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Enable reset password button when checkbox is checked
document.getElementById('confirmReset').addEventListener('change', function() {
    document.getElementById('resetPasswordBtn').disabled = !this.checked;
});
</script>
</body>
</html> 

