<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
require_once 'security_config.php';

// Session & security checks
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

// Role-based access control for Production module
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_PRODUCTION, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>Access Denied</h2>
        <p>You do not have permission to access the Production module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

// DB connection
$conn = SecurityConfig::getConnection();
if (!$conn) {
    die("Error: Unable to connect to database. Please check your database configuration.");
}

// Performance: Defer project loading - will load asynchronously after page render
$projects = [];

// Performance: Optimize reference query with LIMIT and defer loading
// Fetch reference numbers from roll_received that:
// 1. Haven't been used in CNC entry yet
// NOTE: All QC tests (QC Test Order, Water Permeability, Characteristics, Sun Test, UV Test) 
//       will be done AFTER roll entry submission. No tests are required before CNC entry.
$referenceNumbers = [];
$refQuery = "SELECT DISTINCT r.reference_number 
             FROM roll_received r 
             WHERE r.reference_number IS NOT NULL 
             AND NOT EXISTS (
                 SELECT 1 FROM cnc_entries c 
                 WHERE c.reference_number = r.reference_number
             )
             ORDER BY r.created_at DESC
             LIMIT 100";
// Performance: Defer reference loading - will load asynchronously after page render
$referenceNumbers = [];

// Generate Entry ID (auto-increment based on date and sequence)
$current_date = date('Y-m-d');
$next_cnc_number = 1;

// Check if cnc_entries table exists and has cnc_id column
$table_check = $conn->query("SHOW TABLES LIKE 'cnc_entries'");
if ($table_check && $table_check->num_rows > 0) {
    // Check if cnc_id column exists
    $column_check = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'cnc_id'");
    if ($column_check && $column_check->num_rows > 0) {
        // Use prepared statement for date query
        $lastCncStmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(cnc_id, -3) AS UNSIGNED)) as last_num FROM cnc_entries WHERE DATE(date_time) = ?");
        if ($lastCncStmt) {
            $lastCncStmt->bind_param("s", $current_date);
            $lastCncStmt->execute();
            $last_cnc = $lastCncStmt->get_result();
            if ($last_cnc && $last_cnc->num_rows > 0) {
                $row = $last_cnc->fetch_assoc();
                if ($row['last_num']) {
                    $next_cnc_number = $row['last_num'] + 1;
                }
            }
            $lastCncStmt->close();
        }
    }
}

$entry_id = "CNC" . date('Ymd') . str_pad($next_cnc_number, 3, '0', STR_PAD_LEFT);

// Calculate next cutting number (resets at 8 AM daily)
// Get current time in Dhaka timezone
$dhaka_time = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
$current_hour = (int)$dhaka_time->format('H');
$today_8am = clone $dhaka_time;
$today_8am->setTime(8, 0, 0);

// If current time is before 8 AM, use yesterday's 8 AM as the start
if ($current_hour < 8) {
    $shift_start = clone $today_8am;
    $shift_start->modify('-1 day');
} else {
    $shift_start = $today_8am;
}

$shift_start_str = $shift_start->format('Y-m-d H:i:s');

