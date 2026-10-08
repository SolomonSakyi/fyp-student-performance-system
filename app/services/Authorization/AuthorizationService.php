<?php

/**
 * AuthorizationService.php
 * Authorization service for API
 * 
 * @package EduTrack
 * @subpackage Services\Authorization
 * @version 1.3
 *
 * v1.3 changes (2026-10-05) [ITEM-8]:
 *   - Removed the dead SCHOOL_ADMIN branch from canManageSchool().
 *     The branch called $this->context->hasRole('SCHOOL_ADMIN'),
 *     which returns false for every caller because the live roles
 *     table has no SCHOOL_ADMIN row. The branch never executed its
 *     return statement. Its removal changes nothing at runtime; it
 *     removes dead code that a future reader could mistake for a
 *     meaningful restriction.
 *
 *   - Removed the dead CAMPUS_ADMIN branch from canManageCampus().
 *     Same reasoning: hasRole('CAMPUS_ADMIN') always returns false
 *     because the live roles table has no CAMPUS_ADMIN row.
 *
 *   - No replacement role was introduced. The underlying model
 *     still has no per-school or per-campus admin restriction. If
 *     the platform later introduces such a role, the branches can
 *     be re-added with the correct role name and the correct data.
 *
 *   - Not changed in this version: the two methods rewritten in
 *     v1.2 (canAccessStudent, canManageStudent) remain as they are.
 *
 * v1.2 changes (2026-10-05) [ITEM-7]:
 *   - Rewrote canAccessStudent(). The old body JOINed through
 *     student_enrollments, a table that does not exist on the live
 *     database. The method now reads the student's tenant_id,
 *     school_id and campus_id directly from the students table,
 *     all three of which exist on the live schema. The old
 *     "se.status = 'enrolled'" filter was also dropped: the live
 *     enrollment_status enum is ('Active','Inactive','Transferred',
 *     'Graduated','Suspended') and has no 'enrolled' value, and
 *     authorization is a separate concern from student lifecycle.
 *     Soft-deleted students remain excluded by deleted_at IS NULL.
 *
 *   - Rewrote canManageStudent(). Same table defect: the old body
 *     JOINed through student_enrollments. The method now reads the
 *     student's tenant_id and school_id directly from the students
 *     table, and delegates to canManageSchool() as before.
 *
 * v1.1 changes (2026-09-30) [1B] [4A]:
 *   - Added hasPermission(). SettingsController::checkPermission()
 *     called this method but the class did not have it, which
 *     produced a fatal Error on every settings request. The method
 *     now delegates to TenantContext::hasPermission(), which under
 *     [1B] returns true for the three live roles (Admin, Super
 *     Admin, Tenant Admin) and for super admins.
 *
 *   - Rewrote canAccessSchool(). The old body queried
 *     platform_users.school_id and platform_user_roles.school_id.
 *     Neither column exists on the live database. Per [4A], the
 *     method now checks the school's tenant and delegates to
 *     canAccessTenant(). No column-based user scoping.
 *
 *   - Rewrote canAccessCampus(). The old body queried
 *     platform_users.campus_id and platform_user_roles.campus_id.
 *     Neither column exists on the live database. Per [4A], the
 *     method now checks the campus's school and delegates to
 *     canAccessSchool().
 */

require_once dirname(__DIR__, 2) . '/helpers/DatabaseHelper.php';
require_once dirname(__DIR__, 2) . '/services/Tenant/TenantContext.php';

class AuthorizationService
{
    private $context;
    private $db;

    public function __construct()
    {
        $this->context = TenantContext::getInstance();
        $this->db = DatabaseHelper::getInstance();
    }

    // =============================================
    // PERMISSION
    // =============================================

    /**
     * [1B] Check if the user has a permission.
     *
     * The permission-string model is dropped. The decision is
     * delegated to TenantContext::hasPermission(), which returns
     * true for the three live roles (Admin, Super Admin, Tenant
     * Admin) and for super admins, and false otherwise.
     *
     * @param int    $userId         The user id. If falsy, returns false.
     * @param string $permissionCode The permission string. Ignored under [1B].
     * @return bool
     */
    public function hasPermission(int $userId, string $permissionCode): bool
    {
        if (!$userId) {
            return false;
        }

        return $this->context->hasPermission($permissionCode);
    }

