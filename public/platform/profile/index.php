<?php

/**
 * Profile Page - View and manage user profile
 *
 * @package EduTrack
 * @subpackage Platform\Profile
 * @version 1.0
 * @filepath public/platform/profile/index.php
 *
 * v1.0 change (2026-10-07) [SWEEP X-1 + SIDEBAR]:
 *   Platform-profile sweep, X-1 in full, plus sidebar reconciliation.
 *
 *   X-1: the file carried no @version and no @subpackage tag. This
 *   sweep adds both, in the shape every other file on the record
 *   uses, and renames one visible "EduTrack" string to "Student 360":
 *     - The sidebar brand heading <h4>...EduTrack</h4>. That heading
 *       is superseded by the sidebar reconciliation below, which
 *       replaces the entire inline sidebar with the shared partial.
 *
 *   Sidebar: the inline sidebar this page carried — 9 items across
 *   4 groups, with a broken Dashboard link to
 *   /platform/dashboard/index.php — is replaced by a single
 *   require_once of the new shared partial at
 *   app/views/partials/platform-sidebar.php v1.0. That partial
 *   carries the canonical platform-root sidebar shape: 11 items
 *   across 4 groups (Main, Institution, Management, System), folder
 *   paths for Tenants and Settings, no Register Tenant item, plus
 *   the mobile toggle, the sidebar footer, and the toggleSidebar()
 *   JS.
 *
 *   $currentPage is set to '' (empty string), so no canonical
 *   sidebar item is marked active. The Profile page is not one of
 *   the canonical sidebar items. $pendingApprovals = 0.
 *
 *   The inline toggleSidebar() function that this page carried in
 *   its own <script> block is removed, because the partial now
 *   provides it. The page's click-outside handler and resize
 *   handler are kept — they are page-scoped and do not conflict
 *   with the partial's toggle.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
 *
 *   Every other line of the file is byte-identical to the version
 *   that was on disk before this sweep.
 */

// ============================================================
// LOAD HELPERS
// ============================================================
$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/app/helpers/SessionHelper.php';
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
require_once $projectRoot . '/app/helpers/TenantHelper.php';
require_once $projectRoot . '/app/views/partials/platform-sidebar.php';

// ============================================================
// SESSION & AUTHENTICATION
// ============================================================
SessionHelper::start();

// Redirect to login if not authenticated
if (!SessionHelper::isPlatformLoggedIn()) {
    header('Location: /platform/login.php');
    exit;
}

// Get user data
$user = SessionHelper::getUser();
$userId = $user['id'] ?? null;
$tenantId = SessionHelper::getTenantId();

if (!$userId) {
    header('Location: /platform/logout.php');
    exit;
}

$db = DatabaseHelper::getInstance();

// ============================================================
// GET USER DETAILS FROM DATABASE
// ============================================================
$userData = $db->fetchOne(
    "SELECT u.*, t.tenant_name 
     FROM platform_users u
     LEFT JOIN tenants t ON u.tenant_id = t.id
     WHERE u.id = ? AND u.deleted_at IS NULL",
    [$userId]
);

if (!$userData) {
    header('Location: /platform/logout.php');
    exit;
}

// ============================================================
// GET 2FA STATUS
// ============================================================
require_once $projectRoot . '/app/services/TwoFAService.php';
$twofaStatus = TwoFAService::getStatus($userId);

// ============================================================
// HANDLE PROFILE UPDATE
// ============================================================
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $firstName = trim($_POST['first_name'] ?? '');
        $lastName = trim($_POST['last_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $timezone = $_POST['timezone'] ?? 'UTC';
        $language = $_POST['language'] ?? 'en';

        // Validate
        if (empty($firstName)) {
            $error = 'First name is required.';
        } else {
            try {
                $db->execute(
                    "UPDATE platform_users 
                     SET first_name = ?, last_name = ?, phone = ?, timezone = ?, language = ?, updated_at = NOW()
                     WHERE id = ?",
                    [$firstName, $lastName, $phone, $timezone, $language, $userId]
                );

                // Update session
                $_SESSION['user']['first_name'] = $firstName;
                $_SESSION['user']['last_name'] = $lastName;
                $_SESSION['user']['phone'] = $phone;
                $_SESSION['first_name'] = $firstName;
                $_SESSION['last_name'] = $lastName;

                $success = 'Profile updated successfully!';

                // Refresh user data
                $userData = $db->fetchOne(
                    "SELECT u.*, t.tenant_name 
                     FROM platform_users u
                     LEFT JOIN tenants t ON u.tenant_id = t.id
                     WHERE u.id = ? AND u.deleted_at IS NULL",
                    [$userId]
                );
            } catch (Exception $e) {
                $error = 'Error updating profile: ' . $e->getMessage();
            }
        }
    }
}

