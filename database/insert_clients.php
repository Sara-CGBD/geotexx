<?php
/**
 * Insert Client List to Database
 * Run this script once to add all clients from the Geo project list
 */

require_once __DIR__ . '/../config/security_config.php';

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
                echo "✓ Inserted: $clientName\n";
            } else {
                $skipped++;
                echo "- Skipped (already exists): $clientName\n";
            }
        } else {
            $errors[] = $clientName . ': ' . $stmt->error;
            echo "✗ Error: $clientName - " . $stmt->error . "\n";
        }
    }
    
    $stmt->close();
    
    // Get total count
    $countResult = $conn->query("SELECT COUNT(*) AS total FROM clients");
    $totalCount = $countResult->fetch_assoc()['total'];
    
    echo "\n";
    echo "========================================\n";
    echo "Summary:\n";
    echo "========================================\n";
    echo "Inserted: $inserted\n";
    echo "Skipped (already exists): $skipped\n";
    echo "Errors: " . count($errors) . "\n";
    echo "Total clients in database: $totalCount\n";
    
    if (!empty($errors)) {
        echo "\nErrors:\n";
        foreach ($errors as $error) {
            echo "  - $error\n";
        }
    }
    
    $conn->close();
    
} catch (Exception $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>


