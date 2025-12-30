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
$stmt = $conn->prepare("SELECT * FROM daily_gsm_checks WHERE entry_id = ? AND user_id = ? AND status = 'rejected' ORDER BY id");
$stmt->bind_param("si", $entry_id, $userId);
$stmt->execute();
$result = $stmt->get_result();

$checks = [];
$firstRow = null;
while ($row = $result->fetch_assoc()) {
    if (!$firstRow) {
        $firstRow = $row;
    }
    $checks[] = $row;
}
$stmt->close();

if (!$firstRow) {
    die('Entry not found or access denied');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Delete old rejected entries
    $conn->query("DELETE FROM daily_gsm_checks WHERE entry_id = '$entry_id' AND user_id = $userId AND status = 'rejected'");
    
    // Insert new entries (same logic as submit_daily_gsm_check.php but reusing entry_id)
    $dateTime = $_POST['date_time'] ?? '';
    $shift = $_POST['shift'] ?? '';
    $lineNumber = $_POST['line_number'] ?? '';
    $inspector = $_POST['inspector'] ?? '';
    
    $rollNos = $_POST['roll_no'] ?? [];
    $sizeTypes = $_POST['size_type'] ?? [];
    $sizeValues = $_POST['size_value'] ?? [];
    $weightLeft = $_POST['weight_left'] ?? [];
    $weightLeftMiddle = $_POST['weight_left_middle'] ?? [];
    $weightRightMiddle = $_POST['weight_right_middle'] ?? [];
    $weightRight = $_POST['weight_right'] ?? [];
    $gsmLeft = $_POST['gsm_left'] ?? [];
    $gsmLeftMiddle = $_POST['gsm_left_middle'] ?? [];
    $gsmRightMiddle = $_POST['gsm_right_middle'] ?? [];
    $gsmRight = $_POST['gsm_right'] ?? [];
    $avgGsm = $_POST['avg_gsm'] ?? [];
    $remarks = $_POST['remarks'] ?? [];
    
    $insertedCount = 0;
    
    for ($i = 0; $i < count($rollNos); $i++) {
        if (empty($rollNos[$i])) continue;
        
        $stmt = $conn->prepare("INSERT INTO daily_gsm_checks 
            (entry_id, date_time, shift, line_number, roll_no, size_type, size_value,
             weight_left, weight_left_middle, weight_right_middle, weight_right,
             gsm_left, gsm_left_middle, gsm_right_middle, gsm_right, avg_gsm,
             remarks, inspector, user_id, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        
        $sizeType = $sizeTypes[$i] ?? 'N/A';
        $sizeValue = $sizeValues[$i] ?? '';
        $remark = $remarks[$i] ?? '';
        
        $stmt->bind_param('sssssssdddddddddssi',
            $entry_id,
            $dateTime,
            $shift,
            $lineNumber,
            $rollNos[$i],
            $sizeType,
            $sizeValue,
            $weightLeft[$i],
            $weightLeftMiddle[$i],
            $weightRightMiddle[$i],
            $weightRight[$i],
            $gsmLeft[$i],
            $gsmLeftMiddle[$i],
            $gsmRightMiddle[$i],
            $gsmRight[$i],
            $avgGsm[$i],
            $remark,
            $inspector,
            $userId
        );
        
        if ($stmt->execute()) {
            $insertedCount++;
        }
        $stmt->close();
    }
    
    $conn->close();
    header("Location: daily_gsm_check.php?success=" . urlencode("GSM check resubmitted! Entry ID: $entry_id | $insertedCount row(s). Status: Pending Approval"));
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Rejected GSM Check - <?php echo htmlspecialchars($entry_id); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:20px; color:#2c3e50; }
  .container { max-width:1800px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08); }
  h1 { font-size:24px; margin-bottom:20px; color:#2c3e50; }
  .rejection-box { background:#f8d7da; border:1px solid #f5c6cb; padding:15px; border-radius:8px; margin-bottom:20px; }
  .rejection-box h3 { color:#721c24; margin-bottom:10px; font-size:16px; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  input[type="text"], input[type="number"], select { padding:8px; border:1px solid #ccc; border-radius:6px; }
  .gsm-table { width:100%; border-collapse:collapse; margin-top:20px; font-size:13px; }
  .gsm-table th { padding:10px 5px; border:1px solid #ddd; text-align:center; background:#667eea; color:white; font-size:12px; }
  .gsm-table td { padding:5px; border:1px solid #ddd; text-align:center; }
  .gsm-table input { width:100%; padding:6px; text-align:center; font-size:12px; }
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
</style>
</head>
<body>
<div class="container">
  <h1><i class="fas fa-edit"></i> Edit Rejected GSM Check</h1>
  
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

    <h3 style="margin-bottom:15px;">Edit GSM Measurements</h3>
    <div style="overflow-x:auto;">
      <table class="gsm-table">
        <thead>
          <tr>
            <th>Roll No</th>
            <th>Size Type</th>
            <th>Size Value</th>
            <th>Weight<br>Left</th>
            <th>Weight<br>L-Mid</th>
            <th>Weight<br>R-Mid</th>
            <th>Weight<br>Right</th>
            <th>GSM<br>Left</th>
            <th>GSM<br>L-Mid</th>
            <th>GSM<br>R-Mid</th>
            <th>GSM<br>Right</th>
            <th>Avg<br>GSM</th>
            <th>Remarks</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="editTableBody">
          <?php foreach ($checks as $index => $check): ?>
          <tr id="edit_row_<?php echo $index; ?>">
            <td><input type="text" name="roll_no[]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($check['roll_no']); ?>" required></td>
            <td>
              <input type="hidden" name="size_type[]" value="<?php echo htmlspecialchars($check['size_type']); ?>">
              <small><?php echo htmlspecialchars($check['size_type']); ?></small>
            </td>
            <td><input type="text" name="size_value[]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($check['size_value'] ?? ''); ?>"></td>
            <td><input type="number" step="0.01" name="weight_left[]" class="form-control form-control-sm" value="<?php echo $check['weight_left']; ?>" oninput="calculateAvgGSM(<?php echo $index; ?>)" onkeypress="return isNumeric(event)" required></td>
            <td><input type="number" step="0.01" name="weight_left_middle[]" class="form-control form-control-sm" value="<?php echo $check['weight_left_middle']; ?>" oninput="calculateAvgGSM(<?php echo $index; ?>)" onkeypress="return isNumeric(event)" required></td>
            <td><input type="number" step="0.01" name="weight_right_middle[]" class="form-control form-control-sm" value="<?php echo $check['weight_right_middle']; ?>" oninput="calculateAvgGSM(<?php echo $index; ?>)" onkeypress="return isNumeric(event)" required></td>
            <td><input type="number" step="0.01" name="weight_right[]" class="form-control form-control-sm" value="<?php echo $check['weight_right']; ?>" oninput="calculateAvgGSM(<?php echo $index; ?>)" onkeypress="return isNumeric(event)" required></td>
            <td><input type="number" step="0.01" name="gsm_left[]" id="edit_gsm_left_<?php echo $index; ?>" class="form-control form-control-sm" value="<?php echo $check['gsm_left']; ?>" oninput="calculateAvgGSM(<?php echo $index; ?>)" onkeypress="return isNumeric(event)" required></td>
            <td><input type="number" step="0.01" name="gsm_left_middle[]" id="edit_gsm_left_middle_<?php echo $index; ?>" class="form-control form-control-sm" value="<?php echo $check['gsm_left_middle']; ?>" oninput="calculateAvgGSM(<?php echo $index; ?>)" onkeypress="return isNumeric(event)" required></td>
            <td><input type="number" step="0.01" name="gsm_right_middle[]" id="edit_gsm_right_middle_<?php echo $index; ?>" class="form-control form-control-sm" value="<?php echo $check['gsm_right_middle']; ?>" oninput="calculateAvgGSM(<?php echo $index; ?>)" onkeypress="return isNumeric(event)" required></td>
            <td><input type="number" step="0.01" name="gsm_right[]" id="edit_gsm_right_<?php echo $index; ?>" class="form-control form-control-sm" value="<?php echo $check['gsm_right']; ?>" oninput="calculateAvgGSM(<?php echo $index; ?>)" onkeypress="return isNumeric(event)" required></td>
            <td><input type="number" step="0.01" name="avg_gsm[]" id="edit_avg_gsm_<?php echo $index; ?>" class="form-control form-control-sm" value="<?php echo $check['avg_gsm']; ?>" readonly class="readonly"></td>
            <td><input type="text" name="remarks[]" class="form-control form-control-sm" value="<?php echo htmlspecialchars($check['remarks'] ?? ''); ?>"></td>
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
      <a href="daily_gsm_check.php" class="btn cancel-btn">
        <i class="fas fa-times"></i> Cancel
      </a>
    </div>
  </form>
</div>

<script>
// Function to allow only numeric input (numbers, decimal point, backspace, delete, arrow keys)
function isNumeric(event) {
  // Allow: backspace, delete, tab, escape, enter, and arrow keys
  if (event.key === 'Backspace' || event.key === 'Delete' || event.key === 'Tab' || 
      event.key === 'Escape' || event.key === 'Enter' ||
      event.key === 'ArrowLeft' || event.key === 'ArrowRight' || 
      event.key === 'ArrowUp' || event.key === 'ArrowDown') {
    return true;
  }
  // Allow numbers 0-9
  if (event.key >= '0' && event.key <= '9') {
    return true;
  }
  // Allow decimal point only if it doesn't already exist in the input
  if (event.key === '.' || event.key === ',') {
    const decimalChar = event.key === ',' ? '.' : event.key;
    if (event.target.value.indexOf('.') === -1) {
      // Replace comma with dot if user typed comma
      if (event.key === ',') {
        event.preventDefault();
        event.target.value += '.';
        return false;
      }
      return true;
    } else {
      event.preventDefault();
      return false;
    }
  }
  // Block all other characters
  event.preventDefault();
  return false;
}

let rowCount = <?php echo count($checks); ?>;

function addRow() {
  rowCount++;
  const tbody = document.getElementById('editTableBody');
  const row = document.createElement('tr');
  row.id = 'edit_row_' + rowCount;
  
  row.innerHTML = `
    <td><input type="text" name="roll_no[]" class="form-control form-control-sm" required></td>
    <td>
      <input type="hidden" name="size_type[]" value="Bag Size">
      <small>Bag Size</small>
    </td>
    <td><input type="text" name="size_value[]" class="form-control form-control-sm"></td>
    <td><input type="number" step="0.01" name="weight_left[]" class="form-control form-control-sm" oninput="calculateAvgGSM(${rowCount})" onkeypress="return isNumeric(event)" required></td>
    <td><input type="number" step="0.01" name="weight_left_middle[]" class="form-control form-control-sm" oninput="calculateAvgGSM(${rowCount})" onkeypress="return isNumeric(event)" required></td>
    <td><input type="number" step="0.01" name="weight_right_middle[]" class="form-control form-control-sm" oninput="calculateAvgGSM(${rowCount})" onkeypress="return isNumeric(event)" required></td>
    <td><input type="number" step="0.01" name="weight_right[]" class="form-control form-control-sm" oninput="calculateAvgGSM(${rowCount})" onkeypress="return isNumeric(event)" required></td>
    <td><input type="number" step="0.01" name="gsm_left[]" id="edit_gsm_left_${rowCount}" class="form-control form-control-sm" oninput="calculateAvgGSM(${rowCount})" onkeypress="return isNumeric(event)" required></td>
    <td><input type="number" step="0.01" name="gsm_left_middle[]" id="edit_gsm_left_middle_${rowCount}" class="form-control form-control-sm" oninput="calculateAvgGSM(${rowCount})" onkeypress="return isNumeric(event)" required></td>
    <td><input type="number" step="0.01" name="gsm_right_middle[]" id="edit_gsm_right_middle_${rowCount}" class="form-control form-control-sm" oninput="calculateAvgGSM(${rowCount})" onkeypress="return isNumeric(event)" required></td>
    <td><input type="number" step="0.01" name="gsm_right[]" id="edit_gsm_right_${rowCount}" class="form-control form-control-sm" oninput="calculateAvgGSM(${rowCount})" onkeypress="return isNumeric(event)" required></td>
    <td><input type="number" step="0.01" name="avg_gsm[]" id="edit_avg_gsm_${rowCount}" class="form-control form-control-sm" readonly class="readonly"></td>
    <td><input type="text" name="remarks[]" class="form-control form-control-sm"></td>
    <td><button type="button" class="remove-btn" onclick="removeRow(${rowCount})">Remove</button></td>
  `;
  
  tbody.appendChild(row);
}

function calculateAvgGSM(rowId) {
  const gsmLeft = parseFloat(document.getElementById('edit_gsm_left_' + rowId)?.value) || 0;
  const gsmLeftMiddle = parseFloat(document.getElementById('edit_gsm_left_middle_' + rowId)?.value) || 0;
  const gsmRightMiddle = parseFloat(document.getElementById('edit_gsm_right_middle_' + rowId)?.value) || 0;
  const gsmRight = parseFloat(document.getElementById('edit_gsm_right_' + rowId)?.value) || 0;
  
  const avgGsm = (gsmLeft + gsmLeftMiddle + gsmRightMiddle + gsmRight) / 4;
  const avgElement = document.getElementById('edit_avg_gsm_' + rowId);
  if (avgElement) {
    avgElement.value = avgGsm.toFixed(2);
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


