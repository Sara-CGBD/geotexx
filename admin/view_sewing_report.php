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
$stmt = $conn->prepare("SELECT * FROM sewing_thread_reports WHERE id = ?");
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
    1 => ['param' => 'Denier', 'standards' => ['ISO 2060']],
    2 => ['param' => 'Tenacity at Break', 'standards' => ['ASTM D2256']],
    3 => ['param' => 'Std Deviation', 'standards' => ['ASTM D2256']],
    4 => ['param' => 'CV%', 'standards' => ['ASTM D2256']],
    5 => ['param' => 'Elongation at Break', 'standards' => ['ASTM D2256']]
];
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>View Sewing Thread Report - <?php echo htmlspecialchars($report['report_number']); ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:20px; color:#2c3e50; }
    .container { max-width:1000px; margin:auto; background:#fff; border-radius:12px; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.08);} 
    h1 { margin:0 0 20px 0; color:#2c3e50; border-bottom:2px solid #3498db; padding-bottom:10px; }
    .info-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:15px; margin-bottom:20px; }
    .info-item { background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db; }
    .info-label { font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px; }
    .info-value { font-size:14px; color:#2c3e50; }
    table { width:100%; border-collapse:collapse; margin-top:20px; }
    th, td { border:1px solid #ddd; padding:10px; text-align:left; }
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
  <h1>Sewing Thread Report</h1>
  
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
      <div class="info-label">Test Performed By</div>
      <div class="info-value"><?php echo htmlspecialchars($report['test_performed_by']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Submitted At</div>
      <div class="info-value"><?php echo htmlspecialchars($report['created_at']); ?></div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Sample Description</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_description']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Sample Received From</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_received_from']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Sample Collected From</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_collected_from']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Ref</div>
      <div class="info-value"><?php echo htmlspecialchars($report['reference'] ?? 'N/A'); ?></div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Received Date</div>
      <div class="info-value"><?php echo htmlspecialchars($report['received_date']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Test Start Date</div>
      <div class="info-value"><?php echo htmlspecialchars($report['test_start_date']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Test End Date</div>
      <div class="info-value"><?php echo htmlspecialchars($report['test_end_date']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Temperature / RH%</div>
      <div class="info-value"><?php echo htmlspecialchars($report['test_temperature']); ?>°C / <?php echo htmlspecialchars($report['rh_percent']); ?>%</div>
    </div>
  </div>

  <?php if (!empty($report['others_information'])): ?>
  <div class="info-item" style="margin-top:15px;">
    <div class="info-label">Other Information</div>
    <div class="info-value"><?php echo htmlspecialchars($report['others_information']); ?></div>
  </div>
  <?php endif; ?>

  <?php if ($report['approved_by']): ?>
  <div class="info-grid" style="margin-top:15px;">
    <div class="info-item">
      <div class="info-label">Approved By</div>
      <div class="info-value"><?php echo htmlspecialchars($report['approved_by']); ?></div>
    </div>
  </div>
  <?php endif; ?>

  <h3 style="margin-top:30px;">Test Results</h3>
  
  <?php if (!empty($test_results) && is_array($test_results)): ?>
  <table>
    <thead>
      <tr>
        <th>Parameter</th>
        <th>Test Standard</th>
        <th>Unit</th>
        <th>Test Result</th>
        <th>Remarks</th>
      </tr>
    </thead>
    <tbody>
      <?php 
      // If test_results is empty or not an array, try to decode it
      if (empty($test_results) || !is_array($test_results)) {
        $test_results = [];
      }
      
      // If we have test_results but they don't have parameter/sl_no, try to reconstruct from sl_no
      foreach ($test_results as $result): 
        // Get the parameter name - either from result or from sl_no lookup
        $param_name = '';
        $sl_int = isset($result['sl_no']) ? (int)$result['sl_no'] : 0;
        
        if (!empty($result['parameter'])) {
          $param_name = $result['parameter'];
        } elseif ($sl_int > 0 && isset($test_parameters[$sl_int])) {
          $param_name = $test_parameters[$sl_int]['param'];
        } else {
          // Skip if we can't determine the parameter
          continue;
        }
        
        // Get test result value - check both 'test_result' and 'result' fields
        $test_result_value = $result['test_result'] ?? $result['result'] ?? '';
        
        // Skip if no test result and no unit
        if (empty($test_result_value) && empty($result['unit'])) {
          continue;
        }
        
        // Get the standard from test_parameters using sl_no
        $param_data = $test_parameters[$sl_int] ?? null;
      ?>
      <tr>
        <td><strong><?php echo htmlspecialchars($param_name); ?></strong></td>
        <td><?php echo htmlspecialchars($param_data['standards'][0] ?? 'N/A'); ?></td>
        <td><?php echo htmlspecialchars($result['unit'] ?? '-'); ?></td>
        <td><strong><?php echo htmlspecialchars($test_result_value ?: '-'); ?></strong></td>
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


