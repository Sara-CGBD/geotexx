<?php
/**
 * Script to update existing Roll QC Reports that have been approved by AGM
 * This will add the approval columns and mark approved reports
 */
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$is_admin = in_array($user_role, ['admin', 'agm', 'agm ops', 'agm operations']);

if (!$is_admin) {
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$message = '';
$error = '';


if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Add approval columns if they don't exist
try {
    // Check if columns exist before adding
    $colCheck = $conn->query("SHOW COLUMNS FROM roll_qc_reports LIKE 'approved'");
    if (!$colCheck || $colCheck->num_rows == 0) {
        $conn->query("ALTER TABLE roll_qc_reports ADD COLUMN approved TINYINT(1) DEFAULT 0");
    }
    
    $colCheck = $conn->query("SHOW COLUMNS FROM roll_qc_reports LIKE 'approved_by'");
    if (!$colCheck || $colCheck->num_rows == 0) {
        $conn->query("ALTER TABLE roll_qc_reports ADD COLUMN approved_by VARCHAR(255) NULL");
    }
    
    $colCheck = $conn->query("SHOW COLUMNS FROM roll_qc_reports LIKE 'approved_at'");
    if (!$colCheck || $colCheck->num_rows == 0) {
        $conn->query("ALTER TABLE roll_qc_reports ADD COLUMN approved_at TIMESTAMP NULL");
    }
} catch (Exception $e) {
    // Silently ignore if columns already exist
    error_log("Column check/add error (may be expected): " . $e->getMessage());
}

// Handle manual approval update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_approval'])) {
        $reference_number = trim($_POST['reference_number'] ?? '');
        $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
        
        if (empty($reference_number)) {
            $error = "Reference number is required";
        } else {
            $stmt = $conn->prepare("UPDATE roll_qc_reports 
                                     SET approved = 1, approved_by = ?, approved_at = NOW(), overall_status = 'approved'
                                     WHERE reference_number = ?");
            $stmt->bind_param("ss", $approved_by, $reference_number);
            
            if ($stmt->execute()) {
                $affected = $stmt->affected_rows;
                $stmt->close();
                if ($affected > 0) {
                    $message = "Roll QC Report for reference $reference_number has been marked as approved!";
                    // Refresh the page to show updated data
                    header("Location: update_approved_roll_qc.php?msg=" . urlencode($message));
                    exit();
                } else {
                    $error = "No Roll QC Report found for reference $reference_number";
                }
            } else {
                $error = "Failed to update: " . $stmt->error;
                $stmt->close();
            }
        }
    } elseif (isset($_POST['approve_id'])) {
        // Approve by ID (from table row button)
        $id = (int)$_POST['approve_id'];
        $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
        
        $stmt = $conn->prepare("UPDATE roll_qc_reports 
                                 SET approved = 1, approved_by = ?, approved_at = NOW(), overall_status = 'approved'
                                 WHERE id = ?");
        $stmt->bind_param("si", $approved_by, $id);
        
        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected > 0) {
                $message = "Roll QC Report ID $id has been marked as approved!";
                // Refresh the page to show updated data
                header("Location: update_approved_roll_qc.php?msg=" . urlencode($message));
                exit();
            } else {
                $error = "No Roll QC Report found with ID $id";
            }
        } else {
            $error = "Failed to update: " . $stmt->error;
            $stmt->close();
        }
    }
}

// Get all roll_qc_reports
$reports = [];
$result = $conn->query("SELECT * FROM roll_qc_reports ORDER BY created_at DESC LIMIT 50");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Approved Roll QC Reports</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #f4f6f9;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            margin-bottom: 20px;
        }
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 6px;
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
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #f8f9fa;
            font-weight: 600;
        }
        tr:hover {
            background: #f8f9fa;
        }
        .status-pending {
            color: #856404;
            font-weight: 600;
        }
        .status-approved {
            color: #155724;
            font-weight: 600;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }
        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Update Approved Roll QC Reports</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div style="margin-bottom: 30px;">
            <a href="../index.php" class="btn btn-primary">← Back to Dashboard</a>
        </div>
        
        <div class="form-group">
            <form method="POST" action="">
                <label>Reference Number to Mark as Approved:</label>
                <input type="text" name="reference_number" placeholder="Enter reference number (e.g., 4.0L226JAN04-R05-GT0.9.H0.1)" required>
                <button type="submit" name="update_approval" class="btn btn-primary" style="margin-top: 10px;">
                    Mark as Approved
                </button>
            </form>
        </div>
        
        <h2>Roll QC Reports</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Reference Number</th>
                    <th>Roll No</th>
                    <th>Line Number</th>
                    <th>Product Amount</th>
                    <th>GSM Status</th>
                    <th>Length Status</th>
                    <th>Overall Status</th>
                    <th>Approved</th>
                    <th>Approved By</th>
                    <th>Created At</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reports)): ?>
                    <tr>
                        <td colspan="12" style="text-align: center; padding: 20px;">No Roll QC Reports found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reports as $report): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($report['id']); ?></td>
                            <td><strong><?php echo htmlspecialchars($report['reference_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($report['roll_no']); ?></td>
                            <td><?php echo htmlspecialchars($report['line_number'] ?? $report['line_no'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format($report['product_amount'], 2); ?> kg</td>
                            <td class="status-<?php echo strtolower($report['gsm_check_status'] ?? 'pending'); ?>">
                                <?php echo htmlspecialchars($report['gsm_check_status'] ?? 'Pending'); ?>
                            </td>
                            <td class="status-<?php echo strtolower($report['length_calibration_status'] ?? 'pending'); ?>">
                                <?php echo htmlspecialchars($report['length_calibration_status'] ?? 'Pending'); ?>
                            </td>
                            <td class="status-<?php echo strtolower($report['overall_status'] ?? 'pending'); ?>">
                                <?php echo htmlspecialchars($report['overall_status'] ?? 'Pending'); ?>
                            </td>
                            <td>
                                <?php 
                                $approved = $report['approved'] ?? 0;
                                echo $approved ? '<span class="status-approved">✓ Approved</span>' : '<span class="status-pending">Pending</span>';
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($report['approved_by'] ?? 'N/A'); ?></td>
                            <td><?php echo date('d M Y, h:i A', strtotime($report['created_at'])); ?></td>
                            <td>
                                <?php if (!($report['approved'] ?? 0)): ?>
                                    <form method="POST" action="" style="display:inline;">
                                        <input type="hidden" name="approve_id" value="<?php echo $report['id']; ?>">
                                        <button type="submit" class="btn btn-primary" style="padding: 6px 12px; font-size: 12px;">
                                            Approve
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="color: #28a745;">✓ Approved</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>

