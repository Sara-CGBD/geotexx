<?php
// fg_received_entry.php

session_start();
require_once '../config/security_config.php';

// Session & security checks
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

// Role-based access control for Finished Goods module
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_FINISHED_GOODS, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access the Finished Goods module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// Connect DB
$conn = SecurityConfig::getConnection();

// Generate Entry ID (FR-YYYYMMDD-XXX) with 8 AM daily reset
$dhaka_tz = new DateTimeZone('Asia/Dhaka');
$now = new DateTime('now', $dhaka_tz);
$current_hour = (int)$now->format('H');

// Determine reset date (8 AM cutoff)
$reset_date = clone $now;
if ($current_hour < 8) {
    $reset_date->modify('-1 day');
}
$reset_date->setTime(8, 0, 0);
$reset_timestamp = $reset_date->format('Y-m-d H:i:s');
$date_part = $reset_date->format('Ymd');

$entry_prefix = 'FR-' . $date_part . '-';
$next_entry_number = 1;

// Check fg_received_entry table
$table_check = $conn->query("SHOW TABLES LIKE 'fg_received_entry'");
if ($table_check && $table_check->num_rows > 0) {
    $column_check = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'entry_id'");
    if ($column_check && $column_check->num_rows > 0) {
        $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(entry_id, -3) AS UNSIGNED)) as last_num 
                                FROM fg_received_entry 
                                WHERE created_at >= ?");
        if ($stmt) {
            $stmt->bind_param('s', $reset_timestamp);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                if ($row['last_num']) {
                    $next_entry_number = $row['last_num'] + 1;
                }
            }
            $stmt->close();
        }
    }
}

$pre_entry_id = $entry_prefix . str_pad((string)$next_entry_number, 3, '0', STR_PAD_LEFT);

// Fetch trip numbers from roll_transfer where to_location = 'FG'
$fgTripNumbers = [];
$fgRollTripReferences = [];

