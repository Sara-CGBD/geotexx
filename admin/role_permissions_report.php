<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Only admin can access this report
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if ($user_role !== 'admin') {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>Only administrators can access the Role Permissions Report.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Define all roles and their permissions
$rolePermissions = [
    'admin' => [
        'name' => 'Administrator',
        'description' => 'Full system access with all privileges',
        'modules' => [
            'Dashboard Overview' => 'Full Access',
            'Admin Panel' => 'Full Access (User Management, Security, Email)',
            'Quality Control (QC)' => 'Full Access (Entry, Tests, Reports, Approval)',
            'Roll Production' => 'Full Access (Fiber, Roll, Transfer)',
            'Production' => 'Full Access (CNC, Sewing, Branding)',
            'Scrap/Waste' => 'Full Access (Entry, Reports)',
            'Finished Goods' => 'Full Access (Entry, Delivery, Reports)',
            'Recycle' => 'Full Access (Entry, Reports)',
            'Planning' => 'Full Access (Targets, Projects, BOM)',
            'Finance' => 'Full Access (BOM, Costing, Reports)',
            'Reports' => 'All Reports Accessible'
        ]
    ],
    'qc_inspector' => [
        'name' => 'QC Inspector',
        'description' => 'Quality control and inspection access',
        'modules' => [
            'Quality Control (QC)' => 'Full Access (QC Entry, All QC Tests)',
            'QC Reports' => 'View QC Inspection Report',
            'Scrap/Waste' => 'View Reports Only',
            'Roll Production' => 'No Access',
            'Admin Panel' => 'No Access',
            'Finance' => 'No Access'
        ]
    ],
    'production_user' => [
        'name' => 'Production User',
        'description' => 'Production operations and data entry',
        'modules' => [
            'Roll Production' => 'Full Access (Fiber, Roll, Transfer)',
            'Production' => 'Full Access (CNC, Sewing, Branding)',
            'Scrap/Waste' => 'Entry Access Only',
            'Recycle' => 'Entry Access Only',
            'Reports' => 'Production Reports Only',
            'Quality Control' => 'No Access',
            'Admin Panel' => 'No Access',
            'Finance' => 'No Access'
        ]
    ],
    'agm ops' => [
        'name' => 'AGM Operations',
        'description' => 'Operations management and QC approval',
        'modules' => [
            'Management KPI Dashboard' => 'Full Access',
            'Quality Control (QC)' => 'Full Access + Approval Rights',
            'QC Reports Dashboard' => 'Approve/Reject Reports',
            'Production Monitoring' => 'All Production Reports',
            'Roll Production' => 'View Reports',
            'Scrap/Waste' => 'View Reports',
            'Admin Panel' => 'No Access',
            'Data Entry' => 'No Access'
        ]
    ],
    'management' => [
        'name' => 'Management',
        'description' => 'View-only access to all modules for analysis',
        'modules' => [
            'Management KPI Dashboard' => 'Full Access',
            'Quality Control (QC)' => 'View All QC Reports',
            'Reports & Analytics' => 'All Reports (Read-Only)',
            'Production Data' => 'View Only',
            'Financial Data' => 'View Only',
            'Data Entry' => 'No Access',
            'User Management' => 'No Access'
        ]
    ],
    'tester' => [
        'name' => 'Lab Tester',
        'description' => 'Laboratory testing and QC test order entry',
        'modules' => [
            'Lab Testing' => 'Full Access (QC Test Order, All Tests)',
            'Lab Testing Scrap Entry' => 'Full Access (Lab Scrap Entry)',
            'Test Reports' => 'Submit Test Reports',
            'QC Module' => 'Testing Only (No QC Entry)',
            'Production' => 'No Access',
            'Reports' => 'No Access',
            'Admin Panel' => 'No Access'
        ]
    ],
    'checker' => [
        'name' => 'Lab Test Checker',
        'description' => 'Fabric pre-production testing and lab verification',
        'modules' => [
            'Fabric Pre-Production Test' => 'Full Access',
            'Lab Testing' => 'Verification Access',
            'All Other Modules' => 'No Access'
        ]
    ],
    'finance_user' => [
        'name' => 'Finance User',
        'description' => 'Financial operations and BOM management',
        'modules' => [
            'Finance' => 'Full Access (BOM, Costing, Cost Analysis)',
            'FG Stock Summary' => 'View Access',
            'Reports' => 'Financial Reports Only',
            'Production Data' => 'No Access',
            'User Management' => 'No Access'
        ]
    ],
    'planning_user' => [
        'name' => 'Planning User',
        'description' => 'Production planning and target management',
        'modules' => [
            'Planning' => 'Full Access (Targets, Projects, BOM)',
            'Reports' => 'Target vs Actual, Project Reports',
            'Production Data' => 'No Access',
            'Quality Control' => 'No Access',
            'Admin Panel' => 'No Access'
        ]
    ],
    'recycle_user' => [
        'name' => 'Recycle User',
        'description' => 'Recycling operations management',
        'modules' => [
            'Recycle' => 'Full Access (Recycle Entry)',
            'Scrap/Waste' => 'View Reports',
            'Recycled Material Reports' => 'Full Access',
            'Other Modules' => 'No Access'
        ]
    ],
    'store_user' => [
        'name' => 'Store User',
        'description' => 'Raw material store management and inventory',
        'modules' => [
            'Raw Material Store' => 'Full Access (Store Received Entry, Material Issue)',
            'Inventory Report' => 'Full Access',
            'Other Modules' => 'No Access',
            'Admin Panel' => 'No Access'
        ]
    ],
    'delivery_user' => [
        'name' => 'Delivery User',
        'description' => 'Roll transfer and finished goods delivery management',
        'modules' => [
            'Roll Transfer' => 'Full Access (Transfer Entry, Log Reports)',
            'Finished Goods Delivery' => 'Full Access (FG Received, FG Delivery Entry, Reports)',
            'Other Modules' => 'No Access',
            'Admin Panel' => 'No Access'
        ]
    ]
];

// Get actual user counts from database
$userCounts = [];
$roleCountQuery = "SELECT role, COUNT(*) as count FROM new_user WHERE role != '' GROUP BY role";
$result = $conn->query($roleCountQuery);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $userCounts[strtolower(trim($row['role']))] = $row['count'];
    }
}

