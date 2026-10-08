<?php

/**
 * Provision Platform User - Create or manage the platform login
 * for a staff member.
 *
 * @package EduTrack
 * @subpackage Platform\Tenant\Staff
 * @version 1.0
 * @filepath public/platform/tenant/staff/provision-user.php
 *
 * v1.0 change (2026-10-05) [SWEEP]:
 *   Provision Platform User file of the tenant-surface sweep.
 *   Three changes:
 *     - A docblock was added. The file previously carried no
 *       @version, @package, or @filepath tag. The new docblock
 *       carries them.
 *     - The user-facing brand in $pageTitle changed from
 *       'EduTrack Platform' to 'Student 360 Platform'.
 *     - The inline <nav class="sidebar" id="sidebar"> block is
 *       removed and replaced by an include of
 *       app/views/partials/sidebar.php. This file's inline
 *       sidebar carried six items (Dashboard; Schools, Campuses;
 *       Staff, Students; Settings) and no Subscription,
 *       Notifications, or Logout nav link. The partial carries
 *       all nine. After the refactor, this page renders the
 *       Subscription, Notifications, and Logout items as well.
 *       The inline sidebar's Settings link pointed at
 *       /platform/tenant/settings.php; the partial's Settings
 *       link points at /platform/tenant/settings/index.php.
 *   Every other line of the file is byte-identical to the
 *   pre-sweep version. The @package tag is 'EduTrack'.
 */

// ERROR REPORTING
// ============================================
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// SESSION & AUTHENTICATION
// ============================================
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] != true) {
    header('Location: /platform/tenant/login.php');
    exit;
}

// ORGANIZATIONAL CONTEXT
$tenantId = $_SESSION['tenant_id'] ?? 0;
$schoolId = $_SESSION['school_id'] ?? 0;
$campusId = $_SESSION['campus_id'] ?? 0;
$userId = $_SESSION['user_id'] ?? 0;
$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userAvatar = substr($currentUser, 0, 1);
$isSuperAdmin = $_SESSION['is_super_admin'] ?? false;

if (!$tenantId) {
    $_SESSION['errors'] = ['No tenant context found.'];
    header('Location: /platform/tenants/select.php');
    exit;
}

// STAFF ID
$staffId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($staffId <= 0) {
    $_SESSION['errors'] = ['Invalid staff ID.'];
    header('Location: /platform/tenant/staff/index.php');
    exit;
}

$pageTitle = 'Provision Platform User - Student 360 Platform';
$currentPage = 'staff';

// DATABASE & CONFIGURATION
$projectRoot = dirname(__DIR__, 4);
require_once $projectRoot . '/config/config.php';
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';

$db = DatabaseHelper::getInstance();

