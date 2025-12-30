<?php
/**
 * Simple PHPMailer Installation (CLI-friendly)
 */

$vendorDir = __DIR__ . '/vendor';
$phpmailerDir = $vendorDir . '/phpmailer/phpmailer';

echo "Installing PHPMailer...\n";

// Create vendor directory
if (!is_dir($vendorDir)) {
    mkdir($vendorDir, 0755, true);
    echo "Created vendor directory\n";
}

// Check if already installed
if (file_exists($phpmailerDir . '/src/PHPMailer.php')) {
    echo "PHPMailer is already installed!\n";
    exit(0);
}

// Download PHPMailer
echo "Downloading PHPMailer...\n";
$zipUrl = 'https://github.com/PHPMailer/PHPMailer/archive/refs/tags/v6.9.1.zip';
$zipFile = $vendorDir . '/phpmailer.zip';

$zipContent = @file_get_contents($zipUrl);
if ($zipContent === false) {
    // Try with curl
    if (function_exists('curl_init')) {
        $ch = curl_init($zipUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $zipContent = curl_exec($ch);
        curl_close($ch);
    }
}

if ($zipContent === false) {
    die("Failed to download PHPMailer. Please check your internet connection.\n");
}

file_put_contents($zipFile, $zipContent);
echo "Downloaded PHPMailer zip\n";

// Extract
echo "Extracting...\n";
$zip = new ZipArchive();
if ($zip->open($zipFile) === TRUE) {
    $tempDir = $vendorDir . '/phpmailer_temp';
    $zip->extractTo($tempDir);
    $zip->close();
    
    // Find extracted directory
    $extractedDirs = glob($tempDir . '/PHPMailer-*');
    if (empty($extractedDirs)) {
        die("Failed to find extracted directory\n");
    }
    
    $extractedDir = $extractedDirs[0];
    
    // Create phpmailer directory
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
        
        echo "Extracted PHPMailer files\n";
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
    
    if (file_exists($phpmailerDir . '/src/PHPMailer.php')) {
        echo "✅ PHPMailer installed successfully!\n";
        echo "Location: $phpmailerDir\n";
    } else {
        die("❌ Installation verification failed\n");
    }
} else {
    die("Failed to open zip file. ZipArchive extension may not be enabled.\n");
}
?>

