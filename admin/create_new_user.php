<?php
// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || strtolower(trim($_SESSION['role'])) !== 'admin') {
  header("Location: ../login.html");
  exit();
}

// Database connection (XAMPP default: root with no password)
$conn = new mysqli("127.0.0.1", "root", "", "geobagg", 3307);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Function to generate password from employee ID
function generatePassword($employee_id) {
    if (empty($employee_id) || strlen($employee_id) < 2) {
        return '123456'; // Default password for users without employee ID
    }
    $last2digits = substr($employee_id, -2);
    return $employee_id . '@' . $last2digits;
}

// Handle form submission
$message = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
  // Input validation and sanitization
  $username     = trim(htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8'));
  $email        = trim(filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL));
  $first_name   = trim(htmlspecialchars($_POST['first_name'] ?? '', ENT_QUOTES, 'UTF-8'));
  $last_name    = trim(htmlspecialchars($_POST['last_name'] ?? '', ENT_QUOTES, 'UTF-8'));
  $role         = trim(htmlspecialchars($_POST['role'] ?? '', ENT_QUOTES, 'UTF-8'));
  $designation  = trim(htmlspecialchars($_POST['designation'] ?? '', ENT_QUOTES, 'UTF-8'));
  $employee_id  = trim(htmlspecialchars($_POST['employee_id'] ?? '', ENT_QUOTES, 'UTF-8'));
  $phone        = trim(htmlspecialchars($_POST['phone'] ?? '', ENT_QUOTES, 'UTF-8'));

  // Validate required fields
  if (empty($username) || empty($role)) {
    $message = "<p style='color: red;'>Username and role are required.</p>";
  }
  // Validate email format
  elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $message = "<p style='color: red;'>Invalid email format.</p>";
  }
  // Validate role
  elseif (!in_array($role, ['admin', 'roll production', 'production', 'qc', 'recycle','management'])) {
    $message = "<p style='color: red;'>Invalid role selected.</p>";
  }
  else {
    // Generate password using formula (store as plain text per request)
    $password = generatePassword($employee_id);
    $stored_password = $password;

    $stmt = $conn->prepare("INSERT INTO new_user (username, email, first_name, last_name, password, role, designation, employee_id, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssss", $username, $email, $first_name, $last_name, $stored_password, $role, $designation, $employee_id, $phone);

    if ($stmt->execute()) {
      // Mirror user into users table for legacy compatibility
      try {
        $u = $conn->prepare("INSERT INTO users (username, password, role, designation, employee_id, email, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
        if ($u) {
          $u->bind_param("ssssss", $username, $stored_password, $role, $designation, $employee_id, $email);
          $u->execute();
        }
      } catch (Throwable $e) {
        // ignore if users table has unique conflicts or other issues
      }
      $message = "<p style='color: green;'>User created successfully.</p>";
      $message .= "<p style='color: blue;'>Generated password: <strong>$password</strong></p>";
    } else {
      $message = "<p style='color: red;'>âŒ Error: " . $stmt->error . "</p>";
    }

    $stmt->close();
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Create New User - New User System</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:0; color:#2c3e50; }
    .container { max-width:100%; margin:0; background:#fff; border-radius:0; padding:25px 80px; box-shadow:none;}
    h2 {
      text-align: center;
      margin-bottom: 20px;
    }
    .info-box {
      background: #e3f2fd;
      border: 1px solid #2196f3;
      border-radius: 6px;
      padding: 15px;
      margin-bottom: 20px;
    }
    .info-box h4 {
      margin: 0 0 10px 0;
      color: #1976d2;
    }
    .info-box ul {
      margin: 0;
      padding-left: 20px;
    }
    label {
      display: block;
      margin-top: 15px;
      font-weight: 600;
    }
    input, select {
      width: 100%;
      padding: 10px;
      margin-top: 6px;
      border: 1px solid #ccc;
      border-radius: 6px;
      box-sizing: border-box;
    }
    button {
      margin-top: 25px;
      padding: 12px 20px;
      background: #3498db;
      color: white;
      border: none;
      border-radius: 6px;
      font-size: 15px;
      cursor: pointer;
      width: 100%;
    }
    button:hover {
      background: #2980b9;
    }
    .back {
      margin-top: 20px;
      display: block;
      text-align: center;
      color: #3498db;
      text-decoration: none;
      font-weight: bold;
    }
    .password-preview {
      background: #fff3cd;
      border: 1px solid #ffc107;
      border-radius: 4px;
      padding: 8px 12px;
      margin-top: 5px;
      font-size: 0.9em;
      color: #856404;
    }
  </style>
</head>
<body>
  <div class="container">
    <h2>Create New User - New User System</h2>

    <div class="info-box">
      <h4>Password Formula</h4>
      <ul>
        <li><strong>Formula:</strong> employee_id@last2digits</li>
        <li><strong>Example:</strong> Employee ID 14683 → Password: 14683@83</li>
        <li><strong>Exceptions:</strong> admin@cg-bd.com keeps password: 123456</li>
        <li><strong>Default:</strong> Users without employee ID get password: 123456</li>
      </ul>
    </div>

    <?= $message ?>

    <form method="POST">
      <label for="username">Username *</label>
      <input type="text" name="username" required>

      <label for="email">Email Address</label>
      <input type="email" name="email" placeholder="user@company.com">

      <label for="first_name">First Name</label>
      <input type="text" name="first_name">

      <label for="last_name">Last Name</label>
      <input type="text" name="last_name">

      <label for="role">Role *</label>
      <select name="role" required>
        <option value="">Select role</option>
        <option value="admin">Admin</option>
        <option value=" roll production"> RollProduction</option>
        <option value="production">Production</option>
        <option value="qc">QC</option>
        <option value="recycle">Recycle</option>
        <option value="management">Management</option>

      </select>

      <label for="designation">Designation</label>
      <input type="text" name="designation">

      <label for="employee_id">Employee ID</label>
      <input type="text" name="employee_id" id="employee_id" oninput="updatePasswordPreview()">
      <div id="password_preview" class="password-preview" style="display: none;">
        Generated password will be: <span id="preview_password"></span>
      </div>

      <label for="phone">Phone Number</label>
      <input type="tel" name="phone" placeholder="+880 1XXX XXX XXX">

      <button type="submit">Create User</button>
    </form>

    <a class="back" href="user_management_new_user.php">← Back to User Management</a>
  </div>

  <script>
  function updatePasswordPreview() {
    const employeeId = document.getElementById('employee_id').value;
    const previewDiv = document.getElementById('password_preview');
    const previewSpan = document.getElementById('preview_password');
    
    if (employeeId && employeeId.length >= 2) {
      const last2digits = employeeId.slice(-2);
      const password = employeeId + '@' + last2digits;
      previewSpan.textContent = password;
      previewDiv.style.display = 'block';
    } else if (employeeId) {
      previewSpan.textContent = employeeId + '@' + employeeId;
      previewDiv.style.display = 'block';
    } else {
      previewSpan.textContent = '123456';
      previewDiv.style.display = 'block';
    }
  }
  </script>
</body>
</html> 

