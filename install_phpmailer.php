<?php
// PHPMailer Installation Script
// Run this script to install PHPMailer

echo "<h2>PHPMailer Installation</h2>";

// Check if Composer is available
if (!file_exists('composer.json')) {
    echo "<p>Creating composer.json...</p>";
    $composerJson = [
        "require" => [
            "phpmailer/phpmailer" => "^6.8"
        ]
    ];
    file_put_contents('composer.json', json_encode($composerJson, JSON_PRETTY_PRINT));
}

// Check if Composer is installed
$composerPath = 'composer';
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $composerPath = 'composer.bat';
}

echo "<p>Installing PHPMailer...</p>";
echo "<pre>";
system($composerPath . ' install', $returnCode);
echo "</pre>";

if ($returnCode === 0) {
    echo "<p style='color: green;'>✅ PHPMailer installed successfully!</p>";
    echo "<p>You can now use Gmail SMTP for sending emails.</p>";
} else {
    echo "<p style='color: red;'>❌ Failed to install PHPMailer using Composer</p>";
    echo "<p><strong>Composer is not installed or not in PATH.</strong></p>";
    echo "<p><strong>Alternative:</strong> Use the direct installer (no Composer required):</p>";
    echo "<p><a href='install_phpmailer_direct.php' style='display:inline-block;padding:10px 20px;background:#28a745;color:white;text-decoration:none;border-radius:5px;'>📥 Install PHPMailer Directly (Recommended)</a></p>";
    echo "<p><small>Or install Composer from <a href='https://getcomposer.org/download/' target='_blank'>getcomposer.org</a> and run: <code>composer install</code></small></p>";
}

echo "<p><a href='admin/email_management.php'>← Back to Email Management</a></p>";
?> 
