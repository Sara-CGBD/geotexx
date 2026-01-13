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
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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

  <div style="margin-top:20px; display:flex; flex-direction:column; gap:10px; align-items:center;">
    <button onclick="closeWindow()" class="btn btn-back" style="padding:8px 16px;">Close Window</button>
    
    <?php 
    $isApprovalDashboard = isset($_GET['approval_dashboard']) && $_GET['approval_dashboard'] == '1';
    $reportNumber = isset($_GET['report_number']) ? $_GET['report_number'] : $report['report_number'];
    if ($isApprovalDashboard && $report['status'] === 'pending'): 
    ?>
    <div style="display:flex; gap:10px; margin-top:10px;">
      <button onclick="approveReport('<?php echo htmlspecialchars($reportNumber); ?>')" style="background:#27ae60; color:#fff; padding:10px 20px; border:none; border-radius:6px; cursor:pointer; font-size:14px; font-weight:bold;">
        <i class="fas fa-check"></i> Approve
      </button>
      <button onclick="rejectReport('<?php echo htmlspecialchars($reportNumber); ?>')" style="background:#e74c3c; color:#fff; padding:10px 20px; border:none; border-radius:6px; cursor:pointer; font-size:14px; font-weight:bold;">
        <i class="fas fa-times"></i> Reject
      </button>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($isApprovalDashboard && $report['status'] === 'pending'): ?>
<!-- Sewing Thread Test Rejection Modal -->
<div id="sewingRejectModal" class="modal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); overflow-y:auto;">
    <div class="modal-content" style="background:#fff; margin:2% auto; padding:20px; border-radius:12px; width:90%; max-width:500px; max-height:90vh; display:flex; flex-direction:column; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
        <span class="close" onclick="closeSewingRejectModal()" style="float:right; font-size:24px; font-weight:bold; cursor:pointer; color:#aaa; line-height:1;">&times;</span>
        <div class="modal-header" style="font-size:18px; font-weight:600; margin-bottom:12px; color:#e74c3c; flex-shrink:0;">
            <i class="fas fa-exclamation-triangle"></i> Reject Sewing Thread Test
        </div>
        
        <form method="POST" id="sewingRejectForm" onsubmit="return submitSewingRejection(event)">
            <input type="hidden" name="sewing_report_number" id="sewing_report_number_input">
            <input type="hidden" name="sewing_action" value="rejected">
            
            <div class="modal-body" style="flex:1; overflow-y:auto; padding-right:5px;">
                <div class="checkbox-group" style="margin:10px 0; max-height:200px; overflow-y:auto; padding:5px; border:1px solid #e0e0e0; border-radius:6px;">
                    <strong style="display:block; margin-bottom:8px; font-size:13px;">Rejection Reasons:</strong>
                    <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                        <input type="checkbox" name="sewing_rejection_reasons[]" value="Sample quality issue" id="sewing_reason1" style="transform:scale(1.1); cursor:pointer;">
                        <label for="sewing_reason1" style="cursor:pointer; flex:1; font-size:12px;">Sample quality issue</label>
                    </div>
                    <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                        <input type="checkbox" name="sewing_rejection_reasons[]" value="Test method incorrect" id="sewing_reason2" style="transform:scale(1.1); cursor:pointer;">
                        <label for="sewing_reason2" style="cursor:pointer; flex:1; font-size:12px;">Test method incorrect</label>
                    </div>
                    <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                        <input type="checkbox" name="sewing_rejection_reasons[]" value="Results out of range" id="sewing_reason3" style="transform:scale(1.1); cursor:pointer;">
                        <label for="sewing_reason3" style="cursor:pointer; flex:1; font-size:12px;">Results out of range</label>
                    </div>
                    <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                        <input type="checkbox" name="sewing_rejection_reasons[]" value="Missing information" id="sewing_reason4" style="transform:scale(1.1); cursor:pointer;">
                        <label for="sewing_reason4" style="cursor:pointer; flex:1; font-size:12px;">Missing information</label>
                    </div>
                    <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                        <input type="checkbox" name="sewing_rejection_reasons[]" value="Calibration needed" id="sewing_reason5" style="transform:scale(1.1); cursor:pointer;">
                        <label for="sewing_reason5" style="cursor:pointer; flex:1; font-size:12px;">Calibration needed</label>
                    </div>
                    <div class="checkbox-item" style="display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa;">
                        <input type="checkbox" name="sewing_rejection_reasons[]" value="Data entry error" id="sewing_reason6" style="transform:scale(1.1); cursor:pointer;">
                        <label for="sewing_reason6" style="cursor:pointer; flex:1; font-size:12px;">Data entry error</label>
                    </div>
                </div>
                
                <textarea name="sewing_remarks" class="remarks-box" placeholder="Additional comments (optional)" style="width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; margin-top:10px; min-height:80px;"></textarea>
            </div>
            
            <div class="modal-footer" style="flex-shrink:0; margin-top:15px; padding-top:15px; border-top:1px solid #ddd; text-align:right;">
                <button type="button" onclick="closeSewingRejectModal()" style="padding:8px 18px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer; margin-right:8px; font-size:13px;">
                    Cancel
                </button>
                <button type="submit" style="padding:8px 18px; background:#e74c3c; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; font-size:13px;">
                    <i class="fas fa-ban"></i> Reject Report
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function submitSewingRejection(e) {
    e.preventDefault();
    const checkboxes = document.querySelectorAll('input[name="sewing_rejection_reasons[]"]:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one rejection reason.');
        return false;
    }
    
    const form = e.target;
    const formData = new FormData(form);
    
    // Create form in current window and submit to dashboard
    const submitForm = document.createElement('form');
    submitForm.method = 'POST';
    submitForm.action = 'raw_material_test_approval_dashboard.php';
    submitForm.style.display = 'none';
    
    // Add action and report number
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden';
    actionInput.name = 'sewing_action';
    actionInput.value = 'rejected';
    submitForm.appendChild(actionInput);
    
    const reportInput = document.createElement('input');
    reportInput.type = 'hidden';
    reportInput.name = 'sewing_report_number';
    reportInput.value = document.getElementById('sewing_report_number_input').value;
    submitForm.appendChild(reportInput);
    
    // Add rejection reasons
    checkboxes.forEach(function(checkbox) {
        const reasonInput = document.createElement('input');
        reasonInput.type = 'hidden';
        reasonInput.name = 'sewing_rejection_reasons[]';
        reasonInput.value = checkbox.value;
        submitForm.appendChild(reasonInput);
    });
    
    // Add remarks if any
    const remarksField = form.querySelector('textarea[name="sewing_remarks"]');
    if (remarksField && remarksField.value.trim()) {
        const remarksInput = document.createElement('input');
        remarksInput.type = 'hidden';
        remarksInput.name = 'sewing_remarks';
        remarksInput.value = remarksField.value.trim();
        submitForm.appendChild(remarksInput);
    }
    
    document.body.appendChild(submitForm);
    submitForm.submit();
    
    // Refresh parent window after a short delay
    if (window.opener && !window.opener.closed) {
        setTimeout(function() {
            window.opener.location.reload();
            window.close();
        }, 500);
    }
    
    return false;
}
</script>
<?php endif; ?>

