<?php
session_start();
require_once '../forms/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit();
}

$role = strtolower(trim($_SESSION['role'] ?? 'user'));
$user_id = $_SESSION['user_id'];

$conn = SecurityConfig::getConnection();
$report_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($report_id <= 0) {
    die('Invalid report ID');
}

// Fetch the report
$stmt = $conn->prepare("SELECT * FROM fabric_after_production_tests WHERE id = ?");
$stmt->bind_param("i", $report_id);
$stmt->execute();
$result = $stmt->get_result();
$report = $result->fetch_assoc();
$stmt->close();

if (!$report) {
    die('Report not found');
}

// Check access: Admin/AGM can view any report, Tester can only view their own rejected reports
$can_view = false;
if (in_array($role, ['admin', 'agm ops', 'agm operations', 'management'])) {
    $can_view = true;
} elseif ($role === 'tester' && $report['reporter_id'] == $user_id && $report['status'] === 'rejected') {
    $can_view = true;
}

if (!$can_view) {
    die('Access denied. You can only view your own rejected reports.');
}

// Decode test results JSON
$test_results = json_decode($report['test_results'], true);

// Test parameters - same as in the form
$test_parameters = [
    1 => ['param' => 'GSM', 'standards' => ['ASTM D5261']],
    2 => ['param' => 'Thickness', 'standards' => ['ASTM D5199']],
    3 => ['param' => 'Strip Tensile Strength MD', 'standards' => ['ASTM D5035']],
    4 => ['param' => 'Strip Tensile Strength CD', 'standards' => ['ASTM D5035']],
    5 => ['param' => 'Strip Tensile Elongation MD', 'standards' => ['ASTM D5035']],
    6 => ['param' => 'Strip Tensile Elongation CD', 'standards' => ['ASTM D5035']],
    7 => ['param' => 'CBR Puncture Strength', 'standards' => ['ASTM D6241']],
    8 => ['param' => 'Grab Tensile Strength MD', 'standards' => ['ASTM D4632']],
    9 => ['param' => 'Grab Tensile Strength CD', 'standards' => ['ASTM D4632']],
    10 => ['param' => 'Grab Tensile Elongation MD', 'standards' => ['ASTM D4632']],
    11 => ['param' => 'Grab Tensile Elongation CD', 'standards' => ['ASTM D4632']]
];
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>View Fabric After Production Test - <?php echo htmlspecialchars($report['report_number']); ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:20px; color:#2c3e50; }
    .container { max-width:1200px; margin:auto; background:#fff; border-radius:12px; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.08);} 
    h1 { margin:0 0 20px 0; color:#2c3e50; border-bottom:2px solid #3498db; padding-bottom:10px; }
    .info-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:15px; margin-bottom:20px; }
    .info-item { background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db; }
    .info-label { font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px; }
    .info-value { font-size:14px; color:#2c3e50; }
    table { width:100%; border-collapse:collapse; margin-top:20px; font-size:13px; }
    th, td { border:1px solid #ddd; padding:8px; text-align:left; }
    th { background:#3498db; color:#fff; font-weight:600; }
    .status-badge { padding:4px 12px; border-radius:12px; font-size:12px; font-weight:600; display:inline-block; }
    .status-pending { background:#fff3cd; color:#856404; }
    .status-approved { background:#d4edda; color:#155724; }
    .status-rejected { background:#f8d7da; color:#721c24; }
    .btn { padding:8px 16px; border:none; border-radius:6px; cursor:pointer; text-decoration:none; display:inline-block; margin-top:20px; }
    .btn-back { background:#6c757d; color:#fff; }
  </style>
</head>
<body>
<div class="container">
  <h1>Fabric After Production Test Summary</h1>
  
  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Report Number</div>
      <div class="info-value"><?php echo htmlspecialchars($report['report_number']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Status</div>
      <div class="info-value">
        <span class="status-badge status-<?php echo htmlspecialchars($report['status']); ?>">
          <?php echo strtoupper(htmlspecialchars($report['status'])); ?>
        </span>
      </div>
    </div>
    <div class="info-item">
      <div class="info-label">Sample ID</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_id']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Test Performed By</div>
      <div class="info-value"><?php echo htmlspecialchars($report['test_performed_by']); ?></div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">GSM</div>
      <div class="info-value"><?php echo htmlspecialchars($report['gsm']); ?></div>
    </div>
    <?php if (!empty($report['line_number'])): ?>
    <div class="info-item">
      <div class="info-label">Line Number</div>
      <div class="info-value"><?php echo htmlspecialchars($report['line_number']); ?></div>
    </div>
    <?php endif; ?>
    <div class="info-item">
      <div class="info-label">Roll Number</div>
      <div class="info-value"><?php echo htmlspecialchars($report['roll_number']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Batch Information</div>
      <div class="info-value"><?php echo htmlspecialchars($report['batch_information']); ?></div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Received From</div>
      <div class="info-value"><?php echo htmlspecialchars($report['received_from']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Sample Received Date</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_received_date']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Sample Tested Date</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_tested_date']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Submitted At</div>
      <div class="info-value"><?php echo htmlspecialchars($report['created_at']); ?></div>
    </div>
  </div>

  <?php if ($report['approved_by']): ?>
  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Approved By</div>
      <div class="info-value"><?php echo htmlspecialchars($report['approved_by']); ?></div>
    </div>
    <?php if ($report['approved_at']): ?>
    <div class="info-item">
      <div class="info-label">Approved At</div>
      <div class="info-value"><?php echo htmlspecialchars($report['approved_at']); ?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($report['note'])): ?>
  <div class="info-item" style="margin-top:15px;">
    <div class="info-label">Note</div>
    <div class="info-value" style="white-space:pre-wrap;"><?php echo htmlspecialchars($report['note']); ?></div>
  </div>
  <?php endif; ?>

  <?php if (!empty($report['remarks'])): ?>
  <div class="info-item" style="margin-top:15px;">
    <div class="info-label">Remarks (Approval Notes)</div>
    <div class="info-value" style="white-space:pre-wrap;"><?php echo htmlspecialchars($report['remarks']); ?></div>
  </div>
  <?php endif; ?>

  <h3 style="margin-top:30px;">Test Results</h3>
  <?php if (!empty($test_results)): ?>
  <table>
    <thead>
      <tr>
        <th>SL No</th>
        <th>Parameter</th>
        <th>Test Standard</th>
        <th>Unit</th>
        <th>Test Result</th>
        <th>Remarks</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($test_results as $sl_no => $result): ?>
      <tr>
        <td><?php echo htmlspecialchars($sl_no); ?></td>
        <td><?php echo htmlspecialchars($test_parameters[$sl_no]['param'] ?? 'N/A'); ?></td>
        <td><?php echo htmlspecialchars($result['standard'] ?? ($test_parameters[$sl_no]['standards'][0] ?? 'N/A')); ?></td>
        <td><?php echo htmlspecialchars($result['unit'] ?? 'N/A'); ?></td>
        <td><?php echo htmlspecialchars($result['result'] ?? 'N/A'); ?></td>
        <td><?php echo htmlspecialchars($result['remarks'] ?? '-'); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p>No test results available.</p>
  <?php endif; ?>

  <button onclick="closeWindow()" class="btn btn-back">Close Window</button>
</div>

<script>
function closeWindow() {
    if (window.opener) {
        window.close();
    } else {
        if (window.history.length > 1) {
            window.history.back();
        } else {
            window.location.href = '../index.php';
        }
    }
}
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeWindow();
    }
});
</script>
</body>
</html>


