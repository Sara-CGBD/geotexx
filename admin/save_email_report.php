<?php
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
  header("Location: login.html");
  exit();
}
require_once __DIR__ . '/../config.php';

// Handle email report saving
if (isset($_POST['save_report'])) {
  $start_date = date('Y-m-d', strtotime('-7 days'));
  $end_date = date('Y-m-d');
  
  // Load email config for content generation
  $emailConfig = require __DIR__ . '/email_config.php';
  
  // Generate email content
  $emailContent = generateEmailContent($conn, $start_date, $end_date, $emailConfig);
  
  // Create reports directory if it doesn't exist
  $reportsDir = __DIR__ . '/../storage/reports';
  if (!is_dir($reportsDir)) {
    mkdir($reportsDir, 0755, true);
  }
  
  // Save report to file
  $filename = 'management_report_' . date('Y-m-d_H-i-s') . '.html';
  $filepath = $reportsDir . '/' . $filename;
  
  if (file_put_contents($filepath, $emailContent)) {
    $_SESSION['email_msg'] = '<div class="alert alert-success">✅ Report saved successfully as: ' . $filename . '</div>';
  } else {
    $_SESSION['email_msg'] = '<div class="alert alert-danger">❌ Failed to save report file.</div>';
  }
  
  header('Location: management_dashboard.php');
  exit();
}