<script>
function closeWindow() {
    // Check if opened from approval dashboard
    const urlParams = new URLSearchParams(window.location.search);
    const isApprovalDashboard = urlParams.get('approval_dashboard') === '1';
    
    if (window.opener) {
        window.close();
    } else {
        // If opened from approval dashboard, redirect there
        if (isApprovalDashboard) {
            window.location.href = 'raw_material_test_approval_dashboard.php';
        } else if (window.history.length > 1) {
            window.history.back();
        } else {
            window.location.href = '../index.php';
        }
    }
}

<?php if ($isApprovalDashboard && $report['status'] === 'pending'): ?>
function approveReport(reportNumber) {
    if (confirm('Approve Sewing Thread Report ' + reportNumber + '?')) {
        // Create form in current window and submit to dashboard
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'raw_material_test_approval_dashboard.php';
        form.style.display = 'none';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'sewing_action';
        actionInput.value = 'approved';
        form.appendChild(actionInput);
        
        const reportInput = document.createElement('input');
        reportInput.type = 'hidden';
        reportInput.name = 'sewing_report_number';
        reportInput.value = reportNumber;
        form.appendChild(reportInput);
        
        document.body.appendChild(form);
        
        // Submit form and then refresh parent window
        form.submit();
        
        // Refresh parent window after a short delay
        if (window.opener && !window.opener.closed) {
            setTimeout(function() {
                window.opener.location.reload();
                window.close();
            }, 500);
        }
    }
}

function rejectReport(reportNumber) {
    document.getElementById('sewing_report_number_input').value = reportNumber;
    document.getElementById('sewingRejectModal').style.display = 'block';
}

function closeSewingRejectModal() {
    document.getElementById('sewingRejectModal').style.display = 'none';
    document.getElementById('sewingRejectForm').reset();
}
<?php endif; ?>

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeWindow();
    }
});
</script>
</body>
</html>


