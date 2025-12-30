-- =====================================================
-- GEOTEX ENTERPRISE DATABASE OPTIMIZATION (SAFE VERSION)
-- Phase 1: Performance Indexes & Constraints
-- Safe execution with existence checks
-- =====================================================

-- Drop existing indexes if they exist (to recreate properly)
-- This is safe as MySQL ignores if index doesn't exist with IF EXISTS

-- =====================================================
-- 1. PERFORMANCE INDEXES (SAFE)
-- =====================================================

-- Water Permeability Tests (indexes already partially exist)
CREATE INDEX idx_wpt_report_number ON water_permeability_tests(report_number);
CREATE INDEX idx_wpt_status_date ON water_permeability_tests(status, test_date);

-- QC Entries
CREATE INDEX idx_qc_test_date ON qc_entries(test_date);
CREATE INDEX idx_qc_status ON qc_entries(status);
CREATE INDEX idx_qc_project ON qc_entries(project_id);
CREATE INDEX idx_qc_status_date ON qc_entries(status, test_date);

-- Characteristics Tests
CREATE INDEX idx_char_report_number ON characteristics_tests(report_number);
CREATE INDEX idx_char_test_date ON characteristics_tests(test_date);
CREATE INDEX idx_char_status ON characteristics_tests(status);

-- Sewing Thread Reports
CREATE INDEX idx_sewing_report_number ON sewing_thread_reports(report_number);
CREATE INDEX idx_sewing_test_date ON sewing_thread_reports(test_date);
CREATE INDEX idx_sewing_status ON sewing_thread_reports(status);

-- Fiber Test Reports
CREATE INDEX idx_fiber_report_number ON fiber_test_reports(report_number);
CREATE INDEX idx_fiber_test_date ON fiber_test_reports(test_date);
CREATE INDEX idx_fiber_status ON fiber_test_reports(status);

-- Sun Test Reports
CREATE INDEX idx_sun_report_number ON sun_test_reports(report_number);
CREATE INDEX idx_sun_test_date ON sun_test_reports(test_date);
CREATE INDEX idx_sun_status ON sun_test_reports(status);

-- Fabric Pre-Production Tests
CREATE INDEX idx_fabric_pre_report ON fabric_pre_production_tests(report_number);
CREATE INDEX idx_fabric_pre_date ON fabric_pre_production_tests(test_date);
CREATE INDEX idx_fabric_pre_status ON fabric_pre_production_tests(status);

-- Fabric After Production Tests
CREATE INDEX idx_fabric_after_report ON fabric_after_production_tests(report_number);
CREATE INDEX idx_fabric_after_date ON fabric_after_production_tests(test_date);
CREATE INDEX idx_fabric_after_status ON fabric_after_production_tests(status);

-- Projects
CREATE INDEX idx_project_status ON projects(status);
CREATE INDEX idx_project_created ON projects(created_at);
CREATE INDEX idx_project_name ON projects(project_name);

-- Finished Goods (FG)
CREATE INDEX idx_fg_project ON fg(project_id);
CREATE INDEX idx_fg_product ON fg(product_id);
CREATE INDEX idx_fg_date ON fg(received_date);
CREATE INDEX idx_fg_batch ON fg(batch_number);

-- FG Delivery
CREATE INDEX idx_fg_delivery_challan ON fg_delivery(challan_number);
CREATE INDEX idx_fg_delivery_date ON fg_delivery(delivery_date);
CREATE INDEX idx_fg_delivery_project ON fg_delivery(project_id);

-- Roll Entry
CREATE INDEX idx_roll_number ON roll_entry(roll_number);
CREATE INDEX idx_roll_batch ON roll_entry(batch_number);
CREATE INDEX idx_roll_date ON roll_entry(received_date);
CREATE INDEX idx_roll_project ON roll_entry(project_id);

-- Fiber to Roll Entry
CREATE INDEX idx_fiber_roll_number ON fiber_to_roll_entry(roll_number);
CREATE INDEX idx_fiber_roll_project ON fiber_to_roll_entry(project_id);

-- Production Entry
CREATE INDEX idx_production_shift ON production_entry(shift);
CREATE INDEX idx_production_project ON production_entry(project_id);

-- CNC Entries
CREATE INDEX idx_cnc_id ON cnc_entries(cnc_id);
CREATE INDEX idx_cnc_project ON cnc_entries(project_id);
CREATE INDEX idx_cnc_machine ON cnc_entries(cnc_machine_id);

-- Scrap Entry
CREATE INDEX idx_scrap_type ON scrap_entry(scrap_type);
CREATE INDEX idx_scrap_project ON scrap_entry(project_id);

-- BOM (Bill of Materials)
CREATE INDEX idx_bom_project ON bom(project_id);
CREATE INDEX idx_bom_product ON bom(product_id);

-- Target Entry
CREATE INDEX idx_target_shift ON target_entry(shift);
CREATE INDEX idx_target_project ON target_entry(project_id);

-- Users
CREATE INDEX idx_user_username ON new_user(username);
CREATE INDEX idx_user_role ON new_user(role);
CREATE INDEX idx_user_status ON new_user(status);

-- =====================================================
-- 2. ANALYZE TABLES FOR BETTER QUERY OPTIMIZATION
-- =====================================================

ANALYZE TABLE water_permeability_tests;
ANALYZE TABLE qc_entries;
ANALYZE TABLE characteristics_tests;
ANALYZE TABLE sewing_thread_reports;
ANALYZE TABLE fiber_test_reports;
ANALYZE TABLE sun_test_reports;
ANALYZE TABLE fabric_pre_production_tests;
ANALYZE TABLE fabric_after_production_tests;
ANALYZE TABLE projects;
ANALYZE TABLE fg;
ANALYZE TABLE fg_delivery;
ANALYZE TABLE roll_entry;
ANALYZE TABLE fiber_to_roll_entry;
ANALYZE TABLE production_entry;
ANALYZE TABLE cnc_entries;
ANALYZE TABLE scrap_entry;
ANALYZE TABLE bom;
ANALYZE TABLE target_entry;
ANALYZE TABLE new_user;

-- =====================================================
-- OPTIMIZATION PHASE 1 COMPLETE
-- =====================================================

SELECT 'Database optimization complete! Indexes added and tables analyzed.' AS Message;

