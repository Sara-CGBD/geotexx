<?php
session_start();
require_once '../../config/security_config.php';
require_once '../../config/project_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$conn = SecurityConfig::getConnection();
$project = getDefaultProject($conn);
$projects = $project ? [$project] : [];

echo json_encode(['success' => true, 'projects' => $projects]);
?>


