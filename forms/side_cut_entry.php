<?php
session_start();
require_once 'security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}

if (SecurityConfig::checkSessionTimeout()) {
    session_destroy();
    header("Location: login.html?error=timeout");
    exit();
}

SecurityConfig::updateSessionActivity();

if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    session_destroy();
    header("Location: login.html?error=disabled");
    exit();
}

// Role-based access control for Scrap module
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_SCRAP, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access the Scrap/Waste module.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background:rgb(219, 77, 52); color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Get current shift info for filtering
$currentDate = date('Y-m-d');
$currentHour = (int)date('H');
$currentShift = ($currentHour >= 8 && $currentHour <= 19) ? 'Day' : 'Night';

// Fetch reference numbers from fiber_to_roll_entry excluding already recorded ones for current shift
$sheetReferences = [];
$sheetQuery = "SELECT DISTINCT ftr.reference_number 
               FROM fiber_to_roll_entry ftr
               LEFT JOIN side_cut_scrap scs ON scs.reference_number = ftr.reference_number 
                   AND scs.entry_date = ? 
                   AND scs.shift = ? 
                   AND scs.category = 'Sheet Production'
               WHERE ftr.reference_number IS NOT NULL 
                   AND scs.id IS NULL
               ORDER BY ftr.date_time DESC 
               LIMIT 50";
$sheetStmt = $conn->prepare($sheetQuery);
if ($sheetStmt) {
    $sheetStmt->bind_param('ss', $currentDate, $currentShift);
    $sheetStmt->execute();
    $sheetResult = $sheetStmt->get_result();
    while ($row = $sheetResult->fetch_assoc()) {
        $sheetReferences[] = $row['reference_number'];
    }
    $sheetStmt->close();
}

// Fetch CNC cutting batches from cnc_entries excluding already recorded ones for current shift
$cncBatches = [];
$cncQuery = "SELECT DISTINCT ce.cnc_cutting_batch 
             FROM cnc_entries ce
             LEFT JOIN side_cut_scrap scs ON scs.cutting_batch_no = ce.cnc_cutting_batch 
                 AND scs.entry_date = ? 
                 AND scs.shift = ? 
                 AND scs.category = 'Swing Production'
             WHERE ce.cnc_cutting_batch IS NOT NULL 
                 AND scs.id IS NULL
             ORDER BY ce.date_time DESC 
             LIMIT 50";
$cncStmt = $conn->prepare($cncQuery);
if ($cncStmt) {
    $cncStmt->bind_param('ss', $currentDate, $currentShift);
    $cncStmt->execute();
    $cncResult = $cncStmt->get_result();
    while ($row = $cncResult->fetch_assoc()) {
        $cncBatches[] = $row['cnc_cutting_batch'];
    }
    $cncStmt->close();
}

// Generate Entry ID (resets at 8 AM daily)
$nextEntryNumber = 1;
$table_check = $conn->query("SHOW TABLES LIKE 'side_cut_scrap'");
if ($table_check && $table_check->num_rows > 0) {
    // Check if entry_id column exists
    $column_check = $conn->query("SHOW COLUMNS FROM side_cut_scrap LIKE 'entry_id'");
    if ($column_check && $column_check->num_rows > 0) {
        // Get current time in Bangladesh timezone
        $dhaka_tz = new DateTimeZone('Asia/Dhaka');
        $now = new DateTime('now', $dhaka_tz);
        
        // Determine the reset date (8 AM today or yesterday if before 8 AM)
        $reset_date = clone $now;
        if ($now->format('H') < 8) {
            $reset_date->modify('-1 day');
        }
        $reset_date->setTime(8, 0, 0);
        $reset_timestamp = $reset_date->format('Y-m-d H:i:s');
        
        // Get the max number for entries created since 8 AM
        $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(entry_id, -3) AS UNSIGNED)) as last_num 
                                FROM side_cut_scrap 
                                WHERE created_at >= ?");
        if ($stmt) {
            $stmt->bind_param('s', $reset_timestamp);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                if ($row['last_num']) {
                    $nextEntryNumber = $row['last_num'] + 1;
                }
            }
            $stmt->close();
        }
    }
}

// Format the Entry ID
$entryId = 'SIC-' . date('Ymd') . '-' . str_pad($nextEntryNumber, 3, '0', STR_PAD_LEFT);

// Check for existing entries for current shift
$currentDate = date('Y-m-d');
$currentHour = (int)date('H');
$currentShift = ($currentHour >= 8 && $currentHour <= 19) ? 'Day' : 'Night';

$existingEntries = [];
$checkQuery = "SELECT category, reference_number, cutting_batch_no, quantity_kg 
               FROM side_cut_scrap 
               WHERE entry_date = ? AND shift = ?";
