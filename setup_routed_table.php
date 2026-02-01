<?php
/**
 * Setup script to create the 'routed' table for tracking AGM-routed references
 * This table stores all references that have been routed by AGM with their destinations
 */

// Try both possible paths
if (file_exists('config/security_config.php')) {
    require_once 'config/security_config.php';
} elseif (file_exists('forms/security_config.php')) {
    require_once 'forms/security_config.php';
} else {
    die("Error: Could not find security_config.php");
}

$conn = SecurityConfig::getConnection();

if (!$conn) {
    die("Database connection failed");
}

// Create routed table
$createTable = "
CREATE TABLE IF NOT EXISTS routed (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reference_number VARCHAR(200) NOT NULL,
    bundle_reference VARCHAR(200) NULL,
    roll_destination VARCHAR(50) NOT NULL,
    routed_by VARCHAR(255) NOT NULL,
    routed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_reference_number (reference_number),
    INDEX idx_bundle_reference (bundle_reference),
    INDEX idx_roll_destination (roll_destination),
    INDEX idx_routed_at (routed_at),
    UNIQUE KEY unique_reference_destination (reference_number, roll_destination)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

if ($conn->query($createTable)) {
    echo "✅ Table 'routed' created successfully!\n";
} else {
    echo "❌ Error creating table: " . $conn->error . "\n";
}

// Migrate existing routed references from qc_test_orders to routed table
echo "\n🔄 Migrating existing routed references from qc_test_orders...\n";

// Check if roll_destination column exists in qc_test_orders
$checkCol = $conn->query("SHOW COLUMNS FROM qc_test_orders LIKE 'roll_destination'");
if ($checkCol && $checkCol->num_rows > 0) {
    // Get all approved QC test orders with roll_destination set
    $migrateQuery = "
        SELECT DISTINCT 
            qto.sample_reference_id as reference_number,
            qto.roll_destination,
            qto.approved_by as routed_by,
            qto.approved_at as routed_at
        FROM qc_test_orders qto
        WHERE qto.status = 'approved'
        AND qto.roll_destination IS NOT NULL
        AND qto.roll_destination != ''
    ";
    
    $result = $conn->query($migrateQuery);
    if ($result) {
        $migrated = 0;
        $skipped = 0;
        
        while ($row = $result->fetch_assoc()) {
            $ref = $row['reference_number'];
            $destination = $row['roll_destination'];
            $routed_by = $row['routed_by'] ?? 'System';
            $routed_at = $row['routed_at'] ?? date('Y-m-d H:i:s');
            
            // Check if bundle reference (ends with -N pattern)
            $bundleRef = null;
            if (preg_match('/-(\d+)$/', $ref, $matches)) {
                $baseRef = preg_replace('/-\d+$/', '', $ref);
                // Find the bundle reference
                $bundleQuery = $conn->prepare("
                    SELECT reference_number 
                    FROM roll_entry 
                    WHERE reference_number LIKE CONCAT(?, '-%')
                    AND reference_number REGEXP '-[0-9]+$'
                    ORDER BY LENGTH(reference_number) DESC, reference_number DESC
                    LIMIT 1
                ");
                $likePattern = $baseRef . '-%';
                $bundleQuery->bind_param("s", $likePattern);
                $bundleQuery->execute();
                $bundleResult = $bundleQuery->get_result();
                if ($bundleRow = $bundleResult->fetch_assoc()) {
                    $bundleRef = $bundleRow['reference_number'];
                }
                $bundleQuery->close();
            }
            
            // Insert into routed table (ignore duplicates)
            $insertStmt = $conn->prepare("
                INSERT INTO routed (reference_number, bundle_reference, roll_destination, routed_by, routed_at)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    roll_destination = VALUES(roll_destination),
                    routed_by = VALUES(routed_by),
                    updated_at = NOW()
            ");
            $insertStmt->bind_param("sssss", $ref, $bundleRef, $destination, $routed_by, $routed_at);
            
            if ($insertStmt->execute()) {
                $migrated++;
            } else {
                $skipped++;
            }
            $insertStmt->close();
        }
        
        echo "✅ Migrated $migrated existing routed references\n";
        if ($skipped > 0) {
            echo "⚠️  Skipped $skipped references (duplicates or errors)\n";
        }
    } else {
        echo "⚠️  Could not query qc_test_orders for migration: " . $conn->error . "\n";
    }
} else {
    echo "ℹ️  roll_destination column does not exist in qc_test_orders yet. No migration needed.\n";
}

echo "\n✅ Setup complete!\n";
$conn->close();
?>