// Get the last cutting number for current shift (from 8 AM to 7:59 AM next day)
// Format: CW-01, CW-02, etc. (resets at 8 AM daily)
$next_cutting_number = 1;
if ($table_check && $table_check->num_rows > 0) {
    // Check if cnc_cutting_batch column exists
    $batch_column_check = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'cnc_cutting_batch'");
    if ($batch_column_check && $batch_column_check->num_rows > 0) {
        // Look for batches in CW-XX format within the current shift period
        $cutting_query = "SELECT MAX(CAST(SUBSTRING_INDEX(cnc_cutting_batch, '-', -1) AS UNSIGNED)) as last_cutting 
                          FROM cnc_entries 
                          WHERE date_time >= '$shift_start_str' 
                          AND cnc_cutting_batch IS NOT NULL 
                          AND cnc_cutting_batch LIKE 'CW-%'";
        $cutting_result = $conn->query($cutting_query);
        if ($cutting_result && $cutting_result->num_rows > 0) {
            $cutting_row = $cutting_result->fetch_assoc();
            if ($cutting_row['last_cutting']) {
                $next_cutting_number = $cutting_row['last_cutting'] + 1;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CNC Entry</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:0; color:#2c3e50; }
  .container { max-width:100%; margin:0; background:#fff; border-radius:0; padding:25px 80px; box-shadow:none;}
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
  .btn-group { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
  .btn-group .btn {
    padding: 10px 16px;
    font-size: 14px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    background-color: #f8f9fa;
  }
  .btn-group .btn:hover { background-color: #ccc; }
  .btn-group .btn.selected { background-color: #3498db; color: white; }
  .btn-bag-size {
    padding: 8px 12px;
    font-size: 13px;
    border: 1px solid #ddd;
    border-radius: 5px;
    cursor: pointer;
    background-color: #f8f9fa;
    transition: all 0.2s;
    white-space: nowrap;
    flex: 0 0 32%;
    box-sizing: border-box;
    min-width: 0;
  }
  .btn-bag-size.custom-bag-size-btn {
    flex: 0 0 100%;
    margin-top: 5px;
  }
  .btn-bag-size:hover {
    background-color: #007bff;
    color: white;
    border-color: #007bff;
  }
  .btn-bag-size.selected {
    background-color: #28a745;
    color: white;
    border-color: #28a745;
    font-weight: bold;
  }
  /* Custom Warning Popup Styles - Modern Red Design */
  .popup-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    z-index: 10000;
    align-items: center;
    justify-content: center;
    animation: fadeIn 0.2s ease-out;
  }
  .popup-overlay.show {
    display: flex;
  }
  @keyframes fadeIn {
    from {
      opacity: 0;
    }
    to {
      opacity: 1;
    }
  }
  .warning-popup {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 20px 60px rgba(220, 53, 69, 0.3), 0 0 0 1px rgba(220, 53, 69, 0.1);
    max-width: 480px;
    width: 90%;
    overflow: hidden;
    animation: slideUp 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    transform-origin: center;
  }
  @keyframes slideUp {
    from {
      opacity: 0;
      transform: translateY(30px) scale(0.95);
    }
    to {
      opacity: 1;
      transform: translateY(0) scale(1);
    }
  }
  .warning-popup-content {
    display: flex;
    align-items: flex-start;
    padding: 28px 24px;
    position: relative;
  }
  .warning-bar {
    width: 5px;
    background: linear-gradient(180deg, #dc3545 0%, #c82333 100%);
    border-radius: 3px 0 0 3px;
    margin-right: 20px;
    flex-shrink: 0;
    box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);
  }
  .warning-icon {
    width: 48px;
    height: 48px;
    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 18px;
    flex-shrink: 0;
    box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4);
  }
  .warning-icon span {
    color: #fff;
    font-size: 28px;
    font-weight: 700;
    line-height: 1;
  }
  .warning-text {
    flex: 1;
    padding-top: 2px;
  }
  .warning-title {
    font-size: 20px;
    font-weight: 700;
    color: #1a1a1a;
    margin: 0 0 8px 0;
    letter-spacing: -0.3px;
  }
  .warning-message {
    font-size: 15px;
    color: #4a4a4a;
    margin: 0;
    line-height: 1.6;
    font-weight: 500;
  }
  .popup-actions {
    padding: 20px 24px;
    background: linear-gradient(to bottom, #fafafa 0%, #f5f5f5 100%);
    display: flex;
    justify-content: center;
    border-top: 1px solid #e8e8e8;
  }
  .popup-btn {
    padding: 12px 36px;
    border: none;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    letter-spacing: 0.3px;
  }
  .popup-btn-ok {
    background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
    color: #fff;
    min-width: 120px;
  }
  .popup-btn-ok:hover {
    background: linear-gradient(135deg, #c82333 0%, #bd2130 100%);
    box-shadow: 0 4px 16px rgba(220, 53, 69, 0.4);
    transform: translateY(-1px);
  }
  .popup-btn-ok:active {
    transform: translateY(0);
    box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);
  }
  /* Make sure dropdown is visible and overlays properly */
  #reference_dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 9999;
    display: none;
    background: #fff;
    border: 1px solid #d0d0d0;
    border-radius: 6px;
    max-height: 280px;
    overflow: auto;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12);
  }
  /* No matches found message */
  #reference_dropdown .no-matches {
    padding: 15px;
    text-align: center;
    color: #999;
    font-style: italic;
    border-bottom: 1px solid #eee;
  }
</style>
</head>
<body>
<div class="container">
  
  <h1>CNC Machine Entry</h1>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success" style="background: #d4edda; color: #155724; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
      CNC Entry saved successfully! Entry ID: <strong><?php echo isset($_GET['entry_id']) ? htmlspecialchars($_GET['entry_id']) : 'N/A'; ?></strong>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger" style="background: #f8d7da; color: #721c24; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #f5c6cb;">
      âŒ Error: <?php echo htmlspecialchars($_GET['error']); ?>
    </div>
  <?php endif; ?>

  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>

  <!-- Custom Warning Popup -->
  <div id="warningPopup" class="popup-overlay">
    <div class="warning-popup">
      <div class="warning-popup-content">
        <div class="warning-bar"></div>
        <div class="warning-icon">
          <span>!</span>
        </div>
        <div class="warning-text">
          <h3 class="warning-title">Warning</h3>
          <p class="warning-message" id="warningMessage"></p>
        </div>
      </div>
      <div class="popup-actions">
        <button type="button" class="popup-btn popup-btn-ok" id="warningPopupOk" onclick="closeWarningPopup()">OK</button>
      </div>
    </div>
  </div>

  <form id="cncForm" method="post" action="../handlers/submit_cnc_entry.php" onsubmit="return validateForm();" onkeydown="if(event.key === 'Enter' && event.target && event.target.hasAttribute('data-ref-index')) { event.preventDefault(); return false; }">

    <!-- Entry ID (renamed from CNC ID) -->
    <div class="form-group">
      <label>Entry ID: </label>
      <input type="text" value="<?php echo htmlspecialchars($entry_id); ?>" readonly class="readonly">
      <input type="hidden" name="cnc_id" value="<?php echo htmlspecialchars($entry_id); ?>">
    </div>

    <!-- CNC Machine ID -->
    <div class="form-group">
      <label>CNC Machine ID:</label>
      <div class="btn-group" id="cncMachineGroup">
        <button type="button" class="btn cnc-machine-btn" data-value="CNC-01" onclick="selectCNCMachine(this,'cncMachineGroup'); return false;">CNC-01</button>
        <button type="button" class="btn cnc-machine-btn" data-value="CNC-02" onclick="selectCNCMachine(this,'cncMachineGroup'); return false;">CNC-02</button>
        <button type="button" class="btn cnc-machine-btn" data-value="custom" onclick="selectCNCMachine(this,'cncMachineGroup'); return false;">Custom</button>
      </div>
      <input type="text" id="cnc_machine_custom" placeholder="Enter CNC Machine ID manually" style="margin-top: 8px; display: none;">
      <input type="hidden" id="cnc_machine_id" name="cnc_machine_id">
    </div>

    <!-- Reference Number -->
    <div class="form-group">
      <label>Reference Number: </label>
      <div style="display:flex; gap:10px; align-items:flex-start;">
        <div style="flex:1; position:relative;">
          <div style="position:relative;">
            <i class="fas fa-search" style="position:absolute; left:15px; top:13px; color:#6c757d; z-index:100; pointer-events:none; font-size:15px;"></i>
            <input type="text" id="reference_search" placeholder="Please select CNC Machine ID first..." 
                   style="padding:10px 12px 10px 42px; border:1px solid #ccc; border-radius:6px; width:100%; margin-bottom:10px; box-sizing:border-box; background-color:#f5f5f5;"
                   disabled>
          </div>
          <div id="reference_dropdown">
            <!-- References will be loaded dynamically -->
            <div id="no_matches_message" class="no-matches" style="display: none;">No matches found</div>
          </div>
        </div>
        <button type="button" id="add_reference_btn" onclick="addCNCReference()" style="padding:10px 20px; background:#95a5a6; color:#fff; border:none; border-radius:6px; cursor:not-allowed; font-weight:600; white-space:nowrap; opacity:0.6;" disabled>
          <i class="fas fa-plus"></i> Add
        </button>
      </div>
      <div id="selected_references" style="margin-top:10px; min-height:30px;">
        <!-- Selected references will appear here -->
      </div>
      <small id="ref_loading" style="display: block; color: #7f8c8d; font-size: 0.75em; margin-top: 2px;">Please select a CNC Machine ID first to load references.</small>
      <small id="roll_count_info" style="display: block; color: #e74c3c; font-size: 0.75em; margin-top: 5px; font-weight:600;"></small>
      <small style="display: block; color: #7f8c8d; font-size: 0.75em; margin-top: 5px;">Limit: CNC-01/CNC-02: Max 4 rolls total | Custom: Max 100 rolls total (includes bundles and single references)</small>
      <input type="hidden" id="reference_number" name="reference_number" value="" required style="max-length: none;">
      <input type="hidden" id="reference_quantities" name="reference_quantities" value="">
    </div>


    <!-- CNC Cutting Batch (Auto-generated in CW-XX format, resets at 8 AM daily) -->
    <div class="form-group">
      <label>CNC Cutting Batch Number: </label>
      <input type="text" id="cnc_cutting_batch_display" readonly class="readonly" value="CW-<?php echo str_pad($next_cutting_number, 2, '0', STR_PAD_LEFT); ?>">
      <input type="hidden" id="cnc_cutting_batch" name="cnc_cutting_batch" value="CW-<?php echo str_pad($next_cutting_number, 2, '0', STR_PAD_LEFT); ?>">
      <input type="hidden" id="cutting_number" value="<?php echo $next_cutting_number; ?>">
      <small style="display: block; color: #7f8c8d; font-size: 0.75em; margin-top: 5px;">Format: CW-XX (resets at 8 AM daily, increments from 8 AM to 7:59 AM next day)</small>
    </div>

    <!-- Hidden datetime + shift -->
    <input type="hidden" id="dateTime" name="date_time">
    <input type="hidden" id="shift" name="shift">

    <!-- Reporter -->
    <div class="form-group">
      <label>Reporter:</label>
      <input type="text" value="<?php echo htmlspecialchars($_SESSION['username']); ?>" readonly class="readonly">
      <input type="hidden" name="reporter_id" value="<?php echo $_SESSION['user_id']; ?>">
    </div>

    <!-- Project -->
<div class="form-group">
  <label>Project:</label>
  <div class="btn-group" id="projectGroup">
    <!-- Projects will be loaded asynchronously -->
  </div>
  <small id="project_loading" style="display: block; color: #7f8c8d; font-size: 0.75em; margin-top: 2px;">Loading projects...</small>
  <input type="hidden" id="project_id" name="project_id">
</div>

    <!-- Cutting Roll Quantity (Auto-calculated from individual roll quantities) -->
    <div class="form-group">
      <label>Total Cutting Roll Quantity (Auto-calculated):</label>
      <input type="number" step="1" id="cutting_roll_quantity" name="cutting_roll_quantity" required min="0" readonly class="readonly" placeholder="Will be calculated from roll quantities below" style="background:#e8f5e9; font-weight:bold; cursor:not-allowed;">
      <small id="roll_quantity_help" style="display: block; color: #7f8c8d; font-size: 0.75em; margin-top: 5px;">This field is automatically calculated from the cutting quantities entered for each roll below (1 roll = max 36 pieces)</small>
      <small id="max_cutting_quantity_info" style="display: block; color: #27ae60; font-weight: 600; margin-top: 5px; font-size: 0.75em;"></small>
      <small id="cutting_quantity_warning" style="display: block; color: #e74c3c; font-weight: 600; margin-top: 5px; font-size: 0.75em;"></small>
    </div>

    <!-- Bag Size with button system and custom input -->
    <div class="form-group">
      <label>Bag Size:</label>
      <div style="margin-bottom: 10px; max-height: 500px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 5px;">
        <div class="btn-group" id="bagSizeGroup" style="display: flex; flex-wrap: wrap; gap: 5px; width: 100%;">
          <button type="button" class="btn-bag-size" data-bag-size="2000mmX1500mm" onclick="selectBagSize('2000mmX1500mm'); return false;">2000mmX1500mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1200mmX950mm" onclick="selectBagSize('1200mmX950mm'); return false;">1200mmX950mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1250mmX1000mm" onclick="selectBagSize('1250mmX1000mm'); return false;">1250mmX1000mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1225mmX1000mm" onclick="selectBagSize('1225mmX1000mm'); return false;">1225mmX1000mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1300mmX1050mm" onclick="selectBagSize('1300mmX1050mm'); return false;">1300mmX1050mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1600mmX850mm" onclick="selectBagSize('1600mmX850mm'); return false;">1600mmX850mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1100mmX850mm" onclick="selectBagSize('1100mmX850mm'); return false;">1100mmX850mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1200mmX600mm" onclick="selectBagSize('1200mmX600mm'); return false;">1200mmX600mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1100mmX800mm" onclick="selectBagSize('1100mmX800mm'); return false;">1100mmX800mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1125mmX900mm" onclick="selectBagSize('1125mmX900mm'); return false;">1125mmX900mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1150mmX800mm" onclick="selectBagSize('1150mmX800mm'); return false;">1150mmX800mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1150mmX850mm" onclick="selectBagSize('1150mmX850mm'); return false;">1150mmX850mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1150mmX900mm" onclick="selectBagSize('1150mmX900mm'); return false;">1150mmX900mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1700mmX1250mm" onclick="selectBagSize('1700mmX1250mm'); return false;">1700mmX1250mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1050mmX800mm" onclick="selectBagSize('1050mmX800mm'); return false;">1050mmX800mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1075mmX850mm" onclick="selectBagSize('1075mmX850mm'); return false;">1075mmX850mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1030mmX700mm" onclick="selectBagSize('1030mmX700mm'); return false;">1030mmX700mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1000mmX800mm" onclick="selectBagSize('1000mmX800mm'); return false;">1000mmX800mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="950mmX750mm" onclick="selectBagSize('950mmX750mm'); return false;">950mmX750mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="950mmX500mm" onclick="selectBagSize('950mmX500mm'); return false;">950mmX500mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="830mmX600mm" onclick="selectBagSize('830mmX600mm'); return false;">830mmX600mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="300mmX299mm" onclick="selectBagSize('300mmX299mm'); return false;">300mmX299mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="500mmX499mm" onclick="selectBagSize('500mmX499mm'); return false;">500mmX499mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="700mmX700mm" onclick="selectBagSize('700mmX700mm'); return false;">700mmX700mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="850mmX700mm" onclick="selectBagSize('850mmX700mm'); return false;">850mmX700mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1030mmX750mm" onclick="selectBagSize('1030mmX750mm'); return false;">1030mmX750mm</button>
          <button type="button" class="btn-bag-size" data-bag-size="1000mmX700mm" onclick="selectBagSize('1000mmX700mm'); return false;">1000mmX700mm</button>
          <button type="button" class="btn-bag-size custom-bag-size-btn" data-bag-size="custom" onclick="selectBagSize('custom'); return false;" style="background: #6c757d; color: white;">Custom (Enter manually)</button>
        </div>
      </div>
      <input type="text" id="bag_size_custom" placeholder="Enter custom bag size" style="margin-top: 8px; display: none; width: 100%; padding: 8px;">
      <input type="hidden" id="bag_size" name="bag_size">
    </div>

    <!-- GSM Selection (shown dynamically if multiple GSM options) -->
    <div class="form-group" id="gsmSection" style="display:none;">
      <label>GSM:</label>
      <div class="btn-group" id="gsmGroup"></div>
      <input type="hidden" id="gsm" name="gsm">
    </div>

    <!-- Thickness Selection (shown dynamically if multiple thickness options) -->
    <div class="form-group" id="thicknessSection" style="display:none;">
      <label>Thickness (mm):</label>
      <div class="btn-group" id="thicknessGroup"></div>
      <input type="hidden" id="thickness" name="thickness">
    </div>

    <!-- Summary Section -->
    <div class="form-group">
      <div id="summaryBox" class="summary-info"></div>
      <input type="hidden" id="summary" name="summary">
    </div>

    <div class="actions">
      <button type="submit" class="submit-btn">Submit</button>
      <button type="button" class="clear-btn" onclick="clearForm()">Clear</button>
    </div>
  </form>
</div>

<script>
// ============================================================================
// CRITICAL: These functions MUST be defined FIRST before any HTML tries to use them
// ============================================================================

// Store pending machine change
var pendingMachineChange = null;

// CNC Machine selection function - MUST be at top for onclick handlers
function selectCNCMachine(btn, groupId){
  try {
    console.log('selectCNCMachine called with:', btn, groupId);
    
    if (!btn || !groupId) {
      console.error('selectCNCMachine: Invalid parameters');
      return;
    }
    
  const customInput = document.getElementById('cnc_machine_custom');
  const hiddenInput = document.getElementById('cnc_machine_id');
  
  // Get current machine selection before change
  const currentlySelectedBtn = document.querySelector(`#${groupId} .btn.selected`);
  const currentMachineValue = hiddenInput ? hiddenInput.value : '';
  const currentCustomValue = customInput ? customInput.value.trim() : '';
  
  // Determine if currently on Custom machine
  const isCurrentlyCustom = (currentlySelectedBtn && currentlySelectedBtn.dataset.value === 'custom') || 
                            (currentCustomValue !== '' && (currentMachineValue === '' || currentMachineValue === currentCustomValue));
  
    // Get value from data-value attribute or dataset
    const newMachineValue = btn.getAttribute('data-value') || btn.dataset.value;
    console.log('New machine value:', newMachineValue);
  
  // Check if switching from Custom to CNC-01/CNC-02
  // NOTE: This warning will NOT show when switching TO Custom (Custom allows 100 rolls)
  // It only shows when switching FROM Custom TO CNC-01/CNC-02
  if (isCurrentlyCustom && (newMachineValue === 'CNC-01' || newMachineValue === 'CNC-02')) {
      // Safely get current roll count
      let currentRollCount = 0;
      if (typeof getCurrentTotalRollCount === 'function') {
        try {
          currentRollCount = getCurrentTotalRollCount();
        } catch (e) {
          console.error('Error getting current roll count:', e);
        }
      }
    
    // If current roll count exceeds the limit for CNC-01/CNC-02 (4 rolls)
    if (currentRollCount > 4) {
      // Store the pending change
      pendingMachineChange = {
        btn: btn,
        groupId: groupId
      };
      
      // Show warning popup (only for CNC-01/CNC-02, not for Custom)
        if (typeof showWarningPopup === 'function') {
      showWarningPopup(`Warning: You can't add more than 4 rolls in ${newMachineValue}`);
        } else {
          alert(`Warning: You can't add more than 4 rolls in ${newMachineValue}`);
        }
      return; // Don't apply change yet
    }
  }
  
  // Apply the selection change directly if no warning needed
  // This includes switching TO Custom (which allows 100 rolls, so no warning needed)
  applyMachineSelection(btn, groupId);
  } catch (error) {
    console.error('Error in selectCNCMachine:', error);
    alert('An error occurred while selecting CNC Machine: ' + error.message);
  }
}
// Also attach to window for global access
window.selectCNCMachine = selectCNCMachine;

// Global variable to store auto/manual mode preference
window.autoQuantityMode = null; // null = not set, true = auto (36 per roll), false = manual

function applyMachineSelection(btn, groupId) {
  const customInput = document.getElementById('cnc_machine_custom');
  const hiddenInput = document.getElementById('cnc_machine_id');
  
  document.querySelectorAll(`#${groupId} .btn`).forEach(b=>b.classList.remove('selected'));
  btn.classList.add('selected');
  
  if (btn.dataset.value === 'custom') {
    if (customInput) {
      customInput.style.display = 'block';
      customInput.required = true;
      if (hiddenInput) {
        hiddenInput.value = '';
      }
    }
  } else {
    if (customInput) {
      customInput.style.display = 'none';
      customInput.required = false;
      customInput.value = '';
    }
    if (hiddenInput) {
      hiddenInput.value = btn.dataset.value;
    }
  }
  
  // Show dialog to ask about auto/manual quantity mode
  // Only show if mode is not already set (first time selecting machine)
  if (window.autoQuantityMode === null) {
    showQuantityModeDialog();
  } else {
    // Mode already set, proceed with normal flow
    finalizeMachineSelection();
  }
}

function showQuantityModeDialog() {
  // Create dialog HTML
  const dialogHTML = `
    <div id="quantityModeDialog" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;">
      <div style="background: white; border-radius: 12px; padding: 30px; max-width: 500px; width: 90%; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
        <h3 style="margin: 0 0 20px 0; color: #333; font-size: 22px; font-weight: 600;">Quantity Input Mode</h3>
        <p style="margin: 0 0 25px 0; color: #666; font-size: 15px; line-height: 1.6;">
          Do you want to have each roll as 36 quantities automatically?<br><br>
          <strong>Yes:</strong> All references will automatically use 36 pieces per roll. No manual input needed.<br>
          <strong>No:</strong> You can manually enter the cutting quantity for each roll.
        </p>
        <div style="display: flex; gap: 12px; justify-content: flex-end;">
          <button id="quantityModeNo" style="padding: 12px 28px; background: #6c757d; color: white; border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; transition: background 0.2s;">
            No (Manual)
          </button>
          <button id="quantityModeYes" style="padding: 12px 28px; background: #28a745; color: white; border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; transition: background 0.2s;">
            Yes (Auto)
          </button>
        </div>
      </div>
    </div>
  `;
  
  // Remove existing dialog if any
  const existingDialog = document.getElementById('quantityModeDialog');
  if (existingDialog) {
    existingDialog.remove();
  }
  
  // Add dialog to page
  document.body.insertAdjacentHTML('beforeend', dialogHTML);
  
  // Add event listeners
  document.getElementById('quantityModeYes').addEventListener('click', function() {
    window.autoQuantityMode = true;
    closeQuantityModeDialog();
    finalizeMachineSelection();
  });
  
  document.getElementById('quantityModeNo').addEventListener('click', function() {
    window.autoQuantityMode = false;
    closeQuantityModeDialog();
    finalizeMachineSelection();
  });
  
  // Add hover effects
  document.getElementById('quantityModeYes').addEventListener('mouseenter', function() {
    this.style.background = '#218838';
  });
  document.getElementById('quantityModeYes').addEventListener('mouseleave', function() {
    this.style.background = '#28a745';
  });
  document.getElementById('quantityModeNo').addEventListener('mouseenter', function() {
    this.style.background = '#5a6268';
  });
  document.getElementById('quantityModeNo').addEventListener('mouseleave', function() {
    this.style.background = '#6c757d';
  });
}

function closeQuantityModeDialog() {
  const dialog = document.getElementById('quantityModeDialog');
  if (dialog) {
    dialog.remove();
  }
}

function finalizeMachineSelection() {
  // Enable reference search and Add button when machine is selected
  enableReferenceFields();
  
  // Update roll count info and filter references
  if (typeof updateRollCountInfo === 'function') {
    updateRollCountInfo();
  }
  if (typeof filterCNCReferences === 'function') {
    filterCNCReferences();
  }
  
  // Update max cutting quantity when machine is selected (in case references are already selected)
  if (typeof updateMaxCuttingQuantity === 'function') {
    updateMaxCuttingQuantity();
  }
  
  // Refresh selected references display to show/hide inputs based on mode
  if (typeof updateSelectedCNCReferences === 'function') {
    updateSelectedCNCReferences();
  }
  
  updateSummary();
}

// Bag Size selection function - required for bag size button onclick handlers
function selectBagSize(value) {
  try {
    const customInput = document.getElementById('bag_size_custom');
    const hiddenInput = document.getElementById('bag_size');
    const gsmSection = document.getElementById('gsmSection');
    const thicknessSection = document.getElementById('thicknessSection');
    if (!hiddenInput) return;

    document.querySelectorAll('#bagSizeGroup .btn-bag-size').forEach(function(b) {
      b.classList.remove('selected');
      if ((b.getAttribute('data-bag-size') || b.dataset.bagSize) === value) {
        b.classList.add('selected');
      }
    });

    if (value === 'custom') {
      if (customInput) {
        customInput.style.display = 'block';
        customInput.required = true;
        customInput.value = '';
      }
      hiddenInput.value = '';
      if (gsmSection) gsmSection.style.display = 'none';
      if (thicknessSection) thicknessSection.style.display = 'none';
    } else {
      if (customInput) {
        customInput.style.display = 'none';
        customInput.required = false;
        customInput.value = '';
      }
      hiddenInput.value = value;
      if (typeof fetchBagOptions === 'function') {
        fetchBagOptions(value);
      }
    }
    if (typeof updateSummary === 'function') updateSummary();
  } catch (e) {
    console.error('selectBagSize error:', e);
    alert('An error occurred while selecting bag size: ' + (e.message || 'Unknown error'));
  }
}
window.selectBagSize = selectBagSize;

function showWarningPopup(message, useHtml = false) {
  const popup = document.getElementById('warningPopup');
  const messageEl = document.getElementById('warningMessage');
  if (popup && messageEl) {
    if (useHtml) {
      messageEl.innerHTML = message;
    } else {
    messageEl.textContent = message;
    }
    popup.classList.add('show');
  }
}

function closeWarningPopup() {
  const popup = document.getElementById('warningPopup');
  if (popup) {
    popup.classList.remove('show');
    // Don't apply the pending change - user clicked OK to acknowledge, but we cancel the machine switch
    pendingMachineChange = null;
  }
}

function generateCuttingBatch() {
  // Generate cutting batch in CW-XX format (e.g., CW-01, CW-02)
  // ONE batch number per entry - generated once and reused for all references
  // Resets at 8 AM daily (from 8 AM to 7:59 AM next day)
  
  // Only generate if batch is not already set (to keep same batch for all references in one entry)
  const currentBatch = document.getElementById('cnc_cutting_batch').value;
  if (currentBatch && currentBatch.trim() !== '') {
    // Batch already exists, don't regenerate
    return;
  }
  
  const cuttingNumber = parseInt(document.getElementById('cutting_number').value) || 1;
  
  // Format cutting number with leading zero (01, 02, etc.)
  const cuttingNumFormatted = String(cuttingNumber).padStart(2, '0');
  
  // Generate batch: CW-XX format
  const cuttingBatch = `CW-${cuttingNumFormatted}`;
  
  document.getElementById('cnc_cutting_batch_display').value = cuttingBatch;
  document.getElementById('cnc_cutting_batch').value = cuttingBatch;
  updateSummary();
}

// Duplicate removed - function is already defined at the top of the script

function fetchBagOptions(bagSize) {
  fetch(`api/fetch_bag_options.php?bag_size=${encodeURIComponent(bagSize)}`)
    .then(response => response.json())
    .then(data => {
      const gsmSection = document.getElementById('gsmSection');
      const gsmGroup = document.getElementById('gsmGroup');
      const gsmHidden = document.getElementById('gsm');
      
      // Clear previous selections
      gsmGroup.innerHTML = '';
      gsmHidden.value = '';
      
      if (data.gsmList && data.gsmList.length > 1) {
        // Multiple GSM options - show selection
        gsmSection.style.display = 'block';
        data.gsmList.forEach(gsm => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn';
          btn.textContent = gsm;
          btn.onclick = function() { selectGSM(gsm, bagSize); };
          gsmGroup.appendChild(btn);
        });
      } else if (data.gsmList && data.gsmList.length === 1) {
        // Only one GSM - auto-select and check thickness
        gsmSection.style.display = 'none';
        gsmHidden.value = data.gsmList[0];
        fetchThicknessOptions(bagSize, data.gsmList[0]);
      } else {
        // No GSM data - hide both sections
        gsmSection.style.display = 'none';
        document.getElementById('thicknessSection').style.display = 'none';
      }
    })
    .catch(error => {});
}

function selectGSM(gsm, bagSize) {
  document.getElementById('gsm').value = gsm;
  
  // Highlight selected GSM button
  document.querySelectorAll('#gsmGroup .btn').forEach(btn => {
    btn.classList.remove('selected');
    if (btn.textContent == gsm) {
      btn.classList.add('selected');
    }
  });
  
  // Fetch thickness options for this bag size + GSM
  fetchThicknessOptions(bagSize, gsm);
  updateSummary();
}

function fetchThicknessOptions(bagSize, gsm) {
  fetch(`api/fetch_thickness_options.php?bag_size=${encodeURIComponent(bagSize)}&gsm=${gsm}`)
    .then(response => response.json())
    .then(data => {
      const thicknessSection = document.getElementById('thicknessSection');
      const thicknessGroup = document.getElementById('thicknessGroup');
      const thicknessHidden = document.getElementById('thickness');
      
      // Clear previous selections
      thicknessGroup.innerHTML = '';
      thicknessHidden.value = '';
      
      if (data.thicknessList && data.thicknessList.length > 1) {
        // Multiple thickness options - show selection
        thicknessSection.style.display = 'block';
        data.thicknessList.forEach(thickness => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn';
          btn.textContent = thickness;
          btn.onclick = function() { selectThickness(thickness); };
          thicknessGroup.appendChild(btn);
        });
      } else if (data.thicknessList && data.thicknessList.length === 1) {
        // Only one thickness - auto-select
        thicknessSection.style.display = 'none';
        thicknessHidden.value = data.thicknessList[0];
      } else {
        // No thickness data
        thicknessSection.style.display = 'none';
      }
    })
    .catch(error => {});
}

function selectThickness(thickness) {
  document.getElementById('thickness').value = thickness;
  
  // Highlight selected thickness button
  document.querySelectorAll('#thicknessGroup .btn').forEach(btn => {
    btn.classList.remove('selected');
    if (btn.textContent == thickness) {
      btn.classList.add('selected');
    }
  });
  
  updateSummary();
}

function handleBagSizeChange() {
  const dropdown = document.getElementById('bag_size_dropdown');
  const customInput = document.getElementById('bag_size_custom');
  const hiddenInput = document.getElementById('bag_size');
  
  if (dropdown.value === 'custom') {
    customInput.style.display = 'block';
    customInput.required = true;
    hiddenInput.value = '';
  } else {
    customInput.style.display = 'none';
    customInput.required = false;
    customInput.value = '';
    hiddenInput.value = dropdown.value;
  }
  updateSummary();
}

// Listen to custom input changes
document.addEventListener('DOMContentLoaded', function() {
  const bagSizeCustomInput = document.getElementById('bag_size_custom');
  const bagSizeHiddenInput = document.getElementById('bag_size');
  const cncMachineCustomInput = document.getElementById('cnc_machine_custom');
  const cncMachineHiddenInput = document.getElementById('cnc_machine_id');
  const refNumber = document.getElementById('reference_number');
  
  bagSizeCustomInput.addEventListener('input', function() {
    bagSizeHiddenInput.value = this.value;
    updateSummary();
  });
  
  cncMachineCustomInput.addEventListener('input', function() {
    cncMachineHiddenInput.value = this.value;
    updateSummary();
  });
  
  refNumber.addEventListener('change', updateSummary);
});

// Select button function for project selection
function selectBtn(btn, groupId) {
  // Remove selected class from all buttons in the group
  document.querySelectorAll(`#${groupId} .btn`).forEach(b => b.classList.remove('selected'));
  // Add selected class to clicked button
  btn.classList.add('selected');
  
  // Handle project selection
  if (groupId === 'projectGroup') {
    const projectId = btn.getAttribute('data-id');
    const projectIdInput = document.getElementById('project_id');
    if (projectIdInput) {
      projectIdInput.value = projectId;
    }
  }
  
  // Update summary
  if (typeof updateSummary === 'function') {
    updateSummary();
  }
}

// Make function globally available
window.selectBtn = selectBtn;

function updateSummary() {
  const dateTime = document.getElementById("dateTime").value;
  const shift = document.getElementById("shift").value;
  const entryId = document.querySelector('input[name="cnc_id"]').value;
  
  // Get project name from selected button
  const selectedProject = document.querySelector('#projectGroup .btn.selected');
  const projectName = selectedProject ? selectedProject.textContent.trim() : '';
  
  const refNumber = document.getElementById("reference_number").value;
  const cuttingBatch = document.getElementById("cnc_cutting_batch").value;
  const bagSize = document.getElementById("bag_size").value;
  const cuttingQty = document.getElementById("cutting_roll_quantity").value;
  
  // Get CNC Machine ID (either from hidden field or custom input)
  let cncMachineId = document.getElementById("cnc_machine_id").value;
  if (!cncMachineId) {
    const customMachine = document.getElementById("cnc_machine_custom").value.trim();
    if (customMachine) {
      cncMachineId = customMachine;
    }
  }
  
  // Only show summary if at least some basic info is available
  if (dateTime && shift && entryId) {
    let summary = `${dateTime} | Shift: ${shift} | Entry ID: ${entryId}`;
    if (refNumber) summary += ` | Reference: ${refNumber}`;
    if (cuttingBatch) summary += ` | Cutting Batch: ${cuttingBatch}`;
    if (cncMachineId) summary += ` | Machine: ${cncMachineId}`;
    if (projectName) summary += ` | Project: ${projectName}`;
    if (cuttingQty) summary += ` | Rolls: ${cuttingQty}`;
    if (bagSize) summary += ` | Bag Size: ${bagSize}`;
    
    document.getElementById("summaryBox").innerText = summary;
    document.getElementById("summary").value = summary;
  } else {
    document.getElementById("summaryBox").innerText = "";
    document.getElementById("summary").value = "";
  }
}

function clearForm(){
  // Clear all form fields
  document.getElementById("cncForm").reset();
  
  // Clear selected references
  window.selectedCNCReferences = [];
  window.autoQuantityMode = null; // Reset auto/manual mode
  updateSelectedCNCReferences();
  updateRollCountInfo();
  updateMaxCuttingQuantity(); // Reset max cutting quantity
  document.getElementById("reference_search").value = "";
  
  // Reset cutting quantity
  const cuttingQtyInput = document.getElementById('cutting_roll_quantity');
  if (cuttingQtyInput) {
    cuttingQtyInput.value = '';
    cuttingQtyInput.readOnly = false;
    cuttingQtyInput.style.background = '';
    cuttingQtyInput.style.cursor = '';
  }
  
  // Clear button selections
  document.querySelectorAll('#projectGroup .btn').forEach(b=>b.classList.remove('selected'));
  document.querySelectorAll('#cncMachineGroup .btn').forEach(b=>b.classList.remove('selected'));
  document.querySelectorAll('#bagSizeGroup .btn-bag-size').forEach(b=>b.classList.remove('selected'));
  document.getElementById("project_id").value="";
  document.getElementById("cnc_machine_id").value="";
  document.getElementById("bag_size").value="";
  // Regenerate cutting batch on form clear (uses the same number from server)
  generateCuttingBatch();
  document.getElementById("bag_size_custom").style.display = 'none';
  document.getElementById("bag_size_custom").value = '';
  document.getElementById("cnc_machine_custom").style.display = 'none';
  document.getElementById("cnc_machine_custom").value = '';
  const gs = document.getElementById('gsmSection');
  const ts = document.getElementById('thicknessSection');
  if (gs) gs.style.display = 'none';
  if (ts) ts.style.display = 'none';
  
  // Clear summary
  document.getElementById("summaryBox").innerText = "";
  document.getElementById("summary").value = "";
}

function validateForm(){
  // Ensure hidden fields are populated
  const dateTime = document.getElementById("dateTime").value;
  const shift = document.getElementById("shift").value;
  
  if(!window.selectedCNCReferences || window.selectedCNCReferences.length === 0){
    alert("Please select at least one Reference Number."); return false;
  }
  
  // Validate total roll count
  const currentRollCount = getCurrentTotalRollCount();
  const maxRollsLimit = getMaxRolls();
  if (currentRollCount > maxRollsLimit) {
    alert(`Total rolls (${currentRollCount}) exceeds maximum allowed (${maxRollsLimit}) for the selected CNC machine.`); 
    return false;
  }
  // Cutting batch is auto-generated, so it should always have a value
  if(!document.getElementById("cnc_cutting_batch").value){
    // Regenerate if somehow missing
    generateCuttingBatch();
  }
  
  // Handle CNC Machine ID (either from buttons or custom input)
  const cncMachineId = document.getElementById("cnc_machine_id");
  const cncMachineCustom = document.getElementById("cnc_machine_custom");
  
  if(!cncMachineId.value && cncMachineCustom.value.trim()){
    // If custom input has value, use it
    cncMachineId.value = cncMachineCustom.value.trim();
  }
  
  if(!cncMachineId.value){
    alert("Please select or enter a CNC Machine ID."); return false;
  }
  
  if(!document.getElementById("project_id").value){
    alert("Please select a project."); return false;
  }
  const quantityInput = document.getElementById("cutting_roll_quantity");
  // Recalculate total before validation
  if (typeof calculateTotalCuttingQuantity === 'function') {
    calculateTotalCuttingQuantity();
  }
  if(!quantityInput.value || parseInt(quantityInput.value) <= 0){
    alert("Please enter cutting quantities for at least one roll. The total will be calculated automatically."); return false;
  }
  
  // Validate cutting quantity doesn't exceed maximum (rolls * 36)
  // Reuse currentRollCount from above (line 976)
  if (currentRollCount > 0) {
    const maxCuttingQuantity = currentRollCount * 36;
    const enteredQuantity = parseInt(quantityInput.value) || 0;
    
    // Check individual roll quantities don't exceed 36 pieces per roll
    if (window.selectedCNCReferences && window.selectedCNCReferences.length > 0) {
      let hasInvalidQuantity = false;
      let invalidRollInfo = '';
      
      window.selectedCNCReferences.forEach((ref, refIndex) => {
        if (ref.quantities && Array.isArray(ref.quantities)) {
          ref.quantities.forEach((qty, rollIndex) => {
            const quantity = parseInt(qty) || 0;
            if (quantity > 36) {
              hasInvalidQuantity = true;
              invalidRollInfo += `${ref.display || ref.reference} - Roll ${rollIndex + 1}: ${quantity} pieces (max 36)\n`;
            }
          });
        }
      });
      
      if (hasInvalidQuantity) {
        alert(`Some roll quantities exceed the maximum of 36 pieces per roll:\n\n${invalidRollInfo}\nPlease adjust the quantities.`);
        return false;
      }
    }
    
    if (enteredQuantity > maxCuttingQuantity) {
      alert(`Total cutting quantity (${enteredQuantity} pieces) cannot exceed ${maxCuttingQuantity} pieces (${currentRollCount} roll(s) × 36 pieces per roll).`);
      return false;
    }
    
    if (enteredQuantity < 1) {
      alert("Please enter cutting quantities for at least one roll. The total must be at least 1 piece.");
      return false;
    }
  }
  
  // Note: Roll limit validation is for reference selection, not cutting quantity
  
  if(!document.getElementById("bag_size").value.trim()){
    alert("Please select or enter bag size."); return false;
  }
  
  return true;
}

// Add event listeners for form fields to update summary (additional to the ones in handleBagSizeChange)
document.addEventListener('DOMContentLoaded', function() {
  console.log('DOMContentLoaded: Setting up event listeners...');
  
  // Use event delegation for CNC Machine ID buttons (more reliable)
  const cncMachineGroup = document.getElementById('cncMachineGroup');
  if (cncMachineGroup) {
    console.log('CNC Machine Group found, setting up delegation...');
    cncMachineGroup.addEventListener('click', function(e) {
      const clickedBtn = e.target.closest('.cnc-machine-btn');
      if (clickedBtn) {
        console.log('CNC Machine button clicked via delegation!', clickedBtn);
        e.preventDefault();
        e.stopPropagation();
        
        if (typeof selectCNCMachine === 'function') {
          console.log('Calling selectCNCMachine...');
          selectCNCMachine(clickedBtn, 'cncMachineGroup');
        } else {
          console.error('selectCNCMachine function is not defined!');
          alert('Error: CNC Machine selection function not loaded. Please refresh the page.');
        }
      }
    });
  } else {
    console.error('CNC Machine Group container not found!');
  }
  
  // Use event delegation for Bag Size buttons (more reliable)
  const bagSizeGroup = document.getElementById('bagSizeGroup');
  if (bagSizeGroup) {
    console.log('Bag Size Group found, setting up delegation...');
    bagSizeGroup.addEventListener('click', function(e) {
      const clickedBtn = e.target.closest('.btn-bag-size');
      if (clickedBtn) {
        console.log('Bag Size button clicked via delegation!', clickedBtn);
        e.preventDefault();
        e.stopPropagation();
        
        const bagSize = clickedBtn.getAttribute('data-bag-size');
        console.log('Bag size value:', bagSize);
        
        if (bagSize) {
          if (typeof selectBagSize === 'function') {
            console.log('Calling selectBagSize...');
            selectBagSize(bagSize);
          } else {
            console.error('selectBagSize function is not defined!');
            alert('Error: Bag Size selection function not loaded. Please refresh the page.');
          }
        } else {
          console.error('Bag size attribute not found on button:', clickedBtn);
        }
      }
    });
  } else {
    console.error('Bag Size Group container not found!');
  }
  
  // Also try direct attachment as fallback
  const cncMachineButtons = document.querySelectorAll('.cnc-machine-btn');
  console.log('Found CNC Machine buttons (direct):', cncMachineButtons.length);
  cncMachineButtons.forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      console.log('CNC Machine button clicked (direct)!', this);
      e.preventDefault();
      e.stopPropagation();
      if (typeof selectCNCMachine === 'function') {
        selectCNCMachine(this, 'cncMachineGroup');
      }
    });
  });
  
  const bagSizeButtons = document.querySelectorAll('.btn-bag-size');
  console.log('Found Bag Size buttons (direct):', bagSizeButtons.length);
  bagSizeButtons.forEach(function(btn) {
    btn.addEventListener('click', function(e) {
      console.log('Bag Size button clicked (direct)!', this);
      e.preventDefault();
      e.stopPropagation();
      const bagSize = this.getAttribute('data-bag-size');
      if (bagSize && typeof selectBagSize === 'function') {
        selectBagSize(bagSize);
      }
    });
  });
  
  // Verify functions are available
  console.log('selectCNCMachine function available:', typeof selectCNCMachine === 'function');
  console.log('selectBagSize function available:', typeof selectBagSize === 'function');
  
  if (typeof selectCNCMachine !== 'function') {
    console.error('selectCNCMachine function is not defined!');
  }
  if (typeof selectBagSize !== 'function') {
    console.error('selectBagSize function is not defined!');
  }
  
  const cuttingQuantityInput = document.getElementById('cutting_roll_quantity');
  if (cuttingQuantityInput) {
    cuttingQuantityInput.addEventListener('input', function() {
      // Don't validate immediately when adding reference - wait for max quantities to be fetched
      // This prevents popup from showing with wrong max value (1)
      // Validation will happen when user actually enters a value
      if (typeof updateSummary === 'function') {
        updateSummary();
      }
    });
  }
  
  const cncMachineCustom = document.getElementById('cnc_machine_custom');
  if (cncMachineCustom) {
    cncMachineCustom.addEventListener('input', function() {
      if (typeof updateSummary === 'function') {
        updateSummary();
      }
    });
  }
  
  // Initialize reference fields (disabled by default)
  if (typeof enableReferenceFields === 'function') {
  enableReferenceFields();
  }
  if (typeof checkCustomMachineInput === 'function') {
  checkCustomMachineInput();
  }
  
  // Load form data asynchronously after page renders for instant page load
  if (typeof loadFormData === 'function') {
  loadFormData();
  }
  
  // Generate cutting batch on page load (CW-XX format)
  if (typeof generateCuttingBatch === 'function') {
  generateCuttingBatch();
  }
  
  // Update summary on page load
  if (typeof updateSummary === 'function') {
  updateSummary();
  }
  
  // Initialize max cutting quantity
  if (typeof updateMaxCuttingQuantity === 'function') {
    updateMaxCuttingQuantity();
  }
  
  // Initialize date/time and shift display
  if (typeof updateTimeAndShift === 'function') {
    updateTimeAndShift();
    setInterval(updateTimeAndShift, 1000);
  }
  
  // Prevent form submission when Enter is pressed in quantity input fields
  document.addEventListener('keydown', function(e) {
    // Check if the target is a quantity input field
    if (e.target && e.target.hasAttribute('data-ref-index') && e.target.hasAttribute('data-roll-index')) {
      if (e.key === 'Enter') {
        e.preventDefault();
        e.stopPropagation();
        // Trigger the update function
        const refIndex = parseInt(e.target.getAttribute('data-ref-index'));
        const rollIndex = parseInt(e.target.getAttribute('data-roll-index'));
        if (!isNaN(refIndex) && !isNaN(rollIndex)) {
          updateRollQuantity(refIndex, rollIndex, e.target.value);
        }
        return false;
      }
    }
  });
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
        
        data.projects.forEach(project => {
          const btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn';
          btn.setAttribute('data-id', project.id);
          btn.textContent = project.project_name;
          btn.onclick = function() { selectBtn(this, 'projectGroup'); };
          projectGroup.appendChild(btn);
        });
      }
    })
    .catch(err => {
      const loadingText = document.getElementById('project_loading');
      if (loadingText) loadingText.textContent = 'Failed to load projects';
    });
  
  // Don't load references automatically - wait for machine selection
  // References will be loaded when enableReferenceFields() is called after machine selection
}

