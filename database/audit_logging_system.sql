-- =====================================================
-- GEOTEX ENTERPRISE AUDIT LOGGING SYSTEM
-- Comprehensive audit trail for all critical operations
-- =====================================================

-- =====================================================
-- 1. CREATE AUDIT LOG TABLE
-- =====================================================

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(100) NOT NULL,
    record_id INT,
    action ENUM('INSERT', 'UPDATE', 'DELETE', 'APPROVE', 'REJECT', 'LOGIN', 'LOGOUT') NOT NULL,
    old_values JSON,
    new_values JSON,
    changed_by VARCHAR(100),
    user_id INT,
    ip_address VARCHAR(50),
    user_agent VARCHAR(255),
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_table (table_name),
    INDEX idx_audit_action (action),
    INDEX idx_audit_user (changed_by),
    INDEX idx_audit_date (changed_at),
    INDEX idx_audit_record (table_name, record_id)
) ENGINE=InnoDB ROW_FORMAT=COMPRESSED;

-- =====================================================
-- 2. AUDIT TRIGGERS FOR CRITICAL TABLES
-- =====================================================

-- Water Permeability Tests - INSERT
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_wpt_after_insert
AFTER INSERT ON water_permeability_tests
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, new_values, changed_by, user_id)
    VALUES (
        'water_permeability_tests',
        NEW.id,
        'INSERT',
        JSON_OBJECT(
            'report_number', NEW.report_number,
            'test_performed_by', NEW.test_performed_by,
            'status', NEW.status,
            'gsm', NEW.gsm,
            'roll_number', NEW.roll_number
        ),
        NEW.test_performed_by,
        NEW.reporter_id
    );
END$$
DELIMITER ;

-- Water Permeability Tests - UPDATE
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_wpt_after_update
AFTER UPDATE ON water_permeability_tests
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, changed_by, user_id)
    VALUES (
        'water_permeability_tests',
        NEW.id,
        'UPDATE',
        JSON_OBJECT(
            'status', OLD.status,
            'remarks', OLD.remarks,
            'approved_by', OLD.approved_by,
            'checked_by', OLD.checked_by
        ),
        JSON_OBJECT(
            'status', NEW.status,
            'remarks', NEW.remarks,
            'approved_by', NEW.approved_by,
            'checked_by', NEW.checked_by
        ),
        COALESCE(NEW.approved_by, NEW.checked_by, NEW.test_performed_by),
        NEW.reporter_id
    );
END$$
DELIMITER ;

-- QC Entries - INSERT
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_qc_after_insert
AFTER INSERT ON qc_entries
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, new_values, changed_by, user_id)
    VALUES (
        'qc_entries',
        NEW.id,
        'INSERT',
        JSON_OBJECT(
            'report_number', NEW.report_number,
            'tested_by', NEW.tested_by,
            'status', NEW.status,
            'project_id', NEW.project_id
        ),
        NEW.tested_by,
        NEW.reporter_id
    );
END$$
DELIMITER ;

-- QC Entries - UPDATE
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_qc_after_update
AFTER UPDATE ON qc_entries
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, changed_by, user_id)
    VALUES (
        'qc_entries',
        NEW.id,
        'UPDATE',
        JSON_OBJECT(
            'status', OLD.status,
            'remarks', OLD.remarks,
            'approved_by', OLD.approved_by
        ),
        JSON_OBJECT(
            'status', NEW.status,
            'remarks', NEW.remarks,
            'approved_by', NEW.approved_by
        ),
        COALESCE(NEW.approved_by, NEW.tested_by),
        NEW.reporter_id
    );
END$$
DELIMITER ;

-- Projects - INSERT
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_project_after_insert
AFTER INSERT ON projects
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, new_values, changed_by)
    VALUES (
        'projects',
        NEW.id,
        'INSERT',
        JSON_OBJECT(
            'project_name', NEW.project_name,
            'status', NEW.status,
            'description', NEW.description
        ),
        USER()
    );
END$$
DELIMITER ;

-- Projects - UPDATE
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_project_after_update
AFTER UPDATE ON projects
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, old_values, new_values, changed_by)
    VALUES (
        'projects',
        NEW.id,
        'UPDATE',
        JSON_OBJECT(
            'project_name', OLD.project_name,
            'status', OLD.status,
            'description', OLD.description
        ),
        JSON_OBJECT(
            'project_name', NEW.project_name,
            'status', NEW.status,
            'description', NEW.description
        ),
        USER()
    );
END$$
DELIMITER ;

-- FG Delivery - INSERT
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_fg_delivery_after_insert
AFTER INSERT ON fg_delivery
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, new_values, changed_by, user_id)
    VALUES (
        'fg_delivery',
        NEW.id,
        'INSERT',
        JSON_OBJECT(
            'challan_number', NEW.challan_number,
            'project_id', NEW.project_id,
            'quantity', NEW.quantity,
            'unit_price', NEW.unit_price,
            'delivery_date', NEW.delivery_date
        ),
        NEW.reporter_name,
        NEW.reporter_id
    );
END$$
DELIMITER ;

-- Production Entry - INSERT
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_production_after_insert
AFTER INSERT ON production_entry
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, new_values, changed_by, user_id)
    VALUES (
        'production_entry',
        NEW.id,
        'INSERT',
        JSON_OBJECT(
            'project_id', NEW.project_id,
            'production_quantity', NEW.production_quantity,
            'production_date', NEW.production_date,
            'shift', NEW.shift
        ),
        NEW.reporter_name,
        NEW.reporter_id
    );
