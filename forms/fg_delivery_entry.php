<?php
session_start();
require_once '../config/security_config.php';

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

// Role-based access control for Finished Goods module
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_FINISHED_GOODS, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access the FG Delivery module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// Prevent caching - always generate fresh delivery ID and challan
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// DB connection
$conn = SecurityConfig::getConnection();

// Pre-generate FG Delivery ID (FD-YYYYMMDD-XXX) with 8 AM daily reset
$dhaka_tz = new DateTimeZone('Asia/Dhaka');
$now = new DateTime('now', $dhaka_tz);
$current_hour = (int)$now->format('H');

// Determine reset date (8 AM cutoff)
$reset_date = clone $now;
if ($current_hour < 8) {
    // Before 8 AM - use yesterday's date
    $reset_date->modify('-1 day');
}
$reset_date->setTime(8, 0, 0);
$reset_timestamp = $reset_date->format('Y-m-d H:i:s');

$date_part = $reset_date->format('Ymd');
$delivery_prefix = 'FD-' . $date_part . '-';
$next_delivery_number = 1;

// Check fg_deliveries table (new table)
$table_check = $conn->query("SHOW TABLES LIKE 'fg_deliveries'");
if ($table_check && $table_check->num_rows > 0) {
    $column_check = $conn->query("SHOW COLUMNS FROM fg_deliveries LIKE 'delivery_id'");
    if ($column_check && $column_check->num_rows > 0) {
        // Get max number for deliveries created since 8 AM reset
        $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(delivery_id, -3) AS UNSIGNED)) as last_num 
                                FROM fg_deliveries 
                                WHERE created_at >= ?");
        if ($stmt) {
            $stmt->bind_param('s', $reset_timestamp);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                if ($row['last_num']) {
                    $next_delivery_number = $row['last_num'] + 1;
                }
            }
            $stmt->close();
        }
    }
}

$pre_delivery_id = $delivery_prefix . str_pad((string)$next_delivery_number, 3, '0', STR_PAD_LEFT);

// Generate Lighthouse Challan Number (CN-YYYYMMDD-XXX) with 8 AM daily reset
// Increment based on total deliveries today, not just auto-generated challans
$challan_prefix = 'CN-' . $date_part . '-';
$next_challan_number = 1;

// Check fg_deliveries table - count ALL deliveries created since 8 AM reset
if ($table_check && $table_check->num_rows > 0) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total_deliveries 
                            FROM fg_deliveries 
                            WHERE created_at >= ?");
    if ($stmt) {
        $stmt->bind_param('s', $reset_timestamp);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $next_challan_number = $row['total_deliveries'] + 1;
        }
        $stmt->close();
    }
}

$pre_challan_no = $challan_prefix . str_pad((string)$next_challan_number, 3, '0', STR_PAD_LEFT);

// Fetch BOM prices for bag sizes
$bomPrices = [];
$bomQuery = $conn->query("SELECT DISTINCT bag_size, unit_price FROM bom WHERE is_deleted = 0 AND bag_size IS NOT NULL");
if ($bomQuery) {
    while ($row = $bomQuery->fetch_assoc()) {
        $bomPrices[$row['bag_size']] = $row['unit_price'];
    }
}

// Fetch FG entries with remaining quantity (only those with stock available)
$fgEntries = [];
$queryError = null;

// Ensure delivered_quantity column exists (proper MySQL syntax)
$colCheck = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'delivered_quantity'");
if ($colCheck && $colCheck->num_rows == 0) {
    $conn->query("ALTER TABLE fg_entry ADD COLUMN delivered_quantity DECIMAL(10,2) DEFAULT 0 AFTER batch_number");
}

// Schema-aware query for FG entries eligible for delivery
$colExists = function(mysqli $conn, string $table, string $column): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    $col = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    return $res && $res->num_rows > 0;
};

$hasProductType = $colExists($conn, 'fg_entry', 'product_type');
$hasRollEntryType = $colExists($conn, 'fg_entry', 'roll_entry_type');
$hasActualWeight = $colExists($conn, 'fg_entry', 'actual_weight');
$delCol = $colExists($conn, 'fg_deliveries', 'delivery_quantity') ? 'fd.delivery_quantity' : ($colExists($conn, 'fg_deliveries', 'delivery_qty') ? 'fd.delivery_qty' : '0');

$selectProductType = $hasProductType ? "fe.product_type," : "";
$selectRollEntryType = $hasRollEntryType ? "fe.roll_entry_type," : "";

$weightFilter = $hasProductType
    ? "AND ((fe.product_type = 'roll' AND fe.actual_weight > 0) OR (fe.product_type != 'roll' AND fe.passed_qty > 0) OR (fe.product_type IS NULL AND fe.passed_qty > 0))"
    : "AND (fe.passed_qty > 0)";

$remainingExpr = $hasProductType && $hasActualWeight
    ? "CASE WHEN fe.product_type = 'roll' THEN (fe.actual_weight - COALESCE(SUM({$delCol}), 0)) ELSE (fe.passed_qty - COALESCE(SUM({$delCol}), 0)) END"
    : "(fe.passed_qty - COALESCE(SUM({$delCol}), 0))";

$having = "HAVING ({$remainingExpr} > 0)";

$groupCols = "fe.id, fe.fg_id, fe.reference_number, fe.cnc_cutting_batch, fe.bag_size, fe.packaging_type, fe.passed_qty, fe.actual_weight, p.project_name";
if ($hasProductType) $groupCols .= ", fe.product_type";
if ($hasRollEntryType) $groupCols .= ", fe.roll_entry_type";

$fgQuery = "SELECT 
              fe.id,
              fe.fg_id,
              fe.reference_number,
              fe.cnc_cutting_batch,
              fe.bag_size,
              fe.packaging_type,
              fe.passed_qty,
              fe.actual_weight,
              {$selectProductType}
              {$selectRollEntryType}
              COALESCE(SUM({$delCol}), 0) as delivered_quantity,
              {$remainingExpr} as remaining_quantity,
              p.project_name
            FROM fg_entry fe
            LEFT JOIN projects p ON fe.project_id = p.id
            LEFT JOIN fg_deliveries fd ON fe.id = fd.fg_entry_id
            WHERE fe.reference_number IS NOT NULL
              AND fe.reference_number != ''
              {$weightFilter}
            GROUP BY {$groupCols}
            {$having}
            ORDER BY fe.created_at DESC
            LIMIT 100";

$fgResult = $conn->query($fgQuery);
if ($fgResult) {
    while ($row = $fgResult->fetch_assoc()) {
        $fgEntries[] = $row;
    }
} else {
    $queryError = $conn->error;
    error_log("FG Delivery Query Error: " . $queryError);
}

