<?php
/**
 * Direct SQL cleanup script to remove auto-generated fiber_entries
 * Run this once to clean up the database
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/security_config.php';

try {
    $conn = SecurityConfig::getConnection();
    
    if (!$conn) {
        die("Failed to connect to database\n");
    }
    
    echo "Cleaning up auto-generated fiber entries...\n\n";

    // Find entries to delete
    $checkQuery = "
        SELECT 
            id,
            entry_code,
            reference,
            shift_incharge,
            summary
        FROM fiber_entries
        WHERE (shift_incharge = 'System' OR summary LIKE '%Auto-added%')
        AND is_deleted = 0
    ";

    $result = $conn->query($checkQuery);

    if ($result && $result->num_rows > 0) {
        echo "Found {$result->num_rows} auto-generated entries:\n";
        
        $idsToDelete = [];
        while ($row = $result->fetch_assoc()) {
            $idsToDelete[] = $row['id'];
            echo "  - ID: {$row['id']}, Entry Code: {$row['entry_code']}, Reference: {$row['reference']}\n";
        }
        
        echo "\nDeleting entries...\n";
        
        // Soft delete (mark as deleted)
        $deletedCount = 0;
        foreach ($idsToDelete as $id) {
            $deleteStmt = $conn->prepare("UPDATE fiber_entries SET is_deleted = 1, deleted_at = NOW() WHERE id = ?");
            $deleteStmt->bind_param("i", $id);
            if ($deleteStmt->execute()) {
                $deletedCount++;
                echo "  ✓ Deleted entry ID: {$id}\n";
            } else {
                echo "  ✗ Failed to delete entry ID: {$id}: " . $conn->error . "\n";
            }
            $deleteStmt->close();
        }
        
        echo "\n✓ Successfully deleted {$deletedCount} auto-generated entries.\n";
    } else {
        echo "✓ No auto-generated entries found. Database is clean!\n";
    }

    $conn->close();
    echo "\nDone!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>