// Store references data globally
let cncReferencesData = [];
if (typeof window.selectedCNCReferences === 'undefined') {
  window.selectedCNCReferences = [];
}

// Default cutting quantity per roll (configurable)
// This is the default value that will be pre-filled when a reference is selected
const DEFAULT_CUTTING_QUANTITY_PER_ROLL = 36;

// Function to enable/disable reference fields based on machine selection
function enableReferenceFields() {
  const cncMachineId = document.getElementById('cnc_machine_id');
  const cncMachineCustom = document.getElementById('cnc_machine_custom');
  const referenceSearch = document.getElementById('reference_search');
  const addBtn = document.getElementById('add_reference_btn');
  const refLoading = document.getElementById('ref_loading');
  
  const machineValue = cncMachineId ? cncMachineId.value : '';
  const customValue = cncMachineCustom ? cncMachineCustom.value.trim() : '';
  const hasMachineSelected = machineValue || customValue;
  
  if (hasMachineSelected) {
    // Enable reference fields
    if (referenceSearch) {
      referenceSearch.disabled = false;
      referenceSearch.style.backgroundColor = '#fff';
      referenceSearch.style.cursor = 'text';
      referenceSearch.placeholder = 'Search or type reference number...';
      // Event handlers are attached via addEventListener in DOMContentLoaded
      // They will work even when the field is enabled/disabled
    }
    
    if (addBtn) {
      addBtn.disabled = false;
      addBtn.style.background = '#3498db';
      addBtn.style.cursor = 'pointer';
      addBtn.style.opacity = '1';
    }
    
    if (refLoading) {
      refLoading.textContent = 'Loading options...';
      refLoading.style.color = '#7f8c8d';
    }
    
    // Load references if not already loaded
    if (cncReferencesData.length === 0) {
      loadCNCReferences();
    }
  } else {
    // Disable reference fields
    if (referenceSearch) {
      referenceSearch.disabled = true;
      referenceSearch.style.backgroundColor = '#f5f5f5';
      referenceSearch.style.cursor = 'not-allowed';
      referenceSearch.placeholder = 'Please select CNC Machine ID first...';
      referenceSearch.value = '';
      // Remove event handlers
      referenceSearch.onkeyup = null;
      referenceSearch.onkeydown = null;
      referenceSearch.oninput = null;
      referenceSearch.onfocus = null;
    }
    
    if (addBtn) {
      addBtn.disabled = true;
      addBtn.style.background = '#95a5a6';
      addBtn.style.cursor = 'not-allowed';
      addBtn.style.opacity = '0.6';
    }
    
    if (refLoading) {
      refLoading.textContent = 'Please select a CNC Machine ID first to load references.';
      refLoading.style.color = '#7f8c8d';
    }
    
    // Hide dropdown
    const dropdown = document.getElementById('reference_dropdown');
    if (dropdown) {
      dropdown.style.display = 'none';
    }
  }
}

