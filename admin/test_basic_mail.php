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
// Default to SMTP if PHPMailer is installed (since PHP mail() doesn't work on localhost)
$use_smtp = $_POST['use_smtp'] ?? ($phpmailerInstalled ? '1' : '0');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = $_POST['to'] ?? '';
    $subject = $_POST['subject'] ?? 'Test Email from GEOCIL';
    $body = $_POST['body'] ?? '';
    
    if (empty($to) || empty($body)) {
        $message = 'Please fill in all required fields (To and Message).';
        $message_type = 'error';
    } else {
        // Create HTML email content
        $htmlBody = '
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #3498db; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
                .content { padding: 20px; background: #f9f9f9; border: 1px solid #ddd; }
                .footer { text-align: center; padding: 10px; color: #7f8c8d; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>Test Email from GEOCIL</h2>
                </div>
                <div class="content">
                    ' . nl2br(htmlspecialchars($body)) . '
                </div>
                <div class="footer">
                    <p>This is a test email sent using ' . ($use_smtp === '1' ? 'Gmail SMTP' : 'PHP mail() function') . '.</p>
                    <p>Sent at: ' . date('Y-m-d H:i:s') . '</p>
                </div>
            </div>
        </body>
        </html>
        ';
        
        // Use Gmail SMTP if selected and PHPMailer is available
        if ($use_smtp === '1' && $phpmailerInstalled) {
            $smtp_host = $_POST['smtp_host'] ?? 'smtp.gmail.com';
            $smtp_port = $_POST['smtp_port'] ?? '587';
            $smtp_secure = $_POST['smtp_secure'] ?? 'tls';
            $smtp_username = $_POST['smtp_username'] ?? '';
            $smtp_password = $_POST['smtp_password'] ?? '';
            $from_email = $_POST['from_email'] ?? $smtp_username;
            $from_name = $_POST['from_name'] ?? 'GEOCIL Automation System';
            
            if (empty($smtp_username) || empty($smtp_password)) {
                $message = 'Please provide SMTP Username and Password when using Gmail SMTP.';
                $message_type = 'error';
            } else {
                try {
                    $smtp_config = [
                        'smtp_host' => $smtp_host,
                        'smtp_port' => (int)$smtp_port,
                        'smtp_secure' => $smtp_secure,
                        'smtp_username' => $smtp_username,
                        'smtp_password' => $smtp_password,
                        'from_email' => $from_email,
                        'from_name' => $from_name,
                        'subject' => 'Test Email'
                    ];
                    
                    $gmailSender = new GmailSender($smtp_config);
                    $result = $gmailSender->sendEmail($to, $subject, $htmlBody);
                    
                    if ($result) {
                        $message = '✅ Test email sent successfully via Gmail SMTP to ' . htmlspecialchars($to) . '! Please check your inbox.';
                        $message_type = 'success';
                    } else {
                        $message = '❌ Failed to send test email via Gmail SMTP. Please check your SMTP credentials.';
                        $message_type = 'error';
                    }
                } catch (Exception $e) {
                    $message = '❌ Error: ' . htmlspecialchars($e->getMessage());
                    $message_type = 'error';
                }
            }
        } else {
            // Use PHP mail() function
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= "From: GEOCIL Automation System <noreply@geocil.com>" . "\r\n";
            $headers .= "Reply-To: noreply@geocil.com" . "\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();
            
            $result = mail($to, $subject, $htmlBody, $headers);
            
            if ($result) {
                $message = '✅ Test email sent successfully to ' . htmlspecialchars($to) . '! Please check your inbox (and spam folder).';
                $message_type = 'success';
            } else {
                $message = '❌ Failed to send test email using PHP mail(). This often doesn\'t work on localhost/XAMPP. <strong>Try using Gmail SMTP instead</strong> by checking the option below.';
                $message_type = 'error';
            }
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
    <title>Test Basic Mail - GEOCIL Automation Dashboard</title>
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
                    <div class="card-header bg-info text-white">
                        <h4 class="mb-0"><i class="fas fa-envelope"></i> Test Basic PHP Mail</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-<?php echo $message_type === 'success' ? 'success' : 'danger'; ?>">
                                <?php echo $message; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="warning-box">
                            <h5><i class="fas fa-exclamation-triangle"></i> Important Notes</h5>
                            <ul>
                                <li><strong>PHP mail() function</strong> often doesn't work on localhost/XAMPP</li>
                                <li><strong>Recommended:</strong> Use Gmail SMTP option below for reliable email delivery</li>
                                <li>Gmail SMTP requires a Gmail App Password (not your regular password)</li>
                                <li>If emails don't arrive, check your spam folder</li>
                            </ul>
                        </div>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="use_smtp" name="use_smtp" value="1" 
                                           <?php echo $use_smtp === '1' ? 'checked' : ''; ?>
                                           onchange="toggleSMTPFields()" <?php echo !$phpmailerInstalled ? 'disabled' : ''; ?>>
                                    <label class="form-check-label" for="use_smtp">
                                        <strong>Use Gmail SMTP</strong> (Recommended - works on localhost)
                                    </label>
                                </div>
                                <?php if (!$phpmailerInstalled): ?>
                                    <small class="text-danger">⚠️ PHPMailer not installed. Please install it first or use PHP mail().</small>
                                <?php endif; ?>
                            </div>
                            
                            <div id="smtp_fields" style="display: <?php echo $use_smtp === '1' ? 'block' : 'none'; ?>;">
                                <div class="card mb-3" style="background: #e3f2fd;">
                                    <div class="card-body">
                                        <h5 class="card-title">Gmail SMTP Configuration</h5>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="smtp_host" class="form-label">SMTP Host</label>
                                                <input type="text" class="form-control" id="smtp_host" name="smtp_host" 
                                                       value="<?php echo htmlspecialchars($_POST['smtp_host'] ?? 'smtp.gmail.com'); ?>" required>
                                            </div>
                                            <div class="col-md-3 mb-3">
                                                <label for="smtp_port" class="form-label">Port</label>
                                                <input type="number" class="form-control" id="smtp_port" name="smtp_port" 
                                                       value="<?php echo htmlspecialchars($_POST['smtp_port'] ?? '587'); ?>" required>
                                            </div>
                                            <div class="col-md-3 mb-3">
                                                <label for="smtp_secure" class="form-label">Security</label>
                                                <select class="form-control" id="smtp_secure" name="smtp_secure" required>
                                                    <option value="tls" <?php echo ($_POST['smtp_secure'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                                    <option value="ssl" <?php echo ($_POST['smtp_secure'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="smtp_username" class="form-label">SMTP Username (Gmail) <span class="text-danger">*</span></label>
                                                <input type="email" class="form-control" id="smtp_username" name="smtp_username" 
                                                       value="<?php echo htmlspecialchars($_POST['smtp_username'] ?? ''); ?>" 
                                                       placeholder="your.email@gmail.com">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="smtp_password" class="form-label">SMTP Password (App Password) <span class="text-danger">*</span></label>
                                                <input type="password" class="form-control" id="smtp_password" name="smtp_password" 
                                                       value="<?php echo htmlspecialchars($_POST['smtp_password'] ?? ''); ?>" 
                                                       placeholder="16-character App Password">
                                                <small class="form-text text-muted">Use Gmail App Password, not your regular password</small>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-6 mb-3">
                                                <label for="from_email" class="form-label">From Email</label>
                                                <input type="email" class="form-control" id="from_email" name="from_email" 
                                                       value="<?php echo htmlspecialchars($_POST['from_email'] ?? $_POST['smtp_username'] ?? ''); ?>" 
                                                       placeholder="your.email@gmail.com">
                                            </div>
                                            <div class="col-md-6 mb-3">
                                                <label for="from_name" class="form-label">From Name</label>
                                                <input type="text" class="form-control" id="from_name" name="from_name" 
                                                       value="<?php echo htmlspecialchars($_POST['from_name'] ?? 'GEOCIL Automation System'); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="to" class="form-label">To (Email Address) <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="to" name="to" 
                                       value="<?php echo htmlspecialchars($_POST['to'] ?? $recipientsConfig['primary'] ?? ''); ?>" 
                                       placeholder="recipient@example.com" required>
                                <?php if (!empty($recipientsConfig['primary'])): ?>
                                    <small class="form-text text-muted">
                                        Quick select: 
                                        <a href="#" onclick="document.getElementById('to').value='<?php echo htmlspecialchars($recipientsConfig['primary']); ?>'; return false;">
                                            <?php echo htmlspecialchars($recipientsConfig['primary']); ?>
                                        </a>
                                    </small>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mb-3">
                                <label for="subject" class="form-label">Subject</label>
                                <input type="text" class="form-control" id="subject" name="subject" 
                                       value="<?php echo htmlspecialchars($_POST['subject'] ?? 'Test Email from GEOCIL'); ?>" 
                                       placeholder="Email subject">
                            </div>
                            
                            <div class="mb-3">
                                <label for="body" class="form-label">Message <span class="text-danger">*</span></label>
                                <textarea class="form-control" id="body" name="body" rows="8" 
                                          placeholder="Enter your test message here..." required><?php echo htmlspecialchars($_POST['body'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="submit" class="btn btn-info btn-lg">
                                    <i class="fas fa-paper-plane"></i> Send Test Email
                                </button>
                                <a href="email_management.php" class="btn btn-secondary btn-lg">
                                    <i class="fas fa-arrow-left"></i> Back to Email Management
                                </a>
                            </div>
                        </form>
                        
                        <hr class="my-4">
                        
                        <div class="info-box">
                            <h5><i class="fas fa-info-circle"></i> PHP Mail Configuration</h5>
                            <p><strong>PHP Version:</strong> <?php echo phpversion(); ?></p>
                            <p><strong>Mail Function Available:</strong> <?php echo function_exists('mail') ? '✅ Yes' : '❌ No'; ?></p>
                            <p><strong>SMTP (if configured):</strong> <?php echo ini_get('SMTP') ?: 'Not configured'; ?></p>
                            <p><strong>smtp_port:</strong> <?php echo ini_get('smtp_port') ?: 'Not configured'; ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSMTPFields() {
            const useSMTP = document.getElementById('use_smtp').checked;
            const smtpFields = document.getElementById('smtp_fields');
            smtpFields.style.display = useSMTP ? 'block' : 'none';
            
            // Make SMTP fields required/optional
            const smtpInputs = smtpFields.querySelectorAll('input, select');
            smtpInputs.forEach(input => {
                input.required = useSMTP;
            });
        }
        
        // Auto-fill from_email when smtp_username changes
        document.getElementById('smtp_username')?.addEventListener('input', function() {
            const fromEmail = document.getElementById('from_email');
            if (fromEmail && !fromEmail.value) {
                fromEmail.value = this.value;
            }
        });
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleSMTPFields();
        });
    </script>
</body>
</html>

