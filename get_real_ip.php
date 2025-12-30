<?php
/**
 * Get Real IP Address Function
 * Properly detects the real IP address even behind proxies, load balancers, etc.
 */

function getRealIPAddress() {
    // Check for various IP address headers in order of reliability
    $ip_headers = [
        'HTTP_CF_CONNECTING_IP',     // Cloudflare
        'HTTP_CLIENT_IP',            // Client IP
        'HTTP_X_FORWARDED_FOR',      // Forwarded IP
        'HTTP_X_FORWARDED',          // Forwarded IP
        'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster client IP
        'HTTP_FORWARDED_FOR',        // Forwarded for
        'HTTP_FORWARDED',            // Forwarded
        'HTTP_X_REAL_IP',            // Real IP
        'HTTP_X_FORWARDED_FOR_IP',   // Forwarded for IP
        'REMOTE_ADDR'                // Remote address (fallback)
    ];
    
    foreach ($ip_headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            
            // Handle comma-separated IPs (take the first one)
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            
            // Validate IP address
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
            
            // If it's a private IP, still return it but log for debugging
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    
    // For localhost/development environment
    if (isset($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
        if ($ip === '127.0.0.1' || $ip === '::1' || $ip === 'localhost') {
            return '127.0.0.1 (Local)';
        }
        return $ip;
    }
    
    // Final fallback
    return '127.0.0.1 (Local)';
}

// Test function to show all available IP headers
function debugIPHeaders() {
    $headers = [
        'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? 'not set',
        'HTTP_CLIENT_IP' => $_SERVER['HTTP_CLIENT_IP'] ?? 'not set',
        'HTTP_X_FORWARDED_FOR' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'not set',
        'HTTP_X_FORWARDED' => $_SERVER['HTTP_X_FORWARDED'] ?? 'not set',
        'HTTP_X_CLUSTER_CLIENT_IP' => $_SERVER['HTTP_X_CLUSTER_CLIENT_IP'] ?? 'not set',
        'HTTP_FORWARDED_FOR' => $_SERVER['HTTP_FORWARDED_FOR'] ?? 'not set',
        'HTTP_FORWARDED' => $_SERVER['HTTP_FORWARDED'] ?? 'not set',
        'HTTP_X_REAL_IP' => $_SERVER['HTTP_X_REAL_IP'] ?? 'not set',
        'HTTP_CF_CONNECTING_IP' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? 'not set'
    ];
    
    return $headers;
}
?> 
