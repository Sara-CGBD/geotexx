<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'management', 'planning_user', 'finance_user'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$projectId = $_GET['project_id'] ?? '';

// Get projects with production and cost data
$query = "SELECT 
    p.*,
    (SELECT COUNT(*) FROM roll_entry WHERE project_id = p.id AND is_deleted = 0) as roll_count,
    (SELECT SUM(total_weight) FROM roll_entry WHERE project_id = p.id AND is_deleted = 0) as total_production,
    (SELECT COUNT(*) FROM fg_entry WHERE project_id = p.id AND is_deleted = 0) as fg_count,
    (SELECT SUM(passed_qty) FROM fg_entry WHERE project_id = p.id AND is_deleted = 0) as fg_produced,
    (SELECT SUM(cost) FROM bom WHERE product_id = p.id AND is_deleted = 0) as total_cost,
    (SELECT SUM(fg.received_qty * fg.unit_price) FROM fg WHERE fg.project_id = p.id AND fg.is_deleted = 0) as project_value,
    (SELECT SUM(fg.received_qty) FROM fg WHERE fg.project_id = p.id AND fg.is_deleted = 0) as total_produced_qty
FROM projects p
WHERE p.is_deleted = 0";

if ($projectId) {
    $query .= " AND p.id = ?";
}

$query .= " ORDER BY p.created_at DESC";

if ($projectId) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $projectId);
    $stmt->execute();
    $result = $stmt->get_result();
    $projects = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $projects = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
}

$projectList = $conn->query("SELECT id, project_name FROM projects WHERE is_deleted = 0 ORDER BY project_name")->fetch_all(MYSQLI_ASSOC);

