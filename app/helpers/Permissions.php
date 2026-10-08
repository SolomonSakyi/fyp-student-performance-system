<?php

/**
 * Permissions — capability matrix for EduTrack
 *
 * @package EduTrack
 * @subpackage Helpers
 * @version 1.2
 * @filepath app/helpers/Permissions.php
 *
 * Purpose:
 *   Single source of truth for the capability matrix that maps
 *   the live roles to the actions each role may perform.
 *
 * Source of truth for roles:
 *   $_SESSION['roles']       array of role ids (ints)
 *   $_SESSION['role_names']  array of role names (strings)
 *   $_SESSION['user_type']   one of 'platform_admin','tenant_admin',
 *                            'staff','guardian'
 *   All three are written by public/platform/tenant/login.php.
 *
 * Role ids, as of 2026-10-04:
 *   1  Admin
 *   2  Super Admin
 *   3  Tenant Admin
 *   4  Teacher
 *   5  Head Teacher
 *   6  Accountant
 *
 * There is NO Guardian role in the roles table. A guardian is
 * identified by platform_users.user_type = 'guardian' and by a
 * platform_users.guardian_id FK to the guardians table, not by
 * a role assignment. is_guardian() reads
 * $_SESSION['user_type'], not $_SESSION['roles'].
 *
 * v1.1 changes (2026-10-04):
 *   - Added is_guardian(), is_staff(), and
 *     guardian_can_view_student($studentId).
 *   - guardian_can_view_student is the first function in this
 *     file that reads the database.
 *
 * v1.2 changes (2026-10-04):
 *   - Renamed is_super_admin() -> perm_is_super_admin() and
 *     is_tenant_admin() -> perm_is_tenant_admin(). The previous
 *     names collided with functions declared in
 *     app/helpers/Security.php, which is loaded by
 *     app/bootstrap.php. Including both files was a PHP fatal
 *     error: "Cannot redeclare is_super_admin()".
 *   - Every internal caller in this file was updated to the new
 *     names.
 *   - The Security.php versions of is_super_admin() and
 *     is_tenant_admin() are unchanged and remain in force for
 *     callers that use the session-boolean source of truth.
 *     This file's perm_* versions use the roles array, which is
 *     the source of truth after Phase 3.
 *
 * DEPENDENCY:
 *   perm_is_super_admin() and perm_is_tenant_admin() read
 *   $_SESSION['roles']. is_guardian() and is_staff() read
 *   $_SESSION['user_type']. login.php v2.4 writes all of these.
 *
 * USAGE:
 *   require_once $projectRoot . '/app/helpers/Permissions.php';
 *   if (!can_publish_results()) { ... }
 *   if (!guardian_can_view_student($studentId)) { ... }
 */

