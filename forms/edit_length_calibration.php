<?php
session_start();
require_once 'security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$entry_id = $_GET['id'] ?? '';
$userId = $_SESSION['user_id'];

if (empty($entry_id)) {
    die('Invalid entry ID');
}

// Fetch rejected entry data (only if belongs to current user)
$stmt = $conn->prepare("SELECT * FROM length_calibrations WHERE entry_id = ? AND user_id = ? AND status = 'rejected' ORDER BY id");
$stmt->bind_param("si", $entry_id, $userId);
$stmt->execute();
$result = $stmt->get_result();

$calibrations = [];
$firstRow = null;
while ($row = $result->fetch_assoc()) {
    if (!$firstRow) {
        $firstRow = $row;
    }
    $calibrations[] = $row;
}
$stmt->close();

if (!$firstRow) {
    die('Entry not found or access denied');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Delete old rejected entries
    $conn->query("DELETE FROM length_calibrations WHERE entry_id = '$entry_id' AND user_id = $userId AND status = 'rejected'");
    
    // Insert new entries (same logic as submit_length_calibration.php but reusing entry_id)
    $dateTime = $_POST['date_time'] ?? '';
    $shift = $_POST['shift'] ?? '';
    $lineNumber = $_POST['line_number'] ?? '';
    $inspector = $_POST['inspector'] ?? '';
    
    $rollNos = $_POST['roll_no'] ?? [];
    $referenceLengths = $_POST['reference_length'] ?? [];
    $setInMachines = $_POST['set_in_machine'] ?? [];
    $actualLengths = $_POST['actual_length'] ?? [];
    $calibrationLengths = $_POST['calibration_length'] ?? [];
    
    $successCount = 0;
    
    for ($i = 0; $i < count($rollNos); $i++) {
        if (!empty($rollNos[$i])) {
            $refLength = floatval($referenceLengths[$i]);
            $setMachine = floatval($setInMachines[$i]);
            $actualLength = floatval($actualLengths[$i]);
            $calLength = floatval($calibrationLengths[$i]);
            $difference = $refLength - $actualLength;
            
            $stmt = $conn->prepare("INSERT INTO length_calibrations 
                (entry_id, date_time, shift, line_number, roll_no, reference_length, set_in_machine, 
                 actual_length, difference, calibration_length, inspector, user_id, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
            
            $stmt->bind_param("sssssdddddsi",
                $entry_id,
                $dateTime,
                $shift,
                $lineNumber,
                $rollNos[$i],
                $refLength,
                $setMachine,
                $actualLength,
                $difference,
                $calLength,
                $inspector,
                $userId
            );
            
            if ($stmt->execute()) {
                $successCount++;
            }
            $stmt->close();
        }
    }
    
    $conn->close();
    header("Location: length_calibration_entry.php?success=" . urlencode("Length Calibration resubmitted! Entry ID: $entry_id | $successCount row(s). Status: Pending Approval"));
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Rejected Length Calibration - <?php echo htmlspecialchars($entry_id); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:20px; color:#2c3e50; }
  .container { max-width:1600px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08); }
  h1 { font-size:24px; margin-bottom:20px; color:#2c3e50; }
  .rejection-box { background:#f8d7da; border:1px solid #f5c6cb; padding:15px; border-radius:8px; margin-bottom:20px; }
  .rejection-box h3 { color:#721c24; margin-bottom:10px; font-size:16px; }
  .cal-table { width:100%; border-collapse:collapse; margin-top:20px; font-size:13px; }
  .cal-table th { padding:10px 5px; border:1px solid #ddd; text-align:center; background:#667eea; color:white; font-size:12px; }
  .cal-table td { padding:5px; border:1px solid #ddd; text-align:center; }
  .cal-table input { width:100%; padding:6px; text-align:center; font-size:12px; }
  .btn { padding:10px 20px; border:none; border-radius:6px; cursor:pointer; font-weight:600; text-decoration:none; display:inline-block; margin:5px; }
  .submit-btn { background:#27ae60; color:white; }
  .submit-btn:hover { background:#229954; }
  .cancel-btn { background:#6c757d; color:white; }
  .cancel-btn:hover { background:#5a6268; }
  .add-row-btn { background:#3498db; color:white; padding:8px 16px; border:none; border-radius:6px; cursor:pointer; font-weight:600; margin-top:10px; }
  .add-row-btn:hover { background:#2980b9; }
  .remove-btn { background:#e74c3c; color:white; padding:4px 8px; border:none; border-radius:4px; cursor:pointer; font-size:11px; }
  .remove-btn:hover { background:#c0392b; }
  .readonly { background:#ecf0f1; }
  .auto-calculation { font-weight:600; color:#2c3e50; background:#e8f5e9; padding:6px; }
</style>
</head>
<body>
<div class="container">
  <h1><i class="fas fa-edit"></i> Edit Rejected Length Calibration</h1>
  
  <div class="rejection-box">
    <h3><i class="fas fa-exclamation-triangle"></i> Rejection Reason</h3>
    <p><?php echo htmlspecialchars($firstRow['rejection_reason']); ?></p>
    <p style="font-size:12px; color:#856404; margin-top:8px;">
      <strong>Rejected by:</strong> <?php echo htmlspecialchars($firstRow['approved_by']); ?> on <?php echo date('d M Y, h:i A', strtotime($firstRow['approved_at'])); ?>
    </p>
  </div>

  <form method="POST" action="">
    <input type="hidden" name="date_time" value="<?php echo htmlspecialchars($firstRow['date_time']); ?>">
    <input type="hidden" name="shift" value="<?php echo htmlspecialchars($firstRow['shift']); ?>">
    <input type="hidden" name="line_number" value="<?php echo htmlspecialchars($firstRow['line_number']); ?>">
    <input type="hidden" name="inspector" value="<?php echo htmlspecialchars($firstRow['inspector']); ?>">
    
    <div style="margin-bottom:20px; padding:15px; background:#e8f5e9; border-radius:8px;">
      <p><strong>Entry ID:</strong> <?php echo htmlspecialchars($entry_id); ?></p>
      <p><strong>Date & Time:</strong> <?php echo date('d M Y, h:i A', strtotime($firstRow['date_time'])); ?></p>
      <p><strong>Shift:</strong> <?php echo htmlspecialchars($firstRow['shift']); ?></p>
      <p><strong>Line Number:</strong> <?php echo htmlspecialchars($firstRow['line_number']); ?></p>
      <p><strong>Inspector:</strong> <?php echo htmlspecialchars($firstRow['inspector']); ?></p>
    </div>

    <h3 style="margin-bottom:15px;">Edit Calibration Measurements</h3>
    <div style="overflow-x:auto;">
      <table class="cal-table">
        <thead>
          <tr>
            <th>Roll No</th>
            <th>Reference<br>Length</th>
            <th>Set in<br>Machine</th>
            <th>Actual<br>Length</th>
            <th>Difference<br>(Ref - Actual)</th>
            <th>Calibration<br>Length</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="editCalTableBody">
          <?php foreach ($calibrations as $index => $cal): ?>
          <tr id="edit_row_<?php echo $index; ?>">
            <td><input type="text" name="roll_no[]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($cal['roll_no']); ?>" required></td>
            <td><input type="number" step="0.01" name="reference_length[]" id="edit_ref_length_<?php echo $index; ?>" class="form-control form-control-sm" value="<?php echo $cal['reference_length']; ?>" oninput="calculateDiff(<?php echo $index; ?>)" required></td>
            <td><input type="number" step="0.01" name="set_in_machine[]" class="form-control form-control-sm" value="<?php echo $cal['set_in_machine']; ?>" required></td>
            <td><input type="number" step="0.01" name="actual_length[]" id="edit_actual_length_<?php echo $index; ?>" class="form-control form-control-sm" value="<?php echo $cal['actual_length']; ?>" oninput="calculateDiff(<?php echo $index; ?>)" required></td>
            <td><div class="auto-calculation" id="edit_diff_<?php echo $index; ?>"><?php echo number_format($cal['difference'], 2); ?></div></td>
            <td><input type="number" step="0.01" name="calibration_length[]" class="form-control form-control-sm" value="<?php echo $cal['calibration_length']; ?>" required></td>
            <td>
              <?php if ($index > 0): ?>
              <button type="button" class="remove-btn" onclick="removeRow(<?php echo $index; ?>)">Remove</button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <button type="button" class="add-row-btn" onclick="addRow()">
      <i class="fas fa-plus"></i> Add 1 More Row
    </button>

    <div style="margin-top:30px; text-align:center;">
      <button type="submit" class="btn submit-btn">
        <i class="fas fa-check"></i> Resubmit for Approval
      </button>
      <a href="length_calibration_entry.php" class="btn cancel-btn">
        <i class="fas fa-times"></i> Cancel
      </a>
    </div>
  </form>
</div>

<script>
let rowCount = <?php echo count($calibrations); ?>;

function addRow() {
  rowCount++;
  const tbody = document.getElementById('editCalTableBody');
  const row = document.createElement('tr');
  row.id = 'edit_row_' + rowCount;
  
  row.innerHTML = `
    <td><input type="text" name="roll_no[]" class="form-control form-control-sm" required></td>
    <td><input type="number" step="0.01" name="reference_length[]" id="edit_ref_length_${rowCount}" class="form-control form-control-sm" oninput="calculateDiff(${rowCount})" required></td>
    <td><input type="number" step="0.01" name="set_in_machine[]" class="form-control form-control-sm" required></td>
    <td><input type="number" step="0.01" name="actual_length[]" id="edit_actual_length_${rowCount}" class="form-control form-control-sm" oninput="calculateDiff(${rowCount})" required></td>
    <td><div class="auto-calculation" id="edit_diff_${rowCount}">-</div></td>
    <td><input type="number" step="0.01" name="calibration_length[]" class="form-control form-control-sm" required></td>
    <td><button type="button" class="remove-btn" onclick="removeRow(${rowCount})">Remove</button></td>
  `;
  
  tbody.appendChild(row);
}

function calculateDiff(rowId) {
  const refLength = parseFloat(document.getElementById('edit_ref_length_' + rowId)?.value) || 0;
  const actualLength = parseFloat(document.getElementById('edit_actual_length_' + rowId)?.value) || 0;
  const difference = refLength - actualLength;
  const diffElement = document.getElementById('edit_diff_' + rowId);
  if (diffElement) {
    diffElement.textContent = difference.toFixed(2);
  }
}

function removeRow(rowId) {
  const row = document.getElementById('edit_row_' + rowId);
  if (row) {
    row.remove();
  }
}
</script>
</body>
</html>


