<?php
// fg_entry.php

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
$userRole = strtolower(trim($_SESSION['role'] ?? ''));

// Check if user has access to FG module
if (!AccessControl::hasModuleAccess($userRole, AccessControl::MODULE_FINISHED_GOODS, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access the Finished Goods module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

// Determine which product types the user can access
$canAccessRoll = false;
$canAccessBag = false;

// Admin and management can access both
if (in_array($userRole, ['admin', 'management', 'agm ops'])) {
    $canAccessRoll = true;
    $canAccessBag = true;
} elseif ($userRole === 'prod_test' || $userRole === 'production_user') {
    // Production users can only access Roll portion
    $canAccessRoll = true;
    $canAccessBag = false;
} elseif ($userRole === 'sewing_test') {
    // Sewing test users can only access Bag portion
    $canAccessRoll = false;
    $canAccessBag = true;
} else {
    // Default: allow both for other roles with FG access
    $canAccessRoll = true;
    $canAccessBag = true;
}

date_default_timezone_set('Asia/Dhaka');

// Connect DB (shared)
$conn = SecurityConfig::getConnection();

// Default project for fallback (so project shows even if API fails)
require_once '../config/project_helper.php';
$defaultProject = $conn ? getDefaultProject($conn) : null;
$projects = $defaultProject ? [$defaultProject] : [];

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

// Fetch recommended weights (bag capacity) from bag_size_master for all bag sizes
$bagSizeToRecommendedWeightMap = array();
$master_check = $conn->query("SHOW TABLES LIKE 'bag_size_master'");
if ($master_check && $master_check->num_rows > 0) {
    $weightQuery = "SELECT bag_size, bag_capacity FROM bag_size_master 
                    WHERE bag_size IS NOT NULL AND bag_size != '' AND bag_capacity IS NOT NULL AND bag_capacity != '' 
                    ORDER BY id ASC";
    $weightResult = @$conn->query($weightQuery);
    if ($weightResult) {
        while ($row = $weightResult->fetch_assoc()) {
            $bagSize = trim($row['bag_size']);
            if ($bagSize === '') continue;
            // Keep first occurrence per bag_size (one capacity per size from master)
            if (!isset($bagSizeToRecommendedWeightMap[$bagSize])) {
                $capacity = trim($row['bag_capacity']);
                $num = preg_replace('/[^0-9.]/', '', $capacity);
                if ($num !== '') {
                    $bagSizeToRecommendedWeightMap[$bagSize] = (float)$num;
                }
            }
        }
    }
}

// Also add any custom bag sizes from fg_entry that are not in the predefined list
$predefinedSizesList = array_column($predefinedBagSizes, 'size');
$fg_table_check = $conn->query("SHOW TABLES LIKE 'fg_entry'");
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
    $hasMergedPrintQty = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'merged_print_qty'")->num_rows > 0;
    $hasIsDeleted = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'is_deleted'")->num_rows > 0;
    $hasCreatedAt = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'created_at'")->num_rows > 0;

    if ($hasRef) {
        $isDeletedFilter = $hasIsDeleted ? "AND (be.is_deleted = 0 OR be.is_deleted IS NULL)" : "";

        // refToBrandedQtyMap: use merged_print_qty per (ref, batch, bag_size) then sum per reference; then deduct already submitted FG (quality_checked) per reference
        if ($hasMergedPrintQty && $hasCnc && $hasPrintQty) {
            $brandingQtyQuery = "
                SELECT reference_number, SUM(merged_per_batch) AS total_printed
                FROM (
                    SELECT be.reference_number,
                           COALESCE(MAX(be.merged_print_qty), SUM(be.print_qty)) AS merged_per_batch
                    FROM branding_entries be
                    WHERE be.reference_number IS NOT NULL AND be.reference_number <> ''
                      AND be.cnc_cutting_batch IS NOT NULL AND be.cnc_cutting_batch <> ''
                      {$isDeletedFilter}
                    GROUP BY be.reference_number, be.cnc_cutting_batch" . ($hasBagSize ? ", be.bag_size" : "") . "
                ) t
                GROUP BY reference_number
                LIMIT 200
            ";
            $qRes = $conn->query($brandingQtyQuery);
            if ($qRes) {
                while ($row = $qRes->fetch_assoc()) {
                    $ref = $row['reference_number'];
                    if (!isset($refToBrandedQtyMap[$ref])) {
                        $refToBrandedQtyMap[$ref] = (int)($row['total_printed'] ?? 0);
                    }
                }
            }
        }

        $cncCol = $hasCnc ? "MAX(be.cnc_cutting_batch) AS cnc_cutting_batch" : "NULL AS cnc_cutting_batch";
        $bagCol = $hasBagSize ? "MAX(be.bag_size) AS bag_size" : "NULL AS bag_size";
        $printCol = $hasPrintQty ? "SUM(be.print_qty) AS total_printed" : "0 AS total_printed";
        $orderCol = $hasCreatedAt ? "MAX(be.created_at)" : "MAX(be.reference_number)";

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
                // refToBrandedQtyMap already filled from merged query when merged_print_qty exists; else use per-ref sum
                if (!isset($refToBrandedQtyMap[$ref])) {
                    $refToBrandedQtyMap[$ref] = (int)($row['total_printed'] ?? 0);
                }
            }
        }

        // Deduct already submitted FG (quality_checked) per reference so max shows remaining
        if ($fgTableExists && !empty($refToBrandedQtyMap)) {
            $fgHasRef = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'reference_number'")->num_rows > 0;
            $fgHasProductType = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'product_type'")->num_rows > 0;
            $fgHasQualityChecked = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'quality_checked'")->num_rows > 0;
            if ($fgHasRef && $fgHasProductType && $fgHasQualityChecked) {
                $usedRes = $conn->query("SELECT reference_number, COALESCE(SUM(quality_checked), 0) AS used FROM fg_entry 
                    WHERE product_type = 'bag' AND reference_number IS NOT NULL AND reference_number <> '' 
                    GROUP BY reference_number");
                if ($usedRes) {
                    while ($ur = $usedRes->fetch_assoc()) {
                        $ref = $ur['reference_number'];
                        $used = (int)($ur['used'] ?? 0);
                        if (isset($refToBrandedQtyMap[$ref])) {
                            $refToBrandedQtyMap[$ref] = max(0, $refToBrandedQtyMap[$ref] - $used);
                        }
                    }
                }
            }
        }

        // Remove references with no remaining quantity (0) so they are not shown in the dropdown
        foreach (array_keys($refToBrandedQtyMap) as $ref) {
            if ((int)$refToBrandedQtyMap[$ref] <= 0) {
                unset($refToBrandedQtyMap[$ref]);
                unset($refToBatchMap[$ref]);
                unset($refToBagSizeMap[$ref]);
            }
        }
    }
}

// Fetch trip numbers from roll_transfer where to_location = 'FG'
$fgTripNumbers = array();
$fgRollTripReferences = array();
$hasRollTransfer = $conn->query("SHOW TABLES LIKE 'roll_transfer'")->num_rows > 0;
if ($hasRollTransfer) {
    $hasToLocation = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'to_location'")->num_rows > 0;
    $hasTrip = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'trip'")->num_rows > 0;
    $hasIsDeleted = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'is_deleted'")->num_rows > 0;
    
    // Build filter for deleted records
    $deletedFilter = $hasIsDeleted ? "AND (rt.is_deleted = 0 OR rt.is_deleted IS NULL)" : "";
    
    // Exclude references already submitted in fg_entry (product_type='roll')
    $feCols = [];
    $feColRes = $conn->query("SHOW COLUMNS FROM fg_entry");
    if ($feColRes) {
        while ($c = $feColRes->fetch_assoc()) { $feCols[] = strtolower($c['Field']); }
    }
    $feHasProductType = in_array('product_type', $feCols, true);
    $feHasTripNum = in_array('trip_number', $feCols, true);
    $feHasRefNum = in_array('reference_number', $feCols, true);
    $feHasIsDeleted = in_array('is_deleted', $feCols, true);
    $excludeSubmitted = ($feHasProductType && $feHasRefNum)
        ? "AND NOT EXISTS (SELECT 1 FROM fg_entry fe WHERE LOWER(TRIM(COALESCE(fe.product_type,''))) = 'roll' AND TRIM(COALESCE(fe.reference_number,'')) = TRIM(COALESCE(rt.reference_number,'')) " . ($feHasIsDeleted ? "AND (fe.is_deleted = 0 OR fe.is_deleted IS NULL)" : "") . ")"
        : "";
    
    if ($hasToLocation && $hasTrip) {
        // Use case-insensitive comparison and handle both 'FG' and 'fg'
        // Exclude (reference,trip) already submitted in FG Entry
        $tripQuery = $conn->query("
            SELECT DISTINCT rt.trip, MAX(rt.date_time) as last_transfer_date
            FROM roll_transfer rt
            WHERE UPPER(TRIM(rt.to_location)) = 'FG' 
              AND rt.trip IS NOT NULL
              AND rt.trip > 0
              {$excludeSubmitted}
              {$deletedFilter}
            GROUP BY rt.trip
            ORDER BY rt.trip DESC
            LIMIT 50
        ");
        if ($tripQuery) {
            $tripCount = 0;
            while ($row = $tripQuery->fetch_assoc()) {
                $fgTripNumbers[] = [
                    'trip' => (int)$row['trip'],
                    'last_transfer_date' => $row['last_transfer_date']
                ];
                $tripCount++;
            }
            error_log("FG Entry - Found {$tripCount} trip numbers from roll_transfer where to_location='FG'");
        } else {
            // Log query error for debugging
            error_log("FG Entry - Trip query error: " . $conn->error);
        }
        
        // Debug: Check what to_location values actually exist
        $debugQuery = $conn->query("SELECT DISTINCT to_location, COUNT(*) as cnt FROM roll_transfer WHERE to_location IS NOT NULL GROUP BY to_location LIMIT 10");
        if ($debugQuery) {
            $locations = [];
            while ($debugRow = $debugQuery->fetch_assoc()) {
                $locations[] = $debugRow['to_location'] . ' (' . $debugRow['cnt'] . ')';
            }
            error_log("FG Entry - Available to_location values: " . implode(', ', $locations));
        }
    } else {
        error_log("FG Entry - Missing columns: hasToLocation=" . ($hasToLocation ? 'true' : 'false') . ", hasTrip=" . ($hasTrip ? 'true' : 'false'));
    }
    
    // Fetch references for each trip from roll_transfer (where to_location = 'FG')
    if ($hasToLocation && $hasTrip) {
        // Query roll_transfer only - one row per reference+trip where to_location='FG'
        // Exclude (reference,trip) already submitted in FG Entry
        $refQuery = $conn->query("
            SELECT 
                rt.reference_number,
                rt.trip,
                SUM(rt.amount_kg) AS total_amount,
                0 AS total_area,
                NULL AS roll_size
            FROM roll_transfer rt
            WHERE UPPER(TRIM(rt.to_location)) = 'FG'
              AND rt.reference_number IS NOT NULL
              AND rt.reference_number != ''
              AND rt.trip IS NOT NULL
              AND rt.trip > 0
              {$excludeSubmitted}
              {$deletedFilter}
            GROUP BY rt.reference_number, rt.trip
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
                    'total_area' => (float)$row['total_area'],
                    'roll_size' => $row['roll_size'] ?? null
                ];
                $refCount++;
            }
            error_log("FG Entry - Found {$refCount} references from roll_transfer where to_location='FG'");
        } else {
            error_log("FG Entry - Reference query error: " . $conn->error);
        }
    }
}

// Batch number will be auto-generated based on form fields