// Fetch Clients - schema-aware (client_name or name)
$clients = [];
$hasClientName = $conn->query("SHOW COLUMNS FROM clients LIKE 'client_name'");
$hasName = $conn->query("SHOW COLUMNS FROM clients LIKE 'name'");
$clientNameExpr = ($hasClientName && $hasClientName->num_rows > 0) ? "client_name" : "NULL";
$nameExpr = ($hasName && $hasName->num_rows > 0) ? "name" : "NULL";
$cSelect = "SELECT id, COALESCE({$clientNameExpr}, {$nameExpr}, '') AS client_name 
    FROM clients 
    WHERE (COALESCE({$clientNameExpr}, {$nameExpr}, '') != '')
    ORDER BY client_name ASC";
$cres = $conn->query($cSelect);
if ($cres) {
    while ($row = $cres->fetch_assoc()) {
        if (!empty($row['client_name'])) {
            $clients[] = $row;
        }
    }
}
if (empty($clients)) {
    error_log("FG Delivery: No clients found in database (client_name/name missing or empty).");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>FG Delivery Entry</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:0; color:#2c3e50; }
  .container { max-width:100%; margin:0; background:#fff; border-radius:0; padding:25px 80px; box-shadow:none;}
  h1 { text-align:center; font-size:28px; margin-bottom:30px; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; color:#2c3e50; }
  input[type="text"], input[type="number"], select {
    padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px);
  }
  .readonly { background:#ecf0f1; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { 
    padding:12px 28px; 
    font-size:15px; 
    font-weight:600;
    border:none; 
    border-radius:8px; 
    cursor:pointer; 
    margin:0 10px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
  }
  .actions button:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
  }
  .actions button:active {
    transform: translateY(0);
    box-shadow: 0 1px 2px rgba(0,0,0,0.1);
  }
  .submit-btn { 
    background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%); 
    color:#fff; 
  }
  .submit-btn:hover {
    background: linear-gradient(135deg, #27ae60 0%, #229954 100%);
  }
  .clear-btn { 
    background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); 
    color:#fff; 
  }
  .clear-btn:hover {
    background: linear-gradient(135deg, #c0392b 0%, #a93226 100%);
  }
  .alert { padding:12px; border-radius:6px; margin-bottom:20px; text-align:center; font-weight:600; }
  .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
  .alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
  .summary-info {
    font-size: 16px;
    font-weight: bold;
    padding: 10px;
    border-radius: 8px;
    text-align: center;
    margin-bottom: 20px;
    background: #f0f0f0;
  }
  
  /* Button Group Styles */
  .btn-group { 
    display: flex; 
    gap: 12px; 
    flex-wrap: wrap;
    margin-bottom: 10px;
  }
  .btn {
    padding: 12px 24px;
    font-size: 14px;
    font-weight: 600;
    border: 2px solid #cbd5e0;
    border-radius: 8px;
    cursor: pointer;
    background: #ffffff;
    color: #4a5568;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
  }
  .btn:hover {
    border-color: #3498db;
    color: #3498db;
    background: #ebf8ff;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(52, 152, 219, 0.2);
  }
  .btn:active {
    transform: translateY(0);
  }
  .btn.selected {
    background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
    color: #ffffff;
    border-color: #2980b9;
    box-shadow: 0 4px 12px rgba(52, 152, 219, 0.4);
  }
  
  /* Client Autocomplete Styles */
  #client_autocomplete_list {
    font-family: 'Inter', sans-serif;
  }
  .client-autocomplete-item {
    padding: 12px 15px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
    transition: background-color 0.2s;
    font-size: 14px;
    color: #2c3e50;
  }
  .client-autocomplete-item:hover {
    background-color: #f8f9fa;
  }
  .client-autocomplete-item:last-child {
    border-bottom: none;
  }
  .client-autocomplete-item.highlight {
    background-color: #e3f2fd;
    font-weight: 600;
  }
  #client_search:focus {
    outline: none;
    border-color: #3498db;
    box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
  }
