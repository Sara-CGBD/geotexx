<?php
/**
 * AccessControl Class
 * Centralized role-based access control for the entire software
 * Manages permissions for all 9 modules across all user roles
 */

class AccessControl {
    
    // Define all valid roles
    const ROLE_ADMIN = 'admin';
    const ROLE_MANAGEMENT = 'management';
    const ROLE_PRODUCTION_USER = 'production_user';
    const ROLE_QC_INSPECTOR = 'qc_inspector';
    const ROLE_AGM_OPS = 'agm ops'; // or 'agm operations'
    const ROLE_TESTER = 'tester';
    const ROLE_CHECKER = 'checker';
    const ROLE_FINANCE_USER = 'finance_user';
    const ROLE_PLANNING_USER = 'planning_user';
    const ROLE_STORE_USER = 'store_user';
    const ROLE_DELIVERY_USER = 'delivery_user';
    const ROLE_SEWING_TEST = 'sewing_test';
    
    // Define all modules
    const MODULE_QC = 'quality_control';
    const MODULE_ROLL_PRODUCTION = 'roll_production';
    const MODULE_PRODUCTION = 'production';
    const MODULE_SCRAP = 'scrap_waste';
    const MODULE_FINISHED_GOODS = 'finished_goods';
    const MODULE_RECYCLE = 'recycle';
    const MODULE_PLANNING = 'planning';
    const MODULE_FINANCE = 'finance';
    const MODULE_ADMIN = 'admin_panel';
    
    // Permission levels
    const PERMISSION_NONE = 0;
    const PERMISSION_VIEW = 1;
    const PERMISSION_ENTRY = 2;
    const PERMISSION_APPROVE = 3;
    const PERMISSION_CHECK = 4;
    const PERMISSION_FULL = 5;
    
    /**
     * Module access matrix
     * Defines which roles can access which modules and their permission level
     */
    private static $modulePermissions = [
        self::MODULE_QC => [
            self::ROLE_ADMIN => self::PERMISSION_FULL,
            self::ROLE_MANAGEMENT => self::PERMISSION_VIEW,
            self::ROLE_QC_INSPECTOR => self::PERMISSION_ENTRY,
            self::ROLE_AGM_OPS => self::PERMISSION_APPROVE,
            self::ROLE_TESTER => self::PERMISSION_ENTRY,
            self::ROLE_CHECKER => self::PERMISSION_CHECK,
            self::ROLE_PRODUCTION_USER => self::PERMISSION_ENTRY,
        ],
        self::MODULE_ROLL_PRODUCTION => [
            self::ROLE_ADMIN => self::PERMISSION_FULL,
            self::ROLE_MANAGEMENT => self::PERMISSION_VIEW,
            self::ROLE_PRODUCTION_USER => self::PERMISSION_ENTRY,
            self::ROLE_AGM_OPS => self::PERMISSION_VIEW,
            self::ROLE_PLANNING_USER => self::PERMISSION_VIEW,
            self::ROLE_DELIVERY_USER => self::PERMISSION_ENTRY,
        ],
        self::MODULE_PRODUCTION => [
            self::ROLE_ADMIN => self::PERMISSION_FULL,
            self::ROLE_MANAGEMENT => self::PERMISSION_VIEW,
            self::ROLE_PRODUCTION_USER => self::PERMISSION_ENTRY,
            self::ROLE_AGM_OPS => self::PERMISSION_VIEW,
            self::ROLE_PLANNING_USER => self::PERMISSION_VIEW,
            self::ROLE_SEWING_TEST => self::PERMISSION_ENTRY,
        ],
        self::MODULE_SCRAP => [
            self::ROLE_ADMIN => self::PERMISSION_FULL,
            self::ROLE_MANAGEMENT => self::PERMISSION_VIEW,
            self::ROLE_PRODUCTION_USER => self::PERMISSION_ENTRY,
            self::ROLE_AGM_OPS => self::PERMISSION_VIEW,
        ],
        self::MODULE_FINISHED_GOODS => [
            self::ROLE_ADMIN => self::PERMISSION_FULL,
            self::ROLE_MANAGEMENT => self::PERMISSION_VIEW,
            self::ROLE_PRODUCTION_USER => self::PERMISSION_ENTRY,
            self::ROLE_AGM_OPS => self::PERMISSION_VIEW,
            self::ROLE_FINANCE_USER => self::PERMISSION_VIEW,
            self::ROLE_PLANNING_USER => self::PERMISSION_VIEW,
            self::ROLE_DELIVERY_USER => self::PERMISSION_ENTRY,
        ],
        self::MODULE_RECYCLE => [
            self::ROLE_ADMIN => self::PERMISSION_FULL,
            self::ROLE_MANAGEMENT => self::PERMISSION_VIEW,
            self::ROLE_PRODUCTION_USER => self::PERMISSION_ENTRY,
            self::ROLE_AGM_OPS => self::PERMISSION_VIEW,
        ],
        self::MODULE_PLANNING => [
            self::ROLE_ADMIN => self::PERMISSION_FULL,
            self::ROLE_MANAGEMENT => self::PERMISSION_VIEW,
            self::ROLE_AGM_OPS => self::PERMISSION_VIEW,
            self::ROLE_PLANNING_USER => self::PERMISSION_ENTRY,
        ],
        self::MODULE_FINANCE => [
            self::ROLE_ADMIN => self::PERMISSION_FULL,
            self::ROLE_MANAGEMENT => self::PERMISSION_VIEW,
            self::ROLE_FINANCE_USER => self::PERMISSION_FULL,
        ],
        self::MODULE_ADMIN => [
            self::ROLE_ADMIN => self::PERMISSION_FULL,
        ],
    ];
    
