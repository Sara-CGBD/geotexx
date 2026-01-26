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

// Fetch trip numbers and reference numbers from roll_transfer where to_location = 'FG'
$fgTripNumbers = array();
$fgRollReferences = array(); // Reference numbers that were transferred to FG
$hasRollTransfer = $conn->query("SHOW TABLES LIKE 'roll_transfer'")->num_rows > 0;
if ($hasRollTransfer) {
    $hasToLocation = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'to_location'")->num_rows > 0;
    $hasTrip = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'trip'")->num_rows > 0;
    $hasReferenceNumber = $conn->query("SHOW COLUMNS FROM roll_transfer LIKE 'reference_number'")->num_rows > 0;
    
    if ($hasToLocation && $hasTrip) {
        // Fetch trip numbers - only trips that have references in fg_received_entry
        $hasFgReceived = $conn->query("SHOW TABLES LIKE 'fg_received_entry'")->num_rows > 0;
        $tripFilter = "";
        
        if ($hasFgReceived) {
            $hasRefCol = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'reference_number'")->num_rows > 0;
            $hasProductType = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'product_type'")->num_rows > 0;
            $hasTripCol = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'trip_number'")->num_rows > 0;
            
            if ($hasRefCol && $hasProductType) {
                // Only include trips that have at least one reference in fg_received_entry
                // Use a correlated subquery that checks if any reference from this trip exists in fg_received_entry
                $tripFilter = "AND EXISTS (
                    SELECT 1 
                    FROM roll_transfer rt2
                    INNER JOIN fg_received_entry fre ON (
                        fre.reference_number = rt2.reference_number
                        OR FIND_IN_SET(rt2.reference_number, REPLACE(fre.reference_number, ', ', ',')) > 0
                        OR fre.reference_number LIKE CONCAT(rt2.reference_number, ',%')
                        OR fre.reference_number LIKE CONCAT('%, ', rt2.reference_number, ',%')
                        OR fre.reference_number LIKE CONCAT('%, ', rt2.reference_number)
                    )
                    WHERE rt2.trip = rt.trip
                      AND rt2.to_location = 'FG'
                      AND fre.product_type = 'roll'
                      AND fre.reference_number IS NOT NULL 
                      AND fre.reference_number != ''
                )";
            }
        }
        
        $tripQuery = $conn->query("
            SELECT DISTINCT rt.trip, MAX(rt.date_time) as last_transfer_date
            FROM roll_transfer rt
            WHERE rt.to_location = 'FG' 
              AND rt.trip IS NOT NULL
              {$tripFilter}
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
    
    // Fetch reference numbers that were transferred to FG
    // Only include references that have been submitted in fg_received_entry
    if ($hasToLocation && $hasReferenceNumber) {
        // Check if fg_received_entry table exists
        $hasFgReceived = $conn->query("SHOW TABLES LIKE 'fg_received_entry'")->num_rows > 0;
        $fgReceivedFilter = "";
        
        if ($hasFgReceived) {
            $hasRefCol = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'reference_number'")->num_rows > 0;
            $hasProductType = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'product_type'")->num_rows > 0;
            
            if ($hasRefCol && $hasProductType) {
                // Only include references that exist in fg_received_entry for rolls
                // Check if reference exists (exact match or in comma-separated list)
                $fgReceivedFilter = "AND EXISTS (
                    SELECT 1 
                    FROM fg_received_entry fre
                    WHERE fre.product_type = 'roll'
                      AND fre.reference_number IS NOT NULL 
                      AND fre.reference_number != ''
                      AND (
                        fre.reference_number = rt.reference_number
                        OR FIND_IN_SET(rt.reference_number, REPLACE(TRIM(fre.reference_number), ' ', '')) > 0
                        OR fre.reference_number LIKE CONCAT(rt.reference_number, ',%')
                        OR fre.reference_number LIKE CONCAT('%, ', rt.reference_number, ',%')
                        OR fre.reference_number LIKE CONCAT('%, ', rt.reference_number)
                      )
                )";
            } else {
                // If columns don't exist, don't show any references
                $fgReceivedFilter = "AND 1 = 0";
            }
        } else {
            // If table doesn't exist, don't show any references
            $fgReceivedFilter = "AND 1 = 0";
        }
        
        // Check if fg_deliveries table exists for calculating remaining quantity
        $hasFgDeliveries = $conn->query("SHOW TABLES LIKE 'fg_deliveries'")->num_rows > 0;
        $hasDeliveryQty = false;
        $hasDeliveryRef = false;
        $hasDeliveryIsDeleted = false;
        $deliveryQtyCol = 'delivery_quantity';
        
        if ($hasFgDeliveries) {
            $hasDeliveryQty = $conn->query("SHOW COLUMNS FROM fg_deliveries LIKE 'delivery_quantity'")->num_rows > 0;
            if (!$hasDeliveryQty) {
                $hasDeliveryQty = $conn->query("SHOW COLUMNS FROM fg_deliveries LIKE 'delivery_qty'")->num_rows > 0;
                if ($hasDeliveryQty) {
                    $deliveryQtyCol = 'delivery_qty';
                }
            }
            $hasDeliveryRef = $conn->query("SHOW COLUMNS FROM fg_deliveries LIKE 'reference_number'")->num_rows > 0;
            $hasFgEntryId = $conn->query("SHOW COLUMNS FROM fg_deliveries LIKE 'fg_entry_id'")->num_rows > 0;
            $hasDeliveryIsDeleted = $conn->query("SHOW COLUMNS FROM fg_deliveries LIKE 'is_deleted'")->num_rows > 0;
        }
        
        // Build remaining quantity calculation
        $remainingQtyExpr = "fre.received_quantity";
        if ($hasFgDeliveries && $hasDeliveryQty && $hasFgEntryId) {
            $remainingQtyExpr = "(fre.received_quantity - COALESCE(SUM(fd.{$deliveryQtyCol}), 0))";
        }
        
        // Build the LEFT JOIN for fg_deliveries with conditional is_deleted check
        $deliveriesJoin = "";
        if ($hasFgDeliveries && $hasDeliveryQty && $hasFgEntryId) {
            $deliveriesJoin = "LEFT JOIN fg_deliveries fd ON fre.id = fd.fg_entry_id 
                AND fd.delivery_product_type = 'roll'";
            if ($hasDeliveryIsDeleted) {
                $deliveriesJoin .= " AND (fd.is_deleted = 0 OR fd.is_deleted IS NULL)";
            }
        }
        
        $refQuery = $conn->query("
            SELECT DISTINCT 
                rt.reference_number,
                rt.trip,
                rt.amount_kg,
                rt.date_time,
                MAX(rt.date_time) as last_transfer_date,
                COALESCE(re.total_area, 0) as total_area,
                fre.received_quantity,
                {$remainingQtyExpr} as remaining_quantity
            FROM roll_transfer rt
            INNER JOIN fg_received_entry fre ON (
                fre.product_type = 'roll'
                AND fre.reference_number IS NOT NULL 
                AND fre.reference_number != ''
                AND (
                    fre.reference_number = rt.reference_number
                    OR FIND_IN_SET(rt.reference_number, REPLACE(TRIM(fre.reference_number), ' ', '')) > 0
                    OR fre.reference_number LIKE CONCAT(rt.reference_number, ',%')
                    OR fre.reference_number LIKE CONCAT('%, ', rt.reference_number, ',%')
                    OR fre.reference_number LIKE CONCAT('%, ', rt.reference_number)
                )
            )
            LEFT JOIN roll_entry re ON rt.reference_number = re.reference_number 
                AND (re.is_deleted = 0 OR re.is_deleted IS NULL)
            {$deliveriesJoin}
            WHERE rt.to_location = 'FG' 
              AND rt.reference_number IS NOT NULL
              AND rt.reference_number != ''
              {$fgReceivedFilter}
            GROUP BY rt.reference_number, rt.trip, rt.amount_kg, rt.date_time, re.total_area, fre.received_quantity, fre.id
            HAVING remaining_quantity > 0
            ORDER BY rt.date_time DESC, rt.reference_number ASC
            LIMIT 200
        ");
        if ($refQuery) {
            while ($row = $refQuery->fetch_assoc()) {
                $remainingQty = (float)($row['remaining_quantity'] ?? 0);
                // Only add if there's remaining quantity
                if ($remainingQty > 0) {
                    $fgRollReferences[] = [
                        'reference_number' => $row['reference_number'],
                        'trip' => (int)($row['trip'] ?? 0),
                        'amount_kg' => (float)($row['amount_kg'] ?? 0),
                        'total_area' => (float)($row['total_area'] ?? 0),
                        'date_time' => $row['date_time'],
                        'last_transfer_date' => $row['last_transfer_date'],
                        'remaining_quantity' => $remainingQty
                    ];
                }
            }
        }
    }
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
  
  /* Modern Add Button Styles - Minimal Size */
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
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    position: relative;
    overflow: hidden;
    height: 38px;
    margin-top: 0;
    flex-shrink: 0;
  }
  
  .modern-add-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    transition: left 0.5s;
  }
  
  .modern-add-btn:hover::before {
    left: 100%;
  }
  
  .modern-add-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
  }
  
  .modern-add-btn:active {
    transform: translateY(0);
    box-shadow: 0 1px 6px rgba(102, 126, 234, 0.3);
  }
  
  .modern-add-btn .btn-icon-wrapper {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    transition: all 0.3s ease;
  }
  
  .modern-add-btn:hover .btn-icon-wrapper {
    background: rgba(255, 255, 255, 0.3);
    transform: rotate(90deg);
  }
  
  .modern-add-btn .btn-icon-wrapper i {
    font-size: 10px;
  }
  
  .modern-add-btn .btn-text {
    letter-spacing: 0.3px;
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
  
  /* Modern Popup Notification Styles */
  .qty-limit-popup-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(15, 23, 42, 0.75);
    backdrop-filter: blur(8px);
    z-index: 9999;
    display: none;
    animation: fadeInOverlay 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  }
  
  .qty-limit-popup-overlay.show {
    display: flex;
    align-items: center;
    justify-content: center;
  }
  
  @keyframes fadeInOverlay {
    from { opacity: 0; }
    to { opacity: 1; }
  }
  
  .qty-limit-popup {
    position: relative;
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
    border-radius: 20px;
    padding: 0;
    box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.25), 
                0 0 0 1px rgba(231, 76, 60, 0.1);
    z-index: 10000;
    max-width: 400px;
    width: 90%;
    text-align: center;
    animation: popupSlideIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    display: none;
    overflow: hidden;
  }
  
  .qty-limit-popup.show {
    display: block;
  }
  
  @keyframes popupSlideIn {
    from {
      opacity: 0;
      transform: scale(0.9) translateY(-20px);
    }
    to {
      opacity: 1;
      transform: scale(1) translateY(0);
    }
  }
  
  .qty-limit-popup-header {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    padding: 20px 24px 16px;
    position: relative;
    overflow: hidden;
  }
  
  .qty-limit-popup-icon-wrapper {
    position: relative;
    z-index: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 56px;
    height: 56px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    margin-bottom: 12px;
    backdrop-filter: blur(10px);
  }
  
  .qty-limit-popup-icon {
    font-size: 32px;
    color: #ffffff;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.2));
  }
  
  .qty-limit-popup-title {
    font-size: 18px;
    font-weight: 700;
    color: #ffffff;
    margin: 0;
    position: relative;
    z-index: 1;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
  }
  
  .qty-limit-popup-body {
    padding: 20px 24px 24px;
  }
  
  .qty-limit-popup-message {
    font-size: 14px;
    color: #64748b;
    margin-bottom: 16px;
    line-height: 1.5;
  }
  
  .qty-limit-popup-details {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border: 1px solid #fbbf24;
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 20px;
    font-size: 13px;
    color: #78350f;
  }
  
  .qty-limit-popup-details strong {
    color: #92400e;
    font-weight: 600;
    display: inline-block;
    min-width: 70px;
  }
  
  .qty-limit-popup-details-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 0;
  }
  
  .qty-limit-popup-details-row:last-child {
    padding-bottom: 0;
  }
  
  .qty-limit-popup-details-row:first-child {
    padding-top: 0;
  }
  
  .qty-limit-popup-button {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    border: none;
    padding: 12px 32px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    width: 100%;
  }
  
  .qty-limit-popup-button:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(239, 68, 68, 0.4);
  }
  
  .qty-limit-popup-button:active {
    transform: translateY(0);
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.3);
  }
  
  /* Close button (X) in top right */
  .qty-limit-popup-close {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    border: none;
    color: #ffffff;
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    z-index: 10;
    backdrop-filter: blur(10px);
    line-height: 1;
  }
  
  .qty-limit-popup-close:hover {
    background: rgba(255, 255, 255, 0.3);
    transform: scale(1.1);
  }
  
  .qty-limit-popup-close:active {
    transform: scale(0.95);
  }
