<?php
// setup_clients.php
// One-time setup to ensure `clients` table exists with required columns and seed sample data

session_start();

// Use shared security config/connection if available
require_once __DIR__ . '/config/security_config.php';

// Always use Bangladesh time
date_default_timezone_set('Asia/Dhaka');

function respond($ok, $messages) {
    header('Content-Type: text/plain; charset=utf-8');
    echo $ok ? "OK\n" : "ERROR\n";
    foreach ($messages as $m) {
        echo "- $m\n";
    }
    exit;
}

$messages = [];
$ok = true;

try {
    $conn = SecurityConfig::getConnection();

    // 1) Create table if not exists (minimal set)
    $createSql = "
        CREATE TABLE IF NOT EXISTS clients (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_name VARCHAR(150) NOT NULL,
            contact_person VARCHAR(120) NULL,
            phone VARCHAR(50) NULL,
            email VARCHAR(120) NULL,
            address VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    if ($conn->query($createSql) === true) {
        $messages[] = 'Ensured clients table exists.';
    } else {
        $ok = false; $messages[] = 'Failed to ensure clients table: ' . $conn->error;
    }

    // 2) Ensure columns (idempotent) - uses ADD COLUMN IF NOT EXISTS where supported
    $ensureCols = [
        "ALTER TABLE clients ADD COLUMN IF NOT EXISTS client_name VARCHAR(150) NOT NULL",
        "ALTER TABLE clients ADD COLUMN IF NOT EXISTS contact_person VARCHAR(120) NULL",
        "ALTER TABLE clients ADD COLUMN IF NOT EXISTS phone VARCHAR(50) NULL",
        "ALTER TABLE clients ADD COLUMN IF NOT EXISTS email VARCHAR(120) NULL",
        "ALTER TABLE clients ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL",
        "ALTER TABLE clients ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE clients ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
    ];
    foreach ($ensureCols as $sql) {
        try {
            if ($conn->query($sql) === true) {
                // ok
            } else {
                // Some MySQL versions may not support IF NOT EXISTS for ADD COLUMN
                // Fallback: ignore duplicate column errors (code 1060)
                if ($conn->errno && $conn->errno !== 1060) {
                    $messages[] = 'Note: ' . $conn->error;
                }
            }
        } catch (Throwable $t) {
            // Ignore if column exists
            $messages[] = 'Note (ensure column): ' . $t->getMessage();
        }
    }

    // 2b) Backfill client_name from legacy columns if empty
    try {
        // If legacy 'name' exists, copy it into client_name where missing
        $hasNameCol = false;
        if ($res = $conn->query("SHOW COLUMNS FROM clients LIKE 'name'")) {
            $hasNameCol = (bool)$res->num_rows;
            $res->close();
        }
        if ($hasNameCol) {
            $conn->query("UPDATE clients SET client_name = name WHERE (client_name IS NULL OR client_name = '') AND (name IS NOT NULL AND name <> '')");
            $messages[] = 'Backfilled client_name from name where missing.';
        }
    } catch (Throwable $t) {
        $messages[] = 'Backfill note: ' . $t->getMessage();
    }

    // 3) Try to add UNIQUE KEY on client_name (ignore if already exists)
    try {
        $conn->query("ALTER TABLE clients ADD UNIQUE KEY uniq_client_name (client_name)");
        if (!$conn->errno) {
            $messages[] = 'Added unique key on client_name.';
        }
    } catch (Throwable $t) {
        $messages[] = 'Unique key ensure note: ' . $t->getMessage();
    }

    // 4) Seed clients (INSERT IGNORE to avoid duplicates if unique key present)
    // Complete list of 81 clients from Geo project
    $seedSql = "INSERT IGNORE INTO clients (client_name, is_active) VALUES 
        ('Confidence Infrastructure Ltd.(ZDL)', 1),
        ('M/S Saleh Ahmed', 1),
        ('Sigma engineers Ltd', 1),
        ('Confidence Infrastructure Ltd(EPC-1)', 1),
        ('M/S Amir Engineering Corporation', 1),
        ('TEESTA SOLAR LIMITED', 1),
        ('MD. FRIDUL ISLAM', 1),
        ('M/S LUCKY CONSTRUCTION', 1),
        ('M/S GOLDER TRADING CORP.', 1),
        ('Grameen Trade International', 1),
        ('M/S. Masuma Begum', 1),
        ('M/S.M.R. Construction', 1),
        ('Sulekhasons Limited', 1),
        ('M/S. Shaju Hardware', 1),
        ('S.S. Rahman International Ltd', 1),
        ('Bengal Structure Development Ltd - Future Infrastructure Development Ltd. (Joint venture)', 1),
        ('OTJ Joint Venture', 1),
        ('NATIONAL DEVELOPMENT ENGINEERS LTD.', 1),
        ('Azmirr Builders Limited', 1),
        ('M/S SK Emdadul Haque Al Mamun', 1),
        ('M/S. Zinnat Ali Zinnah Limited', 1),
        ('MIR AKHTER - WMCG JV', 1),
        ('GOLAM RABBANI CONSTRUCTION LTD.', 1),
        ('ZINNAT ALI ZINNAH LIMITED', 1),
        ('SK.Emdadul Haque Al-Mamun', 1),
        ('Confidence Infrastructure Limited (EPC-3)', 1),
        ('HUDA TRADERS', 1),
        ('M/S. MOSTAFA & SONS', 1),
        ('ATAUR RAHMAN KHAN LTD.', 1),
        ('Samuda Construction Ltd.', 1),
        ('BISWAS TRADING & CONSTRUCTION', 1),
        ('Confidence Infrastructure Ltd.(CIL-Dredging Unit)', 1),
        ('Orient Trading & Builders Ltd.', 1),
        ('Tajwar Trade System Ltd', 1),
        ('M/S AMIR HOSSEN TRADERS', 1),
        ('CREATIVE ENGINEERS LTD.', 1),
        ('BRAC', 1),
        ('HUDA HOLDINGS LIMITED', 1),
        ('Nahee Geotextile Ltd.', 1),
        ('Orchid Geotextile Ltd', 1),
        ('Hassan & Brothers', 1),
        ('DIRD Felt Limited', 1),
        ('M/s. Ahad Builders', 1),
        ('M/S.SHUKTARA CONSTRUCTION', 1),
        ('Project Director, PMO-FRERMIP, BWDB, Dhaka', 1),
        ('ARC Construction Company', 1),
        ('BALY SHRIMP HATCHERY', 1),
        ('Excel Construction', 1),
        ('M/S .khandaker shahin ahmed', 1),
        ('S.S Engineering & Construction Ltd.', 1),
        ('M/S. Sokina & Sons', 1),
        ('M/S.AR-R\'AD Corporation', 1),
        ('Eagle Ridge Engineering & Construction (BD) Ltd.', 1),
        ('SWAN INTERNATIONAL (PVT.) LIMITED', 1),
        ('M/S Sarika Traders', 1),
        ('Nation Tech Communication Limited', 1),
        ('Bengal Group of Industries', 1),
        ('MASTERMIND ENGINEERING LTD.', 1),
        ('Alam & Brothers', 1),
        ('M.M. Builders & Engineers LTD', 1),
        ('Property Development Ltd', 1),
        ('KCEL-BECL JV', 1),
        ('CASTLE CONSTRUCTION CO. LIMITED', 1),
        ('Standard Engineers LTD', 1),
        ('M/S Rasa Enterprise', 1),
        ('First S.S Enterprise (Pvt.) Ltd.', 1),
        ('CROSSIFIC CONSTRUCTION', 1),
        ('BAY DREDGERS LIMITED', 1),
        ('Future Infrastructure Development Ltd.', 1),
        ('Sazzad Hossain', 1),
        ('BIFEX ENGINEERING', 1),
        ('Md. Ataur Rahman', 1),
        ('Energypac Infrastructure & Development Ltd', 1),
        ('Bismillahi Engineering Corporation Limited', 1),
        ('Md. Afaz Uddin', 1),
        ('M/S Masud Trading Corporation', 1),
        ('Shahin Sharif', 1),
        ('IRA Enterprise', 1),
        ('Al-Mostafa Group', 1),
        ('Confidence Cement Dhaka Ltd', 1),
        ('TEKKEN CORPORATION', 1)
    ";
    if ($conn->query($seedSql) === true) {
        $affected = $conn->affected_rows;
        $messages[] = "Seeded clients (inserted/ignored): $affected";
    } else {
        // If no unique key, INSERT IGNORE still works; log any issues
        $messages[] = 'Seeding note: ' . $conn->error;
    }

    // 5) Report count
    $count = 0;
    if ($res = $conn->query("SELECT COUNT(*) AS c FROM clients")) {
        if ($row = $res->fetch_assoc()) { $count = (int)$row['c']; }
        $res->close();
    }
    $messages[] = 'Total clients now: ' . $count;

    $conn->close();
} catch (Throwable $e) {
    $ok = false;
    $messages[] = 'Setup failed: ' . $e->getMessage();
}

respond($ok, $messages);

?>