// ============================================
// LOCAL HELPERS
// ============================================
if (!function_exists('h')) {
    function h($v)
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('writeAudit')) {
    function writeAudit($db, int $tenantId, int $userId, string $action, string $resourceType, ?int $resourceId, array $details): void
    {
        try {
            $db->insert(
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
            error_log('provision-user.php audit failed: ' . $e->getMessage());
        }
    }
}

// ============================================
// LOAD STAFF RECORD
// ============================================
$staff = $db->fetchOne(
    "SELECT s.*, p.first_name, p.middle_name, p.last_name, p.preferred_name,
            p.id AS person_id, p.email AS person_email,
            st.type_name AS staff_type_name,
            sc.category_name AS staff_category_name
     FROM staff s
     LEFT JOIN persons p ON s.person_id = p.id
     LEFT JOIN staff_types st ON s.staff_type_id = st.id
     LEFT JOIN staff_categories sc ON s.staff_category_id = sc.id
     WHERE s.id = ? AND s.tenant_id = ? AND s.deleted_at IS NULL",
    [$staffId, $tenantId]
);

if (!$staff) {
    $_SESSION['errors'] = ['Staff record not found.'];
    header('Location: /platform/tenant/staff/index.php');
    exit;
}

// ============================================
// LOAD LINKED PLATFORM USER (if any)
// ============================================
$platformUser = null;
if (!empty($staff['platform_user_id'])) {
    $platformUser = $db->fetchOne(
        "SELECT id, first_name, last_name, username, email, is_active, last_login, created_at
         FROM platform_users
         WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
        [(int)$staff['platform_user_id'], $tenantId]
    );
}

// ============================================
// TENANT + SCHOOL + SECURITY SETTINGS
// ============================================
$tenantName = '';
$tenant = $db->fetchOne("SELECT tenant_name FROM tenants WHERE id = ? AND deleted_at IS NULL", [$tenantId]);
if ($tenant) {
    $tenantName = $tenant['tenant_name'] ?? 'Tenant #' . $tenantId;
}

$schoolName = '';
if ($schoolId > 0) {
    $school = $db->fetchOne("SELECT school_name FROM schools WHERE id = ? AND deleted_at IS NULL", [$schoolId]);
    if ($school) {
        $schoolName = $school['school_name'] ?? '';
    }
}

$minPasswordLength = 8;
try {
    $secRow = $db->fetchOne(
        "SELECT min_password_length FROM tenant_security_settings WHERE tenant_id = ? AND deleted_at IS NULL LIMIT 1",
        [$tenantId]
    );
    if ($secRow && !empty($secRow['min_password_length'])) {
        $minPasswordLength = (int)$secRow['min_password_length'];
    }
} catch (Throwable $e) {
    // Table may not exist; keep the default.
}

// ============================================
// HANDLE POST
// ============================================
$errors = [];
$formData = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = $_POST;
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_link') {
            // -----------------------------------------
            // State A: create a new platform_users row
            // and link it to this staff member.
            // -----------------------------------------
            if ($platformUser) {
                throw new Exception('This staff member is already linked to a platform user.');
            }

            $username = trim((string)($_POST['username'] ?? ''));
            $email    = trim((string)($_POST['email'] ?? ''));
            $firstName = trim((string)($_POST['first_name'] ?? ''));
            $lastName  = trim((string)($_POST['last_name'] ?? ''));
            $password  = (string)($_POST['password'] ?? '');
            $password2 = (string)($_POST['password_confirm'] ?? '');

            if ($username === '') {
                $errors[] = 'Username is required.';
            }
            if ($email === '') {
                $errors[] = 'Email is required.';
            }
            if ($firstName === '') {
                $errors[] = 'First name is required.';
            }
            if ($lastName === '') {
                $errors[] = 'Last name is required.';
            }
            if ($password === '') {
                $errors[] = 'Password is required.';
            }
            if ($password !== $password2) {
                $errors[] = 'Passwords do not match.';
            }
            if ($password !== '' && strlen($password) < $minPasswordLength) {
                $errors[] = 'Password must be at least ' . $minPasswordLength . ' characters.';
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }
            if ($username !== '' && !preg_match('/^[A-Za-z0-9._\-]{3,60}$/', $username)) {
                $errors[] = 'Username must be 3-60 characters, letters, digits, dot, underscore or hyphen.';
            }

            if (empty($errors)) {
                $db->beginTransaction();

                try {
                    // Check uniqueness inside the transaction with row locks.
                    $existingUsername = $db->fetchOne(
                        "SELECT id FROM platform_users
                         WHERE username = ? AND deleted_at IS NULL
                         FOR UPDATE",
                        [$username]
                    );
                    if ($existingUsername) {
                        throw new Exception('That username is already taken.');
                    }

                    $existingEmail = $db->fetchOne(
                        "SELECT id FROM platform_users
                         WHERE email = ? AND deleted_at IS NULL
                         FOR UPDATE",
                        [$email]
                    );
                    if ($existingEmail) {
                        throw new Exception('That email address is already registered.');
                    }

                    $uuid = bin2hex(random_bytes(16));
                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    $newId = $db->insert(
                        "INSERT INTO platform_users
                            (uuid, tenant_id, user_type, staff_id, guardian_id,
                             username, email, password_hash,
                             first_name, last_name,
                             is_active, created_at, updated_at)
                         VALUES (?, ?, 'staff', ?, NULL, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
                        [
                            $uuid,
                            $tenantId,
                            $staffId,
                            $username,
                            $email,
                            $hash,
                            $firstName,
                            $lastName,
                        ]
                    );

                    $newId = (int)$newId;
                    if ($newId <= 0) {
                        throw new Exception('Could not determine the new user id.');
                    }

                    $db->execute(
                        "UPDATE staff SET platform_user_id = ?, updated_at = NOW()
                         WHERE id = ? AND tenant_id = ?",
                        [$newId, $staffId, $tenantId]
                    );

                    $db->commit();

                    writeAudit(
                        $db,
                        $tenantId,
                        (int)$userId,
                        'staff.user.provisioned',
                        'staff',
                        $staffId,
                        [
                            'staff_id'        => $staffId,
                            'platform_user_id' => $newId,
                            'username'        => $username,
                            'email'           => $email,
                        ]
                    );

                    $_SESSION['success'] = 'Platform user created and linked to this staff member.';
                    header('Location: /platform/tenant/staff/view.php?id=' . $staffId);
                    exit;
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $e;
                }
            }
        } elseif ($action === 'reset_password') {
            // -----------------------------------------
            // State B: reset the linked platform user's
            // password.
            // -----------------------------------------
            if (!$platformUser) {
                throw new Exception('This staff member has no linked platform user.');
            }

            $password  = (string)($_POST['password'] ?? '');
            $password2 = (string)($_POST['password_confirm'] ?? '');

            if ($password === '') {
                $errors[] = 'New password is required.';
            }
            if ($password !== $password2) {
                $errors[] = 'Passwords do not match.';
            }
            if ($password !== '' && strlen($password) < $minPasswordLength) {
                $errors[] = 'Password must be at least ' . $minPasswordLength . ' characters.';
            }

            if (empty($errors)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $db->execute(
                    "UPDATE platform_users
                     SET password_hash = ?, password_must_change = 1, updated_at = NOW()
                     WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL",
                    [$hash, (int)$platformUser['id'], $tenantId]
                );

                writeAudit(
                    $db,
                    $tenantId,
                    (int)$userId,
                    'staff.user.password_reset',
                    'staff',
                    $staffId,
                    [
                        'staff_id'        => $staffId,
                        'platform_user_id' => (int)$platformUser['id'],
                        'username'        => $platformUser['username'],
                    ]
                );

                $_SESSION['success'] = 'Password reset. The user will be asked to change it on next login.';
                header('Location: /platform/tenant/staff/provision-user.php?id=' . $staffId);
                exit;
            }
        } else {
            throw new Exception('Unknown action.');
        }
    } catch (Throwable $e) {
        if (empty($errors)) {
            $errors[] = $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        $_SESSION['form_data'] = $_POST;
        header('Location: /platform/tenant/staff/provision-user.php?id=' . $staffId);
        exit;
    }
}

