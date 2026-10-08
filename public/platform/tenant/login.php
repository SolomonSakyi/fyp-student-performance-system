<?php

/**
 * Tenant Login Page - Student 360 Platform
 *
 * @package EduTrack
 * @subpackage Platform\Tenant
 * @version 2.8
 * @filepath public/platform/tenant/login.php
 *
 * v2.8 change (2026-10-07) [SWEEP X-1]:
 *   Corpus decision X-1 applied in full: every user-facing
 *   occurrence of the string "EduTrack" in this file's rendered
 *   output is renamed to "Student 360". Two visible body strings
 *   were missed by the v2.7 sweep:
 *     - The logo heading inside .login-container .logo. It read
 *       <h2>...EduTrack</h2> and now reads
 *       <h2>...Student 360</h2>.
 *     - The footer copyright line. It read
 *       <p>&copy; <year> EduTrack. All rights reserved.</p> and now
 *       reads
 *       <p>&copy; <year> Student 360. All rights reserved.</p>.
 *   The $pageTitle rename from v2.7 already handled the <title>
 *   tag. With the two body strings now renamed, no user-facing
 *   "EduTrack" string remains on this page. Class names, table
 *   names, database name, and file paths remain EduTrack per X-1.
 *   Every other line of the file is byte-identical to v2.7.
 *
 * v2.7 change (2026-10-07) [SWEEP]:
 *   Platform-tenant sweep. Two changes:
 *     - The user-facing brand in $pageTitle changed from
 *       'Login - EduTrack Platform' to 'Login - Student 360 Platform'.
 *     - This v2.7 [SWEEP] docblock paragraph was added above the
 *       existing entries.
 *   The page is a standalone auth surface. It does not carry the
 *   tenant sidebar partial and does not carry the
 *   .nav-subgroup-label CSS rule. No $currentPage value is set.
 *   Every other line of the file is byte-identical to v2.6.
 *
 * v2.6 changes [ITEM-26]:
 *  - The SELECT in the POST block now includes platform_users.staff_id.
 *    The v2.4 and v2.5 versions of this file wrote
 *    $_SESSION['staff_id'] from $user['staff_id'], but the SELECT
 *    never fetched that column, so the isset() check was always
 *    false and $_SESSION['staff_id'] was always null. Adding the
 *    column to the SELECT closes that defect. The session write
 *    itself is unchanged from v2.4.
 *
 * v2.5 changes [ITEM-19]:
 *  - Added tenant_login_write_audit() as a local helper. It writes
 *    one row to audit_logs using the same column list used by
 *    results.php and result-locks.php. It fails silently (logs via
 *    error_log) so a failure here cannot break the login flow.
 *  - On a failed password verify, one row is written to
 *    login_attempts with outcome = 'failed'. The row records the
 *    resolved user id (or NULL if the username did not resolve),
 *    the literal username, the client IP, and the user agent.
 *  - On a successful password verify, before the session is
 *    written, one row is written to login_attempts with
 *    outcome = 'success', then SuspiciousLoginService::check() is
 *    called with the resolved user id, the username, the IP, and
 *    the user agent. The verdict is recorded in audit_logs via
 *    tenant_login_write_audit(). Per Decision 19-C-1, the verdict
 *    is recorded but does NOT block the login.
 *  - All three new write paths are wrapped in try/catch so a
 *    failure in login_attempts or in the heuristic cannot break
 *    the login flow. The heuristic itself fails open.
 *
 * v2.1 changes:
 *  - Loads Security.php after DatabaseHelper.php so session_login_regenerate()
 *    is available for the successful-login path.
 *  - SELECT now includes platform_users.is_tenant_admin.   [removed in v2.3]
 *  - Session now carries $_SESSION['is_tenant_admin'].
 *  - Calls session_login_regenerate() after successful authentication,
 *    guarded by function_exists().
 *
 * v2.2 changes [SCHOOL-IDENTITY]:
 *  - Resolves the current school from $_SERVER['HTTP_HOST'] against
 *    tenant_domains before rendering the login form. Strict.
 *  - Populates session from the resolved school row.
 *  - Cross-checks the authenticated user's tenant_id against the
 *    resolved school's tenant_id.
 *
 * v2.3 changes [ROLE-SOURCE]:
 *  - The boolean columns platform_users.is_super_admin and
 *    is_tenant_admin are no longer read. platform_user_roles is the
 *    sole source of role truth.
 *  - Writes $_SESSION['roles'] and $_SESSION['role_names'].
 *
 * v2.4 changes [USER-TYPE-AND-GUARDIAN]:
 *  - The SELECT now reads platform_users.user_type and
 *    platform_users.guardian_id.
 *  - Three new validation rules on login:
 *      1. user_type must be one of the four live values:
 *         platform_admin, tenant_admin, staff, guardian.
 *      2. user_type = 'guardian' requires a non-zero guardian_id.
 *      3. user_type = 'guardian' requires the linked guardians
 *         row to be live (deleted_at IS NULL AND is_active = 1).
 *  - The role-rows check from v2.3 is skipped when
 *    user_type = 'guardian', because guardians hold no roles.
 *  - Two new session keys are written on successful login:
 *      $_SESSION['user_type']    one of the four live values
 *      $_SESSION['guardian_id']  int or null
 *    Also written: $_SESSION['staff_id'] (int or null).
 *    These keys are what Permissions.php v1.1 reads.
 *  - The redirect is now three-way: guardian -> /platform/guardian/
 *    index.php, super admin -> /platform/index.php, everyone else
 *    -> /platform/tenant/dashboard.php.
 *
 * DEPENDENCY:
 *  /platform/guardian/index.php does not exist yet. Item 7 of the
 *  code phase builds it. Until then, a guardian login will
 *  redirect to a 404. That is expected.
 */

