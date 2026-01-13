<?php
// Common header for all form pages to maintain consistent layout
// Note: session_start() should be called by the including file, not here

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
        ['type' => 'group', 'text' => 'Raw Material Store', 'icon' => 'fas fa-warehouse', 'children' => [
            ['text' => '1. Store Received Entry', 'link' => 'forms/store_received_entry.php', 'icon' => 'fas fa-truck-loading'],
            ['text' => '2. Fineness of Fiber Report (ISO 1973)', 'link' => 'forms/fineness_fiber_report.php', 'icon' => 'fas fa-weight'],
            ['text' => '3. Cut Length of Fiber Report (ASTM D5103)', 'link' => 'forms/cut_length_fiber_report.php', 'icon' => 'fas fa-ruler'],
            ['text' => '4. Tenacity of Fiber Report (EN ISO 5079)', 'link' => 'forms/tenacity_fiber_report.php', 'icon' => 'fas fa-flask'],
            ['text' => '5. Tenacity of Yarn Report (ASTM D2256)', 'link' => 'forms/tenacity_yarn_report.php', 'icon' => 'fas fa-flask'],
            ['text' => '6. Fiber Test Report', 'link' => 'forms/fiber_test_report.php', 'icon' => 'fas fa-dna'],
            ['text' => '7. Sewing Thread Report', 'link' => 'forms/sewing_thread_report.php', 'icon' => 'fas fa-scroll'],
            ['text' => '8. Test Approval Dashboard', 'link' => 'admin/raw_material_test_approval_dashboard.php', 'icon' => 'fas fa-clipboard-check'],
            ['text' => '9. Approved Material Inventory', 'link' => 'reports/approved_material_inventory.php', 'icon' => 'fas fa-check-square'],
            ['text' => '10. Inventory Report', 'link' => 'reports/inventory_report.php', 'icon' => 'fas fa-warehouse'],
        ]],
        ['type' => 'group', 'text' => 'Quality Control(QC) ', 'icon' => 'fas fa-clipboard-check', 'children' => [
            ['text' => 'QC Inspections', 'link' => 'qc_inspections.php', 'icon' => 'fas fa-search'],
            ['text' => 'QC Reporting', 'link' => 'qc_reporting.php', 'icon' => 'fas fa-file-alt'],
            ['text' => 'QC Pass/Fail Trend', 'link' => 'reports/qc_pass_fail_trend.php', 'icon' => 'fas fa-chart-line'],
        ]],
        ['type' => 'group', 'text' => 'Sheet Production ', 'icon' => 'fas fa-industry', 'children' => [
            ['text' => 'Fiber Test Report', 'link' => 'forms/fiber_test_report.php', 'icon' => 'fas fa-dna'],
            ['text' => 'Sewing Thread Report', 'link' => 'forms/sewing_thread_report.php', 'icon' => 'fas fa-scroll'],
            ['text' => 'Fiber Pre-Testing', 'link' => 'forms/fiber_pretesting.php', 'icon' => 'fas fa-vial'],
            ['text' => 'Fiber Received Entry', 'link' => 'forms/fiber_entry.php', 'icon' => 'fas fa-boxes'],
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
        ['type' => 'group', 'text' => 'Planning ', 'icon' => 'fas fa-bullseye', 'children' => [
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
            ['text' => 'View Data', 'link' => 'view_data/finance_view.php', 'icon' => 'fas fa-eye'],
            ['text' => 'Export Data', 'link' => 'export_data/finance_export.php', 'icon' => 'fas fa-download'],
            ['text' => 'Cost Analysis Report', 'link' => 'reports/cost_analysis.php', 'icon' => 'fas fa-balance-scale'],
            ['text' => 'Profit/Loss Statement', 'link' => 'reports/profit_loss.php', 'icon' => 'fas fa-chart-line'],
            ['text' => 'Material Usage Cost', 'link' => 'reports/material_usage_cost.php', 'icon' => 'fas fa-calculator'],
            ['text' => 'Roll Production Price Report', 'link' => 'reports/roll_production_price_report.php', 'icon' => 'fas fa-dolly-flatbed'],
        ]],
    ],
    
    'production' => [
        ['type' => 'link', 'text' => 'Dashboard Overview', 'link' => 'admin/management_kpi_dashboard.php', 'icon' => 'fas fa-th-large'],
        ['type' => 'group', 'text' => 'Roll Production', 'icon' => 'fas fa-cogs', 'children' => [
            ['text' => 'Fiber Pre-Testing', 'link' => 'forms/fiber_pretesting.php', 'icon' => 'fas fa-vial'],
            ['text' => 'Fiber Received Entry', 'link' => 'forms/fiber_entry.php', 'icon' => 'fas fa-boxes'],
            ['text' => 'Roll Entry', 'link' => 'forms/roll_entry.php', 'icon' => 'fas fa-dolly-flatbed'],
            ['text' => 'CNC Entry', 'link' => 'forms/cnc_entry.php', 'icon' => 'fas fa-cut'],
        ]],
        
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
        }
        
        /* Topbar */
        .topbar {
            background: linear-gradient(135deg, #1a1f2e 0%, #2d3548 100%);
            padding: 0 20px;
            height: 65px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        
        .topbar::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, #00d4aa, #00b4d8, #0077b6);
            opacity: 0.8;
        }
        
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .topbar-logo {
            height: 38px;
            width: auto;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }
        
        .topbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .topbar-brand-text {
            display: flex;
            flex-direction: column;
            line-height: 1.2;
        }
        
        .topbar-brand-text strong {
            font-size: 1.15em;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: 0.5px;
        }
        
        .topbar-brand-text small {
            font-size: 0.7em;
            color: #00d4aa;
            font-weight: 500;
            letter-spacing: 1.5px;
            text-transform: uppercase;
        }
        
        .menu-toggle {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #e0e6ed;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1em;
            transition: all 0.25s ease;
        }
        
        .menu-toggle:hover {
            background: rgba(0, 212, 170, 0.15);
            border-color: rgba(0, 212, 170, 0.4);
            color: #00d4aa;
            transform: scale(1.05);
        }
        
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-info {
            color: #b8c5d6;
            font-weight: 600;
        }
        
        .user-info i {
            color: #00d4aa;
        }
        
        .logout-btn {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 10px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.25s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .logout-btn:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }
        
        /* Sidebar */
        .sidebar {
            width: 260px;
            background: linear-gradient(180deg, #1a1f2e 0%, #232b3d 100%);
            color: #e0e6ed;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            transform: translateX(-100%);
            transition: transform 0.35s ease-in-out;
            z-index: 1001;
            overflow-y: auto;
            padding: 0;
            border-right: 1px solid rgba(255, 255, 255, 0.06);
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.15);
        }
        
        .sidebar.show {
            transform: translateX(0);
        }
        
        .sidebar-header {
            padding: 20px 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.06);
            background: rgba(0, 0, 0, 0.15);
            position: relative;
        }
        
        .sidebar-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 20px;
            right: 20px;
            height: 2px;
            background: linear-gradient(90deg, transparent, #00d4aa, transparent);
            opacity: 0.6;
        }
        
        .sidebar-logo {
            height: 45px;
            width: auto;
            border-radius: 8px;
            object-fit: contain;
        }
        
        .sidebar h3 {
            text-align: center;
            margin: 0;
            font-size: 1.4em;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .sidebar h3 i {
            color: #00d4aa;
            font-size: 0.9em;
        }
        
        .sidebar-content {
            padding: 15px 10px;
        }
        
        .sidebar a {
            color: #b8c5d6;
            text-decoration: none;
            padding: 12px 14px;
            display: flex;
            align-items: center;
            border-radius: 10px;
            transition: all 0.25s ease;
            margin-bottom: 4px;
            font-size: 0.88em;
            font-weight: 500;
        }
        
        .sidebar a i {
            margin-right: 12px;
            font-size: 1em;
            color: #00d4aa;
            width: 20px;
            text-align: center;
        }
        
        .sidebar a:hover {
            background: rgba(0, 212, 170, 0.1);
            color: #00d4aa;
            transform: translateX(4px);
        }
        
        /* Menu Group Styling */
        .menu-group {
            margin-bottom: 8px;
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .group-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            cursor: pointer;
            border-radius: 10px;
            transition: all 0.25s ease;
            color: #e0e6ed;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid transparent;
        }
        
        .group-header:hover {
            background: rgba(0, 212, 170, 0.08);
            border-color: rgba(0, 212, 170, 0.2);
            color: #00d4aa;
        }
        
        .group-header.active {
            background: rgba(0, 212, 170, 0.12);
            border-color: rgba(0, 212, 170, 0.3);
            color: #00d4aa;
        }
        
        .group-header i {
            color: #00d4aa;
        }
        
        .toggle-icon {
            transition: transform 0.3s ease;
            color: #6b7a8f;
        }
        
        .group-header:hover .toggle-icon,
        .group-header.active .toggle-icon {
            color: #00d4aa;
        }
        
        .group-header.active .toggle-icon {
            transform: rotate(180deg);
        }
        
        .sub-menu {
            display: none;
            background: rgba(0, 0, 0, 0.15);
            padding-left: 10px;
            border-left: 2px solid rgba(0, 212, 170, 0.3);
            margin-left: 5px;
            border-radius: 0 0 8px 8px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .sub-menu.show {
            display: block !important;
        }
        
        .sub-menu a {
            padding: 10px 15px;
            font-size: 0.85em;
            color: #8896a8;
            border-radius: 6px;
            margin-right: 8px;
        }
        
        .sub-menu a i {
            color: #6b7a8f;
        }
        
        .sub-menu a:hover {
            background: rgba(0, 212, 170, 0.1);
            color: #00d4aa;
        }
        
        .sub-menu a:hover i {
            color: #00d4aa;
        }
        
        /* Overlay */
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
            transition: opacity 0.35s ease;
            opacity: 0;
        }
        
        .overlay.active {
            display: block;
            opacity: 1;
        }
        
        /* Main Content */
        .main-content {
            margin-top: 80px;
            padding: 20px;
            min-height: calc(100vh - 80px);
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
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 280px;
            }
            
            .form-container {
                margin: 0 10px;
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <button class="menu-toggle" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            <div class="topbar-brand">
                <img src="../assets/images/logo.jpg" alt="GEOCIL Logo" class="topbar-logo">
                <div class="topbar-brand-text">
                    <strong>GEOCIL</strong>
                    <small>Automation</small>
                </div>
            </div>
        </div>
        <div class="topbar-right">
            <span class="user-info">
                <i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['username']); ?>
            </span>
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3>GEO</h3>
        </div>
        <div class="sidebar-content">
            <?php foreach ($displayMenuItems as $item): ?>
                <?php if ($item['type'] === 'link'): ?>
                    <a href="<?php echo htmlspecialchars($item['link']); ?>" class="nav-link">
                        <i class="<?php echo htmlspecialchars($item['icon']); ?>"></i> <?php echo htmlspecialchars($item['text']); ?>
                    </a>
                <?php elseif ($item['type'] === 'group'): ?>
                    <div class="menu-group">
                        <div class="group-header" onclick="toggleGroup(this)">
                            <div>
                                <i class="<?php echo htmlspecialchars($item['icon']); ?>"></i> 
                                <span><?php echo htmlspecialchars($item['text']); ?></span>
                            </div>
                            <span class="toggle-icon fas fa-chevron-down"></span>
                        </div>
                        <div class="sub-menu">
                            <?php foreach ($item['children'] as $subItem): ?>
                                <a href="<?php echo htmlspecialchars($subItem['link']); ?>" class="nav-link">
                                    <i class="<?php echo htmlspecialchars($subItem['icon']); ?>"></i> <?php echo htmlspecialchars($subItem['text']); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Overlay -->
    <div class="overlay" id="sidebar-overlay"></div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="form-container">

