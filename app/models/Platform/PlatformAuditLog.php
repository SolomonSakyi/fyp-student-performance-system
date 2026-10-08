<?php

/**
 * PlatformAuditLog.php
 * Audit Log Model for Platform Operations
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 2.2
 * 
 * @filepath app/models/Platform/PlatformAuditLog.php
 *
 * v2.2 changes (2026-10-05) [ITEM-22]:
 *   - getActionTypes(), getModules() and getLogs() are now
 *     dual-schema aware. The class already had a fallback in
 *     checkTable() that flips $this->table to 'settings_audit_log'
 *     when platform_audit_logs does not exist. The three methods
 *     above were still written against the platform_audit_logs
 *     column set (action_type, module). If the fallback ever fired,
 *     those three methods raised SQLSTATE[42S22] "Unknown column".
 *     Each of the three methods now selects the correct column
 *     names based on $this->table:
 *
 *       platform_audit_logs : action_type, module
 *       settings_audit_log  : action,      setting_group
 *
 *     getLogs() maps the caller's 'action' and 'module' filter keys
 *     to the correct physical column names per schema. The public
 *     API (filter keys, return shape, signatures) is unchanged.
 *
 *   - log() and clearOldLogs() are unchanged: log() was already
 *     dual-schema aware (it selects its INSERT column list per
 *     table), and clearOldLogs() filters on created_at, which exists
 *     on both schemas.
 *
 * v2.1 changes (2026-10-05) [ITEM-11b-2] [ITEM-11-3]:
 *   - log() now supplies the uuid column in its settings_audit_log
 *     INSERT. The column is VARCHAR(36) NOT NULL with a unique key
 *     and no default; the previous INSERT omitted it, which caused
 *     either a strict-mode failure ("Field 'uuid' doesn't have a
 *     default value") or an empty-string substitution that the unique
 *     key rejected on the second insert. The uuid is generated with
 *     bin2hex(random_bytes(16)), the same pattern used in
 *     SettingsService::logAudit() and in saveSettings() /
 *     saveSecuritySettings() since v2.2.
 *
 *   - [ITEM-11-3] Convention note: the settings_audit_log table
 *     carries both `setting_group` and `section`. Two conventions
 *     coexist by design:
 *       * `setting_group` is written by settings-level writers
 *         (SettingsService::logAudit(), and PlatformAuditLog's
 *         fallback branch when platform_audit_logs does not exist).
 *       * `section` is written by school-configuration writers
 *         (SchoolConfigurationService::logConfigurationChange(),
 *         with the literal value 'school_config').
 *     A reader must decide which convention its rows belong to
 *     before filtering on either column. The table was designed with
 *     both columns; there is no single-column convention.
 */

$projectRoot = dirname(__DIR__, 3) . '/';
require_once $projectRoot . 'app/helpers/DatabaseHelper.php';

class PlatformAuditLog
{
    /**
     * Database instance
     * @var DatabaseHelper
     */
    private $db;

