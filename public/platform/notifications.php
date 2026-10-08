<?php

/**
 * Platform Notifications
 *
 * @package EduTrack
 * @subpackage Platform
 * @version 1.1
 * @filepath public/platform/notifications.php
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
 *   This resolution also removes the broken sidebar links that
 *   v1.0 carried to /platform/tenants.php and
 *   /platform/tenants-create.php. Both are on the record as
 *   non-existent per the directory listings for
 *   public/platform/. The canonical partial does not carry either
 *   link.
 *
 *   $currentPage is set to '' (empty string), so no canonical
 *   sidebar item is marked active. The Notifications page is not
 *   one of the canonical sidebar items, and marking a false active
 *   state would mislead a user.
 *
 *   $pendingApprovals is set to 0 before the partial is required;
 *   this page does not compute pending approvals, so the canonical
 *   sidebar's Approvals badge will not appear here.
 *
 *   A small PHP bootstrap block is added at the top of the file to
 *   define $projectRoot and the sidebar-scope variables. The page
 *   carried no PHP block in v1.0. Every other line of the file is
 *   byte-identical to v1.0.
 *
 * v1.0 change (2026-10-07) [SWEEP X-1]:
 *   Platform-root sweep, X-1 in full. The file carried no @package,
 *   no @subpackage, no @version, and no @filepath tag. This sweep
 *   adds all four, in the shape every other file on the record
 *   uses, adds this v1.0 [SWEEP] docblock paragraph above the
 *   existing description, and renames three visible "EduTrack"
 *   strings to "Student 360":
 *     - The <title> tag.
 *     - The sidebar brand heading.
 *     - The fourth hard-coded notification body.
 *   This page carried an inline sidebar that links to the
 *   platform-root navigation surface. The inline sidebar was
 *   preserved in v1.0. The .nav-subgroup-label CSS rule was not
 *   added.
 *
 *   Every other line of the file was byte-identical to the version
 *   that was on disk before that sweep.
 */

$projectRoot = dirname(__DIR__, 2);

$pageTitle = 'Notifications - Student 360 Platform';
$currentPage = '';
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
    <title>Notifications - Student 360 Platform</title>
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

        .notification-item {
            padding: 12px 16px;
            border-left: 4px solid #1a3c6e;
            margin-bottom: 8px;
            background: #fff;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .notification-item:hover {
            background: #f8f9fa;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .notification-item.unread {
            border-left-color: #dc3545;
            background: #fef8f8;
        }

        .notification-item .notification-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .notification-item .notification-icon.success {
            background: #d4edda;
            color: #155724;
        }

        .notification-item .notification-icon.warning {
            background: #fff3cd;
            color: #856404;
        }

        .notification-item .notification-icon.danger {
            background: #f8d7da;
            color: #721c24;
        }

        .notification-item .notification-icon.info {
            background: #d1ecf1;
            color: #0c5460;
        }

        .notification-time {
            font-size: 12px;
            color: #6c757d;
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
                    <h4><i class="fas fa-bell"></i> Notifications</h4>
                    <div class="d-flex align-items-center gap-3">
                        <button class="btn btn-outline-primary btn-sm" onclick="markAllRead()"><i class="fas fa-check-double"></i> Mark All Read</button>
                        <a href="/platform/index.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
                        <div class="user-avatar" id="userAvatar">A</div>
                    </div>
                </div>

                <div class="px-3 px-md-4 py-3">
                    <div class="card p-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span><strong>All Notifications</strong></span>
                            <span class="text-muted" id="notificationCount">3 unread</span>
                        </div>

                        <div id="notificationList">
                            <div class="notification-item unread">
                                <div class="d-flex align-items-start gap-3">
                                    <div class="notification-icon success"><i class="fas fa-check"></i></div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between">
                                            <strong>Tenant Approved</strong>
                                            <small class="notification-time">2 hours ago</small>
                                        </div>
                                        <p class="mb-0">Tenant "International School of Accra" has been approved successfully.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="notification-item unread">
                                <div class="d-flex align-items-start gap-3">
                                    <div class="notification-icon warning"><i class="fas fa-clock"></i></div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between">
                                            <strong>Subscription Expiring Soon</strong>
                                            <small class="notification-time">1 day ago</small>
                                        </div>
                                        <p class="mb-0">Tenant "Premier School" subscription expires in 5 days.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="notification-item unread">
                                <div class="d-flex align-items-start gap-3">
                                    <div class="notification-icon info"><i class="fas fa-user-plus"></i></div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between">
                                            <strong>New Tenant Registration</strong>
                                            <small class="notification-time">3 days ago</small>
                                        </div>
                                        <p class="mb-0">A new tenant "Bright Future Academy" has registered.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="notification-item">
                                <div class="d-flex align-items-start gap-3">
                                    <div class="notification-icon success"><i class="fas fa-check-circle"></i></div>
                                    <div class="flex-grow-1">
                                        <div class="d-flex justify-content-between">
                                            <strong>System Update Complete</strong>
                                            <small class="notification-time">1 week ago</small>
                                        </div>
                                        <p class="mb-0">Student 360 platform has been updated to version 2.0.0.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

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

        function markAllRead() {
            document.querySelectorAll('.notification-item.unread').forEach(item => {
                item.classList.remove('unread');
            });
            document.getElementById('notificationCount').textContent = '0 unread';
            alert('All notifications marked as read!');
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (!localStorage.getItem('platform_token')) {
                window.location.href = '/platform/login.php';
            }
        });
    </script>
</body>

</html>