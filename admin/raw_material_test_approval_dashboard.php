<?php
session_start();
// Optional dev auto-reload
$devReload = __DIR__ . '/../dev/auto_reload.php';
if (file_exists($devReload)) {
    include_once $devReload;
}

require_once '../config/PerformanceMonitor.php';
PerformanceMonitor::start();

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../config/security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();

$user_role = strtolower(trim($_SESSION['role'] ?? ''));
$is_admin = in_array($user_role, ['admin', 'agm ops', 'agm operations']);

// Only admin and AGM Ops can access
if (!$is_admin) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>🚫 Access Denied</h2>
        <p>Only Admin and AGM Operations can access Raw Material Test Approval Dashboard.</p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

$message = '';
$error = '';

/**
 * Check if all 6 tests are approved for a store entry
 * Note: Materials are NOT automatically added to fiber_entries.
 * They must be manually added via the fiber entry form.
 */
function checkAndAddToApprovedInventory($conn, $store_entry_reference) {
    if (empty($store_entry_reference)) {
        return false;
    }
    
    // Check if all 6 tests are approved for this store entry
    $checkQuery = "
        SELECT 
            ff.status as fineness_fiber_status,
            cl.status as cut_length_fiber_status,
            tf.status as tenacity_fiber_status,
            ty.status as tenacity_yarn_status,
            ft.status as fiber_test_status,
            st.status as sewing_thread_status,
            sre.material_type
        FROM store_received_entries sre
        LEFT JOIN fineness_fiber_reports ff ON sre.entry_number COLLATE utf8mb4_unicode_ci = ff.store_entry_reference
        LEFT JOIN cut_length_fiber_reports cl ON sre.entry_number COLLATE utf8mb4_unicode_ci = cl.store_entry_reference
        LEFT JOIN tenacity_fiber_reports tf ON sre.entry_number COLLATE utf8mb4_unicode_ci = tf.store_entry_reference
        LEFT JOIN tenacity_yarn_reports ty ON sre.entry_number COLLATE utf8mb4_unicode_ci = ty.store_entry_reference
        LEFT JOIN fiber_test_reports ft ON sre.entry_number COLLATE utf8mb4_unicode_ci = ft.store_entry_reference
        LEFT JOIN sewing_thread_reports st ON sre.entry_number COLLATE utf8mb4_unicode_ci = st.store_entry_reference
        WHERE sre.entry_number = ?
    ";
    
    $stmt = $conn->prepare($checkQuery);
    $stmt->bind_param("s", $store_entry_reference);
    $stmt->execute();
    $result = $stmt->get_result();
    $material = $result->fetch_assoc();
    $stmt->close();
    
    if (!$material) {
        return false;
    }
    
    // Check if all 6 tests are approved (for fiber materials)
    $is_fiber = (stripos($material['material_type'], 'Fiber') !== false || stripos($material['material_type'], 'PP') !== false);
    
    if ($is_fiber) {
        $all_approved = (
            $material['fineness_fiber_status'] === 'approved' &&
            $material['cut_length_fiber_status'] === 'approved' &&
            $material['tenacity_fiber_status'] === 'approved' &&
            $material['tenacity_yarn_status'] === 'approved' &&
            $material['fiber_test_status'] === 'approved' &&
            $material['sewing_thread_status'] === 'approved'
        );
    } else {
        // For thread/sewing materials, only sewing thread test is required
        $all_approved = ($material['sewing_thread_status'] === 'approved');
    }
    
    // Return true if all tests are approved (material will show in Approved Material Inventory)
    // But do NOT automatically insert into fiber_entries - user must submit the form
    return $all_approved;
}

// Rejection reason options
$rejection_reasons = [
    'Fiber Test' => [
        'Sample contamination',
        'Incorrect test procedure',
        'Out of specification results',
        'Incomplete data',
        'Equipment calibration issue',
        'Documentation error'
    ],
    'Sewing Thread' => [
        'Sample quality issue',
        'Test method incorrect',
        'Results out of range',
        'Missing information',
        'Calibration needed',
        'Data entry error'
    ],
    'Tenacity Yarn' => [
        'Sample contamination',
        'Incorrect test procedure (ASTM D2256)',
        'Out of specification results',
        'Incomplete test data',
        'Equipment calibration issue',
        'Documentation error',
        'Yarn quality issue'
    ],
    'Tenacity Fiber' => [
        'Sample contamination',
        'Incorrect test procedure (EN ISO 5079)',
        'Out of specification results',
        'Incomplete test data',
        'Equipment calibration issue',
        'Documentation error',
        'Fiber quality issue'
    ],
    'Cut Length Fiber' => [
        'Sample contamination',
        'Incorrect test procedure (ASTM D5103)',
        'Out of specification results',
        'Incomplete test data',
        'Equipment calibration issue',
        'Documentation error',
        'Fiber quality issue'
    ],
    'Fineness Fiber' => [
        'Sample contamination',
        'Incorrect test procedure (ISO 1973)',
        'Out of specification results',
        'Incomplete test data',
        'Equipment calibration issue',
        'Documentation error',
        'Fiber quality issue'
    ]
];

// Handle Fiber Test approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fiber_action'])) {
    $action = $_POST['fiber_action'];
    $report_number = trim($_POST['fiber_report_number']);
    $remarks = trim($_POST['fiber_remarks'] ?? '');
    
    // Handle rejection reasons checkboxes
    if ($action === 'rejected' && isset($_POST['fiber_rejection_reasons']) && is_array($_POST['fiber_rejection_reasons'])) {
        $rejection_reasons_list = array_map('trim', $_POST['fiber_rejection_reasons']);
        $reasons_text = implode(', ', $rejection_reasons_list);
        $remarks = "Rejection Reasons: " . $reasons_text . ($remarks ? "\n\nAdditional Comments: " . $remarks : '');
    }
    
    $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
    $status = ($action === 'approved') ? 'approved' : 'rejected';
    
    // Get store_entry_reference before updating
    $getRefStmt = $conn->prepare("SELECT store_entry_reference FROM fiber_test_reports WHERE report_number = ?");
    $getRefStmt->bind_param("s", $report_number);
    $getRefStmt->execute();
    $refResult = $getRefStmt->get_result();
    $refData = $refResult->fetch_assoc();
    $store_entry_reference = $refData['store_entry_reference'] ?? '';
    $getRefStmt->close();
    
    $stmt = $conn->prepare("UPDATE fiber_test_reports SET status = ?, approved_by = ?, remarks = ? WHERE report_number = ?");
    $stmt->bind_param("ssss", $status, $approved_by, $remarks, $report_number);
    
    if ($stmt->execute()) {
        $message = "Fiber Test Report $report_number has been " . $action . " successfully!";
        // If approved, check if all tests are approved and add to fiber_entries
        if ($action === 'approved' && !empty($store_entry_reference)) {
            checkAndAddToApprovedInventory($conn, $store_entry_reference);
        }
    } else {
        $error = "Failed to update report: " . $stmt->error;
    }
    $stmt->close();
    
    header("Location: raw_material_test_approval_dashboard.php?msg=" . urlencode($message));
    exit();
}

// Handle Sewing Thread Test approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sewing_action'])) {
    $action = $_POST['sewing_action'];
    $report_number = trim($_POST['sewing_report_number']);
    $remarks = trim($_POST['sewing_remarks'] ?? '');
    
    // Handle rejection reasons checkboxes
    if ($action === 'rejected' && isset($_POST['sewing_rejection_reasons']) && is_array($_POST['sewing_rejection_reasons'])) {
        $rejection_reasons_list = array_map('trim', $_POST['sewing_rejection_reasons']);
        $reasons_text = implode(', ', $rejection_reasons_list);
        $remarks = "Rejection Reasons: " . $reasons_text . ($remarks ? "\n\nAdditional Comments: " . $remarks : '');
    }
    
    $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
    $status = ($action === 'approved') ? 'approved' : 'rejected';
    
    // Get store_entry_reference before updating
    $getRefStmt = $conn->prepare("SELECT store_entry_reference FROM sewing_thread_reports WHERE report_number = ?");
    $getRefStmt->bind_param("s", $report_number);
    $getRefStmt->execute();
    $refResult = $getRefStmt->get_result();
    $refData = $refResult->fetch_assoc();
    $store_entry_reference = $refData['store_entry_reference'] ?? '';
    $getRefStmt->close();
    
    $stmt = $conn->prepare("UPDATE sewing_thread_reports SET status = ?, approved_by = ?, remarks = ? WHERE report_number = ?");
    $stmt->bind_param("ssss", $status, $approved_by, $remarks, $report_number);
    
    if ($stmt->execute()) {
        $message = "Sewing Thread Report $report_number has been " . $action . " successfully!";
        // If approved, check if all tests are approved and add to fiber_entries
        if ($action === 'approved' && !empty($store_entry_reference)) {
            checkAndAddToApprovedInventory($conn, $store_entry_reference);
        }
    } else {
        $error = "Failed to update report: " . $stmt->error;
    }
    $stmt->close();
    
    header("Location: raw_material_test_approval_dashboard.php?msg=" . urlencode($message));
    exit();
}

// Handle Tenacity of Yarn Test approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['yarn_action'])) {
    $action = $_POST['yarn_action'];
    $report_number = trim($_POST['yarn_report_number']);
    $remarks = trim($_POST['yarn_remarks'] ?? '');
    
    // Handle rejection reasons checkboxes
    if ($action === 'rejected' && isset($_POST['yarn_rejection_reasons']) && is_array($_POST['yarn_rejection_reasons'])) {
        $rejection_reasons_list = array_map('trim', $_POST['yarn_rejection_reasons']);
        $reasons_text = implode(', ', $rejection_reasons_list);
        $remarks = "Rejection Reasons: " . $reasons_text . ($remarks ? "\n\nAdditional Comments: " . $remarks : '');
    }
    
    $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
    $status = ($action === 'approved') ? 'approved' : 'rejected';
    
    // Ensure table exists
    $conn->query("CREATE TABLE IF NOT EXISTS tenacity_yarn_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_number VARCHAR(100) UNIQUE NOT NULL,
        store_entry_reference VARCHAR(100) NULL,
        sample_description TEXT NOT NULL,
        sample_received_from VARCHAR(255) NOT NULL,
        sample_collected_from VARCHAR(255) NOT NULL,
        manufacturer_name VARCHAR(255) NULL,
        reference VARCHAR(255) NULL,
        received_date DATETIME NOT NULL,
        test_start_date DATE NOT NULL,
        test_end_date DATE NOT NULL,
        others_information TEXT,
        test_temperature DECIMAL(10,2) NOT NULL,
        rh_percent DECIMAL(5,2) NOT NULL,
        test_performed_by VARCHAR(100) NOT NULL,
        approved_by VARCHAR(100) NULL,
        test_results JSON,
        reporter_id INT NOT NULL,
        reporter_name VARCHAR(255) NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_report_number (report_number),
        INDEX idx_status (status),
        INDEX idx_store_entry_reference (store_entry_reference)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    @$conn->query("ALTER TABLE tenacity_yarn_reports ADD COLUMN IF NOT EXISTS remarks TEXT NULL AFTER approved_by");
    
    // Get store_entry_reference before updating
    $getRefStmt = $conn->prepare("SELECT store_entry_reference FROM tenacity_yarn_reports WHERE report_number = ?");
    $getRefStmt->bind_param("s", $report_number);
    $getRefStmt->execute();
    $refResult = $getRefStmt->get_result();
    $refData = $refResult->fetch_assoc();
    $store_entry_reference = $refData['store_entry_reference'] ?? '';
    $getRefStmt->close();
    
    $stmt = $conn->prepare("UPDATE tenacity_yarn_reports SET status = ?, approved_by = ?, remarks = ? WHERE report_number = ?");
    $stmt->bind_param("ssss", $status, $approved_by, $remarks, $report_number);
    
    if ($stmt->execute()) {
        $message = "Tenacity of Yarn Report $report_number has been " . $action . " successfully!";
        // If approved, check if all tests are approved and add to fiber_entries
        if ($action === 'approved' && !empty($store_entry_reference)) {
            checkAndAddToApprovedInventory($conn, $store_entry_reference);
        }
    } else {
        $error = "Failed to update report: " . $stmt->error;
    }
    $stmt->close();
    
    header("Location: raw_material_test_approval_dashboard.php?msg=" . urlencode($message));
    exit();
}

// Handle Tenacity of Fiber Test approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fiber_tenacity_action'])) {
    $action = $_POST['fiber_tenacity_action'];
    $report_number = trim($_POST['fiber_tenacity_report_number']);
    $remarks = trim($_POST['fiber_tenacity_remarks'] ?? '');
    
    // Handle rejection reasons checkboxes
    if ($action === 'rejected' && isset($_POST['fiber_tenacity_rejection_reasons']) && is_array($_POST['fiber_tenacity_rejection_reasons'])) {
        $rejection_reasons_list = array_map('trim', $_POST['fiber_tenacity_rejection_reasons']);
        $reasons_text = implode(', ', $rejection_reasons_list);
        $remarks = "Rejection Reasons: " . $reasons_text . ($remarks ? "\n\nAdditional Comments: " . $remarks : '');
    }
    
    $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
    $status = ($action === 'approved') ? 'approved' : 'rejected';
    
    // Ensure table exists
    $conn->query("CREATE TABLE IF NOT EXISTS tenacity_fiber_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_number VARCHAR(100) UNIQUE NOT NULL,
        store_entry_reference VARCHAR(100) NULL,
        sample_description TEXT NOT NULL,
        sample_received_from VARCHAR(255) NOT NULL,
        sample_collected_from VARCHAR(255) NOT NULL,
        manufacturer_name VARCHAR(255) NULL,
        reference VARCHAR(255) NULL,
        received_date DATETIME NOT NULL,
        test_start_date DATE NOT NULL,
        test_end_date DATE NOT NULL,
        others_information TEXT,
        test_temperature DECIMAL(10,2) NOT NULL,
        rh_percent DECIMAL(5,2) NOT NULL,
        test_performed_by VARCHAR(100) NOT NULL,
        approved_by VARCHAR(100) NULL,
        test_results JSON,
        reporter_id INT NOT NULL,
        reporter_name VARCHAR(255) NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_report_number (report_number),
        INDEX idx_status (status),
        INDEX idx_store_entry_reference (store_entry_reference)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    @$conn->query("ALTER TABLE tenacity_fiber_reports ADD COLUMN IF NOT EXISTS remarks TEXT NULL AFTER approved_by");
    
    // Get store_entry_reference before updating
    $getRefStmt = $conn->prepare("SELECT store_entry_reference FROM tenacity_fiber_reports WHERE report_number = ?");
    $getRefStmt->bind_param("s", $report_number);
    $getRefStmt->execute();
    $refResult = $getRefStmt->get_result();
    $refData = $refResult->fetch_assoc();
    $store_entry_reference = $refData['store_entry_reference'] ?? '';
    $getRefStmt->close();
    
    $stmt = $conn->prepare("UPDATE tenacity_fiber_reports SET status = ?, approved_by = ?, remarks = ? WHERE report_number = ?");
    $stmt->bind_param("ssss", $status, $approved_by, $remarks, $report_number);
    
    if ($stmt->execute()) {
        $message = "Tenacity of Fiber Report $report_number has been " . $action . " successfully!";
        // If approved, check if all tests are approved and add to fiber_entries
        if ($action === 'approved' && !empty($store_entry_reference)) {
            checkAndAddToApprovedInventory($conn, $store_entry_reference);
        }
    } else {
        $error = "Failed to update report: " . $stmt->error;
    }
    $stmt->close();
    
    header("Location: raw_material_test_approval_dashboard.php?msg=" . urlencode($message));
    exit();
}

