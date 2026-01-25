<?php
session_start();
require_once '../config/security_config.php';

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Set timezone to Bangladesh
date_default_timezone_set('Asia/Dhaka');

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Get POST data
$brandingId = trim($_POST['brandingId'] ?? '');
$dateTime = $_POST['dateTime'] ?? '';
$shiftIncharge = trim($_POST['shiftIncharge'] ?? '');
$referenceNumber = trim($_POST['referenceNumber'] ?? '');
$cncCuttingBatch = trim($_POST['cncCuttingBatch'] ?? '');
$projectId = (int)($_POST['project'] ?? 0);
    $printMachine = trim($_POST['printMachine'] ?? '');
    // bagSize will be fetched from cnc_entries, not from POST
    $printQty = (int)($_POST['printQty'] ?? 0);
    $ncpPiece = (int)($_POST['ncpPiece'] ?? 0);
    $reporterId = $_SESSION['user_id'] ?? 0;
    $reporterName = $_SESSION['username'] ?? 'Unknown';

// Validate required fields (referenceNumber and bagSize are now auto-fetched, not from user input)
$required = ['brandingId', 'dateTime', 'shiftIncharge', 'cncCuttingBatch', 'projectId', 'printMachine', 'printQty'];
foreach ($required as $field) {
    if (empty($$field)) {
        header("Location: ../forms/branding_entry.php?error=" . urlencode("Missing required field: $field"));
        exit;
    }
}

