<?php
session_start();
require_once '../config/security_config.php';

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Enable error reporting for debugging (remove in production)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Database connection
$conn = SecurityConfig::getConnection();

// Set timezone to Bangladesh
date_default_timezone_set('Asia/Dhaka');

// Check if form submitted
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Collect form data
    $sewing_id    = $_POST['sewing_id'] ?? '';
    $date_time   = $_POST['date_time'] ?? date('Y-m-d H:i:s');
    // Note: $date_time is already in Bangladesh time format from frontend
    
    $reporter_id = $_POST['reporter_id'] ?? $_SESSION['user_id'];
    $cnc_cutting_batch_raw = $_POST['cnc_cutting_batch'] ?? '';
    
    // Extract components from unique value format: "batch|date|bagSize|id"
    $cnc_cutting_batch = $cnc_cutting_batch_raw;
    $cnc_entry_id = null;
    $cnc_batch_date = null;
    $cnc_bag_size = null;
    
    if (strpos($cnc_cutting_batch_raw, '|') !== false) {
        $parts = explode('|', $cnc_cutting_batch_raw);
        $cnc_cutting_batch = trim($parts[0] ?? ''); // Batch number
        $cnc_batch_date = trim($parts[1] ?? ''); // Date
        $cnc_bag_size = trim($parts[2] ?? ''); // Bag size
        $cnc_entry_id_raw = !empty($parts[3]) ? trim($parts[3]) : null;
        $cnc_entry_id = !empty($cnc_entry_id_raw) && is_numeric($cnc_entry_id_raw) ? (int)$cnc_entry_id_raw : null; // Entry ID (most specific)
        
        // Log extraction for debugging
        error_log("Extracted from unique value - Raw: '{$cnc_cutting_batch_raw}', Batch: '{$cnc_cutting_batch}', Date: '{$cnc_batch_date}', Bag Size: '{$cnc_bag_size}', Entry ID: " . ($cnc_entry_id ?? 'NULL'));
        
        // Validate entry ID
        if ($cnc_entry_id === null || $cnc_entry_id <= 0) {
            error_log("WARNING: Entry ID extraction failed or invalid - Raw value: '{$cnc_entry_id_raw}', Parsed: " . ($cnc_entry_id ?? 'NULL'));
        }
    } else {
        error_log("WARNING: Unique value format not found in '{$cnc_cutting_batch_raw}' - falling back to batch only. This may cause multiple rows to be updated!");
    }
    
    // reference_number is optional - the cnc_cutting_batch serves as the trace to CNC entries
    $reference_number = $_POST['reference_number'] ?? '';
    $project_id  = $_POST['project_id'] ?? '';
    $line_no     = $_POST['line_no'] ?? '';
    $sewing_qty  = $_POST['sewing_qty'] ?? '';
    $ncp_piece   = $_POST['ncp_piece'] ?? '';

    // Validate required fields
    // Note: reference_number and line_no are now optional
    $missing = [];
    if (empty($sewing_id)) $missing[] = 'sewing_id';
    if (empty($cnc_cutting_batch)) $missing[] = 'cnc_cutting_batch';
    // reference_number is optional - removed from validation
    if (empty($project_id)) $missing[] = 'project_id';
    // line_no is optional - removed from validation
    if (empty($sewing_qty)) $missing[] = 'sewing_qty';
    if (empty($ncp_piece) && $ncp_piece !== '0') $missing[] = 'ncp_piece';
    
    if (!empty($missing)) {
        $missing_fields_text = implode(', ', $missing);
        header("Location: ../forms/sewing_machine_entry.php?error=" . urlencode("Missing fields: " . $missing_fields_text));
        exit();
    }

    // Check if old table exists and rename it, or create new table
    $old_table_check = $conn->query("SHOW TABLES LIKE 'swing_machine_entry'");
    if ($old_table_check && $old_table_check->num_rows > 0) {
        // Rename old table to new name if it doesn't exist
        $new_table_check = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
        if (!$new_table_check || $new_table_check->num_rows == 0) {
            $conn->query("RENAME TABLE swing_machine_entry TO sewing_machine_entry");
        }
        // Rename old column to new name if it exists
        $old_col_check = $conn->query("SHOW COLUMNS FROM sewing_machine_entry LIKE 'swing_id'");
        if ($old_col_check && $old_col_check->num_rows > 0) {
            $new_col_check = $conn->query("SHOW COLUMNS FROM sewing_machine_entry LIKE 'sewing_id'");
            if (!$new_col_check || $new_col_check->num_rows == 0) {
                $conn->query("ALTER TABLE sewing_machine_entry CHANGE swing_id sewing_id VARCHAR(50) UNIQUE NOT NULL");
            }
        }
    }
    
    // Ensure table exists
    $create_table = "
        CREATE TABLE IF NOT EXISTS sewing_machine_entry (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sewing_id VARCHAR(50) UNIQUE NOT NULL,
            date_time DATETIME NOT NULL,
            shift VARCHAR(10) NOT NULL,
            reporter_id INT NOT NULL,
            cnc_cutting_batch VARCHAR(150),
            reference_number VARCHAR(100),
            source_cnc_id VARCHAR(50),
            project_id INT NOT NULL,
            line_no VARCHAR(50) DEFAULT NULL,
            sewing_qty INT NOT NULL,
            ncp_piece INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $conn->query($create_table);
    
    // Also add columns if they don't exist
$alter_columns = [
    "ALTER TABLE sewing_machine_entry ADD COLUMN IF NOT EXISTS cnc_cutting_batch VARCHAR(150)",
    "ALTER TABLE sewing_machine_entry ADD COLUMN IF NOT EXISTS reference_number VARCHAR(100)",
    "ALTER TABLE sewing_machine_entry ADD COLUMN IF NOT EXISTS source_cnc_id VARCHAR(50)",
    "ALTER TABLE sewing_machine_entry ADD COLUMN IF NOT EXISTS sewing_id VARCHAR(50)"
];
foreach ($alter_columns as $sql) {
    $conn->query($sql);
}

    // Determine shift using Bangladesh timezone
    $dhaka_hour = (int)date('H');
    $shift = ($dhaka_hour >= 8 && $dhaka_hour < 20) ? 'Day' : 'Night';

    // Track which CNC entry this batch came from
    // Find the first CNC entry with this batch number to establish the trace
    // Note: $cnc_cutting_batch is already extracted to just the batch number above
    $source_cnc_id = null;
    if (!empty($cnc_cutting_batch)) {
        $cnc_trace_query = $conn->prepare("SELECT cnc_id FROM cnc_entries WHERE cnc_cutting_batch = ? ORDER BY date_time ASC LIMIT 1");
        if ($cnc_trace_query) {
            $cnc_trace_query->bind_param("s", $cnc_cutting_batch);
            $cnc_trace_query->execute();
            $cnc_trace_result = $cnc_trace_query->get_result();
            if ($cnc_trace_result && $cnc_trace_row = $cnc_trace_result->fetch_assoc()) {
                $source_cnc_id = $cnc_trace_row['cnc_id'];
            }
            $cnc_trace_query->close();
        }
    }

    // Check if this sewing entry already exists (prevent double submission)
    $check_existing = $conn->prepare("SELECT id FROM sewing_machine_entry WHERE sewing_id = ?");
    if ($check_existing) {
        $check_existing->bind_param("s", $sewing_id);
        $check_existing->execute();
        $check_result = $check_existing->get_result();
        if ($check_result->num_rows > 0) {
            // Entry already exists - this is a duplicate submission
            $check_existing->close();
            error_log("ERROR: Duplicate submission detected - sewing_id '{$sewing_id}' already exists!");
            header("Location: ../forms/swing_machine_entry.php?error=" . urlencode("This entry was already submitted. Please refresh the page."));
            exit();
        }
        $check_existing->close();
    }
    
    // Get bag_size and cutting_roll_quantity from CNC entry (for remaining_quantity)
    $bag_size = $cnc_bag_size; // Default to extracted bag_size
    $cutting_roll_quantity = null;
    if ($cnc_entry_id !== null && $cnc_entry_id > 0) {
        $cnc_row_query = $conn->prepare("SELECT bag_size, cutting_roll_quantity FROM cnc_entries WHERE id = ?");
        if ($cnc_row_query) {
            $cnc_row_query->bind_param("i", $cnc_entry_id);
            $cnc_row_query->execute();
            $cnc_row_result = $cnc_row_query->get_result();
            if ($cnc_row = $cnc_row_result->fetch_assoc()) {
                $bag_size = trim($cnc_row['bag_size'] ?? $cnc_bag_size);
                $cutting_roll_quantity = isset($cnc_row['cutting_roll_quantity']) ? (int)$cnc_row['cutting_roll_quantity'] : null;
            }
            $cnc_row_query->close();
        }
    }
    if ($cutting_roll_quantity === null && !empty($cnc_cutting_batch) && !empty($cnc_batch_date) && !empty($cnc_bag_size)) {
        $hasDateTime = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'date_time'")->num_rows > 0;
        $dateCol = $hasDateTime ? 'date_time' : 'created_at';
        $cnc_fall = $conn->prepare("SELECT cutting_roll_quantity FROM cnc_entries WHERE cnc_cutting_batch = ? AND DATE(" . $dateCol . ") = ? AND bag_size = ? LIMIT 1");
        if ($cnc_fall) {
            $cnc_fall->bind_param("sss", $cnc_cutting_batch, $cnc_batch_date, $cnc_bag_size);
            $cnc_fall->execute();
            $res = $cnc_fall->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            if ($row && isset($row['cutting_roll_quantity'])) {
                $cutting_roll_quantity = (int)$row['cutting_roll_quantity'];
            }
            $cnc_fall->close();
        }
    }
    
    // remaining_quantity = cutting_roll_quantity - (sewing_qty + ncp_piece), store in sewing_machine_entry
    $total_used_for_remaining = (int)$sewing_qty + (int)$ncp_piece;
    $remaining_quantity = null;
    if ($cutting_roll_quantity !== null) {
        $remaining_quantity = max(0, $cutting_roll_quantity - $total_used_for_remaining);
    }
    
    // Ensure bag_size, final_sewing_quantity and remaining_quantity columns exist in sewing_machine_entry
    $conn->query("ALTER TABLE sewing_machine_entry ADD COLUMN IF NOT EXISTS bag_size VARCHAR(100)");
    $conn->query("ALTER TABLE sewing_machine_entry ADD COLUMN IF NOT EXISTS final_sewing_quantity INT DEFAULT 0");
    $conn->query("ALTER TABLE sewing_machine_entry ADD COLUMN IF NOT EXISTS remaining_quantity INT DEFAULT NULL");
    
    // final_sewing_quantity = sewing_qty - ncp_piece (stored per row)
    $final_sewing_quantity = max(0, (int)$sewing_qty - (int)$ncp_piece);
    
    // Insert data
    $stmt = $conn->prepare("
        INSERT INTO sewing_machine_entry 
        (sewing_id, date_time, shift, reporter_id, cnc_cutting_batch, reference_number, source_cnc_id, project_id, line_no, sewing_qty, ncp_piece, final_sewing_quantity, remaining_quantity, bag_size)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if ($stmt) {
         // Types: sewing_id(s), date_time(s), shift(s), reporter_id(i), cnc_cutting_batch(s), reference_number(s),
         // source_cnc_id(s), project_id(i), line_no(s), sewing_qty(i), ncp_piece(i), final_sewing_quantity(i), remaining_quantity(i), bag_size(s)
         $stmt->bind_param(
             "sssississiiiis",
             $sewing_id,
             $date_time,
             $shift,
             $reporter_id,
             $cnc_cutting_batch,
             $reference_number,
             $source_cnc_id,
             $project_id,
             $line_no,
             $sewing_qty,
             $ncp_piece,
             $final_sewing_quantity,
             $remaining_quantity,
             $bag_size
         );

        try {
            $stmt->execute();
            $entry_id = $sewing_id; // Use the sewing_id as entry ID
            
            // Update used_qty and remaining_qty in cnc_entries for this batch
            // Add columns if they don't exist
            $conn->query("ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS used_qty INT DEFAULT 0");
            $conn->query("ALTER TABLE cnc_entries ADD COLUMN IF NOT EXISTS remaining_qty INT DEFAULT 0");
            
            // Calculate total used quantity (sewing_qty + ncp_piece)
            $total_used = (int)$sewing_qty + (int)$ncp_piece;
            
            // Log for debugging - include all extracted values
            error_log("Sewing entry submitted - Batch: '{$cnc_cutting_batch}', Entry ID: " . ($cnc_entry_id ?? 'NULL') . ", Date: '{$cnc_batch_date}', Bag Size: '{$cnc_bag_size}', Sewing Qty: {$sewing_qty}, NCP: {$ncp_piece}, Total Used: {$total_used}");
            
            // Update ONLY the specific CNC entry that was selected (by ID, or by batch+date+bag_size)
            if (!empty($cnc_cutting_batch) && $total_used > 0) {
                // Check if is_deleted column exists
                $hasIsDeleted = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'is_deleted'")->num_rows > 0;
                
                // Build WHERE clause - prefer ID (most specific), then batch+date+bag_size, then just batch
                $where_clause = "";
                $where_params = [];
                $where_types = "";
                
                if ($cnc_entry_id !== null && $cnc_entry_id > 0) {
                    // Most specific: Update by entry ID (ALWAYS prefer this)
                    $where_clause = "id = ?";
                    $where_params[] = $cnc_entry_id;
                    $where_types = "i";
                    error_log("Updating by entry ID: {$cnc_entry_id} (most specific)");
                } elseif (!empty($cnc_batch_date) && !empty($cnc_bag_size)) {
                    // Specific: Update by batch + date + bag_size
                    // Check which date column exists
                    $hasDateTime = $conn->query("SHOW COLUMNS FROM cnc_entries LIKE 'date_time'")->num_rows > 0;
                    $dateColumn = $hasDateTime ? 'date_time' : 'created_at';
                    
                    $where_clause = "cnc_cutting_batch = ? AND DATE({$dateColumn}) = ? AND bag_size = ?";
                    $where_params = [$cnc_cutting_batch, $cnc_batch_date, $cnc_bag_size];
                    $where_types = "sss";
                    error_log("Updating by batch+date+bag_size: '{$cnc_cutting_batch}', '{$cnc_batch_date}', '{$cnc_bag_size}'");
                } else {
                    // CRITICAL: Don't update if we don't have entry ID or batch+date+bag_size
                    // Updating by batch only can match multiple entries and cause incorrect quantities
                    error_log("ERROR: Cannot update - missing entry ID, date, or bag_size. Batch: '{$cnc_cutting_batch}'. Skipping quantity update to prevent incorrect data.");
                    // Don't set where_clause - we'll skip the update
                    $where_clause = null;
                }
                
                // Add is_deleted condition if column exists
                if ($hasIsDeleted) {
                    $where_clause .= " AND (is_deleted = 0 OR is_deleted IS NULL)";
                }
                
                // Only proceed if we have a valid WHERE clause
                if (empty($where_clause)) {
                    error_log("ERROR: Cannot update cnc_entries - no valid WHERE clause. Entry ID, date, or bag_size is missing.");
                } else {
                    // Build and execute UPDATE query - update both used_qty and remaining_qty
                    // Formula: remaining_qty = cutting_roll_quantity - (old_used_qty + sewing_qty + ncp_piece)
                    // Example: If cutting_roll_quantity = 156, sewing_qty = 140, ncp_piece = 6:
                    //   - total_used = 140 + 6 = 146
                    //   - new used_qty = old_used_qty + 146
                    //   - new remaining_qty = 156 - (old_used_qty + 146)
                    // Note: NCP pieces are defective quantities from sewing quantity, so both are deducted
                    $update_query = "UPDATE cnc_entries SET 
                        used_qty = COALESCE(used_qty, 0) + ?,
                        remaining_qty = GREATEST(0, cutting_roll_quantity - (COALESCE(used_qty, 0) + ?))
                        WHERE {$where_clause}";
                    $update_stmt = $conn->prepare($update_query);
                
                    if ($update_stmt) {
                        // Bind parameters: total_used twice (once for used_qty increment, once for remaining_qty calculation), then where clause params
                        $bind_params = array_merge([$total_used, $total_used], $where_params);
                        $bind_types = "ii" . $where_types; // total_used is integer, used twice
                        $update_stmt->bind_param($bind_types, ...$bind_params);
                    
                    if ($update_stmt->execute()) {
                        $affected_rows = $update_stmt->affected_rows;
                        error_log("Updated used_qty and remaining_qty: Added {$total_used}, Affected rows: {$affected_rows}");
                        
                        // CRITICAL: Verify only ONE row was affected (prevent double updates)
                        if ($affected_rows > 1) {
                            error_log("ERROR: UPDATE affected {$affected_rows} rows! Expected 1. This may cause incorrect quantities. WHERE clause: {$where_clause}");
                            
                            // ROLLBACK: If multiple rows were affected, we need to revert the changes
                            // Get all affected rows and revert them
                            $rollback_stmt = $conn->prepare("SELECT id, used_qty, cutting_roll_quantity FROM cnc_entries WHERE {$where_clause}");
                            if ($rollback_stmt && !empty($where_params)) {
                                $rollback_stmt->bind_param($where_types, ...$where_params);
                                $rollback_stmt->execute();
                                $rollback_result = $rollback_stmt->get_result();
                                
                                while ($rollback_row = $rollback_result->fetch_assoc()) {
                                    $rollback_id = (int)($rollback_row['id'] ?? 0);
                                    $rollback_used = (int)($rollback_row['used_qty'] ?? 0);
                                    $rollback_total = (int)($rollback_row['cutting_roll_quantity'] ?? 0);
                                    
                                    // Revert: Subtract the amount we just added
                                    $reverted_used = max(0, $rollback_used - $total_used);
                                    $reverted_remaining = max(0, $rollback_total - $reverted_used);
                                    
                                    $revert_stmt = $conn->prepare("UPDATE cnc_entries SET used_qty = ?, remaining_qty = ? WHERE id = ?");
                                    if ($revert_stmt) {
                                        $revert_stmt->bind_param("iii", $reverted_used, $reverted_remaining, $rollback_id);
                                        $revert_stmt->execute();
                                        $revert_stmt->close();
                                        error_log("ROLLBACK: Reverted entry ID {$rollback_id} - used_qty from {$rollback_used} to {$reverted_used}");
                                    }
                                }
                                $rollback_stmt->close();
                                
                                // Now update ONLY the correct entry (if we have entry ID)
                                if ($cnc_entry_id !== null && $cnc_entry_id > 0) {
                                    $correct_update = $conn->prepare("UPDATE cnc_entries SET used_qty = COALESCE(used_qty, 0) + ?, remaining_qty = GREATEST(0, cutting_roll_quantity - (COALESCE(used_qty, 0) + ?)) WHERE id = ?");
                                    if ($correct_update) {
                                        $correct_update->bind_param("iii", $total_used, $total_used, $cnc_entry_id);
                                        $correct_update->execute();
                                        $correct_update->close();
                                        error_log("CORRECTED: Updated only entry ID {$cnc_entry_id} with {$total_used}");
                                    }
                                } else {
                                    error_log("ERROR: Cannot correct - no entry ID available. User must manually fix the database.");
                                }
                            }
                        } elseif ($affected_rows == 0) {
                            error_log("WARNING: UPDATE affected 0 rows! The WHERE clause may not have matched any entry. WHERE clause: {$where_clause}");
                        }
                        
                        // Verify the update - check the actual values in the database
                        if ($affected_rows > 0) {
                            // Get the updated values to verify
                            $verify_stmt = $conn->prepare("SELECT id, used_qty, remaining_qty, cutting_roll_quantity FROM cnc_entries WHERE {$where_clause} LIMIT 1");
                            if ($verify_stmt && !empty($where_params)) {
                                // Bind the same where params
                                $verify_stmt->bind_param($where_types, ...$where_params);
                                $verify_stmt->execute();
                                $verify_result = $verify_stmt->get_result();
                                if ($verify_row = $verify_result->fetch_assoc()) {
                                    $verify_id = (int)($verify_row['id'] ?? 0);
                                    $verify_used = (int)($verify_row['used_qty'] ?? 0);
                                    $verify_remaining = (int)($verify_row['remaining_qty'] ?? 0);
                                    $verify_total = (int)($verify_row['cutting_roll_quantity'] ?? 0);
                                    error_log("Verification - Entry ID: {$verify_id}, Total: {$verify_total}, Used: {$verify_used}, Remaining: {$verify_remaining}");
                                    
                                    // Check if used_qty exceeds total (shouldn't happen)
                                    if ($verify_used > $verify_total) {
                                        error_log("ERROR: used_qty ({$verify_used}) exceeds cutting_roll_quantity ({$verify_total})! This entry may have been updated multiple times.");
                                        
                                        // Fix: Set used_qty to total and remaining to 0 (can't use more than available)
                                        $fix_used = $verify_total;
                                        $fix_remaining = 0;
                                        $fix_stmt = $conn->prepare("UPDATE cnc_entries SET used_qty = ?, remaining_qty = ? WHERE id = ?");
                                        if ($fix_stmt) {
                                            $fix_stmt->bind_param("iii", $fix_used, $fix_remaining, $verify_id);
                                            $fix_stmt->execute();
                                            $fix_stmt->close();
                                            error_log("Fixed: Set used_qty to {$fix_used} and remaining_qty to {$fix_remaining} for entry ID {$verify_id}");
                                        }
                                    }
                                    
                                    // Check if remaining_qty is correct
                                    $expected_remaining = max(0, $verify_total - $verify_used);
                                    if ($verify_remaining != $expected_remaining) {
                                        error_log("WARNING: remaining_qty ({$verify_remaining}) doesn't match calculation ({$expected_remaining}). Updating...");
                                        // Fix the remaining_qty
                                        $fix_stmt = $conn->prepare("UPDATE cnc_entries SET remaining_qty = ? WHERE id = ?");
                                        if ($fix_stmt) {
                                            $fix_stmt->bind_param("ii", $expected_remaining, $verify_id);
                                            $fix_stmt->execute();
                                            $fix_stmt->close();
                                            error_log("Fixed remaining_qty to {$expected_remaining} for entry ID {$verify_id}");
                                        }
                                    }
                                }
                                $verify_stmt->close();
                            }
                        }
                        } else {
                            error_log("Failed to update used_qty/remaining_qty: " . $update_stmt->error);
                        }
                        $update_stmt->close();
                    } else {
                        error_log("Failed to prepare UPDATE query: " . $conn->error);
                    }
                }
            }
            
            header("Location: ../forms/swing_machine_entry.php?success=sewing_entry_saved&entry_id=" . urlencode($entry_id));
            exit();
        } catch (mysqli_sql_exception $e) {
            $error_msg = urlencode("Insert failed: " . $e->getMessage());
            header("Location: ../forms/swing_machine_entry.php?error=" . $error_msg);
            exit();
        }
    } else {
        $error_msg = urlencode("Prepare failed: " . $conn->error);
        header("Location: ../forms/swing_machine_entry.php?error=" . $error_msg);
        exit();
    }
} else {
    header("Location: ../forms/swing_machine_entry.php");
    exit();
}

$conn->close();
?>

