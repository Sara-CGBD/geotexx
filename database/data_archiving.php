<?php
/**
 * GEOTEX Enterprise Data Archiving System
 * Archives old data to keep main tables fast
 * Run this monthly via scheduled task
 */

require_once __DIR__ . '/../config/config.php';

echo "=====================================================\n";
echo "GEOTEX Data Archiving System\n";
echo "=====================================================\n\n";

$archive_date = date('Y-m-d H:i:s');
$cutoff_date = date('Y-m-d', strtotime('-2 years'));

echo "Archive Date: $archive_date\n";
echo "Archiving records older than: $cutoff_date\n\n";

// Tables to archive with their date columns
$tables_to_archive = [
    'water_permeability_tests' => 'test_date',
    'qc_entries' => 'test_date',
    'characteristics_tests' => 'test_date',
    'sewing_thread_reports' => 'test_date',
    'fiber_test_reports' => 'test_date',
    'sun_test_reports' => 'test_date',
    'fabric_pre_production_tests' => 'test_date',
    'fabric_after_production_tests' => 'test_date',
    'fg_delivery' => 'delivery_date',
    'production_entry' => 'production_date',
    'cnc_entries' => 'date_time',
    'scrap_entry' => 'entry_date',
];

$total_archived = 0;
$total_deleted = 0;

foreach ($tables_to_archive as $table => $date_column) {
    echo "Processing table: $table\n";
    
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE '$table'");
    if (!$table_check || $table_check->num_rows == 0) {
        echo "  [!] Table does not exist, skipping\n\n";
        continue;
    }
    
    // Check if date column exists
    $column_check = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$date_column'");
    if (!$column_check || $column_check->num_rows == 0) {
        echo "  [!] Date column '$date_column' does not exist, skipping\n\n";
        continue;
    }
    
    $archive_table = $table . '_archive';
    
    // Create archive table if not exists
    $conn->query("CREATE TABLE IF NOT EXISTS `$archive_table` LIKE `$table`");
    
    // Add archive_date column if not exists
    $conn->query("ALTER TABLE `$archive_table` 
                  ADD COLUMN IF NOT EXISTS archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    
    // Count records to archive
    $count_query = "SELECT COUNT(*) as cnt FROM `$table` WHERE DATE(`$date_column`) < '$cutoff_date'";
    $count_result = $conn->query($count_query);
    $records_to_archive = $count_result->fetch_assoc()['cnt'];
    
    if ($records_to_archive > 0) {
        echo "  Found $records_to_archive records to archive\n";
        
        // Begin transaction
        $conn->begin_transaction();
        
        try {
            // Copy to archive
            $archive_query = "INSERT INTO `$archive_table` SELECT *, NOW() as archived_at FROM `$table` 
                             WHERE DATE(`$date_column`) < '$cutoff_date'";
            
            if ($conn->query($archive_query)) {
                $archived = $conn->affected_rows;
                echo "  [✓] Archived $archived records\n";
                
                // Delete from main table
                $delete_query = "DELETE FROM `$table` WHERE DATE(`$date_column`) < '$cutoff_date'";
                
                if ($conn->query($delete_query)) {
                    $deleted = $conn->affected_rows;
                    echo "  [✓] Deleted $deleted records from main table\n";
                    
                    // Commit transaction
                    $conn->commit();
                    
                    $total_archived += $archived;
                    $total_deleted += $deleted;
                } else {
                    throw new Exception("Delete failed: " . $conn->error);
                }
            } else {
                throw new Exception("Archive failed: " . $conn->error);
            }
            
        } catch (Exception $e) {
            $conn->rollback();
            echo "  [✗] Error: " . $e->getMessage() . "\n";
        }
        
        // Optimize table
        $conn->query("OPTIMIZE TABLE `$table`");
        echo "  [✓] Table optimized\n";
        
    } else {
        echo "  [→] No records to archive\n";
    }
    
    echo "\n";
}

// Archive old audit logs (keep last 2 years)
echo "Archiving old audit logs...\n";
$audit_archive_query = "CREATE TABLE IF NOT EXISTS audit_log_archive LIKE audit_log";
$conn->query($audit_archive_query);

$audit_count = $conn->query("SELECT COUNT(*) as cnt FROM audit_log 
                            WHERE changed_at < DATE_SUB(NOW(), INTERVAL 2 YEAR)")
                    ->fetch_assoc()['cnt'];

if ($audit_count > 0) {
    $conn->begin_transaction();
    try {
        $conn->query("INSERT INTO audit_log_archive SELECT * FROM audit_log 
                     WHERE changed_at < DATE_SUB(NOW(), INTERVAL 2 YEAR)");
        $archived_audit = $conn->affected_rows;
        
        $conn->query("DELETE FROM audit_log 
                     WHERE changed_at < DATE_SUB(NOW(), INTERVAL 2 YEAR)");
        $deleted_audit = $conn->affected_rows;
        
        $conn->commit();
        echo "[✓] Archived $archived_audit audit log records\n";
        echo "[✓] Deleted $deleted_audit old audit records\n";
        
        $total_archived += $archived_audit;
        $total_deleted += $deleted_audit;
    } catch (Exception $e) {
        $conn->rollback();
        echo "[✗] Audit log archiving failed: " . $e->getMessage() . "\n";
    }
} else {
    echo "[→] No old audit logs to archive\n";
}

echo "\n=====================================================\n";
echo "Archiving Complete!\n";
echo "=====================================================\n";
echo "Total records archived: " . number_format($total_archived) . "\n";
echo "Total records deleted from main tables: " . number_format($total_deleted) . "\n";
echo "Archive date: $archive_date\n";
echo "=====================================================\n";

// Log the archiving action
$conn->query("INSERT INTO audit_log (table_name, action, new_values, changed_by) 
             VALUES ('system', 'DELETE', 
             '{\"total_archived\": $total_archived, \"total_deleted\": $total_deleted, \"archive_date\": \"$archive_date\"}', 
             'SYSTEM_ARCHIVER')");

$conn->close();
?>


