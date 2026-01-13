<?php
/**
 * Script to add approval columns to roll_qc_reports table and update existing records
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
$actions = [];

// Check if columns exist
$colCheck = $conn->query("SHOW COLUMNS FROM roll_qc_reports LIKE 'approved'");
$hasApproved = ($colCheck && $colCheck->num_rows > 0);

$colCheck = $conn->query("SHOW COLUMNS FROM roll_qc_reports LIKE 'approved_by'");
$hasApprovedBy = ($colCheck && $colCheck->num_rows > 0);

$colCheck = $conn->query("SHOW COLUMNS FROM roll_qc_reports LIKE 'approved_at'");
$hasApprovedAt = ($colCheck && $colCheck->num_rows > 0);

// Add columns if they don't exist
if (!$hasApproved) {
    try {
        $conn->query("ALTER TABLE roll_qc_reports ADD COLUMN approved TINYINT(1) DEFAULT 0");
        $actions[] = "✓ Added 'approved' column";
    } catch (Exception $e) {
        $error = "Error adding 'approved' column: " . $e->getMessage();
    }
} else {
    $actions[] = "✓ 'approved' column already exists";
}

if (!$hasApprovedBy) {
    try {
        $conn->query("ALTER TABLE roll_qc_reports ADD COLUMN approved_by VARCHAR(255) NULL");
        $actions[] = "✓ Added 'approved_by' column";
    } catch (Exception $e) {
        $error = "Error adding 'approved_by' column: " . $e->getMessage();
    }
} else {
    $actions[] = "✓ 'approved_by' column already exists";
}

if (!$hasApprovedAt) {
    try {
        $conn->query("ALTER TABLE roll_qc_reports ADD COLUMN approved_at TIMESTAMP NULL");
        $actions[] = "✓ Added 'approved_at' column";
    } catch (Exception $e) {
        $error = "Error adding 'approved_at' column: " . $e->getMessage();
    }
} else {
    $actions[] = "✓ 'approved_at' column already exists";
}

// Handle manual update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ref'])) {
    $reference_number = trim($_POST['reference_number'] ?? '');
    $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
    
    if (empty($reference_number)) {
        $error = "Reference number is required";
    } else {
        // First ensure columns exist
        $conn->query("ALTER TABLE roll_qc_reports ADD COLUMN IF NOT EXISTS approved TINYINT(1) DEFAULT 0");
        $conn->query("ALTER TABLE roll_qc_reports ADD COLUMN IF NOT EXISTS approved_by VARCHAR(255) NULL");
        $conn->query("ALTER TABLE roll_qc_reports ADD COLUMN IF NOT EXISTS approved_at TIMESTAMP NULL");
        
        $stmt = $conn->prepare("UPDATE roll_qc_reports 
                                 SET approved = 1, approved_by = ?, approved_at = NOW(), overall_status = 'approved'
                                 WHERE reference_number = ?");
        $stmt->bind_param("ss", $approved_by, $reference_number);
        
        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected > 0) {
                $message = "✓ Roll QC Report for reference '$reference_number' has been marked as approved! ($affected row(s) updated)";
            } else {
                $error = "No Roll QC Report found for reference '$reference_number'";
            }
        } else {
            $error = "Failed to update: " . $stmt->error;
            $stmt->close();
        }
    }
}

// Get all roll_qc_reports to show current status
$reports = [];
$hasApprovedCol = false;
$colCheck = $conn->query("SHOW COLUMNS FROM roll_qc_reports LIKE 'approved'");
if ($colCheck && $colCheck->num_rows > 0) {
    $hasApprovedCol = true;
    $result = $conn->query("SELECT id, reference_number, roll_no, overall_status, 
                                   COALESCE(approved, 0) as approved, 
                                   approved_by, approved_at, created_at
                            FROM roll_qc_reports 
                            ORDER BY created_at DESC 
                            LIMIT 50");
} else {
    $result = $conn->query("SELECT id, reference_number, roll_no, overall_status, 
                                   NULL as approved, 
                                   NULL as approved_by, NULL as approved_at, created_at
                            FROM roll_qc_reports 
                            ORDER BY created_at DESC 
                            LIMIT 50");
}

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
    <title>Fix Roll QC Reports Columns</title>
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
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        .actions-list {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .actions-list ul {
            margin: 0;
            padding-left: 20px;
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
        .status-approved {
            color: #28a745;
            font-weight: 600;
        }
        .status-pending {
            color: #856404;
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
        <h1>Fix Roll QC Reports Columns</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($actions)): ?>
            <div class="alert alert-info">
                <strong>Column Status:</strong>
                <ul>
                    <?php foreach ($actions as $action): ?>
                        <li><?php echo htmlspecialchars($action); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <div style="margin-bottom: 30px;">
            <a href="../index.php" class="btn btn-primary">← Back to Dashboard</a>
        </div>
        
        <div class="form-group">
            <form method="POST" action="">
                <label>Reference Number to Mark as Approved:</label>
                <input type="text" name="reference_number" 
                       value="<?php echo htmlspecialchars($_POST['reference_number'] ?? '4.0L226JAN04-R05-GT0.9.H0.1'); ?>" 
                       placeholder="Enter reference number" required>
                <button type="submit" name="update_ref" class="btn btn-primary" style="margin-top: 10px;">
                    Mark as Approved
                </button>
            </form>
        </div>
        
        <h2>Roll QC Reports Status</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Reference Number</th>
                    <th>Roll No</th>
                    <th>Overall Status</th>
                    <th>Approved</th>
                    <th>Approved By</th>
                    <th>Created At</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reports)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 20px;">No Roll QC Reports found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reports as $report): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($report['id']); ?></td>
                            <td><strong><?php echo htmlspecialchars($report['reference_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($report['roll_no']); ?></td>
                            <td class="status-<?php echo strtolower($report['overall_status'] ?? 'pending'); ?>">
                                <?php echo htmlspecialchars($report['overall_status'] ?? 'Pending'); ?>
                            </td>
                            <td>
                                <?php 
                                if (!$hasApprovedCol) {
                                    echo '<span style="color: #999;">Column not added yet</span>';
                                } else {
                                    $approved = $report['approved'] ?? 0;
                                    echo $approved ? '<span class="status-approved">✓ Approved</span>' : '<span class="status-pending">Pending</span>';
                                }
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($report['approved_by'] ?? 'N/A'); ?></td>
                            <td><?php echo date('d M Y, h:i A', strtotime($report['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>

