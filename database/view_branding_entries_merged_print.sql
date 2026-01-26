-- Merge print quantity in DB: same cnc_cutting_batch + bag_size → merged_print_qty = SUM(print_qty) only
-- Run in phpMyAdmin: select your database, open SQL tab, run this script.

-- 1) View: merged print qty per (cnc_cutting_batch, bag_size) — computed in DB
DROP VIEW IF EXISTS v_branding_merged_print_qty;
CREATE VIEW v_branding_merged_print_qty AS
SELECT
  TRIM(COALESCE(cnc_cutting_batch, '')) AS cnc_cutting_batch,
  TRIM(COALESCE(bag_size, '')) AS bag_size,
  SUM(COALESCE(print_qty, 0)) AS merged_print_qty
FROM branding_entries
GROUP BY TRIM(COALESCE(cnc_cutting_batch, '')), TRIM(COALESCE(bag_size, ''));

-- 2) Stored procedure: call after insert/update to refresh merged_print_qty in the table (merge done in DB)
DROP PROCEDURE IF EXISTS sp_refresh_branding_merged_print_qty;
DELIMITER $$
CREATE PROCEDURE sp_refresh_branding_merged_print_qty(
  IN p_cnc_cutting_batch VARCHAR(100),
  IN p_bag_size VARCHAR(100)
)
BEGIN
  UPDATE branding_entries e
  SET e.merged_print_qty = (
    SELECT total FROM (
      SELECT SUM(COALESCE(print_qty,0)) AS total
      FROM branding_entries
      WHERE TRIM(COALESCE(cnc_cutting_batch,'')) = TRIM(COALESCE(p_cnc_cutting_batch,''))
        AND TRIM(COALESCE(bag_size,'')) = TRIM(COALESCE(p_bag_size,''))
    ) t
  )
  WHERE TRIM(COALESCE(e.cnc_cutting_batch,'')) = TRIM(COALESCE(p_cnc_cutting_batch,''))
    AND TRIM(COALESCE(e.bag_size,'')) = TRIM(COALESCE(p_bag_size,''));
END$$
DELIMITER ;

-- Usage:
-- Merged totals per group:  SELECT * FROM v_branding_merged_print_qty;
-- After INSERT (from app or manual):  CALL sp_refresh_branding_merged_print_qty('CW-01', '1000mmx700mm');
-- Or join in queries:  SELECT b.*, m.merged_print_qty FROM branding_entries b LEFT JOIN v_branding_merged_print_qty m ON TRIM(COALESCE(b.cnc_cutting_batch,'')) = m.cnc_cutting_batch AND TRIM(COALESCE(b.bag_size,'')) = m.bag_size;
