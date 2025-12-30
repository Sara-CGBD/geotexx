<?php
// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();

// Simple session check (like other forms)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
  header("Location: login.html");
  exit();
}

// Session security checks (only if logged in)
$session_timeout = 3600; // 1 hour
$regenerate_time = 300;  // 5 minutes

// Check session timeout (only if logged in)
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $session_timeout) {
    session_unset();
    session_destroy();
    header("Location: login.html?msg=timeout");
    exit();
}

// Regenerate session ID periodically to prevent session fixation (only if logged in)
if (isset($_SESSION['last_regeneration']) && (time() - $_SESSION['last_regeneration']) > $regenerate_time) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}
require_once __DIR__ . '/../config.php';
require_once 'audit_log.php';

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $stmt = $conn->prepare("DELETE FROM user_data WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['user_msg'] = '<div class="alert alert-success">User deleted successfully.</div>';
        // Log admin action
        log_admin_action($_SESSION['user_id'], 'DELETE_USER', "User ID: $id");
    } else {
        $_SESSION['user_msg'] = '<div class="alert alert-danger">Delete failed: ' . htmlspecialchars($conn->error) . '</div>';
    }
    header('Location: user_management.php');
    exit();
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_id'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_POST['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['user_msg'] = '<div class="alert alert-danger">Invalid request.</div>';
        header('Location: user_management.php');
        exit();
    }
    
    $id = intval($_POST['update_id']);
    $fields = [
        'username', 'first_name', 'last_name', 'role', 'designation', 'mobile', 'employee_id'
    ];
    
    // Use prepared statement for secure update
    $sql = "UPDATE user_data SET username = ?, first_name = ?, last_name = ?, role = ?, designation = ?, mobile = ?, employee_id = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssssi", 
        $_POST['username'], 
        $_POST['first_name'], 
        $_POST['last_name'], 
        $_POST['role'], 
        $_POST['designation'], 
        $_POST['mobile'], 
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
    header('Location: user_management.php');
    exit();
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password_id'])) {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_POST['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['user_msg'] = '<div class="alert alert-danger">Invalid request.</div>';
        header('Location: user_management.php');
        exit();
    }
    
    $id = intval($_POST['password_id']);
    $password = $_POST['new_password'];
    $hashed = password_hash($password, PASSWORD_DEFAULT); // Use secure hashing
    $stmt = $conn->prepare("UPDATE user_data SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $hashed, $id);
    if ($stmt->execute()) {
        $_SESSION['user_msg'] = '<div class="alert alert-success">Password changed successfully.</div>';
        // Log admin action
        log_admin_action($_SESSION['user_id'], 'CHANGE_PASSWORD', "User ID: $id");
    } else {
        $_SESSION['user_msg'] = '<div class="alert alert-danger">Password change failed: ' . htmlspecialchars($conn->error) . '</div>';
    }
    header('Location: user_management.php');
    exit();
}

