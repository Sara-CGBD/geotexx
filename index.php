<?php
session_start();

// Optional dev auto-reload (skip if file is missing)
$devReloadPath = __DIR__ . '/dev/auto_reload.php';
if (file_exists($devReloadPath)) {
    include_once $devReloadPath;
}

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// Session security checks - Simplified for better compatibility
$session_timeout = 3600; // 1 hour
$regenerate_time = 300;  // 5 minutes

// Check if the user is logged in (consistent with other forms)
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}

// Now check session timeout (only if user is logged in)
if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $session_timeout) {
    session_unset();
    session_destroy();
    header("Location: login.html?msg=timeout");
    exit();
}

// Note: prod_user will be allowed to access index.php but will default to welcome.php instead of dashboard


// Regenerate session ID periodically to prevent session fixation (only if logged in)
if (isset($_SESSION['last_regeneration']) && (time() - $_SESSION['last_regeneration']) > $regenerate_time) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// Get the user's role from the session
$userRole = strtolower(trim($_SESSION['role'] ?? 'user')); // Default to 'user' if no role set, normalize to lowercase

// Normalize AGM Operations variations
if ($userRole === 'agm operations' || $userRole === 'agm_ops' || $userRole === 'agm_operations') {
    $userRole = 'agm ops';
}

// Normalize prod_test to prod_user (so they see welcome page, not dashboard)
if ($userRole === 'prod_test') {
    $userRole = 'prod_user';
}