    // =============================================
    // TENANT AUTHORIZATION
    // =============================================

    public function canAccessTenant($tenantId)
    {
        // Super admin can access all tenants
        if ($this->context->isSuperAdmin()) {
            return true;
        }

        // Check if user belongs to this tenant
        $userId = $this->context->getUserId();
        if (!$userId) {
            return false;
        }

        $result = $this->db->fetchOne(
            "SELECT 1 FROM platform_users WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
            [$userId, $tenantId]
        );

        return (bool) $result;
    }

    public function canManageTenant($tenantId)
    {
        // Super admin can manage all tenants
        if ($this->context->isSuperAdmin()) {
            return true;
        }

        // Tenant admin can manage their own tenant
        if ($this->context->hasRole('TENANT_ADMIN')) {
            $contextTenantId = $this->context->getTenantId();
            return $contextTenantId === $tenantId;
        }

        return $this->canAccessTenant($tenantId);
    }

    // =============================================
    // SCHOOL AUTHORIZATION
    // =============================================

    /**
     * [4A] Check if the user can access a school.
     *
     * The old body queried platform_users.school_id and
     * platform_user_roles.school_id. Neither column exists. The
     * method now checks the school's tenant and delegates to
     * canAccessTenant(). No column-based user scoping.
     */
    public function canAccessSchool($schoolId)
    {
        // Super admin can access all schools
        if ($this->context->isSuperAdmin()) {
            return true;
        }

        // Get school's tenant
        $school = $this->db->fetchOne(
            "SELECT tenant_id FROM schools WHERE id = ? AND deleted_at IS NULL",
            [$schoolId]
        );

        if (!$school) {
            return false;
        }

        // Delegate to tenant access. The user must belong to the
        // school's tenant.
        return $this->canAccessTenant($school['tenant_id']);
    }

    /**
     * [ITEM-8 v1.3] Check if the user can manage a school.
     *
     * The v1.1 body carried a dead branch:
     *   if ($this->context->hasRole('SCHOOL_ADMIN')) {
     *       return $this->context->getSchoolId() === $schoolId;
     *   }
     *
     * hasRole('SCHOOL_ADMIN') returns false for every caller because
     * the live roles table has no SCHOOL_ADMIN row, so the branch
     * never executed. It was removed in v1.3. The method now falls
     * through to canAccessSchool() for any user who is not a super
     * admin and not a tenant admin — which is the behaviour it
     * already had at runtime.
     *
     * The underlying model still has no per-school admin
     * restriction. That is a design fact, not a defect introduced
     * by this change.
     */
    public function canManageSchool($schoolId)
    {
        // Super admin can manage all schools
        if ($this->context->isSuperAdmin()) {
            return true;
        }

        // Tenant admin can manage schools in their tenant
        if ($this->context->hasRole('TENANT_ADMIN')) {
            $school = $this->db->fetchOne(
                "SELECT tenant_id FROM schools WHERE id = ? AND deleted_at IS NULL",
                [$schoolId]
            );

            if (!$school) {
                return false;
            }

            return $this->context->getTenantId() === $school['tenant_id'];
        }

        return $this->canAccessSchool($schoolId);
    }

    // =============================================
    // CAMPUS AUTHORIZATION
    // =============================================

    /**
     * [4A] Check if the user can access a campus.
     *
     * The old body queried platform_users.campus_id and
     * platform_user_roles.campus_id. Neither column exists. The
     * method now checks the campus's school and delegates to
     * canAccessSchool().
     */
    public function canAccessCampus($campusId)
    {
        // Super admin can access all campuses
        if ($this->context->isSuperAdmin()) {
            return true;
        }

        // Get campus's school
        $campus = $this->db->fetchOne(
            "SELECT c.id, c.school_id, s.tenant_id
             FROM campuses c
             JOIN schools s ON c.school_id = s.id
             WHERE c.id = ? AND c.deleted_at IS NULL",
            [$campusId]
        );

        if (!$campus) {
            return false;
        }

        // Delegate to school access. The user must belong to the
        // school's tenant.
        return $this->canAccessSchool($campus['school_id']);
    }

