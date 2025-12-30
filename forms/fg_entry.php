<?php
// fg_entry.php

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

// Connect DB (shared)
$conn = SecurityConfig::getConnection();

// Performance: Defer project loading - will load asynchronously after page render
$projects = [];

// Predefined bag sizes with recommended weights (in kg)
$predefinedBagSizes = array(
    array('size' => '50x80', 'weight' => 2),
    array('size' => '60x100', 'weight' => 2),
    array('size' => '70x110', 'weight' => 3),
    array('size' => '80x120', 'weight' => 3.5),
    array('size' => '90x130', 'weight' => 4),
    array('size' => '100x140', 'weight' => 4.5)
);

$bagSizes = $predefinedBagSizes;

// Fetch recommended weights from database for all bag sizes
$bagSizeToRecommendedWeightMap = array();
$fg_table_check = $conn->query("SHOW TABLES LIKE 'fg_entry'");
if ($fg_table_check && $fg_table_check->num_rows > 0) {
    // Get the most recent recommended_weight for each bag_size (latest entry for each size)
    $weightQuery = "SELECT bag_size, recommended_weight 
                    FROM fg_entry 
                    WHERE bag_size IS NOT NULL 
                      AND bag_size != '' 
                      AND recommended_weight IS NOT NULL 
                      AND recommended_weight > 0
                    ORDER BY created_at DESC";
    $weightResult = $conn->query($weightQuery);
    if ($weightResult) {
        while ($row = $weightResult->fetch_assoc()) {
            $bagSize = $row['bag_size'];
            // Only set if not already set (to get the most recent one due to DESC order)
            if (!isset($bagSizeToRecommendedWeightMap[$bagSize])) {
                $bagSizeToRecommendedWeightMap[$bagSize] = (float)$row['recommended_weight'];
            }
        }
    }
}

// Also add any custom bag sizes from fg_entry that are not in the predefined list
$predefinedSizesList = array_column($predefinedBagSizes, 'size');
if ($fg_table_check && $fg_table_check->num_rows > 0) {
    $customBagQuery = $conn->query("SELECT DISTINCT bag_size, recommended_weight FROM fg_entry WHERE bag_size IS NOT NULL AND bag_size != '' AND bag_size NOT IN ('" . implode("','", $predefinedSizesList) . "') ORDER BY bag_size ASC LIMIT 10");
    if ($customBagQuery) {
        while ($row = $customBagQuery->fetch_assoc()) {
            $bagSizes[] = [
                'size' => $row['bag_size'],
                'weight' => $row['recommended_weight']
            ];
        }
    }
}

// FG ID generation (shift-based reset like roll entry)
$current_hour = (int)date('H');
$shift_date = ($current_hour < 8) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');

// Check if fg_entry table exists and has fg_id column
$table_exists = $conn->query("SHOW TABLES LIKE 'fg_entry'")->num_rows > 0;
$next_fg_number = 1;

if ($table_exists) {
    // Check if fg_id column exists
    $column_check = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'fg_id'");
    if ($column_check && $column_check->num_rows > 0) {
        $last_fg = $conn->query("SELECT MAX(fg_id) as last_fg FROM fg_entry 
                                  WHERE DATE(date_time) = '$shift_date'");
        if ($last_fg && $last_fg->num_rows > 0) {
            $l = $last_fg->fetch_assoc();
            $last_fg_id = $l['last_fg'] ?? '';
            if (preg_match('/FG-(\d{8})-(\d{3})$/', $last_fg_id, $matches)) {
                $next_fg_number = intval($matches[2]) + 1;
            }
        }
    }
}

// Performance: Defer CNC batch loading - will load asynchronously after page render
$refToBatchMap = array();

// Performance: Defer bag size loading - will load asynchronously after page render
$refToBagSizeMap = array();

// Performance: Defer branded quantity loading - will load asynchronously after page render
$refToBrandedQtyMap = array();

// Load bag references from branding_entries (for bag FG entry)
$brandingExists = $conn->query("SHOW TABLES LIKE 'branding_entries'")->num_rows > 0;
$fgTableExists = $conn->query("SHOW TABLES LIKE 'fg_entry'")->num_rows > 0;

if ($brandingExists) {
    $hasRef = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'reference_number'")->num_rows > 0;
    $hasCnc = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'cnc_cutting_batch'")->num_rows > 0;
    $hasBagSize = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'bag_size'")->num_rows > 0;
    $hasPrintQty = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'print_qty'")->num_rows > 0;
    $hasIsDeleted = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'is_deleted'")->num_rows > 0;
    $hasCreatedAt = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'created_at'")->num_rows > 0;

    if ($hasRef) {
        // Exclude references already completed in fg_entry (if table/column exists)
        $fgRefFilter = "";
        if ($fgTableExists) {
            $fgHasRef = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'reference_number'")->num_rows > 0;
            $fgHasDeleted = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'is_deleted'")->num_rows > 0;
            if ($fgHasRef) {
                $fgRefFilter = "AND be.reference_number NOT IN (
                    SELECT reference_number FROM fg_entry 
                    WHERE reference_number IS NOT NULL AND reference_number <> '' " .
                    ($fgHasDeleted ? "AND (is_deleted = 0 OR is_deleted IS NULL)" : "") .
                ")";
            }
        }

        $cncCol = $hasCnc ? "MAX(be.cnc_cutting_batch) AS cnc_cutting_batch" : "NULL AS cnc_cutting_batch";
        $bagCol = $hasBagSize ? "MAX(be.bag_size) AS bag_size" : "NULL AS bag_size";
        $printCol = $hasPrintQty ? "SUM(be.print_qty) AS total_printed" : "0 AS total_printed";
        $orderCol = $hasCreatedAt ? "MAX(be.created_at)" : "MAX(be.reference_number)";
        $isDeletedFilter = $hasIsDeleted ? "AND (be.is_deleted = 0 OR be.is_deleted IS NULL)" : "";

        $brandingQuery = "
            SELECT 
                be.reference_number,
                {$cncCol},
                {$bagCol},
                {$printCol}
            FROM branding_entries be
            WHERE be.reference_number IS NOT NULL
              AND be.reference_number <> ''
              {$isDeletedFilter}
              {$fgRefFilter}
            GROUP BY be.reference_number
            ORDER BY {$orderCol} DESC
            LIMIT 200
        ";

        $bRes = $conn->query($brandingQuery);
        if ($bRes) {
            while ($row = $bRes->fetch_assoc()) {
                $ref = $row['reference_number'];
                if (!isset($refToBatchMap[$ref]) && !empty($row['cnc_cutting_batch'])) {
                    $refToBatchMap[$ref] = $row['cnc_cutting_batch'];
                }
                if (!empty($row['bag_size'])) {
                    if (!isset($refToBagSizeMap[$ref])) {
                        $refToBagSizeMap[$ref] = [];
                    }
                    $refToBagSizeMap[$ref][] = $row['bag_size'];
                }
                if (!isset($refToBrandedQtyMap[$ref])) {
                    $refToBrandedQtyMap[$ref] = (int)($row['total_printed'] ?? 0);
                }
            }
        }
    }
}

// Fetch roll references from qc_test_orders routed to FG (status approved)
$rollReferences = array();
$hasQcTable = $conn->query("SHOW TABLES LIKE 'qc_test_orders'")->num_rows > 0;
if ($hasQcTable) {
    $hasApprovedAt = $conn->query("SHOW COLUMNS FROM qc_test_orders LIKE 'approved_at'")->num_rows > 0;
    $approvedAtCol = $hasApprovedAt ? "MAX(qto.approved_at)" : "MAX(qto.updated_at)";

    $hasRollDest = $conn->query("SHOW COLUMNS FROM qc_test_orders LIKE 'roll_destination'")->num_rows > 0;
    $rollDestFilter = $hasRollDest ? "AND qto.roll_destination = 'fg_production'" : "";

    $hasStatus = $conn->query("SHOW COLUMNS FROM qc_test_orders LIKE 'status'")->num_rows > 0;
    $statusFilter = $hasStatus ? "AND qto.status = 'approved'" : "";

    $fgRefNotExists = "";
    if ($conn->query("SHOW TABLES LIKE 'fg_entry'")->num_rows > 0 &&
        $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'reference_number'")->num_rows > 0) {
        $fgHasDeleted = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'is_deleted'")->num_rows > 0;
        $fgRefNotExists = "AND qto.sample_reference_id NOT IN (
            SELECT reference_number FROM fg_entry 
            WHERE reference_number IS NOT NULL AND reference_number != '' " .
            ($fgHasDeleted ? "AND (is_deleted = 0 OR is_deleted IS NULL)" : "") .
        ")";
    }

    $rollRefQuery = $conn->query("
        SELECT DISTINCT 
            qto.sample_reference_id as reference_number,
            re.roll_size,
            re.material_type,
            re.total_weight,
            {$approvedAtCol} as last_approved_at
        FROM qc_test_orders qto
        LEFT JOIN roll_entry re ON qto.sample_reference_id = re.reference_number
        WHERE qto.sample_reference_id IS NOT NULL
          AND qto.sample_reference_id != ''
          AND qto.sample_reference_id NOT LIKE 'EXT-%'
          {$rollDestFilter}
          {$statusFilter}
          {$fgRefNotExists}
        GROUP BY qto.sample_reference_id, re.roll_size, re.material_type, re.total_weight
        ORDER BY last_approved_at DESC
        LIMIT 100
    ");
    if ($rollRefQuery) {
        while ($row = $rollRefQuery->fetch_assoc()) {
            $rollReferences[] = [
                'reference_number' => $row['reference_number'],
                'roll_size' => $row['roll_size'] ?? '',
                'material_type' => $row['material_type'] ?? '',
                'total_weight' => $row['total_weight'] ?? 0
            ];
        }
    }
}

// Performance: Defer bundle references loading - will load asynchronously after page render
$bundleReferences = array();

// Batch number will be auto-generated based on form fields
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>FG Entry</title>
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
  /* Align bag size buttons - 3 per row */
  #bagSizeButtonGroup { 
    display: flex; 
    flex-wrap: wrap; 
    gap: 5px; 
    width: 100%;
  }
  #bagSizeButtonGroup .btn {
    flex: 0 0 32%;
    box-sizing: border-box;
    min-width: 0;
    text-align: center;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  #bagSizeButtonGroup .btn.custom-bag-size-btn {
    flex: 0 0 100%;
    margin-top: 5px;
  }
  /* Align bag size buttons in a neat grid */
  #bagSizeGroup { display: grid !important; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; }
  #bagSizeGroup .btn { width: 100%; text-align: center; }
  /* Thickness button group */
  #thicknessGroup { display: flex; flex-wrap: wrap; gap: 8px; }
  #thicknessGroup .btn { min-width: 100px; text-align: center; }
