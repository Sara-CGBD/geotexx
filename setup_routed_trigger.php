<?php
/**
 * Setup script to create database trigger that automatically populates 'routed' table
 * when AGM approves QC test orders with roll_destination
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

// First, create the routed table
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

// Drop existing triggers if they exist
$conn->query("DROP TRIGGER IF EXISTS after_qc_test_order_approved");
$conn->query("DROP TRIGGER IF EXISTS after_qc_test_order_routed");

// Create trigger to automatically insert into routed table when QC test order is approved with destination
$triggerSQL1 = "
CREATE TRIGGER after_qc_test_order_approved
AFTER UPDATE ON qc_test_orders
FOR EACH ROW
BEGIN
    -- Only process if status changed to 'approved' and roll_destination is set
    IF NEW.status = 'approved' 
       AND NEW.roll_destination IS NOT NULL 
       AND NEW.roll_destination != ''
       AND (OLD.status != 'approved' OR OLD.roll_destination IS NULL OR OLD.roll_destination = '')
       AND NEW.sample_reference_id IS NOT NULL
       AND NEW.sample_reference_id != '' THEN
        
        -- Extract bundle reference from test_data JSON (for QC test orders with bulk references)
        SET @bundle_ref = NULL;
        IF NEW.test_data IS NOT NULL AND NEW.test_data != '' THEN
            -- Try to extract bulk_from_reference and bulk_to_reference from JSON
            SET @bulk_from = JSON_UNQUOTE(JSON_EXTRACT(NEW.test_data, '$.bulk_from_reference'));
            SET @bulk_to = JSON_UNQUOTE(JSON_EXTRACT(NEW.test_data, '$.bulk_to_reference'));
            IF @bulk_from IS NOT NULL AND @bulk_to IS NOT NULL AND @bulk_from != '' AND @bulk_to != '' THEN
                SET @bundle_ref = CONCAT(@bulk_from, '|', @bulk_to);
            END IF;
        END IF;
        
        -- If no bundle reference from JSON, check if sample_reference_id is part of a bundle (ends with -N)
        -- Extract base reference and find the bundle range
        IF @bundle_ref IS NULL AND NEW.sample_reference_id REGEXP '-[0-9]+$' THEN
            SET @base_ref = SUBSTRING_INDEX(NEW.sample_reference_id, '-', -1);
            SET @base_ref = SUBSTRING(NEW.sample_reference_id, 1, LENGTH(NEW.sample_reference_id) - LENGTH(@base_ref) - 1);
            
            -- Find the first and last roll in the bundle
            SELECT MIN(reference_number), MAX(reference_number) INTO @first_roll, @last_roll
            FROM roll_entry
            WHERE reference_number LIKE CONCAT(@base_ref, '-%')
            AND reference_number REGEXP CONCAT('^', REPLACE(@base_ref, '.', '\\.'), '-[0-9]+$');
            
            IF @first_roll IS NOT NULL AND @last_roll IS NOT NULL AND @first_roll != @last_roll THEN
                SET @bundle_ref = CONCAT(@first_roll, '|', @last_roll);
            END IF;
        END IF;
        
        -- Insert into routed table
        INSERT INTO routed (reference_number, bundle_reference, roll_destination, routed_by, routed_at)
        VALUES (NEW.sample_reference_id, @bundle_ref, NEW.roll_destination, COALESCE(NEW.approved_by, 'System'), NOW())
        ON DUPLICATE KEY UPDATE 
            bundle_reference = COALESCE(@bundle_ref, bundle_reference),
            roll_destination = NEW.roll_destination,
            routed_by = COALESCE(NEW.approved_by, routed_by),
            updated_at = NOW();
    END IF;
END";

// Create trigger for when roll_destination is set directly (via route_roll.php handler)
$triggerSQL2 = "
CREATE TRIGGER after_qc_test_order_routed
AFTER UPDATE ON qc_test_orders
FOR EACH ROW
BEGIN
    -- Process when roll_destination is set/changed (even if status was already approved)
    IF NEW.roll_destination IS NOT NULL 
       AND NEW.roll_destination != ''
       AND (OLD.roll_destination IS NULL OR OLD.roll_destination = '' OR OLD.roll_destination != NEW.roll_destination)
       AND NEW.status = 'approved'
       AND NEW.sample_reference_id IS NOT NULL
       AND NEW.sample_reference_id != '' THEN
        
        -- Extract bundle reference from test_data JSON (for QC test orders with bulk references)
        SET @bundle_ref = NULL;
        IF NEW.test_data IS NOT NULL AND NEW.test_data != '' THEN
            -- Try to extract bulk_from_reference and bulk_to_reference from JSON
            SET @bulk_from = JSON_UNQUOTE(JSON_EXTRACT(NEW.test_data, '$.bulk_from_reference'));
            SET @bulk_to = JSON_UNQUOTE(JSON_EXTRACT(NEW.test_data, '$.bulk_to_reference'));
            IF @bulk_from IS NOT NULL AND @bulk_to IS NOT NULL AND @bulk_from != '' AND @bulk_to != '' THEN
                SET @bundle_ref = CONCAT(@bulk_from, '|', @bulk_to);
            END IF;
        END IF;
        
        -- If no bundle reference from JSON, check if sample_reference_id is part of a bundle (ends with -N)
        -- Extract base reference and find the bundle range
        IF @bundle_ref IS NULL AND NEW.sample_reference_id REGEXP '-[0-9]+$' THEN
            SET @base_ref = SUBSTRING_INDEX(NEW.sample_reference_id, '-', -1);
            SET @base_ref = SUBSTRING(NEW.sample_reference_id, 1, LENGTH(NEW.sample_reference_id) - LENGTH(@base_ref) - 1);
            
            -- Find the first and last roll in the bundle
            SELECT MIN(reference_number), MAX(reference_number) INTO @first_roll, @last_roll
            FROM roll_entry
            WHERE reference_number LIKE CONCAT(@base_ref, '-%')
            AND reference_number REGEXP CONCAT('^', REPLACE(@base_ref, '.', '\\.'), '-[0-9]+$');
            
            IF @first_roll IS NOT NULL AND @last_roll IS NOT NULL AND @first_roll != @last_roll THEN
                SET @bundle_ref = CONCAT(@first_roll, '|', @last_roll);
            END IF;
        END IF;
        
        -- Insert into routed table
        INSERT INTO routed (reference_number, bundle_reference, roll_destination, routed_by, routed_at)
        VALUES (NEW.sample_reference_id, @bundle_ref, NEW.roll_destination, COALESCE(NEW.approved_by, 'System'), NOW())
        ON DUPLICATE KEY UPDATE 
            bundle_reference = COALESCE(@bundle_ref, bundle_reference),
            roll_destination = NEW.roll_destination,
            routed_by = COALESCE(NEW.approved_by, routed_by),
            updated_at = NOW();
    END IF;
END";

if ($conn->query($triggerSQL1)) {
    echo "✅ Trigger 'after_qc_test_order_approved' created successfully!\n";
} else {
    echo "❌ Error creating trigger 1: " . $conn->error . "\n";
    echo "Note: MySQL triggers may require DELIMITER changes. If this fails, you may need to run the trigger SQL manually in phpMyAdmin.\n";
}

if ($conn->query($triggerSQL2)) {
    echo "✅ Trigger 'after_qc_test_order_routed' created successfully!\n";
} else {
    echo "❌ Error creating trigger 2: " . $conn->error . "\n";
    echo "Note: MySQL triggers may require DELIMITER changes. If this fails, you may need to run the trigger SQL manually in phpMyAdmin.\n";
}

// Create triggers for other test tables (water_permeability_tests, characteristics_tests, sun_test_reports, weathering_exposure_reports)
// These tables have bundle_reference field that should be used directly

$test_tables = [
    'water_permeability_tests' => 'reference_number',
    'characteristics_tests' => 'reference_number',
    'sun_test_reports' => 'reference_number',
    'weathering_exposure_reports' => 'reference'
];

foreach ($test_tables as $table => $ref_column) {
    // Drop existing triggers
    $conn->query("DROP TRIGGER IF EXISTS after_{$table}_approved");
    $conn->query("DROP TRIGGER IF EXISTS after_{$table}_routed");
    
    // Check if bundle_reference column exists
    $checkBundle = $conn->query("SHOW COLUMNS FROM $table LIKE 'bundle_reference'");
    $hasBundleRef = ($checkBundle && $checkBundle->num_rows > 0);
    
    // Check if roll_destination column exists
    $checkRollDest = $conn->query("SHOW COLUMNS FROM $table LIKE 'roll_destination'");
    $hasRollDest = ($checkRollDest && $checkRollDest->num_rows > 0);
    
    if (!$hasRollDest) {
        echo "⚠️  Table $table does not have roll_destination column. Skipping trigger creation.\n";
        continue;
    }
    
    // Trigger for when status changes to approved
    $triggerSQL3 = "
    CREATE TRIGGER after_{$table}_approved
    AFTER UPDATE ON $table
    FOR EACH ROW
    BEGIN
        -- Only process if status changed to 'approved' and roll_destination is set
        IF NEW.status = 'approved' 
           AND NEW.roll_destination IS NOT NULL 
           AND NEW.roll_destination != ''
           AND (OLD.status != 'approved' OR OLD.roll_destination IS NULL OR OLD.roll_destination = '')
           AND NEW.$ref_column IS NOT NULL
           AND NEW.$ref_column != '' THEN
            
            -- Use bundle_reference directly if available, otherwise NULL
            SET @bundle_ref = NULL;
            " . ($hasBundleRef ? "IF NEW.bundle_reference IS NOT NULL AND NEW.bundle_reference != '' THEN
                SET @bundle_ref = NEW.bundle_reference;
            END IF;" : "") . "
            
            -- Insert into routed table
            INSERT INTO routed (reference_number, bundle_reference, roll_destination, routed_by, routed_at)
            VALUES (NEW.$ref_column, @bundle_ref, NEW.roll_destination, COALESCE(NEW.approved_by, NEW.approver_name, 'System'), NOW())
            ON DUPLICATE KEY UPDATE 
                bundle_reference = COALESCE(@bundle_ref, bundle_reference),
                roll_destination = NEW.roll_destination,
                routed_by = COALESCE(NEW.approved_by, NEW.approver_name, routed_by),
                updated_at = NOW();
        END IF;
    END";
    
    // Trigger for when roll_destination is set directly
    $triggerSQL4 = "
    CREATE TRIGGER after_{$table}_routed
    AFTER UPDATE ON $table
    FOR EACH ROW
    BEGIN
        -- Process when roll_destination is set/changed (even if status was already approved)
        IF NEW.roll_destination IS NOT NULL 
           AND NEW.roll_destination != ''
           AND (OLD.roll_destination IS NULL OR OLD.roll_destination = '' OR OLD.roll_destination != NEW.roll_destination)
           AND NEW.status = 'approved'
           AND NEW.$ref_column IS NOT NULL
           AND NEW.$ref_column != '' THEN
            
            -- Use bundle_reference directly if available, otherwise NULL
            SET @bundle_ref = NULL;
            " . ($hasBundleRef ? "IF NEW.bundle_reference IS NOT NULL AND NEW.bundle_reference != '' THEN
                SET @bundle_ref = NEW.bundle_reference;
            END IF;" : "") . "
            
            -- Insert into routed table
            INSERT INTO routed (reference_number, bundle_reference, roll_destination, routed_by, routed_at)
            VALUES (NEW.$ref_column, @bundle_ref, NEW.roll_destination, COALESCE(NEW.approved_by, NEW.approver_name, 'System'), NOW())
            ON DUPLICATE KEY UPDATE 
                bundle_reference = COALESCE(@bundle_ref, bundle_reference),
                roll_destination = NEW.roll_destination,
                routed_by = COALESCE(NEW.approved_by, NEW.approver_name, routed_by),
                updated_at = NOW();
        END IF;
    END";
    
    if ($conn->query($triggerSQL3)) {
        echo "✅ Trigger 'after_{$table}_approved' created successfully!\n";
    } else {
        echo "❌ Error creating trigger for $table (approved): " . $conn->error . "\n";
    }
    
    if ($conn->query($triggerSQL4)) {
        echo "✅ Trigger 'after_{$table}_routed' created successfully!\n";
    } else {
        echo "❌ Error creating trigger for $table (routed): " . $conn->error . "\n";
    }
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
echo "📝 Summary:\n";
echo "   - The 'routed' table tracks references routed in QC Test Order (when AGM approves with destination)\n";
echo "   - Triggers automatically populate the 'routed' table when references are routed\n";
echo "   - The 4 test APIs (Characteristics, UV, Water, Sun) check the 'routed' table and exclude routed references from dropdowns\n";
echo "   - References that appear in the 'routed' table will NOT show in the 'from-to range' dropdowns of:\n";
echo "     • Characteristics Test\n";
echo "     • UV Test (Weathering Exposure Test)\n";
echo "     • Water Permeability Test\n";
echo "     • Sun Test Report\n";
$conn->close();
?>
