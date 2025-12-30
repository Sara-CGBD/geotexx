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

$message = '';
$error = '';

// Handle form submission (approve/reject)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $checker_name = $_SESSION['full_name'] ?? $_SESSION['username'];
    
    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE characteristics_tests SET status = 'checked', checker_name = ?, checked_at = NOW() WHERE id = ? AND status = 'pending'");
        $stmt->bind_param("si", $checker_name, $report_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "✅ Test approved and forwarded to AGM!";
            $stmt->close();
            // Prevent caching
            header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
            header("Cache-Control: post-check=0, pre-check=0", false);
            header("Pragma: no-cache");
            // Refresh parent window and close this one if opened from parent
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Approved</title><script>
                if (window.opener && !window.opener.closed) {
                    // Send message to parent window to trigger refresh
                    try {
                        window.opener.postMessage({ type: "report_processed", action: "approved", report_type: "characteristics", report_id: ' . $report_id . ' }, window.location.origin);
                    } catch(e) {}
                    // Also refresh parent directly as fallback
                    window.opener.location.href = window.opener.location.href.split("?")[0] + "?t=" + new Date().getTime();
                    setTimeout(function() { window.close(); }, 100);
                } else {
                    window.location.href = "../forms/characteristics_test.php?t=" + new Date().getTime();
                }
            </script></head><body><p>Approved! Closing window...</p></body></html>';
            exit();
        }
        $stmt->close();
    } elseif ($action === 'reject') {
        $reject_reason = trim($_POST['reject_reason'] ?? '');
        
        // Handle rejection reasons checkboxes
        if (isset($_POST['rejection_reasons']) && is_array($_POST['rejection_reasons'])) {
            $rejection_reasons = array_map('trim', $_POST['rejection_reasons']);
            $reasons_text = "Rejection Reasons: " . implode(', ', $rejection_reasons);
            // Append additional comments if provided
            if ($reject_reason) {
                $reject_reason = $reasons_text . "\n\nAdditional Comments: " . $reject_reason;
            } else {
                $reject_reason = $reasons_text;
            }
        }
        
        $stmt = $conn->prepare("UPDATE characteristics_tests SET status = 'rejected', remarks = ?, checker_name = ? WHERE id = ? AND status = 'pending'");
        $stmt->bind_param("ssi", $reject_reason, $checker_name, $report_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "❌ Test rejected and returned to tester!";
            $stmt->close();
            // Prevent caching
            header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
            header("Cache-Control: post-check=0, pre-check=0", false);
            header("Pragma: no-cache");
            // Refresh parent window and close this one if opened from parent
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Rejected</title><script>
                if (window.opener && !window.opener.closed) {
                    // Send message to parent window to trigger refresh
                    try {
                        window.opener.postMessage({ type: "report_processed", action: "rejected", report_type: "characteristics", report_id: ' . $report_id . ' }, window.location.origin);
                    } catch(e) {}
                    // Also refresh parent directly as fallback
                    window.opener.location.href = window.opener.location.href.split("?")[0] + "?t=" + new Date().getTime();
                    setTimeout(function() { window.close(); }, 100);
                } else {
                    window.location.href = "../forms/characteristics_test.php?t=" + new Date().getTime();
                }
            </script></head><body><p>Rejected! Closing window...</p></body></html>';
            exit();
        }
        $stmt->close();
    }
}

// Fetch the report
$stmt = $conn->prepare("SELECT * FROM characteristics_tests WHERE id = ?");
$stmt->bind_param("i", $report_id);
$stmt->execute();
$result = $stmt->get_result();
$report = $result->fetch_assoc();
$stmt->close();

if (!$report) {
    die('Report not found');
}

