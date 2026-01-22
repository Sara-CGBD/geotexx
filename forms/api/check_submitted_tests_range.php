<?php
session_start();
// Try different paths for security_config
if (file_exists(__DIR__ . '/../../security_config.php')) {
    require_once __DIR__ . '/../../security_config.php';
} elseif (file_exists(__DIR__ . '/../security_config.php')) {
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

$from_reference = $_GET['from_reference'];
$to_reference = $_GET['to_reference'];
$exclude_report_id = isset($_GET['exclude_report_id']) ? (int)$_GET['exclude_report_id'] : 0;
$conn = SecurityConfig::getConnection();

// Query for already submitted tests for this reference range
// We need to check both:
// 1. Tests with bulk_from_reference and bulk_to_reference in test_data
// 2. Tests with individual sample_reference_id that fall within the range
$submitted_tests = [];
$submitted_tests_list = []; // Array to store test details for display

// Helper function to check if a reference falls within a range
function isReferenceInRange($ref, $fromRef, $toRef) {
    // Extract base pattern and number from references
    // Pattern: "4.0L226JAN05-R01-H0.1-1" -> base: "4.0L226JAN05-R01-H0.1", num: 1
    if (preg_match('/^(.+?)-(\d+)$/', $ref, $refMatches) &&
        preg_match('/^(.+?)-(\d+)$/', $fromRef, $fromMatches) &&
        preg_match('/^(.+?)-(\d+)$/', $toRef, $toMatches)) {
        
        $refBase = $refMatches[1];
        $refNum = intval($refMatches[2]);
        $fromBase = $fromMatches[1];
        $fromNum = intval($fromMatches[2]);
        $toBase = $toMatches[1];
        $toNum = intval($toMatches[2]);
        
        // Check if base matches and number is in range
        if ($refBase === $fromBase && $refBase === $toBase) {
            return ($refNum >= $fromNum && $refNum <= $toNum);
        }
    }
    
    // If pattern doesn't match, check for exact match
    return ($ref === $fromRef || $ref === $toRef || 
            ($ref >= $fromRef && $ref <= $toRef));
}

// Get all test orders with their sample_reference_id
$sql_with_refs = "
    SELECT qto.id, qto.test_data, qto.sample_reference_id, ts.test_name, ts.standard_code
    FROM qc_test_orders qto
    INNER JOIN test_standards ts ON qto.test_standard_id = ts.id
    WHERE qto.status NOT IN ('rejected_by_checker', 'rejected_by_approver')
      AND qto.sample_reference_id IS NOT NULL
      AND qto.sample_reference_id != ''
";
if ($exclude_report_id > 0) {
    $sql_with_refs .= " AND qto.id != ?";
}

$stmt_with_refs = $conn->prepare($sql_with_refs);
if (!$stmt_with_refs) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
    exit;
}

if ($exclude_report_id > 0) {
    $stmt_with_refs->bind_param("i", $exclude_report_id);
}
$stmt_with_refs->execute();
$result_with_refs = $stmt_with_refs->get_result();

while ($row = $result_with_refs->fetch_assoc()) {
    $test_data = json_decode($row['test_data'] ?? '{}', true);
    $sample_ref = trim($row['sample_reference_id'] ?? '');
    
    $is_match = false;
    
    // Method 1: Check if test_data has bulk reference range that matches
    if (isset($test_data['is_bulk_reference']) && $test_data['is_bulk_reference'] &&
        isset($test_data['bulk_from_reference']) && isset($test_data['bulk_to_reference']) &&
        $test_data['bulk_from_reference'] === $from_reference &&
        $test_data['bulk_to_reference'] === $to_reference) {
        $is_match = true;
    }
    // Method 2: Check if sample_reference_id falls within the selected range
    elseif (!empty($sample_ref) && isReferenceInRange($sample_ref, $from_reference, $to_reference)) {
        $is_match = true;
    }
    
    if ($is_match) {
        $test_name = $row['test_name'] ?? '';
        $standard_code = $row['standard_code'] ?? '';
        $chosen_method = $test_data['chosen_method'] ?? $standard_code;
        
        // Create keys for both standard_code and chosen_method matching
        $test_key_standard = $test_name . '_' . $standard_code;
        $test_key_method = $test_name . '_' . $chosen_method;
        
        $submitted_tests[$test_key_standard] = true;
        $submitted_tests[$test_key_method] = true;
        
        // Store test details for display (avoid duplicates)
        $test_key_display = $test_name . '_' . $chosen_method;
        $already_added = false;
        foreach ($submitted_tests_list as $existing) {
            if ($existing['test_name'] === $test_name && $existing['method'] === $chosen_method) {
                $already_added = true;
                break;
            }
        }
        
        if (!$already_added) {
            $submitted_tests_list[] = [
                'test_name' => $test_name,
                'method' => $chosen_method,
                'sample_ref' => $sample_ref
            ];
        }
    }
}

$stmt_with_refs->close();
$conn->close();

echo json_encode([
    'success' => true,
    'submitted_tests' => $submitted_tests,
    'submitted_tests_list' => $submitted_tests_list // Add list for easy display
]);
