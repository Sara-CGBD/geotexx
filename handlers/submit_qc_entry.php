<?php
session_start();
require_once '../config/security_config.php';

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.html');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../forms/qc_entry.php');
    exit;
}

// Set BD time
date_default_timezone_set('Asia/Dhaka');

// Collect common fields
$qcId        = trim($_POST['qc_id'] ?? '');
$dateTime    = trim($_POST['dateTime'] ?? '');
$shift       = trim($_POST['shift'] ?? '');
$qcStage     = trim($_POST['qc_stage'] ?? '');
$qcType      = trim($_POST['qc_type'] ?? '');
$qcResult    = trim($_POST['qc_result'] ?? '');
$remarks     = substr(trim($_POST['remarks'] ?? ''), 0, 255);

// Stage-specific (optional) fields
$projectRoll = (int)($_POST['project_roll'] ?? 0);
$bagSizeRoll = trim($_POST['bag_size_roll'] ?? '');
$recWtRoll   = (int)($_POST['rec_wt_roll'] ?? 0);
$actWtRoll   = (int)($_POST['act_wt_roll'] ?? 0);

$projectCNC = (int)($_POST['project_cnc'] ?? 0);
$bagSizeCNC = trim($_POST['bag_size_cnc'] ?? '');
$recWtCNC   = (int)($_POST['rec_wt_cnc'] ?? 0);
$actWtCNC   = (int)($_POST['act_wt_cnc'] ?? 0);

$projectProd = (int)($_POST['project_prod'] ?? 0);
$gsmProd     = (int)($_POST['gsm_prod'] ?? 0);
$lineNoProd  = (int)($_POST['line_no_prod'] ?? 0);
$fiberType   = trim($_POST['fiber_type_prod'] ?? '');
$rollNumProd = trim($_POST['roll_number_prod'] ?? '');
$totalWtProd = (int)($_POST['total_wt_prod'] ?? 0);

// New FG fields
$fgReferenceNumber = trim($_POST['fg_reference'] ?? '');
$fgAmount = trim($_POST['fg_amount'] ?? '');
$bagNoFG = trim($_POST['bag_no'] ?? '');
$weightFG = trim($_POST['weight'] ?? '');
$stitchFG = trim($_POST['stitch'] ?? '');
$actualLengthFG = trim($_POST['actual_length'] ?? '');
$widthFG = trim($_POST['width'] ?? '');
$marginLeftFG = trim($_POST['margin_left'] ?? '');
$marginRightFG = trim($_POST['margin_right'] ?? '');
$qcInspectorFG = trim($_POST['qc_inspector_fg'] ?? '');

// Old FG fields (for backward compatibility)
$projectFG   = (int)($_POST['project_fg'] ?? 0);
$bagSizeFG   = trim($_POST['bag_size_fg'] ?? '');
$recWtFG     = (int)($_POST['rec_wt_fg'] ?? 0);
$actWtFG     = (int)($_POST['act_wt_fg'] ?? 0);
$qualityFG   = trim($_POST['quality_checked_fg'] ?? '');
$passedFG    = (int)($_POST['passed_qty_fg'] ?? 0);
$rejectedFG  = (int)($_POST['rejected_qty_fg'] ?? 0);
$packTypeFG  = trim($_POST['packaging_type_fg'] ?? '');

// Derived/linked IDs
$rollEntryId = null; // from roll_entry by roll number (production section)
$batchFromRoll = null;

// Normalize BD date time
try {
    $dt = $dateTime !== '' ? new DateTime($dateTime, new DateTimeZone('Asia/Dhaka')) : new DateTime('now', new DateTimeZone('Asia/Dhaka'));
    $dateTime = $dt->format('Y-m-d H:i:s');
} catch (Throwable $e) {
    $dateTime = date('Y-m-d H:i:s');
}

// Minimal validation
if ($qcId === '' || $qcStage === '' || $qcType === '' || $qcResult === '') {
    header('Location: ../forms/qc_entry.php?error=' . urlencode('Missing required QC fields'));
    exit;
}

