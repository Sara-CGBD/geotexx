<?php
// Security Audit Report Page
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();
date_default_timezone_set('Asia/Dhaka');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header("Location: ../login.html");
  exit();
}

require_once '../config/config.php';
$conn = new mysqli($host, $username, $password, $dbname, $port);
if ($conn->connect_error) {
  die("Connection failed: " . $conn->connect_error);
}

// Ensure required columns exist (idempotent)
$conn->query("ALTER TABLE new_user 
  ADD COLUMN IF NOT EXISTS last_activity TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS last_login TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS wrong_attempts INT DEFAULT 0,
  ADD COLUMN IF NOT EXISTS lock_until TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS is_active TINYINT(1) DEFAULT 1");

$now = date('Y-m-d H:i:s');

// Collect metrics
$metrics = [
  'total_users' => 0,
  'active_users' => 0,
  'disabled_users' => 0,
  'new_users_30d' => 0,
  'locked_accounts' => 0,
  'security_threats' => 0,
  'high_risk_users' => 0,
  'recent_logins_24h' => 0,
  'active_this_week' => 0,
  'inactive_users' => 0,
  'wrong_attempts' => 0,
];

// Total users
if ($res = $conn->query("SELECT COUNT(*) AS c FROM new_user")) { $metrics['total_users'] = (int)$res->fetch_assoc()['c']; }

// Active users (based on last_activity within 30 minutes)
if ($res = $conn->query("SELECT COUNT(*) AS c FROM new_user WHERE last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)")) { $metrics['active_users'] = (int)$res->fetch_assoc()['c']; }

// Disabled users
if ($res = $conn->query("SELECT COUNT(*) AS c FROM new_user WHERE is_active = 0")) { $metrics['disabled_users'] = (int)$res->fetch_assoc()['c']; }

// New users (last 30 days) — fallback to created_at if exists, else last_login
$hasCreatedAt = false;
if ($res = $conn->query("SHOW COLUMNS FROM new_user LIKE 'created_at'")) { $hasCreatedAt = $res->num_rows > 0; }
$newUsersQuery = $hasCreatedAt
  ? "SELECT COUNT(*) AS c FROM new_user WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)"
  : "SELECT COUNT(*) AS c FROM new_user WHERE last_login > DATE_SUB(NOW(), INTERVAL 30 DAY)";
if ($res = $conn->query($newUsersQuery)) { $metrics['new_users_30d'] = (int)$res->fetch_assoc()['c']; }

// Locked accounts
if ($res = $conn->query("SELECT COUNT(*) AS c FROM new_user WHERE lock_until IS NOT NULL AND lock_until > NOW()")) { $metrics['locked_accounts'] = (int)$res->fetch_assoc()['c']; }

// Security threats (wrong_attempts >= 3)
if ($res = $conn->query("SELECT COUNT(*) AS c FROM new_user WHERE wrong_attempts >= 3")) { $metrics['security_threats'] = (int)$res->fetch_assoc()['c']; }

// High risk users (wrong_attempts >= 5)
if ($res = $conn->query("SELECT COUNT(*) AS c FROM new_user WHERE wrong_attempts >= 5")) { $metrics['high_risk_users'] = (int)$res->fetch_assoc()['c']; }

// Recent logins (last 24h)
if ($res = $conn->query("SELECT COUNT(*) AS c FROM new_user WHERE last_login > DATE_SUB(NOW(), INTERVAL 24 HOUR) AND last_login IS NOT NULL")) { $metrics['recent_logins_24h'] = (int)$res->fetch_assoc()['c']; }

// Active this week (last_activity within 7 days)
if ($res = $conn->query("SELECT COUNT(*) AS c FROM new_user WHERE last_activity > DATE_SUB(NOW(), INTERVAL 7 DAY)")) { $metrics['active_this_week'] = (int)$res->fetch_assoc()['c']; }

// Inactive users = total - active_this_week
$metrics['inactive_users'] = max(0, $metrics['total_users'] - $metrics['active_this_week']);

// Total wrong attempts (sum)
if ($res = $conn->query("SELECT COALESCE(SUM(wrong_attempts),0) AS s FROM new_user")) { $metrics['wrong_attempts'] = (int)$res->fetch_assoc()['s']; }

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Security Audit Report</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    body { background: #f7f8fb; font-family: 'Inter', sans-serif; }
    .container { max-width: 1100px; margin: 24px auto; }
    .report-header { margin-bottom: 18px; }
    .report-header h1 { font-size: 1.6rem; color: #2c3e50; margin: 0; }
    .report-card {
      background: #fff; border-radius: 8px; padding: 12px 14px; margin-bottom: 12px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    }
    .table th, .table td { padding: 8px 10px; }
    .section-title { font-size: 1.1rem; color: #34495e; margin-bottom: 8px; }
  </style>
</head>
<body>
  <div class="container">
    <div class="d-flex justify-content-between align-items-center report-header">
      <h1><i class="fas fa-file-shield"></i> Security Audit Report</h1>
      <div>
        <a href="security_dashboard.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
        <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="fas fa-print"></i> Print</button>
      </div>
    </div>

    <!-- User Statistics -->
    <div class="report-card">
      <div class="section-title"><i class="fas fa-user-check"></i> User Statistics</div>
      <table class="table table-sm table-bordered mb-0">
        <tbody>
          <tr><th style="width:45%">Total Users:</th><td><?= $metrics['total_users'] ?></td></tr>
          <tr><th>Active Users:</th><td><?= $metrics['active_users'] ?></td></tr>
          <tr><th>Disabled Users:</th><td><?= $metrics['disabled_users'] ?></td></tr>
          <tr><th>New Users (30d):</th><td><?= $metrics['new_users_30d'] ?></td></tr>
        </tbody>
      </table>
    </div>

    <!-- Security Status -->
    <div class="report-card">
      <div class="section-title"><i class="fas fa-shield-alt"></i> Security Status</div>
      <table class="table table-sm table-bordered mb-0">
        <tbody>
          <tr><th style="width:45%">Locked Accounts:</th><td><?= $metrics['locked_accounts'] ?></td></tr>
          <tr><th>Security Threats:</th><td><?= $metrics['security_threats'] ?></td></tr>
          <tr><th>High Risk Users:</th><td><?= $metrics['high_risk_users'] ?></td></tr>
          <tr><th>Recent Logins (24h):</th><td><?= $metrics['recent_logins_24h'] ?></td></tr>
        </tbody>
      </table>
    </div>

    <!-- Activity Metrics -->
    <div class="report-card">
      <div class="section-title"><i class="fas fa-chart-line"></i> Activity Metrics</div>
      <table class="table table-sm table-bordered mb-0">
        <tbody>
          <tr><th style="width:45%">Active This Week:</th><td><?= $metrics['active_this_week'] ?></td></tr>
          <tr><th>Inactive Users:</th><td><?= $metrics['inactive_users'] ?></td></tr>
          <tr><th>Wrong Attempts:</th><td><?= $metrics['wrong_attempts'] ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>



