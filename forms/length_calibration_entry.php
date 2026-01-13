<?php
session_start();
// Optional auto-reload for development
if (file_exists(__DIR__ . '/../dev/auto_reload.php')) {
    include_once(__DIR__ . '/../dev/auto_reload.php');
}
require_once 'security_config.php';

// Security/session checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}
if (SecurityConfig::checkSessionTimeout()) {
    session_destroy();
    header("Location: ../login.html?error=timeout");
    exit();
}
SecurityConfig::updateSessionActivity();
if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    session_destroy();
    header("Location: ../login.html?error=disabled");
    exit();
}

// Role-based access control for QC module
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_QC, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access the QC module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Schema helper
$colExists = function(mysqli $conn, string $table, string $column): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    $col = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    return $res && $res->num_rows > 0;
};

// Collation alignment for reference/line between fiber_to_roll_entry and length_calibrations
$ftrCollation = null;
$lcCollation = null;
$lcLineCollation = null;
$colRes = $conn->query("SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME 
    FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME IN ('fiber_to_roll_entry','length_calibrations')
      AND COLUMN_NAME IN ('reference_number','line_number')");
if ($colRes) {
    while ($row = $colRes->fetch_assoc()) {
        if ($row['TABLE_NAME'] === 'fiber_to_roll_entry' && $row['COLUMN_NAME'] === 'reference_number') $ftrCollation = $row['COLLATION_NAME'];
        if ($row['TABLE_NAME'] === 'length_calibrations' && $row['COLUMN_NAME'] === 'reference_number') $lcCollation = $row['COLLATION_NAME'];
        if ($row['TABLE_NAME'] === 'length_calibrations' && $row['COLUMN_NAME'] === 'line_number') $lcLineCollation = $row['COLLATION_NAME'];
    }
}
$collation = $ftrCollation ?: $lcCollation ?: 'utf8mb4_unicode_ci';
if (!preg_match('/^[0-9A-Za-z_]+$/', $collation)) {
    $collation = 'utf8mb4_unicode_ci';
}
if ($lcCollation && $lcCollation !== $collation) {
    $conn->query("ALTER TABLE length_calibrations MODIFY reference_number VARCHAR(100) CHARACTER SET utf8mb4 COLLATE {$collation}");
}
if ($lcLineCollation && $lcLineCollation !== $collation) {
    $conn->query("ALTER TABLE length_calibrations MODIFY line_number VARCHAR(100) CHARACTER SET utf8mb4 COLLATE {$collation}");
}

// Build NOT EXISTS filter only if required columns exist
$hasLcRef = $colExists($conn, 'length_calibrations', 'reference_number');
$hasLcRoll = $colExists($conn, 'length_calibrations', 'roll_no');
$hasLcLine = $colExists($conn, 'length_calibrations', 'line_number');
$hasLcStatus = $colExists($conn, 'length_calibrations', 'status');
// Exclude all submitted entries (any status except rejected, as rejected entries can be resubmitted)
$lcStatusFilter = $hasLcStatus ? "AND lc.status NOT IN ('rejected')" : "";
$lcLineFilter = $hasLcLine ? "AND lc.line_number COLLATE {$collation} = CONCAT('Line ', f.line_no) COLLATE {$collation}" : "";
$lcExistsClause = "";
if ($hasLcRoll) {
    // Exclude if roll_no already exists in length_calibrations (regardless of reference_number or line_number)
    // This ensures a roll number can only be submitted once
    if ($hasLcStatus) {
        $lcExistsClause = "
        AND NOT EXISTS (
            SELECT 1 FROM length_calibrations lc 
            WHERE lc.roll_no = f.roll_no
            AND lc.status NOT IN ('rejected')
        )";
    } else {
        // If status column doesn't exist, exclude all entries for this roll_no
        $lcExistsClause = "
        AND NOT EXISTS (
            SELECT 1 FROM length_calibrations lc 
            WHERE lc.roll_no = f.roll_no
        )";
    }
}

// Fetch roll numbers from fiber_to_roll_entry - exclude only if approved or pending test exists for this specific ref+roll+line
$rollNumbers = [];
$rollQuery = $conn->query("
    SELECT f.roll_no, f.reference_number, f.line_no 
    FROM fiber_to_roll_entry f
    WHERE f.roll_no IS NOT NULL 
    {$lcExistsClause}
    ORDER BY f.created_at DESC 
    LIMIT 100
");
if ($rollQuery) {
    while ($row = $rollQuery->fetch_assoc()) {
        $rollNumbers[] = [
            'roll_no' => $row['roll_no'],
            'reference' => $row['reference_number'],
            'line_no' => $row['line_no'],
            'display' => "Roll {$row['roll_no']} ({$row['reference_number']})"
        ];
    }
}

// No need to fetch submitted rolls separately - already excluded in main query above

// Fetch rejected entries for this user to allow resubmission
$rejectedEntries = [];
$userId = $_SESSION['user_id'];
$hasRejectionReason = $colExists($conn, 'length_calibrations', 'rejection_reason');
$rejCol = $hasRejectionReason ? "rejection_reason" : "NULL AS rejection_reason";
$userFilter = $colExists($conn, 'length_calibrations', 'user_id') ? "AND user_id = {$userId}" : "";
$rejectedQuery = $conn->query("
    SELECT entry_id, date_time, shift, line_number, inspector, {$rejCol}, created_at
    FROM length_calibrations
    WHERE status = 'rejected' {$userFilter}
    GROUP BY entry_id
    ORDER BY created_at DESC
    LIMIT 10
");
if ($rejectedQuery) {
    while ($row = $rejectedQuery->fetch_assoc()) {
        $rejectedEntries[] = $row;
    }
}

// Get current date/time
$currentDateTime = date('Y-m-d\TH:i');

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<title>Length Calibration Entry</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:20px; color:#2c3e50; }
  .container { max-width:1600px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);}
  h1 { text-align:center; font-size:28px; margin-bottom:30px; color:#2c3e50; }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; color:#34495e; }
  input[type="datetime-local"], input[type="text"], input[type="number"], select {
    padding:10px; border:1px solid #ccc; border-radius:6px; width:100%;
  }
  .readonly { background:#ecf0f1; }
  .btn-group { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px; }
  .btn { padding:8px 16px; border:1px solid #ccc; background:#f0f0f0; cursor:pointer; border-radius:6px; transition:all 0.2s; font-weight:500; font-size:13px; }
  .btn:hover { background:#e0e0e0; }
  .btn.selected { background:#007bff; color:#fff; border-color:#007bff; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:12px 24px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px; font-weight:600; transition:all 0.3s;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .submit-btn:hover { background:#27ae60; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .clear-btn:hover { background:#c0392b; }
  .back-btn { background:#95a5a6; color:#fff; }
  .back-btn:hover { background:#7f8c8d; }
  .add-row-btn { background:#3498db; color:#fff; padding:6px 14px; border:none; border-radius:4px; cursor:pointer; font-weight:500; margin-top:20px; transition:all 0.3s; font-size:13px; }
  .add-row-btn:hover { background:#2980b9; }
  .cal-table { width:100%; border-collapse:collapse; margin-top:20px; }
  .cal-table th { background:#34495e; color:#fff; padding:12px 10px; text-align:center; font-size:12px; border:1px solid #ddd; font-weight:600; }
  .cal-table td { padding:10px 8px; text-align:center; border:1px solid #ddd; }
  .cal-table input[type="number"] { width:100px; padding:8px; border:1px solid #ccc; border-radius:4px; font-size:12px; }
  .cal-table select { width:180px; padding:8px; border:1px solid #ccc; border-radius:4px; font-size:12px; }
  .cal-table .remove-btn { background:#dc3545; color:#fff; border:2px solid #dc3545; padding:8px 16px; border-radius:5px; cursor:pointer; font-size:13px; font-weight:600; transition:all 0.3s; box-shadow:0 2px 4px rgba(0,0,0,0.1); }
  .cal-table .remove-btn:hover { background:#c82333; border-color:#bd2130; transform:translateY(-1px); box-shadow:0 4px 6px rgba(0,0,0,0.15); }
  .alert { padding:12px; border-radius:6px; margin-bottom:20px; }
  .alert-error { background:#ffe8e8; border:1px solid #e74c3c; color:#c0392b; }
  .alert-success { background:#d4edda; border:1px solid #28a745; color:#155724; }
  .auto-calculation { font-weight:600; color:#2c3e50; text-align:center; }
</style>
</head>
<body>
<div class="container">
  <h1>Length Calibration Entry</h1>

  <!-- Date, Time & Shift Display -->
  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>
  <div id="entryIdDisplay" class="summary-info" style="background:#e8f5e9; border-left:4px solid #27ae60;"></div>

  <!-- Back to Dashboard Link -->
  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
  </div>

  <?php if(isset($_GET['error'])): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($_GET['error']); ?></div>
  <?php endif; ?>
  
  <?php if(isset($_GET['success'])): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($_GET['success']); ?></div>
  <?php endif; ?>

  <?php if (count($rejectedEntries) > 0): ?>
  <div style="background:#f8d7da; border:1px solid #f5c6cb; padding:15px; border-radius:8px; margin-bottom:20px;">
    <h3 style="color:#721c24; margin-bottom:12px; font-size:16px;"><i class="fas fa-exclamation-triangle"></i> Your Rejected Entries</h3>
    <table style="width:100%; background:white; font-size:13px;">
      <thead style="background:#e74c3c; color:white;">
        <tr>
          <th style="padding:8px; font-size:13px;">Entry ID</th>
          <th style="padding:8px; font-size:13px;">Date & Time</th>
          <th style="padding:8px; font-size:13px;">Line</th>
          <th style="padding:8px; font-size:13px;">Rejection Reason</th>
          <th style="padding:8px; font-size:13px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rejectedEntries as $rejected): ?>
        <tr>
          <td style="padding:8px; font-size:12px;"><strong><?php echo htmlspecialchars($rejected['entry_id']); ?></strong></td>
          <td style="padding:8px; font-size:12px;"><?php echo date('d M Y, h:i A', strtotime($rejected['date_time'])); ?></td>
          <td style="padding:8px; font-size:12px;"><?php echo htmlspecialchars($rejected['line_number']); ?></td>
          <td style="padding:8px; font-size:12px;"><?php echo htmlspecialchars($rejected['rejection_reason']); ?></td>
          <td style="padding:8px;">
            <a href="edit_length_calibration.php?id=<?php echo urlencode($rejected['entry_id']); ?>" 
               style="background:#3498db; color:white; border:none; padding:6px 12px; border-radius:6px; cursor:pointer; font-weight:600; font-size:12px; text-decoration:none; display:inline-block;">
              <i class="fas fa-edit"></i> Edit & Resubmit
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <form id="calibrationForm" action="../handlers/submit_length_calibration.php" method="POST" onsubmit="return validateForm()">
    
    <!-- Date and Time -->
    <input type="datetime-local" id="date_time" name="date_time" value="<?php echo $currentDateTime; ?>" required style="display:none;">

    <!-- Shift Selection -->
    <input type="hidden" id="shift" name="shift" value="" required>

    <!-- Line Number Selection -->
    <div class="form-group">
      <label>Line Number:</label>
      <div class="btn-group">
        <button type="button" class="btn" onclick="selectLine('Line 1')">Line 1</button>
        <button type="button" class="btn" onclick="selectLine('Line 2')">Line 2</button>
      </div>
      <input type="hidden" id="line_number" name="line_number" value="" required>
    </div>

    <!-- Inspector Name -->
    <div class="form-group">
      <label>Inspector Name:</label>
      <input type="text" id="inspector" name="inspector" value="<?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?>" readonly class="readonly">
    </div>

    <!-- Calibration Table -->
    <div style="overflow-x:auto;">
      <table class="cal-table" id="calTable">
        <thead>
          <tr>
            <th style="width:50px;">S.No</th>
            <th style="width:200px;">Roll Number</th>
            <th style="width:150px;">Reference Length (m)</th>
            <th style="width:150px;">Set in Machine (m)</th>
            <th style="width:150px;">Actual Length (m)</th>
            <th style="width:150px;">Difference (m)</th>
            <th style="width:150px;">Calibration Length (m)</th>
            <th style="width:100px;">Action</th>
          </tr>
        </thead>
        <tbody id="calTableBody">
        </tbody>
      </table>
    </div>

    <!-- Add Row Button -->
    <button type="button" class="add-row-btn" onclick="addRow()">Add 1 More Row</button>

    <!-- Action Buttons -->
    <div class="actions">
      <button type="submit" class="submit-btn">Submit</button>
      <button type="button" class="clear-btn" onclick="clearForm()">Clear</button>
    </div>
  </form>
</div>

<script>
// Load rejected entry data for editing and resubmission
function loadRejectedLC(entryId) {
  if (!confirm('Load this rejected entry for editing?')) return;
  
  fetch('api/get_rejected_lc.php?entry_id=' + encodeURIComponent(entryId))
    .then(res => {
      if (!res.ok) {
        throw new Error('HTTP error! status: ' + res.status);
      }
      return res.json();
    })
    .then(response => {
      if (response.success && response.data.length > 0) {
        const data = response.data;
        const firstRow = data[0];
        
        // Set resubmit entry ID
        document.getElementById('resubmit_entry_id').value = entryId;
        
        // Set line number
        selectLine(firstRow.line_number);
        
        // Set inspector
        document.getElementById('inspector').value = firstRow.inspector;
        
        // Clear existing rows
        document.getElementById('calTableBody').innerHTML = '';
        rowCount = 0;
        usedRolls.clear();
        
        // Add rows with rejected data
        data.forEach((row, index) => {
          addRow();
          const currentRowId = rowCount; // Capture the current row count
          
          // Wait for row to be added to DOM
          setTimeout(() => {
            // Use the field IDs
            const rollSelect = document.getElementById('roll_select_' + currentRowId);
            if (rollSelect) rollSelect.value = row.roll_no;
            
            const refLength = document.getElementById('ref_length_' + currentRowId);
            if (refLength) refLength.value = row.reference_length;
            
            const setMachine = document.getElementById('set_machine_' + currentRowId);
            if (setMachine) setMachine.value = row.set_in_machine;
            
            const actualLength = document.getElementById('actual_length_' + currentRowId);
            if (actualLength) {
              actualLength.value = row.actual_length;
              // Trigger calculation to update difference
              calculateDifference(currentRowId);
            }
            
            const calLength = document.getElementById('calibration_length_' + currentRowId);
            if (calLength) calLength.value = row.calibration_length;
          }, 100 * index); // Stagger the updates
        });
        
        // Show alert after all rows are loaded
        setTimeout(() => {
          alert('Rejected entry loaded! Please review and edit the values, then submit again.');
          window.scrollTo(0, document.getElementById('calibrationForm').offsetTop);
        }, 100 * data.length + 100);
      } else {
        alert('Error: ' + (response.message || 'Could not load rejected entry'));
      }
    })
    .catch(err => {
      console.error('Fetch error:', err);
      alert('Error loading rejected entry: ' + err.message);
    });
}

let rowCount = 0;
const rollNumbers = <?php echo json_encode($rollNumbers); ?>;
let usedRolls = new Set(); // Track used roll numbers within current form

// Update date/time and shift display
function updateTimeAndShift() {
  const now = new Date();
  const utc = now.getTime() + (now.getTimezoneOffset()*60000);
  const dhaka = new Date(utc + (6*3600000));
  
  document.getElementById("dateTimeDisplay").innerHTML =
    "Date & Time: " + dhaka.toDateString() + " " + dhaka.toLocaleTimeString();

  const h = dhaka.getHours();
  const shift = (h >= 8 && h < 20) ? "Day" : "Night";
  document.getElementById("shiftBanner").innerText = "Shift: " + shift;
  document.getElementById("shift").value = shift;
  
  // Update datetime input
  const yyyy = dhaka.getFullYear();
  const mm = String(dhaka.getMonth()+1).padStart(2,'0');
  const dd = String(dhaka.getDate()).padStart(2,'0');
  const hh = String(dhaka.getHours()).padStart(2,'0');
  const min = String(dhaka.getMinutes()).padStart(2,'0');
  const ss = String(dhaka.getSeconds()).padStart(2,'0');
  document.getElementById("date_time").value = `${yyyy}-${mm}-${dd}T${hh}:${min}`;
  
  // Fetch Entry ID from server
  fetch('../handlers/get_entry_id.php?module=length_calibration')
    .then(response => response.json())
    .then(data => {
      const entryId = data.entry_id || 'LC-00000000-000';
      document.getElementById("entryIdDisplay").innerText = `Entry ID: ${entryId}`;
    })
    .catch(error => {
      document.getElementById("entryIdDisplay").innerText = "Entry ID: Loading...";
    });
}

function selectShift(shift) {
  document.getElementById("shift").value = shift;
  event.target.classList.add('selected');
  event.target.parentElement.querySelectorAll('.btn').forEach(btn => {
    if(btn !== event.target) btn.classList.remove('selected');
  });
}

function selectLine(line) {
  document.getElementById("line_number").value = line;
  event.target.classList.add('selected');
  event.target.parentElement.querySelectorAll('.btn').forEach(btn => {
    if(btn !== event.target) btn.classList.remove('selected');
  });
}

function autoSelectLineFromRoll(rowId) {
  // Get the selected roll's line number and reference
  const selectElement = document.getElementById(`roll_select_${rowId}`);
  const selectedOption = selectElement.options[selectElement.selectedIndex];
  const lineNo = selectedOption.dataset.line;
  const reference = selectedOption.dataset.ref;
  
  // Populate the hidden reference_number field
  const refField = document.getElementById(`reference_number_${rowId}`);
  if (refField) {
    refField.value = reference || '';
  }
  
  if (lineNo && !document.getElementById("line_number").value) {
    // Auto-select the line number button
    const lineText = `Line ${lineNo}`;
    document.getElementById("line_number").value = lineText;
    
    // Highlight the corresponding button
    const lineButtons = document.querySelectorAll('.btn-group .btn');
    lineButtons.forEach(btn => {
      btn.classList.remove('selected');
      if (btn.textContent.trim() === lineText) {
        btn.classList.add('selected');
      }
    });
  }
}

// Initialize on load
updateTimeAndShift();
setInterval(updateTimeAndShift, 1000);

window.addEventListener('DOMContentLoaded', function() {
  addRow(); // Add initial row
});

function addRow() {
  rowCount++;
  const tbody = document.getElementById('calTableBody');
  const row = document.createElement('tr');
  row.id = `row_${rowCount}`;
  
  // Roll Number Options (exclude already used rolls)
  let rollOptionsHTML = '<option value="">Select Roll No.</option>';
  rollNumbers.forEach(roll => {
    // Only show roll if it hasn't been used
    if (!usedRolls.has(roll.roll_no)) {
      rollOptionsHTML += `<option value="${roll.roll_no}" data-ref="${roll.reference}" data-line="${roll.line_no}">Roll ${roll.roll_no}</option>`;
    }
  });
  
  row.innerHTML = `
    <td>${rowCount}</td>
    <td>
      <select name="roll_no[]" id="roll_select_${rowCount}" onchange="autoSelectLineFromRoll(${rowCount})" required>
        ${rollOptionsHTML}
      </select>
      <input type="hidden" name="reference_number[]" id="reference_number_${rowCount}" value="">
    </td>
    <td><input type="number" name="reference_length[]" id="ref_length_${rowCount}" step="0.01" oninput="calculateDifference(${rowCount})" required></td>
    <td><input type="number" name="set_in_machine[]" id="set_machine_${rowCount}" step="0.01" oninput="calculateDifference(${rowCount})" required></td>
    <td><input type="number" name="actual_length[]" id="actual_length_${rowCount}" step="0.01" oninput="calculateDifference(${rowCount})" required></td>
    <td><div class="auto-calculation" id="difference_${rowCount}">-</div></td>
    <td><input type="number" name="calibration_length[]" id="calibration_length_${rowCount}" step="0.01" required></td>
    <td>
      ${rowCount > 1 ? '<button type="button" class="remove-btn" onclick="removeRow(' + rowCount + ')">Remove</button>' : ''}
    </td>
  `;
  
  tbody.appendChild(row);
  
  // Add change event listener after row is added to DOM
  const selectElement = document.getElementById(`roll_select_${rowCount}`);
  if (selectElement) {
    selectElement.addEventListener('change', function() {
      const selectedRoll = this.value;
      if (selectedRoll) {
        usedRolls.add(selectedRoll);
        // Remove this option from other dropdowns
        document.querySelectorAll('select[name="roll_no[]"]').forEach(dd => {
          if (dd !== this) {
            const option = dd.querySelector(`option[value="${selectedRoll}"]`);
            if (option) option.remove();
          }
        });
      }
    });
  }
}

function calculateDifference(rowId) {
  const refLength = parseFloat(document.getElementById(`ref_length_${rowId}`).value) || 0;
  const actualLength = parseFloat(document.getElementById(`actual_length_${rowId}`).value) || 0;
  
  const difference = refLength - actualLength;
  const differenceElement = document.getElementById(`difference_${rowId}`);
  differenceElement.textContent = difference.toFixed(2);
}

function removeRow(rowId) {
  const row = document.getElementById(`row_${rowId}`);
  if (row) {
    // Get the selected roll before removing
    const selectElement = document.getElementById(`roll_select_${rowId}`);
    if (selectElement && selectElement.value) {
      usedRolls.delete(selectElement.value);
      
      // Re-add the roll option to other dropdowns
      const rollInfo = rollNumbers.find(r => r.roll_no === selectElement.value);
      if (rollInfo) {
        document.querySelectorAll('select[name="roll_no[]"]').forEach(dd => {
          if (dd !== selectElement) {
            // Check if option doesn't exist
            if (!dd.querySelector(`option[value="${rollInfo.roll_no}"]`)) {
              const option = document.createElement('option');
              option.value = rollInfo.roll_no;
              option.textContent = `Roll ${rollInfo.roll_no}`;
              dd.appendChild(option);
            }
          }
        });
      }
    }
    
    row.remove();
  }
}

function validateForm(){
  if(!document.getElementById("shift").value){
    alert("Please select a shift."); 
    return false;
  }
  
  if(!document.getElementById("line_number").value){
    alert("Please select a line number."); 
    return false;
  }
  
  const rows = document.querySelectorAll('#calTableBody tr');
  if(rows.length === 0){
    alert("Please add at least one row."); 
    return false;
  }
  
  return true;
}

function clearForm() {
  document.getElementById("calibrationForm").reset();
  document.getElementById("calTableBody").innerHTML = '';
  rowCount = 0;
  usedRolls.clear(); // Reset used rolls
  
  // Remove selected class from buttons
  const buttons = document.querySelectorAll('.btn-group .btn');
  buttons.forEach(btn => btn.classList.remove('selected'));
  
  // Reset and update display
  updateTimeAndShift();
  addRow(); // Re-add initial row
}
</script>
</body>
</html>


