<?php
/**
 * GEOTEX Enterprise Database Optimization
 * Safely add missing indexes without errors
 */

require_once __DIR__ . '/../config/config.php';

echo "=====================================================\n";
echo "GEOTEX Database Index Optimization\n";
echo "=====================================================\n\n";

// Function to check if index exists
function indexExists($conn, $table, $index_name) {
    $result = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name = '$index_name'");
    return $result && $result->num_rows > 0;
}

// Function to safely add index
function addIndex($conn, $table, $index_name, $column) {
    if (!indexExists($conn, $table, $index_name)) {
        $sql = "CREATE INDEX `$index_name` ON `$table`($column)";
        if ($conn->query($sql)) {
            echo "[✓] Added index: $index_name on $table($column)\n";
            return true;
        } else {
            echo "[✗] Failed to add index $index_name: " . $conn->error . "\n";
            return false;
        }
    } else {
        echo "[→] Index already exists: $index_name on $table\n";
        return true;
    }
}

// Indexes to add
$indexes = [
    // Water Permeability Tests
    ['water_permeability_tests', 'idx_wpt_status_date', 'status, test_date'],
    
    // QC Entries
    ['qc_entries', 'idx_qc_test_date', 'test_date'],
    ['qc_entries', 'idx_qc_status', 'status'],
    ['qc_entries', 'idx_qc_project', 'project_id'],
    ['qc_entries', 'idx_qc_status_date', 'status, test_date'],
    
    // Characteristics Tests
    ['characteristics_tests', 'idx_char_report_number', 'report_number'],
    ['characteristics_tests', 'idx_char_test_date', 'test_date'],
    ['characteristics_tests', 'idx_char_status', 'status'],
    
    // Sewing Thread Reports
    ['sewing_thread_reports', 'idx_sewing_report_number', 'report_number'],
    ['sewing_thread_reports', 'idx_sewing_test_date', 'test_date'],
    ['sewing_thread_reports', 'idx_sewing_status', 'status'],
    
    // Fiber Test Reports
    ['fiber_test_reports', 'idx_fiber_report_number', 'report_number'],
    ['fiber_test_reports', 'idx_fiber_test_date', 'test_date'],
    ['fiber_test_reports', 'idx_fiber_status', 'status'],
    
    // Sun Test Reports
    ['sun_test_reports', 'idx_sun_report_number', 'report_number'],
    ['sun_test_reports', 'idx_sun_test_date', 'test_date'],
    ['sun_test_reports', 'idx_sun_status', 'status'],
    
    // Fabric Pre-Production Tests
    ['fabric_pre_production_tests', 'idx_fabric_pre_report', 'report_number'],
    ['fabric_pre_production_tests', 'idx_fabric_pre_date', 'test_date'],
    ['fabric_pre_production_tests', 'idx_fabric_pre_status', 'status'],
    
    // Fabric After Production Tests
    ['fabric_after_production_tests', 'idx_fabric_after_report', 'report_number'],
    ['fabric_after_production_tests', 'idx_fabric_after_date', 'test_date'],
    ['fabric_after_production_tests', 'idx_fabric_after_status', 'status'],
    
    // Projects
    ['projects', 'idx_project_status', 'status'],
    ['projects', 'idx_project_name', 'project_name'],
    
    // FG
    ['fg', 'idx_fg_project', 'project_id'],
    ['fg', 'idx_fg_product', 'product_id'],
    ['fg', 'idx_fg_batch', 'batch_number'],
    
    // FG Delivery
    ['fg_delivery', 'idx_fg_delivery_challan', 'challan_number'],
    ['fg_delivery', 'idx_fg_delivery_project', 'project_id'],
    
    // Roll Entry
    ['roll_entry', 'idx_roll_number', 'roll_number'],
    ['roll_entry', 'idx_roll_batch', 'batch_number'],
    ['roll_entry', 'idx_roll_project', 'project_id'],
    
    // Fiber to Roll Entry
    ['fiber_to_roll_entry', 'idx_fiber_roll_number', 'roll_number'],
    ['fiber_to_roll_entry', 'idx_fiber_roll_project', 'project_id'],
    
    // Production Entry
    ['production_entry', 'idx_production_shift', 'shift'],
    ['production_entry', 'idx_production_project', 'project_id'],
    
    // CNC Entries
    ['cnc_entries', 'idx_cnc_id', 'cnc_id'],
    ['cnc_entries', 'idx_cnc_project', 'project_id'],
    ['cnc_entries', 'idx_cnc_machine', 'cnc_machine_id'],
    
    // Scrap Entry
    ['scrap_entry', 'idx_scrap_type', 'scrap_type'],
    ['scrap_entry', 'idx_scrap_project', 'project_id'],
    
    // BOM
    ['bom', 'idx_bom_project', 'project_id'],
    ['bom', 'idx_bom_product', 'product_id'],
    
    // Target Entry
    ['target_entry', 'idx_target_shift', 'shift'],
    ['target_entry', 'idx_target_project', 'project_id'],
    
    // Users
    ['new_user', 'idx_user_username', 'username'],
    ['new_user', 'idx_user_role', 'role'],
    ['new_user', 'idx_user_status', 'status'],
];

$added = 0;
$skipped = 0;
$failed = 0;

foreach ($indexes as $index) {
    list($table, $index_name, $columns) = $index;
    
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE '$table'");
    if (!$table_check || $table_check->num_rows == 0) {
        echo "[!] Table does not exist: $table\n";
        $skipped++;
        continue;
    }
    
    $result = addIndex($conn, $table, $index_name, $columns);
    if ($result) {
        if (!indexExists($conn, $table, $index_name)) {
            $added++;
        } else {
            $skipped++;
        }
    } else {
        $failed++;
    }
}

echo "\n=====================================================\n";
echo "Index Optimization Complete!\n";
echo "=====================================================\n";
echo "Added: $added indexes\n";
echo "Skipped (already exist): $skipped indexes\n";
echo "Failed: $failed indexes\n\n";

// Analyze all tables for query optimization
echo "Analyzing tables for query optimization...\n\n";

$tables_to_analyze = [
    'water_permeability_tests',
    'qc_entries',
    'characteristics_tests',
    'sewing_thread_reports',
    'fiber_test_reports',
    'sun_test_reports',
    'fabric_pre_production_tests',
    'fabric_after_production_tests',
    'projects',
    'fg',
    'fg_delivery',
    'roll_entry',
    'fiber_to_roll_entry',
    'production_entry',
    'cnc_entries',
    'scrap_entry',
    'bom',
    'target_entry',
    'new_user'
];

foreach ($tables_to_analyze as $table) {
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE '$table'");
    if ($table_check && $table_check->num_rows > 0) {
        if ($conn->query("ANALYZE TABLE `$table`")) {
            echo "[✓] Analyzed table: $table\n";
        }
    }
}

echo "\n=====================================================\n";
echo "Database optimization complete!\n";
echo "Your database is now enterprise-ready! 🚀\n";
echo "=====================================================\n";

$conn->close();
?>


