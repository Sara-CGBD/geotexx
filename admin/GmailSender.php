<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class GmailSender {
    private $mailer;
    private $config;
    
    public function __construct($config) {
        $this->config = $config;
        $this->mailer = new PHPMailer(true);
        $this->setupMailer();
    }
    
    private function setupMailer() {
        try {
            // Server settings
            $this->mailer->isSMTP();
            $this->mailer->Host = $this->config['smtp_host'];
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $this->config['smtp_username'];
            $this->mailer->Password = $this->config['smtp_password'];
            $this->mailer->SMTPSecure = $this->config['smtp_secure'];
            $this->mailer->Port = $this->config['smtp_port'];
            
            // Enable debug output (set to 0 for production)
            $this->mailer->SMTPDebug = 0;
            
            // Set charset
            $this->mailer->CharSet = 'UTF-8';
            
        } catch (Exception $e) {
            throw new Exception("Mailer setup failed: " . $e->getMessage());
        }
    }
    
    public function enableDebug() {
        $this->mailer->SMTPDebug = 2; // Enable debug output
    }
    
    public function sendEmail($to, $subject, $htmlContent) {
        try {
            // Clear any previous recipients
            $this->mailer->clearAddresses();
            
            // Recipients
            $this->mailer->setFrom($this->config['from_email'], $this->config['from_name']);
            
            // Handle multiple recipients
            if (is_string($to) && strpos($to, ',') !== false) {
                // Split comma-separated emails
                $emails = array_map('trim', explode(',', $to));
                foreach ($emails as $email) {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $this->mailer->addAddress($email);
                    }
                }
            } elseif (is_array($to)) {
                // Handle array of emails
                foreach ($to as $email) {
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $this->mailer->addAddress($email);
                    }
                }
            } else {
                // Single email address
                $this->mailer->addAddress($to);
            }
            
            // Content
            $this->mailer->isHTML(true);
            $this->mailer->Subject = $subject;
            $this->mailer->Body = $htmlContent;
            
            // Send email
            $this->mailer->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Email sending failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function sendManagementReport($emailContent, $dateRange = '', $recipients = null) {
        $subject = $this->config['subject'] . ' - ' . date('M d, Y');
        if ($dateRange) {
            $subject .= ' (' . $dateRange . ')';
        }
        
        // Use provided recipients or fall back to primary recipient
        if ($recipients === null) {
            $recipientsConfig = require __DIR__ . '/email_recipients.php';
            $recipients = $recipientsConfig['management'];
        }
        
        // Convert array to comma-separated string if needed
        if (is_array($recipients)) {
            $recipients = implode(', ', $recipients);
        }
        
        return $this->sendEmail($recipients, $subject, $emailContent);
    }
}
?> 