if (isset($_SESSION['errors'])) {
    $errors = $_SESSION['errors'];
    unset($_SESSION['errors']);
}
if (isset($_SESSION['form_data'])) {
    $formData = $_SESSION['form_data'];
    unset($_SESSION['form_data']);
}
if (isset($_SESSION['success'])) {
    $successMessage = $_SESSION['success'];
    unset($_SESSION['success']);
}

$useForm = !empty($formData);

function val($key, $default = '')
{
    global $formData, $useForm;
    if ($useForm) return htmlspecialchars((string)($formData[$key] ?? $default));
    return htmlspecialchars((string)$default);
}

$prefillFirst = $staff['first_name'] ?? '';
$prefillLast  = $staff['last_name'] ?? '';
$prefillEmail = $staff['person_email'] ?? ($staff['email'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo h($pageTitle); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            overflow-x: hidden !important;
            width: 100%;
            max-width: 100%;
            background: #f0f2f5;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            line-height: 1.6;
            color: #1a1a2e;
        }

        .container-fluid {
            padding: 0;
            margin: 0;
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }

        .row {
            margin: 0;
            width: 100%;
            max-width: 100%;
        }

        [class*="col-"] {
            padding-left: 12px;
            padding-right: 12px;
        }

        .sidebar-toggle {
            display: none;
            position: fixed;
            top: 14px;
            left: 14px;
            z-index: 1001;
            background: #1a1a2e;
            color: #fff;
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 22px;
            cursor: pointer;
        }

        .sidebar {
            min-height: 100vh;
            background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
            color: #fff;
            position: fixed;
            width: 260px;
            left: 0;
            top: 0;
            z-index: 1000;
            transition: transform 0.3s ease;
            overflow-y: auto;
            padding: 0;
        }

        .sidebar .sidebar-header {
            padding: 25px 24px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .sidebar .sidebar-header h4 {
            font-weight: 700;
            font-size: 20px;
            margin: 0;
            color: #4facfe;
        }

        .sidebar .sidebar-header small {
            color: rgba(255, 255, 255, 0.4);
            font-size: 12px;
        }

        .sidebar .nav {
            padding: 16px 12px;
        }

        .sidebar .nav-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255, 255, 255, 0.3);
            padding: 0 12px 8px;
            font-weight: 600;
        }

        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.6);
            padding: 10px 16px;
            border-radius: 10px;
            margin: 2px 0;
            transition: all 0.3s;
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: center;
            text-decoration: none;
        }

        .sidebar .nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }

        .sidebar .nav-link.active {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: #fff;
        }

        .sidebar .nav-link i {
            width: 22px;
            text-align: center;
            margin-right: 12px;
            font-size: 15px;
        }

        .sidebar .sidebar-footer {
            position: absolute;
            bottom: 0;
            width: 100%;
            padding: 20px 24px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(0, 0, 0, 0.2);
        }

        .sidebar .sidebar-footer .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar .sidebar-footer .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #fff;
        }

        .main-content {
            margin-left: 260px;
            width: calc(100% - 260px);
            padding: 20px;
            max-width: 100%;
            overflow-x: hidden;
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 0 24px 0;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-bar .page-title h1 {
            font-size: 28px;
            font-weight: 800;
            color: #1a1a2e;
            margin: 0;
        }

        .top-bar .page-title h1 i {
            color: #4facfe;
        }

        .top-bar .page-title p {
            color: #6c757d;
            margin: 0;
            font-size: 14px;
        }

        .top-bar .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            color: #fff;
            border-radius: 10px;
            padding: 10px 24px;
            font-weight: 600;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(79, 172, 254, 0.4);
            color: #fff;
        }

        .btn-outline-secondary {
            background: transparent;
            border: 2px solid #e9ecef;
            color: #6c757d;
            border-radius: 10px;
            padding: 10px 24px;
            font-weight: 500;
        }

        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #ced4da;
        }

        .btn-outline-primary {
            background: transparent;
            border: 2px solid #4facfe;
            color: #4facfe;
            border-radius: 10px;
            padding: 8px 20px;
            font-weight: 500;
        }

        .btn-outline-primary:hover {
            background: #4facfe;
            color: #fff;
        }

        .btn-success {
            background: #28a745;
            border: none;
            color: #fff;
            border-radius: 10px;
            padding: 10px 24px;
            font-weight: 600;
        }

        .btn-success:hover {
            background: #218838;
            color: #fff;
        }

        .logout-btn {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #fff;
            border-radius: 8px;
            padding: 6px 12px;
            cursor: pointer;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .tenant-banner {
            background: #fff;
            border-radius: 14px;
            padding: 16px 24px;
            margin-bottom: 20px;
            border: 2px solid #4facfe;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .tenant-banner .tenant-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .tenant-banner .tenant-info i {
            font-size: 24px;
            color: #4facfe;
        }

        .tenant-banner .tenant-info .tenant-name {
            font-weight: 600;
            font-size: 16px;
            color: #1a1a2e;
        }

        .tenant-banner .tenant-badge {
            background: #e3f0ff;
            color: #0d6efd;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .card-custom {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
            width: 100%;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
        }

        .card-custom .card-header-custom {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .card-custom .card-header-custom h6 {
            font-weight: 600;
            margin: 0;
            font-size: 15px;
            color: #1a1a2e;
        }

        .card-custom .card-body-custom {
            padding: 20px 24px;
        }

        .form-label {
            font-weight: 500;
            font-size: 13px;
            color: #1a1a2e;
            margin-bottom: 4px;
            display: block;
        }

        .form-label .required {
            color: #dc3545;
            margin-left: 2px;
        }

        .form-control,
        .form-select {
            border-radius: 10px;
            padding: 10px 14px;
            border: 2px solid #e9ecef;
            font-size: 13px;
            width: 100%;
            display: block;
            background: #fff;
            color: #1a1a2e;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
            height: 44px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #4facfe;
            box-shadow: 0 0 0 4px rgba(79, 172, 254, 0.1);
            outline: none;
        }

        .form-text {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }

        .alert-pro {
            border-radius: 14px;
            border: none;
            padding: 18px 20px 18px 22px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
        }

        .alert-pro::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            width: 5px;
        }

        .alert-pro .alert-pro-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }

        .alert-pro .alert-pro-content {
            flex: 1;
            padding-top: 2px;
        }

        .alert-pro .alert-pro-title {
            font-weight: 700;
            font-size: 15px;
            margin-bottom: 4px;
        }

        .alert-pro .alert-pro-list {
            margin: 0;
            padding-left: 20px;
            font-size: 13px;
            line-height: 1.7;
        }

        .alert-pro .alert-pro-close {
            background: transparent;
            border: none;
            color: inherit;
            opacity: 0.5;
            font-size: 18px;
            cursor: pointer;
            padding: 0;
            width: 28px;
            height: 28px;
            border-radius: 6px;
        }

        .alert-pro .alert-pro-close:hover {
            opacity: 1;
            background: rgba(0, 0, 0, 0.06);
        }

        .alert-pro.alert-pro-error {
            background: linear-gradient(135deg, #fff5f5 0%, #ffeaea 100%);
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .alert-pro.alert-pro-error::before {
            background: linear-gradient(180deg, #ef4444, #dc2626);
        }

        .alert-pro.alert-pro-error .alert-pro-icon {
            background: rgba(239, 68, 68, 0.12);
            color: #dc2626;
        }

        .alert-pro.alert-pro-success {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .alert-pro.alert-pro-success::before {
            background: linear-gradient(180deg, #22c55e, #16a34a);
        }

        .alert-pro.alert-pro-success .alert-pro-icon {
            background: rgba(34, 197, 94, 0.15);
            color: #16a34a;
        }

        .staff-hero {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border-radius: 20px;
            padding: 24px 30px;
            color: #fff;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            box-shadow: 0 12px 40px rgba(79, 172, 254, 0.25);
            max-width: 900px;
            margin-left: auto;
            margin-right: auto;
        }

        .staff-hero .avatar-lg {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 700;
            color: #fff;
            flex-shrink: 0;
            border: 3px solid rgba(255, 255, 255, 0.4);
        }

        .staff-hero .hero-info {
            flex: 1;
            min-width: 220px;
        }

        .staff-hero .hero-info h2 {
            font-size: 22px;
            font-weight: 800;
            margin: 0 0 4px 0;
            letter-spacing: -0.4px;
        }

        .staff-hero .hero-info .staff-num {
            font-size: 13px;
            opacity: 0.9;
        }

        .kv-row {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) minmax(220px, 2fr);
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #f0f2f5;
            align-items: center;
        }

        .kv-row:last-child {
            border-bottom: none;
        }

        .kv-row .kv-lbl {
            font-weight: 600;
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .kv-row .kv-val {
            font-size: 14px;
            color: #1a1a2e;
            font-weight: 500;
        }

        .kv-row .kv-val.rc-muted {
            color: #adb5bd;
            font-style: italic;
        }

        .badge-soft {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .badge-soft.green {
            background: #dcfce7;
            color: #16a34a;
        }

        .badge-soft.red {
            background: #fee2e2;
            color: #dc2626;
        }

        .badge-soft.blue {
            background: #e3f0ff;
            color: #0d6efd;
        }

        .badge-soft.gray {
            background: #f3f4f6;
            color: #4b5563;
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 72px;
                overflow: hidden;
            }

            .sidebar .sidebar-header h4 {
                font-size: 0;
            }

            .sidebar .nav-link span {
                display: none;
            }

            .sidebar .nav-link i {
                margin-right: 0;
            }

            .sidebar .nav-link {
                justify-content: center;
            }

            .main-content {
                margin-left: 72px;
                width: calc(100% - 72px);
            }

            .sidebar-toggle {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .sidebar-toggle {
                display: inline;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 260px;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .sidebar .nav-link span {
                display: inline;
            }

            .sidebar .nav-link i {
                margin-right: 12px;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 16px;
                padding-top: 70px;
            }

            .kv-row {
                grid-template-columns: 1fr;
                gap: 4px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <button class="sidebar-toggle" id="sidebarToggle" onclick="toggleSidebar()" aria-label="Toggle Sidebar"><i class="fas fa-bars"></i></button>

            <!-- Sidebar (includes app/views/partials/sidebar.php) -->
            <?php include $projectRoot . '/app/views/partials/sidebar.php'; ?>

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-user-plus me-2"></i>Provision Platform User</h1>
                        <p>Create or manage the login for <strong><?php echo h(trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''))); ?></strong></p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/tenant/staff/view.php?id=<?php echo $staffId; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-eye me-2"></i>View Profile
                        </a>
                        <a href="/platform/tenant/staff/edit.php?id=<?php echo $staffId; ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-edit me-2"></i>Edit Staff
                        </a>
                        <a href="/platform/tenant/staff/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Staff
                        </a>
                    </div>
                </div>

                <div class="staff-hero">
                    <div class="avatar-lg">
                        <?php
                        $initials = strtoupper(substr($staff['first_name'] ?? '?', 0, 1) . substr($staff['last_name'] ?? '', 0, 1));
                        echo h($initials);
                        ?>
                    </div>
                    <div class="hero-info">
                        <h2><?php echo h(trim(($staff['first_name'] ?? '') . ' ' . ($staff['middle_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''))); ?></h2>
                        <div class="staff-num">
                            <i class="fas fa-id-badge me-1"></i> <?php echo h($staff['staff_number'] ?? '—'); ?>
                            <?php if (!empty($staff['staff_type_name'])): ?>
                                &nbsp;·&nbsp;<?php echo h($staff['staff_type_name']); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert-pro alert-pro-error" id="serverErrorBox">
                        <div class="alert-pro-icon"><i class="fas fa-times-circle"></i></div>
                        <div class="alert-pro-content">
                            <div class="alert-pro-title">Could not complete the request</div>
                            <ul class="alert-pro-list">
                                <?php foreach ($errors as $error): ?><li><?php echo h($error); ?></li><?php endforeach; ?>
                            </ul>
                        </div>
                        <button type="button" class="alert-pro-close" onclick="document.getElementById('serverErrorBox').remove()" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($successMessage)): ?>
                    <div class="alert-pro alert-pro-success" id="serverSuccessBox">
                        <div class="alert-pro-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="alert-pro-content">
                            <div class="alert-pro-title">Done</div>
                            <div style="font-size: 13px;"><?php echo h($successMessage); ?></div>
                        </div>
                        <button type="button" class="alert-pro-close" onclick="document.getElementById('serverSuccessBox').remove()" aria-label="Close"><i class="fas fa-times"></i></button>
                    </div>
                <?php endif; ?>

                <?php if ($platformUser): ?>
                    <!-- STATE B: MANAGE -->

                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-user-check me-2 text-primary"></i>Linked Platform User</h6>
                            <span class="badge-soft <?php echo !empty($platformUser['is_active']) ? 'green' : 'red'; ?>">
                                <?php echo !empty($platformUser['is_active']) ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>
                        <div class="card-body-custom">
                            <div class="kv-row">
                                <div class="kv-lbl">Username</div>
                                <div class="kv-val"><?php echo h($platformUser['username']); ?></div>
                            </div>
                            <div class="kv-row">
                                <div class="kv-lbl">Display Name</div>
                                <div class="kv-val"><?php echo h(trim(($platformUser['first_name'] ?? '') . ' ' . ($platformUser['last_name'] ?? ''))); ?></div>
                            </div>
                            <div class="kv-row">
                                <div class="kv-lbl">Email</div>
                                <div class="kv-val"><?php echo $platformUser['email'] !== '' ? h($platformUser['email']) : '<span class="rc-muted">—</span>'; ?></div>
                            </div>
                            <div class="kv-row">
                                <div class="kv-lbl">Last Login</div>
                                <div class="kv-val"><?php echo !empty($platformUser['last_login']) ? h($platformUser['last_login']) : '<span class="rc-muted">Never</span>'; ?></div>
                            </div>
                            <div class="kv-row">
                                <div class="kv-lbl">Created At</div>
                                <div class="kv-val"><?php echo h($platformUser['created_at'] ?? '—'); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-key me-2 text-primary"></i>Reset Password</h6>
                        </div>
                        <div class="card-body-custom">
                            <div class="form-text mb-3">
                                The user will be prompted to change this password on their next login.
                            </div>
                            <form method="POST" action="/platform/tenant/staff/provision-user.php?id=<?php echo $staffId; ?>" autocomplete="off">
                                <input type="hidden" name="action" value="reset_password">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="reset_password">New Password <span class="required">*</span></label>
                                        <div style="display:flex;gap:6px;align-items:center;">
                                            <input type="password" class="form-control" id="reset_password" name="password" required minlength="<?php echo (int)$minPasswordLength; ?>" autocomplete="new-password">
                                            <button type="button" class="btn btn-outline-secondary btn-sm js-toggle-pw" data-target="reset_password" title="Show / hide" aria-label="Show or hide password"><i class="fas fa-eye"></i></button>
                                        </div>
                                        <div class="form-text">Minimum <?php echo (int)$minPasswordLength; ?> characters.</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="reset_password_confirm">Confirm Password <span class="required">*</span></label>
                                        <div style="display:flex;gap:6px;align-items:center;">
                                            <input type="password" class="form-control" id="reset_password_confirm" name="password_confirm" required minlength="<?php echo (int)$minPasswordLength; ?>" autocomplete="new-password">
                                            <button type="button" class="btn btn-outline-secondary btn-sm js-toggle-pw" data-target="reset_password_confirm" title="Show / hide" aria-label="Show or hide password"><i class="fas fa-eye"></i></button>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-key me-2"></i>Reset Password</button>
                                    <a href="/platform/tenant/staff/edit.php?id=<?php echo $staffId; ?>" class="btn btn-outline-secondary">
                                        <i class="fas fa-unlink me-2"></i>Unlink via Edit Page
                                    </a>
                                </div>
                                <div class="form-text mt-2">
                                    To unlink this staff member from the platform user, open the Edit Staff page and clear the "Platform User" field.
                                </div>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- STATE A: CREATE -->

                    <div class="card-custom">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-user-plus me-2 text-primary"></i>Create Platform User</h6>
                            <span class="badge-soft gray">No user linked yet</span>
                        </div>
                        <div class="card-body-custom">
                            <div class="form-text mb-3">
                                This creates a new <code>platform_users</code> row with <code>user_type = 'staff'</code>, links it to this staff member, and enables the login. The default values are pre-filled from the staff record; change them if you need to.
                            </div>
                            <form method="POST" action="/platform/tenant/staff/provision-user.php?id=<?php echo $staffId; ?>" autocomplete="off">
                                <input type="hidden" name="action" value="create_link">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="first_name">First Name <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo $useForm ? val('first_name') : h($prefillFirst); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="last_name">Last Name <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo $useForm ? val('last_name') : h($prefillLast); ?>" required>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="username">Username <span class="required">*</span></label>
                                        <input type="text" class="form-control" id="username" name="username" value="<?php echo val('username'); ?>" required minlength="3" maxlength="60" pattern="[A-Za-z0-9._\-]{3,60}">
                                        <div class="form-text">3-60 characters; letters, digits, dot, underscore or hyphen.</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="email">Email <span class="required">*</span></label>
                                        <input type="email" class="form-control" id="email" name="email" value="<?php echo $useForm ? val('email') : h($prefillEmail); ?>" required>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="password">Password <span class="required">*</span></label>
                                        <div style="display:flex;gap:6px;align-items:center;">
                                            <input type="password" class="form-control" id="password" name="password" required minlength="<?php echo (int)$minPasswordLength; ?>" autocomplete="new-password">
                                            <button type="button" class="btn btn-outline-secondary btn-sm js-toggle-pw" data-target="password" title="Show / hide" aria-label="Show or hide password"><i class="fas fa-eye"></i></button>
                                        </div>
                                        <div class="form-text">Minimum <?php echo (int)$minPasswordLength; ?> characters.</div>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="password_confirm">Confirm Password <span class="required">*</span></label>
                                        <div style="display:flex;gap:6px;align-items:center;">
                                            <input type="password" class="form-control" id="password_confirm" name="password_confirm" required minlength="<?php echo (int)$minPasswordLength; ?>" autocomplete="new-password">
                                            <button type="button" class="btn btn-outline-secondary btn-sm js-toggle-pw" data-target="password_confirm" title="Show / hide" aria-label="Show or hide password"><i class="fas fa-eye"></i></button>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus me-2"></i>Create and Link</button>
                                    <a href="/platform/tenant/staff/view.php?id=<?php echo $staffId; ?>" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-2"></i>Cancel
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
        }
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.getElementById('sidebarToggle');
            if (window.innerWidth <= 768 && sidebar.classList.contains('open') && !sidebar.contains(event.target) && !toggle.contains(event.target)) {
                sidebar.classList.remove('open');
            }
        });

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '/platform/tenant/logout.php';
            }
        }

        // Password eye toggle — same pattern as Email tab (item 13).
        document.querySelectorAll('.js-toggle-pw').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.getAttribute('data-target');
                var el = document.getElementById(id);
                if (!el) return;
                var icon = this.querySelector('i');
                if (el.type === 'password') {
                    el.type = 'text';
                    if (icon) {
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    }
                } else {
                    el.type = 'password';
                    if (icon) {
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                }
            });
        });
    </script>
</body>

</html>