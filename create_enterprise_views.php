<?php
require_once 'config/security_config.php';

echo "<!DOCTYPE html>
<html>
<head>
    <title>Create Enterprise Views</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; background: #f5f7fa; }
        .container { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #667eea; border-bottom: 3px solid #667eea; padding-bottom: 10px; }
        .success { color: #10b981; font-weight: bold; }
        .error { color: #ef4444; font-weight: bold; }
        .step { padding: 15px; margin: 10px 0; background: #f9fafb; border-left: 4px solid #667eea; border-radius: 4px; }
        .btn { display: inline-block; padding: 12px 24px; background: #667eea; color: white; text-decoration: none; border-radius: 6px; margin: 10px 5px; }
    </style>
</head>
<body>
    <div class='container'>
        <h1>ðŸ“Š Creating Enterprise Views</h1>";

$conn = SecurityConfig::getConnection();

// View 1: Pending Checker Reports
echo "<div class='step'>";
$sql = "CREATE OR REPLACE VIEW v_pending_checker_reports AS
SELECT 
    qto.id,
    qto.report_number,
    qto.status,
    qto.inspector_name,
    qto.created_at,
    qto.updated_at,
    ts.test_name,
    qto.chosen_method
FROM qc_test_orders qto
LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
WHERE qto.status = 'pending_checker'
ORDER BY qto.updated_at DESC";

if ($conn->query($sql)) {
    echo "<span class='success'>âœ… Created view: v_pending_checker_reports</span>";
} else {
    echo "<span class='error'>âŒ Failed: v_pending_checker_reports - " . $conn->error . "</span>";
}
echo "</div>";

// View 2: User Activity Summary
echo "<div class='step'>";
$sql = "CREATE OR REPLACE VIEW v_user_activity_summary AS
SELECT 
    u.id,
    u.username,
    u.full_name,
    u.role,
    COUNT(DISTINCT al.id) as total_actions,
    u.last_login,
    u.last_activity
FROM new_user u
LEFT JOIN audit_log al ON u.id = al.user_id AND al.timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY)
WHERE u.is_active = 1
GROUP BY u.id, u.username, u.full_name, u.role, u.last_login, u.last_activity";

if ($conn->query($sql)) {
    echo "<span class='success'>âœ… Created view: v_user_activity_summary</span>";
} else {
    echo "<span class='error'>âŒ Failed: v_user_activity_summary - " . $conn->error . "</span>";
}
echo "</div>";

// Verify views exist
echo "<div class='step'><h3>ðŸ” Verifying Views</h3>";

$views = ['v_pending_checker_reports', 'v_user_activity_summary'];
$all_exist = true;

foreach ($views as $view) {
    $result = $conn->query("SHOW FULL TABLES WHERE Table_Type = 'VIEW' AND Tables_in_geobagg = '$view'");
    if ($result && $result->num_rows > 0) {
        echo "<p class='success'>âœ… $view</p>";
    } else {
        echo "<p class='error'>âŒ $view</p>";
        $all_exist = false;
    }
}

echo "</div>";

if ($all_exist) {
    echo "<div class='step' style='background: #ecfdf5; border-color: #10b981;'>
            <h2 class='success'>ðŸŽ‰ All Enterprise Views Created!</h2>
            <p>Your enterprise upgrade is now complete.</p>
          </div>";
} else {
    echo "<div class='step' style='background: #fef2f2; border-color: #ef4444;'>
            <h3 class='error'>âš ï¸ Some views failed</h3>
            <p>Check the errors above.</p>
          </div>";
}

$conn->close();

echo "
        <div style='text-align: center; margin-top: 30px;'>
            <a href='run_enterprise_upgrade.php' class='btn'>ðŸ”„ Final Verification</a>
            <a href='admin/system_monitor.php' class='btn'>ðŸ“Š System Monitor</a>
            <a href='index.php' class='btn'>ðŸ  Dashboard</a>
        </div>
    </div>
</body>
</html>";
?>



