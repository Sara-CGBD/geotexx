<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'php_error.log');
error_reporting(E_ALL);

session_start();
require_once 'security_config.php';

// Handle POST request (form submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Security/session checks for POST
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

// Validate required fields
    $required = ['transfer_id','date_time','operator_id','from_location','to_location'];
foreach ($required as $key) {
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        header('Location: ../forms/roll_transfer_entry.php?error=' . urlencode('Missing field: ' . $key));
        exit;
    }
}

$transferId = (int)$_POST['transfer_id'];
    $dateTime = $_POST['date_time']; // Get date_time first, needed for trip calculation
    // Calculate trip number based on submission time (8 AM to 8 AM cycle)
    $trip = 1;
    try {
        $conn = SecurityConfig::getConnection();
        $hasRollTransfer = $conn->query("SHOW TABLES LIKE 'roll_transfer'");
        if ($hasRollTransfer && $hasRollTransfer->num_rows > 0) {
            $hasTripCol = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'trip'");
            if ($hasTripCol && $hasTripCol->num_rows > 0) {
                date_default_timezone_set('Asia/Dhaka');
                $now = new DateTime($dateTime);
                $currentHour = (int)$now->format('H');
                $currentMinute = (int)$now->format('i');
                $cycleStart = clone $now;
                if ($currentHour < 8 || ($currentHour == 8 && $currentMinute == 0)) {
                    $cycleStart->modify('-1 day');
                }
                $cycleStart->setTime(8, 0, 0);
                $cycleEnd = clone $cycleStart;
                $cycleEnd->modify('+1 day');
                $cycleEnd->setTime(7, 59, 59);
                $startStr = $cycleStart->format('Y-m-d H:i:s');
                $endStr = $cycleEnd->format('Y-m-d H:i:s');
                $tripRes = $conn->prepare("SELECT MAX(trip) AS max_trip FROM roll_transfer WHERE date_time >= ? AND date_time <= ?");
                if ($tripRes) {
                    $tripRes->bind_param('ss', $startStr, $endStr);
                    $tripRes->execute();
                    $tripResult = $tripRes->get_result();
                    if ($tripResult && ($tripRow = $tripResult->fetch_assoc())) {
                        $maxTrip = (int)($tripRow['max_trip'] ?? 0);
                        $trip = $maxTrip + 1;
                    }
                    $tripRes->close();
                }
            }
        }
    } catch (Exception $e) {
        error_log('Trip calculation error: ' . $e->getMessage());
        $trip = 1;
    }
    
$operatorId = (int)$_POST['operator_id'];
$operatorName = isset($_POST['operator_name']) ? substr(trim($_POST['operator_name']), 0, 100) : '';
$fromLocation = substr(trim($_POST['from_location']), 0, 100);
$toLocation = substr(trim($_POST['to_location']), 0, 100);

    $referenceAmounts = [];
    if (isset($_POST['reference_amounts']) && !empty($_POST['reference_amounts'])) {
        $referenceAmounts = json_decode($_POST['reference_amounts'], true);
        if (!is_array($referenceAmounts)) {
            $referenceAmounts = [];
        }
    }

    $referenceNumber = isset($_POST['reference_number']) ? trim($_POST['reference_number']) : '';
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;

$driverId = null;
$driverName = null;
if (isset($_POST['driver_id']) && !empty($_POST['driver_id'])) {
    $driverId = (int)$_POST['driver_id'];
} elseif (isset($_POST['driver_name']) && !empty($_POST['driver_name'])) {
    $driverName = substr(trim($_POST['driver_name']), 0, 100);
}

try {
    $conn = SecurityConfig::getConnection();
    $createTable = "CREATE TABLE IF NOT EXISTS roll_transfer (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transfer_id INT,
            trip INT DEFAULT 1,
        date_time DATETIME NOT NULL,
        operator_id INT NOT NULL,
        operator_name VARCHAR(100) DEFAULT NULL,
        reference_number VARCHAR(200) NOT NULL,
        driver_id INT DEFAULT NULL,
        driver_name VARCHAR(100) DEFAULT NULL,
            amount_kg DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        from_location VARCHAR(100) NOT NULL,
        to_location VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_deleted TINYINT(1) DEFAULT 0,
        who_did VARCHAR(100) DEFAULT NULL,
            deleted_at DATETIME DEFAULT NULL,
            INDEX idx_transfer_id (transfer_id)
    )";
    $conn->query($createTable);
    
        $checkUnique = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS 
                                      WHERE TABLE_SCHEMA = DATABASE() 
                                      AND TABLE_NAME = 'roll_transfer' 
                                      AND CONSTRAINT_NAME = 'uk_transfer_id' 
                                      AND CONSTRAINT_TYPE = 'UNIQUE'");
        if ($checkUnique && $checkUnique->num_rows > 0) {
            $conn->query("ALTER TABLE roll_transfer DROP INDEX uk_transfer_id");
        }
        
        $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS trip INT DEFAULT 1 AFTER transfer_id");
    $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS operator_name VARCHAR(100) DEFAULT NULL AFTER operator_id");
    $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS reference_number VARCHAR(200) AFTER operator_name");
    $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS from_location VARCHAR(100) DEFAULT ''");
    $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS to_location VARCHAR(100) DEFAULT ''");
    $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS driver_id INT DEFAULT NULL");
    $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS driver_name VARCHAR(100) DEFAULT NULL");

        $checkIndex = $conn->query("SHOW INDEX FROM roll_transfer WHERE Key_name = 'idx_transfer_id'");
        if (!$checkIndex || $checkIndex->num_rows == 0) {
            $conn->query("ALTER TABLE roll_transfer ADD INDEX idx_transfer_id (transfer_id)");
        }

        $checkColumn = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'amount_kg'");
        if (!$checkColumn || $checkColumn->num_rows == 0) {
            $checkAmount = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'amount'");
            if ($checkAmount && $checkAmount->num_rows > 0) {
                $conn->query("ALTER TABLE roll_transfer CHANGE COLUMN amount amount_kg DECIMAL(10,2) NOT NULL DEFAULT 0.00");
            } else {
                $conn->query("ALTER TABLE roll_transfer ADD COLUMN IF NOT EXISTS amount_kg DECIMAL(10,2) NOT NULL DEFAULT 0.00");
            }
        }
        
    $stmt = $conn->prepare("INSERT INTO roll_transfer (
            transfer_id, trip, date_time, operator_id, operator_name, reference_number, 
        driver_id, driver_name, amount_kg, from_location, to_location
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

        if (!empty($referenceAmounts)) {
            $insertCount = 0;
            foreach ($referenceAmounts as $refData) {
                $refNum = isset($refData['ref']) ? substr(trim($refData['ref']), 0, 200) : '';
                $refAmount = isset($refData['amount']) ? (float)$refData['amount'] : 0;
                
                if (empty($refNum) || $refAmount <= 0) {
                    continue;
                }
                
    $stmt->bind_param(
                    'iisissisdss',
                    $transferId, $trip, $dateTime, $operatorId, $operatorName,
                    $refNum, $driverId, $driverName, $refAmount, $fromLocation, $toLocation
                );
                
                if ($stmt->execute()) {
                    $insertCount++;
                } else {
                    throw new Exception('Execute failed for reference ' . $refNum . ': ' . $stmt->error);
                }
            }
            
            if ($insertCount > 0) {
                header('Location: ../forms/roll_transfer_entry.php?success=' . urlencode("Roll transfer saved with Transfer ID: $transferId ($insertCount reference(s) added)"));
                exit;
            } else {
                throw new Exception('No valid references to insert');
            }
        } else {
            if (empty($referenceNumber)) {
                throw new Exception('No reference number provided');
            }
            
            $stmt->bind_param(
                'iisissisdss',
                $transferId, $trip, $dateTime, $operatorId, $operatorName,
                $referenceNumber, $driverId, $driverName, $amount, $fromLocation, $toLocation
    );

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    header('Location: ../forms/roll_transfer_entry.php?success=' . urlencode("Roll transfer saved with Transfer ID: $transferId"));
    exit;
        }
} catch (Throwable $e) {
    header('Location: ../forms/roll_transfer_entry.php?error=' . urlencode($e->getMessage()));
    exit;
}
}
// Security/session checks for GET (form display)
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

