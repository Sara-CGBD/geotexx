<?php
session_start();
require_once '../config/security_config.php';

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit;
}

// Check authorization - only admin
$allowedRoles = ['admin'];
$userRole = strtolower(trim($_SESSION['role'] ?? ''));

if (!in_array($userRole, $allowedRoles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>Only administrators can edit QC test data.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$message = '';
$error = '';

// Handle form submission to update data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $report_id = (int)$_POST['report_id'];
    $sample_details = trim($_POST['sample_details']);
    $customer_reference = trim($_POST['customer_reference']);
    
    // Fetch current test_data
    $stmt = $conn->prepare("SELECT test_data FROM qc_test_orders WHERE id = ?");
    $stmt->bind_param('i', $report_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    if ($row) {
        $test_data = json_decode($row['test_data'], true);
        
        // Update the fields
        $test_data['sample_details'] = $sample_details;
        $test_data['customer_reference'] = $customer_reference;
        
        // Save back to database
        $updated_test_data = json_encode($test_data);
        $stmt = $conn->prepare("UPDATE qc_test_orders SET test_data = ? WHERE id = ?");
        $stmt->bind_param('si', $updated_test_data, $report_id);
        
        if ($stmt->execute()) {
            $message = "✅ Successfully updated Report #$report_id";
        } else {
            $error = "❌ Error updating report: " . $stmt->error;
        }
        $stmt->close();
    } else {
        $error = "❌ Report not found";
    }
}

// Fetch all QC test orders with sample_details and customer_reference
$qc_orders = [];
$query = "SELECT id, report_number, test_name, test_data, created_at, status 
          FROM qc_test_orders 
          ORDER BY created_at DESC 
          LIMIT 100";
$result = $conn->query($query);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $test_data = json_decode($row['test_data'], true);
        $row['sample_details'] = $test_data['sample_details'] ?? '';
        $row['customer_reference'] = $test_data['customer_reference'] ?? '';
        $qc_orders[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit QC Test Data</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 5px 10px 5px 5px; color: #2c3e50; }
        .container { max-width: 1400px; margin: 20px auto; background: white; border-radius: 8px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; font-size: 28px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 25px; font-size: 14px; }
        
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; font-size: 13px; }
        tr:hover { background: #f8f9fa; }
        
        .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-approved { background: #d4edda; color: #155724; }
        .badge-rejected { background: #f8d7da; color: #721c24; }
        .badge-pending_checker { background: #cfe2ff; color: #084298; }
        .badge-pending_approval { background: #e7d4ff; color: #6f42c1; }
        
        .btn { padding: 8px 16px; border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; transition: all 0.3s; }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #229954; }
        
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); }
        .modal-content { background-color: white; margin: 5% auto; padding: 30px; border-radius: 10px; width: 90%; max-width: 600px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
        .modal-header { font-size: 20px; font-weight: bold; margin-bottom: 20px; color: #34495e; }
        .modal-body { margin-bottom: 20px; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #34495e; }
        .form-group input, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-family: inherit; font-size: 14px; }
        .form-group textarea { resize: vertical; min-height: 80px; }
        
        .truncate { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        
        .search-box { margin-bottom: 20px; }
        .search-box input { width: 100%; max-width: 400px; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
    </style>
</head>
<body>
<div class="container">
    <a href="../index.php" class="btn btn-secondary" style="margin-bottom: 20px;">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
    
    <h1><i class="fas fa-edit"></i> Edit QC Test Data</h1>
    <p class="subtitle">Update Sample Details and Customer Reference for QC Test Orders</p>
    
    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <!-- Search Box -->
    <div class="search-box">
        <input type="text" id="searchInput" placeholder="🔍 Search by Report Number, Test Name, Sample Details, or Customer Reference..." onkeyup="filterTable()">
    </div>
    
    <!-- QC Test Orders Table -->
    <div style="overflow-x: auto;">
        <table id="qcTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Report Number</th>
                    <th>Test Name</th>
                    <th>Sample Details</th>
                    <th>Customer Reference</th>
                    <th>Status</th>
                    <th>Created At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($qc_orders)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: #7f8c8d;">
                            <i class="fas fa-info-circle"></i> No QC test orders found
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($qc_orders as $order): ?>
                        <tr>
                            <td><?php echo $order['id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($order['report_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($order['test_name']); ?></td>
                            <td>
                                <div class="truncate" title="<?php echo htmlspecialchars($order['sample_details']); ?>">
                                    <?php echo htmlspecialchars($order['sample_details'] ?: 'N/A'); ?>
                                </div>
                            </td>
                            <td>
                                <div class="truncate" title="<?php echo htmlspecialchars($order['customer_reference']); ?>">
                                    <?php echo htmlspecialchars($order['customer_reference'] ?: 'N/A'); ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo $order['status']; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $order['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo date('d M Y, h:i A', strtotime($order['created_at'])); ?></td>
                            <td>
                                <button class="btn btn-primary" onclick="openEditModal(<?php echo $order['id']; ?>, '<?php echo addslashes($order['report_number']); ?>', '<?php echo addslashes($order['sample_details']); ?>', '<?php echo addslashes($order['customer_reference']); ?>')">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <i class="fas fa-edit"></i> Edit QC Test Data
            <div style="font-size: 14px; color: #7f8c8d; margin-top: 5px;">Report: <span id="modalReportNumber"></span></div>
        </div>
        <form method="POST" id="editForm">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="report_id" id="modalReportId">
            
            <div class="modal-body">
                <div class="form-group">
                    <label>Sample Details:</label>
                    <textarea name="sample_details" id="modalSampleDetails" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Customer Reference:</label>
                    <input type="text" name="customer_reference" id="modalCustomerReference">
                </div>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(id, reportNumber, sampleDetails, customerReference) {
    document.getElementById('modalReportId').value = id;
    document.getElementById('modalReportNumber').textContent = reportNumber;
    document.getElementById('modalSampleDetails').value = sampleDetails;
    document.getElementById('modalCustomerReference').value = customerReference;
    document.getElementById('editModal').style.display = 'block';
}

function closeEditModal() {
    document.getElementById('editModal').style.display = 'none';
    document.getElementById('editForm').reset();
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('editModal');
    if (event.target === modal) {
        closeEditModal();
    }
}

// Search/Filter function
function filterTable() {
    const input = document.getElementById('searchInput');
    const filter = input.value.toUpperCase();
    const table = document.getElementById('qcTable');
    const tr = table.getElementsByTagName('tr');
    
    for (let i = 1; i < tr.length; i++) { // Start from 1 to skip header
        const tdReportNum = tr[i].getElementsByTagName('td')[1];
        const tdTestName = tr[i].getElementsByTagName('td')[2];
        const tdSampleDetails = tr[i].getElementsByTagName('td')[3];
        const tdCustomerRef = tr[i].getElementsByTagName('td')[4];
        
        if (tdReportNum || tdTestName || tdSampleDetails || tdCustomerRef) {
            const txtReportNum = tdReportNum.textContent || tdReportNum.innerText;
            const txtTestName = tdTestName.textContent || tdTestName.innerText;
            const txtSampleDetails = tdSampleDetails.textContent || tdSampleDetails.innerText;
            const txtCustomerRef = tdCustomerRef.textContent || tdCustomerRef.innerText;
            
            if (txtReportNum.toUpperCase().indexOf(filter) > -1 ||
                txtTestName.toUpperCase().indexOf(filter) > -1 ||
                txtSampleDetails.toUpperCase().indexOf(filter) > -1 ||
                txtCustomerRef.toUpperCase().indexOf(filter) > -1) {
                tr[i].style.display = '';
            } else {
                tr[i].style.display = 'none';
            }
        }
    }
}
</script>

</body>
</html>


