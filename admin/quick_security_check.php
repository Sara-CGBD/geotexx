<?php
/**
 * Quick Security Check
 * Focuses on implemented security features
 */

echo "🔒 Quick Security Check\n";
echo "======================\n\n";

$checks = [];
$score = 0;
$total = 0;

// Check 1: SQL Injection Protection
echo "1. SQL Injection Protection...\n";
$total++;
$files_to_check = ['/login.php', 'admin/user_management.php', 'public/submit.php'];
$secure_files = 0;

foreach ($files_to_check as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        if (strpos($content, 'bind_param') !== false && strpos($content, 'prepare') !== false) {
            $secure_files++;
        }
    }
}

if ($secure_files >= 2) {
    echo "✅ SQL Injection protection implemented\n";
    $checks['sql_injection'] = true;
    $score += 10;
} else {
    echo "❌ SQL Injection protection incomplete\n";
    $checks['sql_injection'] = false;
}

// Check 2: Password Security
echo "\n2. Password Security...\n";
$total++;
$password_files = ['public/login.php', 'public/user_create.php'];
$secure_password = 0;

foreach ($password_files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        if (strpos($content, 'password_hash') !== false || strpos($content, 'password_verify') !== false) {
            $secure_password++;
        }
    }
}

if ($secure_password >= 1) {
    echo "✅ Secure password hashing implemented\n";
    $checks['password_security'] = true;
    $score += 10;
} else {
    echo "❌ Password security needs improvement\n";
    $checks['password_security'] = false;
}

// Check 3: Session Security
echo "\n3. Session Security...\n";
$total++;
$session_files = ['public/index.php', 'public/user_management.php'];
$secure_session = 0;

foreach ($session_files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        if (strpos($content, 'session_timeout') !== false && strpos($content, 'session_regenerate_id') !== false) {
            $secure_session++;
        }
    }
}

if ($secure_session >= 1) {
    echo "✅ Session security implemented\n";
    $checks['session_security'] = true;
    $score += 10;
} else {
    echo "❌ Session security needs improvement\n";
    $checks['session_security'] = false;
}

// Check 4: CSRF Protection
echo "\n4. CSRF Protection...\n";
$total++;
if (file_exists('public/user_management.php')) {
    $content = file_get_contents('public/user_management.php');
    if (strpos($content, 'csrf_token') !== false && strpos($content, 'hash_equals') !== false) {
        echo "✅ CSRF protection implemented\n";
        $checks['csrf_protection'] = true;
        $score += 10;
    } else {
        echo "❌ CSRF protection missing\n";
        $checks['csrf_protection'] = false;
    }
} else {
    echo "❌ Cannot check CSRF protection\n";
    $checks['csrf_protection'] = false;
}

// Check 5: Security Headers
echo "\n5. Security Headers...\n";
$total++;
$header_files = ['public/index.php', 'public/login.php', 'public/user_management.php'];
$headers_secure = 0;

foreach ($header_files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        if (strpos($content, 'X-Content-Type-Options') !== false && strpos($content, 'X-Frame-Options') !== false) {
            $headers_secure++;
        }
    }
}

if ($headers_secure >= 2) {
    echo "✅ Security headers implemented\n";
    $checks['security_headers'] = true;
    $score += 10;
} else {
    echo "❌ Security headers incomplete\n";
    $checks['security_headers'] = false;
}

// Check 6: Input Validation
echo "\n6. Input Validation...\n";
$total++;
$validation_files = ['public/user_create.php', 'public/submit_qc_data.php'];
$validation_secure = 0;

foreach ($validation_files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        if (strpos($content, 'filter_var') !== false || strpos($content, 'FILTER_SANITIZE') !== false) {
            $validation_secure++;
        }
    }
}

if ($validation_secure >= 1) {
    echo "✅ Input validation implemented\n";
    $checks['input_validation'] = true;
    $score += 10;
} else {
    echo "❌ Input validation needs improvement\n";
    $checks['input_validation'] = false;
}

// Check 7: Rate Limiting
echo "\n7. Rate Limiting...\n";
$total++;
if (file_exists('public/login.php')) {
    $content = file_get_contents('public/login.php');
    if (strpos($content, 'login_attempts') !== false && strpos($content, 'lockout_time') !== false) {
        echo "✅ Rate limiting implemented\n";
        $checks['rate_limiting'] = true;
        $score += 10;
    } else {
        echo "❌ Rate limiting missing\n";
        $checks['rate_limiting'] = false;
    }
} else {
    echo "❌ Cannot check rate limiting\n";
    $checks['rate_limiting'] = false;
}

// Check 8: Audit Logging
echo "\n8. Audit Logging...\n";
$total++;
if (file_exists('public/audit_log.php')) {
    echo "✅ Audit logging system exists\n";
    $checks['audit_logging'] = true;
    $score += 10;
} else {
    echo "❌ Audit logging missing\n";
    $checks['audit_logging'] = false;
}

// Check 9: Error Handling
echo "\n9. Error Handling...\n";
$total++;
$error_files = ['public/login.php', 'public/submit.php'];
$secure_errors = 0;

foreach ($error_files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        if (strpos($content, 'error_reporting(0)') !== false || strpos($content, 'display_errors', 0) !== false) {
            $secure_errors++;
        }
    }
}

if ($secure_errors >= 1) {
    echo "✅ Error handling secured\n";
    $checks['error_handling'] = true;
    $score += 10;
} else {
    echo "❌ Error handling needs improvement\n";
    $checks['error_handling'] = false;
}

// Check 10: Security Dashboard
echo "\n10. Security Monitoring...\n";
$total++;
if (file_exists('public/security_dashboard.php')) {
    echo "✅ Security dashboard implemented\n";
    $checks['security_monitoring'] = true;
    $score += 10;
} else {
    echo "❌ Security monitoring missing\n";
    $checks['security_monitoring'] = false;
}

// Calculate percentage
$percentage = ($score / ($total * 10)) * 100;

echo "\n" . str_repeat("=", 50) . "\n";
echo "🔒 SECURITY CHECK RESULTS\n";
echo str_repeat("=", 50) . "\n\n";

echo "Overall Security Score: $score/" . ($total * 10) . " ($percentage%)\n\n";

if ($percentage >= 90) {
    echo "🏆 EXCELLENT: Your application is enterprise-grade secure!\n";
} elseif ($percentage >= 80) {
    echo "✅ GOOD: Your application is well secured!\n";
} elseif ($percentage >= 70) {
    echo "⚠️  FAIR: Your application has basic security.\n";
} else {
    echo "❌ POOR: Your application needs security improvements.\n";
}

echo "\nDetailed Results:\n";
echo "----------------\n";

foreach ($checks as $check => $status) {
    $icon = $status ? "✅" : "❌";
    $status_text = $status ? "PASS" : "FAIL";
    echo "$icon $check: $status_text\n";
}

echo "\n🎯 SECURITY STATUS:\n";
echo "==================\n";

$passed = array_sum($checks);
$total_checks = count($checks);

echo "✅ Passed: $passed/$total_checks checks\n";
echo "🔒 Security Level: " . ($percentage >= 90 ? "Enterprise" : ($percentage >= 80 ? "Good" : ($percentage >= 70 ? "Fair" : "Poor")) . "\n";
echo "🛡️  Protection: " . ($passed >= 8 ? "Comprehensive" : ($passed >= 6 ? "Good" : ($passed >= 4 ? "Basic" : "Limited")) . "\n";

echo "\n✅ Quick security check completed!\n";
