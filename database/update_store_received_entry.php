<?php
/**
 * Comprehensive Update script to:
 * 1. Change material_type to only "PP Stable Fiber" (remove manufacturer from brackets) in store_received_entries
 * 2. Update manufacturer_name to simplified short names in ALL tables
 * 
 * Tables updated:
 * - store_received_entries
 * - fiber_test_reports
 * - tenacity_fiber_reports
 * - cut_length_fiber_reports
 * - fineness_fiber_reports
 * - tenacity_yarn_reports
 * - sewing_thread_reports
 * 
 * Access via: http://localhost/geotex/database/update_store_received_entry.php
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
    echo "<!DOCTYPE html><html><head><title>Update Store Received Entry</title>";
    echo "<style>body{font-family:Arial;padding:20px;background:#f5f5f5;}";
    echo ".container{max-width:800px;margin:0 auto;background:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}";
    echo "h1{color:#333;} .success{color:#27ae60;} .info{color:#3498db;} .warning{color:#f39c12;} .error{color:#e74c3c;}</style></head><body>";
    echo "<div class='container'><h1>Update: Store Received Entry Data</h1>";
    echo "<pre style='background:#f9f9f9;padding:15px;border-radius:4px;overflow-x:auto;'>";
    
    echo "Starting comprehensive update...\n\n";
    
    $totalRowsUpdated = 0;
    $tablesProcessed = 0;
    
    // List of all tables with manufacturer_name column
    $tablesToUpdate = [
        'store_received_entries',
        'fiber_test_reports',
        'tenacity_fiber_reports',
        'cut_length_fiber_reports',
        'fineness_fiber_reports',
        'tenacity_yarn_reports',
        'sewing_thread_reports'
    ];
    
    // 1. Update material_type in store_received_entries: Remove manufacturer from brackets
    echo "1. Updating material_type in store_received_entries table...\n";
    $tableCheck = $conn->query("SHOW TABLES LIKE 'store_received_entries'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $colCheck = $conn->query("SHOW COLUMNS FROM store_received_entries LIKE 'material_type'");
        if ($colCheck && $colCheck->num_rows > 0) {
            // Try REGEXP_REPLACE first
            $update1 = "UPDATE store_received_entries 
                        SET material_type = TRIM(REGEXP_REPLACE(material_type, '\\s*\\([^)]+\\)\\s*$', ''))
                        WHERE material_type LIKE '%(%'";
            $result1 = $conn->query($update1);
            $affected1 = $conn->affected_rows;
            
            // If REGEXP_REPLACE doesn't work, use REPLACE for specific patterns
            if ($affected1 == 0) {
                $patterns = [
                    "PP Stable Fiber (Natpet)" => "PP Stable Fiber",
                    "PP Stable Fiber (APT)" => "PP Stable Fiber",
                    "PP Stable Fiber (Texofib)" => "PP Stable Fiber",
                    "PP Stable Fiber (Hubei Botao)" => "PP Stable Fiber",
                    "PP Stable Fiber (Jiangsu Botao)" => "PP Stable Fiber",
                    "PP Stable Fiber (Taizhu Hailun)" => "PP Stable Fiber",
                    "PP Stable Fiber (PSF)" => "PP Stable Fiber"
                ];
                
                foreach ($patterns as $old => $new) {
                    $update = "UPDATE store_received_entries SET material_type = REPLACE(material_type, '$old', '$new') WHERE material_type LIKE '%$old%'";
                    $conn->query($update);
                    $affected1 += $conn->affected_rows;
                }
            }
            
            if ($affected1 > 0) {
                echo "<span class='success'>✅ Updated $affected1 row(s) in store_received_entries - removed manufacturer from material_type</span>\n";
                $totalRowsUpdated += $affected1;
            } else {
                echo "<span class='info'>ℹ️  No rows needed updating for material_type in store_received_entries</span>\n";
            }
        }
    }
    echo "\n";
    
    // 2. Update manufacturer_name in ALL tables: Change to simplified short names
    echo "2. Updating manufacturer_name to simplified short names in all tables...\n\n";
    
    $manufacturerMappings = [
        'Natpet Industries Ltd.' => 'Natpet',
        'APT Polymer Ltd.' => 'APT',
        'Texofib Industries' => 'Texofib',
        'Hubei Botao Plastic Co., Ltd.' => 'Hubei Botao',
        'Jiangsu Botao New Materials Co., Ltd.' => 'Jiangsu Botao',
        'Taizhu Hailun Chemical Fiber Co., Ltd.' => 'Taizhu Hailun',
        'PSF Manufacturing Ltd.' => 'PSF'
    ];
    
    foreach ($tablesToUpdate as $table) {
        $tableCheck = $conn->query("SHOW TABLES LIKE '$table'");
        if (!$tableCheck || $tableCheck->num_rows == 0) {
            echo "<span class='warning'>⚠️  Table '$table' does not exist, skipping...</span>\n";
            continue;
        }
        
        $colCheck = $conn->query("SHOW COLUMNS FROM `$table` LIKE 'manufacturer_name'");
        if (!$colCheck || $colCheck->num_rows == 0) {
            echo "<span class='warning'>⚠️  Column 'manufacturer_name' does not exist in table '$table', skipping...</span>\n";
            continue;
        }
        
        $tableAffected = 0;
        foreach ($manufacturerMappings as $old => $new) {
            $update = "UPDATE `$table` 
                       SET manufacturer_name = REPLACE(manufacturer_name, '$old', '$new') 
                       WHERE manufacturer_name LIKE '%$old%'";
            $result = $conn->query($update);
            $tableAffected += $conn->affected_rows;
        }
        
        if ($tableAffected > 0) {
            echo "<span class='success'>✅ Updated $tableAffected row(s) in table '$table'</span>\n";
            $totalRowsUpdated += $tableAffected;
            $tablesProcessed++;
        } else {
            echo "<span class='info'>ℹ️  No rows needed updating in table '$table'</span>\n";
        }
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "<span class='success'><strong>Update completed!</strong></span>\n";
    echo "Tables processed: " . count($tablesToUpdate) . "\n";
    echo "Tables with updates: $tablesProcessed\n";
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


