<?php
session_start();
require_once 'security_config.php';

$user_id = $_SESSION['user_id'] ?? 0;
$role = strtolower(trim($_SESSION['role'] ?? ''));

if (!$user_id) {
    header("Location: ../login.html");
    exit();
}

// Get database connection
$conn = SecurityConfig::getConnection();

$report_id = intval($_GET['id'] ?? 0);

if ($report_id <= 0) {
    die('Invalid report ID');
}

// Fetch the report
$stmt = $conn->prepare("SELECT * FROM fiber_test_reports WHERE id = ?");
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
$common_units = ['dTex', 'mm', 'cN/dTex', '%', 'Nos/25mm'];

$test_parameters = [
    1 => ['param' => 'Unit Weight', 'standards' => ['EN ISO 1973'], 'units' => $common_units],
    2 => ['param' => 'Cut Length', 'standards' => ['ASTM D5103'], 'units' => $common_units],
    3 => ['param' => 'Tenacity at Break', 'standards' => ['EN ISO 5079'], 'units' => $common_units],
    4 => ['param' => 'Std Deviation', 'standards' => ['EN ISO 5079'], 'units' => $common_units],
    5 => ['param' => 'CV%', 'standards' => ['EN ISO 5079'], 'units' => $common_units],
    6 => ['param' => 'Elongation at Break', 'standards' => ['EN ISO 5079'], 'units' => $common_units],
    7 => ['param' => 'Cross Section', 'standards' => ['Round'], 'units' => ['']],
    8 => ['param' => 'No of Crimps', 'standards' => ['ASTM D3937'], 'units' => $common_units],
    9 => ['param' => 'UV Weathering', 'standards' => ['ASTM D4355'], 'units' => $common_units],
];

// Decode test results
$test_results = json_decode($report['test_results'], true) ?? [];

