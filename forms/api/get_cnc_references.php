<?php
session_start();
require_once '../../config/security_config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$conn = SecurityConfig::getConnection();

// Optimized reference query with LIMIT
$referenceNumbers = [];
$refQuery = "SELECT DISTINCT r.reference_number 
             FROM roll_received r 
             WHERE r.reference_number IS NOT NULL 
             AND NOT EXISTS (
                 SELECT 1 FROM cnc_entries c 
                 WHERE c.reference_number = r.reference_number
             )
             AND (
                 NOT EXISTS (
                     SELECT 1 
                     FROM qc_test_orders qto
                     LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
                     WHERE qto.sample_reference_id = r.reference_number
                     AND ts.test_name != 'Weathering Exposure Test'
                     AND (qto.status != 'approved' OR qto.approved_by IS NULL)
                 )
                 AND NOT EXISTS (
                     SELECT 1 
                     FROM water_permeability_tests wpt
                     WHERE wpt.reference_number = r.reference_number
                     AND (wpt.status != 'approved' OR wpt.approved_by IS NULL)
                 )
                 AND NOT EXISTS (
                     SELECT 1 
                     FROM characteristics_tests ct
                     WHERE ct.reference_number = r.reference_number
                     AND (ct.status != 'approved' OR ct.approver_name IS NULL)
                 )
                 AND NOT EXISTS (
                     SELECT 1 
                     FROM sun_test_reports str
                     WHERE str.reference_number = r.reference_number
                     AND (str.status != 'approved' OR str.approved_by IS NULL)
                 )
             )
             AND (
                 EXISTS (
                     SELECT 1 
                     FROM qc_test_orders qto
                     LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
                     WHERE qto.sample_reference_id = r.reference_number
                     AND ts.test_name != 'Weathering Exposure Test'
                     AND qto.status = 'approved'
                     AND qto.approved_by IS NOT NULL
                 )
                 OR EXISTS (
                     SELECT 1 
                     FROM water_permeability_tests wpt
                     WHERE wpt.reference_number = r.reference_number
                     AND wpt.status = 'approved'
                     AND wpt.approved_by IS NOT NULL
                 )
                 OR EXISTS (
                     SELECT 1 
                     FROM characteristics_tests ct
                     WHERE ct.reference_number = r.reference_number
                     AND ct.status = 'approved'
                     AND ct.approver_name IS NOT NULL
                 )
                 OR EXISTS (
                     SELECT 1 
                     FROM sun_test_reports str
                     WHERE str.reference_number = r.reference_number
                     AND str.status = 'approved'
                     AND str.approved_by IS NOT NULL
                 )
             )
             ORDER BY r.created_at DESC
             LIMIT 100";
$refResult = $conn->query($refQuery);
if ($refResult) {
    while ($row = $refResult->fetch_assoc()) {
        $referenceNumbers[] = $row['reference_number'];
    }
}

echo json_encode(['success' => true, 'references' => $referenceNumbers]);
?>


