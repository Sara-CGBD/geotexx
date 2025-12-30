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
$stmt = $conn->prepare("SELECT * FROM sun_test_reports WHERE id = ?");
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
    $can_view = true; // Admin can view all
} elseif ($role === 'tester' && $report['reporter_id'] == $user_id && $report['status'] === 'rejected') {
    $can_view = true; // Tester can view their own rejected reports
}

if (!$can_view) {
    die('Access denied. You can only view your own rejected reports.');
}

// Decode test results JSON
$test_results = json_decode($report['test_results'], true);

// Debug: Show raw JSON if results are empty
if (empty($test_results) && !empty($report['test_results'])) {
    echo '<div style="background:#fff3cd; padding:10px; margin:10px 0; border-radius:4px;">
            <strong>Debug:</strong> Raw JSON data: ' . htmlspecialchars($report['test_results']) . '
          </div>';
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>View Sun Test Report - <?php echo htmlspecialchars($report['report_number']); ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:20px; color:#2c3e50; }
    .container { max-width:1200px; margin:auto; background:#fff; border-radius:12px; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.08);} 
    h1 { margin:0 0 20px 0; color:#2c3e50; border-bottom:2px solid #3498db; padding-bottom:10px; }
    .info-grid { display:block; margin-bottom:20px; }
    .info-item { background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db; }
    .info-label { font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px; }
    .info-value { font-size:14px; color:#2c3e50; }
    table { width:100%; border-collapse:collapse; margin-top:20px; font-size:13px; }
    th, td { border:1px solid #ddd; padding:8px; text-align:center; }
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
  <h1>Sun Test Report</h1>
  
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
    <?php if (!empty($report['reference_number'] ?? '')): ?>
    <div class="info-item">
      <div class="info-label">Reference Number</div>
      <div class="info-value"><?php echo htmlspecialchars($report['reference_number']); ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($report['lab_test_number'] ?? '')): ?>
    <div class="info-item">
      <div class="info-label">Lab Test Number</div>
      <div class="info-value"><?php echo htmlspecialchars($report['lab_test_number']); ?></div>
    </div>
    <?php endif; ?>
  </div>

  <div class="info-grid">
    <?php if (!empty($report['sample_description'] ?? '')): ?>
    <div class="info-item">
      <div class="info-label">Sample Description</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_description']); ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($report['sample_received_from'] ?? '')): ?>
    <div class="info-item">
      <div class="info-label">Reference</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_received_from']); ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($report['received_date'] ?? '')): ?>
    <div class="info-item">
      <div class="info-label">Received Date</div>
      <div class="info-value"><?php echo htmlspecialchars($report['received_date']); ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($report['test_start_date'] ?? '')): ?>
    <div class="info-item">
      <div class="info-label">Test Start Date</div>
      <div class="info-value"><?php echo htmlspecialchars($report['test_start_date']); ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($report['test_end_date'] ?? '')): ?>
    <div class="info-item">
      <div class="info-label">Test End Date</div>
      <div class="info-value"><?php echo htmlspecialchars($report['test_end_date']); ?></div>
    </div>
    <?php endif; ?>
  </div>

  <div class="info-grid">
    <?php if (!empty($report['testing_method'] ?? '')): ?>
    <div class="info-item">
      <div class="info-label">Testing Method</div>
      <div class="info-value"><?php echo htmlspecialchars($report['testing_method']); ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($report['test_name'] ?? '')): ?>
    <div class="info-item">
      <div class="info-label">Test Name</div>
      <div class="info-value"><?php echo htmlspecialchars($report['test_name']); ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($report['test_speed'] ?? '')): ?>
    <div class="info-item">
      <div class="info-label">Test Speed</div>
      <div class="info-value"><?php echo htmlspecialchars($report['test_speed']); ?> mm/min</div>
    </div>
    <?php endif; ?>
    <?php if (!empty($report['gauge_length'] ?? '')): ?>
    <div class="info-item">
      <div class="info-label">Gauge Length</div>
      <div class="info-value"><?php echo htmlspecialchars($report['gauge_length']); ?> mm</div>
    </div>
    <?php endif; ?>
  </div>

  <div class="info-grid">
    <?php if (!empty($report['specimen_size'] ?? '')): ?>
    <div class="info-item">
      <div class="info-label">Specimen Size</div>
      <div class="info-value"><?php echo htmlspecialchars($report['specimen_size']); ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($report['temperature'] ?? '') || !empty($report['rh_percent'] ?? '')): ?>
    <div class="info-item">
      <div class="info-label">Temperature / RH%</div>
      <div class="info-value"><?php echo htmlspecialchars($report['temperature'] ?? ''); ?>°C / <?php echo htmlspecialchars($report['rh_percent'] ?? ''); ?>%</div>
    </div>
    <?php endif; ?>
    <?php if (!empty($report['approved_by'] ?? '')): ?>
    <div class="info-item">
      <div class="info-label">Approved By</div>
      <div class="info-value"><?php echo htmlspecialchars($report['approved_by']); ?></div>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!empty($report['note'])): ?>
  <div class="info-item" style="margin-top:15px;">
    <div class="info-label">Note</div>
    <div class="info-value"><?php echo htmlspecialchars($report['note']); ?></div>
  </div>
  <?php endif; ?>

  <h3 style="margin-top:30px;">Test Results</h3>
  <?php 
  // Debug output
  if (empty($test_results)) {
      echo '<p style="color:red;">Debug: test_results is empty or null</p>';
      echo '<p>Raw JSON length: ' . strlen($report['test_results']) . ' characters</p>';
      if (!empty($report['test_results'])) {
          echo '<pre style="background:#f0f0f0; padding:10px; overflow:auto;">' . htmlspecialchars(substr($report['test_results'], 0, 500)) . '</pre>';
      }
  }
  if (!empty($test_results)): 
  ?>
  <table>
    <thead>
      <tr>
        <th rowspan="2">Specimen No</th>
        <th rowspan="2">Test Direction</th>
        <th colspan="2">Breaking Force (N)</th>
        <th rowspan="2">Force Retain (%)</th>
        <th colspan="2">Elongation (%)</th>
      </tr>
      <tr>
        <th>After</th>
        <th>Before</th>
        <th>After</th>
        <th>Before</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($test_results as $result): ?>
      <tr>
        <td><?php echo htmlspecialchars($result['specimen_no'] ?? 'N/A'); ?></td>
        <td><?php echo htmlspecialchars($result['test_direction'] ?? 'N/A'); ?></td>
        <td><?php echo htmlspecialchars($result['breaking_force_after'] ?? 'N/A'); ?></td>
        <td><?php echo htmlspecialchars($result['breaking_force_before'] ?? 'N/A'); ?></td>
        <td><?php echo htmlspecialchars($result['force_retain'] ?? 'N/A'); ?></td>
        <td><?php echo htmlspecialchars($result['elongation_after'] ?? 'N/A'); ?></td>
        <td><?php echo htmlspecialchars($result['elongation_before'] ?? 'N/A'); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php
  // Calculate statistics
  if (!empty($test_results)) {
      $bf_after = array_filter(array_column($test_results, 'breaking_force_after'), function($v) { return is_numeric($v) && $v !== ''; });
      $bf_before = array_filter(array_column($test_results, 'breaking_force_before'), function($v) { return is_numeric($v) && $v !== ''; });
      
      // Force retain may contain string like "99.8 %"; extract numeric part
      $force_retain_raw = array_column($test_results, 'force_retain');
      $force_retain = [];
      foreach ($force_retain_raw as $fr) {
          if (is_string($fr)) {
              $fr = preg_replace('/[^\d.\-eE]+/', '', $fr);
          }
          if (is_numeric($fr) && $fr !== '') {
              $force_retain[] = (float)$fr;
          }
      }
      
      $elong_after = array_filter(array_column($test_results, 'elongation_after'), function($v) { return is_numeric($v) && $v !== ''; });
      $elong_before = array_filter(array_column($test_results, 'elongation_before'), function($v) { return is_numeric($v) && $v !== ''; });
      
      function calc_stats($arr) {
          $n = count($arr);
          if ($n === 0) return ['avg' => 0, 'sd' => 0, 'cv' => 0, 'max' => 0, 'min' => 0];
          $avg = array_sum($arr) / $n;
          // If fewer than 2 points, set SD/CV to 0 to avoid division by zero
          if ($n < 2) {
              $sd = 0;
          } else {
              $variance = array_sum(array_map(function($x) use ($avg) { return pow($x - $avg, 2); }, $arr)) / ($n - 1);
              $sd = sqrt($variance);
          }
          $cv = $avg != 0 ? ($sd / $avg) * 100 : 0;
          return [
              'avg' => round($avg, 1),
              'sd' => round($sd, 2),
              'cv' => round($cv, 1),
              'max' => round(max($arr), 1),
              'min' => round(min($arr), 1)
          ];
      }
      
      $stats_bf_after = calc_stats($bf_after);
      $stats_bf_before = calc_stats($bf_before);
      $stats_force_retain = calc_stats($force_retain);
      $stats_elong_after = calc_stats($elong_after);
      $stats_elong_before = calc_stats($elong_before);
  ?>
  
  <h3 style="margin-top:30px;">Statistics Summary</h3>
  <table>
    <thead>
      <tr>
        <th>Statistic</th>
        <th>Breaking Force After (N)</th>
        <th>Breaking Force Before (N)</th>
        <th>Force Retain (%)</th>
        <th>Elongation After (%)</th>
        <th>Elongation Before (%)</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>Average</strong></td>
        <td><?php echo $stats_bf_after['avg']; ?></td>
        <td><?php echo $stats_bf_before['avg']; ?></td>
        <td><?php echo $stats_force_retain['avg']; ?>%</td>
        <td><?php echo $stats_elong_after['avg']; ?></td>
        <td><?php echo $stats_elong_before['avg']; ?></td>
      </tr>
      <tr>
        <td><strong>SD</strong></td>
        <td><?php echo $stats_bf_after['sd']; ?></td>
        <td><?php echo $stats_bf_before['sd']; ?></td>
        <td>-</td>
        <td><?php echo $stats_elong_after['sd']; ?></td>
        <td><?php echo $stats_elong_before['sd']; ?></td>
      </tr>
      <tr>
        <td><strong>CV%</strong></td>
        <td><?php echo $stats_bf_after['cv']; ?>%</td>
        <td><?php echo $stats_bf_before['cv']; ?>%</td>
        <td>-</td>
        <td><?php echo $stats_elong_after['cv']; ?>%</td>
        <td><?php echo $stats_elong_before['cv']; ?>%</td>
      </tr>
      <tr>
        <td><strong>Maximum</strong></td>
        <td><?php echo $stats_bf_after['max']; ?></td>
        <td><?php echo $stats_bf_before['max']; ?></td>
        <td><?php echo $stats_force_retain['max']; ?>%</td>
        <td><?php echo $stats_elong_after['max']; ?></td>
        <td><?php echo $stats_elong_before['max']; ?></td>
      </tr>
      <tr>
        <td><strong>Minimum</strong></td>
        <td><?php echo $stats_bf_after['min']; ?></td>
        <td><?php echo $stats_bf_before['min']; ?></td>
        <td><?php echo $stats_force_retain['min']; ?>%</td>
        <td><?php echo $stats_elong_after['min']; ?></td>
        <td><?php echo $stats_elong_before['min']; ?></td>
      </tr>
    </tbody>
  </table>
  <?php } ?>
  
  <?php else: ?>
  <p>No test results available.</p>
  <?php endif; ?>

  <div style="margin-top:20px;">
    <a href="qc_reports_dashboard.php" class="btn btn-back">← Back to Dashboard</a>
    <button onclick="closeWindow()" class="btn btn-back">Close Window</button>
  </div>
</div>

<script>
function closeWindow() {
    if (window.opener) {
        window.close();
    } else {
        if (window.history.length > 1) {
            window.history.back();
        } else {
            window.location.href = 'qc_reports_dashboard.php';
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


