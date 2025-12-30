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

// Clean rejection remarks
$rejection_reason = $report['remarks'] ?? 'No comments';
$rejection_reason = preg_replace('/\[Checker Rejection\]:\s*/i', '', $rejection_reason);
$rejection_reason = preg_replace('/\[Admin Rejection\]:\s*/i', '', $rejection_reason);
$rejection_reason = trim($rejection_reason);

$rejected_by = $report['checker_name'] ?? $report['approver_name'] ?? 'Unknown';

// Use the SAME lab test number from the rejected report (not a new one)
$next_lab_test_no = $report['lab_test_number'];

// Handle form submission - create NEW test with corrected data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->begin_transaction();
        
        // Use the SAME lab test number from the rejected report
        $generated_lab_test_no = $report['lab_test_number'];
        $test_date = date('Y-m-d');
        $gsm = floatval($_POST['gsm']);
        $roll_number = trim($_POST['roll_number']);
        
        $test_date_obj = new DateTime($test_date);
        $year = $test_date_obj->format('y');
        $month = strtoupper($test_date_obj->format('M'));
        $day = $test_date_obj->format('d');
        $gsm_formatted = number_format($gsm / 100, 1);
        $lab_test_formatted = 'LT' . $generated_lab_test_no;
        $roll_formatted = 'R' . $roll_number;
        $report_number = "CT-{$gsm_formatted}L{$year}{$month}{$day}-{$lab_test_formatted}-{$roll_formatted}";
        
        // Collect sieve analysis data
        $sieve_data = [];
        for ($i = 1; $i <= 8; $i++) {
            $sieve_data[] = [
                'sieve_size' => floatval($_POST["sieve_size_$i"] ?? 0),
                'retained' => floatval($_POST["retained_$i"] ?? 0),
                'cumulative' => floatval($_POST["cumulative_$i"] ?? 0),
                'passing' => floatval($_POST["passing_$i"] ?? 0)
            ];
        }
        
        $test_results = [
            'sieve_data' => $sieve_data,
            'o_value' => floatval($_POST['o_value'] ?? 0),
            'opening_size' => floatval($_POST['opening_size'] ?? 0),
            'remarks' => trim($_POST['remarks'] ?? '')
        ];
        
        $test_results_json = json_encode($test_results);
        
        // Delete the old rejected test to allow same report_number for the corrected version
        $delete_old = $conn->prepare("DELETE FROM characteristics_tests WHERE id = ?");
        $delete_old->bind_param("i", $report_id);
        $delete_old->execute();
        $delete_old->close();
        
        // Insert NEW test into database with status='pending'
        $stmt = $conn->prepare(
            "INSERT INTO characteristics_tests 
            (report_number, lab_test_number, test_materials, gsm, roll_number, sample_id,
             specimen_size, specimen_size_unit, sand_type, sand_weight, sand_weight_unit,
             sieving_time, sieving_time_unit, sample_received, sample_tested,
             test_results, test_performed_by, reporter_id, reporter_name, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        );
        
        $sample_tested = date('Y-m-d H:i:s');
        
        $stmt->bind_param(
            "sssdssdsdsdssssssis",
            $report_number,
            $generated_lab_test_no,
            $_POST['test_materials'],
            $gsm,
            $roll_number,
            $_POST['sample_id'],
            $_POST['specimen_size'],
            $_POST['specimen_size_unit'],
            $_POST['sand_type'],
            $_POST['sand_weight'],
            $_POST['sand_weight_unit'],
            $_POST['sieving_time'],
            $_POST['sieving_time_unit'],
            $_POST['sample_received'],
            $sample_tested,
            $test_results_json,
            $reporter_full_name,
            $reporter_id,
            $reporter_name
        );
        
        if ($stmt->execute()) {
            $conn->commit();
            $message = "✅ Test resubmitted successfully! Report Number: " . $report_number . " - Status: Pending";
            // Redirect to main form after 2 seconds
            header("refresh:2;url=characteristics_test.php");
        } else {
            throw new Exception("Failed to save test: " . $stmt->error);
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
    <div class="alert alert-success">✅ <?php echo $message; ?></div>
  <?php endif; ?>

  <?php if ($error): ?>
    <div class="alert alert-error">❌ <?php echo $error; ?></div>
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
        <label>GSM:</label>
        <input type="number" name="gsm" step="0.0001" min="0" value="<?php echo rtrim(rtrim(number_format($report['gsm'], 4), '0'), '.'); ?>" required>
      </div>
      <div class="form-group">
        <label>Roll Number:</label>
        <input type="text" name="roll_number" value="<?php echo htmlspecialchars($report['roll_number']); ?>" required>
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
          <input type="number" name="sand_weight" step="0.0001" min="0" value="<?php echo rtrim(rtrim(number_format($report['sand_weight'], 4), '0'), '.'); ?>" required style="flex:1;" id="sand_weight">
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
          </tr>
        </thead>
        <tbody>
          <?php for ($i = 1; $i <= 8; $i++): 
            $sieve = $test_data['sieve_data'][$i-1] ?? ['sieve_size' => 0, 'retained' => 0, 'cumulative' => 0, 'passing' => 0];
          ?>
          <tr>
            <td><input type="number" name="sieve_size_<?php echo $i; ?>" id="sieve_size_<?php echo $i; ?>" step="0.0001" min="0" value="<?php echo rtrim(rtrim(sprintf('%.4f', $sieve['sieve_size']), '0'), '.'); ?>"></td>
            <td><input type="number" name="retained_<?php echo $i; ?>" id="retained_<?php echo $i; ?>" step="0.0001" min="0" value="<?php echo rtrim(rtrim(sprintf('%.4f', $sieve['retained']), '0'), '.'); ?>" oninput="calculateSieve()"></td>
            <td><input type="number" name="cumulative_<?php echo $i; ?>" id="cumulative_<?php echo $i; ?>" readonly class="readonly" value="<?php echo rtrim(rtrim(sprintf('%.4f', $sieve['cumulative']), '0'), '.'); ?>"></td>
            <td><input type="number" name="passing_<?php echo $i; ?>" id="passing_<?php echo $i; ?>" readonly class="readonly" value="<?php echo rtrim(rtrim(sprintf('%.2f', $sieve['passing']), '0'), '.'); ?>"></td>
          </tr>
          <?php endfor; ?>
        </tbody>
      </table>
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

// Generate Report ID based on format: GSM.XL[YY][MONTH][DD]-LT[XX]-R[XX]
// Example: if gsm=300, lab test=01, roll=34, date=October 14, 2025 -> 3.0L25Oct14-LT01-R34
const nextLabTestNo = '<?php echo $next_lab_test_no; ?>';

function generateReportID() {
  const gsm = document.querySelector('input[name="gsm"]').value;
  const rollNumber = document.querySelector('input[name="roll_number"]').value;
  
  if (!gsm || !rollNumber) {
    document.getElementById('report_id').value = '';
    return;
  }
  
  const now = new Date();
  const utc = now.getTime() + now.getTimezoneOffset() * 60000;
  const dhaka = new Date(utc + 6 * 3600000);
  
  const year = dhaka.getFullYear().toString().slice(-2); // Last 2 digits
  const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const month = monthNames[dhaka.getMonth()];
  const day = String(dhaka.getDate()).padStart(2, '0');
  
  const gsmFormatted = (parseFloat(gsm) / 100).toFixed(1); // e.g., 300 -> 3.0
  const labTestFormatted = 'LT' + nextLabTestNo;
  const rollFormatted = 'R' + rollNumber;
  
  const reportID = `${gsmFormatted}L${year}${month}${day}-${labTestFormatted}-${rollFormatted}`;
  document.getElementById('report_id').value = reportID;
}

// Add event listeners to auto-generate Report ID
document.querySelector('input[name="gsm"]').addEventListener('input', generateReportID);
document.querySelector('input[name="roll_number"]').addEventListener('input', generateReportID);

// Generate on page load
generateReportID();

// Calculate sieve analysis
function calculateSieve() {
  const totalSand = parseFloat(document.getElementById('sand_weight').value) || 0;
  let cumulative = 0;
  
  for (let i = 1; i <= 8; i++) {
    const retained = parseFloat(document.getElementById('retained_' + i).value) || 0;
    cumulative += retained;
    
    document.getElementById('cumulative_' + i).value = cumulative.toFixed(1);
    
    if (totalSand > 0) {
      const passing = ((totalSand - cumulative) / totalSand) * 100;
      document.getElementById('passing_' + i).value = passing.toFixed(1);
    }
  }
}

// Calculate opening size based on O-value
function calculateOpening() {
  const oValue = parseFloat(document.getElementById('o_value_input').value);
  
  if (!oValue || oValue < 0 || oValue > 100) {
    alert('Please enter a valid O-value between 0 and 100');
    return;
  }
  
  // Find interpolated sieve size for the given O-value
  // Passing decreases as sieve size decreases, so we need upper (higher %) and lower (lower %)
  let upperSize = 0, lowerSize = 0, upperPass = 0, lowerPass = 0;
  let found = false;
  
  for (let i = 1; i <= 8; i++) {
    const passing = parseFloat(document.getElementById('passing_' + i).value) || 0;
    const sieveSize = parseFloat(document.getElementById('sieve_size_' + i).value) || 0;
    
    if (passing <= oValue) {
      // Found the lower bound (passing just below O-value)
      lowerSize = sieveSize;
      lowerPass = passing;
      
      // Get upper bound from previous row (passing just above O-value)
      if (i > 1) {
        upperSize = parseFloat(document.getElementById('sieve_size_' + (i-1)).value) || 0;
        upperPass = parseFloat(document.getElementById('passing_' + (i-1)).value) || 0;
        found = true;
      } else {
        // O-value is higher than the first sieve's passing %
        upperSize = sieveSize;
        upperPass = passing;
        found = true;
      }
      break;
    }
  }
  
  // If O-value is lower than all sieves, use the last sieve
  if (!found && lowerSize === 0) {
    for (let i = 8; i >= 1; i--) {
      const sieveSize = parseFloat(document.getElementById('sieve_size_' + i).value) || 0;
      const passing = parseFloat(document.getElementById('passing_' + i).value) || 0;
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
  
  document.getElementById('opening_size').value = openingSize.toFixed(4);
  document.getElementById('calculation_text').innerHTML = calculationHTML;
  document.getElementById('calculation_display').style.display = 'block';
  
  document.getElementById('final_result').innerHTML = 
    `Apparent Opening Size (O${oValue}): ${openingSize.toFixed(3)} mm (${(openingSize * 1000).toFixed(0)} μm)`;
  document.getElementById('result_display').style.display = 'block';
}

// Trigger initial calculation on load
calculateSieve();

// If there's an O-value already, recalculate to show the formula
<?php if (!empty($test_data['o_value'])): ?>
setTimeout(function() {
  calculateOpening();
}, 100);
<?php endif; ?>
</script>
</body>
</html>
<?php $conn->close(); ?>