$hasRollTransfer = $conn->query("SHOW TABLES LIKE 'roll_transfer'")->num_rows > 0;
if ($hasRollTransfer) {
    $hasToLocation = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'to_location'")->num_rows > 0;
    $hasTrip = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'trip'")->num_rows > 0;
    
    if ($hasToLocation && $hasTrip) {
        // Exclude trip numbers that have already been submitted in fg_received_entry
        $hasFgReceived = $conn->query("SHOW TABLES LIKE 'fg_received_entry'")->num_rows > 0;
        $excludeTripFilter = "";
        if ($hasFgReceived) {
            $hasTripCol = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'trip_number'")->num_rows > 0;
            if ($hasTripCol) {
                // Exclude trips that exist in fg_received_entry for roll entries
                $excludeTripFilter = "AND rt.trip NOT IN (
                    SELECT DISTINCT trip_number 
                    FROM fg_received_entry 
                    WHERE trip_number IS NOT NULL 
                      AND trip_number > 0
                      AND product_type = 'roll'
                )";
            }
        }
        
        $tripQuery = $conn->query("
            SELECT DISTINCT rt.trip, MAX(rt.date_time) as last_transfer_date
            FROM roll_transfer rt
            WHERE rt.to_location = 'FG' 
              AND rt.trip IS NOT NULL
              {$excludeTripFilter}
            GROUP BY rt.trip
            ORDER BY rt.trip DESC
            LIMIT 50
        ");
        if ($tripQuery) {
            while ($row = $tripQuery->fetch_assoc()) {
                $fgTripNumbers[] = [
                    'trip' => (int)$row['trip'],
                    'last_transfer_date' => $row['last_transfer_date']
                ];
            }
        }
    }
    
    // Fetch references for each trip from roll_transfer
    // Exclude references that have already been submitted in fg_received_entry
    if ($hasToLocation && $hasTrip) {
        // Check if is_deleted column exists
        $hasIsDeleted = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'is_deleted'")->num_rows > 0;
        $deletedCondition = $hasIsDeleted ? "AND (rt.is_deleted = 0 OR rt.is_deleted IS NULL)" : "";
        
        $hasFgReceived = $conn->query("SHOW TABLES LIKE 'fg_received_entry'")->num_rows > 0;
        $excludeFilter = "";
        if ($hasFgReceived) {
            $hasRefCol = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'reference_number'")->num_rows > 0;
            if ($hasRefCol) {
                // Exclude references that exist in fg_received_entry for the SAME trip
                // Handle comma-separated reference numbers by using FIND_IN_SET or LIKE pattern
                // Only exclude if the reference was received for the same trip number
                $hasTripCol = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'trip_number'")->num_rows > 0;
                $tripMatch = $hasTripCol ? "AND fre.trip_number = rt.trip" : "";
                
                $excludeFilter = "AND NOT EXISTS (
                    SELECT 1 
                    FROM fg_received_entry fre
                    WHERE fre.product_type = 'roll'
                      AND fre.reference_number IS NOT NULL 
                      AND fre.reference_number != ''
                      {$tripMatch}
                      AND (
                        fre.reference_number = rt.reference_number
                        OR FIND_IN_SET(rt.reference_number, REPLACE(fre.reference_number, ', ', ',')) > 0
                        OR fre.reference_number LIKE CONCAT(rt.reference_number, ',%')
                        OR fre.reference_number LIKE CONCAT('%, ', rt.reference_number, ',%')
                        OR fre.reference_number LIKE CONCAT('%, ', rt.reference_number)
                      )
                )";
            }
        }
        
        // Debug: Check total references without exclusion
        $testQuery = $conn->query("
            SELECT COUNT(*) as total_count
            FROM roll_transfer rt
            WHERE rt.to_location = 'FG'
              AND rt.reference_number IS NOT NULL
              AND rt.reference_number != ''
              AND rt.trip IS NOT NULL
              {$deletedCondition}
        ");
        $testResult = $testQuery ? $testQuery->fetch_assoc() : null;
        error_log("FG Received Entry: Total references in roll_transfer with to_location='FG' and trip IS NOT NULL: " . ($testResult['total_count'] ?? 0));
        
        // Debug: Check references after exclusion filter
        $testQuery2 = $conn->query("
            SELECT COUNT(*) as total_count
            FROM roll_transfer rt
            WHERE rt.to_location = 'FG'
              AND rt.reference_number IS NOT NULL
              AND rt.reference_number != ''
              AND rt.trip IS NOT NULL
              {$deletedCondition}
              {$excludeFilter}
        ");
        $testResult2 = $testQuery2 ? $testQuery2->fetch_assoc() : null;
        error_log("FG Received Entry: References after exclusion filter: " . ($testResult2['total_count'] ?? 0));
        
        $refQuery = $conn->query("
            SELECT 
                rt.reference_number,
                rt.trip,
                SUM(rt.amount_kg) AS total_amount,
                COALESCE(re.total_area, 0) AS total_area
            FROM roll_transfer rt
            LEFT JOIN roll_entry re ON rt.reference_number = re.reference_number
            WHERE rt.to_location = 'FG'
              AND rt.reference_number IS NOT NULL
              AND rt.reference_number != ''
              AND rt.trip IS NOT NULL
              {$deletedCondition}
              {$excludeFilter}
            GROUP BY rt.reference_number, rt.trip, re.total_area
            ORDER BY MAX(rt.date_time) DESC
            LIMIT 400
        ");
        
        if ($refQuery) {
            $refCount = 0;
            while ($row = $refQuery->fetch_assoc()) {
                $fgRollTripReferences[] = [
                    'reference_number' => $row['reference_number'],
                    'trip' => (int)$row['trip'],
                    'total_amount' => (float)$row['total_amount'],
                    'total_area' => (float)$row['total_area']
                ];
                $refCount++;
            }
            error_log("FG Received Entry: Loaded " . $refCount . " references from roll_transfer");
        } else {
            error_log("FG Received Entry: Query failed: " . $conn->error);
        }
    } else {
        error_log("FG Received Entry: Missing columns - hasToLocation: " . ($hasToLocation ? 'yes' : 'no') . ", hasTrip: " . ($hasTrip ? 'yes' : 'no'));
    }
}

// Fetch CNC cutting batch numbers from fg_entry where product_type = 'bag'
// Exclude batches that have already been submitted in fg_received_entry
$cncCuttingBatches = [];
$hasFgEntry = $conn->query("SHOW TABLES LIKE 'fg_entry'")->num_rows > 0;
if ($hasFgEntry) {
    $hasProductType = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'product_type'")->num_rows > 0;
    $hasCncBatch = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'cnc_cutting_batch'")->num_rows > 0;
    $hasPassedQty = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'passed_qty'")->num_rows > 0;
    
    if ($hasProductType && $hasCncBatch) {
        $hasFgReceived = $conn->query("SHOW TABLES LIKE 'fg_received_entry'")->num_rows > 0;
        $excludeFilter = "";
        if ($hasFgReceived) {
            $hasCncCol = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'cnc_cutting_batch'")->num_rows > 0;
            if ($hasCncCol) {
                // Exclude CNC batches that exist in fg_received_entry
                $excludeFilter = "AND cnc_cutting_batch NOT IN (
                    SELECT DISTINCT cnc_cutting_batch 
                    FROM fg_received_entry 
                    WHERE cnc_cutting_batch IS NOT NULL 
                      AND cnc_cutting_batch != ''
                      AND product_type = 'bag'
                )";
            }
        }
        
        $cncQuery = $conn->query("
            SELECT DISTINCT 
                cnc_cutting_batch,
                SUM(COALESCE(passed_qty, 0)) as total_quantity
            FROM fg_entry 
            WHERE product_type = 'bag'
              AND cnc_cutting_batch IS NOT NULL
              AND cnc_cutting_batch != ''
              {$excludeFilter}
            GROUP BY cnc_cutting_batch
            ORDER BY cnc_cutting_batch DESC
            LIMIT 200
        ");
        if ($cncQuery) {
            while ($row = $cncQuery->fetch_assoc()) {
                $cncCuttingBatches[] = [
                    'batch' => $row['cnc_cutting_batch'],
                    'quantity' => (int)($row['total_quantity'] ?? 0)
                ];
            }
        }
    }
}

// Get current user for shift in charge
$shiftInCharge = trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>FG Received Entry</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
  .container { max-width:1000px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);}
  h1 { text-align:center; font-size:28px; margin-bottom:30px; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  input[type="text"], input[type="number"], select, textarea {
    padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px);
  }
  textarea { min-height:80px; resize:vertical; }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .readonly { background:#ecf0f1; }
  .alert { padding:12px; border-radius:6px; margin-bottom:20px; }
  .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
  .alert-danger { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
  .btn-group { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px; }
  .btn { padding:8px 16px; border:1px solid #ccc; background:#f0f0f0; cursor:pointer; border-radius:6px; transition:all 0.2s; font-weight:500; }
  .btn:hover { background:#e0e0e0; }
  .btn.selected { background:#007bff; color:#fff; border-color:#007bff; }
  .modern-add-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px 16px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #ffffff;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
  }
  .modern-add-btn .btn-icon-wrapper {
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .modern-add-btn .btn-text {
    font-size: 13px;
    letter-spacing: 0.04em;
  }
  .reference-item {
    background: #e8f5e9;
    border: 1px solid #4caf50;
    border-radius: 6px;
    padding: 12px;
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .reference-item .ref-info {
    flex: 1;
  }
  .reference-item .ref-info strong {
    display: block;
    color: #2c3e50;
    margin-bottom: 5px;
  }
  .reference-item .ref-info small {
    color: #27ae60;
    font-weight: 600;
  }
</style>
</head>
<body>
<div class="container">
  
  <h1>FG Received Entry</h1>

  <?php if (isset($_GET['success']) && $_GET['success'] === 'fg_received_saved'): ?>
    <div class="alert alert-success">
      FG Received Entry saved successfully! Entry ID: <?php echo htmlspecialchars($_GET['entry_id'] ?? ''); ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger">
      ❌ Error: <?php 
        if ($_GET['error'] === 'missing_field') {
          echo "Missing required field: " . htmlspecialchars($_GET['field'] ?? '');
        } elseif ($_GET['error'] === 'database_error') {
          echo "Database error: " . htmlspecialchars($_GET['message'] ?? '');
        } elseif ($_GET['error'] === 'system_error') {
          echo "System error: " . htmlspecialchars($_GET['message'] ?? '');
        } else {
          echo "Unknown error occurred";
        }
      ?>
    </div>
  <?php endif; ?>

  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
  </div>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <form id="fgReceivedForm" method="post" action="../handlers/submit_fg_received_entry.php" onsubmit="return validateForm();" novalidate>

    <!-- Entry ID -->
    <div class="form-group">
      <label>Entry ID:</label>
      <input type="text" id="entryIdDisplay" value="<?php echo htmlspecialchars($pre_entry_id); ?>" readonly class="readonly">
      <input type="hidden" id="entry_id" name="entry_id" value="<?php echo htmlspecialchars($pre_entry_id); ?>">
    </div>

    <!-- Date and Time (hidden for submission) -->
    <input type="hidden" id="date_time" name="date_time">
    
    <!-- Shift (hidden for submission) -->
    <input type="hidden" id="shift" name="shift">

    <!-- Product Type Selection -->
    <div class="form-group">
      <label>Product Type: <span style="color:red;">*</span></label>
      <div class="btn-group" id="productTypeGroup" style="display:flex; gap:10px; flex-wrap:wrap;">
        <button type="button" class="btn product-type-btn" onclick="selectProductType('roll')" style="background:#e0e0e0;color:#333; border:2px solid #ccc;">
          <i class="fas fa-scroll"></i> Roll
        </button>
        <button type="button" class="btn product-type-btn" onclick="selectProductType('bag')" style="background:#e0e0e0;color:#333; border:2px solid #ccc;">
          <i class="fas fa-shopping-bag"></i> Bag
        </button>
      </div>
      <input type="hidden" id="product_type" name="product_type" value="" required>
    </div>

    <!-- Trip Number - Shown only when Roll is selected -->
    <div class="form-group" id="tripNumberGroup" style="display:none;">
      <label>Trip Number: <span style="color:red;">*</span></label>
      <select id="trip_number" name="trip_number" required onchange="onTripChange()">
        <option value="">-- Select Trip Number --</option>
      </select>
      <small style="color:#6c757d; display:block; margin-top:8px;">
        Select a trip number from roll transfers submitted as FG
      </small>
    </div>

    <!-- CNC Cutting Batch - Shown only when Bag is selected -->
    <div class="form-group" id="cncBatchGroup" style="display:none;">
      <label>CNC Cutting Batch: <span style="color:red;">*</span></label>
      <select id="cnc_cutting_batch" name="cnc_cutting_batch" required onchange="updateReceivedQuantityFromCNCBatch()">
        <option value="">-- Select CNC Cutting Batch --</option>
      </select>
      <small style="color:#6c757d; display:block; margin-top:8px;">
        Select a CNC cutting batch from FG entries
      </small>
    </div>

    <!-- Reference Number - Shown only when Trip is selected -->
    <div class="form-group" id="referenceGroup" style="display:none;">
      <label>Reference Number: <span style="color:red;">*</span></label>
      <div style="display:flex; gap:15px; align-items:flex-start; margin-bottom:10px;">
        <div style="flex:1; position:relative;">
          <input type="text"
                 id="reference_search"
                 placeholder="Search or type reference number..."
                 style="padding:10px; border:1px solid #ccc; border-radius:6px; width:100%;"
                 onkeyup="filterReferences()"
                 onfocus="showReferenceDropdown()"
                 disabled>
          <div id="reference_dropdown"
               style="display:none; max-height:220px; overflow-y:auto; border:1px solid #ccc; border-radius:6px; background:#fff; position:absolute; z-index:1000; width:100%; top:100%; box-shadow:0 4px 6px rgba(0,0,0,0.1); margin-top:2px;">
            <div id="no_references_message"
                 style="display:none; padding:15px; text-align:center; color:#999; font-style:italic;">
              Please select a trip number first.
            </div>
          </div>
        </div>
        <div style="display:flex; align-items:center; padding-top:0;">
          <button type="button" onclick="addReference()" class="modern-add-btn">
            <span class="btn-icon-wrapper">
              <i class="fas fa-plus"></i>
            </span>
            <span class="btn-text">Add</span>
          </button>
        </div>
      </div>
      <div id="selected_reference" style="margin-top:10px; min-height:30px;"></div>
      <small id="reference_hint" style="color:#6c757d; display:block; margin-top:5px;">Select a trip number first to load references.</small>
    </div>

    <input type="hidden" id="reference_number" name="reference_number" required>
    <input type="hidden" id="delivered_quantity" name="delivered_quantity" value="0">

    <!-- Received Quantity - Shown for both Roll and Bag -->
    <div class="form-group" id="receivedQuantityGroup" style="display:none;">
      <label id="received_quantity_label">Received Quantity: <span style="color:red;">*</span></label>
      <input type="number" id="received_quantity" name="received_quantity" min="0" step="0.01" placeholder="Auto-calculated" readonly class="readonly">
      <small id="received_quantity_hint" style="color:#6c757d; display:block; margin-top:8px;">
        Auto-calculated from selected references/batch
      </small>
    </div>

    <!-- Available Quantity - Shown only when Bag is selected -->
    <div class="form-group" id="availableQuantityGroup" style="display:none;">
      <label>Available Quantity:</label>
      <input type="number" id="available_quantity" name="available_quantity" readonly class="readonly" value="0">
      <small style="color:#6c757d; display:block; margin-top:8px;">
        Available quantity equals received quantity
      </small>
    </div>

    <!-- Shift In Charge -->
    <div class="form-group">
      <label>Shift In Charge: <span style="color:red;">*</span></label>
      <input type="text" id="shift_in_charge" name="shift_in_charge" value="<?php echo htmlspecialchars($shiftInCharge); ?>" readonly class="readonly">
    </div>

    <!-- Remarks -->
    <div class="form-group">
      <label>Remarks:</label>
      <textarea id="remarks" name="remarks" placeholder="Enter any additional remarks or notes..."></textarea>
    </div>

    <!-- Summary Box -->
    <div class="form-group">
      <div id="summaryBox" class="summary-info"></div>
      <input type="hidden" id="summary" name="summary">
    </div>

    <!-- Actions -->
    <div class="actions">
      <button type="submit" class="submit-btn">Submit</button>
      <button type="button" class="clear-btn" onclick="clearForm()">Clear</button>
    </div>
  </form>
</div>

<script src="https://kit.fontawesome.com/a076d05399.js"></script>
<script>
// FG roll references by trip
const fgTripReferences = <?php echo json_encode($fgRollTripReferences); ?>;
const fgTripNumbers = <?php echo json_encode($fgTripNumbers); ?>;
const cncCuttingBatches = <?php echo json_encode($cncCuttingBatches); ?>;

// Debug: Log loaded data
console.log('FG Trip References loaded:', fgTripReferences);
console.log('FG Trip Numbers loaded:', fgTripNumbers);

// Store CNC batch quantities for auto-calculation
const cncBatchQuantities = {};
<?php
foreach ($cncCuttingBatches as $batch) {
    echo "cncBatchQuantities['" . htmlspecialchars($batch['batch'], ENT_QUOTES) . "'] = " . (int)$batch['quantity'] . ";\n";
}
?>

// Function to load FG Trip Numbers into dropdown
function loadFGTripNumbers() {
  const tripSelect = document.getElementById('trip_number');
  if (!tripSelect) return;
  
  tripSelect.innerHTML = '<option value="">-- Select Trip Number --</option>';
  
  if (fgTripNumbers && fgTripNumbers.length > 0) {
    fgTripNumbers.forEach(tripData => {
      const option = document.createElement('option');
      option.value = tripData.trip;
      const dateStr = tripData.last_transfer_date ? new Date(tripData.last_transfer_date).toLocaleDateString() : '';
      option.textContent = 'Trip ' + tripData.trip + (dateStr ? ' (Last: ' + dateStr + ')' : '');
      tripSelect.appendChild(option);
    });
  } else {
    const option = document.createElement('option');
    option.value = '';
    option.textContent = 'No trips found';
    option.disabled = true;
    tripSelect.appendChild(option);
  }
}

let selectedReferences = [];
let currentTripFilter = '';

function onTripChange() {
  const tripSelect = document.getElementById('trip_number');
  const searchInput = document.getElementById('reference_search');
  const referenceGroup = document.getElementById('referenceGroup');
  const hint = document.getElementById('reference_hint');
  if (!tripSelect || !searchInput) return;

  const selectedTrip = tripSelect.value;
  currentTripFilter = selectedTrip;
  console.log('Trip changed to:', selectedTrip);
  console.log('Available fgTripReferences:', fgTripReferences);
  
  if (selectedTrip) {
    // Show reference group when trip is selected
    if (referenceGroup) referenceGroup.style.display = 'block';
    searchInput.disabled = false;
    
    // Load references immediately when trip is selected
    loadReferencesForTrip(selectedTrip);
    
    // Count references for this trip
    const tripRefs = fgTripReferences.filter(ref => {
      const refTrip = String(ref.trip || ref.trip_number || '');
      return refTrip === String(selectedTrip);
    });
    
    if (hint) {
      hint.textContent = `Showing ${tripRefs.length} reference(s) for Trip ${selectedTrip}.`;
    }
  } else {
    // Hide reference group when no trip is selected
    if (referenceGroup) referenceGroup.style.display = 'none';
    searchInput.disabled = true;
    clearReferenceDropdown();
    if (hint) hint.textContent = 'Select a trip number first to load references.';
  }

  selectedReferences = [];
  renderSelectedReferences();
  updateReferenceHiddenField();
  searchInput.value = '';
}

function loadReferencesForTrip(trip) {
  const dropdown = document.getElementById('reference_dropdown');
  if (!dropdown) return;
  const message = document.getElementById('no_references_message');
  dropdown.innerHTML = '';
  if (message) {
    message.style.display = 'none';
    dropdown.appendChild(message);
  }

  // Debug: Log the data
  console.log('Loading references for trip:', trip);
  console.log('Total fgTripReferences:', fgTripReferences.length);
  console.log('Sample reference:', fgTripReferences[0]);

  // Filter references for this trip - handle both string and number comparison
  const tripRefs = fgTripReferences.filter(ref => {
    // Try multiple ways to get the trip number
    const refTrip = String(ref.trip || ref.trip_number || '');
    const selectedTrip = String(trip);
    const matches = refTrip === selectedTrip;
    if (matches) {
      console.log('Matched reference:', ref.reference_number, 'trip:', refTrip, 'selected:', selectedTrip);
    }
    return matches;
  });
  
  console.log('Filtered references for trip', trip, ':', tripRefs.length, tripRefs);

  if (tripRefs.length === 0) {
    showNoReferencesMessage('No references found for the selected trip.');
    const hint = document.getElementById('reference_hint');
    if (hint) hint.textContent = 'No references found for Trip ' + trip + '.';
    dropdown.style.display = 'block'; // Show message
    return;
  }

  tripRefs.forEach(ref => {
    const option = document.createElement('div');
    option.className = 'reference-option';
    option.setAttribute('data-ref', ref.reference_number);
    option.setAttribute('data-total-amount', ref.total_amount || ref.totalAmount || 0);
    option.setAttribute('data-total-area', ref.total_area || ref.totalArea || 0);
    option.setAttribute('style', 'display:block; padding:10px; cursor:pointer; border-bottom:1px solid #eee;');
    const amount = parseFloat(ref.total_amount || ref.totalAmount || 0).toFixed(2);
    const area = ref.total_area || ref.totalArea || 0;
    option.innerHTML = `<strong>${escapeHtml(ref.reference_number)}</strong><br><small style="color:#27ae60; font-weight:600;">Qty: ${amount} kg${area ? ', Area: ' + parseFloat(area).toFixed(2) + ' sqm' : ''}</small>`;
    option.addEventListener('click', function() {
      selectReferenceFromDropdown(ref.reference_number);
    });
    dropdown.appendChild(option);
  });

  if (message) message.style.display = 'none';
  // Keep dropdown hidden - it will show when user focuses on input
  dropdown.style.display = 'none';
}

function showNoReferencesMessage(text) {
  const message = document.getElementById('no_references_message');
  if (message) {
    message.textContent = text || 'No references available for the selected trip.';
    message.style.display = 'block';
  }
}

function clearReferenceDropdown() {
  const dropdown = document.getElementById('reference_dropdown');
  const message = document.getElementById('no_references_message');
  if (!dropdown) return;
  dropdown.innerHTML = '';
  if (message) {
    message.style.display = 'block';
    message.textContent = 'Please select a trip number first.';
    dropdown.appendChild(message);
  }
}

function filterReferences() {
  const searchInput = document.getElementById('reference_search');
  const dropdown = document.getElementById('reference_dropdown');
  if (!searchInput || !dropdown) return;

  const term = searchInput.value.trim().toLowerCase();
  const options = dropdown.querySelectorAll('.reference-option');
  let visibleCount = 0;
  options.forEach(option => {
    const refText = (option.getAttribute('data-ref') || '').toLowerCase();
    if (!term || refText.includes(term)) {
      option.style.display = 'block';
      visibleCount++;
    } else {
      option.style.display = 'none';
    }
  });

  const noRefsMsg = document.getElementById('no_references_message');
  if (noRefsMsg) noRefsMsg.style.display = visibleCount === 0 ? 'block' : 'none';
  dropdown.style.display = visibleCount === 0 ? 'none' : 'block';
}

function showReferenceDropdown() {
  const dropdown = document.getElementById('reference_dropdown');
  const tripSelect = document.getElementById('trip_number');
  if (!dropdown || !tripSelect) return;
  
  // If no trip is selected, show message
  if (!tripSelect.value) {
    const message = document.getElementById('no_references_message');
    if (message) {
      message.textContent = 'Please select a trip number first.';
      message.style.display = 'block';
    }
    dropdown.style.display = 'block';
    return;
  }
  
  // Ensure references are loaded for the selected trip
  const selectedTrip = tripSelect.value;
  loadReferencesForTrip(selectedTrip);
  
  // Show the dropdown
  filterReferences();
  dropdown.style.display = 'block';
}

function selectReferenceFromDropdown(refNumber) {
  const searchInput = document.getElementById('reference_search');
  const dropdown = document.getElementById('reference_dropdown');
  if (searchInput) searchInput.value = refNumber;
  if (dropdown) dropdown.style.display = 'none';
}

function addReference() {
  const searchInput = document.getElementById('reference_search');
  if (!searchInput) return;
  const value = searchInput.value.trim();
  if (!value) {
    alert('Please search and select a reference number first.');
    return;
  }

  if (!currentTripFilter) {
    alert('Please select a trip number first.');
    return;
  }

  const dropdown = document.getElementById('reference_dropdown');
  const options = dropdown ? dropdown.querySelectorAll('.reference-option') : [];
  let matchedOption = null;
  options.forEach(option => {
    if (!matchedOption) {
      const refText = option.getAttribute('data-ref') || '';
      if (refText.toLowerCase() === value.toLowerCase()) {
        matchedOption = option;
      }
    }
  });
  if (!matchedOption) {
    alert('Please select a valid reference from the dropdown first.');
    return;
  }

  const reference = matchedOption.getAttribute('data-ref');
  if (selectedReferences.some(ref => ref.reference === reference)) {
    alert('This reference has already been added.');
    searchInput.value = '';
    return;
  }

  const totalAmount = parseFloat(matchedOption.getAttribute('data-total-amount')) || 0;
  const totalArea = parseFloat(matchedOption.getAttribute('data-total-area')) || 0;
  selectedReferences.push({
    id: 'ref_' + Date.now() + '_' + Math.random().toString(36).substr(2, 5),
    reference,
    trip: currentTripFilter,
    amountKg: totalAmount,
    areaSqm: totalArea
  });

  renderSelectedReferences();
  updateReferenceHiddenField();
  searchInput.value = '';
  if (dropdown) dropdown.style.display = 'none';
}

function renderSelectedReferences() {
  const container = document.getElementById('selected_reference');
  if (!container) return;
  if (selectedReferences.length === 0) {
    container.innerHTML = '<small style="color:#999;">No references added yet.</small>';
    return;
  }

  container.innerHTML = '';
  selectedReferences.forEach(ref => {
    const item = document.createElement('div');
    item.className = 'reference-item';
    item.innerHTML = `
      <div class="ref-info">
        <strong>${escapeHtml(ref.reference)}</strong>
        <small>Trip: ${ref.trip} | KG: ${parseFloat(ref.amountKg).toFixed(2)} | SQM: ${parseFloat(ref.areaSqm).toFixed(2)}</small>
      </div>
    `;
    container.appendChild(item);
  });
}

function updateReferenceHiddenField() {
  const refField = document.getElementById('reference_number');
  const qtyField = document.getElementById('delivered_quantity');
  const receivedQtyField = document.getElementById('received_quantity');
  
  if (refField) {
    refField.value = selectedReferences.map(ref => ref.reference).join(', ');
  }
  if (qtyField) {
    const totalKg = selectedReferences.reduce((sum, ref) => sum + ref.amountKg, 0);
    qtyField.value = totalKg.toFixed(2);
  }
  
  // Auto-calculate received quantity for rolls from selected references
  if (receivedQtyField && document.getElementById('product_type').value === 'roll') {
    const totalKg = selectedReferences.reduce((sum, ref) => sum + ref.amountKg, 0);
    receivedQtyField.value = totalKg.toFixed(2);
    updateAvailableQuantity();
  }
  
  updateSummary();
}

// Update received quantity from CNC batch selection (for bags)
function updateReceivedQuantityFromCNCBatch() {
  const cncBatchSelect = document.getElementById('cnc_cutting_batch');
  const receivedQtyField = document.getElementById('received_quantity');
  const hint = document.getElementById('received_quantity_hint');
  
  if (!cncBatchSelect || !receivedQtyField) return;
  
  const selectedBatch = cncBatchSelect.value;
  if (selectedBatch && cncBatchQuantities[selectedBatch] !== undefined) {
    receivedQtyField.value = cncBatchQuantities[selectedBatch];
    if (hint) {
      hint.textContent = 'Auto-calculated from CNC batch quantity: ' + cncBatchQuantities[selectedBatch];
    }
    updateAvailableQuantity();
    updateSummary();
  } else {
    receivedQtyField.value = '0';
    if (hint) {
      hint.textContent = 'Auto-calculated from selected references/batch';
    }
    updateAvailableQuantity();
  }
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// Function to load CNC Cutting Batches into dropdown
function loadCNCBatches() {
  const cncSelect = document.getElementById('cnc_cutting_batch');
  if (!cncSelect) return;
  
  cncSelect.innerHTML = '<option value="">-- Select CNC Cutting Batch --</option>';
  
  if (cncCuttingBatches && cncCuttingBatches.length > 0) {
    cncCuttingBatches.forEach(batchData => {
      const option = document.createElement('option');
      option.value = batchData.batch;
      option.textContent = batchData.batch + ' (Qty: ' + batchData.quantity + ')';
      cncSelect.appendChild(option);
    });
  } else {
    const option = document.createElement('option');
    option.value = '';
    option.textContent = 'No CNC batches found';
    option.disabled = true;
    cncSelect.appendChild(option);
  }
}

function selectProductType(type) {
  const productTypeField = document.getElementById('product_type');
  const tripNumberGroup = document.getElementById('tripNumberGroup');
  const referenceGroup = document.getElementById('referenceGroup');
  const cncBatchGroup = document.getElementById('cncBatchGroup');
  const receivedQuantityGroup = document.getElementById('receivedQuantityGroup');
  const availableQuantityGroup = document.getElementById('availableQuantityGroup');
  const productTypeBtns = document.querySelectorAll('.product-type-btn');
  
  if (productTypeField) productTypeField.value = type;
  
  productTypeBtns.forEach(btn => {
    btn.classList.remove('selected');
    btn.style.background = '#e0e0e0';
    btn.style.color = '#333';
    btn.style.border = '2px solid #ccc';
  });
  
  const clickedBtn = event.target.closest('.product-type-btn');
  if (clickedBtn) {
    clickedBtn.classList.add('selected');
    clickedBtn.style.background = '#007bff';
    clickedBtn.style.color = '#fff';
    clickedBtn.style.border = '2px solid #007bff';
  }
  
  const receivedQtyField = document.getElementById('received_quantity');
  const receivedQtyLabel = document.getElementById('received_quantity_label');
  const hint = document.getElementById('received_quantity_hint');
  
  if (type === 'roll') {
    if (tripNumberGroup) tripNumberGroup.style.display = 'block';
    if (referenceGroup) referenceGroup.style.display = 'none'; // Hide initially, show when trip is selected
    if (cncBatchGroup) cncBatchGroup.style.display = 'none';
    if (receivedQuantityGroup) receivedQuantityGroup.style.display = 'block';
    if (availableQuantityGroup) availableQuantityGroup.style.display = 'none';
    if (receivedQtyField) receivedQtyField.value = '0';
    if (receivedQtyLabel) receivedQtyLabel.innerHTML = 'Received Quantity (KG): <span style="color:red;">*</span>';
    if (hint) hint.textContent = 'Auto-calculated from selected references';
    loadFGTripNumbers();
    // Clear any previous selections
    selectedReferences = [];
    renderSelectedReferences();
    updateReferenceHiddenField();
    const searchInput = document.getElementById('reference_search');
    if (searchInput) {
      searchInput.disabled = true;
      searchInput.value = '';
    }
    clearReferenceDropdown();
  } else if (type === 'bag') {
    if (tripNumberGroup) tripNumberGroup.style.display = 'none';
    if (referenceGroup) referenceGroup.style.display = 'none';
    if (cncBatchGroup) cncBatchGroup.style.display = 'block';
    if (receivedQuantityGroup) receivedQuantityGroup.style.display = 'block';
    if (availableQuantityGroup) availableQuantityGroup.style.display = 'block';
    if (receivedQtyField) receivedQtyField.value = '0';
    if (receivedQtyLabel) receivedQtyLabel.innerHTML = 'Received Quantity (Pcs): <span style="color:red;">*</span>';
    if (hint) hint.textContent = 'Auto-calculated from selected CNC batch';
    loadCNCBatches();
    selectedReferences = [];
    renderSelectedReferences();
    updateReferenceHiddenField();
  } else {
    if (tripNumberGroup) tripNumberGroup.style.display = 'none';
    if (referenceGroup) referenceGroup.style.display = 'none';
    if (cncBatchGroup) cncBatchGroup.style.display = 'none';
    if (receivedQuantityGroup) receivedQuantityGroup.style.display = 'none';
    if (availableQuantityGroup) availableQuantityGroup.style.display = 'none';
    selectedReferences = [];
    renderSelectedReferences();
    updateReferenceHiddenField();
  }
  
  updateSummary();
}

function updateTimeAndShift() {
  const now = new Date();
  const utc = now.getTime() + now.getTimezoneOffset() * 60000;
  const dhaka = new Date(utc + 6 * 3600000);
  const dateTimeStr = dhaka.toLocaleString('en-US', { 
    year: 'numeric', 
    month: '2-digit', 
    day: '2-digit', 
    hour: '2-digit', 
    minute: '2-digit',
    second: '2-digit',
    hour12: true 
  });
  
  document.getElementById('date_time').value = dateTimeStr;
  document.getElementById('dateTimeDisplay').textContent = 'Date & Time: ' + dateTimeStr;
  
  const hour = dhaka.getHours();
  const shift = (hour >= 8 && hour < 20) ? 'Day' : 'Night';
  document.getElementById('shift').value = shift;
  document.getElementById('shiftBanner').textContent = 'Shift: ' + shift;
  
  updateSummary();
}

function updateSummary() {
  const shiftInCharge = document.getElementById('shift_in_charge').value || '';
  const productType = document.getElementById('product_type').value || '';
  const tripNumber = document.getElementById('trip_number') ? document.getElementById('trip_number').value : '';
  const referenceNumber = document.getElementById('reference_number').value || '';
  const deliveredQuantity = document.getElementById('delivered_quantity').value || '0';
  const cncBatch = document.getElementById('cnc_cutting_batch') ? document.getElementById('cnc_cutting_batch').value : '';
  const receivedQuantity = document.getElementById('received_quantity') ? document.getElementById('received_quantity').value : '';
  const remarks = document.getElementById('remarks').value || '';
  
  let summary = '';
  if (shiftInCharge) {
    summary = `Shift in Charge: ${shiftInCharge}`;
    if (productType) {
      summary += ` | Type: ${productType.charAt(0).toUpperCase() + productType.slice(1)}`;
    }
    if (productType === 'roll') {
      if (tripNumber) {
        summary += ` | Trip: ${tripNumber}`;
      }
      if (referenceNumber) {
        summary += ` | References: ${referenceNumber}`;
      }
      if (deliveredQuantity && parseFloat(deliveredQuantity) > 0) {
        summary += ` | Total KG: ${parseFloat(deliveredQuantity).toFixed(2)}`;
      }
    } else if (productType === 'bag') {
      if (cncBatch) {
        summary += ` | CNC Batch: ${cncBatch}`;
      }
      if (receivedQuantity) {
        summary += ` | Received Qty: ${receivedQuantity}`;
      }
    }
    if (remarks) {
      summary += ` | Remarks: ${remarks.substring(0, 30)}${remarks.length > 30 ? '...' : ''}`;
    }
  }
  
  document.getElementById('summaryBox').textContent = summary;
  document.getElementById('summary').value = summary;
}

function validateForm() {
  const productType = document.getElementById('product_type').value;
  if (!productType) {
    alert('Please select a product type (Roll or Bag).');
    return false;
  }
  
  if (productType === 'roll') {
    const tripNumber = document.getElementById('trip_number').value;
    if (!tripNumber) {
      alert('Please select a Trip Number.');
      return false;
    }
    
    const referenceNumber = document.getElementById('reference_number').value;
    if (!referenceNumber) {
      alert('Please add at least one reference number.');
      return false;
    }
    
    const receivedQuantity = document.getElementById('received_quantity').value;
    if (!receivedQuantity || parseFloat(receivedQuantity) <= 0) {
      alert('Received quantity must be calculated from selected references.');
      return false;
    }
  } else if (productType === 'bag') {
    const cncBatch = document.getElementById('cnc_cutting_batch').value;
    if (!cncBatch) {
      alert('Please select a CNC Cutting Batch.');
      return false;
    }
    
    const receivedQuantity = document.getElementById('received_quantity').value;
    if (!receivedQuantity || parseFloat(receivedQuantity) <= 0) {
      alert('Received quantity must be calculated from selected CNC batch.');
      return false;
    }
  }
  
  const shiftInCharge = document.getElementById('shift_in_charge').value;
  if (!shiftInCharge || shiftInCharge.trim() === '') {
    alert('Shift in Charge is required.');
    return false;
  }
  
  return true;
}

function clearForm() {
  document.getElementById('fgReceivedForm').reset();
  document.getElementById('summaryBox').textContent = '';
  document.getElementById('summary').value = '';
  
  document.querySelectorAll('.btn').forEach(btn => btn.classList.remove('selected'));
  document.querySelectorAll('.product-type-btn').forEach(btn => {
    btn.style.background = '#e0e0e0';
    btn.style.color = '#333';
    btn.style.border = '2px solid #ccc';
  });
  
  document.getElementById('product_type').value = '';
  const tripNumberField = document.getElementById('trip_number');
  if (tripNumberField) tripNumberField.value = '';
  
  const tripNumberGroup = document.getElementById('tripNumberGroup');
  const referenceGroup = document.getElementById('referenceGroup');
  const cncBatchGroup = document.getElementById('cncBatchGroup');
  const receivedQuantityGroup = document.getElementById('receivedQuantityGroup');
  const availableQuantityGroup = document.getElementById('availableQuantityGroup');
  if (tripNumberGroup) tripNumberGroup.style.display = 'none';
  if (referenceGroup) referenceGroup.style.display = 'none';
  if (cncBatchGroup) cncBatchGroup.style.display = 'none';
  if (receivedQuantityGroup) receivedQuantityGroup.style.display = 'none';
  if (availableQuantityGroup) availableQuantityGroup.style.display = 'none';
  
  selectedReferences = [];
  renderSelectedReferences();
  updateReferenceHiddenField();
  
  updateTimeAndShift();
}

// Update available quantity when received quantity changes
function updateAvailableQuantity() {
  const receivedQty = document.getElementById('received_quantity');
  const availableQty = document.getElementById('available_quantity');
  const productType = document.getElementById('product_type').value;
  
  if (receivedQty) {
    const value = receivedQty.value || '0';
    // For bags, available quantity equals received quantity
    if (productType === 'bag' && availableQty) {
      availableQty.value = value;
    }
    updateSummary();
  }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
  updateTimeAndShift();
  const tripSelect = document.getElementById('trip_number');
  if (tripSelect) {
    tripSelect.addEventListener('change', onTripChange);
  }
  
  // Update available quantity when received quantity changes (for bags)
  const receivedQty = document.getElementById('received_quantity');
  if (receivedQty) {
    receivedQty.addEventListener('input', updateAvailableQuantity);
    receivedQty.addEventListener('change', updateAvailableQuantity);
  }
  
  // Update summary on input changes
  const inputs = ['shift_in_charge', 'remarks', 'cnc_cutting_batch'];
  inputs.forEach(function(inputId) {
    const element = document.getElementById(inputId);
    if (element) {
      element.addEventListener('input', updateSummary);
      element.addEventListener('change', updateSummary);
    }
  });
  
  updateSummary();
});

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
  const dropdown = document.getElementById('reference_dropdown');
  const searchInput = document.getElementById('reference_search');
  if (dropdown && searchInput && !dropdown.contains(event.target) && !searchInput.contains(event.target)) {
    dropdown.style.display = 'none';
  }
});
</script>
</body>
</html>