// Fetch reference numbers from roll_entry that have passed QC testing and are approved and routed to FG or Bag Production (schema-aware)
$referenceNumbers = [];

// Check if is_deleted column exists in roll_transfer table FIRST
$hasIsDeleted = false;
$checkColumn = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'is_deleted'");
if ($checkColumn && $checkColumn->num_rows > 0) {
    $hasIsDeleted = true;
}

// Check if parent_reference column exists in roll_transfer table
$hasParentReference = false;
$checkParentRef = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'parent_reference'");
if ($checkParentRef && $checkParentRef->num_rows > 0) {
    $hasParentReference = true;
}

// Build the LEFT JOIN condition based on whether is_deleted column exists
$deletedCondition = $hasIsDeleted ? "AND (rt.is_deleted = 0 OR rt.is_deleted IS NULL)" : "";

$hasRollDest = $conn->query("SHOW COLUMNS FROM qc_test_orders LIKE 'roll_destination'");
// Include both FG and Bag Production routed references
// Also include references that have been partially transferred (even if not yet routed)
// Use the same reference matching condition as in the SUM calculation
$existsCondition = $hasParentReference 
    ? "(rt2.reference_number = re.reference_number OR rt2.parent_reference = re.reference_number)"
    : "rt2.reference_number = re.reference_number";
$rollDestFilter = ($hasRollDest && $hasRollDest->num_rows > 0) 
    ? "AND (qto.roll_destination IN ('fg_production', 'bag_production') OR EXISTS (SELECT 1 FROM roll_transfer rt2 WHERE {$existsCondition} {$deletedCondition} LIMIT 1))" 
    : "";

// Fetch references with their available amounts
// This query calculates remaining quantity by subtracting all transferred amounts from total weight
// IMPORTANT: We need to ensure ALL transfers are counted, even if reference was partially transferred
// Modified query to use parent_reference for matching (if column exists)
$subqueryDeletedCondition = $hasIsDeleted ? "AND (rt2.is_deleted = 0 OR rt2.is_deleted IS NULL)" : "";
// Build the WHERE condition for matching reference_number or parent_reference
$referenceMatchCondition = $hasParentReference 
    ? "(rt2.reference_number = re.reference_number OR rt2.parent_reference = re.reference_number)"
    : "rt2.reference_number = re.reference_number";