// Also check when custom input changes
function checkCustomMachineInput() {
  const customInput = document.getElementById('cnc_machine_custom');
  if (customInput) {
    customInput.addEventListener('input', function() {
      const hiddenInput = document.getElementById('cnc_machine_id');
      if (hiddenInput && this.value.trim()) {
        hiddenInput.value = this.value.trim();
      } else if (hiddenInput) {
        hiddenInput.value = '';
      }
      enableReferenceFields();
    });
  }
}

function loadCNCReferences() {
  const loadingText = document.getElementById('ref_loading');
  if (loadingText) {
    loadingText.textContent = 'Loading references...';
    loadingText.style.display = 'block';
    loadingText.style.color = '#666';
  }
  
  const apiPath = 'api/get_cnc_references.php';
  
  fetch(apiPath)
    .then(response => {
      if (!response.ok) {
        throw new Error('Network response was not ok: ' + response.status + ' ' + response.statusText);
      }
      
      // Check content type
      const contentType = response.headers.get('content-type');
      if (!contentType || !contentType.includes('application/json')) {
        return response.text().then(text => {
          throw new Error('Response is not JSON. Got: ' + contentType);
        });
      }
      
      return response.json();
    })
    .then(data => {
      if (!data) {
        throw new Error('Empty response from API');
      }
      
      if (data.success && data.references && Array.isArray(data.references)) {
        cncReferencesData = data.references;
        
        if (loadingText) {
          loadingText.style.display = 'none';
        }
        
        // Populate dropdown immediately
        populateCNCReferenceDropdown();
        
        // Always show dropdown after loading data
        const dropdown = document.getElementById('reference_dropdown');
        if (dropdown && dropdown.children.length > 0) {
          dropdown.style.display = 'block';
          
          // If user has typed something, filter the results
          const searchInput = document.getElementById('reference_search');
          if (searchInput && searchInput.value.trim()) {
            filterCNCReferences();
          }
        }
      } else {
        if (loadingText) {
          loadingText.textContent = 'No references available.';
          loadingText.style.color = '#e74c3c';
        }
      }
    })
    .catch(err => {
      if (loadingText) {
        loadingText.textContent = 'Error loading references: ' + err.message;
        loadingText.style.color = '#e74c3c';
        loadingText.style.fontWeight = 'bold';
        loadingText.style.display = 'block';
      }
    });
}

// Ensure function is called - add immediate check

function populateCNCReferenceDropdown() {
  const dropdown = document.getElementById('reference_dropdown');
  if (!dropdown) {
    return;
  }
  
  // Store the no matches message HTML before clearing
  const noMatchesHtml = '<div id="no_matches_message" class="no-matches" style="display: none;">No matches found</div>';
  
  dropdown.innerHTML = '';
  
  // Re-add the no matches message
  dropdown.insertAdjacentHTML('beforeend', noMatchesHtml);
  
  if (!cncReferencesData || cncReferencesData.length === 0) {
    return;
  }
  
  let individualAdded = 0;
  let bundleAdded = 0;
  
  cncReferencesData.forEach((ref, index) => {
    const isBundle = ref.is_bundle || false;
    const displayText = ref.display || ref.reference || '';
    
    // Calculate roll count using the same logic as selection functions
    let rollCount = ref.roll_count;
    
    // If roll_count is not available, try to calculate it
    if (!rollCount || rollCount === 1) {
      // First, try to count from bundle_refs array
      if (isBundle && ref.bundle_refs && Array.isArray(ref.bundle_refs) && ref.bundle_refs.length > 0) {
        rollCount = ref.bundle_refs.length;
      }
      // If still not available, try to parse from display format
      // Format examples: "REF-1 to REF-4" or "REF-1-4 (Bundle)"
      else if (displayText) {
        // Check for "to" format: "X-1 to X-4"
        const toMatch = displayText.match(/\s+to\s+/i);
        if (toMatch) {
          // Split by " to " and extract the last number after the last dash from each part
          const parts = displayText.split(/\s+to\s+/i);
          if (parts.length === 2) {
            // Extract number after last dash in first part (e.g., "2.8L126JAN19-R09-GT0.9.H0.1-1" -> 1)
            // Match all -(\d+) patterns and take the last one
            const firstPartMatches = parts[0].match(/-(\d+)/g);
            // Extract number after last dash in second part (e.g., "2.8L126JAN19-R09-GT0.9.H0.1-4 [Bag]" -> 4)
            const secondPartMatches = parts[1].match(/-(\d+)/g);
            
            if (firstPartMatches && firstPartMatches.length > 0 && secondPartMatches && secondPartMatches.length > 0) {
              // Get the last match from each part (the roll number)
              const firstLastMatch = firstPartMatches[firstPartMatches.length - 1].match(/-(\d+)/);
              const secondLastMatch = secondPartMatches[secondPartMatches.length - 1].match(/-(\d+)/);
              
              if (firstLastMatch && secondLastMatch) {
                const startNum = parseInt(firstLastMatch[1]);
                const endNum = parseInt(secondLastMatch[1]);
                if (!isNaN(startNum) && !isNaN(endNum) && endNum >= startNum) {
                  rollCount = endNum - startNum + 1;
                }
              }
            }
          }
        }
        // Check for dash-separated range format: "REF-1-4 (Bundle)"
        else {
          const dashRangeMatch = displayText.match(/-(\d+)-(\d+)\s*\(/i);
          if (dashRangeMatch) {
            const startNum = parseInt(dashRangeMatch[1]);
            const endNum = parseInt(dashRangeMatch[2]);
            if (!isNaN(startNum) && !isNaN(endNum) && endNum >= startNum) {
              rollCount = endNum - startNum + 1;
            }
          }
        }
      }
    }
    
    // Final fallback
    rollCount = rollCount || 1;
    
    const option = document.createElement('div');
    option.className = 'reference-option' + (isBundle ? ' bundle-option' : '');
    option.setAttribute('data-ref', ref.reference || '');
    option.setAttribute('data-roll-count', rollCount);
    option.setAttribute('data-is-bundle', isBundle ? 'true' : 'false');
    option.setAttribute('data-display', displayText);
    if (isBundle && ref.bundle_refs) {
      option.setAttribute('data-bundle-refs', JSON.stringify(ref.bundle_refs));
    }
    
    option.style.cssText = 'padding:10px; cursor:pointer; border-bottom:1px solid #eee; ' + 
                           (isBundle ? 'background:#e8f5e9;' : 'background:#fff;');
    
    let html = '<strong>' + escapeHtml(displayText) + '</strong>';
    if (isBundle) {
      html += '<span style="background:#4caf50; color:#fff; padding:2px 6px; border-radius:3px; font-size:11px; margin-left:5px;">BUNDLE</span>';
    }
    html += '<br><small style="color:#27ae60; font-weight:600;">Rolls: ' + rollCount + '</small>';
    
    option.innerHTML = html;
    option.style.display = 'block'; // Make sure options are visible by default
    
    // Attach event handlers AFTER setting innerHTML
    // Clicking on option should just select it (fill search input), not add it
    option.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      // Just select the reference (fill search input), don't add it yet
      selectCNCReferenceOption(ref);
      return false;
    });
    
    option.addEventListener('mouseover', function() {
      this.style.background = isBundle ? '#c8e6c9' : '#f0f0f0';
    });
    
    option.addEventListener('mouseout', function() {
      this.style.background = isBundle ? '#e8f5e9' : '#fff';
    });
    
    dropdown.appendChild(option);
    
    if (isBundle) {
      bundleAdded++;
    } else {
      individualAdded++;
    }
  });
  
  // Hide "no matches" message by default when populating (all options are visible initially)
  const noMatchesMsg = document.getElementById('no_matches_message');
  if (noMatchesMsg) {
    noMatchesMsg.style.display = 'none';
  }
  
  // Make dropdown visible if it has content
  // Note: display will be controlled by filterCNCReferences() based on visible matches
  // But we ensure it's populated so filtering can work
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function showCNCReferenceDropdown() {
  const dropdown = document.getElementById('reference_dropdown');
  if (!dropdown) return;
  
  // Check if machine is selected (required to load references)
  const cncMachineId = document.getElementById('cnc_machine_id');
  const cncMachineCustom = document.getElementById('cnc_machine_custom');
  const machineValue = cncMachineId ? cncMachineId.value : '';
  const customValue = cncMachineCustom ? cncMachineCustom.value.trim() : '';
  const hasMachineSelected = machineValue || customValue;
  
  if (!hasMachineSelected) {
    dropdown.style.display = 'none';
    return;
  }
  
  // If data is loaded, populate and show
  if (cncReferencesData && cncReferencesData.length > 0) {
    // Ensure dropdown is populated first
    if (dropdown.children.length === 0) {
      populateCNCReferenceDropdown();
    }
    // Apply filters
    filterCNCReferences();
    // Always show dropdown when user focuses on input field
    dropdown.style.display = 'block';
  } else {
    // Data not loaded yet - trigger loading
    if (cncReferencesData.length === 0) {
      loadCNCReferences();
    }
    dropdown.style.display = 'none';
  }
}

function filterCNCReferences() {
  // Get search term and normalize to lowercase for case-insensitive search
  // This handles uppercase, lowercase, and mixed case input
  const searchInput = document.getElementById('reference_search');
  const searchTerm = (searchInput ? searchInput.value : '').toLowerCase().trim();
  
  // Check if machine is selected (required to load references)
  const cncMachineId = document.getElementById('cnc_machine_id');
  const cncMachineCustom = document.getElementById('cnc_machine_custom');
  const machineValue = cncMachineId ? cncMachineId.value : '';
  const customValue = cncMachineCustom ? cncMachineCustom.value.trim() : '';
  const hasMachineSelected = machineValue || customValue;
  
  if (!hasMachineSelected) {
    return;
  }
  
  // If data not loaded, try to load it
  if (!cncReferencesData || cncReferencesData.length === 0) {
    loadCNCReferences();
    return;
  }
  
  // Clear selected reference when user types (they need to select again)
  if (selectedCNCReferenceForAdd) {
    selectedCNCReferenceForAdd = null;
    // Remove highlight from all options
    const dropdown = document.getElementById('reference_dropdown');
    if (dropdown) {
      const options = dropdown.querySelectorAll('.reference-option');
      options.forEach(opt => {
        const isBundle = opt.getAttribute('data-is-bundle') === 'true';
        opt.style.background = isBundle ? '#e8f5e9' : '#fff';
        opt.style.borderLeft = 'none';
      });
    }
  }
  
  const options = document.querySelectorAll('#reference_dropdown .reference-option');
  const maxRolls = getMaxRolls();
  
  // Get current total roll count
  const currentRollCount = getCurrentTotalRollCount();
  
  if (options.length === 0) {
    // If no options, try to populate first
    if (cncReferencesData && cncReferencesData.length > 0) {
      populateCNCReferenceDropdown();
      // Re-query after populating
      const newOptions = document.querySelectorAll('#reference_dropdown .reference-option');
      newOptions.forEach(option => {
        filterOption(option, searchTerm, maxRolls, currentRollCount);
      });
      // Show dropdown after populating
      const dropdown = document.getElementById('reference_dropdown');
      const noMatchesMsg = document.getElementById('no_matches_message');
      
      if (dropdown) {
        const visibleOptions = Array.from(newOptions).filter(opt => opt.style.display !== 'none');
        
        // Show/hide "no matches" message
        if (noMatchesMsg) {
          if (visibleOptions.length === 0 && searchTerm !== '') {
            noMatchesMsg.style.display = 'block';
          } else {
            noMatchesMsg.style.display = 'none';
          }
        }
        
        // Show dropdown if there are visible options OR if user has typed something (to show "no matches")
        if (visibleOptions.length > 0 || (searchTerm !== '' && cncReferencesData.length > 0)) {
          dropdown.style.display = 'block';
        }
      }
    }
    return;
  }
  
  options.forEach(option => {
    filterOption(option, searchTerm, maxRolls, currentRollCount);
  });
  
  // Always show dropdown when user is typing (so they can see all matching references)
  const dropdown = document.getElementById('reference_dropdown');
  const noMatchesMsg = document.getElementById('no_matches_message');
  
  if (dropdown) {
    // Count visible options
    const visibleOptions = Array.from(options).filter(opt => opt.style.display !== 'none');
    
    // Show/hide "no matches" message
    if (noMatchesMsg) {
      if (visibleOptions.length === 0 && searchTerm !== '') {
        // User has typed something but no matches found
        noMatchesMsg.style.display = 'block';
      } else {
        // Either there are matches or search is empty
        noMatchesMsg.style.display = 'none';
      }
    }
    
    // Show dropdown if there are visible options OR if user has typed something (to show "no matches")
    if (visibleOptions.length > 0 || (searchTerm !== '' && cncReferencesData.length > 0)) {
      dropdown.style.display = 'block';
    } else {
      dropdown.style.display = 'none';
    }
  }
}

