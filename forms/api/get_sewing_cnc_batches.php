<?php
// get_sewing_cnc_batches.php
// Returns distinct CNC cutting batches from sewing_machine_entry for branding entry dropdown

// Enable error reporting to see what's wrong
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Set JSON header first
header('Content-Type: application/json');

// Simple error handler
function sendError($message, $details = []) {
    echo json_encode([
        'success' => false,
        'error' => $message,
        'batches' => [],
        'debug_info' => $details
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    // Start session
    if (session_status() === PHP_SESSION_NONE) {
session_start();
    }
    
    // Include config
    if (!file_exists('../../config/security_config.php')) {
        sendError('Configuration file not found', ['path' => '../../config/security_config.php']);
    }
    
require_once '../../config/security_config.php';

// Security check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
        sendError('Unauthorized - Please login');
}

    // Get database connection
$conn = SecurityConfig::getConnection();
    
if (!$conn) {
        sendError('Database connection failed', ['error' => 'Connection returned null']);
    }
    
    if ($conn->connect_error) {
        sendError('Database connection error', ['error' => $conn->connect_error]);
    }

} catch (Exception $e) {
    sendError('Initialization error', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} catch (Error $e) {
    sendError('Fatal initialization error', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

// Check which sewing table exists
$sewingTable = null;
try {
    $result = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
    if ($result && $result->num_rows > 0) {
        $sewingTable = 'sewing_machine_entry';
    } else {
        $result = $conn->query("SHOW TABLES LIKE 'swing_machine_entry'");
        if ($result && $result->num_rows > 0) {
            $sewingTable = 'swing_machine_entry';
        }
    }
    
    if (!$sewingTable) {
        sendError('Sewing table not found', [
            'checked_tables' => ['sewing_machine_entry', 'swing_machine_entry']
        ]);
    }
} catch (Exception $e) {
    sendError('Error checking tables', ['message' => $e->getMessage()]);
}

// Get all columns from sewing table
$allColumns = [];
$cncBatchColumn = null;
try {
    $result = $conn->query("SHOW COLUMNS FROM `$sewingTable`");
    if (!$result) {
        sendError('Cannot read table structure', [
            'table' => $sewingTable,
            'error' => $conn->error
        ]);
    }
    
    while ($col = $result->fetch_assoc()) {
        $fieldName = $col['Field'];
        $allColumns[] = $fieldName;
        
        // Look for CNC batch column (case insensitive)
        $lowerField = strtolower($fieldName);
        if ($lowerField === 'cnc_cutting_batch' || 
            $lowerField === 'cnc_batch' ||
            $lowerField === 'cutting_batch' ||
            (strpos($lowerField, 'cnc') !== false && strpos($lowerField, 'batch') !== false)) {
            $cncBatchColumn = $fieldName;
    }
}

if (!$cncBatchColumn) {
        sendError('CNC batch column not found', [
            'table' => $sewingTable,
            'available_columns' => $allColumns,
            'looking_for' => 'cnc_cutting_batch or similar'
        ]);
    }
} catch (Exception $e) {
    sendError('Error reading columns', [
        'table' => $sewingTable,
        'message' => $e->getMessage()
    ]);
}

// Check which columns exist (case-insensitive)
$hasColumns = [
    'bag_size' => false,
    'is_deleted' => false,
    'sewing_qty' => false,
    'ncp_piece' => false,
    'date_time' => false,
    'created_at' => false,
    'reference_number' => false,
    'project_id' => false,
    'line_no' => false
];

foreach ($allColumns as $col) {
    $lowerCol = strtolower($col);
    if ($lowerCol === 'bag_size') $hasColumns['bag_size'] = true;
    if ($lowerCol === 'is_deleted') $hasColumns['is_deleted'] = true;
    if ($lowerCol === 'sewing_qty') $hasColumns['sewing_qty'] = true;
    if ($lowerCol === 'ncp_piece') $hasColumns['ncp_piece'] = true;
    if ($lowerCol === 'date_time') $hasColumns['date_time'] = true;
    if ($lowerCol === 'created_at') $hasColumns['created_at'] = true;
    if ($lowerCol === 'reference_number') $hasColumns['reference_number'] = true;
    if ($lowerCol === 'project_id') $hasColumns['project_id'] = true;
    if ($lowerCol === 'line_no') $hasColumns['line_no'] = true;
}

$dateColumn = $hasColumns['date_time'] ? 'date_time' : 'created_at';
if (!$hasColumns['date_time'] && !$hasColumns['created_at']) {
    sendError('No date column found', [
        'table' => $sewingTable,
        'available_columns' => $allColumns
    ]);
}

// Check if branding_entries table exists and its columns (is_deleted, bag_size)
$brandingTableExists = false;
$brandingHasIsDeleted = false;
$brandingHasBagSize = false;
try {
    $result = $conn->query("SHOW TABLES LIKE 'branding_entries'");
    $brandingTableExists = ($result && $result->num_rows > 0);
    
    if ($brandingTableExists) {
        $brandingCols = $conn->query("SHOW COLUMNS FROM branding_entries");
        if ($brandingCols) {
            while ($col = $brandingCols->fetch_assoc()) {
                $f = strtolower($col['Field']);
                if ($f === 'is_deleted') $brandingHasIsDeleted = true;
                if ($f === 'bag_size') $brandingHasBagSize = true;
            }
        }
    }
} catch (Exception $e) {
    error_log("Error checking branding_entries table: " . $e->getMessage());
}

// Build and execute query
$batches = [];
try {
    // Build WHERE clause
    $whereConditions = [
        "`$cncBatchColumn` IS NOT NULL",
        "`$cncBatchColumn` != ''"
    ];
    
    if ($hasColumns['is_deleted']) {
        $whereConditions[] = "(is_deleted = 0 OR is_deleted IS NULL)";
    }
    
    if ($hasColumns['bag_size']) {
        $whereConditions[] = "bag_size IS NOT NULL";
        $whereConditions[] = "bag_size != ''";
        $whereConditions[] = "TRIM(COALESCE(bag_size,'')) != ''";
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Build SELECT clause
    $selectParts = [
        "`$cncBatchColumn` as cnc_cutting_batch"
    ];
    
    if ($hasColumns['bag_size']) {
        $selectParts[] = "TRIM(COALESCE(bag_size,'')) as bag_size";
    } else {
        $selectParts[] = "'' as bag_size";
    }
    
    $selectParts[] = "DATE(MIN(`$dateColumn`)) as batch_date";
    $selectParts[] = "MIN(`$dateColumn`) as first_entry_date";
    $selectParts[] = "COUNT(*) as entry_count";
    $selectParts[] = "GROUP_CONCAT(id ORDER BY id) as sewing_entry_ids";
    
    if ($hasColumns['project_id']) {
        $selectParts[] = "project_id";
    } else {
        $selectParts[] = "NULL as project_id";
    }
    if ($hasColumns['line_no']) {
        $selectParts[] = "COALESCE(line_no, '') as line_no";
    } else {
        $selectParts[] = "'' as line_no";
    }
    
    if ($hasColumns['sewing_qty']) {
        $selectParts[] = "SUM(COALESCE(sewing_qty, 0)) as total_sewing_qty";
    } else {
        $selectParts[] = "COUNT(*) as total_sewing_qty";
    }
    
    if ($hasColumns['ncp_piece']) {
        $selectParts[] = "SUM(COALESCE(ncp_piece, 0)) as total_ncp";
    } else {
        $selectParts[] = "0 as total_ncp";
    }
    
    $selectClause = implode(', ', $selectParts);
    
    // Build GROUP BY: cnc_cutting_batch, bag_size, project_id, line_no (for grouped branding)
    $groupParts = ["`$cncBatchColumn`"];
    if ($hasColumns['bag_size']) {
        $groupParts[] = "bag_size";
    }
    if ($hasColumns['project_id']) {
        $groupParts[] = "project_id";
    }
    if ($hasColumns['line_no']) {
        $groupParts[] = "COALESCE(line_no, '')";
    } else {
        $groupParts[] = "''";
    }
    $groupBy = "GROUP BY " . implode(', ', $groupParts);
    
    // Final query: grouped by cnc_cutting_batch, bag_size, project_id, line_no; HAVING >= 1 for clarity
    $query = "SELECT $selectClause
              FROM `$sewingTable`
              WHERE $whereClause
              $groupBy
              HAVING COUNT(*) >= 1
              ORDER BY MIN(`$dateColumn`) DESC
              LIMIT 200";

    error_log("Executing query: " . $query);
    
$result = $conn->query($query);

    if (!$result) {
        sendError('Query execution failed', [
            'sql_error' => $conn->error,
            'query' => $query
        ]);
    }
    
    // Fetch results
    while ($row = $result->fetch_assoc()) {
        $batch = trim($row['cnc_cutting_batch'] ?? '');
        $bagSize = trim($row['bag_size'] ?? '');
        $totalSewingQty = (int)($row['total_sewing_qty'] ?? 0);
        $totalNcp = (int)($row['total_ncp'] ?? 0);
        
        if (empty($batch) || $totalSewingQty <= 0) {
            continue;
        }
        
        // Net from sewing (after NCP) for reference; remaining is based on sewing qty only
        $availableForBranding = max(0, $totalSewingQty - $totalNcp);
        
        // Calculate branding usage: only count rows for this exact batch + bag_size (branding_entries must have bag_size to filter)
        $brandingUsed = 0;
        if ($brandingTableExists) {
            try {
                $batchNorm = trim($batch ?? '');
                $bagSizeNorm = trim($bagSize ?? '');
                $brandingWhere = "TRIM(COALESCE(cnc_cutting_batch,'')) = ?";
                $brandingParams = [$batchNorm];
                $brandingTypes = "s";
                // Only filter by bag_size when branding_entries has the column AND this sewing group has a real bag size
                if ($brandingHasBagSize && $hasColumns['bag_size'] && $bagSizeNorm !== '') {
                    $brandingWhere .= " AND TRIM(COALESCE(bag_size,'')) = ?";
                    $brandingParams[] = $bagSizeNorm;
                    $brandingTypes .= "s";
                }
                if ($brandingHasIsDeleted) {
                    $brandingWhere .= " AND (is_deleted = 0 OR is_deleted IS NULL)";
                }
                $brandingQuery = "SELECT SUM(COALESCE(print_qty, 0) + COALESCE(ncp_piece, 0)) as total 
                                  FROM branding_entries 
                                  WHERE $brandingWhere";
                $stmt = $conn->prepare($brandingQuery);
                if ($stmt) {
                    $stmt->bind_param($brandingTypes, ...$brandingParams);
                    $stmt->execute();
                    $brandingResult = $stmt->get_result();
                    if ($brandingRow = $brandingResult->fetch_assoc()) {
                        $brandingUsed = (int)($brandingRow['total'] ?? 0);
                    }
                    $stmt->close();
                }
            } catch (Exception $e) {
                error_log("Error calculating branding for batch $batch: " . $e->getMessage());
            }
        }
        // Remaining is always calculated from sewing_machine_entry: total sewing qty (for this batch+bag_size) minus branding already used
        $remainingQty = max(0, $totalSewingQty - $brandingUsed);
        
        // Get reference numbers
        $references = [];
        if ($hasColumns['reference_number']) {
            try {
                if ($hasColumns['bag_size'] && !empty($bagSize)) {
                    $refStmt = $conn->prepare("SELECT DISTINCT reference_number 
                                               FROM `$sewingTable`
                                               WHERE `$cncBatchColumn` = ? 
                                               AND bag_size = ?
                                               AND reference_number IS NOT NULL 
                                               AND reference_number != '' 
                                               LIMIT 5");
                    $refStmt->bind_param("ss", $batch, $bagSize);
                } else {
                    $refStmt = $conn->prepare("SELECT DISTINCT reference_number 
                                               FROM `$sewingTable`
                                               WHERE `$cncBatchColumn` = ?
                             AND reference_number IS NOT NULL 
                             AND reference_number != '' 
                                               LIMIT 5");
                    $refStmt->bind_param("s", $batch);
                }
                
                if ($refStmt) {
                    $refStmt->execute();
                    $refResult = $refStmt->get_result();
                    while ($refRow = $refResult->fetch_assoc()) {
                        $ref = trim($refRow['reference_number'] ?? '');
                        if (!empty($ref)) {
                            $references[] = $ref;
                        }
                    }
                    $refStmt->close();
                }
            } catch (Exception $e) {
                error_log("Error fetching references: " . $e->getMessage());
                }
            }
            
            $entryCount = (int)($row['entry_count'] ?? 0);
            $batches[] = [
                'batch' => $batch,
                'cnc_cutting_batch' => $batch,
                'bag_size' => $bagSize,
                'batch_date' => $row['batch_date'] ?? '',
                'first_entry_date' => $row['first_entry_date'] ?? '',
                'entry_count' => $entryCount,
                'merged_count' => $entryCount,
                'total_sewing_qty' => $totalSewingQty,
                'total_ncp' => $totalNcp,
                'total_ncp_piece' => $totalNcp,
                'available_for_branding' => $availableForBranding,
                'total_branding_used' => $brandingUsed,
                'remaining_qty' => $remainingQty,
                'references' => $references,
                'sewing_entry_ids' => trim($row['sewing_entry_ids'] ?? ''),
                'project_id' => isset($row['project_id']) ? (int)$row['project_id'] : null,
                'line_no' => isset($row['line_no']) ? trim($row['line_no']) : ''
            ];
        }
    
} catch (Exception $e) {
    sendError('Error processing batches', [
        'message' => $e->getMessage(),
        'line' => $e->getLine()
    ]);
}

// Return success response
echo json_encode([
    'success' => true,
    'batches' => $batches,
    'debug_info' => [
        'table' => $sewingTable,
        'column' => $cncBatchColumn,
        'batch_count' => count($batches),
        'has_columns' => $hasColumns,
        'branding_table_exists' => $brandingTableExists,
        'branding_has_bag_size' => $brandingHasBagSize,
        'branding_has_is_deleted' => $brandingHasIsDeleted
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$conn->close();
exit;
?>