</style>
</head>
<body>
<div class="container">
  
  <h1>FG Entry</h1>

  <?php if (isset($_GET['success']) && $_GET['success'] === 'fg_entry_saved'): ?>
    <div class="alert alert-success">
      ✅ FG Entry saved successfully! FG ID: <?php echo htmlspecialchars($_GET['fg_id'] ?? ''); ?>
      <?php if (isset($_GET['batch_number'])): ?>
        <br>Batch Number: <?php echo htmlspecialchars($_GET['batch_number']); ?>
      <?php endif; ?>
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
        } elseif ($_GET['error'] === 'invalid_fg_id') {
          echo "Invalid FG ID format";
        } else {
          echo "Unknown error occurred";
        }
      ?>
    </div>
  <?php endif; ?>

  <!-- Back to Dashboard Link -->
  <?php if (isset($_GET['success'])): ?>
    <div style="background:#d4edda;color:#155724;padding:12px;border-radius:6px;border:1px solid #c3e6cb;margin-bottom:15px;">
      ✅ <?php echo htmlspecialchars($_GET['success']); ?>
      <?php if (isset($_GET['fg_id'])): ?>
        <br><strong>FG ID: <?php echo htmlspecialchars($_GET['fg_id']); ?></strong>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div style="background:#f8d7da;color:#721c24;padding:12px;border-radius:6px;border:1px solid #f5c6cb;margin-bottom:15px;">
      ❌ Error: <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
  </div>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <form id="fgForm" method="post" action="../handlers/submit_fg_entry.php" onsubmit="return validateForm();" novalidate>

    <!-- FG ID -->
    <div class="form-group">
      <label>FG ID:</label>
      <input type="text" id="fgIdDisplay" value="<?php echo 'FG-' . date('Ymd') . '-' . str_pad($next_fg_number, 3, '0', STR_PAD_LEFT); ?>" readonly class="readonly">
      <input type="hidden" id="fg_id" name="fg_id" value="<?php echo 'FG-' . date('Ymd') . '-' . str_pad($next_fg_number, 3, '0', STR_PAD_LEFT); ?>">
    </div>

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

    <!-- Roll Entry Type (Individual or Bundle) - Shown only when Roll is selected -->
    <div class="form-group" id="rollEntryTypeGroup" style="display:none;">
      <label>Entry Type: <span style="color:red;">*</span></label>
      <div class="btn-group" style="display:flex; gap:10px; flex-wrap:wrap;">
        <button type="button" class="btn roll-entry-type-btn" onclick="selectRollEntryType('individual')" style="background:#e0e0e0;color:#333; border:2px solid #ccc;">
          <i class="fas fa-circle"></i> Individual Roll
        </button>
        <button type="button" class="btn roll-entry-type-btn" onclick="selectRollEntryType('bundle')" style="background:#e0e0e0;color:#333; border:2px solid #ccc;">
          <i class="fas fa-layer-group"></i> Bundle (All Rolls Together)
        </button>
      </div>
      <input type="hidden" id="roll_entry_type" name="roll_entry_type" value="">
      <small style="color:#6c757d; display:block; margin-top:8px;">
        <strong>Individual:</strong> Select one roll | <strong>Bundle:</strong> All rolls from same batch submitted together
      </small>
    </div>

    <!-- All other fields below this will be hidden until product/entry type is selected -->
    <div id="productFieldsContainer" style="display:none;">

    <!-- Reference Number (from Sheet Production or Roll) -->
    <div class="form-group">
      <label>Reference Number: <span style="color:red;">*</span></label>
      <select id="reference_number" name="reference_number" onchange="updateCNCBatchFromReference()" required>
        <option value="">Select Product Type First</option>
      </select>
      <small id="reference_hint" style="color:#6c757d; display:block; margin-top:5px;"></small>
      <div id="bundleInfo" style="display:none; background:#e3f2fd; padding:12px; border-radius:6px; margin-top:10px; border-left:4px solid #2196F3;">
        <strong style="color:#1976D2;"><i class="fas fa-info-circle"></i> Bundle Details:</strong>
        <div id="bundleRollList" style="margin-top:8px; color:#424242; font-size:14px;"></div>
      </div>
    </div>
    
    <script>
    // Reference to CNC Batch mapping (for bags)
    const refToBatchMap = <?php echo json_encode($refToBatchMap); ?>;
    const bagReferences = <?php echo json_encode(array_keys($refToBatchMap)); ?>;
    
    // Reference to Bag Size mapping (from branding_entries)
    const refToBagSizeMap = <?php echo json_encode($refToBagSizeMap); ?>;
    
    // Reference to Branded Quantity mapping (total bags produced in branding)
    const refToBrandedQtyMap = <?php echo json_encode($refToBrandedQtyMap); ?>;
    
    // Bag size to recommended weight mapping from database
    const bagSizeToRecommendedWeightFromDB = <?php echo json_encode($bagSizeToRecommendedWeightMap); ?>;
    
    // Roll references (for FG Production)
    const rollReferences = <?php echo json_encode($rollReferences); ?>;
    const bundleReferences = <?php echo json_encode($bundleReferences); ?>;
    
    // Reference to GSM mapping (from fiber_to_roll_entry)
    <?php
      $refToGsmMap = [];
      $gsmRes = $conn->query("SELECT reference_number, gsm, created_at FROM fiber_to_roll_entry WHERE reference_number IS NOT NULL ORDER BY created_at DESC");
      if ($gsmRes) {
          while ($row = $gsmRes->fetch_assoc()) {
              $ref = $row['reference_number'];
              if (!isset($refToGsmMap[$ref])) {
                  $refToGsmMap[$ref] = (float)$row['gsm'];
              }
          }
      }
    ?>
    const refToGsmMap = <?php echo json_encode($refToGsmMap); ?>;
    let currentGsm = null;
    let currentThickness = null;
    
    // Store all predefined bag sizes for reference
    const allBagSizes = [
      '2000mmX1500mm', '1200mmX950mm', '1250mmX1000mm', '1225mmX1000mm',
      '1300mmX1050mm', '1600mmX850mm', '1100mmX850mm', '1200mmX600mm',
      '1100mmX800mm', '1125mmX900mm', '1150mmX800mm', '1150mmX850mm',
      '1150mmX900mm', '1700mmX1250mm', '1050mmX800mm', '1075mmX850mm',
      '1030mmX700mm', '1000mmX800mm', '950mmX750mm', '950mmX500mm',
      '830mmX600mm', '300mmX299mm', '500mmX499mm', '700mmX700mm',
      '850mmX700mm', '1030mmX750mm', '1000mmX700mm'
    ];
    </script>

    <!-- CNC Cutting Batch Number (auto-populated from reference) -->
    <div class="form-group">
      <label>CNC Cutting Batch:</label>
      <input type="text" id="cnc_cutting_batch" name="cnc_cutting_batch" readonly style="background-color: #f0f0f0;" placeholder="Auto-filled when reference selected">
    </div>

    <!-- Hidden datetime -->
    <input type="hidden" id="dateTime" name="date_time">
    <input type="hidden" id="shift" name="shift">

    <!-- Shift in Charge -->
    <div class="form-group">
      <label>Shift in charge: <span style="color:red;">*</span></label>
      <input type="text" id="shift_in_charge" name="shift_in_charge" value="<?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'); ?>" placeholder="Enter Shift in Charge name" data-default="<?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'); ?>" required>
    </div>

    <!-- Project -->
    <div class="form-group">
      <label>Project:</label>
      <div class="btn-group" id="projectGroup">
        <!-- Projects will be loaded asynchronously -->
      </div>
      <small id="project_loading" style="display: block; color: #7f8c8d; font-size: 0.75em; margin-top: 2px;">Loading projects...</small>
      <input type="hidden" id="project_id" name="project_id" value="">
    </div>

    <!-- Roll Size (shown when Roll is selected) -->
    <div class="form-group" id="rollSizeFormGroup" style="display:none;">
      <label>Roll Size:</label>
      <div class="btn-group" id="rollSizeButtonGroup" style="display:flex; flex-wrap:wrap; gap:8px;">
        <button type="button" class="btn roll-size-btn" onclick="selectRollSizeFG('GEOCIL-50 (4X100 MTR)', this)">GEOCIL-50 (4X100MTR)</button>
        <button type="button" class="btn roll-size-btn" onclick="selectRollSizeFG('GEOCIL-60 (4X100 MTR)', this)">GEOCIL-60 (4X100MTR)</button>
        <button type="button" class="btn roll-size-btn" onclick="selectRollSizeFG('GEOCIL-70 (4X90 MTR)', this)">GEOCIL-70 (4X90 MTR)</button>
        <button type="button" class="btn roll-size-btn" onclick="selectRollSizeFG('GEOCIL-80 (4X75 MTR)', this)">GEOCIL-80 (4X75 MTR)</button>
        <button type="button" class="btn roll-size-btn" onclick="selectRollSizeFG('GEOCIL-90 (4X70 MTR)', this)">GEOCIL-90 (4X70 MTR)</button>
        <button type="button" class="btn roll-size-btn" onclick="selectRollSizeFG('GEOCIL-100 (4X60 MTR)', this)">GEOCIL-100 (4X60 MTR)</button>
        <button type="button" class="btn roll-size-btn" onclick="selectRollSizeFG('GEOCIL-110 (4X60 MTR)', this)">GEOCIL-110 (4X60 MTR)</button>
        <button type="button" class="btn roll-size-btn" onclick="selectRollSizeFG('GEOCIL-70 (4.06X35.5 MTR)', this)">GEOCIL-70 (4.06X35.5 MTR)</button>
        <button type="button" class="btn roll-size-btn" onclick="selectRollSizeFG('GEOCIL 70 (2X2 MTR)', this)">GEOCIL 70 (2X2 MTR)</button>
        <button type="button" class="btn roll-size-btn" onclick="selectRollSizeFG('GEOCIL-70 (4.5X35.5 MTR)', this)">GEOCIL-70 (4.5X35.5 MTR)</button>
        <button type="button" class="btn roll-size-btn" onclick="selectRollSizeFG('GEOCIL 100 (3.93X24 MTR)', this)">GEOCIL 100 (3.93X24 MTR)</button>
        <button type="button" class="btn roll-size-btn" onclick="selectRollSizeFG('GEOCIL 20 (100X4 MTR)', this)">GEOCIL 20 (100X4 MTR)</button>
        <button type="button" class="btn roll-size-btn" onclick="selectRollSizeFG('GEOCIL-70 (4.08X50.8 MTR)', this)">GEOCIL-70 (4.08X50.8 MTR)</button>
        <button type="button" class="btn roll-size-btn" onclick="selectRollSizeFG('GEOCIL-40  (4X100 MTR)', this)">GEOCIL-40  (4X100 MTR)</button>
        <button type="button" class="btn roll-size-btn" onclick="selectRollSizeFG('GEOCIL 70 (4.06X35.5 MTR)(White)', this)">GEOCIL 70 (4.06X35.5 MTR)(White)</button>
        <button type="button" class="btn roll-size-btn" onclick="selectRollSizeFG('Geocil-70 | 4.06x51 Mtr)', this)">Geocil-70 | 4.06x51 Mtr)</button>
      </div>
      <input type="hidden" id="fg_roll_size" name="roll_size" value="">
    </div>
    
    <!-- Hidden field to store bundle roll list -->
    <input type="hidden" id="bundle_roll_list" name="bundle_roll_list" value="">

    <!-- Bag size (CNC list buttons + Custom) -->
    <div class="form-group" id="bagSizeFormGroup">
      <label>Bag Size:</label>
      <small id="bagSizeHint" style="color:#2196F3; display:block; margin-bottom:8px; font-weight:500;">
        <i class="fas fa-info-circle"></i> Select a Reference Number first. Branded bags will show specific sizes, non-branded will show all sizes.
      </small>
      <div style="margin-bottom: 10px; max-height: 500px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 5px;">
        <div class="btn-group" id="bagSizeButtonGroup">
          <button type="button" class="btn" onclick="selectBagSize('2000mmX1500mm', null)">2000mmX1500mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1200mmX950mm', null)">1200mmX950mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1250mmX1000mm', null)">1250mmX1000mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1225mmX1000mm', null)">1225mmX1000mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1300mmX1050mm', null)">1300mmX1050mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1600mmX850mm', null)">1600mmX850mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1100mmX850mm', null)">1100mmX850mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1200mmX600mm', null)">1200mmX600mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1100mmX800mm', null)">1100mmX800mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1125mmX900mm', null)">1125mmX900mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1150mmX800mm', null)">1150mmX800mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1150mmX850mm', null)">1150mmX850mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1150mmX900mm', null)">1150mmX900mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1700mmX1250mm', null)">1700mmX1250mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1050mmX800mm', null)">1050mmX800mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1075mmX850mm', null)">1075mmX850mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1030mmX700mm', null)">1030mmX700mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1000mmX800mm', null)">1000mmX800mm</button>
          <button type="button" class="btn" onclick="selectBagSize('950mmX750mm', null)">950mmX750mm</button>
          <button type="button" class="btn" onclick="selectBagSize('950mmX500mm', null)">950mmX500mm</button>
          <button type="button" class="btn" onclick="selectBagSize('830mmX600mm', null)">830mmX600mm</button>
          <button type="button" class="btn" onclick="selectBagSize('300mmX299mm', null)">300mmX299mm</button>
          <button type="button" class="btn" onclick="selectBagSize('500mmX499mm', null)">500mmX499mm</button>
          <button type="button" class="btn" onclick="selectBagSize('700mmX700mm', null)">700mmX700mm</button>
          <button type="button" class="btn" onclick="selectBagSize('850mmX700mm', null)">850mmX700mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1030mmX750mm', null)">1030mmX750mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1000mmX700mm', null)">1000mmX700mm</button>
          <button type="button" class="btn custom-bag-size-btn" onclick="selectBagSize('custom', null)" style="background:#6c757d;color:#fff;">Custom (Enter manually)</button>
        </div>
      </div>
      <input type="text" id="bag_size_custom" placeholder="Enter custom bag size" style="margin-top: 10px; display: none;">
      <input type="hidden" id="bag_size" name="bag_size" value="" required>
    </div>

    <!-- Recommended weight (auto-filled from database based on bag size) - Only for bags -->
    <div class="form-group" id="recommendedWeightGroup" style="display:none;">
      <label>Recommended Weight (kg):</label>
      <input type="number" id="recommended_weight" name="recommended_weight" min="1" step="0.01" readonly style="background-color: #f0f0f0;" placeholder="Auto-filled from database based on bag size">
      <small style="color:#6c757d; display:block; margin-top:5px;">
        <i class="fas fa-info-circle"></i> Automatically fetched from database based on selected bag size
      </small>
    </div>

    <!-- Thickness selector (shown when size demands manual thickness choice) -->
    <div class="form-group" id="thicknessSection" style="display:none;">
      <label>Thickness (mm):</label>
      <div class="btn-group" id="thicknessGroup">
        <button type="button" class="btn" onclick="selectThickness(2.3)">2.3</button>
        <button type="button" class="btn" onclick="selectThickness(3.0)">3.0</button>
        <button type="button" class="btn" onclick="selectThickness(3.3)">3.3</button>
        <button type="button" class="btn" onclick="selectThickness(2.50)">2.50</button>
      </div>
      <input type="hidden" id="thickness_mm" name="thickness_mm" value="">
    </div>

    <!-- Measurement Type Selection (for rolls only) -->
    <div class="form-group" id="measurementTypeGroup" style="display:none;">
      <label>Measurement Type: <span style="color:red;">*</span></label>
      <div class="btn-group" style="display:flex; gap:10px; flex-wrap:wrap;">
        <button type="button" class="btn measurement-type-btn" onclick="selectMeasurementType('weight', this)" style="background:#e0e0e0;color:#333; border:2px solid #ccc;">
          <i class="fas fa-weight"></i> Weight (kg)
        </button>
        <button type="button" class="btn measurement-type-btn" onclick="selectMeasurementType('area', this)" style="background:#e0e0e0;color:#333; border:2px solid #ccc;">
          <i class="fas fa-ruler-combined"></i> Area (sqm)
        </button>
      </div>
      <input type="hidden" id="measurement_type" name="measurement_type" value="">
    </div>

    <!-- Total Weight (shown when weight is selected for rolls) -->
    <div class="form-group" id="actualWeightGroup" style="display:none;">
      <label>Total Weight (kg): <span style="color:red;">*</span></label>
      <input type="number" id="total_weight" name="total_weight" min="0.01" step="0.01">
      <small style="color:#6c757d; display:block; margin-top:5px;">
        <i class="fas fa-info-circle"></i> Enter the total weight manually (will be auto-filled from roll data if available, but you can edit it)
      </small>
    </div>

    <!-- Total Area (shown when area is selected) -->
    <div class="form-group" id="totalAreaGroup" style="display:none;">
      <label>Total Area (sqm): <span style="color:red;">*</span></label>
      <input type="number" id="total_area" name="total_area" min="0.01" step="0.01" placeholder="Enter total area in square meters">
      <small style="color:#6c757d; display:block; margin-top:5px;">
        <i class="fas fa-info-circle"></i> Enter the total area in square meters
      </small>
    </div>

    <!-- Actual weight for bags (manual input, always shown for bags) -->
    <div class="form-group" id="actualWeightBagGroup" style="display:none;">
      <label>Actual Weight (kg): <span style="color:red;">*</span></label>
      <input type="number" id="actual_weight_bag" name="actual_weight_bag" min="0.01" step="0.01" placeholder="Enter actual weight in kg" required>
      <small style="color:#6c757d; display:block; margin-top:5px;">
        <i class="fas fa-info-circle"></i> Enter the actual weight of the bags manually
      </small>
    </div>

    <!-- Quality checked -->
    <div class="form-group" id="qualityCheckedFormGroup">
      <label>Quality Checked (pcs): <span id="maxBrandedQtyLabel" style="color:#2196F3; font-weight:normal; font-size:13px;"></span></label>
      <input type="number" id="quality_checked" name="quality_checked" min="1" onchange="validateQualityChecked()" oninput="validateQualityChecked()">
      <small id="qualityCheckedHint" style="color:#6c757d; display:block; margin-top:5px;">
        <i class="fas fa-info-circle"></i> Enter the number of bags to be quality checked
      </small>
    </div>

    <!-- Passed quantity -->
    <div class="form-group" id="passedQtyFormGroup">
      <label>Passed Quantity (pcs): </label>
      <input type="number" id="passed_qty" name="passed_qty" min="0" onchange="calculateRejected()" oninput="calculateRejected()">
    </div>

    <!-- Rejected quantity (auto-calculated) -->
    <div class="form-group" id="rejectedQtyFormGroup">
      <label>Rejected Quantity (pcs): </label>
      <input type="number" id="rejected_qty" name="rejected_qty" min="0" readonly style="background-color: #f0f0f0;">
    </div>
    
    </div><!-- End productFieldsContainer -->

    <!-- Summary Section (Always Visible) -->
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
function updateTimeAndShift() {
  const now = new Date();
  const utc = now.getTime() + now.getTimezoneOffset()*60000;
  const dhaka = new Date(utc + 6*3600000);
  
  // Format for display
  document.getElementById("dateTimeDisplay").innerHTML =
    "Date & Time: " + dhaka.toLocaleDateString() + " " + dhaka.toLocaleTimeString();
  
  // Format for database (YYYY-MM-DD HH:MM:SS)
  const yyyy = dhaka.getFullYear();
  const mm = String(dhaka.getMonth()+1).padStart(2,'0');
  const dd = String(dhaka.getDate()).padStart(2,'0');
  const hh = String(dhaka.getHours()).padStart(2,'0');
  const min = String(dhaka.getMinutes()).padStart(2,'0');
  const ss = String(dhaka.getSeconds()).padStart(2,'0');
  document.getElementById("dateTime").value = `${yyyy}-${mm}-${dd} ${hh}:${min}:${ss}`;
  
  const h = dhaka.getHours();
  // Day shift: 8am-7:59pm (8-19), Night shift: 8pm-7:59am (20-7)
  const shift = (h >= 8 && h <= 19) ? "Day" : "Night";
  document.getElementById("shiftBanner").innerText = "Shift: " + shift;
  document.getElementById("shift").value = shift;
}
setInterval(updateTimeAndShift,1000); updateTimeAndShift();

