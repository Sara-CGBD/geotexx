<?php
session_start();
require_once '../config/security_config.php';

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit;
}

// Check authorization - only admin, AGM Ops, and management can view
$allowedRoles = ['admin', 'agm ops', 'agm operations', 'management'];
$userRole = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($userRole, $allowedRoles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to view QC entries.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role'] ?? 'not set') . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Ensure new FG columns exist in the database (for backward compatibility with old entries)
$fgColumns = [
    'fg_reference_number' => 'VARCHAR(100) DEFAULT NULL',
    'fg_amount' => 'DECIMAL(10,2) DEFAULT NULL',
    'bag_no_fg' => 'VARCHAR(100) DEFAULT NULL',
    'weight_fg' => 'DECIMAL(10,2) DEFAULT NULL',
    'stitch_fg' => 'VARCHAR(100) DEFAULT NULL',
    'actual_length_fg' => 'DECIMAL(10,2) DEFAULT NULL',
    'width_fg' => 'DECIMAL(10,2) DEFAULT NULL',
    'margin_left_fg' => 'DECIMAL(10,2) DEFAULT NULL',
    'margin_right_fg' => 'DECIMAL(10,2) DEFAULT NULL'
];

// Check which columns exist and add missing ones
$existingColumns = [];
$colCheck = $conn->query("SHOW COLUMNS FROM qc_entries");
if ($colCheck) {
    while ($row = $colCheck->fetch_assoc()) {
        $existingColumns[] = $row['Field'];
    }
}

// Add missing columns only
foreach ($fgColumns as $colName => $colDef) {
    if (!in_array($colName, $existingColumns)) {
        $conn->query("ALTER TABLE qc_entries ADD COLUMN {$colName} {$colDef}");
    }
}

// Get QC entry ID from URL
$qc_entry_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($qc_entry_id === 0) {
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>❌ Invalid Request</h2>
        <p>No QC entry ID specified.</p>
        <a href='qc_approval_dashboard.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Back to Approval Dashboard</a>
        </div>");
}

// Fetch QC entry details - use qe.* to get all columns, then add computed fields
$query = "SELECT qe.*,
          COALESCE(qe.inspector_name, u.full_name, u.username, 'Unknown') as inspector_name, 
          u.username as inspector_username,
          u.role as inspector_role
          FROM qc_entries qe
          LEFT JOIN users u ON qe.reporter_id = u.id
          WHERE qe.id = ?";
$stmt = $conn->prepare($query);
if (!$stmt) {
    die("Database error: " . $conn->error);
}
$stmt->bind_param('i', $qc_entry_id);
$stmt->execute();
$result_set = $stmt->get_result();
$result = $result_set->fetch_assoc();
$stmt->close();

// Ensure all FG fields exist in result array (set to null if column doesn't exist)
if ($result) {
    foreach ($fgColumns as $colName => $colDef) {
        if (!isset($result[$colName])) {
            $result[$colName] = null;
        }
    }
}

if (!$result) {
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>❌ Not Found</h2>
        <p>QC entry not found.</p>
        <a href='qc_approval_dashboard.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Back to Approval Dashboard</a>
        </div>");
}

$qc = $result;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View QC Entry - <?php echo htmlspecialchars($qc['qc_id']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 5px 10px 5px 5px; color: #2c3e50; }
        .container { max-width: 900px; margin: 20px auto; background: white; border-radius: 8px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; font-size: 28px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 25px; font-size: 14px; }
        
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .info-card { background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #3498db; }
        .info-label { font-size: 12px; color: #7f8c8d; font-weight: 600; margin-bottom: 5px; text-transform: uppercase; }
        .info-value { font-size: 16px; color: #2c3e50; font-weight: 600; }
        
        .section { margin-bottom: 25px; padding: 20px; background: #f8f9fa; border-radius: 8px; }
        .section-title { font-size: 18px; font-weight: 700; color: #34495e; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #3498db; }
        
        .detail-row { display: flex; padding: 10px 0; border-bottom: 1px solid #ecf0f1; }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { flex: 0 0 180px; font-weight: 600; color: #7f8c8d; }
        .detail-value { flex: 1; color: #2c3e50; }
        
        .badge { display: inline-block; padding: 6px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; }
        .badge-pass { background: #d4edda; color: #155724; }
        .badge-fail { background: #f8d7da; color: #721c24; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-approved { background: #d4edda; color: #155724; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
        
        .action-buttons { display: flex; gap: 15px; margin-top: 25px; justify-content: center; }
        .btn { padding: 12px 24px; border: none; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; transition: all 0.3s; }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; transform: translateY(-2px); }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #229954; transform: translateY(-2px); }
        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; transform: translateY(-2px); }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; transform: translateY(-2px); }
        
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
    </style>
</head>
<body>
<div class="container">
    <a href="qc_approval_dashboard.php" class="btn btn-secondary" style="margin-bottom: 20px;">
        <i class="fas fa-arrow-left"></i> Back to Approval Dashboard
    </a>
    
    <h1><i class="fas fa-clipboard-check"></i> QC Entry Details</h1>
    <p class="subtitle">View detailed information for QC Entry <?php echo htmlspecialchars($qc['qc_id']); ?></p>
    
    <!-- Status Banner -->
    <?php if ($qc['status'] === 'pending'): ?>
        <div class="alert alert-info">
            <strong><i class="fas fa-info-circle"></i> Status:</strong> This QC entry is pending approval.
        </div>
    <?php endif; ?>
    
    <!-- Main Information Grid -->
    <div class="info-grid">
        <div class="info-card">
            <div class="info-label">QC ID</div>
            <div class="info-value"><?php echo htmlspecialchars($qc['qc_id']); ?></div>
        </div>
        
        <div class="info-card">
            <div class="info-label">Date & Time</div>
            <div class="info-value"><?php echo date('d M Y, h:i A', strtotime($qc['date_time'])); ?></div>
        </div>
        
        <div class="info-card">
            <div class="info-label">Shift</div>
            <div class="info-value"><?php echo htmlspecialchars($qc['shift']); ?></div>
        </div>
        
        <div class="info-card">
            <div class="info-label">Status</div>
            <div class="info-value">
                <span class="badge badge-<?php echo $qc['status']; ?>">
                    <?php echo ucfirst($qc['status']); ?>
                </span>
            </div>
        </div>
    </div>
    
    <!-- QC Details Section -->
    <div class="section">
        <div class="section-title"><i class="fas fa-tasks"></i> QC Information</div>
        
        <div class="detail-row">
            <div class="detail-label">QC Stage:</div>
            <div class="detail-value"><strong><?php echo htmlspecialchars($qc['qc_stage']); ?></strong></div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">QC Type:</div>
            <div class="detail-value"><strong><?php echo htmlspecialchars($qc['qc_type']); ?></strong></div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Result:</div>
            <div class="detail-value">
                <span class="badge <?php echo $qc['qc_result'] === 'Pass' ? 'badge-pass' : 'badge-fail'; ?>">
                    <?php echo htmlspecialchars($qc['qc_result']); ?>
                </span>
            </div>
        </div>
        
        <?php if (!empty($qc['remarks'])): ?>
        <div class="detail-row">
            <div class="detail-label">Remarks:</div>
            <div class="detail-value"><?php echo nl2br(htmlspecialchars($qc['remarks'])); ?></div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Inspector Information -->
    <div class="section">
        <div class="section-title"><i class="fas fa-user"></i> Inspector Information</div>
        
        <div class="detail-row">
            <div class="detail-label">Inspector Name:</div>
            <div class="detail-value"><?php echo htmlspecialchars($qc['inspector_name']); ?></div>
        </div>
        
        <?php if (!empty($qc['inspector_role'])): ?>
        <div class="detail-row">
            <div class="detail-label">Role:</div>
            <div class="detail-value"><?php echo htmlspecialchars($qc['inspector_role']); ?></div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Stage-Specific Information -->
    <?php if ($qc['qc_stage'] === 'Roll' && !empty($qc['roll_number'])): ?>
    <div class="section">
        <div class="section-title"><i class="fas fa-scroll"></i> Roll Information</div>
        
        <div class="detail-row">
            <div class="detail-label">Roll Number:</div>
            <div class="detail-value"><?php echo htmlspecialchars($qc['roll_number']); ?></div>
        </div>
        
        <?php if (!empty($qc['batch_number'])): ?>
        <div class="detail-row">
            <div class="detail-label">Batch Number:</div>
            <div class="detail-value"><?php echo htmlspecialchars($qc['batch_number']); ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($qc['qc_stage'] === 'CNC' && !empty($qc['cnc_id'])): ?>
    <div class="section">
        <div class="section-title"><i class="fas fa-cut"></i> CNC Information</div>
        
        <div class="detail-row">
            <div class="detail-label">CNC ID:</div>
            <div class="detail-value"><?php echo htmlspecialchars($qc['cnc_id']); ?></div>
        </div>
        
        <?php if (!empty($qc['roll_number'])): ?>
        <div class="detail-row">
            <div class="detail-label">Roll Number:</div>
            <div class="detail-value"><?php echo htmlspecialchars($qc['roll_number']); ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($qc['qc_stage'] === 'Production'): ?>
    <div class="section">
        <div class="section-title"><i class="fas fa-industry"></i> Production Information</div>
        
        <?php if (!empty($qc['production_id'])): ?>
        <div class="detail-row">
            <div class="detail-label">Production ID:</div>
            <div class="detail-value"><?php echo htmlspecialchars($qc['production_id']); ?></div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($qc['roll_number'])): ?>
        <div class="detail-row">
            <div class="detail-label">Roll Number:</div>
            <div class="detail-value"><?php echo htmlspecialchars($qc['roll_number']); ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($qc['qc_stage'] === 'FG'): ?>
    <div class="section">
        <div class="section-title"><i class="fas fa-box"></i> Finished Goods Information</div>
        
        <div class="detail-row">
            <div class="detail-label">Product Reference:</div>
            <div class="detail-value"><?php echo !empty($qc['fg_reference_number']) ? htmlspecialchars($qc['fg_reference_number']) : '0'; ?></div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Amount:</div>
            <div class="detail-value"><?php echo !empty($qc['fg_amount']) ? htmlspecialchars($qc['fg_amount']) : '0'; ?></div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Bag No:</div>
            <div class="detail-value"><?php echo !empty($qc['bag_no_fg']) ? htmlspecialchars($qc['bag_no_fg']) : '0'; ?></div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Weight:</div>
            <div class="detail-value"><?php echo !empty($qc['weight_fg']) ? htmlspecialchars($qc['weight_fg']) : '0'; ?></div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Stitch:</div>
            <div class="detail-value"><?php echo !empty($qc['stitch_fg']) ? htmlspecialchars($qc['stitch_fg']) : '0'; ?></div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Actual Length:</div>
            <div class="detail-value"><?php echo !empty($qc['actual_length_fg']) ? htmlspecialchars($qc['actual_length_fg']) : '0'; ?></div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Width:</div>
            <div class="detail-value"><?php echo !empty($qc['width_fg']) ? htmlspecialchars($qc['width_fg']) : '0'; ?></div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Margin Left:</div>
            <div class="detail-value"><?php echo !empty($qc['margin_left_fg']) ? htmlspecialchars($qc['margin_left_fg']) : '0'; ?></div>
        </div>
        
        <div class="detail-row">
            <div class="detail-label">Margin Right:</div>
            <div class="detail-value"><?php echo !empty($qc['margin_right_fg']) ? htmlspecialchars($qc['margin_right_fg']) : '0'; ?></div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Approval Information -->
    <?php if ($qc['status'] !== 'pending'): ?>
    <div class="section">
        <div class="section-title"><i class="fas fa-check-circle"></i> Approval Information</div>
        
        <?php if (!empty($qc['approved_by'])): ?>
        <div class="detail-row">
            <div class="detail-label">Approved/Rejected By:</div>
            <div class="detail-value"><?php echo htmlspecialchars($qc['approved_by']); ?></div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($qc['approved_at'])): ?>
        <div class="detail-row">
            <div class="detail-label">Approved/Rejected At:</div>
            <div class="detail-value"><?php echo date('d M Y, h:i A', strtotime($qc['approved_at'])); ?></div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($qc['rejection_reason'])): ?>
        <div class="detail-row">
            <div class="detail-label">Rejection Reason:</div>
            <div class="detail-value" style="color: #e74c3c; font-weight: 600;"><?php echo nl2br(htmlspecialchars($qc['rejection_reason'])); ?></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- Action Buttons -->
    <?php if ($qc['status'] === 'pending'): ?>
    <div class="action-buttons">
        <button type="button" class="btn btn-success" onclick="openApproveModal(<?php echo $qc['id']; ?>)">
            <i class="fas fa-check"></i> Approve Entry
        </button>
        
        <button type="button" class="btn btn-danger" onclick="openRejectModal(<?php echo $qc['id']; ?>)">
            <i class="fas fa-times"></i> Reject Entry
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Approve Modal -->
<div id="approveModal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5);">
    <div style="background-color:white; margin:5% auto; padding:30px; border-radius:10px; width:90%; max-width:500px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
        <div style="font-size:20px; font-weight:bold; margin-bottom:20px; color:#34495e;">
            <i class="fas fa-check-circle" style="color:#27ae60;"></i> Approve QC Entry
        </div>
        <p style="margin-bottom: 20px; color: #6c757d;">QC ID: <strong id="approve_qc_id_display"></strong></p>
        <form method="POST" action="qc_approval_dashboard.php" id="approveForm">
            <input type="hidden" name="qc_id" id="approve_qc_id" value="<?php echo $qc['id']; ?>">
            <input type="hidden" name="action" value="approve">
            
            <label style="font-weight:600; margin-bottom:10px; display:block; color:#2c3e50;">
                <i class="fas fa-clipboard-check"></i> Final Result (Pass/Fail):
            </label>
            <div style="display:flex; gap:15px; margin-bottom:20px;">
                <label style="display:flex; align-items:center; cursor:pointer; flex:1;">
                    <input type="radio" name="final_result" value="Pass" checked style="margin-right:8px; width:auto;">
                    <span style="padding:10px 20px; background:#d4edda; color:#155724; border-radius:6px; font-weight:600; text-align:center; width:100%;">Pass</span>
                </label>
                <label style="display:flex; align-items:center; cursor:pointer; flex:1;">
                    <input type="radio" name="final_result" value="Fail" style="margin-right:8px; width:auto;">
                    <span style="padding:10px 20px; background:#f8d7da; color:#721c24; border-radius:6px; font-weight:600; text-align:center; width:100%;">Fail</span>
                </label>
            </div>
            
            <div style="margin-top:15px;">
                <label style="font-weight:600; display:block; margin-bottom:8px; color:#2c3e50;">Comments (Optional):</label>
                <textarea name="approval_comments" id="approval_comments" rows="3" placeholder="Add any comments..." style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px; font-family:inherit; resize:vertical;"></textarea>
            </div>
            
            <div style="margin-top:25px; display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" onclick="closeApproveModal()" style="padding:10px 20px; background:#95a5a6; color:white; border:none; border-radius:5px; cursor:pointer; font-weight:600;">
                    Cancel
                </button>
                <button type="submit" style="padding:10px 20px; background:#27ae60; color:white; border:none; border-radius:5px; cursor:pointer; font-weight:600;">
                    <i class="fas fa-check"></i> Approve Entry
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reject Modal -->
<div id="rejectModal" style="display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.5);">
    <div style="background-color:white; margin:5% auto; padding:30px; border-radius:10px; width:90%; max-width:500px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
        <div style="font-size:20px; font-weight:bold; margin-bottom:20px; color:#34495e;">
            <i class="fas fa-times-circle" style="color:#e74c3c;"></i> Reject QC Entry
        </div>
        <form method="POST" action="qc_approval_dashboard.php" id="rejectForm" onsubmit="return validateRejectForm()">
            <input type="hidden" name="qc_id" id="reject_qc_id" value="<?php echo $qc['id']; ?>">
            <input type="hidden" name="action" value="reject">
            
            <label style="font-weight:600; display:block; margin-bottom:10px; color:#c0392b;">
                <i class="fas fa-exclamation-triangle"></i> Reason for Rejection (Select at least one - Mandatory):
            </label>
            
            <div style="margin-bottom:8px;">
                <label style="font-weight:normal; display:block;">
                    <input type="checkbox" name="rejection_reasons[]" value="Incorrect QC Type Selected" style="margin-right:8px;">
                    Incorrect QC Type Selected
                </label>
            </div>
            <div style="margin-bottom:8px;">
                <label style="font-weight:normal; display:block;">
                    <input type="checkbox" name="rejection_reasons[]" value="Failed Quality Standards" style="margin-right:8px;">
                    Failed Quality Standards
                </label>
            </div>
            <div style="margin-bottom:8px;">
                <label style="font-weight:normal; display:block;">
                    <input type="checkbox" name="rejection_reasons[]" value="Incomplete Information" style="margin-right:8px;">
                    Incomplete Information
                </label>
            </div>
            <div style="margin-bottom:8px;">
                <label style="font-weight:normal; display:block;">
                    <input type="checkbox" name="rejection_reasons[]" value="Incorrect Stage/Process" style="margin-right:8px;">
                    Incorrect Stage/Process
                </label>
            </div>
            <div style="margin-bottom:8px;">
                <label style="font-weight:normal; display:block;">
                    <input type="checkbox" name="rejection_reasons[]" value="Data Entry Errors" style="margin-right:8px;">
                    Data Entry Errors
                </label>
            </div>
            <div style="margin-bottom:8px;">
                <label style="font-weight:normal; display:block;">
                    <input type="checkbox" name="rejection_reasons[]" value="Other" style="margin-right:8px;">
                    Other (Specify below)
                </label>
            </div>
            
            <div style="margin-top:15px;">
                <label style="font-weight:600; display:block; margin-bottom:8px;">Additional Comments (Optional):</label>
                <textarea name="rejection_comments" rows="3" placeholder="Add any additional details..." style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px; font-family:inherit;"></textarea>
            </div>
            
            <div id="rejectError" style="display:none; color:#e74c3c; font-weight:600; margin-top:10px; padding:8px; background:#f8d7da; border-radius:4px;">
                ⚠️ Please select at least one rejection reason.
            </div>
            
            <div style="margin-top:20px; text-align:right; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" onclick="closeRejectModal()" style="background:#6c757d; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:pointer; font-size:14px;">Cancel</button>
                <button type="submit" id="rejectSubmitBtn" style="background:#e74c3c; color:white; border:none; padding:10px 20px; border-radius:5px; cursor:not-allowed; font-size:14px; opacity:0.6;" disabled>Reject Entry</button>
            </div>
        </form>
    </div>
</div>

<script>
function openApproveModal(qcId) {
    document.getElementById('approve_qc_id').value = qcId;
    document.getElementById('approve_qc_id_display').textContent = '<?php echo htmlspecialchars($qc['qc_id'] ?? 'N/A'); ?>';
    document.getElementById('approveModal').style.display = 'block';
    
    // Reset form
    document.querySelector('input[name="final_result"][value="Pass"]').checked = true;
    document.getElementById('approval_comments').value = '';
}

function closeApproveModal() {
    document.getElementById('approveModal').style.display = 'none';
}

function openRejectModal(qcId) {
    document.getElementById('reject_qc_id').value = qcId;
    document.getElementById('rejectModal').style.display = 'block';
    
    // Reset form
    document.getElementById('rejectForm').reset();
    document.getElementById('rejectError').style.display = 'none';
    const submitBtn = document.getElementById('rejectSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.style.opacity = '0.6';
    submitBtn.style.cursor = 'not-allowed';
    
    // Enable/disable submit button based on checkbox selection
    const checkboxes = document.querySelectorAll('input[name="rejection_reasons[]"]');
    checkboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            const anyChecked = Array.from(checkboxes).some(c => c.checked);
            submitBtn.disabled = !anyChecked;
            submitBtn.style.cursor = anyChecked ? 'pointer' : 'not-allowed';
            submitBtn.style.opacity = anyChecked ? '1' : '0.6';
            
            // Hide error message if user selects a checkbox
            if (anyChecked) {
                document.getElementById('rejectError').style.display = 'none';
            }
        });
    });
}

function closeRejectModal() {
    document.getElementById('rejectModal').style.display = 'none';
    document.getElementById('rejectForm').reset();
    document.getElementById('rejectError').style.display = 'none';
}

function validateRejectForm() {
    const checkboxes = document.querySelectorAll('input[name="rejection_reasons[]"]');
    const anyChecked = Array.from(checkboxes).some(c => c.checked);
    
    if (!anyChecked) {
        document.getElementById('rejectError').style.display = 'block';
        return false;
    }
    
    return true;
}

// Close modal when clicking outside
window.onclick = function(event) {
    const rejectModal = document.getElementById('rejectModal');
    const approveModal = document.getElementById('approveModal');
    if (event.target === rejectModal) {
        closeRejectModal();
    } else if (event.target === approveModal) {
        closeApproveModal();
    }
}
</script>

</body>
</html>


