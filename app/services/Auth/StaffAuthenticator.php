<?php

/**
 * Staff Authenticator
 * Specialized authentication for staff users
 *
 * @package EduTrack
 * @subpackage Services\Auth
 * @version 1.0
 * @filepath app/services/Auth/StaffAuthenticator.php
 */

class StaffAuthenticator
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
     * Authenticate staff user
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

        // Find staff user
        $staffUser = $this->findStaffUser($identifier, $tenantId);

        if (!$staffUser) {
            $this->auditService->logLoginFailure($tenantId, 'staff', $identifier, 'user_not_found');
            return [
                'success' => false,
                'error' => 'Invalid staff number or password.'
            ];
        }

        // Verify password
        if (!password_verify($password, $staffUser['password_hash'])) {
            $this->auditService->logLoginFailure($tenantId, 'staff', $identifier, 'invalid_password', $staffUser['user_id']);
            return [
                'success' => false,
                'error' => 'Invalid staff number or password.'
            ];
        }

        // Check if user is active
        if (!$staffUser['user_is_active']) {
            $this->auditService->logLoginFailure($tenantId, 'staff', $identifier, 'user_inactive', $staffUser['user_id']);
            return [
                'success' => false,
                'error' => 'Your account is inactive.'
            ];
        }

        // Check if staff is active
        if (!$staffUser['staff_is_active']) {
            $this->auditService->logLoginFailure($tenantId, 'staff', $identifier, 'staff_inactive', $staffUser['user_id']);
            return [
                'success' => false,
                'error' => 'Your staff profile is inactive.'
            ];
        }

        // Verify school access
        if ($schoolId && !$this->verifySchoolAccess($staffUser['staff_id'], $schoolId, $tenantId)) {
            $this->auditService->logSchoolScopeDenial($staffUser['user_id'], $tenantId, $schoolId, 'staff');
            return [
                'success' => false,
                'error' => 'You are not authorized for this school.'
            ];
        }

        // Build staff context
        $staffContext = $this->buildStaffContext($staffUser, $tenantId, $schoolId);

        // Log success
        $this->auditService->logLoginSuccess(
            $staffUser['user_id'],
            $tenantId,
            $schoolId,
            null,
            'staff'
        );

        return [
            'success' => true,
            'user' => $staffContext,
            'error' => null
        ];
    }

    /**
     * Find staff user by identifier
     */
    private function findStaffUser(string $identifier, int $tenantId): ?array
    {
        // Try by staff number
        $staff = $this->db->fetchOne("
            SELECT 
                s.id as staff_id,
                s.staff_number,
                s.is_active as staff_is_active,
                s.is_teaching_staff,
                s.staff_category_id,
                s.staff_type_id,
                s.job_title,
                s.department_id,
                s.tenant_id,
                u.id as user_id,
                u.username,
                u.email,
                u.password_hash,
                u.is_active as user_is_active,
                p.id as person_id,
                p.first_name,
                p.last_name,
                p.middle_name,
                p.primary_phone,
                p.secondary_phone,
                p.email as person_email,
                p.date_of_birth,
                p.gender
            FROM staff s
            LEFT JOIN platform_users u ON s.person_id = u.person_id
            LEFT JOIN persons p ON s.person_id = p.id
            WHERE s.staff_number = ? 
              AND s.tenant_id = ?
              AND (s.deleted_at IS NULL OR s.deleted_at = '')
              AND (u.deleted_at IS NULL OR u.deleted_at = '')
        ", [$identifier, $tenantId]);

        if ($staff) {
            return $staff;
        }

        // Try by email
        $staff = $this->db->fetchOne("
            SELECT 
                s.id as staff_id,
                s.staff_number,
                s.is_active as staff_is_active,
                s.is_teaching_staff,
                s.staff_category_id,
                s.staff_type_id,
                s.job_title,
                s.department_id,
                s.tenant_id,
                u.id as user_id,
                u.username,
                u.email,
                u.password_hash,
                u.is_active as user_is_active,
                p.id as person_id,
                p.first_name,
                p.last_name,
                p.middle_name,
                p.primary_phone,
                p.secondary_phone,
                p.email as person_email,
                p.date_of_birth,
                p.gender
            FROM staff s
            LEFT JOIN platform_users u ON s.person_id = u.person_id
            LEFT JOIN persons p ON s.person_id = p.id
            WHERE (p.email = ? OR u.email = ?) 
              AND s.tenant_id = ?
              AND (s.deleted_at IS NULL OR s.deleted_at = '')
              AND (u.deleted_at IS NULL OR u.deleted_at = '')
        ", [$identifier, $identifier, $tenantId]);

        return $staff;
    }

    /**
     * Verify staff has access to school
     */
    private function verifySchoolAccess(int $staffId, int $schoolId, int $tenantId): bool
    {
        // Check direct school access
        $hasAccess = $this->db->getValue("
            SELECT COUNT(*) FROM staff_school_access 
            WHERE staff_id = ? AND school_id = ?
        ", [$staffId, $schoolId]);

        if ($hasAccess) {
            return true;
        }

        // Check tenant-wide admin access
        $isTenantAdmin = $this->db->getValue("
            SELECT COUNT(*) FROM staff_roles sr
            JOIN roles r ON sr.role_id = r.id
            WHERE sr.staff_id = ? 
              AND r.tenant_id = ? 
              AND r.role_code = 'tenant_admin'
        ", [$staffId, $tenantId]);

        return $isTenantAdmin > 0;
    }

    /**
     * Build staff context
     */
    private function buildStaffContext(array $staff, int $tenantId, ?int $schoolId): array
    {
        // Get staff roles
        $roles = $this->db->fetchAll("
            SELECT r.id, r.role_name, r.role_code, r.description
            FROM staff_roles sr
            JOIN roles r ON sr.role_id = r.id
            WHERE sr.staff_id = ? AND r.tenant_id = ?
        ", [$staff['staff_id'], $tenantId]);

        // Get staff permissions
        $permissions = $this->db->fetchAll("
            SELECT DISTINCT p.permission_code
            FROM staff_roles sr
            JOIN role_permissions rp ON sr.role_id = rp.role_id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE sr.staff_id = ?
        ", [$staff['staff_id']]);

        // Get staff categories
        $categories = $this->db->fetchAll("
            SELECT id, category_name, category_code
            FROM staff_categories
            WHERE is_active = 1 AND deleted_at IS NULL
        ");

        // Get staff types
        $types = $this->db->fetchAll("
            SELECT id, staff_category_id, type_name, type_code
            FROM staff_types
            WHERE is_active = 1 AND deleted_at IS NULL
        ");

        // Get departments
        $departments = $this->db->fetchAll("
            SELECT id, department_name, department_code
            FROM departments
            WHERE tenant_id = ? AND deleted_at IS NULL
        ", [$tenantId]);

        return [
            'user_id' => $staff['user_id'],
            'person_id' => $staff['person_id'],
            'staff_id' => $staff['staff_id'],
            'staff_number' => $staff['staff_number'],
            'username' => $staff['username'] ?? '',
            'email' => $staff['email'] ?? $staff['person_email'] ?? '',
            'first_name' => $staff['first_name'] ?? '',
            'last_name' => $staff['last_name'] ?? '',
            'middle_name' => $staff['middle_name'] ?? '',
            'full_name' => trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? '')),
            'primary_phone' => $staff['primary_phone'] ?? '',
            'secondary_phone' => $staff['secondary_phone'] ?? '',
            'date_of_birth' => $staff['date_of_birth'] ?? null,
            'gender' => $staff['gender'] ?? '',
            'is_teaching_staff' => $staff['is_teaching_staff'] ?? 0,
            'staff_category_id' => $staff['staff_category_id'] ?? null,
            'staff_type_id' => $staff['staff_type_id'] ?? null,
            'job_title' => $staff['job_title'] ?? '',
            'department_id' => $staff['department_id'] ?? null,
            'tenant_id' => $tenantId,
            'school_id' => $schoolId,
            'roles' => $roles,
            'permissions' => array_column($permissions, 'permission_code'),
            'categories' => $categories,
            'types' => $types,
            'departments' => $departments,
            'login_audience' => 'staff',
            'is_active' => $staff['user_is_active'] && $staff['staff_is_active']
        ];
    }
}
