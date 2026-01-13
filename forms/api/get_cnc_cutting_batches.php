<?php
// get_cnc_cutting_batches.php
// Returns distinct CNC cutting batches from cnc_entries for dropdown selection

session_start();
require_once '../../config/security_config.php';

// Security check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Database connection
$conn = SecurityConfig::getConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

// Get batches that have already been used in sewing machine entries
// Check which table exists (sewing_machine_entry or swing_machine_entry)
$used_batches = [];
$sewing_table_check = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
$swing_table_check = $conn->query("SHOW TABLES LIKE 'swing_machine_entry'");

if ($sewing_table_check && $sewing_table_check->num_rows > 0) {
    // Check if cnc_cutting_batch column exists
    $col_check = $conn->query("SHOW COLUMNS FROM sewing_machine_entry LIKE 'cnc_cutting_batch'");
    if ($col_check && $col_check->num_rows > 0) {
        $used_query = "SELECT DISTINCT cnc_cutting_batch 
                       FROM sewing_machine_entry 
                       WHERE cnc_cutting_batch IS NOT NULL 
                       AND cnc_cutting_batch != ''";
        $used_result = $conn->query($used_query);
        if ($used_result) {
            while ($row = $used_result->fetch_assoc()) {
                if (!in_array($row['cnc_cutting_batch'], $used_batches)) {
                    $used_batches[] = $row['cnc_cutting_batch'];
                }
            }
        }
    }
}

if ($swing_table_check && $swing_table_check->num_rows > 0) {
    // Check if cnc_cutting_batch column exists
    $col_check = $conn->query("SHOW COLUMNS FROM swing_machine_entry LIKE 'cnc_cutting_batch'");
    if ($col_check && $col_check->num_rows > 0) {
        $used_query = "SELECT DISTINCT cnc_cutting_batch 
                       FROM swing_machine_entry 
                       WHERE cnc_cutting_batch IS NOT NULL 
                       AND cnc_cutting_batch != ''";
        $used_result = $conn->query($used_query);
        if ($used_result) {
            while ($row = $used_result->fetch_assoc()) {
                if (!in_array($row['cnc_cutting_batch'], $used_batches)) {
                    $used_batches[] = $row['cnc_cutting_batch'];
                }
            }
        }
    }
}

// Fetch distinct CNC cutting batches from cnc_entries with reference numbers
// Exclude batches that have already been used in sewing machine entries
// Note: reference_number can be comma-separated within a single entry
$batches = [];
$query = "SELECT cnc_cutting_batch, 
          COUNT(*) as entry_count,
          MIN(date_time) as first_entry_date,
          MAX(date_time) as last_entry_date,
          GROUP_CONCAT(DISTINCT reference_number SEPARATOR ',') as references_raw
          FROM cnc_entries 
          WHERE cnc_cutting_batch IS NOT NULL 
          AND cnc_cutting_batch != '' 
          AND reference_number IS NOT NULL
          AND reference_number != ''";
          
// Add WHERE clause to exclude used batches if any exist
if (!empty($used_batches)) {
    $placeholders = str_repeat('?,', count($used_batches) - 1) . '?';
    $query .= " AND cnc_cutting_batch NOT IN ($placeholders)";
}

$query .= " GROUP BY cnc_cutting_batch 
           ORDER BY last_entry_date DESC, cnc_cutting_batch DESC
           LIMIT 200";

// Prepare and execute query with used batches exclusion
if (!empty($used_batches)) {
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $types = str_repeat('s', count($used_batches));
        $stmt->bind_param($types, ...$used_batches);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = false;
    }
} else {
    $result = $conn->query($query);
}

if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Clean up references - handle both comma-separated within entries and across entries
        $references_raw = $row['references_raw'] ?? '';
        $reference_list = [];
        if ($references_raw) {
            // Split by comma (handles both cases: comma-separated within entry and GROUP_CONCAT separator)
            $all_refs = explode(',', $references_raw);
            foreach ($all_refs as $ref) {
                $ref = trim($ref);
                if (!empty($ref) && !in_array($ref, $reference_list)) {
                    $reference_list[] = $ref;
                }
            }
            // Sort references for consistent display
            sort($reference_list);
        }
        
        $batches[] = [
            'batch' => $row['cnc_cutting_batch'],
            'entry_count' => (int)$row['entry_count'],
            'first_entry_date' => $row['first_entry_date'],
            'last_entry_date' => $row['last_entry_date'],
            'references' => $reference_list
        ];
    }
    // Close prepared statement if it was used
    if (!empty($used_batches) && isset($stmt)) {
        $stmt->close();
    }
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'batches' => $batches
]);

$conn->close();
?>
