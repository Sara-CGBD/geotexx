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

$message = '';
$error = '';

// Handle form submission (approve/reject) - Only for AGM/Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($role, ['admin', 'agm ops', 'agm operations'])) {
    $action = $_POST['action'];
    $admin_name = $_SESSION['full_name'] ?? $_SESSION['username'];
    
    // Get bundle_reference for this report to find all reports in the same range
    $getBundleStmt = $conn->prepare("SELECT bundle_reference FROM weathering_exposure_reports WHERE id = ?");
    $getBundleStmt->bind_param("i", $report_id);
    $getBundleStmt->execute();
    $bundleResult = $getBundleStmt->get_result();
    $bundleRow = $bundleResult->fetch_assoc();
    $bundle_reference = $bundleRow['bundle_reference'] ?? null;
    $getBundleStmt->close();
    
    // If bundle_reference exists and contains a range (|), update all reports with the same bundle_reference
    $whereClause = "id = ?";
    $params = [$report_id];
    $types = "i";
    
    if ($bundle_reference && strpos($bundle_reference, '|') !== false) {
        // It's a range - update all reports with the same bundle_reference
        $whereClause = "bundle_reference = ? AND status = 'pending'";
        $params = [$bundle_reference];
        $types = "s";
    } else {
        // Single report - update only this one
        $whereClause = "id = ? AND status = 'pending'";
        $params = [$report_id];
        $types = "i";
    }
    
    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE weathering_exposure_reports SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE $whereClause");
        if ($types === "s") {
            $stmt->bind_param("ss", $admin_name, $params[0]);
        } else {
            $stmt->bind_param("si", $admin_name, $params[0]);
        }
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Test approved successfully!";
            $stmt->close();
            header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
            header("Cache-Control: post-check=0, pre-check=0", false);
            header("Pragma: no-cache");
            // Always redirect to QC Test Approval Dashboard
            $return_url = 'qc_test_approval_dashboard.php';
            // Build absolute URL dynamically
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $script_dir = dirname($_SERVER['SCRIPT_NAME']); // e.g., /geotexx/admin
            $base_url = $protocol . '://' . $host . $script_dir . '/' . $return_url;
            $relative_url = $script_dir . '/' . $return_url;
            
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Approved</title><script>
                var returnUrl = ' . json_encode($base_url) . ';
                if (window.opener && !window.opener.closed) {
                    window.opener.location.href = returnUrl + "?t=" + new Date().getTime();
                    setTimeout(function() { window.close(); }, 100);
                } else {
                    window.location.href = ' . json_encode($relative_url) . ' + "?t=" + new Date().getTime();
                }
            </script></head><body><p>Approved! Redirecting...</p></body></html>';
            exit();
        }
        $stmt->close();
    } elseif ($action === 'reject') {
        $reject_reason = trim($_POST['reject_reason'] ?? '');
        
        // Handle rejection reasons checkboxes
        if (isset($_POST['rejection_reasons']) && is_array($_POST['rejection_reasons'])) {
            $rejection_reasons = array_map('trim', $_POST['rejection_reasons']);
            $reasons_text = "Rejection Reasons: " . implode(', ', $rejection_reasons);
            if ($reject_reason) {
                $reject_reason = $reasons_text . "\n\nAdditional Comments: " . $reject_reason;
            } else {
                $reject_reason = $reasons_text;
            }
        }
        
        // Use the same where clause logic for rejection
        $stmt = $conn->prepare("UPDATE weathering_exposure_reports SET status = 'rejected', remarks = ?, approved_by = ? WHERE $whereClause");
        if ($types === "s") {
            $stmt->bind_param("sss", $reject_reason, $admin_name, $params[0]);
        } else {
            $stmt->bind_param("ssi", $reject_reason, $admin_name, $params[0]);
        }
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Test rejected and returned to tester!";
            $stmt->close();
            header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
            header("Cache-Control: post-check=0, pre-check=0", false);
            header("Pragma: no-cache");
            // Always redirect to QC Test Approval Dashboard
            $return_url = 'qc_test_approval_dashboard.php';
            // Build absolute URL dynamically
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $script_dir = dirname($_SERVER['SCRIPT_NAME']); // e.g., /geotexx/admin
            $base_url = $protocol . '://' . $host . $script_dir . '/' . $return_url;
            $relative_url = $script_dir . '/' . $return_url;
            
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Rejected</title><script>
                var returnUrl = ' . json_encode($base_url) . ';
                if (window.opener && !window.opener.closed) {
                    window.opener.location.href = returnUrl + "?t=" + new Date().getTime();
                    setTimeout(function() { window.close(); }, 100);
                } else {
                    window.location.href = ' . json_encode($relative_url) . ' + "?t=" + new Date().getTime();
                }
            </script></head><body><p>Rejected! Redirecting...</p></body></html>';
            exit();
        }
        $stmt->close();
    }
}