// Recommended weight map (kg) by bag size
const bagSizeToRecommendedKg = {
  '2000mmX1500mm': 800,
  '1200mmX950mm': 250,
  '1250mmX1000mm': 250,
  '1225mmX1000mm': 250,
  '1300mmX1050mm': 200,
  '1600mmX850mm': 250,
  '1100mmX850mm': 250,
  '1200mmX600mm': 200,
  '1100mmX800mm': 200,
  '1125mmX900mm': 175,
  '1150mmX800mm': 200,
  '1150mmX850mm': 200,
  '1150mmX900mm': 175,
  '1700mmX1250mm': 500,
  '1050mmX800mm': 175,
  '1075mmX850mm': 175,
  '1030mmX700mm': 126,
  '1000mmX800mm': 125,
  '950mmX750mm': 125,
  '950mmX500mm': 125,
  '830mmX600mm': 100,
  '300mmX299mm': 35,
  '700mmX700mm': 450,
  '850mmX700mm': 75,
  '1030mmX750mm': 126,
  '1000mmX700mm': 125,
  // uncommon/typo variants preserved if needed
  '200mmx600mm': 200,
  '1050mmx800mm': 175,
  '830mmx600mm': 78
};

function selectProductType(type) {
  // Remove selected styling from all product type buttons
  const allBtns = document.querySelectorAll('.product-type-btn');
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
  document.getElementById('product_type').value = type;
  
  // Show/hide roll entry type selection and fields
  const rollEntryTypeGroup = document.getElementById('rollEntryTypeGroup');
  const productFieldsContainer = document.getElementById('productFieldsContainer');
  
  if (type === 'roll') {
    // For rolls, show entry type selection first
    if(rollEntryTypeGroup) rollEntryTypeGroup.style.display = 'block';
    if(productFieldsContainer) productFieldsContainer.style.display = 'none'; // Wait for entry type selection
  } else if (type === 'bag') {
    // For bags, show all fields immediately
    if(rollEntryTypeGroup) rollEntryTypeGroup.style.display = 'none';
    if(productFieldsContainer) productFieldsContainer.style.display = 'block';
    
    
    const bagSizeFormGroup = document.getElementById('bagSizeFormGroup');
    const recommendedWeightGroup = document.getElementById('recommendedWeightGroup');
    const actualWeightBagGroup = document.getElementById('actualWeightBagGroup');
    const qualityCheckedFormGroup = document.getElementById('qualityCheckedFormGroup');
    const passedQtyFormGroup = document.getElementById('passedQtyFormGroup');
    const rejectedQtyFormGroup = document.getElementById('rejectedQtyFormGroup');
    const cncGroup = document.getElementById('cnc_cutting_batch').closest('.form-group');
    
    if(bagSizeFormGroup) bagSizeFormGroup.style.display = 'block';
    if(actualWeightBagGroup) actualWeightBagGroup.style.display = 'block';
    if(qualityCheckedFormGroup) qualityCheckedFormGroup.style.display = 'block';
    if(passedQtyFormGroup) passedQtyFormGroup.style.display = 'block';
    if(rejectedQtyFormGroup) rejectedQtyFormGroup.style.display = 'block';
    if(cncGroup) cncGroup.style.display = 'block';
    
    // Hide roll-specific fields
    const rollSizeFormGroup = document.getElementById('rollSizeFormGroup');
    const measurementTypeGroup = document.getElementById('measurementTypeGroup');
    const actualWeightGroup = document.getElementById('actualWeightGroup');
    const totalAreaGroup = document.getElementById('totalAreaGroup');
    
    if(rollSizeFormGroup) rollSizeFormGroup.style.display = 'none';
    if(measurementTypeGroup) measurementTypeGroup.style.display = 'none';
    if(actualWeightGroup) actualWeightGroup.style.display = 'none';
    if(totalAreaGroup) totalAreaGroup.style.display = 'none';
    
    loadBagReferences();
  }
  
  updateSummary();
}

