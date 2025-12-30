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
        // Get total recycled quantities from scrap_recycle
        $recycledQuery = $conn->query("
            SELECT 
                s.scrap_category,
                COALESCE(SUM(sr.recycled_qty), 0) as total_recycled
            FROM scrap_recycle sr
            INNER JOIN scrap s ON sr.scrap_id = s.id
            WHERE s.is_deleted = 0
            AND s.scrap_category IS NOT NULL
            AND s.scrap_category != ''
            GROUP BY s.scrap_category
        ");
        
        $recycledTotals = [];
        if ($recycledQuery) {
            while ($row = $recycledQuery->fetch_assoc()) {
                $category = trim($row['scrap_category']);
                $total = (float)$row['total_recycled'];
                
                if (stripos($category, 'Sheet Production') !== false) {
                    $recycledTotals['sheet_production'] = ($recycledTotals['sheet_production'] ?? 0) + $total;
                } elseif (stripos($category, 'Swing') !== false && stripos($category, 'Sheet Production') === false) {
                    $recycledTotals['swing'] = ($recycledTotals['swing'] ?? 0) + $total;
                }
            }
        }
        
        // Get amounts already used in fiber_entries (not deleted)
        // Exclude the current entry being submitted (if it's an update)
        $usedStmt = $conn->prepare("
            SELECT 
                COALESCE(SUM(recycled_amount), 0) as total_used
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
        $available = 0;
        if ($recycledType === 'sheet_production') {
            $available = max(0, ($recycledTotals['sheet_production'] ?? 0) - $totalUsed);
        } elseif ($recycledType === 'swing') {
            $available = max(0, ($recycledTotals['swing'] ?? 0) - $totalUsed);
        }
        
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
    
    // Auto-generate entry code
    $entryCode = 'FE-' . time();

    // Create fiber_entries table if it doesn't exist
    $createTable = "CREATE TABLE IF NOT EXISTS fiber_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_code VARCHAR(50),
        date_time DATETIME,
        shift VARCHAR(50) DEFAULT '',
        shift_incharge VARCHAR(100),
        reference VARCHAR(100) DEFAULT '',
        project_id INT,
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
    
    // Helper to add column safely (without failing if AFTER target missing)
    $ensureColumn = function($table, $column, $definition, $after = null) use ($conn) {
        $safeCol = $conn->real_escape_string($column);
        $existsRes = $conn->query("
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = '{$table}'
              AND COLUMN_NAME = '{$safeCol}'
            LIMIT 1
        ");
        if ($existsRes && $existsRes->num_rows > 0) {
            return;
        }
        if ($after) {
            $safeAfter = $conn->real_escape_string($after);
            // Try with AFTER; if it fails, retry without AFTER
            if (!$conn->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition} AFTER {$safeAfter}")) {
                $conn->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        } else {
            $conn->query("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    };

    // Ensure key columns exist before amount columns
    $ensureColumn('fiber_entries', 'project_id', "INT", 'reference');

    // Add missing columns if they don't exist (including amount_kg family)
    $ensureColumn('fiber_entries', 'shift', "VARCHAR(50) DEFAULT ''", 'date_time');
    $ensureColumn('fiber_entries', 'reference', "VARCHAR(100) DEFAULT ''", 'shift_incharge');
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
        (entry_code, date_time, shift, shift_incharge, reference, project_id, amount_kg, recycled_type, recycled_amount, total_amount, material_type, origin, belt_weight, belt_number, summary, reporter_id, reporter_name)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('Prepare failed: ' . $conn->error);
    }

    // Correct bind_param types: s=string, i=integer, d=decimal/double
    // 17 parameters total
    $stmt->bind_param(
        "sssssidsddssissis",
        $entryCode,      // s - string
        $dateTime,       // s - string
        $shift,          // s - string
        $shiftIncharge,  // s - string
        $reference,      // s - string
        $projectId,      // i - integer
        $amount_kg,      // d - decimal
        $recycledType,   // s - string
        $recycledAmount, // d - decimal
        $totalAmount,    // d - decimal
        $materialType,   // s - string
        $origin,         // s - string
        $beltWeight,     // i - integer
        $beltNumber,     // s - string
        $summary,        // s - string
        $reporterId,     // i - integer
        $reporterName    // s - string
    );

    if (!$stmt->execute()) {
        throw new Exception('Execute failed: ' . $stmt->error);
    }

    $insertId = $conn->insert_id;
    
    // Deduct the used amount from store inventory if reference is provided
    if (!empty($reference) && $amount_kg > 0) {
        $updateStmt = $conn->prepare("UPDATE store_received_entries 
                                      SET amount_kg = amount_kg - ? 
                                      WHERE entry_number = ? 
                                      AND amount_kg >= ?");
        if ($updateStmt) {
            $updateStmt->bind_param("dsd", $amount_kg, $reference, $amount_kg);
            if (!$updateStmt->execute()) {
                // Log warning but don't fail the whole transaction
                error_log("Warning: Could not update inventory for reference: $reference");
            }
            $updateStmt->close();
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Fiber entry submitted successfully',
        'entry_id' => $insertId,
        'entry_code' => $entryCode
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>

