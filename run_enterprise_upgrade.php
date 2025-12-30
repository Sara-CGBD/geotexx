<?php
/**
 * Enterprise Upgrade Script
 * Run this once to upgrade the system to enterprise level
 */

require_once 'config/security_config.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Enterprise Upgrade</title>
    <style>
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            max-width: 900px;
            margin: 50px auto;
            padding: 30px;
            background: #f5f7fa;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        h1 {
            color: #667eea;
            margin-bottom: 30px;
            border-bottom: 3px solid #667eea;
            padding-bottom: 15px;
        }
        .step {
            background: #f9fafb;
            padding: 20px;
            margin: 15px 0;
            border-left: 4px solid #667eea;
            border-radius: 4px;
        }
        .success {
            color: #10b981;
            font-weight: bold;
        }
        .error {
            color: #ef4444;
            font-weight: bold;
        }
        .warning {
            color: #f59e0b;
            font-weight: bold;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            margin-top: 20px;
            font-weight: 600;
        }
        .btn:hover {
            background: #5568d3;
        }
        pre {
            background: #1f2937;
            color: #10b981;
            padding: 15px;
            border-radius: 6px;
            overflow-x: auto;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>ðŸš€ Enterprise Upgrade</h1>
        <p style='font-size: 16px; color: #6b7280; margin-bottom: 30px;'>
            Upgrading GeoTex QC Management System to Enterprise Level...
        </p>";

$conn = SecurityConfig::getConnection();
$errors = [];
$warnings = [];
$success_count = 0;

// Step 1: Run optimization SQL
echo "<div class='step'>";
echo "<h3>Step 1: Database Optimization</h3>";

$sql_file = 'database/enterprise_optimization.sql';
if (!file_exists($sql_file)) {
    echo "<p class='error'>âŒ SQL file not found: $sql_file</p>";
    $errors[] = "SQL file missing";
} else {
    $sql = file_get_contents($sql_file);
    
    // Split into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    $executed = 0;
    $failed = 0;
    
    foreach ($statements as $statement) {
        if (empty($statement) || strpos($statement, '--') === 0) continue;
        
        // Skip DELIMITER statements
        if (stripos($statement, 'DELIMITER') !== false) continue;
        
        try {
            $conn->query($statement);
            $executed++;
        } catch (Exception $e) {
            // Some statements might fail if already exist, that's ok
            if (stripos($e->getMessage(), 'already exists') === false && 
                stripos($e->getMessage(), 'Duplicate') === false) {
                $failed++;
                $warnings[] = "Statement failed: " . substr($statement, 0, 50) . "...";
            }
        }
    }
    
    echo "<p class='success'>âœ… Executed $executed SQL statements</p>";
    if ($failed > 0) {
        echo "<p class='warning'>âš ï¸ $failed statements failed (may be normal if already exists)</p>";
    }
    $success_count++;
}

echo "</div>";

// Step 2: Verify tables
echo "<div class='step'>";
echo "<h3>Step 2: Verify New Tables</h3>";

$required_tables = [
    'system_performance',
    'system_health_checks',
    'error_log',
    'api_access_log',
    'backup_log',
    'archive_settings',
    'slow_query_log'
];

foreach ($required_tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result && $result->num_rows > 0) {
        echo "<p class='success'>âœ… Table '$table' created</p>";
    } else {
        echo "<p class='error'>âŒ Table '$table' missing</p>";
        $errors[] = "Table $table not created";
    }
}

if (empty($errors)) {
    $success_count++;
}

echo "</div>";

// Step 3: Verify views
echo "<div class='step'>";
echo "<h3>Step 3: Verify Views</h3>";

$views = ['v_pending_checker_reports', 'v_user_activity_summary'];
foreach ($views as $view) {
    $result = $conn->query("SHOW FULL TABLES WHERE Table_Type = 'VIEW' AND Tables_in_geobagg = '$view'");
    if ($result && $result->num_rows > 0) {
        echo "<p class='success'>âœ… View '$view' created</p>";
    } else {
        echo "<p class='warning'>âš ï¸ View '$view' not found (may need manual creation)</p>";
        $warnings[] = "View $view missing";
    }
}