    /**
     * Check if user has access to a module
     * @param string $userRole User's role
     * @param string $module Module to check
     * @param int $requiredPermission Minimum permission level required (default: VIEW)
     * @return bool
     */
    public static function hasModuleAccess($userRole, $module, $requiredPermission = self::PERMISSION_VIEW) {
        // Normalize role (handle variations)
        $userRole = self::normalizeRole($userRole);
        
        // Admin always has full access
        if ($userRole === self::ROLE_ADMIN) {
            return true;
        }
        
        // Check if module exists in permissions
        if (!isset(self::$modulePermissions[$module])) {
            return false;
        }
        
        // Check if role has permission for this module
        if (!isset(self::$modulePermissions[$module][$userRole])) {
            return false;
        }
        
        // Check if permission level is sufficient
        return self::$modulePermissions[$module][$userRole] >= $requiredPermission;
    }
    
    /**
     * Normalize role name (handle variations like 'agm ops' vs 'agm operations')
     * @param string $role
     * @return string
     */
    private static function normalizeRole($role) {
        $role = strtolower(trim($role));
        
        // Handle variations
        if ($role === 'agm operations' || $role === 'agm_ops' || $role === 'agm_operations') {
            return self::ROLE_AGM_OPS;
        }
        
        return $role;
    }
    
    /**
     * Get user's permission level for a module
     * @param string $userRole User's role
     * @param string $module Module to check
     * @return int Permission level
     */
    public static function getPermissionLevel($userRole, $module) {
        $userRole = self::normalizeRole($userRole);
        
        // Admin always has full permission
        if ($userRole === self::ROLE_ADMIN) {
            return self::PERMISSION_FULL;
        }
        
        if (!isset(self::$modulePermissions[$module][$userRole])) {
            return self::PERMISSION_NONE;
        }
        
        return self::$modulePermissions[$module][$userRole];
    }
    
    /**
     * Check if user can view a module
     */
    public static function canView($userRole, $module) {
        return self::hasModuleAccess($userRole, $module, self::PERMISSION_VIEW);
    }
    
    /**
     * Check if user can enter data in a module
     */
    public static function canEntry($userRole, $module) {
        return self::hasModuleAccess($userRole, $module, self::PERMISSION_ENTRY);
    }
    
    /**
     * Check if user can approve in a module
     */
    public static function canApprove($userRole, $module) {
        return self::hasModuleAccess($userRole, $module, self::PERMISSION_APPROVE);
    }
    
    /**
     * Check if user can check/verify in a module
     */
    public static function canCheck($userRole, $module) {
        return self::hasModuleAccess($userRole, $module, self::PERMISSION_CHECK);
    }
    