// Calculate summary
$totalProjects = count($projects);
$totalProduction = array_sum(array_column($projects, 'total_production'));
$totalCost = array_sum(array_column($projects, 'project_value')); // Use project_value instead of total_cost
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Value Report</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f5f7fa; padding: 20px; color: #2c3e50; }
        .container { max-width: 1800px; margin: 0 auto; background: white; border-radius: 12px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        h1 { text-align: center; color: #34495e; margin-bottom: 10px; }
        .subtitle { text-align: center; color: #7f8c8d; margin-bottom: 30px; font-size: 0.95em; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 10px; padding: 25px; color: white; text-align: center; }
        .stat-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
        .stat-card.orange { background: linear-gradient(135deg,rgb(38, 3, 58) 0%, #e67e22 100%); }
        .stat-value { font-size: 2.5em; font-weight: bold; margin-bottom: 5px; word-break: break-word; overflow-wrap: break-word; }
        .stat-label { font-size: 0.9em; opacity: 0.95; }
        
        .filters { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-size: 0.85em; font-weight: 600; color: #555; margin-bottom: 5px; }
        .filter-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 0.9em; }
        .filter-btn { background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; }
        .filter-btn:hover { background: #2980b9; }
        .reset-btn { background: #95a5a6; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 0.9em; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; }
        tr:hover { background: #f8f9fa; }
        
        .badge { padding: 4px 10px; border-radius: 4px; font-size: 0.85em; font-weight: 600; }
        .badge-active { background: #d5f4e6; color: #27ae60; }
        .badge-completed { background: #e3f2fd; color: #1565c0; }
        .badge-inactive { background: #f5f5f5; color: #757575; }
        
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        
        @media print {
            @page {
                size: A4 landscape;
                margin: 10mm;
            }
            .filters, .export-btn { display: none; }
            body { background: white; padding: 0; }
            .container { max-width: 100%; padding: 10px; }
            h1, h2 { font-size: 1.2em; }
            .table-wrapper {
                overflow: visible;
                width: 100%;
            }
            table {
                width: 100% !important;
                min-width: auto !important;
                max-width: 100%;
                font-size: 9px;
                page-break-inside: avoid;
                break-inside: avoid;
            }
            th, td {
                padding: 4px 3px;
                font-size: 9px;
                white-space: normal;
            }
            th {
                font-size: 9px;
                position: static;
            }
            table thead {
                display: table-header-group;
            }
            table tbody {
                display: table-row-group;
            }
            table tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-project-diagram"></i> Project Value Report</h1>
    <p class="subtitle">Project value vs actual production and cost analysis</p>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value" id="total_projects"><?php echo $totalProjects; ?></div>
            <div class="stat-label">Total Projects</div>
        </div>
        <div class="stat-card green">
            <div class="stat-value" id="total_production"><?php echo number_format($totalProduction, 2); ?></div>
            <div class="stat-label">Total Production (kg)</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-value" id="total_cost">৳<?php echo number_format($totalCost, 2); ?></div>
            <div class="stat-label">Total Cost</div>
        </div>
    </div>
    
    <!-- Loading Message -->
    <div id="loadingMessage" style="display: none; text-align: center; padding: 20px; background: #e8f5e9; border-radius: 8px; margin: 20px 0;">
        <i class="fas fa-spinner fa-spin"></i> Loading data...
    </div>
    
    <form method="GET" action="">
        <div class="filters">
            <div class="filter-row">
                <div class="filter-group">
                    <label><i class="fas fa-project-diagram"></i> Project</label>
                    <select id="project_id" name="project_id" onchange="applyFilters()">
                        <option value="">All Projects</option>
                        <?php foreach ($projectList as $proj): ?>
                            <option value="<?php echo $proj['id']; ?>" 
                                <?php echo $projectId == $proj['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($proj['project_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="button" class="filter-btn" onclick="applyFilters()"><i class="fas fa-filter"></i> Apply</button>
                    <button type="button" class="filter-btn reset-btn" onclick="resetFilters()">Reset</button>
                </div>
            </div>
        </div>
    </form>
    
    <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print</button>
    <button onclick="exportToCSV()" class="export-btn" style="background: #e67e22;"><i class="fas fa-file-csv"></i> Export CSV</button>
    
    <?php if (count($projects) > 0): ?>
    <table id="projectTable">
        <thead>
            <tr>
                <th>#</th>
                <th>Project Name</th>
                <th>Status</th>
                <th>Roll Production (kg)</th>
                <th>FG Produced (pcs)</th>
                <th>Total Cost</th>
                <th>Belt Weight</th>
                <th>Created Date</th>
                <th>Description</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $counter = 1;
            foreach ($projects as $project): 
                $statusClass = $project['status'] == 'active' ? 'badge-active' : ($project['status'] == 'completed' ? 'badge-completed' : 'badge-inactive');
            ?>
                <tr>
                    <td><?php echo $counter++; ?></td>
                    <td><strong><?php echo htmlspecialchars($project['project_name']); ?></strong></td>
                    <td>
                        <span class="badge <?php echo $statusClass; ?>">
                            <?php echo ucfirst($project['status']); ?>
                        </span>
                    </td>
                    <td><?php echo number_format($project['total_production'] ?? 0, 2); ?> kg</td>
                    <td><?php echo number_format($project['fg_produced'] ?? 0); ?> pcs</td>
                    <td><?php echo number_format($project['total_cost'] ?? 0, 2); ?></td>
                    <td><?php echo number_format($project['belt_weight'], 2); ?></td>
                    <td><?php echo date('M d, Y', strtotime($project['created_at'])); ?></td>
                    <td>
                        <?php 
                        $projectValue = $project['project_value'] ?? 0;
                        $producedQty = $project['total_produced_qty'] ?? 0;
                        echo "Project Value: " . number_format($projectValue, 2) . " BDT | Produced Quantity: " . number_format($producedQty, 0);
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div style="text-align: center; padding: 60px; color: #95a5a6;">
        <i class="fas fa-folder-open" style="font-size: 4em; margin-bottom: 20px;"></i>
        <p style="font-size: 1.2em;">No projects found.</p>
    </div>
    <?php endif; ?>
</div>

<script>
function exportToCSV() {
    const table = document.getElementById('projectTable');
    if (!table) return;
    
    let csv = [];
    csv.push(['Project Value Report']);
    csv.push(['Generated: ' + new Date().toLocaleString()]);
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
    link.setAttribute('download', 'project_value_report_' + new Date().toISOString().slice(0,10) + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// AJAX functionality
function applyFilters() {
    const projectId = document.getElementById('project_id').value;
    
    const loadingMessage = document.getElementById('loadingMessage');
    loadingMessage.style.display = 'block';
    
    // Build API URL
    const params = new URLSearchParams();
    if (projectId) params.append('project_id', projectId);
    
    const apiUrl = `api/project_value_data.php?${params.toString()}`;
    
    fetch(apiUrl)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateSummaryCards(data.summary);
                updateTable(data.data);
            } else {
                alert('Error loading data: ' + (data.error || 'Unknown error'));
            }
            loadingMessage.style.display = 'none';
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to load data. Please try again.');
            loadingMessage.style.display = 'none';
        });
}

function updateSummaryCards(summary) {
    document.getElementById('total_projects').textContent = summary.total_projects;
    document.getElementById('total_production').textContent = summary.total_production;
    document.getElementById('total_cost').textContent = '৳' + summary.total_cost;
}

function updateTable(data) {
    const tbody = document.querySelector('#projectTable tbody');
    
    if (data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:20px;">No data found for selected filters</td></tr>';
        return;
    }
    
    tbody.innerHTML = data.map((row, index) => {
        const statusBadge = row.status === 'Active' ? 'badge-active' : 
                           row.status === 'Completed' ? 'badge-completed' : 'badge-inactive';
        
        return `
            <tr>
                <td>${index + 1}</td>
                <td>${row.project_name || 'N/A'}</td>
                <td><span class="badge ${statusBadge}">${row.status || 'N/A'}</span></td>
                <td>${parseFloat(row.total_production || 0).toLocaleString('en-BD', {minimumFractionDigits: 2})}</td>
                <td>${parseFloat(row.fg_produced || 0).toLocaleString('en-BD')}</td>
                <td>৳${parseFloat(row.total_cost || 0).toLocaleString('en-BD', {minimumFractionDigits: 2})}</td>
                <td>${parseFloat(row.belt_weight || 0).toLocaleString('en-BD', {minimumFractionDigits: 2})} kg</td>
                <td>${row.created_at ? new Date(row.created_at).toLocaleDateString('en-GB') : 'N/A'}</td>
                <td>${row.description || '-'}</td>
            </tr>
        `;
    }).join('');
}

function resetFilters() {
    document.getElementById('project_id').value = '';
    applyFilters();
}
</script>
</body>
</html>