    /**
     * Table name
     * @var string
     */
    private $table = 'platform_audit_logs';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        // Check if table exists, fallback to settings_audit_log
        $this->checkTable();
    }

    /**
     * Check which audit table exists
     */
    private function checkTable(): void
    {
        $result = $this->db->fetchOne("SHOW TABLES LIKE 'platform_audit_logs'");
        if (!$result) {
            $result = $this->db->fetchOne("SHOW TABLES LIKE 'settings_audit_log'");
            if ($result) {
                $this->table = 'settings_audit_log';
            }
        }
    }

    /**
     * Log an audit entry
     *
     * [v2.1 ITEM-11b-2] When writing to settings_audit_log, the uuid
     * column is now supplied. When writing to platform_audit_logs,
     * the column list is unchanged (that table does not carry a uuid
     * column per its own schema).
     *
     * @param array $data Log data
     * @return bool Success
     */
    public function log(array $data): bool
    {
        try {
            // Validate required fields
            if (empty($data['user_id']) && empty($data['action_type'])) {
                error_log('Audit log missing required fields');
                return false;
            }

            // Set default values
            $userId = $data['user_id'] ?? null;
            $actionType = $data['action_type'] ?? 'UNKNOWN';
            $module = $data['module'] ?? 'System';
            $resource = $data['resource'] ?? 'unknown';
            $resourceId = $data['resource_id'] ?? null;
            $tenantId = $data['tenant_id'] ?? null;
            $schoolId = $data['school_id'] ?? null;
            $campusId = $data['campus_id'] ?? null;

            $oldData = isset($data['old_data']) ? json_encode($data['old_data']) : null;
            $newData = isset($data['new_data']) ? json_encode($data['new_data']) : null;
            $ipAddress = $data['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $data['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null;

            // Use the appropriate table structure
            if ($this->table === 'settings_audit_log') {
                // [v2.1 ITEM-11b-2] uuid is now supplied.
                $sql = "INSERT INTO {$this->table} 
                        (uuid, tenant_id, school_id, campus_id, user_id, setting_group, setting_key,
                         action, old_value, new_value, ip_address, user_agent, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

                return $this->db->execute($sql, [
                    bin2hex(random_bytes(16)),
                    $tenantId,
                    $schoolId,
                    $campusId,
                    $userId,
                    $module,
                    $resource,
                    $actionType,
                    $oldData,
                    $newData,
                    $ipAddress,
                    $userAgent
                ]);
            } else {
                // Use platform_audit_logs table structure.
                // Unchanged: that table's column list does not include uuid.
                $sql = "INSERT INTO {$this->table} 
                        (user_id, action_type, module, resource, resource_id,
                         old_data, new_data, ip_address, user_agent, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

                return $this->db->execute($sql, [
                    $userId,
                    $actionType,
                    $module,
                    $resource,
                    $resourceId,
                    $oldData,
                    $newData,
                    $ipAddress,
                    $userAgent
                ]);
            }
        } catch (Exception $e) {
            error_log('PlatformAuditLog::log error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get audit logs with filters
     *
     * [v2.2 ITEM-22] The filter keys 'action' and 'module' are
     * mapped to the correct physical column names per schema:
     *   platform_audit_logs : action_type, module
     *   settings_audit_log  : action,      setting_group
     * The public filter keys are unchanged, so a caller that passes
     * ['action' => 'x', 'module' => 'y'] continues to work against
     * either schema.
     *
     * @param array $filters Filters
     * @return array Logs
     */
    public function getLogs(array $filters = []): array
    {
        try {
            // [v2.2 ITEM-22] Per-schema column names for the two
            // ambiguous filter keys.
            if ($this->table === 'settings_audit_log') {
                $colAction = 'action';
                $colModule = 'setting_group';
            } else {
                $colAction = 'action_type';
                $colModule = 'module';
            }

            $params = [];
            $conditions = [];

            if (isset($filters['tenant_id'])) {
                $conditions[] = "tenant_id = ?";
                $params[] = $filters['tenant_id'];
            }

            if (isset($filters['school_id'])) {
                $conditions[] = "school_id = ?";
                $params[] = $filters['school_id'];
            }

            if (isset($filters['user_id'])) {
                $conditions[] = "user_id = ?";
                $params[] = $filters['user_id'];
            }

            if (isset($filters['action'])) {
                $conditions[] = "{$colAction} = ?";
                $params[] = $filters['action'];
            }

            if (isset($filters['module'])) {
                $conditions[] = "{$colModule} = ?";
                $params[] = $filters['module'];
            }

            if (isset($filters['from'])) {
                $conditions[] = "created_at >= ?";
                $params[] = $filters['from'];
            }

            if (isset($filters['to'])) {
                $conditions[] = "created_at <= ?";
                $params[] = $filters['to'];
            }

            $where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";
            $order = "ORDER BY created_at DESC";
            $limit = isset($filters['limit']) ? "LIMIT " . (int)$filters['limit'] : "LIMIT 50";
            $offset = isset($filters['offset']) ? "OFFSET " . (int)$filters['offset'] : "OFFSET 0";

            $sql = "SELECT * FROM {$this->table} $where $order $limit $offset";
            $logs = $this->db->fetchAll($sql, $params);

            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM {$this->table} $where";
            $total = $this->db->fetchOne($countSql, $params);

            return [
                'logs' => $logs,
                'total' => (int)($total['total'] ?? 0)
            ];
        } catch (Exception $e) {
            error_log('PlatformAuditLog::getLogs error: ' . $e->getMessage());
            return ['logs' => [], 'total' => 0];
        }
    }

    /**
     * Clear logs older than days
     * 
     * @param int $days Days to keep
     * @param int|null $tenantId Tenant ID (optional)
     * @return int Number of rows deleted
     */
    public function clearOldLogs(int $days, ?int $tenantId = null): int
    {
        try {
            $params = [date('Y-m-d H:i:s', strtotime("-$days days"))];
            $sql = "DELETE FROM {$this->table} WHERE created_at < ?";

            if ($tenantId) {
                $sql .= " AND tenant_id = ?";
                $params[] = $tenantId;
            }

            return $this->db->execute($sql, $params);
        } catch (Exception $e) {
            error_log('PlatformAuditLog::clearOldLogs error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get unique action types
     *
     * [v2.2 ITEM-22] Reads the correct column per schema:
     *   platform_audit_logs : action_type
     *   settings_audit_log  : action
     *
     * @return array Action types
     */
    public function getActionTypes(): array
    {
        try {
            // [v2.2 ITEM-22] Per-schema column name.
            $column = ($this->table === 'settings_audit_log') ? 'action' : 'action_type';

            $sql = "SELECT DISTINCT {$column} AS action_type FROM {$this->table} ORDER BY {$column}";
            $results = $this->db->fetchAll($sql);
            return array_column($results, 'action_type');
        } catch (Exception $e) {
            error_log('PlatformAuditLog::getActionTypes error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get unique modules
     *
     * [v2.2 ITEM-22] Reads the correct column per schema:
     *   platform_audit_logs : module
     *   settings_audit_log  : setting_group
     *
     * @return array Modules
     */
    public function getModules(): array
    {
        try {
            // [v2.2 ITEM-22] Per-schema column name.
            $column = ($this->table === 'settings_audit_log') ? 'setting_group' : 'module';

            $sql = "SELECT DISTINCT {$column} AS module FROM {$this->table} ORDER BY {$column}";
            $results = $this->db->fetchAll($sql);
            return array_column($results, 'module');
        } catch (Exception $e) {
            error_log('PlatformAuditLog::getModules error: ' . $e->getMessage());
            return [];
        }
    }
}
