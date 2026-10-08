<?php

/**
 * Tenant Login Handler
 * Authenticates user based on audience selection using Audience Engine
 *
 * @package EduTrack
 * @subpackage Platform\Auth
 * @version 2.1
 * @filepath public/platform/auth/tenant-login.php
 *
 * v2.1 change (2026-10-07) [SWEEP]:
 *   Platform-auth sweep. One change:
 *     - @version 2.0 raised to 2.1.
 *   This v2.1 [SWEEP] docblock paragraph was added above the
 *   existing description.
 *
 *   The file is a POST-only login handler. It emits no HTML. It
 *   carries no $pageTitle, no $currentPage, no sidebar partial, no
 *   .nav-subgroup-label CSS rule, and no visible "EduTrack" string.
 *   The sweep doctrine's other four changes do not apply because
 *   there is nothing in this file for them to operate on. The
 *   audit_logs column list used by AuditService::logLoginSuccess()
 *   is left as-is per the accepted policy; the divergence from the
 *   other audit writers' column list is a separate defect.
 *
 *   Every line of code is byte-identical to v2.0, including the
 *   session_start(), the method guard, the service instantiation,
 *   the audience and input validation, the tenant status check,
 *   the authenticate call, the session build, the tenant() chain,
 *   the remember-me cookie and its user_remember_tokens row, the
 *   audit call, the dashboard map, the return-URL validation, the
 *   redirect, and the final exit.
 */

session_start();

// ============================================================
// LOAD REQUIRED CLASSES
// ============================================================
require_once __DIR__ . '/../../../bootstrap/context.php';
require_once __DIR__ . '/../../../app/services/Auth/AudienceService.php';
require_once __DIR__ . '/../../../app/services/Auth/AudienceAuthenticator.php';
require_once __DIR__ . '/../../../app/services/Auth/AuditService.php';
require_once __DIR__ . '/../../../app/services/Auth/SessionManager.php';

// ============================================================
// VALIDATE REQUEST
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /platform/tenant/login.php');
    exit;
}

$audience = $_POST['audience'] ?? '';
$identifier = trim($_POST['identifier'] ?? '');
$password = $_POST['password'] ?? '';
$tenantId = (int)($_POST['tenant_id'] ?? 0);
$schoolId = isset($_POST['school_id']) ? (int)$_POST['school_id'] : null;
$campusId = isset($_POST['campus_id']) ? (int)$_POST['campus_id'] : null;
$domain = $_POST['domain'] ?? '';
$rememberMe = isset($_POST['remember_me']) && $_POST['remember_me'] == '1';

// Initialize services
$audienceService = new AudienceService();
$authenticator = new AudienceAuthenticator();
$auditService = new AuditService();
$sessionManager = new SessionManager();

// ============================================================
// VALIDATE AUDIENCE
// ============================================================
if (!$audienceService->isValidAudience($audience)) {
    $_SESSION['login_error'] = 'Invalid login audience selected.';
    header('Location: /platform/tenant/login.php');
    exit;
}

// ============================================================
// VALIDATE INPUT
// ============================================================
if (empty($identifier) || empty($password)) {
    $_SESSION['login_error'] = 'Please enter your credentials.';
    header('Location: /platform/tenant/login.php?audience=' . $audience);
    exit;
}

// ============================================================
// VALIDATE TENANT CONTEXT
// ============================================================
if (!$tenantId) {
    $_SESSION['login_error'] = 'Invalid tenant context.';
    header('Location: /platform/tenant/login.php');
    exit;
}

// ============================================================
// VERIFY TENANT IS ACTIVE
// ============================================================
try {
    $tenantStatus = $db->getValue(
        "SELECT status FROM tenants WHERE id = ? AND deleted_at IS NULL",
        [$tenantId]
    );

    if ($tenantStatus !== 'active') {
        $_SESSION['login_error'] = 'This school portal is temporarily unavailable.';
        header('Location: /platform/tenant/login.php');
        exit;
    }
} catch (Exception $e) {
    error_log('Tenant status check error: ' . $e->getMessage());
}

// ============================================================
// AUTHENTICATE
// ============================================================
$result = $authenticator->authenticate($audience, $identifier, $password);

