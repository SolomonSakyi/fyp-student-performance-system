<?php

/**
 * Delete School - Super Admin
 *
 * @package EduTrack
 * @subpackage Platform\Schools
 * @filepath public/platform/schools/delete.php
 * @version 2.1
 *
 * v2.1 change (2026-10-08) [SWEEP + CSRF]:
 *   Platform-schools handler sweep. One change.
 *
 *   CSRF gate. A verify_csrf() call is added at the top of the POST
 *   handling, before the id read. Prior to this change the handler
 *   trusted $_POST['id'] directly, with no CSRF check. This closes
 *   the loop with the two form builders that now emit a hidden
 *   csrf_token field to this handler — schools/index.php v2.1 and
 *   schools/view.php v2.1, both swept earlier in this session, both
 *   of which carry the delete modal whose form targets this file.
 *   Before this change the field was present in the form body and
 *   ignored by the handler. The verify_csrf() call matches the
 *   project convention used by the guardian requests.php v1.1.2,
 *   the tenant notifications.php v2.6, the approvals/ files, the
 *   tenants/ files, and schools/create.php v2.1 + schools/edit.php
 *   v2.1.
 *
 *   Open items on the record (NOT changed by this sweep):
 *
 *     - Campus cascade mismatch. The delete modal copy on
 *       schools/index.php v2.1 and schools/view.php v2.1 states,
 *       "This will also delete all associated campuses." This
 *       handler soft-deletes only the schools row. It does not
 *       soft-delete the school's campuses rows. Either campuses are
 *       soft-deleted elsewhere (a database cascade, an FK-trigger,
 *       or a separate handler) or the modal copy overstates the
 *       cascade and the campuses are left live. Not resolved by this
 *       sweep.
 *
 *     - No transaction. This handler performs a single write to the
 *       schools table. A transaction is not strictly required for a
 *       single write. It is stated here for consistency with the
 *       other swept handlers on the record (tenants/register.php
 *       v2.2, tenants/delete.php v2.1, approvals/approve.php v1.0),
 *       which wrap their multi-write blocks in transactions.
 *
 *   The auth guards are unchanged: logged_in redirects to
 *   /platform/tenant/login.php, is_super_admin redirects to
 *   /platform/tenant/dashboard.php. The POST-method guard is
 *   unchanged. The school SELECT (for the name used in the flash)
 *   is unchanged. The single UPDATE schools SET deleted_at = NOW()
 *   is unchanged. The four redirects to /platform/schools/index.php
 *   are unchanged.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
 */

$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/config/config.php';

session_start();

// Check authentication and super admin role
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;
if (!$isSuperAdmin) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /platform/schools/index.php');
    exit;
}

// =============================================
// CSRF GATE
// =============================================
if (function_exists('verify_csrf')) {
    verify_csrf();
} elseif (class_exists('Security')) {
    Security::verifyCsrf();
}

$schoolId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($schoolId <= 0) {
    $_SESSION['error'] = 'Invalid school ID.';
    header('Location: /platform/schools/index.php');
    exit;
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

$school = $db->fetchOne(
    "SELECT school_name FROM schools WHERE id = ? AND deleted_at IS NULL",
    [$schoolId]
);

if (!$school) {
    $_SESSION['error'] = 'School not found.';
    header('Location: /platform/schools/index.php');
    exit;
}

try {
    $db->execute(
        "UPDATE schools SET deleted_at = NOW() WHERE id = ?",
        [$schoolId]
    );
    $_SESSION['success'] = 'School "' . $school['school_name'] . '" deleted successfully.';
} catch (Exception $e) {
    $_SESSION['error'] = 'Error deleting school: ' . $e->getMessage();
}

header('Location: /platform/schools/index.php');
exit;
