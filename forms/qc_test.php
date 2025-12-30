<?php
session_start();

// AJAX endpoint to generate external reference - MUST be before anything that outputs HTML
if (isset($_GET['action']) && $_GET['action'] === 'generate_external_ref') {
    require_once 'security_config.php';
    
    // Verify user is logged in for AJAX call
    if (!isset($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'error' => 'Not authenticated'
        ]);
        exit();
    }
    
    $conn = SecurityConfig::getConnection();
    
    // Generate external reference
    $today = date('Ymd');
    $pattern = "EXT-{$today}-%";
    
    $count = 0;
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c 
        FROM qc_tests 
        WHERE sample_reference_id LIKE ? 
        AND DATE(created_at) = CURDATE()
    ");
    
    if ($stmt) {
        $stmt->bind_param('s', $pattern);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            $row = $res->fetch_assoc();
            $count = (int)($row['c'] ?? 0);
        }
        $stmt->close();
    }
    
    $seq = $count + 1;
    $reference = 'EXT-' . $today . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'reference' => $reference
    ]);
    exit();
}

// Include auto-reload AFTER AJAX endpoint to prevent it from contaminating JSON response
include_once(__DIR__ . '/../dev/auto_reload.php'); // Auto-reload for development

// Prevent browser caching to ensure fresh data loads
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once 'security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header("Location: ../login.html");
    exit();
}
if (SecurityConfig::checkSessionTimeout()) {
    session_destroy();
    header("Location: ../login.html?error=timeout");
    exit();
}
SecurityConfig::updateSessionActivity();
if (SecurityConfig::isAccountLocked($_SESSION['username'])) {
    session_destroy();
    header("Location: ../login.html?error=disabled");
    exit();
}

// Role-based access control for QC module
require_once '../config/AccessControl.php';
if (!AccessControl::hasModuleAccess($_SESSION['role'], AccessControl::MODULE_QC, AccessControl::PERMISSION_ENTRY)) {
    http_response_code(403);
    die("<div style='font-family: Arial; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
        <h2 style='color: #e74c3c;'>?? Access Denied</h2>
        <p>You do not have permission to access the QC Test module.</p>
        <p>Your role: <strong>" . htmlspecialchars($_SESSION['role']) . "</strong></p>
        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
        </div>");
}

date_default_timezone_set('Asia/Dhaka');
$conn = SecurityConfig::getConnection();
$reporter_id = $_SESSION['user_id'];
$reporter_name = $_SESSION['full_name'] ?? $_SESSION['username'];
$user_role = strtolower(trim($_SESSION['role'] ?? ''));

// Check user role permissions
$is_tester = in_array($user_role, ['tester', 'qc_inspector', 'admin']);
$is_checker = ($user_role === 'checker' || $user_role === 'admin');
$is_admin = ($user_role === 'admin' || $user_role === 'agm ops' || $user_role === 'agm operations');

$message = '';
$error = '';

// Check for success message from session (after redirect)
if (isset($_SESSION['qc_success_message'])) {
    $message = $_SESSION['qc_success_message'];
    unset($_SESSION['qc_success_message']); // Clear it after displaying
}

// Check for success message from redirect (legacy)
if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
}

// Check if we're in edit mode
$edit_mode = false;
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$existing_report = null;
$existing_test_data = [];

