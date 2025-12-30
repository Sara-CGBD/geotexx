<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.html");
    exit();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/GmailSender.php';

// Check if PHPMailer is installed
$phpmailerInstalled = false;
try {
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
        $phpmailerInstalled = true;
    } elseif (file_exists(__DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php')) {
        require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php';
        require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php';
        require_once __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php';
        $phpmailerInstalled = true;
    }
} catch (Exception $e) {
    $phpmailerInstalled = false;
}

$message = '';
$message_type = '';
$debug_output = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $phpmailerInstalled) {
            $smtp_host = $_POST['smtp_host'] ?? 'smtp.gmail.com';
            $smtp_port = $_POST['smtp_port'] ?? '587';
            $smtp_secure = $_POST['smtp_secure'] ?? 'tls';
            $smtp_username = $_POST['smtp_username'] ?? '';
            $smtp_password = $_POST['smtp_password'] ?? '';
            $from_email = $_POST['from_email'] ?? $smtp_username;
            $from_name = $_POST['from_name'] ?? 'GEOCIL Automation System';
            
            // Auto-fill from_email if not provided
            if (empty($from_email)) {
                $from_email = $smtp_username;
            }
    $test_email = $_POST['test_email'] ?? '';
    
    if (empty($smtp_username) || empty($smtp_password) || empty($test_email)) {
        $message = 'Please fill in all required fields (SMTP Username, Password, and Test Email).';
        $message_type = 'error';
    } else {
        try {
            // Create SMTP config
            $smtp_config = [
                'smtp_host' => $smtp_host,
                'smtp_port' => (int)$smtp_port,
                'smtp_secure' => $smtp_secure,
                'smtp_username' => $smtp_username,
                'smtp_password' => $smtp_password,
                'from_email' => $from_email,
                'from_name' => $from_name,
                'subject' => 'Test Email from GEOCIL'
            ];
            
            // Create GmailSender instance with debug enabled
            $gmailSender = new GmailSender($smtp_config);
            $gmailSender->enableDebug();
            
            // Capture debug output
            ob_start();
            
            // Create test email content
            $htmlContent = '
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #3498db; color: white; padding: 20px; text-align: center; }
                    .content { padding: 20px; background: #f9f9f9; }
                    .footer { text-align: center; padding: 10px; color: #7f8c8d; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h2>✅ Gmail SMTP Test Email</h2>
                    </div>
                    <div class="content">
                        <p>Hello,</p>
                        <p>This is a test email from <strong>GEOCIL Automation System</strong>.</p>
                        <p>If you received this email, your Gmail SMTP configuration is working correctly!</p>
                        <p><strong>Test Details:</strong></p>
                        <ul>
                            <li>SMTP Host: ' . htmlspecialchars($smtp_host) . '</li>
                            <li>SMTP Port: ' . htmlspecialchars($smtp_port) . '</li>
                            <li>SMTP Secure: ' . htmlspecialchars($smtp_secure) . '</li>
                            <li>Sent at: ' . date('Y-m-d H:i:s') . '</li>
                        </ul>
                    </div>
                    <div class="footer">
                        <p>This is an automated test email. Please do not reply.</p>
                    </div>
                </div>
            </body>
            </html>
            ';
            
            // Send test email
            $result = $gmailSender->sendEmail($test_email, 'Test Email from GEOCIL Automation System', $htmlContent);
            
            // Get debug output
            $debug_output = ob_get_clean();
            
            if ($result) {
                $message = '✅ Test email sent successfully to ' . htmlspecialchars($test_email) . '! Please check your inbox.';
                $message_type = 'success';
            } else {
                // Check if the error is about App Password
                $errorMsg = '❌ Failed to send test email. ';
                if (strpos($debug_output, 'Application-specific password required') !== false || 
                    strpos($debug_output, 'InvalidSecondFactor') !== false) {
                    $errorMsg .= '<strong>You need to use an App Password, not your regular password!</strong><br><br>';
                    
                    // Check if it's a Google Workspace account
                    $isWorkspace = strpos($smtp_username, '@g.') !== false || 
                                   strpos($smtp_username, '@') !== false && 
                                   !strpos($smtp_username, '@gmail.com');
                    
                    if ($isWorkspace) {
                        $errorMsg .= '<div style="background:#fff3cd;padding:15px;border-left:4px solid #ffc107;border-radius:4px;margin:10px 0;">';
                        $errorMsg .= '<strong>⚠️ Google Workspace Account Detected</strong><br>';
                        $errorMsg .= 'Your email (' . htmlspecialchars($smtp_username) . ') appears to be a Google Workspace account.<br>';
                        $errorMsg .= '<strong>Solutions:</strong><br>';
                        $errorMsg .= '<ol style="margin-top:10px;padding-left:20px;">';
                        $errorMsg .= '<li><strong>Contact your IT administrator</strong> to enable App Passwords for your account</li>';
                        $errorMsg .= '<li><strong>Or use a personal Gmail account</strong> (@gmail.com) instead</li>';
                        $errorMsg .= '<li><strong>Or ask your admin</strong> to allow "Less secure app access" (if available)</li>';
                        $errorMsg .= '</ol>';
                        $errorMsg .= '</div>';
                    } else {
                        $errorMsg .= '<ol style="margin-top:10px;padding-left:20px;">';
                        $errorMsg .= '<li>Go to <a href="https://myaccount.google.com/security" target="_blank">Google Account Security</a></li>';
                        $errorMsg .= '<li>Enable <strong>2-Step Verification</strong> (if not already enabled)</li>';
                        $errorMsg .= '<li>Go to <a href="https://myaccount.google.com/apppasswords" target="_blank">App Passwords</a></li>';
                        $errorMsg .= '<li>Select "Mail" and "Other (Custom name)" - enter "GEOCIL System"</li>';
                        $errorMsg .= '<li>Copy the <strong>16-character App Password</strong> and use it in the password field above</li>';
                        $errorMsg .= '</ol>';
                        $errorMsg .= '<div style="background:#fff3cd;padding:10px;border-left:4px solid #ffc107;border-radius:4px;margin-top:10px;">';
                        $errorMsg .= '<strong>Note:</strong> If you see "App passwords is not available for your account", you may need to use a personal Gmail account instead.';
                        $errorMsg .= '</div>';
                    }
                } else {
                    $errorMsg .= 'Please check the debug output below and verify your SMTP credentials.';
                }
                $message = $errorMsg;
                $message_type = 'error';
            }
            
        } catch (Exception $e) {
            $message = '❌ Error: ' . htmlspecialchars($e->getMessage());
            $message_type = 'error';
            $debug_output = $e->getTraceAsString();
        }
    }
}

// Load email recipients for quick selection
$recipientsConfig = ['primary' => '', 'team' => [], 'management' => [], 'all' => []];
$recipientsFile = __DIR__ . '/email_recipients.php';
if (file_exists($recipientsFile)) {
    $loaded = require $recipientsFile;
    if (is_array($loaded)) {
        $recipientsConfig = array_merge($recipientsConfig, $loaded);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Gmail SMTP - GEOCIL Automation Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .config-section { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .debug-output { background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 5px; font-family: 'Courier New', monospace; font-size: 12px; max-height: 400px; overflow-y: auto; }
        .info-box { background: #e3f2fd; border-left: 4px solid #2196f3; padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .warning-box { background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-bottom: 20px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-10 mx-auto">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="fas fa-envelope"></i> Test Gmail SMTP Configuration</h4>
                    </div>
                    <div class="card-body">
                        <?php if (!$phpmailerInstalled): ?>
                            <div class="warning-box">
                                <h5><i class="fas fa-exclamation-triangle"></i> PHPMailer Not Installed</h5>
                                <p>PHPMailer is required to send emails via SMTP. Please install it first:</p>
                                <div class="d-grid gap-2 d-md-block mt-3">
                                    <a href="../install_phpmailer_direct.php" class="btn btn-success">
                                        <i class="fas fa-download"></i> Install PHPMailer Directly (Recommended - No Composer Required)
                                    </a>
                                    <a href="../install_phpmailer.php" class="btn btn-secondary">
                                        <i class="fas fa-code"></i> Install via Composer (If Composer is installed)
                                    </a>
                                </div>
                                <hr>
                                <p><strong>Manual Installation:</strong></p>
                                <ol>
                                    <li>If you have Composer: Run <code>composer install</code> in the project root</li>
                                    <li>Or use the direct installer above (downloads and installs automatically)</li>
                                </ol>
                            </div>
                        <?php else: ?>
                            
                            <?php if ($message): ?>
                                <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'danger'; ?>">
                                    <?php echo $message; ?>
                                </div>
                            <?php endif; ?>
                            
                            <form method="POST">
                                <div class="mb-3">
                                    <label for="smtp_username" class="form-label">Your Gmail Address <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" id="smtp_username" name="smtp_username" 
                                           value="<?php echo htmlspecialchars($_POST['smtp_username'] ?? ''); ?>" 
                                           placeholder="your.email@gmail.com" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="smtp_password" class="form-label">Gmail App Password <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" id="smtp_password" name="smtp_password" 
                                           value="<?php echo htmlspecialchars($_POST['smtp_password'] ?? ''); ?>" 
                                           placeholder="Enter your 16-character App Password" required>
                                    <small class="form-text text-danger">
                                        <strong>⚠️ Important:</strong> You MUST use an App Password (16 characters), NOT your regular password!<br>
                                        <a href="https://myaccount.google.com/apppasswords" target="_blank">Get App Password here</a> (Enable 2-Step Verification first)<br>
                                        <strong>Note:</strong> If you have a Google Workspace account (like @university.edu), App Passwords may be disabled. Contact your IT admin or use a personal Gmail account.
                                    </small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="test_email" class="form-label">Send Test Email To <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" id="test_email" name="test_email" 
                                           value="<?php echo htmlspecialchars($_POST['test_email'] ?? $recipientsConfig['primary'] ?? ''); ?>" 
                                           placeholder="recipient@example.com" required>
                                </div>
                                
                                <!-- Hidden fields with default values -->
                                <input type="hidden" name="smtp_host" value="smtp.gmail.com">
                                <input type="hidden" name="smtp_port" value="587">
                                <input type="hidden" name="smtp_secure" value="tls">
                                <input type="hidden" name="from_name" value="GEOCIL Automation System">
                                <input type="hidden" id="from_email" name="from_email" value="">
                                
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="fas fa-paper-plane"></i> Send Test Email
                                    </button>
                                    <a href="email_management.php" class="btn btn-secondary btn-lg">
                                        <i class="fas fa-arrow-left"></i> Back to Email Management
                                    </a>
                                </div>
                            </form>
                            
                            <?php if ($debug_output): ?>
                                <div class="mt-4">
                                    <h5><i class="fas fa-bug"></i> Debug Output</h5>
                                    <div class="debug-output">
                                        <?php echo nl2br(htmlspecialchars($debug_output)); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-fill from_email with Gmail address
        document.getElementById('smtp_username')?.addEventListener('input', function() {
            document.getElementById('from_email').value = this.value;
        });
        
        // Set from_email on page load if smtp_username has a value
        document.addEventListener('DOMContentLoaded', function() {
            const smtpUsername = document.getElementById('smtp_username');
            const fromEmail = document.getElementById('from_email');
            if (smtpUsername && fromEmail && smtpUsername.value) {
                fromEmail.value = smtpUsername.value;
            }
        });
    </script>
</body>
</html>

