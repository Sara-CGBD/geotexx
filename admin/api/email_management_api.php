<?php
session_start();
require_once '../../config/security_config.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Role-based access control - Admin only
$user_role = strtolower(trim($_SESSION['role'] ?? ''));
if ($user_role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin role required.']);
    exit();
}

try {
    $recipientsFile = __DIR__ . '/../email_recipients.php';
    
    // GET: Fetch current email recipients
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $recipientsConfig = ['primary' => '', 'team' => [], 'management' => [], 'all' => []];
        
        if (file_exists($recipientsFile)) {
            $loaded = require $recipientsFile;
            if (is_array($loaded)) {
                $recipientsConfig = array_merge($recipientsConfig, $loaded);
            }
        }
        
        echo json_encode([
            'success' => true,
            'data' => $recipientsConfig
        ]);
    }
    
    // POST: Update email recipients
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['primary'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Primary email is required']);
            exit();
        }
        
        $primary = trim($input['primary']);
        $team = isset($input['team']) && is_array($input['team']) ? 
                array_filter(array_map('trim', $input['team'])) : [];
        $management = isset($input['management']) && is_array($input['management']) ? 
                      array_filter(array_map('trim', $input['management'])) : [];
        $all = isset($input['all']) && is_array($input['all']) ? 
               array_filter(array_map('trim', $input['all'])) : [];
        
        // Validate primary email
        if (!filter_var($primary, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid primary email address']);
            exit();
        }
        
        // Validate all emails
        $invalidEmails = [];
        foreach (array_merge($team, $management, $all) as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalidEmails[] = $email;
            }
        }
        
        if (!empty($invalidEmails)) {
            http_response_code(400);
            echo json_encode([
                'success' => false, 
                'message' => 'Invalid email addresses found',
                'invalid_emails' => $invalidEmails
            ]);
            exit();
        }
        
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
            echo json_encode([
                'success' => true,
                'message' => 'Email recipients updated successfully',
                'data' => [
                    'primary' => $primary,
                    'team' => $team,
                    'management' => $management,
                    'all' => $all
                ]
            ]);
        } else {
            throw new Exception('Failed to write to email_recipients.php file');
        }
    }
    
    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}
?>