// Handle form submission
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_report'])) {
    try {
        // Rebuild test_results structure
        $updated_results = [];
        foreach ($test_parameters as $sl_no => $param_info) {
            if (isset($_POST["test_results"][$sl_no])) {
                $updated_results[] = [
                    'sl_no' => $sl_no,
                    'parameter' => $param_info['param'],
                    'standard' => $_POST["test_results"][$sl_no]['standard'] ?? '',
                    'unit' => $_POST["test_results"][$sl_no]['unit'] ?? '',
                    'test_result' => $_POST["test_results"][$sl_no]['test_result'] ?? '',
                    'remarks' => $_POST["test_results"][$sl_no]['remarks'] ?? ''
                ];
            }
        }
        $test_results_json = json_encode($updated_results);
        
        // Update the report
        $updateStmt = $conn->prepare("
            UPDATE fiber_test_reports 
            SET sample_name = ?, lc_no = ?, sample_received_date = ?, manufacturer_name = ?,
                sample_id = ?, sample_tested_date = ?, comments = ?,
                test_results = ?, status = 'pending', approved_by = NULL, remarks = NULL
            WHERE id = ?
        ");
        
        $updateStmt->bind_param(
            "ssssssssi",
            $_POST['sample_name'],
            $_POST['lc_no'],
            $_POST['sample_received_date'],
            $_POST['manufacturer_name'],
            $_POST['sample_id'],
            $_POST['sample_tested_date'],
            $_POST['comments'],
            $test_results_json,
            $report_id
        );
        
        if ($updateStmt->execute()) {
            $_SESSION['update_success'] = "Report updated and resubmitted successfully! Status: Pending Approval";
            header("Location: fiber_test_report.php");
            exit();
        } else {
            $error = "Error updating report: " . $updateStmt->error;
        }
        $updateStmt->close();
        
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Organize test results by sl_no for form pre-filling
$results_by_sl = [];
foreach ($test_results as $tr) {
    if (isset($tr['sl_no'])) {
        $results_by_sl[$tr['sl_no']] = $tr;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Edit Fiber Test Report - <?php echo htmlspecialchars($report['report_number']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
  .container { max-width:1200px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);} 
  h1 { text-align:center; font-size:28px; margin-bottom:30px; color:#2c3e50; }
  .form-group { margin-bottom:18px; }
  label { font-weight:600; display:block; margin-bottom:8px; font-size:14px; }
  input[type="text"], input[type="date"], input[type="number"], select, textarea { padding:8px; border:1px solid #ccc; border-radius:4px; width:calc(100% - 18px); font-size:14px; }
  .form-row { display:flex; gap:20px; margin-bottom:25px; }
  .form-row .form-group { flex:1; }
  .readonly { background:#ecf0f1; }
  .test-table { width:100%; border-collapse:collapse; margin-top:15px; font-size:12px; }
  .test-table th, .test-table td { border:1px solid #ddd; padding:8px; text-align:center; }
  .test-table th { background:#3498db; color:#fff; font-weight:600; }
  .test-table input, .test-table select { width:95%; }
  .btn { padding:10px 20px; border:none; border-radius:6px; cursor:pointer; margin:10px 5px 0 0; font-size:14px; text-decoration:none; display:inline-block; }
  .btn-submit { background:#28a745; color:#fff; }
  .btn-back { background:#6c757d; color:#fff; }
  .alert { padding:12px; border-radius:6px; margin-bottom:15px; }
  .alert-warning { background:#fff3cd; border:1px solid #ffc107; color:#856404; }
</style>
</head>
<body>
<div class="container">
  <h1>Edit Rejected Fiber Test Report</h1>
  
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
        <label>Sample Name:</label>
        <input type="text" name="sample_name" placeholder="Enter sample name" value="<?php echo htmlspecialchars($report['sample_name'] ?? ''); ?>" required>
      </div>
      <div class="form-group">
        <label>LC No:</label>
        <input type="text" name="lc_no" placeholder="Enter LC number" value="<?php echo htmlspecialchars($report['lc_no'] ?? ''); ?>">
      </div>
    </div>
    
    <div class="form-row">
      <div class="form-group">
        <label>Manufacturer Name:</label>
        <select name="manufacturer_name" required>
          <option value="">-- Select Manufacturer --</option>
          <?php 
          $manufacturers = [
            'Natpet',
            'APT',
            'Texofib',
            'Hubei Botao',
            'Jiangsu Botao',
            'Taizhu Hailun',
            'PSF',
            'Other'
          ];
          foreach ($manufacturers as $mfr): ?>
            <option value="<?php echo htmlspecialchars($mfr); ?>" 
              <?php echo (isset($report['manufacturer_name']) && $report['manufacturer_name'] === $mfr) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($mfr); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Sample Received Date:</label>
        <input type="date" name="sample_received_date" value="<?php echo htmlspecialchars($report['sample_received_date'] ?? ''); ?>" required>
      </div>
      <div class="form-group">
        <label>Sample Tested Date:</label>
        <input type="date" name="sample_tested_date" value="<?php echo htmlspecialchars($report['sample_tested_date']); ?>" required>
      </div>
    </div>
    
    <input type="hidden" name="sample_id" id="sample_id_hidden" value="<?php echo htmlspecialchars($report['sample_id'] ?? ''); ?>">

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
        <?php foreach ($test_parameters as $sl_no => $param_info):
          $existing = $results_by_sl[$sl_no] ?? [];
        ?>
        <tr>
          <td><?php echo $sl_no; ?></td>
          <td><?php echo htmlspecialchars($param_info['param']); ?></td>
          <?php if ($sl_no == 7): // Cross Section - special row ?>
          <td colspan="3" style="text-align:center; font-weight:600;">Round</td>
          <td>
            <input type="text" name="test_results[<?php echo $sl_no; ?>][remarks]" value="<?php echo htmlspecialchars($existing['remarks'] ?? ''); ?>">
            <input type="hidden" name="test_results[<?php echo $sl_no; ?>][standard]" value="Round">
            <input type="hidden" name="test_results[<?php echo $sl_no; ?>][unit]" value="">
            <input type="hidden" name="test_results[<?php echo $sl_no; ?>][test_result]" value="">
          </td>
          <?php else: ?>
          <td>
            <select name="test_results[<?php echo $sl_no; ?>][standard]">
              <?php foreach ($param_info['standards'] as $std): ?>
                <option value="<?php echo htmlspecialchars($std); ?>" <?php echo (($existing['standard'] ?? '') === $std) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($std); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <select name="test_results[<?php echo $sl_no; ?>][unit]">
              <?php foreach ($param_info['units'] as $unit): ?>
                <option value="<?php echo htmlspecialchars($unit); ?>" <?php echo (($existing['unit'] ?? '') === $unit) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($unit); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <input type="text" name="test_results[<?php echo $sl_no; ?>][test_result]" value="<?php echo htmlspecialchars($existing['test_result'] ?? ''); ?>">
          </td>
          <td>
            <input type="text" name="test_results[<?php echo $sl_no; ?>][remarks]" value="<?php echo htmlspecialchars($existing['remarks'] ?? ''); ?>">
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <h3 style="text-align:center; margin:30px 0 20px 0;">Acceptance Criteria</h3>
    <table class="test-table">
      <thead>
        <tr>
          <th rowspan="2">Acceptance Range</th>
          <th colspan="3">Quality Grades</th>
          <th rowspan="2">BWDB Requirements</th>
        </tr>
        <tr>
          <th>High</th>
          <th>Good</th>
          <th>Medium</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><strong>Tenacity</strong></td>
          <td>5.4+</td>
          <td>5+</td>
          <td>4.5+</td>
          <td style="vertical-align:middle;"></td>
        </tr>
        <tr>
          <td><strong>Elongation</strong></td>
          <td>0.8</td>
          <td>0.6</td>
          <td></td>
          <td>60%+</td>
        </tr>
      </tbody>
    </table>

    <h3 style="margin:30px 0 10px 0;">Comments</h3>
    <div class="form-group">
      <textarea name="comments" rows="3" style="width:calc(100% - 18px);"><?php echo htmlspecialchars($report['comments'] ?? ''); ?></textarea>
    </div>

    <div style="text-align:center; margin-top:30px;">
      <button type="submit" class="btn btn-submit">Update & Resubmit Report</button>
      <a href="fiber_test_report.php" class="btn btn-back">Cancel</a>
    </div>
  </form>
</div>

<script>
// Auto-generate sample_id when form is submitted
document.querySelector('form').addEventListener('submit', function(e) {
    const sampleName = document.querySelector('input[name="sample_name"]').value;
    const lcNo = document.querySelector('input[name="lc_no"]').value;
    const manufacturer = document.querySelector('select[name="manufacturer_name"]').value;
    
    // Auto-generate sample_id as combination
    let sampleId = sampleName;
    if (manufacturer) sampleId += ' - ' + manufacturer;
    if (lcNo) sampleId += ' - LC:' + lcNo;
    
    document.getElementById('sample_id_hidden').value = sampleId;
});
</script>
</body>
</html>

