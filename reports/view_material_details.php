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

$entry_number = isset($_GET['entry']) ? $_GET['entry'] : '';

if (empty($entry_number)) {
    die('Invalid entry number');
}

// Fetch material details with all 6 test information
$query = "
    SELECT 
        sre.*,
        sre.date_time as received_date,
        -- Fineness Fiber Report
        ff.id as fineness_fiber_id,
        ff.report_number as fineness_fiber_report,
        ff.status as fineness_fiber_status,
        ff.test_start_date as fineness_fiber_test_date,
        ff.test_performed_by as fineness_fiber_tester,
        -- Cut Length Fiber Report
        cl.id as cut_length_fiber_id,
        cl.report_number as cut_length_fiber_report,
        cl.status as cut_length_fiber_status,
        cl.test_start_date as cut_length_fiber_test_date,
        cl.test_performed_by as cut_length_fiber_tester,
        -- Tenacity Fiber Report
        tf.id as tenacity_fiber_id,
        tf.report_number as tenacity_fiber_report,
        tf.status as tenacity_fiber_status,
        tf.test_start_date as tenacity_fiber_test_date,
        tf.test_performed_by as tenacity_fiber_tester,
        -- Tenacity Yarn Report
        ty.id as tenacity_yarn_id,
        ty.report_number as tenacity_yarn_report,
        ty.status as tenacity_yarn_status,
        ty.test_start_date as tenacity_yarn_test_date,
        ty.test_performed_by as tenacity_yarn_tester,
        -- Fiber Test Report
        ft.id as fiber_test_id,
        ft.report_number as fiber_report_number,
        ft.status as fiber_status,
        ft.sample_tested_date as fiber_test_date,
        ft.test_performed_by as fiber_tester,
        -- Sewing Thread Report
        st.id as sewing_test_id,
        st.report_number as sewing_report_number,
        st.status as sewing_status,
        st.test_start_date as sewing_test_date,
        st.test_performed_by as sewing_tester
    FROM store_received_entries sre
    LEFT JOIN fineness_fiber_reports ff ON sre.entry_number COLLATE utf8mb4_unicode_ci = ff.store_entry_reference
    LEFT JOIN cut_length_fiber_reports cl ON sre.entry_number COLLATE utf8mb4_unicode_ci = cl.store_entry_reference
    LEFT JOIN tenacity_fiber_reports tf ON sre.entry_number COLLATE utf8mb4_unicode_ci = tf.store_entry_reference
    LEFT JOIN tenacity_yarn_reports ty ON sre.entry_number COLLATE utf8mb4_unicode_ci = ty.store_entry_reference
    LEFT JOIN fiber_test_reports ft ON sre.entry_number COLLATE utf8mb4_unicode_ci = ft.store_entry_reference
    LEFT JOIN sewing_thread_reports st ON sre.entry_number COLLATE utf8mb4_unicode_ci = st.store_entry_reference
    WHERE sre.entry_number = ?
";

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $entry_number);
$stmt->execute();
$result = $stmt->get_result();
$material = $result->fetch_assoc();
$stmt->close();
$conn->close();

