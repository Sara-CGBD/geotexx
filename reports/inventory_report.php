<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

if (SecurityConfig::checkSessionTimeout()) {
    session_destroy();
    header("Location: ../login.html?error=timeout");
    exit();
}

SecurityConfig::updateSessionActivity();

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Detect fiber_entries columns for usage sum
$feCols = [];
$feColRes = $conn->query("SHOW COLUMNS FROM fiber_entries");
if ($feColRes) {
    while ($r = $feColRes->fetch_assoc()) {
        $feCols[] = strtolower($r['Field']);
    }
}
$amountCol = "amount_kg";
if (!in_array('amount_kg', $feCols, true)) {
    if (in_array('total_amount', $feCols, true)) {
        $amountCol = "total_amount";
    } elseif (in_array('amount', $feCols, true)) {
        $amountCol = "amount";
    } else {
        $amountCol = null;
    }
}
$feHasIsDeleted = in_array('is_deleted', $feCols, true);

// Detect reference column in fiber_entries
$refCol = null;
foreach (['reference', 'reference_number', 'entry_number', 'ref_number'] as $candidate) {
    if (in_array($candidate, $feCols, true)) {
        $refCol = $candidate;
        break;
    }
}
$usedSumExpr = ($amountCol && $refCol) ? "COALESCE(SUM(fe2.$amountCol), 0)" : "0";
$usedWhere = $feHasIsDeleted ? "AND fe2.is_deleted = 0" : "";

// Build used_amount projection safely (from fiber_entries)
if ($amountCol && $refCol) {
    $usedSubquery = "(SELECT $usedSumExpr 
         FROM fiber_entries fe2 
         WHERE fe2.$refCol COLLATE utf8mb4_unicode_ci = sre.entry_number 
         $usedWhere) as used_amount";
} else {
    $usedSubquery = "0 as used_amount";
}

// Build deducted_amount from material issue entries
// Check if store_issue_entries table exists and has deduction_details column
$issueTableExists = false;
$issueColCheck = $conn->query("SHOW TABLES LIKE 'store_issue_entries'");
if ($issueColCheck && $issueColCheck->num_rows > 0) {
    $issueTableExists = true;
    $deductionColCheck = $conn->query("SHOW COLUMNS FROM store_issue_entries LIKE 'deduction_details'");
    if ($deductionColCheck && $deductionColCheck->num_rows > 0) {
        // deduction_details column exists - we'll calculate deductions in PHP after fetching
    }
}

// Fetch inventory data with usage tracking
$query = "
    SELECT 
        sre.entry_number,
        sre.material_type,
        COALESCE(sre.original_amount_kg, sre.amount_kg) as original_amount,
        sre.amount_kg as remaining_amount,
        sre.date_time as received_date,
        sre.manufacturer_name,
        $usedSubquery,
        ft.status as fiber_test_status,
        ft.report_number as fiber_report_number,
        st.status as sewing_test_status,
        st.report_number as sewing_report_number,
        CASE 
            WHEN (ft.status = 'approved' OR st.status = 'approved') THEN 'Approved'
            WHEN (ft.status = 'pending' OR st.status = 'pending') THEN 'Testing'
            WHEN (ft.status = 'rejected' OR st.status = 'rejected') THEN 'Rejected'
            ELSE 'Pending Test'
        END as status
    FROM store_received_entries sre
    LEFT JOIN fiber_test_reports ft ON sre.entry_number COLLATE utf8mb4_unicode_ci = ft.store_entry_reference
    LEFT JOIN sewing_thread_reports st ON sre.entry_number COLLATE utf8mb4_unicode_ci = st.store_entry_reference
    GROUP BY sre.entry_number, sre.material_type, sre.amount_kg, sre.original_amount_kg, sre.date_time, sre.manufacturer_name, 
             ft.status, ft.report_number, st.status, st.report_number
    ORDER BY sre.date_time DESC, sre.created_at DESC
";

$result = $conn->query($query);
$inventory = [];

