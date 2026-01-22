<?php
session_start();
require_once '../config/security_config.php';
require_once '../config/AccessControl.php';

// Check access - allow admin, production user, and store user
$userRole = $_SESSION['role'] ?? '';
$hasAccess = AccessControl::hasModuleAccess($userRole, AccessControl::MODULE_MATERIAL_REQUEST, AccessControl::PERMISSION_VIEW) ||
             AccessControl::hasModuleAccess($userRole, AccessControl::MODULE_MATERIAL_REQUEST, AccessControl::PERMISSION_ENTRY) ||
             AccessControl::hasModuleAccess($userRole, AccessControl::MODULE_MATERIAL_REQUEST, AccessControl::PERMISSION_FULL);

if (!$hasAccess && $userRole !== 'store_user') {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to view Material Requests.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS material_request_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_number VARCHAR(30) UNIQUE NOT NULL,
    manufacturer_name VARCHAR(100) NOT NULL,
    material_type VARCHAR(100) NOT NULL,
    required_quantity DECIMAL(10,2) NOT NULL,
    requested_by VARCHAR(100) NOT NULL,
    request_time DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Fetch all material requests with their issue status
$query = "SELECT 
            mre.*,
            CASE 
                WHEN EXISTS (
                    SELECT 1 FROM store_issue_entries sie 
                    WHERE sie.material_request_number = mre.request_number
                ) THEN 'issued'
                ELSE 'pending'
            END as status
          FROM material_request_entries mre
          ORDER BY mre.request_time DESC, mre.created_at DESC";
$result = $conn->query($query);
$requests = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
}

// Calculate statistics
$totalRequests = count($requests);
$totalQuantity = array_sum(array_column($requests, 'required_quantity'));
$pendingRequests = count(array_filter($requests, function($r) { return $r['status'] === 'pending'; }));
$issuedRequests = count(array_filter($requests, function($r) { return $r['status'] === 'issued'; }));

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Material Request List</title>
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
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        @media (min-width: 900px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #667eea;
        }
        .stat-card.total { border-left-color: #3498db; }
        .stat-card.quantity { border-left-color: #e74c3c; }
        .stat-card.pending { border-left-color: #f39c12; }
        .stat-card.issued { border-left-color: #27ae60; }
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
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-badge.pending {
            background: #fff3cd;
            color: #856404;
        }
        .status-badge.issued {
            background: #d4edda;
            color: #155724;
        }
        .action-btn.disabled {
            background: #95a5a6;
            cursor: not-allowed;
            opacity: 0.6;
        }
        .action-btn.disabled:hover {
            background: #95a5a6;
        }
        .table-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
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
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #ecf0f1;
        }
        tbody tr:hover {
            background: #f8f9fa;
        }
        tbody tr:nth-child(even) {
            background: #fafbfc;
        }
        .action-btn {
            background: #27ae60;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            font-size: 13px;
        }
        .action-btn:hover {
            background: #229954;
        }
        .back-btn {
            background: #95a5a6;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 20px;
        }
        .back-btn:hover {
            background: #7f8c8d;
        }
        .no-data {
            text-align: center;
            padding: 60px 20px;
            color: #95a5a6;
            font-size: 1.1em;
        }
        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><i class="fas fa-file-signature"></i> Material Request List</h1>
        <p>View all material requests from production users</p>
    </div>

    <a href="../index.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>

    <?php if (isset($_GET['success'])): ?>
        <div class="message success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($_GET['success']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['error'])): ?>
        <div class="message error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($_GET['error']); ?>
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card total">
            <div class="stat-value"><?php echo number_format($totalRequests); ?></div>
            <div class="stat-label">Total Requests</div>
        </div>
        <div class="stat-card quantity">
            <div class="stat-value"><?php echo number_format($totalQuantity, 2); ?> kg</div>
            <div class="stat-label">Total Quantity Requested</div>
        </div>
        <div class="stat-card pending">
            <div class="stat-value"><?php echo number_format($pendingRequests); ?></div>
            <div class="stat-label">Pending Requests</div>
        </div>
        <div class="stat-card issued">
            <div class="stat-value"><?php echo number_format($issuedRequests); ?></div>
            <div class="stat-label">Issued Requests</div>
        </div>
    </div>

    <div class="table-container">
        <?php if (empty($requests)): ?>
            <div class="no-data">
                <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px; opacity: 0.3;"></i>
                <p>No material requests found.</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Request Number</th>
                        <th>Manufacturer</th>
                        <th>Material Type</th>
                        <th>Required Quantity (kg)</th>
                        <th>Requested By</th>
                        <th>Request Time</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $request): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($request['request_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($request['manufacturer_name']); ?></td>
                            <td><?php echo htmlspecialchars($request['material_type']); ?></td>
                            <td><strong><?php echo number_format($request['required_quantity'], 2); ?></strong></td>
                            <td><?php echo htmlspecialchars($request['requested_by']); ?></td>
                            <td><?php echo date('Y-m-d H:i:s', strtotime($request['request_time'])); ?></td>
                            <td>
                                <span class="status-badge <?php echo htmlspecialchars($request['status']); ?>">
                                    <?php echo ucfirst($request['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($request['status'] === 'pending'): ?>
                                    <a href="../forms/material_issue_entry.php?manufacturer=<?php echo urlencode($request['manufacturer_name']); ?>&material_type=<?php echo urlencode($request['material_type']); ?>&amount=<?php echo urlencode($request['required_quantity']); ?>&request_number=<?php echo urlencode($request['request_number']); ?>" 
                                       class="action-btn">
                                        <i class="fas fa-box-open"></i> Create Issue
                                    </a>
                                <?php else: ?>
                                    <span class="action-btn disabled">
                                        <i class="fas fa-check-circle"></i> Issued
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
