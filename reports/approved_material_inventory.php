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

if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    session_destroy();
    header("Location: ../login.html?error=disabled");
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Fetch approved materials from store_received_entries with all 6 test statuses
$query = "
    SELECT 
        sre.*,
        sre.date_time as received_date,
        -- Test statuses for all 6 tests
        ff.status as fineness_fiber_status,
        cl.status as cut_length_fiber_status,
        tf.status as tenacity_fiber_status,
        ty.status as tenacity_yarn_status,
        ft.status as fiber_test_status,
        st.status as sewing_thread_status,
        -- Test report numbers
        ff.report_number as fineness_fiber_report,
        cl.report_number as cut_length_fiber_report,
        tf.report_number as tenacity_fiber_report,
        ty.report_number as tenacity_yarn_report,
        ft.report_number as fiber_test_report,
        st.report_number as sewing_thread_report,
        -- Material status calculation: Ready only when ALL 6 tests are approved for fiber materials
        CASE 
            WHEN sre.material_type LIKE '%Fiber%' OR sre.material_type LIKE '%PP%' THEN 
                CASE 
                    WHEN (ff.status = 'approved' AND cl.status = 'approved' AND tf.status = 'approved' 
                          AND ty.status = 'approved' AND ft.status = 'approved' AND st.status = 'approved') 
                    THEN 'Ready'
                    WHEN (ff.status IS NOT NULL OR cl.status IS NOT NULL OR tf.status IS NOT NULL 
                          OR ty.status IS NOT NULL OR ft.status IS NOT NULL OR st.status IS NOT NULL) 
                    THEN 'Testing'
                    ELSE 'Pending Test' 
                END
            WHEN sre.material_type LIKE '%Thread%' OR sre.material_type LIKE '%Sewing%' THEN 
                CASE 
                    WHEN st.status = 'approved' THEN 'Ready' 
                    WHEN st.status IS NOT NULL THEN 'Testing'
                    ELSE 'Pending Test' 
                END
            ELSE 'Pending Test'
        END as material_status
    FROM store_received_entries sre
    LEFT JOIN fineness_fiber_reports ff ON sre.entry_number COLLATE utf8mb4_unicode_ci = ff.store_entry_reference
    LEFT JOIN cut_length_fiber_reports cl ON sre.entry_number COLLATE utf8mb4_unicode_ci = cl.store_entry_reference
    LEFT JOIN tenacity_fiber_reports tf ON sre.entry_number COLLATE utf8mb4_unicode_ci = tf.store_entry_reference
    LEFT JOIN tenacity_yarn_reports ty ON sre.entry_number COLLATE utf8mb4_unicode_ci = ty.store_entry_reference
    LEFT JOIN fiber_test_reports ft ON sre.entry_number COLLATE utf8mb4_unicode_ci = ft.store_entry_reference
    LEFT JOIN sewing_thread_reports st ON sre.entry_number COLLATE utf8mb4_unicode_ci = st.store_entry_reference
    ORDER BY sre.date_time DESC, sre.created_at DESC
";

$result = $conn->query($query);
$materials = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $materials[] = $row;
    }
}