// Pre-compute date/time and shift for initial display (so they show without JavaScript)
date_default_timezone_set('Asia/Dhaka');
$now_php = new DateTime('now');
$php_date_time = $now_php->format('D M j, Y') . ' ' . $now_php->format('g:i:s A');
$php_date_db = $now_php->format('Y-m-d H:i:s');
$h = (int)$now_php->format('H');
$php_shift = ($h >= 8 && $h <= 19) ? 'Day' : 'Night';
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
  /* Actions after CNC Cutting Batch (for Bag) */
  .actions-after-cnc { margin: 20px 0 25px 0; padding: 16px 20px; text-align: center; background: #f8f9fa; border: 2px solid #ddd; border-radius: 8px; }
  .actions-after-cnc button { padding: 12px 24px; font-size: 16px; border: none; border-radius: 6px; cursor: pointer; margin: 0 10px; font-weight: 600; }
  /* Actions at end of form */
  .actions { margin-top: 30px; padding: 20px; text-align: center; background: #f8f9fa; border: 2px solid #ddd; border-radius: 8px; }
  .actions button { padding: 12px 24px; font-size: 16px; border: none; border-radius: 6px; cursor: pointer; margin: 0 10px; font-weight: 600; }
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
  /* Modern Warning Popup Styles */
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
</style>
</head>
<body>
<div class="container">
  
  <h1>FG Entry</h1>

  <?php if (isset($_GET['success']) && $_GET['success'] === 'fg_entry_saved'): ?>
    <div class="alert alert-success">
      FG Entry saved successfully! FG ID: <?php echo htmlspecialchars($_GET['fg_id'] ?? ''); ?>
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
          echo htmlspecialchars($_GET['error']);
        }
      ?>
    </div>
  <?php endif; ?>

  <!-- Back to Dashboard Link -->
  <?php if (isset($_GET['success'])): ?>
    <div style="background:#d4edda;color:#155724;padding:12px;border-radius:6px;border:1px solid #c3e6cb;margin-bottom:15px;">
      <?php echo htmlspecialchars($_GET['success']); ?>
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

  <div id="dateTimeDisplay" class="summary-info" style="display:block; visibility:visible;">Date & Time: <?php echo htmlspecialchars($php_date_time); ?></div>
  <div id="shiftBanner" class="summary-info" style="display:block; visibility:visible;">Shift: <?php echo htmlspecialchars($php_shift); ?></div>

  <!-- Modern Warning Popup -->
  <div id="warningPopup" class="popup-overlay">
    <div class="warning-popup">
      <div class="warning-popup-content">
        <div class="warning-bar"></div>
        <div class="warning-icon">
          <span>!</span>
        </div>
        <div class="warning-text">
          <h3 class="warning-title">Quantity Limit Exceeded</h3>
          <p class="warning-message" id="warningMessage"></p>
        </div>
      </div>
      <div class="popup-actions">
        <button type="button" class="popup-btn popup-btn-ok" id="warningPopupOk" onclick="closeWarningPopup()">OK</button>
      </div>
    </div>
  </div>

  <form id="fgForm" method="post" action="../handlers/submit_fg_entry.php" onsubmit="return validateForm();" novalidate>

    <!-- FG ID -->
    <div class="form-group">
      <label>FG ID:</label>
      <input type="text" id="fgIdDisplay" value="<?php echo 'FG-' . date('Ymd') . '-' . str_pad($next_fg_number, 3, '0', STR_PAD_LEFT); ?>" readonly class="readonly">
      <input type="hidden" id="fg_id" name="fg_id" value="<?php echo 'FG-' . date('Ymd') . '-' . str_pad($next_fg_number, 3, '0', STR_PAD_LEFT); ?>">
    </div>

    <!-- Product Type Selection -->
    <div class="form-group" style="position:relative; z-index:2;">
      <label>Product Type: <span style="color:red;">*</span></label>
      <!-- Define selectProductType IMMEDIATELY before buttons to ensure it's available -->
      <script>
        // Define selectProductType function IMMEDIATELY so it's available when buttons are clicked
        (function() {
          if (typeof window.selectProductType === 'undefined') {
            window.selectProductType = function(type) {
              console.log('selectProductType (immediate) called with type:', type);
              
              // Update button styling immediately
              document.querySelectorAll('.product-type-btn').forEach(btn => {
                btn.style.background = '#e0e0e0';
                btn.style.color = '#333';
                btn.style.border = '2px solid #ccc';
                btn.classList.remove('selected');
              });
              
              const targetBtn = type === 'roll' ? document.getElementById('rollProductTypeBtn') : document.getElementById('bagProductTypeBtn');
              if (targetBtn) {
                targetBtn.style.background = '#2196F3';
                targetBtn.style.color = '#fff';
                targetBtn.style.border = '2px solid #1976D2';
                targetBtn.classList.add('selected');
              }
              
              const productTypeInput = document.getElementById('product_type');
              if (productTypeInput) {
                productTypeInput.value = type;
                productTypeInput.dispatchEvent(new Event('input', { bubbles: true }));
                productTypeInput.dispatchEvent(new Event('change', { bubbles: true }));
              }
              
              // Show product fields container
              const productFieldsContainer = document.getElementById('productFieldsContainer');
              if (productFieldsContainer) productFieldsContainer.style.display = 'block';
              
              // Handle roll-specific logic
              if (type === 'roll') {
                // CRITICAL: Show productFieldsContainer first (contains all roll fields)
                const productFieldsContainer = document.getElementById('productFieldsContainer');
                if (productFieldsContainer) {
                  productFieldsContainer.style.display = 'block';
                  console.log('✅ Product Fields Container shown for Roll');
                } else {
                  console.error('❌ productFieldsContainer not found!');
                }
                
                // Show trip number group
                const tripNumberGroup = document.getElementById('tripNumberGroup');
                if (tripNumberGroup) {
                  tripNumberGroup.style.display = 'block';
                  console.log('✅ Trip Number group shown (immediate)');
                }
                
                // Show reference number field when Roll is selected
                const showRollReferenceField = function(retryCount) {
                  retryCount = retryCount || 0;
                  const rollReferenceGroup = document.getElementById('rollReferenceGroup');
                  if (rollReferenceGroup) {
                    rollReferenceGroup.style.setProperty('display', 'block', 'important');
                    rollReferenceGroup.style.setProperty('visibility', 'visible', 'important');
                    const computedStyle = window.getComputedStyle(rollReferenceGroup);
                    console.log('✅ Roll Reference group shown (immediate selectProductType) - computed display:', computedStyle.display);
                    // Keep search input disabled until trip is selected
                    const searchInput = rollReferenceGroup.querySelector('#roll_reference_search');
                    if (searchInput) {
                      searchInput.disabled = true;
                      searchInput.placeholder = 'Select a trip number first...';
                    }
                    return true;
                  } else if (retryCount < 5) {
                    console.warn('⚠️ rollReferenceGroup not found, retry', retryCount + 1);
                    setTimeout(function() {
                      showRollReferenceField(retryCount + 1);
                    }, 100 * (retryCount + 1));
                    return false;
                  } else {
                    console.error('❌ rollReferenceGroup not found after 5 retries');
                    return false;
                  }
                };
                showRollReferenceField(0);
                
                // Hide all bag-specific fields
                const bagCncBatchGroup = document.getElementById('bagCncBatchGroup');
                const actionsAfterCnc = document.getElementById('actionsAfterCnc');
                const cncBatchDisplayGroup = document.getElementById('cncBatchDisplayGroup');
                const bagSizeFormGroup = document.getElementById('bagSizeFormGroup');
                const bagReferenceGroup = document.getElementById('bagReferenceGroup');
                const recommendedWeightGroup = document.getElementById('recommendedWeightGroup');
                const actualWeightBagGroup = document.getElementById('actualWeightBagGroup');
                const qualityCheckedFormGroup = document.getElementById('qualityCheckedFormGroup');
                const passedQtyFormGroup = document.getElementById('passedQtyFormGroup');
                const rejectedQtyFormGroup = document.getElementById('rejectedQtyFormGroup');
                
                if (bagCncBatchGroup) bagCncBatchGroup.style.display = 'none';
                // Show actionsAfterCnc for roll entries (contains Submit/Clear buttons)
                if (actionsAfterCnc) {
                  actionsAfterCnc.style.setProperty('display', 'block', 'important');
                  actionsAfterCnc.style.setProperty('visibility', 'visible', 'important');
                  console.log('✅ Actions After CNC shown for Roll');
                }
                // Also show formActions (backup button container)
                const formActions = document.getElementById('formActions');
                if (formActions) {
                  formActions.style.setProperty('display', 'block', 'important');
                  formActions.style.setProperty('visibility', 'visible', 'important');
                  console.log('✅ Form Actions shown for Roll');
                }
                if (cncBatchDisplayGroup) cncBatchDisplayGroup.style.display = 'none';
                if (bagSizeFormGroup) bagSizeFormGroup.style.display = 'none';
                if (bagReferenceGroup) bagReferenceGroup.style.display = 'none';
                if (recommendedWeightGroup) recommendedWeightGroup.style.display = 'none';
                if (actualWeightBagGroup) actualWeightBagGroup.style.display = 'none';
                if (qualityCheckedFormGroup) qualityCheckedFormGroup.style.display = 'none';
                if (passedQtyFormGroup) passedQtyFormGroup.style.display = 'none';
                if (rejectedQtyFormGroup) rejectedQtyFormGroup.style.display = 'none';
                
                // Function to show roll fields (waits for elements to exist)
                function showRollFields() {
                  // First ensure productFieldsContainer is visible
                  const productFieldsContainer = document.getElementById('productFieldsContainer');
                  if (productFieldsContainer) {
                    // Force show with !important to override any CSS
                    productFieldsContainer.style.setProperty('display', 'block', 'important');
                    productFieldsContainer.style.setProperty('visibility', 'visible', 'important');
                    console.log('✅ productFieldsContainer set to block and visible');
                  } else {
                    console.error('❌ productFieldsContainer not found!');
                    return false;
                  }
                  
                  const elements = {
                    tripNumberGroup: document.getElementById('tripNumberGroup'),
                    rollReferenceGroup: document.getElementById('rollReferenceGroup'),
                    formActions: document.getElementById('formActions'),
                    actionsAfterCnc: document.getElementById('actionsAfterCnc')
                  };
                  
                  console.log('Attempting to show roll fields...');
                  const containerStyle = window.getComputedStyle(productFieldsContainer);
                  console.log('productFieldsContainer computed style - display:', containerStyle.display, 'visibility:', containerStyle.visibility);
                  
                  // Show trip number (should exist as it's before productFieldsContainer)
                  if (elements.tripNumberGroup) {
                    elements.tripNumberGroup.style.display = 'block';
                    elements.tripNumberGroup.style.visibility = 'visible';
                    console.log('✅ Trip Number group shown');
                  } else {
                    console.warn('⚠️ tripNumberGroup not found');
                  }
                  
                  // Show reference number field (outside productFieldsContainer, after trip number) - with retry
                  const showRollRefField = function(retryCount) {
                    retryCount = retryCount || 0;
                    const rollRefGroup = document.getElementById('rollReferenceGroup');
                    if (rollRefGroup) {
                    rollRefGroup.style.setProperty('display', 'block', 'important');
                    rollRefGroup.style.setProperty('visibility', 'visible', 'important');
                    rollRefGroup.style.setProperty('opacity', '1', 'important');
                    rollRefGroup.style.setProperty('height', 'auto', 'important');
                    rollRefGroup.style.setProperty('width', 'auto', 'important');
                    const refStyle = window.getComputedStyle(rollRefGroup);
                    const rect = rollRefGroup.getBoundingClientRect();
                    console.log('✅ Roll Reference group shown - computed display:', refStyle.display, 'visibility:', refStyle.visibility, 'opacity:', refStyle.opacity);
                    console.log('📐 Roll Reference group dimensions - width:', rect.width, 'height:', rect.height, 'top:', rect.top, 'left:', rect.left);
                    console.log('📐 Is visible?', rect.width > 0 && rect.height > 0 ? 'YES' : 'NO');
                    if (rect.width === 0 || rect.height === 0) {
                      console.error('❌ Element has zero dimensions! This is why it\'s not visible.');
                      console.log('Element innerHTML length:', rollRefGroup.innerHTML.length);
                      console.log('Element offsetHeight:', rollRefGroup.offsetHeight, 'offsetWidth:', rollRefGroup.offsetWidth);
                    }
                    // Check parent elements
                    let parent = rollRefGroup.parentElement;
                    let parentLevel = 0;
                    const parentChain = [];
                    while (parent && parentLevel < 5) {
                      const parentStyle = window.getComputedStyle(parent);
                      const parentRect = parent.getBoundingClientRect();
                      parentChain.push({
                        element: parent.id || parent.className || parent.tagName,
                        display: parentStyle.display,
                        visibility: parentStyle.visibility,
                        opacity: parentStyle.opacity,
                        width: parentRect.width,
                        height: parentRect.height
                      });
                      if (parentStyle.display === 'none' || parentStyle.visibility === 'hidden' || parentStyle.opacity === '0') {
                        console.error('❌ Parent element hiding rollReferenceGroup:', parent.id || parent.tagName, 'display:', parentStyle.display, 'visibility:', parentStyle.visibility, 'opacity:', parentStyle.opacity);
                      }
                      parent = parent.parentElement;
                      parentLevel++;
                    }
                    console.log('📋 Parent chain:', parentChain);
                    // Keep search input disabled until trip is selected
                    const searchInput = rollRefGroup.querySelector('#roll_reference_search');
                    if (searchInput) {
                      searchInput.disabled = true;
                      searchInput.placeholder = 'Select a trip number first...';
                      console.log('✅ Search input found and configured');
                    } else {
                      console.error('❌ Search input not found inside rollReferenceGroup');
                    }
                    
                    // Force element to be visible and scroll into view if needed
                    setTimeout(function() {
                      const checkRefGroup = document.getElementById('rollReferenceGroup');
                      if (checkRefGroup) {
                        const checkStyle = window.getComputedStyle(checkRefGroup);
                        const checkRect = checkRefGroup.getBoundingClientRect();
                        console.log('🔍 Final check - display:', checkStyle.display, 'visibility:', checkStyle.visibility, 'width:', checkRect.width, 'height:', checkRect.height);
                        if (checkStyle.display === 'none' || checkRect.width === 0 || checkRect.height === 0) {
                          console.error('❌ Element is hidden or has zero size! Forcing visibility...');
                          checkRefGroup.style.setProperty('display', 'block', 'important');
                          checkRefGroup.style.setProperty('visibility', 'visible', 'important');
                          checkRefGroup.style.setProperty('opacity', '1', 'important');
                          checkRefGroup.style.setProperty('min-height', '50px', 'important');
                          checkRefGroup.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                        }
                      }
                    }, 500);
                    
                    return true;
                    } else if (retryCount < 10) {
                      console.warn('⚠️ rollReferenceGroup not found, retry', retryCount + 1);
                      setTimeout(function() {
                        showRollRefField(retryCount + 1);
                      }, 100 * (retryCount + 1));
                      return false;
                    } else {
                      console.error('❌ rollReferenceGroup not found after 10 retries');
                      return false;
                    }
                  };
                  showRollRefField(0);
                  
                  // Hide roll size, weight, and area fields (not needed for roll entries)
                  const rollSizeFormGroup = document.getElementById('rollSizeFormGroup');
                  const rollWeightGroup = document.getElementById('rollWeightGroup');
                  const rollAreaGroup = document.getElementById('rollAreaGroup');
                  if (rollSizeFormGroup) rollSizeFormGroup.style.display = 'none';
                  if (rollWeightGroup) rollWeightGroup.style.display = 'none';
                  if (rollAreaGroup) rollAreaGroup.style.display = 'none';
                  console.log('✅ Roll Size, Weight, and Area fields hidden (not needed)');
                  
                  // Show Submit/Clear buttons (try both formActions and actionsAfterCnc)
                  if (elements.formActions) {
                    elements.formActions.style.display = 'block';
                    elements.formActions.style.visibility = 'visible';
                    console.log('✅ Form Actions (Submit/Clear buttons) shown');
                  } else {
                    console.warn('⚠️ formActions not found');
                  }
                  
                  if (elements.actionsAfterCnc) {
                    elements.actionsAfterCnc.style.display = 'block';
                    elements.actionsAfterCnc.style.visibility = 'visible';
                    console.log('✅ Actions After CNC (Submit/Clear buttons) shown');
                  } else {
                    console.warn('⚠️ actionsAfterCnc not found');
                  }
                  
                  // Return true if all critical elements found (formActions OR actionsAfterCnc is acceptable)
                  // Note: rollReferenceGroup is outside productFieldsContainer, so it's checked separately
                  const allFound = !!(elements.rollReferenceGroup && (elements.formActions || elements.actionsAfterCnc));
                  if (!allFound) {
                    console.warn('⚠️ Some elements not found:', {
                      rollReferenceGroup: !!elements.rollReferenceGroup,
                      formActions: !!elements.formActions,
                      actionsAfterCnc: !!elements.actionsAfterCnc
                    });
                  }
                  return allFound;
                }
                
                // Try immediately
                let allFound = showRollFields();
                
                // If not all found, retry with increasing delays
                if (!allFound) {
                  console.log('Some elements not found, will retry...');
                  let retryCount = 0;
                  const maxRetries = 15;
                  
                  function retryShowFields() {
                    retryCount++;
                    console.log(`Retry ${retryCount}/${maxRetries}: Looking for roll fields...`);
                    const found = showRollFields();
                    
                    if (!found && retryCount < maxRetries) {
                      // Exponential backoff: 100ms, 200ms, 400ms, etc. up to 1600ms
                      const delay = Math.min(100 * Math.pow(2, retryCount - 1), 1600);
                      setTimeout(retryShowFields, delay);
                    } else if (found) {
                      console.log('✅ All roll fields found and shown!');
                    } else {
                      console.error('❌ Failed to find all roll fields after', maxRetries, 'attempts');
                    }
                  }
                  
                  // Start retrying after initial delay
                  setTimeout(retryShowFields, 300);
                } else {
                  console.log('✅ All roll fields shown immediately!');
                }
                
                // Also call ensureRollFieldsVisible after a delay (backup)
                if (window.ensureRollFieldsVisible && typeof window.ensureRollFieldsVisible === 'function') {
                  setTimeout(function() {
                    console.log('Calling ensureRollFieldsVisible as backup');
                    window.ensureRollFieldsVisible();
                  }, 1000);
                }
                
                // KEEP rollReferenceGroup visible (do NOT hide it) - it should stay visible for Roll selection
                // The search input inside will be disabled until a trip is selected
                
                // Load trip numbers for roll
                console.log('Attempting to load trip numbers for roll (immediate function)');
                if (typeof loadFGTripNumbers === 'function') {
                  console.log('Calling loadFGTripNumbers (local function)');
                  loadFGTripNumbers();
                } else if (window.loadFGTripNumbers && typeof window.loadFGTripNumbers === 'function') {
                  console.log('Calling window.loadFGTripNumbers');
                  window.loadFGTripNumbers();
                } else {
                  // Function not available yet - this is OK since trip numbers are already populated from PHP in HTML
                  console.log('loadFGTripNumbers not available yet (trip numbers already populated from PHP)');
                  // Retry after a short delay if needed (non-blocking, optional)
                  setTimeout(function() {
                    if (window.loadFGTripNumbers && typeof window.loadFGTripNumbers === 'function') {
                      console.log('Calling loadFGTripNumbers after delay');
                      window.loadFGTripNumbers();
                    }
                  }, 500);
                }
              }
              
              // If full implementation is available, use it (this will also show the fields)
              if (window.selectProductTypeFull) {
                console.log('Calling selectProductTypeFull for', type);
                window.selectProductTypeFull(type);
                // Also ensure fields are shown after a brief delay (in case DOM not ready)
                setTimeout(function() {
                  const rollSizeFormGroup = document.getElementById('rollSizeFormGroup');
                  const rollWeightGroup = document.getElementById('rollWeightGroup');
                  const rollAreaGroup = document.getElementById('rollAreaGroup');
                  const formActions = document.getElementById('formActions');
                  // Hide roll size, weight, and area fields (not needed)
                  if (rollSizeFormGroup) rollSizeFormGroup.style.display = 'none';
                  if (rollWeightGroup) rollWeightGroup.style.display = 'none';
                  if (rollAreaGroup) rollAreaGroup.style.display = 'none';
                  if (formActions) {
                    formActions.style.display = 'block';
                    formActions.style.visibility = 'visible';
                  }
                }, 200);
              }
            };
            console.log('selectProductType function defined immediately');
          }
        })();
      </script>
      <div class="btn-group" id="productTypeGroup" style="display:flex; gap:10px; flex-wrap:wrap; position:relative; z-index:2; pointer-events:auto;">
        <?php if ($canAccessRoll): ?>
        <button type="button" class="btn product-type-btn" id="rollProductTypeBtn" data-product-type="roll" onclick="console.log('Roll button onclick fired'); if(window.selectProductType) { window.selectProductType('roll'); } else { alert('selectProductType not available'); }" style="background:#e0e0e0;color:#333; border:2px solid #ccc; cursor:pointer; pointer-events:auto;">
          <i class="fas fa-scroll"></i> Roll
        </button>
        <?php endif; ?>
        <?php if ($canAccessBag): ?>
        <button type="button" class="btn product-type-btn" id="bagProductTypeBtn" data-product-type="bag" onclick="console.log('Bag button onclick fired'); if(window.selectProductType) { window.selectProductType('bag'); } else { alert('selectProductType not available'); }" style="background:#e0e0e0;color:#333; border:2px solid #ccc; cursor:pointer; pointer-events:auto;">
          <i class="fas fa-shopping-bag"></i> Bag
        </button>
        <?php endif; ?>
        <script>
          // ✅ Define showToast FIRST – centered, modern UI (e.g. when quality checked > branding)
          function showToastEarly(msg, type) {
            if (!msg) return;
            type = type || 'error';
            var b = document.body || document.getElementsByTagName('body')[0];
            try { if (window.top && window.top !== window && window.top.document && window.top.document.body) { b = window.top.document.body; } } catch (e) {}
            if (!b) return;
            var bg = (type === 'error' ? '#dc3545' : '#28a745');
            var wrapper = document.createElement('div');
            wrapper.setAttribute('role', 'alert');
            wrapper.style.cssText = 'position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.45);backdrop-filter:blur(4px);animation:fgToastFadeIn 0.25s ease-out;';
            var card = document.createElement('div');
            card.style.cssText = 'background:#fff;color:#333;padding:24px 28px;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,0.2),0 0 0 1px rgba(0,0,0,0.06);max-width:400px;text-align:center;animation:fgToastScaleIn 0.3s ease-out;border-left:4px solid ' + bg + ';';
            card.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;gap:12px;font-size:16px;font-weight:600;">' + (type === 'error' ? '<span style="color:#dc3545;font-size:22px;">\u26a0</span>' : '<span style="color:#28a745;font-size:22px;">\u2713</span>') + '<span>' + String(msg).replace(/<[^>]+>/g, '') + '</span></div>';
            wrapper.appendChild(card);
            if (!document.getElementById('fg-toast-early-style')) {
              var styleEl = document.createElement('style');
              styleEl.id = 'fg-toast-early-style';
              styleEl.textContent = '@keyframes fgToastFadeIn{from{opacity:0}to{opacity:1}}@keyframes fgToastScaleIn{from{opacity:0;transform:scale(0.9)}to{opacity:1;transform:scale(1)}}';
              var head = document.head || document.getElementsByTagName('head')[0];
              if (head) head.appendChild(styleEl);
            }
            b.appendChild(wrapper);
            setTimeout(function() {
              try {
                wrapper.style.transition = 'opacity 0.3s';
                wrapper.style.opacity = '0';
                setTimeout(function() { if (wrapper.parentNode) wrapper.parentNode.removeChild(wrapper); }, 300);
              } catch (e) {}
            }, 4500);
          }
          window.showToast = showToastEarly;

          // ✅ EARLY immediate check: quality checked vs max from selected CNC batch - show notification immediately
          function checkQualityCheckedAgainstBatchMaxEarly() {
            var productType = document.getElementById('product_type');
            if (!productType || productType.value !== 'bag') return;
            var cncSelect = document.getElementById('bag_cnc_cutting_batch');
            if (!cncSelect || !cncSelect.value || cncSelect.selectedIndex <= 0) return;
            var opt = cncSelect.options[cncSelect.selectedIndex];
            if (!opt) return;
            var maxAllowed = parseInt(opt.getAttribute('data-total-printed'), 10) || 0;
            if (maxAllowed <= 0) maxAllowed = parseInt(opt.getAttribute('data-print-qty'), 10) || 0;
            if (maxAllowed <= 0 && opt.textContent) {
              var m = opt.textContent.match(/\s[\u2014\u2013-]\s*(\d+)\s*pcs/i) || opt.textContent.match(/\s(\d+)\s*pcs/i);
              if (m) maxAllowed = parseInt(m[1], 10) || 0;
            }
            if (maxAllowed <= 0) return;
            var qcInput = document.getElementById('quality_checked');
            if (!qcInput) return;
            var val = parseInt(qcInput.value, 10) || 0;
            if (val <= maxAllowed) return;
            var toastFn = window.showToast;
            if (toastFn) { try { toastFn('Quality Checked cannot exceed ' + maxAllowed + ' pcs (stored for this CNC batch).', 'error'); } catch (e) {} }
            qcInput.value = maxAllowed;
            var hint = document.getElementById('qualityCheckedHint');
            if (hint) { hint.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <strong style="color:#e74c3c;">Max ' + maxAllowed + ' pcs for this batch</strong>'; hint.style.color = '#e74c3c'; }
            if (window.calculateRejected) window.calculateRejected();
            if (typeof updateActualWeightFromQualityChecked === 'function') updateActualWeightFromQualityChecked();
          }
          window.checkQualityCheckedAgainstBatchMax = checkQualityCheckedAgainstBatchMaxEarly;

          // ✅ EARLY calculateRejected - so rejected qty updates even before full script loads
          function calculateRejectedEarly() {
            var qc = document.getElementById('quality_checked');
            var p = document.getElementById('passed_qty');
            var r = document.getElementById('rejected_qty');
            if (qc && p && r) {
              var v = Math.max(0, (parseInt(qc.value, 10) || 0) - (parseInt(p.value, 10) || 0));
              r.value = v;
            }
          }
          window.calculateRejected = calculateRejectedEarly;

          // ✅ EARLY updateReferenceFromCNCBatch - runs when CNC batch is selected before full script loads
          function updateReferenceFromCNCBatchEarly(e) {
            var sel = e && e.target ? e.target : document.getElementById('bag_cnc_cutting_batch');
            if (!sel || !sel.value || sel.selectedIndex <= 0) return false;
            var opt = sel.options[sel.selectedIndex];
            if (!opt) return false;
            var bagSize = (opt.getAttribute('data-bag-size') || '').trim();
            var projectId = opt.getAttribute('data-project-id') || '';
            var printQty = parseInt(opt.getAttribute('data-print-qty'), 10) || 0;
            var bagSizeInput = document.getElementById('bag_size');
            var bagSizeHint = document.getElementById('bagSizeHint');
            var projectIdInput = document.getElementById('project_id');
            if (projectIdInput && projectId) projectIdInput.value = projectId;
            if (bagSize && bagSizeInput) {
              bagSizeInput.value = bagSize;
              if (bagSizeHint) {
                bagSizeHint.innerHTML = '<i class="fas fa-lock"></i> Bag size from selected batch (unchangeable): ' + bagSize;
                bagSizeHint.style.color = '#4caf50';
              }
              var lockedLabel = document.getElementById('bagSizeLockedLabel');
              if (lockedLabel) lockedLabel.style.display = 'inline';
              // Lock bag size: disable all buttons and custom input so bag size is unchangaable
              var grp = document.getElementById('bagSizeButtonGroup');
              if (grp) {
                grp.querySelectorAll('.btn').forEach(function(btn) {
                  var t = btn.textContent.trim();
                  if (t.toLowerCase().replace(/\s+/g, '') === bagSize.toLowerCase().replace(/\s+/g, '')) {
                    btn.classList.add('selected'); btn.style.background = '#2196F3'; btn.style.color = '#fff';
                  } else { btn.classList.remove('selected'); btn.style.background = ''; btn.style.color = ''; }
                  btn.disabled = true; btn.style.opacity = '0.6'; btn.style.pointerEvents = 'none'; btn.style.cursor = 'not-allowed';
                });
              }
              var customInput = document.getElementById('bag_size_custom');
              if (customInput) { customInput.disabled = true; customInput.style.opacity = '0.6'; customInput.style.pointerEvents = 'none'; }
              // Recommended weight: fetch from DB (bag_size_master) via API
              var recommendedWeightGroup = document.getElementById('recommendedWeightGroup');
              var actualWeightBagGroup = document.getElementById('actualWeightBagGroup');
              var weightInput = document.getElementById('recommended_weight');
              if (recommendedWeightGroup) recommendedWeightGroup.style.display = 'block';
              if (actualWeightBagGroup) actualWeightBagGroup.style.display = 'block';
              if (weightInput) {
                weightInput.setAttribute('readonly', 'readonly');
                weightInput.style.backgroundColor = '#f0f0f0';
                // Try page-loaded map first, then fetch from API
                var rw = null;
                var weightMap = window.bagSizeToRecommendedWeightFromDB;
                if (weightMap && typeof weightMap === 'object') {
                  rw = weightMap[bagSize];
                  if (rw == null || rw === '') {
                    var bagLower = bagSize.toLowerCase().replace(/\s+/g, '');
                    for (var k in weightMap) { if (weightMap.hasOwnProperty(k) && k.toLowerCase().replace(/\s+/g, '') === bagLower) { rw = weightMap[k]; break; } }
                  }
                }
                if (rw != null && rw !== '') {
                  weightInput.value = rw;
                } else {
                  weightInput.value = '';
                  weightInput.placeholder = 'Fetching from database...';
                  var apiUrl = 'api/fetch_bag_weight.php?bag_size=' + encodeURIComponent(bagSize);
                  try { apiUrl = new URL(apiUrl, window.location.href).href; } catch (e) {}
                  fetch(apiUrl, { credentials: 'include' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                      if (weightInput && data && data.weight != null && data.weight !== '') {
                        weightInput.value = data.weight;
                        weightInput.placeholder = 'Fetched from database';
                      } else {
                        weightInput.placeholder = 'Fetched from database';
                      }
                    })
                    .catch(function() {
                      if (weightInput) weightInput.placeholder = 'Fetched from database';
                    });
                }
              }
              if (typeof disableBagSizeSelection === 'function') disableBagSizeSelection();
              else if (window.disableBagSizeSelection) window.disableBagSizeSelection();
            }
            var qcInput = document.getElementById('quality_checked');
            var passedInput = document.getElementById('passed_qty');
            if (qcInput && printQty > 0) { qcInput.setAttribute('max', printQty); qcInput.value = printQty; }
            if (passedInput && printQty > 0) passedInput.value = printQty;
            if (typeof updateActualWeightFromQualityChecked === 'function') updateActualWeightFromQualityChecked();
            if (typeof calculateRejected === 'function') calculateRejected();
            return true;
          }
          window.updateReferenceFromCNCBatch = updateReferenceFromCNCBatchEarly;

          // ✅ DEFINE loadBagReferencesFull FIRST (before any code that calls it)
          console.log('Defining loadBagReferencesFull early');
          function loadBagReferencesFull() {
            // Only load and show bag/CNC UI when product type is bag (never when Roll is selected)
            const productTypeInput = document.getElementById('product_type');
            if (!productTypeInput || productTypeInput.value !== 'bag') {
              console.log('loadBagReferencesFull: product_type is not "bag", current value:', productTypeInput ? productTypeInput.value : 'input not found');
              return;
            }
            console.log('loadBagReferencesFull: Loading batches for bag product type');
            // Load CNC cutting batches from branding_entries (batches that were submitted in branding entry)
            const cncBatchSelect = document.getElementById('bag_cnc_cutting_batch');
            const cncBatchHint = document.getElementById('cnc_batch_hint');
            
            if (!cncBatchSelect) {
              return;
            }
            
            // Check if dropdown still shows placeholder - if so, force reload
            const hasOnlyPlaceholder = cncBatchSelect.options.length === 1 && 
                                       (cncBatchSelect.options[0].text.includes('Select Product Type First') || 
                                        cncBatchSelect.options[0].value === '');
            
            // Don't reload if dropdown already has options (except placeholder) and a selection exists
            // This prevents clearing the user's selection
            if (!hasOnlyPlaceholder && cncBatchSelect.options.length > 1 && cncBatchSelect.value) {
              console.log('loadBagReferencesFull: Already loaded with selection, skipping');
              return; // Already loaded and has a selection
            }
            
            // If still showing placeholder, clear it and load
            if (hasOnlyPlaceholder) {
              console.log('loadBagReferencesFull: Dropdown still shows placeholder, forcing load');
            }
            
            // Show loading state briefly
            if (cncBatchHint) {
              cncBatchHint.textContent = 'Loading batches...';
              cncBatchHint.style.color = '#2196F3';
            }
            
            // Final check before API call - ensure product_type is still 'bag'
            const finalCheck = document.getElementById('product_type');
            if (!finalCheck || finalCheck.value !== 'bag') {
              console.warn('loadBagReferencesFull: Final check failed - product_type is not "bag", aborting API call');
              return;
            }
            
            // Build API URL relative to current page (works in iframe)
            var apiUrl = 'api/get_branding_cnc_batches_for_fg.php';
            try { apiUrl = new URL(apiUrl, window.location.href).href; } catch (e) {}
            console.log('loadBagReferencesFull: Fetching from', apiUrl);
            window._fgBatchesLoading = true;
            fetch(apiUrl, { credentials: 'include' })
              .then(response => {
                console.log('📡 API Response status (early):', response.status, response.statusText);
                if (!response.ok) {
                  console.error('❌ API error status:', response.status);
                  return { success: true, batches: [] };
                }
                return response.json();
              })
              .then(data => {
                console.log('📦 Batches received (early):', data && data.batches ? data.batches.length : 0, data);
                if (data.success && data.batches && data.batches.length > 0) {
                  const currentValue = cncBatchSelect.value;
                  cncBatchSelect.innerHTML = '<option value="">-- Select CNC Cutting Batch --</option>';
                  
                  const fragment = document.createDocumentFragment();
                  let preservedIndex = -1;
                  let optionIndex = 1;
                  
                  data.batches.forEach(batch => {
                    const option = document.createElement('option');
                    const batchValue = batch.cnc_cutting_batch || batch.batch;
                    const bagSizePart = (batch.bag_size || '').trim();
                    option.value = bagSizePart ? batchValue + '||' + bagSizePart : batchValue;
                    
                    var totalBranded = parseInt(batch.total_print_qty, 10) || 0;
                    let displayText = batchValue;
                    if (batch.batch_date) displayText += ' - ' + batch.batch_date;
                    if (batch.bag_size) displayText += ' [' + batch.bag_size + ']';
                    displayText += ' — ' + totalBranded + ' pcs';
                    if (batch.reference_numbers) {
                      const refs = batch.reference_numbers.split(', ').slice(0, 2);
                      displayText += ' (' + refs.join(', ') + (batch.reference_numbers.split(', ').length > 2 ? '...' : '') + ')';
                    }
                    option.textContent = displayText;
                    option.setAttribute('data-reference', batch.reference_numbers || '');
                    option.setAttribute('data-bag-size', batch.bag_size || '');
                    option.setAttribute('data-project-id', (batch.project_id != null && batch.project_id !== '') ? String(batch.project_id) : '');
                    option.setAttribute('data-print-qty', totalBranded);
                    option.setAttribute('data-total-printed', totalBranded);
                    option.setAttribute('data-ncp-piece', batch.total_ncp_piece || 0);
                    option.setAttribute('data-batch-date', batch.batch_date || '');
                    
                    const optionVal = option.value;
                    if (currentValue && (optionVal === currentValue || batchValue === currentValue)) {
                      preservedIndex = optionIndex;
                      option.selected = true;
                    }
                    
                    fragment.appendChild(option);
                    optionIndex++;
                  });
                  cncBatchSelect.appendChild(fragment);
                  
                  // Attach change listener so selecting a CNC batch auto-fills bag size (early block)
                  if (!window.handleCNCBatchChangeGlobal) {
                    window.handleCNCBatchChangeGlobal = function(e) {
                      var sel = e.target || document.getElementById('bag_cnc_cutting_batch');
                      if (sel && sel.value) {
                        var fn = window.updateReferenceFromCNCBatch || (typeof updateReferenceFromCNCBatch === 'function' ? updateReferenceFromCNCBatch : null);
                        if (fn) fn(e); else console.warn('updateReferenceFromCNCBatch not yet defined');
                      }
                    };
                  }
                  if (!cncBatchSelect.hasAttribute('data-listener-attached')) {
                    cncBatchSelect.removeEventListener('change', window.handleCNCBatchChangeGlobal);
                    cncBatchSelect.addEventListener('change', window.handleCNCBatchChangeGlobal, false);
                    cncBatchSelect.setAttribute('data-listener-attached', 'true');
                  }
                  
                  if (preservedIndex > 0) {
                    cncBatchSelect.selectedIndex = preservedIndex;
                    cncBatchSelect.value = currentValue;
                    // Trigger the change handler to process the restored selection
                    setTimeout(function() {
                      if (typeof updateReferenceFromCNCBatch === 'function') {
                        updateReferenceFromCNCBatch({ target: cncBatchSelect });
                      } else if (window.updateReferenceFromCNCBatch) {
                        window.updateReferenceFromCNCBatch({ target: cncBatchSelect });
                      }
                    }, 150);
                  }
                  
                  if (cncBatchHint) {
                    cncBatchHint.textContent = 'Select a CNC cutting batch from branding entries';
                    cncBatchHint.style.color = '#6c757d';
                  }
                } else {
                  if (cncBatchSelect.options.length <= 1) {
                    cncBatchSelect.innerHTML = '<option value="">-- No Batches Available --</option>';
                  }
                  if (cncBatchHint) {
                    cncBatchHint.textContent = 'No CNC cutting batches found in branding entries';
                    cncBatchHint.style.color = '#6c757d';
                  }
                }
              })
              .catch(err => {
                console.error('❌ Error fetching CNC batches (early):', err);
                if (cncBatchSelect.options.length <= 1) {
                  cncBatchSelect.innerHTML = '<option value="">-- Select CNC Cutting Batch --</option>';
                }
                if (cncBatchHint) {
                  cncBatchHint.textContent = 'Error loading batches: ' + (err.message || 'Network error');
                  cncBatchHint.style.color = '#e74c3c';
                }
              })
              .finally(function() {
                window._fgBatchesLoading = false;
              });
          }
          
          // Assign to window immediately
          window.loadBagReferencesFull = loadBagReferencesFull;
          console.log('✅ loadBagReferencesFull assigned to window (early definition)');
          
          // Define functions early so they're available for button handlers
          // These will be redefined later, but having them here ensures they exist when needed
          console.log('Setting up early selectProductType function');
          if (typeof window.selectProductType === 'undefined') {
            window.selectProductType = function(type) {
              console.log('selectProductType (early) called with type:', type);
              
              // Update button styling immediately
              document.querySelectorAll('.product-type-btn').forEach(btn => {
                btn.style.background = '#e0e0e0';
                btn.style.color = '#333';
                btn.style.border = '2px solid #ccc';
                btn.classList.remove('selected');
              });
              
              const targetBtn = type === 'roll' ? document.getElementById('rollProductTypeBtn') : document.getElementById('bagProductTypeBtn');
              if (targetBtn) {
                targetBtn.style.background = '#2196F3';
                targetBtn.style.color = '#fff';
                targetBtn.style.border = '2px solid #1976D2';
                targetBtn.classList.add('selected');
              }
              
              const productTypeInput = document.getElementById('product_type');
              if (productTypeInput) {
                productTypeInput.value = type;
                // Force a change event to trigger watchers
                productTypeInput.dispatchEvent(new Event('input', { bubbles: true }));
                productTypeInput.dispatchEvent(new Event('change', { bubbles: true }));
                console.log('Early selectProductType: Set product_type to', type, 'value is now:', productTypeInput.value);
              }
              
              // Show product fields container
              const productFieldsContainer = document.getElementById('productFieldsContainer');
              if (productFieldsContainer) productFieldsContainer.style.display = 'block';
              
              
              // Show bag-specific fields
              const bagCncBatchGroup = document.getElementById('bagCncBatchGroup');
              const bagReferenceGroup = document.getElementById('bagReferenceGroup');
              const cncBatchDisplayGroup = document.getElementById('cncBatchDisplayGroup');
              const bagSizeFormGroup = document.getElementById('bagSizeFormGroup');
              const tripNumberGroup = document.getElementById('tripNumberGroup');
              const rollSizeFormGroup = document.getElementById('rollSizeFormGroup');
              
              if (type === 'bag') {
                if (bagCncBatchGroup) bagCncBatchGroup.style.display = 'block';
                const actionsAfterCnc = document.getElementById('actionsAfterCnc');
                if (actionsAfterCnc) actionsAfterCnc.style.display = 'block';
                if (bagReferenceGroup) bagReferenceGroup.style.display = 'none';
                if (cncBatchDisplayGroup) cncBatchDisplayGroup.style.display = 'block';
                if (bagSizeFormGroup) bagSizeFormGroup.style.display = 'block';
                if (tripNumberGroup) tripNumberGroup.style.display = 'none';
                if (rollSizeFormGroup) rollSizeFormGroup.style.display = 'none';
                
                // Ensure product_type is set before loading batches
                console.log('Early selectProductType: Setting product_type to bag and loading batches');
                
                // loadBagReferencesFull is now defined early, so we can call it directly
                // Small delay to ensure DOM is ready
                setTimeout(function() {
                  if (window.loadBagReferencesFull && typeof window.loadBagReferencesFull === 'function') {
                    console.log('Early selectProductType: Calling loadBagReferencesFull (now available)');
                    window.loadBagReferencesFull();
                  } else {
                    console.warn('Early selectProductType: loadBagReferencesFull not available');
                  }
                }, 100);
              } else if (type === 'roll') {
                // Hide bag-only fields (CNC cutting batch is for bags only)
                if (bagCncBatchGroup) bagCncBatchGroup.style.display = 'none';
                const actionsAfterCnc = document.getElementById('actionsAfterCnc');
                if (actionsAfterCnc) actionsAfterCnc.style.display = 'none';
                if (cncBatchDisplayGroup) cncBatchDisplayGroup.style.display = 'none';
                if (bagSizeFormGroup) bagSizeFormGroup.style.display = 'none';
                if (bagReferenceGroup) bagReferenceGroup.style.display = 'none';
                
                // Hide bag-specific weight/quality fields
                const recommendedWeightGroup = document.getElementById('recommendedWeightGroup');
                const actualWeightBagGroup = document.getElementById('actualWeightBagGroup');
                const qualityCheckedFormGroup = document.getElementById('qualityCheckedFormGroup');
                const passedQtyFormGroup = document.getElementById('passedQtyFormGroup');
                const rejectedQtyFormGroup = document.getElementById('rejectedQtyFormGroup');
                if (recommendedWeightGroup) recommendedWeightGroup.style.display = 'none';
                if (actualWeightBagGroup) actualWeightBagGroup.style.display = 'none';
                if (qualityCheckedFormGroup) qualityCheckedFormGroup.style.display = 'none';
                if (passedQtyFormGroup) passedQtyFormGroup.style.display = 'none';
                if (rejectedQtyFormGroup) rejectedQtyFormGroup.style.display = 'none';
                
                // Show roll-specific fields
                console.log('Showing roll-specific fields...');
                if (tripNumberGroup) {
                  tripNumberGroup.style.display = 'block';
                  console.log('Trip Number group shown');
                }
                // Show reference number field when Roll is selected (with retry)
                const showRollReferenceField2 = function(retryCount) {
                  retryCount = retryCount || 0;
                  const rollReferenceGroup = document.getElementById('rollReferenceGroup');
                  if (rollReferenceGroup) {
                    rollReferenceGroup.style.setProperty('display', 'block', 'important');
                    rollReferenceGroup.style.setProperty('visibility', 'visible', 'important');
                    const computedStyle = window.getComputedStyle(rollReferenceGroup);
                    console.log('✅ Roll Reference group shown when Roll selected - computed display:', computedStyle.display);
                    // Keep search input disabled until trip is selected
                    const searchInput = rollReferenceGroup.querySelector('#roll_reference_search');
                    if (searchInput) {
                      searchInput.disabled = true;
                      searchInput.placeholder = 'Select a trip number first...';
                    }
                    return true;
                  } else if (retryCount < 5) {
                    console.warn('⚠️ rollReferenceGroup not found, retry', retryCount + 1);
                    setTimeout(function() {
                      showRollReferenceField2(retryCount + 1);
                    }, 100 * (retryCount + 1));
                    return false;
                  } else {
                    console.error('❌ rollReferenceGroup not found after 5 retries');
                    return false;
                  }
                };
                showRollReferenceField2(0);
                
                // Hide roll size, weight, and area fields (not needed)
                const rollSizeFormGroup = document.getElementById('rollSizeFormGroup');
                const rollWeightGroup = document.getElementById('rollWeightGroup');
                const rollAreaGroup = document.getElementById('rollAreaGroup');
                if (rollSizeFormGroup) rollSizeFormGroup.style.display = 'none';
                if (rollWeightGroup) rollWeightGroup.style.display = 'none';
                if (rollAreaGroup) rollAreaGroup.style.display = 'none';
                console.log('✅ Roll Size, Weight, and Area fields hidden (not needed)');
                
                // Ensure productFieldsContainer is visible
                const productFieldsContainer = document.getElementById('productFieldsContainer');
                if (productFieldsContainer) {
                  productFieldsContainer.style.setProperty('display', 'block', 'important');
                  productFieldsContainer.style.setProperty('visibility', 'visible', 'important');
                  console.log('Product Fields Container shown');
                }
                
                // Show Submit/Clear buttons for Roll
                const actionsAfterCncRoll = document.getElementById('actionsAfterCnc');
                const formActionsRoll = document.getElementById('formActions');
                if (actionsAfterCncRoll) {
                  actionsAfterCncRoll.style.setProperty('display', 'block', 'important');
                  actionsAfterCncRoll.style.setProperty('visibility', 'visible', 'important');
                  console.log('✅ Actions After CNC shown for Roll (selectProductTypeFull)');
                }
                if (formActionsRoll) {
                  formActionsRoll.style.setProperty('display', 'block', 'important');
                  formActionsRoll.style.setProperty('visibility', 'visible', 'important');
                  console.log('✅ Form Actions shown for Roll (selectProductTypeFull)');
                } else {
                  console.warn('⚠️ formActions not found in selectProductTypeFull');
                }
                
                // Roll reference section is visible but search input is disabled until trip is selected
                // (Do NOT hide rollReferenceGroup - it should remain visible so user sees the field)
                
                // Load trip numbers for roll (if function is available)
                // Note: Trip numbers are already populated from PHP in the HTML, so this is optional
                if (typeof loadFGTripNumbers === 'function') {
                  loadFGTripNumbers();
                } else if (window.loadFGTripNumbers && typeof window.loadFGTripNumbers === 'function') {
                  window.loadFGTripNumbers();
                } else {
                  console.log('loadFGTripNumbers not available yet (trip numbers already populated from PHP)');
                }
              }
              
              // Full implementation will be loaded later and will override this
              if (window.selectProductTypeFull) {
                return window.selectProductTypeFull(type);
              }
            };
          }
          
          // Define placeholder function - loadBagReferencesFull is now defined early, so this can call it directly
          window.loadBagReferences = function() {
            if (window.loadBagReferencesFull && typeof window.loadBagReferencesFull === 'function') {
              console.log('loadBagReferences: Calling loadBagReferencesFull (now available early)');
              return window.loadBagReferencesFull();
            } else {
              console.warn('loadBagReferences: loadBagReferencesFull still not available');
            }
          };
          
          window.loadBagReferencesFromBranding = function() {
            // Wait for full implementation
            if (window.loadBagReferencesFromBrandingFull) {
              return window.loadBagReferencesFromBrandingFull();
            } else {
              console.warn('loadBagReferencesFromBrandingFull not yet available');
            }
          };
          
          // Delegated click: ensure product type buttons always work (capture phase so nothing blocks)
          (function setupProductTypeClicks() {
            function handleProductTypeClick(e) {
              var btn = e.target.closest('.product-type-btn');
              if (!btn) return;
              var type = btn.getAttribute('data-product-type') || (btn.id === 'bagProductTypeBtn' ? 'bag' : btn.id === 'rollProductTypeBtn' ? 'roll' : null);
              if (!type) return;
              e.preventDefault();
              e.stopPropagation();
              console.log('Product type button clicked:', type, btn);
              if (window.selectProductType && typeof window.selectProductType === 'function') {
                try {
                  window.selectProductType(type);
                } catch(err) {
                  console.error('Error calling selectProductType:', err);
                  // Fallback: set value directly
                  var input = document.getElementById('product_type');
                  if (input) input.value = type;
                  var c = document.getElementById('productFieldsContainer');
                  if (c) c.style.display = 'block';
                }
              } else {
                console.warn('selectProductType not available, using fallback');
                var input = document.getElementById('product_type');
                if (input) input.value = type;
                var c = document.getElementById('productFieldsContainer');
                if (c) c.style.display = 'block';
              }
            }
            function attach() {
              var group = document.getElementById('productTypeGroup');
              if (group && !group._productTypeDelegate) {
                group._productTypeDelegate = true;
                group.addEventListener('click', handleProductTypeClick, true);
                console.log('Product type click delegation attached');
              }
            }
            attach();
            if (document.readyState === 'loading') {
              document.addEventListener('DOMContentLoaded', attach);
            }
            // Also attach on window load as backup
            window.addEventListener('load', function() {
              var group = document.getElementById('productTypeGroup');
              if (group && !group._productTypeDelegate) {
                attach();
              }
            });
          })();

          // Set up button click handler and auto-select for bag-only users
          <?php if (!$canAccessRoll && $canAccessBag): ?>
          (function() {
            function setupBagButton() {
              const btn = document.getElementById('bagProductTypeBtn');
              if (!btn) {
                setTimeout(setupBagButton, 50);
                return;
              }
              
              // Ensure button is fully enabled and clickable
              btn.removeAttribute('disabled');
              btn.disabled = false;
              btn.style.pointerEvents = 'auto';
              btn.style.cursor = 'pointer';
              btn.style.opacity = '1';
              btn.style.visibility = 'visible';
              btn.style.display = '';
              btn.style.position = 'relative';
              btn.style.zIndex = '10';
              
              // Remove old onclick and add event listener
              btn.removeAttribute('onclick');
              btn.onclick = null;
              
              // Add click event listener
              btn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                console.log('Bag button clicked');
                
                // Update button styling
                btn.style.background = '#2196F3';
                btn.style.color = '#fff';
                btn.style.border = '2px solid #1976D2';
                btn.classList.add('selected');
                
                // Remove selected styling from other buttons
                document.querySelectorAll('.product-type-btn').forEach(b => {
                  if (b !== btn) {
                    b.style.background = '#e0e0e0';
                    b.style.color = '#333';
                    b.style.border = '2px solid #ccc';
                    b.classList.remove('selected');
                  }
                });
                
                // Set product type
                const productTypeInput = document.getElementById('product_type');
                if (productTypeInput) {
                  productTypeInput.value = 'bag';
                }
                
                // Show product fields
                const productFieldsContainer = document.getElementById('productFieldsContainer');
                if (productFieldsContainer) productFieldsContainer.style.display = 'block';
                
                // Show bag-specific fields
                const bagCncBatchGroup = document.getElementById('bagCncBatchGroup');
                const bagReferenceGroup = document.getElementById('bagReferenceGroup');
                const cncBatchDisplayGroup = document.getElementById('cncBatchDisplayGroup');
                const bagSizeFormGroup = document.getElementById('bagSizeFormGroup');
                
                if (bagCncBatchGroup) bagCncBatchGroup.style.display = 'block';
                // Hide reference group for bags (only for rolls)
                if (bagReferenceGroup) bagReferenceGroup.style.display = 'none';
                if (cncBatchDisplayGroup) cncBatchDisplayGroup.style.display = 'block';
                if (bagSizeFormGroup) bagSizeFormGroup.style.display = 'block';
                
                // Hide roll-specific fields
                const tripNumberGroup = document.getElementById('tripNumberGroup');
                const rollReferenceGroup = document.getElementById('rollReferenceGroup');
                const rollSizeFormGroup = document.getElementById('rollSizeFormGroup');
                
                if (tripNumberGroup) tripNumberGroup.style.display = 'none';
                if (rollReferenceGroup) rollReferenceGroup.style.display = 'none';
                if (rollSizeFormGroup) rollSizeFormGroup.style.display = 'none';
                
                // Call selectProductType if available, otherwise use direct approach
                if (window.selectProductType && typeof window.selectProductType === 'function') {
                  window.selectProductType('bag');
                } else {
                  // Direct approach - load CNC batches (no reference number for bags)
                  // Try multiple ways to load batches
                  if (window.loadBagReferences) {
                    window.loadBagReferences();
                  } else if (window.loadBagReferencesFull) {
                    window.loadBagReferencesFull();
                  } else if (typeof loadBagReferencesFull === 'function') {
                    loadBagReferencesFull();
                  }
                  
                  // Update summary if function exists
                  if (typeof updateSummary === 'function') {
                    updateSummary();
                  }
                }
              });
              
              // Auto-select if function is available
              if (window.selectProductType && typeof window.selectProductType === 'function') {
                console.log('Auto-selecting Bag');
                window.selectProductType('bag');
              } else {
                // Wait for function
                const checkInterval = setInterval(function() {
                  if (window.selectProductType && typeof window.selectProductType === 'function') {
                    clearInterval(checkInterval);
                    console.log('Auto-selecting Bag - function now available');
                    window.selectProductType('bag');
                  }
                }, 100);
                
                // Stop checking after 3 seconds
                setTimeout(function() {
                  clearInterval(checkInterval);
                  // Manually trigger selection
                  btn.click();
                }, 3000);
              }
            }
            
            // Try immediately and on various events
            setupBagButton();
            if (document.readyState === 'loading') {
              document.addEventListener('DOMContentLoaded', setupBagButton);
            }
            setTimeout(setupBagButton, 200);
            setTimeout(setupBagButton, 500);
            window.addEventListener('load', setupBagButton);
          })();
          <?php endif; ?>
        </script>
        <?php if (!$canAccessRoll && !$canAccessBag): ?>
        <div style="padding:15px; background:#fff3cd; border:1px solid #ffc107; border-radius:6px; color:#856404;">
          <strong>⚠️ Access Restricted</strong><br>
          You do not have permission to access any product type in FG Entry.
        </div>
        <?php endif; ?>
      </div>
      <input type="hidden" id="product_type" name="product_type" value="" required>
      <?php if ($canAccessRoll && !$canAccessBag): ?>
      <small style="color:#6c757d; display:block; margin-top:5px;">
        <i class="fas fa-info-circle"></i> Your role (<?php echo htmlspecialchars($_SESSION['role']); ?>) can only access Roll entries.
      </small>
      <?php elseif (!$canAccessRoll && $canAccessBag): ?>
      <small style="color:#6c757d; display:block; margin-top:5px;">
        <i class="fas fa-info-circle"></i> Your role (<?php echo htmlspecialchars($_SESSION['role']); ?>) can only access Bag entries.
      </small>
      <?php endif; ?>
    </div>

    <!-- Shift in Charge & Project - Always visible at top (session/projects) -->
    <div class="form-group">
      <label>Shift in charge: <span style="color:red;">*</span></label>
      <input type="text" id="shift_in_charge" name="shift_in_charge" value="<?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'); ?>" placeholder="Auto-filled from session" readonly style="background-color:#f0f0f0;">
    </div>
    <div class="form-group">
      <label>Project:</label>
      <div class="btn-group" id="projectGroup"><?php
        if (!empty($projects)) {
          foreach ($projects as $idx => $p) {
            $pid = (int)($p['id'] ?? 0);
            $pname = htmlspecialchars($p['project_name'] ?? $p['name'] ?? 'Project ' . ($idx + 1));
            $sel = $idx === 0 ? ' selected' : '';
            echo '<button type="button" class="btn' . $sel . '" data-project-id="' . $pid . '" onclick="if(typeof selectProject===\'function\')selectProject(this,' . $pid . ',\'' . addslashes($pname) . '\')">' . $pname . '</button>';
          }
        }
      ?></div>
      <small id="project_loading" style="display:<?php echo empty($projects) ? 'block' : 'none'; ?>; color:#7f8c8d; font-size:0.75em; margin-top:2px;">Loading projects...</small>
      <input type="hidden" id="project_id" name="project_id" value="<?php echo !empty($projects) ? (int)($projects[0]['id'] ?? 0) : ''; ?>">
    </div>

    <!-- Trip Number - Shown only when Roll is selected -->
    <div class="form-group" id="tripNumberGroup" style="display:none;">
      <label>Trip Number: <span style="color:red;">*</span></label>
      <select id="trip_number" name="trip_number" required onchange="if(typeof onFgTripChange === 'function') onFgTripChange();">
        <option value="">-- Select Trip Number --</option>
        <?php foreach($fgTripNumbers as $tripData): ?>
        <option value="<?php echo htmlspecialchars($tripData['trip']); ?>">
          Trip <?php echo htmlspecialchars($tripData['trip']); ?>
          <?php if (!empty($tripData['last_transfer_date'])): ?>
            (Last: <?php echo date('Y-m-d', strtotime($tripData['last_transfer_date'])); ?>)
          <?php endif; ?>
        </option>
        <?php endforeach; ?>
      </select>
      <small style="color:#6c757d; display:block; margin-top:8px;">
        Select a trip number from roll transfers submitted as FG
        <?php if (empty($fgTripNumbers)): ?>
          <span style="color:#e74c3c;">(No trips found)</span>
        <?php endif; ?>
      </small>
    </div>
    <script>
    (function(){
      var refs = <?php echo json_encode($fgRollTripReferences); ?>;
      window.fgRollRefsInline = refs;
      function populateRefFromTrip() {
        var sel = document.getElementById('trip_number');
        var inp = document.getElementById('roll_reference_search');
        if (!sel || !inp || !sel.value) return;
        var trip = String(sel.value);
        var m = refs.find(function(r){ return String(r.trip) === trip || String(r.trip) === String(parseInt(trip)); });
        if (m) {
          inp.value = m.reference_number;
          inp.disabled = false;
          inp.readOnly = true;
          inp.style.backgroundColor = '#f0f0f0';
          var h = document.getElementById('roll_reference_hint');
          if (h) h.innerHTML = '<span style="color:#27ae60; font-weight:600;">✓ Reference auto-filled for Trip ' + trip + '</span>';
          var hid = document.getElementById('reference_number');
          if (hid) hid.value = m.reference_number;
        }
      }
      var tripEl = document.getElementById('trip_number');
      if (tripEl) {
        tripEl.addEventListener('change', populateRefFromTrip);
        if (tripEl.value) populateRefFromTrip();
      }
      window.populateRollRefFromTrip = populateRefFromTrip;
    })();
    </script>

    <!-- Reference Number (for Rolls only - shown after Trip Number) -->
    <div class="form-group" id="rollReferenceGroup" style="display:none; min-height:100px; padding:10px 0;">
      <label>Reference Number: <span style="color:red;">*</span></label>
      <div style="display:flex; gap:15px; align-items:flex-start; margin-bottom:10px;">
        <div style="flex:1; position:relative;">
          <input type="text"
                 id="roll_reference_search"
                 placeholder="Select a trip number first..."
                 style="padding:10px; border:1px solid #ccc; border-radius:6px; width:100%;"
                 onkeyup="filterRollTripReferences()"
                 onfocus="showRollReferenceDropdown()"
                 disabled>
          <div id="roll_reference_dropdown"
               style="display:none; max-height:220px; overflow-y:auto; border:1px solid #ccc; border-radius:6px; background:#fff; position:absolute; z-index:1000; width:100%; top:100%; box-shadow:0 4px 6px rgba(0,0,0,0.1); margin-top:2px;">
            <div id="roll_no_references_message"
                 style="display:none; padding:15px; text-align:center; color:#999; font-style:italic;">
              Please select a trip number first.
            </div>
          </div>
        </div>
        <div style="display:flex; align-items:center; padding-top:0;">
          <button type="button" onclick="addRollReference()" class="modern-add-btn">
            <span class="btn-icon-wrapper">
              <i class="fas fa-plus"></i>
            </span>
            <span class="btn-text">Add</span>
          </button>
        </div>
      </div>
      <div id="roll_selected_reference" style="margin-top:10px; min-height:30px;"></div>
      <small id="roll_reference_hint" style="color:#6c757d; display:block; margin-top:5px;">Select a trip number first to load references.</small>
    </div>

    <!-- All other fields below this will be hidden until product/entry type is selected -->
    <div id="productFieldsContainer" style="display:<?php echo (!$canAccessRoll && $canAccessBag) ? 'block' : 'none'; ?>">

    <!-- Roll-specific fields (shown when Roll is selected) - MOVED INSIDE productFieldsContainer -->
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
    
    <!-- Delivered Quantity / Total Weight (for Rolls only - shown when Roll is selected) -->
    <div class="form-group" id="rollWeightGroup" style="display:none;">
      <label>Delivered Quantity / Total Weight (kg): <span style="color:red;">*</span></label>
      <input type="number" id="delivered_quantity" name="delivered_quantity" min="0.01" step="0.01" placeholder="Enter total weight in kg" required>
      <small style="color:#6c757d; display:block; margin-top:5px;">
        <i class="fas fa-info-circle"></i> Enter the total weight (kg) delivered for this roll entry
      </small>
    </div>
    
    <!-- Total Area (for Rolls - optional) -->
    <div class="form-group" id="rollAreaGroup" style="display:none;">
      <label>Total Area (sqm):</label>
      <input type="number" id="total_area" name="total_area" min="0" step="0.01" placeholder="Enter total area in sqm">
      <small style="color:#6c757d; display:block; margin-top:5px;">
        <i class="fas fa-info-circle"></i> Optional: Enter the total area in square meters
      </small>
    </div>
    
    <!-- Hidden field to store bundle roll list -->
    <input type="hidden" id="bundle_roll_list" name="bundle_roll_list" value="">

    <!-- CNC Cutting Batch (for Bags) -->
    <div class="form-group" id="bagCncBatchGroup">
      <label>CNC Cutting Batch: <span style="color:red;">*</span></label>
      <select id="bag_cnc_cutting_batch" name="bag_cnc_cutting_batch" onchange="if(window.updateReferenceFromCNCBatch){window.updateReferenceFromCNCBatch({target:this});}">
        <option value="">Select Product Type First</option>
      </select>
      <small id="cnc_batch_hint" style="color:#6c757d; display:block; margin-top:5px;">Select a CNC cutting batch for bags</small>
    </div>

    <!-- Bag fields - immediately after CNC Batch so they show without scrolling -->
    <div class="form-group" id="bagSizeFormGroup">
      <label>Bag Size: <span id="bagSizeLockedLabel" style="display:none; color:#4caf50; font-weight:normal;">(locked from batch)</span></label>
      <small id="bagSizeHint" style="color:#2196F3; display:block; margin-bottom:8px; font-weight:500;">
        <i class="fas fa-info-circle"></i> Select a CNC Cutting Batch first. Bag size will be set from the batch and cannot be changed.
      </small>
      <div style="margin-bottom: 10px; max-height: 160px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; border-radius: 5px;">
        <div class="btn-group" id="bagSizeButtonGroup">
          <button type="button" class="btn" onclick="selectBagSize('2000mmX1500mm', null, this)">2000mmX1500mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1200mmX950mm', null, this)">1200mmX950mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1250mmX1000mm', null, this)">1250mmX1000mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1225mmX1000mm', null, this)">1225mmX1000mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1300mmX1050mm', null, this)">1300mmX1050mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1600mmX850mm', null, this)">1600mmX850mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1100mmX850mm', null, this)">1100mmX850mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1200mmX600mm', null, this)">1200mmX600mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1100mmX800mm', null, this)">1100mmX800mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1125mmX900mm', null, this)">1125mmX900mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1150mmX800mm', null, this)">1150mmX800mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1150mmX850mm', null, this)">1150mmX850mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1150mmX900mm', null, this)">1150mmX900mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1700mmX1250mm', null, this)">1700mmX1250mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1050mmX800mm', null, this)">1050mmX800mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1075mmX850mm', null, this)">1075mmX850mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1030mmX700mm', null, this)">1030mmX700mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1000mmX800mm', null, this)">1000mmX800mm</button>
          <button type="button" class="btn" onclick="selectBagSize('950mmX750mm', null, this)">950mmX750mm</button>
          <button type="button" class="btn" onclick="selectBagSize('950mmX500mm', null, this)">950mmX500mm</button>
          <button type="button" class="btn" onclick="selectBagSize('830mmX600mm', null, this)">830mmX600mm</button>
          <button type="button" class="btn" onclick="selectBagSize('300mmX299mm', null, this)">300mmX299mm</button>
          <button type="button" class="btn" onclick="selectBagSize('500mmX499mm', null, this)">500mmX499mm</button>
          <button type="button" class="btn" onclick="selectBagSize('700mmX700mm', null, this)">700mmX700mm</button>
          <button type="button" class="btn" onclick="selectBagSize('850mmX700mm', null, this)">850mmX700mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1030mmX750mm', null, this)">1030mmX750mm</button>
          <button type="button" class="btn" onclick="selectBagSize('1000mmX700mm', null, this)">1000mmX700mm</button>
          <button type="button" class="btn custom-bag-size-btn" onclick="selectBagSize('custom', null, this)" style="background:#6c757d;color:#fff;">Custom (Enter manually)</button>
        </div>
      </div>
      <input type="text" id="bag_size_custom" placeholder="Enter custom bag size" style="margin-top: 10px; display: none;">
      <input type="hidden" id="bag_size" name="bag_size" value="" required>
    </div>
    <div class="form-group" id="recommendedWeightGroup">
      <label>Recommended Weight (kg):</label>
      <input type="number" id="recommended_weight" name="recommended_weight" min="1" step="0.01" readonly style="background-color: #f0f0f0;" placeholder="Fetched from database">
    </div>
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
    <div class="form-group" id="actualWeightBagGroup">
      <label>Actual Weight (kg): <span style="color:red;">*</span></label>
      <input type="number" id="actual_weight_bag" name="actual_weight_bag" min="1" step="1" placeholder="Enter actual weight (kg)" required>
    </div>
    <div class="form-group" id="qualityCheckedFormGroup">
      <label>Quality Checked (pcs): <span id="totalPrintedQtyLabel" style="color:#27ae60; font-weight:600; font-size:13px;"></span> <span id="maxBrandedQtyLabel" style="color:#2196F3; font-weight:normal; font-size:13px;"></span></label>
      <input type="number" id="quality_checked" name="quality_checked" min="1" onchange="if(window.checkQualityCheckedAgainstBatchMax)window.checkQualityCheckedAgainstBatchMax(); if(window.validateQualityChecked)window.validateQualityChecked(true); if(window.updateActualWeightFromQualityChecked)window.updateActualWeightFromQualityChecked(); if(window.calculateRejected)window.calculateRejected();" onblur="if(window.checkQualityCheckedAgainstBatchMax)window.checkQualityCheckedAgainstBatchMax(); if(window.validateQualityChecked)window.validateQualityChecked(true); if(window.calculateRejected)window.calculateRejected();" oninput="if(window.checkQualityCheckedAgainstBatchMax)window.checkQualityCheckedAgainstBatchMax(); if(window.updateActualWeightFromQualityChecked)window.updateActualWeightFromQualityChecked(); if(window.calculateRejected)window.calculateRejected(); if(window.debounceValidateQualityChecked)window.debounceValidateQualityChecked();" placeholder="Enter manually">
      <small id="qualityCheckedHint" style="color:#6c757d; display:block; margin-top:5px;"><i class="fas fa-info-circle"></i> Enter the number of bags to be quality checked</small>
    </div>
    <div class="form-group" id="passedQtyFormGroup">
      <label>Passed Quantity (pcs): </label>
      <input type="number" id="passed_qty" name="passed_qty" min="0" onchange="if(window.calculateRejected)window.calculateRejected();" oninput="if(window.calculateRejected)window.calculateRejected();" placeholder="Enter passed qty">
    </div>
    <div class="form-group" id="rejectedQtyFormGroup">
      <label>Rejected Quantity (pcs): </label>
      <input type="number" id="rejected_qty" name="rejected_qty" min="0" readonly style="background-color: #f0f0f0;">
    </div>
    <div class="actions-after-cnc" id="actionsAfterCnc" style="display:<?php echo (!$canAccessRoll && $canAccessBag) ? 'block' : 'none'; ?>">
      <button type="submit" form="fgForm" class="submit-btn">Submit</button>
      <button type="button" class="clear-btn" onclick="var f=document.getElementById('fgForm');if(f){f.reset();}if(typeof clearForm==='function'){clearForm();}">Clear</button>
    </div>

    <!-- Reference Number (for Bags only - hidden for rolls) -->
    <div class="form-group" id="bagReferenceGroup" style="display:none;">
      <label>Reference Number: <span style="color:red;">*</span></label>
      <select id="bag_reference_number" onchange="updateCNCBatchFromReference()">
        <option value="">Select Product Type First</option>
      </select>
      <small id="reference_hint" style="color:#6c757d; display:block; margin-top:5px;"></small>
      <div id="bundleInfo" style="display:none; background:#e3f2fd; padding:12px; border-radius:6px; margin-top:10px; border-left:4px solid #2196F3;">
        <strong style="color:#1976D2;"><i class="fas fa-info-circle"></i> Bundle Details:</strong>
        <div id="bundleRollList" style="margin-top:8px; color:#424242; font-size:14px;"></div>
      </div>
    </div>

    <input type="hidden" id="reference_number" name="reference_number" required>
    <input type="hidden" id="delivered_quantity" name="delivered_quantity" value="0">
    
    <script>
    // Reference to CNC Batch mapping (for bags)
    const refToBatchMap = <?php echo json_encode($refToBatchMap); ?>;
    const bagReferences = <?php echo json_encode(array_keys($refToBatchMap)); ?>;
    
    // Reference to Bag Size mapping (from branding_entries)
    const refToBagSizeMap = <?php echo json_encode($refToBagSizeMap); ?>;
    
    // Reference to Branded Quantity mapping (total bags produced in branding)
    const refToBrandedQtyMap = <?php echo json_encode($refToBrandedQtyMap); ?>;
    
    // Bag size to recommended weight mapping from database (attach to window for use in later script blocks)
    window.bagSizeToRecommendedWeightFromDB = <?php echo json_encode($bagSizeToRecommendedWeightMap); ?>;
    const bagSizeToRecommendedWeightFromDB = window.bagSizeToRecommendedWeightFromDB;
    
    // FG roll references by trip (from roll_transfer where to_location='FG')
    const fgTripReferences = <?php echo json_encode($fgRollTripReferences); ?>;
    window.fgTripReferences = fgTripReferences;  // Global for onchange handler
    console.log('📦 Loaded fgTripReferences from PHP:', fgTripReferences.length, 'references');
    if (fgTripReferences.length > 0) {
      console.log('📦 Sample references:', fgTripReferences.slice(0, 3));
    }
    const fgTripNumbers = <?php echo json_encode($fgTripNumbers); ?>;
    
    console.log('FG Trip Numbers from PHP:', fgTripNumbers);
    console.log('FG Trip Numbers count:', fgTripNumbers ? fgTripNumbers.length : 0);
    
    // Function to load FG Trip Numbers into dropdown
    function loadFGTripNumbers() {
      console.log('loadFGTripNumbers called');
      const tripSelect = document.getElementById('trip_number');
      if (!tripSelect) {
        console.warn('Trip number select element not found');
        return;
      }
      
      console.log('fgTripNumbers data:', fgTripNumbers);
      console.log('fgTripNumbers length:', fgTripNumbers ? fgTripNumbers.length : 0);
      
      // Don't clear if options already exist (they're populated from PHP)
      const existingOptions = tripSelect.querySelectorAll('option');
      if (existingOptions.length > 1) {
        console.log('Trip numbers already populated from PHP, skipping JavaScript population');
        return;
      }
      
      tripSelect.innerHTML = '<option value="">-- Select Trip Number --</option>';
      
      if (fgTripNumbers && fgTripNumbers.length > 0) {
        fgTripNumbers.forEach(tripData => {
          const option = document.createElement('option');
          option.value = tripData.trip;
          const dateStr = tripData.last_transfer_date ? new Date(tripData.last_transfer_date).toLocaleDateString() : '';
          option.textContent = 'Trip ' + tripData.trip + (dateStr ? ' (Last: ' + dateStr + ')' : '');
          tripSelect.appendChild(option);
        });
        console.log('Loaded', fgTripNumbers.length, 'trip numbers into dropdown via JavaScript');
      } else {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'No trips found';
        option.disabled = true;
        tripSelect.appendChild(option);
        console.warn('No trip numbers found in fgTripNumbers array');
      }
    }
    
    // Make function available globally
    window.loadFGTripNumbers = loadFGTripNumbers;

    let fgSelectedReferences = [];
    let currentTripFilter = '';

    function onFgTripChange() {
      const tripSelect = document.getElementById('trip_number');
      const rollReferenceGroup = document.getElementById('rollReferenceGroup');
      if (!tripSelect) {
        console.error('❌ trip_number select not found');
        return;
      }

      const selectedTrip = tripSelect.value;
      currentTripFilter = selectedTrip;
      console.log('onFgTripChange called with trip:', selectedTrip);
      
      if (selectedTrip) {
        // CRITICAL: Ensure productFieldsContainer is visible first (rollReferenceGroup is inside it)
        const productFieldsContainer = document.getElementById('productFieldsContainer');
        if (productFieldsContainer) {
          productFieldsContainer.style.display = 'block';
          console.log('✅ productFieldsContainer shown for rollReferenceGroup');
        }
        
        // Enable reference section and auto-fetch reference number after trip is selected
        console.log('Trip selected:', selectedTrip);
        console.log('fgTripReferences available:', typeof fgTripReferences !== 'undefined' ? fgTripReferences.length : 'undefined');
        
        // Ensure rollReferenceGroup is visible (it should already be shown when Roll was selected)
        const rollRefGroup = document.getElementById('rollReferenceGroup');
        if (rollRefGroup) {
          // Force show with !important to ensure it's visible
          rollRefGroup.style.setProperty('display', 'block', 'important');
          rollRefGroup.style.setProperty('visibility', 'visible', 'important');
          const computedStyle = window.getComputedStyle(rollRefGroup);
          console.log('✅ rollReferenceGroup is visible - computed display:', computedStyle.display, 'visibility:', computedStyle.visibility);
          
          // Enable searchInput now that trip is selected
          const searchInputEl = document.getElementById('roll_reference_search');
          if (searchInputEl) {
            searchInputEl.disabled = false;
            searchInputEl.placeholder = 'Search or type reference number...';
            console.log('✅ roll_reference_search enabled');
          }
        } else {
          console.warn('⚠️ rollReferenceGroup not found - it should have been shown when Roll was selected');
          // Try to find it with a delay
          setTimeout(function() {
            const found = document.getElementById('rollReferenceGroup');
            if (found) {
              found.style.setProperty('display', 'block', 'important');
              found.style.setProperty('visibility', 'visible', 'important');
              console.log('✅ rollReferenceGroup found and shown (delayed in onFgTripChange)');
            }
          }, 200);
        }
        
        loadRollReferencesForTrip(selectedTrip);
        
        // Auto-fill reference number from roll_transfer for this trip (run immediately + delayed backup)
        function autoFillReferenceForTrip() {
          const refs = window.fgTripReferences || (typeof fgTripReferences !== 'undefined' ? fgTripReferences : []);
          console.log('🔍 Auto-fetching reference for trip:', selectedTrip);
          console.log('🔍 fgTripReferences available:', refs ? refs.length : 0);
          if (refs && refs.length > 0) {
            console.log('🔍 Sample fgTripReferences:', refs.slice(0, 3));
          }
          
          // Filter references for the selected trip (handle both string and number comparisons)
          const tripRefs = refs ? refs.filter(ref => {
            const refTrip = String(ref.trip || '');
            const selTrip = String(selectedTrip || '');
            const match = refTrip === selTrip || parseInt(refTrip) === parseInt(selTrip);
            if (match) {
              console.log('✅ Match found: ref.trip=', ref.trip, 'selectedTrip=', selectedTrip);
            }
            return match;
          }) : [];
          console.log('✅ Filtered tripRefs for trip', selectedTrip, ':', tripRefs);
          console.log('✅ Found', tripRefs.length, 'references for trip', selectedTrip);
          
          if (tripRefs && tripRefs.length > 0) {
            const firstRef = tripRefs[0];
            console.log('Auto-selecting first reference:', firstRef.reference_number);
            
            // Clear any existing references first
            if (typeof fgSelectedReferences !== 'undefined') {
              fgSelectedReferences = [];
            }
            
            // Add the first reference automatically
            if (typeof fgSelectedReferences !== 'undefined') {
              fgSelectedReferences.push({
                id: 'fg_ref_' + Date.now() + '_' + Math.random().toString(36).substr(2, 5),
                reference: firstRef.reference_number,
                trip: selectedTrip,
                amountKg: parseFloat(firstRef.total_amount) || 0,
                areaSqm: parseFloat(firstRef.total_area) || 0,
                rollSize: firstRef.roll_size || ''
              });
            }
            
            // Update UI - auto-fill reference number field
            const searchInputEl = document.getElementById('roll_reference_search');
            if (searchInputEl) {
              searchInputEl.value = firstRef.reference_number;
              // Make search input readonly after auto-fill
              searchInputEl.readOnly = true;
              searchInputEl.style.backgroundColor = '#f0f0f0';
              searchInputEl.style.cursor = 'not-allowed';
              console.log('✅ Reference number field populated:', firstRef.reference_number);
            } else {
              console.error('❌ roll_reference_search input not found for auto-fill');
            }
            
            // Render selected references
            if (typeof renderFgSelectedReferences === 'function') {
              renderFgSelectedReferences();
            }
            if (typeof updateReferenceHiddenField === 'function') {
              updateReferenceHiddenField();
            }
            
            // Roll size, weight, and area fields are not needed - skip auto-selection
            
            const hintEl = document.getElementById('roll_reference_hint');
            if (hintEl) {
              const refDisplay = typeof escapeHtml === 'function' ? escapeHtml(firstRef.reference_number) : firstRef.reference_number.replace(/</g, '&lt;').replace(/>/g, '&gt;');
              hintEl.innerHTML = '<span style="color:#27ae60; font-weight:600;">✓ Reference ' + refDisplay + ' auto-selected for Trip ' + selectedTrip + '. Roll size auto-selected and locked.</span>';
            }
          } else {
            const hintEl = document.getElementById('roll_reference_hint');
            if (hintEl) {
              hintEl.textContent = 'No references found for Trip ' + selectedTrip + '.';
            }
            console.warn('⚠️ No references found for trip:', selectedTrip);
          }
        }
        // Run immediately and again after short delay (in case DOM not ready)
        autoFillReferenceForTrip();
        setTimeout(autoFillReferenceForTrip, 100);
      } else {
        // Trip cleared - hide reference group and reset
        if (rollReferenceGroup) rollReferenceGroup.style.display = 'none';
        
        const searchInputReset = document.getElementById('roll_reference_search');
        if (searchInputReset) {
          searchInputReset.disabled = true;
          searchInputReset.readOnly = false;
          searchInputReset.style.backgroundColor = '';
          searchInputReset.style.cursor = '';
          searchInputReset.value = '';
        }
        
        if (typeof clearRollReferenceDropdown === 'function') {
          clearRollReferenceDropdown();
        }
        
        const hintReset = document.getElementById('roll_reference_hint');
        if (hintReset) {
          hintReset.textContent = 'Select a trip number first to load references.';
        }
        
        // Clear references when trip is cleared
        if (typeof fgSelectedReferences !== 'undefined') {
          fgSelectedReferences = [];
        }
        if (typeof renderFgSelectedReferences === 'function') {
          renderFgSelectedReferences();
        }
        if (typeof updateReferenceHiddenField === 'function') {
          updateReferenceHiddenField();
        }
        
        // Unlock roll size buttons when trip is cleared
        if (typeof unlockRollSizeButtons === 'function') {
          unlockRollSizeButtons();
        }
        // Clear roll size selection
        const rollSizeInput = document.getElementById('fg_roll_size');
        if (rollSizeInput) {
          rollSizeInput.value = '';
        }
        // Remove selected class from all buttons
        document.querySelectorAll('.roll-size-btn').forEach(b => {
          b.classList.remove('selected');
        });
      }
    }
    window.onFgTripChange = onFgTripChange;

    function loadRollReferencesForTrip(trip) {
      const dropdown = document.getElementById('roll_reference_dropdown');
      if (!dropdown) return;
      const message = document.getElementById('roll_no_references_message');
      dropdown.innerHTML = '';
      if (message) dropdown.appendChild(message);

      // Filter references for the selected trip (handle both string and number comparisons)
      const refs = window.fgTripReferences || (typeof fgTripReferences !== 'undefined' ? fgTripReferences : []);
      const tripRefs = refs.filter(ref => {
        const refTrip = String(ref.trip || '');
        const selTrip = String(trip || '');
        return refTrip === selTrip || parseInt(refTrip) === parseInt(selTrip);
      });
      if (tripRefs.length === 0) {
        showNoFgReferencesMessage('No references found for the selected trip.');
        return;
      }

      tripRefs.forEach(ref => {
        const option = document.createElement('div');
        option.className = 'roll-reference-option';
        option.setAttribute('data-ref', ref.reference_number);
        option.setAttribute('data-total-amount', ref.total_amount);
        option.setAttribute('data-total-area', ref.total_area);
        option.setAttribute('data-roll-size', ref.roll_size || '');
        option.setAttribute('style', 'display:block; padding:10px; cursor:pointer; border-bottom:1px solid #eee;');
        option.innerHTML = `<strong>${escapeHtml(ref.reference_number)}</strong><br><small style="color:#27ae60; font-weight:600;">Qty: ${parseFloat(ref.total_amount).toFixed(2)} kg${ref.total_area ? ', Area: ' + parseFloat(ref.total_area).toFixed(2) + ' sqm' : ''}</small>`;
        option.addEventListener('click', function() {
          selectFgReferenceFromDropdown(ref.reference_number);
        });
        dropdown.appendChild(option);
      });

      if (message) message.style.display = 'none';
      dropdown.style.display = 'none';
    }

    function showNoFgReferencesMessage(text) {
      const message = document.getElementById('roll_no_references_message');
      if (message) {
        message.textContent = text || 'No references available for the selected trip.';
        message.style.display = 'block';
      }
    }

    function clearRollReferenceDropdown() {
      const dropdown = document.getElementById('roll_reference_dropdown');
      const message = document.getElementById('roll_no_references_message');
      if (!dropdown) return;
      dropdown.innerHTML = '';
      if (message) {
        message.style.display = 'block';
        message.textContent = 'Please select a trip number first.';
        dropdown.appendChild(message);
      }
    }

    function filterRollTripReferences() {
      const searchInput = document.getElementById('roll_reference_search');
      const dropdown = document.getElementById('roll_reference_dropdown');
      if (!searchInput || !dropdown) return;

      const term = searchInput.value.trim().toLowerCase();
      const options = dropdown.querySelectorAll('.roll-reference-option');
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

      const noRefsMsg = document.getElementById('roll_no_references_message');
      if (noRefsMsg) noRefsMsg.style.display = visibleCount === 0 ? 'block' : 'none';
      dropdown.style.display = visibleCount === 0 ? 'none' : 'block';
    }

    function showRollReferenceDropdown() {
      const dropdown = document.getElementById('roll_reference_dropdown');
      const tripSelect = document.getElementById('trip_number');
      if (!dropdown || !tripSelect || !tripSelect.value) return;
      filterRollTripReferences();
      dropdown.style.display = 'block';
    }

    function selectFgReferenceFromDropdown(refNumber) {
      const searchInput = document.getElementById('roll_reference_search');
      const dropdown = document.getElementById('roll_reference_dropdown');
      if (searchInput) searchInput.value = refNumber;
      if (dropdown) dropdown.style.display = 'none';
    }

    function addRollReference() {
      const searchInput = document.getElementById('roll_reference_search');
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

      const dropdown = document.getElementById('roll_reference_dropdown');
      const options = dropdown ? dropdown.querySelectorAll('.roll-reference-option') : [];
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
      if (fgSelectedReferences.some(ref => ref.reference === reference)) {
        alert('This reference has already been added.');
        const searchInputEl = document.getElementById('roll_reference_search');
        if (searchInputEl) {
          searchInputEl.value = '';
        }
        return;
      }

      const totalAmount = parseFloat(matchedOption.getAttribute('data-total-amount')) || 0;
      const totalArea = parseFloat(matchedOption.getAttribute('data-total-area')) || 0;
      const rollSize = matchedOption.getAttribute('data-roll-size') || '';
      
      fgSelectedReferences.push({
        id: 'fg_ref_' + Date.now() + '_' + Math.random().toString(36).substr(2, 5),
        reference,
        trip: currentTripFilter,
        amountKg: totalAmount,
        areaSqm: totalArea,
        rollSize: rollSize
      });

      // Auto-select roll size if this is the first reference and roll size is available
      if (fgSelectedReferences.length === 1 && rollSize) {
        if (typeof autoSelectRollSize === 'function') {
          autoSelectRollSize(rollSize);
          // Lock roll size buttons after auto-selection
          setTimeout(function() {
            if (typeof lockRollSizeButtons === 'function') {
              lockRollSizeButtons();
              console.log('✅ Roll size locked after reference added');
            }
          }, 200);
        }
      } else if (fgSelectedReferences.length > 1) {
        // If multiple references, ensure roll size is locked (already selected from first reference)
        if (typeof lockRollSizeButtons === 'function') {
          lockRollSizeButtons();
        }
      }

      renderFgSelectedReferences();
      updateReferenceHiddenField();
      searchInput.value = '';
      if (dropdown) dropdown.style.display = 'none';
    }

    function renderFgSelectedReferences() {
      const container = document.getElementById('roll_selected_reference');
      if (!container) return;
      if (fgSelectedReferences.length === 0) {
        container.innerHTML = '<small style="color:#999;">No references added yet.</small>';
        return;
      }

      let html = '';
      fgSelectedReferences.forEach(ref => {
        html += `
          <div class="delivery-ref-row" style="padding:12px; background:#e8f5e9; border:2px solid #4caf50; border-radius:6px; margin-bottom:10px;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <div>
                <strong style="color:#2e7d32;">${escapeHtml(ref.reference)}</strong>
                <br><small style="color:#666;">Trip: ${escapeHtml(ref.trip.toString())}</small>
              </div>
              <button type="button" onclick="removeFgReference('${ref.id}')" style="padding:6px 10px; background:#e74c3c; color:#fff; border:none; border-radius:4px; cursor:pointer; font-weight:600;">Remove</button>
            </div>
            <div style="margin-top:8px; font-size:13px; color:#2c3e50;">
              KG: ${ref.amountKg.toFixed(2)}
              ${ref.areaSqm ? ` | SQM: ${ref.areaSqm.toFixed(2)}` : ''}
            </div>
          </div>
        `;
      });

      container.innerHTML = html;
      updateDeliveredQuantityHidden();
    }

    function updateReferenceHiddenField() {
      const hiddenField = document.getElementById('reference_number');
      if (!hiddenField) return;
      hiddenField.value = fgSelectedReferences.map(ref => ref.reference).join(', ');
      updateDeliveredQuantityHidden();
    }

    function removeFgReference(id) {
      fgSelectedReferences = fgSelectedReferences.filter(ref => ref.id !== id);
      renderFgSelectedReferences();
      updateReferenceHiddenField();
      
      // Unlock roll size buttons if no references are selected
      if (fgSelectedReferences.length === 0) {
        if (typeof unlockRollSizeButtons === 'function') {
          unlockRollSizeButtons();
        }
        // Clear roll size selection
        const rollSizeInput = document.getElementById('fg_roll_size');
        if (rollSizeInput) {
          rollSizeInput.value = '';
        }
        // Remove selected class from all buttons
        document.querySelectorAll('.roll-size-btn').forEach(b => {
          b.classList.remove('selected');
        });
      }
    }

    function updateDeliveredQuantityHidden() {
      const hiddenQty = document.getElementById('delivered_quantity');
      if (!hiddenQty) return;
      const totalKg = fgSelectedReferences.reduce((sum, ref) => sum + (ref.amountKg || 0), 0);
      hiddenQty.value = totalKg.toFixed(2);
    }

    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    document.addEventListener('click', function(event) {
      const dropdown = document.getElementById('roll_reference_dropdown');
      const searchInput = document.getElementById('roll_reference_search');
      if (!dropdown || !searchInput) return;
      if (!dropdown.contains(event.target) && event.target !== searchInput) {
        dropdown.style.display = 'none';
      }
    });
    
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
    
    // Function to ensure roll fields are shown (called after DOM is ready)
    window.ensureRollFieldsVisible = function() {
      const elements = ['rollSizeFormGroup', 'rollWeightGroup', 'rollAreaGroup', 'formActions'];
      elements.forEach(id => {
        const el = document.getElementById(id);
        if (el && el.style.display === 'none') {
          el.style.display = 'block';
          if (id === 'formActions') el.style.visibility = 'visible';
          console.log('✅', id, 'shown via ensureRollFieldsVisible');
        }
      });
    };
    </script>

    <!-- Hidden CNC Cutting Batch field for form submission (populated from dropdown) - for bags only -->
    <input type="hidden" id="cnc_cutting_batch" name="cnc_cutting_batch" value="">

    <!-- Hidden datetime and shift (single instance, no duplicates) -->
    <input type="hidden" id="dateTime" name="date_time" value="<?php echo htmlspecialchars($php_date_db); ?>">
    <input type="hidden" id="shift" name="shift" value="<?php echo htmlspecialchars($php_shift); ?>">
    
    </div><!-- End productFieldsContainer -->

    <!-- Summary Section (Always Visible) -->
    <div class="form-group">
      <div id="summaryBox" class="summary-info"></div>
      <input type="hidden" id="summary" name="summary">
    </div>

    <!-- Submit and Clear Buttons - Always Visible -->
    <div class="actions" id="formActions" style="display:block; visibility:visible; margin-top:20px; padding:20px 0;">
      <button type="submit" class="submit-btn" id="submitBtn" style="display:inline-block;">Submit</button>
      <button type="reset" class="clear-btn" id="clearBtn" onclick="clearForm()" style="display:inline-block;">Clear</button>
    </div>
    
    <!-- Debug helper function -->
    <script>
    // Debug function to check field visibility
    window.debugRollFields = function() {
      console.log('=== DEBUG: Roll Fields Visibility ===');
      const fields = {
        'productFieldsContainer': document.getElementById('productFieldsContainer'),
        'tripNumberGroup': document.getElementById('tripNumberGroup'),
        'rollSizeFormGroup': document.getElementById('rollSizeFormGroup'),
        'rollWeightGroup': document.getElementById('rollWeightGroup'),
        'rollAreaGroup': document.getElementById('rollAreaGroup'),
        'formActions': document.getElementById('formActions'),
        'actionsAfterCnc': document.getElementById('actionsAfterCnc'),
        'product_type': document.getElementById('product_type')
      };
      
      Object.keys(fields).forEach(key => {
        const el = fields[key];
        if (el) {
          const style = window.getComputedStyle(el);
          console.log(`${key}:`, {
            exists: true,
            display: style.display,
            visibility: style.visibility,
            value: el.value || el.textContent?.substring(0, 50) || 'N/A'
          });
        } else {
          console.error(`${key}: NOT FOUND`);
        }
      });
      
      console.log('Product Type Value:', document.getElementById('product_type')?.value);
      console.log('=== END DEBUG ===');
    };
    </script>
  </form>
