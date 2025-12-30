<?php
/**
 * Update FG Entry Database Fields
 * 
 * This script updates existing fg_entry records:
 * - For ROLLS: 
 *   - Form field: "total_weight" (stored in DB column: actual_weight)
 *   - Ensures actual_weight is populated when measurement_type = 'weight'
 *   - Ensures total_area is populated when measurement_type = 'area'
 * - For BAGS:
 *   - Form field: "actual_weight_bag" (stored in DB column: actual_weight)
 *   - Ensures actual_weight is populated
 * 
 * Note: Both rolls and bags use the same 'actual_weight' column in the database,
 * but the form uses different field names for clarity.
 */

require_once __DIR__ . '/../config/SecurityConfig.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Update FG Entry Fields</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 4px; border-left: 4px solid #007bff; overflow-x: auto; }
        .success { color: #28a745; font-weight: bold; }
        .warning { color: #ffc107; font-weight: bold; }
        .error { color: #dc3545; font-weight: bold; }
        .info { color: #17a2b8; }
        a { color: #007bff; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Update FG Entry Database Fields</h1>
        <pre>
<?php

try {
    $conn = SecurityConfig::getConnection();
    
    if (!$conn) {
        throw new Exception("Database connection failed");
    }
    
    echo "Starting update...\n\n";
    
    $totalRowsUpdated = 0;
    $rollsUpdated = 0;
    $bagsUpdated = 0;
    
    // Check if table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE 'fg_entry'");
    if (!$tableCheck || $tableCheck->num_rows == 0) {
        echo "<span class='warning'>⚠️  Table 'fg_entry' does not exist, skipping...</span>\n";
        echo "</pre><p><a href='../index.php'>← Back to Dashboard</a></p></div></body></html>";
        exit;
    }
    
    // Check if columns exist
    $colCheck = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'product_type'");
    if (!$colCheck || $colCheck->num_rows == 0) {
        echo "<span class='warning'>⚠️  Column 'product_type' does not exist, skipping...</span>\n";
        echo "</pre><p><a href='../index.php'>← Back to Dashboard</a></p></div></body></html>";
        exit;
    }
    
    // Ensure total_area column exists for rolls
    $colCheckTotalArea = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'total_area'");
    if (!$colCheckTotalArea || $colCheckTotalArea->num_rows == 0) {
        echo "Adding 'total_area' column...\n";
        $conn->query("ALTER TABLE fg_entry ADD COLUMN total_area DECIMAL(10,2) NULL AFTER actual_weight");
        echo "<span class='success'>✅ Added 'total_area' column</span>\n\n";
    }
    
    // Ensure measurement_type column exists
    $colCheckMeasurementType = $conn->query("SHOW COLUMNS FROM fg_entry LIKE 'measurement_type'");
    if (!$colCheckMeasurementType || $colCheckMeasurementType->num_rows == 0) {
        echo "Adding 'measurement_type' column...\n";
        $conn->query("ALTER TABLE fg_entry ADD COLUMN measurement_type VARCHAR(20) NULL AFTER actual_weight");
        echo "<span class='success'>✅ Added 'measurement_type' column</span>\n\n";
    }
    
    // ========== UPDATE ROLLS ==========
    echo "1. Updating ROLL entries...\n";
    echo str_repeat("-", 60) . "\n";
    echo "<span class='info'>ℹ️  Note: Rolls use 'total_weight' field in form (stored as 'actual_weight' in DB)</span>\n\n";
    
    // For rolls: Ensure total_area is used when measurement_type = 'area'
    // For rolls: Ensure actual_weight (form field: total_weight) is used when measurement_type = 'weight'
    
    // First, set measurement_type for rolls that don't have it
    // If roll has actual_weight > 0, set measurement_type = 'weight'
    // If roll has total_area > 0, set measurement_type = 'area'
    $rollSetMeasurementType = "UPDATE fg_entry 
                               SET measurement_type = CASE 
                                   WHEN total_area IS NOT NULL AND total_area > 0 THEN 'area'
                                   WHEN actual_weight IS NOT NULL AND actual_weight > 0 THEN 'weight'
                                   ELSE 'weight'
                               END
                               WHERE product_type = 'roll' 
                                 AND (measurement_type IS NULL OR measurement_type = '')";
    
    $rollSetResult = $conn->query($rollSetMeasurementType);
    $rollSetAffected = $conn->affected_rows;
    
    if ($rollSetAffected > 0) {
        echo "<span class='success'>✅ Set measurement_type for $rollSetAffected roll(s) based on existing data</span>\n\n";
        $rollsUpdated += $rollSetAffected;
        $totalRowsUpdated += $rollSetAffected;
    } else {
        echo "<span class='info'>ℹ️  All rolls already have measurement_type set.</span>\n\n";
    }
    
    // For rolls with measurement_type = 'area', ensure total_area is populated
    // If they have actual_weight but no total_area, we can't calculate it, so we'll note it
    $rollAreaCheck = "SELECT COUNT(*) as count FROM fg_entry 
                      WHERE product_type = 'roll' 
                        AND measurement_type = 'area' 
                        AND (total_area IS NULL OR total_area = 0)";
    
    $rollAreaCheckResult = $conn->query($rollAreaCheck);
    $rollAreaCheckRow = $rollAreaCheckResult->fetch_assoc();
    $rollsNeedingArea = $rollAreaCheckRow['count'];
    
    if ($rollsNeedingArea > 0) {
        echo "<span class='warning'>⚠️  Found $rollsNeedingArea roll(s) with measurement_type='area' but missing total_area.</span>\n";
        echo "<span class='info'>ℹ️  Note: total_area cannot be calculated from existing data. Please update manually if needed.</span>\n\n";
    } else {
        echo "<span class='info'>ℹ️  All rolls with measurement_type='area' have total_area populated.</span>\n\n";
    }
    
    // For rolls with measurement_type = 'weight', ensure actual_weight (form field: total_weight) is populated
    $rollWeightCheck = "SELECT COUNT(*) as count FROM fg_entry 
                         WHERE product_type = 'roll' 
                           AND measurement_type = 'weight' 
                           AND (actual_weight IS NULL OR actual_weight = 0)";
    
    $rollWeightCheckResult = $conn->query($rollWeightCheck);
    $rollWeightCheckRow = $rollWeightCheckResult->fetch_assoc();
    $rollsNeedingWeight = $rollWeightCheckRow['count'];
    
    if ($rollsNeedingWeight > 0) {
        echo "<span class='warning'>⚠️  Found $rollsNeedingWeight roll(s) with measurement_type='weight' but missing total_weight (stored as actual_weight in DB).</span>\n";
        echo "<span class='info'>ℹ️  Note: total_weight cannot be calculated from existing data. Please update manually if needed.</span>\n\n";
    } else {
        echo "<span class='info'>ℹ️  All rolls with measurement_type='weight' have total_weight (actual_weight in DB) populated.</span>\n\n";
    }
    
    // ========== UPDATE BAGS ==========
    echo "\n2. Updating BAG entries...\n";
    echo str_repeat("-", 60) . "\n";
    echo "<span class='info'>ℹ️  Note: Bags use 'actual_weight_bag' field in form (stored as 'actual_weight' in DB)</span>\n\n";
    
    // For bags, ensure actual_weight (form field: actual_weight_bag) is populated
    // Check for bags with NULL or 0 actual_weight
    $bagCheckQuery = "SELECT COUNT(*) as count FROM fg_entry 
                      WHERE product_type = 'bag' 
                        AND (actual_weight IS NULL OR actual_weight = 0)";
    
    $bagCheckResult = $conn->query($bagCheckQuery);
    $bagCheckRow = $bagCheckResult->fetch_assoc();
    $bagsNeedingUpdate = $bagCheckRow['count'];
    
    if ($bagsNeedingUpdate > 0) {
        echo "Found $bagsNeedingUpdate bag(s) with NULL or 0 actual_weight (form field: actual_weight_bag).\n";
        echo "<span class='info'>ℹ️  Note: actual_weight cannot be calculated from existing data. Please update manually if needed.</span>\n\n";
    } else {
        echo "<span class='info'>ℹ️  All bags have actual_weight (form field: actual_weight_bag) populated.</span>\n\n";
    }
    
    // Ensure bags have measurement_type set to NULL (bags don't use measurement_type)
    $bagMeasurementTypeQuery = "UPDATE fg_entry 
                                SET measurement_type = NULL
                                WHERE product_type = 'bag' 
                                  AND measurement_type IS NOT NULL";
    
    $bagMeasurementTypeResult = $conn->query($bagMeasurementTypeQuery);
    $bagMeasurementTypeAffected = $conn->affected_rows;
    
    if ($bagMeasurementTypeAffected > 0) {
        echo "<span class='success'>✅ Cleared measurement_type for $bagMeasurementTypeAffected bag(s) (bags don't use measurement_type)</span>\n\n";
        $bagsUpdated += $bagMeasurementTypeAffected;
        $totalRowsUpdated += $bagMeasurementTypeAffected;
    } else {
        echo "<span class='info'>ℹ️  No bags needed measurement_type clearing.</span>\n\n";
    }
    
    // ========== SUMMARY ==========
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "<span class='success'><strong>Update completed!</strong></span>\n";
    echo "Total rows updated: $totalRowsUpdated\n";
    echo "  - Rolls updated: $rollsUpdated\n";
    echo "  - Bags updated: $bagsUpdated\n";
    echo str_repeat("=", 60) . "\n";
    
    // Show current statistics
    echo "\n3. Current Database Statistics:\n";
    echo str_repeat("-", 60) . "\n";
    echo "<span class='info'>ℹ️  Field Mapping:</span>\n";
    echo "  - ROLLS: Form field 'total_weight' → DB column 'actual_weight'\n";
    echo "  - BAGS: Form field 'actual_weight_bag' → DB column 'actual_weight'\n";
    echo "  - ROLLS (area): Form field 'total_area' → DB column 'total_area'\n\n";
    
    $statsQuery = "SELECT 
                    product_type,
                    COUNT(*) as total,
                    SUM(CASE WHEN actual_weight IS NOT NULL AND actual_weight > 0 THEN 1 ELSE 0 END) as with_weight,
                    SUM(CASE WHEN total_area IS NOT NULL AND total_area > 0 THEN 1 ELSE 0 END) as with_total_area,
                    SUM(CASE WHEN measurement_type = 'weight' THEN 1 ELSE 0 END) as weight_type,
                    SUM(CASE WHEN measurement_type = 'area' THEN 1 ELSE 0 END) as area_type
                   FROM fg_entry
                   GROUP BY product_type";
    
    $statsResult = $conn->query($statsQuery);
    if ($statsResult) {
        echo sprintf("%-15s %-10s %-25s %-20s %-15s %-15s\n", 
                     "Product Type", "Total", "With Weight (DB)", "With Total Area", "Weight Type", "Area Type");
        echo str_repeat("-", 100) . "\n";
        while ($row = $statsResult->fetch_assoc()) {
            $weightLabel = ($row['product_type'] === 'roll') ? 'Total Weight' : 'Actual Weight';
            echo sprintf("%-15s %-10s %-25s %-20s %-15s %-15s\n",
                         $row['product_type'] ?? 'NULL',
                         $row['total'],
                         $row['with_weight'] . " ($weightLabel)",
                         $row['with_total_area'],
                         $row['weight_type'],
                         $row['area_type']);
        }
    }
    
} catch (Exception $e) {
    echo "<span class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</span>\n";
    echo "Stack trace:\n" . htmlspecialchars($e->getTraceAsString()) . "\n";
}

?>
        </pre>
        <p><a href="../index.php">← Back to Dashboard</a></p>
    </div>
</body>
</html>


