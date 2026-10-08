<?php

/**
 * Security helper - CSRF, session hardening, headers, HTTPS, guards, escaping.
 *
 * @package EduTrack
 * @filepath app/helpers/Security.php
 * @version 1.1 (S20 hardening; adds is_tenant_admin() helper)
 *
 * Session S20 decisions:
 *  SH2A per-session CSRF token for forms + AJAX
 *  SH3A central helper, single include
 *  SH4A regenerate on login, cookie flags, idle timeout, soft hijack check
 *  SH5C h() everywhere including attributes; json_encode for inline JS
 *  SH7A nosniff, X-Frame-Options, Referrer-Policy, CSP (current CDNs)
 *  SH8A force HTTPS (localhost exempt) + secure/httponly/samesite cookies
 *  SH10A display_errors off in prod, log outside webroot, generic user errors
 *
 * v1.1 changes:
 *  - Added is_tenant_admin() - positive admin test, sibling of is_super_admin().
 *    Reads $_SESSION['is_tenant_admin'], which login.php now writes.
 *
 * TEMPORARY (2026-10-08): display_errors forced on for 500 diagnosis.
 *   The line `ini_set('display_errors', $isLocalhost ? '1' : '0');`
 *   has been changed to `ini_set('display_errors', '1');` so the
 *   PHP fatal error behind the dashboard 500 appears in the browser.
 *   MUST BE REVERTED to the $isLocalhost ternary after diagnosis.
 *
 * This file is intentionally side-effect-light: it defines functions only,
 * except for the one-time session bootstrap which is guarded so it can be
 * included from app/bootstrap.php exactly once.
 */

// ------------------------------------------------------------------
// One-time session bootstrap (guarded)
// ------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE && !defined('EDUTRACK_SESSION_STARTED')) {
    define('EDUTRACK_SESSION_STARTED', true);

    // SH4A + SH8A: cookie flags must be set before session_start().
    $isLocalhost = in_array(
        $_SERVER['REMOTE_ADDR'] ?? '',
        ['127.0.0.1', '::1'],
        true
    ) || (($_SERVER['HTTP_HOST'] ?? '') === 'localhost');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $isLocalhost ? '0' : '1');

    // Idle timeout (absolute) - 2 hours
    ini_set('session.gc_maxlifetime', (string)(2 * 60 * 60));

    session_start();

    // SH4A: soft idle timeout
    $idleLimit = 2 * 60 * 60; // 2 hours
    if (isset($_SESSION['last_activity']) && (time() - (int)$_SESSION['last_activity']) > $idleLimit) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['errors'] = ['Your session expired. Please sign in again.'];
    }
    $_SESSION['last_activity'] = time();

    // SH4A: soft hijack check - log only, never block.
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    if (!isset($_SESSION['_ua'])) {
        $_SESSION['_ua'] = $ua;
    } elseif ($_SESSION['_ua'] !== $ua) {
        error_log('Security: user-agent changed mid-session (soft signal).');
    }
}

// ------------------------------------------------------------------
// SH10A: production error display
// ------------------------------------------------------------------
if (!defined('EDUTRACK_ERRORS_CONFIGURED')) {
    define('EDUTRACK_ERRORS_CONFIGURED', true);
    $isLocalhost = $isLocalhost ?? in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', $isLocalhost ? '1' : '0');
    ini_set('log_errors', '1');
    // Log path: sibling of the webroot. Falls back to syslog if not writable.
    $logDir = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    if (is_dir($logDir) && is_writable($logDir)) {
        ini_set('error_log', $logDir . '/edutrack-error.log');
    }
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

// ------------------------------------------------------------------
// SH7A: security headers
// ------------------------------------------------------------------
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    // CSP - keep it compatible with the CDNs already in use.
    // script-src includes 'unsafe-inline' because the current pages use inline <script>;
    // this will be tightened in a follow-up session once scripts are externalised.
    $csp = "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; "
        . "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com data:; "
        . "img-src 'self' data: https:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'self'; "
        . "base-uri 'self'; "
        . "form-action 'self'";
    header('Content-Security-Policy: ' . $csp);
}

// ------------------------------------------------------------------
// SH8A: force HTTPS in production
// ------------------------------------------------------------------
function enforce_https(): void
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isLocalhost = (strpos($host, 'localhost') !== false)
        || (strpos($host, '127.0.0.1') !== false);
    if ($isLocalhost) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!$https) {
        $target = 'https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $target, true, 301);
        exit;
    }
}

// ------------------------------------------------------------------
// SH5C: escaping helpers
// ------------------------------------------------------------------

/**
 * Escape a value for HTML context (text and attribute).
 * Named h() to match the existing codebase convention.
 */
function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Escape a value for safe embedding inside a <script> block.
 * Returns a JSON string with HTML-sensitive characters hex-encoded.
 */
function h_js($value): string
{
    return json_encode(
        $value,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
    );
}

// ------------------------------------------------------------------
// SH2A: CSRF
// ------------------------------------------------------------------
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

/**
 * Verify the CSRF token on the current request.
 * Accepts the token from POST body (_csrf) or the X-CSRF-Token header (AJAX).
 * On failure: aborts with 403. On success: returns true.
 */
function verify_csrf(): bool
{
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['_csrf'] ?? '';

    if ($expected === '' || $sent === '' || !hash_equals($expected, (string)$sent)) {
        if (headers_sent() === false) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
        }
        // If the caller looks like AJAX, return JSON; otherwise plain text.
        $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest')
            || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
        } else {
            echo 'Invalid or missing CSRF token. Please refresh the page and try again.';
        }
        exit;
    }
    return true;
}

// ------------------------------------------------------------------
// SH4A: session ID regeneration helpers
// ------------------------------------------------------------------
function session_login_regenerate(): void
{
    // Call immediately after a successful login, before writing user data.
    @session_regenerate_id(true);
}

// ------------------------------------------------------------------
// Authorization guards (thin wrappers around the session state)
// ------------------------------------------------------------------
function is_logged_in(): bool
{
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

function current_tenant_id(): int
{
    return (int)($_SESSION['tenant_id'] ?? 0);
}

function current_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function is_super_admin(): bool
{
    return !empty($_SESSION['is_super_admin']);
}

function is_tenant_admin(): bool
{
    return !empty($_SESSION['is_tenant_admin']);
}

function is_finance(): bool
{
    return !empty($_SESSION['is_finance']) || is_super_admin();
}

function require_login(string $redirect = '/platform/tenant/login.php'): void
{
    if (!is_logged_in()) {
        $_SESSION['errors'] = ['Please sign in to continue.'];
        header('Location: ' . $redirect);
        exit;
    }
}

function require_tenant(): void
{
    require_login();
    if (current_tenant_id() <= 0) {
        http_response_code(403);
        exit('No tenant context.');
    }
}

function require_super_admin(): void
{
    require_login();
    if (!is_super_admin()) {
        http_response_code(403);
        exit('Super admin access required.');
    }
}

function require_finance(): void
{
    require_tenant();
    if (!is_finance()) {
        http_response_code(403);
        exit('Finance access required.');
    }
}
