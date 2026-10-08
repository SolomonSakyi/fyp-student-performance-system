<?php

/**
 * RBAC Service
 * Manages Role-Based Access Control with scope enforcement
 *
 * @package EduTrack
 * @subpackage Services\Auth
 * @version 1.0
 * @filepath app/services/Auth/RBACService.php
 */

class RBACService
{
    private $db;
    private $tenantContext;
    private $cache = [];

    // Predefined system roles
    const ROLE_SUPER_ADMIN = 'super_admin';
    const ROLE_TENANT_ADMIN = 'tenant_admin';
    const ROLE_SCHOOL_ADMIN = 'school_admin';
    const ROLE_CAMPUS_ADMIN = 'campus_admin';
    const ROLE_TEACHER = 'teacher';
    const ROLE_CLASS_TEACHER = 'class_teacher';
    const ROLE_SUBJECT_TEACHER = 'subject_teacher';
    const ROLE_STAFF = 'staff';
    const ROLE_STUDENT = 'student';
    const ROLE_PARENT = 'parent';

    // Scope types
    const SCOPE_TENANT = 'tenant';
    const SCOPE_SCHOOL = 'school';
    const SCOPE_CAMPUS = 'campus';
    const SCOPE_GLOBAL = 'global';

    public function __construct()
    {
        $this->db = DatabaseHelper::getInstance();
        $this->tenantContext = TenantContext::getInstance();
    }

    /**
     * Get all system roles
     */
    public function getSystemRoles(): array
    {
        return [
            self::ROLE_SUPER_ADMIN => [
                'name' => 'Super Administrator',
                'description' => 'Full system access across all tenants',
                'scope' => self::SCOPE_GLOBAL,
                'level' => 100
            ],
            self::ROLE_TENANT_ADMIN => [
                'name' => 'Tenant Administrator',
                'description' => 'Full access within a tenant',
                'scope' => self::SCOPE_TENANT,
                'level' => 90
            ],
            self::ROLE_SCHOOL_ADMIN => [
                'name' => 'School Administrator',
                'description' => 'Full access within a school',
                'scope' => self::SCOPE_SCHOOL,
                'level' => 80
            ],
            self::ROLE_CAMPUS_ADMIN => [
                'name' => 'Campus Administrator',
                'description' => 'Full access within a campus',
                'scope' => self::SCOPE_CAMPUS,
                'level' => 70
            ],
            self::ROLE_TEACHER => [
                'name' => 'Teacher',
                'description' => 'Teaching staff with classroom access',
                'scope' => self::SCOPE_SCHOOL,
                'level' => 50
            ],
            self::ROLE_CLASS_TEACHER => [
                'name' => 'Class Teacher',
                'description' => 'Teacher with class management responsibilities',
                'scope' => self::SCOPE_SCHOOL,
                'level' => 55
            ],
            self::ROLE_SUBJECT_TEACHER => [
                'name' => 'Subject Teacher',
                'description' => 'Teacher with subject specialization',
                'scope' => self::SCOPE_SCHOOL,
                'level' => 50
            ],
            self::ROLE_STAFF => [
                'name' => 'Staff',
                'description' => 'General staff member',
                'scope' => self::SCOPE_SCHOOL,
                'level' => 30
            ],
            self::ROLE_STUDENT => [
                'name' => 'Student',
                'description' => 'Student with learning access',
                'scope' => self::SCOPE_SCHOOL,
                'level' => 10
            ],
            self::ROLE_PARENT => [
                'name' => 'Parent',
                'description' => 'Parent with student monitoring access',
                'scope' => self::SCOPE_SCHOOL,
                'level' => 20
            ]
        ];
    }

    /**
     * Get role by code
     */
    public function getRole(string $roleCode): ?array
    {
        $roles = $this->getSystemRoles();
        return $roles[$roleCode] ?? null;
    }

    /**
     * Check if user has a specific role
     */
    public function hasRole(int $userId, string $roleCode, ?int $tenantId = null): bool
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if (!$tenantId) {
            return false;
        }

        $cacheKey = "role_{$userId}_{$roleCode}_{$tenantId}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $count = $this->db->getValue("
            SELECT COUNT(*) 
            FROM platform_user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ? 
              AND r.role_code = ? 
              AND r.tenant_id = ?
              AND r.deleted_at IS NULL
              AND ur.deleted_at IS NULL
        ", [$userId, $roleCode, $tenantId]);

