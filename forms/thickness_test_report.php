<?php
session_start();
require_once 'security_config.php';

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

// Role-based access control for QC module
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_QC, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access the QC module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');

class ThicknessTestReportHandler {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
        $this->createMainTable();
        $this->createCounterTable();
    }
    
    private function createMainTable() {
        $sql = "CREATE TABLE IF NOT EXISTS thickness_test_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_number VARCHAR(50) UNIQUE NOT NULL,
            sample_description VARCHAR(255),
            sample_received_from VARCHAR(255),
            test_date DATE,
            tested_by VARCHAR(100),
            lab_test_number VARCHAR(20),
            testing_method VARCHAR(255),
            test_standard VARCHAR(100),
            specimen_1 DECIMAL(10,2),
            specimen_2 DECIMAL(10,2),
            specimen_3 DECIMAL(10,2),
            specimen_4 DECIMAL(10,2),
            specimen_5 DECIMAL(10,2),
            specimen_6 DECIMAL(10,2),
            specimen_7 DECIMAL(10,2),
            specimen_8 DECIMAL(10,2),
            specimen_9 DECIMAL(10,2),
            specimen_10 DECIMAL(10,2),
            average DECIMAL(10,2),
            sd DECIMAL(10,3),
            cv_percent DECIMAL(10,2),
            test_results TEXT,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            approved_by VARCHAR(100),
            remarks TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_report_number (report_number),
            INDEX idx_status (status),
            INDEX idx_test_date (test_date)
        )";
        $this->conn->query($sql);
    }
    
    private function createCounterTable() {
        $sql = "CREATE TABLE IF NOT EXISTS thickness_test_counters (
            id INT AUTO_INCREMENT PRIMARY KEY,
            date_key VARCHAR(10) UNIQUE NOT NULL,
            counter INT DEFAULT 0
        )";
        $this->conn->query($sql);
    }
    
    public function generateReportNumber() {
        $today = date('Ymd');
        $dateKey = $today;
        
        $stmt = $this->conn->prepare("INSERT INTO thickness_test_counters (date_key, counter) VALUES (?, 1) ON DUPLICATE KEY UPDATE counter = counter + 1");
        $stmt->bind_param("s", $dateKey);
        $stmt->execute();
        
        $stmt = $this->conn->prepare("SELECT counter FROM thickness_test_counters WHERE date_key = ?");
        $stmt->bind_param("s", $dateKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $counter = str_pad($row['counter'], 5, '0', STR_PAD_LEFT);
        
        return "THICK-{$today}-{$counter}";
    }
    
    public function extractLabTestNumber($reportNumber) {
        preg_match('/-(\d+)$/', $reportNumber, $matches);
        if (isset($matches[1])) {
            $number = (int)$matches[1];
            if ($number > 99) {
                return substr($matches[1], -3);
            } else {
                return substr($matches[1], -2);
            }
        }
        return '01';
    }
    
    public function saveReport($data) {
        $stmt = $this->conn->prepare("INSERT INTO thickness_test_reports (
            report_number, sample_description, sample_received_from, test_date, tested_by,
            lab_test_number, testing_method, test_standard,
            specimen_1, specimen_2, specimen_3, specimen_4, specimen_5,
            specimen_6, specimen_7, specimen_8, specimen_9, specimen_10,
            average, sd, cv_percent, test_results, approved_by, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        
        $stmt->bind_param("ssssssssdddddddddddddsss",
            $data['report_number'],
            $data['sample_description'],
            $data['sample_received_from'],
            $data['test_date'],
            $data['tested_by'],
            $data['lab_test_number'],
            $data['testing_method'],
            $data['test_standard'],
            $data['specimen_1'],
            $data['specimen_2'],
            $data['specimen_3'],
            $data['specimen_4'],
            $data['specimen_5'],
            $data['specimen_6'],
            $data['specimen_7'],
            $data['specimen_8'],
            $data['specimen_9'],
            $data['specimen_10'],
            $data['average'],
            $data['sd'],
            $data['cv_percent'],
            $data['test_results'],
            $data['approved_by']
        );
        
        return $stmt->execute();
    }
    
    public function canApproveReports() {
        $role = strtolower(trim($_SESSION['role'] ?? ''));
        return in_array($role, ['admin', 'agm ops', 'agm operations']);
    }
    
    public function getPendingReports() {
        $result = $this->conn->query("SELECT * FROM thickness_test_reports WHERE status = 'pending' ORDER BY created_at DESC");
        $reports = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $reports[] = $row;
            }
        }
        return $reports;
    }
    
    public function approveOrRejectByReportNumber($reportNumber, $action, $comments = '') {
        if ($action === 'approve') {
            $approvedBy = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown';
            $stmt = $this->conn->prepare("UPDATE thickness_test_reports SET status = 'approved', approved_by = ? WHERE report_number = ?");
            $stmt->bind_param("ss", $approvedBy, $reportNumber);
        } else {
            $stmt = $this->conn->prepare("UPDATE thickness_test_reports SET status = 'rejected', remarks = ? WHERE report_number = ?");
            $stmt->bind_param("ss", $comments, $reportNumber);
        }
        return $stmt->execute();
    }
    
    public function getRejectedReportsForUser($userId) {
        $stmt = $this->conn->prepare("SELECT id, report_number, sample_description, approved_by as rejected_by, remarks, updated_at 
            FROM thickness_test_reports 
            WHERE status = 'rejected' AND created_by = ? 
            ORDER BY updated_at DESC");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $reports = [];
        while ($row = $result->fetch_assoc()) {
            $reports[] = $row;
        }
        return $reports;
    }
}

