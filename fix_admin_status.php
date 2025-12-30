<!DOCTYPE html>
<html>
<head><title>Fix Admin QC Submissions</title>
<style>body{font-family:Arial;padding:20px;background:#f5f5f5;}.box{max-width:800px;margin:0 auto;background:white;padding:30px;border-radius:8px;box-shadow:0 2px 10px rgba(0,0,0,0.1);}.success{background:#d4edda;color:#155724;padding:15px;margin:15px 0;border-radius:4px;}.btn{display:inline-block;padding:12px 24px;background:#dc3545;color:white;text-decoration:none;border-radius:4px;margin:10px 0;border:none;cursor:pointer;font-size:16px;}</style>
</head>
<body>
<div class="box">
<h2>🔧 Fix Admin QC Test Order Submissions</h2>
<?php
require_once 'config/security_config.php';
$conn = SecurityConfig::getConnection();

$sql = "UPDATE qc_test_orders qto
        INNER JOIN new_user nu ON qto.inspector_id = nu.id
        SET qto.status = 'approved',
            qto.approved_by = qto.inspector_name,
            qto.approved_at = NOW()
        WHERE LOWER(TRIM(nu.role)) IN ('admin', 'agm ops', 'agm operations')
        AND qto.status != 'approved'";

if ($conn->query($sql)) {
    $count = $conn->affected_rows;
    if ($count > 0) {
        echo "<div class='success'><h3>✅ Success!</h3><p>Updated <strong>$count</strong> admin submission(s) to 'approved' status.</p></div>";
    } else {
        echo "<div class='success'><h3>✅ Already Fixed!</h3><p>All admin submissions are already approved.</p></div>";
    }
    
    // Show recent admin submissions
    $result = $conn->query("SELECT qto.report_number, qto.status, qto.inspector_name
                           FROM qc_test_orders qto
                           INNER JOIN new_user nu ON qto.inspector_id = nu.id
                           WHERE LOWER(TRIM(nu.role)) IN ('admin', 'agm ops', 'agm operations')
                           ORDER BY qto.created_at DESC LIMIT 5");
    if ($result && $result->num_rows > 0) {
        echo "<h3>Recent Admin Submissions:</h3><table border='1' cellpadding='8' style='border-collapse:collapse; width:100%;'>";
        echo "<tr style='background:#f0f0f0;'><th>Report Number</th><th>Inspector</th><th>Status</th></tr>";
        while ($row = $result->fetch_assoc()) {
            $color = ($row['status'] === 'approved') ? 'green' : 'red';
            echo "<tr><td>{$row['report_number']}</td><td>{$row['inspector_name']}</td><td style='color:$color; font-weight:bold;'>{$row['status']}</td></tr>";
        }
        echo "</table>";
    }
} else {
    echo "<p style='color:red;'>Error: {$conn->error}</p>";
}

$conn->close();
?>
<hr>
<p><a href="forms/qc_test_order.php" class="btn" style="background:#28a745;">Go to QC Test Order Page</a></p>
</div>
</body>
</html>