// ============================================================
// GET USER ROLES
// ============================================================
$roles = $db->fetchAll(
    "SELECT r.role_name, r.role_code, ur.school_id, ur.campus_id, ur.class_id
     FROM platform_user_roles ur
     JOIN platform_roles r ON ur.role_id = r.id
     WHERE ur.user_id = ? AND ur.is_active = 1",
    [$userId]
);

// ============================================================
// PAGE RENDER
// ============================================================
$tenantName = $userData['tenant_name'] ?? 'Unknown Tenant';
$pageTitle = 'Profile - ' . htmlspecialchars($tenantName);
$currentPage = '';
$pendingApprovals = 0;
$displayName = ($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? '');
$avatar = SessionHelper::getAvatar();

// Timezones
$timezones = [
    'UTC' => 'UTC',
    'Africa/Accra' => 'Africa/Accra (GMT+0)',
    'Africa/Lagos' => 'Africa/Lagos (GMT+1)',
    'Africa/Johannesburg' => 'Africa/Johannesburg (GMT+2)',
    'Africa/Nairobi' => 'Africa/Nairobi (GMT+3)',
    'Europe/London' => 'Europe/London (GMT+0)',
    'Europe/Paris' => 'Europe/Paris (GMT+1)',
    'America/New_York' => 'America/New_York (GMT-5)',
    'America/Chicago' => 'America/Chicago (GMT-6)',
    'America/Denver' => 'America/Denver (GMT-7)',
    'America/Los_Angeles' => 'America/Los_Angeles (GMT-8)',
    'Asia/Dubai' => 'Asia/Dubai (GMT+4)',
    'Asia/Singapore' => 'Asia/Singapore (GMT+8)',
];