try {
    $conn = SecurityConfig::getConnection();

    // Create table if not exists (wide table with nullable stage fields)
    $create = "CREATE TABLE IF NOT EXISTS qc_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        qc_id VARCHAR(50) UNIQUE,
        date_time DATETIME,
        shift VARCHAR(20),
        qc_stage VARCHAR(50),
        qc_type VARCHAR(100),
        qc_result VARCHAR(20),
        remarks VARCHAR(255),
        reporter_id INT DEFAULT NULL,
        roll_entry_id INT DEFAULT NULL,
        -- Roll
        project_roll INT DEFAULT NULL,
        bag_size_roll VARCHAR(100) DEFAULT NULL,
        rec_wt_roll INT DEFAULT NULL,
        act_wt_roll INT DEFAULT NULL,
        -- CNC
        project_cnc INT DEFAULT NULL,
        bag_size_cnc VARCHAR(100) DEFAULT NULL,
        rec_wt_cnc INT DEFAULT NULL,
        act_wt_cnc INT DEFAULT NULL,
        -- Production
        project_prod INT DEFAULT NULL,
        gsm_prod INT DEFAULT NULL,
        line_no_prod INT DEFAULT NULL,
        fiber_type_prod VARCHAR(100) DEFAULT NULL,
        roll_number_prod VARCHAR(100) DEFAULT NULL,
        total_wt_prod INT DEFAULT NULL,
        batch_number_prod VARCHAR(100) DEFAULT NULL,
        -- FG (new fields)
        fg_reference_number VARCHAR(100) DEFAULT NULL,
        fg_amount DECIMAL(10,2) DEFAULT NULL,
        bag_no_fg VARCHAR(100) DEFAULT NULL,
        weight_fg DECIMAL(10,2) DEFAULT NULL,
        stitch_fg VARCHAR(100) DEFAULT NULL,
        actual_length_fg DECIMAL(10,2) DEFAULT NULL,
        width_fg DECIMAL(10,2) DEFAULT NULL,
        margin_left_fg DECIMAL(10,2) DEFAULT NULL,
        margin_right_fg DECIMAL(10,2) DEFAULT NULL,
        -- FG (old fields for backward compatibility)
        project_fg INT DEFAULT NULL,
        bag_size_fg VARCHAR(100) DEFAULT NULL,
        rec_wt_fg INT DEFAULT NULL,
        act_wt_fg INT DEFAULT NULL,
        quality_checked_fg VARCHAR(100) DEFAULT NULL,
        passed_qty_fg INT DEFAULT NULL,
        rejected_qty_fg INT DEFAULT NULL,
        packaging_type_fg VARCHAR(100) DEFAULT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        approved_by VARCHAR(255) DEFAULT NULL,
        approved_at DATETIME DEFAULT NULL,
        rejection_reason TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($create);

    // Ensure columns exist if table predated
    $ensureCols = [
        // core columns
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS qc_id VARCHAR(50)",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS date_time DATETIME",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS shift VARCHAR(20)",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS qc_stage VARCHAR(50)",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS qc_type VARCHAR(100)",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS qc_result VARCHAR(20)",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS remarks VARCHAR(255)",
        // stage/link columns
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS reporter_id INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS inspector_name VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS roll_entry_id INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS project_roll INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS bag_size_roll VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS rec_wt_roll INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS act_wt_roll INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS project_cnc INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS bag_size_cnc VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS rec_wt_cnc INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS act_wt_cnc INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS project_prod INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS gsm_prod INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS line_no_prod INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS fiber_type_prod VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS roll_number_prod VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS total_wt_prod INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS batch_number_prod VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS fg_reference_number VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS fg_amount DECIMAL(10,2) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS bag_no_fg VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS weight_fg DECIMAL(10,2) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS stitch_fg VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS actual_length_fg DECIMAL(10,2) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS width_fg DECIMAL(10,2) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS margin_left_fg DECIMAL(10,2) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS margin_right_fg DECIMAL(10,2) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS project_fg INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS bag_size_fg VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS rec_wt_fg INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS act_wt_fg INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS quality_checked_fg VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS passed_qty_fg INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS rejected_qty_fg INT DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS packaging_type_fg VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS status VARCHAR(20) DEFAULT 'pending'",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS approved_by VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS approved_at DATETIME DEFAULT NULL",
        "ALTER TABLE qc_entries ADD COLUMN IF NOT EXISTS rejection_reason TEXT DEFAULT NULL"
    ];
    foreach ($ensureCols as $sql) { $conn->query($sql); }

    // Resolve linked roll_entry by provided roll number (production section)
    if ($rollNumProd !== '') {
        $q = $conn->prepare("SELECT id, batch_number FROM roll_entry WHERE roll_number = ? ORDER BY id DESC LIMIT 1");
        if ($q) {
            $q->bind_param('s', $rollNumProd);
            $q->execute();
            $res = $q->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $rollEntryId = (int)$row['id'];
                $batchFromRoll = $row['batch_number'] ?? null;
            }
            $q->close();
        }
    }

    // Fallback: Generate batch number if not found, based on provided production fields (same logic as forms)
    if (empty($batchFromRoll)) {
        // Build YYMonDD from BD time, with night shift belonging to previous day
        $now = new DateTime($dateTime, new DateTimeZone('Asia/Dhaka'));
        $hour = (int)$now->format('H');
        if ($hour < 8) { $now->modify('-1 day'); }
        $yy = $now->format('y');
        $mon = $now->format('M');
        $dd = $now->format('d');
        $dateStr = $yy . $mon . $dd; // e.g., 25Jan23

        // Compose only if we have essentials
        $gsmPart   = $gsmProd ? (string)$gsmProd : '';
        $linePart  = $lineNoProd ? ('L' . (string)$lineNoProd) : '';
        $fiberPart = $fiberType ?: '';
        $rollPart  = $rollNumProd ?: '';

        if ($gsmPart && $linePart && $fiberPart && $rollPart) {
            $batchFromRoll = $gsmPart . $linePart . $fiberPart . $dateStr . $rollPart;
        }
    }

    // For CNC QC: batch number should be the CNC ID from cnc_entries
    if (strtoupper($qcStage) === 'CNC') {
        $q = $conn->query("SELECT cnc_id FROM cnc_entries ORDER BY id DESC LIMIT 1");
        if ($q && ($row = $q->fetch_assoc())) {
            if (!empty($row['cnc_id'])) {
                $batchFromRoll = $row['cnc_id'];
            }
        }
    }

    // Determine status based on user role
    $userRole = strtolower(trim($_SESSION['role'] ?? ''));
    $reporterId = $_SESSION['user_id'] ?? null;
    $reporterName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Unknown';
    
    // For FG stage, use qc_inspector_fg if provided, otherwise use reporterName
    $inspectorName = $reporterName;
    if (strtoupper($qcStage) === 'FG' && !empty($qcInspectorFG)) {
        $inspectorName = $qcInspectorFG;
    }
    
    // Admin and AGM Ops can directly approve QC entries
    if (in_array($userRole, ['admin', 'agm ops', 'agm operations', 'management'])) {
        $status = 'approved';
        $approvedBy = $_SESSION['full_name'] ?? $_SESSION['username'];
        $approvedAt = date('Y-m-d H:i:s');
    } else {
        // QC inspectors need approval
        $status = 'pending';
        $approvedBy = null;
        $approvedAt = null;
    }

    // Convert FG amount and numeric fields
    $fgAmountNum = $fgAmount !== '' ? (float)$fgAmount : null;
    $weightFGNum = $weightFG !== '' ? (float)$weightFG : null;
    $actualLengthFGNum = $actualLengthFG !== '' ? (float)$actualLengthFG : null;
    $widthFGNum = $widthFG !== '' ? (float)$widthFG : null;
    $marginLeftFGNum = $marginLeftFG !== '' ? (float)$marginLeftFG : null;
    $marginRightFGNum = $marginRightFG !== '' ? (float)$marginRightFG : null;

    // Insert
    $stmt = $conn->prepare("INSERT INTO qc_entries (
        qc_id, date_time, shift, qc_stage, qc_type, qc_result, remarks, status, approved_by, approved_at, reporter_id, inspector_name, roll_entry_id,
        project_roll, bag_size_roll, rec_wt_roll, act_wt_roll,
        project_cnc, bag_size_cnc, rec_wt_cnc, act_wt_cnc,
        project_prod, gsm_prod, line_no_prod, fiber_type_prod, roll_number_prod, total_wt_prod, batch_number_prod,
        fg_reference_number, fg_amount, bag_no_fg, weight_fg, stitch_fg, actual_length_fg, width_fg, margin_left_fg, margin_right_fg,
        project_fg, bag_size_fg, rec_wt_fg, act_wt_fg, quality_checked_fg, passed_qty_fg, rejected_qty_fg, packaging_type_fg
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?, ?, ?
    )");

    if (!$stmt) { throw new Exception('Prepare failed: ' . $conn->error); }

    // Count parameters: 13 + 4 + 4 + 7 + 9 + 8 = 45 total
    // Using 's' for all parameters - MySQLi will handle type conversion automatically
    $stmt->bind_param(
        str_repeat('s', 45),
        $qcId, $dateTime, $shift, $qcStage, $qcType, $qcResult, $remarks, $status, $approvedBy, $approvedAt, $reporterId, $inspectorName, $rollEntryId,
        $projectRoll, $bagSizeRoll, $recWtRoll, $actWtRoll,
        $projectCNC, $bagSizeCNC, $recWtCNC, $actWtCNC,
        $projectProd, $gsmProd, $lineNoProd, $fiberType, $rollNumProd, $totalWtProd, $batchFromRoll,
        $fgReferenceNumber, $fgAmountNum, $bagNoFG, $weightFGNum, $stitchFG, $actualLengthFGNum, $widthFGNum, $marginLeftFGNum, $marginRightFGNum,
        $projectFG, $bagSizeFG, $recWtFG, $actWtFG, $qualityFG, $passedFG, $rejectedFG, $packTypeFG
    );

    if (!$stmt->execute()) { throw new Exception('Execute failed: ' . $stmt->error); }

    $stmt->close();
    $conn->close();

    header('Location: ../forms/qc_entry.php?success=1&qc_id=' . urlencode($qcId));
    exit;

} catch (Throwable $e) {
    header('Location: ../forms/qc_entry.php?error=' . urlencode($e->getMessage()));
    exit;
}