</style>
</head>
<body>
<div class="container">
  
  <h1>FG Delivery Entry</h1>

  <?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success" style="white-space: pre-line;">
      <?php echo htmlspecialchars($_GET['success']); ?>
    </div>
  <?php endif; ?>
  
  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error">
      <?php echo htmlspecialchars($_GET['error']); ?>
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
        <button type="button" class="btn delivery-product-type-btn" data-type="roll" onclick="selectDeliveryProductType('roll', this)" style="background:#e0e0e0;color:#333; border:2px solid #ccc;">
          <i class="fas fa-scroll"></i> Roll
        </button>
        <button type="button" class="btn delivery-product-type-btn" data-type="bag" onclick="selectDeliveryProductType('bag', this)" style="background:#e0e0e0;color:#333; border:2px solid #ccc;">
          <i class="fas fa-shopping-bag"></i> Bag
        </button>
      </div>
      <input type="hidden" id="delivery_product_type" name="delivery_product_type" value="" required>
      <input type="hidden" id="delivery_unit" name="delivery_unit" value="piece">
    </div>

    <!-- Trip Number - Shown only when Roll is selected -->
    <div class="form-group" id="deliveryTripNumberGroup" style="display:none;">
      <label>Trip Number: <span style="color:red;">*</span></label>
      <select id="delivery_trip_number" name="delivery_trip_number" onchange="onTripNumberChange()">
        <option value="">-- Select Trip Number --</option>
      </select>
      <small style="color:#6c757d; display:block; margin-top:8px;">
        Select a trip number from roll transfers submitted as FG
      </small>
    </div>

    <!-- Delivery Quantity Unit Selection (Only for Rolls, after Trip Number) -->
    <div class="form-group" id="deliveryUnitGroup" style="display:none;">
      <label>Delivery Quantity Unit: <span style="color:red;">*</span></label>
      <div class="btn-group" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:10px;">
        <button type="button" class="btn delivery-unit-btn" data-unit="kg" onclick="selectDeliveryUnit('kg', this)" style="background:#e0e0e0;color:#333; border:2px solid #ccc;">
          <i class="fas fa-weight"></i> KG
        </button>
        <button type="button" class="btn delivery-unit-btn" data-unit="sqm" onclick="selectDeliveryUnit('sqm', this)" style="background:#e0e0e0;color:#333; border:2px solid #ccc;">
          <i class="fas fa-ruler-combined"></i> SQM
        </button>
      </div>
      <input type="hidden" id="delivery_quantity_unit" name="delivery_quantity_unit" value="">
      <small style="color:#6c757d; display:block; margin-top:8px;">
        Select the unit for delivery quantity. References will be shown based on your selection.
      </small>
    </div>

    <!-- All fields below this will be hidden until product type is selected -->
    <div id="deliveryFieldsContainer" style="display:none;">

    <!-- Product -->
    <!-- Reference Number (Only for Rolls) -->
    <div class="form-group" id="referenceNumberGroup">
      <label>Reference Number: <span style="color:red;">*</span></label>
      <div style="display:flex; gap:15px; align-items:flex-start; margin-bottom:10px;">
        <div style="flex:1; position:relative;">
          <input type="text" id="delivery_reference_search" placeholder="Search or type reference number..." 
                 style="padding:10px; border:1px solid #ccc; border-radius:6px; width:100%;"
                 onkeyup="filterDeliveryReferences()" onfocus="showDeliveryReferenceDropdown()">
          <div id="delivery_reference_dropdown" style="display:none; max-height:200px; overflow-y:auto; border:1px solid #ccc; border-radius:6px; background:#fff; position:absolute; z-index:1000; width:100%; top:100%; box-shadow:0 4px 6px rgba(0,0,0,0.1); margin-top:2px;">
            <div id="delivery_no_references_message" style="display:none; padding:15px; text-align:center; color:#999; font-style:italic;">
              No references available. Please ensure references have been transferred to FG.
            </div>
          </div>
        </div>
        <div style="display:flex; align-items:center; padding-top:0;">
          <button type="button" onclick="addDeliveryReference()" class="modern-add-btn">
            <span class="btn-icon-wrapper">
              <i class="fas fa-plus"></i>
            </span>
            <span class="btn-text">Add</span>
          </button>
        </div>
      </div>
      <div id="delivery_selected_reference" style="margin-top:10px; min-height:30px;">
        <!-- Selected reference will appear here -->
      </div>
      <input type="hidden" id="reference_number" name="reference_number" value="" required>
      <input type="hidden" id="reference_quantities" name="reference_quantities" value="">
      <small id="delivery_reference_hint" style="color:#6c757d; display:block; margin-top:5px;"></small>
    </div>

    <!-- CNC Cutting Batch (Dropdown for Bags) -->
    <div class="form-group" id="cncBatchGroup" style="display:none;">
      <label>CNC Cutting Batch: <span style="color:red;">*</span></label>
      <select id="cnc_cutting_batch" name="cnc_cutting_batch" onchange="updateFromCNCBatch()">
        <option value="">-- Select CNC Cutting Batch --</option>
      </select>
      <small id="cnc_batch_hint" style="color:#6c757d; display:block; margin-top:5px;"></small>
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
      <label id="delivery_qty_label">Delivery Quantity:</label>
      <input type="number" id="delivery_qty" name="delivery_qty" required min="1" step="1" placeholder="" readonly style="background:#ecf0f1;">
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
        <button type="button" id="manualChallanBtn" class="btn" onclick="enableManualChallan(this)" style="background: linear-gradient(135deg, #718A95 0%, #5A6F7A 100%); color: white; padding: 10px 20px; white-space: nowrap; border-color: #5A6F7A; box-shadow: 0 2px 6px rgba(114, 132, 143, 0.3);">
          ✏️ Manual Entry
        </button>
      </div>
      <input type="text" id="challan_no_manual" placeholder="Enter lighthouse challan number manually" style="margin-top: 10px; display: none;">
    </div>

    <!-- Unit Price (auto-filled from BOM or manual for custom bags) -->
    <div class="form-group">
      <label>Unit Price (৳ per pcs):</label>
      <input type="number" id="unit_price" name="unit_price" step="1" min="0" required>
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

<!-- Quantity Limit Exceeded Popup -->
<div id="qtyLimitPopupOverlay" class="qty-limit-popup-overlay" onclick="closeQtyLimitPopup()">
  <div id="qtyLimitPopup" class="qty-limit-popup" onclick="event.stopPropagation()">
    <button class="qty-limit-popup-close" onclick="closeQtyLimitPopup()" title="Close">×</button>
    <div class="qty-limit-popup-header">
      <div class="qty-limit-popup-icon-wrapper">
        <div class="qty-limit-popup-icon">⚠️</div>
      </div>
      <div class="qty-limit-popup-title">Quantity Limit Exceeded</div>
    </div>
    <div class="qty-limit-popup-body">
      <div class="qty-limit-popup-message" id="qtyLimitPopupMessage">
        The delivery quantity exceeds the available stock.
      </div>
      <div class="qty-limit-popup-details" id="qtyLimitPopupDetails"></div>
      <button class="qty-limit-popup-button" onclick="closeQtyLimitPopup()">
        <span>Got It</span>
      </button>
    </div>
  </div>
</div>

<script>
// FG Entries data from PHP
const fgEntriesData = <?php echo json_encode($fgEntries); ?>;
const queryError = <?php echo json_encode($queryError); ?>;

// Clients data from PHP
const clientsData = <?php echo json_encode($clients); ?>;
const fgTripNumbers = <?php echo json_encode($fgTripNumbers); ?>;
const fgRollReferences = <?php echo json_encode($fgRollReferences); ?>;
// Debug: Log clients count
console.log('Clients loaded:', clientsData.length, clientsData);

// Minimal debug logging
// Data loaded from PHP