</style>
</head>
<body>
<div class="container">
  
  <h1>FG Delivery Entry</h1>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">
      ✅ <?php echo htmlspecialchars($_GET['success']); ?>
    </div>
  <?php endif; ?>
  
  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error">
      ❌ <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <form id="fgDeliveryForm" method="post" action="../handlers/submit_fg_delivery_entry.php" onsubmit="return validateForm();">

    <!-- Hidden datetime + shift + delivery_date -->
    <input type="hidden" id="dateTime" name="date_time">
    <input type="hidden" id="shift" name="shift">
    <input type="hidden" id="delivery_date" name="delivery_date">
    
    <script>
    // BOM price mapping
    const bomPrices = <?php echo json_encode($bomPrices); ?>;
    </script>

    <!-- FG Delivery ID -->
    <div class="form-group">
      <label>FG Delivery ID:</label>
      <?php 
      // Always use pre-generated ID (don't reuse from URL)
      $deliveryId = $pre_delivery_id; 
      ?>
      <input type="text" value="<?php echo htmlspecialchars($deliveryId); ?>" readonly class="readonly">
      <input type="hidden" name="delivery_id" value="<?php echo htmlspecialchars($deliveryId); ?>">
    </div>

    <!-- Product Type Selection -->
    <div class="form-group">
      <label>Product Type: <span style="color:red;">*</span></label>
      <div class="btn-group" id="deliveryProductTypeGroup" style="display:flex; gap:10px; flex-wrap:wrap;">
        <button type="button" class="btn delivery-product-type-btn" onclick="selectDeliveryProductType('roll')" style="background:#e0e0e0;color:#333; border:2px solid #ccc;">
          <i class="fas fa-scroll"></i> Roll
        </button>
        <button type="button" class="btn delivery-product-type-btn" onclick="selectDeliveryProductType('bag')" style="background:#e0e0e0;color:#333; border:2px solid #ccc;">
          <i class="fas fa-shopping-bag"></i> Bag
        </button>
      </div>
      <input type="hidden" id="delivery_product_type" name="delivery_product_type" value="" required>
      <input type="hidden" id="delivery_unit" name="delivery_unit" value="piece">
    </div>

    <!-- Roll Entry Type (Individual or Bundle) - Shown only when Roll is selected -->
    <div class="form-group" id="deliveryRollEntryTypeGroup" style="display:none;">
      <label>Entry Type: <span style="color:red;">*</span></label>
      <div class="btn-group" style="display:flex; gap:10px; flex-wrap:wrap;">
        <button type="button" class="btn delivery-roll-entry-type-btn" onclick="selectDeliveryRollEntryType('individual')" style="background:#e0e0e0;color:#333; border:2px solid #ccc;">
          <i class="fas fa-circle"></i> Individual Roll
        </button>
        <button type="button" class="btn delivery-roll-entry-type-btn" onclick="selectDeliveryRollEntryType('bundle')" style="background:#e0e0e0;color:#333; border:2px solid #ccc;">
          <i class="fas fa-layer-group"></i> Bundle
        </button>
      </div>
      <input type="hidden" id="delivery_roll_entry_type" name="delivery_roll_entry_type" value="">
    </div>

    <!-- All fields below this will be hidden until product/entry type is selected -->
    <div id="deliveryFieldsContainer" style="display:none;">

    <!-- Product -->
    <!-- Reference Number -->
    <div class="form-group">
      <label>Reference Number:</label>
      <select id="reference_number" name="reference_number" required onchange="updateCNCBatchFromRef()">
        <option value="">-- Select Product Type First --</option>
      </select>
      <small id="delivery_reference_hint" style="color:#6c757d; display:block; margin-top:5px;"></small>
    </div>

    <!-- CNC Cutting Batch (auto-filled) - Only for bags -->
    <div class="form-group" id="cncBatchGroup" style="display:none;">
      <label>CNC Cutting Batch:</label>
      <input type="text" id="cnc_cutting_batch" name="cnc_cutting_batch" readonly style="background-color: #f0f0f0;" placeholder="Auto-filled from reference">
      <input type="hidden" id="fg_entry_id" name="fg_entry_id" value="">
      <input type="hidden" id="bag_size" name="bag_size" value="">
      <input type="hidden" id="packaging_type" name="packaging_type" value="">
    </div>
    
    <!-- Available Quantity (Display only) -->
    <div class="form-group">
      <label id="available_qty_label">Available Quantity (pcs):</label>
      <input type="number" id="available_qty" readonly style="background-color: #f0f0f0; font-weight: bold;" placeholder="Auto-filled from stock">
    </div>

    <!-- Delivery Quantity (User can edit) -->
    <div class="form-group">
      <label id="delivery_qty_label">Delivery Quantity (pcs):</label>
      <input type="number" id="delivery_qty" name="delivery_qty" required min="1" step="0.01" placeholder="Enter quantity to deliver" oninput="validateDeliveryQty()">
      <small style="color: #7f8c8d; font-size: 0.9em; display: none;" id="qty_hint"></small>
    </div>

    <!-- Client -->
    <div class="form-group">
      <label>Client: <span style="color:red;">*</span></label>
      <div style="display: flex; gap: 10px; align-items: center;">
        <div style="position: relative; flex: 1;">
          <input type="text" id="client_search" name="client_search" placeholder="Type to search client or enter new client name..." autocomplete="off" required style="width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px;">
          <div id="client_autocomplete_list" style="display: none; position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid #ccc; border-top: none; border-radius: 0 0 6px 6px; max-height: 300px; overflow-y: auto; z-index: 1000; box-shadow: 0 4px 6px rgba(0,0,0,0.1);"></div>
        </div>
        <button type="button" class="btn" onclick="enableManualClient()" id="manualClientBtn" style="background: linear-gradient(135deg, #718A95 0%, #5A6F7A 100%); color: white; padding: 10px 20px; white-space: nowrap; border-color: #5A6F7A; box-shadow: 0 2px 6px rgba(114, 132, 143, 0.3);">
          ✏️ Manual Entry
        </button>
      </div>
      <small style="color: #7f8c8d; font-size: 0.85em; display: block; margin-top: 5px;">💡 Type to search existing clients or enter a new client name</small>
      <input type="hidden" id="client_id" name="client_id" value="" required>
      <input type="hidden" id="client_name" name="client_name" value="" required>
    </div>

    <!-- Truck Number -->
    <div class="form-group">
      <label>Truck Number:</label>
      <input type="text" id="truck_no" name="truck_no" placeholder="e.g., DHK-1234">
    </div>

    <!-- Destination -->
    <div class="form-group">
      <label>Destination:</label>
      <input type="text" id="destination" name="destination" placeholder="e.g., Dhaka Warehouse">
    </div>

    <!-- Lighthouse Challan Number -->
    <div class="form-group">
      <label>Lighthouse Challan Number:</label>
      <div style="display: flex; gap: 10px; align-items: center;">
        <input type="text" id="challan_no" name="challan_no" value="<?php echo htmlspecialchars($pre_challan_no); ?>" readonly class="readonly" style="flex: 1;">
        <button type="button" class="btn" onclick="enableManualChallan()" style="background: linear-gradient(135deg, #718A95 0%, #5A6F7A 100%); color: white; padding: 10px 20px; white-space: nowrap; border-color: #5A6F7A; box-shadow: 0 2px 6px rgba(114, 132, 143, 0.3);">
          ✏️ Manual Entry
        </button>
      </div>
      <input type="text" id="challan_no_manual" placeholder="Enter lighthouse challan number manually" style="margin-top: 10px; display: none;">
    </div>

    <!-- Unit Price (auto-filled from BOM or manual for custom bags) -->
    <div class="form-group">
      <label>Unit Price (৳ per pcs):</label>
      <input type="number" id="unit_price" name="unit_price" step="0.01" min="0" required>
      <small style="color: #7f8c8d; font-size: 0.9em;" id="price_hint">Auto-filled from BOM</small>
    </div>

    <!-- Remarks -->
    <div class="form-group">
      <label>Remarks:</label>
      <textarea id="remarks" name="remarks" rows="3" placeholder="Any additional notes..." style="width: calc(100% - 22px); padding: 10px; border: 1px solid #ccc; border-radius: 6px; resize: vertical;"></textarea>
    </div>

    <!-- Summary Section -->
    </div><!-- End deliveryFieldsContainer -->

    <div class="form-group">
      <div id="summaryBox" class="summary-info"></div>
      <input type="hidden" id="summary" name="summary">
    </div>

    <div class="actions">
      <button type="submit" class="submit-btn">Submit</button>
      <button type="reset" class="clear-btn" onclick="clearForm()">Clear</button>
    </div>
  </form>
</div>

<script>
// FG Entries data from PHP
const fgEntriesData = <?php echo json_encode($fgEntries); ?>;
const queryError = <?php echo json_encode($queryError); ?>;

// Clients data from PHP
const clientsData = <?php echo json_encode($clients); ?>;
// Debug: Log clients count
console.log('Clients loaded:', clientsData.length, clientsData);

// Minimal debug logging
// Data loaded from PHP

