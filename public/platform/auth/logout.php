<?php

/**
 * Logout Handler
 * Destroys session and redirects to tenant login
 *
 * @package EduTrack
 * @subpackage Platform\Auth
 * @version 1.1
 * @filepath public/platform/auth/logout.php
 *
 * v1.1 change (2026-10-07) [SWEEP]:
 *   Platform-auth sweep. One change:
 *     - @version 1.0 raised to 1.1.
 *   This v1.1 [SWEEP] docblock paragraph was added above the
 *   existing description.
 *
 *   The file is a logout handler. It emits no HTML. It carries no
 *   $pageTitle, no $currentPage, no sidebar partial, no
 *   .nav-subgroup-label CSS rule, and no visible "EduTrack" string.
 *   The sweep doctrine's other four changes do not apply because
 *   there is nothing in this file for them to operate on. The
 *   audit_logs column list used by AuditService::logLogout() is
 *   left as-is per the accepted policy; the divergence from the
 *   other audit writers' column list is a separate defect.
 *
 *   Every line of code is byte-identical to v1.0, including the
 *   session_start(), the guarded audit call, the session clear
 *   ($_SESSION = []), the session-cookie deletion, the
 *   session_destroy(), the redirect to /platform/tenant/login.php,
 *   and the final exit.
 */

session_start();

// Log logout event
if (isset($_SESSION['user_id']) && isset($_SESSION['tenant_id'])) {
    try {
        require_once __DIR__ . '/../../../app/services/Auth/AuditService.php';
        $auditService = new AuditService();
        $auditService->logLogout(
            $_SESSION['user_id'],
            $_SESSION['tenant_id'],
            $_SESSION['school_id'] ?? null,
            null,
            $_SESSION['login_audience'] ?? 'unknown'
        );
    } catch (Exception $e) {
        error_log('Logout audit error: ' . $e->getMessage());
    }
}

// Clear session
$_SESSION = [];

// Destroy session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy session
session_destroy();

// Redirect to tenant login
header('Location: /platform/tenant/login.php');
exit;