// Handle Cut Length of Fiber Test approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cut_length_fiber_action'])) {
    $action = $_POST['cut_length_fiber_action'];
    $report_number = trim($_POST['cut_length_fiber_report_number']);
    $remarks = trim($_POST['cut_length_fiber_remarks'] ?? '');
    
    // Handle rejection reasons checkboxes
    if ($action === 'rejected' && isset($_POST['cut_length_fiber_rejection_reasons']) && is_array($_POST['cut_length_fiber_rejection_reasons'])) {
        $rejection_reasons_list = array_map('trim', $_POST['cut_length_fiber_rejection_reasons']);
        $reasons_text = implode(', ', $rejection_reasons_list);
        $remarks = "Rejection Reasons: " . $reasons_text . ($remarks ? "\n\nAdditional Comments: " . $remarks : '');
    }
    
    // Ensure cut_length_fiber_reports table exists
    $conn->query("CREATE TABLE IF NOT EXISTS cut_length_fiber_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_number VARCHAR(100) UNIQUE NOT NULL,
        store_entry_reference VARCHAR(100) NULL,
        sample_description TEXT NULL,
        sample_received_from VARCHAR(255) NULL,
        sample_collected_from VARCHAR(255) NULL,
        manufacturer_name VARCHAR(255) NULL,
        reference VARCHAR(255) NULL,
        received_date DATE NULL,
        test_start_date DATE NULL,
        test_end_date DATE NULL,
        others_information TEXT NULL,
        test_temperature DECIMAL(10,2) DEFAULT 0,
        rh_percent DECIMAL(5,2) DEFAULT 0,
        test_performed_by VARCHAR(100) NOT NULL,
        approved_by VARCHAR(100) NULL,
        test_results JSON,
        reporter_id INT NOT NULL,
        reporter_name VARCHAR(255) NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        remarks TEXT NULL,
        rejected_by VARCHAR(100) NULL,
        rejected_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_report_number (report_number),
        INDEX idx_status (status),
        INDEX idx_received_date (received_date),
        INDEX idx_reporter (reporter_id),
        INDEX idx_store_entry_reference (store_entry_reference),
        INDEX idx_status_reporter (status, reporter_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Update existing table structure to match new schema
    @$conn->query("ALTER TABLE cut_length_fiber_reports MODIFY sample_description TEXT NULL");
    // Only modify columns that exist (sample_received_from and sample_collected_from were removed)
    $colCheck = $conn->query("SHOW COLUMNS FROM cut_length_fiber_reports LIKE 'sample_received_from'");
    if ($colCheck && $colCheck->num_rows > 0) {
        @$conn->query("ALTER TABLE cut_length_fiber_reports MODIFY sample_received_from VARCHAR(255) NULL");
    }
    $colCheck = $conn->query("SHOW COLUMNS FROM cut_length_fiber_reports LIKE 'sample_collected_from'");
    if ($colCheck && $colCheck->num_rows > 0) {
        @$conn->query("ALTER TABLE cut_length_fiber_reports MODIFY sample_collected_from VARCHAR(255) NULL");
    }
    @$conn->query("ALTER TABLE cut_length_fiber_reports MODIFY received_date DATE NULL");
    @$conn->query("ALTER TABLE cut_length_fiber_reports MODIFY test_start_date DATE NULL");
    @$conn->query("ALTER TABLE cut_length_fiber_reports MODIFY test_end_date DATE NULL");
    // Only modify columns that exist (test_temperature and rh_percent were removed)
    $colCheck = $conn->query("SHOW COLUMNS FROM cut_length_fiber_reports LIKE 'test_temperature'");
    if ($colCheck && $colCheck->num_rows > 0) {
        @$conn->query("ALTER TABLE cut_length_fiber_reports MODIFY test_temperature DECIMAL(10,2) DEFAULT 0");
    }
    $colCheck = $conn->query("SHOW COLUMNS FROM cut_length_fiber_reports LIKE 'rh_percent'");
    if ($colCheck && $colCheck->num_rows > 0) {
        @$conn->query("ALTER TABLE cut_length_fiber_reports MODIFY rh_percent DECIMAL(5,2) DEFAULT 0");
    }
    
    $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
    $status = ($action === 'approved') ? 'approved' : 'rejected';
    
    // Get store_entry_reference before updating
    $getRefStmt = $conn->prepare("SELECT store_entry_reference FROM cut_length_fiber_reports WHERE report_number = ?");
    $getRefStmt->bind_param("s", $report_number);
    $getRefStmt->execute();
    $refResult = $getRefStmt->get_result();
    $refData = $refResult->fetch_assoc();
    $store_entry_reference = $refData['store_entry_reference'] ?? '';
    $getRefStmt->close();
    
    if ($action === 'rejected') {
        $stmt = $conn->prepare("UPDATE cut_length_fiber_reports SET status = ?, approved_by = ?, remarks = ?, rejected_by = ?, rejected_at = NOW() WHERE report_number = ?");
        $stmt->bind_param("sssss", $status, $approved_by, $remarks, $approved_by, $report_number);
    } else {
        $stmt = $conn->prepare("UPDATE cut_length_fiber_reports SET status = ?, approved_by = ?, remarks = ? WHERE report_number = ?");
        $stmt->bind_param("ssss", $status, $approved_by, $remarks, $report_number);
    }
    
    if ($stmt->execute()) {
        $message = "Cut Length of Fiber Report $report_number has been " . $action . " successfully!";
        // If approved, check if all tests are approved and add to fiber_entries
        if ($action === 'approved' && !empty($store_entry_reference)) {
            checkAndAddToApprovedInventory($conn, $store_entry_reference);
        }
    } else {
        $error = "Failed to update report: " . $stmt->error;
    }
    $stmt->close();
    
    header("Location: raw_material_test_approval_dashboard.php?msg=" . urlencode($message));
    exit();
}

// Handle Fineness of Fiber Test approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fineness_fiber_action'])) {
    $action = $_POST['fineness_fiber_action'];
    $report_number = trim($_POST['fineness_fiber_report_number']);
    $remarks = trim($_POST['fineness_fiber_remarks'] ?? '');
    
    // Handle rejection reasons checkboxes
    if ($action === 'rejected' && isset($_POST['fineness_fiber_rejection_reasons']) && is_array($_POST['fineness_fiber_rejection_reasons'])) {
        $rejection_reasons_list = array_map('trim', $_POST['fineness_fiber_rejection_reasons']);
        $reasons_text = implode(', ', $rejection_reasons_list);
        $remarks = "Rejection Reasons: " . $reasons_text . ($remarks ? "\n\nAdditional Comments: " . $remarks : '');
    }
    
    // Ensure fineness_fiber_reports table exists
    $conn->query("CREATE TABLE IF NOT EXISTS fineness_fiber_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_number VARCHAR(100) UNIQUE NOT NULL,
        store_entry_reference VARCHAR(100) NULL,
        sample_description TEXT NULL,
        sample_received_from VARCHAR(255) NULL,
        sample_collected_from VARCHAR(255) NULL,
        manufacturer_name VARCHAR(255) NULL,
        reference VARCHAR(255) NULL,
        received_date DATE NULL,
        test_start_date DATE NULL,
        test_end_date DATE NULL,
        others_information TEXT NULL,
        test_temperature DECIMAL(10,2) DEFAULT 0,
        rh_percent DECIMAL(5,2) DEFAULT 0,
        test_performed_by VARCHAR(100) NOT NULL,
        approved_by VARCHAR(100) NULL,
        test_results JSON,
        reporter_id INT NOT NULL,
        reporter_name VARCHAR(255) NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        remarks TEXT NULL,
        rejected_by VARCHAR(100) NULL,
        rejected_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_report_number (report_number),
        INDEX idx_status (status),
        INDEX idx_received_date (received_date),
        INDEX idx_reporter (reporter_id),
        INDEX idx_store_entry_reference (store_entry_reference),
        INDEX idx_status_reporter (status, reporter_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Update existing table structure to match new schema (only modify columns that exist)
    $colCheck = $conn->query("SHOW COLUMNS FROM fineness_fiber_reports LIKE 'sample_description'");
    if ($colCheck && $colCheck->num_rows > 0) {
        @$conn->query("ALTER TABLE fineness_fiber_reports MODIFY sample_description TEXT NULL");
    }
    $colCheck = $conn->query("SHOW COLUMNS FROM fineness_fiber_reports LIKE 'received_date'");
    if ($colCheck && $colCheck->num_rows > 0) {
        @$conn->query("ALTER TABLE fineness_fiber_reports MODIFY received_date DATE NULL");
    }
    $colCheck = $conn->query("SHOW COLUMNS FROM fineness_fiber_reports LIKE 'test_start_date'");
    if ($colCheck && $colCheck->num_rows > 0) {
        @$conn->query("ALTER TABLE fineness_fiber_reports MODIFY test_start_date DATE NULL");
    }
    $colCheck = $conn->query("SHOW COLUMNS FROM fineness_fiber_reports LIKE 'test_end_date'");
    if ($colCheck && $colCheck->num_rows > 0) {
        @$conn->query("ALTER TABLE fineness_fiber_reports MODIFY test_end_date DATE NULL");
    }
    $colCheck = $conn->query("SHOW COLUMNS FROM fineness_fiber_reports LIKE 'rh_percent'");
    if ($colCheck && $colCheck->num_rows > 0) {
        @$conn->query("ALTER TABLE fineness_fiber_reports MODIFY rh_percent DECIMAL(5,2) DEFAULT 0");
    }
    
    $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
    $status = ($action === 'approved') ? 'approved' : 'rejected';
    
    // Get store_entry_reference before updating
    $getRefStmt = $conn->prepare("SELECT store_entry_reference FROM fineness_fiber_reports WHERE report_number = ?");
    $getRefStmt->bind_param("s", $report_number);
    $getRefStmt->execute();
    $refResult = $getRefStmt->get_result();
    $refData = $refResult->fetch_assoc();
    $store_entry_reference = $refData['store_entry_reference'] ?? '';
    $getRefStmt->close();
    
    if ($action === 'rejected') {
        $stmt = $conn->prepare("UPDATE fineness_fiber_reports SET status = ?, approved_by = ?, remarks = ?, rejected_by = ?, rejected_at = NOW() WHERE report_number = ?");
        $stmt->bind_param("sssss", $status, $approved_by, $remarks, $approved_by, $report_number);
    } else {
        $stmt = $conn->prepare("UPDATE fineness_fiber_reports SET status = ?, approved_by = ?, remarks = ? WHERE report_number = ?");
        $stmt->bind_param("ssss", $status, $approved_by, $remarks, $report_number);
    }
    
    if ($stmt->execute()) {
        $message = "Fineness of Fiber Report $report_number has been " . $action . " successfully!";
        // If approved, check if all tests are approved and add to fiber_entries
        if ($action === 'approved' && !empty($store_entry_reference)) {
            checkAndAddToApprovedInventory($conn, $store_entry_reference);
        }
    } else {
        $error = "Failed to update report: " . $stmt->error;
    }
    $stmt->close();
    
    header("Location: raw_material_test_approval_dashboard.php?msg=" . urlencode($message));
    exit();
}

// Check for message from redirect
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Ensure fineness_fiber_reports table exists
$conn->query("CREATE TABLE IF NOT EXISTS fineness_fiber_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_number VARCHAR(100) UNIQUE NOT NULL,
    store_entry_reference VARCHAR(100) NULL,
    sample_description TEXT NULL,
    sample_received_from VARCHAR(255) NULL,
    sample_collected_from VARCHAR(255) NULL,
    manufacturer_name VARCHAR(255) NULL,
    reference VARCHAR(255) NULL,
    received_date DATE NULL,
    test_start_date DATE NULL,
    test_end_date DATE NULL,
    others_information TEXT NULL,
    test_temperature DECIMAL(10,2) DEFAULT 0,
    rh_percent DECIMAL(5,2) DEFAULT 0,
    test_performed_by VARCHAR(100) NOT NULL,
    approved_by VARCHAR(100) NULL,
    test_results JSON,
    reporter_id INT NOT NULL,
    reporter_name VARCHAR(255) NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    remarks TEXT NULL,
    rejected_by VARCHAR(100) NULL,
    rejected_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_report_number (report_number),
    INDEX idx_status (status),
    INDEX idx_received_date (received_date),
    INDEX idx_reporter (reporter_id),
    INDEX idx_store_entry_reference (store_entry_reference),
    INDEX idx_status_reporter (status, reporter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Update existing table structure to match new schema (only modify columns that exist)
$colCheck = $conn->query("SHOW COLUMNS FROM fineness_fiber_reports LIKE 'sample_description'");
if ($colCheck && $colCheck->num_rows > 0) {
    @$conn->query("ALTER TABLE fineness_fiber_reports MODIFY sample_description TEXT NULL");
}
$colCheck = $conn->query("SHOW COLUMNS FROM fineness_fiber_reports LIKE 'received_date'");
if ($colCheck && $colCheck->num_rows > 0) {
    @$conn->query("ALTER TABLE fineness_fiber_reports MODIFY received_date DATE NULL");
}
$colCheck = $conn->query("SHOW COLUMNS FROM fineness_fiber_reports LIKE 'test_start_date'");
if ($colCheck && $colCheck->num_rows > 0) {
    @$conn->query("ALTER TABLE fineness_fiber_reports MODIFY test_start_date DATE NULL");
}
$colCheck = $conn->query("SHOW COLUMNS FROM fineness_fiber_reports LIKE 'test_end_date'");
if ($colCheck && $colCheck->num_rows > 0) {
    @$conn->query("ALTER TABLE fineness_fiber_reports MODIFY test_end_date DATE NULL");
}
$colCheck = $conn->query("SHOW COLUMNS FROM fineness_fiber_reports LIKE 'rh_percent'");
if ($colCheck && $colCheck->num_rows > 0) {
    @$conn->query("ALTER TABLE fineness_fiber_reports MODIFY rh_percent DECIMAL(5,2) DEFAULT 0");
}

// Ensure cut_length_fiber_reports table exists
$conn->query("CREATE TABLE IF NOT EXISTS cut_length_fiber_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_number VARCHAR(100) UNIQUE NOT NULL,
    store_entry_reference VARCHAR(100) NULL,
    sample_description TEXT NULL,
    sample_received_from VARCHAR(255) NULL,
    sample_collected_from VARCHAR(255) NULL,
    manufacturer_name VARCHAR(255) NULL,
    reference VARCHAR(255) NULL,
    received_date DATE NULL,
    test_start_date DATE NULL,
    test_end_date DATE NULL,
    others_information TEXT NULL,
    test_temperature DECIMAL(10,2) DEFAULT 0,
    rh_percent DECIMAL(5,2) DEFAULT 0,
    test_performed_by VARCHAR(100) NOT NULL,
    approved_by VARCHAR(100) NULL,
    test_results JSON,
    reporter_id INT NOT NULL,
    reporter_name VARCHAR(255) NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    remarks TEXT NULL,
    rejected_by VARCHAR(100) NULL,
    rejected_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_report_number (report_number),
    INDEX idx_status (status),
    INDEX idx_received_date (received_date),
    INDEX idx_reporter (reporter_id),
    INDEX idx_store_entry_reference (store_entry_reference),
    INDEX idx_status_reporter (status, reporter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Update existing table structure to match new schema (only modify columns that exist)
$colCheck = $conn->query("SHOW COLUMNS FROM cut_length_fiber_reports LIKE 'sample_description'");
if ($colCheck && $colCheck->num_rows > 0) {
    @$conn->query("ALTER TABLE cut_length_fiber_reports MODIFY sample_description TEXT NULL");
}
// Only modify columns that exist (sample_received_from and sample_collected_from were removed)
$colCheck = $conn->query("SHOW COLUMNS FROM cut_length_fiber_reports LIKE 'sample_received_from'");
if ($colCheck && $colCheck->num_rows > 0) {
    @$conn->query("ALTER TABLE cut_length_fiber_reports MODIFY sample_received_from VARCHAR(255) NULL");
}
$colCheck = $conn->query("SHOW COLUMNS FROM cut_length_fiber_reports LIKE 'sample_collected_from'");
if ($colCheck && $colCheck->num_rows > 0) {
    @$conn->query("ALTER TABLE cut_length_fiber_reports MODIFY sample_collected_from VARCHAR(255) NULL");
}
$colCheck = $conn->query("SHOW COLUMNS FROM cut_length_fiber_reports LIKE 'received_date'");
if ($colCheck && $colCheck->num_rows > 0) {
    @$conn->query("ALTER TABLE cut_length_fiber_reports MODIFY received_date DATE NULL");
}
$colCheck = $conn->query("SHOW COLUMNS FROM cut_length_fiber_reports LIKE 'test_start_date'");
if ($colCheck && $colCheck->num_rows > 0) {
    @$conn->query("ALTER TABLE cut_length_fiber_reports MODIFY test_start_date DATE NULL");
}
$colCheck = $conn->query("SHOW COLUMNS FROM cut_length_fiber_reports LIKE 'test_end_date'");
if ($colCheck && $colCheck->num_rows > 0) {
    @$conn->query("ALTER TABLE cut_length_fiber_reports MODIFY test_end_date DATE NULL");
}
// Only modify columns that exist (test_temperature and rh_percent were removed)
$colCheck = $conn->query("SHOW COLUMNS FROM cut_length_fiber_reports LIKE 'test_temperature'");
if ($colCheck && $colCheck->num_rows > 0) {
    @$conn->query("ALTER TABLE cut_length_fiber_reports MODIFY test_temperature DECIMAL(10,2) DEFAULT 0");
}
$colCheck = $conn->query("SHOW COLUMNS FROM cut_length_fiber_reports LIKE 'rh_percent'");
if ($colCheck && $colCheck->num_rows > 0) {
    @$conn->query("ALTER TABLE cut_length_fiber_reports MODIFY rh_percent DECIMAL(5,2) DEFAULT 0");
}

// Ensure tenacity_fiber_reports table exists
$conn->query("CREATE TABLE IF NOT EXISTS tenacity_fiber_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_number VARCHAR(100) UNIQUE NOT NULL,
    store_entry_reference VARCHAR(100) NULL,
    sample_description TEXT NOT NULL,
    sample_received_from VARCHAR(255) NOT NULL,
    sample_collected_from VARCHAR(255) NOT NULL,
    manufacturer_name VARCHAR(255) NULL,
    reference VARCHAR(255) NULL,
    received_date DATETIME NOT NULL,
    test_start_date DATE NOT NULL,
    test_end_date DATE NOT NULL,
    others_information TEXT,
    test_temperature DECIMAL(10,2) NOT NULL,
    rh_percent DECIMAL(5,2) NOT NULL,
    test_performed_by VARCHAR(100) NOT NULL,
    approved_by VARCHAR(100) NULL,
    test_results JSON,
    reporter_id INT NOT NULL,
    reporter_name VARCHAR(255) NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_report_number (report_number),
    INDEX idx_status (status),
    INDEX idx_store_entry_reference (store_entry_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Ensure tenacity_yarn_reports table exists
$conn->query("CREATE TABLE IF NOT EXISTS tenacity_yarn_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_number VARCHAR(100) UNIQUE NOT NULL,
    store_entry_reference VARCHAR(100) NULL,
    sample_description TEXT NOT NULL,
    sample_received_from VARCHAR(255) NOT NULL,
    sample_collected_from VARCHAR(255) NOT NULL,
    manufacturer_name VARCHAR(255) NULL,
    reference VARCHAR(255) NULL,
    received_date DATETIME NOT NULL,
    test_start_date DATE NOT NULL,
    test_end_date DATE NOT NULL,
    others_information TEXT,
    test_temperature DECIMAL(10,2) NOT NULL,
    rh_percent DECIMAL(5,2) NOT NULL,
    test_performed_by VARCHAR(100) NOT NULL,
    approved_by VARCHAR(100) NULL,
    test_results JSON,
    reporter_id INT NOT NULL,
    reporter_name VARCHAR(255) NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_report_number (report_number),
    INDEX idx_status (status),
    INDEX idx_store_entry_reference (store_entry_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Fetch all store entries with their test status
// Use prepared statements to get the most recent report for each test type (to handle resubmissions)
$storeEntriesWithTests = [];

// First, get all store entries
$storeEntriesResult = $conn->query("
    SELECT 
        entry_number,
        material_type,
        amount_kg,
        date_time as received_date,
        manufacturer_name
    FROM store_received_entries
    ORDER BY date_time DESC, created_at DESC
");

// Cache which test tables have store_entry_reference column
$storeRefTables = [
    'fiber_test_reports',
    'sewing_thread_reports',
    'fineness_fiber_reports',
    'cut_length_fiber_reports',
    'tenacity_fiber_reports',
    'tenacity_yarn_reports'
];
$storeRefExists = [];
foreach ($storeRefTables as $tbl) {
    $colCheck = $conn->query("SHOW COLUMNS FROM $tbl LIKE 'store_entry_reference'");
    $hasCol = ($colCheck && $colCheck->num_rows > 0);
    if (!$hasCol) {
        // Attempt to add the column if missing
        $conn->query("ALTER TABLE $tbl ADD COLUMN store_entry_reference VARCHAR(100) NULL AFTER report_number");
        // Re-check after alter
        $colCheck2 = $conn->query("SHOW COLUMNS FROM $tbl LIKE 'store_entry_reference'");
        $hasCol = ($colCheck2 && $colCheck2->num_rows > 0);
    }
    $storeRefExists[$tbl] = $hasCol;
}

if ($storeEntriesResult) {
    while ($storeEntry = $storeEntriesResult->fetch_assoc()) {
        $entryNumber = $storeEntry['entry_number'];
        
        // Get the most recent fiber test for this entry
        if (!empty($storeRefExists['fiber_test_reports'])) {
        $fiberTestStmt = $conn->prepare("
            SELECT id, report_number, status, sample_tested_date as test_date, 
                   test_performed_by as tester, created_at
            FROM fiber_test_reports
            WHERE CAST(TRIM(store_entry_reference) AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(TRIM(?) AS CHAR) COLLATE utf8mb4_unicode_ci
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $fiberTestStmt->bind_param("s", $entryNumber);
        $fiberTestStmt->execute();
        $fiberTest = $fiberTestStmt->get_result()->fetch_assoc();
        $fiberTestStmt->close();
        } else {
            $fiberTest = null;
        }
        
        // Get the most recent sewing test for this entry
        if (!empty($storeRefExists['sewing_thread_reports'])) {
        $sewingTestStmt = $conn->prepare("
            SELECT id, report_number, status, test_start_date as test_date, 
                   test_performed_by as tester, created_at
            FROM sewing_thread_reports
            WHERE CAST(TRIM(store_entry_reference) AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(TRIM(?) AS CHAR) COLLATE utf8mb4_unicode_ci
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $sewingTestStmt->bind_param("s", $entryNumber);
        $sewingTestStmt->execute();
        $sewingTest = $sewingTestStmt->get_result()->fetch_assoc();
        $sewingTestStmt->close();
        } else {
            $sewingTest = null;
        }
        
        // Get the most recent fineness fiber test for this entry
        if (!empty($storeRefExists['fineness_fiber_reports'])) {
        $finenessFiberStmt = $conn->prepare("
            SELECT id, report_number, status, test_start_date as test_date, 
                   test_performed_by as tester, created_at
            FROM fineness_fiber_reports
            WHERE CAST(TRIM(store_entry_reference) AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(TRIM(?) AS CHAR) COLLATE utf8mb4_unicode_ci
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $finenessFiberStmt->bind_param("s", $entryNumber);
        $finenessFiberStmt->execute();
        $finenessFiberTest = $finenessFiberStmt->get_result()->fetch_assoc();
        $finenessFiberStmt->close();
        } else {
            $finenessFiberTest = null;
        }
        
        // Get the most recent cut length fiber test for this entry
        if (!empty($storeRefExists['cut_length_fiber_reports'])) {
        $cutLengthFiberStmt = $conn->prepare("
            SELECT id, report_number, status, test_start_date as test_date, 
                   test_performed_by as tester, created_at
            FROM cut_length_fiber_reports
            WHERE CAST(TRIM(store_entry_reference) AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(TRIM(?) AS CHAR) COLLATE utf8mb4_unicode_ci
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $cutLengthFiberStmt->bind_param("s", $entryNumber);
        $cutLengthFiberStmt->execute();
        $cutLengthFiberTest = $cutLengthFiberStmt->get_result()->fetch_assoc();
        $cutLengthFiberStmt->close();
        } else {
            $cutLengthFiberTest = null;
        }
        
        // Get the most recent tenacity fiber test for this entry
        if (!empty($storeRefExists['tenacity_fiber_reports'])) {
        $tenacityFiberStmt = $conn->prepare("
            SELECT id, report_number, status, test_start_date as test_date, 
                   test_performed_by as tester, created_at
            FROM tenacity_fiber_reports
            WHERE CAST(TRIM(store_entry_reference) AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(TRIM(?) AS CHAR) COLLATE utf8mb4_unicode_ci
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $tenacityFiberStmt->bind_param("s", $entryNumber);
        $tenacityFiberStmt->execute();
        $tenacityFiberTest = $tenacityFiberStmt->get_result()->fetch_assoc();
        $tenacityFiberStmt->close();
        } else {
            $tenacityFiberTest = null;
        }
        
        // Get the most recent tenacity yarn test for this entry
        if (!empty($storeRefExists['tenacity_yarn_reports'])) {
        $tenacityYarnStmt = $conn->prepare("
            SELECT id, report_number, status, test_start_date as test_date, 
                   test_performed_by as tester, created_at
            FROM tenacity_yarn_reports
            WHERE CAST(TRIM(store_entry_reference) AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(TRIM(?) AS CHAR) COLLATE utf8mb4_unicode_ci
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $tenacityYarnStmt->bind_param("s", $entryNumber);
        $tenacityYarnStmt->execute();
        $tenacityYarnTest = $tenacityYarnStmt->get_result()->fetch_assoc();
        $tenacityYarnStmt->close();
        } else {
            $tenacityYarnTest = null;
        }
        
        // Normalize status values (handle NULL and empty strings)
        // If status is empty/NULL but report exists, treat as 'pending' (needs approval)
        $normalizeStatus = function($status, $reportExists = true) {
            $trimmed = trim($status ?? '');
            if (empty($trimmed) || $status === null) {
                // If report exists but status is empty, it's pending approval
                return $reportExists ? 'pending' : 'not_submitted';
            }
            return $trimmed;
        };
        
        // Normalize all status values
        // If a report exists but status is empty, treat as 'pending' (needs approval)
        if ($fiberTest) {
            $fiberTest['status'] = $normalizeStatus($fiberTest['status'] ?? null, true);
        }
        if ($sewingTest) {
            $sewingTest['status'] = $normalizeStatus($sewingTest['status'] ?? null, true);
        }
        if ($finenessFiberTest) {
            $finenessFiberTest['status'] = $normalizeStatus($finenessFiberTest['status'] ?? null, true);
        }
        if ($cutLengthFiberTest) {
            $cutLengthFiberTest['status'] = $normalizeStatus($cutLengthFiberTest['status'] ?? null, true);
        }
        if ($tenacityFiberTest) {
            $tenacityFiberTest['status'] = $normalizeStatus($tenacityFiberTest['status'] ?? null, true);
        }
        if ($tenacityYarnTest) {
            $tenacityYarnTest['status'] = $normalizeStatus($tenacityYarnTest['status'] ?? null, true);
        }
        
        // Check if any test is pending or if no tests exist
        $hasPendingTest = false;
        $hasAnyTest = false;
        
        if ($fiberTest && $fiberTest['status'] !== 'not_submitted') {
            $hasAnyTest = true;
            if ($fiberTest['status'] === 'pending') $hasPendingTest = true;
        }
        if ($sewingTest && $sewingTest['status'] !== 'not_submitted') {
            $hasAnyTest = true;
            if ($sewingTest['status'] === 'pending') $hasPendingTest = true;
        }
        if ($finenessFiberTest && $finenessFiberTest['status'] !== 'not_submitted') {
            $hasAnyTest = true;
            if ($finenessFiberTest['status'] === 'pending') $hasPendingTest = true;
        }
        if ($cutLengthFiberTest && $cutLengthFiberTest['status'] !== 'not_submitted') {
            $hasAnyTest = true;
            if ($cutLengthFiberTest['status'] === 'pending') $hasPendingTest = true;
        }
        if ($tenacityFiberTest && $tenacityFiberTest['status'] !== 'not_submitted') {
            $hasAnyTest = true;
            if ($tenacityFiberTest['status'] === 'pending') $hasPendingTest = true;
        }
        if ($tenacityYarnTest && $tenacityYarnTest['status'] !== 'not_submitted') {
            $hasAnyTest = true;
            if ($tenacityYarnTest['status'] === 'pending') $hasPendingTest = true;
        }
        
        // Only include entries with pending tests or no tests at all
        if ($hasPendingTest || !$hasAnyTest) {
            $material = $storeEntry;
            
            // Initialize all status fields with default values
            $material['fiber_status'] = 'not_submitted';
            $material['sewing_status'] = 'not_submitted';
            $material['fineness_fiber_status'] = 'not_submitted';
            $material['cut_length_fiber_status'] = 'not_submitted';
            $material['fiber_tenacity_status'] = 'not_submitted';
            $material['yarn_status'] = 'not_submitted';
            
            // Add fiber test info
            if ($fiberTest) {
                $material['fiber_test_id'] = $fiberTest['id'];
                $material['fiber_report_number'] = $fiberTest['report_number'];
                $material['fiber_status'] = $fiberTest['status'];
                $material['fiber_test_date'] = $fiberTest['test_date'];
                $material['fiber_tester'] = $fiberTest['tester'];
                $material['fiber_created_at'] = $fiberTest['created_at'];
            }
            
            // Add sewing test info
            if ($sewingTest) {
                $material['sewing_test_id'] = $sewingTest['id'];
                $material['sewing_report_number'] = $sewingTest['report_number'];
                $material['sewing_status'] = $sewingTest['status'];
                $material['sewing_test_date'] = $sewingTest['test_date'];
                $material['sewing_tester'] = $sewingTest['tester'];
                $material['sewing_created_at'] = $sewingTest['created_at'];
            }
            
            // Add fineness fiber test info
            if ($finenessFiberTest) {
                $material['fineness_fiber_test_id'] = $finenessFiberTest['id'];
                $material['fineness_fiber_report_number'] = $finenessFiberTest['report_number'];
                $material['fineness_fiber_status'] = $finenessFiberTest['status'];
                $material['fineness_fiber_test_date'] = $finenessFiberTest['test_date'];
                $material['fineness_fiber_tester'] = $finenessFiberTest['tester'];
                $material['fineness_fiber_created_at'] = $finenessFiberTest['created_at'];
            }
            
            // Add cut length fiber test info
            if ($cutLengthFiberTest) {
                $material['cut_length_fiber_test_id'] = $cutLengthFiberTest['id'];
                $material['cut_length_fiber_report_number'] = $cutLengthFiberTest['report_number'];
                $material['cut_length_fiber_status'] = $cutLengthFiberTest['status'];
                $material['cut_length_fiber_test_date'] = $cutLengthFiberTest['test_date'];
                $material['cut_length_fiber_tester'] = $cutLengthFiberTest['tester'];
                $material['cut_length_fiber_created_at'] = $cutLengthFiberTest['created_at'];
            }
            
            // Add tenacity fiber test info
            if ($tenacityFiberTest) {
                $material['fiber_tenacity_test_id'] = $tenacityFiberTest['id'];
                $material['fiber_tenacity_report_number'] = $tenacityFiberTest['report_number'];
                $material['fiber_tenacity_status'] = $tenacityFiberTest['status'];
                $material['fiber_tenacity_test_date'] = $tenacityFiberTest['test_date'];
                $material['fiber_tenacity_tester'] = $tenacityFiberTest['tester'];
                $material['fiber_tenacity_created_at'] = $tenacityFiberTest['created_at'];
            }
            
            // Add tenacity yarn test info
            if ($tenacityYarnTest) {
                $material['yarn_test_id'] = $tenacityYarnTest['id'];
                $material['yarn_report_number'] = $tenacityYarnTest['report_number'];
                $material['yarn_status'] = $tenacityYarnTest['status'];
                $material['yarn_test_date'] = $tenacityYarnTest['test_date'];
                $material['yarn_tester'] = $tenacityYarnTest['tester'];
                $material['yarn_created_at'] = $tenacityYarnTest['created_at'];
            }
            
            $storeEntriesWithTests[] = $material;
        }
    }
}

/* Legacy query removed - using prepared statements above
$storeQuery = $conn->query("
    SELECT 
        sre.entry_number,
        sre.material_type,
        sre.amount_kg,
        sre.date_time as received_date,
        sre.manufacturer_name,
        ft.id as fiber_test_id,
        ft.report_number as fiber_report_number,
        ft.status as fiber_status,
        ft.sample_tested_date as fiber_test_date,
        ft.test_performed_by as fiber_tester,
        ft.created_at as fiber_created_at,
        st.id as sewing_test_id,
        st.report_number as sewing_report_number,
        st.status as sewing_status,
        st.test_start_date as sewing_test_date,
        st.test_performed_by as sewing_tester,
        st.created_at as sewing_created_at,
        ffr.id as fineness_fiber_test_id,
        ffr.report_number as fineness_fiber_report_number,
        ffr.status as fineness_fiber_status,
        ffr.test_start_date as fineness_fiber_test_date,
        ffr.test_performed_by as fineness_fiber_tester,
        ffr.created_at as fineness_fiber_created_at,
        clfr.id as cut_length_fiber_test_id,
        clfr.report_number as cut_length_fiber_report_number,
        clfr.status as cut_length_fiber_status,
        clfr.test_start_date as cut_length_fiber_test_date,
        clfr.test_performed_by as cut_length_fiber_tester,
        clfr.created_at as cut_length_fiber_created_at,
        tfr.id as fiber_tenacity_test_id,
        tfr.report_number as fiber_tenacity_report_number,
        tfr.status as fiber_tenacity_status,
        tfr.test_start_date as fiber_tenacity_test_date,
        tfr.test_performed_by as fiber_tenacity_tester,
        tfr.created_at as fiber_tenacity_created_at,
        tyr.id as yarn_test_id,
        tyr.report_number as yarn_report_number,
        tyr.status as yarn_status,
        tyr.test_start_date as yarn_test_date,
        tyr.test_performed_by as yarn_tester,
        tyr.created_at as yarn_created_at
    FROM store_received_entries sre
    LEFT JOIN (
        SELECT ft1.* 
        FROM fiber_test_reports ft1
        WHERE ft1.id = (
            SELECT id 
            FROM fiber_test_reports 
            WHERE store_entry_reference = ft1.store_entry_reference 
              AND store_entry_reference IS NOT NULL
              AND store_entry_reference != ''
            ORDER BY created_at DESC, id DESC 
            LIMIT 1
        )
    ) ft ON TRIM(sre.entry_number) COLLATE utf8mb4_unicode_ci = TRIM(ft.store_entry_reference) COLLATE utf8mb4_unicode_ci
    LEFT JOIN (
        SELECT st1.* 
        FROM sewing_thread_reports st1
        WHERE st1.id = (
            SELECT id 
            FROM sewing_thread_reports 
            WHERE store_entry_reference = st1.store_entry_reference 
              AND store_entry_reference IS NOT NULL
              AND store_entry_reference != ''
            ORDER BY created_at DESC, id DESC 
            LIMIT 1
        )
    ) st ON TRIM(sre.entry_number) COLLATE utf8mb4_unicode_ci = TRIM(st.store_entry_reference) COLLATE utf8mb4_unicode_ci
    LEFT JOIN (
        SELECT ffr1.* 
        FROM fineness_fiber_reports ffr1
        WHERE ffr1.id = (
            SELECT id 
            FROM fineness_fiber_reports 
            WHERE store_entry_reference = ffr1.store_entry_reference 
              AND store_entry_reference IS NOT NULL
              AND store_entry_reference != ''
            ORDER BY created_at DESC, id DESC 
            LIMIT 1
        )
    ) ffr ON TRIM(sre.entry_number) COLLATE utf8mb4_unicode_ci = TRIM(ffr.store_entry_reference) COLLATE utf8mb4_unicode_ci
    LEFT JOIN (
        SELECT clfr1.* 
        FROM cut_length_fiber_reports clfr1
        WHERE clfr1.id = (
            SELECT id 
            FROM cut_length_fiber_reports 
            WHERE store_entry_reference = clfr1.store_entry_reference 
              AND store_entry_reference IS NOT NULL
              AND store_entry_reference != ''
            ORDER BY created_at DESC, id DESC 
            LIMIT 1
        )
    ) clfr ON TRIM(sre.entry_number) COLLATE utf8mb4_unicode_ci = TRIM(clfr.store_entry_reference) COLLATE utf8mb4_unicode_ci
    LEFT JOIN (
        SELECT tfr1.* 
        FROM tenacity_fiber_reports tfr1
        WHERE tfr1.id = (
            SELECT id 
            FROM tenacity_fiber_reports 
            WHERE store_entry_reference = tfr1.store_entry_reference 
              AND store_entry_reference IS NOT NULL
              AND store_entry_reference != ''
            ORDER BY created_at DESC, id DESC 
            LIMIT 1
        )
    ) tfr ON TRIM(sre.entry_number) COLLATE utf8mb4_unicode_ci = TRIM(tfr.store_entry_reference) COLLATE utf8mb4_unicode_ci
    LEFT JOIN (
        SELECT tyr1.* 
        FROM tenacity_yarn_reports tyr1
        WHERE tyr1.id = (
            SELECT id 
            FROM tenacity_yarn_reports 
            WHERE store_entry_reference = tyr1.store_entry_reference 
              AND store_entry_reference IS NOT NULL
              AND store_entry_reference != ''
            ORDER BY created_at DESC, id DESC 
            LIMIT 1
        )
    ) tyr ON TRIM(sre.entry_number) COLLATE utf8mb4_unicode_ci = TRIM(tyr.store_entry_reference) COLLATE utf8mb4_unicode_ci
    WHERE ft.status = 'pending' OR st.status = 'pending' OR ffr.status = 'pending' 
       OR clfr.status = 'pending' OR tfr.status = 'pending' OR tyr.status = 'pending' 
       OR (ft.id IS NULL AND st.id IS NULL AND ffr.id IS NULL AND clfr.id IS NULL 
           AND tfr.id IS NULL AND tyr.id IS NULL)
    ORDER BY sre.date_time DESC, sre.created_at DESC
");
$storeQuery = null;
*/

// Legacy query disabled; ensure variable exists
$storeQuery = null;

if ($storeQuery) {
    while ($row = $storeQuery->fetch_assoc()) {
        $storeEntriesWithTests[] = $row;
    }
}

// Calculate statistics
$totalPendingFiber = 0;
$totalPendingSewing = 0;
$totalPendingFinenessFiber = 0;
$totalPendingCutLengthFiber = 0;
$totalPendingTenacityFiber = 0;
$totalPendingYarn = 0;
$totalMaterials = count($storeEntriesWithTests);

foreach ($storeEntriesWithTests as $material) {
    if (isset($material['fiber_status']) && $material['fiber_status'] === 'pending') $totalPendingFiber++;
    if (isset($material['sewing_status']) && $material['sewing_status'] === 'pending') $totalPendingSewing++;
    if (isset($material['fineness_fiber_status']) && $material['fineness_fiber_status'] === 'pending') $totalPendingFinenessFiber++;
    if (isset($material['cut_length_fiber_status']) && $material['cut_length_fiber_status'] === 'pending') $totalPendingCutLengthFiber++;
    if (isset($material['fiber_tenacity_status']) && $material['fiber_tenacity_status'] === 'pending') $totalPendingTenacityFiber++;
    if (isset($material['yarn_status']) && $material['yarn_status'] === 'pending') $totalPendingYarn++;
}

$totalPending = $totalPendingFiber + $totalPendingSewing + $totalPendingFinenessFiber + $totalPendingCutLengthFiber + $totalPendingTenacityFiber + $totalPendingYarn;

// Fix any existing records with empty or NULL status - set them to 'pending' (silent fix, no display)
$fixQuery = $conn->query("
    UPDATE fineness_fiber_reports 
    SET status = 'pending', updated_at = CURRENT_TIMESTAMP 
    WHERE (status IS NULL OR status = '' OR TRIM(status) = '')
    AND store_entry_reference IS NOT NULL
    AND store_entry_reference != ''
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Raw Material Test Approval Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
    body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:30px 20px; color:#2c3e50; }
    .container { max-width:1400px; margin:auto; background:#fff; border-radius:12px; padding:30px; box-shadow:0 4px 20px rgba(0,0,0,0.08); }
    h1 { text-align:center; font-size:28px; margin-bottom:10px; color:#2c3e50; }
    .subtitle { text-align:center; color:#7f8c8d; margin-bottom:30px; font-size:14px; }
    .stats { display:flex; gap:20px; margin-bottom:30px; flex-wrap:wrap; }
    .stat-item { flex:1; min-width:200px; padding:20px; border-radius:8px; color:#fff; text-align:center; }
    .stat-value { font-size:36px; font-weight:bold; margin-bottom:8px; }
    .stat-label { font-size:14px; opacity:0.9; }
    .alert { padding:15px; border-radius:8px; margin-bottom:20px; }
    .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
    .section { margin-bottom:40px; }
    .section h2 { color:#34495e; margin-bottom:20px; font-size:22px; border-bottom:2px solid #3498db; padding-bottom:10px; }
    .test-card { background:#f8f9fa; border:1px solid #dee2e6; border-radius:8px; padding:20px; margin-bottom:20px; }
    .test-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:15px; }
    .test-title { font-size:18px; font-weight:600; color:#2c3e50; }
    .test-info { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:15px; margin-bottom:15px; }
    .info-item { display:flex; flex-direction:column; }
    .info-label { font-size:12px; color:#7f8c8d; font-weight:600; margin-bottom:4px; }
    .info-value { font-size:14px; color:#2c3e50; }
    .actions { display:flex; gap:10px; margin-top:15px; }
    .btn { padding:10px 20px; border:none; border-radius:6px; cursor:pointer; font-size:14px; font-weight:600; transition:all 0.3s; text-decoration:none; display:inline-block; }
    .btn-approve { background:#27ae60; color:#fff; }
    .btn-approve:hover { background:#229954; }
    .btn-reject { background:#e74c3c; color:#fff; }
    .btn-reject:hover { background:#c0392b; }
    .btn-view { background:#3498db; color:#fff; }
    .btn-view:hover { background:#2980b9; }
    .remarks-box { width:100%; padding:10px; border:1px solid #ddd; border-radius:6px; margin-top:10px; min-height:80px; }
    .modal { display:none; position:fixed; z-index:1000; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.5); overflow-y:auto; }
    .modal-content { 
        background:#fff; 
        margin:2% auto; 
        padding:20px; 
        border-radius:12px; 
        width:90%; 
        max-width:500px; 
        max-height:90vh; 
        display:flex;
        flex-direction:column;
        box-shadow:0 4px 20px rgba(0,0,0,0.3); 
    }
    .modal-header { font-size:18px; font-weight:600; margin-bottom:12px; color:#e74c3c; flex-shrink:0; }
    .modal-body { flex:1; overflow-y:auto; padding-right:5px; }
    .modal-footer { flex-shrink:0; margin-top:15px; padding-top:15px; border-top:1px solid #ddd; text-align:right; }
    .close { float:right; font-size:24px; font-weight:bold; cursor:pointer; color:#aaa; line-height:1; }
    .close:hover { color:#000; }
    .checkbox-group { margin:10px 0; max-height:200px; overflow-y:auto; padding:5px; border:1px solid #e0e0e0; border-radius:6px; }
    .checkbox-item { display:flex; align-items:center; gap:8px; padding:6px; border:1px solid #ddd; border-radius:4px; margin-bottom:4px; background:#f8f9fa; }
    .checkbox-item input[type="checkbox"] { transform:scale(1.1); cursor:pointer; }
    .checkbox-item label { cursor:pointer; flex:1; font-size:12px; }
    #toastContainer {
        position: fixed;
        top: 20px;
        right: 20px;
        display: flex;
        flex-direction: column;
        gap: 10px;
        z-index: 3000;
        pointer-events: none;
    }
    .toast {
        position: relative;
        padding: 12px 16px;
        border-radius: 10px;
        min-width: 260px;
        display: flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
        color: #fff;
        font-size: 14px;
        pointer-events: auto;
        opacity: 0;
        transform: translateY(-12px) scale(0.98);
        transition: opacity 0.25s ease, transform 0.25s ease;
    }
    .toast.visible {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
    .toast.success {
        background: linear-gradient(135deg, #27ae60, #229954);
    }
    .toast.error {
        background: linear-gradient(135deg, #e74c3c, #c0392b);
    }
    .toast .toast-icon {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.2);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 16px;
    }
    .toast .toast-message {
        flex: 1;
        word-break: break-word;
    }
    .toast .toast-close {
        background: transparent;
        border: none;
        color: inherit;
        font-size: 18px;
        cursor: pointer;
        opacity: 0.8;
        transition: opacity 0.2s ease;
    }
    .toast .toast-close:hover {
        opacity: 1;
    }
</style>
</head>
<body>
<div id="toastContainer" aria-live="polite" aria-atomic="true"></div>
<div class="container">
    <a href="../index.php" style="display:inline-block; margin-bottom:20px; padding:10px 20px; background:#6c757d; color:#fff; text-decoration:none; border-radius:6px; font-size:14px;">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
    
    <h1><i class="fas fa-clipboard-check"></i> Raw Material Test Approval Dashboard</h1>
    <p class="subtitle">Review and approve Fiber Test, Fineness of Fiber (ISO 1973), Cut Length of Fiber (ASTM D5103), Tenacity of Fiber (EN ISO 5079), Tenacity of Yarn (ASTM D2256), and Sewing Thread Test reports</p>
    
    <!-- Statistics -->
    <div class="stats">
        <div class="stat-item" style="background:linear-gradient(135deg, #667eea, #764ba2);">
            <div class="stat-value"><?php echo $totalMaterials; ?></div>
            <div class="stat-label">Total Materials Tracking</div>
        </div>
        <div class="stat-item" style="background:linear-gradient(135deg, #f093fb, #f5576c);">
            <div class="stat-value"><?php echo $totalPendingFiber; ?></div>
            <div class="stat-label">Pending Fiber Tests</div>
        </div>
        <div class="stat-item" style="background:linear-gradient(135deg, #4facfe, #00f2fe);">
            <div class="stat-value"><?php echo $totalPendingSewing; ?></div>
            <div class="stat-label">Pending Sewing Tests</div>
        </div>
        <div class="stat-item" style="background:linear-gradient(135deg, #9b59b6, #8e44ad);">
            <div class="stat-value"><?php echo $totalPendingFinenessFiber; ?></div>
            <div class="stat-label">Pending Fineness Fiber Tests</div>
        </div>
        <div class="stat-item" style="background:linear-gradient(135deg, #ff9800, #f57c00);">
            <div class="stat-value"><?php echo $totalPendingCutLengthFiber; ?></div>
            <div class="stat-label">Pending Cut Length Fiber Tests</div>
        </div>
        <div class="stat-item" style="background:linear-gradient(135deg, #ff6b6b, #ee5a6f);">
            <div class="stat-value"><?php echo $totalPendingTenacityFiber; ?></div>
            <div class="stat-label">Pending Tenacity Fiber Tests</div>
        </div>
        <div class="stat-item" style="background:linear-gradient(135deg, #00bcd4, #0097a7);">
            <div class="stat-value"><?php echo $totalPendingYarn; ?></div>
            <div class="stat-label">Pending Yarn Tests</div>
        </div>
        <div class="stat-item" style="background:linear-gradient(135deg, #fa709a, #fee140);">
            <div class="stat-value"><?php echo $totalPending; ?></div>
            <div class="stat-label">Total Pending</div>
        </div>
    </div>
    
    <!-- All Tests Approval Status Summary -->
    <?php
    // Get all store entries with their test approval status
    // Use prepared statements to get the most recent report for each test type (to handle resubmissions)
    $allStoreEntries = [];
    $allStoreEntriesResult = $conn->query("
        SELECT entry_number, material_type, amount_kg, date_time as received_date, manufacturer_name
        FROM store_received_entries
        ORDER BY date_time DESC
        LIMIT 100
    ");
    
    if ($allStoreEntriesResult) {
        while ($entry = $allStoreEntriesResult->fetch_assoc()) {
            $entryNumber = $entry['entry_number'];
            
            // Get status for each test type
            // Helper function to normalize status (handle NULL and empty strings)
            // If status is empty/NULL but report exists, treat as 'pending' (needs approval)
            $normalizeStatus = function($status, $reportExists = true) {
                $trimmed = trim($status ?? '');
                if (empty($trimmed) || $status === null) {
                    // If report exists but status is empty, it's pending approval
                    return $reportExists ? 'pending' : 'not_submitted';
                }
                return $trimmed;
            };
            
            $stmt = $conn->prepare("SELECT status FROM fineness_fiber_reports WHERE CAST(TRIM(store_entry_reference) AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(TRIM(?) AS CHAR) COLLATE utf8mb4_unicode_ci ORDER BY created_at DESC, id DESC LIMIT 1");
            $stmt->bind_param("s", $entryNumber);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $entry['fineness_fiber_status'] = $normalizeStatus($result['status'] ?? null, $result !== null);
            $stmt->close();
            
            // Cut length fiber status
            if (!empty($storeRefExists['cut_length_fiber_reports'])) {
            $stmt = $conn->prepare("SELECT status FROM cut_length_fiber_reports WHERE CAST(TRIM(store_entry_reference) AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(TRIM(?) AS CHAR) COLLATE utf8mb4_unicode_ci ORDER BY created_at DESC, id DESC LIMIT 1");
            $stmt->bind_param("s", $entryNumber);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $entry['cut_length_fiber_status'] = $normalizeStatus($result['status'] ?? null, $result !== null);
            $stmt->close();
            } else {
                $entry['cut_length_fiber_status'] = 'not_submitted';
            }
            
            // Tenacity fiber status
            if (!empty($storeRefExists['tenacity_fiber_reports'])) {
            $stmt = $conn->prepare("SELECT status FROM tenacity_fiber_reports WHERE CAST(TRIM(store_entry_reference) AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(TRIM(?) AS CHAR) COLLATE utf8mb4_unicode_ci ORDER BY created_at DESC, id DESC LIMIT 1");
            $stmt->bind_param("s", $entryNumber);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $entry['tenacity_fiber_status'] = $normalizeStatus($result['status'] ?? null, $result !== null);
            $stmt->close();
            } else {
                $entry['tenacity_fiber_status'] = 'not_submitted';
            }
            
            // Tenacity yarn status
            if (!empty($storeRefExists['tenacity_yarn_reports'])) {
            $stmt = $conn->prepare("SELECT status FROM tenacity_yarn_reports WHERE CAST(TRIM(store_entry_reference) AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(TRIM(?) AS CHAR) COLLATE utf8mb4_unicode_ci ORDER BY created_at DESC, id DESC LIMIT 1");
            $stmt->bind_param("s", $entryNumber);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $entry['tenacity_yarn_status'] = $normalizeStatus($result['status'] ?? null, $result !== null);
            $stmt->close();
            } else {
                $entry['tenacity_yarn_status'] = 'not_submitted';
            }
            
            // Fiber test status
            if (!empty($storeRefExists['fiber_test_reports'])) {
            $stmt = $conn->prepare("SELECT status FROM fiber_test_reports WHERE CAST(TRIM(store_entry_reference) AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(TRIM(?) AS CHAR) COLLATE utf8mb4_unicode_ci ORDER BY created_at DESC, id DESC LIMIT 1");
            $stmt->bind_param("s", $entryNumber);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $entry['fiber_test_status'] = $normalizeStatus($result['status'] ?? null, $result !== null);
            $stmt->close();
            } else {
                $entry['fiber_test_status'] = 'not_submitted';
            }
            
            // Sewing thread status (match by store_entry_reference or reference only — align to form schema)
            if (!empty($storeRefExists['sewing_thread_reports'])) {
                // Detect available columns to avoid schema errors
                $sewingCols = [];
                $sewingColRes = $conn->query("SHOW COLUMNS FROM sewing_thread_reports");
                if ($sewingColRes) {
                    while ($r = $sewingColRes->fetch_assoc()) {
                        $sewingCols[] = strtolower($r['Field']);
                    }
                }
                $clauses = [];
                $types = '';
                $params = [];

                if (in_array('store_entry_reference', $sewingCols, true)) {
                    $clauses[] = "CAST(TRIM(store_entry_reference) AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(TRIM(?) AS CHAR) COLLATE utf8mb4_unicode_ci";
                    $types .= 's';
                    $params[] = $entryNumber;
                }
                if (in_array('reference', $sewingCols, true)) {
                    $clauses[] = "CAST(TRIM(reference) AS CHAR) COLLATE utf8mb4_unicode_ci = CAST(TRIM(?) AS CHAR) COLLATE utf8mb4_unicode_ci";
                    $types .= 's';
                    $params[] = $entryNumber;
                }

                if (!empty($clauses)) {
                    $query = "SELECT status FROM sewing_thread_reports WHERE (" . implode(' OR ', $clauses) . ") ORDER BY created_at DESC, id DESC LIMIT 1";
                    $stmt = $conn->prepare($query);
                    if ($stmt) {
                        $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $entry['sewing_thread_status'] = $normalizeStatus($result['status'] ?? null, $result !== null);
            $stmt->close();
                    } else {
                        $entry['sewing_thread_status'] = 'not_submitted';
                    }
                } else {
                    // No usable columns to match on
                    $entry['sewing_thread_status'] = 'not_submitted';
                }
            } else {
                $entry['sewing_thread_status'] = 'not_submitted';
            }
            
            // Check if all tests are approved
            $entry['all_tests_approved'] = (
                $entry['fineness_fiber_status'] === 'approved' &&
                $entry['cut_length_fiber_status'] === 'approved' &&
                $entry['tenacity_fiber_status'] === 'approved' &&
                $entry['tenacity_yarn_status'] === 'approved' &&
                $entry['fiber_test_status'] === 'approved' &&
                $entry['sewing_thread_status'] === 'approved'
            );
            
            $allStoreEntries[] = $entry;
        }
    }
    
    // Handle search functionality
    $searchTerm = isset($_GET['search_store_entry']) ? trim($_GET['search_store_entry']) : '';
    $filteredStoreEntries = $allStoreEntries;
    
    if (!empty($searchTerm)) {
        $filteredStoreEntries = array_filter($allStoreEntries, function($entry) use ($searchTerm) {
            // Search in entry_number (store entry reference)
            $entryNumber = strtolower($entry['entry_number'] ?? '');
            $searchLower = strtolower($searchTerm);
            
            // Also search in material_type if needed
            $materialType = strtolower($entry['material_type'] ?? '');
            
            return strpos($entryNumber, $searchLower) !== false || 
                   strpos($materialType, $searchLower) !== false;
        });
        // Re-index array after filtering
        $filteredStoreEntries = array_values($filteredStoreEntries);
    }
    ?>
    
    <?php
    // Helper function for status badge
    function getStatusBadge($status) {
        if (empty($status) || $status === null) $status = 'not_submitted';
        
        switch($status) {
            case 'approved':
                return '<span style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; border-radius: 20px; background: linear-gradient(135deg, #27ae60, #229954); color: #fff; font-size: 11px; font-weight: 600; box-shadow: 0 2px 4px rgba(39, 174, 96, 0.3);"><i class="fas fa-check-circle"></i> Approved</span>';
            case 'pending':
                return '<span style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; border-radius: 20px; background: linear-gradient(135deg, #f39c12, #e67e22); color: #fff; font-size: 11px; font-weight: 600; box-shadow: 0 2px 4px rgba(243, 156, 18, 0.3);"><i class="fas fa-clock"></i> Pending</span>';
            case 'rejected':
                return '<span style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; border-radius: 20px; background: linear-gradient(135deg, #e74c3c, #c0392b); color: #fff; font-size: 11px; font-weight: 600; box-shadow: 0 2px 4px rgba(231, 76, 60, 0.3);"><i class="fas fa-times-circle"></i> Rejected</span>';
            default:
                return '<span style="display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; border-radius: 20px; background: linear-gradient(135deg, #95a5a6, #7f8c8d); color: #fff; font-size: 11px; font-weight: 600; box-shadow: 0 2px 4px rgba(149, 165, 166, 0.3);"><i class="fas fa-minus-circle"></i> Not Submitted</span>';
        }
    }
    ?>
    
    <div class="section">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; flex-wrap: wrap; gap: 20px;">
            <h2 style="margin: 0; flex: 0 0 auto;"><i class="fas fa-check-circle"></i> All Tests Approval Status by Reference</h2>
            <form method="GET" action="" style="display: flex; gap: 10px; align-items: center; flex: 1; min-width: 300px; max-width: 500px; margin: 0;">
                <div style="position: relative; flex: 1;">
                    <input 
                        type="text" 
                        name="search_store_entry" 
                        id="search_store_entry"
                        value="<?php echo htmlspecialchars($searchTerm); ?>" 
                        placeholder="Search by Store Entry Reference (e.g., SRE-20251124-001) or Material Type..."
                        style="width: 100%; padding: 12px 45px 12px 15px; border: 2px solid #e0e0e0; border-radius: 25px; font-size: 14px; transition: all 0.3s; outline: none; box-sizing: border-box;"
                        onfocus="this.style.borderColor='#667eea'; this.style.boxShadow='0 0 0 3px rgba(102, 126, 234, 0.1)'"
                        onblur="this.style.borderColor='#e0e0e0'; this.style.boxShadow='none'"
                    >
                    <i class="fas fa-search" style="position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #95a5a6; pointer-events: none;"></i>
                </div>
                <?php if (!empty($searchTerm)): ?>
                    <a href="?" style="padding: 12px 20px; background: #e74c3c; color: #fff; border-radius: 25px; text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.3s; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0;" onmouseover="this.style.background='#c0392b'" onmouseout="this.style.background='#e74c3c'">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
                <button type="submit" style="padding: 12px 25px; background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; border: none; border-radius: 25px; font-weight: 600; font-size: 14px; cursor: pointer; transition: all 0.3s; white-space: nowrap; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3); display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(102, 126, 234, 0.4)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(102, 126, 234, 0.3)'">
                    <i class="fas fa-search"></i> Search
                </button>
            </form>
        </div>
        
        <?php if (empty($searchTerm)): ?>
            <div style="background: #fff; border-radius: 12px; padding: 60px 40px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-top: 0; clear: both;">
                <i class="fas fa-search" style="font-size: 64px; color: #bdc3c7; margin-bottom: 20px;"></i>
                <h3 style="color: #2c3e50; margin: 0 0 15px 0; font-size: 24px;">Search for Store Entry Reference</h3>
                <p style="color: #7f8c8d; font-size: 16px; margin: 0 0 25px 0; line-height: 1.6;">
                    Enter a Store Entry Reference (e.g., <strong>SRE-20251124-001</strong>) or Material Type in the search box above to view the approval status of all tests for that entry.
                </p>
                <div style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; border-radius: 25px; font-weight: 600; font-size: 14px; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);">
                    <i class="fas fa-lightbulb"></i>
                    <span>Start typing to search...</span>
                </div>
            </div>
        <?php elseif (empty($filteredStoreEntries)): ?>
            <div style="background: #fff; border-radius: 12px; padding: 40px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                <i class="fas fa-search" style="font-size: 48px; color: #bdc3c7; margin-bottom: 15px;"></i>
                <p style="color: #7f8c8d; font-size: 16px; margin: 0;">
                    No store entries found matching "<strong><?php echo htmlspecialchars($searchTerm); ?></strong>"
                </p>
            </div>
        <?php else: ?>
            <div style="background: #e3f2fd; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #2196f3;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-info-circle" style="color: #1976d2; font-size: 18px;"></i>
                    <span style="color: #1565c0; font-weight: 600;">
                        Showing <?php echo count($filteredStoreEntries); ?> result(s) for "<strong><?php echo htmlspecialchars($searchTerm); ?></strong>"
                    </span>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($searchTerm) && !empty($filteredStoreEntries)): ?>
        <div style="display: grid; gap: 20px; margin-top: 20px;">
            <?php foreach ($filteredStoreEntries as $entry): 
                // Calculate approval progress
                $tests = [
                    'fineness_fiber' => $entry['fineness_fiber_status'] ?? 'not_submitted',
                    'cut_length_fiber' => $entry['cut_length_fiber_status'] ?? 'not_submitted',
                    'tenacity_fiber' => $entry['tenacity_fiber_status'] ?? 'not_submitted',
                    'tenacity_yarn' => $entry['tenacity_yarn_status'] ?? 'not_submitted',
                    'fiber_test' => $entry['fiber_test_status'] ?? 'not_submitted',
                    'sewing_thread' => $entry['sewing_thread_status'] ?? 'not_submitted'
                ];
                $approvedCount = 0;
                $totalTests = count($tests);
                foreach ($tests as $testStatus) {
                    if ($testStatus === 'approved') $approvedCount++;
                }
                $progressPercent = ($approvedCount / $totalTests) * 100;
            ?>
            <div style="background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); border-left: 5px solid <?php echo $entry['all_tests_approved'] ? '#27ae60' : ($progressPercent > 50 ? '#f39c12' : '#e74c3c'); ?>; transition: transform 0.2s, box-shadow 0.2s;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(0,0,0,0.12)'" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.08)'">
                <!-- Header -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                    <div>
                        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px;">
                            <div style="width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg, #667eea, #764ba2); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 20px; font-weight: bold; box-shadow: 0 4px 8px rgba(102, 126, 234, 0.3);">
                                <i class="fas fa-box"></i>
                            </div>
                            <div>
                                <h3 style="margin: 0; font-size: 18px; font-weight: 700; color: #2c3e50;"><?php echo htmlspecialchars($entry['entry_number']); ?></h3>
                                <p style="margin: 4px 0 0 0; font-size: 14px; color: #7f8c8d;">
                                    <i class="fas fa-tag"></i> <?php echo htmlspecialchars($entry['material_type']); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div style="text-align: right;">
                        <?php if ($entry['all_tests_approved']): ?>
                            <div style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 25px; background: linear-gradient(135deg, #27ae60, #229954); color: #fff; font-weight: 700; font-size: 14px; box-shadow: 0 4px 12px rgba(39, 174, 96, 0.4);">
                                <i class="fas fa-check-double"></i> All Approved
                            </div>
                        <?php else: ?>
                            <div style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 25px; background: linear-gradient(135deg, #e74c3c, #c0392b); color: #fff; font-weight: 700; font-size: 14px; box-shadow: 0 4px 12px rgba(231, 76, 60, 0.4);">
                                <i class="fas fa-exclamation-triangle"></i> Pending Approval
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Progress Bar -->
                <div style="margin-bottom: 24px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 13px; font-weight: 600; color: #2c3e50;">Approval Progress</span>
                        <span style="font-size: 13px; font-weight: 700; color: #667eea;"><?php echo $approvedCount; ?>/<?php echo $totalTests; ?> Tests Approved</span>
                    </div>
                    <div style="width: 100%; height: 10px; background: #ecf0f1; border-radius: 10px; overflow: hidden; box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);">
                        <div style="width: <?php echo $progressPercent; ?>%; height: 100%; background: linear-gradient(90deg, <?php echo $entry['all_tests_approved'] ? '#27ae60' : ($progressPercent > 50 ? '#f39c12' : '#e74c3c'); ?>, <?php echo $entry['all_tests_approved'] ? '#229954' : ($progressPercent > 50 ? '#e67e22' : '#c0392b'); ?>); border-radius: 10px; transition: width 0.3s ease; box-shadow: 0 2px 4px rgba(0,0,0,0.2);"></div>
                    </div>
                </div>
                
                <!-- Test Status Grid -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                    <div style="background: #f8f9fa; padding: 16px; border-radius: 12px; border: 2px solid #e9ecef; transition: all 0.2s;" onmouseover="this.style.borderColor='#667eea'; this.style.background='#f0f4ff'" onmouseout="this.style.borderColor='#e9ecef'; this.style.background='#f8f9fa'">
                        <div style="font-size: 12px; font-weight: 600; color: #7f8c8d; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
                            <i class="fas fa-microscope"></i> 1. Fineness Fiber
                        </div>
                        <div><?php echo getStatusBadge($entry['fineness_fiber_status'] ?? 'not_submitted'); ?></div>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 16px; border-radius: 12px; border: 2px solid #e9ecef; transition: all 0.2s;" onmouseover="this.style.borderColor='#667eea'; this.style.background='#f0f4ff'" onmouseout="this.style.borderColor='#e9ecef'; this.style.background='#f8f9fa'">
                        <div style="font-size: 12px; font-weight: 600; color: #7f8c8d; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
                            <i class="fas fa-ruler"></i> 2. Cut Length Fiber
                        </div>
                        <div><?php echo getStatusBadge($entry['cut_length_fiber_status'] ?? 'not_submitted'); ?></div>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 16px; border-radius: 12px; border: 2px solid #e9ecef; transition: all 0.2s;" onmouseover="this.style.borderColor='#667eea'; this.style.background='#f0f4ff'" onmouseout="this.style.borderColor='#e9ecef'; this.style.background='#f8f9fa'">
                        <div style="font-size: 12px; font-weight: 600; color: #7f8c8d; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
                            <i class="fas fa-weight-hanging"></i> 3. Tenacity Fiber
                        </div>
                        <div><?php echo getStatusBadge($entry['tenacity_fiber_status'] ?? 'not_submitted'); ?></div>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 16px; border-radius: 12px; border: 2px solid #e9ecef; transition: all 0.2s;" onmouseover="this.style.borderColor='#667eea'; this.style.background='#f0f4ff'" onmouseout="this.style.borderColor='#e9ecef'; this.style.background='#f8f9fa'">
                        <div style="font-size: 12px; font-weight: 600; color: #7f8c8d; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
                            <i class="fas fa-spinner"></i> 4. Tenacity Yarn
                        </div>
                        <div><?php echo getStatusBadge($entry['tenacity_yarn_status'] ?? 'not_submitted'); ?></div>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 16px; border-radius: 12px; border: 2px solid #e9ecef; transition: all 0.2s;" onmouseover="this.style.borderColor='#667eea'; this.style.background='#f0f4ff'" onmouseout="this.style.borderColor='#e9ecef'; this.style.background='#f8f9fa'">
                        <div style="font-size: 12px; font-weight: 600; color: #7f8c8d; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
                            <i class="fas fa-dna"></i> 5. Fiber Test
                        </div>
                        <div><?php echo getStatusBadge($entry['fiber_test_status'] ?? 'not_submitted'); ?></div>
                    </div>
                    
                    <div style="background: #f8f9fa; padding: 16px; border-radius: 12px; border: 2px solid #e9ecef; transition: all 0.2s;" onmouseover="this.style.borderColor='#667eea'; this.style.background='#f0f4ff'" onmouseout="this.style.borderColor='#e9ecef'; this.style.background='#f8f9fa'">
                        <div style="font-size: 12px; font-weight: 600; color: #7f8c8d; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
                            <i class="fas fa-thread"></i> 6. Sewing Thread
                        </div>
                        <div><?php echo getStatusBadge($entry['sewing_thread_status'] ?? 'not_submitted'); ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Materials Grouped by Store Entry -->
    <?php if (!empty($storeEntriesWithTests)): ?>
    <div class="section">
        <h2><i class="fas fa-warehouse"></i> Materials by Store Entry (<?php echo $totalMaterials; ?>)</h2>
        
        <?php foreach ($storeEntriesWithTests as $material): ?>
        <div class="test-card" style="border-left: 4px solid <?php echo ((isset($material['fiber_status']) && $material['fiber_status'] === 'pending') || (isset($material['sewing_status']) && $material['sewing_status'] === 'pending') || (isset($material['fineness_fiber_status']) && $material['fineness_fiber_status'] === 'pending') || (isset($material['cut_length_fiber_status']) && $material['cut_length_fiber_status'] === 'pending') || (isset($material['fiber_tenacity_status']) && $material['fiber_tenacity_status'] === 'pending') || (isset($material['yarn_status']) && $material['yarn_status'] === 'pending')) ? '#f39c12' : '#95a5a6'; ?>;">
            <div class="test-header">
                <div>
                    <div class="test-title">
                        <i class="fas fa-box"></i> <?php echo htmlspecialchars($material['entry_number']); ?>
                    </div>
                    <div style="font-size:14px; color:#7f8c8d; margin-top:5px;">
                        <?php echo htmlspecialchars($material['material_type']); ?> - 
                        <?php echo number_format($material['amount_kg'], 2); ?> kg - 
                        Received: <?php echo date('d M Y', strtotime($material['received_date'])); ?>
                    </div>
                    <div style="font-size:13px; color:#95a5a6; margin-top:3px;">
                        Manufacturer: <?php echo htmlspecialchars($material['manufacturer_name']); ?>
                    </div>
                </div>
            </div>
            
            <!-- Fiber Test Status -->
            <div style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:4px solid <?php echo (isset($material['fiber_status']) && $material['fiber_status'] === 'pending') ? '#f39c12' : ((isset($material['fiber_status']) && $material['fiber_status'] === 'approved') ? '#27ae60' : '#e0e0e0'); ?>; margin-top:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div style="flex:1; min-width:300px;">
                        <div style="font-weight:600; margin-bottom:8px; color:#2c3e50;">
                            <i class="fas fa-dna"></i> Fiber Test
                        </div>
                        <?php if (!empty($material['fiber_test_id'])): ?>
                            <div style="display:flex; gap:20px; flex-wrap:wrap; font-size:13px;">
                                <span><strong>Report:</strong> <?php echo htmlspecialchars($material['fiber_report_number'] ?? 'N/A'); ?></span>
                                <span><strong>Status:</strong> 
                                    <span style="padding:3px 8px; background:<?php echo (isset($material['fiber_status']) && $material['fiber_status'] === 'pending') ? '#fff3cd' : '#d4edda'; ?>; color:<?php echo (isset($material['fiber_status']) && $material['fiber_status'] === 'pending') ? '#856404' : '#155724'; ?>; border-radius:10px; font-size:11px; font-weight:600;">
                                        <?php echo ucfirst($material['fiber_status'] ?? 'N/A'); ?>
                                    </span>
                                </span>
                                <span><strong>Tested By:</strong> <?php echo htmlspecialchars($material['fiber_tester'] ?? 'N/A'); ?></span>
                                <span><strong>Date:</strong> <?php echo !empty($material['fiber_test_date']) ? date('d M Y', strtotime($material['fiber_test_date'])) : 'N/A'; ?></span>
                            </div>
                        <?php else: ?>
                            <div style="color:#95a5a6; font-style:italic; font-size:13px;">
                                <i class="fas fa-info-circle"></i> No fiber test submitted
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($material['fiber_test_id']) && isset($material['fiber_status']) && $material['fiber_status'] === 'pending'): ?>
                    <div style="display:flex; gap:8px; flex-shrink:0;">
                        <a href="../admin/view_fiber_report.php?id=<?php echo $material['fiber_test_id']; ?>&approval_dashboard=1&report_number=<?php echo urlencode($material['fiber_report_number']); ?>" target="_blank" class="btn btn-view" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-eye"></i> View
                        </a>
                        <button type="button" class="btn btn-approve" onclick="approveFiberTest('<?php echo htmlspecialchars($material['fiber_report_number']); ?>')" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button type="button" class="btn btn-reject" onclick="openFiberRejectModal('<?php echo htmlspecialchars($material['fiber_report_number']); ?>')" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Sewing Thread Test Status -->
            <div style="background:#f8f9fa; padding:12px; border-radius:6px; border-left:4px solid <?php echo (isset($material['sewing_status']) && $material['sewing_status'] === 'pending') ? '#f39c12' : ((isset($material['sewing_status']) && $material['sewing_status'] === 'approved') ? '#27ae60' : '#e0e0e0'); ?>; margin-top:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div style="flex:1; min-width:300px;">
                        <div style="font-weight:600; margin-bottom:8px; color:#2c3e50;">
                            <i class="fas fa-scroll"></i> Sewing Thread Test
                        </div>
                        <?php if (!empty($material['sewing_test_id'])): ?>
                            <div style="display:flex; gap:20px; flex-wrap:wrap; font-size:13px;">
                                <span><strong>Report:</strong> <?php echo htmlspecialchars($material['sewing_report_number'] ?? 'N/A'); ?></span>
                                <span><strong>Status:</strong> 
                                    <span style="padding:3px 8px; background:<?php echo (isset($material['sewing_status']) && $material['sewing_status'] === 'pending') ? '#fff3cd' : '#d4edda'; ?>; color:<?php echo (isset($material['sewing_status']) && $material['sewing_status'] === 'pending') ? '#856404' : '#155724'; ?>; border-radius:10px; font-size:11px; font-weight:600;">
                                        <?php echo ucfirst($material['sewing_status'] ?? 'N/A'); ?>
                                    </span>
                                </span>
                                <span><strong>Tested By:</strong> <?php echo htmlspecialchars($material['sewing_tester'] ?? 'N/A'); ?></span>
                                <span><strong>Date:</strong> <?php echo !empty($material['sewing_test_date']) ? date('d M Y', strtotime($material['sewing_test_date'])) : 'N/A'; ?></span>
                            </div>
                        <?php else: ?>
                            <div style="color:#95a5a6; font-style:italic; font-size:13px;">
                                <i class="fas fa-info-circle"></i> No sewing test submitted
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($material['sewing_test_id']) && isset($material['sewing_status']) && $material['sewing_status'] === 'pending'): ?>
                    <div style="display:flex; gap:8px; flex-shrink:0;">
                        <a href="../admin/view_sewing_report.php?id=<?php echo $material['sewing_test_id']; ?>&approval_dashboard=1&report_number=<?php echo urlencode($material['sewing_report_number']); ?>" target="_blank" class="btn btn-view" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-eye"></i> View
                        </a>
                        <button type="button" class="btn btn-approve" onclick="approveSewingTest('<?php echo htmlspecialchars($material['sewing_report_number']); ?>')" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button type="button" class="btn btn-reject" onclick="openSewingRejectModal('<?php echo htmlspecialchars($material['sewing_report_number']); ?>')" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Fineness of Fiber Test Status -->
            <div style="background:<?php echo (isset($material['fineness_fiber_status']) && $material['fineness_fiber_status'] === 'pending') ? '#f3e5f5' : ((isset($material['fineness_fiber_status']) && $material['fineness_fiber_status'] === 'approved') ? '#e8f5e9' : '#f5f5f5'); ?>; padding:12px; border-radius:6px; border-left:4px solid <?php echo (isset($material['fineness_fiber_status']) && $material['fineness_fiber_status'] === 'pending') ? '#9b59b6' : ((isset($material['fineness_fiber_status']) && $material['fineness_fiber_status'] === 'approved') ? '#4caf50' : '#9e9e9e'); ?>; margin-top:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div style="flex:1; min-width:300px;">
                        <div style="font-weight:600; margin-bottom:8px; color:#2c3e50;">
                            <i class="fas fa-weight"></i> Fineness of Fiber Test (ISO 1973)
                        </div>
                        <?php if (!empty($material['fineness_fiber_test_id'])): ?>
                            <div style="display:flex; gap:20px; flex-wrap:wrap; font-size:13px;">
                                <span><strong>Report:</strong> <?php echo htmlspecialchars($material['fineness_fiber_report_number'] ?? 'N/A'); ?></span>
                                <span><strong>Status:</strong> 
                                    <span style="padding:3px 8px; background:<?php echo (isset($material['fineness_fiber_status']) && $material['fineness_fiber_status'] === 'pending') ? '#f3e5f5' : '#d4edda'; ?>; color:<?php echo (isset($material['fineness_fiber_status']) && $material['fineness_fiber_status'] === 'pending') ? '#6a1b9a' : '#155724'; ?>; border-radius:10px; font-size:11px; font-weight:600;">
                                        <?php echo ucfirst($material['fineness_fiber_status'] ?? 'N/A'); ?>
                                    </span>
                                </span>
                                <span><strong>Tested By:</strong> <?php echo htmlspecialchars($material['fineness_fiber_tester'] ?? 'N/A'); ?></span>
                                <span><strong>Date:</strong> <?php echo !empty($material['fineness_fiber_test_date']) ? date('d M Y', strtotime($material['fineness_fiber_test_date'])) : 'N/A'; ?></span>
                            </div>
                        <?php else: ?>
                            <div style="color:#95a5a6; font-style:italic; font-size:13px;">
                                <i class="fas fa-info-circle"></i> No fineness fiber test submitted
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($material['fineness_fiber_test_id']) && isset($material['fineness_fiber_status']) && $material['fineness_fiber_status'] === 'pending'): ?>
                    <div style="display:flex; gap:8px; flex-shrink:0;">
                        <button type="button" class="btn btn-view" onclick="viewFinenessFiberTest('<?php echo $material['fineness_fiber_test_id']; ?>', '<?php echo htmlspecialchars($material['fineness_fiber_report_number']); ?>')" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button type="button" class="btn btn-approve" onclick="approveFinenessFiberTest('<?php echo htmlspecialchars($material['fineness_fiber_report_number']); ?>')" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button type="button" class="btn btn-reject" onclick="openFinenessFiberRejectModal('<?php echo htmlspecialchars($material['fineness_fiber_report_number']); ?>')" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Cut Length of Fiber Test Status -->
            <div style="background:<?php echo (isset($material['cut_length_fiber_status']) && $material['cut_length_fiber_status'] === 'pending') ? '#fff3e0' : ((isset($material['cut_length_fiber_status']) && $material['cut_length_fiber_status'] === 'approved') ? '#e8f5e9' : '#f5f5f5'); ?>; padding:12px; border-radius:6px; border-left:4px solid <?php echo (isset($material['cut_length_fiber_status']) && $material['cut_length_fiber_status'] === 'pending') ? '#ff9800' : ((isset($material['cut_length_fiber_status']) && $material['cut_length_fiber_status'] === 'approved') ? '#4caf50' : '#9e9e9e'); ?>; margin-top:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div style="flex:1; min-width:300px;">
                        <div style="font-weight:600; margin-bottom:8px; color:#2c3e50;">
                            <i class="fas fa-ruler"></i> Cut Length of Fiber Test (ASTM D5103)
                        </div>
                        <?php if (!empty($material['cut_length_fiber_test_id'])): ?>
                            <div style="display:flex; gap:20px; flex-wrap:wrap; font-size:13px;">
                                <span><strong>Report:</strong> <?php echo htmlspecialchars($material['cut_length_fiber_report_number'] ?? 'N/A'); ?></span>
                                <span><strong>Status:</strong> 
                                    <span style="padding:3px 8px; background:<?php echo (isset($material['cut_length_fiber_status']) && $material['cut_length_fiber_status'] === 'pending') ? '#fff3e0' : '#d4edda'; ?>; color:<?php echo (isset($material['cut_length_fiber_status']) && $material['cut_length_fiber_status'] === 'pending') ? '#e65100' : '#155724'; ?>; border-radius:10px; font-size:11px; font-weight:600;">
                                        <?php echo ucfirst($material['cut_length_fiber_status'] ?? 'N/A'); ?>
                                    </span>
                                </span>
                                <span><strong>Tested By:</strong> <?php echo htmlspecialchars($material['cut_length_fiber_tester'] ?? 'N/A'); ?></span>
                                <span><strong>Date:</strong> <?php echo !empty($material['cut_length_fiber_test_date']) ? date('d M Y', strtotime($material['cut_length_fiber_test_date'])) : 'N/A'; ?></span>
                            </div>
                        <?php else: ?>
                            <div style="color:#95a5a6; font-style:italic; font-size:13px;">
                                <i class="fas fa-info-circle"></i> No cut length fiber test submitted
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($material['cut_length_fiber_test_id']) && isset($material['cut_length_fiber_status']) && $material['cut_length_fiber_status'] === 'pending'): ?>
                    <div style="display:flex; gap:8px; flex-shrink:0;">
                        <button type="button" class="btn btn-view" onclick="viewCutLengthFiberTest('<?php echo $material['cut_length_fiber_test_id']; ?>', '<?php echo htmlspecialchars($material['cut_length_fiber_report_number']); ?>')" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button type="button" class="btn btn-approve" onclick="approveCutLengthFiberTest('<?php echo htmlspecialchars($material['cut_length_fiber_report_number']); ?>')" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button type="button" class="btn btn-reject" onclick="openCutLengthFiberRejectModal('<?php echo htmlspecialchars($material['cut_length_fiber_report_number']); ?>')" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Tenacity of Fiber Test Status -->
            <div style="background:<?php echo (isset($material['fiber_tenacity_status']) && $material['fiber_tenacity_status'] === 'pending') ? '#ffe5e5' : ((isset($material['fiber_tenacity_status']) && $material['fiber_tenacity_status'] === 'approved') ? '#e8f5e9' : '#f5f5f5'); ?>; padding:12px; border-radius:6px; border-left:4px solid <?php echo (isset($material['fiber_tenacity_status']) && $material['fiber_tenacity_status'] === 'pending') ? '#ff6b6b' : ((isset($material['fiber_tenacity_status']) && $material['fiber_tenacity_status'] === 'approved') ? '#4caf50' : '#9e9e9e'); ?>; margin-top:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div style="flex:1; min-width:300px;">
                        <div style="font-weight:600; margin-bottom:8px; color:#2c3e50;">
                            <i class="fas fa-flask"></i> Tenacity of Fiber Test (EN ISO 5079)
                        </div>
                        <?php if (!empty($material['fiber_tenacity_test_id'])): ?>
                            <div style="display:flex; gap:20px; flex-wrap:wrap; font-size:13px;">
                                <span><strong>Report:</strong> <?php echo htmlspecialchars($material['fiber_tenacity_report_number'] ?? 'N/A'); ?></span>
                                <span><strong>Status:</strong> 
                                    <span style="padding:3px 8px; background:<?php echo (isset($material['fiber_tenacity_status']) && $material['fiber_tenacity_status'] === 'pending') ? '#ffe5e5' : '#d4edda'; ?>; color:<?php echo (isset($material['fiber_tenacity_status']) && $material['fiber_tenacity_status'] === 'pending') ? '#c0392b' : '#155724'; ?>; border-radius:10px; font-size:11px; font-weight:600;">
                                        <?php echo ucfirst($material['fiber_tenacity_status'] ?? 'N/A'); ?>
                                    </span>
                                </span>
                                <span><strong>Tested By:</strong> <?php echo htmlspecialchars($material['fiber_tenacity_tester'] ?? 'N/A'); ?></span>
                                <span><strong>Date:</strong> <?php echo !empty($material['fiber_tenacity_test_date']) ? date('d M Y', strtotime($material['fiber_tenacity_test_date'])) : 'N/A'; ?></span>
                            </div>
                        <?php else: ?>
                            <div style="color:#95a5a6; font-style:italic; font-size:13px;">
                                <i class="fas fa-info-circle"></i> No tenacity fiber test submitted
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($material['fiber_tenacity_test_id']) && isset($material['fiber_tenacity_status']) && $material['fiber_tenacity_status'] === 'pending'): ?>
                    <div style="display:flex; gap:8px; flex-shrink:0;">
                        <button type="button" class="btn btn-view" onclick="viewFiberTenacityTest('<?php echo $material['fiber_tenacity_test_id']; ?>', '<?php echo htmlspecialchars($material['fiber_tenacity_report_number']); ?>')" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button type="button" class="btn btn-approve" onclick="approveFiberTenacityTest('<?php echo htmlspecialchars($material['fiber_tenacity_report_number']); ?>')" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button type="button" class="btn btn-reject" onclick="openFiberTenacityRejectModal('<?php echo htmlspecialchars($material['fiber_tenacity_report_number']); ?>')" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Tenacity of Yarn Test Status -->
            <div style="background:<?php echo (isset($material['yarn_status']) && $material['yarn_status'] === 'pending') ? '#f3e5f5' : ((isset($material['yarn_status']) && $material['yarn_status'] === 'approved') ? '#e8f5e9' : '#f5f5f5'); ?>; padding:12px; border-radius:6px; border-left:4px solid <?php echo (isset($material['yarn_status']) && $material['yarn_status'] === 'pending') ? '#9c27b0' : ((isset($material['yarn_status']) && $material['yarn_status'] === 'approved') ? '#4caf50' : '#9e9e9e'); ?>; margin-top:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div style="flex:1; min-width:300px;">
                        <div style="font-weight:600; margin-bottom:8px; color:#2c3e50;">
                            <i class="fas fa-flask"></i> Tenacity of Yarn Test (ASTM D2256)
                        </div>
                        <?php if (!empty($material['yarn_test_id'])): ?>
                            <div style="display:flex; gap:20px; flex-wrap:wrap; font-size:13px;">
                                <span><strong>Report:</strong> <?php echo htmlspecialchars($material['yarn_report_number'] ?? 'N/A'); ?></span>
                                <span><strong>Status:</strong> 
                                    <span style="padding:3px 8px; background:<?php echo (isset($material['yarn_status']) && $material['yarn_status'] === 'pending') ? '#f3e5f5' : '#d4edda'; ?>; color:<?php echo (isset($material['yarn_status']) && $material['yarn_status'] === 'pending') ? '#6a1b9a' : '#155724'; ?>; border-radius:10px; font-size:11px; font-weight:600;">
                                        <?php echo ucfirst($material['yarn_status'] ?? 'N/A'); ?>
                                    </span>
                                </span>
                                <span><strong>Tested By:</strong> <?php echo htmlspecialchars($material['yarn_tester'] ?? 'N/A'); ?></span>
                                <span><strong>Date:</strong> <?php echo !empty($material['yarn_test_date']) ? date('d M Y', strtotime($material['yarn_test_date'])) : 'N/A'; ?></span>
                            </div>
                        <?php else: ?>
                            <div style="color:#95a5a6; font-style:italic; font-size:13px;">
                                <i class="fas fa-info-circle"></i> No tenacity yarn test submitted
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($material['yarn_test_id']) && isset($material['yarn_status']) && $material['yarn_status'] === 'pending'): ?>
                    <div style="display:flex; gap:8px; flex-shrink:0;">
                        <button type="button" class="btn btn-view" onclick="viewYarnTest('<?php echo $material['yarn_test_id']; ?>', '<?php echo htmlspecialchars($material['yarn_report_number']); ?>')" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button type="button" class="btn btn-approve" onclick="approveYarnTest('<?php echo htmlspecialchars($material['yarn_report_number']); ?>')" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-check"></i> Approve
                        </button>
                        <button type="button" class="btn btn-reject" onclick="openYarnRejectModal('<?php echo htmlspecialchars($material['yarn_report_number']); ?>')" style="padding:6px 12px; font-size:12px;">
                            <i class="fas fa-times"></i> Reject
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <?php if (empty($storeEntriesWithTests)): ?>
    <div style="text-align:center; padding:60px 20px; color:#7f8c8d;">
        <i class="fas fa-check-circle" style="font-size:64px; margin-bottom:20px; color:#27ae60;"></i>
        <h3 style="color:#2c3e50;">All Tests Approved</h3>
        <p>No pending test reports at this time.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Fiber Test Rejection Modal -->
<div id="fiberRejectModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeFiberRejectModal()">&times;</span>
        <div class="modal-header"><i class="fas fa-exclamation-triangle"></i> Reject Fiber Test</div>
        
        <form method="POST" id="fiberRejectForm">
            <input type="hidden" name="fiber_report_number" id="fiber_report_number_input">
            <input type="hidden" name="fiber_action" value="rejected">
            
            <div class="modal-body">
                <div class="checkbox-group">
                    <strong style="display:block; margin-bottom:8px; font-size:13px;">Rejection Reasons:</strong>
                    <?php foreach ($rejection_reasons['Fiber Test'] as $reason): ?>
                    <div class="checkbox-item">
                        <input type="checkbox" name="fiber_rejection_reasons[]" value="<?php echo htmlspecialchars($reason); ?>" id="fiber_<?php echo md5($reason); ?>">
                        <label for="fiber_<?php echo md5($reason); ?>"><?php echo htmlspecialchars($reason); ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <textarea name="fiber_remarks" class="remarks-box" placeholder="Additional comments (optional)" style="min-height:60px;"></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" onclick="closeFiberRejectModal()" style="padding:8px 18px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer; margin-right:8px; font-size:13px;">
                    Cancel
                </button>
                <button type="submit" style="padding:8px 18px; background:#e74c3c; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; font-size:13px;">
                    <i class="fas fa-ban"></i> Reject Report
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Fineness of Fiber Test Rejection Modal -->
<div id="finenessFiberRejectModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeFinenessFiberRejectModal()">&times;</span>
        <div class="modal-header"><i class="fas fa-exclamation-triangle"></i> Reject Fineness of Fiber Test</div>
        
        <form method="POST" id="finenessFiberRejectForm">
            <input type="hidden" name="fineness_fiber_report_number" id="fineness_fiber_report_number_input">
            <input type="hidden" name="fineness_fiber_action" value="rejected">
            
            <div class="modal-body">
                <div class="checkbox-group">
                    <strong style="display:block; margin-bottom:8px; font-size:13px;">Rejection Reasons:</strong>
                    <?php foreach ($rejection_reasons['Fineness Fiber'] as $reason): ?>
                    <div class="checkbox-item">
                        <input type="checkbox" name="fineness_fiber_rejection_reasons[]" value="<?php echo htmlspecialchars($reason); ?>" id="fineness_fiber_<?php echo md5($reason); ?>">
                        <label for="fineness_fiber_<?php echo md5($reason); ?>"><?php echo htmlspecialchars($reason); ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <textarea name="fineness_fiber_remarks" class="remarks-box" placeholder="Additional comments (optional)" style="min-height:60px;"></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeFinenessFiberRejectModal()">Cancel</button>
                <button type="submit" class="btn btn-reject">Reject Report</button>
            </div>
        </form>
    </div>
</div>

<!-- Cut Length of Fiber Test Rejection Modal -->
<div id="cutLengthFiberRejectModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeCutLengthFiberRejectModal()">&times;</span>
        <div class="modal-header"><i class="fas fa-exclamation-triangle"></i> Reject Cut Length of Fiber Test</div>
        
        <form method="POST" id="cutLengthFiberRejectForm">
            <input type="hidden" name="cut_length_fiber_report_number" id="cut_length_fiber_report_number_input">
            <input type="hidden" name="cut_length_fiber_action" value="rejected">
            
            <div class="modal-body">
                <div class="checkbox-group">
                    <strong style="display:block; margin-bottom:8px; font-size:13px;">Rejection Reasons:</strong>
                    <?php foreach ($rejection_reasons['Cut Length Fiber'] as $reason): ?>
                    <div class="checkbox-item">
                        <input type="checkbox" name="cut_length_fiber_rejection_reasons[]" value="<?php echo htmlspecialchars($reason); ?>" id="cut_length_fiber_<?php echo md5($reason); ?>">
                        <label for="cut_length_fiber_<?php echo md5($reason); ?>"><?php echo htmlspecialchars($reason); ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <textarea name="cut_length_fiber_remarks" class="remarks-box" placeholder="Additional comments (optional)" style="min-height:60px;"></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCutLengthFiberRejectModal()">Cancel</button>
                <button type="submit" class="btn btn-reject">Reject Report</button>
            </div>
        </form>
    </div>
</div>

<!-- Tenacity of Fiber Test Rejection Modal -->
<div id="fiberTenacityRejectModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeFiberTenacityRejectModal()">&times;</span>
        <div class="modal-header"><i class="fas fa-exclamation-triangle"></i> Reject Tenacity of Fiber Test</div>
        
        <form method="POST" id="fiberTenacityRejectForm">
            <input type="hidden" name="fiber_tenacity_report_number" id="fiber_tenacity_report_number_input">
            <input type="hidden" name="fiber_tenacity_action" value="rejected">
            
            <div class="modal-body">
                <div class="checkbox-group">
                    <strong style="display:block; margin-bottom:8px; font-size:13px;">Rejection Reasons:</strong>
                    <?php foreach ($rejection_reasons['Tenacity Fiber'] as $reason): ?>
                    <div class="checkbox-item">
                        <input type="checkbox" name="fiber_tenacity_rejection_reasons[]" value="<?php echo htmlspecialchars($reason); ?>" id="fiber_tenacity_<?php echo md5($reason); ?>">
                        <label for="fiber_tenacity_<?php echo md5($reason); ?>"><?php echo htmlspecialchars($reason); ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <textarea name="fiber_tenacity_remarks" class="remarks-box" placeholder="Additional comments (optional)" style="min-height:60px;"></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" onclick="closeFiberTenacityRejectModal()" style="padding:8px 18px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer; margin-right:8px; font-size:13px;">
                    Cancel
                </button>
                <button type="submit" style="padding:8px 18px; background:#e74c3c; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; font-size:13px;">
                    <i class="fas fa-ban"></i> Reject Report
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Tenacity of Yarn Test Rejection Modal -->
<div id="yarnRejectModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeYarnRejectModal()">&times;</span>
        <div class="modal-header"><i class="fas fa-exclamation-triangle"></i> Reject Tenacity of Yarn Test</div>
        
        <form method="POST" id="yarnRejectForm">
            <input type="hidden" name="yarn_report_number" id="yarn_report_number_input">
            <input type="hidden" name="yarn_action" value="rejected">
            
            <div class="modal-body">
                <div class="checkbox-group">
                    <strong style="display:block; margin-bottom:8px; font-size:13px;">Rejection Reasons:</strong>
                    <?php foreach ($rejection_reasons['Tenacity Yarn'] as $reason): ?>
                    <div class="checkbox-item">
                        <input type="checkbox" name="yarn_rejection_reasons[]" value="<?php echo htmlspecialchars($reason); ?>" id="yarn_<?php echo md5($reason); ?>">
                        <label for="yarn_<?php echo md5($reason); ?>"><?php echo htmlspecialchars($reason); ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <textarea name="yarn_remarks" class="remarks-box" placeholder="Additional comments (optional)" style="min-height:60px;"></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" onclick="closeYarnRejectModal()" style="padding:8px 18px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer; margin-right:8px; font-size:13px;">
                    Cancel
                </button>
                <button type="submit" style="padding:8px 18px; background:#e74c3c; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; font-size:13px;">
                    <i class="fas fa-ban"></i> Reject Report
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Sewing Thread Test Rejection Modal -->
<div id="sewingRejectModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeSewingRejectModal()">&times;</span>
        <div class="modal-header"><i class="fas fa-exclamation-triangle"></i> Reject Sewing Test</div>
        
        <form method="POST" id="sewingRejectForm">
            <input type="hidden" name="sewing_report_number" id="sewing_report_number_input">
            <input type="hidden" name="sewing_action" value="rejected">
            
            <div class="modal-body">
                <div class="checkbox-group">
                    <strong style="display:block; margin-bottom:8px; font-size:13px;">Rejection Reasons:</strong>
                    <?php foreach ($rejection_reasons['Sewing Thread'] as $reason): ?>
                    <div class="checkbox-item">
                        <input type="checkbox" name="sewing_rejection_reasons[]" value="<?php echo htmlspecialchars($reason); ?>" id="sewing_<?php echo md5($reason); ?>">
                        <label for="sewing_<?php echo md5($reason); ?>"><?php echo htmlspecialchars($reason); ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <textarea name="sewing_remarks" class="remarks-box" placeholder="Additional comments (optional)" style="min-height:60px;"></textarea>
            </div>
            
            <div class="modal-footer">
                <button type="button" onclick="closeSewingRejectModal()" style="padding:8px 18px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer; margin-right:8px; font-size:13px;">
                    Cancel
                </button>
                <button type="submit" style="padding:8px 18px; background:#e74c3c; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:600; font-size:13px;">
                    <i class="fas fa-ban"></i> Reject Report
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Approve Fiber Test (direct, no modal)
function approveFiberTest(reportNumber) {
    if (confirm('Approve Fiber Test Report ' + reportNumber + '?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="fiber_action" value="approved">
            <input type="hidden" name="fiber_report_number" value="${reportNumber}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Approve Sewing Test (direct, no modal)
function approveSewingTest(reportNumber) {
    if (confirm('Approve Sewing Thread Report ' + reportNumber + '?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="sewing_action" value="approved">
            <input type="hidden" name="sewing_report_number" value="${reportNumber}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// Approve Fineness of Fiber Test (direct, no modal)
function approveFinenessFiberTest(reportNumber) {
    if (confirm('Approve Fineness of Fiber Report ' + reportNumber + '?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="fineness_fiber_action" value="approved">
            <input type="hidden" name="fineness_fiber_report_number" value="${reportNumber}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// View Fineness of Fiber Test
function viewFinenessFiberTest(testId, reportNumber) {
    window.open('../forms/fineness_fiber_report.php?view=' + testId + '&approval_dashboard=1&report_number=' + encodeURIComponent(reportNumber), '_blank');
}

// Approve Cut Length of Fiber Test (direct, no modal)
function approveCutLengthFiberTest(reportNumber) {
    if (confirm('Approve Cut Length of Fiber Report ' + reportNumber + '?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="cut_length_fiber_action" value="approved">
            <input type="hidden" name="cut_length_fiber_report_number" value="${reportNumber}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// View Cut Length of Fiber Test
function viewCutLengthFiberTest(testId, reportNumber) {
    window.open('../forms/cut_length_fiber_report.php?view=' + testId + '&approval_dashboard=1&report_number=' + encodeURIComponent(reportNumber), '_blank');
}

// Approve Tenacity of Fiber Test (direct, no modal)
function approveFiberTenacityTest(reportNumber) {
    if (confirm('Approve Tenacity of Fiber Report ' + reportNumber + '?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="fiber_tenacity_action" value="approved">
            <input type="hidden" name="fiber_tenacity_report_number" value="${reportNumber}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// View Tenacity of Fiber Test
function viewFiberTenacityTest(testId, reportNumber) {
    window.open('../forms/tenacity_fiber_report.php?view=' + testId + '&approval_dashboard=1&report_number=' + encodeURIComponent(reportNumber), '_blank');
}

// Approve Tenacity of Yarn Test (direct, no modal)
function approveYarnTest(reportNumber) {
    if (confirm('Approve Tenacity of Yarn Report ' + reportNumber + '?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="yarn_action" value="approved">
            <input type="hidden" name="yarn_report_number" value="${reportNumber}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// View Tenacity of Yarn Test
function viewYarnTest(testId, reportNumber) {
    window.open('../forms/tenacity_yarn_report.php?view=' + testId + '&approval_dashboard=1&report_number=' + encodeURIComponent(reportNumber), '_blank');
}

// Fiber Test Rejection Modal
function openFiberRejectModal(reportNumber) {
    document.getElementById('fiber_report_number_input').value = reportNumber;
    document.getElementById('fiberRejectModal').style.display = 'block';
}

function closeFiberRejectModal() {
    document.getElementById('fiberRejectModal').style.display = 'none';
    document.getElementById('fiberRejectForm').reset();
}

// Sewing Test Rejection Modal
function openSewingRejectModal(reportNumber) {
    document.getElementById('sewing_report_number_input').value = reportNumber;
    document.getElementById('sewingRejectModal').style.display = 'block';
}

function closeSewingRejectModal() {
    document.getElementById('sewingRejectModal').style.display = 'none';
    document.getElementById('sewingRejectForm').reset();
}

// Fineness of Fiber Test Rejection Modal
function openFinenessFiberRejectModal(reportNumber) {
    document.getElementById('fineness_fiber_report_number_input').value = reportNumber;
    document.getElementById('finenessFiberRejectModal').style.display = 'block';
}

function closeFinenessFiberRejectModal() {
    document.getElementById('finenessFiberRejectModal').style.display = 'none';
    document.getElementById('finenessFiberRejectForm').reset();
}

// Cut Length of Fiber Test Rejection Modal
function openCutLengthFiberRejectModal(reportNumber) {
    document.getElementById('cut_length_fiber_report_number_input').value = reportNumber;
    document.getElementById('cutLengthFiberRejectModal').style.display = 'block';
}

function closeCutLengthFiberRejectModal() {
    document.getElementById('cutLengthFiberRejectModal').style.display = 'none';
    document.getElementById('cutLengthFiberRejectForm').reset();
}

// Tenacity of Fiber Test Rejection Modal
function openFiberTenacityRejectModal(reportNumber) {
    document.getElementById('fiber_tenacity_report_number_input').value = reportNumber;
    document.getElementById('fiberTenacityRejectModal').style.display = 'block';
}

function closeFiberTenacityRejectModal() {
    document.getElementById('fiberTenacityRejectModal').style.display = 'none';
    document.getElementById('fiberTenacityRejectForm').reset();
}

// Tenacity of Yarn Test Rejection Modal
function openYarnRejectModal(reportNumber) {
    document.getElementById('yarn_report_number_input').value = reportNumber;
    document.getElementById('yarnRejectModal').style.display = 'block';
}

function closeYarnRejectModal() {
    document.getElementById('yarnRejectModal').style.display = 'none';
    document.getElementById('yarnRejectForm').reset();
}

function showToast(message, type = 'success', duration = 5000) {
    if (!message) return;
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    const icon = document.createElement('span');
    icon.className = 'toast-icon';
    icon.textContent = type === 'error' ? '!' : '✓';

    const content = document.createElement('div');
    content.className = 'toast-message';
    content.textContent = message;

    const closeBtn = document.createElement('button');
    closeBtn.className = 'toast-close';
    closeBtn.type = 'button';
    closeBtn.innerHTML = '&times;';

    let autoHide;
    const removeToast = () => {
        toast.classList.remove('visible');
        clearTimeout(autoHide);
        setTimeout(() => {
            if (toast.parentNode === container) {
                container.removeChild(toast);
            }
        }, 250);
    };

    closeBtn.addEventListener('click', removeToast);
    toast.addEventListener('click', removeToast);

    toast.append(icon, content, closeBtn);
    container.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('visible'));
    autoHide = setTimeout(removeToast, duration);
}

(function displayServerToasts() {
    const successMessage = <?php echo json_encode($message ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const errorMessage = <?php echo json_encode($error ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    if (successMessage) {
        showToast(successMessage, 'success');
    }
    if (errorMessage) {
        showToast(errorMessage, 'error');
    }
})();

// Close modal when clicking outside
window.onclick = function(event) {
    const fiberModal = document.getElementById('fiberRejectModal');
    const sewingModal = document.getElementById('sewingRejectModal');
    const finenessFiberModal = document.getElementById('finenessFiberRejectModal');
    const cutLengthFiberModal = document.getElementById('cutLengthFiberRejectModal');
    const fiberTenacityModal = document.getElementById('fiberTenacityRejectModal');
    const yarnModal = document.getElementById('yarnRejectModal');
    if (event.target === fiberModal) {
        closeFiberRejectModal();
    }
    if (event.target === sewingModal) {
        closeSewingRejectModal();
    }
    if (event.target === finenessFiberModal) {
        closeFinenessFiberRejectModal();
    }
    if (event.target === cutLengthFiberModal) {
        closeCutLengthFiberRejectModal();
    }
    if (event.target === fiberTenacityModal) {
        closeFiberTenacityRejectModal();
    }
    if (event.target === yarnModal) {
        closeYarnRejectModal();
    }
}

// Validate rejection form
document.getElementById('fiberRejectForm').addEventListener('submit', function(e) {
    const checkboxes = document.querySelectorAll('input[name="fiber_rejection_reasons[]"]:checked');
    if (checkboxes.length === 0) {
        e.preventDefault();
        alert('Please select at least one rejection reason.');
        return false;
    }
});

document.getElementById('sewingRejectForm').addEventListener('submit', function(e) {
    const checkboxes = document.querySelectorAll('input[name="sewing_rejection_reasons[]"]:checked');
    if (checkboxes.length === 0) {
        e.preventDefault();
        alert('Please select at least one rejection reason.');
        return false;
    }
});

document.getElementById('finenessFiberRejectForm').addEventListener('submit', function(e) {
    const checkboxes = document.querySelectorAll('input[name="fineness_fiber_rejection_reasons[]"]:checked');
    if (checkboxes.length === 0) {
        e.preventDefault();
        alert('Please select at least one rejection reason.');
        return false;
    }
});

document.getElementById('cutLengthFiberRejectForm').addEventListener('submit', function(e) {
    const checkboxes = document.querySelectorAll('input[name="cut_length_fiber_rejection_reasons[]"]:checked');
    if (checkboxes.length === 0) {
        e.preventDefault();
        alert('Please select at least one rejection reason.');
        return false;
    }
});

document.getElementById('fiberTenacityRejectForm').addEventListener('submit', function(e) {
    const checkboxes = document.querySelectorAll('input[name="fiber_tenacity_rejection_reasons[]"]:checked');
    if (checkboxes.length === 0) {
        e.preventDefault();
        alert('Please select at least one rejection reason.');
        return false;
    }
});

document.getElementById('yarnRejectForm').addEventListener('submit', function(e) {
    const checkboxes = document.querySelectorAll('input[name="yarn_rejection_reasons[]"]:checked');
    if (checkboxes.length === 0) {
        e.preventDefault();
        alert('Please select at least one rejection reason.');
        return false;
    }
});
</script>

<script>
// Refresh only when approve/reject actions happen
(function() {
    let isRefreshing = false;
    
    // Function to refresh the dashboard
    function refreshDashboard() {
        if (isRefreshing) return;
        isRefreshing = true;
        
        // Reload the page with cache busting
        window.location.href = window.location.href.split('?')[0] + '?t=' + new Date().getTime();
    }
    
    // Listen for messages from child windows (approval/rejection pages)
    window.addEventListener('message', function(event) {
        // Verify origin for security
        if (event.origin !== window.location.origin) {
            return;
        }
        
        // If message indicates a report was processed (approved/rejected), refresh
        if (event.data && (event.data.type === 'report_processed' || event.data.type === 'report_approved' || event.data.type === 'report_rejected')) {
            // Small delay to ensure database is updated
            setTimeout(function() {
                refreshDashboard();
            }, 300);
        }
    });
})();
</script>
</body>
</html>