if (!function_exists('edu_role_ids')) {
    /**
     * Return the current user's role ids as an array of ints.
     * Reads from the session. Returns [] when the session has no
     * roles key.
     *
     * @return int[]
     */
    function edu_role_ids(): array
    {
        $raw = $_SESSION['roles'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id) {
            $out[] = (int)$id;
        }
        return $out;
    }

    /**
     * Return the current user's role names as an array of strings.
     * Reads from the session. Returns [] when the session has no
     * role_names key.
     *
     * @return string[]
     */
    function edu_role_names(): array
    {
        $raw = $_SESSION['role_names'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $name) {
            $out[] = (string)$name;
        }
        return $out;
    }

    /**
     * True when the current user holds the given role id.
     *
     * @param int $roleId
     * @return bool
     */
    function edu_has_role_id(int $roleId): bool
    {
        return in_array($roleId, edu_role_ids(), true);
    }

    /**
     * True when the current user holds the given role name,
     * compared case-insensitively with normalisation of
     * underscores to spaces.
     *
     * @param string $roleName
     * @return bool
     */
    function edu_has_role_name(string $roleName): bool
    {
        $needle = strtolower(str_replace('_', ' ', $roleName));
        foreach (edu_role_names() as $name) {
            $haystack = strtolower(str_replace('_', ' ', $name));
            if ($needle === $haystack) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the current user's user_type from the session, or
     * null when the session does not carry one.
     *
     * @return string|null
     */
    function edu_user_type(): ?string
    {
        $t = $_SESSION['user_type'] ?? null;
        return $t !== null ? (string)$t : null;
    }

    // ============================================================
    // ROLE CHECKS
    // ============================================================
    // The perm_* prefix exists to avoid redeclaring functions that
    // Security.php already declares under the same names. Do not
    // rename these back without also renaming Security.php's.

    function perm_is_super_admin(): bool
    {
        return edu_has_role_id(2);
    }

    function perm_is_tenant_admin(): bool
    {
        return edu_has_role_id(3);
    }

    function is_head_teacher(): bool
    {
        return edu_has_role_id(5);
    }

    function is_teacher(): bool
    {
        return edu_has_role_id(4);
    }

    function is_accountant(): bool
    {
        return edu_has_role_id(6);
    }

    // ============================================================
    // POPULATION CHECKS (v1.1)
    // ============================================================

    /**
     * True when the current user is a guardian.
     *
     * A guardian has no role assignment. The identification is:
     *   $_SESSION['user_type'] === 'guardian'
     *
     * @return bool
     */
    function is_guardian(): bool
    {
        if (edu_user_type() === 'guardian') {
            return true;
        }
        return edu_has_role_id(7); // future-proof; no such role today
    }

    /**
     * True when the current user is a staff member.
     *
     * @return bool
     */
    function is_staff(): bool
    {
        if (edu_user_type() === 'staff') {
            return true;
        }
        return perm_is_super_admin()
            || perm_is_tenant_admin()
            || is_teacher()
            || is_head_teacher()
            || is_accountant();
    }

    // ============================================================
    // CAPABILITY CHECKS
    // ============================================================

    function can_view_all_classes(): bool
    {
        return perm_is_super_admin() || perm_is_tenant_admin() || is_head_teacher();
    }

    function can_mark_attendance(): bool
    {
        return perm_is_super_admin() || perm_is_tenant_admin()
            || is_head_teacher() || is_teacher();
    }

    function can_verify_attendance(): bool
    {
        return perm_is_super_admin() || perm_is_tenant_admin() || is_head_teacher();
    }

    function can_record_discipline(): bool
    {
        return perm_is_super_admin() || perm_is_tenant_admin()
            || is_head_teacher() || is_teacher();
    }

    function can_review_discipline(): bool
    {
        return perm_is_super_admin() || perm_is_tenant_admin() || is_head_teacher();
    }

    function can_resolve_discipline(): bool
    {
        return perm_is_super_admin() || perm_is_tenant_admin() || is_head_teacher();
    }

    function can_enter_results(): bool
    {
        return perm_is_super_admin() || perm_is_tenant_admin()
            || is_head_teacher() || is_teacher();
    }

    function can_publish_results(): bool
    {
        return perm_is_super_admin() || perm_is_tenant_admin() || is_head_teacher();
    }

    function can_lock_results(): bool
    {
        return perm_is_super_admin() || perm_is_tenant_admin();
    }

    function can_create_students(): bool
    {
        return perm_is_super_admin() || perm_is_tenant_admin() || is_head_teacher();
    }

    function can_edit_students(): bool
    {
        return perm_is_super_admin() || perm_is_tenant_admin() || is_head_teacher();
    }

    function can_enroll_students(): bool
    {
        return perm_is_super_admin() || perm_is_tenant_admin() || is_head_teacher();
    }

    function can_manage_class_offerings(): bool
    {
        return perm_is_super_admin() || perm_is_tenant_admin() || is_head_teacher();
    }

    function can_manage_settings(): bool
    {
        return perm_is_super_admin() || perm_is_tenant_admin();
    }

    function can_manage_finance(): bool
    {
        return perm_is_super_admin() || perm_is_tenant_admin() || is_accountant();
    }

    // ============================================================
    // GUARDIAN CAPABILITY (v1.1)
    // ============================================================

    /**
     * True when the current user is a guardian who is linked to
     * the given student.
     *
     * @param int $studentId
     * @return bool
     */
    function guardian_can_view_student(int $studentId): bool
    {
        if ($studentId <= 0) {
            return false;
        }
        if (!is_guardian()) {
            return false;
        }

        $guardianId = (int)($_SESSION['guardian_id'] ?? 0);
        if ($guardianId <= 0) {
            return false;
        }

        $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            return false;
        }

        if (!class_exists('DatabaseHelper')) {
            return false;
        }

        try {
            $db = DatabaseHelper::getInstance();
            $row = $db->fetchOne(
                "SELECT sg.id
                 FROM student_guardians sg
                 WHERE sg.tenant_id = ?
                   AND sg.guardian_id = ?
                   AND sg.student_id = ?
                   AND sg.deleted_at IS NULL
                 LIMIT 1",
                [$tenantId, $guardianId, $studentId]
            );
            return $row !== false && $row !== null;
        } catch (Exception $e) {
            error_log('guardian_can_view_student error: ' . $e->getMessage());
            return false;
        }
    }
}