// Email functions (copy from management_dashboard.php)
function generateEmailContent($conn, $start_date, $end_date, $emailConfig) {
  // Get all projects
  $projects = [];
  $resProj = $conn->query("SELECT DISTINCT project FROM contracts ORDER BY project");
  while ($row = $resProj->fetch_assoc()) $projects[] = $row['project'];

  // Prepare data per project
  $data = [];
  foreach ($projects as $project) {
    // Total Production
    $sqlProd = "SELECT COUNT(*) as total FROM spc_reports WHERE project='$project' AND DATE(date_time) BETWEEN '$start_date' AND '$end_date'";
    $resProd = $conn->query($sqlProd)->fetch_assoc();
    $totalProduction = $resProd['total'] ?? 0;

    // Production Value
    $sqlProdVal = "SELECT SUM(c.unit_selling_rate) as value
      FROM spc_reports s
      JOIN contracts c ON s.project = c.project AND s.pole_type = c.pole_type
      WHERE s.project='$project' AND DATE(s.date_time) BETWEEN '$start_date' AND '$end_date'";
    $resProdVal = $conn->query($sqlProdVal)->fetch_assoc();
    $productionValue = $resProdVal['value'] ?? 0;

    // Total QC & Fail
    $sqlQC = "SELECT COUNT(*) as total, SUM(qc_status='Fail') as fail FROM qc_entries WHERE project='$project' AND DATE(submission_datetime) BETWEEN '$start_date' AND '$end_date'";
    $resQC = $conn->query($sqlQC)->fetch_assoc();
    $totalQC = $resQC['total'] ?? 0;
    $totalQCFails = $resQC['fail'] ?? 0;

    // Cost for the fail
    $failUnitValue = 0;
    if ($totalQCFails > 0) {
      $failValSql = "SELECT c.unit_selling_rate FROM qc_entries q JOIN contracts c ON q.project = c.project AND q.pole_type = c.pole_type WHERE q.project='$project' AND q.qc_status='Fail' AND DATE(q.submission_datetime) BETWEEN '$start_date' AND '$end_date' LIMIT 1";
      $failValRes = $conn->query($failValSql);
      if ($failValRes && $failValRes->num_rows > 0) {
        $failUnitValue = $failValRes->fetch_assoc()['unit_selling_rate'];
      }
    }
    $costPerFail = $failUnitValue > 0 ? $failUnitValue : 500;
    $costForFail = $totalQCFails * $costPerFail;

    // Yard In
    $sqlYardIn = "SELECT COUNT(*) as total FROM yard_in_entries WHERE project='$project' AND DATE(submission_datetime) BETWEEN '$start_date' AND '$end_date'";
    $resYardIn = $conn->query($sqlYardIn)->fetch_assoc();
    $totalYardIn = $resYardIn['total'] ?? 0;

    // Yard In Value
    $sqlYardInVal = "SELECT SUM(c.unit_selling_rate) as value
      FROM yard_in_entries y
      JOIN contracts c ON y.project = c.project AND y.pole_type = c.pole_type
      WHERE y.project='$project' AND DATE(y.submission_datetime) BETWEEN '$start_date' AND '$end_date'";
    $resYardInVal = $conn->query($sqlYardInVal)->fetch_assoc();
    $yardInValue = $resYardInVal['value'] ?? 0;

    // Yard Out
    $sqlYardOut = "SELECT SUM(pcs) as total FROM yard_out_entries WHERE project='$project' AND DATE(submission_datetime) BETWEEN '$start_date' AND '$end_date'";
    $resYardOut = $conn->query($sqlYardOut)->fetch_assoc();
    $totalYardOut = $resYardOut['total'] ?? 0;

    // Yard Out Value
    $sqlYardOutVal = "SELECT SUM(y.pcs * COALESCE(c.unit_selling_rate, avg_rate.avg_rate)) as value
      FROM yard_out_entries y
      LEFT JOIN contracts c ON y.project = c.project AND y.pole_type = c.pole_type
      LEFT JOIN (
        SELECT project, AVG(unit_selling_rate) as avg_rate 
        FROM contracts 
        WHERE project = '$project'
        GROUP BY project
      ) avg_rate ON y.project = avg_rate.project
      WHERE y.project='$project' AND DATE(y.submission_datetime) BETWEEN '$start_date' AND '$end_date'";
    $resYardOutVal = $conn->query($sqlYardOutVal)->fetch_assoc();
    $yardOutValue = $resYardOutVal['value'] ?? 0;

    // Target
    $sqlTarget = "SELECT SUM(t.target_quantity) as target
      FROM daily_targets t
      JOIN contracts c ON t.pole_type = c.pole_type
      WHERE c.project = '$project' AND t.date BETWEEN '$start_date' AND '$end_date'";
    $resTarget = $conn->query($sqlTarget)->fetch_assoc();
    $totalTarget = $resTarget['target'] ?? 0;

    // Percentages
    $prodAchievePercent = $totalTarget > 0 ? round(($totalProduction / $totalTarget) * 100, 1) : 0;
    $qcAchievePercent = $totalTarget > 0 ? round(($totalQC / $totalTarget) * 100, 1) : 0;
    $yardOutAchievePercent = $totalTarget > 0 ? round(($totalYardOut / $totalTarget) * 100, 1) : 0;

    $data[] = [
      'project' => $project,
      'totalProduction' => $totalProduction,
      'productionValue' => $productionValue,
      'totalQC' => $totalQC,
      'totalQCFails' => $totalQCFails,
      'costForFail' => $costForFail,
      'totalYardIn' => $totalYardIn,
      'yardInValue' => $yardInValue,
      'totalYardOut' => $totalYardOut,
      'yardOutValue' => $yardOutValue,
      'totalTarget' => $totalTarget,
      'prodAchievePercent' => $prodAchievePercent,
      'qcAchievePercent' => $qcAchievePercent,
      'yardOutAchievePercent' => $yardOutAchievePercent
    ];
  }

  // Calculate grand totals
  $grand = [
    'totalProduction' => 0,
    'productionValue' => 0,
    'totalQC' => 0,
    'totalQCFails' => 0,
    'costForFail' => 0,
    'totalYardIn' => 0,
    'yardInValue' => 0,
    'totalYardOut' => 0,
    'yardOutValue' => 0,
    'totalTarget' => 0
  ];
  
  foreach ($data as $row) {
    $grand['totalProduction'] += $row['totalProduction'];
    $grand['productionValue'] += $row['productionValue'];
    $grand['totalQC'] += $row['totalQC'];
    $grand['totalQCFails'] += $row['totalQCFails'];
    $grand['costForFail'] += $row['costForFail'];
    $grand['totalYardIn'] += $row['totalYardIn'];
    $grand['yardInValue'] += $row['yardInValue'];
    $grand['totalYardOut'] += $row['totalYardOut'];
    $grand['yardOutValue'] += $row['yardOutValue'];
    $grand['totalTarget'] += $row['totalTarget'];
  }

  $grand['prodAchievePercent'] = $grand['totalTarget'] > 0 ? round(($grand['totalProduction'] / $grand['totalTarget']) * 100, 1) . '%' : '0%';
  $grand['qcAchievePercent'] = $grand['totalTarget'] > 0 ? round(($grand['totalQC'] / $grand['totalTarget']) * 100, 1) . '%' : '0%';
  $grand['yardOutAchievePercent'] = $grand['totalTarget'] > 0 ? round(($grand['totalYardOut'] / $grand['totalTarget']) * 100, 1) . '%' : '0%';

  // Generate HTML content
  $html = '<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Management Dashboard Report</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    table { border-collapse: collapse; width: 100%; margin-top: 20px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: center; }
    th { background-color: #f2f2f2; font-weight: bold; }
    .grand-total { background-color: #eaf6ff; font-weight: bold; }
    .header { text-align: center; margin-bottom: 30px; }
    .date-range { text-align: center; margin-bottom: 20px; color: #666; }
  </style>
</head>
<body>
  <div class="header">
    <h1>' . $emailConfig['sender_name'] . '</h1>
    <div class="date-range">Report Period: ' . date('M d, Y', strtotime($start_date)) . ' to ' . date('M d, Y', strtotime($end_date)) . '</div>
  </div>
  
  <table>
    <thead>
      <tr>
        <th>Project</th>
        <th>Total Production</th>
        <th>Production Value</th>
        <th>Total QC</th>
        <th>Total QC Fail</th>
        <th>Cost for Fail</th>
        <th>Total Yard In</th>
        <th>Yard In Value</th>
        <th>Yard Stock Out</th>
        <th>Yard Out Value</th>
        <th>Prod Target</th>
        <th>Target vs Production %</th>
        <th>Target vs QC %</th>
        <th>Target vs Yard Out %</th>
      </tr>
    </thead>
    <tbody>';

  foreach ($data as $row) {
    $html .= '<tr>
      <td>' . htmlspecialchars($row['project']) . '</td>
      <td>' . $row['totalProduction'] . '</td>
      <td>' . number_format($row['productionValue'], 2) . '</td>
      <td>' . $row['totalQC'] . '</td>
      <td>' . $row['totalQCFails'] . '</td>
      <td>' . number_format($row['costForFail'], 2) . '</td>
      <td>' . $row['totalYardIn'] . '</td>
      <td>' . number_format($row['yardInValue'], 2) . '</td>
      <td>' . $row['totalYardOut'] . '</td>
      <td>' . number_format($row['yardOutValue'], 2) . '</td>
      <td>' . $row['totalTarget'] . '</td>
      <td>' . ($row['totalTarget'] > 0 ? $row['prodAchievePercent'] . '%' : '0%') . '</td>
      <td>' . ($row['totalTarget'] > 0 ? $row['qcAchievePercent'] . '%' : '0%') . '</td>
      <td>' . ($row['totalTarget'] > 0 ? $row['yardOutAchievePercent'] . '%' : '0%') . '</td>
    </tr>';
  }

  $html .= '<tr class="grand-total">
    <td>Grand Total</td>
    <td>' . $grand['totalProduction'] . '</td>
    <td>' . number_format($grand['productionValue'], 2) . '</td>
    <td>' . $grand['totalQC'] . '</td>
    <td>' . $grand['totalQCFails'] . '</td>
    <td>' . number_format($grand['costForFail'], 2) . '</td>
    <td>' . $grand['totalYardIn'] . '</td>
    <td>' . number_format($grand['yardInValue'], 2) . '</td>
    <td>' . $grand['totalYardOut'] . '</td>
    <td>' . number_format($grand['yardOutValue'], 2) . '</td>
    <td>' . $grand['totalTarget'] . '</td>
    <td>' . $grand['prodAchievePercent'] . '</td>
    <td>' . $grand['qcAchievePercent'] . '</td>
    <td>' . $grand['yardOutAchievePercent'] . '</td>
  </tr>
  </tbody>
  </table>
  
  <div style="margin-top: 30px; text-align: center; color: #666; font-size: 12px;">
    <p>This report was automatically generated by the SPC Management Dashboard.</p>
    <p>Generated on: ' . date('M d, Y H:i:s') . '</p>
  </div>
</body>
</html>';

  return $html;
}
?> 
