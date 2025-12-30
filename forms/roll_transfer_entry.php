<?php
session_start();
require_once 'security_config.php';

// Security/session checks
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

// Role-based access control for Sheet Production module
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_ROLL_PRODUCTION, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>Access Denied</h2>
        <p>You do not have permission to access the Sheet Production module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// Connect DB
$conn = SecurityConfig::getConnection();

// Fetch reference numbers from roll_entry that have passed QC testing and are approved for FG production (schema-aware)
$referenceNumbers = [];
$hasRollDest = $conn->query("SHOW COLUMNS FROM qc_test_orders LIKE 'roll_destination'");
$rollDestFilter = ($hasRollDest && $hasRollDest->num_rows > 0) ? "AND qto.roll_destination = 'fg_production'" : "";
$res = $conn->query("
    SELECT DISTINCT 
        re.reference_number,
        re.total_weight,
        COALESCE(SUM(rt.amount_kg), 0) as transferred_amount,
        (re.total_weight - COALESCE(SUM(rt.amount_kg), 0)) as available_amount
    FROM roll_entry re
    INNER JOIN qc_test_orders qto ON qto.sample_reference_id = re.reference_number
    LEFT JOIN roll_transfer rt ON rt.reference_number = re.reference_number
    WHERE re.reference_number IS NOT NULL 
    AND qto.status = 'approved'
    {$rollDestFilter}
    GROUP BY re.reference_number, re.total_weight
    HAVING available_amount > 0
    ORDER BY re.created_at DESC
");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        if ($r['reference_number']) {
            $referenceNumbers[] = $r;
        }
    }
}

// Fetch drivers (optional table, dynamic columns)
$drivers = [];
$drivers_available = false;
$chk = $conn->query("SHOW TABLES LIKE 'drivers'");
if ($chk && $chk->num_rows > 0) {
    $drivers_available = true;
    $nameCol = 'driver_name';
    $cols = [];
    $dbRow = $conn->query("SELECT DATABASE() AS d")->fetch_assoc();
    $dbName = $dbRow ? $dbRow['d'] : 'geobagg';
    $colRes = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='" . $conn->real_escape_string($dbName) . "' AND TABLE_NAME='drivers'");
    if ($colRes) { while ($cr = $colRes->fetch_assoc()) { $cols[] = $cr['COLUMN_NAME']; } }
    if (!in_array('driver_name', $cols)) {
        foreach (['name','full_name','driver','driver_full_name'] as $cand) {
            if (in_array($cand, $cols)) { $nameCol = $cand; break; }
        }
    }
    $dres = $conn->query("SELECT id, `" . $conn->real_escape_string($nameCol) . "` AS driver_name FROM drivers ORDER BY `" . $conn->real_escape_string($nameCol) . "` ASC");
    if ($dres) {
        while ($d = $dres->fetch_assoc()) $drivers[] = $d;
    }
}

// Next transfer id (tolerant if table doesn't exist yet)
$next_transfer = 1;
$hasRollTransfer = $conn->query("SHOW TABLES LIKE 'roll_transfer'");
if ($hasRollTransfer && $hasRollTransfer->num_rows > 0) {
    $t = $conn->query("SELECT MAX(transfer_id) AS t FROM roll_transfer");
    if ($t && ($row = $t->fetch_assoc())) {
        $next_transfer = ((int)($row['t'] ?? 0)) + 1;
    }
}

