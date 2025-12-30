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

// Role-based access control for QC module
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_QC, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access the QC Entry module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();
$reporter_id = $_SESSION['user_id'];
$reporter_name = $_SESSION['username'];

// Performance: Defer loading - will load asynchronously after page render
$projects = [];
$rollNumbers = [];
$fgEntries = [];

// Latest IDs per stage for display
$latestCncId = '';
$res = $conn->query("SELECT MAX(id) AS mid FROM cnc_entries");
if ($res && ($row=$res->fetch_assoc())) { $latestCncId = $row['mid'] ?? ''; }

$latestCncCode = '';
$res = $conn->query("SELECT cnc_id FROM cnc_entries ORDER BY id DESC LIMIT 1");
if ($res && ($row=$res->fetch_assoc())) { $latestCncCode = $row['cnc_id'] ?? ''; }

$latestProdId = '';
$res = $conn->query("SELECT MAX(id) AS mid FROM production");
if ($res && ($row=$res->fetch_assoc())) { $latestProdId = $row['mid'] ?? ''; }

$latestFgId = '';
$res = $conn->query("SELECT MAX(id) AS mid FROM fg");
if ($res && ($row=$res->fetch_assoc())) { $latestFgId = $row['mid'] ?? ''; }

// Generate QC ID daily reset: QC-YYYYMMDD-XXX
$qc_id = 'QC-' . date('Ymd') . '-001';
$today = date('Y-m-d');
$tbl = $conn->query("SHOW TABLES LIKE 'qc_entries'");
if ($tbl && $tbl->num_rows > 0) {
  $col = $conn->query("SHOW COLUMNS FROM qc_entries LIKE 'qc_id'");
  if ($col && $col->num_rows > 0) {
    $seq = $conn->query("SELECT MAX(CAST(SUBSTRING(qc_id, -3) AS UNSIGNED)) AS last_num FROM qc_entries WHERE DATE(date_time) = '$today'");
    if ($seq && $row = $seq->fetch_assoc()) {
      $next = (!empty($row['last_num']) ? ((int)$row['last_num']) + 1 : 1);
      $qc_id = 'QC-' . date('Ymd') . '-' . str_pad($next, 3, '0', STR_PAD_LEFT);
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>QC Entry</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:0; color:#2c3e50; }
  .container { max-width:100%; margin:0; background:#fff; border-radius:0; padding:25px 80px; box-shadow:none;} 
  h1 { text-align:center; font-size:28px; margin-bottom:30px; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  input[type="text"], input[type="number"], select { padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px); }
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
  .stage-section { display:none; border:1px solid #eee; border-radius:8px; padding:15px; margin-bottom:15px; }
</style>
</head>
<body>
<div class="container">
  <h1>QC Entry</h1>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success" style="background:#d4edda;color:#155724;padding:12px;border-radius:6px;border:1px solid #c3e6cb;margin-bottom:15px;">
      ✅ QC entry saved successfully!
      <?php if (isset($_GET['qc_id'])): ?>
        <br><strong>QC ID: <?php echo htmlspecialchars($_GET['qc_id']); ?></strong>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error" style="background:#f8d7da;color:#721c24;padding:12px;border-radius:6px;border:1px solid #f5c6cb;margin-bottom:15px;">
      ❌ <?php echo htmlspecialchars($_GET['error']); ?>
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

  <form id="qcForm" method="post" action="../handlers/submit_qc_entry.php" onsubmit="return validateForm();">
    <!-- QC ID -->
    <div class="form-group">
      <label>QC ID: </label>
      <input type="text" value="<?php echo htmlspecialchars($qc_id); ?>" readonly class="readonly">
      <input type="hidden" name="qc_id" value="<?php echo htmlspecialchars($qc_id); ?>">
    </div>

    <!-- Stage -->
    <div class="form-group">
      <label>QC Stage:</label>
      <div class="btn-group" id="qcStageGroup">
        <button type="button" class="btn" data-value="CNC" onclick="selectBtn(this,'qcStageGroup')">CNC</button>
        <button type="button" class="btn" data-value="Production" onclick="selectBtn(this,'qcStageGroup')">Production</button>
        <button type="button" class="btn" data-value="FG" onclick="selectBtn(this,'qcStageGroup')">FG</button>
      </div>
      <input type="hidden" id="qc_stage" name="qc_stage" value="">
    </div>

    <!-- QC Type (populated based on Stage) -->
    <div class="form-group">
      <label>QC Type:</label>
      <div class="btn-group" id="qcTypeGroup"></div>
      <input type="hidden" id="qc_type" name="qc_type" value="">
    </div>

    

    <!-- Hidden datetime + shift -->
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">

    <!-- Lightweight CNC Info Section -->
    <div id="sectionCNC" class="stage-section">
      <div class="form-group">
        <label>CNC ID:</label>
        <input type="text" value="<?php echo htmlspecialchars($latestCncId); ?>" readonly class="readonly">
      </div>
      <?php 
      // Only show Reporter Name for non-admin roles
      if (!in_array($_SESSION['role'] ?? '', ['admin', 'agm ops', 'agm operations', 'management'])): 
      ?>
      <div class="form-group">
        <label>Reporter Name:</label>
        <input type="text" value="<?php echo htmlspecialchars($reporter_name); ?>" readonly class="readonly">
      </div>
      <?php endif; ?>
      <div class="form-group">
        <label>Reference Number:</label>
        <select id="roll_number_cnc" name="roll_number_cnc">
          <option value="">-- Loading Reference Numbers... --</option>
        </select>
        <small id="ref_cnc_loading" style="display: block; color: #7f8c8d; font-size: 0.75em; margin-top: 2px;">Loading options...</small>
      </div>
    </div>

    <!-- Lightweight Production Info Section -->
    <div id="sectionProduction" class="stage-section">
      <div class="form-group">
        <label>Production ID:</label>
        <input type="text" value="<?php echo htmlspecialchars($latestProdId); ?>" readonly class="readonly">
      </div>
      <?php 
      // Only show Operator Name for non-admin roles
      if (!in_array($_SESSION['role'] ?? '', ['admin', 'agm ops', 'agm operations', 'management'])): 
      ?>
      <div class="form-group">
        <label>Operator Name:</label>
        <input type="text" value="<?php echo htmlspecialchars($reporter_name); ?>" readonly class="readonly">
      </div>
      <?php endif; ?>
      <div class="form-group">
        <label>Reference Number:</label>
        <select id="roll_number_prod" name="roll_number_prod">
          <option value="">-- Loading Reference Numbers... --</option>
        </select>
        <small id="ref_prod_loading" style="display: block; color: #7f8c8d; font-size: 0.75em; margin-top: 2px;">Loading options...</small>
      </div>
    </div>

    <!-- Lightweight FG Info Section -->
    <div id="sectionFG" class="stage-section">
      <div class="form-group">
        <label>Product Reference (Bags Only):</label>
        <select id="fg_reference" name="fg_reference">
          <option value="">-- Loading Bag References... --</option>
        </select>
        <small id="fg_loading" style="display: block; color: #7f8c8d; font-size: 0.75em; margin-top: 2px;">Loading bag references...</small>
      </div>
      <div class="form-group">
        <label>Amount:</label>
        <input type="text" id="fg_amount" name="fg_amount" readonly class="readonly" placeholder="Amount from FG entry">
      </div>
      <div class="form-group">
        <label>Bag No:</label>
        <input type="text" id="bag_no" name="bag_no" placeholder="Enter bag number">
      </div>
      <div class="form-group">
        <label>Weight:</label>
        <input type="number" id="weight" name="weight" step="0.01" placeholder="Enter weight">
      </div>
      <div class="form-group">
        <label>Stitch:</label>
        <input type="text" id="stitch" name="stitch" placeholder="Enter stitch">
      </div>
      <div class="form-group">
        <label>Actual Length:</label>
        <input type="number" id="actual_length" name="actual_length" step="0.01" placeholder="Enter actual length">
      </div>
      <div class="form-group">
        <label>Width:</label>
        <input type="number" id="width" name="width" step="0.01" placeholder="Enter width">
      </div>
      <div class="form-group">
        <label>Margin Left:</label>
        <input type="number" id="margin_left" name="margin_left" step="0.01" placeholder="Enter margin left">
      </div>
      <div class="form-group">
        <label>Margin Right:</label>
        <input type="number" id="margin_right" name="margin_right" step="0.01" placeholder="Enter margin right">
      </div>
      <div class="form-group">
        <label>QC Inspector Name:</label>
        <input type="text" id="qc_inspector_fg" name="qc_inspector_fg" value="<?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?>" readonly style="background-color: #f0f0f0;">
      </div>
    </div>

    <!-- Result & Remarks moved to end -->
    <div class="form-group">
      <label>Result:</label>
      <div class="btn-group" id="qcResultGroup">
        <button type="button" class="btn" data-value="Pass" onclick="selectBtn(this,'qcResultGroup')">Pass</button>
        <button type="button" class="btn" data-value="Fail" onclick="selectBtn(this,'qcResultGroup')">Fail</button>
      </div>
      <input type="hidden" id="qc_result" name="qc_result" value="">
    </div>

    <div class="form-group">
      <label>Remarks (optional):</label>
      <input type="text" id="remarks" name="remarks" placeholder="Add remarks...">
    </div>

    <!-- Perform Test Button -->
    <div class="form-group">
      <button type="button" class="perform-test-btn" onclick="performTest()" style="background:#3498db; color:#fff; padding:12px 24px; font-size:16px; border:none; border-radius:6px; cursor:pointer; width:100%;">
        🔬 Perform Test
      </button>
    </div>

    <!-- Summary Section -->
    <div class="form-group">
      <div id="summaryBox" class="summary-info"></div>
      <input type="hidden" id="summary" name="summary">
    </div>

    <!-- Approved By -->
    <?php 
    // Hide Approved By field for QC inspectors
    $user_role = strtolower(trim($_SESSION['role'] ?? ''));
    if ($user_role !== 'qc_inspector'): 
    ?>
    <div class="form-group">
      <label>Approved By:</label>
      <input type="text" name="approved_by" value="<?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? ''); ?>" readonly style="background-color: #f0f0f0;" required>
    </div>
    <?php endif; ?>

    <div class="actions">
      <button type="submit" class="submit-btn">Submit</button>
      <button type="button" class="clear-btn" onclick="clearForm()">Clear</button>
    </div>
  </form>
</div>

<script>
function updateTimeAndShift() {
  const now = new Date();
  const utc = now.getTime() + now.getTimezoneOffset()*60000;
  const dhaka = new Date(utc + 6*3600000);
  document.getElementById('dateTimeDisplay').innerHTML = 'Date & Time: ' + dhaka.toDateString() + ' ' + dhaka.toLocaleTimeString();
  const yyyy = dhaka.getFullYear();
  const mm = String(dhaka.getMonth()+1).padStart(2,'0');
  const dd = String(dhaka.getDate()).padStart(2,'0');
  const hh = String(dhaka.getHours()).padStart(2,'0');
  const min = String(dhaka.getMinutes()).padStart(2,'0');
  const ss = String(dhaka.getSeconds()).padStart(2,'0');
  document.getElementById('dateTime').value = `${yyyy}-${mm}-${dd} ${hh}:${min}:${ss}`;
  const h = dhaka.getHours();
  const shift = (h >= 8 && h <= 19) ? 'Day' : 'Night';
  document.getElementById('shiftBanner').innerText = 'Shift: ' + shift;
  document.getElementById('shift').value = shift;
  updateSummary();
}
setInterval(updateTimeAndShift,1000); updateTimeAndShift();
// ensure default QC result selected on load
ensureDefaultResult();

function selectBtn(btn, groupId){
  const group = document.getElementById(groupId);
  group.querySelectorAll('button').forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  let hiddenId = groupId.replace('Group','').toLowerCase();
  // Fix mappings for hidden inputs
  if (groupId === 'qcStageGroup') hiddenId = 'qc_stage';
  if (groupId === 'qcTypeGroup') hiddenId = 'qc_type';
  if (groupId === 'qcResultGroup') hiddenId = 'qc_result';
  const hiddenEl = document.getElementById(hiddenId);
  if (hiddenEl) hiddenEl.value = btn.dataset.value;
  if (groupId === 'qcStageGroup') {
    showStageSection(btn.dataset.value);
  }
  updateSummary();
}

function selectProject(btn, groupId, hiddenId){
  const group = document.getElementById(groupId);
  group.querySelectorAll('button').forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById(hiddenId).value = btn.dataset.value;
  updateSummary();
}

function showStageSection(stage){
  const sections = { CNC: 'sectionCNC', Production: 'sectionProduction', FG: 'sectionFG' };
  // hide all
  Object.values(sections).forEach(id=>{ const el=document.getElementById(id); if(el) el.style.display='none'; });
  // show selected
  const sid = sections[stage] || '';
  if (sid) { const el=document.getElementById(sid); if(el) el.style.display='block'; }
  // Update QC Type options by stage
  const qcTypeMap = {
    'CNC': [
      'Dimension Accuracy', 'Edge Quality Check', 'Piece Count Verification', 'Shape Check'
    ],
    'Production': [
      'Seam Strength Test', 'Stitch Density', 'Handle Attachment Strength', 'Weight of Finished Piece (within tolerance)', 'Drop Test'
    ],
    'FG': [
      'Mechanical check', 'Physical check', 'Hydraulic check'
    ]
  };
  const typeGroup = document.getElementById('qcTypeGroup');
  if (typeGroup) {
    typeGroup.innerHTML = '';
    const types = qcTypeMap[stage] || [];
    types.forEach(t => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn';
      btn.dataset.value = t;
      btn.textContent = t;
      btn.onclick = function(){ selectBtn(btn, 'qcTypeGroup'); };
      typeGroup.appendChild(btn);
    });
    // reset hidden qc_type when stage changes
    const h = document.getElementById('qc_type'); if (h) h.value = '';
    // auto-select the first QC type for convenience
    const first = typeGroup.querySelector('button');
    if (first) {
      first.classList.add('selected');
      if (h) h.value = first.dataset.value;
      updateSummary();
    }
    // Ensure a default QC result is selected (Pass)
    ensureDefaultResult();
  }
}

function ensureDefaultResult(){
  const resGroup = document.getElementById('qcResultGroup');
  const resHidden = document.getElementById('qc_result');
  if (!resGroup || !resHidden) return;
  if (!resHidden.value) {
    const resFirst = resGroup.querySelector('button');
    if (resFirst) {
      resGroup.querySelectorAll('button').forEach(b=>b.classList.remove('selected'));
      resFirst.classList.add('selected');
      resHidden.value = resFirst.dataset.value;
    }
  }
}

// When user selects a roll number, fetch its batch via a lightweight request (optional) or prefill latest known mapping
function syncBatchFromRoll(){
  const sel = document.getElementById('roll_number_prod');
  const val = sel ? sel.value : '';
  const out = document.getElementById('batch_number_prod_view');
  if (!out) return;
  if (!val){ out.value = 'Auto (linked)'; return; }
  // Try to update via a simple fetch to an endpoint if available; fallback keeps existing value
  // Placeholder: keep displayed value; backend will resolve on submit if needed
}

function syncBatchFromRollCNC(){
  const sel = document.getElementById('roll_number_cnc');
  const val = sel ? sel.value : '';
  const out = document.getElementById('batch_number_cnc_view');
  if (!out) return;
  if (!val){ out.value = 'Auto (linked)'; return; }
}

function updateSummary(){
  const dateTime = document.getElementById('dateTime').value;
  const shift = document.getElementById('shift').value;
  const qcStage = document.querySelector('#qcStageGroup .btn.selected')?.textContent?.trim() || '';
  const qcType = document.querySelector('#qcTypeGroup .btn.selected')?.textContent?.trim() || '';
  const qcResult = document.querySelector('#qcResultGroup .btn.selected')?.textContent?.trim() || '';
  const remarks = document.getElementById('remarks').value;

  if (dateTime && shift) {
    let s = `${dateTime} | Shift: ${shift}`;
    if (qcStage) s += ` | Stage: ${qcStage}`;
    if (qcType) s += ` | Type: ${qcType}`;
    if (qcResult) s += ` | Result: ${qcResult}`;
    if (remarks) s += ` | Remarks: ${remarks}`;
    document.getElementById('summaryBox').innerText = s;
    document.getElementById('summary').value = s;
  } else {
    document.getElementById('summaryBox').innerText = '';
    document.getElementById('summary').value = '';
  }
}

function validateForm(){
  const stage = document.getElementById('qc_stage');
  const type = document.getElementById('qc_type');
  const result = document.getElementById('qc_result');
  if(!stage.value){ alert('Select QC Stage.'); return false; }
  if(!type.value){ alert('Select QC Type.'); return false; }
  if(!result.value){ ensureDefaultResult(); }
  return true;
}

function clearForm(){
  const f = document.getElementById('qcForm');
  f.reset();
  document.querySelectorAll('#qcStageGroup .btn, #qcTypeGroup .btn, #qcResultGroup .btn').forEach(b=>b.classList.remove('selected'));
  ['qc_stage','qc_type','qc_result','summary'].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=''; });
  const sb=document.getElementById('summaryBox'); if (sb) sb.innerText='';
  // Hide all stage sections on clear
  showStageSection('');
  updateSummary();
}

function performTest(){
  // Validate required fields
  const stage = document.getElementById('qc_stage');
  const type = document.getElementById('qc_type');
  const result = document.getElementById('qc_result');
  
  if(!stage.value){
    alert('Please select QC Stage before performing test.');
    return;
  }
  if(!type.value){
    alert('Please select QC Type before performing test.');
    return;
  }
  if(!result.value){
    alert('Please select QC Result before performing test.');
    return;
  }
  
  // Submit the QC entry first to get the QC ID
  const form = document.getElementById('qcForm');
  const formData = new FormData(form);
  
  // Submit QC entry via AJAX
  fetch('../handlers/submit_qc_entry.php', {
    method: 'POST',
    body: formData
  })
  .then(response => response.text())
  .then(data => {
    // Extract QC ID from response or use the generated one
    const qcId = document.querySelector('input[name="qc_id"]').value;
    
    // Redirect to Test Order page with QC ID
    window.location.href = `qc_test_order.php?qc_entry_id=${qcId}&stage=${stage.value}&type=${encodeURIComponent(type.value)}`;
  })
  .catch(error => {
    console.error('Error:', error);
    alert('Error submitting QC entry. Please try again.');
  });
}

// Load form data asynchronously after page render
document.addEventListener('DOMContentLoaded', function() {
  loadFormData();
});

function loadFormData() {
  // Load reference numbers for CNC
  fetch('api/get_qc_references.php')
    .then(response => response.json())
    .then(data => {
      if (data.success && data.references) {
        const refSelect = document.getElementById('roll_number_cnc');
        const loadingText = document.getElementById('ref_cnc_loading');
        if (loadingText) loadingText.style.display = 'none';
        
        refSelect.innerHTML = '<option value="">-- Select Reference --</option>';
        data.references.forEach(ref => {
          const option = document.createElement('option');
          option.value = ref;
          option.textContent = ref;
          refSelect.appendChild(option);
        });
      }
    })
    .catch(err => {
      console.error('Error loading reference numbers:', err);
      const loadingText = document.getElementById('ref_cnc_loading');
      if (loadingText) loadingText.textContent = 'Error loading options';
    });
  
  // Load reference numbers for Production
  fetch('api/get_qc_references.php')
    .then(response => response.json())
    .then(data => {
      if (data.success && data.references) {
        const refSelect = document.getElementById('roll_number_prod');
        const loadingText = document.getElementById('ref_prod_loading');
        if (loadingText) loadingText.style.display = 'none';
        
        refSelect.innerHTML = '<option value="">-- Select Reference --</option>';
        data.references.forEach(ref => {
          const option = document.createElement('option');
          option.value = ref;
          option.textContent = ref;
          refSelect.appendChild(option);
        });
      }
    })
    .catch(err => {
      console.error('Error loading reference numbers:', err);
      const loadingText = document.getElementById('ref_prod_loading');
      if (loadingText) loadingText.textContent = 'Error loading options';
    });
  
  // Load FG entries - get first reference of each product
  fetch('api/get_qc_fg_entries.php')
    .then(response => response.json())
    .then(data => {
      if (data.success && data.fgEntries) {
        const fgSelect = document.getElementById('fg_reference');
        const loadingText = document.getElementById('fg_loading');
        if (loadingText) loadingText.style.display = 'none';
        
        fgSelect.innerHTML = '<option value="">-- Select Bag Reference --</option>';
        data.fgEntries.forEach(fg => {
          const option = document.createElement('option');
          option.value = fg.reference_number;
          option.dataset.amount = fg.amount || '';
          option.textContent = fg.reference_number + ' (Bag)';
          fgSelect.appendChild(option);
        });
        
        // When reference is selected, populate amount
        fgSelect.addEventListener('change', function() {
          const selectedOption = this.options[this.selectedIndex];
          const amountField = document.getElementById('fg_amount');
          if (selectedOption && selectedOption.dataset.amount) {
            amountField.value = selectedOption.dataset.amount;
          } else {
            amountField.value = '';
          }
        });
      }
    })
    .catch(err => {
      console.error('Error loading FG entries:', err);
      const loadingText = document.getElementById('fg_loading');
      if (loadingText) loadingText.textContent = 'Error loading options';
    });
}
</script>
</body>
</html>