// Decode test data JSON
$test_data = json_decode($report['test_results'], true);
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Check Characteristics Test - <?php echo htmlspecialchars($report['report_number']); ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:5px 10px 5px 5px; color:#2c3e50; }
    .container { max-width:100%; margin:0; margin-left:0; background:#fff; border-radius:8px; padding:15px 25px 15px 10px; box-shadow:0 2px 10px rgba(0,0,0,0.08);} 
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
    .status-pending { background:#fff3cd; color:#856404; }
    .status-checked { background:#d4edda; color:#155724; }
    .status-approved { background:#d1ecf1; color:#0c5460; }
    .status-rejected { background:#f8d7da; color:#721c24; }
    .btn { padding:10px 20px; border:none; border-radius:6px; cursor:pointer; text-decoration:none; display:inline-block; margin:10px 5px 0 0; font-size:14px; }
    .btn-back { background:#6c757d; color:#fff; }
    .btn-approve { background:#28a745; color:#fff; }
    .btn-reject { background:#dc3545; color:#fff; }
    .table-container { overflow-x: auto; border: 1px solid #ddd; border-radius: 8px; margin-bottom:20px; }
    textarea { width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; font-family:inherit; margin-top:10px; }
    .alert { padding:12px; border-radius:6px; margin-bottom:15px; }
    .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
  </style>
</head>
<body>
<div class="container">
  <h1>🔬 Check Characteristics Test Report (ISO 12956)</h1>
  
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
      <div class="info-label">Lab Test Number</div>
      <div class="info-value"><?php echo htmlspecialchars($report['lab_test_number']); ?></div>
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

  <h3>Test Information</h3>
  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Test Materials</div>
      <div class="info-value"><?php echo htmlspecialchars($report['test_materials']); ?></div>
    </div>
    <?php if (!empty($report['reference_number'])): ?>
    <div class="info-item">
      <div class="info-label">Reference Number</div>
      <div class="info-value"><?php echo htmlspecialchars($report['reference_number']); ?></div>
    </div>
    <?php endif; ?>
    <div class="info-item">
      <div class="info-label">Sample ID</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_id']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Specimen Size</div>
      <div class="info-value"><?php echo rtrim(rtrim(number_format($report['specimen_size'], 4), '0'), '.') . ' ' . htmlspecialchars($report['specimen_size_unit']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Sand Type</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sand_type']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Total Sand Weight</div>
      <div class="info-value"><?php echo rtrim(rtrim(number_format($report['sand_weight'], 4), '0'), '.') . ' ' . htmlspecialchars($report['sand_weight_unit']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Sieving Time</div>
      <div class="info-value"><?php echo rtrim(rtrim(number_format($report['sieving_time'], 4), '0'), '.') . ' ' . htmlspecialchars($report['sieving_time_unit']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Sample Received</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_received']); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Approved By</div>
      <div class="info-value"><?php echo htmlspecialchars($report['approver_name'] ?? 'N/A'); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Sample Tested</div>
      <div class="info-value"><?php echo htmlspecialchars($report['sample_tested']); ?></div>
    </div>
  </div>

  <?php if (!empty($test_data) && isset($test_data['sieve_data'])): ?>
  
  <h3>Sieve Analysis Data</h3>
  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>Sieve Size (mm)</th>
          <th>Retained (g)</th>
          <th>Cumulative (g)</th>
          <th>Cumulative Passing (%)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($test_data['sieve_data'] as $row): ?>
        <tr>
          <td><?php echo rtrim(rtrim(number_format($row['sieve_size'], 4), '0'), '.'); ?></td>
          <td><?php echo rtrim(rtrim(number_format($row['retained'], 4), '0'), '.'); ?></td>
          <td><?php echo rtrim(rtrim(number_format($row['cumulative'], 4), '0'), '.'); ?></td>
          <td><?php echo rtrim(rtrim(number_format($row['passing'], 2), '0'), '.'); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h3>Opening Size Calculation (AOS)</h3>
  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">O-Value (%)</div>
      <div class="info-value"><?php echo rtrim(rtrim(number_format($test_data['o_value'], 1), '0'), '.'); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Apparent Opening Size (AOS)</div>
      <div class="info-value"><?php echo rtrim(rtrim(number_format($test_data['opening_size'], 4), '0'), '.'); ?> mm (<?php echo rtrim(rtrim(number_format($test_data['opening_size'] * 1000, 1), '0'), '.'); ?> μm)</div>
    </div>
  </div>

  <?php if (!empty($test_data['remarks'])): ?>
  <h3>Remarks</h3>
  <div style="background:#f8f9fa; padding:15px; border-radius:6px;">
    <?php echo nl2br(htmlspecialchars($test_data['remarks'])); ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>

  <?php if ($report['status'] === 'pending'): ?>
  <h3>Checker Actions</h3>
  <form method="POST" action="" style="margin-bottom:15px;">
    <input type="hidden" name="action" value="approve">
    <button type="submit" class="btn btn-approve">✓ Approve & Forward to AGM</button>
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
        alert('❌ Please select at least one reason for rejection!');
        return false;
      }
      
      return confirm('Are you sure you want to reject this test?');
    }
  </script>
  <?php else: ?>
  <div style="background:#d4edda; padding:15px; border-radius:6px; margin-top:20px;">
    <strong>This test has already been processed (Status: <?php echo strtoupper($report['status']); ?>)</strong>
  </div>
  <?php endif; ?>

  <div style="margin-top:20px;">
    <a href="../forms/characteristics_test.php" class="btn btn-back">← Back to Dashboard</a>
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