$conn = SecurityConfig::getConnection();
$handler = new ThicknessTestReportHandler($conn);

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    try {
        $reportData = [
            'report_number' => $_POST['report_number'],
            'sample_description' => trim($_POST['sample_description']),
            'sample_received_from' => trim($_POST['sample_received_from']),
            'test_date' => $_POST['test_date'],
            'tested_by' => $_SESSION['full_name'] ?? $_SESSION['username'],
            'lab_test_number' => $_POST['lab_test_number'],
            'testing_method' => 'Thickness Test (Under 2kPa Pressure)',
            'test_standard' => $_POST['test_standard'],
            'specimen_1' => floatval($_POST['specimen_1'] ?? 0),
            'specimen_2' => floatval($_POST['specimen_2'] ?? 0),
            'specimen_3' => floatval($_POST['specimen_3'] ?? 0),
            'specimen_4' => floatval($_POST['specimen_4'] ?? 0),
            'specimen_5' => floatval($_POST['specimen_5'] ?? 0),
            'specimen_6' => floatval($_POST['specimen_6'] ?? 0),
            'specimen_7' => floatval($_POST['specimen_7'] ?? 0),
            'specimen_8' => floatval($_POST['specimen_8'] ?? 0),
            'specimen_9' => floatval($_POST['specimen_9'] ?? 0),
            'specimen_10' => floatval($_POST['specimen_10'] ?? 0),
            'average' => floatval($_POST['average']),
            'sd' => floatval($_POST['sd']),
            'cv_percent' => floatval($_POST['cv_percent']),
            'test_results' => json_encode($_POST),
            'approved_by' => $_SESSION['full_name'] ?? $_SESSION['username']
        ];
        
        if ($handler->saveReport($reportData)) {
            $message = "Thickness test report submitted successfully! Report No: " . $reportData['report_number'];
        } else {
            throw new Exception("Failed to save report");
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['report_number'])) {
    $action = $_POST['action'];
    $reportNumber = $_POST['report_number'];
    $comments = $_POST['comments'] ?? '';
    
    if ($handler->approveOrRejectByReportNumber($reportNumber, $action, $comments)) {
        $message = ucfirst($action) . "d report: " . $reportNumber;
    } else {
        $error = "Failed to " . $action . " report";
    }
}

$user_role = $_SESSION['role'] ?? '';
$canApprove = $handler->canApproveReports();
$pendingReports = $handler->getPendingReports();
$user_id = $_SESSION['user_id'];

