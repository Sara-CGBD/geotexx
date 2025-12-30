<?php
/**
 * Update script to change "Gocil" to "GEOCIL" in roll_size fields across all tables
 * Run this once to update existing database records
 * 
 * Access via: http://localhost/geotex/database/update_gocil_to_geocil.php
 */

session_start();
require_once __DIR__ . '/../config/security_config.php';

// Check if user is logged in (optional - can remove if you want to run without login)
if (!isset($_SESSION['user_id'])) {
    die("Please login first to run this update script.");
}

try {
    $conn = SecurityConfig::getConnection();
    
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>Update Gocil to GEOCIL</title>";
    echo "<style>body{font-family:Arial;padding:20px;background:#f5f5f5;}";
    echo ".container{max-width:800px;margin:0 auto;background:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}";
    echo "h1{color:#333;} .success{color:#27ae60;} .info{color:#3498db;} .warning{color:#f39c12;} .error{color:#e74c3c;}</style></head><body>";
    echo "<div class='container'><h1>Update: Changing 'Gocil' to 'GEOCIL'</h1>";
    echo "<pre style='background:#f9f9f9;padding:15px;border-radius:4px;overflow-x:auto;'>";
    
    echo "Starting update: Changing 'Gocil' to 'GEOCIL' in roll_size fields...\n\n";
    
    $tablesUpdated = 0;
    $totalRowsUpdated = 0;
    
    // List of tables and columns that might contain roll_size data
    $tablesToUpdate = [
        ['table' => 'roll_entry', 'column' => 'roll_size'],
        ['table' => 'fg_entry', 'column' => 'bag_size'], // bag_size is used for roll_size in FG entry (when product_type = 'roll')
        ['table' => 'roll_received', 'column' => 'roll_size'],
    ];
    
    foreach ($tablesToUpdate as $item) {
        $table = $item['table'];
        $column = $item['column'];
        
        // Check if table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE '$table'");
        if (!$tableCheck || $tableCheck->num_rows == 0) {
            echo "<span class='warning'>⚠️  Table '$table' does not exist, skipping...</span>\n";
            continue;
        }
        
        // Check if column exists
        $columnCheck = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if (!$columnCheck || $columnCheck->num_rows == 0) {
            echo "<span class='warning'>⚠️  Column '$column' does not exist in table '$table', skipping...</span>\n";
            continue;
        }
        
        // For fg_entry, only update bag_size where product_type = 'roll'
        $whereClause = "(`$column` LIKE '%Gocil%' OR `$column` LIKE '%GEOCIL-100 4X60 MTR)%' OR `$column` LIKE '%GEOCIL 110 14X60 MTRI%' OR `$column` LIKE '%GEOCIL-110 (14X60 MTR)%')";
        if ($table === 'fg_entry' && $column === 'bag_size') {
            $whereClause = "(`$column` LIKE '%Gocil%' OR `$column` LIKE '%GEOCIL-100 4X60 MTR)%' OR `$column` LIKE '%GEOCIL 110 14X60 MTRI%' OR `$column` LIKE '%GEOCIL-110 (14X60 MTR)%') AND product_type = 'roll'";
        }
        
        // Count rows that need updating
        $countQuery = "SELECT COUNT(*) as count FROM `$table` WHERE $whereClause";
        $countResult = $conn->query($countQuery);
        $count = 0;
        if ($countResult) {
            $countRow = $countResult->fetch_assoc();
            $count = $countRow['count'];
        }
        
        if ($count > 0) {
            // Update Gocil-50 to GEOCIL-50
            $update1 = "UPDATE `$table` SET `$column` = REPLACE(`$column`, 'Gocil-50 (4X100MTR)', 'GEOCIL-50 (4X100MTR)') WHERE $whereClause";
            $result1 = $conn->query($update1);
            $affected1 = $conn->affected_rows;
            
            // Update Gocil-60 to GEOCIL-60
            $update2 = "UPDATE `$table` SET `$column` = REPLACE(`$column`, 'Gocil-60 (4X100MTR)', 'GEOCIL-60 (4X100MTR)') WHERE $whereClause";
            $result2 = $conn->query($update2);
            $affected2 = $conn->affected_rows;
            
            // Fix GEOCIL-100 format: "GEOCIL-100 4X60 MTR)" to "GEOCIL-100 (4X60 MTR)"
            $update5 = "UPDATE `$table` SET `$column` = REPLACE(`$column`, 'GEOCIL-100 4X60 MTR)', 'GEOCIL-100 (4X60 MTR)') WHERE `$column` LIKE '%GEOCIL-100%4X60%'";
            if ($table === 'fg_entry' && $column === 'bag_size') {
                $update5 = "UPDATE `$table` SET `$column` = REPLACE(`$column`, 'GEOCIL-100 4X60 MTR)', 'GEOCIL-100 (4X60 MTR)') WHERE `$column` LIKE '%GEOCIL-100%4X60%' AND product_type = 'roll'";
            }
            $result5 = $conn->query($update5);
            $affected5 = $conn->affected_rows;
            
            // Fix GEOCIL 110 format: "GEOCIL 110 14X60 MTRI" to "GEOCIL-110 (4X60 MTR)"
            $update6 = "UPDATE `$table` SET `$column` = REPLACE(`$column`, 'GEOCIL 110 14X60 MTRI', 'GEOCIL-110 (4X60 MTR)') WHERE `$column` LIKE '%GEOCIL%110%14X60%'";
            if ($table === 'fg_entry' && $column === 'bag_size') {
                $update6 = "UPDATE `$table` SET `$column` = REPLACE(`$column`, 'GEOCIL 110 14X60 MTRI', 'GEOCIL-110 (4X60 MTR)') WHERE `$column` LIKE '%GEOCIL%110%14X60%' AND product_type = 'roll'";
            }
            $result6 = $conn->query($update6);
            $affected6 = $conn->affected_rows;
            
            // Also fix "GEOCIL-110 (14X60 MTR)" to "GEOCIL-110 (4X60 MTR)" if it exists
            $update7 = "UPDATE `$table` SET `$column` = REPLACE(`$column`, 'GEOCIL-110 (14X60 MTR)', 'GEOCIL-110 (4X60 MTR)') WHERE `$column` LIKE '%GEOCIL-110%14X60%'";
            if ($table === 'fg_entry' && $column === 'bag_size') {
                $update7 = "UPDATE `$table` SET `$column` = REPLACE(`$column`, 'GEOCIL-110 (14X60 MTR)', 'GEOCIL-110 (4X60 MTR)') WHERE `$column` LIKE '%GEOCIL-110%14X60%' AND product_type = 'roll'";
            }
            $result7 = $conn->query($update7);
            $affected7 = $conn->affected_rows;
            
            // Also handle any other variations (case-insensitive)
            $update3 = "UPDATE `$table` SET `$column` = REPLACE(`$column`, 'Gocil-50', 'GEOCIL-50') WHERE `$column` LIKE '%Gocil-50%'";
            if ($table === 'fg_entry' && $column === 'bag_size') {
                $update3 = "UPDATE `$table` SET `$column` = REPLACE(`$column`, 'Gocil-50', 'GEOCIL-50') WHERE `$column` LIKE '%Gocil-50%' AND product_type = 'roll'";
            }
            $result3 = $conn->query($update3);
            $affected3 = $conn->affected_rows;
            
            $update4 = "UPDATE `$table` SET `$column` = REPLACE(`$column`, 'Gocil-60', 'GEOCIL-60') WHERE `$column` LIKE '%Gocil-60%'";
            if ($table === 'fg_entry' && $column === 'bag_size') {
                $update4 = "UPDATE `$table` SET `$column` = REPLACE(`$column`, 'Gocil-60', 'GEOCIL-60') WHERE `$column` LIKE '%Gocil-60%' AND product_type = 'roll'";
            }
            $result4 = $conn->query($update4);
            $affected4 = $conn->affected_rows;
            
            $totalAffected = max($affected1, $affected2, $affected3, $affected4, $affected5, $affected6, $affected7);
            
            if ($totalAffected > 0) {
                echo "<span class='success'>✅ Updated $totalAffected row(s) in table '$table', column '$column'</span>\n";
                $tablesUpdated++;
                $totalRowsUpdated += $totalAffected;
            } else {
                echo "<span class='info'>ℹ️  No rows updated in table '$table', column '$column' (found $count matching rows but update may have already been done)</span>\n";
            }
        } else {
            echo "<span class='info'>ℹ️  No rows found with 'Gocil' in table '$table', column '$column'</span>\n";
        }
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "<span class='success'><strong>Update completed!</strong></span>\n";
    echo "Tables updated: $tablesUpdated\n";
    echo "Total rows updated: $totalRowsUpdated\n";
    echo str_repeat("=", 60) . "\n";
    echo "</pre>";
    echo "<p><a href='../index.php'>← Back to Dashboard</a></p>";
    echo "</div></body></html>";
    
} catch (Exception $e) {
    echo "<span class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    echo "</pre></div></body></html>";
    exit(1);
}


