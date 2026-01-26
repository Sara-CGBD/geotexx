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
    $sewingEntryIds = trim($_POST['sewing_entry_ids'] ?? ''); // Comma-separated IDs from grouped sewing
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

    // Helper: get total cutting_roll_quantity from cnc_entries for this batch+bag_size
    $getCuttingQuantity = function ($conn, $batch, $bagSize) {
        $hasIsDeleted = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'is_deleted'")->num_rows > 0;
        $sql = "SELECT COALESCE(SUM(cutting_roll_quantity), 0) as cutting_qty FROM cnc_entries WHERE cnc_cutting_batch = ? AND TRIM(COALESCE(bag_size,'')) = ?";
        if ($hasIsDeleted) {
            $sql .= " AND (is_deleted = 0 OR is_deleted IS NULL)";
        }
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("ss", $batch, $bagSize);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ? (int)($row['cutting_qty'] ?? 0) : 0;
    };
    
    // Fetch bag_size from cnc_entries based on the selected CNC cutting batch
    // Extract entry ID from unique value format if present: "batch|date|bagSize|id"
    $bagSize = '';
    $actual_batch_number = '';
    $cnc_entry_id = null;
    
    if (strpos($cncCuttingBatch, '|') !== false) {
        $parts = explode('|', $cncCuttingBatch);
        $cnc_entry_id_raw = !empty($parts[3]) ? trim($parts[3]) : null;
        $cnc_entry_id = !empty($cnc_entry_id_raw) && is_numeric($cnc_entry_id_raw) ? (int)$cnc_entry_id_raw : null;
    }
    
    // Support "batch||bag_size" from dropdown (unique option value) so we use correct bag_size for remaining_quantity
    if (strpos($cncCuttingBatch, '||') !== false) {
        $parts = explode('||', $cncCuttingBatch, 2);
        $actual_batch_number = trim($parts[0] ?? '');
        $bagSize = trim($parts[1] ?? '');
    }
    // Fetch bag_size from cnc_entries only if not already from "batch||bag_size"
    if (empty($bagSize) && $cnc_entry_id !== null && $cnc_entry_id > 0) {
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
    }
    if (empty($bagSize) && strpos($cncCuttingBatch, '|') !== false && strpos($cncCuttingBatch, '||') === false) {
        $parts = explode('|', $cncCuttingBatch);
        $bagSize = trim($parts[2] ?? '');
    }
    if (empty($bagSize)) {
        $batch_only = strpos($cncCuttingBatch, '|') !== false ? explode('|', $cncCuttingBatch)[0] : $cncCuttingBatch;
        $batch_only = strpos($batch_only, '||') !== false ? explode('||', $batch_only)[0] : $batch_only;
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
    
    // Validate bag_size was found
    if (empty($bagSize)) {
        throw new Exception('Could not determine bag size from CNC entry. Please ensure the CNC batch is valid.');
    }
    
    // Extract actual batch number (already set if we had "batch||bag_size", else derive here)
    if (empty($actual_batch_number)) {
        $actual_batch_number = $cncCuttingBatch;
        if (strpos($cncCuttingBatch, '||') !== false) {
            $parts = explode('||', $cncCuttingBatch, 2);
            $actual_batch_number = trim($parts[0] ?? $cncCuttingBatch);
        } elseif (strpos($cncCuttingBatch, '|') !== false) {
            $parts = explode('|', $cncCuttingBatch);
            $actual_batch_number = trim($parts[0] ?? $cncCuttingBatch);
        }
    }
    
    // Fetch sewing_qty and total ncp from sewing_machine_entry for this batch and bag_size
    // remaining_quantity must match frontend: (sewing_qty - ncp_from_sewing) - all_branding_used_including_this
    $sewing_qty = 0;
    $total_ncp_sewing = 0;
    $sewingTableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
    $sewingTable = ($sewingTableCheck && $sewingTableCheck->num_rows > 0) ? 'sewing_machine_entry' : 'swing_machine_entry';
    
    // Check which column name is used for cnc_cutting_batch and if ncp_piece exists
    $cncBatchColumn = null;
    $hasNcpPiece = false;
    $colCheck = $conn->query("SHOW COLUMNS FROM $sewingTable");
    if ($colCheck) {
        while ($col = $colCheck->fetch_assoc()) {
            $fieldName = $col['Field'];
            if ($fieldName === 'cnc_cutting_batch' || strpos($fieldName, 'cnc_cutting') !== false) {
                $cncBatchColumn = $fieldName;
            }
            if (strtolower($fieldName) === 'ncp_piece') {
                $hasNcpPiece = true;
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
        
        // Get sum of sewing_qty, (if present) ncp_piece, and GROUP_CONCAT of ids for traceability
        $sewingSelect = "SUM(COALESCE(sewing_qty, 0)) as total_sewing_qty";
        if ($hasNcpPiece) {
            $sewingSelect .= ", SUM(COALESCE(ncp_piece, 0)) as total_ncp";
        }
        $sewingSelect .= ", GROUP_CONCAT(id ORDER BY id) as sewing_entry_ids";
        $sewingQuery = $conn->prepare("SELECT {$sewingSelect} FROM {$sewingTable} WHERE {$whereClause}");
        if ($sewingQuery) {
            $sewingQuery->bind_param("ss", $actual_batch_number, $bagSize);
            $sewingQuery->execute();
            $sewingResult = $sewingQuery->get_result();
            if ($sewingRow = $sewingResult->fetch_assoc()) {
                $sewing_qty = (int)($sewingRow['total_sewing_qty'] ?? 0);
                $total_ncp_sewing = (int)($sewingRow['total_ncp'] ?? 0);
                // Store comma-separated sewing entry IDs for this batch+bag_size group
                $ids = trim($sewingRow['sewing_entry_ids'] ?? '');
                if ($ids !== '') {
                    $sewingEntryIds = $ids;
                }
            }
            $sewingQuery->close();
        }
    }
    
    // Existing branding used (print_qty + ncp_piece) for this batch+bag_size (same logic as get_sewing_cnc_batches)
    $branding_used = 0;
    $beCols = $conn->query("SHOW COLUMNS FROM branding_entries");
    $beHasBagSize = false;
    $beHasIsDeleted = false;
    if ($beCols) {
        while ($c = $beCols->fetch_assoc()) {
            if (strtolower($c['Field']) === 'bag_size') $beHasBagSize = true;
            if (strtolower($c['Field']) === 'is_deleted') $beHasIsDeleted = true;
        }
    }
    $beWhere = "cnc_cutting_batch = ?";
    $beParams = [$actual_batch_number];
    $beTypes = "s";
    if ($beHasBagSize && $bagSize !== '') {
        $beWhere .= " AND TRIM(COALESCE(bag_size,'')) = ?";
        $beParams[] = $bagSize;
        $beTypes .= "s";
    }
    if ($beHasIsDeleted) {
        $beWhere .= " AND (is_deleted = 0 OR is_deleted IS NULL)";
    }
    $beStmt = $conn->prepare("SELECT COALESCE(SUM(COALESCE(print_qty,0) + COALESCE(ncp_piece,0)), 0) as tot FROM branding_entries WHERE {$beWhere}");
    if ($beStmt) {
        $beStmt->bind_param($beTypes, ...$beParams);
        $beStmt->execute();
        $beRes = $beStmt->get_result();
        if ($beRow = $beRes->fetch_assoc()) {
            $branding_used = (int)($beRow['tot'] ?? 0);
        }
        $beStmt->close();
    }
    
    // remaining_quantity is always from sewing_machine_entry: sewing qty for this batch+bag_size minus total branding used (including this entry)
    $total_used_after_this = $branding_used + $printQty + $ncpPiece;
    $remaining_quantity = max(0, $sewing_qty - $total_used_after_this);

    // Cutting roll quantity from CNC for this batch+bag_size (for traceability / remaining_from_cnc = cuttingQty - totalUsed)
    $cutting_roll_quantity = $getCuttingQuantity($conn, $actual_batch_number, $bagSize);

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
    $conn->query("ALTER TABLE branding_entries ADD COLUMN IF NOT EXISTS sewing_entry_ids TEXT NULL");
    $conn->query("ALTER TABLE branding_entries ADD COLUMN IF NOT EXISTS cutting_roll_quantity INT NULL");
    $conn->query("ALTER TABLE branding_entries ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'pending'");
    // Add merged_sewing_qty and merged_print_qty for tracking (same batch+bag_size)
    $mergedCol = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'merged_sewing_qty'");
    if (!$mergedCol || $mergedCol->num_rows === 0) {
        $conn->query("ALTER TABLE branding_entries ADD COLUMN merged_sewing_qty INT NULL");
    }
    $mergedPrintCol = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'merged_print_qty'");
    if (!$mergedPrintCol || $mergedPrintCol->num_rows === 0) {
        $conn->query("ALTER TABLE branding_entries ADD COLUMN merged_print_qty INT NULL");
    }
    
    // Drop obsolete columns
    $conn->query("ALTER TABLE branding_entries DROP COLUMN IF EXISTS machine_id");
    $conn->query("ALTER TABLE branding_entries DROP COLUMN IF EXISTS ncp_pcs");

    // Check if optional columns exist for INSERT
    $hasSewingEntryIds = false;
    $hasCuttingRollQty = false;
    $hasMergedSewingQty = false;
    $hasMergedPrintQty = false;
    $cols = $conn->query("SHOW COLUMNS FROM branding_entries");
    if ($cols) {
        while ($c = $cols->fetch_assoc()) {
            if (strtolower($c['Field']) === 'sewing_entry_ids') {
                $hasSewingEntryIds = true;
            }
            if (strtolower($c['Field']) === 'cutting_roll_quantity') {
                $hasCuttingRollQty = true;
            }
            if (strtolower($c['Field']) === 'merged_sewing_qty') {
                $hasMergedSewingQty = true;
            }
            if (strtolower($c['Field']) === 'merged_print_qty') {
                $hasMergedPrintQty = true;
            }
        }
    }

    // Insert the branding entry (reference_number is optional, can be NULL)
    // Include remaining_quantity, sewing_entry_ids, and cutting_roll_quantity when columns exist
    $insertCols = "branding_id, date_time, shift_incharge, reference_number, cnc_cutting_batch, project_id, print_machine, bag_size, print_qty, ncp_piece, remaining_quantity";
    $insertPlaceholders = "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?";
    if ($hasSewingEntryIds) {
        $insertCols .= ", sewing_entry_ids";
        $insertPlaceholders .= ", ?";
    }
    if ($hasCuttingRollQty) {
        $insertCols .= ", cutting_roll_quantity";
        $insertPlaceholders .= ", ?";
    }
    if ($hasMergedSewingQty) {
        $insertCols .= ", merged_sewing_qty";
        $insertPlaceholders .= ", ?";
    }
    if ($hasMergedPrintQty) {
        $insertCols .= ", merged_print_qty";
        $insertPlaceholders .= ", ?";
    }
    $insertCols .= ", reporter_id, reporter_name";
    $insertPlaceholders .= ", ?, ?";
    $stmt = $conn->prepare("INSERT INTO branding_entries ($insertCols) VALUES ($insertPlaceholders)");
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    // Set reference_number to empty string if not provided (will be stored as NULL in DB)
    if (empty($referenceNumber)) {
        $referenceNumber = '';
    }
    
    // Store only the batch number in the database, not the full unique value
    $stored_batch_number = $actual_batch_number;

    $bindTypes = 'ssssssssiii';
    $bindValues = [$brandingId, $dateTime, $shiftIncharge, $referenceNumber, $stored_batch_number, $projectId, $printMachine, $bagSize, $printQty, $ncpPiece, $remaining_quantity];
    if ($hasSewingEntryIds) {
        $bindTypes .= 's';
        $bindValues[] = $sewingEntryIds;
    }
    if ($hasCuttingRollQty) {
        $bindTypes .= 'i';
        $bindValues[] = $cutting_roll_quantity > 0 ? $cutting_roll_quantity : null;
    }
    if ($hasMergedSewingQty) {
        $bindTypes .= 'i';
        $bindValues[] = $sewing_qty > 0 ? $sewing_qty : null;
    }
    if ($hasMergedPrintQty) {
        $bindTypes .= 'i';
        $bindValues[] = $printQty;
    }
    $bindTypes .= 'is';
    $bindValues[] = $reporterId;
    $bindValues[] = $reporterName;
    $stmt->bind_param($bindTypes, ...$bindValues);

    // Execute the statement
    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    $stmt->close();
    
    // merged_print_qty = sum of PRINT QTY only (not ncp_piece) for same cnc_cutting_batch + bag_size
    if ($hasMergedPrintQty && !empty($actual_batch_number)) {
        $upWhere = "TRIM(COALESCE(e.cnc_cutting_batch,'')) = ?";
        $upParams = [$actual_batch_number];
        $upTypes = "s";
        if ($beHasBagSize && $bagSize !== '') {
            $upWhere .= " AND TRIM(COALESCE(e.bag_size,'')) = ?";
            $upParams[] = $bagSize;
            $upTypes .= "s";
        }
        if ($beHasIsDeleted) {
            $upWhere .= " AND (e.is_deleted = 0 OR e.is_deleted IS NULL)";
        }
        $subWhere = "TRIM(COALESCE(e2.cnc_cutting_batch,'')) = TRIM(COALESCE(e.cnc_cutting_batch,'')) AND TRIM(COALESCE(e2.bag_size,'')) = TRIM(COALESCE(e.bag_size,''))";
        if ($beHasIsDeleted) {
            $subWhere .= " AND (e2.is_deleted = 0 OR e2.is_deleted IS NULL)";
        }
        $upSql = "UPDATE branding_entries e SET e.merged_print_qty = (SELECT COALESCE(SUM(COALESCE(e2.print_qty,0)),0) FROM branding_entries e2 WHERE {$subWhere}) WHERE {$upWhere}";
        $upStmt = $conn->prepare($upSql);
        if ($upStmt) {
            $upStmt->bind_param($upTypes, ...$upParams);
            $upStmt->execute();
            $upStmt->close();
        }
    }
    
    // Calculate total used quantity (print_qty + ncp_piece)
    $total_used = $printQty + $ncpPiece;
    
    error_log("Branding entry submitted - Batch: '{$actual_batch_number}', Bag Size: '{$bagSize}', Sewing Qty: {$sewing_qty}, Print Qty: {$printQty}, NCP: {$ncpPiece}, Remaining Qty: {$remaining_quantity}");
    
    // Store branding usage in a separate table or update sewing_machine_entry
    // Since branding comes after sewing, we need to track branding usage separately
    // We'll create/update a tracking mechanism in sewing_machine_entry or create a new tracking table
    
    // Option 1: Add columns to sewing_machine_entry to track branding usage and merged sewing qty
    $conn->query("ALTER TABLE sewing_machine_entry ADD COLUMN IF NOT EXISTS branding_used_qty INT DEFAULT 0");
    $conn->query("ALTER TABLE sewing_machine_entry ADD COLUMN IF NOT EXISTS branding_remaining_qty INT DEFAULT 0");
    $sewingMergedCol = $conn->query("SHOW COLUMNS FROM sewing_machine_entry LIKE 'merged_sewing_qty'");
    if (!$sewingMergedCol || $sewingMergedCol->num_rows === 0) {
        $conn->query("ALTER TABLE sewing_machine_entry ADD COLUMN merged_sewing_qty INT NULL");
    }
    
    // Update sewing_machine_entry entries that match this batch and bag_size
    // Set: branding_used_qty += total_used, branding_remaining_qty, and merged_sewing_qty (group total for tracking)
    if (!empty($actual_batch_number) && !empty($bagSize)) {
        // Check if is_deleted column exists
        $hasIsDeleted = $conn->query("SHOW COLUMNS FROM sewing_machine_entry LIKE 'is_deleted'")->num_rows > 0;
        $hasMergedSewingQtyCol = $conn->query("SHOW COLUMNS FROM sewing_machine_entry LIKE 'merged_sewing_qty'")->num_rows > 0;
        
        $whereClause = "cnc_cutting_batch = ? AND bag_size = ?";
        if ($hasIsDeleted) {
            $whereClause .= " AND (is_deleted = 0 OR is_deleted IS NULL)";
        }
        
        $setParts = [
            "branding_used_qty = COALESCE(branding_used_qty, 0) + ?",
            "branding_remaining_qty = GREATEST(0, sewing_qty - (COALESCE(branding_used_qty, 0) + ?))"
        ];
        if ($hasMergedSewingQtyCol) {
            $setParts[] = "merged_sewing_qty = ?";
        }
        $update_query = "UPDATE sewing_machine_entry SET " . implode(", ", $setParts) . " WHERE {$whereClause}";
        
        $update_stmt = $conn->prepare($update_query);
        if ($update_stmt) {
            if ($hasMergedSewingQtyCol) {
                $update_stmt->bind_param("iiiss", $total_used, $total_used, $sewing_qty, $actual_batch_number, $bagSize);
            } else {
                $update_stmt->bind_param("iiss", $total_used, $total_used, $actual_batch_number, $bagSize);
            }
            $update_stmt->execute();
            $affected_rows = $update_stmt->affected_rows;
            $update_stmt->close();
            
            error_log("Branding entry submitted - Batch: '{$actual_batch_number}', Bag Size: '{$bagSize}', Print Qty: {$printQty}, NCP: {$ncpPiece}, Total Used: {$total_used}, Merged sewing qty: {$sewing_qty}, Affected rows: {$affected_rows}");
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
