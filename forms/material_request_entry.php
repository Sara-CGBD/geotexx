<?php
session_start();
require_once '../config/security_config.php';
require_once '../config/AccessControl.php';

// Role check: allow production users to request raw material
if (!AccessControl::hasModuleAccess($_SESSION['role'] ?? '', AccessControl::MODULE_MATERIAL_REQUEST, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access Material Requests.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role'] ?? 'Unknown') . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS material_request_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_number VARCHAR(30) UNIQUE NOT NULL,
    manufacturer_name VARCHAR(100) NOT NULL,
    material_type VARCHAR(100) NOT NULL,
    required_quantity DECIMAL(10,2) NOT NULL,
    requested_by VARCHAR(100) NOT NULL,
    request_time DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function generateRequestNumber($conn) {
    $now = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
    $hour = (int)$now->format('H');
    $baseDate = clone $now;
    if ($hour < 8) {
        $baseDate->modify('-1 day');
    }
    $baseDate->setTime(8, 0, 0);
    $dateKey = $baseDate->format('Ymd');

    $stmt = $conn->prepare("SELECT MAX(CAST(SUBSTRING(request_number, -3) AS UNSIGNED)) as last_num 
                            FROM material_request_entries 
                            WHERE request_time >= ?");
    $resetTimestamp = $baseDate->format('Y-m-d H:i:s');
    $stmt->bind_param('s', $resetTimestamp);
    $stmt->execute();
    $result = $stmt->get_result();
    $nextNum = 1;
    if ($result && $row = $result->fetch_assoc()) {
        if (!empty($row['last_num'])) {
            $nextNum = (int)$row['last_num'] + 1;
        }
    }
    $stmt->close();

    return 'MR-' . $dateKey . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
}

$displayRequestNumber = generateRequestNumber($conn);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    $manufacturer = trim($_POST['manufacturerName'] ?? '');
    $materialType = trim($_POST['materialType'] ?? '');
    $quantity = floatval($_POST['requiredQuantity'] ?? 0);
    $requestedBy = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown';

    if ($manufacturer === '' || $materialType === '' || $quantity <= 0) {
        $error = 'Please fill all fields and ensure quantity is greater than 0.';
    } else {
        $requestNumber = generateRequestNumber($conn);
        $requestTime = date('Y-m-d H:i:s');

        $stmt = $conn->prepare("INSERT INTO material_request_entries 
            (request_number, manufacturer_name, material_type, required_quantity, requested_by, request_time)
            VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssiss', $requestNumber, $manufacturer, $materialType, $quantity, $requestedBy, $requestTime);
        if ($stmt->execute()) {
            $message = "Material request saved. Request Number: " . $requestNumber;
            $displayRequestNumber = generateRequestNumber($conn);
        } else {
            $error = 'Failed to save request: ' . $stmt->error;
        }
        $stmt->close();
    }
}

$manufacturers = ['Natpet','APT','Texofib','Hubei Botao','Jiangsu Botao','Taizhu Hailun','PSF','Other'];
$materialTypes = ['PP Stable Fiber','PSF Fiber'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Material Request Entry</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Inter',sans-serif; background:#f4f6f9; color:#2c3e50; margin:0; padding:25px; }
    .container { max-width:900px; margin:0 auto; }
    .form-card {
      background:#fff;
      border-radius:12px;
      padding:30px;
      box-shadow:0 10px 30px rgba(0,0,0,0.08);
      max-width:1100px;
      margin:0 auto;
    }
    .form-card-header {
      display:flex;
      justify-content:center;
      align-items:center;
      margin-bottom:15px;
      position:relative;
    }
    .form-card-header h1 {
      font-size:28px;
      margin:0;
    }
    .back-button {
      position:absolute;
      left:0;
      padding:8px 16px;
      background:#e74c3c;
      color:#fff;
      border-radius:6px;
      text-decoration:none;
      font-weight:600;
      display:inline-flex;
      align-items:center;
    }
    .back-button i {
      margin-right:6px;
    }
    .form-group { margin-bottom:20px; }
    label { display:block; font-weight:600; margin-bottom:8px; }
    input[type=text], input[type=number], select, textarea {
      width:100%;
      padding:10px 12px;
      border-radius:6px;
      border:1px solid #d1d5db;
      font-size:14px;
    }
    .btn-group { display:flex; gap:10px; flex-wrap:wrap; }
    .material-type-btn {
      border-radius:8px;
      padding:8px 20px;
      border:1px solid #cbd5f5;
      background:#fff;
      cursor:pointer;
      font-weight:600;
      color:#1f2937;
    }
    .material-type-btn.selected {
      background:#e0e7ff;
      border-color:#4f46e5;
      color:#1d4ed8;
    }
    .actions {
      text-align:center;
      margin-top:15px;
      display:flex;
      gap:10px;
      justify-content:center;
    }
    .actions button {
      padding:10px 18px;
      border:none;
      border-radius:6px;
      font-weight:600;
      cursor:pointer;
    }
    .actions button[type="submit"] {
      background:#2196F3;
      color:#fff;
    }
    .clear-btn {
      background:#6b7280;
      color:#fff;
    }
    .message { padding:12px 15px; border-radius:8px; margin-bottom:20px; }
    .message.success { background:#e6ffed; color:#0f5132; }
    .message.error { background:#ffe6e6; color:#842029; }
    .summary { background:#f1f5f9; padding:12px 15px; border-radius:8px; margin-bottom:20px; font-size:14px; }
    .note { font-size:14px; color:#475569; margin-bottom:10px; }
    .datetime-bar {
      display:flex;
      gap:30px;
      font-size:14px;
      margin-bottom:20px;
      color:#334155;
      justify-content:center;
    }
  </style>
</head>
<body>
<div class="container">
  <div class="form-card">
    <div class="form-card-header">
      <a href="../index.php" class="back-button"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
      <h1>Material Request Entry</h1>
    </div>
  <?php if ($message): ?>
    <div class="message success"><?php echo htmlspecialchars($message); ?></div>
  <?php elseif ($error): ?>
    <div class="message error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

    <div class="summary">
    <strong>Request Number:</strong> <?php echo htmlspecialchars($displayRequestNumber); ?><br>
    Requests reset at 8:00 AM daily; this request will be processed even if available stock is less than required quantity.
  </div>
    <div class="summary" style="background:#eef2ff;">
      <div><strong>Date & Time:</strong> <span id="summaryDateTime">--</span></div>
      <div><strong>Shift:</strong> <span id="summaryShift">--</span></div>
    </div>

  <form method="POST" id="materialRequestForm">
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">
    <div class="form-group">
      <label>Material Request ID</label>
      <input type="text" value="<?php echo htmlspecialchars($displayRequestNumber); ?>" readonly class="readonly">
    </div>

    <div class="form-group">
      <label>Manufacturer Name <span style="color:red;">*</span></label>
      <select name="manufacturerName" required>
        <option value="">-- Select Manufacturer --</option>
        <?php foreach ($manufacturers as $mfr): ?>
          <option value="<?php echo htmlspecialchars($mfr); ?>"><?php echo htmlspecialchars($mfr); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label>Material Type <span style="color:red;">*</span></label>
      <div class="btn-group" id="materialTypeGroup">
        <?php foreach ($materialTypes as $type): ?>
          <button type="button" class="btn material-type-btn" data-value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($type); ?></button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="materialType" id="materialType" required>
    </div>

    <div class="form-group">
      <label>Material Required Quantity (KG) <span style="color:red;">*</span></label>
      <input type="number" name="requiredQuantity" step="0.01" min="0.01" placeholder="Enter required quantity in kg" required>
      <small class="note">Requested quantities can be fulfilled even if store stock is lower.</small>
    </div>

    <div class="summary" id="selectionSummary" style="background:#f8fafc; border:1px dashed #94a3b8; display:none;">
      <strong>Selection Summary:</strong>
      <div id="summaryContent">Fill the form to see summary</div>
    </div>

    <div class="actions">
      <button type="submit" name="submit_request">Submit</button>
      <button type="button" class="clear-btn" onclick="clearForm()">Clear</button>
    </div>
  </form>
  </div>
</div>

<script>
function updateTimeAndShift() {
  const now = new Date();
  const dhaka = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Dhaka' }));
  const formatted = dhaka.toISOString().split('.')[0].replace('T', ' ');
  document.getElementById('dateTime').value = formatted;
  const shift = (dhaka.getHours() >= 8 && dhaka.getHours() <= 19) ? 'Day' : 'Night';
  document.getElementById('shift').value = shift;
  document.getElementById('summaryDateTime').innerText = dhaka.toLocaleString('en-US', {
    weekday:'short', year:'numeric', month:'short', day:'numeric',
    hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:true
  });
  document.getElementById('summaryShift').innerText = shift;
}

function updateSelectionSummary() {
  const manufacturer = document.querySelector('select[name="manufacturerName"]').value;
  const materialType = document.getElementById('materialType').value;
  const quantity = document.querySelector('input[name="requiredQuantity"]').value;
  const summaryBox = document.getElementById('selectionSummary');
  const summaryContent = document.getElementById('summaryContent');
  if (manufacturer && materialType && quantity) {
    summaryBox.style.display = 'block';
    summaryContent.innerHTML = `Manufacturer: <strong>${manufacturer}</strong><br>
      Material: <strong>${materialType}</strong><br>
      Quantity: <strong>${parseFloat(quantity).toFixed(2)} kg</strong>`;
  } else {
    summaryBox.style.display = 'none';
  }
}

function selectMaterialType(btn) {
  document.querySelectorAll('#materialTypeGroup .material-type-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('materialType').value = btn.getAttribute('data-value');
  updateSelectionSummary();
}

document.querySelectorAll('#materialTypeGroup .material-type-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    selectMaterialType(this);
  });
});

const defaultBtn = document.querySelector('#materialTypeGroup .material-type-btn');
if (defaultBtn) {
  selectMaterialType(defaultBtn);
}

document.getElementById('materialRequestForm').addEventListener('input', updateSelectionSummary);

function clearForm() {
  document.getElementById('materialRequestForm').reset();
  document.querySelector('#materialTypeGroup .material-type-btn.selected')?.classList.remove('selected');
  if (defaultBtn) {
    selectMaterialType(defaultBtn);
  }
  document.getElementById('selectionSummary').style.display = 'none';
}

updateTimeAndShift();
setInterval(updateTimeAndShift, 1000);
</script>

</body>
</html>
