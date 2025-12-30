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
$stmt = $conn->prepare("SELECT * FROM fabric_after_production_tests WHERE id = ?");
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
        // Prepare test results data (11 parameters)
        $test_results_data = [];
        for ($i = 1; $i <= 11; $i++) {
            $test_results_data[$i] = [
                'standard' => $_POST["test_results"][$i]['standard'] ?? '',
                'unit' => $_POST["test_results"][$i]['unit'] ?? '',
                'result' => $_POST["test_results"][$i]['result'] ?? '',
                'remarks' => $_POST["test_results"][$i]['remarks'] ?? ''
            ];
        }
        $test_results_json = json_encode($test_results_data);
        
        // Update the report and reset status to pending
        $updateStmt = $conn->prepare("
            UPDATE fabric_after_production_tests 
            SET gsm = ?, line_number = ?, roll_number = ?, sample_id = ?,
                batch_information = ?, received_from = ?, sample_received_date = ?,
                sample_tested_date = ?, test_performed_by = ?, note = ?,
                test_results = ?, status = 'pending', approved_by = NULL, remarks = NULL
            WHERE id = ?
        ");
        
        $updateStmt->bind_param(
            "sisssssssssi",
            $_POST['gsm'],
            $_POST['line_number'],
            $_POST['roll_number'],
            $_POST['sample_id'],
            $_POST['batch_information'],
            $_POST['received_from'],
            $_POST['sample_received_date'],
            $_POST['sample_tested_date'],
            $_POST['test_performed_by'],
            $_POST['note'],
            $test_results_json,
            $report_id
        );
        
        if ($updateStmt->execute()) {
            $_SESSION['update_success'] = "Report updated and resubmitted successfully! Status: Pending Approval";
            header("Location: fabric_after_production_test.php");
            exit();
        } else {
            $error = "Error updating report: " . $updateStmt->error;
        }
        $updateStmt->close();
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Test parameters
$test_parameters = [
    1 => ['param' => 'Mass Per Unit', 'standards' => ['ASTM D5199', 'ISO 9863-1'], 'unit' => 'g/m²'],
    2 => ['param' => 'Thickness(under 2kPa pressure)', 'standards' => ['ASTM D5199'], 'unit' => 'mm'],
    3 => ['param' => 'Strip Tensile Strength (x-dir**)/CMD', 'standards' => ['ASTM D4595', 'ISO 10319'], 'unit' => 'kN/m'],
    4 => ['param' => 'Strip Tensile Elongation (x-dir**)/CMD', 'standards' => ['ASTM D4595', 'ISO 10319'], 'unit' => '%'],
    5 => ['param' => 'Strip Tensile Strength (y-dir**)/MD', 'standards' => ['ASTM D4595', 'ISO 10319'], 'unit' => 'kN/m'],
    6 => ['param' => 'Strip Tensile Elongation (y-dir**)/MD', 'standards' => ['ASTM D4595', 'ISO 10319'], 'unit' => '%'],
    7 => ['param' => 'CBR Puncture Resistance', 'standards' => ['ASTM D6241', 'ISO 12236'], 'unit' => 'N'],
    8 => ['param' => 'Grab Breaking Load (x-dir**)/CMD', 'standards' => ['ASTM D4632'], 'unit' => 'N'],
    9 => ['param' => 'Grab Breaking Elongation (x-dir**)/CMD', 'standards' => ['ASTM D4632'], 'unit' => '%'],
    10 => ['param' => 'Grab Breaking Load (y-dir**)/MD', 'standards' => ['ASTM D4632'], 'unit' => 'N'],
    11 => ['param' => 'Grab Breaking Elongation (y-dir**)/MD', 'standards' => ['ASTM D4632'], 'unit' => '%']
];

// Unit options for dropdown
$unit_options = ['g/m²', 'mm', 'kN/m', '%', 'N'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Fabric After Production Test Report</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
  .container { max-width:1200px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);} 
  h1 { text-align:center; font-size:28px; margin-bottom:30px; color:#2c3e50; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  input[type="text"], input[type="number"], input[type="date"], input[type="datetime-local"], select, textarea { 
    padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px); 
  }
  .actions { margin-top:30px; text-align:center; }
  .actions button, .actions a { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px; text-decoration:none; display:inline-block;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .cancel-btn { background:#95a5a6; color:#fff; }
  .readonly { background:#ecf0f1; }
  .test-table { width:100%; border-collapse:collapse; margin-top:15px; }
  .test-table th, .test-table td { border:1px solid #ddd; padding:8px; text-align:center; }
  .test-table th { background:#3498db; color:#fff; font-weight:600; }
  .test-table input, .test-table select { width:100%; border:none; background:transparent; text-align:center; }
  .test-table select { padding:5px; }
  .form-row { display:flex; gap:20px; margin-bottom:20px; }
  .form-row .form-group { flex:1; }
  .warning-banner { background:#fff3cd; border:1px solid #ffc107; border-radius:8px; padding:15px; margin-bottom:20px; color:#856404; }
  .warning-banner h3 { margin:0 0 10px 0; color:#d39e00; }
  .message { padding:12px; border-radius:8px; margin-bottom:15px; text-align:center; font-weight:600; }
  .message.success { background:#d4edda; border:1px solid #c3e6cb; color:#155724; }
  .message.error { background:#f8d7da; border:1px solid #f5c6cb; color:#721c24; }
</style>
</head>
<body>
<div class="container">
  <h1>Edit Fabric After Production Test Report</h1>
  <a href="fabric_after_production_test.php" style="display:inline-block; margin-bottom:20px; color:#3498db; text-decoration:none;">← Back to Form</a>

  <?php if ($error): ?>
    <div class="message error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <div class="warning-banner">
    <h3>⚠️ Report Rejected</h3>
    <p><strong>Rejected By:</strong> <?php echo htmlspecialchars($report['approved_by'] ?? 'Unknown'); ?></p>
    <p><strong>Rejection Comments:</strong> <?php echo htmlspecialchars($report['remarks'] ?? 'No comments'); ?></p>
    <p><strong>Original Submission:</strong> <?php echo htmlspecialchars($report['created_at']); ?></p>
    <p style="margin-top:10px; font-style:italic;">Please make the necessary corrections and resubmit the report.</p>
  </div>

  <form method="POST" action="">
    <!-- Report Information -->
    <div class="form-row">
      <div class="form-group">
        <label>Report Number:</label>
        <input type="text" value="<?php echo htmlspecialchars($report['report_number']); ?>" readonly class="readonly">
      </div>
      <div class="form-group">
        <label>GSM:</label>
        <input type="text" name="gsm" value="<?php echo htmlspecialchars($report['gsm']); ?>" required>
      </div>
      <div class="form-group">
        <label>Line Number:</label>
        <input type="number" name="line_number" value="<?php echo htmlspecialchars($report['line_number']); ?>" required min="1" step="1">
    </div>
      <div class="form-group">
        <label>Roll Number:</label>
        <input type="text" name="roll_number" value="<?php echo htmlspecialchars($report['roll_number']); ?>" required>
      </div>
    </div>

      <div class="form-group">
      <label>Sample ID:</label>
      <input type="text" name="sample_id" value="<?php echo htmlspecialchars($report['sample_id']); ?>" readonly class="readonly">
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Batch Information:</label>
        <input type="text" name="batch_information" value="<?php echo htmlspecialchars($report['batch_information']); ?>" required>
      </div>
      <div class="form-group">
        <label>Received From:</label>
        <input type="text" name="received_from" value="<?php echo htmlspecialchars($report['received_from']); ?>" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Sample Received Date:</label>
        <input type="datetime-local" name="sample_received_date" value="<?php echo date('Y-m-d\TH:i', strtotime($report['sample_received_date'])); ?>" required>
      </div>
      <div class="form-group">
        <label>Sample Tested Date:</label>
        <input type="date" name="sample_tested_date" value="<?php echo htmlspecialchars($report['sample_tested_date']); ?>" required>
      </div>
    </div>

      <div class="form-group">
      <label>Test Performed By:</label>
      <input type="text" name="test_performed_by" value="<?php echo htmlspecialchars($report['test_performed_by']); ?>" readonly class="readonly">
    </div>

    <!-- Test Results Table -->
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
          $current_result = $test_results[$sl_no] ?? [];
        ?>
        <tr>
          <td><?php echo $sl_no; ?></td>
          <td><?php echo htmlspecialchars($param['param']); ?></td>
          <td>
            <select name="test_results[<?php echo $sl_no; ?>][standard]" required>
              <option value="">Select Standard</option>
              <?php foreach ($param['standards'] as $standard): ?>
              <option value="<?php echo htmlspecialchars($standard); ?>" <?php echo ($current_result['standard'] ?? '') === $standard ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($standard); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <select name="test_results[<?php echo $sl_no; ?>][unit]" required>
              <option value="">Select Unit</option>
              <?php foreach ($unit_options as $unit): ?>
              <option value="<?php echo htmlspecialchars($unit); ?>" <?php echo ($current_result['unit'] ?? $param['unit']) === $unit ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($unit); ?>
              </option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <input type="number" step="0.01" name="test_results[<?php echo $sl_no; ?>][result]" value="<?php echo htmlspecialchars($current_result['result'] ?? ''); ?>">
          </td>
          <td>
            <input type="text" name="test_results[<?php echo $sl_no; ?>][remarks]" value="<?php echo htmlspecialchars($current_result['remarks'] ?? ''); ?>">
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Note Section -->
    <div class="form-group" style="margin-top:30px;">
      <label>Additional Notes:</label>
      <textarea name="note" rows="4" style="width:calc(100% - 22px);"><?php echo htmlspecialchars($report['note'] ?? ''); ?></textarea>
    </div>

    <div class="actions">
      <button type="submit" name="update_report" class="submit-btn">✓ Update and Resubmit</button>
      <a href="fabric_after_production_test.php" class="cancel-btn">Cancel</a>
    </div>
  </form>
</div>
</body>
</html>

