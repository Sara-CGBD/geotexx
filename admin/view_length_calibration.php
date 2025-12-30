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

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Fetch length calibration details
$stmt = $conn->prepare("SELECT * FROM length_calibrations WHERE entry_id = ? ORDER BY id");
$stmt->bind_param("s", $entry_id);
$stmt->execute();
$result = $stmt->get_result();

$calibrations = [];
$firstRow = null;
while ($row = $result->fetch_assoc()) {
    if (!$firstRow) {
        $firstRow = $row;
    }
    $calibrations[] = $row;
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
    <title>View Length Calibration - <?php echo htmlspecialchars($entry_id); ?></title>
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
        .rejection-box { background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 8px; margin-top: 20px; }
        .rejection-box h3 { color: #721c24; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-ruler"></i> Length Calibration Details</h1>
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

        <h2 style="margin-top: 30px; margin-bottom: 15px; color: #667eea;">Calibration Measurements</h2>
        <table>
            <thead>
                <tr>
                    <th>Roll No</th>
                    <th>Reference Length</th>
                    <th>Set in Machine</th>
                    <th>Actual Length</th>
                    <th>Difference</th>
                    <th>Calibration Length</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($calibrations as $cal): ?>
                <tr>
                    <td><?php echo htmlspecialchars($cal['roll_no']); ?></td>
                    <td><?php echo number_format($cal['reference_length'], 2); ?></td>
                    <td><?php echo number_format($cal['set_in_machine'], 2); ?></td>
                    <td><?php echo number_format($cal['actual_length'], 2); ?></td>
                    <td><?php echo number_format($cal['difference'], 2); ?></td>
                    <td><strong><?php echo number_format($cal['calibration_length'], 2); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <button onclick="closeWindow()" class="btn btn-back">
            <i class="fas fa-times"></i> Close
        </button>
    </div>

<script>
function closeWindow() {
    if (window.opener) {
        window.close();
    } else {
        // Redirect to GSM and Length Calibration Approval Dashboard
        window.location.href = 'qc_approval_dashboard.php';
    }
}
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeWindow();
    }
});
</script>
</body>
</html>


