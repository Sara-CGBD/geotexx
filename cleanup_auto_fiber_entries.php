<?php
/**
 * Cleanup script to remove auto-generated fiber_entries
 * These entries were created automatically when tests were approved
 * but should not exist - users must submit the form manually
 */

require_once __DIR__ . '/config/security_config.php';

$conn = SecurityConfig::getConnection();

// First, let's see what auto-generated entries exist
echo "<h2>Finding Auto-Generated Fiber Entries</h2>";

// Check for entries with 'System' as shift_incharge (auto-generated indicator)
$checkQuery = "
    SELECT 
        id,
        entry_code,
        date_time,
        shift,
        shift_incharge,
        reference,
        amount_kg,
        material_type,
        summary,
        is_deleted
    FROM fiber_entries
    WHERE (shift_incharge = 'System' OR summary LIKE '%Auto-added%')
    AND is_deleted = 0
    ORDER BY date_time DESC
";

$result = $conn->query($checkQuery);

if ($result && $result->num_rows > 0) {
    echo "<p><strong>Found {$result->num_rows} auto-generated entries:</strong></p>";
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr>
            <th>ID</th>
            <th>Entry Code</th>
            <th>Date Time</th>
            <th>Shift</th>
            <th>Shift Incharge</th>
            <th>Reference</th>
            <th>Amount (kg)</th>
            <th>Material Type</th>
            <th>Summary</th>
          </tr>";
    
    $idsToDelete = [];
    while ($row = $result->fetch_assoc()) {
        $idsToDelete[] = $row['id'];
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>{$row['entry_code']}</td>";
        echo "<td>{$row['date_time']}</td>";
        echo "<td>{$row['shift']}</td>";
        echo "<td>{$row['shift_incharge']}</td>";
        echo "<td>{$row['reference']}</td>";
        echo "<td>{$row['amount_kg']}</td>";
        echo "<td>{$row['material_type']}</td>";
        echo "<td>" . htmlspecialchars($row['summary'] ?? '') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // If running via GET with ?delete=1, actually delete them
    if (isset($_GET['delete']) && $_GET['delete'] == '1') {
        echo "<hr><h2>Deleting Entries...</h2>";
        
        $deletedCount = 0;
        foreach ($idsToDelete as $id) {
            // Soft delete by setting is_deleted = 1
            $deleteStmt = $conn->prepare("UPDATE fiber_entries SET is_deleted = 1, deleted_at = NOW() WHERE id = ?");
            $deleteStmt->bind_param("i", $id);
            if ($deleteStmt->execute()) {
                $deletedCount++;
                echo "<p>✓ Deleted entry ID: {$id}</p>";
            } else {
                echo "<p>✗ Failed to delete entry ID: {$id}</p>";
            }
            $deleteStmt->close();
        }
        
        echo "<p><strong>Successfully deleted {$deletedCount} entries.</strong></p>";
        echo "<p><a href='cleanup_auto_fiber_entries.php'>View remaining entries</a></p>";
    } else {
        echo "<hr>";
        echo "<p><strong>To delete these entries, click the link below:</strong></p>";
        echo "<p><a href='?delete=1' style='background: #e74c3c; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Delete All Auto-Generated Entries</a></p>";
        echo "<p style='color: #e74c3c;'><strong>Warning: This will soft-delete (mark as deleted) all entries shown above.</strong></p>";
    }
} else {
    echo "<p style='color: green;'><strong>✓ No auto-generated entries found. Database is clean!</strong></p>";
}

$conn->close();
?>