try {
    $conn = SecurityConfig::getConnection();
    
    // Fetch bag_size from cnc_entries based on the selected CNC cutting batch
    // Extract entry ID from unique value format if present: "batch|date|bagSize|id"
    $bagSize = '';
    $cnc_entry_id = null;
    
    if (strpos($cncCuttingBatch, '|') !== false) {
        $parts = explode('|', $cncCuttingBatch);
        $cnc_entry_id_raw = !empty($parts[3]) ? trim($parts[3]) : null;
        $cnc_entry_id = !empty($cnc_entry_id_raw) && is_numeric($cnc_entry_id_raw) ? (int)$cnc_entry_id_raw : null;
    }
    
    // Fetch bag_size from the specific CNC entry
    if ($cnc_entry_id !== null && $cnc_entry_id > 0) {
        $bag_size_query = $conn->prepare("SELECT bag_size FROM cnc_entries WHERE id = ?");
        if ($bag_size_query) {
            $bag_size_query->bind_param("i", $cnc_entry_id);
            $bag_size_query->execute();
            $bag_size_result = $bag_size_query->get_result();
            if ($bag_size_row = $bag_size_result->fetch_assoc()) {
                $bagSize = trim($bag_size_row['bag_size'] ?? '');
            }
            $bag_size_query->close();
        }
    } else {
        // Fallback: Get bag_size from the first CNC entry with this batch number
        $batch_only = strpos($cncCuttingBatch, '|') !== false ? explode('|', $cncCuttingBatch)[0] : $cncCuttingBatch;
        $bag_size_query = $conn->prepare("SELECT bag_size FROM cnc_entries WHERE cnc_cutting_batch = ? ORDER BY id DESC LIMIT 1");
        if ($bag_size_query) {
            $bag_size_query->bind_param("s", $batch_only);
            $bag_size_query->execute();
            $bag_size_result = $bag_size_query->get_result();
            if ($bag_size_row = $bag_size_result->fetch_assoc()) {
                $bagSize = trim($bag_size_row['bag_size'] ?? '');
            }
            $bag_size_query->close();
        }
    }
    
    // If bag_size is still empty, use the extracted value from unique format as fallback
    if (empty($bagSize) && strpos($cncCuttingBatch, '|') !== false) {
        $parts = explode('|', $cncCuttingBatch);
        $bagSize = trim($parts[2] ?? '');
    }
    
    // Validate bag_size was found
    if (empty($bagSize)) {
        throw new Exception('Could not determine bag size from CNC entry. Please ensure the CNC batch is valid.');
    }
    
    // Extract actual batch number from unique value format if present: "batch|date|bagSize|id"
    $actual_batch_number = $cncCuttingBatch;
    if (strpos($cncCuttingBatch, '|') !== false) {
        $parts = explode('|', $cncCuttingBatch);
        $actual_batch_number = trim($parts[0] ?? $cncCuttingBatch);
    }
    
    // Fetch sewing_qty from sewing_machine_entry for this batch and bag_size
    // Formula: remaining_quantity = sewing_qty - (print_qty + ncp_piece)
    $sewing_qty = 0;
    $sewingTableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
    $sewingTable = ($sewingTableCheck && $sewingTableCheck->num_rows > 0) ? 'sewing_machine_entry' : 'swing_machine_entry';
    
    // Check which column name is used for cnc_cutting_batch
    $cncBatchColumn = null;
    $colCheck = $conn->query("SHOW COLUMNS FROM $sewingTable");
    if ($colCheck) {
        while ($col = $colCheck->fetch_assoc()) {
            $fieldName = $col['Field'];
            if ($fieldName === 'cnc_cutting_batch' || strpos($fieldName, 'cnc_cutting') !== false) {
                $cncBatchColumn = $fieldName;
                break;
            }
        }
    }
    
    if ($cncBatchColumn) {
        // Check if is_deleted column exists in sewing table
        $hasIsDeleted = false;
        $colCheck2 = $conn->query("SHOW COLUMNS FROM $sewingTable");
        if ($colCheck2) {
            while ($col = $colCheck2->fetch_assoc()) {
                if (strtolower($col['Field']) === 'is_deleted') {
                    $hasIsDeleted = true;
                    break;
                }
            }
        }
        
        // Build WHERE clause conditionally
        $whereClause = "{$cncBatchColumn} = ? AND COALESCE(NULLIF(bag_size, ''), '') = ?";
        if ($hasIsDeleted) {
            $whereClause .= " AND (is_deleted = 0 OR is_deleted IS NULL)";
        }
        
        // Get the sum of sewing_qty for this batch and bag_size
        $sewingQuery = $conn->prepare("SELECT SUM(COALESCE(sewing_qty, 0)) as total_sewing_qty 
            FROM {$sewingTable} 
            WHERE {$whereClause}");
        if ($sewingQuery) {
            $sewingQuery->bind_param("ss", $actual_batch_number, $bagSize);
            $sewingQuery->execute();
            $sewingResult = $sewingQuery->get_result();
            if ($sewingRow = $sewingResult->fetch_assoc()) {
                $sewing_qty = (int)($sewingRow['total_sewing_qty'] ?? 0);
            }
            $sewingQuery->close();
        }
    }
    
    // Calculate remaining_quantity = sewing_qty - (print_qty + ncp_piece)
    $remaining_quantity = max(0, $sewing_qty - ($printQty + $ncpPiece));

    // Create branding_entries table if it doesn't exist
    $createTable = "CREATE TABLE IF NOT EXISTS branding_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        branding_id VARCHAR(50) UNIQUE,
        date_time DATETIME,
        shift_incharge VARCHAR(100),
        reference_number VARCHAR(100),
        cnc_cutting_batch VARCHAR(100),
        project_id INT,
        print_machine VARCHAR(100),
        bag_size VARCHAR(100),
        print_qty INT,
        ncp_piece INT DEFAULT 0,
        remaining_quantity INT DEFAULT 0,
        reporter_id INT,
        reporter_name VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_deleted TINYINT(1) DEFAULT 0,
        who_did VARCHAR(100) DEFAULT '',
        deleted_at DATETIME NULL
    )";
    $conn->query($createTable);

    // Ensure all columns exist
    $conn->query("ALTER TABLE branding_entries ADD COLUMN IF NOT EXISTS branding_id VARCHAR(50) UNIQUE");
    $conn->query("ALTER TABLE branding_entries ADD COLUMN IF NOT EXISTS reference_number VARCHAR(100)");
    $conn->query("ALTER TABLE branding_entries ADD COLUMN IF NOT EXISTS cnc_cutting_batch VARCHAR(100)");
    $conn->query("ALTER TABLE branding_entries ADD COLUMN IF NOT EXISTS print_machine VARCHAR(100)");
    $conn->query("ALTER TABLE branding_entries ADD COLUMN IF NOT EXISTS ncp_piece INT DEFAULT 0");
    $conn->query("ALTER TABLE branding_entries ADD COLUMN IF NOT EXISTS remaining_quantity INT DEFAULT 0");
    
    // Drop obsolete columns
    $conn->query("ALTER TABLE branding_entries DROP COLUMN IF EXISTS machine_id");
    $conn->query("ALTER TABLE branding_entries DROP COLUMN IF EXISTS ncp_pcs");

    // Insert the branding entry (reference_number is optional, can be NULL)
    // Include remaining_quantity in the INSERT
    $stmt = $conn->prepare("INSERT INTO branding_entries (branding_id, date_time, shift_incharge, reference_number, cnc_cutting_batch, project_id, print_machine, bag_size, print_qty, ncp_piece, remaining_quantity, reporter_id, reporter_name) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    // Set reference_number to empty string if not provided (will be stored as NULL in DB)
    if (empty($referenceNumber)) {
        $referenceNumber = '';
    }
    
    // Store only the batch number in the database, not the full unique value
    $stored_batch_number = $actual_batch_number;

    $stmt->bind_param(
        'ssssssssiiiis',  // branding_id(s), date_time(s), shift_incharge(s), reference_number(s), cnc_cutting_batch(s), project_id(i), print_machine(s), bag_size(s), print_qty(i), ncp_piece(i), remaining_quantity(i), reporter_id(i), reporter_name(s)
        $brandingId,
        $dateTime,
        $shiftIncharge,
        $referenceNumber,
        $stored_batch_number,  // Store only the batch number, not the unique value format
        $projectId,
        $printMachine,
        $bagSize,
        $printQty,
        $ncpPiece,
        $remaining_quantity,  // Store calculated remaining_quantity
        $reporterId,
        $reporterName
    );

    // Execute the statement
    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    $stmt->close();
    
    // Calculate total used quantity (print_qty + ncp_piece)
    $total_used = $printQty + $ncpPiece;
    
    error_log("Branding entry submitted - Batch: '{$actual_batch_number}', Bag Size: '{$bagSize}', Sewing Qty: {$sewing_qty}, Print Qty: {$printQty}, NCP: {$ncpPiece}, Remaining Qty: {$remaining_quantity}");
    
    // Store branding usage in a separate table or update sewing_machine_entry
    // Since branding comes after sewing, we need to track branding usage separately
    // We'll create/update a tracking mechanism in sewing_machine_entry or create a new tracking table
    
    // Option 1: Add columns to sewing_machine_entry to track branding usage
    // Check if columns exist
    $conn->query("ALTER TABLE sewing_machine_entry ADD COLUMN IF NOT EXISTS branding_used_qty INT DEFAULT 0");
    $conn->query("ALTER TABLE sewing_machine_entry ADD COLUMN IF NOT EXISTS branding_remaining_qty INT DEFAULT 0");
    
    // Update sewing_machine_entry entries that match this batch and bag_size
    // Formula: branding_remaining_qty = sewing_qty - (old_branding_used_qty + print_qty + ncp_piece)
    if (!empty($actual_batch_number) && !empty($bagSize) && $total_used > 0) {
        // Check if is_deleted column exists
        $hasIsDeleted = $conn->query("SHOW COLUMNS FROM sewing_machine_entry LIKE 'is_deleted'")->num_rows > 0;
        
        $whereClause = "cnc_cutting_batch = ? AND bag_size = ?";
        if ($hasIsDeleted) {
            $whereClause .= " AND (is_deleted = 0 OR is_deleted IS NULL)";
        }
        
        $update_query = "UPDATE sewing_machine_entry SET 
            branding_used_qty = COALESCE(branding_used_qty, 0) + ?,
            branding_remaining_qty = GREATEST(0, sewing_qty - (COALESCE(branding_used_qty, 0) + ?))
            WHERE {$whereClause}";
        
        $update_stmt = $conn->prepare($update_query);
        if ($update_stmt) {
            // Bind total_used twice (once for branding_used_qty increment, once for branding_remaining_qty calculation), then batch and bag_size
            $update_stmt->bind_param("iiss", $total_used, $total_used, $actual_batch_number, $bagSize);
            $update_stmt->execute();
            $affected_rows = $update_stmt->affected_rows;
            $update_stmt->close();
            
            error_log("Branding entry submitted - Batch: '{$actual_batch_number}', Bag Size: '{$bagSize}', Print Qty: {$printQty}, NCP: {$ncpPiece}, Total Used: {$total_used}, Affected rows: {$affected_rows}");
        }
    }
    
    // Also update cnc_entries for reference (but primary tracking is in sewing_machine_entry)
    $conn->query("ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS used_qty INT DEFAULT 0");
    $conn->query("ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS remaining_qty INT DEFAULT 0");
    
    if (!empty($actual_batch_number) && $total_used > 0) {
        $hasIsDeleted = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'is_deleted'")->num_rows > 0;
        
        if ($hasIsDeleted) {
            $update_query = "UPDATE cnc_entries SET 
                used_qty = COALESCE(used_qty, 0) + ?,
                remaining_qty = GREATEST(0, cutting_roll_quantity - (COALESCE(used_qty, 0) + ?))
                WHERE cnc_cutting_batch = ? AND bag_size = ? AND (is_deleted = 0 OR is_deleted IS NULL)";
        } else {
            $update_query = "UPDATE cnc_entries SET 
                used_qty = COALESCE(used_qty, 0) + ?,
                remaining_qty = GREATEST(0, cutting_roll_quantity - (COALESCE(used_qty, 0) + ?))
                WHERE cnc_cutting_batch = ? AND bag_size = ?";
        }
        
        $update_stmt = $conn->prepare($update_query);
        if ($update_stmt) {
            $update_stmt->bind_param("iiss", $total_used, $total_used, $actual_batch_number, $bagSize);
            $update_stmt->execute();
            $update_stmt->close();
        }
    }
    
    $conn->close();

    // Redirect to form with success message
    header("Location: ../forms/branding_entry.php?success=branding_entry_saved&branding_id=" . urlencode($brandingId));

} catch (Exception $e) {
    error_log("Branding entry error: " . $e->getMessage());
    header("Location: ../forms/branding_entry.php?error=" . urlencode($e->getMessage()));
}
?>