// Fetch all users
$result = $conn->query("SELECT * FROM user_data ORDER BY id DESC");
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
  <title>User Management</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    body { background: #f4f6f9; font-family: 'Inter', sans-serif; }
    .container { max-width: 1100px; margin: 40px auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 16px #0001; padding: 32px; }
    h2 { text-align: center; margin-bottom: 28px; }
    table { background: #fff; }
    th, td { vertical-align: middle !important; }
    .btn-sm { font-size: 0.95em; }
    .modal .form-label { font-weight: 600; }
  </style>
</head>
<body>
<div class="container">
  <h2>👥 User Management</h2>
  <?= $user_msg ?>
  
  <!-- Live Search Section -->
  <div class="row mb-3">
    <div class="col-md-6">
      <div class="input-group">
        <span class="input-group-text">🔍</span>
        <input type="text" id="searchInput" class="form-control" placeholder="Search..." autocomplete="off">
        <button class="btn btn-outline-secondary" type="button" onclick="clearSearch()">Clear</button>
      </div>
    </div>
    <div class="col-md-6 text-end">
      <a href="user_create.php" class="btn btn-primary">➕ Create New User</a>
    </div>
  </div>
  
  <!-- Search Results Info -->
  <div id="searchInfo" class="alert alert-info" style="display: none;">
    <span id="searchResultsCount"></span> users found
  </div>
  <table class="table table-bordered table-hover align-middle">
    <thead class="table-light">
      <tr>
        <th>ID</th>
        <th>Username</th>
        <th>First Name</th>
        <th>Last Name</th>
        <th>Role</th>
        <th>Designation</th>
        <th>Mobile</th>
        <th>Employee ID</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><?= $u['id'] ?></td>
        <td><?= htmlspecialchars($u['username']) ?></td>
        <td><?= htmlspecialchars($u['first_name']) ?></td>
        <td><?= htmlspecialchars($u['last_name']) ?></td>
        <td><?= htmlspecialchars($u['role']) ?></td>
        <td><?= htmlspecialchars($u['designation']) ?></td>
        <td><?= htmlspecialchars($u['mobile']) ?></td>
        <td><?= htmlspecialchars($u['employee_id']) ?></td>
        <td>
          <button class="btn btn-warning btn-sm" onclick="showEditModal(<?= $u['id'] ?>)">Edit</button>
          <button class="btn btn-secondary btn-sm" onclick="showPasswordModal(<?= $u['id'] ?>)">Change Password</button>
          <a href="?delete=<?= $u['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Delete this user?')">Delete</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="POST">
      <div class="modal-header"><h5 class="modal-title">Edit User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="update_id" id="edit_id">
        <input type="hidden" name="csrf_token" value="<?= bin2hex(random_bytes(32)) ?>">
        <div class="mb-2"><label class="form-label">Username</label><input type="text" class="form-control" name="username" id="edit_username" required></div>
        <div class="mb-2"><label class="form-label">First Name</label><input type="text" class="form-control" name="first_name" id="edit_first_name"></div>
        <div class="mb-2"><label class="form-label">Last Name</label><input type="text" class="form-control" name="last_name" id="edit_last_name"></div>
        <div class="mb-2"><label class="form-label">Role</label>
          <select class="form-select" name="role" id="edit_role" required>
            <option value="admin">Admin</option>
            <option value="roll production">Roll Production</option>
            <option value="production">Production</option>
            <option value="qc">QC</option>
            <option value="recycle">Recycle</option>
            <option value="management">Management</option>
          </select>
        </div>
        <div class="mb-2"><label class="form-label">Designation</label><input type="text" class="form-control" name="designation" id="edit_designation"></div>
        <div class="mb-2"><label class="form-label">Mobile</label><input type="text" class="form-control" name="mobile" id="edit_mobile"></div>
        <div class="mb-2"><label class="form-label">Employee ID</label><input type="text" class="form-control" name="employee_id" id="edit_employee_id"></div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-primary">Update</button></div>
    </form>
  </div>
</div>

<!-- Password Modal -->
<div class="modal fade" id="passwordModal" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" method="POST">
      <div class="modal-header"><h5 class="modal-title">Change Password</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="password_id" id="password_id">
        <input type="hidden" name="csrf_token" value="<?= bin2hex(random_bytes(32)) ?>">
        <div class="mb-2"><label class="form-label">New Password</label><input type="password" class="form-control" name="new_password" required></div>
      </div>
      <div class="modal-footer"><button type="submit" class="btn btn-success">Change Password</button></div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const users = <?= json_encode($users) ?>;
let editModal = new bootstrap.Modal(document.getElementById('editModal'));
let passwordModal = new bootstrap.Modal(document.getElementById('passwordModal'));

// Live Search Functionality
document.addEventListener('DOMContentLoaded', function() {
  const searchInput = document.getElementById('searchInput');
  const tableBody = document.querySelector('tbody');
  const searchInfo = document.getElementById('searchInfo');
  const searchResultsCount = document.getElementById('searchResultsCount');
  
  // Add event listener for live search
  searchInput.addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase().trim();
    const rows = tableBody.querySelectorAll('tr');
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
    
    // Update search info
    if (searchTerm.length > 0) {
      searchInfo.style.display = 'block';
      searchResultsCount.textContent = visibleCount;
    } else {
      searchInfo.style.display = 'none';
    }
  });
  
  // Add keyboard shortcuts
  searchInput.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      clearSearch();
    }
  });
});

// Clear search function
function clearSearch() {
  const searchInput = document.getElementById('searchInput');
  const tableBody = document.querySelector('tbody');
  const searchInfo = document.getElementById('searchInfo');
  
  searchInput.value = '';
  const rows = tableBody.querySelectorAll('tr');
  rows.forEach(row => {
    row.style.display = '';
  });
  searchInfo.style.display = 'none';
  searchInput.focus();
}

function showEditModal(id) {
  const u = users.find(x => x.id == id);
  document.getElementById('edit_id').value = u.id;
  document.getElementById('edit_username').value = u.username;
  document.getElementById('edit_first_name').value = u.first_name;
  document.getElementById('edit_last_name').value = u.last_name;
  document.getElementById('edit_role').value = u.role;
  document.getElementById('edit_designation').value = u.designation;
  document.getElementById('edit_mobile').value = u.mobile;
  document.getElementById('edit_employee_id').value = u.employee_id;
  editModal.show();
}

function showPasswordModal(id) {
  document.getElementById('password_id').value = id;
  passwordModal.show();
}
</script>
</body>
</html> 
