<?php
/**
 * Check if checked_by column exists in qc_test_orders table
 * Add it if it doesn't exist
 */

session_start();
require_once __DIR__ . '/../config/security_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Only admin can access
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if (!in_array($user_role, ['admin', 'agm ops', 'agm operations'])) {
    http_response_code(403);
    die("Access denied");
}

$conn = SecurityConfig::getConnection();

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><title>Check checked_by Column</title>";
echo "<style>body{font-family:Arial;padding:20px;background:#f5f5f5;}";
echo ".container{max-width:800px;margin:0 auto;background:white;padding:20px;border-radius:8px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}";
echo "h1{color:#333;} .success{color:#27ae60;} .info{color:#3498db;} .warning{color:#f39c12;} .error{color:#e74c3c;}</style></head><body>";
echo "<div class='container'><h1>Check checked_by Column in qc_test_orders</h1>";
echo "<pre style='background:#f9f9f9;padding:15px;border-radius:4px;overflow-x:auto;'>";

// Check if column exists
$checkCol = $conn->query("SHOW COLUMNS FROM qc_test_orders LIKE 'checked_by'");
if ($checkCol && $checkCol->num_rows > 0) {
    echo "<span class='success'>✅ Column 'checked_by' exists in qc_test_orders table</span>\n\n";
    
    // Check sample data
    $sampleQuery = $conn->query("SELECT report_number, checked_by, status FROM qc_test_orders WHERE status = 'pending_approval' LIMIT 5");
    if ($sampleQuery && $sampleQuery->num_rows > 0) {
        echo "Sample data (pending_approval reports):\n";
        echo str_repeat("-", 60) . "\n";
        while ($row = $sampleQuery->fetch_assoc()) {
            echo "Report: " . htmlspecialchars($row['report_number']) . "\n";
            echo "  Status: " . htmlspecialchars($row['status']) . "\n";
            echo "  Checked By: " . (!empty($row['checked_by']) ? htmlspecialchars($row['checked_by']) : '<span class="warning">NULL/Empty</span>') . "\n";
            echo "\n";
        }
    } else {
        echo "<span class='info'>ℹ️  No pending_approval reports found</span>\n";
    }
} else {
    echo "<span class='warning'>⚠️  Column 'checked_by' does NOT exist. Adding it now...</span>\n";
    
    $alterQuery = "ALTER TABLE qc_test_orders ADD COLUMN checked_by VARCHAR(100) NULL AFTER status";
    if ($conn->query($alterQuery)) {
        echo "<span class='success'>✅ Column 'checked_by' added successfully!</span>\n";
    } else {
        echo "<span class='error'>❌ Error adding column: " . htmlspecialchars($conn->error) . "</span>\n";
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "</pre>";
echo "<p><a href='qc_reports_dashboard.php'>← Back to QC Reports Dashboard</a></p>";
echo "</div></body></html>";
?>


