<?php
session_start();
require_once 'security_config.php';

// Session & security checks
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

// DB connection
$conn = new mysqli("localhost", "root", "root123", "geobagg");
if ($conn->connect_error) die("DB connection failed: " . $conn->connect_error);

// Fetch FG products
$fg_products = [];
$res = $conn->query("SELECT id, project_name FROM fg ORDER BY id DESC");
if ($res) while ($r = $res->fetch_assoc()) $fg_products[] = $r;

// Fetch clients
$clients = [];
$res = $conn->query("SELECT id, client_name FROM clients ORDER BY client_name ASC");
if ($res) while ($r = $res->fetch_assoc()) $clients[] = $r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>FG Received Entry</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
  .container { max-width:900px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08);}
  h1 { text-align:center; font-size:28px; margin-bottom:30px; }
  .form-group { margin-bottom:20px; }
  label { font-weight:600; display:block; margin-bottom:8px; }
  input[type="number"], select {
    padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px);
  }
  .readonly { background:#ecf0f1; }
  .actions { margin-top:30px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
</style>
</head>
<body>
<div class="container">
  
  <h1>FG Received Entry</h1>

  <form id="fgReceivedForm" method="post" action="../handlers/submit_fg_received_entry.php" onsubmit="return validateForm();">

    <!-- FG ID -->
    <div class="form-group">
      <label>FG ID (Auto)</label>
      <input type="text" value="Auto (DB generated)" readonly class="readonly">
    </div>

    <!-- Product -->
    <div class="form-group">
      <label>Product (FG)</label>
      <select id="product_id" name="product_id" required>
        <option value="">-- Select Product --</option>
        <?php foreach($fg_products as $p): ?>
          <option value="<?php echo $p['id']; ?>"><?php echo "FG#".$p['id']." - ".htmlspecialchars($p['project_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <!-- Received quantity -->
    <div class="form-group">
      <label>Received Quantity (kg)</label>
      <input type="number" min="1" id="received_qty" name="received_qty" required>
    </div>

    <!-- Delivery quantity -->
    <div class="form-group">
      <label>Delivery Quantity (kg)</label>
      <input type="number" min="0" id="delivery_qty" name="delivery_qty" required>
    </div>

    <!-- Client -->
    <div class="form-group">
      <label>Client</label>
      <select id="client_id" name="client_id" required>
        <option value="">-- Select Client --</option>
        <?php foreach($clients as $c): ?>
          <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['client_name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="actions">
      <button type="submit" class="submit-btn">Submit</button>
      <button type="reset" class="clear-btn">Clear</button>
    </div>
  </form>
</div>

<script>
function validateForm(){
  if(!document.getElementById("product_id").value){
    alert("Please select a product."); return false;
  }
  if(!document.getElementById("client_id").value){
    alert("Please select a client."); return false;
  }
  return true;
}
</script>
</body>
</html>