// =============================================
// SESSION START
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// [SCHOOL-IDENTITY] RESOLVE SCHOOL FROM HOSTNAME
// =============================================
$projectRoot = dirname(__DIR__, 3);

if (file_exists($projectRoot . '/config/config.php')) {
    require_once $projectRoot . '/config/config.php';
} else {
    die('config.php not found!');
}

require_once $projectRoot . '/app/helpers/DatabaseHelper.php';

if (file_exists($projectRoot . '/app/helpers/Security.php')) {
    require_once $projectRoot . '/app/helpers/Security.php';
}

$db = DatabaseHelper::getInstance();

// [SCHOOL-IDENTITY] Normalise the hostname: strip port, lowercase.
$requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
if (($pos = strpos($requestHost, ':')) !== false) {
    $requestHost = substr($requestHost, 0, $pos);
}

// [SCHOOL-IDENTITY] Strict lookup.
$resolvedSchool = null;
if ($requestHost !== '') {
    try {
        $resolvedSchool = $db->fetchOne(
            "SELECT s.id, s.school_name, s.logo_path, s.tenant_id, s.uuid, s.deleted_at
             FROM tenant_domains td
             JOIN schools s ON td.school_id = s.id
             WHERE td.domain_name = ?
               AND td.is_active = 1
               AND td.is_verified = 1
               AND td.deleted_at IS NULL
               AND s.deleted_at IS NULL
             LIMIT 1",
            [$requestHost]
        );
    } catch (Exception $e) {
        error_log('Tenant login school resolution failed: ' . $e->getMessage());
        $resolvedSchool = null;
    }
}

if (!$resolvedSchool) {
    http_response_code(404);
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>School not found</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
        <style>
            body {
                font-family: system-ui, sans-serif;
                background: #f0f2f5;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            .nf-card {
                background: #fff;
                border-radius: 16px;
                padding: 40px;
                max-width: 480px;
                text-align: center;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
            }

            .nf-card i {
                font-size: 48px;
                color: #dc3545;
                margin-bottom: 16px;
                display: block;
            }

            .nf-card h1 {
                font-size: 22px;
                font-weight: 700;
                color: #1a1a2e;
                margin-bottom: 8px;
            }

            .nf-card p {
                color: #6c757d;
                font-size: 14px;
                margin: 0;
            }
        </style>
    </head>

    <body>
        <div class="nf-card">
            <i class="fas fa-school-circle-xmark"></i>
            <h1>School not found</h1>
            <p>This address is not a registered school. Please check the URL and try again, or contact your administrator.</p>
        </div>
    </body>

    </html>
<?php
    exit;
}

// =============================================
// HELPERS
// =============================================

/**
 * [v2.4] True when the guardians row for a guardian user is live
 * and active. Used to reject guardian logins whose guardians row
 * has been soft-deleted or deactivated.
 */
function tenant_login_guardian_is_live($db, int $tenantId, int $guardianId): bool
{
    if ($tenantId <= 0 || $guardianId <= 0) {
        return false;
    }
    try {
        $row = $db->fetchOne(
            "SELECT id FROM guardians
             WHERE id = ? AND tenant_id = ?
               AND deleted_at IS NULL
               AND is_active = 1
             LIMIT 1",
            [$guardianId, $tenantId]
        );
        return $row !== false && $row !== null;
    } catch (Exception $e) {
        error_log('tenant_login_guardian_is_live error: ' . $e->getMessage());
        return false;
    }
}

