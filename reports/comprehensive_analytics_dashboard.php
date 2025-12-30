<?php
session_start();
require_once '../forms/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Role-based access control
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'management', 'agm ops', 'agm operations', 'finance'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("Access Denied");
}

date_default_timezone_set('Asia/Dhaka');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Comprehensive Analytics Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    body {
      font-family: 'Inter', sans-serif;
      background-color: #f4f6f9;
      margin: 0;
      padding: 30px 20px;
      color: #2c3e50;
    }
    .container {
      max-width: 1600px;
      margin: auto;
      background: #fff;
      border-radius: 12px;
      padding: 30px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    }
    h1 {
      text-align: center;
      font-size: 28px;
      margin-bottom: 30px;
      color: #34495e;
    }
    .report-header-info {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      font-size: 14px;
      color: #555;
      padding: 10px 0;
      border-bottom: 1px solid #eee;
    }
    .report-header-info div {
      flex: 1;
      text-align: center;
    }
    .report-header-info div:first-child { text-align: left; }
    .report-header-info div:last-child { text-align: right; }
    
    .summary-cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }
    .summary-card {
      background-color: #eaf6ff;
      border: 1px solid #cce5ff;
      border-radius: 10px;
      padding: 20px;
      text-align: center;
      box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    }
    .summary-card .label {
      font-size: 0.95em;
      color: #555;
      margin-bottom: 8px;
    }
    .summary-card .value {
      font-size: 2em;
      font-weight: 700;
      color: #3498db;
    }
    .summary-card.green {
      background-color: #e6ffe6;
      border-color: #b3ffb3;
    }
    .summary-card.green .value { color: #28a745; }
    .summary-card.red {
      background-color: #ffe6e6;
      border-color: #ffb3b3;
    }
    .summary-card.red .value { color: #dc3545; }
    .summary-card.orange {
      background-color: #fff8e6;
      border-color: #ffe0b3;
    }
    .summary-card.orange .value { color: #ffc107; }
    .summary-card.purple {
      background-color: #f3e6ff;
      border-color: #e0b3ff;
    }
    .summary-card.purple .value { color: #6f42c1; }
    
    .filter-section {
      display: flex;
      flex-wrap: wrap;
      gap: 15px;
      margin-bottom: 30px;
      padding: 20px;
      border: 1px solid #e0e0e0;
      border-radius: 10px;
      background-color: #fcfcfc;
      align-items: flex-end;
    }
    .filter-group {
      flex: 1;
      min-width: 180px;
    }
    .filter-group label {
      font-weight: 600;
      display: block;
      margin-bottom: 8px;
      font-size: 0.9em;
    }
    .filter-group input[type="date"],
    .filter-group select {
      width: 100%;
      padding: 10px;
      border: 1px solid #ccc;
      border-radius: 8px;
      box-sizing: border-box;
      font-family: 'Inter', sans-serif;
    }
    .filter-actions {
      display: flex;
      gap: 10px;
    }
    .filter-actions button {
      padding: 10px 20px;
      font-size: 15px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      transition: background-color 0.2s;
      font-weight: 600;
    }
    .apply-btn { background-color: #3498db; color: white; }
    .apply-btn:hover { background-color: #2980b9; }
    .reset-btn { background-color: #e0e0e0; color: #333; }
    .reset-btn:hover { background-color: #ccc; }
    .download-csv-btn { background-color: #28a745; color: white; }
    .download-csv-btn:hover { background-color: #218838; }
    
    .excel-report-container {
      margin-top: 30px;
      padding: 20px;
      background-color: #fff;
      border-radius: 12px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.08);
      overflow-x: auto;
    }
    .excel-report-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
      min-width: 1400px;
      border: 1px solid #a0a0a0;
    }
    .excel-report-table th, .excel-report-table td {
      border: 1px solid #d0d0d0;
      padding: 10px 12px;
      vertical-align: middle;
      white-space: nowrap;
    }
    .excel-report-table thead th {
      background-color: #34495e;
      font-weight: 600;
      text-align: center;
      color: white;
      font-size: 12px;
    }
    .excel-report-table thead th.main-header {
      background-color: #5dade2;
      color: #000;
      font-size: 14px;
    }
    .excel-report-table tbody tr:nth-child(even) {
      background-color: #f9f9f9;
    }
    .excel-report-table tbody tr:hover {
      background-color: #e3f2fd;
    }
    .excel-report-table tbody td:first-child {
      font-weight: 600;
    }
    .excel-report-table .total-row {
      background-color: #dbe9f5;
      font-weight: bold;
    }
    .excel-report-table .total-row td {
      border-top: 2px solid #a0a0a0;
    }
    .excel-report-table .text-right {
      text-align: right;
    }
    .excel-report-table .text-center {
      text-align: center;
    }
    
    .chart-container {
      margin-top: 30px;
      padding: 20px;
      background-color: #fff;
      border-radius: 12px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.08);
      height: 400px;
      display: flex;
      justify-content: center;
      align-items: center;
      position: relative;
    }
    .chart-container canvas {
      max-width: 100%;
      max-height: 100%;
    }
    .no-chart-data-message {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      color: #888;
      font-size: 1.2em;
      text-align: center;
      padding: 10px;
      background-color: rgba(255, 255, 255, 0.9);
      border-radius: 8px;
    }
    
    .message-box {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.5);
      justify-content: center;
      align-items: center;
      z-index: 1000;
    }
    .message-box-content {
      background: white;
      padding: 20px;
      border-radius: 8px;
      box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
      text-align: center;
      max-width: 300px;
    }
    .message-box-content p {
      margin-bottom: 15px;
      font-size: 16px;
    }
    .message-box-content button {
      background-color: #3498db;
      color: white;
      padding: 8px 15px;
      border: none;
      border-radius: 5px;
      cursor: pointer;
    }
    
    @media (max-width: 768px) {
      .filter-section {
        flex-direction: column;
        align-items: stretch;
      }
      .filter-actions {
        flex-direction: column;
      }
      .filter-actions button {
        width: 100%;
      }
      .summary-cards-grid {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      }
      .excel-report-table {
        font-size: 11px;
      }
    }
  </style>
</head>
<body>
<div class="container">
  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
  </div>
  <h1><i class="fas fa-chart-line"></i> Comprehensive Analytics Dashboard</h1>
  
  <div class="report-header-info">
    <div>Start Date: <strong id="displayStartDate">N/A</strong></div>
    <div>End Date: <strong id="displayEndDate">N/A</strong></div>
    <div id="currentDateTime">Now: Loading...</div>
  </div>
  
  <div class="summary-cards-grid">
    <div class="summary-card">
      <div class="label">Total Production (pcs):</div>
      <div class="value" id="totalProductionPcs">0</div>
    </div>
    <div class="summary-card green">
      <div class="label">Production Value (৳ Cr):</div>
      <div class="value" id="productionValueCrTk">0.00</div>
    </div>
    <div class="summary-card orange">
      <div class="label">QC Pass Rate (%):</div>
      <div class="value" id="qcPassPercentage">0%</div>
    </div>
    <div class="summary-card">
      <div class="label">Total Delivered (pcs):</div>
      <div class="value" id="totalDeliveredPcs">0</div>
    </div>
    <div class="summary-card green">
      <div class="label">Delivery Value (৳ Cr):</div>
      <div class="value" id="deliveryValueCrTk">0.00</div>
    </div>
    <div class="summary-card purple">
      <div class="label">Current Stock (pcs):</div>
      <div class="value" id="currentStock">0</div>
    </div>
    <div class="summary-card red">
      <div class="label">Total Scrap (kg):</div>
      <div class="value" id="totalScrapKg">0</div>
    </div>
    <div class="summary-card orange">
      <div class="label">Scrap Loss (৳):</div>
      <div class="value" id="scrapLossValue">0</div>
    </div>
  </div>
  
  <div class="filter-section">
    <div class="filter-group">
      <label for="startDate"><i class="fas fa-calendar"></i> Start Date:</label>
      <input type="date" id="startDate">
    </div>
    <div class="filter-group">
      <label for="endDate"><i class="fas fa-calendar"></i> End Date:</label>
      <input type="date" id="endDate">
    </div>
    <div class="filter-group">
      <label for="projectFilter"><i class="fas fa-project-diagram"></i> Project:</label>
      <select id="projectFilter">
        <option value="">All Projects</option>
      </select>
    </div>
    <div class="filter-actions">
      <button class="apply-btn" onclick="fetchDashboardData()"><i class="fas fa-filter"></i> Apply Filters</button>
      <button class="reset-btn" onclick="resetFilters()"><i class="fas fa-redo"></i> Reset</button>
      <button class="download-csv-btn" onclick="downloadCsvReport()"><i class="fas fa-download"></i> Download CSV</button>
    </div>
  </div>
  
  <div class="excel-report-container">
    <table class="excel-report-table" id="excelReportTable">
      <thead>
        <tr>
          <th rowspan="2">Project Name</th>
          <th colspan="2" class="main-header">Production</th>
          <th colspan="3" class="main-header">Quality (QC)</th>
          <th colspan="2" class="main-header">Delivery</th>
          <th colspan="2" class="main-header">Scrap/Waste</th>
          <th rowspan="2">Current Stock</th>
          <th rowspan="2">Stock Value (৳)</th>
        </tr>
        <tr>
          <th>Quantity (pcs)</th>
          <th>Value (৳ Cr)</th>
          <th>Inspected</th>
          <th>Pass %</th>
          <th>Fail %</th>
          <th>Quantity (pcs)</th>
          <th>Value (৳ Cr)</th>
          <th>Scrap (kg)</th>
          <th>Loss (৳)</th>
        </tr>
      </thead>
      <tbody>
        <!-- Data will be populated by JavaScript -->
      </tbody>
    </table>
  </div>
  
  <div class="chart-container">
    <canvas id="trendChart"></canvas>
    <div id="noChartDataMessage" class="no-chart-data-message" style="display: none;">
      No chart data available for the selected filters.
    </div>
  </div>
</div>

<div class="message-box" id="messageBox">
  <div class="message-box-content">
    <p id="messageBoxText"></p>
    <button onclick="closeMessageBox()">OK</button>
  </div>
</div>

<script>
  let trendChart;
  let currentReportData = [];

  function showMessageBox(message) {
    document.getElementById('messageBoxText').textContent = message;
    document.getElementById('messageBox').style.display = 'flex';
  }

  function closeMessageBox() {
    document.getElementById('messageBox').style.display = 'none';
  }

  function setDefaultDates() {
    const today = new Date();
    const endDate = today.toISOString().split('T')[0];
    const sevenDaysAgo = new Date(today);
    sevenDaysAgo.setDate(today.getDate() - 7);
    const startDate = sevenDaysAgo.toISOString().split('T')[0];
    document.getElementById('startDate').value = startDate;
    document.getElementById('endDate').value = endDate;
  }

  function populateFilterOptions(filters) {
    const projectSelect = document.getElementById('projectFilter');
    const currentProject = projectSelect.value;
    
    projectSelect.innerHTML = '<option value="">All Projects</option>';
    
    if (filters.projects) {
      filters.projects.forEach(project => {
        const option = document.createElement('option');
        option.value = project;
        option.textContent = project;
        projectSelect.appendChild(option);
      });
    }
    
    projectSelect.value = currentProject;
  }

  function renderExcelStyleReport(projectData) {
    const tableBody = document.querySelector('#excelReportTable tbody');
    tableBody.innerHTML = '';

    let totals = {
      production_qty: 0,
      production_value: 0,
      qc_inspected: 0,
      qc_pass: 0,
      qc_fail: 0,
      delivery_qty: 0,
      delivery_value: 0,
      scrap_qty: 0,
      scrap_loss: 0,
      stock: 0,
      stock_value: 0
    };

    projectData.forEach(project => {
      const row = tableBody.insertRow();
      
      row.insertCell().textContent = project.project_name;
      row.insertCell().textContent = project.production_qty;
      row.insertCell().textContent = project.production_value_cr.toFixed(4);
      row.insertCell().textContent = project.qc_inspected;
      row.insertCell().textContent = project.qc_pass_percent.toFixed(1) + '%';
      row.insertCell().textContent = project.qc_fail_percent.toFixed(1) + '%';
      row.insertCell().textContent = project.delivery_qty;
      row.insertCell().textContent = project.delivery_value_cr.toFixed(4);
      row.insertCell().textContent = project.scrap_qty.toFixed(2);
      row.insertCell().textContent = project.scrap_loss.toFixed(2);
      row.insertCell().textContent = project.stock;
      row.insertCell().textContent = project.stock_value.toFixed(2);

      totals.production_qty += project.production_qty;
      totals.production_value += project.production_value_cr;
      totals.qc_inspected += project.qc_inspected;
      totals.qc_pass += project.qc_pass;
      totals.qc_fail += project.qc_fail;
      totals.delivery_qty += project.delivery_qty;
      totals.delivery_value += project.delivery_value_cr;
      totals.scrap_qty += project.scrap_qty;
      totals.scrap_loss += project.scrap_loss;
      totals.stock += project.stock;
      totals.stock_value += project.stock_value;
    });

    // Add total row
    const totalRow = tableBody.insertRow();
    totalRow.classList.add('total-row');
    totalRow.insertCell().textContent = 'Grand Total:';
    totalRow.insertCell().textContent = totals.production_qty;
    totalRow.insertCell().textContent = totals.production_value.toFixed(4);
    totalRow.insertCell().textContent = totals.qc_inspected;
    totalRow.insertCell().textContent = (totals.qc_inspected > 0 ? (totals.qc_pass / totals.qc_inspected * 100).toFixed(1) : 0) + '%';
    totalRow.insertCell().textContent = (totals.qc_inspected > 0 ? (totals.qc_fail / totals.qc_inspected * 100).toFixed(1) : 0) + '%';
    totalRow.insertCell().textContent = totals.delivery_qty;
    totalRow.insertCell().textContent = totals.delivery_value.toFixed(4);
    totalRow.insertCell().textContent = totals.scrap_qty.toFixed(2);
    totalRow.insertCell().textContent = totals.scrap_loss.toFixed(2);
    totalRow.insertCell().textContent = totals.stock;
    totalRow.insertCell().textContent = totals.stock_value.toFixed(2);
  }

  function downloadCsvReport() {
    if (currentReportData.length === 0) {
      showMessageBox('No data available to download. Please apply filters first.');
      return;
    }

    let csvContent = "data:text/csv;charset=utf-8,";
    csvContent += "Project Name,Production Qty,Production Value (Cr),QC Inspected,QC Pass %,QC Fail %,Delivery Qty,Delivery Value (Cr),Scrap (kg),Scrap Loss (৳),Stock (pcs),Stock Value (৳)\n";

    let totals = { production_qty: 0, production_value: 0, qc_inspected: 0, qc_pass: 0, qc_fail: 0, delivery_qty: 0, delivery_value: 0, scrap_qty: 0, scrap_loss: 0, stock: 0, stock_value: 0 };

    currentReportData.forEach(project => {
      const row = [
        `"${project.project_name}"`,
        project.production_qty,
        project.production_value_cr.toFixed(4),
        project.qc_inspected,
        project.qc_pass_percent.toFixed(1) + '%',
        project.qc_fail_percent.toFixed(1) + '%',
        project.delivery_qty,
        project.delivery_value_cr.toFixed(4),
        project.scrap_qty.toFixed(2),
        project.scrap_loss.toFixed(2),
        project.stock,
        project.stock_value.toFixed(2)
      ];
      csvContent += row.join(",") + "\n";

      totals.production_qty += project.production_qty;
      totals.production_value += project.production_value_cr;
      totals.qc_inspected += project.qc_inspected;
      totals.qc_pass += project.qc_pass;
      totals.qc_fail += project.qc_fail;
      totals.delivery_qty += project.delivery_qty;
      totals.delivery_value += project.delivery_value_cr;
      totals.scrap_qty += project.scrap_qty;
      totals.scrap_loss += project.scrap_loss;
      totals.stock += project.stock;
      totals.stock_value += project.stock_value;
    });

    const totalRow = [
      "Grand Total:",
      totals.production_qty,
      totals.production_value.toFixed(4),
      totals.qc_inspected,
      (totals.qc_inspected > 0 ? (totals.qc_pass / totals.qc_inspected * 100).toFixed(1) : 0) + '%',
      (totals.qc_inspected > 0 ? (totals.qc_fail / totals.qc_inspected * 100).toFixed(1) : 0) + '%',
      totals.delivery_qty,
      totals.delivery_value.toFixed(4),
      totals.scrap_qty.toFixed(2),
      totals.scrap_loss.toFixed(2),
      totals.stock,
      totals.stock_value.toFixed(2)
    ];
    csvContent += totalRow.join(",") + "\n";

    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    link.setAttribute("download", `Analytics_Dashboard_${startDate}_to_${endDate}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }

  async function fetchDashboardData() {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const project = document.getElementById('projectFilter').value;

    document.getElementById('displayStartDate').textContent = startDate || 'N/A';
    document.getElementById('displayEndDate').textContent = endDate || 'N/A';

    const queryParams = new URLSearchParams({
      start_date: startDate,
      end_date: endDate,
      project: project
    }).toString();

    try {
      const response = await fetch(`api/comprehensive_analytics_data.php?${queryParams}`);
      
      if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
      }
      
      const data = await response.json();
      console.log('API Response:', data);

      if (data.status === 'success') {
        document.getElementById('totalProductionPcs').textContent = data.summary.total_production_pcs || 0;
        document.getElementById('productionValueCrTk').textContent = parseFloat(data.summary.production_value_cr).toFixed(4);
        document.getElementById('qcPassPercentage').textContent = `${data.summary.qc_pass_percentage || 0}%`;
        document.getElementById('totalDeliveredPcs').textContent = data.summary.total_delivered_pcs || 0;
        document.getElementById('deliveryValueCrTk').textContent = parseFloat(data.summary.delivery_value_cr || 0).toFixed(4);
        document.getElementById('currentStock').textContent = data.summary.current_stock || 0;
        document.getElementById('totalScrapKg').textContent = parseFloat(data.summary.total_scrap_kg || 0).toFixed(2);
        document.getElementById('scrapLossValue').textContent = parseFloat(data.summary.scrap_loss_value || 0).toFixed(2);
        
        document.getElementById('currentDateTime').textContent = `Now: ${data.current_datetime}`;
        
        populateFilterOptions(data.available_filters);
        currentReportData = data.project_report_data;
        renderExcelStyleReport(currentReportData);
        
        // Render chart
        const dates = Object.keys(data.charts.daily_trend);
        const productionData = dates.map(date => data.charts.daily_trend[date].production || 0);
        const deliveryData = dates.map(date => data.charts.daily_trend[date].delivery || 0);
        const qcPassData = dates.map(date => data.charts.daily_trend[date].qc_pass || 0);
        
        renderChart(dates, productionData, deliveryData, qcPassData);
      } else {
        showMessageBox('❌ Error fetching data: ' + (data.message || 'Unknown error.'));
        console.error('API Error:', data);
      }
    } catch (error) {
      console.error('Error:', error);
      showMessageBox('❌ Error connecting to server: ' + error.message);
    }
  }

  function renderChart(labels, productionData, deliveryData, qcPassData) {
    const ctx = document.getElementById('trendChart').getContext('2d');
    const noDataMessage = document.getElementById('noChartDataMessage');

    const hasData = productionData.some(val => val > 0) || deliveryData.some(val => val > 0) || qcPassData.some(val => val > 0);

    if (!hasData || labels.length === 0) {
      if (trendChart) {
        trendChart.destroy();
      }
      noDataMessage.style.display = 'block';
      return;
    } else {
      noDataMessage.style.display = 'none';
    }

    if (trendChart) {
      trendChart.destroy();
    }

    trendChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [
          {
            label: 'Production (pcs)',
            data: productionData,
            borderColor: '#3498db',
            backgroundColor: 'rgba(52, 152, 219, 0.2)',
            tension: 0.1,
            fill: true,
            pointRadius: 5
          },
          {
            label: 'Delivery (pcs)',
            data: deliveryData,
            borderColor: '#2ecc71',
            backgroundColor: 'rgba(46, 204, 113, 0.2)',
            tension: 0.1,
            fill: true,
            pointRadius: 5
          },
          {
            label: 'QC Pass (pcs)',
            data: qcPassData,
            borderColor: '#f39c12',
            backgroundColor: 'rgba(243, 156, 18, 0.2)',
            tension: 0.1,
            fill: true,
            pointRadius: 5
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          title: {
            display: true,
            text: 'Daily Production, Delivery & QC Trend',
            font: { size: 16 }
          },
          tooltip: {
            mode: 'index',
            intersect: false,
          },
          legend: {
            position: 'bottom',
          }
        },
        scales: {
          x: {
            title: { display: true, text: 'Date' }
          },
          y: {
            title: { display: true, text: 'Quantity (pcs)' },
            beginAtZero: true
          }
        }
      }
    });
  }

  function resetFilters() {
    setDefaultDates();
    document.getElementById('projectFilter').value = '';
    fetchDashboardData();
  }

  document.addEventListener('DOMContentLoaded', () => {
    setDefaultDates();
    fetchDashboardData();
  });
</script>
</body>
</html>