function filterOption(option, searchTerm, maxRolls, currentRollCount) {
  // Convert reference text to lowercase for case-insensitive matching
  // Handles uppercase (ABC), lowercase (abc), and mixed case (AbC) in reference numbers
  const refText = (option.getAttribute('data-display') || option.getAttribute('data-ref') || '').toLowerCase();
  const rollCount = parseInt(option.getAttribute('data-roll-count')) || 1;
  const isBundle = option.getAttribute('data-is-bundle') === 'true';
  
  // Check if adding this reference would exceed the limit (only if machine is selected)
  const cncMachineId = document.getElementById('cnc_machine_id');
  const cncMachineCustom = document.getElementById('cnc_machine_custom');
  const machineValue = cncMachineId ? cncMachineId.value : '';
  const customValue = cncMachineCustom ? cncMachineCustom.value.trim() : '';
  const hasMachineSelected = machineValue || customValue;
  
  const wouldExceed = hasMachineSelected && (currentRollCount + rollCount) > maxRolls;
  
  // Enhanced search: match exact sequences in the reference (integers, dates, alphabets)
  // Supports searching by: numbers (e.g., "2.0" matches "2.0" but not "4.0"), dates, letters
  // Case-insensitive: handles "ABC", "abc", "AbC", "aBc" all the same way
  let matchesSearch = true;
  if (searchTerm !== '') {
    // Both searchTerm and refText are already converted to lowercase
    // This ensures case-insensitive matching for:
    // - Uppercase letters: "JAN", "R01", "GT" 
    // - Lowercase letters: "jan", "r01", "gt"
    // - Mixed case: "Jan", "R01", "Gt", "jAn05"
    const searchLower = searchTerm; // Already lowercase from filterCNCReferences
    const refLower = refText; // Already lowercase from filterOption
    
    // Exact sequence match - check if search term appears as a complete sequence in reference
    // This ensures "2.0" matches "2.0" but NOT "4.0" or "20"
    // Works for: exact numbers (e.g., "2.0", "226", "05"), dates (e.g., "JAN05"), 
    // alphabets (e.g., "R01", "GT"), or any combination
    matchesSearch = refLower.includes(searchLower);
    
    // Additional validation for number sequences to prevent false matches
    // Example: "2.0" should not match "4.0" or "20" or "2.05"
    if (matchesSearch && /^\d+\.?\d*$/.test(searchLower)) {
      // If searching for a number (like "2.0"), ensure exact match or word boundary
      // Check if the match is at word boundary or exact sequence
      const matchIndex = refLower.indexOf(searchLower);
      if (matchIndex >= 0) {
        const beforeChar = matchIndex > 0 ? refLower[matchIndex - 1] : '';
        const afterChar = matchIndex + searchLower.length < refLower.length ? refLower[matchIndex + searchLower.length] : '';
        
        // Allow match if it's at start/end or surrounded by non-digit characters
        // This ensures "2.0" matches "2.0L" but not "4.0" or "20"
        const isValidBoundary = 
          (matchIndex === 0 || !/\d/.test(beforeChar)) &&
          (afterChar === '' || !/\d/.test(afterChar));
        
        if (!isValidBoundary) {
          matchesSearch = false;
        }
      }
    }
  }
  
  // Check if already selected
  const refValue = option.getAttribute('data-ref');
  const isAlreadySelected = window.selectedCNCReferences && window.selectedCNCReferences.some(r => {
    if (r.reference === refValue) return true;
    if (isBundle && r.isBundle && r.bundleRefs) {
      return r.bundleRefs.some(br => refValue.includes(br));
    }
    return false;
  });
  
  // Show option if it matches search AND is not already selected AND would not exceed limit
  if (matchesSearch && !isAlreadySelected && !wouldExceed) {
    option.style.display = 'block';
  } else {
    option.style.display = 'none';
  }
}

function getMaxRolls() {
  const cncMachineId = document.getElementById('cnc_machine_id');
  const cncMachineCustom = document.getElementById('cnc_machine_custom');
  const machineValue = cncMachineId ? cncMachineId.value : '';
  const customValue = cncMachineCustom ? cncMachineCustom.value.trim() : '';
  
  // Check if Custom button is selected (even if input is empty)
  const customButton = document.querySelector('#cncMachineGroup .btn[data-value="custom"]');
  const isCustomSelected = customButton && customButton.classList.contains('selected');
  
  // If Custom button is selected OR custom input has value, return 100
  if (isCustomSelected || customValue) {
    return 100; // Custom machine: max 100 rolls
  } else if (machineValue === 'CNC-01' || machineValue === 'CNC-02') {
    return 4; // CNC-01 and CNC-02: max 4 rolls
  }
  return 4; // Default
}

function getCurrentTotalRollCount() {
  if (!window.selectedCNCReferences || window.selectedCNCReferences.length === 0) {
    return 0;
  }
  return window.selectedCNCReferences.reduce((sum, ref) => sum + (ref.rollCount || 1), 0);
}

// Store currently selected reference (for Add button)
let selectedCNCReferenceForAdd = null;

function selectCNCReferenceOption(ref) {
  // Select a reference option (fill search input) without adding it
  const searchInput = document.getElementById('reference_search');
  if (searchInput) {
    searchInput.value = ref.display || ref.reference || '';
    // Store the selected reference for the Add button
    selectedCNCReferenceForAdd = ref;
    // Highlight the selected option
    const dropdown = document.getElementById('reference_dropdown');
    if (dropdown) {
      const options = dropdown.querySelectorAll('.reference-option');
      options.forEach(opt => {
        opt.style.background = opt.getAttribute('data-ref') === ref.reference 
          ? (ref.is_bundle ? '#c8e6c9' : '#e3f2fd') 
          : (ref.is_bundle ? '#e8f5e9' : '#fff');
        opt.style.borderLeft = opt.getAttribute('data-ref') === ref.reference ? '3px solid #3498db' : 'none';
      });
    }
  }
}

