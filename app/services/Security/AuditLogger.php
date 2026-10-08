<?php

/**
 * Audit Logger
 * Handles comprehensive audit logging for all system events
 *
 * @package EduTrack
 * @subpackage Services\Security
 * @version 1.0
 * @filepath app/services/Security/AuditLogger.php
 */

class AuditLogger
{
    private $db;
    private $tenantContext;

    // Audit event types
    const EVENT_LOGIN = 'login';
    const EVENT_LOGIN_FAILED = 'login_failed';
    const EVENT_LOGIN_DENIED = 'login_denied';
    const EVENT_LOGOUT = 'logout';
    const EVENT_PASSWORD_RESET = 'password_reset';
    const EVENT_PASSWORD_CHANGE = 'password_change';
    const EVENT_USER_CREATED = 'user_created';
    const EVENT_USER_UPDATED = 'user_updated';
    const EVENT_USER_DELETED = 'user_deleted';
    const EVENT_ROLE_ASSIGNED = 'role_assigned';
    const EVENT_ROLE_REMOVED = 'role_removed';
    const EVENT_PERMISSION_CHANGED = 'permission_changed';
    const EVENT_DATA_EXPORT = 'data_export';
    const EVENT_DATA_IMPORT = 'data_import';
    const EVENT_CONFIG_CHANGED = 'config_changed';
    const EVENT_ACCESS_DENIED = 'access_denied';
    const EVENT_SESSION_EXPIRED = 'session_expired';
    const EVENT_IP_BLOCKED = 'ip_blocked';
    const EVENT_IP_UNBLOCKED = 'ip_unblocked';
    const EVENT_RATE_LIMIT = 'rate_limit';
    const EVENT_SYSTEM_ERROR = 'system_error';

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->tenantContext = TenantContext::getInstance();
    }

    /**
     * Log an audit event
     *
     * @param string $event
     * @param array $data
     * @param int|null $userId
     * @param int|null $tenantId
     * @param int|null $schoolId
     * @param int|null $campusId
     */
    public function log(
        string $event,
        array $data = [],
        ?int $userId = null,
        ?int $tenantId = null,
        ?int $schoolId = null,
        ?int $campusId = null
    ): void {
        try {
            $userId = $userId ?? $this->tenantContext->getUserId();
            $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
            $schoolId = $schoolId ?? $this->tenantContext->getSchoolId();
            $campusId = $campusId ?? $this->tenantContext->getCampusId();

            $details = array_merge([
                'event' => $event,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'timestamp' => date('Y-m-d H:i:s')
            ], $data);

            $this->db->execute("
                INSERT INTO audit_logs (
                    user_id,
                    action,
                    entity_type,
                    entity_id,
                    tenant_id,
                    school_id,
                    campus_id,
                    details,
                    created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ", [
                $userId,
                $event,
                $data['entity_type'] ?? 'system',
                $data['entity_id'] ?? $userId ?? 0,
                $tenantId,
                $schoolId,
                $campusId,
                json_encode($details)
            ]);
        } catch (Exception $e) {
            error_log('Failed to log audit event: ' . $e->getMessage());
        }
    }

    /**
     * Log login event
     */
    public function logLogin(int $userId, string $audience, ?int $tenantId = null, ?int $schoolId = null): void
    {
        $this->log(self::EVENT_LOGIN, [
            'audience' => $audience,
            'entity_type' => 'user',
            'entity_id' => $userId
        ], $userId, $tenantId, $schoolId);
    }

    /**
     * Log login failed event
     */
    public function logLoginFailed(string $identifier, string $reason, ?int $tenantId = null): void
    {
        $this->log(self::EVENT_LOGIN_FAILED, [
            'identifier' => $identifier,
            'reason' => $reason,
            'entity_type' => 'auth'
        ], null, $tenantId);
    }

    /**
     * Log login denied event
     */
    public function logLoginDenied(int $userId, string $audience, string $reason, ?int $tenantId = null): void
    {
        $this->log(self::EVENT_LOGIN_DENIED, [
            'audience' => $audience,
            'reason' => $reason,
            'entity_type' => 'user',
            'entity_id' => $userId
        ], $userId, $tenantId);
    }

    /**
     * Log logout event
     */
    public function logLogout(int $userId, ?int $tenantId = null): void
    {
        $this->log(self::EVENT_LOGOUT, [
            'entity_type' => 'user',
            'entity_id' => $userId
        ], $userId, $tenantId);
    }

    /**
     * Log password reset event
     */
    public function logPasswordReset(int $userId, ?int $tenantId = null): void
    {
        $this->log(self::EVENT_PASSWORD_RESET, [
            'entity_type' => 'user',
            'entity_id' => $userId
        ], $userId, $tenantId);
    }

    /**
     * Log user created event
     */
    public function logUserCreated(int $userId, array $userData, ?int $tenantId = null): void
    {
        $this->log(self::EVENT_USER_CREATED, [
            'entity_type' => 'user',
            'entity_id' => $userId,
            'username' => $userData['username'] ?? '',
            'email' => $userData['email'] ?? ''
        ], $userId, $tenantId);
    }

    /**
     * Log user updated event
     */
    public function logUserUpdated(int $userId, array $changes, ?int $tenantId = null): void
    {
        $this->log(self::EVENT_USER_UPDATED, [
            'entity_type' => 'user',
            'entity_id' => $userId,
            'changes' => $changes
        ], $userId, $tenantId);
    }

    /**
     * Log user deleted event
     */
    public function logUserDeleted(int $userId, ?int $tenantId = null): void
    {
        $this->log(self::EVENT_USER_DELETED, [
            'entity_type' => 'user',
            'entity_id' => $userId
        ], $userId, $tenantId);
    }

    /**
     * Log role assigned event
     */
    public function logRoleAssigned(int $userId, int $roleId, string $roleName, ?int $tenantId = null): void
    {
        $this->log(self::EVENT_ROLE_ASSIGNED, [
            'entity_type' => 'user',
            'entity_id' => $userId,
            'role_id' => $roleId,
            'role_name' => $roleName
        ], $userId, $tenantId);
    }

    /**
     * Log role removed event
     */
    public function logRoleRemoved(int $userId, int $roleId, string $roleName, ?int $tenantId = null): void
    {
        $this->log(self::EVENT_ROLE_REMOVED, [
            'entity_type' => 'user',
            'entity_id' => $userId,
            'role_id' => $roleId,
            'role_name' => $roleName
        ], $userId, $tenantId);
    }

    /**
     * Log access denied event
     */
    public function logAccessDenied(int $userId, string $resource, string $action, ?int $tenantId = null): void
    {
        $this->log(self::EVENT_ACCESS_DENIED, [
            'entity_type' => 'access',
            'resource' => $resource,
            'action' => $action
        ], $userId, $tenantId);
    }

    /**
     * Log IP blocked event
     */
    public function logIPBlocked(string $ip, string $reason, ?int $tenantId = null): void
    {
        $this->log(self::EVENT_IP_BLOCKED, [
            'entity_type' => 'ip',
            'ip' => $ip,
            'reason' => $reason
        ], null, $tenantId);
    }

    /**
     * Log IP unblocked event
     */
    public function logIPUnblocked(string $ip, ?int $tenantId = null): void
    {
        $this->log(self::EVENT_IP_UNBLOCKED, [
            'entity_type' => 'ip',
            'ip' => $ip
        ], null, $tenantId);
    }

    /**
     * Log system error event
     */
    public function logSystemError(string $error, string $context = '', ?int $tenantId = null): void
    {
        $this->log(self::EVENT_SYSTEM_ERROR, [
            'entity_type' => 'system',
            'error' => $error,
            'context' => $context
        ], null, $tenantId);
    }

    /**
     * Get audit logs with filters
     *
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getLogs(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (isset($filters['user_id'])) {
            $where[] = "user_id = ?";
            $params[] = $filters['user_id'];
        }

        if (isset($filters['tenant_id'])) {
            $where[] = "tenant_id = ?";
            $params[] = $filters['tenant_id'];
        }

        if (isset($filters['event'])) {
            $where[] = "action = ?";
            $params[] = $filters['event'];
        }

        if (isset($filters['entity_type'])) {
            $where[] = "entity_type = ?";
            $params[] = $filters['entity_type'];
        }

        if (isset($filters['date_from'])) {
            $where[] = "created_at >= ?";
            $params[] = $filters['date_from'];
        }

        if (isset($filters['date_to'])) {
            $where[] = "created_at <= ?";
            $params[] = $filters['date_to'];
        }

        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        $params[] = $limit;
        $params[] = $offset;

        $logs = $this->db->fetchAll("
            SELECT * FROM audit_logs
            {$whereClause}
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ", $params);

        // Get total count
        $countParams = $params;
        array_pop($countParams); // Remove offset
        array_pop($countParams); // Remove limit

        $total = $this->db->getValue("
            SELECT COUNT(*) FROM audit_logs
            {$whereClause}
        ", $countParams);

        return [
            'data' => $logs,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ];
    }

    /**
     * Get audit statistics
     *
     * @param int $days
     * @param int|null $tenantId
     * @return array
     */
    public function getStats(int $days = 30, ?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();

        $stats = $this->db->fetchOne("
            SELECT 
                COUNT(*) as total_events,
                COUNT(DISTINCT user_id) as unique_users,
                SUM(CASE WHEN action = 'login' THEN 1 ELSE 0 END) as logins,
                SUM(CASE WHEN action = 'login_failed' THEN 1 ELSE 0 END) as failed_logins,
                SUM(CASE WHEN action = 'login_denied' THEN 1 ELSE 0 END) as denied_logins,
                SUM(CASE WHEN action = 'access_denied' THEN 1 ELSE 0 END) as access_denied
            FROM audit_logs
            WHERE tenant_id = ?
              AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
              AND deleted_at IS NULL
        ", [$tenantId, $days]);

        // Get events by type
        $eventsByType = $this->db->fetchAll("
            SELECT action, COUNT(*) as count
            FROM audit_logs
            WHERE tenant_id = ?
              AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
              AND deleted_at IS NULL
            GROUP BY action
            ORDER BY count DESC
        ", [$tenantId, $days]);

        // Get daily activity
        $dailyActivity = $this->db->fetchAll("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM audit_logs
            WHERE tenant_id = ?
              AND created_at > DATE_SUB(NOW(), INTERVAL ? DAY)
              AND deleted_at IS NULL
            GROUP BY DATE(created_at)
            ORDER BY date DESC
        ", [$tenantId, $days]);

        return [
            'summary' => $stats,
            'events_by_type' => $eventsByType,
            'daily_activity' => $dailyActivity,
            'days' => $days
        ];
    }
}
