<?php
session_start();
require_once '../config/security_config.php';

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit;
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Manufacturer Names (simplified - just the short names)
$manufacturerNames = [
    'Natpet',
    'APT',
    'Texofib',
    'Hubei Botao',
    'Jiangsu Botao',
    'Taizhu Hailun',
    'PSF',
    'Other'
];

$message = '';
$error = '';

// Fetch approved test reports (Fiber Test and Sewing Thread)
$approvedTests = [];
$testQuery = "
    (SELECT report_number COLLATE utf8mb4_unicode_ci as report_number, 
            'Fiber Test' as test_type, 
            created_at 
     FROM fiber_test_reports 
     WHERE status = 'approved')
    UNION
    (SELECT report_number COLLATE utf8mb4_unicode_ci as report_number, 
            'Sewing Thread' as test_type, 
            created_at 
     FROM sewing_thread_reports 
     WHERE status = 'approved')
    ORDER BY created_at DESC 
    LIMIT 50";
$testResult = $conn->query($testQuery);
if ($testResult) {
    while ($row = $testResult->fetch_assoc()) {
        $approvedTests[] = $row;
    }
}

// Create table if not exists (always run on page load)
$createTableSQL = "CREATE TABLE IF NOT EXISTS store_received_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entry_number VARCHAR(100) UNIQUE NOT NULL,
    date_time DATETIME NOT NULL,
    shift VARCHAR(50) NOT NULL,
    manufacturer_name VARCHAR(255) NOT NULL,
    material_type VARCHAR(255) NOT NULL,
    amount_kg DECIMAL(10,2) NOT NULL,
    reported_by VARCHAR(255) NOT NULL,
    reporter_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_entry_number (entry_number),
    INDEX idx_date_time (date_time),
    INDEX idx_manufacturer (manufacturer_name),
    INDEX idx_material_type (material_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$conn->query($createTableSQL);

// Add entry_number column if it doesn't exist
$checkColumn = $conn->query("SELECT COUNT(*) as cnt FROM INFORMATION_SCHEMA.COLUMNS 
                             WHERE TABLE_SCHEMA = DATABASE() 
                             AND TABLE_NAME = 'store_received_entries' 
                             AND COLUMN_NAME = 'entry_number'");
if ($checkColumn && $checkColumn->fetch_assoc()['cnt'] == 0) {
    $conn->query("ALTER TABLE store_received_entries ADD COLUMN entry_number VARCHAR(100) UNIQUE NOT NULL AFTER id");
}

// Create counter table for entry numbers
$counterTableSQL = "CREATE TABLE IF NOT EXISTS store_received_counters (
    date_key VARCHAR(20) PRIMARY KEY,
    counter INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$conn->query($counterTableSQL);

// Function to generate entry number (real-time DB count)
function generateEntryNumber($conn) {
    $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
    $hour = (int)$now->format('H');
    
    // If before 8 AM, use previous day's date
    if ($hour < 8) {
        $now->modify('-1 day');
    }
    
    $dateKey = $now->format('Ymd');
    
    // Count actual entries for this day key (real-time)
    $pattern = 'SRE-' . $dateKey . '-%';
    $countStmt = $conn->prepare("SELECT COUNT(*) as entry_count FROM store_received_entries WHERE entry_number LIKE ?");
    $countStmt->bind_param("s", $pattern);
    $countStmt->execute();
    $result = $countStmt->get_result();
    
    $counter = 1;
    if ($result && $row = $result->fetch_assoc()) {
        $counter = (int)$row['entry_count'] + 1;
    }
    $countStmt->close();
    
    return sprintf("SRE-%s-%03d", $dateKey, $counter);
}

// Generate next entry number for display (always fresh from DB)
$display_entry_number = generateEntryNumber($conn);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_entry'])) {
    try {
        $date_time = trim($_POST['dateTime'] ?? '');
        $shift = trim($_POST['shift'] ?? '');
        $manufacturer_name = trim($_POST['manufacturerName'] ?? '');
        $material_type = trim($_POST['materialType'] ?? '');
        $amount_kg = floatval($_POST['amountKg'] ?? 0);
        $reported_by = $_SESSION['full_name'] ?? $_SESSION['username'];
        $reporter_id = $_SESSION['user_id'];

        // Validation
        if (empty($date_time)) {
            throw new Exception("Date and time is required.");
        }
        if (empty($shift)) {
            throw new Exception("Shift is required.");
        }
        if (empty($manufacturer_name)) {
            throw new Exception("Manufacturer name is required.");
        }
        if (empty($material_type)) {
            throw new Exception("Material type is required.");
        }
        if ($amount_kg <= 0) {
            throw new Exception("Amount must be greater than 0 kg.");
        }

        // Generate entry number
        $entry_number = generateEntryNumber($conn);

        // Insert entry
        $stmt = $conn->prepare("INSERT INTO store_received_entries 
            (entry_number, date_time, shift, manufacturer_name, material_type, amount_kg, original_amount_kg, reported_by, reporter_id) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->bind_param("sssssddsi", $entry_number, $date_time, $shift, $manufacturer_name, $material_type, $amount_kg, $amount_kg, $reported_by, $reporter_id);
        
        if ($stmt->execute()) {
            $message = "✅ Store received entry saved successfully! Entry Number: " . $entry_number;
        } else {
            throw new Exception("Failed to save entry: " . $stmt->error);
        }
        
        $stmt->close();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Store Received Entry</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
    .container { max-width:1200px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08); }
    h1 { text-align:center; font-size:28px; margin-bottom:30px; color:#2c3e50; }
    .form-group { margin-bottom:20px; }
    label { font-weight:600; display:block; margin-bottom:8px; }
    input[type="text"], input[type="number"], select, textarea { padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px); }
    .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:20px; background:#f0f0f0; }
    .actions { margin-top:30px; text-align:center; }
    .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px; }
    .submit-btn { background:#2ecc71; color:#fff; }
    .clear-btn { background:#e74c3c; color:#fff; }
    .readonly { background:#ecf0f1; }
    .btn-group { display:flex; flex-wrap:wrap; gap:10px; margin-top:8px; }
    .btn { padding:10px 16px; border:2px solid #ddd; background:#fff; border-radius:6px; cursor:pointer; font-size:14px; font-weight:500; transition:all 0.3s; color:#555; }
    .btn:hover { border-color:#3498db; color:#3498db; }
    .btn.selected { background:#3498db; color:#fff; border-color:#3498db; }
  </style>
</head>
<body>
<div class="container">
  <h1> Store Received Entry</h1>

  <?php if ($message): ?>
    <div style="background:#d4edda;color:#155724;padding:12px;border-radius:6px;border:1px solid #c3e6cb;margin-bottom:15px;">
      ✅ <?php echo htmlspecialchars($message); ?>
    </div>
  <?php endif; ?>
  
  <?php if ($error): ?>
    <div style="background:#f8d7da;color:#721c24;padding:12px;border-radius:6px;border:1px solid #f5c6cb;margin-bottom:15px;">
      ❌ <?php echo htmlspecialchars($error); ?>
    </div>
  <?php endif; ?>

  <!-- Back to Dashboard Link -->
  <div style="margin-bottom: 15px;">
    <a href="../index.php" style="background:#e74c3c; color:#fff; text-decoration: none; padding: 6px 12px; border-radius: 4px; display: inline-block; font-size: 14px;">
      ← Back to Dashboard
    </a>
  </div>
  
  <div id="dateTimeDisplay" class="summary-info"></div>
  <div id="shiftBanner" class="summary-info"></div>
  
  <form method="POST" id="storeReceivedForm">
    <!-- Hidden fields for date/time and shift -->
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">
    
    <!-- Entry Number -->
    <div class="form-group">
      <label>Entry Number:</label>
      <input type="text" value="<?php echo htmlspecialchars($display_entry_number); ?>" class="readonly" readonly>
    </div>
    
    <!-- Manufacturer Name -->
    <div class="form-group">
      <label>Manufacturer Name:</label>
      <select name="manufacturerName" id="manufacturerName" required>
        <option value="">-- Select Manufacturer --</option>
        <?php foreach ($manufacturerNames as $mfr): ?>
        <option value="<?php echo htmlspecialchars($mfr); ?>"><?php echo htmlspecialchars($mfr); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    
    <!-- Material Type -->
    <div class="form-group">
      <label>Material Type:</label>
      <div class="btn-group" id="materialTypeGroup">
        <button type="button" class="btn" data-value="PP Stable Fiber" onclick="selectBtn(this, 'materialTypeGroup')">PP Stable Fiber</button>
      </div>
      <input type="hidden" id="materialType" name="materialType" value="" required>
    </div>
    
    <!-- Amount (Kg) -->
    <div class="form-group">
      <label>Amount (Kg):</label>
      <input type="number" name="amountKg" id="amountKg" step="0.01" min="0.01" placeholder="Enter amount in kg" required>
    </div>
    
    <!-- Summary Section -->
    <div id="summarySection" style="background:#e8f5e9; border:1px solid #4caf50; padding:15px; border-radius:8px; margin-bottom:20px; display:none;">
      <h3 style="margin:0 0 10px 0; color:#2e7d32; font-size:18px;">Summary - Please Review Before Submitting</h3>
      <div id="summaryContent" style="font-size:14px; line-height:1.8; color:#333;">
        <!-- Summary will be populated here -->
      </div>
    </div>
    
    <!-- Actions -->
    <div class="actions">
      <button type="submit" name="submit_entry" class="submit-btn">Submit</button>
      <button type="button" class="clear-btn" onclick="clearForm()">Clear</button>
    </div>
  </form>
</div>

<script>
// Update date, time, and shift
function updateTimeAndShift() {
    const now = new Date();
    const dhaka = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Dhaka' }));
    
    const year = dhaka.getFullYear();
    const month = String(dhaka.getMonth() + 1).padStart(2, '0');
    const day = String(dhaka.getDate()).padStart(2, '0');
    const hours = String(dhaka.getHours()).padStart(2, '0');
    const minutes = String(dhaka.getMinutes()).padStart(2, '0');
    const seconds = String(dhaka.getSeconds()).padStart(2, '0');
    
    const dateTimeStr = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
    document.getElementById("dateTime").value = dateTimeStr;
    
    const displayStr = dhaka.toLocaleString('en-US', {
        weekday: 'short',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    });
    document.getElementById("dateTimeDisplay").innerText = displayStr;
    
    const h = dhaka.getHours();
    const shift = (h >= 8 && h <= 19) ? "Day" : "Night";
    document.getElementById("shiftBanner").innerText = "Shift: " + shift;
    document.getElementById("shift").value = shift;
    
    // Update summary if fields are filled
    if (document.getElementById('manufacturerName').value || document.getElementById('materialType').value) {
        updateSummary();
    }
}
setInterval(updateTimeAndShift, 1000);
updateTimeAndShift();

// Button selection function
function selectBtn(btn, groupId) {
    const group = document.getElementById(groupId);
    const buttons = group.querySelectorAll('.btn');
    buttons.forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    
    const hiddenInput = document.getElementById(groupId.replace('Group', ''));
    if (hiddenInput) {
        const materialValue = btn.getAttribute('data-value');
        hiddenInput.value = materialValue;
    }
    
    updateSummary();
}

// Update summary section
function updateSummary() {
    const dateTime = document.getElementById('dateTime').value;
    const shift = document.getElementById('shift').value;
    const manufacturer = document.getElementById('manufacturerName').value;
    const materialType = document.getElementById('materialType').value;
    const amount = document.getElementById('amountKg').value;
    
    const summarySection = document.getElementById('summarySection');
    const summaryContent = document.getElementById('summaryContent');
    
    // Check if all required fields are filled
    if (manufacturer && materialType && amount && parseFloat(amount) > 0) {
        summarySection.style.display = 'block';
        
        let summary = '<strong>Date & Time:</strong> ' + (dateTime ? new Date(dateTime).toLocaleString('en-US', {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true
        }) : 'N/A') + '<br>';
        summary += '<strong>Shift:</strong> ' + (shift || 'N/A') + '<br>';
        summary += '<strong>Manufacturer:</strong> ' + manufacturer + '<br>';
        summary += '<strong>Material Type:</strong> ' + materialType + '<br>';
        summary += '<strong>Amount:</strong> ' + parseFloat(amount).toFixed(2) + ' kg';
        
        summaryContent.innerHTML = summary;
    } else {
        summarySection.style.display = 'none';
    }
}

// Clear form function
function clearForm() {
    document.getElementById('storeReceivedForm').reset();
    document.querySelectorAll('.btn.selected').forEach(btn => btn.classList.remove('selected'));
    document.getElementById('materialType').value = '';
    updateTimeAndShift();
    updateSummary();
}

// Add event listeners for real-time summary updates
document.getElementById('manufacturerName').addEventListener('change', updateSummary);
document.getElementById('amountKg').addEventListener('input', updateSummary);

// Form validation
document.getElementById('storeReceivedForm').addEventListener('submit', function(e) {
    const materialType = document.getElementById('materialType').value;
    const amountKg = parseFloat(document.getElementById('amountKg').value);
    
    if (!materialType) {
        e.preventDefault();
        alert('Please select a material type.');
        return false;
    }
    
    if (amountKg <= 0) {
        e.preventDefault();
        alert('Amount must be greater than 0 kg.');
        return false;
    }
});
</script>
</body>
</html>


