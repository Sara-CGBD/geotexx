<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . DIRECTORY_SEPARATOR . 'php_error.log');
error_reporting(E_ALL);
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: login.html");
    exit();
}

if (SecurityConfig::checkSessionTimeout()) {
    session_destroy();
    header("Location: login.html?error=timeout");
    exit();
}

SecurityConfig::updateSessionActivity();

if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    session_destroy();
    header("Location: login.html?error=disabled");
    exit();
}

date_default_timezone_set('Asia/Dhaka');

// Fetch projects from database
$projects = [];
$conn = SecurityConfig::getConnection();
$stmt = $conn->prepare("SELECT id, project_name FROM projects WHERE status = 'active' ORDER BY project_name ASC");
if ($stmt && $stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $projects[] = $row;
    }
}

$pageTitle = "Fiber Entry";
include '../includes/iframe_form_header.php';
?>

<h1><i class="fas fa-boxes"></i> Fiber Entry</h1>

<form id="fiberEntryForm">

<!-- Auto-generated Entry ID (hidden) -->
<input type="hidden" id="entryId" name="entryId" value="">

<!-- Auto Date & Time -->
<div class="form-group">
<label>Date & Time:</label>
<span id="dateTimeDisplay"></span>
<input type="hidden" id="dateTime" name="dateTime">
</div>

<!-- Shift Incharge -->
<div class="form-group">
<label>Shift Incharge:</label>
<select id="shiftIncharge" name="shiftIncharge" required>
<option value="">Select Shift Incharge</option>
<option>Monir Hossen</option>
<option>Abul Hashem</option>
<option>MD.Faysal Ahmed</option>
<option>Test Entry</option>
</select>
</div>

<!-- Project -->
<div class="form-group">
<label>Project:</label>
<select id="project" name="project" required onchange="updateAmount()">
<option value="">Select Project</option>
<?php foreach ($projects as $p): ?>
<option value="<?php echo (int)$p['id']; ?>" data-amount="">
<?php echo htmlspecialchars($p['project_name']); ?>
</option>
<?php endforeach; ?>
</select>
</div>

<!-- Amount (Auto) -->
<div class="form-group">
<label>Amount:</label>
<input type="number" id="amount" name="amount" readonly>
</div>

<!-- Origin -->
<div class="form-group">
<label>Origin:</label>
<input type="text" id="origin" name="origin" placeholder="Enter origin" required>
</div>

<!-- Reporter Name (Auto from session) -->
<div class="form-group">
<label>Reporter:</label>
<input type="text" id="reporterName" name="reporterName" value="<?php echo htmlspecialchars($_SESSION['username']); ?>" readonly>
<input type="hidden" id="reporterId" name="reporterId" value="<?php echo $_SESSION['user_id']; ?>">
</div>

<button type="button" onclick="submitFiberEntry()" class="btn btn-success">
    <i class="fas fa-save"></i> Submit Fiber Entry
</button>
</form>

<script>
function updateTime() {
    const now = new Date();
    const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
    const bangladeshTime = new Date(utc + (6 * 3600000));
    
    const options = {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    };
    
    const formattedTime = bangladeshTime.toLocaleString('en-US', options);
    document.getElementById('dateTimeDisplay').textContent = formattedTime;
    document.getElementById('dateTime').value = bangladeshTime.toISOString();
}

function updateAmount() {
    const projectSelect = document.getElementById('project');
    const amountInput = document.getElementById('amount');
    
    if (projectSelect.value) {
        // For now, set a default amount. You can modify this logic based on your requirements
        amountInput.value = '100';
    } else {
        amountInput.value = '';
    }
}

function submitFiberEntry() {
    const formData = new FormData(document.getElementById('fiberEntryForm'));
    
    fetch('../handlers/submit_fiber_entry.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(resp => {
        if (resp.success) {
            alert("Fiber entry submitted successfully!");
            document.getElementById('fiberEntryForm').reset();
            updateTime();
        } else {
            alert("Submission failed: " + (resp.message || "Unknown error"));
        }
    })
    .catch(err => {
        console.error(err);
        alert("Error submitting entry.");
    });
}

// Initialize time on page load
updateTime();
</script>

<?php include '../includes/iframe_form_footer.php'; ?>

