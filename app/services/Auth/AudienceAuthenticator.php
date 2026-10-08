<?php

/**
 * Audience Authenticator
 * Handles authentication for each login audience
 *
 * @package EduTrack
 * @subpackage Services\Auth
 * @version 1.0
 * @filepath app/services/Auth/AudienceAuthenticator.php
 */

class AudienceAuthenticator
{
    private $db;
    private $tenantContext;
    private $audienceService;
    private $auditService;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->tenantContext = TenantContext::getInstance();
        $this->audienceService = new AudienceService();
        $this->auditService = new AuditService();
    }

    /**
     * Authenticate user for a specific audience
     *
     * @param string $audience
     * @param string $identifier
     * @param string $password
     * @return array ['success' => bool, 'user' => array|null, 'error' => string|null]
     */
    public function authenticate(string $audience, string $identifier, string $password): array
    {
        // Validate audience
        if (!$this->audienceService->isValidAudience($audience)) {
            return [
                'success' => false,
                'user' => null,
                'error' => 'Invalid login audience.'
            ];
        }

        // Validate audience is available
        if (!$this->audienceService->isAudienceAvailable($audience)) {
            return [
                'success' => false,
                'user' => null,
                'error' => 'This login option is currently disabled.'
            ];
        }

        // Get tenant context
        $tenantId = $this->tenantContext->getTenantId();
        $schoolId = $this->tenantContext->getSchoolId();

        if (!$tenantId) {
            return [
                'success' => false,
                'user' => null,
                'error' => 'Tenant context not found.'
            ];
        }

        // Find user by identifier
        $user = $this->findUser($identifier, $tenantId, $audience);

        if (!$user) {
            $this->logFailedAttempt($audience, $identifier, $tenantId, 'user_not_found');
            return [
                'success' => false,
                'user' => null,
                'error' => $this->getErrorMessage($audience, 'invalid_credentials')
            ];
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            $this->logFailedAttempt($audience, $identifier, $tenantId, 'invalid_password', $user['id']);
            return [
                'success' => false,
                'user' => null,
                'error' => $this->getErrorMessage($audience, 'invalid_credentials')
            ];
        }

        // Check if user is active
        if (!$user['is_active']) {
            $this->logFailedAttempt($audience, $identifier, $tenantId, 'user_inactive', $user['id']);
            return [
                'success' => false,
                'user' => null,
                'error' => $this->getErrorMessage($audience, 'inactive')
            ];
        }

        // Verify audience-specific authorization
        $authorized = $this->verifyAudienceAuthorization($audience, $user, $tenantId, $schoolId);

        if (!$authorized['success']) {
            $this->logFailedAttempt($audience, $identifier, $tenantId, $authorized['reason'], $user['id']);
            return [
                'success' => false,
                'user' => null,
                'error' => $authorized['error']
            ];
        }

        // Build user context
        $userContext = $this->buildUserContext($audience, $user, $tenantId, $schoolId);

        if (!$userContext) {
            $this->logFailedAttempt($audience, $identifier, $tenantId, 'context_build_failed', $user['id']);
            return [
                'success' => false,
                'user' => null,
                'error' => 'Unable to build user context.'
            ];
        }

        // Log success
        $this->logSuccess($audience, $user['id'], $tenantId, $schoolId);

        return [
            'success' => true,
            'user' => $userContext,
            'error' => null
        ];
    }

    /**
     * Find user by identifier
     */
    private function findUser(string $identifier, int $tenantId, string $audience): ?array
    {
        // First try by username
        $user = $this->db->fetchOne(
            "SELECT u.*, p.id as person_id, p.first_name, p.last_name, p.primary_phone, p.email as person_email
             FROM platform_users u
             LEFT JOIN persons p ON u.person_id = p.id
             WHERE u.username = ? AND u.tenant_id = ? AND u.deleted_at IS NULL",
            [$identifier, $tenantId]
        );

        if ($user) {
            return $user;
        }

        // Try by email
        $user = $this->db->fetchOne(
            "SELECT u.*, p.id as person_id, p.first_name, p.last_name, p.primary_phone, p.email as person_email
             FROM platform_users u
             LEFT JOIN persons p ON u.person_id = p.id
             WHERE (p.email = ? OR u.email = ?) AND u.tenant_id = ? AND u.deleted_at IS NULL",
            [$identifier, $identifier, $tenantId]
        );

        if ($user) {
            return $user;
        }

        // For staff, try staff number
        if ($audience === 'staff') {
            $user = $this->db->fetchOne(
                "SELECT u.*, p.id as person_id, p.first_name, p.last_name, p.primary_phone, p.email as person_email
                 FROM platform_users u
                 LEFT JOIN persons p ON u.person_id = p.id
                 LEFT JOIN staff s ON s.person_id = p.id
                 WHERE s.staff_number = ? AND u.tenant_id = ? AND u.deleted_at IS NULL
                 AND (s.deleted_at IS NULL OR s.deleted_at = '')",
                [$identifier, $tenantId]
            );

            if ($user) {
                return $user;
            }
        }

        // For student, try student number
        if ($audience === 'student') {
            $user = $this->db->fetchOne(
                "SELECT u.*, p.id as person_id, p.first_name, p.last_name, p.primary_phone, p.email as person_email
                 FROM platform_users u
                 LEFT JOIN persons p ON u.person_id = p.id
                 LEFT JOIN students st ON st.id = u.student_id
                 WHERE st.student_number = ? AND u.tenant_id = ? AND u.deleted_at IS NULL
                 AND (st.deleted_at IS NULL OR st.deleted_at = '')",
                [$identifier, $tenantId]
            );

            if ($user) {
                return $user;
            }
        }

        return null;
    }

    /**
     * Verify audience-specific authorization
     */
    private function verifyAudienceAuthorization(string $audience, array $user, int $tenantId, ?int $schoolId): array
    {
        switch ($audience) {
            case 'staff':
                return $this->verifyStaffAuthorization($user, $tenantId, $schoolId);

            case 'student':
                return $this->verifyStudentAuthorization($user, $tenantId, $schoolId);

            case 'admin':
                return $this->verifyAdminAuthorization($user, $tenantId, $schoolId);

            default:
                return [
                    'success' => false,
                    'reason' => 'invalid_audience',
                    'error' => 'Invalid audience.'
                ];
        }
    }

    /**
     * Verify staff authorization
     */
    private function verifyStaffAuthorization(array $user, int $tenantId, ?int $schoolId): array
    {
        // Check if user has staff profile
        $staff = $this->db->fetchOne(
            "SELECT s.* FROM staff s
             WHERE s.person_id = ? AND s.tenant_id = ? AND (s.deleted_at IS NULL OR s.deleted_at = '')",
            [$user['person_id'], $tenantId]
        );

        if (!$staff) {
            return [
                'success' => false,
                'reason' => 'not_staff',
                'error' => 'You are not registered as staff.'
            ];
        }

        // Check if staff is active
        if (!$staff['is_active']) {
            return [
                'success' => false,
                'reason' => 'staff_inactive',
                'error' => 'Your staff account is inactive.'
            ];
        }

        // Check school access if school context is set
        if ($schoolId) {
            $hasAccess = $this->db->getValue(
                "SELECT COUNT(*) FROM staff_school_access 
                 WHERE staff_id = ? AND school_id = ?",
                [$staff['id'], $schoolId]
            );

            // If no direct access, check if staff has tenant-wide access
            if (!$hasAccess) {
                $isTenantAdmin = $this->db->getValue(
                    "SELECT COUNT(*) FROM staff_roles sr
                     JOIN roles r ON sr.role_id = r.id
                     WHERE sr.staff_id = ? AND r.tenant_id = ? AND r.role_code = 'tenant_admin'",
                    [$staff['id'], $tenantId]
                );

                if (!$isTenantAdmin) {
                    return [
                        'success' => false,
                        'reason' => 'wrong_school',
                        'error' => 'You are not authorized for this school.'
                    ];
                }
            }
        }

        return [
            'success' => true,
            'reason' => 'authorized',
            'error' => null,
            'staff' => $staff
        ];
    }

    /**
     * Verify student authorization
     */
    private function verifyStudentAuthorization(array $user, int $tenantId, ?int $schoolId): array
    {
        // Check if user has student profile
        $student = $this->db->fetchOne(
            "SELECT s.* FROM students s
             WHERE s.id = ? AND s.tenant_id = ? AND (s.deleted_at IS NULL OR s.deleted_at = '')",
            [$user['student_id'], $tenantId]
        );

        if (!$student) {
            return [
                'success' => false,
                'reason' => 'not_student',
                'error' => 'You are not registered as a student.'
            ];
        }

        // Check if student is active
        if ($student['enrollment_status'] !== 'Active') {
            return [
                'success' => false,
                'reason' => 'student_inactive',
                'error' => 'Your student account is inactive.'
            ];
        }

        // Check school access if school context is set
        if ($schoolId && $student['school_id'] != $schoolId) {
            return [
                'success' => false,
                'reason' => 'wrong_school',
                'error' => 'You are not enrolled in this school.'
            ];
        }

        return [
            'success' => true,
            'reason' => 'authorized',
            'error' => null,
            'student' => $student
        ];
    }

    /**
     * Verify admin authorization
     */
    private function verifyAdminAuthorization(array $user, int $tenantId, ?int $schoolId): array
    {
        // Check if user has admin role
        $adminRoles = $this->db->fetchAll(
            "SELECT r.id, r.role_name, r.role_code
             FROM platform_user_roles ur
             JOIN roles r ON ur.role_id = r.id
             WHERE ur.user_id = ? AND r.tenant_id = ? 
             AND r.role_code IN ('tenant_admin', 'school_admin', 'campus_admin')
             AND r.deleted_at IS NULL",
            [$user['id'], $tenantId]
        );

        if (empty($adminRoles)) {
            return [
                'success' => false,
                'reason' => 'not_admin',
                'error' => 'You do not have admin privileges.'
            ];
        }

        // Check school access if school context is set
        if ($schoolId) {
            $hasSchoolAccess = false;
            foreach ($adminRoles as $role) {
                if ($role['role_code'] === 'tenant_admin') {
                    $hasSchoolAccess = true;
                    break;
                }
                if ($role['role_code'] === 'school_admin') {
                    // Check if user has access to this specific school
                    $access = $this->db->getValue(
                        "SELECT COUNT(*) FROM user_school_access 
                         WHERE user_id = ? AND school_id = ?",
                        [$user['id'], $schoolId]
                    );
                    if ($access) {
                        $hasSchoolAccess = true;
                        break;
                    }
                }
            }

            if (!$hasSchoolAccess) {
                return [
                    'success' => false,
                    'reason' => 'wrong_school',
                    'error' => 'You are not authorized for this school.'
                ];
            }
        }

        return [
            'success' => true,
            'reason' => 'authorized',
            'error' => null,
            'admin_roles' => $adminRoles
        ];
    }

    /**
     * Build user context
     */
    private function buildUserContext(string $audience, array $user, int $tenantId, ?int $schoolId): array
    {
        // Get person info
        $person = $this->db->fetchOne(
            "SELECT * FROM persons WHERE id = ?",
            [$user['person_id']]
        );

        // Get roles
        $roles = $this->db->fetchAll(
            "SELECT r.id, r.role_name, r.role_code, r.description
             FROM platform_user_roles ur
             JOIN roles r ON ur.role_id = r.id
             WHERE ur.user_id = ? AND r.tenant_id = ? AND r.deleted_at IS NULL",
            [$user['id'], $tenantId]
        );

        // Get permissions
        $permissions = $this->db->fetchAll(
            "SELECT DISTINCT p.permission_code
             FROM platform_user_roles ur
             JOIN role_permissions rp ON ur.role_id = rp.role_id
             JOIN permissions p ON rp.permission_id = p.id
             WHERE ur.user_id = ? AND rp.deleted_at IS NULL AND p.deleted_at IS NULL",
            [$user['id']]
        );

        return [
            'user_id' => $user['id'],
            'person_id' => $user['person_id'],
            'username' => $user['username'],
            'email' => $user['email'] ?? $user['person_email'] ?? '',
            'first_name' => $person['first_name'] ?? '',
            'last_name' => $person['last_name'] ?? '',
            'full_name' => trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')),
            'login_audience' => $audience,
            'tenant_id' => $tenantId,
            'school_id' => $schoolId,
            'roles' => $roles,
            'permissions' => array_column($permissions, 'permission_code'),
            'is_active' => $user['is_active']
        ];
    }

    /**
     * Get error message
     */
    private function getErrorMessage(string $audience, string $errorKey): string
    {
        $messages = $this->audienceService->getAudienceErrorMessages($audience);
        return $messages[$errorKey] ?? 'Authentication failed.';
    }

    /**
     * Log failed attempt
     */
    private function logFailedAttempt(string $audience, string $identifier, int $tenantId, string $reason, ?int $userId = null): void
    {
        try {
            $this->db->execute(
                "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, tenant_id, details, created_at)
                 VALUES (?, 'login_failed', 'user', ?, ?, ?, NOW())",
                [
                    $userId,
                    $userId ?? 0,
                    $tenantId,
                    json_encode([
                        'audience' => $audience,
                        'identifier' => $identifier,
                        'reason' => $reason,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                    ])
                ]
            );
        } catch (Exception $e) {
            error_log('Audit log error: ' . $e->getMessage());
        }
    }

    /**
     * Log success
     */
    private function logSuccess(string $audience, int $userId, int $tenantId, ?int $schoolId): void
    {
        try {
            $this->db->execute(
                "INSERT INTO audit_logs (user_id, action, entity_type, entity_id, tenant_id, school_id, details, created_at)
                 VALUES (?, 'login', 'user', ?, ?, ?, ?, NOW())",
                [
                    $userId,
                    $userId,
                    $tenantId,
                    $schoolId,
                    json_encode([
                        'audience' => $audience,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                    ])
                ]
            );
        } catch (Exception $e) {
            error_log('Audit log error: ' . $e->getMessage());
        }
    }
}
