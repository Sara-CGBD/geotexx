<?php
// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();

// Include security configuration
require_once 'security_config.php';

// Auth/session checks
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

// Timezone
date_default_timezone_set('Asia/Dhaka');

// DB connection (shared config)
$conn = SecurityConfig::getConnection();

// Function to generate GSM and Roll Input ID (resets daily at 8 AM)
function generateGsmRollEntryId($conn) {
    $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
    $hour = (int)$now->format('H');
    $baseDate = clone $now;
    if ($hour < 8) {
        $baseDate->modify('-1 day');
    }
    $baseDate->setTime(8, 0, 0);
    $dateKey = $baseDate->format('Ymd');

    // Check if gsm_roll_entry table exists and has entry_id column
    $tableCheck = $conn->query("SHOW TABLES LIKE 'gsm_roll_entry'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $colCheck = $conn->query("SHOW COLUMNS FROM gsm_roll_entry LIKE 'entry_id'");
        if ($colCheck && $colCheck->num_rows > 0) {
            $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(entry_id, -3) AS UNSIGNED)) as last_num 
                                    FROM gsm_roll_entry 
                                    WHERE date_time >= ?");
            $resetTimestamp = $baseDate->format('Y-m-d H:i:s');
            $stmt->bind_param('s', $resetTimestamp);
            $stmt->execute();
            $result = $stmt->get_result();
            $nextNum = 1;
            if ($result && $row = $result->fetch_assoc()) {
                if (!empty($row['last_num'])) {
                    $nextNum = (int)$row['last_num'] + 1;
                }
            }
            $stmt->close();
        } else {
            $nextNum = 1;
        }
    } else {
        $nextNum = 1;
    }

    return 'GRE-' . $dateKey . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
}

$displayEntryId = generateGsmRollEntryId($conn);

