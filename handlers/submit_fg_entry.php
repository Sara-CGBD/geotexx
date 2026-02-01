<?php
// submit_fg_entry.php

session_start();
require_once '../config/security_config.php';

// Set timezone to Bangladesh
date_default_timezone_set('Asia/Dhaka');

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

// Connect to database
$conn = SecurityConfig::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get user role for validation
        $userRole = strtolower(trim($_SESSION['role'] ?? ''));
        
        // Get form data
        $dateTime          = $_POST['date_time'] ?? date('Y-m-d H:i:s');
        $shift             = trim($_POST['shift'] ?? '');
        // Ensure shift is always set for DB: Day 8:00–19:59, Night otherwise
        if ($shift === '' || (strtolower($shift) !== 'day' && strtolower($shift) !== 'night')) {
            $h = (int)date('H');
            $shift = ($h >= 8 && $h <= 19) ? 'Day' : 'Night';
        }
        $productType       = strtolower(trim($_POST['product_type'] ?? ''));
        $rollEntryType     = $_POST['roll_entry_type'] ?? '';
        $referenceNumber   = $_POST['reference_number'] ?? '';
        // Get shift_in_charge value
        $shiftInCharge = trim($_POST['shift_in_charge'] ?? $_POST['qc_inspector'] ?? '');
        
        // Role-based validation: Check if user can submit this product type
        if ($productType === 'roll') {
            // Only prod_test, production_user, admin, management, agm ops can submit rolls
            if (!in_array($userRole, ['prod_test', 'production_user', 'admin', 'management', 'agm ops'])) {
                header("Location: ../forms/fg_entry.php?error=" . urlencode('Access Denied: Your role does not have permission to submit Roll entries.'));
                exit();
            }
        } elseif ($productType === 'bag') {
            // Only sewing_test, admin, management, agm ops can submit bags
            if (!in_array($userRole, ['sewing_test', 'admin', 'management', 'agm ops'])) {
                header("Location: ../forms/fg_entry.php?error=" . urlencode('Access Denied: Your role does not have permission to submit Bag entries.'));
                exit();
            }
        }
        
        // Aggressively check and prevent '0' from being saved
        // Check for empty, '0', 0, or any variation
        if (empty($shiftInCharge) || 
            $shiftInCharge === '0' || 
            $shiftInCharge === 0 || 
            $shiftInCharge === '0.0' ||
            trim($shiftInCharge) === '' ||
            strtolower(trim($shiftInCharge)) === '0') {
            
            // Get session values
            $sessionName = trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');
            
            // If session name is also empty or '0', use 'User' as default
            if (empty($sessionName) || $sessionName === '0' || $sessionName === 0) {
                $shiftInCharge = 'User';
            } else {
                $shiftInCharge = $sessionName;
            }
        }
        
        // Final safety check - absolutely prevent '0' from being saved
        if ($shiftInCharge === '0' || $shiftInCharge === 0 || trim($shiftInCharge) === '0') {
            $shiftInCharge = 'User';
        }
        $projectId         = trim($_POST['project_id'] ?? '');
        // CNC batch: for bags always use dropdown (bag_cnc_cutting_batch) so every selected batch is saved correctly
        $cncFromSelect     = trim((string)($_POST['bag_cnc_cutting_batch'] ?? ''));
        $cncFromHidden     = trim((string)($_POST['cnc_cutting_batch'] ?? ''));
        if ($productType === 'bag') {
            $cncRaw = ($cncFromSelect !== '' && $cncFromSelect !== '0') ? $cncFromSelect : $cncFromHidden;
        } else {
            $cncRaw = $cncFromHidden !== '' ? $cncFromHidden : $cncFromSelect;
        }
        // Extract batch identifier (before "||" if present; options are "BATCH" or "BATCH||bagSize")
        $cncCuttingBatch   = (strpos($cncRaw, '||') !== false) ? trim(explode('||', $cncRaw)[0]) : $cncRaw;
        if ($cncCuttingBatch === '0' || $cncCuttingBatch === '') {
            $cncCuttingBatch = ($productType === 'bag' && $cncFromSelect !== '' && $cncFromSelect !== '0')
                ? (strpos($cncFromSelect, '||') !== false ? trim(explode('||', $cncFromSelect)[0]) : $cncFromSelect)
                : '';
        }
        $bagSize           = trim($_POST['bag_size'] ?? '');
        $rollSize          = $_POST['roll_size'] ?? '';
        $recommendedWeight = $_POST['recommended_weight'] ?? '';
        // Handle weight fields - get from appropriate field based on product type
        // For rolls: use total_weight field (stored as actual_weight in DB)
        // For bags: use actual_weight_bag field (stored as actual_weight in DB)
        $deliveredQuantity = trim($_POST['delivered_quantity'] ?? '0');
        if ($productType === 'roll') {
            $actualWeight = $deliveredQuantity;
        } else {
            $actualWeight = trim($_POST['actual_weight_bag'] ?? '');
        }
        $totalArea         = trim($_POST['total_area'] ?? '');
        $tripNumber        = ($productType === 'roll') ? trim($_POST['trip_number'] ?? '') : null;
        $qualityChecked    = $_POST['quality_checked'] ?? '';
        $passedQty         = $_POST['passed_qty'] ?? '';
        $rejectedQty       = $_POST['rejected_qty'] ?? '';
        $packagingType     = $_POST['packaging_type'] ?? null;
        
        // For rolls, set default values for bag-specific fields
        if ($productType === 'roll') {
            if (!empty($rollSize)) {
                $bagSize = $rollSize; // Use roll size as bag_size for rolls
            }
            // Set recommended weight to empty for rolls (not applicable)
            if (empty($recommendedWeight) || $recommendedWeight === '') {
                $recommendedWeight = null;
            }
            // Set quality fields to 0 for rolls (not applicable)
            if (empty($qualityChecked) || $qualityChecked === '') {
                $qualityChecked = 0;
            }
            if (empty($passedQty) || $passedQty === '') {
                $passedQty = 0;
            }
            if (empty($rejectedQty) || $rejectedQty === '') {
                $rejectedQty = 0;
            }
        }

        // Batch number handling
        if (isset($_POST['batch_number']) && $_POST['batch_number'] !== '') {
            $batchNumber = $_POST['batch_number'];
        } else {
            $batchNumber = 'AUTO-' . time(); // fallback if form input is empty
        }

        // Debug logs (check in PHP error log)
        error_log("FG Entry Debug - POST batch_number: " . ($_POST['batch_number'] ?? 'NOT SET'));
        error_log("FG Entry Debug - Final batch_number used: " . $batchNumber);

        // For bags: resolve project_id from branding_entries if empty (projects load async)
        if ($productType === 'bag' && (empty($projectId) || $projectId === '0') && !empty($cncCuttingBatch)) {
            $beCheck = $conn->query("SHOW COLUMNS FROM branding_entries LIKE 'project_id'");
            if ($beCheck && $beCheck->num_rows > 0) {
                $batchEsc = $conn->real_escape_string($cncCuttingBatch);
                $bagSizeEsc = $conn->real_escape_string(trim($bagSize));
                // Match by cnc_cutting_batch; prefer exact bag_size match, else any
                $sql = "SELECT project_id FROM branding_entries 
                        WHERE TRIM(cnc_cutting_batch) = '$batchEsc' 
                        AND project_id IS NOT NULL AND project_id > 0 
                        ORDER BY (TRIM(COALESCE(bag_size,'')) = '$bagSizeEsc') DESC
                        LIMIT 1";
                $res = $conn->query($sql);
                if ($res) {
                    $row = $res->fetch_assoc();
                    if (is_array($row) && !empty($row['project_id'])) {
                        $projectId = (string)(int)$row['project_id'];
                    }
                }
            }
        }
        // Fallback to default project if still empty
        if (empty($projectId) || $projectId === '0') {
            require_once '../config/project_helper.php';
            $defProj = getDefaultProject($conn);
            if ($defProj && !empty($defProj['id'])) {
                $projectId = (string)(int)$defProj['id'];
            }
        }

        // Validate project_id after resolution - must have a value
        if (empty($projectId) || $projectId === '0') {
            header("Location: ../forms/fg_entry.php?error=" . urlencode("Missing required field: project_id. Please select a project or ensure the form loads projects."));
            exit();
        }

        // For bags: resolve bag_size from branding_entries if empty but cnc_cutting_batch is set
        if ($productType === 'bag' && (empty($bagSize) || trim($bagSize) === '') && !empty($cncCuttingBatch)) {
            $beCols = [];
            if ($r = $conn->query("SHOW COLUMNS FROM branding_entries")) {
                while ($c = $r->fetch_assoc()) $beCols[$c['Field']] = true;
            }
            if (!empty($beCols['bag_size'])) {
                $batchEsc = $conn->real_escape_string($cncCuttingBatch);
                $res = $conn->query("SELECT TRIM(COALESCE(bag_size,'')) as bag_size FROM branding_entries 
                    WHERE TRIM(cnc_cutting_batch) = '$batchEsc' AND bag_size IS NOT NULL AND TRIM(bag_size) != '' 
                    LIMIT 1");
                if ($res) {
                    $row = $res->fetch_assoc();
                    if (is_array($row) && !empty(trim($row['bag_size'] ?? ''))) {
                        $bagSize = trim($row['bag_size']);
                        $_POST['bag_size'] = $bagSize;
                    }
                }
            }
        }

        // Required fields validation (allow 0 values)
        $required = [
            'fg_id', 'product_type', 'shift_in_charge'
        ];
        
        // Add product-specific required fields
        if ($productType === 'bag') {
            // Reference number is optional for bags (can be auto-filled from CNC batch)
            // CNC cutting batch is the primary identifier for bags
            $required[] = 'bag_size';
            $required[] = 'recommended_weight';
            $required[] = 'actual_weight_bag'; // Use the bag-specific field name
            $required[] = 'quality_checked';
            $required[] = 'passed_qty';
            $required[] = 'rejected_qty';
        } elseif ($productType === 'roll') {
            // For rolls, reference_number is required
            $required[] = 'reference_number';
            // actual_weight or total_area was validated previously (now optional)
        }
        
        // Also accept old field name for backward compatibility
        if (empty($_POST['shift_in_charge']) && !empty($_POST['qc_inspector'])) {
            $shiftInCharge = $_POST['qc_inspector'];
        }

        foreach ($required as $field) {
            // Special handling for shift_in_charge - allow session fallback
            if ($field === 'shift_in_charge') {
                $fieldValue = trim($_POST['shift_in_charge'] ?? $_POST['qc_inspector'] ?? '');
                if (empty($fieldValue)) {
                    $fieldValue = $_SESSION['full_name'] ?? $_SESSION['username'] ?? '';
                }
                if (empty($fieldValue)) {
                    $errorMsg = "Missing required field: " . $field;
                    header("Location: ../forms/fg_entry.php?error=" . urlencode($errorMsg));
                    exit();
                }
            } elseif (!isset($_POST[$field]) || $_POST[$field] === '') {
                $errorMsg = "Missing required field: " . $field;
                header("Location: ../forms/fg_entry.php?error=" . urlencode($errorMsg));
                exit();
            }
        }
        
        // Roll validation: roll_size is optional (form only requires reference_number)
        if ($productType === 'roll') {
            $rollSize = $_POST['roll_size'] ?? $_POST['bag_size'] ?? '';
            if (!empty($rollSize)) {
                $bagSize = $rollSize; // Use roll size as bag_size for rolls when provided
            }
            // Allow empty roll_size - reference_number is the required field for rolls
        }

        // Get FG ID from form
        $fgId = $_POST['fg_id'] ?? '';

        // Validate FG ID format (must match current shift date)
        $current_hour = (int)date('H');
        $shift_date = ($current_hour < 8) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
        $expected_date = date('Ymd', strtotime($shift_date));

        if (!preg_match('/^FG-' . $expected_date . '-\d{3}$/', $fgId)) {
            header("Location: ../forms/fg_entry.php?error=invalid_fg_id");
            exit();
        }

        // Ensure table exists
        $createTable = "
            CREATE TABLE IF NOT EXISTS fg_entry (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fg_id VARCHAR(50) UNIQUE,
                date_time DATETIME,
                shift VARCHAR(20),
                product_type VARCHAR(20),
                roll_entry_type VARCHAR(20),
                reference_number VARCHAR(100),
                cnc_cutting_batch VARCHAR(100),
                shift_in_charge VARCHAR(100),
                project_id INT,
                bag_size VARCHAR(100),
                recommended_weight DECIMAL(10,2) NULL,
                actual_weight DECIMAL(10,2),
                total_area DECIMAL(10,2) NULL,
                measurement_type VARCHAR(20) NULL,
                quality_checked INT,
                passed_qty INT,
                rejected_qty INT,
                packaging_type VARCHAR(100) NULL,
                batch_number VARCHAR(100) DEFAULT '',
                delivered_quantity DECIMAL(10,2) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (project_id) REFERENCES projects(id)
            )
        ";
        $conn->query($createTable);
        
        // Add missing columns if table already exists (proper MySQL syntax)
        $fgColsCheck = $conn->query("SHOW COLUMNS FROM fg_entry");
        $existingFgCols = [];
        if ($fgColsCheck) {
            while ($row = $fgColsCheck->fetch_assoc()) {
                $existingFgCols[] = $row['Field'];
            }
        }
        // Ensure critical columns (including shift so it is always saved)
        if (!in_array('shift', $existingFgCols)) {
            $conn->query("ALTER TABLE fg_entry ADD COLUMN shift VARCHAR(20) DEFAULT NULL AFTER date_time");
            $existingFgCols[] = 'shift';
        }
        $ensureCols = [
            'product_type' => "ALTER TABLE fg_entry ADD COLUMN product_type VARCHAR(20) AFTER shift",
            'roll_entry_type' => "ALTER TABLE fg_entry ADD COLUMN roll_entry_type VARCHAR(20) AFTER product_type",
            'reference_number' => "ALTER TABLE fg_entry ADD COLUMN reference_number VARCHAR(100) AFTER roll_entry_type",
            'cnc_cutting_batch' => "ALTER TABLE fg_entry ADD COLUMN cnc_cutting_batch VARCHAR(100) AFTER reference_number",
        ];
        foreach ($ensureCols as $col => $sql) {
            if (!in_array($col, $existingFgCols)) {
                $conn->query($sql);
                $existingFgCols[] = $col;
            }
        }

        if (!in_array('delivered_quantity', $existingFgCols)) {
            $conn->query("ALTER TABLE fg_entry ADD COLUMN delivered_quantity DECIMAL(10,2) DEFAULT 0");
        }
        if (!in_array('total_area', $existingFgCols)) {
            $conn->query("ALTER TABLE fg_entry ADD COLUMN total_area DECIMAL(10,2) NULL AFTER actual_weight");
        }
        if (!in_array('measurement_type', $existingFgCols)) {
            $conn->query("ALTER TABLE fg_entry ADD COLUMN measurement_type VARCHAR(20) NULL AFTER actual_weight");
        }
        if (!in_array('trip_number', $existingFgCols)) {
            $conn->query("ALTER TABLE fg_entry ADD COLUMN trip_number INT NULL AFTER reference_number");
            $existingFgCols[] = 'trip_number';
        }
        
        // Allow NULL for recommended_weight (not applicable for rolls)
        $conn->query("ALTER TABLE fg_entry MODIFY COLUMN recommended_weight DECIMAL(10,2) NULL");
        
        // Allow NULL for packaging_type (field removed from form)
        $conn->query("ALTER TABLE fg_entry MODIFY COLUMN packaging_type VARCHAR(100) NULL");
        
        // Rename qc_inspector column to shift_in_charge if it exists
        if (in_array('qc_inspector', $existingFgCols) && !in_array('shift_in_charge', $existingFgCols)) {
            $conn->query("ALTER TABLE fg_entry CHANGE COLUMN qc_inspector shift_in_charge VARCHAR(100) DEFAULT 'User'");
        }
        // If shift_in_charge doesn't exist, add it with default
        if (!in_array('shift_in_charge', $existingFgCols) && !in_array('qc_inspector', $existingFgCols)) {
            $conn->query("ALTER TABLE fg_entry ADD COLUMN shift_in_charge VARCHAR(100) DEFAULT 'User' AFTER cnc_cutting_batch");
        }
        // Update existing column to have default value if it doesn't
        if (in_array('shift_in_charge', $existingFgCols)) {
            $conn->query("ALTER TABLE fg_entry MODIFY COLUMN shift_in_charge VARCHAR(100) DEFAULT 'User'");
        }

        // Insert into fg_entry
        $stmt = $conn->prepare("
            INSERT INTO fg_entry (
                fg_id, date_time, shift, product_type, roll_entry_type, reference_number, cnc_cutting_batch, 
                shift_in_charge, project_id, bag_size, recommended_weight, actual_weight, total_area, measurement_type,
                quality_checked, passed_qty, rejected_qty, packaging_type, delivered_quantity
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        // CRITICAL: Final check before bind_param - absolutely prevent '0' from being saved
        // Check every possible variation of '0'
        if (empty($shiftInCharge) || 
            $shiftInCharge === '0' || 
            $shiftInCharge === 0 || 
            trim($shiftInCharge) === '' ||
            trim($shiftInCharge) === '0' ||
            strtolower(trim($shiftInCharge)) === '0') {
            
            $sessionName = trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');
            if (!empty($sessionName) && $sessionName !== '0' && $sessionName !== 0) {
                $shiftInCharge = $sessionName;
            } else {
                $shiftInCharge = 'User'; // Absolute fallback
            }
        }
        
        // One more check - if it's still '0' after all checks, force it to 'User'
        if ($shiftInCharge === '0' || $shiftInCharge === 0 || (string)$shiftInCharge === '0') {
            $shiftInCharge = 'User';
        }
        
        // Final check just before bind_param
        if ($shiftInCharge === "0" || $shiftInCharge === 0 || trim($shiftInCharge) === "0") {
            $shiftInCharge = $_SESSION['full_name'] ?? $_SESSION['username'] ?? "User";
        }
        
        if (empty($shiftInCharge)) {
            $shiftInCharge = "User";
        }
        
        // Convert empty strings to NULL for optional fields
        $recommendedWeightValue = (!empty($recommendedWeight) && $recommendedWeight !== '' && $recommendedWeight !== '0') ? (float)$recommendedWeight : null;
        $totalAreaValue = (!empty($totalArea) && $totalArea !== '') ? (float)$totalArea : null;
        $actualWeightValue = (!empty($actualWeight) && $actualWeight !== '') ? (float)$actualWeight : null;
        if ($actualWeightValue === null) {
            $actualWeightValue = 0;
        }
        $deliveredQuantityValue = (!empty($deliveredQuantity) && $deliveredQuantity !== '') ? (float)$deliveredQuantity : 0;
        $measurementTypeValue = null;
        
        // For bags, reference_number is optional - set to empty string if not provided
        if ($productType === 'bag' && empty($referenceNumber)) {
            $referenceNumber = '';
        }
        
        // Bind params: s = string, i = integer, d = decimal
        $stmt->bind_param(
            "ssssssssisdddsiiiss",
            $fgId,
            $dateTime,
            $shift,
            $productType,
            $rollEntryType,
            $referenceNumber,
            $cncCuttingBatch,
            $shiftInCharge,
            $projectId,
            $bagSize,
            $recommendedWeightValue,
            $actualWeightValue,
            $totalAreaValue,
            $measurementTypeValue,
            $qualityChecked,
            $passedQty,
            $rejectedQty,
            $packagingType,
            $batchNumber
        );

        if ($stmt->execute()) {
            $updateStmt = $conn->prepare("UPDATE fg_entry SET delivered_quantity = ? WHERE fg_id = ?");
            if ($updateStmt) {
                $updateStmt->bind_param("ds", $deliveredQuantityValue, $fgId);
                $updateStmt->execute();
                $updateStmt->close();
            }
            // If it's a bundle, mark all individual rolls as used in fg_entry
            if ($productType === 'roll' && $rollEntryType === 'bundle' && !empty($_POST['bundle_roll_list'])) {
                $bundleRollList = $_POST['bundle_roll_list'];
                $individualRolls = explode(', ', $bundleRollList);
                
                foreach ($individualRolls as $rollRef) {
                    $rollRef = trim($rollRef);
                    if (!empty($rollRef)) {
                        // Insert a marker entry for each roll in the bundle to prevent reuse
                        $packagingTypeValue = $packagingType ? "'{$packagingType}'" : "NULL";
                        $conn->query("INSERT IGNORE INTO fg_entry 
                            (fg_id, date_time, shift, product_type, roll_entry_type, reference_number, shift_in_charge, project_id, bag_size, 
                             recommended_weight, actual_weight, quality_checked, passed_qty, rejected_qty, packaging_type, batch_number)
                            VALUES 
                            ('{$fgId}-{$rollRef}', '{$dateTime}', '{$shift}', 'roll', 'individual', '{$rollRef}', '{$shiftInCharge}', {$projectId}, '{$bagSize}', 
                             0, 0, 0, 0, 0, {$packagingTypeValue}, 'BUNDLE-{$batchNumber}')
                        ");
                    }
                }
            }
            
            // Also insert into legacy fg table for delivery tracking
            $productName = "Finished Product - " . $bagSize; // Generate product name from bag size
            $receivedQty = $actualWeight; // Use actual weight as received quantity
            
            $fgStmt = $conn->prepare("
                INSERT INTO fg (
                    product_name, prod_id, project_id, received_qty, delivery_qty, 
                    client, batch_number, is_deleted, who_did
                ) VALUES (?, ?, ?, ?, 0, (SELECT project_name FROM projects WHERE id = ?), ?, 0, ?)
            ");
            
            $whoDidUser = $_SESSION['username'] ?? 'System';
            
            $fgStmt->bind_param(
                "siidiss",
                $productName,      // s
                $projectId,        // i - for backward compatibility (prod_id)
                $projectId,        // i - new project_id field
                $receivedQty,      // d
                $projectId,        // i - for subquery to get client name
                $batchNumber,      // s
                $whoDidUser        // s
            );
            
            $fgStmt->execute();
            $fgStmt->close();
            
            $successMsg = "FG Entry saved successfully!";

            header("Location: ../forms/fg_entry.php?success=" . urlencode($successMsg) . "&fg_id=" . urlencode($fgId));
        } else {
            header("Location: ../forms/fg_entry.php?error=database_error&message=" . urlencode($stmt->error));
        }

        $stmt->close();

    } catch (Exception $e) {
        error_log("FG Entry Error: " . $e->getMessage());
        header("Location: ../forms/fg_entry.php?error=system_error&message=" . urlencode($e->getMessage()));
    }
} else {
    header("Location: ../forms/fg_entry.php");
}

$conn->close();
?>