if (!$material) {
    die('Material not found');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Material Details - <?php echo htmlspecialchars($entry_number); ?></title>
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
        .container {
            max-width: 1200px;
            margin: 0 auto;
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
            font-size: 28px;
            margin-bottom: 8px;
        }
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .back-link:hover {
            background: #5a6268;
        }
        .close-btn {
            display: inline-block;
            margin-bottom: 20px;
            margin-left: 10px;
            padding: 10px 20px;
            background: #e74c3c;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            cursor: pointer;
            border: none;
        }
        .close-btn:hover {
            background: #c0392b;
        }
        .button-group {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .card h2 {
            color: #667eea;
            margin-bottom: 20px;
            font-size: 20px;
            border-bottom: 2px solid #e0e6ed;
            padding-bottom: 10px;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        .info-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        .info-label {
            font-size: 12px;
            color: #7f8c8d;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .info-value {
            font-size: 16px;
            color: #2c3e50;
            font-weight: 600;
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
        .badge.pending {
            background: #fff3cd;
            color: #856404;
        }
        .badge.rejected {
            background: #f8d7da;
            color: #721c24;
        }
        .badge.not-tested {
            background: #e0e6ed;
            color: #6c757d;
        }
        .test-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-top: 15px;
        }
        .test-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .test-title {
            font-size: 18px;
            font-weight: 600;
            color: #2c3e50;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        .btn-primary {
            background: #3498db;
            color: white;
        }
        .btn-primary:hover {
            background: #2980b9;
        }
        .no-test {
            color: #95a5a6;
            font-style: italic;
            padding: 15px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="button-group">
            <a href="approved_material_inventory.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Inventory
            </a>
            <button class="close-btn" onclick="closeWindow()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>

        <div class="header">
            <h1><i class="fas fa-box"></i> Material Details</h1>
            <p>Entry Number: <?php echo htmlspecialchars($entry_number); ?></p>
        </div>

        <div class="card">
            <h2><i class="fas fa-info-circle"></i> Material Information</h2>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Entry Number</div>
                    <div class="info-value"><?php echo htmlspecialchars($material['entry_number']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Material Type</div>
                    <div class="info-value"><?php echo htmlspecialchars($material['material_type']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Quantity</div>
                    <div class="info-value"><?php echo number_format($material['amount_kg'], 2); ?> kg</div>
                </div>
                <div class="info-item">
                    <div class="info-label">Received Date</div>
                    <div class="info-value"><?php echo date('d M Y, h:i A', strtotime($material['received_date'])); ?></div>
                </div>
            </div>

            <?php if (!empty($material['supplier'])): ?>
            <div class="info-grid" style="margin-top: 20px;">
                <div class="info-item">
                    <div class="info-label">Supplier</div>
                    <div class="info-value"><?php echo htmlspecialchars($material['supplier']); ?></div>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($material['remarks'])): ?>
            <div class="info-item" style="margin-top: 20px;">
                <div class="info-label">Remarks</div>
                <div class="info-value"><?php echo htmlspecialchars($material['remarks']); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2><i class="fas fa-flask"></i> Test Reports</h2>
            
            <!-- Fineness Fiber Report Section -->
            <div class="test-section">
                <div class="test-header">
                    <div class="test-title">
                        <i class="fas fa-ruler"></i> Fineness of Fiber Report (ISO 1973)
                    </div>
                    <?php if ($material['fineness_fiber_id']): ?>
                        <span class="badge <?php echo $material['fineness_fiber_status']; ?>">
                            <?php echo ucfirst($material['fineness_fiber_status']); ?>
                        </span>
                    <?php else: ?>
                        <span class="badge not-tested">Not Tested</span>
                    <?php endif; ?>
                </div>

                <?php if ($material['fineness_fiber_id']): ?>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Report Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($material['fineness_fiber_report']); ?></div>
                    </div>
                    <?php if ($material['fineness_fiber_test_date']): ?>
                    <div class="info-item">
                        <div class="info-label">Test Date</div>
                        <div class="info-value"><?php echo date('d M Y', strtotime($material['fineness_fiber_test_date'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($material['fineness_fiber_tester']): ?>
                    <div class="info-item">
                        <div class="info-label">Tested By</div>
                        <div class="info-value"><?php echo htmlspecialchars($material['fineness_fiber_tester']); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <div class="info-label">Action</div>
                        <div class="info-value">
                            <a href="../forms/fineness_fiber_report.php?view=<?php echo $material['fineness_fiber_id']; ?>&return=approved_material_inventory" 
                               class="btn btn-primary" target="_blank">
                                <i class="fas fa-eye"></i> View Report
                            </a>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="no-test">
                    <i class="fas fa-info-circle"></i> No fineness fiber test has been submitted for this material yet.
                </div>
                <?php endif; ?>
            </div>

            <!-- Cut Length Fiber Report Section -->
            <div class="test-section">
                <div class="test-header">
                    <div class="test-title">
                        <i class="fas fa-ruler-combined"></i> Cut Length of Fiber Report (ASTM D5103)
                    </div>
                    <?php if ($material['cut_length_fiber_id']): ?>
                        <span class="badge <?php echo $material['cut_length_fiber_status']; ?>">
                            <?php echo ucfirst($material['cut_length_fiber_status']); ?>
                        </span>
                    <?php else: ?>
                        <span class="badge not-tested">Not Tested</span>
                    <?php endif; ?>
                </div>

                <?php if ($material['cut_length_fiber_id']): ?>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Report Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($material['cut_length_fiber_report']); ?></div>
                    </div>
                    <?php if ($material['cut_length_fiber_test_date']): ?>
                    <div class="info-item">
                        <div class="info-label">Test Date</div>
                        <div class="info-value"><?php echo date('d M Y', strtotime($material['cut_length_fiber_test_date'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($material['cut_length_fiber_tester']): ?>
                    <div class="info-item">
                        <div class="info-label">Tested By</div>
                        <div class="info-value"><?php echo htmlspecialchars($material['cut_length_fiber_tester']); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <div class="info-label">Action</div>
                        <div class="info-value">
                            <a href="../forms/cut_length_fiber_report.php?view=<?php echo $material['cut_length_fiber_id']; ?>&return=approved_material_inventory" 
                               class="btn btn-primary" target="_blank">
                                <i class="fas fa-eye"></i> View Report
                            </a>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="no-test">
                    <i class="fas fa-info-circle"></i> No cut length fiber test has been submitted for this material yet.
                </div>
                <?php endif; ?>
            </div>

            <!-- Tenacity Fiber Report Section -->
            <div class="test-section">
                <div class="test-header">
                    <div class="test-title">
                        <i class="fas fa-weight-hanging"></i> Tenacity of Fiber Report (EN ISO 5079)
                    </div>
                    <?php if ($material['tenacity_fiber_id']): ?>
                        <span class="badge <?php echo $material['tenacity_fiber_status']; ?>">
                            <?php echo ucfirst($material['tenacity_fiber_status']); ?>
                        </span>
                    <?php else: ?>
                        <span class="badge not-tested">Not Tested</span>
                    <?php endif; ?>
                </div>

                <?php if ($material['tenacity_fiber_id']): ?>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Report Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($material['tenacity_fiber_report']); ?></div>
                    </div>
                    <?php if ($material['tenacity_fiber_test_date']): ?>
                    <div class="info-item">
                        <div class="info-label">Test Date</div>
                        <div class="info-value"><?php echo date('d M Y', strtotime($material['tenacity_fiber_test_date'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($material['tenacity_fiber_tester']): ?>
                    <div class="info-item">
                        <div class="info-label">Tested By</div>
                        <div class="info-value"><?php echo htmlspecialchars($material['tenacity_fiber_tester']); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <div class="info-label">Action</div>
                        <div class="info-value">
                            <a href="../forms/tenacity_fiber_report.php?view=<?php echo $material['tenacity_fiber_id']; ?>&return=approved_material_inventory" 
                               class="btn btn-primary" target="_blank">
                                <i class="fas fa-eye"></i> View Report
                            </a>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="no-test">
                    <i class="fas fa-info-circle"></i> No tenacity fiber test has been submitted for this material yet.
                </div>
                <?php endif; ?>
            </div>

            <!-- Tenacity Yarn Report Section -->
            <div class="test-section">
                <div class="test-header">
                    <div class="test-title">
                        <i class="fas fa-weight"></i> Tenacity of Yarn Report (ASTM D2256)
                    </div>
                    <?php if ($material['tenacity_yarn_id']): ?>
                        <span class="badge <?php echo $material['tenacity_yarn_status']; ?>">
                            <?php echo ucfirst($material['tenacity_yarn_status']); ?>
                        </span>
                    <?php else: ?>
                        <span class="badge not-tested">Not Tested</span>
                    <?php endif; ?>
                </div>

                <?php if ($material['tenacity_yarn_id']): ?>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Report Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($material['tenacity_yarn_report']); ?></div>
                    </div>
                    <?php if ($material['tenacity_yarn_test_date']): ?>
                    <div class="info-item">
                        <div class="info-label">Test Date</div>
                        <div class="info-value"><?php echo date('d M Y', strtotime($material['tenacity_yarn_test_date'])); ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($material['tenacity_yarn_tester']): ?>
                    <div class="info-item">
                        <div class="info-label">Tested By</div>
                        <div class="info-value"><?php echo htmlspecialchars($material['tenacity_yarn_tester']); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <div class="info-label">Action</div>
                        <div class="info-value">
                            <a href="../forms/tenacity_yarn_report.php?view=<?php echo $material['tenacity_yarn_id']; ?>&return=approved_material_inventory" 
                               class="btn btn-primary" target="_blank">
                                <i class="fas fa-eye"></i> View Report
                            </a>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="no-test">
                    <i class="fas fa-info-circle"></i> No tenacity yarn test has been submitted for this material yet.
                </div>
                <?php endif; ?>
            </div>

            <!-- Fiber Test Report Section -->
            <div class="test-section">
                <div class="test-header">
                    <div class="test-title">
                        <i class="fas fa-dna"></i> Fiber Test Report
                    </div>
                    <?php if ($material['fiber_test_id']): ?>
                        <span class="badge <?php echo $material['fiber_status']; ?>">
                            <?php echo ucfirst($material['fiber_status']); ?>
                        </span>
                    <?php else: ?>
                        <span class="badge not-tested">Not Tested</span>
                    <?php endif; ?>
                </div>

                <?php if ($material['fiber_test_id']): ?>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Report Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($material['fiber_report_number']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Test Date</div>
                        <div class="info-value"><?php echo date('d M Y', strtotime($material['fiber_test_date'])); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Tested By</div>
                        <div class="info-value"><?php echo htmlspecialchars($material['fiber_tester']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Action</div>
                        <div class="info-value">
                            <a href="../admin/view_fiber_report.php?id=<?php echo $material['fiber_test_id']; ?>" 
                               class="btn btn-primary" target="_blank">
                                <i class="fas fa-eye"></i> View Report
                            </a>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="no-test">
                    <i class="fas fa-info-circle"></i> No fiber test has been submitted for this material yet.
                </div>
                <?php endif; ?>
            </div>

            <!-- Sewing Thread Test Section -->
            <div class="test-section">
                <div class="test-header">
                    <div class="test-title">
                        <i class="fas fa-scroll"></i> Sewing Thread Test Report
                    </div>
                    <?php if ($material['sewing_test_id']): ?>
                        <span class="badge <?php echo $material['sewing_status']; ?>">
                            <?php echo ucfirst($material['sewing_status']); ?>
                        </span>
                    <?php else: ?>
                        <span class="badge not-tested">Not Tested</span>
                    <?php endif; ?>
                </div>

                <?php if ($material['sewing_test_id']): ?>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Report Number</div>
                        <div class="info-value"><?php echo htmlspecialchars($material['sewing_report_number']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Test Date</div>
                        <div class="info-value"><?php echo date('d M Y', strtotime($material['sewing_test_date'])); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Tested By</div>
                        <div class="info-value"><?php echo htmlspecialchars($material['sewing_tester']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Action</div>
                        <div class="info-value">
                            <a href="../admin/view_sewing_report.php?id=<?php echo $material['sewing_test_id']; ?>" 
                               class="btn btn-primary" target="_blank">
                                <i class="fas fa-eye"></i> View Report
                            </a>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="no-test">
                    <i class="fas fa-info-circle"></i> No sewing thread test has been submitted for this material yet.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    function closeWindow() {
        // Check if window was opened as a popup
        if (window.opener) {
            // If opened in popup, close it
            window.close();
        } else {
            // If navigated to, go back
            window.history.back();
            // Fallback: redirect to inventory if history is empty
            setTimeout(function() {
                window.location.href = 'approved_material_inventory.php';
            }, 100);
        }
    }

    // Allow closing with Escape key
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeWindow();
        }
    });
    </script>
</body>
</html>


