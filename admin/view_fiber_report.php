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
$stmt = $conn->prepare("SELECT * FROM fiber_test_reports WHERE id = ?");
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

// Test parameters mapping - matches what's actually in the form
$test_parameters_standards = [
    'Unit Weight' => 'EN ISO 1973',
    'Cut Length' => 'ASTM D5103',
    'Tenacity at Break' => 'EN ISO 5079',
    'Std Deviation' => 'EN ISO 5079',
    'CV%' => 'EN ISO 5079',
    'Elongation at Break' => 'EN ISO 5079',
    'Cross Section' => 'Round',
    'No of Crimps' => 'ASTM D3937',
    'UV Weathering' => 'ASTM D4355'
];
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>View Fiber Test Report - <?php echo htmlspecialchars($report['report_number']); ?></title>
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
  <h1>Fiber Test Report</h1>
  
  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Report Number</div>
      <div class="info-value"><?php echo htmlspecialchars($report['report_number']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Sample Name</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_name'] ?? 'N/A'); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">LC No</div>
      <div class="info-value"><?php echo htmlspecialchars($report['lc_no'] ?? 'N/A'); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Manufacturer Name</div>
      <div class="info-value"><?php echo htmlspecialchars($report['manufacturer_name'] ?? 'N/A'); ?></div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Sample Received Date</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_received_date'] ?? 'N/A'); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Sample Tested Date</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_tested_date']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Status</div>
      <div class="info-value">
        <span class="status-badge status-<?php echo htmlspecialchars($report['status']); ?>">
          <?php echo strtoupper(htmlspecialchars($report['status'])); ?>
        </span>
      </div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Test Performed By</div>
      <div class="info-value"><?php echo htmlspecialchars($report['test_performed_by']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Submitted At</div>
      <div class="info-value"><?php echo htmlspecialchars($report['created_at']); ?></div>
    </div>
    <?php if ($report['approved_by']): ?>
    <div class="info-item">
      <div class="info-label">Approved By</div>
      <div class="info-value"><?php echo htmlspecialchars($report['approved_by']); ?></div>
    </div>
    <?php endif; ?>
    <?php if ($report['approved_at']): ?>
    <div class="info-item">
      <div class="info-label">Approved At</div>
      <div class="info-value"><?php echo htmlspecialchars($report['approved_at']); ?></div>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($report['comments'])): ?>
  <div class="info-item" style="margin-top:15px;">
    <div class="info-label">Comments</div>
    <div class="info-value"><?php echo htmlspecialchars($report['comments']); ?></div>
  </div>
  <?php endif; ?>

  <?php if (!empty($report['remarks'])): ?>
  <div class="info-item" style="margin-top:15px;">
    <div class="info-label">Remarks (Approval Notes)</div>
    <div class="info-value"><?php echo htmlspecialchars($report['remarks']); ?></div>
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
      <?php foreach ($test_results as $result): 
        // Skip if no parameter
        if (empty($result['parameter']) || $result['parameter'] === 'N/A') continue;
        
        // For Cross Section, it might not have a test result but should still be shown
        $param_name = $result['parameter'];
        $is_cross_section = ($param_name === 'Cross Section');
        
        // Skip if no test result and not cross section
        if (!$is_cross_section && empty($result['test_result']) && empty($result['unit'])) continue;
        
        // Get test standard from mapping
        $test_standard = $test_parameters_standards[$param_name] ?? 'N/A';
      ?>
      <tr>
        <td><strong><?php echo htmlspecialchars($result['parameter']); ?></strong></td>
        <td><?php echo htmlspecialchars($test_standard); ?></td>
        <td><?php echo htmlspecialchars($result['unit'] ?? ($is_cross_section ? '' : '-')); ?></td>
        <td><strong><?php echo htmlspecialchars($result['test_result'] ?? ($is_cross_section ? 'Round' : '-')); ?></strong></td>
        <td><?php echo htmlspecialchars($result['remarks'] ?? '-'); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
  <p>No test results available.</p>
  <?php endif; ?>

  <h3 style="margin-top:30px;">Acceptance Criteria</h3>
  <table>
    <thead>
      <tr>
        <th>Acceptance Range</th>
        <th>High</th>
        <th>Good</th>
        <th>Medium</th>
        <th>BWDB Requirements</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>Tenacity</strong></td>
        <td>5.4+</td>
        <td>5+</td>
        <td>4.5+</td>
        <td>-</td>
      </tr>
      <tr>
        <td><strong>Elongation</strong></td>
        <td>0.8</td>
        <td>0.6</td>
        <td>-</td>
        <td>60%+</td>
      </tr>
    </tbody>
  </table>

  <?php if (!empty($report['comments'])): ?>
  <h3 style="margin-top:30px;">Comments</h3>
  <div class="info-item">
    <div class="info-value" style="white-space:pre-wrap;"><?php echo htmlspecialchars($report['comments']); ?></div>
  </div>
  <?php endif; ?>

  <button onclick="closeWindow()" class="btn btn-back" style="margin-top:20px;">Close Window</button>
</div>

<script>
function closeWindow() {
    // Check if window was opened as a popup
    if (window.opener) {
        // If opened in popup, close it
        window.close();
    } else {
        // If navigated to, go back
        if (window.history.length > 1) {
            window.history.back();
        } else {
            // Fallback: redirect to dashboard
            window.location.href = '../index.php';
        }
    }
}

// Allow closing with Escape key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeWindow();
    }
});
</script>
</body>
</html>


