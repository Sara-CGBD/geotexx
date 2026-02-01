<?php
session_start();
require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../forms/side_cut_entry.php');
    exit;
}

// Validate required fields
$required = ['entry_id', 'entry_date', 'shift', 'category', 'quantity_kg'];
foreach ($required as $key) {
    if (!isset($_POST[$key]) || $_POST[$key] === '') {
        header('Location: ../forms/side_cut_entry.php?error=' . urlencode('Missing field: ' . $key));
        exit;
    }
}

// Get form data
$entryId = trim($_POST['entry_id']);
$entryDate = trim($_POST['entry_date']);
$shift = trim($_POST['shift']);
$category = trim($_POST['category']);
$referenceNumber = isset($_POST['reference_number']) ? trim($_POST['reference_number']) : NULL;
$cuttingBatchNo = isset($_POST['cutting_batch_no']) ? trim($_POST['cutting_batch_no']) : NULL;
$quantityKg = (float)$_POST['quantity_kg'];
$reporterId = (int)$_SESSION['user_id']; // Always use session user_id
$remarks = isset($_POST['remarks']) ? trim($_POST['remarks']) : NULL;

// Initialize reporter name with session fallback
$reporterName = 'Unknown';
if (!empty($_SESSION['full_name']) && trim($_SESSION['full_name']) !== '') {
    $reporterName = trim($_SESSION['full_name']);
} elseif (!empty($_SESSION['username']) && trim($_SESSION['username']) !== '') {
    $reporterName = trim($_SESSION['username']);
}

// Get database connection
$conn = SecurityConfig::getConnection();

// Fetch reporter name from database to override session value if found
if ($reporterId > 0) {
    try {
        // Use COALESCE to combine first_name and last_name when full_name is NULL
        // Priority: full_name > (first_name + last_name) > username
        $stmt = $conn->prepare("SELECT 
            COALESCE(
                NULLIF(full_name, ''),
                NULLIF(CONCAT(TRIM(COALESCE(first_name, '')), ' ', TRIM(COALESCE(last_name, ''))), ' '),
                username
            ) as reporter_name
            FROM new_user WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $reporterId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    if (!empty($row['reporter_name']) && trim($row['reporter_name']) !== '' && trim($row['reporter_name']) !== '0') {
                        $reporterName = trim($row['reporter_name']);
                    }
                } else {
                    // If not found in new_user, try users table
                    $stmt2 = $conn->prepare("SELECT 
                        COALESCE(
                            NULLIF(full_name, ''),
                            NULLIF(CONCAT(TRIM(COALESCE(first_name, '')), ' ', TRIM(COALESCE(last_name, ''))), ' '),
                            username
                        ) as reporter_name
                        FROM users WHERE id = ? LIMIT 1");
                    if ($stmt2) {
                        $stmt2->bind_param('i', $reporterId);
                        if ($stmt2->execute()) {
                            $result2 = $stmt2->get_result();
                            if ($row2 = $result2->fetch_assoc()) {
                                if (!empty($row2['reporter_name']) && trim($row2['reporter_name']) !== '' && trim($row2['reporter_name']) !== '0') {
                                    $reporterName = trim($row2['reporter_name']);
                                }
                            }
                        }
                        $stmt2->close();
                    }
                }
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Error fetching reporter name: " . $e->getMessage());
        // Session fallback is already set
    }
}

// Final safety check - ensure we never have '0' or empty
if (empty($reporterName) || $reporterName === '0' || trim($reporterName) === '' || trim($reporterName) === '0') {
    $reporterName = !empty($_SESSION['username']) ? trim($_SESSION['username']) : 'Unknown';
    error_log("Reporter name was empty/0, using fallback: " . $reporterName);
}

// Additional validation
if ($category === 'Sheet Production' && empty($referenceNumber)) {
    $conn->close();
    header('Location: ../forms/side_cut_entry.php?error=' . urlencode('Reference Number is required for Sheet Production'));
    exit;
}
if ($category === 'Sewing Production' && empty($cuttingBatchNo)) {
    $conn->close();
    header('Location: ../forms/side_cut_entry.php?error=' . urlencode('CNC Cutting Batch is required for Sewing Production'));
    exit;
}

try {
    // Ensure columns exist
    $conn->query("ALTER TABLE side_cut_scrap ADD COLUMN IF NOT EXISTS entry_id VARCHAR(50) UNIQUE");
    $conn->query("ALTER TABLE side_cut_scrap ADD COLUMN IF NOT EXISTS reference_number VARCHAR(100)");
    $conn->query("ALTER TABLE side_cut_scrap ADD COLUMN IF NOT EXISTS recycled_amount_kg DECIMAL(10,2) DEFAULT 0");
    
    // Debug log
    error_log("Inserting side cut entry - Reporter ID: $reporterId, Reporter Name: $reporterName");
    
    // Insert new entry - NOTE: Changed binding type from 'i' to 's' for reporter_name
    $insertStmt = $conn->prepare("INSERT INTO side_cut_scrap (entry_id, entry_date, shift, category, reference_number, cutting_batch_no, quantity_kg, reporter_id, reporter_name, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Correct binding: 'd' for quantity_kg (double), 'i' for reporter_id (integer), 's' for reporter_name (string)
    $insertStmt->bind_param('sssssssdis', $entryId, $entryDate, $shift, $category, $referenceNumber, $cuttingBatchNo, $quantityKg, $reporterId, $reporterName, $remarks);
    
    if (!$insertStmt->execute()) {
        throw new Exception('Insert failed: ' . $insertStmt->error);
    }
    
    $affectedRows = $insertStmt->affected_rows;
    $insertStmt->close();
    $conn->close();
    
    if ($affectedRows > 0) {
        $message = 'Side Cut Entry saved successfully!';
    header('Location: ../forms/side_cut_entry.php?success=' . urlencode($message));
    } else {
        header('Location: ../forms/side_cut_entry.php?error=' . urlencode('No rows inserted. Entry ID may already exist.'));
    }
    exit;
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->close();
    }
    error_log("Side Cut Entry error: " . $e->getMessage());
    header('Location: ../forms/side_cut_entry.php?error=' . urlencode($e->getMessage()));
    exit;
}
?>