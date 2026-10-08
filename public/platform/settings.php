<?php

/**
 * Platform Settings
 *
 * @package EduTrack
 * @subpackage Platform
 * @version 1.0
 * @filepath public/platform/settings.php
 *
 * v1.0 change (2026-10-07) [SWEEP X-1 + SIDEBAR]:
 *   Platform-root sweep, X-1 in full, plus sidebar reconciliation.
 *
 *   X-1: the file carried no @package, no @subpackage, no @version,
 *   and no @filepath tag. This sweep adds all four and renames five
 *   visible "EduTrack" strings to "Student 360":
 *     - The <title> tag.
 *     - The sidebar brand heading (superseded by the sidebar
 *       reconciliation below).
 *     - The System Name input default value.
 *     - The Company Name input default value.
 *     - The Support Email input default value.
 *
 *   Sidebar: the inline sidebar this page carried is replaced by a
 *   single require_once of the new shared partial at
 *   app/views/partials/platform-sidebar.php v1.0. That partial
 *   carries the canonical platform-root sidebar shape: 11 items
 *   across 4 groups (Main, Institution, Management, System), folder
 *   paths for Tenants and Settings, no Register Tenant item, plus
 *   the mobile toggle, the sidebar footer, and the toggleSidebar()
 *   JS.
 *
 *   This resolution also removes the broken sidebar links that the
 *   inline sidebar carried to /platform/tenants.php and
 *   /platform/tenants-create.php. Both are on the record as
 *   non-existent per the directory listings for public/platform/.
 *
 *   $currentPage = 'settings' so the canonical sidebar's Settings
 *   item is marked active on this page. $pendingApprovals = 0.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
 *
 *   Every other line of the file is byte-identical to the version
 *   that was on disk before this sweep.
 */

$projectRoot = dirname(__DIR__, 2);

$pageTitle = 'Settings - Student 360 Platform';
$currentPage = 'settings';
$currentUser = 'Admin';
$userFirstName = 'User';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));
$pendingApprovals = 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Student 360 Platform</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
        }

        .sidebar {
            background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%) !important;
            min-height: 100vh;
        }

        .sidebar .brand {
            padding: 20px 20px 15px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }

        .sidebar .brand h5 {
            font-weight: 700;
            color: #fff;
        }

        .sidebar .brand h5 span {
            color: #f59e0b;
        }

        .sidebar .brand small {
            color: rgba(255, 255, 255, 0.4);
            font-size: 11px;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .sidebar .nav-link {
            color: rgba(255, 255, 255, 0.6);
            padding: 10px 20px;
            margin: 2px 10px;
            border-radius: 8px;
            transition: all 0.3s;
            font-size: 14px;
        }

        .sidebar .nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
            color: #fff;
        }

        .sidebar .nav-link.active {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
        }

        .sidebar .nav-link i {
            width: 22px;
            text-align: center;
            margin-right: 10px;
        }

        .sidebar .nav-divider {
            border-color: rgba(255, 255, 255, 0.05);
            margin: 10px 15px;
        }

        .sidebar .nav-link.text-danger:hover {
            background: rgba(220, 53, 69, 0.15);
        }

        .topbar {
            background: #fff;
            padding: 12px 25px;
            border-bottom: 1px solid #e9ecef;
        }

        .topbar h4 {
            font-weight: 600;
            color: #1a1a2e;
            margin: 0;
        }

        .topbar h4 i {
            color: #f59e0b;
            margin-right: 10px;
        }

        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .form-control:focus {
            border-color: #f59e0b;
            box-shadow: 0 0 0 0.2rem rgba(245, 158, 11, 0.25);
        }

        .btn-primary {
            background: #1a3c6e;
            border-color: #1a3c6e;
        }

        .btn-primary:hover {
            background: #2a4c8e;
            border-color: #2a4c8e;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #1a3c6e;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
        }

        .toast-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 380px;
            width: 100%;
        }

        .toast-custom {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            border-left: 4px solid #1a3c6e;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }

        .toast-custom.success {
            border-left-color: #28a745;
        }

        .toast-custom.danger {
            border-left-color: #dc3545;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(30px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .toast-custom .toast-icon {
            font-size: 20px;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .toast-custom.success .toast-icon {
            background: #d4edda;
            color: #155724;
        }

        .toast-custom.danger .toast-icon {
            background: #f8d7da;
            color: #721c24;
        }

        .toast-custom .toast-body {
            flex: 1;
            font-size: 14px;
            font-weight: 500;
        }

        .toast-custom .toast-close {
            background: none;
            border: none;
            font-size: 18px;
            color: #adb5bd;
            cursor: pointer;
            padding: 0 4px;
        }

        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
            }
        }
    </style>
</head>

