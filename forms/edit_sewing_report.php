<?php
session_start();
require_once 'security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$role = strtolower(trim($_SESSION['role'] ?? ''));
$user_id = $_SESSION['user_id'];
$reporter_full_name = $_SESSION['full_name'] ?? $_SESSION['username'];

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

// Only allow tester to edit their own rejected reports
if ($role !== 'tester' || $report['reporter_id'] != $user_id || $report['status'] !== 'rejected') {
    die('Access denied. You can only edit your own rejected reports.');
}

// Test parameters (same as in the form)
$test_parameters = [
    1 => ['param' => 'Denier', 'standards' => ['ISO 2060'], 'units' => ['D', 'den']],
    2 => ['param' => 'Tenacity at Break', 'standards' => ['ASTM D2256'], 'units' => ['cN/tex', 'g/d', 'N/tex']],
    3 => ['param' => 'Std Deviation', 'standards' => ['ASTM D2256'], 'units' => ['cN/tex', 'g/d']],
    4 => ['param' => 'CV%', 'standards' => ['ASTM D2256'], 'units' => ['%']],
    5 => ['param' => 'Elongation at Break', 'standards' => ['ASTM D2256'], 'units' => ['%', 'mm']],
];

// Decode test results
$test_results = json_decode($report['test_results'], true) ?? [];

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_report'])) {
    try {
        // Prepare test results
        $updated_results = [];
        foreach ($test_parameters as $sl_no => $param) {
            if (isset($_POST['test_results'][$sl_no])) {
                $updated_results[] = [
                    'sl_no' => $sl_no,
                    'parameter' => $param['param'],
                    'unit' => $_POST['test_results'][$sl_no]['unit'] ?? '',
                    'test_result' => $_POST['test_results'][$sl_no]['test_result'] ?? '',
                    'remarks' => $_POST['test_results'][$sl_no]['remarks'] ?? ''
                ];
            }
        }
        $test_results_json = json_encode($updated_results);
        
        // Update the report
        $updateStmt = $conn->prepare("
            UPDATE sewing_thread_reports 
            SET sample_description = ?, sample_received_from = ?, sample_collected_from = ?,
                reference = ?, received_date = ?, test_start_date = ?, test_end_date = ?,
                others_information = ?, test_temperature = ?, rh_percent = ?,
                test_results = ?, status = 'pending', approved_by = NULL, remarks = NULL
            WHERE id = ?
        ");
        
        $updateStmt->bind_param(
            "ssssssssddsi",
            $_POST['sample_description'],
            $_POST['sample_received_from'],
            $_POST['sample_collected_from'],
            $_POST['reference'],
            $_POST['received_date'],
            $_POST['test_start_date'],
            $_POST['test_end_date'],
            $_POST['others_information'],
            $_POST['test_temperature'],
            $_POST['rh_percent'],
            $test_results_json,
            $report_id
        );
        
        if ($updateStmt->execute()) {
            $_SESSION['update_success'] = "Report updated and resubmitted successfully! Status: Pending Approval";
            header("Location: sewing_thread_report.php");
            exit();
        } else {
            $error = "Error updating report: " . $updateStmt->error;
        }
        $updateStmt->close();
        
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Edit Rejected Sewing Thread Report - <?php echo htmlspecialchars($report['report_number']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
  .container { max-width:1200px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);} 
  h1 { text-align:center; font-size:28px; margin-bottom:30px; color:#2c3e50; }
  .form-group { margin-bottom:18px; }
  label { font-weight:600; display:block; margin-bottom:8px; font-size:14px; }
  input[type="text"], input[type="number"], select, textarea { padding:8px; border:1px solid #ccc; border-radius:4px; width:calc(100% - 18px); font-size:14px; }
  .form-row { display:flex; gap:20px; margin-bottom:25px; }
  .readonly { background:#ecf0f1; }
  .test-table { width:100%; border-collapse:collapse; margin-top:15px; font-size:12px; }
  .test-table th, .test-table td { border:1px solid #ddd; padding:8px; text-align:center; }
  .test-table th { background:#3498db; color:#fff; font-weight:600; }
  .btn { padding:10px 20px; border:none; border-radius:6px; cursor:pointer; margin:10px 5px 0 0; font-size:14px; text-decoration:none; display:inline-block; }
  .btn-submit { background:#28a745; color:#fff; }
  .btn-back { background:#6c757d; color:#fff; }
  .alert { padding:12px; border-radius:6px; margin-bottom:15px; }
  .alert-warning { background:#fff3cd; border:1px solid #ffc107; color:#856404; }
</style>
</head>
<body>
<div class="container">
  <h1>Edit Rejected Sewing Thread Report</h1>
  
  <div class="alert alert-warning">
    <strong>⚠️ Report Rejected:</strong> This report was rejected by <strong><?php echo htmlspecialchars($report['approved_by'] ?? 'Admin'); ?></strong><br>
    <strong>Reason:</strong> <?php echo htmlspecialchars($report['remarks'] ?? 'No comments provided'); ?><br>
    <strong>Original Report Number:</strong> <?php echo htmlspecialchars($report['report_number']); ?>
  </div>

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
      <div class="form-group">
        <label>Sample Description:</label>
        <input type="text" name="sample_description" value="<?php echo htmlspecialchars($report['sample_description']); ?>" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Ref:</label>
        <input type="text" name="reference" value="<?php echo htmlspecialchars($report['reference'] ?? ''); ?>">
      </div>
      <div class="form-group"></div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Sample Received From:</label>
        <input type="text" name="sample_received_from" value="<?php echo htmlspecialchars($report['sample_received_from']); ?>" required>
      </div>
      <div class="form-group">
        <label>Sample Collected From:</label>
        <input type="text" name="sample_collected_from" value="<?php echo htmlspecialchars($report['sample_collected_from']); ?>" required>
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
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Test End Date:</label>
        <input type="date" name="test_end_date" value="<?php echo htmlspecialchars($report['test_end_date']); ?>" required>
      </div>
      <div class="form-group"></div>
    </div>

    <div class="form-group">
      <label>Others Information:</label>
      <textarea name="others_information" rows="3"><?php echo htmlspecialchars($report['others_information'] ?? ''); ?></textarea>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Test Temperature (°C):</label>
        <input type="number" name="test_temperature" value="<?php echo htmlspecialchars($report['test_temperature']); ?>" step="0.01" required>
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
          <th>SL No</th>
          <th>Parameter</th>
          <th>Test Standard</th>
          <th>Unit</th>
          <th>Test Result</th>
          <th>Remarks</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($test_parameters as $sl_no => $param): 
          // Find existing test result for this parameter
          $existing_result = null;
          foreach ($test_results as $tr) {
            if (isset($tr['sl_no']) && $tr['sl_no'] == $sl_no) {
              $existing_result = $tr;
              break;
            }
          }
        ?>
        <tr>
          <td><?php echo $sl_no; ?></td>
          <td><?php echo htmlspecialchars($param['param']); ?></td>
          <td><?php echo htmlspecialchars($param['standards'][0]); ?></td>
          <td>
            <select name="test_results[<?php echo $sl_no; ?>][unit]" required style="width:100%; padding:5px;">
              <?php foreach ($param['units'] as $unit): ?>
                <option value="<?php echo htmlspecialchars($unit); ?>" <?php echo (isset($existing_result['unit']) && $existing_result['unit'] === $unit) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($unit); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <input type="text" name="test_results[<?php echo $sl_no; ?>][test_result]" value="<?php echo htmlspecialchars($existing_result['test_result'] ?? ''); ?>" required style="width:100%; padding:5px;">
          </td>
          <td>
            <input type="text" name="test_results[<?php echo $sl_no; ?>][remarks]" value="<?php echo htmlspecialchars($existing_result['remarks'] ?? ''); ?>" style="width:100%; padding:5px;">
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div style="text-align:center; margin-top:30px;">
      <button type="submit" name="update_report" class="btn btn-submit">Update & Resubmit Report</button>
      <a href="sewing_thread_report.php" class="btn btn-back">Cancel</a>
    </div>
  </form>
</div>
</body>
</html>

