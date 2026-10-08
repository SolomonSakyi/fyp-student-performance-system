<?php

/**
 * Select/Change School Context - Super Admin
 * This allows Super Admin to switch to a school-specific view
 *
 * @package EduTrack
 * @subpackage Platform\Schools
 * @filepath public/platform/schools/select.php
 * @version 2.1
 *
 * v2.1 change (2026-10-08) [SWEEP + CSRF]:
 *   Platform-schools handler sweep. One change.
 *
 *   CSRF gate. A verify_csrf() call is added at the top of the POST
 *   handling, before the school_id read. Prior to this change the
 *   handler trusted $_POST['school_id'] and $_POST['redirect']
 *   directly, with no CSRF check. The handler mutates session state
 *   — it sets five selected_* session keys for the currently
 *   authenticated super admin — so it is in the class of actions
 *   CSRF is designed to protect, on the same reasoning as a status
 *   update. The verify_csrf() call matches the project convention
 *   used by the guardian requests.php v1.1.2, the tenant
 *   notifications.php v2.6, the approvals/ files, the tenants/
 *   files, schools/index.php v2.1, schools/view.php v2.1,
 *   schools/create.php v2.1, and schools/edit.php v2.1.
 *
 *   NOTE on record — callers of this handler:
 *     - No file on the record builds a POST to this handler. The
 *       four schools/ files that are on the record (index.php v2.1,
 *       view.php v2.1, create.php v2.1, edit.php v2.1) do not
 *       reference /platform/schools/select.php. The tenants/ files
 *       target /platform/tenants/select.php — a distinct file that
 *       is not on the tenants/ folder listing. The approvals/ files
 *       do not reference this handler.
 *     - Any caller of this handler that is not on the record must
 *       emit a hidden csrf_token field in its POST body. If such a
 *       caller exists and does not emit the field, its POST will be
 *       rejected by the verify_csrf() call added by this sweep.
 *       That is the correct outcome for a state-changing handler,
 *       but it is stated here so the dependency is on the record.
 *
 *   Open items on the record (NOT changed by this sweep):
 *     - $_POST['redirect'] is read at the end of this handler and
 *       passed verbatim to header('Location: ...'). It is not
 *       validated or restricted to a same-host path. This is a
 *       classic open-redirect surface. The legitimate use is a
 *       within-platform redirect back to the calling page.
 *
 *   The auth guards are unchanged: logged_in redirects to
 *   /platform/tenant/login.php, is_super_admin redirects to
 *   /platform/tenant/dashboard.php. The POST-method guard is
 *   unchanged. The school SELECT (joined to tenants) is unchanged.
 *   The five session key assignments are unchanged. The redirect
 *   logic and the success flash are unchanged.
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

$schoolId = isset($_POST['school_id']) ? (int)$_POST['school_id'] : 0;
if ($schoolId <= 0) {
    $_SESSION['error'] = 'Invalid school ID.';
    header('Location: /platform/schools/index.php');
    exit;
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

// Get school details
$school = $db->fetchOne(
    "SELECT s.*, t.tenant_name 
     FROM schools s 
     LEFT JOIN tenants t ON s.tenant_id = t.id 
     WHERE s.id = ? AND s.deleted_at IS NULL",
    [$schoolId]
);

if (!$school) {
    $_SESSION['error'] = 'School not found.';
    header('Location: /platform/schools/index.php');
    exit;
}

// Set session variables for school context
$_SESSION['selected_school_id'] = $school['id'];
$_SESSION['selected_school_name'] = $school['school_name'];
$_SESSION['selected_school_code'] = $school['school_code'];
$_SESSION['selected_tenant_id'] = $school['tenant_id'];
$_SESSION['selected_tenant_name'] = $school['tenant_name'];

// Determine redirect URL
$redirect = isset($_POST['redirect']) ? $_POST['redirect'] : '/platform/schools/view.php?id=' . $schoolId;

// Add success message
$_SESSION['success'] = 'Switched to school: ' . $school['school_name'];

header('Location: ' . $redirect);
exit;