$res = $conn->query("
    SELECT 
        re.reference_number,
        re.total_weight,
        COALESCE((
            SELECT SUM(rt2.amount_kg) 
            FROM roll_transfer rt2 
            WHERE {$referenceMatchCondition}
            {$subqueryDeletedCondition}
        ), 0) as transferred_amount,
        (re.total_weight - COALESCE((
            SELECT SUM(rt2.amount_kg) 
            FROM roll_transfer rt2 
            WHERE {$referenceMatchCondition}
            {$subqueryDeletedCondition}
        ), 0)) as available_amount,
        qto.roll_destination
    FROM roll_entry re
    INNER JOIN qc_test_orders qto ON qto.sample_reference_id = re.reference_number
    WHERE re.reference_number IS NOT NULL 
    AND re.reference_number != ''
    AND qto.status = 'approved'
    {$rollDestFilter}
    HAVING available_amount > 0.001
    ORDER BY re.reference_number ASC
");
$allReferences = [];
if ($res) {
    while ($r = $res->fetch_assoc()) {
        if ($r['reference_number'] && !empty(trim($r['reference_number']))) {
            // Ensure numeric values are properly cast
            $r['total_weight'] = (float)$r['total_weight'];
            $r['transferred_amount'] = (float)$r['transferred_amount'];
            $r['available_amount'] = (float)$r['available_amount'];
            
            // Recalculate available amount to ensure accuracy
            // Use > 0.001 to handle floating point precision issues
            $calculated_available = $r['total_weight'] - $r['transferred_amount'];
            
            // Debug: Log if transferred amount seems incorrect
            if ($r['transferred_amount'] > 0 && $calculated_available < 0) {
                error_log("Roll transfer calculation issue for " . $r['reference_number'] . ": total=" . $r['total_weight'] . ", transferred=" . $r['transferred_amount'] . ", available=" . $calculated_available);
            }
            
            // Include if there's any remaining amount (use > 0.001 to handle floating point precision)
            if ($calculated_available > 0.001 && $r['total_weight'] > 0) {
                $r['available_amount'] = $calculated_available;
                $allReferences[] = $r;
            }
        }
    }
} else {
    // Log query error for debugging
    error_log("Roll transfer entry query error: " . $conn->error);
}

// Group references into bundles (sequential references with same base pattern)
// Pattern: references like "4.0L226JAN05-R01-H0.1-1", "4.0L226JAN05-R01-H0.1-2", etc.
$bundles = [];
$individualRefs = [];
$processedRefs = [];

foreach ($allReferences as $ref) {
    $refNum = $ref['reference_number'];
    
    // Skip if already processed as part of a bundle
    if (in_array($refNum, $processedRefs)) {
        continue;
    }
    
    // Check if this reference matches bundle pattern (ends with -N where N is a number)
    if (preg_match('/^(.+)-(\d+)$/', $refNum, $matches)) {
        $baseRef = $matches[1];
        $rollNum = intval($matches[2]);
        
        // Find all references with the same base
        $bundleRefs = [];
        foreach ($allReferences as $otherRef) {
            $otherRefNum = $otherRef['reference_number'];
            if (preg_match('/^' . preg_quote($baseRef, '/') . '-(\d+)$/', $otherRefNum, $otherMatches)) {
                $bundleRefs[] = $otherRef;
                $processedRefs[] = $otherRefNum;
            }
        }
        
        // If we found 2 or more references with the same base, it's a bundle
        if (count($bundleRefs) >= 2) {
            // Sort by roll number
            usort($bundleRefs, function($a, $b) {
                preg_match('/-(\d+)$/', $a['reference_number'], $ma);
                preg_match('/-(\d+)$/', $b['reference_number'], $mb);
                return intval($ma[1] ?? 0) - intval($mb[1] ?? 0);
            });
            
            // Create bundle entry
            $totalWeight = array_sum(array_column($bundleRefs, 'total_weight'));
            $totalTransferred = array_sum(array_column($bundleRefs, 'transferred_amount'));
            $totalAvailable = $totalWeight - $totalTransferred;
            
            // Use the first reference's destination (they should all be the same)
            $bundleDestination = $bundleRefs[0]['roll_destination'] ?? '';
            
            $bundles[] = [
                'is_bundle' => true,
                'base_reference' => $baseRef,
                'bundle_count' => count($bundleRefs),
                'reference_number' => $baseRef . ' (Bundle of ' . count($bundleRefs) . ')',
                'total_weight' => $totalWeight,
                'transferred_amount' => $totalTransferred,
                'available_amount' => $totalAvailable,
                'roll_destination' => $bundleDestination,
                'bundle_refs' => array_column($bundleRefs, 'reference_number'), // Store individual refs
                'bundle_data' => $bundleRefs // Store full data for each ref
            ];
        } else {
            // Single reference, not part of a bundle
            $individualRefs[] = $ref;
        }
    } else {
        // Reference doesn't match bundle pattern
        $individualRefs[] = $ref;
    }
}

// Combine bundles and individual references
$referenceNumbers = array_merge($bundles, $individualRefs);

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

// Next trip number (auto-incremented from 8 AM to 7:59 AM next day, then resets)
$next_trip = 1;
if ($hasRollTransfer && $hasRollTransfer->num_rows > 0) {
    // Check if trip column exists
    $hasTripCol = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'trip'");
    if ($hasTripCol && $hasTripCol->num_rows > 0) {
        // Get current time in Asia/Dhaka timezone
        date_default_timezone_set('Asia/Dhaka');
        $now = new DateTime();
        $currentHour = (int)$now->format('H');
        $currentMinute = (int)$now->format('i');
        
        // Determine the start of the current cycle (8 AM today or yesterday)
        $cycleStart = clone $now;
        if ($currentHour < 8 || ($currentHour == 8 && $currentMinute == 0)) {
            // Before 8 AM, cycle started at 8 AM yesterday
            $cycleStart->modify('-1 day');
        }
        $cycleStart->setTime(8, 0, 0);
        
        // End of cycle is 7:59:59 AM next day (or today if we're past 8 AM)
        $cycleEnd = clone $cycleStart;
        $cycleEnd->modify('+1 day');
        $cycleEnd->setTime(7, 59, 59);
        
        // Format for SQL query
        $startStr = $cycleStart->format('Y-m-d H:i:s');
        $endStr = $cycleEnd->format('Y-m-d H:i:s');
        
        // Find maximum trip within the current cycle
        $tripRes = $conn->prepare("SELECT MAX(trip) AS max_trip FROM roll_transfer WHERE date_time >= ? AND date_time <= ?");
        if ($tripRes) {
            $tripRes->bind_param("ss", $startStr, $endStr);
            $tripRes->execute();
            $tripResult = $tripRes->get_result();
            if ($tripResult && ($tripRow = $tripResult->fetch_assoc())) {
                $maxTrip = (int)($tripRow['max_trip'] ?? 0);
                $next_trip = $maxTrip + 1;
            }
            $tripRes->close();
        }
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
<title>Roll Internal Transfer Entry</title>
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
  
  <h1>Roll Internal Transfer Entry</h1>

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
        <button type="button" class="btn" data-value="FG" onclick="selectBtn(this, 'toLocationGroup')">FG</button>
      </div>
      <input type="hidden" id="to_location" name="to_location" value="">
    </div>

    <!-- Reference Number -->
    <div class="form-group">
      <label>Reference Number</label>
      <div style="display:flex; gap:10px; align-items:flex-start;">
        <div style="flex:1; position:relative;">
          <input type="text" id="reference_search" placeholder="Search or type reference number..." 
                 style="padding:10px; border:1px solid #ccc; border-radius:6px; width:100%; margin-bottom:10px;"
                 onkeyup="filterReferences()" onfocus="showReferenceDropdown()">
          <div id="reference_dropdown" style="display:none; max-height:200px; overflow-y:auto; border:1px solid #ccc; border-radius:6px; background:#fff; position:absolute; z-index:1000; width:100%; top:100%; box-shadow:0 4px 6px rgba(0,0,0,0.1);">
            <?php foreach($referenceNumbers as $ref): 
              $destination_label = '';
              if (isset($ref['roll_destination'])) {
                $destination_label = $ref['roll_destination'] === 'fg_production' ? ' [FG]' : ($ref['roll_destination'] === 'bag_production' ? ' [Bag]' : '');
              }
              
              // Check if this is a bundle
              $isBundle = isset($ref['is_bundle']) && $ref['is_bundle'];
              $bundleData = '';
              $displayRef = $ref['reference_number'];
              if ($isBundle && isset($ref['bundle_data'])) {
                // Encode bundle data as JSON for JavaScript
                $bundleData = htmlspecialchars(json_encode($ref['bundle_data']), ENT_QUOTES, 'UTF-8');
                $displayRef = $ref['reference_number']; // Already formatted as "Base (Bundle of N)"
              }
            ?>
              <div class="reference-option <?php echo $isBundle ? 'bundle-option' : ''; ?>" 
                   data-ref="<?php echo htmlspecialchars($isBundle ? $ref['base_reference'] : $ref['reference_number']); ?>"
                   data-total-weight="<?php echo $ref['total_weight']; ?>"
                   data-transferred="<?php echo $ref['transferred_amount']; ?>"
                   data-available="<?php echo $ref['available_amount']; ?>"
                   data-destination="<?php echo htmlspecialchars($ref['roll_destination'] ?? ''); ?>"
                   <?php if ($isBundle): ?>
                   data-is-bundle="true"
                   data-bundle-data="<?php echo $bundleData; ?>"
                   <?php endif; ?>
                   onclick="event.preventDefault(); event.stopPropagation(); selectReferenceFromDropdown('<?php echo htmlspecialchars(addslashes($displayRef)); ?>'); return false;"
                   style="padding:10px; cursor:pointer; border-bottom:1px solid #eee; <?php echo $isBundle ? 'background:#e8f5e9;' : ''; ?>"
                   onmouseover="this.style.background='<?php echo $isBundle ? '#c8e6c9' : '#f0f0f0'; ?>'" 
                   onmouseout="this.style.background='<?php echo $isBundle ? '#e8f5e9' : '#fff'; ?>'">
                <strong><?php echo htmlspecialchars($displayRef); ?><?php echo $destination_label; ?></strong>
                <?php if ($isBundle): ?>
                  <span style="background:#4caf50; color:#fff; padding:2px 6px; border-radius:3px; font-size:11px; margin-left:5px;">BUNDLE</span>
                <?php endif; ?>
                <br><small style="color:#27ae60; font-weight:600;">Available: <?php echo number_format($ref['available_amount'], 2); ?> kg</small>
                <?php if (isset($ref['transferred_amount']) && $ref['transferred_amount'] > 0): ?>
                  <br><small style="color:#e67e22;">Transferred: <?php echo number_format($ref['transferred_amount'], 2); ?> kg / Total: <?php echo number_format($ref['total_weight'], 2); ?> kg</small>
                <?php endif; ?>
                <?php if ($isBundle && isset($ref['bundle_refs'])): ?>
                  <br><small style="color:#888; font-size:10px;">Includes: <?php echo implode(', ', array_slice($ref['bundle_refs'], 0, 4)); ?><?php echo count($ref['bundle_refs']) > 4 ? '...' : ''; ?></small>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <button type="button" onclick="addReference()" style="padding:10px 20px; background:#3498db; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; white-space:nowrap;">
          <i class="fas fa-plus"></i> Add
        </button>
      </div>
      <div id="selected_references" style="margin-top:10px; min-height:30px;">
        <!-- Selected references will appear here -->
      </div>
      <input type="hidden" id="reference_number" name="reference_number" value="" required>
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

    <!-- Trip auto -->
    <div class="form-group">
      <label>Trip </label>
      <input type="text" value="<?php echo $next_trip; ?>" readonly class="readonly">
      <input type="hidden" name="trip" value="<?php echo $next_trip; ?>">
    </div>

    <!-- Amount (Auto-calculated) -->
    <div class="form-group">
      <label>Amount (kg)</label>
      <input type="number" step="0.01" id="amount" name="amount" required min="0.01" readonly class="readonly" style="background:#ecf0f1;">
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
    const toLocation = document.getElementById('to_location');
    if (toLocation) {
      toLocation.value = btn.dataset.value;
    }
    // Filter references based on selected destination
    filterReferencesByDestination();
    // Clear selected references when destination changes
    window.selectedReferences = [];
    updateSelectedReferences();
  }
  updateSummary();
}

function filterReferencesByDestination() {
  const toLocation = document.getElementById('to_location');
  if (!toLocation) {
    return;
  }
  
  const selectedDestination = toLocation.value;
  const options = document.querySelectorAll('.reference-option');
  
  console.log('Filtering references by destination:', selectedDestination);
  
  if (!selectedDestination) {
    // If no destination selected, hide all references
    options.forEach(option => {
      option.style.display = 'none';
    });
    return;
  }
  
  // Map UI values to database values
  const destinationMap = {
    'FG': 'fg_production',
    'Swing': 'bag_production'
  };
  
  const dbDestination = destinationMap[selectedDestination];
  
  if (!dbDestination) {
    // Invalid destination, hide all
    options.forEach(option => {
      option.style.display = 'none';
    });
    return;
  }
  
  // Get list of already selected references
  const selectedRefs = [];
  if (typeof window.selectedReferences !== 'undefined' && window.selectedReferences.length > 0) {
    window.selectedReferences.forEach(ref => {
      if (ref && ref.ref) {
        selectedRefs.push(ref.ref.toLowerCase());
      }
    });
  }
  
  options.forEach(option => {
    const optionDestination = option.getAttribute('data-destination') || '';
    const isBundle = option.getAttribute('data-is-bundle') === 'true';
    const refText = option.getAttribute('data-ref') ? option.getAttribute('data-ref').toLowerCase() : '';
    
    // Get available amount - must have remaining amount to show
    const availableAmount = parseFloat(option.getAttribute('data-available')) || 0;
    if (availableAmount <= 0) {
      option.style.display = 'none';
      return;
    }
    
    // Check if this reference is already selected
    // But still show it if there's remaining amount (for partial transfers)
    let isAlreadySelected = false;
    if (isBundle) {
      // For bundles, check if any reference in the bundle is already selected
      const bundleDataJson = option.getAttribute('data-bundle-data');
      if (bundleDataJson) {
        try {
          const bundleData = JSON.parse(bundleDataJson);
          bundleData.forEach(refData => {
            const bundleAvailable = parseFloat(refData.available_amount) || 0;
            // Only consider it "already selected" if selected AND no remaining amount
            if (refData.reference_number && selectedRefs.includes(refData.reference_number.toLowerCase()) && bundleAvailable <= 0) {
              isAlreadySelected = true;
            }
          });
        } catch (e) {
          console.error('Error parsing bundle data:', e);
        }
      }
    } else {
      // For individual references, only hide if selected AND no remaining amount
      // If there's remaining amount, allow it to show (for partial transfers)
      if (selectedRefs.includes(refText) && availableAmount <= 0) {
        isAlreadySelected = true;
      }
    }
    
    // Show only if matches destination and (not already selected OR has remaining amount)
    if (optionDestination === dbDestination && !isAlreadySelected) {
      option.style.display = 'block';
    } else {
      option.style.display = 'none';
    }
  });
  
  // Also filter based on current search term
  filterReferences();
}

// Store selected references - ensure it's global
if (typeof window.selectedReferences === 'undefined') {
  window.selectedReferences = [];
}

function showReferenceDropdown() {
  const toLocation = document.getElementById('to_location');
  if (!toLocation || !toLocation.value) {
    alert('Please select a destination (To: FG or Swing) first.');
    const searchInput = document.getElementById('reference_search');
    if (searchInput) {
      searchInput.blur();
    }
    return;
  }
  
  // Filter references before showing dropdown
  filterReferencesByDestination();
  
  const dropdown = document.getElementById('reference_dropdown');
  if (dropdown) {
    dropdown.style.display = 'block';
  }
}

function filterReferences() {
  const searchTerm = document.getElementById('reference_search').value.toLowerCase();
  const toLocation = document.getElementById('to_location');
  const selectedDestination = toLocation ? toLocation.value : '';
  
  // Map UI values to database values
  const destinationMap = {
    'FG': 'fg_production',
    'Swing': 'bag_production'
  };
  const dbDestination = selectedDestination ? destinationMap[selectedDestination] : null;
  
  // Get list of already selected references
  const selectedRefs = [];
  if (typeof window.selectedReferences !== 'undefined' && window.selectedReferences.length > 0) {
    window.selectedReferences.forEach(ref => {
      if (ref && ref.ref) {
        selectedRefs.push(ref.ref.toLowerCase());
      }
    });
  }
  
  const options = document.querySelectorAll('.reference-option');
  
  options.forEach(option => {
    const refText = option.getAttribute('data-ref').toLowerCase();
    const optionDestination = option.getAttribute('data-destination') || '';
    const isBundle = option.getAttribute('data-is-bundle') === 'true';
    
    // Get available amount for this reference
    const availableAmount = parseFloat(option.getAttribute('data-available')) || 0;
    
    // If no remaining quantity, hide it
    if (availableAmount <= 0) {
      option.style.display = 'none';
      return;
    }
    
    // Check if this reference is already selected in the CURRENT form session
    // But allow it to show if there's remaining quantity (for partial transfers)
    let isAlreadySelected = false;
    if (isBundle) {
      // For bundles, check if any reference in the bundle is already selected
      const bundleDataJson = option.getAttribute('data-bundle-data');
      if (bundleDataJson) {
        try {
          const bundleData = JSON.parse(bundleDataJson);
          bundleData.forEach(refData => {
            const refNum = refData.reference_number ? refData.reference_number.toLowerCase() : '';
            const bundleAvailable = parseFloat(refData.available_amount) || 0;
            // Only hide if selected AND no remaining quantity
            if (refNum && selectedRefs.includes(refNum) && bundleAvailable <= 0) {
              isAlreadySelected = true;
            }
          });
        } catch (e) {
          console.error('Error parsing bundle data:', e);
        }
      }
    } else {
      // For individual references, only hide if selected AND no remaining quantity
      // If there's remaining quantity, allow it to show (for partial transfers)
      if (selectedRefs.includes(refText) && availableAmount <= 0) {
        isAlreadySelected = true;
      }
    }
    
    // Filter by search term
    const matchesSearch = refText.includes(searchTerm) || searchTerm === '';
    
    // Filter by destination if one is selected
    const matchesDestination = !dbDestination || optionDestination === dbDestination;
    
    // Hide if already selected (with no remaining), doesn't match search, or doesn't match destination
    if (isAlreadySelected || !matchesSearch || !matchesDestination) {
      option.style.display = 'none';
    } else {
      option.style.display = 'block';
    }
  });
  
  // Show dropdown if there are visible options
  const visibleOptions = Array.from(options).filter(opt => opt.style.display !== 'none' && opt.style.display !== '');
  const dropdown = document.getElementById('reference_dropdown');
  if (visibleOptions.length > 0) {
    if (dropdown) {
      dropdown.style.display = 'block';
    }
  } else {
    if (dropdown) {
      dropdown.style.display = 'none';
    }
  }
}

function selectReference(refNumber, totalWeight, transferred, available, isBundleRef = false, bundleBaseRef = null) {
  try {
    console.log('selectReference called with:', refNumber, totalWeight, transferred, available, 'isBundle:', isBundleRef);
    
    // Ensure selectedReferences array exists
    if (typeof window.selectedReferences === 'undefined') {
      window.selectedReferences = [];
    }
    
    // Validate inputs
    if (!refNumber || refNumber === 'undefined' || refNumber === 'null') {
      console.error('Invalid reference number:', refNumber);
      alert('Error: Invalid reference number. Please try again.');
      return;
    }
    
    // Allow same reference to be added multiple times (for multiple trips/partial transfers)
    // Add to selected references
    const availableAmount = parseFloat(available) || 0;
    // For single references, default to rounded available amount. For bundles, use full available.
    const defaultTransferAmount = isBundleRef ? availableAmount : Math.round(availableAmount);
    
    const refObj = {
      ref: String(refNumber),
      totalWeight: parseFloat(totalWeight) || 0,
      transferred: parseFloat(transferred) || 0,
      available: availableAmount,
      transferAmount: defaultTransferAmount, // Default to rounded available for single refs, full for bundles
      isBundleRef: isBundleRef || false,
      bundleBaseRef: bundleBaseRef || null
    };
    
    window.selectedReferences.push(refObj);
    
    console.log('Selected references after push:', window.selectedReferences);
    
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
    updateSelectedReferences();
    updateAvailableAmount();
    updateSummary();
    
    console.log('Reference added successfully');
  } catch (error) {
    console.error('Error in selectReference:', error);
    alert('Error adding reference: ' + error.message);
  }
}

function addReference() {
  console.log('addReference called');
  const searchInput = document.getElementById('reference_search');
  if (!searchInput) {
    alert('Search input not found.');
    return;
  }
  
  const refValue = searchInput.value.trim();
  
  if (!refValue) {
    alert('Please search and select a reference number first.');
    return;
  }
  
  // Try to find exact match first, then partial match
  const options = document.querySelectorAll('.reference-option');
  let matchedOption = null;
  let exactMatch = false;
  
  // Use a for loop instead of forEach so we can break early
  // Check all options, not just visible ones, since dropdown might be hidden
  for (let i = 0; i < options.length; i++) {
    const option = options[i];
    
    // Get the displayed text from the option (includes bundle label)
    // Get text from the first child (strong tag) which contains the reference
    const strongTag = option.querySelector('strong');
    const optionDisplayText = strongTag ? strongTag.textContent.trim() : '';
    const optionText = option.textContent || '';
    const refText = option.getAttribute('data-ref');
    const isBundle = option.getAttribute('data-is-bundle') === 'true';
    
    if (refText || optionDisplayText) {
      const refLower = (refText || '').toLowerCase();
      const displayTextLower = (optionDisplayText || '').toLowerCase();
      const optionTextLower = optionText.toLowerCase();
      const valueLower = refValue.toLowerCase();
      
      console.log('Checking option:', {
        refText,
        optionDisplayText,
        isBundle,
        valueLower
      });
      
      // Check if it's a bundle match (search value contains "bundle of" or matches bundle display)
      if (isBundle) {
        // Extract base reference from bundle display text (e.g., "4.0L226JAN05-R01-H0.1 (Bundle of 4)")
        const bundleMatch = displayTextLower.match(/(.+?)\s*\(bundle of \d+\)/i);
        if (bundleMatch) {
          const baseRef = bundleMatch[1].trim().toLowerCase();
          const fullBundleText = bundleMatch[0].trim().toLowerCase();
          
          // Clean the search value (remove extra whitespace, normalize)
          const cleanValue = valueLower.replace(/\s+/g, ' ').trim();
          const cleanBundleText = fullBundleText.replace(/\s+/g, ' ').trim();
          
          console.log('Bundle matching:', {
            cleanValue,
            cleanBundleText,
            baseRef,
            matches: cleanValue === cleanBundleText || cleanValue === baseRef || cleanValue.includes(baseRef) || baseRef.includes(cleanValue.replace(/\s*\(bundle of \d+\)/i, '').trim())
          });
          
          // Check multiple matching conditions
          if (cleanValue === cleanBundleText || 
              cleanValue === baseRef || 
              cleanValue.includes(baseRef) || 
              baseRef.includes(cleanValue.replace(/\s*\(bundle of \d+\)/i, '').trim()) ||
              displayTextLower.includes(cleanValue) ||
              (cleanValue.includes('bundle') && baseRef.includes(cleanValue.replace(/\(bundle of \d+\)/i, '').trim()))) {
            matchedOption = option;
            exactMatch = true;
            break; // Found exact match, stop searching
          }
        }
        // Also check if search value matches the base reference directly (from data-ref)
        if (!exactMatch && refLower) {
          const cleanValue = valueLower.replace(/\s*\(bundle of \d+\)/i, '').trim();
          if (refLower === cleanValue || valueLower.includes(refLower) || refLower.includes(cleanValue)) {
            if (!matchedOption) {
              matchedOption = option;
            }
          }
        }
      } else {
        // Regular reference matching
        // Prefer exact match
        if (refLower === valueLower || displayTextLower === valueLower || displayTextLower.includes(valueLower)) {
          matchedOption = option;
          exactMatch = true;
          break; // Found exact match, stop searching
        } 
        // If no exact match yet, take first partial match
        else if (!exactMatch && (refLower.includes(valueLower) || valueLower.includes(refLower)) && !matchedOption) {
          matchedOption = option;
        }
      }
    }
  }
  
  if (matchedOption) {
    const isBundle = matchedOption.getAttribute('data-is-bundle') === 'true';
    
    if (isBundle) {
      // Handle bundle - add all references in the bundle
      try {
        const bundleDataJson = matchedOption.getAttribute('data-bundle-data');
        if (bundleDataJson) {
          const bundleData = JSON.parse(bundleDataJson);
          const baseRef = matchedOption.getAttribute('data-ref');
          
          // Add each reference in the bundle with bundle flag
          bundleData.forEach(refData => {
            const refText = refData.reference_number;
            const totalWeight = parseFloat(refData.total_weight) || 0;
            const transferred = parseFloat(refData.transferred_amount) || 0;
            const available = parseFloat(refData.available_amount) || 0;
            
            if (refText) {
              selectReference(refText, totalWeight, transferred, available, true, baseRef);
            }
          });
          
          // Clear the search box after adding
          searchInput.value = '';
          console.log('Bundle added successfully:', bundleData.length, 'references');
        } else {
          alert('Error: Could not read bundle data.');
        }
      } catch (error) {
        console.error('Error parsing bundle data:', error);
        alert('Error processing bundle: ' + error.message);
      }
    } else {
      // Handle individual reference
      const refText = matchedOption.getAttribute('data-ref');
      const totalWeight = parseFloat(matchedOption.getAttribute('data-total-weight')) || 0;
      const transferred = parseFloat(matchedOption.getAttribute('data-transferred')) || 0;
      const available = parseFloat(matchedOption.getAttribute('data-available')) || 0;
      
      if (!refText) {
        alert('Error: Could not read reference data.');
        return;
      }
      
      // Add the reference to the list
      selectReference(refText, totalWeight, transferred, available);
      
      // Clear the search box after adding
      searchInput.value = '';
    }
  } else {
    alert('Please select a valid reference from the dropdown first. Type to search, click on a reference to select it, then click Add.');
  }
}

// Keep old function for backward compatibility, but use removeReferenceByIndex
function removeReference(refNumber) {
  if (typeof selectedReferences === 'undefined') {
    window.selectedReferences = [];
  }
  const index = window.selectedReferences.findIndex(r => r.ref === refNumber);
  if (index !== -1) {
    removeReferenceByIndex(index);
  }
}

function updateSelectedReferences() {
  console.log('updateSelectedReferences called');
  
  // Ensure selectedReferences array exists
  if (typeof selectedReferences === 'undefined') {
    window.selectedReferences = [];
  }
  
  const container = document.getElementById('selected_references');
  const hiddenInput = document.getElementById('reference_number');
  
  if (!container) {
    console.error('Container not found');
    return;
  }
  
  if (!hiddenInput) {
    console.error('Hidden input not found');
    return;
  }
  
  if (window.selectedReferences.length === 0) {
    container.innerHTML = '<small style="color:#999;">No references selected</small>';
    hiddenInput.value = '';
    return;
  }
  
  console.log('Updating display for', window.selectedReferences.length, 'references');
  
  let html = '<div style="display:flex; flex-direction:column; gap:10px;">';
  let refList = [];
  let refIndex = 0;
  
  window.selectedReferences.forEach((ref, index) => {
    if (!ref || !ref.ref) {
      console.warn('Invalid reference at index', index, ref);
      return; // Skip invalid references
    }
    
    refList.push(String(ref.ref));
    const uniqueId = `ref_${index}_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
    ref.uniqueId = uniqueId;
    
    const available = parseFloat(ref.available) || 0;
    const isBundleRef = ref.isBundleRef || false;
    // For single references, use transferAmount (rounded). For bundles, use full available.
    const transferAmount = isBundleRef 
      ? available 
      : (parseFloat(ref.transferAmount) || Math.round(available));
    
    // Escape HTML to prevent XSS
    const escapedRef = String(ref.ref).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    
    // Don't show delete button for bundle references
    const deleteButton = isBundleRef 
      ? '' 
      : `<button type="button" onclick="removeReferenceByIndex(${index})" style="background:#f44336; color:#fff; border:none; border-radius:50%; width:24px; height:24px; cursor:pointer; font-size:14px; line-height:1; flex-shrink:0;">×</button>`;
    
    // Use different background color for bundle references
    const bgColor = isBundleRef ? '#e8f5e9' : '#e3f2fd';
    
    // Transfer amount input only for single references (not bundles)
    // Bundles use full available amount, single references can adjust by whole numbers
    let transferAmountInput = '';
    if (!isBundleRef) {
      transferAmountInput = `
      <div style="display:flex; align-items:center; gap:8px;">
        <label style="font-size:12px; color:#666;">Transfer Amount (kg):</label>
        <input type="number" 
               id="${uniqueId}_amount" 
               value="${Math.round(transferAmount)}" 
               min="1" 
               max="${Math.round(available)}" 
               step="1"
               style="width:100px; padding:5px; border:1px solid #ccc; border-radius:4px;"
               onchange="updateReferenceAmount(${index}, this.value)"
               oninput="validateReferenceAmount(${index}, this.value)">
        <small style="color:#666;">/ ${Math.round(available)} kg</small>
      </div>`;
    }
    
    html += `<div style="background:${bgColor}; padding:10px; border-radius:8px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <div style="flex:1; min-width:200px;">
        <strong>${escapedRef}</strong>
        ${isBundleRef ? '<span style="background:#4caf50; color:#fff; padding:2px 6px; border-radius:3px; font-size:10px; margin-left:5px;">BUNDLE</span>' : ''}
        <br><small style="color:#666;">Available: ${available.toFixed(2)} kg</small>
      </div>
      ${transferAmountInput}
      ${deleteButton}
    </div>`;
    refIndex++;
  });
  
  html += '</div>';
  container.innerHTML = html;
  
  // Store references as comma-separated list (for backward compatibility)
  const refListStr = refList.join(',');
  hiddenInput.value = refListStr;
  
  // Store references with individual amounts as JSON
  const referenceAmounts = window.selectedReferences.map(r => ({
    ref: r.ref,
    amount: parseFloat(r.transferAmount) || parseFloat(r.available) || 0
  }));
  
  // Update or create hidden input for reference amounts
  let referenceAmountsInput = document.getElementById('reference_amounts');
  if (!referenceAmountsInput) {
    referenceAmountsInput = document.createElement('input');
    referenceAmountsInput.type = 'hidden';
    referenceAmountsInput.id = 'reference_amounts';
    referenceAmountsInput.name = 'reference_amounts';
    hiddenInput.parentNode.appendChild(referenceAmountsInput);
  }
  referenceAmountsInput.value = JSON.stringify(referenceAmounts);
  
  console.log('Updated reference_number hidden input:', refListStr);
  console.log('Updated reference_amounts:', referenceAmounts);
}

function updateReferenceAmount(index, amount) {
  if (typeof selectedReferences === 'undefined') {
    window.selectedReferences = [];
  }
  if (window.selectedReferences[index]) {
    const ref = window.selectedReferences[index];
    
    // Skip if it's a bundle reference (shouldn't have input)
    if (ref.isBundleRef) {
      return;
    }
    
    const transferAmount = Math.round(parseFloat(amount) || 0);
    const maxAmount = Math.round(parseFloat(ref.available) || 0);
    
    if (transferAmount > maxAmount) {
      alert(`Transfer amount (${transferAmount} kg) cannot exceed available amount (${maxAmount} kg)`);
      const input = document.getElementById(ref.uniqueId + '_amount');
      if (input) {
        input.value = maxAmount;
      }
      ref.transferAmount = maxAmount;
    } else if (transferAmount <= 0) {
      alert('Transfer amount must be greater than 0');
      const input = document.getElementById(ref.uniqueId + '_amount');
      if (input) {
        input.value = ref.transferAmount || 1;
      }
    } else {
      ref.transferAmount = transferAmount;
    }
    
    // Update the hidden input with the new transfer amount
    updateSelectedReferences();
    updateAvailableAmount();
    updateSummary();
  }
}

function validateReferenceAmount(index, amount) {
  if (typeof selectedReferences === 'undefined') {
    window.selectedReferences = [];
  }
  if (window.selectedReferences[index]) {
    const ref = window.selectedReferences[index];
    
    // Skip if it's a bundle reference (shouldn't have input)
    if (ref.isBundleRef) {
      return;
    }
    
    const transferAmount = Math.round(parseFloat(amount) || 0);
    const maxAmount = Math.round(parseFloat(ref.available) || 0);
    const input = document.getElementById(ref.uniqueId + '_amount');
    
    if (!input) {
      return;
    }
    
    if (transferAmount > maxAmount) {
      input.style.border = '2px solid #f44336';
      input.style.borderColor = '#f44336';
    } else {
      input.style.border = '1px solid #ccc';
      input.style.borderColor = '#ccc';
    }
  }
}

function removeReferenceByIndex(index) {
  if (typeof selectedReferences === 'undefined') {
    window.selectedReferences = [];
  }
  window.selectedReferences.splice(index, 1);
  updateSelectedReferences();
  updateAvailableAmount();
  updateSummary();
}

function updateAvailableAmount() {
  if (typeof selectedReferences === 'undefined') {
    window.selectedReferences = [];
  }
  
  const availableText = document.getElementById('available_amount_text');
  const warningText = document.getElementById('amount_warning');
  const amountInput = document.getElementById('amount');
  
  if (window.selectedReferences.length === 0) {
    availableText.style.display = 'none';
    warningText.style.display = 'none';
    amountInput.removeAttribute('data-max-amount');
    amountInput.removeAttribute('max');
    return;
  }
  
  // Calculate total transfer amount from all selected references
  let totalTransferAmount = 0;
  let totalAvailable = 0;
  let totalWeight = 0;
  let totalTransferred = 0;
  
  // Calculate total from all selected references
  // For bundles: use full available amount
  // For single references: use the transferAmount (which can be adjusted by user)
  window.selectedReferences.forEach(ref => {
    let refAmount;
    if (ref.isBundleRef) {
      // Bundle references use full available amount
      refAmount = parseFloat(ref.available) || 0;
      ref.transferAmount = refAmount;
    } else {
      // Single references use the transferAmount (user can adjust)
      refAmount = parseFloat(ref.transferAmount) || parseFloat(ref.available) || 0;
    }
    totalTransferAmount += refAmount;
    totalAvailable += (parseFloat(ref.available) || 0);
    totalWeight += (parseFloat(ref.totalWeight) || 0);
    totalTransferred += (parseFloat(ref.transferred) || 0);
  });
  
  // Auto-fill the amount field with total transfer amount
  amountInput.value = totalTransferAmount.toFixed(2);
  
  // Display available amount
  availableText.textContent = `✓ Total Transfer Amount: ${totalTransferAmount.toFixed(2)} kg | Total Available: ${totalAvailable.toFixed(2)} kg (Total Weight: ${totalWeight.toFixed(2)} kg, Already Transferred: ${totalTransferred.toFixed(2)} kg)`;
  availableText.style.color = '#27ae60';
  availableText.style.display = 'block';
  warningText.style.display = 'none';
  
  // Store for validation - allow up to total transfer amount
  amountInput.setAttribute('data-max-amount', totalTransferAmount);
  amountInput.max = totalTransferAmount;
}

function updateSummary() {
  if (typeof selectedReferences === 'undefined') {
    window.selectedReferences = [];
  }
  
  const dateTime = document.getElementById("dateTime").value;
  const shift = document.getElementById("shiftBanner").innerText.replace("Shift: ", "");
  const operator = "<?php echo htmlspecialchars($operator_name); ?>";
  const referenceNumbers = window.selectedReferences.length > 0 
    ? window.selectedReferences.map(r => String(r.ref)).join(', ') 
    : '';
  
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
  if (referenceNumbers) summary += ` | Ref: ${referenceNumbers}`;
  if (driverName) summary += ` | Driver: ${driverName}`;
  if (amount) summary += ` | Amount: ${amount}`;
  if (fromLocation) summary += ` | From: ${fromLocation}`;
  if (toLocation) summary += ` | To: ${toLocation}`;

  document.getElementById("summaryBox").innerText = summary;
  document.getElementById("summary").value = summary;
}

// Add event delegation for reference options - just select, don't add
document.addEventListener('DOMContentLoaded', function() {
  const dropdown = document.getElementById('reference_dropdown');
  if (dropdown) {
    dropdown.addEventListener('click', function(e) {
      const option = e.target.closest('.reference-option');
      if (option) {
        e.preventDefault();
        e.stopPropagation();
        
        // Get the full display text for bundles, or just the ref for individual references
        const isBundle = option.getAttribute('data-is-bundle') === 'true';
        let displayText = option.getAttribute('data-ref');
        
        if (isBundle) {
          // Extract full bundle display text
          const optionText = option.textContent || '';
          const bundleMatch = optionText.match(/^(.+?)\s*\(Bundle of \d+\)/i);
          if (bundleMatch) {
            displayText = bundleMatch[0].trim();
          }
        }
        
        if (displayText) {
          // Just select the reference in the search box, don't add it yet
          selectReferenceFromDropdown(displayText);
        } else {
          console.error('Missing reference data');
        }
      }
    });
  }
});

// Function to select a reference from dropdown (fills search box, doesn't add to list)
function selectReferenceFromDropdown(refNumber) {
  const searchInput = document.getElementById('reference_search');
  if (searchInput) {
    // Find the option that matches this reference to get the full display text
    const options = document.querySelectorAll('.reference-option');
    let displayValue = refNumber;
    
    options.forEach(option => {
      const optionText = option.textContent || '';
      const isBundle = option.getAttribute('data-is-bundle') === 'true';
      const optionRef = option.getAttribute('data-ref') || '';
      
      if (isBundle) {
        // For bundles, extract the full display text (e.g., "4.0L226JAN05-R01-H0.1 (Bundle of 4)")
        const bundleMatch = optionText.match(/^(.+?)\s*\(Bundle of \d+\)/i);
        if (bundleMatch && (optionText.includes(refNumber) || optionRef === refNumber || refNumber.includes(optionRef))) {
          displayValue = bundleMatch[0].trim();
        }
      } else {
        // For regular references, use the reference number as-is
        if (optionRef === refNumber || optionText.includes(refNumber)) {
          displayValue = refNumber;
        }
      }
    });
    
    searchInput.value = displayValue;
    // Hide dropdown after selection
    const dropdown = document.getElementById('reference_dropdown');
    if (dropdown) {
      dropdown.style.display = 'none';
    }
  }
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
  const searchInput = document.getElementById('reference_search');
  const dropdown = document.getElementById('reference_dropdown');
  
  if (searchInput && dropdown && !searchInput.contains(event.target) && !dropdown.contains(event.target)) {
    dropdown.style.display = 'none';
  }
});

// Add event listeners for summary updates
// Reference number is now handled by updateSelectedReferences
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
  window.selectedReferences = [];
  updateSelectedReferences();
  const searchInput = document.getElementById("reference_search");
  if (searchInput) {
    searchInput.value = "";
  }
  const dropdown = document.getElementById("reference_dropdown");
  if (dropdown) {
    dropdown.style.display = "none";
  }
  if (document.getElementById("driver_id")) document.getElementById("driver_id").value = "";
  if (document.getElementById("driver_name")) document.getElementById("driver_name").value = "";
  document.getElementById("amount").value = "";
  document.getElementById("to_location").value = "";
  document.querySelectorAll('#toLocationGroup .btn').forEach(b => b.classList.remove('selected'));
  document.getElementById("summaryBox").innerText = "Please fill in the details above to generate a summary.";
  document.getElementById("summary").value = "";
  updateAvailableAmount();
}

function validateForm(){
  // Ensure the hidden reference_amounts input is updated with the latest transfer amounts before submission
  if (typeof window.selectedReferences !== 'undefined' && window.selectedReferences.length > 0) {
    updateSelectedReferences();
  }
  if (typeof selectedReferences === 'undefined') {
    window.selectedReferences = [];
  }
  if(window.selectedReferences.length === 0){
    alert("Please add at least one reference number."); return false;
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



