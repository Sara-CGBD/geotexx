<?php
/**
 * Direct PHPMailer Installation Script (No Composer Required)
 * Downloads and installs PHPMailer directly
 */

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>PHPMailer Installation</title>";
echo "<style>body{font-family:Arial,sans-serif;max-width:800px;margin:50px auto;padding:20px;}";
echo ".success{color:green;padding:10px;background:#d4edda;border:1px solid #c3e6cb;border-radius:5px;}";
echo ".error{color:red;padding:10px;background:#f8d7da;border:1px solid #f5c6cb;border-radius:5px;}";
echo ".info{color:#0c5460;padding:10px;background:#d1ecf1;border:1px solid #bee5eb;border-radius:5px;}";
echo "pre{background:#f4f4f4;padding:10px;border-radius:5px;overflow-x:auto;}</style></head><body>";
echo "<h2>📧 PHPMailer Direct Installation</h2>";

$vendorDir = __DIR__ . '/vendor';
$phpmailerDir = $vendorDir . '/phpmailer/phpmailer';

// Create vendor directory if it doesn't exist
if (!is_dir($vendorDir)) {
    if (!mkdir($vendorDir, 0755, true)) {
        die("<div class='error'>❌ Failed to create vendor directory. Please check permissions.</div></body></html>");
    }
    echo "<div class='success'>✅ Created vendor directory</div>";
}

// Check if PHPMailer is already installed
if (file_exists($phpmailerDir . '/src/PHPMailer.php')) {
    echo "<div class='success'>✅ PHPMailer is already installed!</div>";
    echo "<p><a href='admin/test_gmail_smtp.php'>Go to Test Gmail SMTP</a></p>";
    echo "</body></html>";
    exit;
}

// Download PHPMailer from GitHub
echo "<div class='info'>📥 Downloading PHPMailer from GitHub...</div>";

$zipUrl = 'https://github.com/PHPMailer/PHPMailer/archive/refs/tags/v6.9.1.zip';
$zipFile = $vendorDir . '/phpmailer.zip';

// Download the zip file
$ch = curl_init($zipUrl);
$fp = fopen($zipFile, 'w');
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
fclose($fp);

if ($httpCode !== 200 || !file_exists($zipFile)) {
    // Try alternative method using file_get_contents
    echo "<div class='info'>Trying alternative download method...</div>";
    $zipContent = @file_get_contents($zipUrl);
    if ($zipContent === false) {
        die("<div class='error'>❌ Failed to download PHPMailer. Please check your internet connection or install Composer.</div>");
    }
    file_put_contents($zipFile, $zipContent);
}

if (!file_exists($zipFile)) {
    die("<div class='error'>❌ Failed to download PHPMailer zip file.</div></body></html>");
}

echo "<div class='success'>✅ Downloaded PHPMailer zip file</div>";

// Extract the zip file
echo "<div class='info'>📦 Extracting PHPMailer...</div>";

$zip = new ZipArchive();
if ($zip->open($zipFile) === TRUE) {
    // Extract to a temporary directory first
    $tempDir = $vendorDir . '/phpmailer_temp';
    if (is_dir($tempDir)) {
        // Remove existing temp directory
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            @$todo($fileinfo->getRealPath());
        }
        @rmdir($tempDir);
    }
    
    $zip->extractTo($tempDir);
    $zip->close();
    
    // Find the extracted folder (it will be PHPMailer-6.9.1 or similar)
    $extractedDirs = glob($tempDir . '/PHPMailer-*');
    if (empty($extractedDirs)) {
        die("<div class='error'>❌ Failed to find extracted PHPMailer directory.</div></body></html>");
    }
    
    $extractedDir = $extractedDirs[0];
    
    // Create the phpmailer directory structure
    if (!is_dir($phpmailerDir)) {
        mkdir($phpmailerDir, 0755, true);
    }
    
    // Copy src directory
    if (is_dir($extractedDir . '/src')) {
        $srcDest = $phpmailerDir . '/src';
        if (!is_dir($srcDest)) {
            mkdir($srcDest, 0755, true);
        }
        
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($extractedDir . '/src', RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($files as $fileinfo) {
            $destPath = $srcDest . DIRECTORY_SEPARATOR . $files->getSubPathName();
            if ($fileinfo->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                copy($fileinfo->getRealPath(), $destPath);
            }
        }
        
        echo "<div class='success'>✅ Extracted PHPMailer files</div>";
    } else {
        die("<div class='error'>❌ Source directory not found in extracted files.</div></body></html>");
    }
    
    // Clean up
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        @$todo($fileinfo->getRealPath());
    }
    @rmdir($tempDir);
    @unlink($zipFile);
    
} else {
    die("<div class='error'>❌ Failed to open zip file. Please check if ZipArchive is enabled in PHP.</div></body></html>");
}

// Verify installation
if (file_exists($phpmailerDir . '/src/PHPMailer.php')) {
    echo "<div class='success'>✅ PHPMailer installed successfully!</div>";
    echo "<div class='info'>";
    echo "<p><strong>Installation Path:</strong> " . htmlspecialchars($phpmailerDir) . "</p>";
    echo "<p>You can now use Gmail SMTP for sending emails.</p>";
    echo "</div>";
    echo "<p><a href='admin/test_gmail_smtp.php' style='display:inline-block;padding:10px 20px;background:#28a745;color:white;text-decoration:none;border-radius:5px;'>Go to Test Gmail SMTP</a></p>";
    echo "<p><a href='admin/email_management.php' style='display:inline-block;padding:10px 20px;background:#007bff;color:white;text-decoration:none;border-radius:5px;'>Back to Email Management</a></p>";
} else {
    echo "<div class='error'>❌ Installation verification failed. PHPMailer.php not found.</div>";
}

echo "</body></html>";
?>