function selectDeliveryProductType(type) {
  // Remove selected styling from all product type buttons
  const allBtns = document.querySelectorAll('.delivery-product-type-btn');
  allBtns.forEach(btn => {
    btn.style.background = '#e0e0e0';
    btn.style.color = '#333';
    btn.style.border = '2px solid #ccc';
    btn.classList.remove('selected');
  });
  
  // Add selected styling to clicked button (blue)
  event.target.style.background = '#2196F3';
  event.target.style.color = '#fff';
  event.target.style.border = '2px solid #1976D2';
  event.target.classList.add('selected');
  
  // Set hidden input
  document.getElementById('delivery_product_type').value = type;
  
  // Update delivery_unit based on product type
  const deliveryUnitField = document.getElementById('delivery_unit');
  if (deliveryUnitField) {
    deliveryUnitField.value = (type === 'roll') ? 'kg' : 'piece';
  }
  
  // Update labels based on product type
  const availableQtyLabel = document.getElementById('available_qty_label');
  const deliveryQtyLabel = document.getElementById('delivery_qty_label');
  
  // Show/hide CNC cutting batch field (only for bags)
  const cncBatchGroup = document.getElementById('cncBatchGroup');
  
  if (type === 'roll') {
    // For rolls, use kg and hide CNC batch field
    if (availableQtyLabel) availableQtyLabel.textContent = 'Available Quantity (kg):';
    if (deliveryQtyLabel) deliveryQtyLabel.textContent = 'Delivery Quantity (kg):';
    if (cncBatchGroup) cncBatchGroup.style.display = 'none';
    // Clear CNC batch value for rolls
    const cncBatchField = document.getElementById('cnc_cutting_batch');
    if (cncBatchField) cncBatchField.value = '';
  } else if (type === 'bag') {
    // For bags, use pcs and show CNC batch field
    if (availableQtyLabel) availableQtyLabel.textContent = 'Available Quantity (pcs):';
    if (deliveryQtyLabel) deliveryQtyLabel.textContent = 'Delivery Quantity (pcs):';
    if (cncBatchGroup) cncBatchGroup.style.display = 'block';
  }
  
  // Show/hide roll entry type selection
  const rollEntryTypeGroup = document.getElementById('deliveryRollEntryTypeGroup');
  const deliveryFieldsContainer = document.getElementById('deliveryFieldsContainer');
  
  if (type === 'roll') {
    // For rolls, show entry type selection first
    if(rollEntryTypeGroup) rollEntryTypeGroup.style.display = 'block';
    if(deliveryFieldsContainer) deliveryFieldsContainer.style.display = 'none'; // Wait for entry type
  } else if (type === 'bag') {
    // For bags, show all fields immediately
    if(rollEntryTypeGroup) rollEntryTypeGroup.style.display = 'none';
    if(deliveryFieldsContainer) deliveryFieldsContainer.style.display = 'block';
    loadBagDeliveryReferences();
  }
  
  updateSummary();
}

function selectDeliveryRollEntryType(entryType) {
  // Remove selected styling from all entry type buttons
  const allBtns = document.querySelectorAll('.delivery-roll-entry-type-btn');
  allBtns.forEach(btn => {
    btn.style.background = '#e0e0e0';
    btn.style.color = '#333';
    btn.style.border = '2px solid #ccc';
    btn.classList.remove('selected');
  });
  
  // Add selected styling to clicked button (blue)
  event.target.style.background = '#2196F3';
  event.target.style.color = '#fff';
  event.target.style.border = '2px solid #1976D2';
  event.target.classList.add('selected');
  
  // Set hidden input
  document.getElementById('delivery_roll_entry_type').value = entryType;
  
  // Show delivery fields container
  const deliveryFieldsContainer = document.getElementById('deliveryFieldsContainer');
  if(deliveryFieldsContainer) deliveryFieldsContainer.style.display = 'block';
  
  // Hide CNC batch field for rolls (rolls don't have CNC cutting batch)
  const cncBatchGroup = document.getElementById('cncBatchGroup');
  if (cncBatchGroup) cncBatchGroup.style.display = 'none';
  const cncBatchField = document.getElementById('cnc_cutting_batch');
  if (cncBatchField) cncBatchField.value = '';
  
  // Load roll references based on entry type
  loadRollDeliveryReferences(entryType);
  
  updateSummary();
}

function loadRollDeliveryReferences(entryType) {
  const referenceSelect = document.getElementById('reference_number');
  const referenceHint = document.getElementById('delivery_reference_hint');
  referenceSelect.innerHTML = '<option value="">-- Select Reference --</option>';
  
  // Filter FG entries for rolls matching the entry type
  const rollEntries = fgEntriesData.filter(entry => {
    const isRoll = entry.product_type === 'roll';
    const matchesEntryType = entry.roll_entry_type === entryType;
    return isRoll && matchesEntryType;
  });
  
  rollEntries.forEach(entry => {
    const option = document.createElement('option');
    option.value = entry.reference_number;
    option.textContent = entry.reference_number + ' (Remaining: ' + entry.remaining_quantity + ' kg)';
    
    // Ensure all data attributes are strings
    const fgId = String(entry.id || '');
    const cncBatch = String(entry.cnc_cutting_batch || '');
    const bagSize = String(entry.bag_size || '');
    const projectName = String(entry.project_name || '');
    const passedQty = String(entry.passed_qty || '0');
    const actualWeight = String(entry.actual_weight || '0');
    const deliveredQty = String(entry.delivered_quantity || '0');
    const remainingQty = String(entry.remaining_quantity || '0');
    
    // Set all data attributes
    option.setAttribute('data-fg-id', fgId);
    option.setAttribute('data-cnc-batch', cncBatch);
    option.setAttribute('data-bag-size', bagSize);
    option.setAttribute('data-project', projectName);
    option.setAttribute('data-passed-qty', passedQty);
    option.setAttribute('data-actual-weight', actualWeight);
    option.setAttribute('data-delivered-qty', deliveredQty);
    option.setAttribute('data-remaining-qty', remainingQty);
    
    referenceSelect.appendChild(option);
  });
  
  if (rollEntries.length === 0) {
    referenceSelect.innerHTML += '<option value="" disabled>No ' + entryType + ' roll entries available</option>';
  }
  
  referenceHint.textContent = entryType === 'individual' ? 'Showing individual roll entries' : 'Showing bundle entries';
}

