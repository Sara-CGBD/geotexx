<?php
// System Analysis and File Categorization
echo "<h2>GEOCIL Automation System Analysis</h2>";

$files = [
    // Core System Files
    'core' => [
        'index.php' => 'Main dashboard with hamburger menu',
        'login.php' => 'Main login handler',
        'login.html' => 'Login form',
        'logout.php' => 'Logout handler',
        'config.php' => 'Database configuration',
        'security_config.php' => 'Security configuration',
        'get_real_ip.php' => 'IP detection utility'
    ],
    
    // Dashboard Files
    'dashboard' => [
        'all_dashboard.php' => 'Main dashboard content',
        'iframe_form_header.php' => 'Iframe form header template',
        'iframe_form_footer.php' => 'Iframe form footer template',
        'form_header.php' => 'Full form header template',
        'form_footer.php' => 'Full form footer template'
    ],
    
    // Entry Forms (Production)
    'entry_forms' => [
        'fiber_entry.php' => 'Fiber entry form (full layout)',
        'fiber_entry_iframe.php' => 'Fiber entry form (iframe)',
        'roll_entry.php' => 'Roll entry form (full layout)',
        'roll_entry_iframe.php' => 'Roll entry form (iframe)',
        'cnc_entry.php' => 'CNC entry form',
        'scrap_entry.php' => 'Scrap entry form',
        'fg_entry.php' => 'Finished goods entry form',
        'branding_entry.php' => 'Branding entry form',
        'project_entry.php' => 'Project entry form',
        'BOM_entry.php' => 'Bill of Materials entry form',
        'production_entry.php' => 'Production entry form',
        'fiber_to_entry.php' => 'Fiber to roll entry form',
        'roll_received_entry.php' => 'Roll received entry form',
        'roll_transfer_entry.php' => 'Roll transfer entry form',
        'fg_delivery_entry.php' => 'FG delivery entry form',
        'fg_received_entry' => 'FG received entry form',
        'scrap_recycle_entry.php' => 'Scrap recycle entry form',
        'swing_machine_entry.php' => 'Swing machine entry form',
        'cnc_machine_entry.php' => 'CNC machine entry form'
    ],
    
    // Submit Handlers
    'submit_handlers' => [
        'submit_fiber_entry.php' => 'Fiber entry submission handler',
        'submit_roll_entry.php' => 'Roll entry submission handler',
        'submit_scrap_entry.php' => 'Scrap entry submission handler',
        'submit_bom_entry.php' => 'BOM entry submission handler'
    ],
    
    // User Management
    'user_management' => [
        'user_create_new_user.php' => 'Create new user form',
        'user_management_new_user.php' => 'User management interface',
        'user_management.php' => 'User management (old)',
        'create_new_user.php' => 'Create user (old)',
        'create_simple_user.php' => 'Create simple user',
        'add_user.php' => 'Add user utility',
        'change_password.php' => 'Change password form'
    ],
    
    // Database & Setup
    'database' => [
        'create_tables.sql' => 'Database schema',
        'setup_database.php' => 'Database setup script',
        'create_test_user.php' => 'Test user creation',
        'add_user_direct.sql' => 'Direct user addition SQL',
        'add_user_to_users_table.php' => 'Add user to users table'
    ],
    
    // Testing & Debug Files
    'testing_debug' => [
        'test_db.php' => 'Database test',
        'testdb_connection.php' => 'Database connection test',
        'test.php' => 'General test file',
        'test_original_login.php' => 'Original login test',
        'test_secure_login.php' => 'Secure login test',
        'debug_index.php' => 'Index debug',
        'debug_login.php' => 'Login debug',
        'debug_session.php' => 'Session debug',
        'check_admin.php' => 'Admin check',
        'check_available_table.php' => 'Table availability check',
        'check_databasetable.php' => 'Database table check',
        'check_db_structure.php' => 'Database structure check',
        'check_users_simple.php' => 'Simple users check',
        'check_users_table.php' => 'Users table check',
        'verify_db.php' => 'Database verification',
        'audit_log.php' => 'Audit logging',
        'menu_code_extract.php' => 'Menu code extraction'
    ],
    
    // Utility Files
    'utilities' => [
        'generate_secure_password.php' => 'Password generation utility',
        'fix_passwords.php' => 'Password fix utility',
        'update_password_hashes.php' => 'Password hash update',
        'reset_login_attempts.php' => 'Login attempts reset',
        'set_target_module.php' => 'Target module setting',
        'form.php' => 'Generic form',
        'manu.php' => 'Manufacturing page',
        'simple_index.php' => 'Simple index page',
        'login_simple.php' => 'Simple login page'
    ]
];

// Display analysis
foreach ($files as $category => $fileList) {
    echo "<h3>" . ucfirst(str_replace('_', ' ', $category)) . " (" . count($fileList) . " files)</h3>";
    echo "<ul>";
    foreach ($fileList as $file => $description) {
        $exists = file_exists($file) ? "✅" : "❌";
        echo "<li>$exists <strong>$file</strong> - $description</li>";
    }
    echo "</ul>";
}

// Recommendations
echo "<h3>Recommendations:</h3>";
echo "<ol>";
echo "<li><strong>Delete Testing/Debug Files:</strong> Remove all test_*, debug_*, check_* files</li>";
echo "<li><strong>Consolidate Entry Forms:</strong> Keep only iframe versions for Quick Actions</li>";
echo "<li><strong>Organize into Folders:</strong> Create proper directory structure</li>";
echo "<li><strong>Remove Duplicates:</strong> Clean up duplicate functionality</li>";
echo "<li><strong>Standardize Naming:</strong> Use consistent naming conventions</li>";
echo "</ol>";
?>