    /**
     * Check if user has full access to a module
     */
    public static function hasFullAccess($userRole, $module) {
        return self::hasModuleAccess($userRole, $module, self::PERMISSION_FULL);
    }
    
    /**
     * Require access to a module or redirect/exit
     * @param string $userRole User's role
     * @param string $module Module to check
     * @param int $requiredPermission Minimum permission level
     * @param string $redirectUrl Where to redirect if no access (null = show error)
     */
    public static function requireAccess($userRole, $module, $requiredPermission = self::PERMISSION_VIEW, $redirectUrl = null) {
        if (!self::hasModuleAccess($userRole, $module, $requiredPermission)) {
            if ($redirectUrl) {
                header("Location: " . $redirectUrl);
                exit();
            } else {
                http_response_code(403);
                die("
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 100px auto; padding: 30px; border: 2px solid #e74c3c; border-radius: 10px; background: #ffe8e8;'>
                        <h2 style='color: #e74c3c; margin-top: 0;'>🚫 Access Denied</h2>
                        <p style='font-size: 16px; color: #333;'>You do not have permission to access this module.</p>
                        <p style='font-size: 14px; color: #666;'>Your role: <strong>" . htmlspecialchars($userRole) . "</strong></p>
                        <p style='font-size: 14px; color: #666;'>Required module: <strong>" . htmlspecialchars($module) . "</strong></p>
                        <a href='../index.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px;'>Return to Dashboard</a>
                    </div>
                ");
            }
        }
    }
    
    /**
     * Get all modules accessible by a role
     * @param string $userRole User's role
     * @return array Array of accessible modules with their permission levels
     */
    public static function getAccessibleModules($userRole) {
        $userRole = self::normalizeRole($userRole);
        $accessible = [];
        
        foreach (self::$modulePermissions as $module => $roles) {
            if (isset($roles[$userRole]) && $roles[$userRole] > self::PERMISSION_NONE) {
                $accessible[$module] = $roles[$userRole];
            }
        }
        
        return $accessible;
    }
    
    /**
     * Check if user is logged in and get their role
     * @return array|null Returns ['user_id', 'username', 'role', 'full_name'] or null
     */
    public static function getCurrentUser() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
            return null;
        }
        
        return [
            'user_id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? '',
            'role' => $_SESSION['role'],
            'full_name' => $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'
        ];
    }
    
    /**
     * Require user to be logged in
     * @param string $redirectUrl Where to redirect if not logged in
     */
    public static function requireLogin($redirectUrl = '../login.php') {
        if (!self::getCurrentUser()) {
            header("Location: " . $redirectUrl);
            exit();
        }
    }
    
    /**
     * Get permission level name
     * @param int $level Permission level
     * @return string
     */
    public static function getPermissionName($level) {
        switch ($level) {
            case self::PERMISSION_NONE: return 'No Access';
            case self::PERMISSION_VIEW: return 'View Only';
            case self::PERMISSION_ENTRY: return 'Data Entry';
            case self::PERMISSION_APPROVE: return 'Approve';
            case self::PERMISSION_CHECK: return 'Check/Verify';
            case self::PERMISSION_FULL: return 'Full Access';
            default: return 'Unknown';
        }
    }
    
    /**
     * Check if user can access specific QC forms
     * @param string $userRole User's role
     * @param string $formType Type of QC form (e.g., 'sewing_thread', 'uv_test', 'fabric_pre_prod', 'qc_test_order')
     * @return bool
     */
    public static function canAccessQCForm($userRole, $formType) {
        $userRole = self::normalizeRole($userRole);
        
        // Admin, AGM Ops, QC Inspector, Tester can access all QC forms
        if (in_array($userRole, [self::ROLE_ADMIN, self::ROLE_AGM_OPS, self::ROLE_QC_INSPECTOR, self::ROLE_TESTER])) {
            return true;
        }
        
        // Checker can access Fabric Pre-Production Test and QC Test Order
        if ($userRole === self::ROLE_CHECKER && in_array($formType, ['fabric_pre_prod', 'qc_test_order'])) {
            return true;
        }
        
        // Management can view all QC forms
        if ($userRole === self::ROLE_MANAGEMENT) {
            return true;
        }
        
        return false;
    }
}
?>