END$$
DELIMITER ;

-- CNC Entries - INSERT
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS trg_cnc_after_insert
AFTER INSERT ON cnc_entries
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, new_values, changed_by, user_id)
    VALUES (
        'cnc_entries',
        NEW.id,
        'INSERT',
        JSON_OBJECT(
            'cnc_id', NEW.cnc_id,
            'project_id', NEW.project_id,
            'cnc_machine_id', NEW.cnc_machine_id,
            'cutting_type', NEW.cutting_type,
            'cutting_roll_quantity', NEW.cutting_roll_quantity
        ),
        USER(),
        NEW.reporter_id
    );
END$$
DELIMITER ;

-- User Login Audit (Manual - to be called from login.php)
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS log_user_login(
    IN p_user_id INT,
    IN p_username VARCHAR(100),
    IN p_ip_address VARCHAR(50),
    IN p_user_agent VARCHAR(255)
)
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, new_values, changed_by, user_id, ip_address, user_agent)
    VALUES (
        'new_user',
        p_user_id,
        'LOGIN',
        JSON_OBJECT('username', p_username, 'login_time', NOW()),
        p_username,
        p_user_id,
        p_ip_address,
        p_user_agent
    );
END$$
DELIMITER ;

-- User Logout Audit (Manual - to be called from logout.php)
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS log_user_logout(
    IN p_user_id INT,
    IN p_username VARCHAR(100),
    IN p_ip_address VARCHAR(50)
)
BEGIN
    INSERT INTO audit_log (table_name, record_id, action, new_values, changed_by, user_id, ip_address)
    VALUES (
        'new_user',
        p_user_id,
        'LOGOUT',
        JSON_OBJECT('username', p_username, 'logout_time', NOW()),
        p_username,
        p_user_id,
        p_ip_address
    );
END$$
DELIMITER ;

-- =====================================================
-- 3. AUDIT QUERY HELPER VIEWS
-- =====================================================

-- Recent audit activity
CREATE OR REPLACE VIEW v_recent_audit AS
SELECT 
    a.id,
    a.table_name,
    a.action,
    a.changed_by,
    a.changed_at,
    a.ip_address,
    CASE 
        WHEN a.table_name = 'water_permeability_tests' THEN JSON_UNQUOTE(JSON_EXTRACT(a.new_values, '$.report_number'))
        WHEN a.table_name = 'qc_entries' THEN JSON_UNQUOTE(JSON_EXTRACT(a.new_values, '$.report_number'))
        WHEN a.table_name = 'projects' THEN JSON_UNQUOTE(JSON_EXTRACT(a.new_values, '$.project_name'))
        ELSE CONCAT('ID: ', a.record_id)
    END as record_identifier
FROM audit_log a
ORDER BY a.changed_at DESC
LIMIT 100;

-- User activity summary
CREATE OR REPLACE VIEW v_user_activity_summary AS
SELECT 
    changed_by,
    COUNT(*) as total_actions,
    SUM(CASE WHEN action = 'INSERT' THEN 1 ELSE 0 END) as inserts,
    SUM(CASE WHEN action = 'UPDATE' THEN 1 ELSE 0 END) as updates,
    SUM(CASE WHEN action = 'DELETE' THEN 1 ELSE 0 END) as deletes,
    SUM(CASE WHEN action = 'LOGIN' THEN 1 ELSE 0 END) as logins,
    MAX(changed_at) as last_activity
FROM audit_log
GROUP BY changed_by
ORDER BY total_actions DESC;

-- Daily activity report
CREATE OR REPLACE VIEW v_daily_activity AS
SELECT 
    DATE(changed_at) as activity_date,
    table_name,
    action,
    COUNT(*) as action_count
FROM audit_log
GROUP BY DATE(changed_at), table_name, action
ORDER BY activity_date DESC, action_count DESC;

-- =====================================================
-- 4. AUDIT LOG CLEANUP PROCEDURE
-- =====================================================

-- Archive old audit logs (keep last 2 years in main table)
DELIMITER $$
CREATE PROCEDURE IF NOT EXISTS cleanup_old_audit_logs()
BEGIN
    DECLARE rows_archived INT;
    
    -- Create archive table if not exists
    CREATE TABLE IF NOT EXISTS audit_log_archive LIKE audit_log;
    
    -- Move records older than 2 years to archive
    INSERT INTO audit_log_archive
    SELECT * FROM audit_log
    WHERE changed_at < DATE_SUB(NOW(), INTERVAL 2 YEAR);
    
    SET rows_archived = ROW_COUNT();
    
    -- Delete archived records from main table
    DELETE FROM audit_log
    WHERE changed_at < DATE_SUB(NOW(), INTERVAL 2 YEAR);
    
    -- Log the cleanup action
    INSERT INTO audit_log (table_name, action, new_values, changed_by)
    VALUES (
        'audit_log',
        'DELETE',
        JSON_OBJECT('rows_archived', rows_archived, 'archive_date', NOW()),
        'SYSTEM'
    );
END$$
DELIMITER ;

-- =====================================================
-- AUDIT LOGGING SYSTEM READY
-- =====================================================