function loadBagDeliveryReferences() {
  const referenceSelect = document.getElementById('reference_number');
  const referenceHint = document.getElementById('delivery_reference_hint');
  referenceSelect.innerHTML = '<option value="">-- Select Reference --</option>';
  
  // Filter FG entries for bags (including NULL product_type which are bags)
  const bagEntries = fgEntriesData.filter(entry => {
    return entry.product_type === 'bag' || entry.product_type === null || entry.product_type === '';
  });
  
  bagEntries.forEach(entry => {
    const option = document.createElement('option');
    option.value = entry.reference_number;
    option.textContent = entry.reference_number + ' (Remaining: ' + entry.remaining_quantity + ' pcs)';
    
    // Ensure all data attributes are strings and set properly
    const fgId = String(entry.id || '');
    const cncBatch = String(entry.cnc_cutting_batch || '');
    const bagSize = String(entry.bag_size || '');
    const projectName = String(entry.project_name || '');
    const passedQty = String(entry.passed_qty || '0');
    const actualWeight = String(entry.actual_weight || '0');
    const deliveredQty = String(entry.delivered_quantity || '0');
    const remainingQty = String(entry.remaining_quantity || '0');
    
    // Set all data attributes
    option.setAttribute('data-fg-id', fgId);
    option.setAttribute('data-cnc-batch', cncBatch);
    option.setAttribute('data-bag-size', bagSize);
    option.setAttribute('data-project', projectName);
    option.setAttribute('data-passed-qty', passedQty);
    option.setAttribute('data-actual-weight', actualWeight);
    option.setAttribute('data-delivered-qty', deliveredQty);
    option.setAttribute('data-remaining-qty', remainingQty);
    
    referenceSelect.appendChild(option);
  });
  
  if (bagEntries.length === 0) {
    referenceSelect.innerHTML += '<option value="" disabled>No bag entries with remaining stock</option>';
  }
  
  referenceHint.textContent = 'Showing ' + bagEntries.length + ' bag entries with available stock';
}

function updateCNCBatchFromRef() {
  const refSelect = document.getElementById("reference_number");
  if (!refSelect) return;
  
  const selectedIndex = refSelect.selectedIndex;
  if (selectedIndex < 0 || selectedIndex === 0) {
    // Reset fields if no valid option selected
    document.getElementById("cnc_cutting_batch").value = '';
    return;
  }
  
  const selectedOption = refSelect.options[selectedIndex];
  if (!selectedOption || !selectedOption.value) {
    // No reference selected - reset all fields
    document.getElementById("cnc_cutting_batch").value = '';
    document.getElementById("fg_entry_id").value = '';
    document.getElementById("available_qty").value = '';
    const deliveryQtyField = document.getElementById("delivery_qty");
    if (deliveryQtyField) {
      deliveryQtyField.value = '';
      deliveryQtyField.readOnly = false;
      deliveryQtyField.style.backgroundColor = 'white';
      deliveryQtyField.style.fontWeight = 'normal';
      deliveryQtyField.removeAttribute("data-max-qty");
    }
    const maxQtyElem = document.getElementById("max_qty");
    if (maxQtyElem) maxQtyElem.textContent = "0";
    const unitPriceField = document.getElementById("unit_price");
    const priceHint = document.getElementById("price_hint");
    if (unitPriceField) {
      unitPriceField.value = '';
      unitPriceField.readOnly = false;
      unitPriceField.style.backgroundColor = "white";
    }
    if (priceHint) {
      priceHint.textContent = "Auto-filled from BOM";
      priceHint.style.color = "#7f8c8d";
    }
    updateSummary();
    return;
  }
  
  const unitPriceField = document.getElementById("unit_price");
  const priceHint = document.getElementById("price_hint");
  const qtyHint = document.getElementById("qty_hint");
  
  // Get product type to determine if CNC batch should be shown
  const productType = document.getElementById('delivery_product_type').value;
  
  // Only update CNC cutting batch for bags (not for rolls)
  if (productType === 'bag') {
    // Get CNC batch - try multiple methods
    let cncBatch = selectedOption.getAttribute("data-cnc-batch");
    if (!cncBatch || cncBatch === 'null' || cncBatch === 'undefined') {
      cncBatch = selectedOption.dataset.cncBatch || '';
    }
    cncBatch = String(cncBatch || '').trim();
    
    // Update CNC cutting batch field
    const cncBatchField = document.getElementById("cnc_cutting_batch");
    const cncBatchGroup = document.getElementById("cncBatchGroup");
    if (cncBatchField) {
      cncBatchField.value = cncBatch;
    }
    if (cncBatchGroup) {
      cncBatchGroup.style.display = 'block';
    }
  } else {
    // For rolls, hide and clear CNC batch field
    const cncBatchField = document.getElementById("cnc_cutting_batch");
    const cncBatchGroup = document.getElementById("cncBatchGroup");
    if (cncBatchField) {
      cncBatchField.value = '';
    }
    if (cncBatchGroup) {
      cncBatchGroup.style.display = 'none';
    }
  }
  
  // Store FG entry ID
  const fgId = selectedOption.getAttribute("data-fg-id") || '';
  const fgIdField = document.getElementById("fg_entry_id");
  if (fgIdField) fgIdField.value = fgId;
  
  // Store bag size and packaging type in hidden fields
  const bagSize = selectedOption.getAttribute("data-bag-size") || '';
  const packagingType = selectedOption.getAttribute("data-packaging-type") || '';
  const bagSizeField = document.getElementById("bag_size");
  const packagingTypeField = document.getElementById("packaging_type");
  if (bagSizeField) bagSizeField.value = bagSize;
  if (packagingTypeField) packagingTypeField.value = packagingType;
  
  // Get quantities
  const passedQty = parseFloat(selectedOption.getAttribute("data-passed-qty")) || 0;
  const deliveredQty = parseFloat(selectedOption.getAttribute("data-delivered-qty")) || 0;
  const remainingQty = parseFloat(selectedOption.getAttribute("data-remaining-qty")) || 0;
  
  // Display available quantity (format to 2 decimal places if needed)
  const availableQtyField = document.getElementById("available_qty");
  if (availableQtyField) {
    // Format the value - show as integer if whole number, otherwise 2 decimal places
    const formattedQty = remainingQty % 1 === 0 ? remainingQty.toString() : remainingQty.toFixed(2);
    availableQtyField.value = formattedQty;
  }
  
  const deliveryQtyField = document.getElementById("delivery_qty");
  const qtyHintElem = document.getElementById("qty_hint");
  
  if (deliveryQtyField && qtyHintElem) {
    // Always enable the field first (clear any previous readonly state)
    deliveryQtyField.readOnly = false;
    deliveryQtyField.removeAttribute('readonly');
    deliveryQtyField.disabled = false;
    deliveryQtyField.value = "";
    deliveryQtyField.style.backgroundColor = "white";
    deliveryQtyField.style.fontWeight = "normal";
    
    // Get product type to show correct unit
    const productType = document.getElementById('delivery_product_type').value;
    const unit = productType === 'bag' ? 'pcs' : 'kg';
    
    // Set hint with max quantity
    qtyHintElem.innerHTML = `Enter quantity (max: <span id="max_qty">${remainingQty}</span> ${unit})`;
    qtyHintElem.style.display = 'block';
    
    // Store max quantity for validation
    deliveryQtyField.setAttribute("data-max-qty", remainingQty);
  }
  
  // Auto-fill unit price from BOM based on bag size
  if (unitPriceField && priceHint) {
    if (bagSize && bomPrices[bagSize]) {
      // Predefined bag size - auto-fill price from BOM
      unitPriceField.value = bomPrices[bagSize];
      unitPriceField.readOnly = true;
      unitPriceField.style.backgroundColor = "#f0f0f0";
      priceHint.textContent = "Auto-filled from BOM";
      priceHint.style.color = "#27ae60";
    } else {
      // Custom bag size - allow manual price entry
      unitPriceField.value = "";
      unitPriceField.readOnly = false;
      unitPriceField.style.backgroundColor = "white";
      priceHint.textContent = "Enter price manually (custom bag size)";
      priceHint.style.color = "#e67e22";
    }
  }
  
  updateSummary();
}