if (!$result['success']) {
    $_SESSION['login_error'] = $result['error'];
    header('Location: /platform/tenant/login.php?audience=' . $audience);
    exit;
}

// ============================================================
// GET USER CONTEXT
// ============================================================
$user = $result['user'];

// ============================================================
// BUILD SESSION
// ============================================================
// Create secure session
$sessionData = $sessionManager->createSession($user, $rememberMe);

if (!$sessionData) {
    $_SESSION['login_error'] = 'Unable to create session. Please try again.';
    header('Location: /platform/tenant/login.php?audience=' . $audience);
    exit;
}

// Update tenant context with user info
tenant()
    ->setUserId($user['user_id'])
    ->setPersonId($user['person_id'])
    ->setUserRoles($user['roles'] ?? [])
    ->setUserPermissions($user['permissions'] ?? [])
    ->setLoginAudience($user['login_audience'])
    ->saveToSession();

// ============================================================
// SET ADDITIONAL SESSION VARIABLES
// ============================================================
$_SESSION['logged_in'] = true;
$_SESSION['user_id'] = $user['user_id'];
$_SESSION['person_id'] = $user['person_id'];
$_SESSION['user_name'] = $user['full_name'] ?? $user['username'] ?? 'User';
$_SESSION['first_name'] = $user['first_name'] ?? '';
$_SESSION['last_name'] = $user['last_name'] ?? '';
$_SESSION['username'] = $user['username'] ?? '';
$_SESSION['email'] = $user['email'] ?? '';
$_SESSION['tenant_id'] = $user['tenant_id'];
$_SESSION['tenant_name'] = tenantName();
$_SESSION['school_id'] = $user['school_id'];
$_SESSION['school_name'] = schoolName();
$_SESSION['campus_id'] = $user['campus_id'] ?? null;
$_SESSION['campus_name'] = campusName();
$_SESSION['login_audience'] = $user['login_audience'];
$_SESSION['login_domain'] = $domain;
$_SESSION['login_time'] = time();
$_SESSION['user_roles'] = $user['roles'] ?? [];
$_SESSION['user_permissions'] = $user['permissions'] ?? [];
$_SESSION['admin_level'] = $user['admin_level'] ?? 'none';

// Set remember me cookie if enabled
if ($rememberMe) {
    $token = bin2hex(random_bytes(32));
    $expires = time() + 604800; // 7 days

    setcookie(
        'edutrack_remember',
        $token,
        [
            'expires' => $expires,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
            'httponly' => true,
            'samesite' => 'Lax'
        ]
    );

    // Store remember token in database (optional)
    try {
        $db->execute(
            "INSERT INTO user_remember_tokens (user_id, token, expires_at, created_at)
             VALUES (?, ?, FROM_UNIXTIME(?), NOW())",
            [$user['user_id'], $token, $expires]
        );
    } catch (Exception $e) {
        error_log('Remember token error: ' . $e->getMessage());
    }
}

// ============================================================
// AUDIT LOG - SUCCESS
// ============================================================
try {
    $auditService->logLoginSuccess(
        $user['user_id'],
        $user['tenant_id'],
        $user['school_id'] ?? null,
        $user['campus_id'] ?? null,
        $user['login_audience']
    );
} catch (Exception $e) {
    error_log('Audit log error: ' . $e->getMessage());
}

// ============================================================
// REDIRECT TO DASHBOARD
// ============================================================
$dashboardMap = [
    'staff' => '/tenant/staff/dashboard.php',
    'student' => '/tenant/student/dashboard.php',
    'admin' => '/tenant/admin/dashboard.php'
];

$dashboard = $dashboardMap[$audience] ?? '/tenant/dashboard.php';

// Check if there's a return URL
$returnUrl = $_POST['return_url'] ?? $_GET['return'] ?? '';
if (!empty($returnUrl)) {
    // Validate return URL to prevent open redirects
    $parsedUrl = parse_url($returnUrl);
    if (isset($parsedUrl['host']) && $parsedUrl['host'] === $_SERVER['HTTP_HOST']) {
        $dashboard = $returnUrl;
    }
}

header('Location: ' . $dashboard);
exit;