/**
 * [v2.5 ITEM-19] Write one row to audit_logs. Local helper, used
 * only by this page. Fails silently (logs to error_log) so a
 * failure here cannot break the login flow.
 */
function tenant_login_write_audit($db, int $tenantId, int $userId, string $action, string $resourceType, ?int $resourceId, array $details): void
{
    try {
        $db->execute(
            "INSERT INTO audit_logs (user_id, tenant_id, action, resource_type, resource_id, details, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $userId ?: null,
                $tenantId,
                $action,
                $resourceType,
                $resourceId,
                json_encode($details, JSON_UNESCAPED_UNICODE),
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]
        );
    } catch (Throwable $e) {
        error_log('login.php: tenant_login_write_audit failed - ' . $e->getMessage());
    }
}

// =============================================
// IF ALREADY LOGGED IN, REDIRECT APPROPRIATELY
// =============================================
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $existingType = $_SESSION['user_type'] ?? null;
    if ($existingType === 'guardian') {
        header('Location: /platform/guardian/index.php');
    } elseif (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] === true) {
        header('Location: /platform/index.php');
    } else {
        header('Location: /platform/tenant/dashboard.php');
    }
    exit;
}

// =============================================
// PAGE SETUP
// =============================================
$pageTitle = 'Login - Student 360 Platform';
$error = '';
$success = '';