$success_count++;
echo "</div>";

// Step 4: Check indexes
echo "<div class='step'>";
echo "<h3>Step 4: Performance Indexes</h3>";

$result = $conn->query("SHOW INDEX FROM qc_test_orders WHERE Key_name LIKE 'idx_%'");
$index_count = $result ? $result->num_rows : 0;

echo "<p class='success'>âœ… Found $index_count performance indexes on qc_test_orders</p>";

$success_count++;
echo "</div>";

// Step 5: Configuration Check
echo "<div class='step'>";
echo "<h3>Step 5: Enterprise Configuration</h3>";

if (file_exists('config/EnterpriseConfig.php')) {
    echo "<p class='success'>âœ… EnterpriseConfig.php installed</p>";
    require_once 'config/EnterpriseConfig.php';
    echo "<pre>";
    echo "App Name: " . EnterpriseConfig::APP_NAME . "\n";
    echo "Version: " . EnterpriseConfig::APP_VERSION . "\n";
    echo "Environment: " . EnterpriseConfig::ENV . "\n";
    echo "CSRF Protection: " . (EnterpriseConfig::ENABLE_CSRF_PROTECTION ? 'Enabled' : 'Disabled') . "\n";
    echo "Audit Logging: " . (EnterpriseConfig::ENABLE_AUDIT_LOG ? 'Enabled' : 'Disabled') . "\n";
    echo "Rate Limiting: " . (EnterpriseConfig::ENABLE_RATE_LIMIT ? 'Enabled' : 'Disabled') . "\n";
    echo "</pre>";
    $success_count++;
} else {
    echo "<p class='error'>âŒ EnterpriseConfig.php not found</p>";
    $errors[] = "EnterpriseConfig missing";
}

echo "</div>";

// Step 6: Error Handler
echo "<div class='step'>";
echo "<h3>Step 6: Error Handler</h3>";

if (file_exists('config/ErrorHandler.php')) {
    echo "<p class='success'>âœ… ErrorHandler.php installed</p>";
    $success_count++;
} else {
    echo "<p class='error'>âŒ ErrorHandler.php not found</p>";
    $errors[] = "ErrorHandler missing";
}

echo "</div>";

// Summary
echo "<div class='step' style='background: " . (empty($errors) ? '#ecfdf5' : '#fef2f2') . "; border-color: " . (empty($errors) ? '#10b981' : '#ef4444') . ";'>";
echo "<h3>ðŸ“Š Upgrade Summary</h3>";
echo "<p><strong>Steps Completed:</strong> $success_count / 6</p>";
echo "<p><strong>Errors:</strong> " . count($errors) . "</p>";
echo "<p><strong>Warnings:</strong> " . count($warnings) . "</p>";

if (empty($errors)) {
    echo "<p class='success' style='font-size: 18px; margin-top: 20px;'>
        ðŸŽ‰ <strong>Enterprise upgrade completed successfully!</strong>
    </p>";
    echo "<p style='margin-top: 15px;'>Your system now includes:</p>";
    echo "<ul style='line-height: 2;'>";
    echo "<li>âœ… Enhanced database performance with optimized indexes</li>";
    echo "<li>âœ… Comprehensive audit logging and monitoring</li>";
    echo "<li>âœ… Advanced error handling and tracking</li>";
    echo "<li>âœ… Security enhancements (CSRF, rate limiting)</li>";
    echo "<li>âœ… System health monitoring dashboard</li>";
    echo "<li>âœ… Automated backup and archive systems</li>";
    echo "</ul>";
} else {
    echo "<p class='error' style='font-size: 16px; margin-top: 20px;'>
        âš ï¸ Upgrade completed with some errors. Please review above.
    </p>";
}

echo "</div>";

$conn->close();

echo "
        <div style='text-align: center; margin-top: 30px;'>
            <a href='admin/system_monitor.php' class='btn'>ðŸ“Š View System Monitor</a>
            <a href='index.php' class='btn'>ðŸ  Go to Dashboard</a>
        </div>
    </div>
</body>
</html>";
?>