function selectRollEntryType(entryType) {
  // Remove selected styling from all entry type buttons
  const allBtns = document.querySelectorAll('.roll-entry-type-btn');
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
  document.getElementById('roll_entry_type').value = entryType;
  
  // Show product fields container now that entry type is selected
  const productFieldsContainer = document.getElementById('productFieldsContainer');
  if(productFieldsContainer) productFieldsContainer.style.display = 'block';
  
  // Update reference dropdown based on entry type
  const referenceSelect = document.getElementById('reference_number');
  const referenceHint = document.getElementById('reference_hint');
  referenceSelect.innerHTML = '<option value="">-- Select Reference --</option>';
  
  if (entryType === 'individual') {
    // Individual rolls
    rollReferences.forEach(roll => {
      const option = document.createElement('option');
      option.value = roll.reference_number;
      option.textContent = roll.reference_number;
      option.setAttribute('data-roll-size', roll.roll_size || 'N/A');
      option.setAttribute('data-material-type', roll.material_type || '');
      option.setAttribute('data-weight', roll.total_weight || '');
      option.setAttribute('data-is-bundle', 'false');
      referenceSelect.appendChild(option);
    });
    referenceHint.innerHTML = 'Showing <strong>individual rolls</strong> approved for FG';
    
  } else if (entryType === 'bundle') {
    // Bundle references
    console.log('🔍 Loading bundle references...');
    console.log('Bundle references data:', bundleReferences);
    console.log('Bundle count:', bundleReferences ? bundleReferences.length : 0);
    
    if (bundleReferences && bundleReferences.length > 0) {
      bundleReferences.forEach((bundle, index) => {
        console.log(`Adding bundle ${index + 1}:`, bundle);
        const option = document.createElement('option');
        option.value = bundle.base_reference;
        option.textContent = bundle.base_reference + ' (' + bundle.total_rolls + ' rolls)';
        option.setAttribute('data-roll-size', bundle.roll_size || 'N/A');
        option.setAttribute('data-material-type', bundle.material_type || '');
        option.setAttribute('data-weight', bundle.total_weight || '');
        option.setAttribute('data-roll-count', bundle.total_rolls);
        option.setAttribute('data-roll-list', bundle.roll_list || '');
        option.setAttribute('data-is-bundle', 'true');
        referenceSelect.appendChild(option);
      });
      referenceHint.innerHTML = 'Showing <strong>' + bundleReferences.length + ' bundle(s)</strong> (multiple rolls grouped together)';
      console.log('✅ Added ' + bundleReferences.length + ' bundles to dropdown');
    } else {
      referenceHint.innerHTML = '<span style="color:#e74c3c;">⚠️ No bundles available. All rolls may have been used or not all rolls in a batch are QC approved yet.</span>';
      console.log('❌ No bundle references found - bundleReferences is empty or null');
    }
  }
  
  // Show roll-specific fields
  const rollSizeFormGroup = document.getElementById('rollSizeFormGroup');
  if(rollSizeFormGroup) rollSizeFormGroup.style.display = 'block';
  
  // Show measurement type selection for rolls
  const measurementTypeGroup = document.getElementById('measurementTypeGroup');
  if(measurementTypeGroup) measurementTypeGroup.style.display = 'block';
  
    // Hide bag-specific fields
    const cncGroup = document.getElementById('cnc_cutting_batch').closest('.form-group');
    const bagSizeFormGroup = document.getElementById('bagSizeFormGroup');
    const thicknessSection = document.getElementById('thicknessSection');
    const qualityCheckedFormGroup = document.getElementById('qualityCheckedFormGroup');
    const passedQtyFormGroup = document.getElementById('passedQtyFormGroup');
    const rejectedQtyFormGroup = document.getElementById('rejectedQtyFormGroup');
    
    const bagSizeInput = document.getElementById('bag_size');
    const qualityCheckedInput = document.getElementById('quality_checked');
    const passedQtyInput = document.getElementById('passed_qty');
    const rejectedQtyInput = document.getElementById('rejected_qty');
    
    if(cncGroup) cncGroup.style.display = 'none';
    if(bagSizeFormGroup) bagSizeFormGroup.style.display = 'none';
    if(thicknessSection) thicknessSection.style.display = 'none';
    if(qualityCheckedFormGroup) qualityCheckedFormGroup.style.display = 'none';
    if(passedQtyFormGroup) passedQtyFormGroup.style.display = 'none';
    if(rejectedQtyFormGroup) rejectedQtyFormGroup.style.display = 'none';
    
    // Hide recommended weight for rolls
    const recommendedWeightGroup = document.getElementById('recommendedWeightGroup');
    if(recommendedWeightGroup) recommendedWeightGroup.style.display = 'none';
    
    // Remove required from bag-specific fields for rolls
    if(bagSizeInput) bagSizeInput.removeAttribute('required');
    if(qualityCheckedInput) qualityCheckedInput.removeAttribute('required');
    if(passedQtyInput) passedQtyInput.removeAttribute('required');
    if(rejectedQtyInput) rejectedQtyInput.removeAttribute('required');
    
    const recommendedWeightInput = document.getElementById('recommended_weight');
    if(recommendedWeightInput) recommendedWeightInput.removeAttribute('required');
  
  updateSummary();
}