// Operator from session
$operator_id = $_SESSION['user_id'];
$operator_name = $_SESSION['username'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Roll Transfer Entry</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
  .container { max-width:1000px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);}
  h1 { text-align:center; font-size:28px; margin-bottom:30px; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  input[type="text"], input[type="number"], select {
    padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px);
  }
  .btn-group { display:flex; flex-wrap:wrap; gap:10px; }
  .btn { padding:10px 16px; font-size:14px; border:none; border-radius:6px; cursor:pointer; background:#e0e0e0; }
  .btn.selected { background:#3498db; color:#fff; }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .readonly { background:#ecf0f1; }
</style>
</head>
<body>
<div class="container">
  
  <h1>Roll Transfer Entry</h1>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 10px; border: 1px solid #c3e6cb; border-radius: 4px; margin: 10px 0;">
      <?php echo htmlspecialchars($_GET['success']); ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger" style="background: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb; border-radius: 4px; margin: 10px 0;">
      <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <form id="transferForm" method="post" action="../handlers/submit_roll_transfer_entry.php" onsubmit="return validateForm();">

    <!-- Transfer ID auto -->
    <div class="form-group">
      <label>Transfer ID </label>
      <input type="text" value="<?php echo $next_transfer; ?>" readonly class="readonly">
      <input type="hidden" name="transfer_id" value="<?php echo $next_transfer; ?>">
    </div>

    <!-- Hidden datetime -->
    <input type="hidden" id="dateTime" name="date_time">

    <!-- Operator -->
    <div class="form-group">
      <label>Operator </label>
      <input type="text" value="<?php echo htmlspecialchars($operator_name); ?>" readonly class="readonly">
      <input type="hidden" name="operator_id" value="<?php echo $operator_id; ?>">
      <input type="hidden" name="operator_name" value="<?php echo htmlspecialchars($operator_name); ?>">
    </div>

    <!-- Reference Number -->
    <div class="form-group">
      <label>Reference Number</label>
      <select id="reference_number" name="reference_number" required onchange="loadAvailableAmount()">
        <option value="">-- Select Reference Number --</option>
        <?php foreach($referenceNumbers as $ref): ?>
          <option value="<?php echo htmlspecialchars($ref['reference_number']); ?>"
                  data-total-weight="<?php echo $ref['total_weight']; ?>"
                  data-transferred="<?php echo $ref['transferred_amount']; ?>"
                  data-available="<?php echo $ref['available_amount']; ?>">
            <?php echo htmlspecialchars($ref['reference_number']); ?> - Available: <?php echo number_format($ref['available_amount'], 2); ?> kg
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- From (Fixed) -->
    <div class="form-group">
      <label>From</label>
      <input type="text" id="from_location" name="from_location" value="Production Floor" readonly class="readonly">
    </div>

    <!-- To (Buttons) -->
    <div class="form-group">
      <label>To</label>
      <div class="btn-group" id="toLocationGroup">
        <button type="button" class="btn" data-value="Swing" onclick="selectBtn(this, 'toLocationGroup')">Swing</button>
      </div>
      <input type="hidden" id="to_location" name="to_location" value="">
    </div>

    <!-- Driver -->
    <div class="form-group">
      <label>Driver</label>
      <?php if ($drivers_available && !empty($drivers)): ?>
        <select id="driver_id" name="driver_id" required>
          <option value="">-- Select Driver --</option>
          <?php foreach($drivers as $d): ?>
            <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['driver_name']); ?></option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <input type="text" id="driver_name" name="driver_name" placeholder="Enter driver name" required>
      <?php endif; ?>
    </div>

    <!-- Amount -->
    <div class="form-group">
      <label>Amount (kg)</label>
      <input type="number" step="1" id="amount" name="amount" required min="1" oninput="validateAmount()">
      <small id="available_amount_text" style="color:#27ae60; font-weight:600; display:none; margin-top:5px;"></small>
      <small id="amount_warning" style="color:#e74c3c; font-weight:600; display:none; margin-top:5px;"></small>
    </div>

    <!-- Summary Section -->
    <div class="form-group">
      <label>Summary</label>
      <div id="summaryBox" class="summary-info">
        Please fill in the details above to generate a summary.
      </div>
      <input type="hidden" id="summary" name="summary">
    </div>

    <div class="actions">
      <button type="submit" class="submit-btn">Submit</button>
      <button type="button" class="clear-btn" onclick="clearForm()">Clear</button>
    </div>
  </form>
</div>

<script>
function loadAvailableAmount() {
  const refSelect = document.getElementById('reference_number');
  const selectedOption = refSelect.options[refSelect.selectedIndex];
  const availableText = document.getElementById('available_amount_text');
  const warningText = document.getElementById('amount_warning');
  const amountInput = document.getElementById('amount');
  
  if (selectedOption && selectedOption.value) {
    const totalWeight = parseFloat(selectedOption.getAttribute('data-total-weight')) || 0;
    const transferred = parseFloat(selectedOption.getAttribute('data-transferred')) || 0;
    const available = parseFloat(selectedOption.getAttribute('data-available')) || 0;
    
    // Display available amount
    availableText.textContent = `âœ“ Available: ${available.toFixed(2)} kg (Total: ${totalWeight.toFixed(2)} kg, Already Transferred: ${transferred.toFixed(2)} kg)`;
    availableText.style.color = '#27ae60';
    availableText.style.display = 'block';
    warningText.style.display = 'none';
    
    // Store for validation
    amountInput.setAttribute('data-max-amount', available);
    amountInput.max = available;
  } else {
    availableText.style.display = 'none';
    warningText.style.display = 'none';
    amountInput.removeAttribute('data-max-amount');
    amountInput.removeAttribute('max');
  }
}

function validateAmount() {
  const amountInput = document.getElementById('amount');
  const amount = parseFloat(amountInput.value) || 0;
  const maxAmount = parseFloat(amountInput.getAttribute('data-max-amount')) || 0;
  const warningText = document.getElementById('amount_warning');
  const availableText = document.getElementById('available_amount_text');
  
  if (maxAmount > 0 && amount > maxAmount) {
    warningText.textContent = `âš ï¸ Amount (${amount} kg) exceeds available quantity (${maxAmount.toFixed(2)} kg)`;
    warningText.style.display = 'block';
    amountInput.style.border = '2px solid #e74c3c';
    amountInput.style.borderColor = '#e74c3c';
  } else {
    warningText.style.display = 'none';
    amountInput.style.border = '1px solid #ccc';
    amountInput.style.borderColor = '#ccc';
    
    // Keep available text visible and green
    if (availableText && maxAmount > 0) {
      availableText.style.color = '#27ae60';
      availableText.style.display = 'block';
    }
  }
}

function updateTimeAndShift() {
  const now = new Date();
  const utc = now.getTime() + now.getTimezoneOffset()*60000;
  const dhaka = new Date(utc + 6*3600000);
  document.getElementById("dateTimeDisplay").innerHTML =
    "Date & Time: " + dhaka.toDateString() + " " + dhaka.toLocaleTimeString();
  document.getElementById("dateTime").value = dhaka.toISOString().slice(0,19).replace("T"," ");
  const h = dhaka.getHours();
  document.getElementById("shiftBanner").innerText = "Shift: " + ((h>=8&&h<20)?"Day":"Night");
  updateSummary();
}
setInterval(updateTimeAndShift,1000); updateTimeAndShift();

function selectBtn(btn, groupId) {
  document.querySelectorAll(`#${groupId} .btn`).forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  if (groupId === 'toLocationGroup') {
    document.getElementById('to_location').value = btn.dataset.value;
  }
  updateSummary();
}

function updateSummary() {
  const dateTime = document.getElementById("dateTime").value;
  const shift = document.getElementById("shiftBanner").innerText.replace("Shift: ", "");
  const operator = "<?php echo htmlspecialchars($operator_name); ?>";
  const referenceNumber = document.getElementById("reference_number").value;
  
  let driverName = "";
  const driverSelect = document.getElementById("driver_id");
  const driverInput = document.getElementById("driver_name");
  if (driverSelect) {
    driverName = driverSelect.value ? driverSelect.options[driverSelect.selectedIndex].text : "";
  } else if (driverInput) {
    driverName = driverInput.value;
  }

  const amount = document.getElementById("amount").value;
  const fromLocation = document.getElementById("from_location").value;
  const toLocation = document.getElementById("to_location").value;

  let summary = `${dateTime} | Shift: ${shift} | Operator: ${operator}`;
  if (referenceNumber) summary += ` | Ref: ${referenceNumber}`;
  if (driverName) summary += ` | Driver: ${driverName}`;
  if (amount) summary += ` | Amount: ${amount}`;
  if (fromLocation) summary += ` | From: ${fromLocation}`;
  if (toLocation) summary += ` | To: ${toLocation}`;

  document.getElementById("summaryBox").innerText = summary;
  document.getElementById("summary").value = summary;
}

// Add event listeners for summary updates
document.getElementById('reference_number').addEventListener('change', updateSummary);
if (document.getElementById('driver_id')) {
  document.getElementById('driver_id').addEventListener('change', updateSummary);
}
if (document.getElementById('driver_name')) {
  document.getElementById('driver_name').addEventListener('input', updateSummary);
}
document.getElementById('amount').addEventListener('input', updateSummary);

// Initialize summary on page load
updateSummary();

function clearForm() {
  document.getElementById("reference_number").value = "";
  if (document.getElementById("driver_id")) document.getElementById("driver_id").value = "";
  if (document.getElementById("driver_name")) document.getElementById("driver_name").value = "";
  document.getElementById("amount").value = "";
  document.getElementById("to_location").value = "";
  document.querySelectorAll('#toLocationGroup .btn').forEach(b => b.classList.remove('selected'));
  document.getElementById("summaryBox").innerText = "Please fill in the details above to generate a summary.";
  document.getElementById("summary").value = "";
}

function validateForm(){
  if(!document.getElementById("reference_number").value){
    alert("Select a reference number."); return false;
  }
  if(!document.getElementById("to_location").value){
    alert("Select a destination (To)."); return false;
  }
  if(document.getElementById("driver_id") && !document.getElementById("driver_id").value){
    alert("Select a driver."); return false;
  }
  if(document.getElementById("driver_name") && !document.getElementById("driver_name").value){
    alert("Enter driver name."); return false;
  }
  
  // Validate amount against available quantity
  const amountInput = document.getElementById('amount');
  const amount = parseFloat(amountInput.value) || 0;
  const maxAmount = parseFloat(amountInput.getAttribute('data-max-amount')) || 0;
  
  if (maxAmount > 0 && amount > maxAmount) {
    alert(`âŒ Amount (${amount} kg) exceeds available quantity (${maxAmount.toFixed(2)} kg). Please reduce the amount.`);
    return false;
  }
  
  if (amount <= 0) {
    alert("Please enter a valid amount greater than 0.");
    return false;
  }
  
  return true;
}
</script>
</body>
</html>


