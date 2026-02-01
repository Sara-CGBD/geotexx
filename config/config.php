<?php
// Secure Database Configuration
// IMPORTANT: Change these credentials for production!

$host = "127.0.0.1";
$port = 3307;
$username = "root";
$password = "";
// NOTE: Project database is named 'geobagg'
$dbname = "geobagg";
$db = $dbname; // Backward compatibility for modules that expect $db

// Create database connection with error handling
$conn = new mysqli($host, $username, $password, $dbname, $port);

// Check connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    die("Database connection error. Please contact administrator.");
}

// Set secure MySQL options
$conn->set_charset("utf8mb4");

// Production error settings
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/path/to/error.log');

// Session security settings - MUST be set before session_start()
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 0); // Disable for localhost (no HTTPS)
    ini_set('session.use_strict_mode', 1);
    session_set_cookie_params([
        'lifetime' => 3600,
        'path' => '/',
        'domain' => '',
        'secure' => false, // Disable for localhost (no HTTPS)
        'httponly' => true,
        'samesite' => 'Lax' // Changed from Strict for better compatibility
    ]);
}
?>