// Fetch the report
$stmt = $conn->prepare("SELECT * FROM weathering_exposure_reports WHERE id = ?");
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
  <title>View UV Test Report - <?php echo htmlspecialchars($report['report_number']); ?></title>
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
    th, td { border:1px solid #ddd; padding:8px; text-align:center; }
    th { background:#3498db; color:#fff; font-weight:600; }
    .status-badge { padding:4px 12px; border-radius:12px; font-size:12px; font-weight:600; display:inline-block; }
    .status-pending { background:#fff3cd; color:#856404; }
    .status-approved { background:#d4edda; color:#155724; }
    .status-rejected { background:#f8d7da; color:#721c24; }
    .btn { padding:8px 16px; border:none; border-radius:6px; cursor:pointer; text-decoration:none; display:inline-block; margin-top:20px; }
    .btn-back { background:#6c757d; color:#fff; }
    .btn-approve { background:#28a745; color:#fff; }
    .btn-reject { background:#dc3545; color:#fff; }
    textarea { width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-family:inherit; margin-top:10px; }
    .alert { padding:12px; border-radius:6px; margin-bottom:15px; }
    .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
  </style>
</head>
<body>
<div class="container">
  <h1>UV Test Report </h1>
  
  <?php if ($message): ?>
    <div class="alert alert-success"><?php echo $message; ?></div>
  <?php endif; ?>
  
  <?php if ($error): ?>
    <div class="alert alert-error"><?php echo $error; ?></div>
  <?php endif; ?>
  
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
      <div class="info-label">Sample Received From</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_received_from']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Sample Collected From</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_collected_from']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Reference</div>
      <div class="info-value"><?php echo htmlspecialchars($report['reference']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Sample Description</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_description']); ?></div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Recipe</div>
      <div class="info-value"><?php echo htmlspecialchars($report['recipe']); ?></div>
    </div>
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
  </div>

  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Testing Method</div>
      <div class="info-value"><?php echo htmlspecialchars($report['testing_method']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Test Name</div>
      <div class="info-value"><?php echo htmlspecialchars($report['test_name']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Test Speed</div>
      <div class="info-value"><?php echo htmlspecialchars($report['test_speed']); ?> mm/min</div>
    </div>
    <div class="info-item">
      <div class="info-label">Gauge Length</div>
      <div class="info-value"><?php echo htmlspecialchars($report['gauge_length']); ?> mm</div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Specimen Size</div>
      <div class="info-value"><?php echo htmlspecialchars($report['specimen_size']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Temperature / RH%</div>
      <div class="info-value"><?php echo htmlspecialchars($report['temperature']); ?>°C / <?php echo htmlspecialchars($report['rh_percent']); ?>%</div>
    </div>
    <?php if ($report['approved_by']): ?>
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
      $force_retain = array_filter(array_column($test_results, 'force_retain'), function($v) { return is_numeric($v) && $v !== ''; });
      $elong_after = array_filter(array_column($test_results, 'elongation_after'), function($v) { return is_numeric($v) && $v !== ''; });
      $elong_before = array_filter(array_column($test_results, 'elongation_before'), function($v) { return is_numeric($v) && $v !== ''; });
      
      function calc_stats($arr) {
          if (empty($arr)) return ['avg' => 0, 'sd' => 0, 'cv' => 0, 'max' => 0, 'min' => 0];
          $avg = array_sum($arr) / count($arr);
          $variance = array_sum(array_map(function($x) use ($avg) { return pow($x - $avg, 2); }, $arr)) / (count($arr) - 1);
          $sd = sqrt($variance);
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
        <td><?php echo $stats_force_retain['sd']; ?></td>
        <td><?php echo $stats_elong_after['sd']; ?></td>
        <td><?php echo $stats_elong_before['sd']; ?></td>
      </tr>
      <tr>
        <td><strong>CV%</strong></td>
        <td><?php echo $stats_bf_after['cv']; ?>%</td>
        <td><?php echo $stats_bf_before['cv']; ?>%</td>
        <td><?php echo $stats_force_retain['cv']; ?>%</td>
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

  <?php if (in_array($role, ['admin', 'agm ops', 'agm operations', 'management']) && in_array($report['status'], ['pending', 'checked'])): ?>
  <h3 style="margin-top:30px;">Admin Actions</h3>
  <form method="POST" action="" style="margin-bottom:15px; padding:15px; background:#f8f9fa; border-radius:8px; border:1px solid #ddd;">
    <input type="hidden" name="action" value="approve">
    <p style="margin:0 0 15px 0; padding:12px; background:#e8f6ec; border-left:4px solid #28a745; border-radius:4px; color:#065f46; font-size:14px;">
      <i class="fas fa-info-circle"></i> <strong>Note:</strong> After approval, this test will appear in the "Approved Tests with Routing" section of the QC Test Approval Dashboard where you can set the routing destination (FG or Bag Production).
    </p>
    <button type="submit" class="btn btn-approve">✓ Final Approve</button>
  </form>
  
  <button onclick="document.getElementById('rejectForm').style.display='block'" class="btn btn-reject">✗ Reject</button>
  
  <div id="rejectForm" style="display:none; margin-top:15px; padding:15px; background:#fff3cd; border:1px solid #ffc107; border-radius:6px;">
    <form method="POST" action="" onsubmit="return validateRejection()">
      <input type="hidden" name="action" value="reject">
      <label style="font-weight:600; display:block; margin-bottom:10px;">Reason for Rejection (Select at least one):</label>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="rejection_reasons[]" value="Incorrect Roll Identification" style="margin-right:8px;">
          Incorrect Roll Identification
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="rejection_reasons[]" value="Incorrect Fiber Specification Entry" style="margin-right:8px;">
          Incorrect Fiber Specification Entry
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="rejection_reasons[]" value="Excessive Sampling" style="margin-right:8px;">
          Excessive Sampling
        </label>
      </div>
      <label style="font-weight:bold; display:block; margin:15px 0 8px 0;">Additional Comments (Optional):</label>
      <textarea name="reject_reason" id="rejectComments" style="width:calc(100% - 20px); min-height:80px;" placeholder="Provide additional details..."></textarea>
      <div style="margin-top:10px;">
        <button type="submit" class="btn btn-reject">Submit Rejection</button>
        <button type="button" onclick="document.getElementById('rejectForm').style.display='none'" style="padding:10px 20px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer; text-decoration:none; display:inline-block; margin:10px 5px 0 0; font-size:14px;">Cancel</button>
      </div>
    </form>
  </div>
  
  <script>
    function validateRejection() {
      const checkboxes = document.querySelectorAll('input[name="rejection_reasons[]"]');
      const checked = Array.from(checkboxes).filter(cb => cb.checked);
      
      if (checked.length === 0) {
        alert('Please select at least one reason for rejection!');
        return false;
      }
      
      return confirm('Are you sure you want to reject this test?');
    }
  </script>
  <?php elseif (in_array($role, ['admin', 'agm ops', 'agm operations'])): ?>
  <div style="background:#d4edda; padding:15px; border-radius:6px; margin-top:20px;">
    <strong>This test has already been processed (Status: <?php echo strtoupper($report['status']); ?>)</strong>
  </div>
  <?php endif; ?>

  <div style="margin-top:20px;">
    <?php 
    $return_url = $_GET['return'] ?? 'qc_test_approval_dashboard.php';
    if ($return_url === 'qc_test_approval_dashboard') {
        $return_url = 'qc_test_approval_dashboard.php';
    } elseif (empty($return_url)) {
        $return_url = 'qc_test_approval_dashboard.php';
    }
    ?>
    <a href="<?php echo htmlspecialchars($return_url); ?>" class="btn btn-back">← Back to Dashboard</a>
    <button onclick="closeWindow()" class="btn btn-back">Close Window</button>
  </div>
</div>

<script>
function closeWindow() {
    if (window.opener) {
        window.close();
    } else {
        const returnUrl = '<?php 
            $return_url = $_GET['return'] ?? 'qc_test_approval_dashboard.php';
            if ($return_url === 'qc_test_approval_dashboard') {
                $return_url = 'qc_test_approval_dashboard.php';
            } elseif (empty($return_url)) {
                $return_url = 'qc_test_approval_dashboard.php';
            }
            echo htmlspecialchars($return_url); 
        ?>';
        if (returnUrl && returnUrl !== 'qc_test_approval_dashboard.php') {
            window.location.href = returnUrl;
        } else if (window.history.length > 1) {
            window.history.back();
        } else {
            window.location.href = 'qc_test_approval_dashboard.php';
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


