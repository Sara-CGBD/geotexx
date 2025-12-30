<?php
/**
 * Delete Unused Database Tables
 * WARNING: This script will permanently delete tables!
 * Run database/list_unused_tables.php first to review
 */

require_once __DIR__ . '/../config/security_config.php';

// Security check - only allow admin access
session_start();
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    die("Access denied. Please log in.");
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if ($user_role !== 'admin') {
    die("Access denied. Only administrators can delete tables.");
}

$conn = SecurityConfig::getConnection();

// Get tables to delete from POST or GET parameter
$tables_to_delete = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tables'])) {
    $tables_to_delete = $_POST['tables'];
} elseif (isset($_GET['tables'])) {
    $tables_to_delete = explode(',', $_GET['tables']);
}

if (empty($tables_to_delete)) {
    die("No tables specified for deletion. Use database/list_unused_tables.php first.");
}

echo "<h2>🗑️ Deleting Unused Tables</h2>";
echo "<p><strong>Tables to delete:</strong> " . count($tables_to_delete) . "</p>";

$deleted = [];
$errors = [];

foreach ($tables_to_delete as $table) {
    $table = trim($table);
    if (empty($table)) continue;
    
    // Safety check - prevent deletion of critical tables
    $critical_tables = ['new_user', 'users', 'active_sessions', 'projects', 'audit_log'];
    if (in_array($table, $critical_tables)) {
        $errors[] = "❌ Skipped critical table: $table (cannot be deleted)";
        continue;
    }
    
    try {
        // Check if table exists
        $check = $conn->query("SHOW TABLES LIKE '$table'");
        if ($check && $check->num_rows > 0) {
            // Get row count before deletion
            $count_result = $conn->query("SELECT COUNT(*) as cnt FROM `$table`");
            $count = 0;
            if ($count_result) {
                $row = $count_result->fetch_assoc();
                $count = $row['cnt'] ?? 0;
            }
            
            // Delete the table
            $conn->query("DROP TABLE IF EXISTS `$table`");
            $deleted[] = "$table ($count rows deleted)";
            echo "<p style='color:green;'>✅ Deleted: <strong>$table</strong> ($count rows)</p>";
        } else {
            $errors[] = "⚠️ Table not found: $table";
        }
    } catch (Exception $e) {
        $errors[] = "❌ Error deleting $table: " . $e->getMessage();
        echo "<p style='color:red;'>❌ Error deleting <strong>$table</strong>: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

echo "<hr>";
echo "<h3>Summary:</h3>";
echo "<p><strong>Successfully deleted:</strong> " . count($deleted) . " table(s)</p>";
if (!empty($deleted)) {
    echo "<ul>";
    foreach ($deleted as $item) {
        echo "<li>$item</li>";
    }
    echo "</ul>";
}

if (!empty($errors)) {
    echo "<p><strong>Errors/Warnings:</strong> " . count($errors) . "</p>";
    echo "<ul>";
    foreach ($errors as $error) {
        echo "<li>$error</li>";
    }
    echo "</ul>";
}

echo "<br><p><a href='list_unused_tables.php'>← Back to Table List</a></p>";

$conn->close();
?>


