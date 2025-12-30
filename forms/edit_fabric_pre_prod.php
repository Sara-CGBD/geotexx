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
$stmt = $conn->prepare("SELECT * FROM fabric_pre_production_tests WHERE id = ?");
$stmt->bind_param("i", $report_id);
$stmt->execute();
$result = $stmt->get_result();
$report = $result->fetch_assoc();
$stmt->close();

if (!$report) {
    die('Report not found');
}

// Only allow tester to edit their own rejected reports
if ($role !== 'tester' || $report['reporter_id'] != $user_id || 
    ($report['status'] !== 'rejected_by_checker' && $report['status'] !== 'rejected_by_approver')) {
    die('Access denied. You can only edit your own rejected reports.');
}

// Decode test data
$test_data = json_decode($report['test_data'], true) ?? [];

// Handle form submission to update the report
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_report'])) {
    try {
        // Prepare test data
        $updated_test_data = $_POST['test_data'] ?? [];
        
        // Include summary data
        if (isset($_POST['summary'])) {
            $updated_test_data['summary'] = $_POST['summary'];
        }
        
        $test_data_json = json_encode($updated_test_data);
        
        // Update the report and reset status to pending_checker
        $updateStmt = $conn->prepare("
            UPDATE fabric_pre_production_tests 
            SET sample_details = ?, sample_collected_from = ?, batch_information = ?,
                gsm = ?, line_no = ?, roll_number = ?, product_reference = ?,
                customer_reference = ?, sample_received_date = ?, sample_production_date = ?,
                test_period_from = ?, test_period_to = ?, sample_received_from = ?,
                lighthouse_reference = ?, test_performed_by = ?, temperature = ?,
                rh_percentage = ?, others_information = ?, test_data = ?,
                status = 'pending_checker', checked_by = NULL, checked_at = NULL,
                checker_remarks = NULL, approved_by = NULL, remarks = NULL
            WHERE id = ?
        ");
        
        $updateStmt->bind_param(
            "sssiissssssssssddssi",
            $_POST['sample_details'],
            $_POST['sample_collected_from'],
            $_POST['batch_information'],
            $_POST['gsm'],
            $_POST['line_no'],
            $_POST['roll_number'],
            $_POST['product_reference'],
            $_POST['customer_reference'],
            $_POST['sample_received_date'],
            $_POST['sample_production_date'],
            $_POST['test_period_from'],
            $_POST['test_period_to'],
            $_POST['sample_received_from'],
            $_POST['lighthouse_reference'],
            $_POST['test_performed_by'],
            $_POST['temperature'],
            $_POST['rh_percentage'],
            $_POST['others_information'],
            $test_data_json,
            $report_id
        );
        
        if ($updateStmt->execute()) {
            $_SESSION['update_success'] = "Report updated and resubmitted successfully! Status: Pending Checker Review";
            header("Location: fabric_pre_production_test.php");
            exit();
        } else {
            $error = "Error updating report: " . $updateStmt->error;
        }
        $updateStmt->close();
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Determine who rejected and their comments
$rejected_by = '';
$rejection_comments = '';
if ($report['status'] === 'rejected_by_checker') {
    $rejected_by = $report['checked_by'] ?? 'Checker';
    $rejection_comments = $report['checker_remarks'] ?? 'No comments';
} else {
    $rejected_by = $report['approved_by'] ?? 'Admin';
    $rejection_comments = $report['remarks'] ?? 'No comments';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Fabric Pre-Production Test Report</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
  .container { max-width:1400px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);} 
  h1 { text-align:center; font-size:28px; margin-bottom:30px; color:#2c3e50; }
  h3 { text-align:center; margin:30px 0 20px 0; color:#2c3e50; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  input[type="text"], input[type="number"], input[type="date"], input[type="datetime-local"], select, textarea { 
    padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px); 
  }
  textarea { width:calc(100% - 22px); }
  .actions { margin-top:30px; text-align:center; }
  .actions button, .actions a { padding:12px 24px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px; text-decoration:none; display:inline-block;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .cancel-btn { background:#95a5a6; color:#fff; }
  .readonly { background:#ecf0f1; }
  .test-table { width:100%; border-collapse:collapse; margin-top:15px; font-size:13px; }
  .test-table th, .test-table td { border:1px solid #ddd; padding:6px 4px; text-align:center; }
  .test-table th { background:#3498db; color:#fff; font-weight:600; font-size:11px; }
  .test-table input, .test-table select { width:100%; border:none; background:transparent; text-align:center; padding:4px; }
  .test-table select { padding:3px; }
  .form-row { display:flex; gap:20px; margin-bottom:20px; }
  .form-col { flex:1; }
  .warning-banner { background:#fff3cd; border:1px solid #ffc107; border-radius:8px; padding:15px; margin-bottom:20px; color:#856404; }
  .warning-banner h3 { margin:0 0 10px 0; color:#d39e00; text-align:left; }
  .message { padding:12px; border-radius:8px; margin-bottom:15px; text-align:center; font-weight:600; }
  .message.error { background:#f8d7da; border:1px solid #f5c6cb; color:#721c24; }
  .summary-table { width:100%; border-collapse:collapse; margin-top:20px; font-size:12px; }
  .summary-table th, .summary-table td { border:1px solid #ddd; padding:6px 4px; text-align:center; }
  .summary-table th { background:#27ae60; color:#fff; font-weight:600; font-size:11px; white-space:nowrap; }
  .summary-table input { width:100%; border:none; background:transparent; text-align:center; padding:3px; font-size:12px; min-width:50px; max-width:80px; }
</style>
</head>
<body>
<div class="container">
  <h1>Edit Fabric Pre-Production Test Report</h1>
  <a href="fabric_pre_production_test.php" style="display:inline-block; margin-bottom:20px; color:#3498db; text-decoration:none;">← Back to Form</a>

  <?php if ($error): ?>
    <div class="message error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <div class="warning-banner">
    <h3>⚠️ Report Rejected</h3>
    <p><strong>Rejected By:</strong> <?php echo htmlspecialchars($rejected_by); ?></p>
    <p><strong>Rejection Comments:</strong> <?php echo htmlspecialchars($rejection_comments); ?></p>
    <p><strong>Original Submission:</strong> <?php echo htmlspecialchars($report['created_at']); ?></p>
    <p style="margin-top:10px; font-style:italic;">Please make the necessary corrections and resubmit the report.</p>
  </div>

  <form method="POST" action="" id="editForm">
    <input type="hidden" name="update_report" value="1">
    
    <!-- Report Information -->
    <div class="form-group">
      <label>Report No.:</label>
      <input type="text" value="<?php echo htmlspecialchars($report['report_number']); ?>" readonly class="readonly">
    </div>

    <!-- Form Fields in Two Columns -->
    <div class="form-row">
      <div class="form-col">
        <div class="form-group">
          <label>Sample Details:</label>
          <input type="text" name="sample_details" value="<?php echo htmlspecialchars($report['sample_details']); ?>" required>
        </div>
        
        <div class="form-group">
          <label>Sample Collected From:</label>
          <input type="text" name="sample_collected_from" value="<?php echo htmlspecialchars($report['sample_collected_from']); ?>" required>
        </div>
        
        <div class="form-group">
          <label>Batch Information:</label>
          <input type="text" name="batch_information" value="<?php echo htmlspecialchars($report['batch_information']); ?>" required placeholder="e.g., GT9.H1">
        </div>
        
        <div class="form-group">
          <label>GSM:</label>
          <input type="number" name="gsm" value="<?php echo htmlspecialchars($report['gsm']); ?>" required min="1" step="1">
        </div>
        
        <div class="form-group">
          <label>Line No:</label>
          <input type="number" name="line_no" value="<?php echo htmlspecialchars($report['line_no']); ?>" required min="1" step="1">
        </div>
      </div>
      
      <div class="form-col">
        <div class="form-group">
          <label>Roll Number:</label>
          <input type="text" name="roll_number" value="<?php echo htmlspecialchars($report['roll_number']); ?>" required>
        </div>
        
        <div class="form-group">
          <label>Product Reference:</label>
          <input type="text" name="product_reference" value="<?php echo htmlspecialchars($report['product_reference']); ?>" readonly class="readonly">
        </div>
        
        <div class="form-group">
          <label>Customer Reference:</label>
          <input type="text" name="customer_reference" value="<?php echo htmlspecialchars($report['customer_reference']); ?>" required>
        </div>
        
        <div class="form-group">
          <label>Sample Received Date:</label>
          <input type="datetime-local" name="sample_received_date" value="<?php echo date('Y-m-d\TH:i', strtotime($report['sample_received_date'])); ?>" required>
        </div>
        
        <div class="form-group">
          <label>Sample Production Date:</label>
          <input type="datetime-local" name="sample_production_date" value="<?php echo date('Y-m-d\TH:i', strtotime($report['sample_production_date'])); ?>" required>
        </div>
      </div>
    </div>

    <!-- Test Period Row -->
    <div class="form-group">
      <label>Test Period:</label>
      <div style="display: flex; gap: 10px; align-items: center;">
        <input type="date" name="test_period_from" value="<?php echo htmlspecialchars($report['test_period_from']); ?>" required style="flex: 1;">
        <span style="font-weight: bold;">to</span>
        <input type="date" name="test_period_to" value="<?php echo htmlspecialchars($report['test_period_to']); ?>" required style="flex: 1;">
      </div>
    </div>

    <!-- Additional Fields Row -->
    <div class="form-row">
      <div class="form-col">
        <div class="form-group">
          <label>Sample Received From:</label>
          <input type="text" name="sample_received_from" value="<?php echo htmlspecialchars($report['sample_received_from']); ?>" required>
        </div>
      </div>
      
      <div class="form-col">
        <div class="form-group">
          <label>Lighthouse Reference:</label>
          <input type="text" name="lighthouse_reference" value="<?php echo htmlspecialchars($report['lighthouse_reference'] ?? ''); ?>">
        </div>
      </div>
    </div>

    <!-- Test Performed By -->
    <div class="form-group">
      <label>Test Performed By:</label>
      <input type="text" name="test_performed_by" value="<?php echo htmlspecialchars($report['test_performed_by']); ?>" readonly class="readonly">
    </div>

    <!-- Temperature and RH -->
    <div class="form-row">
      <div class="form-col">
        <div class="form-group">
          <label>Temperature (C):</label>
          <input type="number" name="temperature" value="<?php echo htmlspecialchars($report['temperature'] ?? ''); ?>" step="0.1" min="-50" max="100">
        </div>
      </div>
      <div class="form-col">
        <div class="form-group">
          <label>RH%:</label>
          <input type="number" name="rh_percentage" value="<?php echo htmlspecialchars($report['rh_percentage'] ?? ''); ?>" step="0.1" min="0" max="100">
        </div>
      </div>
    </div>

    <!-- Others Information -->
    <div class="form-group">
      <label>Others Information:</label>
      <textarea name="others_information" rows="2"><?php echo htmlspecialchars($report['others_information'] ?? ''); ?></textarea>
    </div>

    <!-- Raw Test Data Table -->
    <h3>Raw Test Data</h3>
    <div style="overflow-x:auto;">
      <table class="test-table">
        <thead>
          <tr>
            <th rowspan="2">SL</th>
            <th colspan="4">GSM Test (EN ISO 9864)</th>
            <th colspan="2">Thickness Test (EN ISO 9863-1)</th>
            <th colspan="4">Strip Tensile Test (EN ISO 10319)</th>
            <th colspan="2">CBR Test (EN ISO 12236)</th>
            <th colspan="3">Grab Tensile Test (ASTM D4632)</th>
          </tr>
          <tr>
            <th>Position</th>
            <th>Weight(g)</th>
            <th>GSM(g/m²)</th>
            <th>Average</th>
            <th>Thickness(mm)</th>
            <th>Average</th>
            <th>Direction</th>
            <th>Strength(kN/m)</th>
            <th>Ratio</th>
            <th>Elongation(%)</th>
            <th>Force(N)</th>
            <th>Displacement(mm)</th>
            <th>Direction</th>
            <th>Force(N)</th>
            <th>Elongation(%)</th>
          </tr>
        </thead>
        <tbody id="testDataBody">
          <?php 
          // Get number of existing rows with data
          $existing_rows = 0;
          if (isset($test_data['position']) && is_array($test_data['position'])) {
              // Count only rows that have some data
              foreach ($test_data['position'] as $idx => $val) {
                  if (!empty($val) || 
                      !empty($test_data['gsm_weight'][$idx] ?? '') ||
                      !empty($test_data['gsm_calculated'][$idx] ?? '') ||
                      !empty($test_data['thickness'][$idx] ?? '') ||
                      !empty($test_data['strip_strength'][$idx] ?? '') ||
                      !empty($test_data['cbr_force'][$idx] ?? '') ||
                      !empty($test_data['grab_force'][$idx] ?? '')) {
                      $existing_rows = $idx + 1;
                  }
              }
          }
          
          // Display only existing rows with data, or minimum 4 rows
          $display_rows = max(4, $existing_rows);
          
          for ($i = 0; $i < $display_rows; $i++): 
            $pos = $test_data['position'][$i] ?? '';
            $gsm_weight = $test_data['gsm_weight'][$i] ?? '';
            $gsm_calculated = $test_data['gsm_calculated'][$i] ?? '';
            $gsm_average = $test_data['gsm_average'][$i] ?? '';
            $thickness = $test_data['thickness'][$i] ?? '';
            $thickness_average = $test_data['thickness_average'][$i] ?? '';
            $strip_direction = $test_data['strip_direction'][$i] ?? ($i % 2 === 0 ? 'MD' : 'CD');
            $strip_strength = $test_data['strip_strength'][$i] ?? '';
            $strip_ratio = $test_data['strip_ratio'][$i] ?? '';
            $strip_elongation = $test_data['strip_elongation'][$i] ?? '';
            $cbr_force = $test_data['cbr_force'][$i] ?? '';
            $cbr_displacement = $test_data['cbr_displacement'][$i] ?? '';
            $grab_direction = $test_data['grab_direction'][$i] ?? ($i % 2 === 0 ? 'MD' : 'CD');
            $grab_force = $test_data['grab_force'][$i] ?? '';
            $grab_elongation = $test_data['grab_elongation'][$i] ?? '';
          ?>
          <tr>
            <td><?php echo $i + 1; ?></td>
            <td><input type="text" name="test_data[position][<?php echo $i; ?>]" value="<?php echo htmlspecialchars($pos); ?>"></td>
            <td><input type="number" step="0.0001" name="test_data[gsm_weight][<?php echo $i; ?>]" value="<?php echo htmlspecialchars($gsm_weight); ?>"></td>
            <td><input type="number" step="0.01" name="test_data[gsm_calculated][<?php echo $i; ?>]" value="<?php echo htmlspecialchars($gsm_calculated); ?>"></td>
            <td><input type="number" step="0.01" name="test_data[gsm_average][<?php echo $i; ?>]" value="<?php echo htmlspecialchars($gsm_average); ?>" readonly class="readonly"></td>
            <td><input type="number" step="0.001" name="test_data[thickness][<?php echo $i; ?>]" value="<?php echo htmlspecialchars($thickness); ?>"></td>
            <td><input type="number" step="0.001" name="test_data[thickness_average][<?php echo $i; ?>]" value="<?php echo htmlspecialchars($thickness_average); ?>" readonly class="readonly"></td>
            <td>
              <select name="test_data[strip_direction][<?php echo $i; ?>]">
                <option value="MD" <?php echo $strip_direction === 'MD' ? 'selected' : ''; ?>>MD</option>
                <option value="CD" <?php echo $strip_direction === 'CD' ? 'selected' : ''; ?>>CD</option>
              </select>
            </td>
            <td><input type="number" step="0.01" name="test_data[strip_strength][<?php echo $i; ?>]" value="<?php echo htmlspecialchars($strip_strength); ?>"></td>
            <td><input type="text" name="test_data[strip_ratio][<?php echo $i; ?>]" value="<?php echo htmlspecialchars($strip_ratio); ?>" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="test_data[strip_elongation][<?php echo $i; ?>]" value="<?php echo htmlspecialchars($strip_elongation); ?>"></td>
            <td><input type="number" step="0.01" name="test_data[cbr_force][<?php echo $i; ?>]" value="<?php echo htmlspecialchars($cbr_force); ?>"></td>
            <td><input type="number" step="0.01" name="test_data[cbr_displacement][<?php echo $i; ?>]" value="<?php echo htmlspecialchars($cbr_displacement); ?>"></td>
            <td>
              <select name="test_data[grab_direction][<?php echo $i; ?>]">
                <option value="MD" <?php echo $grab_direction === 'MD' ? 'selected' : ''; ?>>MD</option>
                <option value="CD" <?php echo $grab_direction === 'CD' ? 'selected' : ''; ?>>CD</option>
              </select>
            </td>
            <td><input type="number" step="0.01" name="test_data[grab_force][<?php echo $i; ?>]" value="<?php echo htmlspecialchars($grab_force); ?>"></td>
            <td><input type="number" step="0.01" name="test_data[grab_elongation][<?php echo $i; ?>]" value="<?php echo htmlspecialchars($grab_elongation); ?>"></td>
          </tr>
          <?php endfor; ?>
        </tbody>
      </table>
      <button type="button" onclick="addMoreRows()" style="margin-top:10px; padding:8px 16px; background:#27ae60; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:14px;">+ Add 4 More Rows</button>
    </div>

    <!-- Summary of Test Results -->
    <h3 style="text-align: center;">Summary of Test Result</h3>
    <div style="overflow-x:auto;">
      <table class="summary-table" style="min-width: 1200px;">
        <thead>
          <tr>
            <th rowspan="2" style="background:#f8f9fa; color:#000; width: 100px;">Statistics</th>
            <th colspan="1" style="background:#1976d2; color:#fff;">Mass Per Unit Area(GSM Test)</th>
            <th colspan="1" style="background:#7b1fa2; color:#fff;">Thickness Test Under 2kPa(mm)</th>
            <th colspan="4" style="background:#388e3c; color:#fff;">Strip Tensile Strength Test</th>
            <th colspan="2" style="background:#f57c00; color:#fff;">CBR Test</th>
            <th colspan="4" style="background:#c2185b; color:#fff;">Grab Test</th>
          </tr>
          <tr>
            <!-- GSM Test -->
            <th style="background:#1976d2; color:#fff;"></th>
            <!-- Thickness Test -->
            <th style="background:#7b1fa2; color:#fff;"></th>
            <!-- Strip Tensile Test -->
            <th colspan="2" style="background:#388e3c; color:#fff;">MD</th>
            <th colspan="2" style="background:#388e3c; color:#fff;">CD</th>
            <!-- CBR Test -->
            <th style="background:#f57c00; color:#fff;">Ultimate Force(N)</th>
            <th style="background:#f57c00; color:#fff;">Ultimate Displacement(mm)</th>
            <!-- Grab Test -->
            <th colspan="2" style="background:#c2185b; color:#fff;">MD</th>
            <th colspan="2" style="background:#c2185b; color:#fff;">CD</th>
          </tr>
          <tr>
            <!-- Third header row -->
            <th style="background:#f8f9fa; color:#000;"></th>
            <th style="background:#1976d2; color:#fff;"></th>
            <th style="background:#7b1fa2; color:#fff;"></th>
            <th style="background:#388e3c; color:#fff;">Strength(kN/m)</th>
            <th style="background:#388e3c; color:#fff;">Elongation(%)</th>
            <th style="background:#388e3c; color:#fff;">Strength(kN/m)</th>
            <th style="background:#388e3c; color:#fff;">Elongation(%)</th>
            <th style="background:#f57c00; color:#fff;"></th>
            <th style="background:#f57c00; color:#fff;"></th>
            <th style="background:#c2185b; color:#fff;">Force(N)</th>
            <th style="background:#c2185b; color:#fff;">Elongation(%)</th>
            <th style="background:#c2185b; color:#fff;">Force(N)</th>
            <th style="background:#c2185b; color:#fff;">Elongation(%)</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td style="font-weight: bold;">Average:</td>
            <td><input type="number" step="0.01" name="summary[gsm_avg]" readonly class="readonly"></td>
            <td><input type="number" step="0.001" name="summary[thickness_avg]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_md_strength_avg]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_md_elongation_avg]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_cd_strength_avg]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_cd_elongation_avg]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[cbr_force_avg]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[cbr_displacement_avg]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_md_force_avg]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_md_elongation_avg]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_cd_force_avg]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_cd_elongation_avg]" readonly class="readonly"></td>
          </tr>
          <tr>
            <td style="font-weight: bold;">SD:</td>
            <td><input type="number" step="0.01" name="summary[gsm_sd]" readonly class="readonly"></td>
            <td><input type="number" step="0.001" name="summary[thickness_sd]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_md_strength_sd]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_md_elongation_sd]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_cd_strength_sd]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_cd_elongation_sd]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[cbr_force_sd]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[cbr_displacement_sd]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_md_force_sd]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_md_elongation_sd]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_cd_force_sd]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_cd_elongation_sd]" readonly class="readonly"></td>
          </tr>
          <tr>
            <td style="font-weight: bold;">CV%:</td>
            <td><input type="number" step="0.01" name="summary[gsm_cv]" readonly class="readonly"></td>
            <td><input type="number" step="0.001" name="summary[thickness_cv]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_md_strength_cv]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_md_elongation_cv]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_cd_strength_cv]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_cd_elongation_cv]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[cbr_force_cv]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[cbr_displacement_cv]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_md_force_cv]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_md_elongation_cv]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_cd_force_cv]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_cd_elongation_cv]" readonly class="readonly"></td>
          </tr>
          <tr>
            <td style="font-weight: bold;">Maximum:</td>
            <td><input type="number" step="0.01" name="summary[gsm_max]" readonly class="readonly"></td>
            <td><input type="number" step="0.001" name="summary[thickness_max]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_md_strength_max]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_md_elongation_max]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_cd_strength_max]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_cd_elongation_max]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[cbr_force_max]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[cbr_displacement_max]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_md_force_max]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_md_elongation_max]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_cd_force_max]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_cd_elongation_max]" readonly class="readonly"></td>
          </tr>
          <tr>
            <td style="font-weight: bold;">Minimum:</td>
            <td><input type="number" step="0.01" name="summary[gsm_min]" readonly class="readonly"></td>
            <td><input type="number" step="0.001" name="summary[thickness_min]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_md_strength_min]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_md_elongation_min]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_cd_strength_min]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[strip_cd_elongation_min]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[cbr_force_min]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[cbr_displacement_min]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_md_force_min]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_md_elongation_min]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_cd_force_min]" readonly class="readonly"></td>
            <td><input type="number" step="0.01" name="summary[grab_cd_elongation_min]" readonly class="readonly"></td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="actions">
      <button type="submit" class="submit-btn">✓ Update and Resubmit</button>
      <a href="fabric_pre_production_test.php" class="cancel-btn">Cancel</a>
    </div>
  </form>
</div>

<script>
// Row count for calculations
let rowCount = <?php echo $display_rows; ?>;

// Add 4 more rows
function addMoreRows() {
  const tbody = document.getElementById('testDataBody');
  const startIdx = rowCount;
  
  for (let i = 0; i < 4; i++) {
    const newIdx = startIdx + i;
    const row = document.createElement('tr');
    row.innerHTML = `
      <td>${newIdx + 1}</td>
      <td><input type="text" name="test_data[position][${newIdx}]"></td>
      <td><input type="number" step="0.0001" name="test_data[gsm_weight][${newIdx}]"></td>
      <td><input type="number" step="0.01" name="test_data[gsm_calculated][${newIdx}]"></td>
      <td><input type="number" step="0.01" name="test_data[gsm_average][${newIdx}]" readonly class="readonly"></td>
      <td><input type="number" step="0.001" name="test_data[thickness][${newIdx}]"></td>
      <td><input type="number" step="0.001" name="test_data[thickness_average][${newIdx}]" readonly class="readonly"></td>
      <td>
        <select name="test_data[strip_direction][${newIdx}]">
          <option value="MD" ${newIdx % 2 === 0 ? 'selected' : ''}>MD</option>
          <option value="CD" ${newIdx % 2 !== 0 ? 'selected' : ''}>CD</option>
        </select>
      </td>
      <td><input type="number" step="0.01" name="test_data[strip_strength][${newIdx}]"></td>
      <td><input type="text" name="test_data[strip_ratio][${newIdx}]" readonly class="readonly"></td>
      <td><input type="number" step="0.01" name="test_data[strip_elongation][${newIdx}]"></td>
      <td><input type="number" step="0.01" name="test_data[cbr_force][${newIdx}]"></td>
      <td><input type="number" step="0.01" name="test_data[cbr_displacement][${newIdx}]"></td>
      <td>
        <select name="test_data[grab_direction][${newIdx}]">
          <option value="MD" ${newIdx % 2 === 0 ? 'selected' : ''}>MD</option>
          <option value="CD" ${newIdx % 2 !== 0 ? 'selected' : ''}>CD</option>
        </select>
      </td>
      <td><input type="number" step="0.01" name="test_data[grab_force][${newIdx}]"></td>
      <td><input type="number" step="0.01" name="test_data[grab_elongation][${newIdx}]"></td>
    `;
    tbody.appendChild(row);
    
    // Add listeners to new inputs
    row.querySelectorAll('input, select').forEach(input => {
      input.addEventListener('input', updateSummaryOfResults);
      input.addEventListener('change', updateSummaryOfResults);
    });
  }
  
  rowCount += 4;
  updateSummaryOfResults();
}

// Auto-calculate summary on page load and when data changes
document.addEventListener('DOMContentLoaded', function() {
  // Initial calculation
  updateSummaryOfResults();
  
  // Recalculate after a short delay to ensure all pre-filled values are loaded
  setTimeout(function() {
    updateSummaryOfResults();
  }, 100);
  
  // Add input listeners to all test data fields
  const testDataInputs = document.querySelectorAll('#testDataBody input, #testDataBody select');
  testDataInputs.forEach(input => {
    input.addEventListener('input', updateSummaryOfResults);
    input.addEventListener('change', updateSummaryOfResults);
  });
});

// Also trigger calculation when window fully loads (after all resources)
window.addEventListener('load', function() {
  updateSummaryOfResults();
});

function updateSummaryOfResults() {
  // Helper functions
  const computeAverage = (arr) => {
    if (!arr.length) return null;
    return arr.reduce((a, b) => a + b, 0) / arr.length;
  };
  
  const computePopulationSd = (arr) => {
    if (!arr || arr.length === 0) return null;
    if (arr.length === 1) return 0;
    const mean = computeAverage(arr);
    const variance = arr.reduce((acc, x) => acc + Math.pow(x - mean, 2), 0) / arr.length;
    return Math.sqrt(variance);
  };
  
  const computeStats = (arr) => {
    if (!Array.isArray(arr) || arr.length === 0) {
      return { avg: null, sd: null, cv: null, max: null, min: null };
    }
    const avg = computeAverage(arr);
    const sd = computePopulationSd(arr);
    let cv = 0;
    if (avg && sd != null && avg !== 0) {
      const cvRaw = (sd / avg) * 100;
      cv = Math.floor(cvRaw * 100) / 100;
    }
    return { avg, sd, cv, max: Math.max(...arr), min: Math.min(...arr) };
  };
  
  const collectNumeric = (baseName, filterPositive = false) => {
    const out = [];
    for (let i = 0; i < rowCount; i++) {
      const el = document.querySelector(`input[name="test_data[${baseName}][${i}]"]`);
      if (!el) continue;
      const v = parseFloat(el.value);
      if (isNaN(v)) continue;
      if (filterPositive && v <= 0) continue;
      out.push(v);
    }
    return out;
  };
  
  const setVal = (sel, val, digits) => { 
    const el = document.querySelector(sel); 
    if (el) el.value = val != null ? val.toFixed(digits) : ''; 
  };
  
  // STEP 1: Calculate row-level averages (every 4 rows)
  // GSM Average: Calculate for each group of 4 rows
  for (let start = 0; start < rowCount; start += 4) {
    const gsmValues = [];
    for (let i = start; i < Math.min(start + 4, rowCount); i++) {
      const el = document.querySelector(`input[name="test_data[gsm_calculated][${i}]"]`);
      if (el) {
        const v = parseFloat(el.value);
        if (!isNaN(v) && v > 0) gsmValues.push(v);
      }
    }
    // Write average to the 4th row (or last row of group)
    const avgIdx = Math.min(start + 3, rowCount - 1);
    const avgEl = document.querySelector(`input[name="test_data[gsm_average][${avgIdx}]"]`);
    if (avgEl && gsmValues.length > 0) {
      avgEl.value = computeAverage(gsmValues).toFixed(2);
    }
  }
  
  // Thickness Average: Calculate for each group of 4 rows
  for (let start = 0; start < rowCount; start += 4) {
    const thkValues = [];
    for (let i = start; i < Math.min(start + 4, rowCount); i++) {
      const el = document.querySelector(`input[name="test_data[thickness][${i}]"]`);
      if (el) {
        const v = parseFloat(el.value);
        if (!isNaN(v) && v > 0) thkValues.push(v);
      }
    }
    // Write average to the 4th row (or last row of group)
    const avgIdx = Math.min(start + 3, rowCount - 1);
    const avgEl = document.querySelector(`input[name="test_data[thickness_average][${avgIdx}]"]`);
    if (avgEl && thkValues.length > 0) {
      avgEl.value = computeAverage(thkValues).toFixed(3);
    }
  }
  
  // Strip Tensile Ratio: Calculate MD:CD ratio for pairs
  // Process in pairs (rows 0-1, 2-3, 4-5, etc.)
  for (let pairStart = 0; pairStart < rowCount; pairStart += 2) {
    const row1 = pairStart;
    const row2 = pairStart + 1;
    
    if (row2 >= rowCount) break;
    
    // Get MD and CD values from the pair
    const dir1El = document.querySelector(`select[name="test_data[strip_direction][${row1}]"]`);
    const strength1El = document.querySelector(`input[name="test_data[strip_strength][${row1}]"]`);
    const dir2El = document.querySelector(`select[name="test_data[strip_direction][${row2}]"]`);
    const strength2El = document.querySelector(`input[name="test_data[strip_strength][${row2}]"]`);
    
    if (dir1El && strength1El && dir2El && strength2El) {
      const dir1 = dir1El.value;
      const strength1 = parseFloat(strength1El.value);
      const dir2 = dir2El.value;
      const strength2 = parseFloat(strength2El.value);
      
      let mdValue = null;
      let cdValue = null;
      
      if (dir1 === 'MD' && !isNaN(strength1)) mdValue = strength1;
      else if (dir1 === 'CD' && !isNaN(strength1)) cdValue = strength1;
      
      if (dir2 === 'MD' && !isNaN(strength2)) mdValue = strength2;
      else if (dir2 === 'CD' && !isNaN(strength2)) cdValue = strength2;
      
      // Calculate and write ratio to the CD row only
      if (mdValue && cdValue && cdValue !== 0) {
        const ratio = mdValue / cdValue;
        const cdRowIdx = dir1 === 'CD' ? row1 : row2;
        const ratioEl = document.querySelector(`input[name="test_data[strip_ratio][${cdRowIdx}]"]`);
        if (ratioEl) {
          ratioEl.value = '1:' + ratio.toFixed(2);
        }
        // Clear the MD row's ratio
        const mdRowIdx = dir1 === 'MD' ? row1 : row2;
        const mdRatioEl = document.querySelector(`input[name="test_data[strip_ratio][${mdRowIdx}]"]`);
        if (mdRatioEl) {
          mdRatioEl.value = '';
        }
      }
    }
  }
  
  // GSM calculations
  const gsmGroupAverages = [];
  for (let start = 0; start < rowCount; start += 4) {
    const idx = Math.min(start + 3, rowCount - 1);
    const el = document.querySelector(`input[name="test_data[gsm_average][${idx}]"]`);
    if (el) {
      const v = parseFloat(el.value);
      if (!isNaN(v)) gsmGroupAverages.push(v);
    }
  }
  const gsmAllForStats = collectNumeric('gsm_calculated', true);
  const gsmAvg = computeAverage(gsmGroupAverages);
  let gsmSd = computePopulationSd(gsmAllForStats);
  if (gsmSd == null) gsmSd = computePopulationSd(gsmGroupAverages);
  
  setVal('input[name="summary[gsm_avg]"]', gsmAvg, 1);
  setVal('input[name="summary[gsm_sd]"]', gsmSd, 1);
  
  const gsmCvEl = document.querySelector('input[name="summary[gsm_cv]"]');
  if (gsmCvEl) {
    const baseAvg = gsmAllForStats.length ? computeAverage(gsmAllForStats) : gsmAvg;
    if (baseAvg && gsmSd != null && baseAvg !== 0) {
      const cv = (gsmSd / baseAvg) * 100;
      gsmCvEl.value = (Math.floor(cv * 100) / 100).toFixed(2);
    } else {
      gsmCvEl.value = '';
    }
  }
  
  if (gsmAllForStats.length) {
    setVal('input[name="summary[gsm_max]"]', Math.max(...gsmAllForStats), 1);
    setVal('input[name="summary[gsm_min]"]', Math.min(...gsmAllForStats), 1);
  }
  
  // Thickness calculations
  const thkGroupAverages = [];
  for (let start = 0; start < rowCount; start += 4) {
    const idx = Math.min(start + 3, rowCount - 1);
    const el = document.querySelector(`input[name="test_data[thickness_average][${idx}]"]`);
    if (el) {
      const v = parseFloat(el.value);
      if (!isNaN(v)) thkGroupAverages.push(v);
    }
  }
  const thkAllForStats = collectNumeric('thickness', true);
  const thkAvg = computeAverage(thkGroupAverages);
  let thkSd = computePopulationSd(thkAllForStats);
  if (thkSd == null) thkSd = computePopulationSd(thkGroupAverages);
  
  setVal('input[name="summary[thickness_avg]"]', thkAvg, 3);
  setVal('input[name="summary[thickness_sd]"]', thkSd, 3);
  
  const thkCvEl = document.querySelector('input[name="summary[thickness_cv]"]');
  if (thkCvEl) {
    const baseAvg = thkAllForStats.length ? computeAverage(thkAllForStats) : thkAvg;
    if (baseAvg && thkSd != null && baseAvg !== 0) {
      const cv = (thkSd / baseAvg) * 100;
      thkCvEl.value = (Math.floor(cv * 100) / 100).toFixed(2);
    } else {
      thkCvEl.value = '';
    }
  }
  
  if (thkAllForStats.length) {
    setVal('input[name="summary[thickness_max]"]', Math.max(...thkAllForStats), 3);
    setVal('input[name="summary[thickness_min]"]', Math.min(...thkAllForStats), 3);
  }
  
  // CBR Test
  const cbrForce = collectNumeric('cbr_force', true);
  const cbrDisp = collectNumeric('cbr_displacement', true);
  const cbrStats_force = computeStats(cbrForce);
  const cbrStats_disp = computeStats(cbrDisp);
  
  setVal('input[name="summary[cbr_force_avg]"]', cbrStats_force.avg, 1);
  setVal('input[name="summary[cbr_displacement_avg]"]', cbrStats_disp.avg, 1);
  setVal('input[name="summary[cbr_force_sd]"]', cbrStats_force.sd, 1);
  setVal('input[name="summary[cbr_displacement_sd]"]', cbrStats_disp.sd, 1);
  setVal('input[name="summary[cbr_force_cv]"]', cbrStats_force.cv, 2);
  setVal('input[name="summary[cbr_displacement_cv]"]', cbrStats_disp.cv, 2);
  setVal('input[name="summary[cbr_force_max]"]', cbrStats_force.max, 1);
  setVal('input[name="summary[cbr_force_min]"]', cbrStats_force.min, 1);
  setVal('input[name="summary[cbr_displacement_max]"]', cbrStats_disp.max, 1);
  setVal('input[name="summary[cbr_displacement_min]"]', cbrStats_disp.min, 1);
  
  // Grab Test
  const grabMdForce = [], grabMdElong = [], grabCdForce = [], grabCdElong = [];
  for (let i = 0; i < rowCount; i++) {
    const dirEl = document.querySelector(`select[name="test_data[grab_direction][${i}]"]`);
    const forceEl = document.querySelector(`input[name="test_data[grab_force][${i}]"]`);
    const elongEl = document.querySelector(`input[name="test_data[grab_elongation][${i}]"]`);
    const dir = dirEl ? dirEl.value.trim().toUpperCase() : '';
    const f = forceEl ? parseFloat(forceEl.value) : NaN;
    const e = elongEl ? parseFloat(elongEl.value) : NaN;
    if (!isNaN(f) && f > 0) (dir.startsWith('MD') ? grabMdForce : grabCdForce).push(f);
    if (!isNaN(e) && e > 0) (dir.startsWith('MD') ? grabMdElong : grabCdElong).push(e);
  }
  
  setVal('input[name="summary[grab_md_force_avg]"]', computeStats(grabMdForce).avg, 1);
  setVal('input[name="summary[grab_md_elongation_avg]"]', computeStats(grabMdElong).avg, 1);
  setVal('input[name="summary[grab_cd_force_avg]"]', computeStats(grabCdForce).avg, 1);
  setVal('input[name="summary[grab_cd_elongation_avg]"]', computeStats(grabCdElong).avg, 1);
  setVal('input[name="summary[grab_md_force_sd]"]', computeStats(grabMdForce).sd, 1);
  setVal('input[name="summary[grab_md_elongation_sd]"]', computeStats(grabMdElong).sd, 1);
  setVal('input[name="summary[grab_cd_force_sd]"]', computeStats(grabCdForce).sd, 1);
  setVal('input[name="summary[grab_cd_elongation_sd]"]', computeStats(grabCdElong).sd, 1);
  setVal('input[name="summary[grab_md_force_cv]"]', computeStats(grabMdForce).cv, 2);
  setVal('input[name="summary[grab_md_elongation_cv]"]', computeStats(grabMdElong).cv, 2);
  setVal('input[name="summary[grab_cd_force_cv]"]', computeStats(grabCdForce).cv, 2);
  setVal('input[name="summary[grab_cd_elongation_cv]"]', computeStats(grabCdElong).cv, 2);
  setVal('input[name="summary[grab_md_force_max]"]', computeStats(grabMdForce).max, 1);
  setVal('input[name="summary[grab_md_elongation_max]"]', computeStats(grabMdElong).max, 1);
  setVal('input[name="summary[grab_cd_force_max]"]', computeStats(grabCdForce).max, 1);
  setVal('input[name="summary[grab_cd_elongation_max]"]', computeStats(grabCdElong).max, 1);
  setVal('input[name="summary[grab_md_force_min]"]', computeStats(grabMdForce).min, 1);
  setVal('input[name="summary[grab_md_elongation_min]"]', computeStats(grabMdElong).min, 1);
  setVal('input[name="summary[grab_cd_force_min]"]', computeStats(grabCdForce).min, 1);
  setVal('input[name="summary[grab_cd_elongation_min]"]', computeStats(grabCdElong).min, 1);
  
  // Strip Tensile Test
  const stripMdStrength = [], stripMdElong = [], stripCdStrength = [], stripCdElong = [];
  for (let i = 0; i < rowCount; i++) {
    const dirEl = document.querySelector(`select[name="test_data[strip_direction][${i}]"]`);
    const strengthEl = document.querySelector(`input[name="test_data[strip_strength][${i}]"]`);
    const elongEl = document.querySelector(`input[name="test_data[strip_elongation][${i}]"]`);
    const dir = dirEl ? dirEl.value.trim().toUpperCase() : '';
    const s = strengthEl ? parseFloat(strengthEl.value) : NaN;
    const e = elongEl ? parseFloat(elongEl.value) : NaN;
    if (!isNaN(s) && s > 0) (dir.startsWith('MD') ? stripMdStrength : stripCdStrength).push(s);
    if (!isNaN(e) && e > 0) (dir.startsWith('MD') ? stripMdElong : stripCdElong).push(e);
  }
  
  setVal('input[name="summary[strip_md_strength_avg]"]', computeStats(stripMdStrength).avg, 1);
  setVal('input[name="summary[strip_md_elongation_avg]"]', computeStats(stripMdElong).avg, 1);
  setVal('input[name="summary[strip_cd_strength_avg]"]', computeStats(stripCdStrength).avg, 1);
  setVal('input[name="summary[strip_cd_elongation_avg]"]', computeStats(stripCdElong).avg, 1);
  setVal('input[name="summary[strip_md_strength_sd]"]', computeStats(stripMdStrength).sd, 1);
  setVal('input[name="summary[strip_md_elongation_sd]"]', computeStats(stripMdElong).sd, 1);
  setVal('input[name="summary[strip_cd_strength_sd]"]', computeStats(stripCdStrength).sd, 1);
  setVal('input[name="summary[strip_cd_elongation_sd]"]', computeStats(stripCdElong).sd, 1);
  setVal('input[name="summary[strip_md_strength_cv]"]', computeStats(stripMdStrength).cv, 2);
  setVal('input[name="summary[strip_md_elongation_cv]"]', computeStats(stripMdElong).cv, 2);
  setVal('input[name="summary[strip_cd_strength_cv]"]', computeStats(stripCdStrength).cv, 2);
  setVal('input[name="summary[strip_cd_elongation_cv]"]', computeStats(stripCdElong).cv, 2);
  setVal('input[name="summary[strip_md_strength_max]"]', computeStats(stripMdStrength).max, 1);
  setVal('input[name="summary[strip_md_elongation_max]"]', computeStats(stripMdElong).max, 1);
  setVal('input[name="summary[strip_cd_strength_max]"]', computeStats(stripCdStrength).max, 1);
  setVal('input[name="summary[strip_cd_elongation_max]"]', computeStats(stripCdElong).max, 1);
  setVal('input[name="summary[strip_md_strength_min]"]', computeStats(stripMdStrength).min, 1);
  setVal('input[name="summary[strip_md_elongation_min]"]', computeStats(stripMdElong).min, 1);
  setVal('input[name="summary[strip_cd_strength_min]"]', computeStats(stripCdStrength).min, 1);
  setVal('input[name="summary[strip_cd_elongation_min]"]', computeStats(stripCdElong).min, 1);
}
</script>
</body>
</html>


