<?php
/**
 * Identify and Delete Unused Database Tables
 * This script identifies tables that are not referenced in the codebase
 */

require_once __DIR__ . '/../config/security_config.php';

$conn = SecurityConfig::getConnection();

// Get all tables in the database
$tables_result = $conn->query("SHOW TABLES");
$all_tables = [];
while ($row = $tables_result->fetch_array()) {
    $all_tables[] = $row[0];
}

echo "📊 Database Table Analysis\n";
echo "==========================\n\n";
echo "Total tables found: " . count($all_tables) . "\n\n";

// Define known used tables (core system tables)
$known_used_tables = [
    // User and authentication
    'new_user',
    'users',
    'active_sessions',
    
    // Core production tables
    'roll_entry',
    'fiber_entry',
    'fg_entry',
    'fg_delivery_entry',
    'roll_transfer_entry',
    'fiber_to_roll_entry',
    'store_received_entry',
    'material_consumption_entry',
    
    // Production operations
    'branding_entry',
    'swing_machine_entry',
    'cnc_entry',
    
    // QC and Testing
    'qc_entry',
    'qc_test_orders',
    'test_standards',
    'characteristics_tests',
    'water_permeability_tests',
    'sun_test_reports',
    'sun_test_counters',
    'sun_test_logs',
    'weathering_exposure_reports',
    'weathering_exposure_counters',
    'weathering_exposure_logs',
    'weathering_exposure_test',
    'sewing_thread_reports',
    'sewing_thread_counters',
    'sewing_thread_logs',
    'fabric_after_production_tests',
    'fabric_after_production_counters',
    
    // Raw material testing
    'tenacity_fiber_reports',
    'tenacity_yarn_reports',
    'cut_length_fiber_reports',
    'fineness_fiber_reports',
    
    // System tables
    'projects',
    'audit_log',
    'user_qc_preferences',
    'bundle_test_completion',
    'bag_size_master',
    
    // Enterprise features
    'performance_metrics',
    'system_logs',
];

// Search codebase for table references
$codebase_path = __DIR__ . '/..';
$used_tables = array_merge($known_used_tables, []);

// Search PHP files for table references
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($codebase_path),
    RecursiveIteratorIterator::SELF_FIRST
);

$table_patterns = [];
foreach ($all_tables as $table) {
    $table_patterns[$table] = [
        'FROM ' . $table,
        'JOIN ' . $table,
        'INTO ' . $table,
        'UPDATE ' . $table,
        'CREATE TABLE.*' . $table,
        "'" . $table . "'",
        '"' . $table . '"',
        '`' . $table . '`',
    ];
}

echo "🔍 Scanning codebase for table references...\n\n";

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $content = @file_get_contents($file->getPathname());
        if ($content === false) continue;
        
        foreach ($table_patterns as $table => $patterns) {
            foreach ($patterns as $pattern) {
                if (stripos($content, $pattern) !== false) {
                    if (!in_array($table, $used_tables)) {
                        $used_tables[] = $table;
                    }
                    break;
                }
            }
        }
    }
}

// Identify unused tables
$unused_tables = array_diff($all_tables, $used_tables);

echo "✅ Used tables (" . count($used_tables) . "):\n";
foreach ($used_tables as $table) {
    echo "   - $table\n";
}

echo "\n❌ Unused tables (" . count($unused_tables) . "):\n";
if (empty($unused_tables)) {
    echo "   No unused tables found!\n";
} else {
    foreach ($unused_tables as $table) {
        echo "   - $table\n";
    }
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "⚠️  WARNING: Review the unused tables list carefully!\n";
echo "Some tables might be used in ways not detected by this scan.\n";
echo str_repeat("=", 50) . "\n\n";

// Ask for confirmation
if (!empty($unused_tables)) {
    echo "Do you want to delete these unused tables? (yes/no): ";
    $handle = fopen("php://stdin", "r");
    $line = trim(fgets($handle));
    fclose($handle);
    
    if (strtolower($line) === 'yes') {
        echo "\n🗑️  Deleting unused tables...\n\n";
        foreach ($unused_tables as $table) {
            try {
                $conn->query("DROP TABLE IF EXISTS `$table`");
                echo "✅ Deleted: $table\n";
            } catch (Exception $e) {
                echo "❌ Error deleting $table: " . $e->getMessage() . "\n";
            }
        }
        echo "\n✅ Cleanup complete!\n";
    } else {
        echo "\n❌ Deletion cancelled.\n";
    }
}

$conn->close();
?>