</div>

<script>
console.log('🚀 FG Entry JavaScript loaded successfully');

function updateTimeAndShift() {
  try {
    const now = new Date();
    const utc = now.getTime() + now.getTimezoneOffset()*60000;
    const dhaka = new Date(utc + 6*3600000);
    
    const dateTimeDisplay = document.getElementById("dateTimeDisplay");
    const shiftBanner = document.getElementById("shiftBanner");
    const dateTimeInput = document.getElementById("dateTime");
    const shiftInput = document.getElementById("shift");
    
    if (dateTimeDisplay) {
      dateTimeDisplay.innerHTML = "Date & Time: " + dhaka.toDateString() + " " + dhaka.toLocaleTimeString();
    }
    
    const yyyy = dhaka.getFullYear();
    const mm = String(dhaka.getMonth()+1).padStart(2,'0');
    const dd = String(dhaka.getDate()).padStart(2,'0');
    const hh = String(dhaka.getHours()).padStart(2,'0');
    const min = String(dhaka.getMinutes()).padStart(2,'0');
    const ss = String(dhaka.getSeconds()).padStart(2,'0');
    
    if (dateTimeInput) {
      dateTimeInput.value = `${yyyy}-${mm}-${dd} ${hh}:${min}:${ss}`;
    }
    
    const h = dhaka.getHours();
    const shift = (h >= 8 && h <= 19) ? "Day" : "Night";
    
    if (shiftBanner) {
      shiftBanner.innerText = "Shift: " + shift;
    }
    
    if (shiftInput) {
      shiftInput.value = shift;
    }
    
    if (typeof updateSummary === 'function') {
      updateSummary();
    }
  } catch(e) {
    console.error('Error in updateTimeAndShift:', e);
  }
}
setInterval(updateTimeAndShift,1000); 
updateTimeAndShift();

