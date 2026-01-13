<?php
// Header specifically designed for iframe content (no hamburger menu)
// This is for forms that open within the main dashboard iframe

// Get user role for menu
$userRole = $_SESSION['role'] ?? 'admin';

// Menu items (same as in index.php)
$menuItems = [
    'admin' => [
        ['type' => 'link', 'text' => 'Dashboard Overview', 'link' => 'admin/management_kpi_dashboard.php', 'icon' => 'fas fa-th-large'],
        ['type' => 'group', 'text' => 'Admin Panel', 'icon' => 'fas fa-user-shield', 'children' => [
            ['text' => 'Add User', 'link' => 'user_create_new_user.php', 'icon' => 'fas fa-user-plus'],
            ['text' => 'User Management', 'link' => 'user_management_new_user.php', 'icon' => 'fas fa-users'],
            ['text' => 'Security Dashboard', 'link' => 'admin/security_dashboard.php', 'icon' => 'fas fa-shield-alt'],
            ['text' => 'System Data View', 'link' => 'admin/system_data_view.php', 'icon' => 'fas fa-eye'],
            ['text' => 'Export All Data', 'link' => 'admin/export_all_data.php', 'icon' => 'fas fa-download'],
        ]],
        ['type' => 'group', 'text' => 'Sheet Production ', 'icon' => 'fas fa-industry', 'children' => [
            ['text' => 'Fiber Test Report', 'link' => 'forms/fiber_test_report.php', 'icon' => 'fas fa-dna'],
            ['text' => 'Sewing Thread Report', 'link' => 'forms/sewing_thread_report.php', 'icon' => 'fas fa-scroll'],
            ['text' => 'Fiber Entry', 'link' => 'forms/fiber_entry.php', 'icon' => 'fas fa-boxes'],
            ['text' => 'Fiber to Roll Entry', 'link' => 'forms/fiber_to_roll_entry.php', 'icon' => 'fas fa-exchange-alt'],
            ['text' => 'Roll Entry', 'link' => 'forms/roll_entry.php', 'icon' => 'fas fa-dolly-flatbed'],
            ['text' => 'Roll Internal Transfer Entry', 'link' => 'forms/roll_transfer_entry.php', 'icon' => 'fas fa-truck-moving'],
            ['text' => 'Fiber to Roll Conversion Report', 'link' => 'reports/fiber_to_roll_conversion.php', 'icon' => 'fas fa-chart-line'],
            ['text' => 'Roll Production Summary', 'link' => 'reports/roll_production_summary.php', 'icon' => 'fas fa-chart-bar'],
            ['text' => 'Roll Production Hourly', 'link' => 'reports/roll_production_hourly.php', 'icon' => 'fas fa-clock'],
            ['text' => 'Roll Production Daily', 'link' => 'reports/roll_production_daily.php', 'icon' => 'fas fa-calendar-day'],
            ['text' => 'Roll Transfer Log', 'link' => 'reports/roll_transfer_log.php', 'icon' => 'fas fa-truck'],
        ]],
        ['type' => 'group', 'text' => 'Bag Production ', 'icon' => 'fas fa-cogs', 'children' => [
            ['text' => 'Roll Received Entry', 'link' => 'forms/roll_received_entry.php', 'icon' => 'fas fa-clipboard-check'],
            ['text' => 'CNC Machine Entry', 'link' => 'forms/cnc_machine_entry.php', 'icon' => 'fas fa-cut'],
            ['text' => 'Sewing Machine Entry', 'link' => 'forms/swing_machine_entry.php', 'icon' => 'fas fa-thread'],
            ['text' => 'Branding Entry', 'link' => 'forms/branding_entry.php', 'icon' => 'fas fa-stamp'],
            ['text' => 'CNC Cutting Summary', 'link' => 'reports/cnc_cutting_summary.php', 'icon' => 'fas fa-cut'],
            ['text' => 'Sewing Output Report', 'link' => 'reports/sewing_output.php', 'icon' => 'fas fa-thread'],
            ['text' => 'Branding Summary Report', 'link' => 'reports/branding_summary.php', 'icon' => 'fas fa-stamp'],
        ]],
        ['type' => 'group', 'text' => 'Scrap/Waste ', 'icon' => 'fas fa-recycle', 'children' => [
            ['text' => 'Scrap Entry', 'link' => 'forms/scrap_entry.php', 'icon' => 'fas fa-trash'],
            ['text' => 'Scrap Analysis', 'link' => 'reports_scrap_analysis.php', 'icon' => 'fas fa-chart-pie'],
            ['text' => 'Scrap Summary by Type', 'link' => 'reports/scrap_summary_type.php', 'icon' => 'fas fa-chart-pie'],
            ['text' => 'Scrap Per Batch', 'link' => 'reports/scrap_per_batch.php', 'icon' => 'fas fa-boxes'],
            ['text' => 'Scrap vs Recycle Ratio', 'link' => 'reports/scrap_recycle_ratio.php', 'icon' => 'fas fa-balance-scale'],
        ]],
        ['type' => 'group', 'text' => 'Quality Control(QC) ', 'icon' => 'fas fa-clipboard-check', 'children' => [
            ['text' => 'QC Inspections', 'link' => 'qc_inspections.php', 'icon' => 'fas fa-search'],
            ['text' => 'QC Reporting', 'link' => 'qc_reporting.php', 'icon' => 'fas fa-file-alt'],
            ['text' => 'QC Pass/Fail Trend', 'link' => 'reports/qc_pass_fail_trend.php', 'icon' => 'fas fa-chart-line'],
        ]],
        ['type' => 'group', 'text' => 'Finished Goods(FG)', 'icon' => 'fas fa-box', 'children' => [
            ['text' => 'FG Entry', 'link' => 'forms/fg_entry.php', 'icon' => 'fas fa-plus-square'],
            ['text' => 'FG Delivery', 'link' => 'forms/fg_delivery_entry.php', 'icon' => 'fas fa-truck'],
            ['text' => 'FG Stock Management', 'link' => 'fg_stock_management.php', 'icon' => 'fas fa-warehouse'],
            ['text' => 'FG Stock Summary', 'link' => 'reports/fg_stock_summary.php', 'icon' => 'fas fa-warehouse'],
            ['text' => 'FG Delivery Report', 'link' => 'reports/fg_delivery_report.php', 'icon' => 'fas fa-truck'],
            ['text' => 'FG Batch Report', 'link' => 'reports/fg_batch_report.php', 'icon' => 'fas fa-boxes'],
        ]],
        ['type' => 'group', 'text' => 'Recycle ', 'icon' => 'fas fa-recycle', 'children' => [
            ['text' => 'Recycle Entry', 'link' => 'forms/scrap_recycle_entry.php', 'icon' => 'fas fa-recycle'],
            ['text' => 'Recycle Tracking', 'link' => 'reports_recycle_tracking.php', 'icon' => 'fas fa-chart-line'],
            ['text' => 'Recycled Material Summary', 'link' => 'reports/recycled_material_summary.php', 'icon' => 'fas fa-recycle'],
            ['text' => 'Recycle vs Scrap Chart', 'link' => 'reports/recycle_vs_scrap_chart.php', 'icon' => 'fas fa-chart-pie'],
        ]],
        ['type' => 'group', 'text' => 'Planning', 'icon' => 'fas fa-bullseye', 'children' => [
            ['text' => 'Target Setting', 'link' => 'forms/target_entry.php', 'icon' => 'fas fa-target'],
            ['text' => 'Project Planning', 'link' => 'forms/project_entry.php', 'icon' => 'fas fa-project-diagram'],
            ['text' => 'BOM Entry', 'link' => 'forms/BOM_entry.php', 'icon' => 'fas fa-list'],
            ['text' => 'Target vs Actual Production', 'link' => 'reports/target_vs_actual.php', 'icon' => 'fas fa-chart-line'],
            ['text' => 'Project Value Report', 'link' => 'reports/project_value_report.php', 'icon' => 'fas fa-dollar-sign'],
            ['text' => 'BOM Entry Log', 'link' => 'reports/bom_entry_log.php', 'icon' => 'fas fa-list'],
        ]],
        ['type' => 'group', 'text' => 'Finance and Analytics', 'icon' => 'fas fa-dollar-sign', 'children' => [
            ['text' => 'Comprehensive Analytics Dashboard', 'link' => 'reports/comprehensive_analytics_dashboard.php', 'icon' => 'fas fa-chart-line'],
            ['text' => 'BOM Management', 'link' => 'bom_management.php', 'icon' => 'fas fa-list-alt'],
            ['text' => 'Costing', 'link' => 'costing.php', 'icon' => 'fas fa-calculator'],
            ['text' => 'Financial Reports', 'link' => 'reports_financial.php', 'icon' => 'fas fa-file-invoice-dollar'],
            ['text' => 'Cost Analysis Report', 'link' => 'reports/cost_analysis.php', 'icon' => 'fas fa-balance-scale'],
            ['text' => 'Profit/Loss Statement', 'link' => 'reports/profit_loss.php', 'icon' => 'fas fa-chart-line'],
            ['text' => 'Material Usage Cost', 'link' => 'reports/material_usage_cost.php', 'icon' => 'fas fa-calculator'],
        ]],
        
    ],
    
    'production' => [
        ['type' => 'link', 'text' => 'Dashboard Overview', 'link' => 'admin/management_kpi_dashboard.php', 'icon' => 'fas fa-th-large'],
        ['type' => 'group', 'text' => 'Roll Production', 'icon' => 'fas fa-cogs', 'children' => [
            ['text' => 'Fiber Entry', 'link' => 'fiber_entry.php', 'icon' => 'fas fa-boxes'],
            ['text' => 'Roll Entry', 'link' => 'roll_entry.php', 'icon' => 'fas fa-dolly-flatbed'],
            ['text' => 'CNC Entry', 'link' => 'cnc_entry.php', 'icon' => 'fas fa-cut'],
        ]],
        ['type' => 'link', 'text' => 'Reports', 'link' => 'reports_production.php', 'icon' => 'fas fa-file-alt'],
    ],
    
    'user' => [
        ['type' => 'link', 'text' => 'Dashboard Overview', 'link' => 'admin/management_kpi_dashboard.php', 'icon' => 'fas fa-th-large'],
        ['type' => 'link', 'text' => 'Quick Access', 'link' => 'quick_access.php', 'icon' => 'fas fa-rocket'],
    ],
];

