<?php
session_start();
require_once '../config/config.php';

// Security/session checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Connect to database (XAMPP default: root with no password)
$conn = new mysqli("localhost", "root", "", "geobagg");
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Get table names from database
$tables = [];
$result = $conn->query("SHOW TABLES");
if ($result) {
    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }
}

// Get current table data if selected
$currentTable = $_GET['table'] ?? '';
$tableData = [];
$tableColumns = [];

if ($currentTable && in_array($currentTable, $tables)) {
    // Get column information
    $columnsResult = $conn->query("SHOW COLUMNS FROM `$currentTable`");
    if ($columnsResult) {
        while ($column = $columnsResult->fetch_assoc()) {
            $tableColumns[] = $column;
        }
    }
    
    // Get table data (limit to 1000 rows for performance)
    $dataResult = $conn->query("SELECT * FROM `$currentTable` LIMIT 1000");
    if ($dataResult) {
        while ($row = $dataResult->fetch_assoc()) {
            $tableData[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Data View - SPC Module</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Inter', sans-serif;
        }
        .data-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            margin-bottom: 20px;
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
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
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
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4 text-center">
                    <i class="fas fa-database"></i> System Data View
                </h1>
                
                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card text-center">
                            <h3><i class="fas fa-table"></i> <?php echo count($tables); ?></h3>
                            <p>Total Tables</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card text-center">
                            <h3><i class="fas fa-eye"></i> <?php echo $currentTable ? count($tableData) : '0'; ?></h3>
                            <p>Records Displayed</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card text-center">
                            <h3><i class="fas fa-columns"></i> <?php echo $currentTable ? count($tableColumns) : '0'; ?></h3>
                            <p>Columns</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card text-center">
                            <h3><i class="fas fa-clock"></i> <?php echo date('H:i:s'); ?></h3>
                            <p>Last Updated</p>
                        </div>
                    </div>
                </div>

                <!-- Export All Button -->
                <div class="text-center mb-4">
                    <a href="export_all_data.php" class="btn-export">
                        <i class="fas fa-download"></i> Export All System Data
                    </a>
                    <a href="export_all_data.php?format=csv" class="btn-export">
                        <i class="fas fa-file-csv"></i> Export as CSV
                    </a>
                    <a href="export_all_data.php?format=excel" class="btn-export">
                        <i class="fas fa-file-excel"></i> Export as Excel
                    </a>
                </div>

                <div class="row">
                    <!-- Table Selection -->
                    <div class="col-md-3">
                        <div class="data-card p-4">
                            <h4><i class="fas fa-list"></i> Available Tables</h4>
                            <div class="list-group">
                                <?php foreach ($tables as $table): ?>
                                    <a href="?table=<?php echo urlencode($table); ?>" 
                                       class="list-group-item list-group-item-action <?php echo $currentTable === $table ? 'active' : ''; ?>">
                                        <i class="fas fa-table"></i> <?php echo htmlspecialchars($table); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Table Data Display -->
                    <div class="col-md-9">
                        <?php if ($currentTable): ?>
                            <div class="data-card p-4">
                                <h4>
                                    <i class="fas fa-table"></i> 
                                    Table: <?php echo htmlspecialchars($currentTable); ?>
                                    <span class="badge bg-primary ms-2"><?php echo count($tableData); ?> records</span>
                                </h4>
                                
                                <?php if (!empty($tableData)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover">
                                            <thead>
                                                <tr>
                                                    <?php foreach ($tableColumns as $column): ?>
                                                        <th><?php echo htmlspecialchars($column['Field']); ?></th>
                                                    <?php endforeach; ?>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($tableData as $row): ?>
                                                    <tr>
                                                        <?php foreach ($row as $value): ?>
                                                            <td><?php echo htmlspecialchars($value ?? ''); ?></td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-info-circle"></i> No data found in this table.
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="data-card p-4 text-center">
                                <i class="fas fa-mouse-pointer fa-3x text-muted mb-3"></i>
                                <h4>Select a table to view data</h4>
                                <p class="text-muted">Choose a table from the left panel to view its contents.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