function selectMeasurementType(type, btnElement) {
  // Remove selected class and reset styles from all buttons
  document.querySelectorAll('.measurement-type-btn').forEach(btn => {
    btn.classList.remove('selected');
    btn.style.background = '#e0e0e0';
    btn.style.color = '#333';
    btn.style.borderColor = '#ccc';
  });
  
  // Add selected class and apply selected styles to clicked button
  const targetBtn = btnElement || event.target;
  targetBtn.classList.add('selected');
  targetBtn.style.background = '#007bff';
  targetBtn.style.color = '#fff';
  targetBtn.style.borderColor = '#007bff';
  
  // Set hidden input
  document.getElementById('measurement_type').value = type;
  
  // Show/hide appropriate fields
  const actualWeightGroup = document.getElementById('actualWeightGroup');
  const totalAreaGroup = document.getElementById('totalAreaGroup');
  const totalWeightInput = document.getElementById('total_weight');
  const totalAreaInput = document.getElementById('total_area');
  
  if (type === 'weight') {
    if(actualWeightGroup) actualWeightGroup.style.display = 'block';
    if(totalAreaGroup) totalAreaGroup.style.display = 'none';
    if(totalWeightInput) totalWeightInput.setAttribute('required', 'required');
    if(totalAreaInput) totalAreaInput.removeAttribute('required');
    if(totalAreaInput) totalAreaInput.value = '';
  } else if (type === 'area') {
    if(actualWeightGroup) actualWeightGroup.style.display = 'none';
    if(totalAreaGroup) totalAreaGroup.style.display = 'block';
    if(totalWeightInput) totalWeightInput.removeAttribute('required');
    if(totalAreaInput) totalAreaInput.setAttribute('required', 'required');
    if(totalWeightInput) totalWeightInput.value = '';
  }
  
  updateSummary();
}

function loadBagReferences() {
  // Load bag references (from CNC)
  const referenceSelect = document.getElementById('reference_number');
  const referenceHint = document.getElementById('reference_hint');
  const actualWeightInput = document.getElementById('actual_weight');
  referenceSelect.innerHTML = '<option value="">-- Select Reference --</option>';
  
  bagReferences.forEach(ref => {
    const option = document.createElement('option');
    option.value = ref;
    option.textContent = ref;
    referenceSelect.appendChild(option);
  });
  referenceHint.textContent = 'Showing bags from Bag Production';
  
  // Note: For bags, we use actual_weight_bag field, not total_weight
  // This function is for bag references, so we don't need to update total_weight here
  
  // Reset all bag size buttons to be visible
  const bagSizeButtons = document.querySelectorAll('#bagSizeButtonGroup .btn');
  bagSizeButtons.forEach(btn => {
    btn.style.display = '';
    btn.classList.remove('selected');
  });
  
  // Reset bag size hint
  const bagSizeHint = document.getElementById('bagSizeHint');
  if (bagSizeHint) {
    bagSizeHint.innerHTML = '<i class="fas fa-info-circle"></i> Select a Reference Number first to see available bag sizes from branding';
    bagSizeHint.style.color = '#2196F3';
  }
  
  // Clear max branded quantity label
  const maxBrandedQtyLabel = document.getElementById('maxBrandedQtyLabel');
  if (maxBrandedQtyLabel) {
    maxBrandedQtyLabel.textContent = '';
  }
  
  // Reset quality checked hint
  const qualityCheckedHint = document.getElementById('qualityCheckedHint');
  if (qualityCheckedHint) {
    qualityCheckedHint.innerHTML = '<i class="fas fa-info-circle"></i> Enter the number of bags to be quality checked';
    qualityCheckedHint.style.color = '#6c757d';
  }
  
  // Hide roll-specific fields
  const rollSizeFormGroup = document.getElementById('rollSizeFormGroup');
  const rollEntryTypeGroup = document.getElementById('rollEntryTypeGroup');
  const bundleInfo = document.getElementById('bundleInfo');
  const measurementTypeGroup = document.getElementById('measurementTypeGroup');
  const actualWeightGroup = document.getElementById('actualWeightGroup');
  const totalAreaGroup = document.getElementById('totalAreaGroup');
  
  if(rollSizeFormGroup) rollSizeFormGroup.style.display = 'none';
  if(rollEntryTypeGroup) rollEntryTypeGroup.style.display = 'none';
  if(bundleInfo) bundleInfo.style.display = 'none';
  if(measurementTypeGroup) measurementTypeGroup.style.display = 'none';
  if(actualWeightGroup) actualWeightGroup.style.display = 'none';
  if(totalAreaGroup) totalAreaGroup.style.display = 'none';
  
  // Show bag-specific fields
  const cncGroup = document.getElementById('cnc_cutting_batch').closest('.form-group');
  const bagSizeFormGroup = document.getElementById('bagSizeFormGroup');
  const qualityCheckedFormGroup = document.getElementById('qualityCheckedFormGroup');
  const passedQtyFormGroup = document.getElementById('passedQtyFormGroup');
  const rejectedQtyFormGroup = document.getElementById('rejectedQtyFormGroup');
  
  const bagSizeInput = document.getElementById('bag_size');
  const qualityCheckedInput = document.getElementById('quality_checked');
  const passedQtyInput = document.getElementById('passed_qty');
  const rejectedQtyInput = document.getElementById('rejected_qty');
  
  // Remove max attribute from quality checked input
  if (qualityCheckedInput) {
    qualityCheckedInput.removeAttribute('max');
  }
  
  if(cncGroup) cncGroup.style.display = 'block';
  if(bagSizeFormGroup) bagSizeFormGroup.style.display = 'block';
  if(qualityCheckedFormGroup) qualityCheckedFormGroup.style.display = 'block';
  if(passedQtyFormGroup) passedQtyFormGroup.style.display = 'block';
  if(rejectedQtyFormGroup) rejectedQtyFormGroup.style.display = 'block';
  
  // Show recommended weight for bags
  const recommendedWeightGroup = document.getElementById('recommendedWeightGroup');
  if(recommendedWeightGroup) recommendedWeightGroup.style.display = 'block';
  
  // Add required back for bag fields
  if(bagSizeInput) bagSizeInput.setAttribute('required', 'required');
  if(qualityCheckedInput) qualityCheckedInput.setAttribute('required', 'required');
  if(passedQtyInput) passedQtyInput.setAttribute('required', 'required');
  if(rejectedQtyInput) rejectedQtyInput.setAttribute('required', 'required');
  
  const recommendedWeightInput = document.getElementById('recommended_weight');
  if(recommendedWeightInput) recommendedWeightInput.setAttribute('required', 'required');
  
  updateSummary();
}

