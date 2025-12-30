<?php
require_once 'config/security_config.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Create Enterprise Tables</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 50px auto; padding: 20px; background: #f5f7fa; }
        .container { background: white; padding: 40px; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        h1 { color: #667eea; border-bottom: 3px solid #667eea; padding-bottom: 15px; margin-bottom: 30px; }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .step { padding: 15px; margin: 10px 0; background: #f9fafb; border-left: 4px solid #667eea; border-radius: 4px; }
        .btn { display: inline-block; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 6px; margin: 10px 5px; font-weight: 600; }
        .btn:hover { background: #5568d3; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🚀 Creating Enterprise Tables</h1>";

$conn = SecurityConfig::getConnection();

// Table 1: system_performance
echo "<div class='step'>";
$sql = "CREATE TABLE IF NOT EXISTS system_performance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    metric_name VARCHAR(100) NOT NULL,
    metric_value DECIMAL(10,2),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_metric_time (metric_name, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "<span class='success'>✅ Created table: system_performance</span>";
} else {
    echo "<span class='error'>❌ Failed: system_performance - " . $conn->error . "</span>";
}
echo "</div>";

// Table 2: system_health_checks
echo "<div class='step'>";
$sql = "CREATE TABLE IF NOT EXISTS system_health_checks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    check_type VARCHAR(50) NOT NULL,
    status ENUM('ok', 'warning', 'error') NOT NULL,
    message TEXT,
    checked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_check_status (check_type, status, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "<span class='success'>✅ Created table: system_health_checks</span>";
} else {
    echo "<span class='error'>❌ Failed: system_health_checks - " . $conn->error . "</span>";
}
echo "</div>";

// Table 3: error_log
echo "<div class='step'>";
$sql = "CREATE TABLE IF NOT EXISTS error_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    error_type VARCHAR(50),
    error_message TEXT,
    file_path VARCHAR(255),
    line_number INT,
    user_id INT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    request_url TEXT,
    occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_error_type_time (error_type, occurred_at),
    INDEX idx_error_user (user_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "<span class='success'>✅ Created table: error_log</span>";
} else {
    echo "<span class='error'>❌ Failed: error_log - " . $conn->error . "</span>";
}
echo "</div>";

// Table 4: api_access_log
echo "<div class='step'>";
$sql = "CREATE TABLE IF NOT EXISTS api_access_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(255) NOT NULL,
    method VARCHAR(10) NOT NULL,
    user_id INT,
    ip_address VARCHAR(45),
    request_data TEXT,
    response_code INT,
    response_time DECIMAL(10,3),
    accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_api_endpoint (endpoint, accessed_at),
    INDEX idx_api_user (user_id, accessed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "<span class='success'>✅ Created table: api_access_log</span>";
} else {
    echo "<span class='error'>❌ Failed: api_access_log - " . $conn->error . "</span>";
}
echo "</div>";

// Table 5: backup_log
echo "<div class='step'>";
$sql = "CREATE TABLE IF NOT EXISTS backup_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_type ENUM('full', 'incremental', 'differential') NOT NULL,
    file_name VARCHAR(255),
    file_size BIGINT,
    status ENUM('started', 'completed', 'failed') NOT NULL,
    started_at TIMESTAMP NOT NULL,
    completed_at TIMESTAMP NULL,
    error_message TEXT,
    INDEX idx_backup_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "<span class='success'>✅ Created table: backup_log</span>";
} else {
    echo "<span class='error'>❌ Failed: backup_log - " . $conn->error . "</span>";
}
echo "</div>";

// Table 6: archive_settings
echo "<div class='step'>";
$sql = "CREATE TABLE IF NOT EXISTS archive_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(100) NOT NULL UNIQUE,
    archive_after_days INT NOT NULL DEFAULT 365,
    is_enabled TINYINT(1) DEFAULT 1,
    last_archived_at TIMESTAMP NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "<span class='success'>✅ Created table: archive_settings</span>";
} else {
    echo "<span class='error'>❌ Failed: archive_settings - " . $conn->error . "</span>";
}
echo "</div>";

// Table 7: slow_query_log
echo "<div class='step'>";
$sql = "CREATE TABLE IF NOT EXISTS slow_query_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    query_hash VARCHAR(64),
    query_text TEXT,
    execution_time DECIMAL(10,3),
    rows_examined INT,
    user_id INT,
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_query_time (execution_time DESC, executed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "<span class='success'>✅ Created table: slow_query_log</span>";
} else {
    echo "<span class='error'>❌ Failed: slow_query_log - " . $conn->error . "</span>";
}
echo "</div>";

// Insert default archive settings
echo "<div class='step'>";
$sql = "INSERT INTO archive_settings (table_name, archive_after_days, is_enabled) VALUES
('qc_test_orders', 730, 0),
('audit_log', 180, 1),
('login_attempts', 90, 1),
('error_log', 90, 1)
ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP";

if ($conn->query($sql)) {
    echo "<span class='success'>✅ Inserted default archive settings</span>";
} else {
    echo "<span class='error'>⚠️ Archive settings: " . $conn->error . "</span>";
}
echo "</div>";

// Add performance indexes
echo "<div class='step'><h3>Adding Performance Indexes</h3>";

$indexes = [
    "CREATE INDEX idx_qc_status_inspector ON qc_test_orders(status, inspector_id, updated_at)",
    "CREATE INDEX idx_qc_report_status ON qc_test_orders(report_number, status)",
    "CREATE INDEX idx_user_role_active ON new_user(role, is_active, last_login)"
];

foreach ($indexes as $idx_sql) {
    try {
        if ($conn->query($idx_sql)) {
            preg_match('/CREATE INDEX (\w+)/', $idx_sql, $matches);
            echo "<span class='success'>✅ Created index: {$matches[1]}</span><br>";
        }
    } catch (Exception $e) {
        // Ignore if already exists
        if (strpos($e->getMessage(), 'Duplicate') === false) {
            echo "<span class='error'>⚠️ Index error: " . substr($e->getMessage(), 0, 50) . "</span><br>";
        } else {
            preg_match('/CREATE INDEX (\w+)/', $idx_sql, $matches);
            echo "<span style='color: #6b7280;'>✓ Index already exists: {$matches[1]}</span><br>";
        }
    }
}
echo "</div>";

// Verify all tables
echo "<div class='step'><h3>🔍 Verifying Installation</h3>";

$tables = [
    'system_performance',
    'system_health_checks',
    'error_log',
    'api_access_log',
    'backup_log',
    'archive_settings',
    'slow_query_log'
];

$all_exist = true;
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        echo "<p class='success'>✅ $table</p>";
    } else {
        echo "<p class='error'>❌ $table</p>";
        $all_exist = false;
    }
}
echo "</div>";

if ($all_exist) {
    echo "<div class='step' style='background: #ecfdf5; border-color: #10b981;'>
            <h2 class='success'>🎉 All Enterprise Tables Installed!</h2>
            <p>Your system is now enterprise-ready.</p>
          </div>";
} else {
    echo "<div class='step' style='background: #fef2f2; border-color: #ef4444;'>
            <h3 class='error'>⚠️ Some tables are missing</h3>
            <p>Please check the errors above or contact support.</p>
          </div>";
}

$conn->close();

echo "
        <div style='text-align: center; margin-top: 30px;'>
            <a href='run_enterprise_upgrade.php' class='btn'>🔄 Verify Upgrade</a>
            <a href='admin/system_monitor.php' class='btn'>📊 System Monitor</a>
            <a href='index.php' class='btn'>🏠 Dashboard</a>
        </div>
    </div>
</body>
</html>";
?>


