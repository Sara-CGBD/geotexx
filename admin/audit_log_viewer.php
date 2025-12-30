<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

// Check if user is admin or management
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$allowed_roles = ['admin', 'management', 'agm ops', 'agm operations'];
if (!in_array($user_role, $allowed_roles)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>You do not have permission to access Audit Log Viewer.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

$pageTitle = "Audit Log Viewer";

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 30;
$offset = ($page - 1) * $records_per_page;

// Filters
$filter_action = $_GET['filter_action'] ?? '';
$filter_user = $_GET['filter_user'] ?? '';
$filter_date = $_GET['filter_date'] ?? '';

// Build query (adapted for existing audit_log structure)
$where_clauses = [];
$params = [];
$types = '';

if ($filter_action) {
    $where_clauses[] = "event_type = ?";
    $params[] = $filter_action;
    $types .= 's';
}

if ($filter_user) {
    $where_clauses[] = "who_did LIKE ?";
    $params[] = "%$filter_user%";
    $types .= 's';
}

if ($filter_date) {
    $where_clauses[] = "DATE(timestamp) = ?";
    $params[] = $filter_date;
    $types .= 's';
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Get total records
$count_query = "SELECT COUNT(*) as total FROM audit_log $where_sql";
if (count($params) > 0) {
    $stmt = $conn->prepare($count_query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $total_records = $result->fetch_assoc()['total'];
    $stmt->close();
} else {
    $result = $conn->query($count_query);
    $total_records = $result->fetch_assoc()['total'];
}

$total_pages = ceil($total_records / $records_per_page);

// Get audit logs
$query = "SELECT * FROM audit_log $where_sql ORDER BY timestamp DESC LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($query);
if (count($params) > 0) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$audit_logs = $stmt->get_result();
$stmt->close();

// Get distinct event types and users for filters
$users = $conn->query("SELECT DISTINCT who_did FROM audit_log WHERE who_did IS NOT NULL ORDER BY who_did");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - GEOTEX</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: 'Inter', sans-serif;
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        min-height: 100vh;
        padding: 20px;
    }
    
    .container {
        max-width: 1400px;
        margin: 0 auto;
        background: white;
        padding: 30px;
        border-radius: 15px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
    }
    
<style>
    .filter-section {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 30px;
    }
    
    .filter-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 15px;
    }
    
    .filter-group label {
        display: block;
        font-weight: 600;
        margin-bottom: 5px;
        color: #2c3e50;
    }
    
    .filter-group select,
    .filter-group input {
        width: 100%;
        padding: 10px;
        border: 2px solid #e1e8ed;
        border-radius: 8px;
        font-size: 0.95rem;
    }
    
    .filter-buttons {
        display: flex;
        gap: 10px;
    }
    
    .stats-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
        margin-bottom: 25px;
    }
    
    .stat-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 15px;
        border-radius: 10px;
        text-align: center;
    }
    
    .stat-card-label {
        font-size: 0.85rem;
        opacity: 0.9;
    }
    
    .stat-card-value {
        font-size: 1.6rem;
        font-weight: bold;
        margin-top: 8px;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
        background: white;
    }
    
    thead {
        background: #2c3e50;
        color: white;
    }
    
    th, td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #ecf0f1;
    }
    
    tbody tr:hover {
        background: #f8f9fa;
    }
    
    .event-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .event-login { background: #d1ecf1; color: #0c5460; }
    .event-logout { background: #e2e3e5; color: #383d41; }
    .event-create { background: #d4edda; color: #155724; }
    .event-update { background: #fff3cd; color: #856404; }
    .event-delete { background: #f8d7da; color: #721c24; }
    
    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
        margin-top: 30px;
    }
    
    .pagination a,
    .pagination span {
        padding: 8px 15px;
        background: #3498db;
        color: white;
        text-decoration: none;
        border-radius: 6px;
        transition: all 0.3s;
    }
    
    .pagination a:hover {
        background: #2980b9;
        transform: translateY(-2px);
    }
    
    .pagination .current {
        background: #7f8c8d;
    }
</style>

<body>
<div class="container">

<a href="../index.php" class="btn" style="background: #7f8c8d; color: white; text-decoration: none; margin-bottom: 20px; display: inline-block;">
    <i class="fas fa-arrow-left"></i> Back to Dashboard
</a>

<h1 style="text-align: center; color: #2c3e50; margin-bottom: 10px;">
    <i class="fas fa-history"></i> Audit Log Viewer
</h1>
<p style="text-align: center; color: #7f8c8d; margin-bottom: 30px;">Comprehensive audit trail for all system operations</p>

<!-- Filters -->
<form method="GET" action="">
    <div class="filter-section">
        <h3 style="margin-bottom: 15px; color: #2c3e50;"><i class="fas fa-filter"></i> Filters</h3>
        <div class="filter-grid">
            <div class="filter-group">
                <label>Event Type:</label>
                <select name="filter_action">
                    <option value="">All Events</option>
                    <option value="login" <?php echo $filter_action === 'login' ? 'selected' : ''; ?>>Login</option>
                    <option value="logout" <?php echo $filter_action === 'logout' ? 'selected' : ''; ?>>Logout</option>
                    <option value="create" <?php echo $filter_action === 'create' ? 'selected' : ''; ?>>Create</option>
                    <option value="update" <?php echo $filter_action === 'update' ? 'selected' : ''; ?>>Update</option>
                    <option value="delete" <?php echo $filter_action === 'delete' ? 'selected' : ''; ?>>Delete</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label>User:</label>
                <select name="filter_user">
                    <option value="">All Users</option>
                    <?php while ($row = $users->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($row['who_did']); ?>" 
                            <?php echo $filter_user === $row['who_did'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($row['who_did']); ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label>Date:</label>
                <input type="date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>">
            </div>
        </div>
        
        <div class="filter-buttons">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> Apply Filters
            </button>
            <a href="audit_log_viewer.php" class="btn" style="background: #7f8c8d; color: white; text-decoration: none;">
                <i class="fas fa-times"></i> Clear
            </a>
        </div>
    </div>
</form>

<!-- Stats -->
<div class="stats-cards">
    <div class="stat-card">
        <div class="stat-card-label">Total Records</div>
        <div class="stat-card-value"><?php echo number_format($total_records); ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-label">Current Page</div>
        <div class="stat-card-value"><?php echo $page; ?> / <?php echo $total_pages; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-card-label">Showing</div>
        <div class="stat-card-value"><?php echo $audit_logs->num_rows; ?></div>
    </div>
</div>

<!-- Audit Logs Table -->
<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Timestamp</th>
            <th>Event Type</th>
            <th>User</th>
            <th>IP Address</th>
            <th>Details</th>
        </tr>
    </thead>
    <tbody>
        <?php if ($audit_logs->num_rows > 0): ?>
            <?php while ($log = $audit_logs->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $log['id']; ?></td>
                    <td><?php echo date('M d, Y H:i:s', strtotime($log['timestamp'])); ?></td>
                    <td>
                        <span class="event-badge event-<?php echo strtolower($log['event_type']); ?>">
                            <?php echo strtoupper($log['event_type']); ?>
                        </span>
                    </td>
                    <td><?php echo htmlspecialchars($log['who_did'] ?? 'System'); ?></td>
                    <td><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></td>
                    <td>
                        <?php if ($log['details']): ?>
                            <?php echo htmlspecialchars(substr($log['details'], 0, 100)) . (strlen($log['details']) > 100 ? '...' : ''); ?>
                        <?php else: ?>
                            N/A
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="6" style="text-align: center; padding: 40px; color: #7f8c8d;">
                    <i class="fas fa-inbox" style="font-size: 3rem; opacity: 0.3; display: block; margin-bottom: 10px;"></i>
                    No audit logs found matching the selected filters.
                </td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?>&filter_action=<?php echo urlencode($filter_action); ?>&filter_user=<?php echo urlencode($filter_user); ?>&filter_date=<?php echo urlencode($filter_date); ?>">
                <i class="fas fa-chevron-left"></i> Previous
            </a>
        <?php endif; ?>
        
        <span class="current">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
        
        <?php if ($page < $total_pages): ?>
            <a href="?page=<?php echo $page + 1; ?>&filter_action=<?php echo urlencode($filter_action); ?>&filter_user=<?php echo urlencode($filter_user); ?>&filter_date=<?php echo urlencode($filter_date); ?>">
                Next <i class="fas fa-chevron-right"></i>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>

</div>
</body>
</html>

