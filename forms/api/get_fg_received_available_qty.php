<?php
/**
 * API to fetch available quantity from fg_received_entry for a given reference number
 * Returns real-time remaining quantity after all deliveries
 */

header('Content-Type: application/json');
session_start();
require_once '../../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn = SecurityConfig::getConnection();

try {
    $referenceNumber = $_GET['reference'] ?? '';
    
    if (empty($referenceNumber)) {
        echo json_encode(['success' => false, 'message' => 'Reference number is required']);
        exit();
    }
    
    // Trim and split comma-separated references
    $referenceNumber = trim($referenceNumber);
    $referenceParts = array_map('trim', explode(',', $referenceNumber));
    $referenceParts = array_filter($referenceParts);
    
    if (empty($referenceParts)) {
        echo json_encode(['success' => false, 'message' => 'Invalid reference number']);
        exit();
    }
    
    // Build query to find fg_received_entry that contains any of the reference parts
    $placeholders = [];
    $types = '';
    $params = [];
    
    foreach ($referenceParts as $refPart) {
        $refPart = trim($refPart);
        if (empty($refPart)) continue;
        
        $placeholders[] = "(
            fre.reference_number = ?
            OR fre.reference_number LIKE CONCAT(?, ',%')
            OR fre.reference_number LIKE CONCAT(?, ' ,%')
            OR fre.reference_number LIKE CONCAT('%,', ?)
            OR fre.reference_number LIKE CONCAT('%, ', ?)
            OR fre.reference_number LIKE CONCAT('%, ', ?, ',%')
            OR fre.reference_number LIKE CONCAT('%,', ?, ',%')
            OR FIND_IN_SET(?, REPLACE(REPLACE(TRIM(fre.reference_number), ', ', ','), ' ,', ',')) > 0
        )";
        
        for ($i = 0; $i < 8; $i++) {
            $types .= 's';
            $params[] = $refPart;
        }
    }
    
    if (empty($placeholders)) {
        echo json_encode(['success' => false, 'message' => 'Invalid reference number']);
        exit();
    }
    
    $whereClause = implode(' OR ', $placeholders);
    
    // Get available quantity from fg_received_entry
    // Calculate: received_quantity - sum of all deliveries (only kg deliveries)
    // Also get area_sqm and calculate remaining sqm (total_area - sum of sqm deliveries)
    $hasDeliveryUnit = $conn->query("SHOW COLUMNS FROM fg_deliveries LIKE 'delivery_quantity_unit'")->num_rows > 0;
    $hasAreaSqm = $conn->query("SHOW COLUMNS FROM fg_received_entry LIKE 'area_sqm'")->num_rows > 0;
    
    $deliveredKgCondition = $hasDeliveryUnit 
        ? "AND (fd.delivery_quantity_unit = 'kg' OR fd.delivery_quantity_unit IS NULL OR fd.delivery_quantity_unit = '')"
        : "";
    $deliveredSqmCondition = $hasDeliveryUnit 
        ? "AND fd.delivery_quantity_unit = 'sqm'"
        : "AND 1 = 0"; // If unit column doesn't exist, no sqm deliveries
    
    $areaSqmSelect = $hasAreaSqm ? ", COALESCE(fre.area_sqm, 0) as area_sqm" : ", 0 as area_sqm";
    $deliveredSqmSelect = $hasDeliveryUnit && $hasAreaSqm
        ? ", COALESCE(SUM(CASE WHEN fd.delivery_quantity_unit = 'sqm' THEN fd.delivery_quantity ELSE 0 END), 0) as delivered_sqm"
        : ", 0 as delivered_sqm";
    $remainingSqmSelect = $hasAreaSqm && $hasDeliveryUnit
        ? ", (COALESCE(fre.area_sqm, 0) - COALESCE(SUM(CASE WHEN fd.delivery_quantity_unit = 'sqm' THEN fd.delivery_quantity ELSE 0 END), 0)) as remaining_sqm"
        : ", COALESCE(fre.area_sqm, 0) as remaining_sqm";
    
    $query = "SELECT 
                fre.id,
                fre.entry_id,
                fre.reference_number,
                fre.received_quantity,
                fre.available_quantity,
                COALESCE(SUM(CASE WHEN fd.delivery_quantity_unit = 'kg' OR fd.delivery_quantity_unit IS NULL OR fd.delivery_quantity_unit = '' THEN fd.delivery_quantity ELSE 0 END), 0) as delivered_quantity_kg,
                (fre.received_quantity - COALESCE(SUM(CASE WHEN fd.delivery_quantity_unit = 'kg' OR fd.delivery_quantity_unit IS NULL OR fd.delivery_quantity_unit = '' THEN fd.delivery_quantity ELSE 0 END), 0)) as remaining_quantity
                {$areaSqmSelect}
                {$deliveredSqmSelect}
                {$remainingSqmSelect}
              FROM fg_received_entry fre
              LEFT JOIN fg_deliveries fd ON fre.id = fd.fg_entry_id AND fd.delivery_product_type = 'roll'
              WHERE fre.product_type = 'roll'
                AND fre.reference_number IS NOT NULL
                AND fre.reference_number != ''
                AND ({$whereClause})
              GROUP BY fre.id, fre.received_quantity, fre.available_quantity, fre.reference_number, fre.entry_id" . ($hasAreaSqm ? ", fre.area_sqm" : "") . "
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'No FG Received Entry found for this reference',
            'remaining_quantity' => 0
        ]);
        $stmt->close();
        $conn->close();
        exit();
    }
    
    $row = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    echo json_encode([
        'success' => true,
        'id' => $row['id'],
        'entry_id' => $row['entry_id'],
        'reference_number' => $row['reference_number'],
        'received_quantity' => (float)$row['received_quantity'],
        'delivered_quantity' => (float)($row['delivered_quantity_kg'] ?? $row['delivered_quantity'] ?? 0),
        'remaining_quantity' => (float)$row['remaining_quantity'],
        'area_sqm' => (float)($row['area_sqm'] ?? 0),
        'delivered_sqm' => (float)($row['delivered_sqm'] ?? 0),
        'remaining_sqm' => (float)($row['remaining_sqm'] ?? ($row['area_sqm'] ?? 0))
    ]);
    
} catch (Exception $e) {
    error_log("get_fg_received_available_qty error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching available quantity: ' . $e->getMessage(),
        'remaining_quantity' => 0
    ]);
    if (isset($conn)) $conn->close();
}
?>
