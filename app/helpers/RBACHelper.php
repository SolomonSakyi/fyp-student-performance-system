<?php

/**
 * RBAC Helper
 * Provides easy access to RBAC functions
 *
 * @package EduTrack
 * @subpackage Helpers
 * @version 1.0
 * @filepath app/helpers/RBACHelper.php
 */

if (!function_exists('rbac')) {
    /**
     * Get RBAC service instance
     */
    function rbac(): RBACService
    {
        static $instance = null;
        if ($instance === null) {
            require_once __DIR__ . '/../services/Auth/RBACService.php';
            $instance = new RBACService();
        }
        return $instance;
    }
}

if (!function_exists('hasRole')) {
    /**
     * Check if current user has a role
     */
    function hasRole(string $roleCode): bool
    {
        $userId = tenant()->getUserId();
        if (!$userId) {
            return false;
        }
        return rbac()->hasRole($userId, $roleCode);
    }
}

if (!function_exists('hasPermission')) {
    /**
     * Check if current user has a permission
     */
    function hasPermission(string $permissionCode): bool
    {
        $userId = tenant()->getUserId();
        if (!$userId) {
            return false;
        }
        return rbac()->hasPermission($userId, $permissionCode);
    }
}

if (!function_exists('isAdmin')) {
    /**
     * Check if current user is an admin
     */
    function isAdmin(): bool
    {
        $userId = tenant()->getUserId();
        if (!$userId) {
            return false;
        }
        return rbac()->isAdmin($userId);
    }
}

if (!function_exists('isSuperAdmin')) {
    /**
     * Check if current user is a super admin
     */
    function isSuperAdmin(): bool
    {
        return hasRole(RBACService::ROLE_SUPER_ADMIN);
    }
}

if (!function_exists('isTenantAdmin')) {
    /**
     * Check if current user is a tenant admin
     */
    function isTenantAdmin(): bool
    {
        return hasRole(RBACService::ROLE_TENANT_ADMIN);
    }
}

if (!function_exists('isSchoolAdmin')) {
    /**
     * Check if current user is a school admin
     */
    function isSchoolAdmin(): bool
    {
        return hasRole(RBACService::ROLE_SCHOOL_ADMIN);
    }
}

if (!function_exists('hasSchoolAccess')) {
    /**
     * Check if current user has access to a school
     */
    function hasSchoolAccess(int $schoolId): bool
    {
        $userId = tenant()->getUserId();
        if (!$userId) {
            return false;
        }
        return rbac()->hasSchoolAccess($userId, $schoolId);
    }
}

if (!function_exists('hasCampusAccess')) {
    /**
     * Check if current user has access to a campus
     */
    function hasCampusAccess(int $campusId): bool
    {
        $userId = tenant()->getUserId();
        if (!$userId) {
            return false;
        }
        return rbac()->hasCampusAccess($userId, $campusId);
    }
}

if (!function_exists('getUserRoles')) {
    /**
     * Get current user's roles
     */
    function getUserRoles(): array
    {
        $userId = tenant()->getUserId();
        if (!$userId) {
            return [];
        }
        return rbac()->getUserRoles($userId);
    }
}

if (!function_exists('getUserPermissions')) {
    /**
     * Get current user's permissions
     */
    function getUserPermissions(): array
    {
        $userId = tenant()->getUserId();
        if (!$userId) {
            return [];
        }
        return rbac()->getUserPermissions($userId);
    }
}

if (!function_exists('canAccess')) {
    /**
     * Check if current user can access an entity
     */
    function canAccess(string $action, string $entityType, int $entityId): bool
    {
        $userId = tenant()->getUserId();
        if (!$userId) {
            return false;
        }
        return rbac()->canAccess($userId, $action, $entityType, $entityId);
    }
}

if (!function_exists('requireRole')) {
    /**
     * Require a role for the current page
     */
    function requireRole($roles): void
    {
        $middleware = new RBACMiddleware();
        $middleware->requireRole($roles);
    }
}

if (!function_exists('requirePermission')) {
    /**
     * Require a permission for the current page
     */
    function requirePermission($permissions): void
    {
        $middleware = new RBACMiddleware();
        $middleware->requirePermission($permissions);
    }
}

if (!function_exists('requireAdmin')) {
    /**
     * Require admin access for the current page
     */
    function requireAdmin(): void
    {
        $middleware = new RBACMiddleware();
        $middleware->requireAdmin();
    }
}

if (!function_exists('requireTenantAdmin')) {
    /**
     * Require tenant admin access for the current page
     */
    function requireTenantAdmin(): void
    {
        $middleware = new RBACMiddleware();
        $middleware->requireTenantAdmin();
    }
}

if (!function_exists('getDashboardItems')) {
    /**
     * Get dashboard items for current user
     */
    function getDashboardItems(): array
    {
        $userId = tenant()->getUserId();
        if (!$userId) {
            return [];
        }
        return rbac()->getDashboardItems($userId);
    }
}
