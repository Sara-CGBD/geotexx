<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$entry_id = $_GET['id'] ?? '';
if (empty($entry_id)) {
    die('Invalid entry ID');
}

// Check if opened from approval dashboard
$isApprovalDashboard = isset($_GET['approval_dashboard']) && $_GET['approval_dashboard'] == '1';

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Fetch GSM check details
$stmt = $conn->prepare("SELECT * FROM daily_gsm_checks WHERE entry_id = ? ORDER BY id");
$stmt->bind_param("s", $entry_id);
$stmt->execute();
$result = $stmt->get_result();

$checks = [];
$firstRow = null;
while ($row = $result->fetch_assoc()) {
    if (!$firstRow) {
        $firstRow = $row;
    }
    $checks[] = $row;
}
$stmt->close();
$conn->close();

if (!$firstRow) {
    die('Entry not found');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View GSM Check - <?php echo htmlspecialchars($entry_id); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f4f6f9; color: #2c3e50; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .header { border-bottom: 2px solid #667eea; padding-bottom: 20px; margin-bottom: 30px; }
        .header h1 { color: #667eea; font-size: 24px; margin-bottom: 10px; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 30px; }
        .info-item { background: #f8f9fa; padding: 15px; border-radius: 8px; border-left: 4px solid #667eea; }
        .info-label { font-size: 12px; color: #7f8c8d; font-weight: 600; text-transform: uppercase; margin-bottom: 5px; }
        .info-value { font-size: 16px; color: #2c3e50; font-weight: 600; }
        .badge { display: inline-block; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge.pending { background: #fff3cd; color: #856404; }
        .badge.approved { background: #d4edda; color: #155724; }
        .badge.rejected { background: #f8d7da; color: #721c24; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        thead { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        .btn { padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; margin-top: 20px; }
        .btn-back { background: #6c757d; color: white; }
        .btn-back:hover { background: #5a6268; }
        .btn-approve { background: #27ae60; color: white; margin-left: 10px; }
        .btn-approve:hover { background: #229954; }
        .btn-reject { background: #e74c3c; color: white; margin-left: 10px; }
        .btn-reject:hover { background: #c0392b; }
        .rejection-box { background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 8px; margin-top: 20px; }
        .rejection-box h3 { color: #721c24; margin-bottom: 10px; }
        .action-buttons { margin-top: 20px; display: flex; gap: 10px; }
        /* Modal Styles */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: white; margin: 5% auto; padding: 0; border-radius: 12px; width: 90%; max-width: 600px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
        .modal-header { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); color: white; padding: 20px; border-radius: 12px 12px 0 0; }
        .modal-header h2 { margin: 0; font-size: 20px; }
        .modal-body { padding: 20px; }
        .modal-footer { padding: 20px; border-top: 1px solid #ecf0f1; display: flex; justify-content: flex-end; gap: 10px; }
        .checkbox-group { display: flex; flex-direction: column; gap: 10px; }
        .checkbox-group label { display: flex; align-items: center; cursor: pointer; padding: 10px; border: 1px solid #ddd; border-radius: 6px; }
        .checkbox-group label:hover { background: #f8f9fa; }
        .checkbox-group input[type="checkbox"] { margin-right: 10px; width: 18px; height: 18px; cursor: pointer; }
        .other-reason { display: none; width: 100%; margin-top: 10px; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-family: inherit; }
        .other-reason.show { display: block; }
        .btn-modal-cancel { padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .btn-modal-cancel:hover { background: #5a6268; }
        .btn-modal-submit { padding: 10px 20px; background: #e74c3c; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .btn-modal-submit:hover { background: #c0392b; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-weight"></i> Daily GSM Check Details</h1>
            <p>Entry ID: <strong><?php echo htmlspecialchars($entry_id); ?></strong></p>
        </div>

        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Date & Time</div>
                <div class="info-value"><?php echo date('d M Y, h:i A', strtotime($firstRow['date_time'])); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Shift</div>
                <div class="info-value"><?php echo htmlspecialchars($firstRow['shift']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Line Number</div>
                <div class="info-value"><?php echo htmlspecialchars($firstRow['line_number']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Inspector</div>
                <div class="info-value"><?php echo htmlspecialchars($firstRow['inspector']); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Status</div>
                <div class="info-value">
                    <span class="badge <?php echo htmlspecialchars($firstRow['status']); ?>">
                        <?php echo strtoupper(htmlspecialchars($firstRow['status'])); ?>
                    </span>
                </div>
            </div>
            <?php if ($firstRow['approved_by']): ?>
            <div class="info-item">
                <div class="info-label"><?php echo $firstRow['status'] === 'approved' ? 'Approved By' : 'Rejected By'; ?></div>
                <div class="info-value"><?php echo htmlspecialchars($firstRow['approved_by']); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($firstRow['status'] === 'rejected' && $firstRow['rejection_reason']): ?>
        <div class="rejection-box">
            <h3><i class="fas fa-exclamation-triangle"></i> Rejection Reason</h3>
            <p><?php echo htmlspecialchars($firstRow['rejection_reason']); ?></p>
        </div>
        <?php endif; ?>

        <h2 style="margin-top: 30px; margin-bottom: 15px; color: #667eea;">GSM Measurements</h2>
        <table>
            <thead>
                <tr>
                    <th>Roll No</th>
                    <th>Size Type</th>
                    <th>Size Value</th>
                    <th>Weight Left</th>
                    <th>Weight L-Mid</th>
                    <th>Weight R-Mid</th>
                    <th>Weight Right</th>
                    <th>GSM Left</th>
                    <th>GSM L-Mid</th>
                    <th>GSM R-Mid</th>
                    <th>GSM Right</th>
                    <th>Avg GSM</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checks as $check): ?>
                <tr>
                    <td><?php echo htmlspecialchars($check['roll_no']); ?></td>
                    <td><?php echo htmlspecialchars($check['size_type']); ?></td>
                    <td><?php echo htmlspecialchars($check['size_value'] ?? '-'); ?></td>
                    <td><?php echo number_format($check['weight_left'], 2); ?></td>
                    <td><?php echo number_format($check['weight_left_middle'], 2); ?></td>
                    <td><?php echo number_format($check['weight_right_middle'], 2); ?></td>
                    <td><?php echo number_format($check['weight_right'], 2); ?></td>
                    <td><?php echo number_format($check['gsm_left'], 2); ?></td>
                    <td><?php echo number_format($check['gsm_left_middle'], 2); ?></td>
                    <td><?php echo number_format($check['gsm_right_middle'], 2); ?></td>
                    <td><?php echo number_format($check['gsm_right'], 2); ?></td>
                    <td><strong><?php echo number_format($check['avg_gsm'], 2); ?></strong></td>
                    <td><?php echo htmlspecialchars($check['remarks'] ?? '-'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="action-buttons">
            <button onclick="closeWindow()" class="btn btn-back">
                <i class="fas fa-times"></i> Close Window
            </button>
            <?php if ($isApprovalDashboard && $firstRow['status'] === 'pending'): ?>
            <button onclick="approveGSM('<?php echo htmlspecialchars($entry_id); ?>')" class="btn btn-approve">
                <i class="fas fa-check"></i> Approve
            </button>
            <button onclick="rejectGSM('<?php echo htmlspecialchars($entry_id); ?>')" class="btn btn-reject">
                <i class="fas fa-times"></i> Reject
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Rejection Modal -->
    <?php if ($isApprovalDashboard && $firstRow['status'] === 'pending'): ?>
    <div id="gsmRejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-times-circle"></i> Reject GSM Check</h2>
                <p style="margin-top: 10px; color: rgba(255,255,255,0.9);">Entry ID: <strong id="gsm_reject_entry_id"></strong></p>
            </div>
            <div class="modal-body">
                <label style="font-weight: 600; margin-bottom: 10px; display: block;">Select Rejection Reasons:</label>
                <div class="checkbox-group">
                    <label>
                        <input type="checkbox" name="gsm_rejection_reasons[]" value="GSM values out of tolerance range">
                        GSM values out of tolerance range
                    </label>
                    <label>
                        <input type="checkbox" name="gsm_rejection_reasons[]" value="Weight measurements incorrect">
                        Weight measurements incorrect
                    </label>
                    <label>
                        <input type="checkbox" name="gsm_rejection_reasons[]" value="Missing or incomplete data">
                        Missing or incomplete data
                    </label>
                    <label>
                        <input type="checkbox" name="gsm_rejection_reasons[]" value="Average GSM calculation error">
                        Average GSM calculation error
                    </label>
                    <label>
                        <input type="checkbox" name="gsm_rejection_reasons[]" value="Incorrect roll number">
                        Incorrect roll number
                    </label>
                    <label>
                        <input type="checkbox" id="gsm_other_checkbox" name="gsm_rejection_reasons[]" value="Other" onchange="toggleOtherReason('gsm')">
                        Other (please specify)
                    </label>
                    <textarea id="gsm_other_reason" class="other-reason" placeholder="Enter other reason..."></textarea>
                </div>
                <div style="margin-top:15px;">
                    <label style="font-weight:600; display:block; margin-bottom:8px;">Additional Comments (Optional):</label>
                    <textarea name="gsm_remarks" id="gsm_remarks" rows="3" placeholder="Add any additional details..." style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px; font-family:inherit;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" onclick="closeGSMRejectModal()">Cancel</button>
                <button type="button" class="btn-modal-submit" onclick="submitGSMRejection()">
                    <i class="fas fa-times"></i> Reject Entry
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

<script>
function closeWindow() {
    if (window.opener) {
        window.close();
    } else {
        // Check if opened from approval dashboard
        const urlParams = new URLSearchParams(window.location.search);
        const isApprovalDashboard = urlParams.get('approval_dashboard') === '1';
        if (isApprovalDashboard) {
            window.location.href = 'qc_approval_dashboard.php';
        } else {
            window.history.back();
        }
    }
}

<?php if ($isApprovalDashboard && $firstRow['status'] === 'pending'): ?>
function approveGSM(entryId) {
    if (confirm('Approve GSM Check: ' + entryId + '?')) {
        // Create form in current window and submit to handler
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '../handlers/approve_qc.php';
        form.style.display = 'none';
        
        const typeInput = document.createElement('input');
        typeInput.type = 'hidden';
        typeInput.name = 'type';
        typeInput.value = 'gsm';
        form.appendChild(typeInput);
        
        const entryInput = document.createElement('input');
        entryInput.type = 'hidden';
        entryInput.name = 'entry_id';
        entryInput.value = entryId;
        form.appendChild(entryInput);
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'approve';
        form.appendChild(actionInput);
        
        // Create JSON payload
        const jsonData = JSON.stringify({
            type: 'gsm',
            entry_id: entryId,
            action: 'approve'
        });
        
        fetch('../handlers/approve_qc.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: jsonData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                alert('GSM Check approved successfully!');
                // Refresh parent window after a short delay
                if (window.opener && !window.opener.closed) {
                    setTimeout(function() {
                        window.opener.location.reload();
                        window.close();
                    }, 500);
                } else {
                    window.location.href = 'qc_approval_dashboard.php';
                }
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred during approval. Please try again.');
        });
    }
}

function rejectGSM(entryId) {
    document.getElementById('gsm_reject_entry_id').textContent = entryId;
    document.getElementById('gsmRejectModal').style.display = 'block';
    
    // Clear previous selections
    document.querySelectorAll('#gsmRejectModal input[type="checkbox"]').forEach(cb => cb.checked = false);
    document.getElementById('gsm_other_reason').value = '';
    document.getElementById('gsm_other_reason').style.display = 'none';
    document.getElementById('gsm_remarks').value = '';
}

function closeGSMRejectModal() {
    document.getElementById('gsmRejectModal').style.display = 'none';
}

function toggleOtherReason(type) {
    const checkbox = document.getElementById('gsm_other_checkbox');
    const textarea = document.getElementById('gsm_other_reason');
    if (checkbox.checked) {
        textarea.classList.add('show');
    } else {
        textarea.classList.remove('show');
        textarea.value = '';
    }
}

function submitGSMRejection() {
    const checkboxes = document.querySelectorAll('#gsmRejectModal input[name="gsm_rejection_reasons[]"]:checked');
    const reasons = [];
    
    checkboxes.forEach(cb => {
        if (cb.value === 'Other') {
            const otherReason = document.getElementById('gsm_other_reason').value.trim();
            if (otherReason) {
                reasons.push('Other: ' + otherReason);
            }
        } else {
            reasons.push(cb.value);
        }
    });
    
    if (reasons.length === 0) {
        alert('Please select at least one rejection reason');
        return;
    }
    
    const reason = reasons.join('; ');
    const remarks = document.getElementById('gsm_remarks').value.trim();
    const finalReason = remarks ? reason + '\n\nAdditional Comments: ' + remarks : reason;
    
    const entryId = document.getElementById('gsm_reject_entry_id').textContent;
    
    fetch('../handlers/approve_qc.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ 
            type: 'gsm', 
            entry_id: entryId, 
            action: 'reject', 
            reason: finalReason 
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert('GSM Check rejected.');
            // Refresh parent window after a short delay
            if (window.opener && !window.opener.closed) {
                setTimeout(function() {
                    window.opener.location.reload();
                    window.close();
                }, 500);
            } else {
                window.location.href = 'qc_approval_dashboard.php';
            }
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred during rejection. Please try again.');
    });
}

// Close modal on outside click
document.getElementById('gsmRejectModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeGSMRejectModal();
    }
});
<?php endif; ?>

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeWindow();
        <?php if ($isApprovalDashboard && $firstRow['status'] === 'pending'): ?>
        closeGSMRejectModal();
        <?php endif; ?>
    }
});
</script>
</body>
</html>