        $result = $count > 0;
        $this->cache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Get user roles
     */
    public function getUserRoles(int $userId, ?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if (!$tenantId) {
            return [];
        }

        $cacheKey = "user_roles_{$userId}_{$tenantId}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $roles = $this->db->fetchAll("
            SELECT 
                r.id as role_id,
                r.role_name,
                r.role_code,
                r.description,
                ur.scope_type,
                ur.scope_id,
                r.created_at
            FROM platform_user_roles ur
            JOIN roles r ON ur.role_id = r.id
            WHERE ur.user_id = ? 
              AND r.tenant_id = ?
              AND r.deleted_at IS NULL
              AND ur.deleted_at IS NULL
            ORDER BY r.role_code
        ", [$userId, $tenantId]);

        $this->cache[$cacheKey] = $roles;
        return $roles;
    }

    /**
     * Get user permissions
     */
    public function getUserPermissions(int $userId, ?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if (!$tenantId) {
            return [];
        }

        $cacheKey = "user_perms_{$userId}_{$tenantId}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $permissions = $this->db->fetchAll("
            SELECT DISTINCT p.permission_code, p.permission_name, p.module
            FROM platform_user_roles ur
            JOIN role_permissions rp ON ur.role_id = rp.role_id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE ur.user_id = ?
              AND rp.deleted_at IS NULL
              AND p.deleted_at IS NULL
            ORDER BY p.module, p.permission_code
        ", [$userId]);

        $this->cache[$cacheKey] = $permissions;
        return $permissions;
    }

    /**
     * Check if user has a specific permission
     */
    public function hasPermission(int $userId, string $permissionCode, ?int $tenantId = null): bool
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if (!$tenantId) {
            return false;
        }

        $cacheKey = "perm_{$userId}_{$permissionCode}_{$tenantId}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $count = $this->db->getValue("
            SELECT COUNT(*) 
            FROM platform_user_roles ur
            JOIN role_permissions rp ON ur.role_id = rp.role_id
            JOIN permissions p ON rp.permission_id = p.id
            WHERE ur.user_id = ?
              AND p.permission_code = ?
              AND rp.deleted_at IS NULL
              AND p.deleted_at IS NULL
        ", [$userId, $permissionCode]);

