<?php
/**
 * Update material_type in fiber_entries, fiber_to_roll_entry, and roll_entry tables to always be "PP Stable Fiber"
 * Removes manufacturer names from brackets (e.g., "PP Stable Fiber (Natpet)" becomes "PP Stable Fiber")
 * 
 * Tables updated:
 * - fiber_entries
 * - fiber_to_roll_entry
 * - roll_entry
 * 
 * Access via: http://localhost/geotex/database/update_fiber_entry_material_type.php
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
    echo "<!DOCTYPE html><html><head><title>Update Fiber Entry Material Type</title>";
    echo "<style>body{font-family:Arial;padding:20px;background:#f5f5f5;}";
    echo ".container{max-width:800px;margin:0 auto;background:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}";
    echo "h1{color:#333;} .success{color:#27ae60;} .info{color:#3498db;} .warning{color:#f39c12;} .error{color:#e74c3c;}</style></head><body>";
    echo "<div class='container'><h1>Update Fiber Entry Material Type</h1>";
    echo "<p>Updating material_type to 'PP Stable Fiber' in fiber_entries, fiber_to_roll_entry, and roll_entry tables</p>";
    echo "<pre style='background:#f9f9f9;padding:15px;border-radius:4px;overflow-x:auto;'>";
    
    echo "Starting update...\n\n";
    
    $totalRowsUpdated = 0;
    $tables = ['fiber_entries', 'fiber_to_roll_entry', 'roll_entry'];
    
    foreach ($tables as $table) {
        echo "Processing table: $table\n";
        echo str_repeat("-", 60) . "\n";
        
        // Check if table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE '$table'");
        if (!$tableCheck || $tableCheck->num_rows == 0) {
            echo "<span class='warning'>⚠️  Table '$table' does not exist, skipping...</span>\n\n";
            continue;
        }
        
        // Check if material_type column exists
        $colCheck = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'material_type'");
        if (!$colCheck || $colCheck->num_rows == 0) {
            echo "<span class='warning'>⚠️  Column 'material_type' does not exist in table '$table', skipping...</span>\n\n";
            continue;
        }
        
        $tableRowsUpdated = 0;
        
        // Try REGEXP_REPLACE first (MySQL 8.0+)
        echo "1. Attempting to update using REGEXP_REPLACE...\n";
        $update1 = "UPDATE `$table` 
                    SET material_type = TRIM(REGEXP_REPLACE(material_type, '\\s*\\([^)]+\\)\\s*$', ''))
                    WHERE material_type LIKE '%PP Stable Fiber%' 
                    AND material_type LIKE '%(%'";
        
        $result1 = $conn->query($update1);
        $affected1 = $conn->affected_rows;
        
        if ($affected1 > 0) {
            echo "<span class='success'>✅ Updated $affected1 row(s) using REGEXP_REPLACE</span>\n";
            $tableRowsUpdated += $affected1;
        } else {
            echo "<span class='info'>ℹ️  No rows updated with REGEXP_REPLACE (may not be supported or no matches)</span>\n";
            echo "Trying alternative method using REPLACE...\n";
            
            // If REGEXP_REPLACE doesn't work, use REPLACE for specific patterns
            $patterns = [
                "PP Stable Fiber (Natpet)" => "PP Stable Fiber",
                "PP Stable Fiber (APT)" => "PP Stable Fiber",
                "PP Stable Fiber (Texofib)" => "PP Stable Fiber",
                "PP Stable Fiber (Hubei Botao)" => "PP Stable Fiber",
                "PP Stable Fiber (Jiangsu Botao)" => "PP Stable Fiber",
                "PP Stable Fiber (Taizhu Hailun)" => "PP Stable Fiber",
                "PP Stable Fiber (PSF)" => "PP Stable Fiber"
            ];
            
            $affected2 = 0;
            foreach ($patterns as $old => $new) {
                $update = "UPDATE `$table` 
                           SET material_type = REPLACE(material_type, '$old', '$new') 
                           WHERE material_type LIKE '%$old%'";
                $result = $conn->query($update);
                $affected2 += $conn->affected_rows;
            }
            
            if ($affected2 > 0) {
                echo "<span class='success'>✅ Updated $affected2 row(s) using REPLACE method</span>\n";
                $tableRowsUpdated += $affected2;
            } else {
                echo "<span class='info'>ℹ️  No rows needed updating (all material types are already 'PP Stable Fiber')</span>\n";
            }
        }
        
        // Also update any NULL or empty material_type to "PP Stable Fiber"
        echo "2. Updating NULL or empty material_type to 'PP Stable Fiber'...\n";
        $updateNull = "UPDATE `$table` 
                       SET material_type = 'PP Stable Fiber' 
                       WHERE (material_type IS NULL OR material_type = '')";
        $resultNull = $conn->query($updateNull);
        $affectedNull = $conn->affected_rows;
        
        if ($affectedNull > 0) {
            echo "<span class='success'>✅ Updated $affectedNull row(s) with NULL/empty material_type</span>\n";
            $tableRowsUpdated += $affectedNull;
        } else {
            echo "<span class='info'>ℹ️  No NULL or empty material_type values found</span>\n";
        }
        
        $totalRowsUpdated += $tableRowsUpdated;
        echo "\n";
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "<span class='success'><strong>Update completed!</strong></span>\n";
    echo "Tables processed: " . count($tables) . "\n";
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


