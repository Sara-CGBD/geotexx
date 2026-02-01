<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'php_error.log');
error_reporting(E_ALL);

// Set JSON header for AJAX response
header('Content-Type: application/json');

session_start();
require_once 'security_config.php';

// Set timezone to Bangladesh
date_default_timezone_set('Asia/Dhaka');

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Get JSON data from request body
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON data']);
    exit;
}

$dateTime       = $input['dateTime'] ?? '';
$shift          = trim($input['shift'] ?? '');
$shiftIncharge  = trim($input['shiftIncharge'] ?? '');
$reference      = trim($input['reference'] ?? '');
$manufacturerName = trim($input['manufacturerName'] ?? '');
$projectId      = isset($input['project']) && $input['project'] !== '' ? (int)$input['project'] : 0;
$amount_kg      = (float)($input['amount'] ?? 0);
$recycledType   = trim($input['recycledType'] ?? 'none');
$recycledAmount = (float)($input['recycledAmount'] ?? 0);
$totalAmount    = (float)($input['totalAmount'] ?? 0);
$materialType   = trim($input['materialType'] ?? '');
$origin         = trim($input['origin'] ?? '');
$beltWeight     = (int)($input['beltWeight'] ?? 0);
$beltNumber     = trim($input['beltNumber'] ?? '');
$summary        = trim($input['summary'] ?? '');
$reporterId     = $_SESSION['user_id'] ?? 0;
$reporterName   = $_SESSION['username'] ?? 'Unknown';

// Validate required fields (entryCode is auto-generated, not required from form)
// Make project optional (default 0) so missing projectId doesn’t block submission
$required = ['dateTime','shiftIncharge','amount_kg','origin','beltWeight','beltNumber'];
foreach ($required as $field) {
    if (empty($$field)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => "Missing required field: $field"]);
        exit;
    }
}

