<?php
session_start();
require_once '../config/security_config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Role-based access control
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_QC, AccessControl::PERMISSION_VIEW)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access QC Reports.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Get filter parameters - Default to last 30 days for performance
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$status = $_GET['status'] ?? 'all';
$testType = $_GET['test_type'] ?? 'all';

// Performance: Use full datetime strings for index usage
$dateFromFull = $dateFrom . ' 00:00:00';
$dateToFull = $dateTo . ' 23:59:59';

// Get all QC test reports
$reports = [];

// 1. Sewing Thread Reports - Optimized query with LIMIT
$query = "SELECT 'Sewing Thread' as test_type, report_number, sample_description, test_start_date as test_date, test_performed_by as tested_by, status, approved_by, created_at, updated_at 
          FROM sewing_thread_reports 
          WHERE test_start_date >= ? AND test_start_date <= ?";
if ($status != 'all') {
    $query .= " AND status = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('sss', $dateFromFull, $dateToFull, $status);
} else {
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ss', $dateFromFull, $dateToFull);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}
$stmt->close();

// 2. UV Test (Weathering Exposure) - Optimized
$query = "SELECT 'UV Test' as test_type, report_number, sample_description, test_start_date as test_date, COALESCE(test_performed_by, tested_by) as tested_by, status, approved_by, created_at, updated_at 
          FROM weathering_exposure_reports 
          WHERE test_start_date >= ? AND test_start_date <= ?";
if ($status != 'all') {
    $query .= " AND status = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('sss', $dateFromFull, $dateToFull, $status);
} else {
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ss', $dateFromFull, $dateToFull);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}
$stmt->close();

// 3. Fiber Test Reports - Optimized
$query = "SELECT 'Fiber Test' as test_type, report_number, sample_id as sample_description, sample_tested_date as test_date, test_performed_by as tested_by, status, approved_by, created_at, updated_at 
          FROM fiber_test_reports 
          WHERE sample_tested_date >= ? AND sample_tested_date <= ?";
if ($status != 'all') {
    $query .= " AND status = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('sss', $dateFromFull, $dateToFull, $status);
} else {
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ss', $dateFromFull, $dateToFull);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}
$stmt->close();

// 4. Fabric Pre-Production Test - Optimized
$query = "SELECT 'Fabric Pre-Production' as test_type, report_number, sample_details as sample_description, test_period_from as test_date, test_performed_by as tested_by, status, approved_by, created_at, updated_at 
          FROM fabric_pre_production_tests 
          WHERE test_period_from >= ? AND test_period_from <= ?";
if ($status != 'all') {
    $query .= " AND status = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('sss', $dateFromFull, $dateToFull, $status);
} else {
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ss', $dateFromFull, $dateToFull);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}
$stmt->close();

// 5. Fabric After Production Test - Optimized
$query = "SELECT 'Fabric After Production' as test_type, report_number, sample_id as sample_description, sample_tested_date as test_date, test_performed_by as tested_by, status, approved_by, created_at, updated_at 
          FROM fabric_after_production_tests 
          WHERE sample_tested_date >= ? AND sample_tested_date <= ?";
if ($status != 'all') {
    $query .= " AND status = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('sss', $dateFromFull, $dateToFull, $status);
} else {
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ss', $dateFromFull, $dateToFull);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}
$stmt->close();

// 6. Sun Test Reports - Optimized
$query = "SELECT 'Sun Test' as test_type, report_number, sample_description, test_start_date as test_date, test_performed_by as tested_by, status, approved_by, created_at, updated_at 
          FROM sun_test_reports 
          WHERE test_start_date >= ? AND test_start_date <= ?";
if ($status != 'all') {
    $query .= " AND status = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('sss', $dateFromFull, $dateToFull, $status);
} else {
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ss', $dateFromFull, $dateToFull);
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $reports[] = $row;
}
$stmt->close();

// Sort by created_at descending
usort($reports, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});

// Calculate statistics
$totalReports = count($reports);
$approvedCount = count(array_filter($reports, fn($r) => $r['status'] === 'approved'));
$pendingCount = count(array_filter($reports, fn($r) => in_array($r['status'], ['pending', 'pending_checker', 'pending_approval'])));
$rejectedCount = count(array_filter($reports, fn($r) => in_array($r['status'], ['rejected', 'rejected_by_checker', 'rejected_by_approver'])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>QC Inspection Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1400px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; text-align: center; }
        .stat-card.approved { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-card.pending { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
        .stat-card.rejected { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }
        .stat-value { font-size: 2.5em; font-weight: bold; }
        .stat-label { font-size: 0.9em; opacity: 0.9; margin-top: 5px; }
        .filters { background: #ecf0f1; padding: 20px; border-radius: 8px; margin-bottom: 30px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .filters label { font-weight: 600; margin-bottom: 5px; display: block; }
        .filters input, .filters select { width: 100%; padding: 8px; border: 1px solid #bdc3c7; border-radius: 4px; }
        .filter-btn { background: #3498db; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; }
        .filter-btn:hover { background: #2980b9; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; }
        tr:hover { background: #f8f9fa; }
        .status-badge { padding: 4px 12px; border-radius: 12px; font-size: 0.85em; font-weight: 600; }
        .status-approved { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .no-data { text-align: center; padding: 40px; color: #95a5a6; }
        .back-btn { background: #95a5a6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; margin-bottom: 20px; }
        .back-btn:hover { background: #7f8c8d; }
        .export-btn { background: #27ae60; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; float: right; }
        .export-btn:hover { background: #229954; }
    </style>
</head>
<body>
<div class="container">
    <a href="../index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    <button onclick="exportToExcel()" class="export-btn"><i class="fas fa-file-excel"></i> Export to Excel</button>
    
    <h1><i class="fas fa-clipboard-check"></i> QC Inspection Report</h1>
    <p class="subtitle">Comprehensive Quality Control Testing Overview</p>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $totalReports; ?></div>
            <div class="stat-label">Total Reports</div>
        </div>
        <div class="stat-card approved">
            <div class="stat-value"><?php echo $approvedCount; ?></div>
            <div class="stat-label">Approved</div>
        </div>
        <div class="stat-card pending">
            <div class="stat-value"><?php echo $pendingCount; ?></div>
            <div class="stat-label">Pending</div>
        </div>
        <div class="stat-card rejected">
            <div class="stat-value"><?php echo $rejectedCount; ?></div>
            <div class="stat-label">Rejected</div>
        </div>
    </div>
    
    <!-- Filters -->
    <form method="GET" class="filters">
        <div>
            <label>Date From:</label>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
        </div>
        <div>
            <label>Date To:</label>
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
        </div>
        <div>
            <label>Status:</label>
            <select name="status">
                <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All</option>
                <option value="approved" <?php echo $status === 'approved' ? 'selected' : ''; ?>>Approved</option>
                <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="rejected" <?php echo $status === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
            </select>
        </div>
        <div style="display: flex; align-items: flex-end;">
            <button type="submit" class="filter-btn"><i class="fas fa-filter"></i> Apply Filters</button>
        </div>
    </form>
    
    <!-- Reports Table -->
    <?php if (count($reports) > 0): ?>
        <div style="overflow-x: auto;">
            <table id="reportTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Test Type</th>
                        <th>Report Number</th>
                        <th>Sample Description</th>
                        <th>Test Date</th>
                        <th>Tested By</th>
                        <th>Status</th>
                        <th>Approved By</th>
                        <th>Submitted At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $counter = 1;
                    foreach ($reports as $report): 
                        $statusClass = 'status-pending';
                        if ($report['status'] === 'approved') $statusClass = 'status-approved';
                        if (strpos($report['status'], 'rejected') !== false) $statusClass = 'status-rejected';
                    ?>
                        <tr>
                            <td><?php echo $counter++; ?></td>
                            <td><strong><?php echo htmlspecialchars($report['test_type']); ?></strong></td>
                            <td><?php echo htmlspecialchars($report['report_number']); ?></td>
                            <td><?php echo htmlspecialchars($report['sample_description']); ?></td>
                            <td><?php echo date('M d, Y', strtotime($report['test_date'])); ?></td>
                            <td><?php echo htmlspecialchars($report['tested_by']); ?></td>
                            <td>
                                <span class="status-badge <?php echo $statusClass; ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $report['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($report['approved_by'] ?? '-'); ?></td>
                            <td><?php echo date('M d, Y g:i A', strtotime($report['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-inbox" style="font-size: 3em; margin-bottom: 15px;"></i>
            <p>No QC inspection reports found for the selected filters.</p>
    </div>
    <?php endif; ?>
  </div>

<script>
function exportToExcel() {
    const table = document.getElementById('reportTable');
    if (!table) {
        alert('No data to export');
        return;
    }
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(row => {
        const cols = row.querySelectorAll('td, th');
        const rowData = [];
        cols.forEach(col => {
            rowData.push('"' + col.innerText.replace(/"/g, '""') + '"');
        });
        csv.push(rowData.join(','));
    });
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    
    link.setAttribute('href', url);
    link.setAttribute('download', 'QC_Inspection_Report_' + new Date().toISOString().split('T')[0] + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>

