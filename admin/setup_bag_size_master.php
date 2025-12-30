<?php
// Setup bag_size_master table

$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create table if not exists
$createTable = "CREATE TABLE IF NOT EXISTS bag_size_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sl_no INT NOT NULL,
    bag_size VARCHAR(50) NOT NULL,
    thickness DECIMAL(5,2) NOT NULL,
    gsm INT NOT NULL,
    bag_capacity VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

if ($conn->query($createTable)) {
    echo "Table created successfully.<br>";
} else {
    echo "Error creating table: " . $conn->error . "<br>";
}

// Insert data
$insertData = "INSERT INTO bag_size_master (sl_no, bag_size, thickness, gsm, bag_capacity) VALUES
(1, '2000mmx1500mm', 4.50, 600, '800 kg'),
(2, '1200mmx950mm', 3.30, 450, '250 kg'),
(3, '1250mmx1000mm', 3.30, 450, '250 kg'),
(4, '1250mmx1000mm', 3.00, 400, '250 kg'),
(5, '1225mmx1000mm', 3.00, 400, '250 kg'),
(6, '1200mmx950mm', 3.00, 400, '250 kg'),
(7, '1200mmx950mm', 2.50, 350, '250 kg'),
(8, '1300mmx1050mm', 2.50, 350, '250 kg'),
(9, '1600mmx850mm', 3.30, 450, '250 kg'),
(10, '1100mmx850mm', 3.30, 450, '250 kg'),
(11, '1100mmx850mm', 3.00, 400, '200 kg'),
(12, '1200mmx600mm', 3.00, 400, '200 kg'),
(13, '1100mmx800mm', 3.00, 400, '200 kg'),
(14, '1125mmx900mm', 3.30, 450, '175 kg'),
(15, '1125mmx900mm', 3.00, 400, '175 kg'),
(16, '1125mmx900mm', 2.30, 300, '175 kg'),
(17, '1150mmx800mm', 3.30, 450, '200 kg'),
(18, '1125mmx900mm', 2.50, 350, '200 kg'),
(19, '1150mmx850mm', 3.00, 400, '200 kg'),
(20, '1150mmx900mm', 3.30, 450, '175 kg'),
(21, '1300mmx1050mm', 3.30, 450, '200 kg'),
(22, '1700mmx1250mm', 4.50, 600, '500 kg'),
(23, '1050mmx800mm', 3.30, 450, '175 kg'),
(24, '1050mmx800mm', 3.00, 400, '175 kg'),
(25, '1050mmx800mm', 2.50, 350, '175 kg'),
(26, '1075mmx850mm', 3.30, 450, '175 kg'),
(27, '1075mmx850mm', 3.00, 400, '175 kg'),
(28, '1075mmx850mm', 2.50, 350, '175 kg'),
(29, '1030mmx700mm', 3.00, 400, '126 kg'),
(30, '1030mmx700mm', 2.50, 350, '126 kg'),
(31, '1030mmx700mm', 3.30, 450, '126 kg'),
(32, '1000mmx800mm', 3.30, 450, '125 kg'),
(33, '950mmx750mm', 3.30, 450, '125 kg'),
(34, '950mmx750mm', 3.00, 400, '125 kg'),
(35, '950mmx500mm', 3.30, 450, '125 kg'),
(36, '830mmx600mm', 2.50, 350, '100 kg'),
(37, '830mmx600mm', 3.00, 400, '78 kg'),
(38, '1030mmx700mm', 2.30, 300, '126 kg'),
(39, '1000mmx800mm', 3.00, 400, '125 kg'),
(40, '300mmx299mm', 5.00, 570, '35 kg'),
(41, '500mmx499mm', 5.00, 570, '170 kg'),
(42, '700mmx700mm', 5.00, 570, '450 kg'),
(44, '1000mmx800mm', 3.00, 400, '125 kg'),
(45, '1000mmx800mm', 3.00, 300, '120 kg'),
(46, '850mmx700mm', 3.00, 400, '75 kg'),
(47, '1030mmx750mm', 3.00, 400, '126 kg'),
(48, '1000mmx700mm', 3.00, 400, '125 kg')";

// Delete existing data first
$conn->query("DELETE FROM bag_size_master");

if ($conn->query($insertData)) {
    echo "Data inserted successfully.<br>";
    echo "Total rows: " . $conn->affected_rows . "<br>";
} else {
    echo "Error inserting data: " . $conn->error . "<br>";
}

$conn->close();
echo "<br><a href='../forms/BOM_entry.php'>Go to BOM Entry</a>";
?>



