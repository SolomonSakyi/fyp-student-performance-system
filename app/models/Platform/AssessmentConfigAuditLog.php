<?php

/**
 * AssessmentConfigAuditLog.php
 * Assessment Configuration Audit Log Model
 * 
 * @package EduTrack
 * @subpackage Models\Platform
 * @version 1.0
 */

require_once dirname(__DIR__, 2) . '/models/Platform/BaseModel.php';

class AssessmentConfigAuditLog extends BaseModel
{
    protected $table = 'assessment_config_audit_logs';

    protected $fillable = [
        'profile_id',
        'version_id',
        'action',
        'field_name',
        'old_value',
        'new_value',
        'reason',
        'changed_by'
    ];

    /**
     * Log a configuration change
     */
    public function log(array $data): bool
    {
        $sql = "INSERT INTO {$this->table} (
                    uuid, profile_id, version_id, action, field_name, 
                    old_value, new_value, reason, changed_by, created_at
                ) VALUES (UUID(), ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        return $this->db->query($sql, [
            $data['profile_id'] ?? null,
            $data['version_id'] ?? null,
            $data['action'] ?? 'update',
            $data['field_name'] ?? null,
            $data['old_value'] ?? null,
            $data['new_value'] ?? null,
            $data['reason'] ?? null,
            $data['changed_by'] ?? null
        ]);
    }

    /**
     * Get audit logs for a profile
     */
    public function getByProfile(int $profileId, int $limit = 50): array
    {
        $sql = "SELECT l.*, u.first_name, u.last_name, u.username
                FROM {$this->table} l
                LEFT JOIN platform_users u ON l.changed_by = u.id
                WHERE l.profile_id = ?
                ORDER BY l.created_at DESC
                LIMIT ?";
        return $this->rawFetch($sql, [$profileId, $limit]);
    }

    /**
     * Get audit logs for a version
     */
    public function getByVersion(int $versionId, int $limit = 50): array
    {
        $sql = "SELECT l.*, u.first_name, u.last_name, u.username
                FROM {$this->table} l
                LEFT JOIN platform_users u ON l.changed_by = u.id
                WHERE l.version_id = ?
                ORDER BY l.created_at DESC
                LIMIT ?";
        return $this->rawFetch($sql, [$versionId, $limit]);
    }

    /**
     * Get audit logs by action
     */
    public function getByAction(string $action, int $limit = 50): array
    {
        $sql = "SELECT l.*, u.first_name, u.last_name, u.username
                FROM {$this->table} l
                LEFT JOIN platform_users u ON l.changed_by = u.id
                WHERE l.action = ?
                ORDER BY l.created_at DESC
                LIMIT ?";
        return $this->rawFetch($sql, [$action, $limit]);
    }

    /**
     * Get audit logs by date range
     */
    public function getByDateRange(string $startDate, string $endDate, int $limit = 50): array
    {
        $sql = "SELECT l.*, u.first_name, u.last_name, u.username
                FROM {$this->table} l
                LEFT JOIN platform_users u ON l.changed_by = u.id
                WHERE DATE(l.created_at) BETWEEN ? AND ?
                ORDER BY l.created_at DESC
                LIMIT ?";
        return $this->rawFetch($sql, [$startDate, $endDate, $limit]);
    }

    /**
     * Get recent changes summary
     */
    public function getRecentSummary(int $profileId, int $days = 30): array
    {
        $sql = "SELECT 
                    action,
                    COUNT(*) as count,
                    DATE(created_at) as date
                FROM {$this->table}
                WHERE profile_id = ? 
                AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY action, DATE(created_at)
                ORDER BY date DESC, action";
        return $this->rawFetch($sql, [$profileId, $days]);
    }
}