// Immediate check: read max from selected CNC batch, if quality_checked > max show notification and reset
function checkQualityCheckedAgainstBatchMax() {
  var productTypeEl = document.getElementById('product_type');
  if (!productTypeEl || productTypeEl.value !== 'bag') return;
  var cncSelect = document.getElementById('bag_cnc_cutting_batch');
  if (!cncSelect || !cncSelect.value || cncSelect.selectedIndex <= 0) return;
  var opt = cncSelect.options[cncSelect.selectedIndex];
  if (!opt) return;
  var maxAllowed = parseInt(opt.getAttribute('data-total-printed'), 10) || 0;
  if (maxAllowed <= 0) maxAllowed = parseInt(opt.getAttribute('data-print-qty'), 10) || 0;
  if (maxAllowed <= 0 && opt.textContent) {
    var m = opt.textContent.match(/\s[\u2014\u2013-]\s*(\d+)\s*pcs/i) || opt.textContent.match(/\s(\d+)\s*pcs/i);
    if (m) maxAllowed = parseInt(m[1], 10) || 0;
  }
  if (maxAllowed <= 0) return;
  var qcInput = document.getElementById('quality_checked');
  if (!qcInput) return;
  var val = parseInt(qcInput.value, 10) || 0;
  if (val <= maxAllowed) return;
  // User entered more than stored for this CNC batch - show notification immediately and reset
  var toastFn = window.showToast || (typeof showToast !== 'undefined' ? showToast : null);
  if (toastFn) {
    try { toastFn('Quality Checked cannot exceed ' + maxAllowed + ' pcs (stored for this CNC batch).', 'error'); } catch (e) {}
  }
  qcInput.value = maxAllowed;
  var hint = document.getElementById('qualityCheckedHint');
  if (hint) {
    hint.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <strong style="color:#e74c3c;">Max ' + maxAllowed + ' pcs for this batch</strong>';
    hint.style.color = '#e74c3c';
  }
  if (window.calculateRejected) window.calculateRejected();
  if (window.updateActualWeightFromQualityChecked) window.updateActualWeightFromQualityChecked();
}
window.checkQualityCheckedAgainstBatchMax = checkQualityCheckedAgainstBatchMax;

