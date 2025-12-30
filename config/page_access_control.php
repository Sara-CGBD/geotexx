<?php
/**
 * Page Access Control Helper
 * Include this file at the top of protected pages to enforce access control
 * 
 * Usage:
 * require_once 'config/page_access_control.php';
 * requirePageAccess(AccessControl::MODULE_QC, AccessControl::PERMISSION_ENTRY);
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include AccessControl class
require_once __DIR__ . '/AccessControl.php';

/**
 * Require page access or redirect to login/error
 * @param string $module Module name (use AccessControl constants)
 * @param int $requiredPermission Minimum permission level required
 * @param string $redirectUrl Custom redirect URL (optional)
 */
function requirePageAccess($module, $requiredPermission = AccessControl::PERMISSION_VIEW, $redirectUrl = null) {
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        header("Location: ../login.html");
        exit();
    }
    
    $userRole = $_SESSION['role'];
    
    // Check access
    AccessControl::requireAccess($userRole, $module, $requiredPermission, $redirectUrl);
}

/**
 * Check if current user has access (returns boolean instead of exiting)
 * @param string $module Module name
 * @param int $requiredPermission Minimum permission level required
 * @return bool
 */
function hasPageAccess($module, $requiredPermission = AccessControl::PERMISSION_VIEW) {
    if (!isset($_SESSION['role'])) {
        return false;
    }
    
    return AccessControl::hasModuleAccess($_SESSION['role'], $module, $requiredPermission);
}

/**
 * Get current user info
 * @return array|null
 */
function getCurrentPageUser() {
    return AccessControl::getCurrentUser();
}

/**
 * Check if current user can approve
 * @param string $module Module name
 * @return bool
 */
function canCurrentUserApprove($module) {
    if (!isset($_SESSION['role'])) {
        return false;
    }
    
    return AccessControl::canApprove($_SESSION['role'], $module);
}

/**
 * Check if current user can check/verify
 * @param string $module Module name
 * @return bool
 */
function canCurrentUserCheck($module) {
    if (!isset($_SESSION['role'])) {
        return false;
    }
    
    return AccessControl::canCheck($_SESSION['role'], $module);
}

/**
 * Check if current user can enter data
 * @param string $module Module name
 * @return bool
 */
function canCurrentUserEntry($module) {
    if (!isset($_SESSION['role'])) {
        return false;
    }
    
    return AccessControl::canEntry($_SESSION['role'], $module);
}
?>