function selectDeliveryProductType(type, buttonElement = null) {
  // Remove selected styling from all product type buttons
  const allBtns = document.querySelectorAll('.delivery-product-type-btn');
  allBtns.forEach(btn => {
    btn.style.background = '#e0e0e0';
    btn.style.color = '#333';
    btn.style.border = '2px solid #ccc';
    btn.classList.remove('selected');
  });
  
  // Add selected styling to clicked button (blue)
  const clickedButton = buttonElement || document.querySelector(`.delivery-product-type-btn[data-type="${type}"]`);
  if (clickedButton) {
    clickedButton.style.background = '#2196F3';
    clickedButton.style.color = '#fff';
    clickedButton.style.border = '2px solid #1976D2';
    clickedButton.classList.add('selected');
  }
  
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
  const referenceNumberGroup = document.getElementById('referenceNumberGroup');
  const cncBatchField = document.getElementById('cnc_cutting_batch');
  
  // Show/hide trip number and fields container
  const tripNumberGroup = document.getElementById('deliveryTripNumberGroup');
  const deliveryFieldsContainer = document.getElementById('deliveryFieldsContainer');
  const deliveryUnitGroup = document.getElementById('deliveryUnitGroup');
  
  if (type === 'roll') {
    // For rolls, show trip number dropdown, unit selector, and all fields immediately
    if(tripNumberGroup) tripNumberGroup.style.display = 'block';
    if(deliveryUnitGroup) deliveryUnitGroup.style.display = 'block';
    if(deliveryFieldsContainer) deliveryFieldsContainer.style.display = 'block';
    
    // Set trip number as required for rolls
    const tripNumberField = document.getElementById('delivery_trip_number');
    if (tripNumberField) {
      tripNumberField.required = true;
    }
    
    // Load trip numbers
    loadFGTripNumbers();
    
    // For rolls, show reference number, hide CNC batch dropdown
    if (availableQtyLabel) availableQtyLabel.textContent = 'Available Quantity:';
    if (deliveryQtyLabel) deliveryQtyLabel.textContent = 'Delivery Quantity:';
    if (referenceNumberGroup) referenceNumberGroup.style.display = 'block';
    if (cncBatchGroup) cncBatchGroup.style.display = 'none';
    if (cncBatchField) cncBatchField.value = '';
    if (cncBatchField) cncBatchField.required = false;
    
    // Set default unit to KG
    selectDeliveryUnit('kg');
    
    // Load roll references (no entry type filtering)
    loadRollDeliveryReferences();
  } else if (type === 'bag') {
    // For bags, hide trip number and unit selector, show all fields immediately
    if(tripNumberGroup) tripNumberGroup.style.display = 'none';
    if(deliveryUnitGroup) deliveryUnitGroup.style.display = 'none';
    if(deliveryFieldsContainer) deliveryFieldsContainer.style.display = 'block';
    
    // Remove required attribute from trip number for bags (field is hidden)
    const tripNumberField = document.getElementById('delivery_trip_number');
    if (tripNumberField) {
      tripNumberField.required = false;
      tripNumberField.value = ''; // Clear value when hidden
    }
    
    // For bags, use pcs, hide reference number, show CNC batch dropdown
    if (availableQtyLabel) availableQtyLabel.textContent = 'Available Quantity (pcs):';
    if (deliveryQtyLabel) deliveryQtyLabel.textContent = 'Delivery Quantity (pcs):';
    if (referenceNumberGroup) referenceNumberGroup.style.display = 'none';
    if (cncBatchGroup) cncBatchGroup.style.display = 'block';
    if (cncBatchField) cncBatchField.required = true;
    // Load CNC batches from branding entries
    loadBrandingCNCBatches();
  }
  
  updateSummary();
}

// Function called when trip number is selected
function onTripNumberChange() {
  const tripNumber = document.getElementById('delivery_trip_number').value;
  const deliveryUnitGroup = document.getElementById('deliveryUnitGroup');
  const referenceNumberGroup = document.getElementById('referenceNumberGroup');
  
  if (tripNumber && tripNumber.trim() !== '') {
    // Show unit selector after trip is selected
    if (deliveryUnitGroup) deliveryUnitGroup.style.display = 'block';
    // Hide reference group until unit is selected
    if (referenceNumberGroup) referenceNumberGroup.style.display = 'none';
    
    // Clear previous selections
    const unitField = document.getElementById('delivery_quantity_unit');
    if (unitField) unitField.value = '';
    window.deliverySelectedReferences = [];
    renderSelectedReferences();
    updateAvailableQuantity();
    updateTotalDeliveryQuantity();
    
    // Reset unit buttons
    const allUnitBtns = document.querySelectorAll('.delivery-unit-btn');
    allUnitBtns.forEach(btn => {
      btn.style.background = '#e0e0e0';
      btn.style.color = '#333';
      btn.style.border = '2px solid #ccc';
      btn.classList.remove('selected');
    });
  } else {
    // Hide unit selector and reference group if trip is cleared
    if (deliveryUnitGroup) deliveryUnitGroup.style.display = 'none';
    if (referenceNumberGroup) referenceNumberGroup.style.display = 'none';
    
    // Clear selections
    const unitField = document.getElementById('delivery_quantity_unit');
    if (unitField) unitField.value = '';
    window.deliverySelectedReferences = [];
    renderSelectedReferences();
    updateAvailableQuantity();
    updateTotalDeliveryQuantity();
  }
}

// Removed selectDeliveryRollEntryType function - Entry Type field has been removed