    /**
     * [ITEM-8 v1.3] Check if the user can manage a campus.
     *
     * The v1.1 body carried a dead branch:
     *   if ($this->context->hasRole('CAMPUS_ADMIN')) {
     *       return $this->context->getCampusId() === $campusId;
     *   }
     *
     * hasRole('CAMPUS_ADMIN') returns false for every caller because
     * the live roles table has no CAMPUS_ADMIN row, so the branch
     * never executed. It was removed in v1.3. The method now
     * returns the result of canManageSchool() for the campus's
     * school — which is the behaviour it already had at runtime,
     * given the dead branch fell through to `return false` and the
     * preceding `canManageSchool()` check.
     *
     * The underlying model still has no per-campus admin
     * restriction. That is a design fact, not a defect introduced
     * by this change.
     */
    public function canManageCampus($campusId)
    {
        // Super admin can manage all campuses
        if ($this->context->isSuperAdmin()) {
            return true;
        }

        // Get campus's school and tenant
        $campus = $this->db->fetchOne(
            "SELECT c.id, c.school_id, s.tenant_id
             FROM campuses c
             JOIN schools s ON c.school_id = s.id
             WHERE c.id = ? AND c.deleted_at IS NULL",
            [$campusId]
        );

        if (!$campus) {
            return false;
        }

        // Check school management permission
        if ($this->canManageSchool($campus['school_id'])) {
            return true;
        }

        return false;
    }

    // =============================================
    // STUDENT AUTHORIZATION
    // =============================================

    /**
     * [ITEM-7 v1.2] Check if the user can access a student.
     *
     * The old body JOINed through student_enrollments, a table that
     * does not exist. The method now reads the student's tenant,
     * school and campus directly from the students table — all
     * three columns exist on the live schema. Soft-deleted students
     * are excluded by deleted_at IS NULL.
     *
     * The old "se.status = 'enrolled'" filter has been dropped. The
     * live enrollment_status enum is ('Active','Inactive',
     * 'Transferred','Graduated','Suspended') and has no 'enrolled'
     * value; and authorization is a separate concern from student
     * lifecycle — a caller that wants to scope visibility by status
     * should do so at its own layer.
     *
     * @param int $studentId
     * @return bool
     */
    public function canAccessStudent($studentId)
    {
        // Super admin can access all students
        if ($this->context->isSuperAdmin()) {
            return true;
        }

        // Get student's tenant, school and campus directly.
        $student = $this->db->fetchOne(
            "SELECT id, tenant_id, school_id, campus_id
             FROM students
             WHERE id = ? AND deleted_at IS NULL
             LIMIT 1",
            [$studentId]
        );

        if (!$student) {
            return false;
        }

        // Check tenant access
        if (!$this->canAccessTenant($student['tenant_id'])) {
            return false;
        }

        // Check school access
        if (!$this->canAccessSchool($student['school_id'])) {
            return false;
        }

        // If campus context is set, check campus access
        if (!empty($student['campus_id']) && $this->context->hasCampusContext()) {
            if (!$this->canAccessCampus($student['campus_id'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * [ITEM-7 v1.2] Check if the user can manage a student.
     *
     * The old body JOINed through student_enrollments, a table that
     * does not exist. The method now reads the student's school_id
     * directly from the students table and delegates to
     * canManageSchool(). Soft-deleted students are excluded by
     * deleted_at IS NULL.
     *
     * @param int $studentId
     * @return bool
     */
    public function canManageStudent($studentId)
    {
        // Super admin can manage all students
        if ($this->context->isSuperAdmin()) {
            return true;
        }

        // Get student's tenant and school directly.
        $student = $this->db->fetchOne(
            "SELECT id, tenant_id, school_id
             FROM students
             WHERE id = ? AND deleted_at IS NULL
             LIMIT 1",
            [$studentId]
        );

        if (!$student) {
            return false;
        }

        // Check if user can manage the school
        return $this->canManageSchool($student['school_id']);
    }
}