$checkStmt = $conn->prepare($checkQuery);
if ($checkStmt) {
    $checkStmt->bind_param('ss', $currentDate, $currentShift);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $existingEntries[] = $row;
    }
    $checkStmt->close();
}

$reporter_id = $_SESSION['user_id'];
// Get reporter name - try full_name first, then username
$reporter_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown';
?>
<!DOCTYPE html>
<html lang="en">  
<head>
<meta charset="UTF-8">
<title>Side Cut Entry</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:0; color:#2c3e50; }
  .container { max-width:100%; margin:0; background:#fff; border-radius:0; padding:25px 80px; box-shadow:none;}
  h1 { text-align:center; font-size:28px; margin-bottom:30px; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  input[type="text"], input[type="number"], input[type="date"], select, textarea {
    padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px);
  }
  select { width:100%; }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .readonly { background:#ecf0f1; }
  .btn-group { display:flex; flex-wrap:wrap; gap:10px; }
  .btn { padding:10px 16px; font-size:14px; border:none; border-radius:6px; cursor:pointer; background-color:#f8f9fa; }
  .btn:hover { background-color:#ccc; }
  .btn.selected { background-color:#3498db; color:white; }
  .alert-success { background:#d4edda; color:#155724; padding:15px; border-radius:6px; margin-bottom:20px; border:1px solid #c3e6cb; }
  .alert-error { background:#f8d7da; color:#721c24; padding:15px; border-radius:6px; margin-bottom:20px; border:1px solid #f5c6cb; }
</style>
</head>
<body>
<div class="container">
  
  <h1>Side Cut Entry (End of Shift)</h1>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert-success">
      <?php echo htmlspecialchars($_GET['success']); ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert-error">
      ❌ Error: <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <?php if (count($existingEntries) > 0): ?>
    <div style="background:#fff3cd; color:#856404; padding:15px; border-radius:6px; margin-bottom:20px; border:1px solid #ffeaa7;">
      <strong>⚠️ Side Cut already recorded for this shift:</strong>
      <ul style="margin: 10px 0 0 20px;">
        <?php foreach($existingEntries as $entry): ?>
          <li>
            <strong><?php echo htmlspecialchars($entry['category']); ?></strong>
            <?php if ($entry['reference_number']): ?>
              - Ref: <?php echo htmlspecialchars($entry['reference_number']); ?>
            <?php endif; ?>
            <?php if ($entry['cutting_batch_no']): ?>
              - Batch: <?php echo htmlspecialchars($entry['cutting_batch_no']); ?>
            <?php endif; ?>
            - Qty: <?php echo number_format($entry['quantity_kg'], 2); ?> kg
          </li>
        <?php endforeach; ?>
      </ul>
      <p style="margin-top: 10px; font-size: 0.9em;">You can still add entries for different categories or batches.</p>
    </div>
  <?php endif; ?>

  <!-- Back to Dashboard Link -->
  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
  </div>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <form id="sideCutForm" method="post" action="../handlers/submit_side_cut_entry.php" onsubmit="return validateForm();">

    <!-- Hidden fields -->
    <input type="hidden" id="entry_date" name="entry_date">
    <input type="hidden" id="shift" name="shift">
    <input type="hidden" name="reporter_id" value="<?php echo $reporter_id; ?>">
    <input type="hidden" name="reporter_name" value="<?php echo htmlspecialchars($reporter_name); ?>">

    <!-- Entry ID (Auto-generated, readonly) -->
    <div class="form-group">
      <label>Entry ID:</label>
      <input type="text" id="entryId" name="entry_id" value="<?php echo htmlspecialchars($entryId); ?>" readonly class="readonly" required>
    </div>

    <!-- Category Selection -->
    <div class="form-group">
      <label>Production Category: </label>
      <div class="btn-group" id="categoryGroup">
        <button type="button" class="btn" data-value="Sheet Production" onclick="selectCategory(this)">Sheet Production</button>
        <button type="button" class="btn" data-value="Swing Production" onclick="selectCategory(this)">Swing Production</button>
      </div>
      <input type="hidden" id="category" name="category" value="">
    </div>

    <!-- Reference Number (Only for Sheet Production) -->
    <div class="form-group" id="referenceSection" style="display:none;">
      <label>Reference Number: </label>
      <select id="reference_number" name="reference_number">
        <option value="">-- Select Reference Number --</option>
        <?php foreach($sheetReferences as $ref): ?>
          <option value="<?php echo htmlspecialchars($ref); ?>"><?php echo htmlspecialchars($ref); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Cutting Batch (Only for Swing Production) -->
    <div class="form-group" id="cuttingBatchSection" style="display:none;">
      <label>CNC Cutting Batch: </label>
      <select id="cutting_batch_no" name="cutting_batch_no">
        <option value="">-- Select CNC Cutting Batch --</option>
        <?php foreach($cncBatches as $batch): ?>
          <option value="<?php echo htmlspecialchars($batch); ?>"><?php echo htmlspecialchars($batch); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Quantity (kg) -->
    <div class="form-group">
      <label>Side Cut Quantity (kg): </label>
      <input type="number" id="quantity_kg" name="quantity_kg" min="0.01" step="0.01" required>
    </div>

    <!-- Remarks (Optional) -->
    <div class="form-group">
      <label>Remarks (Optional): </label>
      <textarea id="remarks" name="remarks" rows="3" placeholder="Enter any additional notes..."></textarea>
    </div>

    <!-- Summary Section -->
    <div class="form-group">
      <div id="summaryBox" class="summary-info"></div>
    </div>

    <div class="actions">
      <button type="submit" class="submit-btn">Submit</button>
      <button type="button" class="clear-btn" onclick="clearForm()">Clear</button>
    </div>
  </form>
</div>

<script>
function updateTimeAndShift() {
  const now = new Date();
  const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
  const dhaka = new Date(utc + (6 * 3600000));
  
  document.getElementById("dateTimeDisplay").innerHTML = 
    "Date: " + dhaka.toDateString() + " " + dhaka.toLocaleTimeString();
  
  const yyyy = dhaka.getFullYear();
  const mm = String(dhaka.getMonth() + 1).padStart(2, '0');
  const dd = String(dhaka.getDate()).padStart(2, '0');
  document.getElementById("entry_date").value = `${yyyy}-${mm}-${dd}`;
  
  const h = dhaka.getHours();
  const shift = (h >= 8 && h <= 19) ? "Day" : "Night";
  document.getElementById("shiftBanner").innerText = "Shift: " + shift;
  document.getElementById("shift").value = shift;
  
  updateSummary();
}
setInterval(updateTimeAndShift, 1000);
updateTimeAndShift();

function selectCategory(btn) {
  const group = document.getElementById('categoryGroup');
  const buttons = group.querySelectorAll('button');
  buttons.forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  
  document.getElementById('category').value = btn.dataset.value;
  
  // Show/hide appropriate field based on category
  if (btn.dataset.value === 'Sheet Production') {
    document.getElementById('referenceSection').style.display = 'block';
    document.getElementById('cuttingBatchSection').style.display = 'none';
    document.getElementById('cutting_batch_no').value = '';
  } else if (btn.dataset.value === 'Swing Production') {
    document.getElementById('cuttingBatchSection').style.display = 'block';
    document.getElementById('referenceSection').style.display = 'none';
    document.getElementById('reference_number').value = '';
  }
  
  updateSummary();
}

function updateSummary() {
  const entryId = document.getElementById('entryId').value;
  const date = document.getElementById('entry_date').value;
  const shift = document.getElementById('shift').value;
  const category = document.getElementById('category').value;
  const ref = document.getElementById('reference_number').value;
  const batch = document.getElementById('cutting_batch_no').value;
  const qty = document.getElementById('quantity_kg').value;
  const remarks = document.getElementById('remarks').value;
  
  if (date && shift) {
    let s = `${entryId} | ${date} | Shift: ${shift}`;
    if (category) s += ` | Category: ${category}`;
    if (ref) s += ` | Ref: ${ref}`;
    if (batch) s += ` | Batch: ${batch}`;
    if (qty) s += ` | Side Cut: ${qty} kg`;
    if (remarks) s += ` | Remarks: ${remarks}`;
    
    document.getElementById('summaryBox').innerText = s;
  } else {
    document.getElementById('summaryBox').innerText = '';
  }
}

function validateForm() {
  if (!document.getElementById('category').value) {
    alert('Please select a production category.');
    return false;
  }
  
  if (document.getElementById('category').value === 'Sheet Production' && 
      !document.getElementById('reference_number').value) {
    alert('Please select a Reference Number for Sheet Production.');
    return false;
  }
  
  if (document.getElementById('category').value === 'Swing Production' && 
      !document.getElementById('cutting_batch_no').value) {
    alert('Please select a CNC Cutting Batch for Swing Production.');
    return false;
  }
  
  if (!document.getElementById('quantity_kg').value) {
    alert('Please enter side cut quantity.');
    return false;
  }
  
  return true;
}

function clearForm() {
  document.getElementById('sideCutForm').reset();
  document.querySelectorAll('.btn-group .btn').forEach(b => b.classList.remove('selected'));
  document.getElementById('category').value = '';
  document.getElementById('referenceSection').style.display = 'none';
  document.getElementById('cuttingBatchSection').style.display = 'none';
  updateSummary();
}

// Add event listeners
document.getElementById('quantity_kg').addEventListener('input', updateSummary);
document.getElementById('reference_number').addEventListener('change', updateSummary);
document.getElementById('cutting_batch_no').addEventListener('change', updateSummary);
document.getElementById('remarks').addEventListener('input', updateSummary);
</script>
</body>
</html>


