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
$stmt = $conn->prepare("SELECT * FROM fabric_pre_production_tests WHERE id = ?");
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
} elseif ($role === 'tester' && $report['reporter_id'] == $user_id && ($report['status'] === 'rejected_by_checker' || $report['status'] === 'rejected_by_approver')) {
    $can_view = true;
}

if (!$can_view) {
    die('Access denied. You can only view your own rejected reports.');
}

// Decode test data JSON
$test_data = json_decode($report['test_data'], true);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>View Fabric Pre-Production Test - <?php echo htmlspecialchars($report['report_number']); ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:20px; color:#2c3e50; }
    .container { max-width:1400px; margin:auto; background:#fff; border-radius:12px; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.08);} 
    h1 { margin:0 0 20px 0; color:#2c3e50; border-bottom:2px solid #3498db; padding-bottom:10px; }
    h3 { margin:20px 0 10px 0; color:#2c3e50; border-bottom:1px solid #ddd; padding-bottom:5px; }
    .info-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:15px; margin-bottom:20px; }
    .info-item { background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db; }
    .info-label { font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px; }
    .info-value { font-size:14px; color:#2c3e50; }
    table { width:100%; border-collapse:collapse; margin-top:15px; font-size:12px; }
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
  <h1>Fabric Pre-Production Test Report</h1>
  
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

  <h3>Sample Information</h3>
  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Sample Details</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_details']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Sample Collected From</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_collected_from']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Batch Information</div>
      <div class="info-value"><?php echo htmlspecialchars($report['batch_information']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">GSM</div>
      <div class="info-value"><?php echo htmlspecialchars($report['gsm']); ?></div>
    </div>
  </div>

  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Line No</div>
      <div class="info-value"><?php echo htmlspecialchars($report['line_no']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Roll Number</div>
      <div class="info-value"><?php echo htmlspecialchars($report['roll_number']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Product Reference</div>
      <div class="info-value"><?php echo htmlspecialchars($report['product_reference']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Customer Reference</div>
      <div class="info-value"><?php echo htmlspecialchars($report['customer_reference']); ?></div>
    </div>
  </div>

  <h3>Dates</h3>
  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Sample Received Date</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_received_date']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Sample Production Date</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_production_date']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Test Period From</div>
      <div class="info-value"><?php echo htmlspecialchars($report['test_period_from']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Test Period To</div>
      <div class="info-value"><?php echo htmlspecialchars($report['test_period_to']); ?></div>
    </div>
  </div>

  <h3>Test Conditions</h3>
  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Sample Received From</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_received_from']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Lighthouse Reference</div>
      <div class="info-value"><?php echo htmlspecialchars($report['lighthouse_reference'] ?: '-'); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Temperature (°C)</div>
      <div class="info-value"><?php echo htmlspecialchars($report['temperature']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">RH (%)</div>
      <div class="info-value"><?php echo htmlspecialchars($report['rh_percentage']); ?></div>
    </div>
  </div>

  <?php if (!empty($report['others_information'])): ?>
  <div class="info-item" style="margin-top:15px;">
    <div class="info-label">Other Information</div>
    <div class="info-value" style="white-space:pre-wrap;"><?php echo htmlspecialchars($report['others_information']); ?></div>
  </div>
  <?php endif; ?>

  <?php if ($report['checked_by']): ?>
  <h3>Checker Information</h3>
  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Checked By</div>
      <div class="info-value"><?php echo htmlspecialchars($report['checked_by']); ?></div>
    </div>
    <?php if ($report['checked_at']): ?>
    <div class="info-item">
      <div class="info-label">Checked At</div>
      <div class="info-value"><?php echo htmlspecialchars($report['checked_at']); ?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php if (!empty($report['checker_remarks'])): ?>
  <div class="info-item" style="margin-top:15px;">
    <div class="info-label">Checker Remarks</div>
    <div class="info-value" style="white-space:pre-wrap;"><?php echo htmlspecialchars($report['checker_remarks']); ?></div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($report['approved_by']): ?>
  <h3>Approval Information</h3>
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

  <?php if (!empty($report['remarks'])): ?>
  <div class="info-item" style="margin-top:15px;">
    <div class="info-label">Remarks (Approval Notes)</div>
    <div class="info-value" style="white-space:pre-wrap;"><?php echo htmlspecialchars($report['remarks']); ?></div>
  </div>
  <?php endif; ?>

  <h3>Test Data Summary</h3>
  
  <?php if (!empty($test_data)): ?>
  
  <?php if (!isset($test_data['summary']) || empty($test_data['summary'])): ?>
  <div style="background:#fff3cd; border:1px solid #ffc107; padding:12px; border-radius:6px; margin-bottom:15px;">
    <strong>⚠️ Note:</strong> No summary data available for this report.
  </div>
  <?php endif; ?>
  
  <p><strong>Note:</strong> This viewer shows a summary of the test data. The full detailed test data table with all rows is saved in the database.</p>
  
  <?php if (isset($test_data['summary'])): ?>
  <h4 style="margin-top:20px;">Summary of Test Results</h4>
  <table>
    <thead>
      <tr>
        <th>Statistic</th>
        <th>GSM</th>
        <th>Thickness (mm)</th>
        <th>Strip MD Strength (N)</th>
        <th>Strip MD Elong. (%)</th>
        <th>Strip CD Strength (N)</th>
        <th>Strip CD Elong. (%)</th>
        <th>CBR Force (N)</th>
        <th>CBR Disp. (mm)</th>
        <th>Grab MD Force (N)</th>
        <th>Grab MD Elong. (%)</th>
        <th>Grab CD Force (N)</th>
        <th>Grab CD Elong. (%)</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>Average</strong></td>
        <td><?php echo htmlspecialchars($test_data['summary']['gsm_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['thickness_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_md_strength_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_md_elongation_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_cd_strength_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_cd_elongation_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['cbr_force_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['cbr_displacement_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_md_force_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_md_elongation_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_cd_force_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_cd_elongation_avg'] ?? '-'); ?></td>
      </tr>
      <tr>
        <td><strong>SD</strong></td>
        <td><?php echo htmlspecialchars($test_data['summary']['gsm_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['thickness_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_md_strength_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_md_elongation_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_cd_strength_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_cd_elongation_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['cbr_force_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['cbr_displacement_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_md_force_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_md_elongation_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_cd_force_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_cd_elongation_sd'] ?? '-'); ?></td>
      </tr>
      <tr>
        <td><strong>CV%</strong></td>
        <td><?php echo htmlspecialchars($test_data['summary']['gsm_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['thickness_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_md_strength_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_md_elongation_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_cd_strength_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_cd_elongation_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['cbr_force_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['cbr_displacement_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_md_force_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_md_elongation_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_cd_force_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_cd_elongation_cv'] ?? '-'); ?></td>
      </tr>
      <tr>
        <td><strong>Maximum</strong></td>
        <td><?php echo htmlspecialchars($test_data['summary']['gsm_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['thickness_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_md_strength_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_md_elongation_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_cd_strength_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_cd_elongation_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['cbr_force_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['cbr_displacement_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_md_force_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_md_elongation_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_cd_force_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_cd_elongation_max'] ?? '-'); ?></td>
      </tr>
      <tr>
        <td><strong>Minimum</strong></td>
        <td><?php echo htmlspecialchars($test_data['summary']['gsm_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['thickness_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_md_strength_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_md_elongation_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_cd_strength_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['strip_cd_elongation_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['cbr_force_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['cbr_displacement_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_md_force_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_md_elongation_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_cd_force_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['summary']['grab_cd_elongation_min'] ?? '-'); ?></td>
      </tr>
    </tbody>
  </table>
  <?php endif; ?>
  
  <?php else: ?>
  <p>No test data available.</p>
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