// Debounce timer for quality checked validation (for hint updates)
let qualityCheckedDebounceTimer = null;

function debounceValidateQualityChecked() {
  if (qualityCheckedDebounceTimer) clearTimeout(qualityCheckedDebounceTimer);
  qualityCheckedDebounceTimer = setTimeout(function() {
    if (window.validateQualityChecked) window.validateQualityChecked(false);
  }, 300);
}
window.debounceValidateQualityChecked = debounceValidateQualityChecked;

// Modern toast notification – centered on page, modal-style, auto-dismiss
function showToast(message, type) {
  if (!message) return;
  type = type || 'error';
  var targetBody = document.body;
  if (!targetBody) targetBody = document.getElementsByTagName('body')[0];
  try { if (window.top && window.top !== window && window.top.document && window.top.document.body) { targetBody = window.top.document.body; } } catch (e) {}
  if (!targetBody) return;
  var accentColor = type === 'error' ? '#dc3545' : (type === 'success' ? '#28a745' : '#007bff');
  var icon = type === 'error' ? '\u26a0' : (type === 'success' ? '\u2713' : '\u2139');
  var wrapper = document.createElement('div');
  wrapper.className = 'fg-toast-notification';
  wrapper.setAttribute('role', 'alert');
  wrapper.style.cssText = 'position:fixed;inset:0;z-index:2147483647;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.45);backdrop-filter:blur(4px);animation:fgToastFadeIn 0.25s ease-out;';
  var card = document.createElement('div');
  card.style.cssText = 'background:#fff;color:#212529;padding:24px 28px;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,0.2),0 0 0 1px rgba(0,0,0,0.06);max-width:400px;text-align:center;animation:fgToastScaleIn 0.3s ease-out;border-left:4px solid ' + accentColor + ';';
  card.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;gap:12px;font-size:16px;font-weight:600;">' + '<span style="color:' + accentColor + ';font-size:22px;">' + icon + '</span>' + '<span>' + String(message).replace(/<[^>]+>/g, '') + '</span></div>';
  wrapper.appendChild(card);
  var styleId = 'fg-toast-keyframes';
  if (!document.getElementById(styleId)) {
    var s = document.createElement('style');
    s.id = styleId;
    s.textContent = '@keyframes fgToastFadeIn{from{opacity:0}to{opacity:1}}@keyframes fgToastScaleIn{from{opacity:0;transform:scale(0.9)}to{opacity:1;transform:scale(1)}}';
    var head = document.head || document.getElementsByTagName('head')[0];
    if (head) head.appendChild(s);
  }
  targetBody.appendChild(wrapper);
  setTimeout(function() {
    wrapper.style.transition = 'opacity 0.3s';
    wrapper.style.opacity = '0';
    setTimeout(function() { try { if (wrapper.parentNode) wrapper.parentNode.removeChild(wrapper); } catch(e) {} }, 300);
  }, 4500);
}
window.showToast = showToast;

// Modern Warning Popup Functions (legacy; use showToast for quality-checked validation)
function showWarningPopup(message) {
  const popup = document.getElementById('warningPopup');
  const messageEl = document.getElementById('warningMessage');
  if (popup && messageEl) {
    messageEl.innerHTML = message;
    popup.classList.add('show');
  }
}

function closeWarningPopup() {
  const popup = document.getElementById('warningPopup');
  if (popup) {
    popup.classList.remove('show');
  }
}

// Close popup when clicking outside
document.addEventListener('click', function(event) {
  const popup = document.getElementById('warningPopup');
  if (popup && event.target === popup) {
    closeWarningPopup();
  }
});

// Hide Entry Type field on page load (if it exists from cache or old version)
document.addEventListener('DOMContentLoaded', function() {
  // Watch product_type input and auto-load batches when it becomes 'bag'
  const productTypeInput = document.getElementById('product_type');
  if (productTypeInput) {
    // Check current value immediately
    if (productTypeInput.value === 'bag') {
      console.log('DOMContentLoaded: product_type is already "bag", loading batches');
      setTimeout(function() {
        if (window.loadBagReferencesFull) {
          window.loadBagReferencesFull();
        }
      }, 100);
    }
    
    // Watch for changes using MutationObserver
    const observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        if (mutation.type === 'attributes' && mutation.attributeName === 'value') {
          const newValue = productTypeInput.value;
          if (newValue === 'bag') {
            console.log('MutationObserver: product_type changed to "bag", loading batches');
            setTimeout(function() {
              if (window.loadBagReferencesFull) {
                window.loadBagReferencesFull();
              }
            }, 50);
          }
        }
      });
    });
    
    // Also watch for direct value changes via input events
    productTypeInput.addEventListener('input', function() {
      if (this.value === 'bag') {
        console.log('Input event: product_type is "bag", loading batches');
        setTimeout(function() {
          if (window.loadBagReferencesFull) {
            window.loadBagReferencesFull();
          }
        }, 50);
      }
    });
    
    // Watch value property changes (for programmatic changes)
    let lastValue = productTypeInput.value;
    setInterval(function() {
      const currentValue = productTypeInput.value;
      if (currentValue !== lastValue && currentValue === 'bag') {
        console.log('Interval check: product_type changed to "bag", loading batches');
        lastValue = currentValue;
        setTimeout(function() {
          if (window.loadBagReferencesFull) {
            window.loadBagReferencesFull();
          }
        }, 50);
      }
      lastValue = currentValue;
    }, 200);
  }
  
  // Recalculate rejected qty on load when quality_checked / passed_qty already have values
  setTimeout(function() {
    if (window.calculateRejected) window.calculateRejected();
  }, 300);
  
  // Direct event listeners for product type buttons (backup to onclick)
  const rollBtn = document.getElementById('rollProductTypeBtn');
  const bagBtn = document.getElementById('bagProductTypeBtn');
  
  if (rollBtn) {
    rollBtn.addEventListener('click', function(e) {
      console.log('Roll button direct listener fired');
      e.preventDefault();
      e.stopPropagation();
      if (window.selectProductType && typeof window.selectProductType === 'function') {
        window.selectProductType('roll');
      } else {
        console.error('selectProductType not available in direct listener');
        // Fallback
        const input = document.getElementById('product_type');
        if (input) input.value = 'roll';
        const container = document.getElementById('productFieldsContainer');
        if (container) container.style.display = 'block';
        const tripGroup = document.getElementById('tripNumberGroup');
        if (tripGroup) tripGroup.style.display = 'block';
      }
    }, true);
  }
  
  if (bagBtn) {
    bagBtn.addEventListener('click', function(e) {
      console.log('Bag button direct listener fired');
      e.preventDefault();
      e.stopPropagation();
      if (window.selectProductType && typeof window.selectProductType === 'function') {
        window.selectProductType('bag');
      } else {
        console.error('selectProductType not available in direct listener');
        // Fallback
        const input = document.getElementById('product_type');
        if (input) input.value = 'bag';
        const container = document.getElementById('productFieldsContainer');
        if (container) container.style.display = 'block';
      }
    }, true);
  }
  
  const rollEntryTypeGroup = document.getElementById('rollEntryTypeGroup');
  if(rollEntryTypeGroup) {
    rollEntryTypeGroup.style.display = 'none';
    rollEntryTypeGroup.remove(); // Remove it completely from DOM
  }
  
  // Auto-select product type if user can only access one type
  <?php if (!$canAccessRoll && $canAccessBag): ?>
    // User can only access Bag - auto-select it
    function autoSelectBagType() {
      // Try to find the bag button by ID first (most reliable)
      const bagButton = document.getElementById('bagProductTypeBtn');
      
      if (bagButton) {
        // Ensure button is visible and enabled
        bagButton.style.display = '';
        bagButton.disabled = false;
        bagButton.style.pointerEvents = 'auto';
        bagButton.style.cursor = 'pointer';
        
        // Wait a moment for the page to fully render, then select
        setTimeout(function() {
          if (typeof selectProductType === 'function') {
            console.log('Auto-selecting Bag product type');
            selectProductType('bag');
          } else {
            // Function not ready yet, try clicking the button
            console.log('selectProductType not ready, clicking button directly');
            bagButton.click();
          }
        }, 100);
      } else {
        // Fallback: search for button
        setTimeout(function() {
          const buttons = document.querySelectorAll('.product-type-btn');
          buttons.forEach(btn => {
            const onclick = btn.getAttribute('onclick') || '';
            const text = btn.textContent.toLowerCase().trim();
            if (onclick.includes("'bag'") || onclick.includes('"bag"') || (text.includes('bag') && !text.includes('roll'))) {
              btn.style.display = '';
              btn.disabled = false;
              if (typeof selectProductType === 'function') {
                selectProductType('bag');
              } else {
                btn.click();
              }
            }
          });
        }, 200);
      }
    }
    
    // Try multiple times to ensure it works
    autoSelectBagType();
    setTimeout(autoSelectBagType, 300);
    setTimeout(autoSelectBagType, 600);
    
  <?php elseif ($canAccessRoll && !$canAccessBag): ?>
    // User can only access Roll - auto-select it
    function autoSelectRollType() {
      const rollButton = document.getElementById('rollProductTypeBtn');
      if (rollButton) {
        rollButton.style.display = '';
        rollButton.disabled = false;
        setTimeout(function() {
          if (window.selectProductType && typeof window.selectProductType === 'function') {
            window.selectProductType('roll');
          } else {
            rollButton.click();
          }
        }, 100);
      }
    }
    autoSelectRollType();
    setTimeout(autoSelectRollType, 300);
  <?php endif; ?>
  
  // Also hide any buttons with roll-entry-type-btn class
  const entryTypeButtons = document.querySelectorAll('.roll-entry-type-btn');
  entryTypeButtons.forEach(btn => {
    const parent = btn.closest('.form-group');
    if(parent && parent.id === 'rollEntryTypeGroup') {
      parent.style.display = 'none';
      parent.remove();
    }
  });
});

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