if ($edit_id > 0) {
    // Fetch the rejected report for editing
    $stmt = $conn->prepare("
        SELECT qto.*, ts.test_name, ts.standard_code 
        FROM qc_tests qto
        LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
        WHERE qto.id = ? AND qto.inspector_id = ? 
        AND qto.status IN ('rejected_by_checker', 'rejected_by_approver')
    ");
    $stmt->bind_param("ii", $edit_id, $reporter_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing_report = $result->fetch_assoc();
    $stmt->close();
    
    if ($existing_report) {
        $edit_mode = true;
        $existing_test_data = json_decode($existing_report['test_data'], true) ?? [];
    } else {
        $error = "Report not found or you don't have permission to edit it.";
    }
}

// Fetch entry numbers from store_received_entries (after Fiber/Sewing tests)
$references = [];
$refQuery = $conn->query("SELECT DISTINCT entry_number FROM store_received_entries WHERE entry_number IS NOT NULL ORDER BY date_time DESC LIMIT 50");
if ($refQuery) {
    while ($row = $refQuery->fetch_assoc()) {
        $references[] = $row['entry_number'];
    }
}

// Define the specific test methods as requested
$test_methods = [
    'Thickness (Under 2kPa Pressure)' => ['ASTM D5199', 'ISO 9863-1'],
    'Mass Per Unit Area (GSM)' => ['ASTM D5261', 'ISO 9864'],
    'Strip Tensile Test' => ['ASTM D4595', 'ISO 10319'],
    'CBR Puncture Resistance' => ['ASTM D6241', 'ISO 12236'],
    'Grab Tensile Test' => ['ASTM D4632'],
    'Weathering Exposure Test' => ['ASTM D4533'],
    'Seam/Joint Test' => ['ISO 10321'],
    'Fineness of Fiber' => ['ISO 1973'],
    'Cut Length of Fiber' => ['ASTM D5103', 'ASTM D5199'],
    'Tenacity of Fiber' => ['ISO 5079'],
    'Tenacity of Yarn' => ['ASTM D2256']
];

// Define which tests require checker approval (first 5 tests only)
$tests_requiring_checker = [
    'Thickness (Under 2kPa Pressure)',
    'Mass Per Unit Area (GSM)',
    'Strip Tensile Test',
    'CBR Puncture Resistance',
    'Grab Tensile Test'
];

// Load user's last used values from database for auto-fill
$user_qc_prefs = [];
try {
    // First check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'user_qc_preferences'");
    if ($table_check && $table_check->num_rows > 0) {
        $pref_query = $conn->prepare("SELECT field_name, field_value FROM user_qc_preferences WHERE user_id = ?");
        $pref_query->bind_param("i", $reporter_id);
        $pref_query->execute();
        $pref_result = $pref_query->get_result();
        while ($pref_row = $pref_result->fetch_assoc()) {
            $user_qc_prefs[$pref_row['field_name']] = $pref_row['field_value'];
        }
        $pref_query->close();
        
        error_log("QC Test: Loaded " . count($user_qc_prefs) . " preferences for user {$reporter_id}");
    } else {
        error_log("QC Test: user_qc_preferences table does not exist");
    }
} catch (Exception $e) {
    error_log("QC Test: Error loading preferences - " . $e->getMessage());
}

// Merge with session data (session takes precedence)
$session_data = $_SESSION['qc_last_general'] ?? [];
$user_qc_prefs = array_merge($user_qc_prefs, $session_data);

error_log("QC Test: Final prefs count = " . count($user_qc_prefs) . " (DB: " . (count($user_qc_prefs) - count($session_data)) . " + Session: " . count($session_data) . ")");

// Fetch existing QC Tests to prevent duplicates
// Build a map of reference_number => [test_methods]
$existing_tests = [];
$existingQuery = $conn->query("
    SELECT sample_reference_id, chosen_method, test_data 
    FROM qc_tests 
    WHERE status NOT IN ('rejected_by_checker', 'rejected_by_approver')
");
if ($existingQuery) {
    while ($row = $existingQuery->fetch_assoc()) {
        $ref = $row['sample_reference_id'];
        $method = $row['chosen_method'];
        
        // Also check test_data JSON for product_reference, fiber_reference_no, yarn_reference_no
        $test_data = json_decode($row['test_data'], true);
        $additional_refs = [];
        if (isset($test_data['product_reference'])) {
            $additional_refs[] = $test_data['product_reference'];
        }
        if (isset($test_data['fiber_reference_no'])) {
            $additional_refs[] = $test_data['fiber_reference_no'];
        }
        if (isset($test_data['yarn_reference_no'])) {
            $additional_refs[] = $test_data['yarn_reference_no'];
        }
        
        // Add all references with this method
        $all_refs = array_merge([$ref], $additional_refs);
        foreach ($all_refs as $reference) {
            if (!empty($reference)) {
                if (!isset($existing_tests[$reference])) {
                    $existing_tests[$reference] = [];
                }
                if (!in_array($method, $existing_tests[$reference])) {
                    $existing_tests[$reference][] = $method;
                }
            }
        }
    }
}

// Handle form submission (both new and edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_order'])) {
    try {
        $is_editing = isset($_POST['edit_id']) && (int)$_POST['edit_id'] > 0;
        $edit_id_post = $is_editing ? (int)$_POST['edit_id'] : 0;
        
        $sample_reference_id = trim($_POST['sample_reference_id']);
        
        if (empty($sample_reference_id)) {
            throw new Exception("Sample Reference ID is required.");
        }
        
        // Check if at least one test method is selected (enforce exactly one)
        $selected_methods = [];
        foreach ($test_methods as $test_name => $methods) {
            foreach ($methods as $method) {
                $field_name = 'test_' . md5($test_name . '_' . $method);
                if (isset($_POST[$field_name])) {
                    $selected_methods[] = [
                        'test_name' => $test_name,
                        'method' => $method
                    ];
                }
            }
        }
        
        
        if (empty($selected_methods)) {
            throw new Exception("Please select at least one test method.");
        }
        if (count($selected_methods) !== 1) {
            throw new Exception("Select exactly one test method before submitting.");
        }
        
        // Check if this is an external product
        $is_external_product = isset($_POST['is_external_product']) && $_POST['is_external_product'] == '1';
        
        // Generate proper sample reference ID
        if ($is_external_product && isset($_POST['external_reference']) && !empty($_POST['external_reference'])) {
            // For external products, use the external reference as sample_reference_id
            $generated_sample_ref = $_POST['external_reference'];
            error_log("QC Test: Using external reference as sample_reference_id: " . $generated_sample_ref);
        } else {
            // For production products, generate with sequence
            $generated_sample_ref = generateSampleReferenceId();
        }
        
        // Get the user's selected reference number (for fetching in summary report)
        $user_reference = '';
        
        if ($is_external_product && isset($_POST['external_reference']) && !empty($_POST['external_reference'])) {
            // External product - use auto-generated external reference
            $user_reference = $_POST['external_reference'];
            error_log("QC Test: Using external reference: " . $user_reference);
        } elseif (isset($_POST['product_reference']) && !empty($_POST['product_reference'])) {
            $user_reference = $_POST['product_reference'];
        } elseif (isset($_POST['fiber_reference_no']) && !empty($_POST['fiber_reference_no'])) {
            $user_reference = $_POST['fiber_reference_no'];
        } elseif (isset($_POST['yarn_reference_no']) && !empty($_POST['yarn_reference_no'])) {
            $user_reference = $_POST['yarn_reference_no'];
        }
        
        // Update the sample reference ID in the form for display
        $_POST['sample_reference_id'] = $generated_sample_ref;
        
        $conn->begin_transaction();
        
        $inserted_count = 0;
        foreach ($selected_methods as $selected) {
            // Find the test_standard_id from database
            $stmt = $conn->prepare("SELECT id FROM test_standards WHERE test_name = ? AND standard_code = ? LIMIT 1");
            $stmt->bind_param("ss", $selected['test_name'], $selected['method']);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            $stmt->close();
            
            if (!$row) {
                // Test standard not found - log and skip
                $debug_msg = "=== QC Test ERROR ===\n";
                $debug_msg .= "Test standard NOT FOUND for: " . $selected['test_name'] . " - " . $selected['method'] . "\n";
                file_put_contents('qc_debug.txt', $debug_msg, FILE_APPEND);
                error_log("QC Test: Test standard not found for " . $selected['test_name'] . " - " . $selected['method']);
                continue; // Skip this test
            }
            
            $test_standard_id = $row['id'];
            
            // Prepare test data JSON
            $test_data = [];
            
            // Debug logging to file
            $debug_msg = "=== QC Test Debug ===\n";
            $debug_msg .= "Test: " . $selected['test_name'] . " - " . $selected['method'] . "\n";
            $debug_msg .= "Test Standard ID: " . $test_standard_id . "\n";
            $debug_msg .= "product_reference: " . ($_POST['product_reference'] ?? 'NOT SET') . "\n";
            $debug_msg .= "fiber_reference_no: " . ($_POST['fiber_reference_no'] ?? 'NOT SET') . "\n";
            $debug_msg .= "user_reference: " . $user_reference . "\n";
            file_put_contents('qc_debug.txt', $debug_msg, FILE_APPEND);
            
            // Save general form fields to test_data for editing
            $general_fields = [
                'sample_details', 'batch_information', 'sample_collected_from', 
                'sample_received_datetime', 'sample_production_date', 
                'temperature', 'rh_percentage', 'test_period_from', 'test_period_to',
                'roll_number', 'gsm', 'customer_reference', 'sample_received_from',
                'lighthouse_reference', 'other_info'
            ];
            foreach ($general_fields as $field) {
                if (isset($_POST[$field]) && $_POST[$field] !== '') {
                    $test_data[$field] = $_POST[$field];
                }
            }
            
            // Save reference numbers to test_data for filtering
            $test_data['is_external_product'] = $is_external_product;
            
            if ($is_external_product && isset($_POST['external_reference']) && !empty($_POST['external_reference'])) {
                $test_data['external_reference'] = $_POST['external_reference'];
                file_put_contents('qc_debug.txt', "Saved external_reference: " . $_POST['external_reference'] . "\n", FILE_APPEND);
            } elseif (isset($_POST['product_reference']) && !empty($_POST['product_reference'])) {
                $test_data['product_reference'] = $_POST['product_reference'];
                file_put_contents('qc_debug.txt', "Saved product_reference: " . $_POST['product_reference'] . "\n", FILE_APPEND);
            }
            
            if (isset($_POST['fiber_reference_no']) && !empty($_POST['fiber_reference_no'])) {
                $test_data['fiber_reference_no'] = $_POST['fiber_reference_no'];
                file_put_contents('qc_debug.txt', "Saved fiber_reference_no: " . $_POST['fiber_reference_no'] . "\n", FILE_APPEND);
            }
            if (isset($_POST['yarn_reference_no']) && !empty($_POST['yarn_reference_no'])) {
                $test_data['yarn_reference_no'] = $_POST['yarn_reference_no'];
                file_put_contents('qc_debug.txt', "Saved yarn_reference_no: " . $_POST['yarn_reference_no'] . "\n", FILE_APPEND);
            }
                
                // Collect thickness test data if this is a thickness test
                if ($selected['test_name'] === 'Thickness (Under 2kPa Pressure)') {
                    $test_data['positions'] = [];
                    for ($i = 1; $i <= 16; $i++) {
                        $position_key = 'astmd5199_position_' . $i;
                        $value_key = 'astmd5199_under2kpa_' . $i;
                        if (isset($_POST[$position_key]) && isset($_POST[$value_key])) {
                            $test_data['positions'][] = [
                                'position' => $_POST[$position_key],
                                'thickness' => floatval($_POST[$value_key])
                            ];
                        }
                    }
                    // Collect statistics
                    if (isset($_POST['astmd5199_avg'])) {
                        $test_data['average'] = floatval($_POST['astmd5199_avg']);
                        $test_data['sd'] = floatval($_POST['astmd5199_sd'] ?? 0);
                        $test_data['cv'] = floatval($_POST['astmd5199_cv'] ?? 0);
                        $test_data['max'] = floatval($_POST['astmd5199_max'] ?? 0);
                        $test_data['min'] = floatval($_POST['astmd5199_min'] ?? 0);
                    }
                }
                
                // Collect Mass Per Unit Area (GSM) test data
                if ($selected['test_name'] === 'Mass Per Unit Area (GSM)') {
                    $test_data['positions'] = [];
                    for ($i = 1; $i <= 16; $i++) {
                        $position_key = 'gsm_position_' . $i;
                        $value_key = 'gsm_weight_' . $i;
                        $calc_key = 'gsm_calculated_' . $i;
                        
                        // Check if position exists
                        if (isset($_POST[$position_key]) && !empty($_POST[$position_key])) {
                            $position_data = [
                                'position' => $_POST[$position_key]
                            ];
                            
                            // Add weight if provided
                            if (isset($_POST[$value_key]) && $_POST[$value_key] !== '') {
                                $position_data['weight'] = floatval($_POST[$value_key]);
                            }
                            
                            // Add calculated GSM if provided
                            if (isset($_POST[$calc_key]) && $_POST[$calc_key] !== '') {
                                $position_data['gsm'] = floatval($_POST[$calc_key]);
                            }
                            
                            $test_data['positions'][] = $position_data;
                        }
                    }
                    // Collect statistics
                    if (isset($_POST['gsm_avg'])) {
                        $test_data['average'] = floatval($_POST['gsm_avg']);
                        $test_data['sd'] = floatval($_POST['gsm_sd'] ?? 0);
                        $test_data['cv'] = floatval($_POST['gsm_cv'] ?? 0);
                        $test_data['max'] = floatval($_POST['gsm_max'] ?? 0);
                        $test_data['min'] = floatval($_POST['gsm_min'] ?? 0);
                    }
                }
                
                // Collect Strip Tensile Test data
                if ($selected['test_name'] === 'Strip Tensile Test') {
                    $test_data['strip_data'] = [];
                    $i = 1;
                    while (isset($_POST["strip_position_$i"])) {
                        $test_data['strip_data'][] = [
                            'position' => $_POST["strip_position_$i"] ?? '',
                            'direction' => $_POST["strip_direction_$i"] ?? '',
                            'strength' => floatval($_POST["strip_strength_$i"] ?? 0),
                            'elongation' => floatval($_POST["strip_elongation_$i"] ?? 0)
                        ];
                        $i++;
                    }
                    // Collect summary statistics
                    $test_data['summary'] = [
                        'md' => [
                            'strength_avg' => floatval($_POST['strip_md_strength_avg'] ?? 0),
                            'strength_sd' => floatval($_POST['strip_md_strength_sd'] ?? 0),
                            'strength_cv' => floatval($_POST['strip_md_strength_cv'] ?? 0),
                            'strength_max' => floatval($_POST['strip_md_strength_max'] ?? 0),
                            'strength_min' => floatval($_POST['strip_md_strength_min'] ?? 0),
                            'elongation_avg' => floatval($_POST['strip_md_elongation_avg'] ?? 0),
                            'elongation_sd' => floatval($_POST['strip_md_elongation_sd'] ?? 0),
                            'elongation_cv' => floatval($_POST['strip_md_elongation_cv'] ?? 0),
                            'elongation_max' => floatval($_POST['strip_md_elongation_max'] ?? 0),
                            'elongation_min' => floatval($_POST['strip_md_elongation_min'] ?? 0)
                        ],
                        'cd' => [
                            'strength_avg' => floatval($_POST['strip_cd_strength_avg'] ?? 0),
                            'strength_sd' => floatval($_POST['strip_cd_strength_sd'] ?? 0),
                            'strength_cv' => floatval($_POST['strip_cd_strength_cv'] ?? 0),
                            'strength_max' => floatval($_POST['strip_cd_strength_max'] ?? 0),
                            'strength_min' => floatval($_POST['strip_cd_strength_min'] ?? 0),
                            'elongation_avg' => floatval($_POST['strip_cd_elongation_avg'] ?? 0),
                            'elongation_sd' => floatval($_POST['strip_cd_elongation_sd'] ?? 0),
                            'elongation_cv' => floatval($_POST['strip_cd_elongation_cv'] ?? 0),
                            'elongation_max' => floatval($_POST['strip_cd_elongation_max'] ?? 0),
                            'elongation_min' => floatval($_POST['strip_cd_elongation_min'] ?? 0)
                        ]
                    ];
                }
                
                // Collect CBR Puncture Resistance test data
                if ($selected['test_name'] === 'CBR Puncture Resistance') {
                    $test_data['cbr_data'] = [];
                    $i = 1;
                    while (isset($_POST["cbr_position_$i"])) {
                        $test_data['cbr_data'][] = [
                            'position' => $_POST["cbr_position_$i"] ?? '',
                            'force' => floatval($_POST["cbr_force_$i"] ?? 0),
                            'displacement' => floatval($_POST["cbr_displacement_$i"] ?? 0)
                        ];
                        $i++;
                    }
                    // Collect summary statistics
                    $test_data['summary'] = [
                        'force' => [
                            'avg' => floatval($_POST['cbr_force_avg'] ?? 0),
                            'sd' => floatval($_POST['cbr_force_sd'] ?? 0),
                            'cv' => floatval($_POST['cbr_force_cv'] ?? 0),
                            'max' => floatval($_POST['cbr_force_max'] ?? 0),
                            'min' => floatval($_POST['cbr_force_min'] ?? 0)
                        ],
                        'displacement' => [
                            'avg' => floatval($_POST['cbr_displacement_avg'] ?? 0),
                            'sd' => floatval($_POST['cbr_displacement_sd'] ?? 0),
                            'cv' => floatval($_POST['cbr_displacement_cv'] ?? 0),
                            'max' => floatval($_POST['cbr_displacement_max'] ?? 0),
                            'min' => floatval($_POST['cbr_displacement_min'] ?? 0)
                        ]
                    ];
                }
                
                // Collect Grab Tensile Test data
                if ($selected['test_name'] === 'Grab Tensile Test') {
                    $test_data['grab_data'] = [];
                    $i = 1;
                    while (isset($_POST["grab_position_$i"])) {
                        $test_data['grab_data'][] = [
                            'position' => $_POST["grab_position_$i"] ?? '',
                            'direction' => $_POST["grab_direction_$i"] ?? '',
                            'breaking_force' => floatval($_POST["grab_breaking_force_$i"] ?? 0),
                            'elongation' => floatval($_POST["grab_elongation_$i"] ?? 0)
                        ];
                        $i++;
                    }
                    // Collect summary statistics
                    $test_data['summary'] = [
                        'md' => [
                            'force_avg' => floatval($_POST['grab_md_force_avg'] ?? 0),
                            'force_sd' => floatval($_POST['grab_md_force_sd'] ?? 0),
                            'force_cv' => floatval($_POST['grab_md_force_cv'] ?? 0),
                            'force_max' => floatval($_POST['grab_md_force_max'] ?? 0),
                            'force_min' => floatval($_POST['grab_md_force_min'] ?? 0),
                            'elongation_avg' => floatval($_POST['grab_md_elongation_avg'] ?? 0),
                            'elongation_sd' => floatval($_POST['grab_md_elongation_sd'] ?? 0),
                            'elongation_cv' => floatval($_POST['grab_md_elongation_cv'] ?? 0),
                            'elongation_max' => floatval($_POST['grab_md_elongation_max'] ?? 0),
                            'elongation_min' => floatval($_POST['grab_md_elongation_min'] ?? 0)
                        ],
                        'cd' => [
                            'force_avg' => floatval($_POST['grab_cd_force_avg'] ?? 0),
                            'force_sd' => floatval($_POST['grab_cd_force_sd'] ?? 0),
                            'force_cv' => floatval($_POST['grab_cd_force_cv'] ?? 0),
                            'force_max' => floatval($_POST['grab_cd_force_max'] ?? 0),
                            'force_min' => floatval($_POST['grab_cd_force_min'] ?? 0),
                            'elongation_avg' => floatval($_POST['grab_cd_elongation_avg'] ?? 0),
                            'elongation_sd' => floatval($_POST['grab_cd_elongation_sd'] ?? 0),
                            'elongation_cv' => floatval($_POST['grab_cd_elongation_cv'] ?? 0),
                            'elongation_max' => floatval($_POST['grab_cd_elongation_max'] ?? 0),
                            'elongation_min' => floatval($_POST['grab_cd_elongation_min'] ?? 0)
                        ]
                    ];
                }
                
                // Collect Fineness of Fiber test data
                if ($selected['test_name'] === 'Fineness of Fiber') {
                    $test_data['fiber_table_data'] = [];
                    
                    // Handle specific field names from the form
                    if (array_key_exists('unit_weight_result', $_POST) || array_key_exists('unit_weight_remarks', $_POST)) {
                        $test_data['fiber_table_data'][] = [
                            'sl_no' => '1',
                            'parameter' => 'Unit Weight',
                            'standard' => 'EN ISO 1973',
                            'unit' => 'dTex',
                            'result' => $_POST['unit_weight_result'] ?? '',
                            'remarks' => $_POST['unit_weight_remarks'] ?? ''
                        ];
                    }
                    
                    // Also handle indexed fields for backward compatibility
                    $i = 1;
                    while (isset($_POST["fiber_sl_no_$i"])) {
                        $test_data['fiber_table_data'][] = [
                            'sl_no' => $_POST["fiber_sl_no_$i"] ?? '',
                            'parameter' => $_POST["fiber_parameter_$i"] ?? '',
                            'standard' => $_POST["fiber_standard_$i"] ?? '',
                            'unit' => $_POST["fiber_unit_$i"] ?? '',
                            'result' => $_POST["fiber_result_$i"] ?? '',
                            'remarks' => $_POST["fiber_remarks_$i"] ?? ''
                        ];
                        $i++;
                    }
                    if (isset($_POST['fiber_sample_name'])) {
                        $test_data['fiber_sample_name'] = $_POST['fiber_sample_name'];
                    }
                    if (isset($_POST['fiber_sample_received_date'])) {
                        $test_data['fiber_sample_received_date'] = $_POST['fiber_sample_received_date'];
                    }
                    if (isset($_POST['fiber_sample_tested'])) {
                        $test_data['fiber_sample_tested'] = $_POST['fiber_sample_tested'];
                    }
                }
                
                // Collect Tenacity of Yarn test data
                if ($selected['test_name'] === 'Tenacity of Yarn') {
                    $test_data['yarn_table_data'] = [];
                    
                    // Fixed rows with specific field names (not dynamic numbering)
                    if (isset($_POST['yarn_tenacity_at_break'])) {
                        $test_data['yarn_table_data'][] = [
                            'sl_no' => '1',
                            'parameter' => 'Tenacity at Break',
                            'standard' => 'ASTM D2256',
                            'unit' => 'cN/dTex',
                            'result' => $_POST['yarn_tenacity_at_break'] ?? '',
                            'remarks' => $_POST['yarn_tenacity_at_break_remarks'] ?? ''
                        ];
                    }
                    if (isset($_POST['yarn_std_deviation'])) {
                        $test_data['yarn_table_data'][] = [
                            'sl_no' => '2',
                            'parameter' => 'Std Deviation',
                            'standard' => 'ASTM D2256',
                            'unit' => 'cN/dTex',
                            'result' => $_POST['yarn_std_deviation'] ?? '',
                            'remarks' => $_POST['yarn_std_deviation_remarks'] ?? ''
                        ];
                    }
                    if (isset($_POST['yarn_cv_percent'])) {
                        $test_data['yarn_table_data'][] = [
                            'sl_no' => '3',
                            'parameter' => 'CV%',
                            'standard' => 'ASTM D2256',
                            'unit' => '%',
                            'result' => $_POST['yarn_cv_percent'] ?? '',
                            'remarks' => $_POST['yarn_cv_percent_remarks'] ?? ''
                        ];
                    }
                    if (isset($_POST['yarn_elongation_at_break'])) {
                        $test_data['yarn_table_data'][] = [
                            'sl_no' => '4',
                            'parameter' => 'Elongation at Break',
                            'standard' => 'ASTM D2256',
                            'unit' => '%',
                            'result' => $_POST['yarn_elongation_at_break'] ?? '',
                            'remarks' => $_POST['yarn_elongation_at_break_remarks'] ?? ''
                        ];
                    }
                    if (isset($_POST['yarn_sample_description'])) {
                        $test_data['yarn_sample_description'] = $_POST['yarn_sample_description'];
                    }
                    if (isset($_POST['yarn_sample_received_from'])) {
                        $test_data['yarn_sample_received_from'] = $_POST['yarn_sample_received_from'];
                    }
                    if (isset($_POST['yarn_sample_collected_from'])) {
                        $test_data['yarn_sample_collected_from'] = $_POST['yarn_sample_collected_from'];
                    }
                    if (isset($_POST['yarn_received_date'])) {
                        $test_data['yarn_received_date'] = $_POST['yarn_received_date'];
                    }
                    if (isset($_POST['yarn_test_start_date'])) {
                        $test_data['yarn_test_start_date'] = $_POST['yarn_test_start_date'];
                    }
                    if (isset($_POST['yarn_test_end_date'])) {
                        $test_data['yarn_test_end_date'] = $_POST['yarn_test_end_date'];
                    }
                    if (isset($_POST['yarn_test_temperature'])) {
                        $test_data['yarn_test_temperature'] = $_POST['yarn_test_temperature'];
                    }
                    if (isset($_POST['yarn_rh'])) {
                        $test_data['yarn_rh'] = $_POST['yarn_rh'];
                    }
                    if (isset($_POST['yarn_others_info'])) {
                        $test_data['yarn_others_info'] = $_POST['yarn_others_info'];
                    }
                }
                
                // Collect Tenacity of Fiber test data
                if ($selected['test_name'] === 'Tenacity of Fiber') {
                    $test_data['tenacity_table_data'] = [];
                    
                    // Handle specific field names from the form - always add rows if fields exist in POST
                    if (array_key_exists('tenacity_at_break', $_POST)) {
                        $test_data['tenacity_table_data'][] = [
                            'sl_no' => '1',
                            'parameter' => 'Tenacity at Break',
                            'standard' => 'EN ISO 5079',
                            'unit' => 'cN/dTex',
                            'result' => $_POST['tenacity_at_break'] ?? '',
                            'remarks' => $_POST['tenacity_at_break_remarks'] ?? ''
                        ];
                    }
                    if (array_key_exists('std_deviation', $_POST)) {
                        $test_data['tenacity_table_data'][] = [
                            'sl_no' => '2',
                            'parameter' => 'Std Deviation',
                            'standard' => 'EN ISO 5079',
                            'unit' => 'cN/dTex',
                            'result' => $_POST['std_deviation'] ?? '',
                            'remarks' => $_POST['std_deviation_remarks'] ?? ''
                        ];
                    }
                    if (array_key_exists('cv_percent', $_POST)) {
                        $test_data['tenacity_table_data'][] = [
                            'sl_no' => '3',
                            'parameter' => 'CV%',
                            'standard' => 'EN ISO 5079',
                            'unit' => '%',
                            'result' => $_POST['cv_percent'] ?? '',
                            'remarks' => $_POST['cv_percent_remarks'] ?? ''
                        ];
                    }
                    if (array_key_exists('elongation_at_break', $_POST)) {
                        $test_data['tenacity_table_data'][] = [
                            'sl_no' => '4',
                            'parameter' => 'Elongation at Break',
                            'standard' => 'EN ISO 5079',
                            'unit' => '%',
                            'result' => $_POST['elongation_at_break'] ?? '',
                            'remarks' => $_POST['elongation_at_break_remarks'] ?? ''
                        ];
                    }
                    if (array_key_exists('cross_section_remarks', $_POST)) {
                        $test_data['tenacity_table_data'][] = [
                            'sl_no' => '5',
                            'parameter' => 'Cross Section',
                            'standard' => '-',
                            'unit' => '-',
                            'result' => 'Round',
                            'remarks' => $_POST['cross_section_remarks'] ?? ''
                        ];
                    }
                    
                    // Also handle indexed fields for backward compatibility
                    $i = 1;
                    while (isset($_POST["tenacity_sl_no_$i"])) {
                        $test_data['tenacity_table_data'][] = [
                            'sl_no' => $_POST["tenacity_sl_no_$i"] ?? '',
                            'parameter' => $_POST["tenacity_parameter_$i"] ?? '',
                            'standard' => $_POST["tenacity_standard_$i"] ?? '',
                            'unit' => $_POST["tenacity_unit_$i"] ?? '',
                            'result' => $_POST["tenacity_result_$i"] ?? '',
                            'remarks' => $_POST["tenacity_remarks_$i"] ?? ''
                        ];
                        $i++;
                    }
                    $test_data['acceptance_criteria'] = true;
                    if (isset($_POST['fiber_sample_name'])) {
                        $test_data['fiber_sample_name'] = $_POST['fiber_sample_name'];
                    }
                    if (isset($_POST['fiber_sample_received_date'])) {
                        $test_data['fiber_sample_received_date'] = $_POST['fiber_sample_received_date'];
                    }
                    if (isset($_POST['fiber_sample_tested'])) {
                        $test_data['fiber_sample_tested'] = $_POST['fiber_sample_tested'];
                    }
                }
                
                // Collect Cut Length of Fiber test data
                if ($selected['test_name'] === 'Cut Length of Fiber') {
                    $test_data['cut_length_table_data'] = [];
                    
                    // Fixed single row with specific field names
                    if (isset($_POST['cut_length_result'])) {
                        $test_data['cut_length_table_data'][] = [
                            'sl_no' => '1',
                            'parameter' => 'Cut Length',
                            'standard' => $selected['method'], // Use the selected method (ASTM D5103, etc.)
                            'unit' => 'mm',
                            'result' => $_POST['cut_length_result'] ?? '',
                            'remarks' => $_POST['cut_length_remarks'] ?? ''
                        ];
                    }
                    if (isset($_POST['fiber_sample_name'])) {
                        $test_data['fiber_sample_name'] = $_POST['fiber_sample_name'];
                    }
                    if (isset($_POST['fiber_sample_received_date'])) {
                        $test_data['fiber_sample_received_date'] = $_POST['fiber_sample_received_date'];
                    }
                    if (isset($_POST['fiber_sample_tested'])) {
                        $test_data['fiber_sample_tested'] = $_POST['fiber_sample_tested'];
                    }
                }
                
            $test_data_json = json_encode($test_data);
            
            // Use user's reference if available, otherwise use generated reference
            $final_reference = !empty($user_reference) ? $user_reference : $generated_sample_ref;
            
            // Generate report number for this test order
            $report_number = generateReportNumber();
            
            // Determine status based on test type and user role
            global $tests_requiring_checker;
            
            // Check if this is an edit/resubmission
            if ($is_editing && $edit_id_post > 0) {
                // UPDATING existing report (resubmission after rejection)
                // Reset to pending_checker or pending_approval based on test type
                $status = in_array($selected['test_name'], $tests_requiring_checker) ? 'pending_checker' : 'pending_approval';
                
                // Clear rejection fields and reset workflow
                $stmt = $conn->prepare("UPDATE qc_tests 
                    SET sample_reference_id = ?, test_standard_id = ?, chosen_method = ?, test_data = ?, 
                        status = ?, checked_by = NULL, checked_at = NULL, checker_remarks = NULL, 
                        approved_by = NULL, approved_at = NULL, admin_remarks = NULL, updated_at = NOW()
                    WHERE id = ? AND inspector_id = ?");
                $stmt->bind_param("sisssii", $final_reference, $test_standard_id, $selected['method'], $test_data_json, $status, $edit_id_post, $reporter_id);
            } else {
                // NEW submission
                // If admin/AGM Ops submits, auto-approve (they are final approvers)
                if ($is_admin) {
                    $status = 'approved';
                    $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
                    $approved_at = date('Y-m-d H:i:s');
                    
                    // Insert with approved status
                    $stmt = $conn->prepare("INSERT INTO qc_tests (sample_reference_id, report_number, test_standard_id, chosen_method, test_data, inspector_id, inspector_name, status, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssississss", $final_reference, $report_number, $test_standard_id, $selected['method'], $test_data_json, $reporter_id, $reporter_name, $status, $approved_by, $approved_at);
                } else {
                    // For regular users: First 5 tests go to checker, others go directly to admin
                    $status = in_array($selected['test_name'], $tests_requiring_checker) ? 'pending_checker' : 'pending_approval';
                    
                    // Insert with pending status
                    $stmt = $conn->prepare("INSERT INTO qc_tests (sample_reference_id, report_number, test_standard_id, chosen_method, test_data, inspector_id, inspector_name, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssississ", $final_reference, $report_number, $test_standard_id, $selected['method'], $test_data_json, $reporter_id, $reporter_name, $status);
                }
            }
        
            if ($stmt->execute()) {
                $inserted_count++;
            } else {
                // Log insert error
                $debug_msg = "=== QC INSERT ERROR ===\n";
                $debug_msg .= "Error: " . $stmt->error . "\n";
                $debug_msg .= "Test: " . $selected['test_name'] . " - " . $selected['method'] . "\n";
                file_put_contents('qc_debug.txt', $debug_msg, FILE_APPEND);
                error_log("QC INSERT ERROR for " . $selected['test_name'] . ": " . $stmt->error);
            }
            $stmt->close();
        }
        
        $conn->commit();

        // Save general information from the last submission for auto-prefill (to database)
        $last_general = [];
        
        // Save top-level general fields (from main form)
        $top_fields = [
            'sample_details', 'batch_information', 'sample_collected_from', 
            'sample_received_datetime', 'sample_production_date', 
            'temperature', 'rh_percentage', 'test_period_from', 'test_period_to',
            'customer_reference', 'sample_received_from'
        ];
        foreach ($top_fields as $field) {
            if (isset($_POST[$field]) && $_POST[$field] !== '') {
                $last_general[$field] = $_POST[$field];
            }
        }
        
        // Save to database for persistence across sessions
        if (!empty($last_general)) {
            // Verify user exists (check both new_user and old users tables)
            $user_exists = false;
            
            // First try new_user table
            $check_user = $conn->prepare("SELECT id FROM new_user WHERE id = ?");
            $check_user->bind_param("i", $reporter_id);
            $check_user->execute();
            $user_exists = $check_user->get_result()->num_rows > 0;
            $check_user->close();
            
            // If not found, try old users table
            if (!$user_exists) {
                $check_old = $conn->prepare("SELECT id FROM users WHERE id = ?");
                $check_old->bind_param("i", $reporter_id);
                $check_old->execute();
                $user_exists = $check_old->get_result()->num_rows > 0;
                $check_old->close();
                
                if ($user_exists) {
                    error_log("QC Preferences: User {$reporter_id} found in old 'users' table, preferences will still be saved");
                }
            }
            
            if ($user_exists) {
                $saved_count = 0;
                foreach ($last_general as $field_name => $field_value) {
                    try {
                        // Remove the foreign key constraint temporarily or save without it
                        $save_stmt = $conn->prepare("INSERT INTO user_qc_preferences (user_id, field_name, field_value) 
                            VALUES (?, ?, ?) 
                            ON DUPLICATE KEY UPDATE field_value = ?, updated_at = NOW()");
                        $save_stmt->bind_param("isss", $reporter_id, $field_name, $field_value, $field_value);
                        if ($save_stmt->execute()) {
                            $saved_count++;
                        }
                        $save_stmt->close();
                    } catch (Exception $e) {
                        error_log("Failed to save QC preference '{$field_name}' for user {$reporter_id}: " . $e->getMessage());
                    }
                }
                error_log("QC Preferences: Saved {$saved_count}/" . count($last_general) . " preferences for user {$reporter_id}");
            } else {
                error_log("Cannot save QC preferences: User ID {$reporter_id} does not exist in new_user OR users table");
            }
        }
        
        // Also save to session as backup
        $_SESSION['qc_last_general'] = $last_general;
        error_log("Saved QC_LAST_GENERAL to database (" . count($last_general) . " fields)");
        
        // Get the report number from POST
        $report_number = $_POST['report_no'] ?? 'N/A';
        
        // Create a more descriptive message with test names and status
        $test_names = array_map(function($m) { return $m['test_name']; }, $selected_methods);
        $test_list = implode(', ', $test_names);
        
        // Determine status message based on user role, test type, and edit mode
        if ($is_editing) {
            $first_test = $selected_methods[0]['test_name'];
            $status_msg = in_array($first_test, $tests_requiring_checker) 
                ? "Pending Checker Approval" 
                : "Pending Admin Approval (Checker review not required)";
            $message = "Successfully resubmitted test: {$test_list} | Report No: {$report_number} | Status: {$status_msg}";
            
            // Clear edit session data
            unset($_SESSION['edit_qc_test_order_id']);
            unset($_SESSION['edit_qc_test_order_data']);
            unset($_SESSION['edit_qc_test_order_test_data']);
        } else {
            if ($is_admin) {
                $status_msg = "Auto-Approved (Admin/AGM Ops submission)";
            } else {
                $first_test = $selected_methods[0]['test_name'];
                $status_msg = in_array($first_test, $tests_requiring_checker) 
                    ? "Pending Checker Approval" 
                    : "Pending Admin Approval (Checker review not required)";
            }
            
            $message = "Successfully submitted {$inserted_count} test(s): {$test_list} | Report No: {$report_number} | Status: {$status_msg}";
            
            // Redirect to reload page with fresh data (auto-refresh preferences)
            $_SESSION['qc_success_message'] = $message;
            header("Location: qc_test_order.php?submitted=1");
            exit();
        }
        
    } catch (Exception $e) {
            $conn->rollback();
        $error = "Error: " . $e->getMessage();
    }
}

// Handle checker approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checker_action']) && $is_checker) {
    try {
        $action = $_POST['checker_action'];
        $report_number = trim($_POST['checker_report_number']);
        $comment = trim($_POST['checker_comment'] ?? '');
        
        // Handle rejection reasons checkboxes
        if ($action === 'rejected' && isset($_POST['rejection_reasons']) && is_array($_POST['rejection_reasons'])) {
            $rejection_reasons = array_map('trim', $_POST['rejection_reasons']);
            $reasons_text = implode(', ', $rejection_reasons);
            $comment = "Rejection Reasons: " . $reasons_text . ($comment ? "\n\nAdditional Comments: " . $comment : '');
        }
        
        $status = ($action === 'approved') ? 'pending_approval' : 'rejected_by_checker';
        $checked_by = $_SESSION['full_name'] ?? $_SESSION['username'];
        $checked_at = date('Y-m-d H:i:s');
        
        $stmt = $conn->prepare("UPDATE qc_tests SET status = ?, checked_by = ?, checked_at = ?, checker_remarks = ?, updated_at = NOW() WHERE report_number = ?");
        $stmt->bind_param("sssss", $status, $checked_by, $checked_at, $comment, $report_number);
        
        if ($stmt->execute()) {
            $stmt->close();
            $message = "Report $report_number has been " . ($action === 'approved' ? 'forwarded to admin' : 'rejected') . " successfully!";
            // Redirect to refresh the page and update the pending list
            header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($message));
            exit();
        } else {
            $error_msg = $stmt->error;
            $stmt->close();
            throw new Exception("Failed to update report: " . $error_msg);
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle admin approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action']) && $is_admin) {
    try {
        $action = $_POST['admin_action'];
        $report_number = trim($_POST['admin_report_number']);
        $comment = trim($_POST['admin_comment'] ?? '');
        
        $status = ($action === 'approved') ? 'approved' : 'rejected_by_approver';
        $approved_by = $_SESSION['full_name'] ?? $_SESSION['username'];
        $approved_at = date('Y-m-d H:i:s');
        
        $stmt = $conn->prepare("UPDATE qc_tests SET status = ?, approved_by = ?, approved_at = ?, admin_remarks = ?, updated_at = NOW() WHERE report_number = ?");
        $stmt->bind_param("sssss", $status, $approved_by, $approved_at, $comment, $report_number);
        
        if ($stmt->execute()) {
            $stmt->close();
            $message = "Report $report_number has been " . ($status === 'approved' ? 'approved' : 'rejected') . " successfully!";
            // Redirect to refresh the page and update the pending list
            header("Location: " . $_SERVER['PHP_SELF'] . "?msg=" . urlencode($message));
            exit();
        } else {
            $error_msg = $stmt->error;
            $stmt->close();
            throw new Exception("Failed to update report: " . $error_msg);
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get pending reports for checker (only first 5 tests, exclude admin submissions)
$pending_for_checker = [];
if ($is_checker) {
    // Get test_standard_ids for first 5 tests
    $first_five_test_names = [
        'Thickness (Under 2kPa Pressure)',
        'Mass Per Unit Area (GSM)',
        'Strip Tensile Test',
        'CBR Puncture Resistance',
        'Grab Tensile Test'
    ];
    
    // Get admin/AGM Ops user IDs to exclude their submissions
    $admin_roles = ['admin', 'agm ops', 'agm operations'];
    $admin_ids_query = $conn->query("SELECT id FROM new_user WHERE LOWER(TRIM(role)) IN ('admin', 'agm ops', 'agm operations')");
    $admin_ids = [];
    if ($admin_ids_query) {
        while ($row = $admin_ids_query->fetch_assoc()) {
            $admin_ids[] = $row['id'];
        }
    }
    
    $stmt = $conn->query("
        SELECT qto.*, ts.test_name 
        FROM qc_tests qto
        LEFT JOIN test_standards ts ON qto.test_standard_id = ts.id
        WHERE qto.status = 'pending_checker' 
        ORDER BY qto.updated_at DESC 
        LIMIT 50
    ");
    if ($stmt) {
        while ($row = $stmt->fetch_assoc()) {
            // Only include if:
            // 1. It's one of the first 5 tests
            // 2. NOT submitted by admin/AGM Ops (should already be auto-approved)
            if (in_array($row['test_name'], $first_five_test_names) && 
                !in_array($row['inspector_id'], $admin_ids)) {
                $pending_for_checker[] = $row;
            }
        }
    }
}

// Get pending reports for admin approval
$pending_for_admin = [];
if ($is_admin) {
    $stmt = $conn->query("SELECT * FROM qc_tests WHERE status = 'pending_approval' ORDER BY updated_at DESC LIMIT 50");
    if ($stmt) {
        while ($row = $stmt->fetch_assoc()) {
            $pending_for_admin[] = $row;
        }
    }
}

// Get rejected reports for tester
$rejected_reports = [];
if ($is_tester) {
    $stmt = $conn->prepare("SELECT * FROM qc_tests WHERE inspector_id = ? AND (status = 'rejected_by_checker' OR status = 'rejected_by_approver') ORDER BY updated_at DESC LIMIT 20");
    $stmt->bind_param("i", $reporter_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rejected_reports[] = $row;
    }
    $stmt->close();
}

// Function to generate sample reference ID with proper sequence
function generateSampleReferenceId() {
    global $conn;
    
    $now = new DateTime();
    $hour = (int)$now->format('H');
    
    // Determine shift (8AM to 7:59AM next day is day shift)
    $isDayShift = $hour >= 8;
    
    // Get shift date
    $shiftDate = clone $now;
    if (!$isDayShift && $hour < 8) {
        // Night shift - if before 8AM, it's still previous day's night shift
        $shiftDate->modify('-1 day');
    }
    
    $dateStr = $shiftDate->format('Ymd');
    
    // Get next sequence number for this date
    $query = "SELECT MAX(CAST(SUBSTRING(sample_reference_id, -3) AS UNSIGNED)) as max_seq 
              FROM qc_tests 
              WHERE sample_reference_id LIKE ?";
    $stmt = $conn->prepare($query);
    $pattern = "GEOCIL-LAB-TR-{$dateStr}%";
    $stmt->bind_param("s", $pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $nextSeq = ($row['max_seq'] ?? 0) + 1;
    $stmt->close();
    
    return "GEOCIL-LAB-TR-{$dateStr}" . str_pad($nextSeq, 3, '0', STR_PAD_LEFT);
}

// Function to generate report number starting from 1 each shift-day (8:00 AM to next day 7:59 AM)
function generateReportNumber() {
    global $conn;

    $now = new DateTime();
    $hour = (int)$now->format('H');

    // Determine shift-day window (from 8:00 of shift date to next day 07:59:59)
    $shiftDate = clone $now;
    if ($hour < 8) {
        // Before 8 AM belongs to previous shift-day
        $shiftDate->modify('-1 day');
    }

    // Start at 08:00:00 of shiftDate
    $start = (clone $shiftDate)->setTime(8, 0, 0);
    // End just before 08:00:00 next day
    $end = (clone $start)->modify('+1 day');

    // Count existing orders in this window to derive next sequence
    $count = 0;
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM qc_tests WHERE created_at >= ? AND created_at < ?");
    if ($stmt) {
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr = $end->format('Y-m-d H:i:s');
        $stmt->bind_param('ss', $startStr, $endStr);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            $row = $res->fetch_assoc();
            $count = (int)($row['c'] ?? 0);
        }
        $stmt->close();
    }

    $seq = $count + 1;
    $dateStr = $shiftDate->format('Ymd');
    return 'QCT-' . $dateStr . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

// Function to generate external reference number for outside/vendor samples
function generateExternalReference() {
    global $conn;
    
    $today = date('Ymd');
    $pattern = "EXT-{$today}-%";
    
    // Count existing external references for today
    $count = 0;
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c 
        FROM qc_tests 
        WHERE sample_reference_id LIKE ? 
        AND DATE(created_at) = CURDATE()
    ");
    
    if ($stmt) {
        $stmt->bind_param('s', $pattern);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            $row = $res->fetch_assoc();
            $count = (int)($row['c'] ?? 0);
        }
        $stmt->close();
    }
    
    $seq = $count + 1;
    return 'EXT-' . $today . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QC Test</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
  body { font-family:'Inter',sans-serif; background:#f4f6f9; margin:0; padding:0; color:#2c3e50; }
  .container { max-width:100%; margin:0; background:#fff; border-radius:0; padding:35px; box-shadow:none;} 
  h1 { text-align:center; font-size:28px; margin-bottom:20px; margin-top:0; }
  .form-group { margin-bottom:10px; }
  label { font-weight:600; display:block; margin-bottom:5px; }
  input[type="text"], input[type="number"], select { padding:10px; border:1px solid #ccc; border-radius:6px; width:calc(100% - 22px); }
  .summary-info { font-size:16px; font-weight:bold; padding:10px; border-radius:8px; text-align:center; margin-bottom:10px; background:#f0f0f0; }
  .actions { margin-top:15px; text-align:center; }
  .actions button { padding:10px 20px; font-size:15px; border:none; border-radius:6px; cursor:pointer; margin:0 10px;}
  .submit-btn { background:#2ecc71; color:#fff; }
  .clear-btn { background:#e74c3c; color:#fff; }
  .readonly { background:#ecf0f1; }
  .btn-group { display:flex; flex-wrap:wrap; gap:10px; }
  .btn { padding:10px 16px; font-size:14px; border:none; border-radius:6px; cursor:pointer; background-color:#f8f9fa; }
  .btn:hover { background-color:#ccc; }
  .btn.selected { background-color:#3498db; color:white; }
  .test-section { border:1px solid #eee; border-radius:8px; padding:10px; margin-bottom:10px; }
  .test-item { margin-bottom:5px; padding:8px; border:1px solid #ddd; border-radius:6px; }
  .test-item:hover { background-color:#f8f9fa; }
  .test-item.selected { background-color:#e8f5e9; border-color:#4caf50; }
  .product-header { background:#f8f9fa; padding:8px; border-radius:6px; margin-bottom:10px; font-weight:bold; }
  .alert { padding:10px; border-radius:6px; margin-bottom:10px; }
  .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
  .alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
  .form-row { display:flex; gap:40px; margin-bottom:40px; }
  .form-group { margin-bottom:25px; }
  .form-group label { font-weight:600; display:block; margin-bottom:12px; font-size:14px; }
  .form-group input, .form-group textarea, .form-group select {
    width:100%;
    padding:16px;
    border:1px solid #ccc;
    border-radius:6px;
    font-size:14px;
  }
    </style>
</head>
<body>
<div class="container">
  
  <?php if (!$is_checker || $is_admin): ?>
  <h1>QC Test</h1>
  <?php endif; ?>

                <?php if ($message): ?>
    <div class="alert alert-success">
      ? <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
    <div class="alert alert-error">
      ? <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

  <!-- Checker Dashboard Section -->
  <?php if ($is_checker && !empty($pending_for_checker)): ?>
  <div style="margin-bottom:15px;">
    <!-- Back Button -->
    <div style="margin-bottom:10px;">
      <a href="../index.php" style="display:inline-block; padding:10px 20px; background:#6c757d; color:#fff; text-decoration:none; border-radius:6px; font-size:14px; transition:background 0.3s;">
        <i class="fas fa-arrow-left"></i> ? Back to Dashboard
      </a>
    </div>
    
    <!-- Pending for Checking Section -->
    <div style="background:#fff; padding:10px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1);">
      <div style="border-bottom:2px solid #ff9800; padding-bottom:15px; margin-bottom:20px;">
        <h2 style="color:#333; margin:0; font-size:24px; font-weight:600;">Pending for Checking</h2>
        <p style="color:#666; margin:5px 0 0 0; font-size:14px;">First 5 tests only: Thickness, GSM, Strip Tensile, CBR, Grab Tensile (<?php echo count($pending_for_checker); ?> pending)</p>
      </div>
      
      <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; min-width:900px;">
          <thead>
            <tr style="background:#f8f9fa; border-bottom:2px solid #dee2e6;">
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Report No</th>
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Sample</th>
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Customer Ref</th>
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Tested By</th>
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Submitted At</th>
              <th style="padding:12px 15px; text-align:center; font-weight:600; color:#495057; font-size:14px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pending_for_checker as $report): 
              $test_data = json_decode($report['test_data'], true);
              // Get sample reference (could be from different fields)
              $sample_ref = $report['sample_reference_id'] ?? 'N/A';
              // Get customer reference from test_data
              $customer_ref = $test_data['customer_reference'] ?? $test_data['product_reference'] ?? 'N/A';
            ?>
            <tr style="border-bottom:1px solid #dee2e6; transition:background 0.2s;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='#fff'">
              <td style="padding:12px 15px; font-weight:500; color:#333;">
                <?php echo htmlspecialchars($report['report_number']); ?>
                <br>
                <span style="font-size:12px; color:#6c757d;"><?php echo htmlspecialchars($report['test_name'] ?? 'N/A'); ?></span>
              </td>
              <td style="padding:12px 15px; color:#495057; font-size:14px;">
                <?php echo htmlspecialchars($sample_ref); ?>
              </td>
              <td style="padding:12px 15px; color:#495057; font-size:14px;">
                <?php echo htmlspecialchars($customer_ref); ?>
              </td>
              <td style="padding:12px 15px; color:#495057; font-size:14px;">
                <?php echo htmlspecialchars($report['inspector_name'] ?? 'N/A'); ?>
              </td>
              <td style="padding:12px 15px; color:#495057; font-size:14px;">
                <?php echo date('M d, Y - g:i A', strtotime($report['updated_at'])); ?>
              </td>
              <td style="padding:12px 15px; text-align:center; white-space:nowrap;">
                <a href="../admin/view_qc_test_order.php?report_number=<?php echo htmlspecialchars($report['report_number']); ?>" target="_blank" style="display:inline-block; padding:8px 16px; background:#17a2b8; color:#fff; text-decoration:none; border-radius:4px; font-size:13px; font-weight:500; margin-right:5px; transition:background 0.3s;" onmouseover="this.style.background='#138496'" onmouseout="this.style.background='#17a2b8'">
                  <i class="fas fa-eye"></i> View
                </a>
                <form method="POST" style="display:inline; margin-right:5px;" onsubmit="return confirmApproval()">
                  <input type="hidden" name="checker_report_number" value="<?php echo htmlspecialchars($report['report_number']); ?>">
                  <button type="submit" name="checker_action" value="approved" style="padding:8px 16px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:13px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#218838'" onmouseout="this.style.background='#28a745'">
                    <i class="fas fa-check"></i> Approve
                  </button>
                </form>
                <button type="button" onclick="openCheckerRejectModal('<?php echo htmlspecialchars($report['report_number']); ?>')" style="padding:8px 16px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:13px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#c82333'" onmouseout="this.style.background='#dc3545'">
                    <i class="fas fa-times"></i> Reject
                  </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Admin Dashboard Section -->
  <?php if ($is_admin && !empty($pending_for_admin)): ?>
  <div style="margin-bottom:30px;">
    <!-- Back Button -->
    <div style="margin-bottom:20px;">
      <a href="../index.php" style="display:inline-block; padding:10px 20px; background:#6c757d; color:#fff; text-decoration:none; border-radius:6px; font-size:14px; transition:background 0.3s;">
        <i class="fas fa-arrow-left"></i> ? Back to Dashboard
      </a>
    </div>
    
    <!-- Pending for Final Approval Section -->
    <div style="background:#fff; padding:25px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1);">
      <div style="border-bottom:2px solid #2196f3; padding-bottom:15px; margin-bottom:20px;">
        <h2 style="color:#333; margin:0; font-size:24px; font-weight:600;">Pending for Final Approval</h2>
        <p style="color:#666; margin:5px 0 0 0; font-size:14px;">QC Tests requiring admin approval (<?php echo count($pending_for_admin); ?> pending)</p>
      </div>
      
      <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; min-width:900px;">
          <thead>
            <tr style="background:#f8f9fa; border-bottom:2px solid #dee2e6;">
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Report No</th>
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Sample</th>
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Customer Ref</th>
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Tested By</th>
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Checked By</th>
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Submitted At</th>
              <th style="padding:12px 15px; text-align:center; font-weight:600; color:#495057; font-size:14px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pending_for_admin as $report): 
              $test_data = json_decode($report['test_data'], true);
              $sample_ref = $report['sample_reference_id'] ?? 'N/A';
              $customer_ref = $test_data['customer_reference'] ?? $test_data['product_reference'] ?? 'N/A';
            ?>
            <tr style="border-bottom:1px solid #dee2e6; transition:background 0.2s;" onmouseover="this.style.background='#f8f9fa'" onmouseout="this.style.background='#fff'">
              <td style="padding:12px 15px; font-weight:500; color:#333;">
                <?php echo htmlspecialchars($report['report_number']); ?>
                <br>
                <span style="font-size:12px; color:#6c757d;"><?php echo htmlspecialchars($report['chosen_method'] ?? 'N/A'); ?></span>
              </td>
              <td style="padding:12px 15px; color:#495057; font-size:14px;">
                <?php echo htmlspecialchars($sample_ref); ?>
              </td>
              <td style="padding:12px 15px; color:#495057; font-size:14px;">
                <?php echo htmlspecialchars($customer_ref); ?>
              </td>
              <td style="padding:12px 15px; color:#495057; font-size:14px;">
                <?php echo htmlspecialchars($report['inspector_name'] ?? 'N/A'); ?>
              </td>
              <td style="padding:12px 15px; color:#495057; font-size:14px;">
                <?php echo htmlspecialchars($report['checked_by'] ?? 'Not checked'); ?>
              </td>
              <td style="padding:12px 15px; color:#495057; font-size:14px;">
                <?php echo date('M d, Y - g:i A', strtotime($report['updated_at'])); ?>
              </td>
              <td style="padding:12px 15px; text-align:center; white-space:nowrap;">
                <a href="../admin/view_qc_test_order.php?report_number=<?php echo htmlspecialchars($report['report_number']); ?>" target="_blank" style="display:inline-block; padding:8px 16px; background:#17a2b8; color:#fff; text-decoration:none; border-radius:4px; font-size:13px; font-weight:500; margin-right:5px; transition:background 0.3s;" onmouseover="this.style.background='#138496'" onmouseout="this.style.background='#17a2b8'">
                  <i class="fas fa-eye"></i> View
                </a>
                <form method="POST" style="display:inline; margin-right:5px;" onsubmit="return confirmApproval()">
                  <input type="hidden" name="admin_report_number" value="<?php echo htmlspecialchars($report['report_number']); ?>">
                  <button type="submit" name="admin_action" value="approved" style="padding:8px 16px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:13px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#218838'" onmouseout="this.style.background='#28a745'">
                    <i class="fas fa-check"></i> Approve
                  </button>
                </form>
                <button type="button" onclick="openAdminRejectModal('<?php echo htmlspecialchars($report['report_number']); ?>')" style="padding:8px 16px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:13px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#c82333'" onmouseout="this.style.background='#dc3545'">
                    <i class="fas fa-times"></i> Reject
                  </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Tester Rejected Reports Section -->
  <?php if ($is_tester && !empty($rejected_reports) && !$edit_mode): ?>
  <div style="margin-bottom:30px;">
    <div style="background:#fff; padding:25px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); border-left:4px solid #dc3545;">
      <div style="border-bottom:2px solid #dc3545; padding-bottom:15px; margin-bottom:20px;">
        <h2 style="color:#333; margin:0; font-size:24px; font-weight:600;">Your Rejected QC Tests</h2>
        <p style="color:#666; margin:5px 0 0 0; font-size:14px;">Please review the rejection reasons and resubmit after making corrections (<?php echo count($rejected_reports); ?> rejected)</p>
      </div>
      
      <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; min-width:900px;">
          <thead>
            <tr style="background:#f8f9fa; border-bottom:2px solid #dee2e6;">
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Report No</th>
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Sample</th>
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Status</th>
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Rejected By</th>
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Rejection Reason</th>
              <th style="padding:12px 15px; text-align:left; font-weight:600; color:#495057; font-size:14px;">Rejected At</th>
              <th style="padding:12px 15px; text-align:center; font-weight:600; color:#495057; font-size:14px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rejected_reports as $report): 
              $test_data = json_decode($report['test_data'], true);
              $sample_ref = $report['sample_reference_id'] ?? 'N/A';
              $rejected_by = ($report['status'] === 'rejected_by_checker') ? $report['checked_by'] : $report['approved_by'];
              $rejection_reason = ($report['status'] === 'rejected_by_checker') ? $report['checker_remarks'] : $report['admin_remarks'];
            ?>
            <tr style="border-bottom:1px solid #dee2e6; transition:background 0.2s;" onmouseover="this.style.background='#fff5f5'" onmouseout="this.style.background='#fff'">
              <td style="padding:12px 15px; font-weight:500; color:#333;">
                <?php echo htmlspecialchars($report['report_number']); ?>
                <br>
                <span style="font-size:12px; color:#6c757d;"><?php echo htmlspecialchars($report['chosen_method'] ?? 'N/A'); ?></span>
              </td>
              <td style="padding:12px 15px; color:#495057; font-size:14px;">
                <?php echo htmlspecialchars($sample_ref); ?>
              </td>
              <td style="padding:12px 15px;">
                <span style="display:inline-block; background:#dc3545; color:#fff; padding:5px 10px; border-radius:4px; font-size:12px; font-weight:500;">
                  <?php echo ($report['status'] === 'rejected_by_checker') ? 'Rejected by Checker' : 'Rejected by Admin'; ?>
                </span>
              </td>
              <td style="padding:12px 15px; color:#495057; font-size:14px;">
                <?php echo htmlspecialchars($rejected_by ?? 'N/A'); ?>
              </td>
              <td style="padding:12px 15px; color:#495057; font-size:13px; max-width:300px;">
                <div style="background:#fff3cd; padding:10px; border-radius:4px; border-left:3px solid #ffc107;">
                  <?php echo nl2br(htmlspecialchars($rejection_reason ?? 'No reason provided')); ?>
                </div>
              </td>
              <td style="padding:12px 15px; color:#495057; font-size:14px;">
                <?php echo date('M d, Y - g:i A', strtotime($report['updated_at'])); ?>
              </td>
              <td style="padding:12px 15px; text-align:center;">
                <a href="qc_test_order.php?edit=<?php echo $report['id']; ?>" style="display:inline-block; padding:8px 16px; background:#ff9800; color:#fff; text-decoration:none; border-radius:4px; font-size:13px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#e68900'" onmouseout="this.style.background='#ff9800'">
                  <i class="fas fa-edit"></i> Edit & Resubmit
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$is_checker || $is_admin): ?>
  <!-- Hide form from pure checkers, show only to testers and admin -->
  
  <!-- Back to Dashboard Link (for form users only) -->
    <div style="margin-bottom: 20px;">
      <a href="../index.php" class="clear-btn" style="text-decoration: none; display: inline-block;">
        ? Back to Dashboard
      </a>
                                </div>
  
  <?php if ($edit_mode): ?>
  <!-- Edit Mode Banner -->
  <div style="background:#fff3cd; border:2px solid #ffc107; border-radius:8px; padding:20px; margin-bottom:25px;">
    <h3 style="margin:0 0 10px 0; color:#856404;"><i class="fas fa-exclamation-triangle"></i> Editing Rejected Report</h3>
    <p style="margin:0; color:#333;">
      <strong>Report No:</strong> <?php echo htmlspecialchars($existing_report['report_number']); ?> |
      <strong>Status:</strong> <?php echo ($existing_report['status'] === 'rejected_by_checker') ? 'Rejected by Checker' : 'Rejected by Admin'; ?>
    </p>
    <p style="margin:10px 0 0 0; color:#666; font-size:14px;">
      <strong>Rejection Reason:</strong> 
      <?php echo nl2br(htmlspecialchars(($existing_report['status'] === 'rejected_by_checker') ? $existing_report['checker_remarks'] : $existing_report['admin_remarks'])); ?>
    </p>
    <p style="margin:10px 0 0 0; color:#856404; font-size:13px;">
      <i class="fas fa-info-circle"></i> Make your corrections below and click Submit to resubmit for approval.
    </p>
  </div>
  <?php endif; ?>
  
                <form method="POST" action="" id="qc_test_form" onsubmit="return validateQCFormBeforeSubmit(event)">
    
    <!-- Hidden edit ID for resubmission -->
    <?php if ($edit_mode): ?>
    <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">
    
    <!-- Edit Mode Banner -->
    <div style="background:#ff9800; color:#fff; padding:15px 20px; margin-bottom:25px; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.1);">
      <h3 style="margin:0 0 8px 0; font-size:18px; display:flex; align-items:center; gap:10px;">
        <i class="fas fa-edit"></i> Editing Rejected Report
      </h3>
      <p style="margin:0; font-size:14px; opacity:0.95;">
        <strong>Report No:</strong> <?php echo htmlspecialchars($existing_report['report_number']); ?> | 
        <strong>Status:</strong> <?php echo ucfirst(str_replace('_', ' ', $existing_report['status'])); ?>
        <?php if (!empty($existing_report['checker_remarks'])): ?>
        <br><strong>Checker Remarks:</strong> <?php echo htmlspecialchars($existing_report['checker_remarks']); ?>
        <?php endif; ?>
        <?php if (!empty($existing_report['admin_remarks'])): ?>
        <br><strong>Admin Remarks:</strong> <?php echo htmlspecialchars($existing_report['admin_remarks']); ?>
        <?php endif; ?>
      </p>
    </div>
    <?php endif; ?>

    <!-- Date/Time and Shift Display -->
    <div id="dateTimeDisplay" class="summary-info"></div>
    <div id="shiftBanner" class="summary-info"></div>

    <!-- Hidden datetime + shift -->
    <input type="hidden" id="dateTime" name="dateTime">
    <input type="hidden" id="shift" name="shift">

    <!-- Report Number (only) -->
    <div class="form-group">
      <label>Report No.:</label>
      <input type="text" id="report_no" name="report_no" readonly class="readonly" value="<?php echo htmlspecialchars($edit_mode ? $existing_report['report_number'] : generateReportNumber()); ?>">
                                    </div>

    <!-- Hidden Sample Reference ID (kept for backend, not shown) -->
    <input type="hidden" id="sample_reference_id" name="sample_reference_id" value="<?php echo isset($_POST['sample_reference_id']) ? htmlspecialchars($_POST['sample_reference_id']) : generateSampleReferenceId(); ?>">

    <!-- Inspector -->
    <div class="form-group">
      <label>Inspector:</label>
      <input type="text" value="<?php echo htmlspecialchars($reporter_name); ?>" readonly class="readonly">
      <input type="hidden" name="inspector_id" value="<?php echo (int)$reporter_id; ?>">
                                </div>

    <!-- Sample Details Section - for main tests -->
    <div id="general-info-section" style="background:#f8f9fa; padding:40px; border-radius:8px; margin-bottom:30px; border:1px solid #dee2e6;">
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Batch Information:</label>
          <input type="text" name="batch_information" id="qc_batch_info" placeholder="GT9.H1">
        </div>
        
        <div class="form-group" style="flex:1;">
          <label>Sample Details:</label>
          <input type="text" name="sample_details" id="sample_details">
        </div>
        
        <div class="form-group" style="flex:1;">
          <label>Sample Collected From:</label>
          <input type="text" name="sample_collected_from" id="sample_collected_from">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Sample Received Date & Time:</label>
          <input type="datetime-local" name="sample_received_datetime" id="sample_received_datetime">
        </div>
        
        <div class="form-group" style="flex:1;">
          <label>Sample Production Date:</label>
          <input type="date" name="sample_production_date" id="sample_production_date">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Temperature (°C):</label>
          <input type="number" name="temperature" id="temperature" step="0.1">
        </div>
        
        <div class="form-group" style="flex:1;">
          <label>RH (%):</label>
          <input type="number" name="rh_percentage" id="rh_percentage" step="0.1">
        </div>
        
        <div class="form-group" style="flex:1;">
          <label>Test Period From:</label>
          <input type="date" name="test_period_from" id="test_period_from" onchange="validateTestPeriod()">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Test Period To:</label>
          <input type="date" name="test_period_to" id="test_period_to" onchange="validateTestPeriod()">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Store Entry Reference:</label>
          <select name="store_entry_reference" id="store_entry_reference">
            <option value="">-- Select Store Entry --</option>
            <?php foreach($references as $ref): ?>
              <option value="<?php echo htmlspecialchars($ref); ?>">
                <?php echo htmlspecialchars($ref); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="form-group" style="flex:1;">
          <label>Reference:</label>
          <input type="text" name="product_reference" id="product_reference" onblur="formatProductReference()" placeholder="e.g., 7.0L625OCT14-R43-GT0.9H0.1">
          <div id="product_ref_help" style="font-size:11px; color:#7f8c8d; margin-top:5px;">
            Format: GSM + L + Line# + YY + MMMDD + -R + Roll# + - + Batch
          </div>
        </div>
      </div>
      
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Customer Reference:</label>
          <input type="text" name="customer_reference" id="customer_reference">
        </div>
        
        <div class="form-group" style="flex:1;">
          <label>Sample Received From:</label>
          <input type="text" name="sample_received_from" id="sample_received_from">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Lighthouse Reference (Optional):</label>
          <input type="text" name="lighthouse_reference">
        </div>
      </div>

      <div class="form-row" id="other-info-row">
        <div class="form-group" style="flex:100%;">
          <label>Other Information (Optional):</label>
          <textarea name="other_information" rows="2" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;"></textarea>
        </div>
      </div>
    </div>

    <!-- Simplified General Info Section - for fiber/yarn tests -->
    <div id="fiber-info-section" style="background:#f8f9fa; padding:25px; border-radius:8px; margin-bottom:30px; border:1px solid #dee2e6; display:none;">
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Sample Name:</label>
          <input type="text" name="fiber_sample_name">
        </div>
        
        <div class="form-group" style="flex:1;">
          <label>Reference No: <span style="color: red;">*</span></label>
          <select name="fiber_reference_no" id="fiber_reference_no" onchange="loadFiberReferenceData(this.value)">
            <option value="">-- Select Reference --</option>
            <?php foreach($references as $ref): ?>
              <option value="<?php echo htmlspecialchars($ref); ?>">
                <?php echo htmlspecialchars($ref); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div id="fiber_reference_error" style="color:red; margin-top:5px; font-size:12px;"></div>
        </div>
      </div>
      
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Sample Received Date:</label>
          <input type="date" name="fiber_sample_received_date">
        </div>
        
        <div class="form-group" style="flex:1;">
          <label>Sample Tested:</label>
          <input type="date" name="fiber_sample_tested_date">
        </div>
      </div>
    </div>

    <!-- Tenacity of Yarn General Info Section -->
    <div id="yarn-info-section" style="background:#f8f9fa; padding:25px; border-radius:8px; margin-bottom:30px; border:1px solid #dee2e6; display:none;">
      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Sample Description: <span style="color: red;">*</span></label>
          <input type="text" name="yarn_sample_description">
        </div>
        
        <div class="form-group" style="flex:1;">
          <label>Sample Received From: <span style="color: red;">*</span></label>
          <input type="text" name="yarn_sample_received_from">
        </div>
        
        <div class="form-group" style="flex:1;">
          <label>Sample Collected From: <span style="color: red;">*</span></label>
          <input type="text" name="yarn_sample_collected_from">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Reference: <span style="color: red;">*</span></label>
          <select name="yarn_reference_no" id="yarn_reference_no" onchange="loadYarnReferenceData(this.value)">
            <option value="">-- Select Reference --</option>
            <?php foreach($references as $ref): ?>
              <option value="<?php echo htmlspecialchars($ref); ?>">
                <?php echo htmlspecialchars($ref); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div id="yarn_reference_error" style="color:red; margin-top:5px; font-size:12px;"></div>
        </div>
        
        <div class="form-group" style="flex:1;">
          <label>Received Date: <span style="color: red;">*</span></label>
          <input type="date" name="yarn_received_date">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Test Start Date: <span style="color: red;">*</span></label>
          <input type="date" name="yarn_test_start_date" id="yarn_test_start_date" onchange="validateYarnTestDates()">
        </div>
        
        <div class="form-group" style="flex:1;">
          <label>Test End Date: <span style="color: red;">*</span></label>
          <input type="date" name="yarn_test_end_date" id="yarn_test_end_date" onchange="validateYarnTestDates()">
          <div id="yarn_date_error" style="color:red; margin-top:5px; font-size:12px;"></div>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:1;">
          <label>Test Temperature (°C): <span style="color: red;">*</span></label>
          <input type="number" name="yarn_temperature" step="0.1">
        </div>
        
        <div class="form-group" style="flex:1;">
          <label>RH (%): <span style="color: red;">*</span></label>
          <input type="number" name="yarn_rh_percentage" step="0.1">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group" style="flex:100%;">
          <label>Others Information:</label>
          <textarea name="yarn_other_information" rows="2" placeholder="Enter others information" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;"></textarea>
        </div>
      </div>
    </div>

    <!-- Test Standards Selection -->
    <div class="form-group">
      <label>Available Test Standards:</label>
      <div style="margin-bottom: 15px;">
        <strong>Selected Methods: <span id="selected-count" style="color: #27ae60;">0</span></strong> | 
        <strong>Total Available: <span id="total-count" style="color: #3498db;"><?php echo array_sum(array_map('count', $test_methods)); ?></span></strong>
                            </div>
                                        
      <div class="test-section">
        <?php foreach ($test_methods as $test_name => $methods): ?>
          <div class="test-item" style="margin-bottom: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 6px;">
            <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap;">
              <div style="min-width: 250px; font-weight: bold;">
                <?php echo htmlspecialchars($test_name); ?>
                        </div>
              <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                <?php foreach ($methods as $method): ?>
                  <label style="display: flex; align-items: center; gap: 5px; cursor: pointer; background: #f8f9fa; padding: 8px 12px; border-radius: 4px; border: 1px solid #dee2e6;">
                    <input type="checkbox" 
                           name="test_<?php echo md5($test_name . '_' . $method); ?>"
                           id="test_<?php echo md5($test_name . '_' . $method); ?>"
                           data-test-name="<?php echo htmlspecialchars($test_name); ?>"
                           data-method="<?php echo htmlspecialchars($method); ?>"
                           class="test-checkbox"
                           style="transform: scale(1.2);"
                           onchange="handleTestSelection(this)">
                    <span style="font-size: 12px; color: #495057;"><?php echo htmlspecialchars($method); ?></span>
                                                    </label>
                <?php endforeach; ?>
                                </div>
                                </div>
            <!-- Test Parameters Section (initially hidden) -->
            <div id="params_<?php echo md5($test_name); ?>" class="test-parameters" style="display: none; margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
              <div id="params_content_<?php echo md5($test_name); ?>"></div>
                            </div>
          </div>
                                        <?php endforeach; ?>
                        </div>
                    </div>

    <div class="actions">
      <button type="submit" name="submit_order" class="submit-btn" id="submit_btn" onclick="return validateQCFormBeforeSubmit(event)">
         Submit
                        </button>
      <button type="button" class="clear-btn" onclick="clearForm()">Clear</button>
                        </div>
                </form>
  <?php endif; ?>
  <!-- End of form - hidden from checkers -->
  
  <?php if ($is_checker && !$is_admin && empty($pending_for_checker)): ?>
  <!-- Message for checker when no pending reports and form is hidden -->
  <div style="background:#e3f2fd; border:1px solid #2196f3; padding:30px; border-radius:8px; text-align:center; margin-top:20px;">
    <h3 style="color:#1976d2; margin-top:0;">? No QC Tests Pending for Checking</h3>
    <p style="color:#555; font-size:16px;">All first 5 tests (Thickness, GSM, Strip Tensile, CBR, Grab Tensile) have been checked.</p>
    <p style="color:#666; font-size:14px; margin-top:10px;">New test orders will appear here when testers submit them.</p>
    <div style="margin-top:20px;">
      <a href="../index.php" style="display:inline-block; padding:12px 24px; background:#2196f3; color:#fff; text-decoration:none; border-radius:6px; font-size:14px;">
        ? Back to Dashboard
      </a>
    </div>
  </div>
  <?php endif; ?>
                
                <script>
                // Sync all summary data to hidden inputs before submit
                function syncAllSummaryData() {
                    console.log('Syncing all summary data...');
                    // This function will be called before form submission to ensure all calculated values are saved
                    return true;
                }
                
                // Validate QC Form before submission
                function validateQCFormBeforeSubmit(event) {
                    console.log('?? Validating QC Form before submission...');
                    
                    // First sync all summary data
                    syncAllSummaryData();
                    
                    // Get form elements
                    const isExternalProductFlag = document.getElementById('is_external_product');
                    const productReference = document.getElementById('product_reference');
                    const externalReference = document.getElementById('external_reference');
                    
                    if (!isExternalProductFlag) {
                        console.error('? is_external_product flag not found!');
                        return true; // Let form submit if we can't validate
                    }
                    
                    const isExternalProduct = isExternalProductFlag.value === '1';
                    const productRefValue = productReference?.value || '';
                    const externalRefValue = externalReference?.value || '';
                    
                    console.log('?? Form validation state:', {
                        isExternalProduct: isExternalProduct,
                        productRefValue: productRefValue,
                        externalRefValue: externalRefValue,
                        productRefDisabled: productReference?.disabled,
                        externalRefDisplay: externalReference?.style.display
                    });
                    
                    // Validate reference based on product type
                    if (isExternalProduct) {
                        // External product - check external reference
                        if (!externalRefValue || externalRefValue.trim() === '') {
                            console.error('? External reference validation failed');
                            alert('? External Reference is Missing!\n\nThe reference has not been auto-generated yet.\n\nPlease ensure these fields are filled:\n• Batch Information\n• Roll Number\n• GSM\n\nWait 2-3 seconds for auto-generation, then try again.');
                            if (event) event.preventDefault();
                            return false;
                        }
                        console.log('? External reference validated:', externalRefValue);
                    } else {
                        // Production product - check product reference
                        if (!productRefValue || productRefValue.trim() === '') {
                            console.error('? Product reference validation failed');
                            alert('? Reference is Required!\n\nPlease select a reference from the dropdown list.');
                            if (event) event.preventDefault();
                            return false;
                        }
                        console.log('? Product reference validated:', productRefValue);
                    }
                    
                    // All validations passed
                    console.log('? Form validation passed - submitting form');
                    return true;
                }
                </script>
                                </div>

    <script>

// Load user preferences (from database + session)
        <?php 
        error_log("QC_LAST_GENERAL Data: " . json_encode($user_qc_prefs));
        ?>
        console.log('=== QC PREFERENCES DEBUG ===');
        console.log('User ID: <?php echo $reporter_id; ?>');
        console.log('User QC Preferences:', <?php echo json_encode($user_qc_prefs); ?>);
        console.log('Preference data count:', Object.keys(<?php echo json_encode($user_qc_prefs); ?>).length);
        window.QC_LAST_GENERAL = <?php echo json_encode($user_qc_prefs); ?>;
        console.log('window.QC_LAST_GENERAL set:', window.QC_LAST_GENERAL);
        console.log('=== END DEBUG ===');
        
        // Show debug info if no preferences loaded
        <?php if (empty($user_qc_prefs)): ?>
        console.warn('?? NO PREFERENCES LOADED - You may need to submit a QC Test first');
        console.warn('This will happen on your first submission or if the database table is missing');
        <?php endif; ?>
// Load fiber reference data when reference is selected
function loadFiberReferenceData(reference) {
    const errorDiv = document.getElementById('fiber_reference_error');
    if (errorDiv) {
        errorDiv.textContent = '';
        errorDiv.style.background = '';
        errorDiv.style.padding = '';
    }
    
    if (!reference || reference === '') {
        // Show all test checkboxes
        document.querySelectorAll('.test-checkbox').forEach(cb => {
            const label = cb.closest('label');
            if (label) label.style.display = '';
        });
        return;
    }
    
    fetch(`api/get_reference_data.php?reference=${encodeURIComponent(reference)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // You can populate other fiber-related fields here if needed from the reference
            } else {
                document.getElementById('fiber_reference_error').textContent = data.error;
            }
        })
        .catch(err => {
            console.error('Error loading reference data:', err);
            document.getElementById('fiber_reference_error').textContent = 'Error loading reference data';
        });
    
    // Fetch already submitted tests for this reference and hide them
    fetch(`api/get_submitted_tests.php?reference=${encodeURIComponent(reference)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.tests) {
                // Show all checkboxes first
                document.querySelectorAll('.test-checkbox').forEach(cb => {
                    const label = cb.closest('label');
                    if (label) label.style.display = '';
                });
                
                // Hide checkboxes for already submitted tests
                data.tests.forEach(test => {
                    const testName = test.test_name;
                    const method = test.method;
                    // Find and hide the checkbox
                    document.querySelectorAll('.test-checkbox').forEach(cb => {
                        if (cb.getAttribute('data-test-name') === testName && 
                            cb.getAttribute('data-method') === method) {
                            const label = cb.closest('label');
                            if (label) {
                                label.style.display = 'none';
                                // If this checkbox was selected, uncheck it and reset the form
                                if (cb.checked) {
                                    cb.checked = false;
                                    const paramsDiv = cb.closest('.test-item')?.querySelector('.test-parameters');
                                    if (paramsDiv) paramsDiv.style.display = 'none';
                                    // Show all test items again
                                    document.querySelectorAll('.test-item').forEach(item => {
                                        item.style.display = 'block';
                                    });
                                }
                            }
                        }
                    });
                });
            }
        })
        .catch(err => {
            console.error('Error loading submitted tests:', err);
        });
}

// Load QC reference data when reference is selected (disabled - manual input now)
function loadQCReferenceData(reference) {
    // Product reference is now a manual text input, no auto-loading needed
    return;
    
    // Fetch already submitted tests for this reference and hide them
    fetch(`api/get_submitted_tests.php?reference=${encodeURIComponent(reference)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.tests) {
                // Show all checkboxes first
                document.querySelectorAll('.test-checkbox').forEach(cb => {
                    const label = cb.closest('label');
                    if (label) label.style.display = '';
                });
                
                // Hide checkboxes for already submitted tests
                data.tests.forEach(test => {
                    const testName = test.test_name;
                    const method = test.method;
                    // Find and hide the checkbox
                    document.querySelectorAll('.test-checkbox').forEach(cb => {
                        if (cb.getAttribute('data-test-name') === testName && 
                            cb.getAttribute('data-method') === method) {
                            const label = cb.closest('label');
                            if (label) {
                                label.style.display = 'none';
                                // If this checkbox was selected, uncheck it and reset the form
                                if (cb.checked) {
                                    cb.checked = false;
                                    const paramsDiv = cb.closest('.test-item')?.querySelector('.test-parameters');
                                    if (paramsDiv) paramsDiv.style.display = 'none';
                                    // Show all test items again
                                    document.querySelectorAll('.test-item').forEach(item => {
                                        item.style.display = 'block';
                                    });
                                }
                            }
                        }
                    });
                });
            }
        })
        .catch(err => {
            console.error('Error loading submitted tests:', err);
        });
}

// Handle product type change (disabled - product type selection removed)
function handleProductTypeChange() {
    // Product type selection removed, no action needed
}

// Generate external reference when Batch Info, Roll Number, and GSM are filled (disabled)
function generateExternalReferenceIfReady() {
    // External product type removed, no action needed
}

// Update date/time and shift display
function updateDateTimeAndShift() {
    const now = new Date();
    const dateTimeStr = now.toLocaleString('en-US', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    });
    
    // Determine shift based on time (8AM to 7:59AM next day)
    const hour = now.getHours();
    let shift = '';
    if (hour >= 8) {
        shift = 'Day Shift';
    } else {
        shift = 'Night Shift';
    }
    
    document.getElementById('dateTimeDisplay').innerHTML = 
        ' Date & Time: ' + dateTimeStr;
    document.getElementById('shiftBanner').innerHTML = 
        ' Shift: ' + shift;
    
    // Update hidden fields
    document.getElementById('dateTime').value = dateTimeStr;
    document.getElementById('shift').value = shift;
    
    // Update sample reference ID
    updateSampleReferenceId();
}

// Generate sample reference ID based on shift
function updateSampleReferenceId() {
    const now = new Date();
    const hour = now.getHours();
    
    // Determine if it's day shift (8AM to 7:59AM next day)
    let isDayShift = hour >= 8;
    
    // Get current date for the shift period
    let shiftDate = new Date(now);
    if (!isDayShift && hour < 8) {
        // Night shift - if it's before 8AM, it's still the previous day's night shift
        shiftDate.setDate(shiftDate.getDate() - 1);
    }
    
    const dateStr = shiftDate.toISOString().split('T')[0].replace(/-/g, '');
    
    // For display purposes, show the format with sequence 001
    // The actual sequence will be generated by PHP on form submission
    const sampleRefId = `GEOCIL-LAB-TR-${dateStr}001`;
    
    // Update the input field
    const sampleRefInput = document.getElementById('sample_reference_id');
    sampleRefInput.value = sampleRefId;
}

        // Update selection count
        function updateSelectionCount() {
    const selected = document.querySelectorAll('input[type="checkbox"]:checked');
            document.getElementById('selected-count').textContent = selected.length;
            
    // Update item styling
            selected.forEach(checkbox => {
        const item = checkbox.closest('.test-item');
        if (item) {
            item.style.backgroundColor = '#e8f5e9';
            item.style.borderColor = '#4caf50';
        }
    });
    
    // Remove styling from unchecked items
    document.querySelectorAll('input[type="checkbox"]:not(:checked)').forEach(checkbox => {
        const item = checkbox.closest('.test-item');
        if (item) {
            item.style.backgroundColor = '';
            item.style.borderColor = '#ddd';
        }
            });
        }

        // GSM grouped table helpers (Mass Per Unit Area)
        const GSM_GROUP_OPTIONS = {
            "Left": ["Left-1", "Left-2", "Left-3", "Left-4"],
            "Middle Left": ["Middle Left-1", "Middle Left-2", "Middle Left-3", "Middle Left-4"],
            "Middle Right": ["Middle Right-1", "Middle Right-2", "Middle Right-3", "Middle Right-4"],
            "Right": ["Right-1", "Right-2", "Right-3", "Right-4"]
        };
        const gsmGroupedState = {};
        function initGsmGrouped(suffixId) {
            const body = document.getElementById(`gsmBody_${suffixId}`);
            if (!body) return;
            gsmGroupedState[suffixId] = { groups: {}, body };
            Object.keys(GSM_GROUP_OPTIONS).forEach(group => createGsmGroupSection(suffixId, group));
        }
        function createGsmGroupSection(suffixId, group) {
            const st = gsmGroupedState[suffixId];
            st.groups[group] = 0;
            gsmAddRow(suffixId, group);
            const btnRow = document.createElement('tr');
            const btnCell = document.createElement('td');
            btnCell.colSpan = 3;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn-add';
            btn.textContent = `Add 1 More Row (${group})`;
            // enforce green style
            btn.style.background = 'linear-gradient(90deg, #16a34a 0%, #22c55e 100%)';
            btn.style.color = '#ffffff';
            btn.style.border = 'none';
            btn.style.padding = '6px 10px';
            btn.style.borderRadius = '6px';
            btn.style.fontSize = '12px';
            btn.style.cursor = 'pointer';
            btn.addEventListener('click', () => gsmAddRow(suffixId, group));
            btnCell.appendChild(btn);
            btnRow.appendChild(btnCell);
            st.body.appendChild(btnRow);
        }
        function gsmFindButtonRow(suffixId, group) {
            const st = gsmGroupedState[suffixId];
            return Array.from(st.body.querySelectorAll('tr'))
                .find(r => r.textContent.includes(`Add 1 More Row (${group})`));
        }
        function gsmUpdateAverage(suffixId, group) {
            const st = gsmGroupedState[suffixId];
            const rows = Array.from(st.body.querySelectorAll(`tr[data-group="${group}"]`));
            // Calculate average from Calculated GSM (manual input) values
            const gsmValues = rows.map(r => parseFloat(r.querySelector('.calc-gsm')?.value) || 0).filter(v => v > 0);
            const avg = gsmValues.length ? (gsmValues.reduce((a, b) => a + b, 0) / gsmValues.length).toFixed(2) : '0.0';
            rows.forEach((r, i) => {
                const c = r.querySelector('.avg-cell');
                if (!c) return;
                c.innerHTML = (i === Math.floor(rows.length / 2)) ? `<span class=\"avg-badge\">${avg}</span>` : '';
            });
        }
        function gsmAddRow(suffixId, group) {
            const st = gsmGroupedState[suffixId];
            const count = st.groups[group] || 0;
            if (count >= 4) { alert(`Max 4 rows for ${group}`); return; }
            const label = GSM_GROUP_OPTIONS[group][count];
            
            // Calculate global row index for proper naming
            const allRows = Array.from(st.body.querySelectorAll('tr[data-group]'));
            const globalIndex = allRows.length + 1;
            
            const row = document.createElement('tr');
            row.setAttribute('data-group', group);
            const pos = document.createElement('td');
            const w = document.createElement('td');
            const calc = document.createElement('td');
            const avg = document.createElement('td');
            
            // build dropdown with all group options
            const select = document.createElement('select');
            select.name = `gsm_position_${globalIndex}`;
            select.id = `gsm_position_${globalIndex}`;
            GSM_GROUP_OPTIONS[group].forEach(opt => {
                const o = document.createElement('option');
                o.value = opt;
                o.textContent = opt;
                if (opt === label) o.selected = true;
                select.appendChild(o);
            });
            pos.appendChild(select);
            
            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'weight-input';
            input.name = `gsm_weight_${globalIndex}`;
            input.placeholder = 'Enter weight';
            input.min = '0';
            input.step = '0.01';
            
            // Calculated GSM - now a manual input field
            const calcInput = document.createElement('input');
            calcInput.type = 'number';
            calcInput.className = 'calc-gsm';
            calcInput.name = `gsm_calculated_${globalIndex}`;
            calcInput.placeholder = 'Enter GSM';
            calcInput.min = '0';
            calcInput.step = '0.01';
            calcInput.style.width = '100%';
            calcInput.style.padding = '10px 12px';
            calcInput.style.border = '1px solid #e5e7eb';
            calcInput.style.borderRadius = '8px';
            calc.classList.add('calc-cell');
            calc.appendChild(calcInput);
            
            input.addEventListener('input', () => { 
                gsmUpdateAverage(suffixId, group);
                if (typeof recalcGsmSummary === 'function') recalcGsmSummary(suffixId);
            });
            
            calcInput.addEventListener('input', () => { 
                gsmUpdateAverage(suffixId, group);
                if (typeof recalcGsmSummary === 'function') recalcGsmSummary(suffixId);
            });
            
            w.appendChild(input);
            avg.classList.add('avg-cell');
            row.appendChild(pos); row.appendChild(w); row.appendChild(calc); row.appendChild(avg);
            st.body.insertBefore(row, gsmFindButtonRow(suffixId, group));
            st.groups[group] = count + 1;
            gsmUpdateAverage(suffixId, group);
            if (typeof recalcGsmSummary === 'function') recalcGsmSummary(suffixId);
        }

        // Strip Tensile grouped table using same group logic as GSM
        const stripGroupedState = {};
        function initStripGrouped(suffixId) {
            const body = document.getElementById(`stripBody_${suffixId}`);
            if (!body) return;
            stripGroupedState[suffixId] = { groups: {}, body, indexCounter: 0, mdStrengths: {}, cdStrengths: {} };
            Object.keys(GSM_GROUP_OPTIONS).forEach(group => createStripGroupSection(suffixId, group));
        }
        function createStripGroupSection(suffixId, group) {
            const st = stripGroupedState[suffixId];
            st.groups[group] = 0;
            stripAddRow(suffixId, group);
            const btnRow = document.createElement('tr');
            const btnCell = document.createElement('td');
            btnCell.colSpan = 5;
            const btn = document.createElement('button');
            btn.textContent = `Add 1 More Row (${group})`;
            btn.className = 'btn-add';
            btn.addEventListener('click', function(e) { e.preventDefault(); stripAddRow(suffixId, group); });
            btnCell.appendChild(btn);
            btnRow.appendChild(btnCell);
            st.body.appendChild(btnRow);
        }
        function stripFindButtonRow(suffixId, group) {
            const st = stripGroupedState[suffixId];
            return Array.from(st.body.querySelectorAll('tr'))
                .find(r => r.textContent.includes(`Add 1 More Row (${group})`));
        }
        function stripAddRow(suffixId, group) {
            const st = stripGroupedState[suffixId];
            const count = st.groups[group] || 0;
            if (count >= 4) { alert(`Max 4 rows for ${group}`); return; }
            const label = GSM_GROUP_OPTIONS[group][count];
            const row = document.createElement('tr');
            row.setAttribute('data-group', group);
            const pos = document.createElement('td');
            const dirCell = document.createElement('td');
            const strengthCell = document.createElement('td');
            const ratioCell = document.createElement('td');
            const elongCell = document.createElement('td');
            const select = document.createElement('select');
            GSM_GROUP_OPTIONS[group].forEach(opt => {
                const o = document.createElement('option');
                o.value = opt; o.textContent = opt; if (opt === label) o.selected = true; select.appendChild(o);
            });
            const idx = ++st.indexCounter;
            select.name = `strip_position_${idx}`;
            select.id = `strip_position_${idx}`;
            pos.appendChild(select);
            const dirSelect = document.createElement('select');
            dirSelect.name = `strip_direction_${idx}`;
            dirSelect.id = `strip_direction_${idx}`;
            dirSelect.style.width = '100%';
            dirSelect.style.padding = '10px 12px';
            dirSelect.style.border = '1px solid #e5e7eb';
            dirSelect.style.borderRadius = '8px';
            const mdOpt = document.createElement('option');
            mdOpt.value = 'MD'; mdOpt.textContent = 'MD';
            const cdOpt = document.createElement('option');
            cdOpt.value = 'CD'; cdOpt.textContent = 'CD';
            dirSelect.appendChild(mdOpt);
            dirSelect.appendChild(cdOpt);
            dirSelect.addEventListener('change', () => { stripCalculateRatio(suffixId, idx); });
            dirCell.appendChild(dirSelect);
            const strengthInput = document.createElement('input');
            strengthInput.type = 'number';
            strengthInput.className = 'strip-strength';
            strengthInput.placeholder = 'Enter strength';
            strengthInput.min = '0';
            strengthInput.step = '0.0001';
            strengthInput.name = `strip_strength_${idx}`;
            strengthInput.addEventListener('input', () => { stripCalculateRatio(suffixId, idx); });
            strengthCell.appendChild(strengthInput);
            const ratioSpan = document.createElement('span');
            ratioSpan.className = 'strip-ratio';
            ratioSpan.id = `strip_ratio_${idx}`;
            ratioSpan.style.fontWeight = 'bold';
            ratioSpan.style.color = '#27ae60';
            ratioSpan.textContent = '0.00';
            ratioCell.appendChild(ratioSpan);
            ratioCell.style.textAlign = 'center';
            ratioCell.style.background = '#f8f9fa';
            const elongInput = document.createElement('input');
            elongInput.type = 'number';
            elongInput.className = 'strip-elongation';
            elongInput.placeholder = 'Enter elongation';
            elongInput.min = '0';
            elongInput.step = '0.0001';
            elongInput.name = `strip_elongation_${idx}`;
            elongInput.addEventListener('input', () => { if (typeof recalcStripSummary === 'function') recalcStripSummary(suffixId); });
            elongCell.appendChild(elongInput);
            row.appendChild(pos); row.appendChild(dirCell); row.appendChild(strengthCell); row.appendChild(ratioCell); row.appendChild(elongCell);
            st.body.insertBefore(row, stripFindButtonRow(suffixId, group));
            st.groups[group] = count + 1;
        }
        
        function stripCalculateRatio(suffixId, rowIdx) {
            const st = stripGroupedState[suffixId];
            const body = document.getElementById(`stripBody_${suffixId}`);
            if (!body) return;
            
            const allRows = Array.from(body.querySelectorAll('tr[data-group]'));
            
            // Clear all ratios first
            allRows.forEach(row => {
                const ratioSpan = row.querySelector('.strip-ratio');
                if (ratioSpan) ratioSpan.textContent = '';
            });
            
            // Group rows into MD-CD pairs
            let mdRow = null;
            let mdValue = null;
            
            allRows.forEach((row, idx) => {
                const dirSelect = row.querySelector('select[name^="strip_direction_"]');
                const strengthInput = row.querySelector('.strip-strength');
                
                if (!dirSelect || !strengthInput) return;
                
                const dir = dirSelect.value;
                const strength = parseFloat(strengthInput.value);
                
                if (!isNaN(strength) && strength > 0) {
                    if (dir === 'MD') {
                        // Store MD row and value for pairing
                        mdRow = row;
                        mdValue = strength;
                    } else if (dir === 'CD' && mdRow && mdValue) {
                        // Found a CD after an MD - calculate ratio for this pair
                        const cdValue = strength;
                        const ratio = mdValue > 0 ? cdValue / mdValue : 0;
                        const ratioText = `1:${ratio.toFixed(2)}`;
                        
                        // Show ratio in the CD row
                        const ratioSpan = row.querySelector('.strip-ratio');
                        if (ratioSpan) {
                            ratioSpan.textContent = ratioText;
                        }
                        
                        // Reset for next pair
                        mdRow = null;
                        mdValue = null;
                    }
                }
            });
            
            // Recalculate summary
            if (typeof recalcStripSummary === 'function') recalcStripSummary(suffixId);
        }
        
        // Recalculate summary stats for Strip Tensile table (separate MD and CD)
        function recalcStripSummary(suffixId) {
            const body = document.getElementById(`stripBody_${suffixId}`);
            if (!body) return;
            const rows = Array.from(body.querySelectorAll('tr[data-group]'));
            
            // Collect all values with their direction
            const mdStrengths = [];
            const mdElongs = [];
            const cdStrengths = [];
            const cdElongs = [];
            
            rows.forEach(row => {
                const dirSelect = row.querySelector('select[name^="strip_direction_"]');
                const strengthInput = row.querySelector('.strip-strength');
                const elongInput = row.querySelector('.strip-elongation');
                
                if (!dirSelect || !strengthInput || !elongInput) return;
                
                const dir = dirSelect.value;
                const strength = parseFloat(strengthInput.value);
                const elong = parseFloat(elongInput.value);
                
                if (dir === 'MD') {
                    if (!isNaN(strength) && strength > 0) mdStrengths.push(strength);
                    if (!isNaN(elong) && elong > 0) mdElongs.push(elong);
                } else if (dir === 'CD') {
                    if (!isNaN(strength) && strength > 0) cdStrengths.push(strength);
                    if (!isNaN(elong) && elong > 0) cdElongs.push(elong);
                }
            });
            
            // Helper to calc stats (Average, SD, CV%, Max, Min)
            const calcStats = (vals) => {
                const n = vals.length;
                if (n === 0) return { avg: 0, sd: 0, cv: 0, max: 0, min: 0 };
                
                const sum = vals.reduce((a,b)=>a+b,0);
                const avg = sum / n;
                
                let sd = 0;
                if (n > 0) {
                    let sumSquaredDiff = 0;
                    for (let i = 0; i < vals.length; i++) {
                        const diff = vals[i] - avg;
                        sumSquaredDiff += diff * diff;
                    }
                    const variance = sumSquaredDiff / n;
                    sd = Math.sqrt(variance);
                }
                
                const cv = (avg > 0 && sd > 0) ? (sd / avg) * 100 : 0;
                const max = n > 0 ? Math.max(...vals) : 0;
                const min = n > 0 ? Math.min(...vals) : 0;
                
                return { avg, sd, cv, max, min };
            };
            
            const mdStrengthStats = calcStats(mdStrengths);
            const mdElongStats = calcStats(mdElongs);
            const cdStrengthStats = calcStats(cdStrengths);
            const cdElongStats = calcStats(cdElongs);
            
            const fmt2 = v => (isFinite(v) ? v.toFixed(2) : '0.00');
            const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            const setInputVal = (id, val) => { const el = document.getElementById(id); if (el) el.value = val; };
            
            // MD Strength
            setText(`strip_sum_md_strength_avg_${suffixId}`, fmt2(mdStrengthStats.avg));
            setInputVal(`strip_input_md_strength_avg_${suffixId}`, mdStrengthStats.avg);
            setText(`strip_sum_md_strength_sd_${suffixId}`, fmt2(mdStrengthStats.sd));
            setInputVal(`strip_input_md_strength_sd_${suffixId}`, mdStrengthStats.sd);
            setText(`strip_sum_md_strength_cv_${suffixId}`, fmt2(mdStrengthStats.cv));
            setInputVal(`strip_input_md_strength_cv_${suffixId}`, mdStrengthStats.cv);
            setText(`strip_sum_md_strength_max_${suffixId}`, fmt2(mdStrengthStats.max));
            setInputVal(`strip_input_md_strength_max_${suffixId}`, mdStrengthStats.max);
            setText(`strip_sum_md_strength_min_${suffixId}`, fmt2(mdStrengthStats.min));
            setInputVal(`strip_input_md_strength_min_${suffixId}`, mdStrengthStats.min);
            
            // MD Elongation
            setText(`strip_sum_md_elong_avg_${suffixId}`, fmt2(mdElongStats.avg));
            setInputVal(`strip_input_md_elong_avg_${suffixId}`, mdElongStats.avg);
            setText(`strip_sum_md_elong_sd_${suffixId}`, fmt2(mdElongStats.sd));
            setInputVal(`strip_input_md_elong_sd_${suffixId}`, mdElongStats.sd);
            setText(`strip_sum_md_elong_cv_${suffixId}`, fmt2(mdElongStats.cv));
            setInputVal(`strip_input_md_elong_cv_${suffixId}`, mdElongStats.cv);
            setText(`strip_sum_md_elong_max_${suffixId}`, fmt2(mdElongStats.max));
            setInputVal(`strip_input_md_elong_max_${suffixId}`, mdElongStats.max);
            setText(`strip_sum_md_elong_min_${suffixId}`, fmt2(mdElongStats.min));
            setInputVal(`strip_input_md_elong_min_${suffixId}`, mdElongStats.min);
            
            // CD Strength
            setText(`strip_sum_cd_strength_avg_${suffixId}`, fmt2(cdStrengthStats.avg));
            setInputVal(`strip_input_cd_strength_avg_${suffixId}`, cdStrengthStats.avg);
            setText(`strip_sum_cd_strength_sd_${suffixId}`, fmt2(cdStrengthStats.sd));
            setInputVal(`strip_input_cd_strength_sd_${suffixId}`, cdStrengthStats.sd);
            setText(`strip_sum_cd_strength_cv_${suffixId}`, fmt2(cdStrengthStats.cv));
            setInputVal(`strip_input_cd_strength_cv_${suffixId}`, cdStrengthStats.cv);
            setText(`strip_sum_cd_strength_max_${suffixId}`, fmt2(cdStrengthStats.max));
            setInputVal(`strip_input_cd_strength_max_${suffixId}`, cdStrengthStats.max);
            setText(`strip_sum_cd_strength_min_${suffixId}`, fmt2(cdStrengthStats.min));
            setInputVal(`strip_input_cd_strength_min_${suffixId}`, cdStrengthStats.min);
            
            // CD Elongation
            setText(`strip_sum_cd_elong_avg_${suffixId}`, fmt2(cdElongStats.avg));
            setInputVal(`strip_input_cd_elong_avg_${suffixId}`, cdElongStats.avg);
            setText(`strip_sum_cd_elong_sd_${suffixId}`, fmt2(cdElongStats.sd));
            setInputVal(`strip_input_cd_elong_sd_${suffixId}`, cdElongStats.sd);
            setText(`strip_sum_cd_elong_cv_${suffixId}`, fmt2(cdElongStats.cv));
            setInputVal(`strip_input_cd_elong_cv_${suffixId}`, cdElongStats.cv);
            setText(`strip_sum_cd_elong_max_${suffixId}`, fmt2(cdElongStats.max));
            setInputVal(`strip_input_cd_elong_max_${suffixId}`, cdElongStats.max);
            setText(`strip_sum_cd_elong_min_${suffixId}`, fmt2(cdElongStats.min));
            setInputVal(`strip_input_cd_elong_min_${suffixId}`, cdElongStats.min);
        }

        // CBR Puncture Resistance grouped table using same group logic as GSM
        const cbrGroupedState = {};
        function initCbrGrouped(suffixId) {
            const body = document.getElementById(`cbrBody_${suffixId}`);
            if (!body) return;
            cbrGroupedState[suffixId] = { groups: {}, body, indexCounter: 0 };
            Object.keys(GSM_GROUP_OPTIONS).forEach(group => createCbrGroupSection(suffixId, group));
        }
        function createCbrGroupSection(suffixId, group) {
            const st = cbrGroupedState[suffixId];
            st.groups[group] = 0;
            cbrAddRow(suffixId, group);
            const btnRow = document.createElement('tr');
            const btnCell = document.createElement('td');
            btnCell.colSpan = 3;
            const btn = document.createElement('button');
            btn.textContent = `Add 1 More Row (${group})`;
            btn.className = 'btn-add';
            btn.addEventListener('click', function(e) { e.preventDefault(); cbrAddRow(suffixId, group); });
            btnCell.appendChild(btn);
            btnRow.appendChild(btnCell);
            st.body.appendChild(btnRow);
        }
        function cbrFindButtonRow(suffixId, group) {
            const st = cbrGroupedState[suffixId];
            return Array.from(st.body.querySelectorAll('tr'))
                .find(r => r.textContent.includes(`Add 1 More Row (${group})`));
        }
        function cbrAddRow(suffixId, group) {
            const st = cbrGroupedState[suffixId];
            const count = st.groups[group] || 0;
            if (count >= 4) { alert(`Max 4 rows for ${group}`); return; }
            const label = GSM_GROUP_OPTIONS[group][count];
            const row = document.createElement('tr');
            row.setAttribute('data-group', group);
            const pos = document.createElement('td');
            const forceCell = document.createElement('td');
            const displCell = document.createElement('td');
            const select = document.createElement('select');
            GSM_GROUP_OPTIONS[group].forEach(opt => {
                const o = document.createElement('option');
                o.value = opt; o.textContent = opt; if (opt === label) o.selected = true; select.appendChild(o);
            });
            const idx = ++st.indexCounter;
            select.name = `cbr_position_${idx}`;
            select.id = `cbr_position_${idx}`;
            pos.appendChild(select);
            const forceInput = document.createElement('input');
            forceInput.type = 'number';
            forceInput.className = 'cbr-force';
            forceInput.placeholder = 'Enter force';
            forceInput.min = '0';
            forceInput.step = '0.0001';
            forceInput.name = `cbr_force_${idx}`;
            forceInput.addEventListener('input', () => { if (typeof recalcCbrSummary === 'function') recalcCbrSummary(suffixId); });
            forceCell.appendChild(forceInput);
            const displInput = document.createElement('input');
            displInput.type = 'number';
            displInput.className = 'cbr-displacement';
            displInput.placeholder = 'Enter displacement';
            displInput.min = '0';
            displInput.step = '0.0001';
            displInput.name = `cbr_displacement_${idx}`;
            displInput.addEventListener('input', () => { if (typeof recalcCbrSummary === 'function') recalcCbrSummary(suffixId); });
            displCell.appendChild(displInput);
            row.appendChild(pos); row.appendChild(forceCell); row.appendChild(displCell);
            st.body.insertBefore(row, cbrFindButtonRow(suffixId, group));
            st.groups[group] = count + 1;
        }

        // Recalculate summary stats for CBR table (ALL values combined, not separated by MD/CD)
        function recalcCbrSummary(suffixId) {
            const body = document.getElementById(`cbrBody_${suffixId}`);
            if (!body) return;
            const rows = Array.from(body.querySelectorAll('tr[data-group]'));
            
            // Collect ALL values (not separated by direction)
            const allForces = [];
            const allDispls = [];
            
            rows.forEach(row => {
                const forceInput = row.querySelector('.cbr-force');
                const displInput = row.querySelector('.cbr-displacement');
                
                if (!forceInput || !displInput) return;
                
                const force = parseFloat(forceInput.value);
                const displ = parseFloat(displInput.value);
                
                if (!isNaN(force) && force > 0) allForces.push(force);
                if (!isNaN(displ) && displ > 0) allDispls.push(displ);
            });
            
            // Helper to calc stats (Average, SD, CV%, Max, Min)
            const calcStats = (vals) => {
                const n = vals.length;
                if (n === 0) return { avg: 0, sd: 0, cv: 0, max: 0, min: 0 };
                
                // Calculate average
                const sum = vals.reduce((a,b)=>a+b,0);
                const avg = sum / n;
                
                // Calculate SD - using population standard deviation (divide by n)
                let sd = 0;
                if (n > 0) {
                    let sumSquaredDiff = 0;
                    for (let i = 0; i < vals.length; i++) {
                        const diff = vals[i] - avg;
                        sumSquaredDiff += diff * diff;
                    }
                    const variance = sumSquaredDiff / n;  // Use n instead of n-1
                    sd = Math.sqrt(variance);
                }
                
                // Calculate CV% = (SD / Average) * 100
                const cv = (avg > 0 && sd > 0) ? (sd / avg) * 100 : 0;
                
                // Calculate Max and Min
                const max = n > 0 ? Math.max(...vals) : 0;
                const min = n > 0 ? Math.min(...vals) : 0;
                
                return { avg, sd, cv, max, min };
            };
            
            const forceStats = calcStats(allForces);
            const displStats = calcStats(allDispls);
            
            const fmt1 = v => (isFinite(v) ? v.toFixed(1) : '0.0');
            const fmt2 = v => (isFinite(v) ? v.toFixed(2) : '0.00');
            const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            const setInput = (id, val) => { const el = document.getElementById(id); if (el) el.value = val; };
            
            // Force stats
            setText(`cbr_sum_force_avg_${suffixId}`, fmt2(forceStats.avg));
            setText(`cbr_sum_force_sd_${suffixId}`, fmt1(forceStats.sd));
            setText(`cbr_sum_force_cv_${suffixId}`, fmt1(forceStats.cv));
            setText(`cbr_sum_force_max_${suffixId}`, fmt2(forceStats.max));
            setText(`cbr_sum_force_min_${suffixId}`, fmt2(forceStats.min));
            setInput(`cbr_sum_force_avg_input_${suffixId}`, forceStats.avg);
            setInput(`cbr_sum_force_sd_input_${suffixId}`, forceStats.sd);
            setInput(`cbr_sum_force_cv_input_${suffixId}`, forceStats.cv);
            setInput(`cbr_sum_force_max_input_${suffixId}`, forceStats.max);
            setInput(`cbr_sum_force_min_input_${suffixId}`, forceStats.min);
            
            // Displacement stats
            setText(`cbr_sum_displ_avg_${suffixId}`, fmt1(displStats.avg));
            setText(`cbr_sum_displ_sd_${suffixId}`, fmt1(displStats.sd));
            setText(`cbr_sum_displ_cv_${suffixId}`, fmt1(displStats.cv));
            setText(`cbr_sum_displ_max_${suffixId}`, fmt1(displStats.max));
            setText(`cbr_sum_displ_min_${suffixId}`, fmt1(displStats.min));
            setInput(`cbr_sum_displ_avg_input_${suffixId}`, displStats.avg);
            setInput(`cbr_sum_displ_sd_input_${suffixId}`, displStats.sd);
            setInput(`cbr_sum_displ_cv_input_${suffixId}`, displStats.cv);
            setInput(`cbr_sum_displ_max_input_${suffixId}`, displStats.max);
            setInput(`cbr_sum_displ_min_input_${suffixId}`, displStats.min);
        }

        // Grab Tensile grouped table using same group logic as GSM
        const grabGroupedState = {};
        function initGrabGrouped(suffixId) {
            const body = document.getElementById(`grabBody_${suffixId}`);
            if (!body) return;
            grabGroupedState[suffixId] = { groups: {}, body, indexCounter: 0 };
            Object.keys(GSM_GROUP_OPTIONS).forEach(group => createGrabGroupSection(suffixId, group));
        }
        function createGrabGroupSection(suffixId, group) {
            const st = grabGroupedState[suffixId];
            st.groups[group] = 0;
            grabAddRow(suffixId, group);
            const btnRow = document.createElement('tr');
            const btnCell = document.createElement('td');
            btnCell.colSpan = 4;
            const btn = document.createElement('button');
            btn.textContent = `Add 1 More Row (${group})`;
            btn.className = 'btn-add';
            btn.addEventListener('click', function(e) { e.preventDefault(); grabAddRow(suffixId, group); });
            btnCell.appendChild(btn);
            btnRow.appendChild(btnCell);
            st.body.appendChild(btnRow);
        }
        function grabFindButtonRow(suffixId, group) {
            const st = grabGroupedState[suffixId];
            return Array.from(st.body.querySelectorAll('tr'))
                .find(r => r.textContent.includes(`Add 1 More Row (${group})`));
        }
        function grabAddRow(suffixId, group) {
            const st = grabGroupedState[suffixId];
            const count = st.groups[group] || 0;
            if (count >= 4) { alert(`Max 4 rows for ${group}`); return; }
            const label = GSM_GROUP_OPTIONS[group][count];
            const row = document.createElement('tr');
            row.setAttribute('data-group', group);
            const pos = document.createElement('td');
            const dirCell = document.createElement('td');
            const forceCell = document.createElement('td');
            const elongCell = document.createElement('td');
            const select = document.createElement('select');
            GSM_GROUP_OPTIONS[group].forEach(opt => {
                const o = document.createElement('option');
                o.value = opt; o.textContent = opt; if (opt === label) o.selected = true; select.appendChild(o);
            });
            const idx = ++st.indexCounter;
            select.name = `grab_position_${idx}`;
            select.id = `grab_position_${idx}`;
            pos.appendChild(select);
            const dirSelect = document.createElement('select');
            dirSelect.name = `grab_direction_${idx}`;
            dirSelect.id = `grab_direction_${idx}`;
            dirSelect.style.width = '100%';
            dirSelect.style.padding = '10px 12px';
            dirSelect.style.border = '1px solid #e5e7eb';
            dirSelect.style.borderRadius = '8px';
            const mdOpt = document.createElement('option');
            mdOpt.value = 'MD'; mdOpt.textContent = 'MD';
            const cdOpt = document.createElement('option');
            cdOpt.value = 'CD'; cdOpt.textContent = 'CD';
            dirSelect.appendChild(mdOpt);
            dirSelect.appendChild(cdOpt);
            dirSelect.addEventListener('change', () => { if (typeof recalcGrabSummary === 'function') recalcGrabSummary(suffixId); });
            dirCell.appendChild(dirSelect);
            const forceInput = document.createElement('input');
            forceInput.type = 'number';
            forceInput.className = 'grab-force';
            forceInput.placeholder = 'Enter force';
            forceInput.min = '0';
            forceInput.step = '0.0001';
            forceInput.name = `grab_force_${idx}`;
            forceInput.addEventListener('input', () => { if (typeof recalcGrabSummary === 'function') recalcGrabSummary(suffixId); });
            forceCell.appendChild(forceInput);
            const elongInput = document.createElement('input');
            elongInput.type = 'number';
            elongInput.className = 'grab-elongation';
            elongInput.placeholder = 'Enter elongation';
            elongInput.min = '0';
            elongInput.step = '0.0001';
            elongInput.name = `grab_elongation_${idx}`;
            elongInput.addEventListener('input', () => { if (typeof recalcGrabSummary === 'function') recalcGrabSummary(suffixId); });
            elongCell.appendChild(elongInput);
            row.appendChild(pos); row.appendChild(dirCell); row.appendChild(forceCell); row.appendChild(elongCell);
            st.body.insertBefore(row, grabFindButtonRow(suffixId, group));
            st.groups[group] = count + 1;
        }

        // Recalculate summary stats for Grab Tensile table (separate MD and CD)
        function recalcGrabSummary(suffixId) {
            const body = document.getElementById(`grabBody_${suffixId}`);
            if (!body) return;
            const rows = Array.from(body.querySelectorAll('tr[data-group]'));
            
            // Collect all values with their direction
            const mdForces = [];
            const mdElongs = [];
            const cdForces = [];
            const cdElongs = [];
            
            rows.forEach(row => {
                const dirSelect = row.querySelector('select[name^="grab_direction_"]');
                const forceInput = row.querySelector('.grab-force');
                const elongInput = row.querySelector('.grab-elongation');
                
                if (!dirSelect || !forceInput || !elongInput) return;
                
                const dir = dirSelect.value;
                const force = parseFloat(forceInput.value);
                const elong = parseFloat(elongInput.value);
                
                if (dir === 'MD') {
                    if (!isNaN(force) && force > 0) mdForces.push(force);
                    if (!isNaN(elong) && elong > 0) mdElongs.push(elong);
                } else if (dir === 'CD') {
                    if (!isNaN(force) && force > 0) cdForces.push(force);
                    if (!isNaN(elong) && elong > 0) cdElongs.push(elong);
                }
            });
            
            // Helper to calc stats (Average, SD, CV%, Max, Min)
            const calcStats = (vals) => {
                const n = vals.length;
                if (n === 0) return { avg: 0, sd: 0, cv: 0, max: 0, min: 0 };
                
                // Calculate average
                const avg = vals.reduce((a,b)=>a+b,0) / n;
                
                // Calculate SD using sample standard deviation (n-1)
                let sd = 0;
                if (n > 1) {
                    const sumSquaredDiff = vals.reduce((acc, v) => acc + Math.pow(v - avg, 2), 0);
                    sd = Math.sqrt(sumSquaredDiff / (n - 1));
                }
                
                // Calculate CV%
                const cv = (avg > 0 && sd > 0) ? (sd / avg) * 100 : 0;
                
                // Calculate Max and Min
                const max = n > 0 ? Math.max(...vals) : 0;
                const min = n > 0 ? Math.min(...vals) : 0;
                
                return { avg, sd, cv, max, min };
            };
            
            const mdForceStats = calcStats(mdForces);
            const mdElongStats = calcStats(mdElongs);
            const cdForceStats = calcStats(cdForces);
            const cdElongStats = calcStats(cdElongs);
            
            const fmt2 = v => (isFinite(v) ? v.toFixed(2) : '0.00');
            const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            const setInputVal = (id, val) => { const el = document.getElementById(id); if (el) el.value = val; };
            
            // MD Force
            setText(`grab_sum_md_force_avg_${suffixId}`, fmt2(mdForceStats.avg));
            setInputVal(`grab_input_md_force_avg_${suffixId}`, mdForceStats.avg);
            setText(`grab_sum_md_force_sd_${suffixId}`, fmt2(mdForceStats.sd));
            setInputVal(`grab_input_md_force_sd_${suffixId}`, mdForceStats.sd);
            setText(`grab_sum_md_force_cv_${suffixId}`, fmt2(mdForceStats.cv));
            setInputVal(`grab_input_md_force_cv_${suffixId}`, mdForceStats.cv);
            setText(`grab_sum_md_force_max_${suffixId}`, fmt2(mdForceStats.max));
            setInputVal(`grab_input_md_force_max_${suffixId}`, mdForceStats.max);
            setText(`grab_sum_md_force_min_${suffixId}`, fmt2(mdForceStats.min));
            setInputVal(`grab_input_md_force_min_${suffixId}`, mdForceStats.min);
            
            // MD Elongation
            setText(`grab_sum_md_elong_avg_${suffixId}`, fmt2(mdElongStats.avg));
            setInputVal(`grab_input_md_elong_avg_${suffixId}`, mdElongStats.avg);
            setText(`grab_sum_md_elong_sd_${suffixId}`, fmt2(mdElongStats.sd));
            setInputVal(`grab_input_md_elong_sd_${suffixId}`, mdElongStats.sd);
            setText(`grab_sum_md_elong_cv_${suffixId}`, fmt2(mdElongStats.cv));
            setInputVal(`grab_input_md_elong_cv_${suffixId}`, mdElongStats.cv);
            setText(`grab_sum_md_elong_max_${suffixId}`, fmt2(mdElongStats.max));
            setInputVal(`grab_input_md_elong_max_${suffixId}`, mdElongStats.max);
            setText(`grab_sum_md_elong_min_${suffixId}`, fmt2(mdElongStats.min));
            setInputVal(`grab_input_md_elong_min_${suffixId}`, mdElongStats.min);
            
            // CD Force
            setText(`grab_sum_cd_force_avg_${suffixId}`, fmt2(cdForceStats.avg));
            setInputVal(`grab_input_cd_force_avg_${suffixId}`, cdForceStats.avg);
            setText(`grab_sum_cd_force_sd_${suffixId}`, fmt2(cdForceStats.sd));
            setInputVal(`grab_input_cd_force_sd_${suffixId}`, cdForceStats.sd);
            setText(`grab_sum_cd_force_cv_${suffixId}`, fmt2(cdForceStats.cv));
            setInputVal(`grab_input_cd_force_cv_${suffixId}`, cdForceStats.cv);
            setText(`grab_sum_cd_force_max_${suffixId}`, fmt2(cdForceStats.max));
            setInputVal(`grab_input_cd_force_max_${suffixId}`, cdForceStats.max);
            setText(`grab_sum_cd_force_min_${suffixId}`, fmt2(cdForceStats.min));
            setInputVal(`grab_input_cd_force_min_${suffixId}`, cdForceStats.min);
            
            // CD Elongation
            setText(`grab_sum_cd_elong_avg_${suffixId}`, fmt2(cdElongStats.avg));
            setInputVal(`grab_input_cd_elong_avg_${suffixId}`, cdElongStats.avg);
            setText(`grab_sum_cd_elong_sd_${suffixId}`, fmt2(cdElongStats.sd));
            setInputVal(`grab_input_cd_elong_sd_${suffixId}`, cdElongStats.sd);
            setText(`grab_sum_cd_elong_cv_${suffixId}`, fmt2(cdElongStats.cv));
            setInputVal(`grab_input_cd_elong_cv_${suffixId}`, cdElongStats.cv);
            setText(`grab_sum_cd_elong_max_${suffixId}`, fmt2(cdElongStats.max));
            setInputVal(`grab_input_cd_elong_max_${suffixId}`, cdElongStats.max);
            setText(`grab_sum_cd_elong_min_${suffixId}`, fmt2(cdElongStats.min));
            setInputVal(`grab_input_cd_elong_min_${suffixId}`, cdElongStats.min);
        }

        // Thickness grouped table (Under 2kPa) using same group logic as GSM
        const thicknessGroupedState = {};
        function initThicknessGrouped(suffixId) {
            const body = document.getElementById(`thkBody_${suffixId}`);
            if (!body) return;
            thicknessGroupedState[suffixId] = { groups: {}, body, indexCounter: 0 };
            Object.keys(GSM_GROUP_OPTIONS).forEach(group => createThicknessGroupSection(suffixId, group));
        }
        function createThicknessGroupSection(suffixId, group) {
            const st = thicknessGroupedState[suffixId];
            st.groups[group] = 0;
            thicknessAddRow(suffixId, group);
            const btnRow = document.createElement('tr');
            const btnCell = document.createElement('td');
            btnCell.colSpan = 3;
            const btn = document.createElement('button');
            btn.textContent = `Add 1 More Row (${group})`;
            btn.className = 'btn-add';
            btn.addEventListener('click', function(e) { e.preventDefault(); thicknessAddRow(suffixId, group); });
            btnCell.appendChild(btn);
            btnRow.appendChild(btnCell);
            st.body.appendChild(btnRow);
        }
        function thicknessFindButtonRow(suffixId, group) {
            const st = thicknessGroupedState[suffixId];
            return Array.from(st.body.querySelectorAll('tr'))
                .find(r => r.textContent.includes(`Add 1 More Row (${group})`));
        }
        function thicknessUpdateAverage(suffixId, group) {
            const st = thicknessGroupedState[suffixId];
            const rows = Array.from(st.body.querySelectorAll(`tr[data-group="${group}"]`));
            const vals = rows.map(r => parseFloat(r.querySelector('.thk-input')?.value) || 0).filter(v => v > 0);
            const avg = vals.length ? (vals.reduce((a, b) => a + b, 0) / vals.length).toFixed(2) : '0.0';
            rows.forEach((r, i) => {
                const c = r.querySelector('.avg-cell');
                if (!c) return;
                c.innerHTML = (i === Math.floor(rows.length / 2)) ? `<span class=\"avg-badge\">${avg}</span>` : '';
            });
            if (typeof recalcThicknessSummary === 'function') recalcThicknessSummary(suffixId);
        }
        function thicknessAddRow(suffixId, group) {
            const st = thicknessGroupedState[suffixId];
            const count = st.groups[group] || 0;
            if (count >= 4) { alert(`Max 4 rows for ${group}`); return; }
            const label = GSM_GROUP_OPTIONS[group][count];
            const row = document.createElement('tr');
            row.setAttribute('data-group', group);
            const pos = document.createElement('td');
            const val = document.createElement('td');
            const avg = document.createElement('td');
            const select = document.createElement('select');
            GSM_GROUP_OPTIONS[group].forEach(opt => {
                const o = document.createElement('option');
                o.value = opt; o.textContent = opt; if (opt === label) o.selected = true; select.appendChild(o);
            });
            // assign index names-compatible with PHP collection
            const idx = ++st.indexCounter;
            select.name = `astmd5199_position_${idx}`;
            select.id = `astmd5199_position_${idx}`;
            pos.appendChild(select);
            const input = document.createElement('input');
            input.type = 'number';
            input.className = 'thk-input';
            input.placeholder = 'Enter thickness';
            input.min = '0';
            input.step = '0.0001';
            input.name = `astmd5199_under2kpa_${idx}`;
            input.addEventListener('input', () => { thicknessUpdateAverage(suffixId, group); recalcThicknessSummary(suffixId); });
            val.appendChild(input);
            avg.classList.add('avg-cell');
            row.appendChild(pos); row.appendChild(val); row.appendChild(avg);
            st.body.insertBefore(row, thicknessFindButtonRow(suffixId, group));
            st.groups[group] = count + 1;
            thicknessUpdateAverage(suffixId, group);
            if (typeof recalcThicknessSummary === 'function') recalcThicknessSummary(suffixId);
        }

        // Recalculate summary stats for thickness table (all inputs across groups)
        function recalcThicknessSummary(suffixId) {
            const body = document.getElementById(`thkBody_${suffixId}`);
            if (!body) return;
            const inputs = Array.from(body.querySelectorAll('input.thk-input'));
            const values = inputs.map(i => parseFloat(i.value)).filter(v => !isNaN(v) && v > 0);
            const n = values.length;
            const avg = n ? (values.reduce((a,b)=>a+b,0) / n) : 0;
            // Population SD to match general UI behavior; adjust if needed
            const variance = (n > 1) ? values.reduce((acc,v)=> acc + Math.pow(v - avg, 2), 0) / (n-1) : 0;
            const sd = Math.sqrt(variance);
            const cv = avg > 0 ? (sd / avg) * 100 : 0;
            const max = n ? Math.max(...values) : 0;
            const min = n ? Math.min(...values) : 0;
            const fmt3 = v => (isFinite(v) ? v.toFixed(3) : '0.000');
            const fmt2 = v => (isFinite(v) ? v.toFixed(2) : '0.00');
            const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            const setInput = (id, val) => { const el = document.getElementById(id); if (el) el.value = val; };
            setText(`thk_sum_avg_${suffixId}`, fmt3(avg));
            setText(`thk_sum_sd_${suffixId}`, fmt3(sd));
            setText(`thk_sum_cv_${suffixId}`, fmt2(cv));
            setText(`thk_sum_max_${suffixId}`, fmt3(max));
            setText(`thk_sum_min_${suffixId}`, fmt3(min));
            // Update hidden inputs
            setInput(`thk_sum_avg_input_${suffixId}`, avg);
            setInput(`thk_sum_sd_input_${suffixId}`, sd);
            setInput(`thk_sum_cv_input_${suffixId}`, cv);
            setInput(`thk_sum_max_input_${suffixId}`, max);
            setInput(`thk_sum_min_input_${suffixId}`, min);
        }

        // Recalculate summary stats for GSM table (Calculated GSM values)
        function recalcGsmSummary(suffixId) {
            const body = document.getElementById(`gsmBody_${suffixId}`);
            if (!body) return;
            const inputs = Array.from(body.querySelectorAll('input.calc-gsm'));
            const values = inputs.map(i => parseFloat(i.value)).filter(v => !isNaN(v) && v > 0);
            const n = values.length;
            const avg = n ? (values.reduce((a,b)=>a+b,0) / n) : 0;
            const variance = (n > 1) ? values.reduce((acc,v)=> acc + Math.pow(v - avg, 2), 0) / (n - 1) : 0;
            const sd = Math.sqrt(variance);
            const cv = avg > 0 ? (sd / avg) * 100 : 0;
            const max = n ? Math.max(...values) : 0;
            const min = n ? Math.min(...values) : 0;
            const fmt2 = v => (isFinite(v) ? v.toFixed(2) : '0.00');
            const setText = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
            const setInput = (id, val) => { const el = document.getElementById(id); if (el) el.value = val; };
            setText(`gsm_sum_avg_${suffixId}`, fmt2(avg));
            setText(`gsm_sum_sd_${suffixId}`, fmt2(sd));
            setText(`gsm_sum_cv_${suffixId}`, fmt2(cv));
            setText(`gsm_sum_max_${suffixId}`, fmt2(max));
            setText(`gsm_sum_min_${suffixId}`, fmt2(min));
            // Update hidden inputs
            setInput(`gsm_sum_avg_input_${suffixId}`, avg);
            setInput(`gsm_sum_sd_input_${suffixId}`, sd);
            setInput(`gsm_sum_cv_input_${suffixId}`, cv);
            setInput(`gsm_sum_max_input_${suffixId}`, max);
            setInput(`gsm_sum_min_input_${suffixId}`, min);
        }

        // Clear form function
        function clearForm() {
            // Uncheck all checkboxes
            document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                checkbox.checked = false;
            });
            
            // Reset selection count
            updateSelectionCount();
            
            // Clear any form validation messages
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => alert.remove());
            
            // Generate new sample reference ID
            updateSampleReferenceId();
            
            // Hide all test parameters
            document.querySelectorAll('.test-parameters').forEach(params => {
                params.style.display = 'none';
            });
        }

        // Test parameters data
        const testParameters = {
            'Thickness (Under 2kPa Pressure)': {
                'ASTM D5199': { type: 'table_thickness_grouped' },
                'ISO 9863-1': { type: 'table_thickness_grouped' }
            },
            'Mass Per Unit Area (GSM)': {
                'ASTM D5261': {
                    type: 'table_gsm',
                    columns: ['Position of Sampling', 'Weight(gm)'],
                    rows: 4,
                    groups: []
                },
                'ISO 9864': {
                    type: 'table_gsm',
                    columns: ['Position of Sampling', 'Weight(gm)'],
                    rows: 4,
                    groups: []
                }
            },
            'Strip Tensile Test': {
                'ASTM D4595': {
                    type: 'table_strip_grouped'
                },
                'ISO 10319': {
                    type: 'table_strip_grouped'
                }
            },
            'CBR Puncture Resistance': {
                'ASTM D6241': {
                    type: 'table_cbr_grouped'
                },
                'ISO 12236': {
                    type: 'table_cbr_grouped'
                }
            },
            'Grab Tensile Test': {
                'ASTM D4632': {
                    type: 'table_grab_grouped'
                }
            },
            'Weathering Exposure Test': {
				'ASTM D4533': {
                    type: 'no_fields'
				}
            },
            'Seam/Joint Test': {
                'ISO 10321': []
            },
            'Fineness of Fiber': {
                'ISO 1973': {
                    type: 'table_fineness'
                }
            },
            'Cut Length of Fiber': {
                'ASTM D5103': {
                    type: 'table_cut_length'
                },
                'ASTM D5199': []
            },
            'Tenacity of Fiber': {
                'ISO 5079': {
                    type: 'table_tenacity_fiber',
                    start_row: 3,
                    end_row: 7
                }
            },
            'Tenacity of Yarn': {
                'ASTM D2256': {
                    type: 'table_tenacity_yarn',
                    start_row: 2,
                    end_row: 5
                }
            },
            
        };

        // Validate that test end date is not earlier than test start date
        function validateYarnTestDates() {
            const startDateInput = document.getElementById('yarn_test_start_date');
            const endDateInput = document.getElementById('yarn_test_end_date');
            const errorDiv = document.getElementById('yarn_date_error');
            
            if (!startDateInput || !endDateInput || !errorDiv) return;
            
            const startDate = startDateInput.value;
            const endDate = endDateInput.value;
            
            if (startDate && endDate) {
                if (endDate < startDate) {
                    errorDiv.textContent = 'Test End Date must not be earlier than Test Start Date';
                    endDateInput.setCustomValidity('Test End Date must not be earlier than Test Start Date');
                } else {
                    errorDiv.textContent = '';
                    endDateInput.setCustomValidity('');
                }
            } else {
                errorDiv.textContent = '';
                endDateInput.setCustomValidity('');
            }
        }

        // Show general information based on test type
        function toggleGeneralInfoVisibility() {
            const generalInfoDiv = document.getElementById('general-info-section');
            const fiberInfoDiv = document.getElementById('fiber-info-section');
            const yarnInfoDiv = document.getElementById('yarn-info-section');
            const productRefSelect = document.getElementById('product_reference');
            const fiberRefSelect = document.getElementById('fiber_reference_no');
            const yarnRefSelect = document.getElementById('yarn_reference_no');
            
            if (!generalInfoDiv || !fiberInfoDiv || !yarnInfoDiv) return;
            
            const selected = document.querySelector('input[type="checkbox"]:checked');
            if (!selected) {
                generalInfoDiv.style.display = '';
                fiberInfoDiv.style.display = 'none';
                yarnInfoDiv.style.display = 'none';
                if (productRefSelect) productRefSelect.required = true;
                if (fiberRefSelect) fiberRefSelect.required = false;
                if (yarnRefSelect) yarnRefSelect.required = false;
                return;
            }
            
            const testName = selected.getAttribute('data-test-name');
            const showMainTests = [
                'Thickness (Under 2kPa Pressure)',
                'Mass Per Unit Area (GSM)',
                'Strip Tensile Test',
                'CBR Puncture Resistance',
                'Grab Tensile Test'
            ];
            
            const showFiberTests = [
                'Fineness of Fiber',
                'Cut Length of Fiber',
                'Tenacity of Fiber'
            ];
            
            if (showMainTests.includes(testName)) {
                generalInfoDiv.style.display = '';
                fiberInfoDiv.style.display = 'none';
                yarnInfoDiv.style.display = 'none';
                if (productRefSelect) productRefSelect.required = true;
                if (fiberRefSelect) fiberRefSelect.required = false;
                if (yarnRefSelect) yarnRefSelect.required = false;
            } else if (showFiberTests.includes(testName)) {
                generalInfoDiv.style.display = 'none';
                fiberInfoDiv.style.display = '';
                yarnInfoDiv.style.display = 'none';
                if (productRefSelect) productRefSelect.required = false;
                if (fiberRefSelect) fiberRefSelect.required = true;
                if (yarnRefSelect) yarnRefSelect.required = false;
            } else if (testName === 'Tenacity of Yarn') {
                generalInfoDiv.style.display = 'none';
                fiberInfoDiv.style.display = 'none';
                yarnInfoDiv.style.display = '';
                if (productRefSelect) productRefSelect.required = false;
                if (fiberRefSelect) fiberRefSelect.required = false;
                if (yarnRefSelect) yarnRefSelect.required = true;
            } else {
                // For other tests (Weathering, Seam/Joint), hide all
                generalInfoDiv.style.display = 'none';
                fiberInfoDiv.style.display = 'none';
                yarnInfoDiv.style.display = 'none';
                if (productRefSelect) productRefSelect.required = false;
                if (fiberRefSelect) fiberRefSelect.required = false;
                if (yarnRefSelect) yarnRefSelect.required = false;
            }
        }

        // Toggle Other Information visibility when GSM-only is selected
        function toggleOtherInfoForGsmOnly() {
            const row = document.getElementById('other-info-row');
            if (!row) return;
            const selected = document.querySelector('input[type="checkbox"]:checked');
            const isGsmOnly = !!selected && selected.getAttribute('data-test-name') === 'Mass Per Unit Area (GSM)';
            row.style.display = isGsmOnly ? 'none' : '';
        }

        // Handle test selection - only one test at a time
        function handleTestSelection(checkbox) {
            if (checkbox.checked) {
                // Uncheck all other checkboxes and hide their test items
                document.querySelectorAll('.test-checkbox').forEach(cb => {
                    if (cb !== checkbox) {
                        cb.checked = false;
                        // Hide the entire test item (not just parameters)
                        const item = cb.closest('.test-item');
                        if (item) item.style.display = 'none';
                        const params = item?.querySelector('.test-parameters');
                        if (params) params.style.display = 'none';
                    }
                });
                // Show only the selected test item
                const selectedItem = checkbox.closest('.test-item');
                if (selectedItem) selectedItem.style.display = 'block';
                
                // Filter reference dropdowns based on selected test method
                filterReferencesByTestMethod(checkbox);
            } else {
                // If unchecking, show all test items again
                document.querySelectorAll('.test-item').forEach(item => {
                    item.style.display = 'block';
                });
                // Reset reference dropdowns to show all
                resetReferenceDropdowns();
            }
            showTestParameters(checkbox);
            toggleOtherInfoForGsmOnly();
            toggleGeneralInfoVisibility();
        }
        
        // Existing tests data (reference => [methods])
        const existingTests = <?php echo json_encode($existing_tests); ?>;
        
        // Filter reference dropdowns to hide already-submitted references for the selected test method
        function filterReferencesByTestMethod(checkbox) {
            // Product reference is now a text input, so no filtering needed
            console.log('Test selected:', checkbox.getAttribute('data-test-name'), checkbox.getAttribute('data-method'));
        }
        
        // Reset reference dropdowns to show all options
        function resetReferenceDropdowns() {
            // Product reference is now a text input, no action needed
        }
        
        // Show test parameters when checkbox is checked
        function showTestParameters(checkbox) {
            console.log('showTestParameters called');
            const testName = checkbox.getAttribute('data-test-name');
            const method = checkbox.getAttribute('data-method');
            
            console.log('Test name:', testName, 'Method:', method);
            
            
            // Find the correct params div using a simpler approach
            const testItem = checkbox.closest('.test-item');
            const paramsDiv = testItem.querySelector('.test-parameters');
            const paramsContent = testItem.querySelector('.test-parameters div:last-child');
            
            if (checkbox.checked) {
                // Show parameters for this test
                if (testParameters[testName] && testParameters[testName][method]) {
                    const testConfig = testParameters[testName][method];
                    
                    if (testConfig.type === 'table') {
                        // Generate table for thickness test
                        // Use 'astmd5199' as the common prefix for both ASTM D5199 and ISO 9863-1
                        const fieldPrefix = 'astmd5199';
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                              <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Position of Sampling</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Under 2kPa(mm)</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Average(mm)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;
                        
                        // Generate initial 4 rows with dropdown - show all position options
                        const allPositionOptions = [
                            'Left-1', 'Left-2', 'Left-3', 'Left-4',
                            'Middle Left-1', 'Middle Left-2', 'Middle Left-3', 'Middle Left-4',
                            'Middle Right-1', 'Middle Right-2', 'Middle Right-3', 'Middle Right-4',
                            'Right-1', 'Right-2', 'Right-3', 'Right-4'
                        ];
                        for (let i = 1; i <= 4; i++) {
                            // All rows show all position options
                            const optionsHtml = allPositionOptions.map(opt => `<option value="${opt}">${opt}</option>`).join('');
                            
                            html += `
                                <tr class=\"gsm-row\">
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <select class="posSelect" name="${fieldPrefix}_position_${i}" id="${fieldPrefix}_position_${i}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" onchange="updateThicknessDropdowns(); calculateThicknessAverages()">
                                            <option value="">-- Select Position --</option>
                                            ${optionsHtml}
                                        </select>
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="number" 
                                               class="under2kpa"
                                               name="${fieldPrefix}_under2kpa_${i}"
                                               step="any" max="1000" 
                                               min="0"
                                               
                                               style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;"
                                               onchange="calculateThicknessAverages()"
                                               oninput="if(this.value < 0) this.value = 0">
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa;">
                                        <span class="avgCell" style="font-weight: bold; color: #27ae60;">0.000</span>
                                    </td>
                                </tr>
                                ${i < 4 ? `
                                <tr>
                                    <td colspan="3" style="border: none; padding: 5px; text-align: center; background: #f8f9fa;">
                                        <button type="button" 
                                                onclick="addSingleThicknessRow(${i})"
                                                style="padding: 5px 12px; background:rgb(21, 75, 14); color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                            <i class="fa fa-plus"></i> Add 1 More Row
                                        </button>
                                    </td>
                                </tr>
                                ` : ''}
                            `;
                        }
                        
                        html += `
                                    </tbody>
                                </table>
                </div>
                                <!-- Add Row Button -->
                                <div style="margin: 15px 0; text-align: center;">
                                    <button type="button" 
                                            class="btn btn-sm btn-primary" 
                                            onclick="addSingleThicknessRow(4)"
                                            style="padding: 5px 12px; background:rgb(21, 75, 14); color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                        <i class="fa fa-plus"></i> Add 1 More Row
                                    </button>
                                </div>
                                        
                                <!-- Hidden fields for statistics -->
                                <input type="hidden" name="${fieldPrefix}_avg" id="${fieldPrefix}_avg">
                                <input type="hidden" name="${fieldPrefix}_sd" id="${fieldPrefix}_sd">
                                <input type="hidden" name="${fieldPrefix}_cv" id="${fieldPrefix}_cv">
                                <input type="hidden" name="${fieldPrefix}_max" id="${fieldPrefix}_max">
                                <input type="hidden" name="${fieldPrefix}_min" id="${fieldPrefix}_min">

                                <!-- Thickness Summary -->
                                <div style="margin-top: 20px;">
                                    <h3 style="text-align: center; margin-bottom: 15px;">Summary of Thickness (Under 2kPa)</h3>
                                    <div style="overflow-x: auto;">
                                        <table style="width: 100%; border-collapse: collapse; margin-top: 10px;">
                                            <thead>
                                                <tr style="background: #1976d2; color: #fff;">
                                                    <th style="border: 1px solid #ddd; padding: 10px; text-align: center;">Statistics</th>
                                                    <th style="border: 1px solid #ddd; padding: 10px; text-align: center;">Under 2kPa (mm)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">Average:</td>
                                                    <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                                                        <input type="text" id="thk_summary_avg" readonly class="readonly" style="width: 100%; padding: 5px; border: none; background: #f8f9fa; text-align: center;">
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">SD:</td>
                                                    <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                                                        <input type="text" id="thk_summary_sd" readonly class="readonly" style="width: 100%; padding: 5px; border: none; background: #f8f9fa; text-align: center;">
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">CV%:</td>
                                                    <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                                                        <input type="text" id="thk_summary_cv" readonly class="readonly" style="width: 100%; padding: 5px; border: none; background: #f8f9fa; text-align: center;">
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">Maximum:</td>
                                                    <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                                                        <input type="text" id="thk_summary_max" readonly class="readonly" style="width: 100%; padding: 5px; border: none; background: #f8f9fa; text-align: center;">
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">Minimum:</td>
                                                    <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                                                        <input type="text" id="thk_summary_min" readonly class="readonly" style="width: 100%; padding: 5px; border: none; background: #f8f9fa; text-align: center;">
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                        
                        // Initialize dropdowns for first 4 rows
                        updateThicknessDropdowns();
                    } else if (testConfig.type === 'table_gsm') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div class=\"gsm-card\" style=\"margin-bottom: 20px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; box-shadow: 0 2px 8px rgba(0,0,0,0.05);\"> 
                              <style>
                                #gsmTable_${suffixId} { width: 100%; border-collapse: separate; border-spacing: 0; }
                                #gsmTable_${suffixId} thead th {
                                    background: #2563eb;
                                    color: #ffffff;
                                    font-weight: 700;
                                    padding: 12px;
                                    border-bottom: 0;
                                    text-align: center;
                                    letter-spacing: 0.2px;
                                }
                                #gsmTable_${suffixId} td { padding: 10px; border-bottom: 1px solid #e5e7eb; }
                                #gsmTable_${suffixId} tr:last-child td { border-bottom: none; }
                                #gsmTable_${suffixId} input[type=number] { width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: #ffffff; }
                                #gsmTable_${suffixId} .avg-cell { text-align: center; }
                                #gsmTable_${suffixId} .calc-cell { text-align: center; }
                                #gsmTable_${suffixId} .avg-badge { display: inline-block; min-width: 64px; padding: 6px 12px; border-radius: 999px; background: #22c55e1a; color: #16a34a; font-weight: 700; border: 1px solid #86efac; }
                                #gsmTable_${suffixId} .group-header { background: #f1f5f9; color: #0f172a; font-weight: 700; text-align: left; border-left: 4px solid #3b82f6; padding: 8px 10px; }
                                #gsmTable_${suffixId} .btn-add {
                                    background: linear-gradient(90deg, #16a34a 0%, #22c55e 100%);
                                    color: #ffffff;
                                    border: none;
                                    padding: 6px 10px;
                                    border-radius: 6px;
                                    font-size: 12px;
                                    cursor: pointer;
                                    box-shadow: 0 3px 8px rgba(34, 197, 94, 0.2);
                                    transition: transform .08s ease, box-shadow .2s ease, filter .2s ease;
                                }
                                #gsmTable_${suffixId} .btn-add:hover { filter: brightness(1.05); box-shadow: 0 4px 10px rgba(34, 197, 94, 0.28); }
                                #gsmTable_${suffixId} .btn-add:active { transform: translateY(1px); }
                              </style>
                              <div style=\"overflow-x: auto;\"> 
                                <table id=\"gsmTable_${suffixId}\"> 
                                    <thead> 
                                        <tr> 
                                            <th>Position of Sampling</th> 
                                            <th>Weight (gm)</th> 
                                            <th>Calculated (GSM)</th> 
                                            <th>Average (GSM)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody id=\"gsmBody_${suffixId}\"></tbody> 
                                </table> 
                              </div> 
                              <div class=\"product-header\" style=\"margin-top: 16px; text-align: center;\">Summary of Test Result</div>
                              <div style=\"margin-top: 8px;\"> 
                                <table style=\"width: 100%; border-collapse: collapse;\"> 
                                    <thead> 
                                        <tr style=\"background: #d1fae5;\"> 
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: left;\">Statistics</th> 
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">Mass Per Unit Area (GSM)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">Average</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"gsm_sum_avg_${suffixId}\">0.00</span>
                                                <input type=\"hidden\" name=\"gsm_avg\" id=\"gsm_sum_avg_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">SD</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"gsm_sum_sd_${suffixId}\">0.00</span>
                                                <input type=\"hidden\" name=\"gsm_sd\" id=\"gsm_sum_sd_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">CV%</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"gsm_sum_cv_${suffixId}\">0.00</span>
                                                <input type=\"hidden\" name=\"gsm_cv\" id=\"gsm_sum_cv_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">Maximum</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"gsm_sum_max_${suffixId}\">0.00</span>
                                                <input type=\"hidden\" name=\"gsm_max\" id=\"gsm_sum_max_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">Minimum</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"gsm_sum_min_${suffixId}\">0.00</span>
                                                <input type=\"hidden\" name=\"gsm_min\" id=\"gsm_sum_min_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                    </tbody> 
                                </table> 
                              </div> 
                            </div> 
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                        if (typeof initGsmGrouped === 'function') {
                            initGsmGrouped(suffixId);
                        }
                        if (typeof applyLastGeneralInfo === 'function') { applyLastGeneralInfo(suffixId); }
                    } else if (testConfig.type === 'table_thickness_grouped') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div class=\"gsm-card\" style=\"margin-bottom: 20px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; box-shadow: 0 2px 8px rgba(0,0,0,0.05);\"> 
                              <style>
                                #thkTable_${suffixId} { width: 100%; border-collapse: separate; border-spacing: 0; }
                                #thkTable_${suffixId} thead th {
                                    background: #2563eb;
                                    color: #ffffff;
                                    font-weight: 700;
                                    padding: 12px;
                                    border-bottom: 0;
                                    text-align: center;
                                    letter-spacing: 0.2px;
                                }
                                #thkTable_${suffixId} td { padding: 10px; border-bottom: 1px solid #e5e7eb; }
                                #thkTable_${suffixId} tr:last-child td { border-bottom: none; }
                                #thkTable_${suffixId} input[type=number] { width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: #ffffff; }
                                #thkTable_${suffixId} .avg-cell { text-align: center; }
                                #thkTable_${suffixId} .avg-badge { display: inline-block; min-width: 64px; padding: 6px 12px; border-radius: 999px; background: #22c55e1a; color: #16a34a; font-weight: 700; border: 1px solid #86efac; }
                                #thkTable_${suffixId} .btn-add { background: linear-gradient(90deg, #16a34a 0%, #22c55e 100%); color: #ffffff; border: none; padding: 6px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; box-shadow: 0 3px 8px rgba(34, 197, 94, 0.2); transition: transform .08s ease, box-shadow .2s ease, filter .2s ease; }
                                #thkTable_${suffixId} .btn-add:hover { filter: brightness(1.05); box-shadow: 0 4px 10px rgba(34, 197, 94, 0.28); }
                                #thkTable_${suffixId} .btn-add:active { transform: translateY(1px); }
                              </style>
                              <div style=\"overflow-x: auto;\"> 
                                <table id=\"thkTable_${suffixId}\"> 
                                    <thead> 
                                        <tr> 
                                            <th>Position of Sampling</th> 
                                            <th>Under 2kPa (mm)</th> 
                                            <th>Average (GSM)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody id=\"thkBody_${suffixId}\"></tbody> 
                                </table> 
                              </div> 
                              <div class=\"product-header\" style=\"margin-top: 16px; text-align: center;\">Summary of Test Result</div>
                              <div style=\"margin-top: 8px;\"> 
                                <table style=\"width: 100%; border-collapse: collapse;\"> 
                                    <thead> 
                                        <tr style=\"background: #d1fae5;\"> 
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: left;\">Statistics</th> 
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">Thickness Test Under 2kPa (mm)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">Average</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"thk_sum_avg_${suffixId}\">0.000</span>
                                                <input type=\"hidden\" name=\"astmd5199_avg\" id=\"thk_sum_avg_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">SD</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"thk_sum_sd_${suffixId}\">0.000</span>
                                                <input type=\"hidden\" name=\"astmd5199_sd\" id=\"thk_sum_sd_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">CV%</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"thk_sum_cv_${suffixId}\">0.00</span>
                                                <input type=\"hidden\" name=\"astmd5199_cv\" id=\"thk_sum_cv_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">Maximum</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"thk_sum_max_${suffixId}\">0.000</span>
                                                <input type=\"hidden\" name=\"astmd5199_max\" id=\"thk_sum_max_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; font-weight: 600;\">Minimum</td> 
                                            <td style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">
                                                <span id=\"thk_sum_min_${suffixId}\">0.000</span>
                                                <input type=\"hidden\" name=\"astmd5199_min\" id=\"thk_sum_min_input_${suffixId}\" value=\"0\">
                                            </td> 
                                        </tr> 
                                    </tbody> 
                                </table> 
                              </div>
                            </div> 
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                        if (typeof initThicknessGrouped === 'function') {
                            initThicknessGrouped(suffixId);
                            if (typeof recalcThicknessSummary === 'function') {
                                recalcThicknessSummary(suffixId);
                            }
                        }
                        if (typeof applyLastGeneralInfo === 'function') { applyLastGeneralInfo(suffixId); }
                    } else if (testConfig.type === 'table_grab_grouped') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div class="gsm-card" style="margin-bottom: 20px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; box-shadow: 0 2px 8px rgba(0,0,0,0.05);"> 
                              <style>
                                #grabTable_${suffixId} { width: 100%; border-collapse: separate; border-spacing: 0; }
                                #grabTable_${suffixId} thead th {
                                    background: #2563eb;
                                    color: #ffffff;
                                    font-weight: 700;
                                    padding: 12px;
                                    border-bottom: 0;
                                    text-align: center;
                                    letter-spacing: 0.2px;
                                }
                                #grabTable_${suffixId} td { padding: 10px; border-bottom: 1px solid #e5e7eb; }
                                #grabTable_${suffixId} tr:last-child td { border-bottom: none; }
                                #grabTable_${suffixId} input[type=number] { width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: #ffffff; }
                                #grabTable_${suffixId} .btn-add { background: linear-gradient(90deg, #16a34a 0%, #22c55e 100%); color: #ffffff; border: none; padding: 6px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; box-shadow: 0 3px 8px rgba(34, 197, 94, 0.2); transition: transform .08s ease, box-shadow .2s ease, filter .2s ease; }
                                #grabTable_${suffixId} .btn-add:hover { filter: brightness(1.05); box-shadow: 0 4px 10px rgba(34, 197, 94, 0.28); }
                                #grabTable_${suffixId} .btn-add:active { transform: translateY(1px); }
                              </style>
                              <div style="overflow-x: auto;"> 
                                <table id="grabTable_${suffixId}"> 
                                    <thead> 
                                        <tr> 
                                            <th>Position of Sampling</th> 
                                            <th>Test Direction</th> 
                                            <th>Force (N)</th> 
                                            <th>Elongation (%)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody id="grabBody_${suffixId}"></tbody> 
                                </table> 
                              </div>
                              <div class="product-header" style="margin-top: 16px; text-align: center;">Summary of Test Result</div>
                              <div style="margin-top: 8px;"> 
                                <table style="width: 100%; border-collapse: collapse;"> 
                                    <thead> 
                                        <tr style="background: #d1fae5;"> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: left;">Grab Test</th> 
                                            <th colspan="2" style="border: 1px solid #ddd; padding: 6px; text-align: center;">MD</th> 
                                            <th colspan="2" style="border: 1px solid #ddd; padding: 6px; text-align: center;">CD</th> 
                                        </tr> 
                                        <tr style="background: #f0fdf4;"> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: left;"></th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Force (N)</th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Elongation (%)</th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Force (N)</th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Elongation (%)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Average:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_force_avg_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_elong_avg_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_force_avg_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_elong_avg_${suffixId}">0.00</span></td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">SD:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_force_sd_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_elong_sd_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_force_sd_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_elong_sd_${suffixId}">0.00</span></td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">CV%:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_force_cv_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_elong_cv_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_force_cv_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_elong_cv_${suffixId}">0.00</span></td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Maximum:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_force_max_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_elong_max_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_force_max_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_elong_max_${suffixId}">0.00</span></td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Minimum:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_force_min_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_md_elong_min_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_force_min_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="grab_sum_cd_elong_min_${suffixId}">0.00</span></td> 
                                        </tr> 
                                    </tbody> 
                                </table>
                                <!-- Hidden inputs for summary statistics -->
                                <div style="display:none;">
                                    <input type="hidden" name="grab_md_force_avg" id="grab_input_md_force_avg_${suffixId}">
                                    <input type="hidden" name="grab_md_force_sd" id="grab_input_md_force_sd_${suffixId}">
                                    <input type="hidden" name="grab_md_force_cv" id="grab_input_md_force_cv_${suffixId}">
                                    <input type="hidden" name="grab_md_force_max" id="grab_input_md_force_max_${suffixId}">
                                    <input type="hidden" name="grab_md_force_min" id="grab_input_md_force_min_${suffixId}">
                                    <input type="hidden" name="grab_md_elongation_avg" id="grab_input_md_elong_avg_${suffixId}">
                                    <input type="hidden" name="grab_md_elongation_sd" id="grab_input_md_elong_sd_${suffixId}">
                                    <input type="hidden" name="grab_md_elongation_cv" id="grab_input_md_elong_cv_${suffixId}">
                                    <input type="hidden" name="grab_md_elongation_max" id="grab_input_md_elong_max_${suffixId}">
                                    <input type="hidden" name="grab_md_elongation_min" id="grab_input_md_elong_min_${suffixId}">
                                    <input type="hidden" name="grab_cd_force_avg" id="grab_input_cd_force_avg_${suffixId}">
                                    <input type="hidden" name="grab_cd_force_sd" id="grab_input_cd_force_sd_${suffixId}">
                                    <input type="hidden" name="grab_cd_force_cv" id="grab_input_cd_force_cv_${suffixId}">
                                    <input type="hidden" name="grab_cd_force_max" id="grab_input_cd_force_max_${suffixId}">
                                    <input type="hidden" name="grab_cd_force_min" id="grab_input_cd_force_min_${suffixId}">
                                    <input type="hidden" name="grab_cd_elongation_avg" id="grab_input_cd_elong_avg_${suffixId}">
                                    <input type="hidden" name="grab_cd_elongation_sd" id="grab_input_cd_elong_sd_${suffixId}">
                                    <input type="hidden" name="grab_cd_elongation_cv" id="grab_input_cd_elong_cv_${suffixId}">
                                    <input type="hidden" name="grab_cd_elongation_max" id="grab_input_cd_elong_max_${suffixId}">
                                    <input type="hidden" name="grab_cd_elongation_min" id="grab_input_cd_elong_min_${suffixId}">
                                </div> 
                              </div>
                            </div> 
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                        if (typeof initGrabGrouped === 'function') {
                            initGrabGrouped(suffixId);
                        }
                        if (typeof recalcGrabSummary === 'function') {
                            recalcGrabSummary(suffixId);
                        }
                        if (typeof applyLastGeneralInfo === 'function') { applyLastGeneralInfo(suffixId); }
                    } else if (testConfig.type === 'table_strip_grouped') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div class="gsm-card" style="margin-bottom: 20px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; box-shadow: 0 2px 8px rgba(0,0,0,0.05);"> 
                              <style>
                                #stripTable_${suffixId} { width: 100%; border-collapse: separate; border-spacing: 0; }
                                #stripTable_${suffixId} thead th {
                                    background: #2563eb;
                                    color: #ffffff;
                                    font-weight: 700;
                                    padding: 12px;
                                    border-bottom: 0;
                                    text-align: center;
                                    letter-spacing: 0.2px;
                                }
                                #stripTable_${suffixId} td { padding: 10px; border-bottom: 1px solid #e5e7eb; }
                                #stripTable_${suffixId} tr:last-child td { border-bottom: none; }
                                #stripTable_${suffixId} input[type=number] { width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: #ffffff; }
                                #stripTable_${suffixId} .btn-add { background: linear-gradient(90deg, #16a34a 0%, #22c55e 100%); color: #ffffff; border: none; padding: 6px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; box-shadow: 0 3px 8px rgba(34, 197, 94, 0.2); transition: transform .08s ease, box-shadow .2s ease, filter .2s ease; }
                                #stripTable_${suffixId} .btn-add:hover { filter: brightness(1.05); box-shadow: 0 4px 10px rgba(34, 197, 94, 0.28); }
                                #stripTable_${suffixId} .btn-add:active { transform: translateY(1px); }
                              </style>
                              <div style="overflow-x: auto;"> 
                                <table id="stripTable_${suffixId}"> 
                                    <thead> 
                                        <tr> 
                                            <th>Position of Sampling</th> 
                                            <th>Test Direction</th> 
                                            <th>Strength (kN/m)</th> 
                                            <th>MD:CD Ratio</th> 
                                            <th>Elongation (%)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody id="stripBody_${suffixId}"></tbody> 
                                </table> 
                              </div>
                              <div class="product-header" style="margin-top: 16px; text-align: center;">Summary of Test Result</div>
                              <div style="margin-top: 8px;"> 
                                <table style="width: 100%; border-collapse: collapse;"> 
                                    <thead> 
                                        <tr style="background: #d1fae5;"> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: left;">Strip Tensile Strength Test</th> 
                                            <th colspan="2" style="border: 1px solid #ddd; padding: 6px; text-align: center;">MD</th> 
                                            <th colspan="2" style="border: 1px solid #ddd; padding: 6px; text-align: center;">CD</th> 
                                        </tr> 
                                        <tr style="background: #f0fdf4;"> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: left;"></th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Strength (kN/m)</th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Elongation (%)</th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Strength (kN/m)</th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Elongation (%)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Average:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_strength_avg_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_elong_avg_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_strength_avg_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_elong_avg_${suffixId}">0.00</span></td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">SD:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_strength_sd_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_elong_sd_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_strength_sd_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_elong_sd_${suffixId}">0.00</span></td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">CV%:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_strength_cv_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_elong_cv_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_strength_cv_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_elong_cv_${suffixId}">0.00</span></td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Maximum:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_strength_max_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_elong_max_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_strength_max_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_elong_max_${suffixId}">0.00</span></td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Minimum:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_strength_min_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_md_elong_min_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_strength_min_${suffixId}">0.00</span></td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;"><span id="strip_sum_cd_elong_min_${suffixId}">0.00</span></td> 
                                        </tr> 
                                    </tbody> 
                                </table>
                                <!-- Hidden inputs for summary statistics -->
                                <div style="display:none;">
                                    <input type="hidden" name="strip_md_strength_avg" id="strip_input_md_strength_avg_${suffixId}">
                                    <input type="hidden" name="strip_md_strength_sd" id="strip_input_md_strength_sd_${suffixId}">
                                    <input type="hidden" name="strip_md_strength_cv" id="strip_input_md_strength_cv_${suffixId}">
                                    <input type="hidden" name="strip_md_strength_max" id="strip_input_md_strength_max_${suffixId}">
                                    <input type="hidden" name="strip_md_strength_min" id="strip_input_md_strength_min_${suffixId}">
                                    <input type="hidden" name="strip_md_elongation_avg" id="strip_input_md_elong_avg_${suffixId}">
                                    <input type="hidden" name="strip_md_elongation_sd" id="strip_input_md_elong_sd_${suffixId}">
                                    <input type="hidden" name="strip_md_elongation_cv" id="strip_input_md_elong_cv_${suffixId}">
                                    <input type="hidden" name="strip_md_elongation_max" id="strip_input_md_elong_max_${suffixId}">
                                    <input type="hidden" name="strip_md_elongation_min" id="strip_input_md_elong_min_${suffixId}">
                                    <input type="hidden" name="strip_cd_strength_avg" id="strip_input_cd_strength_avg_${suffixId}">
                                    <input type="hidden" name="strip_cd_strength_sd" id="strip_input_cd_strength_sd_${suffixId}">
                                    <input type="hidden" name="strip_cd_strength_cv" id="strip_input_cd_strength_cv_${suffixId}">
                                    <input type="hidden" name="strip_cd_strength_max" id="strip_input_cd_strength_max_${suffixId}">
                                    <input type="hidden" name="strip_cd_strength_min" id="strip_input_cd_strength_min_${suffixId}">
                                    <input type="hidden" name="strip_cd_elongation_avg" id="strip_input_cd_elong_avg_${suffixId}">
                                    <input type="hidden" name="strip_cd_elongation_sd" id="strip_input_cd_elong_sd_${suffixId}">
                                    <input type="hidden" name="strip_cd_elongation_cv" id="strip_input_cd_elong_cv_${suffixId}">
                                    <input type="hidden" name="strip_cd_elongation_max" id="strip_input_cd_elong_max_${suffixId}">
                                    <input type="hidden" name="strip_cd_elongation_min" id="strip_input_cd_elong_min_${suffixId}">
                                </div> 
                              </div>
                            </div> 
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                        if (typeof initStripGrouped === 'function') {
                            initStripGrouped(suffixId);
                        }
                        if (typeof recalcStripSummary === 'function') {
                            recalcStripSummary(suffixId);
                        }
                        if (typeof applyLastGeneralInfo === 'function') { applyLastGeneralInfo(suffixId); }
                    } else if (testConfig.type === 'table_cbr_grouped') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div class="gsm-card" style="margin-bottom: 20px; padding: 16px; border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; box-shadow: 0 2px 8px rgba(0,0,0,0.05);"> 
                              <style>
                                #cbrTable_${suffixId} { width: 100%; border-collapse: separate; border-spacing: 0; }
                                #cbrTable_${suffixId} thead th {
                                    background: #2563eb;
                                    color: #ffffff;
                                    font-weight: 700;
                                    padding: 12px;
                                    border-bottom: 0;
                                    text-align: center;
                                    letter-spacing: 0.2px;
                                }
                                #cbrTable_${suffixId} td { padding: 10px; border-bottom: 1px solid #e5e7eb; }
                                #cbrTable_${suffixId} tr:last-child td { border-bottom: none; }
                                #cbrTable_${suffixId} input[type=number] { width: 100%; padding: 10px 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: #ffffff; }
                                #cbrTable_${suffixId} .btn-add { background: linear-gradient(90deg, #16a34a 0%, #22c55e 100%); color: #ffffff; border: none; padding: 6px 10px; border-radius: 6px; font-size: 12px; cursor: pointer; box-shadow: 0 3px 8px rgba(34, 197, 94, 0.2); transition: transform .08s ease, box-shadow .2s ease, filter .2s ease; }
                                #cbrTable_${suffixId} .btn-add:hover { filter: brightness(1.05); box-shadow: 0 4px 10px rgba(34, 197, 94, 0.28); }
                                #cbrTable_${suffixId} .btn-add:active { transform: translateY(1px); }
                              </style>
                              <div style="overflow-x: auto;"> 
                                <table id="cbrTable_${suffixId}"> 
                                    <thead> 
                                        <tr> 
                                            <th>Position of Sampling</th> 
                                            <th>Ultimate Force (N)</th> 
                                            <th>Ultimate Displacement (mm)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody id="cbrBody_${suffixId}"></tbody> 
                                </table> 
                              </div>
                              <div class="product-header" style="margin-top: 16px; text-align: center;">Summary of Test Result</div>
                              <div style="margin-top: 8px;"> 
                                <table style="width: 100%; border-collapse: collapse;"> 
                                    <thead> 
                                        <tr style="background: #d1fae5;"> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: left;">CBR Test</th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Ultimate Force (N)</th> 
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Ultimate Displacement (mm)</th> 
                                        </tr> 
                                    </thead> 
                                    <tbody> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Average:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_force_avg_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_force_avg" id="cbr_sum_force_avg_input_${suffixId}" value="0">
                                            </td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_displ_avg_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_displacement_avg" id="cbr_sum_displ_avg_input_${suffixId}" value="0">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">SD:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_force_sd_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_force_sd" id="cbr_sum_force_sd_input_${suffixId}" value="0">
                                            </td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_displ_sd_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_displacement_sd" id="cbr_sum_displ_sd_input_${suffixId}" value="0">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">CV%:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_force_cv_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_force_cv" id="cbr_sum_force_cv_input_${suffixId}" value="0">
                                            </td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_displ_cv_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_displacement_cv" id="cbr_sum_displ_cv_input_${suffixId}" value="0">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Maximum:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_force_max_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_force_max" id="cbr_sum_force_max_input_${suffixId}" value="0">
                                            </td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_displ_max_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_displacement_max" id="cbr_sum_displ_max_input_${suffixId}" value="0">
                                            </td> 
                                        </tr> 
                                        <tr> 
                                            <td style="border: 1px solid #ddd; padding: 6px; font-weight: 600;">Minimum:</td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_force_min_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_force_min" id="cbr_sum_force_min_input_${suffixId}" value="0">
                                            </td> 
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">
                                                <span id="cbr_sum_displ_min_${suffixId}">0.00</span>
                                                <input type="hidden" name="cbr_displacement_min" id="cbr_sum_displ_min_input_${suffixId}" value="0">
                                            </td> 
                                        </tr> 
                                    </tbody> 
                                </table> 
                              </div>
                            </div> 
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                        if (typeof initCbrGrouped === 'function') {
                            initCbrGrouped(suffixId);
                        }
                        if (typeof recalcCbrSummary === 'function') {
                            recalcCbrSummary(suffixId);
                        }
                        if (typeof applyLastGeneralInfo === 'function') { applyLastGeneralInfo(suffixId); }
                    } else if (testConfig.type === 'table_cut_length') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                              <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">SL No</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Parameter</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Test Standard</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Unit</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Test Result</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">1</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Cut Length</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">${method}</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">mm</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="cut_length_result" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="cut_length_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                              </div>
                            </div>
                        `;
                        
                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                    } else if (testConfig.type === 'table_fineness') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                              <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">SL No</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Parameter</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Test Standard</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Unit</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Test Result</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">1</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Unit Weight</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">EN ISO 1973</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">dTex</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="unit_weight_result" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="unit_weight_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                              </div>
                            </div>
                        `;
                        
                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                    } else if (testConfig.type === 'table_tenacity_fiber') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                              <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">SL No</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Parameter</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Test Standard</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Unit</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Test Result</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">1</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Tenacity at Break</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">EN ISO 5079</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">cN/dTex</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="tenacity_at_break" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="tenacity_at_break_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">2</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Std Deviation</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">EN ISO 5079</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">cN/dTex</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="std_deviation" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="std_deviation_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">3</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">CV%</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">EN ISO 5079</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">%</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="cv_percent" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="cv_percent_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">4</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Elongation at Break</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">EN ISO 5079</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">%</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="elongation_at_break" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="elongation_at_break_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">5</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Cross Section</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">-</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">-</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa;">
                                                <span style="font-weight: bold;">Round</span>
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="cross_section_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                              </div>
                              
                              <!-- Acceptance Criteria Table -->
                              <div style="margin-top: 20px;">
                                <h3 style="text-align: center; margin-bottom: 15px;">Acceptance Criteria</h3>
                                <div style="overflow-x: auto;">
                                  <table style="width: 100%; border-collapse: collapse;">
                                      <thead>
                                          <tr style="background: #1976d2; color: #fff;">
                                              <th style="border: 1px solid #ddd; padding: 10px; text-align: center;">Acceptance Range</th>
                                              <th style="border: 1px solid #ddd; padding: 10px; text-align: center;">High</th>
                                              <th style="border: 1px solid #ddd; padding: 10px; text-align: center;">Good</th>
                                              <th style="border: 1px solid #ddd; padding: 10px; text-align: center;">Medium</th>
                                              <th style="border: 1px solid #ddd; padding: 10px; text-align: center;">BWDB Requirements</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          <tr>
                                              <td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">Tenacity</td>
                                              <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">5.4+</td>
                                              <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">5+</td>
                                              <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">4.5+</td>
                                              <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">-</td>
                                          </tr>
                                          <tr>
                                              <td style="border: 1px solid #ddd; padding: 8px; font-weight: bold;">Elongation</td>
                                              <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">0.8</td>
                                              <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">0.6</td>
                                              <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">-</td>
                                              <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">60%+</td>
                                          </tr>
                                      </tbody>
                                  </table>
                                </div>
                              </div>
                            </div>
                        `;
                        
                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                    } else if (testConfig.type === 'table_tenacity_yarn') {
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                              <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">SL No</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Parameter</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Test Standard</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Unit</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Test Result</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">1</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Tenacity at Break</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">ASTM D2256</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">cN/dTex</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="yarn_tenacity_at_break" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="yarn_tenacity_at_break_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">2</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Std Deviation</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">ASTM D2256</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">cN/dTex</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="yarn_std_deviation" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="yarn_std_deviation_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">3</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">CV%</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">ASTM D2256</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">%</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="yarn_cv_percent" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="yarn_cv_percent_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa; font-weight: bold;">4</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">Elongation at Break</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">ASTM D2256</td>
                                            <td style="border: 1px solid #ddd; padding: 6px; text-align: center;">%</td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="number" name="yarn_elongation_at_break" step="any" min="0" placeholder="Enter result" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                            <td style="border: 1px solid #ddd; padding: 6px;">
                                                <input type="text" name="yarn_elongation_at_break_remarks" placeholder="Remarks" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                              </div>
                            </div>
                        `;
                        
                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                    } else if (testConfig.type === 'table_strip') {
                        // Generate Strip Tensile (ASTM D4595) table (without Sample Details/Common fields)
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            <div style=\"margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;\">
                              <div style=\"overflow-x: auto;\">
                                <table style=\"width: 100%; border-collapse: collapse;\">
                                    <thead>
                                        <tr style=\"background: #f8f9fa;\">
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">Position of Sampling</th>
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">Test Direction</th>
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">Strength (kN/m)</th>
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">MD:CD Ratio</th>
                                            <th style=\"border: 1px solid #ddd; padding: 6px; text-align: center;\">Elongation (%)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;
                        for (let i = 1; i <= 4; i++) {
                            const dir = (i % 2 === 1) ? 'MD' : 'CD';
                            html += `
                                <tr>
                                    <td style=\"border: 1px solid #ddd; padding: 6px;\">
                                        <input type="text" placeholder="Position ${i}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </td>
                                    <td style=\"border: 1px solid #ddd; padding: 6px; text-align:center;\">
                                        <input type="text" value="${dir}" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; text-align:center;">
                                    </td>
                                    <td style=\"border: 1px solid #ddd; padding: 6px;\">
                                        <input type="number" step="any" max="1000" min="0" class="strength_input" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" onchange="updateMdCdRatio(this)" oninput="if(this.value < 0) this.value = 0">
                                    </td>
                                    <td style=\"border: 1px solid #ddd; padding: 6px; text-align:center; background:#f8f9fa;\">
                                        <span class="mdcd_ratio" style="display:inline-block; padding:2px 8px; border-radius:12px; background:#eef3ff; border:1px solid #c9d6ff;">0</span>
                                    </td>
                                    <td style=\"border: 1px solid #ddd; padding: 6px;\">
                                        <input type="number" step="any" max="1000" min="0" class="elong_input" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </td>
                                </tr>
                            `;
                            // No partition between MD and CD rows within a pair
                        }
                        // Partition after four rows for Position column only
                        html += `
                                    <tr>
                                        <td style="border-top: 2px solid #999; height: 1px;"></td>
                                        <td style="border-top: none;"></td>
                                        <td style="border-top: none;"></td>
                                        <td style="border-top: none;"></td>
                                        <td style="border-top: none;"></td>
                                    </tr>
                                    </tbody>
                                </table>
                    </div>
                                <div style=\"margin: 15px 0; text-align: center;\">
                                    <button type="button" class="btn btn-sm btn-primary" onclick="addStripGroup()" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                        <i class="fa fa-plus"></i> Add Group of 4 Rows
                        </button>
                                </div>
                            </div>
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                    } else if (testConfig.type === 'table_cbr') {
                        // Generate CBR table similar to GSM pattern
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            ${isCommonFieldsTest(testName, method) ? buildCommonFieldsHtml(suffixId) : ''}
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                              <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Position of Sampling</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Ultimate Force (N)</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Ultimate Displacement (mm)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;
                        for (let i = 1; i <= 4; i++) {
                            html += `
                                <tr>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="text" placeholder="Position ${i}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="number" step="any" max="1000" min="0" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="number" step="any" max="1000" min="0" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                    </td>
                                </tr>
                            `;
                        }
                        html += `
                                    </tbody>
                                </table>
                                </div>
                                <div style="margin: 15px 0; text-align: center;">
                                    <button type="button" class="btn btn-sm btn-primary" onclick="addCbrGroup()" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                        <i class="fa fa-plus"></i> Add Group of 4 Rows
                                    </button>
                                    </div>
                                </div>
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                    } else if (testConfig.type === 'table_grab') {
                        // Generate Grab Tensile table similar to GSM pattern
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        let html = `
                            ${isCommonFieldsTest(testName, method) ? buildCommonFieldsHtml(suffixId) : ''}
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                              <div style="overflow-x: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead>
                                        <tr style="background: #f8f9fa;">
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Position of Sampling</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Force (N)</th>
                                            <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">Elongation (%)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;
                        for (let i = 1; i <= 4; i++) {
                            html += `
                                <tr>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="text" placeholder="Position ${i}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="number" step="any" max="1000" min="0" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="number" step="any" max="1000" min="0" placeholder="0.0000" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                    </td>
                                </tr>
                            `;
                        }
                        html += `
                                    </tbody>
                                </table>
                            </div>
                                <div style="margin: 15px 0; text-align: center;">
                                    <button type="button" class="btn btn-sm btn-primary" onclick="addGrabGroup()" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                        <i class="fa fa-plus"></i> Add Group of 4 Rows
                                    </button>
                        </div>
                                </div>
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
					} else if (testConfig.type === 'form_fields') {
                        // Custom form fields for Tenacity of Yarn with uniform styling
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        const testPrefix = 'ty';
                        const testParams = ['Denier','Tenacity at Break','Std Deviation','CV%','Elongation at Break'];
                        const testUnits = ['dTex', 'cN/dTex', '%', '%', '%'];
                        const testStandards = ['ISO 2060', 'ASTM D2256', 'ASTM D2256', 'ASTM D2256', 'ASTM D2256'];
                        
                        let html = `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px;">
                        `;
                        
                        testConfig.fields.forEach(field => {
                            const required = field.required ? 'required' : '';
                            const nowLocal = new Date();
                            const y = nowLocal.getFullYear();
                            const m = String(nowLocal.getMonth() + 1).padStart(2, '0');
                            const d = String(nowLocal.getDate()).padStart(2, '0');
                            const hh = String(nowLocal.getHours()).padStart(2, '0');
                            const mm = String(nowLocal.getMinutes()).padStart(2, '0');
                            const localDateTime = `${y}-${m}-${d}T${hh}:${mm}`;
                            const currentValue = field.value === 'current' ? localDateTime : '';
                            const valueAttr = currentValue ? `value="${currentValue}"` : '';
                            const unitText = field.unit ? ` (${field.unit})` : '';
                            
                            if (field.type === 'textarea') {
                                html += `
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">${field.name}${field.required ? ' *' : ''}:</label>
                                        <textarea ${required}
                                                  placeholder="Enter ${field.name.toLowerCase()}"
                                                  maxlength="255"
                                                  style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; min-height: 60px; resize: vertical;"></textarea>
                                </div>
                                `;
                            } else {
                                const stepAttr = field.step ? `step="${field.step}"` : '';
                                const maxAttr = field.max ? `max="${field.max}"` : '';
                                const minAttr = field.min ? `min="${field.min}"` : '';
                                                const placeholder = field.type === 'number' ? '' : '';
                                const negativeGuard = field.type === 'number' ? 'oninput="if(this.value < 0) this.value = 0"' : '';
                                
                                html += `
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">${field.name}${unitText}${field.required ? ' *' : ''}:</label>
                                        <input type="${field.type}"
                                               ${required}
                                               ${stepAttr}
                                               ${maxAttr}
                                               ${minAttr}
                                               ${valueAttr}
                                               ${placeholder}
                                               style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;"
                                               ${negativeGuard}>
                            </div>
                                `;
                            }
                        });
                        
                        html += `
                        </div>
                                <div style="margin-top: 15px;">
                                    <table style="width: 100%; border-collapse: collapse;">
                                        <thead>
                                            <tr style="background:#f8f9fa;">
                                                <th style="border:1px solid #ddd; padding:6px; text-align:center;">SI NO</th>
                                                <th style="border:1px solid #ddd; padding:6px; text-align:center;">Parameter</th>
                                                <th style="border:1px solid #ddd; padding:6px; text-align:center;">Test Standard</th>
                                                <th style="border:1px solid #ddd; padding:6px; text-align:center;">Unit</th>
                                                <th style="border:1px solid #ddd; padding:6px; text-align:center;">Test Result</th>
                                                <th style="border:1px solid #ddd; padding:6px; text-align:center;">Remarks</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${[1,2,3,4,5].map(i => `
                                                <tr>
                                                    <td style=\"border:1px solid #ddd; padding:6px; text-align:center;\">${i}</td>
                                                    <td style=\"border:1px solid #ddd; padding:6px;\"><input type=\"text\" name=\"${testPrefix}_param_${i}\" value=\"${testParams[i-1]}\" readonly class=\"readonly\" style=\"width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;\"></td>
                                                    <td style=\"border:1px solid #ddd; padding:6px;\"><input type=\"text\" name=\"${testPrefix}_std_${i}\" value=\"${testStandards[i-1]}\" readonly class=\"readonly\" style=\"width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;\"></td>
                                                    <td style=\"border:1px solid #ddd; padding:6px;\"><input type=\"text\" name=\"${testPrefix}_unit_${i}\" value=\"${testUnits[i-1]}\" readonly class=\"readonly\" style=\"width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;\"></td>
                                                    <td style=\"border:1px solid #ddd; padding:6px;\"><input type=\"number\" step=\"any\" max=\"1000\" min=\"0\" name=\"${testPrefix}_result_${i}\" style=\"width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;\" oninput=\"if(this.value < 0) this.value = 0\" required></td>
                                                    <td style=\"border:1px solid #ddd; padding:6px;\"><input type=\"text\" name=\"${testPrefix}_remarks_${i}\" placeholder=\"Remarks (optional)\" style=\"width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;\"></td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                    </div>
                            </div>
                        `;
						paramsContent.innerHTML = html;
						paramsDiv.style.display = 'block';
					} else if (testConfig.type === 'form_fields_weathering') {
						// Weathering Exposure Test custom layout
						const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
						const nowLocal = new Date();
						const y = nowLocal.getFullYear();
						const m = String(nowLocal.getMonth() + 1).padStart(2, '0');
						const d = String(nowLocal.getDate()).padStart(2, '0');
						const hh = String(nowLocal.getHours()).padStart(2, '0');
						const mm = String(nowLocal.getMinutes()).padStart(2, '0');
						const localDateTime = `${y}-${m}-${d}T${hh}:${mm}`;

						let html = `
							<div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
								<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px;">
									<div class="form-group"><label>Sample Received From *</label><input type="text" name="wx_sample_received_from_${suffixId}" required></div>
									<div class="form-group"><label>Sample Collected From *</label><input type="text" name="wx_sample_collected_from_${suffixId}" required></div>
									<div class="form-group"><label>Reference *</label>
										<select name="wx_reference_${suffixId}" required style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
											<option value="">-- Select Reference --</option>
											<?php foreach($references as $ref): ?>
												<option value="<?php echo htmlspecialchars($ref); ?>"><?php echo htmlspecialchars($ref); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="form-group" style="grid-column: 1 / -1;"><label>Sample Description *</label><input type="text" name="wx_sample_description_${suffixId}" required></div>
									<div class="form-group"><label>Recipe *</label><input type="text" name="wx_recipe_${suffixId}" required></div>
									<div class="form-group"><label>Received Date *</label><input type="datetime-local" name="wx_received_date_${suffixId}" value="${localDateTime}" required></div>
									<div class="form-group"><label>Test Start Date *</label><input type="date" name="wx_test_start_${suffixId}" id="wx_test_start_${suffixId}" onchange="validateWeatheringTestDate('${suffixId}')" required></div>
									<div class="form-group"><label>Test End Date *</label><input type="date" name="wx_test_end_${suffixId}" id="wx_test_end_${suffixId}" onchange="validateWeatheringTestDate('${suffixId}')" required></div>
									<div class="form-group"><label>Testing Method *</label><input type="text" name="wx_testing_method_${suffixId}" required></div>
									<div class="form-group"><label>Test Name *</label><input type="text" name="wx_test_name_${suffixId}" required></div>
                        </div>
								<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; margin-top: 10px;">
									<div class="form-group">
										<label>Test Speed / Gauge Length / Specimen Size *</label>
										<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
											<div style="display:flex; flex-direction:column; min-width:160px;">
												<small style="color:#6c757d; font-weight:600;">Test Speed</small>
												<input type="text" name="wx_test_speed_${suffixId}" required>
                                </div>
											<div style="display:flex; flex-direction:column; min-width:160px;">
\t\t\t\t\t\t\t\t\t\t\t<small style=\"color:#6c757d; font-weight:600;\">Gauge Length</small>
\t\t\t\t\t\t\t\t\t\t\t<input type=\"text\" name=\"wx_gauge_length_${suffixId}\" required>
\t\t\t\t\t\t\t\t\t\t</div>
											<div style="display:flex; flex-direction:column; min-width:200px; flex:1;">
												<small style="color:#6c757d; font-weight:600;">Specimen Size</small>
												<input type="text" name="wx_specimen_size_${suffixId}" style="width: 140px;" required>
											</div>
										</div>
									</div>
								</div>
								<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; margin-top: 10px;">
									<div class="form-group" style="grid-column: 1 / -1;"><label>Note (if any)</label><input type="text" name="wx_note_${suffixId}" maxlength="255" placeholder="Optional"></div>
									<div class="form-group"><label>Temperature (°C) *</label><input type="number" step="any" min="0" max="1000" name="wx_temperature_${suffixId}" oninput="if(this.value < 0) this.value = 0" required></div>
									<div class="form-group"><label>RH% *</label><input type="number" step="any" min="0" max="100" name="wx_rh_${suffixId}" oninput="if(this.value < 0) this.value = 0" required></div>
								</div>
								<div style="margin-top: 15px;">
									<table style="width:100%; border-collapse: collapse;">
										<thead>
											<tr style="background:#f8f9fa;">
												<th style="border:1px solid #ddd; padding:4px; text-align:center; width:60px; min-width:50px; white-space:nowrap;">Specimen No</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;">Test Direction</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;" colspan="2">Breaking Force (N)</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;">Force Retain (%)</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;" colspan="2">Elongation (%)</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;">Action</th>
											</tr>
											<tr style="background:#fafafa;">
												<th style="border:1px solid #ddd; padding:4px; text-align:center; width:60px; min-width:50px;"></th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;"></th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;">After</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;">Before</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;"></th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;">After</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;">Before</th>
												<th style="border:1px solid #ddd; padding:6px; text-align:center;"></th>
											</tr>
										</thead>
										<tbody id="wx_tbody_${suffixId}">
											<tr>
												<td style="border:1px solid #ddd; padding:6px; text-align:center;">1</td>
												<td style="border:1px solid #ddd; padding:6px; text-align:center;">
													<div style="display:inline-flex; gap:6px; align-items:center;">
														<button type="button" class="dir-btn" data-row="1" data-suffix="${suffixId}" onclick="setWxDirection(this,'MD')" style="padding:4px 10px; border:1px solid #ccc; border-radius:4px; background:#007bff; color:#fff;">MD1</button>
														<button type="button" class="dir-btn" data-row="1" data-suffix="${suffixId}" onclick="setWxDirection(this,'CD')" style="padding:4px 10px; border:1px solid #ccc; border-radius:4px; background:#fff; color:#2c3e50;">CD1</button>
														<input type="hidden" name="wx_dir_${suffixId}_1" value="MD">
													</div>
												</td>
												<td style="border:1px solid #ddd; padding:6px;"><input type="number" step="any" min="0" max="1000" name="wx_bf_after_${suffixId}_1" data-row="1" data-suffix="${suffixId}" class="wx_bf_after" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0; calculateForceRetainWeathering(this)"></td>
												<td style="border:1px solid #ddd; padding:6px;"><input type="number" step="any" min="0" max="1000" name="wx_bf_before_${suffixId}_1" data-row="1" data-suffix="${suffixId}" class="wx_bf_before" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0; calculateForceRetainWeathering(this)"></td>
												<td style="border:1px solid #ddd; padding:6px;"><input type="text" name="wx_fr_${suffixId}_1" class="wx_fr" readonly value="" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px; background:#f8f9fa;"></td>
												<td style="border:1px solid #ddd; padding:6px;"><input type="number" step="any" min="0" max="1000" name="wx_el_after_${suffixId}_1" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0"></td>
												<td style="border:1px solid #ddd; padding:6px;"><input type="number" step="any" min="0" max="1000" name="wx_el_before_${suffixId}_1" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0"></td>
												<td style="border:1px solid #ddd; padding:6px;"></td>
											</tr>
										</tbody>
									<tbody>
										${['Average','SD','CV','Maximum','Minimum'].map((label, idx) => `
											<tr>
												${idx === 0 ? `<td style="border:1px solid #ddd; padding:6px; text-align:center; font-weight:600;" rowspan="5">Total</td>` : ''}
												<td style="border:1px solid #ddd; padding:6px;">${label}</td>
												<td style="border:1px solid #ddd; padding:6px; text-align:center;"><span id="wx_stat_bf_after_${suffixId}_${label.toLowerCase()}"></span></td>
												<td style="border:1px solid #ddd; padding:6px; text-align:center;"><span id="wx_stat_bf_before_${suffixId}_${label.toLowerCase()}"></span></td>
												<td style="border:1px solid #ddd; padding:6px; text-align:center;"><span id="wx_stat_fr_${suffixId}_${label.toLowerCase()}"></span></td>
												<td style="border:1px solid #ddd; padding:6px; text-align:center;"><span id="wx_stat_el_after_${suffixId}_${label.toLowerCase()}"></span></td>
												<td style="border:1px solid #ddd; padding:6px; text-align:center;"><span id="wx_stat_el_before_${suffixId}_${label.toLowerCase()}"></span></td>
											</tr>
										`).join('')}
									</tbody>
									</table>
									<!-- Test Result summary table -->
									<div style="margin-top: 16px;">
										<h3 style="text-align:center; margin:8px 0;">Test Result</h3>
										<table style="width:100%; border-collapse: collapse;">
											<thead>
												<tr style="background:#f8f9fa;">
													<th style="border:1px solid #ddd; padding:6px; text-align:center;"></th>
													<th style="border:1px solid #ddd; padding:6px; text-align:center;">After Exposure</th>
													<th style="border:1px solid #ddd; padding:6px; text-align:center;">Before Exposure</th>
													<th style="border:1px solid #ddd; padding:6px; text-align:center;">Retain (%)</th>
												</tr>
											</thead>
											<tbody>
												<tr>
													<td style="border:1px solid #ddd; padding:6px;">Force (N)</td>
													<td style="border:1px solid #ddd; padding:6px; text-align:center;"><span id="wx_result_after_${suffixId}"></span></td>
													<td style="border:1px solid #ddd; padding:6px; text-align:center;"><span id="wx_result_before_${suffixId}"></span></td>
													<td style="border:1px solid #ddd; padding:6px; text-align:center;"><span id="wx_result_retain_${suffixId}"></span></td>
												</tr>
											</tbody>
										</table>
									</div>
									<div style="margin-top: 10px;">
										<button type="button" onclick="addWxRow('${suffixId}')" style="padding:8px 16px; background:#28a745; color:#fff; border:none; border-radius:4px; cursor:pointer; font-weight:600;">Add 1 More Row</button>
									</div>
								</div>
							</div>
						`;

						paramsContent.innerHTML = html;
						paramsDiv.style.display = 'block';
                    } else if (testConfig.type === 'form_iso12956') {
                        // ISO 12956 Determination of the Characteristics test
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        const now = new Date();
                        const yyyy = now.getFullYear();
                        const mm = String(now.getMonth() + 1).padStart(2, '0');
                        const dd = String(now.getDate()).padStart(2, '0');
                        const hh = String(now.getHours()).padStart(2, '0');
                        const min = String(now.getMinutes()).padStart(2, '0');
                        const currentDateTime = `${yyyy}-${mm}-${dd}T${hh}:${min}`;
                        const currentDate = `${yyyy}-${mm}-${dd}`;

                        let html = `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                                <h3 style="margin-top: 0; color: #2c3e50;">Test Parameters</h3>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px;">
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Test Materials:</label>
                                        <input type="text" name="iso12956_materials_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">GSM:</label>
                                        <input type="number" step="0.0001" min="0" name="iso12956_gsm_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Roll Number:</label>
                                        <input type="text" name="iso12956_roll_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Lab Test No:</label>
                                        <input type="text" name="iso12956_line_${suffixId}" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; background:#f8f9fa;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Sample ID:</label>
                                        <input type="text" name="iso12956_sampleid_${suffixId}" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Specimens Size:</label>
                                        <div style="display: flex; gap: 5px;">
                                            <input type="number" step="0.0001" min="0" name="iso12956_specimens_${suffixId}" required style="flex: 1; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                            <select name="iso12956_specimens_unit_${suffixId}" style="width: 60px; padding: 5px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px;">
                                                <option value="mm">mm</option>
                                                <option value="mm2">mm2</option>
                                                <option value="cm">cm</option>
                                                <option value="m">m</option>
                                                <option value="in">in</option>
                                                <option value="ft">ft</option>
                                                    </select>
                                                </div>
                                            </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Sand Type:</label>
                                        <input type="text" name="iso12956_sandtype_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Total Sand Weight:</label>
                                        <div style="display: flex; gap: 5px;">
                                            <input type="number" step="0.0001" min="0" name="iso12956_sandweight_${suffixId}" required style="flex: 1; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0; calcIso12956('${suffixId}')">
                                            <select name="iso12956_sandweight_unit_${suffixId}" style="width: 60px; padding: 5px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px;">
                                                <option value="g">g</option>
                                                <option value="kg">kg</option>
                                                <option value="mg">mg</option>
                                                <option value="lb">lb</option>
                                                <option value="oz">oz</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Sieving time:</label>
                                        <div style="display: flex; gap: 5px;">
                                            <input type="number" step="0.0001" min="0" name="iso12956_sievingtime_${suffixId}" required style="flex: 1; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                            <select name="iso12956_sievingtime_unit_${suffixId}" style="width: 60px; padding: 5px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px;">
                                                <option value="min">min</option>
                                                <option value="s">s</option>
                                                <option value="h">h</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Checked By:</label>
                                        <input type="text" name="iso12956_checkedby_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Sample Received:</label>
                                        <input type="date" name="iso12956_received_${suffixId}" value="${currentDate}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Sample Tested:</label>
                                        <input type="datetime-local" name="iso12956_tested_${suffixId}" value="${currentDateTime}" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa;">
                        </div>
                    </div>

                                <!-- Sieve Analysis Data Table -->
                                <div style="margin-top: 20px;">
                                    <h3 style="text-align: center; margin-bottom: 15px; color: #2c3e50;">Sieve Analysis Data</h3>
                                    <div style="overflow-x: auto;">
                                        <table id="iso12956_table_${suffixId}" style="width: 100%; border-collapse: collapse;">
                                            <thead>
                                                <tr style="background: #f8f9fa;">
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center;">Sieve Size(mm)</th>
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center;">Retained</th>
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center;">Cumulative</th>
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center;">Cumulative Passing(%)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                        `;
                        
                        // Generate 8 rows for sieve analysis data
                        for (let i = 1; i <= 8; i++) {
                            html += `
                                <tr>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="number" step="0.0001" min="0" name="iso12956_sievesize_${suffixId}_${i}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px;">
                                        <input type="number" step="0.0001" min="0" name="iso12956_retained_${suffixId}_${i}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0; calcIso12956('${suffixId}')">
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa;">
                                        <span id="iso12956_cum_${suffixId}_${i}" style="font-weight: bold; color: #27ae60;">0.00</span>
                                    </td>
                                    <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa;">
                                        <span id="iso12956_pass_${suffixId}_${i}" style="font-weight: bold; color: #27ae60;">0.00</span>
                                    </td>
                                </tr>
                            `;
                        }
                        
                        html += `
                                            </tbody>
                                        </table>
                                        </div>
                                        
                                    <!-- Calculation Section -->
                                    <div style="margin-top: 20px; padding: 15px; border: 2px solid #27ae60; border-radius: 8px; background: #d5f4e6;">
                                        <h3 style="text-align: center; margin-bottom: 15px; color: #2c3e50;"> Calculation:</h3>
                                        <div style="margin-bottom: 15px;">
                                            <label style="font-size: 14px; font-weight: bold; margin-bottom: 8px; display: block;">Enter O-Value (%):</label>
                                            <div style="display: flex; gap: 10px; align-items: center;">
                                                <input type="number" id="o_value_input_${suffixId}" placeholder="e.g., 90" min="0" max="100" step="0.1" style="width: 100px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                                <button type="button" onclick="calculateOpeningSize('${suffixId}')" style="padding: 8px 16px; background: #27ae60; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold;">
                                                    Calculate
                        </button>
                                                </div>
        </div>
                                        <div id="opening_size_results_${suffixId}" style="display: none;">
                                            <div style="background: white; padding: 15px; border-radius: 6px; margin-bottom: 10px;">
                                                <h4 style="margin-top: 0; color: #2c3e50;">Calculation Details:</h4>
                                                <div id="calculation_details_${suffixId}" style="line-height: 1.6; font-size: 14px;"></div>
    </div>
                                            <div style="background: #e8f5e9; padding: 15px; border-radius: 6px; margin-bottom: 10px; border: 2px solid #4caf50;">
                                                <h4 style="margin-top: 0; color: #2c3e50;">Results:</h4>
                                                <div id="final_result_${suffixId}" style="font-size: 16px; font-weight: bold; color: #27ae60;"></div>
                                            </div>
                                            <div style="background: #f8f9fa; padding: 10px; border-radius: 6px; font-size: 12px; color: #6c757d;">
                                                <strong>Note:</strong> O90 corresponds to 90% passing as per ISO 12956 standard. It represents the Apparent Opening Size (AOS) — the pore size through which 90% of particles can pass.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                        
                        // Add event listeners for auto-generation
                        addIso12956EventListeners(suffixId);
                    } else if (testConfig.type === 'form_iso11058') {
                        // ISO 11058 Water Permeability Test
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        const now = new Date();
                        const yyyy = now.getFullYear();
                        const mm = String(now.getMonth() + 1).padStart(2, '0');
                        const dd = String(now.getDate()).padStart(2, '0');
                        const currentDate = `${yyyy}-${mm}-${dd}`;

                        let html = `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                                <h3 style="margin-top: 0; color: #2c3e50;">Test Parameters</h3>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px;">
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Test Name:</label>
                                        <input type="text" name="iso11058_testname_${suffixId}" value="Water Permeability Characteristics Normal to the Plane, Without Load" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Specimen Area, A:</label>
                                        <div style="display: flex; gap: 5px;">
                                            <input type="number" step="0.0001" min="0" name="iso11058_area_${suffixId}" required style="flex: 1; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                            <select name="iso11058_area_unit_${suffixId}" style="width: 60px; padding: 5px; border: 1px solid #ddd; border-radius: 4px; font-size: 12px;">
                                                <option value="mm2">mm2</option>
                                                <option value="cm2">cm2</option>
                                                <option value="m2">m2</option>
                                                    </select>
                                                </div>
                                            </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Water Temperature:</label>
                                        <div style="display: flex; gap: 5px; align-items: center;">
                                            <input type="number" step="0.0001" min="0" name="iso11058_watertemp_${suffixId}" required style="flex: 1; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                                            <span style="font-size: 12px;">°C</span>
                                    </div>
                        </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Correction Factor:</label>
                                        <input type="number" step="0.0001" min="0" name="iso11058_correction_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Water Type:</label>
                                        <input type="text" name="iso11058_watertype_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">GSM:</label>
                                        <input type="number" step="0.0001" min="0" name="iso11058_gsm_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0; iso11058_updateReport('${suffixId}')">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Lab Test No:</label>
                                        <input type="text" id="iso11058_labtest_${suffixId}" name="iso11058_labtest_${suffixId}" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Roll number:</label>
                                        <input type="text" name="iso11058_roll_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="iso11058_updateReport('${suffixId}')">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Test Report No:</label>
                                        <input type="text" name="iso11058_report_${suffixId}" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Test Date:</label>
                                        <input type="date" name="iso11058_testdate_${suffixId}" value="${currentDate}" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; background: #f8f9fa;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Number of Specimens:</label>
                                        <input type="number" step="1" min="1" name="iso11058_numspec_${suffixId}" required style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 1) this.value = 1">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Relative Humidity:</label>
                                        <input type="text" name="iso11058_rh_${suffixId}" placeholder="50~60%" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 5px;">
                                        <label style="font-size: 12px; font-weight: bold;">Dissolved Oxygen Values:</label>
                                        <div style="display:flex; align-items:center; gap:6px;">
                                            <input type="text" name="iso11058_do_${suffixId}" placeholder="<5" style="flex:1; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                            <span style="font-size:12px; color:#2c3e50;">ppm</span>
                                        </div>
                                    </div>
                                </div>
                                <div style="margin-top: 8px; font-size: 12px; color: #2c3e50;">Test specimen were sampled and tested as instructed by the reference standard.</div>

                                <div style="margin-top: 16px; padding: 12px; border: 1px solid #bbb; border-radius: 6px; background: #fcfcfc;">
                                    <h4 style="margin: 0 0 10px 0; color: #2c3e50;">Calculation Inputs</h4>
                                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px;">
                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                            <label style="font-size: 12px; font-weight: bold;">Exposed area of the test specimen (a)</label>
                                            <input type="number" step="0.0001" min="0" name="iso11058_area_specimen_${suffixId}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="recalculateAllRows('${suffixId}')">
                    </div>
                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                            <label style="font-size: 12px; font-weight: bold;">Exposed area of stand pipe (m2)</label>
                                            <input type="number" step="0.0001" min="0" name="iso11058_area_pipe_${suffixId}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="recalculateAllRows('${suffixId}')">
        </div>
                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                            <label style="font-size: 12px; font-weight: bold;">Laboratory Temperature °C</label>
                                            <input type="number" step="0.0001" min="0" name="iso11058_labtemp_${suffixId}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
    </div>
                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                            <label style="font-size: 12px; font-weight: bold;">Avg. Water Temperature °C</label>
                                            <input type="number" step="0.0001" min="0" name="iso11058_avgwatertemp_${suffixId}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                        </div>
                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                            <label style="font-size: 12px; font-weight: bold;">Avg. Correction Factor</label>
                                            <input type="number" step="0.0001" min="0" name="iso11058_avgcorr_${suffixId}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                        </div>
                                    </div>
                                </div>

                                <!-- Experimental Data and Calculations Table -->
                                <div style="margin-top: 20px; padding: 15px; border: 1px solid #bbb; border-radius: 6px; background: #fcfcfc;">
                                    <h4 style="margin: 0 0 15px 0; color: #2c3e50;">Table: Experimental data and calculations for a geotextile or geatexile related product specimen (falling head method)</h4>
                                    
                                    <div style="overflow-x: auto;">
                                        <table style="width: 100%; border-collapse: collapse; border: 1px solid #ddd; font-size: 12px;">
                                            <thead>
                                                <tr style="background: #f8f9fa;">
                                                    <!-- Chosen Water Level Interval -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" colspan="5">Chosen Water Level Interval</th>
                                                    <!-- Thickness -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" rowspan="3">Thickness</th>
                                                    <!-- Water Level at v=0 -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" rowspan="3">Water Level at v=0</th>
                                                    <!-- Temp -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" rowspan="3">Temp.</th>
                                                    <!-- Correction Factor -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" rowspan="3">Correction Factor</th>
                                                    <!-- Head Difference -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" rowspan="3">?h (m)</th>
                                                    <!-- Time -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" rowspan="3">Time</th>
                                                    <!-- Velocity -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" rowspan="3">Velocity (m/s×10?³)</th>
                                                    <!-- Permeability -->
                                                    <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;" rowspan="3">Permeability 10?³(m/s)</th>
                                                </tr>
                                                <tr style="background: #f8f9fa;">
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;" rowspan="2">No</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;" colspan="2">Upper Limit</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;" colspan="2">Lower Limit</th>
                                                </tr>
                                                <tr style="background: #f8f9fa;">
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">h0 (m)</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">t1 (s)</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">h1 (m)</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">t2 (s)</th>
                                                </tr>
                                                <tr style="background: #f8f9fa;">
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;"></th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;"></th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;"></th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;"></th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;"></th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">mm</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">h0 (m)</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">T (°C)</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">RT</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">(m)</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">(s)</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">V20</th>
                                                    <th style="border: 1px solid #ddd; padding: 6px; text-align: center;">K</th>
                                                </tr>
                                            </thead>
                                            <tbody id="experimental_data_body_${suffixId}">
                                                <!-- Data rows will be dynamically added here -->
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" name="exp_no_1_${suffixId}" value="1" readonly style="width: 40px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h0_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 1)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t1_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 1)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h1_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 1)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t2_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 1)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_thickness_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 1)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_water_level_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 1)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_temp_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 1)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_correction_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 1)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_head_diff_1_${suffixId}" readonly style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_time_1_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_velocity_1_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_permeability_1_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" name="exp_no_2_${suffixId}" value="2" readonly style="width: 40px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h0_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 2)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t1_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 2)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h1_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 2)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t2_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 2)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_thickness_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 2)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_water_level_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 2)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_temp_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 2)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_correction_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 2)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_head_diff_2_${suffixId}" readonly style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_time_2_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_velocity_2_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_permeability_2_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" name="exp_no_3_${suffixId}" value="3" readonly style="width: 40px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h0_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 3)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t1_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 3)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h1_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 3)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t2_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 3)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_thickness_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 3)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_water_level_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 3)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_temp_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 3)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_correction_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 3)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_head_diff_3_${suffixId}" readonly style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_time_3_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_velocity_3_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_permeability_3_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" name="exp_no_4_${suffixId}" value="4" readonly style="width: 40px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h0_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 4)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t1_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 4)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h1_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 4)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t2_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 4)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_thickness_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 4)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_water_level_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 4)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_temp_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 4)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_correction_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 4)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_head_diff_4_${suffixId}" readonly style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_time_4_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_velocity_4_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_permeability_4_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" name="exp_no_5_${suffixId}" value="5" readonly style="width: 40px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h0_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 5)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t1_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 5)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_h1_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 5)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_t2_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 5)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_thickness_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 5)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_water_level_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 5)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_temp_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 5)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_correction_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', 5)">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_head_diff_5_${suffixId}" readonly style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.1" name="exp_time_5_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_velocity_5_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                    <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                                                        <input type="number" step="0.0001" name="exp_permeability_5_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <div style="margin-top: 10px; text-align: center;">
                                        <button type="button" onclick="addExperimentalRow('${suffixId}')" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                            Add Row
                                        </button>
                                        <button type="button" onclick="removeExperimentalRow('${suffixId}')" style="padding: 8px 16px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px; margin-left: 10px;">
                                            Remove Last Row
                                        </button>
                                    </div>
                                    
                                    <!-- Summary Results Table -->
                                    <div style="margin-top: 20px; padding: 15px; border: 1px solid #bbb; border-radius: 6px; background: #fff;">
                                        <h4 style="margin: 0 0 15px 0; color: #2c3e50;">Summary Results</h4>
                                        
                                        <div style="overflow-x: auto;">
                                            <table style="width: 100%; border-collapse: collapse; border: 1px solid #ddd; font-size: 12px;">
                                                <thead>
                                                    <tr style="background: #f8f9fa;">
                                                        <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;">Parameter</th>
                                                        <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;">Formula</th>
                                                        <th style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #e9ecef;">Average Value</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center; font-weight: bold;">Permeability, k</td>
                                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">K = (a × L) / (A × t) × log10(h0/h1) × RT</td>
                                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                                                            <input type="text" name="avg_permeability_${suffixId}" readonly id="avg_permeability_${suffixId}" style="width: 100%; padding: 4px; border: 1px solid #ddd; text-align: center; background: #f8f9fa; font-weight: bold;">
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center; font-weight: bold;">Flow Velocity, V20</td>
                                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">V20 = ?h/t × RT</td>
                                                        <td style="border: 1px solid #ddd; padding: 8px; text-align: center;">
                                                            <input type="text" name="avg_velocity_${suffixId}" readonly id="avg_velocity_${suffixId}" style="width: 100%; padding: 4px; border: 1px solid #ddd; text-align: center; background: #f8f9fa; font-weight: bold;">
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;

                        paramsContent.innerHTML = html;
                        paramsDiv.style.display = 'block';
                        
                        // Auto-generate Lab Test No from Sample Reference ID
                        const sampleRef = document.getElementById('sample_reference_id')?.value || '';
                        const labTestField = document.getElementById(`iso11058_labtest_${suffixId}`);
                        if (labTestField && sampleRef) {
                            const match = sampleRef.match(/-(\d+)$/);
                            if (match) {
                                const num = parseInt(match[1]);
                                labTestField.value = num > 99 ? match[1].slice(-3) : match[1].slice(-2);
                            }
                        }
                        
                        iso11058_updateReport(suffixId);
                    } else {
                        // Regular parameter inputs - compact uniform card layout
                        const suffixId = `${method.replace(/\s+/g,'_').toLowerCase()}`;
                        const params = testConfig;
                        let html = '';
                        if (isCommonFieldsTest(testName, method)) {
                            html += buildCommonFieldsHtml(suffixId);
                        }
                        html += `
                            <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 12px;">
                        `;
                        
                        params.forEach(param => {
                            const isReadonly = param.readonly ? 'readonly' : '';
                            const value = param.value ? `value="${param.value}"` : '';
                            const calculated = param.calculated ? 'class="calculated-field"' : '';
                                    const placeholder = param.type === 'number' ? `` : `placeholder="Enter ${param.name.toLowerCase()}"`;
                            const unitText = param.unit ? ` (${param.unit})` : '';
                            const negativeGuard = param.type === 'number' ? 'oninput="if(this.value < 0) this.value = 0"' : '';
                            
                            html += `
                                <div style="display: flex; flex-direction: column; gap: 5px;">
                                    <label style="font-size: 12px; font-weight: bold;">${param.name}${unitText}:</label>
                                    <input type="${param.type}" 
                                           step="${param.step || ''}" 
                                           ${isReadonly} 
                                           ${value} 
                                           ${calculated}
                                           ${placeholder}
                                           style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;"
                                           ${negativeGuard}>
                                </div>
                            `;
                        });
                        
                        html += `
                                </div>
                            </div>
                        `;
						paramsContent.innerHTML = html;
						paramsDiv.style.display = 'block';
						
						// Initial compute of stats after render
						const anyInput = paramsDiv.querySelector('input[name^="wx_bf_after_"]');
						if (anyInput) {
                            recalcWeatheringStats(paramsDiv, suffixId);
                        }
                    }
                } else {
                    paramsContent.innerHTML = '<p style="color: red;">?? No parameters defined for this test method.</p>';
                    paramsDiv.style.display = 'block';
                }
            } else {
                // Hide parameters
                paramsDiv.style.display = 'none';
            }
        }

        // Calculate averages for thickness test - show average in 4th row of each group
        function calculateThicknessAverages() {
            const table = document.querySelector('.test-parameters table tbody');
            if (!table) return;
            
            const rows = Array.from(table.querySelectorAll('tr.gsm-row'));
            
            // Group rows into groups of 4
            for (let groupIndex = 0; groupIndex < rows.length; groupIndex += 4) {
                const groupRows = rows.slice(groupIndex, groupIndex + 4);
                let groupTotal = 0, groupCount = 0;
                
                // Calculate average for this group
                groupRows.forEach((row, rowIdx) => {
                    const input = row.querySelector('.under2kpa');
                    const value = parseFloat(input?.value || 0);
                    if (value > 0) {
                        groupTotal += value;
                        groupCount += 1;
                    }
                });
                
                const groupAvg = (groupCount > 0) ? (groupTotal / groupCount).toFixed(3) : '0.000';
                
                // Find the last filled row in this group
                let lastFilledRowIndex = -1;
                groupRows.forEach((row, index) => {
                    const value = parseFloat(row.querySelector('.under2kpa')?.value || 0);
                    if (value > 0) {
                        lastFilledRowIndex = index;
                    }
                });
                
                // Show average only in the last filled row of this group
                if (lastFilledRowIndex >= 0 && groupRows[lastFilledRowIndex]) {
                    const avgCell = groupRows[lastFilledRowIndex].querySelector('.avgCell');
                    if (avgCell) {
                        avgCell.textContent = groupAvg;
                    }
                }
                
                // Clear averages for other rows in this group
                groupRows.forEach((row, index) => {
                    if (index !== lastFilledRowIndex) {
                        const avgCell = row.querySelector('.avgCell');
                        if (avgCell) {
                            avgCell.textContent = '';
                        }
                    }
                });
            }

            // Calculate Average, SD, CV%, Max, Min
            const validValues = Array.from(rows)
                .map(row => parseFloat(row.querySelector('.under2kpa')?.value || 0))
                .filter(v => v > 0);
            
            if (validValues.length > 0) {
                // Average
                const overallAvg = (validValues.reduce((a,b)=>a+b,0) / validValues.length).toFixed(3);
                const avgNum = parseFloat(overallAvg);
                const avgHidden = document.getElementById('astmd5199_avg');
                if (avgHidden) avgHidden.value = overallAvg;
                const avgOut = document.getElementById('thk_summary_avg');
                if (avgOut) avgOut.value = overallAvg;
                
                // Standard Deviation
                const squareDiffs = validValues.map(val => Math.pow(val - avgNum, 2));
                const variance = squareDiffs.reduce((a, b) => a + b, 0) / validValues.length;
                const sd = Math.sqrt(variance);
                const sdStr = sd.toFixed(3);
                const sdHidden = document.getElementById('astmd5199_sd');
                if (sdHidden) sdHidden.value = sdStr;
                const sdOut = document.getElementById('thk_summary_sd');
                if (sdOut) sdOut.value = sdStr;
                
                // CV%
                const cv = (sd / (avgNum || 1)) * 100;
                const cvStr = cv.toFixed(2);
                const cvHidden = document.getElementById('astmd5199_cv');
                if (cvHidden) cvHidden.value = cvStr;
                const cvOut = document.getElementById('thk_summary_cv');
                if (cvOut) cvOut.value = cvStr;
                
                // Max and Min
                const max = Math.max(...validValues);
                const min = Math.min(...validValues);
                const maxStr = max.toFixed(3);
                const minStr = min.toFixed(3);
                const maxHidden = document.getElementById('astmd5199_max');
                if (maxHidden) maxHidden.value = maxStr;
                const minHidden = document.getElementById('astmd5199_min');
                if (minHidden) minHidden.value = minStr;
                const maxOut = document.getElementById('thk_summary_max');
                if (maxOut) maxOut.value = maxStr;
                const minOut = document.getElementById('thk_summary_min');
                if (minOut) minOut.value = minStr;
            }
        }

        // Group-wise average that updates only the last row in each group
        document.addEventListener('input', function(e) {
            if (e.target.classList && (e.target.classList.contains('weight') || e.target.classList.contains('position'))) {
                calculateGroupWiseAverage();
            }
        });

        function getGroupKey(positionValue) {
            if (!positionValue) return '';
            if (positionValue.startsWith('Middle Left')) return 'Middle Left';
            if (positionValue.startsWith('Middle Right')) return 'Middle Right';
            if (positionValue.startsWith('Left')) return 'Left';
            if (positionValue.startsWith('Right')) return 'Right';
            return '';
        }

        function calculateGroupWiseAverage() {
            const rows = Array.from(document.querySelectorAll('#gsmTable .gsm-row'));

            // Clear all avg cells first
            rows.forEach(r => { const c = r.querySelector('.gsm-avg'); if (c) c.textContent = ''; });

            const group = {
                'Left': { values: [], lastRow: null },
                'Middle Left': { values: [], lastRow: null },
                'Middle Right': { values: [], lastRow: null },
                'Right': { values: [], lastRow: null }
            };

            rows.forEach(r => {
                const posVal = (r.querySelector('.position')?.value || '').trim();
                const key = getGroupKey(posVal);
                if (!key) return;
                const val = parseFloat(r.querySelector('.weight')?.value || '');
                if (!isNaN(val) && val > 0) group[key].values.push(val);
                group[key].lastRow = r; // track last occurrence
            });

            Object.keys(group).forEach(k => {
                const { values, lastRow } = group[k];
                if (!lastRow) return;
                const cell = lastRow.querySelector('.gsm-avg');
                if (!cell) return;
                if (values.length === 0) { cell.textContent = '0.0'; return; }
                const avg = values.reduce((a,b)=>a+b,0)/values.length;
                cell.textContent = avg.toFixed(1);
            });
        }

        function calculateGsmSummary(values) {
            // Filter valid values (greater than 0)
            const validValues = values.filter(v => v > 0);
            
            // GSM summary not needed; no-op to avoid errors
                return;
            }
            
        function addGsmRowAfter(button) {
            const buttonRow = button.closest('tr');
            if (!buttonRow) return;
            const tbody = buttonRow.closest('tbody');
            if (!tbody) return;
            const dataRow = buttonRow.previousElementSibling;
            if (!dataRow) return;
            const firstDataRow = tbody.querySelector('tr');

            // Build a new data row (plain inputs)
            const newDataRow = document.createElement('tr');
            const isAfterFirst = (dataRow === firstDataRow);
            const positionCellHtml = isAfterFirst
                ? `<select style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                     <option value="Left-1">Left-1</option>
                     <option value="Left-2">Left-2</option>
                     <option value="Left-3">Left-3</option>
                     <option value="Left-4">Left-4</option>
                   </select>`
                : `<input type="text" placeholder="Position" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">`;

            newDataRow.innerHTML = `
                <td style="border: 1px solid #ddd; padding: 6px;">${positionCellHtml}</td>
                <td style="border: 1px solid #ddd; padding: 6px;">
                    <input type="number" class="weight" step="any" max="1000" min="0" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" onchange="calculateGroupWiseAverage()" oninput="if(this.value < 0) this.value = 0">
                </td>
                <td style="border: 1px solid #ddd; padding: 6px; text-align:center; background:#f8f9fa;"><span class="gsm-avg" style="font-weight:bold; color:#27ae60;">0.0</span></td>
            `;

            // If not the first row, inherit group label from previous data row for averaging
            if (!isAfterFirst) {
                const prevSelect = dataRow.querySelector('td:first-child select');
                if (prevSelect) {
                    newDataRow.setAttribute('data-inherited-group', prevSelect.options[prevSelect.selectedIndex]?.text || '');
                }
            }
            // Insert the new data row BEFORE the clicked button row
            tbody.insertBefore(newDataRow, buttonRow);
        }

        function addGsmGroup() {
            const tbody = document.querySelector('.test-parameters table tbody');
            if (!tbody) return;
            const currentRows = tbody.querySelectorAll('tr').length;
            const startPos = currentRows + 1;
            const groupNumber = Math.ceil(currentRows / 4) + 1;

            for (let i = 0; i < 4; i++) {
                const pos = startPos + i;
                const isEnd = (i === 3);
                const row = document.createElement('tr');
                // Determine group index (1-based)
                const groupIdx = Math.ceil(pos / 4);
                // For group 2 (rows 5-8), use Middle Left dropdown in first row of that group
                const positionCellHtml = (() => {
                    const idxInGroup = ((pos - 1) % 4) + 1;
                    if (groupIdx === 2 && idxInGroup === 1) {
                        return `<select style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                                  <option value="Middle Left-1">Middle Left-1</option>
                                  <option value="Middle Left-2">Middle Left-2</option>
                                  <option value="Middle Left-3">Middle Left-3</option>
                                  <option value="Middle Left-4">Middle Left-4</option>
                                </select>`;
                    }
                    return `<input type="text" placeholder="Position ${pos}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">`;
                })();
                row.innerHTML = `
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        ${positionCellHtml}
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        <input type="number" class="weight" step="any" min="0" max="1000" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" onchange="calculateGroupWiseAverage()" oninput="this.value = Math.max(0, this.value)"> 
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align:center; background:#f8f9fa;">
                        <span class="gsm-avg" style="font-weight:bold; color:#27ae60;">0.0</span>
                    </td>
                `;
                tbody.appendChild(row);
            }
            
            // Recalculate summary after adding rows
            const table = tbody.closest('table');
            const rows = table.querySelectorAll('tbody tr');
            const values = Array.from(rows).map(r => {
                const inp = r.querySelector('.weight');
                const v = parseFloat(inp?.value || '0');
                return isNaN(v) ? 0 : v;
            });
            calculateGsmSummary(values);
        }

        // Calculate Force Retain (%) = After / Before * 100 with 1 decimal, per row
        function calculateForceRetainWeathering(changedInput) {
            const rowIndex = changedInput.getAttribute('data-row');
            const suffix = changedInput.getAttribute('data-suffix');
            if (!rowIndex || !suffix) return;
            const container = changedInput.closest('.test-parameters');
            if (!container) return;
            const afterInput = container.querySelector(`input[name="wx_bf_after_${suffix}_${rowIndex}"]`);
            const beforeInput = container.querySelector(`input[name="wx_bf_before_${suffix}_${rowIndex}"]`);
            const frSingle = container.querySelector(`input[name="wx_fr_${suffix}_${rowIndex}"]`);

            const afterVal = parseFloat(afterInput?.value || '0');
            const beforeVal = parseFloat(beforeInput?.value || '0');
            let fr = 0;
            if (beforeVal > 0) {
                fr = (afterVal / beforeVal) * 100;
            }
            if (frSingle) frSingle.value = fr.toFixed(1);
            // Recompute aggregate statistics
            const holder = changedInput.closest('.test-parameters');
            if (holder) {
                const suffixEl = holder.querySelector('input[name^="wx_bf_after_"]');
                if (suffixEl) {
                    const m = suffixEl.name.match(/^wx_bf_after_(.+?)_\d+$/);
                    if (m && m[1]) recalcWeatheringStats(holder, m[1]);
                }
            }
        }

        // Compute Average, SD, CV, Max, Min for Weathering table columns
        function recalcWeatheringStats(container, suffix) {
            const collect = (sel) => Array.from(container.querySelectorAll(sel))
                .map(i => parseFloat(i.value || i.textContent || ''))
                .filter(v => !isNaN(v));
            const stats = (arr) => {
                const n = arr.length;
                if (!n) return {avg:'', sd:'', cv:'', max:'', min:''};
                const sum = arr.reduce((a,b)=>a+b,0);
                const avg = sum / n;
                const variance = n > 1 ? arr.reduce((s,v)=> s + Math.pow(v-avg,2),0) / (n-1) : 0;
                const sd = Math.sqrt(variance);
                const cv = avg !== 0 ? (sd/avg)*100 : 0;
                return {avg, sd, cv, max: Math.max(...arr), min: Math.min(...arr)};
            };
            const set = (idSuffix, obj, digits=1) => {
                const map = { 'average':'avg', 'sd':'sd', 'cv':'cv', 'maximum':'max', 'minimum':'min' };
                Object.keys(map).forEach(k => {
                    const el = container.querySelector(`#wx_stat_${idSuffix}_${suffix}_${k}`) || container.querySelector(`#wx_stat_${idSuffix}_${suffix}_${k}`);
                    const target = container.querySelector(`#wx_stat_${idSuffix}_${suffix}_${k}`);
                    if (target) target.textContent = (obj[map[k]] ?? '').toString() ? (obj[map[k]].toFixed(digits)) : '';
                });
            };

            const bfAfter = collect(`input[name^="wx_bf_after_${suffix}_"]`);
            const bfBefore = collect(`input[name^="wx_bf_before_${suffix}_"]`);
            const frVals = collect(`input[name^="wx_fr_${suffix}_"]`).map(v => parseFloat(v));
            const elAfter = collect(`input[name^="wx_el_after_${suffix}_"]`);
            const elBefore = collect(`input[name^="wx_el_before_${suffix}_"]`);

            const s1 = stats(bfAfter); set('bf_after', s1, 1);
            const s2 = stats(bfBefore); set('bf_before', s2, 1);
            // Update Test Result summary: use averages with 2 decimal retain
            const afterEl = container.querySelector(`#wx_result_after_${suffix}`);
            const beforeEl = container.querySelector(`#wx_result_before_${suffix}`);
            const retainEl = container.querySelector(`#wx_result_retain_${suffix}`);
            if (afterEl) afterEl.textContent = (typeof s1.avg === 'number') ? s1.avg.toFixed(1) : '';
            if (beforeEl) beforeEl.textContent = (typeof s2.avg === 'number') ? s2.avg.toFixed(1) : '';
            const retain = (s1.avg && s2.avg) ? ((s2.avg / s1.avg) * 100) : 0;
            if (retainEl) retainEl.textContent = isFinite(retain) ? retain.toFixed(2) : '';
            const s3 = stats(frVals); // display only avg,max,min for FR
            // Write FR stats selectively
            const writeFr = (key, val) => {
                const el = container.querySelector(`#wx_stat_fr_${suffix}_${key}`);
                if (el) el.textContent = (typeof val === 'number') ? val.toFixed(1) : '';
            };
            writeFr('average', s3.avg);
            writeFr('maximum', s3.max);
            writeFr('minimum', s3.min);

            const s4 = stats(elAfter); set('el_after', s4, 1);
            const s5 = stats(elBefore); set('el_before', s5, 1);
        }

        // Calculate ISO 12956 Sieve Analysis Data
        function calcIso12956(suffixId) {
            const table = document.getElementById(`iso12956_table_${suffixId}`);
            if (!table) return;

            const weightInput = document.querySelector(`[name="iso12956_sandweight_${suffixId}"]`);
            const totalWeight = parseFloat(weightInput?.value || '0');
            if (totalWeight <= 0) return;

            let cumulative = 0;
            for (let i = 1; i <= 8; i++) {
                const retainedVal = parseFloat(document.querySelector(`[name="iso12956_retained_${suffixId}_${i}"]`)?.value || '0');
                cumulative += retainedVal;
                const cumulativePassing = ((totalWeight - cumulative) / totalWeight) * 100;

                // Round up to one decimal place using Math.ceil
                const cumulativeRounded = Math.ceil(cumulative * 10) / 10;
                const cumulativePassingRounded = Math.ceil(cumulativePassing * 10) / 10;

                document.getElementById(`iso12956_cum_${suffixId}_${i}`).textContent = cumulativeRounded.toFixed(1);
                document.getElementById(`iso12956_pass_${suffixId}_${i}`).textContent = cumulativePassingRounded.toFixed(1);
            }
        }

        // Generate Sample ID for ISO 12956 test
        function generateSampleId(suffixId) {
            const gsmInput = document.querySelector(`[name="iso12956_gsm_${suffixId}"]`);
            const lineInput = document.querySelector(`[name="iso12956_line_${suffixId}"]`);
            const rollInput = document.querySelector(`[name="iso12956_roll_${suffixId}"]`);
            const sampleIdInput = document.querySelector(`[name="iso12956_sampleid_${suffixId}"]`);
            
            if (!gsmInput || !lineInput || !rollInput || !sampleIdInput) return;
            
            const gsm = parseFloat(gsmInput.value || '0');
            const line = lineInput.value.trim();
            const roll = rollInput.value.trim();
            
            // Only generate Sample ID if all required fields have meaningful values
            if (gsm > 0 && line && roll) {
                const now = new Date();
                const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                const month = monthNames[now.getMonth()];
                const day = String(now.getDate()).padStart(2, '0');
                
            // Generate Sample ID: GSM.0L{LabTestNo}{month}{day}-LT{last2OfLabTestNo}-R{roll}
            // Example: GSM=400, LabTestNo=GEOCIL-LAB-TR-20251005-001, Roll=25 => 4.0LGEOCIL-LAB-TR-20251005-001Oct05-LT01-R25
            const gsmDecimal = (gsm / 100).toFixed(1);
            const lastTwo = (line.slice(-2) || '').padStart(2, '0');
            const sampleId = `${gsmDecimal}L${line}${month}${day}-LT${lastTwo}-R${roll}`;
                
                sampleIdInput.value = sampleId;
            } else {
                // Clear the Sample ID if any required field is empty
                sampleIdInput.value = '';
            }
        }

        // Add event listeners for ISO 12956 fields to update Sample ID
        function addIso12956EventListeners(suffixId) {
            const gsmInput = document.querySelector(`[name="iso12956_gsm_${suffixId}"]`);
            const lineInput = document.querySelector(`[name="iso12956_line_${suffixId}"]`);
            const rollInput = document.querySelector(`[name="iso12956_roll_${suffixId}"]`);
            
            // Auto-generate Lab Test No from Sample Reference ID (last 2-3 digits)
            const sampleRefEl = document.getElementById('sample_reference_id');
            const syncLabTestNo = () => { 
                if (lineInput && sampleRefEl) {
                    const sampleRef = sampleRefEl.value;
                    const match = sampleRef.match(/-(\d+)$/);
                    if (match) {
                        const num = parseInt(match[1]);
                        lineInput.value = num > 99 ? match[1].slice(-3) : match[1].slice(-2);
                    }
                }
            };
            syncLabTestNo();
            if (sampleRefEl) sampleRefEl.addEventListener('input', syncLabTestNo);
            
            if (gsmInput) gsmInput.addEventListener('input', () => generateSampleId(suffixId));
            if (rollInput) rollInput.addEventListener('input', () => generateSampleId(suffixId));
        }

        // Calculate Apparent Opening Size (O-values) automatically
        function calculateOpeningSize(suffixId) {
            // Get user input for O-value
            const oValueInput = document.getElementById(`o_value_input_${suffixId}`);
            const targetPercent = parseFloat(oValueInput.value);
            
            if (isNaN(targetPercent) || targetPercent < 0 || targetPercent > 100) {
                alert('Please enter a valid O-value between 0 and 100.');
                return;
            }
            
            // Predefined sieve sizes (mm) for standard test sieves
            const sieveSizes = [0.150, 0.125, 0.090, 0.075, 0.063, 0.040];
            
            // Get cumulative passing values from the table
            const cumulativePassing = [];
            for (let i = 1; i <= 8; i++) {
                const passingElement = document.getElementById(`iso12956_pass_${suffixId}_${i}`);
                if (passingElement && passingElement.textContent.trim() !== '0.0') {
                    const passingValue = parseFloat(passingElement.textContent);
                    if (!isNaN(passingValue)) {
                        cumulativePassing.push(passingValue);
                    }
                }
            }
            
            if (cumulativePassing.length === 0) {
                alert('Please enter sieve data and calculate cumulative passing values first.');
                return;
            }
            
            // Calculate the O-value with detailed process
            const result = calculateOpeningSizeWithDetails(sieveSizes, cumulativePassing, targetPercent);
            
            // Display detailed results
            displayDetailedOpeningSizeResults(suffixId, targetPercent, result);
        }
        
        // Calculate Opening Size with detailed process information
        function calculateOpeningSizeWithDetails(sieveSizes, cumulativePassing, targetPercent) {
            // Combine sieve size and passing %
            const data = [];
            for (let i = 0; i < Math.min(sieveSizes.length, cumulativePassing.length); i++) {
                data.push([sieveSizes[i], cumulativePassing[i]]);
            }
            
            // Sort data descending by sieve size
            data.sort((a, b) => b[0] - a[0]);
            
            // Find where target lies using linear interpolation
            for (let i = 0; i < data.length - 1; i++) {
                const [size1, pass1] = data[i];
                const [size2, pass2] = data[i + 1];
                
                if ((pass1 >= targetPercent && targetPercent >= pass2) ||
                    (pass2 >= targetPercent && targetPercent >= pass1)) {
                    
                    // Linear interpolation
                    const openingSize = size2 + ((targetPercent - pass2) / (pass1 - pass2)) * (size1 - size2);
                    const roundedSize = Math.round(openingSize * 1000) / 1000;
                    
                    return {
                        found: true,
                        size1: size1,
                        pass1: pass1,
                        size2: size2,
                        pass2: pass2,
                        target: targetPercent,
                        result: roundedSize,
                        formula: `${size2} + ((${targetPercent} - ${pass2}) / (${pass1} - ${pass2})) * (${size1} - ${size2})`
                    };
                }
            }
            
            return { found: false };
        }
        
        // Display detailed opening size calculation results
        function displayDetailedOpeningSizeResults(suffixId, targetPercent, result) {
            const resultsDiv = document.getElementById(`opening_size_results_${suffixId}`);
            const detailsDiv = document.getElementById(`calculation_details_${suffixId}`);
            const finalResultDiv = document.getElementById(`final_result_${suffixId}`);
            
            if (!resultsDiv || !detailsDiv || !finalResultDiv) return;
            
            if (!result.found) {
                detailsDiv.innerHTML = `<span style="color: #e74c3c;">O${targetPercent} could not be calculated from the given data.</span>`;
                finalResultDiv.innerHTML = `<span style="color: #e74c3c;">Apparent Opening Size(O${targetPercent}): Could not be calculated</span>`;
                resultsDiv.style.display = 'block';
                return;
            }
            
            // Calculate the actual interpolation result
            const calculationResult = result.size2 + ((result.target - result.pass2) / (result.pass1 - result.pass2)) * (result.size1 - result.size2);
            
            // Create detailed calculation display
            let detailsHTML = '';
            detailsHTML += `O${targetPercent} lies between sieve size ${result.size1} mm (${result.pass1}% passing) and ${result.size2} mm (${result.pass2}% passing).<br><br>`;
            detailsHTML += `Interpolation formula:<br>`;
            detailsHTML += `${result.formula}<br><br>`;
            detailsHTML += `<strong>O${targetPercent} = ${result.result} mm (${(result.result * 1000).toFixed(0)} µm)</strong>`;
            
            // Create final result display
            const finalResultHTML = `Apparent Opening Size(O${targetPercent}): ${result.result} mm (${(result.result * 1000).toFixed(0)} µm)`;
            
            detailsDiv.innerHTML = detailsHTML;
            finalResultDiv.innerHTML = finalResultHTML;
            resultsDiv.style.display = 'block';
        }

        function addCbrGroup() {
            const table = document.querySelector('.test-parameters table tbody');
            if (!table) return;
            const currentRows = table.querySelectorAll('tr').length;
            const startPos = currentRows + 1;
            for (let i = 0; i < 4; i++) {
                const pos = startPos + i;
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        <input type="text" placeholder="Position ${pos}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        <input type="number" step="any" max="1000" min="0" placeholder="0.0000" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        <input type="number" step="any" max="1000" min="0" placeholder="0.0000" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" oninput="if(this.value < 0) this.value = 0">
                    </td>
                `;
                table.appendChild(row);
            }
        }

        function addGrabGroup() {
            const table = document.querySelector('.test-parameters table tbody');
            if (!table) return;
            const currentRows = table.querySelectorAll('tr').length;
            const startPos = currentRows + 1;
            for (let i = 0; i < 4; i++) {
                const pos = startPos + i;
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td style=\"border: 1px solid #ddd; padding: 8px;\">\n                        <input type=\"text\" placeholder=\"Position ${pos}\" style=\"width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;\">\n                    </td>\n                    <td style=\"border: 1px solid #ddd; padding: 8px;\">\n                        <input type=\"number\" step=\"any\" max=\"1000\" min=\"0\" placeholder=\"0.0000\" style=\"width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;\" oninput=\"if(this.value < 0) this.value = 0\">\n                    </td>\n                    <td style=\"border: 1px solid #ddd; padding: 8px;\">\n                        <input type=\"number\" step=\"any\" max=\"1000\" min=\"0\" placeholder=\"0.0000\" style=\"width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;\" oninput=\"if(this.value < 0) this.value = 0\">\n                    </td>`;
                table.appendChild(row);
            }
        }

        // Weathering: toggle MD/CD selection buttons per row
        function setWxDirection(button, dir) {
            const row = button.getAttribute('data-row');
            const suffix = button.getAttribute('data-suffix');
            if (!row || !suffix) return;
            const container = button.closest('td');
            const all = container.querySelectorAll('.dir-btn');
            all.forEach(b => { b.style.background = '#fff'; b.style.color = '#2c3e50'; });
            // highlight selected
            button.style.background = '#007bff';
            button.style.color = '#fff';
            const hidden = container.querySelector(`input[name="wx_dir_${suffix}_${row}"]`);
            if (hidden) hidden.value = dir;
        }
        
        function addWxRow(suffixId) {
            const tbody = document.getElementById(`wx_tbody_${suffixId}`);
            const currentRows = tbody.querySelectorAll('tr');
            const newRowNum = currentRows.length + 1;
            
            const row = document.createElement('tr');
            row.innerHTML = `
                <td style="border:1px solid #ddd; padding:6px; text-align:center;">${newRowNum}</td>
                <td style="border:1px solid #ddd; padding:6px; text-align:center;">
                    <div style="display:inline-flex; gap:6px; align-items:center;">
                        <button type="button" class="dir-btn" data-row="${newRowNum}" data-suffix="${suffixId}" onclick="setWxDirection(this,'MD')" style="padding:4px 10px; border:1px solid #ccc; border-radius:4px; background:#007bff; color:#fff;">MD${newRowNum}</button>
                        <button type="button" class="dir-btn" data-row="${newRowNum}" data-suffix="${suffixId}" onclick="setWxDirection(this,'CD')" style="padding:4px 10px; border:1px solid #ccc; border-radius:4px; background:#fff; color:#2c3e50;">CD${newRowNum}</button>
                        <input type="hidden" name="wx_dir_${suffixId}_${newRowNum}" value="MD">
                    </div>
                </td>
                <td style="border:1px solid #ddd; padding:6px;"><input type="number" step="any" min="0" max="1000" name="wx_bf_after_${suffixId}_${newRowNum}" data-row="${newRowNum}" data-suffix="${suffixId}" class="wx_bf_after" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0; calculateForceRetainWeathering(this)"></td>
                <td style="border:1px solid #ddd; padding:6px;"><input type="number" step="any" min="0" max="1000" name="wx_bf_before_${suffixId}_${newRowNum}" data-row="${newRowNum}" data-suffix="${suffixId}" class="wx_bf_before" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0; calculateForceRetainWeathering(this)"></td>
                <td style="border:1px solid #ddd; padding:6px;"><input type="text" name="wx_fr_${suffixId}_${newRowNum}" class="wx_fr" readonly value="" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px; background:#f8f9fa;"></td>
                <td style="border:1px solid #ddd; padding:6px;"><input type="number" step="any" min="0" max="1000" name="wx_el_after_${suffixId}_${newRowNum}" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0"></td>
                <td style="border:1px solid #ddd; padding:6px;"><input type="number" step="any" min="0" max="1000" name="wx_el_before_${suffixId}_${newRowNum}" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;" oninput="if(this.value < 0) this.value = 0"></td>
                <td style="border:1px solid #ddd; padding:6px;"><button type="button" onclick="removeWxRow(this, '${suffixId}')" style="padding:4px 8px; background:#dc3545; color:#fff; border:none; border-radius:4px; cursor:pointer;">Remove</button></td>
            `;
            tbody.appendChild(row);
        }
        
        function removeWxRow(button, suffixId) {
            const row = button.closest('tr');
            const tbody = row.closest('tbody');
            
            // Check if this is the only row
            if (tbody.querySelectorAll('tr').length <= 1) {
                alert('You cannot remove the last row. At least one row is required.');
                return;
            }
            
            row.remove();
            
            // Renumber remaining rows
            const rows = tbody.querySelectorAll('tr');
            rows.forEach((r, idx) => {
                const rowNum = idx + 1;
                r.cells[0].textContent = rowNum;
                
                // Update all attributes with new row number
                const inputs = r.querySelectorAll('input[name*="' + rowNum + '"]');
                r.querySelectorAll('[data-row], [name*="' + rowNum + '"]').forEach(el => {
                    if (el.hasAttribute('name')) {
                        el.setAttribute('name', el.getAttribute('name').replace(/_\d+(?=.*)/, '_' + rowNum));
                    }
                    if (el.hasAttribute('data-row')) {
                        el.setAttribute('data-row', rowNum);
                    }
                });
                
                // Update button text
                const mdBtn = r.querySelector('.dir-btn[onclick*="MD"]');
                const cdBtn = r.querySelector('.dir-btn[onclick*="CD"]');
                if (mdBtn) mdBtn.textContent = 'MD' + rowNum;
                if (cdBtn) cdBtn.textContent = 'CD' + rowNum;
            });
        }

        // Compute MD:CD ratio per MD/CD pair within a 4-row group
        function updateMdCdRatio(changedInput) {
            const row = changedInput.closest('tr');
            const tbody = row.closest('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.querySelector('.strength_input'));
            const index = rows.indexOf(row);
            if (index < 0) return;
            const groupStart = Math.floor(index / 4) * 4;

            const computeAndSetForPair = (mdRow, cdRow) => {
                if (!mdRow || !cdRow) return;
                const mdVal = parseFloat(mdRow.querySelector('.strength_input')?.value || '0') || 0;
                const cdVal = parseFloat(cdRow.querySelector('.strength_input')?.value || '0') || 0;
                const display = (mdVal > 0) ? `1:${(cdVal / mdVal).toFixed(2)}` : '1:0.00';
                const mdRatioEl = mdRow.querySelector('.mdcd_ratio');
                const cdRatioEl = cdRow.querySelector('.mdcd_ratio');
                if (mdRatioEl) mdRatioEl.textContent = '';
                if (cdRatioEl) cdRatioEl.textContent = display; // show in one row (CD row)
            };

            // First MD/CD pair (rows groupStart, groupStart+1)
            computeAndSetForPair(rows[groupStart], rows[groupStart + 1]);
            // Second MD/CD pair (rows groupStart+2, groupStart+3)
            computeAndSetForPair(rows[groupStart + 2], rows[groupStart + 3]);
        }

        function addStripGroup() {
            const table = document.querySelector('.test-parameters table');
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const existing = Array.from(tbody.querySelectorAll('tr')).filter(r => r.querySelector('input,span'));
            const currentRows = existing.filter(r => r.querySelector('.strength_input')).length;
            const startIndex = currentRows + 1;
            for (let i = 0; i < 4; i++) {
                const pos = startIndex + i;
                const dir = ((pos % 2) === 1) ? 'MD' : 'CD';
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        <input type="text" placeholder="Position ${pos}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align:center;">
                        <input type="text" value="${dir}" readonly class="readonly" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px; text-align:center;">
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px;">
                                            <input type="number" step="any" max="1000" min="0" class="strength_input" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" onchange="updateMdCdRatio(this)" oninput="if(this.value < 0) this.value = 0"> 
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align:center; background:#f8f9fa;">
                        <span class="mdcd_ratio">0</span>
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        <input type="number" step="any" max="1000" min="0" class="elong_input" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                    </td>
                `;
                tbody.appendChild(tr);
                if (i === 1) {
                    const part = document.createElement('tr');
                    part.innerHTML = `<td colspan="5" style="border-top: 2px dashed #bbb; height: 1px;"></td>`;
                    tbody.appendChild(part);
                }
            }
            const gpart = document.createElement('tr');
            gpart.innerHTML = `<td colspan="5" style="border-top: 2px solid #999; height: 1px;"></td>`;
            tbody.appendChild(gpart);
        }

        // Check if selected test requires common sample/test fields
        function isCommonFieldsTest(testName, method) {
            const key = `${testName}|||${method}`.toLowerCase();
            const allowed = new Set([
                'mass per unit area (gsm)|||astm d5261',
                'mass per unit area (gsm)|||iso 9864',
                'strip tensile test|||astm d4595',
                'strip tensile test|||iso 10319'
            ]);
            return allowed.has(key);
        }

        // Build the common fields block HTML
        function buildCommonFieldsHtml(suffixId) {
            const now = new Date();
            const yyyy = now.getFullYear();
            const mm = String(now.getMonth() + 1).padStart(2, '0');
            const dd = String(now.getDate()).padStart(2, '0');
            const isoNow = `${yyyy}-${mm}-${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;

            return `
                <div class="form-section" style="margin-top: 10px;">
                    
                    <div class="form-row" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:12px;">
                        <div class="form-group"><label>Sample Details</label><input type="text" name="sample_details_${suffixId}" required></div>
                        <div class="form-group"><label>Batch Information</label><input type="text" id="batch_${suffixId}" name="batch_info_${suffixId}" placeholder="GT9.H1" oninput="this.value=this.value.toUpperCase(); updateProductReference('${suffixId}')" required></div>
                    </div>
                    <div class="form-row" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:12px;">
                        <div class="form-group"><label>Sample Collected From</label><input type="text" name="sample_collected_from_${suffixId}" required></div>
                        <div class="form-group"><label>Sample Received Date & Time</label><input type="datetime-local" name="sample_received_dt_${suffixId}" value="${yyyy}-${mm}-${dd}T${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}" required></div>
                    </div>
                    <div class="form-row" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:12px;">
                        <div class="form-group"><label>Sample Production Date</label><input type="date" name="sample_production_date_${suffixId}" style="padding:8px; border:1px solid #ddd; border-radius:6px;" required></div>
                        <div class="form-group"><label>Temperature (°C)</label><input type="number" step="any" max="1000" min="0" name="temperature_c_${suffixId}" style="padding:8px; border:1px solid #ddd; border-radius:6px;" required></div>
                        <div class="form-group"><label>RH (%)</label><input type="number" step="any" max="1000" min="0" name="rh_percent_${suffixId}" style="padding:8px; border:1px solid #ddd; border-radius:6px;" required></div>
                        <div class="form-group">
                            <label>Test Period</label>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <input type="date" name="test_period_from_${suffixId}" style="padding:8px; border:1px solid #ddd; border-radius:6px;" required>
                                <span style="color:#6c757d;">to</span>
                                <input type="date" name="test_period_to_${suffixId}" style="padding:8px; border:1px solid #ddd; border-radius:6px;" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-row" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:12px;">
                        <div class="form-group"><label>GSM</label><input type="number" step="any" max="1000" min="0" id="gsm_${suffixId}" name="gsm_${suffixId}" oninput="updateProductReference('${suffixId}')" required></div>
                        <div class="form-group"><label>Lab Test No</label><input type="text" id="line_${suffixId}" name="line_${suffixId}" readonly class="readonly"></div>
                        <div class="form-group"><label>Roll Number</label><input type="text" id="roll_${suffixId}" name="roll_${suffixId}" oninput="updateProductReference('${suffixId}')" required></div>
                    </div>
                    <div class="form-row" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:12px;">
                        <div class="form-group"><label>Product Reference</label><input type="text" id="product_ref_${suffixId}" name="product_ref_${suffixId}" readonly class="readonly"></div>
                        <div class="form-group"><label>Customer Reference</label><input type="text" name="customer_ref_${suffixId}" required></div>
                        <div class="form-group"><label>Sample Received From</label><input type="text" name="sample_received_from_detail_${suffixId}" required></div>
                    </div>
                    <div class="form-row" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap:12px;">
                        <div class="form-group"><label>Lighthouse Reference (Optional)</label><input type="text" name="lighthouse_ref_${suffixId}"></div>
                        <div class="form-group" style="grid-column: 1 / -1;"><label>Other Information (Optional)</label><textarea name="other_info_${suffixId}" rows="2" style="width:100%;" maxlength="255"></textarea></div>
                    </div>
                </div>
            `;
        }

        // Auto-generate Product Reference using the provided formula
        async function updateProductReference(suffixId) {
            // Auto-generate Lab Test No - fetch current count from server
            const labTestNoField = document.getElementById(`line_${suffixId}`);
            if (labTestNoField) {
                try {
                    const response = await fetch('get_next_lab_test_number.php');
                    const data = await response.json();
                    if (data.lab_test_number) {
                        labTestNoField.value = data.lab_test_number;
                    }
                } catch (error) {
                    // Fallback: use sample reference ID
                    const sampleRef = document.getElementById('sample_reference_id')?.value || '';
                    const match = sampleRef.match(/-(\d+)$/);
                    if (match) {
                        const num = parseInt(match[1]);
                        labTestNoField.value = num > 99 ? match[1].slice(-3) : match[1].slice(-2);
                    }
                }
            }
            
            const gsmVal = parseFloat(document.getElementById(`gsm_${suffixId}`)?.value || '0');
            const lineVal = (document.getElementById(`line_${suffixId}`)?.value || '').toString().trim();
            const rollVal = (document.getElementById(`roll_${suffixId}`)?.value || '').toString().trim();
            const batchVal = (document.getElementById(`batch_${suffixId}`)?.value || '').toString().trim();

            const now = new Date();
            const year = now.getFullYear();
            const yearShort = String(year).slice(-2); // Last 2 digits of year (e.g., 25 for 2025)
            const monthNames = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];
            const mon3 = monthNames[now.getMonth()];
            const dd = String(now.getDate()).padStart(2, '0');

            // Format: {GSM/100}.0L{yearShort}{month}{date}-R{roll}-{batch decimated}
            // Example: 3.0L25OCT09-R23-GT0.9H0.1
            const gsmFormatted = (gsmVal / 100).toFixed(1); // 300 -> 3.0
            const decimateBatch = (val) => {
                if (!val) return '';
                // Replace digit sequences with 0.x form preserving separators/letters
                return val.replace(/(\d+)(?=([^\d]|$))/g, (m) => {
                    const n = parseInt(m, 10);
                    if (isNaN(n)) return m;
                    return '0.' + n;
                });
            };
            const batchPart = decimateBatch(batchVal.toUpperCase());
            const ref = `${gsmFormatted}L${yearShort}${mon3}${dd}-R${rollVal || '0'}-${batchPart}`;
            const target = document.getElementById(`product_ref_${suffixId}`);
            if (target) target.value = ref;
        }

        // Row counter for thickness test
        let thicknessRowCounter = 1;

        // Helper function to get all selected positions
        function getSelectedThicknessPositions() {
            const selected = [];
            const selects = document.querySelectorAll('[id^="astmd5199_position_"]');
            selects.forEach(select => {
                if (select.value) {
                    selected.push(select.value);
                }
            });
            return selected;
        }

        // Helper function to generate position options (excluding selected ones)
        function generateThicknessPositionOptions(currentIndex) {
            const positionOptions = ['Left-1', 'Left-2', 'Left-3', 'Left-4'];
            const selected = getSelectedThicknessPositions();
            const currentSelect = document.getElementById('astmd5199_position_' + currentIndex);
            const currentValue = currentSelect ? currentSelect.value : '';
            
            return positionOptions.map(opt => {
                if (selected.includes(opt) && opt !== currentValue) {
                    return '';
                }
                return `<option value="${opt}">${opt}</option>`;
            }).join('');
        }

        // Update all dropdowns to show all positions (allow any position in any row)
        function updateThicknessDropdowns() {
            const selects = document.querySelectorAll('[id^="astmd5199_position_"]');
            const selected = getSelectedThicknessPositions();
            
            selects.forEach((select) => {
                const currentValue = select.value;
                
                // All position options available
                const allOptions = [
                    'Left-1', 'Left-2', 'Left-3', 'Left-4',
                    'Middle Left-1', 'Middle Left-2', 'Middle Left-3', 'Middle Left-4',
                    'Middle Right-1', 'Middle Right-2', 'Middle Right-3', 'Middle Right-4',
                    'Right-1', 'Right-2', 'Right-3', 'Right-4'
                ];
                
                // Filter out positions already selected in OTHER rows
                const availableOptions = allOptions.filter(opt => {
                        // Always show the current row's selection
                        if (opt === currentValue) return true;
                    // Hide options selected in other rows
                        return !selected.includes(opt);
                    });
                
                // Update dropdown with available options
                select.innerHTML = '';
                // Add empty option first
                const emptyOption = document.createElement('option');
                emptyOption.value = '';
                emptyOption.textContent = '-- Select Position --';
                select.appendChild(emptyOption);
                
                availableOptions.forEach(opt => {
                    const option = document.createElement('option');
                    option.value = opt;
                    option.textContent = opt;
                    if (opt === currentValue) option.selected = true;
                    select.appendChild(option);
                });
            });
        }

        // Add single thickness row
        function addSingleThicknessRow(afterRowNumber) {
            const table = document.querySelector('.test-parameters table tbody');
            if (!table) return;
            
            const currentRows = table.querySelectorAll('tr');
            // Count only data rows (exclude button rows)
            const dataRows = Array.from(currentRows).filter(row => row.querySelector('select[name*="position"]') && !row.querySelector('button'));
            const newRowNumber = dataRows.length + 1;
            
            // Find the button row and insert after it
            let insertIndex = -1;
            for (let i = 0; i < currentRows.length; i++) {
                const btn = currentRows[i].querySelector('button[onclick*="addSingleThicknessRow(' + afterRowNumber + ')"]');
                if (btn) {
                    insertIndex = i + 1;
                    break;
                }
            }
            
            if (insertIndex === -1) {
                insertIndex = currentRows.length;
            }
            
            // Insert new data row
            const selected = getSelectedThicknessPositions();
            
            // All position options available
            const allPositionOptions = [
                'Left-1', 'Left-2', 'Left-3', 'Left-4',
                'Middle Left-1', 'Middle Left-2', 'Middle Left-3', 'Middle Left-4',
                'Middle Right-1', 'Middle Right-2', 'Middle Right-3', 'Middle Right-4',
                'Right-1', 'Right-2', 'Right-3', 'Right-4'
            ];
            const availableOptions = allPositionOptions
                .filter(opt => !selected.includes(opt))
                    .map(opt => `<option value="${opt}">${opt}</option>`).join('');
            
            const newRow = table.insertRow(insertIndex);
            newRow.className = 'gsm-row';
            newRow.innerHTML = `
                <td style="border: 1px solid #ddd; padding: 6px;">
                    <select class="posSelect" name="astmd5199_position_${newRowNumber}" id="astmd5199_position_${newRowNumber}" style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;" onchange="updateThicknessDropdowns(); calculateThicknessAverages()">
                        <option value="">-- Select Position --</option>
                        ${availableOptions}
                    </select>
                </td>
                <td style="border: 1px solid #ddd; padding: 6px;">
                    <input type="number" 
                           class="under2kpa"
                           name="astmd5199_under2kpa_${newRowNumber}"
                           step="0.0001" max="1000" 
                           min="0"
                           style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;"
                           onchange="calculateThicknessAverages()"
                           oninput="if(this.value < 0) this.value = 0">
                </td>
                <td style="border: 1px solid #ddd; padding: 6px; text-align: center; background: #f8f9fa;">
                    <span class="avgCell" style="font-weight: bold; color: #27ae60;">0.000</span>
                </td>
            `;
            
            // Insert "Add Row" button row after new data row
            const btnRow = table.insertRow(insertIndex + 1);
            btnRow.innerHTML = `
                <td colspan="3" style="border: none; padding: 5px; text-align: center; background: #f8f9fa;">
                    <button type="button" 
                            onclick="addSingleThicknessRow(${newRowNumber})"
                            style="padding: 5px 12px; background:rgb(21, 75, 14); color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 12px;">
                        <i class="fa fa-plus"></i> Add 1 More Row
                    </button>
                </td>
            `;
            
            // Remove the old button row
            if (insertIndex > 0) {
                currentRows[insertIndex - 1].remove();
            }

            // De-duplicate accidental multiple consecutive button rows
            const allRows = Array.from(table.querySelectorAll('tr'));
            for (let i = allRows.length - 2; i >= 0; i--) {
                const hasBtn = !!allRows[i].querySelector('button[onclick*="addSingleThicknessRow"]');
                const nextHasBtn = !!allRows[i+1]?.querySelector?.('button[onclick*="addSingleThicknessRow"]');
                if (hasBtn && nextHasBtn) {
                    allRows[i].remove();
                }
            }
            
            // Increment row counter
            thicknessRowCounter++;
            
            // Update dropdowns to show correct options
            updateThicknessDropdowns();
        }

        function removeLastThicknessRow() {
            const table = document.querySelector('.test-parameters table tbody');
            if (!table) return;
            
            const rows = Array.from(table.querySelectorAll('tr'));
            
            // Count data rows (rows without buttons)
            const dataRows = rows.filter(row => !row.querySelector('button') && row.querySelector('select[name*="position"]'));
            
            // Don't remove if only initial 4 rows exist
            // Allow removal if there are more than 4 rows (meaning at least 1 row was added)
            if (dataRows.length <= 4) {
                alert('Cannot remove. Minimum 4 rows required.');
                return;
            }
            
            // Find the last button row from the end
            for (let i = rows.length - 1; i >= 0; i--) {
                const btn = rows[i].querySelector('button[onclick*="addSingleThicknessRow"]');
                if (btn) {
                    // Find the data row above this button row
                    if (i > 0 && !rows[i-1].querySelector('button')) {
                        rows[i-1].remove(); // Remove data row
                    }
                    rows[i].remove(); // Remove button row
                    updateThicknessDropdowns();
                    calculateAllGroupAverages();
                    break;
                }
            }
        }

        // Add thickness group (4 rows at a time)
        function addThicknessGroup() {
            const table = document.querySelector('.test-parameters table tbody');
            if (!table) return;
            
            const currentRows = table.querySelectorAll('tr').length;
            const groupNumber = Math.ceil(currentRows / 4) + 1;
            const startPosition = currentRows + 1;
            
            for (let i = 0; i < 4; i++) {
                const positionNumber = startPosition + i;
                const isGroupEnd = (i === 3);
                
                const row = table.insertRow();
                row.innerHTML = `
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        <input type="text" 
                               placeholder="Position ${positionNumber}"
                               style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;"
                               onchange="calculateThicknessAverages()">
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px;">
                        <input type="number" 
                               step="0.0001" max="1000" 
                               min="0"
                               
                               style="width: 100%; padding: 5px; border: 1px solid #ddd; border-radius: 4px;"
                               onchange="calculateThicknessAverages()"
                               oninput="if(this.value < 0) this.value = 0">
                    </td>
                    <td style="border: 1px solid #ddd; padding: 8px; text-align: center; background: #f8f9fa;">
                        ${isGroupEnd ? `<span id="group_avg_${groupNumber}" style="font-weight: bold; color: #27ae60;">0.0000</span>` : ''}
                    </td>
                `;
            }
        }
        
        // Add event listeners
        document.addEventListener('DOMContentLoaded', function() {
    updateDateTimeAndShift();
    setInterval(updateDateTimeAndShift, 1000); // Update every second
    
    // Initialize general info visibility to set proper required attributes
    toggleGeneralInfoVisibility();
    
    const checkboxes = document.querySelectorAll('input[type="checkbox"]');
            
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateSelectionCount);
            });
            
            updateSelectionCount();
            toggleOtherInfoForGsmOnly();
            
            // Initialize averages for all forms on page load
            setTimeout(() => {
                const allForms = document.querySelectorAll('[id$="_form"]');
                allForms.forEach(form => {
                    const suffixId = form.id.replace('_form', '');
                    calculateAverages(suffixId);
                });
            }, 100);
        });
        
        // Form validation is now handled by onclick in the submit button

        // Functions for experimental data table management
        function addExperimentalRow(suffixId) {
            const tbody = document.getElementById(`experimental_data_body_${suffixId}`);
            const existingRows = tbody.querySelectorAll('tr');
            const nextRowNumber = existingRows.length + 1;
            
            const newRow = document.createElement('tr');
            newRow.innerHTML = `
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" name="exp_no_${nextRowNumber}_${suffixId}" value="${nextRowNumber}" readonly style="width: 40px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.0001" name="exp_h0_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.1" name="exp_t1_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.0001" name="exp_h1_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.1" name="exp_t2_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.0001" name="exp_thickness_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.0001" name="exp_water_level_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.1" name="exp_temp_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.0001" name="exp_correction_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.0001" name="exp_head_diff_${nextRowNumber}_${suffixId}" readonly style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.1" name="exp_time_${nextRowNumber}_${suffixId}" style="width: 70px; padding: 2px; border: 1px solid #ddd; text-align: center;" oninput="calculateRowData('${suffixId}', ${nextRowNumber})">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.0001" name="exp_velocity_${nextRowNumber}_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                </td>
                <td style="border: 1px solid #ddd; padding: 4px; text-align: center;">
                    <input type="number" step="0.0001" name="exp_permeability_${nextRowNumber}_${suffixId}" readonly style="width: 80px; padding: 2px; border: 1px solid #ddd; text-align: center; background: #f8f9fa;">
                </td>
            `;
            
            tbody.appendChild(newRow);
            
            // Automatically calculate averages for the new row
            calculateAverages(suffixId);
        }

        function removeExperimentalRow(suffixId) {
            const tbody = document.getElementById(`experimental_data_body_${suffixId}`);
            const rows = tbody.querySelectorAll('tr');
            
            if (rows.length > 1) { // Keep at least one row
                const lastRow = rows[rows.length - 1];
                lastRow.remove();
                
                // Automatically recalculate averages after removing a row
                calculateAverages(suffixId);
            }
        }

        function calculateExperimentalData(suffixId) {
            // Get calculation inputs
            const areaSpecimen = parseFloat(document.querySelector(`input[name="iso11058_area_specimen_${suffixId}"]`).value) || 0;
            const areaPipe = parseFloat(document.querySelector(`input[name="iso11058_area_pipe_${suffixId}"]`).value) || 0;
            
            if (areaSpecimen === 0 || areaPipe === 0) {
                alert('Please fill in the Exposed area of the test specimen (a) and Exposed area of stand pipe (A) first.');
                return;
            }
            
            // Get all rows in the experimental data table
            const tbody = document.getElementById(`experimental_data_body_${suffixId}`);
            const rows = tbody.querySelectorAll('tr');
            
            rows.forEach((row, index) => {
                const rowNum = index + 1;
                
                // Get input values for this row
                const h0 = parseFloat(row.querySelector(`input[name="exp_h0_${rowNum}_${suffixId}"]`).value) || 0;
                const h1 = parseFloat(row.querySelector(`input[name="exp_h1_${rowNum}_${suffixId}"]`).value) || 0;
                const t1 = parseFloat(row.querySelector(`input[name="exp_t1_${rowNum}_${suffixId}"]`).value) || 0;
                const t2 = parseFloat(row.querySelector(`input[name="exp_t2_${rowNum}_${suffixId}"]`).value) || 0;
                const thickness = parseFloat(row.querySelector(`input[name="exp_thickness_${rowNum}_${suffixId}"]`).value) || 0;
                const time = parseFloat(row.querySelector(`input[name="exp_time_${rowNum}_${suffixId}"]`).value) || 0;
                const correctionFactor = parseFloat(row.querySelector(`input[name="exp_correction_${rowNum}_${suffixId}"]`).value) || 1;
                
                // Calculate Head Difference (?h) = h0 - h1
                const headDifference = h0 - h1;
                
                // Calculate Velocity (V20) = ?h/t × RT
                // Use t2 - t1 for time difference as per falling head method
                const timeDifference = t2 - t1;
                const velocity = timeDifference > 0 ? (headDifference / timeDifference) * correctionFactor : 0;
                
                // Calculate Permeability (K) using the specified logic
                let K = 0;
                const a = areaSpecimen; // Exposed area of specimen
                const A = areaPipe;     // Exposed area of stand pipe
                const L = thickness;    // Thickness
                const t = timeDifference; // Time difference (t2 - t1)
                const h1_val = h0;      // Initial water level
                const h2_val = h1;      // Final water level
                const RT = correctionFactor; // Correction factor
                
                // Only calculate if all required inputs are valid
                if (a > 0 && A > 0 && t > 0 && h1_val > 0 && h2_val > 0 && h1_val !== h2_val) {
                    K = (a * L) / (A * t) * Math.log10(h1_val / h2_val) * RT;
                }
                
                // Update the calculated fields
                row.querySelector(`input[name="exp_head_diff_${rowNum}_${suffixId}"]`).value = headDifference.toFixed(3);
                row.querySelector(`input[name="exp_velocity_${rowNum}_${suffixId}"]`).value = velocity.toFixed(3);
                row.querySelector(`input[name="exp_permeability_${rowNum}_${suffixId}"]`).value = K.toFixed(6);
            });
            
            alert('Calculations completed successfully!');
        }

        function calculateRowData(suffixId, rowNumber) {
            // Get calculation inputs
            const areaSpecimen = parseFloat(document.querySelector(`input[name="iso11058_area_specimen_${suffixId}"]`).value) || 0;
            const areaPipe = parseFloat(document.querySelector(`input[name="iso11058_area_pipe_${suffixId}"]`).value) || 0;
            
            // Debug: Log the values to check if they're being retrieved correctly
            console.log(`Row ${rowNumber} - Specimen Area (a): ${areaSpecimen}, Pipe Area (A): ${areaPipe}`);
            
            // Get input values for this specific row
            const h0 = parseFloat(document.querySelector(`input[name="exp_h0_${rowNumber}_${suffixId}"]`).value) || 0;
            const h1 = parseFloat(document.querySelector(`input[name="exp_h1_${rowNumber}_${suffixId}"]`).value) || 0;
            const t1 = parseFloat(document.querySelector(`input[name="exp_t1_${rowNumber}_${suffixId}"]`).value) || 0;
            const t2 = parseFloat(document.querySelector(`input[name="exp_t2_${rowNumber}_${suffixId}"]`).value) || 0;
            const thickness = parseFloat(document.querySelector(`input[name="exp_thickness_${rowNumber}_${suffixId}"]`).value) || 0;
            const time = parseFloat(document.querySelector(`input[name="exp_time_${rowNumber}_${suffixId}"]`).value) || 0;
            const correctionFactor = parseFloat(document.querySelector(`input[name="exp_correction_${rowNumber}_${suffixId}"]`).value) || 1;
            
            // Calculate Head Difference (?h) = h0 - h1
            const headDifference = h0 - h1;
            
            // Calculate Velocity (V20) = ?h/t × RT
            // Use t2 - t1 for time difference as per falling head method
            const timeDifference = t2 - t1;
            const velocity = timeDifference > 0 ? (headDifference / timeDifference) * correctionFactor : 0;
            
            // Calculate Permeability (K) using natural logarithm
            let K = 0;
            const a = areaSpecimen; // Exposed area of specimen
            const A = areaPipe;     // Exposed area of stand pipe
            const L = thickness;    // Thickness
            const t = timeDifference; // Time difference (t2 - t1)
            const h1_val = h0;      // Initial water level
            const h2_val = h1;      // Final water level
            const RT = correctionFactor; // Correction factor
            
            // Only calculate if all required inputs are valid
            if (a > 0 && A > 0 && t > 0 && h1_val > 0 && h2_val > 0 && h1_val !== h2_val) {
                K = (a * L) / (A * t) * Math.log10(h1_val / h2_val) * RT;
            }
            
            // Update the calculated fields for this row
            document.querySelector(`input[name="exp_head_diff_${rowNumber}_${suffixId}"]`).value = headDifference.toFixed(3);
            document.querySelector(`input[name="exp_velocity_${rowNumber}_${suffixId}"]`).value = velocity.toFixed(3);
            document.querySelector(`input[name="exp_permeability_${rowNumber}_${suffixId}"]`).value = K.toFixed(6);
            
            // Automatically update averages after each calculation
            calculateAverages(suffixId);
        }

        function recalculateAllRows(suffixId) {
            // Get all rows in the experimental data table
            const tbody = document.getElementById(`experimental_data_body_${suffixId}`);
            if (!tbody) return;
            
            const rows = tbody.querySelectorAll('tr');
            rows.forEach((row, index) => {
                const rowNum = index + 1;
                calculateRowData(suffixId, rowNum);
            });
            
            // Automatically update averages after recalculating all rows
            calculateAverages(suffixId);
        }

        function calculateAverages(suffixId) {
            // Get all rows in the experimental data table
            const tbody = document.getElementById(`experimental_data_body_${suffixId}`);
            if (!tbody) return;
            
            const rows = tbody.querySelectorAll('tr');
            let permeabilitySum = 0;
            let velocitySum = 0;
            let validPermeabilityCount = 0;
            let validVelocityCount = 0;
            
            rows.forEach((row, index) => {
                const rowNum = index + 1;
                
                // Get calculated values for this row
                const permeabilityValue = parseFloat(row.querySelector(`input[name="exp_permeability_${rowNum}_${suffixId}"]`).value) || 0;
                const velocityValue = parseFloat(row.querySelector(`input[name="exp_velocity_${rowNum}_${suffixId}"]`).value) || 0;
                
                // Add to sum if value is valid (not 0)
                if (permeabilityValue !== 0) {
                    permeabilitySum += permeabilityValue;
                    validPermeabilityCount++;
                }
                
                if (velocityValue !== 0) {
                    velocitySum += velocityValue;
                    validVelocityCount++;
                }
            });
            
            // Calculate averages
            const avgPermeability = validPermeabilityCount > 0 ? (permeabilitySum / validPermeabilityCount) : 0;
            const avgVelocity = validVelocityCount > 0 ? (velocitySum / validVelocityCount) : 0;
            
            // Update the average fields
            document.getElementById(`avg_permeability_${suffixId}`).value = avgPermeability.toFixed(6);
            document.getElementById(`avg_velocity_${suffixId}`).value = avgVelocity.toFixed(3);
        }
        
        function validateTestPeriod() {
            const fromDate = document.getElementById('test_period_from').value;
            const toDate = document.getElementById('test_period_to').value;
            
            if (fromDate && toDate) {
                if (new Date(toDate) < new Date(fromDate)) {
                    alert('Test Period To cannot be earlier than Test Period From');
                    document.getElementById('test_period_to').value = '';
                }
            }
        }
        
        function validateWeatheringTestDate(suffixId) {
            const startDate = document.getElementById('wx_test_start_' + suffixId)?.value;
            const endDate = document.getElementById('wx_test_end_' + suffixId)?.value;
            
            if (startDate && endDate) {
                if (new Date(endDate) < new Date(startDate)) {
                    alert('Test End Date cannot be earlier than Test Start Date');
                    document.getElementById('wx_test_end_' + suffixId).value = '';
                }
            }
        }

        
        function applyLastGeneralInfo(suffixId) {
            if (!window.QC_LAST_GENERAL) return;
            const map = {
                sample_details: `sample_details_${suffixId}`,
                batch_info: `batch_info_${suffixId}`,
                sample_collected_from: `sample_collected_from_${suffixId}`,
                sample_received_dt: `sample_received_dt_${suffixId}`,
                sample_production_date: `sample_production_date_${suffixId}`,
                temperature_c: `temperature_c_${suffixId}`,
                rh_percent: `rh_percent_${suffixId}`,
                test_period_from: `test_period_from_${suffixId}`,
                test_period_to: `test_period_to_${suffixId}`,
                gsm: `gsm_${suffixId}`,
                line: `line_${suffixId}`,
                roll: `roll_${suffixId}`,
                product_ref: `product_ref_${suffixId}`
            };
            Object.keys(map).forEach(key => {
                const v = window.QC_LAST_GENERAL[key];
                if (v !== undefined) {
                    const el = document.getElementsByName(map[key])[0] || document.getElementById(map[key]);
                    if (el) el.value = v;
                }
            });
        }
        
        // Apply last general info to top-level form fields on page load
        function applyLastGeneralToTopFields() {
            console.log('=== APPLY AUTO-FILL CALLED ===');
            console.log('Edit mode:', <?php echo $edit_mode ? 'true' : 'false'; ?>);
            
            // Don't auto-fill if in edit mode
            const isEditMode = <?php echo $edit_mode ? 'true' : 'false'; ?>;
            if (isEditMode) {
                console.log('? Skipping auto-fill - in edit mode');
                return;
            }
            
            if (!window.QC_LAST_GENERAL || typeof window.QC_LAST_GENERAL !== 'object') {
                console.log('? No QC_LAST_GENERAL data found');
                console.log('window.QC_LAST_GENERAL is:', typeof window.QC_LAST_GENERAL, window.QC_LAST_GENERAL);
                return;
            }
            
            const prefCount = Object.keys(window.QC_LAST_GENERAL).length;
            if (prefCount === 0) {
                console.log('?? QC_LAST_GENERAL is empty - no preferences saved yet');
                console.log('Submit a QC Test to save your preferences');
                return;
            }
            
            console.log('? QC_LAST_GENERAL found:', window.QC_LAST_GENERAL);
            console.log('Number of fields to fill:', prefCount);
            
            const topFieldMap = {
                'sample_details': 'sample_details',
                'batch_information': 'batch_information',
                'sample_collected_from': 'sample_collected_from',
                'sample_received_datetime': 'sample_received_datetime',
                'sample_production_date': 'sample_production_date',
                'temperature': 'temperature',
                'rh_percentage': 'rh_percentage',
                'test_period_from': 'test_period_from',
                'test_period_to': 'test_period_to',
                'customer_reference': 'customer_reference',
                'sample_received_from': 'sample_received_from'
            };
            
            let applied = 0;
            let skipped = 0;
            let notFound = 0;
            
            Object.keys(topFieldMap).forEach(key => {
                const v = window.QC_LAST_GENERAL[key];
                if (v !== undefined && v !== '') {
                    const fieldName = topFieldMap[key];
                    // Try to find by name first, then by ID
                    let el = document.getElementsByName(fieldName)[0];
                    if (!el) {
                        // Try batch_information -> qc_batch_info ID mapping
                        if (fieldName === 'batch_information') {
                            el = document.getElementById('qc_batch_info');
                        } else {
                            el = document.getElementById(fieldName);
                        }
                    }
                    
                    if (el) {
                        // Skip readonly fields that are populated by reference selection
                        const isRefPopulatedField = ['qc_batch_info'].includes(el.id);
                        
                        if (isRefPopulatedField && el.value && el.value !== '') {
                            // Field already populated by reference selection, don't override
                            console.log(`Skipping ${fieldName} (populated by reference selection): ${el.value}`);
                            skipped++;
                        } else {
                            // Fill the field
                            console.log(`Setting ${fieldName} to:`, v);
                            el.value = v;
                            applied++;
                        }
                    } else {
                        console.log(`? Field ${fieldName} not found`);
                        notFound++;
                    }
                } else {
                    skipped++;
                }
            });
            console.log('=== AUTO-FILL SUMMARY ===');
            console.log(`? Applied: ${applied} fields`);
            console.log(`?? Skipped (no value): ${skipped} fields`);
            console.log(`? Not found: ${notFound} fields`);
            console.log('=== END AUTO-FILL ===');
            
            // Show a brief notification if fields were filled
            if (applied > 0) {
                console.log(`? Success! Auto-filled ${applied} fields from your last submission`);
                
                // Optional: Show a brief visual notification
                const notification = document.createElement('div');
                notification.style.cssText = 'position:fixed;top:20px;right:20px;background:#27ae60;color:white;padding:12px 20px;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:10000;font-size:14px;font-weight:600;';
                notification.innerHTML = `? Auto-filled ${applied} field${applied > 1 ? 's' : ''} from previous submission`;
                document.body.appendChild(notification);
                
                // Remove notification after 3 seconds
                setTimeout(() => {
                    notification.style.transition = 'opacity 0.3s';
                    notification.style.opacity = '0';
                    setTimeout(() => notification.remove(), 300);
                }, 3000);
            }
        }
        
        // Multiple attempts to ensure auto-fill works
        let autoFillAttempts = 0;
        const maxAttempts = 5;
        
        function attemptAutoFill() {
            autoFillAttempts++;
            console.log(`Auto-fill attempt ${autoFillAttempts}/${maxAttempts}`);
            
            // Check if general info section exists and is visible
            const generalSection = document.getElementById('general-info-section');
            if (!generalSection) {
                console.log('General info section not found yet');
                if (autoFillAttempts < maxAttempts) {
                    setTimeout(attemptAutoFill, 300);
                }
                return;
            }
            
                applyLastGeneralToTopFields();
        }
        
        // Run on page load - with a delay to ensure fields are ready
        document.addEventListener('DOMContentLoaded', function() {
            console.log('?? DOMContentLoaded - starting auto-fill');
            setTimeout(attemptAutoFill, 200);
        });
        
        // Also run after window is fully loaded (backup)
        window.addEventListener('load', function() {
            console.log('?? Window loaded - running backup auto-fill');
            setTimeout(function() {
                console.log('? Running auto-fill from window.load (backup)');
                applyLastGeneralToTopFields();
            }, 500);
            
            // Initialize product type functionality (disabled - product type removed)
            console.log('? Product type functionality initialized');
            
            // Aggressively check for external reference generation multiple times
            // This ensures it works even if fields are pre-filled
            const checkIntervals = [500, 1000, 1500, 2000, 2500];
            checkIntervals.forEach(delay => {
                setTimeout(function() {
                    console.log('?? Auto-check for external reference generation...');
                    generateExternalReferenceIfReady();
                }, delay);
            });
            
            // Also set up a continuous monitor for the first 5 seconds
            let checkCount = 0;
            const continuousCheck = setInterval(function() {
                checkCount++;
                const extRefInput = document.getElementById('external_reference');
                const isVisible = extRefInput && extRefInput.style.display !== 'none';
                const hasValue = extRefInput && extRefInput.value;
                
                if (isVisible && !hasValue) {
                    console.log(`? Continuous check #${checkCount} - attempting generation...`);
                    generateExternalReferenceIfReady();
                }
                
                // Stop after 5 seconds or if reference is generated
                if (checkCount >= 10 || hasValue) {
                    clearInterval(continuousCheck);
                    console.log('? Continuous monitoring stopped');
                }
            }, 500);
        });
    </script>
    
    <?php if ($edit_mode && !empty($existing_test_data)): ?>
    <!-- Pre-fill form with existing data for edit mode -->
    <script>
    // Pre-fill data for edit mode
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Edit mode: Pre-filling form with existing data');
        
        const existingData = <?php echo json_encode($existing_test_data); ?>;
        const reportData = <?php echo json_encode($existing_report); ?>;
        
        setTimeout(function() {
            // Pre-fill all form fields from existing data
            const fieldMappings = {
                'sample_details': 'sample_details',
                'batch_information': 'batch_information',
                'sample_collected_from': 'sample_collected_from',
                'sample_received_datetime': 'sample_received_datetime',
                'sample_production_date': 'sample_production_date',
                'gsm': 'gsm',
                'temperature': 'temperature',
                'rh_percentage': 'rh_percentage',
                'test_period_from': 'test_period_from',
                'test_period_to': 'test_period_to',
                'roll_number': 'roll_number',
                'customer_reference': 'customer_reference',
                'sample_received_from': 'sample_received_from',
                'lighthouse_reference': 'lighthouse_reference',
                'other_info': 'other_info'
            };
            
            // Pre-fill all matching fields
            Object.keys(fieldMappings).forEach(dataKey => {
                const fieldId = fieldMappings[dataKey];
                if (existingData[dataKey]) {
                    const field = document.getElementById(fieldId) || document.querySelector(`[name="${fieldId}"]`);
                    if (field) {
                        field.value = existingData[dataKey];
                    }
                }
            });
            
            // Pre-fill reference fields and trigger data loading
            if (existingData.product_reference) {
                const productRef = document.getElementById('product_reference');
                if (productRef) {
                    productRef.value = existingData.product_reference;
                    // Trigger the reference data load to populate GSM, roll number, batch info
                    if (typeof loadQCReferenceData === 'function') {
                        loadQCReferenceData(existingData.product_reference);
                        
                        // After reference data loads, re-apply the original submitted values
                        // (in case user had manually edited them)
                        setTimeout(() => {
                            Object.keys(fieldMappings).forEach(dataKey => {
                                const fieldId = fieldMappings[dataKey];
                                if (existingData[dataKey]) {
                                    const field = document.getElementById(fieldId) || document.querySelector(`[name="${fieldId}"]`);
                                    if (field) {
                                        field.value = existingData[dataKey];
                                    }
                                }
                            });
                        }, 800);
                    }
                }
            }
            
            if (existingData.fiber_reference_no) {
                const fiberRef = document.getElementById('fiber_reference_no');
                if (fiberRef) {
                    fiberRef.value = existingData.fiber_reference_no;
                    // Trigger the reference data load
                    if (typeof loadFiberReferenceData === 'function') {
                        loadFiberReferenceData(existingData.fiber_reference_no);
                        
                        // Re-apply original values after lookup
                        setTimeout(() => {
                            Object.keys(fieldMappings).forEach(dataKey => {
                                const fieldId = fieldMappings[dataKey];
                                if (existingData[dataKey]) {
                                    const field = document.getElementById(fieldId) || document.querySelector(`[name="${fieldId}"]`);
                                    if (field) {
                                        field.value = existingData[dataKey];
                                    }
                                }
                            });
                        }, 800);
                    }
                }
            }
            
            if (existingData.yarn_reference_no) {
                const yarnRef = document.getElementById('yarn_reference_no');
                if (yarnRef) {
                    yarnRef.value = existingData.yarn_reference_no;
                }
            }
            
            // Auto-select the test method checkbox
            const selectedMethod = reportData.chosen_method;
            const selectedTestName = reportData.test_name;
            const testCheckboxes = document.querySelectorAll('.test-checkbox');
            
            testCheckboxes.forEach(cb => {
                const method = cb.getAttribute('data-method');
                const testName = cb.getAttribute('data-test-name');
                if (method === selectedMethod && testName === selectedTestName) {
                    cb.checked = true;
                    handleTestSelection(cb);
                    
                    // Wait for parameters to be generated, then pre-fill them
                    setTimeout(function() {
                        prefillTestData(existingData, selectedTestName);
                    }, 800);
                }
            });
            
        }, 300);
        
        function prefillTestData(data, testName) {
            // Pre-fill Thickness test data
            if (testName === 'Thickness (Under 2kPa Pressure)' && data.positions) {
                // Wait a bit longer to ensure the form is fully rendered
                setTimeout(() => {
                    // Group positions by type for average calculation
                    const groups = {
                        'Left': [],
                        'Middle Left': [],
                        'Middle Right': [],
                        'Right': []
                    };
                    
                    // Pre-fill values and group them
                    data.positions.forEach((pos, idx) => {
                        const i = idx + 1;
                        const posSelect = document.getElementById('astmd5199_position_' + i);
                        const thicknessInput = document.querySelector('input[name="astmd5199_under2kpa_' + i + '"]');
                        
                        if (posSelect && thicknessInput) {
                            // Set the value FIRST before any dropdown updates
                            posSelect.value = pos.position;
                            thicknessInput.value = pos.thickness;
                            
                            // Group by position type
                            const posType = pos.position.split('-')[0]; // "Left-1" -> "Left"
                            if (groups[posType]) {
                                groups[posType].push({
                                    index: i,
                                    value: parseFloat(pos.thickness)
                                });
                            }
                        }
                    });
                    
                    // DO NOT call updateThicknessDropdowns() in edit mode as it may reset pre-filled values
                    // The dropdowns already have their values set above
                    
                    // Calculate and display row averages for each group
                    setTimeout(() => {
                        Object.keys(groups).forEach(groupName => {
                            const groupData = groups[groupName];
                            if (groupData.length > 0) {
                                // Calculate group average
                                const sum = groupData.reduce((acc, item) => acc + item.value, 0);
                                const avg = (sum / groupData.length).toFixed(3);
                                
                                // Show average in the last row of this group
                                const lastIndex = groupData[groupData.length - 1].index;
                                const rows = document.querySelectorAll('tr.gsm-row');
                                if (rows[lastIndex - 1]) {
                                    const avgCell = rows[lastIndex - 1].querySelector('.avgCell');
                                    if (avgCell) {
                                        avgCell.textContent = avg;
                                    }
                                }
                            }
                        });
                        
                        // Pre-fill summary display fields
                        if (data.average !== undefined) {
                            const avgDisplay = document.getElementById('thk_summary_avg');
                            const sdDisplay = document.getElementById('thk_summary_sd');
                            const cvDisplay = document.getElementById('thk_summary_cv');
                            const maxDisplay = document.getElementById('thk_summary_max');
                            const minDisplay = document.getElementById('thk_summary_min');
                            
                            const avgHidden = document.getElementById('astmd5199_avg');
                            const sdHidden = document.getElementById('astmd5199_sd');
                            const cvHidden = document.getElementById('astmd5199_cv');
                            const maxHidden = document.getElementById('astmd5199_max');
                            const minHidden = document.getElementById('astmd5199_min');
                            
                            if (avgDisplay) avgDisplay.value = (data.average || 0).toFixed(3);
                            if (sdDisplay) sdDisplay.value = (data.sd || 0).toFixed(3);
                            if (cvDisplay) cvDisplay.value = (data.cv || 0).toFixed(2);
                            if (maxDisplay) maxDisplay.value = (data.max || 0).toFixed(3);
                            if (minDisplay) minDisplay.value = (data.min || 0).toFixed(3);
                            
                            if (avgHidden) avgHidden.value = (data.average || 0).toFixed(3);
                            if (sdHidden) sdHidden.value = (data.sd || 0).toFixed(3);
                            if (cvHidden) cvHidden.value = (data.cv || 0).toFixed(2);
                            if (maxHidden) maxHidden.value = (data.max || 0).toFixed(3);
                            if (minHidden) minHidden.value = (data.min || 0).toFixed(3);
                        }
                    }, 300);
            }, 200);
            }
            
            // Pre-fill GSM test data
            if (testName === 'Mass Per Unit Area (GSM)' && data.positions) {
                setTimeout(() => {
                    data.positions.forEach((pos, idx) => {
                        const i = idx + 1;
                        const posSelect = document.getElementById('gsm_position_' + i);
                        const weightInput = document.querySelector('input[name="gsm_weight_' + i + '"]');
                        const calcInput = document.querySelector('input[name="gsm_calculated_' + i + '"]');
                        
                        if (posSelect) {
                            posSelect.value = pos.position;
                        }
                        if (weightInput && pos.weight !== undefined) {
                            weightInput.value = pos.weight;
                        }
                        if (calcInput && pos.gsm !== undefined) {
                            calcInput.value = pos.gsm;
                        }
                    });
                    
                    // Trigger summary recalculation
                    setTimeout(() => {
                        const gsmBody = document.querySelector('[id^="gsmBody_"]');
                        if (gsmBody && typeof recalcGsmSummary === 'function') {
                            const suffixId = gsmBody.id.replace('gsmBody_', '');
                            recalcGsmSummary(suffixId);
                        }
                    }, 300);
                    
                    // Pre-fill summary statistics
                    setTimeout(() => {
                        if (data.average !== undefined) {
                            const avgInput = document.querySelector('input[name="gsm_avg"]');
                            const sdInput = document.querySelector('input[name="gsm_sd"]');
                            const cvInput = document.querySelector('input[name="gsm_cv"]');
                            const maxInput = document.querySelector('input[name="gsm_max"]');
                            const minInput = document.querySelector('input[name="gsm_min"]');
                            
                            if (avgInput) avgInput.value = (data.average || 0).toFixed(2);
                            if (sdInput) sdInput.value = (data.sd || 0).toFixed(2);
                            if (cvInput) cvInput.value = (data.cv || 0).toFixed(2);
                            if (maxInput) maxInput.value = (data.max || 0).toFixed(2);
                            if (minInput) minInput.value = (data.min || 0).toFixed(2);
                            
                            // Update display fields
                            const avgDisplay = document.getElementById('gsm_summary_avg');
                            const sdDisplay = document.getElementById('gsm_summary_sd');
                            const cvDisplay = document.getElementById('gsm_summary_cv');
                            const maxDisplay = document.getElementById('gsm_summary_max');
                            const minDisplay = document.getElementById('gsm_summary_min');
                            
                            if (avgDisplay) avgDisplay.value = (data.average || 0).toFixed(2);
                            if (sdDisplay) sdDisplay.value = (data.sd || 0).toFixed(2);
                            if (cvDisplay) cvDisplay.value = (data.cv || 0).toFixed(2);
                            if (maxDisplay) maxDisplay.value = (data.max || 0).toFixed(2);
                            if (minDisplay) minDisplay.value = (data.min || 0).toFixed(2);
                        }
                    }, 300);
                }, 200);
            }
            
            // Pre-fill Strip Tensile Test data
            if (testName === 'Strip Tensile Test' && data.strip_data) {
                setTimeout(() => {
                    data.strip_data.forEach((row, idx) => {
                        const i = idx + 1;
                        const posSelect = document.querySelector(`select[name="strip_position_${i}"]`);
                        const dirSelect = document.querySelector(`select[name="strip_direction_${i}"]`);
                        const strengthInput = document.querySelector(`input[name="strip_strength_${i}"]`);
                        const elongInput = document.querySelector(`input[name="strip_elongation_${i}"]`);
                        
                        if (posSelect) posSelect.value = row.position || '';
                        if (dirSelect) dirSelect.value = row.direction || '';
                        if (strengthInput) strengthInput.value = row.strength || '';
                        if (elongInput) elongInput.value = row.elongation || '';
                    });
                    
                    // Trigger summary recalculation after pre-filling
                    setTimeout(() => {
                        const stripBody = document.querySelector('[id^="stripBody_"]');
                        if (stripBody && typeof recalcStripSummary === 'function') {
                            const suffixId = stripBody.id.replace('stripBody_', '');
                            recalcStripSummary(suffixId);
                        }
                    }, 300);
                    
                    // Pre-fill summary statistics (if available)
                    setTimeout(() => {
                        if (data.summary) {
                            ['md', 'cd'].forEach(dir => {
                                if (data.summary[dir]) {
                                    const stats = data.summary[dir];
                                    
                                    // Strength stats
                                    const strengthAvg = document.querySelector(`input[name="strip_${dir}_strength_avg"]`);
                                    const strengthSd = document.querySelector(`input[name="strip_${dir}_strength_sd"]`);
                                    const strengthCv = document.querySelector(`input[name="strip_${dir}_strength_cv"]`);
                                    const strengthMax = document.querySelector(`input[name="strip_${dir}_strength_max"]`);
                                    const strengthMin = document.querySelector(`input[name="strip_${dir}_strength_min"]`);
                                    
                                    if (strengthAvg) strengthAvg.value = (stats.strength_avg || 0).toFixed(2);
                                    if (strengthSd) strengthSd.value = (stats.strength_sd || 0).toFixed(2);
                                    if (strengthCv) strengthCv.value = (stats.strength_cv || 0).toFixed(2);
                                    if (strengthMax) strengthMax.value = (stats.strength_max || 0).toFixed(2);
                                    if (strengthMin) strengthMin.value = (stats.strength_min || 0).toFixed(2);
                                    
                                    // Elongation stats
                                    const elongAvg = document.querySelector(`input[name="strip_${dir}_elongation_avg"]`);
                                    const elongSd = document.querySelector(`input[name="strip_${dir}_elongation_sd"]`);
                                    const elongCv = document.querySelector(`input[name="strip_${dir}_elongation_cv"]`);
                                    const elongMax = document.querySelector(`input[name="strip_${dir}_elongation_max"]`);
                                    const elongMin = document.querySelector(`input[name="strip_${dir}_elongation_min"]`);
                                    
                                    if (elongAvg) elongAvg.value = (stats.elongation_avg || 0).toFixed(2);
                                    if (elongSd) elongSd.value = (stats.elongation_sd || 0).toFixed(2);
                                    if (elongCv) elongCv.value = (stats.elongation_cv || 0).toFixed(2);
                                    if (elongMax) elongMax.value = (stats.elongation_max || 0).toFixed(2);
                                    if (elongMin) elongMin.value = (stats.elongation_min || 0).toFixed(2);
                                }
                            });
                        }
                    }, 300);
                }, 200);
            }
            
            // Pre-fill CBR test data
            if (testName === 'CBR Puncture Resistance' && data.cbr_data) {
                setTimeout(() => {
                    data.cbr_data.forEach((row, idx) => {
                        const i = idx + 1;
                        const posSelect = document.querySelector(`select[name="cbr_position_${i}"]`);
                        const forceInput = document.querySelector(`input[name="cbr_force_${i}"]`);
                        const displacementInput = document.querySelector(`input[name="cbr_displacement_${i}"]`);
                        
                        if (posSelect) posSelect.value = row.position || '';
                        if (forceInput) forceInput.value = row.force || '';
                        if (displacementInput) displacementInput.value = row.displacement || '';
                    });
                    
                    // Trigger summary recalculation
                    setTimeout(() => {
                        // Find the suffixId from the form
                        const cbrBody = document.querySelector('[id^="cbrBody_"]');
                        if (cbrBody) {
                            const suffixId = cbrBody.id.replace('cbrBody_', '');
                            if (typeof recalcCbrSummary === 'function') {
                                recalcCbrSummary(suffixId);
                            }
                        }
                        
                        // Also pre-fill summary if available (backup)
                        if (data.summary) {
                            // Force stats
                            if (data.summary.force) {
                                const forceAvg = document.querySelector('input[name="cbr_force_avg"]');
                                const forceSd = document.querySelector('input[name="cbr_force_sd"]');
                                const forceCv = document.querySelector('input[name="cbr_force_cv"]');
                                const forceMax = document.querySelector('input[name="cbr_force_max"]');
                                const forceMin = document.querySelector('input[name="cbr_force_min"]');
                                
                                if (forceAvg && !forceAvg.value) forceAvg.value = (data.summary.force.avg || 0).toFixed(2);
                                if (forceSd && !forceSd.value) forceSd.value = (data.summary.force.sd || 0).toFixed(2);
                                if (forceCv && !forceCv.value) forceCv.value = (data.summary.force.cv || 0).toFixed(2);
                                if (forceMax && !forceMax.value) forceMax.value = (data.summary.force.max || 0).toFixed(2);
                                if (forceMin && !forceMin.value) forceMin.value = (data.summary.force.min || 0).toFixed(2);
                            }
                            
                            // Displacement stats
                            if (data.summary.displacement) {
                                const displAvg = document.querySelector('input[name="cbr_displacement_avg"]');
                                const displSd = document.querySelector('input[name="cbr_displacement_sd"]');
                                const displCv = document.querySelector('input[name="cbr_displacement_cv"]');
                                const displMax = document.querySelector('input[name="cbr_displacement_max"]');
                                const displMin = document.querySelector('input[name="cbr_displacement_min"]');
                                
                                if (displAvg && !displAvg.value) displAvg.value = (data.summary.displacement.avg || 0).toFixed(2);
                                if (displSd && !displSd.value) displSd.value = (data.summary.displacement.sd || 0).toFixed(2);
                                if (displCv && !displCv.value) displCv.value = (data.summary.displacement.cv || 0).toFixed(2);
                                if (displMax && !displMax.value) displMax.value = (data.summary.displacement.max || 0).toFixed(2);
                                if (displMin && !displMin.value) displMin.value = (data.summary.displacement.min || 0).toFixed(2);
                            }
                        }
                    }, 400);
                }, 200);
            }
            
            // Pre-fill Grab Tensile Test data
            if (testName === 'Grab Tensile Test' && data.grab_data) {
                setTimeout(() => {
                    data.grab_data.forEach((row, idx) => {
                        const i = idx + 1;
                        const posSelect = document.querySelector(`select[name="grab_position_${i}"]`);
                        const dirSelect = document.querySelector(`select[name="grab_direction_${i}"]`);
                        const forceInput = document.querySelector(`input[name="grab_breaking_force_${i}"]`);
                        const elongInput = document.querySelector(`input[name="grab_elongation_${i}"]`);
                        
                        if (posSelect) posSelect.value = row.position || '';
                        if (dirSelect) dirSelect.value = row.direction || '';
                        if (forceInput) forceInput.value = row.breaking_force || '';
                        if (elongInput) elongInput.value = row.elongation || '';
                    });
                    
                    // Trigger summary recalculation after pre-filling
                    setTimeout(() => {
                        const grabBody = document.querySelector('[id^="grabBody_"]');
                        if (grabBody && typeof recalcGrabSummary === 'function') {
                            const suffixId = grabBody.id.replace('grabBody_', '');
                            recalcGrabSummary(suffixId);
                        }
                    }, 300);
                    
                    // Pre-fill summary statistics
                    setTimeout(() => {
                        if (data.summary) {
                            ['md', 'cd'].forEach(dir => {
                                if (data.summary[dir]) {
                                    const stats = data.summary[dir];
                                    
                                    // Breaking Force stats
                                    const forceAvg = document.querySelector(`input[name="grab_${dir}_breaking_force_avg"]`);
                                    const forceSd = document.querySelector(`input[name="grab_${dir}_breaking_force_sd"]`);
                                    const forceCv = document.querySelector(`input[name="grab_${dir}_breaking_force_cv"]`);
                                    const forceMax = document.querySelector(`input[name="grab_${dir}_breaking_force_max"]`);
                                    const forceMin = document.querySelector(`input[name="grab_${dir}_breaking_force_min"]`);
                                    
                                    if (forceAvg) forceAvg.value = (stats.breaking_force_avg || 0).toFixed(2);
                                    if (forceSd) forceSd.value = (stats.breaking_force_sd || 0).toFixed(2);
                                    if (forceCv) forceCv.value = (stats.breaking_force_cv || 0).toFixed(2);
                                    if (forceMax) forceMax.value = (stats.breaking_force_max || 0).toFixed(2);
                                    if (forceMin) forceMin.value = (stats.breaking_force_min || 0).toFixed(2);
                                    
                                    // Elongation stats
                                    const elongAvg = document.querySelector(`input[name="grab_${dir}_elongation_avg"]`);
                                    const elongSd = document.querySelector(`input[name="grab_${dir}_elongation_sd"]`);
                                    const elongCv = document.querySelector(`input[name="grab_${dir}_elongation_cv"]`);
                                    const elongMax = document.querySelector(`input[name="grab_${dir}_elongation_max"]`);
                                    const elongMin = document.querySelector(`input[name="grab_${dir}_elongation_min"]`);
                                    
                                    if (elongAvg) elongAvg.value = (stats.elongation_avg || 0).toFixed(2);
                                    if (elongSd) elongSd.value = (stats.elongation_sd || 0).toFixed(2);
                                    if (elongCv) elongCv.value = (stats.elongation_cv || 0).toFixed(2);
                                    if (elongMax) elongMax.value = (stats.elongation_max || 0).toFixed(2);
                                    if (elongMin) elongMin.value = (stats.elongation_min || 0).toFixed(2);
                                }
                            });
                        }
                    }, 300);
                }, 200);
            }
            
            // Pre-fill Fiber/Yarn test data
            if (data.fiber_table_data && Array.isArray(data.fiber_table_data)) {
                data.fiber_table_data.forEach((row, idx) => {
                    // Handle Fineness of Fiber specific fields
                    if (row.parameter === 'Unit Weight') {
                        const unitWeightResult = document.querySelector('input[name="unit_weight_result"]');
                        const unitWeightRemarks = document.querySelector('input[name="unit_weight_remarks"]');
                        if (unitWeightResult) unitWeightResult.value = row.result || '';
                        if (unitWeightRemarks) unitWeightRemarks.value = row.remarks || '';
                    }
                    
                    // Handle generic indexed fields
                    const i = idx + 1;
                    const resultInput = document.querySelector(`input[name="fiber_result_${i}"]`);
                    const remarksInput = document.querySelector(`input[name="fiber_remarks_${i}"]`);
                    
                    if (resultInput) resultInput.value = row.result || '';
                    if (remarksInput) remarksInput.value = row.remarks || '';
                });
            }
            
            if (data.yarn_table_data && Array.isArray(data.yarn_table_data)) {
                data.yarn_table_data.forEach((row, idx) => {
                    if (row.parameter && row.parameter.includes('Tenacity at Break')) {
                        const input = document.querySelector('input[name="yarn_tenacity_at_break"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="yarn_tenacity_at_break_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    } else if (row.parameter && row.parameter.includes('Std Deviation')) {
                        const input = document.querySelector('input[name="yarn_std_deviation"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="yarn_std_deviation_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    } else if (row.parameter && row.parameter.includes('CV%')) {
                        const input = document.querySelector('input[name="yarn_cv_percent"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="yarn_cv_percent_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    } else if (row.parameter && row.parameter.includes('Elongation at Break')) {
                        const input = document.querySelector('input[name="yarn_elongation_at_break"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="yarn_elongation_at_break_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    }
                });
            }
            
            // Pre-fill Tenacity of Fiber test data
            if (data.tenacity_table_data && Array.isArray(data.tenacity_table_data)) {
                data.tenacity_table_data.forEach((row, idx) => {
                    if (row.parameter && row.parameter.includes('Tenacity at Break')) {
                        const input = document.querySelector('input[name="tenacity_at_break"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="tenacity_at_break_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    } else if (row.parameter && row.parameter.includes('Std Deviation')) {
                        const input = document.querySelector('input[name="std_deviation"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="std_deviation_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    } else if (row.parameter && row.parameter.includes('CV%')) {
                        const input = document.querySelector('input[name="cv_percentage"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="cv_percentage_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    } else if (row.parameter && row.parameter.includes('Elongation at Break')) {
                        const input = document.querySelector('input[name="elongation_at_break"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="elongation_at_break_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    } else if (row.parameter && row.parameter.includes('Breaking Force')) {
                        const input = document.querySelector('input[name="breaking_force"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="breaking_force_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    }
                });
            }
            
            // Pre-fill Cut Length of Fiber test data
            if (data.cut_length_table_data && Array.isArray(data.cut_length_table_data)) {
                data.cut_length_table_data.forEach((row, idx) => {
                    if (row.parameter && row.parameter.includes('Cut Length')) {
                        const input = document.querySelector('input[name="cut_length_result"]');
                        if (input) input.value = row.result || '';
                        const remarksInput = document.querySelector('input[name="cut_length_remarks"]');
                        if (remarksInput) remarksInput.value = row.remarks || '';
                    }
                });
            }
            
            // Pre-fill fiber/yarn sample info fields
            if (data.fiber_sample_name) {
                const input = document.querySelector('input[name="fiber_sample_name"]');
                if (input) input.value = data.fiber_sample_name;
            }
            if (data.yarn_sample_description) {
                const input = document.querySelector('input[name="yarn_sample_description"]');
                if (input) input.value = data.yarn_sample_description;
            }
            if (data.yarn_sample_received_from) {
                const input = document.querySelector('input[name="yarn_sample_received_from"]');
                if (input) input.value = data.yarn_sample_received_from;
            }
            if (data.yarn_sample_collected_from) {
                const input = document.querySelector('input[name="yarn_sample_collected_from"]');
                if (input) input.value = data.yarn_sample_collected_from;
            }
            
            console.log('Test data pre-filled successfully');
        }
        });
    </script>
    <?php endif; ?>

    <!-- Checker Rejection Modal -->
    <div id="checkerRejectModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; overflow-y:auto;">
      <div style="background:#fff; max-width:550px; margin:50px auto; padding:35px; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
        <h2 style="margin-top:0; color:#f44336; margin-bottom:20px;"><i class="fas fa-exclamation-triangle"></i> Reject QC Test</h2>
        <form method="POST" id="checkerRejectForm">
          <input type="hidden" name="checker_report_number" id="checkerRejectReportNumber">
          <input type="hidden" name="checker_action" value="rejected">
          
          <div class="form-group" style="margin-bottom:25px;">
            <label style="font-weight:600; margin-bottom:12px; display:block; color:#333; font-size:15px;">Rejection Reasons: <span style="color:#dc3545;">*</span></label>
            <div style="background:#f8f9fa; padding:15px; border-radius:6px; border:1px solid #dee2e6;">
              <label style="display:flex; align-items:center; padding:10px; margin-bottom:8px; background:#fff; border-radius:4px; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#e9ecef'" onmouseout="this.style.background='#fff'">
                <input type="checkbox" name="rejection_reasons[]" value="Incomplete data" style="width:18px; height:18px; margin-right:12px; cursor:pointer;">
                <span style="color:#333; font-size:14px;">Incomplete data</span>
              </label>
              <label style="display:flex; align-items:center; padding:10px; margin-bottom:8px; background:#fff; border-radius:4px; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#e9ecef'" onmouseout="this.style.background='#fff'">
                <input type="checkbox" name="rejection_reasons[]" value="Incorrect measurements" style="width:18px; height:18px; margin-right:12px; cursor:pointer;">
                <span style="color:#333; font-size:14px;">Incorrect measurements</span>
              </label>
              <label style="display:flex; align-items:center; padding:10px; margin-bottom:8px; background:#fff; border-radius:4px; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#e9ecef'" onmouseout="this.style.background='#fff'">
                <input type="checkbox" name="rejection_reasons[]" value="Missing required fields" style="width:18px; height:18px; margin-right:12px; cursor:pointer;">
                <span style="color:#333; font-size:14px;">Missing required fields</span>
              </label>
              <label style="display:flex; align-items:center; padding:10px; margin-bottom:8px; background:#fff; border-radius:4px; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#e9ecef'" onmouseout="this.style.background='#fff'">
                <input type="checkbox" name="rejection_reasons[]" value="Data inconsistency" style="width:18px; height:18px; margin-right:12px; cursor:pointer;">
                <span style="color:#333; font-size:14px;">Data inconsistency</span>
              </label>
              <label style="display:flex; align-items:center; padding:10px; background:#fff; border-radius:4px; cursor:pointer; transition:all 0.2s;" onmouseover="this.style.background='#e9ecef'" onmouseout="this.style.background='#fff'">
                <input type="checkbox" name="rejection_reasons[]" value="Calculation errors" style="width:18px; height:18px; margin-right:12px; cursor:pointer;">
                <span style="color:#333; font-size:14px;">Calculation errors</span>
              </label>
            </div>
          </div>
          
          <div class="form-group" style="margin-bottom:25px;">
            <label for="checkerRejectComment" style="font-weight:600; display:block; color:#333; font-size:15px; margin-bottom:8px;">Additional Comments: <span style="color:#999; font-size:13px;">(Optional)</span></label>
            <textarea name="checker_comment" id="checkerRejectComment" rows="4" style="width:100%; padding:12px; border:1px solid #dee2e6; border-radius:6px; font-family:inherit; font-size:14px; resize:vertical;" placeholder="Add any additional details about the rejection..."></textarea>
          </div>
          
          <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:25px; padding-top:20px; border-top:1px solid #dee2e6;">
            <button type="button" onclick="closeCheckerRejectModal()" style="padding:10px 20px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:14px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#5a6268'" onmouseout="this.style.background='#6c757d'">
              Cancel
            </button>
            <button type="submit" style="padding:10px 20px; background:#dc3545; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:14px; font-weight:600; transition:background 0.3s;" onmouseover="this.style.background='#c82333'" onmouseout="this.style.background='#dc3545'">
              <i class="fas fa-times"></i> Reject Report
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Admin Rejection Modal -->
    <div id="adminRejectModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:10000; overflow-y:auto;">
      <div style="background:#fff; max-width:550px; margin:50px auto; padding:35px; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
        <h2 style="margin-top:0; color:#f44336; margin-bottom:20px;"><i class="fas fa-exclamation-triangle"></i> Reject QC Test</h2>
        <form method="POST" id="adminRejectForm">
          <input type="hidden" name="admin_report_number" id="adminRejectReportNumber">
          <input type="hidden" name="admin_action" value="rejected">
          
          <div class="form-group" style="margin-bottom:25px;">
            <label for="adminRejectComment" style="font-weight:600; display:block; color:#333; font-size:15px; margin-bottom:10px;">Rejection Reason: <span style="color:#dc3545;">*</span></label>
            <textarea name="admin_comment" id="adminRejectComment" rows="5" style="width:100%; padding:12px; border:1px solid #dee2e6; border-radius:6px; font-family:inherit; font-size:14px; resize:vertical;" placeholder="Please provide detailed reason for rejection..." required></textarea>
            <p style="color:#6c757d; font-size:12px; margin:8px 0 0 0;">This reason will be shown to the tester so they can make corrections.</p>
          </div>
          
          <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:25px; padding-top:20px; border-top:1px solid #dee2e6;">
            <button type="button" onclick="closeAdminRejectModal()" style="padding:10px 20px; background:#6c757d; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:14px; font-weight:500; transition:background 0.3s;" onmouseover="this.style.background='#5a6268'" onmouseout="this.style.background='#6c757d'">
              Cancel
            </button>
            <button type="submit" style="padding:10px 20px; background:#dc3545; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:14px; font-weight:600; transition:background 0.3s;" onmouseover="this.style.background='#c82333'" onmouseout="this.style.background='#dc3545'">
              <i class="fas fa-times"></i> Reject Report
            </button>
          </div>
        </form>
      </div>
    </div>

    <script>
    function confirmApproval() {
      return confirm('Are you sure you want to approve this QC Test?');
    }

    function openCheckerRejectModal(reportNumber) {
      document.getElementById('checkerRejectReportNumber').value = reportNumber;
      document.getElementById('checkerRejectModal').style.display = 'block';
    }

    function closeCheckerRejectModal() {
      document.getElementById('checkerRejectModal').style.display = 'none';
      document.getElementById('checkerRejectForm').reset();
    }

    function openAdminRejectModal(reportNumber) {
      document.getElementById('adminRejectReportNumber').value = reportNumber;
      document.getElementById('adminRejectModal').style.display = 'block';
    }

    function closeAdminRejectModal() {
      document.getElementById('adminRejectModal').style.display = 'none';
      document.getElementById('adminRejectForm').reset();
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
      const checkerModal = document.getElementById('checkerRejectModal');
      const adminModal = document.getElementById('adminRejectModal');
      if (event.target === checkerModal) {
        closeCheckerRejectModal();
      }
      if (event.target === adminModal) {
        closeAdminRejectModal();
      }
    }
    
    // Format Product Reference to standard format: 7.0L625OCT14-R43-GT0.9H0.1
    function formatProductReference() {
      const input = document.getElementById('product_reference');
      if (!input || !input.value.trim()) return;
      
      let value = input.value.trim().toUpperCase();
      
      // Remove all spaces and extra dashes for parsing
      let cleaned = value.replace(/\s+/g, '').replace(/-+/g, '-');
      
      // Parse components using regex patterns
      // Pattern: GSM(decimal or integer) + L + LineNum + Year(2digits) + MONTH(3letters) + Day(2digits) + R + RollNum + Batch
      const pattern = /^(\d+\.?\d*)L?(\d+)(\d{2})([A-Z]{3})(\d{1,2})R?(\d+)(.+)$/i;
      const match = cleaned.match(pattern);
      
      if (match) {
        const gsm = parseFloat(match[1]);
        const lineNum = parseInt(match[2]);
        const year = match[3];
        const month = match[4].toUpperCase();
        const day = match[5].padStart(2, '0');
        const rollNum = parseInt(match[6]);
        const batch = match[7].toUpperCase().replace(/^-/, '');
        
        // Format GSM (divide by 100 if greater than 100)
        const formattedGsm = gsm > 100 ? (gsm / 100).toFixed(1) : gsm.toFixed(1);
        
        // Build formatted reference
        const formatted = `${formattedGsm}L${lineNum}${year}${month}${day}-R${rollNum}-${batch}`;
        
        input.value = formatted;
      } else {
        // If pattern doesn't match, try to at least uppercase it
        input.value = value;
      }
    }
    </script>
</body>
</html>