        $result = $count > 0;
        $this->cache[$cacheKey] = $result;
        return $result;
    }

    /**
     * Get user's scope (tenant, school, campus)
     */
    public function getUserScope(int $userId, ?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if (!$tenantId) {
            return ['type' => null, 'ids' => []];
        }

        $scope = [
            'tenant_id' => $tenantId,
            'school_ids' => [],
            'campus_ids' => []
        ];

        // Check if user has super admin role (global scope)
        if ($this->hasRole($userId, self::ROLE_SUPER_ADMIN)) {
            $scope['type'] = 'global';
            return $scope;
        }

        // Check tenant admin (tenant scope)
        if ($this->hasRole($userId, self::ROLE_TENANT_ADMIN, $tenantId)) {
            $scope['type'] = 'tenant';
            return $scope;
        }

        // Get school scope
        $schoolAccess = $this->db->fetchAll("
            SELECT school_id FROM user_school_access
            WHERE user_id = ? AND school_id IS NOT NULL
        ", [$userId]);

        foreach ($schoolAccess as $access) {
            $scope['school_ids'][] = $access['school_id'];
        }

        // Get campus scope
        $campusAccess = $this->db->fetchAll("
            SELECT campus_id FROM user_campus_access
            WHERE user_id = ? AND campus_id IS NOT NULL
        ", [$userId]);

        foreach ($campusAccess as $access) {
            $scope['campus_ids'][] = $access['campus_id'];
        }

        // Determine scope type
        if (!empty($scope['school_ids']) && !empty($scope['campus_ids'])) {
            $scope['type'] = 'mixed';
        } elseif (!empty($scope['school_ids'])) {
            $scope['type'] = 'school';
        } elseif (!empty($scope['campus_ids'])) {
            $scope['type'] = 'campus';
        } else {
            $scope['type'] = 'none';
        }

        return $scope;
    }

    /**
     * Verify user has access to a specific school
     */
    public function hasSchoolAccess(int $userId, int $schoolId, ?int $tenantId = null): bool
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if (!$tenantId) {
            return false;
        }

        // Super admin has access to everything
        if ($this->hasRole($userId, self::ROLE_SUPER_ADMIN)) {
            return true;
        }

        // Tenant admin has access to all schools in tenant
        if ($this->hasRole($userId, self::ROLE_TENANT_ADMIN, $tenantId)) {
            return true;
        }

        // Check direct school access
        $count = $this->db->getValue("
            SELECT COUNT(*) FROM user_school_access
            WHERE user_id = ? AND school_id = ?
        ", [$userId, $schoolId]);

        if ($count > 0) {
            return true;
        }

        // Check if user has a role with school scope
        $count = $this->db->getValue("
            SELECT COUNT(*) 
            FROM platform_user_roles ur
            WHERE ur.user_id = ? 
              AND ur.scope_type = 'school' 
              AND ur.scope_id = ?
              AND ur.deleted_at IS NULL
        ", [$userId, $schoolId]);

        return $count > 0;
    }

    /**
     * Verify user has access to a specific campus
     */
    public function hasCampusAccess(int $userId, int $campusId, ?int $tenantId = null): bool
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if (!$tenantId) {
            return false;
        }

        // Super admin has access to everything
        if ($this->hasRole($userId, self::ROLE_SUPER_ADMIN)) {
            return true;
        }

        // Tenant admin has access to all campuses in tenant
        if ($this->hasRole($userId, self::ROLE_TENANT_ADMIN, $tenantId)) {
            return true;
        }

        // Check direct campus access
        $count = $this->db->getValue("
            SELECT COUNT(*) FROM user_campus_access
            WHERE user_id = ? AND campus_id = ?
        ", [$userId, $campusId]);

        if ($count > 0) {
            return true;
        }

        // Check if user has a role with campus scope
        $count = $this->db->getValue("
            SELECT COUNT(*) 
            FROM platform_user_roles ur
            WHERE ur.user_id = ? 
              AND ur.scope_type = 'campus' 
              AND ur.scope_id = ?
              AND ur.deleted_at IS NULL
        ", [$userId, $campusId]);

        return $count > 0;
    }

    /**
     * Verify user has access to a specific entity
     */
    public function hasEntityAccess(int $userId, string $entityType, int $entityId, ?int $tenantId = null): bool
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        if (!$tenantId) {
            return false;
        }

        switch ($entityType) {
            case 'tenant':
                return $this->hasTenantAccess($userId, $entityId);
            case 'school':
                return $this->hasSchoolAccess($userId, $entityId, $tenantId);
            case 'campus':
                return $this->hasCampusAccess($userId, $entityId, $tenantId);
            default:
                return false;
        }
    }

    /**
     * Verify user has access to a specific tenant
     */
    public function hasTenantAccess(int $userId, int $tenantId): bool
    {
        // Super admin has access to all tenants
        if ($this->hasRole($userId, self::ROLE_SUPER_ADMIN)) {
            return true;
        }

        // Check if user belongs to this tenant
        $count = $this->db->getValue("
            SELECT COUNT(*) FROM platform_users
            WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL
        ", [$userId, $tenantId]);

        return $count > 0;
    }

    /**
     * Get user's admin level
     */
    public function getAdminLevel(int $userId, ?int $tenantId = null): string
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();

        if ($this->hasRole($userId, self::ROLE_SUPER_ADMIN)) {
            return 'super_admin';
        }

        if ($this->hasRole($userId, self::ROLE_TENANT_ADMIN, $tenantId)) {
            return 'tenant_admin';
        }

        if ($this->hasRole($userId, self::ROLE_SCHOOL_ADMIN, $tenantId)) {
            return 'school_admin';
        }

        if ($this->hasRole($userId, self::ROLE_CAMPUS_ADMIN, $tenantId)) {
            return 'campus_admin';
        }

        return 'none';
    }

    /**
     * Check if user is an admin
     */
    public function isAdmin(int $userId, ?int $tenantId = null): bool
    {
        return $this->getAdminLevel($userId, $tenantId) !== 'none';
    }

    /**
     * Get all permissions grouped by module
     */
    public function getPermissionsByModule(): array
    {
        $permissions = $this->db->fetchAll("
            SELECT permission_code, permission_name, module, description
            FROM permissions
            WHERE deleted_at IS NULL
            ORDER BY module, permission_code
        ");

        $grouped = [];
        foreach ($permissions as $perm) {
            $module = $perm['module'] ?? 'general';
            if (!isset($grouped[$module])) {
                $grouped[$module] = [];
            }
            $grouped[$module][] = $perm;
        }

        return $grouped;
    }

    /**
     * Assign role to user
     */
    public function assignRole(int $userId, int $roleId, string $scopeType = null, int $scopeId = null): bool
    {
        try {
            $this->db->execute("
                INSERT INTO platform_user_roles (user_id, role_id, scope_type, scope_id, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ", [$userId, $roleId, $scopeType, $scopeId]);

            // Clear cache
            $this->clearUserCache($userId);

            return true;
        } catch (Exception $e) {
            error_log('Error assigning role: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove role from user
     */
    public function removeRole(int $userId, int $roleId): bool
    {
        try {
            $this->db->execute("
                UPDATE platform_user_roles 
                SET deleted_at = NOW() 
                WHERE user_id = ? AND role_id = ?
            ", [$userId, $roleId]);

            // Clear cache
            $this->clearUserCache($userId);

            return true;
        } catch (Exception $e) {
            error_log('Error removing role: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear user cache
     */
    private function clearUserCache(int $userId): void
    {
        foreach ($this->cache as $key => $value) {
            if (strpos($key, "_{$userId}_") !== false) {
                unset($this->cache[$key]);
            }
        }
    }

    /**
     * Check if user can perform action on entity
     */
    public function canAccess(int $userId, string $action, string $entityType, int $entityId): bool
    {
        // If user has permission, check scope
        if (!$this->hasPermission($userId, $action)) {
            return false;
        }

        // Check entity access
        return $this->hasEntityAccess($userId, $entityType, $entityId);
    }

    /**
     * Get user's effective permissions with scope
     */
    public function getUserEffectivePermissions(int $userId, ?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        $permissions = $this->getUserPermissions($userId, $tenantId);
        $scope = $this->getUserScope($userId, $tenantId);

        return [
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'permissions' => $permissions,
            'scope' => $scope,
            'is_admin' => $this->isAdmin($userId, $tenantId)
        ];
    }

    /**
     * Validate that a user can access a resource
     * Throws exception if not authorized
     */
    public function validateAccess(int $userId, string $action, string $entityType, int $entityId): void
    {
        if (!$this->canAccess($userId, $action, $entityType, $entityId)) {
            throw new Exception('Unauthorized: ' . $action . ' on ' . $entityType . ' ' . $entityId);
        }
    }

    /**
     * Get dashboard items based on user roles and permissions
     */
    public function getDashboardItems(int $userId, ?int $tenantId = null): array
    {
        $tenantId = $tenantId ?? $this->tenantContext->getTenantId();
        $items = [];

        $permissions = $this->getUserPermissions($userId, $tenantId);
        $permissionCodes = array_column($permissions, 'permission_code');

        // Define dashboard items with required permissions
        $dashboardItems = [
            'dashboard' => [
                'label' => 'Dashboard',
                'icon' => 'fa-tachometer-alt',
                'route' => '/tenant/dashboard.php',
                'permissions' => ['view_dashboard']
            ],
            'students' => [
                'label' => 'Students',
                'icon' => 'fa-user-graduate',
                'route' => '/tenant/students/index.php',
                'permissions' => ['view_students', 'manage_students']
            ],
            'staff' => [
                'label' => 'Staff',
                'icon' => 'fa-user-tie',
                'route' => '/tenant/staff/index.php',
                'permissions' => ['view_staff', 'manage_staff']
            ],
            'classes' => [
                'label' => 'Classes',
                'icon' => 'fa-chalkboard',
                'route' => '/tenant/classes/index.php',
                'permissions' => ['view_classes', 'manage_classes']
            ],
            'subjects' => [
                'label' => 'Subjects',
                'icon' => 'fa-book',
                'route' => '/tenant/subjects/index.php',
                'permissions' => ['view_subjects', 'manage_subjects']
            ],
            'attendance' => [
                'label' => 'Attendance',
                'icon' => 'fa-clipboard-list',
                'route' => '/tenant/attendance/index.php',
                'permissions' => ['view_attendance', 'manage_attendance']
            ],
            'assessments' => [
                'label' => 'Assessments',
                'icon' => 'fa-pencil-alt',
                'route' => '/tenant/assessments/index.php',
                'permissions' => ['view_assessments', 'manage_assessments']
            ],
            'timetable' => [
                'label' => 'Timetable',
                'icon' => 'fa-calendar-alt',
                'route' => '/tenant/timetable/index.php',
                'permissions' => ['view_timetable', 'manage_timetable']
            ],
            'schools' => [
                'label' => 'Schools',
                'icon' => 'fa-school',
                'route' => '/tenant/admin/schools/index.php',
                'permissions' => ['manage_schools']
            ],
            'campuses' => [
                'label' => 'Campuses',
                'icon' => 'fa-map-marker-alt',
                'route' => '/tenant/admin/campuses/index.php',
                'permissions' => ['manage_campuses']
            ],
            'users' => [
                'label' => 'Users',
                'icon' => 'fa-users',
                'route' => '/tenant/admin/users/index.php',
                'permissions' => ['manage_users']
            ],
            'settings' => [
                'label' => 'Settings',
                'icon' => 'fa-cog',
                'route' => '/tenant/admin/settings/index.php',
                'permissions' => ['manage_settings']
            ],
            'audit' => [
                'label' => 'Audit Log',
                'icon' => 'fa-history',
                'route' => '/tenant/admin/audit/index.php',
                'permissions' => ['view_audit']
            ],
            'reports' => [
                'label' => 'Reports',
                'icon' => 'fa-chart-bar',
                'route' => '/tenant/reports/index.php',
                'permissions' => ['view_reports']
            ]
        ];

        foreach ($dashboardItems as $key => $item) {
            // Check if user has any of the required permissions
            foreach ($item['permissions'] as $perm) {
                if (in_array($perm, $permissionCodes)) {
                    $items[$key] = $item;
                    break;
                }
            }
        }

        return $items;
    }
}
