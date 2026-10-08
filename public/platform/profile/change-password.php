<?php

/**
 * Change Password Page
 *
 * @package EduTrack
 * @subpackage Platform\Profile
 * @version 1.0
 * @filepath public/platform/profile/change-password.php
 *
 * v1.0 change (2026-10-07) [SWEEP X-1 + SIDEBAR]:
 *   Platform-profile sweep, X-1 in full, plus sidebar reconciliation.
 *
 *   X-1: the file carried no @version and no @subpackage tag. This
 *   sweep adds both, in the shape every other file on the record
 *   uses, and renames two visible "EduTrack" strings to "Student 360":
 *     - The tenant-name fallback. It read
 *         $tenantName = $tenant['tenant_name'] ?? 'EduTrack';
 *       and now reads
 *         $tenantName = $tenant['tenant_name'] ?? 'Student 360';
 *     - The sidebar brand heading <h4>...EduTrack</h4>. That heading
 *       is superseded by the sidebar reconciliation below, which
 *       replaces the entire inline sidebar with the shared partial.
 *
 *   Sidebar: the inline sidebar this page carried — 7 items across
 *   3 groups, with a broken Dashboard link to
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
 *   sidebar item is marked active. The Profile surface is not one
 *   of the canonical sidebar items. $pendingApprovals = 0.
 *
 *   The inline toggleSidebar() function that this page carried in
 *   its own <script> block is removed, because the partial now
 *   provides it. The page's click-outside handler, resize handler,
 *   togglePassword() function, password-strength checker, and
 *   password-match checker are kept — they are page-scoped and do
 *   not conflict with the partial.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
 *
 *   Every other line of the file is byte-identical to the version
 *   that was on disk before this sweep.
 */

$projectRoot = dirname(__DIR__, 3);
require_once $projectRoot . '/app/helpers/SessionHelper.php';
require_once $projectRoot . '/app/helpers/DatabaseHelper.php';
require_once $projectRoot . '/app/services/PasswordService.php';
require_once $projectRoot . '/app/helpers/TenantHelper.php';
require_once $projectRoot . '/app/views/partials/platform-sidebar.php';

SessionHelper::start();

if (!SessionHelper::isPlatformLoggedIn()) {
    header('Location: /platform/login.php');
    exit;
}

$user = SessionHelper::getUser();
$userId = $user['id'] ?? null;

if (!$userId) {
    header('Location: /platform/logout.php');
    exit;
}

$db = DatabaseHelper::getInstance();
$tenant = TenantHelper::detectTenant();
$tenantName = $tenant['tenant_name'] ?? 'Student 360';

$error = '';
$success = '';
$forceChange = isset($_GET['force']) && $_GET['force'] == 1;

// Get user details
$userData = $db->fetchOne(
    "SELECT first_name, email, must_change_password FROM platform_users WHERE id = ?",
    [$userId]
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Validate
    if (empty($currentPassword)) {
        $error = 'Current password is required.';
    } elseif (empty($newPassword)) {
        $error = 'New password is required.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        // Verify current password
        $dbUser = $db->fetchOne(
            "SELECT password_hash FROM platform_users WHERE id = ?",
            [$userId]
        );

        if (!PasswordService::verify($currentPassword, $dbUser['password_hash'])) {
            $error = 'Current password is incorrect.';
        } else {
            // Update password
            $result = PasswordService::update($userId, $newPassword, $forceChange);

            if ($result['success']) {
                $success = 'Password changed successfully!';
                if ($forceChange) {
                    $success .= ' You can now access the dashboard.';
                }
                // Clear forced change flag
                if ($forceChange) {
                    $db->execute(
                        "UPDATE platform_users SET must_change_password = 0 WHERE id = ?",
                        [$userId]
                    );
                }
            } else {
                $error = $result['message'] ?? 'Failed to update password.';
                if (!empty($result['errors'])) {
                    $error = implode('<br>', $result['errors']);
                }
            }
        }
    }
}

