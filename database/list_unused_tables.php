<?php
/**
 * List Unused Database Tables
 * This script identifies tables that are not referenced in the codebase
 * Run this first to review before deleting
 */

require_once __DIR__ . '/../config/security_config.php';

$conn = SecurityConfig::getConnection();

// Get all tables in the database
$tables_result = $conn->query("SHOW TABLES");
$all_tables = [];
while ($row = $tables_result->fetch_array()) {
    $all_tables[] = $row[0];
}

echo "<h2>📊 Database Table Analysis</h2>";
echo "<p><strong>Total tables found:</strong> " . count($all_tables) . "</p>";

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
$used_tables = $known_used_tables;

// Search PHP files for table references
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($codebase_path),
    RecursiveIteratorIterator::SELF_FIRST
);

$table_references = [];

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $filepath = $file->getPathname();
        // Skip vendor, node_modules, and backup directories
        if (strpos($filepath, 'vendor') !== false || 
            strpos($filepath, 'node_modules') !== false ||
            strpos($filepath, 'backup') !== false) {
            continue;
        }
        
        $content = @file_get_contents($filepath);
        if ($content === false) continue;
        
        foreach ($all_tables as $table) {
            // Check for various SQL patterns
            $patterns = [
                'FROM `' . $table . '`',
                'FROM ' . $table . ' ',
                'JOIN `' . $table . '`',
                'JOIN ' . $table . ' ',
                'INTO `' . $table . '`',
                'INTO ' . $table . ' ',
                'UPDATE `' . $table . '`',
                'UPDATE ' . $table . ' ',
                'CREATE TABLE.*' . $table,
                "'" . $table . "'",
                '"' . $table . '"',
            ];
            
            foreach ($patterns as $pattern) {
                if (stripos($content, $pattern) !== false) {
                    if (!in_array($table, $used_tables)) {
                        $used_tables[] = $table;
                    }
                    if (!isset($table_references[$table])) {
                        $table_references[$table] = [];
                    }
                    $table_references[$table][] = str_replace($codebase_path . '/', '', $filepath);
                    break;
                }
            }
        }
    }
}

// Identify unused tables
$unused_tables = array_diff($all_tables, $used_tables);

echo "<h3>✅ Used Tables (" . count($used_tables) . "):</h3>";
echo "<ul>";
sort($used_tables);
foreach ($used_tables as $table) {
    $refs = isset($table_references[$table]) ? count($table_references[$table]) : 0;
    echo "<li><strong>$table</strong> (referenced in $refs file(s))</li>";
}
echo "</ul>";

echo "<h3>❌ Potentially Unused Tables (" . count($unused_tables) . "):</h3>";
if (empty($unused_tables)) {
    echo "<p style='color:green;'>✅ No unused tables found! All tables are being used.</p>";
} else {
    echo "<div style='background:#fff3cd; padding:15px; border:1px solid #ffc107; border-radius:5px; margin:10px 0;'>";
    echo "<p><strong>⚠️ Warning:</strong> Review these tables carefully before deleting. Some might be used in ways not detected by this scan.</p>";
    echo "</div>";
    
    echo "<table border='1' cellpadding='10' style='border-collapse:collapse; width:100%;'>";
    echo "<tr style='background:#f0f0f0;'><th>Table Name</th><th>Row Count</th><th>Action</th></tr>";
    
    foreach ($unused_tables as $table) {
        // Get row count
        $count_result = $conn->query("SELECT COUNT(*) as cnt FROM `$table`");
        $count = 0;
        if ($count_result) {
            $row = $count_result->fetch_assoc();
            $count = $row['cnt'] ?? 0;
        }
        
        $row_color = $count > 0 ? '#ffe6e6' : '#e6ffe6';
        echo "<tr style='background:$row_color;'>";
        echo "<td><strong>$table</strong></td>";
        echo "<td>$count rows</td>";
        echo "<td>";
        if ($count > 0) {
            echo "<span style='color:red;'>⚠️ Contains data - review carefully!</span>";
        } else {
            echo "<span style='color:green;'>✓ Empty table - safe to delete</span>";
        }
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<br><p><strong>To delete these tables, use:</strong> <code>database/delete_unused_tables.php</code></p>";
}

$conn->close();
?>


