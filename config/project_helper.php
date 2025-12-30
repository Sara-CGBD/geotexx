<?php
/**
 * Helper utilities for locking project selection to a single default project.
 */

if (!defined('DEFAULT_PROJECT_NAME')) {
    define('DEFAULT_PROJECT_NAME', 'OPERATION/CIL/GEO/2024-25/FEB-JUN/03');
}

if (!function_exists('ensureProjectsTable')) {
    /**
     * Ensure projects table exists with id + project_name.
     *
     * @param mysqli $conn
     * @return void
     */
    function ensureProjectsTable($conn)
    {
        if (!$conn) {
            return;
        }

        // Create table if missing
        $conn->query("
            CREATE TABLE IF NOT EXISTS projects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_name VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Add missing columns if schema is older
        $cols = [];
        if ($res = $conn->query("SHOW COLUMNS FROM projects")) {
            while ($r = $res->fetch_assoc()) {
                $cols[] = strtolower($r['Field']);
            }
        }
        if (!in_array('created_at', $cols, true)) {
            @$conn->query("ALTER TABLE projects ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
        }
    }
}

if (!function_exists('getDefaultProject')) {
    /**
     * Fetch the default project once and cache it in memory.
     *
     * @param mysqli $conn
     * @return array|null
     */
    function getDefaultProject($conn)
    {
        static $cachedProject = null;

        if ($cachedProject !== null) {
            return $cachedProject;
        }

        if (!$conn) {
            return null;
        }

        // Ensure table exists before queries
        ensureProjectsTable($conn);

        $stmt = $conn->prepare("SELECT id, project_name FROM projects WHERE project_name = ? LIMIT 1");
        if ($stmt) {
            $projectName = DEFAULT_PROJECT_NAME;
            $stmt->bind_param("s", $projectName);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                if ($row = $result->fetch_assoc()) {
                    $cachedProject = $row;
                }
            }
            $stmt->close();
        }

        if ($cachedProject === null) {
            // If still not found, check if table has any rows
            $maxRes = $conn->query("SELECT MAX(id) as max_id FROM projects");
            $maxId = 0;
            if ($maxRes && $row = $maxRes->fetch_assoc()) {
                $maxId = (int)($row['max_id'] ?? 0);
            }

            if ($maxId === 0) {
                // Table empty: insert default project with id starting at 1
                $insertStmt = $conn->prepare("INSERT INTO projects (id, project_name) VALUES (1, ?) ON DUPLICATE KEY UPDATE project_name = VALUES(project_name)");
                if ($insertStmt) {
                    $projectName = DEFAULT_PROJECT_NAME;
                    $insertStmt->bind_param("s", $projectName);
                    $insertStmt->execute();
                    $insertStmt->close();
                }
                // Align AUTO_INCREMENT to next id after current max
                $conn->query("ALTER TABLE projects AUTO_INCREMENT = 2");
            }

            // Fallback to the first available project (after ensuring a row exists)
            $fallback = $conn->query("SELECT id, project_name FROM projects ORDER BY project_name ASC LIMIT 1");
            if ($fallback && $fallbackRow = $fallback->fetch_assoc()) {
                $cachedProject = $fallbackRow;
            }
        }

        return $cachedProject;
    }
}

if (!function_exists('getDefaultProjectList')) {
    /**
     * Convenience wrapper that always returns an array (used by legacy code that expects multiple projects).
     *
     * @param mysqli $conn
     * @return array
     */
    function getDefaultProjectList($conn)
    {
        $project = getDefaultProject($conn);
        return $project ? [$project] : [];
    }
}


