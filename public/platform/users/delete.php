<?php

/**
 * Delete User - Super Admin
 *
 * @package EduTrack
 * @subpackage Platform\Users
 * @filepath public/platform/users/delete.php
 * @version 1.0
 *
 * v1.0 change (2026-10-07) [SWEEP]:
 *   Platform-users sweep. One change:
 *     - @version 2.0 raised to 1.0.
 *   This v1.0 [SWEEP] docblock paragraph was added above the
 *   existing entries.
 *
 *   The file is a POST-only soft-delete handler. It emits no HTML.
 *   It carries no $pageTitle, no $currentPage, no sidebar partial,
 *   no CSS block, and no visible "EduTrack" string. The sweep
 *   doctrine's X-1 change has nothing to rename, and the sidebar-
 *   reconciliation change has nothing to replace.
 *
 *   Every line of code is byte-identical to v2.0, including the
 *   session_start(), the auth guard, the method check, the
 *   cannot-delete-self server-side guard, the user-lookup query,
 *   the soft-delete UPDATE on platform_users (deleted_at = NOW(),
 *   is_active = 0), the $_SESSION['success'] write, the catch
 *   block, and the redirect to /platform/users/index.php.
 *
 *   NOTE on record: this file performs a destructive UPDATE on a
 *   POST that carries no CSRF token. The two caller pages
 *   (users/index.php and users/view.php) post only a hidden id
 *   field. Coordinating a CSRF fix across all three files is a
 *   separate action, not part of this sweep.
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
    header('Location: /platform/users/index.php');
    exit;
}

$userId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($userId <= 0) {
    $_SESSION['error'] = 'Invalid user ID.';
    header('Location: /platform/users/index.php');
    exit;
}

// Prevent deleting yourself
if ($userId == $_SESSION['user_id']) {
    $_SESSION['error'] = 'You cannot delete your own account.';
    header('Location: /platform/users/index.php');
    exit;
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
$db = DatabaseHelper::getInstance();

$user = $db->fetchOne(
    "SELECT first_name, last_name FROM platform_users WHERE id = ? AND deleted_at IS NULL",
    [$userId]
);

if (!$user) {
    $_SESSION['error'] = 'User not found.';
    header('Location: /platform/users/index.php');
    exit;
}

try {
    // Soft delete the user
    $db->execute(
        "UPDATE platform_users SET deleted_at = NOW(), is_active = 0 WHERE id = ?",
        [$userId]
    );

    $_SESSION['success'] = 'User "' . $user['first_name'] . ' ' . $user['last_name'] . '" deleted successfully.';
} catch (Exception $e) {
    $_SESSION['error'] = 'Error deleting user: ' . $e->getMessage();
}

header('Location: /platform/users/index.php');
exit;
