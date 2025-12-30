<?php
header('Content-Type: application/json');
require_once '../../config/security_config.php';

// Enable exceptions for mysqli errors
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = SecurityConfig::getConnection();

try {
    // Helper functions
    $tableExists = fn($c, $table) => $c->query("SHOW TABLES LIKE '$table'")->num_rows > 0;
    $columnExists = fn($c, $table, $col) => $c->query("SHOW COLUMNS FROM `$table` LIKE '$col'")->num_rows > 0;

    // Ensure columns exist in swing_machine_entry
    if (!$tableExists($conn, 'swing_machine_entry')) {
        echo json_encode(['success' => false, 'references' => [], 'warning' => 'swing_machine_entry table missing']);
        exit;
    }
    if (!$columnExists($conn, 'swing_machine_entry', 'reference_number')) {
        $conn->query("ALTER TABLE swing_machine_entry ADD COLUMN `reference_number` VARCHAR(100)");
    }
    if (!$columnExists($conn, 'swing_machine_entry', 'sewing_qty')) {
        $conn->query("ALTER TABLE swing_machine_entry ADD COLUMN `sewing_qty` INT DEFAULT 0");
    }
    if (!$columnExists($conn, 'swing_machine_entry', 'cnc_cutting_batch')) {
        $conn->query("ALTER TABLE swing_machine_entry ADD COLUMN `cnc_cutting_batch` VARCHAR(150)");
    }

    // Ensure branding_entries exists with needed columns
    if ($tableExists($conn, 'branding_entries')) {
        if (!$columnExists($conn, 'branding_entries', 'reference_number')) {
            $conn->query("ALTER TABLE branding_entries ADD COLUMN `reference_number` VARCHAR(100)");
        }
        if (!$columnExists($conn, 'branding_entries', 'print_qty')) {
            $conn->query("ALTER TABLE branding_entries ADD COLUMN `print_qty` INT DEFAULT 0");
        }
    }

    // Main query
    $sql = "
        SELECT 
            s.reference_number,
            s.cnc_cutting_batch,
            SUM(s.sewing_qty) AS total_sewing_qty,
            COALESCE(SUM(b.print_qty), 0) AS total_printed,
            GREATEST(SUM(s.sewing_qty) - COALESCE(SUM(b.print_qty), 0), 0) AS available_for_print
        FROM swing_machine_entry s
        LEFT JOIN branding_entries b 
            ON b.reference_number = s.reference_number
        WHERE s.reference_number IS NOT NULL AND s.reference_number <> ''
        GROUP BY s.reference_number, s.cnc_cutting_batch
        ORDER BY MAX(COALESCE(s.date_time, s.created_at)) DESC
    ";

    $result = $conn->query($sql);
    $references = [];
    while ($row = $result->fetch_assoc()) {
        $references[] = $row;
    }

    echo json_encode([
        'success' => true,
        'references' => $references
    ]);

} catch (Throwable $e) {
    error_log('get_branding_references error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'references' => [],
        'warning' => $e->getMessage()
    ]);
}
?>
