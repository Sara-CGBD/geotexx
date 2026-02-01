<?php
session_start();
require_once '../../config/security_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$type = $_GET['type'] ?? '';
$allowed = ['sheet_production', 'swing'];
if (!in_array($type, $allowed, true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid recycled type']);
    exit;
}

$conn = SecurityConfig::getConnection();

// Map types to category matches (handle both "Sewing Production" and "Swing" for backward compatibility)
if ($type === 'sheet_production') {
    $categoryMatch = 'Sheet Production';
    $categoryMatches = ['Sheet Production'];
} else {
    // For swing/sewing, match both "Sewing Production" and "Swing"
    $categoryMatch = 'Sewing Production';
    $categoryMatches = ['Sewing Production', 'Swing'];
}

// Check if production_category column exists
$colCheck = $conn->query("SHOW COLUMNS FROM scrap_recycle LIKE 'production_category'");
$hasProductionCategory = $colCheck && $colCheck->num_rows > 0;

if ($hasProductionCategory) {
    // Use production_category column directly
    // Handle multiple category matches for sewing (Sewing Production or Swing)
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
$row = $recycledResult->fetch_assoc();
$totalRecycled = (float)($row['total_recycled'] ?? 0);
$recycledQuery->close();

$usedStmt = $conn->prepare("
    SELECT COALESCE(SUM(fe.recycled_amount), 0) as used
    FROM fiber_entries fe
    WHERE fe.is_deleted = 0
      AND fe.recycled_type = ?
");
$usedStmt->bind_param('s', $type);
$usedStmt->execute();
$usedResult = $usedStmt->get_result()->fetch_assoc();
$usedStmt->close();

$usedAmount = (float)($usedResult['used'] ?? 0);
$available = max(0, $totalRecycled - $usedAmount);

echo json_encode([
    'success' => true,
    'available_amount' => round($available, 2)
]);
