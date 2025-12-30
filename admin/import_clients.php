<?php
/**
 * Import Client List to Database
 * Access this page via browser to import all clients
 */

session_start();
require_once '../config/security_config.php';

// Security/session checks
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

date_default_timezone_set('Asia/Dhaka');

try {
    $conn = SecurityConfig::getConnection();
    
    // Ensure clients table exists
    $createSql = "
        CREATE TABLE IF NOT EXISTS clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_name VARCHAR(200) NOT NULL,
            contact_person VARCHAR(120) NULL,
            phone VARCHAR(50) NULL,
            email VARCHAR(120) NULL,
            address VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_client_name (client_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $conn->query($createSql);
    
    // Complete list of 81 clients from Geo project
    $clients = [
        'Confidence Infrastructure Ltd.(ZDL)',
        'M/S Saleh Ahmed',
        'Sigma engineers Ltd',
        'Confidence Infrastructure Ltd(EPC-1)',
        'M/S Amir Engineering Corporation',
        'TEESTA SOLAR LIMITED',
        'MD. FRIDUL ISLAM',
        'M/S LUCKY CONSTRUCTION',
        'M/S GOLDER TRADING CORP.',
        'Grameen Trade International',
        'M/S. Masuma Begum',
        'M/S.M.R. Construction',
        'Sulekhasons Limited',
        'M/S. Shaju Hardware',
        'S.S. Rahman International Ltd',
        'Bengal Structure Development Ltd - Future Infrastructure Development Ltd. (Joint venture)',
        'OTJ Joint Venture',
        'NATIONAL DEVELOPMENT ENGINEERS LTD.',
        'Azmirr Builders Limited',
        'M/S SK Emdadul Haque Al Mamun',
        'M/S. Zinnat Ali Zinnah Limited',
        'MIR AKHTER - WMCG JV',
        'GOLAM RABBANI CONSTRUCTION LTD.',
        'ZINNAT ALI ZINNAH LIMITED',
        'SK.Emdadul Haque Al-Mamun',
        'Confidence Infrastructure Limited (EPC-3)',
        'HUDA TRADERS',
        'M/S. MOSTAFA & SONS',
        'ATAUR RAHMAN KHAN LTD.',
        'Samuda Construction Ltd.',
        'BISWAS TRADING & CONSTRUCTION',
        'Confidence Infrastructure Ltd.(CIL-Dredging Unit)',
        'Orient Trading & Builders Ltd.',
        'Tajwar Trade System Ltd',
        'M/S AMIR HOSSEN TRADERS',
        'CREATIVE ENGINEERS LTD.',
        'BRAC',
        'HUDA HOLDINGS LIMITED',
        'Nahee Geotextile Ltd.',
        'Orchid Geotextile Ltd',
        'Hassan & Brothers',
        'DIRD Felt Limited',
        'M/s. Ahad Builders',
        'M/S.SHUKTARA CONSTRUCTION',
        'Project Director, PMO-FRERMIP, BWDB, Dhaka',
        'ARC Construction Company',
        'BALY SHRIMP HATCHERY',
        'Excel Construction',
        'M/S .khandaker shahin ahmed',
        'S.S Engineering & Construction Ltd.',
        'M/S. Sokina & Sons',
        'M/S.AR-R\'AD Corporation',
        'Eagle Ridge Engineering & Construction (BD) Ltd.',
        'SWAN INTERNATIONAL (PVT.) LIMITED',
        'M/S Sarika Traders',
        'Nation Tech Communication Limited',
        'Bengal Group of Industries',
        'MASTERMIND ENGINEERING LTD.',
        'Alam & Brothers',
        'M.M. Builders & Engineers LTD',
        'Property Development Ltd',
        'KCEL-BECL JV',
        'CASTLE CONSTRUCTION CO. LIMITED',
        'Standard Engineers LTD',
        'M/S Rasa Enterprise',
        'First S.S Enterprise (Pvt.) Ltd.',
        'CROSSIFIC CONSTRUCTION',
        'BAY DREDGERS LIMITED',
        'Future Infrastructure Development Ltd.',
        'Sazzad Hossain',
        'BIFEX ENGINEERING',
        'Md. Ataur Rahman',
        'Energypac Infrastructure & Development Ltd',
        'Bismillahi Engineering Corporation Limited',
        'Md. Afaz Uddin',
        'M/S Masud Trading Corporation',
        'Shahin Sharif',
        'IRA Enterprise',
        'Al-Mostafa Group',
        'Confidence Cement Dhaka Ltd',
        'TEKKEN CORPORATION'
    ];
    
    $inserted = 0;
    $skipped = 0;
    $errors = [];
    
    $stmt = $conn->prepare("INSERT IGNORE INTO clients (client_name, is_active) VALUES (?, 1)");
    
    foreach ($clients as $clientName) {
        $stmt->bind_param('s', $clientName);
        if ($stmt->execute()) {
            if ($conn->affected_rows > 0) {
                $inserted++;
            } else {
                $skipped++;
            }
        } else {
            $errors[] = $clientName . ': ' . $stmt->error;
        }
    }
    
    $stmt->close();
    
    // Get total count
    $countResult = $conn->query("SELECT COUNT(*) AS total FROM clients");
    $totalCount = $countResult->fetch_assoc()['total'];
    
    $conn->close();
    
    $message = "✅ Client import completed!\n\n";
    $message .= "Inserted: $inserted\n";
    $message .= "Skipped (already exists): $skipped\n";
    $message .= "Total clients in database: $totalCount";
    
    if (!empty($errors)) {
        $message .= "\n\nErrors:\n" . implode("\n", $errors);
    }
    
} catch (Exception $e) {
    $message = "❌ Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Clients</title>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f4f6f9;
            padding: 40px;
            max-width: 800px;
            margin: 0 auto;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            margin-bottom: 20px;
        }
        pre {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid #3498db;
            white-space: pre-wrap;
            font-family: 'Courier New', monospace;
        }
        .success {
            color: #27ae60;
        }
        .error {
            color: #e74c3c;
        }
        a {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: #3498db;
            color: white;
            text-decoration: none;
            border-radius: 6px;
        }
        a:hover {
            background: #2980b9;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📋 Client Import Results</h1>
        <pre><?php echo htmlspecialchars($message); ?></pre>
        <a href="../forms/fg_delivery_entry.php">← Back to FG Delivery Entry</a>
        <a href="../index.php" style="margin-left: 10px;">← Back to Dashboard</a>
    </div>
</body>
</html>