// Pre-fetch all deduction details from material issue entries for efficiency
$allDeductions = [];
if ($issueTableExists) {
    $deductionQuery = "SELECT deduction_details 
                      FROM store_issue_entries 
                      WHERE deduction_details IS NOT NULL 
                      AND deduction_details != '' 
                      AND deduction_details != 'null'";
    $deductionResult = $conn->query($deductionQuery);
    if ($deductionResult) {
        while ($deductionRow = $deductionResult->fetch_assoc()) {
            $deductionDetails = json_decode($deductionRow['deduction_details'], true);
            if (is_array($deductionDetails)) {
                foreach ($deductionDetails as $entryNumber => $deductedAmount) {
                    if (!isset($allDeductions[$entryNumber])) {
                        $allDeductions[$entryNumber] = 0;
                    }
                    $allDeductions[$entryNumber] += floatval($deductedAmount);
                }
            }
        }
    }
}

if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Calculate deducted amount from material issue entries
        $deductedAmount = isset($allDeductions[$row['entry_number']]) ? $allDeductions[$row['entry_number']] : 0;
        
        $row['deducted_amount'] = $deductedAmount; // Store separately for reference if needed
        $usageFromFibers = floatval($row['used_amount']);
        $totalUsed = $usageFromFibers + $deductedAmount;
        $row['used_amount'] = max(0, $totalUsed);

        $originalAmount = floatval($row['original_amount'] ?? 0);
        $row['remaining_amount'] = max(0, $originalAmount - $row['used_amount']);
        $inventory[] = $row;
    }
}

