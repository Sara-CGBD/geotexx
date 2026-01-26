-- Optional: Auto-update branding quantities when sewing entries change.
-- Run this only if you want trigger-based updates (may conflict with application logic).
-- Requires a UNIQUE key on branding_entries (cnc_cutting_batch, bag_size, project_id, line_no)
--   e.g. ALTER TABLE branding_entries ADD UNIQUE KEY uk_branding_group (cnc_cutting_batch, bag_size(50), project_id, line_no(20));
-- Test on a copy of the database first.

DELIMITER $$

DROP TRIGGER IF EXISTS update_branding_after_sewing$$

CREATE TRIGGER update_branding_after_sewing
AFTER INSERT ON sewing_machine_entry
FOR EACH ROW
BEGIN
    -- Update or insert branding entry for this batch+bag_size+project_id+line_no group
    -- (Requires UNIQUE on cnc_cutting_batch, bag_size, project_id, line_no for ON DUPLICATE KEY UPDATE)
    INSERT INTO branding_entries 
        (cnc_cutting_batch, bag_size, project_id, line_no, print_qty, ncp_piece, sewing_entry_ids, status)
    SELECT 
        cnc_cutting_batch,
        COALESCE(bag_size, ''),
        project_id,
        COALESCE(line_no, ''),
        SUM(COALESCE(sewing_qty, 0)),
        SUM(COALESCE(ncp_piece, 0)),
        GROUP_CONCAT(id ORDER BY id),
        'pending'
    FROM sewing_machine_entry
    WHERE cnc_cutting_batch = NEW.cnc_cutting_batch 
        AND COALESCE(bag_size,'') = COALESCE(NEW.bag_size,'')
        AND project_id = NEW.project_id
        AND COALESCE(line_no,'') = COALESCE(NEW.line_no,'')
    GROUP BY cnc_cutting_batch, COALESCE(bag_size,''), project_id, COALESCE(line_no,'')
    ON DUPLICATE KEY UPDATE
        print_qty = VALUES(print_qty),
        ncp_piece = VALUES(ncp_piece),
        sewing_entry_ids = VALUES(sewing_entry_ids);
END$$

DELIMITER ;
