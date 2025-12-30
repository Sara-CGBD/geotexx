<?php
session_start();
require_once 'security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}
if (SecurityConfig::checkSessionTimeout()) {
    session_destroy();
    header("Location: ../login.html?error=timeout");
    exit();
}
SecurityConfig::updateSessionActivity();
if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    session_destroy();
    header("Location: ../login.html?error=disabled");
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Get all unique reference numbers from all QC tables
$references = [];
$error_log = [];

// Get references from qc_test_orders - check all possible reference fields
$query1 = "SELECT DISTINCT sample_reference_id as reference 
FROM qc_test_orders 
WHERE sample_reference_id IS NOT NULL 
  AND sample_reference_id != '' 
  AND status IN ('approved', 'pending_approval', 'pending_checker')
UNION
SELECT DISTINCT 
    JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.product_reference')) as reference
FROM qc_test_orders 
WHERE JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.product_reference')) IS NOT NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.product_reference')) != ''
  AND status IN ('approved', 'pending_approval', 'pending_checker')
UNION
SELECT DISTINCT 
    JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.fiber_reference_no')) as reference
FROM qc_test_orders 
WHERE JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.fiber_reference_no')) IS NOT NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.fiber_reference_no')) != ''
  AND status IN ('approved', 'pending_approval', 'pending_checker')
UNION
SELECT DISTINCT 
    JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.yarn_reference_no')) as reference
FROM qc_test_orders 
WHERE JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.yarn_reference_no')) IS NOT NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(test_data, '$.yarn_reference_no')) != ''
  AND status IN ('approved', 'pending_approval', 'pending_checker')
ORDER BY reference";

$result1 = $conn->query($query1);
if ($result1) {
    while ($row = $result1->fetch_assoc()) {
        if (!empty($row['reference']) && trim($row['reference']) !== '') {
            $references[] = trim($row['reference']);
        }
    }
    error_log("QC Summary - Found " . count($references) . " references from qc_test_orders");
} else {
    $error_log[] = "QC Test Orders query error: " . $conn->error;
    error_log("QC Summary - QC Test Orders query error: " . $conn->error);
}

// Get references from weathering_exposure_reports (UV Test)
$query2 = "SELECT DISTINCT reference 
FROM weathering_exposure_reports 
WHERE reference IS NOT NULL 
  AND reference != '' 
  AND status IN ('approved', 'pending_approval', 'pending_checker', 'checked')
ORDER BY reference";
$result2 = $conn->query($query2);
if ($result2) {
    while ($row = $result2->fetch_assoc()) {
        if (!empty($row['reference']) && trim($row['reference']) !== '') {
            $references[] = trim($row['reference']);
        }
    }
    error_log("QC Summary - Found " . $result2->num_rows . " references from weathering_exposure_reports");
}

// Get references from sun_test_reports
$query3 = "SELECT DISTINCT reference_number as reference 
FROM sun_test_reports 
WHERE reference_number IS NOT NULL 
  AND reference_number != '' 
  AND status IN ('approved', 'pending_approval', 'pending_checker', 'checked')
ORDER BY reference_number";
$result3 = $conn->query($query3);
if ($result3) {
    while ($row = $result3->fetch_assoc()) {
        if (!empty($row['reference']) && trim($row['reference']) !== '') {
            $references[] = trim($row['reference']);
        }
    }
    error_log("QC Summary - Found " . $result3->num_rows . " references from sun_test_reports");
}

// Get references from water_permeability_tests
$query4 = "SELECT DISTINCT reference_number as reference 
FROM water_permeability_tests 
WHERE reference_number IS NOT NULL 
  AND reference_number != '' 
  AND status IN ('approved', 'pending', 'checked')
ORDER BY reference_number";
$result4 = $conn->query($query4);
if ($result4) {
    while ($row = $result4->fetch_assoc()) {
        if (!empty($row['reference']) && trim($row['reference']) !== '') {
            $references[] = trim($row['reference']);
        }
    }
    error_log("QC Summary - Found " . $result4->num_rows . " references from water_permeability_tests");
}

// Get references from fiber_test_reports
$query5 = "SELECT DISTINCT sample_id as reference 
FROM fiber_test_reports 
WHERE sample_id IS NOT NULL 
  AND sample_id != '' 
  AND status IN ('approved', 'pending_approval', 'pending_checker', 'checked')
ORDER BY sample_id";
$result5 = $conn->query($query5);
if ($result5) {
    while ($row = $result5->fetch_assoc()) {
        if (!empty($row['reference']) && trim($row['reference']) !== '') {
            $references[] = trim($row['reference']);
        }
    }
    error_log("QC Summary - Found " . $result5->num_rows . " references from fiber_test_reports");
}

// Get references from characteristics_tests
$query6 = "SELECT DISTINCT reference_number as reference 
FROM characteristics_tests 
WHERE reference_number IS NOT NULL 
  AND reference_number != '' 
  AND status IN ('approved', 'pending', 'checked')
ORDER BY reference_number";
$result6 = $conn->query($query6);
if ($result6) {
    while ($row = $result6->fetch_assoc()) {
        if (!empty($row['reference']) && trim($row['reference']) !== '') {
            $references[] = trim($row['reference']);
        }
    }
    error_log("QC Summary - Found " . $result6->num_rows . " references from characteristics_tests");
}

// Remove duplicates and sort
$references = array_unique($references);
sort($references);