// Calculate summary statistics
$totalOriginal = array_sum(array_column($inventory, 'original_amount'));
$totalUsed = array_sum(array_column($inventory, 'used_amount'));
$totalRemaining = array_sum(array_column($inventory, 'remaining_amount'));

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: #f5f7fa; 
            color: #2c3e50;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        .header p {
            font-size: 16px;
            opacity: 0.9;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #667eea;
        }
        .stat-card.original { border-left-color: #3498db; }
        .stat-card.used { border-left-color: #e74c3c; }
        .stat-card.remaining { border-left-color: #27ae60; }
        .stat-value {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .stat-label {
            font-size: 14px;
            color: #7f8c8d;
            font-weight: 500;
        }
        .filters {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .filter-group {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        .filter-group label {
            font-weight: 600;
            color: #2c3e50;
        }
        .filter-group select, .filter-group input {
            padding: 10px 15px;
            border: 2px solid #e0e6ed;
            border-radius: 8px;
            font-size: 14px;
            min-width: 200px;
        }
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow-x: auto;
            overflow-y: hidden;
            width: 100%;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1100px; /* Ensure slider appears on smaller screens */
        }
        thead {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
        }
        td {
            padding: 15px;
            border-bottom: 1px solid #ecf0f1;
            font-size: 14px;
        }
        tbody tr:hover {
            background: #f8f9fa;
        }
        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge.approved {
            background: #d4edda;
            color: #155724;
        }
        .badge.testing {
            background: #fff3cd;
            color: #856404;
        }
        .badge.rejected {
            background: #f8d7da;
            color: #721c24;
        }
        .badge.pending {
            background: #e0e6ed;
            color: #6c757d;
        }
        .progress-bar {
            width: 100%;
            height: 20px;
            background: #ecf0f1;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #27ae60, #2ecc71);
            transition: width 0.3s ease;
        }
        .progress-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 11px;
            font-weight: 600;
            color: #2c3e50;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            padding: 10px 20px;
            background: #e74c3c;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .back-link:hover {
            background: #c0392b;
        }
        .amount-cell {
            font-weight: 600;
        }
        .amount-original { color: #3498db; }
        .amount-used { color: #e74c3c; }
        .amount-remaining { color: #27ae60; }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #3498db;
            color: white;
        }
        .btn-primary:hover {
            background: #2980b9;
        }
    </style>
</head>
<body>
    <a href="../index.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>

    <div class="header">
        <h1><i class="fas fa-warehouse"></i> Inventory Report</h1>
        <p>Track raw material inventory, usage, and remaining stock</p>
    </div>

    <div class="stats-grid">
        <div class="stat-card original">
            <div class="stat-value"><?php echo number_format($totalOriginal, 2); ?> kg</div>
            <div class="stat-label">Total Received</div>
        </div>
        <div class="stat-card used">
            <div class="stat-value"><?php echo number_format($totalUsed, 2); ?> kg</div>
            <div class="stat-label">Total Used</div>
        </div>
        <div class="stat-card remaining">
            <div class="stat-value"><?php echo number_format($totalRemaining, 2); ?> kg</div>
            <div class="stat-label">Remaining Stock</div>
        </div>
    </div>

    <div class="filters">
        <div class="filter-group">
            <label>Search:</label>
            <input type="text" id="searchInput" placeholder="Search by entry number, material type..." onkeyup="filterTable()">
            
            <label>Status:</label>
            <select id="statusFilter" onchange="filterTable()">
                <option value="">All Status</option>
                <option value="Approved">Approved</option>
                <option value="Testing">Testing</option>
                <option value="Rejected">Rejected</option>
                <option value="Pending Test">Pending Test</option>
            </select>
        </div>
    </div>

    <?php if (count($inventory) > 0): ?>
    <div class="table-container">
        <table id="inventoryTable">
            <thead>
                <tr>
                    <th>Entry Number</th>
                    <th>Material Type</th>
                    <th>Manufacturer</th>
                    <th>Received Date</th>
                    <th>Original (kg)</th>
                    <th>Used (kg)</th>
                    <th>Remaining (kg)</th>
                    <th>Usage %</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inventory as $item): 
                    $usagePercent = $item['original_amount'] > 0 ? ($item['used_amount'] / $item['original_amount']) * 100 : 0;
                ?>
                <tr data-status="<?php echo $item['status']; ?>" data-material="<?php echo htmlspecialchars($item['material_type']); ?>" data-entry="<?php echo htmlspecialchars($item['entry_number']); ?>">
                    <td><strong><?php echo htmlspecialchars($item['entry_number']); ?></strong></td>
                    <td><?php echo htmlspecialchars($item['material_type']); ?></td>
                    <td><?php echo htmlspecialchars($item['manufacturer_name']); ?></td>
                    <td><?php echo date('d M Y', strtotime($item['received_date'])); ?></td>
                    <td class="amount-cell amount-original"><?php echo number_format($item['original_amount'], 2); ?></td>
                    <td class="amount-cell amount-used"><?php echo number_format($item['used_amount'], 2); ?></td>
                    <td class="amount-cell amount-remaining"><?php echo number_format($item['remaining_amount'], 2); ?></td>
                    <td>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo $usagePercent; ?>%"></div>
                            <div class="progress-text"><?php echo number_format($usagePercent, 1); ?>%</div>
                        </div>
                    </td>
                    <td>
                        <span class="badge <?php echo strtolower(str_replace(' ', '-', $item['status'])); ?>">
                            <?php echo $item['status']; ?>
                        </span>
                    </td>
                    <td>
                        <a href="view_material_details.php?entry=<?php echo urlencode($item['entry_number']); ?>" class="btn btn-primary">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="text-align: center; padding: 60px; background: white; border-radius: 12px;">
        <i class="fas fa-inbox" style="font-size: 4em; color: #95a5a6; margin-bottom: 20px;"></i>
        <p style="font-size: 1.2em; color: #7f8c8d;">No inventory data found</p>
    </div>
    <?php endif; ?>

    <script>
    function filterTable() {
        const searchInput = document.getElementById('searchInput').value.toLowerCase();
        const statusFilter = document.getElementById('statusFilter').value;
        const rows = document.querySelectorAll('#inventoryTable tbody tr');
        
        rows.forEach(row => {
            const entryNumber = row.getAttribute('data-entry').toLowerCase();
            const material = row.getAttribute('data-material').toLowerCase();
            const status = row.getAttribute('data-status');
            
            const searchMatch = entryNumber.includes(searchInput) || material.includes(searchInput);
            const statusMatch = !statusFilter || status === statusFilter;
            
            row.style.display = (searchMatch && statusMatch) ? '' : 'none';
        });
    }
    </script>
</body>
</html>


