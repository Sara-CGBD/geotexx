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

if (!isset($_GET['reference']) || empty($_GET['reference'])) {
    echo json_encode(['success' => false, 'error' => 'Reference is required']);
    exit;
}

$reference = $_GET['reference'];
$exclude_report_id = isset($_GET['exclude_report_id']) ? (int)$_GET['exclude_report_id'] : 0;
$conn = SecurityConfig::getConnection();

// Query for already submitted tests for this reference (exclude rejected tests and optionally exclude a specific report ID)
$sql = "
    SELECT ts.test_name, ts.standard_code
    FROM qc_test_orders qto
    INNER JOIN test_standards ts ON qto.test_standard_id = ts.id
    WHERE qto.sample_reference_id = ?
    AND qto.status NOT IN ('rejected_by_checker', 'rejected_by_approver')
";
if ($exclude_report_id > 0) {
    $sql .= " AND qto.id != ?";
}

$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
    exit;
}

if ($exclude_report_id > 0) {
    $stmt->bind_param("si", $reference, $exclude_report_id);
} else {
    $stmt->bind_param("s", $reference);
}
$stmt->execute();
$result = $stmt->get_result();

$submitted_tests = [];
while ($row = $result->fetch_assoc()) {
    $test_key = $row['test_name'] . '_' . $row['standard_code'];
    $submitted_tests[$test_key] = true;
}

$stmt->close();
$conn->close();

echo json_encode([
    'success' => true,
    'submitted_tests' => $submitted_tests
]);

