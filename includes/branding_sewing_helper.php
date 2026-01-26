<?php
/**
 * Helpers for branding based on grouped sewing data.
 * Use these when fetching data for branding or creating branding entries.
 */

/**
 * Fetch grouped sewing data for branding dropdown/options.
 * Groups by cnc_cutting_batch, bag_size, project_id, line_no and aggregates quantities.
 *
 * @param mysqli $conn
 * @return array Array of associative arrays with keys: cnc_cutting_batch, bag_size, project_id, line_no,
 *               total_sewing_qty, total_ncp_piece, sewing_entry_ids, merged_count
 */
function getGroupedSewingForBranding($conn) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'sewing_machine_entry'");
    $table = ($tableCheck && $tableCheck->num_rows > 0) ? 'sewing_machine_entry' : 'swing_machine_entry';

    $sql = "SELECT 
                cnc_cutting_batch,
                COALESCE(bag_size, '') as bag_size,
                project_id,
                COALESCE(line_no, '') as line_no,
                MAX(shift) as shift,
                SUM(COALESCE(sewing_qty, 0)) as total_sewing_qty,
                SUM(COALESCE(ncp_piece, 0)) as total_ncp_piece,
                GROUP_CONCAT(id ORDER BY id) as sewing_entry_ids,
                COUNT(*) as merged_count
            FROM `" . $conn->real_escape_string($table) . "`
            WHERE cnc_cutting_batch IS NOT NULL AND cnc_cutting_batch != ''
            GROUP BY cnc_cutting_batch, COALESCE(bag_size,''), project_id, COALESCE(line_no,'')
            HAVING COUNT(*) >= 1
            ORDER BY MAX(created_at) DESC";

    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }
    return mysqli_fetch_all($result, MYSQLI_ASSOC);
}

/**
 * Get total cutting quantity for a CNC cutting batch (optionally for a specific bag_size).
 *
 * @param mysqli $conn
 * @param string $cncCuttingBatch Batch number (e.g. CW-01)
 * @param string|null $bagSize If provided, sum only rows with this bag_size
 * @return int
 */
function getCuttingQuantity($conn, $cncCuttingBatch, $bagSize = null) {
    $batch = $conn->real_escape_string($cncCuttingBatch);
    $sql = "SELECT COALESCE(SUM(COALESCE(cutting_roll_quantity, 0)), 0) as cut_qty FROM cnc_entries WHERE cnc_cutting_batch = '$batch'";
    if ($bagSize !== null && $bagSize !== '') {
        $bag = $conn->real_escape_string($bagSize);
        $sql .= " AND TRIM(COALESCE(bag_size,'')) = '$bag'";
    }
    $result = $conn->query($sql);
    if (!$result || $result->num_rows === 0) {
        return 0;
    }
    $row = $result->fetch_assoc();
    return (int)($row['cut_qty'] ?? 0);
}