$pageTitle = 'Change Password - ' . htmlspecialchars($tenantName);
$currentPage = '';
$pendingApprovals = 0;
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
        /* Same styles as profile/index.php */
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

        .form-control {
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

        .form-control:focus {
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

        .password-wrapper {
            position: relative;
        }

        .password-wrapper .form-control {
            padding-right: 48px;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            padding: 8px;
            font-size: 18px;
            transition: color 0.2s;
        }

        .password-toggle:hover {
            color: #1a1a2e;
        }

        .password-toggle:focus {
            outline: none;
        }

        .password-strength {
            height: 4px;
            border-radius: 4px;
            margin-top: 8px;
            background: #e9ecef;
            overflow: hidden;
            transition: all 0.3s;
        }

        .password-strength .bar {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .password-strength .bar.weak {
            background: #dc3545;
        }

        .password-strength .bar.fair {
            background: #ffc107;
        }

        .password-strength .bar.good {
            background: #17a2b8;
        }

        .password-strength .bar.strong {
            background: #007bff;
        }

        .password-strength .bar.very-strong {
            background: #28a745;
        }

        .password-hint {
            font-size: 12px;
            color: #6c757d;
            margin-top: 4px;
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

            .form-control {
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

            <main class="main-content">
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-key me-2"></i>Change Password</h1>
                        <p>Update your account password</p>
                    </div>
                    <div class="header-actions">
                        <a href="/platform/profile/index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i> Back to Profile
                        </a>
                    </div>
                </div>

                <div class="tenant-context-banner">
                    <i class="fas fa-building"></i>
                    <span><strong>Tenant:</strong> <?php echo htmlspecialchars($tenantName); ?></span>
                    <span class="text-muted">|</span>
                    <span><i class="fas fa-user"></i> <strong>User:</strong> <?php echo htmlspecialchars($userData['email'] ?? ''); ?></span>
                    <?php if ($forceChange): ?>
                        <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Password change required</span>
                    <?php endif; ?>
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
                    <?php if (!$forceChange): ?>
                        <div class="text-center mt-3">
                            <a href="/platform/profile/index.php" class="btn btn-primary">
                                <i class="fas fa-arrow-left me-2"></i> Back to Profile
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!$success): ?>
                    <div class="card-custom" style="max-width:560px;margin:0 auto;">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-shield-alt me-2 text-primary"></i>Password Requirements</h6>
                        </div>
                        <div class="card-body-custom">
                            <ul style="font-size:13px;color:#6c757d;padding-left:20px;margin-bottom:16px;">
                                <li>Minimum 8 characters</li>
                                <li>At least one uppercase letter</li>
                                <li>At least one lowercase letter</li>
                                <li>At least one number</li>
                                <li>At least one special character</li>
                                <li>Cannot reuse last 5 passwords</li>
                            </ul>

                            <form method="POST" action="">
                                <div class="mb-3">
                                    <label class="form-label">Current Password <span class="required">*</span></label>
                                    <div class="password-wrapper">
                                        <input type="password" class="form-control" id="currentPassword" name="current_password" placeholder="Enter current password" required>
                                        <button type="button" class="password-toggle" onclick="togglePassword('currentPassword')">
                                            <i class="fas fa-eye" id="currentToggleIcon"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">New Password <span class="required">*</span></label>
                                    <div class="password-wrapper">
                                        <input type="password" class="form-control" id="newPassword" name="new_password" placeholder="Enter new password" required minlength="8">
                                        <button type="button" class="password-toggle" onclick="togglePassword('newPassword')">
                                            <i class="fas fa-eye" id="newToggleIcon"></i>
                                        </button>
                                    </div>
                                    <div id="strengthBar" class="password-strength">
                                        <div id="strengthBarInner" class="bar" style="width:0%;"></div>
                                    </div>
                                    <div id="strengthLabel" class="password-hint">Enter a strong password</div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Confirm Password <span class="required">*</span></label>
                                    <div class="password-wrapper">
                                        <input type="password" class="form-control" id="confirmPassword" name="confirm_password" placeholder="Confirm new password" required>
                                        <button type="button" class="password-toggle" onclick="togglePassword('confirmPassword')">
                                            <i class="fas fa-eye" id="confirmToggleIcon"></i>
                                        </button>
                                    </div>
                                    <div id="matchStatus" class="password-hint"></div>
                                </div>

                                <div class="d-flex flex-wrap gap-2 justify-content-end mt-3 pt-3 border-top">
                                    <a href="/platform/profile/index.php" class="btn btn-outline-secondary">
                                        <i class="fas fa-times me-2"></i> Cancel
                                    </a>
                                    <button type="submit" class="btn btn-primary" id="submitBtn">
                                        <i class="fas fa-save me-2"></i> Change Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
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

        function togglePassword(fieldId) {
            const input = document.getElementById(fieldId);
            const icon = document.getElementById(fieldId.replace('Password', 'ToggleIcon'));
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }

        // Password strength checker
        document.getElementById('newPassword').addEventListener('input', function() {
            const password = this.value;
            const bar = document.getElementById('strengthBarInner');
            const label = document.getElementById('strengthLabel');

            let score = 0;
            if (password.length >= 8) score += 20;
            if (password.length >= 12) score += 10;
            if (password.length >= 16) score += 10;
            if (/[a-z]/.test(password)) score += 10;
            if (/[A-Z]/.test(password)) score += 10;
            if (/[0-9]/.test(password)) score += 10;
            if (/[^A-Za-z0-9]/.test(password)) score += 10;
            if (new Set(password).size > 5) score += 10;
            if (new Set(password).size > 8) score += 10;

            score = Math.min(score, 100);

            let level = 'weak';
            let levelText = 'Weak';
            if (score >= 85) {
                level = 'very-strong';
                levelText = 'Very Strong';
            } else if (score >= 70) {
                level = 'strong';
                levelText = 'Strong';
            } else if (score >= 50) {
                level = 'good';
                levelText = 'Good';
            } else if (score >= 30) {
                level = 'fair';
                levelText = 'Fair';
            }

            bar.className = 'bar ' + level;
            bar.style.width = score + '%';
            label.textContent = 'Strength: ' + levelText;
            label.className = 'password-hint';
        });

        // Password match checker
        document.getElementById('confirmPassword').addEventListener('input', function() {
            const password = document.getElementById('newPassword').value;
            const confirm = this.value;
            const status = document.getElementById('matchStatus');

            if (confirm.length === 0) {
                status.textContent = '';
                return;
            }

            if (password === confirm) {
                status.innerHTML = '<span style="color:#28a745;"><i class="fas fa-check-circle"></i> Passwords match</span>';
            } else {
                status.innerHTML = '<span style="color:#dc3545;"><i class="fas fa-exclamation-circle"></i> Passwords do not match</span>';
            }
        });
    </script>
</body>

</html>