// Handle delivery unit selection
function validateDeliveryQty() {
  const deliveryQtyField = document.getElementById("delivery_qty");
  const maxQty = parseFloat(deliveryQtyField.getAttribute("data-max-qty")) || 0;
  const enteredQty = parseFloat(deliveryQtyField.value) || 0;
  const qtyHint = document.getElementById("qty_hint");
  
  // Get product type to show correct unit
  const productType = document.getElementById('delivery_product_type').value;
  const unit = productType === 'bag' ? 'pcs' : 'kg';
  
  if (enteredQty > maxQty) {
    qtyHint.innerHTML = `<span style="color: #e74c3c;">⚠️ Cannot exceed ${maxQty} ${unit}!</span>`;
    deliveryQtyField.style.borderColor = "#e74c3c";
  } else if (enteredQty > 0) {
    qtyHint.innerHTML = `Enter quantity (max: <span id="max_qty">${maxQty}</span> ${unit})`;
    deliveryQtyField.style.borderColor = "#27ae60";
  } else {
    qtyHint.innerHTML = `Enter quantity (max: <span id="max_qty">${maxQty}</span> ${unit})`;
    deliveryQtyField.style.borderColor = "#ccc";
  }
  
  updateSummary();
}

function enableManualChallan() {
  const autoField = document.getElementById("challan_no");
  const manualField = document.getElementById("challan_no_manual");
  const button = event.target;
  
  if (manualField.style.display === "none") {
    // Enable manual mode
    manualField.style.display = "block";
    manualField.focus();
    autoField.removeAttribute("name"); // Don't submit auto field
    manualField.setAttribute("name", "challan_no"); // Submit manual field instead
    button.textContent = "🔄 Use Auto";
    button.style.background = "linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%)";
    button.style.borderColor = "#7f8c8d";
    button.style.boxShadow = "0 2px 6px rgba(149, 165, 166, 0.3)";
  } else {
    // Switch back to auto mode
    manualField.style.display = "none";
    manualField.value = "";
    manualField.removeAttribute("name");
    autoField.setAttribute("name", "challan_no");
    button.textContent = "✏️ Manual Entry";
    button.style.background = "linear-gradient(135deg, #718A95 0%, #5A6F7A 100%)";
    button.style.borderColor = "#5A6F7A";
    button.style.boxShadow = "0 2px 6px rgba(114, 132, 143, 0.3)";
  }
}

// Store original autocomplete handler
let originalClientInputHandler = null;

function enableManualClient() {
  const clientSearch = document.getElementById("client_search");
  const button = document.getElementById("manualClientBtn");
  let isManualMode = clientSearch.getAttribute("data-manual-mode") === "true";
  
  if (!isManualMode) {
    // Enable manual mode - disable autocomplete
    clientSearch.setAttribute("data-manual-mode", "true");
    clientSearch.placeholder = "Enter client name manually...";
    hideAutocomplete();
    
    // Store and remove autocomplete handlers
    const currentValue = clientSearch.value;
    const newInput = clientSearch.cloneNode(true);
    clientSearch.parentNode.replaceChild(newInput, clientSearch);
    newInput.value = currentValue;
    newInput.setAttribute("data-manual-mode", "true");
    newInput.setAttribute("id", "client_search");
    
    // Add manual input handler
    newInput.addEventListener('input', function() {
      const clientNameHidden = document.getElementById('client_name');
      const clientIdHidden = document.getElementById('client_id');
      if (clientNameHidden && clientIdHidden) {
        if (this.value.trim()) {
          clientNameHidden.value = this.value.trim();
          clientIdHidden.value = "0"; // Custom client ID
        } else {
          clientNameHidden.value = "";
          clientIdHidden.value = "";
        }
      }
      updateSummary();
    });
    
    button.textContent = "🔄 Use Autocomplete";
    button.style.background = "linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%)";
    button.style.borderColor = "#7f8c8d";
    button.style.boxShadow = "0 2px 6px rgba(149, 165, 166, 0.3)";
  } else {
    // Switch back to autocomplete mode
    const currentValue = clientSearch.value;
    const newInput = clientSearch.cloneNode(true);
    clientSearch.parentNode.replaceChild(newInput, clientSearch);
    newInput.value = currentValue;
    newInput.removeAttribute("data-manual-mode");
    newInput.setAttribute("id", "client_search");
    newInput.placeholder = "Type to search client or enter new client name...";
    
    // Re-initialize autocomplete
    initializeClientAutocompleteForField(newInput);
    
    button.textContent = "✏️ Manual Entry";
    button.style.background = "linear-gradient(135deg, #718A95 0%, #5A6F7A 100%)";
    button.style.borderColor = "#5A6F7A";
    button.style.boxShadow = "0 2px 6px rgba(114, 132, 143, 0.3)";
  }
}

