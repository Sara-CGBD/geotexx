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
$stmt = $conn->prepare("SELECT * FROM sun_test_reports WHERE id = ?");
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
        // Prepare test results data (dynamic specimens)
        $test_results_data = [];
        $i = 1;
        while (isset($_POST["breaking_force_after_$i"]) || isset($_POST["breaking_force_before_$i"]) || 
               isset($_POST["test_direction_$i"])) {
            if (!empty($_POST["breaking_force_after_$i"]) || !empty($_POST["breaking_force_before_$i"]) || 
                !empty($_POST["test_direction_$i"])) {
                $test_results_data[] = [
                    'specimen_no' => count($test_results_data) + 1,
                    'test_direction' => $_POST["test_direction_$i"] ?? '',
                    'breaking_force_after' => $_POST["breaking_force_after_$i"] ?? '',
                    'breaking_force_before' => $_POST["breaking_force_before_$i"] ?? '',
                    'force_retain' => $_POST["force_retain_$i"] ?? '',
                    'elongation_after' => $_POST["elongation_after_$i"] ?? '',
                    'elongation_before' => $_POST["elongation_before_$i"] ?? ''
                ];
            }
            $i++;
            if ($i > 50) break; // Safety limit
        }
        $test_results_json = json_encode($test_results_data);
        
        // Update the report and reset status to pending
        $updateStmt = $conn->prepare("
            UPDATE sun_test_reports 
            SET reference_name = ?, lab_test_number = ?,
                sample_description = ?, sample_received_from = ?, received_date = ?,
                test_start_date = ?, test_end_date = ?, test_speed = ?, gauge_length = ?,
                specimen_size = ?, note = ?, temperature = ?, rh_percent = ?,
                test_results = ?, status = 'pending', approved_by = NULL, remarks = NULL
            WHERE id = ?
        ");
        
        $updateStmt->bind_param(
            "ssssssssssssssi",
            $_POST['reference_name'],
            $_POST['lab_test_number'],
            $_POST['sample_description'],
            $_POST['sample_received_from'],
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
            header("Location: ../tester_rejected_reports.php");
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
// Filter out empty rows - only include rows with actual data
$filtered_test_results = [];
foreach ($test_results as $tr) {
    // Only include rows that have at least one meaningful value (not empty, not zero, not just whitespace)
    $has_force_after = isset($tr['breaking_force_after']) && trim($tr['breaking_force_after']) !== '' && floatval($tr['breaking_force_after']) != 0;
    $has_force_before = isset($tr['breaking_force_before']) && trim($tr['breaking_force_before']) !== '' && floatval($tr['breaking_force_before']) != 0;
    $has_elongation_after = isset($tr['elongation_after']) && trim($tr['elongation_after']) !== '' && floatval($tr['elongation_after']) != 0;
    $has_elongation_before = isset($tr['elongation_before']) && trim($tr['elongation_before']) !== '' && floatval($tr['elongation_before']) != 0;
    $has_direction = !empty($tr['test_direction']) && trim($tr['test_direction']) !== '';
    
    if ($has_force_after || $has_force_before || $has_elongation_after || $has_elongation_before || $has_direction) {
        $filtered_test_results[] = $tr;
    }
}
// Use filtered results instead of all results
$test_results = $filtered_test_results;

$specimen_data = [];
foreach ($test_results as $tr) {
    $specimen_data[$tr['specimen_no']] = $tr;
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Edit Rejected Report - <?php echo htmlspecialchars($report['report_number']); ?></title>
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
  .dir-btn { padding:4px 8px; border:1px solid #ccc; background:#fff; border-radius:4px; cursor:pointer; font-size:11px; margin:0 2px; }
  .dir-btn.active { background:#3498db; color:#fff; border-color:#3498db; }
</style>
</head>
<body>
<div class="container">
  <h1>Edit Rejected Sun Test Report</h1>
  
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
        <label>Lab Test Number:</label>
        <input type="text" name="lab_test_number" value="<?php echo htmlspecialchars($report['lab_test_number']); ?>" readonly class="readonly">
      </div>
      <div class="form-group">
        <label>Sample Description:</label>
        <input type="text" name="sample_description" value="<?php echo htmlspecialchars($report['sample_description']); ?>" required>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Reference Name: <span style="color:red;">*</span></label>
        <input type="text" name="reference_name" value="<?php echo htmlspecialchars($report['reference_name'] ?? ''); ?>" required placeholder="Enter reference name">
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Reference:</label>
        <input type="text" name="sample_received_from" value="<?php echo htmlspecialchars($report['sample_received_from']); ?>" required>
      </div>
      <div class="form-group">
        <label>Received Date:</label>
        <input type="datetime-local" name="received_date" value="<?php echo date('Y-m-d\TH:i', strtotime($report['received_date'])); ?>" required>
      </div>
    </div>

    <div class="form-row">
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
    <div class="test-table-wrapper">
    <table class="test-table">
      <thead>
        <tr>
          <th>Specimen No</th>
          <th>Test Direction</th>
          <th colspan="2">Breaking Force (N)</th>
          <th>Force Retain (%)</th>
          <th colspan="2">Elongation (%)</th>
          <th style="width:80px;">Action</th>
        </tr>
        <tr>
          <th></th>
          <th></th>
          <th>After</th>
          <th>Before</th>
          <th></th>
          <th>After</th>
          <th>Before</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="sun_tbody">
        <?php 
        $initial_row_count = count($test_results);
        if ($initial_row_count === 0) {
            $initial_row_count = 1; // Ensure at least one row is displayed
        }
        for ($i = 0; $i < $initial_row_count; $i++): 
          $sd = $test_results[$i] ?? [];
          $row_num = $i + 1;
        ?>
        <tr data-row="<?php echo $row_num; ?>">
          <td><?php echo $row_num; ?></td>
          <td>
            <div style="display:inline-flex; gap:6px; align-items:center;">
              <button type="button" class="dir-btn <?php echo ($sd['test_direction'] ?? 'MD') === 'MD' ? 'active' : ''; ?>" data-row="<?php echo $row_num; ?>" onclick="setDirection(this,'MD')">MD<?php echo $row_num; ?></button>
              <button type="button" class="dir-btn <?php echo ($sd['test_direction'] ?? 'MD') === 'CD' ? 'active' : ''; ?>" data-row="<?php echo $row_num; ?>" onclick="setDirection(this,'CD')">CD<?php echo $row_num; ?></button>
              <input type="hidden" name="test_direction_<?php echo $row_num; ?>" value="<?php echo htmlspecialchars($sd['test_direction'] ?? 'MD'); ?>">
            </div>
          </td>
          <td><input type="number" step="0.01" min="0" name="breaking_force_after_<?php echo $row_num; ?>" data-row="<?php echo $row_num; ?>" class="bf-after" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" value="<?php echo htmlspecialchars($sd['breaking_force_after'] ?? ''); ?>" oninput="if(this.value < 0) this.value = 0; calculateForceRetain(this)"></td>
          <td><input type="number" step="0.01" min="0" name="breaking_force_before_<?php echo $row_num; ?>" data-row="<?php echo $row_num; ?>" class="bf-before" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" value="<?php echo htmlspecialchars($sd['breaking_force_before'] ?? ''); ?>" oninput="if(this.value < 0) this.value = 0; calculateForceRetain(this)"></td>
          <td><input type="text" name="force_retain_<?php echo $row_num; ?>" class="fr" readonly value="<?php echo htmlspecialchars($sd['force_retain'] ?? ''); ?>" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px; background:#f8f9fa;"></td>
          <td><input type="number" step="0.01" min="0" name="elongation_after_<?php echo $row_num; ?>" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" value="<?php echo htmlspecialchars($sd['elongation_after'] ?? ''); ?>" oninput="if(this.value < 0) this.value = 0"></td>
          <td><input type="number" step="0.01" min="0" name="elongation_before_<?php echo $row_num; ?>" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" value="<?php echo htmlspecialchars($sd['elongation_before'] ?? ''); ?>" oninput="if(this.value < 0) this.value = 0"></td>
          <td>
            <?php if ($initial_row_count > 1 || $row_num > 1): ?>
            <button type="button" onclick="removeSunRow(this)" style="padding:4px 8px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:12px;">Delete</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endfor; ?>
      </tbody>
      <tbody>
        <?php 
        $statistics = ['Average','SD','CV','Maximum','Minimum'];
        foreach ($statistics as $idx => $label): 
        ?>
        <tr>
          <?php if ($idx === 0): ?>
          <td style="font-weight:600;" rowspan="5">Total</td>
          <?php endif; ?>
          <td><?php echo $label; ?></td>
          <td><span id="stat_bf_after_<?php echo strtolower($label); ?>"></span></td>
          <td><span id="stat_bf_before_<?php echo strtolower($label); ?>"></span></td>
          <td><span id="stat_fr_<?php echo strtolower($label); ?>"></span></td>
          <td><span id="stat_el_after_<?php echo strtolower($label); ?>"></span></td>
          <td><span id="stat_el_before_<?php echo strtolower($label); ?>"></span></td>
          <td></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <!-- Add Row Button -->
    <div style="margin-top: 10px;">
      <button type="button" onclick="addSunRow()" style="padding:8px 16px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer; font-weight:600;">Add 1 More Row</button>
    </div>

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
function setDirection(btn, dir) {
    const rowNum = btn.getAttribute('data-row');
    const btns = btn.parentElement.querySelectorAll('.dir-btn');
    btns.forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelector(`input[name="test_direction_${rowNum}"]`).value = dir;
}

function calculateForceRetain(inputEl) {
    const rowNum = inputEl.getAttribute('data-row');
    const after = parseFloat(document.querySelector(`input[name="breaking_force_after_${rowNum}"]`).value) || 0;
    const before = parseFloat(document.querySelector(`input[name="breaking_force_before_${rowNum}"]`).value) || 0;
    
    if (before > 0) {
        const retain = ((after / before) * 100).toFixed(1);
        document.querySelector(`input[name="force_retain_${rowNum}"]`).value = retain;
    } else {
        document.querySelector(`input[name="force_retain_${rowNum}"]`).value = '';
    }
    
    // Update statistics and summary
    calculateStatistics();
    calculateSummary();
}

function addSunRow() {
    const tbody = document.getElementById('sun_tbody');
    if (!tbody) {
        alert('Error: Could not find table body');
        return;
    }
    const currentRows = tbody.querySelectorAll('tr');
    const newRowNum = currentRows.length + 1;
    
    const row = document.createElement('tr');
    row.setAttribute('data-row', newRowNum);
    row.innerHTML = `
        <td>${newRowNum}</td>
        <td>
            <div style="display:inline-flex; gap:6px; align-items:center;">
                <button type="button" class="dir-btn active" data-row="${newRowNum}" onclick="setDirection(this,'MD')">MD${newRowNum}</button>
                <button type="button" class="dir-btn" data-row="${newRowNum}" onclick="setDirection(this,'CD')">CD${newRowNum}</button>
                <input type="hidden" name="test_direction_${newRowNum}" value="MD">
            </div>
        </td>
        <td><input type="number" step="0.01" min="0" name="breaking_force_after_${newRowNum}" data-row="${newRowNum}" class="bf-after" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0; calculateForceRetain(this)"></td>
        <td><input type="number" step="0.01" min="0" name="breaking_force_before_${newRowNum}" data-row="${newRowNum}" class="bf-before" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0; calculateForceRetain(this)"></td>
        <td><input type="text" name="force_retain_${newRowNum}" class="fr" readonly value="" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px; background:#f8f9fa;"></td>
        <td><input type="number" step="0.01" min="0" name="elongation_after_${newRowNum}" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0"></td>
        <td><input type="number" step="0.01" min="0" name="elongation_before_${newRowNum}" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0"></td>
        <td><button type="button" onclick="removeSunRow(this)" style="padding:4px 8px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:12px;">Delete</button></td>
    `;
    tbody.appendChild(row);
    
    // Update statistics
    calculateStatistics();
    calculateSummary();
}

function removeSunRow(button) {
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
        r.cells[0].textContent = rowNum;
        
        // Update all attributes with new row number
        const mdBtn = r.querySelector('.dir-btn[onclick*="MD"]');
        const cdBtn = r.querySelector('.dir-btn[onclick*="CD"]');
        if (mdBtn) {
            mdBtn.textContent = 'MD' + rowNum;
            mdBtn.setAttribute('data-row', rowNum);
            mdBtn.setAttribute('onclick', `setDirection(this,'MD')`);
        }
        if (cdBtn) {
            cdBtn.textContent = 'CD' + rowNum;
            cdBtn.setAttribute('data-row', rowNum);
            cdBtn.setAttribute('onclick', `setDirection(this,'CD')`);
        }
        
        // Update all input names and data-row attributes
        r.querySelectorAll('[name], [data-row]').forEach(el => {
            const name = el.getAttribute('name');
            if (name && name.includes('_')) {
                const newName = name.replace(/_\d+$/, `_${rowNum}`);
                el.setAttribute('name', newName);
            }
            if (el.hasAttribute('data-row') && el.tagName !== 'TR') {
                el.setAttribute('data-row', rowNum);
            }
        });
        
        // Update oninput calls
        r.querySelectorAll('[oninput]').forEach(el => {
            const oninput = el.getAttribute('oninput');
            if (oninput) {
                el.setAttribute('oninput', oninput.replace(/\d+/, rowNum));
            }
        });
    });
    
    // Update statistics
    calculateStatistics();
    calculateSummary();
}

function calculateStatistics() {
    const tbody = document.getElementById('sun_tbody');
    if (!tbody) return;
    
    const rows = tbody.querySelectorAll('tr');
    const bfAfter = [], bfBefore = [], fr = [], elAfter = [], elBefore = [];
    
    rows.forEach(row => {
        const rowNum = row.getAttribute('data-row');
        const after = parseFloat(document.querySelector(`input[name="breaking_force_after_${rowNum}"]`)?.value) || 0;
        const before = parseFloat(document.querySelector(`input[name="breaking_force_before_${rowNum}"]`)?.value) || 0;
        const retain = parseFloat(document.querySelector(`input[name="force_retain_${rowNum}"]`)?.value) || 0;
        const elongAfter = parseFloat(document.querySelector(`input[name="elongation_after_${rowNum}"]`)?.value) || 0;
        const elongBefore = parseFloat(document.querySelector(`input[name="elongation_before_${rowNum}"]`)?.value) || 0;
        
        if (after > 0) bfAfter.push(after);
        if (before > 0) bfBefore.push(before);
        if (retain > 0) fr.push(retain);
        if (elongAfter > 0) elAfter.push(elongAfter);
        if (elongBefore > 0) elBefore.push(elongBefore);
    });
    
    function calcStats(arr) {
        if (arr.length === 0) return { avg: 0, sd: 0, cv: 0, max: 0, min: 0 };
        const avg = arr.reduce((a, b) => a + b, 0) / arr.length;
        const variance = arr.reduce((sum, val) => sum + Math.pow(val - avg, 2), 0) / (arr.length - 1 || 1);
        const sd = Math.sqrt(variance);
        const cv = avg !== 0 ? (sd / avg) * 100 : 0;
        return {
            avg: avg.toFixed(1),
            sd: sd.toFixed(2),
            cv: cv.toFixed(1),
            max: Math.max(...arr).toFixed(1),
            min: Math.min(...arr).toFixed(1)
        };
    }
    
    const statsBfAfter = calcStats(bfAfter);
    const statsBfBefore = calcStats(bfBefore);
    const statsFr = calcStats(fr);
    const statsElAfter = calcStats(elAfter);
    const statsElBefore = calcStats(elBefore);
    
    document.getElementById('stat_bf_after_average').textContent = statsBfAfter.avg;
    document.getElementById('stat_bf_after_sd').textContent = statsBfAfter.sd;
    document.getElementById('stat_bf_after_cv').textContent = statsBfAfter.cv + '%';
    document.getElementById('stat_bf_after_maximum').textContent = statsBfAfter.max;
    document.getElementById('stat_bf_after_minimum').textContent = statsBfAfter.min;
    
    document.getElementById('stat_bf_before_average').textContent = statsBfBefore.avg;
    document.getElementById('stat_bf_before_sd').textContent = statsBfBefore.sd;
    document.getElementById('stat_bf_before_cv').textContent = statsBfBefore.cv + '%';
    document.getElementById('stat_bf_before_maximum').textContent = statsBfBefore.max;
    document.getElementById('stat_bf_before_minimum').textContent = statsBfBefore.min;
    
    document.getElementById('stat_fr_average').textContent = statsFr.avg;
    document.getElementById('stat_fr_sd').textContent = '-';
    document.getElementById('stat_fr_cv').textContent = '-';
    document.getElementById('stat_fr_maximum').textContent = statsFr.max;
    document.getElementById('stat_fr_minimum').textContent = statsFr.min;
    
    document.getElementById('stat_el_after_average').textContent = statsElAfter.avg;
    document.getElementById('stat_el_after_sd').textContent = statsElAfter.sd;
    document.getElementById('stat_el_after_cv').textContent = statsElAfter.cv + '%';
    document.getElementById('stat_el_after_maximum').textContent = statsElAfter.max;
    document.getElementById('stat_el_after_minimum').textContent = statsElAfter.min;
    
    document.getElementById('stat_el_before_average').textContent = statsElBefore.avg;
    document.getElementById('stat_el_before_sd').textContent = statsElBefore.sd;
    document.getElementById('stat_el_before_cv').textContent = statsElBefore.cv + '%';
    document.getElementById('stat_el_before_maximum').textContent = statsElBefore.max;
    document.getElementById('stat_el_before_minimum').textContent = statsElBefore.min;
}

function calculateSummary() {
    const tbody = document.getElementById('sun_tbody');
    if (!tbody) return;
    
    const rows = tbody.querySelectorAll('tr');
    let totalAfter = 0, totalBefore = 0, count = 0;
    
    rows.forEach(row => {
        const rowNum = row.getAttribute('data-row');
        const after = parseFloat(document.querySelector(`input[name="breaking_force_after_${rowNum}"]`)?.value) || 0;
        const before = parseFloat(document.querySelector(`input[name="breaking_force_before_${rowNum}"]`)?.value) || 0;
        
        if (after > 0 || before > 0) {
            totalAfter += after;
            totalBefore += before;
            count++;
        }
    });
    
    if (count > 0) {
        const avgAfter = (totalAfter / count).toFixed(1);
        const avgBefore = (totalBefore / count).toFixed(1);
        const avgRetain = totalBefore > 0 ? ((totalAfter / totalBefore) * 100).toFixed(1) : '0.0';
        
        document.getElementById('result_after').textContent = avgAfter;
        document.getElementById('result_before').textContent = avgBefore;
        document.getElementById('result_retain').textContent = avgRetain;
    } else {
        document.getElementById('result_after').textContent = '0.0';
        document.getElementById('result_before').textContent = '0.0';
        document.getElementById('result_retain').textContent = '0.0';
    }
}

// Calculate on page load
document.addEventListener('DOMContentLoaded', function() {
    const tbody = document.getElementById('sun_tbody');
    if (tbody) {
        const rows = tbody.querySelectorAll('tr');
        rows.forEach(row => {
            const rowNum = row.getAttribute('data-row');
            const afterInput = document.querySelector(`input[name="breaking_force_after_${rowNum}"]`);
            if (afterInput) {
                calculateForceRetain(afterInput);
            }
        });
    }
    calculateStatistics();
    calculateSummary();
});
</script>
</body>
</html>


