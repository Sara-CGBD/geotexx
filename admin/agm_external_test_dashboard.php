<?php
// Prevent browser caching to ensure fresh data
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

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

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$is_agm = in_array($user_role, ['admin', 'agm ops', 'agm operations']);

if (!$is_agm) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>Only AGM Operations can access this dashboard.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$colExists = function(mysqli $conn, string $table, string $column): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }
    $col = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$col}'");
    return $res && $res->num_rows > 0;
};

$ensureColumn = function(mysqli $conn, string $table, string $column, string $definition) use ($colExists) {
    if (!$colExists($conn, $table, $column)) {
        $ok = $conn->query("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
        if (!$ok) {
            // Fallback without positional clause
            $conn->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` VARCHAR(255) NULL");
        }
    }
};

// Ensure required columns exist in qc_test_orders for this dashboard
$ensureColumn($conn, 'qc_test_orders', 'status', " `status` VARCHAR(50) NOT NULL DEFAULT 'pending' AFTER report_number");
$ensureColumn($conn, 'qc_test_orders', 'inspector_name', " `inspector_name` VARCHAR(255) NULL AFTER sample_reference_id");
$ensureColumn($conn, 'qc_test_orders', 'updated_at', " `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
$ensureColumn($conn, 'qc_test_orders', 'test_data', " `test_data` JSON NULL");

$message = '';
$error = '';

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    $action = $_POST['action'];
    $id = (int)$_POST['id'];
    $remarks = trim($_POST['remarks'] ?? '');
    $approved_by = $_SESSION['username'];
    
    if ($action === 'approve') {
        $stmt = $conn->prepare("UPDATE qc_test_orders SET status = 'approved', approved_by = ?, approved_at = NOW(), admin_remarks = ? WHERE id = ?");
        $stmt->bind_param("ssi", $approved_by, $remarks, $id);
        if ($stmt->execute()) {
            $message = "Test approved successfully!";
        } else {
            $error = "Failed to approve test: " . $stmt->error;
        }
        $stmt->close();
    } elseif ($action === 'reject') {
        $rejection_reasons = $_POST['rejection_reasons'] ?? [];
        $other_reason = trim($_POST['other_reason'] ?? '');
        $rejection_text = implode(', ', $rejection_reasons);
        if (!empty($other_reason)) {
            $rejection_text .= (empty($rejection_text) ? '' : ', ') . "Other: " . $other_reason;
        }
        if (empty($rejection_text)) {
            $rejection_text = "Rejected by AGM (no specific reason provided)";
        }
        
        $stmt = $conn->prepare("UPDATE qc_test_orders SET status = 'rejected_by_approver', approved_by = ?, approved_at = NOW(), admin_remarks = ? WHERE id = ?");
        $stmt->bind_param("ssi", $approved_by, $rejection_text, $id);
        if ($stmt->execute()) {
            $message = "Test rejected successfully!";
        } else {
            $error = "Failed to reject test: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Get external test orders that are pending approval (created by AGM, don't need routing)
// These are external tests with pending_approval status
$external_tests = [];
$stmt = $conn->query("
    SELECT qto.*, ts.test_name, ts.standard_code,
           qto.inspector_name as submitted_by,
           qto.updated_at as submitted_at
    FROM qc_test_orders qto
    LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
    WHERE qto.status = 'pending_approval'
    ORDER BY qto.updated_at DESC
    LIMIT 200
");

if ($stmt) {
    while ($row = $stmt->fetch_assoc()) {
        // Check if it's an external test
        $test_data = json_decode($row['test_data'] ?? '{}', true);
        $sample_ref = trim($row['sample_reference_id'] ?? '');
        $is_external = false;
        
        // Method 1: Check sample_reference_id prefix
        if (!empty($sample_ref) && (stripos($sample_ref, 'EXT-') === 0 || stripos($sample_ref, 'TOKEN-') === 0)) {
            $is_external = true;
        }
        // Method 2: Check is_external_product flag
        if (!$is_external && isset($test_data['is_external_product']) && ($test_data['is_external_product'] == '1' || $test_data['is_external_product'] === true)) {
            $is_external = true;
        }
        
        // Only include external tests
        if ($is_external) {
            $external_tests[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AGM External Test Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: 'Poppins', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 25%, #f093fb 50%, #4facfe 75%, #00f2fe 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            padding: 20px;
            min-height: 100vh;
        }
        
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        .container { 
            max-width: 1400px; 
            margin: 0 auto; 
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border-radius: 20px; 
            box-shadow: 0 20px 60px rgba(0,0,0,0.3), 0 0 0 1px rgba(255,255,255,0.1);
            padding: 40px; 
            animation: slideIn 0.5s ease-out;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .header-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.4);
            position: relative;
            overflow: hidden;
        }
        
        .header-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .header-content {
            position: relative;
            z-index: 1;
        }
        
        h1 { 
            color: white; 
            margin-bottom: 10px; 
            display: flex; 
            align-items: center; 
            gap: 15px; 
            font-size: 32px;
            font-weight: 700;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        h1 i {
            font-size: 36px;
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .subtitle { 
            color: rgba(255,255,255,0.9); 
            margin-bottom: 0; 
            font-size: 15px; 
            font-weight: 400;
        }
        
        .stats-bar {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .stat-card {
            flex: 1;
            min-width: 200px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            border-radius: 12px;
            color: white;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.5);
        }
        
        .stat-card .stat-number {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stat-card .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .alert { 
            padding: 18px 20px; 
            border-radius: 12px; 
            margin-bottom: 25px; 
            border-left: 4px solid;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            animation: slideInLeft 0.4s ease-out;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .alert-success { 
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); 
            color: #155724; 
            border-left-color: #28a745;
        }
        
        .alert-error { 
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%); 
            color: #721c24; 
            border-left-color: #dc3545;
        }
        
        .table-wrapper {
            overflow-x: auto;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-top: 20px;
        }
        
        table { 
            width: 100%; 
            border-collapse: separate;
            border-spacing: 0;
            background: white;
        }
        
        th { 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; 
            padding: 18px 15px; 
            text-align: left; 
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.75px;
            position: sticky;
            top: 0;
            z-index: 10;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        
        th:first-child {
            border-top-left-radius: 15px;
        }
        
        th:last-child {
            border-top-right-radius: 15px;
        }
        
        td { 
            padding: 16px 15px; 
            border-bottom: 1px solid rgba(226,232,240,0.6); 
            transition: all 0.25s ease;
        }

        tbody tr {
            transition: all 0.25s ease;
        }

        tbody tr:nth-child(even) {
            background: rgba(241,245,249,0.7);
        }

        tbody tr:hover { 
            background: rgba(240,249,255,0.9);
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(15,23,42,0.08);
        }
        
        .btn-view { 
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white; 
            padding: 8px 16px; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            text-decoration: none; 
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px; 
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(79, 172, 254, 0.3);
        }
        
        .btn-view:hover { 
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(79, 172, 254, 0.5);
        }
        
        .btn-approve { 
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white; 
            padding: 8px 16px; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(17, 153, 142, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-approve:hover { 
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(17, 153, 142, 0.5);
        }
        
        .btn-reject { 
            background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);
            color: white; 
            padding: 8px 16px; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 10px rgba(235, 51, 73, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-reject:hover { 
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(235, 51, 73, 0.5);
        }
        
        .action-buttons { 
            display: flex; 
            gap: 8px; 
            flex-wrap: wrap;
        }
        
        .status-badge { 
            padding: 6px 14px; 
            border-radius: 20px; 
            font-size: 11px; 
            font-weight: 600; 
            display: inline-block;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            animation: shimmer 2s ease-in-out infinite;
        }
        
        @keyframes shimmer {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.8; }
        }
        
        .status-pending { 
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }
        
        .no-data { 
            text-align: center; 
            padding: 80px 20px; 
            color: #95a5a6;
        }
        
        .no-data i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .back-btn { 
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 25px; 
            padding: 12px 24px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white; 
            text-decoration: none; 
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        
        .back-btn:hover { 
            transform: translateX(-5px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(5px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            background: white;
            padding: 35px;
            border-radius: 20px;
            max-width: 550px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalSlideIn 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .modal-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }
        
        @keyframes modalSlideIn {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-content h2 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 24px;
            font-weight: 700;
        }
        
        .modal-content p {
            color: #7f8c8d;
            margin-bottom: 20px;
        }
        
        .modal-content label {
            display: block;
            margin: 12px 0 8px;
            color: #2c3e50;
            font-weight: 600;
            font-size: 14px;
        }
        
        .modal-content textarea,
        .modal-content input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
        }
        
        .modal-content textarea:focus,
        .modal-content input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .modal-content .checkbox-group {
            margin: 15px 0;
        }
        
        .modal-content .checkbox-group label {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border-radius: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
            font-weight: 400;
        }
        
        .modal-content .checkbox-group label:hover {
            background: #f8f9ff;
        }
        
        .modal-content .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .modal-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 25px;
        }
        
        .modal-buttons button {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }
        
        .modal-buttons button[type="button"] {
            background: #6c757d;
            color: white;
        }
        
        .modal-buttons button[type="button"]:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .modal-buttons button[type="submit"] {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            box-shadow: 0 4px 10px rgba(17, 153, 142, 0.3);
        }
        
        .modal-buttons button[type="submit"]:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(17, 153, 142, 0.5);
        }
        
        .reject-modal .modal-buttons button[type="submit"] {
            background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);
            box-shadow: 0 4px 10px rgba(235, 51, 73, 0.3);
        }
        
        .reject-modal .modal-buttons button[type="submit"]:hover {
            box-shadow: 0 6px 15px rgba(235, 51, 73, 0.5);
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="../index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        
        <div class="header-section">
            <div class="header-content">
                <h1><i class="fas fa-file-alt"></i> AGM External Test Dashboard</h1>
                <p class="subtitle">External test reports that don't require routing through tester/checker workflow</p>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($external_tests)): ?>
            <div class="stats-bar">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($external_tests); ?></div>
                    <div class="stat-label"><i class="fas fa-clipboard-list"></i> Total Tests</div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <div class="stat-number"><?php echo count($external_tests); ?></div>
                    <div class="stat-label"><i class="fas fa-clock"></i> Pending Approval</div>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (empty($external_tests)): ?>
            <div class="no-data">
                <i class="fas fa-inbox"></i>
                <p style="font-size: 18px; font-weight: 500; margin-top: 10px;">No external tests pending approval.</p>
                <p style="font-size: 14px; color: #b0b0b0; margin-top: 5px;">All external tests have been processed.</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Report Number</th>
                            <th>Test Name</th>
                            <th>Sample Reference</th>
                            <th>Test Date</th>
                            <th>Submitted By</th>
                            <th>Submitted Time</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($external_tests as $index => $test): ?>
                            <tr>
                                <td><strong><?php echo $index + 1; ?></strong></td>
                                <td><strong style="color: #667eea;"><?php echo htmlspecialchars($test['report_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($test['test_name'] . ' (' . $test['chosen_method'] . ')'); ?></td>
                                <td><?php echo htmlspecialchars($test['sample_reference_id']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($test['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($test['submitted_by'] ?? $test['inspector_name'] ?? 'N/A'); ?></td>
                                <td><?php echo !empty($test['submitted_at']) ? date('M d, Y, h:i A', strtotime($test['submitted_at'])) : (date('M d, Y, h:i A', strtotime($test['updated_at']))); ?></td>
                                <td>
                                    <span class="status-badge status-pending">Pending Approval</span>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a class="btn-view" href="view_qc_test_order.php?id=<?php echo (int)$test['id']; ?>&return=agm_external_test_dashboard" target="_blank">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                        <button class="btn-approve" onclick="openApproveModal(<?php echo (int)$test['id']; ?>, '<?php echo htmlspecialchars($test['report_number'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                        <button class="btn-reject" onclick="openRejectModal(<?php echo (int)$test['id']; ?>, '<?php echo htmlspecialchars($test['report_number'], ENT_QUOTES); ?>')">
                                            <i class="fas fa-times"></i> Reject
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Approve Modal -->
    <div id="approveModal" class="modal">
        <div class="modal-content">
            <h2><i class="fas fa-check-circle" style="color: #11998e; margin-right: 10px;"></i>Approve Test</h2>
            <p>Report Number: <strong id="approveReportNumber" style="color: #667eea;"></strong></p>
            <form method="POST" id="approveForm">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="id" id="approveId">
                <label>Remarks (Optional):</label>
                <textarea name="remarks" rows="4" placeholder="Add any additional remarks or notes..."></textarea>
                <div class="modal-buttons">
                    <button type="button" onclick="closeApproveModal()">Cancel</button>
                    <button type="submit"><i class="fas fa-check"></i> Approve</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal reject-modal">
        <div class="modal-content">
            <h2><i class="fas fa-times-circle" style="color: #eb3349; margin-right: 10px;"></i>Reject Test</h2>
            <p>Report Number: <strong id="rejectReportNumber" style="color: #667eea;"></strong></p>
            <form method="POST" id="rejectForm" onsubmit="return validateRejectForm()">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="id" id="rejectId">
                <label>Rejection Reasons (Select all that apply):</label>
                <div class="checkbox-group">
                    <label>
                        <input type="checkbox" name="rejection_reasons[]" value="Incorrect Test Data">
                        <span>Incorrect Test Data</span>
                    </label>
                    <label>
                        <input type="checkbox" name="rejection_reasons[]" value="Calculation Errors">
                        <span>Calculation Errors</span>
                    </label>
                    <label>
                        <input type="checkbox" name="rejection_reasons[]" value="Missing Information">
                        <span>Missing Information</span>
                    </label>
                    <label>
                        <input type="checkbox" name="rejection_reasons[]" value="Quality Issues">
                        <span>Quality Issues</span>
                    </label>
                    <label>
                        <input type="checkbox" name="rejection_reasons[]" value="Other" onchange="toggleOtherReason(this.checked)">
                        <span>Other</span>
                    </label>
                </div>
                <div id="otherReasonDiv" style="display: none; margin-top: 15px;">
                    <label>Other Reason:</label>
                    <textarea name="other_reason" rows="3" placeholder="Please specify the reason..."></textarea>
                </div>
                <div class="modal-buttons">
                    <button type="button" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit"><i class="fas fa-times"></i> Reject</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openApproveModal(id, reportNumber) {
            document.getElementById('approveId').value = id;
            document.getElementById('approveReportNumber').textContent = reportNumber;
            document.getElementById('approveModal').style.display = 'flex';
            document.body.style.overflow = 'hidden'; // Prevent background scrolling
        }
        
        function closeApproveModal() {
            document.getElementById('approveModal').style.display = 'none';
            document.body.style.overflow = 'auto'; // Restore scrolling
        }
        
        function openRejectModal(id, reportNumber) {
            document.getElementById('rejectId').value = id;
            document.getElementById('rejectReportNumber').textContent = reportNumber;
            document.getElementById('rejectModal').style.display = 'flex';
            document.body.style.overflow = 'hidden'; // Prevent background scrolling
            // Reset form
            document.getElementById('rejectForm').reset();
            document.getElementById('otherReasonDiv').style.display = 'none';
        }
        
        function closeRejectModal() {
            document.getElementById('rejectModal').style.display = 'none';
            document.body.style.overflow = 'auto'; // Restore scrolling
        }
        
        function toggleOtherReason(checked) {
            const otherDiv = document.getElementById('otherReasonDiv');
            if (checked) {
                otherDiv.style.display = 'block';
                otherDiv.style.animation = 'slideInLeft 0.3s ease-out';
            } else {
                otherDiv.style.display = 'none';
            }
        }
        
        function validateRejectForm() {
            const checkboxes = document.querySelectorAll('input[name="rejection_reasons[]"]:checked');
            const otherReason = document.querySelector('textarea[name="other_reason"]').value.trim();
            
            if (checkboxes.length === 0 && !otherReason) {
                alert('⚠️ Please select at least one rejection reason or provide an other reason.');
                return false;
            }
            return true;
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const approveModal = document.getElementById('approveModal');
            const rejectModal = document.getElementById('rejectModal');
            if (event.target === approveModal) {
                closeApproveModal();
            }
            if (event.target === rejectModal) {
                closeRejectModal();
            }
        }
        
        // Close modals with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeApproveModal();
                closeRejectModal();
            }
        });
    </script>
</body>
</html>