$menuItems = [
        // ========== ADMIN - Full Access to All Modules ==========
        'admin' => [
            ['type' => 'link', 'text' => 'Dashboard Overview', 'link' => 'admin/dashboard_overview.php', 'icon' => 'fas fa-th-large'],
            ['type' => 'group', 'text' => 'Admin Panel', 'icon' => 'fas fa-user-shield', 'children' => [
                ['text' => 'Add User', 'link' => 'admin/user_create_new_user.php', 'icon' => 'fas fa-user-plus'],
                ['text' => 'User Management', 'link' => 'admin/user_management_new_user.php', 'icon' => 'fas fa-users'],
                ['text' => 'Role Permissions Report', 'link' => 'admin/role_permissions_report.php', 'icon' => 'fas fa-user-shield'],
                ['text' => 'Security Dashboard', 'link' => 'admin/security_dashboard.php', 'icon' => 'fas fa-shield-alt'],
                ['text' => 'Enterprise Monitor', 'link' => 'admin/system_monitor.php', 'icon' => 'fas fa-chart-line', 'badge' => 'NEW'],
                ['text' => 'Performance Dashboard', 'link' => 'admin/performance_dashboard.php', 'icon' => 'fas fa-tachometer-alt', 'badge' => 'NEW'],
                ['text' => 'Management KPI Dashboard', 'link' => 'admin/management_kpi_dashboard.php', 'icon' => 'fas fa-chart-line'],
                ['text' => 'Email Management', 'link' => 'admin/email_management.php', 'icon' => 'fas fa-envelope'],
                ['text' => 'Quick Access', 'link' => 'admin/quick_access.php', 'icon' => 'fas fa-rocket'],
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
                ['text' => '11. Material Request List', 'link' => 'reports/material_request_list.php', 'icon' => 'fas fa-file-signature'],
            ]],
            ['type' => 'group', 'text' => 'Quality Control(QC)', 'icon' => 'fas fa-clipboard-check', 'children' => [
                ['text' => 'QC Test Approval Dashboard', 'link' => 'admin/qc_test_approval_dashboard.php', 'icon' => 'fas fa-check-double'],
                ['text' => 'QC Entry Approval Dashboard', 'link' => 'admin/qc_approval_dashboard.php', 'icon' => 'fas fa-check-circle'],
                // ['text' => 'QC Reports Dashboard', 'link' => 'admin/qc_reports_dashboard.php', 'icon' => 'fas fa-tasks'],
                ['text' => 'QC Summary Report', 'link' => 'forms/qc_summary_report.php', 'icon' => 'fas fa-file-invoice'],
                ['text' => 'QC Entry', 'link' => 'forms/qc_entry.php', 'icon' => 'fas fa-clipboard-check'],
                ['text' => 'QC Test Order', 'link' => 'forms/qc_test_order.php', 'icon' => 'fas fa-list-check'],
                ['text' => 'Daily GSM Check (Floor)', 'link' => 'forms/daily_gsm_check.php', 'icon' => 'fas fa-weight'],
                ['text' => 'Length Calibration', 'link' => 'forms/length_calibration_entry.php', 'icon' => 'fas fa-ruler'],
                ['text' => 'Lab Testing Scrap Entry', 'link' => 'forms/lab_testing_scrap_entry.php', 'icon' => 'fas fa-flask'],
                ['text' => 'Water Permeability Test', 'link' => 'forms/water_permeability_test.php', 'icon' => 'fas fa-tint'],
                ['text' => 'Characteristics Test', 'link' => 'forms/characteristics_test.php', 'icon' => 'fas fa-filter'],
                ['text' => 'UV Test', 'link' => 'forms/weathering_exposure_test.php', 'icon' => 'fas fa-sun'],
                ['text' => 'Fabric Internal Production Sample Test Summary', 'link' => 'forms/fabric_after_production_test.php', 'icon' => 'fas fa-industry'],
                ['text' => 'Sun Test Report', 'link' => 'forms/sun_test_report.php', 'icon' => 'fas fa-sun'],
                ['text' => 'QC Pass/Fail Trend', 'link' => 'reports/qc_pass_fail_trend.php', 'icon' => 'fas fa-chart-line'],
            ]],
            ['type' => 'group', 'text' => 'Sheet Production', 'icon' => 'fas fa-industry', 'children' => [
                ['text' => 'Material Request Entry', 'link' => 'forms/material_request_entry.php', 'icon' => 'fas fa-file-signature'],
                ['text' => 'Fiber Received Entry', 'link' => 'forms/fiber_entry.php', 'icon' => 'fas fa-boxes'],
                ['text' => 'Fiber Input Entry', 'link' => 'forms/fiber_to_roll_entry.php', 'icon' => 'fas fa-exchange-alt'],
                ['text' => 'GSM and Roll Input', 'link' => 'forms/gsm_roll_entry.php', 'icon' => 'fas fa-ruler-combined'],
                ['text' => 'Roll QC Report', 'link' => 'forms/roll_qc_report.php', 'icon' => 'fas fa-clipboard-check'],
                ['text' => 'Roll Entry', 'link' => 'forms/roll_entry.php', 'icon' => 'fas fa-dolly-flatbed'],
                ['text' => 'Roll Internal Transfer Entry', 'link' => 'forms/roll_transfer_entry.php', 'icon' => 'fas fa-truck-moving'],
                ['text' => 'Roll Production Summary', 'link' => 'reports/roll_production_summary.php', 'icon' => 'fas fa-chart-bar'],
                ['text' => 'Fiber to Roll Conversion', 'link' => 'reports/fiber_to_roll_conversion.php', 'icon' => 'fas fa-sync-alt'],
                ['text' => 'Roll Transfer Log', 'link' => 'reports/roll_transfer_log.php', 'icon' => 'fas fa-truck-moving'],
                ['text' => 'Roll Defect Report', 'link' => 'reports/roll_defect_report.php', 'icon' => 'fas fa-exclamation-triangle'],
            ]],
            ['type' => 'group', 'text' => 'Bag Production', 'icon' => 'fas fa-cogs', 'children' => [
                ['text' => 'Roll Received Entry', 'link' => 'forms/roll_received_entry.php', 'icon' => 'fas fa-clipboard-check'],
                ['text' => 'CNC Machine Entry', 'link' => 'forms/cnc_entry.php', 'icon' => 'fas fa-cut'],
                ['text' => 'Sewing Machine Entry', 'link' => 'forms/swing_machine_entry.php', 'icon' => 'fas fa-scissors'],
                ['text' => 'Branding Entry', 'link' => 'forms/branding_entry.php', 'icon' => 'fas fa-stamp'],
                ['text' => 'Production Summary Report', 'link' => 'reports/production_summary.php', 'icon' => 'fas fa-chart-line'],
                ['text' => 'Production Comparison Report', 'link' => 'reports/production_comparison_report.php', 'icon' => 'fas fa-chart-area'],
                ['text' => 'CNC Cutting Summary', 'link' => 'reports/cnc_cutting_summary.php', 'icon' => 'fas fa-cut'],
                ['text' => 'Sewing Output Report', 'link' => 'reports/sewing_output_report.php', 'icon' => 'fas fa-scissors'],
                ['text' => 'Branding Summary Report', 'link' => 'reports/branding_summary_report.php', 'icon' => 'fas fa-stamp'],
            ]],
            ['type' => 'group', 'text' => 'Scrap/Waste', 'icon' => 'fas fa-recycle', 'children' => [
                ['text' => 'Scrap Entry', 'link' => 'forms/scrap_entry.php', 'icon' => 'fas fa-trash'],
                ['text' => 'View Scrap Entries (Current Shift)', 'link' => 'forms/scrap_entries_list.php', 'icon' => 'fas fa-list'],
                ['text' => 'Side Cut Entry', 'link' => 'forms/side_cut_entry.php', 'icon' => 'fas fa-cut'],
                ['text' => 'Daily Scrap Summary', 'link' => 'reports/daily_scrap_summary.php', 'icon' => 'fas fa-calendar-day'],
                ['text' => 'Reference-wise Scrap Report', 'link' => 'reports/reference_scrap_report.php', 'icon' => 'fas fa-link'],
                ['text' => 'CNC Batch-wise Scrap Report', 'link' => 'reports/batch_scrap_report.php', 'icon' => 'fas fa-cut'],
                ['text' => 'Scrap Summary by Type', 'link' => 'reports/scrap_summary_type.php', 'icon' => 'fas fa-chart-pie'],
            ]],
            ['type' => 'group', 'text' => 'Finished Goods(FG)', 'icon' => 'fas fa-box', 'children' => [
                ['text' => 'FG Entry', 'link' => 'forms/fg_entry.php', 'icon' => 'fas fa-plus-square'],
                ['text' => 'FG Entry (Bag) Report', 'link' => 'reports/fg_entry_bag_report.php', 'icon' => 'fas fa-shopping-bag'],
                ['text' => 'FG Received Entry', 'link' => 'forms/fg_received_entry.php', 'icon' => 'fas fa-inbox'],
                ['text' => 'FG Delivery Entry', 'link' => 'forms/fg_delivery_entry.php', 'icon' => 'fas fa-truck'],
                ['text' => 'FG Batch Report (QC Summary)', 'link' => 'reports/fg_batch_report.php', 'icon' => 'fas fa-boxes'],
                ['text' => 'FG Stock Summary', 'link' => 'reports/fg_stock_summary.php', 'icon' => 'fas fa-warehouse'],
                ['text' => 'FG Delivery Report', 'link' => 'reports/fg_delivery_report.php', 'icon' => 'fas fa-truck'],
            ]],
            ['type' => 'group', 'text' => 'Recycle', 'icon' => 'fas fa-recycle', 'children' => [
                ['text' => 'Recycle Entry', 'link' => 'forms/scrap_recycle_entry.php', 'icon' => 'fas fa-recycle'],
                ['text' => 'Recycled Material Summary', 'link' => 'reports/recycled_material_summary.php', 'icon' => 'fas fa-recycle'],
                ['text' => 'Scrap vs Recycle Ratio', 'link' => 'reports/scrap_recycle_ratio.php', 'icon' => 'fas fa-balance-scale'],
                ['text' => 'Recycle Efficiency Report', 'link' => 'reports/recycle_efficiency_report.php', 'icon' => 'fas fa-tachometer-alt'],
            ]],
            ['type' => 'group', 'text' => 'Planning', 'icon' => 'fas fa-bullseye', 'children' => [
                ['text' => 'Target Setting', 'link' => 'forms/target_entry.php', 'icon' => 'fas fa-bullseye'],
                ['text' => 'Project Entry', 'link' => 'forms/project_entry.php', 'icon' => 'fas fa-project-diagram'],
                ['text' => 'BOM Entry', 'link' => 'forms/BOM_entry.php', 'icon' => 'fas fa-list'],
                ['text' => 'Material Consumption Entry', 'link' => 'forms/material_consumption_entry.php', 'icon' => 'fas fa-minus-circle'],
                ['text' => 'Target vs Actual', 'link' => 'reports/target_vs_actual.php', 'icon' => 'fas fa-chart-line'],
                ['text' => 'Project Value Report', 'link' => 'reports/project_value_report.php', 'icon' => 'fas fa-dollar-sign'],
                ['text' => 'BOM Entry Log', 'link' => 'reports/bom_entry_log.php', 'icon' => 'fas fa-list'],
                ['text' => 'Material Requirement Report', 'link' => 'reports/material_requirement_report.php', 'icon' => 'fas fa-boxes'],
            ]],
            ['type' => 'group', 'text' => 'Finance and Analytics', 'icon' => 'fas fa-dollar-sign', 'children' => [
                ['text' => 'Comprehensive Analytics Dashboard', 'link' => 'reports/comprehensive_analytics_dashboard.php', 'icon' => 'fas fa-chart-line'],
                ['text' => 'Product Pricing', 'link' => 'admin/product_pricing.php', 'icon' => 'fas fa-tags'],
                ['text' => 'Materials Management', 'link' => 'admin/materials_management.php', 'icon' => 'fas fa-boxes'],
                ['text' => 'Production Cost Settings', 'link' => 'admin/production_cost_settings.php', 'icon' => 'fas fa-cog'],
                ['text' => 'Scrap Cost Settings', 'link' => 'admin/scrap_cost_settings.php', 'icon' => 'fas fa-trash-alt'],
                ['text' => 'Material Consumption Cost', 'link' => 'reports/material_consumption_cost_report.php', 'icon' => 'fas fa-money-bill-wave'],
                ['text' => 'Production Cost Report', 'link' => 'reports/production_cost_report.php', 'icon' => 'fas fa-industry'],
                ['text' => 'Roll Production Price Report', 'link' => 'reports/roll_production_price_report.php', 'icon' => 'fas fa-dolly-flatbed'],
                ['text' => 'Scrap Loss Report', 'link' => 'reports/scrap_loss_report.php', 'icon' => 'fas fa-trash-alt'],
            ]],
        ],
    
        // ========== PRODUCTION USER - Roll Production, Production, Scrap, Recycle ==========
        'production_user' => [
            ['type' => 'group', 'text' => 'Material Request', 'icon' => 'fas fa-file-signature', 'children' => [
                ['text' => 'Material Request Entry', 'link' => 'forms/material_request_entry.php', 'icon' => 'fas fa-file-signature'],
                ['text' => 'Material Request Status', 'link' => 'reports/material_request_status_dashboard.php', 'icon' => 'fas fa-tasks'],
            ]],
            ['type' => 'group', 'text' => 'Sheet Production', 'icon' => 'fas fa-industry', 'children' => [
                ['text' => 'Fiber Received Entry', 'link' => 'forms/fiber_entry.php', 'icon' => 'fas fa-boxes'],
                ['text' => 'Fiber Input Entry', 'link' => 'forms/fiber_to_roll_entry.php', 'icon' => 'fas fa-exchange-alt'],
                ['text' => 'GSM and Roll Input', 'link' => 'forms/gsm_roll_entry.php', 'icon' => 'fas fa-ruler-combined'],
                ['text' => 'Roll Entry', 'link' => 'forms/roll_entry.php', 'icon' => 'fas fa-dolly-flatbed'],
                ['text' => 'Roll Production Summary', 'link' => 'reports/roll_production_summary.php', 'icon' => 'fas fa-chart-bar'],
                ['text' => 'Fiber to Roll Conversion', 'link' => 'reports/fiber_to_roll_conversion.php', 'icon' => 'fas fa-sync-alt'],
            ]],
            ['type' => 'group', 'text' => 'Quality Control(QC)', 'icon' => 'fas fa-clipboard-check', 'children' => [
                ['text' => 'Daily GSM Check (Floor)', 'link' => 'forms/daily_gsm_check.php', 'icon' => 'fas fa-weight'],
                ['text' => 'Length Calibration', 'link' => 'forms/length_calibration_entry.php', 'icon' => 'fas fa-ruler'],
            ]],
            ['type' => 'group', 'text' => 'Scrap/Waste', 'icon' => 'fas fa-trash', 'children' => [
                ['text' => 'Scrap Entry', 'link' => 'forms/scrap_entry.php', 'icon' => 'fas fa-trash'],
                ['text' => 'Side Cut Entry', 'link' => 'forms/side_cut_entry.php', 'icon' => 'fas fa-cut'],
                ['text' => 'Daily Scrap Summary', 'link' => 'reports/daily_scrap_summary.php', 'icon' => 'fas fa-calendar-day'],
            ]],
            ['type' => 'group', 'text' => 'Recycle', 'icon' => 'fas fa-recycle', 'children' => [
                ['text' => 'Recycle Entry', 'link' => 'forms/scrap_recycle_entry.php', 'icon' => 'fas fa-recycle'],
            ]],
            ['type' => 'group', 'text' => 'Finished Goods(FG)', 'icon' => 'fas fa-box', 'children' => [
                ['text' => 'FG Entry', 'link' => 'forms/fg_entry.php', 'icon' => 'fas fa-plus-square'],
                ['text' => 'FG Entry (Roll) Report', 'link' => 'reports/fg_entry_roll_report.php', 'icon' => 'fas fa-scroll'],
            ]],
        ],

        // ========== QC INSPECTOR - QC Entry ONLY (Restricted Access) ==========
        'qc_inspector' => [
            ['type' => 'group', 'text' => 'Quality Control(QC)', 'icon' => 'fas fa-clipboard-check', 'children' => [
                ['text' => 'QC Entry', 'link' => 'forms/qc_entry.php', 'icon' => 'fas fa-clipboard-check'],
            ]],
        ],

        // ========== SEWING TEST - Bag Production Module Only ==========
        'sewing_test' => [
            ['type' => 'link', 'text' => 'Dashboard Overview', 'link' => 'admin/sewing_machine_dashboard.php', 'icon' => 'fas fa-th-large'],
            ['type' => 'group', 'text' => 'Bag Production', 'icon' => 'fas fa-cogs', 'children' => [
                ['text' => 'Roll Received Entry', 'link' => 'forms/roll_received_entry.php', 'icon' => 'fas fa-clipboard-check'],
                ['text' => 'CNC Machine Entry', 'link' => 'forms/cnc_entry.php', 'icon' => 'fas fa-cut'],
                ['text' => 'Sewing Machine Entry', 'link' => 'forms/swing_machine_entry.php', 'icon' => 'fas fa-scissors'],
                ['text' => 'Branding Entry', 'link' => 'forms/branding_entry.php', 'icon' => 'fas fa-stamp'],
                ['text' => 'Production Summary Report', 'link' => 'reports/production_summary.php', 'icon' => 'fas fa-chart-line'],
                ['text' => 'Production Comparison Report', 'link' => 'reports/production_comparison_report.php', 'icon' => 'fas fa-chart-area'],
                ['text' => 'CNC Cutting Summary', 'link' => 'reports/cnc_cutting_summary.php', 'icon' => 'fas fa-cut'],
                ['text' => 'Sewing Output Report', 'link' => 'reports/sewing_output_report.php', 'icon' => 'fas fa-scissors'],
                ['text' => 'Branding Summary Report', 'link' => 'reports/branding_summary_report.php', 'icon' => 'fas fa-stamp'],
            ]],
            ['type' => 'group', 'text' => 'Finished Goods(FG)', 'icon' => 'fas fa-box', 'children' => [
                ['text' => 'FG Entry', 'link' => 'forms/fg_entry.php', 'icon' => 'fas fa-plus-square'],
                ['text' => 'FG Entry (Bag) Report', 'link' => 'reports/fg_entry_bag_report.php', 'icon' => 'fas fa-shopping-bag'],
            ]],
        ],

        // ========== STORE USER - Raw Material Store Only ==========
        'store_user' => [
            ['type' => 'group', 'text' => 'Raw Material Store', 'icon' => 'fas fa-warehouse', 'children' => [
                ['text' => 'Store Received Entry', 'link' => 'forms/store_received_entry.php', 'icon' => 'fas fa-truck-loading'],
                ['text' => 'Material Request List', 'link' => 'reports/material_request_list.php', 'icon' => 'fas fa-file-signature'],
                ['text' => 'Inventory Report', 'link' => 'reports/inventory_report.php', 'icon' => 'fas fa-warehouse'],
            ]],
        ],

        // ========== DELIVERY USER - Roll Transfer & Finished Goods Delivery ==========
        'delivery_user' => [
            ['type' => 'group', 'text' => 'Roll Transfer', 'icon' => 'fas fa-truck-moving', 'children' => [
                ['text' => 'Roll Internal Transfer Entry', 'link' => 'forms/roll_transfer_entry.php', 'icon' => 'fas fa-truck-moving'],
                ['text' => 'Roll Transfer Log Report', 'link' => 'reports/roll_transfer_log.php', 'icon' => 'fas fa-file-alt'],
            ]],
            ['type' => 'group', 'text' => 'Finished Goods Delivery', 'icon' => 'fas fa-truck', 'children' => [
                ['text' => 'FG Received Entry', 'link' => 'forms/fg_received_entry.php', 'icon' => 'fas fa-inbox'],
                ['text' => 'FG Delivery Entry', 'link' => 'forms/fg_delivery_entry.php', 'icon' => 'fas fa-truck'],
                ['text' => 'FG Delivery Report', 'link' => 'reports/fg_delivery_report.php', 'icon' => 'fas fa-file-alt'],
            ]],
        ],

        // ========== AGM OPS - QC Approval + Production Monitoring ==========
        'agm ops' => [
            ['type' => 'link', 'text' => 'Dashboard Overview', 'link' => 'admin/dashboard_overview.php', 'icon' => 'fas fa-th-large'],
            ['type' => 'link', 'text' => 'Management KPI Dashboard', 'link' => 'admin/management_kpi_dashboard.php', 'icon' => 'fas fa-chart-line'],
            ['type' => 'group', 'text' => 'Production Monitoring', 'icon' => 'fas fa-chart-line', 'children' => [
                ['text' => 'Stage-wise Stock & QC Report', 'link' => 'reports/stage_wise_stock_qc_report.php', 'icon' => 'fas fa-chart-pie'],
                ['text' => 'Roll Production Summary', 'link' => 'reports/roll_production_summary.php', 'icon' => 'fas fa-chart-bar'],
                ['text' => 'Target vs Actual', 'link' => 'reports/target_vs_actual.php', 'icon' => 'fas fa-chart-line'],
                ['text' => 'Scrap Summary', 'link' => 'reports/scrap_summary_type.php', 'icon' => 'fas fa-chart-pie'],
                ['text' => 'FG Stock Summary', 'link' => 'reports/fg_stock_summary.php', 'icon' => 'fas fa-warehouse'],
                ['text' => 'FG Batch Report (QC Summary)', 'link' => 'reports/fg_batch_report.php', 'icon' => 'fas fa-boxes'],
                ['text' => 'FG Delivery Report', 'link' => 'reports/fg_delivery_report.php', 'icon' => 'fas fa-truck'],
            ]],
            ['type' => 'group', 'text' => 'Raw Material Store', 'icon' => 'fas fa-warehouse', 'children' => [
                ['text' => '1. Test Approval Dashboard', 'link' => 'admin/raw_material_test_approval_dashboard.php', 'icon' => 'fas fa-clipboard-check'],
                ['text' => '2. Approved Material Inventory', 'link' => 'reports/approved_material_inventory.php', 'icon' => 'fas fa-check-square'],
                ['text' => '3. Inventory Report', 'link' => 'reports/inventory_report.php', 'icon' => 'fas fa-warehouse'],
            ]],
            ['type' => 'group', 'text' => 'Sheet Production', 'icon' => 'fas fa-industry', 'children' => [
                ['text' => 'Roll QC Report', 'link' => 'forms/roll_qc_report.php', 'icon' => 'fas fa-clipboard-check'],
            ]],
            ['type' => 'group', 'text' => 'Quality Control(QC)', 'icon' => 'fas fa-clipboard-check', 'children' => [
                ['text' => 'QC Test Approval Dashboard', 'link' => 'admin/qc_test_approval_dashboard.php', 'icon' => 'fas fa-check-double'],
                ['text' => 'QC Entry Approval Dashboard', 'link' => 'admin/qc_approval_dashboard.php', 'icon' => 'fas fa-check-circle'],
                // ['text' => 'QC Reports Dashboard', 'link' => 'admin/qc_reports_dashboard.php', 'icon' => 'fas fa-tasks'],
                ['text' => 'AGM External Test Dashboard', 'link' => 'admin/agm_external_test_dashboard.php', 'icon' => 'fas fa-file-alt'],
                ['text' => 'QC Summary Report', 'link' => 'forms/qc_summary_report.php', 'icon' => 'fas fa-file-invoice'],
                ['text' => 'QC Test Order', 'link' => 'forms/qc_test_order.php', 'icon' => 'fas fa-list-check'],
            ]],
            ['type' => 'group', 'text' => 'Planning', 'icon' => 'fas fa-bullseye', 'children' => [
                ['text' => 'Project Entry', 'link' => 'forms/project_entry.php', 'icon' => 'fas fa-project-diagram'],
            ]],
            ['type' => 'group', 'text' => 'Finance and Analytics', 'icon' => 'fas fa-dollar-sign', 'children' => [
                ['text' => 'Comprehensive Analytics Dashboard', 'link' => 'reports/comprehensive_analytics_dashboard.php', 'icon' => 'fas fa-chart-line'],
                ['text' => 'Scrap Loss Report', 'link' => 'reports/scrap_loss_report.php', 'icon' => 'fas fa-trash-alt'],
            ]],
        ],

        // ========== TESTER - Lab Testing Only ==========
        'tester' => [
            ['type' => 'link', 'text' => 'Rejected Reports Dashboard', 'link' => 'tester_rejected_reports.php', 'icon' => 'fas fa-exclamation-triangle'],
            ['type' => 'group', 'text' => 'Raw Material Store', 'icon' => 'fas fa-warehouse', 'children' => [
                ['text' => '1. Fineness of Fiber Report (ISO 1973)', 'link' => 'forms/fineness_fiber_report.php', 'icon' => 'fas fa-weight'],
                ['text' => '2. Cut Length of Fiber Report (ASTM D5103)', 'link' => 'forms/cut_length_fiber_report.php', 'icon' => 'fas fa-ruler'],
                ['text' => '3. Tenacity of Fiber Report (EN ISO 5079)', 'link' => 'forms/tenacity_fiber_report.php', 'icon' => 'fas fa-flask'],
                ['text' => '4. Tenacity of Yarn Report (ASTM D2256)', 'link' => 'forms/tenacity_yarn_report.php', 'icon' => 'fas fa-flask'],
                ['text' => '5. Fiber Test Report', 'link' => 'forms/fiber_test_report.php', 'icon' => 'fas fa-dna'],
                ['text' => '6. Sewing Thread Report', 'link' => 'forms/sewing_thread_report.php', 'icon' => 'fas fa-scroll'],
            ]],
            ['type' => 'group', 'text' => 'Lab Testing', 'icon' => 'fas fa-flask', 'children' => [
                ['text' => 'Forwarded External Test Dashboard', 'link' => 'admin/forwarded_external_test_dashboard.php', 'icon' => 'fas fa-inbox'],
                ['text' => 'QC Test Order', 'link' => 'forms/qc_test_order.php', 'icon' => 'fas fa-list-check'],
                ['text' => 'Water Permeability Test', 'link' => 'forms/water_permeability_test.php', 'icon' => 'fas fa-tint'],
                ['text' => 'Characteristics Test', 'link' => 'forms/characteristics_test.php', 'icon' => 'fas fa-filter'],
                ['text' => 'UV Test', 'link' => 'forms/weathering_exposure_test.php', 'icon' => 'fas fa-sun'],
                ['text' => 'Fabric Internal Production Sample Test Summary', 'link' => 'forms/fabric_after_production_test.php', 'icon' => 'fas fa-industry'],
                ['text' => 'Sun Test Report', 'link' => 'forms/sun_test_report.php', 'icon' => 'fas fa-sun'],
                ['text' => 'Lab Testing Scrap Entry', 'link' => 'forms/lab_testing_scrap_entry.php', 'icon' => 'fas fa-trash'],
            ]],
        ],

        // ========== CHECKER - Lab Testing Reports ==========
        'checker' => [
            ['type' => 'link', 'text' => 'Lab Testing Reports Dashboard', 'link' => 'admin/lab_testing_dashboard.php', 'icon' => 'fas fa-chart-line'],
            ['type' => 'link', 'text' => 'External Test Checker Dashboard', 'link' => 'admin/external_checker_dashboard.php', 'icon' => 'fas fa-vial'],
        ],

        // ========== FINANCE USER - Finance Module + BOM ==========
        'finance_user' => [
            ['type' => 'group', 'text' => 'Finance and Analytics', 'icon' => 'fas fa-dollar-sign', 'children' => [
                ['text' => 'Material Consumption Cost', 'link' => 'reports/material_consumption_cost_report.php', 'icon' => 'fas fa-money-bill-wave'],
                ['text' => 'Production Cost Report', 'link' => 'reports/production_cost_report.php', 'icon' => 'fas fa-industry'],
                ['text' => 'Roll Production Price Report', 'link' => 'reports/roll_production_price_report.php', 'icon' => 'fas fa-dolly-flatbed'],
                ['text' => 'Scrap Loss Report', 'link' => 'reports/scrap_loss_report.php', 'icon' => 'fas fa-trash-alt'],
            ]],
            ['type' => 'group', 'text' => 'View Access', 'icon' => 'fas fa-eye', 'children' => [
                ['text' => 'FG Stock Summary', 'link' => 'reports/fg_stock_summary.php', 'icon' => 'fas fa-warehouse'],
            ]],
        ],
        
        // ========== FINANCE ROLE - Finance Module Full Access ==========
        'finance' => [
            ['type' => 'group', 'text' => 'Finance and Analytics', 'icon' => 'fas fa-dollar-sign', 'children' => [
                ['text' => 'Material Consumption Cost', 'link' => 'reports/material_consumption_cost_report.php', 'icon' => 'fas fa-money-bill-wave'],
                ['text' => 'Production Cost Report', 'link' => 'reports/production_cost_report.php', 'icon' => 'fas fa-industry'],
                ['text' => 'Roll Production Price Report', 'link' => 'reports/roll_production_price_report.php', 'icon' => 'fas fa-dolly-flatbed'],
                ['text' => 'Scrap Loss Report', 'link' => 'reports/scrap_loss_report.php', 'icon' => 'fas fa-trash-alt'],
            ]],
            ['type' => 'group', 'text' => 'View Access', 'icon' => 'fas fa-eye', 'children' => [
                ['text' => 'FG Stock Summary', 'link' => 'reports/fg_stock_summary.php', 'icon' => 'fas fa-warehouse'],
                ['text' => 'Material Requirement Report', 'link' => 'reports/material_requirement_report.php', 'icon' => 'fas fa-boxes'],
            ]],
        ],

        // ========== PLANNING USER - Planning Module ==========
        'planning_user' => [
            ['type' => 'group', 'text' => 'Planning', 'icon' => 'fas fa-bullseye', 'children' => [
                ['text' => 'Target Setting', 'link' => 'forms/target_entry.php', 'icon' => 'fas fa-bullseye'],
                ['text' => 'BOM Entry', 'link' => 'forms/BOM_entry.php', 'icon' => 'fas fa-list'],
                ['text' => 'Material Consumption Entry', 'link' => 'forms/material_consumption_entry.php', 'icon' => 'fas fa-minus-circle'],
                ['text' => 'Target vs Actual', 'link' => 'reports/target_vs_actual.php', 'icon' => 'fas fa-chart-line'],
                ['text' => 'Material Requirement Report', 'link' => 'reports/material_requirement_report.php', 'icon' => 'fas fa-boxes'],
            ]],
        ],

        // ========== MANAGEMENT - View All + Analytics (no QC, no Raw Material Store) ==========
        'management' => [
            ['type' => 'link', 'text' => 'Management KPI Dashboard', 'link' => 'admin/management_kpi_dashboard.php', 'icon' => 'fas fa-chart-line'],
            ['type' => 'group', 'text' => 'Reports & Analytics', 'icon' => 'fas fa-chart-area', 'children' => [
                ['text' => 'Roll Production Summary', 'link' => 'reports/roll_production_summary.php', 'icon' => 'fas fa-chart-bar'],
                ['text' => 'Target vs Actual', 'link' => 'reports/target_vs_actual.php', 'icon' => 'fas fa-chart-line'],
                ['text' => 'Scrap Summary', 'link' => 'reports/scrap_summary_type.php', 'icon' => 'fas fa-chart-pie'],
                ['text' => 'FG Stock Summary', 'link' => 'reports/fg_stock_summary.php', 'icon' => 'fas fa-warehouse'],
            ]],
        ],
    
        // ========== PROD_USER - Limited Access (No Dashboard) ==========
        'prod_user' => [
            ['type' => 'link', 'text' => 'Welcome', 'link' => 'welcome.php', 'icon' => 'fas fa-home'],
            ['type' => 'group', 'text' => 'Finished Goods (FG)', 'icon' => 'fas fa-box', 'children' => [
                ['text' => 'FG Entry', 'link' => 'forms/fg_entry.php', 'icon' => 'fas fa-plus-square'],
                ['text' => 'FG Entry (Roll) Report', 'link' => 'reports/fg_entry_roll_report.php', 'icon' => 'fas fa-scroll'],
            ]],
        ],

        // ========== DEFAULT USER - Basic Access ==========
        'user' => [
            ['type' => 'link', 'text' => 'Quick Access', 'link' => 'quick_access.php', 'icon' => 'fas fa-rocket'],
        ],
    ];
    

