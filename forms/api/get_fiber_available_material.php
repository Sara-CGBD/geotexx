<?php
session_start();
require_once '../../config/security_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$material_type = $_GET['material_type'] ?? '';
$manufacturer_name = $_GET['manufacturer_name'] ?? '';

// Both material_type and manufacturer_name are required for matching
if (empty($material_type)) {
    echo json_encode(['success' => false, 'error' => 'Material type is required']);
    exit;
}

if (empty($manufacturer_name)) {
    echo json_encode(['success' => false, 'error' => 'Manufacturer name is required']);
    exit;
}

try {
    $conn = SecurityConfig::getConnection();
    
    // Get available amount from fiber_entries
    // Use total_amount to include recycled material (amount_kg + recycled_amount)
    // Check which column exists - prioritize total_amount over amount_kg
    $colCheck = $conn->query("SHOW COLUMNS FROM fiber_entries LIKE 'total_amount'");
    $hasTotalAmount = $colCheck && $colCheck->num_rows > 0;
    
    if ($hasTotalAmount) {
        $amountColumn = 'total_amount';
    } else {
        $colCheck = $conn->query("SHOW COLUMNS FROM fiber_entries LIKE 'amount_kg'");
        $hasAmountKg = $colCheck && $colCheck->num_rows > 0;
        
        if ($hasAmountKg) {
            $amountColumn = 'amount_kg';
        } else {
            $colCheck = $conn->query("SHOW COLUMNS FROM fiber_entries LIKE 'amount'");
            $hasAmount = $colCheck && $colCheck->num_rows > 0;
            
            if (!$hasAmount) {
                echo json_encode(['success' => false, 'error' => 'No amount column found in fiber_entries']);
                exit;
            } else {
                $amountColumn = 'amount';
            }
        }
    }
    
    // Check if is_deleted column exists
    $colCheck = $conn->query("SHOW COLUMNS FROM fiber_entries LIKE 'is_deleted'");
    $hasIsDeleted = $colCheck && $colCheck->num_rows > 0;
    
    // Check if manufacturer_name column exists
    $colCheck = $conn->query("SHOW COLUMNS FROM fiber_entries LIKE 'manufacturer_name'");
    $hasManufacturer = $colCheck && $colCheck->num_rows > 0;
    
    // Check if material_type column exists
    $colCheck = $conn->query("SHOW COLUMNS FROM fiber_entries LIKE 'material_type'");
    $hasMaterialType = $colCheck && $colCheck->num_rows > 0;
    
    // Calculate remaining weight per entry, then sum only positive remainings
    // This ensures that fully used entries don't affect new entries
    // Match by manufacturer_name AND material_type
    $whereConditions = [];
    $bindParams = [];
    $bindTypes = '';
    
    // Require exact match for manufacturer_name (use fe. prefix for fiber_entries table)
    if ($hasManufacturer) {
        $whereConditions[] = "LOWER(TRIM(fe.manufacturer_name)) = LOWER(TRIM(?))";
        $bindParams[] = $manufacturer_name;
        $bindTypes .= 's';
    }
    
    // Match material_type: exact match OR NULL/empty (use fe. prefix for fiber_entries table)
    if ($hasMaterialType) {
        $whereConditions[] = "(fe.material_type IS NULL OR fe.material_type = '' OR LOWER(TRIM(fe.material_type)) = LOWER(TRIM(?)))";
        $bindParams[] = $material_type;
        $bindTypes .= 's';
    }
    
    // Build query to get individual received entries with their amounts
    $receivedEntriesQuery = "SELECT id, $amountColumn as amount, date_time, created_at FROM fiber_entries";
    
    if (!empty($whereConditions)) {
        $receivedEntriesQuery .= " WHERE " . implode(" AND ", $whereConditions);
    }
    
    // Add is_deleted filter if column exists (use fe. prefix for fiber_entries table)
    if ($hasIsDeleted) {
        if (stripos($receivedEntriesQuery, 'WHERE') !== false) {
            $receivedEntriesQuery .= " AND (fe.is_deleted = 0 OR fe.is_deleted IS NULL)";
        } else {
            $receivedEntriesQuery .= " WHERE (fe.is_deleted = 0 OR fe.is_deleted IS NULL)";
        }
    }
    
    $receivedEntriesQuery .= " ORDER BY created_at ASC, date_time ASC";
    
    // Build query for used amount from fiber_to_roll_entry
    $colCheck = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'material_type'");
    $hasFtrMaterialType = $colCheck && $colCheck->num_rows > 0;
    
    $colCheck = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'manufacturer_name'");
    $hasFtrManufacturer = $colCheck && $colCheck->num_rows > 0;
    
    $colCheck = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'total_weight'");
    $hasFtrTotalWeight = $colCheck && $colCheck->num_rows > 0;
    
    // Check if fiber_entry_id column exists in fiber_to_roll_entry
    $colCheck = $conn->query("SHOW COLUMNS FROM fiber_to_roll_entry LIKE 'fiber_entry_id'");
    $hasFiberEntryId = $colCheck && $colCheck->num_rows > 0;
    
    // If column doesn't exist, add it
    if (!$hasFiberEntryId) {
        $conn->query("ALTER TABLE fiber_to_roll_entry ADD COLUMN fiber_entry_id INT NULL AFTER manufacturer_name");
        $hasFiberEntryId = true;
    }
    
    // Build used query - match exactly by manufacturer_name AND material_type
    // Only count entries that have manufacturer_name set (exclude NULL/empty)
    $usedWhereConditions = [];
    $usedBindParams = [];
    $usedBindTypes = '';
    
    // Require exact match for manufacturer_name AND exclude NULL/empty
    if ($hasFtrManufacturer) {
        $usedWhereConditions[] = "manufacturer_name IS NOT NULL AND manufacturer_name != '' AND LOWER(TRIM(manufacturer_name)) = LOWER(TRIM(?))";
        $usedBindParams[] = $manufacturer_name;
        $usedBindTypes .= 's';
    }
    
    // Require exact match for material_type AND exclude NULL/empty
    if ($hasFtrMaterialType) {
        $usedWhereConditions[] = "material_type IS NOT NULL AND material_type != '' AND LOWER(TRIM(material_type)) = LOWER(TRIM(?))";
        $usedBindParams[] = $material_type;
        $usedBindTypes .= 's';
    }
    
    $usedQuery = "SELECT COALESCE(SUM(total_weight), 0) as used_quantity FROM fiber_to_roll_entry";
    
    if (!empty($usedWhereConditions)) {
        $usedQuery .= " WHERE " . implode(" AND ", $usedWhereConditions);
    }
    
    // Get individual received entries with their used amounts (direct link via fiber_entry_id)
    // Column is ensured to exist above, so we can always use the direct link
    $receivedEntriesQuery = "SELECT fe.id, fe.$amountColumn as amount, fe.date_time, fe.created_at,
                             COALESCE(SUM(ftr.total_weight), 0) as used_amount
                             FROM fiber_entries fe
                             LEFT JOIN fiber_to_roll_entry ftr ON ftr.fiber_entry_id = fe.id";
    
    if (!empty($whereConditions)) {
        $receivedEntriesQuery .= " WHERE " . implode(" AND ", $whereConditions);
    }
    
    // Add is_deleted filter if column exists
    if ($hasIsDeleted) {
        if (stripos($receivedEntriesQuery, 'WHERE') !== false) {
            $receivedEntriesQuery .= " AND (fe.is_deleted = 0 OR fe.is_deleted IS NULL)";
        } else {
            $receivedEntriesQuery .= " WHERE (fe.is_deleted = 0 OR fe.is_deleted IS NULL)";
        }
    }
    
    $receivedEntriesQuery .= " GROUP BY fe.id, fe.$amountColumn, fe.date_time, fe.created_at
                               ORDER BY fe.created_at ASC, fe.date_time ASC";
    
    // Get individual received entries with their used amounts
    $stmt = $conn->prepare($receivedEntriesQuery);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }
    
    // Bind parameters dynamically
    if (!empty($bindParams)) {
        $stmt->bind_param($bindTypes, ...$bindParams);
    }
    $stmt->execute();
    $receivedResult = $stmt->get_result();
    $receivedEntries = [];
    $total_received = 0;
    $total_used = 0;
    
    while ($row = $receivedResult->fetch_assoc()) {
        $received_amount = (float)($row['amount'] ?? 0);
        $used_amount = (float)($row['used_amount'] ?? 0);
        $remaining = max(0, $received_amount - $used_amount);
        
        $receivedEntries[] = [
            'id' => $row['id'],
            'amount' => $received_amount,
            'used' => $used_amount,
            'remaining' => $remaining,
            'date_time' => $row['date_time'],
            'created_at' => $row['created_at']
        ];
        
        $total_received += $received_amount;
        $total_used += $used_amount;
    }
    $stmt->close();
    
    // Sum remaining from all entries (only positive remainings)
    $remaining_quantity = array_sum(array_map(function($entry) {
        return $entry['remaining'];
    }, $receivedEntries));
    
    // Find the fiber_entry_id to use (FIFO - oldest entry with remaining amount)
    $fiber_entry_id_to_use = null;
    foreach ($receivedEntries as $entry) {
        if ($entry['remaining'] > 0) {
            $fiber_entry_id_to_use = $entry['id'];
            break; // Use the oldest entry with remaining amount (FIFO)
        }
    }
    
    // Also calculate total received and used for display
    $received_quantity = $total_received;
    $used_quantity = $total_used;
    
    $conn->close();
    
    // If no amount is available, indicate it clearly
    $message = '';
    if ($remaining_quantity <= 0) {
        if ($received_quantity <= 0) {
            $message = 'No amount available - No entries found matching manufacturer and material type';
        } else {
            $message = 'No amount available - All material has been used';
        }
    }
    
    echo json_encode([
        'success' => true,
        'available_quantity' => number_format($remaining_quantity, 2, '.', ''),
        'received_quantity' => number_format($received_quantity, 2, '.', ''),
        'used_quantity' => number_format($used_quantity, 2, '.', ''),
        'remaining_quantity' => number_format($remaining_quantity, 2, '.', ''),
        'fiber_entry_id' => $fiber_entry_id_to_use,
        'message' => $message,
        'debug' => [
            'manufacturer_name' => $manufacturer_name,
            'material_type' => $material_type,
            'hasManufacturer' => $hasManufacturer,
            'hasMaterialType' => $hasMaterialType
        ]
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

