<?php
/**
 * Delete Entries NOT Entered Today
 * 
 * This script removes all entries from various tables that were NOT created today.
 * Use with caution - this will permanently delete historical data.
 */

session_start();
require_once '../config/security_config.php';

// Security check - only admin can run this
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    die("Unauthorized access. Please login as admin.");
}

if (strtolower($_SESSION['role']) !== 'admin') {
    die("Only administrators can delete old entries.");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Get today's date
$today = date('Y-m-d');

// Tables to clean up with their date columns
$tablesToClean = [
    // Roll Production
    'roll_entry' => 'date_time',
    'fiber_to_roll_entry' => 'date_time',
    'fiber_entries' => 'date_time',
    
    // Bag Production
    'roll_received_entry' => 'date_time',
    'cnc_entries' => 'date_time',
    'production' => 'date_time',
    'branding_entries' => 'date_time',
    
    // Finished Goods
    'fg_entry' => 'date_time',
    'fg_deliveries' => 'delivery_date',
    
    // QC Entries
    'qc_entries' => 'date_time',
    'daily_gsm_checks' => 'date_time',
    'length_calibrations' => 'date_time',
    
    // Lab Tests
    'water_permeability_tests' => 'test_date',
    'characteristics_tests' => 'sample_tested',
    'fiber_test_reports' => 'test_date',
    'sewing_thread_reports' => 'test_date',
    'sun_test_reports' => 'test_date',
    'weathering_exposure_tests' => 'test_date',
    'fabric_pre_production_tests' => 'sample_received_date',
    'fabric_after_production_tests' => 'test_date',
    
    // Material Management
    'store_received_entries' => 'date_time',
    'material_consumption' => 'consumption_date',
    
    // Scrap & Recycle
    'scrap_entries' => 'date_time',
    'scrap_recycle' => 'date_time',
];

$deletionResults = [];
$totalDeleted = 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Old Entries</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f4f6f9;
            padding: 30px;
            color: #2c3e50;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        h1 {
            color: #e74c3c;
            text-align: center;
            margin-bottom: 10px;
        }
        .warning {
            background: #fff3cd;
            border: 2px solid #ffc107;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        .warning h2 {
            color: #856404;
            margin-top: 0;
        }
        .info {
            background: #d1ecf1;
            border: 2px solid #0c5460;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 30px;
            color: #0c5460;
        }
        .button-group {
            text-align: center;
            margin: 30px 0;
        }
        button {
            padding: 15px 30px;
            font-size: 16px;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            margin: 0 10px;
            transition: all 0.3s;
        }
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        .btn-danger:hover {
            background: #c0392b;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(231, 76, 60, 0.4);
        }
        .btn-cancel {
            background: #95a5a6;
            color: white;
        }
        .btn-cancel:hover {
            background: #7f8c8d;
        }
        .results {
            margin-top: 30px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #34495e;
            color: white;
            font-weight: 600;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .deleted {
            color: #e74c3c;
            font-weight: 600;
        }
        .no-delete {
            color: #95a5a6;
        }
        .success {
            background: #d4edda;
            border: 2px solid #28a745;
            padding: 20px;
            border-radius: 8px;
            color: #155724;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚠️ Delete Old Entries</h1>
        <p style="text-align:center; color:#7f8c8d;">Today's Date: <strong><?php echo date('l, F j, Y'); ?></strong></p>

        <div class="warning">
            <h2>⚠️ CRITICAL WARNING</h2>
            <ul>
                <li>This will <strong>permanently delete ROWS/RECORDS</strong> (not tables) NOT created today (<?php echo $today; ?>)</li>
                <li>Tables will remain, but old data inside them will be deleted</li>
                <li>This action <strong>CANNOT be undone</strong></li>
                <li>All historical data will be lost</li>
                <li>Only entries/rows created today will remain in each table</li>
                <li>Database backups are recommended before proceeding</li>
            </ul>
        </div>

        <div class="info">
            <strong>📊 Tables where old ROWS will be deleted (tables themselves remain):</strong>
            <div style="column-count: 3; margin-top: 10px;">
                <?php foreach (array_keys($tablesToClean) as $table): ?>
                    <div>• <?php echo htmlspecialchars($table); ?></div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!isset($_POST['confirm_delete'])): ?>
            <form method="POST" onsubmit="return confirm('⚠️ FINAL CONFIRMATION\n\nAre you ABSOLUTELY SURE you want to delete all old entries?\n\nThis will permanently remove all historical data!\n\nClick OK to proceed or Cancel to abort.');">
                <div class="button-group">
                    <button type="submit" name="confirm_delete" value="yes" class="btn-danger">
                        🗑️ DELETE OLD ENTRIES
                    </button>
                    <a href="../index.php" style="text-decoration:none;">
                        <button type="button" class="btn-cancel">
                            ← Cancel & Go Back
                        </button>
                    </a>
                </div>
            </form>
        <?php else: ?>
            <div class="results">
                <h2>🔄 Row Deletion in Progress...</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Table Name</th>
                            <th>Date Column</th>
                            <th>ROWS Deleted</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($tablesToClean as $table => $dateColumn) {
                            // Check if table exists
                            $tableCheck = $conn->query("SHOW TABLES LIKE '$table'");
                            
                            if ($tableCheck && $tableCheck->num_rows > 0) {
                                // Check if date column exists
                                $columnCheck = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$dateColumn'");
                                
                                if ($columnCheck && $columnCheck->num_rows > 0) {
                                    // Count old entries before deletion (for logging)
                                    $countQuery = "SELECT COUNT(*) as old_count FROM `$table` WHERE DATE($dateColumn) < '$today'";
                                    $countResult = $conn->query($countQuery);
                                    $oldCount = $countResult ? $countResult->fetch_assoc()['old_count'] : 0;
                                    
                                    // Delete entries from BEFORE today (< not !=)
                                    $deleteQuery = "DELETE FROM `$table` WHERE DATE($dateColumn) < '$today'";
                                    $result = $conn->query($deleteQuery);
                                    
                                    if ($result) {
                                        $deletedCount = $conn->affected_rows;
                                        $totalDeleted += $deletedCount;
                                        $status = $deletedCount > 0 ? '✅ Deleted' : '✓ No old entries';
                                        $class = $deletedCount > 0 ? 'deleted' : 'no-delete';
                                        
                                        echo "<tr>";
                                        echo "<td><strong>$table</strong></td>";
                                        echo "<td>$dateColumn</td>";
                                        echo "<td class='$class'>$deletedCount</td>";
                                        echo "<td>$status</td>";
                                        echo "</tr>";
                                    } else {
                                        echo "<tr>";
                                        echo "<td><strong>$table</strong></td>";
                                        echo "<td>$dateColumn</td>";
                                        echo "<td colspan='2' style='color:#e74c3c;'>❌ Error: " . htmlspecialchars($conn->error) . "</td>";
                                        echo "</tr>";
                                    }
                                } else {
                                    echo "<tr>";
                                    echo "<td><strong>$table</strong></td>";
                                    echo "<td>$dateColumn</td>";
                                    echo "<td colspan='2' style='color:#f39c12;'>⚠️ Column not found</td>";
                                    echo "</tr>";
                                }
                            } else {
                        echo "<tr>";
                        echo "<td><strong>$table</strong></td>";
                        echo "<td>$dateColumn</td>";
                        echo "<td colspan='2' style='color:#95a5a6;'>ℹ️ Table doesn't exist (skipped)</td>";
                        echo "</tr>";
                            }
                        }
                        ?>
                    </tbody>
                </table>

                <div class="success">
                    <h2>✅ Row Deletion Complete</h2>
                    <p><strong>Total ROWS/ENTRIES deleted: <?php echo number_format($totalDeleted); ?></strong></p>
                    <p>All tables remain intact. Only entries/rows created today (<?php echo date('F j, Y'); ?>) remain in each table.</p>
                    <p><em>Note: Table structures are unchanged - only old data was removed.</em></p>
                </div>

                <div class="button-group">
                    <a href="../index.php" style="text-decoration:none;">
                        <button type="button" class="btn-cancel">
                            ← Return to Dashboard
                        </button>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

<?php
$conn->close();
?>