error_log("QC Summary - Total unique references found: " . count($references) . " - References: " . implode(', ', array_slice($references, 0, 10)) . (count($references) > 10 ? '...' : ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QC Summary Report - GEOCIL</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
        .container { max-width:1200px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);}
        h1 { text-align:center; font-size:28px; margin-bottom:30px; color:#2c3e50; }
        
        .alert { padding:12px; border-radius:6px; margin-bottom:15px; }
        .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
        .alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
        
        .form-group { margin-bottom:20px; }
        .form-group label { font-weight:600; display:block; margin-bottom:8px; }
        .form-group select, .form-group input { padding:10px; border:1px solid #ccc; border-radius:6px; width:100%; font-size:14px; }
        .form-group select:focus, .form-group input:focus { outline:none; border-color:#3498db; }
        
        .btn { padding:12px 24px; border:none; border-radius:6px; cursor:pointer; font-size:14px; font-weight:600; transition:all 0.3s; text-decoration:none; display:inline-block; }
        .btn-primary { background:#3498db; color:#fff; }
        .btn-primary:hover { background:#2980b9; }
        .btn-secondary { background:#95a5a6; color:#fff; }
        .btn-secondary:hover { background:#7f8c8d; }
        
        .back-btn { margin-bottom:20px; }
        
        .summary-container { display:none; margin-top:30px; }
        
        .test-section { background:#f8f9fa; border:1px solid #dee2e6; border-radius:8px; padding:20px; margin-bottom:20px; }
        .test-section h3 { color:#2c3e50; margin:0 0 15px 0; padding-bottom:10px; border-bottom:2px solid #3498db; font-size:18px; }
        
        .no-data { padding:15px; background:#fff; border-radius:6px; color:#7f8c8d; text-align:center; font-style:italic; }
        
        .test-card { background:#fff; border-left:4px solid #3498db; padding:15px; margin-bottom:15px; border-radius:6px; box-shadow:0 2px 4px rgba(0,0,0,0.05); }
        .test-card h4 { color:#2c3e50; margin:0 0 10px 0; font-size:16px; }
        
        .test-details { display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:10px; margin-top:10px; }
        .detail-item { padding:8px 0; }
        .detail-label { font-weight:600; color:#555; margin-right:5px; }
        .detail-value { color:#2c3e50; }
        
        .status-badge { display:inline-block; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600; text-transform:uppercase; }
        .status-pending { background:#fff3cd; color:#856404; }
        .status-approved { background:#d4edda; color:#155724; }
        .status-rejected { background:#f8d7da; color:#721c24; }
        
        .loading { text-align:center; padding:40px; color:#3498db; font-size:18px; }
        .loading i { font-size:2em; margin-bottom:10px; animation:spin 1s linear infinite; }
        
        @keyframes spin {
            0% { transform:rotate(0deg); }
            100% { transform:rotate(360deg); }
        }
        
        table { width:100%; border-collapse:collapse; margin-top:15px; font-size:13px; }
        table th, table td { padding:10px; text-align:left; border:1px solid #dee2e6; }
        table th { background:#3498db; color:#fff; font-weight:600; }
        table tr:nth-child(even) { background:#f8f9fa; }
        
        .button-group { text-align:center; margin:20px 0; }
        
        @media print {
            body { background:#fff; padding:0; }
            .container { box-shadow:none; }
            .form-group, .btn, .back-btn, .button-group { display:none; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="back-btn">
            <a href="../index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
        
        <h1><i class="fas fa-file-invoice"></i> QC Summary Report</h1>
        
        <?php if (!empty($error_log)): ?>
        <div class="alert alert-error">
            <strong><i class="fas fa-exclamation-circle"></i> Debug Information:</strong><br>
            <?php foreach ($error_log as $err): ?>
                <?php echo htmlspecialchars($err); ?><br>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <div class="form-group">
            <label for="reference_select">
                <i class="fas fa-search"></i> Select Reference Number: <span style="color:red;">*</span>
            </label>
            <select id="reference_select">
                <option value="">-- Select Reference Number --</option>
                <?php 
                if (empty($references)) {
                    echo '<option value="" disabled>No references found in database</option>';
                } else {
                    foreach ($references as $ref): 
                ?>
                    <option value="<?php echo htmlspecialchars($ref); ?>">
                        <?php echo htmlspecialchars($ref); ?>
                    </option>
                <?php 
                    endforeach;
                }
                ?>
            </select>
            <?php if (!empty($references)): ?>
                <small style="color:#666; margin-top:5px; display:block;">
                    <i class="fas fa-info-circle"></i> Found <?php echo count($references); ?> reference(s)
                </small>
            <?php endif; ?>
        </div>
        
        <div class="button-group">
            <button class="btn btn-primary" onclick="loadSummary()">
                <i class="fas fa-download"></i> Load Summary
            </button>
            <button class="btn btn-secondary" onclick="window.print()">
                <i class="fas fa-print"></i> Print Report
            </button>
        </div>
        
        <div id="summary-container" class="summary-container"></div>
    </div>
    
    <script>
        function loadSummary() {
            const reference = document.getElementById('reference_select').value;
            const container = document.getElementById('summary-container');
            
            if (!reference) {
                alert('Please select a reference number');
                return;
            }
            
            console.log('Loading summary for reference:', reference);
            
            container.style.display = 'block';
            container.innerHTML = '<div class="loading"><i class="fas fa-spinner"></i><br>Loading summary data...</div>';
            
            // Fetch data from all test types
            Promise.all([
                fetch(`api/get_qc_test_summary.php?reference=${encodeURIComponent(reference)}`).then(r => {
                    console.log('QC Test response:', r.status);
                    return r.json();
                }),
                fetch(`api/get_uv_test_summary.php?reference=${encodeURIComponent(reference)}`).then(r => {
                    console.log('UV Test response:', r.status);
                    return r.json();
                }),
                fetch(`api/get_water_permeability_summary.php?reference=${encodeURIComponent(reference)}`).then(r => {
                    console.log('Water Permeability response:', r.status);
                    return r.json();
                }),
                fetch(`api/get_sun_test_summary.php?reference=${encodeURIComponent(reference)}`).then(r => {
                    console.log('Sun Test response:', r.status);
                    return r.json();
                }),
                fetch(`api/get_characteristics_test_summary.php?reference=${encodeURIComponent(reference)}`).then(r => {
                    console.log('Characteristics Test response:', r.status);
                    return r.json();
                })
            ]).then(([qcData, uvData, waterData, sunData, characteristicsData]) => {
                console.log('QC Data:', qcData);
                console.log('UV Data:', uvData);
                console.log('Water Data:', waterData);
                console.log('Sun Data:', sunData);
                console.log('Characteristics Data:', characteristicsData);
                displaySummary(reference, qcData, uvData, waterData, sunData, characteristicsData);
            }).catch(error => {
                console.error('Error loading summary:', error);
                container.innerHTML = '<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> Error loading summary: ' + error.message + '</div>';
            });
        }
        
        function displaySummary(reference, qcData, uvData, waterData, sunData, characteristicsData) {
            const container = document.getElementById('summary-container');
            let html = `
                <div style="text-align:right; margin-bottom:20px; font-weight:600; color:#2c3e50;">
                    <i class="fas fa-barcode"></i> Reference Number: ${escapeHtml(reference)}
                </div>
            `;
            
            // QC Test Orders
            html += renderQCTestSection(qcData);
            
            // UV Test
            html += renderUVTestSection(uvData);
            
            // Water Permeability Test
            html += renderWaterPermeabilitySection(waterData);
            
            // Sun Test
            html += renderSunTestSection(sunData);
            
            // Characteristics Test (Sewing Thread Report)
            html += renderCharacteristicsTestSection(characteristicsData);
            
            container.innerHTML = html;
        }
        
        function renderQCTestSection(data) {
            let html = '<div class="test-section"><h3><i class="fas fa-clipboard-check"></i> QC Test Orders</h3>';
            
            if (!data.success || !data.tests || data.tests.length === 0) {
                html += '<div class="no-data"><i class="fas fa-info-circle"></i> No QC test orders found for this reference.</div>';
            } else {
                data.tests.forEach(test => {
                    html += `
                        <div class="test-card">
                            <h4><i class="fas fa-vial"></i> ${escapeHtml(test.test_name)} - ${escapeHtml(test.method)}</h4>
                    `;
                    
                    // Show report number if available
                    if (test.report_number) {
                        html += `<div style="margin-bottom:10px;"><strong>Report Number:</strong> ${escapeHtml(test.report_number)}</div>`;
                    }
                    
                    html += `
                            <div class="test-details">
                                <div class="detail-item">
                                    <span class="detail-label">Sample Reference ID:</span>
                                    <span class="detail-value">${escapeHtml(test.sample_reference_id)}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Created At:</span>
                                    <span class="detail-value">${formatDateTime(test.created_at)}</span>
                                </div>
                            </div>
                    `;
                    
                    if (test.test_data) {
                        html += '<div style="margin-top:15px;">';
                        html += renderTestData(test.test_data, test.test_name);
                        html += '</div>';
                    }
                    
                    html += '</div>';
                });
            }
            
            html += '</div>';
            return html;
        }
        
        function renderUVTestSection(data) {
            let html = '<div class="test-section"><h3><i class="fas fa-sun"></i> UV Weathering Exposure Test</h3>';
            
            if (!data.success || !data.tests || data.tests.length === 0) {
                html += '<div class="no-data"><i class="fas fa-info-circle"></i> No UV test reports found for this reference.</div>';
            } else {
                data.tests.forEach(test => {
                    html += `
                        <div class="test-card">
                            <h4><i class="fas fa-file-alt"></i> Report: ${escapeHtml(test.report_number)}</h4>
                            <span class="status-badge status-${test.status}">${test.status}</span>
                            <div class="test-details">
                                <div class="detail-item">
                                    <span class="detail-label">Sample Description:</span>
                                    <span class="detail-value">${escapeHtml(test.sample_description)}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Testing Method:</span>
                                    <span class="detail-value">${escapeHtml(test.testing_method)}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Test Period:</span>
                                    <span class="detail-value">${formatDate(test.test_start_date)} to ${formatDate(test.test_end_date)}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Temperature:</span>
                                    <span class="detail-value">${test.temperature}°C</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">RH:</span>
                                    <span class="detail-value">${test.rh_percent}%</span>
                                </div>
                            </div>
                    `;
                    
                    // Display test results table if available
                    if (test.test_results) {
                        try {
                            const results = typeof test.test_results === 'string' ? JSON.parse(test.test_results) : test.test_results;
                            if (results && results.length > 0) {
                                // Transform the data structure from specimen-based to parameter-based
                                const forceData = { after_md: [], after_cd: [], before_md: [], before_cd: [], retain_md: [], retain_cd: [] };
                                const elongationData = { after_md: [], after_cd: [], before_md: [], before_cd: [], retain_md: [], retain_cd: [] };
                                
                                results.forEach(row => {
                                    const dir = (row.test_direction || 'MD').toLowerCase();
                                    const dirKey = dir === 'cd' ? 'cd' : 'md';
                                    
                                    // Collect force data
                                    if (row.breaking_force_after) forceData['after_' + dirKey].push(parseFloat(row.breaking_force_after) || 0);
                                    if (row.breaking_force_before) forceData['before_' + dirKey].push(parseFloat(row.breaking_force_before) || 0);
                                    if (row.force_retain) {
                                        // Remove any non-numeric characters except decimal point
                                        const retainVal = String(row.force_retain).replace(/[^0-9.]/g, '');
                                        forceData['retain_' + dirKey].push(parseFloat(retainVal) || 0);
                                    }
                                    
                                    // Collect elongation data (only if non-empty and non-zero)
                                    const elongAfter = parseFloat(row.elongation_after);
                                    const elongBefore = parseFloat(row.elongation_before);
                                    
                                    if (elongAfter && elongAfter > 0) {
                                        elongationData['after_' + dirKey].push(elongAfter);
                                    }
                                    if (elongBefore && elongBefore > 0) {
                                        elongationData['before_' + dirKey].push(elongBefore);
                                    }
                                    // Calculate elongation retain if both values exist and are non-zero
                                    if (elongAfter > 0 && elongBefore > 0) {
                                        const elongRetain = (elongAfter / elongBefore) * 100;
                                        elongationData['retain_' + dirKey].push(elongRetain);
                                    }
                                });
                                
                                // Calculate averages
                                const calcAvg = (arr) => arr.length > 0 ? (arr.reduce((a, b) => a + b, 0) / arr.length).toFixed(2) : '0';
                                
                                const tableData = [];
                                
                                // Add ONLY Force row (Elongation is auxiliary data, not part of UV test result summary)
                                tableData.push({
                                    parameter: 'Force (N)',
                                    after_md: calcAvg(forceData.after_md),
                                    after_cd: calcAvg(forceData.after_cd),
                                    before_md: calcAvg(forceData.before_md),
                                    before_cd: calcAvg(forceData.before_cd),
                                    retain_md: calcAvg(forceData.retain_md),
                                    retain_cd: calcAvg(forceData.retain_cd)
                                });
                                
                                html += '<h4 style="margin-top:15px; color:#2c3e50;">Test Results</h4>';
                                html += '<table style="width:100%; border-collapse:collapse; margin-top:10px;">';
                                html += '<thead><tr style="background:#3498db; color:white;">';
                                html += '<th rowspan="2" style="border:1px solid #ddd; padding:8px;">Parameter</th>';
                                html += '<th colspan="2" style="border:1px solid #ddd; padding:8px; text-align:center;">After Exposure</th>';
                                html += '<th colspan="2" style="border:1px solid #ddd; padding:8px; text-align:center;">Before Exposure</th>';
                                html += '<th colspan="2" style="border:1px solid #ddd; padding:8px; text-align:center;">Retain (%)</th>';
                                html += '</tr><tr style="background:#3498db; color:white;">';
                                html += '<th style="border:1px solid #ddd; padding:8px;">MD</th>';
                                html += '<th style="border:1px solid #ddd; padding:8px;">CD</th>';
                                html += '<th style="border:1px solid #ddd; padding:8px;">MD</th>';
                                html += '<th style="border:1px solid #ddd; padding:8px;">CD</th>';
                                html += '<th style="border:1px solid #ddd; padding:8px;">MD</th>';
                                html += '<th style="border:1px solid #ddd; padding:8px;">CD</th>';
                                html += '</tr></thead><tbody>';
                                
                                tableData.forEach(row => {
                                    html += '<tr>';
                                    html += `<td style="border:1px solid #ddd; padding:8px;">${escapeHtml(row.parameter)}</td>`;
                                    html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${row.after_md}</td>`;
                                    html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${row.after_cd}</td>`;
                                    html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${row.before_md}</td>`;
                                    html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${row.before_cd}</td>`;
                                    html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${row.retain_md}</td>`;
                                    html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${row.retain_cd}</td>`;
                                    html += '</tr>';
                                });
                                
                                html += '</tbody></table>';
                            }
                        } catch (e) {
                            console.error('Error parsing UV test results:', e);
                        }
                    }
                    
                    html += '</div>';
                });
            }
            
            html += '</div>';
            return html;
        }
        
        function renderWaterPermeabilitySection(data) {
            let html = '<div class="test-section"><h3><i class="fas fa-tint"></i> Water Permeability Test</h3>';
            
            if (!data.success || !data.tests || data.tests.length === 0) {
                html += '<div class="no-data"><i class="fas fa-info-circle"></i> No water permeability tests found for this reference.</div>';
            } else {
                data.tests.forEach(test => {
                    html += `
                        <div class="test-card">
                            <h4><i class="fas fa-file-alt"></i> Report: ${escapeHtml(test.report_number)}</h4>
                            <span class="status-badge status-${test.status}">${test.status}</span>
                            <div class="test-details">
                                <div class="detail-item">
                                    <span class="detail-label">Lab Test Number:</span>
                                    <span class="detail-value">${escapeHtml(test.lab_test_number)}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">GSM:</span>
                                    <span class="detail-value">${test.gsm}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Roll Number:</span>
                                    <span class="detail-value">${escapeHtml(test.roll_number)}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Test Date:</span>
                                    <span class="detail-value">${formatDate(test.test_date)}</span>
                                </div>
                            </div>
                    `;
                    
                    // Display summary results if available
                    if (test.test_results) {
                        try {
                            const results = typeof test.test_results === 'string' ? JSON.parse(test.test_results) : test.test_results;
                            
                            // Check if we have summary data (stored at root level)
                            const hasAvgPermeability = results.avg_permeability !== undefined;
                            const hasAvgVelocity = results.avg_velocity !== undefined;
                            
                            if (hasAvgPermeability || hasAvgVelocity) {
                                html += '<h4 style="margin-top:15px; color:#2c3e50;">Summary Results</h4>';
                                html += '<table style="width:100%; border-collapse:collapse; margin-top:10px;"><tbody>';
                                
                                if (hasAvgPermeability) {
                                    html += '<tr>';
                                    html += '<td style="border:1px solid #ddd; padding:8px; width:70%; background:#f8f9fa;"><strong>Average Permeability, k (m/s×10⁻³):</strong></td>';
                                    html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${results.avg_permeability || '0'}</td>`;
                                    html += '</tr>';
                                }
                                
                                if (hasAvgVelocity) {
                                    html += '<tr>';
                                    html += '<td style="border:1px solid #ddd; padding:8px; background:#f8f9fa;"><strong>Average Flow Velocity, V₂₀ (m/s×10⁻³):</strong></td>';
                                    html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${results.avg_velocity || '0'}</td>`;
                                    html += '</tr>';
                                }
                                
                                html += '</tbody></table>';
                            }
                        } catch (e) {
                            console.error('Error parsing water permeability results:', e);
                        }
                    }
                    
                    html += '</div>';
                });
            }
            
            html += '</div>';
            return html;
        }
        
        function renderSunTestSection(data) {
            let html = '<div class="test-section"><h3><i class="fas fa-cloud-sun"></i> Sun Test Report</h3>';
            
            if (!data.success || !data.tests || data.tests.length === 0) {
                html += '<div class="no-data"><i class="fas fa-info-circle"></i> No sun test reports found for this reference.</div>';
            } else {
                data.tests.forEach(test => {
                    html += `
                        <div class="test-card">
                            <h4><i class="fas fa-file-alt"></i> Report: ${escapeHtml(test.report_number)}</h4>
                            <span class="status-badge status-${test.status}">${test.status}</span>
                            <div class="test-details">
                                <div class="detail-item">
                                    <span class="detail-label">Sample Description:</span>
                                    <span class="detail-value">${escapeHtml(test.sample_description)}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">GSM:</span>
                                    <span class="detail-value">${test.gsm}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Roll Number:</span>
                                    <span class="detail-value">${escapeHtml(test.roll_number)}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Test Period:</span>
                                    <span class="detail-value">${formatDate(test.test_start_date)} to ${formatDate(test.test_end_date)}</span>
                                </div>
                            </div>
                    `;
                    
                    // Display test results table if available
                    if (test.test_results) {
                        try {
                            const results = typeof test.test_results === 'string' ? JSON.parse(test.test_results) : test.test_results;
                            if (results && results.length > 0) {
                                // Transform the data structure from specimen-based to parameter-based
                                const forceData = { after_md: [], after_cd: [], before_md: [], before_cd: [], retain_md: [], retain_cd: [] };
                                
                                results.forEach(row => {
                                    const dir = (row.test_direction || 'MD').toLowerCase();
                                    const dirKey = dir === 'cd' ? 'cd' : 'md';
                                    
                                    // Collect force data
                                    if (row.breaking_force_after) forceData['after_' + dirKey].push(parseFloat(row.breaking_force_after) || 0);
                                    if (row.breaking_force_before) forceData['before_' + dirKey].push(parseFloat(row.breaking_force_before) || 0);
                                    if (row.force_retain) {
                                        // Remove any non-numeric characters except decimal point
                                        const retainVal = String(row.force_retain).replace(/[^0-9.]/g, '');
                                        forceData['retain_' + dirKey].push(parseFloat(retainVal) || 0);
                                    }
                                });
                                
                                // Calculate averages
                                const calcAvg = (arr) => arr.length > 0 ? (arr.reduce((a, b) => a + b, 0) / arr.length).toFixed(2) : '0';
                                
                                html += '<h4 style="margin-top:15px; color:#2c3e50;">Test Results</h4>';
                                html += '<table style="width:100%; border-collapse:collapse; margin-top:10px;">';
                                html += '<thead><tr style="background:#3498db; color:white;">';
                                html += '<th rowspan="2" style="border:1px solid #ddd; padding:8px;">Parameter</th>';
                                html += '<th colspan="2" style="border:1px solid #ddd; padding:8px; text-align:center;">After Exposure</th>';
                                html += '<th colspan="2" style="border:1px solid #ddd; padding:8px; text-align:center;">Before Exposure</th>';
                                html += '<th colspan="2" style="border:1px solid #ddd; padding:8px; text-align:center;">Retain (%)</th>';
                                html += '</tr><tr style="background:#3498db; color:white;">';
                                html += '<th style="border:1px solid #ddd; padding:8px;">MD</th>';
                                html += '<th style="border:1px solid #ddd; padding:8px;">CD</th>';
                                html += '<th style="border:1px solid #ddd; padding:8px;">MD</th>';
                                html += '<th style="border:1px solid #ddd; padding:8px;">CD</th>';
                                html += '<th style="border:1px solid #ddd; padding:8px;">MD</th>';
                                html += '<th style="border:1px solid #ddd; padding:8px;">CD</th>';
                                html += '</tr></thead><tbody>';
                                
                                html += '<tr>';
                                html += `<td style="border:1px solid #ddd; padding:8px;">Force (N)</td>`;
                                html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${calcAvg(forceData.after_md)}</td>`;
                                html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${calcAvg(forceData.after_cd)}</td>`;
                                html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${calcAvg(forceData.before_md)}</td>`;
                                html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${calcAvg(forceData.before_cd)}</td>`;
                                html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${calcAvg(forceData.retain_md)}</td>`;
                                html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${calcAvg(forceData.retain_cd)}</td>`;
                                html += '</tr>';
                                
                                html += '</tbody></table>';
                            }
                        } catch (e) {
                            console.error('Error parsing Sun test results:', e);
                        }
                    }
                    
                    html += '</div>';
                });
            }
            
            html += '</div>';
            return html;
        }
        
        function renderCharacteristicsTestSection(data) {
            let html = '<div class="test-section"><h3><i class="fas fa-cog"></i> Characteristics Test (Opening Size Calculation / AOS)</h3>';
            
            if (!data.success || !data.tests || data.tests.length === 0) {
                html += '<div class="no-data"><i class="fas fa-info-circle"></i> No characteristics test reports found for this reference.</div>';
            } else {
                data.tests.forEach(test => {
                    html += `
                        <div class="test-card">
                            <h4><i class="fas fa-file-alt"></i> Report: ${escapeHtml(test.report_number)}</h4>
                            <span class="status-badge status-${test.status}">${test.status}</span>
                            <div class="test-details">
                                <div class="detail-item">
                                    <span class="detail-label">Lab Test Number:</span>
                                    <span class="detail-value">${escapeHtml(test.lab_test_number)}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Test Standard:</span>
                                    <span class="detail-value">${escapeHtml(test.test_standard || 'ISO 12956')}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">GSM:</span>
                                    <span class="detail-value">${parseFloat(test.gsm)}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Roll Number:</span>
                                    <span class="detail-value">${escapeHtml(test.roll_number)}</span>
                                </div>
                                <div class="detail-item">
                                    <span class="detail-label">Sample ID:</span>
                                    <span class="detail-value">${escapeHtml(test.sample_id)}</span>
                                </div>
                            </div>
                    `;
                    
                    // Display test results (Opening Size Calculation / AOS) if available
                    if (test.test_results) {
                        try {
                            const results = typeof test.test_results === 'string' ? JSON.parse(test.test_results) : test.test_results;
                            if (results && results.sieve_data && results.sieve_data.length > 0) {
                                html += '<h4 style="margin-top:15px; color:#2c3e50;">Opening Size Calculation (AOS)</h4>';
                                html += '<table style="width:100%; border-collapse:collapse; margin-top:10px;">';
                                html += '<thead><tr style="background:#3498db; color:white;">';
                                html += '<th style="border:1px solid #ddd; padding:8px;">Sieve Size (mm)</th>';
                                html += '<th style="border:1px solid #ddd; padding:8px;">Retained (%)</th>';
                                html += '<th style="border:1px solid #ddd; padding:8px;">Cumulative (%)</th>';
                                html += '<th style="border:1px solid #ddd; padding:8px;">Passing (%)</th>';
                                html += '</tr></thead><tbody>';
                                
                                results.sieve_data.forEach(row => {
                                    html += '<tr>';
                                    html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${row.sieve_size || 0}</td>`;
                                    html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${row.retained || 0}</td>`;
                                    html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${row.cumulative || 0}</td>`;
                                    html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${row.passing || 0}</td>`;
                                    html += '</tr>';
                                });
                                
                                html += '</tbody></table>';
                                
                                // Display O-Value and Opening Size
                                html += '<div style="margin-top:15px; padding:15px; background:#f8f9fa; border-radius:6px;">';
                                html += '<div style="display:grid; grid-template-columns:1fr 1fr; gap:15px;">';
                                html += `<div><strong>O-Value (O90):</strong> ${results.o_value || 0}</div>`;
                                html += `<div><strong>Opening Size (mm):</strong> ${results.opening_size || 0}</div>`;
                                html += '</div>';
                                if (results.remarks) {
                                    html += `<div style="margin-top:10px;"><strong>Remarks:</strong> ${escapeHtml(results.remarks)}</div>`;
                                }
                                html += '</div>';
                            }
                        } catch (e) {
                            console.error('Error parsing characteristics test results:', e);
                        }
                    }
                    
                    html += '</div>';
                });
            }
            
            html += '</div>';
            return html;
        }
        
        function renderTestData(testData, testName) {
            let html = '';
            
            try {
                const data = typeof testData === 'string' ? JSON.parse(testData) : testData;
                
                // Log for debugging
                console.log('Test Name:', testName, 'Data:', data);
                
                // Check if data is valid
                if (!data || typeof data !== 'object') {
                    console.warn('No valid test data for', testName);
                    return '<div style="padding:10px; color:#666; font-style:italic;">No test data available</div>';
                }
                
                // Special handling for Tenacity of Yarn
                if (testName === 'Tenacity of Yarn' && data.yarn_table_data) {
                    html += '<table style="width:100%; border-collapse:collapse; margin-top:10px;">';
                    html += '<thead><tr style="background:#3498db; color:white;">';
                    html += '<th style="border:1px solid #ddd; padding:8px;">SL No</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Parameter</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Test Standard</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Unit</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Test Result</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Remarks</th>';
                    html += '</tr></thead><tbody>';
                    
                    data.yarn_table_data.forEach(row => {
                        html += '<tr>';
                        html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${row.sl_no || ''}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px;">${escapeHtml(row.parameter || '')}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px;">${escapeHtml(row.standard || '')}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${escapeHtml(row.unit || '')}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${escapeHtml(row.result || '')}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px;">${escapeHtml(row.remarks || '')}</td>`;
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                }
                // Special handling for Fineness of Fiber
                else if (testName === 'Fineness of Fiber' && data.fiber_table_data) {
                    html += '<h4 style="margin-top:15px; color:#2c3e50;">Test Results</h4>';
                    html += '<table style="width:100%; border-collapse:collapse; margin-top:10px;">';
                    html += '<thead><tr style="background:#3498db; color:white;">';
                    html += '<th style="border:1px solid #ddd; padding:8px;">SL No</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Parameter</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Test Standard</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Unit</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Test Result</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Remarks</th>';
                    html += '</tr></thead><tbody>';
                    
                    data.fiber_table_data.forEach(row => {
                        html += '<tr>';
                        html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${row.sl_no || ''}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px;">${escapeHtml(row.parameter || '')}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px;">${escapeHtml(row.standard || '')}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${escapeHtml(row.unit || '')}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${escapeHtml(row.result || '')}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px;">${escapeHtml(row.remarks || '')}</td>`;
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                }
                // Special handling for Tenacity of Fiber
                else if (testName === 'Tenacity of Fiber' && data.tenacity_table_data) {
                    html += '<h4 style="margin-top:15px; color:#2c3e50;">Test Results</h4>';
                    html += '<table style="width:100%; border-collapse:collapse; margin-top:10px;">';
                    html += '<thead><tr style="background:#3498db; color:white;">';
                    html += '<th style="border:1px solid #ddd; padding:8px;">SL No</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Parameter</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Test Standard</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Unit</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Test Result</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Remarks</th>';
                    html += '</tr></thead><tbody>';
                    
                    data.tenacity_table_data.forEach(row => {
                        html += '<tr>';
                        html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${row.sl_no || ''}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px;">${escapeHtml(row.parameter || '')}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px;">${escapeHtml(row.standard || '')}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${escapeHtml(row.unit || '')}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${escapeHtml(row.result || '')}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px;">${escapeHtml(row.remarks || '')}</td>`;
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                    
                    // Show acceptance criteria table if exists
                    if (data.acceptance_criteria) {
                        html += '<h4 style="margin-top:15px; color:#2c3e50;">Acceptance Criteria</h4>';
                        html += '<table style="width:100%; border-collapse:collapse; margin-top:10px;">';
                        html += '<thead><tr style="background:#3498db; color:white;">';
                        html += '<th style="border:1px solid #ddd; padding:8px;">Parameter</th>';
                        html += '<th style="border:1px solid #ddd; padding:8px;">High</th>';
                        html += '<th style="border:1px solid #ddd; padding:8px;">Good</th>';
                        html += '<th style="border:1px solid #ddd; padding:8px;">Medium</th>';
                        html += '<th style="border:1px solid #ddd; padding:8px;">BWDB Requirements</th>';
                        html += '</tr></thead><tbody>';
                        html += '<tr><td style="border:1px solid #ddd; padding:8px;">Tenacity</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">5.4+</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">5+</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">4.5+</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">Requirements</td></tr>';
                        html += '<tr><td style="border:1px solid #ddd; padding:8px;">Elongation</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">0.8</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">0.6</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">-</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">60%+</td></tr>';
                        html += '</tbody></table>';
                    }
                }
                // Special handling for Cut Length of Fiber
                else if (testName === 'Cut Length of Fiber' && data.cut_length_table_data) {
                    html += '<h4 style="margin-top:15px; color:#2c3e50;">Test Results</h4>';
                    html += '<table style="width:100%; border-collapse:collapse; margin-top:10px;">';
                    html += '<thead><tr style="background:#3498db; color:white;">';
                    html += '<th style="border:1px solid #ddd; padding:8px;">SL No</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Parameter</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Test Standard</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Unit</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Test Result</th>';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Remarks</th>';
                    html += '</tr></thead><tbody>';
                    
                    data.cut_length_table_data.forEach(row => {
                        html += '<tr>';
                        html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${row.sl_no || ''}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px;">${escapeHtml(row.parameter || '')}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px;">${escapeHtml(row.standard || '')}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${escapeHtml(row.unit || '')}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px; text-align:center;">${escapeHtml(row.result || '')}</td>`;
                        html += `<td style="border:1px solid #ddd; padding:8px;">${escapeHtml(row.remarks || '')}</td>`;
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                }
                // Handle Strip Tensile Test
                else if (testName === 'Strip Tensile Test' && data.summary) {
                    if (data.summary) {
                        html += '<h4 style="margin-top:15px; color:#2c3e50;">Summary Statistics</h4>';
                        html += '<table style="width:100%; border-collapse:collapse; margin-top:10px;">';
                        html += '<thead><tr style="background:#3498db; color:white;">';
                        html += '<th style="border:1px solid #ddd; padding:8px;">Statistics</th>';
                        html += '<th colspan="2" style="border:1px solid #ddd; padding:8px; text-align:center;">MD</th>';
                        html += '<th colspan="2" style="border:1px solid #ddd; padding:8px; text-align:center;">CD</th>';
                        html += '</tr><tr style="background:#3498db; color:white;">';
                        html += '<th style="border:1px solid #ddd; padding:8px;"></th>';
                        html += '<th style="border:1px solid #ddd; padding:8px;">Strength</th>';
                        html += '<th style="border:1px solid #ddd; padding:8px;">Elongation</th>';
                        html += '<th style="border:1px solid #ddd; padding:8px;">Strength</th>';
                        html += '<th style="border:1px solid #ddd; padding:8px;">Elongation</th>';
                        html += '</tr></thead><tbody>';
                        html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>Average</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.strength_avg) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.elongation_avg) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.strength_avg) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.elongation_avg) || 0)}</td></tr>`;
                        html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>SD</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.strength_sd) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.elongation_sd) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.strength_sd) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.elongation_sd) || 0)}</td></tr>`;
                        html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>CV%</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.strength_cv) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.elongation_cv) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.strength_cv) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.elongation_cv) || 0)}</td></tr>`;
                        html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>Maximum</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.strength_max) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.elongation_max) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.strength_max) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.elongation_max) || 0)}</td></tr>`;
                        html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>Minimum</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.strength_min) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.elongation_min) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.strength_min) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.elongation_min) || 0)}</td></tr>`;
                        html += '</tbody></table>';
                    }
                }
                // Handle CBR Puncture Resistance
                else if (testName === 'CBR Puncture Resistance' && data.summary) {
                    if (data.summary) {
                        html += '<h4 style="margin-top:15px; color:#2c3e50;">Summary Statistics</h4>';
                        html += '<table style="width:100%; border-collapse:collapse; margin-top:10px;">';
                        html += '<thead><tr style="background:#3498db; color:white;">';
                        html += '<th style="border:1px solid #ddd; padding:8px;">Statistics</th>';
                        html += '<th style="border:1px solid #ddd; padding:8px;">Force (N)</th>';
                        html += '<th style="border:1px solid #ddd; padding:8px;">Displacement (mm)</th>';
                        html += '</tr></thead><tbody>';
                        html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>Average</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.force && data.summary.force.avg) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.displacement && data.summary.displacement.avg) || 0)}</td></tr>`;
                        html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>SD</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.force && data.summary.force.sd) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.displacement && data.summary.displacement.sd) || 0)}</td></tr>`;
                        html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>CV%</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.force && data.summary.force.cv) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.displacement && data.summary.displacement.cv) || 0)}</td></tr>`;
                        html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>Maximum</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.force && data.summary.force.max) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.displacement && data.summary.displacement.max) || 0)}</td></tr>`;
                        html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>Minimum</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.force && data.summary.force.min) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.displacement && data.summary.displacement.min) || 0)}</td></tr>`;
                        html += '</tbody></table>';
                    }
                }
                // Handle Grab Tensile Test
                else if (testName === 'Grab Tensile Test' && data.summary) {
                    if (data.summary) {
                        html += '<h4 style="margin-top:15px; color:#2c3e50;">Summary Statistics</h4>';
                        html += '<table style="width:100%; border-collapse:collapse; margin-top:10px;">';
                        html += '<thead><tr style="background:#3498db; color:white;">';
                        html += '<th style="border:1px solid #ddd; padding:8px;">Statistics</th>';
                        html += '<th colspan="2" style="border:1px solid #ddd; padding:8px; text-align:center;">MD</th>';
                        html += '<th colspan="2" style="border:1px solid #ddd; padding:8px; text-align:center;">CD</th>';
                        html += '</tr><tr style="background:#3498db; color:white;">';
                        html += '<th style="border:1px solid #ddd; padding:8px;"></th>';
                        html += '<th style="border:1px solid #ddd; padding:8px;">Force</th>';
                        html += '<th style="border:1px solid #ddd; padding:8px;">Elongation</th>';
                        html += '<th style="border:1px solid #ddd; padding:8px;">Force</th>';
                        html += '<th style="border:1px solid #ddd; padding:8px;">Elongation</th>';
                        html += '</tr></thead><tbody>';
                        html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>Average</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.force_avg) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.elongation_avg) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.force_avg) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.elongation_avg) || 0)}</td></tr>`;
                        html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>SD</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.force_sd) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.elongation_sd) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.force_sd) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.elongation_sd) || 0)}</td></tr>`;
                        html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>CV%</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.force_cv) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.elongation_cv) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.force_cv) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.elongation_cv) || 0)}</td></tr>`;
                        html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>Maximum</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.force_max) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.elongation_max) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.force_max) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.elongation_max) || 0)}</td></tr>`;
                        html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>Minimum</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.force_min) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.md && data.summary.md.elongation_min) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.force_min) || 0)}</td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber((data.summary.cd && data.summary.cd.elongation_min) || 0)}</td></tr>`;
                        html += '</tbody></table>';
                    }
                }
                // Handle other tests with positions (Thickness, GSM) - Show only summary
                else if (data.average !== undefined) {
                    html += '<h4 style="margin-top:15px; color:#2c3e50;">Summary of Test Result</h4>';
                    html += '<table style="width:100%; border-collapse:collapse; margin-top:10px;">';
                    html += '<thead><tr style="background:#3498db; color:white;">';
                    html += '<th style="border:1px solid #ddd; padding:8px;">Statistics</th>';
                    html += `<th style="border:1px solid #ddd; padding:8px;">${testName === 'Thickness (Under 2kPa Pressure)' ? 'Thickness Test Under 2kPa (mm)' : testName === 'Mass Per Unit Area (GSM)' ? 'Mass Per Unit Area (g/m²)' : 'Value'}</th>`;
                    html += '</tr></thead><tbody>';
                    html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>Average</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber(data.average || 0)}</td></tr>`;
                    html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>SD</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber(data.sd || 0)}</td></tr>`;
                    html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>CV%</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber(data.cv || 0)}</td></tr>`;
                    html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>Maximum</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber(data.max || 0)}</td></tr>`;
                    html += `<tr><td style="border:1px solid #ddd; padding:8px;"><strong>Minimum</strong></td><td style="border:1px solid #ddd; padding:8px; text-align:center;">${formatNumber(data.min || 0)}</td></tr>`;
                    html += '</tbody></table>';
                } else {
                    // Generic display for other data
                    html += '<table style="width:100%; border-collapse:collapse; margin-top:10px;">';
                    html += '<thead><tr style="background:#3498db; color:white;"><th style="border:1px solid #ddd; padding:8px;">Parameter</th><th style="border:1px solid #ddd; padding:8px;">Value</th></tr></thead><tbody>';
                    for (const [key, value] of Object.entries(data)) {
                        if (typeof value !== 'object' && !key.includes('reference')) {
                            html += `<tr><td style="border:1px solid #ddd; padding:8px;">${escapeHtml(key)}</td><td style="border:1px solid #ddd; padding:8px;">${escapeHtml(String(value))}</td></tr>`;
                        }
                    }
                    html += '</tbody></table>';
                }
            } catch (e) {
                console.error('Error rendering test data:', testName, e, testData);
                html += '<div style="padding:10px; background:#fff3cd; border:1px solid #ffc107; border-radius:4px; color:#856404;">';
                html += '<strong>⚠ Unable to display test data</strong><br>';
                html += '<small>Error: ' + escapeHtml(e.message) + '</small>';
                html += '</div>';
            }
            
            return html;
        }
        
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function formatNumber(value, decimals = 3) {
            if (value === null || value === undefined || value === 0) return '0';
            const num = parseFloat(value);
            return isNaN(num) ? '0' : num.toFixed(decimals);
        }
        
        function formatDate(dateStr) {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return date.toLocaleDateString('en-GB');
        }
        
        function formatDateTime(dateStr) {
            if (!dateStr) return 'N/A';
            const date = new Date(dateStr);
            return date.toLocaleString('en-GB');
        }
    </script>
</body>
</html>