// Full implementation of selectProductType (replaces placeholder)
function selectProductTypeFull(type) {
  console.log('selectProductType called with type:', type);
  
  // Remove selected styling from all product type buttons
  const allBtns = document.querySelectorAll('.product-type-btn');
  allBtns.forEach(btn => {
    btn.style.background = '#e0e0e0';
    btn.style.color = '#333';
    btn.style.border = '2px solid #ccc';
    btn.classList.remove('selected');
  });
  
  // Find the target button by ID first, then by onclick attribute
  let targetButton = null;
  if (type === 'bag') {
    targetButton = document.getElementById('bagProductTypeBtn');
  } else if (type === 'roll') {
    targetButton = document.getElementById('rollProductTypeBtn');
  }
  
  // Fallback: search by onclick attribute
  if (!targetButton) {
    targetButton = document.querySelector('.product-type-btn[onclick*="' + type + '"]');
  }
  
  // Add selected styling to the button for this type (blue)
  if (targetButton) {
    targetButton.style.background = '#2196F3';
    targetButton.style.color = '#fff';
    targetButton.style.border = '2px solid #1976D2';
    targetButton.classList.add('selected');
    console.log('Styled button for type:', type);
  } else {
    console.warn('Could not find button for type:', type);
  }
  
  // If called from event, also style the event target
  if (typeof event !== 'undefined' && event && event.target) {
    event.target.style.background = '#2196F3';
    event.target.style.color = '#fff';
    event.target.style.border = '2px solid #1976D2';
    event.target.classList.add('selected');
  }
  
  // Set hidden input
  const productTypeInput = document.getElementById('product_type');
  if (productTypeInput) {
    productTypeInput.value = type;
    // Force events to trigger watchers
    productTypeInput.dispatchEvent(new Event('input', { bubbles: true }));
    productTypeInput.dispatchEvent(new Event('change', { bubbles: true }));
    console.log('selectProductTypeFull: Set product_type input to:', type, 'verified value:', productTypeInput.value);
  } else {
    console.error('product_type input not found!');
  }
  
  // Show/hide trip number selection and fields
  const tripNumberGroup = document.getElementById('tripNumberGroup');
  const productFieldsContainer = document.getElementById('productFieldsContainer');
  const bagReferenceGroup = document.getElementById('bagReferenceGroup');
  const rollReferenceGroup = document.getElementById('rollReferenceGroup');
  const rollReferenceSearch = document.getElementById('roll_reference_search');
  
  const referenceHiddenInput = document.getElementById('reference_number');
  const bagReferenceSelect = document.getElementById('bag_reference_number');
  
  if (type === 'roll') {
    // Hide Entry Type field if it still exists (legacy)
    const rollEntryTypeGroup = document.getElementById('rollEntryTypeGroup');
    if(rollEntryTypeGroup) rollEntryTypeGroup.style.display = 'none';
    
    // For rolls: show trip number first; reference dropdown + Add will show after user selects a trip
    if(tripNumberGroup) tripNumberGroup.style.display = 'block';
    if(productFieldsContainer) productFieldsContainer.style.display = 'block';
    loadFGTripNumbers();
    
    if (bagReferenceGroup) bagReferenceGroup.style.display = 'none';
    // Show reference section when Roll is selected (it will be disabled until trip is selected)
    if (rollReferenceGroup) {
      rollReferenceGroup.style.setProperty('display', 'block', 'important');
      rollReferenceGroup.style.setProperty('visibility', 'visible', 'important');
      console.log('✅ Roll Reference group shown in selectProductTypeFull');
    }
    if (rollReferenceSearch) {
      rollReferenceSearch.disabled = true;
      rollReferenceSearch.placeholder = 'Select a trip number first...';
    }
    if (referenceHiddenInput) referenceHiddenInput.value = '';
    if (bagReferenceSelect) bagReferenceSelect.value = '';
    currentTripFilter = '';
    fgSelectedReferences = [];
    renderFgSelectedReferences();
    updateReferenceHiddenField();
  
    // If trip is already selected, or only one trip exists - auto-select and trigger auto-fill
    const tripSelectEl = document.getElementById('trip_number');
    if (tripSelectEl) {
      const opts = tripSelectEl.querySelectorAll('option[value]');
      const nonEmptyOpts = Array.from(opts).filter(o => o.value && o.value !== '');
      if (nonEmptyOpts.length === 1 && !tripSelectEl.value) {
        tripSelectEl.value = nonEmptyOpts[0].value;
      }
      if (tripSelectEl.value) {
        if (typeof window.populateRollRefFromTrip === 'function') {
          window.populateRollRefFromTrip();
        }
        if (typeof onFgTripChange === 'function') {
          setTimeout(function() { onFgTripChange(); }, 100);
        }
      }
    }
  
    // Show roll-specific fields
    const rollSizeFormGroup = document.getElementById('rollSizeFormGroup');
    const rollWeightGroup = document.getElementById('rollWeightGroup');
    const rollAreaGroup = document.getElementById('rollAreaGroup');
    // Hide roll size, weight, and area fields (not needed)
    if(rollSizeFormGroup) rollSizeFormGroup.style.display = 'none';
    if(rollWeightGroup) rollWeightGroup.style.display = 'none';
    if(rollAreaGroup) rollAreaGroup.style.display = 'none';
    
    // Show Submit/Clear buttons for Roll (both actionsAfterCnc and formActions)
    const actionsAfterCncRoll = document.getElementById('actionsAfterCnc');
    if (actionsAfterCncRoll) {
      actionsAfterCncRoll.style.setProperty('display', 'block', 'important');
      actionsAfterCncRoll.style.setProperty('visibility', 'visible', 'important');
      console.log('✅ actionsAfterCnc shown for Roll (selectProductTypeFull)');
    }
    
    const formActions = document.getElementById('formActions');
    if (formActions) {
      formActions.style.setProperty('display', 'block', 'important');
      formActions.style.setProperty('visibility', 'visible', 'important');
      console.log('✅ formActions shown for Roll (selectProductTypeFull)');
    }
    
    // Hide bag-specific fields
    const bagCncBatchGroup = document.getElementById('bagCncBatchGroup');
    const cncBatchDisplayGroup = document.getElementById('cncBatchDisplayGroup');
    const bagSizeFormGroup = document.getElementById('bagSizeFormGroup');
    const recommendedWeightGroup = document.getElementById('recommendedWeightGroup');
    const actualWeightBagGroup = document.getElementById('actualWeightBagGroup');
    const thicknessSection = document.getElementById('thicknessSection');
    const qualityCheckedFormGroup = document.getElementById('qualityCheckedFormGroup');
    const passedQtyFormGroup = document.getElementById('passedQtyFormGroup');
    const rejectedQtyFormGroup = document.getElementById('rejectedQtyFormGroup');
    
    const bagSizeInput = document.getElementById('bag_size');
    const qualityCheckedInput = document.getElementById('quality_checked');
    const passedQtyInput = document.getElementById('passed_qty');
    const rejectedQtyInput = document.getElementById('rejected_qty');
    
    if(bagCncBatchGroup) bagCncBatchGroup.style.display = 'none';
    if(cncBatchDisplayGroup) cncBatchDisplayGroup.style.display = 'none';
    if(bagSizeFormGroup) bagSizeFormGroup.style.display = 'none';
    if(recommendedWeightGroup) recommendedWeightGroup.style.display = 'none';
    if(actualWeightBagGroup) actualWeightBagGroup.style.display = 'none';
    if(thicknessSection) thicknessSection.style.display = 'none';
    if(qualityCheckedFormGroup) qualityCheckedFormGroup.style.display = 'none';
    if(passedQtyFormGroup) passedQtyFormGroup.style.display = 'none';
    if(rejectedQtyFormGroup) rejectedQtyFormGroup.style.display = 'none';
    
    // Remove required from bag-specific fields for rolls
    if(bagSizeInput) bagSizeInput.removeAttribute('required');
    if(qualityCheckedInput) qualityCheckedInput.removeAttribute('required');
    if(passedQtyInput) passedQtyInput.removeAttribute('required');
    if(rejectedQtyInput) rejectedQtyInput.removeAttribute('required');
    
    const recommendedWeightInput = document.getElementById('recommended_weight');
    if(recommendedWeightInput) recommendedWeightInput.removeAttribute('required');
    
    // BACKUP: Ensure all Roll fields are visible after a short delay (to handle any race conditions)
    setTimeout(function() {
      console.log('🔄 Backup: Ensuring Roll fields are visible...');
      const rollRefGroupBackup = document.getElementById('rollReferenceGroup');
      const formActionsBackup = document.getElementById('formActions');
      const actionsAfterCncBackup = document.getElementById('actionsAfterCnc');
      const tripGroupBackup = document.getElementById('tripNumberGroup');
      const containerBackup = document.getElementById('productFieldsContainer');
      
      if (containerBackup) containerBackup.style.setProperty('display', 'block', 'important');
      if (tripGroupBackup) tripGroupBackup.style.setProperty('display', 'block', 'important');
      
      if (rollRefGroupBackup) {
        rollRefGroupBackup.style.setProperty('display', 'block', 'important');
        rollRefGroupBackup.style.setProperty('visibility', 'visible', 'important');
        console.log('✅ Backup: rollReferenceGroup visible');
      }
      if (formActionsBackup) {
        formActionsBackup.style.setProperty('display', 'block', 'important');
        formActionsBackup.style.setProperty('visibility', 'visible', 'important');
        console.log('✅ Backup: formActions visible');
      }
      if (actionsAfterCncBackup) {
        actionsAfterCncBackup.style.setProperty('display', 'block', 'important');
        actionsAfterCncBackup.style.setProperty('visibility', 'visible', 'important');
        console.log('✅ Backup: actionsAfterCnc visible');
      }
    }, 300);
  } else if (type === 'bag') {
    // For bags, show all fields immediately
    if(tripNumberGroup) tripNumberGroup.style.display = 'none';
    if(productFieldsContainer) productFieldsContainer.style.display = 'block';
    
    // Hide reference dropdown for bags (only for rolls)
    if (bagReferenceGroup) bagReferenceGroup.style.display = 'none';
    
    // Show CNC batch dropdown for bags (direct selection from branding entries, no auto-fill)
    const bagCncBatchGroup = document.getElementById('bagCncBatchGroup');
    if (bagCncBatchGroup) bagCncBatchGroup.style.display = 'block';
    const actionsAfterCnc = document.getElementById('actionsAfterCnc');
    if (actionsAfterCnc) actionsAfterCnc.style.display = 'block';
    
    // cncBatchDisplayGroup removed - no auto-fill field for bags
    
    const bagSizeFormGroup = document.getElementById('bagSizeFormGroup');
    const recommendedWeightGroup = document.getElementById('recommendedWeightGroup');
    const actualWeightBagGroup = document.getElementById('actualWeightBagGroup');
    const qualityCheckedFormGroup = document.getElementById('qualityCheckedFormGroup');
    const passedQtyFormGroup = document.getElementById('passedQtyFormGroup');
    const rejectedQtyFormGroup = document.getElementById('rejectedQtyFormGroup');
  
    if(bagSizeFormGroup) bagSizeFormGroup.style.display = 'block';
    if(recommendedWeightGroup) recommendedWeightGroup.style.display = 'block';
    if(actualWeightBagGroup) actualWeightBagGroup.style.display = 'block';
    if(qualityCheckedFormGroup) qualityCheckedFormGroup.style.display = 'block';
    if(passedQtyFormGroup) passedQtyFormGroup.style.display = 'block';
    if(rejectedQtyFormGroup) rejectedQtyFormGroup.style.display = 'block';
    // cncBatchDisplayGroup removed - no auto-fill field for bags
    
    // Hide roll-specific fields
    const rollSizeFormGroup = document.getElementById('rollSizeFormGroup');

    if(rollSizeFormGroup) rollSizeFormGroup.style.display = 'none';
    
    // Ensure product_type is definitely set before loading batches
    const productTypeCheck = document.getElementById('product_type');
    if (productTypeCheck && productTypeCheck.value !== 'bag') {
      productTypeCheck.value = 'bag';
      console.log('selectProductTypeFull: Forced product_type to "bag"');
    }
    
    // Load CNC batches immediately (no delay, but ensure product_type is set)
    console.log('selectProductTypeFull: Calling loadBagReferencesFull, product_type is:', productTypeCheck ? productTypeCheck.value : 'not found');
    if (typeof loadBagReferencesFull === 'function') {
      loadBagReferencesFull();
    } else if (window.loadBagReferencesFull) {
      window.loadBagReferencesFull();
    } else if (window.loadBagReferences) {
      window.loadBagReferences();
    } else {
      console.warn('loadBagReferencesFull not available');
    }
  }
  
  updateSummary();
}

// Assign full implementation to window and replace placeholder
window.selectProductTypeFull = selectProductTypeFull;
window.selectProductType = function(type) {
  if (window.selectProductTypeFull) {
    return window.selectProductTypeFull(type);
  }
};