// =============================================
// HANDLE LOGIN FORM SUBMISSION
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        try {
            // [v2.6 ITEM-26] SELECT now reads staff_id.
            $user = $db->fetchOne(
                "SELECT id, username, email, password_hash, first_name, last_name,
                        tenant_id, is_active, user_type, guardian_id, staff_id
                 FROM platform_users
                 WHERE (username = ? OR email = ?) AND deleted_at IS NULL",
                [$username, $username]
            );

            if (!$user) {
                // [v2.5 ITEM-19] Record the failed attempt (username not resolved).
                try {
                    $db->execute(
                        "INSERT INTO login_attempts (uuid, user_id, username_attempted, ip_address, user_agent, outcome)
                         VALUES (?, ?, ?, ?, ?, 'failed')",
                        [
                            bin2hex(random_bytes(16)),
                            null,
                            $username,
                            $_SERVER['REMOTE_ADDR'] ?? '',
                            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                        ]
                    );
                } catch (Throwable $e) {
                    error_log('login.php: failed to write login_attempts (failed, unknown user) - ' . $e->getMessage());
                }
                $error = 'Invalid username or password.';
            } elseif (!$user['is_active']) {
                $error = 'Your account has been deactivated. Please contact the administrator.';
            } elseif (!password_verify($password, $user['password_hash'])) {
                // [v2.5 ITEM-19] Record the failed attempt (username resolved).
                try {
                    $db->execute(
                        "INSERT INTO login_attempts (uuid, user_id, username_attempted, ip_address, user_agent, outcome)
                         VALUES (?, ?, ?, ?, ?, 'failed')",
                        [
                            bin2hex(random_bytes(16)),
                            (int)$user['id'],
                            $username,
                            $_SERVER['REMOTE_ADDR'] ?? '',
                            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                        ]
                    );
                } catch (Throwable $e) {
                    error_log('login.php: failed to write login_attempts (failed, known user) - ' . $e->getMessage());
                }
                $error = 'Invalid username or password.';
            } else {
                // [SCHOOL-IDENTITY] Cross-check tenant.
                if ((int)$user['tenant_id'] !== (int)$resolvedSchool['tenant_id']) {
                    $error = 'Invalid username or password.';
                } else {
                    // [v2.3] Tenant status invariant.
                    $tenantRow = $db->fetchOne(
                        "SELECT id, status FROM tenants
                         WHERE id = ? AND deleted_at IS NULL",
                        [(int)$user['tenant_id']]
                    );
                    if (!$tenantRow || $tenantRow['status'] !== 'active') {
                        error_log('Tenant login rejected: tenant ' . (int)$user['tenant_id'] . ' is not active');
                        $error = 'Invalid username or password.';
                    } elseif (!empty($resolvedSchool['deleted_at'])) {
                        // [v2.3] School live invariant.
                        error_log('Tenant login rejected: resolved school ' . (int)$resolvedSchool['id'] . ' is soft-deleted');
                        $error = 'Invalid username or password.';
                    } else {
                        // ---------------------------------------------
                        // [v2.4] USER-TYPE VALIDATION
                        // ---------------------------------------------
                        $userType   = (string)($user['user_type'] ?? '');
                        $guardianId = isset($user['guardian_id']) ? (int)$user['guardian_id'] : 0;

                        $allowedTypes = ['platform_admin', 'tenant_admin', 'staff', 'guardian'];

                        if (!in_array($userType, $allowedTypes, true)) {
                            error_log('Tenant login rejected: user ' . (int)$user['id']
                                . ' has unknown user_type "' . $userType . '"');
                            $error = 'Invalid username or password.';
                        } elseif ($userType === 'guardian' && $guardianId <= 0) {
                            error_log('Tenant login rejected: guardian user ' . (int)$user['id']
                                . ' has no guardian_id');
                            $error = 'Invalid username or password.';
                        } elseif (
                            $userType === 'guardian'
                            && !tenant_login_guardian_is_live($db, (int)$user['tenant_id'], $guardianId)
                        ) {
                            error_log('Tenant login rejected: guardian row ' . $guardianId
                                . ' is not live for tenant ' . (int)$user['tenant_id']);
                            $error = 'Invalid username or password.';
                        } else {
                            // ---------------------------------------------
                            // [v2.4] ROLE SOURCE — skip for guardians
                            // ---------------------------------------------
                            if ($userType === 'guardian') {
                                $roleIds       = [];
                                $roleNames     = [];
                                $isSuperAdmin  = false;
                                $isTenantAdmin = false;
                            } else {
                                $roleRows = $db->fetchAll(
                                    "SELECT r.id AS role_id, r.role_name
                                     FROM platform_user_roles ur
                                     JOIN roles r ON r.id = ur.role_id
                                     WHERE ur.user_id = ?",
                                    [(int)$user['id']]
                                );

                                if (empty($roleRows)) {
                                    error_log('Tenant login rejected: user ' . (int)$user['id']
                                        . ' holds no roles');
                                    $error = 'Invalid username or password.';
                                } else {
                                    $roleIds   = [];
                                    $roleNames = [];
                                    foreach ($roleRows as $rr) {
                                        $roleIds[(int)$rr['role_id']] = true;
                                        $roleNames[] = (string)$rr['role_name'];
                                    }
                                    $isSuperAdmin  = isset($roleIds[2]);
                                    $isTenantAdmin = isset($roleIds[3]);
                                }
                            }

                            if ($error === '') {
                                // ---------------------------------------------
                                // [v2.5 ITEM-19] RECORD SUCCESS, RUN HEURISTIC
                                // ---------------------------------------------
                                // The successful attempt is written first,
                                // then the heuristic is run. The heuristic
                                // reads rows with created_at < NOW() (or
                                // the equivalent) and specifically counts
                                // prior successes from the same IP within
                                // the configured window; the row written
                                // here is the current login and does not
                                // change the verdict for this login.
                                try {
                                    $db->execute(
                                        "INSERT INTO login_attempts (uuid, user_id, username_attempted, ip_address, user_agent, outcome)
                                         VALUES (?, ?, ?, ?, ?, 'success')",
                                        [
                                            bin2hex(random_bytes(16)),
                                            (int)$user['id'],
                                            $username,
                                            $_SERVER['REMOTE_ADDR'] ?? '',
                                            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                                        ]
                                    );
                                } catch (Throwable $e) {
                                    error_log('login.php: failed to write login_attempts (success) - ' . $e->getMessage());
                                }

                                try {
                                    require_once $projectRoot . '/app/services/Auth/SuspiciousLoginService.php';
                                    $verdict = SuspiciousLoginService::check(
                                        (int)$user['id'],
                                        $username,
                                        $_SERVER['REMOTE_ADDR'] ?? '',
                                        $_SERVER['HTTP_USER_AGENT'] ?? null
                                    );
                                    tenant_login_write_audit(
                                        $db,
                                        (int)$resolvedSchool['tenant_id'],
                                        (int)$user['id'],
                                        'auth.login.suspicious_check',
                                        'platform_users',
                                        (int)$user['id'],
                                        [
                                            'suspicious' => (bool)$verdict['suspicious'],
                                            'score'      => (int)$verdict['score'],
                                            'reasons'    => $verdict['reasons'],
                                            'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
                                        ]
                                    );
                                } catch (Throwable $e) {
                                    error_log('login.php: SuspiciousLoginService::check failed - ' . $e->getMessage());
                                }

                                // ---------------------------------------------
                                // LOGIN SUCCESSFUL — WRITE SESSION
                                // ---------------------------------------------
                                $_SESSION['logged_in']      = true;
                                $_SESSION['user_id']        = (int)$user['id'];
                                $_SESSION['user_name']      = $user['first_name'] . ' ' . $user['last_name'];
                                $_SESSION['first_name']     = $user['first_name'];
                                $_SESSION['username']       = $user['username'];
                                $_SESSION['is_tenant_admin'] = $isTenantAdmin;
                                $_SESSION['is_super_admin']  = $isSuperAdmin;

                                // [v2.3] role list
                                $_SESSION['roles']      = array_keys($roleIds);
                                $_SESSION['role_names'] = $roleNames;

                                // [v2.4] population and linkage keys
                                // [v2.6 ITEM-26] staff_id is now actually populated
                                // because the SELECT reads it.
                                $_SESSION['user_type']   = $userType;
                                $_SESSION['guardian_id'] = $userType === 'guardian' ? $guardianId : null;
                                $_SESSION['staff_id']    = isset($user['staff_id'])
                                    ? (int)$user['staff_id'] : null;

                                // [SCHOOL-IDENTITY] school session keys
                                $_SESSION['tenant_id']   = (int)$resolvedSchool['tenant_id'];
                                $_SESSION['school_id']   = (int)$resolvedSchool['id'];
                                $_SESSION['school_uuid'] = $resolvedSchool['uuid'];
                                $_SESSION['school_name'] = $resolvedSchool['school_name'];
                                $_SESSION['school_logo'] = $resolvedSchool['logo_path'];

                                if (function_exists('session_login_regenerate')) {
                                    session_login_regenerate();
                                }

                                $db->execute(
                                    "UPDATE platform_users SET last_login = NOW() WHERE id = ?",
                                    [(int)$user['id']]
                                );

                                // [v2.4] three-way redirect
                                if ($userType === 'guardian') {
                                    header('Location: /platform/guardian/index.php');
                                } elseif ($isSuperAdmin) {
                                    header('Location: /platform/index.php');
                                } else {
                                    header('Location: /platform/tenant/dashboard.php');
                                }
                                exit;
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('Login error: ' . $e->getMessage());
            $error = 'Login error: ' . $e->getMessage();
        }
    }
}

$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php if (!empty($resolvedSchool['logo_path'])): ?>
        <link rel="icon" href="<?php echo htmlspecialchars($resolvedSchool['logo_path']); ?>">
    <?php endif; ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #2a2a4e 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: #fff;
            border-radius: 16px;
            padding: 40px;
            max-width: 420px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .login-container .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-container .logo .school-crest {
            max-height: 72px;
            max-width: 72px;
            margin: 0 auto 12px;
            display: block;
        }

        .login-container .logo h2 {
            font-weight: 700;
            color: #1a1a2e;
        }

        .login-container .logo h2 i {
            color: #4facfe;
        }

        .login-container .logo p {
            color: #6c757d;
            font-size: 14px;
            margin-top: 5px;
        }

        .login-container .form-label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
        }

        .login-container .form-control {
            border-radius: 10px;
            padding: 10px 14px;
            border: 1.5px solid #e9ecef;
            font-size: 14px;
        }

        .login-container .form-control:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 3px rgba(79, 172, 254, 0.1);
        }

        .login-container .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            padding: 10px;
            font-weight: 600;
            border-radius: 10px;
            width: 100%;
            color: #fff;
        }

        .login-container .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.4);
        }

        .login-container .alert {
            border-radius: 10px;
            border: none;
            font-size: 13px;
        }

        .login-container .footer-text {
            text-align: center;
            color: #6c757d;
            font-size: 13px;
            margin-top: 20px;
        }

        .login-container .input-group-text {
            background: #f8f9fa;
            border: 1.5px solid #e9ecef;
            border-right: none;
            border-radius: 10px 0 0 10px;
            color: #6c757d;
        }

        .login-container .input-group .form-control {
            border-radius: 0 10px 10px 0;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 24px 20px;
            }
        }
    </style>
</head>

<body>
    <div class="login-container">
        <div class="logo">
            <?php if (!empty($resolvedSchool['logo_path'])): ?>
                <img class="school-crest"
                    src="<?php echo htmlspecialchars($resolvedSchool['logo_path']); ?>"
                    alt="">
            <?php endif; ?>
            <h2><i class="fas fa-graduation-cap me-2"></i>Student 360</h2>
            <p><?php echo htmlspecialchars($resolvedSchool['school_name']); ?></p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="mb-3">
                <label class="form-label" for="username">Username or Email</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                    <input type="text" class="form-control" id="username" name="username"
                        placeholder="Enter your username or email" required autofocus>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password"
                        placeholder="Enter your password" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-sign-in-alt me-2"></i> Login
            </button>
        </form>

        <div class="footer-text">
            <p>&copy; <?php echo date('Y'); ?> Student 360. All rights reserved.</p>
        </div>
    </div>
</body>

</html>