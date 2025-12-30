<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.html");
    exit();
}
?>
<?php
require_once __DIR__ . '/../config/config.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_recipients'])) {
        $recipientsFile = __DIR__ . '/email_recipients.php';
        
        // Get form data
        $primary = trim($_POST['primary']);
        $team = array_filter(array_map('trim', explode(',', $_POST['team'])));
        $management = array_filter(array_map('trim', explode(',', $_POST['management'])));
        $all = array_filter(array_map('trim', explode(',', $_POST['all'])));
        
        // Validate emails
        $validEmails = [];
        foreach (array_merge($team, $management, $all) as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $validEmails[] = $email;
            }
        }
        
        // Create new configuration
        $config = [
            'primary' => $primary,
            'team' => $team,
            'management' => $management,
            'all' => $all
        ];
        
        // Generate PHP code
        $phpCode = "<?php\n// Dynamic Email Recipients Configuration\n// Add or remove email addresses as needed\n\nreturn [\n";
        $phpCode .= "    // Primary recipient (main contact)\n";
        $phpCode .= "    'primary' => '" . addslashes($primary) . "',\n\n";
        $phpCode .= "    // Team members (all will receive emails)\n";
        $phpCode .= "    'team' => [\n";
        foreach ($team as $email) {
            $phpCode .= "        '" . addslashes($email) . "',\n";
        }
        $phpCode .= "    ],\n\n";
        $phpCode .= "    // Management team (receives management reports)\n";
        $phpCode .= "    'management' => [\n";
        foreach ($management as $email) {
            $phpCode .= "        '" . addslashes($email) . "',\n";
        }
        $phpCode .= "    ],\n\n";
        $phpCode .= "    // All recipients (for testing and general notifications)\n";
        $phpCode .= "    'all' => [\n";
        foreach ($all as $email) {
            $phpCode .= "        '" . addslashes($email) . "',\n";
        }
        $phpCode .= "    ]\n];\n?>";
        
        // Save to file
        if (file_put_contents($recipientsFile, $phpCode)) {
            $success = "✅ Email recipients updated successfully!";
        } else {
            $error = "❌ Failed to update email recipients.";
        }
    }
}

// Load current configuration safely (fallback when file missing)
$recipientsConfig = ['primary' => '', 'team' => [], 'management' => [], 'all' => []];
$recipientsFile = __DIR__ . '/email_recipients.php';
if (file_exists($recipientsFile)) {
    $loaded = require $recipientsFile;
    if (is_array($loaded)) {
        $recipientsConfig = array_merge($recipientsConfig, $loaded);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Management - GEOCIL Automation Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .email-group { margin-bottom: 20px; }
        .email-input { margin-bottom: 10px; }
        .help-text { font-size: 0.9em; color: #666; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">📧 Email Recipients Management</h4>
                    </div>
                    <div class="card-body">
                        <?php if (isset($success)): ?>
                            <div class="alert alert-success"><?= $success ?></div>
                        <?php endif; ?>
                        
                        <?php if (isset($error)): ?>
                            <div class="alert alert-danger"><?= $error ?></div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="email-group">
                                <h5>👤 Primary Contact</h5>
                                <div class="help-text">Main contact person for all communications</div>
                                <input type="email" name="primary" class="form-control" 
                                       value="<?= htmlspecialchars($recipientsConfig['primary']) ?>" required>
                            </div>
                            
                            <div class="email-group">
                                <h5>👥 Team Members</h5>
                                <div class="help-text">All team members who receive general notifications</div>
                                <textarea name="team" class="form-control" rows="3" 
                                          placeholder="Enter email addresses separated by commas"><?= htmlspecialchars(implode(', ', $recipientsConfig['team'])) ?></textarea>
                            </div>
                            
                            <div class="email-group">
                                <h5>📊 Management Team</h5>
                                <div class="help-text">Receives management dashboard reports</div>
                                <textarea name="management" class="form-control" rows="3" 
                                          placeholder="Enter email addresses separated by commas"><?= htmlspecialchars(implode(', ', $recipientsConfig['management'])) ?></textarea>
                            </div>
                            
                            <div class="email-group">
                                <h5>📧 All Recipients</h5>
                                <div class="help-text">Everyone who receives test emails and general notifications</div>
                                <textarea name="all" class="form-control" rows="3" 
                                          placeholder="Enter email addresses separated by commas"><?= htmlspecialchars(implode(', ', $recipientsConfig['all'])) ?></textarea>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" name="update_recipients" class="btn btn-primary">
                                    💾 Update Email Recipients
                                </button>
                                <a href="management_dashboard.php" class="btn btn-secondary">
                                    ← Back to Dashboard
                                </a>
                            </div>
                        </form>
                        
                        <hr>
                        
                        <div class="mt-4">
                            <h5>📋 Current Configuration</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>Primary:</strong><br>
                                    <code><?= htmlspecialchars($recipientsConfig['primary']) ?></code>
                                </div>
                                <div class="col-md-6">
                                    <strong>Management Team:</strong><br>
                                    <code><?= htmlspecialchars(implode(', ', $recipientsConfig['management'])) ?></code>
                                </div>
                            </div>
                            <div class="row mt-2">
                                <div class="col-md-6">
                                    <strong>Team Members:</strong><br>
                                    <code><?= htmlspecialchars(implode(', ', $recipientsConfig['team'])) ?></code>
                                </div>
                                <div class="col-md-6">
                                    <strong>All Recipients:</strong><br>
                                    <code><?= htmlspecialchars(implode(', ', $recipientsConfig['all'])) ?></code>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <h5>🧪 Test Options</h5>
                            <div class="d-grid gap-2 d-md-block">
                                <a href="test_gmail_smtp.php" class="btn btn-success btn-sm">
                                    📧 Test Gmail SMTP
                                </a>
                                <a href="test_basic_mail.php" class="btn btn-info btn-sm">
                                    📧 Test Basic Mail
                                </a>
                                <a href="management_dashboard.php" class="btn btn-warning btn-sm">
                                    📊 Send Management Report
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 
