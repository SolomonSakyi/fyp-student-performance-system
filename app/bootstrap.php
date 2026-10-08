<?php

/**
 * Application bootstrap - single include for every page and endpoint.
 *
 * @package EduTrack
 * @filepath app/bootstrap.php
 * @version 1.1 (S20 security hardening + SHAPE-C tenant context)
 *
 * Include order matters:
 *   1. config.php     -> DB_* constants
 *   2. Security.php   -> starts the hardened session, sets error display,
 *                        defines CSRF / h() / h_js() / guards / headers
 *   3. DatabaseHelper -> PDO singleton
 *   4. TenantContext  -> the singleton that holds the request's tenant,
 *                        school, campus, and user context
 *   5. TenantContextMiddleware -> restores the nested session context
 *                        or resolves it from the request hostname
 *
 * After this file returns, the page has:
 *   - A live, hardened session
 *   - Security headers already sent
 *   - HTTPS enforced (except on localhost)
 *   - $db (DatabaseHelper instance)
 *   - h(), csrf_field(), verify_csrf(), require_login(), require_tenant(),
 *     require_super_admin(), require_finance() available
 *   - A populated TenantContext (best effort; see step 4)
 *
 * v1.1 change (2026-09-30) [SHAPE-C]:
 *   Added step 4. The bootstrap now requires TenantContext and
 *   TenantContextMiddleware and calls TenantContextMiddleware::handle()
 *   after $db is live. The middleware restores the nested session
 *   context ($_SESSION['tenant_context']) or resolves it from the
 *   request hostname, and on the domain path calls saveToSession().
 *
 *   On the platform hostname (admin.edutrack.local, localhost,
 *   127.0.0.1, ::1), the middleware returns true early without
 *   populating the nested context. TenantContext::restoreFromSession()
 *   is not called by the middleware in that case either. Pages that
 *   need a populated context on the platform hostname must call
 *   TenantContext::getInstance()->restoreFromSession() themselves;
 *   that method now falls back to the flat session keys written by
 *   login.php v2.2 (the SHAPE-C change to TenantContext.php v2.1).
 *
 *   The middleware call is best-effort. If it throws - for example
 *   if DomainResolver.php is missing, or the tenant status check
 *   fails - the catch logs the error and the bootstrap continues.
 *   The page still renders, and the flat-key fallback in
 *   TenantContext::restoreFromSession() still populates the context
 *   from the session keys on the next call.
 */

if (defined('EDUTRACK_BOOTSTRAPPED')) {
    return;
}
define('EDUTRACK_BOOTSTRAPPED', true);

$projectRoot = dirname(__DIR__);

// 1. Config
if (!defined('DB_HOST')) {
    require_once $projectRoot . '/config/config.php';
}

// 2. Security (must come before any output; starts the session, sends headers)
require_once $projectRoot . '/app/helpers/Security.php';

send_security_headers();
enforce_https();

// 3. Database
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';

try {
    $db = DatabaseHelper::getInstance();
} catch (Throwable $e) {
    error_log('Bootstrap: DB connection failed - ' . $e->getMessage());
    http_response_code(500);
    if ((($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
        || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
    ) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Service temporarily unavailable.']);
    } else {
        echo 'Service temporarily unavailable.';
    }
    exit;
}

// 4. [SHAPE-C] Tenant context
// The middleware restores the nested session context or resolves it
// from the request hostname. On the platform hostname it returns
// true early; the flat-key fallback in TenantContext.php v2.1
// populates the context from the session keys login.php v2.2 writes.
//
// Order matters: TenantContext must be required before
// TenantContextMiddleware, because the middleware's constructor
// calls TenantContext::getInstance().
//
// Best-effort. A failure here logs and continues. The page renders
// without a context, which is the same state as before this change.
require_once $projectRoot . '/app/services/Tenant/TenantContext.php';
require_once $projectRoot . '/app/middleware/TenantContextMiddleware.php';

try {
    $middleware = new TenantContextMiddleware();
    $middleware->handle();
} catch (Throwable $e) {
    error_log('Bootstrap: TenantContextMiddleware failed - ' . $e->getMessage());
}