// ✅ DEFINE loadBagReferencesFull EARLY (before any code that calls it)
// Full implementation of loadBagReferences
function loadBagReferencesFull() {
  // Only load and show bag/CNC UI when product type is bag (never when Roll is selected)
  const productTypeInput = document.getElementById('product_type');
  if (!productTypeInput || productTypeInput.value !== 'bag') {
    console.log('loadBagReferencesFull: product_type is not "bag", current value:', productTypeInput ? productTypeInput.value : 'input not found');
    return;
  }
  console.log('loadBagReferencesFull: Loading batches for bag product type');
  // Load CNC cutting batches from branding_entries (batches that were submitted in branding entry)
  const cncBatchSelect = document.getElementById('bag_cnc_cutting_batch');
  const cncBatchHint = document.getElementById('cnc_batch_hint');
  
  if (!cncBatchSelect) {
    return;
  }
  
  // Check if dropdown still shows placeholder - if so, force reload
  const hasOnlyPlaceholder = cncBatchSelect.options.length === 1 && 
                             (cncBatchSelect.options[0].text.includes('Select Product Type First') || 
                              cncBatchSelect.options[0].value === '');
  
  // Don't reload if dropdown already has options (except placeholder) and a selection exists
  // This prevents clearing the user's selection
  if (!hasOnlyPlaceholder && cncBatchSelect.options.length > 1 && cncBatchSelect.value) {
    console.log('loadBagReferencesFull: Already loaded with selection, skipping');
    return; // Already loaded and has a selection
  }
  
  // If still showing placeholder, clear it and load
  if (hasOnlyPlaceholder) {
    console.log('loadBagReferencesFull: Dropdown still shows placeholder, forcing load');
  }
  
  // Attach change event listener only once using global handler
  if (!window.handleCNCBatchChangeGlobal) {
    window.handleCNCBatchChangeGlobal = function(e) {
      // Process immediately - browser has already set the selection
      updateReferenceFromCNCBatch(e);
    };
  }
  
  if (!cncBatchSelect.hasAttribute('data-listener-attached')) {
    cncBatchSelect.addEventListener('change', window.handleCNCBatchChangeGlobal, false);
    cncBatchSelect.setAttribute('data-listener-attached', 'true');
  }
  
  // Verify dropdown container is visible
  const bagCncBatchGroupCheck = document.getElementById('bagCncBatchGroup');
  if (!bagCncBatchGroupCheck || bagCncBatchGroupCheck.style.display === 'none') {
    console.log('loadBagReferencesFull: bagCncBatchGroup not visible, showing it');
    if (bagCncBatchGroupCheck) bagCncBatchGroupCheck.style.display = 'block';
  }
  
  // Show loading state briefly
  if (cncBatchHint) {
    cncBatchHint.textContent = 'Loading batches...';
    cncBatchHint.style.color = '#2196F3';
  }
  
  // Final check before API call - ensure product_type is still 'bag'
  const finalCheck = document.getElementById('product_type');
  if (!finalCheck || finalCheck.value !== 'bag') {
    console.warn('loadBagReferencesFull: Final check failed - product_type is not "bag", aborting API call');
    return;
  }
  
  // Fetch CNC cutting batches from branding_entries table immediately
  // Path is relative to forms/fg_entry.php -> forms/api/get_branding_cnc_batches_for_fg.php
  console.log('loadBagReferencesFull: Fetching batches from API, product_type confirmed as:', finalCheck.value);
  fetch('api/get_branding_cnc_batches_for_fg.php', {
    credentials: 'include'
  })
    .then(response => {
      console.log('📡 API Response status:', response.status, response.statusText);
      if (!response.ok) {
        console.error('❌ API returned error status:', response.status);
        // Silently retry or just show empty
        return { success: true, batches: [] };
      }
      return response.json();
    })
    .then(data => {
      console.log('📦 CNC Batches API response:', data);
      if (data.success && data.batches && data.batches.length > 0) {
        // Preserve current selection if one exists
        const currentValue = cncBatchSelect.value;
        const currentIndex = cncBatchSelect.selectedIndex;
        
        // Clear and populate dropdown efficiently
        cncBatchSelect.innerHTML = '<option value="">-- Select CNC Cutting Batch --</option>';
        
        // Use DocumentFragment for better performance
        const fragment = document.createDocumentFragment();
        let preservedIndex = -1;
        let optionIndex = 1; // Start at 1 because 0 is the placeholder
        
        console.log('✅ API returned batches:', data.batches.length, 'items');
        console.log('📋 First few batches with bag_size:', data.batches.slice(0, 3).map(b => ({
          batch: b.cnc_cutting_batch || b.batch,
          bag_size: b.bag_size || '(empty)',
          print_qty: b.total_print_qty
        })));
        
        data.batches.forEach(batch => {
          const option = document.createElement('option');
          const batchValue = batch.cnc_cutting_batch || batch.batch;
          const bagSizePart = (batch.bag_size || '').trim();
          // Unique value per (batch, bag_size) so selecting one option gives correct data-bag-size
          option.value = bagSizePart ? batchValue + '||' + bagSizePart : batchValue;
          
          // Store total branded qty (used in display and for max Quality Checked)
          var totalBranded = parseInt(batch.total_print_qty, 10) || 0;

          // Display batch number with date, bag size, quantity, and reference if available
          let displayText = batchValue;
          if (batch.batch_date) {
            displayText += ' - ' + batch.batch_date;
          }
          if (batch.bag_size) {
            displayText += ' [' + batch.bag_size + ']';
          }
          displayText += ' — ' + totalBranded + ' pcs';
          if (batch.reference_numbers) {
            const refs = batch.reference_numbers.split(', ').slice(0, 2);
            displayText += ' (' + refs.join(', ') + (batch.reference_numbers.split(', ').length > 2 ? '...' : '') + ')';
          }
          option.textContent = displayText;
          option.setAttribute('data-reference', batch.reference_numbers || '');
          option.setAttribute('data-bag-size', batch.bag_size || '');
          option.setAttribute('data-project-id', (batch.project_id != null && batch.project_id !== '') ? String(batch.project_id) : '');
          option.setAttribute('data-print-qty', totalBranded);
          option.setAttribute('data-total-printed', totalBranded);
          option.setAttribute('data-ncp-piece', batch.total_ncp_piece || 0);
          option.setAttribute('data-batch-date', batch.batch_date || '');
          
          // Check if this was the previously selected value (match by full value or batch only for backwards compat)
          const optionVal = option.value;
          if (currentValue && (optionVal === currentValue || batchValue === currentValue)) {
            preservedIndex = optionIndex;
            option.selected = true;
          }
          
          fragment.appendChild(option);
          optionIndex++;
        });
        cncBatchSelect.appendChild(fragment);
        
        // Restore selection if it was preserved
        if (preservedIndex > 0) {
          cncBatchSelect.selectedIndex = preservedIndex;
          cncBatchSelect.value = currentValue;
          // Trigger the change handler to process the restored selection
          console.log('Triggering updateReferenceFromCNCBatch for restored selection');
          setTimeout(function() {
            if (typeof updateReferenceFromCNCBatch === 'function') {
              updateReferenceFromCNCBatch({ target: cncBatchSelect });
            }
          }, 100);
        }
        
        // Re-attach event listener after innerHTML (which removes listeners)
        // Use the global handler to avoid duplicates
        if (!window.handleCNCBatchChangeGlobal) {
          window.handleCNCBatchChangeGlobal = function(e) {
            console.log('🔔 CNC Batch change event fired!', e.target.value);
            // Let the browser handle the selection naturally - don't interfere
            const select = e.target || document.getElementById('bag_cnc_cutting_batch');
            if (select && select.value) {
              console.log('🔔 Calling updateReferenceFromCNCBatch with value:', select.value);
              // Process immediately - the selection is already set by the browser
              updateReferenceFromCNCBatch(e);
            } else {
              console.log('⚠️ No value selected in CNC batch dropdown');
            }
          };
        }
        cncBatchSelect.removeEventListener('change', window.handleCNCBatchChangeGlobal);
        cncBatchSelect.addEventListener('change', window.handleCNCBatchChangeGlobal, false);
        cncBatchSelect.setAttribute('data-listener-attached', 'true');
        console.log('✅ Change listener attached to CNC batch dropdown');
        
        if (cncBatchHint) {
          cncBatchHint.textContent = 'Select a CNC cutting batch from branding entries';
          cncBatchHint.style.color = '#6c757d';
        }
      } else {
        // If no batches, just show empty option - no error message
        if (cncBatchSelect.options.length <= 1) {
          cncBatchSelect.innerHTML = '<option value="">-- No Batches Available --</option>';
        }
        if (cncBatchHint) {
          cncBatchHint.textContent = 'No CNC cutting batches found in branding entries';
          cncBatchHint.style.color = '#6c757d';
        }
      }
    })
    .catch(err => {
      console.error('❌ Error fetching CNC batches:', err);
      console.error('❌ Error details:', err.message, err.stack);
      // Silently handle errors - don't show error messages, just keep existing state
      if (cncBatchSelect.options.length <= 1) {
        cncBatchSelect.innerHTML = '<option value="">-- Select CNC Cutting Batch --</option>';
      }
      if (cncBatchHint) {
        cncBatchHint.textContent = 'Error loading batches - ' + err.message;
        cncBatchHint.style.color = '#e74c3c';
      }
    });
  
  // Reset all bag size buttons to be visible
  const bagSizeButtons = document.querySelectorAll('#bagSizeButtonGroup .btn');
  bagSizeButtons.forEach(btn => {
    btn.style.display = '';
    btn.classList.remove('selected');
  });
  
  // Reset bag size hint
  const bagSizeHint = document.getElementById('bagSizeHint');
  if (bagSizeHint) {
    bagSizeHint.innerHTML = '<i class="fas fa-info-circle"></i> Select a CNC Cutting Batch first to see available bag sizes from branding';
    bagSizeHint.style.color = '#2196F3';
  }
  
  // Clear total printed quantity label
  const totalPrintedLabel = document.getElementById('totalPrintedQtyLabel');
  if (totalPrintedLabel) {
    totalPrintedLabel.textContent = '';
    totalPrintedLabel.style.display = 'none';
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
  
  if(rollSizeFormGroup) rollSizeFormGroup.style.display = 'none';
  if(rollEntryTypeGroup) rollEntryTypeGroup.style.display = 'none';
  if(bundleInfo) bundleInfo.style.display = 'none';
  
  // Show bag-specific fields only when product type is bag (do not show CNC when user selected Roll)
  const currentProductType = document.getElementById('product_type');
  if (currentProductType && currentProductType.value === 'roll') {
    return;
  }
  const bagCncBatchGroupDisplay = document.getElementById('bagCncBatchGroup');
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
  
  if(bagCncBatchGroupDisplay) bagCncBatchGroupDisplay.style.display = 'block';
  const actionsAfterCncEl = document.getElementById('actionsAfterCnc');
  if (actionsAfterCncEl) actionsAfterCncEl.style.display = 'block';
  // cncBatchDisplayGroup removed - no auto-fill field for bags
  if(bagSizeFormGroup) bagSizeFormGroup.style.display = 'block';
  
  // Load CNC batches directly (no reference number field for bags)
  if (window.loadBagReferences) window.loadBagReferences();
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

// ✅ ASSIGN TO WINDOW IMMEDIATELY
window.loadBagReferencesFull = loadBagReferencesFull;
console.log('✅ loadBagReferencesFull defined and assigned to window early');

function loadBagReferencesFromBranding() {
  // Populate reference dropdown with references from branding entries
  const bagReferenceSelect = document.getElementById('bag_reference_number');
  if (!bagReferenceSelect) return;
  
  // Get references from branding entries (already loaded in PHP)
  const brandingReferences = Object.keys(refToBatchMap);
  
  bagReferenceSelect.innerHTML = '<option value="">-- Select Reference Number from Branding Entries --</option>';
  
  if (brandingReferences.length > 0) {
    brandingReferences.forEach(ref => {
      const option = document.createElement('option');
      option.value = ref;
      
      // Display reference with CNC batch if available
      let displayText = ref;
      if (refToBatchMap[ref]) {
        displayText += ' (CNC: ' + refToBatchMap[ref] + ')';
      }
      option.textContent = displayText;
      
      // Store CNC batch as data attribute
      if (refToBatchMap[ref]) {
        option.setAttribute('data-cnc-batch', refToBatchMap[ref]);
      }
      bagReferenceSelect.appendChild(option);
    });
    
    // Update hint
    const referenceHint = document.getElementById('reference_hint');
    if (referenceHint) {
      referenceHint.textContent = 'Select a reference number from branding entries. CNC cutting batch will be auto-filled.';
      referenceHint.style.color = '#6c757d';
    }
  } else {
    bagReferenceSelect.innerHTML = '<option value="">-- No References from Branding Entries --</option>';
    const referenceHint = document.getElementById('reference_hint');
    if (referenceHint) {
      referenceHint.textContent = 'No references found in branding entries. Please create branding entries first.';
      referenceHint.style.color = '#e74c3c';
    }
  }
}

// ✅ loadBagReferencesFull already defined earlier - no need to redefine

// Assign loadBagReferencesFromBranding to window
if (typeof loadBagReferencesFromBranding === 'function') {
  window.loadBagReferencesFromBrandingFull = loadBagReferencesFromBranding;
}

// Replace placeholder functions with ones that call full implementations
function loadBagReferences() {
  if (window.loadBagReferencesFull) {
    return window.loadBagReferencesFull();
  }
}

function updateReferenceFromCNCBatch(event) {
  const cncBatchSelect = document.getElementById('bag_cnc_cutting_batch');
  const cncBatchInput = document.getElementById('cnc_cutting_batch');

  if (!cncBatchSelect) {
    return false;
  }

  // Get the selected value directly from the select element
  let selectedBatch = cncBatchSelect.value;
  let selectedIndex = cncBatchSelect.selectedIndex;

  // If value is empty but index is set, try to get from index
  if (!selectedBatch && selectedIndex > 0 && selectedIndex < cncBatchSelect.options.length) {
    const option = cncBatchSelect.options[selectedIndex];
    if (option && option.value) {
      selectedBatch = option.value;
    }
  }

  if (!selectedBatch || selectedBatch === '' || selectedIndex <= 0) {
    // Clear hidden field if nothing selected
    if (cncBatchInput) {
      cncBatchInput.value = '';
    }
    // Re-enable bag size selection when no CNC batch is selected
    enableBagSizeSelection();
    return false;
  }

  const selectedOption = cncBatchSelect.options[selectedIndex];
  
  if (!selectedOption) {
    return false;
  }
  
  // CRITICAL: Ensure the selection persists visually
  // Sometimes the browser needs explicit confirmation
  if (selectedIndex > 0 && selectedIndex < cncBatchSelect.options.length) {
    // Explicitly set the selection to ensure it's visible
    cncBatchSelect.selectedIndex = selectedIndex;
    cncBatchSelect.value = selectedBatch;
    // Mark the option as selected
    for (let i = 0; i < cncBatchSelect.options.length; i++) {
      cncBatchSelect.options[i].selected = (i === selectedIndex);
    }
  }
  
  // Set CNC cutting batch in hidden field for form submission (batch only; value may be "batch||bag_size")
  if (cncBatchInput) {
    cncBatchInput.value = selectedBatch.indexOf('||') >= 0 ? selectedBatch.split('||')[0] : selectedBatch;
  }
  
  // Set project_id from branding entry (batch has project_id from API)
  const projectIdFromBatch = selectedOption.getAttribute('data-project-id') || '';
  const projectIdInput = document.getElementById('project_id');
  if (projectIdInput && projectIdFromBatch) {
    projectIdInput.value = projectIdFromBatch;
  }
  
  // Visual feedback
  cncBatchSelect.style.border = '2px solid #2196F3';
  cncBatchSelect.style.backgroundColor = '#f0f8ff';
  
  // Force a reflow to ensure the selection is rendered
  void cncBatchSelect.offsetHeight;
  
  try {
    // Get bag size and update bag size selection
    const bagSize = selectedOption.getAttribute('data-bag-size') || '';
    const bagSizeInput = document.getElementById('bag_size');
    const bagSizeHint = document.getElementById('bagSizeHint');
    
    console.log('updateReferenceFromCNCBatch: Selected batch:', selectedBatch);
    console.log('updateReferenceFromCNCBatch: data-bag-size attribute:', bagSize);
    console.log('updateReferenceFromCNCBatch: All data attributes:', {
      'data-bag-size': selectedOption.getAttribute('data-bag-size'),
      'data-project-id': selectedOption.getAttribute('data-project-id'),
      'data-print-qty': selectedOption.getAttribute('data-print-qty'),
      'data-batch-date': selectedOption.getAttribute('data-batch-date')
    });
    
    if (bagSize && bagSizeInput) {
      bagSizeInput.value = bagSize;
      
      // Update hint: bag size is unchangeable (locked from batch)
      if (bagSizeHint) {
        bagSizeHint.innerHTML = '<i class="fas fa-lock"></i> Bag size from selected batch (unchangeable): ' + bagSize;
        bagSizeHint.style.color = '#4caf50';
      }
      const bagSizeLockedLabel = document.getElementById('bagSizeLockedLabel');
      if (bagSizeLockedLabel) bagSizeLockedLabel.style.display = 'inline';
      
      // DISABLE bag size selection - it comes from the CNC batch and cannot be changed
      disableBagSizeSelection();
      
      // Show recommended weight field (fetched from DB bag_size_master by bag size)
      const recommendedWeightGroup = document.getElementById('recommendedWeightGroup');
      const actualWeightBagGroup = document.getElementById('actualWeightBagGroup');
      const weightInput = document.getElementById('recommended_weight');
      if (recommendedWeightGroup) recommendedWeightGroup.style.display = 'block';
      if (actualWeightBagGroup) actualWeightBagGroup.style.display = 'block';
      
      if (weightInput) {
        weightInput.setAttribute('readonly', 'readonly');
        weightInput.style.backgroundColor = '#f0f0f0';
        var bagSizeTrimmed = (bagSize || '').trim();
        var recommendedWeight = null;
        var weightMap = window.bagSizeToRecommendedWeightFromDB;
        if (weightMap && typeof weightMap === 'object') {
          recommendedWeight = weightMap[bagSizeTrimmed];
          if (recommendedWeight == null || recommendedWeight === '') {
            var bagLower = bagSizeTrimmed.toLowerCase().replace(/\s+/g, '');
            for (var key in weightMap) {
              if (weightMap.hasOwnProperty(key) && key.toLowerCase().replace(/\s+/g, '') === bagLower) {
                recommendedWeight = weightMap[key];
                break;
              }
            }
          }
        }
        if (recommendedWeight != null && recommendedWeight !== '') {
          weightInput.value = recommendedWeight;
        } else {
          weightInput.value = '';
          weightInput.placeholder = 'Fetching from database...';
          var apiUrl = 'api/fetch_bag_weight.php?bag_size=' + encodeURIComponent(bagSizeTrimmed);
          try { apiUrl = new URL(apiUrl, window.location.href).href; } catch (e) {}
          fetch(apiUrl, { credentials: 'include' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
              if (weightInput && data && data.weight != null && data.weight !== '') {
                weightInput.value = data.weight;
              }
              weightInput.placeholder = 'Fetched from database';
            })
            .catch(function() {
              weightInput.placeholder = 'Fetched from database';
            });
        }
      }
      
      // Auto-select the matching bag size button (case-insensitive and handle spacing)
      let bagSizeSelected = false;
      document.querySelectorAll('#bagSizeButtonGroup .btn').forEach(btn => {
        const btnText = btn.textContent.trim();
        // Normalize both values for comparison (case-insensitive, handle spacing)
        const normalizedBtnText = btnText.toLowerCase().replace(/\s+/g, '');
        const normalizedBagSize = bagSize.toLowerCase().replace(/\s+/g, '');
        if (normalizedBtnText === normalizedBagSize || btnText === bagSize) {
          btn.classList.add('selected');
          btn.style.background = '#2196F3';
          btn.style.color = '#fff';
          // Weight already set above from DB by bag size
          bagSizeSelected = true;
        } else {
          btn.classList.remove('selected');
          btn.style.background = '';
          btn.style.color = '';
        }
      });
      
      // If no button matched, try to find a close match
      if (!bagSizeSelected) {
        document.querySelectorAll('#bagSizeButtonGroup .btn').forEach(btn => {
          const btnText = btn.textContent.trim();
          if (btnText.toLowerCase().includes(bagSize.toLowerCase()) || 
              bagSize.toLowerCase().includes(btnText.toLowerCase())) {
            btn.classList.add('selected');
            btn.style.background = '#2196F3';
            btn.style.color = '#fff';
            // Weight already set above from DB by bag size
            bagSizeSelected = true;
          }
        });
      }
      
      if (!bagSizeSelected) {
        if (bagSizeHint) {
          bagSizeHint.innerHTML = '<i class="fas fa-info-circle"></i> Bag size from batch: ' + bagSize + ' (not in predefined list)';
          bagSizeHint.style.color = '#ff9800';
        }
      }
    } else {
      // Still show recommended weight section when CNC batch selected (no bag size from batch)
      const recommendedWeightGroupNoSize = document.getElementById('recommendedWeightGroup');
      const actualWeightBagGroupNoSize = document.getElementById('actualWeightBagGroup');
      if (recommendedWeightGroupNoSize) recommendedWeightGroupNoSize.style.display = 'block';
      if (actualWeightBagGroupNoSize) actualWeightBagGroupNoSize.style.display = 'block';
      const weightInputNoSize = document.getElementById('recommended_weight');
      if (weightInputNoSize) weightInputNoSize.value = '';
      if (bagSizeHint) {
        bagSizeHint.innerHTML = '<i class="fas fa-info-circle"></i> No bag size specified for this batch - select manually';
        bagSizeHint.style.color = '#6c757d';
      }
    }
    
    // Get total printed quantity and display it next to the field label
    const totalPrinted = parseInt(selectedOption.getAttribute('data-total-printed')) || 0;
    const totalPrintedLabel = document.getElementById('totalPrintedQtyLabel');
    const qualityCheckedInput = document.getElementById('quality_checked');
    
    if (totalPrintedLabel) {
      if (totalPrinted > 0) {
        totalPrintedLabel.textContent = '(Max: ' + totalPrinted + ' pcs from branding)';
        totalPrintedLabel.style.display = 'inline';
      } else {
        totalPrintedLabel.textContent = '';
        totalPrintedLabel.style.display = 'none';
      }
    }
    
    // Set max = total branded bags passed for this CNC batch in branding entry
    if (qualityCheckedInput) {
      if (totalPrinted > 0) {
        qualityCheckedInput.setAttribute('max', totalPrinted);
        // Auto-fill quality checked and passed qty with full remaining for this batch (receive at once)
        qualityCheckedInput.value = totalPrinted;
        const passedQtyInput = document.getElementById('passed_qty');
        if (passedQtyInput) passedQtyInput.value = totalPrinted;
        updateActualWeightFromQualityChecked();
        calculateRejected();
        // Validate current value if it exceeds the limit
        const currentQty = parseInt(qualityCheckedInput.value) || 0;
        if (currentQty > totalPrinted) {
          validateQualityChecked(true);
        } else {
          validateQualityChecked(false);
        }
      } else {
        qualityCheckedInput.removeAttribute('max');
      }
    }
    
    // Update summary (no reference number needed for bags)
    if (typeof updateSummary === 'function') {
      updateSummary();
    }
    
    return true;
  } catch (error) {
    console.error('Error in updateReferenceFromCNCBatch:', error);
    return false;
  }
}

// Assign to window immediately for early access
window.updateReferenceFromCNCBatch = updateReferenceFromCNCBatch;

function updateCNCBatchFromReference() {
  const referenceSelect = document.getElementById('bag_reference_number');
  const cncBatchInput = document.getElementById('cnc_cutting_batch');
  const selectedRef = referenceSelect.value;
  const productType = document.getElementById('product_type').value;
  const selectedOption = referenceSelect.options[referenceSelect.selectedIndex];
  const bagSizeInput = document.getElementById('bag_size');
  const rollSizeInput = document.getElementById('roll_size');
  
  // Handle roll-specific fields
  const bundleInfo = document.getElementById('bundleInfo');
  const bundleRollList = document.getElementById('bundleRollList');
  
  const referenceHiddenInput = document.getElementById('reference_number');
  if (referenceHiddenInput && productType === 'bag') {
    referenceHiddenInput.value = selectedRef;
  }
  
  if (productType === 'roll' && selectedRef) {
    const rollSize = selectedOption.getAttribute('data-roll-size') || '';
    const rollWeight = selectedOption.getAttribute('data-weight') || '0';
    const isBundle = selectedOption.getAttribute('data-is-bundle') === 'true';
    const rollCount = selectedOption.getAttribute('data-roll-count') || '1';
    const rollList = selectedOption.getAttribute('data-roll-list') || '';
    if (rollSizeInput && rollSize && rollSize !== 'N/A') {
      rollSizeInput.value = rollSize;
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
    
  }
  
  // Update CNC batch from branding entry when reference is selected (for bags)
  if (productType === 'bag' && selectedRef) {
    // Get CNC batch from branding entries for this reference
    if (refToBatchMap[selectedRef]) {
      cncBatchInput.value = refToBatchMap[selectedRef];
      // Show the CNC batch display group
      const cncBatchDisplayGroup = document.getElementById('cncBatchDisplayGroup');
      if (cncBatchDisplayGroup) {
        cncBatchDisplayGroup.style.display = 'block';
      }
    } else {
      // No CNC batch found for this reference - clear and hide
      cncBatchInput.value = '';
      const cncBatchDisplayGroup = document.getElementById('cncBatchDisplayGroup');
      if (cncBatchDisplayGroup) {
        cncBatchDisplayGroup.style.display = 'none';
      }
    }
  } else if (productType !== 'bag') {
    // For rolls, clear CNC batch and hide display
    cncBatchInput.value = '';
    const cncBatchDisplayGroup = document.getElementById('cncBatchDisplayGroup');
    if (cncBatchDisplayGroup) {
      cncBatchDisplayGroup.style.display = 'none';
    }
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
      selectBagSize(chosenSize, null, null);
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
        bagSizeHint.innerHTML = '<i class="fas fa-info-circle"></i> Select a CNC Cutting Batch first. Bag sizes will be shown based on the selected batch.';
        bagSizeHint.style.color = '#2196F3';
      }
      
      // Clear total printed quantity label
      const totalPrintedLabel = document.getElementById('totalPrintedQtyLabel');
      if (totalPrintedLabel) {
        totalPrintedLabel.textContent = '';
        totalPrintedLabel.style.display = 'none';
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

  updateSummary();
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

function validateQualityChecked(resetValue = true) {
  const qualityCheckedInput = document.getElementById('quality_checked');
  const qualityChecked = parseInt(qualityCheckedInput.value) || 0;
  const referenceNumber = document.getElementById('reference_number').value;
  const productType = document.getElementById('product_type').value;
  const qualityCheckedHint = document.getElementById('qualityCheckedHint');
  
  // Get total printed quantity from selected CNC batch (for bags)
  let totalPrinted = 0;
  if (productType === 'bag') {
    const cncBatchSelect = document.getElementById('bag_cnc_cutting_batch');
    if (cncBatchSelect && cncBatchSelect.value) {
      const selectedOption = cncBatchSelect.options[cncBatchSelect.selectedIndex];
      if (selectedOption) {
        totalPrinted = parseInt(selectedOption.getAttribute('data-total-printed'), 10) || 0;
        if (totalPrinted <= 0) totalPrinted = parseInt(selectedOption.getAttribute('data-print-qty'), 10) || 0;
        if (totalPrinted <= 0 && selectedOption.textContent) {
          const m = selectedOption.textContent.match(/\s[\u2014\u2013-]\s*(\d+)\s*pcs/i) || selectedOption.textContent.match(/\s(\d+)\s*pcs/i);
          if (m) totalPrinted = parseInt(m[1], 10) || 0;
        }
      }
    }
  }
  
  // Primary validation: Check against total printed quantity (for bags with CNC batch)
  if (productType === 'bag' && totalPrinted > 0) {
    if (qualityChecked > totalPrinted) {
      // Always show toast when over max (so user sees notification)
      var toastFn = window.showToast || (typeof showToast !== 'undefined' ? showToast : null);
      if (toastFn) {
        try { toastFn('Quality Checked cannot be greater than branding passed. Max: ' + totalPrinted + ' pcs for this batch.', 'error'); } catch (e) {}
      }
      // Only reset value if resetValue is true (on blur/change)
      if (resetValue) {
        
        // Reset only quality checked; do not change actual weight (flag blocks sync and async updates)
        window._fgResettingQualityCheckedDueToMax = true;
        if (window._fgResettingQualityCheckedDueToMaxTimer) clearTimeout(window._fgResettingQualityCheckedDueToMaxTimer);
        window._fgResettingQualityCheckedDueToMaxTimer = setTimeout(function() {
          window._fgResettingQualityCheckedDueToMax = false;
          window._fgResettingQualityCheckedDueToMaxTimer = null;
        }, 1500);
        qualityCheckedInput.value = totalPrinted;
        
        // Recalculate rejected after reset
        calculateRejected();
      }
      
      // Show warning hint (always, even during typing)
      if (qualityCheckedHint) {
        qualityCheckedHint.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <strong style="color:#e74c3c;">Quality Checked cannot exceed branded qty (' + totalPrinted + ' pcs)</strong>';
        qualityCheckedHint.style.color = '#e74c3c';
      }
      
      if (resetValue) {
        return;
      }
    } else if (qualityChecked > 0) {
      // Valid entry - show success message
      if (qualityCheckedHint) {
        qualityCheckedHint.innerHTML = '<i class="fas fa-check-circle"></i> Valid - Max: ' + totalPrinted + ' pcs (branded for this batch)';
        qualityCheckedHint.style.color = '#4caf50';
      }
    }
  }
  
  // Secondary validation: Check against branded quantity (if no CNC batch total printed)
  if (productType === 'bag' && referenceNumber && totalPrinted === 0) {
    if (refToBrandedQtyMap[referenceNumber]) {
      // Branding data exists - enforce limit
      const maxBranded = refToBrandedQtyMap[referenceNumber];
      
      if (qualityChecked > maxBranded) {
        var toastFn2 = window.showToast || (typeof showToast !== 'undefined' ? showToast : null);
        if (toastFn2) { try { toastFn2('Quality Checked cannot be greater than branding passed. Max: ' + maxBranded + ' pcs for this reference.', 'error'); } catch (e) {} }
        // Only reset value if resetValue is true (on blur/change)
        if (resetValue) {
          // Reset only quality checked; do not change actual weight (flag blocks sync and async updates)
          window._fgResettingQualityCheckedDueToMax = true;
          if (window._fgResettingQualityCheckedDueToMaxTimer) clearTimeout(window._fgResettingQualityCheckedDueToMaxTimer);
          window._fgResettingQualityCheckedDueToMaxTimer = setTimeout(function() {
            window._fgResettingQualityCheckedDueToMax = false;
            window._fgResettingQualityCheckedDueToMaxTimer = null;
          }, 1500);
          qualityCheckedInput.value = maxBranded;
        }
        
        // Show warning hint (always, even during typing)
        if (qualityCheckedHint) {
          qualityCheckedHint.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <strong style="color:#e74c3c;">Quality Checked cannot exceed ' + maxBranded + ' bags (total branded for this reference)</strong>';
          qualityCheckedHint.style.color = '#e74c3c';
        }
        
        if (resetValue) {
          calculateRejected();
          return;
        }
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
  } else if (productType !== 'bag' || (productType === 'bag' && totalPrinted === 0 && !referenceNumber)) {
    // Default hint for non-bag products or bags without batch/reference
    if (qualityCheckedHint) {
      qualityCheckedHint.innerHTML = '<i class="fas fa-info-circle"></i> Enter the number of bags to be quality checked';
      qualityCheckedHint.style.color = '#6c757d';
    }
  }
  
  // Update actual weight: 1 kg per quality checked pc (after branding print quantity passed)
  updateActualWeightFromQualityChecked();
  // Calculate rejected after validation
  calculateRejected();
}
window.validateQualityChecked = validateQualityChecked;

function updateActualWeightFromQualityChecked() {
  if (window._fgResettingQualityCheckedDueToMax) return;
  const productType = document.getElementById('product_type').value;
  if (productType !== 'bag') return;
  const qualityCheckedInput = document.getElementById('quality_checked');
  const actualWeightInput = document.getElementById('actual_weight_bag');
  if (!qualityCheckedInput || !actualWeightInput) return;
  const qualityChecked = parseInt(qualityCheckedInput.value, 10) || 0;
  // Actual weight = 1 kg per quality checked piece (incremented by 1 per pc)
  actualWeightInput.value = qualityChecked > 0 ? qualityChecked : '';
  actualWeightInput.setAttribute('min', '1');
  actualWeightInput.setAttribute('step', '1');
}
window.updateActualWeightFromQualityChecked = updateActualWeightFromQualityChecked;

function calculateRejected() {
  var qcEl = document.getElementById('quality_checked');
  var passedEl = document.getElementById('passed_qty');
  var rejectedEl = document.getElementById('rejected_qty');
  if (!qcEl || !passedEl || !rejectedEl) return;
  var qualityChecked = parseInt(qcEl.value, 10) || 0;
  var passedQty = parseInt(passedEl.value, 10) || 0;
  var rejectedQty = Math.max(0, qualityChecked - passedQty);
  rejectedEl.value = rejectedQty;
  if (typeof updateActualWeightFromQualityChecked === 'function') updateActualWeightFromQualityChecked();
  if (typeof updateSummary === 'function') updateSummary();
}
window.calculateRejected = calculateRejected;

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
  const rollSizeInput = document.getElementById('fg_roll_size');
  if (rollSizeInput) {
    rollSizeInput.value = size;
  }
  
  // Lock buttons after selection
  lockRollSizeButtons();
  
  if (typeof updateSummary === 'function') {
    updateSummary();
  }
}

// Function to auto-select roll size based on reference number
function autoSelectRollSize(rollSize) {
  if (!rollSize || rollSize === '' || rollSize === 'N/A') {
    console.log('No roll size provided for auto-selection');
    return;
  }
  
  console.log('Auto-selecting roll size:', rollSize);
  const rollSizeButtons = document.querySelectorAll('.roll-size-btn');
  let buttonFound = false;
  
  rollSizeButtons.forEach(btn => {
    const btnText = btn.textContent.trim();
    // Try exact match first
    if (btnText === rollSize) {
      selectRollSizeFG(rollSize, btn);
      buttonFound = true;
      console.log('Roll size auto-selected:', rollSize);
    }
  });
  
  // If exact match not found, try partial match (in case of formatting differences)
  if (!buttonFound) {
    rollSizeButtons.forEach(btn => {
      const btnText = btn.textContent.trim().toUpperCase();
      const rollSizeUpper = rollSize.toUpperCase();
      if (btnText.includes(rollSizeUpper) || rollSizeUpper.includes(btnText)) {
        const sizeValue = btnText; // Use button text as the size value
        selectRollSizeFG(sizeValue, btn);
        buttonFound = true;
        console.log('Roll size auto-selected (partial match):', sizeValue);
      }
    });
  }
  
  if (!buttonFound) {
    console.warn('Roll size not found in buttons:', rollSize);
    // Still set the hidden input value even if button not found
    const rollSizeInput = document.getElementById('fg_roll_size');
    if (rollSizeInput) {
      rollSizeInput.value = rollSize;
    }
    // Lock buttons anyway
    lockRollSizeButtons();
  }
}

// Function to lock/disable roll size buttons after selection
function lockRollSizeButtons() {
  const rollSizeButtons = document.querySelectorAll('.roll-size-btn');
  
  rollSizeButtons.forEach(btn => {
    // Only disable buttons that are not selected
    if (!btn.classList.contains('selected')) {
      btn.disabled = true;
      btn.style.opacity = '0.5';
      btn.style.cursor = 'not-allowed';
      // Prevent clicks
      btn.onclick = function(e) {
        e.preventDefault();
        e.stopPropagation();
        alert('Roll size cannot be changed after selecting a reference number.');
        return false;
      };
    } else {
      // Keep selected button enabled but prevent deselection
      btn.style.cursor = 'default';
      const originalOnclick = btn.onclick;
      btn.onclick = function(e) {
        e.preventDefault();
        e.stopPropagation();
        alert('Roll size cannot be changed after selecting a reference number.');
        return false;
      };
    }
  });
  
  console.log('Roll size buttons locked');
}

// Function to unlock/enable roll size buttons (when no references are selected)
function unlockRollSizeButtons() {
  const rollSizeButtons = document.querySelectorAll('.roll-size-btn');
  
  rollSizeButtons.forEach(btn => {
    btn.disabled = false;
    btn.style.opacity = '1';
    btn.style.cursor = 'pointer';
    // Restore original onclick handler
    const size = btn.textContent.trim();
    btn.onclick = function() {
      selectRollSizeFG(size, btn);
    };
  });
  
  console.log('Roll size buttons unlocked');
}

// Function to disable bag size selection (when CNC batch is selected)
function disableBagSizeSelection() {
  const bagSizeButtons = document.querySelectorAll('#bagSizeButtonGroup .btn');
  const customInput = document.getElementById('bag_size_custom');
  
  bagSizeButtons.forEach(btn => {
    btn.disabled = true;
    btn.style.opacity = '0.5';
    btn.style.cursor = 'not-allowed';
    // Remove onclick to prevent clicks
    btn.onclick = function(e) {
      e.preventDefault();
      e.stopPropagation();
      return false;
    };
  });
  
  if (customInput) {
    customInput.disabled = true;
    customInput.style.opacity = '0.5';
    customInput.style.cursor = 'not-allowed';
  }
}

// Function to enable bag size selection (when no CNC batch is selected)
function enableBagSizeSelection() {
  const bagSizeLockedLabel = document.getElementById('bagSizeLockedLabel');
  if (bagSizeLockedLabel) bagSizeLockedLabel.style.display = 'none';
  const bagSizeHint = document.getElementById('bagSizeHint');
  if (bagSizeHint) {
    bagSizeHint.innerHTML = '<i class="fas fa-info-circle"></i> Select a CNC Cutting Batch first. Bag size will be set from the batch and cannot be changed.';
    bagSizeHint.style.color = '#2196F3';
  }
  const bagSizeButtons = document.querySelectorAll('#bagSizeButtonGroup .btn');
  const customInput = document.getElementById('bag_size_custom');
  
  bagSizeButtons.forEach(btn => {
    btn.disabled = false;
    btn.style.opacity = '1';
    btn.style.cursor = 'pointer';
    // Restore onclick handlers
    const size = btn.textContent.trim();
    if (size === 'Custom (Enter manually)') {
      btn.onclick = function() { selectBagSize('custom', null, this); };
    } else {
      btn.onclick = function() { selectBagSize(size, null, this); };
    }
  });
  
  if (customInput) {
    customInput.disabled = false;
    customInput.style.opacity = '1';
    customInput.style.cursor = 'text';
  }
}

function selectBagSize(size, recommendedWeight, clickedButton) {
  // Check if bag size selection is disabled (CNC batch selected)
  const cncBatchSelect = document.getElementById('bag_cnc_cutting_batch');
  if (cncBatchSelect && cncBatchSelect.value) {
    // Bag size is locked when CNC batch is selected
    return false;
  }
  
  const customInput = document.getElementById('bag_size_custom');
  const hiddenInput = document.getElementById('bag_size');
  const weightInput = document.getElementById('recommended_weight');
  
  // Remove selected class from all buttons in bag size group
  const bagSizeButtons = document.querySelectorAll('#bagSizeButtonGroup .btn');
  bagSizeButtons.forEach(btn => {
    btn.classList.remove('selected');
    btn.style.background = '';
    btn.style.color = '';
  });
  
  // Add selected class to clicked button (if provided)
  if (clickedButton) {
    clickedButton.classList.add('selected');
    clickedButton.style.background = '#2196F3';
    clickedButton.style.color = '#fff';
  } else {
    // Find and highlight the matching button
    bagSizeButtons.forEach(btn => {
      const btnText = btn.textContent.trim();
      if (btnText === size || (size === 'custom' && btnText === 'Custom (Enter manually)')) {
        btn.classList.add('selected');
        btn.style.background = '#2196F3';
        btn.style.color = '#fff';
      }
    });
  }
  
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
      // First try to get from database mapping (use window for cross-script access)
      var dbWeightMap = window.bagSizeToRecommendedWeightFromDB;
      if (dbWeightMap && dbWeightMap.hasOwnProperty(size)) {
        weightInput.value = dbWeightMap[size];
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
    // Show CNC batch dropdown only when product type is bag (never for Roll)
    const productTypeInput = document.getElementById('product_type');
    if (productTypeInput && productTypeInput.value === 'bag') {
      const bagCncBatchGroup = document.getElementById('bagCncBatchGroup');
      if (bagCncBatchGroup) bagCncBatchGroup.style.display = 'block';
    }
    if (bagReferenceGroup) bagReferenceGroup.style.display = 'none';
    if (rollReferenceGroup) rollReferenceGroup.style.display = 'none';
    if (rollReferenceSearch) rollReferenceSearch.disabled = true;
    if (referenceHiddenInput && bagReferenceSelect) referenceHiddenInput.value = bagReferenceSelect.value;

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
    // Validate product type selection
    if(!productType){
      alert("Please select a product type (Roll or Bag).");
      return false;
    }
    
    // Validate trip number if Roll is selected
    if(productType === 'roll'){
      const tripNumber = document.getElementById("trip_number").value;
      if(!tripNumber){
        alert("Please select a Trip Number.");
      return false;
      }
    }
    
    // Validate reference number and CNC batch based on product type
    if(productType === 'bag') {
      // For bags, validate CNC cutting batch
      const cncBatchSelect = document.getElementById('bag_cnc_cutting_batch');
      if(!cncBatchSelect || !cncBatchSelect.value){
        showWarningPopup('Please select a CNC Cutting Batch for bags.');
        return false;
      }
      
      // Reference number is optional for bags - it's auto-filled from CNC batch if available
      // No need to validate reference number for bags
    } else {
      // For rolls, validate reference number
    const referenceNumberField = document.getElementById("reference_number");
    if(!referenceNumberField || !referenceNumberField.value){
        showWarningPopup('Please select a Reference Number. This is required for delivery tracking.');
      return false;
      }
    }
    
    // Validate bag size and quality checked vs branding max for bags
    if(productType === 'bag') {
      const bagSizeField = document.getElementById("bag_size");
      if(!bagSizeField || !bagSizeField.value){
        alert("Please select a bag size.");
        return false;
      }

      const qualityCheckedInput = document.getElementById("quality_checked");
      const qualityChecked = parseInt(qualityCheckedInput.value, 10) || 0;
      let maxBranded = 0;
      const cncBatchSelect = document.getElementById('bag_cnc_cutting_batch');
      if (cncBatchSelect && cncBatchSelect.value) {
        const selOpt = cncBatchSelect.options[cncBatchSelect.selectedIndex];
        if (selOpt) maxBranded = parseInt(selOpt.getAttribute('data-total-printed'), 10) || 0;
      }
      if (maxBranded === 0) {
        const ref = document.getElementById('reference_number').value;
        if (ref && typeof refToBrandedQtyMap !== 'undefined' && refToBrandedQtyMap[ref])
          maxBranded = refToBrandedQtyMap[ref];
      }
      if (maxBranded > 0 && qualityChecked > maxBranded) {
        const warningMsg = 'Quality Checked (<strong>' + qualityChecked + ' pcs</strong>) cannot exceed branded quantity (<strong>' + maxBranded + ' pcs</strong>) from branding entry.<br><br>Please enter a value less than or equal to <strong>' + maxBranded + ' pcs</strong>.';
        showWarningPopup(warningMsg);
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
    }
    
    // Ensure shift is set before submit (so it is always saved in DB)
    const shiftInput = document.getElementById("shift");
    if (shiftInput) {
      const h = new Date().getHours();
      shiftInput.value = (h >= 8 && h <= 19) ? "Day" : "Night";
    }
    // Sync hidden cnc_cutting_batch from dropdown for every batch selected (so it always saves correctly)
    const bagCncSelect = document.getElementById("bag_cnc_cutting_batch");
    const cncHidden = document.getElementById("cnc_cutting_batch");
    const productTypeEl = document.getElementById("product_type");
    if (productTypeEl && productTypeEl.value === "bag" && bagCncSelect && cncHidden) {
      const idx = bagCncSelect.selectedIndex;
      const raw = (idx >= 0 && bagCncSelect.options[idx]) ? (bagCncSelect.options[idx].value || bagCncSelect.value) : bagCncSelect.value;
      if (raw && String(raw).trim() !== "" && String(raw) !== "0") {
        cncHidden.value = String(raw).indexOf("||") >= 0 ? String(raw).split("||")[0].trim() : String(raw).trim();
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
  const tripNumber = document.getElementById("trip_number") ? document.getElementById("trip_number").value : '';
  const referenceNumber = document.getElementById("reference_number").value;
  
  console.log('📊 Updating summary - Product Type:', productType, 'Trip Number:', tripNumber);
  const cncCuttingBatch = document.getElementById("cnc_cutting_batch").value;
  const projectId = document.getElementById("project_id").value;
  const projectName = document.querySelector('#project_id').closest('.form-group').querySelector('.btn.selected') ? 
                       document.querySelector('#project_id').closest('.form-group').querySelector('.btn.selected').textContent : '';
  const bagSize = document.getElementById("bag_size").value;
  const rollSize = document.getElementById("fg_roll_size").value;
  const recommendedWeight = document.getElementById("recommended_weight").value;
  const actualWeightBag = document.getElementById("actual_weight_bag") ? document.getElementById("actual_weight_bag").value : '';
  const qualityChecked = document.getElementById("quality_checked").value;
  const passedQty = document.getElementById("passed_qty").value;
  const rejectedQty = document.getElementById("rejected_qty").value;
  
  // Only show summary if at least some basic info is available
  if (shiftInCharge) {
    let summary = `Shift in Charge: ${shiftInCharge}`;
    
    // Add product type and trip number (show even if not all fields filled)
    if (productType === 'roll') {
      summary += ` | Type: Roll`;
      if (tripNumber) {
        summary += ` | Trip: ${tripNumber}`;
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
    
    // Show weight for bags only
    if (productType === 'bag' && actualWeightBag) {
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
  const tripNumberField = document.getElementById('trip_number');
  if (tripNumberField) tripNumberField.value = '';
  document.getElementById('project_id').value = '';
  document.getElementById('bag_size').value = '';
  
  // Reset bag size hint
  const bagSizeHint = document.getElementById('bagSizeHint');
  if (bagSizeHint) {
    bagSizeHint.innerHTML = '<i class="fas fa-info-circle"></i> Select a CNC Cutting Batch first to see available bag sizes from branding';
    bagSizeHint.style.color = '#2196F3';
  }
  
  // Reset all bag size buttons to visible
  const bagSizeButtons = document.querySelectorAll('#bagSizeButtonGroup .btn');
  bagSizeButtons.forEach(btn => {
    btn.style.display = '';
  });
  
  // Clear total printed quantity label
  const totalPrintedLabel = document.getElementById('totalPrintedQtyLabel');
  if (totalPrintedLabel) {
    totalPrintedLabel.textContent = '';
    totalPrintedLabel.style.display = 'none';
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
  
  // Remove max attribute from quality checked input
  const qualityCheckedInput = document.getElementById('quality_checked');
  if (qualityCheckedInput) {
    qualityCheckedInput.removeAttribute('max');
  }
  
  // Hide product fields container and trip number (Roll flow starts again from product type)
  const productFieldsContainer = document.getElementById('productFieldsContainer');
  if(productFieldsContainer) productFieldsContainer.style.display = 'none';
  const tripNumberGroup = document.getElementById('tripNumberGroup');
  if (tripNumberGroup) tripNumberGroup.style.display = 'none';
  
  // Clear roll references and unlock roll size buttons
  if (typeof fgSelectedReferences !== 'undefined') {
    fgSelectedReferences = [];
  }
  if (typeof renderFgSelectedReferences === 'function') {
    renderFgSelectedReferences();
  }
  if (typeof unlockRollSizeButtons === 'function') {
    unlockRollSizeButtons();
  }
  // Clear roll size selection
  const rollSizeInput = document.getElementById('fg_roll_size');
  if (rollSizeInput) {
    rollSizeInput.value = '';
  }
  // Remove selected class from all roll size buttons
  document.querySelectorAll('.roll-size-btn').forEach(b => {
    b.classList.remove('selected');
  });
  
  // Hide entry type group
  const rollEntryTypeGroup = document.getElementById('rollEntryTypeGroup');
  if(rollEntryTypeGroup) rollEntryTypeGroup.style.display = 'none';
  
  // Reset CNC batch dropdown for bags
  const bagCncBatchSelect = document.getElementById('bag_cnc_cutting_batch');
  if (bagCncBatchSelect) {
    bagCncBatchSelect.value = '';
  }
  
  // Re-enable bag size selection when CNC batch is cleared
  enableBagSizeSelection();
  
    // Hide bag CNC batch group
    const bagCncBatchGroup = document.getElementById('bagCncBatchGroup');
    if (bagCncBatchGroup) bagCncBatchGroup.style.display = 'none';
    const actionsAfterCnc = document.getElementById('actionsAfterCnc');
    if (actionsAfterCnc) actionsAfterCnc.style.display = 'none';
    
    // Re-enable bag size selection when switching away from bag product type
    enableBagSizeSelection();
  
  // Hide custom bag size input
  const customInput = document.getElementById('bag_size_custom');
  if (customInput) {
    customInput.style.display = 'none';
    customInput.value = '';
  }
  
  // Hide bundle info
  const bundleInfo = document.getElementById('bundleInfo');
  if(bundleInfo) bundleInfo.style.display = 'none';
  
  const rollReferenceGroup = document.getElementById('rollReferenceGroup');
  if (rollReferenceGroup) rollReferenceGroup.style.display = 'none';
  const rollReferenceSearch = document.getElementById('roll_reference_search');
  if (rollReferenceSearch) {
    rollReferenceSearch.value = '';
    rollReferenceSearch.disabled = true;
  }
  fgSelectedReferences = [];
  renderFgSelectedReferences();
  updateReferenceHiddenField();
  
  updateTimeAndShift();
}


// Add event listeners for summary updates
document.addEventListener('DOMContentLoaded', function() {
  console.log('DOMContentLoaded: Ensuring all elements are visible');
  
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
  
  // Auto-select product type based on role restrictions
  <?php if ($canAccessRoll && !$canAccessBag): ?>
  // Only Roll access - auto-select Roll
  setTimeout(function() {
    const rollBtn = document.getElementById('rollProductTypeBtn');
    const productTypeInput = document.getElementById('product_type');
    
    if (rollBtn) {
      // Set product type value first
      if (productTypeInput) {
        productTypeInput.value = 'roll';
      }
      
      // Try calling selectProductType function
      if (window.selectProductType && typeof window.selectProductType === 'function') {
        console.log('Calling selectProductType for Roll');
        window.selectProductType('roll');
      } else if (window.selectProductTypeFull && typeof window.selectProductTypeFull === 'function') {
        console.log('Calling selectProductTypeFull for Roll');
        window.selectProductTypeFull('roll');
      } else {
        // Fallback: manually show fields
        console.log('Manually showing Roll fields');
        const productFieldsContainer = document.getElementById('productFieldsContainer');
        const tripNumberGroup = document.getElementById('tripNumberGroup');
        const rollReferenceGroup = document.getElementById('rollReferenceGroup');
        const formActions = document.getElementById('formActions');
        const actionsAfterCnc = document.getElementById('actionsAfterCnc');
        
        if (productFieldsContainer) productFieldsContainer.style.display = 'block';
        if (tripNumberGroup) tripNumberGroup.style.display = 'block';
        // Show reference number field
        if (rollReferenceGroup) {
          rollReferenceGroup.style.setProperty('display', 'block', 'important');
          rollReferenceGroup.style.setProperty('visibility', 'visible', 'important');
          console.log('✅ Roll Reference group shown (fallback)');
          const searchInput = rollReferenceGroup.querySelector('#roll_reference_search');
          if (searchInput) {
            searchInput.disabled = true;
            searchInput.placeholder = 'Select a trip number first...';
          }
        }
        // Hide roll size, weight, and area fields (not needed)
        const rollSizeFormGroup = document.getElementById('rollSizeFormGroup');
        const rollWeightGroup = document.getElementById('rollWeightGroup');
        const rollAreaGroup = document.getElementById('rollAreaGroup');
        if (rollSizeFormGroup) rollSizeFormGroup.style.display = 'none';
        if (rollWeightGroup) rollWeightGroup.style.display = 'none';
        if (rollAreaGroup) rollAreaGroup.style.display = 'none';
        if (formActions) {
          formActions.style.display = 'block';
          formActions.style.visibility = 'visible';
        }
        if (actionsAfterCnc) actionsAfterCnc.style.display = 'block';
        
        // Auto-select first trip if only one exists and trigger reference auto-fill
        const tripSel = document.getElementById('trip_number');
        if (tripSel) {
          const tripOpts = Array.from(tripSel.querySelectorAll('option')).filter(o => o.value && o.value !== '');
          if (tripOpts.length === 1) {
            tripSel.value = tripOpts[0].value;
            if (typeof onFgTripChange === 'function') {
              setTimeout(function() { onFgTripChange(); }, 150);
            }
          }
        }
        
        // Style the button
        rollBtn.style.background = '#2196F3';
        rollBtn.style.color = '#fff';
        rollBtn.style.border = '2px solid #1976D2';
        rollBtn.classList.add('selected');
        
        // Load trip numbers
        if (window.loadFGTripNumbers && typeof window.loadFGTripNumbers === 'function') {
          window.loadFGTripNumbers();
        }
      }
    }
  }, 100);
  <?php elseif (!$canAccessRoll && $canAccessBag): ?>
  // Only Bag access - auto-select Bag
  const bagBtn = document.getElementById('bagProductTypeBtn');
  if (bagBtn) bagBtn.click();
  <?php endif; ?>
  
  const inputs = ['reference_number', 'cnc_cutting_batch', 'recommended_weight', 'actual_weight', 'quality_checked', 'passed_qty', 'rejected_qty'];
  inputs.forEach(function(inputId) {
    const element = document.getElementById(inputId);
    if (element) {
      element.addEventListener('input', updateSummary);
      element.addEventListener('change', updateSummary);
    }
  });
  
  const tripSelect = document.getElementById('trip_number');
  if (tripSelect) {
    tripSelect.addEventListener('change', function() {
      if (typeof window.populateRollRefFromTrip === 'function') window.populateRollRefFromTrip();
      if (typeof onFgTripChange === 'function') onFgTripChange();
    });
    // If a trip is already selected on load, trigger auto-fill
    if (tripSelect.value) {
      if (typeof window.populateRollRefFromTrip === 'function') window.populateRollRefFromTrip();
      if (typeof onFgTripChange === 'function') onFgTripChange();
    }
  }
  
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
  
  // Load form data (projects) when DOM is ready; retry once if called too early
  if (document.getElementById('projectGroup')) {
    loadFormData();
  } else {
    document.addEventListener('DOMContentLoaded', loadFormData);
  }
  
  // Auto-select product type if user can only access one type (after everything is loaded)
  <?php if (!$canAccessRoll && $canAccessBag): ?>
    // User can only access Bag - ensure it's selected
    setTimeout(function() {
      const bagButton = document.getElementById('bagProductTypeBtn');
      if (bagButton && typeof selectProductType === 'function') {
        console.log('Auto-selecting Bag after page load');
        selectProductType('bag');
      } else if (bagButton) {
        console.log('Clicking Bag button directly');
        bagButton.click();
      } else {
        console.error('Bag button not found');
      }
    }, 500);
  <?php elseif ($canAccessRoll && !$canAccessBag): ?>
    // User can only access Roll - ensure it's selected
    setTimeout(function() {
      const rollButton = document.getElementById('rollProductTypeBtn');
      if (rollButton && typeof selectProductType === 'function') {
        selectProductType('roll');
      } else if (rollButton) {
        rollButton.click();
      }
    }, 500);
  <?php endif; ?>
});

// Load form dropdowns asynchronously to avoid blocking page render
function loadFormData() {
  const loadingText = document.getElementById('project_loading');
  const projectGroup = document.getElementById('projectGroup');
  if (!projectGroup) return;
  // Build absolute URL so it works in iframe (same as batches API)
  var projectApiUrl = 'api/get_projects.php';
  try { projectApiUrl = new URL(projectApiUrl, window.location.href).href; } catch (e) {}
  var apiPaths = [projectApiUrl, 'api/get_projects.php', '../forms/api/get_projects.php', 'forms/api/get_projects.php'];
  
  function tryFetchProjects(pathIndex) {
    if (pathIndex >= apiPaths.length) {
      if (loadingText) { loadingText.style.display = 'block'; loadingText.textContent = 'Failed to load projects'; loadingText.style.color = '#c00'; }
      return;
    }
    var url = apiPaths[pathIndex];
    if (url.indexOf('http') !== 0) {
      try { url = new URL(url, window.location.href).href; } catch (e2) {}
    }
    fetch(url, { credentials: 'include' })
      .then(function(response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .then(function(data) {
        if (loadingText) loadingText.style.display = 'none';
        if (!data.success || !data.projects || !Array.isArray(data.projects)) {
          if (loadingText) { loadingText.style.display = 'block'; loadingText.textContent = (data.projects && data.projects.length === 0) ? 'No projects configured' : 'No projects'; }
          return;
        }
        projectGroup.innerHTML = '';
        data.projects.forEach(function(project, index) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'btn' + (index === 0 ? ' selected' : '');
          btn.textContent = project.project_name || project.name || ('Project ' + (project.id || (index + 1)));
          btn.onclick = function() { if (typeof selectProject === 'function') selectProject(this, project.id, project.project_name || project.name); };
          projectGroup.appendChild(btn);
        });
        if (data.projects.length > 0) {
          var pid = document.getElementById('project_id');
          if (pid) pid.value = data.projects[0].id || '';
        }
      })
      .catch(function(err) {
        tryFetchProjects(pathIndex + 1);
      });
  }
  
  tryFetchProjects(0);
}

// Additional window.onload handler to ensure auto-select works
window.addEventListener('load', function() {
  <?php if (!$canAccessRoll && $canAccessBag): ?>
    // Final attempt to auto-select Bag
    setTimeout(function() {
      const bagButton = document.getElementById('bagProductTypeBtn');
      const productTypeInput = document.getElementById('product_type');
      
      if (bagButton && productTypeInput && productTypeInput.value !== 'bag') {
        console.log('Window loaded - auto-selecting Bag');
        if (window.selectProductType && typeof window.selectProductType === 'function') {
          window.selectProductType('bag');
        } else {
          bagButton.click();
        }
      }
    }, 1000);
  <?php elseif ($canAccessRoll && !$canAccessBag): ?>
    setTimeout(function() {
      const rollButton = document.getElementById('rollProductTypeBtn');
      const productTypeInputEl = document.getElementById('product_type');
      
      if (rollButton && productTypeInputEl && productTypeInputEl.value !== 'roll') {
        console.log('Window loaded - auto-selecting Roll');
        if (window.selectProductType && typeof window.selectProductType === 'function') {
          window.selectProductType('roll');
        } else if (window.selectProductTypeFull && typeof window.selectProductTypeFull === 'function') {
          window.selectProductTypeFull('roll');
        } else {
          rollButton.click();
        }
      } else if (productTypeInputEl && productTypeInputEl.value === 'roll') {
        // Roll is already selected, ensure fields are shown
        console.log('Roll already selected on load - showing fields');
        if (window.selectProductTypeFull && typeof window.selectProductTypeFull === 'function') {
          window.selectProductTypeFull('roll');
        } else {
          // Fallback: manually show fields if selectProductTypeFull not available
          const productFieldsContainer = document.getElementById('productFieldsContainer');
          const tripNumberGroup = document.getElementById('tripNumberGroup');
          const rollReferenceGroup = document.getElementById('rollReferenceGroup');
          const formActions = document.getElementById('formActions');
          const actionsAfterCnc = document.getElementById('actionsAfterCnc');
          
          if (productFieldsContainer) productFieldsContainer.style.display = 'block';
          if (tripNumberGroup) tripNumberGroup.style.display = 'block';
          // Show reference number field
          if (rollReferenceGroup) {
            rollReferenceGroup.style.setProperty('display', 'block', 'important');
            rollReferenceGroup.style.setProperty('visibility', 'visible', 'important');
            console.log('✅ Roll Reference group shown (window load fallback)');
            const searchInput = rollReferenceGroup.querySelector('#roll_reference_search');
            if (searchInput) {
              searchInput.disabled = true;
              searchInput.placeholder = 'Select a trip number first...';
            }
          }
          // Hide roll size, weight, and area fields (not needed)
          const rollSizeFormGroup = document.getElementById('rollSizeFormGroup');
          const rollWeightGroup = document.getElementById('rollWeightGroup');
          const rollAreaGroup = document.getElementById('rollAreaGroup');
          if (rollSizeFormGroup) rollSizeFormGroup.style.display = 'none';
          if (rollWeightGroup) rollWeightGroup.style.display = 'none';
          if (rollAreaGroup) rollAreaGroup.style.display = 'none';
          if (rollWeightGroup) rollWeightGroup.style.display = 'block';
          if (rollAreaGroup) rollAreaGroup.style.display = 'block';
          if (formActions) {
            formActions.style.display = 'block';
            formActions.style.visibility = 'visible';
          }
          if (actionsAfterCnc) actionsAfterCnc.style.display = 'block';
          
          if (window.loadFGTripNumbers && typeof window.loadFGTripNumbers === 'function') {
            window.loadFGTripNumbers();
          }
        }
      }
    }, 1000);
  <?php endif; ?>
});
</script>
</body>
</html>

