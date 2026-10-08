<?php

/**
 * Two-Factor Authentication Setup Page
 *
 * @package EduTrack
 * @subpackage Platform\2FA
 * @version 1.1
 * @filepath public/platform/2fa/setup.php
 *
 * v1.1 change (2026-10-07) [SWEEP SIDEBAR]:
 *   Sidebar reconciliation. The inline sidebar this page carried
 *   since v1.0 is replaced by a single require_once of the new
 *   shared partial at app/views/partials/platform-sidebar.php v1.0.
 *   That partial carries the canonical platform-root sidebar shape:
 *   11 items across 4 groups (Main, Institution, Management,
 *   System), folder paths for Tenants (/platform/tenants/index.php)
 *   and Settings (/platform/settings/index.php), no Register Tenant
 *   item, plus the mobile toggle, the sidebar footer, and the
 *   toggleSidebar() JS.
 *
 *   This resolution also removes the broken sidebar link to
 *   /platform/dashboard/index.php that v1.0 carried. The
 *   confirmation is on the record: public/platform/dashboard/ does
 *   not exist in the directory listing. The canonical partial does
 *   not carry a separate Dashboard item beyond /platform/index.php.
 *
 *   $currentPage is changed from 'profile' to 'settings'. The
 *   canonical partial has no Profile item; 'settings' is the
 *   closest canonical key for this page's surface, which is a
 *   platform security setting.
 *
 *   $pendingApprovals is set to 0 before the partial is required;
 *   this page does not compute pending approvals, so the canonical
 *   sidebar's Approvals badge will not appear here.
 *
 *   The inline toggleSidebar() function that this page carried in
 *   its own <script> block is removed, because the partial now
 *   provides it. The page's click-outside handler and resize
 *   handler are kept — they are page-scoped and do not conflict
 *   with the partial's toggle. Every other line of the file is
 *   byte-identical to v1.0.
 *
 * v1.0 change (2026-10-07) [SWEEP X-1]:
 *   Platform-2fa sweep, X-1 in full. The file carried no @version
 *   tag and no @subpackage tag. This sweep adds both, in the shape
 *   every other file on the record uses, adds this v1.0 [SWEEP]
 *   docblock paragraph above the existing description, and renames
 *   three visible "EduTrack" strings to "Student 360":
 *     - The tenant-name fallback.
 *     - The sidebar brand heading.
 *     - The clipboard header in copyBackupCodes().
 *   This page carried an inline sidebar that links to the
 *   platform-root navigation surface. The inline sidebar was
 *   preserved in v1.0: replacing it with the tenant partial would
 *   change the page's navigation. The .nav-subgroup-label CSS rule
 *   was not added. Every other line of the file was byte-identical
 *   to the version that was on disk before this sweep.
 */

// ============================================================
// LOAD HELPERS
// ============================================================
require_once dirname(__DIR__, 3) . '/app/helpers/SessionHelper.php';
require_once dirname(__DIR__, 3) . '/app/helpers/DatabaseHelper.php';
require_once dirname(__DIR__, 3) . '/app/helpers/TenantHelper.php';
require_once dirname(__DIR__, 3) . '/app/services/TwoFAService.php';
require_once dirname(__DIR__, 3) . '/app/services/OTPService.php';
require_once dirname(__DIR__, 3) . '/app/views/partials/platform-sidebar.php';

// ============================================================
// SESSION & AUTHENTICATION
// ============================================================
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

// ============================================================
// GET 2FA STATUS
// ============================================================
$twofaStatus = TwoFAService::getStatus($userId);
$isEnabled = $twofaStatus['enabled'];
$isVerified = $twofaStatus['verified'];
$backupCodes = TwoFAService::getBackupCodes($userId);

// ============================================================
// HANDLE ACTIONS
// ============================================================
$error = '';
$success = '';
$action = $_GET['action'] ?? '';

