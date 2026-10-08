<?php

/**
 * Monitoring Dashboard - Platform monitoring and analytics
 *
 * @package EduTrack
 * @subpackage Platform\Monitoring
 * @version 1.0
 * @filepath public/platform/monitoring/index.php
 *
 * v1.0 change (2026-10-08) [SWEEP X-1 + SIDEBAR + REPAIRS]:
 *   Platform-monitoring sweep, X-1 in full, plus sidebar
 *   reconciliation, plus three repairs. This is the first versioned
 *   dockblock on this file — it carried only a two-line comment
 *   before, so no prior version is displaced.
 *
 *   X-1: the visible "EduTrack" brand heading that this page carried
 *   in its own inline sidebar is superseded by the sidebar
 *   reconciliation below, which replaces the entire inline sidebar
 *   with the shared partial. The $pageTitle is realigned to the
 *   "Student 360 Platform" suffix convention used across the swept
 *   platform pages.
 *
 *   Sidebar: the inline sidebar this page carried — 11 items across
 *   4 groups (Main: Dashboard, Tenants, Users; Institution: Schools,
 *   Campuses; Management: Subscriptions, Domains; System: Audit Logs,
 *   Monitoring, Settings), brand heading "EduTrack", width 260px —
 *   is replaced by a single require_once of the shared partial at
 *   app/views/partials/platform-sidebar.php v1.0. That partial
 *   carries the canonical platform-root sidebar shape: 11 items
 *   across 4 groups, brand heading "Student 360", plus the mobile
 *   toggle, the sidebar footer, and the toggleSidebar() JS. This
 *   file's inline sidebar already matched the canonical item set;
 *   only the brand string and the toggleSidebar() copy differed
 *   from the partial.
 *
 *   $projectRoot = dirname(__DIR__, 3) — three levels up. This
 *   file's path is public/platform/monitoring/index.php, so three
 *   levels up resolves to the project root. The declaration is
 *   added in the same change as the partial require, because the
 *   file did not carry $projectRoot before this sweep.
 *
 *   $currentPage stays 'monitoring' so the Monitoring item in the
 *   canonical partial is marked active. $currentUser, $userFirstName,
 *   $userAvatar, and $pendingApprovals are set before the partial,
 *   matching the other swept pages.
 *
 *   The page's .sidebar, .sidebar-toggle, .sidebar-header, .nav,
 *   .nav-label, .nav-link, .sidebar-footer, .user-info, .user-avatar,
 *   .user-name, .user-role, and .logout-btn CSS rules stay in the
 *   page's <style> block, because the partial ships markup only and
 *   no CSS.
 *
 *   The inline toggleSidebar() function, the inline click-outside
 *   handler, and the inline resize handler are removed from the
 *   page's <script> block, because the partial provides them.
 *   Every dashboard function in that <script> block is retained:
 *   refreshData(), loadStats(), loadGrowthData(), loadRevenueData(),
 *   loadTopTenants(), updateLastUpdated(), getHeaders(),
 *   formatNumber(), formatCurrency(), getRandomColor(),
 *   showAlert(), loadUserInfo(), and the 60-second auto-refresh
 *   interval.
 *
 *   Repairs:
 *
 *     - SESSION_START. The file previously called session_start()
 *       unguarded. Every other swept platform-root file wraps it in
 *       if (session_status() === PHP_SESSION_NONE) { session_start(); }.
 *       The guard is added to prevent a PHP notice when a session is
 *       already active on the request.
 *
 *     - AUTH. The is_super_admin guard is added immediately after
 *       the logged_in guard. Prior to this change this page — the
 *       platform-root monitoring dashboard, reachable from the
 *       canonical sidebar — carried only the logged_in guard. Every
 *       other platform-root page swept in this session carries both
 *       guards. The added guard matches the convention:
 *         if (!isset($_SESSION['is_super_admin']) ||
 *             $_SESSION['is_super_admin'] !== true) {
 *             header('Location: /platform/tenant/dashboard.php');
 *             exit;
 *         }
 *
 *     - LOGOUT. The inline logout() function previously redirected
 *       to '<?php echo $protocol; ?>://<?php echo $host; ?>/platform/login.php'
 *       — the login page, not the logout handler. Clicking Logout
 *       on this page did not clear the session. The redirect is
 *       changed to the relative '/platform/logout.php', matching
 *       the convention used by every other swept platform-root
 *       page.
 *
 *   Open items on the record (NOT changed by this sweep):
 *
 *     - The System Status panel is hardcoded. It always renders
 *       "API Server: Online", "Database: Online", "Storage: Available",
 *       "Cache: Online", and "Uptime: 99.98%". It performs no fetch
 *       and reads no data.
 *
 *     - The Recent Alerts panel is hardcoded. It always renders the
 *       "No recent alerts" block. It performs no fetch and reads no
 *       data.
 *
 *     - The doughnut chart uses getRandomColor(), so its slice
 *       colours change on every page load. This is a cosmetic
 *       inconsistency; it is not a defect.
 *
 *     - The 60-second auto-refresh interval is intentional. It is
 *       cleared on beforeunload.
 *
 *     - No CSRF surface on this page. There is no PHP POST form.
 *       Every data read and every state-changing action is a
 *       client-side fetch() to the
 *       {API_BASE}/index.php?endpoint=monitoring&... endpoints.
 *       The endpoint implementation is not on the record.
 *
 *   Class names, table names, database name, and file paths remain
 *   EduTrack per X-1. The @package line stays 'EduTrack'.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /platform/login.php');
    exit;
}

// Super Admin check - matches the platform-root convention
if (!isset($_SESSION['is_super_admin']) || $_SESSION['is_super_admin'] !== true) {
    header('Location: /platform/tenant/dashboard.php');
    exit;
}

$pageTitle = 'Monitoring Dashboard - Student 360 Platform';
$currentPage = 'monitoring';

// Determine API base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$apiBase = $protocol . '://' . $host . '/api/platform';

// Load user info
$currentUser = $_SESSION['user_name'] ?? 'Admin';
$userFirstName = $_SESSION['first_name'] ?? 'Super';
$userAvatar = strtoupper(substr($userFirstName, 0, 1));

// Variables read by the platform sidebar partial
$pendingApprovals = 0;

// Project root (three levels up from public/platform/monitoring/index.php)
$projectRoot = dirname(__DIR__, 3);

require_once $projectRoot . '/app/views/partials/platform-sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo $pageTitle; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ================================================ */
        /* GLOBAL RESET - MATCHES DASHBOARD                */
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
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
        /* SIDEBAR - MATCHES DASHBOARD                     */
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
        }

        .sidebar .sidebar-footer .logout-btn:hover {
            color: #ff6b6b;
        }

        /* ================================================ */
        /* MAIN CONTENT                                   */
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

        /* ================================================ */
        /* TOP BAR                                        */
        /* ================================================ */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 0 20px 0;
            flex-wrap: wrap;
            gap: 10px;
        }

        .top-bar .page-title h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
            margin: 0;
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
            border-radius: 10px;
            padding: 8px 18px;
            font-weight: 500;
            font-size: 14px;
        }

        .top-bar .header-actions .btn i {
            margin-right: 6px;
        }

        .top-bar .header-actions .btn-refresh {
            background: #fff;
            border: 1px solid #e9ecef;
            color: #1a1a2e;
        }

        .top-bar .header-actions .btn-refresh:hover {
            background: #f8f9fa;
            border-color: #dee2e6;
        }

        /* ================================================ */
        /* STATS CARDS                                    */
        /* ================================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: #fff;
            border-radius: 14px;
            padding: 16px 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .stat-card .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }

        .stat-card .stat-icon.blue {
            background: #e3f0ff;
            color: #0d6efd;
        }

        .stat-card .stat-icon.green {
            background: #d4edda;
            color: #28a745;
        }

        .stat-card .stat-icon.yellow {
            background: #fff3cd;
            color: #ffc107;
        }

        .stat-card .stat-icon.purple {
            background: #e8d5f5;
            color: #6f42c1;
        }

        .stat-card .stat-icon.red {
            background: #f8d7da;
            color: #dc3545;
        }

        .stat-card .stat-icon.orange {
            background: #ffe8d6;
            color: #fd7e14;
        }

        .stat-card .stat-number {
            font-size: 22px;
            font-weight: 700;
            color: #1a1a2e;
            line-height: 1.2;
        }

        .stat-card .stat-label {
            font-size: 12px;
            color: #6c757d;
            font-weight: 500;
        }

        .stat-card .stat-change {
            font-size: 11px;
            font-weight: 600;
            margin-left: 6px;
        }

        .stat-card .stat-change.up {
            color: #28a745;
        }

        .stat-card .stat-change.down {
            color: #dc3545;
        }

        /* ================================================ */
        /* CHART CARDS                                    */
        /* ================================================ */
        .chart-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            padding: 16px 20px;
            margin-bottom: 20px;
            width: 100%;
        }

        .chart-card .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .chart-card .chart-header h6 {
            font-weight: 600;
            margin: 0;
            font-size: 15px;
            color: #1a1a2e;
        }

        .chart-card .chart-body {
            min-height: 200px;
            position: relative;
        }

        .chart-card .chart-body canvas {
            width: 100% !important;
            max-height: 250px;
        }

        /* ================================================ */
        /* TENANT LIST CARD                               */
        /* ================================================ */
        .tenant-list-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(0, 0, 0, 0.03);
            padding: 16px 20px;
            margin-bottom: 20px;
            width: 100%;
        }

        .tenant-list-card .list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 8px;
        }

        .tenant-list-card .list-header h6 {
            font-weight: 600;
            margin: 0;
            font-size: 15px;
            color: #1a1a2e;
        }

        .tenant-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 12px;
            border-bottom: 1px solid #f0f2f5;
        }

        .tenant-item:last-child {
            border-bottom: none;
        }

        .tenant-item .tenant-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .tenant-item .tenant-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 12px;
            color: #fff;
            flex-shrink: 0;
        }

        .tenant-item .tenant-name {
            font-weight: 500;
            font-size: 14px;
        }

        .tenant-item .tenant-detail {
            font-size: 12px;
            color: #6c757d;
        }

        .tenant-item .tenant-value {
            font-weight: 600;
            font-size: 14px;
        }

        /* ================================================ */
        /* RESPONSIVE - NO HORIZONTAL SCROLL              */
        /* ================================================ */
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

            .main-content {
                margin-left: 72px;
                width: calc(100% - 72px);
                padding: 20px;
            }

            .sidebar-toggle {
                display: none;
            }

            .stats-row {
                grid-template-columns: repeat(2, 1fr);
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

            .main-content {
                margin-left: 0;
                width: 100%;
                padding: 16px;
                padding-top: 70px;
            }

            .top-bar .page-title h1 {
                font-size: 20px;
            }

            .top-bar .page-title p {
                font-size: 12px;
            }

            .top-bar .header-actions .btn {
                font-size: 12px;
                padding: 6px 12px;
            }

            .stats-row {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }

            .stat-card {
                padding: 12px 14px;
            }

            .stat-card .stat-number {
                font-size: 18px;
            }

            .stat-card .stat-icon {
                width: 36px;
                height: 36px;
                font-size: 14px;
            }

            .chart-card {
                padding: 12px 14px;
            }

            .chart-card .chart-body {
                min-height: 150px;
            }

            .tenant-list-card {
                padding: 12px 14px;
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

            .stats-row {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }

            .stat-card {
                padding: 10px 12px;
            }

            .stat-card .stat-number {
                font-size: 16px;
            }

            .stat-card .stat-icon {
                width: 32px;
                height: 32px;
                font-size: 12px;
            }

            .stat-card .stat-label {
                font-size: 10px;
            }

            .chart-card .chart-body {
                min-height: 120px;
            }

            .tenant-item {
                padding: 8px 10px;
            }

            .tenant-item .tenant-name {
                font-size: 13px;
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
                <!-- Top Bar -->
                <div class="top-bar">
                    <div class="page-title">
                        <h1><i class="fas fa-chart-line me-2"></i>Monitoring Dashboard</h1>
                        <p>Real-time platform analytics and monitoring</p>
                    </div>
                    <div class="header-actions">
                        <span id="lastUpdated" style="font-size:12px;color:#6c757d;">
                            <i class="fas fa-clock me-1"></i> Last updated: Just now
                        </span>
                        <button class="btn btn-refresh" onclick="refreshData()" title="Refresh data">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>

                <!-- Alert Container -->
                <div id="alertContainer"></div>

                <!-- Stats -->
                <div class="stats-row" id="statsRow">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-building"></i></div>
                        <div>
                            <div class="stat-number" id="totalTenants">0</div>
                            <div class="stat-label">Total Tenants</div>
                            <span class="stat-change up" id="tenantChange">+0%</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="stat-number" id="totalUsers">0</div>
                            <div class="stat-label">Total Users</div>
                            <span class="stat-change up" id="userChange">+0%</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-crown"></i></div>
                        <div>
                            <div class="stat-number" id="activeSubscriptions">0</div>
                            <div class="stat-label">Active Subscriptions</div>
                            <span class="stat-change up" id="subscriptionChange">+0%</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon yellow"><i class="fas fa-dollar-sign"></i></div>
                        <div>
                            <div class="stat-number" id="monthlyRevenue">GHS 0</div>
                            <div class="stat-label">Monthly Revenue</div>
                            <span class="stat-change up" id="revenueChange">+0%</span>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="row">
                    <div class="col-md-6 col-12">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h6><i class="fas fa-chart-bar me-2 text-primary"></i>Monthly Growth</h6>
                                <select class="form-select form-select-sm" id="growthMonths" style="width:auto;" onchange="loadGrowthData()">
                                    <option value="6">Last 6 Months</option>
                                    <option value="12" selected>Last 12 Months</option>
                                    <option value="24">Last 24 Months</option>
                                </select>
                            </div>
                            <div class="chart-body">
                                <canvas id="growthChart"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-12">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h6><i class="fas fa-chart-pie me-2 text-success"></i>Revenue Distribution</h6>
                            </div>
                            <div class="chart-body">
                                <canvas id="revenueChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Tenants -->
                <div class="tenant-list-card">
                    <div class="list-header">
                        <h6><i class="fas fa-trophy me-2 text-warning"></i>Top Performing Tenants</h6>
                        <span class="text-muted" style="font-size:12px;">By revenue</span>
                    </div>
                    <div id="topTenantsList">
                        <div class="text-center py-3 text-muted">
                            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                            Loading top tenants...
                        </div>
                    </div>
                </div>

                <!-- System Status -->
                <div class="row">
                    <div class="col-md-6 col-12">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h6><i class="fas fa-server me-2 text-info"></i>System Status</h6>
                            </div>
                            <div class="chart-body" id="systemStatus">
                                <div class="d-flex align-items-center gap-3 mb-2">
                                    <i class="fas fa-check-circle text-success"></i>
                                    <span>API Server: <strong class="text-success">Online</strong></span>
                                </div>
                                <div class="d-flex align-items-center gap-3 mb-2">
                                    <i class="fas fa-check-circle text-success"></i>
                                    <span>Database: <strong class="text-success">Online</strong></span>
                                </div>
                                <div class="d-flex align-items-center gap-3 mb-2">
                                    <i class="fas fa-check-circle text-success"></i>
                                    <span>Storage: <strong class="text-success">Available</strong></span>
                                </div>
                                <div class="d-flex align-items-center gap-3 mb-2">
                                    <i class="fas fa-check-circle text-success"></i>
                                    <span>Cache: <strong class="text-success">Online</strong></span>
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <i class="fas fa-clock text-warning"></i>
                                    <span>Uptime: <strong>99.98%</strong> (Last 30 days)</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 col-12">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h6><i class="fas fa-bell me-2 text-danger"></i>Recent Alerts</h6>
                            </div>
                            <div class="chart-body" id="recentAlerts">
                                <div class="text-center py-3 text-muted">
                                    <i class="fas fa-check-circle fa-2x d-block mb-2 text-success"></i>
                                    No recent alerts
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // ================================================
        // CONFIGURATION
        // ================================================
        const API_BASE = '<?php echo $apiBase; ?>';
        const TOKEN = localStorage.getItem('token') || '';
        let growthChart = null;
        let revenueChart = null;
        let refreshInterval = null;

        // ================================================
        // LOGOUT
        // ================================================
        function logout() {
            if (confirm('Are you sure you want to logout?')) {
                localStorage.removeItem('token');
                localStorage.removeItem('user');
                window.location.href = '/platform/logout.php';
            }
        }

        // ================================================
        // API HELPERS
        // ================================================
        function getHeaders() {
            return {
                'Authorization': 'Bearer ' + TOKEN,
                'Content-Type': 'application/json'
            };
        }

        function formatNumber(num) {
            return num ? num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',') : '0';
        }

        function formatCurrency(amount) {
            if (!amount || amount == 0) return 'GHS 0';
            return 'GHS ' + parseFloat(amount).toFixed(2);
        }

        function getRandomColor() {
            const colors = ['#4facfe', '#28a745', '#ffc107', '#dc3545', '#6f42c1', '#fd7e14', '#20c997', '#e83e8c'];
            return colors[Math.floor(Math.random() * colors.length)];
        }

        // ================================================
        // SHOW ALERT
        // ================================================
        function showAlert(message, type = 'info') {
            const container = document.getElementById('alertContainer');
            const colors = {
                success: 'alert-success',
                danger: 'alert-danger',
                warning: 'alert-warning',
                info: 'alert-info'
            };
            container.innerHTML = `
                <div class="alert ${colors[type] || 'alert-info'} alert-dismissible fade show" role="alert" style="border-radius:12px;border:none;box-shadow:0 4px 20px rgba(0,0,0,0.06);">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'danger' ? 'fa-exclamation-circle' : 'fa-info-circle'} me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            `;
            setTimeout(() => {
                const alert = container.querySelector('.alert');
                if (alert) {
                    alert.classList.remove('show');
                    setTimeout(() => {
                        container.innerHTML = '';
                    }, 300);
                }
            }, 5000);
        }

        // ================================================
        // LOAD USER INFO
        // ================================================
        function loadUserInfo() {
            const userStr = localStorage.getItem('user');
            if (userStr) {
                try {
                    const user = JSON.parse(userStr);
                    document.getElementById('userName').textContent = user.first_name || 'Admin';
                    document.getElementById('userAvatar').textContent = (user.first_name || 'A').charAt(0);
                    document.getElementById('userRole').textContent = (user.roles || ['Administrator'])[0];
                } catch (e) {
                    console.error('Error parsing user:', e);
                }
            }
        }

        // ================================================
        // UPDATE LAST UPDATED
        // ================================================
        function updateLastUpdated() {
            const now = new Date();
            document.getElementById('lastUpdated').innerHTML = `
                <i class="fas fa-clock me-1"></i> Last updated: ${now.toLocaleTimeString()}
            `;
        }

        // ================================================
        // LOAD STATS
        // ================================================
        async function loadStats() {
            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=monitoring`, {
                    headers: getHeaders()
                });
                const result = await response.json();

                console.log('Stats Response:', result);

                if (result.success && result.data) {
                    const data = result.data;

                    document.getElementById('totalTenants').textContent = formatNumber(data.total_tenants || 0);
                    document.getElementById('totalUsers').textContent = formatNumber(data.total_users || 0);
                    document.getElementById('activeSubscriptions').textContent = formatNumber(data.active_subscriptions || 0);
                    document.getElementById('monthlyRevenue').textContent = formatCurrency(data.monthly_revenue || 0);

                    // Update changes
                    if (data.tenant_growth !== undefined) {
                        const tenantChange = document.getElementById('tenantChange');
                        tenantChange.textContent = (data.tenant_growth >= 0 ? '+' : '') + data.tenant_growth + '%';
                        tenantChange.className = 'stat-change ' + (data.tenant_growth >= 0 ? 'up' : 'down');
                    }
                    if (data.user_growth !== undefined) {
                        const userChange = document.getElementById('userChange');
                        userChange.textContent = (data.user_growth >= 0 ? '+' : '') + data.user_growth + '%';
                        userChange.className = 'stat-change ' + (data.user_growth >= 0 ? 'up' : 'down');
                    }
                    if (data.revenue_growth !== undefined) {
                        const revenueChange = document.getElementById('revenueChange');
                        revenueChange.textContent = (data.revenue_growth >= 0 ? '+' : '') + data.revenue_growth + '%';
                        revenueChange.className = 'stat-change ' + (data.revenue_growth >= 0 ? 'up' : 'down');
                    }
                } else {
                    console.error('Stats Error:', result.message);
                }
            } catch (error) {
                console.error('Error loading stats:', error);
            }
        }

        // ================================================
        // LOAD GROWTH DATA
        // ================================================
        async function loadGrowthData() {
            const months = document.getElementById('growthMonths').value;

            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=monitoring&monthly_growth=true&months=${months}`, {
                    headers: getHeaders()
                });
                const result = await response.json();

                console.log('Growth Data Response:', result);

                if (result.success && result.data) {
                    const data = result.data;
                    const labels = data.map(item => item.month || '');
                    const values = data.map(item => item.count || 0);

                    if (growthChart) {
                        growthChart.destroy();
                    }

                    const ctx = document.getElementById('growthChart').getContext('2d');
                    growthChart = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'New Tenants',
                                data: values,
                                backgroundColor: 'rgba(79, 172, 254, 0.6)',
                                borderColor: '#4facfe',
                                borderWidth: 2,
                                borderRadius: 4
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        stepSize: 1
                                    }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error loading growth data:', error);
            }
        }

        // ================================================
        // LOAD REVENUE DATA
        // ================================================
        async function loadRevenueData() {
            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=monitoring&revenue=true`, {
                    headers: getHeaders()
                });
                const result = await response.json();

                console.log('Revenue Data Response:', result);

                if (result.success && result.data) {
                    const data = result.data;

                    // Filter out plans with 0 revenue for better visualization
                    const filteredData = data.filter(item => item.revenue > 0);
                    const labels = filteredData.map(item => item.plan_name || 'Other');
                    const values = filteredData.map(item => item.revenue || 0);

                    // If no data with revenue, show sample
                    if (labels.length === 0) {
                        labels.push('No Data');
                        values.push(1);
                    }

                    const colors = labels.map(() => getRandomColor());

                    if (revenueChart) {
                        revenueChart.destroy();
                    }

                    const ctx = document.getElementById('revenueChart').getContext('2d');
                    revenueChart = new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: values,
                                backgroundColor: colors,
                                borderWidth: 2,
                                borderColor: '#fff'
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 10,
                                        boxWidth: 12,
                                        font: {
                                            size: 11
                                        }
                                    }
                                }
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Error loading revenue data:', error);
            }
        }

        // ================================================
        // LOAD TOP TENANTS
        // ================================================
        async function loadTopTenants() {
            const container = document.getElementById('topTenantsList');

            try {
                const response = await fetch(`${API_BASE}/index.php?endpoint=monitoring&top_tenants=true&metric=revenue&limit=5`, {
                    headers: getHeaders()
                });
                const result = await response.json();

                console.log('Top Tenants Response:', result);

                if (result.success && result.data) {
                    const tenants = result.data;

                    if (tenants.length === 0) {
                        container.innerHTML = `
                            <div class="text-center py-3 text-muted">
                                <i class="fas fa-building fa-2x d-block mb-2"></i>
                                No tenant data available
                            </div>
                        `;
                        return;
                    }

                    const colors = ['#4facfe', '#28a745', '#ffc107', '#6f42c1', '#fd7e14'];

                    container.innerHTML = tenants.map((tenant, index) => `
                        <div class="tenant-item">
                            <div class="tenant-info">
                                <div class="tenant-avatar" style="background:${colors[index % colors.length]}">
                                    ${(tenant.tenant_name || 'T').charAt(0).toUpperCase()}
                                </div>
                                <div>
                                    <div class="tenant-name">${tenant.tenant_name || 'Unnamed Tenant'}</div>
                                    <div class="tenant-detail">${tenant.users || 0} users · ${tenant.subscriptions || 0} subscriptions</div>
                                </div>
                            </div>
                            <div class="tenant-value">${formatCurrency(tenant.revenue || 0)}</div>
                        </div>
                    `).join('');
                } else {
                    container.innerHTML = `
                        <div class="text-center py-3 text-danger">
                            <i class="fas fa-exclamation-circle me-2"></i> ${result.message || 'Failed to load top tenants'}
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Error loading top tenants:', error);
                container.innerHTML = `
                    <div class="text-center py-3 text-danger">
                        <i class="fas fa-exclamation-circle me-2"></i> Failed to load top tenants: ${error.message}
                    </div>
                `;
            }
        }

        // ================================================
        // REFRESH DATA
        // ================================================
        async function refreshData() {
            const btn = document.querySelector('.btn-refresh i');
            if (btn) {
                btn.className = 'fas fa-spinner fa-spin';
            }

            await Promise.all([
                loadStats(),
                loadGrowthData(),
                loadRevenueData(),
                loadTopTenants()
            ]);

            updateLastUpdated();
            if (btn) {
                btn.className = 'fas fa-sync-alt';
            }
            showAlert('Dashboard refreshed successfully', 'success');
        }

        // ================================================
        // INITIALIZE
        // ================================================
        document.addEventListener('DOMContentLoaded', function() {
            loadUserInfo();

            // Initial data load
            Promise.all([
                loadStats(),
                loadGrowthData(),
                loadRevenueData(),
                loadTopTenants()
            ]).then(() => {
                updateLastUpdated();
            });

            // Auto-refresh every 60 seconds
            refreshInterval = setInterval(() => {
                loadStats();
                loadTopTenants();
                updateLastUpdated();
            }, 60000);
        });

        // Cleanup interval on page unload
        window.addEventListener('beforeunload', function() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
    </script>
</body>

</html>