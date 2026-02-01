<?php
/**
 * API to check which references in a range have been submitted for UV Test (Weathering Exposure Test)
 */
session_start();
if (file_exists(__DIR__ . '/../security_config.php')) {
    require_once __DIR__ . '/../security_config.php';
} else {
    require_once '../../security_config.php';
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if (!isset($_GET['from_reference']) || empty($_GET['from_reference']) || 
    !isset($_GET['to_reference']) || empty($_GET['to_reference'])) {
    echo json_encode(['success' => false, 'error' => 'From and To references are required']);
    exit;
}

$from_reference = trim($_GET['from_reference']);
$to_reference = trim($_GET['to_reference']);
$conn = SecurityConfig::getConnection();

// Helper function to check if a reference falls within a range
function isReferenceInRange($ref, $fromRef, $toRef) {
    if (preg_match('/^(.+?)-(\d+)$/', $ref, $refMatches) &&
        preg_match('/^(.+?)-(\d+)$/', $fromRef, $fromMatches) &&
        preg_match('/^(.+?)-(\d+)$/', $toRef, $toMatches)) {
        
        $refBase = $refMatches[1];
        $refNum = intval($refMatches[2]);
        $fromBase = $fromMatches[1];
        $fromNum = intval($fromMatches[2]);
        $toBase = $toMatches[1];
        $toNum = intval($toMatches[2]);
        
        if ($refBase === $fromBase && $refBase === $toBase) {
            return ($refNum >= $fromNum && $refNum <= $toNum);
        }
    }
    
    return ($ref === $fromRef || $ref === $toRef || 
            ($ref >= $fromRef && $ref <= $toRef));
}

// Get all submitted UV tests
$submitted_refs = [];
$stmt = $conn->prepare("
    SELECT DISTINCT reference, bundle_reference, status, created_at
    FROM weathering_exposure_reports
    WHERE status IN ('pending', 'approved')
    AND reference IS NOT NULL
    AND reference != ''
");

if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $ref = trim($row['reference']);
        $bundleRef = trim($row['bundle_reference'] ?? '');
        
        // Check if reference is in range
        if (isReferenceInRange($ref, $from_reference, $to_reference)) {
            $submitted_refs[] = [
                'reference' => $ref,
                'status' => $row['status'],
                'submitted_at' => $row['created_at']
            ];
        }
        
        // Also check bundle reference if it contains a range
        if (!empty($bundleRef) && strpos($bundleRef, '|') !== false) {
            $parts = explode('|', $bundleRef);
            if (count($parts) === 2) {
                $bundleFrom = trim($parts[0]);
                $bundleTo = trim($parts[1]);
                if ($bundleFrom === $from_reference && $bundleTo === $to_reference) {
                    $submitted_refs[] = [
                        'reference' => $bundleRef,
                        'status' => $row['status'],
                        'submitted_at' => $row['created_at'],
                        'is_bundle' => true
                    ];
                }
            }
        }
    }
    $stmt->close();
}

$conn->close();

echo json_encode([
    'success' => true,
    'submitted_references' => $submitted_refs,
    'count' => count($submitted_refs)
]);
?>
