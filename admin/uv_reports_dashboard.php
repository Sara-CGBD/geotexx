<?php
session_start();
require_once '../forms/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit();
}

$role = strtolower(trim($_SESSION['role'] ?? 'user'));
if (!in_array($role, ['admin', 'agm ops', 'agm operations', 'management'])) {
    die('Access denied. Only admins, AGM Ops, and management can access this page.');
}

$conn = SecurityConfig::getConnection();
$message = '';
$error = '';

// Approve/Reject POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    $id = (int)$_POST['id'];
    $action = $_POST['action'] === 'approve' ? 'Approved' : 'Rejected';
    $remarks = trim($_POST['remarks'] ?? '');
    $approved_by = $_SESSION['username'];
    $approved_at = date('Y-m-d H:i:s');

    // Ensure table exists
    $conn->query("CREATE TABLE IF NOT EXISTS weathering_exposure_test (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_no VARCHAR(100) UNIQUE,
        sample_ref VARCHAR(255),
        client VARCHAR(255),
        material VARCHAR(255),
        batch_no VARCHAR(255),
        exposure_type VARCHAR(100),
        exposure_date DATETIME NULL,
        test_start_date DATE NULL,
        test_end_date DATE NULL,
        test_name VARCHAR(255),
        test_parameter VARCHAR(255),
        before_exposure DECIMAL(12,4) NULL,
        after_exposure DECIMAL(12,4) NULL,
        exposure_hours DECIMAL(12,4) NULL,
        retain_percentage DECIMAL(12,4) NULL,
        tested_by VARCHAR(100) NULL,
        approved_by VARCHAR(100) NULL,
        result_json JSON NULL,
        status VARCHAR(20) DEFAULT 'Pending',
        created_by VARCHAR(255) NULL,
        remarks TEXT NULL,
        approved_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    $stmt = $conn->prepare("UPDATE weathering_exposure_test SET status = ?, approved_by = ?, approved_at = ?, remarks = ? WHERE id = ?");
    $stmt->bind_param('ssssi', $action, $approved_by, $approved_at, $remarks, $id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        $message = "Report {$action}.";
    } else {
        $error = 'No changes made or error occurred.';
    }
    $stmt->close();
}

$res = $conn->query("SELECT id, report_no, client, material, tested_by, status, created_at FROM weathering_exposure_test ORDER BY created_at DESC");
$rows = [];
if ($res) {
    while ($r = $res->fetch_assoc()) $rows[] = $r;
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>UV Reports - Admin Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
    .container { max-width:1200px; margin:auto; background:#fff; border-radius:12px; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.08);} 
    h1 { margin:0 0 16px 0; }
    table { width:100%; border-collapse:collapse; }
    th, td { border:1px solid #ddd; padding:8px; text-align:left; }
    th { background:#3498db; color:#fff; }
    .msg { margin-bottom:12px; padding:10px; border-radius:6px; }
    .ok { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .err { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
    .actions { display:flex; gap:8px; }
    .btn { padding:6px 10px; border:none; border-radius:6px; cursor:pointer; }
    .approve { background:#2ecc71; color:#fff; }
    .reject { background:#e74c3c; color:#fff; }
    textarea { width:100%; min-height:40px; }
  </style>
</head>
<body>
<div class="container">
  <h1>UV Reports - Admin Dashboard</h1>
  <?php if ($message): ?><div class="msg ok"><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <table>
    <thead>
      <tr>
        <th>Report No</th>
        <th>Client</th>
        <th>Material</th>
        <th>Tested By</th>
        <th>Status</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="6" style="text-align:center;">No reports found.</td></tr>
      <?php else: foreach ($rows as $row): ?>
        <tr>
          <td><?= htmlspecialchars($row['report_no']) ?></td>
          <td><?= htmlspecialchars($row['client']) ?></td>
          <td><?= htmlspecialchars($row['material']) ?></td>
          <td><?= htmlspecialchars($row['tested_by']) ?></td>
          <td><?= htmlspecialchars($row['status']) ?></td>
          <td>
            <form method="POST" action="" style="display:flex; gap:8px; align-items:center;">
              <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
              <textarea name="remarks" placeholder="Remarks (optional)"></textarea>
              <button class="btn approve" name="action" value="approve" type="submit">Approve</button>
              <button class="btn reject" name="action" value="reject" type="submit">Reject</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
// Refresh only when approve/reject actions happen
(function() {
    let isRefreshing = false;
    
    // Function to refresh the dashboard
    function refreshDashboard() {
        if (isRefreshing) return;
        isRefreshing = true;
        
        // Reload the page with cache busting
        window.location.href = window.location.href.split('?')[0] + '?t=' + new Date().getTime();
    }
    
    // Listen for messages from child windows (approval/rejection pages)
    window.addEventListener('message', function(event) {
        // Verify origin for security
        if (event.origin !== window.location.origin) {
            return;
        }
        
        // If message indicates a report was processed (approved/rejected), refresh
        if (event.data && (event.data.type === 'report_processed' || event.data.type === 'report_approved' || event.data.type === 'report_rejected')) {
            // Small delay to ensure database is updated
            setTimeout(function() {
                refreshDashboard();
            }, 300);
        }
    });
})();
</script>
</body>
</html>