function selectCNCReferenceFromDropdown(ref) {
  const maxRolls = getMaxRolls();
  const currentRollCount = getCurrentTotalRollCount();
  
  // Calculate roll count: use roll_count from API, or calculate from bundle_refs, or parse from display
  let rollCount = ref.roll_count;
  
  // If roll_count is not available, try to calculate it
  if (!rollCount || rollCount === 1) {
    // First, try to count from bundle_refs array
    if (ref.is_bundle && ref.bundle_refs && Array.isArray(ref.bundle_refs) && ref.bundle_refs.length > 0) {
      rollCount = ref.bundle_refs.length;
    }
    // If still not available, try to parse from display format
    // Format examples: "REF-1 to REF-4" or "REF-1-4 (Bundle)"
    else if (ref.display) {
      // Check for "to" format: "X-1 to X-4"
      const toMatch = ref.display.match(/\s+to\s+/i);
      if (toMatch) {
        // Split by " to " and extract the last number after the last dash from each part
        const parts = ref.display.split(/\s+to\s+/i);
        if (parts.length === 2) {
          // Extract number after last dash in first part (e.g., "2.8L126JAN19-R09-GT0.9.H0.1-1" -> 1)
          // Match all -(\d+) patterns and take the last one
          const firstPartMatches = parts[0].match(/-(\d+)/g);
          // Extract number after last dash in second part (e.g., "2.8L126JAN19-R09-GT0.9.H0.1-4 [Bag]" -> 4)
          const secondPartMatches = parts[1].match(/-(\d+)/g);
          
          if (firstPartMatches && firstPartMatches.length > 0 && secondPartMatches && secondPartMatches.length > 0) {
            // Get the last match from each part (the roll number)
            const firstLastMatch = firstPartMatches[firstPartMatches.length - 1].match(/-(\d+)/);
            const secondLastMatch = secondPartMatches[secondPartMatches.length - 1].match(/-(\d+)/);
            
            if (firstLastMatch && secondLastMatch) {
              const startNum = parseInt(firstLastMatch[1]);
              const endNum = parseInt(secondLastMatch[1]);
              if (!isNaN(startNum) && !isNaN(endNum) && endNum >= startNum) {
                rollCount = endNum - startNum + 1;
              }
            }
          }
        }
      }
      // Check for dash-separated range format: "REF-1-4 (Bundle)"
      else {
        const dashRangeMatch = ref.display.match(/-(\d+)-(\d+)\s*\(/i);
        if (dashRangeMatch) {
          const startNum = parseInt(dashRangeMatch[1]);
          const endNum = parseInt(dashRangeMatch[2]);
          if (!isNaN(startNum) && !isNaN(endNum) && endNum >= startNum) {
            rollCount = endNum - startNum + 1;
          }
        }
      }
    }
  }
  
  // Final fallback
  rollCount = rollCount || 1;
  
  // Check if adding this reference would exceed the limit
  // Only show warning for CNC-01/CNC-02, not for Custom (which allows 100 rolls)
  if ((currentRollCount + rollCount) > maxRolls) {
    const cncMachineId = document.getElementById('cnc_machine_id');
    const cncMachineCustom = document.getElementById('cnc_machine_custom');
    const machineValue = cncMachineId ? cncMachineId.value : '';
    const customValue = cncMachineCustom ? cncMachineCustom.value.trim() : '';
    
    // Only show warning if NOT Custom machine
    if (!customValue && (machineValue === 'CNC-01' || machineValue === 'CNC-02')) {
      showWarningPopup(`Warning: You can't add more than 4 rolls in ${machineValue}`);
      return;
    } else if (customValue || maxRolls === 100) {
      // For Custom machine, show different message or allow
      alert(`You have added maximum rolls (${maxRolls} rolls allowed for the selected CNC machine). Current: ${currentRollCount} rolls, Attempting to add: ${rollCount} rolls.`);
      return;
    }
  }
  
  // Parse remaining quantity from display text or use from ref object
  // Format: "REF (Remaining: 12 pc)" or ref.remaining_quantity
  let remainingQtyPerRoll = DEFAULT_CUTTING_QUANTITY_PER_ROLL; // Default to 36
  
  // For bundles, ALWAYS use 36 per roll (bundles show TOTAL remaining, but each roll can have 36)
  // For individual rolls, use the actual remaining (may be less than 36)
  if (rollCount > 1) {
    // Bundle: always 36 per roll
    remainingQtyPerRoll = DEFAULT_CUTTING_QUANTITY_PER_ROLL;
  } else {
    // Single roll: use actual remaining from API or display
    if (ref.remaining_quantity !== undefined && ref.remaining_quantity !== null) {
      remainingQtyPerRoll = Math.min(ref.remaining_quantity, DEFAULT_CUTTING_QUANTITY_PER_ROLL);
    } else if (ref.display) {
      // Parse from display text: "REF (Remaining: 12 pc)"
      const remainingMatch = ref.display.match(/\(Remaining:\s*(\d+)\s*pc\)/i);
      if (remainingMatch && remainingMatch[1]) {
        const parsedRemaining = parseInt(remainingMatch[1]);
        if (!isNaN(parsedRemaining)) {
          remainingQtyPerRoll = Math.min(parsedRemaining, DEFAULT_CUTTING_QUANTITY_PER_ROLL);
        }
      }
    }
  }
  
  // Determine if it's a bundle: check flag OR rollCount > 1 OR display text patterns
  let isBundleFlag = ref.is_bundle || false;
  if (rollCount > 1) {
    isBundleFlag = true; // Multiple rolls = bundle
  }
  if (!isBundleFlag && ref.display) {
    // Check for bundle patterns in display text
    if (ref.display.includes(' to ') || ref.display.includes('[Bag]') || ref.display.match(/-(\d+)\s+to\s+/)) {
      isBundleFlag = true;
    }
  }
  
  // Add to selected references
  const refObj = {
    reference: ref.reference || '',
    display: ref.display || ref.reference || '',
    rollCount: rollCount,
    isBundle: isBundleFlag, // Use the determined bundle flag
    bundleRefs: ref.bundle_refs || [],
    // For bundles, quantities array stores per-roll values (always 36 per roll for bundles)
    // For individual refs, quantities array stores per-roll values (actual remaining)
    // Initialize with correct per-roll value
    quantities: new Array(rollCount).fill(remainingQtyPerRoll),
    // Initialize max quantities: bundles = 36 per roll, individual = actual remaining
    maxQuantities: new Array(rollCount).fill(remainingQtyPerRoll)
  };
  
  window.selectedCNCReferences.push(refObj);
  
  // Fetch max cutting quantities from backend
  fetchMaxCuttingQuantities(refObj);
  
  // Clear search
  const searchInput = document.getElementById('reference_search');
  if (searchInput) {
    searchInput.value = '';
  }
  const dropdown = document.getElementById('reference_dropdown');
  if (dropdown) {
    dropdown.style.display = 'none';
  }
  
  // Update display
  updateSelectedCNCReferences();
  updateRollCountInfo();
  
  // Calculate total immediately (will be updated again after fetchMaxCuttingQuantities completes)
  calculateTotalCuttingQuantity();
  
  // Don't call updateMaxCuttingQuantity immediately - wait for backend fetch to complete
  // This prevents popup from showing with wrong max value (1) when adding reference
  // The fetchMaxCuttingQuantities function will call updateMaxCuttingQuantity after it completes
  
  // Generate cutting batch when first reference is added (ONE batch per entry)
  // This batch number will be used for all references in this entry
  generateCuttingBatch();
  
  updateSummary();
}

function addCNCReference() {
  const searchInput = document.getElementById('reference_search');
  if (!searchInput) {
    showWarningPopup('Error: Search input not found. Please refresh the page.');
    return;
  }
  
  const refValue = searchInput.value.trim();
  if (!refValue) {
    showWarningPopup('Please search and select a reference number from the dropdown first.');
    return;
  }
  
  // REQUIRE explicit selection from dropdown - no automatic matching
  // User must click on a reference in the dropdown to select it
  if (!selectedCNCReferenceForAdd) {
    showWarningPopup('Please select a reference from the dropdown list first. Type to search, then click on a reference to select it, then click Add.');
    // Show dropdown if it's hidden
    const dropdown = document.getElementById('reference_dropdown');
    if (dropdown) {
      filterCNCReferences(); // Refresh the filtered list
      dropdown.style.display = 'block';
    }
    return;
  }
  
  // Use the explicitly selected reference
  const matchedRef = selectedCNCReferenceForAdd;
  
  if (matchedRef) {
    // Check if adding this reference would exceed the limit before calling selectCNCReferenceFromDropdown
    const maxRolls = getMaxRolls();
    const currentRollCount = getCurrentTotalRollCount();
    
    // Calculate roll count: use roll_count from API, or calculate from bundle_refs, or parse from display
    let rollCount = matchedRef.roll_count;
    
    // If roll_count is not available, try to calculate it
    if (!rollCount || rollCount === 1) {
      // First, try to count from bundle_refs array
      if (matchedRef.is_bundle && matchedRef.bundle_refs && Array.isArray(matchedRef.bundle_refs) && matchedRef.bundle_refs.length > 0) {
        rollCount = matchedRef.bundle_refs.length;
      }
      // If still not available, try to parse from display format
      // Format examples: "REF-1 to REF-4" or "REF-1-4 (Bundle)"
      else if (matchedRef.display) {
        // Check for "to" format: "X-1 to X-4"
        const toMatch = matchedRef.display.match(/\s+to\s+/i);
        if (toMatch) {
          // Split by " to " and extract the last number after the last dash from each part
          const parts = matchedRef.display.split(/\s+to\s+/i);
          if (parts.length === 2) {
            // Extract number after last dash in first part (e.g., "2.8L126JAN19-R09-GT0.9.H0.1-1" -> 1)
            // Match all -(\d+) patterns and take the last one
            const firstPartMatches = parts[0].match(/-(\d+)/g);
            // Extract number after last dash in second part (e.g., "2.8L126JAN19-R09-GT0.9.H0.1-4 [Bag]" -> 4)
            const secondPartMatches = parts[1].match(/-(\d+)/g);
            
            if (firstPartMatches && firstPartMatches.length > 0 && secondPartMatches && secondPartMatches.length > 0) {
              // Get the last match from each part (the roll number)
              const firstLastMatch = firstPartMatches[firstPartMatches.length - 1].match(/-(\d+)/);
              const secondLastMatch = secondPartMatches[secondPartMatches.length - 1].match(/-(\d+)/);
              
              if (firstLastMatch && secondLastMatch) {
                const startNum = parseInt(firstLastMatch[1]);
                const endNum = parseInt(secondLastMatch[1]);
                if (!isNaN(startNum) && !isNaN(endNum) && endNum >= startNum) {
                  rollCount = endNum - startNum + 1;
                }
              }
            }
          }
        }
        // Check for dash-separated range format: "REF-1-4 (Bundle)"
        else {
          const dashRangeMatch = matchedRef.display.match(/-(\d+)-(\d+)\s*\(/i);
          if (dashRangeMatch) {
            const startNum = parseInt(dashRangeMatch[1]);
            const endNum = parseInt(dashRangeMatch[2]);
            if (!isNaN(startNum) && !isNaN(endNum) && endNum >= startNum) {
              rollCount = endNum - startNum + 1;
            }
          }
        }
      }
    }
    
    // Final fallback
    rollCount = rollCount || 1;
    
    if ((currentRollCount + rollCount) > maxRolls) {
      const cncMachineId = document.getElementById('cnc_machine_id');
      const cncMachineCustom = document.getElementById('cnc_machine_custom');
      const machineValue = cncMachineId ? cncMachineId.value : '';
      const customValue = cncMachineCustom ? cncMachineCustom.value.trim() : '';
      
      // Only show custom warning popup for CNC-01/CNC-02, not for Custom
      if (!customValue && (machineValue === 'CNC-01' || machineValue === 'CNC-02')) {
        showWarningPopup(`Warning: You can't add more than 4 rolls in ${machineValue}`);
        return;
      } else if (customValue || maxRolls === 100) {
        // For Custom machine, show standard alert
        alert(`You have added maximum rolls (${maxRolls} rolls allowed for the selected CNC machine). Current: ${currentRollCount} rolls, Attempting to add: ${rollCount} rolls.`);
        return;
      }
    }
    
    // Add the reference
    selectCNCReferenceFromDropdown(matchedRef);
    
    // Clear the selected reference and search input after adding
    selectedCNCReferenceForAdd = null;
    searchInput.value = '';
    
    // Hide dropdown
    const dropdown = document.getElementById('reference_dropdown');
    if (dropdown) {
      dropdown.style.display = 'none';
    }
  } else {
    // Show modern warning popup for invalid reference
    showWarningPopup('The reference number you entered is not in the list. Please search and select a valid reference from the dropdown first.');
  }
}

function updateSelectedCNCReferences() {
  const container = document.getElementById('selected_references');
  const hiddenInput = document.getElementById('reference_number');
  
  if (!container || !hiddenInput) return;
  
  if (!window.selectedCNCReferences || window.selectedCNCReferences.length === 0) {
    container.innerHTML = '<small style="color:#999;">No references selected</small>';
    hiddenInput.value = '';
    // Reset cutting quantity when no references
    const cuttingQtyInput = document.getElementById('cutting_roll_quantity');
    if (cuttingQtyInput) {
      cuttingQtyInput.value = '';
    }
    return;
  }
  
  let html = '<div style="display:flex; flex-direction:column; gap:10px;">';
  let refList = [];
  
  window.selectedCNCReferences.forEach((ref, index) => {
    if (!ref || !ref.reference) return;
    
    refList.push(ref.reference);
    // Determine if it's a bundle: check isBundle flag OR if rollCount > 1
    // Also check display text for "to" pattern (bundle indicator)
    let isBundle = ref.isBundle || false;
    const displayText = ref.display || ref.reference;
    const rollCount = ref.rollCount || 1;
    
    // If rollCount > 1, it's definitely a bundle (even if flag not set)
    if (rollCount > 1) {
      isBundle = true;
    }
    // Also check display text for bundle patterns
    if (!isBundle && displayText) {
      if (displayText.includes(' to ') || displayText.includes('[Bag]') || displayText.match(/-(\d+)\s+to\s+/)) {
        isBundle = true;
      }
    }
    
    // Initialize quantities array if not exists
    // For bundles and individual refs, initialize per-roll quantities
    if (!ref.quantities || !Array.isArray(ref.quantities)) {
      ref.quantities = new Array(rollCount).fill(DEFAULT_CUTTING_QUANTITY_PER_ROLL);
    } else if (ref.quantities.length !== rollCount) {
      // Adjust array size if roll count changed
      const oldLength = ref.quantities.length;
      const currentValues = ref.quantities.slice(0, rollCount);
      ref.quantities = currentValues;
      if (ref.quantities.length < rollCount) {
        // For new rolls, use default value
        ref.quantities.push(...new Array(rollCount - oldLength).fill(DEFAULT_CUTTING_QUANTITY_PER_ROLL));
      }
    } else {
      // If all quantities are 0 (newly added reference), set to default
      const allZero = ref.quantities.every(qty => qty === 0 || qty === null || qty === undefined);
      if (allZero) {
        ref.quantities = new Array(rollCount).fill(DEFAULT_CUTTING_QUANTITY_PER_ROLL);
      }
    }
    
    // Initialize maxQuantities array if not exists
    if (!ref.maxQuantities || !Array.isArray(ref.maxQuantities)) {
      ref.maxQuantities = new Array(rollCount).fill(36); // Default to 36, will be updated from backend
    } else if (ref.maxQuantities.length !== rollCount) {
      // Adjust array size if roll count changed
      const oldLength = ref.maxQuantities.length;
      ref.maxQuantities = ref.maxQuantities.slice(0, rollCount);
      if (ref.maxQuantities.length < rollCount) {
        ref.maxQuantities.push(...new Array(rollCount - oldLength).fill(36));
      }
    }
    
    // Don't show delete button for bundle references
    const deleteButton = isBundle 
      ? '' 
      : `<button type="button" onclick="removeCNCReferenceByIndex(${index})" style="background:#f44336; color:#fff; border:none; border-radius:50%; width:24px; height:24px; cursor:pointer; font-size:14px; line-height:1; flex-shrink:0;">×</button>`;
    
    // Use different background color for bundle references
    const bgColor = isBundle ? '#e8f5e9' : '#e3f2fd';
    
    // Build quantity inputs - check if auto mode is enabled
    let quantityInputsHtml = '<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-left:auto;">';
    quantityInputsHtml += '<small style="color:#666; font-weight:600; white-space:nowrap;">Cutting Qty (pc):</small>';
    
    // If auto mode is enabled, show auto-calculated values (no inputs)
    // HYBRID MODE: Use actual remaining if < 36 (previously used), otherwise 36 (new/unused)
    if (window.autoQuantityMode === true) {
      // Auto mode: Calculate using actual remaining quantities from backend
      let autoTotal = 0;
      const quantitiesArray = [];
      
      if (isBundle) {
        // Bundle: Check each roll's actual remaining
        for (let rollIndex = 0; rollIndex < rollCount; rollIndex++) {
          let rollQty = 36; // Default for new/unused rolls
          
          // Get actual remaining from maxQuantities (fetched from backend)
          if (ref.maxQuantities && Array.isArray(ref.maxQuantities) && ref.maxQuantities[rollIndex] !== undefined) {
            const actualRemaining = parseInt(ref.maxQuantities[rollIndex]) || 0;
            // If actual remaining is > 0 and < 36, use that (previously used roll)
            // If actual remaining is >= 36, use 36 (full capacity)
            if (actualRemaining > 0 && actualRemaining < 36) {
              rollQty = actualRemaining; // Use actual remaining for previously used rolls
            } else if (actualRemaining >= 36) {
              rollQty = 36; // Full capacity available
            }
          }
          
          quantitiesArray.push(rollQty);
          autoTotal += rollQty;
        }
        ref.quantities = quantitiesArray;
      } else {
        // Single roll: Use actual remaining if < 36, otherwise 36
        let rollQty = 36; // Default for new/unused rolls
        
        // Get actual remaining from maxQuantities (fetched from backend)
        if (ref.maxQuantities && Array.isArray(ref.maxQuantities) && ref.maxQuantities[0] !== undefined) {
          const actualRemaining = parseInt(ref.maxQuantities[0]) || 0;
          // If actual remaining is > 0 and < 36, use that (previously used roll)
          // If actual remaining is >= 36, use 36 (full capacity)
          if (actualRemaining > 0 && actualRemaining < 36) {
            rollQty = actualRemaining; // Use actual remaining for previously used rolls
          } else if (actualRemaining >= 36) {
            rollQty = 36; // Full capacity available
          }
        }
        
        quantitiesArray.push(rollQty);
        ref.quantities = quantitiesArray;
        autoTotal = rollQty;
      }
      
      // Build display text showing breakdown
      let displayText = '';
      if (isBundle && quantitiesArray.length > 1) {
        const breakdown = quantitiesArray.map((qty, idx) => `R${idx + 1}:${qty}`).join(', ');
        displayText = `${autoTotal} pc (${breakdown})`;
      } else {
        displayText = `${autoTotal} pc ${quantitiesArray[0] < 36 ? '(actual remaining)' : '(36 per roll)'}`;
      }
      
      quantityInputsHtml += `
        <div style="display:flex; flex-direction:column; gap:2px; min-width:120px;">
          <label style="font-size:11px; color:#666; white-space:nowrap;">Auto: ${autoTotal} pc</label>
          <div style="padding:6px; border:1px solid #28a745; border-radius:4px; background:#d4edda; color:#155724; font-size:13px; font-weight:600; text-align:center; min-width:100px;">
            ${displayText}
          </div>
          <small style="font-size:9px; color:#28a745;">✓ Auto-calculated (hybrid mode)</small>
        </div>
      `;
    } else if (isBundle) {
      // For bundles: Single input with max = 36 * rollCount
      const maxForBundle = 36 * rollCount;
      // For bundles, use the sum of all quantities as the bundle total
      let bundleTotal = 0;
      if (ref.quantities && Array.isArray(ref.quantities)) {
        bundleTotal = ref.quantities.reduce((sum, qty) => sum + (parseInt(qty) || 0), 0);
      }
      // If bundle total is 0, use default (36 * rollCount) and initialize quantities
      if (bundleTotal === 0) {
        bundleTotal = DEFAULT_CUTTING_QUANTITY_PER_ROLL * rollCount;
        // Initialize quantities array with default per roll for bundle
        if (!ref.quantities || !Array.isArray(ref.quantities)) {
          ref.quantities = new Array(rollCount).fill(DEFAULT_CUTTING_QUANTITY_PER_ROLL);
        } else {
          // If quantities exist but sum is 0, set to default
          ref.quantities = new Array(rollCount).fill(DEFAULT_CUTTING_QUANTITY_PER_ROLL);
        }
      }
      
      quantityInputsHtml += `
        <div style="display:flex; flex-direction:column; gap:2px; min-width:120px;">
          <label style="font-size:11px; color:#666; white-space:nowrap;">Total (${rollCount} rolls)</label>
          <input type="number" 
                 step="1" 
                 min="0" 
                 max="${maxForBundle}" 
                 value="${bundleTotal}" 
                 data-ref-index="${index}" 
                 data-is-bundle="true"
                 data-roll-count="${rollCount}"
                 data-max-quantity="${maxForBundle}"
                 oninput="checkBundleQuantityInput(${index}, this)"
                 onchange="updateBundleQuantity(${index}, this.value)"
                 onblur="updateBundleQuantity(${index}, this.value)"
                 onkeydown="if(event.key === 'Enter') { event.preventDefault(); updateBundleQuantity(${index}, this.value); return false; }"
                 placeholder="0"
                 style="padding:6px; border:1px solid #ccc; border-radius:4px; width:100%; font-size:13px; min-width:100px;">
          <small style="font-size:9px; color:#999;">Max: ${maxForBundle} pc (36 × ${rollCount} rolls)</small>
        </div>
      `;
    } else {
      // For individual references: Show per-roll inputs
      for (let rollIndex = 0; rollIndex < rollCount; rollIndex++) {
        // Use backend-calculated max (actual remaining quantity)
        const maxPerRoll = (ref.maxQuantities && ref.maxQuantities[rollIndex] !== undefined) 
          ? ref.maxQuantities[rollIndex] 
          : 36;
        
        // Use default value if quantity is 0 or not set, but don't exceed max
        let quantityValue = ref.quantities[rollIndex];
        if (!quantityValue || quantityValue === 0 || quantityValue > maxPerRoll) {
          // Set to actual remaining (maxPerRoll), not hardcoded 36
          quantityValue = Math.min(maxPerRoll, DEFAULT_CUTTING_QUANTITY_PER_ROLL);
          ref.quantities[rollIndex] = quantityValue;
        }
        quantityInputsHtml += `
          <div style="display:flex; flex-direction:column; gap:2px; min-width:100px;">
            <label style="font-size:11px; color:#666; white-space:nowrap;">Roll ${rollIndex + 1}</label>
            <input type="number" 
                   step="1" 
                   min="0" 
                   max="${maxPerRoll}" 
                   value="${quantityValue}" 
                   data-ref-index="${index}" 
                   data-roll-index="${rollIndex}"
                   data-max-quantity="${maxPerRoll}"
                   oninput="checkQuantityInput(${index}, ${rollIndex}, this)"
                   onchange="updateRollQuantity(${index}, ${rollIndex}, this.value)"
                   onblur="updateRollQuantity(${index}, ${rollIndex}, this.value)"
                   onkeydown="if(event.key === 'Enter') { event.preventDefault(); updateRollQuantity(${index}, ${rollIndex}, this.value); return false; }"
                   placeholder="0"
                   style="padding:6px; border:1px solid #ccc; border-radius:4px; width:100%; font-size:13px; min-width:80px;">
            <small style="font-size:9px; color:#999;">Max: ${maxPerRoll} pc</small>
          </div>
        `;
      }
    }
    
    quantityInputsHtml += '</div>';
    
    html += `<div style="background:${bgColor}; padding:10px; border-radius:8px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <div style="flex:1; min-width:200px;">
        <strong>${escapeHtml(displayText)}</strong>
        ${isBundle ? '<span style="background:#4caf50; color:#fff; padding:2px 6px; border-radius:3px; font-size:10px; margin-left:5px;">BUNDLE</span>' : ''}
        <br><small style="color:#666;">Rolls: ${rollCount}</small>
      </div>
      ${quantityInputsHtml}
      ${deleteButton}
    </div>`;
  });
  
  html += '</div>';
  container.innerHTML = html;
  
  // Store references as comma-separated list (for backward compatibility)
  // Support up to 100 references - ensure no truncation
  const refListStr = refList.join(',');
  if (hiddenInput) {
  hiddenInput.value = refListStr;
    // Debug: Log to console to verify all references are included
    console.log('Storing references:', refList.length, 'references');
    console.log('Reference string length:', refListStr.length);
    console.log('References:', refList);
  }
  
  // Calculate and update total
  calculateTotalCuttingQuantity();
}

// Fetch max cutting quantities from backend for a reference
function fetchMaxCuttingQuantities(refObj) {
  const reference = refObj.reference;
  if (!reference) return;
  
  fetch(`api/get_max_cutting_quantity.php?reference=${encodeURIComponent(reference)}`)
    .then(response => response.json())
    .then(data => {
      if (data.success && data.max_quantities && Array.isArray(data.max_quantities)) {
        // Find the reference object in selectedCNCReferences
        const refIndex = window.selectedCNCReferences.findIndex(r => r.reference === reference);
        if (refIndex >= 0) {
          const ref = window.selectedCNCReferences[refIndex];
          
          // Update max quantities for each roll AND default quantities
          data.max_quantities.forEach((maxQty, index) => {
            if (index < ref.maxQuantities.length) {
              let actualMax = maxQty.max_quantity || 36;
              
              // CRITICAL: For bundles, ALWAYS use 36 per roll (each roll in bundle can have 36)
              if (ref.isBundle && ref.rollCount > 1) {
                actualMax = 36; // Bundles always have 36 per roll
              } else if (actualMax <= 1) {
                // For individual rolls, if max is 0 or 1, check if it's truly used
                // If remaining_quantity from display shows more, use that
                const displayMatch = ref.display.match(/\(Remaining:\s*(\d+)\s*pc\)/i);
                if (displayMatch && displayMatch[1]) {
                  const displayRemaining = parseInt(displayMatch[1]);
                  if (displayRemaining > actualMax) {
                    actualMax = Math.min(displayRemaining, 36);
                  }
                }
                // If still 0 or 1, use default 36 (assume calculation error)
                if (actualMax <= 1) {
                  actualMax = 36;
                }
              }
              
              // Update max quantity
              ref.maxQuantities[index] = actualMax;
              // Also update default quantity to match actual remaining (but don't exceed max)
              if (!ref.quantities[index] || ref.quantities[index] > actualMax) {
                ref.quantities[index] = Math.min(actualMax, DEFAULT_CUTTING_QUANTITY_PER_ROLL);
              }
              
              // Update the input field's max attribute and data attribute
              const inputField = document.querySelector(`input[data-ref-index="${refIndex}"][data-roll-index="${index}"]`);
              if (inputField) {
                inputField.setAttribute('max', actualMax);
                inputField.setAttribute('data-max-quantity', actualMax);
                // Update the small text showing max
                const maxText = inputField.parentElement.querySelector('small');
                if (maxText) {
                  maxText.textContent = `Max: ${actualMax} pc`;
                }
              }
            }
          });
          
          // Update the display to show new max values
          updateSelectedCNCReferences();
          
          // Recalculate total cutting quantity with updated maxQuantities
          calculateTotalCuttingQuantity();
          
          // Also update max cutting quantity to reflect new values
          // Use setTimeout to ensure DOM is updated first
          setTimeout(() => {
            if (typeof updateMaxCuttingQuantity === 'function') {
              updateMaxCuttingQuantity();
            }
          }, 100);
        }
      }
    })
    .catch(error => {
      console.error('Error fetching max cutting quantities:', error);
      // Keep default max of 36 if fetch fails
    });
}

// Check quantity input in real-time and show popup if exceeds max
function checkQuantityInput(refIndex, rollIndex, inputElement) {
  if (!window.selectedCNCReferences || refIndex < 0 || refIndex >= window.selectedCNCReferences.length) {
    return;
  }
  
  const ref = window.selectedCNCReferences[refIndex];
  if (!ref) return;
  
  // Ensure maxQuantities array exists
  if (!ref.maxQuantities || !Array.isArray(ref.maxQuantities)) {
    ref.maxQuantities = new Array(ref.rollCount || 1).fill(36);
  }
  
  // Get current value from input
  let quantityNum = parseInt(inputElement.value);
  if (isNaN(quantityNum) || quantityNum < 0) {
    return; // Don't show popup for invalid/empty values
  }
  
  // Get backend-calculated max quantity for this roll
  // Use the actual remaining quantity from maxQuantities (which should be updated from backend)
  let maxPerRoll = ref.maxQuantities[rollIndex];
  
  // If maxQuantities[rollIndex] is undefined, null, or still 36, try to get from input's data attribute
  if (!maxPerRoll || maxPerRoll === 36) {
    const inputMax = inputElement.getAttribute('data-max-quantity');
    if (inputMax) {
      maxPerRoll = parseInt(inputMax);
    }
  }
  
  // Final fallback to 36 if still not set
  if (!maxPerRoll || isNaN(maxPerRoll)) {
    maxPerRoll = 36;
  }
  
  // Show popup immediately if value exceeds max (but don't clamp yet - let user finish typing)
  if (quantityNum > maxPerRoll) {
    const refDisplay = ref.display || ref.reference;
    const message = `${refDisplay} - Roll ${rollIndex + 1}<br><br><strong>You entered:</strong> ${quantityNum} pieces<br><strong>Maximum allowed:</strong> ${maxPerRoll} pieces`;
    showWarningPopup(message, true);
    
    // Highlight the input field to indicate error
    inputElement.style.borderColor = '#e74c3c';
    inputElement.style.borderWidth = '2px';
  } else {
    // Reset border if value is valid
    inputElement.style.borderColor = '#ccc';
    inputElement.style.borderWidth = '1px';
  }
}

// Update quantity for a specific roll in a reference
function updateRollQuantity(refIndex, rollIndex, quantity) {
  if (!window.selectedCNCReferences || refIndex < 0 || refIndex >= window.selectedCNCReferences.length) {
    return;
  }
  
  const ref = window.selectedCNCReferences[refIndex];
  if (!ref) return;
  
  // Ensure quantities array exists
  if (!ref.quantities || !Array.isArray(ref.quantities)) {
    ref.quantities = new Array(ref.rollCount || 1).fill(DEFAULT_CUTTING_QUANTITY_PER_ROLL);
  }
  
  // Ensure maxQuantities array exists
  if (!ref.maxQuantities || !Array.isArray(ref.maxQuantities)) {
    ref.maxQuantities = new Array(ref.rollCount || 1).fill(36);
  }
  
  // Get the input element to check current value
  const input = document.querySelector(`input[data-ref-index="${refIndex}"][data-roll-index="${rollIndex}"]`);
  if (!input) return;
  
  // Get the actual current value from the input field (more reliable than parameter)
  let quantityNum = parseInt(input.value);
  
  // If quantity is empty or invalid, use 0
  if (isNaN(quantityNum) || quantityNum < 0) {
    quantityNum = 0;
  }
  
  // Get backend-calculated max quantity for this roll
  // First try from ref.maxQuantities, then from input's data attribute, then fallback to 36
  let maxPerRoll = ref.maxQuantities[rollIndex];
  
  // If maxQuantities[rollIndex] is undefined, null, or still 36, try to get from input's data attribute
  if (!maxPerRoll || maxPerRoll === 36) {
    const inputMax = input.getAttribute('data-max-quantity');
    if (inputMax) {
      maxPerRoll = parseInt(inputMax);
    }
  }
  
  // Also check the input's max attribute
  if (!maxPerRoll || maxPerRoll === 36 || isNaN(maxPerRoll)) {
    const inputMaxAttr = input.getAttribute('max');
    if (inputMaxAttr) {
      const parsedMax = parseInt(inputMaxAttr);
      if (!isNaN(parsedMax) && parsedMax !== 36) {
        maxPerRoll = parsedMax;
      }
    }
  }
  
  // Final fallback to 36 if still not set
  if (!maxPerRoll || isNaN(maxPerRoll)) {
    maxPerRoll = 36;
  }
  
  let validQuantity = quantityNum;
  let showPopup = false;
  
  // Check if quantity exceeds backend-calculated maximum
  if (quantityNum > maxPerRoll) {
    validQuantity = maxPerRoll;
    showPopup = true;
    
    // Update input to show clamped value
    if (input.value != validQuantity) {
      input.value = validQuantity;
    }
    
    // Reset border style after clamping
    input.style.borderColor = '#ccc';
    input.style.borderWidth = '1px';
  } else {
    // Reset border if value is valid
    input.style.borderColor = '#ccc';
    input.style.borderWidth = '1px';
  }
  
  // Always update the quantity in the reference object (store the valid quantity)
  ref.quantities[rollIndex] = validQuantity;
  
  // Recalculate total (always update, even if quantity was clamped)
  calculateTotalCuttingQuantity();
  updateSummary();
  
  // Show popup after updating (so total is already calculated)
  if (showPopup) {
    const refDisplay = ref.display || ref.reference;
    const message = `${refDisplay} - Roll ${rollIndex + 1}<br><br><strong>You entered:</strong> ${quantityNum} pieces<br><strong>Maximum allowed:</strong> ${maxPerRoll} pieces`;
    showWarningPopup(message, true);
  }
}

// Calculate total cutting quantity from all rolls
function calculateTotalCuttingQuantity() {
  // If auto mode is enabled, calculate from selected references automatically
  // HYBRID MODE: Use actual remaining if < 36 (previously used), otherwise 36 (new/unused)
  if (window.autoQuantityMode === true) {
    let totalQuantity = 0;
    const referenceQuantities = {}; // Store per-reference quantities for auto mode
    
    if (window.selectedCNCReferences && Array.isArray(window.selectedCNCReferences)) {
      console.log('Auto mode - Calculating total from', window.selectedCNCReferences.length, 'references');
      
      window.selectedCNCReferences.forEach((ref, refIndex) => {
        const rollCount = ref.rollCount || 1;
        let refTotal = 0;
        
        console.log(`Ref ${refIndex + 1}: ${ref.reference || ref.display}, isBundle: ${ref.isBundle}, rollCount: ${rollCount}`);
        console.log(`  - ref.quantities:`, ref.quantities);
        console.log(`  - ref.maxQuantities:`, ref.maxQuantities);
        console.log(`  - ref.bundleRefs:`, ref.bundleRefs);
        console.log(`  - Will calculate for ${rollCount} roll(s)`);
        
        // Build reference_quantities JSON for auto mode using actual remaining quantities
        // PREFER maxQuantities (source of truth from backend), then ref.quantities, then default
        if (ref.isBundle && rollCount > 1) {
          // For bundles, iterate through ALL rolls based on rollCount
          // Use rollCount as the source of truth for how many rolls to calculate
          for (let rollIndex = 0; rollIndex < rollCount; rollIndex++) {
            let rollQty = 36; // Default for new/unused rolls
            const bundleRef = (ref.bundleRefs && Array.isArray(ref.bundleRefs) && ref.bundleRefs[rollIndex]) 
                              ? ref.bundleRefs[rollIndex] 
                              : `${ref.reference}-${rollIndex + 1}`;
            
            // PRIORITY 1: Get actual remaining from maxQuantities (fetched from backend - source of truth)
            if (ref.maxQuantities && Array.isArray(ref.maxQuantities) && ref.maxQuantities[rollIndex] !== undefined) {
              const actualRemaining = parseInt(ref.maxQuantities[rollIndex]) || 0;
              // If actual remaining is > 0 and < 36, use that (previously used roll)
              // If actual remaining is >= 36, use 36 (full capacity)
              if (actualRemaining > 0 && actualRemaining < 36) {
                rollQty = actualRemaining; // Use actual remaining for previously used rolls
              } else if (actualRemaining >= 36) {
                rollQty = 36; // Full capacity available
              }
              console.log(`    Bundle roll ${rollIndex + 1}/${rollCount} (${bundleRef}): Using maxQuantities[${rollIndex}] = ${actualRemaining}, final = ${rollQty}`);
            }
            // PRIORITY 2: Fall back to ref.quantities if maxQuantities not available
            else if (ref.quantities && Array.isArray(ref.quantities) && ref.quantities[rollIndex] !== undefined) {
              rollQty = parseInt(ref.quantities[rollIndex]) || 36;
              console.log(`    Bundle roll ${rollIndex + 1}/${rollCount} (${bundleRef}): Using ref.quantities[${rollIndex}] = ${rollQty} (fallback)`);
            } else {
              console.log(`    Bundle roll ${rollIndex + 1}/${rollCount} (${bundleRef}): Using default = ${rollQty}`);
            }
            
            // Store quantity for this roll (use bundleRef if available, otherwise use index-based key)
            if (ref.bundleRefs && Array.isArray(ref.bundleRefs) && ref.bundleRefs[rollIndex]) {
              referenceQuantities[ref.bundleRefs[rollIndex]] = rollQty;
            } else {
              // Fallback: use reference with index if bundleRefs not available
              referenceQuantities[`${ref.reference}-${rollIndex + 1}`] = rollQty;
            }
            refTotal += rollQty;
          }
          console.log(`  Bundle total (${rollCount} rolls): ${refTotal}`);
        } else {
          // Single roll: Use actual remaining if < 36, otherwise 36
          let rollQty = 36; // Default for new/unused rolls
          
          // PRIORITY 1: Get actual remaining from maxQuantities (fetched from backend - source of truth)
          if (ref.maxQuantities && Array.isArray(ref.maxQuantities) && ref.maxQuantities[0] !== undefined) {
            const actualRemaining = parseInt(ref.maxQuantities[0]) || 0;
            // If actual remaining is > 0 and < 36, use that (previously used roll)
            // If actual remaining is >= 36, use 36 (full capacity)
            if (actualRemaining > 0 && actualRemaining < 36) {
              rollQty = actualRemaining; // Use actual remaining for previously used rolls
            } else if (actualRemaining >= 36) {
              rollQty = 36; // Full capacity available
            }
            console.log(`  Single roll: Using maxQuantities[0] = ${actualRemaining}, final = ${rollQty}`);
          }
          // PRIORITY 2: Fall back to ref.quantities if maxQuantities not available
          else if (ref.quantities && Array.isArray(ref.quantities) && ref.quantities[0] !== undefined) {
            rollQty = parseInt(ref.quantities[0]) || 36;
            console.log(`  Single roll: Using ref.quantities[0] = ${rollQty} (fallback)`);
          } else {
            console.log(`  Single roll: Using default = ${rollQty}`);
          }
          
          referenceQuantities[ref.reference] = rollQty;
          refTotal = rollQty;
          console.log(`  Single roll total: ${refTotal}`);
        }
        
        totalQuantity += refTotal;
        console.log(`  Running total: ${totalQuantity}`);
      });
      
      console.log('Auto mode - Final totalQuantity:', totalQuantity);
    }
    
    // Update the total cutting quantity input
    const cuttingQtyInput = document.getElementById('cutting_roll_quantity');
    const refQuantitiesInput = document.getElementById('reference_quantities');
    
    console.log('Auto mode - Setting cutting_roll_quantity to:', totalQuantity);
    
    if (cuttingQtyInput) {
      cuttingQtyInput.value = totalQuantity;
      cuttingQtyInput.readOnly = true; // Make it read-only in auto mode
      cuttingQtyInput.style.background = '#e8f5e9';
      cuttingQtyInput.style.cursor = 'not-allowed';
      
      // Verify it was set correctly
      console.log('Auto mode - cutting_roll_quantity value after setting:', cuttingQtyInput.value);
      
      // Force update by triggering input event
      cuttingQtyInput.dispatchEvent(new Event('input', { bubbles: true }));
    } else {
      console.error('Auto mode - cutting_roll_quantity input not found!');
    }
    
    // CRITICAL: Populate reference_quantities in auto mode
    if (refQuantitiesInput) {
      try {
        const jsonString = JSON.stringify(referenceQuantities);
        refQuantitiesInput.value = jsonString;
        
        // Debug: Log to verify all references are included
        const refCount = Object.keys(referenceQuantities).length;
        console.log('Auto mode - Storing reference_quantities:', refCount, 'references with quantities');
        console.log('Auto mode - JSON string length:', jsonString.length);
        console.log('Auto mode - Reference quantities object:', referenceQuantities);
        console.log('Auto mode - JSON string:', jsonString);
        
        // Verify it's valid JSON
        const testParse = JSON.parse(jsonString);
        if (Object.keys(testParse).length !== refCount) {
          console.error('WARNING: JSON parse lost some references in auto mode!');
        }
      } catch (e) {
        console.error('Error stringifying reference quantities in auto mode:', e);
        console.error('Reference quantities object:', referenceQuantities);
        refQuantitiesInput.value = '';
      }
    }
    
    // Update the display text
    const totalDisplay = document.getElementById('total_cutting_quantity_display');
    if (totalDisplay) {
      totalDisplay.textContent = `${totalQuantity} pieces (Auto-calculated: 36 per roll)`;
    }
    
    return;
  }
  
  // Manual mode: Calculate from user inputs
  if (!window.selectedCNCReferences || window.selectedCNCReferences.length === 0) {
    const cuttingQtyInput = document.getElementById('cutting_roll_quantity');
    const refQuantitiesInput = document.getElementById('reference_quantities');
    if (cuttingQtyInput) {
      cuttingQtyInput.value = '';
    }
    if (refQuantitiesInput) {
      refQuantitiesInput.value = '';
    }
    return;
  }
  
  let total = 0;
  const referenceQuantities = {}; // Store per-reference quantities
  
  window.selectedCNCReferences.forEach((ref) => {
    if (ref.quantities && Array.isArray(ref.quantities)) {
      // For bundles, the quantity is stored as a single total value
      if (ref.isBundle && ref.bundleRefs && Array.isArray(ref.bundleRefs) && ref.bundleRefs.length > 0) {
        // For bundles, sum all quantities to get total bundle quantity
        const bundleTotal = ref.quantities.reduce((sum, qty) => sum + (parseInt(qty) || 0), 0);
        total += bundleTotal;
        
        // Distribute bundle total evenly across all rolls in the bundle
        // Each roll gets bundleTotal / rollCount (rounded)
        if (bundleTotal > 0) {
          const qtyPerRoll = Math.floor(bundleTotal / ref.bundleRefs.length);
          const remainder = bundleTotal % ref.bundleRefs.length;
          
          ref.bundleRefs.forEach((bundleRef, rollIndex) => {
            // Distribute evenly, with remainder going to first rolls
            const rollQty = qtyPerRoll + (rollIndex < remainder ? 1 : 0);
            if (!referenceQuantities[bundleRef]) {
              referenceQuantities[bundleRef] = 0;
            }
            referenceQuantities[bundleRef] += rollQty;
          });
        }
      } else {
        // For individual references, sum all roll quantities
        let refTotal = 0;
        ref.quantities.forEach((qty) => {
          const qtyNum = parseInt(qty) || 0;
          total += qtyNum;
          refTotal += qtyNum;
        });
        if (refTotal > 0) {
          referenceQuantities[ref.reference] = refTotal;
        }
      }
    }
  });
  
  // Update cutting roll quantity input field (auto-calculated)
  const cuttingQtyInput = document.getElementById('cutting_roll_quantity');
  const refQuantitiesInput = document.getElementById('reference_quantities');
  
  if (cuttingQtyInput) {
    cuttingQtyInput.value = total;
    // Trigger validation
    if (typeof validateCuttingQuantity === 'function') {
      try {
        validateCuttingQuantity();
      } catch (e) {
        console.error('Error in validateCuttingQuantity:', e);
      }
    }
  }
  
  if (refQuantitiesInput) {
    try {
      const jsonString = JSON.stringify(referenceQuantities);
      refQuantitiesInput.value = jsonString;
      
      // Debug: Log to verify all references are included
      const refCount = Object.keys(referenceQuantities).length;
      console.log('Manual mode - Storing reference_quantities:', refCount, 'references with quantities');
      console.log('Manual mode - JSON string length:', jsonString.length);
      console.log('Manual mode - Reference quantities object:', referenceQuantities);
      console.log('Manual mode - JSON string:', jsonString);
      
      // Verify it's valid JSON
      const testParse = JSON.parse(jsonString);
      if (Object.keys(testParse).length !== refCount) {
        console.error('WARNING: JSON parse lost some references!');
      }
    } catch (e) {
      console.error('Error stringifying reference quantities:', e);
      console.error('Reference quantities object:', referenceQuantities);
      refQuantitiesInput.value = '';
    }
  }
  
  // Update summary
  if (typeof updateSummary === 'function') {
    try {
      updateSummary();
    } catch (e) {
      console.error('Error in updateSummary:', e);
    }
  }
}

// Check bundle quantity input in real-time and show popup if exceeds max
function checkBundleQuantityInput(refIndex, inputElement) {
  if (!window.selectedCNCReferences || refIndex < 0 || refIndex >= window.selectedCNCReferences.length) {
    return;
  }
  
  const ref = window.selectedCNCReferences[refIndex];
  if (!ref || !ref.isBundle) return;
  
  // Get current value from input
  let quantityNum = parseInt(inputElement.value);
  if (isNaN(quantityNum) || quantityNum < 0) {
    return; // Don't show popup for invalid/empty values
  }
  
  // Max for bundle = 36 * rollCount
  const maxForBundle = 36 * (ref.rollCount || 1);
  
  // Show popup immediately if value exceeds max
  if (quantityNum > maxForBundle) {
    const refDisplay = ref.display || ref.reference;
    const message = `${refDisplay}<br><br><strong>You entered:</strong> ${quantityNum} pieces<br><strong>Maximum allowed:</strong> ${maxForBundle} pieces (36 × ${ref.rollCount} rolls)`;
    showWarningPopup(message, true);
    
    // Highlight the input field to indicate error
    inputElement.style.borderColor = '#e74c3c';
    inputElement.style.borderWidth = '2px';
  } else {
    // Reset border if value is valid
    inputElement.style.borderColor = '#ccc';
    inputElement.style.borderWidth = '1px';
  }
}

// Update quantity for a bundle (single total input)
function updateBundleQuantity(refIndex, quantity) {
  if (!window.selectedCNCReferences || refIndex < 0 || refIndex >= window.selectedCNCReferences.length) {
    return;
  }
  
  const ref = window.selectedCNCReferences[refIndex];
  if (!ref || !ref.isBundle) return;
  
  // Get the input element
  const input = document.querySelector(`input[data-ref-index="${refIndex}"][data-is-bundle="true"]`);
  if (!input) return;
  
  let quantityNum = parseInt(quantity);
  if (isNaN(quantityNum) || quantityNum < 0) {
    quantityNum = 0;
  }
  
  // Max for bundle = 36 * rollCount
  const maxForBundle = 36 * (ref.rollCount || 1);
  let validQuantity = quantityNum;
  let showPopup = false;
  
  // Check if quantity exceeds max
  if (quantityNum > maxForBundle) {
    validQuantity = maxForBundle;
    showPopup = true;
    if (input.value != validQuantity) {
      input.value = validQuantity;
    }
    input.style.borderColor = '#ccc';
    input.style.borderWidth = '1px';
  } else {
    input.style.borderColor = '#ccc';
    input.style.borderWidth = '1px';
  }
  
  // For bundles, distribute the total quantity evenly across all rolls
  // Store the total in quantities array (sum of all rolls)
  if (!ref.quantities || !Array.isArray(ref.quantities)) {
    ref.quantities = new Array(ref.rollCount || 1).fill(0);
  }
  
  // Distribute evenly: each roll gets validQuantity / rollCount
  const qtyPerRoll = Math.floor(validQuantity / ref.rollCount);
  const remainder = validQuantity % ref.rollCount;
  
  for (let i = 0; i < ref.rollCount; i++) {
    // Distribute evenly, with remainder going to first rolls
    ref.quantities[i] = qtyPerRoll + (i < remainder ? 1 : 0);
  }
  
  // Update total and summary
  calculateTotalCuttingQuantity();
  updateSummary();
  
  // Show popup if exceeded max
  if (showPopup) {
    const refDisplay = ref.display || ref.reference;
    const message = `${refDisplay}<br><br><strong>You entered:</strong> ${quantityNum} pieces<br><strong>Maximum allowed:</strong> ${maxForBundle} pieces (36 × ${ref.rollCount} rolls)`;
    showWarningPopup(message, true);
  }
}

// Make functions globally available
window.checkQuantityInput = checkQuantityInput;
window.updateRollQuantity = updateRollQuantity;
window.checkBundleQuantityInput = checkBundleQuantityInput;
window.updateBundleQuantity = updateBundleQuantity;
window.calculateTotalCuttingQuantity = calculateTotalCuttingQuantity;

function removeCNCReferenceByIndex(index) {
  if (!window.selectedCNCReferences || index < 0 || index >= window.selectedCNCReferences.length) {
    return;
  }
  
  window.selectedCNCReferences.splice(index, 1);
  updateSelectedCNCReferences();
  updateRollCountInfo();
  updateMaxCuttingQuantity(); // Update max cutting quantity when rolls are removed
  
  // Keep the same batch number even if references are removed
  // ONE batch number per entry (for all 4 rolls in CNC-01/02 or all 100 rolls in Custom)
  updateSummary();
}

function updateRollCountInfo() {
  const infoElement = document.getElementById('roll_count_info');
  if (!infoElement) return;
  
  const currentRollCount = getCurrentTotalRollCount();
  const maxRolls = getMaxRolls();
  
  if (currentRollCount > 0) {
    infoElement.textContent = `Total Rolls: ${currentRollCount} / ${maxRolls} (Maximum: ${maxRolls} rolls)`;
    if (currentRollCount >= maxRolls) {
      infoElement.style.color = '#e74c3c';
    } else if (currentRollCount >= maxRolls - 1) {
      infoElement.style.color = '#f39c12';
    } else {
      infoElement.style.color = '#27ae60';
    }
  } else {
    infoElement.textContent = '';
  }
  
  // Update maximum cutting quantity based on selected rolls
  updateMaxCuttingQuantity();
}

// Calculate and update maximum cutting quantity based on selected rolls
// Formula: 1 roll = 36 pieces, so total_rolls * 36 = max cutting quantity
function updateMaxCuttingQuantity() {
  const cuttingQuantityInput = document.getElementById('cutting_roll_quantity');
  const maxInfoElement = document.getElementById('max_cutting_quantity_info');
  const warningElement = document.getElementById('cutting_quantity_warning');
  
  if (!cuttingQuantityInput) return;
  
  const currentRollCount = getCurrentTotalRollCount();
  
  if (currentRollCount > 0) {
    // Calculate max cutting quantity from actual remaining quantities of all selected references
    // Sum up the max quantities from each reference's rolls
    let maxCuttingQuantity = 0;
    if (window.selectedCNCReferences && Array.isArray(window.selectedCNCReferences)) {
      window.selectedCNCReferences.forEach(ref => {
        // For bundles, ALWAYS use rollCount * 36 (each roll can have 36 pieces)
        if (ref.isBundle && ref.rollCount > 1) {
          const bundleMax = ref.rollCount * 36;
          maxCuttingQuantity += bundleMax;
        } else if (ref.maxQuantities && Array.isArray(ref.maxQuantities)) {
          // For individual rolls, sum all max quantities
          let refMax = ref.maxQuantities.reduce((sum, maxQty) => {
            const qty = parseInt(maxQty) || 0;
            // CRITICAL: Ensure each roll's max is never 0 or 1 (unless truly fully used)
            // If qty is 0 or 1, it's likely a calculation error - use 36 per roll
            if (qty <= 1) {
              return sum + 36; // Use default 36 per roll
            }
            return sum + qty;
          }, 0);
          
          // Ensure single roll has at least 36 if not fully used
          if (ref.rollCount === 1 && refMax <= 1) {
            refMax = 36;
          }
          
          maxCuttingQuantity += refMax;
        } else {
          // Fallback: if maxQuantities not available, use default 36 per roll
          maxCuttingQuantity += (ref.rollCount || 1) * 36;
        }
      });
    }
    
    // CRITICAL: Ensure maxCuttingQuantity is ALWAYS at least 36 * rollCount
    // This prevents the popup from showing "1 pieces" or "0 pieces per roll"
    const minimumExpected = currentRollCount * 36;
    if (maxCuttingQuantity < minimumExpected || maxCuttingQuantity <= 1) {
      maxCuttingQuantity = minimumExpected;
    }
    
    // Set max attribute on input
    cuttingQuantityInput.max = maxCuttingQuantity;
    
    // Update help text
    if (maxInfoElement) {
      const perRollAvg = currentRollCount > 0 ? Math.floor(maxCuttingQuantity / currentRollCount) : 36;
      maxInfoElement.textContent = `Maximum cutting quantity: ${maxCuttingQuantity} pieces (${currentRollCount} roll(s) × ${perRollAvg} pieces per roll)`;
      maxInfoElement.style.display = 'block';
    }
    
    // Validate current value if it exceeds max
    const currentValue = parseInt(cuttingQuantityInput.value) || 0;
    if (currentValue > maxCuttingQuantity) {
      cuttingQuantityInput.value = maxCuttingQuantity;
      if (warningElement) {
        warningElement.textContent = `⚠️ Quantity adjusted to maximum: ${maxCuttingQuantity} pieces`;
        warningElement.style.display = 'block';
      }
    } else {
      if (warningElement) {
        warningElement.style.display = 'none';
      }
    }
  } else {
    // No rolls selected - reset to default
    cuttingQuantityInput.max = 1;
    if (maxInfoElement) {
      maxInfoElement.textContent = '';
      maxInfoElement.style.display = 'none';
    }
    if (warningElement) {
      warningElement.style.display = 'none';
    }
  }
}

// Validate cutting quantity input
let lastPopupValue = null; // Track last value that triggered popup to avoid repeated popups

function validateCuttingQuantity() {
  const cuttingQuantityInput = document.getElementById('cutting_roll_quantity');
  const warningElement = document.getElementById('cutting_quantity_warning');
  
  if (!cuttingQuantityInput) return;
  
  const currentValue = parseInt(cuttingQuantityInput.value) || 0;
  let maxValue = parseInt(cuttingQuantityInput.max) || 1;
  const currentRollCount = getCurrentTotalRollCount();
  
  // CRITICAL: If maxValue is 0 or 1 and we have rolls, it's wrong - fix it
  if (currentRollCount > 0 && (maxValue <= 1 || maxValue < currentRollCount)) {
    maxValue = currentRollCount * 36;
    cuttingQuantityInput.max = maxValue;
  }
  
  if (currentRollCount > 0) {
    if (currentValue > maxValue) {
      // Show warning text
      if (warningElement) {
        warningElement.textContent = `⚠️ Maximum cutting quantity is ${maxValue} pieces (${currentRollCount} roll(s) × 36 pieces per roll)`;
        warningElement.style.display = 'block';
      }
      cuttingQuantityInput.setCustomValidity(`Maximum cutting quantity is ${maxValue} pieces`);
      cuttingQuantityInput.style.borderColor = '#e74c3c';
      
      // Show modern popup notification (only once per value to avoid spam)
      if (currentValue !== lastPopupValue && typeof showWarningPopup === 'function') {
        lastPopupValue = currentValue;
        // Format message with HTML for better readability
        // Calculate actual per-roll max (may not be 36 if remaining is less)
        const perRollMax = currentRollCount > 0 ? Math.floor(maxValue / currentRollCount) : 36;
        const message = `Maximum cutting quantity exceeded!<br><br><strong>You entered:</strong> ${currentValue} pieces<br><strong>Maximum allowed:</strong> ${maxValue} pieces<br><br><small>Calculation: ${currentRollCount} roll(s) × ${perRollMax} pieces per roll = ${maxValue} pieces</small>`;
        showWarningPopup(message, true); // true = use HTML
        
        // Auto-close popup after 5 seconds if user doesn't close it manually
        setTimeout(function() {
          if (typeof closeWarningPopup === 'function') {
            closeWarningPopup();
          }
        }, 5000);
      }
      
      return false;
    } else {
      // Reset popup tracking when value is valid
      lastPopupValue = null;
      
      // Close popup if it's open (user corrected the value)
      if (typeof closeWarningPopup === 'function') {
        const popup = document.getElementById('warningPopup');
        if (popup && popup.classList.contains('show')) {
          // Only close if it's a quantity-related warning (check message content)
          const messageEl = document.getElementById('warningMessage');
          if (messageEl && messageEl.textContent.includes('cutting quantity')) {
            closeWarningPopup();
          }
        }
      }
      
      if (warningElement) {
        warningElement.style.display = 'none';
      }
      cuttingQuantityInput.setCustomValidity('');
      cuttingQuantityInput.style.borderColor = '#ccc';
      return true;
    }
  }
  
  return true;
}

// Update date/time and shift display
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
  const shift = (h >= 8 && h <= 19) ? "Day" : "Night";
  document.getElementById("shiftBanner").innerText = "Shift: " + shift;
  document.getElementById("shift").value = shift;
  
  updateSummary();
}

// Note: selectCNCMachine already calls updateRollCountInfo() and filterCNCReferences() 
// through applyMachineSelection(), so no override is needed

// Bind search behavior: case-insensitive "contains" matching is handled inside filterCNCReferences()
document.addEventListener('DOMContentLoaded', function () {
  const searchInput = document.getElementById('reference_search');
  const dropdown = document.getElementById('reference_dropdown');
  if (!searchInput || !dropdown) return;

  // Show + filter when user focuses/clicks into the input
  searchInput.addEventListener('focus', function () {
    // Ensure data is loaded first
    if (cncReferencesData.length === 0) {
      const cncMachineId = document.getElementById('cnc_machine_id');
      const cncMachineCustom = document.getElementById('cnc_machine_custom');
      const machineValue = cncMachineId ? cncMachineId.value : '';
      const customValue = cncMachineCustom ? cncMachineCustom.value.trim() : '';
      if (machineValue || customValue) {
        loadCNCReferences();
      }
    }
    showCNCReferenceDropdown();   // ensures dropdown is populated + visible
    filterCNCReferences();        // applies current search term
  });

  // Filter on every keystroke (supports upper/lower/mixed case, any substring)
  searchInput.addEventListener('input', function () {
    // Ensure data is loaded first
    if (cncReferencesData.length === 0) {
      const cncMachineId = document.getElementById('cnc_machine_id');
      const cncMachineCustom = document.getElementById('cnc_machine_custom');
      const machineValue = cncMachineId ? cncMachineId.value : '';
      const customValue = cncMachineCustom ? cncMachineCustom.value.trim() : '';
      if (machineValue || customValue) {
        loadCNCReferences();
        // Wait a bit for data to load, then filter
        setTimeout(function() {
          showCNCReferenceDropdown();
          filterCNCReferences();
        }, 100);
        return;
      }
    }
    showCNCReferenceDropdown();
    filterCNCReferences();
  });

  // Hide when clicking outside
  document.addEventListener('click', function (e) {
    // Check if click is outside both input and dropdown
    if (e.target !== searchInput && 
        !searchInput.contains(e.target) && 
        !dropdown.contains(e.target)) {
      dropdown.style.display = 'none';
    }
  });
});
</script>
</body>
</html>
