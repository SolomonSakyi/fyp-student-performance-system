<?php
/**
 * Tenant Context Helper
 * Provides easy access to tenant context in controllers and views
 *
 * @package EduTrack
 * @subpackage Helpers
 * @version 1.0
 * @filepath app/helpers/TenantContextHelper.php
 */

if (!function_exists('tenant')) {
    /**
     * Get tenant context instance
     */
    function tenant(): TenantContext
    {
        return TenantContext::getInstance();
    }
}

if (!function_exists('tenantId')) {
    /**
     * Get current tenant ID
     */
    function tenantId(): ?int
    {
        return tenant()->getTenantId();
    }
}

if (!function_exists('tenantName')) {
    /**
     * Get current tenant name
     */
    function tenantName(): ?string
    {
        return tenant()->getTenantName();
    }
}

if (!function_exists('schoolId')) {
    /**
     * Get current school ID
     */
    function schoolId(): ?int
    {
        return tenant()->getSchoolId();
    }
}

if (!function_exists('schoolName')) {
    /**
     * Get current school name
     */
    function schoolName(): ?string
    {
        return tenant()->getSchoolName();
    }
}

if (!function_exists('campusId')) {
    /**
     * Get current campus ID
     */
    function campusId(): ?int
    {
        return tenant()->getCampusId();
    }
}

if (!function_exists('campusName')) {
    /**
     * Get current campus name
     */
    function campusName(): ?string
    {
        return tenant()->getCampusName();
    }
}

if (!function_exists('domainName')) {
    /**
     * Get current domain name
     */
    function domainName(): ?string
    {
        return tenant()->getDomainName();
    }
}

if (!function_exists('loginAudience')) {
    /**
     * Get current login audience
     */
    function loginAudience(): ?string
    {
        return tenant()->getLoginAudience();
    }
}

if (!function_exists('hasTenantContext')) {
    /**
     * Check if tenant context is set
     */
    function hasTenantContext(): bool
    {
        return tenant()->hasTenantContext();
    }
}

if (!function_exists('isSuperAdmin')) {
    /**
     * Check if current user is super admin
     */
    function isSuperAdmin(): bool
    {
        return tenant()->isSuperAdmin();
    }
}

if (!function_exists('hasRole')) {
    /**
     * Check if current user has a role
     */
    function hasRole(string $roleCode): bool
    {
        return tenant()->hasRole($roleCode);
    }
}

if (!function_exists('hasPermission')) {
    /**
     * Check if current user has a permission
     */
    function hasPermission(string $permissionCode): bool
    {
        return tenant()->hasPermission($permissionCode);
    }
}