$displayMenuItems = $menuItems[$userRole] ?? $menuItems['user'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle : 'Form'; ?> - GEOCIL Automation</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .form-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }
        
        .form-container h1 {
            text-align: center;
            margin-bottom: 30px;
            color: #2c3e50;
            font-size: 2em;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #2c3e50;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid #e1e8ed;
            border-radius: 10px;
            font-size: 1em;
            transition: all 0.3s ease;
            box-sizing: border-box;
            background: #f8f9fa;
            font-family: 'Inter', sans-serif;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3498db;
            background: white;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
            transform: translateY(-1px);
        }
        
        .form-group input:hover,
        .form-group select:hover,
        .form-group textarea:hover {
            border-color: #bdc3c7;
            background: white;
        }
        
        .form-group input[readonly] {
            background: #ecf0f1;
            color: #7f8c8d;
            cursor: not-allowed;
        }
        
        .btn {
            padding: 15px 30px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1.1em;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            font-family: 'Inter', sans-serif;
            position: relative;
            overflow: hidden;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(52, 152, 219, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #2ecc71, #27ae60);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(46, 204, 113, 0.3);
        }
        
        .btn i {
            margin-right: 8px;
        }
        
        /* Back button */
        .back-btn {
            background: #95a5a6;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        
        .back-btn:hover {
            background: #7f8c8d;
            transform: translateY(-1px);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .form-container {
                margin: 0 10px;
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="form-container">
        <a href="../admin/management_kpi_dashboard.php" class="back-btn" target="main">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

