<?php
/**
 * Check Old Entries Before Deletion
 * Diagnostic script to see what will be deleted
 */

session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role']) !== 'admin') {
    die("Only administrators can access this page.");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$today = date('Y-m-d');

// Sample tables to check
$tablesToCheck = [
    'roll_entry' => 'date_time',
    'fiber_to_roll_entry' => 'date_time',
    'fg_entry' => 'date_time',
    'qc_entries' => 'date_time',
    'daily_gsm_checks' => 'date_time',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Old Entries</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f4f6f9;
            padding: 30px;
            color: #2c3e50;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        h1 {
            text-align: center;
            color: #2c3e50;
        }
        .info {
            background: #d1ecf1;
            border: 2px solid #0c5460;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            color: #0c5460;
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
        .old-data {
            color: #e74c3c;
            font-weight: 600;
        }
        .today-data {
            color: #27ae60;
            font-weight: 600;
        }
        button {
            padding: 10px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            margin: 10px 5px;
        }
        button:hover {
            background: #2980b9;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Old Entries Diagnostic Check</h1>
        
        <div class="info">
            <strong>Today's Date:</strong> <?php echo date('l, F j, Y'); ?> (<?php echo $today; ?>)
            <br><strong>Time:</strong> <?php echo date('H:i:s'); ?>
        </div>

        <div style="text-align:center; margin:20px 0;">
            <a href="delete_old_entries.php"><button>→ Go to Delete Script</button></a>
            <a href="../index.php"><button style="background:#95a5a6;">← Back to Dashboard</button></a>
        </div>

        <?php foreach ($tablesToCheck as $table => $dateColumn): ?>
            <?php
            // Check if table exists
            $tableCheck = $conn->query("SHOW TABLES LIKE '$table'");
            if (!$tableCheck || $tableCheck->num_rows == 0) {
                echo "<h3>⚠️ Table '$table' does not exist</h3>";
                continue;
            }

            // Check if column exists
            $columnCheck = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$dateColumn'");
            if (!$columnCheck || $columnCheck->num_rows == 0) {
                echo "<h3>⚠️ Column '$dateColumn' does not exist in table '$table'</h3>";
                continue;
            }

            // Count total rows
            $totalQuery = $conn->query("SELECT COUNT(*) as total FROM `$table`");
            $totalRows = $totalQuery ? $totalQuery->fetch_assoc()['total'] : 0;

            // Count today's rows
            $todayQuery = $conn->query("SELECT COUNT(*) as today_count FROM `$table` WHERE DATE($dateColumn) = '$today'");
            $todayRows = $todayQuery ? $todayQuery->fetch_assoc()['today_count'] : 0;

            // Count old rows
            $oldQuery = $conn->query("SELECT COUNT(*) as old_count FROM `$table` WHERE DATE($dateColumn) < '$today'");
            $oldRows = $oldQuery ? $oldQuery->fetch_assoc()['old_count'] : 0;

            // Get sample of old entries
            $sampleQuery = $conn->query("SELECT $dateColumn, DATE($dateColumn) as date_only FROM `$table` WHERE DATE($dateColumn) < '$today' LIMIT 5");
            $samples = [];
            if ($sampleQuery) {
                while ($row = $sampleQuery->fetch_assoc()) {
                    $samples[] = $row;
                }
            }
            ?>

            <h3>📊 Table: <code><?php echo $table; ?></code></h3>
            <table>
                <tr>
                    <th>Total Rows</th>
                    <th>Today's Rows (Will Keep)</th>
                    <th>Old Rows (Will Delete)</th>
                </tr>
                <tr>
                    <td><strong><?php echo number_format($totalRows); ?></strong></td>
                    <td class="today-data"><?php echo number_format($todayRows); ?> ✓</td>
                    <td class="old-data"><?php echo number_format($oldRows); ?> ❌</td>
                </tr>
            </table>

            <?php if (count($samples) > 0): ?>
                <details style="margin-top:10px; padding:10px; background:#f8f9fa; border-radius:6px;">
                    <summary style="cursor:pointer; font-weight:600;">Sample of OLD entries that will be deleted (first 5)</summary>
                    <table style="margin-top:10px; font-size:13px;">
                        <tr>
                            <th>Full DateTime</th>
                            <th>Date Only</th>
                        </tr>
                        <?php foreach ($samples as $sample): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($sample[$dateColumn]); ?></td>
                                <td><?php echo htmlspecialchars($sample['date_only']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </details>
            <?php endif; ?>

        <?php endforeach; ?>

        <div style="text-align:center; margin:30px 0;">
            <a href="delete_old_entries.php">
                <button style="background:#e74c3c; font-size:16px; padding:15px 30px;">
                    🗑️ Proceed to Delete Old Entries
                </button>
            </a>
        </div>
    </div>
</body>
</html>

<?php $conn->close(); ?>


