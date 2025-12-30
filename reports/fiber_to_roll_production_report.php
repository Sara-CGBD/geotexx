<?php
session_start();
require_once '../config/config.php';

// Security/session checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Connect to database
$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Get date range filters
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default to first day of current month
$end_date = $_GET['end_date'] ?? date('Y-m-d'); // Default to today

// Ensure tables exist
$conn->query("CREATE TABLE IF NOT EXISTS fiber_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date_time DATETIME NOT NULL,
    operator_id INT NOT NULL,
    project_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    bale_weight INT NOT NULL,
    bale_number VARCHAR(100) NOT NULL,
    origin VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

$conn->query("CREATE TABLE IF NOT EXISTS fiber_to_roll_entry (
    id INT AUTO_INCREMENT PRIMARY KEY,
    date_time DATETIME NOT NULL,
    operator_id INT NOT NULL,
    project_id INT NOT NULL,
    bale_opener_number VARCHAR(100) NOT NULL,
    bale_number VARCHAR(100) NOT NULL,
    bale_weight INT NOT NULL,
    gsm INT NOT NULL,
    line_no VARCHAR(50) NOT NULL,
    fiber_type VARCHAR(100) NOT NULL,
    origin VARCHAR(100) NOT NULL,
    roll_number INT NOT NULL,
    total_weight DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Get fiber entries data
$fiberQuery = "SELECT * FROM fiber_entries WHERE DATE(date_time) BETWEEN ? AND ? ORDER BY date_time DESC";
$fiberStmt = $conn->prepare($fiberQuery);
$fiberStmt->bind_param('ss', $start_date, $end_date);
$fiberStmt->execute();
$fiberResult = $fiberStmt->get_result();
$fiberEntries = [];
while ($row = $fiberResult->fetch_assoc()) {
    $fiberEntries[] = $row;
}

// Get fiber to roll conversion data
$conversionQuery = "SELECT * FROM fiber_to_roll_entry WHERE DATE(date_time) BETWEEN ? AND ? ORDER BY date_time DESC";
$conversionStmt = $conn->prepare($conversionQuery);
$conversionStmt->bind_param('ss', $start_date, $end_date);
$conversionStmt->execute();
$conversionResult = $conversionStmt->get_result();
$conversionEntries = [];
while ($row = $conversionResult->fetch_assoc()) {
    $conversionEntries[] = $row;
}

// Calculate summary statistics
$totalFiberEntries = count($fiberEntries);
$totalFiberWeight = array_sum(array_column($fiberEntries, 'amount'));
$totalConversionEntries = count($conversionEntries);
$totalRollWeight = array_sum(array_column($conversionEntries, 'total_weight'));

// Group by origin
$fiberByOrigin = [];
foreach ($fiberEntries as $entry) {
    $origin = $entry['origin'];
    if (!isset($fiberByOrigin[$origin])) {
        $fiberByOrigin[$origin] = ['count' => 0, 'weight' => 0];
    }
    $fiberByOrigin[$origin]['count']++;
    $fiberByOrigin[$origin]['weight'] += $entry['amount'];
}

$conversionByOrigin = [];
foreach ($conversionEntries as $entry) {
    $origin = $entry['origin'];
    if (!isset($conversionByOrigin[$origin])) {
        $conversionByOrigin[$origin] = ['count' => 0, 'weight' => 0];
    }
    $conversionByOrigin[$origin]['count']++;
    $conversionByOrigin[$origin]['weight'] += $entry['total_weight'];
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fiber to Roll Production Report - SPC Module</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Inter', sans-serif;
        }
        .report-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
        }
        .stats-card h3 {
            font-size: 2.5rem;
            margin: 0;
        }
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
        }
        .table th {
            background: #f8f9fa;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .btn-export {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
        }
        .btn-export:hover {
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .origin-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            display: inline-block;
            margin: 2px;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4 text-center">
                    <i class="fas fa-exchange-alt"></i> Fiber to Roll Production Report
                </h1>
                
                <!-- Date Filter -->
                <div class="report-card p-4 mb-4">
                    <h4><i class="fas fa-filter"></i> Date Range Filter</h4>
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Start Date</label>
                            <input type="date" class="form-control" name="start_date" value="<?php echo $start_date; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">End Date</label>
                            <input type="date" class="form-control" name="end_date" value="<?php echo $end_date; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary d-block">
                                <i class="fas fa-search"></i> Filter Report
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Export Options -->
                <div class="text-center mb-4">
                    <a href="?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&export=csv" class="btn-export">
                        <i class="fas fa-file-csv"></i> Export as CSV
                    </a>
                    <a href="?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&export=excel" class="btn-export">
                        <i class="fas fa-file-excel"></i> Export as Excel
                    </a>
                </div>

                <!-- Summary Statistics -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card">
                            <h3><i class="fas fa-boxes"></i> <?php echo $totalFiberEntries; ?></h3>
                            <p>Total Fiber Entries</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <h3><i class="fas fa-weight-hanging"></i> <?php echo number_format($totalFiberWeight, 2); ?></h3>
                            <p>Total Fiber Weight (KG)</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <h3><i class="fas fa-dolly-flatbed"></i> <?php echo $totalConversionEntries; ?></h3>
                            <p>Total Conversions</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <h3><i class="fas fa-chart-line"></i> <?php echo number_format($totalRollWeight, 2); ?></h3>
                            <p>Total Roll Weight (KG)</p>
                        </div>
                    </div>
                </div>

                <!-- Origin Analysis -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="report-card p-4">
                            <h4><i class="fas fa-chart-pie"></i> Fiber Entries by Origin</h4>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Origin</th>
                                        <th>Entries</th>
                                        <th>Weight (KG)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fiberByOrigin as $origin => $data): ?>
                                        <tr>
                                            <td><span class="origin-badge"><?php echo htmlspecialchars($origin); ?></span></td>
                                            <td><?php echo $data['count']; ?></td>
                                            <td><?php echo number_format($data['weight'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="report-card p-4">
                            <h4><i class="fas fa-chart-bar"></i> Conversion Entries by Origin</h4>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Origin</th>
                                        <th>Conversions</th>
                                        <th>Weight (KG)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($conversionByOrigin as $origin => $data): ?>
                                        <tr>
                                            <td><span class="origin-badge"><?php echo htmlspecialchars($origin); ?></span></td>
                                            <td><?php echo $data['count']; ?></td>
                                            <td><?php echo number_format($data['weight'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Detailed Fiber Entries -->
                <div class="report-card p-4 mb-4">
                    <h4><i class="fas fa-boxes"></i> Detailed Fiber Entries (<?php echo count($fiberEntries); ?> records)</h4>
                    <?php if (!empty($fiberEntries)): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Operator ID</th>
                                        <th>Project ID</th>
                                        <th>Amount (KG)</th>
                                        <th>Bale Weight</th>
                                        <th>Bale Number</th>
                                        <th>Origin</th>
                                        <th>Created At</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fiberEntries as $entry): ?>
                                        <tr>
                                            <td><?php echo date('Y-m-d H:i', strtotime($entry['date_time'])); ?></td>
                                            <td><?php echo $entry['operator_id']; ?></td>
                                            <td><?php echo $entry['project_id']; ?></td>
                                            <td><?php echo number_format($entry['amount'], 2); ?></td>
                                            <td><?php echo $entry['bale_weight']; ?></td>
                                            <td><?php echo htmlspecialchars($entry['bale_number']); ?></td>
                                            <td><span class="origin-badge"><?php echo htmlspecialchars($entry['origin']); ?></span></td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($entry['created_at'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No fiber entries found for the selected date range.
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Detailed Conversion Entries -->
                <div class="report-card p-4">
                    <h4><i class="fas fa-exchange-alt"></i> Detailed Fiber to Roll Conversions (<?php echo count($conversionEntries); ?> records)</h4>
                    <?php if (!empty($conversionEntries)): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Date & Time</th>
                                        <th>Operator ID</th>
                                        <th>Project ID</th>
                                        <th>Bale Opener</th>
                                        <th>Bale Number</th>
                                        <th>Bale Weight</th>
                                        <th>GSM</th>
                                        <th>Line No</th>
                                        <th>Fiber Type</th>
                                        <th>Origin</th>
                                        <th>Roll Number</th>
                                        <th>Total Weight</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($conversionEntries as $entry): ?>
                                        <tr>
                                            <td><?php echo date('Y-m-d H:i', strtotime($entry['date_time'])); ?></td>
                                            <td><?php echo $entry['operator_id']; ?></td>
                                            <td><?php echo $entry['project_id']; ?></td>
                                            <td><?php echo htmlspecialchars($entry['bale_opener_number']); ?></td>
                                            <td><?php echo htmlspecialchars($entry['bale_number']); ?></td>
                                            <td><?php echo $entry['bale_weight']; ?></td>
                                            <td><?php echo $entry['gsm']; ?></td>
                                            <td><?php echo htmlspecialchars($entry['line_no']); ?></td>
                                            <td><?php echo htmlspecialchars($entry['fiber_type']); ?></td>
                                            <td><span class="origin-badge"><?php echo htmlspecialchars($entry['origin']); ?></span></td>
                                            <td><?php echo $entry['roll_number']; ?></td>
                                            <td><?php echo number_format($entry['total_weight'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> No conversion entries found for the selected date range.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
// Handle export functionality
if (isset($_GET['export'])) {
    $format = $_GET['export'];
    $filename = 'fiber_to_roll_production_report_' . $start_date . '_to_' . $end_date;
    
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Write summary
        fputcsv($output, ['Fiber to Roll Production Report Summary']);
        fputcsv($output, ['Period:', $start_date . ' to ' . $end_date]);
        fputcsv($output, ['Total Fiber Entries:', $totalFiberEntries]);
        fputcsv($output, ['Total Fiber Weight (KG):', $totalFiberWeight]);
        fputcsv($output, ['Total Conversions:', $totalConversionEntries]);
        fputcsv($output, ['Total Roll Weight (KG):', $totalRollWeight]);
        fputcsv($output, []);
        
        // Write fiber entries
        fputcsv($output, ['=== FIBER ENTRIES ===']);
        fputcsv($output, ['Date & Time', 'Operator ID', 'Project ID', 'Amount (KG)', 'Bale Weight', 'Bale Number', 'Origin']);
        foreach ($fiberEntries as $entry) {
            fputcsv($output, [
                $entry['date_time'],
                $entry['operator_id'],
                $entry['project_id'],
                $entry['amount'],
                $entry['bale_weight'],
                $entry['bale_number'],
                $entry['origin']
            ]);
        }
        
        fputcsv($output, []);
        
        // Write conversion entries
        fputcsv($output, ['=== FIBER TO ROLL CONVERSIONS ===']);
        fputcsv($output, ['Date & Time', 'Operator ID', 'Project ID', 'Bale Opener', 'Bale Number', 'Bale Weight', 'GSM', 'Line No', 'Fiber Type', 'Origin', 'Roll Number', 'Total Weight']);
        foreach ($conversionEntries as $entry) {
            fputcsv($output, [
                $entry['date_time'],
                $entry['operator_id'],
                $entry['project_id'],
                $entry['bale_opener_number'],
                $entry['bale_number'],
                $entry['bale_weight'],
                $entry['gsm'],
                $entry['line_no'],
                $entry['fiber_type'],
                $entry['origin'],
                $entry['roll_number'],
                $entry['total_weight']
            ]);
        }
        
        fclose($output);
        exit;
    }
}
?>


