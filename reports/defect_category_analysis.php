<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'qc_inspector', 'management', 'agm ops'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$stage = $_GET['stage'] ?? '';
$projectId = $_GET['project_id'] ?? '';

// Fetch filter options
$stages = $conn->query("SELECT DISTINCT qc_stage FROM qc_entries WHERE qc_stage IS NOT NULL ORDER BY qc_stage")->fetch_all(MYSQLI_ASSOC);
$projects = $conn->query("SELECT id, project_name FROM projects ORDER BY project_name")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Defect Category Analysis</title>
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
        .stat-card { background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); border-radius: 10px; padding: 25px; color: white; text-align: center; }
        .stat-card.orange { background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%); }
        .stat-card.purple { background: linear-gradient(135deg, #8e44ad 0%, #9b59b6 100%); }
        .stat-value { font-size: 2.5em; font-weight: bold; margin-bottom: 5px; }
        .stat-label { font-size: 0.9em; opacity: 0.95; }
        
        .filters { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label { font-size: 0.85em; font-weight: 600; color: #555; margin-bottom: 5px; }
        .filter-group input, .filter-group select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px; font-size: 0.9em; }
        .filter-btn { background: #3498db; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; }
        .filter-btn:hover { background: #2980b9; }
        .reset-btn { background: #95a5a6; }
        
        .charts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(450px, 1fr)); gap: 25px; margin-bottom: 30px; }
        .chart-card { background: #f8f9fa; border-radius: 12px; padding: 25px; }
        .chart-title { font-size: 1.2em; font-weight: 700; color: #2c3e50; margin-bottom: 20px; text-align: center; }
        .chart-container { position: relative; height: 350px; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 0.9em; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ecf0f1; }
        th { background: #34495e; color: white; font-weight: 600; position: sticky; top: 0; }
        tr:hover { background: #f8f9fa; }
        
        .export-btn { background: #27ae60; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: 600; margin-bottom: 20px; margin-right: 10px; }
        
        @media print {
            .filters, .export-btn { display: none; }
            body { background: white; padding: 0; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1><i class="fas fa-chart-pie"></i> Defect Category Analysis</h1>
    <p class="subtitle">Type of defects found across stages and projects</p>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value" id="totalDefects">0</div>
            <div class="stat-label">Total Defects</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-value" id="stageCount">0</div>
            <div class="stat-label">Defect Stages</div>
        </div>
        <div class="stat-card purple">
            <div class="stat-value" id="typeCount">0</div>
            <div class="stat-label">Defect Types</div>
        </div>
    </div>
    
    <form id="filterForm" onsubmit="return false;">
        <div class="filters">
            <div class="filter-row">
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> From Date</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" required>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-calendar"></i> To Date</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" required>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-layer-group"></i> Stage</label>
                    <select name="stage">
                        <option value="">All Stages</option>
                        <?php foreach ($stages as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['qc_stage']); ?>" 
                                <?php echo $stage == $s['qc_stage'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['qc_stage']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label><i class="fas fa-project-diagram"></i> Project</label>
                    <select name="project_id">
                        <option value="">All Projects</option>
                        <?php foreach ($projects as $proj): ?>
                            <option value="<?php echo $proj['id']; ?>" 
                                <?php echo $projectId == $proj['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($proj['project_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="button" onclick="applyFilters()" class="filter-btn"><i class="fas fa-filter"></i> Apply Filter</button>
                    <button type="button" onclick="resetFilters()" class="filter-btn reset-btn">Reset</button>
                </div>
            </div>
        </div>
    </form>
    
    <div id="loadingMessage" style="text-align:center; padding:40px; display:none;">
        <i class="fas fa-spinner fa-spin" style="font-size:3em; color:#3498db;"></i>
        <p style="margin-top:15px; font-size:1.1em; color:#7f8c8d;">Loading data...</p>
    </div>
    
    <div id="contentArea">
        <!-- Content will be loaded dynamically via AJAX -->
    </div>
</div>

<script>
let stageChart, typeChart, projectChart;

function exportToCSV() {
    const table = document.getElementById('defectTable');
    if (!table) return;
    
    const dateFrom = document.querySelector('input[name="date_from"]').value;
    const dateTo = document.querySelector('input[name="date_to"]').value;
    const totalDefects = table.querySelectorAll('tbody tr').length;
    
    let csv = [];
    csv.push(['Defect Category Analysis Report']);
    csv.push([`Period: ${dateFrom} to ${dateTo}`]);
    csv.push([`Total Defects: ${totalDefects}`]);
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
    link.setAttribute('download', 'defect_category_analysis_' + new Date().toISOString().slice(0,10) + '.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function applyFilters() {
    const dateFrom = document.querySelector('input[name="date_from"]').value;
    const dateTo = document.querySelector('input[name="date_to"]').value;
    const stage = document.querySelector('select[name="stage"]').value;
    const projectId = document.querySelector('select[name="project_id"]').value;
    
    const loadingMsg = document.getElementById('loadingMessage');
    const contentArea = document.getElementById('contentArea');
    
    loadingMsg.style.display = 'block';
    contentArea.style.display = 'none';
    
    const params = new URLSearchParams({
        date_from: dateFrom,
        date_to: dateTo,
        stage: stage,
        project_id: projectId
    });
    
    fetch(`api/defect_category_analysis_data.php?${params.toString()}`)
        .then(response => response.json())
        .then(result => {
            loadingMsg.style.display = 'none';
            contentArea.style.display = 'block';
            
            if (result.success) {
                renderContent(result.data);
            } else {
                contentArea.innerHTML = '<p style="color:red; text-align:center;">Error loading data: ' + (result.message || result.error) + '</p>';
            }
        })
        .catch(error => {
            loadingMsg.style.display = 'none';
            contentArea.style.display = 'block';
            contentArea.innerHTML = '<p style="color:red; text-align:center;">Error: ' + error.message + '</p>';
        });
}

function resetFilters() {
    document.querySelector('input[name="date_from"]').value = '<?php echo date('Y-m-d', strtotime('-30 days')); ?>';
    document.querySelector('input[name="date_to"]').value = '<?php echo date('Y-m-d'); ?>';
    document.querySelector('select[name="stage"]').value = '';
    document.querySelector('select[name="project_id"]').value = '';
    applyFilters();
}

function renderContent(data) {
    const contentArea = document.getElementById('contentArea');
    
    // Update stats cards
    document.getElementById('totalDefects').textContent = data.total_defects || 0;
    document.getElementById('stageCount').textContent = data.stage_count || 0;
    document.getElementById('typeCount').textContent = data.type_count || 0;
    
    if (!data.defects || data.defects.length === 0) {
        contentArea.innerHTML = `
            <div style="text-align: center; padding: 60px; color: #95a5a6;">
                <i class="fas fa-check-circle" style="font-size: 4em; margin-bottom: 20px;"></i>
                <p style="font-size: 1.2em;">No defects found in the selected period. Great job!</p>
            </div>
        `;
        return;
    }
    
    // Render content with charts and table
    contentArea.innerHTML = `
        <button onclick="window.print()" class="export-btn"><i class="fas fa-print"></i> Print</button>
        <button onclick="exportToCSV()" class="export-btn" style="background: #e67e22;"><i class="fas fa-file-csv"></i> Export CSV</button>
        
        <div id="chartsSection" class="charts-grid">
            <div class="chart-card">
                <div class="chart-title">Defects by Stage</div>
                <div class="chart-container">
                    <canvas id="stageChart"></canvas>
                </div>
            </div>
            
            <div class="chart-card">
                <div class="chart-title">Defects by Type</div>
                <div class="chart-container">
                    <canvas id="typeChart"></canvas>
                </div>
            </div>
            
            <div class="chart-card">
                <div class="chart-title">Top Projects with Defects</div>
                <div class="chart-container">
                    <canvas id="projectChart"></canvas>
                </div>
            </div>
        </div>
        
        <h2 style="font-size: 1.3em; color: #34495e; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 3px solid #3498db;">
            <i class="fas fa-list"></i> Detailed Defect Records
        </h2>
        <table id="defectTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>QC ID</th>
                    <th>Date & Time</th>
                    <th>Stage</th>
                    <th>Type</th>
                    <th>Project</th>
                    <th>Inspector</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                ${data.defects.map((defect, index) => `
                    <tr>
                        <td>${index + 1}</td>
                        <td><strong>${defect.qc_id}</strong></td>
                        <td>${defect.date_time}</td>
                        <td>${defect.stage || 'N/A'}</td>
                        <td>${defect.type || 'N/A'}</td>
                        <td>${defect.project_name || 'No Project'}</td>
                        <td>${defect.inspector_name || 'Unknown'}</td>
                        <td>${defect.remarks || ''}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
    
    // Destroy old charts
    if (stageChart) stageChart.destroy();
    if (typeChart) typeChart.destroy();
    if (projectChart) projectChart.destroy();
    
    // Create new charts
    const stageCtx = document.getElementById('stageChart');
    stageChart = new Chart(stageCtx, {
        type: 'doughnut',
        data: {
            labels: Object.keys(data.by_stage || {}),
            datasets: [{
                data: Object.values(data.by_stage || {}),
                backgroundColor: ['#e74c3c', '#f39c12', '#3498db', '#9b59b6', '#1abc9c', '#e67e22']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'right' } }
        }
    });
    
    const typeCtx = document.getElementById('typeChart');
    typeChart = new Chart(typeCtx, {
        type: 'bar',
        data: {
            labels: Object.keys(data.by_type || {}),
            datasets: [{
                label: 'Defect Count',
                data: Object.values(data.by_type || {}),
                backgroundColor: 'rgba(231, 76, 60, 0.7)',
                borderColor: '#e74c3c',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true } }
        }
    });
    
    const projectCtx = document.getElementById('projectChart');
    const topProjects = Object.entries(data.by_project || {}).slice(0, 10);
    projectChart = new Chart(projectCtx, {
        type: 'bar',
        data: {
            labels: topProjects.map(p => p[0]),
            datasets: [{
                label: 'Defect Count',
                data: topProjects.map(p => p[1]),
                backgroundColor: 'rgba(155, 89, 182, 0.7)',
                borderColor: '#9b59b6',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: { x: { beginAtZero: true } }
        }
    });
}

// Load data on page load
window.addEventListener('DOMContentLoaded', function() {
    applyFilters();
});
</script>
</body>
</html>