function updateCNCBatchFromReference() {
  const referenceSelect = document.getElementById('reference_number');
  const cncBatchInput = document.getElementById('cnc_cutting_batch');
  const selectedRef = referenceSelect.value;
  const productType = document.getElementById('product_type').value;
  const selectedOption = referenceSelect.options[referenceSelect.selectedIndex];
  const bagSizeInput = document.getElementById('bag_size');
  const rollSizeInput = document.getElementById('roll_size');
  
  // Handle roll-specific fields
  const bundleInfo = document.getElementById('bundleInfo');
  const bundleRollList = document.getElementById('bundleRollList');
  const totalWeightInput = document.getElementById('total_weight');
  
  if (productType === 'roll' && selectedRef) {
    const rollSize = selectedOption.getAttribute('data-roll-size') || '';
    const rollWeight = selectedOption.getAttribute('data-weight') || '0';
    const isBundle = selectedOption.getAttribute('data-is-bundle') === 'true';
    const rollCount = selectedOption.getAttribute('data-roll-count') || '1';
    const rollList = selectedOption.getAttribute('data-roll-list') || '';
    if (rollSizeInput && rollSize && rollSize !== 'N/A') {
      rollSizeInput.value = rollSize;
    }
    
    // Auto-fill total weight from database
    if (totalWeightInput && rollWeight) {
      totalWeightInput.value = parseFloat(rollWeight).toFixed(2);
    }
    
    if (isBundle && rollList) {
      const rolls = rollList.split(', ');
      const totalWeight = rollWeight;
      bundleRollList.innerHTML = '<strong>Rolls in this bundle (' + rollCount + ' rolls):</strong><ul style="margin:5px 0 0 20px; padding:0;">' + 
        rolls.map(roll => '<li>' + roll + '</li>').join('') + 
        '</ul><strong>Total Bundle Weight: ' + parseFloat(totalWeight).toFixed(2) + ' kg</strong>';
      bundleInfo.style.display = 'block';
      
      // Store bundle roll list in hidden field
      document.getElementById('bundle_roll_list').value = rollList;
    } else {
      bundleInfo.style.display = 'none';
      document.getElementById('bundle_roll_list').value = '';
    }
    
    // Auto-select the matching roll size button
    if (rollSize && rollSize !== 'N/A' && rollSize !== '') {
      // Find and click the matching button
      const rollSizeButtons = document.querySelectorAll('.roll-size-btn');
      let buttonFound = false;
      
      rollSizeButtons.forEach(btn => {
        const btnText = btn.textContent.trim();
        if (btnText === rollSize) {
          // Trigger the button click to select it
          btn.click();
          buttonFound = true;
        }
      });
      
      if (!buttonFound) {
        console.log('Roll size not found in buttons:', rollSize);
      }
    }
  } else {
    // Not a roll product - hide bundle info
    if(bundleInfo) bundleInfo.style.display = 'none';
    
    // Show bag actual weight field (manual input)
    const actualWeightBagGroup = document.getElementById('actualWeightBagGroup');
    if (productType === 'bag' && actualWeightBagGroup) {
      actualWeightBagGroup.style.display = 'block';
    }
    
    // Hide roll actual weight field
    const actualWeightGroup = document.getElementById('actualWeightGroup');
    if (actualWeightGroup) {
      actualWeightGroup.style.display = 'none';
    }
  }
  
  // Only update CNC batch if product type is bag
  if (productType === 'bag' && selectedRef && refToBatchMap[selectedRef]) {
    cncBatchInput.value = refToBatchMap[selectedRef];
  } else {
    cncBatchInput.value = '';
  }
  
  // Filter bag sizes based on reference number (for bags)
  if (productType === 'bag') {
    if (selectedRef) {
      filterBagSizesByReference(selectedRef);
    // Auto-select bag size if exactly one available for this reference
    let availableBagSizes = refToBagSizeMap[selectedRef];
    if (availableBagSizes && !Array.isArray(availableBagSizes)) {
      availableBagSizes = [availableBagSizes];
    }
    if (availableBagSizes && availableBagSizes.length === 1 && bagSizeInput) {
      const chosenSize = availableBagSizes[0];
      bagSizeInput.value = chosenSize;
      // Trigger selectBagSize to also set recommended weight/thickness UI
      selectBagSize(chosenSize, null);
      // Highlight the matching button
      document.querySelectorAll('#bagSizeButtonGroup .btn').forEach(btn => {
        const txt = btn.textContent.trim();
        if (txt === chosenSize) {
          btn.classList.add('selected');
        } else {
          btn.classList.remove('selected');
        }
      });
    }
      
      // Update max branded quantity label
      updateMaxBrandedQtyLabel(selectedRef);
    } else {
      // No reference selected - show all bag sizes
      const bagSizeButtons = document.querySelectorAll('#bagSizeButtonGroup .btn');
      bagSizeButtons.forEach(btn => {
        btn.style.display = '';
      });
      const bagSizeHint = document.getElementById('bagSizeHint');
      if (bagSizeHint) {
        bagSizeHint.innerHTML = '<i class="fas fa-info-circle"></i> Select a Reference Number first. Branded bags will show specific sizes, non-branded will show all sizes.';
        bagSizeHint.style.color = '#2196F3';
      }
      
      // Clear max branded quantity label
      const maxBrandedQtyLabel = document.getElementById('maxBrandedQtyLabel');
      if (maxBrandedQtyLabel) {
        maxBrandedQtyLabel.textContent = '';
      }
      
      // Reset quality checked hint
      const qualityCheckedHint = document.getElementById('qualityCheckedHint');
      if (qualityCheckedHint) {
        qualityCheckedHint.innerHTML = '<i class="fas fa-info-circle"></i> Enter the number of bags to be quality checked';
        qualityCheckedHint.style.color = '#6c757d';
      }
    }
  }
  
  // Update current GSM from map
  if (selectedRef && refToGsmMap[selectedRef] !== undefined) {
    currentGsm = parseFloat(refToGsmMap[selectedRef]);
  } else {
    currentGsm = null;
  }
  
  updateSummary();
}

function updateMaxBrandedQtyLabel(referenceNumber) {
  const maxBrandedQtyLabel = document.getElementById('maxBrandedQtyLabel');
  const qualityCheckedInput = document.getElementById('quality_checked');
  
  if (!maxBrandedQtyLabel) return;
  
  if (refToBrandedQtyMap[referenceNumber]) {
    const maxBranded = refToBrandedQtyMap[referenceNumber];
    maxBrandedQtyLabel.innerHTML = '(Max: <strong>' + maxBranded + '</strong> bags branded)';
    maxBrandedQtyLabel.style.color = '#4caf50';
    
    // Set max attribute on input
    if (qualityCheckedInput) {
      qualityCheckedInput.setAttribute('max', maxBranded);
    }
    
    console.log('✅ Max branded quantity for ' + referenceNumber + ': ' + maxBranded + ' bags');
  } else {
    maxBrandedQtyLabel.innerHTML = '(No branding - can sell without branding)';
    maxBrandedQtyLabel.style.color = '#2196F3';
    
    // Remove max attribute
    if (qualityCheckedInput) {
      qualityCheckedInput.removeAttribute('max');
    }
    
    console.log('ℹ️ No branded quantity data for reference:', referenceNumber, '- products can be sold without branding');
  }
}

function filterBagSizesByReference(referenceNumber) {
  console.log('🔍 Filtering bag sizes for reference:', referenceNumber);
  
  // Get all bag size buttons
  const bagSizeButtons = document.querySelectorAll('#bagSizeButtonGroup .btn');
  const bagSizeInput = document.getElementById('bag_size');
  const bagSizeHint = document.getElementById('bagSizeHint');
  
  // Check if this reference has bag sizes in the map
    let availableBagSizes = refToBagSizeMap[referenceNumber];
    if (availableBagSizes && !Array.isArray(availableBagSizes)) {
      availableBagSizes = [availableBagSizes];
    }
  
  console.log('Available bag sizes from branding:', availableBagSizes);
  
  if (!availableBagSizes || availableBagSizes.length === 0) {
    // No bag sizes found for this reference - show all sizes (non-branded products)
    console.log('ℹ️ No branding data for this reference - showing all bag sizes for non-branded products');
    bagSizeButtons.forEach(btn => {
      btn.style.display = '';
    });
    if (bagSizeHint) {
      bagSizeHint.innerHTML = '<i class="fas fa-info-circle"></i> No branding data - showing all available sizes. Products can be sold without branding.';
      bagSizeHint.style.color = '#2196F3';
    }
    return;
  }
  
  // Clear any previously selected bag size
  bagSizeInput.value = '';
  document.getElementById('recommended_weight').value = '';
  
  // Hide/show buttons based on available sizes
  let visibleCount = 0;
  bagSizeButtons.forEach(btn => {
    const btnText = btn.textContent.trim();
    
    // Always show the "Custom" button
    if (btnText.toLowerCase().includes('custom')) {
      btn.style.display = '';
      return;
    }
    
    // Check if this button's size is in the available sizes
    const isAvailable = availableBagSizes.some(size => {
      return size.trim() === btnText;
    });
    
    if (isAvailable) {
      btn.style.display = '';
      btn.classList.remove('selected');
      visibleCount++;
    } else {
      btn.style.display = 'none';
      btn.classList.remove('selected');
    }
  });
  
  console.log('✅ Showing ' + visibleCount + ' bag size(s) for reference ' + referenceNumber);
  
  // Update hint message
  if (bagSizeHint) {
    if (visibleCount > 0) {
      bagSizeHint.innerHTML = '<i class="fas fa-check-circle"></i> Showing ' + visibleCount + ' bag size(s) branded under reference ' + referenceNumber;
      bagSizeHint.style.color = '#4caf50';
    } else {
      bagSizeHint.innerHTML = '<i class="fas fa-exclamation-triangle"></i> No matching bag sizes found. You can use Custom option.';
      bagSizeHint.style.color = '#ff9800';
      alert('No branded bag sizes found for reference ' + referenceNumber + '. You can use the Custom option to enter a bag size manually.');
    }
  }
}

function validateQualityChecked() {
  const qualityCheckedInput = document.getElementById('quality_checked');
  const qualityChecked = parseInt(qualityCheckedInput.value) || 0;
  const referenceNumber = document.getElementById('reference_number').value;
  const productType = document.getElementById('product_type').value;
  const qualityCheckedHint = document.getElementById('qualityCheckedHint');
  
  // Only validate for bags with branding data
  if (productType === 'bag' && referenceNumber) {
    if (refToBrandedQtyMap[referenceNumber]) {
      // Branding data exists - enforce limit
      const maxBranded = refToBrandedQtyMap[referenceNumber];
      
      if (qualityChecked > maxBranded) {
        qualityCheckedInput.value = maxBranded;
        if (qualityCheckedHint) {
          qualityCheckedHint.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <strong style="color:#e74c3c;">Quality Checked cannot exceed ' + maxBranded + ' bags (total branded for this reference)</strong>';
          qualityCheckedHint.style.color = '#e74c3c';
        }
        alert('Quality Checked cannot exceed ' + maxBranded + ' bags. This is the total number of bags branded under reference ' + referenceNumber);
      } else if (qualityChecked > 0) {
        if (qualityCheckedHint) {
          qualityCheckedHint.innerHTML = '<i class="fas fa-check-circle"></i> Valid - Maximum available: ' + maxBranded + ' branded bags';
          qualityCheckedHint.style.color = '#4caf50';
        }
      }
    } else {
      // No branding data - products can be sold without branding
      if (qualityChecked > 0 && qualityCheckedHint) {
        qualityCheckedHint.innerHTML = '<i class="fas fa-info-circle"></i> No branding data found - products can be sold without branding';
        qualityCheckedHint.style.color = '#2196F3';
      } else if (qualityCheckedHint) {
        qualityCheckedHint.innerHTML = '<i class="fas fa-info-circle"></i> Enter the number of bags to be quality checked';
        qualityCheckedHint.style.color = '#6c757d';
      }
    }
  } else {
    if (qualityCheckedHint) {
      qualityCheckedHint.innerHTML = '<i class="fas fa-info-circle"></i> Enter the number of bags to be quality checked';
      qualityCheckedHint.style.color = '#6c757d';
    }
  }
  
  // Calculate rejected after validation
  calculateRejected();
}

function calculateRejected() {
  const qualityChecked = parseInt(document.getElementById('quality_checked').value) || 0;
  const passedQty = parseInt(document.getElementById('passed_qty').value) || 0;
  const rejectedQty = qualityChecked - passedQty;
  
  // Set rejected quantity (minimum 0)
  document.getElementById('rejected_qty').value = Math.max(0, rejectedQty);
  
  updateSummary();
}