// Languages
$languages = [
    'en' => 'English',
    'fr' => 'French',
    'es' => 'Spanish',
    'pt' => 'Portuguese',
    'ar' => 'Arabic',
    'zh' => 'Chinese',
    'hi' => 'Hindi',
    'sw' => 'Swahili',
    'ha' => 'Hausa',
    'yo' => 'Yoruba',
    'ig' => 'Igbo',
    'tw' => 'Twi',
    'ga' => 'Ga',
    'ee' => 'Ewe',
];
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
    <style>
        /* ================================================ */
        /* GLOBAL RESET */
        /* ================================================ */
        * {
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

        /* ================================================ */
        /* SIDEBAR */
        /* ================================================ */
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
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.2);
        }

        .sidebar-toggle:hover {
            background: #2a2a4e;
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
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
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
        }

        .sidebar .sidebar-header h4 i {
            color: #4facfe;
        }

        .sidebar .sidebar-header small {
            color: rgba(255, 255, 255, 0.4);
            font-size: 12px;
        }

        .sidebar .sidebar-header .tenant-badge {
            background: rgba(255, 255, 255, 0.1);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            color: #4facfe;
            display: inline-block;
            margin-top: 4px;
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
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.3);
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
            font-size: 16px;
            color: #fff;
            flex-shrink: 0;
        }

        .sidebar .sidebar-footer .user-name {
            font-weight: 600;
            font-size: 14px;
        }

        .sidebar .sidebar-footer .user-role {
            font-size: 11px;
            color: rgba(255, 255, 255, 0.4);
        }

        .sidebar .sidebar-footer .logout-btn {
            color: rgba(255, 255, 255, 0.4);
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            font-size: 14px;
            width: 100%;
            text-align: left;
            padding: 10px 16px;
            border-radius: 10px;
            transition: all 0.3s;
        }

        .sidebar .sidebar-footer .logout-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }

        .sidebar .sidebar-footer .logout-btn i {
            margin-right: 12px;
            width: 22px;
            text-align: center;
        }

        /* ================================================ */
        /* MAIN CONTENT */
        /* ================================================ */
        .main-content {
            margin-left: 260px;
            padding: 24px 32px 40px;
            background: #f0f2f5;
            min-height: 100vh;
            width: calc(100% - 260px);
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
            letter-spacing: -0.5px;
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

        .top-bar .header-actions .btn {
            border-radius: 12px;
            padding: 8px 20px;
            font-weight: 500;
            font-size: 13px;
            transition: all 0.3s;
        }

        /* ================================================ */
        /* CARDS */
        /* ================================================ */
        .card-custom {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            margin-bottom: 24px;
            overflow: hidden;
            width: 100%;
        }

        .card-custom .card-header-custom {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f2f5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: transparent;
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

        /* ================================================ */
        /* FORM */
        /* ================================================ */
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
            max-width: 100%;
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

        .btn-primary {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            border: none;
            color: #fff;
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
        }

        .btn-outline-secondary:hover {
            background: #f8f9fa;
            border-color: #ced4da;
        }

        .btn-success {
            background: #28a745;
            border: none;
            color: #fff;
        }

        .btn-success:hover {
            background: #1e7e34;
            color: #fff;
        }

        .btn-danger {
            background: #dc3545;
            border: none;
            color: #fff;
        }

        .btn-danger:hover {
            background: #bd2130;
            color: #fff;
        }

        .btn-outline-success {
            background: transparent;
            border: 2px solid #28a745;
            color: #28a745;
        }

        .btn-outline-success:hover {
            background: #28a745;
            color: #fff;
        }

        /* ================================================ */
        /* PROFILE AVATAR */
        /* ================================================ */
        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            font-weight: 700;
            color: #fff;
            margin: 0 auto 16px;
            flex-shrink: 0;
        }

        .role-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            margin: 2px 4px;
        }

        .role-badge.super-admin {
            background: #d4edda;
            color: #155724;
        }

        .role-badge.tenant-admin {
            background: #cce5ff;
            color: #004085;
        }

        .role-badge.school-admin {
            background: #fff3cd;
            color: #856404;
        }

        .role-badge.campus-admin {
            background: #d1ecf1;
            color: #0c5460;
        }

        .role-badge.teacher {
            background: #f8d7da;
            color: #721c24;
        }

        .role-badge.student {
            background: #e2f0d9;
            color: #1e7e34;
        }

        .role-badge.parent {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .role-badge.staff {
            background: #fff0d9;
            color: #856404;
        }

        .role-badge.default {
            background: #e9ecef;
            color: #6c757d;
        }

        .tenant-context-banner {
            background: #e7f3ff;
            border-radius: 12px;
            padding: 12px 20px;
            margin-bottom: 20px;
            border-left: 4px solid #4facfe;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 13px;
            color: #1a1a2e;
        }

        .tenant-context-banner i {
            color: #4facfe;
            font-size: 18px;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-badge.active {
            background: #d4edda;
            color: #155724;
        }

        .status-badge.inactive {
            background: #e9ecef;
            color: #6c757d;
        }

        .status-badge.locked {
            background: #f8d7da;
            color: #721c24;
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 72px;
                overflow: hidden;
            }

            .sidebar .sidebar-header h4 {
                font-size: 0;
            }

            .sidebar .sidebar-header h4 i {
                font-size: 24px;
            }

            .sidebar .sidebar-header small {
                display: none;
            }

            .sidebar .sidebar-header .tenant-badge {
                display: none;
            }

            .sidebar .nav-link span {
                display: none;
            }

            .sidebar .nav-link i {
                margin-right: 0;
                font-size: 18px;
            }

            .sidebar .nav-link {
                text-align: center;
                padding: 12px;
                justify-content: center;
            }

            .sidebar .nav-label {
                display: none;
            }

            .sidebar .sidebar-footer .user-info span {
                display: none;
            }

            .sidebar .sidebar-footer .user-info {
                justify-content: center;
            }

            .sidebar .sidebar-footer .logout-btn span {
                display: none;
            }

            .sidebar .sidebar-footer .logout-btn {
                text-align: center;
                padding: 12px;
            }

            .sidebar .sidebar-footer .logout-btn i {
                margin-right: 0;
            }

            .main-content {
                margin-left: 72px;
                width: calc(100% - 72px);
                padding: 20px;
            }

            .sidebar-toggle {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .sidebar-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
                width: 280px;
                position: fixed;
                z-index: 1000;
                top: 0;
                left: 0;
                height: 100vh;
                overflow-y: auto;
            }

            .sidebar.open {
                transform: translateX(0);
            }

            .sidebar .sidebar-header h4 {
                font-size: 20px;
            }

            .sidebar .sidebar-header small {
                display: block;
            }

            .sidebar .sidebar-header .tenant-badge {
                display: inline-block;
            }

            .sidebar .nav-link span {
                display: inline;
            }

            .sidebar .nav-link i {
                margin-right: 12px;
                font-size: 15px;
            }

            .sidebar .nav-link {
                text-align: left;
                padding: 10px 16px;
                justify-content: flex-start;
            }

            .sidebar .nav-label {
                display: block;
            }

            .sidebar .sidebar-footer .user-info span {
                display: inline;
            }

            .sidebar .sidebar-footer .user-info {
                justify-content: flex-start;
            }

            .sidebar .sidebar-footer .logout-btn span {
                display: inline;
            }

            .sidebar .sidebar-footer .logout-btn {
                text-align: left;
                padding: 10px 16px;
            }

            .sidebar .sidebar-footer .logout-btn i {
                margin-right: 12px;
            }

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 16px;
                padding-top: 70px;
            }

            .top-bar .page-title h1 {
                font-size: 22px;
            }

            .top-bar .page-title p {
                font-size: 12px;
            }

            .top-bar .header-actions .btn {
                font-size: 12px;
                padding: 6px 12px;
            }
        }

        @media (max-width: 480px) {
            .main-content {
                padding: 10px 12px 20px;
                padding-top: 65px;
            }

            .top-bar .page-title h1 {
                font-size: 18px;
            }

            .top-bar .page-title p {
                font-size: 11px;
            }

            .top-bar .header-actions .btn {
                font-size: 11px;
                padding: 4px 10px;
            }

            .card-custom .card-body-custom {
                padding: 14px 16px;
            }

            .form-control,
            .form-select {
                font-size: 13px;
                padding: 6px 12px;
                height: 40px;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php require_once $projectRoot . '/app/views/partials/platform-sidebar.php'; ?>

            <!-- Main Content -->
            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-user me-2"></i>My Profile</h1>
                        <p>Manage your account settings and preferences</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/profile/change-password.php" class="btn btn-outline-secondary">
                            <i class="fas fa-key me-2"></i> Change Password
                        </a>
                    </div>
                </div>

                <!-- Tenant Context Banner -->
                <div class="tenant-context-banner">
                    <i class="fas fa-building"></i>
                    <span><strong>Tenant:</strong> <?php echo htmlspecialchars($tenantName); ?></span>
                    <span class="text-muted">|</span>
                    <span><i class="fas fa-user-tag"></i> <strong>Status:</strong>
                        <span class="status-badge <?php echo $userData['is_active'] ? 'active' : 'inactive'; ?>">
                            <?php echo $userData['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </span>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Profile Info Card -->
                    <div class="col-lg-4 col-12">
                        <div class="card-custom">
                            <div class="card-body-custom" style="text-align:center;">
                                <div class="profile-avatar">
                                    <?php echo substr($userData['first_name'] ?? 'A', 0, 1); ?>
                                </div>
                                <h5 style="font-weight:700;margin-bottom:4px;"><?php echo htmlspecialchars($displayName); ?></h5>
                                <p style="color:#6c757d;font-size:14px;margin-bottom:4px;">
                                    <i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars($userData['email'] ?? ''); ?>
                                </p>
                                <?php if (!empty($userData['phone'])): ?>
                                    <p style="color:#6c757d;font-size:14px;">
                                        <i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($userData['phone']); ?>
                                    </p>
                                <?php endif; ?>
                                <div style="margin-top:12px;">
                                    <?php foreach ($roles as $role): ?>
                                        <span class="role-badge <?php echo strtolower(str_replace(' ', '-', $role['role_code'] ?? 'default')); ?>">
                                            <?php echo htmlspecialchars($role['role_name'] ?? 'Role'); ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <hr>
                                <div style="font-size:13px;color:#6c757d;text-align:left;">
                                    <div style="display:flex;justify-content:space-between;padding:4px 0;">
                                        <span>Account Created</span>
                                        <span><?php echo isset($userData['created_at']) ? date('M d, Y', strtotime($userData['created_at'])) : 'N/A'; ?></span>
                                    </div>
                                    <div style="display:flex;justify-content:space-between;padding:4px 0;">
                                        <span>Last Login</span>
                                        <span><?php echo isset($userData['last_login']) ? date('M d, Y H:i', strtotime($userData['last_login'])) : 'Never'; ?></span>
                                    </div>
                                    <div style="display:flex;justify-content:space-between;padding:4px 0;">
                                        <span>2FA Status</span>
                                        <span>
                                            <?php if ($twofaStatus['enabled']): ?>
                                                <span style="color:#28a745;"><i class="fas fa-check-circle"></i> Enabled</span>
                                            <?php else: ?>
                                                <span style="color:#6c757d;"><i class="fas fa-times-circle"></i> Disabled</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                                <hr>
                                <a href="/platform/2fa/setup.php" class="btn btn-outline-<?php echo $twofaStatus['enabled'] ? 'danger' : 'success'; ?> btn-sm" style="border-radius:20px;">
                                    <i class="fas <?php echo $twofaStatus['enabled'] ? 'fa-times' : 'fa-shield-alt'; ?> me-1"></i>
                                    <?php echo $twofaStatus['enabled'] ? 'Disable 2FA' : 'Enable 2FA'; ?>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Edit Profile Form -->
                    <div class="col-lg-8 col-12">
                        <div class="card-custom">
                            <div class="card-header-custom">
                                <h6><i class="fas fa-edit me-2 text-primary"></i>Edit Profile</h6>
                            </div>
                            <div class="card-body-custom">
                                <form method="POST" action="">
                                    <input type="hidden" name="action" value="update_profile">

                                    <div class="row">
                                        <div class="col-md-6 col-12 mb-3">
                                            <label class="form-label">First Name <span class="required">*</span></label>
                                            <input type="text" class="form-control" name="first_name"
                                                value="<?php echo htmlspecialchars($userData['first_name'] ?? ''); ?>" required>
                                        </div>
                                        <div class="col-md-6 col-12 mb-3">
                                            <label class="form-label">Last Name</label>
                                            <input type="text" class="form-control" name="last_name"
                                                value="<?php echo htmlspecialchars($userData['last_name'] ?? ''); ?>">
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 col-12 mb-3">
                                            <label class="form-label">Email Address</label>
                                            <input type="email" class="form-control"
                                                value="<?php echo htmlspecialchars($userData['email'] ?? ''); ?>" disabled>
                                            <div class="form-text">Email cannot be changed. Contact administrator for changes.</div>
                                        </div>
                                        <div class="col-md-6 col-12 mb-3">
                                            <label class="form-label">Phone Number</label>
                                            <input type="tel" class="form-control" name="phone"
                                                value="<?php echo htmlspecialchars($userData['phone'] ?? ''); ?>"
                                                placeholder="+233 XX XXX XXXX">
                                        </div>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 col-12 mb-3">
                                            <label class="form-label">Default Timezone</label>
                                            <select class="form-select" name="timezone">
                                                <?php foreach ($timezones as $key => $label): ?>
                                                    <option value="<?php echo $key; ?>" <?php echo ($userData['timezone'] ?? 'UTC') == $key ? 'selected' : ''; ?>>
                                                        <?php echo $label; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6 col-12 mb-3">
                                            <label class="form-label">Default Language</label>
                                            <select class="form-select" name="language">
                                                <?php foreach ($languages as $key => $label): ?>
                                                    <option value="<?php echo $key; ?>" <?php echo ($userData['language'] ?? 'en') == $key ? 'selected' : ''; ?>>
                                                        <?php echo $label; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="d-flex flex-wrap gap-2 justify-content-end mt-3 pt-3 border-top">
                                        <a href="/platform/index.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-times me-2"></i> Cancel
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-save me-2"></i> Update Profile
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // toggleSidebar() is provided by the partial at
        // app/views/partials/platform-sidebar.php.

        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.getElementById('sidebarToggle');
            if (!sidebar || !toggle) return;
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                }
            }
        });

        window.addEventListener('resize', function() {
            const sb = document.getElementById('sidebar');
            if (sb && window.innerWidth > 768) {
                sb.classList.remove('open');
            }
        });

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                window.location.href = '/platform/logout.php';
            }
        }
    </script>
</body>

</html>