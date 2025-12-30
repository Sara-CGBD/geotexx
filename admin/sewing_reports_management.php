<?php
session_start();
require_once '../forms/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit();
}

$role = strtolower($_SESSION['role'] ?? '');
if ($role !== 'management') {
    die('Access denied. Management only.');
}

$conn = SecurityConfig::getConnection();

$res = $conn->query("SELECT id, report_number, sample_received_from AS client, sample_description AS material, test_performed_by AS tested_by, status, created_at FROM sewing_thread_reports ORDER BY created_at DESC");
$rows = [];
if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; }
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Sewing Thread Reports - Management View</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
    .container { max-width:1200px; margin:auto; background:#fff; border-radius:12px; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.08);} 
    h1 { margin:0 0 16px 0; }
    table { width:100%; border-collapse:collapse; }
    th, td { border:1px solid #ddd; padding:8px; text-align:left; }
    th { background:#3498db; color:#fff; }
  </style>
</head>
<body>
<div class="container">
  <h1>Sewing Thread Reports - Management</h1>
  <table>
    <thead>
      <tr>
        <th>Report No</th>
        <th>Client</th>
        <th>Material</th>
        <th>Tested By</th>
        <th>Status</th>
        <th>Submitted At</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="6" style="text-align:center;">No reports found.</td></tr>
      <?php else: foreach ($rows as $row): ?>
        <tr>
          <td><?= htmlspecialchars($row['report_number']) ?></td>
          <td><?= htmlspecialchars($row['client']) ?></td>
          <td><?= htmlspecialchars($row['material']) ?></td>
          <td><?= htmlspecialchars($row['tested_by']) ?></td>
          <td><?= htmlspecialchars($row['status']) ?></td>
          <td><?= htmlspecialchars($row['created_at']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
</body>
</html>

