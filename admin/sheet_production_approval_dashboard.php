<?php
session_start();
include_once(__DIR__ . '/../dev/auto_reload.php'); // Auto-reload for development

// Performance monitoring
require_once '../config/PerformanceMonitor.php';
PerformanceMonitor::start();

require_once '../forms/security_config.php';

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

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'agm ops', 'agm operations', 'management'];

if (!in_array($user_role, $allowed_roles)) {
    echo "🚫 Access Denied<br>You do not have permission to access the Sheet Production Approval Dashboard.";
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$message = '';
$error = '';

// Handle approval/rejection for Fiber Test Reports
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fiber_action'], $_POST['fiber_report_number'])) {
    $action = $_POST['fiber_action'];
    $report_number = trim($_POST['fiber_report_number']);
    $comment = trim($_POST['fiber_comment'] ?? '');
    
    // Handle rejection reasons checkboxes
    if ($action === 'rejected' && isset($_POST['fiber_rejection_reasons']) && is_array($_POST['fiber_rejection_reasons'])) {
        $rejection_reasons = array_map('trim', $_POST['fiber_rejection_reasons']);
        $reasons_text = implode(', ', $rejection_reasons);
        $comment = "Rejection Reasons: " . $reasons_text . ($comment ? "\n\nAdditional Comments: " . $comment : '');
    }
    
    $approver = $_SESSION['full_name'] ?? $_SESSION['username'];
    $status = ($action === 'approved') ? 'approved' : 'rejected';
    
    $stmt = $conn->prepare("UPDATE fiber_test_reports SET status = ?, approved_by = ?, approved_at = NOW(), checker_remarks = ? WHERE report_number = ?");
    $stmt->bind_param("ssss", $status, $approver, $comment, $report_number);
    
    if ($stmt->execute()) {
        $message = "Fiber Test Report {$report_number} has been {$status}!";
    } else {
        $error = "Failed to update Fiber Test Report.";
    }
    $stmt->close();
}

// Handle approval/rejection for Sewing Thread Reports
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sewing_action'], $_POST['sewing_report_number'])) {
    $action = $_POST['sewing_action'];
    $report_number = trim($_POST['sewing_report_number']);
    $comment = trim($_POST['sewing_comment'] ?? '');
    
    // Handle rejection reasons checkboxes
    if ($action === 'rejected' && isset($_POST['sewing_rejection_reasons']) && is_array($_POST['sewing_rejection_reasons'])) {
        $rejection_reasons = array_map('trim', $_POST['sewing_rejection_reasons']);
        $reasons_text = implode(', ', $rejection_reasons);
        $comment = "Rejection Reasons: " . $reasons_text . ($comment ? "\n\nAdditional Comments: " . $comment : '');
    }
    
    $approver = $_SESSION['full_name'] ?? $_SESSION['username'];
    $status = ($action === 'approved') ? 'approved' : 'rejected';
    
    $stmt = $conn->prepare("UPDATE sewing_thread_reports SET status = ?, approved_by = ?, remarks = ? WHERE report_number = ?");
    $stmt->bind_param("ssss", $status, $approver, $comment, $report_number);
    
    if ($stmt->execute()) {
        $message = "Sewing Thread Report {$report_number} has been {$status}!";
    } else {
        $error = "Failed to update Sewing Thread Report.";
    }
    $stmt->close();
}

// Fetch pending Fiber Test Reports
$pendingFiberTests = [];
$fiberQuery = "SELECT ft.*, u.full_name as reporter_full_name 
               FROM fiber_test_reports ft
               LEFT JOIN users u ON ft.reporter_id = u.id
               WHERE ft.status = 'pending'
               ORDER BY ft.created_at DESC";
$fiberResult = $conn->query($fiberQuery);
if ($fiberResult) {
    while ($row = $fiberResult->fetch_assoc()) {
        $pendingFiberTests[] = $row;
    }
}

// Fetch pending Sewing Thread Reports
$pendingSewingTests = [];
$sewingQuery = "SELECT st.*, u.full_name as reporter_full_name 
                FROM sewing_thread_reports st
                LEFT JOIN users u ON st.reporter_id = u.id
                WHERE st.status = 'pending'
                ORDER BY st.created_at DESC";
$sewingResult = $conn->query($sewingQuery);
if ($sewingResult) {
    while ($row = $sewingResult->fetch_assoc()) {
        $pendingSewingTests[] = $row;
    }
}

// Get statistics
$fiberPendingCount = count($pendingFiberTests);
$sewingPendingCount = count($pendingSewingTests);
$totalPending = $fiberPendingCount + $sewingPendingCount;

