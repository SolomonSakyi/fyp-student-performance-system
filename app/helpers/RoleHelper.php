<?php

/**
 * Role Helper - Manage user roles and permissions
 *
 * @package EduTrack
 * @subpackage Helpers
 * @filepath app/helpers/RoleHelper.php
 * @version 2.0
 */

class RoleHelper
{
    // Role constants
    const ROLE_SUPER_ADMIN = 'super_admin';
    const ROLE_TENANT_ADMIN = 'tenant_admin';
    const ROLE_SCHOOL_ADMIN = 'school_admin';
    const ROLE_STAFF = 'staff';
    const ROLE_TEACHER = 'teacher';
    const ROLE_STUDENT = 'student';
    const ROLE_PARENT = 'parent';

    /**
     * Check if current user is Super Admin
     */
    public static function isSuperAdmin()
    {
        return isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] === true;
    }

    /**
     * Check if current user is Tenant Admin
     */
    public static function isTenantAdmin()
    {
        return isset($_SESSION['role']) && $_SESSION['role'] === self::ROLE_TENANT_ADMIN;
    }

    /**
     * Check if current user is School Admin
     */
    public static function isSchoolAdmin()
    {
        return isset($_SESSION['role']) && $_SESSION['role'] === self::ROLE_SCHOOL_ADMIN;
    }

    /**
     * Check if user has permission to create/edit/delete staff
     */
    public static function canManageStaff()
    {
        return self::isTenantAdmin() || self::isSchoolAdmin();
    }

    /**
     * Check if user has permission to create/edit/delete students
     */
    public static function canManageStudents()
    {
        return self::isTenantAdmin() || self::isSchoolAdmin();
    }

    /**
     * Check if user has permission to create/edit/delete schools
     */
    public static function canManageSchools()
    {
        return self::isSuperAdmin() || self::isTenantAdmin();
    }

    /**
     * Check if user has permission to create/edit/delete tenants
     */
    public static function canManageTenants()
    {
        return self::isSuperAdmin();
    }

    /**
     * Check if user has permission to view audit logs
     */
    public static function canViewAuditLogs()
    {
        return self::isSuperAdmin() || self::isTenantAdmin();
    }

    /**
     * Get user's role display name
     */
    public static function getRoleDisplayName()
    {
        if (self::isSuperAdmin()) {
            return 'Super Administrator';
        }
        return $_SESSION['role_display'] ?? 'User';
    }

    /**
     * Get user's permissions array
     */
    public static function getPermissions()
    {
        return [
            'can_manage_tenants' => self::canManageTenants(),
            'can_manage_schools' => self::canManageSchools(),
            'can_manage_staff' => self::canManageStaff(),
            'can_manage_students' => self::canManageStudents(),
            'can_view_audit_logs' => self::canViewAuditLogs(),
            'is_read_only_staff' => self::isSuperAdmin(), // Super Admin sees staff as read-only
            'is_read_only_students' => self::isSuperAdmin(), // Super Admin sees students as read-only
        ];
    }
}
