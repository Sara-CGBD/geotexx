<?php
/**
 * Script to delete Sun test reports submitted before today
 */

require_once __DIR__ . '/config/config.php';

try {
    $conn = new mysqli($host, $username, $password, $dbname, $port);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Get today's date
    $today = date('Y-m-d');
    
    echo "<h2>Deleting Old Sun Test Reports</h2>";
    echo "<p>Today's date: <strong>$today</strong></p>";
    
    // First, count the records before today
    $countQuery = "SELECT COUNT(*) as total FROM sun_test_reports WHERE DATE(created_at) < '$today'";
    $result = $conn->query($countQuery);
    $row = $result->fetch_assoc();
    $totalRecords = $row['total'];
    
    echo "<p>Found <strong>$totalRecords</strong> report(s) from before today.</p>";
    
    if ($totalRecords > 0) {
        // Get the report IDs that will be deleted
        $reportIds = [];
        $idsQuery = "SELECT id FROM sun_test_reports WHERE DATE(created_at) < '$today'";
        $idsResult = $conn->query($idsQuery);
        while ($row = $idsResult->fetch_assoc()) {
            $reportIds[] = $row['id'];
        }
        
        if (!empty($reportIds)) {
            $idsStr = implode(',', $reportIds);
            
            // Delete from logs table
            echo "<p>Deleting related log entries...</p>";
            $conn->query("DELETE FROM weathering_exposure_logs WHERE report_id IN ($idsStr)");
            
            // Delete from main table
            echo "<p>Deleting reports from sun_test_reports table...</p>";
            $conn->query("DELETE FROM sun_test_reports WHERE DATE(created_at) < '$today'");
            
            echo "<p style='color: green;'><strong>✅ Successfully deleted $totalRecords report(s) submitted before today.</strong></p>";
        }
    } else {
        echo "<p style='color: orange;'>No old reports found to delete.</p>";
    }
    
    // Show remaining reports
    $remainingQuery = "SELECT COUNT(*) as total FROM sun_test_reports";
    $remainingResult = $conn->query($remainingQuery);
    $remainingRow = $remainingResult->fetch_assoc();
    $remainingRecords = $remainingRow['total'];
    
    echo "<p><strong>Reports remaining in database:</strong> $remainingRecords</p>";
    
    $conn->close();
    
    echo "<br><a href='forms/sun_test_report.php'>← Back to Sun Test Form</a>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>