// Calculate summary statistics
$totalMaterials = count($materials);
$readyMaterials = count(array_filter($materials, function($m) { return $m['material_status'] === 'Ready'; }));
$testingMaterials = count(array_filter($materials, function($m) { return $m['material_status'] === 'Testing'; }));
$pendingMaterials = count(array_filter($materials, function($m) { return $m['material_status'] === 'Pending Test'; }));

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Approved Material Inventory</title>
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
        .stat-card.ready { border-left-color: #27ae60; }
        .stat-card.testing { border-left-color: #f39c12; }
        .stat-card.pending { border-left-color: #e74c3c; }
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
        .filter-group select {
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
            overflow: hidden;
        }
        table {
            width: 100%;
            border-collapse: collapse;
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
        .badge.ready {
            background: #d4edda;
            color: #155724;
        }
        .badge.testing {
            background: #fff3cd;
            color: #856404;
        }
        .badge.pending {
            background: #f8d7da;
            color: #721c24;
        }
        .badge.rejected {
            background: #f8d7da;
            color: #721c24;
        }
        @media (max-width: 1200px) {
            .table-container {
                overflow-x: auto;
            }
            table {
                min-width: 1200px;
            }
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #3498db;
            color: white;
        }
        .btn-primary:hover {
            background: #2980b9;
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
    </style>
</head>
<body>
    <a href="../index.php" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>

    <div class="header">
        <h1><i class="fas fa-check-square"></i> Approved Material Inventory</h1>
        <p>Track materials that have passed quality tests and are ready for production</p>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $totalMaterials; ?></div>
            <div class="stat-label">Total Materials</div>
        </div>
        <div class="stat-card ready">
            <div class="stat-value"><?php echo $readyMaterials; ?></div>
            <div class="stat-label">Ready for Production</div>
        </div>
        <div class="stat-card testing">
            <div class="stat-value"><?php echo $testingMaterials; ?></div>
            <div class="stat-label">Under Testing</div>
        </div>
        <div class="stat-card pending">
            <div class="stat-value"><?php echo $pendingMaterials; ?></div>
            <div class="stat-label">Pending Test</div>
        </div>
    </div>

    <div class="filters">
        <div class="filter-group">
            <label>Filter by Status:</label>
            <select id="statusFilter" onchange="filterTable()">
                <option value="">All Materials</option>
                <option value="Ready">Ready for Production</option>
                <option value="Testing">Under Testing</option>
                <option value="Pending Test">Pending Test</option>
            </select>
            
            <label>Material Type:</label>
            <select id="typeFilter" onchange="filterTable()">
                <option value="">All Types</option>
                <option value="PP Stable Fiber (Natpet)">PP Stable Fiber (Natpet)</option>
                <option value="PP Stable Fiber (APT)">PP Stable Fiber (APT)</option>
                <option value="PP Stable Fiber (Texofib)">PP Stable Fiber (Texofib)</option>
                <option value="PP Stable Fiber (Hubei Botao)">PP Stable Fiber (Hubei Botao)</option>
                <option value="PP Stable Fiber (Jiangsu Botao)">PP Stable Fiber (Jiangsu Botao)</option>
                <option value="PP Stable Fiber (Taizhu Hailun)">PP Stable Fiber (Taizhu Hailun)</option>
                <option value="PP Stable Fiber (PSF)">PP Stable Fiber (PSF)</option>
            </select>
        </div>
    </div>

    <?php if (count($materials) > 0): ?>
    <div class="table-container">
        <table id="materialTable">
            <thead>
                <tr>
                    <th>Entry Number</th>
                    <th>Material Type</th>
                    <th>Quantity (kg)</th>
                    <th>Received Date</th>
                    <th>Fineness</th>
                    <th>Cut Length</th>
                    <th>Tenacity Fiber</th>
                    <th>Tenacity Yarn</th>
                    <th>Fiber Test</th>
                    <th>Sewing Thread</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($materials as $material): ?>
                <tr data-status="<?php echo $material['material_status']; ?>" data-type="<?php echo $material['material_type']; ?>">
                    <td><strong><?php echo htmlspecialchars($material['entry_number']); ?></strong></td>
                    <td><?php echo htmlspecialchars($material['material_type']); ?></td>
                    <td><?php echo number_format($material['amount_kg'], 2); ?> kg</td>
                    <td><?php echo date('d M Y', strtotime($material['received_date'])); ?></td>
                    <td>
                        <?php if ($material['fineness_fiber_status']): ?>
                            <span class="badge <?php echo $material['fineness_fiber_status'] === 'approved' ? 'ready' : ($material['fineness_fiber_status'] === 'rejected' ? 'pending' : 'testing'); ?>">
                                <?php echo ucfirst($material['fineness_fiber_status']); ?>
                            </span>
                        <?php else: ?>
                            <span class="badge pending">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($material['cut_length_fiber_status']): ?>
                            <span class="badge <?php echo $material['cut_length_fiber_status'] === 'approved' ? 'ready' : ($material['cut_length_fiber_status'] === 'rejected' ? 'pending' : 'testing'); ?>">
                                <?php echo ucfirst($material['cut_length_fiber_status']); ?>
                            </span>
                        <?php else: ?>
                            <span class="badge pending">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($material['tenacity_fiber_status']): ?>
                            <span class="badge <?php echo $material['tenacity_fiber_status'] === 'approved' ? 'ready' : ($material['tenacity_fiber_status'] === 'rejected' ? 'pending' : 'testing'); ?>">
                                <?php echo ucfirst($material['tenacity_fiber_status']); ?>
                            </span>
                        <?php else: ?>
                            <span class="badge pending">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($material['tenacity_yarn_status']): ?>
                            <span class="badge <?php echo $material['tenacity_yarn_status'] === 'approved' ? 'ready' : ($material['tenacity_yarn_status'] === 'rejected' ? 'pending' : 'testing'); ?>">
                                <?php echo ucfirst($material['tenacity_yarn_status']); ?>
                            </span>
                        <?php else: ?>
                            <span class="badge pending">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($material['fiber_test_status']): ?>
                            <span class="badge <?php echo $material['fiber_test_status'] === 'approved' ? 'ready' : ($material['fiber_test_status'] === 'rejected' ? 'pending' : 'testing'); ?>">
                                <?php echo ucfirst($material['fiber_test_status']); ?>
                            </span>
                        <?php else: ?>
                            <span class="badge pending">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($material['sewing_thread_status']): ?>
                            <span class="badge <?php echo $material['sewing_thread_status'] === 'approved' ? 'ready' : ($material['sewing_thread_status'] === 'rejected' ? 'pending' : 'testing'); ?>">
                                <?php echo ucfirst($material['sewing_thread_status']); ?>
                            </span>
                        <?php else: ?>
                            <span class="badge pending">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?php 
                            echo $material['material_status'] === 'Ready' ? 'ready' : 
                                 ($material['material_status'] === 'Testing' ? 'testing' : 'pending'); 
                        ?>">
                            <?php echo $material['material_status']; ?>
                        </span>
                    </td>
                    <td>
                        <button class="btn btn-primary" onclick="viewDetails('<?php echo $material['entry_number']; ?>')">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="text-align: center; padding: 60px; background: white; border-radius: 12px;">
        <i class="fas fa-inbox" style="font-size: 4em; color: #95a5a6; margin-bottom: 20px;"></i>
        <p style="font-size: 1.2em; color: #7f8c8d;">No materials found in inventory</p>
    </div>
    <?php endif; ?>

    <script>
    function filterTable() {
        const statusFilter = document.getElementById('statusFilter').value;
        const typeFilter = document.getElementById('typeFilter').value;
        const rows = document.querySelectorAll('#materialTable tbody tr');
        
        rows.forEach(row => {
            const status = row.getAttribute('data-status');
            const type = row.getAttribute('data-type');
            
            const statusMatch = !statusFilter || status === statusFilter;
            const typeMatch = !typeFilter || type === typeFilter;
            
            row.style.display = (statusMatch && typeMatch) ? '' : 'none';
        });
    }

    function viewDetails(entryNumber) {
        // Open in a popup window
        const width = 1200;
        const height = 800;
        const left = (screen.width - width) / 2;
        const top = (screen.height - height) / 2;
        
        window.open(
            'view_material_details.php?entry=' + encodeURIComponent(entryNumber),
            'MaterialDetails',
            'width=' + width + ',height=' + height + ',left=' + left + ',top=' + top + ',resizable=yes,scrollbars=yes'
        );
    }
    </script>
</body>
</html>