<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <?php require_once $projectRoot . '/app/views/partials/platform-sidebar.php'; ?>

            <main class="col-md-9 ms-sm-auto col-lg-10 px-0">
                <div class="topbar d-flex justify-content-between align-items-center">
                    <h4><i class="fas fa-cog"></i> Platform Settings</h4>
                    <div class="d-flex align-items-center gap-3">
                        <a href="/platform/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
                        <div class="user-avatar" id="userAvatar">A</div>
                    </div>
                </div>

                <div class="px-3 px-md-4 py-3">
                    <div class="card p-4">
                        <div id="successMessage" class="alert alert-success" style="display:none;">
                            <i class="fas fa-check-circle"></i> <span id="successText"></span>
                        </div>
                        <div id="errorMessage" class="alert alert-danger" style="display:none;">
                            <i class="fas fa-exclamation-circle"></i> <span id="errorText"></span>
                        </div>

                        <h6 class="mb-3"><i class="fas fa-globe text-primary"></i> General Settings</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">System Name</label>
                                <input type="text" class="form-control" id="system_name" value="Student 360 Enterprise">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">System Version</label>
                                <input type="text" class="form-control" id="system_version" value="2.0.0" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Default Timezone</label>
                                <select class="form-select" id="default_timezone">
                                    <option value="UTC">UTC</option>
                                    <option value="Africa/Accra" selected>Africa/Accra (GMT)</option>
                                    <option value="Africa/Lagos">Africa/Lagos (WAT)</option>
                                    <option value="Africa/Nairobi">Africa/Nairobi (EAT)</option>
                                    <option value="America/New_York">America/New_York (EST)</option>
                                    <option value="Europe/London">Europe/London (GMT)</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Default Currency</label>
                                <select class="form-select" id="default_currency">
                                    <option value="GHS" selected>GHS - Ghana Cedis</option>
                                    <option value="NGN">NGN - Nigerian Naira</option>
                                    <option value="KES">KES - Kenyan Shilling</option>
                                    <option value="ZAR">ZAR - South African Rand</option>
                                    <option value="USD">USD - US Dollar</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Company Name</label>
                                <input type="text" class="form-control" id="company_name" value="Student 360 Systems">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Support Email</label>
                                <input type="email" class="form-control" id="support_email" value="support@student360.com">
                            </div>
                        </div>

                        <hr class="my-4">

                        <h6 class="mb-3"><i class="fas fa-shield-alt text-warning"></i> Security Settings</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Session Timeout (minutes)</label>
                                <input type="number" class="form-control" id="session_timeout" value="60">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Max Login Attempts</label>
                                <input type="number" class="form-control" id="max_attempts" value="5">
                            </div>
                            <div class="col-md-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="two_factor_auth" checked>
                                    <label class="form-check-label" for="two_factor_auth">Enable Two-Factor Authentication</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="email_verification" checked>
                                    <label class="form-check-label" for="email_verification">Require Email Verification</label>
                                </div>
                            </div>
                        </div>

                        <hr class="my-4">

                        <h6 class="mb-3"><i class="fas fa-bell text-info"></i> Notification Settings</h6>
                        <div class="row g-3">
                            <div class="col-md-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="notify_tenant_registration" checked>
                                    <label class="form-check-label" for="notify_tenant_registration">Notify on Tenant Registration</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="notify_tenant_approval" checked>
                                    <label class="form-check-label" for="notify_tenant_approval">Notify on Tenant Approval</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="notify_subscription_expiry" checked>
                                    <label class="form-check-label" for="notify_subscription_expiry">Notify on Subscription Expiry</label>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="button" class="btn btn-primary px-4" onclick="saveSettings()">
                                <i class="fas fa-save"></i> Save Settings
                            </button>
                            <button type="button" class="btn btn-outline-secondary ms-2" onclick="location.reload()">
                                <i class="fas fa-undo"></i> Reset
                            </button>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script>
        const token = localStorage.getItem('platform_token');
        if (!token) {
            window.location.href = '/platform/login.php';
        }

        try {
            const user = JSON.parse(localStorage.getItem('platform_user') || '{}');
            if (user.first_name) {
                document.getElementById('userAvatar').textContent = user.first_name[0].toUpperCase();
            }
        } catch (e) {}

        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                localStorage.removeItem('platform_token');
                localStorage.removeItem('platform_user');
                window.location.href = '/platform/login.php';
            }
        }

        function showToast(type, message) {
            const container = document.getElementById('toastContainer');
            const icons = {
                success: 'fa-check-circle',
                danger: 'fa-exclamation-circle'
            };
            const toast = document.createElement('div');
            toast.className = `toast-custom ${type}`;
            toast.innerHTML = `
                <div class="toast-icon"><i class="fas ${icons[type] || 'fa-info-circle'}"></i></div>
                <div class="toast-body">${message}</div>
                <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
            `;
            container.appendChild(toast);
            setTimeout(() => {
                toast.style.animation = 'slideOut 0.3s ease forwards';
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }

        function saveSettings() {
            const btn = event.target;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';
            btn.disabled = true;

            setTimeout(() => {
                document.getElementById('successMessage').style.display = 'block';
                document.getElementById('successText').textContent = 'Settings saved successfully!';
                showToast('success', 'Settings saved successfully!');
                btn.innerHTML = '<i class="fas fa-save"></i> Save Settings';
                btn.disabled = false;
                setTimeout(() => {
                    document.getElementById('successMessage').style.display = 'none';
                }, 5000);
            }, 1500);
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (!localStorage.getItem('platform_token')) {
                window.location.href = '/platform/login.php';
            }
        });
    </script>
</body>

</html>