function selectProject(btn, projectId, projectName) {
  // Remove selected class from all buttons in project group
  const projectGroup = btn.closest('.btn-group');
  if (projectGroup) {
    projectGroup.querySelectorAll('.btn').forEach(b => b.classList.remove('selected'));
  }
  // Add selected class to clicked button
  btn.classList.add('selected');
  // Set hidden input value
  document.getElementById('project_id').value = projectId;
  updateSummary();
}

function selectRollSizeFG(size, btn) {
  // Remove selected class from all roll size buttons
  document.querySelectorAll('.roll-size-btn').forEach(b => b.classList.remove('selected'));
  
  // Add selected class to clicked button
  btn.classList.add('selected');
  
  // Set hidden input
  document.getElementById('fg_roll_size').value = size;
  
  updateSummary();
}

function selectBagSize(size, recommendedWeight) {
  const customInput = document.getElementById('bag_size_custom');
  const hiddenInput = document.getElementById('bag_size');
  const weightInput = document.getElementById('recommended_weight');
  
  // Remove selected class from all buttons in bag size group
  const bagSizeGroup = event.target.closest('.btn-group');
  if (bagSizeGroup) {
    bagSizeGroup.querySelectorAll('.btn').forEach(btn => btn.classList.remove('selected'));
  }
  
  // Add selected class to clicked button
  event.target.classList.add('selected');
  
  if (size === 'custom') {
    // Show custom input
    customInput.style.display = 'block';
    customInput.focus();
    hiddenInput.value = '';
    // Don't auto-fill weight for custom
    weightInput.value = '';
    // Hide thickness options for custom and reset
    const thicknessSection = document.getElementById('thicknessSection');
    const thicknessHidden = document.getElementById('thickness_mm');
    if (thicknessSection) thicknessSection.style.display = 'none';
    if (thicknessHidden) thicknessHidden.value = '';
    // Let user input the recommended weight manually for custom sizes
    weightInput.removeAttribute('readonly');
    weightInput.style.backgroundColor = '#fff';
    weightInput.focus();
    
    // Show recommended weight group and actual weight bag group
    const recommendedWeightGroup = document.getElementById('recommendedWeightGroup');
    if(recommendedWeightGroup) recommendedWeightGroup.style.display = 'block';
    const actualWeightBagGroup = document.getElementById('actualWeightBagGroup');
    if(actualWeightBagGroup) actualWeightBagGroup.style.display = 'block';
  } else {
    // Hide custom input and set value
    customInput.style.display = 'none';
    customInput.value = '';
    hiddenInput.value = size;
    
    // Some sizes are GSM-dependent and require reference to determine correct weight
    const gsmDependentSizes = ['1100mmX850mm','1000mmX800mm'];
    const refSelected = !!document.getElementById('reference_number').value;

    // Thickness sub-buttons visibility control for 1125mmX900mm
    const thicknessSection = document.getElementById('thicknessSection');
    const thicknessHidden = document.getElementById('thickness_mm');
    if (size === '1125mmX900mm') {
      thicknessSection.style.display = 'block';
      thicknessHidden.value = '';
      // clear previous selection state
      document.querySelectorAll('#thicknessGroup .btn').forEach(b=>b.classList.remove('selected'));
    } else {
      thicknessSection.style.display = 'none';
      thicknessHidden.value = '';
      document.querySelectorAll('#thicknessGroup .btn').forEach(b=>b.classList.remove('selected'));
    }

    if (gsmDependentSizes.includes(size) && !refSelected) {
      weightInput.value = '';
      alert('Please select a Reference Number first to calculate recommended weight for this bag size.');
    } else if (size === '1100mmX850mm' && currentGsm) {
      weightInput.value = currentGsm >= 450 ? 250 : 200;
    } else if (size === '1000mmX800mm' && currentGsm) {
      // 400 -> 125, 300 -> 120 (fallback 125 for >=400)
      if (currentGsm >= 400) weightInput.value = 125; else if (currentGsm >= 300) weightInput.value = 120; else weightInput.value = bagSizeToRecommendedKg[size] || '';
    } else if (size === '1125mmX900mm') {
      // Require thickness selection first; don't set until user chooses
      weightInput.value = '';
    } else {
      // First try to get from database mapping
      if (bagSizeToRecommendedWeightFromDB && bagSizeToRecommendedWeightFromDB.hasOwnProperty(size)) {
        weightInput.value = bagSizeToRecommendedWeightFromDB[size];
      } else if (bagSizeToRecommendedKg.hasOwnProperty(size)) {
        // Fallback to predefined mapping
        weightInput.value = bagSizeToRecommendedKg[size];
      } else if (recommendedWeight !== null && recommendedWeight !== undefined) {
        weightInput.value = recommendedWeight;
      } else {
        // leave as is if unknown
      }
    }
    
    // Show recommended weight group
    const recommendedWeightGroup = document.getElementById('recommendedWeightGroup');
    if(recommendedWeightGroup) recommendedWeightGroup.style.display = 'block';
    
    // Show actual weight bag group (after recommended weight)
    const actualWeightBagGroup = document.getElementById('actualWeightBagGroup');
    if(actualWeightBagGroup) actualWeightBagGroup.style.display = 'block';
    
    // Make recommended weight readonly after setting value
    if(weightInput) {
      weightInput.setAttribute('readonly', 'readonly');
      weightInput.style.backgroundColor = '#f0f0f0';
    }
  }
  
  updateSummary();
}


// Listen to custom bag size input
document.addEventListener('DOMContentLoaded', function() {
  const customInput = document.getElementById('bag_size_custom');
  if (customInput) {
    customInput.addEventListener('input', function() {
      document.getElementById('bag_size').value = this.value;
      updateSummary();
    });
  }
  // If thickness is changed via buttons, weight will be recalculated for specific size
});

function selectThickness(thickness){
  // highlight selection
  document.querySelectorAll('#thicknessGroup .btn').forEach(b=>b.classList.remove('selected'));
  const btn = event.target; btn.classList.add('selected');
  document.getElementById('thickness_mm').value = thickness;
  // Only applicable for 1125mmX900mm rule
  const size = document.getElementById('bag_size').value;
  if (size === '1125mmX900mm'){
    const weightInput = document.getElementById('recommended_weight');
    weightInput.value = (parseFloat(thickness) === 2.5) ? 200 : 300;
    updateSummary();
  }
}

function updateBatchNumberDisplay() {
  const shiftInCharge = document.getElementById("shift_in_charge").value || '';
  const bagSize = document.getElementById("bag_size").value || '';
  
  if (shiftInCharge && bagSize) {
    const now = new Date();
    const utc = now.getTime() + now.getTimezoneOffset()*60000;
    const dhaka = new Date(utc + 6*3600000);
    const hour = dhaka.getHours();
    let shiftDate = new Date(dhaka);
    if (hour < 8) { shiftDate.setDate(shiftDate.getDate() - 1); }
    const year = String(shiftDate.getFullYear()).slice(-2);
    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = monthNames[shiftDate.getMonth()];
    const day = String(shiftDate.getDate()).padStart(2, '0');
    const dateStr = year + month + day;
    const fgId = document.getElementById('fg_id').value || '1';
    const fgNumber = fgId.split('-').pop() || '1';
    const batchPreview = shiftInCharge.substring(0,3).toUpperCase() + bagSize.substring(0,3).toUpperCase() + dateStr + fgNumber;
    document.getElementById("batchNumberDisplay").value = batchPreview;
    document.getElementById("batchNumber").value = batchPreview;
  } else {
    document.getElementById("batchNumberDisplay").value = "Auto-generated on submit";
    document.getElementById("batchNumber").value = "";
  }
}

// Generate batch number on form submission
function validateForm(){
  try {
    console.log("Validating form...");
    
    // Validate shift in charge field - prevent '0' from being submitted
    const shiftInChargeField = document.getElementById("shift_in_charge");
    if (!shiftInChargeField) {
      console.error("shift_in_charge field not found");
      alert("Error: Shift in charge field not found. Please refresh the page.");
      return false;
    }
    
    let shiftInChargeValue = shiftInChargeField.value.trim();
    
    // Check for empty, '0', or any invalid value
    if (!shiftInChargeValue || 
        shiftInChargeValue === '0' || 
        shiftInChargeValue === '' ||
        shiftInChargeValue === '0.0') {
      // Use session value as fallback
      const sessionValue = shiftInChargeField.getAttribute('data-default') || 
                           shiftInChargeField.defaultValue || 
                           shiftInChargeField.getAttribute('value') ||
                           'User';
      shiftInChargeField.value = sessionValue;
      shiftInChargeValue = sessionValue;
    }
    
    // Final check - if still '0', force to 'User'
    if (shiftInChargeValue === '0' || shiftInChargeValue === '') {
      shiftInChargeField.value = 'User';
    }
    
    const projectIdField = document.getElementById("project_id");
    if(!projectIdField || !projectIdField.value){
      alert("Select a project.");
      return false;
    }
    
    const productType = document.getElementById("product_type").value;
    const rollEntryType = document.getElementById("roll_entry_type").value;
    
    // Validate product type selection
    if(!productType){
      alert("Please select a product type (Roll or Bag).");
      return false;
    }
    
    // Validate roll entry type if Roll is selected
    if(productType === 'roll' && !rollEntryType){
      alert("Please select entry type (Individual or Bundle).");
      return false;
    }
    
    // Validate reference number (required for all products)
    const referenceNumberField = document.getElementById("reference_number");
    if(!referenceNumberField || !referenceNumberField.value){
      alert("Please select a Reference Number. This is required for delivery tracking.");
      return false;
    }
    
    // Validate bag size only for bags
    if(productType === 'bag') {
      const bagSizeField = document.getElementById("bag_size");
      if(!bagSizeField || !bagSizeField.value){
        alert("Please select a bag size.");
        return false;
      }
      
      const actualWeightBag = document.getElementById("actual_weight_bag");
      if(!actualWeightBag || !actualWeightBag.value || parseFloat(actualWeightBag.value) <= 0) {
        alert("Please enter a valid actual weight for bags.");
        return false;
      }
    }
    
    // Validate roll size for rolls
    if(productType === 'roll') {
      const rollSizeField = document.getElementById("fg_roll_size");
      if(!rollSizeField || !rollSizeField.value){
        alert("Please select a roll size.");
        return false;
      }
      
      // Validate measurement type for rolls
      const measurementTypeField = document.getElementById("measurement_type");
      if(!measurementTypeField || !measurementTypeField.value) {
        alert("Please select a measurement type (Weight or Area).");
        return false;
      }
      
      const measurementType = measurementTypeField.value;
      
      if(measurementType === 'weight') {
        const totalWeight = document.getElementById("total_weight");
        if(!totalWeight || !totalWeight.value || parseFloat(totalWeight.value) <= 0) {
          alert("Please enter a valid total weight.");
          return false;
        }
      } else if(measurementType === 'area') {
        const totalArea = document.getElementById("total_area");
        if(!totalArea || !totalArea.value || parseFloat(totalArea.value) <= 0) {
          alert("Please enter a valid total area.");
          return false;
        }
      }
    }
    
    console.log("Form validation passed, submitting...");
    return true;
  } catch (error) {
    console.error("Validation error:", error);
    alert("An error occurred during validation: " + error.message);
    return false;
  }
}

