<?php
/**
 * Enable Performance Monitoring
 * This script shows you how to add performance monitoring to your pages
 */

require_once 'config/security_config.php';
$conn = SecurityConfig::getConnection();

?>
<!DOCTYPE html>
<html>
<head>
    <title>Enable Performance Monitoring</title>
    <style>
        body { font-family: Arial; padding: 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 40px; border-radius: 10px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        h1 { color: #667eea; margin-top: 0; }
        .success { background: #d4edda; color: #155724; padding: 15px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #28a745; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; margin: 20px 0; border-left: 4px solid #17a2b8; }
        .warning { background: #fff3cd; color: #856404; padding: 15px; margin: 20px 0; border-left: 4px solid #ffc107; }
        pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
        .btn { background: #667eea; color: white; padding: 12px 24px; border: none; border-radius: 5px; text-decoration: none; display: inline-block; margin: 5px; }
        .btn:hover { background: #5568d3; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Performance Monitoring Setup</h1>
        
        <div class="info">
            <h3>✅ Performance Monitor Created!</h3>
            <p>The PerformanceMonitor class is ready to use. Follow the steps below to enable it.</p>
        </div>
        
        <h2>📋 How to Use:</h2>
        
        <div class="warning">
            <h3>Step 1: Add to Any Page</h3>
            <p>Add these two lines to track performance:</p>
            <pre>
&lt;?php
// At the very top of the page (after session_start)
require_once 'config/PerformanceMonitor.php';
PerformanceMonitor::start();

// ... your page content ...

// At the very bottom (before closing ?&gt;)
PerformanceMonitor::end('page_name_here');
?&gt;</pre>
        </div>
        
        <div class="info">
            <h3>Step 2: Example Implementation</h3>
            <p>Here's how to add it to the QC Reports Dashboard:</p>
            <pre>
&lt;?php
session_start();
require_once '../config/PerformanceMonitor.php';
PerformanceMonitor::start(); // ⭐ START MONITORING

require_once '../config/security_config.php';
// ... rest of your code ...

// At the end, before &lt;/body&gt;
&lt;?php 
PerformanceMonitor::end('qc_reports_dashboard'); // ⭐ END MONITORING
?&gt;
&lt;/body&gt;
&lt;/html&gt;</pre>
        </div>
        
        <div class="success">
            <h3>Step 3: View Performance Dashboard</h3>
            <p>Access the Performance Dashboard to see metrics:</p>
            <p><a href="admin/performance_dashboard.php" class="btn">📊 Open Performance Dashboard</a></p>
        </div>
        
        <h2>🎯 Key Pages to Monitor:</h2>
        
        <div class="info">
            <h4>High Priority (Monitor These First):</h4>
            <ul>
                <li><code>admin/qc_reports_dashboard.php</code> - QC Reports Dashboard</li>
                <li><code>admin/lab_testing_dashboard.php</code> - Lab Testing Dashboard</li>
                <li><code>admin/management_kpi_dashboard.php</code> - Management KPI Dashboard</li>
                <li><code>forms/qc_test_order.php</code> - QC Test Order Form</li>
                <li><code>admin/view_qc_test_order.php</code> - View QC Test Order</li>
            </ul>
            
            <h4>Medium Priority:</h4>
            <ul>
                <li><code>index.php</code> - Main Dashboard</li>
                <li><code>tester_rejected_reports.php</code> - Rejected Reports</li>
                <li><code>reports/*.php</code> - All report pages</li>
            </ul>
        </div>
        
        <h2>📊 What You'll See:</h2>
        
        <div class="info">
            <p>The Performance Dashboard shows:</p>
            <ul>
                <li>✅ <strong>Average response time</strong> for each page</li>
                <li>✅ <strong>Slowest pages</strong> that need optimization</li>
                <li>✅ <strong>Memory usage</strong> per page</li>
                <li>✅ <strong>Query count</strong> per page</li>
                <li>✅ <strong>Performance timeline</strong> (daily trends)</li>
                <li>✅ <strong>Optimization recommendations</strong></li>
            </ul>
        </div>
        
        <h2>🎨 Optional: Development Badge</h2>
        
        <div class="warning">
            <p>During development, you can see real-time performance on each page:</p>
            <pre>
&lt;?php
// At the end of your page, before &lt;/body&gt;
PerformanceMonitor::displayBadge();
?&gt;</pre>
            <p>This shows a small badge at bottom-right with:</p>
            <p style="background:#28a745; color:white; padding:8px 15px; border-radius:20px; display:inline-block;">
                ⏱️ 0.45s | 💾 12.5 MB | 🔍 8 queries
            </p>
            <p><small>Color changes: Green (fast), Yellow (medium), Red (slow)</small></p>
        </div>
        
        <h2>⚙️ Configuration:</h2>
        
        <div class="info">
            <p><strong>Enable/Disable Monitoring:</strong></p>
            <pre>
&lt;?php
// Disable in production if you want
PerformanceMonitor::setEnabled(false);
?&gt;</pre>
            
            <p><strong>Get Current Metrics (for debugging):</strong></p>
            <pre>
&lt;?php
$metrics = PerformanceMonitor::getCurrentMetrics();
print_r($metrics);
?&gt;</pre>
        </div>
        
        <h2>📈 Performance Metrics Explained:</h2>
        
        <table style="width:100%; border-collapse:collapse; margin:20px 0;">
            <tr style="background:#f8f9fa;">
                <th style="padding:10px; border:1px solid #ddd;">Response Time</th>
                <th style="padding:10px; border:1px solid #ddd;">Level</th>
                <th style="padding:10px; border:1px solid #ddd;">Status</th>
                <th style="padding:10px; border:1px solid #ddd;">Action Needed</th>
            </tr>
            <tr>
                <td style="padding:10px; border:1px solid #ddd;">< 0.5s</td>
                <td style="padding:10px; border:1px solid #ddd; color:#27ae60; font-weight:bold;">Excellent</td>
                <td style="padding:10px; border:1px solid #ddd;">✅ Perfect</td>
                <td style="padding:10px; border:1px solid #ddd;">None</td>
            </tr>
            <tr style="background:#f8f9fa;">
                <td style="padding:10px; border:1px solid #ddd;">0.5-1s</td>
                <td style="padding:10px; border:1px solid #ddd; color:#2ecc71; font-weight:bold;">Good</td>
                <td style="padding:10px; border:1px solid #ddd;">✅ Acceptable</td>
                <td style="padding:10px; border:1px solid #ddd;">None</td>
            </tr>
            <tr>
                <td style="padding:10px; border:1px solid #ddd;">1-2s</td>
                <td style="padding:10px; border:1px solid #ddd; color:#f39c12; font-weight:bold;">Fair</td>
                <td style="padding:10px; border:1px solid #ddd;">⚠️ Could improve</td>
                <td style="padding:10px; border:1px solid #ddd;">Consider optimization</td>
            </tr>
            <tr style="background:#f8f9fa;">
                <td style="padding:10px; border:1px solid #ddd;">2-5s</td>
                <td style="padding:10px; border:1px solid #ddd; color:#e74c3c; font-weight:bold;">Poor</td>
                <td style="padding:10px; border:1px solid #ddd;">❌ Too slow</td>
                <td style="padding:10px; border:1px solid #ddd;">Optimize now!</td>
            </tr>
            <tr>
                <td style="padding:10px; border:1px solid #ddd;">> 5s</td>
                <td style="padding:10px; border:1px solid #ddd; color:#c0392b; font-weight:bold;">Critical</td>
                <td style="padding:10px; border:1px solid #ddd;">🔴 Urgent!</td>
                <td style="padding:10px; border:1px solid #ddd;">Fix immediately!</td>
            </tr>
        </table>
        
        <div class="success">
            <h3>🎉 Ready to Use!</h3>
            <p>The Performance Monitor is now available. Start adding it to your pages to track performance!</p>
            <p><a href="admin/performance_dashboard.php" class="btn">View Performance Dashboard</a></p>
        </div>
        
        <a href="index.php" class="btn" style="background:#95a5a6;">Back to Main Dashboard</a>
    </div>
</body>
</html>


