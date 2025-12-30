<?php
session_start();
require_once 'security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$role = strtolower(trim($_SESSION['role'] ?? ''));
$user_id = $_SESSION['user_id'];

$conn = SecurityConfig::getConnection();
$report_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($report_id <= 0) {
    die('Invalid report ID');
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

// Only allow tester to edit their own rejected reports
if ($role !== 'tester' || $report['reporter_id'] != $user_id || $report['status'] !== 'rejected') {
    die('Access denied. You can only edit your own rejected reports.');
}

// Decode test results
$test_results = json_decode($report['test_results'], true);

// Handle form submission to update the report
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_report'])) {
    try {
        // Prepare test results data (5 specimens)
        $test_results_data = [];
        for ($i = 1; $i <= 10; $i++) {
            $test_results_data[] = [
                'specimen_no' => $i,
                'test_direction' => $_POST["test_direction_$i"] ?? '',
                'breaking_force_after' => $_POST["breaking_force_after_$i"] ?? '',
                'breaking_force_before' => $_POST["breaking_force_before_$i"] ?? '',
                'force_retain' => $_POST["force_retain_$i"] ?? '',
                'elongation_after' => $_POST["elongation_after_$i"] ?? '',
                'elongation_before' => $_POST["elongation_before_$i"] ?? ''
            ];
        }
        $test_results_json = json_encode($test_results_data);
        
        // Update the report and reset status to pending
        $updateStmt = $conn->prepare("
            UPDATE weathering_exposure_reports 
            SET sample_collected_from = ?, reference = ?,
                sample_description = ?, recipe = ?, received_date = ?,
                test_start_date = ?, test_end_date = ?, test_speed = ?, gauge_length = ?,
                specimen_size = ?, note = ?, temperature = ?, rh_percent = ?,
                test_results = ?, status = 'pending', approved_by = NULL, remarks = NULL
            WHERE id = ?
        ");
        
        $updateStmt->bind_param(
            "sssssssssssddsi",
            $_POST['sample_collected_from'],
            $_POST['reference'],
            $_POST['sample_description'],
            $_POST['recipe'],
            $_POST['received_date'],
            $_POST['test_start_date'],
            $_POST['test_end_date'],
            $_POST['test_speed'],
            $_POST['gauge_length'],
            $_POST['specimen_size'],
            $_POST['note'],
            $_POST['temperature'],
            $_POST['rh_percent'],
            $test_results_json,
            $report_id
        );
        
        if ($updateStmt->execute()) {
            $_SESSION['update_success'] = "Report updated and resubmitted successfully! Status: Pending Approval";
            header("Location: weathering_exposure_test.php");
            exit();
        } else {
            $error = "Error updating report: " . $updateStmt->error;
        }
        $updateStmt->close();
        
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Load test results into variables for form pre-filling
$specimen_data = [];
foreach ($test_results as $tr) {
    $specimen_data[$tr['specimen_no']] = $tr;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Edit UV Test Report - <?php echo htmlspecialchars($report['report_number']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
  .container { max-width:1200px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);} 
  h1 { text-align:center; font-size:28px; margin-bottom:30px; color:#2c3e50; }
  .form-group { margin-bottom:15px; }
  label { font-weight:600; display:block; margin-bottom:5px; font-size:14px; }
  input[type="text"], input[type="number"], select, textarea { padding:8px; border:1px solid #ccc; border-radius:4px; width:calc(100% - 18px); font-size:14px; }
  .form-row { display:flex; gap:15px; margin-bottom:15px; }
  .form-col { flex:1; }
  .readonly { background:#ecf0f1; }
  .test-table { width:100%; border-collapse:collapse; margin-top:15px; font-size:12px; }
  .test-table th, .test-table td { border:1px solid #ddd; padding:6px; text-align:center; }
  .test-table th { background:#3498db; color:#fff; font-weight:600; }
  .btn { padding:10px 20px; border:none; border-radius:6px; cursor:pointer; margin:10px 5px 0 0; font-size:14px; text-decoration:none; display:inline-block; }
  .btn-submit { background:#28a745; color:#fff; }
  .btn-back { background:#6c757d; color:#fff; }
  .alert { padding:12px; border-radius:6px; margin-bottom:15px; }
  .alert-warning { background:#fff3cd; border:1px solid #ffc107; color:#856404; }
  .dir-btn { padding:6px 12px; border:1px solid #ccc; background:#fff; border-radius:4px; cursor:pointer; font-size:13px; margin:0 2px; font-weight:500; width:50px; height:32px; display:inline-flex; align-items:center; justify-content:center; }
  .dir-btn.active { background:#3498db; color:#fff; border-color:#3498db; }
</style>
</head>
<body>
<div class="container">
  <h1>Edit Rejected UV Test Report</h1>
  
  <div class="alert alert-warning">
    <strong>⚠️ Report Rejected:</strong> This report was rejected by <strong><?php echo htmlspecialchars($report['approved_by'] ?? 'Admin'); ?></strong><br>
    <strong>Reason:</strong> <?php echo htmlspecialchars($report['remarks'] ?? 'No comments provided'); ?><br>
    <strong>Original Report Number:</strong> <?php echo htmlspecialchars($report['report_number']); ?>
  </div>

  <?php if ($message): ?>
    <div class="alert" style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;">
      <?php echo $message; ?>
    </div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert" style="background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;">
      <?php echo $error; ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="">
    <input type="hidden" name="update_report" value="1">
    
    <div class="form-row">
      <div class="form-group">
        <label>Report Number:</label>
        <input type="text" value="<?php echo htmlspecialchars($report['report_number']); ?>" readonly class="readonly">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Sample Collected From:</label>
        <input type="text" name="sample_collected_from" value="<?php echo htmlspecialchars($report['sample_collected_from']); ?>" required>
      </div>
      <div class="form-group">
        <label>Reference:</label>
        <input type="text" name="reference" value="<?php echo htmlspecialchars($report['reference']); ?>" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Sample Description:</label>
        <input type="text" name="sample_description" value="<?php echo htmlspecialchars($report['sample_description']); ?>" required>
      </div>
      <div class="form-group">
        <label>Recipe:</label>
        <input type="text" name="recipe" value="<?php echo htmlspecialchars($report['recipe']); ?>" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Received Date:</label>
        <input type="datetime-local" name="received_date" value="<?php echo date('Y-m-d\TH:i', strtotime($report['received_date'])); ?>" required>
      </div>
      <div class="form-group">
        <label>Test Start Date:</label>
        <input type="date" name="test_start_date" value="<?php echo htmlspecialchars($report['test_start_date']); ?>" required>
      </div>
      <div class="form-group">
        <label>Test End Date:</label>
        <input type="date" name="test_end_date" value="<?php echo htmlspecialchars($report['test_end_date']); ?>" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Test Speed (mm/min):</label>
        <input type="text" name="test_speed" value="<?php echo htmlspecialchars($report['test_speed']); ?>" required>
      </div>
      <div class="form-group">
        <label>Gauge Length (mm):</label>
        <input type="text" name="gauge_length" value="<?php echo htmlspecialchars($report['gauge_length']); ?>" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Specimen Size:</label>
        <input type="text" name="specimen_size" value="<?php echo htmlspecialchars($report['specimen_size']); ?>" required>
      </div>
      <div class="form-group">
        <label>Note:</label>
        <input type="text" name="note" value="<?php echo htmlspecialchars($report['note'] ?? ''); ?>">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Temperature (°C):</label>
        <input type="number" name="temperature" value="<?php echo htmlspecialchars($report['temperature']); ?>" step="0.01" required>
      </div>
      <div class="form-group">
        <label>RH%:</label>
        <input type="number" name="rh_percent" value="<?php echo htmlspecialchars($report['rh_percent']); ?>" step="0.01" required>
      </div>
    </div>

    <h3 style="text-align:center; margin:30px 0 20px 0;">Test Results</h3>
    <table class="test-table">
      <thead>
        <tr>
          <th>Specimen No</th>
          <th>Test Direction</th>
          <th colspan="2">Breaking Force (N)</th>
          <th>Force Retain (%)</th>
          <th colspan="2">Elongation (%)</th>
        </tr>
        <tr>
          <th></th>
          <th></th>
          <th>After</th>
          <th>Before</th>
          <th></th>
          <th>After</th>
          <th>Before</th>
        </tr>
      </thead>
      <tbody>
        <?php for ($i = 1; $i <= 10; $i++): 
          $sd = $specimen_data[$i] ?? [];
        ?>
        <tr>
          <td><?php echo $i; ?></td>
          <td>
            <div style="display:inline-flex; gap:6px;">
              <button type="button" class="dir-btn <?php echo ($sd['test_direction'] ?? 'MD') === 'MD' ? 'active' : ''; ?>" onclick="setDirection(this,'MD',<?php echo $i; ?>)">MD</button>
              <button type="button" class="dir-btn <?php echo ($sd['test_direction'] ?? 'MD') === 'CD' ? 'active' : ''; ?>" onclick="setDirection(this,'CD',<?php echo $i; ?>)">CD</button>
              <input type="hidden" name="test_direction_<?php echo $i; ?>" value="<?php echo htmlspecialchars($sd['test_direction'] ?? 'MD'); ?>">
            </div>
          </td>
          <td><input type="number" step="0.01" name="breaking_force_after_<?php echo $i; ?>" value="<?php echo htmlspecialchars($sd['breaking_force_after'] ?? ''); ?>" oninput="calculateForceRetain(<?php echo $i; ?>)"></td>
          <td><input type="number" step="0.01" name="breaking_force_before_<?php echo $i; ?>" value="<?php echo htmlspecialchars($sd['breaking_force_before'] ?? ''); ?>" oninput="calculateForceRetain(<?php echo $i; ?>)"></td>
          <td><input type="text" name="force_retain_<?php echo $i; ?>" value="<?php echo htmlspecialchars($sd['force_retain'] ?? ''); ?>" readonly class="readonly"></td>
          <td><input type="number" step="0.01" name="elongation_after_<?php echo $i; ?>" value="<?php echo htmlspecialchars($sd['elongation_after'] ?? ''); ?>"></td>
          <td><input type="number" step="0.01" name="elongation_before_<?php echo $i; ?>" value="<?php echo htmlspecialchars($sd['elongation_before'] ?? ''); ?>"></td>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>

    <!-- Test Result Summary -->
    <div style="margin-top: 16px;">
      <h3 style="text-align:center; margin:8px 0;">Test Result</h3>
      <table class="test-table">
        <thead>
          <tr>
            <th></th>
            <th>After Exposure</th>
            <th>Before Exposure</th>
            <th>Retain (%)</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><strong>Force (N)</strong></td>
            <td><span id="result_after"></span></td>
            <td><span id="result_before"></span></td>
            <td><span id="result_retain"></span></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div style="text-align:center; margin-top:30px;">
      <button type="submit" name="update_report" class="btn btn-submit">Update & Resubmit Report</button>
      <a href="sun_test_report.php" class="btn btn-back">Cancel</a>
    </div>
  </form>
</div>

<script>
function setDirection(btn, dir, row) {
    const btns = btn.parentElement.querySelectorAll('.dir-btn');
    btns.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelector(`input[name="test_direction_${row}"]`).value = dir;
}

function calculateForceRetain(row) {
    const after = parseFloat(document.querySelector(`input[name="breaking_force_after_${row}"]`).value) || 0;
    const before = parseFloat(document.querySelector(`input[name="breaking_force_before_${row}"]`).value) || 0;
    
    if (before > 0) {
        const retain = ((after / before) * 100).toFixed(1);
        document.querySelector(`input[name="force_retain_${row}"]`).value = retain;
    } else {
        document.querySelector(`input[name="force_retain_${row}"]`).value = '';
    }
    
    // Update summary
    calculateSummary();
}

function calculateSummary() {
    let totalAfter = 0, totalBefore = 0, totalRetain = 0;
    let count = 0;
    
    for (let i = 1; i <= 5; i++) {
        const after = parseFloat(document.querySelector(`input[name="breaking_force_after_${i}"]`).value) || 0;
        const before = parseFloat(document.querySelector(`input[name="breaking_force_before_${i}"]`).value) || 0;
        const retain = parseFloat(document.querySelector(`input[name="force_retain_${i}"]`).value) || 0;
        
        if (after > 0 || before > 0) {
            totalAfter += after;
            totalBefore += before;
            totalRetain += retain;
            count++;
        }
    }
    
    if (count > 0) {
        document.getElementById('result_after').textContent = (totalAfter / count).toFixed(1);
        document.getElementById('result_before').textContent = (totalBefore / count).toFixed(1);
        document.getElementById('result_retain').textContent = (totalRetain / count).toFixed(1);
    } else {
        document.getElementById('result_after').textContent = '0.0';
        document.getElementById('result_before').textContent = '0.0';
        document.getElementById('result_retain').textContent = '0.0';
    }
}

// Calculate force retain and summary on page load
document.addEventListener('DOMContentLoaded', function() {
    for (let i = 1; i <= 5; i++) {
        calculateForceRetain(i);
    }
    calculateSummary();
});
</script>
</body>
</html>