function updateSummary() {
  const shiftInCharge = document.getElementById("shift_in_charge").value;
  const productType = document.getElementById("product_type").value;
  const rollEntryType = document.getElementById("roll_entry_type").value;
  const referenceNumber = document.getElementById("reference_number").value;
  
  console.log('📊 Updating summary - Product Type:', productType, 'Entry Type:', rollEntryType);
  const cncCuttingBatch = document.getElementById("cnc_cutting_batch").value;
  const projectId = document.getElementById("project_id").value;
  const projectName = document.querySelector('#project_id').closest('.form-group').querySelector('.btn.selected') ? 
                       document.querySelector('#project_id').closest('.form-group').querySelector('.btn.selected').textContent : '';
  const bagSize = document.getElementById("bag_size").value;
  const rollSize = document.getElementById("fg_roll_size").value;
  const recommendedWeight = document.getElementById("recommended_weight").value;
  // For rolls: use total_weight, for bags: use actual_weight_bag
  const totalWeight = document.getElementById("total_weight") ? document.getElementById("total_weight").value : '';
  const actualWeightBag = document.getElementById("actual_weight_bag") ? document.getElementById("actual_weight_bag").value : '';
  const qualityChecked = document.getElementById("quality_checked").value;
  const passedQty = document.getElementById("passed_qty").value;
  const rejectedQty = document.getElementById("rejected_qty").value;
  
  // Only show summary if at least some basic info is available
  if (shiftInCharge) {
    let summary = `Shift in Charge: ${shiftInCharge}`;
    
    // Add product type and entry type (show even if not all fields filled)
    if (productType === 'roll') {
      summary += ` | Type: Roll`;
      if (rollEntryType) {
        summary += ` (${rollEntryType === 'individual' ? 'Individual' : 'Bundle'})`;
      }
    } else if (productType === 'bag') {
      summary += ` | Type: Bag`;
    }
    
    if (projectId && projectName) {
      summary += ` | Project: ${projectName}`;
    }
    
    if (referenceNumber) summary += ` | Reference: ${referenceNumber}`;
    if (cncCuttingBatch) summary += ` | CNC Batch: ${cncCuttingBatch}`;
    
    // Show roll size for rolls, bag size for bags
    if (productType === 'roll' && rollSize) {
      summary += ` | Roll Size: ${rollSize}`;
    } else if (bagSize) {
      summary += ` | Bag Size: ${bagSize}`;
    }
    
    if (recommendedWeight) summary += ` | Rec Weight: ${recommendedWeight} kg`;
    
    // Show weight based on product type
    if (productType === 'roll' && totalWeight) {
      summary += ` | Total Weight: ${totalWeight} kg`;
    } else if (productType === 'bag' && actualWeightBag) {
      summary += ` | Actual Weight: ${actualWeightBag} kg`;
    }
    
    // Only show quality fields for bags
    if (productType === 'bag') {
      if (qualityChecked) summary += ` | QC: ${qualityChecked} pieces`;
      if (passedQty) summary += ` | Passed: ${passedQty}`;
      if (rejectedQty) summary += ` | Rejected: ${rejectedQty}`;
    }

    document.getElementById("summaryBox").innerText = summary;
    document.getElementById("summary").value = summary;
  } else {
    document.getElementById("summaryBox").innerText = "";
    document.getElementById("summary").value = "";
  }
}

function clearForm() {
  document.getElementById("fgForm").reset();
  document.getElementById("summaryBox").innerText = "";
  document.getElementById("summary").value = "";
  
  // Clear all button selections including product type
  document.querySelectorAll('.btn').forEach(btn => btn.classList.remove('selected'));
  document.querySelectorAll('.product-type-btn').forEach(btn => {
    btn.style.background = '#e0e0e0';
    btn.style.color = '#333';
    btn.style.border = '2px solid #ccc';
  });
  document.querySelectorAll('.roll-entry-type-btn').forEach(btn => {
    btn.style.background = '#e0e0e0';
    btn.style.color = '#333';
    btn.style.border = '2px solid #ccc';
  });
  
  document.getElementById('product_type').value = '';
  document.getElementById('roll_entry_type').value = '';
  document.getElementById('project_id').value = '';
  document.getElementById('bag_size').value = '';
  
  // Reset bag size hint
  const bagSizeHint = document.getElementById('bagSizeHint');
  if (bagSizeHint) {
    bagSizeHint.innerHTML = '<i class="fas fa-info-circle"></i> Select a Reference Number first to see available bag sizes from branding';
    bagSizeHint.style.color = '#2196F3';
  }
  
  // Reset all bag size buttons to visible
  const bagSizeButtons = document.querySelectorAll('#bagSizeButtonGroup .btn');
  bagSizeButtons.forEach(btn => {
    btn.style.display = '';
  });
  
  // Clear max branded quantity label
  const maxBrandedQtyLabel = document.getElementById('maxBrandedQtyLabel');
  if (maxBrandedQtyLabel) {
    maxBrandedQtyLabel.textContent = '';
  }
  
  // Reset quality checked hint
  const qualityCheckedHint = document.getElementById('qualityCheckedHint');
  if (qualityCheckedHint) {
    qualityCheckedHint.innerHTML = '<i class="fas fa-info-circle"></i> Enter the number of bags to be quality checked';
    qualityCheckedHint.style.color = '#6c757d';
  }
  
  // Remove max attribute from quality checked input
  const qualityCheckedInput = document.getElementById('quality_checked');
  if (qualityCheckedInput) {
    qualityCheckedInput.removeAttribute('max');
  }
  
  // Hide product fields container
  const productFieldsContainer = document.getElementById('productFieldsContainer');
  if(productFieldsContainer) productFieldsContainer.style.display = 'none';
  
  // Hide entry type group
  const rollEntryTypeGroup = document.getElementById('rollEntryTypeGroup');
  if(rollEntryTypeGroup) rollEntryTypeGroup.style.display = 'none';
  
  // Hide custom bag size input
  const customInput = document.getElementById('bag_size_custom');
  if (customInput) {
    customInput.style.display = 'none';
    customInput.value = '';
  }
  
  // Hide bundle info
  const bundleInfo = document.getElementById('bundleInfo');
  if(bundleInfo) bundleInfo.style.display = 'none';
  
  updateTimeAndShift();
}


// Add event listeners for summary updates
document.addEventListener('DOMContentLoaded', function() {
  // Ensure shift_in_charge field never has '0' as value
  const shiftInChargeField = document.getElementById('shift_in_charge');
  if (shiftInChargeField) {
    const currentValue = shiftInChargeField.value.trim();
    if (!currentValue || currentValue === '0' || currentValue === '') {
      const defaultValue = shiftInChargeField.getAttribute('data-default') || 
                          shiftInChargeField.getAttribute('value') || 
                          'User';
      shiftInChargeField.value = defaultValue;
    }
  }
  
  const inputs = ['reference_number', 'cnc_cutting_batch', 'recommended_weight', 'actual_weight', 'quality_checked', 'passed_qty', 'rejected_qty'];
  inputs.forEach(function(inputId) {
    const element = document.getElementById(inputId);
    if (element) {
      element.addEventListener('input', updateSummary);
      element.addEventListener('change', updateSummary);
    }
  });
  
  // Debug: Log form submission attempts
  const form = document.getElementById('fgForm');
  if (form) {
    form.addEventListener('submit', function(e) {
      console.log('Form submit event triggered');
      const isValid = validateForm();
      console.log('Validation result:', isValid);
      if (!isValid) {
        console.log('Validation failed, preventing submission');
      } else {
        console.log('Validation passed, form will submit');
      }
    });
  }
  
  // Initial summary update on page load
  updateSummary();
  
  // Load form data asynchronously after page renders for instant page load
  loadFormData();
});

// Load form dropdowns asynchronously to avoid blocking page render
function loadFormData() {
  // Load projects
  fetch('api/get_projects.php')
    .then(response => response.json())
    .then(data => {
      if (data.success && data.projects) {
        const projectGroup = document.getElementById('projectGroup');
        const loadingText = document.getElementById('project_loading');
        if (loadingText) loadingText.style.display = 'none';
        
        data.projects.forEach((project, index) => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn' + (index === 0 ? ' selected' : '');
          btn.textContent = project.project_name;
          btn.onclick = function() { selectProject(this, project.id, project.project_name); };
          projectGroup.appendChild(btn);
        });
        if (projectGroup && data.projects.length > 0) {
          document.getElementById('project_id').value = data.projects[0].id || '';
        }
      }
    })
    .catch(err => {
      console.error('Error loading projects:', err);
      const loadingText = document.getElementById('project_loading');
      if (loadingText) loadingText.textContent = 'Failed to load projects';
    });
  
  // Note: FG-specific data (references, batches, etc.) will be loaded on-demand
  // when user selects product type to avoid loading unnecessary data
}
</script>
</body>
</html>

