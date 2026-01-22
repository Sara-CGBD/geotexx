<?php
session_start();
require_once '../forms/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit();
}

$role = strtolower(trim($_SESSION['role'] ?? 'user'));
if (!in_array($role, ['admin', 'agm ops', 'agm operations', 'management'])) {
    die('Access denied. Only admins can access this page.');
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
    $admin_name = $_SESSION['full_name'] ?? $_SESSION['username'];
    
    // Get bundle_reference for this report to find all reports in the same range
    $getBundleStmt = $conn->prepare("SELECT bundle_reference FROM water_permeability_tests WHERE id = ?");
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
        $whereClause = "bundle_reference = ? AND status IN ('pending', 'checked')";
        $params = [$bundle_reference];
        $types = "s";
    } else {
        // Single report - update only this one
        $whereClause = "id = ? AND status IN ('pending', 'checked')";
        $params = [$report_id];
        $types = "i";
    }
    
    if ($action === 'approve') {
        // Check if roll_destination column exists and add if needed
        $checkRollDest = $conn->query("SHOW COLUMNS FROM water_permeability_tests LIKE 'roll_destination'");
        if ($checkRollDest && $checkRollDest->num_rows === 0) {
            @$conn->query("ALTER TABLE water_permeability_tests ADD COLUMN roll_destination VARCHAR(255) NULL");
        }
        
        // Get routing destination if provided
        $roll_destination = $_POST['roll_destination'] ?? '';
        
        // Update query with or without routing
        if (!empty($roll_destination)) {
            $stmt = $conn->prepare("UPDATE water_permeability_tests SET status = 'approved', approved_by = ?, approved_at = NOW(), roll_destination = ? WHERE $whereClause");
            if ($types === "s") {
                $stmt->bind_param("sss", $admin_name, $roll_destination, $params[0]);
            } else {
                $stmt->bind_param("ssi", $admin_name, $roll_destination, $params[0]);
            }
        } else {
            $stmt = $conn->prepare("UPDATE water_permeability_tests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE $whereClause");
            if ($types === "s") {
                $stmt->bind_param("ss", $admin_name, $params[0]);
            } else {
                $stmt->bind_param("si", $admin_name, $params[0]);
            }
        }
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Test approved successfully! The approved test will appear in the routing section of the QC Test Approval Dashboard where you can set the routing destination.";
            $stmt->close();
            // Prevent caching
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
            
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Approved</title></head><body><script>
                var returnUrl = ' . json_encode($base_url) . ';
                if (window.opener && !window.opener.closed) {
                    window.opener.location.href = returnUrl + "?t=" + new Date().getTime();
                    setTimeout(function() { window.close(); }, 100);
                } else {
                    window.location.href = ' . json_encode($relative_url) . ' + "?t=" + new Date().getTime();
                }
            </script><p>Approved! Redirecting...</p></body></html>';
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
        
        // Use the same where clause logic for rejection
        $stmt = $conn->prepare("UPDATE water_permeability_tests SET status = 'rejected', remarks = ?, approved_by = ? WHERE $whereClause");
        if ($types === "s") {
            $stmt->bind_param("sss", $reject_reason, $admin_name, $params[0]);
        } else {
            $stmt->bind_param("ssi", $reject_reason, $admin_name, $params[0]);
        }
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Test rejected and returned to tester!";
            $stmt->close();
            // Prevent caching
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
            
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Rejected</title></head><body><script>
                var returnUrl = ' . json_encode($base_url) . ';
                if (window.opener && !window.opener.closed) {
                    window.opener.location.href = returnUrl + "?t=" + new Date().getTime();
                    setTimeout(function() { window.close(); }, 100);
                } else {
                    window.location.href = ' . json_encode($relative_url) . ' + "?t=" + new Date().getTime();
                }
            </script><p>Rejected! Redirecting...</p></body></html>';
            exit();
        }
        $stmt->close();
    }
}

