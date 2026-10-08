<?php

/**
 * Audit Service
 * Handles audit logging for authentication events
 *
 * @package EduTrack
 * @subpackage Services\Auth
 * @version 1.0
 * @filepath app/services/Auth/AuditService.php
 */

class AuditService
{
    private $db;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
    }

    /**
     * Log authentication event
     */
    public function logAuthEvent(
        string $action,
        ?int $userId,
        int $tenantId,
        ?int $schoolId,
        ?int $campusId,
        string $audience,
        string $result,
        string $reason = null,
        array $extra = []
    ): void {
        try {
            $details = array_merge([
                'audience' => $audience,
                'result' => $result,
                'reason' => $reason,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ], $extra);

            $this->db->execute(
                "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, tenant_id, school_id, campus_id, details, created_at)
                 VALUES (?, ?, 'auth', ?, ?, ?, ?, ?, NOW())",
                [
                    $userId,
                    $action,
                    $userId ?? 0,
                    $tenantId,
                    $schoolId,
                    $campusId,
                    json_encode($details)
                ]
            );
        } catch (Exception $e) {
            error_log('Audit log error: ' . $e->getMessage());
        }
    }

    /**
     * Log login success
     */
    public function logLoginSuccess(int $userId, int $tenantId, ?int $schoolId, ?int $campusId, string $audience): void
    {
        $this->logAuthEvent(
            'login',
            $userId,
            $tenantId,
            $schoolId,
            $campusId,
            $audience,
            'success'
        );
    }

    /**
     * Log login failure
     */
    public function logLoginFailure(int $tenantId, string $audience, string $identifier, string $reason): void
    {
        $this->logAuthEvent(
            'login_failed',
            null,
            $tenantId,
            null,
            null,
            $audience,
            'failed',
            $reason,
            ['identifier' => $identifier]
        );
    }

    /**
     * Log logout
     */
    public function logLogout(int $userId, int $tenantId, ?int $schoolId, ?int $campusId, string $audience): void
    {
        $this->logAuthEvent(
            'logout',
            $userId,
            $tenantId,
            $schoolId,
            $campusId,
            $audience,
            'success'
        );
    }

    /**
     * Log audience denial (user tried to login with wrong audience)
     */
    public function logAudienceDenial(int $userId, int $tenantId, string $audience, string $reason): void
    {
        $this->logAuthEvent(
            'login_denied',
            $userId,
            $tenantId,
            null,
            null,
            $audience,
            'denied',
            $reason
        );
    }

    /**
     * Log tenant scope denial
     */
    public function logTenantScopeDenial(int $userId, int $tenantId, string $audience): void
    {
        $this->logAuthEvent(
            'login_denied',
            $userId,
            $tenantId,
            null,
            null,
            $audience,
            'denied',
            'wrong_tenant'
        );
    }

    /**
     * Log school scope denial
     */
    public function logSchoolScopeDenial(int $userId, int $tenantId, int $schoolId, string $audience): void
    {
        $this->logAuthEvent(
            'login_denied',
            $userId,
            $tenantId,
            $schoolId,
            null,
            $audience,
            'denied',
            'wrong_school'
        );
    }

    /**
     * Log campus scope denial
     */
    public function logCampusScopeDenial(int $userId, int $tenantId, int $schoolId, int $campusId, string $audience): void
    {
        $this->logAuthEvent(
            'login_denied',
            $userId,
            $tenantId,
            $schoolId,
            $campusId,
            $audience,
            'denied',
            'wrong_campus'
        );
    }
}
