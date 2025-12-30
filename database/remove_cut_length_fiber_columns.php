<?php
/**
 * Remove columns from cut_length_fiber_reports table:
 * - sample_collected_from
 * - sample_received_from
 * - reference
 * - others_information
 * - test_temperature
 * - rh_percent
 * 
 * Access via: http://localhost/geotex/database/remove_cut_length_fiber_columns.php
 */

session_start();
require_once __DIR__ . '/../config/security_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    die("Please login first to run this update script.");
}

try {
    $conn = SecurityConfig::getConnection();
    
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>Remove Cut Length Fiber Columns</title>";
    echo "<style>body{font-family:Arial;padding:20px;background:#f5f5f5;}";
    echo ".container{max-width:800px;margin:0 auto;background:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}";
    echo "h1{color:#333;} .success{color:#27ae60;} .info{color:#3498db;} .warning{color:#f39c12;} .error{color:#e74c3c;}</style></head><body>";
    echo "<div class='container'><h1>Remove Columns from Cut Length Fiber Reports</h1>";
    echo "<pre style='background:#f9f9f9;padding:15px;border-radius:4px;overflow-x:auto;'>";
    
    echo "Starting removal of columns...\n\n";
    
    $totalColumnsRemoved = 0;
    $table = 'cut_length_fiber_reports';
    $columnsToRemove = [
        'sample_collected_from',
        'sample_received_from',
        'reference',
        'others_information',
        'test_temperature',
        'rh_percent'
    ];
    
    $tableCheck = $conn->query("SHOW TABLES LIKE '$table'");
    if (!$tableCheck || $tableCheck->num_rows == 0) {
        echo "<span class='warning'>⚠️  Table '$table' does not exist, skipping...</span>\n";
        echo "</pre><p><a href='../index.php'>← Back to Dashboard</a></p></div></body></html>";
        exit;
    }
    
    foreach ($columnsToRemove as $column) {
        $colCheck = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if (!$colCheck || $colCheck->num_rows == 0) {
            echo "<span class='info'>ℹ️  Column '$column' does not exist in table '$table', skipping...</span>\n";
            continue;
        }
        
        // Drop the column
        $dropQuery = "ALTER TABLE `$table` DROP COLUMN `$column`";
        $result = $conn->query($dropQuery);
        
        if ($result) {
            echo "<span class='success'>✅ Removed '$column' column from table '$table'</span>\n";
            $totalColumnsRemoved++;
        } else {
            echo "<span class='error'>❌ Failed to remove '$column' column from table '$table': " . $conn->error . "</span>\n";
        }
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "<span class='success'><strong>Update completed!</strong></span>\n";
    echo "Columns processed: " . count($columnsToRemove) . "\n";
    echo "Columns removed: $totalColumnsRemoved\n";
    echo str_repeat("=", 60) . "\n";
    echo "</pre>";
    echo "<p><a href='../index.php'>← Back to Dashboard</a></p>";
    echo "</div></body></html>";
    
} catch (Exception $e) {
    echo "<span class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    echo "</pre></div></body></html>";
    exit(1);
}