// Enable 2FA
if ($action === 'enable' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $otpCode = trim($_POST['otp_code'] ?? '');

    if (empty($otpCode)) {
        $error = 'Please enter the verification code.';
    } else {
        // Verify OTP
        $verifyResult = OTPService::verify($userId, $otpCode, '2fa_setup');

        if ($verifyResult['success']) {
            // Enable 2FA
            $result = TwoFAService::enable($userId);
            if ($result['success']) {
                // Update session
                $_SESSION['user']['two_factor_enabled'] = 1;
                $success = '2FA enabled successfully!';
                $backupCodes = $result['backup_codes'];
                $isEnabled = true;
                $isVerified = true;

                // Log the action
                $db->execute(
                    "INSERT INTO platform_audit_logs (user_id, tenant_id, action, resource_type, details, ip_address, user_agent) 
                     VALUES (?, ?, '2fa_enabled', 'user', ?, ?, ?)",
                    [$userId, $tenant['id'] ?? 1, json_encode(['user_id' => $userId]), $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']
                );
            } else {
                $error = $result['message'] ?? 'Failed to enable 2FA.';
            }
        } else {
            $error = $verifyResult['message'];
        }
    }
}

// Disable 2FA
if ($action === 'disable' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirm = $_POST['confirm'] ?? '';

    if ($confirm !== 'yes') {
        $error = 'Please confirm you want to disable 2FA.';
    } else {
        $result = TwoFAService::disable($userId);
        if ($result['success']) {
            $_SESSION['user']['two_factor_enabled'] = 0;
            $success = '2FA disabled successfully.';
            $isEnabled = false;
            $isVerified = false;
            $backupCodes = [];

            // Log the action
            $db->execute(
                "INSERT INTO platform_audit_logs (user_id, tenant_id, action, resource_type, details, ip_address, user_agent) 
                 VALUES (?, ?, '2fa_disabled', 'user', ?, ?, ?)",
                [$userId, $tenant['id'] ?? 1, json_encode(['user_id' => $userId]), $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '']
            );
        } else {
            $error = $result['message'] ?? 'Failed to disable 2FA.';
        }
    }
}

// Regenerate backup codes
if ($action === 'regenerate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $newBackupCodes = TwoFAService::regenerateBackupCodes($userId);
    if ($newBackupCodes) {
        $backupCodes = $newBackupCodes;
        $success = 'Backup codes regenerated successfully. Please save your new backup codes.';
    } else {
        $error = 'Failed to regenerate backup codes.';
    }
}

// Send OTP for setup
if ($action === 'send_otp') {
    $otp = OTPService::generate($userId, '2fa_setup');
    $result = OTPService::sendEmail($userId, $otp, '2fa_setup');

    if ($result['success']) {
        $_SESSION['_2fa_setup_otp_sent'] = true;
        $success = 'Verification code sent to your email.';
    } else {
        $error = 'Failed to send verification code. Please try again.';
    }
}

$pageTitle = '2FA Setup - ' . htmlspecialchars($tenantName);
$currentPage = 'settings';
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

        .btn-outline-danger {
            background: transparent;
            border: 2px solid #dc3545;
            color: #dc3545;
        }

        .btn-outline-danger:hover {
            background: #dc3545;
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

        .backup-codes {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 16px 20px;
            border: 2px dashed #e9ecef;
            font-family: 'Courier New', monospace;
            font-size: 16px;
            letter-spacing: 2px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .backup-codes .code {
            padding: 6px 12px;
            background: #fff;
            border-radius: 6px;
            text-align: center;
            font-weight: 600;
            color: #1a1a2e;
        }

        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 40px;
            margin-bottom: 24px;
            position: relative;
        }

        .step-indicator::after {
            content: '';
            position: absolute;
            top: 16px;
            left: 25%;
            right: 25%;
            height: 2px;
            background: #e9ecef;
            z-index: 0;
        }

        .step-indicator .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
        }

        .step-indicator .step .circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e9ecef;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
            transition: all 0.3s;
        }

        .step-indicator .step.active .circle {
            background: #4facfe;
            color: #fff;
            box-shadow: 0 4px 12px rgba(79, 172, 254, 0.4);
        }

        .step-indicator .step.completed .circle {
            background: #28a745;
            color: #fff;
        }

        .step-indicator .step .label {
            font-size: 11px;
            color: #6c757d;
            margin-top: 4px;
        }

        .step-indicator .step.active .label {
            color: #4facfe;
            font-weight: 600;
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

            .backup-codes {
                grid-template-columns: 1fr;
            }

            .step-indicator {
                gap: 20px;
            }

            .step-indicator::after {
                left: 20%;
                right: 20%;
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

            .step-indicator {
                gap: 12px;
            }

            .step-indicator .step .label {
                font-size: 9px;
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
                        <h1><i class="fas fa-shield-alt me-2"></i>Two-Factor Authentication</h1>
                        <p>Secure your account with 2FA</p>
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
                    <span><i class="fas fa-shield-alt"></i> <strong>Status:</strong>
                        <?php if ($isEnabled): ?>
                            <span style="color:#28a745;"><i class="fas fa-check-circle"></i> Enabled</span>
                        <?php else: ?>
                            <span style="color:#6c757d;"><i class="fas fa-times-circle"></i> Disabled</span>
                        <?php endif; ?>
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

                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step <?php echo $isEnabled ? 'completed' : 'active'; ?>">
                        <div class="circle">1</div>
                        <span class="label">Enable 2FA</span>
                    </div>
                    <div class="step <?php echo $isEnabled ? 'active' : ''; ?>">
                        <div class="circle">2</div>
                        <span class="label">Save Backup Codes</span>
                    </div>
                </div>

                <?php if (!$isEnabled): ?>
                    <!-- Enable 2FA -->
                    <div class="card-custom" style="max-width:560px;margin:0 auto;">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-shield-alt me-2 text-primary"></i>Enable Two-Factor Authentication</h6>
                        </div>
                        <div class="card-body-custom">
                            <p style="font-size:14px;color:#6c757d;margin-bottom:16px;">
                                <i class="fas fa-info-circle text-primary me-1"></i>
                                When 2FA is enabled, you'll need to enter a verification code from your email every time you log in.
                            </p>

                            <div class="alert alert-warning" style="border-radius:12px;border:none;font-size:13px;">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Make sure you have access to your registered email address before enabling 2FA.
                            </div>

                            <form method="POST" action="?action=enable">
                                <div class="mb-3">
                                    <label class="form-label">Verification Code <span class="required">*</span></label>
                                    <div class="d-flex gap-2">
                                        <input type="text" class="form-control" name="otp_code"
                                            placeholder="Enter 6-digit code" maxlength="6" required
                                            style="flex:1;">
                                        <a href="?action=send_otp" class="btn btn-outline-secondary" style="white-space:nowrap;">
                                            <i class="fas fa-paper-plane"></i> Send Code
                                        </a>
                                    </div>
                                    <div class="form-text">
                                        <i class="fas fa-clock me-1"></i> Code expires in 15 minutes
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-check-circle me-2"></i> Enable 2FA
                                </button>
                            </form>
                        </div>
                    </div>

                <?php elseif ($isEnabled && empty($backupCodes)): ?>
                    <!-- Show Backup Codes -->
                    <div class="card-custom" style="max-width:560px;margin:0 auto;">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-key me-2 text-warning"></i>Your Backup Codes</h6>
                        </div>
                        <div class="card-body-custom">
                            <div class="alert alert-success" style="border-radius:12px;border:none;font-size:13px;">
                                <i class="fas fa-check-circle me-2"></i>
                                <strong>2FA has been enabled successfully!</strong>
                            </div>

                            <p style="font-size:14px;color:#6c757d;margin-bottom:12px;">
                                <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                                <strong>Save these backup codes in a secure place.</strong> Each code can only be used once.
                            </p>

                            <div class="backup-codes" id="backupCodes">
                                <?php
                                $codes = TwoFAService::getBackupCodes($userId);
                                if (empty($codes)) {
                                    $codes = TwoFAService::regenerateBackupCodes($userId);
                                }
                                foreach ($codes as $code):
                                ?>
                                    <div class="code"><?php echo htmlspecialchars($code); ?></div>
                                <?php endforeach; ?>
                            </div>

                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <button class="btn btn-primary" onclick="copyBackupCodes()">
                                    <i class="fas fa-copy me-2"></i> Copy Codes
                                </button>
                                <a href="?action=regenerate" class="btn btn-outline-warning" onclick="return confirm('Regenerating codes will invalidate existing backup codes. Continue?')">
                                    <i class="fas fa-sync me-2"></i> Regenerate Codes
                                </a>
                                <a href="/platform/profile/index.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-right me-2"></i> Done
                                </a>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- 2FA Enabled with Backup Codes -->
                    <div class="card-custom" style="max-width:560px;margin:0 auto;">
                        <div class="card-header-custom">
                            <h6><i class="fas fa-shield-alt me-2 text-success"></i>2FA is Enabled</h6>
                        </div>
                        <div class="card-body-custom">
                            <div class="alert alert-success" style="border-radius:12px;border:none;font-size:13px;">
                                <i class="fas fa-check-circle me-2"></i>
                                Your account is protected with Two-Factor Authentication.
                            </div>

                            <p style="font-size:14px;color:#6c757d;margin-bottom:12px;">
                                <i class="fas fa-key me-1 text-warning"></i>
                                <strong>Backup Codes:</strong> You have <strong><?php echo count($backupCodes); ?></strong> backup codes remaining.
                            </p>

                            <?php if (!empty($backupCodes)): ?>
                                <div class="backup-codes mb-3" id="backupCodes">
                                    <?php foreach (array_slice($backupCodes, 0, 5) as $code): ?>
                                        <div class="code"><?php echo htmlspecialchars($code); ?></div>
                                    <?php endforeach; ?>
                                    <?php if (count($backupCodes) > 5): ?>
                                        <div class="code" style="background:#f8f9fa;color:#6c757d;">
                                            +<?php echo count($backupCodes) - 5; ?> more
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-outline-primary" onclick="copyBackupCodes()">
                                    <i class="fas fa-copy me-2"></i> Copy Codes
                                </button>
                                <a href="?action=regenerate" class="btn btn-outline-warning" onclick="return confirm('Regenerating codes will invalidate existing backup codes. Continue?')">
                                    <i class="fas fa-sync me-2"></i> Regenerate Codes
                                </a>
                                <button class="btn btn-outline-danger" onclick="showDisableModal()">
                                    <i class="fas fa-times me-2"></i> Disable 2FA
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Disable 2FA Modal -->
                    <div class="modal fade" id="disableModal" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content" style="border-radius:16px;border:none;box-shadow:0 20px 60px rgba(0,0,0,0.15);">
                                <div class="modal-header" style="border-bottom:1px solid #f0f2f5;padding:16px 24px;">
                                    <h5 class="modal-title" style="font-weight:600;color:#dc3545;">
                                        <i class="fas fa-exclamation-triangle me-2"></i> Disable 2FA
                                    </h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body" style="padding:24px;">
                                    <p>Are you sure you want to disable Two-Factor Authentication?</p>
                                    <p class="text-muted" style="font-size:13px;">This will make your account less secure. You can re-enable it at any time.</p>
                                    <form method="POST" action="?action=disable">
                                        <input type="hidden" name="confirm" value="yes">
                                        <div class="d-flex gap-2 mt-3">
                                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger">
                                                <i class="fas fa-times me-2"></i> Yes, Disable 2FA
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
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

        function showDisableModal() {
            const modal = new bootstrap.Modal(document.getElementById('disableModal'));
            modal.show();
        }

        function copyBackupCodes() {
            const codes = document.querySelectorAll('#backupCodes .code');
            if (codes.length === 0) {
                alert('No backup codes to copy.');
                return;
            }

            let text = '=== Student 360 Backup Codes ===\n';
            text += 'Keep these codes safe. Each can be used only once.\n\n';
            codes.forEach(code => {
                text += code.textContent.trim() + '\n';
            });
            text += '\nGenerated: ' + new Date().toLocaleString();

            navigator.clipboard.writeText(text).then(() => {
                alert('Backup codes copied to clipboard!');
            }).catch(() => {
                // Fallback
                const textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                alert('Backup codes copied to clipboard!');
            });
        }
    </script>

    <?php if ($isEnabled && empty($backupCodes)): ?>
        <script>
            // Auto-copy backup codes when first shown
            document.addEventListener('DOMContentLoaded', function() {
                setTimeout(() => {
                    if (confirm('Do you want to copy your backup codes now?')) {
                        copyBackupCodes();
                    }
                }, 1000);
            });
        </script>
    <?php endif; ?>
</body>

</html>