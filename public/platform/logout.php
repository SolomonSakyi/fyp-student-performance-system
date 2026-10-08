<?php

/**
 * Logout Page - Student 360 Platform
 * Clears all session data and redirects to login
 *
 * @package EduTrack
 * @subpackage Platform
 * @version 2.1
 * @filepath public/platform/logout.php
 *
 * v2.1 change (2026-10-07) [SWEEP]:
 *   Platform-root sweep. One docblock entry added, one docblock
 *   line repaired, and the version bumped:
 *     - @version 2.0 raised to 2.1. The v2.x chain is preserved,
 *       matching the shape used for tenant/login.php v2.6 -> v2.7.
 *     - The malformed docblock line
 *         "@package EduTrack * @subpackage Platform"
 *       is split into two separate lines, matching the docblock
 *       shape used by every other file on the record.
 *     - This v2.1 [SWEEP] docblock paragraph was added above the
 *       existing description.
 *   The file is a standalone auth-exit script. It emits no HTML.
 *   It carries no $pageTitle, no $currentPage, no sidebar partial,
 *   and no CSS block. The sweep doctrine's remaining four changes
 *   do not apply because there is nothing in this file for them to
 *   operate on. Every other line of the file is byte-identical to
 *   v2.0, including the session clear, the session cookie deletion,
 *   the two remember-me cookie clears, session_destroy(), the three
 *   no-cache headers, the redirect to /platform/tenant/login.php,
 *   and the final exit.
 */

// Load config first
$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/config/config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// CLEAR ALL SESSION VARIABLES
// ============================================================
$_SESSION = array();

// ============================================================
// DELETE SESSION COOKIE
// ============================================================
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Clear any remember me cookies
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
}

if (isset($_COOKIE['portal_remember'])) {
    setcookie('portal_remember', '', time() - 3600, '/', '', false, true);
}

// ============================================================
// DESTROY SESSION
// ============================================================
session_destroy();

// ============================================================
// PREVENT CACHING
// ============================================================
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Thu, 01 Jan 1970 00:00:00 GMT");

// ============================================================
// REDIRECT TO LOGIN
// ============================================================
header('Location: /platform/tenant/login.php');
exit;