// Check for success message from edit page
if (isset($_SESSION['update_success'])) {
    $message = $_SESSION['update_success'];
    unset($_SESSION['update_success']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Thickness Test Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f4f6f9; margin: 0; padding: 30px 20px; color: #2c3e50; }
        .container { max-width: 900px; margin: auto; background: #fff; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; font-size: 28px; margin-bottom: 30px; color: #34495e; }
        .form-group { margin-bottom: 20px; }
        label { font-weight: 600; display: block; margin-bottom: 8px; color: #34495e; }
        input[type="text"], input[type="date"], input[type="number"], select, textarea {
            padding: 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
            width: 100%;
            box-sizing: border-box;
            font-size: 14px;
        }
        input[readonly] { background-color: #f0f0f0; }
        .specimens-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .specimens-table th, .specimens-table td { padding: 10px; border: 1px solid #ddd; text-align: center; }
        .specimens-table th { background: #3498db; color: white; }
        .specimens-table input { width: 100%; padding: 8px; text-align: center; }
        .stats-row { background: #ecf0f1; font-weight: bold; }
        .submit-btn { background: #27ae60; color: white; padding: 12px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; }
        .submit-btn:hover { background: #229954; }
        .clear-btn { background: #e74c3c; color: white; padding: 12px 30px; border: none; border-radius: 6px; cursor: pointer; font-size: 16px; margin-left: 10px; }
        .actions { text-align: center; margin-top: 30px; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .pending-reports { margin-top: 40px; }
        .pending-reports table { width: 100%; border-collapse: collapse; }
        .pending-reports th, .pending-reports td { padding: 10px; border: 1px solid #ddd; text-align: left; }
        .pending-reports th { background: #34495e; color: white; }
        .approve-btn { background: #27ae60; color: white; padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; }
        .reject-btn { background: #e74c3c; color: white; padding: 6px 12px; border: none; border-radius: 4px; cursor: pointer; margin-left: 5px; }
    </style>
</head>
<body>
<div class="container">
    <h1>📏 Thickness Test Report (Under 2kPa Pressure)</h1>
    
    <?php if ($message): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <form method="POST" action="" id="thicknessForm">
        <!-- Report Number -->
        <div class="form-group">
            <label>Report No.:</label>
            <input type="text" name="report_number" id="report_number" value="<?php echo $handler->generateReportNumber(); ?>" readonly>
        </div>
        
        <!-- Lab Test Number (Auto-filled from report number) -->
        <div class="form-group">
            <label>Lab Test Number:</label>
            <input type="text" name="lab_test_number" id="lab_test_number" readonly>
        </div>
        
        <!-- Sample Description -->
        <div class="form-group">
            <label>Sample Description:</label>
            <input type="text" name="sample_description" required>
        </div>
        
        <!-- Sample Received From -->
        <div class="form-group">
            <label>Sample Received From:</label>
            <input type="text" name="sample_received_from" required>
        </div>
        
        <!-- Test Date -->
        <div class="form-group">
            <label>Test Date:</label>
            <input type="date" name="test_date" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        
        <!-- Tested By -->
        <div class="form-group">
            <label>Tested By:</label>
            <input type="text" name="tested_by" value="<?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?>" readonly>
        </div>
        
        <!-- Test Standard -->
        <div class="form-group">
            <label>Test Standard:</label>
            <select name="test_standard" required>
                <option value="">Select Standard</option>
                <option value="ASTM D5199">ASTM D5199</option>
                <option value="ISO 9863-1">ISO 9863-1</option>
            </select>
        </div>
        
        <!-- Specimen Results Table -->
        <h3>Test Results (mm)</h3>
        <table class="specimens-table">
            <thead>
                <tr>
                    <th>Specimen 1</th>
                    <th>Specimen 2</th>
                    <th>Specimen 3</th>
                    <th>Specimen 4</th>
                    <th>Specimen 5</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><input type="number" name="specimen_1" step="0.01" required oninput="calculateStats()"></td>
                    <td><input type="number" name="specimen_2" step="0.01" required oninput="calculateStats()"></td>
                    <td><input type="number" name="specimen_3" step="0.01" required oninput="calculateStats()"></td>
                    <td><input type="number" name="specimen_4" step="0.01" required oninput="calculateStats()"></td>
                    <td><input type="number" name="specimen_5" step="0.01" required oninput="calculateStats()"></td>
                </tr>
            </tbody>
        </table>
        
        <table class="specimens-table">
            <thead>
                <tr>
                    <th>Specimen 6</th>
                    <th>Specimen 7</th>
                    <th>Specimen 8</th>
                    <th>Specimen 9</th>
                    <th>Specimen 10</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><input type="number" name="specimen_6" step="0.01" required oninput="calculateStats()"></td>
                    <td><input type="number" name="specimen_7" step="0.01" required oninput="calculateStats()"></td>
                    <td><input type="number" name="specimen_8" step="0.01" required oninput="calculateStats()"></td>
                    <td><input type="number" name="specimen_9" step="0.01" required oninput="calculateStats()"></td>
                    <td><input type="number" name="specimen_10" step="0.01" required oninput="calculateStats()"></td>
                </tr>
            </tbody>
        </table>
        
        <!-- Statistics -->
        <h3>Statistics</h3>
        <table class="specimens-table">
            <thead>
                <tr>
                    <th>Average (mm)</th>
                    <th>SD (mm)</th>
                    <th>CV%</th>
                </tr>
            </thead>
            <tbody class="stats-row">
                <tr>
                    <td><input type="number" name="average" id="average" step="0.01" readonly></td>
                    <td><input type="number" name="sd" id="sd" step="0.001" readonly></td>
                    <td><input type="number" name="cv_percent" id="cv_percent" step="0.01" readonly></td>
                </tr>
            </tbody>
        </table>
        
        <div class="actions">
            <button type="submit" name="submit_report" class="submit-btn">Submit Report</button>
            <button type="button" class="clear-btn" onclick="clearForm()">Clear</button>
        </div>
    </form>
    
    <!-- Pending Reports for Approval -->
    <?php if ($canApprove && count($pendingReports) > 0): ?>
        <div class="pending-reports">
            <h2>📋 Pending Thickness Test Reports for Approval</h2>
            <table>
                <thead>
                    <tr>
                        <th>Report No</th>
                        <th>Sample</th>
                        <th>Test Date</th>
                        <th>Tested By</th>
                        <th>Average</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pendingReports as $report): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($report['report_number']); ?></td>
                            <td><?php echo htmlspecialchars($report['sample_description']); ?></td>
                            <td><?php echo htmlspecialchars($report['test_date']); ?></td>
                            <td><?php echo htmlspecialchars($report['tested_by']); ?></td>
                            <td><?php echo htmlspecialchars($report['average']); ?> mm</td>
                            <td>
                                <form method="POST" style="display: inline;" onsubmit="return confirmApproval()">
                                    <input type="hidden" name="report_number" value="<?php echo htmlspecialchars($report['report_number']); ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button type="submit" class="approve-btn">Approve</button>
                                </form>
                                <form method="POST" style="display: inline;" onsubmit="return confirmRejection(this)">
                                    <input type="hidden" name="report_number" value="<?php echo htmlspecialchars($report['report_number']); ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="comments" class="rejection-comments">
                                    <button type="submit" class="reject-btn">Reject</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
// Auto-extract lab test number from report number
document.addEventListener('DOMContentLoaded', function() {
    extractLabTestNumber();
});

function extractLabTestNumber() {
    const reportNumber = document.getElementById('report_number').value;
    const match = reportNumber.match(/-(\d+)$/);
    if (match) {
        const number = parseInt(match[1]);
        let labTestNum;
        if (number > 99) {
            labTestNum = match[1].slice(-3);
        } else {
            labTestNum = match[1].slice(-2);
        }
        document.getElementById('lab_test_number').value = labTestNum;
    }
}

// Calculate statistics
function calculateStats() {
    const specimens = [];
    for (let i = 1; i <= 10; i++) {
        const val = parseFloat(document.querySelector(`input[name="specimen_${i}"]`).value);
        if (!isNaN(val)) {
            specimens.push(val);
        }
    }
    
    if (specimens.length === 0) return;
    
    // Average
    const avg = specimens.reduce((a, b) => a + b, 0) / specimens.length;
    document.getElementById('average').value = avg.toFixed(2);
    
    // Standard Deviation
    const squareDiffs = specimens.map(val => Math.pow(val - avg, 2));
    const avgSquareDiff = squareDiffs.reduce((a, b) => a + b, 0) / specimens.length;
    const sd = Math.sqrt(avgSquareDiff);
    document.getElementById('sd').value = sd.toFixed(3);
    
    // CV%
    const cv = (sd / avg) * 100;
    document.getElementById('cv_percent').value = cv.toFixed(2);
}

function clearForm() {
    if (confirm('Are you sure you want to clear the form?')) {
        document.getElementById('thicknessForm').reset();
        extractLabTestNumber();
    }
}

function confirmApproval() {
    return confirm('Are you sure you want to approve this report?');
}

function confirmRejection(form) {
    const comments = prompt('Please enter rejection reason (mandatory):');
    if (comments === null || comments.trim() === '') {
        alert('Rejection reason is mandatory!');
        return false;
    }
    form.querySelector('.rejection-comments').value = comments;
    return true;
}
</script>
</body>
</html>


