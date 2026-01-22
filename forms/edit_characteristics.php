<?php
session_start();
require_once 'security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();
$reporter_id = $_SESSION['user_id'];
$reporter_name = $_SESSION['username'];

// Fetch full name from database
$reporter_full_name = $reporter_name;
try {
    $stmt = $conn->prepare("SELECT full_name FROM users WHERE username = ?");
    $stmt->bind_param("s", $reporter_name);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $reporter_full_name = $row['full_name'] ?: $reporter_name;
    }
    $stmt->close();
} catch (Exception $e) {
    $reporter_full_name = $reporter_name;
}

// Check user role - only testers can access this page
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$is_checker = ($user_role === 'checker');
$can_approve = in_array($user_role, ['admin', 'agm ops', 'agm operations'], true);

if ($is_checker || $can_approve) {
    die('Access denied. Only testers can edit rejected tests.');
}

$report_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($report_id <= 0) {
    die('Invalid report ID');
}

$message = '';
$error = '';

// Fetch the rejected report (must belong to this user and be rejected)
$stmt = $conn->prepare("SELECT * FROM characteristics_tests WHERE id = ? AND reporter_id = ? AND status = 'rejected'");
$stmt->bind_param("ii", $report_id, $reporter_id);
$stmt->execute();
$result = $stmt->get_result();
$report = $result->fetch_assoc();
$stmt->close();

if (!$report) {
    die('Report not found or you do not have permission to edit it.');
}

// Decode test data JSON
$test_data = json_decode($report['test_results'], true);

// Filter out empty sieve rows - only include rows with actual data
if (isset($test_data['sieve_data']) && is_array($test_data['sieve_data'])) {
    $filtered_sieve_data = [];
    foreach ($test_data['sieve_data'] as $sieve) {
        // Only include rows that have at least sieve_size or retained > 0
        if ((isset($sieve['sieve_size']) && floatval($sieve['sieve_size']) > 0) || 
            (isset($sieve['retained']) && floatval($sieve['retained']) > 0)) {
            $filtered_sieve_data[] = $sieve;
        }
    }
    $test_data['sieve_data'] = $filtered_sieve_data;
}

// Clean rejection remarks
$rejection_reason = $report['remarks'] ?? 'No comments';
$rejection_reason = preg_replace('/\[Checker Rejection\]:\s*/i', '', $rejection_reason);
$rejection_reason = preg_replace('/\[Admin Rejection\]:\s*/i', '', $rejection_reason);
$rejection_reason = trim($rejection_reason);

$rejected_by = $report['checker_name'] ?? $report['approver_name'] ?? 'Unknown';

// Use the SAME lab test number from the rejected report (not a new one)
$next_lab_test_no = $report['lab_test_number'];

// Get reference number from the rejected report - auto-fetch for resubmission
$original_reference_number = $report['reference_number'] ?? '';
$original_bundle_reference = $report['bundle_reference'] ?? '';

// If reference_number is truncated or too short, try to get full reference from roll_entry
if (empty($original_reference_number) || strlen(trim($original_reference_number)) <= 3) {
    if (!empty($original_bundle_reference)) {
        // Use bundle_reference if available
        $original_reference_number = $original_bundle_reference;
    } else {
        // Try to find full reference from roll_entry
        $refLookup = $conn->prepare("SELECT reference_number FROM roll_entry WHERE reference_number LIKE ? OR reference_number = ? LIMIT 1");
        if ($refLookup) {
            $searchPattern = '%' . $original_reference_number . '%';
            $refLookup->bind_param("ss", $searchPattern, $original_reference_number);
            $refLookup->execute();
            $refResult = $refLookup->get_result();
            if ($refRow = $refResult->fetch_assoc()) {
                $original_reference_number = $refRow['reference_number'];
            }
            $refLookup->close();
        }
    }
}