try {
    $conn = SecurityConfig::getConnection();
    
    // Validate recycled amount doesn't exceed available
    if ($recycledType !== 'none' && $recycledAmount > 0) {
        // Map types to category matches (same logic as API)
        if ($recycledType === 'sheet_production') {
            $categoryMatches = ['Sheet Production'];
        } else {
            // For swing/sewing, match both "Sewing Production" and "Swing"
            $categoryMatches = ['Sewing Production', 'Swing'];
        }
        
        // Check if production_category column exists
        $colCheck = $conn->query("SHOW COLUMNS FROM scrap_recycle LIKE 'production_category'");
        $hasProductionCategory = $colCheck && $colCheck->num_rows > 0;
        
        $totalRecycled = 0;
        
        if ($hasProductionCategory) {
            // Use production_category column directly (same as API)
            $placeholders = implode(',', array_fill(0, count($categoryMatches), '?'));
            $recycledQuery = $conn->prepare("
                SELECT COALESCE(SUM(sr.recycled_qty), 0) as total_recycled
                FROM scrap_recycle sr
                LEFT JOIN side_cut_scrap scs ON sr.scrap_id = scs.id AND sr.scrap_type = 'side_cut'
                WHERE sr.scrap_type = 'side_cut'
                  AND (
                    sr.production_category IN ($placeholders)
                    OR (sr.production_category IS NULL AND scs.category IN ($placeholders))
                  )
            ");
            $bindParams = array_merge($categoryMatches, $categoryMatches);
            $types = str_repeat('s', count($bindParams));
            $recycledQuery->bind_param($types, ...$bindParams);
        } else {
            // Fallback: join with side_cut_scrap to get category
            $placeholders = implode(',', array_fill(0, count($categoryMatches), '?'));
            $recycledQuery = $conn->prepare("
                SELECT COALESCE(SUM(sr.recycled_qty), 0) as total_recycled
                FROM scrap_recycle sr
                INNER JOIN side_cut_scrap scs ON sr.scrap_id = scs.id
                WHERE sr.scrap_type = 'side_cut'
                  AND scs.category IN ($placeholders)
            ");
            $types = str_repeat('s', count($categoryMatches));
            $recycledQuery->bind_param($types, ...$categoryMatches);
        }
        
        $recycledQuery->execute();
        $recycledResult = $recycledQuery->get_result();
        if ($recycledResult && $row = $recycledResult->fetch_assoc()) {
            $totalRecycled = (float)($row['total_recycled'] ?? 0);
        }
        $recycledQuery->close();
        
        // Get amounts already used in fiber_entries (not deleted)
        $usedStmt = $conn->prepare("
            SELECT COALESCE(SUM(recycled_amount), 0) as total_used
            FROM fiber_entries
            WHERE is_deleted = 0
            AND recycled_type = ?
            AND recycled_amount > 0
        ");
        $usedStmt->bind_param("s", $recycledType);
        $usedStmt->execute();
        $usedResult = $usedStmt->get_result();
        $totalUsed = 0;
        if ($usedResult && $row = $usedResult->fetch_assoc()) {
            $totalUsed = (float)$row['total_used'];
        }
        $usedStmt->close();
        
        // Calculate available
        $available = max(0, $totalRecycled - $totalUsed);
        
        // Check if requested amount exceeds available
        if ($recycledAmount > $available) {
            http_response_code(400);
            echo json_encode([
                'status' => 'error', 
                'message' => "Cannot use {$recycledAmount} kg of recycled material. Only {$available} kg is available for {$recycledType}."
            ]);
            exit;
        }
    }
    
    // Generate entry code using date-based sequential format
    $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
    $hour = (int)$now->format('H');
    
    // If before 8 AM, use previous day's date
    if ($hour < 8) {
        $now->modify('-1 day');
    }
    
    $dateKey = $now->format('Ymd');
    
    // Count actual entries for this day key (real-time)
    $pattern = 'FE-' . $dateKey . '-%';
    $countStmt = $conn->prepare("SELECT COUNT(*) as entry_count FROM fiber_entries WHERE entry_code LIKE ? AND (is_deleted = 0 OR is_deleted IS NULL)");
    $countStmt->bind_param("s", $pattern);
    $countStmt->execute();
    $result = $countStmt->get_result();
    
    $counter = 1;
    if ($result && $row = $result->fetch_assoc()) {
        $counter = (int)$row['entry_count'] + 1;
    }
    $countStmt->close();
    
    $entryCode = sprintf("FE-%s-%03d", $dateKey, $counter);

    // Create fiber_entries table if it doesn't exist
    $createTable = "CREATE TABLE IF NOT EXISTS fiber_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_code VARCHAR(50),
        date_time DATETIME,
        shift VARCHAR(50) DEFAULT '',
        shift_incharge VARCHAR(100),
        reference VARCHAR(100) DEFAULT '',
        manufacturer_name VARCHAR(255) DEFAULT '',
        project_id INT DEFAULT 0,
        amount_kg DECIMAL(10,2),
        recycled_type VARCHAR(50) DEFAULT 'none',
        recycled_amount DECIMAL(10,2) DEFAULT 0,
        total_amount DECIMAL(10,2) DEFAULT 0,
        material_type VARCHAR(255) DEFAULT '',
        origin VARCHAR(255),
        belt_weight INT DEFAULT 0,
        belt_number VARCHAR(100) DEFAULT '',
        summary TEXT,
        reporter_id INT,
        reporter_name VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_deleted TINYINT(1) DEFAULT 0,
        who_did VARCHAR(100) DEFAULT '',
        deleted_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->query($createTable);
    
    // Helper to add column safely (without failing if AFTER target missing) - using prepared statements
    $ensureColumn = function($table, $column, $definition, $after = null) use ($conn) {
        // Validate column name before using
        if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return; // Invalid column name
        }
        
        // Use prepared statement for INFORMATION_SCHEMA query
        $existsStmt = $conn->prepare("
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1
        ");
        if ($existsStmt) {
            $existsStmt->bind_param("ss", $table, $column);
            $existsStmt->execute();
            $existsRes = $existsStmt->get_result();
            if ($existsRes && $existsRes->num_rows > 0) {
                $existsStmt->close();
                return;
            }
            $existsStmt->close();
        }
        
        if ($after) {
            // Validate after column name
            if (preg_match('/^[A-Za-z0-9_]+$/', $after)) {
                // Try with AFTER; if it fails, retry without AFTER
                if (!$conn->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition} AFTER {$after}")) {
                    $conn->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                }
            } else {
                $conn->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        } else {
            // Validate table and column names before using
            if (preg_match('/^[A-Za-z0-9_]+$/', $table) && preg_match('/^[A-Za-z0-9_]+$/', $column)) {
                $conn->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        }
    };

    // Ensure key columns exist before amount columns
    $ensureColumn('fiber_entries', 'project_id', "INT DEFAULT 0", 'reference');

    // Add missing columns if they don't exist (including amount_kg family)
    $ensureColumn('fiber_entries', 'shift', "VARCHAR(50) DEFAULT ''", 'date_time');
    $ensureColumn('fiber_entries', 'reference', "VARCHAR(100) DEFAULT ''", 'shift_incharge');
    $ensureColumn('fiber_entries', 'manufacturer_name', "VARCHAR(255) DEFAULT ''", 'reference');
    $ensureColumn('fiber_entries', 'amount_kg', "DECIMAL(10,2) DEFAULT 0", 'project_id');
    $ensureColumn('fiber_entries', 'recycled_type', "VARCHAR(50) DEFAULT 'none'", 'amount_kg');
    $ensureColumn('fiber_entries', 'recycled_amount', "DECIMAL(10,2) DEFAULT 0", 'recycled_type');
    $ensureColumn('fiber_entries', 'total_amount', "DECIMAL(10,2) DEFAULT 0", 'recycled_amount');
    $ensureColumn('fiber_entries', 'material_type', "VARCHAR(255) DEFAULT ''", 'amount_kg');
    $ensureColumn('fiber_entries', 'summary', "TEXT", 'belt_number');
    $ensureColumn('fiber_entries', 'recycled_amount_kg', "DECIMAL(10,2) DEFAULT 0", 'recycled_type');
    $ensureColumn('fiber_entries', 'total_amount_kg', "DECIMAL(10,2) DEFAULT 0", 'recycled_amount_kg');

    // Insert SQL (uses amount_kg / recycled_amount / total_amount; these are ensured above)
    $sql = "INSERT INTO fiber_entries 
        (entry_code, date_time, shift, shift_incharge, reference, manufacturer_name, project_id, amount_kg, recycled_type, recycled_amount, total_amount, material_type, origin, belt_weight, belt_number, summary, reporter_id, reporter_name)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    // Correct bind_param types: s=string, i=integer, d=decimal/double
    // 18 parameters total (added manufacturer_name)
    $stmt->bind_param(
        "ssssssidsddssissis",
        $entryCode,        // s - string
        $dateTime,         // s - string
        $shift,            // s - string
        $shiftIncharge,    // s - string
        $reference,        // s - string
        $manufacturerName, // s - string
        $projectId,        // i - integer
        $amount_kg,        // d - decimal
        $recycledType,     // s - string
        $recycledAmount,   // d - decimal
        $totalAmount,      // d - decimal
        $materialType,     // s - string
        $origin,           // s - string
        $beltWeight,       // i - integer
        $beltNumber,       // s - string
        $summary,          // s - string
        $reporterId,       // i - integer
        $reporterName      // s - string
    );

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    $insertId = $conn->insert_id;
    
    // Deduct the used amount from actual store_received_entries stock
    // Get material type and manufacturer from the material issue entry
    if (!empty($reference) && $amount_kg > 0) {
        // Get material type and manufacturer from the material issue entry
        $issueStmt = $conn->prepare("SELECT material_type, manufacturer_name FROM store_issue_entries WHERE issue_number = ?");
        $issueStmt->bind_param("s", $reference);
        $issueStmt->execute();
        $issueResult = $issueStmt->get_result();
        
        if ($issueResult && $issueRow = $issueResult->fetch_assoc()) {
            $material_type = $issueRow['material_type'];
            $manufacturer_name = $issueRow['manufacturer_name'];
            $issueStmt->close();
            
            // Deduct from store_received_entries using FIFO (First In First Out)
            $remaining_to_deduct = $amount_kg;
            $deductQuery = "SELECT id, entry_number, amount_kg 
                           FROM store_received_entries
                           WHERE material_type = ? 
                           AND manufacturer_name = ?
                           AND amount_kg > 0
                           AND (is_deleted = 0 OR is_deleted IS NULL)
                           ORDER BY date_time ASC, created_at ASC";
            $deductStmt = $conn->prepare($deductQuery);
            $deductStmt->bind_param("ss", $material_type, $manufacturer_name);
            $deductStmt->execute();
            $deductResult = $deductStmt->get_result();
            
            while ($remaining_to_deduct > 0 && $row = $deductResult->fetch_assoc()) {
                $entry_id = $row['id'];
                $available = floatval($row['amount_kg']);
                
                $deduct_amount = min($remaining_to_deduct, $available);
                
                $updateStmt = $conn->prepare("UPDATE store_received_entries 
                                              SET amount_kg = amount_kg - ? 
                                              WHERE id = ?");
                $updateStmt->bind_param("di", $deduct_amount, $entry_id);
                $updateStmt->execute();
                $updateStmt->close();
                
                $remaining_to_deduct -= $deduct_amount;
            }
            $deductStmt->close();
        } else {
            $issueStmt->close();
        }
    }

    echo json_encode([
        'success' => true,
        'status' => 'success',
        'message' => 'Fiber entry submitted successfully',
        'entry_id' => $insertId,
        'entry_code' => $entryCode,
        'reference' => $reference,
        'amount_kg' => $amount_kg
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>