// Function to load FG Trip Numbers into dropdown
function loadFGTripNumbers() {
  const tripSelect = document.getElementById('delivery_trip_number');
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

function loadRollDeliveryReferences() {
  const dropdown = document.getElementById('delivery_reference_dropdown');
  const referenceHint = document.getElementById('delivery_reference_hint');
  if (!dropdown) return;
  
  // Get selected trip number and unit
  const tripNumber = document.getElementById('delivery_trip_number').value;
  const unitField = document.getElementById('delivery_quantity_unit');
  const selectedUnit = unitField ? unitField.value : '';
  
  // Clear existing options
  dropdown.innerHTML = '';
  
  // If trip number or unit is not selected, don't show references
  if (!tripNumber || tripNumber.trim() === '' || !selectedUnit) {
    if (referenceHint) {
      referenceHint.textContent = 'Please select a trip number and delivery quantity unit first.';
    }
    return;
  }
  
  // Use reference numbers from roll_transfer where to_location = 'FG' and trip matches
  if (fgRollReferences && fgRollReferences.length > 0) {
    // Get unique reference numbers for the selected trip
    const uniqueRefs = {};
    fgRollReferences.forEach(ref => {
      // Filter by selected trip number
      if (ref.trip && ref.trip.toString() !== tripNumber.toString()) {
        return; // Skip references not in selected trip
      }
      
      const refNum = ref.reference_number;
      if (!uniqueRefs[refNum]) {
        uniqueRefs[refNum] = {
          reference_number: refNum,
          trips: [],
          total_amount: 0,
          total_area: ref.total_area || 0,
          last_transfer_date: ref.last_transfer_date || ref.date_time
        };
      }
      if (ref.trip && !uniqueRefs[refNum].trips.includes(ref.trip)) {
        uniqueRefs[refNum].trips.push(ref.trip);
      }
      uniqueRefs[refNum].total_amount += ref.amount_kg;
      // Use the maximum total_area if multiple entries exist
      if (ref.total_area && ref.total_area > (uniqueRefs[refNum].total_area || 0)) {
        uniqueRefs[refNum].total_area = ref.total_area;
      }
    });
    
    // Convert to array and sort by last transfer date
    const refArray = Object.values(uniqueRefs).sort((a, b) => {
      return new Date(b.last_transfer_date) - new Date(a.last_transfer_date);
    });
    
    // Fetch real-time available quantities for all references
    const refPromises = refArray.map(ref => {
      return fetch(`api/get_fg_received_available_qty.php?reference=${encodeURIComponent(ref.reference_number)}`)
        .then(response => response.json())
        .then(data => {
          // Get available quantity based on selected unit
          let availableQty = 0;
          if (selectedUnit === 'kg') {
            availableQty = data.success ? (parseFloat(data.remaining_quantity) || 0) : ref.total_amount;
          } else if (selectedUnit === 'sqm') {
            // For sqm, use remaining_sqm if available, otherwise use area_sqm
            availableQty = data.success ? (parseFloat(data.remaining_sqm) || parseFloat(data.area_sqm) || 0) : (ref.total_area || 0);
          } else {
            availableQty = data.success ? (parseFloat(data.remaining_quantity) || 0) : ref.total_amount;
          }
          
          return {
            ref: ref,
            availableQty: availableQty,
            remainingSqm: data.success ? (parseFloat(data.remaining_sqm) || parseFloat(data.area_sqm) || 0) : (ref.total_area || 0)
          };
        })
        .catch(error => {
          console.error('Error fetching available quantity for ' + ref.reference_number + ':', error);
          return {
            ref: ref,
            availableQty: selectedUnit === 'sqm' ? (ref.total_area || 0) : ref.total_amount,
            remainingSqm: ref.total_area || 0
          };
        });
    });
    
    // Wait for all API calls to complete, then render options
    Promise.all(refPromises).then(results => {
      // Clear dropdown first
      dropdown.innerHTML = '';
      
      // Filter out references based on selected unit
      // For kg: check availableQty > 0
      // For sqm: check total_area > 0
      const validResults = results.filter(result => {
        const availableQty = parseFloat(result.availableQty) || 0;
        const remainingSqm = parseFloat(result.remainingSqm) || 0;
        
        if (selectedUnit === 'kg') {
          // For kg unit, check if available quantity > 0
          return availableQty > 0;
        } else if (selectedUnit === 'sqm') {
          // For sqm unit, check if remaining sqm > 0
          return remainingSqm > 0;
        } else {
          // Default: check if either is available
          return availableQty > 0 || remainingSqm > 0;
        }
      });
      
      validResults.forEach(result => {
        const ref = result.ref;
        const availableQty = parseFloat(result.availableQty) || 0;
        const remainingSqm = parseFloat(result.remainingSqm) || 0;
        
        // Skip based on selected unit
        if (selectedUnit === 'kg' && availableQty <= 0) {
          return;
        } else if (selectedUnit === 'sqm' && remainingSqm <= 0) {
          return;
        } else if (!selectedUnit && availableQty <= 0 && remainingSqm <= 0) {
          return;
        }
        
        const option = document.createElement('div');
        option.className = 'delivery-reference-option';
        const tripText = ref.trips.length > 1 ? 'Trips: ' + ref.trips.sort((a,b) => a-b).join(', ') : 'Trip: ' + ref.trips[0];
        
        // Show value based on selected unit - use real-time available quantity
        let valueText = '';
        if (selectedUnit === 'kg') {
          valueText = 'Available: ' + Math.round(availableQty) + ' kg';
        } else if (selectedUnit === 'sqm') {
          const areaValue = remainingSqm > 0 ? remainingSqm.toFixed(2) : '0.00';
          valueText = 'Available: ' + areaValue + ' sqm';
        } else {
          // Default: show both
          valueText = 'Available: ' + Math.round(availableQty) + ' kg';
          if (remainingSqm > 0) {
            valueText += ', Area: ' + remainingSqm.toFixed(2) + ' sqm';
          }
        }
        
        option.innerHTML = '<strong>' + escapeHtml(ref.reference_number) + '</strong><br><small style="color:#27ae60; font-weight:600;">' + escapeHtml(tripText) + ', ' + valueText + '</small>';
        
        // Set data attributes - use real-time available quantity
        option.setAttribute('data-ref', ref.reference_number);
        option.setAttribute('data-trips', ref.trips.join(','));
        option.setAttribute('data-total-amount', ref.total_amount.toFixed(2));
        option.setAttribute('data-total-area', (ref.total_area || 0).toFixed(2));
        option.setAttribute('data-remaining-qty', Math.round(availableQty).toString());
        option.setAttribute('style', 'display:block; padding:10px; cursor:pointer; border-bottom:1px solid #eee;');
        option.setAttribute('onmouseover', "this.style.background='#f0f0f0'");
        option.setAttribute('onmouseout', "this.style.background='#fff'");
        option.setAttribute('onclick', "event.preventDefault(); event.stopPropagation(); selectDeliveryReferenceFromDropdown('" + ref.reference_number.replace(/'/g, "\\'") + "'); return false;");
        
        dropdown.appendChild(option);
      });
      
      if (referenceHint) {
        referenceHint.textContent = validResults.length > 0 
          ? 'Showing ' + validResults.length + ' reference(s) with available stock. Type to search, click to select, then click Add.' 
          : 'No references with available stock found for the selected trip and unit.';
      }
      
      if (referenceHint) {
        referenceHint.textContent = 'Showing ' + results.length + ' reference(s) transferred to FG. Type to search, click to select, then click Add.';
      }
    });
    
    if (referenceHint) {
      referenceHint.textContent = 'Showing ' + refArray.length + ' reference(s) transferred to FG. Type to search, click to select, then click Add.';
    }
  } else {
    // Fallback: try to find matching entries in fgEntriesData
  const rollEntries = fgEntriesData.filter(entry => {
    const isRoll = entry.product_type === 'roll';
      return isRoll;
  });
  
  rollEntries.forEach(entry => {
      const option = document.createElement('div');
      option.className = 'delivery-reference-option';
      
      option.innerHTML = '<strong>' + escapeHtml(entry.reference_number) + '</strong><br><small style="color:#27ae60; font-weight:600;">Remaining: ' + entry.remaining_quantity + ' kg</small>';
    
    // Set all data attributes
      option.setAttribute('data-ref', entry.reference_number);
      option.setAttribute('data-fg-id', String(entry.id || ''));
      option.setAttribute('data-cnc-batch', String(entry.cnc_cutting_batch || ''));
      option.setAttribute('data-bag-size', String(entry.bag_size || ''));
      option.setAttribute('data-project', String(entry.project_name || ''));
      option.setAttribute('data-passed-qty', String(entry.passed_qty || '0'));
      option.setAttribute('data-actual-weight', String(entry.actual_weight || '0'));
      option.setAttribute('data-delivered-qty', String(entry.delivered_quantity || '0'));
      option.setAttribute('data-remaining-qty', String(entry.remaining_quantity || '0'));
      option.setAttribute('style', 'display:none; padding:10px; cursor:pointer; border-bottom:1px solid #eee;');
      option.setAttribute('onmouseover', "this.style.background='#f0f0f0'");
      option.setAttribute('onmouseout', "this.style.background='#fff'");
      option.setAttribute('onclick', "event.preventDefault(); event.stopPropagation(); selectDeliveryReferenceFromDropdown('" + entry.reference_number.replace(/'/g, "\\'") + "'); return false;");
      
      dropdown.appendChild(option);
    });
    
    if (referenceHint) {
      referenceHint.textContent = rollEntries.length > 0 ? 'Showing roll entries from FG entry. Type to search, click to select, then click Add.' : 'No roll references found';
    }
  }
  
  // Hide dropdown initially
  dropdown.style.display = 'none';
}

// Helper function to escape HTML
function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// Function to show reference dropdown
function showDeliveryReferenceDropdown() {
  const dropdown = document.getElementById('delivery_reference_dropdown');
  if (dropdown) {
    filterDeliveryReferences();
    dropdown.style.display = 'block';
  }
}

// Function to filter references based on search term
function filterDeliveryReferences() {
  const searchTerm = document.getElementById('delivery_reference_search').value.toLowerCase();
  const options = document.querySelectorAll('.delivery-reference-option');
  const noRefsMsg = document.getElementById('delivery_no_references_message');
  
  let visibleCount = 0;
  
  options.forEach(option => {
    const refText = (option.getAttribute('data-ref') || '').toLowerCase();
    
    if (!searchTerm || refText.includes(searchTerm)) {
      option.style.display = 'block';
      visibleCount++;
    } else {
      option.style.display = 'none';
    }
  });
  
  if (noRefsMsg) {
    noRefsMsg.style.display = visibleCount === 0 ? 'block' : 'none';
  }
  
  const dropdown = document.getElementById('delivery_reference_dropdown');
  if (dropdown) {
    dropdown.style.display = visibleCount === 0 && !searchTerm ? 'none' : 'block';
  }
}

// Function to select reference from dropdown (fills search box)
function selectDeliveryReferenceFromDropdown(refNumber) {
  const searchInput = document.getElementById('delivery_reference_search');
  if (searchInput) {
    searchInput.value = refNumber;
    // Hide dropdown after selection
    const dropdown = document.getElementById('delivery_reference_dropdown');
    if (dropdown) {
      dropdown.style.display = 'none';
    }
  }
}

// Store selected references array
if (typeof window.deliverySelectedReferences === 'undefined') {
  window.deliverySelectedReferences = [];
}

// Function to add reference
function addDeliveryReference() {
  const searchInput = document.getElementById('delivery_reference_search');
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
  const options = document.querySelectorAll('.delivery-reference-option');
  let matchedOption = null;
  
  // First try exact match (case-insensitive)
  for (let i = 0; i < options.length; i++) {
    const option = options[i];
    const refText = option.getAttribute('data-ref') || '';
    
    if (refText.toLowerCase() === refValue.toLowerCase()) {
      matchedOption = option;
      break;
    }
  }
  
  // If no exact match, try partial match
  if (!matchedOption) {
    for (let i = 0; i < options.length; i++) {
      const option = options[i];
      const refText = option.getAttribute('data-ref') || '';
      
      // Check if search value is contained in reference or vice versa
      if (refText.toLowerCase().includes(refValue.toLowerCase()) || refValue.toLowerCase().includes(refText.toLowerCase())) {
        matchedOption = option;
        break;
      }
    }
  }
  
  if (matchedOption) {
    const refText = matchedOption.getAttribute('data-ref');
    
    // Check if reference already exists
    if (window.deliverySelectedReferences.some(ref => ref.reference === refText)) {
      alert('This reference has already been added.');
      searchInput.value = '';
      return;
    }
    
    const trips = matchedOption.getAttribute('data-trips') || '';
    const totalAmount = parseFloat(matchedOption.getAttribute('data-total-amount')) || 0;
    const totalArea = parseFloat(matchedOption.getAttribute('data-total-area')) || 0;
    
    // Get selected unit to determine default values
    const unitField = document.getElementById('delivery_quantity_unit');
    const selectedUnit = unitField ? unitField.value : 'kg';
    
    // Fetch real-time available quantity from fg_received_entry API
    fetch(`api/get_fg_received_available_qty.php?reference=${encodeURIComponent(refText)}`)
      .then(response => response.json())
      .then(data => {
        let availableAmount = totalAmount;
        let remainingQty = totalAmount;
        let areaToUse = totalArea;
        
        if (data.success && data.remaining_quantity !== undefined) {
          // Use real-time remaining quantity from fg_received_entry
          remainingQty = parseFloat(data.remaining_quantity) || 0;
          availableAmount = remainingQty > 0 ? remainingQty : totalAmount;
          const remainingSqmFromApi = parseFloat(data.remaining_sqm ?? data.area_sqm ?? 0) || 0;
          if (remainingSqmFromApi > 0) {
            areaToUse = remainingSqmFromApi;
          }
        } else {
          // Fallback to static data if API fails
          const staticRemainingQty = parseFloat(matchedOption.getAttribute('data-remaining-qty')) || totalAmount;
          remainingQty = staticRemainingQty;
          availableAmount = remainingQty > 0 ? remainingQty : totalAmount;
        }
        
        // Round to whole numbers
        const availableAmountInt = Math.round(availableAmount);
        const availableAreaInt = Math.round(areaToUse);
        const defaultAmountInt = availableAmountInt > 0 ? availableAmountInt : 1;
        const defaultAreaInt = availableAreaInt > 0 ? availableAreaInt : 0;
        
        // Add to selected references array
        const refId = 'ref_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        const newRef = {
          id: refId,
          reference: refText,
          trips: trips,
          availableAmount: availableAmountInt,
          availableArea: availableAreaInt,
          deliveryAmount: selectedUnit === 'kg' ? defaultAmountInt : 0,
          deliveryArea: selectedUnit === 'sqm' ? defaultAreaInt : 0,
          fgId: matchedOption.getAttribute('data-fg-id') || ''
        };
        
        window.deliverySelectedReferences.push(newRef);
        
        // Render all selected references
        renderSelectedReferences();
        
        // Update available quantity immediately when reference is added
        updateAvailableQuantity();
        
        // Clear search box
        searchInput.value = '';
        const dropdown = document.getElementById('delivery_reference_dropdown');
        if (dropdown) {
          dropdown.style.display = 'none';
        }
        
        // Update summary
        updateSummary();
      })
      .catch(error => {
        console.error('Error fetching available quantity:', error);
        // Fallback to static data if API fails
        const remainingQty = parseFloat(matchedOption.getAttribute('data-remaining-qty')) || totalAmount;
        const availableAmount = remainingQty > 0 ? remainingQty : totalAmount;
        const availableAmountInt = Math.round(availableAmount);
        const availableAreaInt = Math.round(totalArea);
        const defaultAmountInt = availableAmountInt > 0 ? availableAmountInt : 1;
        const defaultAreaInt = availableAreaInt > 0 ? availableAreaInt : 0;
        
        const refId = 'ref_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
        const newRef = {
          id: refId,
          reference: refText,
          trips: trips,
          availableAmount: availableAmountInt,
          availableArea: availableAreaInt,
          deliveryAmount: selectedUnit === 'kg' ? defaultAmountInt : 0,
          deliveryArea: selectedUnit === 'sqm' ? defaultAreaInt : 0,
          fgId: matchedOption.getAttribute('data-fg-id') || ''
        };
        
        window.deliverySelectedReferences.push(newRef);
        renderSelectedReferences();
        updateAvailableQuantity();
        searchInput.value = '';
        const dropdown = document.getElementById('delivery_reference_dropdown');
        if (dropdown) dropdown.style.display = 'none';
        updateSummary();
      });
  } else {
    alert('Please select a valid reference from the dropdown first. Type to search, click on a reference to select it, then click Add.');
  }
}

// Function to render all selected references
function renderSelectedReferences() {
  const selectedDiv = document.getElementById('delivery_selected_reference');
  const hiddenInput = document.getElementById('reference_number');
  
  if (!selectedDiv) return;
  
  if (window.deliverySelectedReferences.length === 0) {
    selectedDiv.innerHTML = '';
    if (hiddenInput) hiddenInput.value = '';
    return;
  }
  
  let html = '';
  const refNumbers = [];
  
  const unitField = document.getElementById('delivery_quantity_unit');
  const selectedUnit = unitField ? unitField.value : 'kg';

  window.deliverySelectedReferences.forEach((ref, index) => {
    const tripText = ref.trips ? (ref.trips.includes(',') ? 'Trips: ' + ref.trips : 'Trip: ' + ref.trips) : '';
    const isSQM = selectedUnit === 'sqm';
    const availableAmountInt = Math.round(isSQM ? (ref.availableArea || 0) : ref.availableAmount);
    const deliveryValue = Math.round(isSQM ? (ref.deliveryArea || availableAmountInt) : (ref.deliveryAmount || availableAmountInt));
    const labelText = isSQM ? 'Delivery Amount (sqm):' : 'Delivery Amount (kg):';
    const availableUnitLabel = isSQM ? 'sqm' : 'kg';

    html += `
      <div class="delivery-ref-row" style="padding:12px; background:#e8f5e9; border:2px solid #4caf50; border-radius:6px; margin-bottom:10px;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
          <div>
            <strong style="color:#2e7d32; font-size:16px;">${escapeHtml(ref.reference)}</strong>
            ${tripText ? '<br><small style="color:#666;">' + escapeHtml(tripText) + '</small>' : ''}
          </div>
          <button type="button" onclick="removeDeliveryReferenceById('${ref.id}')" style="padding:6px 12px; background:#e74c3c; color:#fff; border:none; border-radius:4px; cursor:pointer; font-weight:600;">
            <i class="fas fa-times"></i> Remove
          </button>
        </div>
        <div style="display:flex; align-items:center; gap:10px;">
          <label style="font-weight:600; color:#333; white-space:nowrap;" id="delivery_amount_label_${ref.id}">${labelText}</label>
          <input type="number" 
                 id="delivery_ref_amount_${ref.id}" 
                 step="1" 
                 min="1" 
                 max="${availableAmountInt || 1}" 
                 value="${deliveryValue}"
                 data-available-amount="${Math.round(ref.availableAmount)}"
                 data-available-area="${Math.round(ref.availableArea || 0)}"
                 data-reference="${escapeHtml(ref.reference)}"
                 data-ref-id="${ref.id}"
                 onchange="validateDeliveryReferenceAmountById('${ref.id}')" 
                 onblur="validateDeliveryReferenceAmountById('${ref.id}')"
                 style="padding:8px; border:2px solid #4caf50; border-radius:6px; width:150px; font-size:14px; font-weight:600;"
                 required>
          <small style="color:#666; white-space:nowrap;" id="delivery_available_info_${ref.id}">Available: <strong style="color:#27ae60;">${availableAmountInt}</strong> ${availableUnitLabel}</small>
        </div>
      </div>
    `;
    
    refNumbers.push(ref.reference);
  });
  
  selectedDiv.innerHTML = html;
  
  // Update hidden input with all reference numbers (comma-separated)
  if (hiddenInput) {
    hiddenInput.value = refNumbers.join(', ');
  }
  
  // Update available quantity when references are rendered
  updateAvailableQuantity();
  updateTotalDeliveryQuantity();
}

// Function to update available quantity (sum of all selected references' available amounts)
function updateAvailableQuantity() {
  const unitField = document.getElementById('delivery_quantity_unit');
  const selectedUnit = unitField ? unitField.value : 'kg';
  
  let totalAvailable = 0;
  
  // Calculate sum based on selected unit
  if (selectedUnit === 'kg') {
    window.deliverySelectedReferences.forEach(ref => {
      totalAvailable += ref.availableAmount;
    });
  } else if (selectedUnit === 'sqm') {
    window.deliverySelectedReferences.forEach(ref => {
      totalAvailable += (ref.availableArea || 0);
    });
  }
  
  // Update available quantity field with sum of all available amounts
  const availableQtyField = document.getElementById('available_qty');
  if (availableQtyField) {
    if (window.deliverySelectedReferences.length > 0) {
      availableQtyField.value = totalAvailable;
    } else {
      availableQtyField.value = '';
    }
  }
  
  // Update FG entry ID (use first reference if exists)
  if (window.deliverySelectedReferences.length > 0) {
    const firstRef = window.deliverySelectedReferences[0];
    if (firstRef.fgId) {
      const fgIdField = document.getElementById('fg_entry_id');
      if (fgIdField) fgIdField.value = firstRef.fgId;
    }
  }
}

// Function to select delivery unit (KG or SQM)
function selectDeliveryUnit(unit, buttonElement = null) {
  // Remove selected styling from all unit buttons
  const allBtns = document.querySelectorAll('.delivery-unit-btn');
  allBtns.forEach(btn => {
    btn.style.background = '#e0e0e0';
    btn.style.color = '#333';
    btn.style.border = '2px solid #ccc';
    btn.classList.remove('selected');
  });
  
  // Add selected styling to clicked button
  const targetBtn = buttonElement || document.querySelector(`.delivery-unit-btn[data-unit="${unit}"]`);
  if (targetBtn) {
    targetBtn.style.background = '#2196F3';
    targetBtn.style.color = '#fff';
    targetBtn.style.border = '2px solid #1976D2';
    targetBtn.classList.add('selected');
  }
  
  // Set hidden input
  const unitField = document.getElementById('delivery_quantity_unit');
  if (unitField) unitField.value = unit;
  
  // Show reference number group after unit is selected
  const referenceNumberGroup = document.getElementById('referenceNumberGroup');
  if (referenceNumberGroup) referenceNumberGroup.style.display = 'block';
  
  // Load references based on selected unit
  loadRollDeliveryReferences();
  
  // Update labels
  const availableQtyLabel = document.getElementById('available_qty_label');
  const deliveryQtyLabel = document.getElementById('delivery_qty_label');
  const deliveryQtyField = document.getElementById('delivery_qty');
  
  if (unit === 'kg') {
    if (availableQtyLabel) availableQtyLabel.textContent = 'Available Quantity (kg):';
    if (deliveryQtyLabel) deliveryQtyLabel.textContent = 'Delivery Quantity (kg):';
    if (deliveryQtyField) { deliveryQtyField.step = 1; deliveryQtyField.min = 1; }
  } else if (unit === 'sqm') {
    if (availableQtyLabel) availableQtyLabel.textContent = 'Available Quantity (sqm):';
    if (deliveryQtyLabel) deliveryQtyLabel.textContent = 'Delivery Quantity (sqm):';
    if (deliveryQtyField) { deliveryQtyField.step = 1
      ; deliveryQtyField.min = 0; }
  }
  
  // Clear previous selections when unit changes
  window.deliverySelectedReferences = [];
  renderSelectedReferences();
  
  // Update available quantity
  updateAvailableQuantity();
  
  // Update total delivery quantity
  updateTotalDeliveryQuantity();
  
  // Update summary
  updateSummary();
}

// Function to update reference rows based on selected unit
function updateReferenceRowsForUnit(unit) {
  window.deliverySelectedReferences.forEach(ref => {
    const amountInput = document.getElementById('delivery_ref_amount_' + ref.id);
    const label = document.getElementById('delivery_amount_label_' + ref.id);
    const info = document.getElementById('delivery_available_info_' + ref.id);
    
    if (!amountInput || !label || !info) return;
    
    if (unit === 'kg') {
      label.textContent = 'Delivery Amount (kg):';
      const availableAmount = parseFloat(amountInput.getAttribute('data-available-amount')) || 0;
      const currentValue = parseFloat(amountInput.value) || 0;
      amountInput.max = availableAmount;
      amountInput.min = 1;
      amountInput.step = 1;
      amountInput.value = (typeof ref.deliveryAmount !== 'undefined' && ref.deliveryAmount !== null) ? ref.deliveryAmount : (currentValue || availableAmount);
      info.innerHTML = 'Available: <strong style="color:#27ae60;">' + availableAmount + '</strong> kg';
      ref.deliveryAmount = parseFloat(amountInput.value) || 0;
    } else if (unit === 'sqm') {
      label.textContent = 'Delivery Amount (sqm):';
      const availableArea = parseFloat(amountInput.getAttribute('data-available-area')) || 0;
      const currentValue = parseFloat(amountInput.value) || 0;
      amountInput.max = availableArea;
      amountInput.min = 0.01;
      amountInput.step = 0.01;
      amountInput.value = (typeof ref.deliveryArea !== 'undefined' && ref.deliveryArea !== null && ref.deliveryArea !== 0) ? ref.deliveryArea : (currentValue || availableArea);
      info.innerHTML = 'Available: <strong style="color:#27ae60;">' + availableArea + '</strong> sqm';
      ref.deliveryArea = parseFloat(amountInput.value) || 0;
    }
  });
}

// Function to update total delivery quantity (sum of all delivery amounts)
function updateTotalDeliveryQuantity() {
  // Only update for rolls, not for bags (bags have manual entry)
  const productType = document.getElementById('delivery_product_type')?.value;
  if (productType === 'bag') {
    return; // Don't update delivery quantity for bags - user enters manually
  }
  
  const unitField = document.getElementById('delivery_quantity_unit');
  const selectedUnit = unitField ? unitField.value : 'kg';
  let totalAmount = 0;

  if (!window.deliverySelectedReferences || window.deliverySelectedReferences.length === 0) {
    const deliveryQtyField = document.getElementById('delivery_qty');
    if (deliveryQtyField) {
      deliveryQtyField.value = '';
    }
    return;
  }

  window.deliverySelectedReferences.forEach(ref => {
    const amountInput = document.getElementById('delivery_ref_amount_' + ref.id);
    if (!amountInput) return;

    const amount = parseFloat(amountInput.value) || 0;
    if (selectedUnit === 'kg') {
      ref.deliveryAmount = amount;
    } else if (selectedUnit === 'sqm') {
      ref.deliveryArea = amount;
    }

    totalAmount += amount;
  });

  const deliveryQtyField = document.getElementById('delivery_qty');
  if (deliveryQtyField) {
    deliveryQtyField.value = totalAmount > 0 ? totalAmount : '';
  }
}

// Function to validate delivery reference amount by ID
function validateDeliveryReferenceAmountById(refId) {
  const amountInput = document.getElementById('delivery_ref_amount_' + refId);
  if (!amountInput) return;
  
  const unitField = document.getElementById('delivery_quantity_unit');
  const selectedUnit = unitField ? unitField.value : 'kg';
  
  const enteredAmount = parseFloat(amountInput.value) || 0;
  const maxAmount = selectedUnit === 'kg' 
    ? parseFloat(amountInput.getAttribute('data-available-amount')) || 0
    : parseFloat(amountInput.getAttribute('data-available-area')) || 0;
  const refNumber = amountInput.getAttribute('data-reference') || '';
  
  // Find and update the reference in the array
  const ref = window.deliverySelectedReferences.find(r => r.id === refId);
  if (ref) {
    if (selectedUnit === 'kg') {
      ref.deliveryAmount = enteredAmount;
    } else if (selectedUnit === 'sqm') {
      ref.deliveryArea = enteredAmount;
    }
  }
  
  // Update total delivery quantity
  updateTotalDeliveryQuantity();
  
  // Validate amount
  if (enteredAmount <= 0) {
    amountInput.style.borderColor = '#e74c3c';
    amountInput.value = '1';
    if (ref) {
      if (selectedUnit === 'kg') ref.deliveryAmount = 1;
      else if (selectedUnit === 'sqm') ref.deliveryArea = 1;
    }
    updateTotalDeliveryQuantity();
    updateSummary();
    return;
  }
  
  if (enteredAmount > maxAmount) {
    // Show popup notification
    const unitText = selectedUnit === 'kg' ? 'kg' : 'sqm';
    showDeliveryAmountExceedPopup(refNumber, enteredAmount, maxAmount, unitText);
    
    // Auto-correct to max amount
    amountInput.value = maxAmount;
    amountInput.style.borderColor = '#e74c3c';
    
    if (ref) {
      if (selectedUnit === 'kg') ref.deliveryAmount = maxAmount;
      else if (selectedUnit === 'sqm') ref.deliveryArea = maxAmount;
    }
    updateTotalDeliveryQuantity();
  } else {
    amountInput.style.borderColor = '#4caf50';
  }
  
  updateSummary();
}

// Function to show amount exceed popup
function showDeliveryAmountExceedPopup(refNumber, enteredAmount, availableAmount, unit = 'kg') {
  const popup = document.getElementById('qtyLimitPopup');
  const overlay = document.getElementById('qtyLimitPopupOverlay');
  const message = document.getElementById('qtyLimitPopupMessage');
  const details = document.getElementById('qtyLimitPopupDetails');
  
  if (!popup || !overlay || !message || !details) return;
  
  message.textContent = `The amount you entered for reference "${refNumber}" exceeds the available quantity.`;
  
  details.innerHTML = `
    <div style="background:#fff3cd; border-left:4px solid #ffc107; padding:12px; border-radius:6px; margin-top:12px;">
      <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
        <span style="color:#856404; font-weight:600;">Entered Amount:</span>
        <span style="color:#e74c3c; font-weight:700; font-size:16px;">${enteredAmount} ${unit}</span>
      </div>
      <div style="display:flex; justify-content:space-between; margin-bottom:8px;">
        <span style="color:#856404; font-weight:600;">Available Amount:</span>
        <span style="color:#27ae60; font-weight:700; font-size:16px;">${availableAmount} ${unit}</span>
      </div>
      <div style="margin-top:12px; padding-top:12px; border-top:1px solid #ffc107;">
        <small style="color:#856404; font-style:italic;">The amount has been automatically corrected to ${availableAmount} ${unit}.</small>
      </div>
    </div>
  `;
  
  popup.classList.add('show');
  overlay.classList.add('show');
}

// Function to close amount exceed popup
function closeQtyLimitPopup() {
  const popup = document.getElementById('qtyLimitPopup');
  const overlay = document.getElementById('qtyLimitPopupOverlay');
  
  if (popup) popup.classList.remove('show');
  if (overlay) overlay.classList.remove('show');
  
  // Focus back on amount input
  setTimeout(() => {
    const amountInput = document.getElementById('delivery_ref_amount');
    if (amountInput) {
      amountInput.focus();
      amountInput.select();
    }
  }, 100);
}

// Function to remove selected reference by ID
function removeDeliveryReferenceById(refId) {
  // Remove from array
  window.deliverySelectedReferences = window.deliverySelectedReferences.filter(ref => ref.id !== refId);
  
  // Re-render all references
  renderSelectedReferences();
  
  // Update available quantity when reference is removed
  updateAvailableQuantity();
  
  // Update delivery quantity
  updateTotalDeliveryQuantity();
  
  // Clear related fields if no references left
  if (window.deliverySelectedReferences.length === 0) {
    const fgIdField = document.getElementById('fg_entry_id');
    if (fgIdField) fgIdField.value = '';
  }
  
  // Update summary
  updateSummary();
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
  const dropdown = document.getElementById('delivery_reference_dropdown');
  const searchInput = document.getElementById('delivery_reference_search');
  
  if (dropdown && searchInput && !dropdown.contains(event.target) && event.target !== searchInput) {
    dropdown.style.display = 'none';
  }
});

function loadBrandingCNCBatches() {
  const cncBatchSelect = document.getElementById('cnc_cutting_batch');
  const cncBatchHint = document.getElementById('cnc_batch_hint');
  
  if (!cncBatchSelect) {
    console.error('CNC batch select element not found');
    return;
  }
  
  cncBatchSelect.innerHTML = '<option value="">-- Loading CNC Batches --</option>';
  if (cncBatchHint) {
    cncBatchHint.textContent = 'Loading...';
  }
  
  fetch('api/get_branding_cnc_batches.php')
    .then(response => {
      if (!response.ok) {
        throw new Error('Network response was not ok: ' + response.status);
      }
      return response.json();
    })
    .then(data => {
      if (!data.success) {
        cncBatchSelect.innerHTML = '<option value="">-- Error loading batches --</option>';
        if (cncBatchHint) {
          cncBatchHint.textContent = 'Error: ' + (data.error || 'Unknown error');
          cncBatchHint.style.color = '#e74c3c';
        }
        console.error('API returned error:', data);
        return;
      }
      
      cncBatchSelect.innerHTML = '<option value="">-- Select CNC Cutting Batch --</option>';
      
      if (data.batches && data.batches.length > 0) {
        data.batches.forEach(batch => {
          const option = document.createElement('option');
          // Unique value per (batch, bag_size) so delivery is tied to the correct received lot
          option.value = (batch.bag_size ? batch.batch + '||' + batch.bag_size : batch.batch);
          const label = batch.bag_size ? (batch.batch + ' | ' + batch.bag_size + ' (Remaining: ' + batch.remaining_qty + ' pcs)') : (batch.batch + ' (Remaining: ' + batch.remaining_qty + ' pcs)');
          option.textContent = label;
          option.setAttribute('data-remaining-qty', batch.remaining_qty);
          option.setAttribute('data-total-print-qty', batch.total_print_qty);
          option.setAttribute('data-delivered-qty', batch.delivered_qty);
          if (batch.bag_size) {
            option.setAttribute('data-bag-size', batch.bag_size);
          }
          if (batch.project_id) {
            option.setAttribute('data-project-id', batch.project_id);
          }
          cncBatchSelect.appendChild(option);
        });
        
        if (cncBatchHint) {
          cncBatchHint.textContent = 'Showing ' + data.batches.length + ' CNC cutting batches with available stock';
          cncBatchHint.style.color = '#27ae60';
        }
      } else {
        cncBatchSelect.innerHTML += '<option value="" disabled>No CNC cutting batches with remaining stock</option>';
        if (cncBatchHint) {
          cncBatchHint.textContent = 'No batches available';
          cncBatchHint.style.color = '#e74c3c';
        }
      }
    })
    .catch(error => {
      console.error('Error loading CNC batches:', error);
      cncBatchSelect.innerHTML = '<option value="">-- Error loading batches --</option>';
      cncBatchHint.textContent = 'Error loading batches: ' + error.message + '. Please check console for details.';
      if (cncBatchHint) {
        cncBatchHint.style.color = '#e74c3c';
      }
    });
}

function updateFromCNCBatch() {
  const cncBatchSelect = document.getElementById('cnc_cutting_batch');
  if (!cncBatchSelect) return;
  
  const selectedIndex = cncBatchSelect.selectedIndex;
  if (selectedIndex < 0 || selectedIndex === 0) {
    // Reset fields if no valid option selected
    const deliveryQtyField = document.getElementById('delivery_qty');
    document.getElementById('available_qty').value = '';
    if (deliveryQtyField) {
      deliveryQtyField.value = '';
      deliveryQtyField.removeAttribute('data-max-qty');
      deliveryQtyField.placeholder = ''; // Keep placeholder empty for bags
    }
    document.getElementById('bag_size').value = '';
    updateSummary();
    return;
  }
  
  const selectedOption = cncBatchSelect.options[selectedIndex];
  if (!selectedOption || !selectedOption.value) {
    return;
  }
  
  // Get remaining quantity
  const remainingQty = parseFloat(selectedOption.getAttribute('data-remaining-qty')) || 0;
  const bagSize = selectedOption.getAttribute('data-bag-size') || '';
  
  // Update bag size hidden field
  const bagSizeField = document.getElementById('bag_size');
  if (bagSizeField) bagSizeField.value = bagSize;
  
  // Display available quantity
  const availableQtyField = document.getElementById('available_qty');
  if (availableQtyField) {
    const formattedQty = remainingQty % 1 === 0 ? remainingQty.toString() : remainingQty.toFixed(2);
    availableQtyField.value = formattedQty;
  }
  
  // Update delivery quantity field
  const deliveryQtyField = document.getElementById('delivery_qty');
  const qtyHintElem = document.getElementById('qty_hint');
  
  if (deliveryQtyField && qtyHintElem) {
    deliveryQtyField.readOnly = false;
    deliveryQtyField.removeAttribute('readonly');
    deliveryQtyField.disabled = false;
    deliveryQtyField.value = '';
    deliveryQtyField.style.backgroundColor = 'white';
    deliveryQtyField.style.fontWeight = 'normal';
    
    // Clear placeholder for bags (not needed since user enters manually)
    deliveryQtyField.placeholder = '';
    
    qtyHintElem.innerHTML = `Enter quantity (max: <span id="max_qty">${remainingQty}</span> pcs)`;
    qtyHintElem.style.display = 'block';
    
    deliveryQtyField.setAttribute('data-max-qty', remainingQty);
    
    // Note: Event listeners are already added in DOMContentLoaded section
    // No need to add them here to avoid duplicates
  }
  
  // Auto-fill unit price from BOM based on bag size
  const unitPriceField = document.getElementById('unit_price');
  const priceHint = document.getElementById('price_hint');
  
  if (unitPriceField && priceHint) {
    if (bagSize && bomPrices[bagSize]) {
      unitPriceField.value = bomPrices[bagSize];
      unitPriceField.readOnly = true;
      unitPriceField.style.backgroundColor = '#f0f0f0';
      priceHint.textContent = 'Auto-filled from BOM';
      priceHint.style.color = '#27ae60';
    } else {
      unitPriceField.value = '';
      unitPriceField.readOnly = false;
      unitPriceField.style.backgroundColor = 'white';
      priceHint.textContent = 'Enter price manually (custom bag size)';
      priceHint.style.color = '#e67e22';
    }
  }
  
  updateSummary();
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
let lastPopupQty = null; // Track last quantity that triggered popup to avoid repeated popups

function validateDeliveryQty() {
  const deliveryQtyField = document.getElementById("delivery_qty");
  if (!deliveryQtyField) return;
  
  const maxQty = parseFloat(deliveryQtyField.getAttribute("data-max-qty")) || 0;
  const enteredQty = parseFloat(deliveryQtyField.value) || 0;
  const qtyHint = document.getElementById("qty_hint");
  
  // Get product type to show correct unit
  const productType = document.getElementById('delivery_product_type').value;
  const unit = productType === 'bag' ? 'pcs' : 'kg';
  
  // Only validate if maxQty is set (field is ready)
  if (maxQty > 0) {
    if (enteredQty > maxQty) {
      if (qtyHint) {
        qtyHint.innerHTML = `<span style="color: #e74c3c;">⚠️ Cannot exceed ${maxQty} ${unit}!</span>`;
      }
      deliveryQtyField.style.borderColor = "#e74c3c";
      
      // Show popup notification (only once per quantity value to avoid spam)
      if (lastPopupQty !== enteredQty) {
        showQtyLimitPopup(enteredQty, maxQty, unit);
        lastPopupQty = enteredQty;
      }
    } else {
      // Reset popup tracking when quantity is valid
      if (enteredQty <= maxQty) {
        lastPopupQty = null;
      }
      
      if (qtyHint) {
        if (enteredQty > 0) {
          qtyHint.innerHTML = `Enter quantity (max: <span id="max_qty">${maxQty}</span> ${unit})`;
          deliveryQtyField.style.borderColor = "#27ae60";
        } else {
          qtyHint.innerHTML = `Enter quantity (max: <span id="max_qty">${maxQty}</span> ${unit})`;
          deliveryQtyField.style.borderColor = "#ccc";
        }
      }
    }
  }
  
  if (typeof updateSummary === 'function') {
    updateSummary();
  }
}

function showQtyLimitPopup(enteredQty, maxQty, unit, customMessage = null) {
  const popup = document.getElementById('qtyLimitPopup');
  const overlay = document.getElementById('qtyLimitPopupOverlay');
  const message = document.getElementById('qtyLimitPopupMessage');
  const details = document.getElementById('qtyLimitPopupDetails');
  
  if (!popup || !overlay || !message || !details) return;
  
  // Use custom message if provided, otherwise use default
  if (customMessage) {
    message.textContent = customMessage;
    details.innerHTML = '';
  } else {
    // Shorter, more user-friendly message
    message.textContent = `Delivery quantity (<strong>${enteredQty} ${unit}</strong>) cannot exceed available stock (<strong>${maxQty} ${unit}</strong>).`;
    
    // Simplified details structure
    const excess = (enteredQty - maxQty).toFixed(2);
    details.innerHTML = `
      <div class="qty-limit-popup-details-row">
        <strong>Available:</strong>
        <span style="color: #27ae60; font-weight: 700;">${maxQty} ${unit}</span>
      </div>
      <div class="qty-limit-popup-details-row">
        <strong>Entered:</strong>
        <span style="color: #e74c3c; font-weight: 700;">${enteredQty} ${unit}</span>
      </div>
      <div class="qty-limit-popup-details-row">
        <strong>Excess:</strong>
        <span style="color: #dc2626; font-weight: 700;">${excess} ${unit}</span>
      </div>
      <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
        <small style="color: #64748b; font-style: italic;">Please reduce the delivery quantity to ${maxQty} ${unit} or less.</small>
      </div>
    `;
  }
  
  overlay.classList.add('show');
  // Small delay to ensure overlay is rendered first
  setTimeout(() => {
    popup.classList.add('show');
  }, 10);
}

// closeQtyLimitPopup function is defined above - removed duplicate

// Close popup on ESC key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    const popup = document.getElementById('qtyLimitPopup');
    if (popup && popup.classList.contains('show')) {
      closeQtyLimitPopup();
    }
  }
});

function enableManualChallan(button = null) {
  const autoField = document.getElementById("challan_no");
  const manualField = document.getElementById("challan_no_manual");
  const toggleButton = button || document.getElementById("manualChallanBtn");
  
  if (manualField.style.display === "none") {
    // Enable manual mode
    manualField.style.display = "block";
    manualField.focus();
    autoField.removeAttribute("name"); // Don't submit auto field
    manualField.setAttribute("name", "challan_no"); // Submit manual field instead
    if (toggleButton) {
      toggleButton.textContent = "🔄 Use Auto";
      toggleButton.style.background = "linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%)";
      toggleButton.style.borderColor = "#7f8c8d";
      toggleButton.style.boxShadow = "0 2px 6px rgba(149, 165, 166, 0.3)";
    }
  } else {
    // Switch back to auto mode
    manualField.style.display = "none";
    manualField.value = "";
    manualField.removeAttribute("name");
    autoField.setAttribute("name", "challan_no");
    if (toggleButton) {
      toggleButton.textContent = "✏️ Manual Entry";
      toggleButton.style.background = "linear-gradient(135deg, #718A95 0%, #5A6F7A 100%)";
      toggleButton.style.borderColor = "#5A6F7A";
      toggleButton.style.boxShadow = "0 2px 6px rgba(114, 132, 143, 0.3)";
    }
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
  console.log('validateForm called');
  try {
  // Close any open popups before validation to ensure they don't block submission
  closeQtyLimitPopup();
  
  // Ensure delivery quantity field is editable before validation
  const deliveryQtyFieldCheck = document.getElementById("delivery_qty");
  if (deliveryQtyFieldCheck) {
    deliveryQtyFieldCheck.readOnly = false;
    deliveryQtyFieldCheck.removeAttribute('readonly');
    deliveryQtyFieldCheck.disabled = false;
  }
  
  // Ensure quantities are recalculated before validation
  const productType = document.getElementById("delivery_product_type").value;
  
  // Only update total delivery quantity for rolls (bags have manual entry)
  if (productType === 'roll') {
    if (typeof updateAvailableQuantity === 'function') updateAvailableQuantity();
    if (typeof updateTotalDeliveryQuantity === 'function') updateTotalDeliveryQuantity();
  } else if (productType === 'bag') {
    // For bags, only update available quantity, don't touch delivery quantity (user enters manually)
    if (typeof updateAvailableQuantity === 'function') updateAvailableQuantity();
  }
  
  if (typeof updateSummary === 'function') updateSummary(); // Update summary before validation
    console.log('Product type:', productType);
    
    if (!productType) {
      alert("Please select a product type (Roll or Bag).");
      return false;
    }
  
  // Validate based on product type
  if (productType === 'roll') {
    // Check if any references are selected
    if (!window.deliverySelectedReferences || window.deliverySelectedReferences.length === 0) {
      alert("Please add at least one reference number."); 
      return false;
    }
    
    // Check if reference_number hidden field has value
    const referenceNumberField = document.getElementById("reference_number");
    if (!referenceNumberField || !referenceNumberField.value || referenceNumberField.value.trim() === '') {
      alert("Please add at least one reference number."); 
      return false;
    }
    
    // Validate trip number for rolls
    const tripNumberField = document.getElementById("delivery_trip_number");
    if (tripNumberField && tripNumberField.required && !tripNumberField.value) {
      alert("Please select a trip number.");
      return false;
    }
  } else if (productType === 'bag') {
    if(!document.getElementById("cnc_cutting_batch").value){
      alert("Please select a CNC cutting batch."); 
      return false;
    }
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
  
  // For rolls, validate delivery quantity unit
  if (productType === 'roll') {
    const unitField = document.getElementById('delivery_quantity_unit');
    const selectedUnit = unitField ? unitField.value : '';
    
    if (!selectedUnit || (selectedUnit !== 'kg' && selectedUnit !== 'sqm')) {
      showQtyLimitPopup(0, 0, '', "Please select a delivery quantity unit (KG or SQM).");
      return false;
    }
  }
  
  const deliveryQtyField = document.getElementById("delivery_qty");
  if (!deliveryQtyField) {
    showQtyLimitPopup(0, 0, 'pcs', "Delivery quantity field not found. Please refresh the page.");
    return false;
  }
  
  // Get the raw value (important: read before any other operations)
  const rawValue = deliveryQtyField.value;
  const deliveryQtyValue = rawValue ? String(rawValue).trim() : '';
  const deliveryQty = deliveryQtyValue ? parseFloat(deliveryQtyValue) : NaN;
  const availableQty = parseFloat(document.getElementById("available_qty").value) || 0;
  
  // Determine unit based on product type and selected unit
  let unit = 'pcs';
  if (productType === 'roll') {
    const unitField = document.getElementById('delivery_quantity_unit');
    unit = unitField ? unitField.value : 'kg';
  }
  
  // Debug logging to help diagnose issues
  console.log('Delivery Qty Validation Debug:', {
    rawValue: rawValue,
    trimmedValue: deliveryQtyValue,
    parsedValue: deliveryQty,
    isValidNumber: !isNaN(deliveryQty),
    isGreaterThanZero: deliveryQty > 0,
    productType: productType,
    fieldReadonly: deliveryQtyField.readOnly,
    fieldDisabled: deliveryQtyField.disabled
  });
  
  // Check if delivery quantity is empty or invalid
  // For bags, the user enters manually, so we need to check the actual value
  if (!deliveryQtyValue || deliveryQtyValue === '' || isNaN(deliveryQty) || deliveryQty <= 0) {
    // Focus on the delivery quantity field
    deliveryQtyField.focus();
    deliveryQtyField.style.borderColor = '#e74c3c';
    showQtyLimitPopup(0, 0, unit, "Please enter a delivery quantity.");
    return false;
  }
  
  // Reset border color if valid
  deliveryQtyField.style.borderColor = '';
  
  // For rolls, validate against available quantity (sum of all selected references)
  if (productType === 'roll') {
    if (availableQty <= 0) {
      showQtyLimitPopup(0, 0, unit, "Available quantity is 0. Please check your selected references."); 
      return false;
    }
    
    if(deliveryQty > availableQty){
      showQtyLimitPopup(deliveryQty, availableQty, unit); 
      return false;
    }
    
    // Validate each individual reference amount doesn't exceed its available amount
    const unitField = document.getElementById('delivery_quantity_unit');
    const selectedUnit = unitField ? unitField.value : 'kg';
    
    let hasInvalidAmount = false;
    let invalidRef = '';
    let maxAmount = 0;
    let enteredAmount = 0;
    if (window.deliverySelectedReferences && window.deliverySelectedReferences.length > 0) {
      window.deliverySelectedReferences.forEach(ref => {
        const amountInput = document.getElementById('delivery_ref_amount_' + ref.id);
        if (amountInput) {
          const entered = parseFloat(amountInput.value) || 0;
          const maxAvailable = selectedUnit === 'kg' ? ref.availableAmount : (ref.availableArea || 0);
          
          if (entered > maxAvailable) {
            hasInvalidAmount = true;
            invalidRef = ref.reference;
            maxAmount = maxAvailable;
            enteredAmount = entered;
          }
        }
      });
    }
    
    if (hasInvalidAmount) {
      showDeliveryAmountExceedPopup(invalidRef, enteredAmount, maxAmount, selectedUnit);
      return false;
    }
  } else {
    // For bags, use the old validation with data-max-qty
    const deliveryQtyField = document.getElementById("delivery_qty");
    const maxQty = parseFloat(deliveryQtyField.getAttribute("data-max-qty")) || 0;
  
  if(maxQty === 0){
    showQtyLimitPopup(0, 0, unit, "⚠️ ERROR: Maximum quantity is 0!\n\nThis means:\n• The dropdown option didn't have data-remaining-qty attribute set\n• Try refreshing the page\n• Check the debug panel at the top\n\nDebug Info:\n• Delivery Qty: " + deliveryQty + "\n• Max Qty from attribute: " + deliveryQtyField.getAttribute("data-max-qty")); 
    return false;
  }
  
  if(deliveryQty > maxQty){
    showQtyLimitPopup(deliveryQty, maxQty, unit); 
    return false;
    }
  }
  
  // For rolls, collect individual reference quantities and send as JSON
  if (productType === 'roll' && window.deliverySelectedReferences && window.deliverySelectedReferences.length > 0) {
    const unitField = document.getElementById('delivery_quantity_unit');
    const selectedUnit = unitField ? unitField.value : 'kg';
    
    const referenceQuantities = [];
    window.deliverySelectedReferences.forEach(ref => {
      const amountInput = document.getElementById('delivery_ref_amount_' + ref.id);
      if (amountInput) {
        const deliveryAmount = parseFloat(amountInput.value) || 0;
        referenceQuantities.push({
          reference: ref.reference,
          delivery_quantity: deliveryAmount
        });
      }
    });
    
    // Store as JSON in hidden field
    const referenceQuantitiesField = document.getElementById('reference_quantities');
    if (referenceQuantitiesField) {
      referenceQuantitiesField.value = JSON.stringify(referenceQuantities);
    }
  }
  
  console.log('Validation passed, submitting form');
  return true;
  } catch (error) {
    console.error('Validation error:', error);
    alert('An error occurred during validation: ' + error.message);
    return false;
  }
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
  const productTypeVal = document.getElementById("delivery_product_type").value;
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
  
  // Check if we have required fields based on product type
  const hasRequiredFields = productTypeVal === 'roll' 
    ? (dateTime && shift && productTypeVal && referenceNumber && deliveryQty && hasClient && unitPrice)
    : (dateTime && shift && productTypeVal && cncCuttingBatch && deliveryQty && hasClient && unitPrice);
  
  if (hasRequiredFields) {
    const totalCost = (parseFloat(deliveryQty) * parseFloat(unitPrice)).toFixed(2);
    
    let productTypeText = '';
    if (productTypeVal === 'roll') {
      productTypeText = 'Roll';
    } else if (productTypeVal === 'bag') {
      productTypeText = 'Bag';
    }
    
    // Get unit based on product type
    const unit = productTypeVal === 'bag' ? 'pcs' : 'kg';
    const unitShort = productTypeVal === 'bag' ? 'pc' : 'kg';
    
    let summaryText = `${dateTime} | Shift: ${shift} | Type: ${productTypeText}`;
    if (productTypeVal === 'roll' && referenceNumber) {
      summaryText += ` | Reference: ${referenceNumber}`;
    }
    if (cncCuttingBatch) {
      summaryText += ` | CNC Batch: ${cncCuttingBatch}`;
    }
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
  const maxQtyElem = document.getElementById("max_qty");
  if (maxQtyElem) maxQtyElem.textContent = "0";
  
  // Clear selected references
  window.deliverySelectedReferences = [];
  renderSelectedReferences();
  
  // Clear search input
  const searchInput = document.getElementById('delivery_reference_search');
  if (searchInput) searchInput.value = '';
  
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
  const tripNumberGroup = document.getElementById('deliveryTripNumberGroup');
  const deliveryFieldsContainer = document.getElementById('deliveryFieldsContainer');
  if (tripNumberGroup) tripNumberGroup.style.display = 'none';
  if (deliveryFieldsContainer) deliveryFieldsContainer.style.display = 'none';
  
  // Reset hidden inputs
  document.getElementById('delivery_product_type').value = '';
  document.getElementById('delivery_unit').value = 'piece';
  
  // Reset trip number and remove required attribute
  const tripNumberField = document.getElementById('delivery_trip_number');
  if (tripNumberField) {
    tripNumberField.value = '';
    tripNumberField.required = false; // Remove required when clearing form
  }
  
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
  // If there's a success message, refresh reference data to show updated quantities
  const successAlert = document.querySelector('.alert-success');
  if (successAlert) {
    // Clear any selected references to force fresh data load
    window.deliverySelectedReferences = [];
    renderSelectedReferences();
    
    // If trip and unit are already selected, reload references with fresh data
    const tripNumber = document.getElementById('delivery_trip_number')?.value;
    const unitField = document.getElementById('delivery_quantity_unit')?.value;
    if (tripNumber && unitField) {
      // Small delay to ensure DOM is ready, then reload references
      setTimeout(() => {
        loadRollDeliveryReferences();
      }, 500);
    }
  }
  
  // Hide and remove Entry Type field if it still exists (legacy/cache)
  const rollEntryTypeGroup = document.getElementById('deliveryRollEntryTypeGroup');
  if(rollEntryTypeGroup) {
    rollEntryTypeGroup.style.display = 'none';
    rollEntryTypeGroup.remove(); // Remove it completely from DOM
  }
  
  // Also hide any buttons with delivery-roll-entry-type-btn class
  const entryTypeButtons = document.querySelectorAll('.delivery-roll-entry-type-btn');
  entryTypeButtons.forEach(btn => {
    const parent = btn.closest('.form-group');
    if(parent && parent.id === 'deliveryRollEntryTypeGroup') {
      parent.style.display = 'none';
      parent.remove();
    }
  });
  
  const refSelect = document.getElementById("reference_number");
  if (refSelect) refSelect.addEventListener('change', updateSummary);
  
  const deliveryQty = document.getElementById("delivery_qty");
  if (deliveryQty) {
    deliveryQty.addEventListener('input', updateSummary);
    // Add validation for bags when delivery quantity changes (debounced to avoid blocking)
    let validationTimeout = null;
    deliveryQty.addEventListener('input', function() {
      const productType = document.getElementById('delivery_product_type').value;
      if (productType === 'bag') {
        // Clear previous timeout
        if (validationTimeout) {
          clearTimeout(validationTimeout);
        }
        // Debounce validation to avoid blocking form submission
        validationTimeout = setTimeout(function() {
          try {
            validateDeliveryQty();
          } catch (e) {
            console.error('Error in validateDeliveryQty:', e);
          }
        }, 300);
      }
    });
    deliveryQty.addEventListener('change', function() {
      const productType = document.getElementById('delivery_product_type').value;
      if (productType === 'bag') {
        if (validationTimeout) {
          clearTimeout(validationTimeout);
        }
        try {
          validateDeliveryQty();
        } catch (e) {
          console.error('Error in validateDeliveryQty:', e);
        }
      }
    });
  }
  
  const unitPrice = document.getElementById("unit_price");
  if (unitPrice) unitPrice.addEventListener('input', updateSummary);
  
  // Initial updates
  updateTimeAndShift();
  updateSummary();
  
  // Check for error in URL and show modern popup if it's about delivery quantity exceeding available stock
  const urlParams = new URLSearchParams(window.location.search);
  const error = urlParams.get('error');
  if (error) {
    // Check if error is about delivery quantity exceeding available stock
    if (error.includes('exceeds available stock') || error.includes('exceeds available')) {
      // Extract quantities from error message
      const match = error.match(/Delivery quantity \(([\d.]+)\s*(\w+)\) exceeds available stock \(([\d.]+)\s*(\w+)\)/i);
      if (match) {
        const enteredQty = parseFloat(match[1]);
        const unit = match[2];
        const availableQty = parseFloat(match[3]);
        showQtyLimitPopup(enteredQty, availableQty, unit);
      } else {
        // Fallback: show error message as-is
        showQtyLimitPopup(0, 0, '', error);
      }
      // Remove error from URL
      const newUrl = window.location.pathname;
      window.history.replaceState({}, document.title, newUrl);
    }
  }
});

// Update time and shift every second
setInterval(updateTimeAndShift, 1000);
</script>
</body>
</html>