//style

// Determine which menu items to display for the current user's role
$displayMenuItems = $menuItems[$userRole] ?? $menuItems['user']; // Fallback to 'user' role if current role is not defined

// Set default page - Explicitly handle prod_user first, then others
// Also check for URL parameter attempts to load dashboard
$requestedPage = $_GET['page'] ?? '';

// If prod_user tries to access dashboard via URL parameter, block it
if ($userRole === 'prod_user') {
    if (strpos($requestedPage, 'dashboard_overview') !== false || strpos($requestedPage, 'dashboard') !== false) {
        $requestedPage = 'welcome.php';
    }
    // prod_user should always see welcome page, never dashboard
    $defaultPage = $requestedPage ?: 'welcome.php';
} elseif ($userRole === 'agm ops' || $userRole === 'agm operations') {
    // AGM ops always defaults to Dashboard Overview
    $defaultPage = $requestedPage ?: 'admin/dashboard_overview.php';
} else {
    // Only admin and agm ops can access the reporting dashboard
    $dashboardRoles = ['admin', 'agm ops', 'agm operations'];
    if (in_array($userRole, $dashboardRoles, true)) {
        $defaultPage = $requestedPage ?: 'admin/dashboard_overview.php';
    } else {
        $defaultPage = $requestedPage ?: 'welcome.php';
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title> GEOCIL Automation System - Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <style>
    /* Base Styles & Typography */
    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }
    html {
      margin: 0 !important;
      padding: 0 !important;
      background-color: #fff !important;
    }
    body {
      margin: 0 !important;
      padding: 0 !important;
      font-family: 'Inter', sans-serif;
      background-color: #fff !important;
      display: flex;
      flex-direction: column;
      height: 100vh;
      color: #333;
      overflow: hidden;
    }

    /* Main Container & Layout */
    .container {
      display: flex;
      flex: 1;
      overflow: hidden; /* Ensure content doesn't spill */
    }

    /* Topbar */
    .topbar {
      background: linear-gradient(135deg, #1a1f2e 0%, #2d3548 100%);
      padding: 0 20px;
      height: 65px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
      z-index: 100;
      margin-left: 240px;
      transition: margin-left 0.3s ease-in-out;
      position: relative;
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
    
    /* When sidebar is collapsed, topbar takes full width */
    .container.sidebar-collapsed .topbar {
      margin-left: 0;
    }

    .topbar-left {
      display: flex;
      align-items: center;
      gap: 15px;
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
    
    .topbar-brand {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .topbar-logo {
      height: 38px;
      width: auto;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
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

    .topbar .user-info {
      font-weight: 500;
      color: #b8c5d6;
      margin-right: 15px;
    }

    .topbar .logout-btn {
      background: linear-gradient(135deg, #ef4444, #dc2626);
      color: white;
      padding: 10px 20px;
      border: none;
      border-radius: 8px;
      font-size: 0.95em;
      cursor: pointer;
      transition: all 0.25s ease;
    }

    .topbar .logout-btn:hover {
      background: linear-gradient(135deg, #dc2626, #b91c1c);
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
    }

    /* Profile Dropdown Styles */
    .topbar-right {
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .profile-dropdown {
      position: relative;
      display: inline-block;
    }

    .profile-btn {
      background: linear-gradient(135deg, #00d4aa, #00b4d8);
      color: #1a1f2e;
      border: none;
      padding: 10px 18px;
      border-radius: 50px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 0.9em;
      font-weight: 600;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(0, 212, 170, 0.3);
    }

    .profile-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(0, 212, 170, 0.4);
    }
    
    .profile-btn:active {
      transform: translateY(0);
    }

    .profile-btn i {
      font-size: 1.15em;
    }
    
    .profile-btn .fa-chevron-down {
      font-size: 0.7em;
      opacity: 0.8;
      transition: transform 0.3s ease;
    }
    
    .profile-btn:hover .fa-chevron-down {
      transform: translateY(2px);
    }

    .profile-dropdown-content {
      display: none;
      position: absolute;
      right: 0;
      top: 100%;
      background: #1e2536;
      min-width: 290px;
      box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
      border-radius: 16px;
      border: 1px solid rgba(255, 255, 255, 0.08);
      z-index: 1000;
      margin-top: 12px;
      overflow: hidden;
      animation: dropdownFadeIn 0.3s ease;
    }

    @keyframes dropdownFadeIn {
      from {
        opacity: 0;
        transform: translateY(-10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .profile-dropdown-content.show {
      display: block;
    }

    .profile-header {
      background: linear-gradient(135deg, #00d4aa 0%, #00b4d8 100%);
      color: #1a1f2e;
      padding: 22px 20px;
      display: flex;
      align-items: center;
      gap: 15px;
    }

    .profile-header i {
      font-size: 2.8em;
      opacity: 0.85;
    }

    .profile-header div {
      flex: 1;
    }

    .profile-header strong {
      display: block;
      font-size: 1.15em;
      margin-bottom: 3px;
      font-weight: 700;
    }

    .profile-header small {
      opacity: 0.85;
      font-size: 0.85em;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      font-weight: 500;
    }

    .profile-actions {
      padding: 12px 0;
      background: #1e2536;
    }

    .profile-actions a {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 20px;
      color: #b8c5d6;
      text-decoration: none;
      transition: all 0.25s ease;
      font-weight: 500;
    }

    .profile-actions a:hover {
      background: rgba(0, 212, 170, 0.1);
      color: #00d4aa;
      padding-left: 25px;
    }

    .profile-actions a i {
      width: 20px;
      text-align: center;
      color: #6b7a8f;
      transition: color 0.25s ease;
    }

    .profile-actions a:hover i {
      color: #00d4aa;
    }

    .profile-footer {
      padding: 15px 20px;
      border-top: 1px solid rgba(255, 255, 255, 0.06);
      background: #171c28;
    }

    .logout-btn-dropdown {
      width: 100%;
      background: linear-gradient(135deg, #ef4444, #dc2626);
      color: white;
      border: none;
      padding: 12px 20px;
      border-radius: 10px;
      cursor: pointer;
      font-size: 0.95em;
      font-weight: 600;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }

    .logout-btn-dropdown:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
    }

    /* Auto-dismiss progress bar */
    .auto-dismiss-progress {
      height: 3px;
      background-color: rgba(255, 255, 255, 0.1);
      position: relative;
      overflow: hidden;
    }

    .auto-dismiss-progress::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      height: 100%;
      width: 100%;
      background: linear-gradient(90deg, #00d4aa, #00b4d8, #0077b6);
      animation: progressShrink 5s linear forwards;
      transform-origin: left;
    }

    @keyframes progressShrink {
      from {
        transform: scaleX(1);
      }
      to {
        transform: scaleX(0);
      }
    }

    /* Hide progress bar when dropdown is not visible */
    .profile-dropdown-content:not(.show) .auto-dismiss-progress {
      display: none;
    }

    .auto-dismiss-notice {
      text-align: center;
      padding: 10px 15px;
      background: rgba(0, 212, 170, 0.08);
      color: #8896a8;
      font-size: 0.8em;
      border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    }

    .auto-dismiss-notice i {
      margin-right: 5px;
      color: #00d4aa;
    }

    /* Hide notice when dropdown is not visible */
    .profile-dropdown-content:not(.show) .auto-dismiss-notice {
      display: none;
    }

    /* Sidebar fix */
    .sidebar {
      width: 240px;
      position: fixed;
      top: 0;
      left: 0;
      height: 100%;
      z-index: 1000;
      background: linear-gradient(180deg, #1a1f2e 0%, #232b3d 100%);
      color: #e0e6ed;
      border-right: 1px solid rgba(255, 255, 255, 0.06);
      box-shadow: 4px 0 20px rgba(0, 0, 0, 0.15);
      display: flex;
      flex-direction: column;
      padding: 0;
      transition: transform 0.3s ease-in-out;
      overflow-y: hidden;
    }

    /* Desktop: Sidebar starts open */
    .sidebar:not(.collapsed) {
        transform: translateX(0);
    }

    .sidebar.collapsed {
      transform: translateX(-100%);
      width: 0;
      padding: 0;
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
      flex: 1;
      overflow-y: auto;
      padding: 15px 10px;
      scrollbar-width: thin;
      scrollbar-color: #3d4a5c #1a1f2e;
    }

    /* Custom scrollbar for Webkit browsers */
    .sidebar-content::-webkit-scrollbar {
      width: 6px;
    }
    .sidebar-content::-webkit-scrollbar-track {
      background: #1a1f2e;
    }
    .sidebar-content::-webkit-scrollbar-thumb {
      background: linear-gradient(180deg, #00d4aa, #00b4d8);
      border-radius: 10px;
    }

    .sidebar a {
      color: #b8c5d6;
      text-decoration: none;
      padding: 12px 14px;
      transition: all 0.25s ease;
      display: flex;
      align-items: center;
      border-radius: 10px;
      margin-bottom: 4px;
      white-space: nowrap;
      overflow: hidden;
      min-width: 0;
      font-size: 0.88em;
      font-weight: 500;
    }
    
    .sub-menu a > span {
      overflow: visible;
      text-overflow: clip;
      flex: 1;
      min-width: 0;
      white-space: normal;
      word-wrap: break-word;
      overflow-wrap: break-word;
    }
    
    .sidebar a:not(.group-header):not(.sub-menu a) > span {
      overflow: visible;
      text-overflow: clip;
      flex: 1;
      min-width: 0;
    }

    .sidebar a i {
      font-size: 1em;
      color: #00d4aa;
      flex-shrink: 0;
      margin-right: 12px;
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

    .menu-group:last-of-type {
      border-bottom: none;
    }

    .group-header {
      display: flex;
      align-items: center;
      padding: 12px 14px;
      background: rgba(255, 255, 255, 0.03);
      cursor: pointer;
      font-weight: 600;
      font-size: 0.9em;
      transition: all 0.25s ease;
      border-radius: 10px;
      margin-bottom: 2px;
      color: #e0e6ed;
      gap: 8px;
      border: 1px solid transparent;
    }

    .group-header i {
        color: #00d4aa;
        flex-shrink: 0;
        margin-right: 10px;
        width: 20px;
        text-align: center;
    }
    
    .group-header > span:first-of-type {
        flex: 1;
        white-space: nowrap;
        overflow: visible;
        text-overflow: clip;
        min-width: 0;
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

    .toggle-icon {
      font-size: 0.7em;
      transition: transform 0.3s ease;
      color: #6b7a8f;
      margin-left: auto;
      flex-shrink: 0;
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
      padding-left: 0 !important;
      margin-left: 0 !important;
      border-left: none !important;
      border-radius: 0 0 10px 10px;
      overflow: hidden;
      transition: all 0.3s ease;
      padding-top: 5px;
      padding-bottom: 8px;
      margin-top: 0;
      margin-bottom: 6px;
      margin-left: 10px !important;
      margin-right: 0;
      border-left: 2px solid rgba(0, 212, 170, 0.3) !important;
    }
    
    .sub-menu.show {
      display: block !important;
    }

    .sub-menu a {
      padding: 10px 12px;
      padding-left: 15px !important;
      margin-left: 0 !important;
      font-size: 0.82em;
      color: #8896a8;
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 2px;
      white-space: normal;
      word-wrap: break-word;
      overflow-wrap: break-word;
      border-radius: 6px;
      margin-right: 8px;
    }
    
    .sub-menu a i {
      font-size: 0.85em;
      min-width: 16px;
      text-align: center;
      color: #6b7a8f;
    }
    
    .sub-menu a:hover {
        background: rgba(0, 212, 170, 0.1);
        color: #00d4aa;
    }
    
    .sub-menu a:hover i {
        color: #00d4aa;
    }

    /* Menu Toggle Button (for all screens now, but only visible on mobile by default) */
    .menu-toggle {
      background: none;
      border: none;
      color: #2c3e50;
      font-size: 28px; /* Larger icon */
      margin-right: 15px;
      cursor: pointer;
      transition: color 0.2s ease;
      padding: 8px;
      border-radius: 4px;
      display: block !important; /* Make visible on all screen sizes for testing */
    }
    .menu-toggle:hover {
        color: #3498db;
        background-color: rgba(52, 152, 219, 0.1);
    }

    /* Main content area */
    .main-content {
      flex: 1;
      display: flex;
      flex-direction: column;
      height: 100%;
      background-color: #fff !important;
      padding: 0 10px !important;
      margin: 0 !important;
      transition: margin-left 0.3s ease-in-out;
    }

    /* Wrap content tightly beside sidebar */
    .content-wrapper,
    .page-wrapper {
      margin: 0 !important;
      padding: 0 !important;
    }

    /* Align main container flush with sidebar */
    .container:not(.sidebar-collapsed) .main-content {
      margin-left: 240px !important; /* exactly sidebar width */
    }

    /* Sidebar collapsed → content full width */
    .container.sidebar-collapsed .main-content {
      margin-left: 0 !important;
    }

    /* Iframe tight integration */
    iframe {
      flex: 1;
      width: 100%;
      height: 100%;
      border: none !important;
      border-radius: 0 !important;
      box-shadow: none !important;
      display: block;
      background-color: #fff;
      overflow: auto !important;
    }

    /* Eliminate ALL body margin & padding */
    body,
    html {
      margin: 0 !important;
      padding: 0 !important;
      background-color: #fff !important;
    }
    
    /* Allow iframe body to scroll */
    iframe body {
      margin: 0 !important;
      padding: 0 !important;
      background-color: #fff !important;
      overflow-y: auto !important;
      overflow-x: hidden !important;
    }

    /* Close Sidebar Button */
    .close-sidebar-btn {
      position: absolute;
      top: 18px;
      right: 12px;
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.1);
      color: #8896a8;
      font-size: 16px;
      cursor: pointer;
      padding: 8px;
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 101;
      transition: all 0.25s ease;
    }
    .close-sidebar-btn:hover {
        background: rgba(239, 68, 68, 0.15);
        border-color: rgba(239, 68, 68, 0.3);
        color: #ef4444;
    }

    /* Overlay for mobile sidebar */
    .overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5); /* Semi-transparent black */
        z-index: 999; /* Below sidebar, above content */
        display: none; /* Hidden by default */
        transition: opacity 0.35s ease;
        opacity: 0;
    }
    .overlay.active {
        display: block;
        opacity: 1;
    }

    /* Footer - compact and flush */
    .footer-link {
        text-align: center;
        padding: 3px 0;
        font-size: 0.75em;
        color: #555;
        text-decoration: none;
        cursor: pointer;
        border-top: 1px solid #e1e1e1;
        background-color: #ffffff;
    }
    .footer-link:hover {
        color: #3498db;
    }

    /* Developer Info Pop-up */
    .dev-info-popup {
      position: fixed;
      bottom: 50px; /* Above the footer link */
      left: 50%;
      transform: translateX(-50%);
      background-color: #333;
      color: #fff;
      padding: 15px 25px;
      border-radius: 8px;
      box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
      text-align: center;
      font-size: 0.9em;
      opacity: 0;
      visibility: hidden;
      transition: opacity 0.3s ease, visibility 0.3s ease;
      z-index: 10000; /* Increased z-index to ensure visibility */
      max-width: 90%; /* Responsive width */
    }
    .dev-info-popup.show {
      opacity: 1;
      visibility: visible;
    }
    .dev-info-popup p {
        margin: 5px 0;
    }
    .dev-info-popup strong {
        color: #ffcc00; /* Highlight for developer info */
    }

    /* Optional: Slight overlay animation for sidebar collapse */
    @media (max-width: 768px) {
      .sidebar {
        width: 250px;
        position: fixed;
        left: 0;
        top: 0;
        height: 100%;
        transform: translateX(-100%);
        transition: transform 0.3s ease;
        z-index: 9999;
      }

      .sidebar:not(.collapsed) {
        transform: translateX(0);
      }

      .sidebar.collapsed {
        transform: translateX(-100%);
      }

      .close-sidebar-btn {
        display: block;
      }

      .main-content {
        margin-left: 0 !important;
      }
      
      .topbar {
        margin-left: 0 !important; /* Full width on mobile */
      }

      iframe {
        border-radius: 0;
      }

      .topbar-brand-text strong {
          font-size: 1em;
      }
      
      .topbar-brand-text small {
          font-size: 0.6em;
      }

      .profile-btn {
        padding: 8px 14px;
        font-size: 0.85em;
      }
      
      .profile-btn span {
        display: none;
      }

      .profile-dropdown-content {
        min-width: 260px;
        right: -10px;
      }

      .footer-link {
        font-size: 0.7em;
        padding: 5px 10px;
      }
      .dev-info-popup {
          bottom: 30px; /* Adjust for smaller screens */
          font-size: 0.8em;
          padding: 10px 15px;
      }
    }
  </style>
</head>
<body>
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
      </button>
      <div class="topbar-brand">
        <img src="assets/images/logo.jpg" alt="GEOCIL Logo" class="topbar-logo">
        <div class="topbar-brand-text">
          <strong>GEOCIL</strong>
          <small>Automation</small>
        </div>
      </div>
    </div>
    <div class="topbar-right">
      <div class="profile-dropdown">
        <button class="profile-btn" onclick="toggleProfileDropdown()">
          <i class="fas fa-user-circle"></i>
          <span><?php 
            // Show full name only for admin
            if (strtolower(trim($_SESSION['role'] ?? '')) === 'admin') {
                echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin');
            }
          ?></span>
          <i class="fas fa-chevron-down"></i>
        </button>
        <div class="profile-dropdown-content" id="profileDropdown">
          <div class="profile-header">
            <i class="fas fa-user-circle"></i>
            <div>
              <strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['first_name'] ?? $_SESSION['username'] ?? 'User'); ?></strong>
              <small><?php echo htmlspecialchars($_SESSION['role'] ?? 'Guest'); ?></small>
            </div>
          </div>
          <div class="auto-dismiss-progress" id="autoDismissProgress"></div>
          <div class="auto-dismiss-notice">
            <small><i class="fas fa-clock"></i> Auto-closes in 5 seconds</small>
          </div>
          <div class="profile-actions">
            <a href="change_password.php" target="main" onclick="closeProfileDropdown()">
              <i class="fas fa-key"></i> Change Password
            </a>
            <a href="admin/profile_settings.php" target="main" onclick="closeProfileDropdown()">
              <i class="fas fa-cog"></i> Profile Settings
            </a>
          </div>
          <div class="profile-footer">
            <a href="logout.php" target="_top" class="logout-btn-dropdown">
              <i class="fas fa-sign-out-alt"></i> Logout
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="container" id="main-container">
    <div class="sidebar" id="sidebar">
      <button class="close-sidebar-btn" onclick="toggleSidebar()">
        <i class="fas fa-times"></i>
      </button>

      <div class="sidebar-header">
        <h3>GEO</h3>
      </div>
      

      <div class="sidebar-content">
        <?php foreach ($displayMenuItems as $item): ?>
            <?php if ($item['type'] === 'link'): ?>
                <a href="<?php echo htmlspecialchars($item['link']); ?>" target="main" class="nav-link">
                    <i class="<?php echo htmlspecialchars($item['icon']); ?>"></i> <span><?php echo htmlspecialchars($item['text']); ?></span>
                </a>
            <?php elseif ($item['type'] === 'group'): ?>
                <div class="menu-group">
                    <div class="group-header" onclick="toggleGroup(this)">
                        <i class="<?php echo htmlspecialchars($item['icon']); ?>"></i> <span><?php echo htmlspecialchars($item['text']); ?></span>
                        <span class="toggle-icon fas fa-chevron-down"></span>
                    </div>
                    <div class="sub-menu">
                        <?php foreach ($item['children'] as $subItem): ?>
                            <a href="<?php echo htmlspecialchars($subItem['link']); ?>" target="main" class="nav-link">
                                <i class="<?php echo htmlspecialchars($subItem['icon']); ?>"></i> <span><?php echo htmlspecialchars($subItem['text']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="overlay" id="sidebar-overlay"></div>
    <div class="main-content" id="main-content">
      <?php 
      // Triple-check: If user is prod_user, force welcome.php (security check)
      // Block any attempt to load dashboard, even via direct URL
      if ($userRole === 'prod_user') {
          if (strpos($defaultPage, 'dashboard_overview') !== false || 
              strpos($defaultPage, 'dashboard') !== false ||
              $defaultPage === 'admin/dashboard_overview.php') {
              $defaultPage = 'welcome.php';
          }
      }
      ?>
      <iframe name="main" src="<?php echo htmlspecialchars($defaultPage); ?>" title="Main Content Area" id="mainFrame"></iframe>
    </div>
  </div>

  <div class="dev-info-popup" id="devInfoPopup">
    <p><strong>Software Developed by CIPLC Scalability Team</strong></p>
    <p><strong>Developer:</strong> Sara</p>
    <p>
      <strong>Notice:</strong> This system is strictly for authorized internal use. Any unauthorized access or misuse of data is prohibited and may result in disciplinary or legal action.<br>
      For technical support or further information, please contact: <a href="tel:+8801704119934">+8801704119934</a>
    </p>
    <p>&copy; 2025 CIPLC. All rights reserved.</p>
  </div>

  <a href="#" class="footer-link" onclick="showDevInfo(event)">
     Click to View Developer Info
  </a>

  <script>
    const sidebar = document.getElementById('sidebar');
    const mainContainer = document.getElementById('main-container');
    const mainContent = document.getElementById('main-content');
    const sidebarOverlay = document.getElementById('sidebar-overlay');
    const closeSidebarBtn = document.querySelector('.close-sidebar-btn');
    const devInfoPopup = document.getElementById('devInfoPopup');
    let devInfoTimeout;

    function isMobile() {
        return window.innerWidth <= 1100; // Treat tablets as mobile for layout
    }

    function toggleSidebar() {
        if (isMobile()) {
            const isOpen = !sidebar.classList.contains('collapsed');
            if (isOpen) {
                sidebar.classList.add('collapsed');
                sidebarOverlay.classList.remove('active');
                document.body.style.overflow = 'auto';
            } else {
                sidebar.classList.remove('collapsed');
                sidebarOverlay.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
            mainContainer.classList.add('sidebar-collapsed'); // Ensure content spans full width
        } else {
            sidebar.classList.toggle('collapsed');
            if (sidebar.classList.contains('collapsed')) {
                mainContainer.classList.add('sidebar-collapsed');
            } else {
                mainContainer.classList.remove('sidebar-collapsed');
            }
        }
    }

    function closeSidebarMobile() {
        if (isMobile() && !sidebar.classList.contains('collapsed')) {
            toggleSidebar();
        }
    }

    function toggleGroup(header) {
      console.log('toggleGroup called'); // Debug log
      const subMenu = header.nextElementSibling;
      const toggleIcon = header.querySelector('.toggle-icon');

      // Close other open submenus
      document.querySelectorAll('.menu-group .sub-menu').forEach(menu => {
          if (menu !== subMenu && (menu.style.display === 'block' || menu.classList.contains('show'))) {
              menu.style.display = 'none';
              menu.classList.remove('show');
              menu.previousElementSibling.classList.remove('active');
              const icon = menu.previousElementSibling.querySelector('.toggle-icon');
              if (icon) {
                  icon.classList.replace('fa-chevron-up', 'fa-chevron-down');
              }
          }
      });

      // Toggle current submenu
      if (subMenu.style.display === 'block' || subMenu.classList.contains('show')) {
        subMenu.style.display = 'none';
        subMenu.classList.remove('show');
        header.classList.remove('active');
        toggleIcon.classList.replace('fa-chevron-up', 'fa-chevron-down');
        console.log('Submenu closed'); // Debug log
      } else {
        subMenu.style.display = 'block';
        subMenu.classList.add('show');
        header.classList.add('active');
        toggleIcon.classList.replace('fa-chevron-down', 'fa-chevron-up');
        console.log('Submenu opened'); // Debug log
      }
    }

    // Function to show developer info popup
    function showDevInfo(event) {
        event.preventDefault(); // Prevent default link behavior
        clearTimeout(devInfoTimeout); // Clear any existing timeout

        devInfoPopup.classList.add('show');

        // Set timeout to hide after 3 seconds
        devInfoTimeout = setTimeout(() => {
            devInfoPopup.classList.remove('show');
        }, 2000); // 3000 milliseconds = 3 seconds
    }

    // Profile dropdown functions
    let dropdownTimeout;

    function toggleProfileDropdown() {
        const dropdown = document.getElementById('profileDropdown');
        const isVisible = dropdown.classList.contains('show');
        
        if (isVisible) {
            // If dropdown is visible, close it
            closeProfileDropdown();
        } else {
            // If dropdown is hidden, open it and start auto-dismiss timer
            dropdown.classList.add('show');
            startAutoDismissTimer();
        }
    }

    function closeProfileDropdown() {
        const dropdown = document.getElementById('profileDropdown');
        dropdown.classList.remove('show');
        clearAutoDismissTimer();
    }

    function startAutoDismissTimer() {
        // Clear any existing timer
        clearAutoDismissTimer();
        
        // Reset progress bar animation
        const progressBar = document.getElementById('autoDismissProgress');
        if (progressBar) {
            progressBar.style.animation = 'none';
            progressBar.offsetHeight; // Trigger reflow
            progressBar.style.animation = 'progressShrink 5s linear forwards';
        }
        
        // Set new timer for 5 seconds
        dropdownTimeout = setTimeout(() => {
            closeProfileDropdown();
        }, 5000); // 5000 milliseconds = 5 seconds
    }

    function clearAutoDismissTimer() {
        if (dropdownTimeout) {
            clearTimeout(dropdownTimeout);
            dropdownTimeout = null;
        }
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('profileDropdown');
        const profileBtn = document.querySelector('.profile-btn');
        
        if (!profileBtn.contains(event.target) && !dropdown.contains(event.target)) {
            closeProfileDropdown();
        }
    });

    // Reset timer when user interacts with dropdown
    document.addEventListener('mouseenter', function(event) {
        const dropdown = document.getElementById('profileDropdown');
        if (dropdown.classList.contains('show') && dropdown.contains(event.target)) {
            // User is hovering over dropdown, restart timer
            startAutoDismissTimer();
        }
    });

    // Pause timer when user is interacting with dropdown
    document.addEventListener('mouseleave', function(event) {
        const dropdown = document.getElementById('profileDropdown');
        if (dropdown.classList.contains('show') && dropdown.contains(event.target)) {
            // User left dropdown area, start countdown
            startAutoDismissTimer();
        }
    });

    // Pause progress bar when user is interacting
    document.addEventListener('mouseenter', function(event) {
        const dropdown = document.getElementById('profileDropdown');
        const progressBar = document.getElementById('autoDismissProgress');
        if (dropdown.classList.contains('show') && dropdown.contains(event.target) && progressBar) {
            // Pause the progress bar animation
            progressBar.style.animationPlayState = 'paused';
        }
    });

    document.addEventListener('mouseleave', function(event) {
        const dropdown = document.getElementById('profileDropdown');
        const progressBar = document.getElementById('autoDismissProgress');
        if (dropdown.classList.contains('show') && dropdown.contains(event.target) && progressBar) {
            // Resume the progress bar animation
            progressBar.style.animationPlayState = 'running';
        }
    });

    sidebarOverlay.addEventListener('click', closeSidebarMobile);

    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.addEventListener('click', () => {
            closeSidebarMobile();
        });
    });

    // Initialize sidebar state on load
    window.addEventListener('DOMContentLoaded', () => {
        if (isMobile()) {
            sidebar.classList.add('collapsed');
            mainContainer.classList.add('sidebar-collapsed');
            closeSidebarBtn.style.display = 'block';
        } else {
            sidebar.classList.remove('collapsed');
            mainContainer.classList.remove('sidebar-collapsed');
            closeSidebarBtn.style.display = 'none';
        }
    });

    // Handle window resize for sidebar behavior
    window.addEventListener('resize', () => {
        if (isMobile()) {
            if (!sidebar.classList.contains('collapsed')) {
                 sidebar.classList.add('collapsed');
                 sidebarOverlay.classList.remove('active');
                 document.body.style.overflow = 'auto';
            }
            mainContainer.classList.add('sidebar-collapsed');
            closeSidebarBtn.style.display = 'block';
        } else {
            sidebar.classList.remove('collapsed');
            sidebarOverlay.classList.remove('active');
            document.body.style.overflow = 'hidden';
            mainContainer.classList.remove('sidebar-collapsed');
            closeSidebarBtn.style.display = 'none';
        }
    });

    // Prevent prod_user from accessing dashboard - Monitor iframe src changes
    <?php if ($userRole === 'prod_user'): ?>
    (function() {
        const mainFrame = document.getElementById('mainFrame');
        if (mainFrame) {
            // Check initial src on page load
            setTimeout(function() {
                if (mainFrame.src.includes('dashboard_overview') || mainFrame.src.includes('dashboard')) {
                    mainFrame.src = 'welcome.php';
                }
            }, 100);
            
            // Monitor for any src changes (e.g., from menu clicks)
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'attributes' && mutation.attributeName === 'src') {
                        const currentSrc = mainFrame.src;
                        if (currentSrc.includes('dashboard_overview') || currentSrc.includes('dashboard')) {
                            mainFrame.src = 'welcome.php';
                        }
                    }
                });
            });
            
            observer.observe(mainFrame, {
                attributes: true,
                attributeFilter: ['src']
            });
            
            // Also intercept navigation attempts via menu links
            document.querySelectorAll('a[href*="dashboard_overview"], a[href*="dashboard"]').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    mainFrame.src = 'welcome.php';
                });
            });
        }
    })();
    <?php endif; ?>

  </script>
</body>
</html>