$fiberApprovedCount = $conn->query("SELECT COUNT(*) as cnt FROM fiber_test_reports WHERE status = 'approved'")->fetch_assoc()['cnt'];
$fiberRejectedCount = $conn->query("SELECT COUNT(*) as cnt FROM fiber_test_reports WHERE status = 'rejected'")->fetch_assoc()['cnt'];

$sewingApprovedCount = $conn->query("SELECT COUNT(*) as cnt FROM sewing_thread_reports WHERE status = 'approved'")->fetch_assoc()['cnt'];
$sewingRejectedCount = $conn->query("SELECT COUNT(*) as cnt FROM sewing_thread_reports WHERE status = 'rejected'")->fetch_assoc()['cnt'];

$totalApproved = $fiberApprovedCount + $sewingApprovedCount;
$totalRejected = $fiberRejectedCount + $sewingRejectedCount;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sheet Production Approval Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: #f5f7fa; 
            padding: 5px 10px 5px 5px; 
            color: #2c3e50; 
        }
        .container { 
            max-width: 100%; 
            margin: 0; 
            margin-left: 0; 
            background: white; 
            border-radius: 8px; 
            padding: 15px 25px 15px 10px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.08); 
        }
        h1 { 
            text-align: center; 
            color: #34495e; 
            margin-bottom: 5px; 
            font-size: 24px; 
        }
        .subtitle { 
            text-align: center; 
            color: #7f8c8d; 
            margin-bottom: 15px; 
            font-size: 14px; 
        }
        .stats { 
            display: flex; 
            justify-content: center; 
            gap: 30px; 
            margin-bottom: 15px; 
            padding: 15px; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
            border-radius: 8px; 
            color: white; 
            flex-wrap: wrap;
        }
        .stat-item { 
            text-align: center; 
            min-width: 150px;
        }
        .stat-value { 
            font-size: 2.5em; 
            font-weight: bold; 
        }
        .stat-label { 
            font-size: 0.9em; 
            opacity: 0.9; 
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #ecf0f1;
        }
        .section h2 {
            color: #34495e;
            margin-top: 0;
            margin-bottom: 15px;
            font-size: 18px;
            font-weight: 600;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 6px;
            overflow: hidden;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ecf0f1;
        }
        th {
            background: #34495e;
            color: white;
            font-weight: 600;
            font-size: 13px;
            position: sticky;
            top: 0;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .test-badge {
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: 600;
            display: inline-block;
        }
        .badge-fiber {
            background: #e3f2fd;
            color: #1565c0;
        }
        .badge-sewing {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .badge-pending {
            background: #fff3cd;
            color: #856404;
        }
        .approve-btn {
            background: #27ae60;
            color: white;
            padding: 6px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9em;
            font-weight: 500;
        }
        .approve-btn:hover {
            background: #229954;
        }
        .reject-btn {
            background: #e74c3c;
            color: white;
            padding: 6px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-left: 5px;
            font-size: 0.9em;
            font-weight: 500;
        }
        .reject-btn:hover {
            background: #c0392b;
        }
        .view-btn {
            background: #3498db;
            color: white;
            padding: 6px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-left: 5px;
            font-size: 0.9em;
            text-decoration: none;
            display: inline-block;
            font-weight: 500;
        }
        .view-btn:hover {
            background: #2980b9;
        }
        .back-btn {
            display: inline-block;
            margin-bottom: 20px;
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.3s;
        }
        
        .back-btn:hover {
            background: #5a6268;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: #6c757d;
            font-size: 16px;
        }
        
        .no-data i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background: white;
            margin: 10% auto;
            padding: 30px;
            border-radius: 12px;
            max-width: 500px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            margin-bottom: 20px;
        }
        
        .modal-header h3 {
            color: #dc3545;
            font-size: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-family: inherit;
            resize: vertical;
            min-height: 100px;
        }
        
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .btn-cancel {
            background: #6c757d;
            color: white;
        }
        
        .btn-cancel:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="../index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1><i class="fas fa-scroll"></i> Sheet Production Approval Dashboard</h1>
        <p class="subtitle">Review and approve Fiber Test and Sewing Thread reports submitted by testers</p>
        
        <div class="stats">
            <div class="stat-item">
                <div class="stat-value"><?php echo $totalPending; ?></div>
                <div class="stat-label">Pending Approvals</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $totalApproved; ?></div>
                <div class="stat-label">Total Approved</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $totalRejected; ?></div>
                <div class="stat-label">Total Rejected</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $fiberPendingCount; ?></div>
                <div class="stat-label">Fiber Tests</div>
            </div>
            <div class="stat-item">
                <div class="stat-value"><?php echo $sewingPendingCount; ?></div>
                <div class="stat-label">Sewing Tests</div>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success">✅ <?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
            
            <!-- Pending Fiber Test Reports -->
            <div class="section">
                <h2><i class="fas fa-dna"></i> Pending Fiber Test Reports (<?php echo count($pendingFiberTests); ?>)</h2>
                <?php if (count($pendingFiberTests) > 0): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Report Number</th>
                                    <th>Sample ID (Ref)</th>
                                    <th>Reporter</th>
                                    <th>Submitted Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingFiberTests as $report): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($report['report_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($report['sample_id']); ?></td>
                                        <td><?php echo htmlspecialchars($report['reporter_full_name'] ?? $report['reporter_name']); ?></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($report['created_at'])); ?></td>
                                        <td><span class="badge badge-pending">Pending</span></td>
                                        <td>
                                            <a href="view_fiber_report.php?id=<?php echo $report['id']; ?>" target="_blank" class="view-btn">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="fiber_report_number" value="<?php echo htmlspecialchars($report['report_number']); ?>">
                                                <button type="submit" name="fiber_action" value="approved" class="approve-btn">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                            </form>
                                            <button onclick="showFiberRejectModal('<?php echo htmlspecialchars($report['report_number']); ?>')" class="reject-btn">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-clipboard-check"></i>
                        <p>No pending fiber test reports</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Pending Sewing Thread Reports -->
            <div class="section">
                <h2><i class="fas fa-thread"></i> Pending Sewing Thread Reports (<?php echo count($pendingSewingTests); ?>)</h2>
                <?php if (count($pendingSewingTests) > 0): ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Report Number</th>
                                    <th>Reference</th>
                                    <th>Sample Description</th>
                                    <th>Reporter</th>
                                    <th>Submitted Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingSewingTests as $report): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($report['report_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($report['reference']); ?></td>
                                        <td><?php echo htmlspecialchars($report['sample_description']); ?></td>
                                        <td><?php echo htmlspecialchars($report['reporter_full_name'] ?? $report['reporter_name']); ?></td>
                                        <td><?php echo date('M d, Y H:i', strtotime($report['created_at'])); ?></td>
                                        <td><span class="badge badge-pending">Pending</span></td>
                                        <td>
                                            <a href="view_sewing_report.php?id=<?php echo $report['id']; ?>" target="_blank" class="view-btn">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="sewing_report_number" value="<?php echo htmlspecialchars($report['report_number']); ?>">
                                                <button type="submit" name="sewing_action" value="approved" class="approve-btn">
                                                    <i class="fas fa-check"></i> Approve
                                                </button>
                                            </form>
                                            <button onclick="showSewingRejectModal('<?php echo htmlspecialchars($report['report_number']); ?>')" class="reject-btn">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-clipboard-check"></i>
                        <p>No pending sewing thread reports</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Fiber Test Reject Modal -->
    <div id="fiberRejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle"></i> Reject Fiber Test Report</h3>
            </div>
            <form method="POST" id="fiberRejectForm" onsubmit="return validateFiberRejection()">
                <input type="hidden" id="fiberRejectReportNumber" name="fiber_report_number" value="">
                <input type="hidden" name="fiber_action" value="rejected">
                
                <div class="form-group">
                    <label style="font-weight:600; margin-bottom:12px; display:block;">Reason for Rejection (Select at least one):</label>
                    <div style="margin-bottom:10px;">
                        <label style="font-weight:normal; display:flex; align-items:center; cursor:pointer;">
                            <input type="checkbox" name="fiber_rejection_reasons[]" value="Incorrect Roll Identification" style="margin-right:8px;">
                            Incorrect Roll Identification
                        </label>
                    </div>
                    <div style="margin-bottom:10px;">
                        <label style="font-weight:normal; display:flex; align-items:center; cursor:pointer;">
                            <input type="checkbox" name="fiber_rejection_reasons[]" value="Incorrect Fiber Specification Entry" style="margin-right:8px;">
                            Incorrect Fiber Specification Entry
                        </label>
                    </div>
                    <div style="margin-bottom:10px;">
                        <label style="font-weight:normal; display:flex; align-items:center; cursor:pointer;">
                            <input type="checkbox" name="fiber_rejection_reasons[]" value="Excessive Sampling" style="margin-right:8px;">
                            Excessive Sampling
                        </label>
                    </div>
                    <div style="margin-bottom:10px;">
                        <label style="font-weight:normal; display:flex; align-items:center; cursor:pointer;">
                            <input type="checkbox" name="fiber_rejection_reasons[]" value="Test values not filled" style="margin-right:8px;">
                            Test values not filled
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Additional Comments (Optional):</label>
                    <textarea name="fiber_comment" placeholder="Enter any additional comments here..."></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeFiberRejectModal()" class="btn btn-cancel">Cancel</button>
                    <button type="submit" class="btn btn-reject">Reject Report</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Sewing Thread Reject Modal -->
    <div id="sewingRejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-times-circle"></i> Reject Sewing Thread Report</h3>
            </div>
            <form method="POST" id="sewingRejectForm" onsubmit="return validateSewingRejection()">
                <input type="hidden" id="sewingRejectReportNumber" name="sewing_report_number" value="">
                <input type="hidden" name="sewing_action" value="rejected">
                
                <div class="form-group">
                    <label style="font-weight:600; margin-bottom:12px; display:block;">Reason for Rejection (Select at least one):</label>
                    <div style="margin-bottom:10px;">
                        <label style="font-weight:normal; display:flex; align-items:center; cursor:pointer;">
                            <input type="checkbox" name="sewing_rejection_reasons[]" value="Incorrect Thread Specification" style="margin-right:8px;">
                            Incorrect Thread Specification
                        </label>
                    </div>
                    <div style="margin-bottom:10px;">
                        <label style="font-weight:normal; display:flex; align-items:center; cursor:pointer;">
                            <input type="checkbox" name="sewing_rejection_reasons[]" value="Test values not filled" style="margin-right:8px;">
                            Test values not filled
                        </label>
                    </div>
                    <div style="margin-bottom:10px;">
                        <label style="font-weight:normal; display:flex; align-items:center; cursor:pointer;">
                            <input type="checkbox" name="sewing_rejection_reasons[]" value="Incomplete Test Data" style="margin-right:8px;">
                            Incomplete Test Data
                        </label>
                    </div>
                    <div style="margin-bottom:10px;">
                        <label style="font-weight:normal; display:flex; align-items:center; cursor:pointer;">
                            <input type="checkbox" name="sewing_rejection_reasons[]" value="Invalid Reference Number" style="margin-right:8px;">
                            Invalid Reference Number
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Additional Comments (Optional):</label>
                    <textarea name="sewing_comment" placeholder="Enter any additional comments here..."></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" onclick="closeSewingRejectModal()" class="btn btn-cancel">Cancel</button>
                    <button type="submit" class="btn btn-reject">Reject Report</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function showFiberRejectModal(reportNumber) {
            document.getElementById('fiberRejectReportNumber').value = reportNumber;
            document.getElementById('fiberRejectForm').reset();
            document.getElementById('fiberRejectModal').style.display = 'block';
        }
        
        function closeFiberRejectModal() {
            document.getElementById('fiberRejectModal').style.display = 'none';
            document.getElementById('fiberRejectForm').reset();
        }
        
        function validateFiberRejection() {
            const checkboxes = document.querySelectorAll('input[name="fiber_rejection_reasons[]"]');
            const checked = Array.from(checkboxes).filter(cb => cb.checked);
            
            if (checked.length === 0) {
                alert('❌ Please select at least one reason for rejection!');
                return false;
            }
            return true;
        }
        
        function showSewingRejectModal(reportNumber) {
            document.getElementById('sewingRejectReportNumber').value = reportNumber;
            document.getElementById('sewingRejectForm').reset();
            document.getElementById('sewingRejectModal').style.display = 'block';
        }
        
        function closeSewingRejectModal() {
            document.getElementById('sewingRejectModal').style.display = 'none';
            document.getElementById('sewingRejectForm').reset();
        }
        
        function validateSewingRejection() {
            const checkboxes = document.querySelectorAll('input[name="sewing_rejection_reasons[]"]');
            const checked = Array.from(checkboxes).filter(cb => cb.checked);
            
            if (checked.length === 0) {
                alert('❌ Please select at least one reason for rejection!');
                return false;
            }
            return true;
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                closeFiberRejectModal();
                closeSewingRejectModal();
            }
        }
    </script>
    
<script>
// Refresh only when approve/reject actions happen
(function() {
    let isRefreshing = false;
    
    // Function to refresh the dashboard
    function refreshDashboard() {
        if (isRefreshing) return;
        isRefreshing = true;
        
        // Reload the page with cache busting
        window.location.href = window.location.href.split('?')[0] + '?t=' + new Date().getTime();
    }
    
    // Listen for messages from child windows (approval/rejection pages)
    window.addEventListener('message', function(event) {
        // Verify origin for security
        if (event.origin !== window.location.origin) {
            return;
        }
        
        // If message indicates a report was processed (approved/rejected), refresh
        if (event.data && (event.data.type === 'report_processed' || event.data.type === 'report_approved' || event.data.type === 'report_rejected')) {
            // Small delay to ensure database is updated
            setTimeout(function() {
                refreshDashboard();
            }, 300);
        }
    });
})();
</script>
    
<?php PerformanceMonitor::end('sheet_production_approval_dashboard'); ?>
</body>
</html>