// Fetch the report
$stmt = $conn->prepare("SELECT * FROM water_permeability_tests WHERE id = ?");
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
  <title>View Water Permeability Test - <?php echo htmlspecialchars($report['report_number']); ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
  <h1>💧 Water Permeability Test Report - Admin Review</h1>
  
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
      <div class="info-label">Checked By</div>
      <div class="info-value"><?php echo htmlspecialchars($report['checked_by'] ?? 'N/A'); ?></div>
    </div>
    <div class="info-item">
      <div class="info-label">Submitted At</div>
      <div class="info-value"><?php echo htmlspecialchars($report['created_at']); ?></div>
    </div>
  </div>

  <h3>Test Information</h3>
  <div class="info-grid">
    <?php if (!empty($report['reference_number'])): ?>
    <div class="info-item">
      <div class="info-label">Reference Number</div>
      <div class="info-value"><?php echo htmlspecialchars($report['reference_number']); ?></div>
    </div>
    <?php endif; ?>
    <div class="info-item">
      <div class="info-label">Test Date</div>
      <div class="info-value"><?php echo htmlspecialchars($report['test_date']); ?></div>
    </div>
  </div>

  <?php if (!empty($test_data) && isset($test_data['experimental_data'])): ?>
  
  <h3>Experimental Data</h3>
  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>No</th>
          <th>h₀ (m)</th>
          <th>t₁ (s)</th>
          <th>h₁ (m)</th>
          <th>t₂ (s)</th>
          <th>Thickness (mm)</th>
          <th>Temp (°C)</th>
          <th>Correction</th>
          <th>Δh (m)</th>
          <th>Time (s)</th>
          <th>Velocity (m/s)</th>
          <th>Permeability (×10⁻³ m/s)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($test_data['experimental_data'] as $row): ?>
        <tr>
          <td><?php echo $row['no']; ?></td>
          <td><?php echo number_format($row['h0'], 4); ?></td>
          <td><?php echo number_format($row['t1'], 1); ?></td>
          <td><?php echo number_format($row['h1'], 4); ?></td>
          <td><?php echo number_format($row['t2'], 1); ?></td>
          <td><?php echo number_format($row['thickness'], 2); ?></td>
          <td><?php echo number_format($row['temp'], 1); ?></td>
          <td><?php echo number_format($row['correction'], 4); ?></td>
          <td><?php echo number_format($row['head_diff'], 4); ?></td>
          <td><?php echo number_format($row['time'], 1); ?></td>
          <td><?php echo number_format($row['velocity'], 4); ?></td>
          <td><?php echo number_format($row['permeability'], 3); ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <h3>Summary Results</h3>
  <div class="info-grid">
    <div class="info-item">
      <div class="info-label">Average Permeability</div>
      <div class="info-value"><?php echo number_format($test_data['avg_permeability'], 3); ?> × 10⁻³ m/s</div>
    </div>
    <div class="info-item">
      <div class="info-label">Average Velocity</div>
      <div class="info-value"><?php echo number_format($test_data['avg_velocity'], 3); ?> m/s</div>
    </div>
  </div>

  <?php if (!empty($test_data['remarks'])): ?>
  <h3>Remarks</h3>
  <div style="background:#f8f9fa; padding:15px; border-radius:6px;">
    <?php echo nl2br(htmlspecialchars($test_data['remarks'])); ?>
  </div>
  <?php endif; ?>

  <?php endif; ?>

  <?php if (in_array($role, ['admin', 'agm ops', 'agm operations', 'management']) && in_array($report['status'], ['pending', 'checked'])): ?>
  <h3>Admin Actions</h3>
  
  <!-- Approval Form -->
  <form method="POST" action="" style="margin-bottom:20px; padding:20px; background:#f8f9fa; border-radius:8px; border:1px solid #ddd;">
    <input type="hidden" name="action" value="approve">
    <p style="margin:0 0 15px 0; padding:12px; background:#e8f6ec; border-left:4px solid #28a745; border-radius:4px; color:#065f46; font-size:14px;">
      <i class="fas fa-info-circle"></i> <strong>Note:</strong> After approval, this test will appear in the "Approved Tests with Routing" section of the QC Test Approval Dashboard where you can set the routing destination (FG or Bag Production).
    </p>
    <button type="submit" class="btn btn-approve">✓ Final Approve</button>
  </form>
  
  <!-- Rejection Form -->
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
          <input type="checkbox" name="rejection_reasons[]" value="Incorrect Test Data Entry" style="margin-right:8px;">
          Incorrect Test Data Entry
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="rejection_reasons[]" value="Test Results Do Not Meet Standards" style="margin-right:8px;">
          Test Results Do Not Meet Standards
        </label>
      </div>
      <div style="margin-bottom:8px;">
        <label style="font-weight:normal; display:block;">
          <input type="checkbox" name="rejection_reasons[]" value="Missing Required Information" style="margin-right:8px;">
          Missing Required Information
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
  <?php else: ?>
  <div style="background:#d4edda; padding:15px; border-radius:6px; margin-top:20px;">
    <strong>This test has already been processed (Status: <?php echo strtoupper($report['status']); ?>)</strong>
    <?php if ($report['status'] === 'approved'): ?>
    <p>This test was approved by <?php echo htmlspecialchars($report['approved_by'] ?? 'Admin'); ?> on <?php echo $report['approved_at']; ?></p>
    <?php endif; ?>
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