function initializeClientAutocompleteForField(field) {
  if (!field) return;
  
  // Filter clients as user types
  field.addEventListener('input', function() {
    if (this.getAttribute("data-manual-mode") !== "true") {
      filterClients(this.value);
      handleClientInput();
    }
  });
  
  // Handle keyboard navigation
  field.addEventListener('keydown', function(e) {
    if (this.getAttribute("data-manual-mode") === "true") return;
    
    const autocompleteList = document.getElementById('client_autocomplete_list');
    if (!autocompleteList || autocompleteList.style.display === 'none') {
      if (e.key === 'Enter') {
        e.preventDefault();
        handleClientInput();
        return;
      }
      return;
    }
    
    const items = autocompleteList.querySelectorAll('.client-autocomplete-item');
    
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      selectedClientIndex = Math.min(selectedClientIndex + 1, items.length - 1);
      items[selectedClientIndex]?.scrollIntoView({ block: 'nearest' });
      items.forEach((item, idx) => {
        item.classList.toggle('highlight', idx === selectedClientIndex);
      });
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      selectedClientIndex = Math.max(selectedClientIndex - 1, -1);
      if (selectedClientIndex >= 0) {
        items[selectedClientIndex]?.scrollIntoView({ block: 'nearest' });
      }
      items.forEach((item, idx) => {
        item.classList.toggle('highlight', idx === selectedClientIndex);
      });
    } else if (e.key === 'Enter') {
      e.preventDefault();
      if (selectedClientIndex >= 0 && items[selectedClientIndex]) {
        const clientId = items[selectedClientIndex].getAttribute('data-client-id');
        const clientName = items[selectedClientIndex].getAttribute('data-client-name');
        selectClient(clientId, clientName);
      } else {
        handleClientInput();
      }
    } else if (e.key === 'Escape') {
      hideAutocomplete();
    }
  });
}

// Client Autocomplete Functionality
let selectedClientIndex = -1;
let filteredClients = [];

function filterClients(searchTerm) {
  if (!searchTerm || searchTerm.trim() === '') {
    filteredClients = [];
    hideAutocomplete();
    return;
  }
  
  // Check if clientsData is empty
  if (!clientsData || clientsData.length === 0) {
    const autocompleteList = document.getElementById('client_autocomplete_list');
    if (autocompleteList) {
      autocompleteList.innerHTML = '<div class="client-autocomplete-item" style="color: #e74c3c; font-weight: 600;">⚠️ No clients in database. Please import clients first via <a href="../admin/import_clients.php" target="_blank" style="color: #3498db;">admin/import_clients.php</a></div>';
      autocompleteList.style.display = 'block';
    }
    return;
  }
  
  const term = searchTerm.toLowerCase().trim();
  filteredClients = clientsData.filter(client => {
    const clientName = (client.client_name || '').toLowerCase();
    return clientName.startsWith(term);
  });
  
  displayAutocomplete(filteredClients);
}

function displayAutocomplete(clients) {
  const autocompleteList = document.getElementById('client_autocomplete_list');
  if (!autocompleteList) return;
  
  if (clients.length === 0) {
    autocompleteList.innerHTML = '<div class="client-autocomplete-item" style="color: #7f8c8d; font-style: italic;">No matching clients found. Press Enter to add as new client.</div>';
    autocompleteList.style.display = 'block';
    return;
  }
  
  autocompleteList.innerHTML = '';
  clients.forEach((client, index) => {
    const item = document.createElement('div');
    item.className = 'client-autocomplete-item';
    item.textContent = client.client_name;
    item.setAttribute('data-client-id', client.id);
    item.setAttribute('data-client-name', client.client_name);
    item.addEventListener('click', () => selectClient(client.id, client.client_name));
    item.addEventListener('mouseenter', () => {
      // Remove highlight from all items
      autocompleteList.querySelectorAll('.client-autocomplete-item').forEach(i => i.classList.remove('highlight'));
      // Add highlight to hovered item
      item.classList.add('highlight');
      selectedClientIndex = index;
    });
    autocompleteList.appendChild(item);
  });
  
  autocompleteList.style.display = 'block';
  selectedClientIndex = -1;
}

function hideAutocomplete() {
  const autocompleteList = document.getElementById('client_autocomplete_list');
  if (autocompleteList) {
    autocompleteList.style.display = 'none';
  }
  selectedClientIndex = -1;
}

function selectClient(clientId, clientName) {
  const clientSearch = document.getElementById('client_search');
  const clientIdHidden = document.getElementById('client_id');
  const clientNameHidden = document.getElementById('client_name');
  
  if (clientSearch) clientSearch.value = clientName;
  if (clientIdHidden) clientIdHidden.value = clientId;
  if (clientNameHidden) clientNameHidden.value = clientName;
  
  hideAutocomplete();
  updateSummary();
}

function handleClientInput() {
  const clientSearch = document.getElementById('client_search');
  const clientIdHidden = document.getElementById('client_id');
  const clientNameHidden = document.getElementById('client_name');
  
  if (!clientSearch) return;
  
  const searchValue = clientSearch.value.trim();
  
  // If empty, clear hidden fields
  if (searchValue === '') {
    if (clientIdHidden) clientIdHidden.value = '';
    if (clientNameHidden) clientNameHidden.value = '';
    hideAutocomplete();
    updateSummary();
    return;
  }
  
  // Check if the exact value matches a client
  const exactMatch = clientsData.find(client => 
    client.client_name.toLowerCase() === searchValue.toLowerCase()
  );
  
  if (exactMatch) {
    // Exact match found - set as selected client
    if (clientIdHidden) clientIdHidden.value = exactMatch.id;
    if (clientNameHidden) clientNameHidden.value = exactMatch.client_name;
    hideAutocomplete();
  } else {
    // No exact match - treat as new client
    if (clientIdHidden) clientIdHidden.value = '0'; // 0 indicates new client
    if (clientNameHidden) clientNameHidden.value = searchValue;
    // Show autocomplete suggestions if there are any
    filterClients(searchValue);
  }
  
  updateSummary();
}

// Initialize client autocomplete on page load
document.addEventListener('DOMContentLoaded', function() {
  const clientSearch = document.getElementById('client_search');
  if (clientSearch) {
    initializeClientAutocompleteForField(clientSearch);
    
    // Hide autocomplete when clicking outside
    document.addEventListener('click', function(e) {
      if (!clientSearch.contains(e.target) && 
          !document.getElementById('client_autocomplete_list')?.contains(e.target)) {
        hideAutocomplete();
      }
    });
  }
});