// Handle form submission - create NEW test with corrected data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->begin_transaction();
        
        // Use the SAME lab test number from the rejected report
        $generated_lab_test_no = $report['lab_test_number'];
        $test_date = date('Y-m-d');
        
        $test_date_obj = new DateTime($test_date);
        $year = $test_date_obj->format('y');
        $month = strtoupper($test_date_obj->format('M'));
        $day = $test_date_obj->format('d');
        $lab_test_formatted = 'LT' . $generated_lab_test_no;
        // Generate report number without GSM and Roll Number
        $report_number = "CT-{$year}{$month}{$day}-{$lab_test_formatted}";
        
        // Collect sieve analysis data (dynamic rows)
        $sieve_data = [];
        $i = 1;
        while (isset($_POST["sieve_size_$i"]) || isset($_POST["retained_$i"])) {
            if (!empty($_POST["sieve_size_$i"]) || !empty($_POST["retained_$i"])) {
                $sieve_data[] = [
                    'sieve_size' => floatval($_POST["sieve_size_$i"] ?? 0),
                    'retained' => floatval($_POST["retained_$i"] ?? 0),
                    'cumulative' => floatval($_POST["cumulative_$i"] ?? 0),
                    'passing' => floatval($_POST["passing_$i"] ?? 0)
                ];
            }
            $i++;
            if ($i > 50) break; // Safety limit
        }
        
        $test_results = [
            'sieve_data' => $sieve_data,
            'o_value' => floatval($_POST['o_value'] ?? 0),
            'opening_size' => floatval($_POST['opening_size'] ?? 0),
            'remarks' => trim($_POST['remarks'] ?? '')
        ];
        
        $test_results_json = json_encode($test_results);
        
        // Check if this report has a bundle_reference with pipe separator (range format: from|to)
        $bundle_ref = trim($original_bundle_reference ?? '');
        $reports_to_update = [$report_id];
        
        // If bundle_reference exists and contains a pipe (|), find all reports with the same bundle_reference
        if (!empty($bundle_ref) && strpos($bundle_ref, '|') !== false) {
            // Find all reports with the same bundle_reference that are rejected
            $findBulkStmt = $conn->prepare("SELECT id FROM characteristics_tests WHERE bundle_reference = ? AND status IN ('rejected', 'rejected_by_checker') AND id != ?");
            $findBulkStmt->bind_param("si", $bundle_ref, $report_id);
            $findBulkStmt->execute();
            $bulkResult = $findBulkStmt->get_result();
            while ($bulkRow = $bulkResult->fetch_assoc()) {
                $reports_to_update[] = $bulkRow['id'];
            }
            $findBulkStmt->close();
            
            error_log("Characteristics: Resubmitting bulk reference range: $bundle_ref. Found " . count($reports_to_update) . " reports to update.");
        }
        
        // Update all reports in the range to 'pending' status
        $placeholders = str_repeat('?,', count($reports_to_update) - 1) . '?';
        $updateStmt = $conn->prepare("UPDATE characteristics_tests SET status = 'pending', remarks = NULL, checker_name = NULL, checked_at = NULL, approver_name = NULL, approved_at = NULL, updated_at = NOW() WHERE id IN ($placeholders)");
        $types = str_repeat('i', count($reports_to_update));
        $updateStmt->bind_param($types, ...$reports_to_update);
        $updateStmt->execute();
        $updateStmt->close();
        
        // Use the auto-fetched reference number from the rejected report
        $reference_number = $original_reference_number;
        
        // Update the existing report with new test data (instead of creating a new one)
        $sample_tested = date('Y-m-d H:i:s');
        
        // Convert numeric fields to floats for proper database binding
        $specimen_size = isset($_POST['specimen_size']) && is_numeric($_POST['specimen_size']) ? floatval($_POST['specimen_size']) : 0.0;
        $sand_weight = isset($_POST['sand_weight']) && is_numeric($_POST['sand_weight']) ? floatval($_POST['sand_weight']) : 0.0;
        $sieving_time = isset($_POST['sieving_time']) && is_numeric($_POST['sieving_time']) ? floatval($_POST['sieving_time']) : 0.0;
        
        $stmt = $conn->prepare(
            "UPDATE characteristics_tests 
            SET test_materials = ?, 
                reference_number = ?,
                sample_id = ?,
                specimen_size = ?,
                specimen_size_unit = ?,
                sand_type = ?,
                sand_weight = ?,
                sand_weight_unit = ?,
                sieving_time = ?,
                sieving_time_unit = ?,
                sample_received = ?,
                sample_tested = ?,
                test_results = ?,
                test_performed_by = ?,
                updated_at = NOW()
            WHERE id = ?"
        );
        
        $stmt->bind_param(
            "sssdssdsssssssi",
            $_POST['test_materials'],          // 1. s - test_materials
            $reference_number,                 // 2. s - reference_number (auto-fetched)
            $_POST['sample_id'],               // 3. s - sample_id
            $specimen_size,                    // 4. d - specimen_size (DECIMAL)
            $_POST['specimen_size_unit'],      // 5. s - specimen_size_unit
            $_POST['sand_type'],               // 6. s - sand_type
            $sand_weight,                      // 7. d - sand_weight (DECIMAL)
            $_POST['sand_weight_unit'],        // 8. s - sand_weight_unit
            $sieving_time,                     // 9. d - sieving_time (DECIMAL)
            $_POST['sieving_time_unit'],       // 10. s - sieving_time_unit
            $_POST['sample_received'],         // 11. s - sample_received
            $sample_tested,                    // 12. s - sample_tested
            $test_results_json,                // 13. s - test_results
            $reporter_full_name,               // 14. s - test_performed_by
            $report_id                         // 15. i - id
        );
        
        if ($stmt->execute()) {
            $stmt->close();
            $conn->commit();
            $count = count($reports_to_update);
            $message = $count > 1 
                ? "Test resubmitted successfully! {$count} reports in the reference range have been resubmitted. Status: Pending"
                : "Test resubmitted successfully! Report Number: " . $report['report_number'] . " - Status: Pending";
            $_SESSION['success_message'] = $message;
            header("Location: ../tester_rejected_reports.php");
            exit();
        } else {
            $stmt->close();
            $conn->rollback();
            throw new Exception("Failed to update test: " . $stmt->error);
        }
        $stmt->close();
        
    } catch (Exception $e) {
        $conn->rollback();
        $error = "Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit & Resubmit Characteristics Test</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:5px 10px 5px 5px; color:#2c3e50; }
  .container { max-width:100%; margin:0; margin-left:0; background:#fff; border-radius:8px; padding:15px 25px 15px 10px; box-shadow:0 2px 10px rgba(0,0,0,0.08);} 
  h1 { text-align:center; font-size:28px; margin-bottom:30px; color:#2c3e50; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; font-size:12px; }
  input[type="text"], input[type="number"], input[type="date"], input[type="datetime-local"], select, textarea { 
    padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px); 
  }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .readonly { background:#ecf0f1; }
  .test-table { width:100%; border-collapse:collapse; margin-top:15px; font-size:12px; }
  .test-table th, .test-table td { border:1px solid #ddd; padding:6px; text-align:center; }
  .test-table th { background:#3498db; color:#fff; font-weight:600; }
  .test-table input { width:70px; border:none; background:transparent; text-align:center; padding:4px; }
  .form-row { display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap:12px; margin-bottom:20px; }
  .alert { padding:12px; border-radius:6px; margin-bottom:15px; }
  .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
  .alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
  .alert-warning { background:#fff3cd; color:#856404; border:1px solid #ffeeba; }
  .section-title { font-size:16px; font-weight:700; margin:25px 0 15px 0; padding:10px; background:#e9ecef; border-left:4px solid #3498db; }
  .calc-section { margin-top:20px; padding:15px; border:2px solid #27ae60; border-radius:8px; background:#d5f4e6; }
  .calc-btn { padding:8px 16px; background:#27ae60; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:bold; }
  .result-box { background:#e8f5e9; padding:15px; border-radius:6px; margin-top:10px; border:2px solid #4caf50; }
</style>
</head>
<body>
<div class="container">
  <h1>✏️ Edit & Resubmit Characteristics Test</h1>

  <?php if ($message): ?>
    <div class="alert alert-success"> <?php echo $message; ?></div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-error"> <?php echo $error; ?></div>
  <?php endif; ?>

  <div style="margin-bottom: 15px;">
    <a href="characteristics_test.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
  </div>

  <div class="alert alert-warning">
    <strong>⚠️ Test was rejected by: <?php echo htmlspecialchars($rejected_by); ?></strong><br>
    <strong>Reason:</strong> <?php echo htmlspecialchars($rejection_reason); ?><br>
    <em>Please make corrections and resubmit.</em>
  </div>

  <form method="POST" action="">
    
    <!-- Date/Time and Shift Display -->
    <div id="dateTimeDisplay" class="summary-info"></div>
    <div id="shiftBanner" class="summary-info"></div>

    <div class="form-row">
      <div class="form-group">
        <label>Test Materials:</label>
        <input type="text" name="test_materials" value="<?php echo htmlspecialchars($report['test_materials']); ?>" required>
      </div>
      <div class="form-group">
        <label>Reference Number:</label>
        <input type="text" value="<?php echo htmlspecialchars($original_reference_number ?: 'Not available'); ?>" readonly class="readonly" style="background:#e8f5e9; border:2px solid #4caf50;">
        <small style="color:#666; font-size:11px;">Auto-fetched from rejected report</small>
      </div>
      <div class="form-group">
        <label>Report ID:</label>
        <input type="text" name="sample_id" id="report_id" value="<?php echo htmlspecialchars($report['sample_id']); ?>" readonly class="readonly">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Specimens Size:</label>
        <div style="display:flex; gap:5px;">
          <input type="number" name="specimen_size" step="0.0001" min="0" value="<?php echo rtrim(rtrim(number_format($report['specimen_size'], 4), '0'), '.'); ?>" required style="flex:1;">
          <select name="specimen_size_unit" style="width:70px;">
            <option value="mm" <?php echo ($report['specimen_size_unit'] === 'mm') ? 'selected' : ''; ?>>mm</option>
            <option value="mm2" <?php echo ($report['specimen_size_unit'] === 'mm2') ? 'selected' : ''; ?>>mm²</option>
            <option value="cm" <?php echo ($report['specimen_size_unit'] === 'cm') ? 'selected' : ''; ?>>cm</option>
            <option value="m" <?php echo ($report['specimen_size_unit'] === 'm') ? 'selected' : ''; ?>>m</option>
            <option value="in" <?php echo ($report['specimen_size_unit'] === 'in') ? 'selected' : ''; ?>>in</option>
            <option value="ft" <?php echo ($report['specimen_size_unit'] === 'ft') ? 'selected' : ''; ?>>ft</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Sand Type:</label>
        <input type="text" name="sand_type" value="<?php echo htmlspecialchars($report['sand_type']); ?>" required>
      </div>
      <div class="form-group">
        <label>Total Sand Weight:</label>
        <div style="display:flex; gap:5px;">
          <input type="number" name="sand_weight" step="0.0001" min="0" value="<?php echo rtrim(rtrim(number_format($report['sand_weight'], 4), '0'), '.'); ?>" required style="flex:1;" id="sand_weight" oninput="calculateSieve()">
          <select name="sand_weight_unit" style="width:70px;">
            <option value="g" <?php echo ($report['sand_weight_unit'] === 'g') ? 'selected' : ''; ?>>g</option>
            <option value="kg" <?php echo ($report['sand_weight_unit'] === 'kg') ? 'selected' : ''; ?>>kg</option>
            <option value="mg" <?php echo ($report['sand_weight_unit'] === 'mg') ? 'selected' : ''; ?>>mg</option>
            <option value="lb" <?php echo ($report['sand_weight_unit'] === 'lb') ? 'selected' : ''; ?>>lb</option>
            <option value="oz" <?php echo ($report['sand_weight_unit'] === 'oz') ? 'selected' : ''; ?>>oz</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Sieving Time:</label>
        <div style="display:flex; gap:5px;">
          <input type="number" name="sieving_time" step="0.0001" min="0" value="<?php echo rtrim(rtrim(number_format($report['sieving_time'], 4), '0'), '.'); ?>" required style="flex:1;">
          <select name="sieving_time_unit" style="width:70px;">
            <option value="min" <?php echo ($report['sieving_time_unit'] === 'min') ? 'selected' : ''; ?>>min</option>
            <option value="s" <?php echo ($report['sieving_time_unit'] === 's') ? 'selected' : ''; ?>>s</option>
            <option value="h" <?php echo ($report['sieving_time_unit'] === 'h') ? 'selected' : ''; ?>>h</option>
          </select>
        </div>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Sample Received:</label>
        <input type="date" name="sample_received" value="<?php echo htmlspecialchars($report['sample_received']); ?>" required>
      </div>
    </div>

    <div class="section-title">📊 Sieve Analysis Data</div>
    <div style="overflow-x:auto;">
      <table class="test-table">
        <thead>
          <tr>
            <th>Sieve Size (mm)</th>
            <th>Retained (g)</th>
            <th>Cumulative (g)</th>
            <th>Cumulative Passing (%)</th>
            <th style="width:80px;">Action</th>
          </tr>
        </thead>
        <tbody id="sieve_tbody">
          <?php 
          $initial_sieve_count = count($test_data['sieve_data'] ?? []);
          if ($initial_sieve_count === 0) {
              $initial_sieve_count = 1; // Ensure at least one row is displayed
          }
          for ($i = 0; $i < $initial_sieve_count; $i++): 
            $sieve = $test_data['sieve_data'][$i] ?? ['sieve_size' => 0, 'retained' => 0, 'cumulative' => 0, 'passing' => 0];
            $row_num = $i + 1;
          ?>
          <tr data-row="<?php echo $row_num; ?>">
            <td><input type="number" name="sieve_size_<?php echo $row_num; ?>" id="sieve_size_<?php echo $row_num; ?>" step="0.0001" min="0" value="<?php echo rtrim(rtrim(sprintf('%.4f', $sieve['sieve_size']), '0'), '.'); ?>"></td>
            <td><input type="number" name="retained_<?php echo $row_num; ?>" id="retained_<?php echo $row_num; ?>" step="0.0001" min="0" value="<?php echo rtrim(rtrim(sprintf('%.4f', $sieve['retained']), '0'), '.'); ?>" oninput="calculateSieve()"></td>
            <td><input type="number" name="cumulative_<?php echo $row_num; ?>" id="cumulative_<?php echo $row_num; ?>" readonly class="readonly" value="<?php echo rtrim(rtrim(sprintf('%.4f', $sieve['cumulative']), '0'), '.'); ?>"></td>
            <td><input type="number" name="passing_<?php echo $row_num; ?>" id="passing_<?php echo $row_num; ?>" readonly class="readonly" value="<?php echo rtrim(rtrim(sprintf('%.2f', $sieve['passing']), '0'), '.'); ?>"></td>
            <td>
              <?php if ($initial_sieve_count > 1 || $row_num > 1): ?>
              <button type="button" onclick="removeSieveRow(this)" style="padding:4px 8px; background:#dc3545; color:#fff; border:none; cursor:pointer; border-radius:4px;">Delete</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endfor; ?>
        </tbody>
      </table>
      <button type="button" onclick="addSieveRow()" style="margin-top:10px; padding:8px 16px; background:#28a745; color:#fff; border:none; cursor:pointer; border-radius:4px; font-weight:600;">Add 1 More Row</button>
    </div>

    <div class="section-title">Opening Size Calculation (AOS)</div>
    <div class="calc-section">
      <div style="margin-bottom:15px;">
        <label style="font-size:14px; font-weight:bold; margin-bottom:8px; display:block;">Enter O-Value (%):</label>
        <div style="display:flex; gap:10px; align-items:center;">
          <input type="number" id="o_value_input" name="o_value" value="<?php echo htmlspecialchars($test_data['o_value'] ?? 0); ?>" placeholder="e.g., 90" min="0" max="100" step="0.1" style="width:100px; padding:8px; border:1px solid #ddd; border-radius:4px;">
          <button type="button" onclick="calculateOpening()" class="calc-btn">Calculate</button>
        </div>
      </div>
      <div id="calculation_display" style="display:<?php echo (!empty($test_data['opening_size'])) ? 'block' : 'none'; ?>; margin-top:15px;">
        <h4 style="margin:10px 0; color:#2c3e50; font-size:15px;">Calculation:</h4>
        <div id="calculation_text" style="background:#fff; padding:12px; border-radius:6px; border:1px solid #27ae60; line-height:1.8; font-size:14px;"></div>
      </div>
      <div id="result_display" style="display:<?php echo (!empty($test_data['opening_size'])) ? 'block' : 'none'; ?>; margin-top:15px;">
        <h4 style="margin:10px 0; color:#2c3e50; font-size:15px;">Results:</h4>
        <div class="result-box">
          <div id="final_result" style="font-size:16px; font-weight:bold; color:#27ae60;">
            <?php if (!empty($test_data['opening_size'])): ?>
              Apparent Opening Size (O<?php echo rtrim(rtrim(number_format($test_data['o_value'], 1), '0'), '.'); ?>): <?php echo rtrim(rtrim(number_format($test_data['opening_size'], 3), '0'), '.'); ?> mm (<?php echo round($test_data['opening_size'] * 1000); ?> μm)
            <?php endif; ?>
          </div>
        </div>
        <input type="hidden" name="opening_size" id="opening_size" value="<?php echo htmlspecialchars($test_data['opening_size'] ?? 0); ?>">
      </div>
    </div>

    <div class="section-title"> Remarks</div>
    <div class="form-group">
      <label>Remarks (Optional):</label>
      <textarea name="remarks" rows="3"><?php echo htmlspecialchars($test_data['remarks'] ?? ''); ?></textarea>
    </div>

    <div class="actions">
      <button type="submit" class="submit-btn">Resubmit Test</button>
      <button type="button" onclick="window.location.href='characteristics_test.php'" class="clear-btn">Cancel</button>
    </div>
  </form>
</div>

<script>
// Update date/time and shift
function updateTimeBD() {
  const now = new Date();
  const utc = now.getTime() + now.getTimezoneOffset() * 60000;
  const dhaka = new Date(utc + 6 * 3600000);
  
  document.getElementById("dateTimeDisplay").innerHTML = 
    "Date & Time: " + dhaka.toDateString() + " " + dhaka.toLocaleTimeString();
  
  const h = dhaka.getHours();
  const shift = (h >= 8 && h < 20) ? "Day" : "Night";
  document.getElementById("shiftBanner").innerText = "Shift: " + shift;
}

setInterval(updateTimeBD, 1000);
updateTimeBD();

// Report ID is already set from the rejected report, no need to regenerate

// Calculate sieve analysis
function calculateSieve() {
  const totalSand = parseFloat(document.getElementById('sand_weight').value) || 0;
  let cumulative = 0;
  
  const tbody = document.getElementById('sieve_tbody');
  if (!tbody) return;
  
  const rows = tbody.querySelectorAll('tr');
  rows.forEach(row => {
    const rowNum = row.getAttribute('data-row');
    const retainedEl = document.getElementById(`retained_${rowNum}`);
    const cumulativeEl = document.getElementById(`cumulative_${rowNum}`);
    const passingEl = document.getElementById(`passing_${rowNum}`);
    
    if (retainedEl && cumulativeEl && passingEl) {
      const retained = parseFloat(retainedEl.value) || 0;
      cumulative += retained;
      
      cumulativeEl.value = cumulative.toFixed(4);
      
      if (totalSand > 0) {
        const passing = ((totalSand - cumulative) / totalSand) * 100;
        passingEl.value = passing.toFixed(2);
      } else {
        passingEl.value = '';
      }
    }
  });
}

// Add sieve row
let sieveRowCounter = <?php echo $initial_sieve_count; ?>;
function addSieveRow() {
    const tbody = document.getElementById('sieve_tbody');
    sieveRowCounter++;
    const row = document.createElement('tr');
    row.setAttribute('data-row', sieveRowCounter);
    row.innerHTML = `
        <td><input type="number" name="sieve_size_${sieveRowCounter}" id="sieve_size_${sieveRowCounter}" step="0.0001" min="0"></td>
        <td><input type="number" name="retained_${sieveRowCounter}" id="retained_${sieveRowCounter}" step="0.0001" min="0" oninput="calculateSieve()"></td>
        <td><input type="number" name="cumulative_${sieveRowCounter}" id="cumulative_${sieveRowCounter}" readonly class="readonly"></td>
        <td><input type="number" name="passing_${sieveRowCounter}" id="passing_${sieveRowCounter}" readonly class="readonly"></td>
        <td><button type="button" onclick="removeSieveRow(this)" style="padding:4px 8px; background:#dc3545; color:#fff; border:none; cursor:pointer; border-radius:4px;">Delete</button></td>
    `;
    tbody.appendChild(row);
}

// Remove sieve row
function removeSieveRow(button) {
    const row = button.closest('tr');
    const tbody = row.closest('tbody');
    
    // Check if this is the only row
    if (tbody.querySelectorAll('tr').length <= 1) {
        alert('You cannot remove the last row. At least one row is required.');
        return;
    }
    
    row.remove();
    
    // Renumber remaining rows
    const rows = tbody.querySelectorAll('tr');
    rows.forEach((r, idx) => {
        const rowNum = idx + 1;
        r.setAttribute('data-row', rowNum);
        
        // Update all input IDs and names
        r.querySelectorAll('input, button').forEach(el => {
            const id = el.id;
            const name = el.name;
            if (id) {
                const newId = id.replace(/\d+$/, rowNum);
                el.id = newId;
            }
            if (name) {
                const newName = name.replace(/\d+$/, rowNum);
                el.name = newName;
            }
            // Update oninput calls
            if (el.hasAttribute('oninput')) {
                el.setAttribute('oninput', el.getAttribute('oninput'));
            }
        });
    });
    
    sieveRowCounter = rows.length;
    calculateSieve();
}

// Calculate opening size based on O-value - using same logic as main form
function calculateOpening() {
  // Ensure sieve calculations are done first
  calculateSieve();
  
  const oValue = parseFloat(document.getElementById('o_value_input').value);
  
  if (!oValue || oValue < 0 || oValue > 100) {
    alert('Please enter a valid O-value between 0 and 100');
    return;
  }
  
  // Find interpolated sieve size for the given O-value
  // Passing decreases as sieve size decreases, so we need upper (higher %) and lower (lower %)
  let upperSize = 0, lowerSize = 0, upperPass = 0, lowerPass = 0;
  let found = false;
  
  // Get all rows dynamically
  const tbody = document.getElementById('sieve_tbody');
  if (!tbody) {
    alert('Error: Sieve data table not found');
    return;
  }
  
  const rows = Array.from(tbody.querySelectorAll('tr'));
  if (rows.length === 0) {
    alert('Error: No sieve data rows found. Please add sieve data first.');
    return;
  }
  
  // Collect all sieve data with their row numbers
  const sieveData = [];
  rows.forEach(row => {
    const rowNum = row.getAttribute('data-row');
    if (!rowNum) return;
    
    const sieveSizeEl = document.getElementById(`sieve_size_${rowNum}`);
    const passingEl = document.getElementById(`passing_${rowNum}`);
    
    if (sieveSizeEl && passingEl) {
      const sieveSize = parseFloat(sieveSizeEl.value) || 0;
      const passing = parseFloat(passingEl.value) || 0;
      if (sieveSize > 0) {
        sieveData.push({ size: sieveSize, passing: passing, rowNum: rowNum });
      }
    }
  });
  
  if (sieveData.length === 0) {
    alert('Error: No valid sieve data found. Please enter sieve sizes and ensure retained values are entered.');
    return;
  }
  
  // Sort by sieve size descending (largest first), same as main form logic
  sieveData.sort((a, b) => b.size - a.size);
  
  // Loop through sorted data to find where passing crosses O-value
  // This mimics the main form's loop from 1 to 8
  for (let i = 0; i < sieveData.length; i++) {
    const passing = sieveData[i].passing;
    const sieveSize = sieveData[i].size;
    
    if (passing <= oValue) {
      // Found the lower bound (passing just below O-value)
      lowerSize = sieveSize;
      lowerPass = passing;
      
      // Get upper bound from previous row (passing just above O-value)
      if (i > 0) {
        upperSize = sieveData[i-1].size;
        upperPass = sieveData[i-1].passing;
        found = true;
      } else {
        // O-value is higher than the first sieve's passing %
        // Use the first sieve size as the result
        upperSize = sieveSize;
        upperPass = passing;
        found = true;
      }
      break;
    }
  }
  
  // If O-value is lower than all sieves, use the last sieve
  if (!found && lowerSize === 0) {
    for (let i = sieveData.length - 1; i >= 0; i--) {
      const sieveSize = sieveData[i].size;
      const passing = sieveData[i].passing;
      if (sieveSize > 0) {
        lowerSize = sieveSize;
        lowerPass = passing;
        found = true;
        break;
      }
    }
  }
  
  let openingSize = 0;
  let calculationHTML = '';
  
  if (upperPass > 0 && lowerPass >= 0 && upperSize > 0 && lowerSize > 0 && upperPass > lowerPass) {
    // Linear interpolation: O = lowerSize + (oValue - lowerPass)/(upperPass - lowerPass) * (upperSize - lowerSize)
    openingSize = lowerSize + ((oValue - lowerPass) / (upperPass - lowerPass)) * (upperSize - lowerSize);
    
    // Build calculation text
    calculationHTML = `
      O${oValue} lies between sieve size ${upperSize} mm (${upperPass.toFixed(1)}% passing) and ${lowerSize} mm (${lowerPass.toFixed(1)}% passing)<br><br>
      <strong>Interpolation formula:</strong><br>
      O${oValue} = ${lowerSize} + ((${oValue} - ${lowerPass.toFixed(1)}) / (${upperPass.toFixed(1)} - ${lowerPass.toFixed(1)})) × (${upperSize} - ${lowerSize})<br><br>
      O${oValue} = ${openingSize.toFixed(3)} mm (${(openingSize * 1000).toFixed(0)} μm)
    `;
  } else if (upperSize > 0 && lowerSize === 0) {
    // O-value is higher than all sieves
    openingSize = upperSize;
    calculationHTML = `O${oValue} is higher than all sieve passing percentages.<br>Using largest sieve size: ${openingSize.toFixed(3)} mm (${(openingSize * 1000).toFixed(0)} μm)`;
  } else if (lowerSize > 0 && upperSize === 0) {
    // O-value is lower than all sieves
    openingSize = lowerSize;
    calculationHTML = `O${oValue} is lower than all sieve passing percentages.<br>Using smallest sieve size: ${openingSize.toFixed(3)} mm (${(openingSize * 1000).toFixed(0)} μm)`;
  } else if (upperSize > 0) {
    openingSize = upperSize;
    calculationHTML = `O${oValue} = ${openingSize.toFixed(3)} mm (${(openingSize * 1000).toFixed(0)} μm)`;
  } else if (lowerSize > 0) {
    openingSize = lowerSize;
    calculationHTML = `O${oValue} = ${openingSize.toFixed(3)} mm (${(openingSize * 1000).toFixed(0)} μm)`;
  }
  
  // Update hidden field with calculated opening size
  const openingSizeField = document.getElementById('opening_size');
  if (openingSizeField) {
    openingSizeField.value = openingSize.toFixed(4);
  }
  
  // Update calculation display
  const calculationText = document.getElementById('calculation_text');
  const calculationDisplay = document.getElementById('calculation_display');
  if (calculationText && calculationDisplay) {
    calculationText.innerHTML = calculationHTML;
    calculationDisplay.style.display = 'block';
  }
  
  // Update result display
  const finalResult = document.getElementById('final_result');
  const resultDisplay = document.getElementById('result_display');
  if (finalResult && resultDisplay) {
    finalResult.innerHTML = 
    `Apparent Opening Size (O${oValue}): ${openingSize.toFixed(3)} mm (${(openingSize * 1000).toFixed(0)} μm)`;
    resultDisplay.style.display = 'block';
  }
}

// Trigger initial calculation on load
calculateSieve();

// If there's an O-value already, recalculate to show the formula
<?php if (!empty($test_data['o_value'])): ?>
setTimeout(function() {
  calculateOpening();
}, 100);
<?php endif; ?>

// Add event listener to O-value input to auto-calculate on Enter key or when value changes
document.addEventListener('DOMContentLoaded', function() {
  const oValueInput = document.getElementById('o_value_input');
  if (oValueInput) {
    // Auto-calculate when Enter is pressed
    oValueInput.addEventListener('keypress', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        calculateOpening();
      }
    });
    
    // Optional: Auto-calculate when value changes (uncomment if desired)
    // oValueInput.addEventListener('input', function() {
    //   if (this.value && parseFloat(this.value) >= 0 && parseFloat(this.value) <= 100) {
    //     calculateOpening();
    //   }
    // });
  }
});
</script>
</body>
</html>
<?php $conn->close(); ?>


