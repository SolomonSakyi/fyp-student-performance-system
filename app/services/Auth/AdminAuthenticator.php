<?php

/**
 * Admin Authenticator
 * Specialized authentication for admin users
 *
 * @package EduTrack
 * @subpackage Services\Auth
 * @version 1.0
 * @filepath app/services/Auth/AdminAuthenticator.php
 */

class AdminAuthenticator
{
    private $db;
    private $tenantContext;
    private $auditService;

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->tenantContext = TenantContext::getInstance();
        $this->auditService = new AuditService();
    }

    /**
     * Authenticate admin user
     */
    public function authenticate(string $identifier, string $password): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $schoolId = $this->tenantContext->getSchoolId();

        if (!$tenantId) {
            return [
                'success' => false,
                'error' => 'Tenant context not found.'
            ];
        }

        // Find admin user
        $adminUser = $this->findAdminUser($identifier, $tenantId);

        if (!$adminUser) {
            $this->auditService->logLoginFailure($tenantId, 'admin', $identifier, 'user_not_found');
            return [
                'success' => false,
                'error' => 'Invalid username or password.'
            ];
        }

        // Verify password
        if (!password_verify($password, $adminUser['password_hash'])) {
            $this->auditService->logLoginFailure($tenantId, 'admin', $identifier, 'invalid_password', $adminUser['user_id']);
            return [
                'success' => false,
                'error' => 'Invalid username or password.'
            ];
        }

        // Check if user is active
        if (!$adminUser['user_is_active']) {
            $this->auditService->logLoginFailure($tenantId, 'admin', $identifier, 'user_inactive', $adminUser['user_id']);
            return [
                'success' => false,
                'error' => 'Your account is inactive.'
            ];
        }

        // Verify admin roles
        $adminRoles = $this->getAdminRoles($adminUser['user_id'], $tenantId);

        if (empty($adminRoles)) {
            $this->auditService->logAudienceDenial($adminUser['user_id'], $tenantId, 'admin', 'not_admin');
            return [
                'success' => false,
                'error' => 'You do not have admin privileges.'
            ];
        }

        // Verify school access
        if ($schoolId && !$this->verifySchoolAccess($adminUser['user_id'], $schoolId, $tenantId, $adminRoles)) {
            $this->auditService->logSchoolScopeDenial($adminUser['user_id'], $tenantId, $schoolId, 'admin');
            return [
                'success' => false,
                'error' => 'You are not authorized for this school.'
            ];
        }

        // Build admin context
        $adminContext = $this->buildAdminContext($adminUser, $tenantId, $schoolId, $adminRoles);

        // Log success
        $this->auditService->logLoginSuccess(
            $adminUser['user_id'],
            $tenantId,
            $schoolId,
            null,
            'admin'
        );

        return [
            'success' => true,
            'user' => $adminContext,
            'error' => null
        ];
    }

    /**
     * Find admin user by identifier
     */
    private function findAdminUser(string $identifier, int $tenantId): ?array
    {
        // Try by username
        $user = $this->db->fetchOne("
            SELECT 
                u.id as user_id,
                u.username,
                u.email,
                u.password_hash,
                u.is_active as user_is_active,
                u.tenant_id,
                p.id as person_id,
                p.first_name,
                p.last_name,
                p.middle_name,
                p.primary_phone,
                p.secondary_phone,
                p.email as person_email
            FROM platform_users u
            LEFT JOIN persons p ON u.person_id = p.id
            WHERE u.username = ? 
              AND u.tenant_id = ?
              AND (u.deleted_at IS NULL OR u.deleted_at = '')
        ", [$identifier, $tenantId]);

        if ($user) {
            return $user;
        }

        // Try by email
        $user = $this->db->fetchOne("
            SELECT 
                u.id as user_id,
                u.username,
                u.email,
                u.password_hash,
                u.is_active as user_is_active,
                u.tenant_id,
                p.id as person_id,
                p.first_name,
                p.last_name,
                p.middle_name,
                p.primary_phone,
                p.secondary_phone,
                p.email as person_email
            FROM platform_users u
            LEFT JOIN persons p ON u.person_id = p.id
            WHERE (p.email = ? OR u.email = ?) 
              AND u.tenant_id = ?
              AND (u.deleted_at IS NULL OR u.deleted_at = '')
        ", [$identifier, $identifier, $tenantId]);

        return $user;
    }

    /**
     * Get admin roles for user
     */
    private function getAdminRoles(int $userId, int $tenantId): array
    {
        return $this->db->fetchAll("
            SELECT 
                r.id as role_id,
                r.role_name,
                r.role_code,
                r.description,
                ur.scope_type,
                ur.scope_id
            FROM platform_user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ? 
              AND r.tenant_id = ?
              AND r.role_code IN ('super_admin', 'tenant_admin', 'school_admin', 'campus_admin')
              AND r.deleted_at IS NULL
              AND ur.deleted_at IS NULL
        ", [$userId, $tenantId]);
    }

    /**
     * Verify admin has access to school
     */
    private function verifySchoolAccess(int $userId, int $schoolId, int $tenantId, array $adminRoles): bool
    {
        foreach ($adminRoles as $role) {
            // Super admin has access to everything
            if ($role['role_code'] === 'super_admin') {
                return true;
            }

            // Tenant admin has access to all schools in the tenant
            if ($role['role_code'] === 'tenant_admin') {
                return true;
            }

            // School admin needs specific school access
            if ($role['role_code'] === 'school_admin') {
                // Check direct school access
                $hasAccess = $this->db->getValue("
                    SELECT COUNT(*) FROM user_school_access 
                    WHERE user_id = ? AND school_id = ?
                ", [$userId, $schoolId]);

                if ($hasAccess) {
                    return true;
                }

                // Check if role scope is this school
                if ($role['scope_type'] === 'school' && $role['scope_id'] == $schoolId) {
                    return true;
                }
            }

            // Campus admin needs school access through campus
            if ($role['role_code'] === 'campus_admin') {
                if ($role['scope_type'] === 'campus') {
                    $campusSchool = $this->db->getValue("
                        SELECT school_id FROM campuses WHERE id = ?
                    ", [$role['scope_id']]);

                    if ($campusSchool == $schoolId) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Build admin context
     */
    private function buildAdminContext(array $user, int $tenantId, ?int $schoolId, array $adminRoles): array
    {
        // Get all permissions
        $permissions = $this->db->fetchAll("
            SELECT DISTINCT p.permission_code
            FROM platform_user_roles ur
            JOIN role_permissions rp ON ur.role_id = rp.role_id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE ur.user_id = ?
              AND rp.deleted_at IS NULL
              AND p.deleted_at IS NULL
        ", [$user['user_id']]);

        // Get tenant info
        $tenant = $this->db->fetchOne("
            SELECT id, tenant_name, tenant_code, status
            FROM tenants
            WHERE id = ?
        ", [$tenantId]);

        // Get school info if applicable
        $school = null;
        if ($schoolId) {
            $school = $this->db->fetchOne("
                SELECT id, school_name, school_code, status
                FROM schools
                WHERE id = ?
            ", [$schoolId]);
        }

        // Determine admin level
        $adminLevel = 'admin';
        foreach ($adminRoles as $role) {
            if ($role['role_code'] === 'super_admin') {
                $adminLevel = 'super_admin';
                break;
            }
            if ($role['role_code'] === 'tenant_admin') {
                $adminLevel = 'tenant_admin';
            }
        }

        return [
            'user_id' => $user['user_id'],
            'person_id' => $user['person_id'],
            'username' => $user['username'],
            'email' => $user['email'] ?? $user['person_email'] ?? '',
            'first_name' => $user['first_name'] ?? '',
            'last_name' => $user['last_name'] ?? '',
            'middle_name' => $user['middle_name'] ?? '',
            'full_name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'primary_phone' => $user['primary_phone'] ?? '',
            'secondary_phone' => $user['secondary_phone'] ?? '',
            'tenant_id' => $tenantId,
            'tenant_name' => $tenant['tenant_name'] ?? '',
            'tenant_code' => $tenant['tenant_code'] ?? '',
            'school_id' => $schoolId,
            'school_name' => $school['school_name'] ?? null,
            'school_code' => $school['school_code'] ?? null,
            'admin_roles' => $adminRoles,
            'permissions' => array_column($permissions, 'permission_code'),
            'admin_level' => $adminLevel,
            'login_audience' => 'admin',
            'is_active' => $user['user_is_active']
        ];
    }
}