function validateForm(){
  updateSummary(); // Update summary before validation
  
  if(!document.getElementById("reference_number").value){
    alert("Please select a reference number."); 
    return false;
  }
  
  // Check client_name (from search field or hidden field)
  const clientSearch = document.getElementById("client_search");
  const clientName = document.getElementById("client_name").value;
  if((!clientSearch || !clientSearch.value.trim()) && !clientName){
    alert("Please enter a client name."); 
    return false;
  }
  
  if(!document.getElementById("unit_price").value || parseFloat(document.getElementById("unit_price").value) <= 0){
    alert("Please enter a valid unit price."); 
    return false;
  }
  
  const deliveryQty = parseFloat(document.getElementById("delivery_qty").value);
  const deliveryQtyField = document.getElementById("delivery_qty");
  const maxQty = parseFloat(deliveryQtyField.getAttribute("data-max-qty")) || 0;
  const productType = document.getElementById("delivery_product_type").value;
  const unit = productType === 'bag' ? 'pcs' : 'kg';
  
  if(!deliveryQty || deliveryQty <= 0){
    alert("Please enter a delivery quantity."); 
    return false;
  }
  
  if(maxQty === 0){
    alert("⚠️ ERROR: Maximum quantity is 0!\n\nThis means:\n• The dropdown option didn't have data-remaining-qty attribute set\n• Try refreshing the page\n• Check the debug panel at the top\n\nDebug Info:\n• Delivery Qty: " + deliveryQty + "\n• Max Qty from attribute: " + deliveryQtyField.getAttribute("data-max-qty")); 
    return false;
  }
  
  if(deliveryQty > maxQty){
    alert("Delivery quantity (" + deliveryQty + " " + unit + ") cannot exceed available stock (" + maxQty + " " + unit + ")."); 
    return false;
  }
  
  return true;
}

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
  document.getElementById("delivery_date").value = `${yyyy}-${mm}-${dd}`;

  const h = dhaka.getHours();
  const shift = (h >= 8 && h <= 19) ? "Day" : "Night";
  document.getElementById("shiftBanner").innerText = "Shift: " + shift;
  document.getElementById("shift").value = shift;
}

function updateSummary() {
  const dateTime = document.getElementById("dateTime").value;
  const shift = document.getElementById("shift").value;
  const productType = document.getElementById("delivery_product_type").value;
  const rollEntryType = document.getElementById("delivery_roll_entry_type").value;
  const referenceNumber = document.getElementById("reference_number").value;
  const cncCuttingBatch = document.getElementById("cnc_cutting_batch").value;
  const deliveryQty = document.getElementById("delivery_qty").value;
  const clientSearch = document.getElementById("client_search");
  const unitPrice = document.getElementById("unit_price").value;
  let clientName = document.getElementById("client_name").value;
  
  // Use client_search value if client_name is not set
  if (!clientName && clientSearch && clientSearch.value.trim()) {
    clientName = clientSearch.value.trim();
  }
  
  // Check if we have client info
  const hasClient = clientName && clientName.trim() !== '';
  
  if (dateTime && shift && productType && referenceNumber && deliveryQty && hasClient && unitPrice) {
    const totalCost = (parseFloat(deliveryQty) * parseFloat(unitPrice)).toFixed(2);
    
    let productTypeText = '';
    if (productType === 'roll') {
      productTypeText = rollEntryType === 'bundle' ? 'Roll (Bundle)' : 'Roll (Individual)';
    } else if (productType === 'bag') {
      productTypeText = 'Bag';
    }
    
    // Get unit based on product type
    const unit = productType === 'bag' ? 'pcs' : 'kg';
    const unitShort = productType === 'bag' ? 'pc' : 'kg';
    
    let summaryText = `${dateTime} | Shift: ${shift} | Type: ${productTypeText} | Reference: ${referenceNumber}`;
    if (cncCuttingBatch) summaryText += ` | CNC Batch: ${cncCuttingBatch}`;
    summaryText += ` | Delivery Qty: ${deliveryQty} ${unit} | Unit Price: ৳${parseFloat(unitPrice).toFixed(2)}/${unitShort} | Total Cost: ৳${totalCost} | Client: ${clientName}`;
    
    document.getElementById("summaryBox").textContent = summaryText;
    document.getElementById("summary").value = summaryText;
  } else {
    document.getElementById("summaryBox").textContent = "Please fill all fields to see summary";
    document.getElementById("summary").value = "";
  }
}

function clearForm() {
  document.getElementById("fgDeliveryForm").reset();
  document.getElementById("summaryBox").textContent = "Please fill all fields to see summary";
  document.getElementById("summary").value = "";
  document.getElementById("available_qty").value = "";
  document.getElementById("delivery_qty").value = "";
  document.getElementById("delivery_qty").removeAttribute("data-max-qty");
  document.getElementById("max_qty").textContent = "0";
  
  // Reset product type selection
  document.querySelectorAll('.delivery-product-type-btn').forEach(btn => {
    btn.style.background = '#e0e0e0';
    btn.style.color = '#333';
    btn.style.border = '2px solid #ccc';
    btn.classList.remove('selected');
  });
  
  // Reset roll entry type selection
  document.querySelectorAll('.delivery-roll-entry-type-btn').forEach(btn => {
    btn.style.background = '#e0e0e0';
    btn.style.color = '#333';
    btn.style.border = '2px solid #ccc';
    btn.classList.remove('selected');
  });
  
  // Reset labels to default (pcs)
  const availableQtyLabel = document.getElementById('available_qty_label');
  const deliveryQtyLabel = document.getElementById('delivery_qty_label');
  if (availableQtyLabel) availableQtyLabel.textContent = 'Available Quantity (pcs):';
  if (deliveryQtyLabel) deliveryQtyLabel.textContent = 'Delivery Quantity (pcs):';
  
  // Hide conditional sections
  const rollEntryTypeGroup = document.getElementById('deliveryRollEntryTypeGroup');
  const deliveryFieldsContainer = document.getElementById('deliveryFieldsContainer');
  if (rollEntryTypeGroup) rollEntryTypeGroup.style.display = 'none';
  if (deliveryFieldsContainer) deliveryFieldsContainer.style.display = 'none';
  
  // Reset hidden inputs
  document.getElementById('delivery_product_type').value = '';
  document.getElementById('delivery_roll_entry_type').value = '';
  document.getElementById('delivery_unit').value = 'piece';
  
  // Reset reference dropdown
  const referenceSelect = document.getElementById('reference_number');
  referenceSelect.innerHTML = '<option value="">-- Select Product Type First --</option>';
  
  // Reset client search
  const clientSearch = document.getElementById('client_search');
  const clientIdHidden = document.getElementById('client_id');
  const clientNameHidden = document.getElementById('client_name');
  if (clientSearch) clientSearch.value = '';
  if (clientIdHidden) clientIdHidden.value = '';
  if (clientNameHidden) clientNameHidden.value = '';
  hideAutocomplete();
}

// Add event listeners for real-time summary updates
document.addEventListener('DOMContentLoaded', function() {
  const refSelect = document.getElementById("reference_number");
  if (refSelect) refSelect.addEventListener('change', updateSummary);
  
  const deliveryQty = document.getElementById("delivery_qty");
  if (deliveryQty) deliveryQty.addEventListener('input', updateSummary);
  
  const unitPrice = document.getElementById("unit_price");
  if (unitPrice) unitPrice.addEventListener('input', updateSummary);
  
  // Initial updates
  updateTimeAndShift();
  updateSummary();
});

// Update time and shift every second
setInterval(updateTimeAndShift, 1000);
</script>
</body>
</html>