// Get total users
$totalUsersQuery = "SELECT COUNT(*) as total FROM new_user WHERE role != 'admin'";
$totalUsers = $conn->query($totalUsersQuery)->fetch_assoc()['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Role Permissions Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1800px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; padding: 25px; color: white; text-align: center; }
        .stat-card.blue { background: linear-gradient(135deg, #3498db 0%, #2980b9 100%); }
        .stat-value { font-size: 2.5em; font-weight: bold; margin-bottom: 5px; }
        .stat-label { font-size: 0.9em; opacity: 0.95; }
        
        .role-card { background: #f8f9fa; border-radius: 12px; padding: 25px; margin-bottom: 25px; border-left: 5px solid #3498db; }
        .role-card:nth-child(odd) { border-left-color: #9b59b6; }
        .role-card:nth-child(even) { border-left-color: #27ae60; }
        
        .role-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        .role-name { font-size: 1.5em; font-weight: 700; color: #2c3e50; }
        .role-badge { background: #3498db; color: white; padding: 5px 15px; border-radius: 20px; font-size: 0.85em; font-weight: 600; }
        .role-description { color: #7f8c8d; margin-bottom: 20px; font-style: italic; }
        
        .permissions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; }
        .permission-item { background: white; padding: 15px; border-radius: 8px; border-left: 3px solid #27ae60; }
        .permission-item.no-access { border-left-color: #e74c3c; opacity: 0.6; }
        .permission-module { font-weight: 600; color: #34495e; margin-bottom: 5px; }
        .permission-level { color: #7f8c8d; font-size: 0.9em; }
        
        .export-btn { background: #27ae60; color: white; border: none; padding: 12px 25px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; font-size: 1em; }
        .export-btn:hover { background: #229954; transform: translateY(-2px); transition: all 0.3s; }
        .export-btn.pdf { background: #e74c3c; }
        .export-btn.pdf:hover { background: #c0392b; }
        
        .table-wrapper { overflow-x: auto; margin: 30px 0; -webkit-overflow-scrolling: touch; }
        .summary-table { width: 100%; border-collapse: collapse; min-width: 800px; }
        .summary-table th, .summary-table td { padding: 12px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        .summary-table th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; }
        .summary-table tr:hover { background: #f8f9fa; }
        
        .access-full { color: #27ae60; font-weight: 600; }
        .access-partial { color: #f39c12; font-weight: 600; }
        .access-none { color: #e74c3c; font-weight: 600; }
        
        /* Tablet/Medium Screen Responsive Styles */
        @media (max-width: 1024px) {
            .summary-table th, .summary-table td { 
                padding: 8px 6px; 
                font-size: 0.85em; 
            }
            .summary-table { min-width: 700px; }
            .access-full, .access-partial, .access-none {
                font-size: 0.8em;
                white-space: nowrap;
            }
            .container { padding: 20px; }
        }
        
        @media (max-width: 768px) {
            .summary-table th, .summary-table td { 
                padding: 6px 4px; 
                font-size: 0.75em; 
            }
            .summary-table { min-width: 600px; }
            .access-full, .access-partial, .access-none {
                font-size: 0.75em;
            }
            .container { padding: 15px; }
            .role-name { font-size: 1.2em; }
            .permissions-grid { grid-template-columns: 1fr; }
        }
        
        @media print {
            .export-btn { display: none; }
            body { background: white; padding: 0; }
            .role-card { page-break-inside: avoid; }
            .table-wrapper { overflow-x: visible; }
            .summary-table { min-width: 100%; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-user-shield"></i> Role Permissions Report</h1>
    <p class="subtitle">Current access rights and permissions for each user role in the system</p>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo count($rolePermissions); ?></div>
            <div class="stat-label">Total Roles</div>
        </div>
        <div class="stat-card blue">
            <div class="stat-value"><?php echo $totalUsers; ?></div>
            <div class="stat-label">Active Users</div>
        </div>
    </div>
    
    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print Report</button>
    <button onclick="exportToPDF()" class="export-btn pdf"><i class="fas fa-file-pdf"></i> Export to PDF</button>
    <button onclick="exportToCSV()" class="export-btn" style="background: #3498db;"><i class="fas fa-file-csv"></i> Export CSV</button>
    
    <!-- Quick Summary Table -->
    <h2 style="font-size: 1.4em; color: #34495e; margin: 30px 0 20px 0; padding-bottom: 10px; border-bottom: 3px solid #3498db;">
        <i class="fas fa-table"></i> Roles Summary
    </h2>
    <div class="table-wrapper">
    <table class="summary-table" id="summaryTable">
        <thead>
            <tr>
                <th>#</th>
                <th>Role Name</th>
                <th>Active Users</th>
                <th>Primary Access</th>
                <th>Data Entry</th>
                <th>Reports</th>
                <th>Admin Panel</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $counter = 1;
            foreach ($rolePermissions as $roleKey => $role): 
                $userCount = $userCounts[$roleKey] ?? 0;
            ?>
                <tr>
                    <td><?php echo $counter++; ?></td>
                    <td><strong><?php echo $role['name']; ?></strong></td>
                    <td><?php echo $userCount; ?></td>
                    <td>
                        <?php if (strpos($roleKey, 'admin') !== false): ?>
                            <span class="access-full">✓ Full Access</span>
                        <?php elseif (strpos($roleKey, 'management') !== false || strpos($roleKey, 'agm') !== false): ?>
                            <span class="access-partial">◐ View/Monitor</span>
                        <?php else: ?>
                            <span class="access-partial">◐ Module Specific</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (in_array($roleKey, ['admin', 'production_user', 'qc_inspector', 'tester', 'checker', 'finance_user', 'planning_user', 'store_user', 'delivery_user'])): ?>
                            <span class="access-full">✓ Yes</span>
                        <?php else: ?>
                            <span class="access-none">✗ No</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (in_array($roleKey, ['admin', 'management', 'agm ops'])): ?>
                            <span class="access-full">✓ All Reports</span>
                        <?php elseif (in_array($roleKey, ['production_user', 'qc_inspector', 'finance_user', 'planning_user'])): ?>
                            <span class="access-partial">◐ Limited</span>
                        <?php else: ?>
                            <span class="access-none">✗ None</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($roleKey == 'admin'): ?>
                            <span class="access-full">✓ Full Access</span>
                        <?php else: ?>
                            <span class="access-none">✗ No Access</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    
    <!-- Detailed Role Cards -->
    <h2 style="font-size: 1.4em; color: #34495e; margin: 40px 0 20px 0; padding-bottom: 10px; border-bottom: 3px solid #3498db;">
        <i class="fas fa-list-alt"></i> Detailed Permissions
    </h2>
    
    <?php foreach ($rolePermissions as $roleKey => $role): 
        $userCount = $userCounts[$roleKey] ?? 0;
    ?>
        <div class="role-card">
            <div class="role-header">
                <div class="role-name"><i class="fas fa-user"></i> <?php echo $role['name']; ?></div>
                <div class="role-badge"><?php echo $userCount; ?> User<?php echo $userCount != 1 ? 's' : ''; ?></div>
            </div>
            <div class="role-description"><?php echo $role['description']; ?></div>
            
            <div class="permissions-grid">
                <?php foreach ($role['modules'] as $module => $access): 
                    $isNoAccess = (strpos(strtolower($access), 'no access') !== false);
                ?>
                    <div class="permission-item <?php echo $isNoAccess ? 'no-access' : ''; ?>">
                        <div class="permission-module">
                            <?php if (!$isNoAccess): ?>
                                <i class="fas fa-check-circle" style="color: #27ae60;"></i>
                            <?php else: ?>
                                <i class="fas fa-times-circle" style="color: #e74c3c;"></i>
                            <?php endif; ?>
                            <?php echo $module; ?>
                        </div>
                        <div class="permission-level"><?php echo $access; ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    
    <div style="margin-top: 40px; padding: 20px; background: #e8f5e9; border-radius: 8px; border-left: 5px solid #27ae60;">
        <h3 style="color: #27ae60; margin-bottom: 10px;"><i class="fas fa-info-circle"></i> Report Information</h3>
        <p style="color: #555; line-height: 1.6;">
            <strong>Generated:</strong> <?php echo date('F d, Y g:i A'); ?><br>
            <strong>Total Roles:</strong> <?php echo count($rolePermissions); ?><br>
            <strong>Total Active Users:</strong> <?php echo $totalUsers; ?><br>
            <strong>System:</strong> GEOCIL Automation System - Role-Based Access Control (RBAC)
        </p>
    </div>
</div>

<script>
function exportToPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('l', 'mm', 'a4'); // Landscape orientation
    
    // Add title
    doc.setFontSize(18);
    doc.setTextColor(52, 73, 94);
    doc.text('Role Permissions Report', 148, 20, { align: 'center' });
    
    doc.setFontSize(10);
    doc.setTextColor(127, 140, 141);
    doc.text('GEOCIL Automation System - Generated: <?php echo date('M d, Y g:i A'); ?>', 148, 27, { align: 'center' });
    
    // Prepare table data
    const tableData = [];
    <?php foreach ($rolePermissions as $roleKey => $role): ?>
        tableData.push([
            '<?php echo addslashes($role['name']); ?>',
            '<?php echo $userCounts[$roleKey] ?? 0; ?>',
            '<?php echo addslashes($role['description']); ?>',
            '<?php echo addslashes(implode(", ", array_keys($role['modules']))); ?>'
        ]);
    <?php endforeach; ?>
    
    // Add table
    doc.autoTable({
        startY: 35,
        head: [['Role Name', 'Users', 'Description', 'Primary Modules']],
        body: tableData,
        theme: 'grid',
        headStyles: { fillColor: [52, 73, 94], textColor: 255 },
        styles: { fontSize: 8, cellPadding: 3 },
        columnStyles: {
            0: { cellWidth: 40 },
            1: { cellWidth: 15, halign: 'center' },
            2: { cellWidth: 80 },
            3: { cellWidth: 'auto' }
        }
    });
    
    // Save PDF
    doc.save('role_permissions_report_' + new Date().toISOString().slice(0,10) + '.pdf');
}

function exportToCSV() {
    const table = document.getElementById('summaryTable');
    let csv = [];
    
    csv.push(['Role Permissions Report']);
    csv.push(['GEOCIL Automation System']);
    csv.push(['Generated: <?php echo date('F d, Y g:i A'); ?>']);
    csv.push([]);
    
    const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent);
    csv.push(headers.join(','));
    
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const cols = Array.from(row.querySelectorAll('td')).map(td => {
            let text = td.textContent.trim();
            if (text.includes(',') || text.includes('"')) {
                text = '"' + text.replace(/"/g, '""') + '"';
            }
            return text;
        });
        csv.push(cols.join(','));
    });
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', 'role_permissions_report_' + new Date().toISOString().slice(0,10) + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>


