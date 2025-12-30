<?php
/**
 * Script to completely delete ABC Project from all tables
 * This script will:
 * 1. Find ABC Project ID
 * 2. Set all related records' project_id to NULL
 * 3. Delete the project from projects table
 */

session_start();
require_once 'forms/security_config.php';

// Only allow admin access
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    die("Access Denied: Not logged in");
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if ($user_role !== 'admin') {
    die("Access Denied: Admin only");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$projectName = 'ABC Project';

echo "<html><head><title>Delete ABC Project</title>";
echo "<style>body { font-family: Arial; padding: 20px; } .success { color: green; } .error { color: red; } .info { color: blue; }</style>";
echo "</head><body>";
echo "<h1>Deleting ABC Project</h1>";

try {
    // Step 1: Find the project ID
    $stmt = $conn->prepare("SELECT id, project_name FROM projects WHERE project_name = ?");
    $stmt->bind_param('s', $projectName);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo "<p class='error'>❌ Project '{$projectName}' not found in database.</p>";
        $stmt->close();
        $conn->close();
        echo "</body></html>";
        exit;
    }
    
    $project = $result->fetch_assoc();
    $projectId = $project['id'];
    echo "<p class='info'>✓ Found project: <strong>{$project['project_name']}</strong> (ID: {$projectId})</p>";
    $stmt->close();
    
    // Step 2: Update all tables that reference this project
    $tables = [
        'fg_entry' => 'project_id',
        'cnc_entries' => 'project_id',
        'swing_machine_entry' => 'project_id',
        'roll_entry' => 'project_id',
        'fiber_to_roll_entry' => 'project_id',
        'bom' => 'product_id', // BOM might reference projects
        'fg' => 'project_id', // If FG table exists
    ];
    
    $conn->autocommit(FALSE); // Start transaction
    
    $totalUpdated = 0;
    foreach ($tables as $table => $column) {
        // Check if table exists
        $checkTable = $conn->query("SHOW TABLES LIKE '{$table}'");
        if ($checkTable && $checkTable->num_rows > 0) {
            // Check if column exists
            $checkCol = $conn->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
            if ($checkCol && $checkCol->num_rows > 0) {
                // Count records
                $countStmt = $conn->prepare("SELECT COUNT(*) as cnt FROM {$table} WHERE {$column} = ?");
                $countStmt->bind_param('i', $projectId);
                $countStmt->execute();
                $countResult = $countStmt->get_result();
                $count = $countResult->fetch_assoc()['cnt'];
                $countStmt->close();
                
                if ($count > 0) {
                    // Update records to set project_id to NULL
                    $updateStmt = $conn->prepare("UPDATE {$table} SET {$column} = NULL WHERE {$column} = ?");
                    $updateStmt->bind_param('i', $projectId);
                    if ($updateStmt->execute()) {
                        echo "<p class='success'>✓ Updated {$count} record(s) in <strong>{$table}</strong></p>";
                        $totalUpdated += $count;
                    } else {
                        throw new Exception("Failed to update {$table}: " . $updateStmt->error);
                    }
                    $updateStmt->close();
                } else {
                    echo "<p class='info'>○ No records found in <strong>{$table}</strong></p>";
                }
            }
        }
    }
    
    // Step 3: Delete from projects table
    $deleteStmt = $conn->prepare("DELETE FROM projects WHERE id = ?");
    $deleteStmt->bind_param('i', $projectId);
    
    if ($deleteStmt->execute()) {
        echo "<p class='success'>✓ Deleted project from <strong>projects</strong> table</p>";
    } else {
        throw new Exception("Failed to delete project: " . $deleteStmt->error);
    }
    $deleteStmt->close();
    
    // Commit transaction
    $conn->commit();
    $conn->autocommit(TRUE);
    
    echo "<hr>";
    echo "<h2 class='success'>✅ Success!</h2>";
    echo "<p><strong>Summary:</strong></p>";
    echo "<ul>";
    echo "<li>Updated {$totalUpdated} record(s) across related tables</li>";
    echo "<li>Deleted project '{$projectName}' from projects table</li>";
    echo "</ul>";
    echo "<p><a href='index.php'>← Back to Dashboard</a></p>";
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    $conn->autocommit(TRUE);
    
    echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><a href='index.php'>← Back to Dashboard</a></p>";
}

$conn->close();
echo "</body></html>";
?>


