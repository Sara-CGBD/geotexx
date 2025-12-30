<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Ensure BOM table exists
$conn->query("CREATE TABLE IF NOT EXISTS bom (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bag_size VARCHAR(100) UNIQUE,
  unit_price DECIMAL(12,2) DEFAULT 0,
  is_deleted TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$sizes = [
  '2000mmX1500mm','1200mmX950mm','1250mmX1000mm','1225mmX1000mm','1300mmX1050mm',
  '1600mmX850mm','1100mmX850mm','1200mmX600mm','1100mmX800mm','1125mmX900mm',
  '1150mmX800mm','1150mmX850mm','1150mmX900mm','1700mmX1250mm','1050mmX800mm',
  '1075mmX850mm','1030mmX700mm','1000mmX800mm','950mmX750mm','950mmX500mm',
  '830mmX600mm','300mmX299mm','500mmX499mm','700mmX700mm','850mmX700mm',
  '1030mmX750mm','1000mmX700mm'
];

// Delete old bag sizes first
$conn->query("DELETE FROM bom WHERE bag_size IS NOT NULL");

// Insert new sizes, default price 0
$stmt = $conn->prepare("INSERT INTO bom (bag_size, unit_price) VALUES (?, 0)");
foreach ($sizes as $sz) {
    $stmt->bind_param('s', $sz);
    $stmt->execute();
}
$stmt->close();

$conn->query("DELETE FROM bom WHERE bag_size IN ('Finished Product - 70x110','Finished Product - 1125mmX900mm')");

$conn->close();

header('Location: ../reports/bag_size_price_list.php');
exit();
?>



