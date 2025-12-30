<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.html");
    exit();
}

require_once __DIR__ . '/../config/security_config.php';
require_once __DIR__ . '/GmailSender.php';

// Role-based access control
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'management', 'agm ops', 'agm operations'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access Management Dashboard.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

$conn = SecurityConfig::getConnection();
$message = '';
$message_type = '';

// Load email recipients
$recipientsConfig = ['primary' => '', 'team' => [], 'management' => [], 'all' => []];
$recipientsFile = __DIR__ . '/email_recipients.php';
if (file_exists($recipientsFile)) {
    $loaded = require $recipientsFile;
    if (is_array($loaded)) {
        $recipientsConfig = array_merge($recipientsConfig, $loaded);
    }
}

// Handle sending management report
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_report'])) {
    // Check if Gmail SMTP is configured
    $smtp_config_file = __DIR__ . '/gmail_smtp_config.php';
    
    if (!file_exists($smtp_config_file)) {
        $message = '❌ Gmail SMTP is not configured. Please configure it first in <a href="test_gmail_smtp.php">Test Gmail SMTP</a> page. For now, you can manually enter SMTP credentials below.';
        $message_type = 'error';
        
        // Allow manual SMTP configuration
        if (isset($_POST['smtp_username']) && isset($_POST['smtp_password'])) {
            $smtp_config = [
                'smtp_host' => $_POST['smtp_host'] ?? 'smtp.gmail.com',
                'smtp_port' => (int)($_POST['smtp_port'] ?? 587),
                'smtp_secure' => $_POST['smtp_secure'] ?? 'tls',
                'smtp_username' => $_POST['smtp_username'],
                'smtp_password' => $_POST['smtp_password'],
                'from_email' => $_POST['from_email'] ?? $_POST['smtp_username'],
                'from_name' => $_POST['from_name'] ?? 'GEOCIL Automation System',
                'subject' => 'Management Report'
            ];
            
            try {
                // Generate management report content
                $dateFrom = $_POST['date_from'] ?? date('Y-m-01');
                $dateTo = $_POST['date_to'] ?? date('Y-m-d');
                
                // Fetch key metrics
                $stats = [];
                
                // Production stats
                $productionQuery = "SELECT COUNT(*) as total, SUM(quantity) as qty FROM roll_production WHERE DATE(production_date) BETWEEN ? AND ?";
                $stmt = $conn->prepare($productionQuery);
                $stmt->bind_param('ss', $dateFrom, $dateTo);
                $stmt->execute();
                $productionResult = $stmt->get_result()->fetch_assoc();
                $stats['production'] = $productionResult;
                $stmt->close();
                
                // QC stats
                $qcQuery = "SELECT COUNT(*) as total, SUM(CASE WHEN status = 'Pass' THEN 1 ELSE 0 END) as passed FROM qc_tests WHERE DATE(test_date) BETWEEN ? AND ?";
                $stmt = $conn->prepare($qcQuery);
                $stmt->bind_param('ss', $dateFrom, $dateTo);
                $stmt->execute();
                $qcResult = $stmt->get_result()->fetch_assoc();
                $stats['qc'] = $qcResult;
                $stmt->close();
                
                // Create HTML report
                $htmlContent = '
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
                        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
                        .content { padding: 30px; background: #f9f9f9; border: 1px solid #ddd; }
                        .stat-box { background: white; padding: 20px; margin: 15px 0; border-radius: 8px; border-left: 4px solid #3498db; }
                        .stat-box h3 { margin: 0 0 10px 0; color: #2c3e50; }
                        .stat-box p { margin: 5px 0; color: #7f8c8d; }
                        .footer { text-align: center; padding: 20px; color: #7f8c8d; font-size: 12px; background: #ecf0f1; border-radius: 0 0 8px 8px; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <h1>📊 Management Report</h1>
                            <p>Period: ' . date('M d, Y', strtotime($dateFrom)) . ' to ' . date('M d, Y', strtotime($dateTo)) . '</p>
                        </div>
                        <div class="content">
                            <h2>Key Performance Indicators</h2>
                            
                            <div class="stat-box">
                                <h3>Production</h3>
                                <p><strong>Total Rolls Produced:</strong> ' . number_format($stats['production']['total'] ?? 0) . '</p>
                                <p><strong>Total Quantity:</strong> ' . number_format($stats['production']['qty'] ?? 0) . ' kg</p>
                            </div>
                            
                            <div class="stat-box">
                                <h3>Quality Control</h3>
                                <p><strong>Total Inspections:</strong> ' . number_format($stats['qc']['total'] ?? 0) . '</p>
                                <p><strong>Passed:</strong> ' . number_format($stats['qc']['passed'] ?? 0) . '</p>
                                <p><strong>Pass Rate:</strong> ' . ($stats['qc']['total'] > 0 ? number_format(($stats['qc']['passed'] / $stats['qc']['total']) * 100, 2) : 0) . '%</p>
                            </div>
                            
                            <p style="margin-top: 30px; color: #7f8c8d;">
                                <em>This is an automated management report generated by GEOCIL Automation System.</em>
                            </p>
                        </div>
                        <div class="footer">
                            <p>Generated on ' . date('F d, Y \a\t H:i:s') . '</p>
                            <p>GEOCIL Automation System</p>
                        </div>
                    </div>
                </body>
                </html>
                ';
                
                // Send email using GmailSender
                $gmailSender = new GmailSender($smtp_config);
                $dateRange = date('M d', strtotime($dateFrom)) . ' - ' . date('M d, Y', strtotime($dateTo));
                
                $recipients = $recipientsConfig['management'];
                if (empty($recipients)) {
                    $recipients = [$recipientsConfig['primary']];
                }
                
                $result = $gmailSender->sendManagementReport($htmlContent, $dateRange, $recipients);
                
                if ($result) {
                    $message = '✅ Management report sent successfully to ' . count($recipients) . ' recipient(s)!';
                    $message_type = 'success';
                } else {
                    $message = '❌ Failed to send management report. Please check your Gmail SMTP configuration.';
                    $message_type = 'error';
                }
                
            } catch (Exception $e) {
                $message = '❌ Error: ' . htmlspecialchars($e->getMessage());
                $message_type = 'error';
            }
        }
    } else {
        try {
            $smtp_config = require $smtp_config_file;
            
            // Generate management report content
            $dateFrom = $_POST['date_from'] ?? date('Y-m-01'); // First day of current month
            $dateTo = $_POST['date_to'] ?? date('Y-m-d');
            
            // Fetch key metrics
            $stats = [];
            
            // Production stats
            $productionQuery = "SELECT COUNT(*) as total, SUM(quantity) as qty FROM roll_production WHERE DATE(production_date) BETWEEN ? AND ?";
            $stmt = $conn->prepare($productionQuery);
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $productionResult = $stmt->get_result()->fetch_assoc();
            $stats['production'] = $productionResult;
            $stmt->close();
            
            // QC stats
            $qcQuery = "SELECT COUNT(*) as total, SUM(CASE WHEN status = 'Pass' THEN 1 ELSE 0 END) as passed FROM qc_tests WHERE DATE(test_date) BETWEEN ? AND ?";
            $stmt = $conn->prepare($qcQuery);
            $stmt->bind_param('ss', $dateFrom, $dateTo);
            $stmt->execute();
            $qcResult = $stmt->get_result()->fetch_assoc();
            $stats['qc'] = $qcResult;
            $stmt->close();
            
            // Create HTML report
            $htmlContent = '
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 800px; margin: 0 auto; padding: 20px; }
                    .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
                    .content { padding: 30px; background: #f9f9f9; border: 1px solid #ddd; }
                    .stat-box { background: white; padding: 20px; margin: 15px 0; border-radius: 8px; border-left: 4px solid #3498db; }
                    .stat-box h3 { margin: 0 0 10px 0; color: #2c3e50; }
                    .stat-box p { margin: 5px 0; color: #7f8c8d; }
                    .footer { text-align: center; padding: 20px; color: #7f8c8d; font-size: 12px; background: #ecf0f1; border-radius: 0 0 8px 8px; }
                    table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                    th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
                    th { background: #34495e; color: white; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>📊 Management Report</h1>
                        <p>Period: ' . date('M d, Y', strtotime($dateFrom)) . ' to ' . date('M d, Y', strtotime($dateTo)) . '</p>
                    </div>
                    <div class="content">
                        <h2>Key Performance Indicators</h2>
                        
                        <div class="stat-box">
                            <h3>Production</h3>
                            <p><strong>Total Rolls Produced:</strong> ' . number_format($stats['production']['total'] ?? 0) . '</p>
                            <p><strong>Total Quantity:</strong> ' . number_format($stats['production']['qty'] ?? 0) . ' kg</p>
                        </div>
                        
                        <div class="stat-box">
                            <h3>Quality Control</h3>
                            <p><strong>Total Inspections:</strong> ' . number_format($stats['qc']['total'] ?? 0) . '</p>
                            <p><strong>Passed:</strong> ' . number_format($stats['qc']['passed'] ?? 0) . '</p>
                            <p><strong>Pass Rate:</strong> ' . ($stats['qc']['total'] > 0 ? number_format(($stats['qc']['passed'] / $stats['qc']['total']) * 100, 2) : 0) . '%</p>
                        </div>
                        
                        <p style="margin-top: 30px; color: #7f8c8d;">
                            <em>This is an automated management report generated by GEOCIL Automation System.</em>
                        </p>
                    </div>
                    <div class="footer">
                        <p>Generated on ' . date('F d, Y \a\t H:i:s') . '</p>
                        <p>GEOCIL Automation System</p>
                    </div>
                </div>
            </body>
            </html>
            ';
            
            // Send email using GmailSender
            $gmailSender = new GmailSender($smtp_config);
            $dateRange = date('M d', strtotime($dateFrom)) . ' - ' . date('M d, Y', strtotime($dateTo));
            
            $recipients = $recipientsConfig['management'];
            if (empty($recipients)) {
                $recipients = [$recipientsConfig['primary']];
            }
            
            $result = $gmailSender->sendManagementReport($htmlContent, $dateRange, $recipients);
            
            if ($result) {
                $message = '✅ Management report sent successfully to ' . count($recipients) . ' recipient(s)!';
                $message_type = 'success';
            } else {
                $message = '❌ Failed to send management report. Please check your Gmail SMTP configuration.';
                $message_type = 'error';
            }
            
        } catch (Exception $e) {
            $message = '❌ Error: ' . htmlspecialchars($e->getMessage());
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Management Dashboard - GEOCIL Automation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .info-box { background: #e3f2fd; border-left: 4px solid #2196f3; padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .warning-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-bottom: 20px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-10 mx-auto">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h4 class="mb-0"><i class="fas fa-chart-bar"></i> Send Management Report</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'danger'; ?>">
                                <?php echo $message; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="info-box">
                            <h5><i class="fas fa-info-circle"></i> Management Report</h5>
                            <p>Send a comprehensive management report via email to the management team. The report includes key performance indicators, production statistics, and quality control metrics.</p>
                        </div>
                        
                        <?php if (!file_exists(__DIR__ . '/gmail_smtp_config.php')): ?>
                            <div class="warning-box">
                                <h5><i class="fas fa-exclamation-triangle"></i> Gmail SMTP Not Configured</h5>
                                <p>Gmail SMTP configuration file not found. Please configure it in <a href="test_gmail_smtp.php">Test Gmail SMTP</a> page, or enter SMTP credentials below to send the report.</p>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="date_from" class="form-label">From Date</label>
                                    <input type="date" class="form-control" id="date_from" name="date_from" 
                                           value="<?php echo htmlspecialchars($_POST['date_from'] ?? date('Y-m-01')); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="date_to" class="form-label">To Date</label>
                                    <input type="date" class="form-control" id="date_to" name="date_to" 
                                           value="<?php echo htmlspecialchars($_POST['date_to'] ?? date('Y-m-d')); ?>" required>
                                </div>
                            </div>
                            
                            <?php if (!file_exists(__DIR__ . '/gmail_smtp_config.php')): ?>
                                <div class="card mb-3" style="background: #fff3cd;">
                                    <div class="card-body">
                                        <h5 class="card-title">SMTP Configuration</h5>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="smtp_host" class="form-label">SMTP Host</label>
                                                <input type="text" class="form-control" id="smtp_host" name="smtp_host" value="smtp.gmail.com" required>
                                            </div>
                                            <div class="col-md-3 mb-3">
                                                <label for="smtp_port" class="form-label">Port</label>
                                                <input type="number" class="form-control" id="smtp_port" name="smtp_port" value="587" required>
                                            </div>
                                            <div class="col-md-3 mb-3">
                                                <label for="smtp_secure" class="form-label">Security</label>
                                                <select class="form-control" id="smtp_secure" name="smtp_secure" required>
                                                    <option value="tls" selected>TLS</option>
                                                    <option value="ssl">SSL</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="smtp_username" class="form-label">SMTP Username (Gmail) <span class="text-danger">*</span></label>
                                                <input type="email" class="form-control" id="smtp_username" name="smtp_username" placeholder="your.email@gmail.com" required>
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="smtp_password" class="form-label">SMTP Password (App Password) <span class="text-danger">*</span></label>
                                                <input type="password" class="form-control" id="smtp_password" name="smtp_password" placeholder="16-character App Password" required>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="from_email" class="form-label">From Email</label>
                                                <input type="email" class="form-control" id="from_email" name="from_email" placeholder="your.email@gmail.com">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="from_name" class="form-label">From Name</label>
                                                <input type="text" class="form-control" id="from_name" name="from_name" value="GEOCIL Automation System">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label class="form-label">Recipients</label>
                                <div class="form-control" style="background: #f8f9fa;">
                                    <?php if (!empty($recipientsConfig['management'])): ?>
                                        <strong>Management Team:</strong><br>
                                        <?php foreach ($recipientsConfig['management'] as $email): ?>
                                            <span class="badge bg-primary"><?php echo htmlspecialchars($email); ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted">No management recipients configured. <a href="email_management.php">Configure here</a></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" name="send_report" class="btn btn-warning btn-lg">
                                    <i class="fas fa-paper-plane"></i> Send Management Report
                                </button>
                                <a href="management_kpi_dashboard.php" class="btn btn-primary btn-lg">
                                    <i class="fas fa-chart-line"></i> View KPI Dashboard
                                </a>
                                <a href="email_management.php" class="btn btn-secondary btn-lg">
                                    <i class="fas fa-arrow-left"></i> Back to Email Management
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