// Fetch distinct line numbers from fiber_to_roll_entry
$lineNumbers = [];
$lineQuery = $conn->query("
    SELECT DISTINCT line_no
    FROM fiber_to_roll_entry
    WHERE line_no IS NOT NULL
    AND line_no != ''
    AND line_no != '0'
    ORDER BY CAST(line_no AS UNSIGNED), line_no ASC
");
if ($lineQuery) {
    while ($row = $lineQuery->fetch_assoc()) {
        $lineNumbers[] = $row['line_no'];
    }
}

// Fetch available fiber input entries from fiber_to_roll_entry
// Exclude entries that have already been used in submitted gsm_roll_entry
$fiberInputEntries = [];

// Check if gsm_roll_entry table exists
$tableExists = false;
$tableCheck = $conn->query("SHOW TABLES LIKE 'gsm_roll_entry'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $tableExists = true;
}

// Build query - exclude used entries only if table exists
if ($tableExists) {
    $fiberQuery = $conn->query('
        SELECT 
            f.entry_id,
            f.date_time,
            f.line_no,
            f.batch_info,
            f.manufacturer_name,
            f.material_percentage,
            f.total_weight
        FROM fiber_to_roll_entry f
        WHERE f.entry_id IS NOT NULL
        AND f.entry_id != ""
        AND NOT EXISTS (
            SELECT 1 
            FROM gsm_roll_entry g 
            WHERE g.fiber_input_entries IS NOT NULL
            AND g.fiber_input_entries != ""
            AND (g.fiber_input_entries LIKE CONCAT("%\"", f.entry_id, "\"%")
                 OR g.fiber_input_entries LIKE CONCAT("%\"entry_id\":\"", f.entry_id, "\"%"))
        )
        ORDER BY f.date_time DESC
        LIMIT 100
    ');
} else {
    // If table doesn't exist yet, just fetch all entries
    $fiberQuery = $conn->query('
        SELECT 
            entry_id,
            date_time,
            line_no,
            batch_info,
            manufacturer_name,
            material_percentage,
            total_weight
        FROM fiber_to_roll_entry
        WHERE entry_id IS NOT NULL
        AND entry_id != ""
        ORDER BY date_time DESC
        LIMIT 100
    ');
}

if ($fiberQuery) {
    while ($row = $fiberQuery->fetch_assoc()) {
        $fiberInputEntries[] = $row;
    }
}

// Manufacturer name mappings for reference generation
$manufacturerCodes = [
    'Texofib' => 'T',
    'Hubei Botao' => 'H',
    'Natpet' => 'N',
    'APT' => 'A',
    'Jiangsu Botao' => 'J',
    'Taizhu Hailun' => 'Z',
    'PSF' => 'P'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>GSM and Roll Input</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:0; color:#2c3e50; }
    .container { max-width:100%; margin:0; background:#fff; border-radius:0; padding:25px 80px; box-shadow:none; }
    h1 { text-align:center; font-size:28px; margin-bottom:30px; }
    .form-group { margin-bottom:20px; }
    label { font-weight:600; display:block; margin-bottom:8px; }
    input[type="text"], input[type="number"], textarea, select {
      padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px); box-sizing:border-box; font-family:'Inter',sans-serif;
    }
    textarea { resize:vertical; min-height:60px; }
    .btn-group { display:flex; flex-wrap:wrap; gap:10px; }
    .btn { padding:10px 16px; font-size:14px; border:none; border-radius:6px; cursor:pointer; background:#e0e0e0; }
    .btn:hover { background:#ccc; }
    .btn.selected { background:#3498db; color:white; }
    .btn.disabled { background:#f5f5f5; color:#ccc; opacity:0.6; cursor:not-allowed !important; }
    .btn.disabled:hover { background:#f5f5f5; }
    .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; color:#333; }
    .actions { margin-top:30px; text-align:center; }
    .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px; }
    .submit-btn { background:#2ecc71; color:white; }
    .clear-btn { background:#e74c3c; color:white; }
    .readonly { background:#ecf0f1; }
    .reference-list { margin-top:10px; padding:10px; background:#f8f9fa; border-radius:6px; border:1px solid #ddd; }
    .reference-item { display:flex; align-items:center; justify-content:space-between; padding:8px; margin:5px 0; background:white; border-radius:4px; border:1px solid #ddd; }
    .reference-item span { flex:1; }
    .reference-item button { padding:5px 10px; background:#e74c3c; color:white; border:none; border-radius:4px; cursor:pointer; font-size:12px; }
    .reference-item button:hover { background:#c0392b; }
    .manufacturer-percentages { margin-top:15px; padding:15px; background:#f0f8ff; border-radius:6px; border:1px solid #b3d9ff; }
    .manufacturer-percentages h4 { margin:0 0 10px 0; color:#2c3e50; font-size:14px; }
    .manufacturer-item { display:flex; justify-content:space-between; padding:5px 0; border-bottom:1px solid #ddd; }
    .manufacturer-item:last-child { border-bottom:none; }
    .manufacturer-item strong { color:#3498db; }
  </style>
</head>
<body>
  <div class="container">
    <h1>GSM and Roll Input</h1>

    <div id="dateTimeDisplay" class="summary-info"></div>
    <div id="shiftBanner" class="summary-info"></div>

    <?php if (isset($_GET['error'])): ?>
      <div style="background:#fee; color:#c33; padding:10px; border-radius:6px; margin-bottom:20px; border:1px solid #fcc;">
        <strong>Error:</strong> <?php echo htmlspecialchars($_GET['error']); ?>
      </div>
    <?php endif; ?>

    <?php if (isset($_GET['success'])): ?>
      <div style="background:#efe; color:#3c3; padding:10px; border-radius:6px; margin-bottom:20px; border:1px solid #cfc;">
        <strong>Success:</strong> <?php echo htmlspecialchars($_GET['success']); ?>
      </div>
    <?php endif; ?>

    <form id="gsmRollForm" method="post" action="../handlers/submit_gsm_roll_entry.php" onsubmit="return validateAndSubmit();">
      <input type="hidden" id="dateTime" name="date_time" />

      <!-- Entry ID -->
      <div class="form-group">
        <label>Entry ID:</label>
        <input type="text" id="entry_id" name="entry_id" value="<?php echo htmlspecialchars($displayEntryId); ?>" readonly class="readonly">
      </div>

      <!-- Line Number -->
      <div class="form-group">
        <label>Line Number:</label>
        <div class="btn-group" id="lineNumberGroup">
          <?php 
          foreach ($lineNumbers as $lineNo): 
            // Count available entries for this line
            $entryCount = 0;
            foreach ($fiberInputEntries as $entry) {
              if ($entry['line_no'] == $lineNo) {
                $entryCount++;
              }
            }
            // Only show button if line has at least 3 entries
            if ($entryCount >= 3):
          ?>
            <button type="button" class="btn" onclick="selectLineNumber(this, '<?php echo htmlspecialchars($lineNo); ?>')">
              Line <?php echo htmlspecialchars($lineNo); ?>
            </button>
          <?php 
            endif;
          endforeach; 
          ?>
        </div>
        <input type="hidden" id="line_number" name="line_number" value="" required>
        <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">Select a line number (must have at least 3 Fiber Input Entries) to filter Fiber Input Entries</small>
      </div>

      <!-- Fiber Input Entry ID Reference -->
      <div class="form-group">
        <label>Fiber Input Entry ID Reference:</label>
        <select id="fiber_input_entry_select">
          <option value="">-- Select Fiber Input Entry --</option>
          <?php foreach ($fiberInputEntries as $entry): ?>
            <option value="<?php echo htmlspecialchars($entry['entry_id']); ?>" 
                    data-line-no="<?php echo htmlspecialchars($entry['line_no'] ?? ''); ?>"
                    data-batch-info="<?php echo htmlspecialchars($entry['batch_info'] ?? ''); ?>"
                    data-manufacturer="<?php echo htmlspecialchars($entry['manufacturer_name'] ?? ''); ?>"
                    data-percentage="<?php echo htmlspecialchars($entry['material_percentage'] ?? '0'); ?>"
                    data-weight="<?php echo htmlspecialchars($entry['total_weight'] ?? '0'); ?>"
                    style="display:none;">
              <?php echo htmlspecialchars($entry['entry_id']); ?> 
              <?php if (!empty($entry['batch_info'])): ?> - <?php echo htmlspecialchars($entry['batch_info']); ?><?php endif; ?>
              <?php if (!empty($entry['manufacturer_name'])): ?> (<?php echo htmlspecialchars($entry['manufacturer_name']); ?>)<?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="button" onclick="addFiberInputEntry()" style="margin-top:10px; padding:8px 16px; background:#3498db; color:white; border:none; border-radius:6px; cursor:pointer;">Add</button>
        <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">Select a Fiber Input Entry and click Add to include it</small>
        
        <!-- Selected References List -->
        <div id="selectedReferences" class="reference-list" style="display:none;">
          <strong>Selected Fiber Input Entries:</strong>
          <div id="referenceItems"></div>
        </div>
      </div>

      <!-- Manufacturer Percentages Display -->
      <div id="manufacturerPercentages" class="manufacturer-percentages" style="display:none;">
        <h4>Manufacturer Percentages:</h4>
        <div id="manufacturerList"></div>
      </div>

      <!-- Total Weight Display -->
      <div id="totalWeightDisplay" class="manufacturer-percentages" style="display:none; background:#e8f5e9; border:1px solid #4caf50; margin-top:15px;">
        <h4 style="margin:0 0 10px 0; color:#2c3e50; font-size:16px; font-weight:600;">Total Weight from Selected Fiber Input Entries:</h4>
        <div style="font-size:18px; font-weight:bold; color:#2e7d32; padding:8px 0;">
          <span id="totalWeightValue">0.00</span> kg
        </div>
        <small style="color:#666; font-size:12px; display:block; margin-top:5px;">Sum of all selected fiber input entry weights</small>
      </div>

      <!-- GSM -->
      <div class="form-group" style="margin-top: 30px;">
        <label>GSM:</label>
        <input type="number" step="1" min="1" id="gsm" name="gsm" required oninput="generateReference()">
      </div>

      <!-- Roll No -->
      <div class="form-group">
        <label>Roll No:</label>
        <input type="number" step="1" min="1" id="roll_no" name="roll_no" required oninput="generateReference()">
      </div>

      <!-- Reference (Auto-generated) -->
      <div class="form-group">
        <label>Reference:</label>
        <input type="text" id="reference" name="reference" readonly class="readonly">
        <small style="color: #666; font-size: 12px; display: block; margin-top: 5px;">Auto-generated based on GSM, Roll No, Line No, Date, Batch Info, and Manufacturer Percentages</small>
      </div>

      <!-- Summary -->
      <div class="form-group">
        <label></label>
        <div id="summaryBox" class="summary-info">Please fill in the details above to generate a summary.</div>
        <input type="hidden" id="summary" name="summary">
      </div>

      <!-- Hidden field for selected fiber input entries -->
      <input type="hidden" id="fiber_input_entries" name="fiber_input_entries" value="">

      <div class="actions">
        <button type="submit" class="submit-btn">Submit</button>
        <button type="reset" class="clear-btn" onclick="clearForm()">Clear</button>
      </div>
    </form>
  </div>

<script>
  // Manufacturer name mappings for reference generation
  const manufacturerCodes = <?php echo json_encode($manufacturerCodes); ?>;
  
  // Store selected fiber input entries
  let selectedFiberEntries = [];
  let manufacturerPercentages = {};

  function updateTimeAndShift() {
    const now = new Date();
    const utc = now.getTime() + (now.getTimezoneOffset()*60000);
    const dhaka = new Date(utc + (6*3600000));
    document.getElementById("dateTimeDisplay").innerHTML =
      "Date & Time: " + dhaka.toDateString() + " " + dhaka.toLocaleTimeString();

    const yyyy = dhaka.getFullYear();
    const mm = String(dhaka.getMonth()+1).padStart(2,'0');
    const dd = String(dhaka.getDate()).padStart(2,'0');
    const hh = String(dhaka.getHours()).padStart(2,'0');
    const min = String(dhaka.getMinutes()).padStart(2,'0');
    const ss = String(dhaka.getSeconds()).padStart(2,'0');
    document.getElementById("dateTime").value = `${yyyy}-${mm}-${dd} ${hh}:${min}:${ss}`;

    const h = dhaka.getHours();
    document.getElementById("shiftBanner").innerText = "Shift: " + ((h>=8&&h<=19)?"Day":"Night");
    generateReference();
    updateSummary();
  }
  setInterval(updateTimeAndShift,1000);
  updateTimeAndShift();

  // Select Line Number button
  function selectLineNumber(button, lineNo) {
    // Check if line has at least 3 fiber input entries
    checkLineEntryCount(lineNo, function(hasEnoughEntries) {
      if (!hasEnoughEntries) {
        alert('This line number must have at least 3 Fiber Input Entries before you can proceed. Please add more Fiber Input Entries for this line.');
        return;
      }
      
      // Remove selected class from all buttons in the group
      const buttons = document.querySelectorAll('#lineNumberGroup .btn');
      buttons.forEach(btn => btn.classList.remove('selected'));
      
      // Add selected class to clicked button
      button.classList.add('selected');
      
      // Set hidden input value
      document.getElementById('line_number').value = lineNo;
      
      // Filter fiber input entries
      filterFiberInputEntries();
      generateReference();
      updateSummary();
    });
  }
  
  // Check if line has at least 3 fiber input entries
  function checkLineEntryCount(lineNo, callback) {
    const select = document.getElementById('fiber_input_entry_select');
    const options = select.querySelectorAll('option');
    let count = 0;
    
    options.forEach((option, index) => {
      if (index === 0) return; // Skip placeholder
      const optionLineNo = option.getAttribute('data-line-no');
      if (optionLineNo === lineNo) {
        count++;
      }
    });
    
    callback(count >= 3);
  }

  // Filter Fiber Input Entries based on selected Line Number
  function filterFiberInputEntries() {
    const lineNumber = document.getElementById('line_number').value;
    const select = document.getElementById('fiber_input_entry_select');
    const options = select.querySelectorAll('option');
    
    // Reset selection
    select.selectedIndex = 0;
    
    // Show/hide options based on line number
    options.forEach((option, index) => {
      if (index === 0) {
        // Always show the first option (placeholder)
        option.style.display = '';
        return;
      }
      
      const optionLineNo = option.getAttribute('data-line-no');
      
      if (!lineNumber || lineNumber === '') {
        // If no line number selected, hide all options
        option.style.display = 'none';
      } else if (optionLineNo === lineNumber) {
        // Show options matching the selected line number
        option.style.display = '';
      } else {
        // Hide options that don't match
        option.style.display = 'none';
      }
    });
    
    // Clear selected entries if line number changes
    if (selectedFiberEntries.length > 0) {
      const currentLineNo = selectedFiberEntries[0].line_no;
      if (currentLineNo !== lineNumber) {
        selectedFiberEntries = [];
        manufacturerPercentages = {};
        updateSelectedReferencesList();
        updateManufacturerPercentages();
        updateTotalWeightDisplay();
        generateReference();
        updateSummary();
      }
    }
  }

  // Add Fiber Input Entry to selected list
  function addFiberInputEntry() {
    const select = document.getElementById('fiber_input_entry_select');
    const selectedOption = select.options[select.selectedIndex];
    
    if (!selectedOption || !selectedOption.value) {
      alert('Please select a Fiber Input Entry first');
      return;
    }

    const entryId = selectedOption.value;
    
    // Check if already added
    if (selectedFiberEntries.find(e => e.entry_id === entryId)) {
      alert('This Fiber Input Entry is already added');
      return;
    }

    // Add to selected list
    const entry = {
      entry_id: entryId,
      line_no: selectedOption.getAttribute('data-line-no') || '',
      batch_info: selectedOption.getAttribute('data-batch-info') || '',
      manufacturer: selectedOption.getAttribute('data-manufacturer') || '',
      percentage: parseFloat(selectedOption.getAttribute('data-percentage') || 0),
      weight: parseFloat(selectedOption.getAttribute('data-weight') || 0)
    };
    
    selectedFiberEntries.push(entry);
    
    // Update UI
    updateSelectedReferencesList();
    updateManufacturerPercentages();
    updateTotalWeightDisplay();
    setTimeout(() => {
      generateReference();
      updateSummary();
    }, 100);
    
    // Reset select
    select.selectedIndex = 0;
  }

  // Remove Fiber Input Entry from selected list
  function removeFiberInputEntry(entryId) {
    selectedFiberEntries = selectedFiberEntries.filter(e => e.entry_id !== entryId);
    updateSelectedReferencesList();
    updateManufacturerPercentages();
    updateTotalWeightDisplay();
    setTimeout(() => {
      generateReference();
      updateSummary();
    }, 100);
  }

  // Update selected references list display
  function updateSelectedReferencesList() {
    const container = document.getElementById('selectedReferences');
    const itemsDiv = document.getElementById('referenceItems');
    
    if (selectedFiberEntries.length === 0) {
      container.style.display = 'none';
      return;
    }
    
    container.style.display = 'block';
    itemsDiv.innerHTML = '';
    
    selectedFiberEntries.forEach(entry => {
      const item = document.createElement('div');
      item.className = 'reference-item';
      const weightDisplay = entry.weight > 0 ? ` <strong style="color:#2e7d32;">(${parseFloat(entry.weight).toFixed(2)} kg)</strong>` : '';
      item.innerHTML = `
        <span>${entry.entry_id}${entry.batch_info ? ' - ' + entry.batch_info : ''}${entry.manufacturer ? ' (' + entry.manufacturer + ' ' + entry.percentage + '%)' : ''}${weightDisplay}</span>
        <button type="button" onclick="removeFiberInputEntry('${entry.entry_id}')">Remove</button>
      `;
      itemsDiv.appendChild(item);
    });
    
    // Update hidden field
    document.getElementById('fiber_input_entries').value = JSON.stringify(selectedFiberEntries);
  }

    // Update manufacturer percentages display
  function updateManufacturerPercentages() {
    const container = document.getElementById('manufacturerPercentages');
    const listDiv = document.getElementById('manufacturerList');
    
    // Sum percentages for each manufacturer directly
    // If same manufacturer appears multiple times, sum their percentages
    // If different manufacturers, show their individual percentages
    manufacturerPercentages = {};
    
    selectedFiberEntries.forEach(entry => {
      if (entry.manufacturer && entry.percentage > 0) {
        if (!manufacturerPercentages[entry.manufacturer]) {
          manufacturerPercentages[entry.manufacturer] = 0;
        }
        // Sum the percentages directly (e.g., 10% + 5% + 9% = 24%)
        manufacturerPercentages[entry.manufacturer] += entry.percentage;
      }
    });
    
    if (Object.keys(manufacturerPercentages).length === 0) {
      container.style.display = 'none';
      return;
    }
    
    container.style.display = 'block';
    listDiv.innerHTML = '';
    
    Object.keys(manufacturerPercentages).sort().forEach(manufacturer => {
      const percentage = manufacturerPercentages[manufacturer];
      const item = document.createElement('div');
      item.className = 'manufacturer-item';
      item.innerHTML = `<strong>${manufacturer}:</strong> <span>${percentage.toFixed(2)}%</span>`;
      listDiv.appendChild(item);
    });
    
    // Update total weight display
    updateTotalWeightDisplay();
  }

  // Update total weight display
  function updateTotalWeightDisplay() {
    const container = document.getElementById('totalWeightDisplay');
    const valueElement = document.getElementById('totalWeightValue');
    
    // Calculate total weight from all selected entries
    let totalWeight = 0;
    selectedFiberEntries.forEach(entry => {
      totalWeight += parseFloat(entry.weight || 0);
    });
    
    if (selectedFiberEntries.length === 0) {
      container.style.display = 'none';
      return;
    }
    
    container.style.display = 'block';
    valueElement.textContent = totalWeight.toFixed(2);
  }

  // Generate reference number
  function generateReference() {
    const gsm = document.getElementById('gsm').value;
    const rollNo = document.getElementById('roll_no').value;
    const dateTime = document.getElementById('dateTime').value;
    const referenceField = document.getElementById('reference');
    
    if (!gsm || !rollNo || !dateTime) {
      referenceField.value = '';
      return;
    }

    // Parse date
    const date = new Date(dateTime);
    const day = String(date.getDate()).padStart(2, '0');
    const monthNames = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
    const month = monthNames[date.getMonth()];
    const year = String(date.getFullYear()).slice(-2);
    
    // Get GSM (divide by 100 for format, e.g., 300 -> 3.0)
    const gsmFormatted = (parseFloat(gsm) / 100).toFixed(1);
    
    // Get line number from selected line number dropdown (or from first selected entry as fallback)
    const selectedLineNo = document.getElementById('line_number').value;
    const lineNo = selectedLineNo || (selectedFiberEntries.length > 0 && selectedFiberEntries[0].line_no 
      ? selectedFiberEntries[0].line_no 
      : '1');
    // Format: L + LineNo (e.g., L1)
    const lineCode = 'L' + lineNo;
    
    // Get batch info from first selected entry and convert format
    // Example: GT9.H1 → GT0.9H0.1
    let batchInfo = selectedFiberEntries.length > 0 && selectedFiberEntries[0].batch_info 
      ? selectedFiberEntries[0].batch_info 
      : '';
    
    // Convert batch info format: GT9.H1 → GT0.9H0.1
    if (batchInfo) {
      // First, remove dot before H (GT9.H1 → GT9H1)
      batchInfo = batchInfo.replace(/\.H/g, 'H');
      // Replace GT followed by digits with GT0.{digits} (GT9 → GT0.9)
      batchInfo = batchInfo.replace(/GT(\d+)/g, function(match, digits) {
        const num = parseInt(digits);
        return 'GT0.' + num;
      });
      // Replace H followed by digits with H0.{digits} (H1 → H0.1)
      batchInfo = batchInfo.replace(/H(\d+)/g, function(match, digits) {
        const num = parseInt(digits);
        return 'H0.' + num;
      });
    }
    
    // Format roll number (pad to 2 digits)
    const rollFormatted = 'R' + String(parseInt(rollNo)).padStart(2, '0');
    
    // Build manufacturer percentage string with material code and total percentage
    // Format: Code.TotalPercentage% (e.g., H.10% J.10% T.3%)
    // Use first letter/word of manufacturer name or mapping from manufacturerCodes
    let manufacturerCodesStr = '';
    Object.keys(manufacturerPercentages).sort().forEach(manufacturer => {
      const percentage = Math.round(manufacturerPercentages[manufacturer]);
      if (manufacturerCodesStr !== '') {
        manufacturerCodesStr += ' ';
      }
      // Get manufacturer code: use mapping if available, otherwise use first letter
      let code = manufacturerCodes[manufacturer];
      if (!code) {
        // If no mapping, use first letter of manufacturer name
        code = manufacturer.trim().charAt(0).toUpperCase();
      }
      // Format: Code.TotalPercentage% (e.g., H.10% instead of Hubei Botao.10%)
      manufacturerCodesStr += code + '.' + percentage + '%';
    });
    
    // Build reference: 3.0L126JAN28- R03- GT0.9H0.1- H.10% J.10% T.3%
    // Format: GSM + L + LineNo + Year + Month + Day - RollNo - BatchInfo - Code.TotalPercentage%
    // Example: 3.0L126JAN28- R03- GT0.9H0.1- H.10% J.10% T.3%
    // Manufacturer codes: H=Hubei Botao, J=Jiangsu Botao, T=Texofib, etc.
    let reference = `${gsmFormatted}${lineCode}${year}${month}${day}- ${rollFormatted}- ${batchInfo}- ${manufacturerCodesStr}`;
    
    referenceField.value = reference;
  }

  // Update summary
  function updateSummary() {
    const dateTime = document.getElementById("dateTime").value;
    const shift = document.getElementById("shiftBanner").innerText.replace("Shift: ","");
    const entryId = document.getElementById("entry_id").value;
    const lineNumber = document.getElementById("line_number").value;
    const gsm = document.getElementById("gsm").value;
    const rollNo = document.getElementById("roll_no").value;
    const reference = document.getElementById("reference").value;
    const fiberCount = selectedFiberEntries.length;

    let summary = `${dateTime} | Shift: ${shift} | Entry ID: ${entryId}`;
    if (lineNumber) summary += ` | Line No: ${lineNumber}`;
    if (fiberCount > 0) summary += ` | Fiber Entries: ${fiberCount}`;
    if (gsm) summary += ` | GSM: ${gsm}`;
    if (rollNo) summary += ` | Roll No: ${rollNo}`;
    if (reference) summary += ` | Reference: ${reference}`;
    
    if (Object.keys(manufacturerPercentages).length > 0) {
      summary += ` | Manufacturers: `;
      const mfgList = Object.keys(manufacturerPercentages).map(m => `${m} ${manufacturerPercentages[m].toFixed(1)}%`).join(', ');
      summary += mfgList;
    }

    document.getElementById("summaryBox").innerText = summary;
    document.getElementById("summary").value = summary;
  }

  // Clear form
  function clearForm() {
    selectedFiberEntries = [];
    manufacturerPercentages = {};
    
    // Clear line number selection
    const buttons = document.querySelectorAll('#lineNumberGroup .btn');
    buttons.forEach(btn => btn.classList.remove('selected'));
    document.getElementById('line_number').value = '';
    
    document.getElementById('fiber_input_entry_select').selectedIndex = 0;
    document.getElementById('gsm').value = '';
    document.getElementById('roll_no').value = '';
    document.getElementById('reference').value = '';
    filterFiberInputEntries(); // Reset filter
    updateSelectedReferencesList();
    updateManufacturerPercentages();
    updateTotalWeightDisplay();
    updateTimeAndShift();
    updateSummary();
  }

  // Validate and submit
  function validateAndSubmit() {
    const lineNumber = document.getElementById('line_number').value;
    if (!lineNumber) {
      alert('Please select a Line Number');
      return false;
    }
    
    // Check if line has at least 3 entries before allowing submission
    let entryCount = 0;
    const select = document.getElementById('fiber_input_entry_select');
    const options = select.querySelectorAll('option');
    options.forEach((option, index) => {
      if (index === 0) return; // Skip placeholder
      const optionLineNo = option.getAttribute('data-line-no');
      if (optionLineNo === lineNumber) {
        entryCount++;
      }
    });
    
    if (entryCount < 3) {
      alert('This line number must have at least 3 Fiber Input Entries before submission. Please add more Fiber Input Entries for this line.');
      return false;
    }
    
    if (selectedFiberEntries.length === 0) {
      alert('Please add at least one Fiber Input Entry');
      return false;
    }
    if (!document.getElementById('gsm').value) {
      alert('Please enter GSM');
      return false;
    }
    if (!document.getElementById('roll_no').value) {
      alert('Please enter Roll No');
      return false;
    }
    return true;
  }

  // Update reference when inputs change
  ["gsm", "roll_no"].forEach(id => {
    document.getElementById(id).addEventListener("input", function() {
      generateReference();
      updateSummary();
    });
  });
  
  // Initialize filter on page load
  document.addEventListener('DOMContentLoaded', function() {
    filterFiberInputEntries();
    // Ensure line number buttons are properly initialized
    const lineNumberInput = document.getElementById('line_number');
    if (lineNumberInput && lineNumberInput.value) {
      // If there's a value, find and select the corresponding button
      const buttons = document.querySelectorAll('#lineNumberGroup .btn');
      buttons.forEach(btn => {
        if (btn.textContent.trim().includes('Line ' + lineNumberInput.value)) {
          btn.classList.add('selected');
        }
      });
    }
  });
</script>
</body>
</html>
