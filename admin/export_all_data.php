<?php
session_start();
require_once '../config/config.php';

// Security/session checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Connect to database
$conn = new mysqli("127.0.0.1", "root", "root123", "geobagg", 3307);
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Get format parameter
$format = $_GET['format'] ?? 'csv';
$filename = 'system_data_export_' . date('Y-m-d_H-i-s');

// Get all tables
$tables = [];
$result = $conn->query("SHOW TABLES");
if ($result) {
    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }
}

if ($format === 'excel') {
    // Excel export using CSV format (can be opened in Excel)
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    
    echo "<html><head><meta charset='UTF-8'></head><body>";
    
    foreach ($tables as $table) {
        echo "<h2>Table: $table</h2>";
        echo "<table border='1'>";
        
        // Get column headers
        $columnsResult = $conn->query("SHOW COLUMNS FROM `$table`");
        if ($columnsResult) {
            echo "<tr>";
            while ($column = $columnsResult->fetch_assoc()) {
                echo "<th>" . htmlspecialchars($column['Field']) . "</th>";
            }
            echo "</tr>";
        }
        
        // Get data
        $dataResult = $conn->query("SELECT * FROM `$table`");
        if ($dataResult) {
            while ($row = $dataResult->fetch_assoc()) {
                echo "<tr>";
                foreach ($row as $value) {
                    echo "<td>" . htmlspecialchars($value ?? '') . "</td>";
                }
                echo "</tr>";
            }
        }
        
        echo "</table><br><br>";
    }
    
    echo "</body></html>";
    
} else {
    // CSV export
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    foreach ($tables as $table) {
        // Write table header
        fputcsv($output, ["=== TABLE: $table ==="]);
        
        // Get column headers
        $columnsResult = $conn->query("SHOW COLUMNS FROM `$table`");
        if ($columnsResult) {
            $headers = [];
            while ($column = $columnsResult->fetch_assoc()) {
                $headers[] = $column['Field'];
            }
            fputcsv($output, $headers);
        }
        
        // Get data
        $dataResult = $conn->query("SELECT * FROM `$table`");
        if ($dataResult) {
            while ($row = $dataResult->fetch_assoc()) {
                fputcsv($output, array_values($row));
            }
        }
        
        // Add separator between tables
        fputcsv($output, []);
        fputcsv($output, []);
    }
    
    fclose($output);
}

$conn->close();
?>


