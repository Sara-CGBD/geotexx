<?php
header('Content-Type: application/json');

// Database connection
$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

// Fetch all bag sizes from bag_size_master
$bagSizes = [];
$query = "SELECT DISTINCT bag_size FROM bag_size_master WHERE bag_size IS NOT NULL AND bag_size != '' ORDER BY bag_size ASC";
$result = $conn->query($query);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $bagSizes[] = $row['bag_size'];
    }
}

$conn->close();

echo json_encode([
    'success' => true,
    'bagSizes' => $bagSizes,
    'count' => count($bagSizes)
]);
?>



