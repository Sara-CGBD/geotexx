<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Ensure BOM table exists and provide a one-click sync to current CNC list
if ($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS bom (
      id INT AUTO_INCREMENT PRIMARY KEY,
      bag_size VARCHAR(100) UNIQUE,
      unit_price DECIMAL(12,2) DEFAULT 0,
      is_deleted TINYINT(1) DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (isset($_GET['sync']) && $_GET['sync'] === '1') {
        // Fetch bag sizes from bag_size_master table (updated database values)
        $sizesQuery = "SELECT DISTINCT bag_size FROM bag_size_master WHERE bag_size IS NOT NULL AND bag_size != '' ORDER BY bag_size ASC";
        $sizesResult = $conn->query($sizesQuery);
        $sizes = [];
        if ($sizesResult) {
            while ($row = $sizesResult->fetch_assoc()) {
                $sizes[] = $row['bag_size'];
            }
        }

        // Replace BOM contents with the bag sizes from bag_size_master while preserving existing prices when sizes match
        $conn->query("CREATE TEMPORARY TABLE tmp_bom LIKE bom");
        $conn->query("INSERT INTO tmp_bom (bag_size, unit_price, is_deleted) SELECT bag_size, unit_price, 0 FROM bom");
        $conn->query("DELETE FROM bom");

        if (!empty($sizes)) {
            $stmt = $conn->prepare("INSERT INTO bom (bag_size, unit_price, is_deleted) VALUES (?, COALESCE((SELECT unit_price FROM tmp_bom WHERE bag_size = ? LIMIT 1), 0), 0)");
            foreach ($sizes as $sz) {
                $stmt->bind_param('ss', $sz, $sz);
                $stmt->execute();
            }
            $stmt->close();
        }
        $conn->query("DROP TEMPORARY TABLE IF EXISTS tmp_bom");

        header('Location: bag_size_price_list.php');
        exit();
    }
}

// Fetch all bag sizes with prices from BOM
$bomData = [];
$bomQuery = "SELECT id, bag_size, unit_price, created_at 
             FROM bom 
             WHERE is_deleted = 0 AND bag_size IS NOT NULL 
             ORDER BY bag_size ASC";
$result = $conn->query($bomQuery);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $bomData[] = $row;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Bag Size & Price List</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1000px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }
        
        .info-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; margin-bottom: 25px; text-align: center; }
        .info-card h2 { font-size: 2.5em; margin: 0; }
        .info-card p { margin: 5px 0 0 0; opacity: 0.9; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 15px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        th { background: #34495e; color: white; font-weight: 600; }
        tr:hover { background: #f8f9fa; }
        tr:nth-child(even) { background: #f9f9f9; }
        
        .badge { padding: 6px 12px; border-radius: 5px; font-weight: 600; font-size: 0.9em; }
        .badge-price { background: #d4edda; color: #155724; }
        
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; margin-right: 10px; transition: all 0.3s; }
        .btn-back { background: #95a5a6; color: white; }
        .btn-back:hover { background: #7f8c8d; }
        .btn-edit { background: #3498db; color: white; }
        .btn-edit:hover { background: #2980b9; }
        
        .action-btns { margin-bottom: 20px; }
        
        .no-data { text-align: center; padding: 60px; color: #95a5a6; font-size: 1.1em; }
        
        @media print {
            .action-btns, .btn { display: none; }
            body { background: white; padding: 0; }
            .container { box-shadow: none; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-tags"></i> Bag Size & Unit Price List</h1>
    <p class="subtitle">Current pricing structure for finished goods</p>
    
    <div class="info-card">
        <h2><?php echo count($bomData); ?></h2>
        <p>Bag Sizes Configured</p>
    </div>
    
    <div class="action-btns">
        <a href="../index.php" class="btn btn-back">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        <a href="../forms/BOM_entry.php" class="btn btn-edit">
            <i class="fas fa-edit"></i> Manage BOM
        </a>
    </div>
    
    <?php if (count($bomData) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Bag Size</th>
                    <th>Unit Price (৳)</th>
                    <th>Created Date</th>
                </tr>
            </thead>
            <tbody>
                <?php $counter = 1; foreach ($bomData as $row): ?>
                    <tr>
                        <td><?php echo $counter++; ?></td>
                        <td><strong><?php echo htmlspecialchars($row['bag_size']); ?></strong></td>
                        <td>
                            <span class="badge badge-price">
                                ৳ <?php echo number_format($row['unit_price'], 2); ?>
                            </span>
                        </td>
                        <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="no-data">
            <i class="fas fa-inbox" style="font-size: 4em; margin-bottom: 20px; opacity: 0.3;"></i>
            <p>No bag sizes configured in BOM yet.</p>
            <a href="../forms/BOM_entry.php" class="btn btn-edit" style="margin-top: 15px;">
                <i class="fas fa-plus"></i> Add Bag Sizes
            </a>
        </div>
    <?php endif; ?>
</div>

</body>
</html>


