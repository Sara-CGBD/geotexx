<?php
session_start();
require_once '../forms/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit();
}

$role = strtolower(trim($_SESSION['role'] ?? 'user'));
if (!in_array($role, ['checker'])) {
    die('Access denied. Only checkers can access this page.');
}

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

// Decode test data JSON
$test_data = json_decode($report['test_data'], true);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Check Fabric Pre-Production Test - <?php echo htmlspecialchars($report['report_number']); ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:20px; color:#2c3e50; }
    .container { max-width:1600px; margin:auto; background:#fff; border-radius:12px; padding:24px; box-shadow:0 4px 20px rgba(0,0,0,0.08);} 
    h1 { margin:0 0 20px 0; color:#2c3e50; border-bottom:2px solid#3498db; padding-bottom:10px; }
    h3 { margin:20px 0 10px 0; color:#2c3e50; border-bottom:1px solid #ddd; padding-bottom:5px; }
    .info-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:15px; margin-bottom:20px; }
    .info-item { background:#f8f9fa; padding:12px; border-radius:6px; border-left:3px solid #3498db; }
    .info-label { font-weight:600; color:#6c757d; font-size:12px; text-transform:uppercase; margin-bottom:4px; }
    .info-value { font-size:14px; color:#2c3e50; }
    table { width:100%; border-collapse:collapse; margin-top:15px; font-size:11px; }
    th, td { border:1px solid #ddd; padding:6px; text-align:center; }
    th { background:#3498db; color:#fff; font-weight:600; }
    .status-badge { padding:4px 12px; border-radius:12px; font-size:12px; font-weight:600; display:inline-block; }
    .status-pending_checker { background:#fff3cd; color:#856404; }
    .btn { padding:10px 20px; border:none; border-radius:6px; cursor:pointer; text-decoration:none; display:inline-block; margin:10px 5px 0 0; font-size:14px; }
    .btn-back { background:#6c757d; color:#fff; }
    .btn-approve { background:#28a745; color:#fff; }
    .btn-reject { background:#dc3545; color:#fff; }
    .table-container { overflow-x: auto; border: 1px solid #ddd; border-radius: 8px; margin-bottom:20px; }
    textarea { width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-family:inherit; margin-top:10px; }
  </style>
</head>
<body>
<div class="container">
  <h1>Check Fabric Pre-Production Test Report</h1>
  
  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Report Number</div>
      <div class="info-value"><?php echo htmlspecialchars($report['report_number']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Status</div>
      <div class="info-value">
        <span class="status-badge status-<?php echo htmlspecialchars($report['status']); ?>">
          <?php echo strtoupper(str_replace('_', ' ', htmlspecialchars($report['status']))); ?>
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
      <div class="info-label">Customer Reference</div>
      <div class="info-value"><?php echo htmlspecialchars($report['customer_reference']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">GSM</div>
      <div class="info-value"><?php echo htmlspecialchars($report['gsm']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Line No</div>
      <div class="info-value"><?php echo htmlspecialchars($report['line_no']); ?></div>
    </div>
  </div>

  <?php if (!empty($test_data)): ?>
  
  <h3>Test Raw Data</h3>
  <div class="table-container">
  <table style="font-size:10px;">
    <thead>
      <tr>
        <th colspan="4" style="background:#1976d2; color:#fff;">GSM Test</th>
        <th colspan="2" style="background:#7b1fa2; color:#fff;">Thickness Test</th>
        <th colspan="4" style="background:#388e3c; color:#fff;">Strip Tensile Test</th>
        <th colspan="2" style="background:#f57c00; color:#fff;">CBR Test</th>
        <th colspan="3" style="background:#c2185b; color:#fff;">Grab Tensile Test</th>
      </tr>
      <tr>
        <th style="background:#1976d2; color:#fff;">Position</th>
        <th style="background:#1976d2; color:#fff;">Weight(gm)</th>
        <th style="background:#1976d2; color:#fff;">Calc(GSM)</th>
        <th style="background:#1976d2; color:#fff;">Avg(GSM)</th>
        <th style="background:#7b1fa2; color:#fff;">2KPa(mm)</th>
        <th style="background:#7b1fa2; color:#fff;">Avg(mm)</th>
        <th style="background:#388e3c; color:#fff;">Dir</th>
        <th style="background:#388e3c; color:#fff;">Str(kN/m)</th>
        <th style="background:#388e3c; color:#fff;">Ratio</th>
        <th style="background:#388e3c; color:#fff;">Elong(%)</th>
        <th style="background:#f57c00; color:#fff;">Force(N)</th>
        <th style="background:#f57c00; color:#fff;">Disp(mm)</th>
        <th style="background:#c2185b; color:#fff;">Dir</th>
        <th style="background:#c2185b; color:#fff;">Force(N)</th>
        <th style="background:#c2185b; color:#fff;">Elong(%)</th>
      </tr>
    </thead>
    <tbody>
      <?php
      // Display raw data rows
      $positions = $test_data['position'] ?? [];
      $row_count = count($positions);
      for ($i = 0; $i < $row_count; $i++):
      ?>
      <tr>
        <td><?php echo htmlspecialchars($test_data['position'][$i] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['gsm_weight'][$i] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['gsm_calculated'][$i] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['gsm_average'][$i] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['thickness'][$i] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['thickness_average'][$i] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['strip_direction'][$i] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['strip_strength'][$i] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['strip_ratio'][$i] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['strip_elongation'][$i] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['cbr_force'][$i] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['cbr_displacement'][$i] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['grab_direction'][$i] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['grab_force'][$i] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($test_data['grab_elongation'][$i] ?? '-'); ?></td>
      </tr>
      <?php endfor; ?>
    </tbody>
  </table>
  </div>

  <?php if (isset($test_data['summary'])): ?>
  <?php $summary = $test_data['summary']; ?>
  
  <h3 style="margin-top:30px;">Summary of Test Results</h3>
  <div class="table-container">
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
        <td><?php echo htmlspecialchars($summary['gsm_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['thickness_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_md_strength_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_md_elongation_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_cd_strength_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_cd_elongation_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['cbr_force_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['cbr_displacement_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_md_force_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_md_elongation_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_cd_force_avg'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_cd_elongation_avg'] ?? '-'); ?></td>
      </tr>
      <tr>
        <td><strong>SD</strong></td>
        <td><?php echo htmlspecialchars($summary['gsm_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['thickness_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_md_strength_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_md_elongation_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_cd_strength_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_cd_elongation_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['cbr_force_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['cbr_displacement_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_md_force_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_md_elongation_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_cd_force_sd'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_cd_elongation_sd'] ?? '-'); ?></td>
      </tr>
      <tr>
        <td><strong>CV%</strong></td>
        <td><?php echo htmlspecialchars($summary['gsm_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['thickness_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_md_strength_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_md_elongation_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_cd_strength_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_cd_elongation_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['cbr_force_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['cbr_displacement_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_md_force_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_md_elongation_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_cd_force_cv'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_cd_elongation_cv'] ?? '-'); ?></td>
      </tr>
      <tr>
        <td><strong>Maximum</strong></td>
        <td><?php echo htmlspecialchars($summary['gsm_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['thickness_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_md_strength_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_md_elongation_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_cd_strength_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_cd_elongation_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['cbr_force_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['cbr_displacement_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_md_force_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_md_elongation_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_cd_force_max'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_cd_elongation_max'] ?? '-'); ?></td>
      </tr>
      <tr>
        <td><strong>Minimum</strong></td>
        <td><?php echo htmlspecialchars($summary['gsm_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['thickness_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_md_strength_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_md_elongation_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_cd_strength_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['strip_cd_elongation_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['cbr_force_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['cbr_displacement_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_md_force_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_md_elongation_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_cd_force_min'] ?? '-'); ?></td>
        <td><?php echo htmlspecialchars($summary['grab_cd_elongation_min'] ?? '-'); ?></td>
      </tr>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  
  <?php endif; ?>

  <h3>Checker Actions</h3>
  <form method="POST" action="../forms/fabric_pre_production_test.php" style="margin-bottom:10px;" id="checkerForm">
    <input type="hidden" name="checker_report_number" value="<?php echo htmlspecialchars($report['report_number']); ?>">
    
    <div id="rejectionReasons" style="display:none; margin:15px 0; padding:15px; background:#fff3cd; border:1px solid #ffc107; border-radius:6px;">
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
    </div>
    
    <label for="checker_comment">Additional Comments (Optional):</label>
    <textarea name="checker_comment" id="checker_comment" rows="3" placeholder="Add any additional comments..."></textarea>
    <div>
      <button type="submit" name="checker_action" value="approved" class="btn btn-approve" onclick="return handleApprove()">✓ Approve & Forward</button>
      <button type="submit" name="checker_action" value="rejected" class="btn btn-reject" onclick="return handleReject()">✗ Reject</button>
      <a href="../forms/fabric_pre_production_test.php" style="background:#6c757d; color:#fff; text-decoration:none; padding:10px 20px; border-radius:6px; display:inline-block; margin:10px 5px 0 0; font-size:14px;">← Back</a>
    </div>
  </form>

  <script>
    function handleApprove() {
      document.getElementById('rejectionReasons').style.display = 'none';
      return confirm('Forward this report to approver?');
    }

    function handleReject() {
      const reasonsDiv = document.getElementById('rejectionReasons');
      reasonsDiv.style.display = 'block';
      
      const checkboxes = document.querySelectorAll('input[name="rejection_reasons[]"]');
      const checked = Array.from(checkboxes).filter(cb => cb.checked);
      
      if (checked.length === 0) {
        alert('❌ Please select at least one reason for rejection!');
        return false;
      }
      
      const comment = document.getElementById('checker_comment').value.trim();
      const reasons = checked.map(cb => cb.value).join(', ');
      return confirm('Reject this report?\n\nReasons: ' + reasons + (comment ? '\nComments: ' + comment : ''));
    }

  </script>

</div>
</body>
</html>


